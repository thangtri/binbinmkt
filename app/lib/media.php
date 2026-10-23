<?php
// Ảnh và video của một bài: nhiều file, có thứ tự. File đầu tiên là ảnh đại diện (posts.media_path).

final class Media
{
    public static function isVideo(array $m): bool
    {
        return str_starts_with($m['mime'] ?? '', 'video/') || preg_match('/\.(mp4|mov|webm)$/i', $m['path'] ?? '');
    }

    public static function list(int $postId): array
    {
        return DB::all('SELECT * FROM post_media WHERE post_id = ? ORDER BY position, id', [$postId]);
    }

    public static function syncCover(int $postId): void
    {
        $first = self::list($postId)[0] ?? null;
        DB::run('UPDATE posts SET media_path = ? WHERE id = ?', [$first['path'] ?? null, $postId]);
    }

    public static function add(int $postId, array $files): void
    {
        if (!$files) return;
        $pos = (int) (DB::value('SELECT MAX(position) FROM post_media WHERE post_id = ?', [$postId]) ?? -1) + 1;
        foreach ($files as $f) {
            DB::run('INSERT INTO post_media (post_id, path, mime, size, position, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$postId, $f['path'], $f['mime'], $f['size'], $pos++, D::now()]);
        }
        self::syncCover($postId);
    }

    // Form gửi: remove_<id>=1 để gỡ, media_order="id,id,id" để sắp xếp
    public static function applyForm(int $postId, array $body): void
    {
        $items = self::list($postId);
        foreach ($items as $m) {
            if (!empty($body['remove_' . $m['id']])) {
                DB::run('DELETE FROM post_media WHERE id = ?', [$m['id']]);
                Upload::remove($m['path']);
            }
        }
        $order = array_filter(array_map('intval', explode(',', (string) ($body['media_order'] ?? ''))));
        if ($order) {
            $own = array_map('intval', array_column($items, 'id'));
            $pos = 0;
            foreach ($order as $id) if (in_array($id, $own, true)) DB::run('UPDATE post_media SET position = ? WHERE id = ?', [$pos++, $id]);
        }
        self::syncCover($postId);
    }

    // Nhân bản bài: chép file sang tên mới để xoá bài này không làm mất ảnh của bài kia
    public static function copy(int $fromId, int $toId): void
    {
        foreach (self::list($fromId) as $m) {
            $src = Upload::fullPath($m['path']);
            if (!$src || !is_file($src)) continue;
            $rel = dirname($m['path']) . '/' . bin2hex(random_bytes(12)) . '.' . pathinfo($m['path'], PATHINFO_EXTENSION);
            copy($src, cfg('uploadDir') . '/' . $rel);
            DB::run('INSERT INTO post_media (post_id, path, mime, size, position, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$toId, $rel, $m['mime'], $m['size'], $m['position'], D::now()]);
        }
        self::syncCover($toId);
    }

    public static function removeAll(int $postId): void
    {
        foreach (self::list($postId) as $m) Upload::remove($m['path']);
        DB::run('DELETE FROM post_media WHERE post_id = ?', [$postId]);
    }
}
