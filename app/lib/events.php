<?php

final class Events
{
    // Các lần diễn ra của sự kiện trong khoảng [from, to] (YYYY-MM-DD).
    // Sự kiện lặp lại hằng năm được chiếu sang từng năm trong khoảng.
    public static function occurrences(string $from, string $to): array
    {
        $rows = DB::all('SELECT * FROM events WHERE recurring = 1 OR (start_date <= ? AND end_date >= ?) ORDER BY start_date', [$to, $from]);
        $out = [];
        $y1 = (int) substr($from, 0, 4) - 1;
        $y2 = (int) substr($to, 0, 4);
        foreach ($rows as $ev) {
            $ev['id'] = (int) $ev['id'];
            if (!(int) $ev['recurring']) {
                $out[] = $ev + ['occ_start' => $ev['start_date'], 'occ_end' => $ev['end_date']];
                continue;
            }
            $length = D::diffDays($ev['start_date'], $ev['end_date']);
            for ($y = $y1; $y <= $y2; $y++) {
                $start = $y . substr($ev['start_date'], 4);
                if (!D::isDate($start)) continue; // 29/2 năm không nhuận
                $end = D::addDays($start, $length);
                if ($start <= $to && $end >= $from) $out[] = $ev + ['occ_start' => $start, 'occ_end' => $end];
            }
        }
        usort($out, fn($a, $b) => strcmp($a['occ_start'], $b['occ_start']));
        return $out;
    }
}
