<?php
/**
 * AI 知识库 · 按年份生成（kb-years）v3
 *
 * 背景与两次踩坑（都记下来，避免重犯）：
 *   ① 系统提示词规定「锚点 ID 只能取自知识库明确写出的商品 ID」。
 *      原知识库只有 2015/2016 的商品 ID → 用户问 2020/2007 年时 AI 只能
 *      给纯文字 + 「去站内搜索」→ 用户点过去找不到（体验上就是 404）。
 *   ② v2 用 --years=10 取「最新 10 年」，得到 2025→2016，
 *      结果 2015 年（恰是原知识库最老的年份）反而没覆盖 —— 必须按
 *      「有商品的年份」全量生成，而不是按「最近 N 年」。
 *   ③ 标题里会把「150年古树」的 150 误当成年份 → 必须限定 1990~今年。
 *
 * 用法：php tools/kb-years.php [--write] [--top=5] [--min=1]
 *       --top  每年最多推荐几款（默认 5）
 *       --min  该年在售多少款才生成条目（默认 1）
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

// ★ CLI 守卫：不设 REQUEST_URI 会被 home-static 等插件拦截并静默退出（退出码 255）
$_SERVER['REQUEST_URI'] = '/__cli__/no-home-map';

$ROOT = dirname(__DIR__);
require $ROOT . '/config.inc.php';
require $ROOT . '/core/bootstrap.php';

$write = in_array('--write', $argv, true);
$top = 5;
$minGoods = 1;
foreach ($argv as $a) {
    if (preg_match('/^--top=(\d+)$/', $a, $m)) { $top = max(1, min(12, (int)$m[1])); }
    if (preg_match('/^--min=(\d+)$/', $a, $m)) { $minGoods = max(1, (int)$m[1]); }
}

// ★ mall 插件的表前缀是 mall_，必须 Db::t('mall_goods')，不是 Db::t('goods')
$GT = Db::t('mall_goods');
$KT = Db::t('ai_knowledge');
$thisYear = (int)date('Y');

echo "AI 知识库 · 按年份生成" . ($write ? "【写入】" : "【预览】") . "\n";
echo "参数: 每年 {$top} 款 / 至少 {$minGoods} 款才生成 / 年份范围 1990-{$thisYear}\n\n";

// 全量扫描年份（不截断），在 PHP 侧过滤掉假年份（150 / 80 / 0 这类）
$all = Db::rows("SELECT CAST(SUBSTRING(title, LOCATE('年', title) - 4, 4) AS UNSIGNED) AS y,"
    . " COUNT(*) AS n FROM {$GT}"
    . " WHERE status=1 AND is_delete_time=0 AND total_stock>0"
    . "   AND title REGEXP '20[0-2][0-9]年'"
    . " GROUP BY y ORDER BY y DESC");

$years = [];
foreach ($all as $r) {
    $y = (int)$r['y'];
    $n = (int)$r['n'];
    // ★ 过滤假年份：只接受 1990 ~ 今年
    if ($y < 1990 || $y > $thisYear) { continue; }
    if ($n < $minGoods) { continue; }
    $years[$y] = $n;
}
krsort($years);

echo "有效年份 " . count($years) . " 个：" . implode(', ', array_keys($years)) . "\n\n";

$made = 0;
foreach ($years as $y => $total) {
    $picks = Db::rows("SELECT id, title, min_price FROM {$GT}"
        . " WHERE status=1 AND is_delete_time=0 AND total_stock>0 AND title LIKE ?"
        . " ORDER BY sales_count DESC, min_price ASC LIMIT " . (int)$top, ['%' . $y . '年%']);
    if (!$picks) { continue; }

    $lines = [];
    foreach ($picks as $g) {
        $lines[] = '  · ' . trim((string)$g['title']) . ' ||goods:' . (int)$g['id'] . '||'
            . '（¥' . number_format((float)$g['min_price'], 0) . '）';
    }
    $age = $thisYear - $y;
    $stage = $age >= 10 ? '已进入中期茶，青味/渥堆味基本转化，到手即可饮用'
        : ($age >= 5 ? '转化中，口感开始柔化，可饮可存'
        : '较新，建议醒茶或再存放一段时间');

    $content = "【{$y} 年在售茶品】\n"
        . "共 {$total} 款，以下按热度推荐 " . count($picks) . " 款：\n"
        . implode("\n", $lines) . "\n\n"
        . "【陈期】{$y} 年的茶至今约 {$age} 年，{$stage}。\n"
        . "【说明】均为站内在售商品；价格以商品页为准；年份以包装标注为准，本站不做鉴定。";

    $title = "{$y} 年在售茶品推荐";
    $exists = Db::one("SELECT id FROM {$KT} WHERE title=? AND is_delete_time=0 LIMIT 1", [$title]);

    printf("%s %s（在售 %d 款，推荐 %d）\n", $exists ? '[更新]' : '[新增]', $title, $total, count($picks));
    foreach ($picks as $g) {
        printf("      %s  ¥%s  #%d\n", mb_substr(trim((string)$g['title']), 0, 30),
            number_format((float)$g['min_price'], 0), (int)$g['id']);
    }

    if ($write) {
        $data = ['title' => $title, 'content' => $content, 'tags' => "年份,{$y}年,推荐",
                 'sort' => 300 - ($y % 300), 'is_show' => 1, 'updated_at' => date('Y-m-d H:i:s')];
        if ($exists) {
            Db::update('ai_knowledge', $data, 'id=?', [(int)$exists]);
        } else {
            $data['is_delete_time'] = 0;
            $data['created_at'] = date('Y-m-d H:i:s');
            Db::insert('ai_knowledge', $data);
        }
        $made++;
    }
    echo "\n";
}

echo str_repeat('-', 62) . "\n";
echo $write ? "已写入/更新 {$made} 条年份知识\n" : "预览完成（加 --write 写入）\n";
echo "知识库总数: " . (int)Db::one("SELECT COUNT(*) FROM {$KT} WHERE is_delete_time=0") . "\n";
