<?php
declare(strict_types=1);
/**
 * StatX · 统计看板「取数层」（批次 2 建立，批次 3 起只保留取数）
 *
 * ============================ 本文件在批次 3 的角色 ============================
 * 批次 2 时本文件既是取数层又是页面层（自带 5 个视图 + 热图网格 + CSV 注册表）。
 * 批次 3 按站长要求「界面按老版 tj 重做」⇒ **页面层整体搬到 pages*.php / adv.php**，
 * 本文件退化成**纯粹的取数层**：每个 `statx_dash_*()` 返回一张表的 `['cols','rows','bar','title','note']`，
 * 由 `ui.php::stx_table()` 统一渲染、由 `stxui.php::statx_ui_csv_handle()` 统一导出。
 *
 * ★ 为什么要这么切：**页面与 CSV 用同一个函数取数** ⇒ 页面上的数字与导出的数字
 *   **结构上不可能分叉**（批次 2 的注册表就是为这个目的，批次 3 把它做得更彻底）。
 *
 * ★ 保留的硬约束（一条没变）：
 *   ① 每个聚合都在 SQL 里做（GROUP BY + LIMIT），**绝不把明细取回 PHP 再聚合**；
 *   ② 只读，一行都不写；
 *   ③ 参数一律占位符（`?`）；
 *   ④ 不新增任何索引（批次 1 起只有 idx_hits_site_d / idx_hits_site_uid 两个）。
 */

require_once __DIR__ . '/derive.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/links.php';

/** UV 口径（唯一权威定义，全看板共用）：客户端长期随机 uid；没有 uid 的老行退回不可逆 visitor_hash */
const STATX_DASH_UV = "COALESCE(NULLIF(uid,''),'#'||visitor_hash)";

/** 单条查询（带计时与计数；页脚的"服务端渲染 / SQL 条数"就是它累计出来的） */
function statx_dash_q(string $sql, array $p = []): array
{
    if (!isset($GLOBALS['statx_dash_qstat'])) { $GLOBALS['statx_dash_qstat'] = ['n' => 0, 'ms' => 0.0]; }
    $GLOBALS['statx_dash_qstat']['n']++;
    $t0 = microtime(true);
    try {
        $rows = statx_sdb_all($sql, $p);
    } catch (Throwable $e) {
        // ★ 服务端异常**只在页面上显示一句话**（批次 2 的规矩），绝不把 PDO 报错栈甩到页面上
        $GLOBALS['statx_dash_err'] = $e->getMessage();
        $rows = [];
    }
    $GLOBALS['statx_dash_qstat']['ms'] += (microtime(true) - $t0) * 1000;
    return $rows;
}

function statx_dash_qstat(): array
{
    return $GLOBALS['statx_dash_qstat'] ?? ['n' => 0, 'ms' => 0.0];
}

/** 常用 WHERE 片段 */
function statx_dash_w(bool $human = true, bool $pv = true): string
{
    return 'site_key=? AND d>=? AND d<=?' . ($pv ? " AND kind='pv'" : '') . ($human ? ' AND is_bot=0' : '');
}

/* ============================== 核心数字 ============================== */

function statx_dash_kpi(array $ctx): array
{
    $sql = 'SELECT'
        . " SUM(kind='pv' AND is_bot=0) pv,"
        . ' COUNT(DISTINCT CASE WHEN kind=\'pv\' AND is_bot=0 THEN ' . STATX_DASH_UV . ' END) uv,'
        . ' COUNT(DISTINCT CASE WHEN kind=\'pv\' AND is_bot=0 AND is_new=1 THEN ' . STATX_DASH_UV . ' END) newv,'
        . " COUNT(DISTINCT CASE WHEN kind='pv' AND is_bot=0 AND sid<>'' THEN sid END) sess,"
        . " SUM(kind='pv' AND is_bot=1) botpv,"
        . " SUM(kind='pv') allpv,"
        . " SUM(kind='cl' AND is_bot=0) clicks,"
        . " SUM(kind='pv' AND is_bot=0 AND region<>'') geopv,"
        . " SUM(kind='pv' AND is_bot=0 AND ipver=6) v6pv"
        . ' FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND d>=? AND d<=?';
    $r = statx_dash_q($sql, [$ctx['site'], $ctx['from'], $ctx['to']]);
    $r = $r[0] ?? [];
    $pv = (int)($r['pv'] ?? 0);
    $all = (int)($r['allpv'] ?? 0);
    $bot = (int)($r['botpv'] ?? 0);
    $rows = [
        ['k' => 'PV（人类）',   'v' => number_format($pv),                                        'c' => $pv],
        ['k' => 'UV（访客）',   'v' => number_format((int)($r['uv'] ?? 0)),                       'c' => (int)($r['uv'] ?? 0)],
        ['k' => '新访客',       'v' => number_format((int)($r['newv'] ?? 0)),                     'c' => (int)($r['newv'] ?? 0)],
        ['k' => '会话数',       'v' => number_format((int)($r['sess'] ?? 0)),                     'c' => (int)($r['sess'] ?? 0)],
        ['k' => '爬虫占比',     'v' => ($all > 0 ? round($bot / $all * 100, 1) : 0) . '%（' . number_format($bot) . ' 次）', 'c' => $bot],
        ['k' => '点击（热图）', 'v' => number_format((int)($r['clicks'] ?? 0)),                   'c' => (int)($r['clicks'] ?? 0)],
        ['k' => '地域覆盖',     'v' => ($pv > 0 ? round((int)($r['geopv'] ?? 0) / $pv * 100, 1) : 0) . '%'
            . '（IPv6 ' . ($pv > 0 ? round((int)($r['v6pv'] ?? 0) / $pv * 100, 1) : 0) . '%）', 'c' => (int)($r['geopv'] ?? 0)],
    ];
    return ['cols' => [['指标', 'k'], ['数值', 'v', 'r']], 'rows' => $rows, 'bar' => '',
            'title' => '核心数字',
            'note' => '口径：**PV/UV 只计人类流量**（`is_bot=0`）；UV = 客户端随机 uid 去重；'
                . '会话 = 客户端 `sid`；点击来自 `st.js` 的 click 监听；'
                . '「地域覆盖」是**有地域值的 PV 占比**（老行没有地域，因为它没有 IP —— 见下）。'];
}

