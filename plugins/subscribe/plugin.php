<?php
declare(strict_types=1);
/**
 * 邮件订阅 (Newsletter) · YeCha 通用插件
 * - 订阅页 /subscribe(表单,双确认)、/subscribe/confirm、/subscribe/unsub
 * - 按语言选择发件人显示名;SMTP 复用「设置 → 邮件通知」的 mail.* 配置
 * - 后台:订阅名单 / 测试发信 / 立即发送周刊 / CSV 导出
 * - 周刊脚本:plugins/subscribe/newsletter.php(可挂计划任务)
 */
return [
    'id' => 'subscribe',
    'name' => '邮件订阅',
    'ver' => '1.0.0',
    'desc' => '邮件订阅(Newsletter):前台订阅表单 + 双确认邮件 + 一键退订,后台名单管理,周刊一键群发,支持多语言与定时任务。',
    'author' => 'YeCha',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0-beta.2'],
    'toggle' => true,
    'tables' => ['subscribers'],
    'uninstall' => [
        'tables' => ['subscribers'],
        'options' => [],
        'like' => ['subscribe.%', 'plugin.subscribe.%'],
    ],
    'admin' => ['label' => '邮件订阅', 'desc' => '订阅名单 / 测试发信 / 周刊群发'],
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_subscribe_admin',
    'register_file' => 'boot.php',
];
