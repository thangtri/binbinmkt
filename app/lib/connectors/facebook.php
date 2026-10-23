<?php
// Facebook Page qua Graph API. Đăng nhập Facebook một lần → lấy Page token (không hết hạn khi
// xuất phát từ token người dùng dài hạn) → đọc người theo dõi và tương tác của các bài gần đây.

final class FacebookConnector
{
    const KEY = 'facebook';
    const OAUTH = true;
    // pages_manage_posts: để tự đăng bài lên Page
    const SCOPES = ['pages_show_list', 'pages_read_engagement', 'read_insights', 'pages_manage_posts'];

    private static function graph(string $path, array $query = []): string
    {
        return cfg('facebook.graphBase') . '/' . cfg('facebook.graphVersion') . $path . ($query ? '?' . http_build_query($query) : '');
    }

    public static function isConfigured(): bool
    {
        return cfg('facebook.appId') !== '' && cfg('facebook.appSecret') !== '';
    }

    private static function check(array $res): array
    {
        if ($res['ok'] && empty($res['data']['error'])) return $res['data'] ?? [];
        $e = $res['data']['error'] ?? [];
        // 190: token hết hạn hoặc bị thu hồi
        throw new ConnectorError($e['message'] ?? "Facebook trả về lỗi {$res['status']}", ($e['code'] ?? 0) === 190);
    }

    public static function authUrl(string $redirectUri, string $state, ?string $challenge = null): string
    {
        return 'https://www.facebook.com/' . cfg('facebook.graphVersion') . '/dialog/oauth?' . http_build_query([
            'client_id' => cfg('facebook.appId'), 'redirect_uri' => $redirectUri, 'state' => $state, 'scope' => implode(',', self::SCOPES), 'response_type' => 'code',
        ]);
    }

    // Đổi code → token người dùng dài hạn → danh sách Page mà tài khoản quản lý
    public static function exchange(string $code, string $redirectUri, ?string $verifier = null): array
    {
        $app = ['client_id' => cfg('facebook.appId'), 'client_secret' => cfg('facebook.appSecret')];
        $short = self::check(Http::request(self::graph('/oauth/access_token', $app + ['redirect_uri' => $redirectUri, 'code' => $code])));
        $long = self::check(Http::request(self::graph('/oauth/access_token', ['grant_type' => 'fb_exchange_token'] + $app + ['fb_exchange_token' => $short['access_token']])));
        $pages = self::check(Http::request(self::graph('/me/accounts', ['fields' => 'id,name,link,access_token', 'access_token' => $long['access_token']])));
        return array_map(fn($p) => [
            'id' => $p['id'], 'name' => $p['name'], 'url' => $p['link'] ?? "https://www.facebook.com/{$p['id']}", 'accessToken' => $p['access_token'],
        ], $pages['data'] ?? []);
    }

    public static function fetchStats(array $account, ?array $token): array
    {
        if (empty($token['access_token'])) throw new ConnectorError('Chưa kết nối Facebook.', true);
        $t = $token['access_token'];
        $id = $account['external_id'];
        $page = self::check(Http::request(self::graph("/$id", ['fields' => 'name,link,followers_count,fan_count', 'access_token' => $t])));
        $posts = self::check(Http::request(self::graph("/$id/posts", [
            'fields' => 'id,message,created_time,permalink_url,shares,reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)',
            'since' => (string) (time() - 28 * 86400),
            'limit' => '50',
            'access_token' => $t,
        ])));
        $items = array_map(fn($p) => [
            'url' => $p['permalink_url'] ?? null,
            'title' => mb_substr((string) ($p['message'] ?? ''), 0, 120),
            'createdAt' => $p['created_time'] ?? null,
            'likes' => (int) ($p['reactions']['summary']['total_count'] ?? 0),
            'comments' => (int) ($p['comments']['summary']['total_count'] ?? 0),
            'shares' => (int) ($p['shares']['count'] ?? 0),
            'views' => null,
        ], $posts['data'] ?? []);

        // Tiếp cận 28 ngày: Meta đổi tên chỉ số theo thời gian, nên lỗi ở đây không làm hỏng cả lần cập nhật
        $reach = null;
        try {
            $ins = Http::request(self::graph("/$id/insights", ['metric' => 'page_impressions_unique', 'period' => 'days_28', 'access_token' => $t]));
            $vals = $ins['data']['data'][0]['values'] ?? [];
            if ($ins['ok'] && $vals) $reach = (int) end($vals)['value'] ?: null;
        } catch (ConnectorError $e) {
            $reach = null;
        }

        return [
            'followers' => $page['followers_count'] ?? $page['fan_count'] ?? null,
            'reach' => $reach,
            'engagement' => array_sum(array_map(fn($p) => $p['likes'] + $p['comments'] + $p['shares'], $items)),
            'profile' => ['name' => $page['name'] ?? null, 'url' => $page['link'] ?? null],
            'posts' => $items,
        ];
    }

    /* ---------- Tự đăng bài ---------- */

    private static function uploadForm(string $url, array $fields, ?array $file): array
    {
        if ($file) $fields['source'] = new CURLFile($file['path'], $file['mime'] ?: 'application/octet-stream', basename($file['path']));
        return Http::request($url, ['method' => 'POST', 'body' => $fields, 'timeout' => 600]);
    }

    // files: [{ path (đường dẫn tuyệt đối), mime, isVideo }]
    public static function publish(array $account, ?array $token, string $message, array $files): array
    {
        if (empty($token['access_token'])) throw new ConnectorError('Chưa kết nối Facebook.', true);
        $t = $token['access_token'];
        $page = $account['external_id'];
        $video = current(array_filter($files, fn($x) => $x['isVideo'])) ?: null;
        if ($video) {
            // Facebook không gộp video với ảnh trong một bài: đăng video đầu tiên kèm nội dung
            $v = self::check(self::uploadForm(cfg('facebook.videoBase') . '/' . cfg('facebook.graphVersion') . "/$page/videos", ['description' => $message, 'access_token' => $t], $video));
            return [
                'url' => "https://www.facebook.com/$page/videos/{$v['id']}",
                'externalId' => $v['id'],
                'note' => count($files) > 1 ? 'Facebook chỉ đăng được video, các ảnh đi kèm bị bỏ qua.' : null,
            ];
        }
        $fields = ['message' => $message, 'access_token' => $t];
        foreach (array_slice(array_values($files), 0, 30) as $i => $photo) {
            $p = self::check(self::uploadForm(self::graph("/$page/photos"), ['published' => 'false', 'access_token' => $t], $photo));
            $fields["attached_media[$i]"] = json_encode(['media_fbid' => $p['id']]);
        }
        $post = self::check(Http::request(self::graph("/$page/feed"), ['method' => 'POST', 'form' => $fields]));
        $link = Http::request(self::graph("/{$post['id']}", ['fields' => 'permalink_url', 'access_token' => $t]));
        return ['url' => $link['data']['permalink_url'] ?? "https://www.facebook.com/{$post['id']}", 'externalId' => $post['id'], 'note' => null];
    }
}
