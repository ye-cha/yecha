<?php
declare(strict_types=1);
/**
 * StatX · 页面级 builder 之二（批次 3）：来源分析 / 访问分析 / 转化分析 / 访客分析 / 访客轨迹
 *
 * 每一页的结构**照老版对应页的骨架**（区块顺序、表头列名、口径说明的位置都照）。
 * 差异逐条写在各页的 `note` 里（页面上一眼能看到"哪里故意不同、为什么"）。
 */

require_once __DIR__ . '/pages3.php';

/* ============================== 来源分析 ============================== */

function statx_pg_source(array $ctx): array
{
    if ($ctx['site'] === '') { return ['kind' => 'page', 'body' => stx_empty('所选范围内没有站点数据。')]; }
    $rt = statx_dash_rtype($ctx);
    $map = [];
    foreach ($rt['rows'] as $r) { $map[strip_tags((string)$r['k'])] = (int)$r['c']; }
    $h = stx_kpis([
        ['直达（无来路）', number_format((int)($map['直达（无来路）'] ?? 0))],
        ['搜索引擎', number_format((int)($map['搜索引擎'] ?? 0))],
        ['外部链接', number_format((int)($map['外链'] ?? 0))],
        ['站内来源', number_format((int)($map['站内来源'] ?? 0))],
    ]);
    $h .= stx_table(stx_t(statx_dash_rtype($ctx), 'rtype'), $ctx);
    $h .= stx_table(stx_t(statx_dash_top_ref($ctx), 'ref'), $ctx);
    $h .= stx_table(stx_t(statx_dash_utm($ctx), 'utm'), $ctx);
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_src_search(array $ctx): array
{
    $rows = statx_q_ref_search($ctx);
    $tot = 0; foreach ($rows as $r) { $tot += (int)$r['c']; }
    $h = stx_kpis([
        ['搜索引擎 PV', number_format($tot)],
        ['不同引擎', (string)count($rows)],
        ['最大来源', $rows ? strip_tags((string)$rows[0]['k']) : '—', $rows ? ('<b>' . (int)$rows[0]['c'] . '</b> 次') : ''],
    ]);
    $h .= stx_table(stx_t(statx_c_ref_search($ctx), 'search'), $ctx);
    $h .= stx_muted('「搜索引擎」= 来路 referrer 的域名命中本插件内置引擎库'
        . '（`derive.php` 的 `statx_se_engines()`：Google / Bing / 百度 / 搜狗 / 360 / DuckDuckGo / Yandex / Naver 等）。'
        . '**这条链路的可靠性天然有限**：Google 早已不把关键词放进 referrer，'
        . '所以"引擎 PV 有数、关键词为空"是正常现象，不是系统丢数据。');
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_src_keyword(array $ctx): array
{
    $rows = statx_q_keyword($ctx, 50);
    $se = 0; $in = 0;
    foreach ($rows as $r) { $se += (int)$r['se']; $in += (int)$r['inkw']; }
    $h = stx_kpis([
        ['关键词条数', (string)count($rows)],
        ['引擎关键词 PV', number_format($se)],
        ['站内搜索词 PV', number_format($in)],
    ]);
    $h .= stx_table(stx_t(statx_c_keyword($ctx), 'keyword'), $ctx);
    $h .= stx_muted('★ r12 新增的「来路」列 = 该关键词**最近一条**带 `?q=…` 的完整来源地址'
        . '（截断显示，**鼠标悬停看全文**；零 JS）。'
        . '库里 `ref` 一直存的就是完整 URL（≤500 字符）—— 这里只是把它显示出来。'
        . '**引擎关键词天然是少数**：Google 早已不把 `q=` 放进 referrer（浏览器隐私策略），'
        . 'Bing 只在"从搜索结果页点进来"时给 `q=`，同一个引擎的其它 URL（如 `https://cn.bing.com/`）'
        . '本来就没有查询串 —— 所以"引擎 PV 有数、关键词为空"是环境决定的，不是系统丢数据。');
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_src_ext(array $ctx): array
{
    $ext = statx_dash_ext($ctx);
    $hosts = [];
    foreach ($ext['rows'] as $r) {
        $dm = strip_tags((string)$r['k']);
        if ($dm !== '') { $hosts[$dm] = $dm; }
    }
    $h = stx_table(stx_t($ext, 'ext'), $ctx);
    $h .= stx_table(['cols' => [['来源站', 'k'], ['PV', 'c', 'r'], ['会话', 'sess', 'r']],
                     'rows' => $ext['rows'], 'bar' => 'c', 'title' => '外部来源站（点「查看」下钻）',
                     'csv' => 'ext',
                     'note' => '下钻是**链接**（老版习惯：`?h=<域名>`，复制 URL 就能分享同一个视图）。'], $ctx);
    $h .= stx_table(stx_t(statx_c_ext_detail($ctx), 'extdet'), $ctx);
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_src_int(array $ctx): array
{
    $rows = statx_dash_q('SELECT ref k, COUNT(*) c, COUNT(DISTINCT NULLIF(sid,\'\')) sess'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . " AND ref_kind='in' AND ref<>'' GROUP BY k ORDER BY c DESC, k LIMIT 50",
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; $r['sess'] = (int)$r['sess']; }
    unset($r);
    $h = stx_table(['cols' => [['来源页面（referrer 路径）', 'k'], ['PV', 'c', 'r'], ['会话', 'sess', 'r']],
                    'rows' => $rows, 'bar' => 'c', 'title' => '站内来源（按 referrer 路径）', 'csv' => 'intdet'], $ctx);
    $h .= stx_table(stx_t(statx_c_int_detail($ctx), 'intdet'), $ctx);
    return ['kind' => 'page', 'body' => $h];
}

/* ============================== 访问分析 ============================== */

/** 受访页面 TOP N（老版 TOP 50 硬上限；本插件同样封顶 50，并在页面上写明） */
function statx_c_pages50(array $ctx, int $limit = 50): array
{
    $rows = statx_dash_q('SELECT path k, COUNT(*) c, COUNT(DISTINCT ' . STATX_DASH_UV . ') uv, SUM(is_new) newv'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . ' GROUP BY k ORDER BY c DESC, k LIMIT ' . max(1, min(200, $limit)),
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) {
        $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; $r['uv'] = (int)$r['uv']; $r['newv'] = (int)$r['newv'];
    }
    unset($r);
    return ['cols' => [['页面', 'k'], ['PV', 'c', 'r'], ['UV', 'uv', 'r'], ['新访客', 'newv', 'r']],
            'rows' => $rows, 'bar' => 'c', 'title' => '受访页面 TOP ' . $limit];
}

/** 入口页面 TOP N（老版 TOP 50） */
function statx_c_entry50(array $ctx, int $limit = 50): array
{
    $rows = statx_dash_q('SELECT path k, COUNT(*) c FROM ('
        . ' SELECT sid, path, MIN(id) mid FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . " AND sid<>'' GROUP BY sid) GROUP BY k ORDER BY c DESC, k LIMIT " . max(1, min(200, $limit)),
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; }
    unset($r);
    return ['cols' => [['入口页面', 'k'], ['会话数', 'c', 'r']], 'rows' => $rows, 'bar' => 'c',
            'title' => '入口页面 TOP ' . $limit,
            'note' => '口径：入口页 = 每次会话（`sid`）访问的**第一个**页面。'];
}

/** 退出页面 TOP N（★ 老版没有这一页 —— 新版优点，保留） */
function statx_c_exit50(array $ctx, int $limit = 50): array
{
    $rows = statx_dash_q('SELECT path k, COUNT(*) c FROM ('
        . ' SELECT sid, path, MAX(id) mid FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . " AND sid<>'' GROUP BY sid) GROUP BY k ORDER BY c DESC, k LIMIT " . max(1, min(200, $limit)),
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = e((string)$r['k']); $r['c'] = (int)$r['c']; }
    unset($r);
    return ['cols' => [['退出页面', 'k'], ['会话数', 'c', 'r']], 'rows' => $rows, 'bar' => 'c',
            'title' => '退出页面 TOP ' . $limit,
            'note' => '★ **老版没有"退出页"这个视图**（47 条路由里一处都没有）。本插件保留它：'
                . '入口页告诉你"读者从哪进来"，退出页告诉你"读到哪就走了"——排查内容断点比跳出率一个数字有用得多。'];
}

function statx_pg_pages(array $ctx): array
{
    $h = stx_table(['cols' => [['页面', 'k'], ['PV', 'c', 'r'], ['UV', 'uv', 'r'], ['新访客', 'newv', 'r']],
                    'rows' => statx_c_pages50($ctx, 50)['rows'], 'bar' => 'c',
                    'title' => '受访页面 TOP 50（硬上限，与老版一致）', 'csv' => 'page50'], $ctx);
    $h .= stx_muted('老版这一页是"受访页面 TOP 50"（50 行硬上限，没有分页、没有导出）。本插件同序同上限，'
        . '**多两样**：UV / 新访客两列（老版只有 PV），以及右上角的 CSV。');
    $h .= stx_table(stx_t(statx_dash_depth($ctx), 'depth'), $ctx);
    $h .= stx_table(stx_t(statx_dash_flow($ctx), 'flow'), $ctx);
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_entry(array $ctx): array
{
    $h = stx_table(['cols' => [['入口页面', 'k'], ['会话数', 'c', 'r']],
                    'rows' => statx_c_entry50($ctx, 50)['rows'], 'bar' => 'c',
                    'title' => '入口页面 TOP 50（硬上限，与老版一致）', 'csv' => 'entry50'], $ctx);
    $h .= stx_table(stx_t(statx_dash_entry($ctx), 'entry'), $ctx);
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_exit(array $ctx): array
{
    $h = stx_table(['cols' => [['退出页面', 'k'], ['会话数', 'c', 'r']],
                    'rows' => statx_c_exit50($ctx, 50)['rows'], 'bar' => 'c',
                    'title' => '退出页面 TOP 50', 'csv' => 'exit50'], $ctx);
    $h .= stx_table(stx_t(statx_dash_exit($ctx), 'exit'), $ctx);
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_domain(array $ctx): array
{
    $h = stx_table(stx_t(statx_c_domain($ctx), 'domain'), $ctx);
    $h .= stx_muted('受访域名来自 `hits.host` —— 采集端点在写入时就记下了请求的 Host，'
        . '所以多域名站不需要额外配置。');
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_paths(array $ctx): array
{
    $h = stx_table(stx_t(statx_c_paths1($ctx), 'paths1'), $ctx);
    $h .= stx_table(stx_t(statx_c_paths2($ctx), 'paths2'), $ctx);
    $h .= stx_muted('分层是**纯 SQL 切的**（`instr()` 找第 1/第 2 个斜杠），不注册任何自定义 SQLite 函数、'
        . '不把明细取回 PHP —— 与老版"两张表各 TOP 20"的结果形状一致。');
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_depth(array $ctx): array
{
    $rows = statx_dash_q('SELECT sid, COUNT(*) n FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . " AND sid<>'' GROUP BY sid", [$ctx['site'], $ctx['from'], $ctx['to']]);
    $tot = count($rows); $sum = 0; $one = 0; $max = 0;
    foreach ($rows as $r) { $n = (int)$r['n']; $sum += $n; $max = max($max, $n); if ($n === 1) { $one++; } }
    $h = stx_kpis([
        ['会话总数', number_format($tot)],
        ['平均深度', $tot > 0 ? round($sum / $tot, 2) . ' 页' : '—'],
        ['跳出率', $tot > 0 ? round($one / $tot * 100, 1) . '%' : '—', '只有 1 个 PV 的会话占比'],
        ['最深访问', $max > 0 ? ($max . ' 页') : '—'],
    ]);
    $h .= stx_table(stx_t(statx_dash_depth($ctx), 'depth'), $ctx);
    $h .= stx_muted('老版这一页的 4 个 KPI 与本插件**同名同口径**（会话总数 / 平均深度 / 跳出率 / 最深访问）。'
        . '★ 唯一差别：老版的会话是"同一 IP + 30 分钟静默"推出来的（受 NAT 与共享出口影响），'
        . '本插件的会话是**客户端 `st.js` 生成的 `sid`**（浏览器新开标签/30 分钟静默即新会话）⇒ 更准，'
        . '代价是老版能覆盖"没跑 JS 的访问"，本插件覆盖不到（那种情况下本来也不会有 PV 数据）。');
    return ['kind' => 'page', 'body' => $h];
}

/* ---- 用户列表（页面入口；取数在 statx_c_users，CSV 与页面共用同一组 cols） ---- */

function statx_pg_users(array $ctx): array
{
    $t = statx_c_users($ctx);
    $t['csv'] = 'users';
    $h = stx_table($t, $ctx);
    $h .= stx_muted('★ 老版这一页是 **9 列**（用户 / 类型 / IP / 地域 / 设备 / PV / 页面数 / 会话 / 最后访问 / 操作），'
        . '并把「类型」列做成 `.tag t1`「标识用户」；本插件同序同形状，但**故意不同**的有三处：'
        . '① **没有 IP 列**（库里根本没有 IP —— 隐私模型：不存明文 IP）；'
        . '② 设备和浏览器/系统**合成一列**（老版只有设备一列，我们多给两个维度但省一列宽度）；'
        . '③ **多一列「首次访问」**（老版只有"最后访问"，看不出这个人是什么时候来的）。'
        . '上限同样是最多 200 行（老版硬上限）。');
    return ['kind' => 'page', 'body' => $h];
}

/* ---- 页面点击图（老版 /clickmap：候选页分页器 + 热力网格 + 5 级色标） ---- */

function statx_c_click_pages(array $ctx): array
{
    $rows = statx_dash_q("SELECT path k, COUNT(*) c FROM " . STATX_SDB_HITS
        . " WHERE site_key=? AND d>=? AND d<=? AND kind='cl' AND is_bot=0"
        . ' GROUP BY k ORDER BY c DESC, k LIMIT 60', [$ctx['site'], $ctx['from'], $ctx['to']]);
    foreach ($rows as &$r) { $r['k'] = (string)$r['k']; $r['c'] = (int)$r['c']; }
    unset($r);
    return $rows;
}

function statx_pg_clickmap(array $ctx): array
{
    $pages = statx_c_click_pages($ctx);
    $cur = (string)($ctx['q']['url'] ?? '');
    $items = [];
    foreach ($pages as $r) { $items[(string)$r['k']] = (string)$r['k'] . '（' . (int)$r['c'] . '）'; }

    $h = '<div class="stx-card"><div class="stx-cardh"><h2>选择页面（' . e($ctx['range_label']) . ' 有点击的页面）</h2>'
        . '<span class="stx-note">' . count($pages) . ' 个候选</span></div>';
    $h .= stx_pager($ctx, 'url', array_slice($items, 0, 40, true), '（不筛选）');
    if (!$pages) {
        $h .= stx_muted('所选范围内**没有任何点击记录**。点击来自 `st.js` 的 `click` 监听（`kind=\'cl\'`）——'
            . '如果站点侧脚本已接入但一条 `cl` 都没有，先确认读者真的点了（或者 `st.js` 是否被缓存挡在旧版本）。');
    }
    $h .= '</div>';

    if ($cur === '') {
        $h .= stx_muted('请在**上方选择一个页面**查看点击热力图。');
        return ['kind' => 'page', 'body' => $h];
    }
    $grid = statx_dash_q("SELECT CAST(x*24/vw AS INT) cx, CAST(y*14/vh AS INT) cy, COUNT(*) c"
        . " FROM " . STATX_SDB_HITS . " WHERE site_key=? AND d>=? AND d<=? AND kind='cl' AND is_bot=0 AND path=?"
        . ' AND vw>0 AND vh>0 AND x>=0 AND y>=0 AND x<vw AND y<vh GROUP BY cx, cy ORDER BY c DESC LIMIT 500',
        [$ctx['site'], $ctx['from'], $ctx['to'], $cur]);
    $tot = statx_dash_q("SELECT COUNT(*) c, SUM(vw>0 AND vh>0 AND x>=0 AND y>=0 AND x<vw AND y<vh) ok"
        . " FROM " . STATX_SDB_HITS . " WHERE site_key=? AND d>=? AND d<=? AND kind='cl' AND is_bot=0 AND path=?",
        [$ctx['site'], $ctx['from'], $ctx['to'], $cur]);
    $tot = $tot[0] ?? ['c' => 0, 'ok' => 0];
    $cells = [];
    foreach ($grid as $r) { $cells[] = ['cx' => (int)$r['cx'], 'cy' => (int)$r['cy'], 'c' => (int)$r['c']]; }

    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>点击热力图：' . e($cur) . '（'
        . number_format((int)$tot['c']) . ' 次点击）</h2>'
        . '<span class="stx-note">可用点击 ' . (int)$tot['ok'] . ' / ' . (int)$tot['c'] . '</span></div>';
    $h .= $cells ? stx_heat_grid($cells, 24, 14) : stx_empty('这个页面在所选范围内没有落在视口内的点击。');
    $h .= stx_muted('★ 老版这一页是 **16 列 × 9 行** 的热力网格，归一化用的是 **`screen` 分辨率** ——'
        . '浏览器窗口一窄，整张图就会横向失真（把页面顶部的点击画到中间去）。'
        . '本插件**故意不同**：网格是 **24×14**，归一化用**每个访客自己的 viewport**'
        . '（`clientX / innerWidth`），并且**只统计确实落在可视区内的点击**'
        . '（滚动后超出视口的点击被排除，避免把页脚点击画到页面顶部）。这是批次 2 的既有优点，本批不退回。'
        . '色阶仍是老版的 5 档绿（无 → `#bfe8d8` → `#7fd4b4` → `#37b588` → `#1d9e75`）。');
    $h .= '</div>';
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_flow(array $ctx): array
{
    $h = stx_table(stx_t(statx_dash_flow($ctx), 'flow'), $ctx);
    $h .= stx_muted('流向用 `sid` 把同一会话的浏览串起来（`lag(id) OVER (PARTITION BY sid ORDER BY id)`，'
        . 'SQLite 3.40 原生窗口函数），**在 SQL 里聚合**，不把明细取回 PHP。'
        . '老版这一页叫「页面上下游」、表头是"从（来源页）/ 到（目标页）/ 次数"，本插件同序（多一个占比条）。');
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_events(array $ctx): array
{
    $h = stx_table(stx_t(statx_c_events($ctx), 'events'), $ctx);
    $h .= stx_table(stx_t(statx_c_events_recent($ctx), 'events_rec'), $ctx);
    return ['kind' => 'page', 'body' => $h];
}

/* ---- 分析结论（★ 老版唯一的人工写作页；务必保留） ---- */

function statx_pg_insights(array $ctx): array
{
    $sk = $ctx['site'];
    $key = 'insight.' . ($sk !== '' ? $sk : '_global');
    $saved = (string)statx_opt($key, '');
    $at = (string)statx_opt($key . '.at', '');

    $h = '<div class="stx-card"><div class="stx-cardh"><h2>分析结论 · ' . e($sk !== '' ? $sk : '（全局）') . '</h2>'
        . '<span class="stx-note">' . ($at !== '' ? ('最后保存于 ' . e($at)) : '尚未写过结论') . '</span></div>';
    $h .= '<form method="post" action="/admin/?s=plug_statx">' . Csrf::field()
        . '<input type="hidden" name="do" value="statx-insight-save">'
        . '<input type="hidden" name="site" value="' . e($sk) . '">'
        . '<div class="stx-fld stx-wide"><label>自由书写运营结论（等宽字体；存起来给以后看）</label>'
        . '<textarea name="insight" spellcheck="false" placeholder="例：9 月 13 日上线 statx；古书屋的 PV 主要来自爬虫，'
        . '真实读者集中在蒙学三字经页；下周准备把 /category/ 的入口链接往前挪。">' . e($saved) . '</textarea></div>'
        . '<div style="margin-top:10px;display:flex;gap:10px;align-items:center">'
        . '<button class="stx-btn" type="submit">保存结论</button>'
        . '<span class="stx-muted-inline">只在点「保存结论」时写入；本页**读取不产生任何副作用**</span></div>'
        . '</form></div>';

    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>顺手看的几个数</h2>'
        . '<span class="stx-note">写结论时最常用的下钻入口（老版这一页也是先给一排跳转链接）</span></div>';
    $links = [
        ['p' => 'overview', 'label' => '网站概况'],
        ['p' => 'traffic', 'label' => '流量分析'],
        ['p' => 'trend', 'label' => '趋势分析'],
        ['p' => 'src_keyword', 'label' => '关键词'],
        ['p' => 'pages', 'label' => '受访页面'],
        ['p' => 'depth', 'label' => '访问深度'],
        ['p' => 'region', 'label' => '地域分布'],
        ['p' => 'bots', 'label' => '蜘蛛与 AI'],
        ['p' => 'conversion', 'label' => '转化概况'],
    ];
    $acts = '';
    foreach ($links as $l) { $acts .= stx_lk($l['label'], ['p' => $l['p']], $ctx) . ' '; }
    $h .= '<div>' . $acts . '</div>';
    $k = statx_dash_kpi($ctx);
    $kv = [];
    foreach (array_slice($k['rows'], 0, 6) as $r) { $kv[] = [(string)$r['k'], (string)$r['v']]; }
    $h .= stx_kpis($kv);
    $h .= stx_muted('★ 老版 47 条路由里**只有这一页是可写的**（一个 `<textarea>` + 保存按钮）。'
        . '它看着不起眼，但是"三个月后回来看当时在想什么"的唯一载体 —— 所以务必保留。'
        . '本插件把它存进 `options`（`statx.insight.<site>`），**不存库、不建表**；'
        . '每个站点各有一份，切站点就是切结论。');
    $h .= '</div>';
    return ['kind' => 'page', 'body' => $h];
}

/* ============================== 转化分析 ============================== */

function statx_pg_conversion(array $ctx): array
{
    $rows = statx_dash_q('SELECT CASE WHEN pv>=3 THEN 1 ELSE 0 END k, COUNT(*) c FROM ('
        . ' SELECT sid, COUNT(*) pv FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . " AND sid<>'' GROUP BY sid) GROUP BY k", [$ctx['site'], $ctx['from'], $ctx['to']]);
    $conv = 0; $tot = 0;
    foreach ($rows as $r) { $tot += (int)$r['c']; if ((int)$r['k'] === 1) { $conv = (int)$r['c']; } }
    $h = stx_kpis([
        ['转化会话（≥3 页）', number_format($conv)],
        ['会话总数', number_format($tot)],
        ['转化率', $tot > 0 ? round($conv / $tot * 100, 1) . '%' : '—'],
        ['未转化会话', number_format($tot - $conv), '只看了 1–2 页'],
    ]);
    $h .= stx_table(stx_t(statx_dash_conv($ctx), 'conv'), $ctx);
    $h .= stx_muted('★ **这一页的口径与老版故意不同**：老版的转化是"URL 包含某个关键词"'
        . '（需要在后台先添加"转化目标"，且只有配置过的站点才有数）。'
        . '本插件用**行为口径**：一次会话里浏览 ≥ 3 个页面就算转化 —— 不需要任何配置、'
        . '对所有站点立刻可用，而且不会被"URL 改名"悄悄弄坏。'
        . '要看"最后三步读了什么"，看下一屏「转化路径」。');
    $h .= stx_table(stx_t(statx_dash_cpath($ctx), 'cpath'), $ctx);
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_cpath(array $ctx): array
{
    $h = stx_table(stx_t(statx_dash_cpath($ctx), 'cpath'), $ctx);
    $h .= stx_table(stx_t(statx_dash_conv($ctx), 'conv'), $ctx);
    $h .= stx_muted('老版这一页是"选一个转化目标 → 看该目标前 N 步的路径"（未配置目标时只有一句'
        . '「请先在上方添加转化目标」）。本插件的转化目标是**行为定义的**（会话 ≥3 页），'
        . '所以这一页永远有内容可看 —— 取每个转化会话**最后三个页面**的序列并计数，'
        . '回答的是"读者最后在读什么"。');
    return ['kind' => 'page', 'body' => $h];
}

/* ============================== 访客分析 ============================== */

function statx_pg_region(array $ctx): array
{
    $h = stx_table(stx_t(statx_dash_geo_full($ctx), 'geov'), $ctx);
    $h .= stx_table(stx_t(statx_dash_ipver($ctx), 'ipver'), $ctx);
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_env(array $ctx): array
{
    $h = stx_pair([
        stx_table(stx_t(statx_dash_os($ctx), 'os'), $ctx),
        stx_table(stx_t(statx_dash_browser($ctx), 'bow'), $ctx),
    ]);
    $h .= stx_pair([
        stx_table(stx_t(statx_dash_dev($ctx), 'dev'), $ctx),
        stx_table(stx_t(statx_dash_res($ctx), 'res'), $ctx),
    ]);
    $h .= stx_table(stx_t(statx_dash_lang($ctx), 'lang'), $ctx);
    $h .= stx_muted('老版这一页是 **5 张表**（操作系统 / 浏览器 / 设备 / 分辨率 / 语言），本插件同序同 5 张。'
        . '★ 分辨率这一列**与老版同口径**（`screen` 尺寸，即显示器分辨率），'
        . '而「页面点击图」的归一化**故意不用 screen**（用 viewport）—— 两者用途不同，不要混。'
        . '⚠️ 空字符串那一行（显示为 `(未知)`）是**批次 2 之前入库的老行**：那时端点还没派生 browser/os。'
        . '新流量都会带上。');
    return ['kind' => 'page', 'body' => $h];
}

function statx_pg_newold(array $ctx): array
{
    // ★ 口径必须与 statx_dash_new() **完全一致**（`statx_dash_w()`：site + 区间 + kind='pv' + is_bot=0），
    //   否则同一页上的 KPI 与表格会各算一套、给出两个数（这正是老版被诟病的"两处口径分叉"）。
    $rows = statx_dash_q("SELECT is_new k, SUM(kind='pv' AND is_bot=0) pv,"
        . ' COUNT(DISTINCT CASE WHEN kind=\'pv\' AND is_bot=0 THEN ' . STATX_DASH_UV . ' END) uv'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND d>=? AND d<=? GROUP BY k',
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    $new = 0; $old = 0;
    foreach ($rows as $r) { if ((int)$r['k'] === 1) { $new = (int)$r['pv']; } else { $old = (int)$r['pv']; } }
    $tot = $new + $old;
    $h = stx_kpis([
        ['新访客访问', number_format($new)],
        ['老访客访问', number_format($old)],
        ['新访客占比', $tot > 0 ? round($new / $tot * 100, 1) . '%' : '—'],
    ]);
    $h .= stx_table(stx_t(statx_dash_new($ctx), 'newv'), $ctx);
    $h .= stx_muted('老版这一页的说明是"靠浏览器本地标识判断"；本插件同样是客户端标识，但**口径更硬**：'
        . '`is_new` 在**采集端点入库时**就算好并落库（该 `uid` 在本插件保留窗口内是否第一次出现），'
        . '所以这一页和看板上的"新访客"数字**永远是同一个值**，不会两处各算一遍算出两个数。');
    return ['kind' => 'page', 'body' => $h];
}

/* ============================== 访客轨迹（老版 /visitor，最细的一页） ============================== */

function statx_pg_visitor(array $ctx): array
{
    $uid = (string)($ctx['q']['uid'] ?? '');
    // ★★ r8：访客轨迹是**下钻页**（不在左栏导航里），必须留一条"回到来源页"的路 —— 而且
    //   返回时**当前站点与时间档要原样带回去**（否则用户回到的是别的站/别的档）。
    $back = stx_lk('← 返回用户列表', ['p' => 'users'], $ctx);
    if ($uid === '') {
        return ['kind' => 'page', 'body' => stx_muted('缺少参数 `uid` —— 请从「用户列表」或「实时访客」的'
            . '「轨迹 →」进入（老版无参数时是 302 回用户列表，本插件给一句说明，少一次跳转）。')
            . $back];
    }
    $rows = statx_dash_q('SELECT ts, path, kind, en, device, browser, os, region, ref_domain, sid, ipver'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND uid=? AND d>=? AND d<=? ORDER BY id',
        [$ctx['site'], $uid, $ctx['from'], $ctx['to']]);

    $pv = 0; $cl = 0; $ev = 0; $paths = []; $sids = []; $t0 = ''; $t1 = '';
    $region = ''; $dev = ''; $bow = ''; $os = ''; $ref = ''; $v6 = 0; $v4 = 0;
    foreach ($rows as $r) {
        $p = (string)$r['path'];
        $k = (string)$r['kind'];
        if ($k === 'pv') { $pv++; $paths[$p] = 1; }
        elseif ($k === 'cl') { $cl++; }
        if ((string)$r['en'] !== '') { $ev++; }
        if ((string)$r['sid'] !== '') { $sids[(string)$r['sid']] = 1; }
        if ($t0 === '') { $t0 = (string)$r['ts']; }
        $t1 = (string)$r['ts'];
        if ($region === '' && (string)$r['region'] !== '') { $region = (string)$r['region']; }
        if ($dev === '') { $dev = (string)$r['device']; }
        if ($bow === '') { $bow = (string)$r['browser']; }
        if ($os === '') { $os = (string)$r['os']; }
        if ($ref === '' && (string)$r['ref_domain'] !== '') { $ref = (string)$r['ref_domain']; }
        if ((int)$r['ipver'] === 6) { $v6++; } elseif ((int)$r['ipver'] === 4) { $v4++; }
    }
    $news = statx_dash_q('SELECT MAX(is_new) n FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND uid=? AND d>=? AND d<=?',
        [$ctx['site'], $uid, $ctx['from'], $ctx['to']]);
    $isNew = (int)($news[0]['n'] ?? 0) === 1;

    // ★ r8：轨迹页顶部的"返回来源页"入口（带当前站 / 当前档 / 单日日期）
    $h = '<div class="stx-acts" style="margin:0 0 12px">' . $back . '</div>';
    // KPI 11 个（照老版：访问次数/访问页面数/会话数/首次访问/最后活跃/用户标识/地域/系统/浏览器/设备/主要来源）
    $h .= stx_kpis([
        ['访问次数(PV)', number_format($pv)],
        ['访问页面数', (string)count($paths)],
        ['会话数', (string)count($sids)],
        ['点击', number_format($cl)],
        ['事件', number_format($ev)],
        ['首次访问', e($t0 !== '' ? $t0 : '—')],
        ['最后活跃', e($t1 !== '' ? $t1 : '—')],
        ['用户标识', $isNew ? stx_tag('新访客', 'g') : stx_tag('回访者', 'o')],
        ['地域', e(statx_geo_short($region) !== '' ? statx_geo_short($region) : '—'), e($region !== '' ? $region : '')],
        ['设备 / 系统', e($dev . ' / ' . $os)],
        ['浏览器', e($bow !== '' ? $bow : '—')],
        ['主要来源', e($ref !== '' ? $ref : '直达（无来路）')],
    ]);
    $h .= stx_muted('访客标识 <code>' . e($uid) . '</code> —— 这是**客户端 localStorage 里的随机值**'
        . '（不是 IP、不是指纹，库中没有任何 IP 列），所以它能跨天把同一个人串起来。'
        . 'IP 版本分布：IPv4 ' . $v4 . ' 次 / IPv6 ' . $v6 . ' 次（IPv6 也在正常记录，老版当年这里是空桶）。');

    // 访问轨迹：按会话分组、倒序（照老版 `.live-row` 时间线）
    $groups = [];
    foreach ($rows as $r) { $groups[(string)$r['sid'] !== '' ? (string)$r['sid'] : '(无会话)'][] = $r; }
    $h .= '<div class="stx-card"><div class="stx-cardh"><h2>访问轨迹（' . e($ctx['range_label']) . '，按会话分组，倒序）</h2>'
        . '<span class="stx-note">' . count($rows) . ' 条记录 · ' . count($groups) . ' 个会话</span></div>';
    if (!$groups) {
        $h .= stx_empty('该访客在所选范围内没有记录（可能是日期范围不对，或明细已被保留清理）。');
    }
    foreach (array_reverse($groups, true) as $sid => $items) {
        $t0g = (string)$items[0]['ts']; $t1g = (string)$items[count($items) - 1]['ts'];
        $h .= '<div style="margin:10px 0 4px;font-size:12.5px;color:#666">会话 <code>'
            . e(mb_substr((string)$sid, 0, 12)) . '</code> · ' . e($t0g) . ' → ' . e($t1g)
            . ' · ' . count($items) . ' 条</div>';
        $tl = [];
        foreach (array_reverse($items) as $r) {
            $k = (string)$r['kind'];
            $badge = $k === 'pv' ? stx_tag('PV', 'g') : ($k === 'cl' ? stx_tag('点击', 'o') : stx_tag($k, ''));
            $tail = [];
            if ((string)$r['en'] !== '') { $tail[] = '事件 <b>' . e((string)$r['en']) . '</b>'; }
            if ((string)$r['ref_domain'] !== '') { $tail[] = '来自 ' . e((string)$r['ref_domain']); }
            $tl[] = ['t' => (string)$r['ts'], 'main' => $badge . ' ' . e((string)$r['path']),
                     'tail' => implode(' · ', $tail)];
        }
        $h .= stx_timeline($tl);
    }
    $h .= stx_muted('★ 老版这一页是全后台**做得最细的一页**（11 个 KPI + 按会话分组的时间线 + 访问过的页面），'
        . '本插件照它的形状做。唯一口径差别写在上面：老版依赖 IP+UA 拼人（受 NAT 影响，'
        . '而且它把 IP 明文存库了），本插件用客户端随机 uid，**库里没有 IP**。');
    $h .= '</div>';

    $h .= stx_table(stx_t(statx_c_visitor_pages($ctx), 'vpages'), $ctx);
    $h .= stx_table(stx_t(statx_c_visitor_sessions($ctx), 'sessions'), $ctx);

    // 会话分组的时间线用原始数据的 CSV（cols 由 visits 定义）
    return ['kind' => 'page', 'body' => $h];
}

function statx_c_visitor_visits_flat(array $ctx): array
{
    $uid = (string)($ctx['q']['uid'] ?? '');
    $rows = [];
    if ($uid !== '') {
        foreach (statx_dash_q('SELECT ts, sid, kind, path, en, ref_domain, device, browser, os, region'
            . ' FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND uid=? AND d>=? AND d<=? ORDER BY id DESC LIMIT 500',
            [$ctx['site'], $uid, $ctx['from'], $ctx['to']]) as $r) {
            $rows[] = ['ts' => e((string)$r['ts']), 'sid' => e((string)$r['sid']), 'kind' => e((string)$r['kind']),
                       'path' => e((string)$r['path']), 'en' => e((string)$r['en']),
                       'ref' => e((string)$r['ref_domain']), 'dev' => e((string)$r['device'])];
        }
    }
    return ['cols' => [['时间', 'ts'], ['会话', 'sid'], ['类型', 'kind'], ['页面', 'path'], ['事件', 'en'],
                       ['来源', 'ref'], ['设备', 'dev']],
            'rows' => $rows, 'bar' => '', 'title' => '访问轨迹明细'];
}
