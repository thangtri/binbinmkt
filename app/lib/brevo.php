<?php
// Gửi email qua Brevo Transactional API. Không có BREVO_API_KEY thì chạy chế độ thử:
// ghi nhận như đã gửi nhưng không gửi thật.

final class Brevo
{
    const API = 'https://api.brevo.com/v3/smtp/email';

    public static function isLive(): bool
    {
        return cfg('brevo.apiKey') !== '' && cfg('brevo.senderEmail') !== '';
    }

    // Bọc nội dung chiến dịch trong khung email có chân trang và link huỷ đăng ký
    public static function renderEmail(string $subject, ?string $preheader, ?string $bodyHtml, string $unsubscribeUrl, bool $transactional = false): string
    {
        $footer = $transactional
            ? 'Email tự động từ Bin Bin Marketing, công cụ nội bộ của Bin Bin Hotel.'
            : 'Bạn nhận email này vì đã là khách hoặc đã đăng ký nhận tin từ Bin Bin Hotel.<br>' . "\n" . '<a href="' . e($unsubscribeUrl) . '" style="color:#777777;">Huỷ nhận email</a>';
        $s = e($subject);
        $p = e($preheader ?? '');
        $b = $bodyHtml ?? '';
        return <<<HTML
<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>$s</title></head>
<body style="margin:0;padding:0;background:#f2f2f4;font-family:Arial,Helvetica,sans-serif;color:#2a2122;">
<span style="display:none;max-height:0;overflow:hidden;opacity:0;">$p</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f2f4;padding:24px 12px;"><tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:6px;overflow:hidden;">
<tr><td style="background:#ed3237;padding:18px 28px;color:#ffffff;font-size:18px;font-weight:bold;letter-spacing:.5px;">Bin Bin Hotel</td></tr>
<tr><td style="padding:28px;font-size:15px;line-height:1.6;">$b</td></tr>
<tr><td style="padding:18px 28px;border-top:1px solid #eeeeee;font-size:12px;line-height:1.5;color:#777777;">
$footer
</td></tr></table></td></tr></table></body></html>
HTML;
    }

    /** @return array{messageId:string, dryRun:bool} */
    public static function send(string $to, ?string $name, string $subject, string $html, ?string $unsubscribeUrl = null, ?string $tag = null): array
    {
        if (!self::isLive()) {
            return ['messageId' => 'dryrun-' . time() . '-' . bin2hex(random_bytes(3)), 'dryRun' => true];
        }
        $payload = [
            'sender' => ['email' => cfg('brevo.senderEmail'), 'name' => cfg('brevo.senderName')],
            'to' => [array_filter(['email' => $to, 'name' => $name ?: null])],
            'subject' => $subject,
            'htmlContent' => $html,
        ];
        if ($tag) $payload['tags'] = [$tag];
        if ($unsubscribeUrl) $payload['headers'] = ['List-Unsubscribe' => "<$unsubscribeUrl>"];
        $res = Http::request(self::API, [
            'method' => 'POST',
            'headers' => ['api-key' => cfg('brevo.apiKey')],
            'json' => $payload,
        ]);
        if (!$res['ok']) throw new RuntimeException($res['data']['message'] ?? "Brevo trả về lỗi {$res['status']}");
        return ['messageId' => (string) ($res['data']['messageId'] ?? ''), 'dryRun' => false];
    }
}
