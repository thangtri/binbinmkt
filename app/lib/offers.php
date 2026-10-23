<?php
// Ưu đãi và mã giảm giá. Mỗi ưu đãi có một mã riêng cho từng kênh (COMBO15FB, COMBO15TT…)
// để biết kênh nào mang khách về. Trong bài đăng, {{ma}} được thay bằng mã của kênh đang đăng.

final class Offers
{
    // Kênh nhận mã: 4 kênh mạng xã hội + email + khách đặt trực tiếp
    const CODE_CHANNELS = [
        ['key' => 'facebook', 'suffix' => 'FB', 'name' => 'Facebook'],
        ['key' => 'tiktok', 'suffix' => 'TT', 'name' => 'TikTok'],
        ['key' => 'google', 'suffix' => 'GG', 'name' => 'Google Maps'],
        ['key' => 'zalo', 'suffix' => 'ZL', 'name' => 'Zalo OA'],
        ['key' => 'email', 'suffix' => 'EM', 'name' => 'Email'],
        ['key' => 'direct', 'suffix' => '', 'name' => 'Tại quầy, gọi điện'],
    ];

    public static function channelNames(): array
    {
        return array_column(self::CODE_CHANNELS, 'name', 'key');
    }

    public static function normPrefix($s): string
    {
        return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper(unaccent((string) $s))), 0, 14);
    }

    private static function readForm(array $body): array
    {
        $start = D::isDate($body['start_date'] ?? null) ? $body['start_date'] : D::today();
        $end = D::isDate($body['end_date'] ?? null) ? $body['end_date'] : $start;
        if ($end < $start) $end = $start;
        $ids = array_values(array_filter(array_map('int', (array) ($body['branch_ids'] ?? []))));
        return [
            'title' => str($body['title'] ?? '', 200),
            'summary' => str($body['summary'] ?? '', 300),
            'details' => str($body['details'] ?? '', 5000),
            'terms' => str($body['terms'] ?? '', 5000),
            'branch_ids' => $ids ? implode(',', $ids) : null,
            'start_date' => $start,
            'end_date' => $end,
            'code_prefix' => self::normPrefix($body['code_prefix'] ?? ''),
            // Form có ô "Đang áp dụng" gửi kèm has_active; tạo mới mặc định đang áp dụng
            'active' => !empty($body['has_active']) ? (!empty($body['active']) ? 1 : 0) : 1,
        ];
    }

    public static function list(): array
    {
        return DB::all(
            'SELECT o.*, (SELECT COUNT(*) FROM offer_redemptions r WHERE r.offer_id = o.id) AS used,
                    (SELECT COALESCE(SUM(amount), 0) FROM offer_redemptions r WHERE r.offer_id = o.id) AS revenue
             FROM offers o ORDER BY o.active DESC, o.end_date DESC, o.id DESC'
        );
    }

    public static function active(?string $on = null): array
    {
        return DB::all('SELECT * FROM offers WHERE active = 1 AND end_date >= ? ORDER BY start_date', [$on ?? D::today()]);
    }

    public static function get($id): ?array
    {
        $o = DB::get('SELECT * FROM offers WHERE id = ?', [int($id)]);
        if (!$o) return null;
        $o['id'] = (int) $o['id'];
        $o['codes'] = DB::all(
            'SELECT c.*, (SELECT COUNT(*) FROM offer_redemptions r WHERE r.code_id = c.id) AS used,
                    (SELECT COALESCE(SUM(amount), 0) FROM offer_redemptions r WHERE r.code_id = c.id) AS revenue
             FROM offer_codes c WHERE c.offer_id = ? ORDER BY c.id',
            [$o['id']]
        );
        return $o;
    }

    private static function saveCodes(int $offerId, string $prefix): void
    {
        foreach (self::CODE_CHANNELS as $ch) {
            $code = $prefix . $ch['suffix'];
            $existing = DB::get('SELECT id FROM offer_codes WHERE offer_id = ? AND channel = ?', [$offerId, $ch['key']]);
            if ($existing) DB::run('UPDATE offer_codes SET code = ? WHERE id = ?', [$code, $existing['id']]);
            else DB::run('INSERT INTO offer_codes (offer_id, channel, code) VALUES (?, ?, ?)', [$offerId, $ch['key'], $code]);
        }
    }

    private static function validate(array $o, int $exceptId = 0): ?string
    {
        if (!$o['title']) return 'Hãy đặt tên ưu đãi.';
        if (strlen($o['code_prefix']) < 3) return 'Mã ưu đãi cần ít nhất 3 ký tự chữ hoặc số, ví dụ COMBO15.';
        $codes = array_map(fn($c) => $o['code_prefix'] . $c['suffix'], self::CODE_CHANNELS);
        $clash = DB::get('SELECT code FROM offer_codes WHERE offer_id <> ? AND code IN (' . DB::placeholders(count($codes)) . ')', [$exceptId, ...$codes]);
        if ($clash) return "Mã {$clash['code']} đã dùng cho ưu đãi khác. Chọn mã khác.";
        return null;
    }

    public static function create(array $body, ?array $user): array
    {
        $o = self::readForm($body);
        if ($error = self::validate($o)) return ['error' => $error, 'form' => $o];
        $id = DB::run(
            'INSERT INTO offers (title, summary, details, terms, branch_ids, start_date, end_date, code_prefix, active, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$o['title'], $o['summary'], $o['details'], $o['terms'], $o['branch_ids'], $o['start_date'], $o['end_date'], $o['code_prefix'], $o['active'], $user['id'] ?? null, D::now()]
        )['insertId'];
        self::saveCodes($id, $o['code_prefix']);
        return ['id' => $id];
    }

    public static function update(int $id, array $body): array
    {
        $o = self::readForm($body);
        if ($error = self::validate($o, $id)) return ['error' => $error, 'form' => $o];
        DB::run(
            'UPDATE offers SET title = ?, summary = ?, details = ?, terms = ?, branch_ids = ?, start_date = ?, end_date = ?, code_prefix = ?, active = ? WHERE id = ?',
            [$o['title'], $o['summary'], $o['details'], $o['terms'], $o['branch_ids'], $o['start_date'], $o['end_date'], $o['code_prefix'], $o['active'], $id]
        );
        self::saveCodes($id, $o['code_prefix']);
        return ['id' => $id];
    }

    public static function findCode($code): ?array
    {
        return DB::get('SELECT c.*, o.title, o.start_date, o.end_date, o.active FROM offer_codes c JOIN offers o ON o.id = c.offer_id WHERE c.code = ?', [self::normPrefix($code)]);
    }

    public static function redeem(array $body, ?array $user): array
    {
        $c = self::findCode($body['code'] ?? '');
        if (!$c) return ['error' => 'Không tìm thấy mã "' . str($body['code'] ?? '', 40) . '".'];
        $usedOn = D::isDate($body['used_on'] ?? null) ? $body['used_on'] : D::today();
        $warn = $usedOn < $c['start_date'] || $usedOn > $c['end_date'] ? 'Lưu ý: mã dùng ngoài thời gian ưu đãi (' . D::dmy($c['start_date']) . ' – ' . D::dmy($c['end_date']) . ').' : null;
        $nights = trim((string) ($body['nights'] ?? '')) === '' ? null : max(0, int($body['nights']));
        $amount = trim((string) ($body['amount'] ?? '')) === '' ? null : max(0, int($body['amount']));
        DB::run(
            'INSERT INTO offer_redemptions (offer_id, code_id, channel, branch_id, used_on, guest, nights, amount, note, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$c['offer_id'], $c['id'], $c['channel'], int($body['branch_id'] ?? 0) ?: null, $usedOn, str($body['guest'] ?? '', 150), $nights, $amount, str($body['note'] ?? '', 255), $user['id'] ?? null, D::now()]
        );
        return ['ok' => true, 'code' => $c, 'warn' => $warn];
    }

    public static function redemptions(int $offerId, int $limit = 100): array
    {
        return DB::all(
            "SELECT r.*, c.code, b.name AS branch_name, u.name AS user_name FROM offer_redemptions r
             LEFT JOIN offer_codes c ON c.id = r.code_id LEFT JOIN branches b ON b.id = r.branch_id LEFT JOIN users u ON u.id = r.created_by
             WHERE r.offer_id = ? ORDER BY r.used_on DESC, r.id DESC LIMIT $limit",
            [$offerId]
        );
    }

    public static function codeFor($offerId, string $channel): ?string
    {
        if (!$offerId) return null;
        $r = DB::get('SELECT code FROM offer_codes WHERE offer_id = ? AND channel = ?', [$offerId, $channel]);
        return $r['code'] ?? null;
    }

    // Thay {{ma}} trong nội dung bằng mã của kênh
    public static function applyCode(?string $text, ?string $code): string
    {
        return preg_replace('/\{\{\s*ma\s*\}\}/iu', $code ?? '', (string) $text);
    }
}
