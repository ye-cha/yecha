<?php
/**
 * 独立页面列表（内核路由 /pages）
 * ★★ P3（2026-09-18）：v2 下本页升级为**人读版站点地图**（设计 §③-5 线框 ⑥-10 / §③-8）：
 *    · 4 组 13 类（含计数 + 每类 3 条样本）
 *    · 7 条独立页
 *    · 最近更新 100 条
 *    · 顶部给 XML 版 /sitemap.xml 的入口（机器可读那份 **一个字没动**）
 *    ★ 为什么落在 /pages 而不是设计里写的 /sitemap/：内核 Router.php 把 `$head === 'sitemap'`
 *      硬编码成 XML 直出（`/sitemap` 与 `/sitemap/` 都落到 `Feed::render('sitemap')`），
 *      要在 `/sitemap/` 出 HTML **必须改 core/Router.php 一行** —— 本轮红线禁止碰 core/ ⇒ 见报告。
 *    v1 分支原样保留。
 */
require_once __DIR__ . '/../../inc.php';
$cwV2 = chawiki_v2();
if ($cwV2):
    $GLOBALS['YC_P3_KIND'] = 'sitemap';
    $lp = chawiki_prefix();
    $groups = chawiki_cat_groups();
    $recent = chawiki_recent_entries(100);
    $nEntries = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . " WHERE type='post' AND status='publish' AND deleted_at IS NULL");
    $nCats = 0;
    foreach ($groups as $g) { $nCats += count((array)$g['cats']); }
    /* 每类 3 条样本（与分类索引同口径）—— 取自左栏树的块缓存，0 条新增 SQL */
    $__useT2 = Poly::on() && Lang::short() !== Poly::defaultLang();
    $__tree3 = chawiki_left_tree(Lang::short(), $__useT2);
    $__samp3 = [];
    foreach ($__tree3 as $__g) {
        $__arr3 = [];
        foreach ((array)($__g['items'] ?? []) as $__it) { if (count($__arr3) >= 3) { break; } $__arr3[] = $__it; }
        $__samp3[(string)($__g['slug'] ?? '')] = $__arr3;
    }
    foreach ($groups as $gi => $g) {
        foreach ((array)$g['cats'] as $ci => $c) {
            $groups[$gi]['cats'][$ci]['samples'] = $__samp3[(string)$c['slug']] ?? [];
        }
    }
?>
<nav class="breadcrumb">
  <a href="<?= e($lp . '/') ?>"><?= e(__('nav.home')) ?></a>
  <span class="sep">/</span>
  <span class="cur">站点地图</span>
</nav>
<header class="list-head">
  <h1 class="article-title">站点地图</h1>
  <p class="list-sub"><?= number_format($nEntries) ?> 条词条 · <?= (int)$nCats ?> 个分类 · 4 组</p>
</header>
<p class="sm-xml">机器可读版：<a href="<?= e($lp . '/sitemap.xml') ?>">/sitemap.xml</a>（12,000+ 条 URL，给搜索引擎）· <a href="<?= e(ui_lp() . '/feed') ?>">RSS</a></p>

<?php foreach ($groups as $g): ?>
<section class="cat-group sm-sec">
  <h2 class="cg-head"><?= e((string)$g['name']) ?><span class="cg-n"><?= number_format((int)$g['n']) ?> 条</span></h2>
  <div class="sm-ci">
    <?php foreach ((array)$g['cats'] as $c): ?>
    <div class="sm-ci-row">
      <a class="sm-ci-name" href="<?= e($lp . '/category/' . rawurlencode((string)$c['slug']) . '/') ?>"><?= e((string)$c['name']) ?><span class="n"><?= number_format((int)$c['n']) ?></span></a>
      <span class="sm-ci-samp">
        <?php foreach ((array)($c['samples'] ?? []) as $sp): ?>
        <a href="<?= e($lp . chawiki_entry_url((string)$sp['slug'])) ?>"><?= e(Str::excerpt((string)$sp['title'], 16)) ?></a>
        <?php endforeach; ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>

<?php if ($pages): ?>
<section class="sm-sec">
  <h2 class="cg-head">独立页<span class="cg-n"><?= count($pages) ?> 条</span></h2>
  <div class="sm-ci">
    <?php foreach ($pages as $pg): ?>
    <div class="sm-ci-row">
      <a class="sm-ci-name" href="<?= e(chawiki_entry_url((string)$pg['slug'])) ?>"><?= e(Str::excerpt((string)$pg['title'], 20)) ?></a>
      <span class="sm-ci-samp"><?= e(Str::excerpt((string)($pg['excerpt'] !== '' ? $pg['excerpt'] : $pg['description']), 60)) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($recent): ?>
<section class="sm-sec">
  <h2 class="cg-head">最近更新<span class="cg-n"><?= count($recent) ?> 条</span></h2>
  <div class="sm-recent">
    <?php foreach ($recent as $r): ?>
    <a href="<?= e($lp . chawiki_entry_url((string)$r['slug'])) ?>"><?= e((string)$r['title']) ?></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php else: /* ---- v1 原路径（原样保留） ---- */ ?>
<div class="page-head"><h1><?= e($page_title) ?></h1><span class="sub"><?= e($sub) ?></span></div>
<?php if ($pages): ?>
<div class="post-list" id="viewPages">
  <?php foreach ($pages as $pg): ?>
  <article class="post-item">
    <h2><a href="<?= e(chawiki_entry_url((string)$pg['slug'])) ?>"><?= e($pg['title']) ?></a></h2>
    <p class="post-excerpt"><?= e(Str::excerpt((string)($pg['excerpt'] !== '' ? $pg['excerpt'] : $pg['description']), 140)) ?></p>
    <div class="post-meta"><span><?= e(__('pg.updated')) ?><?= e(substr((string)$pg['updated_at'], 0, 10)) ?></span>
      <a class="readmore" href="<?= e(chawiki_entry_url((string)$pg['slug'])) ?>"><?= e(__('ui.read')) ?></a></div>
  </article>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="uc-empty"><?= e(__('pg.empty')) ?></div>
<?php endif; ?>
<?php endif; ?>
