<?php
declare(strict_types=1);
/**
 * Upgrade：一键升级框架（WordPress 式后台执行 + 迁移注册）
 * - ye_migrations(component, file, applied_at)：每组件各自版本推进
 * - 迁移文件：核心 install/migrations/；插件 plugins/<id>/migrations/；主题 themes/<name>/migrations/
 *   .sql 用分号拆分逐条执行；.php 直接 require（内部使用 Db::run 等，需幂等：先查后加）
 * - 安全：所有迁移要求幂等；执行前必须备份；失败停止并记录日志；已应用文件只记不重跑。
 */
final class Upgrade
{
    public const COMPONENTS = ['core' => '系统核心', 'poly' => '多语言插件', 'notes' => '记事本插件', 'theme-yecha' => '主题 yecha'];

    public static function ensureRegistry(): void
    {
        $t = Db::t('migrations');
        $ok = Db::rows("SHOW TABLES LIKE '{$t}'");
        if (!$ok) {
            Db::run("CREATE TABLE IF NOT EXISTS {$t} (
                component VARCHAR(40) NOT NULL,
                file      VARCHAR(200) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (component, file)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }

    public static function componentVersion(string $component): string
    {
        if ($component === 'core') { return defined('YC_VERSION') ? YC_VERSION : '?'; }
        if (str_starts_with($component, 'theme-')) { return '1.0.0'; }
        $d = Plugins::def($component);
        return $d['ver'] ?? '1.0.0';
    }

    /** 迁移目录（不存在返回 null） */
    public static function dirFor(string $component): ?string
    {
        $base = dirname(__DIR__);
        if ($component === 'core') { return $base . '/install/migrations'; }
        if (str_starts_with($component, 'theme-')) {
            $d = $base . '/themes/' . substr($component, 6) . '/migrations';
            return is_dir($d) ? $d : null;
        }
        $d = $base . '/plugins/' . $component . '/migrations';
        return is_dir($d) ? $d : null;
    }

    public static function applied(string $component): array
    {
        self::ensureRegistry();
        $rows = Db::rows('SELECT file FROM ' . Db::t('migrations') . ' WHERE component=?', [$component]);
        return array_column($rows, 'file');
    }

    public static function pending(string $component): array
    {
        $dir = self::dirFor($component);
        if ($dir === null || !is_dir($dir)) { return []; }
        $files = [];
        foreach ((array)scandir($dir) as $f) {
            if (str_ends_with($f, '.sql') || str_ends_with($f, '.php')) { $files[] = $dir . '/' . $f; }
        }
        sort($files);
        $done = self::applied($component);
        $out = [];
        foreach ($files as $f) {
            $name = basename($f);
            if (str_starts_with($name, 'README') || in_array($name, $done, true)) { continue; }
            $out[] = $f;
        }
        return $out;
    }

    /** 执行某组件全部待应用迁移；返回 ['applied'=>[],'skipped'=>[],'error'=>null] */
    public static function apply(string $component, ?callable $log = null): array
    {
        self::ensureRegistry();
        $log = $log ?? fn(string $s) => null;
        $files = self::pending($component);
        $res = ['applied' => [], 'error' => null];
        foreach ($files as $f) {
            $name = basename($f);
            try {
                if (str_ends_with(strtolower($name), '.sql')) {
                    $sql = file_get_contents($f);
                    if ($sql === false) { throw new RuntimeException('read fail: ' . $name); }
                    foreach (self::splitSql($sql) as $st) { Db::run($st); }
                } else {
                    require $f; // .php 迁移：内部使用 Db::run 等，必须幂等
                }
                Db::insert('migrations', ['component' => $component, 'file' => $name]);
                $res['applied'][] = $name;
                $log('applied ' . $component . ':' . $name);
            } catch (Throwable $e) {
                $res['error'] = $name . ' :: ' . $e->getMessage();
                $log('ERROR ' . $component . ':' . $name . ' :: ' . $e->getMessage());
                break;
            }
        }
        return $res;
    }

    /** 简单 SQL 拆分（忽略 -- 注释行与空段） */
    private static function splitSql(string $sql): array
    {
        $out = [];
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $st) {
            $st = trim((string)$st);
            if ($st === '' || str_starts_with($st, '--')) { continue; }
            $st = preg_replace('/^--[^\r\n]*(\r?\n)?/m', '', $st) ?? $st;
            $st = trim($st);
            if ($st !== '') { $out[] = $st; }
        }
        return $out;
    }

    /** 组件总体状态（供后台展示） */
    public static function status(): array
    {
        $out = [];
        foreach (array_keys(self::COMPONENTS) as $c) {
            $pending = self::pending($c);
            $out[$c] = [
                'name' => self::COMPONENTS[$c],
                'version' => self::componentVersion($c),
                'applied' => count(self::applied($c)),
                'pending' => $pending,
            ];
        }
        return $out;
    }

    /** 追加一行升级日志 */
    public static function log(string $s): void
    {
        @file_put_contents(dirname(__DIR__) . '/var/log/upgrade.log', date('Y-m-d H:i:s') . ' ' . $s . "\n", FILE_APPEND);
    }
}
