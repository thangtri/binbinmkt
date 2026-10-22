const express = require('express');
const db = require('../db');
const config = require('../config');
const dates = require('../lib/dates');
const brevo = require('../lib/brevo');
const csv = require('../lib/csv');
const campaigns = require('../lib/campaigns');
const upload = require('../lib/upload');
const { requirePerm } = require('../lib/auth');
const { h, flash, logActivity, str, int, isEmail, token, parseTags } = require('../lib/util');

const router = express.Router();
const PAGE = 50;

function notFound(what) {
  const err = new Error(`Không tìm thấy ${what}.`);
  err.status = 404;
  err.expose = true;
  return err;
}

router.get('/', (req, res) => res.redirect('/email/campaigns'));

/* ---------- Danh bạ ---------- */

async function allTags() {
  const set = new Set();
  for (const r of await db.all(`SELECT tags FROM contacts WHERE tags IS NOT NULL AND tags <> ''`)) {
    for (const t of r.tags.split(',')) if (t.trim()) set.add(t.trim().toLowerCase());
  }
  return [...set].sort();
}

router.get(
  '/contacts',
  h(async (req, res) => {
    const where = [];
    const params = [];
    if (req.query.q) {
      where.push('(email LIKE ? OR name LIKE ? OR phone LIKE ?)');
      params.push(`%${req.query.q}%`, `%${req.query.q}%`, `%${req.query.q}%`);
    }
    if (req.query.status === 'unsub') where.push('subscribed = 0');
    else if (req.query.status === 'sub') where.push('subscribed = 1');
    const sqlWhere = where.length ? 'WHERE ' + where.join(' AND ') : '';
    let rows = await db.all(`SELECT * FROM contacts ${sqlWhere} ORDER BY id DESC`, params);
    const tag = str(req.query.tag, 60).toLowerCase();
    if (tag) rows = rows.filter((c) => campaigns.hasTag(c.tags, tag));
    const page = Math.max(1, int(req.query.page, 1));
    const totals = await db.get('SELECT COUNT(*) AS total, SUM(subscribed) AS subscribed FROM contacts');
    res.render('email/contacts', {
      title: 'Danh bạ email',
      contacts: rows.slice((page - 1) * PAGE, page * PAGE),
      total: rows.length,
      page,
      pages: Math.ceil(rows.length / PAGE) || 1,
      tags: await allTags(),
      tag,
      totals: { total: Number(totals.total), subscribed: Number(totals.subscribed || 0) },
    });
  })
);

router.post(
  '/contacts',
  requirePerm('contact:edit'),
  h(async (req, res) => {
    const email = str(req.body.email, 190).toLowerCase();
    if (!isEmail(email)) {
      flash(req, 'error', 'Email không hợp lệ.');
      return res.redirect('/email/contacts');
    }
    const exists = await db.get('SELECT id FROM contacts WHERE email = ?', [email]);
    if (exists) {
      flash(req, 'error', `${email} đã có trong danh bạ.`);
      return res.redirect('/email/contacts');
    }
    await db.run('INSERT INTO contacts (email, name, phone, tags, subscribed, unsub_token, source, created_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?)', [
      email,
      str(req.body.name, 150),
      str(req.body.phone, 30),
      parseTags(req.body.tags),
      token(),
      'manual',
      dates.now(),
    ]);
    flash(req, 'success', `Đã thêm ${email}.`);
    res.redirect('/email/contacts');
  })
);

