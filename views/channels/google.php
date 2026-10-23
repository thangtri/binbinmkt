<?= partial('partials/top') ?>
<?php
  $editable = can('channel:edit');
  $delta = function ($b, $key, $dec = 0) {
      if (!$b['latest'] || !$b['previous'] || $b['latest'][$key] === null || $b['previous'][$key] === null) return null;
      return round((float) $b['latest'][$key] - (float) $b['previous'][$key], $dec);
  };
  $deltaText = fn($v, $dec = 0) => $v ? '<small class="block ' . ($v > 0 ? 'up' : 'down') . '">' . ($v > 0 ? '+' : '') . ($dec ? number_format($v, $dec, ',', '.') : num($v)) . '</small>' : '';
  $rating = fn($t) => isset($t['latest']['rating']) && $t['latest']['rating'] !== null ? number_format((float) $t['latest']['rating'], 1, ',', '.') . '★' : '—';
  $reviewsN = fn($t) => isset($t['latest']['reviews']) && $t['latest']['reviews'] !== null ? num($t['latest']['reviews']) : '—';
  $growth = fn($t) => $t['growth30'] === null ? '—' : ($t['growth30'] > 0 ? '+' : '') . num($t['growth30']);
?>
<header class="page-head">
  <div>
    <p class="crumbs"><a href="/channels">Kênh</a></p>
    <div class="ch-title ch-google">
      <span class="cc-code cc-code-lg"><?= $ch['code'] ?></span>
      <div>
        <h1>Google Maps</h1>
        <p class="sub">Mỗi chi nhánh một địa điểm riêng. Số liệu tự cập nhật mỗi 12 giờ.
          <span class="muted"><?= !empty($account['owner_name']) ? 'Phụ trách: ' . e($account['owner_name']) : '' ?></span></p>
      </div>
    </div>
  </div>
  <?php if ($editable && $conn['configured'] && $chain['tracked']): ?>
    <form method="post" action="/channels/google/sync">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <button class="btn" type="submit" data-busy="Đang cập nhật…">Cập nhật ngay</button>
    </form>
  <?php endif ?>
</header>

<?php if (!$conn['configured']): ?>
  <section class="connect panel is-off">
    <div class="setup-steps connect-help">
      <p><strong>Máy chủ chưa có khoá Google Places API.</strong> Quản trị viên thêm <code>GOOGLE_PLACES_API_KEY</code> vào file cấu hình <code>binbin.env</code> (xem mục 5 trong DEPLOY.md), tải lại trang rồi dán link Google Maps cho từng chi nhánh bên dưới.</p>
    </div>
  </section>
<?php elseif ($conn['error']): ?>
  <div class="flash flash-warn"><strong>Lần cập nhật gần nhất có lỗi:</strong> <?= e($conn['error']) ?></div>
<?php endif ?>

<section class="kpis" aria-label="Tổng cả chuỗi">
  <div class="kpi"><span class="kpi-label">Điểm trung bình cả chuỗi</span><span class="kpi-value"><?= $chain['rating'] !== null ? number_format($chain['rating'], 1, ',', '.') . '★' : '—' ?></span><span class="kpi-note">tính theo số đánh giá từng chi nhánh</span></div>
  <div class="kpi"><span class="kpi-label">Tổng số đánh giá</span><span class="kpi-value"><?= num($chain['reviews']) ?></span><span class="kpi-note">cộng tất cả chi nhánh</span></div>
  <div class="kpi"><span class="kpi-label">Chi nhánh đang theo dõi</span><span class="kpi-value"><?= $chain['tracked'] ?>/<?= $chain['branches'] ?></span><span class="kpi-note"><?= $chain['tracked'] < $chain['branches'] ? 'còn chi nhánh chưa dán link' : 'đã đủ' ?></span></div>
</section>

