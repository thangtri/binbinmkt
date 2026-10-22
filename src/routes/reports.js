const express = require('express');
const dates = require('../lib/dates');
const report = require('../lib/report');
const ai = require('../lib/ai');
const { requirePerm } = require('../lib/auth');
const { h, flash, logActivity } = require('../lib/util');

const router = express.Router();

function monthOf(req) {
  const m = req.params.month || req.query.m;
  return /^\d{4}-\d{2}$/.test(m || '') ? m : dates.today().slice(0, 7);
}

router.get(
  '/',
  h(async (req, res) => {
    const month = monthOf(req);
    res.render('reports/month', {
      title: `Báo cáo tháng ${month}`,
      r: await report.build(month),
      prev: report.prevMonth(month),
      next: report.nextMonth(month),
      insight: await ai.latest(`report-${month}`),
      aiReady: ai.isConfigured(),
      channelNames: Object.fromEntries(require('../lib/offers').CODE_CHANNELS.map((c) => [c.key, c.name])),
    });
  })
);

router.get(
  '/:month.csv',
  h(async (req, res) => {
    const month = monthOf(req);
    res.set('content-type', 'text/csv; charset=utf-8');
    res.set('content-disposition', `attachment; filename="binbin-bao-cao-${month}.csv"`);
    res.send(report.toCsv(await report.build(month)));
  })
);

router.post(
  '/:month/ai',
  requirePerm('channel:edit'),
  h(async (req, res) => {
    const month = monthOf(req);
    try {
      await ai.commentReport(await report.build(month), req.user);
      await logActivity(req.user, 'report.ai', 'report', null, `Nhận xét báo cáo tháng ${month}`);
      flash(req, 'success', 'AI đã nhận xét báo cáo.');
    } catch (err) {
      flash(req, 'error', `Chưa nhận xét được: ${err.message}`);
    }
    res.redirect(`/reports?m=${month}`);
  })
);

module.exports = router;
