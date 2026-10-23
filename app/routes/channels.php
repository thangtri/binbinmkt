<?php

// Chỉ số chính của từng kênh. Google Maps không có "người theo dõi" nên dùng đánh giá.
const CHANNEL_METRICS = [
    'social' => [
        ['key' => 'followers', 'label' => 'Người theo dõi'],
        ['key' => 'reach', 'label' => 'Tiếp cận 28 ngày'],
        ['key' => 'engagement', 'label' => 'Tương tác 28 ngày'],
    ],
    'zalo' => [['key' => 'followers', 'label' => 'Người quan tâm OA']],
    'google' => [
        ['key' => 'rating', 'label' => 'Điểm đánh giá', 'decimals' => 1],
        ['key' => 'reviews', 'label' => 'Số đánh giá'],
    ],
];

// Khoá cần có trong file cấu hình để kết nối từng kênh
const REQUIRED_ENV = [
    'google' => ['GOOGLE_PLACES_API_KEY'],
    'facebook' => ['FACEBOOK_APP_ID', 'FACEBOOK_APP_SECRET'],
    'tiktok' => ['TIKTOK_CLIENT_KEY', 'TIKTOK_CLIENT_SECRET'],
    'zalo' => ['ZALO_APP_ID', 'ZALO_APP_SECRET'],
];

function metricsFor(string $key): array
{
    return CHANNEL_METRICS[$key] ?? CHANNEL_METRICS['social'];
}

function channelOr404(string $key): array
{
    if (!isset(C::CHANNELS[$key])) abort(404, 'Không có kênh này.');
    return C::CHANNELS[$key];
}

// Giá trị gần nhất của một chỉ số và mức thay đổi so với lần ghi trước có chỉ số đó
function summarizeMetric(array $stats, array $metric): array
{
    $rows = array_values(array_filter($stats, fn($s) => $s[$metric['key']] !== null));
    if (!$rows) return $metric + ['value' => null, 'delta' => null, 'on' => null, 'prevOn' => null];
    $value = (float) $rows[0][$metric['key']];
    $prev = isset($rows[1]) ? (float) $rows[1][$metric['key']] : null;
    return $metric + [
        'value' => $value,
        'on' => $rows[0]['recorded_on'],
        'delta' => $prev === null ? null : round($value - $prev, $metric['decimals'] ?? 0),
        'prevOn' => $rows[1]['recorded_on'] ?? null,
    ];
}

function chartSeries(array $stats, array $metrics): array
{
    $chartMetric = $metrics[0];
    foreach ($metrics as $m) {
        if (count(array_filter($stats, fn($s) => $s[$m['key']] !== null)) >= 2) {
            $chartMetric = $m;
            break;
        }
    }
    $rows = array_slice(array_values(array_filter($stats, fn($s) => $s[$chartMetric['key']] !== null)), 0, 26);
    $series = array_reverse(array_map(fn($s) => ['day' => $s['recorded_on'], 'value' => (float) $s[$chartMetric['key']]], $rows));
    return [$chartMetric, $series];
}

function channelAccount(string $key): array
{
    return DB::get('SELECT a.*, u.name AS owner_name FROM channel_accounts a LEFT JOIN users u ON u.id = a.owner_id WHERE a.channel = ?', [$key]) ?? ['channel' => $key];
}

function connectionInfo(array $ch, array $account): array
{
    $conn = Sync::conn($ch['key']);
    $token = $conn::OAUTH ? Sync::getToken($ch['key']) : null;
    return [
        'configured' => $conn::isConfigured(),
        'connected' => Sync::isConnected($account, $token),
        'oauth' => $conn::OAUTH,
        'missingEnv' => array_values(array_filter(REQUIRED_ENV[$ch['key']], fn($n) => Config::env($n) === '')),
        'status' => $account['sync_status'] ?? null,
        'error' => $account['sync_error'] ?? null,
        'lastSyncedAt' => $account['last_synced_at'] ?? null,
    ];
}

function editorUsers(): array
{
    return DB::all("SELECT id, name FROM users WHERE active = 1 AND role IN ('admin', 'editor') ORDER BY name");
}

function oauthRedirectUri(string $key): string
{
    return cfg('appUrl') . "/channels/oauth/$key/callback";
}

