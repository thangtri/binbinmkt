<?php
// Đọc cấu hình từ file .env. Trên Hostinger đặt file ở thư mục cha của public_html
// (../binbin.env) để không bị ghi đè khi deploy và không truy cập được từ web.

final class Config
{
    private static ?array $c = null;

    private static function loadEnv(string $file): void
    {
        if (!is_file($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) $v = substr($v, 1, -1);
            if (getenv($k) === false) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
            }
        }
    }

    public static function env(string $k, string $default = ''): string
    {
        $v = getenv($k);
        return $v === false || $v === '' ? $default : $v;
    }

    public static function all(): array
    {
        if (self::$c !== null) return self::$c;
        $root = dirname(__DIR__);
        self::loadEnv(dirname($root) . '/binbin.env');
        self::loadEnv($root . '/.env');

        $env = self::env('APP_ENV', 'development');
        $isProd = $env === 'production';
        $secret = self::env('SESSION_SECRET');
        if ($isProd && strlen($secret) < 32) {
            throw new RuntimeException('SESSION_SECRET phải dài ít nhất 32 ký tự trên production.');
        }
        $secret = $secret ?: 'dev-only-secret-not-for-production';
        $path = fn($p) => preg_match('~^([a-zA-Z]:)?[\\\\/]~', $p) ? $p : $root . '/' . preg_replace('~^\./~', '', $p);

        return self::$c = [
            'root' => $root,
            'env' => $env,
            'isProd' => $isProd,
            'appUrl' => rtrim(self::env('APP_URL', 'http://localhost:8000'), '/'),
            'sessionSecret' => $secret,
            'timezone' => 'Asia/Ho_Chi_Minh',
            'db' => [
                'client' => self::env('DB_CLIENT') === 'mysql' ? 'mysql' : 'sqlite',
                'sqlitePath' => $path(self::env('SQLITE_PATH', 'data/binbin.db')),
                'host' => self::env('DB_HOST', 'localhost'),
                'port' => (int) self::env('DB_PORT', '3306'),
                'user' => self::env('DB_USER'),
                'password' => self::env('DB_PASSWORD'),
                'name' => self::env('DB_NAME'),
            ],
            'admin' => [
                'username' => strtolower(trim(self::env('ADMIN_USERNAME', 'admin'))),
                'email' => strtolower(trim(self::env('ADMIN_EMAIL'))),
                'password' => self::env('ADMIN_PASSWORD'),
                'name' => self::env('ADMIN_NAME', 'Quản trị viên'),
            ],
            'brevo' => [
                'apiKey' => self::env('BREVO_API_KEY'),
                'senderEmail' => self::env('BREVO_SENDER_EMAIL'),
                'senderName' => self::env('BREVO_SENDER_NAME', 'Bin Bin Hotel'),
                'webhookSecret' => self::env('BREVO_WEBHOOK_SECRET'),
            ],
            'uploadDir' => rtrim($path(self::env('UPLOAD_DIR', 'uploads')), '/\\'),
            // Email liên hệ ghi trên trang Chính sách bảo mật / Điều khoản
            'legalContact' => self::env('CONTACT_EMAIL', self::env('BREVO_SENDER_EMAIL', self::env('ADMIN_EMAIL'))),
            // Khoá mã hoá token kết nối kênh; mặc định suy ra từ SESSION_SECRET
            'encryptionKey' => self::env('ENCRYPTION_KEY', $secret),
            // Mật khẩu cho link chạy việc nền qua web (khi không đặt được cron bằng dòng lệnh)
            'cronKey' => self::env('CRON_KEY'),
            'google' => ['placesApiKey' => self::env('GOOGLE_PLACES_API_KEY')],
            'facebook' => [
                'appId' => self::env('FACEBOOK_APP_ID'),
                'appSecret' => self::env('FACEBOOK_APP_SECRET'),
                'graphVersion' => self::env('FACEBOOK_GRAPH_VERSION', 'v23.0'),
                // Chỉ đổi khi chạy thử với máy chủ giả lập
                'graphBase' => rtrim(self::env('FACEBOOK_GRAPH_BASE', 'https://graph.facebook.com'), '/'),
                'videoBase' => rtrim(self::env('FACEBOOK_VIDEO_BASE', 'https://graph-video.facebook.com'), '/'),
            ],
            'tiktok' => ['clientKey' => self::env('TIKTOK_CLIENT_KEY'), 'clientSecret' => self::env('TIKTOK_CLIENT_SECRET')],
            'zalo' => ['appId' => self::env('ZALO_APP_ID'), 'appSecret' => self::env('ZALO_APP_SECRET')],
            'ai' => ['apiKey' => self::env('ANTHROPIC_API_KEY'), 'model' => self::env('AI_MODEL', 'claude-opus-5-5')],
            'siteVerification' => [
                'zalo' => self::env('ZALO_SITE_VERIFICATION'),
                'facebook' => self::env('FACEBOOK_DOMAIN_VERIFICATION'),
            ],
        ];
    }

    public static function get(string $path)
    {
        $v = self::all();
        foreach (explode('.', $path) as $k) $v = $v[$k] ?? null;
        return $v;
    }
}

function cfg(string $path)
{
    return Config::get($path);
}
