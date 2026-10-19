<?php
/** 浏览工坊首页【正文部件】· 「大站首页」（重构版）
 *  站主 2026-09-23 指令："工具这些色块太重，后期工具还会更多，可能 herontool.com 的工具都要来。
 *                        重构首页，要像大站，要简单，要大气。"
 *
 *  ★ 这是一个【共享部件】（不是视图本身）：themes/yecha/views/landing.php（正式首页）
 *    与 preview-home.php（效果图预览）都 include 它 ⇒ ★ 单一源码，看到的 = 要上的 ★
 *
 *  ★ 四条硬约束（本文件按它写死）：
 *    ① 【能扩】首页结构只由"分类数"决定，与工具总数无关 ⇒ 137 还是 1700，元素数与数量都不变
 *    ② 【克制】色块只用于"状态"与"图标底"两处；同一屏只允许 1 个强调色；工具本身不上色
 *    ③ 【像大站】搜索为核心 + 少量分类入口 + 精选内容行 + 大量留白
 *    ④ 【真实】"热门"必须来自真实访问数据（nginx 日志 /tool/{slug} PV），不写死榜单
 *
 *  说明：分类采用【统一分类体系】，同时能装下 liulanqi 的 137 个与 herontool 的 1079 个工具。
 *  回滚 = 删本文件 + 还原 landing.php 的 .bak_style_<date>。
 *  传入：$posts（可选，内核在 landing 分支传入）
 */

/* ─────────────────────────  1. 真实计数  ───────────────────────── */
$nTools = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . " WHERE status='publish' AND deleted_at IS NULL AND slug LIKE 'tool\\_\\_%'");
$nTips  = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . " WHERE status='publish' AND deleted_at IS NULL AND slug LIKE 'tips\\_\\_%'");
$nLearn = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . " WHERE status='publish' AND deleted_at IS NULL AND slug LIKE 'learn\\_\\_%'");
$nDl    = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . " WHERE status='publish' AND deleted_at IS NULL AND slug LIKE 'download\\_\\_%'");

$navMark  = 'navsrc:pg-nav_sites';
$navTotal = 0; $navAlive = 0; $navSlow = 0; $navBlocked = 0; $navDown = 0;
if (Db::rows('SHOW TABLES LIKE "' . Db::t('links') . '"')) {
    $navTotal = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('links') . ' WHERE engine=?', [$navMark]);
    foreach (Db::rows('SELECT probe_state s, COUNT(*) c FROM ' . Db::t('links') . ' WHERE engine=? GROUP BY probe_state', [$navMark]) as $r) {
        $k = (string)$r['s'];
        if ($k === 'alive')       { $navAlive   = (int)$r['c']; }
        elseif ($k === 'slow')    { $navSlow    = (int)$r['c']; }
        elseif ($k === 'blocked') { $navBlocked = (int)$r['c']; }
        else                      { $navDown   += (int)$r['c']; }
    }
}
$stCls   = $navDown > 0 ? 'warn' : 'ok';
$stTxt   = '本轮验过';

/* ─────────────────────────  2. 统一分类体系（★ 能装扩展的核心）  ─────────────────────────
   · 每类 = 一个入口卡；★ 卡片数量只由这张表决定，与工具总数无关
   · 目标是 12 组（可扩到 15）；下面这张表同时容纳 liulanqi 与 herontool 的分类语汇
   · ★ 未匹配到分类的工具不会消失：进「其他」，并且仍可被搜索/仍出现在 /tool 列表页
   ───────────────────────────────────────────────────────────────────────────── */
/* ★ 分类的两张表与归类函数都在【单一真源】里 ——
   分类筛选页 /tool/c/{key} 读的是同一份 ⇒ 卡上的数字与筛出来的条数不会漂。
   ★ 真源用**函数返回数组**：实测踩过两种坑 —— 变量版在函数体里 require 会掉作用域；
     define() 版把数组字面量内联进去会多一个分号 ⇒ ParseError。
   ★★ 必须 require_once + function_exists 双保险：index.php 顶部已经引过一次，
      这里再 require（非 once）会 "Cannot redeclare yc_cat_table()" ⇒ 首页 500（实测）。 */
if (!function_exists('yc_cat_table')) { require_once __DIR__ . '/cat-def.php'; }
$CAT_GROUPS = yc_cat_table();   // 本文件原有代码用的变量名，映一份本地副本
$LQ_CAT_OF  = yc_lq_cat_map();

