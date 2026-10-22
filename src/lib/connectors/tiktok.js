// TikTok qua TikTok for Developers (Login Kit + Display API).
// Access token sống 24 giờ, refresh token 365 ngày; hệ thống tự làm mới.
const config = require('../../config');
const { request, ConnectorError } = require('./http');

const SCOPES = ['user.info.basic', 'user.info.profile', 'user.info.stats', 'video.list'];
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

module.exports = { key: 'tiktok', oauth: true, isConfigured, authUrl, exchange, refresh, fetchStats };
