// Ưu đãi và mã giảm giá. Mỗi ưu đãi có một mã riêng cho từng kênh (COMBO15FB, COMBO15TT…)
// để biết kênh nào mang khách về. Trong bài đăng, {{ma}} được thay bằng mã của kênh đang đăng.
const db = require('../db');
const dates = require('./dates');
const { str, int } = require('./util');

// Kênh nhận mã: 4 kênh mạng xã hội + email + khách đặt trực tiếp
const CODE_CHANNELS = [
  { key: 'facebook', suffix: 'FB', name: 'Facebook' },
  { key: 'tiktok', suffix: 'TT', name: 'TikTok' },
  { key: 'google', suffix: 'GG', name: 'Google Maps' },
  { key: 'zalo', suffix: 'ZL', name: 'Zalo OA' },
  { key: 'email', suffix: 'EM', name: 'Email' },
  { key: 'direct', suffix: '', name: 'Tại quầy, gọi điện' },
];

function normPrefix(s) {
  return String(s || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/đ/gi, 'd')
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, '')
    .slice(0, 14);
}

function readForm(body) {
  const start = dates.isDate(body.start_date) ? body.start_date : dates.today();
  let end = dates.isDate(body.end_date) ? body.end_date : start;
  if (end < start) end = start;
  const branchIds = [].concat(body.branch_ids || []).map(int).filter(Boolean);
  return {
    title: str(body.title, 200),
    summary: str(body.summary, 300),
    details: str(body.details, 5000),
    terms: str(body.terms, 5000),
    branch_ids: branchIds.length ? branchIds.join(',') : null,
    start_date: start,
    end_date: end,
    code_prefix: normPrefix(body.code_prefix),
    // Form có ô "Đang áp dụng" gửi kèm has_active; tạo mới mặc định đang áp dụng
    active: body.has_active ? (body.active ? 1 : 0) : 1,
  };
}

async function list() {
  return db.all(
    `SELECT o.*, (SELECT COUNT(*) FROM offer_redemptions r WHERE r.offer_id = o.id) AS used,
            (SELECT COALESCE(SUM(amount), 0) FROM offer_redemptions r WHERE r.offer_id = o.id) AS revenue
     FROM offers o ORDER BY o.active DESC, o.end_date DESC, o.id DESC`
  );
}

async function active(on = dates.today()) {
  return db.all('SELECT * FROM offers WHERE active = 1 AND end_date >= ? ORDER BY start_date', [on]);
}

async function get(id) {
  const o = await db.get('SELECT * FROM offers WHERE id = ?', [int(id)]);
  if (!o) return null;
  o.codes = await db.all(
    `SELECT c.*, (SELECT COUNT(*) FROM offer_redemptions r WHERE r.code_id = c.id) AS used,
            (SELECT COALESCE(SUM(amount), 0) FROM offer_redemptions r WHERE r.code_id = c.id) AS revenue
     FROM offer_codes c WHERE c.offer_id = ? ORDER BY c.id`,
    [o.id]
  );
  return o;
}

async function saveCodes(offerId, prefix) {
  for (const ch of CODE_CHANNELS) {
    const code = prefix + ch.suffix;
    const existing = await db.get('SELECT id FROM offer_codes WHERE offer_id = ? AND channel = ?', [offerId, ch.key]);
    if (existing) await db.run('UPDATE offer_codes SET code = ? WHERE id = ?', [code, existing.id]);
    else await db.run('INSERT INTO offer_codes (offer_id, channel, code) VALUES (?, ?, ?)', [offerId, ch.key, code]);
  }
}

