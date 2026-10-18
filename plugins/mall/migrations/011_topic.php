<?php
/**
 * M15 专题活动（topic）迁移（幂等 .php 迁移）
 * - ye_mall_topic：专题（标题/封面/简介/富文本内容/排序/状态）
 * - ye_mall_topic_goods：专题 ↔ 商品关联（专题页挂商品）
 *
 * 对标 51puer /topic.html「专题活动列表」。
 */
declare(strict_types=1);

Db::run('CREATE TABLE IF NOT EXISTS ' . Db::t('mall_topic') . " (
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    title varchar(128) NOT NULL DEFAULT '' COMMENT 'topic title',
    summary varchar(255) NOT NULL DEFAULT '' COMMENT 'short summary',
    cover varchar(255) NOT NULL DEFAULT '' COMMENT 'cover image url',
    content text COMMENT 'rich text content (html or markdown)',
    sort int(11) NOT NULL DEFAULT 0,
    status tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1 on 0 off',
    is_delete_time int(10) unsigned NOT NULL DEFAULT 0,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status (status, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='mall topic'");

Db::run('CREATE TABLE IF NOT EXISTS ' . Db::t('mall_topic_goods') . " (
    id int(10) unsigned NOT NULL AUTO_INCREMENT,
    topic_id int(10) unsigned NOT NULL DEFAULT 0,
    goods_id int(10) unsigned NOT NULL DEFAULT 0,
    sort int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_topic_goods (topic_id, goods_id),
    KEY idx_topic (topic_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='mall topic goods'");