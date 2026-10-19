<?php
/** 首页（★ 2026-10-05「精仿」轮 —— 以 www.liulanqi.com 的**真实 HTML 骨架**为模板）
 * ===========================================================================
 * ★ 与上一轮的差别（站主判据："现在还不大像" ⇒ 本轮换做法）：
 *   上一轮 = "照着重画"（自己写 markup、复用它的 CSS）⇒ 形状像、细节不像。
 *   本轮   = **拿参照页的 HTML 当模板**：`lqwww/skel/home_*.html` 是从主域盘上
 *            `seo/index.html` **按字节切出来**的片段（生成器 `_lqpix/src/mkhome.py`），
 *            除动态点外，标签 / 类名 / 属性 / 嵌套层级**逐字节相同**。
 *            ⇒ 本文件里**没有一行"画 UI"的代码**，只有"取真数据 → 填占位符"。
 *
 * ★ 动态点（= 参照有数据、我们也有 ⇒ 用实例真数据填；没有的 ⇒ 空态 + 报告）：
 *   ① 品牌 / 摘要（hero h1 · hero p）      ② 书签分类卡（/bookmark 页的真数据）
 *   ③ 工具卡 16 张（真 slug · 真标题 · 真分类）④ 分类 chips
 *   ⑤ 学堂精选 6 篇（真 slug · 真标题 · 真摘要）⑥ 精选壁纸 6 张
 * ★ 不做的事：不编数据；参照那 6 张壁纸图**实例盘上没有图片文件** ⇒ 保留参照的空态盒子
 *   （`div.overflow-hidden.rounded-lg.border.bg-muted > div.aspect-[16/10].w-full`）并如实报备。
 *
 * 由 index.php:home 分支在 home.landing=1 时渲染（View::render('landing', …)），
 * 外壳（header/footer）由 views/layout.php → parts/lq-www.php 用同一套骨架渲染。
 */
require_once __DIR__ . '/../parts/lq-www.php';
require_once __DIR__ . '/../parts/lq-skel.php';
/* ★ 分类表是**单一真源**：站点自身已从 themes/yecha/parts/cat-def.php 载入时就不再重复载入
   （两份文件路径不同 ⇒ require_once 不会去重 ⇒ 曾经直接 Fatal「Cannot redeclare yc_cat_table()」）。*/
if (!function_exists('yc_cat_table')) { require_once __DIR__ . '/../parts/cat-def.php'; }

$lp = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? '/' . Lang::short() : '';
$q  = (string)($_GET['q'] ?? '');
$brand = site_label();

/* ---------------------------------------------------------------- 真数据：计数 */
$nTool = (int)Db::one(
    'SELECT COUNT(*) FROM ' . Db::t('contents')
    . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND slug LIKE 'tool\\_\\_%'"
);

/* ---------------------------------------------------------------- 真数据：工具 16 张
 * 参照首页那 16 张卡的 slug 序列（前 15 个是工具，第 16 个是 Markdown 云笔记页）。
 * 全部在实例 DB 里存在（逐个实测 200）⇒ 用真行的标题 + 真分类表的分类名与图标。 */
$pickSlugs = ['qrcode','unit','calculator','timestamp','base64','json','text','password','hash',
              'recorder','wheel','wordcount','teatimer','simptrad','moonphase'];
$catName = [];
foreach (yc_cat_table() as $g) { $catName[(string)$g[0]] = (string)$g[1]; }
/* ★ 2026-10-14 第14棒：首页那 15 张工具卡的分类名 = **逐字节取参照首页同位置卡的分类名**
   （参照自己的分类词表与我们 cat-def 的中文名不同 ⇒ 原先 12 张卡的分类名与参照不同）。 */
$refChip = ['qrcode'=>'开发者工具','unit'=>'单位与编码','calculator'=>'效率办公','timestamp'=>'单位与编码',
            'base64'=>'单位与编码','json'=>'开发者工具','text'=>'文本处理','password'=>'安全与随机',
            'hash'=>'开发者工具','recorder'=>'音视频工具','wheel'=>'生活趣味','wordcount'=>'文本处理',
            'teatimer'=>'生活趣味','simptrad'=>'文本处理','moonphase'=>'生活趣味'];
