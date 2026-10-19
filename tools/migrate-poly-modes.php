<?php
/** 迁移（双模式引擎）：poly_contents 增加 manual_override / ai_revision / last_ai_at */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';
$T = Db::t('poly_contents');
$cols = array_map('strtolower', array_column(Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$T}'"), 'COLUMN_NAME'));
$add = [
    'manual_override' => "TINYINT NOT NULL DEFAULT 0 COMMENT '人工手动修正（AI 全量重译跳过该篇）'",
    'ai_revision'     => "INT NOT NULL DEFAULT 0 COMMENT 'AI 翻译修订号'",
    'last_ai_at'      => "DATETIME NULL COMMENT '最近一次 AI 生成时间'",
];
foreach ($add as $col => $def) {
    if (!in_array($col, $cols, true)) {
        Db::run("ALTER TABLE {$T} ADD COLUMN {$col} {$def}");
        echo "poly_contents.{$col} added\n";
    } else { echo "poly_contents.{$col} exists\n"; }
}
echo "done\n";
