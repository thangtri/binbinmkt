// Báo cáo marketing một tháng: bài đăng, kênh, Google theo chi nhánh, đối thủ, email, ưu đãi.
const db = require('../db');
const dates = require('./dates');
const { CHANNELS } = require('./constants');

function monthRange(month) {
  const y = Number(month.slice(0, 4));
  const m = Number(month.slice(5, 7));
  const last = new Date(Date.UTC(y, m, 0)).getUTCDate();
  return { from: `${month}-01`, to: `${month}-${String(last).padStart(2, '0')}` };
}

function prevMonth(month) {
  const y = Number(month.slice(0, 4));
  const m = Number(month.slice(5, 7));
  return m === 1 ? `${y - 1}-12` : `${y}-${String(m - 1).padStart(2, '0')}`;
}

function nextMonth(month) {
  const y = Number(month.slice(0, 4));
  const m = Number(month.slice(5, 7));
  return m === 12 ? `${y + 1}-01` : `${y}-${String(m + 1).padStart(2, '0')}`;
}

// Giá trị đầu kỳ (lần ghi cuối trước tháng, hoặc đầu tháng) và cuối kỳ của một chỉ số
async function startEnd(sqlWhere, params, col, from, to) {
  const start =
    (await db.get(`SELECT ${col} AS v FROM channel_stats WHERE ${sqlWhere} AND ${col} IS NOT NULL AND recorded_on < ? ORDER BY recorded_on DESC, id DESC LIMIT 1`, [...params, from])) ||
    (await db.get(`SELECT ${col} AS v FROM channel_stats WHERE ${sqlWhere} AND ${col} IS NOT NULL AND recorded_on BETWEEN ? AND ? ORDER BY recorded_on, id LIMIT 1`, [...params, from, to]));
  const end = await db.get(`SELECT ${col} AS v FROM channel_stats WHERE ${sqlWhere} AND ${col} IS NOT NULL AND recorded_on <= ? ORDER BY recorded_on DESC, id DESC LIMIT 1`, [...params, to]);
  const s = start ? Number(start.v) : null;
  const e = end ? Number(end.v) : null;
  return { start: s, end: e, change: s != null && e != null ? Math.round((e - s) * 100) / 100 : null };
}

async function build(month) {
  const { from, to } = monthRange(month);
  const F = from + ' 00:00:00';
  const T = to + ' 23:59:59';

  const statusRows = await db.all('SELECT status, COUNT(*) AS n FROM posts WHERE scheduled_at BETWEEN ? AND ? GROUP BY status', [F, T]);
  const status = Object.fromEntries(statusRows.map((r) => [r.status, Number(r.n)]));
  const published = Number((await db.get(`SELECT COUNT(*) AS n FROM posts WHERE status = 'published' AND published_at BETWEEN ? AND ?`, [F, T])).n);

  const topPosts = (
    await db.all(
      `SELECT id, title, format, published_at, views, likes, comments, shares FROM posts
       WHERE status = 'published' AND published_at BETWEEN ? AND ? ORDER BY (likes + comments + shares) DESC, views DESC LIMIT 5`,
      [F, T]
    )
  ).map((p) => ({ ...p, interactions: Number(p.likes) + Number(p.comments) + Number(p.shares) }));

  const channels = [];
  for (const ch of Object.values(CHANNELS)) {
    if (ch.key === 'google') continue;
    const followers = await startEnd('channel = ?', [ch.key], 'followers', from, to);
    const perf = await db.get(
      `SELECT COUNT(*) AS posts, COALESCE(SUM(pc.views), 0) AS views,
              COALESCE(SUM(COALESCE(pc.likes, 0) + COALESCE(pc.comments, 0) + COALESCE(pc.shares, 0)), 0) AS interactions
       FROM posts p JOIN post_channels pc ON pc.post_id = p.id AND pc.channel = ?
       WHERE p.status = 'published' AND p.published_at BETWEEN ? AND ?`,
      [ch.key, F, T]
    );
    channels.push({ key: ch.key, name: ch.name, followers, posts: Number(perf.posts), views: Number(perf.views), interactions: Number(perf.interactions) });
  }

  const branchesRows = [];
  for (const b of await require('./branches').list()) {
    branchesRows.push({
      name: b.name,
      rating: await startEnd("channel = 'google' AND branch_id = ?", [b.id], 'rating', from, to),
      reviews: await startEnd("channel = 'google' AND branch_id = ?", [b.id], 'reviews', from, to),
    });
  }

  const rivals = [];
  for (const c of await db.all('SELECT c.*, b.name AS branch_name FROM competitors c LEFT JOIN branches b ON b.id = c.branch_id WHERE c.active = 1 ORDER BY b.position, c.name')) {
    const end = await db.get('SELECT rating, reviews FROM competitor_stats WHERE competitor_id = ? AND recorded_on <= ? ORDER BY recorded_on DESC, id DESC LIMIT 1', [c.id, to]);
    const start = await db.get('SELECT reviews FROM competitor_stats WHERE competitor_id = ? AND recorded_on < ? ORDER BY recorded_on DESC, id DESC LIMIT 1', [c.id, from]);
    rivals.push({ name: c.name, branch: c.branch_name, rating: end?.rating ?? null, reviews: end?.reviews ?? null, newReviews: start && end ? Number(end.reviews) - Number(start.reviews) : null });
  }

  const emails = await db.all(
    `SELECT c.id, c.name, c.sent_at,
            (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'sent') AS sent,
            (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.opened_at IS NOT NULL) AS opened,
            (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.clicked_at IS NOT NULL) AS clicked
     FROM campaigns c WHERE c.status = 'sent' AND c.sent_at BETWEEN ? AND ? ORDER BY c.sent_at`,
    [F, T]
  );

  const offerRows = await db.all(
    `SELECT o.title, r.channel, COUNT(*) AS used, COALESCE(SUM(r.amount), 0) AS revenue, COALESCE(SUM(r.nights), 0) AS nights
     FROM offer_redemptions r JOIN offers o ON o.id = r.offer_id WHERE r.used_on BETWEEN ? AND ? GROUP BY o.title, r.channel ORDER BY o.title, used DESC`,
    [from, to]
  );

  return {
    month,
    from,
    to,
    posts: { status, published, top: topPosts },
    channels,
    branches: branchesRows,
    rivals,
    emails: emails.map((e) => ({ ...e, sent: Number(e.sent), opened: Number(e.opened), clicked: Number(e.clicked) })),
    offers: offerRows.map((o) => ({ ...o, used: Number(o.used), revenue: Number(o.revenue), nights: Number(o.nights) })),
  };
}

