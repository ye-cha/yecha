<?php
declare(strict_types=1);
/**
 * StatX · 新增聚合查询（批次 3）
 *
 * ★ 与批次 2 相同的硬约束（延续，不改）：**每个聚合都在 SQL 里做**（GROUP BY + LIMIT），
 *   绝不把明细取回 PHP 再聚合；参数一律用占位符（`?`）；本文件**只读**，一行都不写。
 * ★ 本文件**不新增任何索引**（批次 1 起只建 idx_hits_site_d / idx_hits_site_uid 两个；这条红线延续）。
 *   ⇒ 所有查询都以 `site_key=? AND d>=? AND d<=?` 打头，走 idx_hits_site_d。
 */

require_once __DIR__ . '/dash.php';   // 复用 statx_dash_q / STATX_DASH_UV / statx_dash_w / statx_geo_short

/* ============================== 单日详情（老版 /day 的 12 表 + 1 图） ============================== */

/** 24 小时分布（0–23 全部补齐，缺的小时补 0 —— 老版柱状图就是 24 根柱） */
function statx_q_hours(array $ctx): array
{
    $rows = statx_dash_q("SELECT CAST(substr(ts,12,2) AS INT) h,"
        . " SUM(kind='pv' AND is_bot=0) pv, SUM(kind='pv' AND is_bot=1) bot,"
        . ' COUNT(DISTINCT ' . STATX_DASH_UV . ") uv FROM " . STATX_SDB_HITS
        . " WHERE site_key=? AND d>=? AND d<=? GROUP BY h ORDER BY h",
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    $m = [];
    foreach ($rows as $r) { $m[(int)$r['h']] = ['pv' => (int)$r['pv'], 'bot' => (int)$r['bot'], 'uv' => (int)$r['uv']]; }
    $out = [];
    for ($i = 0; $i < 24; $i++) {
        $out[] = ['l' => str_pad((string)$i, 2, '0', STR_PAD_LEFT) . ' 时', 'v' => (int)($m[$i]['pv'] ?? 0),
                  'bot' => (int)($m[$i]['bot'] ?? 0), 'uv' => (int)($m[$i]['uv'] ?? 0)];
    }
    return $out;
}

/** 24 小时分布的 CSV 版（内部函数返回 `l`/`v` 是为了直接喂给 SVG，这里换成给人看的列名） */
function statx_c_hours_csv(array $ctx): array
{
    $out = [];
    foreach (statx_q_hours($ctx) as $r) {
        $out[] = ['h' => (string)$r['l'], 'pv' => (int)$r['v'], 'bot' => (int)$r['bot'], 'uv' => (int)$r['uv']];
    }
    return ['cols' => [['小时', 'h'], ['人类 PV', 'pv', 'r'], ['爬虫 PV', 'bot', 'r'], ['UV', 'uv', 'r']],
            'rows' => $out, 'bar' => 'pv', 'title' => '时段分布（24 小时）',
            'note' => '0–23 全部补齐，缺的小时记 0。口径：人类 PV（`kind=\'pv\' AND is_bot=0`）。'];
}

/* ============================== 受访域名 / 一级·二级路径（老版 /domain、/paths） ============================== */

function statx_q_domain(array $ctx): array
{
    $rows = statx_dash_q("SELECT CASE WHEN host='' THEN '(未记录)' ELSE host END k, COUNT(*) c,"
        . ' COUNT(DISTINCT ' . STATX_DASH_UV . ") uv FROM " . STATX_SDB_HITS
        . ' WHERE ' . statx_dash_w() . ' GROUP BY k ORDER BY c DESC, k LIMIT 30',
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; $r['uv'] = (int)$r['uv']; }
    unset($r);
    return $rows;
}

/**
 * 分层路径 TOP N：`lv=1` 取一级（`/a/b/c` → `/a`），`lv=2` 取二级（→ `/a/b`）。
 * ★ 纯 SQL 实现：用两次 `instr` 定位第 1/第 2 个斜杠，切出来的都是常量时间的字符串函数，
 *   不做正则（SQLite 的 REGEXP 需要自建函数，本插件不注册任何自定义函数）。
 */
function statx_q_paths(array $ctx, int $lv, int $limit = 20): array
{
    $H = STATX_SDB_HITS;
    if ($lv <= 1) {
        $expr = "CASE WHEN instr(substr(path,2),'/')>0"
            . " THEN substr(path,1,instr(substr(path,2),'/'))"
            . " ELSE path END";
    } else {
        $rest = "substr(path, instr(substr(path,2),'/')+1)";              // 去掉开头 '/xxx' 后剩下的
        $expr = "CASE WHEN instr(substr(path,2),'/')=0 THEN '(一级页)'"
            . " WHEN instr(substr(" . $rest . ",2),'/')>0"
            . " THEN substr(path,1, instr(substr(path,2),'/') + instr(substr(" . $rest . ",2),'/'))"
            . " ELSE path END";
    }
    $rows = statx_dash_q('SELECT ' . $expr . ' k, COUNT(*) c,'
        . ' COUNT(DISTINCT ' . STATX_DASH_UV . ') uv'
        . " FROM {$H} WHERE " . statx_dash_w()
        . ' GROUP BY k ORDER BY c DESC, k LIMIT ' . max(1, min(200, $limit)),
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; $r['uv'] = (int)$r['uv']; }
    unset($r);
    return $rows;
}

/* ============================== 搜索引擎 / 关键词下钻（老版 /source/search、/source/keyword） ============================== */

function statx_q_ref_search(array $ctx): array
{
    $rows = statx_dash_q('SELECT ref_domain k, COUNT(*) c,'
        . ' COUNT(DISTINCT ' . STATX_DASH_UV . ') uv, COUNT(DISTINCT NULLIF(sid,\'\')) sess,'
        . ' MAX(id) mx, ref ref_full'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . " AND ref_type='search' AND ref_domain<>''"
        . ' GROUP BY k ORDER BY c DESC, k LIMIT 30', [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) {
        $full = (string)($r['ref_full'] ?? '');
        $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; $r['uv'] = (int)$r['uv']; $r['sess'] = (int)$r['sess'];
        // ★ r12：悬停看**该引擎最近一条带查询串的完整来路 URL**（零 JS）
        if ($full !== '') { $r['_t_k'] = $full; }
        /* ★★ r12x：站长原话「来路统计只有根域名，要统计到带参数的页面地址」——
           `MAX(id) mx, ref` 让 ref_full 真的取到**最新那一行**的完整地址（SQLite bare-column 语义，
           已用 TEST1/TEST2 对照验证）。这里把它**印成一列**：只用悬停不够 —— 站长要的是一眼看到
           带参数的页面地址。截断 72 字符 + title 悬停看全文，零 JS，与其他表同一套 stx_table 约定。 */
        $r['_t_ref'] = $full;
        $r['ref'] = $full === '' ? '—' : e(mb_substr($full, 0, 72) . (mb_strlen($full) > 72 ? '…' : ''));
    }
    unset($r);
    return $rows;
}

/** 关键词（含「直达搜索」判定：该会话里除搜索落地页外还有别的页面 = 用户真的继续读了） */
function statx_q_keyword(array $ctx, int $limit = 50): array
{
    $H = STATX_SDB_HITS;
    $rows = statx_dash_q("SELECT kw k,"
        . " SUM(ref_type='search') se, SUM(ref_type='internal') inkw,"
        . ' COUNT(*) c, COUNT(DISTINCT ' . STATX_DASH_UV . ') uv,'
        // ★★ r12x 修 **致命 SQL 语法错**：r12 初版把 `, MAX(ref) ref_full` 拼在了 WHERE 之后
        //    （`… ref_type IN ('search','internal') , MAX(ref) ref_full GROUP BY k`）⇒ SQLite 直接
        //    报 `near ",": syntax error`，被 statx_dash_q 的 catch 吞掉 ⇒ **关键词页永远显示"暂无数据"**。
        //    实测证据：a4a52d81838e7286 在近 7 天库里有 25 行 kw，页面却显示"0 关键词条数"（见报告 §0）。
        //    现在放回 SELECT 列表，并改用 `MAX(id) mx, ref ref_full` —— SQLite 的 bare-column 语义
        //    会取 max(id) 那一行的 ref，因此「最近一条」这个说法**真的成立**（原先 MAX(ref) 是字典序最大，
        //    不是最新；已用 TEST1/TEST2 双向对照验证，见报告 §③）。
        . ' MAX(id) mx, ref ref_full'
        . " FROM {$H} WHERE site_key=? AND d>=? AND d<=? AND kind='pv' AND is_bot=0 AND kw<>''"
        . " AND ref_type IN ('search','internal')"
        . ' GROUP BY k ORDER BY c DESC, k LIMIT ' . max(1, min(500, $limit)),
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) {
        $full = (string)($r['ref_full'] ?? '');
        $r['k'] = e((string)$r['k']);
        $r['c'] = (int)$r['c']; $r['uv'] = (int)$r['uv'];
        $r['se'] = (int)$r['se']; $r['inkw'] = (int)$r['inkw'];
        // ★★ r12：这一行新增「来路」列（截断显示 + title 悬停看**完整 URL，含 ?q=… 查询串**）。
        //    目的：让「关键词」页能一眼看到"这个词是从哪个地址带回来的"（零 JS，不点不改）。
        $r['_t_ref'] = $full;
        $r['ref'] = $full === '' ? '—' : e(mb_substr($full, 0, 64) . (mb_strlen($full) > 64 ? '…' : ''));
        $in = $r['inkw'] > 0; $se = $r['se'] > 0;
        $r['src'] = $in && $se ? '站内+引擎' : ($in ? '站内' : '引擎');
    }
    unset($r);
    // 「直达搜索」= 该关键词的会话里，会话页面数 ≥2 的会话数（一条聚合，不做逐会话取数）
    $deep = [];
    foreach (statx_dash_q("SELECT kw k, COUNT(*) c FROM ("
        . ' SELECT kw, sid, COUNT(*) n FROM ' . $H
        . " WHERE site_key=? AND d>=? AND d<=? AND kind='pv' AND is_bot=0 AND kw<>'' AND sid<>''"
        . ' GROUP BY kw, sid HAVING COUNT(*)>=2) GROUP BY k',
        [$ctx['site'], $ctx['from'], $ctx['to']]) as $r) {
        $deep[(string)$r['k']] = (int)$r['c'];
    }
    foreach ($rows as &$r) { $r['deep'] = $deep[strip_tags((string)$r['k'])] ?? 0; }
    unset($r);
    return $rows;
}

/** 外部链接下钻明细（老版 /source/external/host：时间 / 来路地址 / 落地页 / 页面标题） */
function statx_q_ext_host(array $ctx, string $host, int $limit = 200): array
{
    $rows = statx_dash_q('SELECT ts, ref, path, ref_kind FROM ' . STATX_SDB_HITS
        . ' WHERE ' . statx_dash_w() . ' AND ref_domain=?'
        . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)), [$ctx['site'], $ctx['from'], $ctx['to'], $host]);
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['t' => (string)$r['ts'], 'ref' => e((string)$r['ref']), 'path' => e((string)$r['path']),
                  'kind' => e(statx_derive_ref_label((string)$r['ref_kind']))];
    }
    return $out;
}

