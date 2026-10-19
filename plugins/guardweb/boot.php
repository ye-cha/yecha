<?php
declare(strict_types=1);
/**
 * 访客防线 · 前台拦截入口（register_file：Plugins::boot() 阶段加载，早于路由/全页缓存）
 * 原则：
 *  1) 只处理「前台页面 GET/HEAD」，后台/安装/登录用户/资源类一律放行；
 *  2) 搜索引擎官方蜘蛛 → 放行（不误伤收录）；AI 爬虫与采集器 → 拦截；
 *  3) 全部判断零 DB 查询（命中才落日志），单请求开销 <0.1ms；
 *  4) 插件默认全关（需后台开启），开箱不影响任何正常访问。
 */
if (PHP_SAPI === 'cli') { return; }

function yc_guardweb_cfg(): array
{
    static $cfg = null;
    if ($cfg !== null) { return $cfg; }
    $raw = (string)get_option('guardweb.cfg', '');
    $j = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    $cfg = $j + [
        'on'          => '0',    // 总开关
        'mode'        => 'block',// 命中 AI/采集：block 403 | empty 200 空 | gone 410 | 429
        'block_se'    => '0',    // 是否顺带屏蔽未知搜索引擎蜘蛛（默认放行一切“疑似蜘蛛”）
        'rate'        => '0',    // 频率限制开关
        'rate_n'      => '120',  // 窗口内页面上限
        'rate_win'    => '60',   // 窗口秒
        'log'         => '1',    // 命中落日志
        'robots'      => '1',    // robots.txt 输出 AI Disallow 增强
        'trust_cf'    => '1',    // 信任 CF-Connecting-IP
        'allow_ips'   => '',     // 永远放行的 IP（逗号/换行分隔）
        'notice'      => 'Access denied by guard.',
    ];
    return $cfg;
}

/** 前台是否可介入：后台/安装/API/资源放行，仅前台页面 GET/HEAD 且未登录 */
function yc_guardweb_can_intercept(): bool
{
    $m = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($m, ['GET', 'HEAD'], true)) { return false; }
    $logged = class_exists('Auth') ? Auth::check() : false;
    if ($logged) { return false; }
    $sn = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $sn = '/' . ltrim($sn, '/');
    foreach (['/admin', '/install', '/uploads', '/themes', '/plugins', '/assets', '/var', '/tools'] as $p) {
        if ($sn === $p || str_starts_with($sn, $p . '/')) { return false; }
    }
    return true;
}

function yc_guardweb_ip(): string
{
    $cfg = yc_guardweb_cfg();
    if (($cfg['trust_cf'] ?? '1') === '1') {
        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) { return $cf; }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** 允许名单 IP？ */
function yc_guardweb_ip_allowed(string $ip): bool
{
    $raw = trim((string)(yc_guardweb_cfg()['allow_ips'] ?? ''));
    if ($raw === '') { return false; }
    $list = preg_split('/[\s,]+/', $raw) ?: [];
    foreach ($list as $x) {
        $x = trim($x);
        if ($x === '') { continue; }
        if (strpos($x, '/') !== false) {
            [$n, $bits] = array_pad(explode('/', $x, 2), 2, '32');
            if (filter_var($n, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ctype_digit((string)$bits)) {
                $l = (int)$bits; if ($l < 0 || $l > 32) { continue; }
                if (ip_in_cidr($ip, $x)) { return true; }
            }
            continue;
        }
        if ($ip === $x) { return true; }
    }
    return false;
}

function ip_in_cidr(string $ip, string $cidr): bool
{
    [$subnet, $bits] = explode('/', $cidr);
    $bits = (int)$bits;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) || filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return false; // IPv6 CIDR 需扩展示例，IPv4 已覆盖
    }
    $ipL = ip2long($ip); $subL = ip2long($subnet);
    if ($ipL === false || $subL === false) { return false; }
    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
    return (($ipL & $mask) === ($subL & $mask));
}

