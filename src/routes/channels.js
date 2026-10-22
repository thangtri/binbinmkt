const crypto = require('crypto');
const express = require('express');
const db = require('../db');
const config = require('../config');
const dates = require('../lib/dates');
const { CHANNELS } = require('../lib/constants');
const { requirePerm } = require('../lib/auth');
const sync = require('../lib/sync');
const ai = require('../lib/ai');
const { h, flash, logActivity, str, int, safeUrl } = require('../lib/util');

const router = express.Router();

// Chỉ số chính của từng kênh. Google Maps không có "người theo dõi" nên dùng đánh giá.
const METRICS = {
  social: [
    { key: 'followers', label: 'Người theo dõi' },
    { key: 'reach', label: 'Tiếp cận 28 ngày' },
    { key: 'engagement', label: 'Tương tác 28 ngày' },
  ],
  zalo: [{ key: 'followers', label: 'Người quan tâm OA' }],
  google: [
    { key: 'rating', label: 'Điểm đánh giá', decimals: 1 },
    { key: 'reviews', label: 'Số đánh giá' },
  ],
};

// Biến môi trường cần có để kết nối từng kênh
const REQUIRED_ENV = {
  google: ['GOOGLE_PLACES_API_KEY'],
  facebook: ['FACEBOOK_APP_ID', 'FACEBOOK_APP_SECRET'],
  tiktok: ['TIKTOK_CLIENT_KEY', 'TIKTOK_CLIENT_SECRET'],
  zalo: ['ZALO_APP_ID', 'ZALO_APP_SECRET'],
};

function metricsFor(key) {
  return METRICS[key] || METRICS.social;
}

function nullableInt(v) {
  const s = String(v ?? '').trim();
  return s === '' ? null : Math.max(0, int(s));
}

function channelOr404(req) {
  const ch = CHANNELS[req.params.channel];
  if (!ch) {
    const err = new Error('Không có kênh này.');
    err.status = 404;
    err.expose = true;
    throw err;
  }
  return ch;
}

// Giá trị gần nhất của một chỉ số và mức thay đổi so với lần ghi trước có chỉ số đó
function summarize(stats, metric) {
  const rows = stats.filter((s) => s[metric.key] != null);
  if (!rows.length) return { ...metric, value: null };
  const value = Number(rows[0][metric.key]);
  const prev = rows[1] ? Number(rows[1][metric.key]) : null;
  const f = 10 ** (metric.decimals || 0);
  const delta = prev == null ? null : Math.round((value - prev) * f) / f;
  return { ...metric, value, on: rows[0].recorded_on, delta, prevOn: rows[1]?.recorded_on };
}

function parseExtra(account) {
  try {
    return JSON.parse(account?.extra || '{}');
  } catch {
    return {};
  }
}

async function connection(ch, account) {
  const conn = sync.connectors[ch.key];
  const token = conn.oauth ? await sync.getToken(ch.key) : null;
  return {
    configured: conn.isConfigured(),
    connected: sync.isConnected(account, token),
    oauth: Boolean(conn.oauth),
    missingEnv: REQUIRED_ENV[ch.key].filter((name) => !process.env[name]),
    status: account.sync_status,
    error: account.sync_error,
    lastSyncedAt: account.last_synced_at,
  };
}

router.get(
  '/',
  h(async (req, res) => {
    const since = dates.addDays(dates.today(), -30) + ' 00:00:00';
    const rows = [];
    for (const ch of Object.values(CHANNELS)) {
      const account = await db.get(
        'SELECT a.*, u.name AS owner_name FROM channel_accounts a LEFT JOIN users u ON u.id = a.owner_id WHERE a.channel = ?',
        [ch.key]
      );
      const stats = await db.all('SELECT * FROM channel_stats WHERE channel = ? ORDER BY recorded_on DESC, id DESC LIMIT 60', [ch.key]);
      const metrics = metricsFor(ch.key);
      const primary = summarize(stats, metrics[0]);
      const reviews = ch.key === 'google' ? summarize(stats, metrics[1]) : null;
      const perf = await db.get(
        `SELECT COUNT(*) AS posts, COALESCE(SUM(views), 0) AS views, COALESCE(SUM(likes + comments + shares), 0) AS interactions
         FROM posts WHERE channel = ? AND status = 'published' AND published_at >= ?`,
        [ch.key, since]
      );
      const upcoming = await db.get(
        `SELECT COUNT(*) AS n FROM posts WHERE channel = ? AND status IN ('pending', 'scheduled') AND scheduled_at >= ?`,
        [ch.key, dates.now()]
      );
      rows.push({ ch, account, primary, reviews, perf, upcoming: Number(upcoming.n), conn: await connection(ch, account) });
    }
    res.render('channels/index', { title: 'Kênh mạng xã hội', rows });
  })
);

