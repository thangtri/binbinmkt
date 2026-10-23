<?php if ($pages > 1):
  $link = fn($p) => '?' . http_build_query(['page' => $p] + $query);
?>
<nav class="pager" aria-label="Phân trang">
  <?php if ($page > 1): ?><a class="btn btn-quiet" href="<?= e($link($page - 1)) ?>"><?= icon('left') ?> Trang trước</a><?php endif ?>
  <span>Trang <?= $page ?>/<?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn btn-quiet" href="<?= e($link($page + 1)) ?>">Trang sau <?= icon('right') ?></a><?php endif ?>
</nav>
<?php endif ?>