/** 站内来源明细（老版 /source/internal：来源页面 / 来路页面 / 次数） */
function statx_q_int_detail(array $ctx, int $limit = 50): array
{
    $H = STATX_SDB_HITS;
    $rows = statx_dash_q('SELECT a.path src, b.path ref, COUNT(*) c FROM ('
        . ' SELECT id, sid, path, ref FROM ' . $H . ' WHERE ' . statx_dash_w()
        . ") a JOIN {$H} b ON b.ref=a.ref AND b.site_key=a.site_key AND b.id<>a.id"
        . " WHERE a.ref_type='internal' AND a.ref<>''"
        . ' GROUP BY src, ref ORDER BY c DESC LIMIT ' . max(1, min(200, $limit)),
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) {
        $r['src'] = e((string)$r['src']); $r['ref'] = e((string)$r['ref']); $r['c'] = (int)$r['c'];
    }
    unset($r);
    return $rows;
}

/* ============================== 事件 / 爬虫 / 预警 ============================== */

function statx_q_events(array $ctx): array
{
    $rows = statx_dash_q("SELECT en k, COUNT(*) c, COUNT(DISTINCT " . STATX_DASH_UV . ') uv,'
        . " COUNT(DISTINCT NULLIF(sid,'')) sess FROM " . STATX_SDB_HITS
        . " WHERE site_key=? AND d>=? AND d<=? AND en<>'' AND is_bot=0"
        . ' GROUP BY k ORDER BY c DESC, k LIMIT 50', [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; $r['uv'] = (int)$r['uv']; $r['sess'] = (int)$r['sess']; }
    unset($r);
    return $rows;
}

/** 最近事件流水（时间 / 事件名 / 页面 / 设备） */
function statx_q_events_recent(array $ctx, int $limit = 50): array
{
    $rows = statx_dash_q("SELECT ts, en, path, device, uid FROM " . STATX_SDB_HITS
        . " WHERE site_key=? AND d>=? AND d<=? AND en<>'' AND is_bot=0"
        . ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)), [$ctx['site'], $ctx['from'], $ctx['to']]);
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['ts' => (string)$r['ts'], 'en' => e((string)$r['en']), 'path' => e((string)$r['path']),
                  'device' => e((string)$r['device']), 'uid' => (string)$r['uid']];
    }
    return $out;
}