/* ---------- Kết nối OAuth (Facebook, TikTok, Zalo OA) ---------- */

function redirectUri(key) {
  return `${config.appUrl}/channels/oauth/${key}/callback`;
}

// Danh sách Page Facebook chờ chọn, giữ trong bộ nhớ 10 phút (token quá dài để để trong cookie)
const pendingPages = new Map();

router.get(
  '/oauth/:channel/callback',
  requirePerm('channel:edit'),
  h(async (req, res) => {
    const ch = channelOr404(req);
    const conn = sync.connectors[ch.key];
    const saved = req.session.oauth;
    req.session.oauth = null;
    const back = `/channels/${ch.key}`;
    if (req.query.error || req.query.error_description) {
      flash(req, 'error', `Chưa kết nối được ${ch.name}: ${req.query.error_description || req.query.error}.`);
      return res.redirect(back);
    }
    if (!saved || saved.channel !== ch.key || saved.state !== req.query.state || !req.query.code) {
      flash(req, 'error', 'Phiên kết nối đã hết hạn. Bấm Kết nối lại.');
      return res.redirect(back);
    }
    try {
      if (ch.key === 'facebook') {
        const pages = await conn.exchange({ code: req.query.code, redirectUri: redirectUri(ch.key) });
        if (!pages.length) throw new Error('Tài khoản Facebook này không quản lý Page nào.');
        if (pages.length === 1) {
          await connectFacebookPage(pages[0], req.user, req);
          return res.redirect(back);
        }
        const id = crypto.randomBytes(12).toString('hex');
        pendingPages.set(id, { pages, userId: req.user.id, until: Date.now() + 10 * 60 * 1000 });
        return res.redirect(`/channels/facebook/choose-page?k=${id}`);
      }
      const t = await conn.exchange({ code: req.query.code, redirectUri: redirectUri(ch.key), verifier: saved.verifier });
      await sync.saveToken(ch.key, t);
      await db.run('UPDATE channel_accounts SET external_id = ?, updated_at = ? WHERE channel = ?', [t.openId || 'connected', dates.now(), ch.key]);
      await afterConnect(req, ch);
    } catch (err) {
      flash(req, 'error', `Chưa kết nối được ${ch.name}: ${err.message}`);
    }
    res.redirect(back);
  })
);

async function afterConnect(req, ch) {
  await logActivity(req.user, 'channel.connect', 'channel', null, ch.name);
  const r = await sync.sync(ch.key, req.user);
  flash(req, r.ok ? 'success' : 'warn', r.ok ? `Đã kết nối ${ch.name} và lấy số liệu mới nhất.` : `Đã kết nối ${ch.name} nhưng chưa lấy được số liệu: ${r.error}`);
}

async function connectFacebookPage(page, user, req) {
  await sync.saveToken('facebook', { accessToken: page.accessToken });
  await db.run('UPDATE channel_accounts SET external_id = ?, display_name = ?, url = COALESCE(url, ?), updated_at = ? WHERE channel = ?', [
    page.id,
    page.name,
    page.url,
    dates.now(),
    'facebook',
  ]);
  if (req) await afterConnect(req, CHANNELS.facebook);
  else await sync.sync('facebook', user);
}

router.get('/facebook/choose-page', requirePerm('channel:edit'), (req, res) => {
  const p = pendingPages.get(String(req.query.k || ''));
  if (!p || p.userId !== req.user.id || p.until < Date.now()) {
    flash(req, 'error', 'Danh sách Page đã hết hạn. Bấm Kết nối lại.');
    return res.redirect('/channels/facebook');
  }
  res.render('channels/choose-page', { title: 'Chọn Page Facebook', pages: p.pages, k: req.query.k });
});

router.post(
  '/facebook/choose-page',
  requirePerm('channel:edit'),
  h(async (req, res) => {
    const p = pendingPages.get(String(req.body.k || ''));
    const page = p && p.userId === req.user.id && p.until > Date.now() ? p.pages.find((x) => x.id === req.body.page_id) : null;
    pendingPages.delete(String(req.body.k || ''));
    if (!page) {
      flash(req, 'error', 'Không tìm thấy Page đã chọn. Bấm Kết nối lại.');
      return res.redirect('/channels/facebook');
    }
    await connectFacebookPage(page, req.user, req);
    res.redirect('/channels/facebook');
  })
);

