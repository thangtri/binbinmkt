<?= partial('partials/top') ?>
<header class="page-head">
  <div>
    <p class="crumbs"><a href="/events">Sự kiện</a></p>
    <h1>Ý tưởng bài cho <?= e($ev['title']) ?></h1>
    <p class="sub"><?= D::dmy($ev['occ_start']) ?><?= $ev['occ_end'] !== $ev['occ_start'] ? ' – ' . D::dmy($ev['occ_end']) : '' ?>. Chọn ý tưởng phù hợp để tạo bài nháp, rồi thêm ảnh và chỉnh lại trước khi gửi duyệt.</p>
  </div>
  <form method="post" action="/events/<?= $ev['id'] ?>/ideas">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="start" value="<?= $ev['occ_start'] ?>"><input type="hidden" name="end" value="<?= $ev['occ_end'] ?>">
    <button class="btn" type="submit" data-busy="AI đang nghĩ…">Gợi ý 3 ý tưởng khác</button>
  </form>
</header>

<?php if ($error): ?>
  <div class="flash flash-error" role="alert">Chưa tạo được ý tưởng: <?= e($error) ?></div>
<?php endif ?>

<div class="ideas">
  <?php foreach ($ideas as $i => $it): ?>
    <section class="panel idea">
      <p class="muted small">Ý tưởng <?= $i + 1 ?>, <?= e($it['format']) ?>, đăng ngày <?= D::dmy($it['date']) ?></p>
      <h2><?= e($it['title']) ?></h2>
      <p class="muted"><?= e($it['why']) ?></p>
      <p class="caption"><?= e($it['caption']) ?></p>
      <p class="tags"><?= e($it['hashtags']) ?></p>
      <form method="post" action="/posts/from-idea">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="title" value="<?= e($it['title']) ?>">
        <input type="hidden" name="caption" value="<?= e($it['caption']) ?>">
        <input type="hidden" name="hashtags" value="<?= e($it['hashtags']) ?>">
        <input type="hidden" name="format" value="<?= e($it['format']) ?>">
        <input type="hidden" name="date" value="<?= e($it['date']) ?>">
        <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
        <button class="btn btn-primary btn-block" type="submit"><?= icon('plus') ?> Tạo bài nháp từ ý tưởng này</button>
      </form>
    </section>
  <?php endforeach ?>
</div>
<?= partial('partials/bottom') ?>
