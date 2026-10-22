// Tự đăng bài đã duyệt lên Facebook và TikTok khi đến giờ.
// Google Maps và Zalo OA vẫn đăng tay (cần quyền API riêng chưa hỗ trợ).
const crypto = require('crypto');
const path = require('path');
const db = require('../db');
const config = require('../config');
const dates = require('./dates');
const media = require('./media');
const postChannels = require('./post-channels');
const { CHANNELS } = require('./constants');
const { logActivity } = require('./util');

const AUTO = ['facebook', 'tiktok'];
const MANUAL = Object.keys(CHANNELS).filter((c) => !AUTO.includes(c));

// Link ảnh công khai có chữ ký, hết hạn sau 24 giờ (TikTok tự tải ảnh về từ link này)
function sign(id, exp) {
  return crypto.createHmac('sha256', String(config.encryptionKey)).update(`media:${id}:${exp}`).digest('hex').slice(0, 32);
}
function publicMediaUrl(id) {
  const exp = Math.floor(Date.now() / 1000) + 24 * 3600;
  return `${config.appUrl}/m/${id}/${exp}/${sign(id, exp)}`;
}
function verifyMedia(id, exp, sig) {
  if (Number(exp) < Date.now() / 1000) return false;
  const want = Buffer.from(sign(id, exp));
  const got = Buffer.from(String(sig || ''));
  return want.length === got.length && crypto.timingSafeEqual(want, got);
}

function message(post) {
  return [post.caption, post.hashtags].filter((s) => s && String(s).trim()).join('\n\n');
}

async function files(postId) {
  return (await media.list(postId)).map((m) => ({
    id: m.id,
    path: path.resolve(config.uploadDir, m.path),
    mime: m.mime,
    isVideo: media.isVideo(m),
    publicUrl: publicMediaUrl(m.id),
  }));
}

async function setState(postId, channel, fields) {
  await postChannels.update(postId, channel, fields);
}

// Khi cả 4 kênh đều có link (tự đăng hoặc dán tay) thì bài chuyển sang Đã đăng
async function maybeComplete(postId) {
  const rows = await postChannels.list(postId);
  if (rows.every((r) => r.published_url || r.publish_status === 'done')) {
    const res = await db.run(`UPDATE posts SET status = 'published', published_at = COALESCE(published_at, ?), updated_at = ? WHERE id = ? AND status = 'scheduled'`, [
      dates.now(),
      dates.now(),
      postId,
    ]);
    if (res.changes) await logActivity(null, 'post.publish', 'post', postId, 'Tự đăng xong trên tất cả các kênh');
  }
  await postChannels.recalcTotals(postId);
}

async function publishChannel(post, channel) {
  const sync = require('./sync');
  const conn = sync.connectors[channel];
  const account = await db.get('SELECT * FROM channel_accounts WHERE channel = ?', [channel]);
  const token = await sync.freshToken(channel);
  if (!conn.isConfigured() || !sync.isConnected(account, token)) return { skipped: true };

  // Giành quyền đăng: chỉ một tiến trình được đăng một bài lên một kênh
  await postChannels.ensure(post.id);
  const claim = await db.run(
    `UPDATE post_channels SET publish_status = 'publishing', publish_error = NULL, updated_at = ?
     WHERE post_id = ? AND channel = ? AND publish_status IS NULL AND published_url IS NULL`,
    [dates.now(), post.id, channel]
  );
  if (!claim.changes) return { skipped: true };

  try {
    const out = await conn.publish(account, token, { message: message(post), files: await files(post.id) });
    if (out.pending) {
      await setState(post.id, channel, { publish_status: `processing:${out.kind}`, external_id: out.externalId });
    } else {
      await setState(post.id, channel, { publish_status: 'done', published_url: out.url, external_id: out.externalId, publish_error: out.note || null });
      await logActivity(null, 'post.autopublish', 'post', post.id, `${CHANNELS[channel].name}: ${post.title}`);
    }
    return { ok: true };
  } catch (err) {
    await setState(post.id, channel, { publish_status: 'failed', publish_error: String(err.message || err).slice(0, 500) });
    return { ok: false, error: err.message };
  }
}

async function publishPost(postId) {
  const post = await db.get('SELECT * FROM posts WHERE id = ?', [postId]);
  if (!post || post.status !== 'scheduled') return [];
  const results = [];
  for (const ch of AUTO) results.push({ channel: ch, ...(await publishChannel(post, ch)) });
  await maybeComplete(post.id);
  return results;
}

// TikTok xử lý bài bất đồng bộ: hỏi lại trạng thái cho tới khi xong
async function checkProcessing() {
  const sync = require('./sync');
  const rows = await db.all(`SELECT * FROM post_channels WHERE publish_status LIKE 'processing:%'`);
  for (const r of rows) {
    const conn = sync.connectors[r.channel];
    if (!conn.publishStatus) continue;
    try {
      const account = await db.get('SELECT * FROM channel_accounts WHERE channel = ?', [r.channel]);
      const token = await sync.freshToken(r.channel);
      const st = await conn.publishStatus(account, token, r.external_id, r.publish_status.split(':')[1]);
      if (st.failed) await setState(r.post_id, r.channel, { publish_status: 'failed', publish_error: st.error });
      else if (st.done) {
        await setState(r.post_id, r.channel, { publish_status: 'done', published_url: st.url, publish_error: st.note || null });
        const post = await db.get('SELECT title FROM posts WHERE id = ?', [r.post_id]);
        await logActivity(null, 'post.autopublish', 'post', r.post_id, `${CHANNELS[r.channel].name}: ${post?.title || ''}`);
        await maybeComplete(r.post_id);
      }
    } catch (err) {
      console.error('[publish] Lỗi khi kiểm tra trạng thái', r.channel, r.post_id, err.message);
    }
  }
}

let running = false;
async function tick() {
  if (running) return;
  running = true;
  try {
    const due = await db.all(
      `SELECT id FROM posts WHERE status = 'scheduled' AND auto_publish = 1 AND scheduled_at IS NOT NULL AND scheduled_at <= ? ORDER BY scheduled_at LIMIT 20`,
      [dates.now()]
    );
    for (const p of due) await publishPost(p.id);
    await checkProcessing();
  } catch (err) {
    console.error('[publish] Lỗi khi tự đăng bài', err);
  } finally {
    running = false;
  }
}

function start() {
  setTimeout(tick, 20 * 1000);
  setInterval(tick, 60 * 1000).unref();
}

// Cho phép thử lại một kênh bị lỗi
async function retry(postId, channel) {
  await db.run(`UPDATE post_channels SET publish_status = NULL, publish_error = NULL WHERE post_id = ? AND channel = ? AND publish_status = 'failed'`, [
    postId,
    channel,
  ]);
  return publishPost(postId);
}

module.exports = { AUTO, MANUAL, start, tick, publishPost, retry, verifyMedia, publicMediaUrl };
