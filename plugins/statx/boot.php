<?php
declare(strict_types=1);
/**
 * StatX · 前台注册（能力① 的端点挂载点）
 *
 * 由 `plugin.php` 的 `register_file` 声明，`Plugins::boot()` 在 **Router 之前、全页缓存之前**
 * `require_once`（先例：plugins/recite/boot.php 拦 /tts、plugins/reader、plugins/guardweb）。
 * 因此：命中端点 → 自己输出并 exit，既不会进 Router，也**不会命中 var/cache/pages 全页缓存**。
 *
 * ★ 成本纪律：**非端点请求只做一次 rt.php include（opcache）+ 2~3 次字符串比较**，
 *   不碰 DB、不解析 GET 之外的任何东西、不注册任何输出缓冲。
 * ★ 端点请求才会 require endpoint.php；endpoint.php 全程零 SQL（见该文件头注释）。
 * ★ 采集总开关：`statx.collect.enabled` 写进 rt.php（由 admin/CLI 生成）。rt.php 缺失时
 *   只在「看起来就是端点请求」的那一次自愈生成，其余请求零成本。
 *
 * ★★ 2026-09-14 事故后新增：**公共目录安全自检**（只在端点请求上跑，普通页面零成本）。
 *   事故：本插件落库路径命中临时目录兜底时，对 `dirname()`（= `/tmp`）无条件 chmod 0750 +
 *   chown www:www ⇒ 同机 MySQL（tmpdir=/tmp）signal 11 崩溃、4 个站 500、持续 1,077 秒。
 *   现在除了在 sdb.php 里上闸门，还在这里**主动巡检**并留痕：
 *     · 每次端点请求都判一次「本插件解析出的库目录是不是公共目录」（纯字符串判断，极廉价）；
 *     · 判为公共目录 ⇒ 写入 `var/statx/sdb-safety-warn.txt` 并在插件目录留下记号，
 *       由后台「系统环境」/`cli.php --task=env` 立即显示（另有 `--task=env` 的完整自检）。
 *     · 完整自检（含 stat 公共目录权限、库文件权限）每 60 秒最多一次（节流），结果缓存复用。
 */

if (!defined('YC_VERSION')) { return; }   // 只应由 index.php/bootstrap 载入；被 Web 直接访问时安静退出

/* ============================================================================
 * ★ 2026-10-17【第27棒 · 站主指令】「把后台调出来，统计插件默认是不需要安装的」
 *   ⇒ 本段原有两件事已**删除**（不是注释）：
 *     ① GET /admin/ 且 s 为空/dashboard 时 302 → /admin/?s=plug_statx；
 *     ② s=plug_statx* 时置 $GLOBALS['YC_ADMIN_FOCUS']=true（整条内核 <aside> 不渲染）。
 *   ⇒ /admin/ 回到内核默认面板，左侧 23 项菜单全显示。
 *   ★ 同时本插件已停用：option plugin.statx.enabled=0（统计插件默认不装）。
 *   回滚（一行）：
 *     cp -a /root/ta27/backup/statx_boot.php.orig /www/wwwroot/liulanqi-yecha/plugins/statx/boot.php \
 *       && php /root/ta27/t27_opt_off.php restore
 * ========================================================================== */

