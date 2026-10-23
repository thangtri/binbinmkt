<?= partial('partials/top') ?>
<?php
  $items = $p['plan']['items'] ?? [];
  $evName = array_column($p['plan']['events'] ?? [], 'title', 'id');
  $ofName = array_column($p['plan']['offers'] ?? [], 'title', 'id');
?>
<header class="page-head">
  <div>
    <p class="crumbs"><a href="/plan">Kế hoạch tháng bằng AI</a></p>
    <h1>Kế hoạch tháng <?= e($p['month']) ?></h1>
    <p class="sub"><?= count($items) ?> bài đề xuất. Bỏ chọn bài không dùng, sửa ngày giờ nếu cần, rồi tạo bài nháp.</p>
  </div>
</header>
<?php if (!empty($p['plan']['overview'])): ?><section class="panel ai-panel"><p class="ai-summary"><?= e($p['plan']['overview']) ?></p></section><?php endif ?>

<form method="post" action="/plan/<?= $p['id'] ?>/create" class="plan-form">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <ol class="plan-list">
    <?php foreach ($items as $i => $it): ?>
      <li class="panel plan-item">
        <label class="check plan-pick"><input type="checkbox" name="pick_<?= $i ?>" value="1" checked> <strong><?= e($it['title']) ?></strong></label>
        <div class="plan-meta">
          <input type="date" name="date_<?= $i ?>" value="<?= e($it['date']) ?>" aria-label="Ngày đăng">
          <input type="time" name="time_<?= $i ?>" value="<?= e($it['time']) ?>" aria-label="Giờ đăng">
          <span class="tag"><?= e($it['format']) ?></span>
          <?php if ($it['event_id'] && isset($evName[$it['event_id']])): ?><span class="tag">Sự kiện: <?= e($evName[$it['event_id']]) ?></span><?php endif ?>
          <?php if ($it['offer_id'] && isset($ofName[$it['offer_id']])): ?><span class="tag">Ưu đãi: <?= e($ofName[$it['offer_id']]) ?></span><?php endif ?>
        </div>
        <p class="muted small"><?= e($it['why']) ?></p>
        <details><summary>Xem nội dung AI viết</summary><p class="caption"><?= e($it['caption']) ?></p><p class="tags"><?= e($it['hashtags']) ?></p></details>
      </li>
    <?php endforeach ?>
  </ol>
  <div class="toolbar plan-actions">
    <button class="btn btn-primary" type="submit"><?= icon('plus') ?> Tạo bài nháp cho các bài đã chọn</button>
    <span class="hint">Bài nháp được đặt lên lịch, gắn sẵn sự kiện và ưu đãi; vẫn cần thêm ảnh và gửi duyệt.</span>
  </div>
</form>
<?= partial('partials/bottom') ?>
