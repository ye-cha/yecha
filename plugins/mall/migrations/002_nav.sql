-- YeCha Mall 插件 · 导航入口（幂等）
-- 走宿主 Nav::list 的 nav.custom 官方通道（不改内核 defs()）。
-- 注意：生产站 nav.custom 已有自定义导航（论坛/市场等），因此语义是「追加」而非「新建」：
--   1) nav.custom 不存在 → 新建含商城项；
--   2) 已存在但无 mall 项 → JSON_ARRAY_APPEND 追加；
--   3) 已存在且含 mall 项 → 不动（幂等，重复启用安全）。
INSERT INTO `ye_options` (`name`, `value`, `autoload`)
SELECT 'nav.custom', '[{"key":"mall","label":"商城","uri":"/mall","zone":["top"]}]', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ye_options` WHERE `name` = 'nav.custom');

UPDATE `ye_options`
SET `value` = JSON_ARRAY_APPEND(`value`, '$', CAST('{"key":"mall","label":"商城","uri":"/mall","zone":["top"]}' AS JSON))
WHERE `name` = 'nav.custom'
  AND NOT JSON_CONTAINS(`value`, '{"key":"mall"}', '$');
