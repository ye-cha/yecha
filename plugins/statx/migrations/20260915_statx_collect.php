<?php
declare(strict_types=1);
/**
 * StatX · 迁移（幂等）—— 能力① 自建统计采集
 * ① 建 4 张采集表（ye_statx_hits / _daily / _sites / _bots）
 * ② 播种站点登记（本站 + 能力② 目标表里出现的各 site_key）
 * ③ 生成 rt.php（端点零 SQL 运行参数：开关/路径/Origin 白名单/tj token 映射）
 *
 * 说明：只在本站自己的库里建表。绝不触碰 ye_contents 等内核表；绝不重启任何服务。
 */
require_once dirname(__DIR__) . '/inc.php';

foreach (statx_collect_ensure_schema() as $line) {
    echo 'statx: ' . $line . "\n";
}
$s = statx_collect_seed_sites();
echo 'statx: collect sites added=' . $s['added'] . ' kept=' . $s['kept'] . "\n";

$rt = statx_collect_rt_write();
echo 'statx: rt.php written=' . (!empty($rt['_written']) ? 'yes' : 'no')
    . ' on=' . (int)$rt['on'] . ' site=' . (string)$rt['site']
    . ' script=' . (string)$rt['script'] . ' path=' . (string)$rt['path']
    . ' jsver=' . (string)$rt['jsver'] . ' origins=' . count((array)$rt['origins'])
    . ' tjmap=' . count((array)$rt['tjmap']) . "\n";
echo "statx: collect schema ready\n";
