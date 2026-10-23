<?php
// Chi nhánh của chuỗi. Mỗi chi nhánh có địa điểm Google Maps riêng (điểm, số đánh giá, đánh giá mới).

final class Branches
{
    public static function list(bool $all = false): array
    {
        $rows = DB::all('SELECT * FROM branches ' . ($all ? '' : 'WHERE active = 1') . ' ORDER BY position, id');
        foreach ($rows as &$r) $r['id'] = (int) $r['id'];
        return $rows;
    }

    public static function get($id): ?array
    {
        $b = DB::get('SELECT * FROM branches WHERE id = ?', [int($id)]);
        if ($b) $b['id'] = (int) $b['id'];
        return $b;
    }

    private static function readForm(array $body): array
    {
        return [
            'name' => str($body['name'] ?? '', 150),
            'address' => str($body['address'] ?? '', 300),
            'hotline' => str($body['hotline'] ?? '', 60),
            'zalo' => str($body['zalo'] ?? '', 150),
            'position' => int($body['position'] ?? 0),
        ];
    }

    public static function create(array $body): int
    {
        $b = self::readForm($body);
        if (!$b['name']) throw new UserError('Hãy nhập tên chi nhánh.');
        return DB::run('INSERT INTO branches (name, address, hotline, zalo, position, active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)', [
            $b['name'], $b['address'], $b['hotline'], $b['zalo'], $b['position'], D::now(),
        ])['insertId'];
    }

    public static function update($id, array $body): void
    {
        $b = self::readForm($body);
        if (!$b['name']) throw new UserError('Tên chi nhánh không được để trống.');
        DB::run('UPDATE branches SET name = ?, address = ?, hotline = ?, zalo = ?, position = ?, active = ? WHERE id = ?', [
            $b['name'], $b['address'], $b['hotline'], $b['zalo'], $b['position'], empty($body['active']) ? 0 : 1, int($id),
        ]);
    }

    public static function setPlace(int $id, ?string $placeId, ?string $url): void
    {
        DB::run('UPDATE branches SET place_id = ?, maps_url = ?, sync_status = NULL, sync_error = NULL WHERE id = ?', [$placeId, safeUrl($url) ?: null, $id]);
    }

    public static function parseExtra(?array $b): array
    {
        return json_decode_array($b['extra'] ?? null);
    }

    // Số liệu Google mới nhất của từng chi nhánh, kèm lần ghi trước để tính mức thay đổi
    public static function googleSnapshot(): array
    {
        $out = [];
        foreach (self::list() as $b) {
            $rows = DB::all(
                "SELECT recorded_on, rating, reviews FROM channel_stats WHERE channel = 'google' AND branch_id = ? AND (rating IS NOT NULL OR reviews IS NOT NULL)
                 ORDER BY recorded_on DESC, id DESC LIMIT 2",
                [$b['id']]
            );
            $out[] = ['latest' => $rows[0] ?? null, 'previous' => $rows[1] ?? null, 'extra' => self::parseExtra($b)] + $b;
        }
        return $out;
    }

    // Điểm trung bình cả chuỗi, tính theo số đánh giá của từng chi nhánh
    public static function chainRating(array $snap): array
    {
        $sum = 0;
        $count = 0;
        $reviews = 0;
        foreach ($snap as $b) {
            $reviews += (int) ($b['latest']['reviews'] ?? 0);
            if (!$b['latest'] || $b['latest']['rating'] === null) continue;
            $n = (int) $b['latest']['reviews'] ?: 1;
            $sum += (float) $b['latest']['rating'] * $n;
            $count += $n;
        }
        return [
            'rating' => $count ? round($sum / $count, 1) : null,
            'reviews' => $reviews,
            'branches' => count($snap),
            'tracked' => count(array_filter($snap, fn($b) => $b['place_id'])),
        ];
    }
}
