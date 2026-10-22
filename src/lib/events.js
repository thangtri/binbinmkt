const db = require('../db');
const dates = require('./dates');

// Các lần diễn ra của sự kiện trong khoảng [from, to] (YYYY-MM-DD).
// Sự kiện lặp lại hằng năm được chiếu sang từng năm trong khoảng.
async function occurrences(from, to) {
  const rows = await db.all(
    'SELECT * FROM events WHERE recurring = 1 OR (start_date <= ? AND end_date >= ?) ORDER BY start_date',
    [to, from]
  );
  const out = [];
  const y1 = Number(from.slice(0, 4)) - 1;
  const y2 = Number(to.slice(0, 4));
  for (const ev of rows) {
    if (!Number(ev.recurring)) {
      out.push({ ...ev, occ_start: ev.start_date, occ_end: ev.end_date });
      continue;
    }
    const length = dates.diffDays(ev.start_date, ev.end_date);
    for (let y = y1; y <= y2; y++) {
      const start = `${y}${ev.start_date.slice(4)}`;
      if (!dates.isDate(start)) continue; // 29/2 năm không nhuận
      const end = dates.addDays(start, length);
      if (start <= to && end >= from) out.push({ ...ev, occ_start: start, occ_end: end });
    }
  }
  return out.sort((a, b) => (a.occ_start < b.occ_start ? -1 : a.occ_start > b.occ_start ? 1 : 0));
}

module.exports = { occurrences };
