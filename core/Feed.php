<?php
declare(strict_types=1);
/**
 * Feed：RSS / Sitemap（多语 + hreflang）
 * - /feed        默认语言文章 RSS（/en/feed 为英文译文 RSS）
 * - /sitemap.xml 全站地图：zh 默认 + 每篇含英文译文时输出 alternate hreflang zh/en
 */
final class Feed
{
    private static function baseUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8082';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host;
    }

    private static function xesc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public static function render(string $kind, string $lang): string
    {
        $hostKey = strtolower((string)($_SERVER['HTTP_HOST'] ?? '')); // 缓存键含 Host，防 Host 头投毒串源
        $key = $kind . ':' . $lang . ':v2:' . $hostKey;
        $cached = Cache::get('blocks', $key);
        if ($cached !== false) { return $cached; }
        $body = $kind === 'feed' ? self::rss($lang) : self::sitemap();
        Cache::set('blocks', $key, $body, 600);
        return $body;
    }

    /* ---------- RSS ---------- */
    private static function rss(string $lang): string
    {
        $T = Db::t('');
        $base = self::baseUrl();
        $default = Poly::defaultLang();
        $langTag = $lang === $default ? 'zh-CN' : $lang;
        $site = (string)get_option('site.name', 'YeCha');
        $sub = (string)get_option('site.subtitle', '');
        $title = $lang === $default ? $site : site_label() . ' · ' . Poly::label($lang);
        $desc = $lang === $default ? $sub : __('site.tagline');

        $items = '';
        if ($lang === $default) {
            $rows = Db::rows("SELECT * FROM {$T}contents WHERE type='post' AND status='publish' AND deleted_at IS NULL ORDER BY published_at DESC, id DESC LIMIT 100");
            foreach ($rows as $r) {
                $link = $base . '/' . $r['slug'] . '.html';
                $items .= self::rssItem($r['title'], $link, (string)$r['excerpt'], (string)$r['content'], (string)$r['published_at']);
            }
        } else {
            $rows = Poly::publishedList($lang, 100, true);
            $lp = $lang === $default ? '' : '/' . $lang;
            foreach ($rows as $r) {
                $link = $base . $lp . '/' . $r['slug'] . '.html';
                $items .= self::rssItem($r['title'], $link, (string)$r['excerpt'], (string)($r['content'] ?? ''), (string)($r['published_at'] ?? $r['updated_at']));
            }
        }
        $now = date(DATE_RSS);
        $pi = '';
        $xsl = self::xslUrl('feed.xsl');
        if ($xsl !== null) { $pi = '<?xml-stylesheet type="text/xsl" href="' . self::xesc($xsl) . '"?>' . "\n"; }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . $pi
            . '<rss version="2.0"><channel>'
            . '<title>' . self::xesc($title) . '</title>'
            . '<link>' . self::xesc($base) . '</link>'
            . '<description>' . self::xesc($desc) . '</description>'
            . '<language>' . $langTag . '</language>'
            . '<lastBuildDate>' . $now . '</lastBuildDate>'
            . $items . '</channel></rss>';
    }

    private static function rssItem(string $title, string $link, string $excerpt, string $content, string $date): string
    {
        // 先清洗 Markdown 再取纯文本，避免 ![图](..) 等语法漏进 RSS description
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags(Str::cleanMd((string)$content))) ?? '');
        if ($plain === '') { $plain = trim(Str::cleanMd((string)$excerpt)); }
        $pub = $date ? date(DATE_RSS, strtotime($date)) : date(DATE_RSS);
        return '<item><title>' . self::xesc($title) . '</title>'
            . '<link>' . self::xesc($link) . '</link>'
            . '<guid isPermaLink="true">' . self::xesc($link) . '</guid>'
            . '<description>' . self::xesc(mb_substr($plain, 0, 500)) . '</description>'
            . '<pubDate>' . $pub . '</pubDate></item>';
    }

    /* 当前主题的 XSL 样式表 URL（无则 null）：优先 theme.default 主题目录，回退 yecha */
    private static function xslUrl(string $file): ?string
    {
        foreach ([(string)get_option('theme.default', 'yecha'), 'yecha'] as $th) {
            if (!preg_match('/^[a-z0-9_-]+$/i', $th)) { continue; }
            $p = dirname(__DIR__) . '/themes/' . $th . '/' . $file;
            if (is_file($p)) { return '/themes/' . $th . '/' . $file . '?v=' . filemtime($p); }
        }
        return null;
    }

    /* ---------- Sitemap（多语 + hreflang） ---------- */
    private static function sitemap(): string
    {
        $T = Db::t('');
        $base = self::baseUrl();
        $pi = '';
        $xsl = self::xslUrl('sitemap.xsl');
        if ($xsl !== null) { $pi = '<?xml-stylesheet type="text/xsl" href="' . self::xesc($xsl) . '"?>' . "\n"; }
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . $pi
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
            . 'xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        // 首页
        $polyOn = Poly::on();
        $default = Poly::defaultLang();
        $langs = $polyOn ? array_values(array_filter(Poly::enabled(), fn($c) => $c !== $default)) : [];
        $out .= self::url($base . '/', '', '1.0');
        foreach ($langs as $lc) {
            $out .= self::url($base . '/' . $lc . '/', '', '0.8');
        }

        // 分类
        $cats = Db::rows("SELECT slug, `count` FROM {$T}metas WHERE type='category' ORDER BY `order` ASC, id ASC");
        foreach ($cats as $c) {
            if ($c['count'] > 0) { $out .= self::url($base . '/category/' . $c['slug'] . '/', '', '0.6'); }
        }
        // 页面
        $pages = Db::rows("SELECT slug FROM {$T}contents WHERE type='page' AND status='publish' AND deleted_at IS NULL");
        foreach ($pages as $p) { $out .= self::url($base . '/' . $p['slug'] . '.html', '', '0.7'); }

        // 文章：基准语言条目 + 每启用语言译文条目与 alternate
        $posts = Db::rows("SELECT id, slug, published_at, updated_at FROM {$T}contents WHERE type='post' AND status='publish' AND deleted_at IS NULL ORDER BY id ASC");
        $polyByLang = [];
        if ($polyOn) {
            foreach ($langs as $lc) {
                $rows = Db::rows('SELECT group_id, slug, updated_at FROM ' . Poly::table() . " WHERE lang=? AND status='publish'", [$lc]);
                $polyByLang[$lc] = [];
                foreach ($rows as $e) { $polyByLang[$lc][(int)$e['group_id']] = $e; }
            }
        }

        foreach ($posts as $p) {
            $zh = $base . '/' . $p['slug'] . '.html';
            $last = (string)($p['published_at'] ?? $p['updated_at']);
            $alt = '';
            if ($polyOn) { $alt = '<xhtml:link rel="alternate" hreflang="' . $default . '" href="' . self::xesc($zh) . '"/>'; }
            foreach ($langs as $lc) {
                if (isset($polyByLang[$lc][(int)$p['id']])) {
                    $row = $polyByLang[$lc][(int)$p['id']];
                    $u = $base . '/' . $lc . '/' . $row['slug'] . '.html';
                    $alt .= '<xhtml:link rel="alternate" hreflang="' . $lc . '" href="' . self::xesc($u) . '"/>';
                    $out .= self::url($u, $last, '0.8', $alt);
                }
            }
            $out .= self::url($zh, $last, '0.8', $alt);
        }
        return $out . '</urlset>';
    }

    private static function url(string $loc, string $lastmod, string $priority, string $alternates = ''): string
    {
        $mod = $lastmod !== '' ? '<lastmod>' . date('Y-m-d', strtotime($lastmod)) . '</lastmod>' : '';
        return '<url><loc>' . self::xesc($loc) . '</loc>' . $mod . '<priority>' . $priority . '</priority>' . $alternates . '</url>' . "\n";
    }
}
