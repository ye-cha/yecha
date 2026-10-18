<?php
/** 一次性数据清洗：把 contents / poly_contents 的 excerpt、description 中的 Markdown 语法清成纯文本 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';
$fixed = ['contents_excerpt' => 0, 'contents_desc' => 0, 'poly_excerpt' => 0, 'poly_desc' => 0];

foreach (Db::rows("SELECT id, excerpt, description FROM " . Db::t('contents')) as $r) {
    $up = [];
    $ex = (string)$r['excerpt'];
    $de = (string)$r['description'];
    if ($ex !== '' && preg_match('/[!#*_~`\[\]\(\)>]/', $ex)) {
        $c = Str::cleanMd($ex);
        if ($c !== $ex) { $up['excerpt'] = $c; $fixed['contents_excerpt']++; }
    }
    if ($de !== '' && preg_match('/[!#*_~`\[\]\(\)>]/', $de)) {
        $c = Str::cleanMd($de);
        if ($c !== $de) { $up['description'] = $c; $fixed['contents_desc']++; }
    }
    if ($up) { Db::update('contents', $up, 'id=?', [(int)$r['id']]); }
}

foreach (Db::rows("SELECT id, excerpt, description FROM " . Db::t('poly_contents')) as $r) {
    $up = [];
    $ex = (string)$r['excerpt'];
    $de = (string)$r['description'];
    if ($ex !== '' && preg_match('/[!#*_~`\[\]\(\)>]/', $ex)) {
        $c = Str::cleanMd($ex);
        if ($c !== $ex) { $up['excerpt'] = $c; $fixed['poly_excerpt']++; }
    }
    if ($de !== '' && preg_match('/[!#*_~`\[\]\(\)>]/', $de)) {
        $c = Str::cleanMd($de);
        if ($c !== $de) { $up['description'] = $c; $fixed['poly_desc']++; }
    }
    if ($up) { Db::update('poly_contents', $up, 'id=?', [(int)$r['id']]); }
}

Cache::flush('pages');
Cache::flush('blocks');
echo json_encode($fixed, JSON_UNESCAPED_UNICODE) . "\n";
