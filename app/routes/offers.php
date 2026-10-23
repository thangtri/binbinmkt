<?php

function loadOffer($id): array
{
    $o = Offers::get($id);
    if (!$o) abort(404, 'Không tìm thấy ưu đãi này.');
    return $o;
}

Router::get('/offers', function () {
    render('offers/index', ['title' => 'Ưu đãi', 'list' => Offers::list(), 'branchList' => Branches::list(), 'today' => D::today()]);
});

Router::get('/offers/new', function () {
    Auth::requirePerm('offer:edit');
    $start = D::today();
    render('offers/form', [
        'title' => 'Thêm ưu đãi',
        'o' => ['id' => null, 'title' => '', 'summary' => '', 'details' => '', 'terms' => '', 'branch_ids' => null, 'start_date' => $start, 'end_date' => D::addDays($start, 30), 'code_prefix' => '', 'active' => 1],
        'branchList' => Branches::list(),
        'error' => null,
    ]);
});

Router::post('/offers', function () {
    Auth::requirePerm('offer:edit');
    $r = Offers::create(body(), user());
    if (isset($r['error'])) render('offers/form', ['title' => 'Thêm ưu đãi', 'o' => $r['form'] + ['id' => null], 'branchList' => Branches::list(), 'error' => $r['error']], 400);
    logActivity(user(), 'offer.create', 'offer', $r['id'], body('title'));
    flash('success', 'Đã tạo ưu đãi và mã cho từng kênh.');
    redirect("/offers/{$r['id']}");
});

// Lễ tân ghi nhận khách dùng mã (tra theo mã, không cần biết ưu đãi nào)
Router::post('/offers/redeem', function () {
    Auth::requirePerm('offer:redeem');
    $r = Offers::redeem(body(), user());
    if (isset($r['error'])) flash('error', $r['error']);
    else {
        logActivity(user(), 'offer.redeem', 'offer', $r['code']['offer_id'], "{$r['code']['code']}: {$r['code']['title']}");
        flash($r['warn'] ? 'warn' : 'success', "Đã ghi nhận mã {$r['code']['code']} ({$r['code']['title']})." . ($r['warn'] ? ' ' . $r['warn'] : ''));
    }
    redirect(body('back') === 'offer' && isset($r['code']) ? "/offers/{$r['code']['offer_id']}" : '/offers');
});

Router::get('/offers/{id}', function ($id) {
    $o = loadOffer($id);
    $branchList = Branches::list(true);
    $byId = array_column($branchList, 'name', 'id');
    $branchNames = array_values(array_filter(array_map(fn($bid) => $byId[(int) $bid] ?? null, array_filter(explode(',', (string) $o['branch_ids'])))));
    render('offers/show', [
        'title' => $o['title'],
        'o' => $o,
        'channelNames' => Offers::channelNames(),
        'branchNames' => $branchNames,
        'branchList' => array_values(array_filter($branchList, fn($b) => (int) $b['active'])),
        'byBranch' => DB::all(
            'SELECT b.name, COUNT(r.id) AS used, COALESCE(SUM(r.amount), 0) AS revenue FROM offer_redemptions r
             LEFT JOIN branches b ON b.id = r.branch_id WHERE r.offer_id = ? GROUP BY b.name ORDER BY used DESC',
            [$o['id']]
        ),
        'posts' => DB::all('SELECT id, title, status, scheduled_at FROM posts WHERE offer_id = ? ORDER BY scheduled_at DESC', [$o['id']]),
        'list' => Offers::redemptions($o['id']),
        'today' => D::today(),
    ]);
});

Router::get('/offers/{id}/edit', function ($id) {
    Auth::requirePerm('offer:edit');
    render('offers/form', ['title' => 'Sửa ưu đãi', 'o' => loadOffer($id), 'branchList' => Branches::list(), 'error' => null]);
});

Router::post('/offers/{id}', function ($id) {
    Auth::requirePerm('offer:edit');
    $old = loadOffer($id);
    $r = Offers::update($old['id'], body());
    if (isset($r['error'])) render('offers/form', ['title' => 'Sửa ưu đãi', 'o' => $r['form'] + $old, 'branchList' => Branches::list(), 'error' => $r['error']], 400);
    logActivity(user(), 'offer.update', 'offer', $old['id'], body('title'));
    flash('success', 'Đã lưu ưu đãi.');
    redirect("/offers/{$old['id']}");
});

Router::post('/offers/redemptions/{rid}/delete', function ($rid) {
    Auth::requirePerm('offer:redeem');
    $r = DB::get('SELECT * FROM offer_redemptions WHERE id = ?', [(int) $rid]);
    if ($r) DB::run('DELETE FROM offer_redemptions WHERE id = ?', [$r['id']]);
    flash('success', 'Đã xoá lượt dùng mã.');
    redirect($r ? "/offers/{$r['offer_id']}" : '/offers');
});
