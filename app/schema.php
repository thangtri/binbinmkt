<?php
// Migration đơn giản, đánh số tăng dần. Mỗi migration chỉ chạy một lần.

final class Schema
{
    private static function types(): array
    {
        $mysql = DB::$dialect === 'mysql';
        return [
            'id' => $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'longtext' => $mysql ? 'MEDIUMTEXT' : 'TEXT',
            'tail' => $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '',
        ];
    }

    private static function migrations(): array
    {
        return [
            'initial' => function (string $id, string $longtext, string $tail): array {
                return [
                    "CREATE TABLE users (
                        id $id,
                        username VARCHAR(40) NOT NULL UNIQUE,
                        email VARCHAR(190) NOT NULL UNIQUE,
                        name VARCHAR(120) NOT NULL,
                        password_hash VARCHAR(255) NOT NULL,
                        role VARCHAR(20) NOT NULL DEFAULT 'editor',
                        active INT NOT NULL DEFAULT 1,
                        session_version INT NOT NULL DEFAULT 1,
                        created_at DATETIME NOT NULL,
                        last_login_at DATETIME NULL
                    )$tail",
                    "CREATE TABLE events (
                        id $id,
                        title VARCHAR(200) NOT NULL,
                        kind VARCHAR(30) NOT NULL DEFAULT 'holiday',
                        start_date DATE NOT NULL,
                        end_date DATE NOT NULL,
                        recurring INT NOT NULL DEFAULT 0,
                        description TEXT NULL,
                        created_by INT NULL,
                        created_at DATETIME NOT NULL
                    )$tail",
                    "CREATE TABLE posts (
                        id $id,
                        title VARCHAR(200) NOT NULL,
                        channel VARCHAR(20) NOT NULL DEFAULT 'all',
                        format VARCHAR(40) NULL,
                        caption $longtext NULL,
                        hashtags TEXT NULL,
                        media_path VARCHAR(255) NULL,
                        scheduled_at DATETIME NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'draft',
                        event_id INT NULL,
                        offer_id INT NULL,
                        author_id INT NULL,
                        reviewer_id INT NULL,
                        review_note TEXT NULL,
                        notes TEXT NULL,
                        auto_publish INT NOT NULL DEFAULT 1,
                        published_at DATETIME NULL,
                        published_url VARCHAR(500) NULL,
                        views INT NOT NULL DEFAULT 0,
                        likes INT NOT NULL DEFAULT 0,
                        comments INT NOT NULL DEFAULT 0,
                        shares INT NOT NULL DEFAULT 0,
                        created_at DATETIME NOT NULL,
                        updated_at DATETIME NOT NULL
                    )$tail",
                    'CREATE INDEX idx_posts_scheduled ON posts (scheduled_at)',
                    'CREATE INDEX idx_posts_status ON posts (status)',
                    // Một bài đăng lên tất cả các kênh; link, số liệu và trạng thái tự đăng lưu theo từng kênh
                    "CREATE TABLE post_channels (
                        id $id,
                        post_id INT NOT NULL,
                        channel VARCHAR(20) NOT NULL,
                        published_url VARCHAR(500) NULL,
                        views INT NULL,
                        likes INT NULL,
                        comments INT NULL,
                        shares INT NULL,
                        publish_status VARCHAR(20) NULL,
                        publish_error VARCHAR(500) NULL,
                        external_id VARCHAR(200) NULL,
                        updated_at DATETIME NULL
                    )$tail",
                    'CREATE UNIQUE INDEX idx_post_channels ON post_channels (post_id, channel)',
                    "CREATE TABLE post_media (
                        id $id,
                        post_id INT NOT NULL,
                        path VARCHAR(255) NOT NULL,
                        mime VARCHAR(60) NULL,
                        size INT NULL,
                        position INT NOT NULL DEFAULT 0,
                        created_at DATETIME NOT NULL
                    )$tail",
                    'CREATE INDEX idx_post_media ON post_media (post_id, position)',
                    "CREATE TABLE channel_accounts (
                        channel VARCHAR(20) PRIMARY KEY,
                        display_name VARCHAR(150) NULL,
                        handle VARCHAR(150) NULL,
                        url VARCHAR(500) NULL,
                        owner_id INT NULL,
                        notes TEXT NULL,
                        external_id VARCHAR(200) NULL,
                        sync_status VARCHAR(20) NULL,
                        sync_error VARCHAR(500) NULL,
                        last_synced_at DATETIME NULL,
                        extra $longtext NULL,
                        updated_at DATETIME NULL
                    )$tail",
                    "CREATE TABLE channel_stats (
                        id $id,
                        channel VARCHAR(20) NOT NULL,
                        branch_id INT NULL,
                        recorded_on DATE NOT NULL,
                        followers INT NULL,
                        reach INT NULL,
                        engagement INT NULL,
                        rating DECIMAL(3,2) NULL,
                        reviews INT NULL,
                        note VARCHAR(255) NULL,
                        source VARCHAR(20) NULL,
                        created_by INT NULL,
                        created_at DATETIME NOT NULL
                    )$tail",
                    'CREATE INDEX idx_stats_channel ON channel_stats (channel, recorded_on)',
                    'CREATE INDEX idx_stats_branch ON channel_stats (branch_id, recorded_on)',
                    "CREATE TABLE channel_tokens (
                        channel VARCHAR(20) PRIMARY KEY,
                        access_token TEXT NULL,
                        refresh_token TEXT NULL,
                        expires_at DATETIME NULL,
                        refresh_expires_at DATETIME NULL,
                        updated_at DATETIME NOT NULL
                    )$tail",
                    "CREATE TABLE channel_insights (
                        id $id,
                        channel VARCHAR(20) NOT NULL,
                        summary TEXT NULL,
                        highlights TEXT NULL,
                        concerns TEXT NULL,
                        recommendations TEXT NULL,
                        model VARCHAR(60) NULL,
                        created_by INT NULL,
                        created_at DATETIME NOT NULL
                    )$tail",
                    'CREATE INDEX idx_insights_channel ON channel_insights (channel, created_at)',
                    "CREATE TABLE contacts (
                        id $id,
                        email VARCHAR(190) NOT NULL UNIQUE,
                        name VARCHAR(150) NULL,
                        phone VARCHAR(30) NULL,
                        tags VARCHAR(255) NULL,
                        subscribed INT NOT NULL DEFAULT 1,
                        unsub_token VARCHAR(64) NOT NULL UNIQUE,
                        source VARCHAR(50) NULL,
                        created_at DATETIME NOT NULL,
                        unsubscribed_at DATETIME NULL
                    )$tail",
                    "CREATE TABLE campaigns (
                        id $id,
                        name VARCHAR(200) NOT NULL,
                        subject VARCHAR(200) NOT NULL,
                        preheader VARCHAR(200) NULL,
                        body_html $longtext NULL,
                        audience_tag VARCHAR(60) NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'draft',
                        dry_run INT NOT NULL DEFAULT 0,
                        total INT NOT NULL DEFAULT 0,
                        created_by INT NULL,
                        sent_by INT NULL,
                        created_at DATETIME NOT NULL,
                        updated_at DATETIME NOT NULL,
                        sent_at DATETIME NULL
                    )$tail",
                    "CREATE TABLE campaign_recipients (
                        id $id,
                        campaign_id INT NOT NULL,
                        contact_id INT NULL,
                        email VARCHAR(190) NOT NULL,
                        message_id VARCHAR(190) NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'queued',
                        error VARCHAR(255) NULL,
                        sent_at DATETIME NULL,
                        delivered_at DATETIME NULL,
                        opened_at DATETIME NULL,
                        clicked_at DATETIME NULL,
                        bounced_at DATETIME NULL,
                        unsubscribed_at DATETIME NULL
                    )$tail",
                    'CREATE INDEX idx_recipients_campaign ON campaign_recipients (campaign_id)',
                    'CREATE INDEX idx_recipients_message ON campaign_recipients (message_id)',
                    "CREATE TABLE activity (
                        id $id,
                        user_id INT NULL,
                        action VARCHAR(60) NOT NULL,
                        entity VARCHAR(30) NULL,
                        entity_id INT NULL,
                        summary VARCHAR(255) NULL,
                        created_at DATETIME NOT NULL
                    )$tail",
                    "CREATE TABLE password_resets (
                        id $id,
                        user_id INT NOT NULL,
                        token_hash VARCHAR(64) NOT NULL,
                        expires_at DATETIME NOT NULL,
                        used_at DATETIME NULL,
                        created_at DATETIME NOT NULL
                    )$tail",
                    'CREATE INDEX idx_resets_token ON password_resets (token_hash)',
                    "CREATE TABLE settings (
                        name VARCHAR(60) PRIMARY KEY,
                        value $longtext NULL,
                        updated_at DATETIME NULL
                    )$tail",
                    // Nhiều chi nhánh: mỗi chi nhánh một địa điểm Google Maps; Facebook, TikTok, Zalo OA dùng chung
                    "CREATE TABLE branches (
                        id $id,
                        name VARCHAR(150) NOT NULL,
                        address VARCHAR(300) NULL,
                        hotline VARCHAR(60) NULL,
                        zalo VARCHAR(150) NULL,
                        maps_url VARCHAR(500) NULL,
                        place_id VARCHAR(200) NULL,
                        sync_status VARCHAR(20) NULL,
                        sync_error VARCHAR(500) NULL,
                        last_synced_at DATETIME NULL,
                        extra $longtext NULL,
                        active INT NOT NULL DEFAULT 1,
                        position INT NOT NULL DEFAULT 0,
                        created_at DATETIME NOT NULL
                    )$tail",
                    "CREATE TABLE post_google (
                        id $id,
                        post_id INT NOT NULL,
                        branch_id INT NOT NULL,
                        published_url VARCHAR(500) NULL,
                        updated_at DATETIME NULL
                    )$tail",
                    'CREATE UNIQUE INDEX idx_post_google ON post_google (post_id, branch_id)',
                    // Ưu đãi và mã giảm giá theo kênh
                    "CREATE TABLE offers (
                        id $id,
                        title VARCHAR(200) NOT NULL,
                        summary VARCHAR(300) NULL,
                        details TEXT NULL,
                        terms TEXT NULL,
                        branch_ids VARCHAR(255) NULL,
                        start_date DATE NOT NULL,
                        end_date DATE NOT NULL,
                        code_prefix VARCHAR(20) NOT NULL,
                        active INT NOT NULL DEFAULT 1,
                        created_by INT NULL,
                        created_at DATETIME NOT NULL
                    )$tail",
                    "CREATE TABLE offer_codes (
                        id $id,
                        offer_id INT NOT NULL,
                        channel VARCHAR(20) NOT NULL,
                        code VARCHAR(40) NOT NULL UNIQUE
                    )$tail",
                    "CREATE TABLE offer_redemptions (
                        id $id,
                        offer_id INT NOT NULL,
                        code_id INT NULL,
                        channel VARCHAR(20) NULL,
                        branch_id INT NULL,
                        used_on DATE NOT NULL,
                        guest VARCHAR(150) NULL,
                        nights INT NULL,
                        amount INT NULL,
                        note VARCHAR(255) NULL,
                        created_by INT NULL,
                        created_at DATETIME NOT NULL
                    )$tail",
                    'CREATE INDEX idx_redemptions_offer ON offer_redemptions (offer_id, used_on)',
                    // Đối thủ trên Google Maps
                    "CREATE TABLE competitors (
                        id $id,
                        branch_id INT NULL,
                        name VARCHAR(150) NOT NULL,
                        maps_url VARCHAR(500) NULL,
                        place_id VARCHAR(200) NULL,
                        sync_status VARCHAR(20) NULL,
                        sync_error VARCHAR(500) NULL,
                        last_synced_at DATETIME NULL,
                        extra $longtext NULL,
                        active INT NOT NULL DEFAULT 1,
                        created_at DATETIME NOT NULL
                    )$tail",
                    "CREATE TABLE competitor_stats (
                        id $id,
                        competitor_id INT NOT NULL,
                        recorded_on DATE NOT NULL,
                        rating DECIMAL(3,2) NULL,
                        reviews INT NULL,
                        created_at DATETIME NOT NULL
                    )$tail",
                    'CREATE INDEX idx_competitor_stats ON competitor_stats (competitor_id, recorded_on)',
                    // Kế hoạch nội dung cả tháng do AI lập
                    "CREATE TABLE ai_plans (
                        id $id,
                        month VARCHAR(7) NOT NULL,
                        brief TEXT NULL,
                        items $longtext NOT NULL,
                        created_by INT NULL,
                        created_at DATETIME NOT NULL
                    )$tail",
                    // Dữ liệu tạm có hạn: chặn dò mật khẩu, danh sách Page Facebook chờ chọn, khoá việc nền
                    "CREATE TABLE cache (
                        k VARCHAR(190) PRIMARY KEY,
                        v $longtext NULL,
                        expires_at DATETIME NOT NULL
                    )$tail",
                ];
            },
        ];
    }

    public static function migrate(): void
    {
        $t = self::types();
        DB::exec("CREATE TABLE IF NOT EXISTS schema_migrations (version INT PRIMARY KEY)" . $t['tail']);
        $done = array_map('intval', array_column(DB::all('SELECT version FROM schema_migrations'), 'version'));
        $version = 0;
        foreach (self::migrations() as $name => $fn) {
            $version++;
            if (in_array($version, $done, true)) continue;
            foreach ($fn($t['id'], $t['longtext'], $t['tail']) as $step) {
                if (is_callable($step)) $step();
                else DB::exec($step);
            }
            DB::run('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
            error_log("[db] Đã chạy migration $version: $name");
        }
    }

    // Dữ liệu nền tảng: admin đầu tiên, 4 kênh, các ngày lễ lặp lại hằng năm
    public static function seed(): void
    {
        $now = D::now();
        if ((int) DB::value('SELECT COUNT(*) FROM users') === 0) {
            $a = cfg('admin');
            if ($a['email'] && $a['password']) {
                DB::run(
                    'INSERT INTO users (username, email, name, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)',
                    [$a['username'], $a['email'], $a['name'], Auth::hashPassword($a['password']), 'admin', $now]
                );
                error_log("[seed] Đã tạo tài khoản admin, tên đăng nhập: {$a['username']}");
            }
        }
        foreach (array_keys(C::CHANNELS) as $ch) {
            if (!DB::get('SELECT channel FROM channel_accounts WHERE channel = ?', [$ch])) {
                DB::run('INSERT INTO channel_accounts (channel, display_name, updated_at) VALUES (?, ?, ?)', [$ch, 'Bin Bin Hotel', $now]);
            }
        }
        if ((int) DB::value('SELECT COUNT(*) FROM events') === 0) {
            // Ngày lễ dương lịch lặp lại hằng năm (năm gốc 2026)
            $recurring = [
                ['Tết Dương lịch', '2026-01-01', '2026-01-01', 'holiday'],
                ['Lễ Tình nhân', '2026-02-14', '2026-02-14', 'holiday'],
                ['Quốc tế Phụ nữ 8/3', '2026-03-08', '2026-03-08', 'holiday'],
                ['Giải phóng miền Nam & Quốc tế Lao động', '2026-04-30', '2026-05-01', 'holiday'],
                ['Quốc tế Thiếu nhi', '2026-06-01', '2026-06-01', 'holiday'],
                ['Mùa du lịch hè', '2026-06-01', '2026-08-31', 'season'],
                ['Quốc khánh 2/9', '2026-09-02', '2026-09-02', 'holiday'],
                ['Phụ nữ Việt Nam 20/10', '2026-10-20', '2026-10-20', 'holiday'],
                ['Nhà giáo Việt Nam 20/11', '2026-11-20', '2026-11-20', 'holiday'],
                ['Giáng sinh', '2026-12-24', '2026-12-25', 'holiday'],
            ];
            // Lễ theo âm lịch: ngày dương thay đổi mỗi năm, nhập riêng từng năm
            $lunar = [
                ['Tết Trung thu', '2026-09-25', '2026-09-25', 'holiday'],
                ['Tết Nguyên đán Đinh Mùi', '2027-02-05', '2027-02-10', 'holiday'],
                ['Tết Trung thu', '2027-09-15', '2027-09-15', 'holiday'],
            ];
            foreach ([[$recurring, 1], [$lunar, 0]] as [$list, $rec]) {
                foreach ($list as [$title, $start, $end, $kind]) {
                    DB::run('INSERT INTO events (title, kind, start_date, end_date, recurring, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$title, $kind, $start, $end, $rec, $now]);
                }
            }
        }
        // Chuỗi có ít nhất một chi nhánh để gắn Google Maps
        if ((int) DB::value('SELECT COUNT(*) FROM branches') === 0) {
            DB::run('INSERT INTO branches (name, active, position, created_at) VALUES (?, 1, 0, ?)', ['Bin Bin Hotel', $now]);
        }
    }
}
