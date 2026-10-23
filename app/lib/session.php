<?php
// Phiên đăng nhập lưu trong cookie đã mã hoá (AES-256-GCM), không phụ thuộc thư mục session của hosting.
// Dữ liệu nhỏ: id người dùng, phiên bản phiên, mã CSRF, thông báo flash, trạng thái OAuth.

final class Session
{
    const COOKIE = 'bb_sid';
    const MAX_AGE = 7 * 24 * 3600;

    private static ?array $data = null;
    private static bool $dirty = false;

    private static function key(): string
    {
        return hash('sha256', 'session:' . cfg('sessionSecret'), true);
    }

    private static function load(): void
    {
        if (self::$data !== null) return;
        self::$data = [];
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if ($raw === '') return;
        $bin = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($bin === false || strlen($bin) < 29) return;
        $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        $data = $plain === false ? null : json_decode($plain, true);
        // Phiên quá hạn 7 ngày kể từ lần ghi cuối thì bỏ
        if (is_array($data) && ($data['_exp'] ?? 0) > time()) self::$data = $data;
    }

    public static function get(string $k, $default = null)
    {
        self::load();
        return self::$data[$k] ?? $default;
    }

    public static function set(string $k, $v): void
    {
        self::load();
        self::$data[$k] = $v;
        self::$dirty = true;
    }

    public static function pull(string $k)
    {
        $v = self::get($k);
        if ($v !== null) self::set($k, null);
        return $v;
    }

    public static function clear(): void
    {
        self::$data = [];
        self::$dirty = true;
    }

    public static function save(): void
    {
        if (!self::$dirty || headers_sent()) return;
        self::$dirty = false;
        $data = array_filter(self::$data ?? [], fn($v) => $v !== null);
        $opts = ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => (bool) cfg('isProd')];
        if (!$data) {
            setcookie(self::COOKIE, '', ['expires' => time() - 3600] + $opts);
            return;
        }
        $data['_exp'] = time() + self::MAX_AGE;
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(json_encode($data, JSON_UNESCAPED_UNICODE), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        $value = rtrim(strtr(base64_encode($iv . $tag . $cipher), '+/', '-_'), '=');
        setcookie(self::COOKIE, $value, ['expires' => time() + self::MAX_AGE] + $opts);
    }
}
