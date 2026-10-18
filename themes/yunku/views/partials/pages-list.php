<?php
/** 独立页面列表：$pages $page_title $sub */
?>
<div class="page-head"><h1><?= e($page_title) ?></h1><span class="sub"><?= e($sub) ?></span></div>
<?php if ($pages): ?>
<div class="post-list" id="viewPages">
  <?php foreach ($pages as $pg): ?>
  <article class="post-item">
    <h2><a href="/<?= e((string)$pg['slug']) ?>.html"><?= e($pg['title']) ?></a></h2>
    <p class="post-excerpt"><?= e(Str::excerpt((string)($pg['excerpt'] !== '' ? $pg['excerpt'] : $pg['description']), 140)) ?></p>
    <div class="post-meta"><span><?= e(__('pg.updated')) ?><?= e(substr((string)$pg['updated_at'], 0, 10)) ?></span>
      <a class="readmore" href="/<?= e((string)$pg['slug']) ?>.html"><?= e(__('ui.read')) ?></a></div>
  </article>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="uc-empty"><?= e(__('pg.empty')) ?></div>
<?php endif; ?>
