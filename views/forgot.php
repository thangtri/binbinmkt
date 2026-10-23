<?= partial('partials/head') ?>
<body class="auth">
<?= partial('partials/auth_art', ['heading' => 'Lấy lại quyền truy cập', 'text' => 'Link đặt mật khẩu mới sẽ được gửi tới email của tài khoản.']) ?>
<main class="auth-panel">
  <div class="login-form">
    <h1>Quên mật khẩu</h1>
    <?php if ($sent): ?>
      <p class="flash flash-success" role="status">Nếu tài khoản tồn tại, một email có link đặt mật khẩu mới vừa được gửi. Link có hiệu lực trong 60 phút.</p>
      <p>Không thấy email? Kiểm tra mục Thư rác, hoặc nhờ quản trị viên đặt lại mật khẩu trong mục Tài khoản.</p>
      <p><a class="btn btn-block" href="/login">Về trang đăng nhập</a></p>
    <?php else: ?>
      <p class="lead">Nhập tên đăng nhập hoặc email của bạn.</p>
      <?php if ($error): ?><p class="field-error" role="alert"><?= e($error) ?></p><?php endif ?>
      <form method="post" action="/forgot" class="stack">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="field">
          <span>Tên đăng nhập hoặc email</span>
          <input type="text" name="username" value="<?= e($value) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </label>
        <button class="btn btn-primary btn-block" type="submit">Gửi link đặt lại</button>
      </form>
      <p class="hint"><a href="/login">Quay lại đăng nhập</a></p>
    <?php endif ?>
  </div>
</main>
</body>
</html>
