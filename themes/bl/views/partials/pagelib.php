<?php
/** lqpg 页面组件（★ 精仿第 2 批 2026-10-05）
 * ===========================================================================
 * 全部函数都在 `if (!function_exists('lqpg_ctx'))` 守卫里 ⇒ 同一请求重复 include 安全
 * （list.php 会被渲染多次：列表 + 侧栏 + 相关块）。
 */

/* =============================================================== 栏目首页「全量」取数 */
if (!function_exists('lqpg_store_all')) {
    /** ★ 2026-10-05（精仿第 3 批 · 像素轮）
     *  参照的 5 个栏目**索引页**是「全量单页」形态：/tips 一次给 78 条、/tool 一次给全部、
     *  /gallery 一次给 288 张（`seo/*.html` 是整页预渲染，没有第二页）。
     *  而实例的 section 列表走内核分页（`Front::sectionIndex`，per_page=30）⇒ 首屏只有 30 条
     *  ⇒ 与参照已经不是"像不像"的问题，是**内容量差 2.6 倍**（实测 /tips 参照 78 卡 / 实例 30 卡，
     *  文档高 6976 vs 3140）；归一化差异 2.377% 里最主要的成分就是这个。
     *  修法（只在呈现层、只对 /tips /tool /gallery 三个 store 的**第 1 页**）：
     *  仍走内核同一套 postWhere() 口径与同一排序，仅把 LIMIT/OFFSET 放开 ⇒ 数据口径不变。
     *  ★ 分页页（/tips/page/2…）与 /tips 的 pager **一个都不删**，功能不丢。
     *  返回 null = 不用全量（走内核原样）。 */
    function lqpg_store_all(string $section, int $page): ?array
    {
        if ($page > 1) { return null; }
        if (!in_array($section, ['tips', 'tool', 'gallery'], true)) { return null; }
        $prefix = $section . '__';
        $rows = Db::rows(
            'SELECT id, title, slug, excerpt, description, views, published_at, created_at FROM ' . Db::t('contents')
            . " WHERE LEFT(slug, " . strlen($prefix) . ")=? AND type='post' AND status='publish' AND deleted_at IS NULL"
            . ' ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT 600',
            [$prefix]
        );
        if (!$rows) { return null; }
        foreach ($rows as &$r) {
            $sg = (string)$r['slug'];
            if (str_contains($sg, '__')) {
                $r['slug'] = substr($sg, 0, strpos($sg, '__')) . '/' . substr($sg, strpos($sg, '__') + 2);
                $r['updated_at'] = (string)($r['published_at'] ?? '');
            }
        }
        unset($r);
        return $rows;
    }
}

/* =============================================================== 面包屑条目 */
if (!function_exists('lqpg_sec_crumbs')) {
    /** 栏目页面包屑条目（与参照 /tips 的三段一致：首页 › 浏览器学堂 › 本页） */
    function lqpg_sec_crumbs(string $section, int $page, string $pageTitle, string $label): array
    {
        $lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
        $cur = $pageTitle !== '' ? $pageTitle : $label;
        if ($page > 1) { $cur .= ' · 第 ' . $page . ' 页'; }
        $items = [['首页', $lp . '/']];
        if (in_array($section, ['tips', 'learn'], true)) { $items[] = ['浏览器学堂', $lp . '/learn']; }
        $items[] = [$cur, ''];
        return $items;
    }
}

