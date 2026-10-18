<?php
/** 列表：分类页 / 标签页 / 归档（kuake 夸克 · 红 · 主列表 + 右侧双 widget）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix,$total_items,$description */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<?php if (!empty($description) || !empty($total_items)): ?>
<div class="kk-listhead">
  <h1><?= e($page_title) ?></h1>
  <?php if (!empty($description)): ?><p class="kk-listdesc"><?= e($description) ?></p><?php endif; ?>
  <div class="kk-listmeta">
    <?php if (!empty($total_items)): ?><span class="kk-count"><?= (int)$total_items ?> 篇文章</span><?php endif; ?>
    <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="kk-listhead"><h1><?= e($page_title) ?></h1><?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?></div>
<?php endif; ?>
<?php if (!empty($posts)): ?>
<div class="kk-shell">
  <div class="kk-stream">
    <?php foreach ($posts as $p): ?>
      <?= View::render('partials/kk-entry', ['p' => $p, 'lp' => $lp, 'base' => $base]) ?>
    <?php endforeach; ?>
  </div>
  <?= View::render('partials/kk-widgets') ?>
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
