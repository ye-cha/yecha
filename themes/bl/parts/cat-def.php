<?php
/** 浏览工坊【工具分类：单一真源】· 2026-09-23
 *  ★ 为什么单独成文件：分类归属只能有**一处定义**。
 *    首页（parts/home-calm.php）与分类筛选页（/tool/c/{key}）都 require 本文件 ⇒
 *    "首页卡上写 42、筛出来 40" 这种必然漂移就不会发生。
 *  ★ ★ 为什么用**函数返回**而不是普通变量、也不是 define() 常量（两次实测踩过）：
 *    · 用普通变量 `$CAT_GROUPS = [...]`：谁 require 它决定了它在哪个作用域 ——
 *      在函数体里 require ⇒ 变量成局部，而被函数看到的全局那份是空的 ⇒ 两表全空 ⇒ 分类页 404。
 *    · 用 define('X', [...] )：把数组字面量内联进 define() 时，数组里最后那个 `];` 会多出分号
 *      ⇒ **ParseError**（实测：cat-def.php:32 unexpected token ";"）——
 *      而且 php -l 在"拼补丁那一刻"不覆盖生成物，只有真跑才炸。
 *    ⇒ 正解：函数返回数组（函数名全局可见，值每次构造，语义与原来的变量完全一致）。
 *  ★ 本文件只定义数据与纯函数，不含输出、不连数据库。
 *  回滚 = 删本文件 + 把两张表放回 home-calm.php（见 .bak 备份）。
 */

/* 分类表：[键, 中文名, 线性图标, liulanqi description 分类词, 对应 herontool 的 cat]
   ★ 卡片数量只由这张表（非空组）决定 ⇒ 与工具总数无关（1,700 个也一样） */
function yc_cat_table(): array
{
    return [
    // [键, 中文名, 线性图标, 覆盖的 liulanqi description 分类词, 对应 herontool 的 cat]
    ['text',    '文本处理',   'text',    ['文本处理', '效率办公'],           ['text']],
    ['dev',     '开发编程',   'code',    ['开发者工具'],                      ['dev']],
    ['convert', '转换与编码', 'shuffle', ['Markdown 转换', '单位与编码'],     []],
    ['doc',     '文档与办公', 'file',    ['效率办公'],                        ['life']],
    ['media',   '图片媒体',   'image',   ['图片处理'],                        ['media']],
    ['av',      '音视频',     'play',    ['音视频工具'],                      []],
    ['calc',    '计算与换算', 'calc',    ['单位与编码'],                      ['math']],
    ['date',    '日期与时间', 'clock',   ['效率办公'],                        ['life']],
    ['design',  '设计资源',   'palette', [],                                  ['design']],
    ['ai',      'AI 工具',    'spark',   [],                                  ['ai']],
    ['seo',     'SEO 与营销', 'tag',     [],                                  ['seo']],
    ['life',    '生活与趣味', 'heart',   ['生活趣味'],                        ['life']],
    ['sec',     '安全与随机', 'shield',  ['安全与随机'],                      []],
];;
}

