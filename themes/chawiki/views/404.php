<?php
/**
 * ChaWiki · 404 页
 * ★★ P3（2026-09-18）：v2 下按设计 §③-5 线框 ⑥-10 重做 ——
 *    · 去掉现状那 13 条左栏链接（v2 的 404 本来就没有左栏：layout.php 按页型决定）
 *    · 出口从「只有返回首页」1 个 → **4 组 13 类 + 最近更新 8 条 + 搜索框 + 返回首页**（≥4 个出口）
 *    v1 分支原样保留。
 */
require_once __DIR__ . '/../inc.php';
$cwV2 = chawiki_v2();
if ($cwV2) { $GLOBALS['YC_P3_KIND'] = 'notfound'; }
if (!$cwV2):
?>
<div class="empty" style="padding:70px 0;">
  <h1 style="font-family:var(--font-serif);font-size:60px;margin:0 0 6px;color:var(--border-strong);">404</h1>
  <p style="color:var(--text-soft);"><?= e(__('e404.msg')) ?></p>
  <p><a class="sec-more" href="/"><?= e(__('e404.back')) ?></a> · <a class="sec-more" href="/category/">分类总览</a> · <a class="sec-more" href="/search">搜索词条</a></p>
</div>
<?php else:
$lp404 = chawiki_prefix();
$g404 = chawiki_cat_groups();
$recent404 = chawiki_recent_entries(8);
?>
<div class="p404">
  <h1 class="p404-big">404</h1>
  <p class="p404-msg"><?= e(__('e404.msg')) ?></p>

  <form class="p404-search" action="<?= e($lp404 . '/search') ?>" method="get" role="search">
    <input type="search" name="q" placeholder="搜索词条：茶名 / 产区 / 工艺 / 器具…" aria-label="<?= e(__('ui.search')) ?>">
    <button type="submit"><?= e(__('ui.search')) ?></button>
  </form>

  <?php if ($g404): ?>
  <section class="p404-sec">
    <h2 class="p404-cap">按分类找</h2>
    <div class="p404-cats">
      <a class="p404-link" href="<?= e($lp404 . '/category/') ?>">全部分类索引</a>
      <?php foreach ($g404 as $g): foreach ((array)$g['cats'] as $c): ?>
      <a class="p404-link" href="<?= e($lp404 . '/category/' . rawurlencode((string)$c['slug']) . '/') ?>"><?= e((string)$c['name']) ?><span class="n"><?= number_format((int)$c['n']) ?></span></a>
      <?php endforeach; endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($recent404): ?>
  <section class="p404-sec">
    <h2 class="p404-cap">最近更新</h2>
    <div class="p404-recent">
      <?php foreach ($recent404 as $r): ?>
      <a href="<?= e($lp404 . chawiki_entry_url((string)$r['slug'])) ?>"><?= e((string)$r['title']) ?></a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <p class="p404-back">
    <a href="<?= e($lp404 . '/') ?>"><?= e(__('e404.back')) ?></a>
    <a href="<?= e($lp404 . '/latest') ?>">词条索引</a>
    <a href="<?= e($lp404 . '/tag/') ?>">标签索引</a>
    <a href="<?= e($lp404 . '/pages') ?>">站点地图</a>
  </p>
</div>
<?php endif; ?>
