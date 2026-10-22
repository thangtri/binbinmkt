const express = require('express');
const db = require('../db');
const dates = require('../lib/dates');
const { verifyPassword, hashPassword, validatePassword, requireLogin } = require('../lib/auth');
const { h, flash, str } = require('../lib/util');

const router = express.Router();

// Chặn dò mật khẩu: tối đa 8 lần sai trong 15 phút cho mỗi IP
const attempts = new Map();
function tooMany(ip) {
  const a = attempts.get(ip);
  return a && a.count >= 8 && Date.now() - a.first < 15 * 60 * 1000;
}
function fail(ip) {
  const a = attempts.get(ip);
  if (!a || Date.now() - a.first > 15 * 60 * 1000) attempts.set(ip, { count: 1, first: Date.now() });
  else a.count++;
}

function safeNext(n) {
  return typeof n === 'string' && n.startsWith('/') && !n.startsWith('//') ? n : '/';
}

router.get('/login', (req, res) => {
  if (req.user) return res.redirect('/');
  res.render('login', { title: 'Đăng nhập', next: safeNext(req.query.next), email: '', error: null });
});

router.post(
  '/login',
  h(async (req, res) => {
    const email = str(req.body.email, 190).toLowerCase();
    const next = safeNext(req.body.next);
    const render = (error) => res.status(400).render('login', { title: 'Đăng nhập', next, email, error });
    if (tooMany(req.ip)) return render('Đăng nhập sai quá nhiều lần. Thử lại sau 15 phút.');
    const user = await db.get('SELECT * FROM users WHERE email = ?', [email]);
    if (!user || !verifyPassword(String(req.body.password || ''), user.password_hash)) {
      fail(req.ip);
      return render('Email hoặc mật khẩu không đúng.');
    }
    if (!Number(user.active)) return render('Tài khoản này đã bị khoá. Liên hệ quản trị viên.');
    attempts.delete(req.ip);
    await db.run('UPDATE users SET last_login_at = ? WHERE id = ?', [dates.now(), user.id]);
    req.session.uid = user.id;
    res.redirect(next);
  })
);

router.post('/logout', (req, res) => {
  req.session = null;
  res.redirect('/login');
});

router.get('/account', requireLogin, (req, res) => {
  res.render('account', { title: 'Tài khoản của tôi', error: null });
});

router.post(
  '/account/password',
  requireLogin,
  h(async (req, res) => {
    const row = await db.get('SELECT password_hash FROM users WHERE id = ?', [req.user.id]);
    const render = (error) => res.status(400).render('account', { title: 'Tài khoản của tôi', error });
    if (!verifyPassword(String(req.body.current || ''), row.password_hash)) return render('Mật khẩu hiện tại không đúng.');
    const err = validatePassword(req.body.password);
    if (err) return render(err);
    if (req.body.password !== req.body.confirm) return render('Hai lần nhập mật khẩu mới không khớp.');
    await db.run('UPDATE users SET password_hash = ? WHERE id = ?', [hashPassword(req.body.password), req.user.id]);
    flash(req, 'success', 'Đã đổi mật khẩu.');
    res.redirect('/account');
  })
);

module.exports = router;
