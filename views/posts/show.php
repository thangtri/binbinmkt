<?= partial('partials/top') ?>
<?php
  $editMetrics = can('post:publish') && $post['status'] === 'published';
  $editLinks = $editMetrics || ($post['status'] === 'scheduled' && can('post:publish'));
  $steps = ['draft', 'pending', 'scheduled', 'published'];
  $at = array_search($post['status'], $steps, true);
  $fullText = implode("\n\n", array_filter([$post['caption'], $post['hashtags']], fn($s) => (string) $s !== ''));
  $hasCode = $fullText !== '' && preg_match('/\{\{\s*ma\s*\}\}/iu', $fullText);
  $captionLen = mb_strlen((string) $post['caption']);
?>
<header class="page-head">
  <div>
    <p class="crumbs"><a href="/posts">Bài đăng</a></p>
    <h1><?= e($post['title']) ?></h1>
    <p class="sub">
      <?= partial('partials/chips', ['kind' => 'status', 'value' => $post['status']]) ?>
      <?php if ($post['format']): ?><span class="muted"><?= e($post['format']) ?></span><?php endif ?>
    </p>
  </div>
  <div class="toolbar">
    <?php if ($canEdit): ?><a class="btn" href="/posts/<?= $post['id'] ?>/edit">Sửa bài</a><?php endif ?>
  </div>
</header>

<ol class="steps" aria-label="Tiến trình bài">
  <?php foreach ($steps as $i => $s): ?>
    <li class="<?= $i < $at ? 'is-done' : ($i === $at ? 'is-now' : '') ?>"<?= $i === $at ? ' aria-current="step"' : '' ?>><span><?= $i + 1 ?></span><?= e(C::STATUSES[$s]['label']) ?></li>
  <?php endforeach ?>
</ol>

<?php if ($post['review_note']): ?>
  <div class="flash <?= $post['status'] === 'draft' ? 'flash-warn' : 'flash-info' ?>">
    <strong><?= $post['status'] === 'draft' ? 'Cần sửa' : 'Ghi chú duyệt' ?><?= $post['reviewer_name'] ? ' từ ' . e($post['reviewer_name']) : '' ?>:</strong> <?= e($post['review_note']) ?>
  </div>
<?php endif ?>

