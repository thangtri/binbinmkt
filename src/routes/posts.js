const express = require('express');
const db = require('../db');
const dates = require('../lib/dates');
const { CHANNELS, POST_FORMATS, STATUSES } = require('../lib/constants');
const postChannels = require('../lib/post-channels');
const { can, requirePerm } = require('../lib/auth');
const upload = require('../lib/upload');
const { h, flash, logActivity, str, int, safeUrl } = require('../lib/util');

const router = express.Router();
const PAGE = 30;

function notFound() {
  const err = new Error('Không tìm thấy bài đăng này.');
  err.status = 404;
  err.expose = true;
  return err;
}

async function load(id) {
  const post = await db.get(
    `SELECT p.*, a.name AS author_name, r.name AS reviewer_name, e.title AS event_title
     FROM posts p
     LEFT JOIN users a ON a.id = p.author_id
     LEFT JOIN users r ON r.id = p.reviewer_id
     LEFT JOIN events e ON e.id = p.event_id
     WHERE p.id = ?`,
    [id]
  );
  if (!post) throw notFound();
  return post;
}

function canEdit(user, post) {
  if (user.role === 'admin') return true;
  return can(user, 'post:edit') && post.status !== 'published';
}

function canDelete(user, post) {
  if (user.role === 'admin') return true;
  return can(user, 'post:edit') && post.author_id === user.id && post.status !== 'published';
}

async function eventOptions() {
  const today = dates.today();
  return db.all(
    `SELECT id, title, start_date, recurring FROM events
     WHERE recurring = 1 OR end_date >= ? ORDER BY recurring, start_date`,
    [dates.addDays(today, -30)]
  );
}

function readForm(body) {
  const date = dates.isDate(body.date) ? body.date : '';
  const time = /^\d{2}:\d{2}$/.test(body.time || '') ? body.time : '09:00';
  return {
    title: str(body.title, 200),
    format: POST_FORMATS.includes(body.format) ? body.format : POST_FORMATS[0],
    caption: str(body.caption, 60000),
    hashtags: str(body.hashtags, 2000),
    scheduled_at: date ? `${date} ${time}:00` : null,
    event_id: int(body.event_id) || null,
    notes: str(body.notes, 5000),
  };
}

function validate(p, intent) {
  if (!p.title) return 'Hãy đặt tiêu đề cho bài.';
  if ((intent === 'submit' || intent === 'approve') && !p.scheduled_at) return 'Cần chọn ngày đăng trước khi gửi duyệt.';
  return null;
}

router.get(
  '/',
  h(async (req, res) => {
    const where = [];
    const params = [];
    const { status, q } = req.query;
    if (STATUSES[status]) {
      where.push('p.status = ?');
      params.push(status);
    }
    if (q) {
      where.push('(p.title LIKE ? OR p.caption LIKE ?)');
      params.push(`%${q}%`, `%${q}%`);
    }
    if (req.query.mine) {
      where.push('p.author_id = ?');
      params.push(req.user.id);
    }
    const sqlWhere = where.length ? 'WHERE ' + where.join(' AND ') : '';
    const page = Math.max(1, int(req.query.page, 1));
    const total = Number((await db.get(`SELECT COUNT(*) AS n FROM posts p ${sqlWhere}`, params)).n);
    const posts = await db.all(
      `SELECT p.*, u.name AS author_name, e.title AS event_title FROM posts p
       LEFT JOIN users u ON u.id = p.author_id LEFT JOIN events e ON e.id = p.event_id
       ${sqlWhere}
       ORDER BY p.scheduled_at IS NULL DESC, p.scheduled_at DESC, p.id DESC
       LIMIT ${PAGE} OFFSET ${(page - 1) * PAGE}`,
      params
    );
    const counts = {};
    for (const r of await db.all('SELECT status, COUNT(*) AS n FROM posts GROUP BY status')) counts[r.status] = Number(r.n);
    res.render('posts/index', { title: 'Bài đăng', posts, total, page, pages: Math.ceil(total / PAGE) || 1, counts });
  })
);

