<?= partial('partials/top') ?>
<header class="page-head">
  <div><h1>Ưu đãi</h1><p class="sub">Mỗi ưu đãi có mã riêng cho từng kênh để biết kênh nào mang khách về.</p></div>
  <?php if (can('offer:edit')): ?><a class="btn btn-primary" href="/offers/new"><?= icon('plus') ?> Thêm ưu đãi</a><?php endif ?>
</header>

<?php if (can('offer:redeem')): ?>
  <section class="panel">
    <h2>Ghi nhận khách dùng mã</h2>
    <p class="hint">Lễ tân nhập mã khách đưa khi nhận phòng. Hệ thống tự biết mã thuộc ưu đãi và kênh nào.</p>
    <?= partial('offers/_redeem', ['back' => '', 'branchList' => $branchList, 'today' => $today]) ?>
  </section>
<?php endif ?>

<?php if (!$list): ?>
  <div class="panel empty-state"><p>Chưa có ưu đãi nào.</p><?php if (can('offer:edit')): ?><a class="btn btn-primary" href="/offers/new">Tạo ưu đãi đầu tiên</a><?php endif ?></div>
<?php else: ?>
  <div class="panel panel-flush" style="margin-top: 20px">
    <table class="table table-rows">
      <thead><tr><th>Ưu đãi</th><th>Thời gian</th><th>Mã gốc</th><th class="num">Lượt dùng</th><th class="num">Doanh thu</th></tr></thead>
      <tbody>
        <?php foreach ($list as $o):
          $state = !(int) $o['active'] ? 'Đã tắt' : ($o['end_date'] < $today ? 'Hết hạn' : ($o['start_date'] > $today ? 'Sắp diễn ra' : 'Đang chạy')); ?>
          <tr class="<?= $state === 'Đang chạy' || $state === 'Sắp diễn ra' ? '' : 'is-muted' ?>">
            <td><a class="row-title" href="/offers/<?= $o['id'] ?>"><?= e($o['title']) ?></a><small class="muted block"><?= $state ?><?= $o['summary'] ? ', ' . e($o['summary']) : '' ?></small></td>
            <td class="nowrap"><?= D::dm($o['start_date']) ?> – <?= D::dmy($o['end_date']) ?></td>
            <td><code><?= e($o['code_prefix']) ?></code></td>
            <td class="num"><?= num($o['used']) ?></td>
            <td class="num"><?= (int) $o['revenue'] ? num($o['revenue']) . 'đ' : '—' ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?php endif ?>
<?= partial('partials/bottom') ?>
