<?php

const BOARD_DAYS = 14;

// Xếp sự kiện chồng nhau vào các làn, giống xếp lượt khách vào phòng
function assignLanes(array &$items): int
{
    $lanes = [];
    foreach ($items as &$it) {
        $lane = null;
        foreach ($lanes as $i => $end) {
            if ($end < $it['col']) {
                $lane = $i;
                break;
            }
        }
        if ($lane === null) {
            $lane = count($lanes);
            $lanes[] = 0;
        }
        $lanes[$lane] = $it['col'] + $it['span'] - 1;
        $it['lane'] = $lane;
    }
    return count($lanes);
}

function postsBetween(string $from, string $to): array
{
    return DB::all('SELECT id, title, status, scheduled_at, format FROM posts WHERE scheduled_at BETWEEN ? AND ? ORDER BY scheduled_at', ["$from 00:00:00", "$to 23:59:59"]);
}

Router::get('/calendar', function () {
    $today = D::today();
    $view = q('view') === 'month' ? 'month' : 'board';

    if ($view === 'board') {
        $start = D::startOfWeek(D::isDate(q('start')) ? q('start') : $today);
        $end = D::addDays($start, BOARD_DAYS - 1);
        $days = array_map(fn($i) => D::addDays($start, $i), range(0, BOARD_DAYS - 1));
        $events = array_map(function ($ev) use ($start, $end) {
            $s = max($ev['occ_start'], $start);
            $e = min($ev['occ_end'], $end);
            return $ev + ['col' => D::diffDays($start, $s) + 1, 'span' => D::diffDays($s, $e) + 1, 'clippedStart' => $ev['occ_start'] < $start, 'clippedEnd' => $ev['occ_end'] > $end];
        }, Events::occurrences($start, $end));
        $eventLanes = assignLanes($events);
        $cells = [];
        foreach (postsBetween($start, $end) as $p) $cells[substr($p['scheduled_at'], 0, 10)][] = $p;
        render('calendar', [
            'title' => 'Lịch đăng bài', 'view' => $view, 'today' => $today, 'days' => $days, 'start' => $start, 'end' => $end,
            'prev' => D::addDays($start, -7), 'next' => D::addDays($start, 7), 'events' => $events, 'eventLanes' => $eventLanes, 'cells' => $cells,
        ]);
    }

    $m = preg_match('/^\d{4}-\d{2}$/', (string) q('m')) ? q('m') : substr($today, 0, 7);
    $year = (int) substr($m, 0, 4);
    $month = (int) substr($m, 5, 2);
    if ($month < 1 || $month > 12) [$m, $year, $month] = [substr($today, 0, 7), (int) substr($today, 0, 4), (int) substr($today, 5, 2)];
    $weeks = D::monthGrid($year, $month);
    $from = $weeks[0][0];
    $to = end($weeks)[6];
    $byDay = [];
    foreach (postsBetween($from, $to) as $p) $byDay[substr($p['scheduled_at'], 0, 10)][] = $p;
    $eventsByDay = [];
    foreach (Events::occurrences($from, $to) as $ev) {
        for ($dd = max($ev['occ_start'], $from); $dd <= $ev['occ_end'] && $dd <= $to; $dd = D::addDays($dd, 1)) $eventsByDay[$dd][] = $ev;
    }
    render('calendar', [
        'title' => 'Lịch đăng bài', 'view' => $view, 'today' => $today, 'weeks' => $weeks, 'year' => $year, 'month' => $month, 'm' => $m,
        'prevM' => Report::prevMonth($m), 'nextM' => Report::nextMonth($m), 'byDay' => $byDay, 'eventsByDay' => $eventsByDay,
    ]);
});
