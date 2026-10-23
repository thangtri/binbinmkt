<?= partial('partials/top') ?>
<header class="page-head">
  <div><h1>Email marketing</h1><p class="sub"><?= num($totals['subscribed']) ?> người đang nhận tin trên tổng <?= num($totals['total']) ?> liên hệ</p></div>
  <a class="btn" href="/email/contacts/export.csv">Xuất CSV</a>
</header>
<?= partial('email/_nav') ?>

<?php if (can('contact:edit')): ?>
<div class="split">
  <section class="panel">
    <h2>Thêm liên hệ</h2>
    <form method="post" action="/email/contacts" class="grid-form">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label class="field"><span>Email</span><input type="email" name="email" required></label>
      <label class="field"><span>Tên</span><input type="text" name="name"></label>
      <label class="field"><span>Số điện thoại</span><input type="tel" name="phone"></label>
      <label class="field"><span>Nhóm</span><input type="text" name="tags" placeholder="khach-cu, doanh-nghiep"></label>
      <div class="field-wide"><button class="btn btn-primary" type="submit">Thêm</button></div>
    </form>
  </section>
  <section class="panel">
    <h2>Nhập từ file CSV</h2>
    <form method="post" action="/email/contacts/import?_csrf=<?= rawurlencode($csrf) ?>" enctype="multipart/form-data" class="stack">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label class="field"><span>File CSV</span><input type="file" name="file" accept=".csv,text/csv" required>
        <small>Dòng đầu là tiêu đề cột: <code>email</code>, <code>ten</code>, <code>sdt</code>, <code>tags</code>. Chỉ cột email là bắt buộc. Email đã có sẽ được bỏ qua.</small></label>
      <label class="field"><span>Gắn thêm nhóm cho cả file</span><input type="text" name="tags" placeholder="Ví dụ: khach-2026"></label>
      <div><button class="btn" type="submit"><?= icon('upload') ?> Nhập danh bạ</button></div>
    </form>
  </section>
</div>
<?php endif ?>

<form class="filters" method="get">
  <label class="field field-inline field-grow"><span class="sr-only">Tìm</span><input type="search" name="q" value="<?= e($query['q'] ?? '') ?>" placeholder="Tìm theo email, tên, số điện thoại"></label>
  <label class="field field-inline"><span>Nhóm</span>
    <select name="tag" data-autosubmit><option value="">Tất cả</option>
      <?php foreach ($tags as $t): ?><option value="<?= e($t) ?>"<?= $tag === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach ?>
    </select>
  </label>
  <label class="field field-inline"><span>Trạng thái</span>
    <select name="status" data-autosubmit>
      <option value="">Tất cả</option>
      <option value="sub"<?= ($query['status'] ?? '') === 'sub' ? ' selected' : '' ?>>Đang nhận tin</option>
      <option value="unsub"<?= ($query['status'] ?? '') === 'unsub' ? ' selected' : '' ?>>Đã huỷ</option>
    </select>
  </label>
  <button class="btn" type="submit">Lọc</button>
</form>

<?php if (!$contacts): ?>
  <div class="panel empty-state"><p>Chưa có liên hệ nào. Thêm tay hoặc nhập từ file CSV danh sách khách cũ.</p></div>
<?php else: ?>
  <div class="panel panel-flush">
    <table class="table table-rows">
      <thead><tr><th>Email</th><th>Tên</th><th>Số điện thoại</th><th>Nhóm</th><th>Trạng thái</th><?php if (can('contact:edit')): ?><th><span class="sr-only">Sửa</span></th><?php endif ?></tr></thead>
      <tbody>
        <?php foreach ($contacts as $c): ?>
          <tr>
            <td><?= e($c['email']) ?></td>
            <td><?= e($c['name'] ?? '') ?></td>
            <td class="nowrap"><?= e($c['phone'] ?? '') ?></td>
            <td><?php foreach (array_filter(array_map('trim', explode(',', (string) $c['tags'])), 'strlen') as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach ?></td>
            <td><?= (int) $c['subscribed'] ? 'Đang nhận' : 'Đã huỷ ' . D::dmy($c['unsubscribed_at'] ?? '') ?></td>
            <?php if (can('contact:edit')): ?>
              <td class="right">
                <details class="row-edit">
                  <summary class="btn btn-small btn-quiet">Sửa</summary>
                  <div class="row-edit-body">
                    <form method="post" action="/email/contacts/<?= $c['id'] ?>" class="stack">
                      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                      <label class="field"><span>Tên</span><input type="text" name="name" value="<?= e($c['name'] ?? '') ?>"></label>
                      <label class="field"><span>Số điện thoại</span><input type="tel" name="phone" value="<?= e($c['phone'] ?? '') ?>"></label>
                      <label class="field"><span>Nhóm</span><input type="text" name="tags" value="<?= e($c['tags'] ?? '') ?>"></label>
                      <button class="btn btn-small" type="submit">Lưu</button>
                    </form>
                    <form method="post" action="/email/contacts/<?= $c['id'] ?>/delete" data-confirm="Xoá <?= e($c['email']) ?> khỏi danh bạ?">
                      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                      <button class="btn btn-small btn-danger-quiet" type="submit">Xoá liên hệ</button>
                    </form>
                  </div>
                </details>
              </td>
            <?php endif ?>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?= partial('partials/pager', ['page' => $page, 'pages' => $pages]) ?>
<?php endif ?>
<?= partial('partials/bottom') ?>
