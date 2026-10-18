<?php
declare(strict_types=1);
/**
 * StatX · 界面外壳（批次 3）——「照老版 tj 重做」
 *
 * ============================ 本文件干什么 ============================
 * 批次 2 的界面是「一个页面 + 25 个平铺区块」；站长明确要求**按老版 tj 的界面重做**：
 * **左栏分组导航树 + 右主区 topbar（H1 + 站点切换条）+ 站点页三件套（时间范围胶囊 / 日期选择器 / 站点切换）
 * + 浅底白卡 + 单一绿主色 + 高对比大数字墙 + 零 JS 纯 SSR**。
 * 本文件把这套外壳做成**可复用零件**，页面定义（pages.php / adv.php / admin.php）只声明"有哪些区块"。
 *
 * ============================ 照抄依据 ============================
 * 依据是 `tj-ui-dump/` 里老版**每页的 HTML 骨架**（不是报告的文字描述）：
 *   外壳（`dashboard.html` / `site_overview.html`）：
 *     `<div class="app"><aside class="side">…</aside><main class="main">`
 *     `<div class="topbar"><h1>页面名</h1><div class="sw"><a class="on">站点名</a>…</div></div>`
 *     侧栏：`.brand` + 站点块 `.m-site`（含 `⇄` 切站）+ 分区 `.m-g`（3px 左色条 + 16×16 内联 SVG 图标）
 *           + 菜单项 `.m-i`（7px 圆点 `.m-d`，当前项 `.on` 绿底白字）
 *     站点页三件套：`.days a`（今日/昨日/7/30/90）+ `form.daypick`（`<input type=date>` + 查看，onchange 自动提交）
 *     色板：底 `#f2f5f1` · 侧栏 `#173f33` · 主色 `#1d9e75` · 卡 `#fff`/`#e4e8e0` · 表头 `#fafcf8` · 分隔 `#eef1ea`
 *     `.tag t1/t2/t3` 三色语义 · `.lk` 查看胶囊 · `.num` 等宽右对齐 · `.muted` 口径说明 · `.kpi` 数字墙（24px）
 *   老版**53 页 `<script>` 标签数 = 0、无任何外部资源** ⇒ 本文件同样：零外链、零 JS 库、零 Canvas，
 *     图表全部**内联 SVG 手绘**（照老版 Python 拼字符串的做法）。
 *
 * ============================ 与老版**故意不同**的地方（逐条给理由） ============================
 *   ① 路由用**查询串**而不是路径段：内核把插件后台固定挂在 `/admin/?s=plug_statx`（`Plugins::adminPage()`），
 *      插件无法注册 `/admin/site/1/traffic` 这种路径 ⇒ 用 `?p=<页面>&site=<站>&r=<区间>`。
 *      **好处**：与老版一样"每个视图都能直接当链接分享"；**代价**：URL 形状不同（已在报告里列明）。
 *   ② 视觉主色沿用老版的 `#1d9e75` 绿白体系，但**外壳与内核后台共用一套**：本站后台主色是 `#b3541e`（赭色），
 *      统计页自成一套绿白体系（与老版一致），因此本文件的选择器**全部加 `stx-` 前缀**，一行都不泄漏到内核后台。
 *   ③ 老版 `.bar`（横向占比条）在**生产代码里是死代码**（`_bar_rows()` 定义了从不调用，47 页里一处都没渲染）。
 *      本文件**真用它**：所有 Top 榜都带一行占比条（`stx_bar_row()` / 表格 `bar` 列）。
 *   ④ 老版实时页用 `<meta http-equiv="refresh" content="15">` —— **照抄**（比 JS 轮询简单，且仍然零 JS）。
 *   ⑤ 老版没有 CSV 导出 / 没有退出页 / 没有点击元素榜 / 没有站内搜索词 / 没有 utm 渠道 ——
 *      这些是**批次 2 的优点，一个都不丢**（每张表右上角仍有 CSV）。
 */

/** 老版色板（逐条取自 tj-ui-dump 的内联 CSS） */
function stx_palette(): array
{
    return [
        'bg'      => '#f2f5f1',
        'side'    => '#173f33',
        'side2'   => '#1f5243',
        'side3'   => '#235545',
        'sidetx'  => '#cfe6dd',
        'sidegm'  => '#7fa999',
        'primary' => '#1d9e75',
        'primary2'=> '#0f6e56',
        'card'    => '#fff',
        'border'  => '#e4e8e0',
        'thbg'    => '#fafcf8',
        'line'    => '#eef1ea',
        'muted'   => '#888',
        't1bg'    => '#e1f5ee', 't1fg' => '#0f6e56',   // 绿 = 正常 / 搜索引擎
        't2bg'    => '#faeeda', 't2fg' => '#854f0b',   // 橙 = AI 爬虫 / 不确定
        't3bg'    => '#fcebeb', 't3fg' => '#a32d2d',   // 红 = 未知爬虫 / 风险
    ];
}

/** 分组主题色（照老版：系统/分析/来源/访问/转化/访客/监测） */
function stx_group_colors(): array
{
    return ['sys' => '#94a3b8', 'ana' => '#1d9e75', 'src' => '#3b82f6',
            'acc' => '#8b5cf6', 'cnv' => '#f59e0b', 'vis' => '#06b6d4', 'mon' => '#94a3b8'];
}

/* ============================== 内联 SVG 图标（照老版：全部内联，无字体无 CDN） ============================== */

