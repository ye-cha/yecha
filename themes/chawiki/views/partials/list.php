<?php
/**
 * ChaWiki · 列表区块（分类页 / 标签页 / 归档 / 搜索共用）
 * 变量：$posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$link_prefix
 * 三类页型之一：分类页（/category/{slug}/）
 */
require_once __DIR__ . '/../../inc.php';

/* ★ P3：告诉 layout.php 这是「列表页」（分类页/标签页/归档列表）—— v2 下保留满高左栏 */
$GLOBALS['YC_P3_KIND'] = 'list';

/* ★ P3 · 设计 §③-2(3) R3：**count=1 的标签页**加 `<meta name="robots" content="noindex,follow">`
   （避免 ~7,700 个薄页进索引）。只在 /tag/{slug} 形态下多查一条 SQL（1 次，仅标签页）；
   不改 robots.txt（那是 guardweb 的地盘，红线要求不碰）。 */
if (chawiki_v2()) {
    $__reqP = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    if (preg_match('#/(?:[a-z]{2}/)?tag/([^/]+)/?$#', $__reqP, $__tm)) {
        $__tslug = rawurldecode((string)$__tm[1]);
        $__tn = (int)Db::one('SELECT `count` FROM ' . Db::t('metas') . ' WHERE type=? AND slug=? LIMIT 1', ['tag', $__tslug]);
        if ($__tn > 0 && $__tn < 2) { $GLOBALS['YC_ROBOTS'] = 'noindex,follow'; }
    }
}

$lp = (string)($link_prefix ?? chawiki_prefix());
?><nav class="breadcrumb">
  <a href="<?= e($lp . '/') ?>"><?= e(__('nav.home')) ?></a>
  <span class="sep">/</span>
  <a href="<?= e($lp . '/category/') ?>">分类</a>
  <?php if (isset($page_title) && $page_title !== ''): ?><span class="sep">/</span><span class="cur"><?= e((string)$page_title) ?></span><?php endif; ?>
</nav>
<?php if (isset($page_title) && $page_title !== ''): ?>
<header class="list-head">
  <h1 class="article-title"><?= e((string)$page_title) ?></h1>
  <?php if (isset($sub) && $sub !== ''): ?><p class="list-sub"><?= e((string)$sub) ?></p><?php endif; ?>
</header>
<?php endif; ?>
<?php if (!empty($posts)):
$i = 0;
$total = isset($total_pages) ? (int)$total_pages : 1;
$pg = isset($page) ? (int)$page : 1;
?>
<div class="entry-grid chawiki-list">
<?php foreach ($posts as $p):
    $i++;
    $c0 = $p['cats'][0] ?? null;
    $cat = $c0 ? Poly::termName($c0) : '';
    $ex = trim((string)($p['excerpt'] ?? ''));
    if ($ex === '') { $ex = Str::excerpt((string)($p['content'] ?? ''), 90); }
    $im = '';
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', (string)($p['content'] ?? ''), $m)
        && (str_starts_with($m[1], '/') || str_starts_with($m[1], 'http'))) { $im = $m[1]; }
?>
  <a class="entry-card" href="<?= e(chawiki_url((string)$p['slug'])) ?>">
    <?php if ($im !== ''): ?><img class="ec-thumb" src="<?= e($im) ?>" alt="<?= e((string)$p['title']) ?>" loading="lazy" width="268" height="168"><?php endif; ?>
    <span class="ec-body">
      <?php if ($cat !== ''): ?><span class="ec-cat"><?= e($cat) ?></span><?php endif; ?>
      <span class="ec-title"><?= e((string)$p['title']) ?></span>
      <span class="ec-ex"><?= e(Str::excerpt($ex, 84)) ?></span>
    </span>
  </a>
<?php endforeach; ?>
</div>
<?php if ($total > 1): ?>
<nav class="pager">
  <?php if ($pg > 1): ?><a class="pg" href="<?= e(str_replace('{n}', (string)($pg - 1), (string)$pager_base)) ?>">‹</a><?php endif; ?>
  <?php for ($n = 1; $n <= $total; $n++):
      if ($total > 9 && $n > 2 && $n < $total - 1 && abs($n - $pg) > 2) { if ($n === 3) { echo '<span class="pg off">…</span>'; } continue; }
      if ($n === $pg): ?><span class="pg on"><?= $n ?></span><?php else: ?><a class="pg" href="<?= e(str_replace('{n}', (string)$n, (string)$pager_base)) ?>"><?= $n ?></a><?php endif; ?>
  <?php endfor; ?>
  <?php if ($pg < $total): ?><a class="pg" href="<?= e(str_replace('{n}', (string)($pg + 1), (string)$pager_base)) ?>">›</a><?php endif; ?>
</nav>
<?php endif;
else:
$emptyTxt = isset($empty) && $empty !== '' ? (string)$empty : __('list.empty'); ?>
<div class="empty"><?= e($emptyTxt) ?></div>
<?php endif; ?>