/* =============================================================== 动作 chips */
if (!function_exists('lqpg_list_chips')) {
    /** 标题右侧的两个动作 chip（参照 /tips 有：去浏览器学堂 / Chrome 专题课） */
    function lqpg_list_chips(string $section, string $lp): string
    {
        $chips = [];
        if ($section === 'tips') {
            $chips = [['/learn', '去浏览器学堂'], ['/learn/chrome', 'Chrome 专题课']];
        } elseif ($section === 'tool') {
            $chips = [['/learn', '去浏览器学堂'], ['/download', '浏览器下载']];
        }
        $h = '';
        foreach ($chips as $c) {
            if (!lqpg_href_ok($c[0])) { continue; }
            $h .= '<a class="inline-flex items-center gap-1 rounded-md border bg-card px-3 py-1.5 text-sm font-medium text-muted-foreground transition hover:border-primary hover:text-primary" href="'
                . e($lp . $c[0]) . '">' . e($c[1]) . lqwww_ico('arrow-right', 'h-3.5 w-3.5') . '</a>';
        }
        return $h;
    }
}

if (!function_exists('lqpg_href_ok')) {
    /** 目标路径在实例里是否真有对应内容行（★ 不指向 404） */
    function lqpg_href_ok(string $href): bool
    {
        static $c = [];
        if (isset($c[$href])) { return $c[$href]; }
        $path = (string)parse_url($href, PHP_URL_PATH);
        $map = ['/learn', '/tips', '/tool', '/download', '/plugins', '/mobile',
                '/bookmark'];
        if (in_array($path, $map, true)) { return $c[$href] = true; }
        if (preg_match('#^/(learn|tips|tool|download|gallery)/([a-z0-9\-]+)$#', $path, $m)) {
            return $c[$href] = ((int)Db::one(
                'SELECT COUNT(*) FROM ' . Db::t('contents')
                . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND slug=?",
                [$m[1] . '__' . $m[2]]
            )) > 0;
        }
        return $c[$href] = false;
    }
}

/* =============================================================== /tool 分组 */
if (!function_exists('lqpg_tool_sections')) {
    /** /tool：参照把工具按分类分成多个 <section>（h2 = 分类名 + 条数 + 4 列卡） */
    function lqpg_tool_sections(array $posts, string $lp): string
    {
        $catName = [];
        if (function_exists('yc_cat_table')) {
            foreach (yc_cat_table() as $g) { $catName[(string)$g[0]] = (string)$g[1]; }
        }
        $groups = [];
        foreach ($posts as $p) {
            $store = lqwww_store_slug((string)$p['slug']);
            $tail  = (string)preg_replace('/^tool__/', '', $store);
            $ck    = function_exists('yc_tool_cat') ? yc_tool_cat($tail, (string)($p['description'] ?? '')) : 'text';
            $groups[$ck][] = $p;
        }
        $h = '';
        foreach ($groups as $ck => $rows) {
            $cells = '';
            foreach ($rows as $p) {
                $store = lqwww_store_slug((string)$p['slug']);
                $tail  = (string)preg_replace('/^tool__/', '', $store);
                $face  = lqskel_tool_face((string)$ck);
                $cells .= lqskel_fill(lqskel('list_item_tool4'), [
                    'href'            => e($lp . '/tool/' . $tail),
                    'icon_span_class' => 'bg-muted ' . e($face[1]),
                    'icon'            => lqwww_ico($face[0], 'h-5 w-5'),
                    'name'            => e(lqwww_tool_title($store, (string)$p['title'])),
                    'lead'            => e(lqwww_lead((array)$p, 110)),
                    'count'           => e('打开工具 →'),
                ], 'tool4');
            }
            $h .= lqskel_fill(lqskel('list_tool_section'), [
                'name'  => e((string)($catName[$ck] ?? $ck)),
                'items' => $cells,
            ], 'tool_section');
        }
        return $h;
    }
}

