// Mã hoá token kết nối kênh trước khi lưu vào database (AES-256-GCM)
const crypto = require('crypto');
const config = require('../config');

const key = crypto.createHash('sha256').update(String(config.encryptionKey)).digest();

function encrypt(text) {
  if (text == null || text === '') return null;
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
  const data = Buffer.concat([cipher.update(String(text), 'utf8'), cipher.final()]);
  return ['v1', iv.toString('base64'), cipher.getAuthTag().toString('base64'), data.toString('base64')].join(':');
}

function decrypt(stored) {
  if (!stored) return null;
  const [v, iv, tag, data] = String(stored).split(':');
  if (v !== 'v1') return null;
  try {
    const decipher = crypto.createDecipheriv('aes-256-gcm', key, Buffer.from(iv, 'base64'));
    decipher.setAuthTag(Buffer.from(tag, 'base64'));
    return Buffer.concat([decipher.update(Buffer.from(data, 'base64')), decipher.final()]).toString('utf8');
  } catch {
    // Khoá đã đổi: token cũ không đọc được, cần kết nối lại
    return null;
  }
}

module.exports = { encrypt, decrypt };
