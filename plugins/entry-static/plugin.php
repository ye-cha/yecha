<?php
declare(strict_types=1);
// 词条页静态化（YeCha 通用插件）：把已发布词条页渲染成静态 HTML，由 nginx try_files 直出，
// 省掉 PHP + DB 全链路 —— 词条页在 php-fpm 上不再占子进程，池压力与 504 直接下降。
// 交互说明：
//  · 静态文件：var/entry-static/entry/<slug>.html（唯一真源 = DB 里 status=publish 的词条集合）
//  · 失效：词条被改/被撤稿/被删 ⇒ 同步删掉静态文件（此后回落内核动态渲染，含 gone301 的 301/404）；
//          站点选项或主题关键文件变化 ⇒ 触发后台全量重建。
//  · nginx 侧未命中静态文件时**必然回落 /index.php**，因此不会产生任何新的 404。
//  · 后台「词条页静态化」页可看状态、手动同步/全量重建/清空。
return [
    'id' => 'entry-static',
    'name' => '词条页静态化',
    'ver' => '1.0.0',
    'desc' => '把已发布词条页（/entry/{slug}）渲染为静态 HTML 由 nginx 直出：不占 php-fpm 子进程、不查库；改稿/撤稿即时失效并回落动态渲染；未命中绝不产生新 404。',
    'author' => 'YeCha',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0-beta'],
    'toggle' => true,
    'uninstall' => ['tables' => [], 'options' => ['entry_static.enabled'], 'like' => ['entry_static.%', 'es.%']],
    'admin' => ['label' => '词条页静态化', 'desc' => '词条静态 HTML 直出（状态 / 同步 / 全量重建）'],
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_entry_static_admin',
    'register_file' => 'boot.php',
];
