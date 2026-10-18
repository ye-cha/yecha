<?php
declare(strict_types=1);
/** 体验站可达性监控（cron）：对 status ok|down 的站点 HEAD 探测并落库状态。
 *  用法：cd 站点根 && php tools/demo-mon.php */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';
function probe(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => 'YeCha-DemoMonitor/1.0 (+https://yecha.org)',
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ms = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    curl_close($ch);
    return ['code' => $code, 'ms' => $ms];
}
$rows = Db::rows('SELECT id,url FROM ' . Db::t('demo_sites') . " WHERE status IN ('ok','down')");
foreach ($rows as $r) {
    $p = probe((string)$r['url']);
    $status = ($p['code'] >= 200 && $p['code'] < 400) ? 'ok' : 'down';
    Db::update('demo_sites', [
        'status' => $status, 'last_code' => $p['code'], 'last_ms' => $p['ms'],
        'checked_at' => date('Y-m-d H:i:s'),
    ], 'id=?', [(int)$r['id']]);
    echo '#' . $r['id'] . ' ' . $status . ' http=' . $p['code'] . ' ' . $p['ms'] . "ms\n";
}
Cache::flush('pages'); Cache::flush('blocks');
echo "done\n";