async function validate(o, exceptId = 0) {
  if (!o.title) return 'Hãy đặt tên ưu đãi.';
  if (o.code_prefix.length < 3) return 'Mã ưu đãi cần ít nhất 3 ký tự chữ hoặc số, ví dụ COMBO15.';
  const codes = CODE_CHANNELS.map((c) => o.code_prefix + c.suffix);
  const clash = await db.get(
    `SELECT code FROM offer_codes WHERE offer_id <> ? AND code IN (${db.placeholders(codes.length)})`,
    [exceptId, ...codes]
  );
  if (clash) return `Mã ${clash.code} đã dùng cho ưu đãi khác. Chọn mã khác.`;
  return null;
}

async function create(body, user) {
  const o = readForm(body);
  const error = await validate(o);
  if (error) return { error, form: o };
  const r = await db.run(
    `INSERT INTO offers (title, summary, details, terms, branch_ids, start_date, end_date, code_prefix, active, created_by, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [o.title, o.summary, o.details, o.terms, o.branch_ids, o.start_date, o.end_date, o.code_prefix, o.active, user?.id || null, dates.now()]
  );
  await saveCodes(r.insertId, o.code_prefix);
  return { id: r.insertId };
}

async function update(id, body) {
  const o = readForm(body);
  const error = await validate(o, int(id));
  if (error) return { error, form: o };
  await db.run(
    'UPDATE offers SET title = ?, summary = ?, details = ?, terms = ?, branch_ids = ?, start_date = ?, end_date = ?, code_prefix = ?, active = ? WHERE id = ?',
    [o.title, o.summary, o.details, o.terms, o.branch_ids, o.start_date, o.end_date, o.code_prefix, o.active, int(id)]
  );
  await saveCodes(int(id), o.code_prefix);
  return { id: int(id) };
}

async function findCode(code) {
  return db.get(
    `SELECT c.*, o.title, o.start_date, o.end_date, o.active FROM offer_codes c JOIN offers o ON o.id = c.offer_id WHERE c.code = ?`,
    [normPrefix(code)]
  );
}

async function redeem(body, user) {
  const c = await findCode(body.code);
  if (!c) return { error: `Không tìm thấy mã "${str(body.code, 40)}".` };
  const usedOn = dates.isDate(body.used_on) ? body.used_on : dates.today();
  const warn = usedOn < c.start_date || usedOn > c.end_date ? `Lưu ý: mã dùng ngoài thời gian ưu đãi (${dates.dmy(c.start_date)} – ${dates.dmy(c.end_date)}).` : null;
  const nights = String(body.nights ?? '').trim() === '' ? null : Math.max(0, int(body.nights));
  const amount = String(body.amount ?? '').trim() === '' ? null : Math.max(0, int(body.amount));
  await db.run(
    `INSERT INTO offer_redemptions (offer_id, code_id, channel, branch_id, used_on, guest, nights, amount, note, created_by, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [c.offer_id, c.id, c.channel, int(body.branch_id) || null, usedOn, str(body.guest, 150), nights, amount, str(body.note, 255), user?.id || null, dates.now()]
  );
  return { ok: true, code: c, warn };
}

async function redemptions(offerId, limit = 100) {
  return db.all(
    `SELECT r.*, c.code, b.name AS branch_name, u.name AS user_name FROM offer_redemptions r
     LEFT JOIN offer_codes c ON c.id = r.code_id LEFT JOIN branches b ON b.id = r.branch_id LEFT JOIN users u ON u.id = r.created_by
     WHERE r.offer_id = ? ORDER BY r.used_on DESC, r.id DESC LIMIT ${Number(limit)}`,
    [offerId]
  );
}

async function codeFor(offerId, channel) {
  if (!offerId) return null;
  const r = await db.get('SELECT code FROM offer_codes WHERE offer_id = ? AND channel = ?', [offerId, channel]);
  return r ? r.code : null;
}

// Thay {{ma}} trong nội dung bằng mã của kênh
function applyCode(text, code) {
  return String(text || '').replace(/\{\{\s*ma\s*\}\}/gi, code || '');
}

module.exports = { CODE_CHANNELS, list, active, get, create, update, findCode, redeem, redemptions, codeFor, applyCode, normPrefix };
