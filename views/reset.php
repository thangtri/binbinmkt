<?= partial('partials/head') ?>
<body class="auth">
<?= partial('partials/auth_art', ['heading' => 'Đặt mật khẩu mới', 'text' => 'Sau khi đổi, mọi thiết bị đang đăng nhập bằng mật khẩu cũ sẽ bị đăng xuất.']) ?>
<main class="auth-panel">
  <div class="login-form">
    <?php if (!empty($done)): ?>
      <h1>Đã đặt mật khẩu mới</h1>
      <p>Bạn có thể đăng nhập bằng mật khẩu vừa đặt.</p>
      <p><a class="btn btn-primary btn-block" href="/login">Đăng nhập</a></p>
    <?php elseif (!$valid): ?>
      <h1>Link không còn hiệu lực</h1>
      <p>Link đặt lại mật khẩu đã hết hạn hoặc đã được dùng. Mỗi link chỉ dùng được một lần trong 60 phút.</p>
      <p><a class="btn btn-primary btn-block" href="/forgot">Gửi link mới</a></p>
    <?php else: ?>
      <h1>Đặt mật khẩu mới</h1>
      <?php if ($error): ?><p class="field-error" role="alert"><?= e($error) ?></p><?php endif ?>
      <form method="post" class="stack">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="field"><span>Mật khẩu mới</span><input type="password" name="password" autocomplete="new-password" minlength="8" required autofocus><small>Ít nhất 8 ký tự.</small></label>
        <label class="field"><span>Nhập lại mật khẩu mới</span><input type="password" name="confirm" autocomplete="new-password" minlength="8" required></label>
        <button class="btn btn-primary btn-block" type="submit">Lưu mật khẩu mới</button>
      </form>
    <?php endif ?>
  </div>
</main>
</body>
</html>
