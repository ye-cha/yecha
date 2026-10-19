<?php
declare(strict_types=1);
// 迁移（幂等）：多语言译文表 ye_poly_contents（本插件安装时自动建表；老站升级时表已存在则跳过）
$t = Db::t('poly_contents');
$cols = Db::rows('SHOW TABLES LIKE "' . $t . '"');
if (!$cols) {
    Db::run("CREATE TABLE {$t} (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      group_id BIGINT UNSIGNED NOT NULL COMMENT '翻译组=ye_contents.id（默认语言为基准）',
      lang VARCHAR(8) NOT NULL COMMENT '如 en',
      title VARCHAR(200) NOT NULL DEFAULT '',
      slug VARCHAR(200) NOT NULL DEFAULT '' COMMENT '该语言前缀下唯一',
      excerpt TEXT NULL,
      cat_ids TEXT NULL COMMENT '英文版独立分类 meta id JSON',
      tag_ids TEXT NULL COMMENT '英文版独立标签 meta id JSON',
      keywords VARCHAR(255) NOT NULL DEFAULT '',
      description VARCHAR(300) NOT NULL DEFAULT '' COMMENT 'meta description（该语言独立）',
      content LONGTEXT NULL,
      status VARCHAR(16) NOT NULL DEFAULT 'draft' COMMENT 'draft | publish',
      ai_generated TINYINT NOT NULL DEFAULT 0,
      manual_override TINYINT NOT NULL DEFAULT 0 COMMENT '人工手动修正（AI 全量重译跳过该篇）',
      ai_revision INT NOT NULL DEFAULT 0,
      stale TINYINT NOT NULL DEFAULT 0 COMMENT '基准原文更新后=1（需重译）',
      last_ai_at DATETIME NULL DEFAULT NULL,
      source_lang VARCHAR(8) DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uk_poly_lang_slug (lang, slug),
      KEY idx_poly_group (group_id, lang)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
// 插件安装默认启用多语目录 zh+en（若站点尚未配置 languages）
if (get_option('site.languages', null) === null) {
    set_option('site.languages', json_encode(['zh', 'en'], JSON_UNESCAPED_UNICODE));
}
if (get_option('site.default_lang', null) === null) {
    set_option('site.default_lang', 'zh');
}
