<?php
// TikTok qua TikTok for Developers (Login Kit + Display API).
// Access token sống 24 giờ, refresh token 365 ngày; hệ thống tự làm mới.

final class TiktokConnector
{
    const KEY = 'tiktok';
    const OAUTH = true;
    // video.publish: để tự đăng video và ảnh lên TikTok
    const SCOPES = ['user.info.basic', 'user.info.profile', 'user.info.stats', 'video.list', 'video.publish'];
    const API = 'https://open.tiktokapis.com/v2';

    public static function isConfigured(): bool
    {
        return cfg('tiktok.clientKey') !== '' && cfg('tiktok.clientSecret') !== '';
    }

    public static function authUrl(string $redirectUri, string $state, ?string $challenge = null): string
    {
        return 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query([
            'client_key' => cfg('tiktok.clientKey'), 'scope' => implode(',', self::SCOPES), 'response_type' => 'code', 'redirect_uri' => $redirectUri, 'state' => $state,
        ]);
    }

    private static function tokenResult(?array $d): array
    {
        if (empty($d['access_token'])) throw new ConnectorError($d['error_description'] ?? 'TikTok không trả về token.', true);
        return [
            'accessToken' => $d['access_token'],
            'refreshToken' => $d['refresh_token'] ?? null,
            'expiresIn' => $d['expires_in'] ?? null,
            'refreshExpiresIn' => $d['refresh_expires_in'] ?? null,
            'openId' => $d['open_id'] ?? null,
        ];
    }

    public static function exchange(string $code, string $redirectUri, ?string $verifier = null): array
    {
        $res = Http::request(self::API . '/oauth/token/', ['method' => 'POST', 'form' => [
            'client_key' => cfg('tiktok.clientKey'), 'client_secret' => cfg('tiktok.clientSecret'), 'code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => $redirectUri,
        ]]);
        return self::tokenResult($res['data']);
    }

    public static function refresh(string $refreshToken): array
    {
        $res = Http::request(self::API . '/oauth/token/', ['method' => 'POST', 'form' => [
            'client_key' => cfg('tiktok.clientKey'), 'client_secret' => cfg('tiktok.clientSecret'), 'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken,
        ]]);
        return self::tokenResult($res['data']);
    }

    private static function check(array $res): array
    {
        $code = $res['data']['error']['code'] ?? null;
        if ($res['ok'] && (!$code || $code === 'ok')) return $res['data']['data'] ?? [];
        $msg = $res['data']['error']['message'] ?? "TikTok trả về lỗi {$res['status']}";
        throw new ConnectorError($msg, $code === 'access_token_invalid' || $res['status'] === 401);
    }

    private static function auth(array $token): array
    {
        return ['Authorization' => 'Bearer ' . $token['access_token']];
    }

    public static function fetchStats(array $account, ?array $token): array
    {
        if (empty($token['access_token'])) throw new ConnectorError('Chưa kết nối TikTok.', true);
        $auth = self::auth($token);
        $user = self::check(Http::request(self::API . '/user/info/?fields=open_id,display_name,username,profile_deep_link,follower_count,likes_count,video_count', ['headers' => $auth]))['user'] ?? [];
        $list = self::check(Http::request(self::API . '/video/list/?fields=id,title,video_description,share_url,create_time,view_count,like_count,comment_count,share_count', [
            'method' => 'POST', 'headers' => $auth, 'json' => ['max_count' => 20],
        ]));
        $since = time() - 28 * 86400;
        $videos = array_map(fn($v) => [
            'url' => $v['share_url'] ?? null,
            'title' => mb_substr((string) ($v['title'] ?? $v['video_description'] ?? ''), 0, 120),
            'createdAt' => !empty($v['create_time']) ? gmdate('c', (int) $v['create_time']) : null,
            'recent' => ($v['create_time'] ?? 0) >= $since,
            'views' => (int) ($v['view_count'] ?? 0),
            'likes' => (int) ($v['like_count'] ?? 0),
            'comments' => (int) ($v['comment_count'] ?? 0),
            'shares' => (int) ($v['share_count'] ?? 0),
        ], $list['videos'] ?? []);
        $recent = array_filter($videos, fn($v) => $v['recent']);
        return [
            'followers' => $user['follower_count'] ?? null,
            'reach' => array_sum(array_column($recent, 'views')),
            'engagement' => array_sum(array_map(fn($v) => $v['likes'] + $v['comments'] + $v['shares'], $recent)),
            'profile' => [
                'name' => $user['display_name'] ?? null,
                'handle' => !empty($user['username']) ? '@' . $user['username'] : null,
                'url' => $user['profile_deep_link'] ?? null,
                'likes' => $user['likes_count'] ?? null,
                'videos' => $user['video_count'] ?? null,
            ],
            'posts' => $videos,
        ];
    }

