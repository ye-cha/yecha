<?php
declare(strict_types=1);

/** HTML 转义（XSS 防线之一：输出必过此函数） */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 站点 URL（相对根），供前台/后台模板生成链接 */
function site_url(string $path = ''): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

/** 读取配置常量（config.inc.php 中 define 的键） */
function cfg(string $key, mixed $default = null): mixed
{
    return defined($key) ? constant($key) : $default;
}

/* ---------- 敏感配置加密（SECRET 派生 AES-256-GCM；格式 enc:base64(iv+tag+ct)） ---------- */
/** 加密密钥：由 SECRET 派生（每次安装不同，换 SECRET 后旧密文需重新保存） */
function yc_crypt_key(): string
{
    return hash('sha256', (string)cfg('SECRET', 'yecha'), true);
}
function yc_encrypt(string $plain): string
{
    if ($plain === '') { return ''; }
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', yc_crypt_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return $ct === false ? '' : 'enc:' . base64_encode($iv . $tag . $ct);
}
function yc_decrypt(string $v): string
{
    if ($v === '' || !str_starts_with($v, 'enc:')) { return $v; } // 兼容历史明文
    $b = base64_decode(substr($v, 4), true);
    if ($b === false || strlen($b) < 29) { return ''; }
    $iv = substr($b, 0, 12); $tag = substr($b, 12, 16); $ct = substr($b, 28);
    $p = openssl_decrypt($ct, 'aes-256-gcm', yc_crypt_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $p === false ? '' : $p;
}
/** 读取可能已加密的敏感 option（如 ai.key / mail.pass），自动解密 */
function yc_opt(string $key, string $def = ''): string
{
    return yc_decrypt((string)get_option($key, $def));
}
/** 保存敏感 option：空值不覆盖（保留旧值），非空则加密存储 */
function yc_opt_set(string $key, string $plain): void
{
    if ($plain !== '') { set_option($key, yc_encrypt($plain)); }
}
/** 是否已配置（含旧明文） */
function yc_opt_has(string $key): bool
{
    return yc_opt($key, '') !== '';
}

/** 302 跳转并结束 */
function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

/** 302 跳回引用页（站内校验，登录回跳用） */
function redirect_back(string $fallback = '/'): never
{
    $to = $_SERVER['HTTP_REFERER'] ?? '';
    if ($to !== '' && str_starts_with($to, (site_url() !== '' ? site_url() : '/'))) {
        redirect($to);
    }
    redirect($fallback);
}

/** 简单错误日志：var/log/app.log */
function slog(string $msg): void
{
    $dir = dirname(__DIR__) . '/var/log';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    @file_put_contents($dir . '/app.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

/** 定时文章到点自动上架（读列表/文章前调用，仅在有到期项时写库并失效缓存） */
function content_publish_due(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    // 大数据量节流：30 秒内只执行一次（延迟发布最多迟到 30 秒）
    $last = Cache::get('misc', 'pd_last');
    if ($last !== false && ((int)$last + 30) > time()) { return; }
    Cache::set('misc', 'pd_last', (string)time(), 60);
    $st = Db::run('UPDATE ' . Db::t('contents')
        . " SET status='publish' WHERE status='scheduled' AND published_at IS NOT NULL"
        . " AND published_at <= NOW() AND deleted_at IS NULL");
    if ($st->rowCount() > 0) {
        Cache::flush('pages');
        Cache::flush('blocks');
    }
}
