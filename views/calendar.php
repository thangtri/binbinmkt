<?= partial('partials/top') ?>
<?php
  $editable = can('post:edit');
  $chip = fn($p) => '<a class="chip chip-' . e($p['status']) . '" href="/posts/' . $p['id'] . '"'
    . ($editable && $p['status'] !== 'published' ? ' draggable="true" data-post="' . $p['id'] . '"' : '')
    . ' title="' . e(C::STATUSES[$p['status']]['label'] . ': ' . $p['title']) . '"><time>' . D::hm($p['scheduled_at']) . '</time><span>' . e($p['title']) . '</span></a>';
?>
<header class="page-head">
  <div>
    <h1>Lịch đăng bài</h1>
    <p class="sub">
      <?php if ($view === 'board'): ?><?= D::dmy($start) ?> – <?= D::dmy($end) ?><?php else: ?><?= D::monthLabel($year, $month) ?><?php endif ?>
    </p>
  </div>
  <div class="toolbar">
    <?php if (can('post:create')): ?><a class="btn" href="/plan"><?= icon('spark') ?> Kế hoạch tháng bằng AI</a><?php endif ?>
    <div class="seg" role="group" aria-label="Kiểu xem">
      <a href="/calendar?view=board" class="<?= $view === 'board' ? 'is-on' : '' ?>"<?= $view === 'board' ? ' aria-current="page"' : '' ?>>Bảng 2 tuần</a>
      <a href="/calendar?view=month" class="<?= $view === 'month' ? 'is-on' : '' ?>"<?= $view === 'month' ? ' aria-current="page"' : '' ?>>Tháng</a>
    </div>
    <div class="seg" role="group" aria-label="Chuyển thời gian">
      <?php if ($view === 'board'): ?>
        <a href="/calendar?view=board&amp;start=<?= $prev ?>" aria-label="Tuần trước"><?= icon('left') ?></a>
        <a href="/calendar?view=board">Hôm nay</a>
        <a href="/calendar?view=board&amp;start=<?= $next ?>" aria-label="Tuần sau"><?= icon('right') ?></a>
      <?php else: ?>
        <a href="/calendar?view=month&amp;m=<?= $prevM ?>" aria-label="Tháng trước"><?= icon('left') ?></a>
        <a href="/calendar?view=month">Tháng này</a>
        <a href="/calendar?view=month&amp;m=<?= $nextM ?>" aria-label="Tháng sau"><?= icon('right') ?></a>
      <?php endif ?>
    </div>
  </div>
</header>

<div class="legend" aria-label="Chú thích trạng thái">
  <?php foreach (C::STATUSES as $s): ?><span class="chip chip-<?= $s['key'] ?> chip-legend"><span><?= e($s['label']) ?></span></span><?php endforeach ?>
  <?php if ($editable): ?><span class="hint">Kéo một bài sang ô khác để đổi ngày đăng.</span><?php endif ?>
</div>

<?php if ($view === 'board'): $lanes = max(1, $eventLanes); ?>
  <div class="board-scroll" data-board data-csrf="<?= e($csrf) ?>">
    <div class="board" style="--days: <?= count($days) ?>">
      <div class="b-corner"></div>
      <?php foreach ($days as $i => $day): $dow = D::weekday($day); ?>
        <div class="b-day<?= $day === $today ? ' is-today' : '' ?><?= $dow === 'T7' || $dow === 'CN' ? ' is-weekend' : '' ?>" style="grid-column: <?= $i + 2 ?>">
          <small><?= $dow ?></small><strong><?= substr($day, 8, 2) ?></strong><?php if ($i === 0 || substr($day, 8, 2) === '01'): ?><small>Th<?= (int) substr($day, 5, 2) ?></small><?php endif ?>
        </div>
      <?php endforeach ?>

      <div class="b-label b-label-events" style="grid-row: span <?= $lanes ?>">Sự kiện</div>
      <div class="b-events" style="grid-row: span <?= $lanes ?>; --lanes: <?= $lanes ?>">
        <?php foreach ($days as $i => $day): ?><div class="b-evbg<?= $day === $today ? ' is-today' : '' ?>" style="grid-column: <?= $i + 1 ?>"></div><?php endforeach ?>
        <?php foreach ($events as $ev): ?>
          <a class="evbar ev-<?= e($ev['kind']) ?><?= $ev['clippedStart'] ? ' cut-start' : '' ?><?= $ev['clippedEnd'] ? ' cut-end' : '' ?>"
             href="<?= can('event:edit') ? '/events/' . $ev['id'] . '/edit' : '/events' ?>"
             style="grid-column: <?= $ev['col'] ?> / span <?= $ev['span'] ?>; grid-row: <?= $ev['lane'] + 1 ?>"
             title="<?= e($ev['title']) ?> (<?= D::dm($ev['occ_start']) ?><?= $ev['occ_end'] !== $ev['occ_start'] ? ' – ' . D::dm($ev['occ_end']) : '' ?>)"><?= e($ev['title']) ?></a>
        <?php endforeach ?>
        <?php if (!$events): ?><span class="b-noevents">Không có sự kiện trong 2 tuần này</span><?php endif ?>
      </div>

      <div class="b-label b-label-posts"><span>Bài đăng</span><small>cả 4 kênh</small></div>
      <?php foreach ($days as $day): ?>
        <div class="b-cell b-cell-posts<?= $day === $today ? ' is-today' : '' ?><?= $day < $today ? ' is-past' : '' ?>" data-date="<?= $day ?>">
          <?php foreach ($cells[$day] ?? [] as $p) echo $chip($p) ?>
          <?php if (can('post:create') && $day >= $today): ?>
            <a class="b-add" href="/posts/new?date=<?= $day ?>" aria-label="Soạn bài ngày <?= D::dm($day) ?>"><?= icon('plus') ?></a>
          <?php endif ?>
        </div>
      <?php endforeach ?>
    </div>
  </div>
<?php else: ?>
  <div class="month" data-board data-csrf="<?= e($csrf) ?>">
    <?php foreach (['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'] as $wd): ?><div class="m-head"><?= $wd ?></div><?php endforeach ?>
    <?php foreach ($weeks as $wk): foreach ($wk as $day):
         $items = $byDay[$day] ?? []; $evs = $eventsByDay[$day] ?? []; $out = substr($day, 5, 2) !== substr($m, 5, 2); ?>
      <div class="m-cell<?= $out ? ' is-out' : '' ?><?= $day === $today ? ' is-today' : '' ?>" data-date="<?= $day ?>">
        <div class="m-date">
          <span><?= (int) substr($day, 8, 2) ?></span>
          <?php if (can('post:create') && $day >= $today): ?><a class="b-add" href="/posts/new?date=<?= $day ?>" aria-label="Soạn bài ngày <?= D::dm($day) ?>"><?= icon('plus') ?></a><?php endif ?>
        </div>
        <?php foreach ($evs as $ev): ?><span class="m-ev ev-<?= e($ev['kind']) ?>" title="<?= e($ev['title']) ?>"><?= e($ev['title']) ?></span><?php endforeach ?>
        <?php foreach (array_slice($items, 0, 4) as $p) echo $chip($p) ?>
        <?php if (count($items) > 4): ?><a class="m-more" href="/calendar?view=board&amp;start=<?= $day ?>">+<?= count($items) - 4 ?> bài</a><?php endif ?>
      </div>
    <?php endforeach; endforeach ?>
  </div>
<?php endif ?>
<?= partial('partials/bottom') ?>
