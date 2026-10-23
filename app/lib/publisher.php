<?php
// Tự đăng bài đã duyệt lên Facebook và TikTok khi đến giờ.
// Google Maps và Zalo OA vẫn đăng tay (cần quyền API riêng chưa hỗ trợ).

final class Publisher
{
    const AUTO = ['facebook', 'tiktok'];

    // Link ảnh công khai có chữ ký, hết hạn sau 24 giờ (TikTok tự tải ảnh về từ link này)
    private static function sign($id, $exp): string
    {
        return substr(hash_hmac('sha256', "media:$id:$exp", (string) cfg('encryptionKey')), 0, 32);
    }

    public static function publicMediaUrl(int $id): string
    {
        $exp = time() + 24 * 3600;
        return cfg('appUrl') . "/m/$id/$exp/" . self::sign($id, $exp);
    }

    public static function verifyMedia($id, $exp, $sig): bool
    {
        if ((int) $exp < time()) return false;
        return hash_equals(self::sign($id, $exp), (string) $sig);
    }

    private static function message(array $post, string $channel): string
    {
        $text = implode("\n\n", array_filter([$post['caption'], $post['hashtags']], fn($s) => trim((string) $s) !== ''));
        return Offers::applyCode($text, Offers::codeFor($post['offer_id'], $channel));
    }

    private static function files(int $postId): array
    {
        return array_map(fn($m) => [
            'id' => (int) $m['id'],
            'path' => Upload::fullPath($m['path']),
            'mime' => $m['mime'],
            'isVideo' => Media::isVideo($m),
            'publicUrl' => self::publicMediaUrl((int) $m['id']),
        ], Media::list($postId));
    }

    // Khi cả 4 kênh đều có link (tự đăng hoặc dán tay) thì bài chuyển sang Đã đăng
    private static function maybeComplete(int $postId): void
    {
        $rows = PostChannels::list($postId);
        if (count(array_filter($rows, fn($r) => !empty($r['published_url']) || ($r['publish_status'] ?? '') === 'done')) === count($rows)) {
            $res = DB::run("UPDATE posts SET status = 'published', published_at = COALESCE(published_at, ?), updated_at = ? WHERE id = ? AND status = 'scheduled'", [D::now(), D::now(), $postId]);
            if ($res['changes']) logActivity(null, 'post.publish', 'post', $postId, 'Tự đăng xong trên tất cả các kênh');
        }
        PostChannels::recalcTotals($postId);
    }

    private static function publishChannel(array $post, string $channel): array
    {
        $conn = Sync::conn($channel);
        $account = DB::get('SELECT * FROM channel_accounts WHERE channel = ?', [$channel]);
        try {
            $token = Sync::freshToken($channel);
        } catch (Throwable $e) {
            $token = null;
        }
        if (!$conn::isConfigured() || !Sync::isConnected($account, $token)) return ['skipped' => true];

        // Giành quyền đăng: chỉ một tiến trình được đăng một bài lên một kênh
        PostChannels::ensure((int) $post['id']);
        $claim = DB::run(
            "UPDATE post_channels SET publish_status = 'publishing', publish_error = NULL, updated_at = ?
             WHERE post_id = ? AND channel = ? AND publish_status IS NULL AND published_url IS NULL",
            [D::now(), $post['id'], $channel]
        );
        if (!$claim['changes']) return ['skipped' => true];

        try {
            $out = $conn::publish($account, $token, self::message($post, $channel), self::files((int) $post['id']));
            if (!empty($out['pending'])) {
                PostChannels::update((int) $post['id'], $channel, ['publish_status' => 'processing:' . $out['kind'], 'external_id' => $out['externalId']]);
            } else {
                PostChannels::update((int) $post['id'], $channel, ['publish_status' => 'done', 'published_url' => $out['url'], 'external_id' => $out['externalId'], 'publish_error' => $out['note'] ?? null]);
                logActivity(null, 'post.autopublish', 'post', $post['id'], C::CHANNELS[$channel]['name'] . ': ' . $post['title']);
            }
            return ['ok' => true];
        } catch (Throwable $e) {
            PostChannels::update((int) $post['id'], $channel, ['publish_status' => 'failed', 'publish_error' => mb_substr($e->getMessage(), 0, 500)]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function publishPost(int $postId): array
    {
        @set_time_limit(900);
        $post = DB::get('SELECT * FROM posts WHERE id = ?', [$postId]);
        if (!$post || $post['status'] !== 'scheduled') return [];
        $results = [];
        foreach (self::AUTO as $ch) $results[] = ['channel' => $ch] + self::publishChannel($post, $ch);
        self::maybeComplete($postId);
        return $results;
    }

    // TikTok xử lý bài bất đồng bộ: hỏi lại trạng thái cho tới khi xong
    private static function checkProcessing(): void
    {
        foreach (DB::all("SELECT * FROM post_channels WHERE publish_status LIKE 'processing:%'") as $r) {
            $conn = Sync::conn($r['channel']);
            if (!method_exists($conn, 'publishStatus')) continue;
            try {
                $account = DB::get('SELECT * FROM channel_accounts WHERE channel = ?', [$r['channel']]);
                $token = Sync::freshToken($r['channel']);
                $st = $conn::publishStatus($account, $token, $r['external_id'], explode(':', $r['publish_status'])[1]);
                $pid = (int) $r['post_id'];
                if (!empty($st['failed'])) PostChannels::update($pid, $r['channel'], ['publish_status' => 'failed', 'publish_error' => $st['error']]);
                elseif (!empty($st['done'])) {
                    PostChannels::update($pid, $r['channel'], ['publish_status' => 'done', 'published_url' => $st['url'], 'publish_error' => $st['note']]);
                    $post = DB::get('SELECT title FROM posts WHERE id = ?', [$pid]);
                    logActivity(null, 'post.autopublish', 'post', $pid, C::CHANNELS[$r['channel']]['name'] . ': ' . ($post['title'] ?? ''));
                    self::maybeComplete($pid);
                }
            } catch (Throwable $e) {
                error_log("[publish] Lỗi khi kiểm tra trạng thái {$r['channel']} {$r['post_id']}: " . $e->getMessage());
            }
        }
    }

    // Việc nền mỗi phút: đăng các bài đến giờ, hỏi trạng thái bài TikTok đang xử lý
    public static function tick(): void
    {
        if (!Cache::lock('publish', 900)) return;
        try {
            $due = DB::all("SELECT id FROM posts WHERE status = 'scheduled' AND auto_publish = 1 AND scheduled_at IS NOT NULL AND scheduled_at <= ? ORDER BY scheduled_at LIMIT 20", [D::now()]);
            foreach ($due as $p) self::publishPost((int) $p['id']);
            self::checkProcessing();
        } finally {
            Cache::unlock('publish');
        }
    }

    // Cho phép thử lại một kênh bị lỗi
    public static function retry(int $postId, string $channel): array
    {
        DB::run("UPDATE post_channels SET publish_status = NULL, publish_error = NULL WHERE post_id = ? AND channel = ? AND publish_status = 'failed'", [$postId, $channel]);
        return self::publishPost($postId);
    }
}
