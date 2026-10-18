<?php
/**
 * ChaWiki 主题 · URL 助手（URL 方案 B：保留旧站形态 /entry/{slug}，零 301）
 *
 * 为什么需要：内核文章路由是 /{slug}.html（Router 里写死），而站长已批【URL 方案 B：/entry/{slug} 不变】。
 *   内核侧只加了一条「旧站前缀归一」分支（core/Router.php 的 cha.wiki 补丁），
 *   canonical / og:url / 站内链接统一由本主题按同一前缀生成 ⇒ 全站链接一致、可点、零 301。
 *
 * 前缀来源优先级：
 *   ① 选项 content.base_path（可后台改成别的前缀，如 /wiki）
 *   ② 空串（= 用内核原生 /{slug}.html 形态）
 */

if (!function_exists('chawiki_base_path')) {
    /** 词条路径前缀，如 '/entry'（返回时已 strip 尾斜杠；空串表示不用前缀） */
    function chawiki_base_path(): string
    {
        /* ★ URL 统一：与内核 yc_entry_base() 共用同一实现（避免两处逻辑漂移） */
        if (function_exists('yc_entry_base')) { return yc_entry_base(); }
        $base = (string)get_option('content.base_path', '/entry');
        $base = '/' . trim($base, '/');
        return $base === '/' ? '' : $base;
    }
}

if (!function_exists('chawiki_prefix')) {
    /** 当前语言前缀（多语未开时为空串） */
    function chawiki_prefix(): string
    {
        return Poly::on() && Lang::short() !== Poly::defaultLang() ? ui_lp() : '';
    }
}

if (!function_exists('chawiki_entry_url')) {
    /** 词条/独立页 URL（不带语言前缀）：/entry/{slug} */
    function chawiki_entry_url(string $slug): string
    {
        /* ★ URL 统一：与内核 yc_entry_url() 共用同一实现（避免两处逻辑漂移） */
        if (function_exists('yc_entry_url')) { return yc_entry_url($slug); }
        return chawiki_base_path() . '/' . rawurlencode($slug);
    }
}

if (!function_exists('chawiki_url')) {
    /** 词条 URL（带当前语言前缀） */
    function chawiki_url(string $slug): string
    {
        return chawiki_prefix() . chawiki_entry_url($slug);
    }
}

/* ============================================================
   ★ Wiki 版式补丁（2026-09-18）· 新增助手（不改动上面任何既有函数）
   ============================================================ */

if (!function_exists('chawiki_route_ready')) {
    /**
     * 功能路由是否已上线 —— 读 core/Router.php 源码做「段名标记」探测（进程内缓存一次）。
     *
     * 为什么需要：词条页顶部的【编辑】/【历史】入口与「wiki 功能线」并行开发，
     * 对方尚未上线 /entry/{slug}/edit 与 /entry/{slug}/history 时，直接渲染链接 = 给读者一个 404 死链。
     * 这里按「路由源码里是否出现该段名」判定：
     *   · 已上线 ⇒ 渲染真链接（编辑仅管理员可见）
     *   · 未上线 ⇒ 非管理员完全不渲染；管理员渲染成灰色「（即将可用）」占位
     * 对方一上线（Router.php 里出现 'edit' / 'history' 段）本函数自动变 true，主题侧无需再改代码。
     */
    function chawiki_route_ready(string $seg): bool
    {
        static $src = null;
        if ($src === null) {
            $f = dirname(__DIR__, 2) . '/core/Router.php';
            $src = is_file($f) ? (string)@file_get_contents($f) : '';
        }
        if ($src === '' || $seg === '') { return false; }
        return preg_match('#[\'"/]' . preg_quote($seg, '#') . '[\'"/]#', $src) === 1;
    }
}

