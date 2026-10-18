<?php
/* plugins/lqprobe/inc.php — 第22棒（ta22）：`/api/probe`（服务端探测）· `/api/share` + `/s/<code>`（即现分享）
 * =============================================================================
 * 由 index.php 的**声明式接管**段 require（路径全等才命中并立即 exit）。
 *
 * ★★ 访客侧零外发：本文件的所有网络动作都在**服务端**发起；页面只请求本站。
 * ★★ SSRF 守卫（本文件的第一职责）：
 *      ① 只允许 http/https；② 去 userinfo；③ 主机名解析出**全部** A/AAAA 记录，
 *      任一落在私网 / 回环 / 链路本地 / 保留 / CGNAT（100.64/10）⇒ 直接拒绝；
 *      ④ 不跟随跳转（`CURLOPT_FOLLOWLOCATION=false`）⇒ 302 只报出来、不再发第二次请求；
 *      ⑤ 连接/总超时 5s/8s；⑥ 正文只读前 512 KB。
 * ========================================================================== */

if (!defined('LQPROBE_TIMEOUT')) {
    define('LQPROBE_TIMEOUT', 8);
    define('LQPROBE_BODY_MAX', 524288);      /* 抓取正文上限 512 KB */
    define('LQPROBE_IN_MAX', 1048576);       /* 请求体上限 1 MB */
    define('LQSHARE_MAX', 262144);           /* 单份分享内容上限 256 KB */
    define('LQSHARE_TTL_H', 168);            /* 7 天 */
}

/* ------------------------------------------------------------------ 工具函数 */
function lqprobe_ip_public(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) { return false; }
    if (preg_match('/^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\./', $ip)) { return false; }  /* CGNAT 100.64/10 */
    if (preg_match('/^(fe80|fec0|fc|fd)/i', $ip)) { return false; }
    return true;
}

/** 解析主机 → [[ips], why]；why 非空即拒 */
function lqprobe_resolve(string $host): array
{
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        foreach ((array)@gethostbynamel($host) as $x) { if ($x) { $ips[] = $x; } }
        foreach ((array)@dns_get_record($host, DNS_AAAA) as $r) { if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; } }
    }
    $ips = array_values(array_unique($ips));
    if (!$ips) { return [[], 'dns_fail']; }
    foreach ($ips as $ip) { if (!lqprobe_ip_public($ip)) { return [$ips, 'private_host']; } }
    return [$ips, ''];
}

/** 规范化 + 守卫；返回 [$info|nil, $why] */
function lqprobe_url_ok(string $u): array
{
    $u = trim($u);
    if ($u === '') { return [null, 'empty']; }
    if (!preg_match('#^https?://#i', $u)) { $u = 'https://' . $u; }
    $p = @parse_url($u);
    if (!is_array($p) || empty($p['host'])) { return [null, 'bad_url']; }
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) { return [null, 'bad_scheme']; }
    $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($port < 1 || $port > 65535) { return [null, 'bad_port']; }
    $host = strtolower((string)$p['host']);
    if (strlen($host) > 253 || !preg_match('/^[a-z0-9._\-\[\]:]+$/', $host)) { return [null, 'bad_host']; }
    $path = (string)($p['path'] ?? '/');
    if ($path === '') { $path = '/'; }
    $clean = $scheme . '://' . $host . (isset($p['port']) ? ':' . $port : '') . $path . (isset($p['query']) ? '?' . $p['query'] : '');
    [$ips, $why] = lqprobe_resolve($host);
    if ($why !== '') { return [null, $why]; }
    return [['url' => $clean, 'host' => $host, 'port' => $port, 'scheme' => $scheme, 'ips' => $ips], ''];
}

/** 单次请求（不跟随跳转）；返回 [info, body, err, headers] */
function lqprobe_curl(string $url, array $extra = []): array
{
    $hdr = [];
    $ch = curl_init($url);
    $opt = [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_CONNECTTIMEOUT  => 5,
        CURLOPT_TIMEOUT         => LQPROBE_TIMEOUT,
        CURLOPT_USERAGENT       => 'liulanqi-probe/1.0 (+https://liulanqi.com)',
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_NOBODY          => false,
        CURLOPT_HTTPHEADER      => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8'],
        CURLOPT_HEADERFUNCTION  => function ($c, $line) use (&$hdr) { $l = rtrim($line, "\r\n"); if ($l !== '') { $hdr[] = $l; } return strlen($line); },
    ];
    foreach ($extra as $k => $v) { $opt[$k] = $v; }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if (is_string($body) && strlen($body) > LQPROBE_BODY_MAX) { $body = substr($body, 0, LQPROBE_BODY_MAX); }
    return [$info, (string)$body, $err, $hdr];
}

