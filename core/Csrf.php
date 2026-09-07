<?php
declare(strict_types=1);

/** Csrf：所有 POST 表单必须带 token 校验 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(?string $token): bool
    {
        return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
    }

    public static function check(): void
    {
        $ok = self::verify($_POST['_csrf'] ?? null);
        if (!$ok) {
            http_response_code(403);
            exit('CSRF 校验失败，请返回重试');
        }
    }
}
