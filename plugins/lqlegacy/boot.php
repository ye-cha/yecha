<?php
declare(strict_types=1);
/**
 * lqlegacy · 注册文件
 * ★ 只把函数定义进来（供 core/Auth.php 的 `function_exists('lql_verify_legacy')` 命中）。
 * ★ **不注册任何路由**（不调用 Hooks::on('route', …)）。
 */
if (!function_exists('lql_verify_legacy')) { require_once __DIR__ . '/inc.php'; }
