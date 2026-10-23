<?php
  // Dùng: partial('partials/chips', ['kind' => 'status', 'value' => $post['status']])
  //       partial('partials/chips', ['kind' => 'channel', 'value' => 'facebook'])
?>
<?php if ($kind === 'status'): ?>
  <span class="status status-<?= e($value) ?>"><?= e(C::STATUSES[$value]['label'] ?? $value) ?></span>
<?php elseif ($kind === 'channel'): $ch = C::CHANNELS[$value] ?? null; ?>
  <span class="ch ch-<?= e($value) ?>"><b><?= e($ch['code'] ?? '?') ?></b><?= e($ch['name'] ?? $value) ?></span>
<?php endif ?>
