<?php
/** 独立页面模板：$post $html（帮助主题皮肤 · hero 标题 + 正文 + 右章节目录，去 astra 残留类名）
 *  与 post.php 同构，但不含收藏/划线/段评/上下篇。 */
$p = $post;
$cid = (int)($p['id'] ?? 0);
$pt = strtotime((string)($p['updated_at'] ?? $p['published_at'] ?? $p['created_at']));
$lp2 = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? ui_lp() : '';

/* 本页 Markdown 目录：解析正文 h2~h4 并补锚点 id */
$tocItems = [];
$htmlX = preg_replace_callback('/<h([234])([^>]*)>(.*?)<\/h\1>/is', function ($m) use (&$tocItems): string {
    $lvl = (int)$m[1]; $attrs = (string)$m[2]; $inner = (string)$m[3];
    $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    if ($text === '') { return $m[0]; }
    if (preg_match('/\bid=["\']([^"\']+)["\']/', $attrs, $mm)) { $id = $mm[1]; }
    else { $id = 'sec-' . count($tocItems); $attrs .= ' id="' . $id . '"'; }
    $tocItems[] = ['l' => $lvl, 'id' => $id, 't' => $text];
    return '<h' . $lvl . $attrs . '>' . $inner . '</h' . $lvl . '>';
}, (string)$html);
$showToc = theme_opt('show_toc', '1') !== '0' && $tocItems !== [];
?>
<article class="suj-document-container clearfix" data-cid="<?= $cid ?>">

  <header class="suj-hero" id="suj-document-hero">
    <div class="suj-hero-body">
      <div class="suj-kicker"><a href="<?= e($lp2 . '/pages') ?>"><?= e(__('pg.back')) ?></a></div>
      <h1 class="suj-hero-title"><?= e($p['title']) ?></h1>
      <?php if (!empty($fallback) && Poly::on() && Lang::short() !== Poly::defaultLang()): ?>
      <p class="suj-poly-notice"><?= e(__('poly.fallback')) ?> · <a href="/<?= e((string)$p['slug']) ?>.html"><?= e(__('poly.view_orig')) ?></a></p>
      <?php endif; ?>
      <div class="suj-hero-meta"><span class="suj-date"><?= e(__('pg.updated_at')) ?><?= e(date('Y-m-d', $pt)) ?></span></div>
    </div>
  </header>

  <div class="suj-columns">
    <div class="suj-doc-wrapper<?= $showToc ? ' has-toc' : '' ?>">
      <div class="suj-content" id="articleBody"><?= $htmlX ?></div>

      <?php if (!empty($p['tags'])): ?>
      <div class="suj-tags">
        <span class="suj-tags-label"><?= e(__('ui.tags_label')) ?></span>
        <?php foreach ($p['tags'] as $t): ?>
          <a class="suj-tag-chip" href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($showToc): ?>
    <aside class="suj-doc-toc" id="sujDocToc" aria-label="<?= e(__('post.toc')) ?>">
      <div class="suj-doc-toc-in">
        <p class="suj-doc-toc-title"><?= e(__('post.toc')) ?></p>
        <ul>
          <?php foreach ($tocItems as $it): $lv = (int)$it['l']; ?>
          <li class="l<?= $lv ?>"><a href="#<?= e((string)$it['id']) ?>"><?= e((string)$it['t']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </aside>
    <?php endif; ?>
  </div>
</article>

<?php if ($cid > 0): ?>
<script>
(function () {
  try {
    var key = 'yc_view_' + <?= $cid ?> + '_' + new Date().toISOString().slice(0, 10);
    if (localStorage.getItem(key)) { return; }
    localStorage.setItem(key, '1');
    var fd = new FormData();
    fd.append('content_id', String(<?= $cid ?>));
    fetch('/count', { method: 'POST', body: fd, credentials: 'same-origin' });
  } catch (e) {}
})();
</script>
<?php endif; ?>
