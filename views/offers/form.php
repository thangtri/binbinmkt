<?= partial('partials/top') ?>
<?php $isNew = empty($o['id']); $chosen = array_map('intval', array_filter(explode(',', (string) ($o['branch_ids'] ?? '')))); ?>
<header class="page-head">
  <div><p class="crumbs"><a href="/offers">Ưu đãi</a></p><h1><?= $isNew ? 'Thêm ưu đãi' : 'Sửa ưu đãi' ?></h1></div>
</header>
<?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>

<form method="post" action="<?= $isNew ? '/offers' : '/offers/' . $o['id'] ?>" class="panel settings-form">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <div class="settings-grid">
    <label class="field"><span>Tên ưu đãi</span><input type="text" name="title" value="<?= e($o['title']) ?>" required placeholder="Combo 2 đêm cuối tuần"></label>
    <label class="field"><span>Mã gốc</span><input type="text" name="code_prefix" value="<?= e($o['code_prefix']) ?>" required maxlength="14" placeholder="COMBO15" autocapitalize="characters">
      <small>Hệ thống tạo mã cho từng kênh: thêm FB, TT, GG, ZL, EM vào cuối. Mã gốc dùng cho khách đặt tại quầy.</small></label>
    <label class="field field-wide"><span>Tóm tắt một dòng</span><input type="text" name="summary" value="<?= e($o['summary'] ?? '') ?>" placeholder="Giảm 15% và tặng bữa sáng cho 2 người"></label>
    <label class="field"><span>Từ ngày</span><input type="date" name="start_date" value="<?= e($o['start_date']) ?>" required></label>
    <label class="field"><span>Đến ngày</span><input type="date" name="end_date" value="<?= e($o['end_date']) ?>" required></label>
    <label class="field field-wide"><span>Chi tiết ưu đãi</span><textarea name="details" rows="4" placeholder="Áp dụng phòng Deluxe, tối thiểu 2 đêm, nhận phòng thứ Sáu hoặc thứ Bảy…"><?= e($o['details'] ?? '') ?></textarea>
      <small>AI dựa vào phần này khi viết bài, nên ghi đúng giá và điều kiện.</small></label>
    <label class="field field-wide"><span>Điều kiện, lưu ý</span><textarea name="terms" rows="3" placeholder="Không áp dụng cùng ưu đãi khác. Số lượng có hạn."><?= e($o['terms'] ?? '') ?></textarea></label>
    <div class="field field-wide">
      <span>Áp dụng tại</span>
      <div class="check-list">
        <?php foreach ($branchList as $b): ?><label class="check"><input type="checkbox" name="branch_ids[]" value="<?= $b['id'] ?>"<?= in_array($b['id'], $chosen, true) ? ' checked' : '' ?>> <?= e($b['name']) ?></label><?php endforeach ?>
      </div>
      <small>Không chọn chi nhánh nào nghĩa là áp dụng cả chuỗi.</small>
    </div>
    <?php if (!$isNew): ?>
      <input type="hidden" name="has_active" value="1">
      <label class="check"><input type="checkbox" name="active" value="1"<?= (int) $o['active'] ? ' checked' : '' ?>> Đang áp dụng</label>
    <?php endif ?>
  </div>
  <div class="toolbar"><button class="btn btn-primary" type="submit"><?= $isNew ? 'Tạo ưu đãi' : 'Lưu' ?></button><a class="btn btn-quiet" href="<?= $isNew ? '/offers' : '/offers/' . $o['id'] ?>">Huỷ</a></div>
</form>
<?= partial('partials/bottom') ?>