/** UA 归一化（小写，去版本号细节前的稳定判断串） */
function yc_guardweb_ua(): string
{
    static $ua = null;
    if ($ua !== null) { return $ua; }
    $ua = mb_strtolower(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
    return $ua;
}

/* ---------------- 机器人分类 ---------------- */

/** 合法机器人白名单（搜索引擎官方蜘蛛 + 社交分享预览 + 收录/监控服务商）→ true 放行
 *  注意：与 AI 名单严格互斥——bytespider/petalbot 等“搜索兼 AI 训练”爬虫默认按 AI 处理（可后台移除）。 */
function yc_guardweb_is_se(string $ua): bool
{
    if ($ua === '') { return false; }
    $se = [
        'googlebot', 'bingbot', 'baiduspider', 'sogou', '360spider', 'yandex',
        'duckduckbot', 'applebot', 'naverbot', 'slurp', 'yisouspider',
        'facebookexternalhit', 'linkedinbot', 'twitterbot',
        'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot', 'pingdom', 'uptimerobot',
    ];
    foreach ($se as $k) {
        if (strpos($ua, $k) !== false) { return true; }
    }
    return false;
}

/** AI / 大模型训练爬虫（与白名单互斥；Googlebot 本体不含 google-extended token） */
function yc_guardweb_is_ai(string $ua): bool
{
    if ($ua === '') { return false; }
    $ai = [
        'gptbot', 'chatgpt-user', 'oai-searchbot', 'anthropic', 'claudebot',
        'claude-web', 'claude-searchbot', 'perplexitybot', 'ccbot', 'amazonbot',
        'cohere', 'cohere-ai', 'google-extended', 'meta-externalagent',
        'imagesiftbot', 'timpibot', 'applebot-extended', 'diffbot', 'ai2bot',
        'omgili', 'omgilibot', 'bytespider', 'petalbot', 'seekr', 'dataforseo',
        'firecrawl', 'crawl4ai', 'sidetrade', 'magpie-crawler', 'netsprint',
    ];
    foreach ($ai as $k) {
        if (strpos($ua, $k) !== false) { return true; }
    }
    return false;
}

/** 通用采集/脚本特征 → true 视为采集器（不含主流蜘蛛/AI，命中蜘蛛白名单在前已放行） */
function yc_guardweb_is_scraper(string $ua): bool
{
    if ($ua === '') { return false; } // 空 UA 单独处理
    $sc = [
        'python-requests', 'python-urllib', 'aiohttp', 'httpx', 'scrapy', 'pycurl',
        'wget', 'curl/', 'go-http-client', 'okhttp', 'httpclient', 'apache-httpclient',
        'java/', 'libwww-perl', 'lwp', 'ruby', 'mechanize', 'phantomjs',
        'headlesschrome', 'selenium', 'playwright', 'puppeteer',
        'axios', 'node-fetch', 'postman', 'insomnia', 'httpie',
        'masscan', 'zgrab', 'nmap', 'nikto', 'sqlmap',
    ];
    foreach ($sc as $k) {
        if (strpos($ua, $k) !== false) { return true; }
    }
    // 无浏览器内核标记的非空 UA（疑似脚本）：含 Mozilla 或 AppleWebKit 才算真人浏览器
    if (!preg_match('/(mozilla|applewebkit|gecko|chrome|safari|edge|firefox|opera)/', $ua)) {
        // 保守：只有“完全没有浏览器特征且含 http 客户端词”才拦（上面已覆盖），这里不再扩大
        return false;
    }
    return false;
}

/** 命中响应：写日志 + 输出 */
function yc_guardweb_hit(string $reason): void
{
    $cfg = yc_guardweb_cfg();
    if (($cfg['log'] ?? '1') === '1') {
        try {
            $T = Db::t('guard_logs');
            $ua = mb_substr(yc_guardweb_ua(), 0, 200);
            $path = mb_substr((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), 0, 200);
            $ip = yc_guardweb_ip();
            $day = date('Y-m-d');
            Db::run(
                "INSERT INTO {$T} (day, ip, ua, path, reason, n, created_at) VALUES (?,?,?,?,?,1,NOW()) "
                . 'ON DUPLICATE KEY UPDATE n = n + 1, updated_at = NOW()',
                [$day, $ip, $ua, $path, $reason]
            );
        } catch (Throwable $e) { /* 日志失败不影响拦截 */ }
    }
    $mode = (string)($cfg['mode'] ?? 'block');
    $code = $mode === 'empty' ? 200 : ($mode === 'gone' ? 410 : ($mode === '429' ? 429 : 403));
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Guard: ' . $reason);
        if ($code === 429) { header('Retry-After: 60'); }
    }
    http_response_code($code);
    if ($mode === 'empty') {
        // 采集器拿到 200 + 空壳（连提示都不给）
        echo '';
    } else {
        $msg = trim((string)($cfg['notice'] ?? ''));
        if ($msg === '') { $msg = 'Access denied by guard.'; }
        echo $msg;
    }
    exit;
}

