<?php

function reportMonth(?string $m): string
{
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $m) ? $m : substr(D::today(), 0, 7);
}

Router::get('/reports', function () {
    $month = reportMonth(q('m'));
    render('reports/month', [
        'title' => "Báo cáo tháng $month",
        'r' => Report::build($month),
        'prev' => Report::prevMonth($month),
        'next' => Report::nextMonth($month),
        'insight' => AI::latest("report-$month"),
        'aiReady' => AI::isConfigured(),
        'channelNames' => Offers::channelNames(),
    ]);
});

Router::get('/reports/{month}.csv', function ($month) {
    $month = reportMonth($month);
    Csv::download("binbin-bao-cao-$month.csv", Report::csvRows(Report::build($month)));
});

Router::post('/reports/{month}/ai', function ($month) {
    Auth::requirePerm('channel:edit');
    $month = reportMonth($month);
    try {
        AI::commentReport(Report::build($month), user());
        logActivity(user(), 'report.ai', 'report', null, "Nhận xét báo cáo tháng $month");
        flash('success', 'AI đã nhận xét báo cáo.');
    } catch (UserError $e) {
        flash('error', 'Chưa nhận xét được: ' . $e->getMessage());
    }
    redirect("/reports?m=$month");
});