function stx_ico(string $name): string
{
    $p = [
        'sys'   => '<circle cx="8" cy="8" r="2"/><path d="M8 1.8v2M8 12.2v2M14.2 8h-2M3.8 8h-2M12.2 3.8 10.8 5.2M5.2 10.8 3.8 12.2M12.2 12.2 10.8 10.8M5.2 5.2 3.8 3.8"/>',
        'ana'   => '<path d="M2 14h12M4 11V7.5M7.5 11V4.5M11 11V2.5"/>',
        'src'   => '<path d="M2 8 4.5 3h7L14 8v4a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 2 12Z"/><path d="M2 8h3.5l1.5 2h2l1.5-2H14"/>',
        'acc'   => '<path d="M4 1.5h6l3 3V14a1.5 1.5 0 0 1-1.5 1.5h-7A1.5 1.5 0 0 1 3 14V3a1.5 1.5 0 0 1 1-1.5Z"/><path d="M10 1.5V5h3.5M5.5 8h5M5.5 10.5h5"/>',
        'cnv'   => '<path d="M1.5 3h13L9.5 8.8V13l-3 1.5V8.8Z"/>',
        'vis'   => '<circle cx="8" cy="4.5" r="2.6"/><path d="M2.5 14c.6-3 3-4.5 5.5-4.5s4.9 1.5 5.5 4.5"/>',
        'mon'   => '<path d="M2 13h12M8 13V6M4 6h8M5.5 3h5"/>',
        'bot'   => '<rect x="3" y="5" width="10" height="7" rx="2"/><path d="M8 5V2.5M6 8.5h.01M10 8.5h.01M3 9H1.5M13 9h1.5"/>',
        'grid'  => '<rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9" y="2" width="5" height="5" rx="1"/><rect x="2" y="9" width="5" height="5" rx="1"/><rect x="9" y="9" width="5" height="5" rx="1"/>',
        'chart' => '<path d="M2 12 5.5 7l3 2.5L14 3"/><path d="M11 3h3v3"/>',
        'clock' => '<circle cx="8" cy="8" r="6"/><path d="M8 4.5V8l2.5 1.5"/>',
        'edit'  => '<path d="M11.5 2.5 13.5 4.5 5.5 12.5H3.5v-2z"/><path d="M2 14h12"/>',
        'shield'=> '<path d="M8 1.5 13 3v5c0 3-2.2 5.3-5 6.5C5.2 13.3 3 11 3 8V3z"/><path d="M6 8l1.5 1.5L10.5 6.5"/>',
        'warn'  => '<path d="M8 2 14.5 13.5h-13z"/><path d="M8 6.5v3.5M8 12h.01"/>',
        'keep'  => '<ellipse cx="8" cy="4" rx="5.5" ry="2.2"/><path d="M2.5 4v8c0 1.2 2.5 2.2 5.5 2.2s5.5-1 5.5-2.2V4"/><path d="M2.5 8c0 1.2 2.5 2.2 5.5 2.2S13.5 9.2 13.5 8"/>',
        'link'  => '<path d="M6.5 9.5 9.5 6.5"/><path d="M7 4.5 8.5 3a3 3 0 1 1 4.5 4.5L11.5 9"/><path d="M9 11.5 7.5 13A3 3 0 1 1 3 8.5L4.5 7"/>',
    ];
    $d = $p[$name] ?? $p['sys'];
    return '<svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.5"'
        . ' style="vertical-align:-2px" aria-hidden="true">' . $d . '</svg>';
}

/* ============================== 组件（返回 HTML 片段） ============================== */

/** 数字墙（照老版 `.kpis` / `.kpi`：白卡 + 24px 主色等宽数字 + 12px 灰标签） */
function stx_kpis(array $items): string
{
    if (!$items) { return ''; }
    $h = '<div class="stx-kpis">';
    foreach ($items as $it) {
        $lab = (string)($it[0] ?? '');
        $val = (string)($it[1] ?? '');
        $sub = (string)($it[2] ?? '');
        $tone = (string)($it[3] ?? '');
        $cls = 'stx-kpi' . ($tone !== '' ? ' stx-kpi-' . $tone : '');
        $h .= '<div class="' . $cls . '"><b>' . $val . '</b><span>' . e($lab) . '</span>'
            . ($sub !== '' ? '<i>' . $sub . '</i>' : '') . '</div>';
    }
    return $h . '</div>';
}

/** 三色语义 tag（照老版 `.tag t1/t2/t3`）：green=正常/搜索引擎 · orange=AI 爬虫 · red=未知/风险 */
function stx_tag(string $text, string $tone = '', string $title = ''): string
{
    $cls = 'stx-tag';
    if ($tone === 'g' || $tone === 't1') { $cls .= ' stx-t1'; }
    elseif ($tone === 'o' || $tone === 't2') { $cls .= ' stx-t2'; }
    elseif ($tone === 'r' || $tone === 't3') { $cls .= ' stx-t3'; }
    return '<span class="' . $cls . '"' . ($title !== '' ? ' title="' . e($title) . '"' : '') . '>' . e($text) . '</span>';
}

/**
 * 「查看」下钻胶囊（照老版 `.lk`：浅绿底 + 深绿字，hover 变实心）
 *
 * ★★ r8：加了第 3 个参数 `$ctx` —— **下钻链接必须带上当前站点与时间上下文**。
 *   老版 tj 的下钻（用户列表 → 访客轨迹）是**保留**当前站/当前档的；本插件原来只传
 *   `['p' => 'visitor', 'uid' => …]` ⇒ 生成的 href 连 `site` 与 `r` 都没有（实测），
 *   点进去看到的是"默认站 + 默认档 + 那个 uid"（访客在别的站当然查不到 ⇒ 显示无数据）。
 *   `$ctx` 可选：不传时行为与从前**逐字节相同**（向后兼容既有调用方）。
 */
function stx_lk(string $text, array $q, array $ctx = []): string
{
    return '<a class="stx-lk" href="' . e(statx_ui_url($ctx, $q)) . '">' . e($text) . '</a>';
}

