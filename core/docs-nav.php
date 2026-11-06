<?php
/**
 * 官网文档侧栏的共用数据源（内核功能块 与 主题 partial 共用，服务端直出、零 JS）
 *  - yc_docs_nav()          文档中心 + docs-* 章节页，含英文标题
 *  - yc_recent_releases()   /changelog 最近 N 个版本，附与 core/Changelog.php 同规则的深链锚点；
 *                           非默认语言优先读该语言的 changelog 译文标题
 *  - yc_side_block_html()   单个 side-box HTML（about / docs / recent / resources）
 *  - yc_side_blocks_html()  about + docs + recent + resources（主题 partial 用）
 * index.php 的「页面布局」功能块与 themes/yecha/views/partials/sidebar.php 都调用这里，
 * 避免中文界面与译文界面各写一套清单。
 */
if (!function_exists('yc_docs_nav')) {
    function yc_docs_nav(): array
    {
        static $cache = null;
        if ($cache !== null) { return $cache; }
        $T = Db::t('contents');
        $P = Db::t('poly_contents');
        $sql = "SELECT c.slug, c.title AS tzh, p.title AS ten
                FROM {$T} c
                LEFT JOIN {$P} p ON p.group_id = c.id AND p.lang = 'en' AND p.status = 'publish'
                WHERE c.type = 'page' AND c.status = 'publish' AND c.deleted_at IS NULL
                  AND (c.slug = 'docs' OR c.slug LIKE 'docs-%')
                ORDER BY c.`order` ASC, c.id ASC LIMIT 40";
        $out = [];
        foreach ((array)Db::rows($sql) as $r) {
            $ten = (string)($r['ten'] ?? '');
            $out[] = [
                'slug' => (string)$r['slug'],
                'zh'   => (string)$r['tzh'],
                'en'   => $ten !== '' ? $ten : (string)$r['tzh'],
            ];
        }
        return $cache = $out;
    }

    function yc_recent_releases(int $n = 4): array
    {
        static $cache = null;
        if ($cache !== null) { return $cache; }
        $T = Db::t('contents');
        $base = Db::row("SELECT id, content FROM {$T} WHERE slug='changelog' AND status='publish' AND deleted_at IS NULL LIMIT 1");
        $md = (string)($base['content'] ?? '');
        $lang = Lang::short();
        if ($md !== '' && class_exists('Poly') && Poly::on() && $lang !== Poly::defaultLang()) {
            $P = Db::t('poly_contents');
            $tr = Db::row("SELECT content FROM {$P} WHERE group_id=? AND lang=? AND status='publish' LIMIT 1",
                [(int)($base['id'] ?? 0), $lang]);
            $ten = trim((string)($tr['content'] ?? ''));
            if ($ten !== '') { $md = $ten; }
        }
        $out = [];
        if ($md !== '' && preg_match_all('/^##\s+(\d{4}-\d{2}-\d{2})\s*[·・]\s*YeCha\s*(\S+)\s*[·・]\s*(.+)$/mu', $md, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $v = strtolower(trim((string)preg_replace('/[^a-z0-9]+/', '-', (string)$x[2]), '-'));
                $out[] = [
                    'date'   => (string)$x[1],
                    'ver'    => (string)$x[2],
                    'title'  => trim((string)$x[3]),
                    'anchor' => $v !== '' ? 'v' . $v : '',
                ];
                if (count($out) >= $n) { break; }
            }
        }
        return $cache = $out;
    }

    /** 单个侧栏块 HTML；$which = about | docs | recent | resources */
    function yc_side_block_html(string $which): string
    {
        $en = Lang::isEn();
        $lp = ui_lp();
        $box = function (string $title, string $inner, string $headLink = ''): string {
            $h = $headLink !== '' ? '<a href="' . e($headLink) . '">' . e($title) . '</a>' : e($title);
            return '<div class="side-box"><h4>' . $h . '</h4>' . $inner . '</div>';
        };

        if ($which === 'about') {
            $bio = (string)get_option('blogger.bio', '');
            $nick = trim((string)get_option('blogger.nick', ''));
            if ($en) {
                $bioEn = (string)get_option('blogger.bio_en', '');
                if ($bioEn !== '') { $bio = $bioEn; }
                $nickEn = trim((string)get_option('blogger.nick_en', ''));
                if ($nickEn !== '') { $nick = $nickEn; }
            }
            $inner = ($nick !== '' ? '<div class="side-nick" style="font-weight:700;margin-bottom:6px;">' . e($nick) . '</div>' : '')
                . '<div class="side-bio">' . e(Str::excerpt($bio, 70)) . '</div>';
            return $box((string)__('sb.about_site'), $inner, $lp . '/about.html');
        }

        if ($which === 'docs') {
            $li = '';
            foreach (yc_docs_nav() as $d) {
                $href = $lp . '/' . ($d['slug'] === 'docs' ? 'docs' : $d['slug'] . '.html');
                $li .= '<li><a href="' . e($href) . '">' . e($en ? $d['en'] : $d['zh']) . '</a></li>';
            }
            $li .= '<li><a href="' . e($lp . '/developers.html') . '">' . e($en ? 'Developer docs' : '开发文档') . '</a></li>';
            return $box((string)__('sb.docs'), '<ul class="side-list">' . $li . '</ul>', $lp . '/docs');
        }

        if ($which === 'recent') {
            $li = '';
            foreach (yc_recent_releases(4) as $r) {
                $href = $lp . '/changelog' . ($r['anchor'] !== '' ? '#' . $r['anchor'] : '');
                $li .= '<li><a href="' . e($href) . '"><b>' . e($r['ver']) . '</b> · ' . e($r['date'])
                    . '<span class="d">' . e(Str::excerpt($r['title'], 22)) . '</span></a></li>';
            }
            if ($li === '') { return ''; }
            return $box((string)__('sb.recent'), '<ul class="side-list">' . $li . '</ul>', $lp . '/changelog');
        }

        if ($which === 'resources') {
            $items = [
                [$lp . '/download.html', '下载安装包', 'Packages'],
                [$lp . '/theme',         '主题广场',   'Themes'],
                [$lp . '/plugin',        '插件市场',   'Plugins'],
                [$lp . '/enterprise',    '企业主题',   'Enterprise themes'],
                [$lp . '/forum.html',    '社区论坛',   'Forum'],
            ];
            $li = '';
            foreach ($items as $it) {
                $li .= '<li><a href="' . e($it[0]) . '">' . e($en ? $it[2] : $it[1]) . '</a></li>';
            }
            return $box((string)__('sb.resources'), '<ul class="side-list">' . $li . '</ul>');
        }
        return '';
    }

    function yc_side_blocks_html(): string
    {
        $h = '';
        foreach (['about', 'docs', 'recent', 'resources'] as $w) {
            $h .= yc_side_block_html($w);
        }
        return $h;
    }
}