<div class="detail">
  <section class="panel post-preview">
    <div class="panel-head">
      <h2>Nội dung</h2>
      <?php if ($hasCode && $offer): ?>
        <span class="copy-group">
          <?php foreach ($offer['codes'] as $c): if (!isset(C::CHANNELS[$c['channel']])) continue; ?><button class="btn btn-small" type="button" data-copy="<?= e(Offers::applyCode($fullText, $c['code'])) ?>">Sao chép cho <?= e(C::CHANNELS[$c['channel']]['name']) ?></button><?php endforeach ?>
        </span>
      <?php elseif ($fullText !== ''): ?><button class="btn btn-small" type="button" data-copy="<?= e($fullText) ?>">Sao chép để đăng</button><?php endif ?>
    </div>
    <?php if ($mediaList): ?>
      <div class="gallery<?= count($mediaList) === 1 ? ' gallery-one' : '' ?>">
        <?php foreach ($mediaList as $i => $m): ?>
          <figure>
            <?php if (Media::isVideo($m)): ?><video src="/uploads/<?= e($m['path']) ?>" controls preload="metadata"></video><?php else: ?><a href="/uploads/<?= e($m['path']) ?>" target="_blank" rel="noopener"><img src="/uploads/<?= e($m['path']) ?>" alt="Ảnh số <?= $i + 1 ?> của bài" loading="lazy"></a><?php endif ?>
            <figcaption><?= $i + 1 ?></figcaption>
          </figure>
        <?php endforeach ?>
      </div>
      <p class="gallery-actions"><a class="btn btn-small" href="/posts/<?= $post['id'] ?>/media.zip"><?= icon('upload') ?> Tải <?= count($mediaList) > 1 ? 'cả ' . count($mediaList) . ' file' : 'file' ?> (.zip)</a></p>
    <?php endif ?>
    <?php if ($post['caption']): ?><div class="caption"><?= e($post['caption']) ?></div><?php else: ?><p class="empty">Chưa có nội dung.</p><?php endif ?>
    <?php if ($post['hashtags']): ?><p class="tags"><?= e($post['hashtags']) ?></p><?php endif ?>
    <p class="muted small"><?= num($captionLen) ?> ký tự<?= $captionLen > C::GOOGLE_LIMIT ? '. Google Maps chỉ nhận ' . num(C::GOOGLE_LIMIT) . ' ký tự, nhớ rút gọn khi đăng lên Google.' : '' ?></p>
    <?php if ($offer): ?><div class="note"><strong>Ưu đãi:</strong> <a href="/offers/<?= $offer['id'] ?>"><?= e($offer['title']) ?></a>, mã gốc <code><?= e($offer['code_prefix']) ?></code></div><?php endif ?>
    <?php if ($post['notes']): ?><div class="note"><strong>Ghi chú cho team:</strong> <?= e($post['notes']) ?></div><?php endif ?>
  </section>

  <aside class="stack">
    <section class="panel">
      <dl class="kv">
        <dt>Ngày đăng</dt><dd><?= $post['scheduled_at'] ? D::weekday($post['scheduled_at'], true) . ', ' . D::dmyhm($post['scheduled_at']) : 'Chưa chọn' ?></dd>
        <?php if ($post['event_title']): ?><dt>Sự kiện</dt><dd><?= e($post['event_title']) ?></dd><?php endif ?>
        <dt>Người soạn</dt><dd><?= e($post['author_name'] ?? '') ?></dd>
        <?php if ($post['reviewer_name'] && $post['status'] !== 'draft'): ?><dt>Người duyệt</dt><dd><?= e($post['reviewer_name']) ?></dd><?php endif ?>
        <?php if ($post['published_at']): ?><dt>Đã đăng lúc</dt><dd><?= D::dmyhm($post['published_at']) ?></dd><?php endif ?>
      </dl>
    </section>

    <?php if ($post['status'] === 'draft' && can('post:submit')): ?>
      <section class="panel">
        <form method="post" action="/posts/<?= $post['id'] ?>/submit">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-primary btn-block" type="submit"<?= $post['scheduled_at'] ? '' : ' disabled' ?>>Gửi duyệt</button>
          <?php if (!$post['scheduled_at']): ?><p class="hint">Chọn ngày đăng trước khi gửi duyệt.</p><?php endif ?>
        </form>
      </section>
    <?php endif ?>

    <?php if ($post['status'] === 'pending' && can('post:approve')): ?>
      <section class="panel stack">
        <h2>Duyệt bài</h2>
        <form method="post" action="/posts/<?= $post['id'] ?>/approve" class="stack">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <label class="field"><span>Ghi chú (không bắt buộc)</span><textarea name="note" rows="2"></textarea></label>
          <button class="btn btn-primary btn-block" type="submit"><?= icon('check') ?> Duyệt bài</button>
        </form>
        <form method="post" action="/posts/<?= $post['id'] ?>/reject" class="stack">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <label class="field"><span>Lý do trả về</span><textarea name="note" rows="2" required placeholder="Cần sửa gì để duyệt được?"></textarea></label>
          <button class="btn btn-block" type="submit">Trả về nháp</button>
        </form>
      </section>
    <?php endif ?>

    <?php if (can('post:create')): ?>
      <section class="panel stack">
        <h2>Dùng lại bài này</h2>
        <form method="post" action="/posts/<?= $post['id'] ?>/duplicate">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-block" type="submit">Nhân bản thành bài nháp mới</button>
        </form>
      </section>
    <?php endif ?>

    <?php if ($canDelete): ?>
      <form method="post" action="/posts/<?= $post['id'] ?>/delete" data-confirm="Xoá bài “<?= e($post['title']) ?>”? Không khôi phục được.">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn btn-danger-quiet" type="submit">Xoá bài</button>
      </form>
    <?php endif ?>
  </aside>
</div>

