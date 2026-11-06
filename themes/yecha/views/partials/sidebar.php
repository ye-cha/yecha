<?php
/**
 * 官网侧栏（文档版，译文界面用）：搜索 / 关于 YeCha / 文档导航 / 最近更新 / 资源入口
 *  - 文档清单与版本由 core/docs-nav.php 统一提供，中文界面的「页面布局」功能块同源
 *  - 入参 $latest/$cats/$tags/$months 保留以兼容旧调用，不再渲染博客式区块
 */
require_once dirname(__DIR__, 4) . '/core/docs-nav.php';
$en = Lang::isEn();
$lp = ui_lp();
?>
<div class="side-box">
  <h4><?= e(__('sb.search')) ?></h4>
  <form class="side-search" method="get" action="<?= e($lp . '/search') ?>" role="search">
    <input type="search" name="q" placeholder="<?= e(__('sb.search_ph')) ?>" aria-label="<?= e(__('sb.search_aria')) ?>">
    <button type="submit"><?= e(__('sb.go')) ?></button>
  </form>
</div>
<?= yc_side_blocks_html() ?>
