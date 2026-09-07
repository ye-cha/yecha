<?php
declare(strict_types=1);

/**
 * Auth：登录/会话/防爆破
 * - 会话固定防护：登录成功后 session_regenerate_id(true)
 * - 防爆破：Cache('throttle') 按 IP 记失败次数（文件驱动即可，无外部依赖）
 */
final class Auth
{
    public static function attempt(string $username, string $password): array
    {
        $uKey = self::userThrottleKey($username);
        $err = self::throttleCheck(self::throttleKey());
        if ($err !== null) { return ['ok' => false, 'error' => $err, 'throttled' => true]; }
        $err = self::throttleCheck($uKey);
        if ($err !== null) { return ['ok' => false, 'error' => $err, 'throttled' => true]; }
        $user = Db::row(
            'SELECT * FROM ' . Db::t('users') . ' WHERE (username=? OR email=?) LIMIT 1',
            [$username, $username]
        );
        if ($user === null || !password_verify($password, $user['password'])) {
            self::throttleHit(self::throttleKey());
            self::throttleHit($uKey);
            return ['ok' => false, 'error' => __('auth.bad_creds')];
        }
        if ($user['status'] !== 'normal') {
            return ['ok' => false, 'error' => __('auth.disabled')];
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$user['id'];
        Db::update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ], 'id=?', [(int)$user['id']]);
        // 成功登录清零 IP 与账号维度的失败计数
        self::throttleReset(self::throttleKey());
        self::throttleReset($uKey);
        return ['ok' => true, 'user' => $user];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function id(): int
    {
        return (int)($_SESSION['uid'] ?? 0);
    }

    /** 注册（subscriber）并自动登录 */
    public static function register(string $username, string $email, string $password): array
    {
        $username = trim($username);
        $email = trim($email);
        if (!preg_match('/^[A-Za-z0-9_\-\x{4e00}-\x{9fa5}]{2,30}$/u', $username)) {
            return ['ok' => false, 'error' => __('auth.user_len')];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => __('auth.email_invalid')];
        }
        if (strlen($password) < 6) {
            return ['ok' => false, 'error' => __('auth.pass_short')];
        }
        $T = Db::t('users');
        if (Db::one("SELECT id FROM $T WHERE username=? LIMIT 1", [$username]) !== null) {
            return ['ok' => false, 'error' => __('auth.user_taken')];
        }
        if (Db::one("SELECT id FROM $T WHERE email=? LIMIT 1", [$email]) !== null) {
            return ['ok' => false, 'error' => __('auth.email_taken')];
        }
        $uid = Db::insert('users', [
            'username' => $username, 'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'subscriber', 'display_name' => $username, 'status' => 'normal',
        ]);
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        return ['ok' => true, 'uid' => $uid];
    }

    public static function check(): bool
    {
        return self::id() > 0;
    }

    /** 校验指定用户当前密码 */
    public static function verifyPassword(int $uid, string $plain): bool
    {
        $row = Db::row('SELECT password FROM ' . Db::t('users') . ' WHERE id=? LIMIT 1', [$uid]);
        return $row !== null && password_verify($plain, (string)$row['password']);
    }

    /** 修改指定用户密码（外部负责长度/一致性校验） */
    public static function setPassword(int $uid, string $plain): void
    {
        Db::update('users', ['password' => password_hash($plain, PASSWORD_DEFAULT)], 'id=?', [$uid]);
    }

    public static function user(): ?array
    {
        $id = self::id();
        if ($id <= 0) { return null; }
        static $cached = null;
        if ($cached !== null && (int)$cached['id'] === $id) { return $cached; }
        $cached = Db::row('SELECT * FROM ' . Db::t('users') . ' WHERE id=? LIMIT 1', [$id]);
        return $cached;
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u !== null && $u['role'] === 'admin';
    }

    /* ---------- 防爆破：IP 与账号双维度，各 5 次/10 分钟；成功登录清零 ---------- */
    private static function throttleKey(): string
    {
        return 'login:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private static function userThrottleKey(string $username): string
    {
        return 'ulogin:' . strtolower(trim($username));
    }

    private static function throttleCheck(string $key): ?string
    {
        $n = (int)Cache::get('throttle', $key);
        return $n >= 5 ? __('auth.throttle') : null;
    }

    private static function throttleHit(string $key): void
    {
        Cache::incr('throttle', $key, 600);
    }

    public static function throttleReset(string $key = ''): void
    {
        Cache::forget('throttle', $key !== '' ? $key : self::throttleKey());
    }
}
