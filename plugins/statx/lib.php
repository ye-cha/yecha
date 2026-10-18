<?php
declare(strict_types=1);
/**
 * StatX · 能力②【站点可访问性健康检测】—— 探针引擎（纯逻辑，不依赖后台）
 *
 * 探针红线（逐条来自 recon 实测 + 本次复测，实现里都有对应代码）：
 *   ① 绝不请求 /p.gif（会写 tj 的库、污染统计、虚增流量）—— 本文件不含任何 /p.gif 逻辑
 *   ② /healthz 是"假绿陷阱"（guardweb 显式跳过 + 浅检查不碰 DB/不渲染模板）
 *      → 只作为 kind='healthz' 的辅助目标，绝不作为站点可用的主判据
 *   ③ 必须带真实浏览器 UA；撞到 guard 标记要报「探针配置故障」而不是「站点故障」
 *   ④ URL 必须带 cache-buster（/s3.js 实测 cf-cache-status: HIT / age 55991）
 *   ⑤ 429 必须单独识别为「被限频」，不计失败且翻倍间隔
 *   ⑥ 每站每轮 ≤3 请求（1 次首探 + 最多 2 次重试）、间隔 ≥60s、并发 ≤2（硬上限，代码里 clamp）
 *   ⑦ TLS 测公网边缘证书（capture_peer_cert），不读源站 pem
 *   ⑧ 有副作用 / 需凭据的路径一律不探（无 /admin、无任何写操作）
 *   ⑨ 运行环境：nice -n 19 ionice -c3，跑在 YeCha 机（统计机只有 1vCPU/846MB，不加负载）
 */

if (!defined('STATX_VERSION')) { define('STATX_VERSION', '0.9.0'); }

/** 并发硬上限（红线：并发 ≤2） */
const STATX_MAX_CONCURRENCY = 2;
/** 单目标单轮请求上限（红线：每站每轮 ≤3） */
const STATX_MAX_ATTEMPTS = 3;
/** 响应体解码后最多保留的字节数（用于内容标记匹配与体积判定） */
const STATX_BODY_CAP = 1048576;

/** 真实浏览器 UA（guardweb 的 is_scraper() 会拦 python-requests/curl/wget/go-http-client 等） */
function statx_ua(): string
{
    return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36';
}

/** 加 cache-buster（破 Cloudflare 缓存；每次调用都不同 → 重试也算"新的 URL"） */
function statx_bust(string $url): string
{
    $sep = str_contains($url, '?') ? '&' : '?';
    return $url . $sep . '_h=' . dechex(time()) . dechex(random_int(0x1000, 0xffff));
}

/**
 * 禁止标记（命中即异常）。只在响应体的**前 8 KB** 内匹配 ——
 * 避免长正文里偶然出现 "Warning: " 之类的假阳性。
 * 返回 [正则, 错误类别, 是否属于「探针配置故障」]
 */
function statx_forbid_rules(): array
{
    return [
        // 自家 guardweb 拦了探针 → 是探针配置问题，不是站点坏了（★ 关键设计）
        ['~Access denied by guard~i',                                        'probe_config',  true],
        // CF 盾 / 挑战页
        ['~Just a moment\.\.\.|Attention Required!|Checking your browser~i',  'cf_challenge',  true],
        // Cloudflare 源站错误（teayear.com 实测 520）
        ['~Error 5(20|21|22|23|24|25|26|27)\b~',                              'cf_error',      false],
        ['~Web server is returning an unknown error~i',                       'cf_error',      false],
        // 应用层错误漏到页面
        ['~SQLSTATE\[|Fatal error:|Uncaught (Error|Exception)|数据库连接失败~', 'app_error',    false],
        // 部署缺失
        ['~站点未安装|not installed~i',                                        'not_installed', false],
    ];
}

function statx_expect_codes(string $s): array
{
    $out = [];
    foreach (preg_split('/[\s,]+/', trim($s)) ?: [] as $x) {
        if ($x !== '' && ctype_digit($x)) { $out[] = (int)$x; }
    }
    return $out ?: [200];
}

