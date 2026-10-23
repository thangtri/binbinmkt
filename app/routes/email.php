<?php

const CONTACTS_PAGE = 50;

const STARTER_EMAIL = '<p>Chào {{ten}},</p>
<p>Bin Bin Hotel gửi bạn ưu đãi dành riêng cho khách quen: <strong>giảm 15% khi đặt phòng trực tiếp</strong> trong tháng này.</p>
<p><a href="https://zalo.me/" style="display:inline-block;background:#c8232b;color:#ffffff;padding:12px 20px;border-radius:4px;text-decoration:none;">Nhắn Zalo để đặt phòng</a></p>
<p>Hẹn gặp lại bạn,<br>Bin Bin Hotel</p>';

function allTags(): array
{
    $set = [];
    foreach (DB::all("SELECT tags FROM contacts WHERE tags IS NOT NULL AND tags <> ''") as $r) {
        foreach (explode(',', $r['tags']) as $t) if (trim($t) !== '') $set[mb_strtolower(trim($t))] = true;
    }
    $tags = array_keys($set);
    sort($tags);
    return $tags;
}

function contactTotals(): array
{
    $t = DB::get('SELECT COUNT(*) AS total, SUM(subscribed) AS subscribed FROM contacts');
    return ['total' => (int) $t['total'], 'subscribed' => (int) ($t['subscribed'] ?? 0)];
}

function loadContact($id): array
{
    $c = DB::get('SELECT * FROM contacts WHERE id = ?', [int($id)]);
    if (!$c) abort(404, 'Không tìm thấy liên hệ.');
    return $c;
}

function campaignStats(int $id): array
{
    $s = DB::get(
        "SELECT COUNT(*) AS total,
           SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
           SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
           SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued,
           SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered,
           SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
           SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
           SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) AS bounced,
           SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) AS unsubscribed
         FROM campaign_recipients WHERE campaign_id = ?",
        [$id]
    );
    return array_map('intval', $s);
}

function loadCampaign($id): array
{
    $c = DB::get('SELECT c.*, u.name AS author_name FROM campaigns c LEFT JOIN users u ON u.id = c.created_by WHERE c.id = ?', [int($id)]);
    if (!$c) abort(404, 'Không tìm thấy chiến dịch.');
    $c['id'] = (int) $c['id'];
    return $c;
}

function readCampaign(array $b): array
{
    return [
        'name' => str($b['name'] ?? '', 200),
        'subject' => str($b['subject'] ?? '', 200),
        'preheader' => str($b['preheader'] ?? '', 200),
        'body_html' => str($b['body_html'] ?? '', 200000),
        'audience_tag' => mb_strtolower(str($b['audience_tag'] ?? '', 60)),
    ];
}

function validateCampaign(array $c): ?string
{
    if (!$c['name']) return 'Hãy đặt tên chiến dịch (chỉ hiển thị nội bộ).';
    if (!$c['subject']) return 'Hãy nhập tiêu đề email.';
    if (!$c['body_html']) return 'Nội dung email đang trống.';
    return null;
}

Router::get('/email', fn() => redirect('/email/campaigns'));

/* ---------- Danh bạ ---------- */

Router::get('/email/contacts', function () {
    $where = [];
    $params = [];
    $search = q('q');
    if (is_string($search) && $search !== '') {
        $where[] = '(email LIKE ? OR name LIKE ? OR phone LIKE ?)';
        array_push($params, "%$search%", "%$search%", "%$search%");
    }
    if (q('status') === 'unsub') $where[] = 'subscribed = 0';
    elseif (q('status') === 'sub') $where[] = 'subscribed = 1';
    $rows = DB::all('SELECT * FROM contacts ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC', $params);
    $tag = mb_strtolower(str(q('tag'), 60));
    if ($tag) $rows = array_values(array_filter($rows, fn($c) => Campaigns::hasTag($c['tags'], $tag)));
    $page = max(1, int(q('page'), 1));
    render('email/contacts', [
        'title' => 'Danh bạ email',
        'contacts' => array_slice($rows, ($page - 1) * CONTACTS_PAGE, CONTACTS_PAGE),
        'total' => count($rows),
        'page' => $page,
        'pages' => max(1, (int) ceil(count($rows) / CONTACTS_PAGE)),
        'tags' => allTags(),
        'tag' => $tag,
        'totals' => contactTotals(),
    ]);
});