router.post(
  '/:channel/connect',
  requirePerm('channel:edit'),
  h(async (req, res) => {
    const ch = channelOr404(req);
    const conn = sync.connectors[ch.key];
    if (!conn.oauth) return res.redirect(`/channels/${ch.key}`);
    if (!conn.isConfigured()) {
      flash(req, 'error', `Máy chủ chưa có ${REQUIRED_ENV[ch.key].join(', ')}. Xem hướng dẫn trong DEPLOY.md.`);
      return res.redirect(`/channels/${ch.key}`);
    }
    const state = crypto.randomBytes(16).toString('hex');
    const pk = conn.pkce ? conn.pkce() : null;
    req.session.oauth = { channel: ch.key, state, verifier: pk?.verifier };
    res.redirect(conn.authUrl({ redirectUri: redirectUri(ch.key), state, challenge: pk?.challenge }));
  })
);

/* ---------- Google Maps: dán link ---------- */

router.post(
  '/google/link',
  requirePerm('channel:edit'),
  h(async (req, res) => {
    const link = str(req.body.link, 500);
    try {
      const placeId = await sync.connectors.google.resolveLink(link);
      await db.run('UPDATE channel_accounts SET external_id = ?, url = ?, updated_at = ? WHERE channel = ?', [placeId, safeUrl(link) || null, dates.now(), 'google']);
      await afterConnect(req, CHANNELS.google);
    } catch (err) {
      flash(req, 'error', err.message);
    }
    res.redirect('/channels/google');
  })
);

router.post(
  '/:channel/sync',
  requirePerm('channel:edit'),
  h(async (req, res) => {
    const ch = channelOr404(req);
    const r = await sync.sync(ch.key, req.user);
    if (r.ok) await logActivity(req.user, 'channel.sync', 'channel', null, ch.name);
    flash(req, r.ok ? 'success' : 'error', r.ok ? `Đã cập nhật số liệu ${ch.name}${r.matched ? `, khớp ${r.matched} bài đã đăng` : ''}.` : `Không cập nhật được: ${r.error}`);
    res.redirect(`/channels/${ch.key}`);
  })
);

router.post(
  '/:channel/disconnect',
  requirePerm('channel:edit'),
  h(async (req, res) => {
    const ch = channelOr404(req);
    await sync.disconnect(ch.key);
    await logActivity(req.user, 'channel.disconnect', 'channel', null, ch.name);
    flash(req, 'success', `Đã ngắt kết nối ${ch.name}. Số liệu cũ vẫn được giữ.`);
    res.redirect(`/channels/${ch.key}`);
  })
);

router.post(
  '/:channel/analyze',
  requirePerm('channel:edit'),
  h(async (req, res) => {
    const ch = channelOr404(req);
    try {
      await ai.analyze(ch.key, req.user);
      await logActivity(req.user, 'channel.analyze', 'channel', null, ch.name);
      flash(req, 'success', 'AI đã phân tích xong.');
    } catch (err) {
      flash(req, 'error', `Chưa phân tích được: ${err.message}`);
    }
    res.redirect(`/channels/${ch.key}#ai`);
  })
);

/* ---------- Trang chi tiết kênh ---------- */

