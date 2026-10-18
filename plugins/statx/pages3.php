<?php
declare(strict_types=1);
/**
 * StatX · 页面级 builder（批次 3）——把区块拼成老版形状的整页
 *
 * 每个 builder 返回 ['kind'=>'page'|'csv'|'raw', 'body'=>…]（csv 型返回 ['kind'=>'csv','table'=>…]）。
 * 页面顺序**照老版骨架**（例如单日详情 = 24 小时分布图 + 热门页面 + 来源 + 蜘蛛 + 地域 + 系统环境
 * + 访问深度 + 访客列表，一张不少）；差异只在口径更好的地方（见各页 note）。
 */

require_once __DIR__ . '/pages2.php';

/* ============================== 系统：仪表盘 / 站点卡片网格 ============================== */

function statx_pg_sites_grid(array $ctx): array
{
    $sites = statx_ui_sites(true);
    $today = date('Y-m-d');
    $k = statx_dash_q('SELECT'
        . " SUM(kind='pv' AND is_bot=0) pv,"
        . ' COUNT(DISTINCT CASE WHEN kind=\'pv\' AND is_bot=0 THEN ' . STATX_DASH_UV . ' END) uv,'
        . " SUM(kind='pv' AND is_bot=1) bot FROM " . STATX_SDB_HITS . ' WHERE d=?', [$today]);
    $k = $k[0] ?? [];
    $m30 = statx_dash_q('SELECT'
        . " SUM(kind='pv' AND is_bot=0) pv,"
        . ' COUNT(DISTINCT CASE WHEN kind=\'pv\' AND is_bot=0 THEN ' . STATX_DASH_UV . ' END) uv'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE d>=? AND d<=?', [date('Y-m-d', strtotime('-29 day')), $today]);
    $m30 = $m30[0] ?? [];

    $h = stx_kpis([
        ['今日 PV', number_format((int)($k['pv'] ?? 0))],
        ['今日 UV', number_format((int)($k['uv'] ?? 0))],
        ['今日爬虫', number_format((int)($k['bot'] ?? 0))],
        ['30 天 PV', number_format((int)($m30['pv'] ?? 0))],
        ['30 天 UV', number_format((int)($m30['uv'] ?? 0))],
        ['站点数', (string)count($sites)],
    ]);
    $h .= stx_muted('照老版仪表盘：先看 6 个总数字，再一站一卡地进各自的仪表盘。'
        . '口径：**PV/UV 只计人类流量**（爬虫单独一列，不算进 PV）；'
        . '访客 = 客户端随机 uid（不存 IP）；时间 = 北京时间（Asia/Shanghai）。');

    if (!$sites) {
        $h .= stx_empty('站点登记表是空的。先到「设置」页点「同步站点登记」。');
        return ['kind' => 'page', 'body' => $h];
    }

    $h .= '<h2 style="font-size:16px;margin:4px 0 12px">站点</h2><div class="stx-grid">';
    foreach ($sites as $s) {
        $sk = (string)$s['site_key'];
        $lab = trim((string)$s['label']) !== '' ? (string)$s['label'] : $sk;
        $dom = trim((string)$s['domains']) !== '' ? (string)$s['domains'] : (string)$s['host'];
        $h .= '<div class="stx-scard"><h3><a href="' . e(statx_ui_url($ctx, ['p' => 'overview', 'site' => $sk]))
            . '">' . e($lab) . '</a>' . ((int)$s['enabled'] === 1 ? '' : ' <span class="stx-muted-inline">未启用</span>') . '</h3>'
            . '<div class="row"><span>域名</span><span>' . e($dom !== '' ? $dom : '—') . '</span></div>'
            . '<div class="row"><span>今日 PV</span><b>' . (int)$s['pv'] . '</b></div>'
            . '<div class="row"><span>今日 UV</span><b>' . (int)$s['uv'] . '</b></div>'
            . '<div class="row"><span>今日爬虫</span><span>' . (int)$s['bot'] . '</span></div>'
            . '<div class="row"><span>最近上报</span><span>' . e((string)($s['last_hit_at'] ?? '—') ?: '—') . '</span></div>'
            . '<div class="stx-acts"><a class="stx-btn" href="' . e(statx_ui_url($ctx, ['p' => 'overview', 'site' => $sk])) . '">进入仪表盘</a>'
            . '<a class="stx-link" href="' . e(statx_ui_url($ctx, ['p' => 'settings'])) . '">编辑</a>'
            . '<a class="stx-link" href="' . e(statx_ui_url($ctx, ['p' => 'check'])) . '">检测</a></div>'
            . '</div>';
    }
    $h .= '</div>';
    $h .= stx_muted('★ 老版的站点卡片只有"今日 PV / UV / 爬虫"三个数（14 张卡）；本插件**多给一个「最近上报」** —— '
        . '它才是判断"这个站的统计还有没有在跑"的那一列。');
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_dash(array $ctx): array
{
    return statx_pg_sites_grid($ctx);
}

/* ============================== 分析：网站概况（老版 /site/{sid}） ============================== */

function statx_pg_overview(array $ctx): array
{
    $b = statx_bounds();
    if ($ctx['site'] === '') {
        return ['kind' => 'page', 'body' => stx_empty('所选范围内没有任何站点有记录。'
            . ($b['rows'] === 0 ? '库里目前 0 行明细 —— 先到「设置」确认采集总开关与站点登记。' : '换个大一点的时间范围试试。'))];
    }
    $k = statx_dash_kpi($ctx);
    $kv = [];
    foreach ($k['rows'] as $r) { $kv[] = [(string)$r['k'], (string)$r['v']]; }

    $h = stx_kpis($kv);
    $h .= stx_table(stx_t(statx_dash_trend($ctx), 'trend'), $ctx);
    $h .= stx_muted('趋势：' . ($ctx['r'] === 'today' || $ctx['r'] === 'yesterday' || $ctx['r'] === 'day'
        ? '按小时（24 点）' : '按天') . '；范围 <b>' . e($ctx['from']) . ' ~ ' . e($ctx['to']) . '</b>（'
        . e($ctx['range_label']) . '）。');

    $h .= stx_table(stx_t(statx_dash_top_page($ctx), 'page'), $ctx);
    $h .= stx_pair([
        stx_table(stx_t(statx_dash_top_ref($ctx), 'ref'), $ctx),
        stx_table(stx_t(statx_dash_rtype($ctx), 'rtype'), $ctx),
    ]);
    $h .= stx_pair([
        stx_table(stx_t(statx_dash_browser($ctx), 'bow'), $ctx),
        stx_table(stx_t(statx_dash_os($ctx), 'os'), $ctx),
    ]);
    $h .= stx_table(stx_t(statx_dash_top_geo($ctx), 'geo'), $ctx);
    $h .= stx_table(stx_t(statx_c_bots($ctx), 'bots'), $ctx);
    $h .= statx_snippet_block($ctx);
    return ['kind' => 'page', 'body' => $h];
}

/** 「统计代码」代码块（照老版概况页底的代码块 + 复制按钮；本插件不引 JS，改用"点一下就全选"的原生 `readonly` 框） */
function statx_snippet_block(array $ctx): string
{
    try {
        $st = statx_q_collect_status();
        $rt = $st['rt'];
        $pub = (string)($st['pub'] ?? '');
        $url = $pub . (string)($rt['script'] ?? '/st.js');
        $code = $pub !== '' ? ('<script src="' . $url . '" defer></script>') : '';
    } catch (Throwable $e) {
        return '';
    }
    if ($code === '') {
        // ★ 2026-09-14：域名识别不出来时**不能**给出一行指向别人的脚本 —— 明确报错并给修法
        return '<div class="stx-card"><div class="stx-cardh"><h2>统计代码</h2>'
            . '<span class="stx-note">还不能生成</span></div>'
            . stx_empty('还没有识别出本站的对外域名 —— 这一行必须指向**你自己的站**，'
                . '所以插件不会替你猜一个别人的域名。到「采集与站点管理」把「集中端点域名」填成 '
                . '`https://' . e(statx_collect_site_key()) . '`（或你的真实域名），保存后这里就会出现可复制的一行。')
            . '</div>';
    }
    $h = '<div class="stx-card"><div class="stx-cardh"><h2>统计代码</h2>'
        . '<span class="stx-note">站点侧只需这一行</span>'
        . '<a class="stx-csv" href="' . e(statx_ui_url($ctx, ['p' => 'collect'])) . '">接入状态 →</a></div>';
    $h .= '<div class="stx-scroll"><pre style="background:#f6f8f4;border:1px solid #e4e8e0;border-radius:8px;'
        . 'padding:12px;font-size:12px;margin:0;white-space:pre-wrap;word-break:break-all">' . e($code) . '</pre></div>';
    $h .= stx_muted('老版这一块带一个 `navigator.clipboard` 的「复制代码」按钮（老版全站唯一第二处内联 JS）。'
        // ★ 2026-09-15 修：这里的 `<pre>` / `<script>` 是**说明文字**，必须写成实体。
        //   原来直接写 `<script>` ⇒ 浏览器把它当成真的脚本开始标签，而页面里**没有 `</script>`**，
        //   于是这句话之后的内容（页脚口径那一行）**被当作脚本吞掉、不再显示**（实测：该标签出现在
        //   页面 99.1% 处，之后就是页脚）。`<pre>` 同理（未闭合）。这也是"零 JS 口径"的自证：
        //   本页 `<script src>` 始终 = 0，唯一那个 `<script` 字面量就是这句说明。
        . '本插件不引 JS —— 用原生 `<code>&lt;pre&gt;</code>` 框：全选复制一样方便，'
        . '而全站 `<code>&lt;script&gt;</code>` 数量保持 0。'
        . '站点侧**只加这一行**，不改模板、不动 tj 的旁站标签（本插件只追加自己的标记块）。');
    return $h . '</div>';
}

/* ============================== 分析：流量分析（老版 /traffic） ============================== */

function statx_pg_traffic(array $ctx): array
{
    if ($ctx['site'] === '') { return ['kind' => 'page', 'body' => stx_empty('所选范围内没有站点数据。')]; }
    $h = stx_table(stx_t(statx_c_days($ctx), 'days'), $ctx);

    // 时段分布柱状图（照老版「时段分布」+ 图例「按小时（0-23 点）」）
    $hours = statx_q_hours($ctx);
    $tot = 0;
    foreach ($hours as $r) { $tot += (int)$r['v']; }
    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>时段分布</h2>'
        . '<span class="stx-note">' . e($ctx['range_label']) . ' 内累计</span>'
        . '<a class="stx-csv" href="' . e(statx_ui_url($ctx, ['csv' => 'hours'])) . '">CSV</a></div>';
    if ($tot === 0) {
        $h .= stx_empty('所选范围内没有人类 PV，画不出时段分布。');
    } else {
        $hmax = max(array_map(static fn($r) => (int)$r['v'], $hours));
        $h .= stx_svg_bars($hours, '#1d9e75', 760, 170, ' 次');
        $h .= '<div class="stx-legend"><span><i style="background:#1d9e75"></i>人类 PV（按小时，0-23 点）</span>'
            . '<span class="stx-muted-inline">峰值 ' . $hmax . ' 次</span></div>';
        $h .= stx_muted('柱高 = 该小时的人类 PV 合计。老版这张图是**单日**的 24 小时分布（`/traffic` 与 `/day` 都有）；'
            . '本插件在**多天范围**下把同小时累加（这样"几点是高峰"依然可读），单日范围下与老版完全一致。');
    }
    $h .= '</div>';

    // 流量来源四分类 KPI（照老版的 KPI 墙观感）
    $rt = statx_dash_rtype($ctx);
    $map = [];
    foreach ($rt['rows'] as $r) { $map[strip_tags((string)$r['k'])] = (int)$r['c']; }
    $h .= stx_kpis([
        ['直达访问', number_format((int)($map['直达（无来路）'] ?? 0))],
        ['搜索引擎', number_format((int)($map['搜索引擎'] ?? 0))],
        ['外部链接', number_format((int)($map['外链'] ?? 0))],
        ['站内来源', number_format((int)($map['站内来源'] ?? 0))],
        ['社交/内容平台', number_format((int)($map['社交/内容平台'] ?? 0))],
    ]);
    $h .= stx_table(stx_t(statx_dash_depth($ctx), 'depth'), $ctx);
    $h .= stx_table(stx_t(statx_dash_top_page($ctx), 'page'), $ctx);
    return ['kind' => 'page', 'body' => $h];
}

/* ============================== 分析：趋势分析（老版 /trend） ============================== */

function statx_pg_trend(array $ctx): array
{
    if ($ctx['site'] === '') { return ['kind' => 'page', 'body' => stx_empty('所选范围内没有站点数据。')]; }
    $rows = statx_dash_q('SELECT d b,'
        . " SUM(kind='pv' AND is_bot=0) pv,"
        . ' COUNT(DISTINCT CASE WHEN kind=\'pv\' AND is_bot=0 THEN ' . STATX_DASH_UV . ' END) uv,'
        . " SUM(kind='pv' AND is_bot=1) bot FROM " . STATX_SDB_HITS
        . ' WHERE site_key=? AND d>=? AND d<=? GROUP BY b ORDER BY b', [$ctx['site'], $ctx['from'], $ctx['to']]);
    $series = [];
    $maxPv = 0; $maxDay = '—'; $sumPv = 0; $sumUv = 0;
    foreach ($rows as $r) {
        $pv = (int)$r['pv'];
        $series[] = ['b' => (string)$r['b'], 'pv' => $pv, 'uv' => (int)$r['uv'], 'bot' => (int)$r['bot']];
        $sumPv += $pv; $sumUv += (int)$r['uv'];
        if ($pv > $maxPv) { $maxPv = $pv; $maxDay = (string)$r['b']; }
    }
    $avg = count($series) > 0 ? round($sumPv / count($series), 1) : 0;
    $h = stx_kpis([
        ['区间 PV', number_format($sumPv)],
        ['区间 UV 之和', number_format($sumUv), '逐日 UV 相加（跨天会重复计人）', 'plain'],
        ['单日峰值', number_format($maxPv), '发生在 <b>' . e($maxDay) . '</b>'],
        ['日均 PV', number_format($avg, $avg === floor($avg) ? 0 : 1)],
        ['数据天数', (string)count($series)],
    ]);
    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>PV / UV 趋势</h2>'
        . '<span class="stx-note">范围 ' . e($ctx['from']) . ' ~ ' . e($ctx['to']) . '</span>'
        . '<a class="stx-csv" href="' . e(statx_ui_url($ctx, ['csv' => 'trend'])) . '">CSV</a></div>';
    if (!$series) {
        $h .= stx_empty('所选范围内没有数据。');
    } else {
        $h .= stx_svg_line($series, [
            ['key' => 'pv', 'label' => 'PV（人类）', 'color' => '#1d9e75', 'area' => true],
            ['key' => 'uv', 'label' => 'UV（访客）', 'color' => '#e8a33d'],
        ], 760, 210);
        $h .= stx_muted('两条线：PV 绿 / UV 橙（**与老版同一对配色**：老版折线图就是 PV 绿 `#1d9e75` / UV 橙 `#e8a33d`）。'
            . '★ 「区间 UV 之和」是**逐日 UV 相加**，同一个人在两天访问会被算两次 —— '
            . '要看真实人数请看「网站概况」里的 UV（那是整个区间去重后的值）。这个差别老版没有写明，这里写明。');
    }
    $h .= '</div>';
    $h .= stx_table(stx_t(statx_c_days($ctx), 'days'), $ctx);
    return ['kind' => 'page', 'body' => $h];
}

/* ============================== 分析：单日详情（老版 /day：12 表 + 1 图） ============================== */

function statx_pg_day(array $ctx): array
{
    $b = statx_bounds();
    if ($ctx['single'] === '') {
        // 没给日期 → 默认今天（老版是必须带 ?d=，这里给个合理的默认并说明）
        $ctx['single'] = $ctx['to'];
    }
    $h = '';
    $d = (string)$ctx['single'];

    // 1) 顶部 24 小时分布图（照老版：单日页第一屏就是这张柱状图）
    $hours = statx_q_hours($ctx);
    $tot = 0;
    foreach ($hours as $r) { $tot += (int)$r['v']; }
    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>24 小时分布</h2>'
        . '<span class="stx-note">' . e($d) . ' · 人类 PV 合计 ' . number_format($tot) . '</span>'
        . '<a class="stx-csv" href="' . e(statx_ui_url($ctx, ['csv' => 'hours'])) . '">CSV</a></div>';
    $h .= $tot === 0 ? stx_empty('这一天没有人类 PV。' . (($d > date('Y-m-d')) ? '（选的是未来日期）' : ''))
        : stx_svg_bars($hours, '#1d9e75', 760, 170, ' 次');
    $h .= '</div>';

    // 2) KPI 墙（老版单日页是 8 个）
    $k = statx_dash_kpi($ctx);
    $kv = [];
    foreach ($k['rows'] as $r) { $kv[] = [(string)$r['k'], (string)$r['v']]; }
    $h .= stx_kpis($kv);

    // 3) 12 张表（照老版顺序）
    $h .= stx_table(stx_t(statx_dash_top_page($ctx), 'page'), $ctx);
    $h .= stx_pair([
        stx_table(stx_t(statx_dash_top_ref($ctx), 'ref'), $ctx),
        stx_table(stx_t(statx_dash_rtype($ctx), 'rtype'), $ctx),
    ]);
    $h .= stx_table(stx_t(statx_c_bots($ctx), 'bots'), $ctx);
    $h .= stx_pair([
        stx_table(stx_t(statx_dash_top_geo($ctx), 'geo'), $ctx),
        stx_table(stx_t(statx_dash_os($ctx), 'os'), $ctx),
    ]);
    $h .= stx_pair([
        stx_table(stx_t(statx_dash_browser($ctx), 'bow'), $ctx),
        stx_table(stx_t(statx_dash_dev($ctx), 'dev'), $ctx),
    ]);
    $h .= stx_pair([
        stx_table(stx_t(statx_dash_res($ctx), 'res'), $ctx),
        stx_table(stx_t(statx_dash_lang($ctx), 'lang'), $ctx),
    ]);
    $h .= stx_table(stx_t(statx_dash_depth($ctx), 'depth'), $ctx);
    $h .= stx_table(stx_t(statx_c_users($ctx), 'users'), $ctx);
    $h .= stx_muted('★ 日期继承：在这一页点左栏任何菜单 / 换站点，都会**带着这一天**过去（`?r=day&d=' . e($d) . '`），'
        . '与老版的中间件行为一致。');
    return ['kind' => 'page', 'body' => $h];
}

/* ============================== 分析：实时访客（老版 /live） ============================== */

function statx_pg_live(array $ctx): array
{
    $now = date('Y-m-d H:i:s');
    $min15 = date('Y-m-d H:i:s', time() - 900);
    $min30 = date('Y-m-d H:i:s', time() - 1800);
    $today = date('Y-m-d');

    $k = statx_dash_q('SELECT'
        . " COUNT(DISTINCT CASE WHEN kind='pv' THEN " . STATX_DASH_UV . ' END) online15,'
        . " SUM(kind='pv') pv15,"
        . ' COUNT(DISTINCT CASE WHEN kind=\'pv\' THEN NULLIF(sid,\'\') END) sess30,'
        . " COUNT(DISTINCT CASE WHEN kind='cl' THEN " . STATX_DASH_UV . ' END) clickers'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND ts>=? AND ts<=? LIMIT 1',
        [$ctx['site'], $min15, $now]);
    $k = $k[0] ?? [];
    $k2 = statx_dash_q('SELECT COUNT(DISTINCT ' . STATX_DASH_UV . ") uv, SUM(kind='pv') pv FROM " . STATX_SDB_HITS
        . " WHERE site_key=? AND ts>=? AND ts<=? LIMIT 1", [$ctx['site'], $min30, $now]);
    $k2 = $k2[0] ?? [];

    $h = stx_kpis([
        ['当前在线（15 分钟）', number_format((int)($k['online15'] ?? 0)), '去重访客 uid'],
        ['15 分钟 PV', number_format((int)($k['pv15'] ?? 0))],
        ['30 分钟访客', number_format((int)($k2['uv'] ?? 0))],
        ['30 分钟 PV', number_format((int)($k2['pv'] ?? 0))],
        ['今日 UV', number_format((int)(statx_sdb_val('SELECT COUNT(DISTINCT ' . STATX_DASH_UV . ') FROM '
            . STATX_SDB_HITS . " WHERE site_key=? AND d=? AND kind='pv' AND is_bot=0", [$ctx['site'], $today]) ?: 0))],
    ]);

    // 在线人数趋势（近 30 分钟，每 5 分钟 6 根柱 —— 照老版"6 根柱"）
    $buckets = [];
    for ($i = 5; $i >= 0; $i--) {
        $end = time() - $i * 300;
        $start = $end - 300;
        $buckets[] = ['from' => date('Y-m-d H:i:s', $start), 'to' => date('Y-m-d H:i:s', $end),
                      'l' => date('H:i', $start), 'v' => 0];
    }
    $rows = statx_dash_q('SELECT ' . STATX_DASH_UV . ' u, ts FROM ' . STATX_SDB_HITS
        . " WHERE site_key=? AND ts>=? AND ts<=? AND kind='pv'", [$ctx['site'], $buckets[0]['from'], $now]);
    foreach ($rows as $r) {
        $t = strtotime((string)$r['ts']);
        foreach ($buckets as $i => $b) {
            if ($t >= strtotime($b['from']) && $t <= strtotime($b['to'])) { $buckets[$i]['_u'][(string)$r['u']] = 1; }
        }
    }
    foreach ($buckets as $i => $b) { $buckets[$i]['v'] = count($b['_u'] ?? []); }

    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>在线人数趋势（近 30 分钟，每 5 分钟）</h2>'
        . '<span class="stx-note">本页每 15 秒自动整页刷新（`<meta http-equiv="refresh">`，不是 AJAX —— 照老版）</span></div>';
    $h .= stx_svg_bars($buckets, '#1d9e75', 760, 170, ' 人');
    $h .= '<div class="stx-legend"><span><i style="background:#1d9e75"></i>在线访客（uid 去重）</span></div>';
    $h .= stx_muted('★ 老版这张图的横轴是"近 30 分钟里每 5 分钟"的 6 根柱，本插件**照抄**；'
        . '纵轴是**去重后的访客 uid**（不是 PV）。整页 15 秒自动重载用的是老版同款 '
        . '`<meta http-equiv="refresh" content="15">` —— 它**不算 JS**，所以本插件仍然零 JS。');
    $h .= '</div>';

    // 在线用户列表（照老版 9 列观感，只列近 15 分钟）
    $list = statx_dash_q('SELECT uid u, COUNT(*) pv, MIN(ts) t0, MAX(ts) t1,'
        . ' MAX(region) region, MAX(device) device, MAX(browser) browser, MAX(os) os,'
        . ' MAX(path) lastpath, COUNT(DISTINCT NULLIF(sid,\'\')) sess'
        . ' FROM ' . STATX_SDB_HITS . " WHERE site_key=? AND ts>=? AND ts<=? AND kind='pv' GROUP BY u"
        . ' ORDER BY t1 DESC LIMIT 100', [$ctx['site'], $min15, $now]);
    $out = [];
    foreach ($list as $r) {
        $u = (string)$r['u'];
        $out[] = ['u' => '<code>' . e($u) . '</code>', 'last' => e((string)$r['t1']),
                  'pv' => (int)$r['pv'], 'sess' => (int)$r['sess'],
                  'path' => e((string)$r['lastpath']),
                  'dev' => e((string)$r['device']) . ' / ' . e((string)$r['browser']) . ' / ' . e((string)$r['os']),
                  'geo' => e(statx_geo_short((string)$r['region']) ?: '—'),
                  'act' => stx_lk('轨迹 →', ['p' => 'visitor', 'uid' => $u], $ctx)];
    }
    $h .= stx_table(['cols' => [['访客', 'u'], ['最后动作', 'last'], ['PV', 'pv', 'r'], ['会话', 'sess', 'r'],
                                ['最近页面', 'path'], ['设备 / 浏览器 / 系统', 'dev'], ['地域', 'geo'], ['操作', 'act']],
                     'rows' => $out, 'bar' => 'pv', 'title' => '在线用户列表（近 15 分钟）',
                     'right' => count($out) . ' 人',
                     'note' => '老版这一页的表是 9 列、按最近活跃倒序；本插件同序，'
                         . '但**多一列「操作」直接下钻到访客轨迹**（老版要在用户列表页才能进轨迹）。'], $ctx);
    return ['kind' => 'page', 'body' => $h];
}