/** 横向占比条（★ 老版定义了 `.bar` 却从不调用 = 死代码；本插件**真用**） */
function stx_bar(int $v, int $max, string $tone = ''): string
{
    $pct = $max > 0 ? max(1, (int)round($v / $max * 100)) : 0;
    return '<span class="stx-bar"><i style="width:' . $pct . '%' . ($tone !== '' ? ';background:' . $tone : '') . '"></i></span>';
}

/** 口径说明（照老版 `.muted`：老版每个数字旁边都写了口径，这条务必保留） */
function stx_muted(string $html): string
{
    return '<p class="stx-muted">' . $html . '</p>';
}

/** 时间线（照老版 `.live-row`：86px 灰时间 + 内容，换行 flex）—— 访客轨迹用 */
function stx_timeline(array $rows): string
{
    if (!$rows) { return '<div class="stx-empty">该访客在所选范围内没有浏览记录。</div>'; }
    $h = '<div class="stx-timeline">';
    foreach ($rows as $r) {
        $h .= '<div class="stx-live-row">'
            . '<span class="stx-t">' . e((string)($r['t'] ?? '')) . '</span>'
            . '<span class="stx-lr-main">' . (string)($r['main'] ?? '') . '</span>'
            . (!empty($r['tail']) ? '<span class="stx-lr-tail">' . (string)$r['tail'] . '</span>' : '')
            . '</div>';
    }
    return $h . '</div>';
}

/** 时间范围胶囊（照老版 `.days`：今日 / 昨日 / 7 天 / 30 天 / 90 天） */
function stx_days(array $ctx): string
{
    $items = ['today' => '今日', 'yesterday' => '昨日', '7d' => '7 天', '30d' => '30 天', '90d' => '90 天'];
    $h = '<div class="stx-days">';
    foreach ($items as $k => $lab) {
        $on = ($ctx['r'] === $k);
        $h .= '<a class="' . ($on ? 'on' : '') . '" href="' . e(statx_ui_url($ctx, ['r' => $k])) . '">' . $lab . '</a>';
    }
    return $h . '</div>';
}

/**
 * 三件套②：日期选择器（照老版 `form.daypick`）—— GET 表单 + `onchange="this.form.submit()"`
 * ★ 这是**全站唯一的内联 JS**（与老版完全一致：老版全站只有这一处内联事件 + 仪表盘复制代码那处）。
 * ★ `min`/`max` 按"真正有数据的范围"给（超范围会在下方给出提示，不静默返回空表）。
 */
function stx_daypick(array $ctx, array $bounds): string
{
    $min = (string)($bounds['ui_min'] ?? date('Y-m-d', strtotime('-89 day')));
    $max = (string)($bounds['ui_max'] ?? date('Y-m-d'));
    $val = ($ctx['single'] !== '') ? $ctx['single'] : '';
    // ★★ r8：原来只带 `p`/`site` —— 在「近 7 天」页上选一个日期提交后，URL 变成 `?p=…&site=…&d=…`，
    //   **`r` 丢了**（`statx_ctx()` 见到 `d` 才补回 `r=day`，所以看着能用）；但在「昨日」页上提交
    //   会得到 `?p=…&site=…&d=…`，一旦 `d` 被浏览器清空就退化成"默认档"。显式带上 `r=day` 才是
    //   与"点这一天的数据"一致的语义（也让链接可分享、可复现）。`d` 为空时不带（否则等于选了空日期）。
    return '<form class="stx-daypick" method="get" action="/admin/">'
        . '<input type="hidden" name="s" value="plug_statx">'
        . '<input type="hidden" name="p" value="' . e($ctx['p']) . '">'
        . '<input type="hidden" name="r" value="day">'
        . ($val !== '' ? '<input type="hidden" name="d" value="' . e($val) . '">' : '')
        . ($ctx['site'] !== '' ? '<input type="hidden" name="site" value="' . e($ctx['site']) . '">' : '')
        . '<label>选择具体日期</label>'
        . '<input type="date" name="d" min="' . e($min) . '" max="' . e($max) . '" value="' . e($val) . '"'
        . ' onchange="this.form.submit()">'
        . '<button type="submit">查看</button>'
        . '<span class="stx-muted-inline">可选 ' . e($min) . ' ~ ' . e($max) . '</span>'
        . '</form>';
}

/** 超 30 天提示（照老版原文口径改写） */
function stx_range_notice(array $ctx, array $bounds): string
{
    $out = '';
    if (!empty($ctx['notice'])) { $out .= '<div class="stx-note-warn">' . $ctx['notice'] . '</div>'; }
    if ($ctx['r'] === '90d' || ($ctx['single'] !== '' && $ctx['single'] < $bounds['detail_min'])) {
        $out .= '<p class="stx-muted">超过 <b>' . (int)$bounds['keep_days'] . ' 天</b>的原始访问明细已自动按天汇总并清除'
            . '（保留清理：每日把汇总写进 `hits_rollup` 后删掉原始行）⇒ <b>逐条访客轨迹、用户列表、点击热图等明细'
            . '仅可查看近 ' . (int)$bounds['keep_days'] . ' 天</b>；区间类聚合（PV/UV/来源/页面）在更长的范围内仍然可用。'
            . '本插件有数据的最早一天是 <b>' . e((string)$bounds['data_min']) . '</b>。</p>';
    }
    return $out;
}

