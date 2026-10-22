// Facebook Page qua Graph API. Đăng nhập Facebook một lần → lấy Page token (không hết hạn khi
// xuất phát từ token người dùng dài hạn) → đọc người theo dõi và tương tác của các bài gần đây.
const config = require('../../config');
const { request, ConnectorError } = require('./http');

const SCOPES = ['pages_show_list', 'pages_read_engagement', 'read_insights'];

function graph(path) {
  return `https://graph.facebook.com/${config.facebook.graphVersion}${path}`;
}

function isConfigured() {
  return Boolean(config.facebook.appId && config.facebook.appSecret);
}

function check(res) {
  if (res.ok && !res.data?.error) return res.data;
  const e = res.data?.error || {};
  // 190: token hết hạn hoặc bị thu hồi
  throw new ConnectorError(e.message || `Facebook trả về lỗi ${res.status}`, { needsReconnect: e.code === 190 });
}

function authUrl({ redirectUri, state }) {
  const q = new URLSearchParams({ client_id: config.facebook.appId, redirect_uri: redirectUri, state, scope: SCOPES.join(','), response_type: 'code' });
  return `https://www.facebook.com/${config.facebook.graphVersion}/dialog/oauth?${q}`;
}

// Đổi code → token người dùng dài hạn → danh sách Page mà tài khoản quản lý
async function exchange({ code, redirectUri }) {
  const short = check(
    await request(graph('/oauth/access_token?') + new URLSearchParams({ client_id: config.facebook.appId, client_secret: config.facebook.appSecret, redirect_uri: redirectUri, code }))
  );
  const long = check(
    await request(
      graph('/oauth/access_token?') +
        new URLSearchParams({ grant_type: 'fb_exchange_token', client_id: config.facebook.appId, client_secret: config.facebook.appSecret, fb_exchange_token: short.access_token })
    )
  );
  const pages = check(await request(graph('/me/accounts?') + new URLSearchParams({ fields: 'id,name,link,access_token', access_token: long.access_token })));
  return (pages.data || []).map((p) => ({ id: p.id, name: p.name, url: p.link || `https://www.facebook.com/${p.id}`, accessToken: p.access_token }));
}

async function fetchStats(account, token) {
  if (!token?.access_token) throw new ConnectorError('Chưa kết nối Facebook.', { needsReconnect: true });
  const t = token.access_token;
  const page = check(await request(graph(`/${account.external_id}?`) + new URLSearchParams({ fields: 'name,link,followers_count,fan_count', access_token: t })));

  const since = Math.floor(Date.now() / 1000) - 28 * 86400;
  const posts = check(
    await request(
      graph(`/${account.external_id}/posts?`) +
        new URLSearchParams({
          fields: 'id,message,created_time,permalink_url,shares,reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)',
          since: String(since),
          limit: '50',
          access_token: t,
        })
    )
  );
  const items = (posts.data || []).map((p) => ({
    url: p.permalink_url,
    title: String(p.message || '').slice(0, 120),
    createdAt: p.created_time,
    likes: p.reactions?.summary?.total_count || 0,
    comments: p.comments?.summary?.total_count || 0,
    shares: p.shares?.count || 0,
    views: null,
  }));

  // Tiếp cận 28 ngày: Meta đổi tên chỉ số theo thời gian, nên lỗi ở đây không làm hỏng cả lần cập nhật
  let reach = null;
  const ins = await request(
    graph(`/${account.external_id}/insights?`) + new URLSearchParams({ metric: 'page_impressions_unique', period: 'days_28', access_token: t })
  );
  if (ins.ok && ins.data?.data?.[0]?.values?.length) {
    const vals = ins.data.data[0].values;
    reach = Number(vals[vals.length - 1].value) || null;
  }

  return {
    followers: page.followers_count ?? page.fan_count ?? null,
    reach,
    engagement: items.reduce((s, p) => s + p.likes + p.comments + p.shares, 0),
    profile: { name: page.name, url: page.link },
    posts: items,
  };
}

module.exports = { key: 'facebook', oauth: true, isConfigured, authUrl, exchange, fetchStats };
