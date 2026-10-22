// Tự động cập nhật số liệu kênh: gọi API từng kênh, ghi một dòng số liệu mỗi ngày,
// cập nhật lượt thích/bình luận/chia sẻ cho các bài đã đăng có link khớp.
const db = require('../db');
const dates = require('./dates');
const { encrypt, decrypt } = require('./secret');
const { logActivity } = require('./util');
const postChannels = require('./post-channels');

const connectors = {
  google: require('./connectors/google'),
  facebook: require('./connectors/facebook'),
  tiktok: require('./connectors/tiktok'),
  zalo: require('./connectors/zalo'),
};

const SYNC_EVERY_HOURS = 12;
const AI_EVERY_DAYS = 7;

async function getToken(channel) {
  const row = await db.get('SELECT * FROM channel_tokens WHERE channel = ?', [channel]);
  if (!row) return null;
  return { ...row, access_token: decrypt(row.access_token), refresh_token: decrypt(row.refresh_token) };
}

async function saveToken(channel, t) {
  const now = dates.now();
  const values = [
    encrypt(t.accessToken),
    encrypt(t.refreshToken),
    t.expiresIn ? dates.addSeconds(now, t.expiresIn) : null,
    t.refreshExpiresIn ? dates.addSeconds(now, t.refreshExpiresIn) : null,
    now,
  ];
  const exists = await db.get('SELECT channel FROM channel_tokens WHERE channel = ?', [channel]);
  if (exists) {
    await db.run('UPDATE channel_tokens SET access_token = ?, refresh_token = ?, expires_at = ?, refresh_expires_at = ?, updated_at = ? WHERE channel = ?', [...values, channel]);
  } else {
    await db.run('INSERT INTO channel_tokens (access_token, refresh_token, expires_at, refresh_expires_at, updated_at, channel) VALUES (?, ?, ?, ?, ?, ?)', [...values, channel]);
  }
}

async function disconnect(channel) {
  await db.run('DELETE FROM channel_tokens WHERE channel = ?', [channel]);
  await db.run(
    `UPDATE channel_accounts SET external_id = NULL, sync_status = NULL, sync_error = NULL, last_synced_at = NULL, extra = NULL, updated_at = ? WHERE channel = ?`,
    [dates.now(), channel]
  );
}

function isConnected(account, token) {
  if (account.channel === 'google') return Boolean(account.external_id);
  return Boolean(token?.access_token && account.external_id);
}

// Làm mới access token khi còn dưới 30 phút
async function freshToken(channel) {
  const conn = connectors[channel];
  let token = await getToken(channel);
  if (!token || !conn.refresh || !token.refresh_token) return token;
  if (token.expires_at && token.expires_at > dates.addSeconds(dates.now(), 1800)) return token;
  const t = await conn.refresh(token.refresh_token);
  await saveToken(channel, { ...t, refreshToken: t.refreshToken || token.refresh_token });
  return getToken(channel);
}

function normalizeUrl(u) {
  return String(u || '').split('?')[0].replace(/\/$/, '');
}

async function updatePostMetrics(channel, items) {
  const rows = await db.all(
    `SELECT pc.post_id, pc.published_url FROM post_channels pc JOIN posts p ON p.id = pc.post_id
     WHERE pc.channel = ? AND p.status = 'published' AND pc.published_url IS NOT NULL`,
    [channel]
  );
  const byUrl = new Map(rows.map((r) => [normalizeUrl(r.published_url), r.post_id]));
  let matched = 0;
  for (const it of items || []) {
    const postId = byUrl.get(normalizeUrl(it.url));
    if (!postId) continue;
    await db.run(
      'UPDATE post_channels SET views = COALESCE(?, views), likes = ?, comments = ?, shares = ?, updated_at = ? WHERE post_id = ? AND channel = ?',
      [it.views, it.likes, it.comments, it.shares, dates.now(), postId, channel]
    );
    await postChannels.recalcTotals(postId);
    matched++;
  }
  return matched;
}

