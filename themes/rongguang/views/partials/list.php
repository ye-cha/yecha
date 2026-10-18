<?php
/** 列表：分类页 / 标签页 / 首页分页（绒光 Rongguang · 居中「日期 + 标题」纯文本列表）
 *  $posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix */
$lp = (string)($link_prefix ?? (Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : ''));
$base = $lp === '' ? '/' : rtrim($lp, '/') . '/';
?>
<div class="rg-page">
  <header class="rg-page-h">
    <h1 class="rg-page-t"><?= e($page_title) ?></h1>
    <?php if (!empty($total_items)): ?><p class="rg-page-n"><?= (int)$total_items ?> 篇</p><?php endif; ?>
    <?php if (!empty($description)): ?><p class="rg-page-d"><?= e($description) ?></p><?php endif; ?>
    <?php if (!empty($sub)): ?><p class="sub rg-page-crumb"><?= $sub ?></p><?php endif; ?>
  </header>

  <?php if (!empty($posts)): ?>
  <div class="rg-list">
  <?php foreach ($posts as $p):
    $u = $base . e((string)$p['slug']) . '.html';
    $dt = strtotime((string)($p['published_at'] ?? $p['created_at']));
  ?>
    <article class="rg-row">
      <time class="rg-date" datetime="<?= e(date('Y-m-d', $dt)) ?>"><?= e(date('Y-m-d', $dt)) ?></time>
      <a class="rg-title" href="<?= e($u) ?>"><?= e((string)$p['title']) ?></a>
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
</div>