if (!function_exists('chawiki_left_tree')) {
    /**
     * 左栏「分类 → 词条」树（数据层，走内核块缓存 Cache::blocks，每页 0 条 SQL）。
     *
     * ★ 与旧实现的差别：给每个分类补了真实词条数 n（ye_metas.count）；
     *   并把「取树」从 layout.php 抽到这里，供 layout（左栏）与 post（相关词条）共用；
     *   缓存键升到 leftnav4（结构变了，避免读到旧结构缓存）。
     * 入参 $useLangTitles：多语译文界面下，分组名/词条标题取当前语言译文。
     */
    function chawiki_left_tree(string $activeLang, bool $useLangTitles): array
    {
        static $memo = [];
        $key = 'leftnav4:' . $activeLang . ($useLangTitles ? ':t' : '');
        if (isset($memo[$key])) { return $memo[$key]; }

        $raw = Cache::get('blocks', $key);
        $tree = null;
        if ($raw !== false) {
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) { $tree = $decoded; }
        }
        if ($tree === null) {
            $tree = [];
            $lcats = Db::rows('SELECT m.id, m.name, m.names_i18n, m.slug, m.count FROM ' . Db::t('metas')
                . " m WHERE m.type='category' AND m.count>0 ORDER BY m.`order` ASC, m.id ASC LIMIT 30");
            foreach ($lcats as $lc) {
                $lrows = Db::rows(
                    'SELECT c.id, c.slug, c.title FROM ' . Db::t('relationships') . ' r JOIN ' . Db::t('contents') . ' c ON c.id=r.content_id '
                    . "WHERE r.meta_id=? AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL ORDER BY c.published_at ASC, c.id ASC LIMIT 30",
                    [(int)$lc['id']]
                );
                if ($lrows === []) { continue; }
                $items = [];
                $polyTitles = [];
                if ($useLangTitles) {
                    $cids = array_column($lrows, 'id');
                    $in = implode(',', array_fill(0, count($cids), '?'));
                    foreach (Db::rows('SELECT group_id, title, status FROM ' . Poly::table() . " WHERE lang=? AND group_id IN ($in)", array_merge([$activeLang], array_map('intval', $cids))) as $pt) {
                        if (($pt['status'] ?? '') === 'publish') { $polyTitles[(int)$pt['group_id']] = (string)$pt['title']; }
                    }
                }
                foreach ($lrows as $lr) {
                    $title = $useLangTitles && isset($polyTitles[(int)$lr['id']]) ? $polyTitles[(int)$lr['id']] : (string)$lr['title'];
                    $items[] = ['title' => $title, 'slug' => (string)$lr['slug']];
                }
                $catName = (string)$lc['name'];
                if ($useLangTitles) { $cn = Poly::termLang($lc, $activeLang); if ($cn !== '') { $catName = $cn; } }
                $tree[] = [
                    'name'  => $catName,
                    'slug'  => (string)$lc['slug'],
                    'n'     => (int)$lc['count'],
                    'items' => $items,
                ];
            }
            Cache::set('blocks', $key, (string)json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 600);
        }
        return $memo[$key] = $tree;
    }
}

if (!function_exists('chawiki_tree_mark')) {
    /**
     * 给树打「当前位置」标记：每条词条 on、每个分组 on。
     * ★ 兼容三种入口形态（URL 统一的成果不能丢）：
     *   /entry/{slug} · /entry/{slug}.html · /{slug}.html
     *   以及分类页 /category/{slug}/ ⇒ 该分组 on。
     */
    function chawiki_tree_mark(array $tree, string $curSlug, string $curCatSlug = ''): array
    {
        foreach ($tree as $gi => $g) {
            $grpOn = ($curCatSlug !== '' && (string)($g['slug'] ?? '') === $curCatSlug);
            foreach (($g['items'] ?? []) as $ii => $it) {
                $isOn = $curSlug !== '' && (string)($it['slug'] ?? '') === $curSlug;
                $tree[$gi]['items'][$ii]['on'] = $isOn;
                if ($isOn) { $grpOn = true; }
            }
            $tree[$gi]['on'] = $grpOn;
        }
        return $tree;
    }
}

if (!function_exists('chawiki_toc')) {
    /**
     * 目录 TOC（wiki 惯例；服务端生成、零 JS）。
     * 数据来自内核 Front::post 的 $toc（h2~h5 已带锚点 id）—— 旧版把它传进视图却从未渲染。
     * 用 <details> 实现可折叠：桌面默认展开、移动端默认收起（由调用方传 $open）。
     */
    function chawiki_toc(array $items, bool $open = true, string $cls = ''): string
    {
        if ($items === []) { return ''; }
        $h = '<details class="wtoc' . ($cls !== '' ? ' ' . $cls : '') . '"' . ($open ? ' open' : '') . '>';
        $h .= '<summary class="wtoc-cap"><span>' . e(__('post.toc')) . '</span><span class="wtoc-n">' . count($items) . '</span></summary>';
        $h .= '<ul class="wtoc-list">';
        foreach ($items as $it) {
            $l = (int)($it['l'] ?? 2);
            $h .= '<li class="wl' . $l . '"><a href="#' . e((string)$it['id']) . '">' . e((string)$it['t']) . '</a></li>';
        }
        return $h . '</ul></details>';
    }
}

