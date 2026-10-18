<?php
/** 列表：标签页 / 分类页 / 搜索 / 归档（云库 Yunku · 知识库风格）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<?php if (!empty($description) || !empty($total_items)): ?>
<div class="yk-cat-banner">
  <h1><?= e($page_title) ?></h1>
  <?php if (!empty($description)): ?><p class="yk-cat-desc"><?= e($description) ?></p><?php endif; ?>
  <div class="yk-cat-meta">
    <?php if (!empty($total_items)): ?><span class="yk-cat-count"><?= (int)$total_items ?> 篇文章</span><?php endif; ?>
    <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="page-head"><h1><?= e($page_title) ?></h1><?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?></div>
<?php endif; ?>
<?php if (!empty($posts)): ?>
<div class="yk-list">
<?php foreach ($posts as $p):
    $c0 = $p['cats'][0] ?? null;
    $cat = $c0 ? Poly::termName($c0) : '';
    $catUrl = $c0 ? Poly::termUrl('category', $c0) : '';
    $u = $base . e((string)$p['slug']) . '.html';
    $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
    $raw = ((string)($p['excerpt'] ?? '') !== '') ? (string)$p['excerpt'] : (string)($p['content'] ?? '');
    $exc = Str::plain(Str::plain(Str::excerpt($raw, 120)));
?>
  <article class="yk-item">
    <?php if ($cat !== ''): ?><a class="yk-cat" href="<?= e($catUrl) ?>"><?= e($cat) ?></a><?php endif; ?>
    <h2 class="yk-t"><a href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a></h2>
    <p class="yk-x"><?= e($exc) ?></p>
    <div class="yk-m"><span><?= e(date('Y-m-d', $dt)) ?></span><span><?= e(__('meta.reads')) ?><?= Str::num((int)($p['views'] ?? 0)) ?></span></div>
    <a class="yk-more" href="<?= e($u) ?>"><?= e(__('ui.read_more')) ?></a>
  </article>
<?php endforeach; ?>
</div>
<?php if ($total_pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>"><?= e(__('ui.prev')) ?></a><?php endif; ?>
  <span><?= $page ?> / <?= $total_pages ?></span>
  <?php if ($page < $total_pages): ?><a href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>"><?= e(__('ui.next')) ?></a><?php endif; ?>
</div>
<?php endif;
else:
$emptyTxt = isset($empty) && $empty !== '' ? $empty : __('list.empty'); ?>
<div class="empty"><?= e($emptyTxt) ?><?php if (!empty($empty_link)): ?><br><a href="<?= e($empty_link) ?>"><?= e(__('list.empty_alt')) ?></a><?php endif; ?></div>
<?php endif; ?>