/**
 * 站点**短显示名**（r7 新增）：把 label 里给运维看的括号说明去掉，只留"人话短名"。
 *
 * 背景（站长 2026-09-15 反馈）：老站 tj 的站点切换条用的是**短名**（"古书屋 / 学茶网 / 老吕"），
 * 而我们早期把 label 写成了带路径的说明（"老像素站（静态 HTML，.106:/www/wwwroot/liulanqi.com）"），
 * 结果胶囊/卡片/侧栏全被撑长。**运维信息不该占据显示位** —— 它们仍然在 label 里、也仍然
 * 出现在 `title`（鼠标悬停）里，只是不再当显示名用。
 *
 * 规则（保守、可预期，不做任何"猜测式"改写）：
 *   ① 从第一个左括号（`（` 或 `(`）截断 ⇒ "老像素站（静态 HTML，.106:/www/wwwroot/liulanqi.com）" → "老像素站"；
 *   ② 截完为空（例如 label 本身就是"（无）"）⇒ 退回整条原 label；
 *   ③ 始终 trim；不改字、不翻译、不加域名后缀。
 *
 * ★ 为什么**不再**顺带按 ` · ` / ` / ` 截断（r7 初稿试过，已撤）：
 *   实测把 "老像素站（Node/Express，.106:/www/wwwroot/teask）" 里的 "Node/Express"
 *   当成了分隔符，结果 9 个老像素站被截成同一个"老像素站" —— 胶囊全一样，反而更分不清。
 *   括号规则已经足够（现网 19 条 label 里 8 条带括号说明），且**只按明确的分隔符切**，不改写其余内容。
 *   `label` 里若出现"主名 · 副题"，副题会保留 —— 那属于站名本身，不该被我们当成说明砍掉。
 *
 * @param string $label sites.label 原值
 * @param string $fallback 兜底（通常是 site_key）
 */
function stx_site_short_name(string $label, string $fallback = ''): string
{
    $s = trim($label);
    if ($s === '') { return $fallback; }
    $cut = preg_split('~[（(]~u', $s, 2);
    if (is_array($cut) && isset($cut[0]) && trim($cut[0]) !== '') { $s = trim($cut[0]); }
    $s = trim($s);
    return $s !== '' ? $s : trim($label);
}

/**
 * 该页是否"一屏看全部站点"（系统级页面：仪表盘 / 设置 / 数据保留 / 蜘蛛汇总 / 采集预警 / 统计检测）。
 * 实时访客页不算 —— 它是**单站**的实时流。
 */
function stx_page_is_all_sites(array $ctx): bool
{
    return !stx_page_is_site($ctx) && (string)($ctx['p'] ?? '') !== 'live';
}

/** 站点切换条（照老版 `.sw`：一排胶囊，当前站绿底白字，**切换时保留当前时间范围/页面**） */
function stx_site_switcher(array $ctx, array $sites): string
{
    if (!$sites) { return ''; }
    $all = stx_page_is_all_sites($ctx);
    $h = '<div class="stx-sw">';
    $extra = ($ctx['r'] === 'day' && $ctx['single'] !== '') ? ['r' => 'day', 'd' => $ctx['single']] : [];
    // ★★ 2026-09-15 新增第一个胶囊「全部站点（汇总）」：
    //   · 它是**统计服务器的默认口径** —— 一屏 19 个站，6 个总数字 = 下面各站数字之和；
    //   · URL = `?p=dash`（**不带 site**）⇒ 复制出去就是同一个视图（照插件既有习惯，零 JS）；
    //   · 任何页面上都能一键回到汇总（站长要求：能一眼看到在看哪个站、能切站、能回汇总）。
    $h .= '<a class="' . (($all || $ctx['site'] === '') ? 'on' : '') . '" href="'
        . e(statx_ui_url($ctx, ['p' => 'dash', 'site' => ''])) . '"'
        . ' title="全部站点汇总（一个站都不筛）">全部站点</a>';
    foreach ($sites as $s) {
        $k = (string)$s['site_key'];
        $labFull = trim((string)$s['label']) !== '' ? (string)$s['label'] : $k;
        // ★ r7：显示名用**短名**（括号说明留给 title / 站点管理页），避免 19 个胶囊一行放不下
        $lab = stx_site_short_name($labFull, $k);
        // 系统级页面本身不按站点筛 ⇒ 那里不该把某个站标成"当前"，而且点它要进**那个站自己的看板**
        $on = (!$all && $ctx['site'] === $k);
        // ★ r8：`$extra` 只在**单日页**上非空 —— 而切站是"换一个站看同一个档"，
        //   单日页切站后 `r=day&d=…` 会把 d 原样带过去（站点换了、日期不变，符合"同一个档"）。
        // ★ r8 实测修：`$all` 分支原来是 `p=overview`，`r` 由 URL 构造从 $ctx 继承 ⇒ 正确；
        //   但**非 $all 分支**（系统页上点"某个站"的胶囊）原来只给 site，`r` 被
        //   `statx_ui_url()` 的 `['p' => 'overview', 'r' => '7d']` 调用方硬编码成了 7d
        //   ⇒ 在仪表盘（昨日/单日）上点胶囊会**把当前档位换成"近 7 天"**（实测 4 处）。
        //   这里显式继承当前档位（单日时 $extra 里已经带了 d），站点卡片那一处的 7d 保持不动。
        $q  = ['r' => (string)$ctx['r']] + ($all ? ['p' => 'overview'] : []) + $extra + ['site' => $k];
        // ★ 2026-09-15 顺手修正提示语：这里的数字是**当前区间内该站的记录条数**（`statx_ui_sites_ordered`
        //   写进 `pv` 的是 `c_in_range`），老文案写"今日 … PV"会把 7 天的数字说成今天的。
        // ★ r7：title 里同时给**短名 + 完整 label + site_key**（悬停可看全名，显示位只用短名）
        $tip = $lab;
        if ($labFull !== $lab) { $tip .= ' · ' . $labFull; }
        $tip .= ' · ' . $k . ' · ' . (string)$ctx['range_label'] . ' ' . (int)$s['pv'] . ' 条记录';
        $h .= '<a class="' . ($on ? 'on' : '') . '" href="' . e(statx_ui_url($ctx, $q)) . '"'
            . ' title="' . e($tip) . '">'
            . e($lab) . '</a>';
    }
    return $h . '</div>';
}

