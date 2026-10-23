<?php
// Bộ định tuyến đơn giản: đường dẫn dạng "/posts/{id}" → hàm xử lý nhận tham số theo thứ tự.

final class Router
{
    private static array $routes = [];

    public static function get(string $pattern, callable $fn): void
    {
        self::$routes[] = ['GET', $pattern, $fn];
    }

    public static function post(string $pattern, callable $fn): void
    {
        self::$routes[] = ['POST', $pattern, $fn];
    }

    private static function regex(string $pattern): string
    {
        $re = preg_replace_callback('/\{(\w+)\}/', fn($m) => $m[1] === 'id' || str_ends_with($m[1], 'id') ? '(\d+)' : '([^/]+)', $pattern);
        return '#^' . $re . '$#';
    }

    // Trả về true nếu có route khớp (hàm xử lý thường tự kết thúc bằng render/redirect)
    public static function dispatch(string $method, string $path): bool
    {
        foreach (self::$routes as [$m, $pattern, $fn]) {
            if ($m !== $method && !($method === 'HEAD' && $m === 'GET')) continue;
            if (preg_match(self::regex($pattern), $path, $match)) {
                $fn(...array_map('rawurldecode', array_slice($match, 1)));
                return true;
            }
        }
        return false;
    }
}