/* =============================================================== /gallery */
if (!function_exists('lqpg_gallery_sections')) {
    /** /gallery：参照每个分类一个 <section>（6 列图墙；实例盘上无图 ⇒ 参照的空态盒，不编图） */
    function lqpg_gallery_sections(array $posts, string $lp): string
    {
        /* ★ 2026-10-06：图墙的图片位以前硬编码空盒 ⇒ 现取内容行里的真 <img>
           （每类 48 张 dl.liulanqi.com 1080p，标记 = 参照自己的：aspect-[16/10] + loading=lazy + alt）。
           只补呈现层：不新造图源、不外链第三方。 */
        $galPics = [];
        foreach (Db::rows(
            'SELECT slug, content FROM ' . Db::t('contents')
            . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND slug LIKE 'gallery\\_\\_%'"
        ) as $gr) {
            if (preg_match_all('#<img\b[^>]*>#i', (string)$gr['content'], $gmm)) { $galPics[(string)$gr['slug']] = $gmm[0]; }
        }
        $h = '';
        foreach ($posts as $p) {
            $store = lqwww_store_slug((string)$p['slug']);
            $slug  = (string)preg_replace('/^gallery__/', '', $store);
            $title = lqwww_tool_title($store, (string)$p['title']);
            $n = 0;
            if (preg_match('/（(\d+)\s*张）/u', $title, $m)) { $n = (int)$m[1]; }
            $name = trim((string)preg_replace('/（\d+\s*张）/u', '', $title));
            $name = trim((string)preg_replace('/\s*-\s*灵感画廊.*$/u', '', $name));
            $cells = '';
            /* ★ 参照每个分类给 48 张（6 列 × 8 行）；旧稿固定 12 张 ⇒ 段高 345 vs 参照 1296，
               整页矮/错位。改成取参照同量（48），并保留空态盒（实例盘上无图，不编图）。 */
            $tile = max(12, (int)($n > 0 ? ceil($n / 6) * 6 : 48));
            $pics = $galPics['gallery__' . $slug] ?? [];
            $np   = count($pics);
            for ($i = 0; $i < $tile; $i++) {
                $cells .= lqskel_fill(lqskel('gal_item'), [
                    'href'    => e($lp . '/gallery/' . $slug),
                    'img'     => $np ? $pics[$i % $np] : '<div class="aspect-[16/10] w-full"></div>',
                    'caption' => e($name),
                ], 'gal_item');
            }
            $h .= lqskel_fill(lqskel('gal_section'), [
                'slug'      => e($slug),
                'name'      => e($n > 0 ? $name . '（' . $n . ' 张）' : $name),
                'more_href' => e($lp . '/gallery/' . $slug),
                'more'      => e('查看全部 ' . $n . ' 张' . $name . '壁纸 →'),
                'items'     => $cells,
            ], 'gal_section');
        }
        return $h;
    }
}

/* =============================================================== /learn 段 */

