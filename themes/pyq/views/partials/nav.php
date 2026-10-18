<?php
/**
 * 顶部导航（朋友圈 Pyq 演示站主题 · 手写，勿用 gen_parts 覆盖）
 * 顶栏保留核心站点导航：首页 / 分类 / 标签 / 归档（+ 关于，若站点建有该页）。
 * 搜索入口为右上图标按钮；分类 / 标签 / 归档 / RSS 等完整集合仍见页脚。
 * —— 不读 nav.custom，因此不会出现「主题」「插件」等市场入口。
 * 结果同时写入 $GLOBALS['yc_pyq_nav']，供 layout 的移动抽屉复用。
 */
$cur = Nav::currentPath();
$lp  = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';

// 核心顶栏链接（顺序即展示顺序）
$items = [
    ['uri' => '/',           'label' => __('nav.home'),   'root' => true,  'prefix' => false],
    ['uri' => '/category/',  'label' => __('nav.cats'),   'root' => false, 'prefix' => '/category/'],
    ['uri' => '/tag/',       'label' => __('nav.tags'),   'root' => false, 'prefix' => '/tag/'],
    ['uri' => '/archives',   'label' => __('nav.archive'),'root' => false, 'prefix' => '/archives'],
    ['uri' => '/about.html', 'label' => __('nav.about'),  'root' => false, 'prefix' => false],
];

// 独立页（.html）不存在时自动跳过
$has = [];
try {
    if (class_exists('Db', false)) {
        foreach (Db::rows('SELECT slug FROM ' . Db::t('contents') . " WHERE type='page' AND status='publish' AND deleted_at IS NULL") as $r) {
            $has[(string)$r['slug']] = true;
        }
    }
} catch (\Throwable $e) { $has = []; }

$pyqNav = [];
foreach ($items as $it) {
    $uri = (string)$it['uri'];
    if (substr($uri, -5) === '.html' && !isset($has[substr($uri, 0, -5)])) { continue; }
    $href = !empty($it['root']) ? ($lp === '' ? '/' : $lp . '/') : $lp . $uri;
    if (!empty($it['prefix'])) {
        $on = str_starts_with($cur, $href);
    } else {
        $on = ($href === '/')
            ? ($cur === '/' || $cur === $lp . '/' || $cur === $lp)
            : ($cur === $href);
    }
    $pyqNav[] = ['href' => $href, 'label' => (string)$it['label'], 'active' => $on];
}
$GLOBALS['yc_pyq_nav'] = $pyqNav;
?>
<nav class="nav thn thn-pyq">
<?php foreach ($pyqNav as $ni): ?>
  <a href="<?= e($ni['href']) ?>" class="thn-a<?= $ni['active'] ? ' on' : '' ?>"><?= e($ni['label']) ?></a>
<?php endforeach; ?>
</nav>
