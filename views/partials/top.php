<?= partial('partials/head') ?>
<?php
  $nav = [
    ['/', 'Tổng quan', 'home', fn($p) => $p === '/'],
    ['/calendar', 'Lịch đăng bài', 'calendar', fn($p) => str_starts_with($p, '/calendar') || str_starts_with($p, '/plan')],
    ['/posts', 'Bài đăng', 'file', fn($p) => str_starts_with($p, '/posts')],
    ['/events', 'Sự kiện', 'flag', fn($p) => str_starts_with($p, '/events')],
    ['/channels', 'Kênh', 'share', fn($p) => str_starts_with($p, '/channels')],
    ['/email/campaigns', 'Email marketing', 'mail', fn($p) => str_starts_with($p, '/email')],
    ['/offers', 'Ưu đãi', 'tag', fn($p) => str_starts_with($p, '/offers')],
    ['/reports', 'Báo cáo', 'chart', fn($p) => str_starts_with($p, '/reports')],
  ];
  if (($user['role'] ?? '') === 'admin') {
    $nav[] = ['/users', 'Tài khoản', 'users', fn($p) => str_starts_with($p, '/users')];
    $nav[] = ['/settings', 'Thông tin khách sạn', 'key', fn($p) => str_starts_with($p, '/settings')];
  }
?>
<body>
<a class="skip" href="#main">Bỏ qua menu</a>
<div class="shell">
  <aside class="side" id="side">
    <a class="brand" href="/">
      <img src="/public/img/logo.png" alt="" width="40" height="40">
      <span><strong>Bin Bin Hotel</strong><small>Marketing</small></span>
    </a>
    <nav aria-label="Chính">
      <?php foreach ($nav as [$href, $label, $ic, $match]): $on = $match($path); ?>
        <a href="<?= $href ?>" class="nav-item<?= $on ? ' is-on' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= e($label) ?></span></a>
      <?php endforeach ?>
    </nav>
    <div class="side-foot">
      <a class="me" href="/account">
        <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim($user['name']), 0, 1))) ?></span>
        <span><strong><?= e($user['name']) ?></strong><small><?= e(C::ROLES[$user['role']]['label'] ?? '') ?></small></span>
      </a>
      <form method="post" action="/logout">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="icon-btn" type="submit" title="Đăng xuất" aria-label="Đăng xuất"><?= icon('logout') ?></button>
      </form>
    </div>
  </aside>
  <div class="topbar">
    <button class="icon-btn" type="button" data-toggle-nav aria-controls="side" aria-expanded="false" aria-label="Mở menu"><?= icon('menu') ?></button>
    <a class="brand brand-sm" href="/"><img src="/public/img/logo.png" alt="" width="30" height="30"><strong>Bin Bin Marketing</strong></a>
  </div>
  <main id="main" class="main">
    <?php if ($flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></div>
    <?php endif ?>
