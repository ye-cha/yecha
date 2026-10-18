-- 企业官网 · 多语言译文（AI 翻译落地表）
-- 一条记录 = 某个内容项的某个语言的译文快照（JSON），前台 ?lang=xx 命中即切换显示。
CREATE TABLE IF NOT EXISTS `ye_site_i18n` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_type` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '内容类型：product / case / job',
  `item_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '内容 ID',
  `lang` VARCHAR(10) NOT NULL DEFAULT '' COMMENT '语言代码：en / ja / ko / es / ru / de / fr / ar',
  `payload` TEXT COMMENT '译文 JSON（字段名与原文一致）',
  `engine` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '翻译引擎（sensenova / zhipu / manual）',
  `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item_lang` (`item_type`, `item_id`, `lang`),
  KEY `idx_lang` (`lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='企业官网多语言译文';
