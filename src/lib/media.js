// Ảnh và video của một bài: nhiều file, có thứ tự. File đầu tiên là ảnh đại diện (posts.media_path).
const db = require('../db');
const dates = require('./dates');
const upload = require('./upload');

function isVideo(m) {
  return /^video\//.test(m.mime || '') || /\.(mp4|mov|webm)$/i.test(m.path || '');
}

async function list(postId) {
  return db.all('SELECT * FROM post_media WHERE post_id = ? ORDER BY position, id', [postId]);
}

async function syncCover(postId) {
  const first = (await list(postId))[0];
  await db.run('UPDATE posts SET media_path = ? WHERE id = ?', [first ? first.path : null, postId]);
}

async function add(postId, files) {
  if (!files || !files.length) return;
  const last = await db.get('SELECT MAX(position) AS p FROM post_media WHERE post_id = ?', [postId]);
  let pos = Number(last?.p ?? -1) + 1;
  for (const f of files) {
    await db.run('INSERT INTO post_media (post_id, path, mime, size, position, created_at) VALUES (?, ?, ?, ?, ?, ?)', [
      postId,
      upload.relPath(f),
      f.mimetype,
      f.size,
      pos++,
      dates.now(),
    ]);
  }
  await syncCover(postId);
}

// Form gửi: remove_<id>=1 để gỡ, media_order="id,id,id" để sắp xếp
async function applyForm(postId, body) {
  const items = await list(postId);
  for (const m of items) {
    if (body[`remove_${m.id}`]) {
      await db.run('DELETE FROM post_media WHERE id = ?', [m.id]);
      upload.remove(m.path);
    }
  }
  const order = String(body.media_order || '')
    .split(',')
    .map((s) => Number(s))
    .filter(Boolean);
  if (order.length) {
    const own = new Set(items.map((m) => m.id));
    let pos = 0;
    for (const id of order) if (own.has(id)) await db.run('UPDATE post_media SET position = ? WHERE id = ?', [pos++, id]);
  }
  await syncCover(postId);
}

// Nhân bản bài: chép file sang tên mới để xoá bài này không làm mất ảnh của bài kia
async function copy(fromId, toId) {
  const fs = require('fs');
  const path = require('path');
  const crypto = require('crypto');
  const config = require('../config');
  for (const m of await list(fromId)) {
    const src = path.resolve(config.uploadDir, m.path);
    if (!fs.existsSync(src)) continue;
    const rel = `${path.dirname(m.path)}/${crypto.randomBytes(12).toString('hex')}${path.extname(m.path)}`;
    await fs.promises.copyFile(src, path.resolve(config.uploadDir, rel));
    await db.run('INSERT INTO post_media (post_id, path, mime, size, position, created_at) VALUES (?, ?, ?, ?, ?, ?)', [
      toId,
      rel,
      m.mime,
      m.size,
      m.position,
      dates.now(),
    ]);
  }
  await syncCover(toId);
}

async function removeAll(postId) {
  for (const m of await list(postId)) upload.remove(m.path);
  await db.run('DELETE FROM post_media WHERE post_id = ?', [postId]);
}

module.exports = { list, add, applyForm, copy, removeAll, syncCover, isVideo };
