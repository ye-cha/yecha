<?php
/** 独立页面模板：$post $html（页面走阅读态；独立页也纳入三栏：左=文章目录，右=本页 Markdown 目录） */
$p = $post;
$pt = strtotime((string)($p['updated_at'] ?? $p['published_at'] ?? $p['created_at']));
/* 本页目录：给 h2~h5 加锚点并注入 YC_TOC（有 ≥1 个标题即出右栏） */
$tocItems = [];
$htmlX = preg_replace_callback('/<h([2345])([^>]*)>(.*?)<\/h\1>/is', function ($m) use (&$tocItems): string {
    $lvl = (int)$m[1]; $attrs = (string)$m[2]; $inner = (string)$m[3];
    $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    if ($text === '') { return $m[0]; }
    if (preg_match('/\bid=["\']([^"\']+)["\']/', $attrs, $mm)) { $id = $mm[1]; }
    else { $id = 'sec-' . count($tocItems); $attrs .= ' id="' . $id . '"'; }
    $tocItems[] = ['l' => $lvl, 'id' => $id, 't' => $text];
    return '<h' . $lvl . $attrs . '>' . $inner . '</h' . $lvl . '>';
}, (string)$html);
if ($tocItems !== []) { $GLOBALS['YC_TOC'] = $tocItems; }
?>
<article class="article page">
  <?php if (!empty($fallback) && Poly::on() && Lang::short() !== Poly::defaultLang()): ?>
  <div class="poly-notice"><?= e(__('poly.fallback')) ?> · <a href="/<?= e((string)$p['slug']) ?>.html"><?= e(__('poly.view_orig')) ?></a></div>
  <?php endif; ?>
  <header class="article-header">
    <h1 class="article-title"><?= e($p['title']) ?></h1>
    <?php
    $plainTxt = trim(preg_replace('/\s+/u', '', Str::cleanMd((string)($p['content'] ?? ''))) ?? '');
    $readMin = $plainTxt !== '' ? max(1, (int)ceil(mb_strlen($plainTxt) / 450)) : 1;
    ?>
    <div class="article-meta">
      <span class="meta-item">
        <svg class="meta-icon" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>
        <time class="meta-date"><?= e(lang_date($pt)) ?></time>
      </span>
      <span class="meta-item">
        <svg class="meta-icon" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
        <span class="meta-reading"><?= e(__('ui.read_min')) ?><?= (int)$readMin ?><?= e(__('ui.read_min_unit')) ?></span>
      </span>
      <?php if (!empty($p['tags'])): ?>
      <span class="meta-item meta-tags"><?php foreach ($p['tags'] as $t): ?><a href="<?= e(Poly::termUrl('tag', $t)) ?>">#<?= e(Poly::termName($t)) ?></a><?php endforeach; ?></span>
      <?php endif; ?>
    </div>
  </header>
  <div class="article-content"><?= $htmlX ?></div>
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
