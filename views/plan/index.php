<?= partial('partials/top') ?>
<header class="page-head">
  <div>
    <p class="crumbs"><a href="/calendar">Lịch đăng bài</a></p>
    <h1>Kế hoạch tháng bằng AI</h1>
    <p class="sub">AI đọc sự kiện, ưu đãi, bài đã lên lịch và bài hiệu quả gần đây để đề xuất lịch đăng cả tháng. Bạn chọn bài nào lấy, hệ thống tạo bài nháp.</p>
  </div>
</header>
<?php if ($error): ?><div class="flash flash-error" role="alert">Chưa lập được kế hoạch: <?= e($error) ?></div><?php endif ?>

<?php if (!$aiReady): ?>
  <div class="panel empty-state"><p>Chưa bật AI. Quản trị viên cần thêm <code>ANTHROPIC_API_KEY</code> vào cấu hình máy chủ.</p></div>
<?php else: ?>
  <form method="post" action="/plan" class="panel settings-form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="settings-grid">
      <label class="field"><span>Tháng</span><input type="month" name="month" value="<?= e($month) ?>" required></label>
      <label class="field"><span>Số bài mỗi tuần</span><input type="number" name="per_week" min="1" max="10" value="4"></label>
      <label class="field field-wide"><span>Trọng tâm tháng này (không bắt buộc)</span><textarea name="focus" rows="3" placeholder="Ví dụ: đẩy combo cuối tuần cho cặp đôi, giới thiệu chi nhánh mới, tăng đánh giá Google"></textarea></label>
    </div>
    <div class="toolbar"><button class="btn btn-primary" type="submit" data-busy="AI đang lập kế hoạch… (khoảng 1 phút)"><?= icon('spark') ?> Lập kế hoạch</button><span class="hint">Mất khoảng 30–90 giây.</span></div>
  </form>
<?php endif ?>

<?php if ($plans): ?>
  <section class="panel" style="margin-top: 20px">
    <h2>Kế hoạch đã lập</h2>
    <ul class="plain-list">
      <?php foreach ($plans as $p): ?><li><a href="/plan/<?= $p['id'] ?>">Tháng <?= e($p['month']) ?></a> <span class="muted small"><?= D::dmyhm($p['created_at']) ?>, <?= e($p['user_name'] ?? '') ?><?= $p['brief'] ? ': ' . e(mb_substr($p['brief'], 0, 80)) : '' ?></span></li><?php endforeach ?>
    </ul>
  </section>
<?php endif ?>
<?= partial('partials/bottom') ?>
