<?php
/**
 * Tales · 列表（分类 / 标签 / 归档 / 搜索 / 首页文章流）
 * 变量：$posts, $page_title, $sub, $page, $total_pages, $pager_base, $empty, $link_prefix
 * 特点：字牌颜色＝**该篇所属大类**（不再按序号轮转）；分类页顶部有八大类切换条。
 */
require_once __DIR__ . '/../../lib.php';

$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$L  = (Lang::short() === 'en');
$T_ = function (string $zh, string $en) use ($L): string { return $L ? $en : $zh; };
$GLOBALS['YC_TALES_LIST'] = true;

/* 当前分类（用于切换条高亮） */
$curCat = '';
if (preg_match('#/category/([^/]+)/?#', (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), $m)) {
    $curCat = (string)$m[1];
}
$cats = tales_cats($L);
?>
<div class="page-head tales-head">
  <div class="kicker kicker-acc"><?= e($curCat !== '' ? $T_('栏目 · CATEGORY', 'CATEGORY') : $T_('故事图录 · INDEX', 'STORY INDEX')) ?></div>
  <h1><?= e(isset($page_title) && $page_title !== '' ? (string)$page_title : $T_('所有故事', 'All stories')) ?></h1>
  <?php if (!empty($sub)): ?><p class="lead"><?= e(trim(str_replace(array('· 首页', '· Home'), '', strip_tags((string)$sub)))) ?></p><?php endif; ?>
</div>

<?php if ($cats): ?>
<nav class="catbar" aria-label="<?= e($T_('分类切换', 'Categories')) ?>">
  <a class="<?= $curCat === '' ? 'on' : '' ?>" href="<?= e($lp . '/category/') ?>"><?= e($T_('全部', 'All')) ?></a>
  <?php foreach ($cats as $c): ?>
  <a class="<?= $curCat === $c['slug'] ? 'on' : '' ?>" style="--c:<?= e($c['color']) ?>"
     href="<?= e($lp . '/category/' . rawurlencode($c['slug']) . '/') ?>"><?= e($c['name']) ?><i><?= (int)$c['count'] ?></i></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if (!empty($posts)): ?>
<div class="atlas">
  <?php foreach (array_values($posts) as $i => $p):
      $cid = (int)$p['id'];
      $cat = tales_cat_of($cid);
      $pt  = strtotime((string)($p['published_at'] ?? $p['created_at']));
      $ex  = Str::excerpt(((string)($p['excerpt'] ?? '')) !== '' ? (string)$p['excerpt'] : (string)($p['content'] ?? ''), 90);
      $ttl = (string)$p['title'];
  ?>
  <a class="cell" href="<?= e($lp . '/' . rawurlencode((string)$p['slug']) . '.html') ?>" style="--c:<?= e($cat['color'] ?? 'var(--acc)') ?>">
    <span class="idx"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
    <span class="plate"><b><?= e(mb_substr($ttl, 0, 1)) ?></b></span>
    <h3><?= e($ttl) ?></h3>
    <p><?= e($ex) ?></p>
    <span class="meta"><?php if ($cat): ?><em><?= e($L && trim((string)$cat['name_en']) !== '' ? (string)$cat['name_en'] : (string)$cat['name']) ?></em><i>·</i><?php endif; ?><?= e(date('Y-m-d', $pt)) ?></span>
  </a>
  <?php endforeach; ?>
</div>

<?php if (($total_pages ?? 1) > 1): ?>
<nav class="pager">
  <?php if (($page ?? 1) > 1): ?>
    <a href="<?= e(str_replace('{n}', (string)((int)$page - 1), (string)$pager_base)) ?>">← <?= e($T_('上一页', 'Prev')) ?></a>
  <?php else: ?><span class="off">← <?= e($T_('上一页', 'Prev')) ?></span><?php endif; ?>
  <?php
  $cur = (int)($page ?? 1); $tp = (int)$total_pages;
  $from = max(1, $cur - 2); $to = min($tp, $from + 4); $from = max(1, $to - 4);
  for ($n = $from; $n <= $to; $n++):
      if ($n === $cur): ?><span class="cur"><?= $n ?></span>
      <?php else: ?><a href="<?= e(str_replace('{n}', (string)$n, (string)$pager_base)) ?>"><?= $n ?></a><?php endif;
  endfor; ?>
  <?php if ($cur < $tp): ?>
    <a href="<?= e(str_replace('{n}', (string)($cur + 1), (string)$pager_base)) ?>"><?= e($T_('下一页', 'Next')) ?> →</a>
  <?php else: ?><span class="off"><?= e($T_('下一页', 'Next')) ?> →</span><?php endif; ?>
</nav>
<?php endif; ?>

<?php else:
  $emptyTxt = (isset($empty) && $empty !== '') ? (string)$empty : $T_('这里还没有故事。', 'No stories here yet.'); ?>
<div class="empty"><?= e($emptyTxt) ?><?php if (!empty($empty_link)): ?> · <a href="<?= e((string)$empty_link) ?>"><?= e($T_('回首页', 'Back home')) ?></a><?php endif; ?></div>
<?php endif; ?>
