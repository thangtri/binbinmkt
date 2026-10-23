<?= partial('partials/top') ?>
<?php $pct = fn($a, $b) => $b ? round($a / $b * 100) . '%' : '—'; ?>
<header class="page-head">
  <div><h1>Email marketing</h1><p class="sub"><?= num($subscribed) ?> người đang nhận tin</p></div>
  <?php if (can('campaign:edit')): ?><a class="btn btn-primary" href="/email/campaigns/new"><?= icon('plus') ?> Chiến dịch mới</a><?php endif ?>
</header>
<?= partial('email/_nav') ?>

<?php if (!$list): ?>
  <div class="panel empty-state">
    <p>Chưa có chiến dịch nào. Bắt đầu bằng một email ưu đãi gửi khách cũ.</p>
    <?php if (can('campaign:edit')): ?><a class="btn btn-primary" href="/email/campaigns/new">Tạo chiến dịch</a><?php endif ?>
  </div>
<?php else: ?>
  <div class="panel panel-flush">
    <table class="table table-rows">
      <thead><tr><th>Chiến dịch</th><th>Nhóm nhận</th><th>Trạng thái</th><th class="num">Đã gửi</th><th class="num">Mở</th><th class="num">Nhấp</th></tr></thead>
      <tbody>
        <?php foreach ($list as $c): $s = $c['stats']; ?>
          <tr>
            <td><a class="row-title" href="/email/campaigns/<?= $c['id'] ?>"><?= e($c['name']) ?></a><small class="muted block"><?= e($c['subject']) ?></small></td>
            <td><?= e($c['audience_tag'] ?: 'Tất cả') ?></td>
            <td>
              <span class="status status-<?= $c['status'] === 'sent' ? 'published' : ($c['status'] === 'sending' ? 'pending' : 'draft') ?>"><?= ['draft' => 'Nháp', 'sending' => 'Đang gửi', 'sent' => 'Đã gửi'][$c['status']] ?? e($c['status']) ?></span>
              <?php if ((int) $c['dry_run']): ?><small class="muted block">chế độ thử</small><?php endif ?>
            </td>
            <td class="num"><?= $s ? num($s['sent']) : '—' ?><?php if ($c['sent_at']): ?><small class="muted block"><?= D::dmy($c['sent_at']) ?></small><?php endif ?></td>
            <td class="num"><?= $s ? $pct($s['opened'], $s['sent']) : '—' ?></td>
            <td class="num"><?= $s ? $pct($s['clicked'], $s['sent']) : '—' ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?php endif ?>
<?= partial('partials/bottom') ?>
