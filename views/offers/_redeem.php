<?php // Form ghi nhận khách dùng mã. Dùng: partial('offers/_redeem', ['back' => 'offer', 'branchList' => …, 'today' => …]) ?>
<form method="post" action="/offers/redeem" class="grid-form redeem-form">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <?php if (!empty($back)): ?><input type="hidden" name="back" value="<?= e($back) ?>"><?php endif ?>
  <label class="field"><span>Mã khách đưa</span><input type="text" name="code" required autocapitalize="characters" spellcheck="false" placeholder="COMBO15FB"></label>
  <label class="field"><span>Chi nhánh</span>
    <select name="branch_id"><?php foreach ($branchList as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach ?></select>
  </label>
  <label class="field"><span>Ngày dùng</span><input type="date" name="used_on" value="<?= $today ?>"></label>
  <label class="field"><span>Tên khách</span><input type="text" name="guest"></label>
  <label class="field"><span>Số đêm</span><input type="number" min="0" name="nights"></label>
  <label class="field"><span>Doanh thu (đồng)</span><input type="number" min="0" step="1000" name="amount" placeholder="1500000"></label>
  <div class="field-wide"><button class="btn btn-primary" type="submit"><?= icon('check') ?> Ghi nhận</button></div>
</form>
