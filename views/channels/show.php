<?= partial('partials/top') ?>
<?php
  $isGoogle = $ch['key'] === 'google';
  $editable = can('channel:edit');
  $showReach = !$isGoogle && $ch['key'] !== 'zalo';
  $sourceLabel = ['auto' => 'Tự động', 'manual' => 'Nhập tay'];
  $connectHelp = [
    'facebook' => 'Đăng nhập bằng tài khoản Facebook đang quản trị Page của khách sạn. Hệ thống xin quyền đọc số liệu và đăng những bài đã được duyệt lên Page.',
    'tiktok' => 'Đăng nhập tài khoản TikTok của khách sạn và cho phép đọc thông tin hồ sơ, danh sách video và đăng bài đã duyệt.',
    'zalo' => 'Đăng nhập bằng tài khoản Zalo đang là quản trị viên của OA và cấp quyền cho ứng dụng.',
  ];
  $profile = $extra['profile'] ?? [];
  $accountName = $account['handle'] ?? '' ?: ($account['display_name'] ?? '');
  $setupStep = $isGoogle ? 'Tạo API key cho Places API (New) trên Google Cloud.' : ($ch['key'] === 'facebook' ? 'Tạo ứng dụng trên developers.facebook.com.' : ($ch['key'] === 'tiktok' ? 'Tạo ứng dụng trên developers.tiktok.com (có Login Kit và Display API).' : 'Tạo ứng dụng trên developers.zalo.me và liên kết với OA.'));
?>
<header class="page-head">
  <div>
    <p class="crumbs"><a href="/channels">Kênh</a></p>
    <div class="ch-title ch-<?= $ch['key'] ?>">
      <span class="cc-code cc-code-lg"><?= $ch['code'] ?></span>
      <div>
        <h1><?= e($ch['name']) ?></h1>
        <p class="sub">
          <?php if (!empty($account['url'])): ?><a href="<?= e($account['url']) ?>" target="_blank" rel="noopener"><?= e(($profile['name'] ?? '') ?: ($accountName ?: 'Mở trang')) ?></a><?php else: ?><?= e($accountName ?: 'Chưa kết nối tài khoản') ?><?php endif ?>
          <span class="muted"><?= !empty($account['owner_name']) ? 'Phụ trách: ' . e($account['owner_name']) : 'Chưa phân công người phụ trách' ?></span>
        </p>
      </div>
    </div>
  </div>
  <div class="toolbar">
    <?php if (can('post:create')): ?><a class="btn" href="/posts/new"><?= icon('plus') ?> Soạn bài</a><?php endif ?>
  </div>
</header>

<section class="connect panel is-<?= $conn['connected'] ? ($conn['status'] === 'ok' ? 'ok' : 'warn') : 'off' ?>">
  <div class="connect-state">
    <span class="connect-dot" aria-hidden="true"></span>
    <div>
      <?php if (!$conn['connected']): ?>
        <strong>Chưa kết nối</strong>
        <p class="muted small">Kết nối một lần, số liệu sẽ tự cập nhật mỗi 12 giờ.</p>
      <?php elseif ($conn['status'] === 'ok'): ?>
        <strong>Đang tự động cập nhật</strong>
        <p class="muted small">Lần gần nhất: <?= D::dmyhm($conn['lastSyncedAt']) ?>. Lần tới: khoảng 12 giờ sau.</p>
      <?php elseif ($conn['status'] === 'reconnect'): ?>
        <strong>Cần kết nối lại</strong>
        <p class="muted small"><?= e($conn['error']) ?></p>
      <?php else: ?>
        <strong>Lần cập nhật gần nhất bị lỗi</strong>
        <p class="muted small"><?= e($conn['error'] ?: 'Chưa cập nhật lần nào.') ?></p>
      <?php endif ?>
    </div>
  </div>

  <?php if ($editable): ?>
    <div class="connect-actions">
      <?php if (!$conn['configured']): ?>
        <button class="btn btn-primary" type="button" disabled><?= icon('link') ?> <?= $isGoogle ? 'Lưu và lấy số liệu' : 'Kết nối ' . e($ch['name']) ?></button>
      <?php elseif ($isGoogle): ?>
        <a class="btn btn-primary" href="/channels/google"><?= icon('link') ?> Dán link Google Maps cho từng chi nhánh</a>
      <?php elseif (!$conn['connected'] || $conn['status'] === 'reconnect'): ?>
        <form method="post" action="/channels/<?= $ch['key'] ?>/connect">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-primary" type="submit"><?= icon('link') ?> <?= $conn['connected'] ? 'Kết nối lại' : 'Kết nối ' . e($ch['name']) ?></button>
        </form>
      <?php endif ?>
      <?php if ($conn['connected'] && $conn['configured']): ?>
        <form method="post" action="/channels/<?= $ch['key'] ?>/sync">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <button class="btn" type="submit" data-busy="Đang cập nhật…">Cập nhật ngay</button>
        </form>
        <form method="post" action="/channels/<?= $ch['key'] ?>/disconnect" data-confirm="Ngắt kết nối <?= e($ch['name']) ?>? Số liệu đã lấy vẫn được giữ.">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-quiet" type="submit">Ngắt kết nối</button>
        </form>
      <?php endif ?>
    </div>
    <?php if (!$conn['configured']): ?>
      <div class="setup-steps connect-help">
        <p><strong>Nút Kết nối sẽ bấm được sau khi máy chủ có khoá của <?= e($ch['name']) ?>.</strong> Các bước:</p>
        <ol>
          <li><?= e($setupStep) ?></li>
          <?php if (!$isGoogle): ?><li>Khai báo địa chỉ quay về: <code><?= e($oauthCallback) ?></code></li><?php endif ?>
          <li>Thêm vào file cấu hình <code>binbin.env</code> trên Hostinger (hoặc file <code>.env</code> khi chạy thử):
            <?php foreach ($conn['missingEnv'] as $name): ?><code><?= e($name) ?></code> <?php endforeach ?>
          </li>
          <li>Tải lại trang này và bấm <?= $isGoogle ? 'Lưu và lấy số liệu' : 'Kết nối' ?>.</li>
        </ol>
        <p class="muted">Hướng dẫn chi tiết từng bước: mục 5 trong DEPLOY.md.</p>
      </div>
    <?php endif ?>
    <?php if ($conn['configured'] && !$conn['connected']): ?>
      <p class="hint connect-help"><?= e($isGoogle ? 'Mở Google Maps, tìm từng chi nhánh, bấm Chia sẻ rồi Sao chép đường liên kết. Hệ thống tự lấy điểm, số đánh giá và đánh giá mới nhất.' : $connectHelp[$ch['key']]) ?></p>
    <?php endif ?>
  <?php endif ?>
