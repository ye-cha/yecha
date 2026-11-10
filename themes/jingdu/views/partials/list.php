<?php
/**
 * 静读 · 列表区块（归档 / 分类 / 标签 / 搜索 / 分页列表共用）
 * 入参：$posts,$page_title,$sub,$page,$total_pages,$pager_base,$empty,$empty_link,$link_prefix
 */
$lp = (string)($link_prefix ?? ui_lp());
$jd_excerpt_n = (int)theme_opt('excerpt', '150');
$jd_posts = (array)($posts ?? []);
?>
<div class="list-head">
  <h1><?= e($page_title) ?></h1>
  <?php if (!empty($sub)): ?><span class="sub"><?= e($sub) ?></span><?php endif; ?>
</div>

<?php if (!$jd_posts): ?>
  <div class="list-empty">
    <p><?= e(isset($empty) && $empty !== '' ? $empty : __('list.empty')) ?></p>
    <?php if (!empty($empty_link)): ?>
      <p><a href="<?= e($empty_link) ?>"><?= e(__('e404.back')) ?></a></p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <?php require __DIR__ . '/cards.php'; ?>

  <?php if ((int)$total_pages > 1): ?>
  <nav class="pager" aria-label="<?= e(__('ui.page') ?? '分页') ?>">
    <?php if ((int)$page > 1): ?>
      <a href="<?= e(str_replace('{n}', (string)((int)$page - 1), (string)$pager_base)) ?>" rel="prev"><?= e(__('ui.prev')) ?></a>
    <?php endif; ?>
    <span><?= (int)$page ?> / <?= (int)$total_pages ?></span>
    <?php if ((int)$page < (int)$total_pages): ?>
      <a href="<?= e(str_replace('{n}', (string)((int)$page + 1), (string)$pager_base)) ?>" rel="next"><?= e(__('ui.next')) ?></a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
<?php endif; ?>
