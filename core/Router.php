<?php
declare(strict_types=1);

/**
 * Router：统一路由器（设计文档第 5.4 节顺序）
 * 解析顺序：固定路由 → 语言段(预留) → category/tag → 归档 → {slug}.html → 404
 * 伪静态关闭时路径为空、参数在 ?r=… 中，由 index.php 转译为 $path 后再进本类。
 */
final class Router
{
    /** 预留固定功能路径（内容 slug 不得占用，建 slug 时校验） */
    public const RESERVED = ['login', 'register', 'user', 'admin', 'feed', 'sitemap.xml', 'search', 'healthz', 'page', 'category', 'tag', 'archives', 'themes', 'plugins', 'uploads', 'install'];

    public static function resolve(string $path, array $query): array
    {
        $path = trim($path, '/');
        $seg = $path === '' ? [] : array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
        if (count($seg) === 0) {
            return ['kind' => 'home', 'page' => self::page($query)];
        }
        $head = strtolower($seg[0]);

        // 固定路由
        if ($head === 'healthz') {
            return ['kind' => 'health'];
        }

        // 语言段（多语插件启用后才解析；关闭时语言前缀不生效、直接落到 404/普通路由）
        $langs = Poly::on() ? Poly::enabled() : [];
        if (in_array($head, $langs, true)) {
            return self::resolve(implode('/', array_slice($seg, 1)), $query + ['__lang' => $head]);
        }

        if ($head === 'category' || $head === 'tag') {
            if (!isset($seg[1])) {
                // 索引页：/tag/ 全标签、/category/ 全分类（导航 & SEO）
                return ['kind' => $head === 'tag' ? 'tags_index' : 'cats_index'];
            }
            $page = 1;
            if (($seg[2] ?? '') === 'page' && isset($seg[3]) && is_numeric($seg[3])) {
                $page = max(1, (int)$seg[3]);
            }
            return ['kind' => 'meta_list', 'type' => $head === 'category' ? 'category' : 'tag',
                    'slug' => $seg[1], 'page' => $page];
        }

        if ($head === 'archives') {
            if (!isset($seg[1])) {
                return ['kind' => 'archives_overview'];
            }
            $y = (int)$seg[1];
            $m = isset($seg[2]) && is_numeric($seg[2]) ? (int)$seg[2] : 0;
            if ($y < 1970 || $y > 2200 || $m < 0 || $m > 12) {
                return ['kind' => '404']; // 非法年份/月份
            }
            return ['kind' => 'archives_list', 'year' => $y, 'month' => $m];
        }

        if ($head === 'search') {
            return ['kind' => 'search', 'q' => trim((string)($query['q'] ?? ''))];
        }

        // 前台登录/注册/退出
        if ($head === 'login') { return ['kind' => 'login']; }
        if ($head === 'register') { return ['kind' => 'register']; }
        if ($head === 'logout') { return ['kind' => 'logout']; }
        // 阅读计数上报（POST，前端按 访客+文章+自然日 去重）
        if ($head === 'count') { return ['kind' => 'count_hit']; }

        // RSS / sitemap（多语：/{lang}/feed 由语言段递归后落到 feed）
        if ($head === 'feed') { return ['kind' => 'feed']; }
        if ($head === 'sitemap' || $head === 'sitemap.xml') { return ['kind' => 'sitemap']; }
        if ($head === 'pages') { return ['kind' => 'pages_list']; }

        // 用户中心 / 动作端点（E2）
        if ($head === 'user') {
            $sub = $seg[1] ?? 'profile';
            if ($sub === 'api') {
                return ['kind' => 'user_api', 'api' => (string)($seg[2] ?? '')];
            }
            if (in_array($sub, ['mark', 'annotation', 'mark-del', 'annotation-del', 'favorite', 'comment', 'notify-read', 'logout', 'password'], true)) {
                return ['kind' => 'user_action', 'act' => $sub];
            }
            if ($sub === 'notifications') { return ['kind' => 'user_notifications']; }
            if ($sub === 'favorites') { return ['kind' => 'user_favorites']; }
            if ($sub === 'notes') { return ['kind' => 'user_notes']; }
            return ['kind' => 'user_profile'];
        }

        if ($head === 'page' && isset($seg[1]) && is_numeric($seg[1])) {
            return ['kind' => 'home', 'page' => max(1, (int)$seg[1])];
        }

        // {slug}.html（文章/页面统一）
        $last = end($seg);
        if (is_string($last) && str_ends_with(strtolower($last), '.html') && count($seg) === 1) {
            $slug = substr($last, 0, -5);
            if ($slug === '' || in_array(strtolower($slug), self::RESERVED, true)) {
                return ['kind' => '404'];
            }
            return ['kind' => 'post', 'slug' => $slug];
        }

        // 第三方插件独立页：/{slug}（单段、非保留字）
        if ($path !== '' && count($seg) === 1) {
            $plugPages = class_exists('Plugins', false) ? Plugins::pages() : [];
            if (isset($plugPages[$path])) {
                return ['kind' => 'plugin_page', 'slug' => $path];
            }
        }

        return ['kind' => '404'];
    }

    public static function page(array $query): int
    {
        return max(1, (int)($query['page'] ?? 1));
    }
}
