<?php
// Việc nền: đặt Cron Job trên Hostinger chạy mỗi phút
//   /usr/bin/php /home/<tài khoản>/domains/<tên miền>/public_html/cron.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/app/bootstrap.php';
Jobs::run();
