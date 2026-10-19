<?php
declare(strict_types=1);
// 邮件订阅 · 前台注册(每请求执行):拦截 /subscribe 相关路由
if (!function_exists('sub_front')) { require_once __DIR__ . '/inc.php'; }
sub_front();
