<?php
declare(strict_types=1);
/**
 * StatX · 采集端点实现（能力① 的看家本领）
 * ★★ 本文件在整个请求里**绝不执行任何 SQL**（不 connect、不 SELECT、不 INSERT）：
 *    · 运行参数全部来自同目录的 rt.php（`<?php return [...]`，由 admin/CLI 生成，走 opcache）
 *    · 访客标识用 config.inc.php 里的 SECRET 常量（已在内存）派生，不查库
 *    · 命中先**追加写一行**到 /tmp 的 spool 文件；真正的批量 INSERT 交给
 *      `cli.php --task=collect-rollup`（cron，每分钟）→ 所以端点既不慢也不打爆存储
 * ★ 本文件由 boot.php **在命中端点路径时才 require**，其余请求零解析成本。
 * ★ 依赖：botlib.php（爬虫三级库）、core 的 cfg()/e() 等纯函数（已由 index.php 载入）。
 *
 * ★ 批次 1（基础层）：本文件只做「把新字段**原样收下**并落到 spool」，存储层在 sdb.php（SQLite）。
 *   spool 行格式 **v3**（制表符分隔，28 列；v2 的 24 列与 v1 的 10 列仍可被 collect.php 识别，
 *   见 statx_collect_flush）：
 *     0  v3          版本标记
 *     1  ts          unix 秒
 *     2  site        站点 key
 *     3  host        站点 host
 *     4  path        rawurlencode(path)（查询串已在服务端剥掉）
 *     5  kind        pv | cl | ev
 *     6  ref_kind    none | in（站内）| out（站外）
 *     7  ref_domain  来源域名
 *     8  ref         rawurlencode(完整 referrer URL，≤500)
 *     9  device      desktop | mobile | tablet | bot
 *     10 cls         human | se | ai | scraper | empty | other
 *     11 bot_name    命中的特征名
 *     12 vhash       不可逆访客指纹（sha256 前 16 位，逐日轮换盐；**不含明文 IP**）
 *     13 sid         会话 ID（客户端，30 分钟无活动即换）
 *     14 uid         长期随机访客 ID（客户端 localStorage；**不由 IP 派生**）
 *     15 w 16 h      screen 宽高（分辨率分布，tj 同口径）
 *     17 vw 18 vh    viewport 宽高（热图坐标归一化用）
 *     19 lang        语言
 *     20 en          事件名（data-event 优先）
 *     21 x 22 y      点击坐标（仅 kind=cl）
 *     23 utm         `utm_source=…&utm_medium=…`（无参数时为空串）
 *     —— 以下 4 列是**批次 2（界面层）新增**，只为一件事：让看板能只靠 SQL 聚合 ——
 *     24 region      地域（`国家|省|市|ISP|CC`，**由 IP 现算、IP 本身不落盘**）
 *     25 browser     浏览器族
 *     26 os          操作系统族
 *     27 ipver       4 | 6 | 0（IPv4/IPv6/未知）—— 证明 IPv6 不再是静默空桶
 */

if (!defined('YC_VERSION')) { http_response_code(404); exit; }   // 禁止被 Web 直接当脚本执行

require_once __DIR__ . '/botlib.php';
require_once __DIR__ . '/derive.php';   // 批次 2：UA → 浏览器/操作系统族（纯函数，零副作用）
require_once __DIR__ . '/geo.php';      // 批次 2：IP → 地域（ip2region xdb 读取器，失败即返回空串）

/* ============================== 运行参数 ============================== */

/** 读 rt.php（生成器见 collect.php: statx_collect_rt_write） */
function statx_ep_rt(): array
{
    static $rt = null;
    if ($rt !== null) { return $rt; }
    $f = __DIR__ . '/rt.php';
    $r = @include $f;
    $rt = is_array($r) ? $r : [];
    return $rt;
}

/** 采集脚本字节（单一来源：同目录 st.js；/st.js 与静态兜底完全同源） */
function statx_ep_js(): string
{
    static $js = null;
    if ($js !== null) { return $js; }
    $f = __DIR__ . '/st.js';
    $js = is_file($f) ? (string)file_get_contents($f) : '';
    return $js;
}

