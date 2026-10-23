<?php
  // Biểu đồ đường có trục, vẽ phía server. Cần: $series [{day, value}], $chartMetric, $label, $emptyNote
  $fmt = fn($m, $v) => $v === null ? '—' : (!empty($m['decimals']) ? number_format((float) $v, $m['decimals'], ',', '.') : num($v));
?>
<?php if (count($series) >= 2):
  [$W, $H, $L, $R, $T, $B] = [640, 200, 52, 16, 14, 28];
  $vals = array_column($series, 'value');
  $min = min($vals);
  $max = max($vals);
  if ($min == $max) { $min -= 1; $max += 1; }
  $padV = ($max - $min) * 0.12;
  $min -= $padV;
  $max += $padV;
  $n = count($series);
  $x = fn($i) => $L + $i * ($W - $L - $R) / ($n - 1);
  $y = fn($v) => $T + ($H - $T - $B) * (1 - ($v - $min) / ($max - $min));
  $pts = array_map(fn($i) => [$x($i), $y($series[$i]['value'])], range(0, $n - 1));
  $f1 = fn($v) => number_format($v, 1, '.', '');
  $line = implode(' ', array_map(fn($p) => $f1($p[0]) . ',' . $f1($p[1]), $pts));
  $area = 'M' . $f1($pts[0][0]) . ',' . ($H - $B) . ' L' . str_replace(' ', ' L', $line) . ' L' . $f1($pts[$n - 1][0]) . ',' . ($H - $B) . ' Z';
  $ticks = array_map(function ($t) use ($min, $max, $y, $chartMetric) {
      $v = $min + ($max - $min) * $t;
      return ['y' => $y($v), 'label' => !empty($chartMetric['decimals']) ? number_format($v, 1, ',', '.') : num(round($v))];
  }, [0, 0.5, 1]);
?>
  <svg class="chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="<?= e($label) ?>">
    <?php foreach ($ticks as $t): ?>
      <line x1="<?= $L ?>" x2="<?= $W - $R ?>" y1="<?= $f1($t['y']) ?>" y2="<?= $f1($t['y']) ?>" class="grid"/>
      <text x="<?= $L - 8 ?>" y="<?= $f1($t['y'] + 4) ?>" text-anchor="end" class="tick"><?= $t['label'] ?></text>
    <?php endforeach ?>
    <path d="<?= $area ?>" class="area"/>
    <polyline points="<?= $line ?>" class="line"/>
    <?php foreach ($pts as $i => $p): $last = $i === $n - 1; ?>
      <circle cx="<?= $f1($p[0]) ?>" cy="<?= $f1($p[1]) ?>" r="<?= $last ? 4.5 : 3 ?>" class="dot<?= $last ? ' dot-last' : '' ?>"><title><?= D::dmy($series[$i]['day']) ?>: <?= $fmt($chartMetric, $series[$i]['value']) ?></title></circle>
    <?php endforeach ?>
    <text x="<?= $L ?>" y="<?= $H - 8 ?>" class="tick"><?= D::dmy($series[0]['day']) ?></text>
    <text x="<?= $W - $R ?>" y="<?= $H - 8 ?>" text-anchor="end" class="tick"><?= D::dmy($series[$n - 1]['day']) ?></text>
  </svg>
<?php else: ?>
  <div class="empty-chart">
    <p><strong>Chưa đủ số liệu để vẽ biểu đồ.</strong></p>
    <p class="muted"><?= e($emptyNote) ?></p>
  </div>
<?php endif ?>
