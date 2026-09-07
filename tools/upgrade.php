<?php
/** CLI 升级：docker compose exec -T php php tools/upgrade.php --component core|poly|notes|theme-yecha [--dry-run]
 * 安全：建议先到后台「工具 → 备份」或后台「系统升级」一键（其会自动全库备份）再执行本 CLI。 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';

$component = 'core';
$dry = false;
$args = $argv; array_shift($args);
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--component') { $component = (string)($args[++$i] ?? 'core'); }
    if ($args[$i] === '--dry-run') { $dry = true; }
}
if (!isset(Upgrade::COMPONENTS[$component])) { fwrite(STDERR, "unknown component\n"); exit(2); }
$pending = Upgrade::pending($component);
echo Upgrade::COMPONENTS[$component] . " v" . Upgrade::componentVersion($component)
    . " | 待执行迁移 " . count($pending) . "\n";
foreach ($pending as $f) { echo "  - " . basename($f) . "\n"; }
if ($dry || !$pending) { exit(0); }

echo "开始应用（无自动备份：请确保已在后台生成备份）\n";
$res = Upgrade::apply($component, fn(string $s) => print('  ' . $s . "\n"));
echo 'applied=' . count($res['applied']) . ($res['error'] ? ' error=' . $res['error'] : '') . "\n";
Cache::flush('pages');
Cache::flush('blocks');
exit($res['error'] ? 1 : 0);
