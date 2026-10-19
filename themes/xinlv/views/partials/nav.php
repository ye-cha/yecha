<?php
/**
 * 左栏竖排导航（新绿 Xinlv 演示站主题 · 手写，勿用 gen_parts 覆盖）
 * 演示站门面只呈现站点内容导航：首页 / 分类 / 标签 / 归档 / 关于 / RSS
 * —— 不读 nav.custom，因此不会出现「主题」「插件」等市场入口。
 * 结果同时写入 $GLOBALS['yc_xinlv_nav']，供 layout 的移动抽屉复用。
 */
$cur = Nav::currentPath();
$lp  = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$icoHome    = '<path d="M3 10.5 12 3l9 7.5"></path><path d="M5 9.8V21h14V9.8"></path>';
$icoFolder  = '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>';
$icoTag     = '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"></path><circle cx="7" cy="7" r="0.8" fill="currentColor" stroke="none"></circle>';
$icoArch    = '<rect x="2" y="4" width="20" height="5" rx="1"></rect><path d="M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"></path><path d="M10 13h4"></path>';
$icoAbout   = '<circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path>';
$icoRss     = '<path d="M4 11a9 9 0 0 1 9 9"></path><path d="M4 4a16 16 0 0 1 16 16"></path><circle cx="5" cy="19" r="1"></circle>';
$items = [
    ['uri' => '/',           'label' => __('nav.home'),    'root' => true,  'icon' => $icoHome],
    ['uri' => '/category/',  'label' => __('nav.cats'),    'root' => false, 'icon' => $icoFolder],
    ['uri' => '/tag/',       'label' => __('nav.tags'),    'root' => false, 'icon' => $icoTag],
    ['uri' => '/archives',   'label' => __('nav.archive'), 'root' => false, 'icon' => $icoArch],
    ['uri' => '/about.html', 'label' => __('nav.about'),   'root' => false, 'icon' => $icoAbout],
    ['uri' => '/feed',       'label' => 'RSS',             'root' => false, 'icon' => $icoRss],
];
// 独立页不存在时自动跳过（如站点没建“关于我们”）
$has = [];
try {
    if (class_exists('Db', false)) {
        foreach (Db::rows('SELECT slug FROM ' . Db::t('contents') . " WHERE type='page' AND status='publish' AND deleted_at IS NULL") as $r) {
            $has[(string)$r['slug']] = true;
        }
    }
} catch (\Throwable $e) { $has = []; }

$xinlvNav = [];
foreach ($items as $it) {
    $uri = (string)$it['uri'];
    if (substr($uri, -5) === '.html' && !isset($has[substr($uri, 0, -5)])) { continue; }
    $href = !empty($it['root']) ? ($lp === '' ? '/' : $lp . '/') : $lp . $uri;
    $on   = ($href === '/')
        ? ($cur === '/' || $cur === $lp . '/' || $cur === $lp)
        : (str_starts_with($cur, $href));
    $xinlvNav[] = ['href' => $href, 'label' => (string)$it['label'], 'active' => $on, 'icon' => $it['icon']];
}
$GLOBALS['yc_xinlv_nav'] = $xinlvNav;
?>
<nav class="hs-rail-nav">
<?php foreach ($xinlvNav as $ni): ?>
  <a href="<?= e($ni['href']) ?>"<?= $ni['active'] ? ' class="on"' : '' ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $ni['icon'] ?></svg><span><?= e($ni['label']) ?></span></a>
<?php endforeach; ?>
</nav>
