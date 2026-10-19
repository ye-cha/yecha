<?php
/**
 * 顶部导航（柳川 Liuchuan 演示站主题 · 手写，勿用 gen_parts 覆盖）
 * 演示站门面只呈现站点内容导航：首页 / 分类 / 标签 / 归档 / 关于 / RSS
 * —— 不读 nav.custom，因此不会出现「主题」「插件」等市场入口。
 * 结果同时写入 $GLOBALS['yc_liuchuan_nav']，供 layout 的移动抽屉复用。
 */
$cur = Nav::currentPath();
$lp  = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$items = [
    ['uri' => '/',           'label' => __('nav.home'),    'root' => true],
    ['uri' => '/category/',  'label' => __('nav.cats'),    'root' => false],
    ['uri' => '/tag/',       'label' => __('nav.tags'),    'root' => false],
    ['uri' => '/archives',   'label' => __('nav.archive'), 'root' => false],
    ['uri' => '/about.html', 'label' => __('nav.about'),   'root' => false],
    ['uri' => '/feed',       'label' => 'RSS',             'root' => false],
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

$bkNav = [];
foreach ($items as $it) {
    $uri = (string)$it['uri'];
    if (substr($uri, -5) === '.html' && !isset($has[substr($uri, 0, -5)])) { continue; }
    $href = !empty($it['root']) ? ($lp === '' ? '/' : $lp . '/') : $lp . $uri;
    $on   = ($href === '/')
        ? ($cur === '/' || $cur === $lp . '/' || $cur === $lp)
        : (str_starts_with($cur, $href));
    $bkNav[] = ['href' => $href, 'label' => (string)$it['label'], 'active' => $on];
}
$GLOBALS['yc_liuchuan_nav'] = $bkNav;
?>
<nav class="nav thn thn-liuchuan">
<?php foreach ($bkNav as $ni): ?>
  <a href="<?= e($ni['href']) ?>" class="thn-a<?= $ni['active'] ? ' on' : '' ?>"><?= e($ni['label']) ?></a>
<?php endforeach; ?>
</nav>
