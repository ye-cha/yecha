<?php
declare(strict_types=1);
/**
 * StatX · 迁移（幂等）
 * ① 建 4 张健康表（ye_statx_health_targets / _checks / _daily / _events）
 * ② 播种首批探测目标（P0 统计链路 + 主力站首页；P1 sitemap/内容页/云市场清单）
 *
 * 说明：只在本站（gushu_stage）建表。跨站复用方案见 plugins/statx/README.md。
 * 绝不触碰 ye_contents 等内核表；绝不重启任何服务。
 */
require_once dirname(__DIR__) . '/inc.php';

foreach (statx_ensure_schema() as $line) {
    echo 'statx: ' . $line . "\n";
}
$seed = statx_seed_targets();
echo 'statx: targets added=' . $seed['added'] . ' kept=' . $seed['kept'] . "\n";
echo "statx: schema ready\n";
