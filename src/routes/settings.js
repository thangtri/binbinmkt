const express = require('express');
const settings = require('../lib/settings');
const { requireAdmin } = require('../lib/auth');
const { h, flash, logActivity } = require('../lib/util');

const router = express.Router();
router.use(requireAdmin);

router.get(
  '/',
  h(async (req, res) => {
    res.render('settings', { title: 'Thông tin khách sạn', fields: settings.FIELDS, values: await settings.all() });
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

module.exports = router;
