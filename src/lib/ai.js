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

// Gọi Claude với structured output (JSON theo schema) và chuyển lỗi sang thông báo tiếng Việt
async function callJson(params) {
  if (!isConfigured()) throw new Error('Chưa cấu hình ANTHROPIC_API_KEY trên máy chủ.');
  const req = { ...params, model: config.ai.model };
  if (FALLBACK_MODELS.has(config.ai.model)) {
    req.betas = ['server-side-fallback-2026-07-01'];
    req.fallbacks = 'default';
  }
  let response;
  try {
    response = await getClient().beta.messages.create(req);
  } catch (err) {
    if (err instanceof Anthropic.AuthenticationError) throw new Error('ANTHROPIC_API_KEY không hợp lệ.');
    if (err instanceof Anthropic.RateLimitError) throw new Error('Claude đang quá tải hoặc hết hạn mức, thử lại sau ít phút.');
    if (err instanceof Anthropic.APIError) throw new Error(`Claude trả về lỗi ${err.status}: ${err.message}`);
    throw err;
  }
  if (response.stop_reason === 'refusal') throw new Error('Claude từ chối yêu cầu này.');
  if (response.stop_reason === 'max_tokens') throw new Error('Câu trả lời bị cắt giữa chừng, hãy thử lại.');
  const text = response.content.filter((b) => b.type === 'text').map((b) => b.text).join('');
  return { out: JSON.parse(text), model: response.model };
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
- Với đánh giá Google, nêu khách khen gì và phàn nàn gì nếu có nội dung đánh giá.
- Google Maps có nhiều chi nhánh (trường chi_nhanh): so sánh điểm, số đánh giá và nội dung đánh giá giữa các chi nhánh, chỉ rõ chi nhánh nào cần cải thiện và vì sao; nêu tên chi nhánh khi đề xuất.`;

async function gather(channel) {
  const account = await db.get('SELECT * FROM channel_accounts WHERE channel = ?', [channel]);
  const stats = await db.all(
    'SELECT recorded_on, followers, reach, engagement, rating, reviews FROM channel_stats WHERE channel = ? ORDER BY recorded_on DESC, id DESC LIMIT 30',
    [channel]
  );
  const since = dates.addDays(dates.today(), -45) + ' 00:00:00';
  const posts = await db.all(
    `SELECT p.title, p.format, p.published_at, pc.views, pc.likes, pc.comments, pc.shares
     FROM posts p JOIN post_channels pc ON pc.post_id = p.id AND pc.channel = ?
     WHERE p.status = 'published' AND p.published_at >= ? ORDER BY p.published_at DESC LIMIT 40`,
    [channel, since]
  );
  const upcoming = await db.all(
    `SELECT title, format, status, scheduled_at FROM posts WHERE status IN ('draft', 'pending', 'scheduled') AND scheduled_at >= ? ORDER BY scheduled_at LIMIT 15`,
    [dates.today() + ' 00:00:00']
  );
  const { occurrences } = require('./events');
  const events = (await occurrences(dates.today(), dates.addDays(dates.today(), 45))).map((e) => ({ title: e.title, from: e.occ_start, to: e.occ_end }));
  let extra = {};
  try {
    extra = JSON.parse(account?.extra || '{}');
  } catch {
    extra = {};
  }
  let chiNhanh = [];
  if (channel === 'google') {
    const branches = require('./branches');
    for (const b of await branches.list()) {
      const rows = await db.all(
        `SELECT recorded_on, rating, reviews FROM channel_stats WHERE channel = 'google' AND branch_id = ? ORDER BY recorded_on DESC, id DESC LIMIT 30`,
        [b.id]
      );
      chiNhanh.push({
        ten: b.name,
        dia_chi: b.address,
        dang_theo_doi: Boolean(b.place_id),
        so_lieu_theo_ngay: rows.reverse(),
        danh_gia_gan_day: branches.parseExtra(b).recentReviews || [],
      });
    }
  }
  return {
    kenh: CHANNELS[channel].name,
    chi_nhanh: chiNhanh,
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
  const branchData = data.chi_nhanh.some((b) => b.so_lieu_theo_ngay.length || b.danh_gia_gan_day.length);
  if (!data.so_lieu_theo_ngay.length && !data.bai_da_dang_45_ngay.length && !data.danh_gia_google_gan_day.length && !branchData) {
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
  const { out, model } = await callJson(params);
  const r = await db.run(
    `INSERT INTO channel_insights (channel, summary, highlights, concerns, recommendations, model, created_by, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      channel,
      out.summary,
      JSON.stringify(out.highlights || []),
      JSON.stringify(out.concerns || []),
      JSON.stringify(out.recommendations || []),
      model,
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

/* ---------- Viết bài và gợi ý ý tưởng ---------- */

function hotelBrief(s, branchList = []) {
  const lines = [
    `Tên: ${s.hotel_name}`,
    s.address && `Địa chỉ: ${s.address}`,
    s.hotline && `Hotline đặt phòng: ${s.hotline}`,
    s.zalo && `Zalo đặt phòng: ${s.zalo}`,
    s.booking_url && `Link đặt phòng: ${s.booking_url}`,
    s.highlights && `Điểm mạnh:\n${s.highlights}`,
    s.audience && `Khách hàng chính: ${s.audience}`,
    s.voice && `Giọng văn: ${s.voice}`,
    s.avoid && `Không được dùng hoặc hứa hẹn: ${s.avoid}`,
    s.hashtags && `Hashtag luôn có: ${s.hashtags}`,
    branchList.length > 1 &&
      `Chuỗi có ${branchList.length} chi nhánh (bài đăng chung cho cả chuỗi; chỉ nhắc chi nhánh cụ thể khi ý chính yêu cầu):\n` +
        branchList.map((b) => `- ${b.name}${b.address ? `, ${b.address}` : ''}${b.hotline ? `, hotline ${b.hotline}` : ''}`).join('\n'),
  ];
  return lines.filter(Boolean).join('\n');
}

const WRITER_SYSTEM = `Bạn là người viết nội dung mạng xã hội cho một khách sạn ở Việt Nam.
Mỗi bài được đăng nguyên văn lên cả Facebook, TikTok, Google Maps và Zalo OA, nên:
- Viết tiếng Việt tự nhiên, câu ngắn, dễ đọc trên điện thoại; có thể dùng vài emoji phù hợp nhưng không lạm dụng.
- Nội dung (caption) dưới 1.500 ký tự vì Google Maps chỉ nhận tới đó; dòng đầu phải đủ hấp dẫn để người lướt dừng lại.
- Kết thúc bằng lời kêu gọi đặt phòng rõ ràng, dùng đúng hotline/Zalo/link trong thông tin khách sạn nếu có. Không bịa số điện thoại, giá, ưu đãi hay tiện ích không có trong yêu cầu hoặc thông tin khách sạn.
- Hashtag để riêng, 3–6 cái, không lặp trong caption.
- Tiêu đề nội bộ ngắn gọn (dưới 60 ký tự) để team nhận ra bài trên lịch; khách không thấy tiêu đề này.`;

const OPTION = {
  type: 'object',
  properties: {
    title: { type: 'string', description: 'Tiêu đề nội bộ, dưới 60 ký tự' },
    caption: { type: 'string', description: 'Nội dung đăng, không gồm hashtag' },
    hashtags: { type: 'string', description: 'Hashtag cách nhau bằng dấu cách' },
  },
  required: ['title', 'caption', 'hashtags'],
  additionalProperties: false,
};

async function writePost({ brief, tone, format, eventTitle, current }) {
  const s = await require('./settings').all();
  const branchList = await require('./branches').list();
  const req = [
    `Ý chính của bài: ${brief}`,
    format && `Định dạng: ${format}`,
    tone && `Sắc thái mong muốn: ${tone}`,
    eventTitle && `Bài gắn với sự kiện: ${eventTitle}`,
    current && `Bản nháp hiện tại (có thể dựa vào, viết lại cho hay hơn):\n${current}`,
  ]
    .filter(Boolean)
    .join('\n');
  const { out } = await callJson({
    max_tokens: 16000,
    output_config: {
      effort: 'medium',
      format: {
        type: 'json_schema',
        schema: {
          type: 'object',
          properties: { options: { type: 'array', items: OPTION, description: 'Đúng 3 phương án khác nhau về cách mở bài và góc nhìn' } },
          required: ['options'],
          additionalProperties: false,
        },
      },
    },
    system: WRITER_SYSTEM,
    messages: [{ role: 'user', content: `Thông tin khách sạn:\n${hotelBrief(s, branchList)}\n\nViết 3 phương án cho bài sau.\n${req}` }],
  });
  return (out.options || []).slice(0, 3);
}

async function ideasForEvent(ev) {
  const s = await require('./settings').all();
  const branchList = await require('./branches').list();
  const { out } = await callJson({
    max_tokens: 16000,
    output_config: {
      effort: 'medium',
      format: {
        type: 'json_schema',
        schema: {
          type: 'object',
          properties: {
            ideas: {
              type: 'array',
              description: 'Đúng 3 ý tưởng, rải trước và trong sự kiện',
              items: {
                type: 'object',
                properties: {
                  ...OPTION.properties,
                  format: { type: 'string', enum: require('./constants').POST_FORMATS },
                  days_before: { type: 'integer', description: 'Đăng trước ngày bắt đầu sự kiện bao nhiêu ngày (0 = đúng ngày)' },
                  why: { type: 'string', description: 'Một câu giải thích vì sao bài này hợp với sự kiện' },
                },
                required: ['title', 'caption', 'hashtags', 'format', 'days_before', 'why'],
                additionalProperties: false,
              },
            },
          },
          required: ['ideas'],
          additionalProperties: false,
        },
      },
    },
    system: WRITER_SYSTEM,
    messages: [
      {
        role: 'user',
        content: `Thông tin khách sạn:\n${hotelBrief(s, branchList)}\n\nSự kiện: ${ev.title} (${ev.occ_start}${ev.occ_end !== ev.occ_start ? ` đến ${ev.occ_end}` : ''}).${
          ev.description ? `\nGhi chú của team: ${ev.description}` : ''
        }\n\nĐề xuất 3 bài đăng cho dịp này, mỗi bài một góc khác nhau (ví dụ: báo trước ưu đãi, gợi ý trải nghiệm, nhắc đặt phòng sát ngày).`,
      },
    ],
  });
  return (out.ideas || []).slice(0, 3);
}

module.exports = { isConfigured, analyze, latest, writePost, ideasForEvent };
