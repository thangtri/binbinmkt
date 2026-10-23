<?= partial('partials/top') ?>
<header class="page-head">
  <div>
    <h1>Thông tin khách sạn</h1>
    <p class="sub">AI đọc phần này mỗi khi viết bài và gợi ý ý tưởng, nên càng cụ thể bài viết càng đúng.</p>
  </div>
</header>

<form method="post" action="/settings" class="panel settings-form">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <div class="settings-grid">
    <?php foreach ($fields as $f): $long = !empty($f['long']); ?>
      <label class="field<?= $long ? ' field-wide' : '' ?>">
        <span><?= e($f['label']) ?></span>
        <?php if ($long): ?>
          <textarea name="<?= $f['key'] ?>" rows="4"><?= e($values[$f['key']]) ?></textarea>
        <?php else: ?>
          <input type="text" name="<?= $f['key'] ?>" value="<?= e($values[$f['key']]) ?>">
        <?php endif ?>
        <?php if (!empty($f['hint'])): ?><small><?= e($f['hint']) ?></small><?php endif ?>
      </label>
    <?php endforeach ?>
  </div>
  <div><button class="btn btn-primary" type="submit">Lưu thông tin</button></div>
</form>

<section class="panel branches-panel" id="chi-nhanh">
  <div class="panel-head">
    <h2>Chi nhánh</h2>
    <span class="muted small"><?= count(array_filter($branchList, fn($b) => (int) $b['active'])) ?> chi nhánh đang hoạt động</span>
  </div>
  <p class="hint">Facebook, TikTok, Zalo OA dùng chung cho cả chuỗi. Mỗi chi nhánh có địa điểm Google Maps riêng: sau khi thêm chi nhánh, vào Kênh → Google Maps để dán link.</p>
  <table class="table table-rows">
    <thead><tr><th>Chi nhánh</th><th>Hotline</th><th>Google Maps</th><th><span class="sr-only">Sửa</span></th></tr></thead>
    <tbody>
      <?php foreach ($branchList as $b): $active = (int) $b['active']; ?>
        <tr class="<?= $active ? '' : 'is-muted' ?>">
          <td><strong><?= e($b['name']) ?></strong><?= $active ? '' : ' (ngừng hoạt động)' ?><?php if ($b['address']): ?><small class="muted block"><?= e($b['address']) ?></small><?php endif ?></td>
          <td class="nowrap"><?= e($b['hotline'] ?? '') ?></td>
          <td><?= $b['place_id'] ? 'Đang theo dõi' : 'Chưa có link' ?></td>
          <td class="right">
            <details class="row-edit">
              <summary class="btn btn-small btn-quiet">Sửa</summary>
              <div class="row-edit-body">
                <form method="post" action="/settings/branches/<?= $b['id'] ?>" class="stack">
                  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                  <label class="field"><span>Tên chi nhánh</span><input type="text" name="name" value="<?= e($b['name']) ?>" required></label>
                  <label class="field"><span>Địa chỉ</span><input type="text" name="address" value="<?= e($b['address'] ?? '') ?>"></label>
                  <label class="field"><span>Hotline</span><input type="text" name="hotline" value="<?= e($b['hotline'] ?? '') ?>"></label>
                  <label class="field"><span>Zalo</span><input type="text" name="zalo" value="<?= e($b['zalo'] ?? '') ?>"></label>
                  <label class="field"><span>Thứ tự hiển thị</span><input type="number" name="position" value="<?= (int) $b['position'] ?>"></label>
                  <label class="check"><input type="checkbox" name="active" value="1"<?= $active ? ' checked' : '' ?>> Đang hoạt động</label>
                  <button class="btn btn-small" type="submit">Lưu</button>
                </form>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
  <form method="post" action="/settings/branches" class="grid-form branch-add">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label class="field"><span>Tên chi nhánh mới</span><input type="text" name="name" required placeholder="Ví dụ: Bin Bin 17 Hotel"></label>
    <label class="field"><span>Địa chỉ</span><input type="text" name="address"></label>
    <label class="field"><span>Hotline</span><input type="text" name="hotline"></label>
    <label class="field"><span>Zalo</span><input type="text" name="zalo"></label>
    <div class="field-wide"><button class="btn btn-primary" type="submit"><?= icon('plus') ?> Thêm chi nhánh</button></div>
  </form>
</section>
<?= partial('partials/bottom') ?>
