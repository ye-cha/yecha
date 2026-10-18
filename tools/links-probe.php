<?php
declare(strict_types=1);
/** links 插件机器体检 CLI：站点存活 + RSS 可达/解析/最近更新（供 cron 定期执行）
 * 用法：
 *   docker compose exec -T php php tools/links-probe.php
 *   docker compose exec -T php php tools/links-probe.php --id=5
 *   docker compose exec -T php php tools/links-probe.php --limit=20
 * 说明：只探测公网 http(s)（内网地址自动拒绝）；每站超时 ~8s、RSS ~10s。 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';
$file = dirname(__DIR__) . '/plugins/links/data.php';
if (!is_file($file)) { fwrite(STDERR, "links plugin not found\n"); exit(1); }
require_once $file;

$id = 0;
$limit = 0;
foreach ($argv as $a) {
    if (preg_match('/^--id=(\d+)$/', $a, $m)) { $id = (int)$m[1]; }
    elseif (preg_match('/^--limit=(\d+)$/', $a, $m)) { $limit = (int)$m[1]; }
}

$sql = 'SELECT * FROM ' . Db::t('links');
$params = [];
if ($id > 0) {
    $sql .= ' WHERE id=?';
    $params[] = $id;
} else {
    $sql .= " WHERE status IN ('show','pending')";
}
$sql .= ' ORDER BY id ASC' . ($limit > 0 ? ' LIMIT ' . $limit : '');
$rows = Db::rows($sql, $params);
if (!$rows) { echo "no links to probe\n"; exit(0); }
$okN = 0;
foreach ($rows as $l) {
    $r = yc_links_probe_link($l);
    $okN += $r['state'] === 'ok' ? 1 : 0;
    $last = $r['last_post_at'] ?? '';
    printf("[%d] %s  state=%-6s last_post=%s  %s\n", (int)$l['id'], mb_substr((string)$l['name'], 0, 24), $r['state'], $last !== null ? $last : '-', mb_substr($r['note'], 0, 60));
}
echo "probed " . count($rows) . " links (ok=" . $okN . ")\n";
Cache::flush('blocks');
