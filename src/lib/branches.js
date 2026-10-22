// Chi nhánh của chuỗi. Mỗi chi nhánh có địa điểm Google Maps riêng (điểm, số đánh giá, đánh giá mới).
const db = require('../db');
const dates = require('./dates');
const { str, safeUrl } = require('./util');

async function list({ all = false } = {}) {
  return db.all(`SELECT * FROM branches ${all ? '' : 'WHERE active = 1'} ORDER BY position, id`);
}

async function get(id) {
  return db.get('SELECT * FROM branches WHERE id = ?', [Number(id)]);
}

function readForm(body) {
  return {
    name: str(body.name, 150),
    address: str(body.address, 300),
    hotline: str(body.hotline, 60),
    zalo: str(body.zalo, 150),
    position: Number(body.position) || 0,
  };
}

async function create(body) {
  const b = readForm(body);
  if (!b.name) throw Object.assign(new Error('Hãy nhập tên chi nhánh.'), { expose: true });
  const r = await db.run('INSERT INTO branches (name, address, hotline, zalo, position, active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)', [
    b.name,
    b.address,
    b.hotline,
    b.zalo,
    b.position,
    dates.now(),
  ]);
  return r.insertId;
}

async function update(id, body) {
  const b = readForm(body);
  if (!b.name) throw Object.assign(new Error('Tên chi nhánh không được để trống.'), { expose: true });
  await db.run('UPDATE branches SET name = ?, address = ?, hotline = ?, zalo = ?, position = ?, active = ? WHERE id = ?', [
    b.name,
    b.address,
    b.hotline,
    b.zalo,
    b.position,
    body.active ? 1 : 0,
    Number(id),
  ]);
}

async function setPlace(id, { placeId, url }) {
  await db.run('UPDATE branches SET place_id = ?, maps_url = ?, sync_status = NULL, sync_error = NULL WHERE id = ?', [placeId, safeUrl(url) || null, Number(id)]);
}

function parseExtra(b) {
  try {
    return JSON.parse(b?.extra || '{}');
  } catch {
    return {};
  }
}

// Số liệu Google mới nhất của từng chi nhánh, kèm lần ghi trước để tính mức thay đổi
async function googleSnapshot() {
  const out = [];
  for (const b of await list()) {
    const rows = await db.all(
      `SELECT recorded_on, rating, reviews FROM channel_stats WHERE channel = 'google' AND branch_id = ? AND (rating IS NOT NULL OR reviews IS NOT NULL)
       ORDER BY recorded_on DESC, id DESC LIMIT 2`,
      [b.id]
    );
    out.push({ ...b, latest: rows[0] || null, previous: rows[1] || null, extra: parseExtra(b) });
  }
  return out;
}

// Điểm trung bình cả chuỗi, tính theo số đánh giá của từng chi nhánh
function chainRating(snap) {
  let sum = 0;
  let count = 0;
  for (const b of snap) {
    if (!b.latest || b.latest.rating == null) continue;
    const n = Number(b.latest.reviews) || 0;
    sum += Number(b.latest.rating) * (n || 1);
    count += n || 1;
  }
  const reviews = snap.reduce((s, b) => s + (Number(b.latest?.reviews) || 0), 0);
  return { rating: count ? Math.round((sum / count) * 10) / 10 : null, reviews, branches: snap.length, tracked: snap.filter((b) => b.place_id).length };
}

module.exports = { list, get, create, update, setPlace, parseExtra, googleSnapshot, chainRating };
