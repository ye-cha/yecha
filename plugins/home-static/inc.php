<?php
declare(strict_types=1);
// 首页生成静态 · 共享逻辑（inc.php）：目录/语言映射/内部渲染抓取/直出/自动重建
// 被 boot.php（前台）与 admin.php（后台）require_once。

function hs_dir(): string
{
    return rtrim(dirname(__DIR__, 2), '/') . '/var/home-static';
}

/** 语言 → 前缀 映射（site.languages / site.default_lang；缺省单语 zh）
 *  ★ r10：声明为 public —— statx 的 collect-inject 需要复用这份**权威**映射来重建静态首页，
 *    避免两处各写一份「语言→路径」逻辑后漂移。 */
function hs_langs(): array
{
    $def = (string)get_option('site.default_lang', 'zh');
    $raw = (string)get_option('site.languages', '');
    $list = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($list) || $list === []) { $list = [$def]; }
    if (!in_array($def, $list, true)) { array_unshift($list, $def); }
    $out = [];
    foreach ($list as $lg) {
        $lg = trim((string)$lg);
        if ($lg === '') { continue; }
        $out[$lg] = $lg === $def ? '' : '/' . $lg;
    }
    return $out;
}

/** 首页路径（不含 query）→ 相对静态文件（null 表示非首页，不处理） */
function hs_map(string $path): ?string
{
    $path = trim($path, '/');
    if ($path === '' || $path === 'index.html') {
        return 'index.html';
    }
    $segs = explode('/', $path);
    if (count($segs) === 1) {
        $lg = $segs[0];
        if (isset(hs_langs()[$lg]) && hs_langs()[$lg] !== '') {
            return $lg . '/index.html';
        }
    } elseif (count($segs) === 2 && $segs[1] === 'index.html') {
        $lg = $segs[0];
        if (isset(hs_langs()[$lg]) && hs_langs()[$lg] !== '') {
            return $lg . '/index.html';
        }
    }
    return null;
}

function hs_ttl(): int
{
    return max(30, (int)get_option('home_static.ttl', '600'));
}

/**
 * 站点自己的 host（Host 头的唯一可信来源）。
 * 顺序：① 调用方显式传入 ② 选项 site.url 的 host ③ SERVER_NAME ④ Web 请求的 HTTP_HOST
 *     ⑤ CLI 且都取不到 → 站点根目录名（宝塔/常规布局目录名就是域名）→ 仍取不到返回 ''。
 * ★ 绝不再 fallback 到 'localhost'：CLI 下那样会因 nginx 有 default_server 而**抓到别的站**。
 */
function hs_site_host(): string
{
    $u = trim((string)get_option('site.url', ''));
    if ($u !== '') {
        $h = (string)(parse_url(str_contains($u, '://') ? $u : ('https://' . $u))['host'] ?? '');
        if ($h !== '') { return mb_strtolower($h); }
    }
    $sn = trim((string)($_SERVER['SERVER_NAME'] ?? ''));
    if ($sn !== '') { return mb_strtolower(preg_replace('/:\d+$/', '', $sn)); }
    if (PHP_SAPI !== 'cli') {
        $hh = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($hh !== '') { return mb_strtolower(preg_replace('/:\d+$/', '', $hh)); }
    }
    $k = basename(dirname(__DIR__, 2));
    return preg_match('~^[a-z0-9\-]+(\.[a-z0-9\-]+)+$~i', $k) ? mb_strtolower($k) : '';
}

/**
 * 内部抓取首页 HTML（跳过直出与页缓存，得到最新渲染）。
 * ★ r10 修复：原来用 `https://127.0.0.1` + `Host:` 头抓。多站 nginx 上有 `default_server`
 *   且 **无 SNI**，请求会被路由到**默认站**（抓错站！）；CLI 下 Host 还会退化成 localhost。
 *   现在用 **CURLOPT_RESOLVE 把域名钉到 127.0.0.1** —— SNI 与 Host 都是正确的域名，
 *   请求真正落到本站的 server 块；且不改 /etc/hosts、不影响其它进程。
 */
