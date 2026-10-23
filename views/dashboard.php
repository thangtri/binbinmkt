<?= partial('partials/top') ?>
<?php
  $weekTotal = array_sum($week);
  $pct = fn($a, $b) => $b ? round($a / $b * 100) . '%' : '—';
  $actions = [
    'post.create' => 'tạo bài', 'post.update' => 'sửa bài', 'post.submit' => 'gửi duyệt', 'post.approve' => 'duyệt bài', 'post.reject' => 'trả bài',
    'post.publish' => 'đã đăng', 'post.reschedule' => 'đổi ngày', 'post.delete' => 'xoá bài', 'event.create' => 'thêm sự kiện', 'event.update' => 'sửa sự kiện',
    'event.delete' => 'xoá sự kiện', 'campaign.create' => 'tạo chiến dịch', 'campaign.send' => 'gửi chiến dịch', 'campaign.delete' => 'xoá chiến dịch',
    'contact.import' => 'nhập danh bạ', 'contact.delete' => 'xoá liên hệ', 'channel.update' => 'sửa kênh', 'channel.stats' => 'ghi số liệu',
    'channel.sync' => 'cập nhật số liệu', 'channel.connect' => 'kết nối kênh', 'channel.disconnect' => 'ngắt kết nối kênh', 'channel.analyze' => 'chạy phân tích AI',
    'user.create' => 'tạo tài khoản', 'user.email' => 'đổi email', 'settings.update' => 'sửa thông tin khách sạn', 'branch.create' => 'thêm chi nhánh',
    'offer.create' => 'tạo ưu đãi', 'offer.update' => 'sửa ưu đãi', 'offer.redeem' => 'ghi nhận mã', 'plan.create' => 'nhờ AI lập kế hoạch',
    'plan.apply' => 'tạo bài từ kế hoạch', 'competitor.add' => 'thêm đối thủ', 'report.ai' => 'nhờ AI nhận xét báo cáo', 'branch.update' => 'sửa chi nhánh',
    'post.ai' => 'nhờ AI viết bài', 'post.autopublish' => 'tự đăng bài', 'post.publishnow' => 'bấm đăng ngay', 'user.update' => 'sửa tài khoản',
  ];
  $noPost = count(array_filter($upcoming, fn($e) => !$e['post_count']));
?>
<header class="welcome<?= $hotelPhoto ? ' has-photo' : '' ?>">
  <div class="welcome-text">
    <p class="date-line"><?= D::weekday($today, true) ?>, <?= D::dmy($today) ?></p>
    <h1>Chào <?= e(trim($user['name'])) ?></h1>
    <p class="summary">
      <?php if ($weekTotal): ?>Tuần này có <strong><?= $weekTotal ?> bài</strong> trên lịch.<?php else: ?>Tuần này chưa có bài nào trên lịch.<?php endif ?>
      <?php if ($pendingCount): ?><a href="/posts?status=pending"><strong><?= $pendingCount ?> bài</strong> đang chờ duyệt</a>.<?php else: ?>Không có bài nào chờ duyệt.<?php endif ?>
      Tháng này đã đăng <strong><?= $publishedMonth ?> bài</strong>.
    </p>
    <div class="welcome-actions">
      <?php if (can('post:create')): ?><a class="btn btn-primary" href="/posts/new"><?= icon('plus') ?> Soạn bài</a><?php endif ?>
      <a class="btn" href="/calendar"><?= icon('calendar') ?> Mở lịch đăng bài</a>
    </div>
  </div>
  <?php if ($hotelPhoto): ?>
    <div class="welcome-photo" style="background-image:url('<?= e($hotelPhoto) ?>')" role="img" aria-label="Ảnh Bin Bin Hotel"></div>
  <?php else: ?>
    <img class="welcome-mark" src="/public/img/logo.png" alt="" width="104" height="104">
  <?php endif ?>
</header>

<nav class="quick" aria-label="Việc cần chú ý">
  <a class="quick-tile<?= $due ? ' is-alert' : '' ?>" href="/calendar">
    <span class="quick-icon"><?= icon('calendar') ?></span>
    <span><strong><?= count($due) ?></strong><small>bài cần đăng hôm nay</small></span>
  </a>
  <a class="quick-tile<?= $pendingCount ? ' is-alert' : '' ?>" href="/posts?status=pending">
    <span class="quick-icon"><?= icon('file') ?></span>
    <span><strong><?= $pendingCount ?></strong><small>bài chờ duyệt</small></span>
  </a>
  <a class="quick-tile<?= $noPost ? ' is-alert' : '' ?>" href="/events">
    <span class="quick-icon"><?= icon('flag') ?></span>
    <span><strong><?= count($upcoming) ?></strong><small>sự kiện sắp tới<?= $noPost ? ", $noPost chưa có bài" : '' ?></small></span>
  </a>
  <a class="quick-tile" href="/email/campaigns">
    <span class="quick-icon"><?= icon('mail') ?></span>
    <span><strong><?= $campaign ? $pct((int) $campaign['stats']['opened'], (int) $campaign['stats']['total']) : '—' ?></strong><small>tỷ lệ mở email gần nhất</small></span>
  </a>
</nav>

