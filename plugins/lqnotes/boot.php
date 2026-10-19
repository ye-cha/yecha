<?php
declare(strict_types=1);
/**
 * lqnotes · 注册文件（manifest 的 register_file）
 * 由 Plugins::boot() 在每个请求的最早期 include（早于路由解析、早于全页缓存）。
 * 只做一件事：把「认领 URL」挂到内核既有的 'route' 挂载点上。
 * 认领成功由回调内部 output+exit；没认领则 payload 原样返回 ⇒ 既有路由 100% 不变。
 */
if (!function_exists('lqn_route')) { require_once __DIR__ . '/inc.php'; }
if (class_exists('Hooks')) { Hooks::on('route', 'lqn_route', 5); }
