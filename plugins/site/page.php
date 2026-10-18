<?php
declare(strict_types=1);
// 企业官网插件 · 独立页兜底：/site 单段 slug 经 Plugins::renderPage 走这里（正常路径由内核 case 'site' 分发，两者同入口）
require_once __DIR__ . '/lib.php';

function yc_site_page(string $slug): ?array
{
    $path = array_values(array_filter(explode('/', trim((string)($_SERVER['PATH_INFO'] ?? ''), '/'))));
    return Site::dispatch($path, $_GET);
}
