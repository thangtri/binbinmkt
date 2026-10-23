<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> – Bin Bin Marketing</title>
  <link rel="icon" href="/public/img/logo.png">
  <?php if (!empty($siteVerification['zalo'])): ?><meta name="zalo-platform-site-verification" content="<?= e($siteVerification['zalo']) ?>"><?php endif ?>
  <?php if (!empty($siteVerification['facebook'])): ?><meta name="facebook-domain-verification" content="<?= e($siteVerification['facebook']) ?>"><?php endif ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="/public/css/app.css">
  <link rel="stylesheet" href="/public/css/polish.css">
  <link rel="stylesheet" href="/public/css/sky.css">
</head>