$bySlug = [];
if ($pickSlugs !== []) {
    $ph = implode(',', array_fill(0, count($pickSlugs), '?'));
    $rows = Db::rows(
        'SELECT slug, title, description FROM ' . Db::t('contents')
        . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND slug IN ($ph)",
        array_map(static fn(string $s): string => 'tool__' . $s, $pickSlugs)
    );
    foreach ($rows as $r) {
        $bySlug[(string)preg_replace('/^tool__/', '', (string)$r['slug'])] = $r;
    }
}
$toolCards = [];
foreach ($pickSlugs as $s) {
    if (!isset($bySlug[$s])) { continue; }
    $r   = $bySlug[$s];
    $ck  = yc_tool_cat($s, (string)$r['description']);
    $toolCards[] = ['slug' => $s, 'name' => lqwww_tool_title('tool__' . $s, (string)$r['title']),
                    'cat' => (string)($refChip[$s] ?? $catName[$ck] ?? $ck), 'ck' => $ck];
}
/* 第 16 张 = 云笔记页（参照同一位置也是它，不是工具） */
$notesRow = Db::row('SELECT slug, title FROM ' . Db::t('contents')
    . " WHERE slug='notes' AND status='publish' AND deleted_at IS NULL LIMIT 1");
if ($notesRow) {
    $toolCards[] = ['slug' => 'notes', 'name' => lqwww_tool_title('notes', (string)$notesRow['title']),
                    'cat' => '效率办公', 'ck' => 'doc', 'page' => true];
}
$toolItems = '';
foreach ($toolCards as $c) {
    $face = lqskel_tool_face((string)$c['ck']);
    $href = !empty($c['page']) ? ($lp . '/notes') : ($lp . '/tool/' . $c['slug']);
    $toolItems .= lqskel_fill(lqskel('item_tool'), [
        'href'       => e($href),
        'icon_color' => e($face[1]),
        'icon'       => lqwww_ico($face[0], 'h-4 w-4'),
        'name'       => e((string)$c['name']),
        'cat'        => e((string)$c['cat']),
    ], 'tool_card');
}
/* 分类 chips = 实例分类表里的「description 分类词」（真值），与参照那 6 枚同源 */
/* ★ 2026-10-14 第14棒：改为逐字节取参照首页那 6 枚（原按分类表前 6 个取 ⇒ 6 枚字面与顺序全不同） */
$chipWords = ['效率办公', '单位与编码', '开发者工具', '文本处理', '图片处理', '音视频工具'];
$toolChips = '';
foreach ($chipWords as $w) {
    $toolChips .= '<span class="rounded-full border px-3 py-1 text-xs text-muted-foreground">' . e((string)$w) . '</span>';
}

/* ---------------------------------------------------------------- 真数据：学堂精选 6 篇 */
$pickTips = ['browser-rec','download-browser','default-browser','newtab-nav','shortcuts','clear-cache'];
$tips = Db::rows(
    'SELECT slug, title, description, excerpt FROM ' . Db::t('contents')
    . ' WHERE type=\'post\' AND status=\'publish\' AND deleted_at IS NULL AND slug IN ('
    . implode(',', array_fill(0, count($pickTips), '?')) . ')',
    array_map(static fn(string $s): string => 'tips__' . $s, $pickTips)
);
$tipMap = [];
foreach ($tips as $r) { $tipMap[(string)preg_replace('/^tips__/', '', (string)$r['slug'])] = $r; }
$tipItems = '';
foreach ($pickTips as $s) {
    if (!isset($tipMap[$s])) { continue; }
    $r = $tipMap[$s];
    $tipItems .= lqskel_fill(lqskel('item_tip'), [
        'href'  => e($lp . '/tips/' . $s),
        'title' => e(lqwww_tool_title('tips__' . $s, (string)$r['title'])),
        'desc'  => e(lqwww_lead($r, 120)),
    ], 'tip_card');
}

/* ---------------------------------------------------------------- 真数据：精选壁纸 6 张
 * 实例 DB 有 6 个壁纸分类（gallery__*，标题带「（48 张）」⇒ 真总数 288）；
 * 但实例盘上**没有图片文件**（/gallery 实测 <img> = 0）⇒ 走参照的空态盒子，不编图。 */
