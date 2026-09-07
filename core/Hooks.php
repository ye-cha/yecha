<?php
declare(strict_types=1);
/**
 * Hooks：轻量动作总线（插件挂载点）
 * - 载荷统一为数组，挂载点语义：listener 读/改 payload（引用传入）。
 * - 常用点：'side_blocks'（payload['blocks'][] = ['id','label','html']）
 *           'pages'       （payload['pages'][] = ['slug','title']）
 *           'head'/'foot' （payload['html'] .= …）
 * 插件文件建议带唯一前缀的函数；注册在插件 enable/boot 阶段调用 Hooks::on。
 */
final class Hooks
{
    private static array $reg = []; // point => [ [prio, seq, callable], … ]
    private static int $seq = 0;

    public static function on(string $point, callable $fn, int $prio = 10): void
    {
        self::$reg[$point][] = [$prio, ++self::$seq, $fn];
    }

    /** 触发动作；返回（可能被改写的）payload */
    public static function run(string $point, array $payload = []): array
    {
        $list = self::$reg[$point] ?? [];
        usort($list, fn($a, $b) => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
        foreach ($list as $ent) {
            $fn = $ent[2];
            $payload = (array)$fn($payload);
        }
        return $payload;
    }

    public static function has(string $point): bool
    {
        return !empty(self::$reg[$point]);
    }

    public static function clear(?string $point = null): void
    {
        if ($point === null) { self::$reg = []; self::$seq = 0; }
        else { unset(self::$reg[$point]); }
    }
}
