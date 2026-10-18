<?php
/** 列表：分类页 / 标签页 / 归档（kejiyuan 科技原 · 蓝 · 标题带 + 卡片网格）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix,$total_items,$description */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="kj-band">
  <h1><?= e($page_title) ?></h1>
  <?php if (!empty($description)): ?><p class="kj-listdesc"><?= e($description) ?></p><?php endif; ?>
  <div class="kj-band-meta">
    <?php if (!empty($total_items)): ?><span class="kj-count"><?= (int)$total_items ?> 篇文章</span><?php endif; ?>
    <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
  </div>
</div>
<?php if (!empty($posts)): ?>
<div class="kj-grid">
  <?php foreach ($posts as $p): ?>
    <?= View::render('partials/kj-card', ['p' => $p, 'lp' => $lp, 'base' => $base]) ?>
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
