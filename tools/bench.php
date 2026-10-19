<?php
/** 轻量压测：warm 后顺序采样并输出 均值/最大/P95（单位 ms）。用法：
 * docker compose exec -T php php tools/bench.php [n] [/url ...]
 * 宿主并行压测建议 ab/wrk：ab -n 2000 -c 50 http://localhost:8082/
 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';

$args = $argv; array_shift($args);
$n = 20;
$urls = [];
foreach ($args as $a) {
    if (ctype_digit($a)) { $n = max(3, (int)$a); continue; }
    if ($a !== '') { $urls[] = $a; }
}
if (!$urls) {
    $row = Db::row("SELECT slug FROM " . Db::t('contents') . " WHERE type='post' AND status='publish' AND deleted_at IS NULL ORDER BY views DESC LIMIT 1");
    $urls = ['/', '/en/', '/tag/', '/' . ($row['slug'] ?? 'learning-notes') . '.html'];
}

function bench_url(string $path, int $n): array
{
    // warm 3 次
    for ($i = 0; $i < 3; $i++) { @file_get_contents('http://nginx' . $path); }
    $samples = [];
    $bytes = 0;
    for ($i = 0; $i < $n; $i++) {
        $t0 = microtime(true);
        $body = @file_get_contents('http://nginx' . $path);
        $samples[] = (microtime(true) - $t0) * 1000;
        if ($body !== false) { $bytes = strlen($body); }
    }
    sort($samples);
    $avg = array_sum($samples) / count($samples);
    $max = end($samples);
    $p95 = $samples[(int)floor(count($samples) * 0.95) - 1];
    return ['avg' => round($avg, 1), 'p95' => round($p95, 1), 'max' => round($max, 1), 'bytes' => $bytes];
}

printf("%-46s %8s %8s %8s %8s\n", 'url', 'avg(ms)', 'p95(ms)', 'max(ms)', 'bytes');
foreach ($urls as $u) {
    $r = bench_url($u, $n);
    printf("%-46s %8.1f %8.1f %8.1f %8d\n", $u, $r['avg'], $r['p95'], $r['max'], $r['bytes']);
}
echo "bench done (warm cache, sequential; 并发请用宿主 ab/wrk)\n";
