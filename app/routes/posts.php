<?php

const POSTS_PAGE = 30;

function loadPost($id): array
{
    $post = DB::get(
        'SELECT p.*, a.name AS author_name, r.name AS reviewer_name, e.title AS event_title
         FROM posts p
         LEFT JOIN users a ON a.id = p.author_id
         LEFT JOIN users r ON r.id = p.reviewer_id
         LEFT JOIN events e ON e.id = p.event_id
         WHERE p.id = ?',
        [(int) $id]
    );
    if (!$post) abort(404, 'Không tìm thấy bài đăng này.');
    $post['id'] = (int) $post['id'];
    return $post;
}

function canEditPost(array $post): bool
{
    if (user()['role'] === 'admin') return true;
    return can('post:edit') && $post['status'] !== 'published';
}

function canDeletePost(array $post): bool
{
    if (user()['role'] === 'admin') return true;
    return can('post:edit') && (int) $post['author_id'] === user()['id'] && $post['status'] !== 'published';
}

function postFormOptions(): array
{
    return [
        'events' => DB::all('SELECT id, title, start_date, recurring FROM events WHERE recurring = 1 OR end_date >= ? ORDER BY recurring, start_date', [D::addDays(D::today(), -30)]),
        'offerList' => Offers::active(D::addDays(D::today(), -30)),
        'aiReady' => AI::isConfigured(),
    ];
}

function readPostForm(array $b): array
{
    $date = D::isDate($b['date'] ?? null) ? $b['date'] : '';
    $time = D::isTime($b['time'] ?? null) ? $b['time'] : '09:00';
    return [
        'title' => str($b['title'] ?? '', 200),
        'format' => in_array($b['format'] ?? '', C::POST_FORMATS, true) ? $b['format'] : C::POST_FORMATS[0],
        'caption' => str($b['caption'] ?? '', 60000),
        'hashtags' => str($b['hashtags'] ?? '', 2000),
        'scheduled_at' => $date ? "$date $time:00" : null,
        'event_id' => int($b['event_id'] ?? 0) ?: null,
        'notes' => str($b['notes'] ?? '', 5000),
        'auto_publish' => empty($b['auto_publish']) ? 0 : 1,
        'offer_id' => int($b['offer_id'] ?? 0) ?: null,
    ];
}

function validatePost(array $p, ?string $intent): ?string
{
    if (!$p['title']) return 'Hãy đặt tiêu đề cho bài.';
    if (($intent === 'submit' || $intent === 'approve') && !$p['scheduled_at']) return 'Cần chọn ngày đăng trước khi gửi duyệt.';
    return null;
}

function afterSavePost(int $id, ?string $intent, string $wasStatus): void
{
    if ($intent === 'submit' && in_array($wasStatus, ['draft', 'pending'], true)) {
        DB::run("UPDATE posts SET status = 'pending', review_note = NULL WHERE id = ?", [$id]);
    } elseif ($intent === 'approve' && user()['role'] === 'admin' && $wasStatus !== 'published') {
        DB::run("UPDATE posts SET status = 'scheduled', reviewer_id = ? WHERE id = ?", [user()['id'], $id]);
    }
}

// Link của bài trên từng kênh: url_facebook, url_tiktok, url_zalo; Google Maps một link cho mỗi chi nhánh (url_google_<id>)
function savePostUrls(int $postId, array $b): void
{
    foreach (array_keys(C::CHANNELS) as $ch) {
        if ($ch === 'google' || !array_key_exists("url_$ch", $b)) continue;
        PostChannels::update($postId, $ch, ['published_url' => safeUrl($b["url_$ch"]) ?: null]);
    }
    $touched = false;
    foreach (PostChannels::googleLinks($postId) as $l) {
        if (!array_key_exists('url_google_' . $l['branch_id'], $b)) continue;
        PostChannels::saveGoogleLink($postId, (int) $l['branch_id'], safeUrl($b['url_google_' . $l['branch_id']]) ?: null);
        $touched = true;
    }
    if ($touched) PostChannels::syncGoogleRow($postId);
}

