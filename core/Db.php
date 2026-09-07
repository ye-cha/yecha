<?php
declare(strict_types=1);

/**
 * Db：极简 PDO 封装（预处理防注入）
 * 表名统一 Db::t('users') → ye_users
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function t(string $table): string
    {
        return cfg('DB_PREFIX', 'ye_') . $table;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                cfg('DB_HOST', 'mysql'),
                cfg('DB_PORT', '3306'),
                cfg('DB_NAME', 'yecha')
            );
            self::$pdo = new PDO($dsn, cfg('DB_USER', 'yecha'), cfg('DB_PASS', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function rows(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $r = self::run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function one(string $sql, array $params = []): mixed
    {
        $r = self::row($sql, $params);
        return $r === null ? null : reset($r);
    }

    /** 插入并返回自增 id */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . self::t($table)
            . ' (`' . implode('`,`', $cols) . '`) VALUES ('
            . rtrim(str_repeat('?,', count($cols)), ',') . ')';
        self::run($sql, array_values($data));
        return (int)self::pdo()->lastInsertId();
    }

    /** 更新并返回影响行数 */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($data)));
        self::run('UPDATE ' . self::t($table) . ' SET ' . $set . ' WHERE ' . $where,
            array_merge(array_values($data), $whereParams));
        return self::run('SELECT ROW_COUNT() AS n')->fetch()['n'];
    }

    public static function deleteBy(string $table, string $where, array $params = []): void
    {
        self::run('DELETE FROM ' . self::t($table) . ' WHERE ' . $where, $params);
    }
}
