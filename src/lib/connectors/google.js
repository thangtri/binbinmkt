// Google Maps: dán link → tìm địa điểm qua Google Places API (New) → lấy điểm, số đánh giá và đánh giá mới.
// Lượt xem hồ sơ, lượt gọi chỉ có trong Business Profile Performance API (cần duyệt riêng), nên không lấy ở đây.
const config = require('../../config');
const { request, ConnectorError } = require('./http');

const PLACES = 'https://places.googleapis.com/v1';
const ALLOWED_HOSTS = /^(?:www\.)?(?:google\.[a-z.]+|maps\.google\.[a-z.]+|maps\.app\.goo\.gl|goo\.gl|g\.co)$/i;

function isConfigured() {
  return Boolean(config.google.placesApiKey);
}

function checkHost(u) {
  let url;
  try {
    url = new URL(u);
  } catch {
    throw new ConnectorError('Link không hợp lệ. Hãy dán link chia sẻ từ Google Maps.');
  }
  if (url.protocol !== 'https:' || !ALLOWED_HOSTS.test(url.hostname)) {
    throw new ConnectorError('Đây không phải link Google Maps. Mở Google Maps, bấm Chia sẻ rồi sao chép link.');
  }
  return url;
}

// Link rút gọn (maps.app.goo.gl/…) → link đầy đủ
async function expand(link) {
  let url = checkHost(link);
  for (let i = 0; i < 5; i++) {
    if (!/goo\.gl$|g\.co$/i.test(url.hostname)) return url;
    let res;
    try {
      res = await fetch(url, { redirect: 'manual', signal: AbortSignal.timeout(15000) });
    } catch {
      throw new ConnectorError('Không mở được link rút gọn của Google Maps.');
    }
    const next = res.headers.get('location');
    if (!next) throw new ConnectorError('Link rút gọn không dẫn tới địa điểm nào.');
    url = checkHost(new URL(next, url).toString());
  }
  return url;
}

// Đọc tên và toạ độ địa điểm từ link Google Maps đầy đủ
function parse(url) {
  const placeId = url.searchParams.get('query_place_id') || url.searchParams.get('place_id');
  const path = decodeURIComponent(url.pathname);
  const name = (path.match(/\/maps\/place\/([^/]+)/) || [])[1]?.replace(/\+/g, ' ');
  const at = path.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
  const pin = url.toString().match(/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/);
  const coords = pin || at;
  const q = url.searchParams.get('q') || url.searchParams.get('query');
  return {
    placeId,
    text: name || q || null,
    lat: coords ? Number(coords[1]) : null,
    lng: coords ? Number(coords[2]) : null,
  };
}

function headers(fields) {
  return { 'X-Goog-Api-Key': config.google.placesApiKey, 'X-Goog-FieldMask': fields };
}

function apiError(res) {
  const msg = res.data?.error?.message || `Google trả về lỗi ${res.status}`;
  if (res.status === 403 || res.status === 400) {
    return new ConnectorError(`Google Places API từ chối: ${msg}. Kiểm tra GOOGLE_PLACES_API_KEY và đã bật "Places API (New)".`);
  }
  return new ConnectorError(msg);
}

// Từ link → place id (lưu lại để những lần sau gọi thẳng)
async function resolveLink(link) {
  if (!isConfigured()) throw new ConnectorError('Chưa cấu hình GOOGLE_PLACES_API_KEY.');
  const url = await expand(link);
  const p = parse(url);
  if (p.placeId) return p.placeId;
  if (!p.text) throw new ConnectorError('Không đọc được tên địa điểm trong link. Hãy mở trang địa điểm trên Google Maps rồi bấm Chia sẻ.');
  const body = { textQuery: p.text, languageCode: 'vi', pageSize: 1 };
  if (p.lat != null) body.locationBias = { circle: { center: { latitude: p.lat, longitude: p.lng }, radius: 500 } };
  const res = await request(`${PLACES}/places:searchText`, {
    method: 'POST',
    headers: headers('places.id,places.displayName,places.formattedAddress'),
    json: body,
  });
  if (!res.ok) throw apiError(res);
  const place = res.data?.places?.[0];
  if (!place) throw new ConnectorError(`Google không tìm thấy địa điểm "${p.text}".`);
  return place.id;
}

async function fetchStats(account) {
  if (!isConfigured()) throw new ConnectorError('Chưa cấu hình GOOGLE_PLACES_API_KEY.');
  if (!account.external_id) throw new ConnectorError('Chưa có link Google Maps của khách sạn.');
  const res = await request(`${PLACES}/places/${encodeURIComponent(account.external_id)}?languageCode=vi`, {
    headers: headers('id,displayName,formattedAddress,googleMapsUri,rating,userRatingCount,reviews'),
  });
  if (!res.ok) throw apiError(res);
  const d = res.data || {};
  return {
    rating: d.rating ?? null,
    reviews: d.userRatingCount ?? null,
    profile: { name: d.displayName?.text, address: d.formattedAddress, url: d.googleMapsUri },
    recentReviews: (d.reviews || []).map((r) => ({
      author: r.authorAttribution?.displayName || 'Khách',
      rating: r.rating,
      text: r.text?.text || r.originalText?.text || '',
      when: r.relativePublishTimeDescription || '',
      publishedAt: r.publishTime || null,
    })),
  };
}

module.exports = { key: 'google', isConfigured, resolveLink, fetchStats, _parse: parse };
