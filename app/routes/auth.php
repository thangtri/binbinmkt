<?php
// Đăng nhập, quên mật khẩu, tài khoản của tôi

const RESET_MINUTES = 60;

function safeNext($n): string
{
    return is_string($n) && str_starts_with($n, '/') && !str_starts_with($n, '//') ? $n : '/';
}

Router::get('/login', function () {
    if (user()) redirect('/');
    render('login', ['title' => 'Đăng nhập', 'next' => safeNext(q('next')), 'username' => '', 'error' => null]);
});

Router::post('/login', function () {
    $username = Auth::normalizeUsername(str(body('username'), 190));
    $next = safeNext(body('next'));
    $fail = fn($error) => render('login', ['title' => 'Đăng nhập', 'next' => $next, 'username' => $username, 'error' => $error], 400);
    // Chặn dò mật khẩu: tối đa 8 lần sai trong 15 phút cho mỗi IP
    $key = 'login:' . Req::ip();
    if (Cache::count($key) >= 8) $fail('Đăng nhập sai quá nhiều lần. Thử lại sau 15 phút.');
    $u = DB::get('SELECT * FROM users WHERE username = ?', [$username]);
    if (!$u || !Auth::verifyPassword((string) body('password', ''), $u['password_hash'])) {
        Cache::hit($key, 15 * 60);
        $fail('Tên đăng nhập hoặc mật khẩu không đúng.');
    }
    if (!(int) $u['active']) $fail('Tài khoản này đã bị khoá. Liên hệ quản trị viên.');
    Cache::delete($key);
    DB::run('UPDATE users SET last_login_at = ? WHERE id = ?', [D::now(), $u['id']]);
    Session::set('uid', (int) $u['id']);
    Session::set('sv', (int) $u['session_version'] ?: 1);
    redirect($next);
});

Router::post('/logout', function () {
    Session::clear();
    redirect('/login');
});

/* ---------- Quên mật khẩu ---------- */

Router::get('/forgot', function () {
    render('forgot', ['title' => 'Quên mật khẩu', 'sent' => false, 'error' => null, 'value' => '']);
});

Router::post('/forgot', function () {
    $value = strtolower(str(body('username'), 190));
    // Tối đa 5 yêu cầu mỗi 15 phút cho một IP
    $key = 'forgot:' . Req::ip();
    if (Cache::count($key) >= 5) {
        render('forgot', ['title' => 'Quên mật khẩu', 'sent' => false, 'value' => $value, 'error' => 'Bạn đã yêu cầu quá nhiều lần. Thử lại sau 15 phút.'], 429);
    }
    Cache::hit($key, 15 * 60);

    $u = DB::get('SELECT * FROM users WHERE (username = ? OR email = ?) AND active = 1', [$value, $value]);
    if ($u) {
        $token = bin2hex(random_bytes(32));
        DB::run('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)', [
            $u['id'], hash('sha256', $token), D::addSeconds(D::now(), RESET_MINUTES * 60), D::now(),
        ]);
        $link = cfg('appUrl') . "/reset/$token";
        $html = Brevo::renderEmail(
            'Đặt lại mật khẩu Bin Bin Marketing',
            'Link có hiệu lực trong ' . RESET_MINUTES . ' phút',
            '<p>Chào ' . e($u['name']) . ',</p>
<p>Có yêu cầu đặt lại mật khẩu cho tài khoản <strong>' . e($u['username']) . '</strong> trên Bin Bin Marketing.</p>
<p><a href="' . $link . '" style="display:inline-block;background:#c8232b;color:#ffffff;padding:12px 20px;border-radius:999px;text-decoration:none;">Đặt mật khẩu mới</a></p>
<p>Link có hiệu lực trong ' . RESET_MINUTES . ' phút và chỉ dùng được một lần. Nếu bạn không yêu cầu, hãy bỏ qua email này; mật khẩu hiện tại vẫn giữ nguyên.</p>',
            cfg('appUrl'),
            true
        );
        try {
            $out = Brevo::send($u['email'], $u['name'], 'Đặt lại mật khẩu Bin Bin Marketing', $html, null, 'password-reset');
            if ($out['dryRun']) error_log("[forgot] Chế độ thử, chưa gửi email. Link đặt lại cho {$u['username']}: $link");
        } catch (Throwable $e) {
            error_log('[forgot] Không gửi được email đặt lại mật khẩu: ' . $e->getMessage());
        }
    }
    // Luôn trả cùng một thông báo để không lộ tài khoản nào tồn tại
    render('forgot', ['title' => 'Quên mật khẩu', 'sent' => true, 'error' => null, 'value' => '']);
});

