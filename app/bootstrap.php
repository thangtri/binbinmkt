<?php
// Nạp cấu hình, thư viện và database. Dùng chung cho web (index.php) và việc nền (cron.php).

declare(strict_types=0);

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Ho_Chi_Minh');

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/schema.php';
foreach (glob(__DIR__ . '/lib/*.php') as $f) require_once $f;
foreach (glob(__DIR__ . '/lib/connectors/*.php') as $f) require_once $f;

// Chạy migration khi phiên bản code mới hơn database (đánh dấu bằng file để khỏi kiểm tra mỗi lần)
(function () {
    $dataDir = dirname(cfg('db.sqlitePath'));
    if (!is_dir($dataDir)) mkdir($dataDir, 0775, true);
    DB::pdo();
    $marker = $dataDir . '/.schema-' . md5_file(__DIR__ . '/schema.php') . '-' . DB::$dialect;
    if (!is_file($marker)) {
        Schema::migrate();
        Schema::seed();
        foreach (glob($dataDir . '/.schema-*') as $old) @unlink($old);
        @file_put_contents($marker, D::now());
    }
    if (!is_dir(cfg('uploadDir'))) @mkdir(cfg('uploadDir'), 0775, true);
})();
