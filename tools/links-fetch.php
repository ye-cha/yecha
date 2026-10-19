<?php
declare(strict_types=1);
/** links 插件 RSS 聚合 CLI：抓已收录博客的新文章元数据入 ye_circle_posts（阶段2 温柔聚合）
 * 只存标题/原文URL/摘要(≤400)/发布时间，绝不存正文；同 URL 去重。
 * 用法：
 *   docker compose exec -T php php tools/links-fetch.php
 *   docker compose exec -T php php tools/links-fetch.php --id=5
 *   docker compose exec -T php php tools/links-fetch.php --back=90 --per=20
 * 建议 cron：每小时一次（与博友圈同级频率）。UA 见 data.php（YeCha-LinksProbe）。 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';
$file = dirname(__DIR__) . '/plugins/links/data.php';
if (!is_file($file)) { fwrite(STDERR, "links plugin not found\n"); exit(1); }
require_once $file;

$id = 0;
$back = 120;
$per = 20;
foreach ($argv as $a) {
    if (preg_match('/^--id=(\d+)$/', $a, $m)) { $id = (int)$m[1]; }
    elseif (preg_match('/^--back=(\d+)$/', $a, $m)) { $back = max(7, min(730, (int)$m[1])); }
    elseif (preg_match('/^--per=(\d+)$/', $a, $m)) { $per = max(1, min(50, (int)$m[1])); }
}

$sql = "SELECT * FROM " . Db::t('links') . " WHERE status='show'";
$params = [];
if ($id > 0) { $sql .= ' AND id=?'; $params[] = $id; }
$sql .= ' ORDER BY id ASC';
$rows = Db::rows($sql, $params);
$totalNew = 0;
$totalSeen = 0;
$n = 0;
foreach ($rows as $l) {
    if (trim((string)$l['rss']) === '') { continue; }
    $n++;
    $r = yc_links_fetch_link($l, $back, $per);
    $totalNew += $r['new'];
    $totalSeen += $r['seen'];
    printf("[%d] %-22s rss=%-44s new=%-3d seen=%d\n", (int)$l['id'], mb_substr((string)$l['name'], 0, 22), mb_substr((string)$l['rss'], 0, 44), $r['new'], $r['seen']);
}
echo "fetched {$n} feeds, new={$totalNew} seen={$totalSeen}\n";
Cache::flush('pages');
