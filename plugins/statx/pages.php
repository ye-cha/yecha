<?php
declare(strict_types=1);
/**
 * StatX · 页面路由 + 时间维度（批次 3 的骨架）
 *
 * ============================ 这一层解决什么 ============================
 * 老版 tj 的后台是"47 条路由 + 左栏 6 分组导航"，每个站点页顶部都有**三件套**：
 * 时间范围胶囊（今日/昨日/7/30/90）· 日期选择器（跳到任意一天）· 站点切换。
 * 本文件实现这三件事的**统一口径**（所有页面共用，一处改处处改），并把页面名派发到各页 builder。
 *
 * ★ 时间维度的三条硬规则：
 *   ① 范围解析只有一个函数 `statx_ctx()`（今日 / 昨日 / 近 7/30/90 天 / 指定某一天）
 *      —— 老版是 `days=1/2/7/30/90` + `?d=`，本插件统一成 `?r=`，语义一一对应：
 *         `r=today` ← days=1 · `r=yesterday` ← days=2 · `r=7d` ← days=7 · `r=30d` ← days=30 · `r=90d` ← days=90
 *         `r=day&d=YYYY-MM-DD` ← `/day?d=`
 *   ② **日期在视图间继承**：切页面 / 切站点 / 切视图时，`r` 原样带下去（老版由中间件实现）。
 *   ③ **超范围不静默**：未来日期、早于"保留清理边界"的日期、早于"有数据的第一天"的日期，
 *      一律给出**一句人话说明**（老版的原文提示是"超过 30 天的原始访问明细已自动按天汇总并清除…"）。
 *
 * ★ 站点清单来自插件自带 SQLite 的 `sites` 表（老版是 MySQL 的 sites 表 + 14 个站点胶囊）。
 *   老版默认进"最近有数据的站"；本插件保留批次 2 的聪明做法：**区间内记录最多的站**，
 *   但**界面上现在有站点胶囊了**（照老版），不再需要靠猜。
 */

require_once __DIR__ . '/sdb.php';
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/derive.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/links.php';
require_once __DIR__ . '/botlib.php';
require_once __DIR__ . '/collect.php';
require_once __DIR__ . '/dash.php';
require_once __DIR__ . '/agg.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/stxui.php';

/** 本批新增的聚合/页面文件（admin.php 与 cli.php 都会用到，写在一处） */
function statx_pages_requires_adv(): void
{
    require_once __DIR__ . '/adv.php';
}

/* ============================== 时间维度 ============================== */

/**
 * 数据边界（用于日期选择器的 min/max 与"超范围"提示）。
 * @return array{data_min:string,data_max:string,keep_days:int,detail_min:string,ui_min:string,ui_max:string,rows:int}
 */
function statx_bounds(): array
{
    static $b = null;
    if ($b !== null) { return $b; }
    $today = date('Y-m-d');
    $keep  = max(1, (int)statx_cfg('collect.keep_days'));
    try {
        $minD = (string)statx_sdb_val('SELECT COALESCE(MIN(d), "") FROM ' . STATX_SDB_HITS);
        $maxD = (string)statx_sdb_val('SELECT COALESCE(MAX(d), "") FROM ' . STATX_SDB_HITS);
        $rows = (int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_HITS);
        // ★ 2026-09-14 新增：今日人类 PV —— 供「今日暂无数据」提示用。
        //   刷新后的论据不变：这一条就在这个函数里，与其它边界同一次连接，避免额外开销。
        $todayPv = (int)statx_sdb_val(
            'SELECT COUNT(*) FROM ' . STATX_SDB_HITS . " WHERE d=? AND kind='pv' AND is_bot=0", [date('Y-m-d')]);
    } catch (Throwable $e) {
        $minD = $maxD = ''; $rows = 0; $todayPv = 0;
    }
    // 明细保留窗口（老版 30 天）：边界按配置算，不写死
    $detailMin = date('Y-m-d', strtotime('-' . ($keep - 1) . ' day'));
    // 日期选择器可选范围 = 明细窗口 ∩ [有数据的最早一天, 今天]
    $uiMin = $detailMin;
    if ($minD !== '' && $minD > $uiMin) { $uiMin = $minD; }
    $uiMax = $today;
    if ($uiMin > $uiMax) { $uiMin = $uiMax; }
    return $b = [
        'data_min'   => $minD !== '' ? $minD : '（还没有数据）',
        'data_max'   => $maxD !== '' ? $maxD : '（还没有数据）',
        'keep_days'  => $keep,
        'detail_min' => $detailMin,
        'ui_min'     => $uiMin,
        'ui_max'     => $uiMax,
        'rows'       => $rows,
        'today_pv'   => $todayPv,
    ];
}

