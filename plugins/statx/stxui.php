<?php
declare(strict_types=1);
/**
 * StatX · 界面骨架（批次 3）：CSS + URL 构造 + CSV 导出 + 页面外壳
 *
 * 与 ui.php 的分工：
 *   ui.php    —— 导航树定义、视觉零件（tag / 胶囊 / 表格 / 内联 SVG 折线·柱状·热力网格 / 时间线 / 分页器）
 *   本文件    —— 样式表、URL 构造（所有筛选都走查询串，**每个视图都能当链接分享**，这是老版的好习惯）、
 *               CSV 导出（必须在任何 HTML 之前发生）、以及"把区块拼成整页"的外壳。
 *
 * ★ 路由说明（与老版的**唯一结构性不同**，必须写清）：
 *   老版是 `/admin/site/1/traffic?days=7`（路径段）；内核把插件后台固定挂在 `/admin/?s=plug_statx`
 *   （`core/Plugins.php::adminPage()`），插件**无法注册路径** ⇒ 本插件用 `?p=<页面>`。
 *   查询串的好处与老版一致：复制 URL 就复现同一视图。
 */

/**
 * 站点清单（站点切换条 + 站点卡片网格共用）。
 *
 * ★★ 2026-09-15 修【站长实际看到的 bug：仪表盘上 19 张站点卡片"今日 PV/UV/爬虫"全是 0】★★
 *   旧的 `static $cache = null; if ($cache !== null) { return $cache; }` **不区分 `$withStats`**，
 *   而一个请求里的调用顺序是固定的：
 *     ① `statx_render_page()` 先取站点切换条的清单 → `statx_ui_sites_ordered()` → `statx_ui_sites(false)`
 *        —— 这一版**不带统计**（`pv/uv/bot` 被写成 0），却把 static 缓存填满了；
 *     ② 随后仪表盘 builder `statx_pg_sites_grid()` 调 `statx_ui_sites(true)`，旧代码在开头就命中
 *        ①留下的缓存 ⇒ 拿到的是「全 0」那一版 ⇒ **19 张卡片全部显示 0**。
 *   实测证据（同一秒、同一个库）：
 *     · `?p=dash` 的 19 张卡片：今日 PV 全 0；而 `?p=settings` 的「站点登记」表（走另一条查询）
 *       同一刻显示 liulanqi 54/49、xuecha.org 72/72、gushuwu.com 3/3 —— 说明**数据一直在**，
 *       是仪表盘这一处读到了被污染的缓存。
 *     · 复现脚本（`/root/sxwork/repro-cacheorder.php`）：false-first ⇒ 19 行 PV 之和 = 0；
 *       true-first（对照组）⇒ 19 行 PV 之和 = 151 = 裸 SQL 的全库今日人类 PV。
 *   修法：缓存**按 `$withStats` 分开存两份**（键 'plain' / 'with'），互不污染。
 *   ★ 回滚：把 `static $cache = [];` + 键判断换回 `static $cache = null; if ($cache !== null) { return $cache; }`，
 *     并把末尾 `return $cache[$ck] = $rows;` 换回 `return $cache = $rows;`（结果 = 卡片全 0 的老行为）。
 */
function statx_ui_sites(bool $withStats = false): array
{
    static $cache = [];
    $ck = $withStats ? 'with' : 'plain';
    if (isset($cache[$ck])) { return $cache[$ck]; }
    $rows = [];
    try {
        $rows = statx_sdb_all('SELECT site_key, label, host, domains, enabled, inject_where, verify_ok,'
            . ' verify_note, hits_total, sort, last_hit_at FROM ' . STATX_SDB_SITES . ' ORDER BY sort, site_key');
    } catch (Throwable $e) { $rows = []; }
    if ($withStats && $rows) {
        $today = date('Y-m-d');
        $agg = [];
        try {
            foreach (statx_sdb_all('SELECT site_key,'
                . " SUM(kind='pv' AND is_bot=0) pv, COUNT(DISTINCT CASE WHEN kind='pv' AND is_bot=0 THEN "
                . STATX_DASH_UV . ' END) uv,'
                . " SUM(kind='pv' AND is_bot=1) bot FROM " . STATX_SDB_HITS
                . ' WHERE d=? GROUP BY site_key', [$today]) as $r) {
                $agg[(string)$r['site_key']] = $r;
            }
        } catch (Throwable $e) { $agg = []; }
        foreach ($rows as &$r) {
            $a = $agg[(string)$r['site_key']] ?? [];
            $r['pv']  = (int)($a['pv'] ?? 0);
            $r['uv']  = (int)($a['uv'] ?? 0);
            $r['bot'] = (int)($a['bot'] ?? 0);
        }
        unset($r);
    } else {
        foreach ($rows as &$r) { $r['pv'] = 0; $r['uv'] = 0; $r['bot'] = 0; }
        unset($r);
    }
    return $cache[$ck] = $rows;
}

