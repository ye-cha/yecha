<?php
declare(strict_types=1);
/** 邮件订阅 · 建表迁移(幂等) */
if (!function_exists('sub_ensure_table')) { require_once dirname(__DIR__) . '/inc.php'; }
sub_ensure_table();
echo "subscribe: ye_subscribers ready\n";
