<?php
declare(strict_types=1);

/**
 * Cache：文件缓存（默认驱动，零外部依赖），Redis 可插拔（E1 后提供同接口驱动）
 * 命名空间即子目录：pages / blocks / throttle / misc，flush($ns) 清空某命名空间
 * 文件首行存过期时间（unix），ttl=0 表示不过期（由写事件失效）
 */
final class Cache
{
    private static string $root = '';

    private static function root(): string
    {
        if (self::$root === '') {
            self::$root = dirname(__DIR__) . '/var/cache';
            if (!is_dir(self::$root)) { @mkdir(self::$root, 0777, true); }
        }
        return self::$root;
    }

    private static function path(string $ns, string $key): string
    {
        $dir = self::root() . '/' . preg_replace('/[^a-z0-9_]/', '', $ns);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        return $dir . '/' . md5($key) . '.cache';
    }

    public static function get(string $ns, string $key): string|false
    {
        $file = self::path($ns, $key);
        if (!is_file($file)) {
            return false;
        }
        $fp = fopen($file, 'rb');
        if ($fp === false) { return false; }
        $exp = (int)fgets($fp);
        $body = stream_get_contents($fp);
        fclose($fp);
        if ($exp !== 0 && time() >= $exp) {
            @unlink($file);
            return false;
        }
        return $body === false ? false : $body;
    }

    public static function set(string $ns, string $key, string $value, int $ttl = 0): void
    {
        $exp = $ttl > 0 ? time() + $ttl : 0;
        $file = self::path($ns, $key);
        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmp, $exp . "\n" . $value) !== false) {
            @rename($tmp, $file);
        }
    }

    public static function forget(string $ns, string $key): void
    {
        @unlink(self::path($ns, $key));
    }

    public static function flush(string $ns): void
    {
        $dir = self::root() . '/' . preg_replace('/[^a-z0-9_]/', '', $ns);
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.cache') ?: [] as $f) { @unlink($f); }
        }
    }

    /** 布尔短路：用作限流等简单原子计数（写放大极小场景） */
    public static function incr(string $ns, string $key, int $ttl = 0): int
    {
        $file = self::path($ns, $key);
        $n = (int)self::get($ns, $key);
        $n++;
        self::set($ns, $key, (string)$n, $ttl);
        return $n;
    }
}