function hs_fetch(string $prefix, string $host = ''): string
{
    if ($host === '') { $host = hs_site_host(); }
    $host = mb_strtolower(trim((string)preg_replace('/:\d+$/', '', $host)));
    if ($host === '') { return ''; }             // ★ 拿不到 host 宁可失败，也不抓错站
    if (!function_exists('curl_init')) { return ''; }
    $path = $prefix === '' ? '/' : $prefix . '/';
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => 'https://' . $host . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        // 把域名钉到回环：SNI/Host 均为正确域名，绕开 default_server + 无 SNI 的错站问题
        CURLOPT_RESOLVE => [$host . ':443:127.0.0.1'],
        CURLOPT_HTTPHEADER => [
            'Host: ' . $host,
            'X-HS-GEN: 1',          // 让 hs_front() 放行，走正常动态渲染
            'X-YC-NOCACHE: 1',      // 绕开页缓存
            'User-Agent: YeCha-home-static/1.0',
        ],
    ];
    curl_setopt_array($ch, $opts);
    $html = (string)curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err !== '' || $code !== 200) {
        @error_log('[home-static] hs_fetch 失败 host=' . $host . ' prefix=' . $prefix
            . ' code=' . $code . ' err=' . $err);
    }
    return $html;
}

/** 生成全部语言首页静态文件；返回 [lang => bool]（$host 显式传入；CLI 下必传，否则拿不到正确 Host） */
function hs_generate(string $host = ''): array
{
    $dir = hs_dir();
    if ($host === '') { $host = hs_site_host(); }
    $res = [];
    if ($host === '') {
        $res['_error'] = '拿不到站点 host：拒绝生成（避免抓到别的站）';
        return $res;
    }
    foreach (hs_langs() as $lg => $prefix) {
        $html = hs_fetch($prefix, $host);
        $ok = false;
        if (strlen($html) > 500 && stripos($html, '<html') !== false && stripos($html, '<body') !== false) {
            $sub = $prefix === '' ? $dir : $dir . '/' . $lg;
            if (!is_dir($sub)) { @mkdir($sub, 0755, true); }
            $file = $sub . '/index.html';
            $ok = @file_put_contents($file, $html) !== false;
            @chmod($file, 0644);
        }
        $res[$lg] = $ok;
    }
    set_option('hs.gen_at', (string)time());
    return $res;
}

/** 直接输出命中文件并终止 */
function hs_serve(string $rel): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-HomeStatic: HIT');
    echo (string)file_get_contents(hs_dir() . '/' . $rel);
    exit;
}

/** 前台入口（boot.php include 后调用）：命中直出；陈旧时防抖自动重建 */
function hs_front(): void
{
    if ((string)get_option('home_static.enabled', '1') !== '1') { return; }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { return; }
    // 生成回环请求（X-HS-GEN）→ 走正常动态渲染
    if (isset($_SERVER['HTTP_X_HS_GEN'])) { return; }
    $path = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string)parse_url($path, PHP_URL_PATH);
    $rel = hs_map($path);
    if ($rel === null) { return; }
    $file = hs_dir() . '/' . $rel;
    if (!is_file($file)) {
        hs_try_rebuild();
        return;
    }
    $fresh = (time() - (int)@filemtime($file)) <= hs_ttl();
    if ($fresh) {
        hs_serve($rel);
    } else {
        hs_try_rebuild(); // 该次访问继续走动态渲染（内容最新），同时后台静默重建
    }
}

/** 自动重建（60 秒防抖，串行） */
function hs_try_rebuild(): void
{
    $now = time();
    $last = (int)get_option('hs.rebuild_at', '0');
    if ($now - $last < 60) { return; }
    set_option('hs.rebuild_at', (string)$now);
    hs_generate();
}
