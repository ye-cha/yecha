<?php
declare(strict_types=1);
/**
 * 二次修正：正文图片 URL 统一指向本机已下载副本（按 images.tsv 文件名映射），并清理异常前缀
 * 用法：docker compose exec -T php php tools/laolv-import/fix-content-imgs.php
 */
require dirname(__DIR__, 2) . '/config.inc.php';
require dirname(__DIR__, 2) . '/core/bootstrap.php';

$dir = dirname(__DIR__, 2) . '/data/laolv';
$up = dirname(__DIR__, 2) . '/uploads';

// basename -> '/uploads/'.dest
$byName = [];
foreach (file($dir . '/images.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (!str_contains($line, "\t")) { continue; }
    [$src, $dest] = explode("\t", $line, 2);
    $name = basename(parse_url($src, PHP_URL_PATH));
    if ($name !== '') { $byName[$name] = '/uploads/' . ltrim($dest, '/'); }
}

$rows = Db::rows('SELECT id, slug, content FROM ' . Db::t('contents') . " WHERE type='post' AND deleted_at IS NULL");
$fixed = 0;
$perSlug = [];
foreach ($rows as $r) {
    $content = (string)$r['content'];
    $orig = $content;
    // 1) 清理双重前缀
    $content = str_replace('/uploads/laolv/uploads/', '/uploads/laolv/', $content);
    // 1b) 剥离解析残留的孤儿外链片段（图片/附件在源站 /usr/uploads、/uploads/import 等，本机已用副本）
    $content = preg_replace('~\]\(/(?:usr/uploads|uploads)/(?!laolv/)[^)\s]+\)~', '', $content) ?? $content;
    // 2) markdown 图片 url 归一：非 /uploads/laolv/ 开头 → 按 basename 找副本
    $content = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', function ($m) use ($byName, $up) {
        $url = $m[2];
        if (str_starts_with($url, '/uploads/laolv/')) { return $m[0]; }
        if (str_starts_with($url, '/uploads/')) {
            // 本地已有(直接 laolv 下同名?) 检查
            $cand = '/uploads/laolv' . substr($url, strlen('/uploads'));
            if (is_file(dirname(__DIR__, 2) . $cand)) { return '![' . $m[1] . '](' . $cand . ')'; }
        }
        $name = basename(parse_url($url, PHP_URL_PATH));
        if ($name !== '' && isset($byName[$name]) && is_file(dirname(__DIR__, 2) . $byName[$name])) {
            return '![' . $m[1] . '](' . $byName[$name] . ')';
        }
        // 允许非图片附件/未知：保留原文
        return $m[0];
    }, $content);
    if ($content !== $orig) {
        Db::update('contents', ['content' => $content], 'id=?', [(int)$r['id']]);
        $fixed++;
    }
}
Cache::flush('pages');
Cache::flush('blocks');
echo "rows_scanned=" . count($rows) . " fixed={$fixed}\n";
