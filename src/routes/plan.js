// AI lập kế hoạch nội dung cả tháng → team chọn bài → tạo bài nháp hàng loạt
const express = require('express');
const db = require('../db');
const dates = require('../lib/dates');
const ai = require('../lib/ai');
const postChannels = require('../lib/post-channels');
const { POST_FORMATS } = require('../lib/constants');
const { requirePerm } = require('../lib/auth');
const { h, flash, logActivity, str, int } = require('../lib/util');

const router = express.Router();
router.use(requirePerm('post:create'));

function nextMonth() {
  const t = dates.today();
  const y = Number(t.slice(0, 4));
  const m = Number(t.slice(5, 7));
  // Sau ngày 20 thì mặc định lập cho tháng sau
  if (Number(t.slice(8, 10)) < 20) return t.slice(0, 7);
  return m === 12 ? `${y + 1}-01` : `${y}-${String(m + 1).padStart(2, '0')}`;
}

router.get(
  '/',
  h(async (req, res) => {
    const plans = await db.all('SELECT p.id, p.month, p.brief, p.created_at, u.name AS user_name FROM ai_plans p LEFT JOIN users u ON u.id = p.created_by ORDER BY p.id DESC LIMIT 10');
    res.render('plan/index', { title: 'Kế hoạch tháng bằng AI', month: nextMonth(), plans, aiReady: ai.isConfigured(), error: null });
  })
);

router.post(
  '/',
  h(async (req, res) => {
    const month = /^\d{4}-\d{2}$/.test(req.body.month || '') ? req.body.month : nextMonth();
    const perWeek = Math.max(1, Math.min(10, int(req.body.per_week, 3)));
    const focus = str(req.body.focus, 1000);
    try {
      const plan = await ai.planMonth({ month, perWeek, focus });
      const r = await db.run('INSERT INTO ai_plans (month, brief, items, created_by, created_at) VALUES (?, ?, ?, ?, ?)', [
        month,
        focus || null,
        JSON.stringify(plan),
        req.user.id,
        dates.now(),
      ]);
      await logActivity(req.user, 'plan.create', 'plan', r.insertId, `Kế hoạch tháng ${month}`);
      res.redirect(`/plan/${r.insertId}`);
    } catch (err) {
      const plans = await db.all('SELECT p.id, p.month, p.brief, p.created_at, u.name AS user_name FROM ai_plans p LEFT JOIN users u ON u.id = p.created_by ORDER BY p.id DESC LIMIT 10');
      res.status(502).render('plan/index', { title: 'Kế hoạch tháng bằng AI', month, plans, aiReady: ai.isConfigured(), error: err.message });
    }
  })
);

async function loadPlan(id) {
  const row = await db.get('SELECT * FROM ai_plans WHERE id = ?', [int(id)]);
  if (!row) {
    const err = new Error('Không tìm thấy kế hoạch này.');
    err.status = 404;
    err.expose = true;
    throw err;
  }
  return { ...row, plan: JSON.parse(row.items) };
}

router.get(
  '/:id',
  h(async (req, res) => {
    const p = await loadPlan(req.params.id);
    res.render('plan/show', { title: `Kế hoạch tháng ${p.month}`, p, today: dates.today() });
  })
);

// Tạo bài nháp từ các dòng được chọn (pick_<i>=1), ngày giờ có thể sửa (date_<i>, time_<i>)
router.post(
  '/:id/create',
  h(async (req, res) => {
    const p = await loadPlan(req.params.id);
    const now = dates.now();
    let n = 0;
    for (const [i, it] of p.plan.items.entries()) {
      if (!req.body[`pick_${i}`]) continue;
      const date = dates.isDate(req.body[`date_${i}`]) ? req.body[`date_${i}`] : it.date;
      const time = /^\d{2}:\d{2}$/.test(req.body[`time_${i}`] || '') ? req.body[`time_${i}`] : it.time;
      const r = await db.run(
        `INSERT INTO posts (title, channel, format, caption, hashtags, scheduled_at, status, event_id, offer_id, author_id, notes, auto_publish, created_at, updated_at)
         VALUES (?, 'all', ?, ?, ?, ?, 'draft', ?, ?, ?, ?, 1, ?, ?)`,
        [
          str(it.title, 200),
          POST_FORMATS.includes(it.format) ? it.format : POST_FORMATS[0],
          str(it.caption, 60000),
          str(it.hashtags, 2000),
          `${date} ${time}:00`,
          it.event_id || null,
          it.offer_id || null,
          req.user.id,
          `Từ kế hoạch AI tháng ${p.month}: ${str(it.why, 300)}`,
          now,
          now,
        ]
      );
      await postChannels.ensure(r.insertId);
      n++;
    }
    if (!n) {
      flash(req, 'error', 'Hãy chọn ít nhất một bài.');
      return res.redirect(`/plan/${p.id}`);
    }
    await logActivity(req.user, 'plan.apply', 'plan', p.id, `Tạo ${n} bài nháp từ kế hoạch tháng ${p.month}`);
    flash(req, 'success', `Đã tạo ${n} bài nháp trên lịch tháng ${p.month}. Thêm ảnh và gửi duyệt từng bài.`);
    res.redirect(`/calendar?view=month&m=${p.month}`);
  })
);

module.exports = router;
