<?php
// Hàm dùng chung: đọc request, trả response, kiểm tra dữ liệu, ghi nhật ký.

// Lỗi có mã HTTP; expose = true thì hiện nguyên thông báo cho người dùng
class HttpError extends RuntimeException
{
    public int $status;
    public bool $expose;

    public function __construct(int $status, string $message, bool $expose = true)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->expose = $expose;
    }
}

// Lỗi nghiệp vụ hiển thị được cho người dùng (như err.expose bên Node)
class UserError extends RuntimeException
{
}

function abort(int $status, string $message): never
{
    throw new HttpError($status, $message);
}

function e($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function str($v, int $max = 1000): string
{
    if (is_array($v)) $v = '';
    return mb_substr(trim((string) ($v ?? '')), 0, $max);
}

function int($v, int $fallback = 0): int
{
    if (is_array($v)) return $fallback;
    $s = preg_replace('/[^\d-]/', '', (string) ($v ?? ''));
    return preg_match('/^-?\d+/', $s, $m) ? (int) $m[0] : $fallback;
}

function isEmail($s): bool
{
    return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u', (string) ($s ?? ''));
}

function safeUrl($u): string
{
    $s = str($u, 500);
    return preg_match('~^https?://~i', $s) ? $s : '';
}

function parseTags($s): string
{
    $tags = array_filter(array_map(fn($t) => mb_strtolower(trim($t)), explode(',', (string) ($s ?? ''))), 'strlen');
    return implode(', ', array_values(array_unique($tags)));
}

// Số kiểu Việt Nam: 1.234.567 và 4,5
function num($n): string
{
    $n = (float) ($n ?? 0);
    if (floor($n) == $n) return number_format($n, 0, ',', '.');
    return rtrim(rtrim(number_format($n, 3, ',', '.'), '0'), ',');
}

function token(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}

// Bỏ dấu tiếng Việt: "Đà Lạt" → "Da Lat"
function unaccent(string $s): string
{
    $s = str_replace(['đ', 'Đ'], ['d', 'D'], $s);
    if (class_exists('Normalizer')) return preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($s, Normalizer::FORM_D));
    $from = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹ';
    $to = 'aaaaaaaaaaaaaaaaaeeeeeeeeeeeiiiiiooooooooooooooooouuuuuuuuuuuyyyyy';
    $map = [];
    foreach (mb_str_split($from) as $i => $ch) {
        $map[$ch] = $to[$i];
        $map[mb_strtoupper($ch)] = strtoupper($to[$i]);
    }
    return strtr($s, $map);
}

function json_decode_array(?string $s): array
{
    $v = json_decode((string) $s, true);
    return is_array($v) ? $v : [];
}

/* ---------- Request ---------- */

final class Req
{
    private static ?array $body = null;
    public static string $path = '/';

    // Dữ liệu form (POST) hoặc JSON (fetch từ app.js)
    public static function body(): array
    {
        if (self::$body !== null) return self::$body;
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($type, 'application/json')) {
            $data = json_decode(file_get_contents('php://input'), true);
            return self::$body = is_array($data) ? $data : [];
        }
        return self::$body = $_POST;
    }

    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    public static function header(string $name): string
    {
        return (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '');
    }

    public static function uri(): string
    {
        return $_SERVER['REQUEST_URI'] ?? '/';
    }
}

function body(?string $key = null, $default = null)
{
    $b = Req::body();
    return $key === null ? $b : ($b[$key] ?? $default);
}

