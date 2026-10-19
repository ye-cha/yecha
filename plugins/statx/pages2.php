<?php
declare(strict_types=1);
/**
 * StatX · 站点页（批次 3）——「照老版 tj 的逐页结构」
 *
 * 本文件承载老版站点页那一整套：概况 / 流量分析 / 实时访客 / 趋势分析 / 单日详情 /
 * 来源分析（5 页）/ 访问分析（11 页）/ 转化分析（2 页）/ 访客分析（3 页）/ 监测（2 页）。
 *
 * ★ 每一页都按老版骨架的"数据元素逐个"来排（顺序也照老版），差异只在**口径更好**的地方：
 *   · 热图按各自 viewport 归一化（24×14），不退回老版 screen 16×9；
 *   · 地域读写两份 xdb（IPv6 不再是空桶）；
 *   · 每张表多一个 CSV（老版一个导出都没有）；
 *   · 老版没有"退出页 / 点击元素 / 站内搜索词 / utm 渠道"，这几个本插件保留。
 */

require_once __DIR__ . '/pages.php';

/* ============================== 表格包装（带 CSV id，页面与导出共用同一组 cols） ============================== */

function stx_t(array $t, string $csv): array
{
    if ($csv !== '' && !isset($t['csv'])) { $t['csv'] = $csv; }
    return $t;
}

function statx_c_domain(array $ctx): array
{
    return ['cols' => [['域名', 'k'], ['PV', 'c', 'r'], ['UV', 'uv', 'r']], 'rows' => statx_q_domain($ctx),
            'bar' => 'c', 'title' => '受访域名（多域名站点才有意义）',
            'note' => '老版这一页是为"一个站点挂多个域名"准备的（表头写的就是"适用于多域名站点"）。'
                . '单域名站这里只有 1 行，那是正常的。'];
}

function statx_c_paths1(array $ctx): array
{
    return ['cols' => [['一级路径', 'k'], ['PV', 'c', 'r'], ['UV', 'uv', 'r']], 'rows' => statx_q_paths($ctx, 1, 20),
            'bar' => 'c', 'title' => '一级路径 TOP 20',
            'note' => '一级路径 = URL 的第一段（`/tool/markdown` → `/tool`）。'];
}

function statx_c_paths2(array $ctx): array
{
    return ['cols' => [['二级路径', 'k'], ['PV', 'c', 'r'], ['UV', 'uv', 'r']], 'rows' => statx_q_paths($ctx, 2, 20),
            'bar' => 'c', 'title' => '二级路径 TOP 20',
            'note' => '二级路径能看到 `/tool/xxx` 这一层；只有一级的页面归到「(一级页)」。'];
}

function statx_c_ref_search(array $ctx): array
{
    return ['cols' => [['搜索引擎', 'k'], ['完整来路地址', 'ref'], ['PV', 'c', 'r'], ['UV', 'uv', 'r'], ['会话', 'sess', 'r']],
            'rows' => statx_q_ref_search($ctx), 'bar' => 'c', 'title' => '搜索引擎',
            'note' => '★★ r12x：新增「完整来路地址」列 —— 站长原话「来路统计只有根域名，'
                . '要统计到带参数的页面地址」，这一列就是**带参数的完整地址**（例如 '
                . '`https://www.bing.com/search?q=普洱茶&…&FORM=PERE1`），不再只给域名。'
                . '截断 72 字符显示、**鼠标悬停看全文**（零 JS）；取的是该引擎**最新一条**真实来路。'
                . '库里 `ref` 一直存的就是完整 URL（≤500 字符），本次只是把它显示出来 —— '
                . '真正被改掉的是**端点侧的隐私加固**：入库前剥离 token/session/auth/email 等敏感参数。'];
}

function statx_c_keyword(array $ctx): array
{
    $rows = statx_q_keyword($ctx, 50);
    return ['cols' => [['关键词', 'k'], ['来源', 'src'], ['来路', 'ref'], ['PV', 'c', 'r'], ['UV', 'uv', 'r'], ['直达搜索', 'deep', 'r']],
            'rows' => $rows, 'bar' => 'c', 'title' => '关键词（搜索词）',
            'note' => '**站内搜索词是本插件 100% 可靠的来源**（来自 `/search?q=…` 这类站内结果页的 referrer）；'
                . '外部引擎关键词天然只有一部分 —— Google 早已不把 `q=` 放进 referrer（浏览器隐私策略）。'
                . '「直达搜索」= 该关键词的会话里继续看了 ≥2 个页面的会话数。'
                . ' ★ r12：新增「来路」列 = 该关键词**最近一条带 `?q=…` 的完整来源地址**（截断显示，'
                . '**鼠标悬停看全文**，零 JS）—— 库里的 `ref` 一直是完整 URL，这次只是把它显示出来。'];
}