/**
 * URL 构造：**所有筛选都在查询串里**（老版习惯）。
 * $over 里的 null 值表示"删除这个键"。
 */
function statx_ui_url(array $ctx, array $over = []): string
{
    $q = ['s' => 'plug_statx', 'p' => (string)($ctx['p'] ?? 'dash')];
    if (!empty($ctx['r'])) { $q['r'] = (string)$ctx['r']; }
    if (!empty($ctx['site'])) { $q['site'] = (string)$ctx['site']; }
    if (!empty($ctx['q']) && is_array($ctx['q'])) {
        foreach ($ctx['q'] as $k => $v) { if ($v !== '' && $v !== null) { $q[(string)$k] = (string)$v; } }
    }
    // ★★ r8 修复【站长报的导航丢上下文 bug】——**上下文继承只在这一处**：
    //   实测：`?p=users&r=yesterday&site=X` 上点「轨迹 →」，链接是 `stx_lk('轨迹 →', ['p' => 'visitor', 'uid' => …])`，
    //   而 `stx_lk()` 走的是**本函数** —— 传进来的 `$q` 只有 `p/uid`，`r` 与 `site` 从来没进过 URL
    //   ⇒ 目标页只能用它自己的默认值渲染（站点、档位、日期全变），用户看到的是"另一个站、另一档"。
    //   这里把 `$ctx` 里已有的上下文并进来，**显式覆盖优先**（`$over` 仍然能删/改任意键）。
    if (isset($ctx['r']) && (string)$ctx['r'] !== '' && !isset($over['r'])) {
        $q['r'] = (string)$ctx['r'];
    }
    // ★ 单日模式下"那一天"在 `$ctx['single']`（`statx_ctx()` 从来不放 `$ctx['d']`）——
    //   主补丁第一版只读了 `$ctx['d']` ⇒ 实测**从未命中**，轨迹链接仍然缺 `d`（实测 379 条）。
    //   这里同时认 `single` 与 `d`（后者容错），单日页的内链才真正带上那一天。
    $__d = '';
    if ((string)($ctx['r'] ?? '') === 'day') {
        $__d = (string)($ctx['single'] ?? '');
    }
    if ($__d === '' && isset($ctx['d'])) { $__d = (string)$ctx['d']; }
    if ($__d !== '' && !array_key_exists('d', $over)) { $q['d'] = $__d; }
    // 站点同理 —— 但"全部站点"这类链接会用 `['site' => '']` 显式清掉，所以这里只补"没被显式提到"的情况
    if (!empty($ctx['site']) && !array_key_exists('site', $over)) { $q['site'] = (string)$ctx['site']; }
    // 请求里没带 site（= 正在看"全部站点汇总"）时，不能把站点清单里的**某一行**当成当前站继承下去
    if ($q['p'] !== 'dash' && empty($q['site']) && array_key_exists('site', $over) && (string)$over['site'] === '') {
        unset($q['site']);
    }
    foreach ($over as $k => $v) {
        if ($v === null || $v === '') { unset($q[(string)$k]); }
        else { $q[(string)$k] = (string)$v; }
    }
    // 'd' 只在单日模式下保留（避免 ?r=today&d=... 语义打架）
    if (isset($q['d']) && ($q['d'] === '' || ($q['r'] ?? '') !== 'day')) { unset($q['d']); }
    // ★ CSV 导出不是"页面"，不该被页面级下钻参数污染（uid/url/h … 会让导出与页面口径分叉）
    if (isset($q['csv'])) { foreach (array_keys($q) as $k) { if (in_array($k, ['uid', 'url', 'h', 'kw', 't', 'eid', 'st'], true)) { unset($q[$k]); } } }
    return '/admin/?' . http_build_query($q);
}

