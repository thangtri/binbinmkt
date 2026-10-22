const crypto = require('crypto');
const db = require('../db');

const KEYLEN = 64;

function hashPassword(password) {
  const salt = crypto.randomBytes(16).toString('hex');
  const hash = crypto.scryptSync(password, salt, KEYLEN).toString('hex');
  return `scrypt$${salt}$${hash}`;
}

function verifyPassword(password, stored) {
  const [scheme, salt, hash] = String(stored || '').split('$');
  if (scheme !== 'scrypt' || !salt || !hash) return false;
  const expected = Buffer.from(hash, 'hex');
  const actual = crypto.scryptSync(password, salt, expected.length);
  return crypto.timingSafeEqual(expected, actual);
}

function validatePassword(pw) {
  if (!pw || pw.length < 8) return 'Mật khẩu cần ít nhất 8 ký tự.';
  return null;
}

// Quyền theo vai trò. Admin làm được mọi việc.
const PERMISSIONS = {
  editor: new Set([
    'post:create',
    'post:edit',
    'post:submit',
    'post:publish',
    'event:edit',
    'channel:edit',
    'contact:edit',
    'campaign:edit',
  ]),
  viewer: new Set(),
};

function can(user, perm) {
  if (!user) return false;
  if (user.role === 'admin') return true;
  return PERMISSIONS[user.role]?.has(perm) || false;
}

async function loadUser(req, res, next) {
  req.user = null;
  const id = req.session?.uid;
  if (id) {
    const user = await db.get('SELECT id, email, name, role, active FROM users WHERE id = ?', [id]);
    if (user && user.active) req.user = user;
    else req.session = null;
  }
  next();
}

function requireLogin(req, res, next) {
  if (req.user) return next();
  if (req.accepts(['html', 'json']) === 'json') return res.status(401).json({ error: 'Chưa đăng nhập' });
  res.redirect('/login?next=' + encodeURIComponent(req.originalUrl));
}

function requirePerm(perm) {
  return (req, res, next) => {
    if (can(req.user, perm)) return next();
    res.status(403);
    if (req.accepts(['html', 'json']) === 'json') return res.json({ error: 'Bạn không có quyền làm việc này' });
    res.render('error', { title: 'Không có quyền', message: 'Tài khoản của bạn không có quyền làm việc này. Liên hệ quản trị viên nếu cần.' });
  };
}

const requireAdmin = (req, res, next) => {
  if (req.user?.role === 'admin') return next();
  return requirePerm('__admin_only__')(req, res, next);
};

module.exports = { hashPassword, verifyPassword, validatePassword, can, loadUser, requireLogin, requirePerm, requireAdmin };
