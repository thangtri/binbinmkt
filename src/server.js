const path = require('path');
const fs = require('fs');
const crypto = require('crypto');
const express = require('express');
const helmet = require('helmet');
const cookieSession = require('cookie-session');

const config = require('./config');
const db = require('./db');
const { migrate } = require('./db/schema');
const { seedBase } = require('./db/seed');
const auth = require('./lib/auth');
const dates = require('./lib/dates');
const util = require('./lib/util');
const brevo = require('./lib/brevo');
const { icon } = require('./lib/icons');
const constants = require('./lib/constants');

// Ảnh khách sạn tuỳ chọn cho trang đăng nhập và trang tổng quan: public/img/hotel.(jpg|webp|png)
function hotelPhoto() {
  for (const ext of ['jpg', 'webp', 'png']) {
    if (fs.existsSync(path.join(config.root, 'public', 'img', `hotel.${ext}`))) return `/public/img/hotel.${ext}`;
  }
  return null;
}

async function main() {
  await db.init();
  await migrate();
  await seedBase();
  fs.mkdirSync(config.uploadDir, { recursive: true });

  const app = express();
  app.set('view engine', 'ejs');
  app.set('views', path.join(config.root, 'views'));
  app.set('trust proxy', 1);
  // Mã xác thực tên miền mà Zalo / Facebook đưa ra khi đăng ký ứng dụng (thẻ meta)
  app.locals.siteVerification = {
    zalo: process.env.ZALO_SITE_VERIFICATION || '',
    facebook: process.env.FACEBOOK_DOMAIN_VERIFICATION || '',
  };
  app.disable('x-powered-by');

  app.use(
    helmet({
      contentSecurityPolicy: {
        directives: {
          'default-src': ["'self'"],
          'script-src': ["'self'"],
          'style-src': ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
          'font-src': ["'self'", 'https://fonts.gstatic.com'],
          'img-src': ["'self'", 'data:', 'blob:'],
          'media-src': ["'self'", 'blob:'],
          'frame-src': ["'self'"],
          'form-action': ["'self'"],
          // Chỉ ép HTTPS trên production; chạy thử bằng http://localhost thì tắt
          'upgrade-insecure-requests': config.isProd ? [] : null,
        },
      },
    })
  );

  app.use('/public', express.static(path.join(config.root, 'public'), { maxAge: config.isProd ? '7d' : 0 }));
  app.use(express.urlencoded({ extended: false, limit: '2mb' }));
  app.use(express.json({ limit: '1mb' }));
  app.use(
    cookieSession({
      name: 'bb.sid',
      keys: [config.sessionSecret],
      maxAge: 7 * 24 * 3600 * 1000,
      httpOnly: true,
      sameSite: 'lax',
      secure: config.isProd,
    })
  );

  // Trang công khai: huỷ đăng ký email, webhook Brevo (không cần đăng nhập, không CSRF)
  app.use(require('./routes/public'));

  app.use(util.h(auth.loadUser));

  // CSRF: token lưu trong session, gửi kèm form (_csrf) hoặc header X-CSRF-Token
  app.use((req, res, next) => {
    if (!req.session.csrf) req.session.csrf = crypto.randomBytes(18).toString('hex');
    if (req.method === 'POST') {
      const sent = req.body?._csrf || req.get('x-csrf-token') || req.query._csrf;
      if (sent !== req.session.csrf) {
        res.status(403);
        if (req.accepts(['html', 'json']) === 'json') return res.json({ error: 'Phiên làm việc đã hết hạn, hãy tải lại trang.' });
        return res.render('error', { title: 'Phiên đã hết hạn', message: 'Phiên làm việc đã hết hạn. Hãy quay lại, tải lại trang rồi thử lần nữa.' });
      }
    }
    next();
  });

  app.use((req, res, next) => {
    res.locals.user = req.user;
    res.locals.csrf = req.session.csrf;
    res.locals.flash = req.session.flash || null;
    req.session.flash = null;
    res.locals.path = req.path;
    res.locals.can = (perm) => auth.can(req.user, perm);
    res.locals.d = dates;
    res.locals.icon = icon;
    res.locals.num = util.numberVi;
    res.locals.C = constants;
    res.locals.brevoLive = brevo.isLive();
    res.locals.query = req.query;
    res.locals.hotelPhoto = hotelPhoto();
    next();
  });

  app.use(require('./routes/auth'));
  app.use('/uploads', auth.requireLogin, express.static(config.uploadDir, { maxAge: '30d' }));
  app.use(auth.requireLogin);
  app.use(require('./routes/dashboard'));
  app.use('/calendar', require('./routes/calendar'));
  app.use('/posts', require('./routes/posts'));
  app.use('/events', require('./routes/events'));
  app.use('/channels', require('./routes/channels'));
  app.use('/email', require('./routes/email'));
  app.use('/users', require('./routes/users'));

  app.use((req, res) => {
    res.status(404).render('error', { title: 'Không tìm thấy', message: 'Trang bạn tìm không tồn tại hoặc đã bị xoá.' });
  });

  // eslint-disable-next-line no-unused-vars
  app.use((err, req, res, next) => {
    console.error(err);
    const status = err.status || 500;
    const message = err.expose ? err.message : 'Đã có lỗi phía máy chủ. Thử lại sau ít phút; nếu vẫn lỗi, báo quản trị viên.';
    res.status(status);
    if (req.accepts(['html', 'json']) === 'json') return res.json({ error: message });
    res.render('error', { title: 'Có lỗi xảy ra', message });
  });

  app.listen(config.port, () => {
    console.log(`Bin Bin Marketing đang chạy tại ${config.appUrl} (cổng ${config.port}, db: ${db.dialect})`);
    // Gửi tiếp các chiến dịch bị gián đoạn do máy chủ khởi động lại
    require('./lib/campaigns').resume();
    // Tự động cập nhật số liệu kênh và phân tích AI định kỳ
    require('./lib/sync').start();
  });
}

main().catch((err) => {
  console.error('Không khởi động được ứng dụng:', err);
  process.exit(1);
});
