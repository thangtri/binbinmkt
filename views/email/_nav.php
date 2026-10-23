<nav class="tabs" aria-label="Email marketing">
  <a href="/email/campaigns" class="<?= str_starts_with($path, '/email/campaigns') ? 'is-on' : '' ?>">Chiến dịch</a>
  <a href="/email/contacts" class="<?= str_starts_with($path, '/email/contacts') ? 'is-on' : '' ?>">Danh bạ</a>
</nav>
<?php if (!$brevoLive): ?>
  <div class="flash flash-warn"><strong>Chế độ thử:</strong> chưa cấu hình Brevo (BREVO_API_KEY, BREVO_SENDER_EMAIL), nên email chỉ được ghi nhận chứ không gửi thật.</div>
<?php endif ?>