/* ============================== 响应 ============================== */

/**
 * 端点收尾前的清理：
 *   ① core/bootstrap.php 在 Plugins::boot() **之前**已经 session_start()（内核行为，插件无法阻止）
 *      —— 端点这里 session_abort()：既不写会话、也不持有会话锁，更**绝不销毁**访客已有会话
 *   ② header_remove('Set-Cookie')：端点**从不设置任何 Cookie**（README 的「无 Cookie」由此成立）
 *      —— 批次 1 用的 localStorage 也**不是 Cookie、不进请求头**，站点侧同样不留痕
 *   ③ 去掉 session cache limiter 留下的 Pragma/Expires（否则与长缓存语义打架）
 */
function statx_ep_detach(): void
{
    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
        @session_abort();                 // 只丢弃「本次请求的会话变更」，不销毁会话本身
    }
    if (!headers_sent()) {
        header_remove('Set-Cookie');
        header_remove('Pragma');
        header_remove('Expires');
    }
}

/** 1×1 透明 GIF（43 B）—— 兼作 <img> 回执与 beacon 的 200 响应体 */
function statx_ep_gif(): string
{
    return "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,"
         . "\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";
}

/**
 * CORS：**按白名单回显 Origin**，绝不用 `*`。
 * 白名单来自 rt.php 的 origins（由各站登记的 host 生成），因此只能被自己的站点嵌入。
 */