function statx_curl_err_class(int $errno, string $err): string
{
    if (str_contains($err, 'redirect_loop')) { return 'redirect_loop'; }
    if (str_contains($err, 'too many redirects')) { return 'redirect_too_many'; }
    return match ($errno) {
        6  => 'dns_fail',
        7  => 'connect_fail',
        28 => 'timeout',
        35, 51, 58, 60, 66, 77, 83 => 'tls_fail',
        47 => 'redirect_too_many',
        52, 55, 56 => 'io_fail',
        default => 'curl_' . $errno,
    };
}

function statx_err_label(string $cls): string
{
    return match ($cls) {
        ''                 => '正常',
        'dns_fail'         => 'DNS 解析失败',
        'connect_fail'     => '连接失败',
        'tls_fail'         => 'TLS 握手/证书失败',
        'timeout'          => '超时',
        'redirect_loop'    => '重定向环',
        'redirect_too_many'=> '重定向次数超限',
        'rate_limited'     => '被限频（429，非故障）',
        'probe_config'     => '探针配置故障（不是站点故障）',
        'cf_challenge'     => 'CF 挑战页（探针被拦）',
        'cf_error'         => 'Cloudflare 源站错误',
        'app_error'        => '应用层错误（DB/PHP）',
        'not_installed'    => '站点未安装',
        'marker_missing'   => '内容标记缺失',
        'size_drop'        => '页面体积突降',
        'io_fail'          => '传输中断',
        default            => str_starts_with($cls, 'http_') ? ('HTTP ' . substr($cls, 5)) : $cls,
    };
}

/** 相对 Location → 绝对 URL */
function statx_absolutize(string $loc, string $base): string
{
    if ($loc === '') { return $base; }
    if (preg_match('~^https?://~i', $loc)) { return $loc; }
    $p = parse_url($base);
    $root = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($loc, '//')) { return ($p['scheme'] ?? 'https') . ':' . $loc; }
    if (str_starts_with($loc, '/'))  { return $root . $loc; }
    $dir = preg_replace('~[^/]*$~', '', (string)($p['path'] ?? '/'));
    return $root . $dir . $loc;
}

/** 建立一个 curl 句柄；响应体与 Location 头分别累积进 $bufs/$locs[$hid] */
function statx_handle(int $hid, string $url, string $ua, int $timeout, int $connectTimeout,
                      array &$bufs, array &$locs, int $bodyCap = STATX_BODY_CAP)
{
    $ch = curl_init($url);
    if ($ch === false) { return null; }
    $bufs[$hid] = '';
    $locs[$hid] = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => false,     // 手动逐跳，才能记录每一跳状态码
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_ENCODING       => '',        // 接受 gzip/br，与真实浏览器一致
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,      // 公网证书必须严格校验
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
        CURLOPT_WRITEFUNCTION  => function ($c, $chunk) use (&$bufs, $hid, $bodyCap) {
            if (strlen($bufs[$hid]) < $bodyCap) {
                $bufs[$hid] .= substr($chunk, 0, $bodyCap - strlen($bufs[$hid]));
            }
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$locs, $hid) {
            if (stripos($line, 'location:') === 0) { $locs[$hid] = trim(substr($line, 9)); }
            return strlen($line);
        },
    ]);
    return $ch;
}

/** 收尾一个 curl 句柄 → 单跳结果 */
function statx_finish($ch, array $bufs, array $locs, int $hid, ?int $forceErrno = null): array
{
    $i = curl_getinfo($ch);
    $errno = $forceErrno ?? curl_errno($ch);
    $err   = curl_error($ch) ?: '';
    if ($errno && $err === '') { $err = 'curl_errno=' . $errno; }
    if ($forceErrno !== null && $forceErrno !== 0 && $err === '') { $err = 'curl_multi result=' . $forceErrno; }
    $body = $bufs[$hid] ?? '';
    return [
        'code'     => (int)($i['http_code'] ?? 0),
        'body'     => $body,
        // ★ size 用「解码后的正文长度」：curl 的 size_download 是 gzip 后的 wire 大小，
        //   用它做「页面体积突降」会误判（实测 gushuwu 首页 wire=14076B / 解码=55329B）
        'size'     => strlen($body) > 0 ? strlen($body) : (int)($i['size_download'] ?? 0),
        'wire'     => (int)($i['size_download'] ?? 0),
        'dns'      => (int)round(((float)($i['namelookup_time'] ?? 0)) * 1000),
        'connect'  => (int)round(((float)($i['connect_time'] ?? 0)) * 1000),
        'tls'      => (int)round(((float)($i['appconnect_time'] ?? 0)) * 1000),
        'ttfb'     => (int)round(((float)($i['starttransfer_time'] ?? 0)) * 1000),
        'total'    => (int)round(((float)($i['total_time'] ?? 0)) * 1000),
        'location' => (string)($locs[$hid] ?? ''),
        'err'      => $err,
        'errno'    => $errno,
    ];
}

