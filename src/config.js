const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '..', '.env') });

const root = path.join(__dirname, '..');
const env = process.env.NODE_ENV || 'development';
const isProd = env === 'production';

const sessionSecret = process.env.SESSION_SECRET || '';
if (isProd && sessionSecret.length < 32) {
  throw new Error('SESSION_SECRET phải dài ít nhất 32 ký tự trên production.');
}

module.exports = {
  root,
  env,
  isProd,
  port: Number(process.env.PORT) || 3000,
  appUrl: (process.env.APP_URL || 'http://localhost:3000').replace(/\/$/, ''),
  sessionSecret: sessionSecret || 'dev-only-secret-not-for-production',
  timezone: 'Asia/Ho_Chi_Minh',
  db: {
    client: process.env.DB_CLIENT === 'mysql' ? 'mysql' : 'sqlite',
    sqlitePath: path.resolve(root, process.env.SQLITE_PATH || './data/binbin.db'),
    mysql: {
      host: process.env.DB_HOST || 'localhost',
      port: Number(process.env.DB_PORT) || 3306,
      user: process.env.DB_USER,
      password: process.env.DB_PASSWORD,
      database: process.env.DB_NAME,
    },
  },
  admin: {
    email: (process.env.ADMIN_EMAIL || '').trim().toLowerCase(),
    password: process.env.ADMIN_PASSWORD || '',
    name: process.env.ADMIN_NAME || 'Quản trị viên',
  },
  brevo: {
    apiKey: process.env.BREVO_API_KEY || '',
    senderEmail: process.env.BREVO_SENDER_EMAIL || '',
    senderName: process.env.BREVO_SENDER_NAME || 'Bin Bin Hotel',
    webhookSecret: process.env.BREVO_WEBHOOK_SECRET || '',
  },
  uploadDir: path.resolve(root, process.env.UPLOAD_DIR || './uploads'),
  // Email liên hệ ghi trên trang Chính sách bảo mật / Điều khoản
  legalContact: process.env.CONTACT_EMAIL || process.env.BREVO_SENDER_EMAIL || process.env.ADMIN_EMAIL || '',
  // Khoá mã hoá token kết nối kênh; mặc định suy ra từ SESSION_SECRET
  encryptionKey: process.env.ENCRYPTION_KEY || sessionSecret || 'dev-only-secret-not-for-production',
  google: { placesApiKey: process.env.GOOGLE_PLACES_API_KEY || '' },
  facebook: {
    appId: process.env.FACEBOOK_APP_ID || '',
    appSecret: process.env.FACEBOOK_APP_SECRET || '',
    graphVersion: process.env.FACEBOOK_GRAPH_VERSION || 'v23.0',
  },
  tiktok: { clientKey: process.env.TIKTOK_CLIENT_KEY || '', clientSecret: process.env.TIKTOK_CLIENT_SECRET || '' },
  zalo: { appId: process.env.ZALO_APP_ID || '', appSecret: process.env.ZALO_APP_SECRET || '' },
  ai: {
    apiKey: process.env.ANTHROPIC_API_KEY || '',
    model: process.env.AI_MODEL || 'claude-opus-5-5',
  },
};
