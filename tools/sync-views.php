<?php
declare(strict_types=1);
/** 阅读量回写：把 ye_views 累加进 contents.views 后清空明细（供 cron / 后台定期执行）
 *
 * 用法：
 *   php tools/sync-views.php              # 汇总 + 清 pages/blocks 缓存（数字立即生效）
 *   php tools/sync-views.php --no-flush   # 只汇总不清缓存（大站用；数字随页面缓存自然刷新）
 *
 * 设计要点：
 * - 只做「累加」（views = views + n），**不会覆盖**历史导入的阅读量；
 * - 明细折进总数后清空 ye_views，重复执行安全；
 * - **无待汇总数据时不写库、不清缓存**（cron 高频跑也不影响页面缓存）；
 * - 40 万行级大站（如图片站）建议用 --no-flush，避免每次汇总把整页缓存清空。
 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';

$flush = !in_array('--no-flush', $argv, true);
$V = Db::t('views');
$C = Db::t('contents');

if (!Db::rows('SHOW TABLES LIKE "' . $V . '"')) {
    echo "no ye_views table — run upgrade first\n";
    exit(0);
}

$agg = Db::rows("SELECT content_id, SUM(n) v FROM {$V} GROUP BY content_id");
if (!$agg) {
    echo "views: nothing to sync\n";
    exit(0);
}

$total = 0;
$n = 0;
foreach ($agg as $r) {
    $v = (int)$r['v'];
    if ($v <= 0) { continue; }
    Db::run("UPDATE {$C} SET views = views + ?, updated_at = updated_at WHERE id = ?", [$v, (int)$r['content_id']]);   /* ★ updated_at 显式自赋值 ⇒ 抑制 ON UPDATE，防止污染"内容最后编辑时间" */
    $total += $v;
    $n++;
}
Db::run("DELETE FROM {$V}");
if ($flush) {
    Cache::flush('pages');
    Cache::flush('blocks');
}
echo 'synced views: ' . $total . ' (' . $n . ' 篇' . ($flush ? '' : '，未清缓存') . ")\n";
