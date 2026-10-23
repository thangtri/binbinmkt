<?php
// Báo cáo marketing một tháng: bài đăng, kênh, Google theo chi nhánh, đối thủ, email, ưu đãi.

final class Report
{
    public static function monthRange(string $month): array
    {
        return ['from' => "$month-01", 'to' => $month . '-' . sprintf('%02d', D::daysInMonth($month))];
    }

    public static function prevMonth(string $month): string
    {
        [$y, $m] = [(int) substr($month, 0, 4), (int) substr($month, 5, 2)];
        return $m === 1 ? ($y - 1) . '-12' : sprintf('%04d-%02d', $y, $m - 1);
    }

    public static function nextMonth(string $month): string
    {
        [$y, $m] = [(int) substr($month, 0, 4), (int) substr($month, 5, 2)];
        return $m === 12 ? ($y + 1) . '-01' : sprintf('%04d-%02d', $y, $m + 1);
    }

    // Giá trị đầu kỳ (lần ghi cuối trước tháng, hoặc đầu tháng) và cuối kỳ của một chỉ số
    private static function startEnd(string $where, array $params, string $col, string $from, string $to): array
    {
        $start = DB::get("SELECT $col AS v FROM channel_stats WHERE $where AND $col IS NOT NULL AND recorded_on < ? ORDER BY recorded_on DESC, id DESC LIMIT 1", [...$params, $from])
            ?: DB::get("SELECT $col AS v FROM channel_stats WHERE $where AND $col IS NOT NULL AND recorded_on BETWEEN ? AND ? ORDER BY recorded_on, id LIMIT 1", [...$params, $from, $to]);
        $end = DB::get("SELECT $col AS v FROM channel_stats WHERE $where AND $col IS NOT NULL AND recorded_on <= ? ORDER BY recorded_on DESC, id DESC LIMIT 1", [...$params, $to]);
        $s = $start ? (float) $start['v'] : null;
        $e = $end ? (float) $end['v'] : null;
        return ['start' => $s, 'end' => $e, 'change' => $s !== null && $e !== null ? round($e - $s, 2) : null];
    }

    public static function build(string $month): array
    {
        ['from' => $from, 'to' => $to] = self::monthRange($month);
        $F = "$from 00:00:00";
        $T = "$to 23:59:59";

        $status = [];
        foreach (DB::all('SELECT status, COUNT(*) AS n FROM posts WHERE scheduled_at BETWEEN ? AND ? GROUP BY status', [$F, $T]) as $r) $status[$r['status']] = (int) $r['n'];
        $published = (int) DB::value("SELECT COUNT(*) FROM posts WHERE status = 'published' AND published_at BETWEEN ? AND ?", [$F, $T]);

        $top = array_map(fn($p) => $p + ['interactions' => (int) $p['likes'] + (int) $p['comments'] + (int) $p['shares']], DB::all(
            "SELECT id, title, format, published_at, views, likes, comments, shares FROM posts
             WHERE status = 'published' AND published_at BETWEEN ? AND ? ORDER BY (likes + comments + shares) DESC, views DESC LIMIT 5",
            [$F, $T]
        ));

        $channels = [];
        foreach (C::CHANNELS as $ch) {
            if ($ch['key'] === 'google') continue;
            $perf = DB::get(
                "SELECT COUNT(*) AS posts, COALESCE(SUM(pc.views), 0) AS views,
                        COALESCE(SUM(COALESCE(pc.likes, 0) + COALESCE(pc.comments, 0) + COALESCE(pc.shares, 0)), 0) AS interactions
                 FROM posts p JOIN post_channels pc ON pc.post_id = p.id AND pc.channel = ?
                 WHERE p.status = 'published' AND p.published_at BETWEEN ? AND ?",
                [$ch['key'], $F, $T]
            );
            $channels[] = [
                'key' => $ch['key'],
                'name' => $ch['name'],
                'followers' => self::startEnd('channel = ?', [$ch['key']], 'followers', $from, $to),
                'posts' => (int) $perf['posts'],
                'views' => (int) $perf['views'],
                'interactions' => (int) $perf['interactions'],
            ];
        }

        $branches = [];
        foreach (Branches::list() as $b) {
            $branches[] = [
                'name' => $b['name'],
                'rating' => self::startEnd("channel = 'google' AND branch_id = ?", [$b['id']], 'rating', $from, $to),
                'reviews' => self::startEnd("channel = 'google' AND branch_id = ?", [$b['id']], 'reviews', $from, $to),
            ];
        }

        $rivals = [];
        foreach (DB::all('SELECT c.*, b.name AS branch_name FROM competitors c LEFT JOIN branches b ON b.id = c.branch_id WHERE c.active = 1 ORDER BY b.position, c.name') as $c) {
            $end = DB::get('SELECT rating, reviews FROM competitor_stats WHERE competitor_id = ? AND recorded_on <= ? ORDER BY recorded_on DESC, id DESC LIMIT 1', [$c['id'], $to]);
            $start = DB::get('SELECT reviews FROM competitor_stats WHERE competitor_id = ? AND recorded_on < ? ORDER BY recorded_on DESC, id DESC LIMIT 1', [$c['id'], $from]);
            $rivals[] = [
                'name' => $c['name'],
                'branch' => $c['branch_name'],
                'rating' => $end['rating'] ?? null,
                'reviews' => $end['reviews'] ?? null,
                'newReviews' => $start && $end ? (int) $end['reviews'] - (int) $start['reviews'] : null,
            ];
        }

        $emails = array_map(fn($e) => ['sent' => (int) $e['sent'], 'opened' => (int) $e['opened'], 'clicked' => (int) $e['clicked']] + $e, DB::all(
            "SELECT c.id, c.name, c.sent_at,
                    (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'sent') AS sent,
                    (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.opened_at IS NOT NULL) AS opened,
                    (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.clicked_at IS NOT NULL) AS clicked
             FROM campaigns c WHERE c.status = 'sent' AND c.sent_at BETWEEN ? AND ? ORDER BY c.sent_at",
            [$F, $T]
        ));

        $offers = array_map(fn($o) => ['used' => (int) $o['used'], 'revenue' => (int) $o['revenue'], 'nights' => (int) $o['nights']] + $o, DB::all(
            'SELECT o.title, r.channel, COUNT(*) AS used, COALESCE(SUM(r.amount), 0) AS revenue, COALESCE(SUM(r.nights), 0) AS nights
             FROM offer_redemptions r JOIN offers o ON o.id = r.offer_id WHERE r.used_on BETWEEN ? AND ? GROUP BY o.title, r.channel ORDER BY o.title, used DESC',
            [$from, $to]
        ));

        return [
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'posts' => ['status' => $status, 'published' => $published, 'top' => $top],
            'channels' => $channels,
            'branches' => $branches,
            'rivals' => $rivals,
            'emails' => $emails,
            'offers' => $offers,
        ];
    }

