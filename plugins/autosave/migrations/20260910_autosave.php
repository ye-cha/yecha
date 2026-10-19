<?php
declare(strict_types=1);
/** 文章自动保存 · 建表迁移(幂等) */
if (!function_exists('asv_ensure_table')) { require_once dirname(__DIR__) . '/inc.php'; }
asv_ensure_table();
echo "autosave: ye_autosaves ready\n";
