<?php
declare(strict_types=1);
// 词条页静态化 · 前台/后台每请求注册（manifest 的 register_file）
// 只做两件事：① 登录用户下发「请让开静态直出」标记 cookie；② 注册请求结束时的变更看门狗。
// ★ 全站请求路径上不得有任何副作用（生成/删除一律「先删、后异步重建」，见 inc.php）。
if (!function_exists('es_cookie_sync')) { require_once __DIR__ . '/inc.php'; }
es_cookie_sync();
register_shutdown_function('es_watch_shutdown');
