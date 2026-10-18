<?php
declare(strict_types=1);
/**
 * lqlegacy · 老站口令旁存校验（透明升级的"旧算法那一半"）
 * ===========================================================================
 * 老站口径（Node）：crypto.pbkdf2Sync(pw, salt, 100000, 32, 'sha256')
 *   · 派生 32 字节；salt 按【字符串的 UTF-8 字节】用（不做 hex 解码）
 *   · 老站的落库形态可能是 hex / base64 / 原始二进制 —— 本函数**按长度+字符集自适应**，
 *     并与 PHP 的 hash_pbkdf2 逐形态比较；比较一律用 hash_equals()（恒时）。
 * ★ 只读旁表 + 只按 user id 定位；不查询、不打印、不外传任何口令或哈希。
 * ★ core/Auth.php 只在【现代校验没过】时才调到这里 ⇒ 正常用户零额外开销。
 */
if (!function_exists('lql_verify_legacy')):

if (!defined('LQL_ITER')) {
    define('LQL_ITER', 100000);
    define('LQL_LEN', 32);
    define('LQL_ALGO', 'pbkdf2-sha256-100000-32');
}
function lql_log(string $msg): void
{
    $f = '/www/wwwroot/liulanqi-yecha/var/log/lqlegacy.log';
    @file_put_contents($f, '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND);   // ★ 只记事件与 uid，不记任何口令/哈希
}
/** 旁存行是否存在 */
function lql_has_legacy(int $uid): bool
{
    if ($uid <= 0) { return false; }
    return Db::row('SELECT user_id FROM ' . Db::t('user_legacy') . ' WHERE user_id=? LIMIT 1', [$uid]) !== null;
}
/** 写入/覆盖旁存行（批8 迁移与本插件测试用；salt/hash 都是"已存在的哈希材料"，不是明文） */
function lql_put_legacy(int $uid, string $salt, string $hash, string $algo = LQL_ALGO): bool
{
    if ($uid <= 0 || $salt === '' || $hash === '') { return false; }
    Db::run(
        'INSERT INTO ' . Db::t('user_legacy') . ' (user_id, algo, salt, hash, created_at) VALUES (?,?,?,?,NOW()) '
        . 'ON DUPLICATE KEY UPDATE algo=VALUES(algo), salt=VALUES(salt), hash=VALUES(hash)',
        [$uid, $algo, $salt, $hash]
    );
    return true;
}
/** 摘掉旁存行（透明升级成功后调用） */
function lql_drop_legacy(int $uid): bool
{
    if ($uid <= 0) { return false; }
    Db::run('DELETE FROM ' . DB::t('user_legacy') . ' WHERE user_id=?', [$uid]);
    return true;
}
/**
 * ★ core/Auth.php 调的就是它：老站口径验口令。
 * 返回 true ⇒ 认为"这对口令对该用户有效"；core 会随即 setPassword() 改存现代哈希。
 * 失败返回 false（行为与"没这个插件"完全一致）。
 */
function lql_verify_legacy(array $user, string $plain): bool
{
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0 || $plain === '') { return false; }
    $row = Db::row('SELECT algo, salt, hash FROM ' . Db::t('user_legacy') . ' WHERE user_id=? LIMIT 1', [$uid]);
    if ($row === null) { return false; }
    $algo = strtolower((string)$row['algo']);
    if ($algo !== LQL_ALGO) {
        lql_log('unknown_algo uid=' . $uid . ' algo=' . $algo);
        return false;                                        // ★ 只认我们登记过的那一种
    }
    $stored = (string)$row['hash'];
    $salt   = (string)$row['salt'];
    /* ★ 关键：先取【32 字节原始值】再 bin2hex ⇒ 64 位十六进制，等价于 Node 的 `.toString('hex')`。
       实测踩过：`hash_pbkdf2(..., 32, false)` 返回的是 **32 个十六进制字符（=16 字节）**，
       与老站 `.toString('hex')` 的 **64 位** 不等长 ⇒ 真实老用户会永远登不上（日志 hashLen=32 暴露）。 */
    $raw = hash_pbkdf2('sha256', $plain, $salt, LQL_ITER, LQL_LEN, true);   // 32 字节
    $hex = bin2hex($raw);                                                   // 64 位十六进制
    $b64 = base64_encode($raw);
    /* 形态无关的比较（hex / 原始二进制 / base64 都试；hash_equals 对不等长直接 false） */
    $ok = hash_equals($stored, $hex) || hash_equals($stored, $raw) || hash_equals($stored, $b64);
    if ($ok) { lql_log('legacy_ok uid=' . $uid . ' form=' . (hash_equals($stored, $hex) ? 'hex' : (hash_equals($stored, $raw) ? 'raw' : 'b64'))); }
    else { lql_log('legacy_fail uid=' . $uid . ' hashLen=' . strlen($stored)); }
    return $ok;
}
/** 该用户是否还在用合成邮箱（供"首次登录提示补邮箱"用；只读 ye_users.email） */
function lql_synth_email(int $uid): bool
{
    if ($uid <= 0) { return false; }
    $e = (string)Db::one('SELECT email FROM ' . Db::t('users') . ' WHERE id=? LIMIT 1', [$uid]);
    return $e !== '' && substr($e, -15) === '@import.invalid';   // ★ 后缀长 15（@ + import + . + invalid）
}

endif;
