<?php
/* lqht.php — 「913 款工具」我方【自写 UI】渲染器（第 14 棒建 · 第 15 棒扩到 66 个 widget）
 * ===========================================================================
 * ★ 分工（站主裁定 ⒜）：
 *   · **算法/行为** = 本地化的共享运行时段 `themes/bl/lqwww/lqtools/engine.js`
 *     （逐字节取自 herontool `public/engine.js`，sha256 = bcfe872c…；零外发）。
 *   · **界面/样式** = 本文件产出的标记 + `themes/bl/lqwww/lqtools/lqht.css`（**我方设计系统**）。
 *   · **数据** = 每款一份 `window.HT_CTX`（detail + tool_ui），落在该款 `ye_contents` 行内。
 * ★ 契约（**必须与引擎同源，不许改**）：引擎按 **id / data-* / 少量 class** 取元素。
 *   ⇒ 这些"钩子名"是**引擎契约**；其余样式一律走我方 class（`.lqht-*`）与 CSS 变量。
 * ★ 参照结构来源：herontool `views/tool.njk`（只读）逐支对位；**唯一刻意偏离**：
 *   ① 外链资源改指我方盘上路径（`/themes/bl/lqwww/lqtools/vendor/**`，零 nginx 改动）；
 *   ② `data.ui.*` 语言串按 zh 语言包取值后内联（本棒已取值，见 lqht_ui）。
 * ★ select 的 `selected` 语义**逐支对齐 njk**：只有 filetool/boximg/blurhash/pdftool/fx 组
 *   与 io 的 controls 才在 `o.v == dflt` 上打 `selected`；calc/gen/hash…**不打**
 *   （实测 26 处 `dflt != 首项`，若多打 selected 会与 oracle 初始值不同 ⇒ L40 意义上的行为偏差）。
 * ★ 不支持的 widget（probe / share / hotlist）⇒ **不渲染、返回 null**
 *   （`ai` 自第16棒起**已支持**：站主裁定用现成 AI 通道 + 登录门 + 20 次/人/天配额）
 *   （调用方计数并跳过，fail-closed，不许静默产出半成品）。
 * ★ 第 15 棒补（站主裁定① · 逐条准做）：
 *   · `icons` —— 引擎 `fetch('/static/icons-tabler.json')` 取的是**本站自己的**文件（非第三方）
 *     ⇒ 在站点根放该文件即成立（**非 nginx 改动**）。
 *   · `hash` —— 引擎 `fetch('/api/hash?text=&algo=')` ⇒ 在**实例自己的入口** index.php 加一条
 *     声明式路由（纯本地 hash()，**零外发**；非 nginx、非主域）；实测 8/8 与参照逐字对拍相同。
 */

function lqht_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** 参照 zh 语言包里 `data.ui.*` 的取值（只影响**我方**按钮/提示文案，不影响引擎钩子） */
function lqht_ui(string $k): string
{
    static $m = [
        'kc_hint' => '点击此处后按下任意按键', 'kc_key' => '按键', 'kc_code' => 'Code', 'kc_history' => '按键历史',
        'cam_start' => '开始录像', 'cam_stop' => '停止', 'cam_dl' => '下载视频',
        'tts_rate' => '语速', 'tts_pitch' => '音调', 'tts_btn' => '朗读', 'tts_stop' => '停止',
        'tp_start' => '开始测试',
        'ch_start' => '开始', 'ch_lap' => '圈数', 'ch_reset' => '复位', 'ch_laps' => '圈数记录',
        'save' => '保存', 'color' => '颜色', 'size' => '笔刷',
    ];
    return $m[$k] ?? '';
}

/**
 * 本渲染器已实现渲染的 widget 白名单（其余一律跳过并计数）
 * ★ [t22 ext-widget] 第22棒起 **probe / share 已支持**（服务端本地完成，访客侧零外发）：
 *   probe = /api/probe（curl + 本机 dns_get_record / openssl 握手；内网/回环/保留地址一律拒绝）
 *   share = /api/share + /s/<短码>（库表 ye_shares · 默认 7 天过期 · 分享页 CSP sandbox 隔离）
 * ★ [t24 hotlist] 第24棒起 **hotlist 已支持**：/api/topics（服务端抓公开接口 + 20 分钟文件缓存 + 每 IP 每分钟 40 次频控，
 *   上游失败用过期缓存兜底）；字段结构参考开源项目 imsyy/DailyHotApi（MIT）。访客侧仍零外发。
 *   ⇒ 共 **21 款**，均为"需联网/需服务端抓取"（站主裁定② **登记不做**）。
 */
function lqht_supported(string $w): bool
{
    static $ok = [
        // 第 14 棒已有
        'io', 'calc', 'gen', 'meta', 'cssgen', 'shadow', 'contrast', 'counter',
        // 第 15 棒新增（第 1 组 · 文本/编码）
        'markdown', 'regex', 'font', 'diff', 'jwt', 'timestamp', 'svg', 'color', 'image',
        // 第 2 组 · 画布/媒体
        'mdimg', 'paint', 'notes', 'wordcloud', 'heatmap', 'waveform', 'gifsplit',
        'codepng', 'plot', 'easing', 'palette', 'batchimg', 'emojifav', 'paint',
        // 第 3 组 · 文件/PDF/条码
        'filetool', 'boximg', 'blurhash', 'crop', 'barcode', 'qr', 'qrread',
        'pdftool', 'pdfmerge', 'pdfsplit', 'pdfcompress', 'b64file', 'exif',
        // 第 4 组 · 图片处理（canvas 同支）
        'rotate', 'grayscale', 'watermark', 'border', 'corner', 'overlay', 'collage',
        'favicon', 'ascii',
        // 第 5 组 · 其它
        'highlight', 'prompt', 'polish', 'mockup', 'currency', 'equation',
        'keycode', 'device', 'camera', 'tts', 'typing', 'chronometer', 'cheat', 'videocompress',
        // 第 6 组 · 站主裁定① 逐条准做
        'icons',
        'hash',
        'ai',
        // 第 22 棒新增（[t22 ext-widget]）：probe = 服务端探测 4 款 · share = 即现分享
        'probe',
        'share',
        // 第 24 棒新增（[t24 hotlist]）：hotlist = 全网热榜聚合（服务端 /api/topics 取数 + 缓存 + 频控）
        'hotlist',
    ];
    return in_array($w, $ok, true);
}

/** detail.labels → 数组 */
function lqht_lab(array $d): array { return (array)($d['labels'] ?? []); }

/** 一个字段 → 标记（label + 控件；id 是引擎契约，class 是我方的）
 *  $sel=true 时才在 dflt 命中项上打 `selected`（逐支对齐 njk） */
function lqht_field(array $f, bool $sel = false): string
{
    $id = (string)($f['id'] ?? '');
    if ($id === '') { return ''; }
    $lab   = (string)($f['label'] ?? $id);
    $type  = (string)($f['type'] ?? 'text');
    $ph    = (string)($f['placeholder'] ?? '');
    $dflt  = array_key_exists('dflt', $f) ? (string)$f['dflt'] : '';
    $half  = !empty($f['half']) ? ' half' : '';
    if ($type === 'select') {
        $c = '<select class="calc-field" id="' . lqht_e($id) . '">';
        foreach ((array)($f['options'] ?? []) as $o) {
            $v  = (string)($o['v'] ?? '');
            $ol = (string)($o['label'] ?? $v);
            $c .= '<option value="' . lqht_e($v) . '"' . ($sel && $v === $dflt && $dflt !== '' ? ' selected' : '') . '>' . lqht_e($ol) . '</option>';
        }
        $c .= '</select>';
    } elseif ($type === 'textarea') {
        $c = '<textarea class="calc-field" id="' . lqht_e($id) . '" rows="' . (int)($f['rows'] ?? 4) . '" placeholder="' . lqht_e($ph) . '"></textarea>';
    } elseif ($type === 'color') {
        $c = '<input class="calc-field" id="' . lqht_e($id) . '" type="color" value="' . lqht_e($dflt !== '' ? $dflt : '#000000') . '">';
    } else {
        $ok = ['number', 'range', 'date', 'time', 'email', 'url', 'tel', 'datetime-local', 'file'];
        $ty = in_array($type, $ok, true) ? $type : 'text';
        $c  = '<input class="calc-field" id="' . lqht_e($id) . '" type="' . lqht_e($ty) . '"'
            . ($dflt !== '' ? ' value="' . lqht_e($dflt) . '"' : '')
            . ' placeholder="' . lqht_e($ph) . '">';
    }
    return '<div class="calc-field-wrap' . $half . '"><label for="' . lqht_e($id) . '">' . lqht_e($lab) . '</label>' . $c . '</div>';
}