/* ============================== 小工具 ============================== */

/**
 * 毫秒 → 人类可读（站点健康页用）。
 * ★ 这个函数**必须在插件层**（不能像批次 2 那样只定义在 admin.php 里）：
 *   批次 3 的页面渲染路径是 pages*.php / ui.php，不再经过 admin.php，
 *   若只放在 admin.php 就会在页面渲染时报 "Call to undefined function"（本批实测踩到）。
 */
if (!function_exists('statx_ms')) {
    function statx_ms(int $ms): string
    {
        return $ms >= 1000 ? (round($ms / 1000, 2) . 's') : ($ms . 'ms');
    }
}

/**
 * CSV 导出：**必须在任何 HTML 输出之前调用**（后台外壳会 echo，一旦输出就没法加头了）。
 * 与页面共用同一组 cols/rows 生成函数 ⇒ 页面数字与导出数字**不可能分叉**。
 */
function statx_ui_csv_handle(array $ctx): bool
{
    $id = (string)preg_replace('~[^a-z0-9_]~i', '', (string)($_GET['csv'] ?? ''));
    if ($id === '') { return false; }
    $reg = statx_pages_registry();
    if (!isset($reg[$id])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'unknown table: ' . $id;
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="statx-' . $id . '-'
        . preg_replace('~[^A-Za-z0-9._-]~', '_', $ctx['site'] !== '' ? $ctx['site'] : 'all') . '-'
        . preg_replace('~[^A-Za-z0-9._-]~', '_', $ctx['r']) . '-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');
    $fp = fopen('php://output', 'wb');
    fwrite($fp, "\xEF\xBB\xBF");                 // BOM：Excel 双击不乱码
    try {
        $t = ($reg[$id])($ctx);
        $cols = $t['cols'] ?? [['值', 'k']];
        $rows = $t['rows'] ?? [];
        $head = [];
        foreach ($cols as $c) { $head[] = (string)$c[0]; }
        fputcsv($fp, $head);
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $c) {
                $v = $r[(string)$c[1]] ?? '';
                $line[] = is_scalar($v) ? strip_tags((string)$v) : json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            fputcsv($fp, $line);
        }
    } catch (Throwable $e) {
        fputcsv($fp, ['export failed']);
    }
    fclose($fp);
    exit;
}

/* ============================== 样式表（自包含、可打印、零外部资源） ============================== */

