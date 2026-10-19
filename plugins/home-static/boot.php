<?php
declare(strict_types=1);
// 首页生成静态 · 前台注册（register_file）：Plugins::boot 时随前台每请求执行。
if (!function_exists('hs_front')) { require_once __DIR__ . '/inc.php'; }
hs_front();
