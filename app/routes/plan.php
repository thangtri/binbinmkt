<?php
// AI lập kế hoạch nội dung cả tháng → team chọn bài → tạo bài nháp hàng loạt

// Sau ngày 20 thì mặc định lập cho tháng sau
function defaultPlanMonth(): string
{
    $t = D::today();
    return (int) substr($t, 8, 2) < 20 ? substr($t, 0, 7) : Report::nextMonth(substr($t, 0, 7));
}

function recentPlans(): array
{
    return DB::all('SELECT p.id, p.month, p.brief, p.created_at, u.name AS user_name FROM ai_plans p LEFT JOIN users u ON u.id = p.created_by ORDER BY p.id DESC LIMIT 10');
}

function loadPlan($id): array
{
    $row = DB::get('SELECT * FROM ai_plans WHERE id = ?', [int($id)]);
    if (!$row) abort(404, 'Không tìm thấy kế hoạch này.');
    return ['plan' => json_decode_array($row['items'])] + $row;
}

Router::get('/plan', function () {
    Auth::requirePerm('post:create');
    render('plan/index', ['title' => 'Kế hoạch tháng bằng AI', 'month' => defaultPlanMonth(), 'plans' => recentPlans(), 'aiReady' => AI::isConfigured(), 'error' => null]);
});

Router::post('/plan', function () {
    Auth::requirePerm('post:create');
    $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) body('month')) ? body('month') : defaultPlanMonth();
    $perWeek = max(1, min(10, int(body('per_week'), 3)));
    $focus = str(body('focus'), 1000);
    try {
        $plan = AI::planMonth($month, $perWeek, $focus);
        $id = DB::run('INSERT INTO ai_plans (month, brief, items, created_by, created_at) VALUES (?, ?, ?, ?, ?)', [$month, $focus ?: null, json_encode($plan, JSON_UNESCAPED_UNICODE), user()['id'], D::now()])['insertId'];
        logActivity(user(), 'plan.create', 'plan', $id, "Kế hoạch tháng $month");
        redirect("/plan/$id");
    } catch (UserError $e) {
        render('plan/index', ['title' => 'Kế hoạch tháng bằng AI', 'month' => $month, 'plans' => recentPlans(), 'aiReady' => AI::isConfigured(), 'error' => $e->getMessage()], 502);
    }
});

Router::get('/plan/{id}', function ($id) {
    Auth::requirePerm('post:create');
    $p = loadPlan($id);
    render('plan/show', ['title' => "Kế hoạch tháng {$p['month']}", 'p' => $p, 'today' => D::today()]);
});

// Tạo bài nháp từ các dòng được chọn (pick_<i>=1), ngày giờ có thể sửa (date_<i>, time_<i>)
Router::post('/plan/{id}/create', function ($id) {
    Auth::requirePerm('post:create');
    $p = loadPlan($id);
    $b = body();
    $now = D::now();
    $n = 0;
    foreach ($p['plan']['items'] ?? [] as $i => $it) {
        if (empty($b["pick_$i"])) continue;
        $date = D::isDate($b["date_$i"] ?? null) ? $b["date_$i"] : $it['date'];
        $time = D::isTime($b["time_$i"] ?? null) ? $b["time_$i"] : $it['time'];
        $postId = DB::run(
            "INSERT INTO posts (title, channel, format, caption, hashtags, scheduled_at, status, event_id, offer_id, author_id, notes, auto_publish, created_at, updated_at)
             VALUES (?, 'all', ?, ?, ?, ?, 'draft', ?, ?, ?, ?, 1, ?, ?)",
            [
                str($it['title'] ?? '', 200),
                in_array($it['format'] ?? '', C::POST_FORMATS, true) ? $it['format'] : C::POST_FORMATS[0],
                str($it['caption'] ?? '', 60000),
                str($it['hashtags'] ?? '', 2000),
                "$date $time:00",
                $it['event_id'] ?: null,
                $it['offer_id'] ?: null,
                user()['id'],
                "Từ kế hoạch AI tháng {$p['month']}: " . str($it['why'] ?? '', 300),
                $now,
                $now,
            ]
        )['insertId'];
        PostChannels::ensure($postId);
        $n++;
    }
    if (!$n) {
        flash('error', 'Hãy chọn ít nhất một bài.');
        redirect("/plan/{$p['id']}");
    }
    logActivity(user(), 'plan.apply', 'plan', $p['id'], "Tạo $n bài nháp từ kế hoạch tháng {$p['month']}");
    flash('success', "Đã tạo $n bài nháp trên lịch tháng {$p['month']}. Thêm ảnh và gửi duyệt từng bài.");
    redirect("/calendar?view=month&m={$p['month']}");
});
