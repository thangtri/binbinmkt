<?= partial('partials/head') ?>
<body class="auth">
<?= partial('partials/auth_art', ['heading' => 'Mọi bài đăng của Bin Bin Hotel, ở một nơi', 'text' => 'Lên lịch, duyệt nội dung và theo dõi Facebook, TikTok, Google Maps, Zalo OA cùng email khách hàng.']) ?>
<main class="auth-panel">
  <form method="post" action="/login" class="login-form">
    <h1>Đăng nhập</h1>
    <p class="lead">Dành cho team marketing Bin Bin Hotel.</p>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <?php if ($error): ?><p class="field-error" role="alert"><?= e($error) ?></p><?php endif ?>
    <label class="field">
      <span>Tên đăng nhập</span>
      <input type="text" name="username" value="<?= e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
    </label>
    <label class="field">
      <span>Mật khẩu</span>
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button class="btn btn-primary btn-block" type="submit">Đăng nhập</button>
    <p class="hint"><a href="/forgot">Quên mật khẩu?</a></p>
  </form>
</main>
</body>
</html>