router.get(
  '/:channel',
  h(async (req, res) => {
    const ch = channelOr404(req);
    const account = await db.get(
      'SELECT a.*, u.name AS owner_name FROM channel_accounts a LEFT JOIN users u ON u.id = a.owner_id WHERE a.channel = ?',
      [ch.key]
    );
    const stats = await db.all(
      'SELECT s.*, u.name AS user_name FROM channel_stats s LEFT JOIN users u ON u.id = s.created_by WHERE s.channel = ? ORDER BY s.recorded_on DESC, s.id DESC LIMIT 60',
      [ch.key]
    );
    const metrics = metricsFor(ch.key).map((m) => summarize(stats, m));
    // Biểu đồ: chỉ số đầu tiên có ít nhất 2 lần ghi
    const chartMetric = metrics.find((m) => stats.filter((s) => s[m.key] != null).length >= 2) || metrics[0];
    const series = stats
      .filter((s) => s[chartMetric.key] != null)
      .slice(0, 26)
      .reverse()
      .map((s) => ({ day: s.recorded_on, value: Number(s[chartMetric.key]) }));

    const since = dates.addDays(dates.today(), -30) + ' 00:00:00';
    const perf = await db.get(
      `SELECT COUNT(*) AS posts, COALESCE(SUM(views), 0) AS views,
              COALESCE(SUM(likes), 0) AS likes, COALESCE(SUM(comments), 0) AS comments, COALESCE(SUM(shares), 0) AS shares
       FROM posts WHERE channel = ? AND status = 'published' AND published_at >= ?`,
      [ch.key, since]
    );
    const upcoming = await db.all(
      `SELECT id, title, status, scheduled_at FROM posts
       WHERE channel = ? AND status IN ('draft', 'pending', 'scheduled') AND scheduled_at >= ?
       ORDER BY scheduled_at LIMIT 8`,
      [ch.key, dates.today() + ' 00:00:00']
    );
    const posts = await db.all(
      `SELECT id, title, published_at, published_url, views, likes, comments, shares FROM posts
       WHERE channel = ? AND status = 'published' ORDER BY published_at DESC LIMIT 10`,
      [ch.key]
    );
    const users = await db.all(`SELECT id, name FROM users WHERE active = 1 AND role IN ('admin', 'editor') ORDER BY name`);
    res.render('channels/show', {
      title: ch.name,
      ch,
      account,
      extra: parseExtra(account),
      conn: await connection(ch, account),
      oauthCallback: redirectUri(ch.key),
      insight: await ai.latest(ch.key),
      aiConfigured: ai.isConfigured(),
      stats,
      metrics,
      chartMetric,
      series,
      perf: Object.fromEntries(Object.entries(perf).map(([k, v]) => [k, Number(v || 0)])),
      upcoming,
      posts,
      users,
      today: dates.today(),
    });
  })
);

router.post(
  '/:channel/account',
  requirePerm('channel:edit'),
  h(async (req, res) => {
    const ch = channelOr404(req);
    await db.run('UPDATE channel_accounts SET display_name = ?, handle = ?, url = ?, owner_id = ?, notes = ?, updated_at = ? WHERE channel = ?', [
      str(req.body.display_name, 150),
      str(req.body.handle, 150),
      safeUrl(req.body.url) || null,
      int(req.body.owner_id) || null,
      str(req.body.notes, 5000),
      dates.now(),
      ch.key,
    ]);
    await logActivity(req.user, 'channel.update', 'channel', null, ch.name);
    flash(req, 'success', `Đã lưu thông tin kênh ${ch.name}.`);
    res.redirect(`/channels/${ch.key}`);
  })
);

// Nhập tay: chỉ dùng khi kênh chưa kết nối được
router.post(
  '/:channel/stats',
  requirePerm('channel:edit'),
  h(async (req, res) => {
    const ch = channelOr404(req);
    const day = dates.isDate(req.body.recorded_on) ? req.body.recorded_on : dates.today();
    const ratingRaw = String(req.body.rating ?? '').replace(',', '.').trim();
    const rating = ratingRaw === '' ? null : Math.min(5, Math.max(0, Number(ratingRaw) || 0));
    await db.run(
      `INSERT INTO channel_stats (channel, recorded_on, followers, reach, engagement, rating, reviews, note, source, created_by, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?, ?)`,
      [
        ch.key,
        day,
        nullableInt(req.body.followers),
        nullableInt(req.body.reach),
        nullableInt(req.body.engagement),
        ch.key === 'google' ? rating : null,
        ch.key === 'google' ? nullableInt(req.body.reviews) : null,
        str(req.body.note, 255),
        req.user.id,
        dates.now(),
      ]
    );
    await logActivity(req.user, 'channel.stats', 'channel', null, `${ch.name}: số liệu ngày ${dates.dmy(day)}`);
    flash(req, 'success', 'Đã ghi số liệu.');
    res.redirect(`/channels/${ch.key}`);
  })
);

router.post(
  '/:channel/stats/:id/delete',
  requirePerm('channel:edit'),
  h(async (req, res) => {
    const ch = channelOr404(req);
    await db.run('DELETE FROM channel_stats WHERE id = ? AND channel = ?', [int(req.params.id), ch.key]);
    flash(req, 'success', 'Đã xoá dòng số liệu.');
    res.redirect(`/channels/${ch.key}`);
  })
);

module.exports = router;
