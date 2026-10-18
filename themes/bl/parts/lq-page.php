<?php
/** lq-page —— 精仿第 2 批：其余 7 个页型的「骨架渲染层」（★ 2026-10-05）
 * ===========================================================================
 * 机制与首页完全一致（见 parts/lq-skel.php 头注释）：
 *   参照页 www.liulanqi.com 的**真实 HTML**（主域盘只读）→ `_lqpix/src/mk2.py`
 *   按字节切片（span.find_span，不过序列化器）→ `lqwww/skel/*.html` + `lqwww/gen/*.html`
 *   ⇒ 本文件**没有一行"画 UI"的代码**，只有"取真数据 → 填占位符"。
 *
 * 覆盖页型（参照件 → 骨架）：
 *   ① /tips            seo/tips.html        gen/list_tips.html
 *   ② /tips/<slug>     seo/tips/<slug>.html gen/detail_tips.html
 *   ③ /tool            seo/tool.html        gen/list_tool.html
 *   ④ /tool/<slug>     seo/tool/<slug>.html gen/detail_tool.html
 *   ⑤ /download        seo/download.html    gen/list_download.html
 *   ⑥ /gallery         seo/gallery.html     gen/list_gallery.html
 *   ⑦ /learn           seo/learn.html       gen/list_learn.html
 * ★ 一个功能都不删：分页 · 分类 chips · 阅读数 · 评论 · 上下篇 · TOC · 朗读/打印 ·
 *   收藏/登录 · 结构化数据 · feed · /en/ —— 详情页的机制件由 layout.php / post.php 继续负责。
 */
/* ★ 骨架层（lqskel / lqskel_fill / lqskel_repeat / lqskel_tool_face）必须先加载：
   lqpg_* 里每个函数都要用它；只 include 视图而不 include 它 ⇒ Call to undefined function
   （本轮真踩到：/tips /tool /gallery /tips/* /tool/* 全部因此 Fatal）。 */
require_once __DIR__ . '/lq-skel.php';