Router::post('/email/contacts', function () {
    Auth::requirePerm('contact:edit');
    $email = strtolower(str(body('email'), 190));
    if (!isEmail($email)) {
        flash('error', 'Email không hợp lệ.');
        redirect('/email/contacts');
    }
    if (DB::get('SELECT id FROM contacts WHERE email = ?', [$email])) {
        flash('error', "$email đã có trong danh bạ.");
        redirect('/email/contacts');
    }
    DB::run('INSERT INTO contacts (email, name, phone, tags, subscribed, unsub_token, source, created_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?)', [
        $email, str(body('name'), 150), str(body('phone'), 30), parseTags(body('tags')), token(), 'manual', D::now(),
    ]);
    flash('success', "Đã thêm $email.");
    redirect('/email/contacts');
});

Router::post('/email/contacts/import', function () {
    Auth::requirePerm('contact:edit');
    $text = Upload::csvText('file');
    if ($text === null) {
        flash('error', 'Hãy chọn file CSV (tối đa 5 MB).');
        redirect('/email/contacts');
    }
    $rows = Csv::parse($text);
    $header = array_map(fn($c) => mb_strtolower(trim($c)), $rows[0] ?? []);
    $col = function (array $names) use ($header) {
        foreach ($header as $i => $c) if (in_array($c, $names, true)) return $i;
        return -1;
    };
    $iEmail = $col(['email', 'e-mail', 'mail']);
    $iName = $col(['name', 'ten', 'tên', 'họ tên', 'ho ten', 'họ và tên']);
    $iPhone = $col(['phone', 'sdt', 'sđt', 'số điện thoại', 'dien thoai', 'điện thoại']);
    $iTags = $col(['tags', 'tag', 'nhóm', 'nhom']);
    $data = array_slice($rows, 1);
    if ($iEmail === -1) {
        // Không có dòng tiêu đề: coi cột đầu là email
        $iEmail = 0;
        $data = $rows;
    }
    $extraTags = parseTags(body('tags'));
    $added = 0;
    $skipped = 0;
    foreach ($data as $r) {
        $email = strtolower(str($r[$iEmail] ?? '', 190));
        if (!isEmail($email) || DB::get('SELECT id FROM contacts WHERE email = ?', [$email])) {
            $skipped++;
            continue;
        }
        DB::run('INSERT INTO contacts (email, name, phone, tags, subscribed, unsub_token, source, created_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?)', [
            $email,
            $iName >= 0 ? str($r[$iName] ?? '', 150) : '',
            $iPhone >= 0 ? str($r[$iPhone] ?? '', 30) : '',
            parseTags(($iTags >= 0 ? ($r[$iTags] ?? '') : '') . ',' . $extraTags),
            token(),
            'import',
            D::now(),
        ]);
        $added++;
    }
    logActivity(user(), 'contact.import', 'contact', null, "Nhập $added liên hệ");
    flash('success', "Đã thêm $added liên hệ. Bỏ qua $skipped dòng trùng hoặc email không hợp lệ.");
    redirect('/email/contacts');
});

Router::get('/email/contacts/export.csv', function () {
    $rows = [['email', 'ten', 'sdt', 'tags', 'trang_thai', 'ngay_them']];
    foreach (DB::all('SELECT email, name, phone, tags, subscribed, created_at FROM contacts ORDER BY id') as $r) {
        $rows[] = [$r['email'], $r['name'], $r['phone'], $r['tags'], (int) $r['subscribed'] ? 'dang_nhan' : 'da_huy', $r['created_at']];
    }
    Csv::download('binbin-danh-ba-' . D::today() . '.csv', $rows);
});

Router::post('/email/contacts/{id}', function ($id) {
    Auth::requirePerm('contact:edit');
    $c = loadContact($id);
    DB::run('UPDATE contacts SET name = ?, phone = ?, tags = ? WHERE id = ?', [str(body('name'), 150), str(body('phone'), 30), parseTags(body('tags')), $c['id']]);
    flash('success', "Đã lưu {$c['email']}.");
    redirect('/email/contacts');
});

