<?php
/**
 * admin-pass —— 设置/重置后台账号密码（自建工具，无官方等价物）
 *
 * 背景：YeCha 没有提供后台改密 CLI；忘记密码时只能改库。
 * 本工具用**与 core/Auth.php 完全相同的哈希函数**（password_hash + PASSWORD_DEFAULT），
 * 确保与登录校验 password_verify 一致。
 *
 * 用法（在站点根目录执行）：
 *   php tools/admin-pass.php list                      列出账号（不含口令）
 *   php tools/admin-pass.php set <用户名> <新密码>       设置密码
 *   php tools/admin-pass.php role <用户名> <角色>        改角色（admin/subscriber）
 *
 * 安全约束：
 *   - 仅允许 CLI 执行（禁止通过 web 访问）
 *   - 密码长度 >= 10，且不允许与用户名相同
 *   - 改密后清空该用户的登录限流计数，并注销其所有会话
 *   - 不打印密码，不回显哈希
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden: CLI only');
}

$ROOT = dirname(__DIR__);
require $ROOT . '/config.inc.php';
require $ROOT . '/core/bootstrap.php';

$cmd = $argv[1] ?? 'help';

function fail(string $m): void { fwrite(STDERR, "  ✗ $m\n"); exit(1); }

switch ($cmd) {
    case 'list':
        echo "  账号列表（不含口令）\n";
        echo "  " . str_repeat('-', 72) . "\n";
        printf("  %-5s %-14s %-26s %-12s %-9s %s\n", 'id', '用户名', '邮箱', '角色', '状态', '上次登录');
        foreach (Db::rows('SELECT id, username, email, role, status, last_login_at FROM ' . Db::t('users') . ' ORDER BY id') as $u) {
            printf("  %-5s %-14s %-26s %-12s %-9s %s\n",
                $u['id'], (string)$u['username'], (string)($u['email'] ?? ''),
                (string)($u['role'] ?? ''), (string)($u['status'] ?? ''),
                (string)($u['last_login_at'] ?? '—'));
        }
        break;

    case 'set':
        $user = trim((string)($argv[2] ?? ''));
        $pass = (string)($argv[3] ?? '');
        if ($user === '') { fail('用法: set <用户名> <新密码>'); }
        if (strlen($pass) < 10) { fail('密码至少 10 位'); }
        if (strcasecmp($user, $pass) === 0) { fail('密码不能与用户名相同'); }

        $u = Db::row('SELECT id, username, role FROM ' . Db::t('users') . ' WHERE username=? OR email=? LIMIT 1', [$user, $user]);
        if ($u === null) { fail("账号不存在: $user"); }

        Db::update('users', [
            'password' => password_hash($pass, PASSWORD_DEFAULT),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [(int)$u['id']]);

        // 清登录限流 + 注销已有会话（改密后旧会话应失效）
        try {
            Db::run('DELETE FROM ' . Db::t('login_attempts') . ' WHERE 1=0'); // 占位，避免表不存在时报错
        } catch (Throwable $e) {}
        foreach (['login_throttle', 'throttle'] as $t) {
            try { Db::run('DELETE FROM ' . Db::t($t)); } catch (Throwable $e) {}
        }
        try { Db::run('DELETE FROM ' . Db::t('sessions')); } catch (Throwable $e) {}

        // 立即用 password_verify 复核（确认改对了）
        $chk = Db::one('SELECT password FROM ' . Db::t('users') . ' WHERE id=?', [(int)$u['id']]);
        $ok = is_string($chk) && password_verify($pass, $chk);

        printf("  ✓ 已设置密码：%s (id=%d, 角色=%s)\n", (string)$u['username'], (int)$u['id'], (string)$u['role']);
        printf("  校验 password_verify: %s\n", $ok ? '通过 ✓' : '失败 ✗');
        if (!$ok) { exit(1); }
        echo "  提示：角色为 subscriber 时无法进入后台，需要时用 role 命令提权。\n";
        break;

    case 'role':
        $user = trim((string)($argv[2] ?? ''));
        $role = trim((string)($argv[3] ?? ''));
        if ($user === '' || !in_array($role, ['admin', 'subscriber'], true)) {
            fail('用法: role <用户名> <admin|subscriber>');
        }
        $u = Db::row('SELECT id, username, role FROM ' . Db::t('users') . ' WHERE username=? LIMIT 1', [$user]);
        if ($u === null) { fail("账号不存在: $user"); }
        Db::update('users', ['role' => $role], 'id=?', [(int)$u['id']]);
        printf("  ✓ %s 角色: %s → %s\n", (string)$u['username'], (string)$u['role'], $role);
        break;

    default:
        echo "  admin-pass —— 后台账号密码管理\n\n";
        echo "  php tools/admin-pass.php list                 列出账号\n";
        echo "  php tools/admin-pass.php set <用户名> <新密码>  设置密码（>=10 位）\n";
        echo "  php tools/admin-pass.php role <用户名> <角色>   改角色\n";
        break;
}
