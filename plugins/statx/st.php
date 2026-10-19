<?php
declare(strict_types=1);
/**
 * StatX · 独立入口（standalone entry）—— **不依赖内核 `Plugins::boot()` 的兜底通道**
 * =====================================================================================
 * 为什么存在（2026-09-14 定案，务必先读）：
 *
 *   YeCha 内核 `core/Plugins.php::compat()` 的版本归一有两个缺陷合在一起：
 *     ① 预发布后缀可能是**空格**分隔（`YC_VERSION = '1.0 Beta Build20260910'`），
 *        原正则 `/[-+].*$/` 去不掉空格后缀；
 *     ② 只按空白切还会剩 `'1.0'`，而 `version_compare('1.0','1.0.0','>=')` **仍是 false**。
 *   后果：**每一个第三方插件**（凡是写了 `requires.core` 的）在 `Plugins::boot()` 里被
 *   `continue` 跳过 ⇒ 本插件的 `register_file`（= `boot.php`）**从未被 require**
 *   ⇒ `/st`、`/st.js` 不存在，请求掉进内核路由 → 404 或 302 到首页 → 看板永远 0 PV。
 *
 *   这个缺陷存在于 **2026-09-10 的发行包**（`yecha-blog-1.0-beta-build20260910.zip`）里，
 *   所以**每个从 zip 下载安装的新站都必然踩**。内核侧已修（见 `themes/gushu/CORE-PATCH.md`），
 *   但「别人的站」可能装着各种版本的内核 —— 本文件让插件**不依赖那个修复也能活**。
 *
 * 本文件的第二个收益（一石二鸟）：**绕开内核 bootstrap 的强制 MySQL 开销**。
 *   `core/bootstrap.php` 在每个请求里都要起 PDO + 读选项表；而采集端点**本来零 SQL**
 *   （运行参数全在 rt.php）。走独立入口时端点不必付这份钱。
 *
 * 用法（三条，任选；效果相同）：
 *   A. 自动：`php install-standalone.php`（推荐；它在站点根放一个副本并打印 nginx 配置）
 *   B. 手动：把本文件复制到站点根（可改名，如 `stx.php`），再用下面两条 nginx 精确匹配：
 *        location = /st     { try_files $uri /stx.php?$query_string; }
 *        location = /st.js  { try_files $uri /stx.php?$query_string; }
 *      （也可以保持本文件在 plugins/statx/ 目录内不动，而把上面两行指向
 *        /plugins/statx/st.php —— 但**必须**同时确认 nginx 允许该目录下的 .php 执行，
 *        宝塔默认是拒绝的，所以复制到站点根更稳。）
 *   C. 不想动 nginx：直接用查询串形式 —— `<script async src="https://你的域名/stx.php?st=js"></script>`
 *      上报端点是 `...stx.php?st=h`。功能完全相同，只是 URL 不好看。
 *
 * ★ 安全：本文件只做端点路由；既不是「谁都能调用的后台」，也不泄露任何数据。
 *   非 StatX 请求（缺少 st= / s= / h= 参数且路径不匹配）一律 **404 立即退出**。
 *   库位置、spool、白名单全部来自同目录的 rt.php（由 admin / collect-init 生成）。
 *
 * 设计纪律：
 *   · 只 `require` **纯函数**文件（botlib / derive / geo / sdb / collect）；**绝不** require
 *     `inc.php`（那里 `require` 了 MySQL 侧的 Db/Mail 依赖，且每个 `statx_opt()` 都要查库）。
 *   · 未初始化（rt.php 是占位 `_stub`）时，**只在确实像是端点请求的那一次**做一次自愈初始化；
 *     其余请求零成本。
 */

/* ------------------------------------------------------------------ 0. 内核模式短路 */
if (defined('YC_STATX_BOOTED')) { return; }   // 插件已被内核正常载入（register_file 生效）→ 本文件什么都不做

/* ------------------------------------------------------------------ 1. 站点根 & 内核常量 */
/** 站点根：本文件既可能躺在 <站点根>/ 下，也可能躺在 <站点根>/plugins/statx/ 下 */
$statx_sr = is_file(__DIR__ . '/config.inc.php')
    ? __DIR__
    : (is_file(dirname(__DIR__, 2) . '/config.inc.php') ? dirname(__DIR__, 2) : '');

if ($statx_sr === '') {
    // 找不到内核配置：本文件被放在了一个不是 YeCha 站点的地方 → 安静 404
    http_response_code(404);
    header('X-Statx: no-site-root');
    exit;
}

// 内核会在 bootstrap 里定义它；独立模式下我们自己给一个（endpoint.php 用它做「禁止被 Web 直接当脚本执行」判断）
if (!defined('YC_VERSION')) { define('YC_VERSION', 'standalone'); }
if (!defined('YC_STATX_STANDALONE')) { define('YC_STATX_STANDALONE', 1); }

/* ------------------------------------------------------------------ 2. 内核纯函数垫片 */
/* endpoint.php 只用到 cfg() 一个内核函数（YCHA 的 e() 在端点里其实没被调用）。
   这里按 `config.inc.php` 的常量语义实现同名函数，**不查库、不依赖内核**。 */
if (!function_exists('cfg')) {
    function cfg(string $k, mixed $def = null): mixed
    {
        if (defined($k)) { return constant($k); }
        return $def;
    }
}

/* ------------------------------------------------------------------ 3. 端点请求判定 */
$statx_p  = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$statx_p  = rtrim($statx_p, '/');            // '/st/' 视同 '/st'
if ($statx_p === '') { $statx_p = '/'; }
$statx_g  = (string)($_GET['st'] ?? '');     // 'h' = 上报，'js' = 脚本（与插件既有的 ?st= 口径一致）
$statx_self = '/' . basename(__FILE__);      // 本文件自己的公开路径（可能被改名）