$gal = Db::rows(
    'SELECT slug, title, content FROM ' . Db::t('contents')
    . " WHERE type='post' AND status='publish' AND deleted_at IS NULL AND slug LIKE 'gallery\\_\\_%'"
    . ' ORDER BY slug ASC'
);
$nWall = 0;
$galItems = '';
foreach ($gal as $r) {
    $name = lqwww_tool_title((string)$r['slug'], (string)$r['title']);
    if (preg_match('/（(\d+)\s*张）/u', $name, $mm)) { $nWall += (int)$mm[1]; $name = trim((string)preg_replace('/（\d+\s*张）/u', '', $name)); }
    $slug = (string)preg_replace('/^gallery__/', '', (string)$r['slug']);
    /* ★ 2026-10-06：图片位以前硬编码空盒 ⇒ 现取内容行里的真 <img>
       （本库自带的 dl.liulanqi.com 1080p；标记 = 参照自己的 aspect-[16/10] + loading=lazy + alt）。 */
    $galPic = '';
    if (preg_match('#<img\b[^>]*>#i', (string)($r['content'] ?? ''), $gm)) { $galPic = $gm[0]; }
    if ($galPic === '') { $galPic = '<div class="aspect-[16/10] w-full"></div>'; }
    $galItems .= lqskel_fill(lqskel('item_gal'), [
        'href'    => e($lp . '/gallery/' . $slug),
        'img'     => $galPic,
        'caption' => e($name),
    ], 'gal_card');
}

/* ---------------------------------------------------------------- 真数据：书签 2 张分类卡 */
$bm = lqskel_bookmarks(2, 8);
$bmCards = '';
foreach ($bm['cats'] as $c) {
    $sites = '';
    foreach ($c['sites'] as $s) {
        $sites .= lqskel_fill(lqskel('item_bm_site'), [
            'href' => e((string)$s['url']),
            'name' => e((string)$s['name']),
            'desc' => e((string)$s['desc']),
        ], 'bm_site');
    }
    $bmCards .= lqskel_fill(lqskel('item_bm_cat'), [
        'cat'   => e((string)$c['name']),
        'sites' => $sites,
    ], 'bm_cat');
}

/* ---------------------------------------------------------------- hero 文案
 * h1 = 实例真品牌（`site.name` 选项，站主既有决定）+ 参照的同一句式。
 * p  = **参照的静态模板块逐字节照抄**：句子里每个断言对实例都成立（151 条真书签 / 137 个工具
 *      ⇒ "100+" 成立 / /plugins 真页 / 免登录本地优先），且它是"营销文案"不是数据字段。
 *      ⇒ 这里不做数字替换（换 "137 个" 会把这行挤到不一样的换行点，纯亏相似度）。
 *      若站主要"数字必须是 137"，改这一行即可（report §⑤-2 已列）。 */
$heroH1 = $brand . ' · 去哪儿，更容易';
$heroP  = '常用网站书签导航，让你知道「去哪儿」；100+ 免费在线工具与浏览器扩展， 让上网「更容易」。全部免费、本地优先、注重隐私。';

$base = [
    'lp' => $lp, 'q' => $q, 'search' => $lp . '/search', 'home_href' => ($lp === '' ? '/' : $lp . '/'),
];
?>
<div class="space-y-12">
<?php
echo lqskel_fill(lqskel('home_hero'), $base + [
    'hero_badge_href' => e($lp . '/share'),
    'hero_badge_text' => e('笔记分享：把一篇笔记变成一条链接'),
    'hero_h1'         => e($heroH1),
    'hero_p'          => e($heroP),
    'search_action'   => e($lp . '/search'),
    'search_btn'      => e(__('sr.btn')),
    'q'               => e($q),
], 'hero');

echo lqskel_fill(lqskel('home_bookmarks'), $base + [
    'bm_meta'  => e($bm['ncat'] . ' 个分类 · ' . $bm['nsite'] . ' 个网站'),
    'bm_cards' => $bmCards,
], 'bookmarks');

/* Markdown 推广区：文案是参照的**静态模板块**（非动态点）⇒ 逐字节照抄 */
echo lqskel('home_markdown');

echo lqskel_fill(lqskel('home_tools'), $base + [
    'tool_items' => $toolItems,
    'tool_chips' => $toolChips,
], 'tools');

/* 站点板块：6 张卡指向的 6 条路径实例全都在（/learn /mobile /download /plugins /tool /about）
   ⇒ 逐字节照抄（连文案）*/
echo lqskel('home_sites');

echo lqskel_fill(lqskel('home_tips'), $base + ['tip_items' => $tipItems], 'tips');

/* ★ 2026-10-11 站主裁定①：/gallery（灵感画廊 · 4K 壁纸）栏目整体删除
   ⇒ 首页「精选壁纸」板块一并摘除（该板块是首页【唯一】指向 /gallery 与 /gallery/<cat> 的来源）。
   $galItems / $nWall 仍由上方的真数据查询产出，无副作用。 */
?>
</div>
