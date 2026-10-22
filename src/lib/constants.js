// Danh mục dùng chung: kênh, trạng thái bài, loại sự kiện, vai trò.

const CHANNELS = {
  facebook: {
    key: 'facebook',
    name: 'Facebook',
    code: 'FB',
    formats: ['Bài viết', 'Album ảnh', 'Reels', 'Story', 'Livestream'],
    tips: 'Ảnh phòng thật, ánh sáng tự nhiên. Kêu gọi nhắn tin đặt phòng ở cuối bài.',
  },
  tiktok: {
    key: 'tiktok',
    name: 'TikTok',
    code: 'TT',
    formats: ['Video ngắn', 'Ảnh lướt', 'Livestream'],
    tips: '3 giây đầu quyết định lượt xem. Quay dọc, có phụ đề tiếng Việt.',
  },
  google: {
    key: 'google',
    name: 'Google Maps',
    code: 'GG',
    formats: ['Cập nhật', 'Ưu đãi', 'Sự kiện', 'Trả lời đánh giá'],
    limit: 1500,
    tips: 'Bài cập nhật tối đa 1.500 ký tự. Trả lời mọi đánh giá trong 24 giờ.',
  },
  zalo: {
    key: 'zalo',
    name: 'Zalo OA',
    code: 'ZL',
    formats: ['Bài viết', 'Tin truyền thông', 'Tin tư vấn'],
    tips: 'Gửi tới khách cũ đã quan tâm OA. Ngắn gọn, có nút gọi hoặc đặt phòng.',
  },
};

const STATUSES = {
  draft: { key: 'draft', label: 'Nháp' },
  pending: { key: 'pending', label: 'Chờ duyệt' },
  scheduled: { key: 'scheduled', label: 'Đã duyệt' },
  published: { key: 'published', label: 'Đã đăng' },
};

const EVENT_KINDS = {
  holiday: { key: 'holiday', label: 'Lễ, Tết' },
  season: { key: 'season', label: 'Mùa du lịch' },
  promo: { key: 'promo', label: 'Ưu đãi khách sạn' },
  local: { key: 'local', label: 'Sự kiện địa phương' },
  internal: { key: 'internal', label: 'Nội bộ' },
};

const ROLES = {
  admin: { key: 'admin', label: 'Quản trị', desc: 'Toàn quyền: duyệt bài, gửi email, quản lý tài khoản.' },
  editor: { key: 'editor', label: 'Biên tập', desc: 'Soạn bài, lên lịch, gửi duyệt, ghi số liệu kênh.' },
  viewer: { key: 'viewer', label: 'Chỉ xem', desc: 'Xem lịch, bài đăng và báo cáo.' },
};

module.exports = { CHANNELS, STATUSES, EVENT_KINDS, ROLES };