/* ============================== 导航树（照老版：7 分组 + 分组色条 + 彩色圆点 + 当前项反白） ============================== */

function stx_nav(): array
{
    return [
        ['sys', '系统', 'sys', [
            ['dash',      '仪表盘',      'grid'],
            ['bots_all',  '蜘蛛与 AI',   'bot'],
            ['alerts_all','采集预警',    'warn'],
            ['check',     '统计检测',    'shield'],
            ['sites_mgr', '站点管理',    'grid'],
            ['account',   '账号',        'vis'],
            ['settings',  '设置',        'edit'],
        ]],
        ['ana', '分析', 'ana', [
            ['overview', '网站概况',   'chart'],
            ['traffic',  '流量分析',   'ana'],
            ['live',     '实时访客',   'clock'],
            ['trend',    '趋势分析',   'chart'],
            ['day',      '单日详情',   'ana'],
        ]],
        ['src', '来源分析', 'src', [
            ['source',      '全部来源', 'src'],
            ['src_search',  '搜索引擎', 'src'],
            ['src_keyword', '关键词',   'src'],
            ['src_ext',     '外部链接', 'link'],
            ['src_int',     '站内来源', 'link'],
        ]],
        ['acc', '访问分析', 'acc', [
            ['pages',    '受访页面',   'acc'],
            ['entry',    '入口页面',   'acc'],
            ['exit',     '退出页面',   'acc'],
            ['domain',   '受访域名',   'acc'],
            ['paths',    '路径分析',   'acc'],
            ['depth',    '访问深度',   'acc'],
            ['users',    '用户列表',   'vis'],
            ['clickmap', '页面点击图', 'grid'],
            ['flow',     '页面上下游', 'link'],
            ['events',   '事件分析',   'chart'],
            ['insights', '分析结论',   'edit'],
        ]],
        ['cnv', '转化分析', 'cnv', [
            ['conversion', '转化概况', 'cnv'],
            ['cpath',      '转化路径', 'cnv'],
        ]],
        ['vis', '访客分析', 'vis', [
            ['region', '地域分布',   'vis'],
            ['env',    '系统环境',   'vis'],
            ['newold', '新老访客',   'vis'],
        ]],
        ['mon', '监测', 'mon', [
            ['bots',      '蜘蛛与 AI', 'bot'],
            ['alerts',    '采集预警',  'warn'],
            ['collect',   '统计检测',  'shield'],
            ['health',    '站点健康',  'shield'],
            ['retention', '数据保留',  'keep'],
        ]],
    ];
}

/** 页面 → 标题（H1 与导航高亮共用） */
function stx_titles(): array
{
    return [
        'dash' => '仪表盘', 'bots_all' => '蜘蛛与 AI', 'alerts_all' => '采集预警',
        'check' => '统计检测', 'settings' => '设置', 'overview' => '网站概况',
        // ★ 2026-09-15 新增两页（站点管理 / 账号）—— statx_ctx() 靠这张表认页面，漏登记会被打回默认页
        'sites_mgr' => '站点管理', 'account' => '账号',
        'traffic' => '流量分析', 'live' => '实时访客', 'trend' => '趋势分析', 'day' => '单日详情',
        'source' => '全部来源', 'src_search' => '搜索引擎', 'src_keyword' => '关键词',
        'src_ext' => '外部链接', 'src_int' => '站内来源',
        'pages' => '受访页面', 'entry' => '入口页面', 'exit' => '退出页面', 'domain' => '受访域名',
        'paths' => '路径分析', 'depth' => '访问深度', 'users' => '用户列表',
        'clickmap' => '页面点击图', 'flow' => '页面上下游', 'events' => '事件分析', 'insights' => '分析结论',
        'conversion' => '转化概况', 'cpath' => '转化路径',
        'region' => '地域分布', 'env' => '系统环境', 'newold' => '新老访客',
        'bots' => '蜘蛛与 AI', 'alerts' => '采集预警', 'collect' => '统计检测',
        'health' => '站点健康', 'retention' => '数据保留', 'visitor' => '访客轨迹',
        // ★ 以下两个**不在左栏导航里**（老版也没有"路由清单"这种页），只作为诊断/下钻入口：
        'admin' => '诊断 · 目标管理与事件流水',   // 无导航项
        'sites_grid' => '站点卡片网格',            // 由仪表盘复用
    ];
}

/** 该页是否显示三件套（实时页只有站点切换；系统级页面全都没有） */
function stx_page_is_site(array $ctx): bool
{
    if (in_array($ctx['p'], ['dash', 'bots_all', 'alerts_all', 'check', 'settings', 'retention',
                             'sites_mgr', 'account'], true)) { return false; }
    if ($ctx['p'] === 'live') { return false; }   // 老版实时页：只有站切，无范围/日期
    return true;
}

