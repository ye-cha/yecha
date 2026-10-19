<?php
declare(strict_types=1);
/**
 * laolv 评论导入：解析每篇 raw/*.html 的评论列表（li.comment-item：.c-meta 昵称·时间 + 内容）
 * 仅对已导入 contents 的文章；幂等（content+作者+时间+内容 重复跳过）
 * 用法：docker compose exec -T php php tools/laolv-import/import-comments.php
 */
require dirname(__DIR__, 2) . '/config.inc.php';
require dirname(__DIR__, 2) . '/core/bootstrap.php';

$dir = dirname(__DIR__, 2) . '/data/laolv';
$rawDir = $dir . '/raw';
$T = Db::t('');
$manifest = file($dir . '/manifest.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$stat = ['files' => 0, 'new' => 0, 'skip' => 0, 'posts_with_comments' => 0];

foreach ($manifest as $line) {
    $slug = explode("\t", $line)[0];
    $row = Db::row("SELECT id FROM {$T}contents WHERE slug=? LIMIT 1", [$slug]);
    if ($row === null) { continue; }
    $htmlFile = $rawDir . '/' . $slug . '.html';
    if (!is_file($htmlFile)) { continue; }
    $stat['files']++;
    $html = (string)file_get_contents($htmlFile);
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $items = $xp->query('//div[contains(@class,"comments")]//li[contains(@class,"comment-item")]');
    if ($items === false || $items->length === 0) { continue; }
    $stat['posts_with_comments']++;
    foreach ($items as $li) {
        $metaEl = $xp->query('.//div[contains(@class,"c-meta")]', $li)->item(0);
        $author = '';
        $time = '';
        if ($metaEl !== null) {
            $mt = trim($metaEl->textContent);
            if (preg_match('/^(.*?)\s*[·•]\s*(\d{4}-\d{2}-\d{2}(?:[ T]\d{1,2}:\d{2})?)/u', $mt, $mm)) {
                $author = trim($mm[1]);
                $time = str_replace('T', ' ', $mm[2]);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $time)) { $time .= ' 12:00'; }
            } else {
                $author = trim($mt);
            }
        }
        $bodyEls = [];
        foreach ($li->childNodes as $cn) {
            if ($cn instanceof DOMElement && stripos($cn->getAttribute('class'), 'c-meta') === false) { $bodyEls[] = $cn; }
        }
        $body = '';
        foreach ($bodyEls as $be) { $body .= ' ' . trim($be->textContent); }
        $body = trim(preg_replace('/\s+/u', ' ', $body));
        if ($body === '' || mb_strlen($body) < 2) { continue; }
        if (stripos($author, 'bot') !== false || stripos($body, '还没有评论') !== false) { continue; }
        $time = $time !== '' ? date('Y-m-d H:i:s', strtotime($time)) : date('Y-m-d H:i:s', strtotime($row['updated_at'] ?? 'now'));
        $exists = Db::one('SELECT id FROM ' . Db::t('comments') . ' WHERE content_id=? AND author_name=? AND body=? AND created_at=? LIMIT 1',
            [(int)$row['id'], $author, $body, $time]);
        if ($exists !== null) { $stat['skip']++; continue; }
        Db::insert('comments', [
            'content_id' => (int)$row['id'], 'author_id' => null,
            'author_name' => mb_substr($author, 0, 100), 'author_email' => '',
            'ip' => '', 'body' => mb_substr($body, 0, 5000),
            'kind' => 'normal', 'status' => 'approved',
            'created_at' => $time,
        ]);
        $stat['new']++;
    }
}
Cache::flush('pages');
Cache::flush('blocks');
echo json_encode($stat, JSON_UNESCAPED_UNICODE) . "\n";
$total = (int)Db::one("SELECT COUNT(*) FROM {$T}comments WHERE status='approved'");
echo "approved_comments_total={$total}\n";
