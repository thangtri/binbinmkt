// Link và số liệu của một bài trên từng kênh. Tổng số liệu được ghi lại vào bảng posts
// để các trang danh sách, tổng quan không phải cộng mỗi lần.
const db = require('../db');
const dates = require('./dates');
const { CHANNELS } = require('./constants');

async function ensure(postId) {
  const have = new Set((await db.all('SELECT channel FROM post_channels WHERE post_id = ?', [postId])).map((r) => r.channel));
  for (const ch of Object.keys(CHANNELS)) {
    if (!have.has(ch)) await db.run('INSERT INTO post_channels (post_id, channel, updated_at) VALUES (?, ?, ?)', [postId, ch, dates.now()]);
  }
}

async function list(postId) {
  const rows = await db.all('SELECT * FROM post_channels WHERE post_id = ?', [postId]);
  const byKey = Object.fromEntries(rows.map((r) => [r.channel, r]));
  return Object.keys(CHANNELS).map((ch) => byKey[ch] || { channel: ch });
}

async function recalcTotals(postId) {
  const t = await db.get(
    `SELECT COALESCE(SUM(views), 0) AS views, COALESCE(SUM(likes), 0) AS likes,
            COALESCE(SUM(comments), 0) AS comments, COALESCE(SUM(shares), 0) AS shares,
            MIN(published_url) AS url
     FROM post_channels WHERE post_id = ?`,
    [postId]
  );
  await db.run('UPDATE posts SET views = ?, likes = ?, comments = ?, shares = ?, published_url = ? WHERE id = ?', [
    Number(t.views),
    Number(t.likes),
    Number(t.comments),
    Number(t.shares),
    t.url || null,
    postId,
  ]);
}

async function update(postId, channel, fields) {
  const cols = Object.keys(fields);
  if (!cols.length) return;
  await ensure(postId);
  await db.run(`UPDATE post_channels SET ${cols.map((c) => `${c} = ?`).join(', ')}, updated_at = ? WHERE post_id = ? AND channel = ?`, [
    ...cols.map((c) => fields[c]),
    dates.now(),
    postId,
    channel,
  ]);
}

/* Google Maps đăng riêng từng chi nhánh: link lưu trong post_google.
   Dòng 'google' của bài chỉ có link khi mọi chi nhánh đang hoạt động đều đã đăng. */

async function googleLinks(postId) {
  return db.all(
    `SELECT b.id AS branch_id, b.name, pg.published_url FROM branches b
     LEFT JOIN post_google pg ON pg.branch_id = b.id AND pg.post_id = ?
     WHERE b.active = 1 ORDER BY b.position, b.id`,
    [postId]
  );
}

async function saveGoogleLink(postId, branchId, url) {
  const exists = await db.get('SELECT id FROM post_google WHERE post_id = ? AND branch_id = ?', [postId, branchId]);
  if (exists) await db.run('UPDATE post_google SET published_url = ?, updated_at = ? WHERE id = ?', [url, dates.now(), exists.id]);
  else await db.run('INSERT INTO post_google (post_id, branch_id, published_url, updated_at) VALUES (?, ?, ?, ?)', [postId, branchId, url, dates.now()]);
}

async function syncGoogleRow(postId) {
  const links = await googleLinks(postId);
  const done = links.length > 0 && links.every((l) => l.published_url);
  await update(postId, 'google', { published_url: done ? links[0].published_url : null });
}

module.exports = { ensure, list, update, recalcTotals, googleLinks, saveGoogleLink, syncGoogleRow };
