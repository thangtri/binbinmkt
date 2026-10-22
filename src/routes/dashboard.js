const express = require('express');
const db = require('../db');
const dates = require('../lib/dates');
const { occurrences } = require('../lib/events');
const { h } = require('../lib/util');

const router = express.Router();

router.get(
  '/',
  h(async (req, res) => {
    const today = dates.today();
    const now = dates.now();
    const weekStart = dates.startOfWeek(today);
    const weekEnd = dates.addDays(weekStart, 6);
    const monthStart = today.slice(0, 8) + '01';

    // Bài đã duyệt, đến hạn hôm nay hoặc đã quá hạn mà chưa đánh dấu đăng
    const due = await db.all(
      `SELECT p.*, u.name AS author_name FROM posts p LEFT JOIN users u ON u.id = p.author_id
       WHERE p.status = 'scheduled' AND p.scheduled_at <= ? ORDER BY p.scheduled_at`,
      [today + ' 23:59:59']
    );

    const pending = await db.all(
      `SELECT p.*, u.name AS author_name FROM posts p LEFT JOIN users u ON u.id = p.author_id
       WHERE p.status = 'pending' ORDER BY p.scheduled_at IS NULL, p.scheduled_at LIMIT 6`
    );
    const pendingCount = (await db.get(`SELECT COUNT(*) AS n FROM posts WHERE status = 'pending'`)).n;

    const weekRows = await db.all(
      `SELECT channel, status, COUNT(*) AS n FROM posts WHERE scheduled_at BETWEEN ? AND ? GROUP BY channel, status`,
      [weekStart + ' 00:00:00', weekEnd + ' 23:59:59']
    );
    const week = {};
    for (const r of weekRows) {
      week[r.channel] = week[r.channel] || { total: 0, published: 0 };
      week[r.channel].total += Number(r.n);
      if (r.status === 'published') week[r.channel].published += Number(r.n);
    }

    const publishedMonth = (
      await db.get(`SELECT COUNT(*) AS n FROM posts WHERE status = 'published' AND published_at >= ?`, [monthStart + ' 00:00:00'])
    ).n;

    // Sự kiện 45 ngày tới và số bài đã chuẩn bị cho từng sự kiện
    const upcoming = (await occurrences(today, dates.addDays(today, 45))).slice(0, 8);
    for (const ev of upcoming) {
      const r = await db.get(
        `SELECT COUNT(*) AS n FROM posts WHERE event_id = ? AND scheduled_at BETWEEN ? AND ?`,
        [ev.id, dates.addDays(ev.occ_start, -30) + ' 00:00:00', ev.occ_end + ' 23:59:59']
      );
      ev.post_count = Number(r.n);
    }

    const campaign = await db.get(`SELECT * FROM campaigns WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 1`);
    if (campaign) {
      campaign.stats = await db.get(
        `SELECT COUNT(*) AS total, SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked
         FROM campaign_recipients WHERE campaign_id = ? AND status = 'sent'`,
        [campaign.id]
      );
    }

    const activity = await db.all(
      `SELECT a.*, u.name AS user_name FROM activity a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8`
    );

    res.render('dashboard', {
      title: 'Tổng quan',
      today,
      now,
      due,
      pending,
      pendingCount: Number(pendingCount),
      week,
      weekStart,
      weekEnd,
      publishedMonth: Number(publishedMonth),
      upcoming,
      campaign,
      activity,
    });
  })
);

module.exports = router;
