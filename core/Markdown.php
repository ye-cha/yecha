<?php
declare(strict_types=1);

/**
 * Markdown：极简安全渲染（升级版块级解析）
 * 支持：标题(#~###### 层级对应) / 段落 / 引用(多行) / 无序有序列表(分组) / 围栏代码 /
 * 表格(分隔行) / 分割线 / 图片与链接 / 行内 code/bold/italic/del
 * 安全：正文先转义再组合标签；URL 协议白名单(http/https/mailto/相对)。
 */
final class Markdown
{
    public static function render(string $src): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $src);
        if ($lines === false) { $lines = []; }
        $out = '';
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $raw = $lines[$i];
            $t = trim($raw);

            if ($t === '') { $i++; continue; }

            // 围栏代码
            if (str_starts_with($t, '```')) {
                $buf = [];
                $i++;
                while ($i < $n && !str_starts_with(trim($lines[$i]), '```')) {
                    $buf[] = $lines[$i];
                    $i++;
                }
                $i++; // 跳过闭合围栏
                $out .= '<pre><code>' . e(implode("\n", $buf)) . '</code></pre>';
                continue;
            }
            // 分割线
            if ($t === '---' || $t === '***' || $t === '___') { $out .= '<hr>'; $i++; continue; }
            // 标题（层级如实：### -> h3）
            if (preg_match('/^(#{1,6})\s+(.*)$/', $t, $m)) {
                $lv = strlen($m[1]);
                $txt = trim($m[2]);
                $hid = '';
                // t38：支持显式锚点 `## 标题 {#anchor}`（Front::post 会沿用该 id 生成目录）
                if (preg_match('/\s*\{#([A-Za-z0-9_-]{1,40})\}\s*$/', $txt, $ma)) {
                    $hid = $ma[1];
                    $txt = trim(substr($txt, 0, -strlen($ma[0])));
                }
                $out .= '<h' . $lv . ($hid !== '' ? ' id="' . $hid . '"' : '') . '>' . self::inline(e($txt)) . '</h' . $lv . '>';
                $i++;
                continue;
            }
            // 引用（连续多行合并）
            if ($t[0] === '>') {
                $q = [];
                while ($i < $n && str_starts_with(trim($lines[$i]), '>')) {
                    $s = trim($lines[$i]);
                    $q[] = trim(substr($s, 1));
                    $i++;
                }
                $out .= '<blockquote>' . self::inline(e(implode("\n", $q))) . '</blockquote>';
                continue;
            }
            // 列表（相邻列表项分组，有序/无序按首项判定）
            if (preg_match('/^\s*([-*+]|\d+[.)])\s+(.*)$/', $raw, $lm)) {
                $ordered = is_numeric($lm[1]);
                $items = [trim($lm[2])];
                $i++;
                while ($i < $n && trim($lines[$i]) !== ''
                    && preg_match('/^\s*([-*+]|\d+[.)])\s+(.*)$/', trim($lines[$i]), $nm2)) {
                    $items[] = trim($nm2[2]);
                    $i++;
                }
                $tag = $ordered ? 'ol' : 'ul';
                $out .= '<' . $tag . '>';
                foreach ($items as $it) {
                    $out .= '<li>' . self::inline(e($it)) . '</li>';
                }
                $out .= '</' . $tag . '>';
                continue;
            }
            // 表格：当前行含 |，下一行为分隔行 |---|---|
            if (str_contains($t, '|') && $i + 1 < $n && preg_match('/^\s*\|?[\s:|-]+\|/', $lines[$i + 1])) {
                $rows = [];
                $row = self::splitRow($t);
                $rows[] = $row;
                $i += 2; // 跳过表头与分隔
                while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                    $rows[] = self::splitRow(trim($lines[$i]));
                    $i++;
                }
                $html = '<table>';
                foreach ($rows as $ri => $cells) {
                    $html .= '<tr>';
                    foreach ($cells as $cell) {
                        $tag = $ri === 0 ? 'th' : 'td';
                        $html .= '<' . $tag . '>' . self::inline(e(trim($cell))) . '</' . $tag . '>';
                    }
                    $html .= '</tr>';
                }
                $out .= $html . '</table>';
                continue;
            }
            // 段落（合并到下一个块级结构前）
            $para = [self::inline(e($raw))];
            $i++;
            while ($i < $n && trim($lines[$i]) !== '' && !self::isBlockStart(trim($lines[$i]))) {
                $para[] = self::inline(e($lines[$i]));
                $i++;
            }
            $out .= '<p>' . implode('<br>', $para) . '</p>';
        }
        return $out;
    }

    private static function isBlockStart(string $t): bool
    {
        return $t[0] === '>'
            || str_starts_with($t, '```')
            || $t === '---' || $t === '***' || $t === '___'
            || preg_match('/^#{1,6}\s+/', $t) === 1
            || preg_match('/^\s*([-*+]|\d+[.)])\s+/', $t) === 1
            || (str_contains($t, '|') && preg_match('/^\s*\|?[\s:|-]+\|/', $t) === 1 && self::looksLikeTableRow($t));
    }

    /**
     * 安全清洗导来的富文本 HTML（如 51puer 资讯）：去掉 script/style/iframe/object/embed、
     * on* 事件属性、javascript: 协议，并清掉 Word 复制残留的 style/class/lang 等属性。
     * 保留结构标签与 <img> 的 width/height/src/alt。供博客正文与商城资讯共用。
     * （内核合流 2026-09-26，来源：yecha-dev core/Markdown.php）
     */
    public static function sanitizeForeignHtml(string $h): string
    {
        $h = preg_replace('#<script\b.*?</script>#is', '', $h);
        $h = preg_replace('#<style\b.*?</style>#is', '', $h);
        $h = preg_replace('#<iframe\b.*?</iframe>#is', '', $h);
        $h = preg_replace('#<object\b.*?</object>#is', '', $h);
        $h = preg_replace('#<embed\b[^>]*/?>#i', '', $h);
        $h = preg_replace('#\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $h);
        $h = preg_replace('#\s+(?:href|src)\s*=\s*("javascript:[^"]*"|\'javascript:[^\']*\')#i', ' src="#"', $h);
        $h = preg_replace('#</?o:p\s*/?>#i', '', $h);
        $h = preg_replace('#\s+(?:style|class|lang|face|size|vspace|hspace|clear|type)\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $h);
        return $h;
    }

    private static function looksLikeTableRow(string $t): bool
    {
        return substr_count($t, '|') >= 1;
    }

    private static function listItemText(string $raw): string
    {
        return preg_replace('/^\s*([-*+]|\d+[.)])\s+/', '', $raw) ?? trim($raw);
    }

    private static function lstripMarker(string $s): string
    {
        return preg_replace('/^\s*([-*+]|\d+[.)])\s+/', '', $s) ?? trim($s);
    }

    private static function splitRow(string $line): array
    {
        $line = trim($line);
        if (str_starts_with($line, '|')) { $line = substr($line, 1); }
        if (str_ends_with($line, '|')) { $line = substr($line, 0, -1); }
        $cells = array_map('trim', explode('|', $line));
        return $cells;
    }

    /** 行内：图片/链接/反引号/强调；输入必须已转义。URL 白名单过滤危险协议 */
    private static function inline(string $s): string
    {
        $safe = function (string $u): string {
            $u = trim($u);
            if ($u === '') { return '#'; }
            if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', $u, $mm) && !in_array(strtolower($mm[1]), ['http', 'https', 'mailto'], true)) {
                return '#';
            }
            return $u;
        };
        $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', function ($m) use ($safe) {
            $src = $safe($m[2]);
            if ($src === '#') { return $m[0]; }
            $alt = $m[1] === '' ? 'image' : $m[1];
            return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '">';
        }, $s) ?? $s;
        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', function ($m) use ($safe) {
            $u = $safe($m[2]);
            if ($u === '#') { return $m[0]; }
            if (preg_match('~\.(webp|png|jpe?g|gif|svg)$~i', $u)) {
                $alt = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                return '<a href="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '"><img src="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '"></a>';
            }
            $external = preg_match('~^https?://~i', $u) ? ' rel="noopener" target="_blank"' : '';
            return '<a href="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '"' . $external . '>' . $m[1] . '</a>';
        }, $s) ?? $s;
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
        $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('/\*([^*\n]+)\*/', '<em>$1</em>', $s) ?? $s;
        $s = preg_replace('/~~(.+?)~~/', '<del>$1</del>', $s) ?? $s;
        return $s;
    }
}