/**
 * ★ 默认时间档（2026-09-15 站长指令：改回「今日」）
 *
 * 站长原话："默认进入的站点应该是今天"。上一版（2026-09-14）默认「近 7 天」，
 * 当时的理由是"刚过零点打开几乎每站都是 0，看起来像统计坏了" —— 但那个"全是 0"
 * 的**真因是仪表盘缓存 bug**（`statx_ui_sites()` 的两个调用方共用一份缓存、先 false
 * 后 true 互相污染；已在 r6 修掉：缓存按 `$withStats` 分两份存）。
 * 真因既已修掉，「今日」这个档本身没有问题 ⇒ 按站长指令改回 `'today'`。
 *
 * ★ 配套的"0 值提示"在 `statx_ctx()` 里：`r=today` 且全库今日 PV 为 0
 *   （或所选站点今日为 0）时给一句"今日暂无数据（服务器时间刚过零点？）→ 切到近 7 天"，
 *   所以不存在"打开一片 0 又不知道能怎么办"的情况。
 * ★ 回滚：把返回值改回 `'7d'` 即可（仅此一处）。
 */
function statx_default_range(): string
{
    return 'today';
}

/** 单日请求是否合法（`Y-m-d` 且真实存在的日期） */
function statx_valid_date(string $d): bool
{
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $d)) { return false; }
    [$y, $m, $dd] = array_map('intval', explode('-', $d));
    return checkdate($m, $dd, $y);
}

/**
 * 解析页面上下文。$defaultPage 由调用方给（默认 dash）。
 * @return array{p:string,r:string,single:string,from:string,to:string,range_label:string,
 *               site:string,site_label:string,site_auto:bool,q:array,notice:string,v:array}
 */
/**
 * ★★ 2026-09-15 新增：请求域名 → 本实例「已登记站点」的 site_key（没有则返回 ''）。
 *
 * 为什么需要它（站长 2026-09-15 的要求）：
 *   · 这台机器是**独立统计站**（`tj.51puer.com`，19 个站的数据都汇到这里）。
 *     「当前域名」`tj.51puer.com` 在 `sites` 表里只有两条**占位行**（`tj` / `tj-real`，`enabled=0`
 *     —— 它们是"统计后端自己的健康目标"，不是被统计的站）⇒ 按定义**没登记为站点**。
 *   · 别人把本插件装到**自己的博客**上时，「当前域名」就是他自己的站 ⇒ 默认必须是**他自己的站**，
 *     绝不能被"区间内记录最多的站"顶掉（否则他会以为统计坏了）。
 *   ⇒ 所以默认口径必须**按域名分流**：
 *        当前域名 **已登记为站点（enabled=1）** ⇒ 默认 = 该站；
 *        当前域名 **未登记**（统计服务器场景）  ⇒ 才允许"区间内记录最多的站"兜底，并在页面上写明。
 *
 * 只认 `enabled=1`：`enabled=0` 是「本机找不到该站站点根」的占位行（见 statx_collect_seed_sites）。
 * 命中判定：`sites.host` 或 `sites.domains`（逗号/空白分隔）里的任一个；
 *          两边都去掉端口与 `www.` 前缀再比，避免 `www.example.com` 与 `example.com` 互相漏掉。
 */
function statx_host_site_key(string $host): string
{
    $h = strtolower(trim($host));
    if ($h === '') { return ''; }
    $h = (string)preg_replace('~:\d+$~', '', $h);      // 去端口（Host: example.com:8080）
    $h = (string)preg_replace('~^www\.~', '', $h);
    if ($h === '' || !preg_match('~^[a-z0-9.\-]+$~', $h)) { return ''; }
    try {
        foreach (statx_sdb_all('SELECT site_key, host, domains FROM ' . STATX_SDB_SITES
            . ' WHERE enabled=1 ORDER BY sort, site_key') as $s) {
            $cands = [(string)$s['host']];
            foreach (preg_split('~[\s,;]+~', (string)$s['domains']) ?: [] as $d) { $cands[] = $d; }
            foreach ($cands as $c) {
                $c = strtolower(trim((string)$c));
                if ($c === '') { continue; }
                $c = (string)preg_replace('~^[a-z]+://~', '', $c);   // 容忍写成 https://x.com
                $c = (string)preg_replace('~[/:].*$~', '', $c);      // 去路径/端口
                $c = (string)preg_replace('~^www\.~', '', $c);
                if ($c !== '' && $c === $h) { return (string)$s['site_key']; }
            }
        }
    } catch (Throwable $e) { return ''; }
    return '';
}

