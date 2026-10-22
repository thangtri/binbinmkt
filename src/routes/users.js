const express = require('express');
const db = require('../db');
const dates = require('../lib/dates');
const { ROLES } = require('../lib/constants');
const { requireAdmin, hashPassword, validatePassword, normalizeUsername, validateUsername } = require('../lib/auth');
const { h, flash, logActivity, str, int, isEmail } = require('../lib/util');

const router = express.Router();
router.use(requireAdmin);

const LIST = 'SELECT id, username, email, name, role, active, created_at, last_login_at FROM users ORDER BY active DESC, name';

async function render(res, { error = null, form = { name: '', username: '', email: '', role: 'editor' }, status = 200 } = {}) {
  res.status(status).render('users/index', { title: 'Tài khoản', users: await db.all(LIST), error, form });
}

// Kiểm tra tên đăng nhập và email không trùng với tài khoản khác
async function checkIdentity({ username, email }, exceptId = 0) {
  const err = validateUsername(username);
  if (err) return err;
  if (!isEmail(email)) return 'Email không hợp lệ.';
  if (await db.get('SELECT id FROM users WHERE username = ? AND id <> ?', [username, exceptId])) return `Tên đăng nhập "${username}" đã có người dùng.`;
  if (await db.get('SELECT id FROM users WHERE email = ? AND id <> ?', [email, exceptId])) return `Email ${email} đã có tài khoản.`;
  return null;
}

router.get('/', h(async (req, res) => render(res)));

router.post(
  '/',
  h(async (req, res) => {
    const form = {
      name: str(req.body.name, 120),
      username: normalizeUsername(str(req.body.username, 40)),
      email: str(req.body.email, 190).toLowerCase(),
      role: ROLES[req.body.role] ? req.body.role : 'editor',
    };
    const error = (!form.name && 'Hãy nhập tên.') || (await checkIdentity(form)) || validatePassword(req.body.password);
    if (error) return render(res, { error, form, status: 400 });
    const r = await db.run('INSERT INTO users (username, email, name, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)', [
      form.username,
      form.email,
      form.name,
      hashPassword(req.body.password),
      form.role,
      dates.now(),
    ]);
    await logActivity(req.user, 'user.create', 'user', r.insertId, `${form.name} (${ROLES[form.role].label})`);
    flash(req, 'success', `Đã tạo tài khoản cho ${form.name}, tên đăng nhập "${form.username}". Gửi mật khẩu tạm cho họ qua kênh riêng và nhắc đổi mật khẩu.`);
    res.redirect('/users');
  })
);

async function adminsLeft(exceptId) {
  const r = await db.get(`SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND active = 1 AND id <> ?`, [exceptId]);
  return Number(r.n);
}

router.post(
  '/:id',
  h(async (req, res) => {
    const u = await db.get('SELECT * FROM users WHERE id = ?', [int(req.params.id)]);
    if (!u) return res.redirect('/users');
    const role = ROLES[req.body.role] ? req.body.role : u.role;
    const active = req.body.active ? 1 : 0;
    const username = normalizeUsername(str(req.body.username, 40)) || u.username;
    const email = str(req.body.email, 190).toLowerCase() || u.email;
    const back = (msg) => {
      flash(req, 'error', `${u.name}: ${msg}`);
      res.redirect('/users');
    };
    if ((role !== 'admin' || !active) && u.role === 'admin' && (await adminsLeft(u.id)) === 0) {
      return back('phải còn ít nhất một quản trị viên đang hoạt động.');
    }
    const idErr = await checkIdentity({ username, email }, u.id);
    if (idErr) return back(idErr);
    if (req.body.password) {
      const err = validatePassword(req.body.password);
      if (err) return back(err);
    }
    await db.run('UPDATE users SET name = ?, username = ?, email = ?, role = ?, active = ? WHERE id = ?', [
      str(req.body.name, 120) || u.name,
      username,
      email,
      role,
      active,
      u.id,
    ]);
    if (req.body.password) await db.run('UPDATE users SET password_hash = ? WHERE id = ?', [hashPassword(req.body.password), u.id]);
    await logActivity(req.user, 'user.update', 'user', u.id, u.name);
    flash(req, 'success', `Đã lưu tài khoản ${u.name}${username !== u.username ? `. Tên đăng nhập mới: "${username}"` : ''}.`);
    res.redirect('/users');
  })
);

module.exports = router;
