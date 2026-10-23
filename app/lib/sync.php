<?php
// Tự động cập nhật số liệu kênh: gọi API từng kênh, ghi một dòng số liệu mỗi ngày,
// cập nhật lượt thích/bình luận/chia sẻ cho các bài đã đăng có link khớp.

final class Sync
{
    const SYNC_EVERY_HOURS = 12;
    const AI_EVERY_DAYS = 7;

    // Tên lớp kết nối của từng kênh
    const CONNECTORS = [
        'google' => GoogleConnector::class,
        'facebook' => FacebookConnector::class,
        'tiktok' => TiktokConnector::class,
        'zalo' => ZaloConnector::class,
    ];

    public static function conn(string $channel): string
    {
        return self::CONNECTORS[$channel];
    }

    public static function getToken(string $channel): ?array
    {
        $row = DB::get('SELECT * FROM channel_tokens WHERE channel = ?', [$channel]);
        if (!$row) return null;
        return ['access_token' => Secret::decrypt($row['access_token']), 'refresh_token' => Secret::decrypt($row['refresh_token'])] + $row;
    }

    public static function saveToken(string $channel, array $t): void
    {
        $now = D::now();
        $values = [
            Secret::encrypt($t['accessToken'] ?? null),
            Secret::encrypt($t['refreshToken'] ?? null),
            !empty($t['expiresIn']) ? D::addSeconds($now, $t['expiresIn']) : null,
            !empty($t['refreshExpiresIn']) ? D::addSeconds($now, $t['refreshExpiresIn']) : null,
            $now,
            $channel,
        ];
        if (DB::get('SELECT channel FROM channel_tokens WHERE channel = ?', [$channel])) {
            DB::run('UPDATE channel_tokens SET access_token = ?, refresh_token = ?, expires_at = ?, refresh_expires_at = ?, updated_at = ? WHERE channel = ?', $values);
        } else {
            DB::run('INSERT INTO channel_tokens (access_token, refresh_token, expires_at, refresh_expires_at, updated_at, channel) VALUES (?, ?, ?, ?, ?, ?)', $values);
        }
    }

    public static function disconnect(string $channel): void
    {
        DB::run('DELETE FROM channel_tokens WHERE channel = ?', [$channel]);
        DB::run('UPDATE channel_accounts SET external_id = NULL, sync_status = NULL, sync_error = NULL, last_synced_at = NULL, extra = NULL, updated_at = ? WHERE channel = ?', [D::now(), $channel]);
        if ($channel === 'google') DB::run('UPDATE branches SET place_id = NULL, sync_status = NULL, sync_error = NULL');
    }

    // Google Maps "đã kết nối" khi ít nhất một chi nhánh có địa điểm
    public static function markGoogleConnected(): void
    {
        $n = (int) DB::value('SELECT COUNT(*) FROM branches WHERE place_id IS NOT NULL AND active = 1');
        DB::run("UPDATE channel_accounts SET external_id = ? WHERE channel = 'google'", [$n ? 'branches' : null]);
    }

    public static function isConnected(array $account, ?array $token): bool
    {
        if ($account['channel'] === 'google') return !empty($account['external_id']);
        return !empty($token['access_token']) && !empty($account['external_id']);
    }

    // Làm mới access token khi còn dưới 30 phút
    public static function freshToken(string $channel): ?array
    {
        $conn = self::conn($channel);
        $token = self::getToken($channel);
        if (!$token || !method_exists($conn, 'refresh') || !$token['refresh_token']) return $token;
        if ($token['expires_at'] && $token['expires_at'] > D::addSeconds(D::now(), 1800)) return $token;
        $t = $conn::refresh($token['refresh_token']);
        self::saveToken($channel, ['refreshToken' => $t['refreshToken'] ?: $token['refresh_token']] + $t);
        return self::getToken($channel);
    }

    private static function normalizeUrl(?string $u): string
    {
        return rtrim(explode('?', (string) $u)[0], '/');
    }