function statx_ctx(string $defaultPage = 'dash'): array
{
    $today = date('Y-m-d');

    $p = (string)preg_replace('~[^a-z_0-9]~', '', (string)($_GET['p'] ?? ''));
    if ($p === '') { $p = $defaultPage; }
    $titles = stx_titles();
    if (!isset($titles[$p])) { $p = $defaultPage; }

    // ★ 批次 2 的 `?v=` 兼容映射：`cli.php --task=dash`（服务端自检）与老书签都还在用它。
    //   本批实测踩到两件事，所以这里的条件必须**严格**：
    //     ① 不映射的话 `--task=dash` 会变成"每个组合都渲染同一页"甚至静默退化；
    //     ② 映射条件太宽（只看 p 缺省）会把**仪表盘页**也误判成"总览" ——
    //        实测 `?p=dash` 直接渲染成"网站概况 · 今日 · 无站点"的空态。
    //   ⇒ 只在**请求里真的带了 `v`** 时才映射。
    if ($p === $defaultPage && isset($_GET['v'])) {
        $vLegacy = (string)preg_replace('~[^a-z]~', '', (string)$_GET['v']);
        $map = ['' => 'overview', 'pages' => 'pages', 'src' => 'source', 'vis' => 'env', 'deep' => 'clickmap'];
        if (array_key_exists($vLegacy, $map) && isset($titles[$map[$vLegacy]])) { $p = $map[$vLegacy]; }
    }

    $single = '';
    $rawD = (string)preg_replace('~[^0-9\-]~', '', (string)($_GET['d'] ?? ''));
    $r = (string)preg_replace('~[^a-z0-9]~', '', (string)($_GET['r'] ?? ''));

    if ($r === 'day' || ($r === '' && $rawD !== '')) {
        // ★ 指定某一天（老版 `?d=`；日期非法 → 回落到今日并给提示，不静默）
        if (statx_valid_date($rawD)) { $single = $rawD; $r = 'day'; }
        else { $r = statx_default_range(); }
    }
    if ($r === '') { $r = statx_default_range(); }
    if (!in_array($r, ['today', 'yesterday', '7d', '30d', '90d', 'day'], true)) { $r = statx_default_range(); }

    $from = $today; $to = $today; $label = '今日';
    if ($r === 'yesterday')      { $from = $to = date('Y-m-d', strtotime('-1 day')); $label = '昨日'; }
    elseif ($r === '7d')         { $from = date('Y-m-d', strtotime('-6 day'));  $to = $today; $label = '近 7 天'; }
    elseif ($r === '30d')        { $from = date('Y-m-d', strtotime('-29 day')); $to = $today; $label = '近 30 天'; }
    elseif ($r === '90d')        { $from = date('Y-m-d', strtotime('-89 day')); $to = $today; $label = '近 90 天'; }
    elseif ($r === 'day')        { $from = $to = $single; $label = $single . '（单日）'; }

    // ---- 站点：显式 ?site= 优先；否则**按"当前请求域名是否已登记为站点"分流**（2026-09-15 修） ----
    //   ★ 老行为（唯一口径）：`?site=` 缺省时一律取"区间内记录最多的站"。
    //     在统计服务器上这会让人以为"默认进的就是这台机器的站"，而 `tj.51puer.com` 其实不是被统计的站；
    //     在别人自己的博客上，它也**可能**把他顶到别的站去（多站登记的实例里就会）。
    //   ★ 新口径：见 statx_host_site_key() 的说明。`$autoWhy` 只用于页面上把"为什么是这个站"讲清楚。
    $siteReq = (string)preg_replace('~[^A-Za-z0-9._:@\-]~', '', (string)($_GET['site'] ?? ''));
    $site = $siteReq; $auto = false; $autoWhy = '';
    if ($site === '') {
        $reqHost = (string)($_SERVER['HTTP_HOST'] ?? '');
        $own = statx_host_site_key($reqHost);
        if ($own !== '') {
            // ① 当前域名**已登记为站点** ⇒ 默认就是它自己（别人装到自己站上：行为不变，且更准）
            $site = $own; $auto = true; $autoWhy = 'host';
        } else {
            // ② 当前域名**未登记**（统计服务器 / 未登记域名）⇒ 才用"区间内记录最多的站"兜底
            try {
                $row = statx_sdb_one('SELECT site_key k, COUNT(*) c FROM ' . STATX_SDB_HITS
                    . ' WHERE d>=? AND d<=? GROUP BY site_key ORDER BY c DESC, k LIMIT 1', [$from, $to]);
                $site = (string)($row['k'] ?? '');
                $auto = ($site !== '');
                $autoWhy = $auto ? 'busiest' : '';
            } catch (Throwable $e) { $site = ''; }
        }
    }
    $siteLabel = '';
    if ($site !== '') {
        try {
            $s = statx_sdb_one('SELECT label FROM ' . STATX_SDB_SITES . ' WHERE site_key=?', [$site]);
            $siteLabel = (string)($s['label'] ?? '');
        } catch (Throwable $e) { $siteLabel = ''; }
    }

    // ---- 页面级查询参数白名单（**不在白名单里的一律丢弃**，防止把任意 GET 拼进 SQL/HTML） ----
    $q = [];
    foreach (['uid', 'url', 'h', 'kw', 't', 'eid', 'st'] as $k) {
        $v = (string)($_GET[$k] ?? '');
        if ($v === '') { continue; }
        if ($k === 'url') { $q[$k] = mb_substr($v, 0, 300); }
        elseif ($k === 'h') { $q[$k] = mb_substr((string)preg_replace('~[^A-Za-z0-9._:\-]~', '', $v), 0, 120); }
        else { $q[$k] = mb_substr((string)preg_replace('~[^\x20-\x7E\x{4e00}-\x{9fff}]~u', '', $v), 0, 120); }
    }

    $ctx = ['p' => $p, 'r' => $r, 'single' => $single, 'from' => $from, 'to' => $to, 'range_label' => $label,
            'site' => $site, 'site_label' => $siteLabel, 'site_auto' => $auto, 'site_auto_why' => $autoWhy,
            'q' => $q, 'notice' => ''];

    $b = statx_bounds();
    // ---- 超范围 / 未来日期的提示（★ 不静默：给出范围与原因） ----
    if ($r === 'day') {
        if ($single > $today) {
            $ctx['notice'] = '⚠ 选的是**未来日期** <b>' . e($single) . '</b>（今天是 ' . e($today) . '）'
                . ' —— 后面当然不会有数据。已按这一天查询，范围上限是 <b>' . e($today) . '</b>。';
        } elseif ($b['rows'] === 0) {
            $ctx['notice'] = '⚠ 库里还没有任何采集记录 —— 先到「设置」确认采集总开关与站点登记。';
        } elseif ($single < (string)$b['data_min']) {
            $ctx['notice'] = '⚠ <b>' . e($single) . '</b> 早于本插件**有数据的第一天** <b>' . e($b['data_min']) . '</b>'
                . ' ⇒ 这一天没有记录（statx 上线之前的日子，数据库里本来就没有）。';
        } elseif ($single < (string)$b['detail_min']) {
            $ctx['notice'] = '⚠ <b>' . e($single) . '</b> 已超过明细保留窗口（近 ' . (int)$b['keep_days'] . ' 天，'
                . '当前边界 <b>' . e($b['detail_min']) . '</b>）⇒ 逐条明细已被"按天汇总后清除"，'
                . '这一天只剩汇总里的 PV/UV，看不出访客与页面明细。';
        }
    }

    // ★★ 2026-09-14【站长的真实抱怨】「今日暂无数据」提示
    //   现象：凌晨打开后台（默认档曾是“今日”），几乎每个站都是 0 ⇒ 看起来统计坏了。
    //   事实：采集一直正常，只是自然日刚翻页（实测 01:29 还有上报）。默认档已改为近 7 天；
    //   这里再补一句明确、可点、带数字的提示，避免“看上去每个都没数据”的误判。
    //   ★ 两层判定：① **全库**今日人类 PV 为 0（最强信号）；
    //     ② 其实更常见的是「全库有但**当前这个站**今日还是 0」—— 这才是站长看到的那一幕。
    //     为此额外查一条当前站的今日行数（走 idx_hits_site_d 索引，仅在今日档下才执行）。
    $siteTodayZero = false;
    if ($r === 'today' && $site !== '') {
        try {
            $siteTodayZero = ((int)statx_sdb_val(
                'SELECT COUNT(*) FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND d=?', [$site, $today]) === 0);
        } catch (Throwable $e) { $siteTodayZero = false; }
    }
    if ($r === 'today' && ((int)($b['today_pv'] ?? 0) === 0 || $siteTodayZero)) {
        $seven = statx_ui_url($ctx, ['r' => '7d', 'd' => null]);
        $extra = ((int)$b['rows'] > 0)
            ? '（库里共有 <b>' . (int)$b['rows'] . '</b> 条采集记录，最近有数据的一天是 <b>' . e((string)$b['data_max']) . '</b>）'
            : '';
        $ctx['notice'] = 'ℹ <b>今日暂无数据</b>' . $extra . '。'
            . '这是**正常的自然日翻页**（若刚过零点，今日自然还是空的），不是采集故障 —— '
            . '<a href="' . e($seven) . '">切到「近 7 天」查看</a>'
            . '（本页默认档是「今日」，正是站长指定；今日为 0 时点上面那个链接即可）。';
    }

    // ★★ 2026-09-15 新增：**自动选站必须在页面上说清"现在看的是哪个站、为什么是它"**
    //   触发条件只有两个：① `?site=` 缺省且当前请求域名未登记为站点（统计服务器场景）；
    //                    ② 当前页**确实是按站点筛的**（仪表盘/设置这类系统页本身一屏看全部站，
    //                       那里再说"当前站点 = X"只会让人以为它被筛了）。
    //   为什么别人自己装机时不用提示：他的默认站就是他自己，不需要解释。
    if ($auto && $autoWhy === 'busiest' && $site !== '' && stx_page_is_site($ctx)) {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $sl   = $siteLabel !== '' ? stx_site_short_name($siteLabel, $site) : $site;
        $n    = 0;
        try { $n = (int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_SITES); }
        catch (Throwable $e) { $n = 0; }
        $ctx['notice'] .= 'ℹ <b>当前站点：' . e($sl) . '</b>（<code>' . e($site) . '</code>）—— '
            . '这是<b>统计服务器</b>：请求域名 <code>' . e($host) . '</code> 没有登记为站点'
            . '（`sites` 里 `tj` / `tj-real` 是统计后端自己的健康目标行，`enabled=0`），'
            . '所以按「' . e($label) . '内记录最多的站」自动选了一个。'
            . '换站：点右上角站点胶囊；看<b>全部站点汇总</b>：点胶囊里的「全部站点」，'
            . ($n > 0 ? '或直接进 <a href="' . e(statx_ui_url($ctx, ['p' => 'dash', 'site' => ''])) . '">仪表盘</a>'
                      . '（一屏 ' . $n . ' 个站）。' : '或直接进「仪表盘」。');
    }
    // r7：提示文案里一直写着 Markdown 式 `**加粗**`，模板却从不解析 ⇒ 页面上显示成字面星号。
    //     这里统一收口一次（见 statx_notice_md 的说明）。
    if ($ctx['notice'] !== '') { $ctx['notice'] = statx_notice_md((string)$ctx['notice']); }
    return $ctx;
}

