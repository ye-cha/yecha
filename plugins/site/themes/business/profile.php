<?php
declare(strict_types=1);
// 行业风格包：商务稳重（制造 / 通用 B2B）
// 词表决定前台板块名称与文案，home 决定首页区块顺序（可自由增删/换序）。
return [
    'label' => '商务稳重（制造 / 通用 B2B）',
    'lex' => [
        'stats_title' => '资质与产能',
        'process_title' => '合作流程',
        'team_title' => '技术团队',
        'list_title' => '产品系列',
        'table_title' => '规格参数一览',
        // 全部使用默认词表（产品中心 / 客户案例 / 新闻动态 …）
    ],
    'home' => ['hero', 'stats', 'features', 'products', 'cases', 'news', 'cta'],
];