function lqprobe_hdr(array $hdr, string $name): string
{
    foreach ($hdr as $h) {
        $i = strpos($h, ':');
        if ($i !== false && strcasecmp(trim(substr($h, 0, $i)), $name) === 0) { return trim(substr($h, $i + 1)); }
    }
    return '';
}

/* ------------------------------------------------------------------ probe 主体 */
function lqprobe_handle(array $in): array
{
    $op  = (string)($in['op'] ?? '');
    $raw = (string)($in['url'] ?? '');

    if ($op === 'bulk') {
        $lines = preg_split('/[\r\n]+/', $raw) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn($x) => $x !== ''));
        if (!$lines) { return [400, ['error' => 'empty', 'msg' => '请至少填一行网址']]; }
        if (count($lines) > 20) { $lines = array_slice($lines, 0, 20); }
        $list = []; $okN = 0;
        foreach ($lines as $u) {
            [$g, $why] = lqprobe_url_ok($u);
            if ($g === null) { $list[] = ['url' => $u, 'status' => '拒绝', 'note' => $why]; continue; }
            [$info, , $err] = lqprobe_curl($g['url'], [CURLOPT_NOBODY => true, CURLOPT_HTTPHEADER => ['Accept: */*']]);
            $code = (int)($info['http_code'] ?? 0);
            if ($code === 0 || $code >= 400) {  /* HEAD 不被支持时回落 GET */
                [$info2, , $err2] = lqprobe_curl($g['url']);
                if ((int)($info2['http_code'] ?? 0) > 0) { $info = $info2; $code = (int)$info2['http_code']; $err = $err2; }
            }
            $note = $err !== '' ? ('连接失败：' . $err) : ('耗时 ' . round((float)($info['total_time'] ?? 0), 2) . 's · ' . lqprobe_hdr([], ''));
            if ($code > 0 && $code < 400) { $okN++; }
            $list[] = ['url' => $g['url'], 'status' => $code > 0 ? (string)$code : '失败', 'note' => trim($note)];
        }
        return [200, ['ok' => true, 'list' => $list, 'note' => '共 ' . count($list) . ' 条，正常 ' . $okN . ' 条；每行一个网址，最多 20 个；服务端串行检测（不跟随跳转）。']];
    }

    /* 其余三款：单地址 */
    [$g, $why] = lqprobe_url_ok($raw);
    if ($g === null) {
        $msg = ['empty' => '请先填网址', 'dns_fail' => '域名解析失败', 'private_host' => '内网 / 回环 / 保留地址一律拒绝',
                'bad_url' => '网址格式不对', 'bad_scheme' => '只支持 http/https', 'bad_host' => '主机名不合法', 'bad_port' => '端口不合法'][$why] ?? $why;
        return [400, ['error' => $why, 'msg' => $msg]];
    }

    if ($op === 'http2') {
        [$info, , $err, $hdr] = lqprobe_curl($g['url'], [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0]);
        if ($err !== '') { return [502, ['error' => 'fetch_fail', 'msg' => '连接失败：' . $err]]; }
        $ver = (int)($info['http_version'] ?? 0);
        $vname = [0 => '未知', 1 => 'HTTP/1.0', 2 => 'HTTP/1.1', 3 => 'HTTP/2', 30 => 'HTTP/3'][$ver] ?? ('v' . $ver);
        $tls = '';
        if ($g['scheme'] === 'https') {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
            $s = @stream_socket_client('ssl://' . $g['host'] . ':' . $g['port'], $eno, $estr, 6, STREAM_CLIENT_CONNECT, $ctx);
            if ($s) { $meta = stream_get_meta_data($s); $tls = (string)($meta['crypto']['protocol'] ?? '') . ' / ' . (string)($meta['crypto']['cipher_name'] ?? ''); fclose($s); }
        }
        $rows = [
            ['目标', $g['url']],
            ['状态码', (string)($info['http_code'] ?? 0)],
            ['协商到的协议', $vname],
            ['HTTP/2 支持', $ver >= 3 ? '是' : '否（服务端未通过 ALPN 协商到 h2）'],
            ['TLS', $tls !== '' ? $tls : '(非 https 或无 TLS)'],
            ['Server', lqprobe_hdr($hdr, 'Server')],
            ['响应耗时', round((float)($info['total_time'] ?? 0), 3) . ' s'],
            ['远端 IP', (string)($info['primary_ip'] ?? '')],
        ];
        return [200, ['ok' => true, 'rows' => $rows,
            'note' => '检测口径：curl 以 CURL_HTTP_VERSION_2_0 发起、读服务端 ALPN 协商结果（不跟随跳转）；TLS 版本/套件取自本机 openssl 握手。']];
    }

    if ($op === 'framework') {
        [$info, $body, $err, $hdr] = lqprobe_curl($g['url']);
        if ($err !== '') { return [502, ['error' => 'fetch_fail', 'msg' => '连接失败：' . $err]]; }
        $xpb   = lqprobe_hdr($hdr, 'X-Powered-By');
        $srv   = lqprobe_hdr($hdr, 'Server');
        $ck    = lqprobe_hdr($hdr, 'Set-Cookie');
        $gen   = lqprobe_hdr($hdr, 'X-Generator');
        $hit = [];
        $probe = static function (string $name, string $needle, bool $ci = true) use ($body, &$hit) {
            if ($needle === '') { return; }
            if ($ci ? (stripos($body, $needle) !== false) : (strpos($body, $needle) !== false)) { $hit[] = $name; }
        };
        $probe('WordPress', 'wp-content');
        $probe('WordPress', 'wp-json');
        $probe('Next.js', '__NEXT_DATA__');
        $probe('Next.js', '/_next/static');
        $probe('Nuxt', '__NUXT__');
        $probe('Gatsby', 'gatsby-');
        $probe('Astro', 'astro-island');
        $probe('SvelteKit', '__sveltekit');
        $probe('Vue', 'data-v-');
        $probe('React', 'data-reactroot');
        $probe('Tailwind CSS', 'tailwind');
        $probe('Bootstrap', 'bootstrap');
        $probe('jQuery', 'jquery');
        $probe('Hexo', 'hexo');
        $probe('Drupal', 'drupal');
        $probe('Joomla', 'joomla');
        $probe('Typecho', 'typecho');
        $probe('Discuz', 'discuz');
        $probe('Hugo', 'hugo');
        if (stripos($xpb, 'PHP') !== false) { $hit[] = 'PHP ' . trim(str_ireplace('php/', '', $xpb)); }
        if (stripos($xpb, 'ASP.NET') !== false) { $hit[] = 'ASP.NET'; }
        if (stripos($xpb, 'Express') !== false) { $hit[] = 'Express'; }
        if (stripos($ck, 'laravel_session') !== false) { $hit[] = 'Laravel'; }
        if (stripos($ck, 'think_') !== false || stripos($body, 'ThinkPHP') !== false) { $hit[] = 'ThinkPHP'; }
        if (stripos($ck, 'PHPSESSID') !== false) { $hit[] = 'PHP 会话（PHPSESSID）'; }
        if (stripos($ck, 'csrftoken') !== false) { $hit[] = 'Django'; }
        if (stripos($ck, '_rails') !== false || stripos($ck, '_session_id') !== false) { $hit[] = 'Ruby on Rails'; }
        if (lqprobe_hdr($hdr, 'CF-Ray') !== '') { $hit[] = 'Cloudflare'; }
        if (stripos($gen, 'generator') !== false) { $hit[] = 'generator: ' . $gen; }
        $hit = array_values(array_unique(array_filter($hit)));
        $rows = [
            ['目标', $g['url']],
            ['状态码', (string)($info['http_code'] ?? 0)],
            ['Server', $srv],
            ['X-Powered-By', $xpb],
            ['HTML 大小', strlen($body) . ' B（最多读 512 KB）'],
            ['识别结果', $hit ? implode(' · ', $hit) : '未命中已知指纹'],
        ];
        return [200, ['ok' => true, 'rows' => $rows,
            'note' => '口径：只读**首个响应**（不跟随跳转），指纹取自响应头 + 正文前 512 KB 的固定特征串；未命中不代表没有框架。']];
    }

    if ($op === 'friendlink') {
        [$info, $body, $err] = lqprobe_curl($g['url']);
        if ($err !== '') { return [502, ['error' => 'fetch_fail', 'msg' => '连接失败：' . $err]]; }
        $hosts = [];
        if (preg_match_all('#<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']#i', $body, $m)) {
            foreach ($m[1] as $href) {
                $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
                if ($href === '' || $href[0] === '#' || stripos($href, 'javascript:') === 0 || stripos($href, 'mailto:') === 0) { continue; }
                $h = @parse_url($href, PHP_URL_HOST);
                if (!$h) { continue; }
                $h = strtolower($h);
                if ($h === $g['host'] || str_ends_with($h, '.' . $g['host'])) { continue; }
                $hosts[$h] = ($hosts[$h] ?? 0) + 1;
            }
        }
        arsort($hosts);
        $list = [];
        foreach (array_slice($hosts, 0, 60, true) as $h => $n) {
            $list[] = ['url' => $h, 'status' => $n . ' 条', 'note' => ''];
        }
        return [200, ['ok' => true,
            'rows' => [['目标', $g['url']], ['状态码', (string)($info['http_code'] ?? 0)], ['外链域名数', (string)count($hosts)], ['外链总数', (string)array_sum($hosts)]],
            'list' => $list,
            'note' => '口径：只统计**外链域名**（与本站同域/子域的链接不计），按出现次数降序取前 60；同域判定按主机名后缀。']];
    }

    return [400, ['error' => 'bad_op', 'msg' => '不支持的 op']];
}