/**
 * 提示条里的 `**加粗**` 小标记 → `<strong>`（r7 新增）
 *
 * 背景：本插件的提示文案（`$ctx['notice']`）**从一开始就是按 Markdown 习惯写的**
 * （`**正常的自然日翻页**`、`**未来日期**` …），但模板从来不解析它 ⇒ 浏览器里
 * 直接显示成一对字面星号（实测：`?p=overview&site=teayear.com` 的"今日暂无数据"提示）。
 * 老版 tj 的提示是纯文本，所以这不是"回归"，是**一直没做完的一半** —— 顺手补齐。
 * 只处理**成对的** `**…**`，不跨行、不跨越既有标签，避免误伤；输入里的 `<` `>`
 * 不会被再解释一次（正则不允许匹配跨越 `<` / `>` 的片段）。
 */
function statx_notice_md(string $html): string
{
    if (strpos($html, '**') === false) { return $html; }
    $out = preg_replace('~\*\*(?!\s)([^*<>\n]{1,200}?)\*\*~u', '<strong>$1</strong>', $html);
    return $out === null ? $html : $out;
}

/* ============================== 全站小工具 ============================== */

/** 区间内"有数据的站点"清单（站点切换条用它排序：有数据的在前，照老版顺序） */
function statx_ui_sites_ordered(string $from, string $to): array
{
    $sites = statx_ui_sites(false);
    $cnt = [];
    try {
        foreach (statx_sdb_all('SELECT site_key k, COUNT(*) c FROM ' . STATX_SDB_HITS
            . ' WHERE d>=? AND d<=? GROUP BY site_key', [$from, $to]) as $r) {
            $cnt[(string)$r['k']] = (int)$r['c'];
        }
    } catch (Throwable $e) { $cnt = []; }
    foreach ($sites as &$s) {
        $k = (string)$s['site_key'];
        $s['c_in_range'] = $cnt[$k] ?? 0;
        $s['pv'] = $s['c_in_range'];
    }
    unset($s);
    usort($sites, static function ($a, $b) {
        if ($b['c_in_range'] !== $a['c_in_range']) { return $b['c_in_range'] <=> $a['c_in_range']; }
        return strcmp((string)$a['site_key'], (string)$b['site_key']);
    });
    return $sites;
}

