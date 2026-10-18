<?php
declare(strict_types=1);
/** 邮件订阅 · 周刊发送 CLI(建议挂计划任务,例:每周日 09:00)
 *   php plugins/subscribe/newsletter.php --dry
 *   php plugins/subscribe/newsletter.php --test you@example.com
 *   php plugins/subscribe/newsletter.php
 */
require dirname(__DIR__, 2) . '/config.inc.php';
require dirname(__DIR__, 2) . '/core/bootstrap.php';
require __DIR__ . '/inc.php';

$dry = in_array('--dry', $argv, true);
$test = '';
foreach ($argv as $i => $a) { if ($a === '--test' && isset($argv[$i + 1])) { $test = (string)$argv[$i + 1]; } }
$limit = 3;
foreach ($argv as $i => $a) { if ($a === '--limit' && isset($argv[$i + 1])) { $limit = max(1, (int)$argv[$i + 1]); } }

$r = sub_send_weekly($dry, $test, $limit);
foreach ($r['log'] as $line) { echo $line . "\n"; }
echo "done sent={$r['sent']} fail={$r['fail']} skipped={$r['skipped']}\n";
