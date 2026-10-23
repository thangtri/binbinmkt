<?= partial('partials/top') ?>
<header class="page-head">
  <div><h1>Tài khoản</h1><p class="sub">Ai được vào hệ thống và làm được gì</p></div>
</header>

<div class="detail">
  <section class="panel panel-flush">
    <table class="table table-rows">
      <thead><tr><th>Người dùng</th><th>Tên đăng nhập</th><th>Vai trò</th><th>Đăng nhập gần nhất</th><th><span class="sr-only">Sửa</span></th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): $active = (int) $u['active']; $me = (int) $u['id'] === $user['id']; ?>
          <tr class="<?= $active ? '' : 'is-muted' ?>">
            <td><strong><?= e($u['name']) ?></strong><small class="muted block"><?= e($u['email']) ?></small></td>
            <td><code><?= e($u['username']) ?></code></td>
            <td><?= e(C::ROLES[$u['role']]['label'] ?? $u['role']) ?><?= $active ? '' : ' (đã khoá)' ?></td>
            <td class="nowrap"><?= $u['last_login_at'] ? D::dmyhm($u['last_login_at']) : 'Chưa đăng nhập' ?></td>
            <td class="right">
              <details class="row-edit">
                <summary class="btn btn-small btn-quiet">Sửa</summary>
                <div class="row-edit-body">
                  <form method="post" action="/users/<?= $u['id'] ?>" class="stack">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <label class="field"><span>Tên</span><input type="text" name="name" value="<?= e($u['name']) ?>" required></label>
                    <label class="field"><span>Tên đăng nhập</span><input type="text" name="username" value="<?= e($u['username']) ?>" required pattern="[a-z0-9][a-z0-9._\-]{1,28}[a-z0-9]" autocapitalize="none" spellcheck="false"></label>
                    <label class="field"><span>Email</span><input type="email" name="email" value="<?= e($u['email']) ?>" required></label>
                    <label class="field"><span>Vai trò</span>
                      <select name="role"><?php foreach (C::ROLES as $r): ?><option value="<?= $r['key'] ?>"<?= $u['role'] === $r['key'] ? ' selected' : '' ?>><?= e($r['label']) ?></option><?php endforeach ?></select>
                    </label>
                    <label class="field"><span>Đặt mật khẩu mới</span><input type="password" name="password" autocomplete="new-password" minlength="8" placeholder="Để trống nếu không đổi"></label>
                    <label class="check"><input type="checkbox" name="active" value="1"<?= $active ? ' checked' : '' ?><?= $me ? ' disabled' : '' ?>> Cho phép đăng nhập</label>
                    <?php if ($me): ?><input type="hidden" name="active" value="1"><?php endif ?>
                    <button class="btn btn-small" type="submit">Lưu</button>
                  </form>
                </div>
              </details>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </section>

  <aside class="stack">
    <section class="panel stack">
      <h2>Thêm tài khoản</h2>
      <?php if ($error): ?><p class="field-error" role="alert"><?= e($error) ?></p><?php endif ?>
      <form method="post" action="/users" class="stack">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="field"><span>Họ tên</span><input type="text" name="name" value="<?= e($form['name']) ?>" required></label>
        <label class="field"><span>Tên đăng nhập</span><input type="text" name="username" value="<?= e($form['username']) ?>" required pattern="[a-z0-9][a-z0-9._\-]{1,28}[a-z0-9]" autocapitalize="none" spellcheck="false" placeholder="vd: thanhtri">
          <small>3–30 ký tự: chữ thường không dấu, số, dấu chấm, gạch dưới, gạch ngang.</small></label>
        <label class="field"><span>Email</span><input type="email" name="email" value="<?= e($form['email']) ?>" required></label>
        <label class="field"><span>Vai trò</span>
          <select name="role"><?php foreach (C::ROLES as $r): ?><option value="<?= $r['key'] ?>"<?= $form['role'] === $r['key'] ? ' selected' : '' ?>><?= e($r['label']) ?></option><?php endforeach ?></select>
        </label>
        <label class="field"><span>Mật khẩu tạm</span><input type="password" name="password" autocomplete="new-password" minlength="8" required><small>Ít nhất 8 ký tự. Người dùng tự đổi sau khi đăng nhập.</small></label>
        <button class="btn btn-primary" type="submit">Tạo tài khoản</button>
      </form>
    </section>
    <section class="panel">
      <h2>Quyền theo vai trò</h2>
      <dl class="kv kv-stack">
        <?php foreach (C::ROLES as $r): ?><dt><?= e($r['label']) ?></dt><dd><?= e($r['desc']) ?></dd><?php endforeach ?>
      </dl>
    </section>
  </aside>
</div>
<?= partial('partials/bottom') ?>
