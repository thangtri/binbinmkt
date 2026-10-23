<?php
// Đăng nhập, phân quyền theo vai trò.

final class Auth
{
    public static ?array $user = null;

    // Quyền theo vai trò. Admin làm được mọi việc.
    const PERMISSIONS = [
        'editor' => ['post:create', 'post:edit', 'post:submit', 'post:publish', 'event:edit', 'channel:edit', 'contact:edit', 'campaign:edit', 'offer:edit', 'offer:redeem'],
        'viewer' => [],
    ];

    // Tên đăng nhập: 3–30 ký tự, chữ thường không dấu, số, dấu chấm, gạch dưới, gạch ngang
    const USERNAME_RE = '/^[a-z0-9](?:[a-z0-9._-]{1,28})[a-z0-9]$/';

    public static function hashPassword(string $pw): string
    {
        return password_hash($pw, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $pw, ?string $hash): bool
    {
        return $hash !== null && $hash !== '' && password_verify($pw, $hash);
    }

    public static function validatePassword($pw): ?string
    {
        if (!is_string($pw) || mb_strlen($pw) < 8) return 'Mật khẩu cần ít nhất 8 ký tự.';
        return null;
    }

    public static function normalizeUsername($s): string
    {
        return strtolower(trim((string) ($s ?? '')));
    }

    public static function validateUsername(string $s): ?string
    {
        if (!preg_match(self::USERNAME_RE, $s)) {
            return 'Tên đăng nhập dài 3–30 ký tự, chỉ gồm chữ thường không dấu, số, dấu chấm, gạch dưới hoặc gạch ngang, không bắt đầu hay kết thúc bằng dấu.';
        }
        return null;
    }

    public static function can(?array $user, string $perm): bool
    {
        if (!$user) return false;
        if ($user['role'] === 'admin') return true;
        return in_array($perm, self::PERMISSIONS[$user['role']] ?? [], true);
    }

    public static function loadUser(): void
    {
        self::$user = null;
        $id = Session::get('uid');
        if (!$id) return;
        $u = DB::get('SELECT id, username, email, name, role, active, session_version FROM users WHERE id = ?', [$id]);
        // session_version tăng khi đổi/đặt lại mật khẩu → mọi phiên đăng nhập cũ hết hiệu lực
        if ($u && (int) $u['active'] && (int) Session::get('sv', 1) === (int) $u['session_version']) {
            $u['id'] = (int) $u['id'];
            self::$user = $u;
        } else {
            Session::set('uid', null);
            Session::set('sv', null);
        }
    }

    public static function requireLogin(): void
    {
        if (self::$user) return;
        if (Req::wantsJson()) json_out(['error' => 'Chưa đăng nhập'], 401);
        redirect('/login?next=' . rawurlencode(Req::uri()));
    }

    public static function requirePerm(string $perm): void
    {
        if (self::can(self::$user, $perm)) return;
        self::deny();
    }

    public static function requireAdmin(): void
    {
        if ((self::$user['role'] ?? '') === 'admin') return;
        self::deny();
    }

    public static function deny(): never
    {
        if (Req::wantsJson()) json_out(['error' => 'Bạn không có quyền làm việc này'], 403);
        render('error', ['title' => 'Không có quyền', 'message' => 'Tài khoản của bạn không có quyền làm việc này. Liên hệ quản trị viên nếu cần.'], 403);
    }
}

function user(): ?array
{
    return Auth::$user;
}
