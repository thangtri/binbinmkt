const express = require('express');
const settings = require('../lib/settings');
const branches = require('../lib/branches');
const { requireAdmin } = require('../lib/auth');
const { h, flash, logActivity } = require('../lib/util');

const router = express.Router();
router.use(requireAdmin);

router.get(
  '/',
  h(async (req, res) => {
    res.render('settings', {
      title: 'Thông tin khách sạn',
      fields: settings.FIELDS,
      values: await settings.all(),
      branchList: await branches.list({ all: true }),
    });
  })
);

router.post(
  '/',
  h(async (req, res) => {
    await settings.save(req.body);
    await logActivity(req.user, 'settings.update', 'settings', null, 'Thông tin khách sạn');
    flash(req, 'success', 'Đã lưu. AI sẽ dùng thông tin mới từ lần viết tiếp theo.');
    res.redirect('/settings');
  })
);

router.post(
  '/branches',
  h(async (req, res) => {
    try {
      await branches.create(req.body);
      await logActivity(req.user, 'branch.create', 'branch', null, req.body.name);
      flash(req, 'success', `Đã thêm chi nhánh ${req.body.name}. Vào Kênh → Google Maps để dán link Google Maps của chi nhánh.`);
    } catch (err) {
      flash(req, 'error', err.message);
    }
    res.redirect('/settings#chi-nhanh');
  })
);

router.post(
  '/branches/:id',
  h(async (req, res) => {
    try {
      await branches.update(req.params.id, req.body);
      await logActivity(req.user, 'branch.update', 'branch', Number(req.params.id), req.body.name);
      flash(req, 'success', `Đã lưu chi nhánh ${req.body.name}.`);
    } catch (err) {
      flash(req, 'error', err.message);
    }
    res.redirect('/settings#chi-nhanh');
  })
);

module.exports = router;