if (!function_exists('lqpg_ctx')) {

    /** 取 gen/ 里的骨架文件（请求内缓存） */
    function lqpg_gen(string $name): string
    {
        static $c = [];
        if (!isset($c[$name])) {
            $p = dirname(__DIR__) . '/lqwww/gen/' . $name . '.html';
            $c[$name] = is_file($p) ? (string)file_get_contents($p) : '';
            if ($c[$name] === '') { error_log('[lqpg] missing gen: ' . $p); }
        }
        return $c[$name];
    }

    /** 骨架里若还有未消费的 {{...}} —— 绝不留到页面上（先记日志再抹掉） */
    function lqpg_clean(string $html, string $label): string
    {
        if (preg_match_all('/\{\{([A-Za-z0-9_]+)\}\}/', $html, $m) && $m[0] !== []) {
            error_log('[lqpg] unfilled ' . implode(',', array_unique($m[0])) . ' in ' . $label);
            $html = str_replace($m[0], '', $html);
        }
        return $html;
    }

    /* ------------------------------------------------------------------ 侧栏
     * 参照的侧栏是所有列表/详情页共用的同一个 aside（逐字节相同）⇒ 一份骨架、按页填值：
     *   根栏目名 · 首页链接 · 当前栏目链接（高亮）· 「浏览器专题」链接表 · 「工具与资源」7 条固定链接。
     * ★ 专题链接取自实例**真实存在**的 /learn/<slug> 行（不编造）；工具与资源 7 条在实例全都在。
     */
    function lqpg_sidebar(string $rootLabel, array $ctx): string
    {
        static $cache = [];
        $key = md5($rootLabel . json_encode($ctx, JSON_UNESCAPED_UNICODE));
        if (isset($cache[$key])) { return $cache[$key]; }
        $lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
        $onCls  = 'bg-accent font-medium text-foreground';
        $offCls = 'text-muted-foreground';
        /* ★ lqrd 2026-10-07：高亮规则 = 「当前路径的前缀匹配链接里，取 href 最长的那一条点亮」。
           已逐页对参照验证 4/4 MATCH（/learn→学堂首页 · /learn/chrome→Chrome 从入门到精通 ·
           /tips→浏览器技巧全览 · /tips/adblock→浏览器技巧全览）。判"是否点亮"必须按整 token 比
           `bg-accent`（`hover:bg-accent` 会骗人）。回滚：bash /root/lqrd-backup/ROLLBACK-SIDEBAR.sh */
        $__cur  = (string)($ctx['cur'] ?? ($ctx['sec_href'] ?? ''));
        $__cand = [['href' => (string)($ctx['home_href'] ?? '/learn'), 'key' => 'home'],
                   ['href' => (string)($ctx['sec_href']  ?? '/'),      'key' => 'sec']];
        foreach ((array)($ctx['topics'] ?? []) as $__i => $__t) {
            $__cand[] = ['href' => (string)($__t['href'] ?? ''), 'key' => 't' . $__i];
        }
        $__hit = null;
        foreach ($__cand as $__c) {
            if ($__c['href'] !== '' && $__cur !== '' && strpos($__cur, $__c['href']) === 0
                && ($__hit === null || strlen($__c['href']) > strlen((string)$__hit['href']))) { $__hit = $__c; }
        }
        $__isOn = static function (string $k) use ($__hit): bool { return $__hit !== null && $__hit['key'] === $k; };
        $__lqcls = static function (string $href) use ($__cand, $__isOn, $onCls, $offCls): string {
            foreach ($__cand as $__c) { if ($__c['href'] === $href) { return $__isOn($__c['key']) ? $onCls : $offCls; } }
            return $offCls;
        };
        $topics = '';
        foreach ((array)($ctx['topics'] ?? []) as $t) {
            $topics .= lqskel_fill(lqskel('item_side_topic'), [
                'href' => e($lp . (string)$t['href']),
                'name' => e((string)$t['name']),
                'cls'  => $__lqcls((string)($t['href'] ?? '')),
            ], 'side_topic');
        }
        $html = lqskel_fill(lqskel('side_nav'), [
            'sb_root'      => e($rootLabel),
            'sb_home'      => e((string)($ctx['home_label'] ?? '学堂首页')),
            'sb_home_href' => e($lp . (string)($ctx['home_href'] ?? '/learn')),
            'sb_home_cls'  => $__isOn('home') ? $onCls : $offCls,
            'sb_sec'       => e((string)($ctx['sec_label'] ?? '')),
            'sb_sec_href'  => e($lp . (string)($ctx['sec_href'] ?? '/')),
            'sb_sec_cls'   => $__isOn('sec') ? $onCls : $offCls,
            'sb_topics'    => $topics,
        ], 'sidebar');
        return $cache[$key] = $html;
    }

    /** 侧栏的默认上下文（专题 = 实例 /learn 的真行；顺序与参照侧栏一致） */
    function lqpg_sidebar_ctx(string $secLabel, string $secHref, string $rootLabel = '浏览器学堂', string $cur = ''): array
    {
        $want = ['chrome', 'edge', 'firefox', 'quark', 'yandex', 'opera'];
        $map  = [];
        $rows = Db::rows(
            'SELECT slug, title FROM ' . Db::t('contents')
            . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND slug LIKE 'learn\\_\\_%'"
        );
        foreach ($rows as $r) {
            $s = (string)preg_replace('/^learn__/', '', (string)$r['slug']);
            $map[$s] = (string)$r['title'];
        }
        $topics = [];
        foreach ($want as $s) {
            if (!isset($map[$s])) { continue; }
            $name = (string)preg_replace('/[：:].*$/u', '', $map[$s]);
            /* ★ lqrd：侧栏短名与参照一致（参照 = 「夸克从入门到精通」）；DB 行标题一字不动 */
            $name = (string)preg_replace('/^(夸克|Yandex|Opera)(\\s*)浏览器/u', '$1$2', $name);
            $topics[] = ['href' => '/learn/' . $s, 'name' => $name];
        }
        return [
            'root'       => $rootLabel,
            'home_label' => '学堂首页',
            'home_href'  => '/learn',
            /* ★ lqrd：参照在 /learn、/learn/<slug>、/tips、/tips/<slug> 四个页型上第 2 条链接
               都是「浏览器技巧全览」→ /tips（原来这里放调用方传来的栏目名，/learn 家族还指同一个 /learn） */
            'sec_label'  => '浏览器技巧全览',
            'sec_href'   => '/tips',
            'topics'     => $topics,
            'cur'        => $cur !== '' ? $cur : (string)$secHref,
        ];
    }

    /** 工具页侧栏上下文：根 = 工具箱；专题位改成「11 个工具分类」的**真值**入口（实例既有功能件） */
    function lqpg_sidebar_tool_ctx(string $activeCat, string $lp): array
    {
        /* ★ 2026-10-11 站主裁定 P1：/tool/c/* 撤除 ⇒ 侧栏不再收分类主题 */
        $topics = [];
        return [
            'root'       => '工具箱',
            'home_label' => '工具箱首页',
            'home_href'  => '/tool',
            'sec_label'  => '全部分类',
            'sec_href'   => '/tool',
            'topics'     => $topics,
        ];
    }

    /* ------------------------------------------------------------------ 面包屑     * 参照的面包屑是 `<nav … aria-label="breadcrumb">` + 每段一个 `<span class="flex items-center gap-1">`，
     * 末段是 `<span class="text-foreground">`，其余是 `<a class="hover:text-foreground">` + chevron svg。
     * ★ 这里按参照**同一份字节形状**产出（head/foot 两份片段都切自参照，不重写）。
     */
    function lqpg_crumbs(array $items): string
    {
        static $head = null, $tail = null;
        if ($head === null) {
            $head = '<span class="flex items-center gap-1"><a class="hover:text-foreground" href="%s">%s</a>'
                  . lqwww_ico('chevron-right', 'h-4 w-4') . '</span>';
            $tail = '<span class="flex items-center gap-1"><span class="text-foreground">%s</span></span>';
        }
        $n = count($items);
        if ($n === 0) { return ''; }
        $h = '';
        foreach ($items as $i => $c) {
            $h .= ($i === $n - 1)
                ? sprintf($tail, e((string)$c[0]))
                : sprintf($head, e((string)$c[1]), e((string)$c[0]));
        }
        return $h;
    }

    /* -------------------------------------------------------------- 列表卡片
     * 参照 /tips 的卡片有**两种**形状：无分类徽章（A）与带「<div class=mb-1 …>分类</div>」（B）。
     * 两种都按参照字节切好（list_item_plain / list_item_badge）。
     * 图标：参照每卡一个 lucide 图标 + 一个色阶；实例按**唯一真源**分类表（parts/cat-def.php）映射。
     */
    function lqpg_card_icon(string $sec, string $storeSlug): array
    {
        if ($sec === 'tool') {
            $tail = (string)preg_replace('/^tool__/', '', $storeSlug);
            if (function_exists('yc_tool_cat')) {
                $ck = yc_tool_cat($tail, '');
                return lqskel_tool_face($ck);
            }
        }
        return ['file-text', 'text-primary'];
    }

    /** 列表页卡片列表（参照 /tips 的 3 列网格） */
    function lqpg_cards(array $posts, string $sec, string $lp, string $linkPrefix = ''): string
    {
        $h = '';
        foreach ($posts as $p) {
            $store = lqwww_store_slug((string)$p['slug']);
            $isTool = str_starts_with($store, 'tool__');
            /* 分类徽章：参照里是「Chrome 从入门到精通」这类**专题名**；实例用同一套映射取真值 */
            /* ★ 参照 /tips 的 30 张卡里只有 1 张带「<div class=mb-1 …>专题</div>」分类徽章
               ⇒ 卡片**默认不带徽章**（与参照 30 张里的 29 张一致）；带徽章那一份模板
               （list_item_badge.html）保留在盘上，需要时单点启用。 */
            $badge = '';
            [$ic, $col] = lqpg_card_icon($sec, $store);
            $ts = strtotime((string)(($p['updated_at'] ?? '') ?: (($p['published_at'] ?? '') ?: ($p['created_at'] ?? ''))));
            $meta = $ts ? '更新于 <!-- -->' . date('Y-m-d', $ts) : '';
            $tpl = $badge !== '' ? 'list_item_badge' : 'list_item_plain';
            $h .= lqskel_fill(lqskel($tpl), [
                'href'       => e($linkPrefix . yc_entry_url(lqwww_url_slug((string)$p['slug']))),
                'icon_class' => e($col),
                'icon'       => lqwww_ico($ic, 'h-5 w-5'),
                'badge'      => e($badge),
                'title'      => e(lqwww_tool_title($store, (string)$p['title'])),
                'lead'       => e(lqwww_lead((array)$p, 130)),
                'meta'       => $meta,
            ], 'card');
        }
        return $h;
    }

    /** 条目的「专题名」：由实例自己的 /learn 行反查（工具用分类名） */
    function lqpg_topic_of(string $storeSlug): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (Db::rows('SELECT slug, title FROM ' . Db::t('contents')
                . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND slug LIKE 'learn\\_\\_%'") as $r) {
                $map[(string)$r['slug']] = (string)$r['title'];
            }
        }
        $base = (string)preg_replace('/^tips__/', '', $storeSlug);
        $pre  = (string)(preg_split('/-/', $base)[0] ?? '');
        $cand = ['chrome', 'edge', 'firefox', 'quark', 'yandex', 'opera', 'uc'];
        foreach ($cand as $c) {
            if ($pre === $c && isset($map['learn__' . $c])) {
                return (string)preg_replace('/[：:].*$/u', '', $map['learn__' . $c]);
            }
        }
        return '';
    }

    /** 分页条（★ 实例功能件，保留；放在参照列表页的最底部，几何上不遮挡参照区块） */
    function lqpg_pager(int $page, int $totalPages, string $base, int $total, int $per): string
    {
        return function_exists('lqwww_pager') ? lqwww_pager($page, $totalPages, $base, $total, $per) : '';
    }

    /** 分类 chips（★ 实例功能件，保留）：/tool 的 11 个分类入口 + 当前高亮 */
    function lqpg_cat_chips(string $active = 'all'): string
    {
        if (!function_exists('yc_cat_table')) { return ''; }
        $lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
        $byCat = function_exists('yc_tools_by_cat') ? yc_tools_by_cat() : [];
        /* ★ 2026-10-11 站主裁定 P1：/tool/c/* 撤除 ⇒ 分类链接块不再产出 */
        return '';
    }
    /* ------------------------------------------------------- /learn 参照字节外壳
     * ★ lrlearn 2026-10-06：与首页/列表页**同一套精仿机制**，不另起一套。
     *   骨架 = `lqwww/skel/page_learn.html`，由 `_lqpix/src/mk_learn.py` 从参照
     *   `https://liulanqi.com/learn` 的真实 HTML **按字节区间**切出（span 配平 + 字面量替换，
     *   不过任何 HTML 序列化器 ⇒ 引号风格/自闭合/属性顺序全不变），只把 4 个动态点换成占位符：
     *     {{crumb}} 面包屑整段 · {{side}} 侧栏整段 · {{h1}} hero 标题 · {{lead}} hero 副文案
     *   ⇒ 除这 4 个占位符外，外壳与参照逐字节相同；DB 行（id=790）的富版正文原样接在骨架之后。
     *   ★ 骨架缺失/为空 ⇒ 本函数返回 ''，调用方（partials/page.php）自动回落旧壳（不白屏、不半截）。
     */
    function lqpg_learn_shell_ready(): bool
    {
        return lqskel('page_learn') !== '';
    }

    /** 参照面包屑是 `<nav class="mb-6 flex items-center gap-1 text-sm text-muted-foreground" aria-label="breadcrumb">`
     *  包着一串 `<span class="flex items-center gap-1">`。
     *  ★ 只发 span 会丢掉 `mb-6`（下边距 24px）⇒ 面包屑行高 24 而不是 20、后面的网格整体下移 4px
     *    （第一轮像素差异里 y=121..145 那一带就是它）。这里按参照的同一份字节形状补上外层 nav。 */
    function lqpg_crumbs_nav(array $items): string
    {
        $inner = lqpg_crumbs($items);
        if ($inner === '') { return ''; }
        return '<nav class="mb-6 flex items-center gap-1 text-sm text-muted-foreground" aria-label="breadcrumb">'
             . $inner . '</nav>';
    }

    function lqpg_learn_shell(string $crumbHtml, string $sideHtml, string $h1, string $lead): string
    {
        $s = lqskel('page_learn');
        if ($s === '') { return ''; }
        return lqskel_fill($s, [
            'crumb' => $crumbHtml,
            'side'  => $sideHtml,
            'h1'    => $h1,
            'lead'  => $lead,
        ], 'learn_shell');
    }

    /* ------------------------------------------- /learn/<slug> 文章页参照字节外壳
     * ★ lrlearn-one 2026-10-06：与主页**同一套机制**，但参照件不同 ⇒ 单独一份骨架。
     *   参照 = https://liulanqi.com/learn/chrome → `_lqpix/learn/ref_learn_chrome.html`
     *   差异（实测）：
     *     ① 包裹是 `div.mx-auto.max-w-6xl.space-y-6.px-4.py-6`（主页是 `py-6` 版，无 space-y-6）
     *     ② **hero 卡属于壳**：标签行 / h1 / lead 都是卡的**直接子节点**，卡内没有包裹 div
     *     ③ 参照文章页里**没有** `<article>`（我方旧的 partials/post.php 走 detail_tips 骨架带 article）
     *   骨架：page_learn_one.html（{{crumb}} {{side}}）
     *         learn_one_hero.html（{{card_open}} {{label_icon}} {{label}} {{h1}} {{lead}}）
     *   ★ 骨架缺一 ⇒ ready() 为 false ⇒ post.php 自动回落旧 article 壳（不白屏、不半截）。
     */
    function lqpg_learn_one_ready(): bool
    {
        return lqskel('page_learn_one') !== '' && lqskel('learn_one_hero') !== '';
    }

    /** 外壳前半：包裹 + 面包屑 + 两栏 + 侧栏 + 开「内容列」 */
    function lqpg_learn_one_shell(string $crumbHtml, string $sideHtml): string
    {
        $s = lqskel('page_learn_one');
        if ($s === '') { return ''; }
        return lqskel_fill($s, ['crumb' => $crumbHtml, 'side' => $sideHtml], 'learn_one_shell')
             . '<div class="min-w-0 space-y-8">';
    }

    /** hero 卡：参照卡的外壳 + 标签行 + h1 + lead（全部来自骨架字节） */
    function lqpg_learn_one_hero(string $label, string $labelIcon, string $h1, string $lead): string
    {
        $h = lqskel('learn_one_hero');
        if ($h === '') { return ''; }
        return lqskel_fill($h, [
            'card_open'  => '<div class="rounded-2xl border px-6 py-7" style="background:linear-gradient(120deg, #2563eb14, hsl(var(--card)) 70%)">',
            'label'      => $label,
            'label_icon' => $labelIcon,
            'h1'         => $h1,
            'lead'       => $lead,
        ], 'learn_one_hero');
    }

    /** 外壳收口：内容列 </div> + 两栏 </div> + 包裹 </div> */
    function lqpg_learn_one_close(): string
    {
        return '</div></div></div>';
    }
}

/* 页面组件（chips / /tool 分组 / /gallery 段 / /learn 段 / 面包屑条目）
   —— 单独一份、全部带 function_exists 守卫；同一请求里被多次 include 也安全。 */
$__lqpg_lib = __DIR__ . '/../views/partials/pagelib.php';
if (is_file($__lqpg_lib)) { require_once $__lqpg_lib; }
unset($__lqpg_lib);