Router::get('/posts', function () {
    $where = [];
    $params = [];
    $status = q('status');
    if (is_string($status) && isset(C::STATUSES[$status])) {
        $where[] = 'p.status = ?';
        $params[] = $status;
    }
    if (is_string(q('q')) && q('q') !== '') {
        $where[] = '(p.title LIKE ? OR p.caption LIKE ?)';
        array_push($params, '%' . q('q') . '%', '%' . q('q') . '%');
    }
    if (q('mine')) {
        $where[] = 'p.author_id = ?';
        $params[] = user()['id'];
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $page = max(1, int(q('page'), 1));
    $total = (int) DB::value("SELECT COUNT(*) FROM posts p $sqlWhere", $params);
    $offset = ($page - 1) * POSTS_PAGE;
    $posts = DB::all(
        "SELECT p.*, u.name AS author_name, e.title AS event_title FROM posts p
         LEFT JOIN users u ON u.id = p.author_id LEFT JOIN events e ON e.id = p.event_id
         $sqlWhere
         ORDER BY p.scheduled_at IS NULL DESC, p.scheduled_at DESC, p.id DESC
         LIMIT " . POSTS_PAGE . " OFFSET $offset",
        $params
    );
    $counts = array_map('intval', array_column(DB::all('SELECT status, COUNT(*) AS n FROM posts GROUP BY status'), 'n', 'status'));
    render('posts/index', ['title' => 'Bài đăng', 'posts' => $posts, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / POSTS_PAGE)), 'counts' => $counts]);
});

Router::get('/posts/new', function () {
    Auth::requirePerm('post:create');
    $post = [
        'id' => null,
        'title' => '',
        'format' => C::POST_FORMATS[0],
        'caption' => '',
        'hashtags' => Settings::all()['hashtags'] ?? '',
        'scheduled_at' => D::isDate(q('date')) ? q('date') . ' 09:00:00' : null,
        'event_id' => int(q('event')) ?: null,
        'notes' => '',
        'status' => 'draft',
        'auto_publish' => 1,
        'offer_id' => int(q('offer')) ?: null,
        'review_note' => null,
    ];
    render('posts/form', ['title' => 'Soạn bài mới', 'post' => $post, 'mediaList' => [], 'error' => null] + postFormOptions());
});

// AI viết 3 phương án nội dung từ ý chính (gọi từ form soạn bài)
Router::post('/posts/ai/draft', function () {
    Auth::requirePerm('post:create');
    $brief = str(body('brief'), 2000);
    if (!$brief) json_out(['error' => 'Hãy nhập ý chính của bài.'], 400);
    $ev = int(body('event_id')) ? DB::get('SELECT title FROM events WHERE id = ?', [int(body('event_id'))]) : null;
    try {
        $options = AI::writePost($brief, str(body('tone'), 100), str(body('format'), 40), $ev['title'] ?? null, str(body('current'), 3000), int(body('offer_id')) ?: null);
        logActivity(user(), 'post.ai', 'post', null, mb_substr($brief, 0, 120));
        json_out(['options' => $options]);
    } catch (UserError $e) {
        json_out(['error' => $e->getMessage()], 502);
    }
});

// Tạo bài nháp từ ý tưởng AI gợi ý cho sự kiện
Router::post('/posts/from-idea', function () {
    Auth::requirePerm('post:create');
    $date = D::isDate(body('date')) ? body('date') : null;
    $now = D::now();
    $id = DB::run(
        "INSERT INTO posts (title, channel, format, caption, hashtags, scheduled_at, status, event_id, author_id, created_at, updated_at)
         VALUES (?, 'all', ?, ?, ?, ?, 'draft', ?, ?, ?, ?)",
        [
            str(body('title'), 200) ?: 'Bài từ ý tưởng AI',
            in_array(body('format'), C::POST_FORMATS, true) ? body('format') : C::POST_FORMATS[0],
            str(body('caption'), 60000),
            str(body('hashtags'), 2000),
            $date ? "$date 09:00:00" : null,
            int(body('event_id')) ?: null,
            user()['id'],
            $now,
            $now,
        ]
    )['insertId'];
    PostChannels::ensure($id);
    logActivity(user(), 'post.create', 'post', $id, str(body('title'), 120) . ' (ý tưởng AI)');
    flash('success', 'Đã tạo bài nháp từ ý tưởng. Thêm ảnh, kiểm tra nội dung rồi gửi duyệt.');
    redirect("/posts/$id/edit");
});

