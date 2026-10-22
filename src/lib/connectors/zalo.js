// Zalo OA qua Zalo Official Account API (OAuth v4 có PKCE).
// Access token sống khoảng 25 giờ; refresh token chỉ dùng được một lần, nên luôn lưu cái mới.
const crypto = require('crypto');
const config = require('../../config');
const { request, ConnectorError } = require('./http');

const OAUTH = 'https://oauth.zaloapp.com/v4/oa';

function isConfigured() {
  return Boolean(config.zalo.appId && config.zalo.appSecret);
}

function pkce() {
  const verifier = crypto.randomBytes(32).toString('base64url');
  const challenge = crypto.createHash('sha256').update(verifier).digest('base64url');
  return { verifier, challenge };
}

function authUrl({ redirectUri, state, challenge }) {
  const q = new URLSearchParams({ app_id: config.zalo.appId, redirect_uri: redirectUri, code_challenge: challenge, state });
  return `${OAUTH}/permission?${q}`;
}

function tokenResult(res) {
  const d = res.data || {};
  if (!d.access_token) throw new ConnectorError(d.error_description || d.error_name || 'Zalo không trả về token.', { needsReconnect: true });
  return { accessToken: d.access_token, refreshToken: d.refresh_token, expiresIn: Number(d.expires_in) || 90000, refreshExpiresIn: 90 * 86400 };
}

async function exchange({ code, verifier }) {
  return tokenResult(
    await request(`${OAUTH}/access_token`, {
      method: 'POST',
      headers: { secret_key: config.zalo.appSecret },
      form: { app_id: config.zalo.appId, code, grant_type: 'authorization_code', code_verifier: verifier },
    })
  );
}

async function refresh(refreshToken) {
  return tokenResult(
    await request(`${OAUTH}/access_token`, {
      method: 'POST',
      headers: { secret_key: config.zalo.appSecret },
      form: { app_id: config.zalo.appId, refresh_token: refreshToken, grant_type: 'refresh_token' },
    })
  );
}

async function fetchStats(account, token) {
  if (!token?.access_token) throw new ConnectorError('Chưa kết nối Zalo OA.', { needsReconnect: true });
  const res = await request('https://openapi.zalo.me/v2.0/oa/getoa', { headers: { access_token: token.access_token } });
  const d = res.data || {};
  if (!res.ok || (d.error && d.error !== 0)) {
    // -216: token không hợp lệ hoặc hết hạn
    throw new ConnectorError(d.message || `Zalo trả về lỗi ${res.status}`, { needsReconnect: d.error === -216 });
  }
  const oa = d.data || {};
  return {
    followers: oa.num_follower ?? null,
    reach: null,
    engagement: null,
    profile: { name: oa.name, url: oa.oa_id ? `https://zalo.me/${oa.oa_id}` : null, oaId: oa.oa_id },
    posts: [],
  };
}

module.exports = { key: 'zalo', oauth: true, isConfigured, pkce, authUrl, exchange, refresh, fetchStats };