    private static function updatePostMetrics(string $channel, array $items): int
    {
        $rows = DB::all(
            "SELECT pc.post_id, pc.published_url FROM post_channels pc JOIN posts p ON p.id = pc.post_id
             WHERE pc.channel = ? AND p.status = 'published' AND pc.published_url IS NOT NULL",
            [$channel]
        );
        $byUrl = [];
        foreach ($rows as $r) $byUrl[self::normalizeUrl($r['published_url'])] = (int) $r['post_id'];
        $matched = 0;
        foreach ($items as $it) {
            $postId = $byUrl[self::normalizeUrl($it['url'] ?? '')] ?? null;
            if (!$postId) continue;
            DB::run('UPDATE post_channels SET views = COALESCE(?, views), likes = ?, comments = ?, shares = ?, updated_at = ? WHERE post_id = ? AND channel = ?', [
                $it['views'], $it['likes'], $it['comments'], $it['shares'], D::now(), $postId, $channel,
            ]);
            PostChannels::recalcTotals($postId);
            $matched++;
        }
        return $matched;
    }

    // Google Maps: mỗi chi nhánh một địa điểm, ghi số liệu riêng theo branch_id
    private static function syncGoogle(?array $user = null): array
    {
        $now = D::now();
        $today = D::today();
        $list = array_filter(Branches::list(), fn($b) => $b['place_id']);
        $errors = [];
        $ok = 0;
        if (!GoogleConnector::isConfigured()) $errors[] = 'Kênh này chưa được cấu hình khoá API trên máy chủ.';
        elseif (!$list) $errors[] = 'Chưa có chi nhánh nào được dán link Google Maps.';
        else {
            foreach ($list as $b) {
                try {
                    $s = GoogleConnector::fetchStats(['external_id' => $b['place_id']]);
                    $existing = DB::get("SELECT id FROM channel_stats WHERE channel = 'google' AND branch_id = ? AND recorded_on = ? AND source = 'auto'", [$b['id'], $today]);
                    if ($existing) {
                        DB::run('UPDATE channel_stats SET rating = ?, reviews = ?, created_at = ? WHERE id = ?', [$s['rating'], $s['reviews'], $now, $existing['id']]);
                    } else {
                        DB::run("INSERT INTO channel_stats (channel, branch_id, recorded_on, rating, reviews, source, created_by, created_at) VALUES ('google', ?, ?, ?, ?, 'auto', ?, ?)", [
                            $b['id'], $today, $s['rating'], $s['reviews'], $user['id'] ?? null, $now,
                        ]);
                    }
                    $extra = ['profile' => $s['profile'] ?? [], 'recentReviews' => $s['recentReviews'] ?? []];
                    DB::run("UPDATE branches SET sync_status = 'ok', sync_error = NULL, last_synced_at = ?, extra = ? WHERE id = ?", [$now, json_encode($extra, JSON_UNESCAPED_UNICODE), $b['id']]);
                    $ok++;
                } catch (Throwable $e) {
                    $msg = mb_substr($e->getMessage(), 0, 500);
                    DB::run("UPDATE branches SET sync_status = 'error', sync_error = ?, last_synced_at = ? WHERE id = ?", [$msg, $now, $b['id']]);
                    $errors[] = "{$b['name']}: $msg";
                }
            }
        }
        // Đối thủ trên Google Maps cập nhật cùng lúc với các chi nhánh
        if (GoogleConnector::isConfigured()) Competitors::syncAll();
        DB::run("UPDATE channel_accounts SET sync_status = ?, sync_error = ?, last_synced_at = ? WHERE channel = 'google'", [
            $errors ? ($ok ? 'warn' : 'error') : 'ok',
            mb_substr(implode('; ', $errors), 0, 500) ?: null,
            $now,
        ]);
        return ['ok' => $ok > 0 && !$errors, 'partial' => $ok > 0 && $errors, 'error' => implode('; ', $errors), 'synced' => $ok, 'matched' => 0];
    }