Router::post('/posts', function () {
    Auth::requirePerm('post:create');
    $p = readPostForm(body());
    $intent = body('intent');
    if ($error = validatePost($p, $intent)) {
        render('posts/form', ['title' => 'Soạn bài mới', 'post' => $p + ['id' => null, 'status' => 'draft', 'review_note' => null], 'mediaList' => [], 'error' => $error] + postFormOptions(), 400);
    }
    $files = Upload::many('media');
    $now = D::now();
    $id = DB::run(
        "INSERT INTO posts (title, channel, format, caption, hashtags, media_path, scheduled_at, status, event_id, author_id, notes, auto_publish, offer_id, created_at, updated_at)
         VALUES (?, 'all', ?, ?, ?, NULL, ?, 'draft', ?, ?, ?, ?, ?, ?, ?)",
        [$p['title'], $p['format'], $p['caption'], $p['hashtags'], $p['scheduled_at'], $p['event_id'], user()['id'], $p['notes'], $p['auto_publish'], $p['offer_id'], $now, $now]
    )['insertId'];
    afterSavePost($id, $intent, 'draft');
    PostChannels::ensure($id);
    Media::add($id, $files);
    logActivity(user(), 'post.create', 'post', $id, $p['title']);
    flash('success', $intent === 'submit' ? 'Đã lưu và gửi duyệt.' : ($intent === 'approve' ? 'Đã lưu và duyệt bài.' : 'Đã lưu bản nháp.'));
    redirect("/posts/$id");
});

Router::get('/posts/{id}', function ($id) {
    $post = loadPost($id);
    render('posts/show', [
        'title' => $post['title'],
        'post' => $post,
        'channels' => PostChannels::list($post['id']),
        'googleLinks' => PostChannels::googleLinks($post['id']),
        'offer' => $post['offer_id'] ? Offers::get($post['offer_id']) : null,
        'mediaList' => Media::list($post['id']),
        'autoChannels' => Publisher::AUTO,
        'canEdit' => canEditPost($post),
        'canDelete' => canDeletePost($post),
    ]);
});

Router::get('/posts/{id}/edit', function ($id) {
    $post = loadPost($id);
    if (!canEditPost($post)) Auth::deny();
    render('posts/form', ['title' => 'Sửa bài', 'post' => $post, 'mediaList' => Media::list($post['id']), 'error' => null] + postFormOptions());
});

Router::post('/posts/{id}', function ($id) {
    $post = loadPost($id);
    if (!canEditPost($post)) Auth::deny();
    $p = readPostForm(body());
    $intent = body('intent');
    if ($error = validatePost($p, $intent)) {
        render('posts/form', ['title' => 'Sửa bài', 'post' => $p + $post, 'mediaList' => Media::list($post['id']), 'error' => $error] + postFormOptions(), 400);
    }
    $files = Upload::many('media');
    // Biên tập viên sửa bài đã duyệt → bài cần được duyệt lại
    $status = $post['status'];
    if (user()['role'] !== 'admin' && $status === 'scheduled') $status = 'pending';
    DB::run(
        'UPDATE posts SET title = ?, format = ?, caption = ?, hashtags = ?, scheduled_at = ?, auto_publish = ?, offer_id = ?,
         event_id = ?, notes = ?, status = ?, updated_at = ? WHERE id = ?',
        [$p['title'], $p['format'], $p['caption'], $p['hashtags'], $p['scheduled_at'], $p['auto_publish'], $p['offer_id'], $p['event_id'], $p['notes'], $status, D::now(), $post['id']]
    );
    Media::applyForm($post['id'], body());
    Media::add($post['id'], $files);
    afterSavePost($post['id'], $intent, $status);
    logActivity(user(), 'post.update', 'post', $post['id'], $p['title']);
    flash('success', $status === 'pending' && $post['status'] === 'scheduled' ? 'Đã lưu. Bài sửa sau khi duyệt cần được duyệt lại.' : 'Đã lưu thay đổi.');
    redirect("/posts/{$post['id']}");
});

Router::post('/posts/{id}/submit', function ($id) {
    Auth::requirePerm('post:submit');
    $post = loadPost($id);
    if ($post['status'] !== 'draft') flash('error', 'Chỉ bản nháp mới gửi duyệt được.');
    elseif (!$post['scheduled_at']) flash('error', 'Cần chọn ngày đăng trước khi gửi duyệt.');
    else {
        DB::run("UPDATE posts SET status = 'pending', review_note = NULL, updated_at = ? WHERE id = ?", [D::now(), $post['id']]);
        logActivity(user(), 'post.submit', 'post', $post['id'], $post['title']);
        flash('success', 'Đã gửi duyệt.');
    }
    redirect("/posts/{$post['id']}");
});

