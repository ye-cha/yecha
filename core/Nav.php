<?php
declare(strict_types=1);
/**
 * Nav：站点导航单一数据源（顶部主导航 / 底部次导航共用）
 * - 标签走语言包（label = key 或字面量），链接走语言前缀；
 * - zh_only 项（About/Pages 等独立页）仅在基准语言出现。
 */
final class Nav
{
    /** 栏目定义：zone=top|foot；root=首页类；zh_only=仅基准语言 */
    public static function defs(): array
    {
        return [
            'home'    => ['label' => 'nav.home',    'uri' => '/',          'zone' => ['top', 'foot'], 'root' => true],
            'tags'    => ['label' => 'nav.tags',    'uri' => '/tag/',      'zone' => ['top', 'foot'], 'root' => false],
            'cats'    => ['label' => 'nav.cats',    'uri' => '/category/', 'zone' => ['foot'],        'root' => false],
            'archive' => ['label' => 'nav.archive', 'uri' => '/archives',  'zone' => ['foot'],        'root' => false],
            'search'  => ['label' => 'nav.search',  'uri' => '/search',    'zone' => ['top'],         'root' => false],
            'links'   => ['label' => 'nav.links',   'uri' => '/links.html', 'zone' => ['foot'],      'root' => false, 'zh_only' => true],
            'about'   => ['label' => 'nav.about',   'uri' => '/about.html', 'zone' => ['foot'],      'root' => false, 'zh_only' => true],
            'privacy' => ['label' => 'nav.privacy', 'uri' => '/privacy-policy.html', 'zone' => ['foot'], 'root' => false, 'zh_only' => true],
            'feed'    => ['label' => 'RSS',         'uri' => '/feed',      'zone' => ['foot'],        'root' => false],
            'sitemap' => ['label' => 'Sitemap',     'uri' => '/sitemap.xml', 'zone' => ['foot'],     'root' => false],
        ];
    }

    /** 当前请求原始路径 */
    public static function currentPath(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    }

    /** 按区域取导航项；返回 [['key','label','href','active']] */
    public static function list(string $zone, ?string $lang = null): array
    {
        $lang = $lang ?? Lang::short();
        $default = Poly::defaultLang();
        $cur = self::currentPath();
        // 站点可隐藏默认导航项（option nav.hide_top / nav.hide_foot，按 key 过滤；未配置则全部显示）
        $hide = json_decode((string)get_option('nav.hide_' . $zone, '[]'), true);
        $hide = is_array($hide) ? $hide : [];
        $out = [];
        foreach (self::defs() as $key => $d) {
            if (!in_array($zone, $d['zone'], true)) { continue; }
            if (!empty($d['zh_only']) && $lang !== $default) { continue; }
            if (in_array($key, $hide, true)) { continue; }
            $out[] = self::item($key, $d['label'], $d['uri'], $d, $lang, $default);
        }
        // 站点自定义导航（按站点 option nav.custom 配置；未配置的站点无任何影响）
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

    private static function item(string $key, mixed $labelSrc, string $uri, array $d, string $lang, string $default): array
    {
        $isLabel = is_string($labelSrc);
        $label = in_array($labelSrc, ['RSS', 'Sitemap'], true) ? $labelSrc : __($labelSrc);
        if ($key === 'sitemap') {
            $href = '/sitemap.xml';
        } elseif (($d['root'] ?? false)) {
            $href = $lang === $default ? '/' : '/' . $lang . '/';
        } else {
            $href = ($lang === $default ? '' : '/' . $lang) . $uri;
        }
        return ['key' => $key, 'label' => $label, 'href' => $href, 'active' => self::isActive(self::currentPath(), $href, (bool)($d['root'] ?? false))];
    }

    private static function isActive(string $cur, string $href, bool $root): bool
    {
        if ($root) { return $cur === $href || $cur === '/' || preg_match('#^/(?:en/)?page/\d+$#', $cur) === 1; }
        $base = rtrim($href, '/');
        if ($base === '') { return false; }
        return $cur === $base || str_starts_with($cur, $base . '/');
    }
}