    public static function sync(string $channel, ?array $user = null): array
    {
        if ($channel === 'google') return self::syncGoogle($user);
        $account = DB::get('SELECT * FROM channel_accounts WHERE channel = ?', [$channel]);
        if (!isset(self::CONNECTORS[$channel]) || !$account) return ['ok' => false, 'error' => 'Không có kênh này.'];
        $conn = self::conn($channel);
        $now = D::now();
        try {
            if (!$conn::isConfigured()) throw new ConnectorError('Kênh này chưa được cấu hình khoá API trên máy chủ.');
            $token = self::freshToken($channel);
            if (!self::isConnected($account, $token)) throw new ConnectorError('Kênh chưa được kết nối.', true);
            $s = $conn::fetchStats($account, $token);

            $today = D::today();
            $row = [$s['followers'] ?? null, $s['reach'] ?? null, $s['engagement'] ?? null, $s['rating'] ?? null, $s['reviews'] ?? null];
            $existing = DB::get("SELECT id FROM channel_stats WHERE channel = ? AND recorded_on = ? AND source = 'auto'", [$channel, $today]);
            if ($existing) {
                DB::run('UPDATE channel_stats SET followers = ?, reach = ?, engagement = ?, rating = ?, reviews = ?, created_at = ? WHERE id = ?', [...$row, $now, $existing['id']]);
            } else {
                DB::run("INSERT INTO channel_stats (channel, recorded_on, followers, reach, engagement, rating, reviews, source, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'auto', ?, ?)", [
                    $channel, $today, ...$row, $user['id'] ?? null, $now,
                ]);
            }

            $matched = self::updatePostMetrics($channel, $s['posts'] ?? []);
            $extra = ['profile' => $s['profile'] ?? [], 'recentReviews' => $s['recentReviews'] ?? [], 'posts' => array_slice($s['posts'] ?? [], 0, 20), 'matched' => $matched];
            DB::run(
                "UPDATE channel_accounts SET sync_status = 'ok', sync_error = NULL, last_synced_at = ?, extra = ?,
                   handle = COALESCE(handle, ?), url = COALESCE(url, ?), updated_at = ? WHERE channel = ?",
                [$now, json_encode($extra, JSON_UNESCAPED_UNICODE), $s['profile']['handle'] ?? $s['profile']['name'] ?? null, $s['profile']['url'] ?? null, $now, $channel]
            );
            return ['ok' => true, 'matched' => $matched];
        } catch (Throwable $e) {
            $status = $e instanceof ConnectorError && $e->needsReconnect ? 'reconnect' : 'error';
            DB::run('UPDATE channel_accounts SET sync_status = ?, sync_error = ?, last_synced_at = ? WHERE channel = ?', [$status, mb_substr($e->getMessage(), 0, 500), $now, $channel]);
            return ['ok' => false, 'error' => $e->getMessage(), 'status' => $status];
        }
    }

    // Việc nền: cập nhật kênh đã kết nối mỗi 12 giờ, phân tích AI mỗi 7 ngày
    public static function tick(): void
    {
        if (!Cache::lock('sync', 1800)) return;
        try {
            $threshold = D::addSeconds(D::now(), -self::SYNC_EVERY_HOURS * 3600);
            foreach (DB::all('SELECT * FROM channel_accounts') as $account) {
                $ch = $account['channel'];
                if (!isset(self::CONNECTORS[$ch])) continue;
                $conn = self::conn($ch);
                $token = $conn::OAUTH ? self::getToken($ch) : null;
                if (!self::isConnected($account, $token)) continue;
                if (!$account['last_synced_at'] || $account['last_synced_at'] < $threshold) {
                    $r = self::sync($ch);
                    if ($r['ok']) logActivity(null, 'channel.sync', 'channel', null, "Tự động cập nhật $ch");
                }
                if (AI::isConfigured()) {
                    $last = DB::get('SELECT created_at FROM channel_insights WHERE channel = ? ORDER BY id DESC LIMIT 1', [$ch]);
                    if (!$last || $last['created_at'] < D::addSeconds(D::now(), -self::AI_EVERY_DAYS * 86400)) {
                        try {
                            AI::analyze($ch);
                        } catch (Throwable $e) {
                            error_log("[ai] $ch: " . $e->getMessage());
                        }
                    }
                }
            }
        } finally {
            Cache::unlock('sync');
        }
    }
}
