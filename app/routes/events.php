<?php

function readEventForm(array $b): array
{
    $start = D::isDate($b['start_date'] ?? null) ? $b['start_date'] : '';
    $end = D::isDate($b['end_date'] ?? null) ? $b['end_date'] : $start;
    if ($end && $start && $end < $start) $end = $start;
    return [
        'title' => str($b['title'] ?? '', 200),
        'kind' => isset(C::EVENT_KINDS[$b['kind'] ?? '']) ? $b['kind'] : 'promo',
        'start_date' => $start,
        'end_date' => $end,
        'recurring' => empty($b['recurring']) ? 0 : 1,
        'description' => str($b['description'] ?? '', 5000),
    ];
}

function validateEvent(array $ev): ?string
{
    if (!$ev['title']) return 'Hãy đặt tên sự kiện.';
    if (!$ev['start_date']) return 'Hãy chọn ngày bắt đầu.';
    if (D::diffDays($ev['start_date'], $ev['end_date']) > 366) return 'Sự kiện không dài quá một năm.';
    return null;
}

function loadEvent($id): array
{
    $ev = DB::get('SELECT * FROM events WHERE id = ?', [int($id)]);
    if (!$ev) abort(404, 'Không tìm thấy sự kiện.');
    $ev['id'] = (int) $ev['id'];
    return $ev;
}

Router::get('/events', function () {
    $today = D::today();
    $from = D::isDate(q('from')) ? q('from') : $today;
    $to = D::addDays($from, 180);
    $kind = isset(C::EVENT_KINDS[q('kind', '')]) ? q('kind') : '';
    // Nhóm theo tháng để đọc như một cuốn lịch
    $months = [];
    foreach (Events::occurrences($from, $to) as $ev) {
        if ($kind && $ev['kind'] !== $kind) continue;
        $ev['post_count'] = (int) DB::value('SELECT COUNT(*) FROM posts WHERE event_id = ? AND scheduled_at BETWEEN ? AND ?', [
            $ev['id'], D::addDays($ev['occ_start'], -30) . ' 00:00:00', $ev['occ_end'] . ' 23:59:59',
        ]);
        $key = substr($ev['occ_start'], 0, 7);
        $months[$key] ??= ['key' => $key, 'label' => D::monthLabel((int) substr($key, 0, 4), (int) substr($key, 5, 2)), 'items' => []];
        $months[$key]['items'][] = $ev;
    }
    render('events/index', ['title' => 'Sự kiện', 'months' => array_values($months), 'from' => $from, 'to' => $to, 'kind' => $kind, 'today' => $today, 'aiReady' => AI::isConfigured()]);
});

Router::get('/events/new', function () {
    Auth::requirePerm('event:edit');
    $start = D::isDate(q('date')) ? q('date') : D::today();
    render('events/form', ['title' => 'Thêm sự kiện', 'ev' => ['id' => null, 'title' => '', 'kind' => 'promo', 'start_date' => $start, 'end_date' => $start, 'recurring' => 0, 'description' => ''], 'posts' => [], 'error' => null]);
});

Router::post('/events', function () {
    Auth::requirePerm('event:edit');
    $ev = readEventForm(body());
    if ($error = validateEvent($ev)) render('events/form', ['title' => 'Thêm sự kiện', 'ev' => $ev + ['id' => null], 'posts' => [], 'error' => $error], 400);
    $id = DB::run('INSERT INTO events (title, kind, start_date, end_date, recurring, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        $ev['title'], $ev['kind'], $ev['start_date'], $ev['end_date'], $ev['recurring'], $ev['description'], user()['id'], D::now(),
    ])['insertId'];
    logActivity(user(), 'event.create', 'event', $id, $ev['title']);
    flash('success', 'Đã thêm sự kiện.');
    redirect('/events?from=' . substr($ev['start_date'], 0, 8) . '01');
});

Router::get('/events/{id}/edit', function ($id) {
    Auth::requirePerm('event:edit');
    $ev = loadEvent($id);
    $posts = DB::all('SELECT id, title, channel, status, scheduled_at FROM posts WHERE event_id = ? ORDER BY scheduled_at DESC LIMIT 20', [$ev['id']]);
    render('events/form', ['title' => 'Sửa sự kiện', 'ev' => $ev, 'posts' => $posts, 'error' => null]);
});

Router::post('/events/{id}', function ($id) {
    Auth::requirePerm('event:edit');
    $old = loadEvent($id);
    $ev = readEventForm(body());
    if ($error = validateEvent($ev)) render('events/form', ['title' => 'Sửa sự kiện', 'ev' => $ev + $old, 'posts' => [], 'error' => $error], 400);
    DB::run('UPDATE events SET title = ?, kind = ?, start_date = ?, end_date = ?, recurring = ?, description = ? WHERE id = ?', [
        $ev['title'], $ev['kind'], $ev['start_date'], $ev['end_date'], $ev['recurring'], $ev['description'], $old['id'],
    ]);
    logActivity(user(), 'event.update', 'event', $old['id'], $ev['title']);
    flash('success', 'Đã lưu sự kiện.');
    redirect('/events?from=' . substr($ev['start_date'], 0, 8) . '01');
});

// AI gợi ý 3 bài cho một lần diễn ra của sự kiện
Router::post('/events/{id}/ideas', function ($id) {
    Auth::requirePerm('post:create');
    $ev = loadEvent($id);
    $ev['occ_start'] = D::isDate(body('start')) ? body('start') : $ev['start_date'];
    $ev['occ_end'] = D::isDate(body('end')) ? body('end') : $ev['end_date'];
    $today = D::today();
    $ideas = [];
    $error = null;
    try {
        foreach (AI::ideasForEvent($ev) as $it) {
            $d = D::addDays($ev['occ_start'], -max(0, min(30, (int) ($it['days_before'] ?? 0))));
            $ideas[] = $it + ['date' => max($d, $today)];
        }
    } catch (UserError $e) {
        $error = $e->getMessage();
    }
    render('events/ideas', ['title' => "Ý tưởng cho {$ev['title']}", 'ev' => $ev, 'ideas' => $ideas, 'error' => $error]);
});

Router::post('/events/{id}/delete', function ($id) {
    Auth::requirePerm('event:edit');
    $ev = loadEvent($id);
    DB::run('UPDATE posts SET event_id = NULL WHERE event_id = ?', [$ev['id']]);
    DB::run('DELETE FROM events WHERE id = ?', [$ev['id']]);
    logActivity(user(), 'event.delete', 'event', $ev['id'], $ev['title']);
    flash('success', 'Đã xoá sự kiện. Các bài liên quan vẫn được giữ.');
    redirect('/events');
});