<div class="dash">
  <div class="dash-main">
    <section class="panel">
      <div class="panel-head">
        <h2>Cần đăng hôm nay</h2>
        <a href="/calendar">Xem lịch</a>
      </div>
      <?php if (!$due): ?>
        <p class="empty">Hôm nay không có bài nào đến giờ đăng.</p>
      <?php else: ?>
        <ul class="due">
          <?php foreach ($due as $p): $late = substr($p['scheduled_at'], 0, 10) < $today; ?>
            <li>
              <div class="due-time<?= $late ? ' is-late' : '' ?>">
                <strong><?= D::hm($p['scheduled_at']) ?></strong>
                <small><?= $late ? 'Trễ từ ' . D::dm($p['scheduled_at']) : 'Hôm nay' ?></small>
              </div>
              <div class="due-body">
                <span class="muted small"><?= e($p['format'] ?: 'Bài đăng') ?>, cả 4 kênh</span>
                <a href="/posts/<?= $p['id'] ?>" class="due-title"><?= e($p['title']) ?></a>
              </div>
              <?php if (can('post:publish')): ?>
                <form method="post" action="/posts/<?= $p['id'] ?>/publish" class="due-action">
                  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="back" value="dashboard">
                  <button class="btn btn-small" type="submit"><?= icon('check') ?> Đã đăng</button>
                </form>
              <?php endif ?>
            </li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>Chờ duyệt</h2>
        <?php if ($pendingCount > count($pending)): ?><a href="/posts?status=pending">Xem cả <?= $pendingCount ?> bài</a><?php endif ?>
      </div>
      <?php if (!$pending): ?>
        <p class="empty">Không có bài nào chờ duyệt.</p>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>Bài</th><th>Ngày đăng</th><th class="hide-sm">Người soạn</th><?php if ($user['role'] === 'admin'): ?><th><span class="sr-only">Duyệt</span></th><?php endif ?></tr></thead>
          <tbody>
          <?php foreach ($pending as $p): ?>
            <tr>
              <td><a href="/posts/<?= $p['id'] ?>"><?= e($p['title']) ?></a></td>
              <td class="nowrap"><?= $p['scheduled_at'] ? D::dmyhm($p['scheduled_at']) : 'Chưa chọn' ?></td>
              <td class="hide-sm"><?= e($p['author_name'] ?? '') ?></td>
              <?php if ($user['role'] === 'admin'): ?>
                <td class="right">
                  <form method="post" action="/posts/<?= $p['id'] ?>/approve">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="back" value="dashboard">
                    <button class="btn btn-small" type="submit">Duyệt</button>
                  </form>
                </td>
              <?php endif ?>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      <?php endif ?>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Hoạt động gần đây</h2></div>
      <?php if (!$activity): ?>
        <p class="empty">Chưa có hoạt động nào.</p>
      <?php else: ?>
        <ul class="feed">
          <?php foreach ($activity as $a): ?>
            <li><span class="feed-who"><?= e($a['user_name'] ?: 'Hệ thống') ?></span> <?= e($actions[$a['action']] ?? $a['action']) ?>
              <span class="feed-what"><?= e($a['summary']) ?></span>
              <time><?= D::dmyhm($a['created_at']) ?></time></li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
    </section>
  </div>

  <div class="dash-side">
    <section class="panel">
      <div class="panel-head"><h2>Sự kiện sắp tới</h2><a href="/events">Tất cả</a></div>
      <?php if (!$upcoming): ?>
        <p class="empty">45 ngày tới chưa có sự kiện nào. <a href="/events/new">Thêm sự kiện</a></p>
      <?php else: ?>
        <ol class="upcoming">
          <?php foreach ($upcoming as $ev): $draftDay = max($today, D::addDays($ev['occ_start'], -3)); ?>
            <li>
              <div class="up-date"><strong><?= substr($ev['occ_start'], 8, 2) ?></strong><small>Th<?= (int) substr($ev['occ_start'], 5, 2) ?></small></div>
              <div>
                <p class="up-title"><?= e($ev['title']) ?></p>
                <p class="up-meta">
                  <?= D::relative($ev['occ_start']) ?>,
                  <?php if ($ev['post_count']): ?><?= $ev['post_count'] ?> bài đã lên kế hoạch
                  <?php else: ?><span class="warn">chưa có bài</span><?php if (can('post:create')): ?> <a href="/posts/new?event=<?= $ev['id'] ?>&amp;date=<?= $draftDay ?>">Soạn bài</a><?php endif ?><?php endif ?>
                </p>
              </div>
            </li>
          <?php endforeach ?>
        </ol>
      <?php endif ?>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Tuần <?= D::dm($weekStart) ?> – <?= D::dm($weekEnd) ?></h2></div>
      <table class="table table-compact">
        <thead><tr><th>Trạng thái</th><th class="num">Số bài</th></tr></thead>
        <tbody>
          <?php foreach (C::STATUSES as $s): ?>
            <tr><td><?= partial('partials/chips', ['kind' => 'status', 'value' => $s['key']]) ?></td><td class="num"><?= $week[$s['key']] ?? 0 ?></td></tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Email gần nhất</h2><a href="/email/campaigns">Chiến dịch</a></div>
      <?php if (!$campaign): ?>
        <p class="empty">Chưa gửi chiến dịch nào.</p>
      <?php else: ?>
        <p><a href="/email/campaigns/<?= $campaign['id'] ?>"><strong><?= e($campaign['name']) ?></strong></a></p>
        <dl class="kv">
          <dt>Đã gửi</dt><dd><?= num($campaign['stats']['total']) ?> người, <?= D::dmy($campaign['sent_at']) ?></dd>
          <dt>Tỷ lệ mở</dt><dd><?= $pct((int) $campaign['stats']['opened'], (int) $campaign['stats']['total']) ?></dd>
          <dt>Tỷ lệ nhấp</dt><dd><?= $pct((int) $campaign['stats']['clicked'], (int) $campaign['stats']['total']) ?></dd>
        </dl>
      <?php endif ?>
    </section>
  </div>
</div>
<?= partial('partials/bottom') ?>