/* ============================== 趋势 / 时段 ============================== */

function statx_dash_trend(array $ctx): array
{
    $H = STATX_SDB_HITS;
    $byHour = in_array($ctx['r'], ['today', 'yesterday', 'day'], true);
    if ($byHour) {
        $rows = statx_dash_q("SELECT substr(ts,12,2) b, SUM(kind='pv' AND is_bot=0) pv, SUM(kind='pv' AND is_bot=1) bot"
            . " FROM {$H} WHERE site_key=? AND d>=? AND d<=? GROUP BY b ORDER BY b",
            [$ctx['site'], $ctx['from'], $ctx['to']]);
    } else {
        $rows = statx_dash_q("SELECT d b, SUM(kind='pv' AND is_bot=0) pv, SUM(kind='pv' AND is_bot=1) bot"
            . " FROM {$H} WHERE site_key=? AND d>=? AND d<=? GROUP BY b ORDER BY b",
            [$ctx['site'], $ctx['from'], $ctx['to']]);
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['b' => (string)$r['b'], 'pv' => (int)$r['pv'], 'bot' => (int)$r['bot']];
    }
    return ['cols' => [['时间', 'b'], ['人类 PV', 'pv', 'r'], ['爬虫 PV', 'bot', 'r']], 'rows' => $out,
            'bar' => 'pv', 'title' => '趋势明细（' . ($byHour ? '按小时' : '按天') . '）',
            'right' => $ctx['from'] . ' ~ ' . $ctx['to'],
            'note' => '口径：`b` 在单日范围下是**小时**（`00`–`23`），在区间范围下是**日期**。'
                . '老版是"单日=柱状图 24 根，区间=折线图"，本插件两种都画（见「流量分析」/「趋势分析」）。'];
}

/* ============================== Top 榜（页面 / 来路 / 关键词 / 地域 / 设备…） ============================== */