function stx_css(): string
{
    return '<style>'
        /* ---- 外壳：把内核 main 铺满（日志/权限在插件页里没有意义，故用 YC_ADMIN_FOCUS 走全宽） ---- */
        /* ★★ r7（站长 2026-09-15「页面全屏：左栏固定宽度、右栏占剩下全部」）：
         *   实测的**真实**原因：内核 `admin/index.php:647` 给 `.wrap` 写死
         *     `max-width:1240px`（即使走 YC_ADMIN_FOCUS 也是 1240px，不是 none）
         *   而且 `.wrap` 是 `.stx-app` 的**父元素**：
         *     `<div class="wrap"><main><div class="stx-app">…`
         *   所以只把内层 `.stx-app` 设 `width:100%` 毫无作用 —— 外层先被卡在 1240px，
         *   1920 屏上右边白掉一大条。必须覆盖 `.wrap` 本身。
         *
         *   为什么作用域这样定（做过取舍，两个更"窄"的办法都不可靠）：
         *     · `.wrap:has(> main > .stx-app)` —— 能用 `:has()` 的浏览器才生效，老浏览器直接失效；
         *     · 把 `.stx-app` 提到 `.wrap` 外面 —— 要动用内核的渲染顺序，插件拿不到。
         *   实测代价：`/www/wwwroot/tj-stat` 这个实例是**独立统计站**，`YC_ADMIN_FOCUS` 只由
         *   `statx/boot.php` 对 `s=plug_statx*` 设置，且内核在该分支下**不渲染它自己的 `<aside>`**
         *   ⇒ 本实例后台里唯一的 `.wrap` 就是插件页那个。唯一会跟着变宽的"非统计页"是这条
         *   独立站自己的内核仪表盘（`?s=dashboard`），而 boot.php 已把裸 `/admin/` 302 到
         *   `?s=plug_statx`。验收时逐页确认过：38 页 200、页脚齐全、无横向溢出。
         *   ★ 回滚：删掉下面这行 `.wrap{…}`（其余布局回到 1240px 居中）。 */
        . '.wrap{max-width:100%;width:100%;margin:0;padding:0 var(--stx-pad);align-items:start;box-sizing:border-box}'
        /* ★★ r11【隐藏宿主后台外壳顶部】
         *   选项 `statx.ui.hide_host_chrome=1` 时，boot.php 给 `<body>` 加了 `.stx-hidechrome`，
         *   这里把内核那条 `<header>`（欢迎语 / 查看站点 ↗ / 博主：xxx / 退出）整条收起。
         *   选择器用**直接子代** `body > header`：
         *     · 内核顶部是 `<body><header>…` 的直接子元素；
         *     · 插件自己的 `<main class="stx-main">` 在 `<body> > .wrap > main` 里面，
         *       不是 body 的直接子元素 ⇒ **不可能被误伤**（实测整页只有 1 个 <header>）。
         *   同时把左栏的 `top:54px`（原本是让开那条 sticky header 的高度）改回 0，
         *   否则顶部会凭空留 54px 空白。
         *   ★ 回滚：删掉这两行（或把选项设回 0）。 */
        . 'body.stx-hidechrome > header{display:none}'
        . 'body.stx-hidechrome .stx-side{top:0;height:100vh}'
        /* 设置页「隐藏宿主后台外壳顶部」开关那一行的排版（复选框 + 文案，**不用 JS**） */
        . '.stx-chk{display:flex;align-items:flex-start;gap:8px;font-size:13.5px;color:#333;margin:2px 0 10px;max-width:760px;line-height:1.6}'
        . '.stx-chk input[type=checkbox]{margin:3px 0 0;flex:0 0 auto}'
        /* 右栏 = 剩下的 99%：用 calc(100% - 212px - 2*pad) 显式给出，不依赖 1fr 的隐式分配 */
        . ':root{--stx-side:212px;--stx-pad:22px}'
        . '@media(max-width:1200px){:root{--stx-pad:14px}}'
        . '@media(max-width:960px){:root{--stx-pad:12px}}'
        /* ★ 实测修的两个细节（不改这两处，"左栏固定 212px / 右栏占满"都做不到）：
         *   ① `.stx-side{width:212px;padding:14px 10px}` 在**没有全局 border-box** 的页面里
         *      实际占到 234px（212 + 左右各 10px padding）> 网格轨道 212px ⇒ 左栏**溢出 12px**，
         *      同时把右栏挤窄 12px。加 box-sizing:border-box 后左栏精确 = 212px。
         *   ② 右栏轨道用 `minmax(0, calc(100% - 212px - 2*pad))` 时，Chrome 把其中的 `100%`
         *      解析得比网格容器**还小 44px**（实测 1920 下算出 1605px，而容器 1861px；
         *      正确值应为 1649px）⇒ 右栏右边凭空空出 44px，肉眼就是"没铺满"。
         *      根因是 grid 轨道里的百分比在 minmax 内的解析语义不直观。
         *      改成 `minmax(0, 1fr)`（= "剩下的全部"，语义干净、无百分比歧义）后实测正确：
         *      1920 下右栏 = 1861 - 212 = 1649px，右边缘 1883 与容器右边缘重合。
         *   ③ 左栏仍**显式固定** 212px（`--stx-side`），右栏 = 容器宽 - 212px = 剩下的全部。 */
        . '.stx-app{display:grid;grid-template-columns:var(--stx-side) minmax(0,1fr);gap:0;width:100%;min-width:0}'
        . '@media(max-width:960px){.stx-app{grid-template-columns:minmax(0,1fr)}.stx-side{position:static!important;height:auto!important;width:auto!important;flex:0 0 auto!important}}'
        . '.stx-side{width:212px;flex:0 0 212px;box-sizing:border-box;background:#173f33;color:#cfe6dd;padding:14px 10px 24px;'
        . 'position:sticky;top:54px;height:calc(100vh - 54px);overflow-y:auto;border-radius:8px}'
        . '.stx-brand{display:block;font-size:17px;font-weight:700;color:#fff;padding:2px 8px 12px;'
        . 'border-bottom:1px solid #2a5a4b;margin-bottom:10px;text-decoration:none}'
        . '.stx-brand small{font-size:11px;color:#7fa999;font-weight:400}.stx-brand:hover{opacity:.85}'
        . '.stx-msite{font-size:12px;padding:7px 9px;background:#1f5243;border-radius:8px;margin-bottom:10px;'
        . 'display:flex;justify-content:space-between;align-items:center;gap:6px}'
        . '.stx-msite .n{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
        . '.stx-msite a{color:#8fd0b6;font-size:11px;text-decoration:none}'
        . '.stx-mg{font-size:11px;color:#7fa999;padding:10px 8px 4px;letter-spacing:1px}'
        . '.stx-mi{display:block;padding:6px 8px;border-radius:7px;color:#cfe6dd;font-size:13px;margin-bottom:1px;text-decoration:none}'
        . '.stx-mi:hover{background:#235545}'
        . '.stx-mi.on{background:#1d9e75;color:#fff;font-weight:600}'
        . '.stx-md{display:inline-block;width:7px;height:7px;border-radius:99px;margin-right:8px;vertical-align:1px;opacity:.85}'
        . '.stx-mi.on .stx-md{opacity:1;box-shadow:0 0 0 2px rgba(255,255,255,.25)}'
        . '.stx-main{flex:1;min-width:0;padding:0 0 40px 22px}'
        . '@media(max-width:960px){.stx-main{padding:0 0 30px 0}}'
        /* ---- topbar ---- */
        . '.stx-topbar{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px}'
        . '.stx-topbar h1{font-size:20px;color:#222;margin:0;font-weight:700;line-height:1.4}'
        . '.stx-sw{display:flex;gap:6px;flex-wrap:wrap}'
        . '.stx-sw a{padding:4px 11px;border-radius:99px;background:#fff;border:1px solid #d8e2da;font-size:12px;'
        . 'color:#555;text-decoration:none;white-space:nowrap}'
        . '.stx-sw a:hover{border-color:#1d9e75;color:#0f6e56}'
        . '.stx-sw a.on{background:#1d9e75;color:#fff;border-color:#1d9e75}'
        /* ---- 三件套 ---- */
        . '.stx-days{display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap}'
        . '.stx-days a{padding:3px 12px;border-radius:99px;background:#fff;border:1px solid #d8e2da;font-size:12px;'
        . 'color:#555;text-decoration:none;white-space:nowrap}'
        . '.stx-days a:hover{border-color:#1d9e75;color:#0f6e56}'
        . '.stx-days a.on{background:#1d9e75;color:#fff;border-color:#1d9e75}'
        . '.stx-daypick{display:flex;align-items:center;gap:8px;margin-bottom:10px;font-size:12px;color:#555;flex-wrap:wrap}'
        . '.stx-daypick input[type=date]{padding:4px 8px;border-radius:8px;border:1px solid #d8e2da;background:#fff;'
        . 'font-size:13px;color:#333}'
        . '.stx-daypick button{padding:4px 12px;border-radius:8px;border:1px solid #1d9e75;background:#1d9e75;'
        . 'color:#fff;font-size:13px;cursor:pointer}'
        . '.stx-filters{margin:0 0 14px}'
        /* ---- 卡片 / 表格 ---- */
        . '.stx-card{background:#fff;border:1px solid #e4e8e0;border-radius:10px;padding:15px 18px;margin-bottom:15px}'
        . '.stx-cardh{display:flex;align-items:baseline;gap:10px;margin-bottom:10px;flex-wrap:wrap}'
        . '.stx-cardh h2{font-size:15px;margin:0;color:#333;font-weight:700}'
        . '.stx-note{color:#888;font-size:12px}'
        . '.stx-csv{margin-left:auto;font-size:12px;color:#0f6e56;text-decoration:none;background:#e1f5ee;'
        . 'border-radius:6px;padding:1px 9px;white-space:nowrap}'
        . '.stx-csv:hover{background:#1d9e75;color:#fff}'
        . '.stx-scroll{overflow-x:auto}'
        . '.stx-tb{width:100%;border-collapse:collapse;font-size:13px}'
        . '.stx-tb th{padding:6px 9px;text-align:left;border-bottom:1px solid #eef1ea;vertical-align:top;'
        . 'color:#666;font-weight:600;background:#fafcf8;white-space:nowrap}'
        . '.stx-tb td{padding:6px 9px;text-align:left;border-bottom:1px solid #eef1ea;vertical-align:top;word-break:break-all}'
        . '.stx-tb th.num{text-align:right}.stx-tb td.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}'
        . '.stx-tb tbody tr:hover{background:#fcfdfb}'
        . '.stx-muted{color:#888;font-size:12px;margin:6px 0 0;line-height:1.65}'
        . '.stx-muted-inline{color:#999;font-size:12px}'
        . '.stx-block{display:block}'
        . '.stx-empty{color:#8b9098;font-size:13px;padding:12px 2px}'
        . '.stx-errbox{color:#a32d2d;background:#fcebeb;border:1px solid #f0d0d0;border-radius:8px;padding:10px 12px;'
        . 'font-size:13px;margin-bottom:14px}'
        . '.stx-note-warn{color:#854f0b;background:#faeeda;border:1px solid #f2ddbd;border-radius:8px;'
        . 'padding:8px 12px;font-size:12.5px;margin-bottom:12px}'
        /* ---- 数字墙 ---- */
        . '.stx-kpis{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:15px}'
        . '.stx-kpi{background:#fff;border:1px solid #e4e8e0;border-radius:10px;padding:12px 18px;min-width:120px;flex:1 1 120px}'
        . '.stx-kpi b{display:block;font-size:24px;color:#1d9e75;font-variant-numeric:tabular-nums;line-height:1.35}'
        . '.stx-kpi span{color:#777;font-size:12px}'
        . '.stx-kpi i{display:block;color:#9aa6a0;font-size:11.5px;font-style:normal;margin-top:2px}'
        . '.stx-kpi-warn b{color:#854f0b}.stx-kpi-bad b{color:#a32d2d}.stx-kpi-plain b{color:#222}'
        /* ---- tag / 胶囊 / 条 ---- */
        . '.stx-tag{display:inline-block;padding:1px 8px;border-radius:99px;font-size:12px;white-space:nowrap;'
        . 'background:#eef1ea;color:#555}'
        . '.stx-t1{background:#e1f5ee;color:#0f6e56}'
        . '.stx-t2{background:#faeeda;color:#854f0b}'
        . '.stx-t3{background:#fcebeb;color:#a32d2d}'
        . '.stx-lk{display:inline-block;padding:2px 10px;border-radius:6px;background:#e1f5ee;color:#0f6e56;'
        . 'font-size:12px;white-space:nowrap;text-decoration:none}'
        . '.stx-lk:hover{background:#1d9e75;color:#fff}'
        . '.stx-bar{display:inline-block;background:#eef1ea;height:8px;border-radius:99px;overflow:hidden;min-width:70px;'
        . 'width:96px;vertical-align:middle}'
        . '.stx-bar i{display:block;background:#1d9e75;height:100%}'
        /* ---- 热力网格（照老版 5 档绿） ---- */
        . '.stx-cmap{padding:6px;border:1px solid #eef1ea;border-radius:8px;background:#fff}'
        . '.stx-cm{width:100%;border-collapse:separate;border-spacing:2px;table-layout:fixed}'
        . '.stx-cm td{background:#f4f7f3;border-radius:2px;height:20px;text-align:center;font-size:10px;color:#5a2020}'
        . '.stx-cm td.h1{background:#bfe8d8}.stx-cm td.h2{background:#7fd4b4}'
        . '.stx-cm td.h3{background:#37b588}.stx-cm td.h4{background:#1d9e75;color:#fff}'
        . '.stx-cm th{font-weight:400;color:#9aa0a6;font-size:10px;text-align:center;padding:0 1px}'
        . '.stx-cmap-cap{display:flex;gap:8px;align-items:center;margin-top:8px;font-size:12px;color:#888;flex-wrap:wrap}'
        . '.stx-cmap-cap i{display:inline-block;width:14px;height:10px;border-radius:2px;background:#f4f7f3}'
        . '.stx-cmap-cap i.h1{background:#bfe8d8}.stx-cmap-cap i.h2{background:#7fd4b4}'
        . '.stx-cmap-cap i.h3{background:#37b588}.stx-cmap-cap i.h4{background:#1d9e75}'
        /* ---- 时间线（照老版 .live-row） ---- */
        . '.stx-timeline{margin-top:4px}'
        . '.stx-live-row{display:flex;gap:12px;padding:7px 0;border-bottom:1px solid #f0f3ee;font-size:13px;'
        . 'flex-wrap:wrap;align-items:center}'
        . '.stx-live-row .stx-t{color:#999;min-width:86px;font-variant-numeric:tabular-nums}'
        . '.stx-lr-main{flex:1;min-width:180px;word-break:break-all}'
        . '.stx-lr-tail{color:#999;font-size:12px;white-space:nowrap}'
        /* ---- 分页器（照老版 .pager） ---- */
        . '.stx-pager{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}'
        . '.stx-pager a{padding:4px 10px;border-radius:99px;background:#fff;border:1px solid #d8e2da;font-size:12px;'
        . 'color:#555;text-decoration:none}'
        . '.stx-pager a:hover{border-color:#1d9e75;color:#0f6e56}'
        . '.stx-pager a.on{background:#1d9e75;color:#fff;border-color:#1d9e75}'
        /* ---- 仪表盘站点卡片网格（照老版 .grid / .scard） ---- */
        . '.stx-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;margin-bottom:16px}'
        . '.stx-scard{background:#fff;border:1px solid #e4e8e0;border-radius:10px;padding:14px 16px}'
        . '.stx-scard h3{font-size:15px;margin:0 0 8px}'
        . '.stx-scard h3 a{color:#173f33;text-decoration:none}'
        . '.stx-scard h3 a:hover{color:#1d9e75}'
        . '.stx-scard .row{display:flex;justify-content:space-between;font-size:13px;color:#666;margin:3px 0;gap:10px}'
        . '.stx-scard .row b{color:#1d9e75;font-variant-numeric:tabular-nums}'
        . '.stx-acts{margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}'
        . '.stx-btn{display:inline-block;padding:5px 13px;border-radius:8px;background:#1d9e75;color:#fff;'
        . 'font-size:13px;text-decoration:none;border:0;cursor:pointer}'
        . '.stx-btn:hover{background:#0f6e56;color:#fff}'
        . '.stx-btn.ghost{background:#fff;color:#3f4650;border:1px solid #dfe2e7}'
        . '.stx-btn.ghost:hover{background:#f6f7f9;color:#20252c}'
        . '.stx-btn.danger{background:#a32d2d}.stx-btn.danger:hover{background:#8f1c12}'
        . '.stx-btn.sm{padding:2px 10px;font-size:12px}'
        . '.stx-link{color:#1d9e75;text-decoration:none;font-size:12px}'
        . '.stx-link:hover{color:#0f6e56;text-decoration:underline}'
        /* ---- 表单零件（只用在本插件的设置/保留页；页面筛选一律用链接，不用表单 —— 照老版） ---- */
        . '.stx-form{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;font-size:12.5px}'
        . '.stx-fld{display:flex;flex-direction:column;gap:3px}'
        . '.stx-fld label{color:#777;font-size:12px}'
        . '.stx-fld input,.stx-fld select,.stx-fld textarea{padding:6px 10px;border:1px solid #d5dbd2;'
        . 'border-radius:8px;font-size:13px;background:#fff;color:#222}'
        . '.stx-fld textarea{width:100%;min-height:220px;font-family:ui-monospace,Menlo,Consolas,"Courier New",monospace;'
        . 'line-height:1.7}'
        . '.stx-wide{width:100%}'
        . '.stx-svg{width:100%;height:auto;display:block;background:#fff;border-radius:6px}'
        . '.stx-legend{display:flex;gap:14px;font-size:12px;color:#666;margin-top:6px;flex-wrap:wrap}'
        . '.stx-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px}'
        . '.stx-two{display:flex;gap:15px;flex-wrap:wrap}'
        . '.stx-two>div{flex:1 1 340px;min-width:300px}'
        . '.stx-foot{color:#9aa6a0;font-size:12px;padding:14px 0 4px;margin-top:6px;border-top:1px solid #e4e8e0}'
        . '.stx-detail{border:1px solid #e4e8e0;border-radius:10px;padding:8px 14px;margin:14px 0;background:#fbfbfa}'
        . '.stx-detail>summary{cursor:pointer;font-weight:600;font-size:13px;color:#3d4650}'
        . '.stx-cell{font-variant-numeric:tabular-nums}'
        /* ★★ r7「页面全屏」收尾（**必须放在样式表最后**：上面的 `.stx-muted/.stx-empty` 同名规则
         *   在后、优先级相同，放前面会被它们盖掉）：
         *   表格/卡片跟着铺满右栏，但**纯文字段落与表单输入**要有合理上限 —— 超宽屏（1920/2560）
         *   下把一段说明拉成 180 字一行是没法读的。这里只限"读的东西"，不限"看的表格"。 */
        . '.stx-muted,.stx-empty,.stx-note,.stx-block{max-width:78ch}'
        . '.stx-fld>input,.stx-fld>select{max-width:760px}'
        . '.stx-fld>textarea{max-width:100%}'
        . '@media print{.stx-csv,.stx-days,.stx-daypick,.stx-topbar .stx-sw,.stx-side{display:none}}'
        . '</style>';
}

