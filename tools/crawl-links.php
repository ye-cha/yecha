<?php
/** 全站链接巡检：种子 = 各语言首页/栏目/feed/sitemap + 系统页 + 抽样文章（zh/en/ja/es），逐个请求报告非 2xx。
 * 用法：docker compose exec -T php php tools/crawl-links.php [--sample N]
 * 退出码：0=全部通过；1=存在失败链接
 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';

$args = $argv; array_shift($args);
$sample = 40;
foreach ($args as $a) {
    if (str_starts_with($a, '--sample=')) { $sample = max(1, (int)substr($a, 9)); }
}

$urls = ['/', '/pages', '/about.html', '/login', '/register', '/search', '/archives', '/tag/', '/category/'];
$langs = array_values(array_filter(Poly::enabled(), fn($l) => $l !== Poly::defaultLang()));
foreach ($langs as $l) {
    $urls[] = '/' . $l . '/';
    foreach (['archives', 'tag/', 'category/', 'search', 'feed'] as $p) { $urls[] = '/' . $l . '/' . $p; }
}
$urls[] = '/sitemap.xml';

// 抽样文章：基准 + 每语言译文
foreach (Db::rows("SELECT slug FROM " . Db::t('contents') . " WHERE type='post' AND status='publish' AND deleted_at IS NULL ORDER BY views DESC LIMIT " . $sample) as $r) {
    $urls[] = '/' . $r['slug'] . '.html';
}
foreach ($langs as $l) {
    foreach (Db::rows("SELECT slug FROM " . Db::t('poly_contents') . " WHERE lang=? AND status='publish' ORDER BY id DESC LIMIT " . $sample, [$l]) as $r) {
        $urls[] = '/' . $l . '/' . $r['slug'] . '.html';
    }
}
$urls = array_values(array_unique($urls));
sort($urls);

function fetch_code(string $path): array
{
    $ctx = stream_context_create(['http' => ['timeout' => 12, 'ignore_errors' => true]]);
    $t0 = microtime(true);
    $body = @file_get_contents('http://nginx' . $path, false, $ctx);
    $ms = (int)((microtime(true) - $t0) * 1000);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) { $code = (int)$m[1]; }
    return ['code' => $code === 0 && $body !== false ? 200 : $code, 'ms' => $ms];
}

$bad = [];
$ok = 0;
foreach ($urls as $u) {
    $r = fetch_code($u);
    if (in_array($r['code'], [200, 301, 302], true)) { $ok++; }
    else { $bad[] = $u . ' => ' . $r['code'] . ' (' . $r['ms'] . 'ms)'; }
}
echo 'crawled=' . count($urls) . ' ok=' . $ok . ' bad=' . count($bad) . "\n";
foreach ($bad as $b) { echo '  BAD ' . $b . "\n"; }
exit($bad ? 1 : 0);
