// Đối thủ trên Google Maps: vài khách sạn gần mỗi chi nhánh, lấy điểm và số đánh giá mỗi ngày
// để so sánh với chi nhánh theo thời gian.
const db = require('../db');
const dates = require('./dates');
const { int, safeUrl } = require('./util');

function google() {
  return require('./connectors/google');
}

async function upsertStat(competitorId, s) {
  const today = dates.today();
  const existing = await db.get('SELECT id FROM competitor_stats WHERE competitor_id = ? AND recorded_on = ?', [competitorId, today]);
  if (existing) await db.run('UPDATE competitor_stats SET rating = ?, reviews = ?, created_at = ? WHERE id = ?', [s.rating ?? null, s.reviews ?? null, dates.now(), existing.id]);
  else
    await db.run('INSERT INTO competitor_stats (competitor_id, recorded_on, rating, reviews, created_at) VALUES (?, ?, ?, ?, ?)', [
      competitorId,
      today,
      s.rating ?? null,
      s.reviews ?? null,
      dates.now(),
    ]);
}

async function syncOne(c) {
  try {
    const s = await google().fetchStats({ external_id: c.place_id });
    await upsertStat(c.id, s);
    await db.run(`UPDATE competitors SET sync_status = 'ok', sync_error = NULL, last_synced_at = ?, extra = ?, name = COALESCE(NULLIF(?, ''), name) WHERE id = ?`, [
      dates.now(),
      JSON.stringify({ profile: s.profile || {}, recentReviews: s.recentReviews || [] }),
      s.profile?.name || '',
      c.id,
    ]);
    return true;
  } catch (err) {
    await db.run(`UPDATE competitors SET sync_status = 'error', sync_error = ?, last_synced_at = ? WHERE id = ?`, [String(err.message).slice(0, 500), dates.now(), c.id]);
    return false;
  }
}

async function add({ branchId, link }) {
  const placeId = await google().resolveLink(link);
  const dup = await db.get('SELECT id FROM competitors WHERE place_id = ? AND branch_id = ? AND active = 1', [placeId, int(branchId) || null]);
  if (dup) throw new Error('Đối thủ này đã có trong danh sách của chi nhánh.');
  const r = await db.run('INSERT INTO competitors (branch_id, name, maps_url, place_id, active, created_at) VALUES (?, ?, ?, ?, 1, ?)', [
    int(branchId) || null,
    'Đang lấy tên…',
    safeUrl(link) || null,
    placeId,
    dates.now(),
  ]);
  const c = await db.get('SELECT * FROM competitors WHERE id = ?', [r.insertId]);
  await syncOne(c);
  return db.get('SELECT * FROM competitors WHERE id = ?', [r.insertId]);
}

async function remove(id) {
  await db.run('UPDATE competitors SET active = 0 WHERE id = ?', [int(id)]);
}

async function syncAll() {
  let ok = 0;
  for (const c of await db.all('SELECT * FROM competitors WHERE active = 1 AND place_id IS NOT NULL')) if (await syncOne(c)) ok++;
  return ok;
}

// Số liệu mới nhất và số đánh giá tăng thêm trong 30 ngày (so với lần ghi gần nhất trước đó 30 ngày)
async function trend(table, keyCol, id) {
  const latest = await db.get(`SELECT recorded_on, rating, reviews FROM ${table} WHERE ${keyCol} = ? AND (rating IS NOT NULL OR reviews IS NOT NULL) ORDER BY recorded_on DESC, id DESC LIMIT 1`, [id]);
  if (!latest) return { latest: null, growth30: null };
  const base = await db.get(
    `SELECT reviews FROM ${table} WHERE ${keyCol} = ? AND reviews IS NOT NULL AND recorded_on <= ? ORDER BY recorded_on DESC, id DESC LIMIT 1`,
    [id, dates.addDays(latest.recorded_on, -30)]
  );
  const first = base || (await db.get(`SELECT reviews, recorded_on FROM ${table} WHERE ${keyCol} = ? AND reviews IS NOT NULL ORDER BY recorded_on, id LIMIT 1`, [id]));
  const growth30 = first && latest.reviews != null && first.reviews != null ? Number(latest.reviews) - Number(first.reviews) : null;
  return { latest, growth30, since: base ? '30 ngày' : first?.recorded_on };
}

// Bảng so sánh theo từng chi nhánh: chi nhánh ở dòng đầu, đối thủ bên dưới
async function comparison() {
  const branches = await require('./branches').list();
  const out = [];
  for (const b of branches) {
    const own = await trend('channel_stats', "channel = 'google' AND branch_id", b.id);
    const rivals = [];
    for (const c of await db.all('SELECT * FROM competitors WHERE active = 1 AND branch_id = ? ORDER BY name', [b.id])) {
      rivals.push({ ...c, ...(await trend('competitor_stats', 'competitor_id', c.id)) });
    }
    out.push({ branch: b, own, rivals });
  }
  return out;
}

module.exports = { add, remove, syncAll, comparison };