/* liulanqi 工具 slug → 统一分类键（显式映射；未列出者按 description 的「分类」兜底） */

/* 统一分类的内部映射：$CAT_GROUPS 的 description 关键词 → 分类键 */
$descToKey = [];
foreach ($CAT_GROUPS as $g) { foreach ($g[3] as $w) { $descToKey[$w] = $g[0]; } }

/* ─────────────────────────  3. 工具表（真实数据，逐个来自 DB）  ───────────────────────── */
/* ★ 扩容读法：1,700 条时若把每行 keywords/正文都读进来，单次首页渲染会多读几百 KB 无用列。
   ① 只 SELECT 真正用到的 3 列；② 用 instr(description,'liulanqi「') 预筛 —— 该串实测 137/137 命中
   （分类就是从它里面正则取出来的），预筛不中 ⇒ 与全量读法一样落到兜底分类 'text'。
   ★ 结构不变式：DOM 元素数与页面高度只由 $CAT_GROUPS 决定，与工具总数无关。 */
$rows = Db::rows('SELECT slug, title, description FROM ' . Db::t('contents')
    . " WHERE status='publish' AND deleted_at IS NULL AND slug LIKE 'tool\\_\\_%'"
    . " AND instr(description, 'liulanqi「') > 0 ORDER BY slug ASC");

$tools = [];            // slug => [title, cat]
foreach ($rows as $r) {
    $slug  = preg_replace('/^tool__/', '', (string)$r['slug']);
    $label = trim((string)$r['title']);
    $label = preg_replace('/\s*[-–—·|]\s*liulanqi.*$/u', '', $label);   // 去掉 SEO 尾巴（不改 DB）
    $label = preg_replace('/\s*[-–—·|]\s*浏览工坊.*$/u', '', $label);
    if ($label === '') { $label = (string)$r['title']; }

    if (isset($LQ_CAT_OF[$slug])) {
        $cat = $LQ_CAT_OF[$slug];
    } elseif (preg_match('/liulanqi「([^」]+)」/u', (string)$r['description'], $m) && isset($descToKey[$m[1]])) {
        $cat = $descToKey[$m[1]];
    } else {
        $cat = 'text';                                              // 兜底≠丢弃：仍可搜索、仍在 /tool
    }
    $tools[$slug] = ['slug' => $slug, 'title' => $label, 'cat' => $cat];
}

/* ★ 每类计数与分类筛选页读**同一份**数据（cat-def.php 的 yc_tools_by_cat()）——
   只有这样"卡上的数字 == 筛出来的条数"才是**结构上成立**，而不是靠人去对齐两段代码。
   结构不变式：计数只由分类表决定，与工具总数无关（1,700 个也一样）。 */
$__catAll = yc_tools_by_cat();
$catCount = [];
foreach ($CAT_GROUPS as $g) { $catCount[$g[0]] = count($__catAll[$g[0]] ?? []); }

/* ★★ 分类数上限闸（站主 2026-09-23 裁决口径："分类数 > 15 个时才走'只显示最多的 8-10 个 + 更多进 /tool'"）
   为什么要有：★ 首页**高度**只由"非空分类组数"决定 ⇒ 能让首页长高的那个量正是**分类数**，不是工具数。
   现在 11 组 ⇒ **不触发**（渲染与没有这段时逐字节相同）；一旦 > 15 ⇒ 只显示最多的 10 个 + 一张「更多分类 →」。
   ★ 这是"会失败的前置守卫"：把 $CAT_CAP 改成 3 就能看见它折叠（负向测试见交付说明）。 */
$CAT_CAP     = 15;
$CAT_SHOWTOP = 10;
$__shown = [];
if (count(array_filter($catCount)) > $CAT_CAP) {
    $__shown = array_keys($catCount);
    usort($__shown, fn($a, $b) => [$catCount[$b], $a] <=> [$catCount[$a], $b]);
    $__shown = array_slice($__shown, 0, $CAT_SHOWTOP);
}
unset($__catAll);