/**
 * 爬虫汇总（跨站或单站）。`cls` 分类用来上三色 tag：
 *   search = 搜索引擎（绿）· ai = AI 爬虫（橙）· other/unknown = 未知爬虫（红）
 */
function statx_q_bots(?string $site, string $from, string $to, int $limit = 60): array
{
    $w = 'd>=? AND d<=? AND kind=\'pv\' AND is_bot=1';
    $p = [$from, $to];
    if ($site !== null && $site !== '') { $w = 'site_key=? AND ' . $w; $p = array_merge([$site], $p); }
    $rows = statx_dash_q('SELECT bot_name k, bot_cls cls, site_key s, COUNT(*) c,'
        . " SUM(ipver=6) v6 FROM " . STATX_SDB_HITS . " WHERE {$w}"
        . ' GROUP BY k, cls, s ORDER BY c DESC LIMIT ' . max(1, min(300, $limit)), $p);
    foreach ($rows as &$r) { $r['k'] = (string)$r['k']; $r['c'] = (int)$r['c']; $r['v6'] = (int)$r['v6']; }
    unset($r);
    return $rows;
}

/** 最近爬虫访问（时间 / 爬虫 / 类型 / 页面 / 站点 / IP 版本） */
function statx_q_bots_recent(?string $site, string $from, string $to, int $limit = 40): array
{
    $w = "d>=? AND d<=? AND kind='pv' AND is_bot=1";
    $p = [$from, $to];
    if ($site !== null && $site !== '') { $w = 'site_key=? AND ' . $w; $p = array_merge([$site], $p); }
    $rows = statx_dash_q("SELECT ts, bot_name, bot_cls, path, site_key, ipver, ua FROM " . STATX_SDB_HITS
        . " WHERE {$w} ORDER BY id DESC LIMIT " . max(1, min(200, $limit)), $p);
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['ts' => (string)$r['ts'], 'bot' => e((string)$r['bot_name']), 'cls' => (string)$r['bot_cls'],
                  'path' => e((string)$r['path']), 'site' => e((string)$r['site_key']),
                  'ipver' => (int)$r['ipver'] === 6 ? 'IPv6' : ((int)$r['ipver'] === 4 ? 'IPv4' : '—')];
    }
    return $out;
}

