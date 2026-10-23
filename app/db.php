<?php
// Lớp truy cập dữ liệu dùng chung cho MySQL (Hostinger) và SQLite (chạy thử trên máy).
// Chỉ dùng SQL chuẩn với placeholder `?` để chạy được trên cả hai.

final class DB
{
    private static ?PDO $pdo = null;
    public static string $dialect = 'sqlite';

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $c = cfg('db');
        if ($c['client'] === 'mysql') {
            self::$dialect = 'mysql';
            $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $c['user'], $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
            ]);
            self::$pdo->exec("SET time_zone = '+07:00'");
        } else {
            self::$dialect = 'sqlite';
            if (!is_dir(dirname($c['sqlitePath']))) mkdir(dirname($c['sqlitePath']), 0775, true);
            self::$pdo = new PDO('sqlite:' . $c['sqlitePath'], null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        }
        return self::$pdo;
    }

    private static function normalize(array $params): array
    {
        return array_map(fn($v) => is_bool($v) ? ($v ? 1 : 0) : $v, array_values($params));
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute(self::normalize($params));
        return $st->fetchAll();
    }

    public static function get(string $sql, array $params = []): ?array
    {
        $rows = self::all($sql, $params);
        return $rows[0] ?? null;
    }

    // Giá trị cột đầu tiên của dòng đầu tiên (COUNT, MAX…)
    public static function value(string $sql, array $params = [])
    {
        $row = self::get($sql, $params);
        return $row ? array_values($row)[0] : null;
    }

    /** @return array{insertId:int, changes:int} */
    public static function run(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute(self::normalize($params));
        return ['insertId' => (int) self::pdo()->lastInsertId(), 'changes' => $st->rowCount()];
    }

    public static function exec(string $sql): void
    {
        self::pdo()->exec($sql);
    }

    // Tạo chuỗi "?, ?, ?" cho mệnh đề IN
    public static function placeholders(int $n): string
    {
        return implode(', ', array_fill(0, $n, '?'));
    }
}
