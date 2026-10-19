<?php
/** 独立页面模板：$post $html（页面走阅读态；独立页也纳入三栏：左=文章目录，右=本页 Markdown 目录）
 *  ★★ P3（2026-09-18）：v2 下独立页 = **单列、无左栏、无右列**（设计 §③-1 表第 7 行 / 线框 ⑥-8）；
 *     正文走 v2 排版令牌（17px / 1.8 / max-width 38em ⇒ 每行 38 字，判据 A8），
 *     并在正文末尾补「相关入口」4 个（分类索引 / 产区地图 / 茶人 / 老吕视角）。v1 分支原样保留。 */
$p = $post;
require_once __DIR__ . '/../../inc.php';
$cwV2 = chawiki_v2();
if ($cwV2) { $GLOBALS['YC_P3_KIND'] = 'page'; }
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
  <div class="poly-notice"><?= e(__('poly.fallback')) ?> · <a href="<?= e(chawiki_entry_url((string)$p['slug'])) ?>"><?= e(__('poly.view_orig')) ?></a></div>
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
  <div class="article-content<?= $cwV2 ? ' prose-wiki' : '' ?>"<?= $cwV2 ? ' id="articleBody"' : '' ?>><?= $htmlX ?></div>
  <?php if ($cwV2): /* ★ P3：独立页的「相关入口」（设计 ⑥-8 线框最后一行） */ ?>
  <nav class="page-ways" aria-label="相关入口">
    <span class="pw-cap">相关入口</span>
    <a href="<?= e(chawiki_prefix() . '/category/') ?>">全部分类</a>
    <a href="<?= e(chawiki_prefix() . '/tag/') ?>">标签索引</a>
    <a href="<?= e(chawiki_url('map')) ?>">产区地图</a>
    <a href="<?= e(chawiki_url('learn')) ?>">学茶入门</a>
    <a href="<?= e(chawiki_prefix() . '/latest') ?>">最新词条</a>
  </nav>
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
