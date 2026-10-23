<?= partial('partials/top') ?>
<?php
  $fmt = fn($v, $dec = 0) => $dec ? number_format((float) $v, $dec, ',', '.') : num($v);
  $sign = fn($v, $dec = 0) => $v === null ? '—' : ($v > 0 ? '+' : '') . $fmt($v, $dec);
  $cls = fn($v) => $v > 0 ? 'up' : ($v < 0 ? 'down' : '');
  $val = fn($v, $dec = 0) => $v === null ? '—' : $fmt($v, $dec);
  $pct = fn($a, $b) => $b ? round($a / $b * 100) . '%' : '—';
  $totalUsed = array_sum(array_column($r['offers'], 'used'));
  $totalRevenue = array_sum(array_column($r['offers'], 'revenue'));
  $totalFollow = array_sum(array_map(fn($c) => $c['followers']['change'] ?? 0, $r['channels']));
?>
<header class="page-head">
  <div>
    <h1>Báo cáo tháng <?= substr($r['month'], 5) ?>/<?= substr($r['month'], 0, 4) ?></h1>
    <p class="sub"><?= D::dmy($r['from']) ?> – <?= D::dmy($r['to']) ?></p>
  </div>
  <div class="toolbar no-print">
    <div class="seg" role="group" aria-label="Chọn tháng">
      <a href="/reports?m=<?= $prev ?>" aria-label="Tháng trước"><?= icon('left') ?></a>
      <a href="/reports">Tháng này</a>
      <a href="/reports?m=<?= $next ?>" aria-label="Tháng sau"><?= icon('right') ?></a>
    </div>
    <a class="btn" href="/reports/<?= $r['month'] ?>.csv"><?= icon('upload') ?> Tải Excel (.csv)</a>
    <button class="btn" type="button" data-print><?= icon('file') ?> In / Lưu PDF</button>
  </div>
</header>

<section class="kpis">
  <div class="kpi"><span class="kpi-label">Bài đã đăng</span><span class="kpi-value"><?= num($r['posts']['published']) ?></span><span class="kpi-note">trên cả 4 kênh</span></div>
  <div class="kpi"><span class="kpi-label">Người theo dõi tăng thêm</span><span class="kpi-value <?= $cls($totalFollow) ?>"><?= $sign($totalFollow) ?></span><span class="kpi-note">Facebook, TikTok, Zalo OA</span></div>
  <div class="kpi"><span class="kpi-label">Lượt dùng mã ưu đãi</span><span class="kpi-value"><?= num($totalUsed) ?></span><span class="kpi-note"><?= $totalRevenue ? num($totalRevenue) . 'đ doanh thu ghi nhận' : 'chưa ghi doanh thu' ?></span></div>
  <div class="kpi"><span class="kpi-label">Email đã gửi</span><span class="kpi-value"><?= num(array_sum(array_column($r['emails'], 'sent'))) ?></span><span class="kpi-note"><?= count($r['emails']) ?> chiến dịch</span></div>
</section>

<?= partial('channels/_insight', [
  'insight' => $insight,
  'aiConfigured' => $aiReady,
  'canRun' => can('channel:edit'),
  'action' => "/reports/{$r['month']}/ai",
  'emptyText' => 'Chưa có nhận xét cho tháng này.',
  'recoTitle' => 'Đề xuất cho tháng sau',
]) ?>

