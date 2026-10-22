// Dữ liệu mẫu để xem thử giao diện. Chỉ chạy trên máy cá nhân: npm run seed:demo
const db = require('./index');
const config = require('../config');
const { migrate } = require('./schema');
const { seedBase } = require('./seed');
const dates = require('../lib/dates');
const { hashPassword } = require('../lib/auth');
const { token } = require('../lib/util');

async function main() {
  if (config.isProd) throw new Error('Không chạy dữ liệu mẫu trên production.');
  await db.init();
  await migrate();
  await seedBase();

  const now = dates.now();
  const today = dates.today();
  const admin = await db.get(`SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1`);
  if (!admin) throw new Error('Cần tạo admin trước (ADMIN_EMAIL, ADMIN_PASSWORD trong .env).');

  let editor = await db.get('SELECT id FROM users WHERE email = ?', ['bientap@binbinhotel.vn']);
  if (!editor) {
    const r = await db.run('INSERT INTO users (email, name, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)', [
      'bientap@binbinhotel.vn',
      'Nguyễn Thu Hà',
      hashPassword('bientap123'),
      'editor',
      now,
    ]);
    editor = { id: r.insertId };
  }

  const promo = await db.run(
    'INSERT INTO events (title, kind, start_date, end_date, recurring, description, created_by, created_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?)',
    ['Combo cuối tuần: 2 đêm tặng bữa sáng', 'promo', dates.addDays(today, 2), dates.addDays(today, 9), 'Áp dụng đặt trực tiếp qua Zalo hoặc hotline.', admin.id, now]
  );

  const at = (offset, time) => `${dates.addDays(today, offset)} ${time}:00`;
  const posts = [
    ['Video room tour phòng Deluxe hướng phố', 'tiktok', 'Video ngắn', 'scheduled', at(0, '19:30'), 'Một vòng phòng Deluxe trong 30 giây: giường đôi lớn, ban công nhìn ra phố, bồn tắm riêng. Đặt phòng qua link ở bio!', '#BinBinHotel #RoomTour #KhachSan'],
    ['Ưu đãi combo cuối tuần', 'facebook', 'Album ảnh', 'scheduled', at(0, '11:00'), 'Cuối tuần này ở Bin Bin Hotel: đặt 2 đêm, tặng bữa sáng cho 2 người. Nhắn tin cho page để giữ phòng.', '#BinBinHotel #UuDai', promo.insertId],
    ['Trả lời đánh giá tuần này', 'google', 'Trả lời đánh giá', 'pending', at(1, '09:00'), 'Cảm ơn anh Minh đã chọn Bin Bin Hotel. Rất vui vì anh hài lòng với phòng và bữa sáng. Hẹn gặp lại anh lần tới!', ''],
    ['Tin Zalo: nhắc khách cũ combo cuối tuần', 'zalo', 'Tin truyền thông', 'pending', at(2, '10:00'), 'Bin Bin Hotel gửi quý khách ưu đãi riêng: 2 đêm cuối tuần tặng bữa sáng. Bấm Gọi ngay để giữ phòng.', '', promo.insertId],
    ['Khoảnh khắc check-in của khách', 'facebook', 'Bài viết', 'draft', at(3, '20:00'), 'Mỗi lượt check-in là một câu chuyện…', '#BinBinHotel'],
    ['Hậu trường dọn phòng 5 sao', 'tiktok', 'Video ngắn', 'draft', at(4, '18:00'), '', '#BinBinHotel #Hautruong'],
    ['Ảnh sảnh mới trang trí', 'google', 'Cập nhật', 'scheduled', at(5, '08:30'), 'Sảnh Bin Bin Hotel vừa được trang trí lại, mời bạn ghé chụp ảnh.', ''],
    ['Bữa sáng buffet có gì?', 'facebook', 'Reels', 'published', at(-2, '07:30'), 'Bữa sáng hôm nay: phở bò, bánh mì chảo, trái cây theo mùa.', '#BinBinHotel #BuaSang'],
    ['Check-in sớm miễn phí', 'zalo', 'Bài viết', 'published', at(-3, '09:00'), 'Khách đặt trực tiếp được nhận phòng sớm từ 11 giờ nếu còn phòng trống.', ''],
    ['Trend biến hình phòng ngủ', 'tiktok', 'Video ngắn', 'published', at(-1, '20:00'), 'Trước và sau khi dọn phòng. Bạn chấm mấy điểm?', '#BinBinHotel #Trend'],
    ['Gợi ý quán ăn gần khách sạn', 'facebook', 'Bài viết', 'scheduled', at(8, '12:00'), '5 quán ngon trong bán kính 1 km, khách Bin Bin Hotel nào cũng hỏi.', '#BinBinHotel #AnGi'],
    ['Tin nhắn chúc mừng 20/11', 'zalo', 'Tin truyền thông', 'draft', at(11, '08:00'), 'Bin Bin Hotel tri ân thầy cô: giảm 20% đặt phòng trong tuần lễ 20/11.', ''],
  ];
  for (const [title, channel, format, status, when, caption, hashtags, eventId] of posts) {
    const published = status === 'published';
    await db.run(
      `INSERT INTO posts (title, channel, format, caption, hashtags, scheduled_at, status, event_id, author_id, reviewer_id,
        published_at, views, likes, comments, shares, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        title, channel, format, caption, hashtags, when, status, eventId || null, editor.id,
        ['scheduled', 'published'].includes(status) ? admin.id : null,
        published ? when : null,
        published ? 800 + Math.floor(Math.random() * 9000) : 0,
        published ? 40 + Math.floor(Math.random() * 400) : 0,
        published ? Math.floor(Math.random() * 60) : 0,
        published ? Math.floor(Math.random() * 30) : 0,
        now, now,
      ]
    );
  }

  const followers = { facebook: 12400, tiktok: 5300, google: 0, zalo: 2100 };
  for (const [channel, base] of Object.entries(followers)) {
    for (let w = 6; w >= 0; w--) {
      const day = dates.addDays(today, -w * 7);
      const grow = (6 - w) * Math.round(base * 0.012);
      await db.run(
        `INSERT INTO channel_stats (channel, recorded_on, followers, reach, engagement, rating, reviews, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [
          channel,
          day,
          channel === 'google' ? null : base + grow,
          Math.round((base || 3000) * (0.8 + Math.random())),
          Math.round((base || 3000) * 0.05 * (0.6 + Math.random())),
          channel === 'google' ? 4.5 + (6 - w) * 0.03 : null,
          channel === 'google' ? 180 + (6 - w) * 6 : null,
          editor.id,
          now,
        ]
      );
    }
  }
  await db.run('UPDATE channel_accounts SET handle = ?, url = ?, owner_id = ? WHERE channel = ?', ['@binbinhotel', 'https://www.tiktok.com/', editor.id, 'tiktok']);
  await db.run('UPDATE channel_accounts SET handle = ?, url = ?, owner_id = ? WHERE channel = ?', ['Bin Bin Hotel', 'https://www.facebook.com/', editor.id, 'facebook']);

  const people = [
    ['lan.tran@example.com', 'Trần Ngọc Lan', 'khach-cu'],
    ['minh.le@example.com', 'Lê Quang Minh', 'khach-cu, doanh-nghiep'],
    ['hoa.pham@example.com', 'Phạm Thu Hoa', 'khach-cu'],
    ['booking@congtyabc.example.com', 'Công ty ABC', 'doanh-nghiep'],
    ['an.nguyen@example.com', 'Nguyễn Văn An', 'khach-moi'],
  ];
  for (const [email, name, tags] of people) {
    if (await db.get('SELECT id FROM contacts WHERE email = ?', [email])) continue;
    await db.run('INSERT INTO contacts (email, name, tags, subscribed, unsub_token, source, created_at) VALUES (?, ?, ?, 1, ?, ?, ?)', [
      email, name, tags, token(), 'demo', now,
    ]);
  }

  await db.run(
    'INSERT INTO activity (user_id, action, entity, summary, created_at) VALUES (?, ?, ?, ?, ?)',
    [editor.id, 'post.create', 'post', 'TikTok: Video room tour phòng Deluxe hướng phố', now]
  );
  console.log('Đã thêm dữ liệu mẫu. Tài khoản biên tập: bientap@binbinhotel.vn / bientap123');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
