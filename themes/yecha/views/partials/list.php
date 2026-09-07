<?php
/** 列表区块：$posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link(可选,空态引导),$link_prefix(如 /en) */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : '')); ?>
<div class="page-head"><h1><?= e($page_title) ?></h1><?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?></div>
<?php if (!empty($posts)):
foreach ($posts as $p):
    $c0 = $p['cats'][0] ?? null;
    $cat = $c0 ? Poly::termName($c0) : '';
    $pt = strtotime((string)($p['published_at'] ?? $p['created_at']));
?>
<article class="post-item">
  <h2><a href="<?= e($lp . '/' . (string)$p['slug']) ?>.html"><?= e($p['title']) ?></a></h2>
  <p class="post-excerpt"><?= e(Str::excerpt(((string)($p['excerpt'] ?? '')) !== '' ? (string)$p['excerpt'] : (string)($p['content'] ?? ''), 120)) ?></p>
  <div class="post-meta">
    <span><?= e(lang_date($pt)) ?></span>
    <span><?= e(__('meta.reads')) ?><?= Str::num((int)$p['views']) ?></span>
    <?php if ($cat !== ''): ?><a class="cat" href="<?= e(Poly::termUrl('category', $p['cats'][0])) ?>"><?= e($cat) ?></a><?php endif; ?>
    <a class="readmore" href="<?= e($lp . '/' . (string)$p['slug']) ?>.html"><?= e(__('ui.read_more')) ?></a>
  </div>
</article>
<?php endforeach;
if ($total_pages > 1): ?>
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
