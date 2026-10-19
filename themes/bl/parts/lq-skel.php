<?php
/** lqskel —— 「精仿」骨架渲染层（★ 2026-10-05 本轮）
 * ===========================================================================
 * 做法（站主定的正解）：**不再"照着重画"**，直接把参照页 `www.liulanqi.com` 的**真实
 * HTML 骨架**当模板用；只在"动态数据点"处换成 `{{占位符}}`，其余标签结构 / 类名 / 属性 /
 * 嵌套层级**逐字节照抄**。
 *
 * 骨架文件 = `themes/bl/lqwww/skel/*.html`，由生成器 `blog/_lqpix/src/mkhome.py` 从参照
 * 页 `seo/*.html`（主域盘上只读）**按字节切片**得到 —— 生成器只做三件事：
 *   ① 用 `span.find_span()` 取"完整元素"的字节区间（不经过任何 HTML 序列化器 ⇒ 不失真）；
 *   ② 用**字面量替换**把动态点换成 `{{name}}`（替换数必须恰为 1，否则生成器报错退出）；
 *   ③ 写盘。⇒ 除占位符外，骨架与参照逐字节相同（可用 `skelcmp.py` 复核）。
 *
 * 本文件只做三件事：读骨架 · 填占位符 · 重复渲染列表项。**没有任何"画 UI"的代码。**
 */
