<ul class="reviews">
  <?php foreach ($reviews as $r): $stars = (int) round((float) ($r['rating'] ?? 0)); ?>
    <li>
      <div class="review-head"><strong><?= e($r['author']) ?></strong><span class="stars" aria-label="<?= e($r['rating']) ?> sao"><?= str_repeat('★', $stars) ?><span class="stars-off"><?= str_repeat('★', max(0, 5 - $stars)) ?></span></span><span class="muted small"><?= e($r['when']) ?></span></div>
      <?php if ($r['text']): ?><p><?= e($r['text']) ?></p><?php endif ?>
    </li>
  <?php endforeach ?>
</ul>
<p class="hint">Google chỉ cung cấp tối đa 5 đánh giá tiêu biểu qua API.</p>
