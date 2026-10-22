// Gửi email qua Brevo Transactional API. Không có BREVO_API_KEY thì chạy chế độ thử:
// ghi nhận như đã gửi nhưng không gửi thật.
const config = require('../config');
const { escapeHtml } = require('./util');

const API = 'https://api.brevo.com/v3/smtp/email';

function isLive() {
  return Boolean(config.brevo.apiKey && config.brevo.senderEmail);
}

// Bọc nội dung chiến dịch trong khung email có chân trang và link huỷ đăng ký
function renderEmail({ subject, preheader, bodyHtml, unsubscribeUrl }) {
  return `<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${escapeHtml(subject)}</title></head>
<body style="margin:0;padding:0;background:#f2f2f4;font-family:Arial,Helvetica,sans-serif;color:#2a2122;">
<span style="display:none;max-height:0;overflow:hidden;opacity:0;">${escapeHtml(preheader || '')}</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f2f4;padding:24px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:6px;overflow:hidden;">
<tr><td style="background:#ed3237;padding:18px 28px;color:#ffffff;font-size:18px;font-weight:bold;letter-spacing:.5px;">Bin Bin Hotel</td></tr>
<tr><td style="padding:28px;font-size:15px;line-height:1.6;">${bodyHtml || ''}</td></tr>
<tr><td style="padding:18px 28px;border-top:1px solid #eeeeee;font-size:12px;line-height:1.5;color:#777777;">
Bạn nhận email này vì đã là khách hoặc đã đăng ký nhận tin từ Bin Bin Hotel.<br>
<a href="${escapeHtml(unsubscribeUrl)}" style="color:#777777;">Huỷ nhận email</a>
</td></tr></table></td></tr></table></body></html>`;
}

async function send({ to, name, subject, html, unsubscribeUrl, tag }) {
  if (!isLive()) {
    return { messageId: `dryrun-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`, dryRun: true };
  }
  const res = await fetch(API, {
    method: 'POST',
    headers: { 'api-key': config.brevo.apiKey, 'content-type': 'application/json', accept: 'application/json' },
    body: JSON.stringify({
      sender: { email: config.brevo.senderEmail, name: config.brevo.senderName },
      to: [{ email: to, name: name || undefined }],
      subject,
      htmlContent: html,
      tags: tag ? [tag] : undefined,
      headers: unsubscribeUrl ? { 'List-Unsubscribe': `<${unsubscribeUrl}>` } : undefined,
    }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.message || `Brevo trả về lỗi ${res.status}`);
  }
  return { messageId: data.messageId, dryRun: false };
}

module.exports = { isLive, renderEmail, send };
