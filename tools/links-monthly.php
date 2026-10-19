<?php
declare(strict_types=1);
/** links 月度精选发刊 CLI（订阅者收到当月站长置顶的精选文章链接）
 * 用法：
 *   docker compose exec -T php php tools/links-monthly.php          # 当月
 *   docker compose exec -T php php tools/links-monthly.php --ym=2026-09
 * 说明：邮件需先在后台「设置 → 邮件通知」开启并配置 SMTP（mail.enabled=1），否则打印 skip。 */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';
$file = dirname(__DIR__) . '/plugins/links/data.php';
if (!is_file($file)) { fwrite(STDERR, "links plugin not found\n"); exit(1); }
require_once $file;

$ym = date('Y-m');
foreach ($argv as $a) {
    if (preg_match('/^--ym=(\d{4}-\d{2})$/D', $a, $m)) { $ym = $m[1]; }
}
$t = Db::t('circle_posts');
$Tl = Db::t('links');
$posts = Db::rows("SELECT p.id, p.title, p.url, p.featured_at, l.name AS blog FROM {$t} p JOIN {$Tl} l ON l.id=p.link_id WHERE p.featured=1 AND p.featured_at LIKE ? ORDER BY p.featured_at DESC LIMIT 30", [$ym . '%']);
$subs = Db::rows("SELECT email, token FROM " . Db::t('circle_subs') . " WHERE type='monthly'");
if (!$posts) { echo "no featured posts for {$ym}, nothing to send\n"; }
if (!$subs) { echo "no subscribers\n"; }
$host = 'https://yecha.org';
$lines = [];
foreach ($posts as $p) {
    $lines[] = '- ' . $p['title'] . '（' . $p['blog'] . '） ' . $p['url'];
}
$body = "YeCha 博客收录圈 · {$ym} 月度精选（站长置顶 " . count($posts) . " 篇）\n\n"
    . implode("\n", $lines) . "\n\n更多请见 https://yecha.org/selected?ym={$ym}\n";
$sent = 0;
$skip = 0;
foreach ($subs as $s) {
    $unsub = $host . '/selected?unsub=' . rawurlencode($s['email']) . '&token=' . rawurlencode($s['token']);
    $b = $body . "\n不再接收：{$unsub}\n";
    $r = yc_links_send_mail($s['email'], "[YeCha 收录圈] {$ym} 月度精选", $b);
    if ($r['ok']) { $sent++; } else { $skip++; }
    printf("[%s] %s -> %s\n", $s['email'], $r['ok'] ? 'sent' : 'skip', $r['err']);
}
echo "monthly {$ym}: posts=" . count($posts) . " subs=" . count($subs) . " sent={$sent} skip={$skip}\n";
