<?php
declare(strict_types=1);
// AI 能力插件：SenseNova 为主的统一 AI 服务层 —— chat / 翻译 / 知识库 RAG / 调用日志。
// 独立于业务，供 site（企业官网）、mall、博客等任何插件复用：Ai::chat() / Ai::translate() / Ai::kbSearch()。
return [
    'id' => 'ai',
    'name' => 'AI 能力',
    'ver' => '0.1.0',
    'desc' => 'AI 能力层：SenseNova chat / 多语言翻译 / 知识库 RAG 问答 / AI 客服接口 / 调用日志，供其他插件复用。',
    'author' => 'YeCha',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0'],
    'toggle' => true,
    'tables' => [
        'ai_knowledge', 'ai_chat_session', 'ai_chat_message', 'ai_log',
    ],
    'uninstall' => [
        'tables' => [
            'ai_knowledge', 'ai_chat_session', 'ai_chat_message', 'ai_log',
        ],
        'options' => [], 'like' => ['ai.%'],
    ],
    'admin' => ['label' => 'AI 能力', 'desc' => '服务商配置 / 知识库 / 调用日志'],
    'pages' => [],
    'register_file' => 'lib.php',
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_ai_admin',
];