/** 页面总数（导航里出现过的页面数；报告用） */
function statx_page_count(): int
{
    $n = 0;
    foreach (stx_nav() as $g) { $n += count($g[3]); }
    return $n + 1;   // +1 = 访客轨迹页（不在导航里，由「用户列表」下钻进入，老版也是这样）
}

/* ============================== 向后兼容（批次 2 的两个入口） ============================== */

/**
 * `statx_dash_page()` —— **批次 2 的入口名，保留为兼容壳**（本批界面整体重做，但它还有调用方）：
 *   `cli.php --task=dash`（服务端自检：渲染字节数 / SQL 条数 / 耗时）就是调它。
 *   ★ 本批实测踩到：重做时把这个函数删了 ⇒ `--task=dash` 变成"0 个视图组合"的**静默退化**
 *     （任务"成功"但没有输出，正是最危险那种回归）。⇒ 现在补回这个壳：转发到新的 `statx_render_page()`。
 * 旧签名 `statx_dash_page()` / 新签名 `statx_dash_page($ctx)` 都接受。
 */
function statx_dash_page(?array $ctx = null): string
{
    if ($ctx === null) { $ctx = statx_ctx('dash'); }
    require_once __DIR__ . '/adv.php';
    return statx_render_page($ctx);
}

/* ============================== 页面注册表（CSV 导出按 id 取同一个 builder） ============================== */