/**
 * 批量逐跳探测（并发硬上限 2）
 * @param array $jobs key => ['url','ua','timeout','connect','follow','max_redirects']
 * @return array key => ['hops','final_code','body','final_url','size','wire','dns','connect','tls','ttfb','total','err','errno']
 */
function statx_chain_many(array $jobs, int $conc = STATX_MAX_CONCURRENCY): array
{
    $conc = max(1, min(STATX_MAX_CONCURRENCY, $conc));   // ★ 红线：并发 ≤2
    $keys = array_keys($jobs);
    $st = [];
    foreach ($keys as $k) {
        $j = $jobs[$k];
        $st[$k] = [
            'job' => $j, 'cur' => (string)$j['url'], 'visited' => [], 'hops' => [],
            'body' => '', 'sum' => 0, 'last' => null, 'err' => '', 'errno' => 0,
            'final_url' => (string)$j['url'], 'done' => false, 'round' => 0, 'hid' => 0,
        ];
    }

    $mh = curl_multi_init();
    $bufs = []; $locs = []; $handles = []; $hid = 0; $inflight = [];

    $startOne = function ($k) use (&$st, &$mh, &$bufs, &$locs, &$handles, &$hid, &$inflight): bool {
        $url = (string)$st[$k]['cur'];
        if (isset($st[$k]['visited'][$url])) {
            $st[$k]['err'] = 'redirect_loop'; $st[$k]['errno'] = 47; $st[$k]['done'] = true;
            return false;
        }
        $st[$k]['visited'][$url] = true;
        $hid++;
        $j = $st[$k]['job'];
        $ch = statx_handle($hid, $url, (string)$j['ua'], (int)$j['timeout'], (int)$j['connect'], $bufs, $locs);
        if ($ch === null) {
            $st[$k]['err'] = 'curl_init failed'; $st[$k]['errno'] = -1; $st[$k]['done'] = true;
            return false;
        }
        $handles[$hid] = $ch;
        $st[$k]['hid'] = $hid;
        $inflight[$hid] = $k;
        curl_multi_add_handle($mh, $ch);
        return true;
    };

    foreach ($keys as $k) {
        if (count($inflight) >= $conc) { break; }
        $startOne($k);
    }

    while ($inflight) {
        do { $mrc = curl_multi_exec($mh, $running); } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $myHid = 0;
            foreach ($handles as $h => $hc) { if ($hc === $ch) { $myHid = $h; break; } }
            if ($myHid === 0 || !isset($inflight[$myHid])) { continue; }
            $k = $inflight[$myHid];
            unset($inflight[$myHid], $handles[$myHid]);
            curl_multi_remove_handle($mh, $ch);

            $cn = (int)($info['result'] ?? 0);
            $r = statx_finish($ch, $bufs, $locs, $myHid, $cn !== 0 ? $cn : null);

            $st[$k]['sum'] += $r['total'];
            $st[$k]['hops'][] = ['url' => $st[$k]['cur'], 'code' => $r['code'], 'ms' => $r['total'], 'loc' => $r['location']];

            if ($r['err'] !== '') {
                $st[$k]['err'] = $r['err'];
                $st[$k]['errno'] = (int)$r['errno'];
                $st[$k]['done'] = true;
                continue;
            }
            $st[$k]['body'] = $r['body'];
            $st[$k]['last'] = $r;
            $st[$k]['final_url'] = $st[$k]['cur'];

            $j = $st[$k]['job'];
            $isRedirect = in_array($r['code'], [301, 302, 303, 307, 308], true);
            if ($isRedirect && $r['location'] !== '' && (int)$j['follow'] === 1) {
                if ($st[$k]['round'] >= (int)$j['max_redirects']) {
                    $st[$k]['err'] = 'too many redirects'; $st[$k]['errno'] = 47; $st[$k]['done'] = true;
                } else {
                    $st[$k]['cur'] = statx_absolutize($r['location'], (string)$st[$k]['cur']);
                    $st[$k]['round']++;
                }
            } else {
                $st[$k]['done'] = true;
            }
        }

        // 续填（保持并发 ≤ $conc，按 keys 顺序公平推进）
        foreach ($keys as $k) {
            if (count($inflight) >= $conc) { break; }
            if (!$st[$k]['done'] && !in_array($k, $inflight, true)) { $startOne($k); }
        }

        if ($inflight) { curl_multi_select($mh, 1.0); }
    }

    curl_multi_close($mh);

    $out = [];
    foreach ($keys as $k) {
        $s = $st[$k];
        $l = $s['last'] ?? ['dns' => 0, 'connect' => 0, 'tls' => 0, 'ttfb' => 0, 'size' => 0, 'wire' => 0, 'code' => 0];
        $out[$k] = [
            'hops'       => $s['hops'],
            'final_code' => (int)$l['code'],
            'body'       => $s['body'],
            'final_url'  => $s['final_url'],
            'size'       => (int)$l['size'],
            'wire'       => (int)$l['wire'],
            'dns'        => (int)$l['dns'],
            'connect'    => (int)$l['connect'],
            'tls'        => (int)$l['tls'],
            'ttfb'       => (int)$l['ttfb'],
            'total'      => $s['sum'],
            'err'        => $s['err'],
            'errno'      => $s['errno'],
        ];
    }
    return $out;
}

