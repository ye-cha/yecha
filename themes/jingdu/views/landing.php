<?php
/**
 * 静读 · 首页：文章卡片流（单栏、无侧栏、无大图）
 * 变量（由 Front::home 提供）：$posts,$page,$total_pages,$page_title,$list_title,$title
 */
$lp = ui_lp();
$jd_excerpt_n = (int)theme_opt('excerpt', '150');
$jd_posts = (array)($posts ?? []);
$jd_page = (int)($page ?? 1);
$jd_total = (int)($total_pages ?? 1);
$jd_ptitle = (string)($page_title ?? '');
$jd_ltitle = (string)($list_title ?? '');

/* 分页基址：/page/{n}（译文界面自动带语言前缀） */
$pager_base = $lp . '/page/{n}';
?>
<div class="list-head">
  <h1><?= e($jd_ptitle !== '' ? $jd_ptitle : __('tt.latest')) ?></h1>
  <?php if ($jd_ltitle !== ''): ?><span class="sub"><?= e($jd_ltitle) ?></span><?php endif; ?>
</div>

<?php if (!$jd_posts): ?>
  <div class="list-empty">
    <p><?= e(__('list.empty')) ?></p>
    <p><a href="<?= e($lp === '' ? '/' : $lp . '/') ?>"><?= e(__('e404.back')) ?></a></p>
  </div>
<?php else: ?>
  <?php require __DIR__ . '/partials/cards.php'; ?>

  <?php if ($jd_total > 1): ?>
  <nav class="pager" aria-label="<?= e(__('ui.page') ?? '分页') ?>">
    <?php if ($jd_page > 1): ?>
      <a href="<?= e(str_replace('{n}', (string)($jd_page - 1), $pager_base)) ?>" rel="prev"><?= e(__('ui.prev')) ?></a>
    <?php endif; ?>
    <span><?= $jd_page ?> / <?= $jd_total ?></span>
    <?php if ($jd_page < $jd_total): ?>
      <a href="<?= e(str_replace('{n}', (string)($jd_page + 1), $pager_base)) ?>" rel="next"><?= e(__('ui.next')) ?></a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
<?php endif; ?>
