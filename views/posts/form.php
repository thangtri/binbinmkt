<?= partial('partials/top') ?>
<?php
  $isNew = empty($post['id']);
  $date = $post['scheduled_at'] ? substr($post['scheduled_at'], 0, 10) : '';
  $time = $post['scheduled_at'] ? substr($post['scheduled_at'], 11, 5) : '09:00';
  // _csrf trên đường dẫn: nếu file tải lên quá lớn, máy chủ bỏ trống phần form nhưng vẫn kiểm tra được phiên
  $action = ($isNew ? '/posts' : '/posts/' . $post['id']) . '?_csrf=' . rawurlencode($csrf);
  $status = $post['status'] ?? 'draft';
?>
<header class="page-head">
  <div>
    <p class="crumbs"><a href="/posts">Bài đăng</a><?php if (!$isNew): ?> / <a href="/posts/<?= $post['id'] ?>"><?= e($post['title']) ?></a><?php endif ?></p>
    <h1><?= $isNew ? 'Soạn bài mới' : 'Sửa bài' ?></h1>
  </div>
</header>

<?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>
<?php if (!empty($post['review_note']) && $status === 'draft'): ?>
  <div class="flash flash-warn"><strong>Góp ý khi duyệt:</strong> <?= e($post['review_note']) ?></div>
