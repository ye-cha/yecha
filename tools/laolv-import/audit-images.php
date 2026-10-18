<?php
// 审计图片（仅位图/矢量）：正文 URL 指向的本地文件缺失则报出
require dirname(__DIR__, 2) . '/config.inc.php';
require dirname(__DIR__, 2) . '/core/bootstrap.php';
$imgExt = 'webp|png|jpe?g|gif|svg';
$rows = Db::rows('SELECT id, slug, content FROM ' . Db::t('contents') . " WHERE type='post' AND deleted_at IS NULL");
$missing = [];
$seen = 0;
foreach ($rows as $r) {
    if (preg_match_all('~(/uploads/[A-Za-z0-9_\-./%]+\.(?:' . $imgExt . '))~', (string)$r['content'], $mm)) {
        foreach (array_unique($mm[1]) as $rel) {
            $seen++;
            $f = dirname(__DIR__, 2) . $rel;
            if (!is_file($f) || filesize($f) === 0) { $missing[] = $r['slug'] . ' :: ' . $rel; }
        }
    }
}
echo 'img_refs=' . $seen . ' missing=' . count($missing) . "\n";
foreach ($missing as $m) { echo 'MISS ' . $m . "\n"; }