// 只有「明确看着就是端点」的请求才继续；其余一律 404（避免本文件被当成任意脚本执行）
// ★ CORS 预检（OPTIONS）也必须算作命中端点：浏览器在真正 POST 之前会先发 OPTIONS /st，
//   那时**没有任何查询参数**，旧判据会把它误当脚本端点 → 拿不到 204 + ACAO，跨域上报会失败。
$statx_is_pre = (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS');
$statx_is_js  = ($statx_g === 'js') || ($statx_p !== '/' && ($statx_p === $statx_self) && isset($_GET['st']));
$statx_is_hit = ($statx_g === 'h')  || (isset($_GET['s']) && isset($_GET['h'])) || $statx_is_pre;
if ($statx_p === '/' && !$statx_is_js && !$statx_is_hit) {
    http_response_code(404);
    header('X-Statx: idle');                 // 本文件存在，但这次请求与 StatX 无关
    exit;
}

/* ------------------------------------------------------------------ 4. 装载纯函数层 */
foreach (array('botlib.php', 'derive.php', 'geo.php', 'sdb.php', 'collect.php') as $statx_f) {
    $statx_fp = __DIR__ . '/' . $statx_f;
    if (is_file($statx_fp)) { require_once $statx_fp; }
}
/* 若本文件被复制到了站点根，则插件文件在 plugins/statx/ 下 */
if (!function_exists('statx_collect_rt_path')) {
    $statx_pd = rtrim($statx_sr, '/') . '/plugins/statx';
    foreach (array('botlib.php', 'derive.php', 'geo.php', 'sdb.php', 'collect.php') as $statx_f) {
        $statx_fp = $statx_pd . '/' . $statx_f;
        if (is_file($statx_fp)) { require_once $statx_fp; }
    }
}
if (!function_exists('statx_collect_rt_read')) {
    http_response_code(500);
    header('X-Statx: missing-plugin-files');
    exit('StatX: plugin files not found');
}

$statx_dir = dirname((string)(new ReflectionFunction('statx_collect_rt_path'))->getFileName());
$statx_rt  = statx_collect_rt_read();

/* ------------------------------------------------------------------ 5. 未初始化 → 一次性自愈 */
$statx_stub = !is_array($statx_rt) || !empty($statx_rt['_stub']) || (string)($statx_rt['path'] ?? '') === '';
if ($statx_stub) {
    // 只在「确实是端点请求」时才动手（上面已判定），并把失败安静吞掉（宁可不采集，也不抛 500）
    try {
        statx_collect_ensure_schema();
        $statx_host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $statx_key  = '';
        if ($statx_host !== '') {
            $statx_key = mb_strtolower((string)preg_replace('~^www\.~', '', $statx_host));
            $statx_key = (string)preg_replace('~[^a-z0-9.\-]~', '', $statx_key);
        }
        if ($statx_key === '') { $statx_key = basename(rtrim($statx_sr, '/')); }
        if ($statx_key !== '' && function_exists('statx_collect_site_save')) {
            statx_collect_site_save($statx_key, array(
                'host'    => $statx_host,
                'label'   => $statx_key,
                'enabled' => 1,
                'sort'    => 100,
            ), true);
        }
        if (function_exists('statx_collect_rt_write')) { statx_collect_rt_write(); }
        $statx_rt = statx_collect_rt_read();
    } catch (Throwable $statx_e) {
        // 静默降级：端点先 200 回执，但明确标出「未初始化」
        $statx_rt = array('on' => 0, '_stub' => 1);
    }
    $statx_stub = !is_array($statx_rt) || !empty($statx_rt['_stub']);
}

/* ------------------------------------------------------------------ 6. 路由到真实端点实现 */
$statx_ep = $statx_dir . '/endpoint.php';
if (!is_file($statx_ep)) {
    http_response_code(500);
    header('X-Statx: missing-endpoint');
    exit('StatX: endpoint.php not found');
}
require_once $statx_ep;

// 与 boot.php 同口径：命中哪个端点
$statx_q   = (string)($_GET['st'] ?? '');
$statx_pre = (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS');
// ★ 自检请求（?probe=…）**不受采集总开关影响**：它是「端点是否被插件接住」的探针，
//   采集关着也必须能回答。顺序必须与 boot.php 一致 —— 先 probe，再 on 开关，再 OPTIONS，最后上报。
$statx_probe = ((string)($_GET['probe'] ?? '') !== '');
$statx_hit = ($statx_q === 'h') || (isset($_GET['s']) && isset($_GET['h'])) || $statx_pre || $statx_probe;
$statx_js  = ($statx_q === 'js') || (!$statx_hit && $statx_p !== '/');

if (!$statx_hit && !$statx_js) {
    statx_ep_script();                            // 兜底：当脚本端点处理
}

if ($statx_hit) {
    statx_ep_cors();
    // ① 显式自检：不写盘、不查询、不看 on 开关（与 statx_ep_hit() 内部同口径）
    if ($statx_probe) { statx_ep_done('probe-ok', 200); }
    // ② CORS 预检：204 + 白名单 ACAO，不落库、不读库
    if ($statx_pre) { statx_ep_preflight(); }
    // ③ 采集未启用：仍然是合法回执，只是不落盘
    if ((int)($statx_rt['on'] ?? 0) !== 1) { statx_ep_done('off', 200); }
    statx_ep_hit();
}

statx_ep_script();
