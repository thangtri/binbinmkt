// Migration đơn giản, đánh số tăng dần. Mỗi migration chỉ chạy một lần.
const db = require('./index');

function types() {
  const mysql = db.dialect === 'mysql';
  return {
    id: mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
    longtext: mysql ? 'MEDIUMTEXT' : 'TEXT',
    tail: mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '',
  };
}

const migrations = [
  function initial({ id, longtext, tail }) {
    return [
      `CREATE TABLE users (
        id ${id},
        email VARCHAR(190) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'editor',
        active INT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        last_login_at DATETIME NULL
      )${tail}`,
      `CREATE TABLE events (
        id ${id},
        title VARCHAR(200) NOT NULL,
        kind VARCHAR(30) NOT NULL DEFAULT 'holiday',
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        recurring INT NOT NULL DEFAULT 0,
        description TEXT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL
      )${tail}`,
      `CREATE TABLE posts (
        id ${id},
        title VARCHAR(200) NOT NULL,
        channel VARCHAR(20) NOT NULL,
        format VARCHAR(40) NULL,
        caption ${longtext} NULL,
        hashtags TEXT NULL,
        media_path VARCHAR(255) NULL,
        scheduled_at DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        event_id INT NULL,
        author_id INT NULL,
        reviewer_id INT NULL,
        review_note TEXT NULL,
        notes TEXT NULL,
        published_at DATETIME NULL,
        published_url VARCHAR(500) NULL,
        views INT NOT NULL DEFAULT 0,
        likes INT NOT NULL DEFAULT 0,
        comments INT NOT NULL DEFAULT 0,
        shares INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
      )${tail}`,
      'CREATE INDEX idx_posts_scheduled ON posts (scheduled_at)',
      'CREATE INDEX idx_posts_status ON posts (status)',
      `CREATE TABLE channel_accounts (
        channel VARCHAR(20) PRIMARY KEY,
        display_name VARCHAR(150) NULL,
        handle VARCHAR(150) NULL,
        url VARCHAR(500) NULL,
        owner_id INT NULL,
        notes TEXT NULL,
        updated_at DATETIME NULL
      )${tail}`,
      `CREATE TABLE channel_stats (
        id ${id},
        channel VARCHAR(20) NOT NULL,
        recorded_on DATE NOT NULL,
        followers INT NULL,
        reach INT NULL,
        engagement INT NULL,
        rating DECIMAL(3,2) NULL,
        reviews INT NULL,
        note VARCHAR(255) NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL
      )${tail}`,
      'CREATE INDEX idx_stats_channel ON channel_stats (channel, recorded_on)',
      `CREATE TABLE contacts (
        id ${id},
        email VARCHAR(190) NOT NULL UNIQUE,
        name VARCHAR(150) NULL,
        phone VARCHAR(30) NULL,
        tags VARCHAR(255) NULL,
        subscribed INT NOT NULL DEFAULT 1,
        unsub_token VARCHAR(64) NOT NULL UNIQUE,
        source VARCHAR(50) NULL,
        created_at DATETIME NOT NULL,
        unsubscribed_at DATETIME NULL
      )${tail}`,
      `CREATE TABLE campaigns (
        id ${id},
        name VARCHAR(200) NOT NULL,
        subject VARCHAR(200) NOT NULL,
        preheader VARCHAR(200) NULL,
        body_html ${longtext} NULL,
        audience_tag VARCHAR(60) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        dry_run INT NOT NULL DEFAULT 0,
        total INT NOT NULL DEFAULT 0,
        created_by INT NULL,
        sent_by INT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        sent_at DATETIME NULL
      )${tail}`,
      `CREATE TABLE campaign_recipients (
        id ${id},
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
      )${tail}`,
      'CREATE INDEX idx_recipients_campaign ON campaign_recipients (campaign_id)',
      'CREATE INDEX idx_recipients_message ON campaign_recipients (message_id)',
      `CREATE TABLE activity (
        id ${id},
        user_id INT NULL,
        action VARCHAR(60) NOT NULL,
        entity VARCHAR(30) NULL,
        entity_id INT NULL,
        summary VARCHAR(255) NULL,
        created_at DATETIME NOT NULL
      )${tail}`,
    ];
  },
  // Tự động lấy số liệu kênh và phân tích bằng AI
  function autoSync({ id, longtext, tail }) {
    return [
      'ALTER TABLE channel_accounts ADD COLUMN external_id VARCHAR(200) NULL',
      'ALTER TABLE channel_accounts ADD COLUMN sync_status VARCHAR(20) NULL',
      'ALTER TABLE channel_accounts ADD COLUMN sync_error VARCHAR(500) NULL',
      'ALTER TABLE channel_accounts ADD COLUMN last_synced_at DATETIME NULL',
      `ALTER TABLE channel_accounts ADD COLUMN extra ${longtext} NULL`,
      'ALTER TABLE channel_stats ADD COLUMN source VARCHAR(20) NULL',
      `CREATE TABLE channel_tokens (
        channel VARCHAR(20) PRIMARY KEY,
        access_token TEXT NULL,
        refresh_token TEXT NULL,
        expires_at DATETIME NULL,
        refresh_expires_at DATETIME NULL,
        updated_at DATETIME NOT NULL
      )${tail}`,
      `CREATE TABLE channel_insights (
        id ${id},
        channel VARCHAR(20) NOT NULL,
        summary TEXT NULL,
        highlights TEXT NULL,
        concerns TEXT NULL,
        recommendations TEXT NULL,
        model VARCHAR(60) NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL
      )${tail}`,
      'CREATE INDEX idx_insights_channel ON channel_insights (channel, created_at)',
    ];
  },
  // Đăng nhập bằng tên người dùng; tài khoản cũ lấy phần trước @ của email làm tên
  function usernames() {
    return [
      'ALTER TABLE users ADD COLUMN username VARCHAR(40) NULL',
      async () => {
        const { suggestUsername } = require('../lib/auth');
        const taken = new Set();
        for (const u of await db.all('SELECT id, email FROM users ORDER BY id')) {
          const name = suggestUsername(u.email, taken);
          taken.add(name);
          await db.run('UPDATE users SET username = ? WHERE id = ?', [name, u.id]);
        }
      },
      'CREATE UNIQUE INDEX idx_users_username ON users (username)',
    ];
  },
  // Một bài đăng lên tất cả các kênh; link và số liệu lưu riêng theo từng kênh
  function postChannels({ id, tail }) {
    return [
      `CREATE TABLE post_channels (
        id ${id},
        post_id INT NOT NULL,
        channel VARCHAR(20) NOT NULL,
        published_url VARCHAR(500) NULL,
        views INT NULL,
        likes INT NULL,
        comments INT NULL,
        shares INT NULL,
        updated_at DATETIME NULL
      )${tail}`,
      'CREATE UNIQUE INDEX idx_post_channels ON post_channels (post_id, channel)',
      async () => {
        const { CHANNELS } = require('../lib/constants');
        for (const p of await db.all('SELECT * FROM posts')) {
          for (const ch of Object.keys(CHANNELS)) {
            const own = ch === p.channel;
            await db.run(
              'INSERT INTO post_channels (post_id, channel, published_url, views, likes, comments, shares, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
              [p.id, ch, own ? p.published_url : null, own ? p.views : null, own ? p.likes : null, own ? p.comments : null, own ? p.shares : null, p.updated_at]
            );
          }
        }
        await db.run(`UPDATE posts SET channel = 'all'`);
      },
    ];
  },
  // Quên mật khẩu, nhiều ảnh cho một bài, thông tin khách sạn cho AI, tự đăng bài
  function resetMediaSettingsPublish({ id, longtext, tail }) {
    return [
      'ALTER TABLE users ADD COLUMN session_version INT NOT NULL DEFAULT 1',
      `CREATE TABLE password_resets (
        id ${id},
        user_id INT NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL
      )${tail}`,
      'CREATE INDEX idx_resets_token ON password_resets (token_hash)',
      `CREATE TABLE post_media (
        id ${id},
        post_id INT NOT NULL,
        path VARCHAR(255) NOT NULL,
        mime VARCHAR(60) NULL,
        size INT NULL,
        position INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL
      )${tail}`,
      'CREATE INDEX idx_post_media ON post_media (post_id, position)',
      async () => {
        const rows = await db.all('SELECT id, media_path, created_at FROM posts WHERE media_path IS NOT NULL');
        for (const p of rows) {
          const mime = /\.(mp4|mov|webm)$/i.test(p.media_path) ? 'video/mp4' : 'image/jpeg';
          await db.run('INSERT INTO post_media (post_id, path, mime, position, created_at) VALUES (?, ?, ?, 0, ?)', [p.id, p.media_path, mime, p.created_at]);
        }
      },
      `CREATE TABLE settings (
        name VARCHAR(60) PRIMARY KEY,
        value ${longtext} NULL,
        updated_at DATETIME NULL
      )${tail}`,
      'ALTER TABLE posts ADD COLUMN auto_publish INT NOT NULL DEFAULT 1',
      'ALTER TABLE post_channels ADD COLUMN publish_status VARCHAR(20) NULL',
      'ALTER TABLE post_channels ADD COLUMN publish_error VARCHAR(500) NULL',
      'ALTER TABLE post_channels ADD COLUMN external_id VARCHAR(200) NULL',
    ];
  },
];

async function migrate() {
  const { tail } = types();
  await db.exec(`CREATE TABLE IF NOT EXISTS schema_migrations (version INT PRIMARY KEY)${tail}`);
  const done = new Set((await db.all('SELECT version FROM schema_migrations')).map((r) => Number(r.version)));
  for (let i = 0; i < migrations.length; i++) {
    const version = i + 1;
    if (done.has(version)) continue;
    for (const step of migrations[i](types())) {
      if (typeof step === 'function') await step();
      else await db.exec(step);
    }
    await db.run('INSERT INTO schema_migrations (version) VALUES (?)', [version]);
    console.log(`[db] Đã chạy migration ${version}: ${migrations[i].name}`);
  }
}

module.exports = { migrate };