/* ============================================================================
 * ★★ r11【隐藏宿主后台外壳顶部】—— 站长 2026-09-17 指令：
 *      「统计系统顶部，欢迎回来 / 查看站点 / 这些 yecha.org 后台的顶部不需要」
 *
 * 现况（r11 实测，详见报告的结构表）：内核 `admin/index.php::adm_render()`（第 619 行起）把
 * 顶部**硬编码**输出成 `<header>…</header>`，内容四件：
 *   · `<a class="logo">`  YeCha 夜叉 · 后台（站点 Logo）
 *   · `<a class="c" href="/">` 查看站点 ↗        ← 本独立实例里 `/` 是 404
 *   · `博主：<display_name>`（= 站长看到的那块站点/身份信息）
 *   · `<a class="c" href="/admin/?action=logout">退出`
 * 另外内核把 `<title>` 写死成 `<display_name> · YeCha 后台`（同函数），
 * 以及登录后落地的第一条提示 `$msg`（`欢迎回来，<display_name>`，`admin/index.php:40`）。
 * ★ 这四处**内核没有提供任何过滤器/变量**给插件 ⇒ 纯插件层只能从**输出流**与**会话**下手。
 *
 * 所以本段做三件事，全部**只在这个选项打开时**（`statx.ui.hide_host_chrome=1`）生效：
 *   ① `body` 加一个作用域类 `stx-hidechrome`，由插件样式表（stxui.php::stx_css()）把
 *      `body.stx-hidechrome > header` 整条 `display:none`（**零 JS**，红线 7）；
 *   ② 输出缓冲里**只替换** `<title>…</title>` 这一处（字符串精确匹配，不中即不动）；
 *   ③ 清掉内核刚写入的「欢迎回来」落地提示（登录跳转那一次），**只匹配这一条**，
 *      其它提示（设置已保存 / 检测结果…）一律保留。
 *
 * ★★ 默认（选项 = 0）时本段在**同一个字符比较**上就返回：
 *    不改 body、不改标题、不动会话 —— 别人装到自己站上的体验与本版**逐字节一致**。
 *    这就是红线 1 要求的「默认不影响别人」。
 *
 * 作用域：只认 `/admin/` 前缀，其它任何请求（前台、端点、CLI）零成本。
 * 成本：admin 请求 +1 次 ob_start + 2 次字符串替换（页面约 38KB）。
 * ★ 这里**只能**用内核的 `get_option()`：实测 `statx/boot.php` 是在 `Plugins::boot()` 里被 require 的，
 *   而 `statx_opt()` 来自 `statx/inc.php` —— 那一刻**还没加载**（`function_exists('statx_opt')` = false）。
 *   写 `statx_opt()` 会直接 fatal。`get_option()` 由内核 `core/bootstrap.php:54` 提供，始终可用，
 *   且读的是同一张 `ye_options` 表、同一个 `statx.ui.hide_host_chrome` 键。
 * 回滚：`--task=ui --hide-host-chrome=0` 即可全量还原；再退一步删掉本 BEGIN…END 整段。
 * ========================================================================== */
if (isset($_SERVER['REQUEST_URI'])) {
    $__st_hcPath = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (($__st_hcPath === '/admin' || $__st_hcPath === '/admin/' || str_starts_with($__st_hcPath, '/admin/'))
        && (string)get_option('statx.ui.hide_host_chrome', '0') === '1') {
        /* ③ 清掉「欢迎回来，…」这一条落地提示（只匹配这一条前缀；内核写法见 admin/index.php:40） */
        if (isset($_SESSION['flash']) && is_string($_SESSION['flash'])
            && str_starts_with($_SESSION['flash'], '欢迎回来')) {
            unset($_SESSION['flash']);
        }
        /* ①② 输出缓冲：给 body 加作用域类 + 精确改写 <title> */
        ob_start(static function (string $__st_hcBuf): string {
            $__st_hcTitle = '统计 · ' . (string)get_option('site.name', 'StatX');
            /* ① 整页只有 1 个 <title>（内核 adm_render()），限一次替换；没命中就原样返回 */
            $__st_hcBuf = preg_replace_callback(
                '~(<title>)(.*?)(</title>)~su',
                static fn(array $m): string => $m[1] . htmlspecialchars($__st_hcTitle, ENT_QUOTES) . $m[3],
                $__st_hcBuf, 1) ?? $__st_hcBuf;
            /* ② 给 <body> 加作用域类（原来的属性一个不动）。
             *    ★ 用回调而不是 '<body class="…"$1>' —— 后者在 body 没有其它属性时
             *      `$1` 为空，会多出一个悬空空格（`<body class="x" >`），虽然合法但没必要。 */
            $__st_hcBuf = preg_replace_callback('~<body(\s[^>]*)?>~', static function (array $m): string {
                $attrs = isset($m[1]) ? rtrim($m[1]) : '';
                return '<body class="stx-hidechrome"' . ($attrs !== '' ? ' ' . ltrim($attrs) : '') . '>';
            }, $__st_hcBuf, 1) ?? $__st_hcBuf;
            return $__st_hcBuf;
        });
    }
    unset($__st_hcPath);
}
/* ── statx-hide-host-chrome END ─────────────────────────────────────────── */

