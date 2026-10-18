<?php
/** 一次性：为缺 cat_ids/tag_ids 的存量译文补上基准中文的分类/标签（幂等） */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';
$T = Db::t('poly_contents');
$fixed = 0;
foreach (Db::rows("SELECT id, group_id, cat_ids, tag_ids FROM {$T} WHERE lang<>? ", [Poly::defaultLang()]) as $p) {
    $needCat = $p['cat_ids'] === null || $p['cat_ids'] === '';
    $needTag = $p['tag_ids'] === null || $p['tag_ids'] === '';
    if (!$needCat && !$needTag) { continue; }
    $tax = Poly::baseTaxIds((int)$p['group_id']);
    Db::update('poly_contents', [
        'cat_ids' => json_encode($tax['cats'], JSON_UNESCAPED_UNICODE),
        'tag_ids' => json_encode($tax['tags'], JSON_UNESCAPED_UNICODE),
    ], 'id=?', [(int)$p['id']]);
    $fixed++;
}
Cache::flush('pages');
Cache::flush('blocks');
echo "backfilled rows: {$fixed}\n";
