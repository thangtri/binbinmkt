// TikTok qua TikTok for Developers (Login Kit + Display API).
// Access token sống 24 giờ, refresh token 365 ngày; hệ thống tự làm mới.
const fs = require('fs');
const config = require('../../config');
const { request, ConnectorError } = require('./http');

// video.publish: để tự đăng video và ảnh lên TikTok
const SCOPES = ['user.info.basic', 'user.info.profile', 'user.info.stats', 'video.list', 'video.publish'];
const API = 'https://open.tiktokapis.com/v2';

function isConfigured() {
  return Boolean(config.tiktok.clientKey && config.tiktok.clientSecret);
}

function authUrl({ redirectUri, state }) {
  const q = new URLSearchParams({ client_key: config.tiktok.clientKey, scope: SCOPES.join(','), response_type: 'code', redirect_uri: redirectUri, state });
  return `https://www.tiktok.com/v2/auth/authorize/?${q}`;
}

function tokenResult(d) {
  if (!d?.access_token) throw new ConnectorError(d?.error_description || 'TikTok không trả về token.', { needsReconnect: true });
  return {
    accessToken: d.access_token,
    refreshToken: d.refresh_token,
    expiresIn: d.expires_in,
    refreshExpiresIn: d.refresh_expires_in,
    openId: d.open_id,
  };
}

async function exchange({ code, redirectUri }) {
  const res = await request(`${API}/oauth/token/`, {
    method: 'POST',
    form: { client_key: config.tiktok.clientKey, client_secret: config.tiktok.clientSecret, code, grant_type: 'authorization_code', redirect_uri: redirectUri },
  });
  return tokenResult(res.data);
}

async function refresh(refreshToken) {
  const res = await request(`${API}/oauth/token/`, {
    method: 'POST',
    form: { client_key: config.tiktok.clientKey, client_secret: config.tiktok.clientSecret, grant_type: 'refresh_token', refresh_token: refreshToken },
  });
  return tokenResult(res.data);
}

function check(res) {
  const code = res.data?.error?.code;
  if (res.ok && (!code || code === 'ok')) return res.data?.data || {};
  const msg = res.data?.error?.message || `TikTok trả về lỗi ${res.status}`;
  throw new ConnectorError(msg, { needsReconnect: code === 'access_token_invalid' || res.status === 401 });
}

async function fetchStats(account, token) {
  if (!token?.access_token) throw new ConnectorError('Chưa kết nối TikTok.', { needsReconnect: true });
  const auth = { authorization: `Bearer ${token.access_token}` };
  const user = check(
    await request(`${API}/user/info/?fields=open_id,display_name,username,profile_deep_link,follower_count,likes_count,video_count`, { headers: auth })
  ).user || {};

  const list = check(
    await request(`${API}/video/list/?fields=id,title,video_description,share_url,create_time,view_count,like_count,comment_count,share_count`, {
      method: 'POST',
      headers: auth,
      json: { max_count: 20 },
    })
  );
  const since = Date.now() / 1000 - 28 * 86400;
  const videos = (list.videos || []).map((v) => ({
    url: v.share_url,
    title: String(v.title || v.video_description || '').slice(0, 120),
    createdAt: v.create_time ? new Date(v.create_time * 1000).toISOString() : null,
    recent: v.create_time >= since,
    views: v.view_count || 0,
    likes: v.like_count || 0,
    comments: v.comment_count || 0,
    shares: v.share_count || 0,
  }));
  const recent = videos.filter((v) => v.recent);

  return {
    followers: user.follower_count ?? null,
    reach: recent.reduce((s, v) => s + v.views, 0),
    engagement: recent.reduce((s, v) => s + v.likes + v.comments + v.shares, 0),
    profile: { name: user.display_name, handle: user.username ? `@${user.username}` : null, url: user.profile_deep_link, likes: user.likes_count, videos: user.video_count },
    posts: videos,
  };
}

/* ---------- Tự đăng bài (Content Posting API, đăng thẳng) ---------- */

