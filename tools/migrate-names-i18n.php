<?php
/** 迁移：metas.names_i18n（JSON，兼容 name_en）→ 把既有 name_en 并入 en 键 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';
$T = Db::t('metas');
$cols = array_map('strtolower', array_column(Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$T}'"), 'COLUMN_NAME'));
if (!in_array('names_i18n', $cols, true)) {
    Db::run("ALTER TABLE {$T} ADD COLUMN names_i18n TEXT NULL COMMENT '术语多语言名 JSON {code:name}' AFTER name_en");
    echo "metas.names_i18n added\n";
} else { echo "metas.names_i18n exists\n"; }
$fixed = 0;
foreach (Db::rows("SELECT id, name_en FROM {$T} WHERE name_en<>''") as $r) {
    $i18n = json_decode((string)Db::one("SELECT names_i18n FROM {$T} WHERE id=?", [(int)$r['id']]), true);
    if (!is_array($i18n)) { $i18n = []; }
    if (($i18n['en'] ?? '') === '') { $i18n['en'] = $r['name_en']; }
    Db::update('metas', ['names_i18n' => json_encode($i18n, JSON_UNESCAPED_UNICODE)], 'id=?', [(int)$r['id']]);
    $fixed++;
}
echo "merged name_en into names_i18n: {$fixed}\n";
Cache::flush('pages');
Cache::flush('blocks');
echo "done\n";
