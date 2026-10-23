<?php $fmt = fn($m, $v) => $v === null ? '—' : (!empty($m['decimals']) ? number_format((float) $v, $m['decimals'], ',', '.') : num($v)); ?>
<?php foreach ($metrics as $m): ?>
  <div class="kpi">
    <span class="kpi-label"><?= e($m['label']) ?></span>
    <span class="kpi-value"><?= $fmt($m, $m['value']) ?><?= $m['key'] === 'rating' && $m['value'] !== null ? '★' : '' ?></span>
    <?php if ($m['value'] === null): ?>
      <span class="kpi-note">Chưa có số liệu</span>
    <?php elseif ($m['delta']): ?>
      <span class="kpi-note <?= $m['delta'] > 0 ? 'up' : 'down' ?>"><?= $m['delta'] > 0 ? '+' : '' ?><?= $fmt($m, $m['delta']) ?> so với <?= D::dm($m['prevOn']) ?></span>
    <?php else: ?>
      <span class="kpi-note">Cập nhật <?= D::dm($m['on']) ?></span>
    <?php endif ?>
  </div>
<?php endforeach ?>