    /* ---------- Tự đăng bài (Content Posting API, đăng thẳng) ---------- */

    private static function privacyLevel(array $auth): string
    {
        $info = self::check(Http::request(self::API . '/post/publish/creator_info/query/', ['method' => 'POST', 'headers' => $auth, 'json' => new stdClass()]));
        $options = $info['privacy_level_options'] ?? [];
        // App chưa được TikTok duyệt chỉ đăng được ở chế độ riêng tư
        return in_array('PUBLIC_TO_EVERYONE', $options, true) ? 'PUBLIC_TO_EVERYONE' : ($options[0] ?? 'SELF_ONLY');
    }

    // files: [{ path, mime, isVideo, publicUrl }]; ảnh cần publicUrl trên tên miền đã xác minh với TikTok
    public static function publish(array $account, ?array $token, string $message, array $files): array
    {
        if (empty($token['access_token'])) throw new ConnectorError('Chưa kết nối TikTok.', true);
        $auth = self::auth($token);
        $video = current(array_filter($files, fn($x) => $x['isVideo'])) ?: null;
        $photos = array_values(array_filter($files, fn($x) => !$x['isVideo'] && preg_match('/jpe?g|webp/i', $x['mime'] ?: $x['path'])));
        if (!$video && !$photos) {
            throw new ConnectorError($files ? 'TikTok chỉ nhận ảnh JPG hoặc WebP, hoặc video.' : 'TikTok cần ít nhất một ảnh hoặc video.');
        }
        $privacy = self::privacyLevel($auth);
        if ($video) {
            $size = filesize($video['path']);
            $init = self::check(Http::request(self::API . '/post/publish/video/init/', ['method' => 'POST', 'headers' => $auth, 'json' => [
                'post_info' => ['title' => mb_substr($message, 0, 2200), 'privacy_level' => $privacy, 'disable_comment' => false, 'disable_duet' => false, 'disable_stitch' => false],
                'source_info' => ['source' => 'FILE_UPLOAD', 'video_size' => $size, 'chunk_size' => $size, 'total_chunk_count' => 1],
            ]]));
            try {
                $res = Http::request($init['upload_url'], [
                    'method' => 'PUT',
                    'upload' => $video['path'],
                    'timeout' => 600,
                    'headers' => ['Content-Type' => $video['mime'] ?: 'video/mp4', 'Content-Range' => 'bytes 0-' . ($size - 1) . "/$size"],
                ]);
            } catch (ConnectorError $e) {
                throw new ConnectorError('Không tải được video lên TikTok.');
            }
            if (!$res['ok']) throw new ConnectorError("Tải video lên TikTok lỗi {$res['status']}.");
            return ['pending' => true, 'externalId' => $init['publish_id'], 'privacy' => $privacy, 'kind' => 'video'];
        }
        $init = self::check(Http::request(self::API . '/post/publish/content/init/', ['method' => 'POST', 'headers' => $auth, 'json' => [
            'post_info' => ['title' => mb_substr(explode("\n", $message)[0], 0, 90), 'description' => mb_substr($message, 0, 4000), 'privacy_level' => $privacy, 'disable_comment' => false, 'auto_add_music' => true],
            'source_info' => ['source' => 'PULL_FROM_URL', 'photo_cover_index' => 0, 'photo_images' => array_map(fn($p) => $p['publicUrl'], array_slice($photos, 0, 35))],
            'post_mode' => 'DIRECT_POST',
            'media_type' => 'PHOTO',
        ]]));
        return ['pending' => true, 'externalId' => $init['publish_id'], 'privacy' => $privacy, 'kind' => 'photo'];
    }

    // Kiểm tra bài đã xử lý xong chưa; khi xong trả về link bài (nếu bài công khai)
    public static function publishStatus(array $account, ?array $token, string $publishId, string $kind): array
    {
        $d = self::check(Http::request(self::API . '/post/publish/status/fetch/', ['method' => 'POST', 'headers' => self::auth($token), 'json' => ['publish_id' => $publishId]]));
        if (($d['status'] ?? '') === 'FAILED') return ['failed' => true, 'error' => 'TikTok từ chối bài: ' . ($d['fail_reason'] ?? 'không rõ lý do')];
        if (($d['status'] ?? '') !== 'PUBLISH_COMPLETE') return ['done' => false];
        $id = $d['publicaly_available_post_id'][0] ?? null;
        $handle = json_decode_array($account['extra'] ?? null)['profile']['handle'] ?? null;
        $url = $id && $handle ? "https://www.tiktok.com/$handle/" . ($kind === 'photo' ? 'photo' : 'video') . "/$id" : null;
        return ['done' => true, 'url' => $url, 'note' => $id ? null : 'Bài đã lên TikTok ở chế độ riêng tư vì app chưa được TikTok duyệt quyền đăng công khai.'];
    }
}
