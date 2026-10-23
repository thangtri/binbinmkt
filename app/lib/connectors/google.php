<?php
// Google Maps: dán link → tìm địa điểm qua Google Places API (New) → lấy điểm, số đánh giá và đánh giá mới.
// Lượt xem hồ sơ, lượt gọi chỉ có trong Business Profile Performance API (cần duyệt riêng), nên không lấy ở đây.

final class GoogleConnector
{
    const KEY = 'google';
    const OAUTH = false;
    const PLACES = 'https://places.googleapis.com/v1';
    const ALLOWED_HOSTS = '/^(?:www\.)?(?:google\.[a-z.]+|maps\.google\.[a-z.]+|maps\.app\.goo\.gl|goo\.gl|g\.co)$/i';

    public static function isConfigured(): bool
    {
        return cfg('google.placesApiKey') !== '';
    }

    private static function checkHost(string $u): array
    {
        $url = parse_url($u);
        if (!$url || empty($url['host'])) throw new ConnectorError('Link không hợp lệ. Hãy dán link chia sẻ từ Google Maps.');
        if (($url['scheme'] ?? '') !== 'https' || !preg_match(self::ALLOWED_HOSTS, $url['host'])) {
            throw new ConnectorError('Đây không phải link Google Maps. Mở Google Maps, bấm Chia sẻ rồi sao chép link.');
        }
        return $url + ['full' => $u];
    }

    // Link rút gọn (maps.app.goo.gl/…) → link đầy đủ
    private static function expand(string $link): array
    {
        $url = self::checkHost($link);
        for ($i = 0; $i < 5; $i++) {
            if (!preg_match('/goo\.gl$|g\.co$/i', $url['host'])) return $url;
            $res = Http::request($url['full'], ['follow' => false, 'timeout' => 15, 'headers' => ['Accept' => '*/*']]);
            $next = $res['headers']['location'] ?? '';
            if (!$next) throw new ConnectorError('Link rút gọn không dẫn tới địa điểm nào.');
            if (!preg_match('~^https?://~', $next)) $next = 'https://' . $url['host'] . '/' . ltrim($next, '/');
            $url = self::checkHost($next);
        }
        return $url;
    }

    // Đọc tên và toạ độ địa điểm từ link Google Maps đầy đủ
    public static function parse(array $url): array
    {
        parse_str($url['query'] ?? '', $qs);
        $placeId = $qs['query_place_id'] ?? $qs['place_id'] ?? null;
        $path = rawurldecode($url['path'] ?? '');
        $name = preg_match('~/maps/place/([^/]+)~', $path, $m) ? str_replace('+', ' ', $m[1]) : null;
        $coords = null;
        if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $url['full'], $m)) $coords = $m;
        elseif (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $path, $m)) $coords = $m;
        $q = $qs['q'] ?? $qs['query'] ?? null;
        return [
            'placeId' => $placeId,
            'text' => $name ?: $q,
            'lat' => $coords ? (float) $coords[1] : null,
            'lng' => $coords ? (float) $coords[2] : null,
        ];
    }

    private static function headers(string $fields): array
    {
        return ['X-Goog-Api-Key' => cfg('google.placesApiKey'), 'X-Goog-FieldMask' => $fields];
    }

    private static function apiError(array $res): ConnectorError
    {
        $msg = $res['data']['error']['message'] ?? "Google trả về lỗi {$res['status']}";
        if ($res['status'] === 403 || $res['status'] === 400) {
            return new ConnectorError("Google Places API từ chối: $msg. Kiểm tra GOOGLE_PLACES_API_KEY và đã bật \"Places API (New)\".");
        }
        return new ConnectorError($msg);
    }

    // Từ link → place id (lưu lại để những lần sau gọi thẳng)
    public static function resolveLink(string $link): string
    {
        if (!self::isConfigured()) throw new ConnectorError('Chưa cấu hình GOOGLE_PLACES_API_KEY.');
        $p = self::parse(self::expand($link));
        if ($p['placeId']) return $p['placeId'];
        if (!$p['text']) throw new ConnectorError('Không đọc được tên địa điểm trong link. Hãy mở trang địa điểm trên Google Maps rồi bấm Chia sẻ.');
        $body = ['textQuery' => $p['text'], 'languageCode' => 'vi', 'pageSize' => 1];
        if ($p['lat'] !== null) $body['locationBias'] = ['circle' => ['center' => ['latitude' => $p['lat'], 'longitude' => $p['lng']], 'radius' => 500]];
        $res = Http::request(self::PLACES . '/places:searchText', ['method' => 'POST', 'headers' => self::headers('places.id,places.displayName,places.formattedAddress'), 'json' => $body]);
        if (!$res['ok']) throw self::apiError($res);
        $place = $res['data']['places'][0] ?? null;
        if (!$place) throw new ConnectorError("Google không tìm thấy địa điểm \"{$p['text']}\".");
        return $place['id'];
    }

    public static function fetchStats(array $account, ?array $token = null): array
    {
        if (!self::isConfigured()) throw new ConnectorError('Chưa cấu hình GOOGLE_PLACES_API_KEY.');
        if (empty($account['external_id'])) throw new ConnectorError('Chưa có link Google Maps của khách sạn.');
        $res = Http::request(self::PLACES . '/places/' . rawurlencode($account['external_id']) . '?languageCode=vi', [
            'headers' => self::headers('id,displayName,formattedAddress,googleMapsUri,rating,userRatingCount,reviews'),
        ]);
        if (!$res['ok']) throw self::apiError($res);
        $d = $res['data'] ?? [];
        return [
            'rating' => $d['rating'] ?? null,
            'reviews' => $d['userRatingCount'] ?? null,
            'profile' => ['name' => $d['displayName']['text'] ?? null, 'address' => $d['formattedAddress'] ?? null, 'url' => $d['googleMapsUri'] ?? null],
            'recentReviews' => array_map(fn($r) => [
                'author' => $r['authorAttribution']['displayName'] ?? 'Khách',
                'rating' => $r['rating'] ?? null,
                'text' => $r['text']['text'] ?? $r['originalText']['text'] ?? '',
                'when' => $r['relativePublishTimeDescription'] ?? '',
                'publishedAt' => $r['publishTime'] ?? null,
            ], $d['reviews'] ?? []),
        ];
    }
}
