<?= partial('partials/top') ?>
<?php $pct = fn($a, $b) => $b ? round($a / $b * 100) . '%' : '—'; ?>
<header class="page-head">
  <div>
    <p class="crumbs"><a href="/email/campaigns">Email marketing</a></p>
    <h1><?= e($c['name']) ?></h1>
    <p class="sub">Tiêu đề: <?= e($c['subject']) ?></p>
  </div>
  <div class="toolbar">
    <?php if ($c['status'] === 'draft' && can('campaign:edit')): ?><a class="btn" href="/email/campaigns/<?= $c['id'] ?>/edit">Sửa</a><?php endif ?>
    <?php if (can('campaign:edit')): ?>
      <form method="post" action="/email/campaigns/<?= $c['id'] ?>/duplicate"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-quiet" type="submit">Nhân bản</button></form>
    <?php endif ?>
  </div>
</header>
<?= partial('email/_nav') ?>

<div class="detail">
  <section class="panel panel-flush">
    <iframe class="email-frame" src="/email/campaigns/<?= $c['id'] ?>/preview" title="Xem trước email"></iframe>
  </section>

  <aside class="stack">
    <?php if ($c['status'] === 'draft'): ?>
      <section class="panel stack">
        <h2>Gửi thử</h2>
        <form method="post" action="/email/campaigns/<?= $c['id'] ?>/test" class="stack">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <label class="field"><span>Email nhận thử</span><input type="email" name="to" value="<?= e($user['email']) ?>"></label>
          <button class="btn btn-block" type="submit"><?= icon('send') ?> Gửi thử</button>
        </form>
      </section>
      <section class="panel stack">
        <h2>Gửi chiến dịch</h2>
        <p>Sẽ gửi tới <strong><?= num($audienceCount) ?> người</strong> <?= $c['audience_tag'] ? 'trong nhóm “' . e($c['audience_tag']) . '”' : 'đang nhận tin' ?>.</p>
        <?php if (can('campaign:send')): ?>
          <form method="post" action="/email/campaigns/<?= $c['id'] ?>/send" data-confirm="Gửi “<?= e($c['subject']) ?>” tới <?= $audienceCount ?> người? Email đã gửi không thu hồi được.">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button class="btn btn-primary btn-block" type="submit"<?= $audienceCount ? '' : ' disabled' ?>>Gửi tới <?= num($audienceCount) ?> người</button>
          </form>
          <?php if (!$brevoLive): ?><p class="hint">Đang ở chế độ thử: hệ thống ghi nhận như đã gửi, nhưng không email nào được gửi thật.</p><?php endif ?>
        <?php else: ?>
          <p class="hint">Chỉ quản trị viên mới gửi được chiến dịch. Báo quản trị viên khi bạn đã kiểm tra xong.</p>
        <?php endif ?>
      </section>
    <?php else: ?>
      <section class="panel">
        <h2><?= $c['status'] === 'sending' ? 'Đang gửi…' : 'Kết quả' ?></h2>
        <?php if ((int) $c['dry_run']): ?><p class="hint">Gửi ở chế độ thử, không có email thật nào được gửi.</p><?php endif ?>
        <dl class="kv">
          <dt>Đã gửi</dt><dd><?= num($stats['sent']) ?> / <?= num($stats['total']) ?><?= $stats['queued'] ? ', còn ' . num($stats['queued']) . ' trong hàng đợi' : '' ?></dd>
          <?php if ($stats['failed']): ?><dt>Lỗi</dt><dd class="down"><?= num($stats['failed']) ?></dd><?php endif ?>
          <dt>Đã nhận</dt><dd><?= num($stats['delivered']) ?></dd>
          <dt>Mở</dt><dd><?= num($stats['opened']) ?> (<?= $pct($stats['opened'], $stats['sent']) ?>)</dd>
          <dt>Nhấp link</dt><dd><?= num($stats['clicked']) ?> (<?= $pct($stats['clicked'], $stats['sent']) ?>)</dd>
          <dt>Bị trả về</dt><dd><?= num($stats['bounced']) ?></dd>
          <dt>Huỷ nhận</dt><dd><?= num($stats['unsubscribed']) ?></dd>
          <?php if ($c['sent_at']): ?><dt>Gửi xong</dt><dd><?= D::dmyhm($c['sent_at']) ?></dd><?php endif ?>
        </dl>
        <p class="hint">Số liệu mở và nhấp được Brevo gửi về qua webhook, có thể chậm vài phút.</p>
      </section>
    <?php endif ?>
    <?php if ($c['status'] === 'draft' || $user['role'] === 'admin'): ?>
      <form method="post" action="/email/campaigns/<?= $c['id'] ?>/delete" data-confirm="Xoá chiến dịch “<?= e($c['name']) ?>”<?= $c['status'] !== 'draft' ? ' cùng toàn bộ số liệu gửi' : '' ?>?">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn btn-danger-quiet" type="submit">Xoá chiến dịch</button>
      </form>
    <?php endif ?>
  </aside>
</div>

<?php if ($recipients): ?>
  <section class="panel panel-flush">
    <div class="panel-head pad"><h2>Người nhận</h2><?php if ($stats['total'] > count($recipients)): ?><span class="muted small">Hiển thị <?= count($recipients) ?> / <?= num($stats['total']) ?></span><?php endif ?></div>
    <table class="table table-rows">
      <thead><tr><th>Email</th><th>Trạng thái</th><th>Đã nhận</th><th>Mở</th><th>Nhấp</th></tr></thead>
      <tbody>
        <?php foreach ($recipients as $r): ?>
          <tr>
            <td><?= e($r['email']) ?></td>
            <td><?= ['queued' => 'Chờ gửi', 'sent' => 'Đã gửi', 'failed' => 'Lỗi', 'skipped' => 'Bỏ qua'][$r['status']] ?? e($r['status']) ?><?php if ($r['error']): ?><small class="muted block"><?= e($r['error']) ?></small><?php endif ?></td>
            <td><?= $r['bounced_at'] ? 'Bị trả về' : ($r['delivered_at'] ? D::dmyhm($r['delivered_at']) : '') ?></td>
            <td><?= $r['opened_at'] ? D::dmyhm($r['opened_at']) : '' ?></td>
            <td><?= $r['clicked_at'] ? D::dmyhm($r['clicked_at']) : '' ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </section>
<?php endif ?>
<?= partial('partials/bottom') ?>
