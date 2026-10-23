<section class="panel ai-panel" id="ai">
  <div class="panel-head">
    <h2>Phân tích của AI</h2>
    <?php if ($insight): ?><span class="muted small"><?= D::dmyhm($insight['created_at']) ?></span><?php endif ?>
  </div>
  <?php if ($insight): ?>
    <p class="ai-summary"><?= e($insight['summary']) ?></p>
    <div class="ai-cols">
      <?php if ($insight['highlights']): ?><div><h3>Điểm tốt</h3><ul><?php foreach ($insight['highlights'] as $x): ?><li><?= e($x) ?></li><?php endforeach ?></ul></div><?php endif ?>
      <?php if ($insight['concerns']): ?><div><h3>Cần chú ý</h3><ul><?php foreach ($insight['concerns'] as $x): ?><li><?= e($x) ?></li><?php endforeach ?></ul></div><?php endif ?>
    </div>
    <?php if ($insight['recommendations']): ?>
      <div class="ai-reco"><h3><?= e($recoTitle ?? 'Nên làm tuần này') ?></h3><ul><?php foreach ($insight['recommendations'] as $x): ?><li><?= e($x) ?></li><?php endforeach ?></ul></div>
    <?php endif ?>
  <?php elseif (!$aiConfigured): ?>
    <p class="empty">Chưa bật phân tích AI. Quản trị viên cần thêm <code>ANTHROPIC_API_KEY</code> vào cấu hình máy chủ.</p>
  <?php else: ?>
    <p class="empty"><?= e($emptyText) ?></p>
  <?php endif ?>
  <?php if ($aiConfigured && $canRun): ?>
    <form method="post" action="<?= e($action) ?>" class="ai-run">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <button class="btn btn-small" type="submit" data-busy="AI đang phân tích…"><?= $insight ? 'Phân tích lại' : 'Phân tích ngay' ?></button>
      <span class="hint">Mất khoảng 20–60 giây.</span>
    </form>
  <?php endif ?>
</section>
