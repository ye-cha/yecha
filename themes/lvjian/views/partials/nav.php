<?php
/**
 * 顶部导航（工坊 Toolbox v2）
 * 顶部只呈现站点分类，仿 tools.02405.com 的横向分类链接。
 * 移动抽屉仍保留完整站点导航（首页 / 分类 / 标签 / 归档 / 关于 / RSS）。
 */
$cur = Nav::currentPath();
$lp  = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';

// ---- 分类（最多 6 个，按后台顺序） ----
$catNav = [];
try {
    if (class_exists('Db', false)) {
        $M = Db::t('metas');
        foreach (Db::rows("SELECT name, slug FROM {$M} WHERE type='category' ORDER BY `order` ASC LIMIT 6") as $r) {
            $href = $lp . '/category/' . rawurlencode((string)$r['slug']) . '/';
            $catNav[] = ['href' => $href, 'label' => (string)$r['name'], 'active' => $cur === $href || str_starts_with($cur, $href)];
        }
    }
} catch (\Throwable $e) { $catNav = []; }

// ---- 抽屉用完整导航 ----
$items = [
    ['uri' => '/',           'label' => __('nav.home'),    'root' => true],
    ['uri' => '/category/',  'label' => __('nav.cats'),    'root' => false],
    ['uri' => '/tag/',       'label' => __('nav.tags'),    'root' => false],
    ['uri' => '/archives',   'label' => __('nav.archive'), 'root' => false],
    ['uri' => '/about.html', 'label' => __('nav.about'),   'root' => false],
    ['uri' => '/feed',       'label' => 'RSS',             'root' => false],
];
$has = [];
try {
    if (class_exists('Db', false)) {
        foreach (Db::rows('SELECT slug FROM ' . Db::t('contents') . " WHERE type='page' AND status='publish' AND deleted_at IS NULL") as $r) {
            $has[(string)$r['slug']] = true;
        }
    }
} catch (\Throwable $e) { $has = []; }

$toolboxNav = [];
foreach ($items as $it) {
    $uri = (string)$it['uri'];
    if (substr($uri, -5) === '.html' && !isset($has[substr($uri, 0, -5)])) { continue; }
    $href = !empty($it['root']) ? ($lp === '' ? '/' : $lp . '/') : $lp . $uri;
    $on   = ($href === '/')
        ? ($cur === '/' || $cur === $lp . '/' || $cur === $lp)
        : (str_starts_with($cur, $href));
    $toolboxNav[] = ['href' => $href, 'label' => (string)$it['label'], 'active' => $on];
}
$GLOBALS['yc_toolbox_nav'] = $toolboxNav;
?>
<nav class="nav thn thn-toolbox tb-links">
<?php foreach ($catNav as $ni): ?>
  <a href="<?= e($ni['href']) ?>" class="<?= $ni['active'] ? 'on' : '' ?>"><?= e($ni['label']) ?></a>
<?php endforeach; ?>
</nav>
