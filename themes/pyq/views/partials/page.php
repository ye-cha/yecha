<?php
/** 独立页面模板：$post $html（无笔记工具条/划线/段评，页面走阅读态） */
$p = $post;
$pt = strtotime((string)($p['updated_at'] ?? $p['published_at'] ?? $p['created_at']));
?>
<article class="article page">
  <?php if (!empty($fallback) && Poly::on() && Lang::short() !== Poly::defaultLang()): ?>
  <div class="poly-notice"><?= e(__('poly.fallback')) ?> · <a href="/<?= e((string)$p['slug']) ?>.html"><?= e(__('poly.view_orig')) ?></a></div>
  <?php endif; ?>
  <div class="kicker"><a href="/pages"><?= e(__('pg.back')) ?></a></div>
  <h1 class="article-title"><?= e($p['title']) ?></h1>
  <div class="article-meta"><span><?= e(__('pg.updated_at')) ?><?= e(date('Y-m-d', $pt)) ?></span></div>
  <div class="article-content"><?= $html ?></div>
  <?php if ($p['tags']): ?>
  <div class="article-tags"><span class="tags-label"><?= e(__('ui.tags_label')) ?></span>
    <?php foreach ($p['tags'] as $t): ?><span class="tag"><a href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a></span><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ((int)$p['id'] > 0): ?>
  <script>
  (function () {
    try {
      var key = 'yc_view_' + <?= (int)$p['id'] ?> + '_' + new Date().toISOString().slice(0, 10);
      if (localStorage.getItem(key)) { return; }
      localStorage.setItem(key, '1');
      var fd = new FormData();
      fd.append('content_id', String(<?= (int)$p['id'] ?>));
      fetch('/count', { method: 'POST', body: fd, credentials: 'same-origin' });
    } catch (e) {}
  })();
  </script>
  <?php endif; ?>
</article>