if (!function_exists('lqart_gallery_cat_html')) {
    function lqart_gallery_cat_html(array $p, string $html, string $lp): string
    {
        $cells = '';
        if (preg_match_all('#<img\b[^>]*>#i', $html, $mm)) {
            foreach ($mm[0] as $tag) {
                $alt = '';
                if (preg_match('/\balt="([^"]*)"/i', $tag, $am)) { $alt = $am[1]; }
                $cells .= '<div class="group"><div class="overflow-hidden rounded-lg border bg-muted">'
                        . $tag . '</div>'
                        . '<div class="mt-1.5 truncate text-xs text-muted-foreground">' . e($alt) . '</div></div>';
            }
        }
        if ($cells === '') {
            $cells = '<div class="rounded-xl border bg-card p-6 text-sm text-muted-foreground">'
                   . e('no-image') . '</div>';
        }
        $store = lqwww_store_slug((string)$p['slug']);
        $slug  = (string)preg_replace('/^gallery__/', '', $store);
        $title = lqwww_tool_title($store, (string)$p['title']);
        /* title = "X壁纸（48 张） - 灵感画廊" ⇒ 取 X；并把 h1 拼成参照同一串（含 <!-- --> 分隔符） */
        $name  = trim((string)preg_replace('/\x{FF08}\d+\s*\x{5F20}\x{FF09}/u', '', $title));
        $name  = trim((string)preg_replace('/\s*[-\x{2014}\x{2013}]\s*\S*$/u', '', $name));
        $name  = (string)preg_replace('/\x{58C1}\x{7EB8}$/u', '', $name);
        if ($name === '') { $name = $slug; }
        $nPic  = (int)preg_match_all('/<img\b[^>]*>/i', $html);
        $h1Txt = $name . "<!-- -->壁纸（<!-- -->" . ($nPic > 0 ? $nPic : 48) . "<!-- --> 张）";
        $lead  = "liulanqi 灵感画廊 · <!-- -->" . $name . "<!-- -->风格，支持 1080p / 2K / 4K / 手机分辨率下载。";
        $pills = '';
        /* 参照的 pill 顺序是**字母序的 slug**（tech/minimal/abstract/mesh/waves/geo），不是 SQL 序 */
        $__gorder = ['tech', 'minimal', 'abstract', 'mesh', 'waves', 'geo'];
        $__grows  = Db::rows('SELECT slug, title FROM ' . Db::t('contents')
            . " WHERE LEFT(slug, ?)=? AND status='publish' AND deleted_at IS NULL",
            [strlen('gallery__'), 'gallery__']);
        $__gmap = [];
        foreach ($__grows as $__gr) { $__gmap[(string)preg_replace('/^gallery__/', '', (string)$__gr['slug'])] = (string)$__gr['title']; }
        $__glist = [];
        foreach ($__gorder as $__gs) { if (isset($__gmap[$__gs]) && $__gs !== $slug) { $__glist[] = ['slug' => $__gs, 'title' => $__gmap[$__gs]]; } }
        foreach ($__gmap as $__gs => $__gt) {
            if ($__gs === $slug) { continue; }
            $ok2 = false; foreach ($__glist as $__x) { if ($__x['slug'] === $__gs) { $ok2 = true; break; } }
            if (!$ok2) { $__glist[] = ['slug' => $__gs, 'title' => $__gt]; }
        }
        foreach ($__glist as $g) {
            $gs = (string)preg_replace('/^gallery__/', '', (string)$g['slug']);
            if ($gs === $slug) { continue; }
            $gt = lqwww_tool_title((string)$g['slug'], (string)$g['title']);
            $gn = trim((string)preg_replace('/\x{FF08}\d+\s*\x{5F20}\x{FF09}/u', '', $gt));
            $gn = trim((string)preg_replace('/\s*[-\x{2014}\x{2013}]\s*\S*$/u', '', $gn));
            $gn = (string)preg_replace('/\x{58C1}\x{7EB8}$/u', '', $gn);
            $pills .= '<a class="rounded-full border px-3 py-1 text-sm text-muted-foreground hover:bg-accent hover:text-foreground" href="'
                    . e($lp . '/gallery/' . $gs) . '">' . e($gn) . '</a>';
        }
        $crumb = function_exists('lqpg_crumbs_nav')
            ? lqpg_crumbs_nav([["首页", '/'], ["灵感画廊", $lp . '/gallery'], [$name, '']])
            : '';
        return '<div class="space-y-6">' . $crumb
             . '<div class=""><h1 class="text-3xl font-bold">' . $h1Txt . '</h1>'
             . '<p class="mt-2 text-muted-foreground">' . $lead . '</p></div>'
             . '<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">' . $cells . '</div>'
             . ($pills !== '' ? '<div class="flex flex-wrap gap-2">' . $pills . '</div>' : '')
             . '</div>';
    }
}

if (!function_exists('lqpg_learn_hero')) {
    function lqpg_learn_hero(string $h1, string $lead, int $total, int $pages): string
    {
        return lqskel_fill(lqskel('learn_hero'), [
            'h1'        => e($h1),
            'lead'      => e($lead !== '' ? $lead : ('从选型、下载、设置到技巧与隐私，' . $total . ' 篇图文与浏览器专题。')),
            'hero_icon' => lqwww_ico('sparkles', 'h-6 w-6'),
        ], 'learn_hero');
    }
}

