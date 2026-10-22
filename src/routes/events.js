const express = require('express');
const db = require('../db');
const dates = require('../lib/dates');
const { EVENT_KINDS } = require('../lib/constants');
const { requirePerm } = require('../lib/auth');
const { occurrences } = require('../lib/events');
const { h, flash, logActivity, str, int } = require('../lib/util');

const router = express.Router();

function readForm(body) {
  const start = dates.isDate(body.start_date) ? body.start_date : '';
  let end = dates.isDate(body.end_date) ? body.end_date : start;
  if (end && start && end < start) end = start;
  return {
    title: str(body.title, 200),
    kind: EVENT_KINDS[body.kind] ? body.kind : 'promo',
    start_date: start,
    end_date: end,
    recurring: body.recurring ? 1 : 0,
    description: str(body.description, 5000),
  };
}

function validate(ev) {
  if (!ev.title) return 'Hãy đặt tên sự kiện.';
  if (!ev.start_date) return 'Hãy chọn ngày bắt đầu.';
  if (dates.diffDays(ev.start_date, ev.end_date) > 366) return 'Sự kiện không dài quá một năm.';
  return null;
}

router.get(
  '/',
  h(async (req, res) => {
    const today = dates.today();
    const from = dates.isDate(req.query.from) ? req.query.from : today;
    const to = dates.addDays(from, 180);
    const list = await occurrences(from, to);
    for (const ev of list) {
      const r = await db.get(
        'SELECT COUNT(*) AS n FROM posts WHERE event_id = ? AND scheduled_at BETWEEN ? AND ?',
        [ev.id, dates.addDays(ev.occ_start, -30) + ' 00:00:00', ev.occ_end + ' 23:59:59']
      );
      ev.post_count = Number(r.n);
    }
    // Nhóm theo tháng để đọc như một cuốn lịch
    const months = [];
    for (const ev of list) {
      const key = ev.occ_start.slice(0, 7);
      let g = months[months.length - 1];
      if (!g || g.key !== key) months.push((g = { key, label: dates.monthLabel(Number(key.slice(0, 4)), Number(key.slice(5, 7))), items: [] }));
      g.items.push(ev);
    }
    const kind = EVENT_KINDS[req.query.kind] ? req.query.kind : '';
    if (kind) for (const g of months) g.items = g.items.filter((e) => e.kind === kind);
    res.render('events/index', { title: 'Sự kiện', months: months.filter((g) => g.items.length), from, to, kind, today });
  })
);

router.get('/new', requirePerm('event:edit'), (req, res) => {
  const start = dates.isDate(req.query.date) ? req.query.date : dates.today();
  res.render('events/form', {
    title: 'Thêm sự kiện',
    ev: { title: '', kind: 'promo', start_date: start, end_date: start, recurring: 0, description: '' },
    error: null,
  });
});

router.post(
  '/',
  requirePerm('event:edit'),
  h(async (req, res) => {
    const ev = readForm(req.body);
    const error = validate(ev);
    if (error) return res.status(400).render('events/form', { title: 'Thêm sự kiện', ev, error });
    const r = await db.run(
      'INSERT INTO events (title, kind, start_date, end_date, recurring, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
      [ev.title, ev.kind, ev.start_date, ev.end_date, ev.recurring, ev.description, req.user.id, dates.now()]
    );
    await logActivity(req.user, 'event.create', 'event', r.insertId, ev.title);
    flash(req, 'success', 'Đã thêm sự kiện.');
    res.redirect('/events?from=' + ev.start_date.slice(0, 8) + '01');
  })
);

async function load(id) {
  const ev = await db.get('SELECT * FROM events WHERE id = ?', [int(id)]);
  if (!ev) {
    const err = new Error('Không tìm thấy sự kiện.');
    err.status = 404;
    err.expose = true;
    throw err;
  }
  return ev;
}

router.get(
  '/:id/edit',
  requirePerm('event:edit'),
  h(async (req, res) => {
    const ev = await load(req.params.id);
    const posts = await db.all('SELECT id, title, channel, status, scheduled_at FROM posts WHERE event_id = ? ORDER BY scheduled_at DESC LIMIT 20', [ev.id]);
    res.render('events/form', { title: 'Sửa sự kiện', ev, posts, error: null });
  })
);

router.post(
  '/:id',
  requirePerm('event:edit'),
  h(async (req, res) => {
    const old = await load(req.params.id);
    const ev = readForm(req.body);
    const error = validate(ev);
    if (error) return res.status(400).render('events/form', { title: 'Sửa sự kiện', ev: { ...old, ...ev }, error });
    await db.run('UPDATE events SET title = ?, kind = ?, start_date = ?, end_date = ?, recurring = ?, description = ? WHERE id = ?', [
      ev.title,
      ev.kind,
      ev.start_date,
      ev.end_date,
      ev.recurring,
      ev.description,
      old.id,
    ]);
    await logActivity(req.user, 'event.update', 'event', old.id, ev.title);
    flash(req, 'success', 'Đã lưu sự kiện.');
    res.redirect('/events?from=' + ev.start_date.slice(0, 8) + '01');
  })
);

router.post(
  '/:id/delete',
  requirePerm('event:edit'),
  h(async (req, res) => {
    const ev = await load(req.params.id);
    await db.run('UPDATE posts SET event_id = NULL WHERE event_id = ?', [ev.id]);
    await db.run('DELETE FROM events WHERE id = ?', [ev.id]);
    await logActivity(req.user, 'event.delete', 'event', ev.id, ev.title);
    flash(req, 'success', 'Đã xoá sự kiện. Các bài liên quan vẫn được giữ.');
    res.redirect('/events');
  })
);

module.exports = router;