// Xuất CSV nhiều phần (mở được bằng Excel, giữ dấu tiếng Việt)
function toCsv(r) {
  const csv = require('./csv');
  const codeNames = Object.fromEntries(require('./offers').CODE_CHANNELS.map((c) => [c.key, c.name]));
  const rows = [
    [`Báo cáo marketing Bin Bin Hotel tháng ${r.month}`],
    [],
    ['BÀI ĐĂNG'],
    ['Đã đăng trong tháng', r.posts.published],
    ...Object.entries(r.posts.status).map(([k, v]) => [`Lên lịch trong tháng: ${k}`, v]),
    [],
    ['BÀI HIỆU QUẢ NHẤT', 'Ngày đăng', 'Lượt xem', 'Tương tác'],
    ...r.posts.top.map((p) => [p.title, p.published_at?.slice(0, 10), p.views, p.interactions]),
    [],
    ['KÊNH', 'Người theo dõi đầu kỳ', 'Cuối kỳ', 'Tăng/giảm', 'Bài đã đăng', 'Lượt xem', 'Tương tác'],
    ...r.channels.map((c) => [c.name, c.followers.start, c.followers.end, c.followers.change, c.posts, c.views, c.interactions]),
    [],
    ['GOOGLE MAPS THEO CHI NHÁNH', 'Điểm đầu kỳ', 'Điểm cuối kỳ', 'Số đánh giá đầu kỳ', 'Cuối kỳ', 'Đánh giá mới'],
    ...r.branches.map((b) => [b.name, b.rating.start, b.rating.end, b.reviews.start, b.reviews.end, b.reviews.change]),
    [],
    ['ĐỐI THỦ', 'So với chi nhánh', 'Điểm', 'Số đánh giá', 'Đánh giá mới trong tháng'],
    ...r.rivals.map((c) => [c.name, c.branch, c.rating, c.reviews, c.newReviews]),
    [],
    ['EMAIL', 'Ngày gửi', 'Đã gửi', 'Mở', 'Nhấp'],
    ...r.emails.map((e) => [e.name, e.sent_at?.slice(0, 10), e.sent, e.opened, e.clicked]),
    [],
    ['ƯU ĐÃI', 'Kênh', 'Lượt dùng mã', 'Số đêm', 'Doanh thu'],
    ...r.offers.map((o) => [o.title, codeNames[o.channel] || o.channel, o.used, o.nights, o.revenue]),
  ];
  return csv.stringify(rows);
}

module.exports = { build, toCsv, prevMonth, nextMonth, monthRange };