router.get(
  '/new',
  requirePerm('post:create'),
  h(async (req, res) => {
    const post = {
      title: '',
      format: POST_FORMATS[0],
      caption: '',
      hashtags: '#BinBinHotel',
      scheduled_at: dates.isDate(req.query.date) ? `${req.query.date} 09:00:00` : null,
      event_id: int(req.query.event) || null,
      notes: '',
      status: 'draft',
    };
    res.render('posts/form', { title: 'Soạn bài mới', post, events: await eventOptions(), error: null });
  })
);

async function afterSave(req, id, intent, wasStatus) {
  if (intent === 'submit' && ['draft', 'pending'].includes(wasStatus)) {
    await db.run(`UPDATE posts SET status = 'pending', review_note = NULL WHERE id = ?`, [id]);
  } else if (intent === 'approve' && req.user.role === 'admin' && wasStatus !== 'published') {
    await db.run(`UPDATE posts SET status = 'scheduled', reviewer_id = ? WHERE id = ?`, [req.user.id, id]);
  }
}

router.post(
  '/',
  requirePerm('post:create'),
  upload.single('media'),
  h(async (req, res) => {
    const p = readForm(req.body);
    const intent = req.body.intent;
    const error = validate(p, intent);
    if (error) {
      if (req.file) upload.remove(upload.relPath(req.file));
      return res.status(400).render('posts/form', { title: 'Soạn bài mới', post: { ...p, status: 'draft' }, events: await eventOptions(), error });
    }
    const now = dates.now();
    const r = await db.run(
      `INSERT INTO posts (title, channel, format, caption, hashtags, media_path, scheduled_at, status, event_id, author_id, notes, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?)`,
      [p.title, 'all', p.format, p.caption, p.hashtags, req.file ? upload.relPath(req.file) : null, p.scheduled_at, p.event_id, req.user.id, p.notes, now, now]
    );
    await afterSave(req, r.insertId, intent, 'draft');
    await postChannels.ensure(r.insertId);
    await logActivity(req.user, 'post.create', 'post', r.insertId, p.title);
    flash(req, 'success', intent === 'submit' ? 'Đã lưu và gửi duyệt.' : intent === 'approve' ? 'Đã lưu và duyệt bài.' : 'Đã lưu bản nháp.');
    res.redirect(`/posts/${r.insertId}`);
  })
);

router.get(
  '/:id',
  h(async (req, res) => {
    const post = await load(req.params.id);
    res.render('posts/show', {
      title: post.title,
      post,
      channels: await postChannels.list(post.id),
      canEdit: canEdit(req.user, post),
      canDelete: canDelete(req.user, post),
    });
  })
);

router.get(
  '/:id/edit',
  h(async (req, res) => {
    const post = await load(req.params.id);
    if (!canEdit(req.user, post)) return requirePerm('__never__')(req, res);
    res.render('posts/form', { title: 'Sửa bài', post, events: await eventOptions(), error: null });
  })
);

router.post(
  '/:id',
  upload.single('media'),
  h(async (req, res) => {
    const post = await load(req.params.id);
    if (!canEdit(req.user, post)) return requirePerm('__never__')(req, res);
    const p = readForm(req.body);
    const intent = req.body.intent;
    const error = validate(p, intent);
    if (error) {
      if (req.file) upload.remove(upload.relPath(req.file));
      return res.status(400).render('posts/form', { title: 'Sửa bài', post: { ...post, ...p }, events: await eventOptions(), error });
    }
    let media = post.media_path;
    if (req.file || req.body.remove_media) {
      upload.remove(media);
      media = req.file ? upload.relPath(req.file) : null;
    }
    // Biên tập viên sửa bài đã duyệt → bài cần được duyệt lại
    let status = post.status;
    if (req.user.role !== 'admin' && status === 'scheduled') status = 'pending';
    await db.run(
      `UPDATE posts SET title = ?, format = ?, caption = ?, hashtags = ?, media_path = ?, scheduled_at = ?,
       event_id = ?, notes = ?, status = ?, updated_at = ? WHERE id = ?`,
      [p.title, p.format, p.caption, p.hashtags, media, p.scheduled_at, p.event_id, p.notes, status, dates.now(), post.id]
    );
    await afterSave(req, post.id, intent, status);
    await logActivity(req.user, 'post.update', 'post', post.id, p.title);
    flash(req, 'success', status === 'pending' && post.status === 'scheduled' ? 'Đã lưu. Bài sửa sau khi duyệt cần được duyệt lại.' : 'Đã lưu thay đổi.');
    res.redirect(`/posts/${post.id}`);
  })
);

