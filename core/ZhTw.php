<?php
declare(strict_types=1);
/**
 * ZhTw：简体 → 繁体（OpenCC 词典本地转换，无外部依赖）
 * 词典：core/data/opencc/STCharacters.txt + STPhrases.txt（Apache-2.0, BYVoid/OpenCC）
 * 用法：ZhTw::t('简体文本')
 */
final class ZhTw
{
    private static ?array $phrases = null; // 词条：key => value（按长度倒序分组）
    private static ?array $chars = null;   // 单字：key => value
    private static array $byLen = [];      // len => [key=>value]

    private static function load(): void
    {
        if (self::$phrases !== null) { return; }
        self::$phrases = [];
        self::$chars = [];
        self::$byLen = [];
        $dir = __DIR__ . '/data/opencc';
        foreach (['STPhrases.txt' => true, 'STCharacters.txt' => false] as $fn => $isPhrase) {
            $fh = @fopen($dir . '/' . $fn, 'r');
            if ($fh === false) { continue; }
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) { continue; }
                $p = preg_split('/[\t ]+/u', $line) ?: [];
                $key = $p[0] ?? '';
                $val = $p[1] ?? '';
                if ($key === '' || $val === '') { continue; }
                if ($isPhrase) {
                    self::$phrases[$key] = $val;
                } else {
                    self::$chars[$key] = $val;
                }
            }
            fclose($fh);
        }
        foreach (self::$phrases as $k => $v) {
            $len = mb_strlen($k);
            if ($len >= 2 && $len <= 16) { self::$byLen[$len][$k] = $v; }
        }
        krsort(self::$byLen);
    }

    /** 简体 → 繁体 */
    public static function t(string $s): string
    {
        self::load();
        if ($s === '') { return ''; }
        $out = '';
        $n = mb_strlen($s);
        $i = 0;
        while ($i < $n) {
            $rest = mb_substr($s, $i);
            $hit = null;
            // 优先长词条
            foreach (self::$byLen as $len => $map) {
                if ($len > mb_strlen($rest)) { continue; }
                $sub = mb_substr($rest, 0, $len);
                if (isset($map[$sub])) { $hit = [$len, $map[$sub]]; break; }
            }
            if ($hit !== null) {
                $out .= $hit[1];
                $i += $hit[0];
                continue;
            }
            $ch = mb_substr($s, $i, 1);
            $out .= self::$chars[$ch] ?? $ch;
            $i++;
        }
        return $out;
    }
}