/* ============================== 页面外壳 ============================== */

/**
 * 把"页面区块 HTML"装进老版外观的整页外壳。
 * @param array $ctx   上下文（p / r / site / range_label / from / to …）
 * @param array $sites 站点清单（站点切换条用）
 * @param string $body 正文区块
 * @param array $opt   ['notice'=>html, 'filters'=>html, 'bare'=>bool, 'foot'=>html]
 */
function stx_shell(array $ctx, array $sites, string $body, array $opt = []): string
{
    $h = stx_css();
    // ★ `head` 选项在**正文最前面**输出（`<meta http-equiv="refresh">` 放在 body 顶部同样生效）——
    //   内核的 `<head>` 由 admin/index.php 输出，插件拿不到，所以只能这样注入；这是老版实时页同款机制。
    if (!empty($opt['head'])) { $h .= (string)$opt['head']; }
    $h .= '<div class="stx-app">';
    $h .= !empty($opt['bare']) ? '' : stx_sidebar($ctx, $sites);
    $h .= '<main class="stx-main">';
    if (empty($opt['bare'])) { $h .= stx_topbar($ctx, $sites); }
    if (!empty($opt['filters'])) { $h .= '<div class="stx-filters">' . $opt['filters'] . '</div>'; }
    if (!empty($opt['notice'])) { $h .= $opt['notice']; }
    $h .= $body;
    if (!empty($opt['foot'])) { $h .= '<div class="stx-foot">' . $opt['foot'] . '</div>'; }
    $h .= '</main></div>';
    return $h;
}

/** 站点页三件套（时间范围 + 日期选择器），老版每页标配 */
function stx_filters(array $ctx, array $bounds): string
{
    if (!stx_page_is_site($ctx)) { return ''; }
    return stx_days($ctx) . stx_daypick($ctx, $bounds);
}
