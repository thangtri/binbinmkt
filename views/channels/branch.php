<?= partial('partials/top') ?>
<?php $editable = can('channel:edit'); $profile = $extra['profile'] ?? []; ?>
<header class="page-head">
  <div>
    <p class="crumbs"><a href="/channels">Kênh</a> / <a href="/channels/google">Google Maps</a></p>
    <h1><?= e($b['name']) ?></h1>
    <p class="sub"><?= e($b['address'] ?? '') ?><?php if ($b['hotline']): ?> <span class="muted">Hotline <?= e($b['hotline']) ?></span><?php endif ?></p>
  </div>
  <div class="toolbar">
    <?php if ($b['maps_url']): ?><a class="btn" href="<?= e($b['maps_url']) ?>" target="_blank" rel="noopener"><?= icon('link') ?> Mở trên Google Maps</a><?php endif ?>
    <?php if ($editable && $b['place_id']): ?>
      <form method="post" action="/channels/google/branch/<?= $b['id'] ?>/clear" data-confirm="Bỏ theo dõi Google Maps của <?= e($b['name']) ?>? Số liệu cũ vẫn được giữ.">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn btn-quiet" type="submit">Bỏ theo dõi</button>
      </form>
    <?php endif ?>
  </div>
</header>

<?php if (!$b['place_id']): ?>
  <div class="flash flash-warn">Chi nhánh này chưa có link Google Maps. <a href="/channels/google">Dán link ở trang Google Maps</a> để hệ thống tự lấy số liệu.</div>
<?php elseif ($b['sync_status'] === 'error'): ?>
  <div class="flash flash-error">Lần cập nhật gần nhất bị lỗi: <?= e($b['sync_error']) ?></div>
<?php endif ?>

<section class="kpis"><?= partial('channels/_kpis', ['metrics' => $metrics]) ?></section>

<div class="detail">
  <div class="stack">
    <section class="panel">
      <div class="panel-head"><h2><?= e($chartMetric['label']) ?> theo thời gian</h2><?php if ($series): ?><span class="muted small"><?= count($series) ?> lần ghi</span><?php endif ?></div>
      <?= partial('channels/_chart', ['series' => $series, 'chartMetric' => $chartMetric, 'label' => $chartMetric['label'] . ' của ' . $b['name'], 'emptyNote' => 'Hệ thống ghi số liệu mỗi ngày; biểu đồ hiện từ ngày thứ hai.']) ?>
    </section>

    <section class="panel panel-flush">
      <div class="panel-head pad"><h2>Lịch sử số liệu</h2></div>
      <?php if (!$stats): ?><p class="empty pad">Chưa có số liệu nào.</p><?php else: ?>
        <table class="table table-rows">
          <thead><tr><th>Ngày</th><th class="num">Điểm</th><th class="num">Đánh giá</th><th>Nguồn</th></tr></thead>
          <tbody>
            <?php foreach (array_slice($stats, 0, 20) as $s): ?>
              <tr><td class="nowrap"><?= D::dmy($s['recorded_on']) ?></td>
                <td class="num"><?= $s['rating'] !== null ? number_format((float) $s['rating'], 1, ',', '.') . '★' : '—' ?></td>
                <td class="num"><?= $s['reviews'] !== null ? num($s['reviews']) : '—' ?></td>
                <td><?= $s['source'] === 'auto' ? 'Tự động' : 'Nhập tay' ?></td></tr>
            <?php endforeach ?>
          </tbody>
        </table>
      <?php endif ?>
    </section>
  </div>

  <aside class="stack">
    <section class="panel">
      <div class="panel-head"><h2>Đánh giá gần đây</h2><?php if (!empty($profile['url'])): ?><a href="<?= e($profile['url']) ?>" target="_blank" rel="noopener">Trả lời trên Google</a><?php endif ?></div>
      <?php if (empty($extra['recentReviews'])): ?>
        <p class="empty">Chưa có đánh giá nào được lấy về.</p>
      <?php else: ?>
        <?= partial('channels/_reviews', ['reviews' => $extra['recentReviews']]) ?>
      <?php endif ?>
    </section>
  </aside>
</div>
<?= partial('partials/bottom') ?>
