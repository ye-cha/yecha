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

    /**
     * 摘要（列表/归档/标签/检索/相关文章/RSS 共用）
     * 2026-10-19 修：先 Markdown→纯文本，再从正文开头取，CJK 友好截断。
     * 注意：$src 可能是 Markdown 正文、已截断的 excerpt 字段、或 HTML 片段；一律按纯文本收口。
     */
    public static function excerpt(string $src, int $len = 140): string
    {
        $t = self::md2text($src);
        $t = self::leadClean($t);
        if ($t === '' && preg_match('/!\[([^\]]{2,})/u', $src, $m)) {
            // 整段只有图片时，退回图片 alt 文字，避免列表摘要空白
            $t = self::leadClean(self::md2text($m[1]));
        }
        if ($len < 1) {
            return $t;
        }
        if (mb_strlen($t) <= $len) {
            return $t;
        }
        $cut = mb_substr($t, 0, $len);
        // 不在 URL / 英文长词中间截断（中文按字符截，无需退让）
        if (preg_match('/(?:https?:\/\/|\b[a-z0-9._%+\-]{12,})\S*$/iu', $cut)) {
            $cut2 = (string)preg_replace('/\s*\S+$/u', '', $cut);
            if (mb_strlen($cut2) >= (int)($len * 0.6)) {
                $cut = $cut2;
            }
        }
        $cut = (string)preg_replace('/[\s,，、;；:：·•\-—–(（「『\[【]+$/u', '', $cut);
        if ($cut === '') {
            $cut = mb_substr($t, 0, $len);
        }
        return $cut . '…';
    }

    /**
     * Markdown / HTML → 纯文本（摘要、RSS、搜索、meta 共用；单行）
     * 顺序：front matter/注释/脚本 → 代码围栏(留文本) → HTML 标签 → 图片 → 链接 → 行内代码
     *      → 标题/引用/列表/表格/分割线 → 强调 → 实体解码 → 压缩空白
     */
    public static function md2text(string $s): string
    {
        if ($s === '') { return ''; }
        // 0) BOM / 零宽 / nbsp
        $s = str_replace(["\xEF\xBB\xBF", "\xE2\x80\x8B", "\xC2\xA0"], ['', '', ' '], $s);
        // 1) front matter（--- 或 +++ 包裹）与 HTML 注释、script/style
        $s = (string)preg_replace('/\A(?:---|\+\+\+)[ \t]*\r?\n.*?\r?\n(?:---|\+\+\+)[ \t]*(?:\r?\n|\z)/su', ' ', $s);
        $s = (string)preg_replace('/<!--.*?-->/su', ' ', $s);
        $s = (string)preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', ' ', $s);
        // 2) 代码块：去围栏，保留内部文本
        $s = (string)preg_replace('/^[ \t]*(?:```|~~~)[^\n]*\n(.*?)(?:^[ \t]*(?:```|~~~)[ \t]*$|\z)/sum', "\$1", $s);
        $s = (string)preg_replace('/^[ \t]*(?:```|~~~)[^\n]*$/mu', ' ', $s);
        // 3) HTML 标签（含 <img>、<br>；含未闭合残片）
        $s = (string)preg_replace('/<[^>\n]{0,400}>/u', ' ', $s);
        // 4) 图片：完整写法丢弃；导入截断的（缺右括号）丢弃到行尾
        $s = (string)preg_replace('/!\[[^\]]*\]\([^)\n]*\)/u', ' ', $s);
        $s = (string)preg_replace('/!\[[^\]]*\]\([^)\n]*/u', ' ', $s);
        $s = (string)preg_replace('/!\[[^\]]*\]/u', ' ', $s);
        $s = (string)preg_replace('/!\[[^\]]*$/mu', ' ', $s);
        // 5) 链接：完整/截断都保留文字
        $s = (string)preg_replace('/\[([^\]]*)\]\([^)\n]*\)/u', '$1', $s);
        $s = (string)preg_replace('/\[([^\]]*)\]\([^)\n]*/u', '$1', $s);
        $s = (string)preg_replace('/\[([^\]]+)\]\[[^\]]*\]/u', '$1', $s);
        // 行内代码：去反引号留文本
        $s = str_replace('`', '', $s);
        // 6) 标记符：标题（行首与行中）、引用、列表、表格、分割线
        $s = (string)preg_replace('/^[ \t]*#{1,6}[ \t]*/mu', ' ', $s);
        $s = (string)preg_replace('/(?<![\w#])#{1,6}[ \t]+/u', ' ', $s);
        $s = (string)preg_replace('/^[ \t]*>+[ \t]?/mu', ' ', $s);
        $s = (string)preg_replace('/(?<=\s)(?:>[ \t]*){2,3}/u', ' ', $s);
        $s = (string)preg_replace('/(?:^|(?<=\s))(?:[-*+]|\d{1,3}[.)])[ \t]+/u', ' ', $s);
        $s = (string)preg_replace('/(?<=\s)-{3,}(?=\s|$)/u', ' ', $s);
        $s = (string)preg_replace('/^[ \t]*\|?[ \t]*:?-{3,}:?[ \t]*\|?[ \t]*$/mu', ' ', $s);
        $s = (string)preg_replace('/^[ \t]*(?:[-*_][ \t]*){3,}$/mu', ' ', $s);
        $s = str_replace('\\|', '|', $s);
        // 7) 强调（成对去；残符去）
        $s = (string)preg_replace('/\*\*(.+?)\*\*/su', '$1', $s);
        $s = (string)preg_replace('/(?<![\w])__(.+?)__(?![\w])/su', '$1', $s);
        $s = (string)preg_replace('/~~(.+?)~~/su', '$1', $s);
        $s = (string)preg_replace('/(?<![\w*])\*([^*\s][^*]*?)\*(?![\w*])/su', '$1', $s);
        $s = str_replace(['**', '__', '~~'], '', $s);
        // 残留未配对的方括号（导入截断常见，如 "[![alt…"）；成对括号（如 [宋]）保留
        $s = self::dropStrayBrackets($s);
        // 8) 实体解码
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 9) 压缩空白（单行）
        $s = (string)preg_replace('/[\s\x{00A0}\x{3000}]+/u', ' ', $s);
        return trim($s);
    }

    /** 去掉不成对的方括号（Markdown 残片），保留成对括号（如人名前的 [宋]） */
    private static function dropStrayBrackets(string $s): string
    {
        if (strpos($s, '[') === false && strpos($s, ']') === false) {
            return $s;
        }
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stack = [];
        $drop = [];
        foreach ($chars as $i => $ch) {
            if ($ch === '[') {
                $stack[] = $i;
            } elseif ($ch === ']') {
                if ($stack) { array_pop($stack); } else { $drop[$i] = true; }
            }
        }
        foreach ($stack as $i) { $drop[$i] = true; }
        if (!$drop) { return $s; }
        $out = '';
        foreach ($chars as $i => $ch) {
            if (!isset($drop[$i])) { $out .= $ch; }
        }
        return $out;
    }

    /** 摘要开头清理：去残留符号、去「请输入图片描述」类占位 caption，保证第一句是人话 */
    private static function leadClean(string $t): string
    {
        $junk = '/^\s*(?:请输入图[片文]描述?|图片?描述|图注|图片来源|点击查看大图|上下滚动查看更多)[：:。.\s]*/u';
        for ($i = 0; $i < 3; $i++) {
            $t = ltrim($t, " \t\r\n");
            $t = (string)preg_replace('/^[>\-–—·•*#|,，。、;；:：!！?？)）\]】…\.]+[ \t]*/u', '', $t);
            $t = (string)preg_replace($junk, '', $t);
        }
        return trim($t);
    }

    /** 把 Markdown/HTML 转成纯文本（用于摘要、搜索结果展示） */
    public static function plain(string $s): string
    {
        return self::md2text($s);
    }

    /** 拆分检索词 */
    public static function terms(string $q): array
    {
        // 各类引号统一替换为空格后，再用正则按标点/空白拆分
        $q = str_replace(['"', "'", "“", "”", "‘", "’"], ' ', $q);
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
        $seg = mb_substr($plain, $start, $radius * 2);
        return $seg . '…';
    }

    /** 把 Markdown 还原成可读纯文本（摘要/meta 用，避免 ![图](..) 等语法漏出） */
    public static function cleanMd(string $md): string
    {
        return self::md2text($md);
    }
}