/**
 * 采集预警：单页取数次数异常高的「访客/IP 版本 + 页面」组合（一页被同一 uid 反复拉取 = 被采集）。
 * 口径写在页面上：同一 `uid` 在所选区间内对**同一个 path** 的 PV ≥ 阈值即计入。
 */
function statx_q_alerts(?string $site, string $from, string $to, int $thr = 10, int $limit = 60): array
{
    $w = "kind='pv' AND is_bot=0 AND uid<>''";
    $p = [];
    if ($site !== null && $site !== '') { $w = 'site_key=? AND ' . $w; $p[] = $site; }
    $p[] = $from; $p[] = $to; $p[] = $thr;
    // ⚠️ `hits` 表**没有 ua 列**（隐私模型只存从 UA 派生的 device/browser/os，不存 UA 原文）
    //    ⇒ 预警表不显示 UA，只显示 uid / 页面 / 次数 / 时段 / IP 版本。
    $rows = statx_dash_q("SELECT site_key s, uid u, path, COUNT(*) c,"
        . ' MIN(ts) t0, MAX(ts) t1, MAX(ipver) v FROM ' . STATX_SDB_HITS
        . " WHERE {$w} AND d>=? AND d<=? GROUP BY s, u, path HAVING COUNT(*)>=? ORDER BY c DESC LIMIT "
        . max(1, min(200, $limit)), $p);
    foreach ($rows as &$r) { $r['c'] = (int)$r['c']; }
    unset($r);
    return $rows;
}

