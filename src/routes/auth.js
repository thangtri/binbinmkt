const crypto = require('crypto');
const express = require('express');
const config = require('../config');
const brevo = require('../lib/brevo');
const db = require('../db');
const dates = require('../lib/dates');
const { verifyPassword, hashPassword, validatePassword, normalizeUsername, requireLogin } = require('../lib/auth');
const { h, flash, str, isEmail, logActivity, escapeHtml } = require('../lib/util');

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
    req.session.sv = Number(user.session_version) || 1;
    res.redirect(next);
  })
);

router.post('/logout', (req, res) => {
  req.session = null;
  res.redirect('/login');
});

/* ---------- Quên mật khẩu ---------- */

const RESET_MINUTES = 60;
const resetAsks = new Map();

function hashToken(t) {
  return crypto.createHash('sha256').update(t).digest('hex');
}

router.get('/forgot', (req, res) => {
  res.render('forgot', { title: 'Quên mật khẩu', sent: false, error: null, value: '' });
});

router.post(
  '/forgot',
  h(async (req, res) => {
    const value = str(req.body.username, 190).toLowerCase();
    // Tối đa 5 yêu cầu mỗi 15 phút cho một IP
    const a = resetAsks.get(req.ip);
    if (a && a.count >= 5 && Date.now() - a.first < 15 * 60 * 1000) {
      return res.status(429).render('forgot', { title: 'Quên mật khẩu', sent: false, value, error: 'Bạn đã yêu cầu quá nhiều lần. Thử lại sau 15 phút.' });
    }
    if (!a || Date.now() - a.first > 15 * 60 * 1000) resetAsks.set(req.ip, { count: 1, first: Date.now() });
    else a.count++;

    const user = await db.get('SELECT * FROM users WHERE (username = ? OR email = ?) AND active = 1', [value, value]);
    if (user) {
      const token = crypto.randomBytes(32).toString('hex');
      await db.run('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)', [
        user.id,
        hashToken(token),
        dates.addSeconds(dates.now(), RESET_MINUTES * 60),
        dates.now(),
      ]);
      const link = `${config.appUrl}/reset/${token}`;
      const html = brevo.renderEmail({
        subject: 'Đặt lại mật khẩu Bin Bin Marketing',
        preheader: `Link có hiệu lực trong ${RESET_MINUTES} phút`,
        bodyHtml: `<p>Chào ${escapeHtml(user.name)},</p>
<p>Có yêu cầu đặt lại mật khẩu cho tài khoản <strong>${escapeHtml(user.username)}</strong> trên Bin Bin Marketing.</p>
<p><a href="${link}" style="display:inline-block;background:#c8232b;color:#ffffff;padding:12px 20px;border-radius:999px;text-decoration:none;">Đặt mật khẩu mới</a></p>
<p>Link có hiệu lực trong ${RESET_MINUTES} phút và chỉ dùng được một lần. Nếu bạn không yêu cầu, hãy bỏ qua email này; mật khẩu hiện tại vẫn giữ nguyên.</p>`,
        unsubscribeUrl: config.appUrl,
        transactional: true,
      });
      try {
        const out = await brevo.send({ to: user.email, name: user.name, subject: 'Đặt lại mật khẩu Bin Bin Marketing', html, tag: 'password-reset' });
        if (out.dryRun) console.log(`[forgot] Chế độ thử, chưa gửi email. Link đặt lại cho ${user.username}: ${link}`);
      } catch (err) {
        console.error('[forgot] Không gửi được email đặt lại mật khẩu:', err.message);
      }
    }
    // Luôn trả cùng một thông báo để không lộ tài khoản nào tồn tại
    res.render('forgot', { title: 'Quên mật khẩu', sent: true, error: null, value: '' });
  })
);

async function validReset(token) {
  if (!/^[a-f0-9]{64}$/.test(String(token || ''))) return null;
  const r = await db.get('SELECT * FROM password_resets WHERE token_hash = ?', [hashToken(token)]);
  if (!r || r.used_at || r.expires_at < dates.now()) return null;
  return r;
}

router.get(
  '/reset/:token',
  h(async (req, res) => {
    const r = await validReset(req.params.token);
    res.render('reset', { title: 'Đặt mật khẩu mới', valid: Boolean(r), error: null });
  })
);

router.post(
  '/reset/:token',
  h(async (req, res) => {
    const r = await validReset(req.params.token);
    if (!r) return res.status(400).render('reset', { title: 'Đặt mật khẩu mới', valid: false, error: null });
    const err = validatePassword(req.body.password) || (req.body.password !== req.body.confirm ? 'Hai lần nhập mật khẩu không khớp.' : null);
    if (err) return res.status(400).render('reset', { title: 'Đặt mật khẩu mới', valid: true, error: err });
    await db.run('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [hashPassword(req.body.password), r.user_id]);
    await db.run('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [dates.now(), r.user_id]);
    req.session = null;
    res.render('reset', { title: 'Đã đặt mật khẩu mới', valid: false, done: true, error: null });
  })
);

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
    await db.run('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [hashPassword(req.body.password), req.user.id]);
    // Giữ phiên hiện tại, đăng xuất các thiết bị khác
    req.session.sv = Number(req.user.session_version) + 1;
    flash(req, 'success', 'Đã đổi mật khẩu. Các thiết bị khác đã được đăng xuất.');
    res.redirect('/account');
  })
);

module.exports = router;
