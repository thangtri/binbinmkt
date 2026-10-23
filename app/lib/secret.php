<?php
// Mã hoá token kết nối kênh trước khi lưu vào database (AES-256-GCM)

final class Secret
{
    private static function key(): string
    {
        return hash('sha256', (string) cfg('encryptionKey'), true);
    }

    public static function encrypt(?string $text): ?string
    {
        if ($text === null || $text === '') return null;
        $iv = random_bytes(12);
        $tag = '';
        $data = openssl_encrypt($text, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return implode(':', ['v1', base64_encode($iv), base64_encode($tag), base64_encode($data)]);
    }

    public static function decrypt(?string $stored): ?string
    {
        if (!$stored) return null;
        $p = explode(':', $stored);
        if (count($p) !== 4 || $p[0] !== 'v1') return null;
        $out = openssl_decrypt(base64_decode($p[3]), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, base64_decode($p[1]), base64_decode($p[2]));
        // Khoá đã đổi: token cũ không đọc được, cần kết nối lại
        return $out === false ? null : $out;
    }
}
