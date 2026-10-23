<?php
// Nhận file tải lên: kiểm tra loại file thật (không tin đuôi file), đặt tên ngẫu nhiên, lưu theo tháng.

final class Upload
{
    const ALLOWED = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/webp' => '.webp',
        'image/gif' => '.gif',
        'video/mp4' => '.mp4',
        'video/quicktime' => '.mov',
        'video/webm' => '.webm',
    ];
    const MAX_SIZE = 50 * 1024 * 1024;
    const MAX_FILES = 20;

    // Danh sách file của một ô <input type="file" name="field[]" multiple>
    private static function files(string $field): array
    {
        $f = $_FILES[$field] ?? null;
        if (!$f) return [];
        if (!is_array($f['name'])) $f = array_map(fn($v) => [$v], $f);
        $out = [];
        foreach ($f['name'] as $i => $name) {
            if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $out[] = ['name' => $name, 'tmp' => $f['tmp_name'][$i], 'size' => (int) $f['size'][$i], 'error' => $f['error'][$i]];
        }
        return $out;
    }

    /**
     * Lưu các file ảnh/video. Lỗi thì xoá những file đã lưu và báo lỗi dễ hiểu.
     * @return list<array{path:string, mime:string, size:int}>
     */
    public static function many(string $field): array
    {
        $files = self::files($field);
        if (count($files) > self::MAX_FILES) throw new HttpError(400, 'Mỗi lần tải tối đa 20 file.');
        $saved = [];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        try {
            foreach ($files as $f) {
                if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > self::MAX_SIZE) {
                    throw new HttpError(400, 'File quá lớn. Giới hạn 50 MB mỗi file, hãy nén video trước khi tải lên.');
                }
                if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp'])) throw new HttpError(400, 'Tải file lên bị lỗi, thử lại.');
                $mime = $finfo->file($f['tmp']);
                if (!isset(self::ALLOWED[$mime])) throw new HttpError(400, 'Chỉ nhận ảnh JPG, PNG, WebP, GIF hoặc video MP4, MOV, WebM.');
                $sub = date('Y-m');
                $dir = cfg('uploadDir') . '/' . $sub;
                if (!is_dir($dir)) mkdir($dir, 0775, true);
                $rel = $sub . '/' . bin2hex(random_bytes(12)) . self::ALLOWED[$mime];
                if (!move_uploaded_file($f['tmp'], cfg('uploadDir') . '/' . $rel)) throw new HttpError(500, 'Không lưu được file lên máy chủ.');
                $saved[] = ['path' => $rel, 'mime' => $mime, 'size' => $f['size']];
            }
        } catch (Throwable $e) {
            foreach ($saved as $s) self::remove($s['path']);
            throw $e;
        }
        return $saved;
    }

    // Đường dẫn tuyệt đối của file đã lưu, chặn đường dẫn thoát ra ngoài thư mục upload
    public static function fullPath(?string $rel): ?string
    {
        if (!$rel || str_contains($rel, '..') || !preg_match('~^[\w./-]+$~', $rel)) return null;
        return cfg('uploadDir') . '/' . $rel;
    }

    public static function remove(?string $rel): void
    {
        $full = self::fullPath($rel);
        if ($full && is_file($full)) @unlink($full);
    }

    // Nội dung file CSV (tối đa 5 MB)
    public static function csvText(string $field): ?string
    {
        $f = self::files($field)[0] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) return null;
        return file_get_contents($f['tmp']);
    }
}
