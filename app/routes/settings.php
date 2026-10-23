<?php

Router::get('/settings', function () {
    Auth::requireAdmin();
    render('settings', ['title' => 'Thông tin khách sạn', 'fields' => Settings::FIELDS, 'values' => Settings::all(), 'branchList' => Branches::list(true)]);
});

Router::post('/settings', function () {
    Auth::requireAdmin();
    Settings::save(body());
    logActivity(user(), 'settings.update', 'settings', null, 'Thông tin khách sạn');
    flash('success', 'Đã lưu. AI sẽ dùng thông tin mới từ lần viết tiếp theo.');
    redirect('/settings');
});

Router::post('/settings/branches', function () {
    Auth::requireAdmin();
    try {
        Branches::create(body());
        logActivity(user(), 'branch.create', 'branch', null, body('name'));
        flash('success', 'Đã thêm chi nhánh ' . str(body('name'), 150) . '. Vào Kênh → Google Maps để dán link Google Maps của chi nhánh.');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/settings#chi-nhanh');
});

Router::post('/settings/branches/{id}', function ($id) {
    Auth::requireAdmin();
    try {
        Branches::update($id, body());
        logActivity(user(), 'branch.update', 'branch', (int) $id, body('name'));
        flash('success', 'Đã lưu chi nhánh ' . str(body('name'), 150) . '.');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/settings#chi-nhanh');
});