function statx_pages_registry(): array
{
    static $reg = null;
    if ($reg !== null) { return $reg; }
    require_once __DIR__ . '/pages2.php';
    return $reg = [
        // 系统
        'dash'        => 'statx_pg_dash',
        'sites_grid'  => 'statx_pg_sites_grid',
        // 分析
        // ★ 这三个是**批次 2 遗留的缺函数**（实测用「注册表 → 函数是否存在」自检抓到）：
        //   批次 2 之后 `statx_c_kpi/trend/hours` 从未存在过 ⇒ `<h2>趋势明细（按天）</h2>` 与
        //   时段分布卡片上的 `CSV` 链接会 404（页面本身正常，只有点导出才炸）。现改用真实存在的函数。
        'kpi'         => 'statx_dash_kpi',
        'trend'       => 'statx_dash_trend',
        'hours'       => 'statx_c_hours_csv',
        'days'        => 'statx_c_days',
        'page'        => 'statx_dash_top_page',
        // ★ 这三条是「受访页面 / 入口页面 / 退出页面」三页上显示的 **TOP 50 表**（与上面的 Top10 版不同 id）——
        //   实测踩到：只登记了 Top10 版，页面上那三个 `CSV` 链接会 404。用"把页面上真实出现的 CSV 链接
        //   全部点一遍"的方法抓到的（`/tmp/b3-csv.sh`）。
        'page50'      => 'statx_c_pages50',
        'entry50'     => 'statx_c_entry50',
        'exit50'      => 'statx_c_exit50',
        'ref'         => 'statx_dash_top_ref',
        'kw'          => 'statx_dash_top_kw',
        'geo'         => 'statx_dash_top_geo',
        'links'       => 'statx_dash_links',
        'entry'       => 'statx_dash_entry',
        'exit'        => 'statx_dash_exit',
        'depth'       => 'statx_dash_depth',
        'rtype'       => 'statx_dash_rtype',
        'ext'         => 'statx_dash_ext',
        'utm'         => 'statx_dash_utm',
        'dev'         => 'statx_dash_dev',
        'bow'         => 'statx_dash_browser',
        'os'          => 'statx_dash_os',
        'newv'        => 'statx_dash_new',
        'geov'        => 'statx_dash_geo_full',
        'res'         => 'statx_dash_res',
        'lang'        => 'statx_dash_lang',
        'ipver'       => 'statx_dash_ipver',
        'elen'        => 'statx_dash_elen',
        'heat'        => 'statx_dash_heat',
        'flow'        => 'statx_dash_flow',
        'conv'        => 'statx_dash_conv',
        'cpath'       => 'statx_dash_cpath',
        // 批次 3 新增
        'domain'      => 'statx_c_domain',
        'paths1'      => 'statx_c_paths1',
        'paths2'      => 'statx_c_paths2',
        'search'      => 'statx_c_ref_search',
        'keyword'     => 'statx_c_keyword',
        'extdet'      => 'statx_c_ext_detail',
        'intdet'      => 'statx_c_int_detail',
        'events'      => 'statx_c_events',
        'events_rec'  => 'statx_c_events_recent',
        'users'       => 'statx_c_users',
        'bots'        => 'statx_c_bots',
        'bots_rec'    => 'statx_c_bots_recent',
        'alerts'      => 'statx_c_alerts',
        'chk_items'   => 'statx_c_check_items',
        'health'      => 'statx_c_health',
        'pipeline'    => 'statx_c_pipeline',
        'visits'      => 'statx_c_visitor_visits',
        'vpages'      => 'statx_c_visitor_pages',
        'sessions'    => 'statx_c_visitor_sessions',
    ];
}

