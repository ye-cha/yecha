<?php
/** kejiyuan 科技原 —— 卡片网格项（单条文章，带图标）；入参 $p,$lp,$base
 *  $p 可带 cats[]（列表/搜索页由控制器提供）或预置 cat/cat_url（首页自查）。 */
$slug = rawurlencode((string)($p['slug'] ?? ''));
$u = $base . $slug . '.html';
$dt = strtotime((string)($p['published_at'] ?? $p['created_at'] ?? 'now'));
$catName = ''; $catUrl = '';
if (isset($p['cat']) && $p['cat'] !== '') { $catName = (string)$p['cat']; $catUrl = (string)($p['cat_url'] ?? ''); }
elseif (!empty($p['cats'])) { $c0 = $p['cats'][0]; $catName = Poly::termName($c0); $catUrl = Poly::termUrl('category', $c0); }
$raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)($p['excerpt']) : (string)($p['content'] ?? '');
$exc = Str::plain(Str::plain(Str::excerpt($raw, 80)));
$ico = '📄';
$ck = (string)$catName;
if (mb_strpos($ck, '茶') !== false) $ico = '🍵';
elseif (mb_strpos($ck, '技术') !== false || mb_strpos($ck, '科技') !== false) $ico = '🔬';
elseif (mb_strpos($ck, '代码') !== false || mb_strpos($ck, '程序') !== false || mb_strpos($ck, '开发') !== false) $ico = '💻';
elseif (mb_strpos($ck, '教程') !== false || mb_strpos($ck, '指南') !== false) $ico = '📘';
elseif (mb_strpos($ck, '工具') !== false) $ico = '🛠️';
?>
<article class="kj-card">
  <div class="kj-card-ico" aria-hidden="true"><?= $ico ?></div>
  <?php if ($catName !== '' && $catUrl !== ''): ?><a class="kj-card-cat" href="<?= e($catUrl) ?>"><?= e($catName) ?></a><?php endif; ?>
  <h2 class="kj-card-t"><a href="<?= e($u) ?>"><?= e((string)($p['title'] ?? '')) ?></a></h2>
  <p class="kj-card-x"><?= e($exc) ?></p>
  <div class="kj-card-m"><time><?= e(date('Y-m-d', $dt)) ?></time><span class="kj-r"><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span></div>
</article>
