<?php
// Việc nền chạy mỗi phút (cron): tự đăng bài, gửi tiếp email, cập nhật số liệu kênh, phân tích AI.

final class Jobs
{
    public static function run(): void
    {
        @set_time_limit(840);
        ignore_user_abort(true);
        foreach ([
            'publish' => fn() => Publisher::tick(),
            'email' => fn() => Campaigns::resume(40),
            'sync' => fn() => Sync::tick(),
        ] as $name => $fn) {
            try {
                $fn();
            } catch (Throwable $e) {
                error_log("[jobs] $name: " . $e->getMessage());
            }
        }
        Cache::set('jobs:last_run', D::now(), 7 * 86400);
    }
}