async function sync(channel, user = null) {
  const conn = connectors[channel];
  const account = await db.get('SELECT * FROM channel_accounts WHERE channel = ?', [channel]);
  if (!conn || !account) return { ok: false, error: 'Không có kênh này.' };
  const now = dates.now();
  try {
    if (!conn.isConfigured()) throw new Error('Kênh này chưa được cấu hình khoá API trên máy chủ.');
    const token = conn.oauth ? await freshToken(channel) : null;
    if (!isConnected(account, token)) throw Object.assign(new Error('Kênh chưa được kết nối.'), { needsReconnect: true });
    const s = await conn.fetchStats(account, token);

    const today = dates.today();
    const row = [s.followers ?? null, s.reach ?? null, s.engagement ?? null, s.rating ?? null, s.reviews ?? null];
    const existing = await db.get(`SELECT id FROM channel_stats WHERE channel = ? AND recorded_on = ? AND source = 'auto'`, [channel, today]);
    if (existing) {
      await db.run('UPDATE channel_stats SET followers = ?, reach = ?, engagement = ?, rating = ?, reviews = ?, created_at = ? WHERE id = ?', [...row, now, existing.id]);
    } else {
      await db.run(
        `INSERT INTO channel_stats (channel, recorded_on, followers, reach, engagement, rating, reviews, source, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'auto', ?, ?)`,
        [channel, today, ...row, user?.id || null, now]
      );
    }

    const matched = await updatePostMetrics(channel, s.posts);
    const extra = { profile: s.profile || {}, recentReviews: s.recentReviews || [], posts: (s.posts || []).slice(0, 20), matched };
    await db.run(
      `UPDATE channel_accounts SET sync_status = 'ok', sync_error = NULL, last_synced_at = ?, extra = ?,
         handle = COALESCE(handle, ?), url = COALESCE(url, ?), updated_at = ? WHERE channel = ?`,
      [now, JSON.stringify(extra), s.profile?.handle || s.profile?.name || null, s.profile?.url || null, now, channel]
    );
    return { ok: true, matched };
  } catch (err) {
    const status = err.needsReconnect ? 'reconnect' : 'error';
    await db.run('UPDATE channel_accounts SET sync_status = ?, sync_error = ?, last_synced_at = ? WHERE channel = ?', [
      status,
      String(err.message || err).slice(0, 500),
      now,
      channel,
    ]);
    return { ok: false, error: err.message, status };
  }
}

// Chạy nền: cập nhật kênh đã kết nối mỗi 12 giờ, phân tích AI mỗi 7 ngày
let running = false;
async function tick() {
  if (running) return;
  running = true;
  try {
    const ai = require('./ai');
    const threshold = dates.addSeconds(dates.now(), -SYNC_EVERY_HOURS * 3600);
    for (const account of await db.all('SELECT * FROM channel_accounts')) {
      const token = connectors[account.channel]?.oauth ? await getToken(account.channel) : null;
      if (!isConnected(account, token)) continue;
      if (!account.last_synced_at || account.last_synced_at < threshold) {
        const r = await sync(account.channel);
        if (r.ok) await logActivity(null, 'channel.sync', 'channel', null, `Tự động cập nhật ${account.channel}`);
      }
      if (ai.isConfigured()) {
        const last = await db.get('SELECT created_at FROM channel_insights WHERE channel = ? ORDER BY id DESC LIMIT 1', [account.channel]);
        if (!last || last.created_at < dates.addSeconds(dates.now(), -AI_EVERY_DAYS * 86400)) {
          await ai.analyze(account.channel).catch((err) => console.error('[ai]', account.channel, err.message));
        }
      }
    }
  } catch (err) {
    console.error('[sync] Lỗi khi tự động cập nhật', err);
  } finally {
    running = false;
  }
}

function start() {
  setTimeout(tick, 60 * 1000);
  setInterval(tick, 30 * 60 * 1000).unref();
}

module.exports = { connectors, getToken, saveToken, disconnect, isConnected, sync, start };