<section class="panel panel-flush">
  <div class="panel-head pad">
    <h2>Các chi nhánh</h2>
    <?php if ($user['role'] === 'admin'): ?><a href="/settings#chi-nhanh">Thêm, sửa chi nhánh</a><?php endif ?>
  </div>
  <?php if (!$snap): ?>
    <p class="empty pad">Chưa có chi nhánh nào. Quản trị viên thêm chi nhánh trong mục Thông tin khách sạn.</p>
  <?php else: ?>
    <table class="table table-rows branch-table">
      <thead><tr><th>Chi nhánh</th><th class="num">Điểm</th><th class="num">Đánh giá</th><th>Cập nhật</th><th>Link Google Maps</th></tr></thead>
      <tbody>
        <?php foreach ($snap as $b): $prof = $b['extra']['profile'] ?? []; ?>
          <tr>
            <td>
              <a class="row-title" href="/channels/google/branch/<?= $b['id'] ?>"><?= e($b['name']) ?></a>
              <?php if ($b['address']): ?><small class="muted block"><?= e($b['address']) ?></small><?php endif ?>
            </td>
            <td class="num"><?= $rating($b) ?><?= $deltaText($delta($b, 'rating', 1), 1) ?></td>
            <td class="num"><?= $reviewsN($b) ?><?= $deltaText($delta($b, 'reviews')) ?></td>
            <td>
              <?php if (!$b['place_id']): ?><span class="pub">Chưa theo dõi</span>
              <?php elseif ($b['sync_status'] === 'error'): ?><span class="pub pub-fail">Lỗi</span><small class="block down"><?= e($b['sync_error']) ?></small>
              <?php elseif ($b['last_synced_at']): ?><span class="pub pub-done">Tự động</span><small class="block muted"><?= D::dmyhm($b['last_synced_at']) ?></small>
              <?php else: ?><span class="pub pub-wait">Chờ cập nhật</span><?php endif ?>
            </td>
            <td>
              <?php if ($editable && $conn['configured']): ?>
                <form method="post" action="/channels/google/link" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="branch_id" value="<?= $b['id'] ?>">
                  <label class="sr-only" for="link-<?= $b['id'] ?>">Link Google Maps của <?= e($b['name']) ?></label>
                  <input id="link-<?= $b['id'] ?>" class="input-sm" type="url" name="link" required value="<?= e($b['maps_url'] ?? '') ?>" placeholder="https://maps.app.goo.gl/…">
                  <button class="btn btn-small" type="submit" data-busy="Đang lấy…"><?= $b['place_id'] ? 'Đổi' : 'Lưu' ?></button>
                </form>
                <?php if (!empty($prof['address'])): ?><small class="muted block">Đang theo dõi: <?= e($prof['name']) ?>, <?= e($prof['address']) ?></small><?php endif ?>
              <?php elseif ($b['maps_url']): ?><a href="<?= e($b['maps_url']) ?>" target="_blank" rel="noopener">Mở trên Google Maps</a><?php else: ?><span class="muted">Chưa có</span><?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
    <p class="hint pad">Lấy link: mở Google Maps, tìm đúng chi nhánh, bấm Chia sẻ, Sao chép đường liên kết. Bấm tên chi nhánh để xem biểu đồ và đánh giá gần đây.</p>
  <?php endif ?>
</section>

