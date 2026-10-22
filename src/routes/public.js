// Đường dẫn công khai: huỷ nhận email và webhook thống kê của Brevo
const express = require('express');
const crypto = require('crypto');
const db = require('../db');
const config = require('../config');
const dates = require('../lib/dates');
const { h } = require('../lib/util');

const router = express.Router();

async function contactByToken(t) {
  if (!/^[a-f0-9]{16,64}$/.test(String(t || ''))) return null;
  return db.get('SELECT * FROM contacts WHERE unsub_token = ?', [t]);
}

// Trang xác nhận — chỉ huỷ khi người nhận bấm nút (tránh bộ lọc thư tự mở link làm huỷ nhầm)
router.get(
  '/u/:token',
  h(async (req, res) => {
    const contact = await contactByToken(req.params.token);
    res.render('unsubscribe', { title: 'Huỷ nhận email', contact, done: false });
  })
);

router.post(
  '/u/:token',
  h(async (req, res) => {
    const contact = await contactByToken(req.params.token);
    if (contact && Number(contact.subscribed)) {
      const now = dates.now();
      await db.run('UPDATE contacts SET subscribed = 0, unsubscribed_at = ? WHERE id = ?', [now, contact.id]);
      // Ghi nhận vào chiến dịch gần nhất mà người này nhận được
      const last = await db.get('SELECT MAX(id) AS id FROM campaign_recipients WHERE contact_id = ?', [contact.id]);
      if (last?.id) {
        await db.run('UPDATE campaign_recipients SET unsubscribed_at = COALESCE(unsubscribed_at, ?) WHERE id = ?', [now, last.id]);
      }
    }
    res.render('unsubscribe', { title: 'Huỷ nhận email', contact, done: true });
  })
);

// Trang pháp lý công khai: Facebook, TikTok, Zalo yêu cầu khi đăng ký ứng dụng
const LEGAL = { privacy: 'Chính sách bảo mật', terms: 'Điều khoản sử dụng' };
for (const [path, page] of [['/chinh-sach-bao-mat', 'privacy'], ['/dieu-khoan', 'terms']]) {
  router.get(path, (req, res) => {
    res.render('legal', { title: LEGAL[page], page, updated: '22/10/2026', contact: config.legalContact });
  });
}

const EVENT_COLUMN = {
  delivered: 'delivered_at',
  opened: 'opened_at',
  unique_opened: 'opened_at',
  click: 'clicked_at',
  hard_bounce: 'bounced_at',
  soft_bounce: 'bounced_at',
  blocked: 'bounced_at',
  invalid_email: 'bounced_at',
  unsubscribed: 'unsubscribed_at',
};

function secretOk(given) {
  const want = config.brevo.webhookSecret;
  if (!want) return false;
  const a = Buffer.from(String(given || ''));
  const b = Buffer.from(want);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

router.post(
  '/webhooks/brevo/:secret',
  express.json({ limit: '1mb' }),
  h(async (req, res) => {
    if (!secretOk(req.params.secret)) return res.status(404).end();
    const events = Array.isArray(req.body) ? req.body : [req.body];
    for (const ev of events) {
      const column = EVENT_COLUMN[ev?.event];
      const messageId = String(ev?.['message-id'] || ev?.messageId || '');
      if (!column || !messageId) continue;
      const bare = messageId.replace(/^<|>$/g, '');
      const r = await db.get('SELECT id, contact_id FROM campaign_recipients WHERE message_id IN (?, ?, ?)', [messageId, bare, `<${bare}>`]);
      if (!r) continue;
      const now = dates.now();
      await db.run(`UPDATE campaign_recipients SET ${column} = COALESCE(${column}, ?) WHERE id = ?`, [now, r.id]);
      // Mở/nhấp cũng có nghĩa là đã nhận được
      if (column === 'opened_at' || column === 'clicked_at') {
        await db.run('UPDATE campaign_recipients SET delivered_at = COALESCE(delivered_at, ?) WHERE id = ?', [now, r.id]);
      }
      if (column === 'unsubscribed_at' || ev.event === 'hard_bounce' || ev.event === 'invalid_email') {
        await db.run('UPDATE contacts SET subscribed = 0, unsubscribed_at = COALESCE(unsubscribed_at, ?) WHERE id = ?', [now, r.contact_id]);
      }
    }
    res.json({ ok: true });
  })
);

module.exports = router;