function statx_c_ext_detail(array $ctx): array
{
    $h = (string)($ctx['q']['h'] ?? '');
    $rows = [];
    foreach (statx_q_ext_host($ctx, $h, 200) as $r) {
        $rows[] = ['t' => e($r['t']), 'ref' => $r['ref'], 'path' => $r['path'], 'kind' => $r['kind']];
    }
    return ['cols' => [['时间', 't'], ['来路地址', 'ref'], ['落地页', 'path'], ['类型', 'kind']],
            'rows' => $rows, 'bar' => '', 'title' => '外部链接明细 · ' . ($h !== '' ? $h : '（未选来源站）'),
            'empty' => $h === '' ? '请先在上方「外部链接」表里点一个来源站的「查看」→' : '该来源站在所选范围内没有落到本站的记录。'];
}

function statx_c_int_detail(array $ctx): array
{
    return ['cols' => [['来源页面', 'src'], ['来路页面', 'ref'], ['次数', 'c', 'r']],
            'rows' => statx_q_int_detail($ctx, 50), 'bar' => 'c', 'title' => '站内来源明细'];
}

function statx_c_events(array $ctx): array
{
    return ['cols' => [['事件名', 'k'], ['次数', 'c', 'r'], ['UV', 'uv', 'r'], ['会话', 'sess', 'r']],
            'rows' => statx_q_events($ctx), 'bar' => 'c', 'title' => '事件汇总',
            'note' => '事件来自页面上的 `data-event` 标注（本站 `st.js` 会逐级向上查找），或直接调用 `ycstatx(\'事件名\')`。'
                . '**没有事件是正常的** —— 只有站点侧真的埋了事件才会有数据。'];
}

function statx_c_events_recent(array $ctx): array
{
    $rows = [];
    foreach (statx_q_events_recent($ctx, 50) as $r) {
        $rows[] = ['ts' => e($r['ts']), 'en' => $r['en'], 'path' => $r['path'], 'device' => $r['device'],
                   'uid' => $r['uid'] !== '' ? '<code>' . e($r['uid']) . '</code> '
                       . stx_lk('轨迹 →', ['p' => 'visitor', 'uid' => $r['uid']], $ctx) : '—'];
    }
    return ['cols' => [['时间', 'ts'], ['事件', 'en'], ['页面', 'path'], ['设备', 'device'], ['访客', 'uid']],
            'rows' => $rows, 'bar' => '', 'title' => '最近事件'];
}

/* ============================== 访客级明细（老版最细的两页） ============================== */

/** 用户列表（老版 9 列：用户 / 类型 / 地域 / 设备 / PV / 页面数 / 会话 / 最后访问 / 操作） */
function statx_c_users(array $ctx): array
{
    $rows = statx_dash_q('SELECT uid u, COUNT(*) pv, COUNT(DISTINCT path) paths,'
        . " COUNT(DISTINCT NULLIF(sid,'')) sess, MIN(ts) t0, MAX(ts) t1,"
        . ' MAX(region) region, MAX(device) device, MAX(browser) browser, MAX(os) os,'
        . ' MAX(CASE WHEN is_new=1 THEN 1 ELSE 0 END) seen_new'
        . ' FROM ' . STATX_SDB_HITS . ' WHERE ' . statx_dash_w()
        . " AND uid<>'' GROUP BY u ORDER BY t1 DESC LIMIT 200",
        [$ctx['site'], $ctx['from'], $ctx['to']]);
    $out = [];
    foreach ($rows as $r) {
        $u = (string)$r['u'];
        $out[] = [
            'u'      => '<code>' . e($u) . '</code>',
            'type'   => stx_tag('标识用户', 'g', '客户端 localStorage 里的随机 uid（跨天可关联；不存 IP）'),
            'region' => e(statx_geo_short((string)$r['region']) !== '' ? statx_geo_short((string)$r['region']) : '—'),
            'dev'    => e((string)$r['device']) . ' / ' . e((string)$r['browser']) . ' / ' . e((string)$r['os']),
            'pv'     => (int)$r['pv'],
            'paths'  => (int)$r['paths'],
            'sess'   => (int)$r['sess'],
            'first'  => e((string)$r['t0']),
            'last'   => e((string)$r['t1']),
            'act'    => stx_lk('轨迹 →', ['p' => 'visitor', 'uid' => $u], $ctx),
        ];
    }
    return ['cols' => [['用户', 'u'], ['类型', 'type'], ['地域', 'region'], ['设备 / 浏览器 / 系统', 'dev'],
                       ['PV', 'pv', 'r'], ['页面数', 'paths', 'r'], ['会话', 'sess', 'r'],
                       ['首次访问', 'first'], ['最后活跃', 'last'], ['操作', 'act']],
            'rows' => $out, 'bar' => 'pv', 'title' => '用户列表（按最后活跃倒序，最多 200 行）',
            'right' => count($out) . ' 个标识用户',
            'note' => '★ 隐私模型：访客标识是**客户端随机 uid**（不是 IP、不是指纹），所以这里能跨天把同一个人串起来；'
                . '库中**没有 IP 列**。「类型」只有一种情况不是"标识用户"——'
                . '如果某个浏览器禁用了 localStorage，`st.js` 会退回一次性会话标识，那种行不会出现在本表。'
                . '统计页现在比老版**多一列「首次访问」**（老版只有最后访问）。'];
}