if (!function_exists('lqpg_learn_courses')) {
    function lqpg_learn_courses(string $lp): string
    {
        $want = ['chrome', 'edge', 'firefox', 'quark', 'yandex', 'opera'];
        $face = [
            'chrome'  => ['#2563eb', 'C'], 'edge'    => ['#0ea5e9', 'E'],
            'firefox' => ['#f97316', 'F'], 'quark'   => ['#7c3aed', '夸'],
            'yandex'  => ['#dc2626', 'Y'], 'opera'   => ['#c026d3', 'O'],
        ];
        $items = '';
        foreach ($want as $s) {
            $row = Db::row('SELECT slug, title, description, excerpt FROM ' . Db::t('contents')
                . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND slug=? LIMIT 1", ['learn__' . $s]);
            if (!$row) { continue; }
            $items .= lqskel_fill(lqskel('learn_item_course'), [
                'href'   => e($lp . '/learn/' . $s),
                'color'  => (string)($face[$s][0] ?? '#2563eb'),
                'letter' => e((string)($face[$s][1] ?? 'A')),
                'title'  => e((string)preg_replace('/[：:].*$/u', '', (string)$row['title'])),
                'desc'   => e(lqwww_lead((array)$row, 60)),
            ], 'learn_course');
        }
        return lqskel_fill(lqskel('learn_section_courses'), [
            'h2' => '浏览器专题课程', 'right' => '图文教程 · 从入门到精通', 'items' => $items,
        ], 'sec_courses');
    }
}

if (!function_exists('lqpg_learn_reads')) {
    function lqpg_learn_reads(string $lp): string
    {
        $pick = ['browser-rec', 'download-browser', 'default-browser', 'newtab-nav', 'shortcuts', 'clear-cache'];
        $items = '';
        foreach ($pick as $s) {
            $row = Db::row('SELECT slug, title, description, excerpt FROM ' . Db::t('contents')
                . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND slug=? LIMIT 1", ['tips__' . $s]);
            if (!$row) { continue; }
            $items .= lqskel_fill(lqskel('learn_item_read'), [
                'href'  => e($lp . '/tips/' . $s),
                'icon'  => lqwww_ico('file-text', 'h-4 w-4'),
                'title' => e(lqwww_tool_title('tips__' . $s, (string)$row['title'])),
                'desc'  => e(lqwww_lead((array)$row, 90)),
            ], 'learn_read');
        }
        $more = lqskel_fill(lqskel('learn_morelink'), [
            'href'  => e($lp . '/tips'),
            'label' => e('全部技巧'),
            'more'  => e('全部技巧'),
        ], 'learn_morelink');
        return lqskel_fill(lqskel('learn_section_reads'), [
            'h2' => '推荐阅读', 'more' => $more, 'items' => $items,
        ], 'sec_reads');
    }
}

if (!function_exists('lqpg_learn_topics')) {
    function lqpg_learn_topics(string $lp): string
    {
        $topics = [
            ['浏览器怎么选', '内核、横评、双核与极速浏览器对比', ['browser-kernel', 'browser-rec', 'mobile-browser', 'dual-core', 'fast-browser']],
            ['下载与安装', '官方渠道下载、卸载重装、设默认浏览器', ['download-browser', 'uninstall-browser', 'default-browser']],
            ['起步与主页', '主页、新标签页、搜索引擎与起点', ['homepage', 'newtab-nav', 'search-engine', 'liyu-liulanqi']],
            ['效率操作', '快捷键、标签管理、历史、缓存与截图', ['shortcuts', 'tab-manage', 'browser-history', 'clear-cache', 'web-screenshot', 'save-webpage']],
            ['翻译与阅读', '网页翻译、翻译插件与浏览器看 PDF', ['web-translate', 'translate-ext', 'pdf-reader']],
            ['书签与同步', '导入、导出、云同步，收藏夹不再丢', ['bookmark-import', 'bookmark-export', 'bookmark-sync']],
            ['隐私与安全', '隐私浏览器、无痕、广告拦截与密码', ['privacy-browser', 'incognito', 'adblock', 'password-manager', 'clear-cookies']],
            ['各浏览器技巧', 'Chrome / Edge / 夸克 / UC / Yandex / Opera', ['chrome-tips', 'edge', 'quark', 'uc', 'yandex-tips', 'opera-web']],
            ['插件与工具', '插件推荐、在线工具与开发者工具入门', ['plugin-rec', 'online-tools', 'devtools']],
        ];
        $cards = '';
        foreach ($topics as $t) {
            $links = '';
            foreach ($t[2] as $s) {
                $row = Db::row('SELECT title FROM ' . Db::t('contents')
                    . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND slug=? LIMIT 1", ['tips__' . $s]);
                if (!$row) { continue; }
                $links .= lqskel_fill(lqskel('learn_item_topiclink'), [
                    'href'  => e($lp . '/tips/' . $s),
                    'label' => e(lqwww_tool_title('tips__' . $s, (string)$row['title'])),
                ], 'learn_topiclink');
            }
            if ($links === '') { continue; }
            $cards .= lqskel_fill(lqskel('learn_item_topic'), [
                'icon'  => lqwww_ico('compass', 'h-4 w-4'),
                'title' => e($t[0]),
                'desc'  => e($t[1]),
                'links' => $links,
            ], 'learn_topic');
        }
        return lqskel_fill(lqskel('learn_section_topics'), ['h2' => '按主题学习', 'items' => $cards], 'sec_topics');
    }
}

if (!function_exists('lqpg_learn_recent')) {
    function lqpg_learn_recent(string $lp): string
    {
        $rows = Db::rows('SELECT slug, title, description, excerpt, updated_at, published_at, created_at, views FROM '
            . Db::t('contents') . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND slug LIKE 'tips\\_\\_%'"
            . ' ORDER BY COALESCE(updated_at, published_at, created_at) DESC, id DESC LIMIT 8');
        $items = '';
        foreach ($rows as $r) {
            $store = lqwww_store_slug((string)$r['slug']);
            $slug  = (string)preg_replace('/^tips__/', '', $store);
            $items .= lqskel_fill(lqskel('learn_item_read'), [
                'href'  => e($lp . '/tips/' . $slug),
                'icon'  => lqwww_ico('file-text', 'h-4 w-4'),
                'title' => e(lqwww_tool_title($store, (string)$r['title'])),
                'desc'  => e(lqwww_lead((array)$r, 90)),
            ], 'learn_recent');
        }
        return lqskel_fill(lqskel('learn_section_recent'), ['h2' => '最近更新', 'items' => $items], 'sec_recent');
    }
}

if (!function_exists('lqpg_learn_more')) {
    function lqpg_learn_more(string $lp): string
    {
        $more = [
            ['/download', '浏览器下载', 'Chrome / Firefox / Brave / Edge 官方下载'],
            ['/plugins', '浏览器插件', '新标签页扩展与图片工具'],
            ['/tool', '在线工具箱', '100+ 免费在线工具，本地处理'],
            ['/bookmark', '书签导航', '常用网站分组收藏与管理'],
        ];
        $items = '';
        foreach ($more as $m) {
            if (!lqpg_href_ok($m[0])) { continue; }
            $items .= lqskel_fill(lqskel('learn_item_more'), [
                'href'  => e($lp . $m[0]),
                'icon'  => lqwww_ico('external-link', 'h-4 w-4'),
                'title' => e($m[1]),
                'desc'  => e($m[2]),
            ], 'learn_more');
        }
        return lqskel_fill(lqskel('learn_section_more'), ['h2' => '更多资源', 'items' => $items], 'sec_more');
    }
}
