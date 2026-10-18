<?php
declare(strict_types=1);
/**
 * 文章自动保存 · CLI
 * 用法:
 *   php plugins/autosave/autosave.php --init            建表 + 记录迁移（幂等）
 *   php plugins/autosave/autosave.php --stats           统计
 *   php plugins/autosave/autosave.php --list[=20]       最近版本列表
 *   php plugins/autosave/autosave.php --purge           清理过期版本（按保留天数）
 *   php plugins/autosave/autosave.php --purge-content=235 [--lang=zh]   清掉某篇全部版本
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
require dirname(__DIR__, 2) . '/config.inc.php';
require dirname(__DIR__, 2) . '/core/bootstrap.php';
if (!function_exists('asv_table')) { require __DIR__ . '/inc.php'; }

$argvv = $argv ?? [];
$has = static fn(string $f): bool => in_array($f, $argvv, true);
$val = static function (string $prefix) use ($argvv): string {
    foreach ($argvv as $a) { if (str_starts_with($a, $prefix)) { return substr($a, strlen($prefix)); } }
    return '';
};

if ($has('--init')) {
    $r = Upgrade::apply('autosave', static fn(string $s) => print('  ' . $s . "\n"));
    if ($r['error']) { fwrite(STDERR, "迁移失败: {$r['error']}\n"); exit(1); }
    asv_ensure_table();
    echo "autosave 初始化完成（表 " . asv_table() . "）\n";
    exit(0);
}

asv_ensure_table();

if ($has('--stats')) {
    $s = asv_stats();
    echo "版本总数 : {$s['n']}\n涉及文章 : {$s['docs']}\n字数合计 : {$s['chars']}\n";
    echo "最早     : " . ($s['first_at'] !== '' ? $s['first_at'] : '—') . "\n";
    echo "最近     : " . ($s['last_at'] !== '' ? $s['last_at'] : '—') . "\n";
    echo "策略     : 间隔 {$s['interval']}s · 每篇保留 {$s['keep']} 版 · 保留 {$s['days']} 天\n";
    exit(0);
}

if ($has('--purge')) {
    $n = asv_purge_expired();
    echo "已清理过期版本: {$n}（保留 " . asv_days() . " 天）\n";
    exit(0);
}

$pc = $val('--purge-content=');
if ($pc !== '') {
    $lang = $val('--lang=');
    $n = asv_delete_content((int)$pc, $lang);
    echo "已删除 content_id={$pc}" . ($lang !== '' ? " lang={$lang}" : '') . " 的 {$n} 个版本\n";
    exit(0);
}

$limit = 20;
$lv = $val('--list=');
if ($lv !== '') { $limit = max(1, (int)$lv); }
$rows = asv_list(null, null, $limit);
if (!$rows) { echo "（暂无快照）\n"; exit(0); }
printf("%-6s %-20s %-8s %-8s %-6s %-8s %s\n", 'id', 'created_at', 'cid', 'lang', '字', '来源', '标题');
foreach ($rows as $r) {
    printf("%-6s %-20s %-8s %-8s %-6s %-8s %s\n", $r['id'], $r['created_at'], $r['content_id'], $r['lang'],
        $r['plain_chars'], $r['reason'], mb_substr((string)$r['title'], 0, 40));
}
exit(0);
