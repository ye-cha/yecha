<?php
/**
 * Tales · 独立页面（$post $html）
 * 特例：slug = pinyin 的页面渲染「拼音索引」（/pinyin/），与 /tag/ 同一版式、页签互切。
 */
require_once __DIR__ . '/../../lib.php';
$p = $post;
$pSlug = (string)($p['slug'] ?? '');
$L  = (Lang::short() === 'en');
$T_ = function (string $zh, string $en) use ($L): string { return $L ? $en : $zh; };
$lp = (string)(Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : '');

if ($pSlug === 'pinyin') {
    echo '<div class="wrap">' . View::render('partials/page-pinyin', []) . '</div>';
    return;
}

$pt = strtotime((string)($p['updated_at'] ?? $p['published_at'] ?? $p['created_at']));
$GLOBALS['YC_TALES_LIST'] = true; // 页面通栏
?>
<div class="wrap">
  <nav class="crumb tales-crumb">
    <a href="<?= e($lp . '/') ?>"><?= e($T_('首页', 'Home')) ?></a><i>/</i><?= e($p['title']) ?>
  </nav>
  <article class="art art-page" style="--c:var(--acc)">
    <?php if (!empty($fallback) && Poly::on() && Lang::short() !== Poly::defaultLang()): ?>
    <div class="art-notice"><?= e(__('poly.fallback')) ?> · <a href="/<?= e($pSlug) ?>.html"><?= e(__('poly.view_orig')) ?></a></div>
    <?php endif; ?>
    <h1><?= e($p['title']) ?></h1>
    <div class="art-meta"><span><?= e($T_('更新于 ', 'Updated ')) ?><?= e(date('Y-m-d', $pt)) ?></span></div>
    <div class="art-rule"><i></i><i></i><i></i><i></i><i></i></div>
    <div class="art-body" id="articleBody"><?= $html ?></div>
    <?php if (!empty($p['tags'])): ?>
    <div class="art-tags">
      <span class="k"><?= e(__('ui.tags_label')) ?></span>
      <?php foreach ($p['tags'] as $i => $t): ?><?= $i ? '<i>·</i>' : '' ?><a href="<?= e(Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </article>
</div>
<?php if ((int)$p['id'] > 0): ?>
<script>
(function () {
  try {
    var key = 'yc_view_' + <?= (int)$p['id'] ?> + '_' + new Date().toISOString().slice(0, 10);
    if (localStorage.getItem(key)) { return; }
    var fd = new FormData();
    fd.append('content_id', String(<?= (int)$p['id'] ?>));
    fetch('/count', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) { localStorage.setItem(key, '1'); } })
      .catch(function () {});
  } catch (e) {}
})();
</script>
<?php endif; ?>
