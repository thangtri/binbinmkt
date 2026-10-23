<?php
// Phân tích kênh, viết bài, lập kế hoạch tháng bằng Claude (thư viện chính thức anthropic-ai/sdk).

use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageStartEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;

final class AI
{
    // Model hỗ trợ chuyển sang model dự phòng khi bị từ chối do bộ lọc an toàn
    const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5'];

    private static ?Client $client = null;

    public static function isConfigured(): bool
    {
        return cfg('ai.apiKey') !== '';
    }

    private static function client(): Client
    {
        return self::$client ??= new Client(apiKey: cfg('ai.apiKey'), requestOptions: ['timeout' => 600]);
    }

    // Gọi Claude với structured output (JSON theo schema) và chuyển lỗi sang thông báo tiếng Việt.
    // Dùng streaming để yêu cầu dài (như kế hoạch cả tháng) không bị ngắt giữa chừng.
    /** @return array{out:array, model:string} */
    private static function callJson(string $system, string $content, array $schema, int $maxTokens = 16000): array
    {
        if (!self::isConfigured()) throw new UserError('Chưa cấu hình ANTHROPIC_API_KEY trên máy chủ.');
        @set_time_limit(900);
        $model = cfg('ai.model');
        $fallback = in_array($model, self::FALLBACK_MODELS, true);
        $text = '';
        $stop = null;
        $served = $model;
        try {
            $stream = self::client()->beta->messages->createStream(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $content]],
                model: $model,
                fallbacks: $fallback ? 'default' : null,
                outputConfig: ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                system: $system,
                betas: $fallback ? ['server-side-fallback-2026-07-01'] : null,
            );
            foreach ($stream as $event) {
                if ($event instanceof BetaRawMessageStartEvent) $served = $event->message->model;
                elseif ($event instanceof BetaRawContentBlockDeltaEvent && $event->delta instanceof BetaTextDelta) $text .= $event->delta->text;
                elseif ($event instanceof BetaRawMessageDeltaEvent && $event->delta->stopReason) $stop = $event->delta->stopReason;
            }
        } catch (AuthenticationException $e) {
            throw new UserError('ANTHROPIC_API_KEY không hợp lệ.');
        } catch (RateLimitException $e) {
            throw new UserError('Claude đang quá tải hoặc hết hạn mức, thử lại sau ít phút.');
        } catch (APIStatusException $e) {
            throw new UserError("Claude trả về lỗi {$e->status}.");
        } catch (APIConnectionException $e) {
            throw new UserError('Không kết nối được tới Claude, thử lại sau.');
        }
        if ($stop === 'refusal') throw new UserError('Claude từ chối yêu cầu này.');
        if ($stop === 'max_tokens') throw new UserError('Câu trả lời bị cắt giữa chừng, hãy thử lại.');
        $out = json_decode($text, true);
        if (!is_array($out)) throw new UserError('Claude trả về dữ liệu không đọc được, hãy thử lại.');
        return ['out' => $out, 'model' => (string) $served];
    }

    private static function json($data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'summary' => ['type' => 'string', 'description' => 'Nhận xét tổng quan 2-4 câu về tình hình kênh'],
            'highlights' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Điểm tốt, mỗi ý một câu'],
            'concerns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Vấn đề cần chú ý, mỗi ý một câu'],
            'recommendations' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Việc nên làm trong 1-2 tuần tới, cụ thể, mỗi ý một câu'],
        ],
        'required' => ['summary', 'highlights', 'concerns', 'recommendations'],
        'additionalProperties' => false,
    ];

    const SYSTEM = <<<'TXT'
Bạn là chuyên viên phân tích marketing cho Bin Bin Hotel, một khách sạn tại TP. Hồ Chí Minh.
Đội marketing đọc phân tích của bạn để quyết định nội dung đăng trong 1-2 tuần tới.