/* ─────────────────────────  4. 热门：来自真实访问数据（不写死）  ─────────────────────────
   ★ 数据源 = nginx 日志里 /tool/{slug} 的请求数（排掉爬虫 UA）
   ★ 只读【当前日志文件的尾部 256 KB】（fseek 倒着读）—— 不扫全量，首页不因此变慢
   ★ 拿不到日志 ⇒ 不编造榜单，只按名称给几项并在页面上如实标注
   ★ Cache::get/set 的真实签名是 (ns, key)（已实测 core/Cache.php）—— 别写错
   ───────────────────────────────────────────────────────────────────────── */
$hot = [];
$hotSrc = 'none';
$hotScanned = 0;
$hotLogFrom = '';                                    // ★ 本次实际读到的日志文件的起始时间（口径的一部分）
$hotGenAt   = time();                                // ★ 取数时刻
if (function_exists('get_option')) {
    $__tz = (string)get_option('site.timezone', 'Asia/Shanghai');
    if ($__tz !== '') { @date_default_timezone_set($__tz); }
    unset($__tz);
}
$HOT_NS = 'misc';
$HOT_KEY = 'home_hot_tools_v2';
$cachedRaw = class_exists('Cache') ? Cache::get($HOT_NS, $HOT_KEY) : false;
if (is_string($cachedRaw) && $cachedRaw !== '') {
    $decoded = json_decode($cachedRaw, true);
    if (is_array($decoded) && !empty($decoded['items'])) {
        $hot = $decoded['items'];
        $hotSrc = 'cache';
        $hotScanned = (int)($decoded['scanned'] ?? 0);
    }
}
if ($hot === []) {
    $counts = [];
    /* ★ 实测过的坑：爬虫洪峰（YisouSpider 之类）会在几分钟内把日志尾部填满"只为爬虫写的行"，
       256 KB 的尾巴里过滤掉爬虫后常常一条 /tool/ 都不剩 ⇒ 那会让首页误判成"没有访问数据"。
       所以读【当天 + 前一天】两个日志文件，每个文件各取尾部 2 MB —— 既避开洪峰，又不会扫全量。 */
    $logs = ['/www/wwwlogs/liulanqi.com.log', '/www/wwwlogs/liulanqi.com.log.1'];
    $scanned = 0;
    foreach ($logs as $log) {
        if (!is_readable($log)) { continue; }
        $fh = @fopen($log, 'rb');
        if (!$fh) { continue; }
        /* ★ 实测：只读"尾部 N MB"会读到爬虫洪峰，过滤后人类 PV 只剩 1–3 的噪声；
           而这两天日志整读（约 42 MB / 24 万行）耗时在百毫秒级，且结果小时级缓存 ⇒ 整读更准也够快。 */
        while (($line = fgets($fh)) !== false) {
            $scanned++;
            if ($scanned === 1 && $hotLogFrom === '') {
                /* ★ 口径要能写出"从什么时候到什么时候"：取本次读到的日志文件第一行的时间戳 */
                if (preg_match('#\[(\d{2}/[A-Za-z]{3}/\d{4}:\d{2}:\d{2}:\d{2})#', $line, $__m)) {
                    $__ts = strtotime($__m[1]);
                    if ($__ts) { $hotLogFrom = date('Y-m-d H:i', $__ts); }
                }
            }
            if (stripos($line, 'bot') !== false || stripos($line, 'spider') !== false
                || stripos($line, 'crawl') !== false || stripos($line, 'ahrefs') !== false
                || stripos($line, 'semrush') !== false || stripos($line, 'curl') !== false
                || stripos($line, 'python') !== false || stripos($line, 'go-http') !== false) { continue; }
            if (preg_match('#GET /tool/([a-z0-9\-]+)#', $line, $m)) {
                $counts[$m[1]] = ($counts[$m[1]] ?? 0) + 1;
            }
        }
        fclose($fh);
        if (count($counts) >= 20) { break; }                      // 已经够排序了，不必再读前一天
    }
    $hotScanned = $scanned;
    if ($counts !== []) {
        arsort($counts);
        $i = 0;
        foreach ($counts as $slug => $pv) {
            if (!isset($tools[$slug])) { continue; }              // 只收仍存在的工具
            $hot[] = ['slug' => $slug, 'title' => $tools[$slug]['title'], 'pv' => (int)$pv];
            if (++$i >= 8) { break; }
        }
        if ($hot !== []) {
            $hotSrc = 'nginx log';
            if (class_exists('Cache')) {
                Cache::set($HOT_NS, $HOT_KEY, json_encode(['items' => $hot, 'scanned' => $scanned], JSON_UNESCAPED_UNICODE), 3600);
            }
        }
    }
}
if ($hot === []) {
    $i = 0;
    foreach ($tools as $t) { $hot[] = ['slug' => $t['slug'], 'title' => $t['title'], 'pv' => 0]; if (++$i >= 8) { break; } }
    $hotSrc = 'fallback';
}
/* 导航精选：从 151 条里挑若干个真实条目（只取"已收录 + 最近一轮探测 alive"的，不编）
   ★ 实测：本实例 status 全为 'public'（151/151） */