/* ============================================================
   ★★ 方案 D · P3（2026-09-18）· v2 铺开到其余 12 个页型 —— 新增助手
   ★ 本节**全部是新增函数**，上面（以及 wiki.css / chawiki.css / style.css）的既有实现一个字未改。
   ============================================================ */

if (!function_exists('chawiki_v2')) {
    /** v2 版式是否启用：DB `theme.options.layout` === 'v2'（**字符串**；默认 v1）。R1 回滚就改这一个字符。 */
    function chawiki_v2(): bool
    {
        return (string)theme_opt('layout', 'v1') === 'v2';
    }
}

if (!function_exists('chawiki_cat_groups')) {
    /**
     * 13 个分类分 4 组（设计 §③-2(2)）——「让侧栏 ≤ 一屏」的关键手段。
     * 左栏渲染 = 4 行组标题 + 13 行分类 = 17 行 ≈ 422px ≤ 826px（900 视口）⇒ 满高、无内部滚动（A19）。
     * 数据走内核块缓存 Cache::blocks（键 catgroups5）⇒ 每页 **0 条新增 SQL**（与左栏树同性质）。
     * 返回：[ ['name'=>'茶之本','n'=>5311,'cats'=>[['name'=>'茶类','slug'=>'tea-type','n'=>4437], …]] , … ]
     */
    function chawiki_cat_groups(): array
    {
        static $memo = null;
        $key = 'catgroups5';
        if ($memo !== null) { return $memo; }
        $raw = Cache::get('blocks', $key);
        if ($raw !== false) {
            $dec = json_decode((string)$raw, true);
            if (is_array($dec) && $dec !== []) { return $memo = $dec; }
        }
        $rows = Db::rows('SELECT id, name, names_i18n, slug, `count` FROM ' . Db::t('metas')
            . " WHERE type='category' AND `count`>0 ORDER BY `order` ASC, id ASC LIMIT 40");
        $by = [];
        $useLang = Poly::on() && Lang::short() !== Poly::defaultLang();
        foreach ($rows as $r) {
            $nm = (string)$r['name'];
            if ($useLang) { $t = Poly::termLang($r, Lang::short()); if ($t !== '') { $nm = $t; } }
            $by[(string)$r['slug']] = ['name' => $nm, 'slug' => (string)$r['slug'], 'n' => (int)$r['count']];
        }
        $plan = [
            '茶之本'     => ['tea-type', 'variety', 'craft'],
            '茶之地与人' => ['region', 'company', 'people'],
            '茶之用'     => ['review', 'health', 'brew', 'tool', 'storage'],
            '茶之文'     => ['history', 'custom'],
        ];
        $used = [];
        $groups = [];
        foreach ($plan as $gname => $slugs) {
            $cats = [];
            $sum = 0;
            foreach ($slugs as $sl) {
                if (!isset($by[$sl])) { continue; }
                $cats[] = $by[$sl];
                $sum += $by[$sl]['n'];
                $used[$sl] = 1;
            }
            if ($cats !== []) { $groups[] = ['name' => $gname, 'n' => $sum, 'cats' => $cats]; }
        }
        /* 兜底：DB 里出现计划外的新分类（不丢入口） */
        $rest = [];
        $rsum = 0;
        foreach ($by as $sl => $c) { if (!isset($used[$sl])) { $rest[] = $c; $rsum += $c['n']; } }
        if ($rest !== []) { $groups[] = ['name' => '其它', 'n' => $rsum, 'cats' => $rest]; }
        Cache::set('blocks', $key, (string)json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 600);
        return $memo = $groups;
    }
}

if (!function_exists('chawiki_pinyin_initial')) {
    /**
     * 汉字首字母（设计 §③-2(3) R1「按拼音首字母 <details> 分组」）。
     * 用的是 GBK 区位边界表（业界通行的无依赖做法），不引第三方库、不加 SQL：
     *   A B C D E F G H J K L M N O P Q R S T W X Y Z（无 I / U / V —— 与拼音声母表一致）
     * 非汉字（拉丁字母/数字/符号）→ '#'。
     */
    function chawiki_pinyin_initial(string $s): string
    {
        $ch = mb_substr(trim($s), 0, 1, 'UTF-8');
        if ($ch === '') { return '#'; }
        $g = @iconv('UTF-8', 'GBK//IGNORE', $ch);
        if ($g === false || $g === '' || strlen($g) < 2) { return '#'; }
        $code = (ord($g[0]) << 8) | ord($g[1]);
        if ($code < 0xB0A1) { return '#'; }
        static $B = [
            0xB0A1 => 'A', 0xB0C5 => 'B', 0xB2C1 => 'C', 0xB4EE => 'D', 0xB6EA => 'E',
            0xB7A2 => 'F', 0xB8C1 => 'G', 0xB9FE => 'H', 0xBBF7 => 'J', 0xBFA6 => 'K',
            0xC0AC => 'L', 0xC2E8 => 'M', 0xC4C3 => 'N', 0xC5B6 => 'O', 0xC5BE => 'P',
            0xC6DA => 'Q', 0xC8BB => 'R', 0xC8F6 => 'S', 0xCBFA => 'T', 0xCDDA => 'W',
            0xCEF4 => 'X', 0xD1B9 => 'Y', 0xD4D1 => 'Z',
        ];
        $cur = '#';
        foreach ($B as $lo => $L) { if ($code >= $lo) { $cur = $L; } else { break; } }
        return $cur;
    }
}