/** 单访客轨迹：11 个 KPI + 按会话分组的时间线 + 访问过的页面 */
function statx_c_visitor_visits(array $ctx): array
{
    $uid = (string)($ctx['q']['uid'] ?? '');
    $rows = [];
    if ($uid !== '') {
        foreach (statx_dash_q('SELECT ts, path, kind, en, device, browser, os, region, ref_domain, sid'
            . ' FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND uid=? AND d>=? AND d<=? ORDER BY id DESC LIMIT 500',
            [$ctx['site'], $uid, $ctx['from'], $ctx['to']]) as $r) {
            $rows[] = $r;
        }
    }
    return ['_raw' => $rows, 'cols' => [], 'rows' => [], 'bar' => '', 'title' => ''];
}

function statx_c_visitor_pages(array $ctx): array
{
    $uid = (string)($ctx['q']['uid'] ?? '');
    $rows = [];
    if ($uid !== '') {
        foreach (statx_dash_q('SELECT path k, COUNT(*) c, MIN(ts) t0, MAX(ts) t1'
            . ' FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND uid=? AND d>=? AND d<=?'
            . " AND kind='pv' GROUP BY k ORDER BY c DESC, k LIMIT 50",
            [$ctx['site'], $uid, $ctx['from'], $ctx['to']]) as $r) {
            $rows[] = ['k' => e((string)$r['k']), 'c' => (int)$r['c'], 't0' => e((string)$r['t0']), 't1' => e((string)$r['t1'])];
        }
    }
    return ['cols' => [['页面', 'k'], ['PV', 'c', 'r'], ['首次', 't0'], ['最近', 't1']],
            'rows' => $rows, 'bar' => 'c', 'title' => '访问过的页面'];
}

function statx_c_visitor_sessions(array $ctx): array
{
    $uid = (string)($ctx['q']['uid'] ?? '');
    $rows = [];
    if ($uid !== '') {
        foreach (statx_dash_q('SELECT sid s, COUNT(*) n, MIN(ts) t0, MAX(ts) t1'
            . ' FROM ' . STATX_SDB_HITS . ' WHERE site_key=? AND uid=? AND d>=? AND d<=?'
            . " AND sid<>'' AND kind='pv' GROUP BY s ORDER BY t1 DESC LIMIT 100",
            [$ctx['site'], $uid, $ctx['from'], $ctx['to']]) as $r) {
            $rows[] = ['s' => '<code>' . e(mb_substr((string)$r['s'], 0, 12)) . '</code>',
                       'n' => (int)$r['n'], 't0' => e((string)$r['t0']), 't1' => e((string)$r['t1'])];
        }
    }
    return ['cols' => [['会话', 's'], ['页数', 'n', 'r'], ['开始', 't0'], ['结束', 't1']],
            'rows' => $rows, 'bar' => 'n', 'title' => '会话列表'];
}

/* ============================== 爬虫 / 预警 ============================== */

function stx_bot_tag(string $cls): string
{
    $c = strtolower($cls);
    if ($c === 'search' || $c === 'se') { return stx_tag('搜索引擎', 'g'); }
    if ($c === 'ai') { return stx_tag('AI 爬虫', 'o'); }
    /* ★★ r12：原来 'scraper' / 'other' / 'empty' 是**直接把英文 cls 原样印在页面上**
       （站长看到的"类型"栏里会冒出 scraper / other / empty 这种词）——
       这里换成中文，并保持"绿=搜索引擎 / 橙=AI / 红=未知"的三色语义不变。 */
    if ($c === 'scraper') { return stx_tag('采集器', 'r'); }
    if ($c === 'other' || $c === 'empty') { return stx_tag('未知爬虫', 'r'); }
    return stx_tag($cls !== '' ? $cls : '未知爬虫', 'r');
}

