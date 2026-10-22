// Facebook Page qua Graph API. Đăng nhập Facebook một lần → lấy Page token (không hết hạn khi
// xuất phát từ token người dùng dài hạn) → đọc người theo dõi và tương tác của các bài gần đây.
const fs = require('fs');
const path = require('path');
const config = require('../../config');
const { request, ConnectorError } = require('./http');

// pages_manage_posts: để tự đăng bài lên Page
const SCOPES = ['pages_show_list', 'pages_read_engagement', 'read_insights', 'pages_manage_posts'];

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

/* ---------- Tự đăng bài ---------- */

async function uploadForm(url, fields, file) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(fields)) fd.append(k, v);
  if (file) fd.append('source', await fs.openAsBlob(file.path, { type: file.mime }), path.basename(file.path));
  return request(url, { method: 'POST', body: fd, timeout: 10 * 60 * 1000 });
}

// files: [{ path (đường dẫn tuyệt đối), mime, isVideo }]
async function publish(account, token, { message, files }) {
  if (!token?.access_token) throw new ConnectorError('Chưa kết nối Facebook.', { needsReconnect: true });
  const t = token.access_token;
  const page = account.external_id;
  const video = files.find((x) => x.isVideo);
  if (video) {
    // Facebook không gộp video với ảnh trong một bài: đăng video đầu tiên kèm nội dung
    const v = check(await uploadForm(`https://graph-video.facebook.com/${config.facebook.graphVersion}/${page}/videos`, { description: message, access_token: t }, video));
    return {
      url: `https://www.facebook.com/${page}/videos/${v.id}`,
      externalId: v.id,
      note: files.length > 1 ? 'Facebook chỉ đăng được video, các ảnh đi kèm bị bỏ qua.' : null,
    };
  }
  const fields = { message, access_token: t };
  const photos = files.slice(0, 30);
  for (let i = 0; i < photos.length; i++) {
    const p = check(await uploadForm(graph(`/${page}/photos`), { published: 'false', access_token: t }, photos[i]));
    fields[`attached_media[${i}]`] = JSON.stringify({ media_fbid: p.id });
  }
  const post = check(await request(graph(`/${page}/feed`), { method: 'POST', form: fields }));
  const link = await request(graph(`/${post.id}?`) + new URLSearchParams({ fields: 'permalink_url', access_token: t }));
  return { url: link.data?.permalink_url || `https://www.facebook.com/${post.id}`, externalId: post.id };
}

module.exports = { key: 'facebook', oauth: true, isConfigured, authUrl, exchange, fetchStats, publish };