if (!function_exists('chawiki_recent_entries')) {
    /** 最近更新的 N 条已发布词条（人读版站点地图 / 404 页共用）——走块缓存，每页 0 条新增 SQL。 */
    function chawiki_recent_entries(int $n = 100): array
    {
        $n = max(1, min(200, $n));
        $key = 'recent5:' . $n;
        $raw = Cache::get('blocks', $key);
        if ($raw !== false) {
            $dec = json_decode((string)$raw, true);
            if (is_array($dec)) { return $dec; }
        }
        $rows = Db::rows('SELECT slug, title FROM ' . Db::t('contents')
            . " WHERE type='post' AND status='publish' AND deleted_at IS NULL"
            . ' ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT ' . $n);
        $out = [];
        foreach ($rows as $r) { $out[] = ['slug' => (string)$r['slug'], 'title' => (string)$r['title']]; }
        Cache::set('blocks', $key, (string)json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 600);
        return $out;
    }
}

if (!function_exists('chawiki_canonical')) {
    /**
     * 当前页 canonical（绝对地址）。
     * · 词条页：/entry/{slug}（方案 B 的规范形；/entry/{slug}.html 与尾斜杠都归到它）
     * · 其它页：REQUEST_URI（去 query）—— 分类页保持 /category/{slug}/ 的尾斜杠
     */
    function chawiki_canonical(): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') { return ''; }
        /* ★ origin 取值（修「同一站两种 scheme」—— 迁移报告 §6-6 / §5④-7 的未决项）：
           优先用站点自己的 URL 选项 site.url（预览 = https://test.cha.wiki；上线 = https://cha.wiki）。
           为什么必须这样：全页缓存（Cache::pages）的键**不含 scheme**，而 canonical 原来按请求
           scheme 生成 ⇒ CF→源站走一次 HTTP，就会把 http:// 的 canonical 写进缓存，之后所有
           HTTPS 访客都读到 http:// 的 canonical（本机已实测复现：/entry/latest 返回 http://）。
           改用 site.url 后 canonical 与请求 scheme 解耦 ⇒ 缓存安全、全站 scheme 唯一；
           且上线时只要改 site.url（见报告「上线前必做清单」）canonical 自动变 https://cha.wiki。
           site.url 未配置时回退原行为（请求 scheme 多源判定），不影响任何既有站点。 */
        $origin = '';
        $su = trim((string)get_option('site.url', ''));
        if ($su !== '') {
            $sp = parse_url(str_contains($su, '://') ? $su : ('https://' . $su));
            if (!empty($sp['scheme']) && !empty($sp['host'])) {
                $origin = $sp['scheme'] . '://' . $sp['host'] . rtrim((string)($sp['path'] ?? ''), '/');
            }
        }
        if ($origin === '') {
            $origin = (function_exists('yc_theme_origin') ? yc_theme_origin() : 'http://' . $host);
        }

        $reqPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $lp = chawiki_prefix();

        /* 词条页：/entry/{slug}[.html|/] 或 /{slug}.html ⇒ 统一成 {base}/{slug} */
        if (preg_match('#^' . preg_quote($lp, '#') . '/entry/([A-Za-z0-9._-]+?)(\.html?)?/?$#', $reqPath, $m)) {
            return $origin . $lp . chawiki_entry_url($m[1]);
        }
        if ($lp === '' && preg_match('#^/([A-Za-z0-9._-]+?)\.html?$#', $reqPath, $m)) {
            if (!in_array(strtolower($m[1]), Router::RESERVED, true)) {
                return $origin . chawiki_entry_url($m[1]);
            }
        }
        return $origin . $reqPath;
    }
}