/** 侧栏（照老版结构：brand + 站点块 + 7 分组 + 菜单项） */
function stx_sidebar(array $ctx, array $sites): string
{
    $gc = stx_group_colors();
    $titles = stx_titles();
    $cur = $ctx['p'];
    $h = '<aside class="stx-side">';
    $h .= '<a class="stx-brand" href="' . e(statx_ui_url($ctx, ['p' => 'dash'])) . '">statx<small> 网站分析</small></a>';

    if (stx_page_is_site($ctx)) {
        // ★ r7：侧栏站点块用**短名**（CSS 已有 ellipsis 兜底），完整 label 放 title
        $slFull = $ctx['site_label'] !== '' ? (string)$ctx['site_label'] : (string)$ctx['site'];
        $sl = $ctx['site'] !== '' ? stx_site_short_name($slFull, (string)$ctx['site']) : ($slFull !== '' ? $slFull : '（无数据）');
        $h .= '<div class="stx-msite"><span class="n" title="' . e($slFull . ' · ' . $ctx['site']) . '">' . e($sl) . '</span>'
            . '<a href="' . e(statx_ui_url($ctx, ['p' => 'dash', 'site' => ''])) . '" title="切换站点">⇄</a></div>';
    }
    /* ── t18 站点后台入口 BEGIN（2026-10-16）──────────────────────────────────
       站长反馈：「后台只有统计一个菜单，找不到填 DeepSeek Key 的 AI 设置」。
       根因：本插件 boot.php 把 GET /admin/ 302 到 ?s=plug_statx，并对 plug_statx* 设
       $GLOBALS['YC_ADMIN_FOCUS'] ⇒ 内核**不渲染它自己的 <aside>** ⇒ 统计后台里看不见内核菜单。
       此处只补一组**直链**（纯 <a>，零 SQL、零 JS、内核一个字节都没改）。
       回滚：删掉本 BEGIN…END 整段，或跑 /root/ta18/patches/t18_rollback.sh。 */
    $h .= '<div class="stx-mg" style="border-left:3px solid #c2703a;padding-left:7px;color:#9fc9bb">'
        . stx_ico('edit') . ' 站点后台</div>';
    foreach ([
        ['/admin/?s=poly#ai-settings', 'AI 设置（DeepSeek Key）'],
        ['/admin/?s=settings',         '站点设置'],
        ['/admin/?s=plugins',          '插件与全部菜单'],
    ] as $lq) {
        $h .= '<a class="stx-mi" href="' . e($lq[0]) . '"><span class="stx-md" style="background:#c2703a"></span>'
            . e($lq[1]) . '</a>';
    }
    /* ── t18 站点后台入口 END ─────────────────────────────────────────────── */
    // 系统组（老版排在最前，无站点块）
    foreach (stx_nav() as [$gid, $glab, $gico, $items]) {
        $col = $gc[$gid] ?? '#94a3b8';
        $h .= '<div class="stx-mg" style="border-left:3px solid ' . $col . ';padding-left:7px;color:#9fc9bb">'
            . stx_ico($gico) . ' ' . e($glab) . '</div>';
        foreach ($items as [$pid, $plab, $pico]) {
            if ($gid === 'sys') {
                $q = ['p' => $pid];
            } else {
                // ★ 日期继承（老版由中间件实现）：单日模式下点任何菜单都把**那一天**带过去；
                //   非单日模式带的是 r（今日 / 昨日 / 7 / 30 / 90）。
                $q = ['p' => $pid, 'r' => $ctx['r']];
                if ($ctx['r'] === 'day' && $ctx['single'] !== '') { $q['d'] = $ctx['single']; }
                if ($ctx['site'] !== '') { $q['site'] = $ctx['site']; }
            }
            $on = ($cur === $pid);
            // 高亮归并：overview/traffic/trend/day 各自独立；bots/alerts/check 在两组里都出现，按组内高亮
            if ($on) { $on = true; }
            $h .= '<a class="stx-mi' . ($on ? ' on' : '') . '" href="' . e(statx_ui_url($ctx, $q)) . '">'
                . '<span class="stx-md" style="background:' . $col . '"></span>' . e($plab) . '</a>';
        }
    }
    $h .= '</aside>';
    return $h;
}

/** topbar（照老版：左 H1，右站点切换条） */
function stx_topbar(array $ctx, array $sites): string
{
    $t = stx_titles()[$ctx['p']] ?? '统计';
    $h1 = $t;
    if ($ctx['p'] === 'day' && $ctx['single'] !== '') { $h1 = '单日详情 ' . $ctx['single']; }
    if (stx_page_is_site($ctx) && $ctx['site'] !== '') {
        // ★ r7：H1 也用短名（整页标题不该被"（静态 HTML，.106:/www/wwwroot/…）"撑长）
        $sf = $ctx['site_label'] !== '' ? (string)$ctx['site_label'] : (string)$ctx['site'];
        $sn = stx_site_short_name($sf, (string)$ctx['site']);
        $h1 = ($ctx['p'] === 'overview') ? $sn : $h1 . ' · ' . $sn;
    }
    // ★ r7：H1 上保留完整 label 作 title（悬停看全名）
    $tit = stx_page_is_site($ctx) && $ctx['site_label'] !== '' ? ' title="' . e((string)$ctx['site_label']) . '"' : '';
    $h = '<div class="stx-topbar"><h1' . $tit . '>' . e($h1) . '</h1>';
    if ($ctx['p'] !== 'live') { $h .= stx_site_switcher($ctx, $sites); }
    return $h . '</div>';
}

/* ============================== 表格 / 图表零件 ============================== */

function stx_error(string $text): string
{
    return '<div class="stx-errbox">' . e($text) . '</div>';
}

function stx_empty(string $text): string
{
    return '<div class="stx-empty">' . e($text) . '</div>';
}

function stx_tbl_note(string $html): string
{
    return '<p class="stx-muted stx-block">' . $html . '</p>';
}

