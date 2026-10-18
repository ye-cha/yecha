<?php
// 站点健康检查（轻量，供 cron 定时跑）
//
// 为什么需要：2026-09-25 生产商品页出现 502。
// 根因不是本站代码，而是【同机其他应用】的慢查询把共享 MySQL 拖垮
//   t_atsvg     单条扫描 3,298,847 行 / 16.6 秒
//   gushu_stage 单条扫描   128,684 行 / 8.0 秒
// 服务器 load average 61（2 核机），PHP-FPM worker 被拖到超时被杀
//   → nginx 记 recv() failed (104: Connection reset by peer) → 用户见 502
//
// 本脚本做三件事
//   1. 自测关键页面可达性与耗时
//   2. 记录本站资源占用（便于与同机应用区分责任）
//   3. 失败时落盘 var/log/health-fail.log，及早发现而不是等用户投诉
//
// cron 建议: */10 * * * * cd /www/wwwroot/yecha.org && php tools/health.php >> var/log/health.log 2>&1
//
// 注意: 原写法用 /* */ 块注释，块内含全角分号会让 PHP 解析器提前结束注释
//       （Parse error: unexpected token "*"）——本文件因此改为 // 行注释。
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }
$_SERVER['REQUEST_URI'] = '/__cli__/no-home-map';

$ROOT = dirname(__DIR__);
require $ROOT . '/config.inc.php';
require $ROOT . '/core/bootstrap.php';

$host = (string)get_option('site.domain', 'yecha.org');
$host = preg_replace('#^https?://#', '', rtrim($host, '/'));
if ($host === '') { $host = 'yecha.org'; }

/* 要探测的页面：覆盖各路由类型 */
$paths = [
    '/'                         => '首页',
    '/list.html'                => '商品列表',
    '/goods-3373.html'          => '商品详情',
    '/help'                     => '帮助中心',
    '/brand'                    => '品牌页',
    '/mall/api/home'            => '首页接口',
];

$results = [];
$fails = [];

foreach ($paths as $p => $label) {
    $ch = curl_init('https://' . $host . $p);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_RESOLVE => [$host . ':443:127.0.0.1'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'YeCha-HealthCheck/1.0',
        CURLOPT_NOBODY => false,
    ]);
    $t0 = microtime(true);
    $body = curl_exec($ch);
    $cost = (microtime(true) - $t0) * 1000;
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $ok = ($code === 200 && $body !== false);
    // 顺手检查页面是否被截断（502/500 有时会带残缺 HTML）
    // ★ 只对 HTML 页面判断：JSON 接口（如 /mall/api/home）本来就小，
    //   用固定字节阈值会误报（实测 2134 字节的合法 JSON 被判为截断）。
    $len = is_string($body) ? strlen($body) : 0;
    $isHtml = is_string($body) && (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<html') !== false);
    $truncated = $ok && $isHtml && $len < 5000 && stripos($body, '</html>') === false;
    $results[] = sprintf('  %-24s %s  %4d ms  %7d 字节%s',
        $p, $ok ? '✓' : '★', (int)$cost, $len, $truncated ? '  ← 疑似截断' : '');
    if (!$ok) { $fails[] = $p . ' HTTP ' . $code . ($err !== '' ? ' (' . $err . ')' : ''); }
    if ($truncated) { $fails[] = $p . ' 页面疑似截断（' . $len . ' 字节）'; }
}

/* 资源情况 */
$load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
$memTotal = 0; $memAvail = 0;
if (is_readable('/proc/meminfo')) {
    foreach (file('/proc/meminfo') as $l) {
        if (preg_match('/^MemTotal:\s+(\d+)/', $l, $m)) { $memTotal = (int)$m[1] * 1024; }
        if (preg_match('/^MemAvailable:\s+(\d+)/', $l, $m)) { $memAvail = (int)$m[1] * 1024; }
    }
}
$cores = 0;
if (is_readable('/proc/cpuinfo')) {
    $cores = substr_count((string)file_get_contents('/proc/cpuinfo'), 'processor');
}
$cores = max(1, $cores);

$ts = date('Y-m-d H:i:s');
$lines = [];
$lines[] = '[' . $ts . '] 健康检查';
$lines[] = implode("\n", $results);
$lines[] = sprintf('  负载 load1=%.2f load5=%.2f load15=%.2f (核数 %d, 每核 %.2f)',
    $load[0], $load[1], $load[2], $cores, $load[0] / $cores);
$lines[] = sprintf('  内存 可用 %.0f MB / 共 %.0f MB', $memAvail / 1048576, $memTotal / 1048576);
$lines[] = sprintf('  本站 PHP 峰值内存 %.1f MB', memory_get_peak_usage(true) / 1048576);

/* 负载告警阈值：每核 load > 4 说明机器被别人拖死，本站会开始 502 */
$overloaded = ($load[0] / $cores) > 4;
if ($overloaded) {
    $lines[] = '  ⚠ 机器过载（每核 load ' . round($load[0] / $cores, 2) . '）：本站可能开始出现 502，'
        . '根因通常是同机其他应用的慢查询占用共享 MySQL。';
}
if ($memAvail < 300 * 1048576) {
    $lines[] = '  ⚠ 可用内存不足 300MB，PHP-FPM worker 有被 OOM 杀的风险。';
}

echo implode("\n", $lines) . "\n";
if ($fails) {
    echo '  ★ 失败 ' . count($fails) . ' 项: ' . implode('; ', $fails) . "\n";
    // 落盘，便于事后核对 502 时间点
    @file_put_contents($ROOT . '/var/log/health-fail.log',
        '[' . $ts . '] ' . implode('; ', $fails) . "\n", FILE_APPEND);
}
exit($fails ? 1 : 0);