function afterConnect(array $ch): void
{
    logActivity(user(), 'channel.connect', 'channel', null, $ch['name']);
    $r = Sync::sync($ch['key'], user());
    flash($r['ok'] ? 'success' : 'warn', $r['ok'] ? "Đã kết nối {$ch['name']} và lấy số liệu mới nhất." : "Đã kết nối {$ch['name']} nhưng chưa lấy được số liệu: {$r['error']}");
}

function connectFacebookPage(array $page): void
{
    Sync::saveToken('facebook', ['accessToken' => $page['accessToken']]);
    DB::run('UPDATE channel_accounts SET external_id = ?, display_name = ?, url = COALESCE(url, ?), updated_at = ? WHERE channel = ?', [$page['id'], $page['name'], $page['url'], D::now(), 'facebook']);
    afterConnect(C::CHANNELS['facebook']);
}

Router::get('/channels', function () {
    $since = D::addDays(D::today(), -30) . ' 00:00:00';
    $upcoming = (int) DB::value("SELECT COUNT(*) FROM posts WHERE status IN ('pending', 'scheduled') AND scheduled_at >= ?", [D::now()]);
    $rows = [];
    foreach (C::CHANNELS as $ch) {
        $account = channelAccount($ch['key']);
        $stats = DB::all('SELECT * FROM channel_stats WHERE channel = ? ORDER BY recorded_on DESC, id DESC LIMIT 60', [$ch['key']]);
        $metrics = metricsFor($ch['key']);
        $primary = summarizeMetric($stats, $metrics[0]);
        $reviews = null;
        $chain = null;
        if ($ch['key'] === 'google') {
            // Google Maps: điểm trung bình cả chuỗi theo số đánh giá từng chi nhánh
            $chain = Branches::chainRating(Branches::googleSnapshot());
            $primary = $metrics[0] + ['value' => $chain['rating'], 'delta' => null, 'prevOn' => null];
            $reviews = $metrics[1] + ['value' => $chain['reviews']];
        }
        $perf = DB::get(
            "SELECT COUNT(*) AS posts, COALESCE(SUM(pc.views), 0) AS views,
                    COALESCE(SUM(COALESCE(pc.likes, 0) + COALESCE(pc.comments, 0) + COALESCE(pc.shares, 0)), 0) AS interactions
             FROM posts p JOIN post_channels pc ON pc.post_id = p.id AND pc.channel = ?
             WHERE p.status = 'published' AND p.published_at >= ?",
            [$ch['key'], $since]
        );
        $rows[] = ['ch' => $ch, 'account' => $account, 'primary' => $primary, 'reviews' => $reviews, 'chain' => $chain, 'perf' => $perf, 'upcoming' => $upcoming, 'conn' => connectionInfo($ch, $account)];
    }
    render('channels/index', ['title' => 'Kênh mạng xã hội', 'rows' => $rows]);
});

/* ---------- Kết nối OAuth (Facebook, TikTok, Zalo OA) ---------- */

Router::get('/channels/oauth/{channel}/callback', function ($channel) {
    Auth::requirePerm('channel:edit');
    $ch = channelOr404($channel);
    $conn = Sync::conn($ch['key']);
    $saved = Session::pull('oauth');
    $back = "/channels/{$ch['key']}";
    if (q('error') || q('error_description')) {
        flash('error', "Chưa kết nối được {$ch['name']}: " . str(q('error_description') ?: q('error'), 300) . '.');
        redirect($back);
    }
    if (!$saved || $saved['channel'] !== $ch['key'] || !hash_equals((string) $saved['state'], (string) q('state')) || !q('code')) {
        flash('error', 'Phiên kết nối đã hết hạn. Bấm Kết nối lại.');
        redirect($back);
    }
    try {
        if ($ch['key'] === 'facebook') {
            $pages = $conn::exchange((string) q('code'), oauthRedirectUri('facebook'));
            if (!$pages) throw new UserError('Tài khoản Facebook này không quản lý Page nào.');
            if (count($pages) === 1) {
                connectFacebookPage($pages[0]);
                redirect($back);
            }
            // Danh sách Page chờ chọn, giữ 10 phút (token quá dài để để trong cookie)
            $k = bin2hex(random_bytes(12));
            Cache::set("fbpages:$k", ['pages' => array_map(fn($p) => ['accessToken' => Secret::encrypt($p['accessToken'])] + $p, $pages), 'userId' => user()['id']], 600);
            redirect("/channels/facebook/choose-page?k=$k");
        }
        $t = $conn::exchange((string) q('code'), oauthRedirectUri($ch['key']), $saved['verifier'] ?? null);
        Sync::saveToken($ch['key'], $t);
        DB::run('UPDATE channel_accounts SET external_id = ?, updated_at = ? WHERE channel = ?', [$t['openId'] ?? 'connected', D::now(), $ch['key']]);
        afterConnect($ch);
    } catch (UserError | ConnectorError $e) {
        flash('error', "Chưa kết nối được {$ch['name']}: " . $e->getMessage());
    }
    redirect($back);
});

