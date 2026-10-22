const express = require('express');
const db = require('../db');
const dates = require('../lib/dates');
const offers = require('../lib/offers');
const branches = require('../lib/branches');
const { requirePerm } = require('../lib/auth');
const { h, flash, logActivity } = require('../lib/util');

const router = express.Router();

function notFound() {
  const err = new Error('Không tìm thấy ưu đãi này.');
  err.status = 404;
  err.expose = true;
  return err;
}

router.get(
  '/',
  h(async (req, res) => {
    res.render('offers/index', {
      title: 'Ưu đãi',
      list: await offers.list(),
      branchList: await branches.list(),
      today: dates.today(),
    });
  })
);

router.get('/new', requirePerm('offer:edit'), h(async (req, res) => {
  const start = dates.today();
  res.render('offers/form', {
    title: 'Thêm ưu đãi',
    o: { title: '', summary: '', details: '', terms: '', branch_ids: null, start_date: start, end_date: dates.addDays(start, 30), code_prefix: '', active: 1 },
    branchList: await branches.list(),
    error: null,
  });
}));

router.post(
  '/',
  requirePerm('offer:edit'),
  h(async (req, res) => {
    const r = await offers.create(req.body, req.user);
    if (r.error) return res.status(400).render('offers/form', { title: 'Thêm ưu đãi', o: r.form, branchList: await branches.list(), error: r.error });
    await logActivity(req.user, 'offer.create', 'offer', r.id, req.body.title);
    flash(req, 'success', 'Đã tạo ưu đãi và mã cho từng kênh.');
    res.redirect(`/offers/${r.id}`);
  })
);

// Lễ tân ghi nhận khách dùng mã (tra theo mã, không cần biết ưu đãi nào)
router.post(
  '/redeem',
  requirePerm('offer:redeem'),
  h(async (req, res) => {
    const r = await offers.redeem(req.body, req.user);
    if (r.error) flash(req, 'error', r.error);
    else {
      await logActivity(req.user, 'offer.redeem', 'offer', r.code.offer_id, `${r.code.code}: ${r.code.title}`);
      flash(req, r.warn ? 'warn' : 'success', `Đã ghi nhận mã ${r.code.code} (${r.code.title}).${r.warn ? ' ' + r.warn : ''}`);
    }
    res.redirect(req.body.back === 'offer' && r.code ? `/offers/${r.code.offer_id}` : '/offers');
  })
);

router.get(
  '/:id',
  h(async (req, res) => {
    const o = await offers.get(req.params.id);
    if (!o) throw notFound();
    const branchList = await branches.list({ all: true });
    const byBranch = await db.all(
      `SELECT b.name, COUNT(r.id) AS used, COALESCE(SUM(r.amount), 0) AS revenue FROM offer_redemptions r
       LEFT JOIN branches b ON b.id = r.branch_id WHERE r.offer_id = ? GROUP BY b.name ORDER BY used DESC`,
      [o.id]
    );
    const posts = await db.all('SELECT id, title, status, scheduled_at FROM posts WHERE offer_id = ? ORDER BY scheduled_at DESC', [o.id]);
    res.render('offers/show', {
      title: o.title,
      o,
      channelNames: Object.fromEntries(offers.CODE_CHANNELS.map((c) => [c.key, c.name])),
      branchNames: (o.branch_ids || '').split(',').filter(Boolean).map((id) => branchList.find((b) => b.id === Number(id))?.name).filter(Boolean),
      branchList: branchList.filter((b) => Number(b.active)),
      byBranch,
      posts,
      list: await offers.redemptions(o.id),
      today: dates.today(),
    });
  })
);

router.get(
  '/:id/edit',
  requirePerm('offer:edit'),
  h(async (req, res) => {
    const o = await offers.get(req.params.id);
    if (!o) throw notFound();
    res.render('offers/form', { title: 'Sửa ưu đãi', o, branchList: await branches.list(), error: null });
  })
);

router.post(
  '/:id',
  requirePerm('offer:edit'),
  h(async (req, res) => {
    const old = await offers.get(req.params.id);
    if (!old) throw notFound();
    const r = await offers.update(old.id, req.body);
    if (r.error) return res.status(400).render('offers/form', { title: 'Sửa ưu đãi', o: { ...old, ...r.form }, branchList: await branches.list(), error: r.error });
    await logActivity(req.user, 'offer.update', 'offer', old.id, req.body.title);
    flash(req, 'success', 'Đã lưu ưu đãi.');
    res.redirect(`/offers/${old.id}`);
  })
);

router.post(
  '/redemptions/:rid/delete',
  requirePerm('offer:redeem'),
  h(async (req, res) => {
    const r = await db.get('SELECT * FROM offer_redemptions WHERE id = ?', [Number(req.params.rid)]);
    if (r) await db.run('DELETE FROM offer_redemptions WHERE id = ?', [r.id]);
    flash(req, 'success', 'Đã xoá lượt dùng mã.');
    res.redirect(r ? `/offers/${r.offer_id}` : '/offers');
  })
);

module.exports = router;
