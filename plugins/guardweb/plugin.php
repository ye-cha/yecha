<?php
declare(strict_types=1);
// 访客防线 · 防采集 / 防 AI 拉取（通用插件）
// 思路：只拦截「机器人读取型流量」——AI 爬虫、采集脚本、高频抓取；搜索引擎蜘蛛白名单放行，真人零感知。
return [
    'id' => 'guardweb',
    'name' => '防采集',
    'ver' => '1.0.0',
    'desc' => '通用防采集与 AI 拉取防线：识别并拦截 AI 爬虫（GPTBot/ClaudeBot/PerplexityBot/CCBot/Bytespider/Amazonbot 等）、采集脚本与高频抓取，搜索引擎蜘蛛（Google/Bing/百度/搜狗/360/Yandex 等）自动放行；支持 UA 特征、频率限制、防重放校验、命中日志与全站开关，不影响正常访客与收录。',
    'author' => 'YeCha',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0-beta'], // beta 站点 YC_VERSION=1.0.0-beta；stable 1.0.0 亦满足（<= 比较）
    'toggle' => true,
    'tables' => ['guard_logs'],
    'uninstall' => ['tables' => ['guard_logs'], 'options' => [], 'like' => ['guardweb.%']],
    'admin' => ['label' => '防采集', 'desc' => '拦截 AI 爬虫 / 采集脚本 / 高频抓取（搜索引擎与真人不受影响）'],
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_guardweb_admin',
    'register_file' => 'boot.php',
];
