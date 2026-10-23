<?= partial('partials/top') ?>
<header class="page-head">
  <div><h1>Sự kiện</h1><p class="sub">Lễ, Tết, mùa du lịch và ưu đãi trong 6 tháng tính từ <?= D::dmy($from) ?></p></div>
  <?php if (can('event:edit')): ?><a class="btn btn-primary" href="/events/new"><?= icon('plus') ?> Thêm sự kiện</a><?php endif ?>
</header>

<form class="filters" method="get">
  <label class="field field-inline"><span>Từ ngày</span><input type="date" name="from" value="<?= e($from) ?>"></label>
  <label class="field field-inline"><span>Loại</span>
    <select name="kind" data-autosubmit>
      <option value="">Tất cả</option>
      <?php foreach (C::EVENT_KINDS as $k): ?><option value="<?= $k['key'] ?>"<?= $kind === $k['key'] ? ' selected' : '' ?>><?= e($k['label']) ?></option><?php endforeach ?>
    </select>
  </label>
  <button class="btn" type="submit">Xem</button>
</form>

<?php if (!$months): ?>
  <div class="panel empty-state"><p>Không có sự kiện nào trong khoảng này.</p><?php if (can('event:edit')): ?><a class="btn btn-primary" href="/events/new">Thêm sự kiện</a><?php endif ?></div>
<?php endif ?>

<?php foreach ($months as $g): ?>
  <section class="ev-month">
    <h2><?= e($g['label']) ?></h2>
    <ul class="ev-list">
      <?php foreach ($g['items'] as $ev): $past = $ev['occ_end'] < $today; $draftDay = max($today, D::addDays($ev['occ_start'], -3)); ?>
        <li class="ev-row<?= $past ? ' is-past' : '' ?>">
          <div class="ev-when">
            <strong><?= D::dm($ev['occ_start']) ?></strong>
            <?php if ($ev['occ_end'] !== $ev['occ_start']): ?><small>đến <?= D::dm($ev['occ_end']) ?></small><?php else: ?><small><?= D::weekday($ev['occ_start'], true) ?></small><?php endif ?>
          </div>
          <span class="ev-kind ev-<?= e($ev['kind']) ?>"><?= e(C::EVENT_KINDS[$ev['kind']]['label'] ?? $ev['kind']) ?></span>
          <div class="ev-body">
            <p class="ev-title"><?= e($ev['title']) ?><?php if ((int) $ev['recurring']): ?> <small class="muted">hằng năm</small><?php endif ?></p>
            <?php if ($ev['description']): ?><p class="muted small"><?= e($ev['description']) ?></p><?php endif ?>
          </div>
          <div class="ev-posts">
            <?php if ($ev['post_count']): ?><span><?= $ev['post_count'] ?> bài</span>
            <?php elseif (!$past): ?><span class="warn">Chưa có bài</span><?php endif ?>
            <?php if (!$past && can('post:create')): ?><a class="btn btn-small" href="/posts/new?event=<?= $ev['id'] ?>&amp;date=<?= $draftDay ?>">Soạn bài</a><?php endif ?>
            <?php if (!$past && $aiReady && can('post:create')): ?>
              <form method="post" action="/events/<?= $ev['id'] ?>/ideas">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="start" value="<?= $ev['occ_start'] ?>"><input type="hidden" name="end" value="<?= $ev['occ_end'] ?>">
                <button class="btn btn-small" type="submit" data-busy="AI đang nghĩ…">Gợi ý bài bằng AI</button>
              </form>
            <?php endif ?>
            <?php if (can('event:edit')): ?><a class="btn btn-small btn-quiet" href="/events/<?= $ev['id'] ?>/edit">Sửa</a><?php endif ?>
          </div>
        </li>
      <?php endforeach ?>
    </ul>
  </section>
<?php endforeach ?>
<?= partial('partials/bottom') ?>