</section>

<section class="kpis" aria-label="Số liệu mới nhất">
  <?= partial('channels/_kpis', ['metrics' => $metrics]) ?>
  <?php if ($ch['key'] === 'tiktok' && isset($profile['likes'])): ?>
    <div class="kpi"><span class="kpi-label">Tổng lượt thích</span><span class="kpi-value"><?= num($profile['likes']) ?></span><span class="kpi-note"><?= num($profile['videos'] ?? 0) ?> video</span></div>
  <?php endif ?>
</section>

<div class="detail">
  <div class="stack">
    <?= partial('channels/_insight', ['insight' => $insight, 'aiConfigured' => $aiConfigured, 'canRun' => $editable, 'action' => "/channels/{$ch['key']}/analyze", 'emptyText' => 'AI tự phân tích mỗi tuần khi kênh đã có số liệu. Bạn cũng có thể chạy ngay.']) ?>

    <section class="panel">
      <div class="panel-head">
        <h2><?= e($chartMetric['label']) ?> theo thời gian</h2>
        <?php if ($series): ?><span class="muted small"><?= count($series) ?> lần ghi</span><?php endif ?>
      </div>
      <?= partial('channels/_chart', ['series' => $series, 'chartMetric' => $chartMetric, 'label' => $chartMetric['label'] . ' của ' . $ch['name'], 'emptyNote' => $conn['connected'] ? 'Hệ thống ghi số liệu mỗi ngày; biểu đồ hiện từ ngày thứ hai.' : 'Kết nối kênh để hệ thống tự ghi số liệu mỗi ngày.']) ?>
    </section>

    <?php if ($isGoogle && !empty($extra['recentReviews'])): ?>
      <section class="panel">
        <div class="panel-head"><h2>Đánh giá gần đây trên Google</h2><?php if (!empty($profile['url'])): ?><a href="<?= e($profile['url']) ?>" target="_blank" rel="noopener">Trả lời trên Google</a><?php endif ?></div>
        <?= partial('channels/_reviews', ['reviews' => $extra['recentReviews']]) ?>
      </section>
    <?php endif ?>

    <?php if (!$isGoogle): $apiPosts = array_slice($extra['posts'] ?? [], 0, 8); ?>
    <section class="panel">
      <div class="panel-head"><h2>Hiệu quả bài đăng, 30 ngày qua</h2><a href="/posts?status=published">Xem bài đã đăng</a></div>
      <dl class="perf">
        <div><dt>Bài đã đăng</dt><dd><?= num($perf['posts']) ?></dd></div>
        <div><dt>Lượt xem</dt><dd><?= num($perf['views']) ?></dd></div>
        <div><dt>Thích</dt><dd><?= num($perf['likes']) ?></dd></div>
        <div><dt>Bình luận</dt><dd><?= num($perf['comments']) ?></dd></div>
        <div><dt>Chia sẻ</dt><dd><?= num($perf['shares']) ?></dd></div>
        <div><dt>Xem trung bình mỗi bài</dt><dd><?= $perf['posts'] ? num(round($perf['views'] / $perf['posts'])) : '—' ?></dd></div>
      </dl>
      <?php if ($apiPosts): ?>
        <table class="table table-compact">
          <thead><tr><th>Bài mới nhất trên <?= e($ch['name']) ?></th><?php if ($ch['key'] === 'tiktok'): ?><th class="num">Xem</th><?php endif ?><th class="num">Thích</th><th class="num">Bình luận</th><th class="num">Chia sẻ</th></tr></thead>
          <tbody>
            <?php foreach ($apiPosts as $p): ?>
              <tr>
                <td><?php if (!empty($p['url'])): ?><a href="<?= e($p['url']) ?>" target="_blank" rel="noopener"><?= e($p['title'] ?: 'Không có tiêu đề') ?></a><?php else: ?><?= e($p['title']) ?><?php endif ?><?php if (!empty($p['createdAt'])): ?><small class="muted block"><?= D::dmy(substr((string) $p['createdAt'], 0, 10)) ?></small><?php endif ?></td>
                <?php if ($ch['key'] === 'tiktok'): ?><td class="num"><?= num($p['views']) ?></td><?php endif ?>
                <td class="num"><?= num($p['likes']) ?></td>
                <td class="num"><?= num($p['comments']) ?></td>
                <td class="num"><?= num($p['shares']) ?></td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
        <p class="hint">Bài trong lịch có link trùng với bài trên kênh sẽ được cập nhật lượt thích, bình luận, chia sẻ tự động.</p>
      <?php elseif ($posts): ?>
        <table class="table table-compact">
          <thead><tr><th>Bài gần đây</th><th class="num">Xem</th><th class="num">Tương tác</th></tr></thead>
          <tbody>
            <?php foreach ($posts as $p): ?>
              <tr>
                <td><a href="/posts/<?= $p['id'] ?>"><?= e($p['title']) ?></a><small class="muted block"><?= D::dmy($p['published_at']) ?></small></td>
                <td class="num"><?= num($p['views']) ?></td>
                <td class="num"><?= num((int) $p['likes'] + (int) $p['comments'] + (int) $p['shares']) ?></td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="empty">Chưa có bài nào. <?= $conn['connected'] ? 'Bài mới trên kênh sẽ hiện ở đây sau lần cập nhật tới.' : 'Kết nối kênh để thấy bài và tương tác.' ?></p>
      <?php endif ?>
    </section>
    <?php endif ?>

    <section class="panel panel-flush">
      <div class="panel-head pad"><h2>Lịch sử số liệu</h2></div>
      <?php if (!$stats): ?>
        <p class="empty pad">Chưa có số liệu nào.</p>
      <?php else: ?>
        <table class="table table-rows">
          <thead><tr><th>Ngày</th>
            <?php if ($isGoogle): ?><th class="num">Điểm</th><th class="num">Đánh giá</th><?php else: ?><th class="num">Theo dõi</th><?php endif ?>
            <?php if ($showReach): ?><th class="num">Tiếp cận</th><th class="num">Tương tác</th><?php endif ?>
            <th>Nguồn</th><?php if ($editable): ?><th><span class="sr-only">Xoá</span></th><?php endif ?></tr></thead>
          <tbody>
          <?php foreach (array_slice($stats, 0, 20) as $s): ?>
            <tr>
              <td class="nowrap"><?= D::dmy($s['recorded_on']) ?></td>
              <?php if ($isGoogle): ?>
                <td class="num"><?= $s['rating'] !== null ? number_format((float) $s['rating'], 1, ',', '.') . '★' : '—' ?></td>
                <td class="num"><?= $s['reviews'] !== null ? num($s['reviews']) : '—' ?></td>
              <?php else: ?>
                <td class="num"><?= $s['followers'] !== null ? num($s['followers']) : '—' ?></td>
              <?php endif ?>
              <?php if ($showReach): ?>
                <td class="num"><?= $s['reach'] !== null ? num($s['reach']) : '—' ?></td>
                <td class="num"><?= $s['engagement'] !== null ? num($s['engagement']) : '—' ?></td>
              <?php endif ?>
              <td><?= $sourceLabel[$s['source']] ?? 'Nhập tay' ?><?php if ($s['note']): ?><small class="muted block"><?= e($s['note']) ?></small><?php endif ?></td>
              <?php if ($editable): ?>
                <td class="right">
                  <form method="post" action="/channels/<?= $ch['key'] ?>/stats/<?= $s['id'] ?>/delete" data-confirm="Xoá dòng số liệu ngày <?= D::dmy($s['recorded_on']) ?>?">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button class="btn btn-small btn-quiet" type="submit">Xoá</button>
                  </form>
                </td>
              <?php endif ?>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      <?php endif ?>
    </section>
  </div>

  <aside class="stack">
    <section class="panel stack">
      <h2>Sắp đăng</h2>
      <?php if (!$upcoming): ?>
        <p class="empty">Chưa có bài nào lên lịch.</p>
      <?php else: ?>
        <ul class="plain-list">
          <?php foreach ($upcoming as $p): ?>
            <li class="up-row">
              <span class="up-when"><strong><?= D::dm($p['scheduled_at']) ?></strong><small><?= D::hm($p['scheduled_at']) ?></small></span>
              <span><a href="/posts/<?= $p['id'] ?>"><?= e($p['title']) ?></a><br><?= partial('partials/chips', ['kind' => 'status', 'value' => $p['status']]) ?></span>
            </li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
      <p class="hint"><?= e($ch['tips']) ?></p>
    </section>

    <section class="panel stack">
      <h2>Thông tin tài khoản</h2>
      <?php if ($editable): ?>
        <form method="post" action="/channels/<?= $ch['key'] ?>/account" class="stack">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <label class="field"><span>Tên hiển thị</span><input type="text" name="display_name" value="<?= e($account['display_name'] ?? '') ?>"></label>
          <?php if (!$isGoogle): ?>
            <label class="field"><span>Tên tài khoản</span><input type="text" name="handle" value="<?= e($account['handle'] ?? '') ?>" placeholder="<?= $ch['key'] === 'tiktok' ? '@binbinhotel' : '' ?>"></label>
            <label class="field"><span>Đường dẫn</span><input type="url" name="url" value="<?= e($account['url'] ?? '') ?>" placeholder="https://"></label>
          <?php else: ?>
            <input type="hidden" name="url" value="<?= e($account['url'] ?? '') ?>">
          <?php endif ?>
          <label class="field"><span>Người phụ trách</span>
            <select name="owner_id"><option value="">Chưa phân công</option>
              <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"<?= (int) ($account['owner_id'] ?? 0) === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach ?>
            </select>
          </label>
          <label class="field"><span>Ghi chú</span><textarea name="notes" rows="3" placeholder="Giờ đăng hiệu quả, quy định riêng của kênh…"><?= e($account['notes'] ?? '') ?></textarea>
            <small>Không lưu mật khẩu ở đây.</small></label>
          <button class="btn" type="submit">Lưu thông tin</button>
        </form>
      <?php else: ?>
        <dl class="kv">
          <dt>Tài khoản</dt><dd><?= e($accountName ?: '—') ?></dd>
          <?php if (!empty($account['notes'])): ?><dt>Ghi chú</dt><dd><?= e($account['notes']) ?></dd><?php endif ?>
        </dl>
      <?php endif ?>
    </section>

    <?php if ($editable): ?>
      <details class="panel manual">
        <summary>Nhập số liệu thủ công</summary>
        <p class="hint">Chỉ dùng khi kênh chưa kết nối được hoặc cần bổ sung số liệu cũ. Số liệu tự động vẫn được ghi riêng.</p>
        <form method="post" action="/channels/<?= $ch['key'] ?>/stats" class="stack">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <label class="field"><span>Ngày</span><input type="date" name="recorded_on" value="<?= $today ?>" required></label>
          <div class="row-2">
            <?php if ($isGoogle): ?>
              <label class="field"><span>Điểm đánh giá</span><input type="number" min="0" max="5" step="0.1" name="rating"></label>
              <label class="field"><span>Số đánh giá</span><input type="number" min="0" name="reviews" inputmode="numeric"></label>
            <?php else: ?>
              <label class="field field-wide"><span>Người theo dõi</span><input type="number" min="0" name="followers" inputmode="numeric"></label>
              <?php if ($showReach): ?>
                <label class="field"><span>Tiếp cận</span><input type="number" min="0" name="reach" inputmode="numeric"></label>
                <label class="field"><span>Tương tác</span><input type="number" min="0" name="engagement" inputmode="numeric"></label>
              <?php endif ?>
            <?php endif ?>
          </div>
          <label class="field"><span>Ghi chú</span><input type="text" name="note" maxlength="255"></label>
          <button class="btn" type="submit">Lưu số liệu</button>
        </form>
      </details>
    <?php endif ?>
  </aside>
</div>
<?= partial('partials/bottom') ?>
