<?php
/** 列表：分类页 / 标签页 / 归档（琪初 Qichu · 仿 qitry.vip 窄栏衬线列表）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="qc-head">
  <h1 class="qc-h"><?= e($page_title) ?></h1>
  <?php if (!empty($sub)): ?><span class="sub qc-sub"><?= $sub ?></span><?php endif; ?>
</div>
<?php if (!empty($posts)): ?>
<div class="qc-list">
<?php foreach ($posts as $p):
    $u = $base . e((string)$p['slug']) . '.html';
    $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
?>
  <article class="qc-item">
    <a class="qc-item-t" href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a>
    <time class="qc-item-d"><?= e(date('Y-m-d', $dt)) ?></time>
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