function pendingPages($k): ?array
{
    $p = Cache::get('fbpages:' . preg_replace('/[^a-f0-9]/', '', (string) $k));
    if (!$p || (int) $p['userId'] !== user()['id']) return null;
    return $p;
}

Router::get('/channels/facebook/choose-page', function () {
    Auth::requirePerm('channel:edit');
    $p = pendingPages(q('k'));
    if (!$p) {
        flash('error', 'Danh sách Page đã hết hạn. Bấm Kết nối lại.');
        redirect('/channels/facebook');
    }
    render('channels/choose-page', ['title' => 'Chọn Page Facebook', 'pages' => $p['pages'], 'k' => q('k')]);
});

Router::post('/channels/facebook/choose-page', function () {
    Auth::requirePerm('channel:edit');
    $p = pendingPages(body('k'));
    Cache::delete('fbpages:' . preg_replace('/[^a-f0-9]/', '', (string) body('k')));
    $page = null;
    foreach ($p['pages'] ?? [] as $x) if ($x['id'] === body('page_id')) $page = $x;
    if (!$page) {
        flash('error', 'Không tìm thấy Page đã chọn. Bấm Kết nối lại.');
        redirect('/channels/facebook');
    }
    $page['accessToken'] = Secret::decrypt($page['accessToken']);
    connectFacebookPage($page);
    redirect('/channels/facebook');
});

Router::post('/channels/{channel}/connect', function ($channel) {
    Auth::requirePerm('channel:edit');
    $ch = channelOr404($channel);
    $conn = Sync::conn($ch['key']);
    if (!$conn::OAUTH) redirect("/channels/{$ch['key']}");
    if (!$conn::isConfigured()) {
        flash('error', 'Máy chủ chưa có ' . implode(', ', REQUIRED_ENV[$ch['key']]) . '. Xem hướng dẫn trong DEPLOY.md.');
        redirect("/channels/{$ch['key']}");
    }
    $state = bin2hex(random_bytes(16));
    $pk = method_exists($conn, 'pkce') ? $conn::pkce() : null;
    Session::set('oauth', ['channel' => $ch['key'], 'state' => $state, 'verifier' => $pk['verifier'] ?? null]);
    redirect($conn::authUrl(oauthRedirectUri($ch['key']), $state, $pk['challenge'] ?? null));
});

/* ---------- Google Maps: dán link ---------- */