function statx_dash_top_page(array $ctx): array
{
    $rows = statx_dash_q('SELECT path k, COUNT(*) c, COUNT(DISTINCT ' . STATX_DASH_UV . ') uv, SUM(is_new) newv'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . ' GROUP BY k ORDER BY c DESC, k LIMIT 10', [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; $r['uv'] = (int)$r['uv']; $r['newv'] = (int)$r['newv']; }
    unset($r);
    return ['cols' => [['路径', 'k'], ['PV', 'c', 'r'], ['UV', 'uv', 'r'], ['新访', 'newv', 'r']],
            'rows' => $rows, 'bar' => 'c', 'title' => '热门页 Top10'];
}

function statx_dash_top_ref(array $ctx): array
{
    $rows = statx_dash_q("SELECT CASE WHEN ref_domain='' THEN '(直接访问)' ELSE ref_domain END k,"
        . ' COUNT(*) c, COUNT(DISTINCT NULLIF(sid,\'\')) sess,'
        // ★ r12x：`MAX(id) mx, ref` —— SQLite 的 bare-column 语义取 max(id) 那一行的 ref，
        //   所以悬停看到的是**真正最新一条**来路（r12 初版用 MAX(ref)，那是"字典序最大"不是"最新"）。
        . ' MAX(id) mx, ref ref_full'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . ' GROUP BY k ORDER BY c DESC, k LIMIT 10', [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) {
        $full = (string)($r['ref_full'] ?? '');
        $r['k'] = e((string)$r['k']);
        $r['c'] = (int)$r['c']; $r['sess'] = (int)$r['sess'];
        /* ★★ r12：来路列**悬停就能看到带查询串的完整 URL**（零 JS；内容仍是域名，界面不变复杂）。
           `_t_<key>` 是 stx_table() 认的 title 约定（见 ui.php::stx_table），值会被 e() 转义。 */
        if ($full !== '') { $r['_t_k'] = $full; }
    }
    unset($r);
    return ['cols' => [['来源域名', 'k'], ['PV', 'c', 'r'], ['会话', 'sess', 'r']],
            'rows' => $rows, 'bar' => 'c', 'title' => '来路 Top10',
            'note' => '「(直接访问)」含 `ref` 为空与被 `Referrer-Policy` 剥离的情况 ——'
                . '那是**浏览器隐私策略**，不是系统丢数据（老版把这两种混在一起叫"直接访问"，同样没写清）。'
                . ' ★ 鼠标停在来源域名上可看到该来源**最新一条带查询串的完整 URL**（库里的 `ref` 一直是完整地址，'
                . '≤500 字符；端点入库前已剥离 token/session/auth/email 等敏感参数）。'
                . ' ★★ r12x 想看"带参数的页面地址"本身（不只悬停）⇒ 去「分析 → 来源分析 → 搜索引擎」，'
                . '那一页有独立的「完整来路地址」列。'];
}

/**
 * 关键词 Top10：**外部引擎关键词 + 站内搜索词**。
 * 两类都来自建库时算好的 `kw` 列（见 derive.php）—— 所以这里依旧只是一条 GROUP BY。
 */
function statx_dash_top_kw(array $ctx): array
{
    $H = STATX_SDB_HITS;
    $rows = statx_dash_q("SELECT kw k, COUNT(*) c,"
        . ' COUNT(DISTINCT NULLIF(sid,\'\')) sess,'
        . " SUM(ref_type='internal') inkw, SUM(ref_type='search') sekw"
        . " FROM {$H} WHERE site_key=? AND d>=? AND d<=? AND kind='pv' AND is_bot=0 AND kw<>''"
        . " AND ref_type IN ('search','internal')"
        . ' GROUP BY k ORDER BY c DESC, k LIMIT 10', [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) {
        $r['k'] = e((string)$r['k']);
        $r['c'] = (int)$r['c'];
        $r['sess'] = (int)$r['sess'];
        $in = (int)$r['inkw'] > 0; $se = (int)$r['sekw'] > 0;
        $r['src'] = $in && $se ? '站内+引擎' : ($in ? '站内' : '引擎');
    }
    unset($r);
    $cov = statx_dash_q("SELECT COUNT(DISTINCT NULLIF(sid,'')) alls,"
        . " COUNT(DISTINCT CASE WHEN ref_type='search' THEN sid END) se,"
        . " COUNT(DISTINCT CASE WHEN ref_type='internal' AND kw<>'' THEN sid END) inkw"
        . " FROM {$H} WHERE " . statx_dash_w() . " AND sid<>''", [$ctx['site'], $ctx['from'], $ctx['to']]);
    $cov = $cov[0] ?? [];
    $alls = max(1, (int)($cov['alls'] ?? 0));
    $note = '覆盖率：**站内搜索词 ' . round((int)($cov['inkw'] ?? 0) / $alls * 100, 1) . '%**（'
        . (int)($cov['inkw'] ?? 0) . '/' . (int)($cov['alls'] ?? 0) . ' 个会话）· '
        . '**外部引擎 ' . round((int)($cov['se'] ?? 0) / $alls * 100, 1) . '%**（'
        . (int)($cov['se'] ?? 0) . '/' . (int)($cov['alls'] ?? 0) . ' 个会话）。'
        . '外部引擎关键词天然只有一部分：Google 早已不把 `q=` 放进 referrer；'
        . '**站内搜索词是本插件 100% 可靠的来源**（来自 `/search?q=…` 这类站内结果页的 referrer）。';
    return ['cols' => [['关键词', 'k'], ['来源', 'src'], ['次数', 'c', 'r'], ['会话', 'sess', 'r']],
            'rows' => $rows, 'bar' => 'c', 'title' => '关键词 Top10', 'note' => $note];
}

/** 地域：SQL 聚合到 15 行的完整串，PHP 只做「同城合并 + 截断」这一件有界的小事 */
function statx_dash_geo_rows(array $ctx, int $limit, int $keep): array
{
    $rows = statx_dash_q('SELECT region k, COUNT(*) c, COUNT(DISTINCT ' . STATX_DASH_UV . ') uv,'
        . ' SUM(ipver=6) v6, COUNT(DISTINCT NULLIF(sid,\'\')) sess'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . ' GROUP BY k ORDER BY c DESC, k LIMIT ' . $limit, [$ctx['site'], $ctx['from'], $ctx['to']]);
    $merge = [];
    foreach ($rows as $r) {
        $raw = (string)$r['k'];
        $short = statx_geo_short($raw);
        if (!isset($merge[$short])) {
            $merge[$short] = ['k' => e($short), '_t_k' => $raw, 'c' => 0, 'uv' => 0, 'v6' => 0, 'sess' => 0, 'raws' => []];
        }
        $merge[$short]['c'] += (int)$r['c'];
        $merge[$short]['uv'] += (int)$r['uv'];
        $merge[$short]['v6'] += (int)$r['v6'];
        $merge[$short]['sess'] += (int)$r['sess'];
        if ($raw !== '') { $merge[$short]['raws'][$raw] = 1; }
    }
    usort($merge, static fn($a, $b) => $b['c'] <=> $a['c']);
    $out = [];
    foreach (array_slice($merge, 0, $keep) as $m) {
        $m['_t_k'] = implode(' / ', array_slice(array_keys($m['raws']), 0, 3));
        unset($m['raws']);
        $out[] = $m;
    }
    return $out;
}

function statx_dash_top_geo(array $ctx): array
{
    // ★ 2026-09-14：地域库是**可选**的（ZIP 不带，见 README「地域库」）；没装就说明白，
    //   而不是让用户对着一个永远空白的地域列猜。
    $gi = function_exists('statx_geo_db_info') ? statx_geo_db_info() : ['found' => '', 'paths' => []];
    $note = $gi['found'] !== ''
        ? '地域在**采集端点**由访客 IP 现算（库：`' . e((string)$gi['found']) . '`），**IP 本身从不落库**。'
        : '**地域库未安装** ⇒ 地域一列恒为空（不影响采集与其它统计）。把 `ip2region.xdb` 放到 '
          . '`<站点根>/var/statx/` 即自动生效，详见「系统环境」页。';
    return ['cols' => [['地域', 'k'], ['PV', 'c', 'r'], ['UV', 'uv', 'r'], ['IPv6', 'v6', 'r']],
            'rows' => statx_dash_geo_rows($ctx, 15, 10), 'bar' => 'c', 'title' => '地域 Top10',
            'note' => $note . '（隐私模型：IP 从不落库）'];
}

/**
 * 链接健康 —— **可选**能力：复用 links 插件已经跑出来的体检结果（只读，零出站、零写库）。
 * ★ 2026-09-14：不再写死 `yecha.org` —— 别的用户机器上没有那个站，也没有 links 插件。
 *   现在来源可配（`statx.links.source_site_key`，空 → 本站自己），拿不到就说明「未安装」。
 */
function statx_dash_links(array $ctx): array
{
    $src = trim((string)statx_opt('links.source_site_key', ''));
    if ($src === '') { $src = statx_collect_site_key(); }
    $h = statx_links_health($src);
    if (!$h['ok']) {
        return ['cols' => [['状态', 'k']], 'rows' => [], 'bar' => '', 'title' => '链接健康（外链/友链可达性）',
                'empty' => '链接体检数据不可用：' . $h['error'], 'note' => '来源：' . e($h['probe_source'] ?? '')];
    }
    $s = $h['summary'];
    $rows = [
        ['k' => '可达 ok',        'c' => (int)$s['ok'],      'note' => ''],
        ['k' => '停更 stale',     'c' => (int)$s['stale'],   'note' => '可达但长期停更（>' . (int)$h['stale_days'] . ' 天）——“可达 ≠ 活着”'],
        ['k' => '异常 err',       'c' => (int)$s['err'],     'note' => '可达但 5xx / 内容标记不符'],
        ['k' => '不可达 down',    'c' => (int)$s['down'],    'note' => '只给建议清单，**绝不自动删链**'],
        ['k' => '被拦 blocked',   'c' => (int)$s['blocked'], 'note' => '被 WAF/限频拦 —— **不算死链**，建议不重试'],
        ['k' => '未体检 unknown', 'c' => (int)$s['unknown'], 'note' => ''],
    ];
    $rows = array_values(array_filter($rows, static fn($r) => $r['c'] > 0));
    $maxIdle = (int)($s['max_idle_days'] ?? 0);
    return ['cols' => [['状态', 'k'], ['条目数', 'c', 'r'], ['说明', 'note']], 'rows' => $rows, 'bar' => 'c',
            'title' => '链接健康（外链/友链可达性）',
            'right' => '合计 ' . (int)$s['total'] . ' 条 · 体检于 ' . e((string)($h['probed_at'] !== '' ? $h['probed_at'] : '-')),
            'note' => '来源：' . e($h['probe_source'] ?? '') . '；最长停更 ' . $maxIdle . ' 天（'
                . e((string)($h['rows'][0]['url'] ?? '-')) . '）。本插件**不发任何请求**，只读这份既有结果。'];
}

/* ============================== 访问分析（入口 / 退出 / 深度） ============================== */

function statx_dash_entry(array $ctx): array
{
    // SQLite 的 min()/max() 裸列语义：按 sid 分组时，其余列取「命中 min(id) 的那一行」= 会话首页
    $rows = statx_dash_q('SELECT path k, COUNT(*) c FROM ('
        . ' SELECT sid, path, MIN(id) mid FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . " AND sid<>'' GROUP BY sid) GROUP BY k ORDER BY c DESC, k LIMIT 10",
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; }
    unset($r);
    return ['cols' => [['入口页', 'k'], ['会话数', 'c', 'r']], 'rows' => $rows, 'bar' => 'c',
            'id' => 'entry', 'title' => '入口页 Top10'];
}

function statx_dash_exit(array $ctx): array
{
    $rows = statx_dash_q('SELECT path k, COUNT(*) c FROM ('
        . ' SELECT sid, path, MAX(id) mid FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . " AND sid<>'' GROUP BY sid) GROUP BY k ORDER BY c DESC, k LIMIT 10",
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; }
    unset($r);
    return ['cols' => [['退出页', 'k'], ['会话数', 'c', 'r']], 'rows' => $rows, 'bar' => 'c',
            'title' => '退出页 Top10'];
}

function statx_dash_depth(array $ctx): array
{
    $rows = statx_dash_q('SELECT depth k, COUNT(*) c FROM ('
        . ' SELECT sid, COUNT(*) depth FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . " AND sid<>'' GROUP BY sid) GROUP BY k ORDER BY k LIMIT 50",
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    $tot = 0; $one = 0;
    foreach ($rows as &$r) {
        $r['k'] = (string)(int)$r['k'] . ' 页'; $r['c'] = (int)$r['c'];
        $tot += $r['c'];
        if ((int)$r['k'] === 1) { $one = $r['c']; }
    }
    unset($r);
    $bounce = $tot > 0 ? round($one / $tot * 100, 1) : 0;
    return ['cols' => [['访问深度', 'k'], ['会话数', 'c', 'r']], 'rows' => $rows, 'bar' => 'c',
            'title' => '访问深度分布',
            'right' => '会话 ' . number_format($tot) . ' · 跳出率 ' . $bounce . '%',
            'note' => '跳出率 = 只有 1 个 PV 的会话占全部会话的比例。'];
}

/* ============================== 来源分析 ============================== */

function statx_dash_rtype(array $ctx): array
{
    $rows = statx_dash_q("SELECT CASE WHEN ref_type='' THEN 'direct' ELSE ref_type END k,"
        . ' COUNT(*) c, COUNT(DISTINCT ' . STATX_DASH_UV . ') uv, COUNT(DISTINCT NULLIF(sid,\'\')) sess'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . ' GROUP BY k ORDER BY c DESC', [$ctx['site'], $ctx['from'], $ctx['to']]);
    $tot = 0;
    foreach ($rows as $r) { $tot += (int)$r['c']; }
    foreach ($rows as &$r) {
        $r['k'] = e(statx_derive_ref_label((string)$r['k']));
        $r['c'] = (int)$r['c'];
        $r['uv'] = (int)$r['uv'];
        $r['sess'] = (int)$r['sess'];
        $r['pct'] = ($tot > 0 ? round($r['c'] / $tot * 100, 1) : 0) . '%';
    }
    unset($r);
    return ['cols' => [['来源类型', 'k'], ['PV', 'c', 'r'], ['占比', 'pct', 'r'], ['UV', 'uv', 'r'], ['会话', 'sess', 'r']],
            'rows' => $rows, 'bar' => 'c', 'title' => '来源类型（四分类 + 直达）',
            'note' => '分类在**入库时**一次性算好并落库（`derive.php`），所以本表依旧只是一条 GROUP BY。'
                . '「直达」含 `ref` 为空与被 `Referrer-Policy` 剥离的情况 —— 那是浏览器隐私策略，不是系统丢数据。'];
}

function statx_dash_ext(array $ctx): array
{
    $rows = statx_dash_q("SELECT ref_domain k, ref_type t, COUNT(*) c, COUNT(DISTINCT NULLIF(sid,'')) sess"
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . " AND ref_kind='out' AND ref_domain<>''"
        . ' GROUP BY k, t ORDER BY c DESC, k LIMIT 10', [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) {
        $dm = (string)$r['k'];
        $r['k'] = e($dm);
        $r['k'] = $r['k'] . ' ' . stx_lk('查看', ['p' => 'src_ext', 'h' => $dm]);
        $r['t'] = e(statx_derive_ref_label((string)$r['t']));
        $r['c'] = (int)$r['c']; $r['sess'] = (int)$r['sess'];
    }
    unset($r);
    return ['cols' => [['来源域名', 'k'], ['类型', 't'], ['PV', 'c', 'r'], ['会话', 'sess', 'r']],
            'rows' => $rows, 'bar' => 'c', 'title' => '外链来源 Top10'];
}

function statx_dash_utm(array $ctx): array
{
    $rows = statx_dash_q("SELECT (CASE WHEN utm_source<>'' THEN utm_source ELSE '(无 source)' END)"
        . " || ' / ' || (CASE WHEN utm_medium<>'' THEN utm_medium ELSE '(无 medium)' END) k,"
        . ' COUNT(*) c, COUNT(DISTINCT ' . STATX_DASH_UV . ') uv'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . " AND (utm_source<>'' OR utm_medium<>'' OR utm_campaign<>'')"
        . ' GROUP BY k ORDER BY c DESC, k LIMIT 10', [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; $r['uv'] = (int)$r['uv']; }
    unset($r);
    return ['cols' => [['source / medium', 'k'], ['PV', 'c', 'r'], ['UV', 'uv', 'r']],
            'rows' => $rows, 'bar' => 'c', 'title' => 'utm 渠道 Top10',
            'note' => '★ **老版没有这一张独立的表**（utm 只在"外部链接"页的「渠道参数」列里露出）。'
                . 'utm 由客户端的落地 URL query 直接解析（白名单 5 键：source/medium/campaign/term/content），零站点开销。'];
}

/* ============================== 访客分析（设备 / 浏览器 / 系统 / 分辨率 / 语言 / 新老 / 版本） ============================== */

function statx_dash_group(array $ctx, string $col, string $title, int $limit = 8): array
{
    $rows = statx_dash_q('SELECT CASE WHEN ' . $col . "='' THEN '(未知)' ELSE " . $col . ' END k,'
        . ' COUNT(*) c, COUNT(DISTINCT ' . STATX_DASH_UV . ') uv'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . ' GROUP BY k ORDER BY c DESC, k LIMIT ' . max(1, $limit), [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; $r['uv'] = (int)$r['uv']; }
    unset($r);
    return ['cols' => [[$title, 'k'], ['PV', 'c', 'r'], ['UV', 'uv', 'r']],
            'rows' => $rows, 'bar' => 'c', 'title' => $title];
}

function statx_dash_dev(array $ctx): array     { return statx_dash_group($ctx, 'device', '设备', 8); }
function statx_dash_browser(array $ctx): array { return statx_dash_group($ctx, 'browser', '浏览器', 8); }
function statx_dash_os(array $ctx): array      { return statx_dash_group($ctx, 'os', '操作系统', 8); }
function statx_dash_lang(array $ctx): array    { return statx_dash_group($ctx, 'lang', '语言', 8); }

function statx_dash_res(array $ctx): array
{
    $rows = statx_dash_q("SELECT (width || 'x' || height) k, COUNT(*) c, COUNT(DISTINCT " . STATX_DASH_UV . ') uv'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w() . ' AND width>0 AND height>0'
        . ' GROUP BY k ORDER BY c DESC, k LIMIT 8', [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; $r['uv'] = (int)$r['uv']; }
    unset($r);
    return ['cols' => [['分辨率（screen）', 'k'], ['PV', 'c', 'r'], ['UV', 'uv', 'r']],
            'rows' => $rows, 'bar' => 'c', 'title' => '分辨率 Top8',
            'note' => '口径：`screen` 尺寸（显示器分辨率），与老版一致。'
                . '⚠ 页面点击热图的归一化**故意不用它**（用每个访客自己的 viewport）—— 见「页面点击图」。'];
}

function statx_dash_new(array $ctx): array
{
    $rows = statx_dash_q('SELECT is_new k, COUNT(*) c, COUNT(DISTINCT ' . STATX_DASH_UV . ') uv'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . ' GROUP BY k ORDER BY k DESC', [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = ((int)$r['k'] === 1) ? '新访客' : '老访客'; $r['c'] = (int)$r['c']; $r['uv'] = (int)$r['uv']; }
    unset($r);
    return ['cols' => [['类型', 'k'], ['PV', 'c', 'r'], ['UV', 'uv', 'r']],
            'rows' => $rows, 'bar' => 'c', 'title' => '新老访客',
            'note' => '「新」= 该 `uid` 在保留窗口内第一次出现（客户端随机 uid，跨天可关联；见批次 1 隐私模型）。'
                . '`is_new` 在**入库时**就算好并落库 ⇒ 本表与看板"新访客"数字永远一致。'];
}

function statx_dash_geo_full(array $ctx): array
{
    return ['cols' => [['地域', 'k'], ['PV', 'c', 'r'], ['UV', 'uv', 'r'], ['IPv6', 'v6', 'r'], ['会话', 'sess', 'r']],
            'rows' => statx_dash_geo_rows($ctx, 24, 15), 'bar' => 'c', 'title' => '地域 Top15',
            'note' => 'IPv6 一列是**硬指标**：tj 当年 IPv6 占 26.2% 却 100% 查不到地域'
                . '（`region.py` 写死 IPv4）；本插件 IPv4/IPv6 两份官方 xdb 都读，IPv6 不再是静默的空桶。'];
}

function statx_dash_ipver(array $ctx): array
{
    $rows = statx_dash_q('SELECT ipver k, COUNT(*) c, COUNT(DISTINCT ' . STATX_DASH_UV . ') uv,'
        . " SUM(region<>'') geo FROM " . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . ' GROUP BY k ORDER BY c DESC', [$ctx['site'], $ctx['from'], $ctx['to']]);
    $tot = 0;
    foreach ($rows as $r) { $tot += (int)$r['c']; }
    $out = [];
    foreach ($rows as $r) {
        $v = (int)$r['k'];
        $out[] = ['k' => $v === 6 ? 'IPv6' : ($v === 4 ? 'IPv4' : '(未记录)'),
                  'c' => (int)$r['c'],
                  'pct' => ($tot > 0 ? round((int)$r['c'] / $tot * 100, 1) : 0) . '%',
                  'uv' => (int)$r['uv'],
                  'geo' => (int)$r['geo']];
    }
    return ['cols' => [['IP 版本', 'k'], ['PV', 'c', 'r'], ['占比', 'pct', 'r'], ['UV', 'uv', 'r'], ['有地域', 'geo', 'r']],
            'rows' => $out, 'bar' => 'c', 'title' => 'IP 版本与地域覆盖',
            'note' => '`(未记录)` 是批次 2 之前的老行（那时端点还没算地域）。'];
}

/* ============================== 深入（点击元素 / 热图 / 流向 / 转化） ============================== */

function statx_dash_elen(array $ctx): array
{
    $rows = statx_dash_q("SELECT CASE WHEN en='' THEN '(未命名)' ELSE en END k, COUNT(*) c,"
        . ' COUNT(DISTINCT NULLIF(sid,\'\')) sess'
        . " FROM " . STATX_SDB_HITS . " WHERE site_key=? AND d>=? AND d<=? AND kind='cl' AND is_bot=0"
        . ' GROUP BY k ORDER BY c DESC, k LIMIT 10', [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; $r['sess'] = (int)$r['sess']; }
    unset($r);
    return ['cols' => [['被点元素', 'k'], ['点击', 'c', 'r'], ['会话', 'sess', 'r']],
            'rows' => $rows, 'bar' => 'c', 'title' => '点击元素 Top10',
            'note' => '★ **老版没有"点击元素排行榜"**（它的热图只有一个网格）。元素名来自页面上的 `data-event` 标注'
                . '（逐级向上查找），没有标注就退回标签名（如 `button`/`a`）。'];
}

function statx_dash_heat(array $ctx): array
{
    // 归一化在 SQL 里做：x/vw、y/vh → 24×14 网格。**只统计坐标确实落在视口内的点击**
    $rows = statx_dash_q("SELECT CAST(x*24/vw AS INT) cx, CAST(y*14/vh AS INT) cy, COUNT(*) c"
        . " FROM " . STATX_SDB_HITS . " WHERE site_key=? AND d>=? AND d<=? AND kind='cl' AND is_bot=0"
        . ' AND vw>0 AND vh>0 AND x>=0 AND y>=0 AND x<vw AND y<vh'
        . ' GROUP BY cx, cy ORDER BY c DESC LIMIT 200', [$ctx['site'], $ctx['from'], $ctx['to']]);
    $tot = statx_dash_q("SELECT COUNT(*) c, SUM(vw>0 AND vh>0 AND x>=0 AND y>=0 AND x<vw AND y<vh) ok"
        . " FROM " . STATX_SDB_HITS . " WHERE site_key=? AND d>=? AND d<=? AND kind='cl' AND is_bot=0",
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    $tot = $tot[0] ?? ['c' => 0, 'ok' => 0];
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['k' => (int)$r['cx'] . ',' . (int)$r['cy'], 'cx' => (int)$r['cx'], 'cy' => (int)$r['cy'], 'c' => (int)$r['c']];
    }
    return ['cols' => [['网格', 'k'], ['点击', 'c', 'r']], 'rows' => $out, 'bar' => 'c',
            'title' => '热图网格（归一化 24×14）',
            'right' => '可用点击 ' . (int)$tot['ok'] . ' / ' . (int)$tot['c'],
            'note' => '横轴 = 视口宽度的百分比，纵轴 = 视口高度的百分比。'
                . '**归一化用的是每个访客自己的 viewport**（`clientX/innerWidth`）而不是屏幕分辨率 —— '
                . 'tj 用的是 `screen` 尺寸，浏览器窗口一窄整张图就会横向失真。'
                . '只统计确实落在可视区内的点击（滚动后超出视口的点击被排除）。'];
}

function statx_dash_flow(array $ctx): array
{
    $rows = statx_dash_q('SELECT a.path src, b.path dst, COUNT(*) c FROM ('
        . ' SELECT sid, id, path, lag(id) OVER (PARTITION BY sid ORDER BY id) pid'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w() . " AND sid<>''"
        . ') a JOIN ' . STATX_SDB_HITS . ' b ON b.id=a.pid'
        . ' WHERE a.pid IS NOT NULL AND a.path<>b.path'
        . ' GROUP BY src, dst ORDER BY c DESC LIMIT 12', [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) {
        $r['k'] = e((string)$r['src']) . ' <span class="stx-arrow" style="color:#a9b0b7">→</span> ' . e((string)$r['dst']);
        $r['c'] = (int)$r['c'];
    }
    unset($r);
    return ['cols' => [['流向（上一页 → 下一页）', 'k'], ['次数', 'c', 'r']],
            'rows' => $rows, 'bar' => 'c', 'title' => '页面流向 Top12',
            'note' => '用 `sid` 把同一会话的浏览串起来（`lag(id) OVER (PARTITION BY sid ORDER BY id)`，'
                . 'SQLite 3.40 原生支持），**在 SQL 里聚合**，不把明细取回 PHP。'];
}

function statx_dash_conv_def(): string
{
    return '转化 = 一次会话（sid）内**浏览 ≥ 3 个页面**。';
}

function statx_dash_conv(array $ctx): array
{
    $rows = statx_dash_q('SELECT CASE WHEN pv>=3 THEN 1 ELSE 0 END k, COUNT(*) c FROM ('
        . ' SELECT sid, COUNT(*) pv FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . " AND sid<>'' GROUP BY sid) GROUP BY k", [$ctx['site'], $ctx['from'], $ctx['to']]);
    $conv = 0; $tot = 0;
    foreach ($rows as $r) { $tot += (int)$r['c']; if ((int)$r['k'] === 1) { $conv = (int)$r['c']; } }
    $out = [
        ['k' => '转化会话（≥3 页）', 'c' => $conv, 'pct' => ($tot > 0 ? round($conv / $tot * 100, 1) : 0) . '%'],
        ['k' => '未转化会话（1–2 页）', 'c' => $tot - $conv, 'pct' => ($tot > 0 ? round(($tot - $conv) / $tot * 100, 1) : 0) . '%'],
        ['k' => '会话合计', 'c' => $tot, 'pct' => '100%'],
    ];
    return ['cols' => [['口径', 'k'], ['会话数', 'c', 'r'], ['占比', 'pct', 'r']], 'rows' => $out, 'bar' => 'c',
            'title' => '转化会话', 'note' => statx_dash_conv_def()];
}

function statx_dash_cpath(array $ctx): array
{
    $rows = statx_dash_q('SELECT p3, p2, p1, COUNT(*) c FROM ('
        . ' SELECT sid, MAX(CASE WHEN rn=3 THEN path END) p3, MAX(CASE WHEN rn=2 THEN path END) p2,'
        . ' MAX(CASE WHEN rn=1 THEN path END) p1'
        . ' FROM (SELECT sid, path, row_number() OVER (PARTITION BY sid ORDER BY id DESC) rn'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w() . " AND sid<>''"
        . ') WHERE rn<=3 GROUP BY sid HAVING COUNT(*)>=3)'
        . ' GROUP BY p3, p2, p1 ORDER BY c DESC LIMIT 10', [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) {
        $r['k'] = e((string)$r['p3']) . ' <span class="stx-arrow" style="color:#a9b0b7">→</span> ' . e((string)$r['p2'])
            . ' <span class="stx-arrow" style="color:#a9b0b7">→</span> ' . e((string)$r['p1']);
        $r['c'] = (int)$r['c'];
    }
    unset($r);
    return ['cols' => [['最后三步（第 N-2 → N-1 → 末页）', 'k'], ['会话数', 'c', 'r']],
            'rows' => $rows, 'bar' => 'c', 'title' => '转化路径 Top10',
            'note' => '取每个转化会话**最后三个页面**的序列并计数 —— 「访客最后在读什么」。'
                . '口径：' . statx_dash_conv_def()];
}
