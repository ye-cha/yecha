<?php
declare(strict_types=1);
/**
 * lqbookmarks · 注册文件（manifest 的 register_file）
 * 由 Plugins::boot() 在每个请求最早期 include（早于路由解析、早于全页缓存）。
 * 只做一件事：把「认领 URL」挂到内核既有的 'route' 挂载点上（与 lqnotes 同一手法）。
 */
if (!function_exists('lqb_route')) { require_once __DIR__ . '/inc.php'; }
if (class_exists('Hooks')) { Hooks::on('route', 'lqb_route', 6); }