Router::post('/posts/{id}/approve', function ($id) {
    Auth::requirePerm('post:approve');
    $post = loadPost($id);
    if ($post['status'] !== 'pending') flash('error', 'Bài này không ở trạng thái chờ duyệt.');
    else {
        DB::run("UPDATE posts SET status = 'scheduled', reviewer_id = ?, review_note = ?, updated_at = ? WHERE id = ?", [user()['id'], str(body('note'), 2000) ?: null, D::now(), $post['id']]);
        logActivity(user(), 'post.approve', 'post', $post['id'], $post['title']);
        flash('success', 'Đã duyệt bài.');
    }
    redirect(body('back') === 'dashboard' ? '/' : "/posts/{$post['id']}");
});

Router::post('/posts/{id}/reject', function ($id) {
    Auth::requirePerm('post:approve');
    $post = loadPost($id);
    $note = str(body('note'), 2000);
    if (!$note) flash('error', 'Hãy ghi lý do để người soạn biết cần sửa gì.');
    else {
        DB::run("UPDATE posts SET status = 'draft', reviewer_id = ?, review_note = ?, updated_at = ? WHERE id = ?", [user()['id'], $note, D::now(), $post['id']]);
        logActivity(user(), 'post.reject', 'post', $post['id'], $post['title']);
        flash('success', 'Đã trả bài về nháp kèm góp ý.');
    }
    redirect("/posts/{$post['id']}");
});

Router::post('/posts/{id}/publish', function ($id) {
    Auth::requirePerm('post:publish');
    $post = loadPost($id);
    if ($post['status'] !== 'scheduled') flash('error', 'Chỉ bài đã duyệt mới đánh dấu đã đăng được.');
    else {
        DB::run("UPDATE posts SET status = 'published', published_at = ?, updated_at = ? WHERE id = ?", [D::now(), D::now(), $post['id']]);
        savePostUrls($post['id'], body());
        PostChannels::recalcTotals($post['id']);
        logActivity(user(), 'post.publish', 'post', $post['id'], $post['title']);
        flash('success', 'Đã đánh dấu đã đăng. Dán link bài trên từng kênh để hệ thống tự cập nhật lượt thích, bình luận.');
    }
    redirect(body('back') === 'dashboard' ? '/' : "/posts/{$post['id']}");
});

// Đăng ngay lên các kênh tự đăng được (không chờ tới giờ hẹn)
Router::post('/posts/{id}/publish-now', function ($id) {
    Auth::requirePerm('post:publish');
    $post = loadPost($id);
    if ($post['status'] !== 'scheduled') {
        flash('error', 'Chỉ bài đã duyệt mới đăng được.');
        redirect("/posts/{$post['id']}");
    }
    $results = Publisher::publishPost($post['id']);
    $failed = array_filter($results, fn($r) => ($r['ok'] ?? null) === false);
    $sent = array_filter($results, fn($r) => !empty($r['ok']));
    $name = fn($r) => C::CHANNELS[$r['channel']]['name'];
    if (!$sent && !$failed) flash('warn', 'Không có kênh nào tự đăng được: Facebook, TikTok chưa kết nối hoặc đã có link.');
    elseif ($failed) flash('error', 'Lỗi khi đăng: ' . implode('; ', array_map(fn($r) => $name($r) . ': ' . $r['error'], $failed)));
    else flash('success', 'Đã gửi bài lên ' . implode(', ', array_map($name, $sent)) . '. TikTok có thể mất vài phút để xử lý.');
    logActivity(user(), 'post.publishnow', 'post', $post['id'], $post['title']);
    redirect("/posts/{$post['id']}");
});

Router::post('/posts/{id}/retry/{channel}', function ($id, $channel) {
    Auth::requirePerm('post:publish');
    $post = loadPost($id);
    if (in_array($channel, Publisher::AUTO, true)) Publisher::retry($post['id'], $channel);
    flash('success', 'Đã thử đăng lại. Xem trạng thái trong bảng Trên các kênh.');
    redirect("/posts/{$post['id']}");
});

