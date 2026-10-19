<?php
declare(strict_types=1);
/** 邮件配置自检/试发 CLI
 * 用法：
 *   docker compose exec -T php php tools/mail-test.php                 # 发给后台 notify_to
 *   docker compose exec -T php php tools/mail-test.php you@example.com
 * 配置：后台「设置 → 邮件通知」；常见免费 SMTP：
 *   QQ 邮箱  smtp.qq.com 465 SSL / 587 STARTTLS，密码=授权码（设置→账号→开启 SMTP）
 *   163 邮箱 smtp.163.com 465 SSL，密码=授权码
 *   Gmail   smtp.gmail.com 587 STARTTLS(ssl=0)，密码=应用专用密码
 *   （境外域名更推荐腾讯云/阿里云邮件推送或 Resend/Brevo 免费额度） */
require dirname(__DIR__) . '/config.inc.php';
require dirname(__DIR__) . '/core/bootstrap.php';
$to = (string)($argv[1] ?? '');
if ($to === '') { $to = (string)get_option('mail.notify_to', ''); }
$host = (string)get_option('mail.host', '');
$port = (int)get_option('mail.port', 465);
$ssl = (string)get_option('mail.ssl', '1') === '1';
$user = (string)get_option('mail.user', '');
$from = (string)get_option('mail.from', '');
$enabled = (string)get_option('mail.enabled', '0') === '1';
echo 'enabled=', ($enabled ? '1' : '0'), ' host=', $host, ' port=', $port, ' ssl=', $ssl ? '1' : '0', ' user=', $user !== '' ? '(set)' : '', ' from=', $from, PHP_EOL;
if (!$enabled) { echo "FAIL: mail.enabled=0 —— 请先在后台「设置→邮件通知」开启并保存\n"; exit(2); }
if ($host === '' || $from === '' || $to === '') { echo "FAIL: 缺少 host/from/to（后台填 SMTP 与发件人；--to 或后台 notify_to）\n"; exit(2); }
$r = \Mail::send($host, $port, $ssl, $user, (string)get_option('mail.pass', ''), $from, $to, '[YeCha] 邮件配置测试', "如果你收到这封邮件，说明 SMTP 配置可用。\n—— YeCha");
if ($r === true) { echo "OK: 已发送给 {$to}\n"; }
else { echo "FAIL: " . (string)$r . "\n"; exit(1); }