    // Xuất CSV nhiều phần (mở được bằng Excel, giữ dấu tiếng Việt)
    public static function csvRows(array $r): array
    {
        $codeNames = Offers::channelNames();
        $rows = [["Báo cáo marketing Bin Bin Hotel tháng {$r['month']}"], [], ['BÀI ĐĂNG'], ['Đã đăng trong tháng', $r['posts']['published']]];
        foreach ($r['posts']['status'] as $k => $v) $rows[] = ["Lên lịch trong tháng: $k", $v];
        $rows[] = [];
        $rows[] = ['BÀI HIỆU QUẢ NHẤT', 'Ngày đăng', 'Lượt xem', 'Tương tác'];
        foreach ($r['posts']['top'] as $p) $rows[] = [$p['title'], substr((string) $p['published_at'], 0, 10), $p['views'], $p['interactions']];
        $rows[] = [];
        $rows[] = ['KÊNH', 'Người theo dõi đầu kỳ', 'Cuối kỳ', 'Tăng/giảm', 'Bài đã đăng', 'Lượt xem', 'Tương tác'];
        foreach ($r['channels'] as $c) $rows[] = [$c['name'], $c['followers']['start'], $c['followers']['end'], $c['followers']['change'], $c['posts'], $c['views'], $c['interactions']];
        $rows[] = [];
        $rows[] = ['GOOGLE MAPS THEO CHI NHÁNH', 'Điểm đầu kỳ', 'Điểm cuối kỳ', 'Số đánh giá đầu kỳ', 'Cuối kỳ', 'Đánh giá mới'];
        foreach ($r['branches'] as $b) $rows[] = [$b['name'], $b['rating']['start'], $b['rating']['end'], $b['reviews']['start'], $b['reviews']['end'], $b['reviews']['change']];
        $rows[] = [];
        $rows[] = ['ĐỐI THỦ', 'So với chi nhánh', 'Điểm', 'Số đánh giá', 'Đánh giá mới trong tháng'];
        foreach ($r['rivals'] as $c) $rows[] = [$c['name'], $c['branch'], $c['rating'], $c['reviews'], $c['newReviews']];
        $rows[] = [];
        $rows[] = ['EMAIL', 'Ngày gửi', 'Đã gửi', 'Mở', 'Nhấp'];
        foreach ($r['emails'] as $e) $rows[] = [$e['name'], substr((string) $e['sent_at'], 0, 10), $e['sent'], $e['opened'], $e['clicked']];
        $rows[] = [];
        $rows[] = ['ƯU ĐÃI', 'Kênh', 'Lượt dùng mã', 'Số đêm', 'Doanh thu'];
        foreach ($r['offers'] as $o) $rows[] = [$o['title'], $codeNames[$o['channel']] ?? $o['channel'], $o['used'], $o['nights'], $o['revenue']];
        return $rows;
    }
}