/** detail.fields → .calc-fields 段 */
function lqht_fields(array $d, bool $sel = false): string
{
    $h = '';
    foreach ((array)($d['fields'] ?? []) as $f) { $h .= lqht_field((array)$f, $sel); }
    return $h;
}

function lqht_fields_box(array $d, bool $sel = false, string $extra = ''): string
{
    $h = lqht_fields($d, $sel);
    return $h === '' ? '' : '<div class="calc-fields"' . $extra . '>' . $h . '</div>';
}

/** calc/meta/cssgen/shadow/contrast 的 outs（逐字节对位 njk 189-210） */
function lqht_outs_calc(array $d, array $ui): string
{
    $h = '';
    foreach ((array)($d['out'] ?? []) as $o) {
        $id  = (string)($o['id'] ?? '');
        $lab = (string)($o['label'] ?? $id);
        if ($id === '') { continue; }
        if ($id === 'code') {
            $h .= '<textarea class="calc-code" id="calc-out-code" readonly placeholder="' . lqht_e($ui['copy_code'] ?? '') . '"></textarea>';
        } elseif ($id === 'preview') {
            $h .= '<div class="calc-out serp-out" id="calc-out-preview" style="white-space:normal"></div>';
        } else {
            $h .= '<div class="calc-out"><span class="lab">' . lqht_e($lab) . '</span><span class="val" id="calc-out-' . lqht_e($id) . '">—</span></div>';
        }
    }
    return $h === '' ? '' : '<div class="calc-outs">' . $h . '</div>';
}

/** color 分支的 outs（njk 135-137：scale 是 tt-output 文本域；preview 进 calc-out 包裹） */
function lqht_outs_color(array $d, array $ui): string
{
    $h = '';
    foreach ((array)($d['out'] ?? []) as $o) {
        $id  = (string)($o['id'] ?? '');
        $lab = (string)($o['label'] ?? $id);
        if ($id === '') { continue; }
        if ($id === 'scale') {
            $h .= '<textarea class="tt-output" id="calc-out-scale" readonly placeholder="' . lqht_e($ui['process_hint'] ?? '') . '"></textarea>';
        } elseif ($id === 'preview') {
            $h .= '<div class="calc-out"><span class="lab">' . lqht_e($lab) . '</span><div class="serp-out" id="calc-out-preview"></div></div>';
        } else {
            $h .= '<div class="calc-out"><span class="lab">' . lqht_e($lab) . '</span><span class="val" id="calc-out-' . lqht_e($id) . '">—</span></div>';
        }
    }
    return $h === '' ? '' : '<div class="calc-outs">' . $h . '</div>';
}

/** hash/timestamp/jwt/svg 分支的 outs（njk 615-617：全是 tt-output 文本域） */
function lqht_outs_text(array $d, array $ui): string
{
    $h = '';
    foreach ((array)($d['out'] ?? []) as $o) {
        $id = (string)($o['id'] ?? '');
        if ($id === '') { continue; }
        $h .= '<textarea class="tt-output" id="calc-out-' . lqht_e($id) . '" readonly placeholder="' . lqht_e($ui['process_hint'] ?? '') . '"></textarea>';
    }
    return $h === '' ? '' : '<div class="calc-outs">' . $h . '</div>';
}

/** detail.controls → io 的控制条（btn / chk / field；钩子名是引擎契约）
 *  ★ io 的 field 分支在 njk 90 **有** `selected` */
function lqht_controls(array $d): string
{
    $h = '';
    foreach ((array)($d['controls'] ?? []) as $c) {
        $kind = (string)($c['kind'] ?? '');
        if ($kind === 'btn') {
            $h .= '<div class="ctrl-btns">';
            $dflt = (string)($c['default'] ?? '');
            foreach ((array)($c['opts'] ?? []) as $o) {
                $act = (string)($o['act'] ?? '');
                $h .= '<button type="button" data-act="' . lqht_e($act) . '"' . ($act === $dflt ? ' class="on"' : '') . '>' . lqht_e((string)($o['label'] ?? $act)) . '</button>';
            }
            $h .= '</div>';
        } elseif ($kind === 'chk') {
            $h .= '<label class="chk"><input type="checkbox" data-opt="' . lqht_e((string)($c['opt'] ?? '')) . '"'
                . (!empty($c['checked']) ? ' checked' : '') . '> ' . lqht_e((string)($c['label'] ?? '')) . '</label>';
        } elseif ($kind === 'field') {
            $h .= lqht_field($c, true);
        }
    }
    return $h === '' ? '' : '<div class="tt-controls">' . $h . '</div>';
}

/** io 的 field 控制（tt-field 系列，njk 88-92） */
function lqht_ctrl_fields(array $d): string
{
    $h = '';
    foreach ((array)($d['controls'] ?? []) as $c) {
        if ((string)($c['kind'] ?? '') !== 'field') { continue; }
        $id = (string)($c['id'] ?? '');
        if ($id === '') { continue; }
        $type = (string)($c['type'] ?? 'text');
        $ph = (string)($c['placeholder'] ?? '');
        $dflt = (string)($c['dflt'] ?? '');
        if ($type === 'textarea') {
            $h .= '<textarea class="tt-field tt-field-area" id="' . lqht_e($id) . '" rows="' . (int)($c['rows'] ?? 4) . '" placeholder="' . lqht_e($ph) . '"></textarea>';
        } elseif ($type === 'select') {
            $h .= '<select class="tt-field" id="' . lqht_e($id) . '">';
            foreach ((array)($c['options'] ?? []) as $o) {
                $v = (string)($o['v'] ?? '');
                $h .= '<option value="' . lqht_e($v) . '"' . ($v === $dflt && $dflt !== '' ? ' selected' : '') . '>' . lqht_e((string)($o['label'] ?? $v)) . '</option>';
            }
            $h .= '</select>';
        } else {
            $h .= '<input class="tt-field" id="' . lqht_e($id) . '" placeholder="' . lqht_e($ph) . '"'
                . ($dflt !== '' ? ' value="' . lqht_e($dflt) . '"' : '') . '>';
        }
    }
    return $h;
}

/** 外链第三方库（我方盘上副本；只在需要时加载，与 oracle 同条件） */
function lqht_vendor_scripts(string $w, array $d): array
{
    $op = (string)($d['op'] ?? '');
    $V  = '/themes/bl/lqwww/lqtools/vendor/';
    $out = [];
    if (in_array($op, ['json2yaml', 'yaml2json', 'dockercompose', 'yamlfmt', 'toml2yaml'], true)) { $out[] = '<script src="' . $V . 'js-yaml.min.js"></script>'; }
    if (in_array($op, ['toml2json', 'json2toml', 'toml2yaml'], true)) { $out[] = '<script src="' . $V . 'toml.js"></script>'; }
    if ($op === 'bcrypt') { $out[] = '<script src="' . $V . 'bcrypt.min.js"></script>'; }
    if ($op === 'qrread' || $w === 'qrread') { $out[] = '<script src="' . $V . 'jsQR.js"></script>'; }
    if ($op === 'bip39') { $out[] = '<script src="' . $V . 'bip39-words.js"></script>'; }
    if (in_array($op, ['textcompress', 'gzipc', 'zlibc', 'crc32'], true)) { $out[] = '<script src="' . $V . 'pako.min.js"></script>'; }
    if ($w === 'barcode') { $out[] = '<script src="' . $V . 'JsBarcode.all.min.js"></script>'; }
    if ($w === 'highlight') {
        $out[] = '<link rel="stylesheet" href="' . $V . 'highlight-github-dark.min.css">';
        $out[] = '<script src="' . $V . 'highlight.min.js"></script>';
    }
    if (in_array($w, ['pdfmerge', 'pdfsplit', 'pdfcompress', 'pdftool'], true)) { $out[] = '<script src="' . $V . 'pdf-lib.min.js"></script>'; }
    return $out;
}

/**
 * 主入口：一款工具 → 落在 ye_contents.content 里的**工具块**（含数据 + 共享引擎引用）
 * @return string|null  null = 该 widget 本棒未实现（调用方必须计数并跳过）
 */