/** 单目标逐跳探测（复用批量实现，并发 1） */
function statx_http_chain(string $url, string $ua, int $timeout, int $connectTimeout,
                          bool $follow, int $maxRedirects): array
{
    $r = statx_chain_many(['one' => [
        'url' => $url, 'ua' => $ua, 'timeout' => $timeout, 'connect' => $connectTimeout,
        'follow' => $follow ? 1 : 0, 'max_redirects' => $maxRedirects,
    ]], 1);
    return $r['one'];
}

/** 重定向链 → 紧凑字符串（落库用） */
function statx_hops_str(array $hops): string
{
    $p = [];
    foreach ($hops as $h) {
        $u = preg_replace('~^https?://~', '', (string)$h['url']);
        $u = preg_replace('~([?&])_h=[0-9a-f]+~', '$1', (string)$u);
        $u = rtrim((string)$u, '?&');
        $p[] = (int)$h['code'] . ' ' . mb_substr((string)$u, 0, 90) . ' (' . (int)$h['ms'] . 'ms)';
    }
    return mb_substr(implode(' → ', $p), 0, 990);
}

/**
 * 公网边缘证书剩余天数（capture_peer_cert）
 * ★ 必须测公网握手到的证书：源站是 CF Origin CA（2041 到期，无意义），用户看到的是 CF 边缘证书
 */
function statx_tls_days(string $host, int $port = 443, int $timeout = 8): ?int
{
    if ($host === '') { return null; }
    $ctx = stream_context_create(['ssl' => [
        'capture_peer_cert' => true,
        'verify_peer'       => false,   // 只取有效期；证书链有效性由 curl 那侧严格校验
        'verify_peer_name'  => false,
        'SNI_enabled'       => true,
        'peer_name'         => $host,
    ]]);
    $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, $timeout,
        STREAM_CLIENT_CONNECT, $ctx);
    if ($fp === false) { return null; }
    $params = stream_context_get_params($fp);
    fclose($fp);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    if (!$cert) { return null; }
    $info = @openssl_x509_parse($cert);
    if (!is_array($info) || empty($info['validTo_time_t'])) { return null; }
    return (int)floor(($info['validTo_time_t'] - time()) / 86400);
}

function statx_url_host(string $url): array
{
    $p = parse_url($url);
    return [(string)($p['host'] ?? ''), (($p['scheme'] ?? '') === 'https')];
}

/**
 * 分类：把「一次探测的原始结果」判成 ok / 错误类别 / 是否失败 / 是否探针故障
 * @param array $t 目标行；$ch 见 statx_chain_many 的单元素返回；$o ['median_size'=>int]
 */
