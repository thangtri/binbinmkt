// Dữ liệu nền tảng: admin đầu tiên, 4 kênh, các ngày lễ lặp lại hằng năm.
const db = require('./index');
const config = require('../config');
const dates = require('../lib/dates');
const { hashPassword } = require('../lib/auth');
const { CHANNELS } = require('../lib/constants');

// Ngày lễ dương lịch lặp lại hằng năm (năm gốc 2026)
const RECURRING = [
  ['Tết Dương lịch', '2026-01-01', '2026-01-01', 'holiday'],
  ['Lễ Tình nhân', '2026-02-14', '2026-02-14', 'holiday'],
  ['Quốc tế Phụ nữ 8/3', '2026-03-08', '2026-03-08', 'holiday'],
  ['Giải phóng miền Nam & Quốc tế Lao động', '2026-04-30', '2026-05-01', 'holiday'],
  ['Quốc tế Thiếu nhi', '2026-06-01', '2026-06-01', 'holiday'],
  ['Mùa du lịch hè', '2026-06-01', '2026-08-31', 'season'],
  ['Quốc khánh 2/9', '2026-09-02', '2026-09-02', 'holiday'],
  ['Phụ nữ Việt Nam 20/10', '2026-10-20', '2026-10-20', 'holiday'],
  ['Nhà giáo Việt Nam 20/11', '2026-11-20', '2026-11-20', 'holiday'],
  ['Giáng sinh', '2026-12-24', '2026-12-25', 'holiday'],
];

// Lễ theo âm lịch: ngày dương thay đổi mỗi năm, nhập riêng từng năm
const LUNAR = [
  ['Tết Trung thu', '2026-09-25', '2026-09-25', 'holiday'],
  ['Tết Nguyên đán Đinh Mùi', '2027-02-05', '2027-02-10', 'holiday'],
  ['Tết Trung thu', '2027-09-15', '2027-09-15', 'holiday'],
];

async function seedBase() {
  const now = dates.now();

  const users = await db.get('SELECT COUNT(*) AS n FROM users');
  if (Number(users.n) === 0) {
    if (config.admin.email && config.admin.password) {
      await db.run(
        'INSERT INTO users (email, name, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)',
        [config.admin.email, config.admin.name, hashPassword(config.admin.password), 'admin', now]
      );
      console.log(`[seed] Đã tạo tài khoản admin ${config.admin.email}`);
    } else {
      console.warn('[seed] Chưa có tài khoản nào. Đặt ADMIN_EMAIL và ADMIN_PASSWORD trong .env rồi khởi động lại.');
    }
  }

  for (const ch of Object.values(CHANNELS)) {
    const exists = await db.get('SELECT channel FROM channel_accounts WHERE channel = ?', [ch.key]);
    if (!exists) {
      await db.run('INSERT INTO channel_accounts (channel, display_name, updated_at) VALUES (?, ?, ?)', [ch.key, 'Bin Bin Hotel', now]);
    }
  }

  const events = await db.get('SELECT COUNT(*) AS n FROM events');
  if (Number(events.n) === 0) {
    for (const [title, start, end, kind] of RECURRING) {
      await db.run(
        'INSERT INTO events (title, kind, start_date, end_date, recurring, created_at) VALUES (?, ?, ?, ?, 1, ?)',
        [title, kind, start, end, now]
      );
    }
    for (const [title, start, end, kind] of LUNAR) {
      await db.run(
        'INSERT INTO events (title, kind, start_date, end_date, recurring, created_at) VALUES (?, ?, ?, ?, 0, ?)',
        [title, kind, start, end, now]
      );
    }
    console.log('[seed] Đã thêm lịch lễ, Tết mặc định');
  }
}

module.exports = { seedBase };