if (!function_exists('lqskel_dir')) {

    /** 骨架目录 */
    function lqskel_dir(): string
    {
        return dirname(__DIR__) . '/lqwww/skel';
    }

    /** 读骨架（请求内缓存；缺失返回空串并记日志，绝不静默回落到"手画版"） */
    function lqskel(string $name): string
    {
        static $cache = [];
        if (!array_key_exists($name, $cache)) {
            $p = lqskel_dir() . '/' . $name . '.html';
            if (is_file($p)) {
                $cache[$name] = (string)file_get_contents($p);
            } else {
                $cache[$name] = '';
                error_log('[lqskel] missing skeleton: ' . $p);
            }
        }
        return $cache[$name];
    }

    /**
     * 填占位符。
     * ★ 未提供的占位符**绝不留到页面上**（否则会露出 `{{xx}}` 给用户/爬虫）：
     *   先记日志，再替换成空串 —— 这是"留空并报我"的落地方式。
     */
    function lqskel_fill(string $tpl, array $vars, string $label = ''): string
    {
        $out = (string)preg_replace_callback(
            '/\{\{([A-Za-z0-9_]+)\}\}/',
            static function (array $m) use ($vars): string {
                return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : '';
            },
            $tpl
        );
        if (preg_match_all('/\{\{[A-Za-z0-9_]+\}\}/', $out, $mm) && $mm[0] !== []) {
            error_log('[lqskel] unfilled ' . implode(',', array_unique($mm[0])) . ' in ' . $label);
            $out = str_replace($mm[0], '', $out);
        }
        return $out;
    }

    /** 列表项：同一份**参照的**条目骨架重复 N 次（模板由调用方给，逐字节来自参照） */
    function lqskel_repeat(string $itemTpl, array $rows, callable $map, string $label = ''): string
    {
        $h = '';
        foreach ($rows as $i => $r) {
            $h .= lqskel_fill($itemTpl, (array)$map($r, $i), $label);
        }
        return $h;
    }

    /* ------------------------------------------------------------------ 图标
     * 参照的工具卡是「每工具一个 lucide 图标 + 一个色阶」。本实例的工具**没有**图标列，
     * 但有**唯一真源**的分类表（parts/cat-def.php）⇒ 按分类取图标与色阶（真数据，不编造）。
     * 结构完全一致：`<span class="… bg-primary/10 {{color}}">{{svg h-4 w-4}}</span>`
     * ⇒ 图标盒子的几何完全相同，只有"字形"这一个动态点不同（已在报告里列明）。
     */
    function lqskel_tool_face(string $catKey): array
    {
        static $M = [
            'text'    => ['text',       'text-sky-600'],
            'dev'     => ['code',       'text-violet-600'],
            'convert' => ['shuffle',    'text-amber-600'],
            'doc'     => ['file',       'text-blue-600'],
            'media'   => ['image',      'text-emerald-600'],
            'av'      => ['play',       'text-rose-600'],
            'calc'    => ['calculator', 'text-teal-600'],
            'date'    => ['clock',      'text-indigo-600'],
            'design'  => ['palette',    'text-pink-600'],
            'ai'      => ['sparkles',   'text-purple-600'],
            'seo'     => ['tag',        'text-lime-600'],
            'life'    => ['heart',      'text-orange-600'],
            'sec'     => ['shield',     'text-red-600'],
        ];
        return $M[$catKey] ?? ['wrench', 'text-fuchsia-600'];
    }

    /* -------------------------------------------------------------- 书签数据
     * 首页「去哪儿 · 常用网站」那 2 张分类卡 = 实例 `/bookmark` 页**自己的真数据**。
     * `/bookmark` 是一行 `contents`（slug='bookmark'，type=page，content=渲染好的卡片 HTML）
     * ⇒ 这里只**读**它、按 `id="sec-N"` 切分类、按卡片取 (url, 名称, 描述)。
     * 取不到 ⇒ 返回空数组，由调用方走"空态"（不编任何网址）。
     */
    function lqskel_bookmarks(int $wantCats = 2, int $perCat = 8): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $res = ['cats' => [], 'ncat' => 0, 'nsite' => 0];
        $row = Db::row(
            'SELECT content FROM ' . Db::t('contents')
            . " WHERE slug='bookmark' AND status='publish' AND deleted_at IS NULL LIMIT 1"
        );
        $html = $row ? (string)$row['content'] : '';
        if ($html === '') {
            return $cache = $res;
        }
        /* ★ 分类标题在**原始 content** 里是 `<h2 class="font-semibold">搜索</h2>`（没有 id；
           页面上看到的 `id="sec-N"` 是前台再加工的）⇒ 这里按 h2 的**字节位置**切分类块。 */
        if (!preg_match_all('#<h2 class="font-semibold"[^>]*>([^<]*)</h2>#u', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $cache = $res;
        }
        $res['ncat'] = count($m[1]);
        $res['nsite'] = (int)preg_match_all('#target="_blank" rel="noopener noreferrer"#', $html);
        $n = count($m[1]);
        for ($i = 0; $i < $n && count($res['cats']) < $wantCats; $i++) {
            $start = (int)$m[1][$i][1] + strlen($m[1][$i][0]);
            $end   = ($i + 1 < $n) ? (int)$m[1][$i + 1][1] : strlen($html);
            $blk   = substr($html, $start, $end - $start);
            $sites = [];
            /* ★ 每张卡：外层 `<a href … target=_blank rel=noopener noreferrer>`，
               内层再取「名称」（truncate text-sm font-medium）与「一句话描述」（title= 属性，
               即页面上那个 `text-[11px]/80` 的 div）。分两步取 ⇒ 描述缺失时不会把整条丢掉。 */
            if (preg_match_all(
                '#<a href="([^"]+)" target="_blank" rel="noopener noreferrer"[^>]*>(.*?)</a>#su',
                $blk, $c, PREG_SET_ORDER
            )) {
                foreach ($c as $one) {
                    $inner = (string)$one[2];
                    $nm = [];
                    $ds = [];
                    preg_match('#<div class="truncate text-sm font-medium">([^<]*)</div>#u', $inner, $nm);
                    preg_match('#title="([^"]*)"#u', $inner, $ds);
                    $sites[] = ['url' => (string)$one[1], 'name' => (string)($nm[1] ?? ''), 'desc' => (string)($ds[1] ?? '')];
                }
            }
            if ($sites === []) {
                continue;
            }
            $res['cats'][] = [
                'name'  => (string)$m[1][$i][0],
                'sites' => array_slice($sites, 0, $perCat),
            ];
        }
        return $cache = $res;
    }
}
