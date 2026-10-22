<?php
declare(strict_types=1);
/**
 * Nav：站点导航单一数据源（顶部主导航 / 底部次导航共用）
 * - 标签走语言包（label = key 或字面量），链接走语言前缀；
 * - t39：顶部一级固定四项「下载(按钮) / 应用市场 / 文档 / 论坛」；下载与应用市场各带二级：
 *   下载 → 完整包 / 安装包 / 更新日志；应用市场 → 主题 / 插件。
 *   其余（站内分类 / 关于 / 联系 / 开发文档 / 应用商店）收进页脚。
 *   children 支持两种写法：字符串键数组（平铺）或 ['label'=>…,'items'=>[...]]（分组）。
 */
final class Nav
{
    public static function defs(): array
    {
        return [
            'home'      => ['label' => 'nav.home',      'uri' => '/',              'zone' => ['foot'], 'root' => true],
            'downloads' => ['label' => 'nav.download',  'uri' => '/downloads',     'zone' => ['top', 'foot'], 'root' => false,
                            'children' => [
                                ['label' => 'nav.grp_download', 'items' => ['pkg_full', 'pkg_install', 'changelog']],
                            ]],
            'market'    => ['label' => 'nav.market',    'uri' => '/market',        'zone' => ['top', 'foot'], 'root' => false,
                            'children' => [
                                ['label' => 'nav.grp_market', 'items' => ['themes', 'plugins']],
                            ]],
            'docs'      => ['label' => 'nav.docs',      'uri' => '/docs',          'zone' => ['top', 'foot'], 'root' => false],
            'forum'     => ['label' => 'nav.forum',     'uri' => '/forum.html',         'zone' => ['top', 'foot'], 'root' => false],

            // 二级 / 页脚项
            'pkg_full'  => ['label' => 'nav.pkg_full',  'uri' => '/downloads',     'zone' => ['foot'], 'root' => false],
            'pkg_install' => ['label' => 'nav.download_page', 'uri' => '/download.html', 'zone' => ['foot'], 'root' => false],
            'themes'    => ['label' => 'nav.themes',    'uri' => '/theme',         'zone' => ['foot'], 'root' => false],
            'plugins'   => ['label' => 'nav.plugins',   'uri' => '/plugin',        'zone' => ['foot'], 'root' => false],
            'store'     => ['label' => 'nav.store',     'uri' => '/store',         'zone' => ['foot'], 'root' => false],
            'developers'=> ['label' => 'nav.developers', 'uri' => '/developers.html', 'zone' => ['foot'], 'root' => false],
            'changelog' => ['label' => 'nav.changelog', 'uri' => '/changelog',     'zone' => ['foot'], 'root' => false],
            'cats'      => ['label' => 'nav.cats',      'uri' => '/category/',     'zone' => ['foot'], 'root' => false],
            'tags'      => ['label' => 'nav.tags',      'uri' => '/tag/',          'zone' => ['foot'], 'root' => false],
            'archive'   => ['label' => 'nav.archive',   'uri' => '/archives',      'zone' => ['foot'], 'root' => false],
            'search'    => ['label' => 'nav.search',    'uri' => '/search',        'zone' => ['foot'], 'root' => false],
            'about'     => ['label' => 'nav.about',     'uri' => '/about.html',    'zone' => ['foot'], 'root' => false],
            'join'      => ['label' => 'nav.join',      'uri' => '/join.html',     'zone' => ['foot'], 'root' => false],
            'links'     => ['label' => 'nav.links',     'uri' => '/links.html',    'zone' => ['foot'], 'root' => false, 'zh_only' => true],
            'privacy'   => ['label' => 'nav.privacy',   'uri' => '/privacy-policy.html', 'zone' => ['foot'], 'root' => false, 'zh_only' => true],
            'feed'      => ['label' => 'RSS',           'uri' => '/feed',          'zone' => ['foot'], 'root' => false],
            'sitemap'   => ['label' => 'Sitemap',       'uri' => '/sitemap.xml',   'zone' => ['foot'], 'root' => false],
        ];
    }

    public static function currentPath(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    }