Router::post('/channels/google/link', function () {
    Auth::requirePerm('channel:edit');
    $link = str(body('link'), 500);
    try {
        $placeId = GoogleConnector::resolveLink($link);
        // Link gắn với chi nhánh được chọn (mặc định chi nhánh đầu tiên)
        $list = Branches::list();
        $branch = current(array_filter($list, fn($b) => $b['id'] === int(body('branch_id')))) ?: ($list[0] ?? null);
        if (!$branch) throw new UserError('Chưa có chi nhánh nào. Thêm chi nhánh trong mục Thông tin khách sạn.');
        Branches::setPlace($branch['id'], $placeId, $link);
        Sync::markGoogleConnected();
        afterConnect(C::CHANNELS['google']);
    } catch (UserError | ConnectorError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/channels/google');
});

Router::post('/channels/{channel}/sync', function ($channel) {
    Auth::requirePerm('channel:edit');
    $ch = channelOr404($channel);
    $r = Sync::sync($ch['key'], user());
    if ($r['ok']) logActivity(user(), 'channel.sync', 'channel', null, $ch['name']);
    if (!empty($r['partial'])) flash('warn', "Đã cập nhật {$r['synced']} chi nhánh. Chưa cập nhật được: {$r['error']}");
    elseif ($r['ok']) flash('success', "Đã cập nhật số liệu {$ch['name']}" . (!empty($r['matched']) ? ", khớp {$r['matched']} bài đã đăng" : '') . '.');
    else flash('error', "Không cập nhật được: {$r['error']}");
    redirect("/channels/{$ch['key']}");
});

Router::post('/channels/{channel}/disconnect', function ($channel) {
    Auth::requirePerm('channel:edit');
    $ch = channelOr404($channel);
    Sync::disconnect($ch['key']);
    logActivity(user(), 'channel.disconnect', 'channel', null, $ch['name']);
    flash('success', "Đã ngắt kết nối {$ch['name']}. Số liệu cũ vẫn được giữ.");
    redirect("/channels/{$ch['key']}");
});

Router::post('/channels/{channel}/analyze', function ($channel) {
    Auth::requirePerm('channel:edit');
    $ch = channelOr404($channel);
    try {
        AI::analyze($ch['key'], user());
        logActivity(user(), 'channel.analyze', 'channel', null, $ch['name']);
        flash('success', 'AI đã phân tích xong.');
    } catch (UserError $e) {
        flash('error', 'Chưa phân tích được: ' . $e->getMessage());
    }
    redirect("/channels/{$ch['key']}#ai");
});

/* ---------- Google Maps theo chi nhánh ---------- */

Router::get('/channels/google', function () {
    $ch = C::CHANNELS['google'];
    $account = channelAccount('google');
    $snap = Branches::googleSnapshot();
    render('channels/google', [
        'title' => $ch['name'],
        'ch' => $ch,
        'account' => $account,
        'snap' => $snap,
        'chain' => Branches::chainRating($snap),
        'comparison' => Competitors::comparison(),
        'conn' => connectionInfo($ch, $account),
        'insight' => AI::latest('google'),
        'aiConfigured' => AI::isConfigured(),
        'users' => editorUsers(),
    ]);
});

Router::get('/channels/google/branch/{id}', function ($id) {
    $b = Branches::get($id);
    if (!$b) abort(404, 'Không có chi nhánh này.');
    $stats = DB::all("SELECT * FROM channel_stats WHERE channel = 'google' AND branch_id = ? ORDER BY recorded_on DESC, id DESC LIMIT 60", [$b['id']]);
    $metrics = array_map(fn($m) => summarizeMetric($stats, $m), CHANNEL_METRICS['google']);
    [$chartMetric, $series] = chartSeries($stats, CHANNEL_METRICS['google']);
    render('channels/branch', [
        'title' => "{$b['name']} trên Google Maps",
        'ch' => C::CHANNELS['google'],
        'b' => $b,
        'extra' => Branches::parseExtra($b),
        'stats' => $stats,
        'metrics' => $metrics,
        'chartMetric' => $chartMetric,
        'series' => $series,
    ]);
});

Router::post('/channels/google/competitors', function () {
    Auth::requirePerm('channel:edit');
    try {
        $c = Competitors::add(body('branch_id'), str(body('link'), 500));
        logActivity(user(), 'competitor.add', 'competitor', $c['id'], $c['name']);
        if ($c['sync_status'] === 'ok') flash('success', "Đã thêm đối thủ {$c['name']}.");
        else flash('warn', "Đã thêm đối thủ nhưng chưa lấy được số liệu: {$c['sync_error']}");
    } catch (UserError | ConnectorError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/channels/google#doi-thu');
});

Router::post('/channels/google/competitors/{id}/delete', function ($id) {
    Auth::requirePerm('channel:edit');
    Competitors::remove($id);
    flash('success', 'Đã bỏ theo dõi đối thủ. Số liệu cũ vẫn được giữ.');
    redirect('/channels/google#doi-thu');
});

Router::post('/channels/google/branch/{id}/clear', function ($id) {
    Auth::requirePerm('channel:edit');
    if ($b = Branches::get($id)) {
        Branches::setPlace($b['id'], null, null);
        Sync::markGoogleConnected();
        flash('success', "Đã bỏ theo dõi Google Maps của {$b['name']}. Số liệu cũ vẫn được giữ.");
    }
    redirect('/channels/google');
});

/* ---------- Trang chi tiết kênh ---------- */

Router::get('/channels/{channel}', function ($channel) {
    $ch = channelOr404($channel);
    $account = channelAccount($ch['key']);
    $stats = DB::all('SELECT s.*, u.name AS user_name FROM channel_stats s LEFT JOIN users u ON u.id = s.created_by WHERE s.channel = ? ORDER BY s.recorded_on DESC, s.id DESC LIMIT 60', [$ch['key']]);
    $metrics = array_map(fn($m) => summarizeMetric($stats, $m), metricsFor($ch['key']));
    [$chartMetric, $series] = chartSeries($stats, metricsFor($ch['key']));
    $perf = array_map('intval', DB::get(
        "SELECT COUNT(*) AS posts, COALESCE(SUM(pc.views), 0) AS views,
                COALESCE(SUM(pc.likes), 0) AS likes, COALESCE(SUM(pc.comments), 0) AS comments, COALESCE(SUM(pc.shares), 0) AS shares
         FROM posts p JOIN post_channels pc ON pc.post_id = p.id AND pc.channel = ?
         WHERE p.status = 'published' AND p.published_at >= ?",
        [$ch['key'], D::addDays(D::today(), -30) . ' 00:00:00']
    ));
    render('channels/show', [
        'title' => $ch['name'],
        'ch' => $ch,
        'account' => $account,
        'extra' => json_decode_array($account['extra'] ?? null),
        'conn' => connectionInfo($ch, $account),
        'oauthCallback' => oauthRedirectUri($ch['key']),
        'insight' => AI::latest($ch['key']),
        'aiConfigured' => AI::isConfigured(),
        'stats' => $stats,
        'metrics' => $metrics,
        'chartMetric' => $chartMetric,
        'series' => $series,
        'perf' => $perf,
        'upcoming' => DB::all("SELECT id, title, status, scheduled_at FROM posts WHERE status IN ('draft', 'pending', 'scheduled') AND scheduled_at >= ? ORDER BY scheduled_at LIMIT 8", [D::today() . ' 00:00:00']),
        'posts' => DB::all(
            "SELECT p.id, p.title, p.published_at, pc.published_url, pc.views, pc.likes, pc.comments, pc.shares
             FROM posts p JOIN post_channels pc ON pc.post_id = p.id AND pc.channel = ?
             WHERE p.status = 'published' ORDER BY p.published_at DESC LIMIT 10",
            [$ch['key']]
        ),
        'users' => editorUsers(),
        'today' => D::today(),
    ]);
});

Router::post('/channels/{channel}/account', function ($channel) {
    Auth::requirePerm('channel:edit');
    $ch = channelOr404($channel);
    DB::run('UPDATE channel_accounts SET display_name = ?, handle = ?, url = ?, owner_id = ?, notes = ?, updated_at = ? WHERE channel = ?', [
        str(body('display_name'), 150), str(body('handle'), 150), safeUrl(body('url')) ?: null, int(body('owner_id')) ?: null, str(body('notes'), 5000), D::now(), $ch['key'],
    ]);
    logActivity(user(), 'channel.update', 'channel', null, $ch['name']);
    flash('success', "Đã lưu thông tin kênh {$ch['name']}.");
    redirect("/channels/{$ch['key']}");
});

// Nhập tay: chỉ dùng khi kênh chưa kết nối được
Router::post('/channels/{channel}/stats', function ($channel) {
    Auth::requirePerm('channel:edit');
    $ch = channelOr404($channel);
    $day = D::isDate(body('recorded_on')) ? body('recorded_on') : D::today();
    $n = fn($v) => trim((string) ($v ?? '')) === '' ? null : max(0, int($v));
    $ratingRaw = trim(str_replace(',', '.', (string) body('rating', '')));
    $rating = $ratingRaw === '' ? null : min(5, max(0, (float) $ratingRaw));
    $isG = $ch['key'] === 'google';
    DB::run(
        "INSERT INTO channel_stats (channel, recorded_on, followers, reach, engagement, rating, reviews, note, source, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?, ?)",
        [$ch['key'], $day, $n(body('followers')), $n(body('reach')), $n(body('engagement')), $isG ? $rating : null, $isG ? $n(body('reviews')) : null, str(body('note'), 255), user()['id'], D::now()]
    );
    logActivity(user(), 'channel.stats', 'channel', null, "{$ch['name']}: số liệu ngày " . D::dmy($day));
    flash('success', 'Đã ghi số liệu.');
    redirect("/channels/{$ch['key']}");
});

Router::post('/channels/{channel}/stats/{id}/delete', function ($channel, $id) {
    Auth::requirePerm('channel:edit');
    $ch = channelOr404($channel);
    DB::run('DELETE FROM channel_stats WHERE id = ? AND channel = ?', [int($id), $ch['key']]);
    flash('success', 'Đã xoá dòng số liệu.');
    redirect("/channels/{$ch['key']}");
});