function lqht_body(string $slug, array $d, array $ui, string $cssUrl, string $jsUrl, int $aiLimit = 0): ?string
{
    $w = (string)($d['widget'] ?? '');
    if (!lqht_supported($w)) { return null; }
    $desc = (string)($d['description'] ?? '');
    $lab  = lqht_lab($d);
    $L    = static function (string $k, string $dflt = '') use ($lab): string { return (string)($lab[$k] ?? $dflt); };
    $U    = static function (string $k, string $dflt = '') use ($ui): string { return (string)($ui[$k] ?? $dflt); };
    $op   = (string)($d['op'] ?? '');

    if ($w === 'io') {
        /* njk 67-99 */
        $inner = '<div class="lqht-widget text-tool lqht-io" data-tool="' . lqht_e($slug) . '">'
            . '<div class="tt-grid">'
            . '<div class="tt-col"><div class="w-label">' . lqht_e($U('input_label')) . '</div>'
            . '<textarea class="tt-input" id="ttInput" placeholder="' . lqht_e($U('input_placeholder')) . '"></textarea></div>'
            . '<div class="tt-col"><div class="w-label">' . lqht_e($U('out_label')) . '</div>'
            . '<textarea class="tt-output" id="ttOutput" readonly placeholder="' . lqht_e($U('process_hint')) . '"></textarea></div>'
            . '</div>'
            . lqht_controls($d)
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="ttCopy">' . lqht_e($U('copy_result')) . '</button>'
            . '<button type="button" class="btn-ghost" id="ttClear">' . lqht_e($U('clear')) . '</button></div>'
            . '</div>';
    } elseif ($w === 'counter') {
        /* njk 41-65 */
        $mode = (string)($d['mode'] ?? 'words');
        $cards = '<div class="stat-card"><div class="num" id="stChars">0</div><div class="lab">' . lqht_e($U('chars_with_space')) . '</div></div>'
            . '<div class="stat-card"><div class="num" id="stCharsNo">0</div><div class="lab">' . lqht_e($U('chars_no_space')) . '</div></div>';
        if ($mode === 'chars') {
            $cards .= '<div class="stat-card"><div class="num" id="stLines">0</div><div class="lab">' . lqht_e($U('lines')) . '</div></div>'
                . '<div class="stat-card"><div class="num" id="stParas">0</div><div class="lab">' . lqht_e($U('paragraphs')) . '</div></div>';
        } else {
            $cards .= '<div class="stat-card"><div class="num" id="stWords">0</div><div class="lab">' . lqht_e($U('words')) . '</div></div>'
                . '<div class="stat-card"><div class="num" id="stLines">0</div><div class="lab">' . lqht_e($U('lines')) . '</div></div>'
                . '<div class="stat-card"><div class="num" id="stParas">0</div><div class="lab">' . lqht_e($U('paragraphs')) . '</div></div>'
                . '<div class="stat-card"><div class="num" id="stRead">0</div><div class="lab">' . lqht_e($U('read_min')) . '</div></div>';
        }
        $inner = '<div class="lqht-widget lqht-counter" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($U('input_label')) . '</div>'
            . '<textarea class="tw-textarea" id="twInput" placeholder="' . lqht_e($U('input_placeholder')) . '"></textarea>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="twCopy">' . lqht_e($U('copy')) . '</button>'
            . '<button type="button" class="btn-ghost" id="twClear">' . lqht_e($U('clear')) . '</button></div>'
            . '<div class="stat-grid">' . $cards . '</div></div>';
    } elseif ($w === 'gen') {
        /* njk 214-231：fields 不打 selected */
        $inner = '<div class="lqht-widget text-tool gen-tool lqht-gen" data-tool="' . lqht_e($slug) . '">'
            . lqht_fields_box($d)
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="genBtn">' . lqht_e($U('generate')) . '</button>'
            . '<button type="button" class="btn-ghost" id="genClear">' . lqht_e($U('clear')) . '</button></div>'
            . '<div class="pal-swatches" id="palSwatches"></div>'
            . '<textarea class="tt-output" id="genOutput" readonly placeholder="' . lqht_e($U('process_hint')) . '"></textarea>'
            . '</div>';
    } elseif ($w === 'calc' || $w === 'meta' || $w === 'cssgen' || $w === 'shadow' || $w === 'contrast') {
        /* njk 189-212：fields 不打 selected */
        $inner = '<div class="lqht-widget calc-tool lqht-calc" data-tool="' . lqht_e($slug) . '">'
            . lqht_fields_box($d)
            . ($slug === 'gradient-generator' ? '<div class="grad-preview" id="gradPreview"></div>' : '')
            . lqht_outs_calc($d, $ui)
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="calcCopy">' . lqht_e($U('copy_result')) . '</button></div>'
            . '</div>';
    } elseif ($w === 'markdown') {
        /* njk 101-119 */
        $inner = '<div class="lqht-widget text-tool md-tool" data-tool="' . lqht_e($slug) . '">'
            . '<div class="tt-grid">'
            . '<div class="tt-col"><div class="w-label">' . lqht_e($U('input_label')) . '</div>'
            . '<textarea class="tt-input" id="mdInput" placeholder="# 标题&#10;**加粗** *斜体* `代码` - 列表 [链接](https://...)"></textarea></div>'
            . '<div class="tt-col"><div class="w-label">' . lqht_e($U('preview_label')) . '</div>'
            . '<div class="md-preview" id="mdPreview"></div></div>'
            . '</div>'
            . ($op === 'tohtml' ? '<div class="w-label" style="margin-top:12px">' . lqht_e($U('preview_label')) . ' (HTML)</div><textarea class="tt-output" id="mdHtml" readonly style="margin-top:8px" placeholder="&lt;h1&gt;..."></textarea>' : '')
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="mdCopy">' . lqht_e($U('copy_code')) . '</button>'
            . '<button type="button" class="btn-ghost" id="mdClear">' . lqht_e($U('clear')) . '</button></div>'
            . '</div>';
    } elseif ($w === 'color') {
        /* njk 121-146 */
        if ($op !== '') {
            $inner = '<div class="lqht-widget calc-tool lqht-color" data-tool="' . lqht_e($slug) . '">'
                . lqht_fields_box($d)
                . lqht_outs_color($d, $ui)
                . '</div>';
        } else {
            $inner = '<div class="lqht-widget calc-tool lqht-color" data-tool="' . lqht_e($slug) . '">'
                . '<div class="w-label">' . lqht_e($U('color_label', '颜色')) . '</div>'
                . '<input class="calc-field" id="colorInput" type="text" placeholder="#3e7c46 或 rgb(62,124,70)" style="max-width:320px">'
                . '<div class="color-swatch" id="colorSwatch"></div>'
                . lqht_outs_color($d, $ui)
                . '</div>';
        }
    } elseif ($w === 'regex') {
        /* njk 148-159 */
        $rxh = '<input class="tt-field" id="rxPattern" placeholder="' . lqht_e((string)($d['controls'][0]['placeholder'] ?? '')) . '" style="flex:2;min-width:200px">';
        foreach ((array)($d['controls'] ?? []) as $c) {
            if ((string)($c['kind'] ?? '') === 'chk') {
                $rxh .= '<label class="chk"><input type="checkbox" data-opt="' . lqht_e((string)($c['opt'] ?? '')) . '"> ' . lqht_e((string)($c['label'] ?? '')) . '</label>';
            }
        }
        $inner = '<div class="lqht-widget text-tool lqht-regex" data-tool="' . lqht_e($slug) . '">'
            . '<div class="tt-controls" style="margin-bottom:14px">' . $rxh . '</div>'
            . '<div class="tt-grid">'
            . '<div class="tt-col"><div class="w-label">' . lqht_e($U('input_label')) . '</div><textarea class="tt-input" id="rxInput" placeholder="' . lqht_e($U('input_placeholder')) . '"></textarea></div>'
            . '<div class="tt-col"><div class="w-label">' . lqht_e($U('matches_label')) . '</div><textarea class="tt-output" id="rxOutput" readonly placeholder="' . lqht_e($U('process_hint')) . '"></textarea></div>'
            . '</div></div>';
    } elseif ($w === 'font') {
        /* njk 161-168 */
        $fh = '';
        foreach ((array)($d['controls'] ?? []) as $c) {
            $kind = (string)($c['kind'] ?? '');
            $id = (string)($c['id'] ?? '');
            if ($id === '') { continue; }
            if ($kind === 'field') {
                $fh .= '<input class="tt-field" id="' . lqht_e($id) . '" placeholder="' . lqht_e((string)($c['placeholder'] ?? '')) . '" style="flex:2;min-width:200px">';
            } elseif ($kind === 'select') {
                $fh .= '<select class="tt-field" id="' . lqht_e($id) . '">';
                foreach ((array)($c['options'] ?? []) as $o) {
                    $v = (string)($o['v'] ?? '');
                    $fh .= '<option value="' . lqht_e($v) . '">' . lqht_e((string)($o['label'] ?? $v)) . '</option>';
                }
                $fh .= '</select>';
            }
        }
        $inner = '<div class="lqht-widget text-tool font-tool lqht-font" data-tool="' . lqht_e($slug) . '">'
            . ($fh === '' ? '' : '<div class="tt-controls">' . $fh . '</div>')
            . '<div class="font-preview" id="fontPreview">' . lqht_e($U('preview_label')) . '</div>'
            . '</div>';
    } elseif ($w === 'image') {
        /* njk 170-187 */
        $inner = '<div class="lqht-widget img-tool lqht-image" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($U('input_label')) . '</div>'
            . '<label class="btn-ghost file-btn" for="imgFile">' . lqht_e($U('choose_file')) . '</label>'
            . '<input type="file" id="imgFile" accept="image/*" class="img-file" style="display:none">'
            . '<div class="calc-outs" style="margin-top:14px">' . lqht_fields($d) . '</div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="imgBtn">' . lqht_e($U('process_label')) . '</button></div>'
            . '<div class="img-out" id="imgOut"></div>'
            . '</div>';
    } elseif ($w === 'diff') {
        /* njk 621-634 */
        $cols = '';
        foreach ((array)($d['fields'] ?? []) as $f) {
            $f = (array)$f;
            $cols .= '<div class="tt-col"><div class="w-label">' . lqht_e((string)($f['label'] ?? '')) . '</div>'
                . '<textarea class="tt-input" id="' . lqht_e((string)($f['id'] ?? '')) . '" placeholder="' . lqht_e((string)($f['placeholder'] ?? '')) . '"></textarea></div>';
        }
        $inner = '<div class="lqht-widget text-tool lqht-diff" data-tool="' . lqht_e($slug) . '">'
            . '<div class="tt-grid">' . $cols . '</div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="diffCopy">' . lqht_e($U('copy_result')) . '</button></div>'
            . '<textarea class="tt-output" id="calc-out-diffOut" readonly style="margin-top:12px" placeholder="' . lqht_e($U('process_hint')) . '"></textarea>'
            . '</div>';
    } elseif ($w === 'jwt' || $w === 'timestamp' || $w === 'svg' || $w === 'hash') {
        /* njk 602-619 */
        $inner = '<div class="lqht-widget calc-tool lqht-st" data-tool="' . lqht_e($slug) . '">'
            . lqht_fields_box($d)
            . lqht_outs_text($d, $ui)
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="stCopy">' . lqht_e($U('copy_result')) . '</button></div>'
            . '</div>';
    } elseif ($w === 'mdimg') {
        /* njk 260-267 */
        $inner = '<div class="lqht-widget text-tool mdimg-tool lqht-mdimg" data-tool="' . lqht_e($slug) . '">'
            . '<div class="calc-field-wrap" style="grid-column:1/-1"><label>' . lqht_e((string)($d['fields'][0]['label'] ?? '')) . '</label>'
            . '<textarea class="calc-field" id="mdimgInput" rows="8" placeholder="# 标题&#10;正文内容…"></textarea></div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="mdimgBtn">' . lqht_e($U('generate')) . '</button>'
            . '<button type="button" class="btn-ghost" id="mdimgClear">' . lqht_e($U('clear')) . '</button></div>'
            . '<div class="mdimg-prev" id="mdimgPrev"><canvas id="mdimgCanvas" style="display:none"></canvas></div>'
            . '<div class="tw-actions" id="mdimgDL" style="display:none"><a class="btn-copy" id="mdimgA" download="markdown-card.png">⬇ ' . lqht_e($U('download')) . '</a></div>'
            . '</div>';
    } elseif ($w === 'paint') {
        /* njk 269-280 */
        $inner = '<div class="lqht-widget paint-tool lqht-paint" data-tool="' . lqht_e($slug) . '">'
            . '<div class="paint-tools">'
            . '<label class="paint-lbl">' . lqht_e($U('color', '颜色')) . ' <input type="color" id="paintColor" value="#2f5a35"></label>'
            . '<label class="paint-lbl">' . lqht_e($U('size', '笔刷')) . ' <select id="paintSize"><option value="2">2</option><option value="5" selected>5</option><option value="10">10</option><option value="20">20</option></select></label>'
            . '<button type="button" class="btn-ghost" id="paintEraser">橡皮</button>'
            . '<button type="button" class="btn-ghost" id="paintClear">清除</button>'
            . '<button type="button" class="btn-copy" id="paintSave">下载 PNG</button>'
            . '</div>'
            . '<canvas id="paintCanvas" width="800" height="500" class="paint-canvas"></canvas>'
            . '</div>';
    } elseif ($w === 'notes') {
        /* njk 282-292 */
        $inner = '<div class="lqht-widget notes-tool lqht-notes" data-tool="' . lqht_e($slug) . '">'
            . '<textarea class="calc-field" id="notesArea" rows="12" placeholder="' . lqht_e($L('placeholder', '在此输入…自动保存到浏览器本地')) . '"></textarea>'
            . '<div class="tw-actions">'
            . '<button type="button" class="btn-ghost" id="notesSave">' . lqht_e(lqht_ui('save')) . '</button>'
            . '<button type="button" class="btn-copy" id="notesCopy">' . lqht_e($U('copy')) . '</button>'
            . '<button type="button" class="btn-ghost" id="notesClear">' . lqht_e($U('clear')) . '</button>'
            . '<span class="notes-status" id="notesStatus"></span>'
            . '</div></div>';
    } elseif ($w === 'wordcloud' || $w === 'heatmap') {
        /* njk 294-310 */
        $p = ($w === 'wordcloud') ? ['wc', 'word-cloud.png'] : ['hm', 'heatmap.png'];
        $ph = ($w === 'wordcloud')
            ? "词1 10&#10;词2 5&#10;词3 3（每行：词 权重，权重越大字越大）"
            : "1,2,3,4&#10;5,6,7,8&#10;9,10,11,12（每行一行，逗号分隔）";
        $stage = ($w === 'wordcloud') ? 'wc-stage' : 'hm-stage';
        $inner = '<div class="lqht-widget text-tool ' . $p[0] . '-tool lqht-' . $p[0] . '" data-tool="' . lqht_e($slug) . '">'
            . '<div class="calc-field-wrap" style="grid-column:1/-1"><label>' . lqht_e((string)($d['fields'][0]['label'] ?? '')) . '</label>'
            . '<textarea class="calc-field" id="' . $p[0] . 'Input" rows="6" placeholder="' . $ph . '"></textarea></div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="' . $p[0] . 'Btn">' . lqht_e($U('generate')) . '</button>'
            . '<button type="button" class="btn-ghost" id="' . $p[0] . 'Clear">' . lqht_e($U('clear')) . '</button></div>'
            . '<div class="' . $stage . '" id="' . $stage . '"><canvas id="' . $p[0] . 'Canvas"></canvas></div>'
            . '<div class="tw-actions" id="' . $p[0] . 'DL" style="display:none"><a class="btn-copy" id="' . $p[0] . 'A" download="' . $p[1] . '">⬇ ' . lqht_e($U('download')) . '</a></div>'
            . '</div>';
    } elseif ($w === 'waveform') {
        /* njk 312-318 */
        $inner = '<div class="lqht-widget text-tool wf-tool lqht-waveform" data-tool="' . lqht_e($slug) . '">'
            . '<div class="wf-choose"><button type="button" class="btn-copy file-btn" id="wfPick">📁 ' . lqht_e($U('choose_audio', '选择音频')) . '</button>'
            . '<input type="file" id="wfFile" accept="audio/*" style="display:none"><span class="wf-name" id="wfName"></span></div>'
            . '<div class="wf-stage" id="wfStage"><canvas id="wfCanvas"></canvas></div>'
            . '<div class="tw-actions" id="wfDL" style="display:none"><a class="btn-copy" id="wfA" download="waveform.png">⬇ ' . lqht_e($U('download')) . '</a></div>'
            . '</div>';
    } elseif ($w === 'gifsplit') {
        /* njk 320-325 */
        $inner = '<div class="lqht-widget text-tool gs-tool lqht-gifsplit" data-tool="' . lqht_e($slug) . '">'
            . '<div class="wf-choose"><button type="button" class="btn-copy file-btn" id="gifPick">📁 ' . lqht_e($U('choose_gif', '选择 GIF')) . '</button>'
            . '<input type="file" id="gifFile" accept="image/gif" style="display:none"><span class="wf-name" id="gifName"></span></div>'
            . '<div class="gs-stage" id="gsStage"></div>'
            . '</div>';
    } elseif ($w === 'qr') {
        /* njk 386-398 */
        $inner = '<div class="lqht-widget qr-tool lqht-qr" data-tool="' . lqht_e($slug) . '">'
            . '<div class="calc-fields">'
            . '<div class="calc-field-wrap" style="grid-column:1/-1"><label>' . lqht_e($L('text')) . '</label>'
            . '<textarea class="tt-field" id="qrText" placeholder="' . lqht_e($L('placeholder')) . '" rows="3"></textarea></div>'
            . '<div class="calc-field-wrap"><label>' . lqht_e($L('ec')) . '</label><select class="calc-field" id="qrEc"><option value="L">L</option><option value="M" selected>M</option><option value="Q">Q</option><option value="H">H</option></select></div>'
            . '<div class="calc-field-wrap"><label>' . lqht_e($L('size')) . '</label><input class="calc-field" id="qrSize" type="number" value="256" min="64" max="1024"></div>'
            . '<div class="calc-field-wrap"><label>' . lqht_e($L('dark')) . '</label><input class="calc-field" id="qrDark" type="color" value="#000000"></div>'
            . '<div class="calc-field-wrap"><label>' . lqht_e($L('light')) . '</label><input class="calc-field" id="qrLight" type="color" value="#ffffff"></div>'
            . '</div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="qrBtn">' . lqht_e($U('process_label')) . '</button></div>'
            . '<div class="qr-out" id="qrOut"></div>'
            . '</div>';
    } elseif ($w === 'highlight') {
        /* njk 411-427（vendor 两件在函数尾部统一注入） */
        $langs = ['javascript' => 'JavaScript', 'json' => 'JSON', 'python' => 'Python', 'html' => 'HTML', 'css' => 'CSS',
                  'bash' => 'Bash', 'sql' => 'SQL', 'xml' => 'XML', 'typescript' => 'TypeScript', 'java' => 'Java', 'cpp' => 'C++'];
        $opt = '<option value="auto">' . lqht_e($L('lang_auto')) . '</option>';
        foreach ($langs as $v => $t) { $opt .= '<option value="' . $v . '">' . $t . '</option>'; }
        $inner = '<div class="lqht-widget text-tool hl-tool lqht-hl" data-tool="' . lqht_e($slug) . '">'
            . '<div class="tt-controls">'
            . '<label class="hl-lang">' . lqht_e($L('lang')) . ' <select class="tt-field" id="hlLang">' . $opt . '</select></label>'
            . '<button type="button" class="btn-copy" id="hlBtn">' . lqht_e($U('process_label')) . '</button>'
            . '</div>'
            . '<div class="tt-grid">'
            . '<div class="tt-col"><div class="w-label">' . lqht_e($U('input_label')) . '</div><textarea class="tt-input" id="hlInput" placeholder="' . lqht_e($L('placeholder')) . '"></textarea></div>'
            . '<div class="tt-col"><div class="w-label">' . lqht_e($U('preview_label')) . '</div><pre class="hl-preview"><code id="hlOut"></code></pre></div>'
            . '</div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="hlCopy">' . lqht_e($U('copy_code')) . '</button></div>'
            . '</div>';
    } elseif ($w === 'crop') {
        /* njk 400-409 */
        $inner = '<div class="lqht-widget crop-tool lqht-crop" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($L('file')) . '</div>'
            . '<label class="btn-ghost file-btn" for="cropFile">' . lqht_e($U('choose_file')) . '</label>'
            . '<input type="file" id="cropFile" accept="image/*" class="img-file" style="display:none">'
            . '<div class="crop-stage" id="cropStage"><span class="crop-hint">' . lqht_e($L('tip')) . '</span></div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="cropBtn">' . lqht_e($U('process_label')) . '</button>'
            . '<button type="button" class="btn-ghost" id="cropClear">' . lqht_e($U('clear')) . '</button></div>'
            . '<div class="img-out" id="cropOut"></div>'
            . '</div>';
    } elseif ($w === 'prompt') {
        /* njk 429-441 */
        $inner = '<div class="lqht-widget text-tool prompt-tool lqht-prompt" data-tool="' . lqht_e($slug) . '">'
            . '<div class="calc-fields">'
            . '<div class="calc-field-wrap"><label>' . lqht_e($L('role')) . '</label><input class="calc-field" id="pRole" placeholder="' . lqht_e($L('role_ph')) . '"></div>'
            . '<div class="calc-field-wrap" style="grid-column:1/-1"><label>' . lqht_e($L('task')) . '</label><textarea class="tt-field" id="pTask" placeholder="' . lqht_e($L('task_ph')) . '" rows="2"></textarea></div>'
            . '<div class="calc-field-wrap" style="grid-column:1/-1"><label>' . lqht_e($L('context')) . '</label><textarea class="tt-field" id="pCtx" placeholder="' . lqht_e($L('ctx_ph')) . '" rows="2"></textarea></div>'
            . '<div class="calc-field-wrap" style="grid-column:1/-1"><label>' . lqht_e($L('constraints')) . '</label><textarea class="tt-field" id="pCon" placeholder="' . lqht_e($L('constr_ph')) . '" rows="2"></textarea></div>'
            . '<div class="calc-field-wrap" style="grid-column:1/-1"><label>' . lqht_e($L('output')) . '</label><input class="calc-field" id="pOut" placeholder="' . lqht_e($L('out_ph')) . '"></div>'
            . '</div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="pGen">' . lqht_e($L('gen')) . '</button>'
            . '<button type="button" class="btn-ghost" id="pCopy">' . lqht_e($U('copy_result')) . '</button>'
            . '<button type="button" class="btn-ghost" id="pClear">' . lqht_e($U('clear')) . '</button></div>'
            . '<textarea class="tt-output" id="pResult" readonly placeholder="' . lqht_e($U('process_hint')) . '" style="margin-top:14px"></textarea>'
            . '</div>';
    } elseif ($w === 'polish') {
        /* njk 443-456 */
        $inner = '<div class="lqht-widget text-tool polish-tool lqht-polish" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($L('input')) . '</div>'
            . '<textarea class="tt-input" id="polInput" placeholder="' . lqht_e($L('ph')) . '" rows="6"></textarea>'
            . '<div class="tt-controls" style="margin:10px 0">'
            . '<label class="chk"><input type="checkbox" data-opt="space" checked> ' . lqht_e($L('opt_space')) . '</label>'
            . '<label class="chk"><input type="checkbox" data-opt="case" checked> ' . lqht_e($L('opt_case')) . '</label>'
            . '<label class="chk"><input type="checkbox" data-opt="filler" checked> ' . lqht_e($L('opt_filler')) . '</label>'
            . '<label class="chk"><input type="checkbox" data-opt="punct" checked> ' . lqht_e($L('opt_punct')) . '</label>'
            . '</div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="polBtn">' . lqht_e($U('process_label')) . '</button>'
            . '<button type="button" class="btn-ghost" id="polCopy">' . lqht_e($U('copy_result')) . '</button>'
            . '<button type="button" class="btn-ghost" id="polClear">' . lqht_e($U('clear')) . '</button></div>'
            . '<textarea class="tt-output" id="polOut" readonly placeholder="' . lqht_e($U('process_hint')) . '" style="margin-top:14px"></textarea>'
            . '</div>';
    } elseif ($w === 'mockup') {
        /* njk 467-478 */
        $inner = '<div class="lqht-widget text-tool mockup-tool lqht-mockup" data-tool="' . lqht_e($slug) . '">'
            . '<div class="tt-controls">'
            . '<input class="tt-field" id="mkUrl" placeholder="' . lqht_e($L('ph')) . '" style="flex:2;min-width:200px">'
            . '<span class="mk-or">' . lqht_e($L('upload')) . '</span>'
            . '<label class="btn-ghost file-btn" for="mkFile">' . lqht_e($U('choose_file')) . '</label>'
            . '<input type="file" id="mkFile" accept="image/*" class="img-file" style="display:none">'
            . '<select class="tt-field" id="mkDev"><option value="desktop">' . lqht_e($L('desktop')) . '</option><option value="phone">' . lqht_e($L('phone')) . '</option><option value="tablet">' . lqht_e($L('tablet')) . '</option></select>'
            . '</div>'
            . '<div class="mockup-stage" id="mockupStage"></div>'
            . '</div>';
    } elseif ($w === 'currency') {
        /* njk 480-490 */
        $inner = '<div class="lqht-widget cur-tool lqht-currency" data-tool="' . lqht_e($slug) . '">'
            . '<div class="calc-fields">'
            . '<div class="calc-field-wrap"><label>' . lqht_e($L('amount')) . '</label><input class="calc-field" id="curAmt" type="number" value="1"></div>'
            . '<div class="calc-field-wrap"><label>' . lqht_e($L('from')) . '</label><select class="calc-field" id="curFrom"></select></div>'
            . '<div class="calc-field-wrap"><label>' . lqht_e($L('to')) . '</label><select class="calc-field" id="curTo"></select></div>'
            . '</div>'
            . '<div class="calc-outs"><div class="calc-out"><span class="lab">' . lqht_e($L('to')) . '</span><span class="val" id="curResult">—</span></div></div>'
            . '<p class="rate-note" id="curNote">' . lqht_e($L('rate_note')) . '</p>'
            . '</div>';
    } elseif ($w === 'pdfmerge' || $w === 'pdfsplit') {
        /* njk 492-512 */
        $isMerge = ($w === 'pdfmerge');
        $fid = $isMerge ? 'pdfFiles' : 'pdfSplitFile';
        $bid = $isMerge ? 'pdfMergeBtn' : 'pdfSplitBtn';
        $oid = $isMerge ? 'pdfMergeOut' : 'pdfSplitOut';
        $inner = '<div class="lqht-widget pdf-tool lqht-pdf" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($isMerge ? $L('files') : $L('file')) . '</div>'
            . '<label class="btn-ghost file-btn" for="' . $fid . '">' . lqht_e($U('choose_file')) . '</label>'
            . '<input type="file" id="' . $fid . '" accept="application/pdf"' . ($isMerge ? ' multiple' : '') . ' class="img-file" style="display:none">'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="' . $bid . '">' . lqht_e($isMerge ? $L('merge') : $L('split')) . '</button></div>'
            . '<div class="img-out" id="' . $oid . '"></div>'
            . '</div>';
    } elseif ($w === 'filetool') {
        /* njk 514-531：fields 打 selected */
        $inner = '<div class="lqht-widget pdf-tool ft-tool lqht-ft" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($L('files')) . '</div>'
            . '<label class="btn-ghost file-btn" for="ftFiles">' . lqht_e($U('choose_file')) . '</label>'
            . '<input type="file" id="ftFiles" multiple class="img-file" style="display:none">'
            . lqht_fields_box($d, true)
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="ftGo">' . lqht_e($U('process_label')) . '</button></div>'
            . '<div class="img-out" id="ftOut"></div>'
            . '</div>';
    } elseif ($w === 'boximg' || $w === 'blurhash') {
        /* njk 533-570：fields 打 selected */
        $b = ($w === 'boximg') ? ['bx', 'bxFile'] : ['bh', 'bhFile'];
        $inner = '<div class="lqht-widget pdf-tool lqht-' . $b[0] . '" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($L('file')) . '</div>'
            . '<label class="btn-ghost file-btn" for="' . $b[1] . '">' . lqht_e($U('choose_file')) . '</label>'
            . '<input type="file" id="' . $b[1] . '" accept="image/*" class="img-file" style="display:none">'
            . lqht_fields_box($d, true)
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="' . $b[0] . 'Go">' . lqht_e($w === 'boximg' ? $U('process_label') : $U('generate')) . '</button></div>'
            . '<div class="img-out" id="' . $b[0] . 'Out"></div>'
            . '</div>';
    } elseif ($w === 'pdftool') {
        /* njk 571-591：fields 打 selected */
        $inner = '<div class="lqht-widget pdf-tool lqht-pdftool" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($L('file')) . '</div>'
            . '<label class="btn-ghost file-btn" for="pdfOne">' . lqht_e($U('choose_file')) . '</label>'
            . '<input type="file" id="pdfOne" accept="' . lqht_e((string)($d['accept'] ?? 'application/pdf')) . '"' . (!empty($d['multi']) ? ' multiple' : '') . ' class="img-file" style="display:none">'
            . lqht_fields_box($d, true)
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="pdfGo">' . lqht_e($U('process_label')) . '</button></div>'
            . '<div class="img-out" id="pdfOut"></div>'
            . '</div>';
    } elseif ($w === 'equation') {
        /* njk 592-600 */
        $inner = '<div class="lqht-widget text-tool eq-tool lqht-eq" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($L('input')) . '</div>'
            . '<input class="calc-field" id="eqInput" type="text" placeholder="' . lqht_e($L('ph')) . '" style="max-width:460px">'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="eqBtn">' . lqht_e($U('process_label')) . '</button>'
            . '<button type="button" class="btn-ghost" id="eqClear">' . lqht_e($U('clear')) . '</button></div>'
            . '<div class="calc-outs"><div class="calc-out"><span class="lab">' . lqht_e($L('solution')) . '</span><span class="val" id="eqOut">—</span></div></div>'
            . '<textarea class="tt-output" id="eqSteps" readonly style="margin-top:14px"></textarea>'
            . '</div>';
    } elseif ($w === 'barcode') {
        /* njk 636-651 */
        $inner = '<div class="lqht-widget calc-tool lqht-barcode" data-tool="' . lqht_e($slug) . '">'
            . lqht_fields_box($d)
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="bcBtn">' . lqht_e($U('process_label')) . '</button></div>'
            . '<div class="img-out" id="bcOut"></div>'
            . '</div>';
    } elseif (in_array($w, ['rotate', 'grayscale', 'watermark', 'border', 'corner', 'overlay', 'collage', 'favicon', 'ascii', 'exif', 'qrread', 'b64file'], true)) {
        /* njk 653-671：fields 打 selected */
        $acc = ($w === 'b64file') ? '*' : 'image/*';
        $inner = '<div class="lqht-widget img-tool lqht-fx" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($L('file')) . '</div>'
            . '<label class="btn-ghost file-btn" for="fxFile">' . lqht_e($U('choose_file')) . '</label>'
            . '<input type="file" id="fxFile" accept="' . $acc . '"' . ($w === 'collage' ? ' multiple' : '') . ' class="img-file" style="display:none">'
            . lqht_fields_box($d, true, ' style="margin-top:12px"')
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="fxBtn">' . lqht_e($U('process_label')) . '</button></div>'
            . '<div class="img-out" id="fxOut"></div>'
            . '</div>';
    } elseif ($w === 'pdfcompress') {
        /* njk 673-690 */
        $inner = '<div class="lqht-widget pdf-tool lqht-pdfc" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($U('input_label')) . '</div>'
            . '<label class="btn-ghost file-btn" for="pdfcFile">' . lqht_e($U('choose_file')) . '</label>'
            . '<input type="file" id="pdfcFile" accept="application/pdf" class="img-file" style="display:none">'
            . '<div class="calc-fields" style="margin-top:12px">';
        foreach ((array)($d['fields'] ?? []) as $f) {
            $f = (array)$f;
            $id = (string)($f['id'] ?? '');
            if ($id === '') { continue; }
            $inner .= '<div class="calc-field-wrap' . (!empty($f['half']) ? ' half' : '') . '"><label for="' . lqht_e($id) . '">' . lqht_e((string)($f['label'] ?? $id)) . '</label>'
                . '<input class="calc-field" id="' . lqht_e($id) . '" type="' . lqht_e((string)($f['type'] ?? 'text')) . '"'
                . (isset($f['dflt']) && (string)$f['dflt'] !== '' ? ' value="' . lqht_e((string)$f['dflt']) . '"' : '')
                . ' placeholder="' . lqht_e((string)($f['placeholder'] ?? '')) . '"></div>';
        }
        $inner .= '</div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="pdfcBtn">' . lqht_e($U('process_label')) . '</button></div>'
            . '<div class="img-out" id="pdfcOut"></div>'
            . '</div>';
    } elseif ($w === 'keycode') {
        /* njk 692-702 */
        $inner = '<div class="lqht-widget keycode-tool lqht-kc" data-tool="' . lqht_e($slug) . '">'
            . '<div class="key-box" id="kcBox" tabindex="0"><span class="kb-hint">' . lqht_e(lqht_ui('kc_hint')) . '</span><span class="kb-code"></span></div>'
            . '<div class="kc-row">'
            . '<div class="kc-item"><label>' . lqht_e(lqht_ui('kc_key')) . '</label><div class="kc-val" id="kcKey">&mdash;</div></div>'
            . '<div class="kc-item"><label>' . lqht_e(lqht_ui('kc_code')) . '</label><div class="kc-val" id="kcCode">&mdash;</div></div>'
            . '<div class="kc-item"><label>KeyCode</label><div class="kc-val" id="kcKeyCode">&mdash;</div></div>'
            . '</div>'
            . '<div class="w-label" style="margin-top:14px">' . lqht_e(lqht_ui('kc_history')) . '</div>'
            . '<div class="kc-history" id="kcHist"></div>'
            . '</div>';
    } elseif ($w === 'device') {
        $inner = '<div class="lqht-widget device-tool lqht-dev" data-tool="' . lqht_e($slug) . '"><div class="dev-grid" id="devGrid"></div></div>';
    } elseif ($w === 'camera') {
        /* njk 709-717 */
        $inner = '<div class="lqht-widget camera-tool lqht-cam" data-tool="' . lqht_e($slug) . '">'
            . '<video id="camVideo" autoplay muted playsinline></video>'
            . '<div class="tw-actions">'
            . '<button type="button" class="btn-copy" id="camStart">' . lqht_e(lqht_ui('cam_start')) . '</button>'
            . '<button type="button" class="btn-copy" id="camStop" disabled>' . lqht_e(lqht_ui('cam_stop')) . '</button>'
            . '<a class="btn-ghost" id="camDl" download="recording.webm" hidden>' . lqht_e(lqht_ui('cam_dl')) . '</a>'
            . '</div></div>';
    } elseif ($w === 'tts') {
        /* njk 719-728 */
        $inner = '<div class="lqht-widget text-tool tts-tool lqht-tts" data-tool="' . lqht_e($slug) . '">'
            . '<textarea class="tt-input" id="ttsText" rows="6" placeholder="' . lqht_e($U('input_placeholder')) . '"></textarea>'
            . '<div class="calc-fields" style="margin-top:12px">'
            . '<div class="calc-field-wrap half"><label>' . lqht_e(lqht_ui('tts_rate')) . '</label><input type="range" id="ttsRate" min="0.5" max="2" step="0.1" value="1"></div>'
            . '<div class="calc-field-wrap half"><label>' . lqht_e(lqht_ui('tts_pitch')) . '</label><input type="range" id="ttsPitch" min="0" max="2" step="0.1" value="1"></div>'
            . '</div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="ttsBtn">' . lqht_e(lqht_ui('tts_btn')) . '</button>'
            . '<button type="button" class="btn-ghost" id="ttsStop">' . lqht_e(lqht_ui('tts_stop')) . '</button></div>'
            . '<div class="rate-note" id="ttsNote"></div>'
            . '</div>';
    } elseif ($w === 'typing') {
        /* njk 730-736 */
        $inner = '<div class="lqht-widget text-tool typing-tool lqht-typing" data-tool="' . lqht_e($slug) . '">'
            . '<div class="tp-text" id="tpText"></div>'
            . '<textarea class="tt-input" id="tpInput" rows="3" disabled></textarea>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="tpStart">' . lqht_e(lqht_ui('tp_start')) . '</button></div>'
            . '<div class="typing-stats" id="tpStats"></div>'
            . '</div>';
    } elseif ($w === 'chronometer') {
        /* njk 738-748 */
        $inner = '<div class="lqht-widget chrono-tool lqht-chrono" data-tool="' . lqht_e($slug) . '">'
            . '<div class="chrono-display" id="chDisp">00:00.00</div>'
            . '<div class="tw-actions">'
            . '<button type="button" class="btn-copy" id="chStart">' . lqht_e(lqht_ui('ch_start')) . '</button>'
            . '<button type="button" class="btn-ghost" id="chLap">' . lqht_e(lqht_ui('ch_lap')) . '</button>'
            . '<button type="button" class="btn-ghost" id="chReset">' . lqht_e(lqht_ui('ch_reset')) . '</button>'
            . '</div>'
            . '<div class="w-label" style="margin-top:14px">' . lqht_e(lqht_ui('ch_laps')) . '</div>'
            . '<div class="chrono-laps" id="chLaps"></div>'
            . '</div>';
    } elseif ($w === 'cheat') {
        $inner = '<div class="lqht-widget cheat-tool lqht-cheat" data-tool="' . lqht_e($slug) . '"><div class="cheat-table" id="cheatTable"></div></div>';
    } elseif ($w === 'codepng') {
        /* njk 756-771 */
        $inner = '<div class="lqht-widget text-tool codepng-tool lqht-codepng" data-tool="' . lqht_e($slug) . '">'
            . lqht_fields_box($d)
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="cpRender">' . lqht_e($U('preview_label')) . '</button>'
            . '<button type="button" class="btn-ghost" id="cpDown">' . lqht_e($U('download')) . '</button></div>'
            . '<div class="img-out" id="cpOut"></div>'
            . '</div>';
    } elseif ($w === 'videocompress') {
        /* njk 773-784 */
        $inner = '<div class="lqht-widget text-tool videocompress-tool lqht-vc" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($L('file')) . '</div>'
            . '<label class="btn-ghost file-btn" for="vcFile">' . lqht_e($U('choose_file')) . '</label>'
            . '<input type="file" id="vcFile" accept="video/*" class="img-file" style="display:none">'
            . '<div class="calc-fields" style="margin-top:12px">'
            . '<div class="calc-field-wrap"><label>质量 / Quality</label><select class="calc-field" id="vcQuality"><option value="0.4">360p</option><option value="0.6">480p</option><option value="0.8" selected>720p</option><option value="1">1080p</option></select></div>'
            . '</div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="vcBtn">' . lqht_e($U('process_label')) . '</button></div>'
            . '<div class="img-out" id="vcOut"></div>'
            . '</div>';
    } elseif ($w === 'easing') {
        /* njk 786-795 */
        $inner = '<div class="lqht-widget easing-tool lqht-easing" data-tool="' . lqht_e($slug) . '">'
            . '<div class="calc-fields">'
            . '<div class="calc-field-wrap"><label>缓动 / Easing</label><select class="calc-field" id="ezSel">'
            . '<option value="linear">linear</option><option value="ease" selected>ease</option><option value="ease-in">ease-in</option>'
            . '<option value="ease-out">ease-out</option><option value="ease-in-out">ease-in-out</option>'
            . '<option value="0.34,1.56,0.64,1">back (overshoot)</option><option value="0.68,-0.55,0.27,1.55">ease-in-back</option>'
            . '<option value="0.25,0.46,0.45,0.94">sine-in-out</option><option value="0.47,0,0.745,0.715">quart-in</option>'
            . '</select></div></div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="ezCopy">' . lqht_e($U('copy_code')) . '</button></div>'
            . '<canvas id="ezCanvas" width="320" height="200" style="max-width:100%;border:1px solid #e0e8dc;border-radius:8px;margin-top:10px"></canvas>'
            . '</div>';
    } elseif ($w === 'plot') {
        /* njk 797-811 */
        $inner = '<div class="lqht-widget plot-tool lqht-plot" data-tool="' . lqht_e($slug) . '">'
            . lqht_fields_box($d)
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="plBtn">' . lqht_e($U('process_label')) . '</button></div>'
            . '<canvas id="plCanvas" width="520" height="320" style="max-width:100%;border:1px solid #e0e8dc;border-radius:8px;margin-top:10px"></canvas>'
            . '</div>';
    } elseif ($w === 'palette') {
        /* njk 813-821 */
        $inner = '<div class="lqht-widget img-tool palette-tool lqht-palette" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($L('file')) . '</div>'
            . '<label class="btn-ghost file-btn" for="palFile">' . lqht_e($U('choose_file')) . '</label>'
            . '<input type="file" id="palFile" accept="image/*" class="img-file" style="display:none">'
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="palBtn">' . lqht_e($U('process_label')) . '</button></div>'
            . '<div class="pal-swatches" id="palOut"></div>'
            . '</div>';
    } elseif ($w === 'batchimg') {
        /* njk 823-840：fields 不打 selected */
        $inner = '<div class="lqht-widget img-tool batchimg-tool lqht-batchimg" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($L('file')) . '</div>'
            . '<label class="btn-ghost file-btn" for="biFile">' . lqht_e($U('choose_file')) . '</label>'
            . '<input type="file" id="biFile" accept="image/*" multiple class="img-file" style="display:none">'
            . lqht_fields_box($d, false, ' style="margin-top:12px"')
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="biBtn">' . lqht_e($U('process_label')) . '</button></div>'
            . '<div class="img-out" id="biOut"></div>'
            . '</div>';
    } elseif ($w === 'emojifav') {
        /* njk 842-857：fields 不打 selected */
        $inner = '<div class="lqht-widget emoji-tool lqht-emojifav" data-tool="' . lqht_e($slug) . '">'
            . lqht_fields_box($d)
            . '<div class="tw-actions"><button type="button" class="btn-copy" id="efBtn">' . lqht_e($U('process_label')) . '</button>'
            . '<button type="button" class="btn-ghost" id="efDown">' . lqht_e($U('download')) . '</button></div>'
            . '<div class="img-out" id="efOut"></div>'
            . '</div>';
    } elseif ($w === 'icons') {
        /* njk 458-465：图标查找（数据 = 本站自己的 /static/icons-tabler.json） */
        $inner = '<div class="lqht-widget text-tool icons-tool lqht-icons" data-tool="' . lqht_e($slug) . '">'
            . '<div class="w-label">' . lqht_e($L('search')) . '</div>'
            . '<input class="tt-field" id="iconSearch" placeholder="' . lqht_e($L('ph')) . '" style="max-width:360px">'
            . '<div class="icon-count" id="iconCount"></div>'
            . '<div class="icon-gallery" id="iconGallery"></div>'
            . '</div>';
    } elseif ($w === 'ai') {
        /* ★ [t16 ai-widget] njk 233-258 对位：**钩子名同源**（aiBtn / aiClear / aiStatus / aiOut / aiLoginTip
           + 每个字段 id）⇒ 共享引擎 engine.js 的 `W === 'ai'` 分支零改动即可驱动；
           样式（.lqht-ai* / lqht.css）与提示文案为我方。参照**只有**"登录提示"、无"剩余额度"
           ⇒ 我方加 `#aiQuota`（本站登记）。
           ★ select **不打 `selected`**（与 njk 同支）；textarea 用 f.rows 缺省 5、整行铺满。
           ★ 静态页没有服务端登录态（产物可公共缓存）⇒ 本段脚本向 `/api/ai/quota` 探一次：
             login=1 ⇒ 隐藏 #aiLoginTip（引擎 aiRun() 的早退条件就是它可见）+ 置 data-logged=1；
             并把"剩余 N/limit"写进 #aiQuota；额度用尽时把引擎的按小时文案改成按日文案。 */
        $limIt = $aiLimit > 0 ? $aiLimit : 20;
        $fh = '';
        foreach ((array)($d['fields'] ?? []) as $f) {
            $f  = (array)$f;
            $id = (string)($f['id'] ?? '');
            if ($id === '') { continue; }
            $ty   = (string)($f['type'] ?? 'text');
            $df   = (string)($f['dflt'] ?? '');
            $full = ($ty === 'textarea') ? ' style="grid-column:1/-1"' : '';
            $fh .= '<div class="calc-field-wrap' . (!empty($f['half']) ? ' half' : '') . '"' . $full . '>'
                . '<label for="' . lqht_e($id) . '">' . lqht_e((string)($f['label'] ?? $id)) . '</label>';
            if ($ty === 'select') {
                $fh .= '<select class="calc-field" id="' . lqht_e($id) . '">';
                foreach ((array)($f['options'] ?? []) as $o) {
                    $v  = (string)($o['v'] ?? '');
                    $ol = (string)($o['label'] ?? $v);
                    $fh .= '<option value="' . lqht_e($v) . '">' . lqht_e($ol) . '</option>';
                }
                $fh .= '</select>';
            } elseif ($ty === 'textarea') {
                $fh .= '<textarea class="calc-field" id="' . lqht_e($id) . '" rows="' . (int)($f['rows'] ?? 5) . '"'
                    . ' placeholder="' . lqht_e((string)($f['placeholder'] ?? '')) . '">' . lqht_e($df) . '</textarea>';
            } else {
                $fh .= '<input class="calc-field" id="' . lqht_e($id) . '" type="' . lqht_e($ty) . '"'
                    . ($df !== '' ? ' value="' . lqht_e($df) . '"' : '')
                    . ' placeholder="' . lqht_e((string)($f['placeholder'] ?? '')) . '">';
            }
            $fh .= '</div>';
        }
        $aiJs = <<<JS
(function () {
  var LIM = {$limIt};
  var tip = document.getElementById('aiLoginTip');
  var q = document.getElementById('aiQuota');
  var out = document.getElementById('aiOut');
  function paint(j) {
    if (!j) { return; }
    var s;
    if (!j.login) { s = '未登录 · 不可用（每人每日 ' + j.limit + ' 次）'; }
    else if (!j.key_ready) { s = '已登录 · 今日剩余 ' + j.remain + '/' + j.limit + ' 次（AI 通道待配置）'; }
    else { s = '已登录 · 今日剩余 ' + j.remain + '/' + j.limit + ' 次'; }
    if (q) { q.textContent = s; }
    if (j.login) {
      window.HT_LOGGED = true;
      window.HT_AUTH_READY = true;
      document.body.setAttribute('data-logged', '1');
      if (tip) { tip.style.display = 'none'; }
    } else if (tip) { tip.style.display = ''; }
  }
  function fixOut() {
    if (!out) { return; }
    if ((out.textContent || '').indexOf('本小时') >= 0) {
      out.innerHTML = '<p class="ai-msg err">今日额度已用完（每人每日 ' + LIM + ' 次，次日 0 点重置）</p>';
    }
  }
  function pull() {
    fetch('/api/ai/quota', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) { paint(j); fixOut(); })
      .catch(function () {});
  }
  pull();
  if (out && window.MutationObserver) {
    var t = null;
    new MutationObserver(function () {
      if (t) { clearTimeout(t); }
      t = setTimeout(pull, 300);
    }).observe(out, { childList: true, subtree: true, characterData: true });
  }
})();
JS;
        $inner = '<div class="lqht-widget ai-tool lqht-ai" data-tool="' . lqht_e($slug) . '">'
            . '<div class="calc-fields">' . $fh . '</div>'
            . '<div class="tw-actions">'
            . '<button type="button" class="btn-copy" id="aiBtn">' . lqht_e($U('generate')) . '</button>'
            . '<button type="button" class="btn-ghost" id="aiClear" style="display:none">' . lqht_e($U('clear')) . '</button>'
            . '</div>'
            . '<div class="ai-status" id="aiStatus" style="display:none"></div>'
            . '<div class="ai-out" id="aiOut"></div>'
            . '<div class="ai-login-tip lqht-ai-tip" id="aiLoginTip">'
            . '<p>' . lqht_e('AI 工具需登录后使用（每人每日 ' . $limIt . ' 次）；未登录不可用。') . '</p>'
            . '<p class="lqht-ai-quota" id="aiQuota">读取额度中…</p>'
            . '<a class="btn-copy lqht-ai-login" href="/login?next=' . lqht_e(rawurlencode('/tool/' . $slug)) . '">登录</a>'
            . '</div>'
            . '<script>' . $aiJs . '</script>'
            . '</div>';
    } elseif ($w === 'probe' || $w === 'share') {
        /* ★ [t22 ext-widget] 第22棒新增两款 widget：probe（服务端探测：http2 / framework / bulk / friendlink）
           与 share（即现分享：上传/粘贴 → /s/<短码>）。渲染代码单独放 parts/lqht-ext.php。
           ★ 访客侧零外发：页面只 POST 本站 /api/probe · /api/share；外部访问全在服务端完成（见 lqprobe/inc.php 的 SSRF 守卫）。
           为什么这样加：承重件只多一个分支 ⇒ `` 不是 probe/share 的行**逐字节不变**（可由 t22_gen_rows.php --dry 断言）。 */
        require_once __DIR__ . '/lqht-ext.php';
        $inner = lqht_ext_body($w, $slug, $d, $ui);
    } elseif ($w === 'hotlist') {
        /* ★ [t24 hotlist] 第24棒新增：全网热榜聚合（百度/微博/知乎/B站/头条/掘金/V2EX/IT之家）。
           ★ 访客侧零外发：页面只请求本站 /api/topics（plugins/lqtopics/inc.php），上游访问全在服务端；
           渲染代码单独放 parts/lqht-topics.php ⇒ 不是 hotlist 的行逐字节不变（t24_render_all.php 914 规格对照）。 */
        require_once __DIR__ . '/lqht-topics.php';
        $inner = lqht_topics_body($slug, $d, $ui);
    } else {
        return null;
    }
    $ctx = ['detail' => $d, 'tool_ui' => $ui, 'slug' => $slug, 'lang' => 'zh'];
    $json = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { return null; }
    /* ★ [t22 json-escape]（承重修正 · 第22棒）HT_CTX 的 JSON **字符串值**里有两类字节会被页面
       渲染层的通用 pass 吃掉/改坏，必须在**构建期**就写成 JSON 转义（JSON.parse 后语义零变化）：
         ⒜ `{{<id>}}`（genTpl / aiPrompt 的字段占位符）—— themes/bl/parts/lq-skel.php:56 的
            "残留占位符一律抹掉"作用在**已注入正文的整页**上 ⇒ genTpl 被抹成空 ⇒ 引擎
            GEN_REG.template 无可替换字段（habit-tracker 输出 " -  - "）。
         ⒝ `<h2|<h3|<h4|<h5`（genTpl 是 HTML 模板时）—— core/Front.php:174 的"服务端目录"会给
            正文里每个 h2~h5 补 `id="sec-N"`，裸双引号插进 JSON 串 ⇒ 整段 JSON 解析失败
            （feedback-form-generator 页错误 `Unexpected identifier 'sec'`）。
       本段是第16棒 `[t16 ai-escape]`（当时只对 widget=ai 的字段 id 做 ⒜）的严格超集。 */
    $json = preg_replace_callback('/\{\{([A-Za-z0-9_]+)\}\}/', static function (array $m): string {
        return '\u007b\u007b' . $m[1] . '\u007d\u007d';
    }, $json);
    $json = str_replace(['<h2', '<h3', '<h4', '<h5'], ['\u003ch2', '\u003ch3', '\u003ch4', '\u003ch5'], $json);

    $vend = implode('', lqht_vendor_scripts($w, $d));

    return '<div class="space-y-6" data-lq-tool="' . lqht_e($slug) . '" data-lq-runtime="ht1">'
        . '<div><p class="mt-2 max-w-2xl text-muted-foreground">' . lqht_e($desc) . '</p></div>'
        . '<div class="lqht-hold">'
        . $inner
        . $vend
        . '<link rel="stylesheet" href="' . lqht_e($cssUrl) . '">'
        . '<script>window.HT_CTX=' . $json . ';</script>'
        . '<script src="' . lqht_e($jsUrl) . '" defer></script>'
        . '</div></div>';
}
