<?php
declare(strict_types=1);

/** Str：slug、小工具 */
final class Str
{
    /** 生成 URL 别名：仅保留小写字母数字，其余转 -；纯中文标题回退 p-<hash>（多语/AI slug 后续增强） */
    public static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        if ($s === '' || strlen($s) < 2) {
            $s = 'p-' . substr(md5(uniqid('', true)), 0, 6);
        }
        return substr($s, 0, 190);
    }

    /** 标签 slug：中文名保留中文（URL 语义可读），非中文走 slug() */
    public static function tagSlug(string $name): string
    {
        $name = trim($name);
        if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $name)) {
            return mb_substr($name, 0, 60);
        }
        return self::slug($name);
    }

    /** 打散数字阅读量展示，如 1284 → 1,284 */
    public static function num(int $n): string
    {
        return number_format($n);
    }

    public static function excerpt(string $html, int $len = 140): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        if (mb_strlen($text) <= $len) {
            return $text;
        }
        return mb_substr($text, 0, $len) . '…';
    }

    /** 把 Markdown/HTML 转成纯文本（用于摘要、搜索结果展示）
     *  （内核合流 2026-09-26：演示主题广场的 26 套主题里有 19 套在列表/分类/标签/检索页调用它，
     *    缺此方法会让这些页面直接 Fatal —— 故按演示站同名实现补入内核） */
    public static function plain(string $s): string
    {
        $s = strip_tags($s);
        // Markdown 标题：# / ## / ###
        $s = preg_replace('/^#{1,6}\s+/mu', '', $s) ?? $s;
        $s = preg_replace('/\s#{1,6}\s+/u', ' ', $s) ?? $s;
        // 图片与链接：[text](url)、![alt](url)
        $s = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $s) ?? $s;
        // 自动链接 <url>
        $s = preg_replace('/<[^>]+>/u', '', $s) ?? $s;
        // 列表符、引用、水平线
        $s = preg_replace('/^[-*+>]\s+/mu', '', $s) ?? $s;
        $s = preg_replace('/^\d+\.\s+/mu', '', $s) ?? $s;
        $s = preg_replace('/^[-*_]{3,}\s*$/mu', '', $s) ?? $s;
        // 强调符 * _ ~ `（保留中文语境下的内容）
        $s = preg_replace('/(?<!\w)[*_~`]{1,2}(?!\s)/u', '', $s) ?? $s;
        $s = preg_replace('/(?<!\s)[*_~`]{1,2}(?!\w)/u', '', $s) ?? $s;
        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    /** 拆分检索词 */
    public static function terms(string $q): array
    {
        // 各类引号统一替换为空格后，再用正则按标点/空白拆分
        $q = str_replace(['"', "'", '“', '”', '‘', '’'], ' ', $q);
        $q = preg_replace('~[,\s，。、！？!?；;：:·（）(){}\[\]<>《》/\\\\|—_*#@]~u', ' ', $q) ?? $q;
        $arr = preg_split('/\s+/u', trim($q)) ?: [];
        $out = [];
        foreach ($arr as $w) {
            $w = trim($w);
            if ($w !== '') { $out[] = $w; }
        }
        return array_values(array_unique($out));
    }

    /** 全文搜索标题/摘要：把命中的词包成 <mark>（输入为纯文本，输出已是安全 HTML） */
    public static function mark(string $text, string $q): string
    {
        $terms = self::terms($q);
        if (!$terms) { return e($text); }
        $safe = e($text);
        foreach ($terms as $t) {
            $pat = '/' . preg_quote($t, '/') . '/iu';
            $safe = preg_replace_callback($pat, fn($m) => '<mark>' . $m[0] . '</mark>', $safe) ?? $safe;
        }
        return $safe;
    }

    /** 命中摘要窗口（纯文本片段） */
    public static function snippet(string $text, string $q, int $radius = 90): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($plain === '') { return ''; }
        $terms = self::terms($q);
        $pos = 0;
        foreach ($terms as $t) {
            $p = mb_stripos($plain, $t);
            if ($p !== false) { $pos = $p; break; }
        }
        if (mb_strlen($plain) <= $radius * 2) { return $plain; }
        $start = max(0, $pos - $radius);
        $prefix = $start > 0 ? '…' : '';
        $seg = mb_substr($plain, $start, $radius * 2);
        return $prefix . $seg . '…';
    }

    /** 把 Markdown 还原成可读纯文本（摘要/meta 用，避免 ![图](..) 等语法漏出） */
    public static function cleanMd(string $md): string
    {
        $s = $md;
        $s = preg_replace('/```.*?```/su', ' ', $s) ?? $s;                 // 代码围栏
        $s = preg_replace('~!\[[^\]]*\]\([^)\n]*\)~u', '', $s) ?? $s;        // 图片（完整写法）
        $s = preg_replace('~!\[[^\]]*\]\([^)\n]*$~mu', '', $s) ?? $s;       // 图片（导入时被截断、缺右括号）
        $s = preg_replace('~\[([^\]]*)\]\([^)]*\)~u', '$1', $s) ?? $s;     // 链接保留文字
        $s = preg_replace('~^#{1,6}\s*~mu', '', $s) ?? $s;                 // 标题符
        $s = preg_replace('~^\s*>\s?~mu', '', $s) ?? $s;                   // 引用
        $s = preg_replace('~^\s*([-*+]|\d+[.)])\s+~mu', '', $s) ?? $s;     // 列表符
        $s = preg_replace('#[*_~`]{1,2}#', '', $s) ?? $s;                  // 强调/代码
        $s = preg_replace('/^\s*[-]{3,}\s*$/m', '', $s) ?? $s;             // 分割线
        $s = preg_replace('/[ \t]+\n/', "\n", $s) ?? $s;
        return trim(preg_replace('/\n{2,}/u', "\n", trim($s)) ?? '');
    }
}
