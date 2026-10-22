const crypto = require('crypto');
const db = require('../db');
const dates = require('./dates');

// Bọc handler async để lỗi được chuyển tới error handler của Express 4
const h = (fn) => (req, res, next) => Promise.resolve(fn(req, res, next)).catch(next);

function flash(req, type, message) {
  req.session.flash = { type, message };
}

async function logActivity(user, action, entity, entityId, summary) {
  await db.run(
    'INSERT INTO activity (user_id, action, entity, entity_id, summary, created_at) VALUES (?, ?, ?, ?, ?, ?)',
    [user?.id || null, action, entity, entityId || null, String(summary || '').slice(0, 255), dates.now()]
  );
}

function token(bytes = 24) {
  return crypto.randomBytes(bytes).toString('hex');
}

function str(v, max = 1000) {
  return String(v ?? '').trim().slice(0, max);
}

function int(v, fallback = 0) {
  const n = parseInt(String(v ?? '').replace(/[^\d-]/g, ''), 10);
  return Number.isFinite(n) ? n : fallback;
}

function isEmail(s) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(s || ''));
}

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function safeUrl(u) {
  const s = str(u, 500);
  return /^https?:\/\//i.test(s) ? s : '';
}

function parseTags(s) {
  return [...new Set(String(s || '').split(',').map((t) => t.trim().toLowerCase()).filter(Boolean))].join(', ');
}

function numberVi(n) {
  return Number(n || 0).toLocaleString('vi-VN');
}

module.exports = { h, flash, logActivity, token, str, int, isEmail, escapeHtml, safeUrl, parseTags, numberVi };