/** 页面 → builder */
function statx_page_builder(string $p): ?callable
{
    require_once __DIR__ . '/adv.php';
    $map = [        'dash' => 'statx_pg_dash', 'bots_all' => 'statx_pg_bots_all', 'alerts_all' => 'statx_pg_alerts_all',
        'check' => 'statx_pg_check', 'settings' => 'statx_pg_settings',
        // ★ 2026-09-15 新增：站点管理 / 账号（实现在 adminops.php，由 adv.php require 进来）
        'sites_mgr' => 'statx_pg_sites_mgr', 'account' => 'statx_pg_account',
        'overview' => 'statx_pg_overview', 'traffic' => 'statx_pg_traffic', 'live' => 'statx_pg_live',
        'trend' => 'statx_pg_trend', 'day' => 'statx_pg_day',
        'source' => 'statx_pg_source', 'src_search' => 'statx_pg_src_search', 'src_keyword' => 'statx_pg_src_keyword',
        'src_ext' => 'statx_pg_src_ext', 'src_int' => 'statx_pg_src_int',
        'pages' => 'statx_pg_pages', 'entry' => 'statx_pg_entry', 'exit' => 'statx_pg_exit',
        'domain' => 'statx_pg_domain', 'paths' => 'statx_pg_paths', 'depth' => 'statx_pg_depth',
        'users' => 'statx_pg_users', 'clickmap' => 'statx_pg_clickmap', 'flow' => 'statx_pg_flow',
        'events' => 'statx_pg_events', 'insights' => 'statx_pg_insights',
        'conversion' => 'statx_pg_conversion', 'cpath' => 'statx_pg_cpath',
        'region' => 'statx_pg_region', 'env' => 'statx_pg_env', 'newold' => 'statx_pg_newold',
        'bots' => 'statx_pg_bots', 'alerts' => 'statx_pg_alerts',
        'collect' => 'statx_pg_collect', 'health' => 'statx_pg_health', 'retention' => 'statx_pg_retention',
        'visitor' => 'statx_pg_visitor', 'sites_grid' => 'statx_pg_sites_grid',
        'admin' => 'statx_pg_admin',
        // ★ 单站版：与全局版共用同一个 builder（builder 内部按 `$ctx['p']` 判断是否限定站点），
        //   所以这里必须显式登记 —— 实测踩到：漏登记会让左栏「监测」组里的「蜘蛛与 AI」「采集预警」
        //   两个**单站**菜单项渲染出"页面不存在：bots／alerts"，而全局版却是好好的。
        'bots' => 'statx_pg_bots', 'alerts' => 'statx_pg_alerts',
    ];
    $fn = $map[$p] ?? null;
    return ($fn !== null && function_exists($fn)) ? $fn : null;
}

/* ============================== 页脚：口径 + 渲染耗时 + SQL 条数 ============================== */