router.post(
  '/contacts/import',
  requirePerm('contact:edit'),
  upload.memoryCsv(),
  h(async (req, res) => {
    if (!req.file) {
      flash(req, 'error', 'Hãy chọn file CSV.');
      return res.redirect('/email/contacts');
    }
    const rows = csv.parse(req.file.buffer.toString('utf8'));
    const header = (rows[0] || []).map((c) => c.trim().toLowerCase());
    const col = (names) => header.findIndex((c) => names.includes(c));
    let iEmail = col(['email', 'e-mail', 'mail']);
    const iName = col(['name', 'ten', 'tên', 'họ tên', 'ho ten', 'họ và tên']);
    const iPhone = col(['phone', 'sdt', 'sđt', 'số điện thoại', 'dien thoai', 'điện thoại']);
    const iTags = col(['tags', 'tag', 'nhóm', 'nhom']);
    let body = rows.slice(1);
    if (iEmail === -1) {
      // Không có dòng tiêu đề: coi cột đầu là email
      iEmail = 0;
      body = rows;
    }
    const extraTags = parseTags(req.body.tags);
    let added = 0;
    let skipped = 0;
    for (const r of body) {
      const email = str(r[iEmail], 190).toLowerCase();
      if (!isEmail(email) || (await db.get('SELECT id FROM contacts WHERE email = ?', [email]))) {
        skipped++;
        continue;
      }
      const tags = parseTags([iTags >= 0 ? r[iTags] : '', extraTags].join(','));
      await db.run('INSERT INTO contacts (email, name, phone, tags, subscribed, unsub_token, source, created_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?)', [
        email,
        iName >= 0 ? str(r[iName], 150) : '',
        iPhone >= 0 ? str(r[iPhone], 30) : '',
        tags,
        token(),
        'import',
        dates.now(),
      ]);
      added++;
    }
    await logActivity(req.user, 'contact.import', 'contact', null, `Nhập ${added} liên hệ`);
    flash(req, 'success', `Đã thêm ${added} liên hệ. Bỏ qua ${skipped} dòng trùng hoặc email không hợp lệ.`);
    res.redirect('/email/contacts');
  })
);

router.get(
  '/contacts/export.csv',
  h(async (req, res) => {
    const rows = await db.all('SELECT email, name, phone, tags, subscribed, created_at FROM contacts ORDER BY id');
    const out = [['email', 'ten', 'sdt', 'tags', 'trang_thai', 'ngay_them']].concat(
      rows.map((r) => [r.email, r.name, r.phone, r.tags, Number(r.subscribed) ? 'dang_nhan' : 'da_huy', r.created_at])
    );
    res.set('content-type', 'text/csv; charset=utf-8');
    res.set('content-disposition', `attachment; filename="binbin-danh-ba-${dates.today()}.csv"`);
    res.send(csv.stringify(out));
  })
);

router.post(
  '/contacts/:id',
  requirePerm('contact:edit'),
  h(async (req, res) => {
    const c = await db.get('SELECT * FROM contacts WHERE id = ?', [int(req.params.id)]);
    if (!c) throw notFound('liên hệ');
    await db.run('UPDATE contacts SET name = ?, phone = ?, tags = ? WHERE id = ?', [
      str(req.body.name, 150),
      str(req.body.phone, 30),
      parseTags(req.body.tags),
      c.id,
    ]);
    flash(req, 'success', `Đã lưu ${c.email}.`);
    res.redirect('/email/contacts');
  })
);

router.post(
  '/contacts/:id/delete',
  requirePerm('contact:edit'),
  h(async (req, res) => {
    const c = await db.get('SELECT * FROM contacts WHERE id = ?', [int(req.params.id)]);
    if (!c) throw notFound('liên hệ');
    await db.run('DELETE FROM contacts WHERE id = ?', [c.id]);
    await logActivity(req.user, 'contact.delete', 'contact', c.id, c.email);
    flash(req, 'success', `Đã xoá ${c.email} khỏi danh bạ.`);
    res.redirect('/email/contacts');
  })
);

/* ---------- Chiến dịch ---------- */

async function statsFor(id) {
  const s = await db.get(
    `SELECT COUNT(*) AS total,
       SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
       SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
       SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued,
       SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered,
       SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
       SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
       SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) AS bounced,
       SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) AS unsubscribed
     FROM campaign_recipients WHERE campaign_id = ?`,
    [id]
  );
  return Object.fromEntries(Object.entries(s).map(([k, v]) => [k, Number(v || 0)]));
}

async function loadCampaign(id) {
  const c = await db.get('SELECT c.*, u.name AS author_name FROM campaigns c LEFT JOIN users u ON u.id = c.created_by WHERE c.id = ?', [int(id)]);
  if (!c) throw notFound('chiến dịch');
  return c;
}

function readCampaign(body) {
  return {
    name: str(body.name, 200),
    subject: str(body.subject, 200),
    preheader: str(body.preheader, 200),
    body_html: str(body.body_html, 200000),
    audience_tag: str(body.audience_tag, 60).toLowerCase(),
  };
}

function validateCampaign(c) {
  if (!c.name) return 'Hãy đặt tên chiến dịch (chỉ hiển thị nội bộ).';
  if (!c.subject) return 'Hãy nhập tiêu đề email.';
  if (!c.body_html) return 'Nội dung email đang trống.';
  return null;
}

