<?php
// Đường dẫn công khai: huỷ nhận email, trang pháp lý, ảnh có chữ ký, webhook Brevo, việc nền qua web

function contactByToken($t): ?array
{
    if (!preg_match('/^[a-f0-9]{16,64}$/', (string) $t)) return null;
    return DB::get('SELECT * FROM contacts WHERE unsub_token = ?', [$t]);
}

// Trang xác nhận — chỉ huỷ khi người nhận bấm nút (tránh bộ lọc thư tự mở link làm huỷ nhầm)
Router::get('/u/{token}', function ($token) {
    render('unsubscribe', ['title' => 'Huỷ nhận email', 'contact' => contactByToken($token), 'done' => false, 'token' => $token]);
});

Router::post('/u/{token}', function ($token) {
    $contact = contactByToken($token);
    if ($contact && (int) $contact['subscribed']) {
        $now = D::now();
        DB::run('UPDATE contacts SET subscribed = 0, unsubscribed_at = ? WHERE id = ?', [$now, $contact['id']]);
        // Ghi nhận vào chiến dịch gần nhất mà người này nhận được
        $last = DB::value('SELECT MAX(id) FROM campaign_recipients WHERE contact_id = ?', [$contact['id']]);
        if ($last) DB::run('UPDATE campaign_recipients SET unsubscribed_at = COALESCE(unsubscribed_at, ?) WHERE id = ?', [$now, $last]);
    }
    render('unsubscribe', ['title' => 'Huỷ nhận email', 'contact' => $contact, 'done' => true, 'token' => $token]);
});

// Trang pháp lý công khai: Facebook, TikTok, Zalo yêu cầu khi đăng ký ứng dụng
foreach (['/chinh-sach-bao-mat' => ['privacy', 'Chính sách bảo mật'], '/dieu-khoan' => ['terms', 'Điều khoản sử dụng']] as $p => [$page, $title]) {
    Router::get($p, function () use ($page, $title) {
        render('legal', ['title' => $title, 'page' => $page, 'updated' => '22/10/2026', 'contact' => cfg('legalContact')]);
    });
}

// Ảnh/video của bài qua link có chữ ký (TikTok tự tải ảnh về khi đăng)
Router::get('/m/{id}/{exp}/{sig}', function ($id, $exp, $sig) {
    if (!Publisher::verifyMedia($id, $exp, $sig)) abort(404, 'Không tìm thấy.');
    $m = DB::get('SELECT path, mime FROM post_media WHERE id = ?', [(int) $id]);
    $full = $m ? Upload::fullPath($m['path']) : null;
    if (!$full) abort(404, 'Không tìm thấy.');
    send_file($full, $m['mime'] ?: 'application/octet-stream');
});

Router::post('/webhooks/brevo/{secret}', function ($secret) {
    $want = cfg('brevo.webhookSecret');
    if (!$want || !hash_equals($want, (string) $secret)) {
        http_response_code(404);
        exit;
    }
    $columns = [
        'delivered' => 'delivered_at', 'opened' => 'opened_at', 'unique_opened' => 'opened_at', 'click' => 'clicked_at',
        'hard_bounce' => 'bounced_at', 'soft_bounce' => 'bounced_at', 'blocked' => 'bounced_at', 'invalid_email' => 'bounced_at',
        'unsubscribed' => 'unsubscribed_at',
    ];
    $body = json_decode(file_get_contents('php://input'), true);
    $events = is_array($body) && array_is_list($body) ? $body : [$body];
    foreach ($events as $ev) {
        if (!is_array($ev)) continue;
        $column = $columns[$ev['event'] ?? ''] ?? null;
        $messageId = (string) ($ev['message-id'] ?? $ev['messageId'] ?? '');
        if (!$column || $messageId === '') continue;
        $bare = trim($messageId, '<>');
        $r = DB::get('SELECT id, contact_id FROM campaign_recipients WHERE message_id IN (?, ?, ?)', [$messageId, $bare, "<$bare>"]);
        if (!$r) continue;
        $now = D::now();
        DB::run("UPDATE campaign_recipients SET $column = COALESCE($column, ?) WHERE id = ?", [$now, $r['id']]);
        // Mở/nhấp cũng có nghĩa là đã nhận được
        if ($column === 'opened_at' || $column === 'clicked_at') DB::run('UPDATE campaign_recipients SET delivered_at = COALESCE(delivered_at, ?) WHERE id = ?', [$now, $r['id']]);
        if ($column === 'unsubscribed_at' || in_array($ev['event'], ['hard_bounce', 'invalid_email'], true)) {
            DB::run('UPDATE contacts SET subscribed = 0, unsubscribed_at = COALESCE(unsubscribed_at, ?) WHERE id = ?', [$now, $r['contact_id']]);
        }
    }
    json_out(['ok' => true]);
});

// Việc nền qua web, cho hosting chỉ đặt được cron dạng gọi link: /cron/<CRON_KEY>
Router::get('/cron/{key}', function ($key) {
    $want = cfg('cronKey');
    if (strlen((string) $want) < 16 || !hash_equals($want, (string) $key)) abort(404, 'Không tìm thấy.');
    header('Content-Type: text/plain; charset=utf-8');
    echo "ok\n";
    finish_request();
    Jobs::run();
    exit;
});