/* ------------------------------------------------------------------ share */
function lqshare_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . DB_PREFIX . 'shares ('
        . 'code varchar(16) NOT NULL PRIMARY KEY,'
        . 'title varchar(200) NOT NULL DEFAULT \'\','
        . 'kind varchar(16) NOT NULL DEFAULT \'text\','
        . 'mime varchar(120) NOT NULL DEFAULT \'text/plain; charset=utf-8\','
        . 'body mediumblob NOT NULL,'
        . 'size int(11) NOT NULL DEFAULT 0,'
        . 'ip varchar(64) NOT NULL DEFAULT \'\','
        . 'created_at datetime NOT NULL,'
        . 'expires_at datetime NOT NULL,'
        . 'hits int(11) NOT NULL DEFAULT 0,'
        . 'KEY exp (expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    return $pdo;
}

function lqshare_code(): string
{
    $abc = 'abcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 8; $i++) { $s .= $abc[random_int(0, strlen($abc) - 1)]; }
    return $s;
}

function lqshare_kind(string $name, string $mime): array
{
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $map = [
        'html' => ['html', 'text/html; charset=utf-8'], 'htm' => ['html', 'text/html; charset=utf-8'],
        'md' => ['md', 'text/plain; charset=utf-8'], 'markdown' => ['md', 'text/plain; charset=utf-8'],
        'svg' => ['svg', 'image/svg+xml'],
        'png' => ['png', 'image/png'], 'jpg' => ['img', 'image/jpeg'], 'jpeg' => ['img', 'image/jpeg'],
        'gif' => ['img', 'image/gif'], 'webp' => ['img', 'image/webp'],
        'txt' => ['text', 'text/plain; charset=utf-8'], 'json' => ['text', 'application/json; charset=utf-8'],
    ];
    if (isset($map[$ext])) { return $map[$ext]; }
    if ($mime !== '' && str_starts_with($mime, 'image/')) { return ['img', $mime]; }
    if ($mime !== '' && str_starts_with($mime, 'text/')) { return ['text', 'text/plain; charset=utf-8']; }
    return ['file', $mime !== '' ? $mime : 'application/octet-stream'];
}

