<?php
// Ngày giờ được lưu dạng chuỗi "YYYY-MM-DD HH:MM:SS" theo giờ Việt Nam,
// nên so sánh chuỗi cũng là so sánh thời gian.

final class D
{
    const WEEKDAYS = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
    const WEEKDAYS_LONG = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];

    public static function today(): string
    {
        return date('Y-m-d');
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function isDate($s): bool
    {
        if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return false;
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    // Giờ "HH:MM" hợp lệ (00:00 – 23:59)
    public static function isTime($s): bool
    {
        return is_string($s) && (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s);
    }

    private static function utc(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable(substr($date, 0, 10) . ' 00:00:00', new DateTimeZone('UTC'));
    }

    public static function addDays(string $date, int $n): string
    {
        return self::utc($date)->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    // Cộng giây vào chuỗi "YYYY-MM-DD HH:MM:SS"
    public static function addSeconds(string $dt, $s): string
    {
        $d = new DateTimeImmutable($dt, new DateTimeZone('UTC'));
        $s = (int) $s;
        return $d->modify(($s >= 0 ? '+' : '') . $s . ' seconds')->format('Y-m-d H:i:s');
    }

    public static function diffDays(string $a, string $b): int
    {
        return (int) round((self::utc($b)->getTimestamp() - self::utc($a)->getTimestamp()) / 86400);
    }

    // Thứ Hai của tuần chứa ngày đã cho
    public static function startOfWeek(string $date): string
    {
        $dow = (int) self::utc($date)->format('w');
        return self::addDays($date, $dow === 0 ? -6 : 1 - $dow);
    }

    public static function weekday(string $date, bool $long = false): string
    {
        $i = (int) self::utc($date)->format('w');
        return ($long ? self::WEEKDAYS_LONG : self::WEEKDAYS)[$i];
    }

    // "12/10"
    public static function dm(?string $date): string
    {
        if (!$date) return '';
        return substr($date, 8, 2) . '/' . substr($date, 5, 2);
    }

    // "12/10/2026"
    public static function dmy(?string $date): string
    {
        if (!$date) return '';
        return self::dm($date) . '/' . substr($date, 0, 4);
    }

    // "12/10/2026 09:30"
    public static function dmyhm(?string $dt): string
    {
        if (!$dt) return '';
        return self::dmy($dt) . ' ' . substr($dt, 11, 5);
    }

    public static function hm(?string $dt): string
    {
        return $dt ? substr($dt, 11, 5) : '';
    }

    public static function monthLabel(int $year, int $month): string
    {
        return "Tháng $month/$year";
    }

    // Lưới tháng: các tuần (Thứ Hai → Chủ nhật) phủ kín tháng
    public static function monthGrid(int $year, int $month): array
    {
        $first = sprintf('%04d-%02d-01', $year, $month);
        $last = substr($first, 0, 8) . sprintf('%02d', self::daysInMonth(substr($first, 0, 7)));
        $cur = self::startOfWeek($first);
        $weeks = [];
        while ($cur <= $last) {
            $week = [];
            for ($i = 0; $i < 7; $i++) $week[] = self::addDays($cur, $i);
            $weeks[] = $week;
            $cur = self::addDays($cur, 7);
        }
        return $weeks;
    }

    // Số ngày của tháng "YYYY-MM"
    public static function daysInMonth(string $month): int
    {
        return (int) self::utc($month . '-01')->format('t');
    }

    // "3 ngày nữa", "hôm nay", "2 ngày trước"
    public static function relative(string $date): string
    {
        $d = self::diffDays(self::today(), substr($date, 0, 10));
        if ($d === 0) return 'hôm nay';
        if ($d === 1) return 'ngày mai';
        if ($d === -1) return 'hôm qua';
        return $d > 0 ? "$d ngày nữa" : -$d . ' ngày trước';
    }
}
