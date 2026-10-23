<?= partial('partials/top') ?>
<header class="page-head">
  <div><h1>Bài đăng</h1><p class="sub"><?= $total ?> bài phù hợp bộ lọc</p></div>
  <?php if (can('post:create')): ?><a class="btn btn-primary" href="/posts/new"><?= icon('plus') ?> Soạn bài</a><?php endif ?>
</header>

<nav class="tabs" aria-label="Lọc theo trạng thái">
  <?php
    $base = function ($s) use ($query) {
        $q = $query;
        unset($q['page'], $q['status']);
        if ($s) $q['status'] = $s;
        return '?' . http_build_query($q);
    };
    $cur = $query['status'] ?? '';
  ?>
  <a href="<?= e($base('')) ?>" class="<?= !$cur ? 'is-on' : '' ?>">Tất cả</a>
  <?php foreach (C::STATUSES as $s): ?>
    <a href="<?= e($base($s['key'])) ?>" class="<?= $cur === $s['key'] ? 'is-on' : '' ?>"><?= e($s['label']) ?> <span class="count"><?= $counts[$s['key']] ?? 0 ?></span></a>
  <?php endforeach ?>
</nav>

<form class="filters" method="get">
  <?php if ($cur): ?><input type="hidden" name="status" value="<?= e($cur) ?>"><?php endif ?>
  <label class="field field-inline field-grow"><span class="sr-only">Tìm</span>
    <input type="search" name="q" value="<?= e($query['q'] ?? '') ?>" placeholder="Tìm theo tiêu đề hoặc nội dung">
  </label>
  <label class="check"><input type="checkbox" name="mine" value="1"<?= !empty($query['mine']) ? ' checked' : '' ?> data-autosubmit> Bài của tôi</label>
  <button class="btn" type="submit">Lọc</button>
</form>

<?php if (!$posts): ?>
  <div class="panel empty-state">
    <p>Chưa có bài nào phù hợp.</p>
    <?php if (can('post:create')): ?><a class="btn btn-primary" href="/posts/new">Soạn bài đầu tiên</a><?php endif ?>
  </div>
<?php else: ?>
  <div class="panel panel-flush">
    <table class="table table-rows">
      <thead><tr><th>Ngày đăng</th><th>Bài</th><th>Định dạng</th><th>Trạng thái</th><th>Người soạn</th></tr></thead>
      <tbody>
      <?php foreach ($posts as $p): ?>
        <tr>
          <td class="nowrap"><?php if ($p['scheduled_at']): ?><strong><?= D::dm($p['scheduled_at']) ?></strong> <span class="muted"><?= D::hm($p['scheduled_at']) ?>, <?= D::weekday($p['scheduled_at']) ?></span><?php else: ?><span class="muted">Chưa chọn ngày</span><?php endif ?></td>
          <td>
            <a href="/posts/<?= $p['id'] ?>" class="row-title"><?= e($p['title']) ?></a>
            <?php if ($p['event_title']): ?><small class="muted block">Cho sự kiện: <?= e($p['event_title']) ?></small><?php endif ?>
          </td>
          <td class="muted"><?= e($p['format'] ?? '') ?></td>
          <td><?= partial('partials/chips', ['kind' => 'status', 'value' => $p['status']]) ?></td>
          <td><?= e($p['author_name'] ?? '') ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?= partial('partials/pager', ['page' => $page, 'pages' => $pages]) ?>
<?php endif ?>
<?= partial('partials/bottom') ?>