function statx_classify(array $t, array $ch, array $o = []): array
{
    $expect = statx_expect_codes((string)($t['expect_codes'] ?? '200'));
    $cls = ''; $note = ''; $forbid = ''; $isProbeFault = false; $markerHit = false;

    // ① 禁止标记（先判，可覆盖状态码判定）。只看前 8 KB，避免长正文假阳性。
    $head = substr((string)$ch['body'], 0, 8192);
    if ($head !== '') {
        foreach (statx_forbid_rules() as [$re, $c, $pf]) {
            if (preg_match($re, $head)) {
                $forbid = $c; $cls = $c; $isProbeFault = (bool)$pf;
                $note = '命中禁止标记：' . $c;
                break;
            }
        }
    }

    // ② 传输层错误
    if ($cls === '' && $ch['err'] !== '') {
        $cls = statx_curl_err_class((int)$ch['errno'], (string)$ch['err']);
        $note = mb_substr((string)$ch['err'], 0, 120);
    }

    // ③ 状态码
    if ($cls === '' && !in_array((int)$ch['final_code'], $expect, true)) {
        if ((int)$ch['final_code'] === 429) {
            $cls = 'rate_limited';           // ★ 单独识别：被自家 limit_req 限频，不是站点故障
            $note = 'HTTP 429（限频，不计失败）';
        } elseif ((int)$ch['final_code'] === 403 && strlen((string)$ch['body']) < 1024) {
            // 裸 403 + 极小响应体：几乎一定是「探针被拦」（nginx UA 黑名单 / WAF / geo），不是用户可见故障
            $cls = 'probe_config'; $isProbeFault = true;
            $note = 'HTTP 403 且响应体极小 → 疑似探针被拦（非站点故障）';
        } else {
            $cls = 'http_' . (int)$ch['final_code'];
            $note = 'HTTP ' . (int)$ch['final_code'] . '（期望 ' . implode('/', $expect) . '）';
        }
    }

    // ④ 必需内容标记（"看内容标记不只看状态码"）
    $require = trim((string)($t['require_re'] ?? ''));
    if ($cls === '' && $require !== '') {
        if (preg_match('~' . str_replace('~', '\~', $require) . '~', (string)$ch['body'])) { $markerHit = true; }
        else { $cls = 'marker_missing'; $note = '缺少必需内容标记 /' . mb_substr($require, 0, 60) . '/'; }
    } elseif ($cls === '') {
        $markerHit = true;   // 未配置必需标记时不作为判据
    }

    // ⑤ 页面体积
    $minBytes = (int)($t['min_bytes'] ?? 0);
    if ($cls === '' && $minBytes > 0 && (int)$ch['size'] < $minBytes) {
        $cls = 'size_drop'; $note = '体积 ' . (int)$ch['size'] . 'B < 下限 ' . $minBytes . 'B';
    }
    $median = (int)($o['median_size'] ?? 0);
    if ($cls === '' && $median > 4096 && (int)$ch['size'] > 0 && (int)$ch['size'] < (int)($median * 0.4)) {
        $cls = 'size_drop'; $note = '体积突降 ' . (int)$ch['size'] . 'B（近 7 天中位 ' . $median . 'B）';
    }

    $is429   = ((int)$ch['final_code'] === 429);
    $isProbe = ($cls === 'probe_config' || $cls === 'cf_challenge' || $isProbeFault);
    $isFail  = ($cls !== '' && !$is429 && !$isProbe);

    $slow = ($cls === '' && (int)$t['slow_ms'] > 0 && (int)$ch['total'] > (int)$t['slow_ms']);
    if ($cls === '' && $slow) { $note = '慢：' . (int)$ch['total'] . 'ms > ' . (int)$t['slow_ms'] . 'ms'; }

    // TLS（每天一次；按 tls_interval_s 在调用侧节流）
    $tlsDays = null;
    [$host, $isHttps] = statx_url_host((string)$t['url']);
    if ($isHttps && (int)($t['tls_check'] ?? 1) === 1) { $tlsDays = statx_tls_days($host); }

    return [
        'ok'         => ($cls === ''),
        'err_class'  => $cls,
        'err_label'  => statx_err_label($cls),
        'is_fail'    => $isFail ? 1 : 0,
        'is_probe'   => $isProbe ? 1 : 0,
        'is_429'     => $is429 ? 1 : 0,
        'slow'       => $slow ? 1 : 0,
        'http_code'  => (int)$ch['final_code'],
        'redirects'  => max(0, count($ch['hops']) - 1),
        'hops'       => statx_hops_str($ch['hops']),
        'final_url'  => mb_substr((string)$ch['final_url'], 0, 500),
        'dns_ms'     => (int)$ch['dns'],
        'connect_ms' => (int)$ch['connect'],
        'tls_ms'     => (int)$ch['tls'],
        'ttfb_ms'    => (int)$ch['ttfb'],
        'total_ms'   => (int)$ch['total'],
        'size_bytes' => (int)$ch['size'],
        'tls_days'   => $tlsDays,
        'marker_hit' => $markerHit ? 1 : 0,
        'forbid_hit' => $forbid,
        'note'       => mb_substr($note, 0, 240),
    ];
}