async function privacyLevel(auth) {
  const info = check(await request(`${API}/post/publish/creator_info/query/`, { method: 'POST', headers: auth, json: {} }));
  const options = info.privacy_level_options || [];
  // App chưa được TikTok duyệt chỉ đăng được ở chế độ riêng tư
  return options.includes('PUBLIC_TO_EVERYONE') ? 'PUBLIC_TO_EVERYONE' : options[0] || 'SELF_ONLY';
}

// files: [{ path, mime, isVideo, publicUrl }]; ảnh cần publicUrl trên tên miền đã xác minh với TikTok
async function publish(account, token, { message, files }) {
  if (!token?.access_token) throw new ConnectorError('Chưa kết nối TikTok.', { needsReconnect: true });
  const auth = { authorization: `Bearer ${token.access_token}` };
  const video = files.find((x) => x.isVideo);
  const photos = files.filter((x) => !x.isVideo && /jpe?g|webp/i.test(x.mime || x.path));
  if (!video && !photos.length) {
    throw new ConnectorError(files.length ? 'TikTok chỉ nhận ảnh JPG hoặc WebP, hoặc video.' : 'TikTok cần ít nhất một ảnh hoặc video.');
  }
  const privacy = await privacyLevel(auth);
  if (video) {
    const size = fs.statSync(video.path).size;
    const init = check(
      await request(`${API}/post/publish/video/init/`, {
        method: 'POST',
        headers: auth,
        json: {
          post_info: { title: message.slice(0, 2200), privacy_level: privacy, disable_comment: false, disable_duet: false, disable_stitch: false },
          source_info: { source: 'FILE_UPLOAD', video_size: size, chunk_size: size, total_chunk_count: 1 },
        },
      })
    );
    let res;
    try {
      res = await fetch(init.upload_url, {
        method: 'PUT',
        headers: { 'content-type': video.mime || 'video/mp4', 'content-length': String(size), 'content-range': `bytes 0-${size - 1}/${size}` },
        body: await fs.openAsBlob(video.path),
        signal: AbortSignal.timeout(10 * 60 * 1000),
      });
    } catch {
      throw new ConnectorError('Không tải được video lên TikTok.');
    }
    if (!res.ok) throw new ConnectorError(`Tải video lên TikTok lỗi ${res.status}.`);
    return { pending: true, externalId: init.publish_id, privacy, kind: 'video' };
  }
  const init = check(
    await request(`${API}/post/publish/content/init/`, {
      method: 'POST',
      headers: auth,
      json: {
        post_info: { title: message.split('\n')[0].slice(0, 90), description: message.slice(0, 4000), privacy_level: privacy, disable_comment: false, auto_add_music: true },
        source_info: { source: 'PULL_FROM_URL', photo_cover_index: 0, photo_images: photos.slice(0, 35).map((p) => p.publicUrl) },
        post_mode: 'DIRECT_POST',
        media_type: 'PHOTO',
      },
    })
  );
  return { pending: true, externalId: init.publish_id, privacy, kind: 'photo' };
}

// Kiểm tra bài đã xử lý xong chưa; khi xong trả về link bài (nếu bài công khai)
async function publishStatus(account, token, publishId, kind) {
  const auth = { authorization: `Bearer ${token.access_token}` };
  const d = check(await request(`${API}/post/publish/status/fetch/`, { method: 'POST', headers: auth, json: { publish_id: publishId } }));
  if (d.status === 'FAILED') return { failed: true, error: `TikTok từ chối bài: ${d.fail_reason || 'không rõ lý do'}` };
  if (d.status !== 'PUBLISH_COMPLETE') return { done: false };
  const id = (d.publicaly_available_post_id || [])[0];
  let handle = null;
  try {
    handle = JSON.parse(account.extra || '{}').profile?.handle;
  } catch {
    handle = null;
  }
  const url = id && handle ? `https://www.tiktok.com/${handle}/${kind === 'photo' ? 'photo' : 'video'}/${id}` : null;
  return { done: true, url, note: id ? null : 'Bài đã lên TikTok ở chế độ riêng tư vì app chưa được TikTok duyệt quyền đăng công khai.' };
}

module.exports = { key: 'tiktok', oauth: true, isConfigured, authUrl, exchange, refresh, fetchStats, publish, publishStatus };
