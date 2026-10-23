<?php

Router::get('/', function () {
    $today = D::today();
    $weekStart = D::startOfWeek($today);
    $weekEnd = D::addDays($weekStart, 6);
    $monthStart = substr($today, 0, 8) . '01';

    // Bài đã duyệt, đến hạn hôm nay hoặc đã quá hạn mà chưa đánh dấu đăng
    $due = DB::all(
        "SELECT p.*, u.name AS author_name FROM posts p LEFT JOIN users u ON u.id = p.author_id
         WHERE p.status = 'scheduled' AND p.scheduled_at <= ? ORDER BY p.scheduled_at",
        ["$today 23:59:59"]
    );
    $pending = DB::all(
        "SELECT p.*, u.name AS author_name FROM posts p LEFT JOIN users u ON u.id = p.author_id
         WHERE p.status = 'pending' ORDER BY p.scheduled_at IS NULL, p.scheduled_at LIMIT 6"
    );
    $week = [];
    foreach (DB::all('SELECT status, COUNT(*) AS n FROM posts WHERE scheduled_at BETWEEN ? AND ? GROUP BY status', ["$weekStart 00:00:00", "$weekEnd 23:59:59"]) as $r) {
        $week[$r['status']] = (int) $r['n'];
    }

    // Sự kiện 45 ngày tới và số bài đã chuẩn bị cho từng sự kiện
    $upcoming = array_slice(Events::occurrences($today, D::addDays($today, 45)), 0, 8);
    foreach ($upcoming as &$ev) {
        $ev['post_count'] = (int) DB::value('SELECT COUNT(*) FROM posts WHERE event_id = ? AND scheduled_at BETWEEN ? AND ?', [
            $ev['id'], D::addDays($ev['occ_start'], -30) . ' 00:00:00', $ev['occ_end'] . ' 23:59:59',
        ]);
    }
    unset($ev);

    $campaign = DB::get("SELECT * FROM campaigns WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 1");
    if ($campaign) {
        $campaign['stats'] = DB::get(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                    SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked
             FROM campaign_recipients WHERE campaign_id = ? AND status = 'sent'",
            [$campaign['id']]
        );
    }

    render('dashboard', [
        'title' => 'Tổng quan',
        'today' => $today,
        'due' => $due,
        'pending' => $pending,
        'pendingCount' => (int) DB::value("SELECT COUNT(*) FROM posts WHERE status = 'pending'"),
        'week' => $week,
        'weekStart' => $weekStart,
        'weekEnd' => $weekEnd,
        'publishedMonth' => (int) DB::value("SELECT COUNT(*) FROM posts WHERE status = 'published' AND published_at >= ?", ["$monthStart 00:00:00"]),
        'upcoming' => $upcoming,
        'campaign' => $campaign,
        'activity' => DB::all('SELECT a.*, u.name AS user_name FROM activity a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8'),
    ]);
});