$navPick = [];
if (Db::rows('SHOW TABLES LIKE "' . Db::t('links') . '"')) {
    $navPick = Db::rows('SELECT name, url, probe_state FROM ' . Db::t('links')
        . " WHERE engine=? AND status IN ('show','public','pending') AND probe_state='alive' ORDER BY sort DESC, id ASC LIMIT 6", [$navMark]);
}

/* ─────────────────────────  5. 线性图标（内联 SVG，单色，不引外链）  ───────────────────────── */
$ICONS = [
    'text'    => '<path d="M4 6h16M4 12h10M4 18h7"/>',
    'code'    => '<path d="M9 7 4 12l5 5M15 7l5 5-5 5"/>',
    'shuffle' => '<path d="M3 7h4l10 10h4M17 7h4v4M3 17h4l3-3M17 17h4v-4"/>',
    'file'    => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
    'image'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m4 18 5-5 4 4 3-3 4 4"/>',
    'play'    => '<circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4z"/>',
    'calc'    => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 11h2M12 11h2M16 11h0M8 15h2M12 15h2M16 15h0"/>',
    'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'palette' => '<circle cx="12" cy="12" r="9"/><circle cx="9" cy="10" r="1"/><circle cx="15" cy="10" r="1"/><circle cx="12" cy="15" r="1"/>',
    'spark'   => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18"/>',
    'tag'     => '<path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9z"/><circle cx="8" cy="8" r="1.4"/>',
    'heart'   => '<path d="M12 20s-7-4.5-7-9.5A4 4 0 0 1 12 7a4 4 0 0 1 7 3.5C19 15.5 12 20 12 20z"/>',
    'shield'  => '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/>',
];
$icon = function (string $k) use ($ICONS): string {
    $p = $ICONS[$k] ?? $ICONS['file'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
?>
<style>
/* ★ [中性色令牌化批 2026-09-24] 原值：var(--fg)×2 → var(--fg)。令牌 = theme 自己的 style.css 已定义的 --line / --fg。回滚 = 换回 home-calm.php.bak_tok_20260923-1838 */
/* ═══════════ 大站首页（hc- = home calm）· 只加这一个前缀，不影响其它页 ═══════════
   ★ 克制配色：正文区中性灰；只有「状态色」「图标底」「1 个强调色」三处用色
   ★ 大气：大标题 + 大区块间距 + 每屏元素少
   ══════════════════════════════════════════════════════════════════════════ */
.hc{max-width:1080px;margin:0 auto;padding:0 20px}
/* --- 第一屏：搜索为核心 --- */
.hc-hero{padding:64px 0 8px;text-align:center}
.hc-h1{font-size:40px;line-height:1.25;margin:0 0 14px;letter-spacing:-1.2px;font-weight:900;color:var(--fg)}
.hc-lede{margin:0 auto 26px;max-width:560px;font-size:16px;line-height:1.9;color:var(--muted)}
.hc-lede b{color:var(--fg);font-weight:700}
.hc-search{
  display:flex;align-items:center;gap:11px;max-width:660px;margin:0 auto;
  background:#fff;border:1px solid var(--line);border-radius:14px;padding:15px 17px;
  box-shadow:0 1px 2px rgba(16,20,28,.04), 0 12px 30px -18px rgba(16,20,28,.28);
  text-align:left;color:#96a0ab;font-size:15.5px;
}
.hc-search:hover{border-color:#c3cbda}
.hc-search:focus-within{border-color:var(--accent);box-shadow:0 0 0 4px rgba(47,91,215,.10)}
.hc-search svg{width:18px;height:18px;flex:none;color:#96a0ab}
.hc-search .ph{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hc-search kbd{font:11px/1 ui-monospace,Menlo,Consolas,monospace;color:var(--muted);background:#f6f7f9;border:1px solid var(--line);border-radius:5px;padding:4px 6px}
.hc-tags{display:flex;gap:9px;flex-wrap:wrap;justify-content:center;margin:16px 0 0;font-size:13px;color:var(--muted);align-items:center}
.hc-tags a{color:var(--muted);border-bottom:1px solid transparent;padding-bottom:1px}
.hc-tags a:hover{color:var(--accent);border-bottom-color:var(--accent)}

/* --- 分类入口：一行 6–8 个，卡片数只由分类表决定 --- */
.hc-sec{margin:64px 0 0}
.hc-sechead{display:flex;align-items:baseline;gap:12px;margin:0 0 20px}
.hc-sechead h2{font-size:20px;margin:0;font-weight:800;letter-spacing:-.3px}
.hc-sechead .sub{color:var(--muted);font-size:13px}
.hc-sechead .more{margin-left:auto;font-size:13.5px;font-weight:700;color:var(--accent);white-space:nowrap}
.hc-cats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.hc-cat{
  display:flex;align-items:center;gap:12px;padding:16px;
  background:#fff;border:1px solid var(--line);border-radius:12px;
}
.hc-cat:hover{border-color:#c3cbda;box-shadow:0 2px 6px rgba(16,20,28,.05)}
.hc-cat .ic{
  width:36px;height:36px;border-radius:10px;flex:none;display:flex;align-items:center;justify-content:center;
  background:#f4f6fa;color:#4a5568;                 /* ★ 单色图标底：不做每格一个彩底 */
}
.hc-cat .ic svg{width:19px;height:19px}
.hc-cat .tx{min-width:0}
.hc-cat .nm{font-size:14.5px;font-weight:700;color:var(--fg);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hc-cat .ct{font-size:12px;color:var(--muted);margin-top:2px;font-variant-numeric:tabular-nums}

/* --- 精选/热门：2–3 行 × 每行 6–8 个 --- */
.hc-hots{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.hc-hot{
  display:flex;align-items:center;gap:10px;padding:12px 13px;
  background:#fff;border:1px solid var(--line);border-radius:11px;min-width:0;
}
.hc-hot:hover{border-color:#c3cbda;background:#fcfcfd}
.hc-hot .ic{width:30px;height:30px;border-radius:9px;flex:none;display:flex;align-items:center;justify-content:center;background:#f4f6fa;color:#4a5568}
.hc-hot .ic svg{width:16px;height:16px}
.hc-hot .nm{font-size:14px;font-weight:600;color:var(--fg);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hc-hot .pv{font:11px/1 ui-monospace,Menlo,Consolas,monospace;color:var(--muted);margin-left:auto;flex:none}
.hc-hotnote{color:var(--muted);font-size:12px;margin:12px 0 0}

/* --- 导航入口：只给一个板块 --- */
.hc-navbox{
  display:flex;align-items:center;gap:18px;flex-wrap:wrap;
  background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px 22px;
}
.hc-navbox .lead{font-size:15px;font-weight:700;color:var(--fg)}
.hc-navbox .lead span{display:block;font-size:12.5px;font-weight:400;color:var(--muted);margin-top:4px}
.hc-navlinks{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto}
.hc-navlinks a{font-size:13px;color:var(--muted);border:1px solid var(--line);border-radius:999px;padding:5px 12px}
.hc-navlinks a:hover{color:var(--accent);border-color:#c3cbda}
.hc-chip{
  display:inline-flex;align-items:center;gap:6px;height:26px;padding:0 11px;border-radius:999px;
  font-size:11.5px;font-weight:700;background:#eaf6ef;color:#0f5c37;border:1px solid #cfe9da;flex:none;
}
.hc-chip i{width:7px;height:7px;border-radius:50%;background:#18894f;display:block;flex:none}
.hc-chip.warn{background:#fdf4e3;color:#7a5406;border-color:#f2e2c0}
.hc-chip.warn i{background:#b57d09}

/* --- 内容区：一排 3–4 张卡 --- */
.hc-posts{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.hc-post{background:#fff;border:1px solid var(--line);border-radius:12px;padding:18px;display:flex;flex-direction:column;gap:9px}
.hc-post:hover{border-color:#c3cbda;box-shadow:0 2px 6px rgba(16,20,28,.05)}
.hc-post .cat{font-size:11.5px;color:var(--muted);letter-spacing:.4px}
.hc-post .t{font-size:15.5px;font-weight:700;line-height:1.55;color:var(--fg)}
.hc-post .d{font-size:13px;color:var(--muted);line-height:1.75;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.hc-post .m{margin-top:auto;font:11.5px/1 ui-monospace,Menlo,Consolas,monospace;color:var(--muted)}

/* --- 深色 CTA（简化版） --- */
.hc-cta{
  background:#14171a;color:#fff;border-radius:16px;padding:32px 34px;margin:64px 0 0;
  display:flex;align-items:center;gap:24px;flex-wrap:wrap;
}
.hc-cta .txt{flex:1;min-width:260px}
.hc-cta h3{margin:0;font-size:22px;font-weight:800;letter-spacing:-.4px}
.hc-cta p{margin:6px 0 0;font-size:13.5px;color:rgba(255,255,255,.66);line-height:1.8;font-weight:400}
.hc-cta .acts{display:flex;gap:10px;flex-wrap:wrap}
.hc-btn{display:inline-flex;align-items:center;gap:8px;border-radius:10px;padding:12px 20px;font-size:14.5px;font-weight:700;border:1px solid rgba(255,255,255,.22);color:#fff;background:rgba(255,255,255,.08)}
.hc-btn.pri{background:#fff;border-color:#fff;color:#14171a}
.hc-btn:hover{color:inherit}
.hc-btn.pri:hover{color:#14171a}

/* --- 响应式：★ 断点只改列数，不改"有几块" --- */
@media(max-width:900px){
  .hc-hero{padding:40px 0 4px}
  .hc-h1{font-size:31px}
  .hc-sec{margin:44px 0 0}
  /* ★ 截图里读到的问题：窄屏时"标题 + 副标 + 查看全部"挤成一行会让标题断行。
     修法：允许换行，并把"查看全部"整条推到下一行（右对齐）。 */
  .hc-sechead{flex-wrap:wrap;row-gap:6px;margin-bottom:18px}
  .hc-sechead h2{font-size:19px;white-space:nowrap}
  .hc-sechead .more{margin-left:auto}
  .hc-sechead .sub{flex:1 1 100%;margin-top:-2px}
  .hc-cats{grid-template-columns:repeat(2,1fr)}
  .hc-hots{grid-template-columns:repeat(2,1fr)}
  .hc-posts{grid-template-columns:1fr}
  .hc-cta{margin-top:44px;padding:24px}
}
@media(max-width:560px){
  .hc-h1{font-size:26px}
  .hc-cats{gap:9px}
  /* ★ 截图里读出来的问题：390 宽时"文本处理 / 7 个工具"会折成两行，标题也被挤断。
     修法：窄屏把分类卡改成【图标在上、文字在下】的竖排，并让计数不换行。 */
  .hc-cat{flex-direction:column;align-items:flex-start;gap:8px;padding:13px}
  .hc-cat .ic{width:30px;height:30px}
  .hc-cat .ic svg{width:16px;height:16px}
  .hc-cat .nm,.hc-cat .ct{white-space:nowrap;width:100%;overflow:hidden;text-overflow:ellipsis}
  /* 热门卡同理：名字太长就省略号，不撑破格子 */
  .hc-hot{padding:11px 10px;gap:7px}
  .hc-hot .ic{width:24px;height:24px;border-radius:7px}
  .hc-hot .ic svg{width:14px;height:14px}
  /* ★ 截图里读到的问题：390 宽下 "Markdown 转 HTML" 与 "Markdown 转 PDF" 都被截成 "Markdown …"，
     两者不可辨 ⇒ 窄屏改成允许两行（.nm 不再 nowrap）。 */
  .hc-hot .nm{white-space:normal;font-size:13px;line-height:1.35;overflow:visible;text-overflow:clip;
              display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
}
/* 深色 */
html.dark .hc-search{background:#1b1f24;border-color:#2a2f36}
html.dark .hc-search:focus-within{border-color:var(--accent)}
html.dark .hc-search kbd{background:#151a1f;border-color:#2a2f36}
html.dark .hc-cat,html.dark .hc-hot,html.dark .hc-navbox,html.dark .hc-post{background:#1b1f24;border-color:#2a2f36}
html.dark .hc-cat .ic,html.dark .hc-hot .ic{background:#232a31;color:#a8b3c0}
html.dark .hc-h1{color:var(--fg)}
html.dark .hc-lede b{color:var(--fg)}
html.dark .hc-navlinks a{background:#1b1f24;border-color:#2a2f36}
</style>

<div class="hc">

  <!-- ═══ ① 第一屏：搜索为核心（★ 与站头搜索共用同一个真表单：这里只是聚焦入口） ═══ -->
  <section class="hc-hero">
    <h1 class="hc-h1">你的浏览器就是工作台</h1>
    <p class="hc-lede">
      <b><?= (int)$nTools ?> 个工具</b> · <b><?= (int)$navTotal ?> 个入口</b> · 不需要服务器的工具，都在本机跑。
    </p>
    <?php /* ★ 全站只有一个真搜索表单 = 站头那个（#sthQ）。
             这里用锚点，无 JS 时点它也真的把光标送进站头输入框。 */ ?>
    <a class="hc-search" id="hcSearch" href="#sthQ" aria-label="把光标送到站头搜索框">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4 4"/></svg>
      <span class="ph">搜工具、搜站点、搜技巧 —— 例如：二维码 / 时间戳 / 书签</span>
      <kbd>/</kbd><kbd>Ctrl K</kbd>
    </a>
    <div class="hc-tags">
      <span>常搜：</span>
      <?php foreach (array_slice($hot, 0, 5) as $h): ?>
      <a href="/tool/<?= e($h['slug']) ?>"><?= e($h['title']) ?></a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ═══ ② 分类入口（★ 卡片数只由分类表决定 ⇒ 工具涨到 1700 也不变） ═══ -->
  <section class="hc-sec">
    <div class="hc-sechead">
      <h2>按类别找</h2>
      <?php /* ★ 实测踩过：表里 13 组，但空组会被 continue 跳过 ⇒ 实际只渲染 11 张卡，
               副标写 count($CAT_GROUPS) 会出现"13 个分类"而屏幕上只有 11 张卡。改成数"真要渲染的"。 */
            $__lq_ncat = 0;
            foreach ($CAT_GROUPS as $__lq_g) { if (($catCount[$__lq_g[0]] ?? 0) > 0) { $__lq_ncat++; } }
            unset($__lq_g); ?>
      <span class="sub"><?= (int)$nTools ?> 个工具</span>
      <a class="more" href="/tool">查看全部 →</a>
    </div>
    <!-- ★ 2026-10-11 站主裁定 P1：/tool/c/* 分类页撤除 ⇒ 首页「分类」卡整块摘除（旧址 301 → /tool） -->

  </section>

  <!-- ═══ ③ 热门（★ 来自真实访问数据；拿不到就不显示这块，不编榜单） ═══ -->
  <?php if ($hot !== []): ?>
  <section class="hc-sec">
    <div class="hc-sechead">
      <h2><?= ($hotSrc === 'nginx log' || $hotSrc === 'cache') ? '大家在用' : '先从这几个开始' ?></h2>
      <span class="sub"><?= $hotSrc === 'nginx log' || $hotSrc === 'cache' ? '按本站真实访问量排序' : '按名称排序 · 暂无访问数据' ?></span>
      <a class="more" href="/tool">查看全部 <?= (int)$nTools ?> 个 →</a>
    </div>
    <div class="hc-hots">
      <?php foreach ($hot as $h): ?>
      <a class="hc-hot" href="/tool/<?= e($h['slug']) ?>">
        <span class="ic"><?= $icon(($tools[$h['slug']]['cat'] ?? 'text') === 'media' ? 'image' : (($tools[$h['slug']]['cat'] ?? 'text') === 'dev' ? 'code' : 'file')) ?></span>
        <span class="nm"><?= e($h['title']) ?></span>

      </a>
      <?php endforeach; ?>
    </div>
    <?php if ($hotSrc === 'nginx log' || $hotSrc === 'cache'):
        /* ★ 时区统一：页面上的时间一律标 **UTC**（nginx 日志戳本身就是 UTC，不再换算成上海时间，
           免得"同一个时刻写成两个样子"）。 */
        $__lq_lf = $hotLogFrom !== '' ? $hotLogFrom : gmdate('Y-m-d H:i', (int)$hotGenAt); ?>
    <p class="hc-hotnote">取数：<b>nginx 日志</b> <code>/tool/{slug}</code> 请求数（已剔爬虫）· 窗口 <b><?= e((string)$__lq_lf) ?></b> → <b><?= e(gmdate('Y-m-d H:i', (int)$hotGenAt)) ?> UTC</b> · 扫 <b><?= number_format((int)$hotScanned) ?></b> 行 · 缓存 1h
      <details><summary>口径细节</summary>既不是人工榜单，也不是 statx 口径；按 UA 排除 bot / spider / crawl / ahrefs / semrush / curl / python / go-http；当日 + 前一天两个日志文件；榜上每一条都是站上真实存在的工具；取不到日志时本段整体不显示。</details>
    </p>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- ═══ ④ 导航入口（只给一个板块，不铺全） ═══ -->
  <section class="hc-sec">
    <div class="hc-sechead">
      <h2>上网入口</h2>
      <span class="sub"><?= (int)$navTotal ?> 条 · 条条实测 · 四种状态全公开</span>
      <a class="more" href="/links-health">查看全部 →</a>
    </div>
    <div class="hc-navbox">
      <div class="lead">
        <span class="hc-chip <?= e($stCls) ?>"><i></i><?= (int)$navTotal ?> 条 · <?= e($stTxt) ?></span>
        <span><b style="color:var(--fg)">活的 <?= (int)$navAlive ?></b> · 慢的 <?= (int)$navSlow ?> · 被反爬 <?= (int)$navBlocked ?> · 已下线 <?= (int)$navDown ?> —— 连坏的那几条也照原样留着。</span>
      </div>
      <div class="hc-navlinks">
        <?php foreach (array_slice($navPick, 0, 5) as $np): ?>
        <a href="<?= e((string)$np['url']) ?>" rel="noopener" target="_blank"><?= e((string)$np['name']) ?></a>
        <?php endforeach; ?>
        <a href="/links-health">链接体检 →</a>
      </div>
    </div>
  </section>

  <!-- ═══ ⑤ 内容区（一排 3 张真实文章） ═══ -->
  <?php
  $tips = Db::rows('SELECT slug, title, description, published_at FROM ' . Db::t('contents')
      . " WHERE status='publish' AND deleted_at IS NULL AND slug LIKE 'tips\\_\\_%'
          ORDER BY COALESCE(published_at, created_at) DESC LIMIT 3");
  if ($tips !== []):
  ?>
  <section class="hc-sec">
    <div class="hc-sechead">
      <h2>浏览器技巧</h2>
      <span class="sub"><?= (int)$nTips ?> 篇 · 教你把手上的浏览器用透</span>
      <a class="more" href="/tips">查看全部 →</a>
    </div>
    <div class="hc-posts">
      <?php foreach ($tips as $t): $sl = preg_replace('/^tips__/', '', (string)$t['slug']); ?>
      <a class="hc-post" href="/tips/<?= e($sl) ?>">
        <span class="cat">浏览器技巧</span>
        <span class="t"><?= e((string)$t['title']) ?></span>
        <?php $d = trim(strip_tags((string)($t['description'] ?? ''))); if ($d !== ''): ?>
        <span class="d"><?= e($d) ?></span>
        <?php endif; ?>
        <span class="m"><?= e(substr((string)($t['published_at'] ?? ''), 0, 10)) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ═══ ⑥ 深色 CTA（简化） ═══ -->
  <div class="hc-cta">
    <div class="txt">
      <h3><?= (int)$nTools ?> 个工具，不用注册、不用装东西</h3>
      <p>不需要服务器的工具逐个标上「本机处理 · 不上传」；走服务器的工具，如实写在它自己的页面上。</p>
    </div>
    <div class="acts">
      <a class="hc-btn pri" href="/tool">打开工具箱</a>
      <a class="hc-btn" href="/links-health">看链接体检</a>
    </div>
  </div>

</div>
<script>
(function () {
  var a = document.getElementById('hcSearch'), q = document.getElementById('sthQ');
  if (!a || !q) return;
  a.addEventListener('click', function (e) {
    e.preventDefault();
    q.readOnly = false; q.focus(); q.select();
    try { q.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (err) { q.scrollIntoView(); }
    setTimeout(function () { q.readOnly = true; }, 0);
  });
})();
</script>
