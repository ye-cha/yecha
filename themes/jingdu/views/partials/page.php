<?php
/**
 * 静读 · 独立页面（页面走纯阅读态：无笔记工具条、无划线、无段评）
 * 入参：$post $html $translated $fallback
 */
$p  = $post;
$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';
$pt = strtotime((string)($p['updated_at'] ?? $p['published_at'] ?? $p['created_at'] ?? 'now'));

/* 页面目录：与文章页同一规则（服务端生成，无小标题则不占位） */
$tocOn = (string)theme_opt('toc', '1') !== '0';
if ($tocOn && !empty($p['toc']) && is_array($p['toc'])) {
    $items = [];
    foreach ($p['toc'] as $it) {
        $lv = (int)($it['l'] ?? 2);
        if ($lv < 2 || $lv > 4) { continue; }
        if ((string)($it['t'] ?? '') === '') { continue; }
        $items[] = $it;
    }
    if (count($items) >= 2) {
        $htmlToc = '<ol>';
        foreach ($items as $it) {
            $lv = (int)$it['l'];
            $cls = $lv === 3 ? ' class="lv3"' : ($lv === 4 ? ' class="lv4"' : '');
            $htmlToc .= '<li><a' . $cls . ' href="#' . e((string)$it['id']) . '">' . e((string)$it['t']) . '</a></li>';
        }
        $htmlToc .= '</ol>';
        $GLOBALS['jd_toc'] = $htmlToc;
    }
}
?>
<article class="article page">
  <nav class="crumbs" aria-label="breadcrumb">
    <a href="<?= e($lp === '' ? '/' : $lp . '/') ?>"><?= e(__('nav.home') ?? '首页') ?></a>
    <span class="sep">/</span>
    <span><?= e((string)$p['title']) ?></span>
  </nav>

  <?php if (!empty($fallback) && Poly::on() && Lang::short() !== Poly::defaultLang()): ?>
    <div class="poly-notice"><?= e(__('poly.fallback')) ?> · <a href="/<?= e((string)$p['slug']) ?>.html"><?= e(__('poly.view_orig')) ?></a></div>
  <?php endif; ?>

  <h1 class="article-title"><?= e((string)$p['title']) ?></h1>

  <div class="article-meta">
    <span>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5l3 2"></path><circle cx="12" cy="12" r="9"></circle></svg>
      <time datetime="<?= e(date('Y-m-d', $pt)) ?>"><?= e(__('pg.updated_at')) ?><?= e(date('Y-m-d', $pt)) ?></time>
    </span>
    <a href="<?= e($lp . '/pages') ?>" style="color:var(--muted)"><?= e(__('pg.back')) ?></a>
  </div>

  <div class="prose"><?= $html ?></div>

  <?php if (!empty($p['tags'])): ?>
  <div class="article-tags">
    <span class="tags-label"><?= e(__('ui.tags_label')) ?></span>
    <?php foreach ($p['tags'] as $t): ?>
      <span class="tag"><a href="<?= e(Poly::termUrl('tag', $t)) ?>" rel="tag"><?= e(Poly::termName($t)) ?></a></span>
    <?php endforeach; ?>
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