<section class="panel panel-flush post-channels">
  <div class="panel-head pad">
    <h2>Trên các kênh</h2>
    <span class="muted small"><?= $post['status'] === 'published' ? 'Tổng: ' . num($post['views']) . ' lượt xem, ' . num((int) $post['likes'] + (int) $post['comments'] + (int) $post['shares']) . ' tương tác' : 'Cùng nội dung, đăng lên cả 4 kênh' ?></span>
  </div>
  <form method="post" action="/posts/<?= $post['id'] ?>/<?= $post['status'] === 'scheduled' ? 'publish' : 'metrics' ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <table class="table table-rows">
      <thead><tr><th>Kênh</th><th>Đăng</th><th>Link bài</th><th class="num">Xem</th><th class="num">Thích</th><th class="num">Bình luận</th><th class="num">Chia sẻ</th></tr></thead>
      <tbody>
        <?php foreach ($channels as $c): $meta = C::CHANNELS[$c['channel']]; $st = (string) ($c['publish_status'] ?? ''); $auto = in_array($c['channel'], $autoChannels, true); ?>
          <tr>
            <td><?= partial('partials/chips', ['kind' => 'channel', 'value' => $c['channel']]) ?></td>
            <td class="pub-state">
              <?php if ($st === 'done'): ?><span class="pub pub-done">Đã tự đăng</span>
              <?php elseif (str_starts_with($st, 'processing')): ?><span class="pub pub-wait">TikTok đang xử lý</span>
              <?php elseif ($st === 'publishing'): ?><span class="pub pub-wait">Đang đăng…</span>
              <?php elseif ($st === 'failed'): ?><span class="pub pub-fail">Lỗi</span>
                <?php if (can('post:publish') && $post['status'] === 'scheduled'): ?><button class="btn btn-small btn-quiet" type="submit" formaction="/posts/<?= $post['id'] ?>/retry/<?= $c['channel'] ?>" formnovalidate>Thử lại</button><?php endif ?>
              <?php elseif (!empty($c['published_url'])): ?><span class="pub pub-done">Đã đăng tay</span>
              <?php elseif ($auto && (int) $post['auto_publish']): ?><span class="pub">Tự đăng lúc <?= $post['scheduled_at'] ? D::hm($post['scheduled_at']) : '' ?></span>
              <?php elseif ($c['channel'] === 'google' && count($googleLinks) > 1): ?><span class="pub">Đăng tay, <?= count(array_filter($googleLinks, fn($l) => $l['published_url'])) ?>/<?= count($googleLinks) ?> chi nhánh</span>
              <?php else: ?><span class="pub">Đăng tay</span><?php endif ?>
              <?php if (!empty($c['publish_error'])): ?><small class="block <?= $st === 'failed' ? 'down' : 'muted' ?>"><?= e($c['publish_error']) ?></small><?php endif ?>
            </td>
            <?php if ($c['channel'] === 'google'): ?>
              <td class="google-links">
                <?php if (!$googleLinks): ?><span class="muted">Chưa có chi nhánh</span><?php endif ?>
                <?php foreach ($googleLinks as $l): ?>
                  <div class="google-link">
                    <span class="google-branch"><?= e($l['name']) ?></span>
                    <?php if ($editLinks): ?>
                      <label class="sr-only" for="url-google-<?= $l['branch_id'] ?>">Link Google Maps của <?= e($l['name']) ?></label>
                      <input id="url-google-<?= $l['branch_id'] ?>" class="input-sm" type="url" name="url_google_<?= $l['branch_id'] ?>" value="<?= e($l['published_url'] ?? '') ?>" placeholder="Dán link bài trên Google Maps">
                    <?php elseif ($l['published_url']): ?><a href="<?= e($l['published_url']) ?>" target="_blank" rel="noopener">Mở bài</a>
                    <?php else: ?><span class="muted">Chưa đăng</span><?php endif ?>
                  </div>
                <?php endforeach ?>
              </td>
            <?php elseif ($editLinks): ?>
              <td><label class="sr-only" for="url-<?= $c['channel'] ?>">Link trên <?= e($meta['name']) ?></label><input id="url-<?= $c['channel'] ?>" class="input-sm" type="url" name="url_<?= $c['channel'] ?>" value="<?= e($c['published_url'] ?? '') ?>" placeholder="Dán link bài trên <?= e($meta['name']) ?>"></td>
            <?php else: ?>
              <td><?php if (!empty($c['published_url'])): ?><a href="<?= e($c['published_url']) ?>" target="_blank" rel="noopener">Mở bài</a><?php else: ?><span class="muted">Chưa có link</span><?php endif ?></td>
            <?php endif ?>
            <?php foreach (['views', 'likes', 'comments', 'shares'] as $m): $v = $c[$m] ?? null; ?>
              <td class="num">
                <?php if ($editMetrics): ?><label class="sr-only" for="<?= $m ?>-<?= $c['channel'] ?>"><?= $m ?> <?= e($meta['name']) ?></label><input id="<?= $m ?>-<?= $c['channel'] ?>" class="input-num" type="number" min="0" name="<?= $m ?>_<?= $c['channel'] ?>" value="<?= $v === null ? '' : (int) $v ?>"><?php else: ?><?= $v === null ? '—' : num($v) ?><?php endif ?>
              </td>
            <?php endforeach ?>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
    <?php if ($post['status'] === 'scheduled' && can('post:publish')): ?>
      <div class="pad post-channels-foot">
        <button class="btn" type="submit" formaction="/posts/<?= $post['id'] ?>/publish-now" data-busy="Đang đăng…" formnovalidate><?= icon('send') ?> Đăng ngay lên Facebook, TikTok</button>
        <button class="btn btn-primary" type="submit"><?= icon('check') ?> Đánh dấu đã đăng</button>
        <span class="hint">Link có thể dán sau. Với Facebook và TikTok đã kết nối, lượt thích, bình luận, chia sẻ tự cập nhật theo link.</span>
      </div>
    <?php elseif ($editMetrics): ?>
      <div class="pad post-channels-foot">
        <button class="btn" type="submit">Lưu link và số liệu</button>
        <span class="hint">Kênh đã kết nối tự cập nhật số liệu theo link; ô nào để trống thì bỏ qua.</span>
      </div>
    <?php endif ?>
  </form>
</section>
<?= partial('partials/bottom') ?>
