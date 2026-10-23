<?= partial('partials/top') ?>
<header class="page-head">
  <div><h1>Tài khoản của tôi</h1><p class="sub"><?= e($user['name']) ?>, vai trò <?= e(mb_strtolower(C::ROLES[$user['role']]['label'])) ?></p></div>
</header>

<div class="account-grid">
  <section class="panel stack">
    <h2>Thông tin đăng nhập</h2>
    <dl class="kv">
      <dt>Tên đăng nhập</dt><dd><strong><?= e($user['username']) ?></strong></dd>
      <dt>Email</dt><dd><?= e($user['email']) ?></dd>
    </dl>
    <p class="hint">Dùng tên đăng nhập để vào hệ thống. Muốn đổi tên đăng nhập, nhờ quản trị viên đổi trong mục Tài khoản.</p>
  </section>

  <section class="panel stack">
    <h2>Đổi email</h2>
    <?php if ($emailError): ?><p class="field-error" role="alert"><?= e($emailError) ?></p><?php endif ?>
    <form method="post" action="/account/email" class="stack">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label class="field"><span>Email mới</span><input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required></label>
      <label class="field"><span>Mật khẩu hiện tại</span><input type="password" name="password" autocomplete="current-password" required><small>Nhập để xác nhận đúng là bạn.</small></label>
      <div><button class="btn btn-primary" type="submit">Lưu email</button></div>
    </form>
  </section>

  <section class="panel stack">
    <h2>Đổi mật khẩu</h2>
    <?php if ($error): ?><p class="field-error" role="alert"><?= e($error) ?></p><?php endif ?>
    <form method="post" action="/account/password" class="stack">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label class="field"><span>Mật khẩu hiện tại</span><input type="password" name="current" autocomplete="current-password" required></label>
      <label class="field"><span>Mật khẩu mới</span><input type="password" name="password" autocomplete="new-password" minlength="8" required><small>Ít nhất 8 ký tự.</small></label>
      <label class="field"><span>Nhập lại mật khẩu mới</span><input type="password" name="confirm" autocomplete="new-password" minlength="8" required></label>
      <div><button class="btn btn-primary" type="submit">Đổi mật khẩu</button></div>
    </form>
  </section>
</div>
<?= partial('partials/bottom') ?>
