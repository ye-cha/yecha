<?php
declare(strict_types=1);
// 迁移：为既有分类/标签回填 slug_i18n（语言化 URL 别名）。
// 幂等：按 id 顺序逐条基于 names_i18n 重新生成（生成函数自带同类型冲突去重），
// 重复执行结果不变（同 id 顺序 + 已入库值参与去重）。
$t = Db::t('metas');
$cols = Db::rows('SHOW COLUMNS FROM ' . $t);
$fields = array_map('strval', array_column($cols, 'Field'));
if (!in_array('slug_i18n', $fields, true)) { return; } // 前置迁移未跑则不处理
$rows = Db::rows(
    "SELECT id, type, names_i18n FROM {$t} WHERE type IN ('category','tag') AND names_i18n IS NOT NULL AND names_i18n<>'' ORDER BY id ASC"
);
$done = 0;
foreach ($rows as $r) {
    $i18n = json_decode((string)$r['names_i18n'], true);
    if (!is_array($i18n)) { continue; }
    $map = Poly::termSlugI18nFor($i18n, (string)$r['type'], (int)$r['id']);
    $json = json_encode($map, JSON_UNESCAPED_UNICODE);
    Db::update('metas', ['slug_i18n' => $json], 'id=?', [(int)$r['id']]);
    $done++;
}
// 无 names_i18n 的术语 slug_i18n 置空串（保持列一致）
Db::run("UPDATE {$t} SET slug_i18n='' WHERE slug_i18n IS NULL");
echo "slug_i18n backfilled: {$done}\n";