    /** 按区域取导航项；返回 [['key','label','href','active','groups'?]] */
    public static function list(string $zone, ?string $lang = null): array
    {
        $lang = $lang ?? Lang::short();
        $default = Poly::defaultLang();
        $cur = self::currentPath();
        $hide = json_decode((string)get_option('nav.hide_' . $zone, '[]'), true);
        $hide = is_array($hide) ? $hide : [];
        $defs = self::defs();

        // 二级项只在顶部下拉里隐藏（页脚仍平铺全部栏目）
        $isChild = [];
        if ($zone === 'top') {
            foreach ($defs as $k => $d) {
                if (!in_array($zone, $d['zone'], true) || empty($d['children'])) { continue; }
                foreach (self::childKeys($d['children']) as $c) { $isChild[$c] = true; }
            }
        }

        $out = [];
        foreach ($defs as $key => $d) {
            if (!in_array($zone, $d['zone'], true)) { continue; }
            if (isset($isChild[$key])) { continue; }
            if (!empty($d['zh_only']) && $lang !== $default) { continue; }
            if (in_array($key, $hide, true)) { continue; }
            $it = self::item($key, $d['label'], $d['uri'], $d, $lang, $default);
            if (!empty($d['children']) && $zone === 'top') {
                $groups = [];
                foreach ((array)$d['children'] as $gk => $gd) {
                    if (!is_array($gd) || !isset($gd['items'])) { continue; }
                    $items = [];
                    foreach ((array)$gd['items'] as $ck) {
                        $cd = $defs[$ck] ?? null;
                        if ($cd === null) { continue; }
                        if (!empty($cd['zh_only']) && $lang !== $default) { continue; }
                        if (in_array($ck, $hide, true)) { continue; }
                        $items[] = self::item($ck, $cd['label'], $cd['uri'], $cd, $lang, $default);
                    }
                    if ($items !== []) { $groups[] = ['label' => __((string)($gd['label'] ?? '')), 'items' => $items]; }
                }
                if ($groups === []) { continue; }
                $it['groups'] = $groups;
                $flat = [];
                foreach ($groups as $g) { $flat = array_merge($flat, $g['items']); }
                $it['active'] = $it['active'] || (bool)array_filter(array_column($flat, 'active'));
            }
            $out[] = $it;
        }
        $custom = json_decode((string)get_option('nav.custom', '[]'), true);
        if (is_array($custom)) {
            foreach ($custom as $c) {
                if (!is_array($c)) { continue; }
                $zoneMatch = is_array($c['zone'] ?? null) ? $c['zone'] : (array)($c['zone'] ?? []);
                if (!in_array($zone, $zoneMatch, true)) { continue; }
                $zh = !empty($c['zh_only']);
                if ($zh && $lang !== $default) { continue; }
                $uri = trim((string)($c['uri'] ?? ''));
                if ($uri === '') { continue; }
                $label = trim((string)($c['label'] ?? ''));
                if ($label === '') { continue; }
                $out[] = self::item((string)($c['key'] ?? ('custom_' . count($out))), $label, $uri, $c, $lang, $default);
            }
        }
        return $out;
    }

    /** children 支持平铺键数组或分组定义；统一取子项 key */
    private static function childKeys(array $children): array
    {
        $keys = [];
        foreach ($children as $c) {
            if (is_array($c) && isset($c['items'])) {
                foreach ((array)$c['items'] as $k) { $keys[] = (string)$k; }
            } elseif (is_string($c)) {
                $keys[] = $c;
            }
        }
        return $keys;
    }

    private static function item(string $key, mixed $labelSrc, string $uri, array $d, string $lang, string $default): array
    {
        $label = in_array($labelSrc, ['RSS', 'Sitemap'], true) ? $labelSrc : __($labelSrc);
        if ($key === 'sitemap') {
            $href = '/sitemap.xml';
        } elseif (($d['root'] ?? false)) {
            $href = $lang === $default ? '/' : '/' . $lang . '/';
        } elseif ($uri === '') {
            $href = '#';
        } else {
            $href = ($lang === $default ? '' : '/' . $lang) . $uri;
        }
        return ['key' => $key, 'label' => $label, 'href' => $href, 'active' => self::isActive(self::currentPath(), $href, (bool)($d['root'] ?? false))];
    }

    private static function isActive(string $cur, string $href, bool $root): bool
    {
        if ($root) { return $cur === $href || $cur === '/' || preg_match('#^/(?:en/)?page/\d+$#', $cur) === 1; }
        $base = rtrim($href, '/');
        if ($base === '' || $base === '#') { return false; }
        if ($cur === $base || str_starts_with($cur, $base . '/')) { return true; }
        /* yf-seo-20261020：社区平铺伪静态 —— /forum.html 族（板块/帖子/用户/热榜…）都算社区项高亮 */
        if (($base === '/forum' || $base === '/forum.html')
            && (preg_match('#^/forum(-[a-z0-9-]+)?\\.html$', $cur) === 1 || preg_match('#^/forum(/|$)', $cur) === 1)) { return true; }
        if (!str_ends_with($base, '.html') && $cur === $base . '.html') { return true; }
        return false;
    }
}
