<?php
/**
 * Tales · 搜索页（$q, $posts）
 * 版式：页头（kicker + H1 + 结果计数）→ 搜索框 → 结果图录（字牌＝所属分类色，命中词高亮）
 */
require_once __DIR__ . '/../../lib.php';
$lp = (string)ui_lp();
$L  = (Lang::short() === 'en');
$T_ = function (string $zh, string $en) use ($L): string { return $L ? $en : $zh; };
$GLOBALS['YC_TALES_LIST'] = true;
$q = (string)($q ?? '');
$posts = is_array($posts ?? null) ? $posts : [];
$ph = $T_('输入关键词，如：田螺姑娘、洪水、报恩…', 'Try: snail maiden, flood, repayment…');
?>
<div class="wrap">
  <div class="page-head tales-head">
    <div class="kicker kicker-acc"><?= e($T_('站内搜索 · SEARCH', 'SEARCH')) ?></div>
    <h1><?= e($q !== '' ? sprintf($T_('搜索：%s', 'Search: %s'), $q) : $T_('搜索', 'Search')) ?></h1>
    <p class="lead"><?= $q !== ''
        ? e(sprintf($T_('共找到 %d 则故事。', '%d stories found.'), count($posts)))
        : e($T_('按标题、正文与母题标签检索。', 'Search titles, text and motif tags.')) ?></p>
  </div>

  <form class="tales-search" method="get" action="<?= e($lp . '/search') ?>" role="search">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e($ph) ?>" autocomplete="off" aria-label="<?= e($T_('搜索', 'Search')) ?>">
    <button type="submit"><?= e($T_('搜索', 'Search')) ?></button>
  </form>

  <?php if ($q !== '' && $posts): ?>
  <div class="atlas" style="margin-top:30px">
    <?php foreach (array_values($posts) as $i => $p):
        $cid = (int)$p['id'];
        $cat = tales_cat_of($cid);
        $pt  = strtotime((string)($p['published_at'] ?? $p['created_at']));
        $src = (string)(($p['excerpt'] ?? '') !== '' ? $p['excerpt'] : ($p['content'] ?? ''));
        $plain = trim((string)(preg_replace(['~!\[[^\]]*\]\([^)]*\)~', '~\[[^\]]*\]\([^)]*\)~', '~[#>`]{1,3}~', '~\*{1,3}~', '~_{1,3}~'], '', $src) ?? $src));
    ?>
    <a class="cell" href="<?= e($lp . '/' . rawurlencode((string)$p['slug']) . '.html') ?>" style="--c:<?= e($cat['color'] ?? 'var(--acc)') ?>">
      <span class="idx"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
      <span class="plate"><b><?= e(mb_substr((string)$p['title'], 0, 1)) ?></b></span>
      <h3><?= Str::mark((string)$p['title'], $q) ?></h3>
      <p><?= Str::mark(Str::snippet($plain, $q), $q) ?></p>
      <span class="meta"><?php if ($cat): ?><em><?= e($L && trim((string)$cat['name_en']) !== '' ? (string)$cat['name_en'] : (string)$cat['name']) ?></em><i>·</i><?php endif; ?><?= e(date('Y-m-d', $pt)) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php elseif ($q !== ''): ?>
  <div class="empty"><?= e(sprintf($T_('没有找到与「%s」相关的故事。', 'Nothing found for “%s”.'), $q)) ?></div>
  <?php endif; ?>
</div>
