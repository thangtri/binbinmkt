const path = require('path');
const fs = require('fs');
const crypto = require('crypto');
const multer = require('multer');
const config = require('../config');

const ALLOWED = {
  'image/jpeg': '.jpg',
  'image/png': '.png',
  'image/webp': '.webp',
  'image/gif': '.gif',
  'video/mp4': '.mp4',
  'video/quicktime': '.mov',
  'video/webm': '.webm',
};

const storage = multer.diskStorage({
  destination: (req, file, cb) => {
    const sub = new Date().toISOString().slice(0, 7); // YYYY-MM
    const dir = path.join(config.uploadDir, sub);
    fs.mkdirSync(dir, { recursive: true });
    cb(null, dir);
  },
  filename: (req, file, cb) => cb(null, crypto.randomBytes(12).toString('hex') + ALLOWED[file.mimetype]),
});

const media = multer({
  storage,
  limits: { fileSize: 50 * 1024 * 1024, files: 1 },
  fileFilter: (req, file, cb) => {
    if (ALLOWED[file.mimetype]) return cb(null, true);
    const err = new Error('Chỉ nhận ảnh JPG, PNG, WebP, GIF hoặc video MP4, MOV, WebM.');
    err.status = 400;
    err.expose = true;
    cb(err);
  },
});

// Lỗi kích thước file của multer → thông báo dễ hiểu
function single(field) {
  const mw = media.single(field);
  return (req, res, next) =>
    mw(req, res, (err) => {
      if (err?.code === 'LIMIT_FILE_SIZE') {
        err.status = 400;
        err.expose = true;
        err.message = 'File quá lớn. Giới hạn 50 MB — hãy nén video trước khi tải lên.';
      }
      next(err);
    });
}

// Đường dẫn lưu trong DB: "2026-10/abc.jpg"
function relPath(file) {
  return path.relative(config.uploadDir, file.path).split(path.sep).join('/');
}

function remove(rel) {
  if (!rel) return;
  const full = path.resolve(config.uploadDir, rel);
  if (!full.startsWith(config.uploadDir)) return;
  fs.promises.unlink(full).catch(() => {});
}

function memoryCsv() {
  return multer({ storage: multer.memoryStorage(), limits: { fileSize: 5 * 1024 * 1024, files: 1 } }).single('file');
}

module.exports = { single, relPath, remove, memoryCsv };
