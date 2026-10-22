const express = require('express');
const db = require('../db');
const dates = require('../lib/dates');
const { CHANNELS } = require('../lib/constants');
const { requirePerm } = require('../lib/auth');
const { h, flash, logActivity, str, int, safeUrl } = require('../lib/util');

const router = express.Router();

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
      const primary = summarize(stats, ch.key === 'google' ? METRICS.google[0] : METRICS.social[0]);
      const reviews = ch.key === 'google' ? summarize(stats, METRICS.google[1]) : null;
      const perf = await db.get(
        `SELECT COUNT(*) AS posts, COALESCE(SUM(views), 0) AS views, COALESCE(SUM(likes + comments + shares), 0) AS interactions
         FROM posts WHERE channel = ? AND status = 'published' AND published_at >= ?`,
        [ch.key, since]
      );
      const upcoming = await db.get(
        `SELECT COUNT(*) AS n FROM posts WHERE channel = ? AND status IN ('pending', 'scheduled') AND scheduled_at >= ?`,
        [ch.key, dates.now()]
      );
      rows.push({ ch, account, primary, reviews, perf, upcoming: Number(upcoming.n) });
    }
    res.render('channels/index', { title: 'Kênh mạng xã hội', rows });
  })
);

// Chỉ số chính của từng kênh. Google Maps không có "người theo dõi" nên dùng đánh giá.
const METRICS = {
  social: [
    { key: 'followers', label: 'Người theo dõi' },
    { key: 'reach', label: 'Tiếp cận' },
    { key: 'engagement', label: 'Tương tác' },
  ],
  google: [
    { key: 'rating', label: 'Điểm đánh giá', decimals: 1 },
    { key: 'reviews', label: 'Số đánh giá' },
    { key: 'reach', label: 'Lượt xem hồ sơ' },
    { key: 'engagement', label: 'Lượt gọi, chỉ đường' },
  ],
};

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
    const metrics = (ch.key === 'google' ? METRICS.google : METRICS.social).map((m) => summarize(stats, m));
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

router.post(
  '/:channel/stats',
  requirePerm('channel:edit'),
  h(async (req, res) => {
    const ch = channelOr404(req);
    const day = dates.isDate(req.body.recorded_on) ? req.body.recorded_on : dates.today();
    const ratingRaw = String(req.body.rating ?? '').replace(',', '.').trim();
    const rating = ratingRaw === '' ? null : Math.min(5, Math.max(0, Number(ratingRaw) || 0));
    await db.run(
      `INSERT INTO channel_stats (channel, recorded_on, followers, reach, engagement, rating, reviews, note, created_by, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
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
