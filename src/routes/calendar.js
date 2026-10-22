const express = require('express');
const db = require('../db');
const dates = require('../lib/dates');
const { occurrences } = require('../lib/events');
const { h } = require('../lib/util');

const router = express.Router();
const BOARD_DAYS = 14;

// Xếp sự kiện chồng nhau vào các làn, giống xếp lượt khách vào phòng
function assignLanes(items) {
  const lanes = [];
  for (const it of items) {
    let lane = lanes.findIndex((end) => end < it.col);
    if (lane === -1) {
      lane = lanes.length;
      lanes.push(0);
    }
    lanes[lane] = it.col + it.span - 1;
    it.lane = lane;
  }
  return lanes.length;
}

async function postsBetween(from, to) {
  return db.all(
    `SELECT id, title, status, scheduled_at, format FROM posts
     WHERE scheduled_at BETWEEN ? AND ? ORDER BY scheduled_at`,
    [from + ' 00:00:00', to + ' 23:59:59']
  );
}

router.get(
  '/',
  h(async (req, res) => {
    const today = dates.today();
    const view = req.query.view === 'month' ? 'month' : 'board';

    if (view === 'board') {
      const start = dates.startOfWeek(dates.isDate(req.query.start) ? req.query.start : today);
      const end = dates.addDays(start, BOARD_DAYS - 1);
      const days = Array.from({ length: BOARD_DAYS }, (_, i) => dates.addDays(start, i));

      const events = (await occurrences(start, end)).map((ev) => {
        const s = ev.occ_start < start ? start : ev.occ_start;
        const e = ev.occ_end > end ? end : ev.occ_end;
        return { ...ev, col: dates.diffDays(start, s) + 1, span: dates.diffDays(s, e) + 1, clippedStart: ev.occ_start < start, clippedEnd: ev.occ_end > end };
      });
      const eventLanes = assignLanes(events);

      const cells = {};
      for (const p of await postsBetween(start, end)) {
        const key = p.scheduled_at.slice(0, 10);
        (cells[key] = cells[key] || []).push(p);
      }

      return res.render('calendar', {
        title: 'Lịch đăng bài',
        view,
        today,
        days,
        start,
        end,
        prev: dates.addDays(start, -7),
        next: dates.addDays(start, 7),
        events,
        eventLanes,
        cells,
      });
    }

    const m = /^\d{4}-\d{2}$/.test(req.query.m || '') ? req.query.m : today.slice(0, 7);
    const year = Number(m.slice(0, 4));
    const month = Number(m.slice(5, 7));
    const weeks = dates.monthGrid(year, month);
    const from = weeks[0][0];
    const to = weeks[weeks.length - 1][6];

    const byDay = {};
    for (const p of await postsBetween(from, to)) {
      const k = p.scheduled_at.slice(0, 10);
      (byDay[k] = byDay[k] || []).push(p);
    }
    const eventsByDay = {};
    for (const ev of await occurrences(from, to)) {
      for (let dd = ev.occ_start < from ? from : ev.occ_start; dd <= ev.occ_end && dd <= to; dd = dates.addDays(dd, 1)) {
        (eventsByDay[dd] = eventsByDay[dd] || []).push(ev);
      }
    }

    const prevM = month === 1 ? `${year - 1}-12` : `${year}-${String(month - 1).padStart(2, '0')}`;
    const nextM = month === 12 ? `${year + 1}-01` : `${year}-${String(month + 1).padStart(2, '0')}`;
    res.render('calendar', {
      title: 'Lịch đăng bài',
      view,
      today,
      weeks,
      year,
      month,
      m,
      prevM,
      nextM,
      byDay,
      eventsByDay,
    });
  })
);

module.exports = router;