<div class="report-grid">
  <section class="panel panel-flush">
    <div class="panel-head pad"><h2>Kênh mạng xã hội</h2></div>
    <table class="table table-rows">
      <thead><tr><th>Kênh</th><th class="num">Theo dõi cuối kỳ</th><th class="num">Tăng/giảm</th><th class="num">Bài</th><th class="num">Lượt xem</th><th class="num">Tương tác</th></tr></thead>
      <tbody>
        <?php foreach ($r['channels'] as $c): ?>
          <tr><td><?= partial('partials/chips', ['kind' => 'channel', 'value' => $c['key']]) ?></td>
            <td class="num"><?= $val($c['followers']['end']) ?></td>
            <td class="num <?= $cls($c['followers']['change']) ?>"><?= $sign($c['followers']['change']) ?></td>
            <td class="num"><?= num($c['posts']) ?></td><td class="num"><?= num($c['views']) ?></td><td class="num"><?= num($c['interactions']) ?></td></tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </section>

  <section class="panel panel-flush">
    <div class="panel-head pad"><h2>Google Maps theo chi nhánh</h2></div>
    <table class="table table-rows">
      <thead><tr><th>Chi nhánh</th><th class="num">Điểm</th><th class="num">Thay đổi</th><th class="num">Đánh giá</th><th class="num">Mới trong tháng</th></tr></thead>
      <tbody>
        <?php foreach ($r['branches'] as $b): ?>
          <tr><td><?= e($b['name']) ?></td>
            <td class="num"><?= $b['rating']['end'] === null ? '—' : $fmt($b['rating']['end'], 1) . '★' ?></td>
            <td class="num <?= $cls($b['rating']['change']) ?>"><?= $sign($b['rating']['change'], 1) ?></td>
            <td class="num"><?= $val($b['reviews']['end']) ?></td>
            <td class="num <?= $cls($b['reviews']['change']) ?>"><?= $sign($b['reviews']['change']) ?></td></tr>
        <?php endforeach ?>
      </tbody>
    </table>
    <?php if ($r['rivals']): ?>
      <table class="table table-rows">
        <thead><tr><th>Đối thủ</th><th>Gần chi nhánh</th><th class="num">Điểm</th><th class="num">Mới trong tháng</th></tr></thead>
        <tbody>
          <?php foreach ($r['rivals'] as $c): ?>
            <tr><td><?= e($c['name']) ?></td><td class="muted"><?= e($c['branch'] ?? '') ?></td><td class="num"><?= $c['rating'] === null ? '—' : $fmt($c['rating'], 1) . '★' ?></td><td class="num"><?= $sign($c['newReviews']) ?></td></tr>
          <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </section>

  <section class="panel panel-flush">
    <div class="panel-head pad"><h2>Bài hiệu quả nhất</h2></div>
    <?php if (!$r['posts']['top']): ?><p class="empty pad">Chưa có bài nào đăng trong tháng.</p><?php else: ?>
      <table class="table table-rows">
        <thead><tr><th>Bài</th><th class="num">Xem</th><th class="num">Tương tác</th></tr></thead>
        <tbody>
          <?php foreach ($r['posts']['top'] as $p): ?>
            <tr><td><a href="/posts/<?= $p['id'] ?>"><?= e($p['title']) ?></a><small class="muted block"><?= D::dmy($p['published_at']) ?>, <?= e($p['format'] ?? '') ?></small></td><td class="num"><?= num($p['views']) ?></td><td class="num"><?= num($p['interactions']) ?></td></tr>
          <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </section>

  <section class="panel panel-flush">
    <div class="panel-head pad"><h2>Ưu đãi và mã giảm giá</h2></div>
    <?php if (!$r['offers']): ?><p class="empty pad">Chưa có khách dùng mã trong tháng.</p><?php else: ?>
      <table class="table table-rows">
        <thead><tr><th>Ưu đãi</th><th>Kênh</th><th class="num">Lượt dùng</th><th class="num">Đêm</th><th class="num">Doanh thu</th></tr></thead>
        <tbody>
          <?php foreach ($r['offers'] as $o): ?>
            <tr><td><?= e($o['title']) ?></td><td><?= e($channelNames[$o['channel']] ?? $o['channel']) ?></td><td class="num"><?= num($o['used']) ?></td><td class="num"><?= num($o['nights']) ?></td><td class="num"><?= $o['revenue'] ? num($o['revenue']) . 'đ' : '—' ?></td></tr>
          <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </section>

  <section class="panel panel-flush">
    <div class="panel-head pad"><h2>Email marketing</h2></div>
    <?php if (!$r['emails']): ?><p class="empty pad">Không gửi chiến dịch nào trong tháng.</p><?php else: ?>
      <table class="table table-rows">
        <thead><tr><th>Chiến dịch</th><th class="num">Gửi</th><th class="num">Mở</th><th class="num">Nhấp</th></tr></thead>
        <tbody>
          <?php foreach ($r['emails'] as $em): ?>
            <tr><td><?= e($em['name']) ?><small class="muted block"><?= D::dmy($em['sent_at']) ?></small></td><td class="num"><?= num($em['sent']) ?></td><td class="num"><?= $pct($em['opened'], $em['sent']) ?></td><td class="num"><?= $pct($em['clicked'], $em['sent']) ?></td></tr>
          <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </section>
</div>
<?= partial('partials/bottom') ?>