router.get(
  '/campaigns',
  h(async (req, res) => {
    const list = await db.all('SELECT c.*, u.name AS author_name FROM campaigns c LEFT JOIN users u ON u.id = c.created_by ORDER BY c.id DESC');
    for (const c of list) if (c.status !== 'draft') c.stats = await statsFor(c.id);
    const totals = await db.get('SELECT COUNT(*) AS total, SUM(subscribed) AS subscribed FROM contacts');
    res.render('email/campaigns', { title: 'Email marketing', list, subscribed: Number(totals.subscribed || 0) });
  })
);

const STARTER = `<p>Chào {{ten}},</p>
<p>Bin Bin Hotel gửi bạn ưu đãi dành riêng cho khách quen: <strong>giảm 15% khi đặt phòng trực tiếp</strong> trong tháng này.</p>
<p><a href="https://zalo.me/" style="display:inline-block;background:#c8232b;color:#ffffff;padding:12px 20px;border-radius:4px;text-decoration:none;">Nhắn Zalo để đặt phòng</a></p>
<p>Hẹn gặp lại bạn,<br>Bin Bin Hotel</p>`;

router.get(
  '/campaigns/new',
  requirePerm('campaign:edit'),
  h(async (req, res) => {
    res.render('email/campaign-form', {
      title: 'Chiến dịch mới',
      c: { name: '', subject: '', preheader: '', body_html: STARTER, audience_tag: '' },
      tags: await allTags(),
      error: null,
    });
  })
);

router.post(
  '/campaigns',
  requirePerm('campaign:edit'),
  h(async (req, res) => {
    const c = readCampaign(req.body);
    const error = validateCampaign(c);
    if (error) return res.status(400).render('email/campaign-form', { title: 'Chiến dịch mới', c, tags: await allTags(), error });
    const now = dates.now();
    const r = await db.run(
      'INSERT INTO campaigns (name, subject, preheader, body_html, audience_tag, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [c.name, c.subject, c.preheader, c.body_html, c.audience_tag || null, 'draft', req.user.id, now, now]
    );
    await logActivity(req.user, 'campaign.create', 'campaign', r.insertId, c.name);
    flash(req, 'success', 'Đã lưu chiến dịch. Xem trước rồi gửi thử cho chính bạn trước khi gửi thật.');
    res.redirect(`/email/campaigns/${r.insertId}`);
  })
);

router.get(
  '/campaigns/:id',
  h(async (req, res) => {
    const c = await loadCampaign(req.params.id);
    const stats = c.status === 'draft' ? null : await statsFor(c.id);
    const audienceCount = c.status === 'draft' ? (await campaigns.audience(c.audience_tag)).length : null;
    const recipients =
      c.status === 'draft'
        ? []
        : await db.all('SELECT * FROM campaign_recipients WHERE campaign_id = ? ORDER BY id LIMIT 300', [c.id]);
    res.render('email/campaign-show', { title: c.name, c, stats, audienceCount, recipients });
  })
);

// Bản xem trước hiển thị trong iframe, với CSP riêng (không cho chạy script)
router.get(
  '/campaigns/:id/preview',
  h(async (req, res) => {
    const c = await loadCampaign(req.params.id);
    const html = brevo.renderEmail({
      subject: c.subject,
      preheader: c.preheader,
      bodyHtml: campaigns.personalize(c.body_html, { name: req.user.name }),
      unsubscribeUrl: '#',
    });
    res.set('content-security-policy', "default-src 'none'; img-src * data:; style-src 'unsafe-inline'; font-src *");
    res.set('x-frame-options', 'SAMEORIGIN');
    res.type('html').send(html);
  })
);

router.get(
  '/campaigns/:id/edit',
  requirePerm('campaign:edit'),
  h(async (req, res) => {
    const c = await loadCampaign(req.params.id);
    if (c.status !== 'draft') {
      flash(req, 'error', 'Chiến dịch đã gửi không sửa được. Hãy nhân bản để tạo bản mới.');
      return res.redirect(`/email/campaigns/${c.id}`);
    }
    res.render('email/campaign-form', { title: 'Sửa chiến dịch', c, tags: await allTags(), error: null });
  })
);