Router::post('/email/contacts/{id}/delete', function ($id) {
    Auth::requirePerm('contact:edit');
    $c = loadContact($id);
    DB::run('DELETE FROM contacts WHERE id = ?', [$c['id']]);
    logActivity(user(), 'contact.delete', 'contact', $c['id'], $c['email']);
    flash('success', "Đã xoá {$c['email']} khỏi danh bạ.");
    redirect('/email/contacts');
});

/* ---------- Chiến dịch ---------- */

Router::get('/email/campaigns', function () {
    $list = DB::all('SELECT c.*, u.name AS author_name FROM campaigns c LEFT JOIN users u ON u.id = c.created_by ORDER BY c.id DESC');
    foreach ($list as &$c) $c['stats'] = $c['status'] !== 'draft' ? campaignStats((int) $c['id']) : null;
    unset($c);
    render('email/campaigns', ['title' => 'Email marketing', 'list' => $list, 'subscribed' => contactTotals()['subscribed']]);
});

Router::get('/email/campaigns/new', function () {
    Auth::requirePerm('campaign:edit');
    render('email/campaign-form', ['title' => 'Chiến dịch mới', 'c' => ['id' => null, 'name' => '', 'subject' => '', 'preheader' => '', 'body_html' => STARTER_EMAIL, 'audience_tag' => ''], 'tags' => allTags(), 'error' => null]);
});

Router::post('/email/campaigns', function () {
    Auth::requirePerm('campaign:edit');
    $c = readCampaign(body());
    if ($error = validateCampaign($c)) render('email/campaign-form', ['title' => 'Chiến dịch mới', 'c' => $c + ['id' => null], 'tags' => allTags(), 'error' => $error], 400);
    $now = D::now();
    $id = DB::run('INSERT INTO campaigns (name, subject, preheader, body_html, audience_tag, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $c['name'], $c['subject'], $c['preheader'], $c['body_html'], $c['audience_tag'] ?: null, 'draft', user()['id'], $now, $now,
    ])['insertId'];
    logActivity(user(), 'campaign.create', 'campaign', $id, $c['name']);
    flash('success', 'Đã lưu chiến dịch. Xem trước rồi gửi thử cho chính bạn trước khi gửi thật.');
    redirect("/email/campaigns/$id");
});

Router::get('/email/campaigns/{id}', function ($id) {
    $c = loadCampaign($id);
    $draft = $c['status'] === 'draft';
    render('email/campaign-show', [
        'title' => $c['name'],
        'c' => $c,
        'stats' => $draft ? null : campaignStats($c['id']),
        'audienceCount' => $draft ? count(Campaigns::audience($c['audience_tag'])) : null,
        'recipients' => $draft ? [] : DB::all('SELECT * FROM campaign_recipients WHERE campaign_id = ? ORDER BY id LIMIT 300', [$c['id']]),
    ]);
});

// Bản xem trước hiển thị trong iframe, với CSP riêng (không cho chạy script)
Router::get('/email/campaigns/{id}/preview', function ($id) {
    $c = loadCampaign($id);
    header("Content-Security-Policy: default-src 'none'; img-src * data:; style-src 'unsafe-inline'; font-src *", true);
    header('X-Frame-Options: SAMEORIGIN');
    header('Content-Type: text/html; charset=utf-8');
    echo Brevo::renderEmail($c['subject'], $c['preheader'], Campaigns::personalize($c['body_html'], user()['name']), '#');
    exit;
});

Router::get('/email/campaigns/{id}/edit', function ($id) {
    Auth::requirePerm('campaign:edit');
    $c = loadCampaign($id);
    if ($c['status'] !== 'draft') {
        flash('error', 'Chiến dịch đã gửi không sửa được. Hãy nhân bản để tạo bản mới.');
        redirect("/email/campaigns/{$c['id']}");
    }
    render('email/campaign-form', ['title' => 'Sửa chiến dịch', 'c' => $c, 'tags' => allTags(), 'error' => null]);
});

Router::post('/email/campaigns/{id}', function ($id) {
    Auth::requirePerm('campaign:edit');
    $old = loadCampaign($id);
    if ($old['status'] !== 'draft') redirect("/email/campaigns/{$old['id']}");
    $c = readCampaign(body());
    if ($error = validateCampaign($c)) render('email/campaign-form', ['title' => 'Sửa chiến dịch', 'c' => $c + $old, 'tags' => allTags(), 'error' => $error], 400);
    DB::run('UPDATE campaigns SET name = ?, subject = ?, preheader = ?, body_html = ?, audience_tag = ?, updated_at = ? WHERE id = ?', [
        $c['name'], $c['subject'], $c['preheader'], $c['body_html'], $c['audience_tag'] ?: null, D::now(), $old['id'],
    ]);
    flash('success', 'Đã lưu chiến dịch.');
    redirect("/email/campaigns/{$old['id']}");
});

