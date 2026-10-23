<aside class="auth-art<?= $hotelPhoto ? ' has-photo' : '' ?>"<?= $hotelPhoto ? ' style="background-image:url(\'' . e($hotelPhoto) . '\')"' : '' ?>>
  <?php if (!$hotelPhoto): ?><img class="auth-monogram" src="/public/img/logo.png" alt=""><?php endif ?>
  <img class="auth-logo" src="/public/img/logo.png" alt="Bin Bin Hotel" width="88" height="88">
  <h2><?= e($heading) ?></h2>
  <p><?= e($text) ?></p>
</aside>
