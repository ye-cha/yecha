<?php
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';
foreach ([27, 76, 152] as $g) {
    $r = Db::row('SELECT title,content,updated_at FROM ' . Db::t('poly_contents') . ' WHERE group_id=? AND lang=?', [$g, 'ja']);
    $c = (string)$r['content'];
    $k = preg_match_all('/[\x{3040}-\x{30FF}]/u', $c);
    $h = preg_match_all('/\p{Han}/u', $c);
    $lat = preg_match_all('/[A-Za-z]{3,}/', $c);
    echo "gid=$g kana=$k han=$h latinwords=$lat upd=" . $r['updated_at'] . "\n  head: " . mb_substr($c, 0, 150) . "\n";
}