/** 通用表格（CSV 链接由同一组 cols 生成 ⇒ 页面与导出**永不口径分叉**） */
function stx_table(array $t, array $ctx): string
{
    $title = (string)($t['title'] ?? '');
    $cols  = $t['cols'] ?? [];
    $rows  = $t['rows'] ?? [];
    $right = (string)($t['right'] ?? '');
    $note  = (string)($t['note'] ?? '');
    $csvId = (string)($t['csv'] ?? '');

    $h = '<div class="stx-card">';
    if ($title !== '' || $csvId !== '') {
        $h .= '<div class="stx-cardh"><h2>' . e($title) . '</h2>';
        if ($right !== '') { $h .= '<span class="stx-note">' . $right . '</span>'; }
        if ($csvId !== '') {
            $h .= '<a class="stx-csv" href="' . e(statx_ui_url($ctx, ['csv' => $csvId])) . '">CSV</a>';
        }
        $h .= '</div>';
    }
    if (!$rows) {
        $h .= '<div class="stx-empty">' . e((string)($t['empty'] ?? ('暂无数据（时间范围：' . $ctx['range_label'] . '）'))) . '</div>';
        if ($note !== '') { $h .= stx_tbl_note($note); }
        return $h . '</div>';
    }
    $barKey = (string)($t['bar'] ?? '');
    $maxBar = 0;
    if ($barKey !== '') { foreach ($rows as $r) { $maxBar = max($maxBar, (int)($r[$barKey] ?? 0)); } }

    $h .= '<div class="stx-scroll"><table class="stx-tb"><thead><tr>';
    foreach ($cols as $c) {
        $h .= '<th' . ((($c[2] ?? '') === 'r') ? ' class="num"' : '') . '>' . e((string)$c[0]) . '</th>';
    }
    if ($barKey !== '') { $h .= '<th class="num" style="width:110px">占比</th>'; }
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr>';
        foreach ($cols as $c) {
            $k = (string)$c[1];
            $v = (string)($r[$k] ?? '');
            $ttl = (string)($r['_t_' . $k] ?? '');
            $h .= '<td' . ((($c[2] ?? '') === 'r') ? ' class="num"' : '')
                . ($ttl !== '' ? ' title="' . e($ttl) . '"' : '') . '>' . $v . '</td>';
        }
        if ($barKey !== '') { $h .= '<td class="num">' . stx_bar((int)($r[$barKey] ?? 0), $maxBar) . '</td>'; }
        $h .= '</tr>';
    }
    $h .= '</tbody></table></div>';
    if ($note !== '') { $h .= stx_tbl_note($note); }
    return $h . '</div>';
}

/** 并排两栏（照老版：卡片纵向流式，只在"两张小表"时并排） */
function stx_pair(array $blocks): string
{
    $b = array_values(array_filter($blocks, static fn($x) => $x !== ''));
    if (!$b) { return ''; }
    if (count($b) === 1) { return $b[0]; }
    $h = '<div class="stx-two">';
    foreach ($b as $x) { $h .= '<div>' . $x . '</div>'; }
    return $h . '</div>';
}

/** 折线图（内联 SVG，照老版 `_svg_line`：PV 绿 / UV 橙，5 档网格，首尾 X 轴标签） */
function stx_svg_line(array $rows, array $series, int $w = 760, int $h = 210): string
{
    $n = count($rows);
    if ($n < 2) { return '<div class="stx-empty">数据不足，画不出趋势（至少 2 个点）——换个更大的时间范围试试。</div>'; }
    $max = 1;
    foreach ($rows as $r) { foreach ($series as $s) { $max = max($max, (int)($r[$s['key']] ?? 0)); } }
    $padL = 8; $padR = 8; $padT = 10; $padB = 22;
    $iw = $w - $padL - $padR; $ih = $h - $padT - $padB;
    $dx = $iw / max(1, $n - 1);
    $y = static fn(int $v): float => round($padT + $ih - ($v / max(1, $max)) * $ih, 1);

    $g = '<svg class="stx-svg" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="趋势折线">';
    for ($i = 0; $i <= 4; $i++) {
        $yy = round($padT + $i * ($ih / 4), 1);
        $g .= '<line x1="' . $padL . '" y1="' . $yy . '" x2="' . ($w - $padR) . '" y2="' . $yy . '" stroke="#eef1ea" stroke-width="1"/>';
        $g .= '<text x="2" y="' . ($yy + 3) . '" font-size="9" fill="#999">' . (int)round($max * (1 - $i / 4)) . '</text>';
    }
    foreach ($series as $s) {
        $pts = [];
        foreach ($rows as $i => $r) { $pts[] = round($padL + $i * $dx, 1) . ',' . $y((int)($r[$s['key']] ?? 0)); }
        if (!empty($s['area'])) {
            $g .= '<polygon points="' . $padL . ',' . ($padT + $ih) . ' ' . implode(' ', $pts) . ' '
                . round($padL + ($n - 1) * $dx, 1) . ',' . ($padT + $ih) . '" fill="' . $s['color'] . '" opacity="0.10"/>';
        }
        $g .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $s['color'] . '" stroke-width="1.8"'
            . (!empty($s['dash']) ? ' stroke-dasharray="4,3"' : '') . '/>';
    }
    // 首 / 中 / 末 三个 X 轴刻度（照老版 12 个日期标签的观感，这里按宽度给 3 个以免重叠）
    foreach ([0, (int)floor(($n - 1) / 2), $n - 1] as $idx) {
        $anchor = $idx === 0 ? 'start' : ($idx === $n - 1 ? 'end' : 'middle');
        $g .= '<text x="' . round($padL + $idx * $dx, 1) . '" y="' . ($h - 6) . '" font-size="10" fill="#999" text-anchor="'
            . $anchor . '">' . e((string)$rows[$idx]['b']) . '</text>';
    }
    $g .= '</svg>';
    $lg = '<div class="stx-legend">';
    foreach ($series as $s) {
        $lg .= '<span><i style="background:' . $s['color'] . '"></i>' . e((string)$s['label']) . '</span>';
    }
    $lg .= '<span class="stx-muted-inline">峰值 ' . $max . '</span></div>';
    return $g . $lg;
}

