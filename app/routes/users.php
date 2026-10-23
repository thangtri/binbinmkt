<?php

function renderUsers(?string $error = null, ?array $form = null, int $status = 200): never
{
    render('users/index', [
        'title' => 'Tài khoản',
        'users' => DB::all('SELECT id, username, email, name, role, active, created_at, last_login_at FROM users ORDER BY active DESC, name'),
        'error' => $error,
        'form' => $form ?? ['name' => '', 'username' => '', 'email' => '', 'role' => 'editor'],
    ], $status);
}

// Kiểm tra tên đăng nhập và email không trùng với tài khoản khác
function checkIdentity(string $username, string $email, int $exceptId = 0): ?string
{
    if ($err = Auth::validateUsername($username)) return $err;
    if (!isEmail($email)) return 'Email không hợp lệ.';
    if (DB::get('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $exceptId])) return "Tên đăng nhập \"$username\" đã có người dùng.";
    if (DB::get('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $exceptId])) return "Email $email đã có tài khoản.";
    return null;
}

Router::get('/users', function () {
    Auth::requireAdmin();
    renderUsers();
});

Router::post('/users', function () {
    Auth::requireAdmin();
    $form = [
        'name' => str(body('name'), 120),
        'username' => Auth::normalizeUsername(str(body('username'), 40)),
        'email' => strtolower(str(body('email'), 190)),
        'role' => isset(C::ROLES[body('role', '')]) ? body('role') : 'editor',
    ];
    $error = (!$form['name'] ? 'Hãy nhập tên.' : null) ?? checkIdentity($form['username'], $form['email']) ?? Auth::validatePassword(body('password'));
    if ($error) renderUsers($error, $form, 400);
    $id = DB::run('INSERT INTO users (username, email, name, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)', [
        $form['username'], $form['email'], $form['name'], Auth::hashPassword(body('password')), $form['role'], D::now(),
    ])['insertId'];
    logActivity(user(), 'user.create', 'user', $id, "{$form['name']} (" . C::ROLES[$form['role']]['label'] . ')');
    flash('success', "Đã tạo tài khoản cho {$form['name']}, tên đăng nhập \"{$form['username']}\". Gửi mật khẩu tạm cho họ qua kênh riêng và nhắc đổi mật khẩu.");
    redirect('/users');
});

Router::post('/users/{id}', function ($id) {
    Auth::requireAdmin();
    $u = DB::get('SELECT * FROM users WHERE id = ?', [int($id)]);
    if (!$u) redirect('/users');
    $uid = (int) $u['id'];
    $role = isset(C::ROLES[body('role', '')]) ? body('role') : $u['role'];
    $active = empty(body('active')) ? 0 : 1;
    $username = Auth::normalizeUsername(str(body('username'), 40)) ?: $u['username'];
    $email = strtolower(str(body('email'), 190)) ?: $u['email'];
    $back = function ($msg) use ($u) {
        flash('error', "{$u['name']}: $msg");
        redirect('/users');
    };
    $adminsLeft = (int) DB::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id <> ?", [$uid]);
    if (($role !== 'admin' || !$active) && $u['role'] === 'admin' && $adminsLeft === 0) $back('phải còn ít nhất một quản trị viên đang hoạt động.');
    if ($err = checkIdentity($username, $email, $uid)) $back($err);
    $pw = body('password');
    if ($pw && ($err = Auth::validatePassword($pw))) $back($err);
    DB::run('UPDATE users SET name = ?, username = ?, email = ?, role = ?, active = ?, session_version = session_version + ? WHERE id = ?', [
        str(body('name'), 120) ?: $u['name'], $username, $email, $role, $active, (int) $u['active'] && !$active ? 1 : 0, $uid,
    ]);
    if ($pw) {
        DB::run('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [Auth::hashPassword($pw), $uid]);
        if ($uid === user()['id']) Session::set('sv', (int) user()['session_version'] + 1);
    }
    logActivity(user(), 'user.update', 'user', $uid, $u['name']);
    flash('success', "Đã lưu tài khoản {$u['name']}" . ($username !== $u['username'] ? ". Tên đăng nhập mới: \"$username\"" : '') . '.');
    redirect('/users');
});
