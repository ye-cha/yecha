<?php
declare(strict_types=1);
/**
 * Mail：极简 SMTP 发送（可选 STARTTLS/隐式SSL），失败返回 false，由调用方记录
 */
final class Mail
{
    /** @return bool|string true 或错误信息 */
    public static function send(string $host, int $port, bool $ssl, string $user, string $pass, string $from, string $to, string $subject, string $body, string $fromName = ''): bool|string
    {
        $remote = ($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 10);
        if ($fp === false) { return '连接失败: ' . $errstr; }
        stream_set_timeout($fp, 10);
        $r = function () use ($fp): void { while (!feof($fp) && ($l = fgets($fp, 1024)) !== false) { if (isset($l[3]) && $l[3] === ' ') { break; } } };
        $c = fn(string $s): ?string => fputs($fp, $s . "\r\n") === false ? null : $s;
        $r();
        if ($c('EHLO yecha') === null) { fclose($fp); return 'EHLO 失败'; }
        $r();
        if ($user !== '') {
            $c('AUTH LOGIN'); $r();
            $c(base64_encode($user)); $r();
            if ($c(base64_encode($pass)) === null) { fclose($fp); return 'AUTH 失败'; }
            $r();
        }
        $c('MAIL FROM:<' . $from . '>'); $r();
        $c('RCPT TO:<' . $to . '>'); $r();
        if ($c('DATA') === null) { fclose($fp); return 'DATA 失败'; }
        $r();
        fputs($fp, "Subject: " . str_replace(["\r", "\n"], '', $subject) . "\r\n");
        fputs($fp, "From: " . ($fromName !== '' ? mb_encode_mimeheader($fromName, 'UTF-8', 'B') . " <" . $from . ">" : $from) . "\r\nTo: " . $to . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n");
        fputs($fp, mb_convert_encoding($body, 'UTF-8', 'UTF-8') . "\r\n.\r\n");
        $r();
        $ok = fputs($fp, "QUIT\r\n") !== false;
        fclose($fp);
        return $ok ? true : 'QUIT 失败';
    }
}
