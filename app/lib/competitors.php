<?php
// Đối thủ trên Google Maps: vài khách sạn gần mỗi chi nhánh, lấy điểm và số đánh giá mỗi ngày
// để so sánh với chi nhánh theo thời gian.

final class Competitors
{
    private static function upsertStat(int $competitorId, array $s): void
    {
        $today = D::today();
        $existing = DB::get('SELECT id FROM competitor_stats WHERE competitor_id = ? AND recorded_on = ?', [$competitorId, $today]);
        if ($existing) DB::run('UPDATE competitor_stats SET rating = ?, reviews = ?, created_at = ? WHERE id = ?', [$s['rating'] ?? null, $s['reviews'] ?? null, D::now(), $existing['id']]);
        else DB::run('INSERT INTO competitor_stats (competitor_id, recorded_on, rating, reviews, created_at) VALUES (?, ?, ?, ?, ?)', [$competitorId, $today, $s['rating'] ?? null, $s['reviews'] ?? null, D::now()]);
    }

    public static function syncOne(array $c): bool
    {
        try {
            $s = GoogleConnector::fetchStats(['external_id' => $c['place_id']]);
            self::upsertStat((int) $c['id'], $s);
            DB::run("UPDATE competitors SET sync_status = 'ok', sync_error = NULL, last_synced_at = ?, extra = ?, name = COALESCE(NULLIF(?, ''), name) WHERE id = ?", [
                D::now(),
                json_encode(['profile' => $s['profile'] ?? [], 'recentReviews' => $s['recentReviews'] ?? []], JSON_UNESCAPED_UNICODE),
                $s['profile']['name'] ?? '',
                $c['id'],
            ]);
            return true;
        } catch (Throwable $e) {
            DB::run("UPDATE competitors SET sync_status = 'error', sync_error = ?, last_synced_at = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), D::now(), $c['id']]);
            return false;
        }
    }

    public static function add($branchId, string $link): array
    {
        $placeId = GoogleConnector::resolveLink($link);
        $bid = int($branchId) ?: null;
        if (DB::get('SELECT id FROM competitors WHERE place_id = ? AND branch_id = ? AND active = 1', [$placeId, $bid])) {
            throw new UserError('Đối thủ này đã có trong danh sách của chi nhánh.');
        }
        $id = DB::run('INSERT INTO competitors (branch_id, name, maps_url, place_id, active, created_at) VALUES (?, ?, ?, ?, 1, ?)', [$bid, 'Đang lấy tên…', safeUrl($link) ?: null, $placeId, D::now()])['insertId'];
        self::syncOne(DB::get('SELECT * FROM competitors WHERE id = ?', [$id]));
        return DB::get('SELECT * FROM competitors WHERE id = ?', [$id]);
    }

    public static function remove($id): void
    {
        DB::run('UPDATE competitors SET active = 0 WHERE id = ?', [int($id)]);
    }

    public static function syncAll(): int
    {
        $ok = 0;
        foreach (DB::all('SELECT * FROM competitors WHERE active = 1 AND place_id IS NOT NULL') as $c) if (self::syncOne($c)) $ok++;
        return $ok;
    }

    // Số liệu mới nhất và số đánh giá tăng thêm trong 30 ngày (so với lần ghi gần nhất trước đó 30 ngày)
    private static function trend(string $table, string $keyCol, int $id): array
    {
        $latest = DB::get("SELECT recorded_on, rating, reviews FROM $table WHERE $keyCol = ? AND (rating IS NOT NULL OR reviews IS NOT NULL) ORDER BY recorded_on DESC, id DESC LIMIT 1", [$id]);
        if (!$latest) return ['latest' => null, 'growth30' => null, 'since' => null];
        $base = DB::get("SELECT reviews FROM $table WHERE $keyCol = ? AND reviews IS NOT NULL AND recorded_on <= ? ORDER BY recorded_on DESC, id DESC LIMIT 1", [$id, D::addDays($latest['recorded_on'], -30)]);
        $first = $base ?: DB::get("SELECT reviews, recorded_on FROM $table WHERE $keyCol = ? AND reviews IS NOT NULL ORDER BY recorded_on, id LIMIT 1", [$id]);
        $growth = $first && $latest['reviews'] !== null && $first['reviews'] !== null ? (int) $latest['reviews'] - (int) $first['reviews'] : null;
        return ['latest' => $latest, 'growth30' => $growth, 'since' => $base ? '30 ngày' : ($first['recorded_on'] ?? null)];
    }

    // Bảng so sánh theo từng chi nhánh: chi nhánh ở dòng đầu, đối thủ bên dưới
    public static function comparison(): array
    {
        $out = [];
        foreach (Branches::list() as $b) {
            $rivals = [];
            foreach (DB::all('SELECT * FROM competitors WHERE active = 1 AND branch_id = ? ORDER BY name', [$b['id']]) as $c) {
                $rivals[] = self::trend('competitor_stats', 'competitor_id', (int) $c['id']) + $c;
            }
            $out[] = ['branch' => $b, 'own' => self::trend('channel_stats', "channel = 'google' AND branch_id", $b['id']), 'rivals' => $rivals];
        }
        return $out;
    }
}
