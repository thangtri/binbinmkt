<?php
// Gửi chiến dịch email: xếp người nhận vào hàng đợi, gửi lần lượt và ghi kết quả vào campaign_recipients.
// Gửi ngay sau khi bấm (sau khi trang đã trả về) và cron gửi tiếp phần còn lại nếu bị gián đoạn.

final class Campaigns
{
    public static function unsubscribeUrl(string $token): string
    {
        return cfg('appUrl') . '/u/' . $token;
    }

    public static function hasTag(?string $tags, string $tag): bool
    {
        return in_array($tag, array_map(fn($t) => mb_strtolower(trim($t)), explode(',', (string) $tags)), true);
    }

    public static function audience(?string $tag): array
    {
        $all = DB::all('SELECT id, email, name, tags, unsub_token FROM contacts WHERE subscribed = 1 ORDER BY id');
        return $tag ? array_values(array_filter($all, fn($c) => self::hasTag($c['tags'], $tag))) : $all;
    }

    public static function enqueue(array $campaign): int
    {
        $list = self::audience($campaign['audience_tag']);
        foreach ($list as $c) {
            DB::run('INSERT INTO campaign_recipients (campaign_id, contact_id, email, status) VALUES (?, ?, ?, ?)', [$campaign['id'], $c['id'], $c['email'], 'queued']);
        }
        DB::run("UPDATE campaigns SET status = 'sending', total = ?, dry_run = ?, updated_at = ? WHERE id = ?", [count($list), Brevo::isLive() ? 0 : 1, D::now(), $campaign['id']]);
        return count($list);
    }

    // {{ten}} → tên người nhận (hoặc "bạn" nếu chưa có tên)
    public static function personalize(?string $html, ?string $name): string
    {
        $name = trim((string) $name) ?: 'bạn';
        return preg_replace('/\{\{\s*ten\s*\}\}/iu', e($name), (string) $html);
    }

    // Gửi tối đa $seconds giây; phần còn lại để lần chạy sau
    public static function run(int $campaignId, int $seconds = 50): void
    {
        if (!Cache::lock("campaign:$campaignId", $seconds + 60)) return;
        $started = time();
        try {
            $campaign = DB::get('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
            if (!$campaign) return;
            $queue = DB::all(
                "SELECT r.id, r.email, c.name, c.unsub_token, c.subscribed FROM campaign_recipients r
                 LEFT JOIN contacts c ON c.id = r.contact_id
                 WHERE r.campaign_id = ? AND r.status = 'queued' ORDER BY r.id",
                [$campaignId]
            );
            foreach ($queue as $r) {
                if (time() - $started > $seconds) return;
                if (!$r['unsub_token'] || !(int) $r['subscribed']) {
                    DB::run("UPDATE campaign_recipients SET status = 'skipped', error = ? WHERE id = ?", ['Đã huỷ nhận email', $r['id']]);
                    continue;
                }
                $url = self::unsubscribeUrl($r['unsub_token']);
                $html = Brevo::renderEmail($campaign['subject'], $campaign['preheader'], self::personalize($campaign['body_html'], $r['name']), $url);
                try {
                    $out = Brevo::send($r['email'], $r['name'], $campaign['subject'], $html, $url, "campaign-$campaignId");
                    DB::run("UPDATE campaign_recipients SET status = 'sent', message_id = ?, sent_at = ? WHERE id = ?", [$out['messageId'], D::now(), $r['id']]);
                } catch (Throwable $e) {
                    DB::run("UPDATE campaign_recipients SET status = 'failed', error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 255), $r['id']]);
                }
                // Giãn nhịp gửi để không chạm giới hạn tốc độ của Brevo
                if (Brevo::isLive()) usleep(150000);
            }
            DB::run("UPDATE campaigns SET status = 'sent', sent_at = ?, updated_at = ? WHERE id = ?", [D::now(), D::now(), $campaignId]);
        } finally {
            Cache::unlock("campaign:$campaignId");
        }
    }

    // Gửi tiếp các chiến dịch còn người nhận trong hàng đợi
    public static function resume(int $seconds = 50): void
    {
        foreach (DB::all("SELECT id FROM campaigns WHERE status = 'sending'") as $r) self::run((int) $r['id'], $seconds);
    }
}
