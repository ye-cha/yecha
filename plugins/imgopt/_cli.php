<?php
declare(strict_types=1);
// 一次性 CLI（支持并行 worker）：
//   全量：   php _cli.php
//   切片：   php _cli.php <lo> <hi>       处理 ye_mall_goods.id ∈ [lo,hi]
//   附加：   php _cli.php extras          处理 品牌/分类/评价 图（少量，单 worker）
require __DIR__ . '/../../config.inc.php';
require __DIR__ . '/../../core/bootstrap.php';
require __DIR__ . '/lib.php';

$arg = $argv[1] ?? '';
$stat = [];
$t0 = microtime(true);
if ($arg === 'extras') {
    ImgOpt::runExtras($stat);
} elseif (is_numeric($arg)) {
    $lo = (int)$arg;
    $hi = (int)($argv[2] ?? 2147483647);
    ImgOpt::runRange($lo, $hi, $stat);
} else {
    ImgOpt::run($stat);
}
$sec = round(microtime(true) - $t0, 1);
fwrite(STDERR, "worker[{$arg}] DONE in {$sec}s\n");
echo json_encode($stat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