function statx_footer(float $t0, array $ctx, array $bounds): string
{
    $qs = statx_dash_qstat();
    $bits = [];
    $bits[] = '服务端渲染 <b>' . round((microtime(true) - $t0) * 1000, 1) . ' ms</b>';
    $bits[] = 'SQL <b>' . (int)$qs['n'] . '</b> 条 / ' . round((float)$qs['ms'], 1) . ' ms（全部聚合在 SQL 里：GROUP BY + LIMIT）';
    $bits[] = '口径：<b>PV/UV 只计人类流量</b>，爬虫单独成列；访客标识 = 客户端随机 uid（不存 IP）';
    $bits[] = '明细保留 <b>' . (int)$bounds['keep_days'] . '</b> 天 → 按天汇总永久保留';
    $bits[] = '有数据：<b>' . e((string)$bounds['data_min']) . '</b> ~ <b>' . e((string)$bounds['data_max']) . '</b>（'
        . number_format((int)$bounds['rows']) . ' 行明细）';
    return implode(' · ', $bits);
}

/* ============================== 公用的"每组一行"辅助 ============================== */

/** 按天分组的区间明细（老版 /traffic 的「按天」表：日期 / PV / UV / 爬虫） */
function statx_c_days(array $ctx): array
{
    $rows = statx_dash_q('SELECT d k,'
        . " SUM(kind='pv' AND is_bot=0) pv,"
        . ' COUNT(DISTINCT CASE WHEN kind=\'pv\' AND is_bot=0 THEN ' . STATX_DASH_UV . ' END) uv,'
        . " SUM(kind='pv' AND is_bot=1) bot, SUM(kind='cl') cl"
        . ' FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND d>=? AND d<=? GROUP BY k ORDER BY k DESC LIMIT 200',
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) {
        $r['k'] = e((string)$r['k']); $r['pv'] = (int)$r['pv']; $r['uv'] = (int)$r['uv'];
        $r['bot'] = (int)$r['bot']; $r['cl'] = (int)$r['cl'];
    }
    unset($r);
    return ['cols' => [['日期', 'k'], ['人类 PV', 'pv', 'r'], ['UV', 'uv', 'r'], ['爬虫 PV', 'bot', 'r'], ['点击', 'cl', 'r']],
            'rows' => $rows, 'bar' => 'pv', 'csv' => 'days',
            'title' => '按天明细（' . count($rows) . ' 天）',
            'note' => '口径：PV/UV 只计人类流量（`is_bot=0`），爬虫 PV 单独一列。'];
}

/** 采集链路的"业务问题"表（设置页 / 统计检测页共用） */
function statx_c_pipeline(array $ctx): array
{
    try {
        $pl = statx_q_pipeline();
        $rows = [
            ['k' => '采集总开关', 'v' => $pl['enabled'] ? '已开启' : '已关闭',
             'note' => $pl['enabled'] ? '' : '关闭时端点直接返回 204，站点侧脚本照样加载但不上报'],
            ['k' => '端点配置 rt.php', 'v' => $pl['rt_exists'] ? '已生成' : '缺失',
             'note' => '端点读它 → 零 SQL；缺失时到「设置」保存一次即可重新生成'],
            ['k' => '站点登记', 'v' => count($pl['sites']) . ' 个',
             'note' => '启用中的站会进 CORS Origin 白名单（不用通配符）'],
            ['k' => 'spool 积压', 'v' => (int)$pl['spool']['lines'] . ' 行 / ' . number_format((int)$pl['spool']['bytes']) . ' B',
             'note' => '目录 ' . (string)$pl['spool']['dir'] . '（中间产物绝不落 webroot）'],
            ['k' => '库内明细', 'v' => number_format((int)$pl['rows']) . ' 行',
             'note' => '最早一天 ' . ($pl['min_d'] !== '' ? $pl['min_d'] : '（还没有）')],
            ['k' => '最近一次上报', 'v' => $pl['last_hit'] !== '' ? $pl['last_hit'] : '（还没有）',
             'note' => $pl['last_hit'] === '' ? '★ 一条都没有 ⇒ 先查"站点登记/一行 JS/端点自检"三件事' : ''],
            ['k' => '口径分界日', 'v' => (string)$pl['cutover'],
             'note' => '与 tj 并存期间两套口径不同，**不要把新旧数字拼成一条曲线**'],
        ];
    } catch (Throwable $e) {
        return ['cols' => [['检查项', 'k'], ['结果', 'v'], ['说明', 'note']], 'rows' => [], 'bar' => '',
                'title' => '采集链路自检', 'empty' => '采集链路自检读取失败（详见服务器 PHP 错误日志）'];
    }
    return ['cols' => [['检查项', 'k'], ['结果', 'v'], ['说明', 'note']], 'rows' => $rows, 'bar' => '',
            'title' => '采集链路自检（这就是"为什么是 0"的答案）', 'csv' => 'pipeline'];
}
