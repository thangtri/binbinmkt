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

// Tên đăng nhập: 3–30 ký tự, chữ thường không dấu, số, dấu chấm, gạch dưới, gạch ngang
const USERNAME_RE = /^[a-z0-9](?:[a-z0-9._-]{1,28})[a-z0-9]$/;

function normalizeUsername(s) {
  return String(s || '').trim().toLowerCase();
}

function validateUsername(s) {
  if (!USERNAME_RE.test(s)) {
    return 'Tên đăng nhập dài 3–30 ký tự, chỉ gồm chữ thường không dấu, số, dấu chấm, gạch dưới hoặc gạch ngang, không bắt đầu hay kết thúc bằng dấu.';
  }
  return null;
}

// Gợi ý tên đăng nhập từ email (phần trước @), thêm số nếu đã có người dùng
function suggestUsername(email, taken) {
  let base = String(email || '')
    .split('@')[0]
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/đ/gi, 'd')
    .toLowerCase()
    .replace(/[^a-z0-9._-]/g, '')
    .replace(/^[._-]+|[._-]+$/g, '')
    .slice(0, 26);
  if (base.length < 3) base = `user${base}`;
  let name = base;
  for (let n = 2; taken.has(name); n++) name = `${base}${n}`;
  return name;
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
    const user = await db.get('SELECT id, username, email, name, role, active, session_version FROM users WHERE id = ?', [id]);
    // session_version tăng khi đổi/đặt lại mật khẩu → mọi phiên đăng nhập cũ hết hiệu lực
    if (user && user.active && Number(req.session.sv || 1) === Number(user.session_version)) req.user = user;
    else {
      // Phiên hết hiệu lực: xoá thông tin đăng nhập nhưng giữ session để các bước sau (CSRF) vẫn dùng được
      req.session.uid = null;
      req.session.sv = null;
    }
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

module.exports = { hashPassword, verifyPassword, validatePassword, normalizeUsername, validateUsername, suggestUsername, can, loadUser, requireLogin, requirePerm, requireAdmin };