router.post(
  '/campaigns/:id',
  requirePerm('campaign:edit'),
  h(async (req, res) => {
    const old = await loadCampaign(req.params.id);
    if (old.status !== 'draft') return res.redirect(`/email/campaigns/${old.id}`);
    const c = readCampaign(req.body);
    const error = validateCampaign(c);
    if (error) return res.status(400).render('email/campaign-form', { title: 'Sửa chiến dịch', c: { ...old, ...c }, tags: await allTags(), error });
    await db.run('UPDATE campaigns SET name = ?, subject = ?, preheader = ?, body_html = ?, audience_tag = ?, updated_at = ? WHERE id = ?', [
      c.name,
      c.subject,
      c.preheader,
      c.body_html,
      c.audience_tag || null,
      dates.now(),
      old.id,
    ]);
    flash(req, 'success', 'Đã lưu chiến dịch.');
    res.redirect(`/email/campaigns/${old.id}`);
  })
);

router.post(
  '/campaigns/:id/test',
  requirePerm('campaign:edit'),
  h(async (req, res) => {
    const c = await loadCampaign(req.params.id);
    const to = str(req.body.to, 190).toLowerCase() || req.user.email;
    if (!isEmail(to)) {
      flash(req, 'error', 'Email nhận thử không hợp lệ.');
      return res.redirect(`/email/campaigns/${c.id}`);
    }
    const html = brevo.renderEmail({
      subject: c.subject,
      preheader: c.preheader,
      bodyHtml: campaigns.personalize(c.body_html, { name: req.user.name }),
      unsubscribeUrl: `${config.appUrl}/`,
    });
    try {
      const out = await brevo.send({ to, subject: `[Thử] ${c.subject}`, html, tag: 'test' });
      flash(req, 'success', out.dryRun ? `Chế độ thử: chưa cấu hình Brevo nên không có email nào được gửi tới ${to}.` : `Đã gửi email thử tới ${to}.`);
    } catch (err) {
      flash(req, 'error', `Brevo không gửi được: ${err.message}`);
    }
    res.redirect(`/email/campaigns/${c.id}`);
  })
);

router.post(
  '/campaigns/:id/send',
  requirePerm('campaign:send'),
  h(async (req, res) => {
    const c = await loadCampaign(req.params.id);
    if (c.status !== 'draft') {
      flash(req, 'error', 'Chiến dịch này đã được gửi.');
      return res.redirect(`/email/campaigns/${c.id}`);
    }
    const n = await campaigns.enqueue(c);
    if (n === 0) {
      await db.run(`UPDATE campaigns SET status = 'draft', updated_at = ? WHERE id = ?`, [dates.now(), c.id]);
      flash(req, 'error', 'Không có người nhận nào trong nhóm đã chọn.');
      return res.redirect(`/email/campaigns/${c.id}`);
    }
    await db.run('UPDATE campaigns SET sent_by = ? WHERE id = ?', [req.user.id, c.id]);
    campaigns.run(c.id);
    await logActivity(req.user, 'campaign.send', 'campaign', c.id, `${c.name} → ${n} người`);
    flash(req, 'success', `Đang gửi tới ${n} người. Trang này sẽ cập nhật khi bạn tải lại.`);
    res.redirect(`/email/campaigns/${c.id}`);
  })
);

router.post(
  '/campaigns/:id/duplicate',
  requirePerm('campaign:edit'),
  h(async (req, res) => {
    const c = await loadCampaign(req.params.id);
    const now = dates.now();
    const r = await db.run(
      'INSERT INTO campaigns (name, subject, preheader, body_html, audience_tag, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [`${c.name} (bản sao)`, c.subject, c.preheader, c.body_html, c.audience_tag, 'draft', req.user.id, now, now]
    );
    flash(req, 'success', 'Đã nhân bản chiến dịch.');
    res.redirect(`/email/campaigns/${r.insertId}/edit`);
  })
);

router.post(
  '/campaigns/:id/delete',
  requirePerm('campaign:edit'),
  h(async (req, res) => {
    const c = await loadCampaign(req.params.id);
    if (c.status !== 'draft' && req.user.role !== 'admin') {
      flash(req, 'error', 'Chỉ quản trị viên mới xoá được chiến dịch đã gửi.');
      return res.redirect(`/email/campaigns/${c.id}`);
    }
    if (c.status === 'sending') {
      flash(req, 'error', 'Chiến dịch đang gửi, chờ gửi xong rồi mới xoá được.');
      return res.redirect(`/email/campaigns/${c.id}`);
    }
    await db.run('DELETE FROM campaign_recipients WHERE campaign_id = ?', [c.id]);
    await db.run('DELETE FROM campaigns WHERE id = ?', [c.id]);
    await logActivity(req.user, 'campaign.delete', 'campaign', c.id, c.name);
    flash(req, 'success', 'Đã xoá chiến dịch.');
    res.redirect('/email/campaigns');
  })
);

module.exports = router;
