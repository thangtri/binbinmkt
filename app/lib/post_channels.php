<?php
// Link và số liệu của một bài trên từng kênh. Tổng số liệu được ghi lại vào bảng posts
// để các trang danh sách, tổng quan không phải cộng mỗi lần.

final class PostChannels
{
    public static function ensure(int $postId): void
    {
        $have = array_column(DB::all('SELECT channel FROM post_channels WHERE post_id = ?', [$postId]), 'channel');
        foreach (array_keys(C::CHANNELS) as $ch) {
            if (!in_array($ch, $have, true)) DB::run('INSERT INTO post_channels (post_id, channel, updated_at) VALUES (?, ?, ?)', [$postId, $ch, D::now()]);
        }
    }

    public static function list(int $postId): array
    {
        $byKey = [];
        foreach (DB::all('SELECT * FROM post_channels WHERE post_id = ?', [$postId]) as $r) $byKey[$r['channel']] = $r;
        return array_map(fn($ch) => $byKey[$ch] ?? ['channel' => $ch], array_keys(C::CHANNELS));
    }

    public static function recalcTotals(int $postId): void
    {
        $t = DB::get(
            'SELECT COALESCE(SUM(views), 0) AS views, COALESCE(SUM(likes), 0) AS likes,
                    COALESCE(SUM(comments), 0) AS comments, COALESCE(SUM(shares), 0) AS shares,
                    MIN(published_url) AS url
             FROM post_channels WHERE post_id = ?',
            [$postId]
        );
        DB::run('UPDATE posts SET views = ?, likes = ?, comments = ?, shares = ?, published_url = ? WHERE id = ?', [
            (int) $t['views'], (int) $t['likes'], (int) $t['comments'], (int) $t['shares'], $t['url'] ?: null, $postId,
        ]);
    }

    public static function update(int $postId, string $channel, array $fields): void
    {
        if (!$fields) return;
        self::ensure($postId);
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($fields)));
        DB::run("UPDATE post_channels SET $set, updated_at = ? WHERE post_id = ? AND channel = ?", [...array_values($fields), D::now(), $postId, $channel]);
    }

    /* Google Maps đăng riêng từng chi nhánh: link lưu trong post_google.
       Dòng 'google' của bài chỉ có link khi mọi chi nhánh đang hoạt động đều đã đăng. */

    public static function googleLinks(int $postId): array
    {
        return DB::all(
            'SELECT b.id AS branch_id, b.name, pg.published_url FROM branches b
             LEFT JOIN post_google pg ON pg.branch_id = b.id AND pg.post_id = ?
             WHERE b.active = 1 ORDER BY b.position, b.id',
            [$postId]
        );
    }

    public static function saveGoogleLink(int $postId, int $branchId, ?string $url): void
    {
        $exists = DB::get('SELECT id FROM post_google WHERE post_id = ? AND branch_id = ?', [$postId, $branchId]);
        if ($exists) DB::run('UPDATE post_google SET published_url = ?, updated_at = ? WHERE id = ?', [$url, D::now(), $exists['id']]);
        else DB::run('INSERT INTO post_google (post_id, branch_id, published_url, updated_at) VALUES (?, ?, ?, ?)', [$postId, $branchId, $url, D::now()]);
    }

    public static function syncGoogleRow(int $postId): void
    {
        $links = self::googleLinks($postId);
        $done = $links && count(array_filter($links, fn($l) => $l['published_url'])) === count($links);
        self::update($postId, 'google', ['published_url' => $done ? $links[0]['published_url'] : null]);
    }
}