/* liulanqi 工具 slug → 统一分类键（显式映射；未列出者按 description 的「分类」兜底） */
function yc_lq_cat_map(): array
{
    return [
    'text'=>'text','textdiff'=>'text','wordcount'=>'text','case'=>'text','regex'=>'text','simptrad'=>'text',
    'base64'=>'convert','hash'=>'sec','hex'=>'convert','binary'=>'convert','baseconv'=>'convert',
    'unicode'=>'convert','htmlentity'=>'convert','morse'=>'convert','rot13'=>'convert','railfence'=>'sec',
    'vigenere'=>'sec','caesar'=>'sec','numcn'=>'convert','numrmb'=>'convert','roman'=>'convert',
    'punycode'=>'convert','url'=>'convert','md2html'=>'convert','md2pdf'=>'convert','md2word'=>'convert',
    'md-todocx'=>'convert','md2image'=>'convert','md-convert'=>'convert','md-format'=>'convert',
    'md-lint'=>'convert','md-toc'=>'convert','md-badge'=>'convert','md-diagram'=>'convert',
    'md-mindmap'=>'convert','md-slide'=>'convert','md-wechat'=>'convert','md-github-cards'=>'convert',
    'md-fromoffice'=>'convert','markdown'=>'doc','json'=>'dev','yaml'=>'dev','xml'=>'dev','csv'=>'dev',
    'mock'=>'dev','uuid'=>'dev','jwt'=>'dev','totp'=>'sec','snowflake'=>'dev','cidr'=>'dev','ip'=>'dev',
    'ua'=>'dev','httpcode'=>'dev','chmod'=>'dev','cron'=>'dev','gitignore'=>'dev','extractlinks'=>'dev',
    'luhn'=>'dev','screenrec'=>'av','browserinfo'=>'dev','qrcode'=>'media','barcode'=>'media',
    'favicon'=>'media','faviconx'=>'media','imgcompress'=>'media','imgresize'=>'media','imggray'=>'media',
    'imginvert'=>'media','imgformat'=>'media','imgwatermark'=>'media','imgpick'=>'media','imggrid'=>'media',
    'imgmosaic'=>'media','imgbase64'=>'media','imgradius'=>'media','imginfo'=>'media','avatar'=>'media',
    'svg2png'=>'media','paint'=>'media','plate'=>'life','recorder'=>'av','stt'=>'av','tts'=>'av',
    'audioconv'=>'av','audiojoin'=>'av','audiospeed'=>'av','audiogain'=>'av','audiotrim'=>'av',
    'videotrim'=>'av','bmi'=>'calc','tax'=>'calc','mortgage'=>'calc','investment'=>'calc',
    'installment'=>'calc','discount'=>'calc','compound'=>'calc','unit'=>'calc','size'=>'calc',
    'area'=>'calc','coin'=>'calc','age'=>'date','agediff'=>'date','timestamp'=>'date','clock'=>'date',
    'worldclock'=>'date','calendar'=>'date','countdown'=>'date','pomodoro'=>'date','teatimer'=>'date',
    'moonphase'=>'date','password'=>'sec','random'=>'sec','wheel'=>'sec','wheel-spinner'=>'life',
    'zodiac'=>'life','constellation'=>'life','blood'=>'life','tianti'=>'life','dynasty'=>'life',
    'relatives'=>'life','capital'=>'life','countrycode'=>'life','whattoeat'=>'life','pregnancy'=>'life',
    'petage'=>'life','period'=>'life','lottery'=>'life','emoji'=>'life','idcard'=>'life','id-photo'=>'media',
    'todo'=>'doc','notepad'=>'doc','group'=>'life','lorem'=>'text','persona-generator'=>'life',
    'image-collage'=>'media','affine'=>'sec','color'=>'design','palette'=>'design','contrast'=>'design',
    'gradient'=>'design','width'=>'calc','pixel'=>'media',
    /* [t24 cat] 第24棒：新落工具 hot-topics 归入「生活与趣味」（依据 = 参照规格 cat=life）。
       ★ 本文件是 index.php 顶层 require 的**运行期生效**那份；themes/bl/parts/cat-def.php 是同一份副本
         （两者改动前逐字节相同，本棒同时补、并断言 sha 相等）。★ 为什么不改 index.php 的路径：
         那是站点级口径（1172 页共用），不为一个工具破口径。 */
    'hot-topics'=>'life',
];;
}

/**
 * 工具行 → 分类键（★ 与首页、筛选页共用的**同一套**判定）
 * 判定顺序：① 显式映射  ② description 里的「分类」词  ③ 兜底 'text'（不丢弃）
 */
function yc_tool_cat(string $slug, string $description): string
{
    static $descToKey = null;
    if ($descToKey === null) {
        $descToKey = [];
        foreach (yc_cat_table() as $g) { foreach ($g[3] as $w) { $descToKey[$w] = $g[0]; } }
    }
    $map = yc_lq_cat_map();
    if (isset($map[$slug])) { return (string)$map[$slug]; }
    if (preg_match('/liulanqi「([^」]+)」/u', $description, $m) && isset($descToKey[$m[1]])) {
        return (string)$descToKey[$m[1]];
    }
    return 'text';
}

/**
 * 分类键 → 该类的**全部**工具 · 供首页计数与分类筛选页切片（★ 同一份数据 ⇒ 计数必然相等）
 * ★ static 缓存：一次请求只查一次库（首页原来会因为"先算总数再算分类"查两遍）。
 * 返回 [分类键 => [ ['slug'=>…, 'title'=>…], … ]]
 */
function yc_tools_by_cat(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    /* ★ 不预筛 description（曾经用 instr(description,'liulanqi「')>0 省一点读列）——
       实测那样会**漏掉 2 个工具**（description 里没有这串标记的），
       而"未匹配的工具也不许少"是硬要求 ⇒ 全读、逐条归类，兜底落 'text'。
       代价：1,700 条时这一句读约 200KB 三列数据（可接受；扩容测试证明结构不随工具数变）。 */
    $rows = Db::rows('SELECT slug, title, description FROM ' . Db::t('contents')
        . " WHERE status='publish' AND deleted_at IS NULL AND slug LIKE 'tool\\_\\_%'"
        . " ORDER BY slug ASC");
    $out = [];
    foreach ($rows as $r) {
        $slug  = preg_replace('/^tool__/', '', (string)$r['slug']);
        $label = trim((string)$r['title']);
        $label = preg_replace('/\s*[-–—·|]\s*liulanqi.*$/u', '', $label);
        $label = preg_replace('/\s*[-–—·|]\s*浏览工坊.*$/u', '', $label);
        if ($label === '') { $label = (string)$r['title']; }
        $out[yc_tool_cat($slug, (string)$r['description'])][] = ['slug' => $slug, 'title' => $label];
    }
    $cache = $out;
    return $cache;
}
