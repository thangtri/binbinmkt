// Phân tích kênh bằng Claude: đọc số liệu, bài đã đăng và đánh giá, viết nhận xét và đề xuất bằng tiếng Việt.
const { Anthropic } = require('@anthropic-ai/sdk');
const db = require('../db');
const config = require('../config');
const dates = require('./dates');
const { CHANNELS } = require('./constants');

// Model hỗ trợ chuyển sang model dự phòng khi bị từ chối do bộ lọc an toàn
const FALLBACK_MODELS = new Set(['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5']);

let client = null;
function getClient() {
  if (!client) client = new Anthropic({ apiKey: config.ai.apiKey });
  return client;
}

function isConfigured() {
  return Boolean(config.ai.apiKey);
}

const SCHEMA = {
  type: 'object',
  properties: {
    summary: { type: 'string', description: 'Nhận xét tổng quan 2-4 câu về tình hình kênh' },
    highlights: { type: 'array', items: { type: 'string' }, description: 'Điểm tốt, mỗi ý một câu' },
    concerns: { type: 'array', items: { type: 'string' }, description: 'Vấn đề cần chú ý, mỗi ý một câu' },
    recommendations: { type: 'array', items: { type: 'string' }, description: 'Việc nên làm trong 1-2 tuần tới, cụ thể, mỗi ý một câu' },
  },
  required: ['summary', 'highlights', 'concerns', 'recommendations'],
  additionalProperties: false,
};

const SYSTEM = `Bạn là chuyên viên phân tích marketing cho Bin Bin Hotel, một khách sạn tại TP. Hồ Chí Minh.
Đội marketing đọc phân tích của bạn để quyết định nội dung đăng trong 1-2 tuần tới.

Cách viết:
- Tiếng Việt, ngắn gọn, giọng chuyên môn nhưng dễ hiểu với nhân viên không chuyên số liệu.
- Chỉ dựa trên dữ liệu được cung cấp. Không bịa số. Khi nhắc số, dùng đúng số trong dữ liệu.
- So sánh xu hướng theo thời gian (tăng, giảm, đứng yên) thay vì chỉ đọc lại số mới nhất.
- Đề xuất phải làm được ngay: loại nội dung, giờ đăng, chủ đề gắn với sự kiện sắp tới, cách trả lời đánh giá.
- Nếu dữ liệu còn quá ít để kết luận, nói rõ điều đó trong phần tổng quan và đề xuất cách thu thêm dữ liệu.
- Với đánh giá Google, nêu khách khen gì và phàn nàn gì nếu có nội dung đánh giá.`;

async function gather(channel) {
  const account = await db.get('SELECT * FROM channel_accounts WHERE channel = ?', [channel]);
  const stats = await db.all(
    'SELECT recorded_on, followers, reach, engagement, rating, reviews FROM channel_stats WHERE channel = ? ORDER BY recorded_on DESC, id DESC LIMIT 30',
    [channel]
  );
  const since = dates.addDays(dates.today(), -45) + ' 00:00:00';
  const posts = await db.all(
    `SELECT title, format, published_at, views, likes, comments, shares FROM posts
     WHERE channel = ? AND status = 'published' AND published_at >= ? ORDER BY published_at DESC LIMIT 40`,
    [channel, since]
  );
  const upcoming = await db.all(
    `SELECT title, format, status, scheduled_at FROM posts WHERE channel = ? AND status IN ('draft', 'pending', 'scheduled') AND scheduled_at >= ? ORDER BY scheduled_at LIMIT 15`,
    [channel, dates.today() + ' 00:00:00']
  );
  const { occurrences } = require('./events');
  const events = (await occurrences(dates.today(), dates.addDays(dates.today(), 45))).map((e) => ({ title: e.title, from: e.occ_start, to: e.occ_end }));
  let extra = {};
  try {
    extra = JSON.parse(account?.extra || '{}');
  } catch {
    extra = {};
  }
  return {
    kenh: CHANNELS[channel].name,
    hom_nay: dates.today(),
    so_lieu_theo_ngay: stats.reverse(),
    bai_da_dang_45_ngay: posts,
    bai_tren_kenh_lay_tu_api: (extra.posts || []).slice(0, 15),
    danh_gia_google_gan_day: extra.recentReviews || [],
    bai_sap_dang: upcoming,
    su_kien_45_ngay_toi: events,
  };
}

async function analyze(channel, user = null) {
  if (!isConfigured()) throw new Error('Chưa cấu hình ANTHROPIC_API_KEY trên máy chủ.');
  const data = await gather(channel);
  if (!data.so_lieu_theo_ngay.length && !data.bai_da_dang_45_ngay.length && !data.danh_gia_google_gan_day.length) {
    throw new Error('Chưa có số liệu nào để phân tích. Kết nối kênh và cập nhật số liệu trước.');
  }

  const params = {
    model: config.ai.model,
    max_tokens: 16000,
    output_config: { effort: 'medium', format: { type: 'json_schema', schema: SCHEMA } },
    system: SYSTEM,
    messages: [
      {
        role: 'user',
        content: `Phân tích kênh ${data.kenh} của Bin Bin Hotel dựa trên dữ liệu sau (JSON):\n\n${JSON.stringify(data, null, 1)}`,
      },
    ],
  };
  if (FALLBACK_MODELS.has(config.ai.model)) {
    params.betas = ['server-side-fallback-2026-07-01'];
    params.fallbacks = 'default';
  }

  let response;
  try {
    response = await getClient().beta.messages.create(params);
  } catch (err) {
    if (err instanceof Anthropic.AuthenticationError) throw new Error('ANTHROPIC_API_KEY không hợp lệ.');
    if (err instanceof Anthropic.RateLimitError) throw new Error('Claude đang quá tải hoặc hết hạn mức, thử lại sau ít phút.');
    if (err instanceof Anthropic.APIError) throw new Error(`Claude trả về lỗi ${err.status}: ${err.message}`);
    throw err;
  }
  if (response.stop_reason === 'refusal') throw new Error('Claude từ chối phân tích dữ liệu này.');
  if (response.stop_reason === 'max_tokens') throw new Error('Phân tích bị cắt giữa chừng, hãy thử lại.');

  const text = response.content.filter((b) => b.type === 'text').map((b) => b.text).join('');
  const out = JSON.parse(text);
  const r = await db.run(
    `INSERT INTO channel_insights (channel, summary, highlights, concerns, recommendations, model, created_by, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      channel,
      out.summary,
      JSON.stringify(out.highlights || []),
      JSON.stringify(out.concerns || []),
      JSON.stringify(out.recommendations || []),
      response.model,
      user?.id || null,
      dates.now(),
    ]
  );
  return r.insertId;
}

async function latest(channel) {
  const row = await db.get('SELECT * FROM channel_insights WHERE channel = ? ORDER BY id DESC LIMIT 1', [channel]);
  if (!row) return null;
  const list = (s) => {
    try {
      return JSON.parse(s || '[]');
    } catch {
      return [];
    }
  };
  return { ...row, highlights: list(row.highlights), concerns: list(row.concerns), recommendations: list(row.recommendations) };
}

module.exports = { isConfigured, analyze, latest };