Cách viết:
- Tiếng Việt, ngắn gọn, giọng chuyên môn nhưng dễ hiểu với nhân viên không chuyên số liệu.
- Chỉ dựa trên dữ liệu được cung cấp. Không bịa số. Khi nhắc số, dùng đúng số trong dữ liệu.
- So sánh xu hướng theo thời gian (tăng, giảm, đứng yên) thay vì chỉ đọc lại số mới nhất.
- Đề xuất phải làm được ngay: loại nội dung, giờ đăng, chủ đề gắn với sự kiện sắp tới, cách trả lời đánh giá.
- Nếu dữ liệu còn quá ít để kết luận, nói rõ điều đó trong phần tổng quan và đề xuất cách thu thêm dữ liệu.
- Với đánh giá Google, nêu khách khen gì và phàn nàn gì nếu có nội dung đánh giá.
- Google Maps có nhiều chi nhánh (trường chi_nhanh): so sánh điểm, số đánh giá và nội dung đánh giá giữa các chi nhánh, chỉ rõ chi nhánh nào cần cải thiện và vì sao; nêu tên chi nhánh khi đề xuất. Nếu có doi_thu_google, so sánh từng chi nhánh với đối thủ gần đó (điểm, số đánh giá, tốc độ có đánh giá mới).
TXT;

    private static function gather(string $channel): array
    {
        $account = DB::get('SELECT * FROM channel_accounts WHERE channel = ?', [$channel]);
        $stats = DB::all('SELECT recorded_on, followers, reach, engagement, rating, reviews FROM channel_stats WHERE channel = ? ORDER BY recorded_on DESC, id DESC LIMIT 30', [$channel]);
        $posts = DB::all(
            "SELECT p.title, p.format, p.published_at, pc.views, pc.likes, pc.comments, pc.shares
             FROM posts p JOIN post_channels pc ON pc.post_id = p.id AND pc.channel = ?
             WHERE p.status = 'published' AND p.published_at >= ? ORDER BY p.published_at DESC LIMIT 40",
            [$channel, D::addDays(D::today(), -45) . ' 00:00:00']
        );
        $upcoming = DB::all(
            "SELECT title, format, status, scheduled_at FROM posts WHERE status IN ('draft', 'pending', 'scheduled') AND scheduled_at >= ? ORDER BY scheduled_at LIMIT 15",
            [D::today() . ' 00:00:00']
        );
        $events = array_map(fn($e) => ['title' => $e['title'], 'from' => $e['occ_start'], 'to' => $e['occ_end']], Events::occurrences(D::today(), D::addDays(D::today(), 45)));
        $extra = json_decode_array($account['extra'] ?? null);
        $chiNhanh = [];
        $doiThu = [];
        if ($channel === 'google') {
            foreach (Branches::list() as $b) {
                $rows = DB::all("SELECT recorded_on, rating, reviews FROM channel_stats WHERE channel = 'google' AND branch_id = ? ORDER BY recorded_on DESC, id DESC LIMIT 30", [$b['id']]);
                $chiNhanh[] = [
                    'ten' => $b['name'],
                    'dia_chi' => $b['address'],
                    'dang_theo_doi' => (bool) $b['place_id'],
                    'so_lieu_theo_ngay' => array_reverse($rows),
                    'danh_gia_gan_day' => Branches::parseExtra($b)['recentReviews'] ?? [],
                ];
            }
            foreach (Competitors::comparison() as $g) {
                if (!$g['rivals']) continue;
                $doiThu[] = [
                    'chi_nhanh' => $g['branch']['name'],
                    'doi_thu' => array_map(fn($r) => ['ten' => $r['name'], 'diem' => $r['latest']['rating'] ?? null, 'so_danh_gia' => $r['latest']['reviews'] ?? null, 'danh_gia_moi_30_ngay' => $r['growth30']], $g['rivals']),
                ];
            }
        }
        return [
            'kenh' => C::CHANNELS[$channel]['name'],
            'chi_nhanh' => $chiNhanh,
            'doi_thu_google' => $doiThu,
            'hom_nay' => D::today(),
            'so_lieu_theo_ngay' => array_reverse($stats),
            'bai_da_dang_45_ngay' => $posts,
            'bai_tren_kenh_lay_tu_api' => array_slice($extra['posts'] ?? [], 0, 15),
            'danh_gia_google_gan_day' => $extra['recentReviews'] ?? [],
            'bai_sap_dang' => $upcoming,
            'su_kien_45_ngay_toi' => $events,
        ];
    }

    private static function saveInsight(string $channel, array $out, string $model, ?array $user): int
    {
        return DB::run(
            'INSERT INTO channel_insights (channel, summary, highlights, concerns, recommendations, model, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $channel,
                $out['summary'] ?? '',
                json_encode($out['highlights'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($out['concerns'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($out['recommendations'] ?? [], JSON_UNESCAPED_UNICODE),
                $model,
                $user['id'] ?? null,
                D::now(),
            ]
        )['insertId'];
    }

    public static function analyze(string $channel, ?array $user = null): int
    {
        if (!self::isConfigured()) throw new UserError('Chưa cấu hình ANTHROPIC_API_KEY trên máy chủ.');
        $data = self::gather($channel);
        $branchData = (bool) array_filter($data['chi_nhanh'], fn($b) => $b['so_lieu_theo_ngay'] || $b['danh_gia_gan_day']);
        if (!$data['so_lieu_theo_ngay'] && !$data['bai_da_dang_45_ngay'] && !$data['danh_gia_google_gan_day'] && !$branchData) {
            throw new UserError('Chưa có số liệu nào để phân tích. Kết nối kênh và cập nhật số liệu trước.');
        }
        $r = self::callJson(self::SYSTEM, "Phân tích kênh {$data['kenh']} của Bin Bin Hotel dựa trên dữ liệu sau (JSON):\n\n" . self::json($data), self::SCHEMA);
        return self::saveInsight($channel, $r['out'], $r['model'], $user);
    }

    public static function latest(string $channel): ?array
    {
        $row = DB::get('SELECT * FROM channel_insights WHERE channel = ? ORDER BY id DESC LIMIT 1', [$channel]);
        if (!$row) return null;
        foreach (['highlights', 'concerns', 'recommendations'] as $k) $row[$k] = json_decode_array($row[$k]);
        return $row;
    }

    /* ---------- Viết bài và gợi ý ý tưởng ---------- */

    // Thông tin ưu đãi cho AI; mã giảm giá được viết là {{ma}} để hệ thống thay theo từng kênh
    private static function offerBrief($offerId): ?string
    {
        if (!$offerId) return null;
        $o = Offers::get($offerId);
        if (!$o) return null;
        return implode("\n", array_filter([
            "Bài quảng bá ưu đãi: {$o['title']}",
            $o['summary'] ? "Tóm tắt: {$o['summary']}" : null,
            $o['details'] ? "Chi tiết (dùng đúng các con số này, không thêm bớt): {$o['details']}" : null,
            $o['terms'] ? "Điều kiện: {$o['terms']}" : null,
            'Thời gian áp dụng: ' . D::dmy($o['start_date']) . ' đến ' . D::dmy($o['end_date']),
            'Ghi mã giảm giá trong bài đúng nguyên văn là {{ma}} (hệ thống sẽ thay bằng mã của từng kênh). Ví dụ: "Nhập mã {{ma}} khi đặt phòng".',
        ]));
    }

    private static function hotelBrief(): string
    {
        $s = Settings::all();
        $branchList = Branches::list();
        $lines = [
            "Tên: {$s['hotel_name']}",
            $s['address'] ? "Địa chỉ: {$s['address']}" : null,
            $s['hotline'] ? "Hotline đặt phòng: {$s['hotline']}" : null,
            $s['zalo'] ? "Zalo đặt phòng: {$s['zalo']}" : null,
            $s['booking_url'] ? "Link đặt phòng: {$s['booking_url']}" : null,
            $s['highlights'] ? "Điểm mạnh:\n{$s['highlights']}" : null,
            $s['audience'] ? "Khách hàng chính: {$s['audience']}" : null,
            $s['voice'] ? "Giọng văn: {$s['voice']}" : null,
            $s['avoid'] ? "Không được dùng hoặc hứa hẹn: {$s['avoid']}" : null,
            $s['hashtags'] ? "Hashtag luôn có: {$s['hashtags']}" : null,
        ];
        if (count($branchList) > 1) {
            $lines[] = 'Chuỗi có ' . count($branchList) . " chi nhánh (bài đăng chung cho cả chuỗi; chỉ nhắc chi nhánh cụ thể khi ý chính yêu cầu):\n"
                . implode("\n", array_map(fn($b) => "- {$b['name']}" . ($b['address'] ? ", {$b['address']}" : '') . ($b['hotline'] ? ", hotline {$b['hotline']}" : ''), $branchList));
        }
        return implode("\n", array_filter($lines));
    }

    const WRITER_SYSTEM = <<<'TXT'
Bạn là người viết nội dung mạng xã hội cho một khách sạn ở Việt Nam.
Mỗi bài được đăng nguyên văn lên cả Facebook, TikTok, Google Maps và Zalo OA, nên:
- Viết tiếng Việt tự nhiên, câu ngắn, dễ đọc trên điện thoại; có thể dùng vài emoji phù hợp nhưng không lạm dụng.
- Nội dung (caption) dưới 1.500 ký tự vì Google Maps chỉ nhận tới đó; dòng đầu phải đủ hấp dẫn để người lướt dừng lại.
- Kết thúc bằng lời kêu gọi đặt phòng rõ ràng, dùng đúng hotline/Zalo/link trong thông tin khách sạn nếu có. Không bịa số điện thoại, giá, ưu đãi hay tiện ích không có trong yêu cầu hoặc thông tin khách sạn.
- Hashtag để riêng, 3–6 cái, không lặp trong caption.
- Tiêu đề nội bộ ngắn gọn (dưới 60 ký tự) để team nhận ra bài trên lịch; khách không thấy tiêu đề này.
TXT;

    const OPTION_PROPS = [
        'title' => ['type' => 'string', 'description' => 'Tiêu đề nội bộ, dưới 60 ký tự'],
        'caption' => ['type' => 'string', 'description' => 'Nội dung đăng, không gồm hashtag'],
        'hashtags' => ['type' => 'string', 'description' => 'Hashtag cách nhau bằng dấu cách'],
    ];

    public static function writePost(string $brief, string $tone = '', string $format = '', ?string $eventTitle = null, string $current = '', $offerId = null): array
    {
        $req = implode("\n", array_filter([
            "Ý chính của bài: $brief",
            $format ? "Định dạng: $format" : null,
            $tone ? "Sắc thái mong muốn: $tone" : null,
            $eventTitle ? "Bài gắn với sự kiện: $eventTitle" : null,
            $current ? "Bản nháp hiện tại (có thể dựa vào, viết lại cho hay hơn):\n$current" : null,
            self::offerBrief($offerId),
        ]));
        $schema = [
            'type' => 'object',
            'properties' => ['options' => [
                'type' => 'array',
                'description' => 'Đúng 3 phương án khác nhau về cách mở bài và góc nhìn',
                'items' => ['type' => 'object', 'properties' => self::OPTION_PROPS, 'required' => ['title', 'caption', 'hashtags'], 'additionalProperties' => false],
            ]],
            'required' => ['options'],
            'additionalProperties' => false,
        ];
        $r = self::callJson(self::WRITER_SYSTEM, "Thông tin khách sạn:\n" . self::hotelBrief() . "\n\nViết 3 phương án cho bài sau.\n$req", $schema);
        return array_slice($r['out']['options'] ?? [], 0, 3);
    }

    public static function ideasForEvent(array $ev): array
    {
        $schema = [
            'type' => 'object',
            'properties' => ['ideas' => [
                'type' => 'array',
                'description' => 'Đúng 3 ý tưởng, rải trước và trong sự kiện',
                'items' => [
                    'type' => 'object',
                    'properties' => self::OPTION_PROPS + [
                        'format' => ['type' => 'string', 'enum' => C::POST_FORMATS],
                        'days_before' => ['type' => 'integer', 'description' => 'Đăng trước ngày bắt đầu sự kiện bao nhiêu ngày (0 = đúng ngày)'],
                        'why' => ['type' => 'string', 'description' => 'Một câu giải thích vì sao bài này hợp với sự kiện'],
                    ],
                    'required' => ['title', 'caption', 'hashtags', 'format', 'days_before', 'why'],
                    'additionalProperties' => false,
                ],
            ]],
            'required' => ['ideas'],
            'additionalProperties' => false,
        ];
        $when = $ev['occ_start'] . ($ev['occ_end'] !== $ev['occ_start'] ? " đến {$ev['occ_end']}" : '');
        $content = "Thông tin khách sạn:\n" . self::hotelBrief() . "\n\nSự kiện: {$ev['title']} ($when)."
            . ($ev['description'] ? "\nGhi chú của team: {$ev['description']}" : '')
            . "\n\nĐề xuất 3 bài đăng cho dịp này, mỗi bài một góc khác nhau (ví dụ: báo trước ưu đãi, gợi ý trải nghiệm, nhắc đặt phòng sát ngày).";
        $r = self::callJson(self::WRITER_SYSTEM, $content, $schema);
        return array_slice($r['out']['ideas'] ?? [], 0, 3);
    }

    /* ---------- Kế hoạch nội dung cả tháng ---------- */

    public static function planMonth(string $month, int $perWeek, string $focus): array
    {
        ['from' => $from, 'to' => $to] = Report::monthRange($month);
        $events = array_map(fn($e) => ['id' => $e['id'], 'title' => $e['title'], 'from' => $e['occ_start'], 'to' => $e['occ_end']], Events::occurrences($from, $to));
        $offerList = array_values(array_map(
            fn($o) => ['id' => (int) $o['id'], 'title' => $o['title'], 'summary' => $o['summary'], 'from' => $o['start_date'], 'to' => $o['end_date']],
            array_filter(Offers::active($from), fn($o) => $o['start_date'] <= $to)
        ));
        $existing = DB::all('SELECT title, scheduled_at FROM posts WHERE scheduled_at BETWEEN ? AND ? ORDER BY scheduled_at', ["$from 00:00:00", "$to 23:59:59"]);
        $best = DB::all(
            "SELECT title, format, views, likes, comments, shares FROM posts WHERE status = 'published' AND published_at >= ?
             ORDER BY (likes + comments + shares) DESC LIMIT 8",
            [D::addDays(D::today(), -90) . ' 00:00:00']
        );
        $count = max(4, min(40, (int) round(($perWeek ?: 3) * 4.3)));
        $schema = [
            'type' => 'object',
            'properties' => [
                'overview' => ['type' => 'string', 'description' => '2-3 câu: chủ đề chính của tháng và cách phân bổ bài'],
                'items' => [
                    'type' => 'array',
                    'description' => "Khoảng $count bài, rải đều trong tháng, không trùng ngày với bài đã có nếu được",
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => ['type' => 'string', 'description' => "Ngày đăng YYYY-MM-DD, trong khoảng $from đến $to"],
                            'time' => ['type' => 'string', 'description' => 'Giờ đăng HH:MM'],
                        ] + self::OPTION_PROPS + [
                            'format' => ['type' => 'string', 'enum' => C::POST_FORMATS],
                            'event_id' => ['type' => 'integer', 'description' => 'id sự kiện liên quan, 0 nếu không có'],
                            'offer_id' => ['type' => 'integer', 'description' => 'id ưu đãi bài này quảng bá, 0 nếu không có'],
                            'why' => ['type' => 'string', 'description' => 'Một câu: mục đích của bài'],
                        ],
                        'required' => ['date', 'time', 'title', 'caption', 'hashtags', 'format', 'event_id', 'offer_id', 'why'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['overview', 'items'],
            'additionalProperties' => false,
        ];
        $system = self::WRITER_SYSTEM . "\n- Khi lập kế hoạch cả tháng: xen kẽ nhiều loại bài (giới thiệu phòng, trải nghiệm quanh khách sạn, hậu trường, ưu đãi, đánh giá của khách), bám theo sự kiện và ưu đãi trong tháng, ưu tiên kiểu bài từng hiệu quả.\n- Bài gắn ưu đãi phải ghi mã là {{ma}} và dùng đúng thông tin ưu đãi được cung cấp.";
        $content = "Thông tin khách sạn:\n" . self::hotelBrief() . "\n\nLập kế hoạch đăng bài tháng $month (khoảng $perWeek bài mỗi tuần)."
            . ($focus ? "\nTrọng tâm tháng này: $focus" : '')
            . "\n\nDữ liệu (JSON):\n" . self::json(['su_kien' => $events, 'uu_dai' => $offerList, 'bai_da_len_lich' => $existing, 'bai_hieu_qua_90_ngay' => $best]);
        $out = self::callJson($system, $content, $schema, 32000)['out'];

        $validEvent = array_column($events, 'id');
        $validOffer = array_column($offerList, 'id');
        $items = [];
        foreach ($out['items'] ?? [] as $it) {
            if (!D::isDate($it['date'] ?? null) || $it['date'] < $from || $it['date'] > $to) continue;
            $it['time'] = D::isTime($it['time'] ?? null) ? $it['time'] : '09:00';
            $it['event_id'] = in_array((int) $it['event_id'], $validEvent, true) ? (int) $it['event_id'] : null;
            $it['offer_id'] = in_array((int) $it['offer_id'], $validOffer, true) ? (int) $it['offer_id'] : null;
            $items[] = $it;
        }
        usort($items, fn($a, $b) => strcmp($a['date'] . $a['time'], $b['date'] . $b['time']));
        return ['overview' => $out['overview'] ?? '', 'items' => $items, 'events' => $events, 'offers' => $offerList];
    }

    // Nhận xét báo cáo tháng; lưu như một bản phân tích với channel = "report-YYYY-MM"
    public static function commentReport(array $report, ?array $user = null): void
    {
        $system = self::SYSTEM . "\n- Đây là báo cáo tổng hợp một tháng cho ban giám đốc: nêu kết quả chính, kênh và chi nhánh nổi bật, ưu đãi nào mang khách về (theo lượt dùng mã và doanh thu), và đề xuất cho tháng sau.";
        $r = self::callJson($system, "Nhận xét báo cáo marketing tháng {$report['month']} của Bin Bin Hotel (JSON):\n\n" . self::json($report), self::SCHEMA);
        self::saveInsight("report-{$report['month']}", $r['out'], $r['model'], $user);
    }
}
