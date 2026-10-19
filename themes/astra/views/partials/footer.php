<?php
/** 页脚（Astra 蓝调轻盈 风格 · 由 tools/gen_parts.py 生成，勿手改） */
$fnv = Nav::list('foot', Lang::short());
$icp = trim((string)get_option('site.icp', ''));
$icpLink = (string)get_option('site.icp_link', 'https://beian.miit.gov.cn/');
$desc = trim((string)get_option('site.description', ''));
?>
<footer class="site-footer thf thf-astra"><div class="wrap">
  <nav class="thf-nav"><?php foreach ($fnv as $ni): ?><a href="<?= e($ni['href']) ?>"><?= e($ni['label']) ?></a><?php endforeach; ?><a href="/feed">RSS 订阅</a><a href="/sitemap.xml">站点地图</a></nav>
  <div class="thf-bot"><span>&copy; <?= date('Y') ?> <?= e(site_label()) ?></span><?php if ($icp !== ''): ?><span class="dot">·</span><a href="<?= e($icpLink) ?>" rel="noopener" target="_blank"><?= e($icp) ?></a><?php endif; ?><span class="dot">·</span><span>主题演示站</span></div>
</div></footer>
