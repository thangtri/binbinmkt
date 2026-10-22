// Ngày giờ được lưu dạng chuỗi "YYYY-MM-DD HH:MM:SS" theo giờ Việt Nam,
// nên so sánh chuỗi cũng là so sánh thời gian.
const config = require('../config');

const parts = new Intl.DateTimeFormat('en-CA', {
  timeZone: config.timezone,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hourCycle: 'h23',
});

function nowParts() {
  const p = Object.fromEntries(parts.formatToParts(new Date()).map((x) => [x.type, x.value]));
  return p;
}

function today() {
  const p = nowParts();
  return `${p.year}-${p.month}-${p.day}`;
}

function now() {
  const p = nowParts();
  return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}:${p.second}`;
}

function isDate(s) {
  return typeof s === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(s) && !Number.isNaN(Date.parse(s + 'T00:00:00Z'));
}

function toUTC(dateStr) {
  return new Date(dateStr.slice(0, 10) + 'T00:00:00Z');
}

function fromUTC(d) {
  return d.toISOString().slice(0, 10);
}

function addDays(dateStr, n) {
  const d = toUTC(dateStr);
  d.setUTCDate(d.getUTCDate() + n);
  return fromUTC(d);
}

function diffDays(a, b) {
  return Math.round((toUTC(b) - toUTC(a)) / 86400000);
}

// Thứ Hai của tuần chứa ngày đã cho
function startOfWeek(dateStr) {
  const dow = toUTC(dateStr).getUTCDay();
  return addDays(dateStr, dow === 0 ? -6 : 1 - dow);
}

const WEEKDAYS = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
const WEEKDAYS_LONG = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];

function weekday(dateStr, long = false) {
  const i = toUTC(dateStr).getUTCDay();
  return (long ? WEEKDAYS_LONG : WEEKDAYS)[i];
}

// "12/10"
function dm(dateStr) {
  if (!dateStr) return '';
  return `${dateStr.slice(8, 10)}/${dateStr.slice(5, 7)}`;
}

// "12/10/2026"
function dmy(dateStr) {
  if (!dateStr) return '';
  return `${dm(dateStr)}/${dateStr.slice(0, 4)}`;
}

// "12/10/2026 09:30"
function dmyhm(dt) {
  if (!dt) return '';
  return `${dmy(dt)} ${dt.slice(11, 16)}`;
}

function hm(dt) {
  return dt ? dt.slice(11, 16) : '';
}

function monthLabel(year, month) {
  return `Tháng ${month}/${year}`;
}

// Lưới tháng: các tuần (Thứ Hai → Chủ nhật) phủ kín tháng
function monthGrid(year, month) {
  const first = `${year}-${String(month).padStart(2, '0')}-01`;
  const last = addDays(fromUTC(new Date(Date.UTC(year, month, 1))), -1);
  let cur = startOfWeek(first);
  const weeks = [];
  while (cur <= last) {
    const week = [];
    for (let i = 0; i < 7; i++) week.push(addDays(cur, i));
    weeks.push(week);
    cur = addDays(cur, 7);
  }
  return weeks;
}

// "3 ngày nữa", "hôm nay", "2 ngày trước"
function relative(dateStr) {
  const d = diffDays(today(), dateStr.slice(0, 10));
  if (d === 0) return 'hôm nay';
  if (d === 1) return 'ngày mai';
  if (d === -1) return 'hôm qua';
  return d > 0 ? `${d} ngày nữa` : `${-d} ngày trước`;
}

module.exports = {
  today,
  now,
  isDate,
  addDays,
  diffDays,
  startOfWeek,
  weekday,
  dm,
  dmy,
  dmyhm,
  hm,
  monthLabel,
  monthGrid,
  relative,
};
