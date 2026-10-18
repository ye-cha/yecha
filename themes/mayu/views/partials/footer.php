<?php
/**
 * 页脚（码语 Mayu 演示站主题 · 手写，勿用 gen_parts 覆盖）
 * 与顶部导航一致：只列站点内容链接，不出现「主题」「插件」市场入口。
 */
$icp     = trim((string)get_option('site.icp', ''));
$icpLink = (string)get_option('site.icp_link', 'https://beian.miit.gov.cn/');
$lp      = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$links = [
    '/'                      => __('nav.home'),
    '/category/'             => __('nav.cats'),
    '/tag/'                  => __('nav.tags'),
    '/archives'              => __('nav.archive'),
    '/links.html'            => __('nav.links'),
    '/about.html'            => __('nav.about'),
    '/privacy-policy.html'   => __('nav.privacy'),
    '/feed'                  => 'RSS',
    '/sitemap.xml'           => 'Sitemap',
];
// 独立页不存在时自动跳过
$has = [];
try {
    if (class_exists('Db', false)) {
        foreach (Db::rows('SELECT slug FROM ' . Db::t('contents') . " WHERE type='page' AND status='publish' AND deleted_at IS NULL") as $r) {
            $has[(string)$r['slug']] = true;
        }
    }
} catch (\Throwable $e) { $has = []; }
?>
<footer class="site-footer thf thf-mayu"><div class="wrap">
  <nav class="thf-nav">
    <?php foreach ($links as $uri => $label):
        if (substr($uri, -5) === '.html' && !isset($has[substr($uri, 0, -5)])) { continue; }
        $href = ($uri === '/') ? ($lp === '' ? '/' : $lp . '/') : $lp . $uri; ?>
    <a href="<?= e($href) ?>"><?= e((string)$label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="thf-bot">
    <span>&copy; <?= date('Y') ?> <?= e(site_label()) ?></span>
    <span class="dot">·</span><span>本站为演示站，站内内容均为演示数据</span>
    <?php if ($icp !== ''): ?><span class="dot">·</span><a href="<?= e($icpLink) ?>" rel="noopener" target="_blank"><?= e($icp) ?></a><?php endif; ?>
  </div>
</div></footer>
