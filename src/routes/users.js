const express = require('express');
const db = require('../db');
const dates = require('../lib/dates');
const { ROLES } = require('../lib/constants');
const { requireAdmin, hashPassword, validatePassword } = require('../lib/auth');
const { h, flash, logActivity, str, int, isEmail } = require('../lib/util');

const router = express.Router();
router.use(requireAdmin);

router.get(
  '/',
  h(async (req, res) => {
    const users = await db.all('SELECT id, email, name, role, active, created_at, last_login_at FROM users ORDER BY active DESC, name');
    res.render('users/index', { title: 'Tài khoản', users, error: null, form: { name: '', email: '', role: 'editor' } });
  })
);

router.post(
  '/',
  h(async (req, res) => {
    const form = { name: str(req.body.name, 120), email: str(req.body.email, 190).toLowerCase(), role: ROLES[req.body.role] ? req.body.role : 'editor' };
    let error = null;
    if (!form.name) error = 'Hãy nhập tên.';
    else if (!isEmail(form.email)) error = 'Email không hợp lệ.';
    else if (await db.get('SELECT id FROM users WHERE email = ?', [form.email])) error = 'Email này đã có tài khoản.';
    else error = validatePassword(req.body.password);
    if (error) {
      const users = await db.all('SELECT id, email, name, role, active, created_at, last_login_at FROM users ORDER BY active DESC, name');
      return res.status(400).render('users/index', { title: 'Tài khoản', users, error, form });
    }
    const r = await db.run('INSERT INTO users (email, name, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)', [
      form.email,
      form.name,
      hashPassword(req.body.password),
      form.role,
      dates.now(),
    ]);
    await logActivity(req.user, 'user.create', 'user', r.insertId, `${form.name} (${ROLES[form.role].label})`);
    flash(req, 'success', `Đã tạo tài khoản cho ${form.name}. Gửi mật khẩu tạm cho họ qua kênh riêng và nhắc đổi mật khẩu.`);
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
    if ((role !== 'admin' || !active) && u.role === 'admin' && (await adminsLeft(u.id)) === 0) {
      flash(req, 'error', 'Phải còn ít nhất một quản trị viên đang hoạt động.');
      return res.redirect('/users');
    }
    await db.run('UPDATE users SET name = ?, role = ?, active = ? WHERE id = ?', [str(req.body.name, 120) || u.name, role, active, u.id]);
    if (req.body.password) {
      const err = validatePassword(req.body.password);
      if (err) {
        flash(req, 'error', err);
        return res.redirect('/users');
      }
      await db.run('UPDATE users SET password_hash = ? WHERE id = ?', [hashPassword(req.body.password), u.id]);
    }
    await logActivity(req.user, 'user.update', 'user', u.id, u.name);
    flash(req, 'success', `Đã lưu tài khoản ${u.name}.`);
    res.redirect('/users');
  })
);

module.exports = router;