<?php endif ?>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="editor"
      data-post-form data-google-limit="<?= C::GOOGLE_LIMIT ?>">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <div class="editor-main panel">
    <?php if (!empty($aiReady)): ?>
      <details class="ai-writer" data-ai-writer data-csrf="<?= e($csrf) ?>"<?= $isNew && !$post['caption'] ? ' open' : '' ?>>
        <summary><?= icon('send') ?> Nhờ AI viết bài</summary>
        <div class="ai-writer-body">
          <label class="field">
            <span>Ý chính của bài</span>
            <textarea rows="3" data-ai-brief placeholder="Ví dụ: combo 2 đêm cuối tuần giảm 15%, tặng bữa sáng cho 2 người, áp dụng tới hết tháng 11"></textarea>
          </label>
          <div class="ai-writer-row">
            <label class="field field-inline"><span>Sắc thái</span>
              <select data-ai-tone>
                <option>Thân thiện, ấm áp</option>
                <option>Sang trọng, tinh tế</option>
                <option>Vui nhộn, bắt trend</option>
                <option>Ngắn gọn, đi thẳng vào ưu đãi</option>
              </select>
            </label>
            <button class="btn btn-primary" type="button" data-ai-run>Viết 3 phương án</button>
          </div>
          <p class="hint" data-ai-status aria-live="polite">AI dùng thông tin trong mục "Thông tin khách sạn" và sự kiện đã chọn. Mất khoảng 20–40 giây.</p>
          <div class="ai-options" data-ai-options></div>
        </div>
      </details>
    <?php endif ?>
    <label class="field">
      <span>Tiêu đề nội bộ</span>
      <input type="text" name="title" value="<?= e($post['title']) ?>" maxlength="200" required placeholder="Ví dụ: Ưu đãi phòng đôi cuối tuần">
      <small>Chỉ team thấy, dùng để nhận ra bài trên lịch.</small>
    </label>

    <div class="field">
      <span>Đăng lên</span>
      <p class="all-channels"><?php foreach (C::CHANNELS as $ch) echo partial('partials/chips', ['kind' => 'channel', 'value' => $ch['key']]) ?></p>
      <small>Cùng một nội dung cho cả 4 kênh. Sau khi đăng, dán link từng kênh để theo dõi số liệu.</small>
    </div>

    <label class="field">
      <span>Nội dung đăng</span>
      <textarea name="caption" rows="12" data-caption placeholder="Viết nội dung như khách sẽ đọc trên kênh"><?= e($post['caption']) ?></textarea>
      <small class="counter" data-counter aria-live="polite"></small>
    </label>

    <label class="field">
      <span>Hashtag</span>
      <input type="text" name="hashtags" value="<?= e($post['hashtags']) ?>" placeholder="#BinBinHotel #KhachSan">
    </label>

    <div class="field">
      <span>Ảnh và video</span>
      <?php if ($mediaList): ?>
        <input type="hidden" name="media_order" value="<?= e(implode(',', array_column($mediaList, 'id'))) ?>" data-media-order>
        <ol class="media-grid" data-media-grid>
          <?php foreach ($mediaList as $i => $m): ?>
            <li class="media-item" data-id="<?= $m['id'] ?>">
              <?php if (Media::isVideo($m)): ?><video src="/uploads/<?= e($m['path']) ?>" preload="metadata" muted></video><?php else: ?><img src="/uploads/<?= e($m['path']) ?>" alt="Ảnh số <?= $i + 1 ?>"><?php endif ?>
              <span class="media-num" data-media-num><?= $i + 1 ?></span>
              <div class="media-tools">
                <button type="button" class="icon-btn" data-move="-1" aria-label="Đưa lên trước"><?= icon('left') ?></button>
                <button type="button" class="icon-btn" data-move="1" aria-label="Đưa ra sau"><?= icon('right') ?></button>
                <label class="check media-remove"><input type="checkbox" name="remove_<?= $m['id'] ?>" value="1"> Gỡ</label>
              </div>
            </li>
          <?php endforeach ?>
        </ol>
        <small>Ảnh số 1 là ảnh đại diện. Dùng mũi tên để đổi thứ tự đăng.</small>
      <?php endif ?>
      <input type="file" name="media[]" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm" data-media-input>
      <small>Chọn được nhiều file cùng lúc, tối đa 20 file, mỗi file 50 MB. File mới được thêm vào cuối.</small>
      <div class="media-preview" data-media-preview hidden></div>
    </div>
  </div>

  <aside class="editor-side">
    <div class="panel stack">
      <h2>Lịch đăng</h2>
      <div class="row-2">
        <label class="field"><span>Ngày</span><input type="date" name="date" value="<?= e($date) ?>"></label>
        <label class="field"><span>Giờ</span><input type="time" name="time" value="<?= e($time) ?>"></label>
      </div>
      <label class="field"><span>Định dạng</span>
        <select name="format">
          <?php foreach (C::POST_FORMATS as $f): ?>
            <option<?= $post['format'] === $f ? ' selected' : '' ?>><?= e($f) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <label class="field"><span>Gắn với sự kiện</span>
        <select name="event_id">
          <option value="">Không gắn</option>
          <?php foreach ($events as $ev): ?>
            <option value="<?= $ev['id'] ?>"<?= (int) $post['event_id'] === (int) $ev['id'] ? ' selected' : '' ?>><?= e($ev['title']) ?><?= (int) $ev['recurring'] ? ' (hằng năm)' : ' (' . D::dmy($ev['start_date']) . ')' ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <label class="field"><span>Gắn với ưu đãi</span>
        <select name="offer_id">
          <option value="">Không gắn</option>
          <?php foreach ($offerList as $o): ?>
            <option value="<?= $o['id'] ?>"<?= (int) $post['offer_id'] === (int) $o['id'] ? ' selected' : '' ?>><?= e($o['title']) ?> (<?= e($o['code_prefix']) ?>)</option>
          <?php endforeach ?>
        </select>
        <small>Viết <code>{{ma}}</code> trong nội dung: khi đăng hoặc sao chép cho từng kênh, hệ thống thay bằng mã của kênh đó.</small>
      </label>
      <label class="check"><input type="checkbox" name="auto_publish" value="1"<?= (int) ($post['auto_publish'] ?? 1) ? ' checked' : '' ?>> Tự đăng lên Facebook, TikTok khi đến giờ</label>
      <p class="hint">Chỉ áp dụng cho bài đã duyệt và kênh đã kết nối. Google Maps, Zalo OA vẫn đăng tay.</p>
      <label class="field"><span>Ghi chú cho team</span><textarea name="notes" rows="3" placeholder="Ai quay, cần chuẩn bị gì…"><?= e($post['notes']) ?></textarea></label>
    </div>

    <div class="panel stack actions-panel">
      <?php if ($user['role'] === 'admin' && $status !== 'published'): ?>
        <button class="btn btn-primary btn-block" type="submit" name="intent" value="approve">Lưu và duyệt</button>
      <?php elseif (in_array($status, ['draft', 'pending'], true)): ?>
        <button class="btn btn-primary btn-block" type="submit" name="intent" value="submit">Lưu và gửi duyệt</button>
      <?php endif ?>
      <button class="btn btn-block" type="submit" name="intent" value="save"><?= $status === 'draft' ? 'Lưu nháp' : 'Lưu thay đổi' ?></button>
      <?php if ($status === 'scheduled' && $user['role'] !== 'admin'): ?><p class="hint">Bài này đã được duyệt. Sửa xong bài sẽ cần duyệt lại.</p><?php endif ?>
      <a class="btn btn-quiet btn-block" href="<?= $isNew ? '/posts' : '/posts/' . $post['id'] ?>">Huỷ</a>
    </div>
  </aside>
</form>
<?= partial('partials/bottom') ?>
