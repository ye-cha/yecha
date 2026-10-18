<?php
/** 列表：分类页 / 标签页 / 归档月（跃思 Yuesi · 扁平 标题 + 日期 / 灰色摘要 纯列表）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="ys-page">
  <header class="ys-page-h">
    <h1 class="ys-page-t"><?= e($page_title) ?></h1>
    <p class="ys-page-n">
      <?php if (!empty($total_items)): ?><span><?= (int)$total_items ?> 篇</span><?php endif; ?>
      <?php if (!empty($sub)): ?><span class="sub"><?= $sub ?></span><?php endif; ?>
    </p>
    <?php if (!empty($description)): ?><p class="ys-page-d"><?= e($description) ?></p><?php endif; ?>
  </header>

  <?php if (!empty($posts)): ?>
  <div class="ys-list">
    <?php foreach ($posts as $p): ?>
      <?= View::render('partials/ys-item', ['p' => $p, 'base' => $base]) ?>
    <?php endforeach; ?>
  </div>
  <?php if ($total_pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a href="<?= e(str_replace('{n}', (string)($page - 1), $pager_base)) ?>"><?= e(__('ui.prev')) ?></a><?php endif; ?>
    <span><?= $page ?> / <?= $total_pages ?></span>
    <?php if ($page < $total_pages): ?><a href="<?= e(str_replace('{n}', (string)($page + 1), $pager_base)) ?>"><?= e(__('ui.next')) ?></a><?php endif; ?>
  </nav>
  <?php endif;
  else:
  $emptyTxt = isset($empty) && $empty !== '' ? $empty : __('list.empty'); ?>
  <div class="empty"><?= e($emptyTxt) ?><?php if (!empty($empty_link)): ?><br><a href="<?= e($empty_link) ?>"><?= e(__('list.empty_alt')) ?></a><?php endif; ?></div>
  <?php endif; ?>
</div>
