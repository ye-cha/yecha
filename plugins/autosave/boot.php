<?php
declare(strict_types=1);
/** 文章自动保存 · 加载入口:注册编辑器挂载点 + 处理 /autosave/* 接口 */
if (!function_exists('asv_register_hooks')) {
    require_once __DIR__ . '/inc.php';
}
asv_register_hooks();
asv_front();