function statx_c_bots(array $ctx): array
{
    $all = ($ctx['p'] === 'bots_all');
    $site = $all ? null : $ctx['site'];
    $rows = [];
    foreach (statx_q_bots($site, $ctx['from'], $ctx['to'], 60) as $r) {
        $rows[] = ['k' => e((string)$r['k']), 'cls' => stx_bot_tag((string)$r['cls']),
                   's' => e((string)$r['s']), 'c' => (int)$r['c'], 'v6' => (int)$r['v6']];
    }
    return ['cols' => [['爬虫', 'k'], ['类型', 'cls'], ['站点', 's'], ['次数', 'c', 'r'], ['IPv6', 'v6', 'r']],
            'rows' => $rows, 'bar' => 'c',
            'title' => $all ? '蜘蛛与 AI 爬虫汇总（全部站点）' : '蜘蛛与 AI 爬虫汇总（本站）',
            'note' => '三色语义（与老版一致）：' . stx_tag('绿 = 搜索引擎', 'g') . ' · ' . stx_tag('橙 = AI 爬虫', 'o')
                . ' · ' . stx_tag('红 = 未知爬虫', 'r') . '。爬虫**不计入 PV/UV**（看板的 PV 只有人类流量），'
                . '它们单独成列 —— 这样"流量涨了"不会被爬虫刷出来的数字骗到。'
                // ★★ r12 新增口径说明（站长 2026-09-18 问「google/bing 蜘蛛是不是显示不全」）
                . ' <b>为什么 Google / Bing 蜘蛛常常只有个位数甚至 0：</b>本表统计的是'
                . '**执行了 JS 的爬虫**（本插件的采集脚本是 `document.referrer` + `sendBeacon`）。'
                . 'Googlebot / bingbot / Applebot 抓 HTML 时**不执行 JS**（Googlebot 只在"渲染阶段"跑 JS，'
                . '且那一步的 IP 不在 `googlebot.json` 公布的抓取段里），所以它们根本不会上报 ——'
                . '**日志里有几百次、这里也不会有行**。这不是漏采或算错，是这类采集方式的固有边界。'
                . '要拿到"真正来抓了多少"，权威口径是 nginx access log（按 UA 计数）；'
                . '要在这里看到它们，需要另开一条**服务端解析 access log** 的采集链路（见 r12 报告「仍然存在的问题」）。'];
}

function statx_c_bots_recent(array $ctx): array
{
    $all = ($ctx['p'] === 'bots_all');
    $site = $all ? null : $ctx['site'];
    $rows = [];
    foreach (statx_q_bots_recent($site, $ctx['from'], $ctx['to'], 40) as $r) {
        $rows[] = ['ts' => e($r['ts']), 'bot' => $r['bot'], 'cls' => stx_bot_tag($r['cls']),
                   'path' => $r['path'], 'site' => $r['site'], 'ipver' => $r['ipver']];
    }
    return ['cols' => [['时间', 'ts'], ['爬虫', 'bot'], ['类型', 'cls'], ['页面', 'path'], ['站点', 'site'], ['IP 版本', 'ipver']],
            'rows' => $rows, 'bar' => '', 'title' => '最近爬虫访问（最多 40 行）',
            'note' => 'IP 版本列能证明 IPv6 爬虫**也被正常记录**（老版 `region.py` 当年写死 IPv4，IPv6 全是空桶）。'];
}