/** 单目标探测（含一次 cache-buster；不重试、不写库） */
function statx_probe(array $t, array $o = []): array
{
    $ua  = (string)($o['ua'] ?? statx_ua());
    $url = (string)$t['url'];
    if ((int)($t['buster'] ?? 0) === 1) { $url = statx_bust($url); }
    $ch = statx_http_chain($url, $ua, max(3, (int)($t['timeout_s'] ?? 15)), 5,
        (int)($t['follow'] ?? 1) === 1, max(1, (int)($t['max_redirects'] ?? 5)));
    $r = statx_classify($t, $ch, $o);
    $r['ua'] = $ua;
    return $r;
}

/**
 * 对一组目标做「首探 + 重试」，受总预算 $deadline 约束。
 * 重试：同一 UA + 新的 cache-buster；只有"真失败"才重试（429 / 探针被拦 不重试）。
 * @return array key => 结果行（含 attempts；attempts=0 表示预算耗尽未探测）
 */
function statx_probe_jobs_with_retry(array $targetsByKey, array $opt, float $deadline, int $maxRetries, int $conc): array
{
    $out = [];
    $pending = $targetsByKey;
    $backoffs = [20, 60];

    for ($attempt = 1; $attempt <= max(1, $maxRetries + 1); $attempt++) {
        if (!$pending) { break; }
        if ($attempt > 1) {
            $wait = $backoffs[$attempt - 2] ?? 60;
            $left = (int)floor($deadline - microtime(true));
            if ($left < $wait + 3) { break; }     // 预算不足 → 放弃剩余重试（attempts 如实记录）
            sleep($wait);
        }
        $jobs = [];
        foreach ($pending as $k => $t) {
            $url = (string)$t['url'];
            if ((int)($t['buster'] ?? 0) === 1) { $url = statx_bust($url); }   // ★ 每次重试换新 buster
            $jobs[$k] = [
                'url' => $url, 'ua' => (string)($opt['ua'] ?? statx_ua()),
                'timeout' => max(3, (int)($t['timeout_s'] ?? 15)), 'connect' => 5,
                'follow' => (int)($t['follow'] ?? 1), 'max_redirects' => max(1, (int)($t['max_redirects'] ?? 5)),
            ];
        }
        $chains = statx_chain_many($jobs, $conc);
        $still = [];
        foreach ($pending as $k => $t) {
            $res = statx_classify($t, $chains[$k] ?? [], ['median_size' => (int)($opt['median'][$k] ?? 0)]);
            $res['attempts'] = $attempt;
            $out[$k] = $res;
            if ($res['is_fail'] === 1) { $still[$k] = $t; }
        }
        $pending = $still;
    }

    foreach ($targetsByKey as $k => $t) {
        if (!isset($out[$k])) {
            $out[$k] = statx_classify($t, ['hops' => [], 'final_code' => 0, 'body' => '', 'final_url' => (string)$t['url'],
                'size' => 0, 'wire' => 0, 'dns' => 0, 'connect' => 0, 'tls' => 0, 'ttfb' => 0, 'total' => 0,
                'err' => '', 'errno' => 0], []);
            $out[$k]['attempts'] = 0;   // 预算耗尽：不落库、不计失败
        }
    }
    return $out;
}
