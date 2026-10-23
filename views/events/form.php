<?= partial('partials/top') ?>
<?php $isNew = empty($ev['id']); ?>
<header class="page-head">
  <div><p class="crumbs"><a href="/events">Sự kiện</a></p><h1><?= $isNew ? 'Thêm sự kiện' : 'Sửa sự kiện' ?></h1></div>
</header>
<?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>

<div class="detail">
  <form method="post" action="<?= $isNew ? '/events' : '/events/' . $ev['id'] ?>" class="panel stack">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label class="field"><span>Tên sự kiện</span><input type="text" name="title" value="<?= e($ev['title']) ?>" maxlength="200" required placeholder="Ví dụ: Lễ hội pháo hoa, Combo hè cho gia đình"></label>
    <label class="field"><span>Loại</span>
      <select name="kind">
        <?php foreach (C::EVENT_KINDS as $k): ?><option value="<?= $k['key'] ?>"<?= $ev['kind'] === $k['key'] ? ' selected' : '' ?>><?= e($k['label']) ?></option><?php endforeach ?>
      </select>
    </label>
    <div class="row-2">
      <label class="field"><span>Từ ngày</span><input type="date" name="start_date" value="<?= e($ev['start_date']) ?>" required></label>
      <label class="field"><span>Đến ngày</span><input type="date" name="end_date" value="<?= e($ev['end_date']) ?>"></label>
    </div>
    <label class="check"><input type="checkbox" name="recurring" value="1"<?= (int) $ev['recurring'] ? ' checked' : '' ?>> Lặp lại hằng năm vào cùng ngày dương lịch</label>
    <p class="hint">Lễ theo âm lịch (Tết, Trung thu) đổi ngày dương mỗi năm, nên nhập riêng từng năm.</p>
    <label class="field"><span>Mô tả, ý tưởng nội dung</span><textarea name="description" rows="5"><?= e($ev['description'] ?? '') ?></textarea></label>
    <div class="toolbar">
      <button class="btn btn-primary" type="submit"><?= $isNew ? 'Thêm sự kiện' : 'Lưu' ?></button>
      <a class="btn btn-quiet" href="/events">Huỷ</a>
    </div>
  </form>

  <?php if (!$isNew): ?>
    <aside class="stack">
      <section class="panel">
        <h2>Bài gắn với sự kiện</h2>
        <?php if (!$posts): ?>
          <p class="empty">Chưa có bài nào.</p>
        <?php else: ?>
          <ul class="plain-list">
            <?php foreach ($posts as $p): ?>
              <li><a href="/posts/<?= $p['id'] ?>"><?= e($p['title']) ?></a> <?= partial('partials/chips', ['kind' => 'status', 'value' => $p['status']]) ?> <span class="muted small"><?= $p['scheduled_at'] ? D::dm($p['scheduled_at']) : '' ?></span></li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>
        <?php if (can('post:create')): ?><p><a class="btn btn-small" href="/posts/new?event=<?= $ev['id'] ?>">Soạn bài cho sự kiện này</a></p><?php endif ?>
      </section>
      <form method="post" action="/events/<?= $ev['id'] ?>/delete" data-confirm="Xoá sự kiện “<?= e($ev['title']) ?>”? Các bài liên quan vẫn được giữ.">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn btn-danger-quiet" type="submit">Xoá sự kiện</button>
      </form>
    </aside>
  <?php endif ?>
</div>
<?= partial('partials/bottom') ?>