function validReset($token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', (string) $token)) return null;
    $r = DB::get('SELECT * FROM password_resets WHERE token_hash = ?', [hash('sha256', $token)]);
    if (!$r || $r['used_at'] || $r['expires_at'] < D::now()) return null;
    return $r;
}

Router::get('/reset/{token}', function ($token) {
    render('reset', ['title' => 'Đặt mật khẩu mới', 'valid' => (bool) validReset($token), 'error' => null, 'done' => false]);
});

Router::post('/reset/{token}', function ($token) {
    $r = validReset($token);
    if (!$r) render('reset', ['title' => 'Đặt mật khẩu mới', 'valid' => false, 'error' => null, 'done' => false], 400);
    $pw = body('password');
    $err = Auth::validatePassword($pw) ?? ($pw !== body('confirm') ? 'Hai lần nhập mật khẩu không khớp.' : null);
    if ($err) render('reset', ['title' => 'Đặt mật khẩu mới', 'valid' => true, 'error' => $err, 'done' => false], 400);
    DB::run('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [Auth::hashPassword($pw), $r['user_id']]);
    DB::run('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [D::now(), $r['user_id']]);
    Session::clear();
    render('reset', ['title' => 'Đã đặt mật khẩu mới', 'valid' => false, 'done' => true, 'error' => null]);
});

/* ---------- Tài khoản của tôi ---------- */

function renderAccount(?string $error = null, ?string $emailError = null, ?string $email = null): never
{
    render('account', ['title' => 'Tài khoản của tôi', 'error' => $error, 'emailError' => $emailError, 'email' => $email ?? user()['email']], $error || $emailError ? 400 : 200);
}

Router::get('/account', function () {
    Auth::requireLogin();
    renderAccount();
});

Router::post('/account/email', function () {
    Auth::requireLogin();
    $me = user();
    $email = strtolower(str(body('email'), 190));
    $hash = DB::value('SELECT password_hash FROM users WHERE id = ?', [$me['id']]);
    if (!isEmail($email)) renderAccount(null, 'Email không hợp lệ.', $email);
    if (!Auth::verifyPassword((string) body('password', ''), $hash)) renderAccount(null, 'Mật khẩu hiện tại không đúng.', $email);
    if ($email === $me['email']) redirect('/account');
    if (DB::get('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $me['id']])) renderAccount(null, 'Email này đã được dùng cho tài khoản khác.', $email);
    DB::run('UPDATE users SET email = ? WHERE id = ?', [$email, $me['id']]);
    logActivity($me, 'user.email', 'user', $me['id'], "{$me['email']} → $email");
    flash('success', "Đã đổi email thành $email.");
    redirect('/account');
});

Router::post('/account/password', function () {
    Auth::requireLogin();
    $me = user();
    $hash = DB::value('SELECT password_hash FROM users WHERE id = ?', [$me['id']]);
    if (!Auth::verifyPassword((string) body('current', ''), $hash)) renderAccount('Mật khẩu hiện tại không đúng.');
    $pw = body('password');
    if ($err = Auth::validatePassword($pw)) renderAccount($err);
    if ($pw !== body('confirm')) renderAccount('Hai lần nhập mật khẩu mới không khớp.');
    DB::run('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [Auth::hashPassword($pw), $me['id']]);
    // Giữ phiên hiện tại, đăng xuất các thiết bị khác
    Session::set('sv', (int) $me['session_version'] + 1);
    flash('success', 'Đã đổi mật khẩu. Các thiết bị khác đã được đăng xuất.');
    redirect('/account');
});

// Ảnh, video đã tải lên: chỉ người đã đăng nhập mới xem được
Router::get('/uploads/{a}/{b}', function ($a, $b) {
    Auth::requireLogin();
    $full = Upload::fullPath("$a/$b");
    if (!$full || !is_file($full)) abort(404, 'Không tìm thấy file.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($full) ?: 'application/octet-stream';
    send_file($full, $mime, 30 * 86400);
});