function statx_c_alerts(array $ctx): array
{
    $all = ($ctx['p'] === 'alerts_all');
    $site = $all ? null : $ctx['site'];
    $thr = 10;
    $rows = [];
    foreach (statx_q_alerts($site, $ctx['from'], $ctx['to'], $thr, 60) as $r) {
        $rows[] = ['s' => e((string)$r['s']),
                   'u' => '<code>' . e((string)$r['u']) . '</code> '
                       . stx_lk('轨迹 →', ['p' => 'visitor', 'uid' => (string)$r['u']], $ctx),
                   'path' => e((string)$r['path']), 'c' => (int)$r['c'],
                   't0' => e((string)$r['t0']), 't1' => e((string)$r['t1']),
                   'v' => (int)$r['v'] === 6 ? 'IPv6' : ((int)$r['v'] === 4 ? 'IPv4' : '—')];
    }
    return ['cols' => [['站点', 's'], ['访客', 'u'], ['页面', 'path'], ['次数', 'c', 'r'],
                       ['首次', 't0'], ['最近', 't1'], ['IP 版本', 'v']],
            'rows' => $rows, 'bar' => 'c',
            'title' => $all ? '采集 / AI 拉取预警（全部站点）' : '采集 / AI 拉取预警（本站）',
            'right' => '阈值：同一访客对同一页面 ≥ ' . $thr . ' 次',
            'note' => '规则（口径写在这里，数字才可解释）：**同一个 `uid` 在所选区间内对同一个 `path` 的 PV ≥ '
                . $thr . '** 即计入预警 —— 正常读者不会把同一页刷十遍，这几乎一定是采集器/预取/镜像。'
                . '⚠ 本表**不显示 IP**（库里没有 IP 列），只给 uid 与 IP 版本；也不会自动封禁任何人 ——'
                . '**判断权在站长手里**（要拦到 `guardweb` 的 UA/行为规则，不在本插件职责内）。'
                . '阈值可在「设置」页调整（`collect.alert_thr`）。'];
}

function statx_c_check_items(array $ctx): array
{
    try {
        $st = statx_q_collect_status();
        $rt = $st['rt'];
        $items = [
            ['k' => '插件已启用', 'v' => Plugins::isOn('statx') ? '通过' : '未通过',
             'note' => '到「插件」页启用 statx，否则后台页只显示停用提示'],
            ['k' => '采集总开关', 'v' => $st['enabled'] ? '通过' : '未通过',
             'note' => '关闭时端点返回 204（站点侧脚本照样加载但不上报）'],
            ['k' => '端点配置 rt.php', 'v' => $st['rt_exists'] ? '通过' : '未通过',
             'note' => '端点读它 → 零 SQL；缺失时到「设置」保存一次即重建'],
            ['k' => '端点路径', 'v' => (string)($rt['path'] ?? '/st') . ' / 脚本 ' . (string)($rt['script'] ?? '/st.js'),
             // ★ 2026-09-14：域名识别不出来时不再拼出一行指向别人的脚本，而是说清怎么办
             'note' => ((string)$st['pub'] !== ''
                 ? '站点侧只需一行 `<script src="' . e((string)$st['pub']) . (string)($rt['script'] ?? '/st.js') . '">`'
                 : '**还没有识别出本站域名** ⇒ 去「采集与站点管理」填写端点域名后，这里才给可复制的一行')],
            ['k' => 'Origin 白名单', 'v' => count((array)($rt['origins'] ?? [])) . ' 个',
             'note' => '由"启用中的站点登记"自动生成，**不用通配符**（`*` 会允许任何站往这里灌数据）'],
            ['k' => '站点登记', 'v' => count($st['sites']) . ' 个',
             'note' => '未启用的站不会进白名单'],
            ['k' => 'spool 目录', 'v' => $st['spool']['exists'] ? '存在' : '尚未创建',
             'note' => (string)$st['spool']['dir'] . '（中间产物绝不落 webroot）'],
            ['k' => '本地静态副本', 'v' => (statx_cfg('collect.inject_mode') === 'static') ? '启用' : '未启用',
             'note' => '注入模式 = ' . e((string)statx_cfg('collect.inject_mode'))],
        ];
    } catch (Throwable $e) {
        return ['cols' => [['检测项', 'k'], ['结果', 'v'], ['说明', 'note']], 'rows' => [], 'bar' => '',
                'title' => '统计代码检测结果', 'empty' => '检测读取失败（详见服务器 PHP 错误日志）'];
    }
    return ['cols' => [['检测项', 'k'], ['结果', 'v'], ['说明', 'note']], 'rows' => $items, 'bar' => '',
            'title' => '统计代码检测结果',
            'note' => '老版这一页是"从后台主动抓目标站页面、看代码有没有接上"；本插件**用同一件事的更硬做法**：'
                . '不抓页面（避免出站请求与误判），而是直接读**本插件自己的运行时真值** —— '
                . '端点配置、白名单、站点登记、spool、以及"最近一次真正收到上报是什么时候"。'
                . '最后一行的"最近一次上报"才是**唯一无法伪造的证据**：收到过就是接上了。'
                . '需要真实抓页面实证时，到「设置」页点「实证校验」（会带 cache-buster 抓一次首页）。'];
}
