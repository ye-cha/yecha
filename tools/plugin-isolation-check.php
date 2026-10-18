<?php
/**
 * 插件隔离验收：停用全部可停用插件（notes/poly）→ 主系统核心页面仍 200、多语零痕迹 → 恢复启用。
 * 用法：docker compose exec -T php php tools/plugin-isolation-check.php
 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';

function get_page(string $u): array
{
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    $body = @file_get_contents('http://nginx' . $u, false, $ctx);
    return ['body' => $body === false ? '' : $body];
}

$fails = 0;
set_option('plugin.poly.enabled', '0');
set_option('plugin.notes.enabled', '0');
Cache::flush('pages');
Cache::flush('blocks');

$home = get_page('/');
$tag = get_page('/tag/');
$arch = get_page('/archives');
$en = get_page('/en/');
$ok1 = $home['body'] !== '' && str_contains($home['body'], '最新文章');
$ok2 = $tag['body'] !== '' && str_contains($tag['body'], '标签索引');
$ok3 = $arch['body'] !== '' && str_contains($arch['body'], '文章归档');
$ok4 = $en['body'] !== '' && !str_contains($en['body'], 'Latest posts') && str_contains($en['body'], '404');
$noTrace = $home['body'] !== '' && !str_contains($home['body'], 'English') && !str_contains($home['body'], 'poly-langs');

echo 'home=' . ($ok1 ? 'ok' : 'bad') . ' tag=' . ($ok2 ? 'ok' : 'bad') . ' archives=' . ($ok3 ? 'ok' : 'bad')
    . ' en404=' . ($ok4 ? 'ok' : 'bad') . ' zh-no-en-trace=' . ($noTrace ? 'yes' : 'no') . "\n";
if (!$ok1 || !$ok2 || !$ok3 || !$ok4 || !$noTrace) { $fails++; }

// 恢复
set_option('plugin.poly.enabled', '1');
set_option('plugin.notes.enabled', '1');
Cache::flush('pages');
Cache::flush('blocks');
$enOn = get_page('/en/');
$ok5 = $enOn['body'] !== '' && str_contains($enOn['body'], 'Latest posts');
echo 'restored /en/=' . ($ok5 ? 'ok' : 'bad') . "\n";
if (!$ok5) { $fails++; }
echo ($fails === 0 ? 'ISOLATION-OK' : 'ISOLATION-FAIL') . "\n";
exit($fails === 0 ? 0 : 1);