/* ---- 公共目录安全自检（节流 60s；结果写 var/statx/sdb-safety.json）---- */
$__st_safety = static function (): void {
    $dir = __DIR__ . '/../../../var/statx';
    $stamp = $dir . '/sdb-safety.json';
    $now = time();
    $last = @filemtime($stamp);
    if (is_int($last) && ($now - $last) < 60) { return; }   // 节流：最多每 60 秒一次
    require_once __DIR__ . '/sdb.php';
    $a = statx_sdb_security_audit();
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $rec = [
        'at'      => date('c', $now),
        'ok'      => (bool)$a['ok'],
        'path'    => (string)$a['path'],
        'source'  => (string)$a['source'],
        'warn'    => (string)$a['warn'],
        'failed'  => array_values(array_map(
            static fn(array $c): string => (string)$c['id'] . ': ' . (string)$c['detail'],
            array_filter($a['checks'], static fn(array $c): bool => !$c['ok'])
        )),
    ];
    @file_put_contents($stamp, (string)json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    if (!$a['ok']) {
        // 留一条显眼的痕迹，便于运维 `ls` 就发现（不输出到页面、不改 HTTP 行为）
        @file_put_contents($dir . '/sdb-safety-WARN.txt',
            $rec['at'] . "\n" . $rec['warn'] . "\n" . implode("\n", $rec['failed']) . "\n", LOCK_EX);
    }
};

$__st_rt = @include __DIR__ . '/rt.php';

// ★ 2026-09-14：包里带的是**未初始化占位 rt.php**（_stub=1, on=0）—— 「下载解压」不会让任何数据
//   流向任何地方；首次配置（后台保存 / collect-init）时才会生成真配置。
$__st_stub = is_array($__st_rt) && !empty($__st_rt['_stub']);
if (!is_array($__st_rt) || $__st_stub) {
    // 未初始化：仅在疑似端点请求时自愈一次（默认路径），避免给普通页面增加任何开销
    $__st_p0 = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($__st_p0 !== '/st' && $__st_p0 !== '/st.js' && $__st_p0 !== '/s3.js' && !isset($_GET['st'])) {
        return;
    }
    require_once __DIR__ . '/inc.php';
    statx_collect_rt_write();
    $__st_rt = @include __DIR__ . '/rt.php';
    if (!is_array($__st_rt) || !empty($__st_rt['_stub'])) { return; }
}

if ((int)($__st_rt['on'] ?? 0) !== 1) { return; }   // 采集已关闭 → 端点整体不存在

$__st_path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$__st_q    = (string)($_GET['st'] ?? '');

$__st_is_hit    = ($__st_path === (string)($__st_rt['path'] ?? '/st'))   || $__st_q === 'h';
$__st_is_script = ($__st_path === (string)($__st_rt['script'] ?? '/st.js'))
               || ($__st_path === (string)($__st_rt['compat'] ?? '/s3.js'))
               || $__st_q === 'js';

if (!$__st_is_hit && !$__st_is_script) {
    unset($__st_rt, $__st_path, $__st_q, $__st_is_hit, $__st_is_script, $__st_safety);
    return;
}

// ★ 确认是端点请求才跑自检 ⇒ **普通页面零成本**（也绝不会因自检而多一次 stat）
$__st_safety();
unset($__st_safety);

unset($__st_path, $__st_q);
$__st_hit    = $__st_is_hit;      // ⚠️ 必须在用之前先留存：早先在这里把标志一起 unset 掉，
$__st_script = $__st_is_script;   //    导致 if ($__st_is_hit) 恒为假 → /st 被当成脚本路径（已修）
unset($__st_is_hit, $__st_is_script);

require_once __DIR__ . '/endpoint.php';

if ($__st_hit) {                       // 上报端点（POST / GET / OPTIONS），no-store
    unset($__st_rt, $__st_script);
    statx_ep_cors();
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') { statx_ep_preflight(); }
    statx_ep_hit();
}

unset($__st_rt, $__st_hit);
statx_ep_script();                     // 采集脚本（长缓存 + 版本串 + 可跨域加载）
