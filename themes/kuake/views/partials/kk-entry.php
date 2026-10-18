<?php
/** kuake 夸克 —— 密集列表项（单条文章）；入参 $p,$lp,$base
 *  $p 可带 cats[]（列表/搜索页由控制器提供）或预置 cat/cat_url（首页自查）。 */
$slug = rawurlencode((string)($p['slug'] ?? ''));
$u = $base . $slug . '.html';
$dt = strtotime((string)($p['published_at'] ?? $p['created_at'] ?? 'now'));
$catName = ''; $catUrl = '';
if (isset($p['cat']) && $p['cat'] !== '') { $catName = (string)$p['cat']; $catUrl = (string)($p['cat_url'] ?? ''); }
elseif (!empty($p['cats'])) { $c0 = $p['cats'][0]; $catName = Poly::termName($c0); $catUrl = Poly::termUrl('category', $c0); }
$raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)($p['excerpt']) : (string)($p['content'] ?? '');
$exc = Str::plain(Str::plain(Str::excerpt($raw, 96)));
?>
<article class="kk-post">
  <?php if ($catName !== '' && $catUrl !== ''): ?><a class="kk-post-cat" href="<?= e($catUrl) ?>"><?= e($catName) ?></a><?php endif; ?>
  <h2 class="kk-post-t"><a href="<?= e($u) ?>"><?= e((string)($p['title'] ?? '')) ?></a></h2>
  <p class="kk-post-x"><?= e($exc) ?></p>
  <div class="kk-post-m"><time><?= e(date('Y-m-d', $dt)) ?></time><span class="kk-r"><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span></div>
</article>