router.post(
  '/:id/submit',
  requirePerm('post:submit'),
  h(async (req, res) => {
    const post = await load(req.params.id);
    if (post.status !== 'draft') {
      flash(req, 'error', 'Chỉ bản nháp mới gửi duyệt được.');
    } else if (!post.scheduled_at) {
      flash(req, 'error', 'Cần chọn ngày đăng trước khi gửi duyệt.');
    } else {
      await db.run(`UPDATE posts SET status = 'pending', review_note = NULL, updated_at = ? WHERE id = ?`, [dates.now(), post.id]);
      await logActivity(req.user, 'post.submit', 'post', post.id, post.title);
      flash(req, 'success', 'Đã gửi duyệt.');
    }
    res.redirect(`/posts/${post.id}`);
  })
);

router.post(
  '/:id/approve',
  requirePerm('post:approve'),
  h(async (req, res) => {
    const post = await load(req.params.id);
    if (post.status !== 'pending') {
      flash(req, 'error', 'Bài này không ở trạng thái chờ duyệt.');
    } else {
      await db.run(`UPDATE posts SET status = 'scheduled', reviewer_id = ?, review_note = ?, updated_at = ? WHERE id = ?`, [
        req.user.id,
        str(req.body.note, 2000) || null,
        dates.now(),
        post.id,
      ]);
      await logActivity(req.user, 'post.approve', 'post', post.id, post.title);
      flash(req, 'success', 'Đã duyệt bài.');
    }
    res.redirect(req.body.back === 'dashboard' ? '/' : `/posts/${post.id}`);
  })
);

router.post(
  '/:id/reject',
  requirePerm('post:approve'),
  h(async (req, res) => {
    const post = await load(req.params.id);
    const note = str(req.body.note, 2000);
    if (!note) {
      flash(req, 'error', 'Hãy ghi lý do để người soạn biết cần sửa gì.');
    } else {
      await db.run(`UPDATE posts SET status = 'draft', reviewer_id = ?, review_note = ?, updated_at = ? WHERE id = ?`, [
        req.user.id,
        note,
        dates.now(),
        post.id,
      ]);
      await logActivity(req.user, 'post.reject', 'post', post.id, post.title);
      flash(req, 'success', 'Đã trả bài về nháp kèm góp ý.');
    }
    res.redirect(`/posts/${post.id}`);
  })
);

// Link của bài trên từng kênh: url_facebook, url_tiktok, url_google, url_zalo
async function saveUrls(postId, body) {
  for (const ch of Object.keys(CHANNELS)) {
    if (body[`url_${ch}`] === undefined) continue;
    await postChannels.update(postId, ch, { published_url: safeUrl(body[`url_${ch}`]) || null });
  }
}

router.post(
  '/:id/publish',
  requirePerm('post:publish'),
  h(async (req, res) => {
    const post = await load(req.params.id);
    if (post.status !== 'scheduled') {
      flash(req, 'error', 'Chỉ bài đã duyệt mới đánh dấu đã đăng được.');
    } else {
      await db.run(`UPDATE posts SET status = 'published', published_at = ?, updated_at = ? WHERE id = ?`, [dates.now(), dates.now(), post.id]);
      await saveUrls(post.id, req.body);
      await postChannels.recalcTotals(post.id);
      await logActivity(req.user, 'post.publish', 'post', post.id, post.title);
      flash(req, 'success', 'Đã đánh dấu đã đăng. Dán link bài trên từng kênh để hệ thống tự cập nhật lượt thích, bình luận.');
    }
    res.redirect(req.body.back === 'dashboard' ? '/' : `/posts/${post.id}`);
  })
);