function q(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

/* ---------- Response ---------- */

function redirect(string $url): never
{
    Session::save();
    header('Location: ' . $url, true, 302);
    exit;
}

// Chuyển trang ngay cho người dùng rồi làm tiếp việc nặng trong cùng tiến trình (gửi email…)
function redirect_then(string $url, callable $work): never
{
    Session::save();
    header('Location: ' . $url, true, 302);
    header('Content-Length: 0');
    finish_request();
    try {
        $work();
    } catch (Throwable $e) {
        error_log('[after-response] ' . $e->getMessage());
    }
    exit;
}

function json_out($data, int $status = 200): never
{
    Session::save();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function flash(string $type, string $message): void
{
    Session::set('flash', ['type' => $type, 'message' => $message]);
}

// Biến dùng chung cho mọi view
final class View
{
    public static array $shared = [];

    public static function render(string $name, array $vars = []): string
    {
        $file = cfg('root') . '/views/' . $name . '.php';
        if (!is_file($file)) throw new RuntimeException("Không có view $name");
        extract(self::$shared, EXTR_SKIP);
        extract($vars, EXTR_OVERWRITE);
        ob_start();
        try {
            include $file;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean();
    }
}

function render(string $view, array $vars = [], int $status = 200): never
{
    // Tiêu đề trang dùng chung cho các phần đầu trang (partials/top, partials/head)
    View::$shared['title'] = $vars['title'] ?? 'Bin Bin Marketing';
    $html = View::render($view, $vars);
    Session::save();
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

// Chèn một view con (partial) trong view
function partial(string $name, array $vars = []): string
{
    return View::render($name, $vars);
}

function can(string $perm): bool
{
    return Auth::can(Auth::$user, $perm);
}

function logActivity(?array $user, string $action, ?string $entity, $entityId, $summary): void
{
    DB::run('INSERT INTO activity (user_id, action, entity, entity_id, summary, created_at) VALUES (?, ?, ?, ?, ?, ?)', [
        $user['id'] ?? null,
        $action,
        $entity,
        $entityId ?: null,
        mb_substr((string) ($summary ?? ''), 0, 255),
        D::now(),
    ]);
}

// Gửi file kèm hỗ trợ tải từng đoạn (Range) để xem video
function send_file(string $path, string $mime, int $maxAge = 0): never
{
    Session::save();
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    $size = filesize($path);
    $start = 0;
    $end = $size - 1;
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    if ($maxAge) header('Cache-Control: private, max-age=' . $maxAge);
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'] ?? '', $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') {
            $start = max(0, $size - (int) $m[2]);
        } else {
            $start = (int) $m[1];
            if ($m[2] !== '') $end = min($end, (int) $m[2]);
        }
        if ($start > $end) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) {
        $chunk = fread($fh, min(1 << 20, $left));
        echo $chunk;
        $left -= strlen($chunk);
        flush();
    }
    fclose($fh);
    exit;
}

// Trả trang cho người dùng ngay, rồi chạy tiếp việc nặng (gửi email…) trong cùng tiến trình
function finish_request(): void
{
    Session::save();
    ignore_user_abort(true);
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else {
        // PHP chạy thử trên máy: không tách được, đành gửi hết rồi làm tiếp
        if (ob_get_level()) ob_end_flush();
        flush();
    }
}

/* ---------- Dữ liệu tạm có hạn ---------- */

final class Cache
{
    public static function get(string $k)
    {
        $r = DB::get('SELECT v, expires_at FROM cache WHERE k = ?', [$k]);
        if (!$r || $r['expires_at'] < D::now()) return null;
        return json_decode($r['v'], true);
    }

    public static function set(string $k, $v, int $seconds): void
    {
        $exp = D::addSeconds(D::now(), $seconds);
        $json = json_encode($v, JSON_UNESCAPED_UNICODE);
        if (DB::get('SELECT k FROM cache WHERE k = ?', [$k])) DB::run('UPDATE cache SET v = ?, expires_at = ? WHERE k = ?', [$json, $exp, $k]);
        else DB::run('INSERT INTO cache (k, v, expires_at) VALUES (?, ?, ?)', [$k, $json, $exp]);
        if (random_int(1, 50) === 1) DB::run('DELETE FROM cache WHERE expires_at < ?', [D::now()]);
    }

    public static function delete(string $k): void
    {
        DB::run('DELETE FROM cache WHERE k = ?', [$k]);
    }

    // Đếm số lần trong một khoảng thời gian (chặn dò mật khẩu…)
    public static function hit(string $k, int $windowSeconds): int
    {
        $cur = self::get($k);
        $n = ($cur['n'] ?? 0) + 1;
        $until = $cur['until'] ?? D::addSeconds(D::now(), $windowSeconds);
        self::set($k, ['n' => $n, 'until' => $until], max(1, strtotime($until) - time()));
        return $n;
    }

    public static function count(string $k): int
    {
        return (int) (self::get($k)['n'] ?? 0);
    }

    // Khoá để chỉ một tiến trình làm một việc nền tại một thời điểm (hết hạn sau $seconds nếu tiến trình chết)
    public static function lock(string $name, int $seconds): bool
    {
        $k = 'lock:' . $name;
        DB::run('DELETE FROM cache WHERE k = ? AND expires_at < ?', [$k, D::now()]);
        try {
            DB::run('INSERT INTO cache (k, v, expires_at) VALUES (?, ?, ?)', [$k, '1', D::addSeconds(D::now(), $seconds)]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    public static function unlock(string $name): void
    {
        self::delete('lock:' . $name);
    }
}
