const express = require('express');
const db = require('../db');
const dates = require('../lib/dates');
const { verifyPassword, hashPassword, validatePassword, normalizeUsername, requireLogin } = require('../lib/auth');
const { h, flash, str, isEmail, logActivity } = require('../lib/util');

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
  res.render('login', { title: 'Đăng nhập', next: safeNext(req.query.next), username: '', error: null });
});

router.post(
  '/login',
  h(async (req, res) => {
    const username = normalizeUsername(str(req.body.username, 190));
    const next = safeNext(req.body.next);
    const render = (error) => res.status(400).render('login', { title: 'Đăng nhập', next, username, error });
    if (tooMany(req.ip)) return render('Đăng nhập sai quá nhiều lần. Thử lại sau 15 phút.');
    const user = await db.get('SELECT * FROM users WHERE username = ?', [username]);
    if (!user || !verifyPassword(String(req.body.password || ''), user.password_hash)) {
      fail(req.ip);
      return render('Tên đăng nhập hoặc mật khẩu không đúng.');
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

function renderAccount(res, { error = null, emailError = null, email } = {}) {
  res.status(error || emailError ? 400 : 200).render('account', {
    title: 'Tài khoản của tôi',
    error,
    emailError,
    email: email ?? res.locals.user.email,
  });
}

router.get('/account', requireLogin, (req, res) => renderAccount(res));

router.post(
  '/account/email',
  requireLogin,
  h(async (req, res) => {
    const email = str(req.body.email, 190).toLowerCase();
    const row = await db.get('SELECT password_hash FROM users WHERE id = ?', [req.user.id]);
    if (!isEmail(email)) return renderAccount(res, { emailError: 'Email không hợp lệ.', email });
    if (!verifyPassword(String(req.body.password || ''), row.password_hash)) {
      return renderAccount(res, { emailError: 'Mật khẩu hiện tại không đúng.', email });
    }
    if (email === req.user.email) return res.redirect('/account');
    if (await db.get('SELECT id FROM users WHERE email = ? AND id <> ?', [email, req.user.id])) {
      return renderAccount(res, { emailError: 'Email này đã được dùng cho tài khoản khác.', email });
    }
    await db.run('UPDATE users SET email = ? WHERE id = ?', [email, req.user.id]);
    await logActivity(req.user, 'user.email', 'user', req.user.id, `${req.user.email} → ${email}`);
    flash(req, 'success', `Đã đổi email thành ${email}.`);
    res.redirect('/account');
  })
);

router.post(
  '/account/password',
  requireLogin,
  h(async (req, res) => {
    const row = await db.get('SELECT password_hash FROM users WHERE id = ?', [req.user.id]);
    const render = (error) => renderAccount(res, { error });
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