// Nhập link và số liệu theo từng kênh (số liệu Facebook, TikTok tự cập nhật nếu kênh đã kết nối)
router.post(
  '/:id/metrics',
  requirePerm('post:publish'),
  h(async (req, res) => {
    const post = await load(req.params.id);
    await saveUrls(post.id, req.body);
    const n = (v) => (String(v ?? '').trim() === '' ? null : Math.max(0, int(v)));
    for (const ch of Object.keys(CHANNELS)) {
      const fields = {};
      for (const m of ['views', 'likes', 'comments', 'shares']) {
        if (req.body[`${m}_${ch}`] !== undefined) fields[m] = n(req.body[`${m}_${ch}`]);
      }
      await postChannels.update(post.id, ch, fields);
    }
    await postChannels.recalcTotals(post.id);
    await db.run('UPDATE posts SET updated_at = ? WHERE id = ?', [dates.now(), post.id]);
    flash(req, 'success', 'Đã lưu link và số liệu.');
    res.redirect(`/posts/${post.id}`);
  })
);

// Kéo thả trên lịch: đổi ngày, giữ nguyên giờ
router.post(
  '/:id/reschedule',
  requirePerm('post:edit'),
  h(async (req, res) => {
    const post = await load(req.params.id);
    if (post.status === 'published') return res.status(400).json({ error: 'Bài đã đăng không đổi ngày được.' });
    if (!dates.isDate(req.body.date)) return res.status(400).json({ error: 'Ngày không hợp lệ.' });
    const time = post.scheduled_at ? post.scheduled_at.slice(11, 19) : '09:00:00';
    await db.run('UPDATE posts SET scheduled_at = ?, updated_at = ? WHERE id = ?', [`${req.body.date} ${time}`, dates.now(), post.id]);
    await logActivity(req.user, 'post.reschedule', 'post', post.id, `${post.title} → ${dates.dmy(req.body.date)}`);
    res.json({ ok: true });
  })
);

router.post(
  '/:id/duplicate',
  requirePerm('post:create'),
  h(async (req, res) => {
    const post = await load(req.params.id);
    const now = dates.now();
    const r = await db.run(
      `INSERT INTO posts (title, channel, format, caption, hashtags, media_path, scheduled_at, status, event_id, author_id, notes, created_at, updated_at)
       VALUES (?, 'all', ?, ?, ?, NULL, NULL, 'draft', ?, ?, ?, ?, ?)`,
      [`${post.title} (bản sao)`, post.format, post.caption, post.hashtags, post.event_id, req.user.id, post.notes, now, now]
    );
    await postChannels.ensure(r.insertId);
    await logActivity(req.user, 'post.create', 'post', r.insertId, `${post.title} (bản sao)`);
    flash(req, 'success', 'Đã tạo bản sao ở dạng nháp. Chọn ngày đăng mới và chỉnh nội dung nếu cần.');
    res.redirect(`/posts/${r.insertId}/edit`);
  })
);

router.post(
  '/:id/delete',
  h(async (req, res) => {
    const post = await load(req.params.id);
    if (!canDelete(req.user, post)) return requirePerm('__never__')(req, res);
    await db.run('DELETE FROM post_channels WHERE post_id = ?', [post.id]);
    await db.run('DELETE FROM posts WHERE id = ?', [post.id]);
    upload.remove(post.media_path);
    await logActivity(req.user, 'post.delete', 'post', post.id, post.title);
    flash(req, 'success', 'Đã xoá bài.');
    res.redirect('/posts');
  })
);

module.exports = router;
