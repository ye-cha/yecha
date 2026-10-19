<?php
/** huangyu 共用小工具：圆形头像（纯色圆+首字）+ 摘要去 Markdown 残留
 *  用 function_exists 守卫，避免同一请求内多个 partial 重复声明。 */
if (!function_exists('hyu_avatar')) {
    function hyu_avatar(string $seed, string $title): array {
        // 绿色调色板（与主色 #0ca678 同系，深浅交替，保证区分度）
        $pal = ['#0ca678', '#099268', '#12b886', '#0f9d76', '#1c9e6b', '#34c98a', '#0ca678', '#2bb673'];
        $h = crc32($seed !== '' ? $seed : $title);
        $color = $pal[abs($h) % count($pal)];
        $ini = mb_substr(trim($title), 0, 1, 'UTF-8');
        if ($ini === '') { $ini = '·'; }
        return [$color, $ini];
    }

    function hyu_excerpt(string $raw, int $n = 110): string {
        // 去 Markdown 残留：分隔线 ---、引用行 >、图片片段 [![...](...)] 与 ![...](...)
        $t = (string)$raw;
        $t = preg_replace('/-{3,}/', ' ', $t);                          // ---
        $t = preg_replace('/^[ \t]*>[ \t]?.*$/m', ' ', $t);            // > 引用行
        $t = preg_replace('/\[?!\[[^\]]*\]\([^)]*\)\]?/', ' ', $t);    // ![...](...) / [![...](...)]
        $t = strip_tags($t);
        $t = str_replace(["\r", "\n", "\t"], ' ', $t);
        $t = preg_replace('/\s+/u', ' ', $t);
        $t = trim($t);
        if (mb_strlen($t, 'UTF-8') > $n) { $t = mb_substr($t, 0, $n, 'UTF-8') . '…'; }
        return $t;
    }
}
