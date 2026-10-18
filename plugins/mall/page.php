<?php
declare(strict_types=1);
// 商城插件 · 独立页兜底：/mall 单段 slug 经 Plugins::renderPage 走这里（正常路径由内核 case 'mall' 分发，两者同入口）
require_once __DIR__ . '/lib.php';

function yc_mall_page(string $slug): array
{
    return Mall::dispatch([], $_GET);
}
