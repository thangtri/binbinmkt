// Thông tin khách sạn dùng chung (AI viết bài dựa vào đây). Lưu trong bảng settings.
const db = require('../db');
const dates = require('./dates');

const FIELDS = [
  { key: 'hotel_name', label: 'Tên khách sạn', default: 'Bin Bin Hotel' },
  { key: 'address', label: 'Địa chỉ', default: '' },
  { key: 'hotline', label: 'Hotline đặt phòng', default: '' },
  { key: 'zalo', label: 'Zalo đặt phòng', default: '', hint: 'Số Zalo hoặc link zalo.me' },
  { key: 'booking_url', label: 'Link đặt phòng', default: '', hint: 'Website, Booking, Agoda…' },
  { key: 'highlights', label: 'Điểm mạnh của khách sạn', default: '', long: true, hint: 'Mỗi dòng một ý: loại phòng, tiện ích, vị trí, bữa sáng…' },
  { key: 'audience', label: 'Khách hàng chính', default: '', long: true, hint: 'Ví dụ: cặp đôi cuối tuần, khách công tác, gia đình có trẻ nhỏ' },
  { key: 'voice', label: 'Giọng văn', default: 'Thân thiện, ấm áp, lịch sự; xưng "Bin Bin" và gọi khách là "bạn".', long: true },
  { key: 'avoid', label: 'Điều cần tránh', default: '', long: true, hint: 'Từ ngữ, cam kết hoặc chủ đề không được dùng' },
  { key: 'hashtags', label: 'Hashtag mặc định', default: '#BinBinHotel' },
];

async function all() {
  const rows = await db.all('SELECT name, value FROM settings');
  const saved = Object.fromEntries(rows.map((r) => [r.name, r.value]));
  return Object.fromEntries(FIELDS.map((f) => [f.key, saved[f.key] ?? f.default]));
}

async function save(values) {
  const now = dates.now();
  for (const f of FIELDS) {
    if (values[f.key] === undefined) continue;
    const v = String(values[f.key]).trim().slice(0, 5000);
    const exists = await db.get('SELECT name FROM settings WHERE name = ?', [f.key]);
    if (exists) await db.run('UPDATE settings SET value = ?, updated_at = ? WHERE name = ?', [v, now, f.key]);
    else await db.run('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)', [f.key, v, now]);
  }
}

module.exports = { FIELDS, all, save };