function lqshare_handle(): array
{
    $body = null; $title = ''; $name = ''; $mime = '';
    if (!empty($_FILES['f']['tmp_name']) && is_uploaded_file($_FILES['f']['tmp_name'])) {
        if ((int)($_FILES['f']['size'] ?? 0) > LQSHARE_MAX) { return [413, ['error' => 'too_large', 'msg' => '文件超过 256 KB']]; }
        $body = (string)file_get_contents($_FILES['f']['tmp_name']);
        $name = (string)($_FILES['f']['name'] ?? '');
        $mime = (string)($_FILES['f']['type'] ?? '');
        $title = trim((string)($_POST['title'] ?? ''));
    } else {
        $raw = (string)file_get_contents('php://input');
        if (strlen($raw) > LQPROBE_IN_MAX) { return [413, ['error' => 'too_large', 'msg' => '请求体过大']]; }
        $j = json_decode($raw, true);
        if (!is_array($j)) { $j = $_POST; }
        $body = (string)($j['text'] ?? '');
        $title = trim((string)($j['title'] ?? ''));
        $name = (string)($j['name'] ?? '');
    }
    if ($body === '') { return [400, ['error' => 'empty', 'msg' => '内容为空']]; }
    if (strlen($body) > LQSHARE_MAX) { return [413, ['error' => 'too_large', 'msg' => '内容超过 256 KB']]; }
    /* ★ [t22 share-mime] 没有文件名（直接粘贴）时按正文头部自判类型：
       否则会回落到 application/octet-stream ⇒ 浏览器**下载**而不是直接打开。 */
    if ($name === '') {
        $head = ltrim(substr($body, 0, 96));
        if (preg_match('#^<svg\b#i', $head)) { $name = 'pasted.svg'; }
        elseif (preg_match('#^<(?:!doctype\s+html|html)\b#i', $head)) { $name = 'pasted.html'; }
        elseif (preg_match('#^[\{\[]#', $head)) { $name = 'pasted.json'; }
        elseif (preg_match('/^(#{1,6}\s|[-*+]\s|\d+\.\s|>\s)/m', substr($body, 0, 600))) { $name = 'pasted.md'; }
        else { $name = 'pasted.txt'; }
    }
    [$kind, $m] = lqshare_kind($name, $mime);
    try {
        $pdo = lqshare_pdo();
        for ($try = 0; $try < 5; $try++) {
            $code = lqshare_code();
            try {
                $st = $pdo->prepare('INSERT INTO ' . DB_PREFIX . 'shares (code,title,kind,mime,body,size,ip,created_at,expires_at,hits)'
                    . ' VALUES (?,?,?,?,?,?,?,NOW(),DATE_ADD(NOW(), INTERVAL ' . (int)LQSHARE_TTL_H . ' HOUR),0)');
                $st->execute([$code, mb_substr($title, 0, 120), $kind, $m, $body, strlen($body), (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
                return [200, ['ok' => true, 'url' => '/p/' . $code, 'code' => $code,
                    'expires_h' => LQSHARE_TTL_H, 'size_h' => (strlen($body) >= 1024 ? round(strlen($body) / 1024, 1) . ' KB' : strlen($body) . ' B')]];
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate') === false) { throw $e; }
            }
        }
        return [500, ['error' => 'code_conflict', 'msg' => '短码生成失败，请重试']];
    } catch (Throwable $e) {
        return [500, ['error' => 'db', 'msg' => '存储不可用']];
    }
}

function lqshare_serve(string $code): void
{
    $code = strtolower(trim($code));
    if (!preg_match('/^[a-z0-9]{4,16}$/', $code)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo '404'; return; }
    try {
        $pdo = lqshare_pdo();
        $st = $pdo->prepare('SELECT * FROM ' . DB_PREFIX . 'shares WHERE code=? AND expires_at > NOW() LIMIT 1');
        $st->execute([$code]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $r = null; }
    if (!$r) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>链接已失效</title><p style="font:16px/1.7 system-ui;padding:2rem">这条分享链接不存在或已过期（默认 7 天）。</p>';
        return;
    }
    try { $pdo->prepare('UPDATE ' . DB_PREFIX . 'shares SET hits=hits+1 WHERE code=?')->execute([$code]); } catch (Throwable $e) { }
    $kind = (string)$r['kind'];
    $mime = (string)$r['mime'];
    $title = (string)$r['title'];
    http_response_code(200);
    header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    header('X-Content-Type-Options: nosniff', true);
    /* ★ 沙箱化：脚本/表单/外链一律禁止 ⇒ 分享页不可能变成钓鱼或 XSS 跳板 */
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:", true);
    header('Referrer-Policy: no-referrer', true);
    if ($kind === 'md') {
        header('Content-Type: text/plain; charset=utf-8');
        echo (string)$r['body'];
        return;
    }
    if ($kind === 'text' || $kind === 'file') {
        header('Content-Type: ' . (str_starts_with($mime, 'text/') || str_starts_with($mime, 'application/json') ? $mime : 'application/octet-stream'));
        if ($kind === 'file') { header('Content-Disposition: attachment; filename="' . rawurlencode($title !== '' ? $title : 'download') . '"'); }
        echo (string)$r['body'];
        return;
    }
    if ($kind === 'img') {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline');
        echo (string)$r['body'];
        return;
    }
    if ($kind === 'svg') {
        header('Content-Type: image/svg+xml');
        echo (string)$r['body'];
        return;
    }
    /* html：CSP sandbox 已封脚本 */
    header('Content-Type: text/html; charset=utf-8');
    echo (string)$r['body'];
}
