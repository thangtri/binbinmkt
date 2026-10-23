<?= partial('partials/top') ?>
<header class="page-head">
  <div><p class="crumbs"><a href="/channels/facebook">Facebook</a></p><h1>Chọn Page của khách sạn</h1>
  <p class="sub">Tài khoản Facebook vừa đăng nhập đang quản lý nhiều Page. Chọn Page cần theo dõi số liệu và tự đăng bài.</p></div>
</header>
<form method="post" action="/channels/facebook/choose-page" class="panel stack narrow">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <input type="hidden" name="k" value="<?= e($k) ?>">
  <?php foreach ($pages as $i => $p): ?>
    <label class="check"><input type="radio" name="page_id" value="<?= e($p['id']) ?>"<?= $i === 0 ? ' checked' : '' ?> required> <span><strong><?= e($p['name']) ?></strong></span></label>
  <?php endforeach ?>
  <div class="toolbar"><button class="btn btn-primary" type="submit">Kết nối Page này</button><a class="btn btn-quiet" href="/channels/facebook">Huỷ</a></div>
</form>
<?= partial('partials/bottom') ?>