/** 站点健康矩阵数据（能力②，跨站或单站） */
function statx_q_health(?string $site): array
{
    $rows = statx_targets(['enabled' => 1], 'sort, id');
    if ($site !== null && $site !== '') {
        $rows = array_values(array_filter($rows, static fn($t) => (string)$t['site_key'] === $site));
    }
    $since = statx_ago(86400);
    foreach ($rows as &$t) {
        $t['cnt24'] = (int)Db::one('SELECT COUNT(*) FROM ' . statx_t('checks') . ' WHERE target_id=? AND ts > ?', [(int)$t['id'], $since]);
        $t['fail24'] = (int)Db::one('SELECT COUNT(*) FROM ' . statx_t('checks') . ' WHERE target_id=? AND ts > ? AND is_fail=1', [(int)$t['id'], $since]);
    }
    unset($t);
    return $rows;
}

/* ============================== 设置页要用的只读信息 ============================== */

function statx_q_collect_status(): array
{
    $rt = statx_collect_rt_read();
    return [
        'rt_exists' => is_file(statx_collect_rt_path()),
        'rt'        => $rt,
        'enabled'   => statx_cfg('collect.enabled') === '1',
        'spool'     => statx_collect_spool_stat(),
        'kpi'       => statx_collect_kpi(),
        'sites'     => statx_collect_site_rows(1),
        'pub'       => statx_collect_pub_origin(),
        'keep_days' => (int)statx_cfg('collect.keep_days'),
        'keep_months' => (int)statx_cfg('collect.keep_months'),
        'cutover'   => (string)statx_opt('collect.cutover_note', date('Y-m-d') . '（statx 上线日）'),
    ];
}

/** 采集链路健康度：能不能收到数据（对"零数据"给出可执行的判断，而不是让人猜） */
function statx_q_pipeline(): array
{
    $st = statx_q_collect_status();
    $last = (string)statx_sdb_val('SELECT COALESCE(MAX(ts), "") FROM ' . STATX_SDB_HITS);
    $rows = (int)statx_sdb_val('SELECT COUNT(*) FROM ' . STATX_SDB_HITS);
    $minD = (string)statx_sdb_val('SELECT COALESCE(MIN(d), "") FROM ' . STATX_SDB_HITS);
    return $st + ['last_hit' => $last, 'rows' => $rows, 'min_d' => $minD];
}