/* ---------------- 前台拦截主流程 ---------------- */
function yc_guardweb_run(): void
{
    $cfg = yc_guardweb_cfg();
    if (($cfg['on'] ?? '0') !== '1') { return; }

    // robots.txt 增强（蜘蛛与 AI 都要能读 robots；不拦，先于一切）
    $path0 = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $base = '/' . ltrim($path0, '/');
    // 后台 / 安装 / 静态资源路径：即使被重写到 PHP 也一律放行
    foreach (['/admin', '/install', '/uploads/', '/themes/', '/plugins/', '/assets/', '/var/', '/tools/', '/api/'] as $p) {
        if (str_starts_with($base, $p)) { return; }
    }
    // 健康检查端点：监控/面板探测放行（避免自检误伤）
    if (in_array($base, ['/health', '/healthz', '/ping'], true)) { return; }
    if (($cfg['robots'] ?? '1') === '1' && ($base === '/robots.txt')) {
        if (!headers_sent()) { header('Content-Type: text/plain; charset=utf-8'); }
        $lines = ["User-agent: *", "Allow: /", "", "Sitemap: " . yc_guardweb_origin() . '/sitemap.xml'];
        if (yc_guardweb_ai_list()) {
            $lines[] = '';
            foreach (yc_guardweb_ai_list() as $au) {
                $lines[] = "User-agent: {$au}";
                $lines[] = "Disallow: /";
                $lines[] = '';
            }
        }
        echo implode("\n", $lines);
        exit;
    }

    if (!yc_guardweb_can_intercept()) { return; }

    $ip = yc_guardweb_ip();
    if (yc_guardweb_ip_allowed($ip)) { return; }

    $ua = yc_guardweb_ua();

    // 1) 空 UA：无浏览器 UA 的页面抓取（脚本/部分爬虫）— 拦截（除非是已知蜘蛛 token，但蜘蛛必有 UA）
    if ($ua === '') {
        yc_guardweb_hit('no_ua');
        return;
    }
    // 2) 搜索引擎蜘蛛 → 放行（含 Googlebot 等官方 token；即使带 AI 词如 gptbot 的字节爬虫在 is_se 已排除——gptbot 不在 se 名单，见下）
    if (yc_guardweb_is_se($ua)) { return; }
    // 3) AI 训练爬虫 → 拦截
    if (yc_guardweb_is_ai($ua)) { yc_guardweb_hit('ai_bot'); return; }
    // 4) 采集脚本特征 → 拦截
    if (yc_guardweb_is_scraper($ua)) { yc_guardweb_hit('scraper'); return; }

    // 5) 频率限制：仅对“无浏览器完整特征”或命中过 UA 名单的访客计数，真人浏览器不计数（零 IO）
    if (($cfg['rate'] ?? '0') === '1' && !preg_match('/(chrome|firefox|safari|edge)/', $ua)) {
        $dir = sys_get_temp_dir() . '/yc_guardweb';
        @mkdir($dir, 0777, true);
        $file = $dir . '/' . md5($ip);
        $now = time();
        $win = max(10, (int)($cfg['rate_win'] ?? 60));
        $max = max(5, (int)($cfg['rate_n'] ?? 120));
        $cnt = 1;
        $t0 = $now;
        if (is_file($file)) {
            $d = @file_get_contents($file);
            [$t0, $cnt] = array_pad(explode('|', (string)$d), 2, '0');
            $t0 = (int)$t0; $cnt = (int)$cnt;
        }
        if (($now - $t0) > $win) { $t0 = $now; $cnt = 1; }
        else { $cnt++; }
        @file_put_contents($file, $t0 . '|' . $cnt, LOCK_EX);
        if ($cnt > $max) { yc_guardweb_hit('rate'); return; }
    }
}

/** robots 输出辅助 */
function yc_guardweb_origin(): string
{
    $s = yc_theme_scheme();
    $h = (string)($_SERVER['HTTP_HOST'] ?? '');
    return $h !== '' ? $s . '://' . $h : '';
}

function yc_guardweb_ai_list(): array
{
    return [
        'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'OAI-SearchBot-Web', 'anthropic-ai',
        'ClaudeBot', 'Claude-Web', 'Claude-SearchBot', 'PerplexityBot', 'CCBot',
        'Amazonbot', 'cohere-ai', 'Bytespider', 'Google-Extended', 'Applebot-Extended',
        'meta-externalagent', 'ImagesiftBot', 'Diffbot', 'Timpibot', 'AI2Bot',
        'FirecrawlBot', 'Crawl4AI', 'Sidetrade indexer bot',
    ];
}

// 立即执行（此文件被 Plugins::boot() include，位于 index.php 路由解析之前）
yc_guardweb_run();
