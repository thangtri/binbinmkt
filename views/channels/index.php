<?= partial('partials/top') ?>
<header class="page-head">
  <div><h1>Kênh mạng xã hội</h1><p class="sub">Số liệu tự động lấy từ từng kênh mỗi 12 giờ, AI phân tích mỗi tuần. Hiệu quả bài tính trong 30 ngày gần nhất.</p></div>
</header>

<div class="channel-grid">
  <?php foreach ($rows as $r): $p = $r['primary']; $isG = $r['ch']['key'] === 'google'; $c = $r['conn']; ?>
    <a class="channel-card ch-<?= $r['ch']['key'] ?>" href="/channels/<?= $r['ch']['key'] ?>">
      <div class="cc-head">
        <span class="cc-code"><?= $r['ch']['code'] ?></span>
        <div>
          <strong><?= e($r['ch']['name']) ?></strong>
          <small><?= e(($r['account']['handle'] ?? '') ?: (($r['account']['display_name'] ?? '') ?: 'Chưa nhập tài khoản')) ?></small>
        </div>
      </div>
      <div class="cc-main">
        <?php if ($p['value'] === null): ?>
          <span class="cc-value muted">Chưa có số liệu</span>
        <?php else: ?>
          <span class="cc-value"><?= $isG ? number_format($p['value'], 1, ',', '.') . '★' : num($p['value']) ?></span>
          <span class="cc-label"><?= $isG ? 'từ ' . num($r['reviews']['value']) . ' đánh giá, ' . $r['chain']['tracked'] . '/' . $r['chain']['branches'] . ' chi nhánh' : 'người theo dõi' ?></span>
          <?php if ($p['delta']): ?><span class="cc-delta <?= $p['delta'] > 0 ? 'up' : 'down' ?>"><?= $p['delta'] > 0 ? '+' : '' ?><?= $isG ? number_format($p['delta'], 1, ',', '.') : num($p['delta']) ?> so với <?= D::dm($p['prevOn']) ?></span><?php endif ?>
        <?php endif ?>
      </div>
      <dl class="cc-stats">
        <div><dt>Bài đã đăng</dt><dd><?= num($r['perf']['posts']) ?></dd></div>
        <div><dt>Lượt xem bài</dt><dd><?= num($r['perf']['views']) ?></dd></div>
        <div><dt>Tương tác</dt><dd><?= num($r['perf']['interactions']) ?></dd></div>
        <div><dt>Sắp đăng</dt><dd><?= $r['upcoming'] ?></dd></div>
      </dl>
      <span class="cc-sync is-<?= $c['connected'] ? ($c['status'] === 'ok' ? 'ok' : 'warn') : 'off' ?>">
        <span class="connect-dot" aria-hidden="true"></span>
        <?= !$c['connected'] ? 'Chưa kết nối' : ($c['status'] === 'ok' ? 'Tự động, cập nhật ' . D::dmyhm($c['lastSyncedAt']) : ($c['status'] === 'reconnect' ? 'Cần kết nối lại' : 'Lỗi khi cập nhật')) ?>
      </span>
      <span class="cc-foot"><?= !empty($r['account']['owner_name']) ? 'Phụ trách: ' . e($r['account']['owner_name']) : 'Chưa phân công người phụ trách' ?></span>
    </a>
  <?php endforeach ?>
</div>
<?= partial('partials/bottom') ?>