Router::post('/email/campaigns/{id}/test', function ($id) {
    Auth::requirePerm('campaign:edit');
    $c = loadCampaign($id);
    $to = strtolower(str(body('to'), 190)) ?: user()['email'];
    if (!isEmail($to)) {
        flash('error', 'Email nhận thử không hợp lệ.');
        redirect("/email/campaigns/{$c['id']}");
    }
    $html = Brevo::renderEmail($c['subject'], $c['preheader'], Campaigns::personalize($c['body_html'], user()['name']), cfg('appUrl') . '/');
    try {
        $out = Brevo::send($to, null, "[Thử] {$c['subject']}", $html, null, 'test');
        flash('success', $out['dryRun'] ? "Chế độ thử: chưa cấu hình Brevo nên không có email nào được gửi tới $to." : "Đã gửi email thử tới $to.");
    } catch (Throwable $e) {
        flash('error', 'Brevo không gửi được: ' . $e->getMessage());
    }
    redirect("/email/campaigns/{$c['id']}");
});

Router::post('/email/campaigns/{id}/send', function ($id) {
    Auth::requirePerm('campaign:send');
    $c = loadCampaign($id);
    if ($c['status'] !== 'draft') {
        flash('error', 'Chiến dịch này đã được gửi.');
        redirect("/email/campaigns/{$c['id']}");
    }
    $n = Campaigns::enqueue($c);
    if ($n === 0) {
        DB::run("UPDATE campaigns SET status = 'draft', updated_at = ? WHERE id = ?", [D::now(), $c['id']]);
        flash('error', 'Không có người nhận nào trong nhóm đã chọn.');
        redirect("/email/campaigns/{$c['id']}");
    }
    DB::run('UPDATE campaigns SET sent_by = ? WHERE id = ?', [user()['id'], $c['id']]);
    logActivity(user(), 'campaign.send', 'campaign', $c['id'], "{$c['name']} → $n người");
    flash('success', "Đang gửi tới $n người. Tải lại trang để xem tiến độ; phần còn lại được gửi tiếp tự động mỗi phút.");
    // Gửi ngay sau khi trang đã chuyển, phần chưa xong cron gửi tiếp
    redirect_then("/email/campaigns/{$c['id']}", fn() => Campaigns::run($c['id'], 240));
});

Router::post('/email/campaigns/{id}/duplicate', function ($id) {
    Auth::requirePerm('campaign:edit');
    $c = loadCampaign($id);
    $now = D::now();
    $newId = DB::run('INSERT INTO campaigns (name, subject, preheader, body_html, audience_tag, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        "{$c['name']} (bản sao)", $c['subject'], $c['preheader'], $c['body_html'], $c['audience_tag'], 'draft', user()['id'], $now, $now,
    ])['insertId'];
    flash('success', 'Đã nhân bản chiến dịch.');
    redirect("/email/campaigns/$newId/edit");
});

Router::post('/email/campaigns/{id}/delete', function ($id) {
    Auth::requirePerm('campaign:edit');
    $c = loadCampaign($id);
    if ($c['status'] !== 'draft' && user()['role'] !== 'admin') {
        flash('error', 'Chỉ quản trị viên mới xoá được chiến dịch đã gửi.');
        redirect("/email/campaigns/{$c['id']}");
    }
    if ($c['status'] === 'sending') {
        flash('error', 'Chiến dịch đang gửi, chờ gửi xong rồi mới xoá được.');
        redirect("/email/campaigns/{$c['id']}");
    }
    DB::run('DELETE FROM campaign_recipients WHERE campaign_id = ?', [$c['id']]);
    DB::run('DELETE FROM campaigns WHERE id = ?', [$c['id']]);
    logActivity(user(), 'campaign.delete', 'campaign', $c['id'], $c['name']);
    flash('success', 'Đã xoá chiến dịch.');
    redirect('/email/campaigns');
});