/** 柱状图（内联 SVG，照老版 `_svg_bars`：24 根柱 + `<title>` 悬停提示） */
function stx_svg_bars(array $rows, string $color = '#1d9e75', int $w = 760, int $h = 170, string $unit = ''): string
{
    $n = count($rows);
    if ($n === 0) { return '<div class="stx-empty">暂无数据。</div>'; }
    $max = 1;
    foreach ($rows as $r) { $max = max($max, (int)$r['v']); }
    $padL = 8; $padR = 8; $padT = 8; $padB = 20;
    $iw = $w - $padL - $padR; $ih = $h - $padT - $padB;
    $step = $iw / max(1, $n);
    $bw = max(2, (int)floor($step) - ($n > 40 ? 0 : 3));
    $g = '<svg class="stx-svg" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="柱状图">';
    for ($i = 0; $i <= 4; $i++) {
        $yy = round($padT + $i * ($ih / 4), 1);
        $g .= '<line x1="' . $padL . '" y1="' . $yy . '" x2="' . ($w - $padR) . '" y2="' . $yy . '" stroke="#eef1ea" stroke-width="1"/>';
        $g .= '<text x="2" y="' . ($yy + 3) . '" font-size="9" fill="#999">' . (int)round($max * (1 - $i / 4)) . '</text>';
    }
    foreach ($rows as $i => $r) {
        $v = (int)$r['v'];
        $hh = (int)round($v / $max * $ih);
        $x = round($padL + $i * $step + ($step - $bw) / 2, 1);
        $g .= '<rect x="' . $x . '" y="' . ($padT + $ih - $hh) . '" width="' . $bw . '" height="' . max(1, $hh)
            . '" rx="1" fill="' . $color . '"><title>' . e((string)$r['l'] . ': ' . $v . $unit) . '</title></rect>';
    }
    foreach ([0, (int)floor(($n - 1) / 2), $n - 1] as $idx) {
        $anchor = $idx === 0 ? 'start' : ($idx === $n - 1 ? 'end' : 'middle');
        $g .= '<text x="' . round($padL + $idx * $step + $step / 2, 1) . '" y="' . ($h - 6) . '" font-size="10" fill="#999" text-anchor="'
            . $anchor . '">' . e((string)$rows[$idx]['l']) . '</text>';
    }
    return $g . '</svg>';
}

/**
 * 点击热力网格（照老版 5 档绿色，但**网格是 24×14 且按各自 viewport 归一化** —— 这是新版优点，不退回老版 16×9）
 * 老版按 `screen` 尺寸归一化，窗口一窄整张图横向失真；本插件用点击时的 `clientX/innerWidth`。
 */
function stx_heat_grid(array $rows, int $w = 24, int $h = 14): string
{
    $m = []; $max = 0;
    foreach ($rows as $r) { $m[(int)$r['cy']][(int)$r['cx']] = (int)$r['c']; $max = max($max, (int)$r['c']); }
    $level = static function (int $v, int $max): int {
        if ($v <= 0) { return 0; }
        if ($max <= 1) { return 4; }
        $r = $v / $max;
        return $r >= 0.75 ? 4 : ($r >= 0.5 ? 3 : ($r >= 0.25 ? 2 : 1));
    };
    $cls = [0 => '', 1 => 'h1', 2 => 'h2', 3 => 'h3', 4 => 'h4'];
    $t = '<div class="stx-cmap"><table class="stx-cm">';
    for ($yy = 0; $yy < $h; $yy++) {
        $t .= '<tr><th>' . round($yy / $h * 100) . '%</th>';
        for ($xx = 0; $xx < $w; $xx++) {
            $v = $m[$yy][$xx] ?? 0;
            $t .= '<td class="' . $cls[$level($v, $max)] . '" title="列 ' . round($xx / $w * 100) . '% · 行 '
                . round($yy / $h * 100) . '% · ' . $v . ' 次">' . ($v > 0 ? $v : '') . '</td>';
        }
        $t .= '</tr>';
    }
    $t .= '<tr><th></th>';
    for ($xx = 0; $xx < $w; $xx++) { $t .= '<th>' . round($xx / $w * 100) . '</th>'; }
    $t .= '</tr></table></div>';
    $cap = '<div class="stx-cmap-cap"><span>无</span><i></i><span>1</span><i class="h1"></i><span>2</span><i class="h2"></i>'
        . '<span>3</span><i class="h3"></i><span>4+</span><i class="h4"></i>'
        . '<span class="stx-muted-inline">（颜色越深点击越多）</span></div>';
    return $t . $cap;
}

/** 分页器（照老版 `.pager`：胶囊 + 当前项绿底白字 + 「（不筛选）」） */
function stx_pager(array $ctx, string $key, array $items, string $allLabel = '（不筛选）'): string
{
    $cur = (string)($ctx['q'][$key] ?? '');
    $h = '<div class="stx-pager">';
    // ★ r8：`$key`（url/h/kw…）是**页面级**参数 —— 只改它，绝不把当前站/档丢掉
    $h .= '<a class="' . ($cur === '' ? 'on' : '') . '" href="' . e(statx_ui_url($ctx, [$key => ''])) . '">' . e($allLabel) . '</a>';
    foreach ($items as $k => $lab) {
        $h .= '<a class="' . ($cur === (string)$k ? 'on' : '') . '" href="' . e(statx_ui_url($ctx, [$key => (string)$k])) . '">'
            . e((string)$lab) . '</a>';
    }
    return $h . '</div>';
}

/** 胶囊条（范围 / 站点 / 类型 通用） */
function stx_pills(array $items, string $cur): string
{
    $h = '<div class="stx-days">';
    foreach ($items as $k => $o) {
        $h .= '<a class="' . ($cur === (string)$k ? 'on' : '') . '" href="' . e((string)$o['href']) . '"'
            . (!empty($o['title']) ? ' title="' . e((string)$o['title']) . '"' : '') . '>' . e((string)$o['label']) . '</a>';
    }
    return $h . '</div>';
}
