<?php
// Cửa vào duy nhất của website: mọi đường dẫn (trừ file trong /public) đi qua đây.

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Chạy thử bằng "php -S": để máy chủ tự trả file tĩnh trong /public
if (PHP_SAPI === 'cli-server' && str_starts_with($path, '/public/') && is_file(__DIR__ . $path)) return false;

require __DIR__ . '/app/bootstrap.php';

Req::$path = $path === '/' ? '/' : rtrim($path, '/');
$isProd = cfg('isProd');

// Ảnh khách sạn tuỳ chọn cho trang đăng nhập và trang tổng quan: public/img/hotel.(jpg|webp|png)
function hotelPhoto(): ?string
{
    foreach (['jpg', 'webp', 'png'] as $ext) {
        if (is_file(cfg('root') . "/public/img/hotel.$ext")) return "/public/img/hotel.$ext";
    }
    return null;
}

header_remove('X-Powered-By');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; media-src 'self' blob:; frame-src 'self'; form-action 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'" . ($isProd ? '; upgrade-insecure-requests' : ''));
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
if ($isProd) header('Strict-Transport-Security: max-age=15552000; includeSubDomains');

function render_error(int $status, string $title, string $message): never
{
    if (Req::wantsJson()) json_out(['error' => $message], $status);
    render('error', ['title' => $title, 'message' => $message], $status);
}

View::$shared = [
    'user' => null,
    'csrf' => '',
    'flash' => null,
    'path' => Req::$path,
    'brevoLive' => false,
    'query' => $_GET,
    'hotelPhoto' => hotelPhoto(),
    'siteVerification' => cfg('siteVerification'),
];

try {
    $method = Req::method();

    // Trang công khai: huỷ nhận email, trang pháp lý, ảnh có chữ ký, webhook Brevo, việc nền (không cần đăng nhập, không CSRF)
    require __DIR__ . '/app/routes/public.php';
    if (Router::dispatch($method, Req::$path)) exit;

    Auth::loadUser();

    // File tải lên vượt post_max_size: PHP bỏ trống toàn bộ form
    if ($method === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
        && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        throw new HttpError(400, 'File quá lớn so với giới hạn của máy chủ. Hãy nén video hoặc tải ít file hơn mỗi lần.');
    }

    // CSRF: token lưu trong phiên, gửi kèm form (_csrf) hoặc header X-CSRF-Token
    if (!Session::get('csrf')) Session::set('csrf', bin2hex(random_bytes(18)));
    if ($method === 'POST') {
        $sent = body('_csrf') ?: (Req::header('X-CSRF-Token') ?: q('_csrf'));
        if (!is_string($sent) || !hash_equals(Session::get('csrf'), $sent)) {
            render_error(403, 'Phiên đã hết hạn', 'Phiên làm việc đã hết hạn. Hãy quay lại, tải lại trang rồi thử lần nữa.');
        }
    }

    View::$shared = [
        'user' => Auth::$user,
        'csrf' => Session::get('csrf'),
        'flash' => Session::pull('flash'),
        'brevoLive' => Brevo::isLive(),
    ] + View::$shared;

    require __DIR__ . '/app/routes/auth.php';
    if (Router::dispatch($method, Req::$path)) exit;

    Auth::requireLogin();

    foreach (['dashboard', 'calendar', 'posts', 'events', 'channels', 'email', 'users', 'settings', 'offers', 'plan', 'reports'] as $r) {
        require __DIR__ . "/app/routes/$r.php";
    }
    if (Router::dispatch($method, Req::$path)) exit;

    render_error(404, 'Không tìm thấy', 'Trang bạn tìm không tồn tại hoặc đã bị xoá.');
} catch (HttpError $e) {
    render_error($e->status, $e->status === 404 ? 'Không tìm thấy' : 'Có lỗi xảy ra', $e->expose ? $e->getMessage() : 'Đã có lỗi phía máy chủ.');
} catch (Throwable $e) {
    error_log('[error] ' . $e);
    render_error(500, 'Có lỗi xảy ra', $isProd ? 'Đã có lỗi phía máy chủ. Thử lại sau ít phút; nếu vẫn lỗi, báo quản trị viên.' : $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
