// Gửi chiến dịch trong nền: lần lượt từng người nhận, ghi kết quả vào campaign_recipients.
// Nếu máy chủ khởi động lại giữa chừng, resume() gửi tiếp những người còn trong hàng đợi.
const db = require('../db');
const config = require('../config');
const dates = require('./dates');
const brevo = require('./brevo');
const { escapeHtml } = require('./util');

const running = new Set();

function unsubscribeUrl(token) {
  return `${config.appUrl}/u/${token}`;
}

function hasTag(tags, tag) {
  return String(tags || '')
    .split(',')
    .map((t) => t.trim().toLowerCase())
    .includes(tag);
}

async function audience(tag) {
  const all = await db.all('SELECT id, email, name, tags, unsub_token FROM contacts WHERE subscribed = 1 ORDER BY id');
  return tag ? all.filter((c) => hasTag(c.tags, tag)) : all;
}

async function enqueue(campaign) {
  const list = await audience(campaign.audience_tag);
  for (const c of list) {
    await db.run('INSERT INTO campaign_recipients (campaign_id, contact_id, email, status) VALUES (?, ?, ?, ?)', [
      campaign.id,
      c.id,
      c.email,
      'queued',
    ]);
  }
  await db.run(`UPDATE campaigns SET status = 'sending', total = ?, dry_run = ?, updated_at = ? WHERE id = ?`, [
    list.length,
    brevo.isLive() ? 0 : 1,
    dates.now(),
    campaign.id,
  ]);
  return list.length;
}

async function run(campaignId) {
  if (running.has(campaignId)) return;
  running.add(campaignId);
  try {
    const campaign = await db.get('SELECT * FROM campaigns WHERE id = ?', [campaignId]);
    if (!campaign) return;
    const queue = await db.all(
      `SELECT r.id, r.email, c.name, c.unsub_token, c.subscribed FROM campaign_recipients r
       LEFT JOIN contacts c ON c.id = r.contact_id
       WHERE r.campaign_id = ? AND r.status = 'queued' ORDER BY r.id`,
      [campaignId]
    );
    for (const r of queue) {
      if (!r.unsub_token || !Number(r.subscribed)) {
        await db.run(`UPDATE campaign_recipients SET status = 'skipped', error = ? WHERE id = ?`, ['Đã huỷ nhận email', r.id]);
        continue;
      }
      const url = unsubscribeUrl(r.unsub_token);
      const html = brevo.renderEmail({ subject: campaign.subject, preheader: campaign.preheader, bodyHtml: personalize(campaign.body_html, r), unsubscribeUrl: url });
      try {
        const out = await brevo.send({ to: r.email, name: r.name, subject: campaign.subject, html, unsubscribeUrl: url, tag: `campaign-${campaignId}` });
        await db.run(`UPDATE campaign_recipients SET status = 'sent', message_id = ?, sent_at = ? WHERE id = ?`, [out.messageId, dates.now(), r.id]);
      } catch (err) {
        await db.run(`UPDATE campaign_recipients SET status = 'failed', error = ? WHERE id = ?`, [String(err.message).slice(0, 255), r.id]);
      }
      // Giãn nhịp gửi để không chạm giới hạn tốc độ của Brevo
      if (brevo.isLive()) await new Promise((ok) => setTimeout(ok, 150));
    }
    await db.run(`UPDATE campaigns SET status = 'sent', sent_at = ?, updated_at = ? WHERE id = ?`, [dates.now(), dates.now(), campaignId]);
  } catch (err) {
    console.error('[campaign] Lỗi khi gửi chiến dịch', campaignId, err);
  } finally {
    running.delete(campaignId);
  }
}

// {{ten}} → tên người nhận (hoặc "bạn" nếu chưa có tên)
function personalize(html, r) {
  const name = String(r.name || '').trim() || 'bạn';
  return String(html || '').replace(/\{\{\s*ten\s*\}\}/gi, escapeHtml(name));
}

async function resume() {
  const rows = await db.all(`SELECT id FROM campaigns WHERE status = 'sending'`);
  for (const r of rows) run(Number(r.id));
}

module.exports = { audience, enqueue, run, resume, unsubscribeUrl, personalize, hasTag };