// Nhập link và số liệu theo từng kênh (số liệu Facebook, TikTok tự cập nhật nếu kênh đã kết nối)
Router::post('/posts/{id}/metrics', function ($id) {
    Auth::requirePerm('post:publish');
    $post = loadPost($id);
    $b = body();
    savePostUrls($post['id'], $b);
    $n = fn($v) => trim((string) ($v ?? '')) === '' ? null : max(0, int($v));
    foreach (array_keys(C::CHANNELS) as $ch) {
        $fields = [];
        foreach (['views', 'likes', 'comments', 'shares'] as $m) {
            if (array_key_exists("{$m}_$ch", $b)) $fields[$m] = $n($b["{$m}_$ch"]);
        }
        PostChannels::update($post['id'], $ch, $fields);
    }
    PostChannels::recalcTotals($post['id']);
    DB::run('UPDATE posts SET updated_at = ? WHERE id = ?', [D::now(), $post['id']]);
    flash('success', 'Đã lưu link và số liệu.');
    redirect("/posts/{$post['id']}");
});

// Kéo thả trên lịch: đổi ngày, giữ nguyên giờ
Router::post('/posts/{id}/reschedule', function ($id) {
    Auth::requirePerm('post:edit');
    $post = loadPost($id);
    if ($post['status'] === 'published') json_out(['error' => 'Bài đã đăng không đổi ngày được.'], 400);
    $date = body('date');
    if (!D::isDate($date)) json_out(['error' => 'Ngày không hợp lệ.'], 400);
    $time = $post['scheduled_at'] ? substr($post['scheduled_at'], 11, 8) : '09:00:00';
    DB::run('UPDATE posts SET scheduled_at = ?, updated_at = ? WHERE id = ?', ["$date $time", D::now(), $post['id']]);
    logActivity(user(), 'post.reschedule', 'post', $post['id'], "{$post['title']} → " . D::dmy($date));
    json_out(['ok' => true]);
});

Router::post('/posts/{id}/duplicate', function ($id) {
    Auth::requirePerm('post:create');
    $post = loadPost($id);
    $now = D::now();
    $newId = DB::run(
        "INSERT INTO posts (title, channel, format, caption, hashtags, media_path, scheduled_at, status, event_id, offer_id, author_id, notes, auto_publish, created_at, updated_at)
         VALUES (?, 'all', ?, ?, ?, NULL, NULL, 'draft', ?, ?, ?, ?, ?, ?, ?)",
        ["{$post['title']} (bản sao)", $post['format'], $post['caption'], $post['hashtags'], $post['event_id'], $post['offer_id'], user()['id'], $post['notes'], $post['auto_publish'], $now, $now]
    )['insertId'];
    PostChannels::ensure($newId);
    Media::copy($post['id'], $newId);
    logActivity(user(), 'post.create', 'post', $newId, "{$post['title']} (bản sao)");
    flash('success', 'Đã tạo bản sao ở dạng nháp. Chọn ngày đăng mới và chỉnh nội dung nếu cần.');
    redirect("/posts/$newId/edit");
});

Router::post('/posts/{id}/delete', function ($id) {
    $post = loadPost($id);
    if (!canDeletePost($post)) Auth::deny();
    DB::run('DELETE FROM post_channels WHERE post_id = ?', [$post['id']]);
    DB::run('DELETE FROM post_google WHERE post_id = ?', [$post['id']]);
    Media::removeAll($post['id']);
    DB::run('DELETE FROM posts WHERE id = ?', [$post['id']]);
    logActivity(user(), 'post.delete', 'post', $post['id'], $post['title']);
    flash('success', 'Đã xoá bài.');
    redirect('/posts');
});

// Tải toàn bộ ảnh/video của bài về một file ZIP, đánh số theo thứ tự đăng
Router::get('/posts/{id}/media.zip', function ($id) {
    $post = loadPost($id);
    $entries = [];
    foreach (Media::list($post['id']) as $i => $m) {
        $full = Upload::fullPath($m['path']);
        if ($full && is_file($full)) $entries[] = ['name' => sprintf('%02d.', $i + 1) . pathinfo($m['path'], PATHINFO_EXTENSION), 'path' => $full];
    }
    if (!$entries) redirect("/posts/{$post['id']}");
    Zip::send("binbin-bai-{$post['id']}.zip", $entries);
});