<section class="panel panel-flush" id="doi-thu">
  <div class="panel-head pad">
    <h2>So sánh với đối thủ</h2>
    <span class="muted small">Điểm và số đánh giá cập nhật cùng lúc với chi nhánh</span>
  </div>
  <table class="table table-rows rival-table">
    <thead><tr><th>Khách sạn</th><th class="num">Điểm</th><th class="num">Đánh giá</th><th class="num">Đánh giá mới 30 ngày</th><th><span class="sr-only">Bỏ</span></th></tr></thead>
    <?php foreach ($comparison as $g): $ownR = $g['own']['latest']['rating'] ?? null; ?>
      <tbody>
        <tr class="rival-own">
          <td><strong><?= e($g['branch']['name']) ?></strong> <span class="tag">Bin Bin</span></td>
          <td class="num"><strong><?= $rating($g['own']) ?></strong></td>
          <td class="num"><?= $reviewsN($g['own']) ?></td>
          <td class="num"><?= $growth($g['own']) ?></td>
          <td></td>
        </tr>
        <?php foreach ($g['rivals'] as $r): $rivalR = $r['latest']['rating'] ?? null; $ahead = $ownR !== null && $rivalR !== null && (float) $rivalR > (float) $ownR; ?>
          <tr>
            <td class="rival-name"><?php if ($r['maps_url']): ?><a href="<?= e($r['maps_url']) ?>" target="_blank" rel="noopener"><?= e($r['name']) ?></a><?php else: ?><?= e($r['name']) ?><?php endif ?>
              <?php if ($r['sync_status'] === 'error'): ?><small class="block down"><?= e($r['sync_error']) ?></small><?php endif ?></td>
            <td class="num<?= $ahead ? ' down' : '' ?>"><?= $rating($r) ?></td>
            <td class="num"><?= $reviewsN($r) ?></td>
            <td class="num"><?= $growth($r) ?></td>
            <td class="right">
              <?php if ($editable): ?><form method="post" action="/channels/google/competitors/<?= $r['id'] ?>/delete" data-confirm="Bỏ theo dõi <?= e($r['name']) ?>?"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-small btn-quiet" type="submit">Bỏ</button></form><?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
        <?php if (!$g['rivals']): ?><tr><td colspan="5" class="muted small rival-name">Chưa có đối thủ nào cho chi nhánh này.</td></tr><?php endif ?>
      </tbody>
    <?php endforeach ?>
  </table>
  <?php if ($editable && $conn['configured'] && $snap): ?>
    <form method="post" action="/channels/google/competitors" class="inline-form pad rival-add">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label class="sr-only" for="rival-branch">So với chi nhánh</label>
      <select id="rival-branch" name="branch_id"><?php foreach ($snap as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach ?></select>
      <label class="sr-only" for="rival-link">Link Google Maps của đối thủ</label>
      <input id="rival-link" class="input-sm" type="url" name="link" required placeholder="Dán link Google Maps của khách sạn đối thủ">
      <button class="btn btn-small" type="submit" data-busy="Đang lấy…"><?= icon('plus') ?> Thêm đối thủ</button>
    </form>
  <?php endif ?>
  <p class="hint pad">Số đỏ là đối thủ đang có điểm cao hơn chi nhánh. Nên theo dõi 2–4 khách sạn cùng phân khúc gần mỗi chi nhánh.</p>
</section>

<div class="detail">
  <?= partial('channels/_insight', ['insight' => $insight, 'aiConfigured' => $aiConfigured, 'canRun' => $editable, 'action' => '/channels/google/analyze', 'emptyText' => 'AI so sánh điểm, số đánh giá và nội dung đánh giá giữa các chi nhánh, mỗi tuần một lần.']) ?>

  <aside class="stack">
    <section class="panel stack">
      <h2>Phụ trách kênh</h2>
      <?php if ($editable): ?>
        <form method="post" action="/channels/google/account" class="stack">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="display_name" value="<?= e($account['display_name'] ?? '') ?>">
          <label class="field"><span>Người phụ trách</span>
            <select name="owner_id"><option value="">Chưa phân công</option>
              <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"<?= (int) ($account['owner_id'] ?? 0) === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach ?>
            </select>
          </label>
          <label class="field"><span>Ghi chú</span><textarea name="notes" rows="3" placeholder="Quy định trả lời đánh giá, ai trực chi nhánh nào…"><?= e($account['notes'] ?? '') ?></textarea></label>
          <button class="btn" type="submit">Lưu</button>
        </form>
      <?php else: ?>
        <p><?= e($account['owner_name'] ?? '' ?: 'Chưa phân công') ?></p>
      <?php endif ?>
      <p class="hint"><?= e($ch['tips']) ?></p>
    </section>
  </aside>
</div>
<?= partial('partials/bottom') ?>