function statx_ep_cors(): void
{
    $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($o === '') { return; }
    $allow = statx_ep_rt()['origins'] ?? [];
    if (is_array($allow) && in_array($o, $allow, true)) {
        header('Access-Control-Allow-Origin: ' . $o);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
}

/** 预检：直接 204，不落库、不读库（站长要求：别让页面等） */
function statx_ep_preflight(): never
{
    statx_ep_detach();
    statx_ep_cors();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Statx: preflight');
    http_response_code(204);
    exit;
}

/** 立即结束（no-store：绕开全页缓存 / CF 缓存，绝不被缓存吞掉） */
function statx_ep_done(string $marker = 'ok', int $code = 200): never
{
    statx_ep_detach();
    statx_ep_cors();
    header('Content-Type: image/gif');
    header('Content-Length: 43');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Statx: ' . $marker);
    http_response_code($code);
    echo statx_ep_gif();
    exit;
}

/* ============================== 采集 ============================== */

/** 只保留站点 key / host 允许的字符（不做任何 DB 校验 → 端点可零查询短路） */
function statx_ep_token(string $s, int $max = 64): string
{
    $s = trim($s);
    if ($s === '' || strlen($s) > $max) { return ''; }
    return preg_match('~^[A-Za-z0-9._:@\-]+$~', $s) ? $s : '';
}

/** 路径清洗：**剥掉查询串**（可能含搜索词 / token），只留 path，截断 255 */
function statx_ep_path(string $raw, int $max = 255): string
{
    $raw = trim($raw);
    if ($raw === '') { return '/'; }
    $p = parse_url($raw, PHP_URL_PATH);
    if (!is_string($p) || $p === '') { $p = '/'; }
    $p = rawurldecode($p);
    $p = preg_replace('~[\x00-\x1f\x7f]+~', '', $p) ?? '/';
    if ($p === '' || $p[0] !== '/') { $p = '/' . ltrim($p, '/'); }
    return mb_substr($p, 0, $max);
}

/**
 * 通用文本清洗（批次 1 新增）：**去掉一切控制字符（含制表符/换行）**，再截断。
 * 必要性：spool 是**按行拼接**的（`\t` 分列、`\n` 分行），任何一个字段里混进控制字符都会
 * 破坏格式 ⇒ 在入库前就地清除（存储侧还会再洗一遍，双保险）。
 */
function statx_ep_txt(string $s, int $max): string
{
    $s = preg_replace('~[\x00-\x1f\x7f]+~', '', trim($s)) ?? '';
    return $max > 0 ? mb_substr($s, 0, $max) : $s;
}

/** 整数清洗（批次 1 新增）：非数字一律当 0，越界夹紧（防止伪造上报写脏库/撑爆体积） */
function statx_ep_int(string $s, int $min, int $max): int
{
    $s = trim($s);
    if ($s === '' || !preg_match('~^-?\d{1,9}$~', $s)) { return 0; }
    $n = (int)$s;
    if ($n < $min) { return $min; }
    if ($n > $max) { return $max; }
    return $n;
}

/** 事件名清洗：只留可读字符（data-event 由站长自己写，但仍是外部输入） */
function statx_ep_event(string $s): string
{
    $s = preg_replace('~[^\p{L}\p{N}_.:\- ]+~u', '', statx_ep_txt($s, 64)) ?? '';
    return mb_substr(trim($s), 0, 48);
}

/**
 * 完整 referrer 清洗（批次 1 新增）：**只接受 http/https 的绝对 URL**，去掉控制字符，截断 500。
 * ★ 隐私说明：这是**来源站自己给出的** URL（浏览器 Referer），不是访客身份；本插件仍然**不存 IP**。
 *   （tj 存完整 referrer，statx 老版只留域名；站点侧决定了放宽到完整 URL —— 见 README 隐私模型）
 *
 * ★★ r12x 加固（站长 2026-09-18「要统计到带参数的页面地址」同一批次里必须做的隐私配套）：
 *   ① **长度上限 500 字符**（任务红线要求 ≤512；取 500 与 collect.php 的入库截断一致，
 *      实测库内 `MAX(LENGTH(ref))` = 282，所以上限不是当前瓶颈）；
 *   ② **剥离敏感参数** —— token/access_token/refresh_token/id_token/api_key/secret/key/jwt/
 *      auth/password/session/sid/csrf/signature/otp/code/email/phone/username/account/uid …
 *      （完整名单见 `derive.php::statx_ref_sensitive_params()`）。**只删这些参数**，
 *      路径与其它参数（含引擎关键词 `?q=…`）原样保留 —— 这是"能看到搜索词"与"不泄漏凭据"
 *      能同时成立的关键。一个都没命中时返回值与入参字节完全相同（不重写 URL）。
 *   ③ **不存 UA、不存 IP** —— 与本插件既有隐私模型一致，本次**未改动**（UA 只进 visitor_hash）。
 *   ⚠️ 只对**以后新写入**的行生效；既有历史行一行都不动（红线：不许改统计数据行）。
 */
function statx_ep_ref(string $raw, int $max = 500): string
{
    $raw = statx_ep_txt($raw, $max * 2);
    if ($raw === '') { return ''; }
    $scheme = strtolower((string)parse_url($raw, PHP_URL_SCHEME));
    if ($scheme !== 'http' && $scheme !== 'https') { return ''; }
    // ★ r12x：先剥敏感参数再截断。剥只会变短，所以"先剥后截"与"先截后剥"在 ≤500 时等价，
    //   但先剥能避免把一个超长 token 截断后留下一段"半截 token"（那反而更像凭据）。
    return mb_substr(statx_ref_strip_sensitive($raw), 0, $max);
}

/** utm 打包（批次 1 新增）：`utm_source=…&utm_medium=…`，值做 rawurlencode，整体不含 `\t` */
function statx_ep_utmpack(): string
{
    $out = [];
    foreach (['source', 'medium', 'campaign', 'term', 'content'] as $k) {
        $v = statx_ep_txt((string)($_GET['utm_' . $k] ?? ''), 100);
        if ($v !== '') { $out[] = 'utm_' . $k . '=' . rawurlencode($v); }
    }
    return implode('&', $out);
}

/**
 * 不可逆访客标识：sha256(SECRET | 日 | 站点 | IP | UA) 前 16 位十六进制。
 * · 原始 IP 与 UA **只进哈希、绝不落库、绝不落盘**（tj 的教训：hits.ip 明文）
 * · 盐**每天轮换**（掺入日期）→ 跨天不可关联 ⇒ 天然「不做跨站/跨天身份打通」
 * · 批次 1 起它的职责**收窄为「限流/防灌 + 当日去重」**：跨天可关联性由客户端随机 `uid` 承担
 *   （uid 是客户端随机值、不含任何个人信息，见 README 隐私模型）
 */
function statx_ep_vhash(string $site, string $ip, string $ua): string
{
    $secret = (string)cfg('SECRET', 'yecha');
    return substr(hash('sha256', $secret . '|' . date('Y-m-d') . '|' . $site . '|' . $ip . '|' . $ua), 0, 16);
}

/** 解析站点 key：优先 data-site，其次 tj 老 token 映射（rt.tjmap，用于 /s3.js 平滑迁移） */
function statx_ep_site(string $raw): string
{
    $k = statx_ep_token($raw, 64);
    if ($k === '') { return ''; }
    $map = statx_ep_rt()['tjmap'] ?? [];
    if (is_array($map) && isset($map[$k]) && $map[$k] !== '') { return (string)$map[$k]; }
    return $k;
}

/**
 * 追加一行到当日 spool。返回 'ok' | 'off' | 'rate' | 'cap' | 'link' | 'err'
 * 全程零 SQL；写失败绝不影响页面（调用方已经 exit 过）。
 */
function statx_ep_spool(array $f): string
{
    $rt  = statx_ep_rt();
    // ★ 2026-09-14：老实现第二处写死 '/tmp/statx-spool'（与 collect.php 的默认值各写一份，
    //   容易漂移）。spool 目录由 rt.php 携带，rt.php 一定带值；这里只留一个惰性兜底。
    $dir = (string)($rt['spool'] ?? '');
    if ($dir === '') { $dir = rtrim(sys_get_temp_dir(), '/') . '/statx-spool'; }
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    if (!is_dir($dir)) { return 'err'; }

    $base = $dir . '/' . $f['site'] . '-' . date('Ymd', $f['ts']) . '.log';
    if (is_link($base)) { return 'link'; }                 // 防本地符号链接攻击
    if (is_file($base) && filesize($base) > (int)($rt['spool_max'] ?? 33554432)) { return 'cap'; }

    $line = implode("\t", $f['cols']) . "\n";               // v3 固定 28 列，见文件头
    $fp = @fopen($base, 'ab');
    if ($fp === false) { return 'err'; }
    $ok = @flock($fp, LOCK_EX);
    $n  = @fwrite($fp, $line);
    if ($ok) { @flock($fp, LOCK_UN); }
    @fclose($fp);
    return ($n === strlen($line)) ? 'ok' : 'err';
}

/**
 * 处理一次命中（GET / POST 均可）：
 *   probe=1 → 只回执不落盘（供「一键写入」自检：证明端点在不该写库时**一次查询都不做**）
 *
 * 批次 1 新增的采集参数（全部可选，缺省时不影响老行为）：
 *   si=sid  ui=uid  k=pv|cl|ev  sw/sh=screen  vw/vh=viewport  lg=lang  en=事件名  x/y=点击坐标  utm_*
 */
function statx_ep_hit(): never
{
    $rt = statx_ep_rt();
    if (($rt['on'] ?? 0) !== 1) { statx_ep_done('off', 200); }

    // 站点 key（data-site 或 tj 老 token）
    $site = statx_ep_site((string)($_GET['s'] ?? ''));
    if ($site === '') { statx_ep_done('bad-site', 200); }

    // 显式自检：不写盘、不查询
    if ((string)($_GET['probe'] ?? '') !== '') { statx_ep_done('probe-ok', 200); }

    $host = statx_ep_token((string)($_GET['h'] ?? ''), 160);
    if ($host === '') { $host = statx_ep_token((string)($_SERVER['HTTP_HOST'] ?? ''), 160); }

    $ua   = statx_bot_ua();
    $bot  = statx_bot_classify($ua);
    $refU = statx_ep_ref((string)($_GET['r'] ?? ''));
    $ref  = statx_ref_split($refU, $host);
    $ip   = (string)($_SERVER['REMOTE_ADDR'] ?? '');        // nginx 已按 CF 段还原真 IP；不信任任何请求头
    $ts   = time();
    $kind = (string)($_GET['k'] ?? 'pv');
    if (!in_array($kind, ['pv', 'cl', 'ev'], true)) { $kind = 'pv'; }

    // ★ 批次 2：地域在这里算 —— 这是整个链路里**唯一还看得到 IP 的一刻**
    //   （算完只把「地域字符串」写进 spool，IP 本身绝不落盘、绝不进 spool；失败一律空串）
    $region = statx_ep_txt(statx_geo_lookup($ip), 64);
    [$browser, $os] = statx_derive_ua($ua);

    $cols = [
        'v3',
        $ts,
        $site,
        $host,
        rawurlencode(statx_ep_path((string)($_GET['p'] ?? '/'))),
        $kind,
        $ref['kind'],
        $ref['domain'],
        rawurlencode($refU),
        statx_device($ua, (bool)$bot['is_bot']),
        (string)$bot['cls'],
        (string)$bot['name'],
        statx_ep_vhash($site, $ip, $ua),
        statx_ep_txt((string)($_GET['si'] ?? ''), 32),      // sid
        statx_ep_txt((string)($_GET['ui'] ?? ''), 32),      // uid
        statx_ep_int((string)($_GET['sw'] ?? ''), 0, 20000),// screen w
        statx_ep_int((string)($_GET['sh'] ?? ''), 0, 20000),// screen h
        statx_ep_int((string)($_GET['vw'] ?? ''), 0, 20000),// viewport w
        statx_ep_int((string)($_GET['vh'] ?? ''), 0, 20000),// viewport h
        statx_ep_txt((string)($_GET['lg'] ?? ''), 16),
        statx_ep_event((string)($_GET['en'] ?? '')),
        statx_ep_int((string)($_GET['x'] ?? ''), 0, 100000),
        statx_ep_int((string)($_GET['y'] ?? ''), 0, 100000),
        statx_ep_utmpack(),
        $region,                                            // 批次 2：地域（IP 现算，IP 不落盘）
        $browser,                                           // 批次 2
        $os,                                                // 批次 2
        statx_derive_ipver($ip),                            // 批次 2：4 / 6 / 0
    ];

    $st = statx_ep_spool([
        'ts'   => $ts,
        'site' => $site,
        'cols' => $cols,
    ]);
    statx_ep_done('hit-' . $st . '-' . $kind . ($bot['is_bot'] ? '-bot' : ''), 200);
}

/* ============================== 采集脚本 ============================== */

/**
 * 返回采集脚本。默认**长缓存 + 版本串**（版本串来自 rt.php 的 jsver = st.js 内容哈希前 8 位，
 * 因此脚本一改、注入的 `?v=` 也变 → 浏览器必然拿到新版本）。
 */
function statx_ep_script(): never
{
    $js = statx_ep_js();
    if ($js === '') { http_response_code(404); header('Cache-Control: no-store'); exit; }
    $rt  = statx_ep_rt();
    $ver = (string)($rt['jsver'] ?? '');
    $q   = (string)($_GET['v'] ?? '');
    $imm = ($ver !== '' && $q === $ver);
    $ttl = $imm ? 31536000 : 3600;

    statx_ep_detach();
    header('Content-Type: application/javascript; charset=utf-8');
    header('Content-Length: ' . strlen($js));
    header('Cache-Control: ' . ($imm
        ? 'public, max-age=31536000, immutable'
        : 'public, max-age=3600, must-revalidate'));
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT');
    header('ETag: "' . $ver . '"');
    header('X-Statx: js' . ($imm ? '-immutable' : ''));
    header('X-Content-Type-Options: nosniff');
    statx_ep_cors();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') { exit; }
    echo $js;
    exit;
}
