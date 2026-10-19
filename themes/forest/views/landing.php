<?php
/** 森林茶 Forest theme · 首页门户 V6（白底浅绿框架）
 *  hero（深绿焦点，大字克制）→ 栏目卡（占满整行）→ 品牌引语
 *  → 最新文章（两列均衡网格）→ CTA。全 CSS 无图，明暗兼容。
 */
$GLOBALS['YC_HOME_HERO'] = true;
$name = site_label();
$sub  = trim((string)get_option('site.subtitle', ''));
$bio  = trim((string)get_option('blogger.bio', ''));
/* 双语：当前语言 / 链接前缀（en 时 /en/） */
$curLangShort = Lang::short();
$L = ($curLangShort === 'en');
$def = Poly::defaultLang();
$lp = (Poly::on() && $curLangShort !== $def) ? '/' . $curLangShort : '';
$U  = $L ? $lp : '';
$blogNick = (string)get_option('blogger.nick', site_name());
$blogNick = ($blogNick !== '' ? $blogNick : site_label());
if ($L) { $blogNick = 'Lao Sen'; }
$blogAv   = mb_substr($blogNick, 0, 1);
$str = function (string $zh, string $en): string { return $GLOBALS['__LND_EN'] ? $en : $zh; };
$GLOBALS['__LND_EN'] = $L;
/* 栏目 */
$secCats = [];
foreach (Db::rows('SELECT * FROM ' . Db::t('metas') . " WHERE type='category' AND count>0 ORDER BY `order` ASC, id ASC LIMIT 12") as $c) {
    $secCats[] = $c;
}
$flavors  = Db::row("SELECT slug, title FROM " . Db::t('contents') . " WHERE type='page' AND slug='flavors' AND status='publish' AND deleted_at IS NULL LIMIT 1");
$laosenPg = Db::row("SELECT slug, title FROM " . Db::t('contents') . " WHERE type='page' AND slug='laosen' AND status='publish' AND deleted_at IS NULL LIMIT 1");
if ($L) {
    $enTitle = static function (string $slug) {
        $id = Db::one('SELECT id FROM ' . Db::t('contents') . " WHERE type='page' AND slug=? AND status='publish' LIMIT 1", [$slug]);
        if (!$id) { return ''; }
        return (string)Db::one('SELECT title FROM ' . Db::t('poly_contents') . " WHERE group_id=? AND lang='en' AND status='publish' LIMIT 1", [(int)$id]);
    };
    if ($flavors)  { $et = $enTitle('flavors');  if ($et !== '') { $flavors['title']  = $et; } }
    if ($laosenPg) { $et = $enTitle('laosen');   if ($et !== '') { $laosenPg['title'] = $et; } }
}
/* 统计 */
$totPost = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . " WHERE type='post' AND status='publish' AND deleted_at IS NULL");
$totCat  = count($secCats);
$totTag  = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('metas') . " WHERE type='tag' AND count>0");
/* 最新文章 6 篇（与栏目页同一套 post-item 结构，保证"正常文章列表"观感） */
$latest = Db::rows(
    'SELECT c.id, c.slug, c.title, c.excerpt, COALESCE(c.published_at, c.created_at) AS dt FROM ' . Db::t('contents') . ' c '
    . "WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL "
    . 'ORDER BY COALESCE(c.published_at, c.created_at) DESC, c.id DESC LIMIT 6'
);
$latestCats = [];
$latestEn = [];
if ($latest) {
    $ids = array_column($latest, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    if ($L) {
        foreach (Db::rows('SELECT group_id, title, excerpt FROM ' . Db::t('poly_contents') . " WHERE lang='en' AND status='publish' AND group_id IN (" . $in . ')', array_map('intval', $ids)) as $er) {
            $latestEn[(int)$er['group_id']] = $er;
        }
    }
    foreach (Db::rows('SELECT r.content_id, m.slug, m.name, m.name_en FROM ' . Db::t('relationships') . ' r JOIN ' . Db::t('metas') . ' m ON m.id=r.meta_id AND m.type=\'category\' WHERE r.content_id IN (' . $in . ')', array_map('intval', $ids)) as $rc) {
        $latestCats[(int)$rc['content_id']] = $rc;
    }
}
$postItems = '';
$i0 = 0;
foreach ($latest as $p) {
    $i0++;
    $ct   = $latestCats[(int)$p['id']] ?? null;
    $en   = $latestEn[(int)$p['id']] ?? null;
    $purl = $U . '/' . rawurlencode((string)$p['slug']) . '.html';
    $ttl  = $L && $en ? (string)$en['title'] : (string)$p['title'];
    $ex   = $L && $en && trim((string)$en['excerpt']) !== '' ? trim((string)$en['excerpt']) : trim((string)($p['excerpt'] ?? ''));
    $catName = $ct ? ($L && trim((string)($ct['name_en'] ?? '')) !== '' ? (string)$ct['name_en'] : (string)$ct['name']) : '';
    $catUrl  = $ct ? ($U . '/category/' . rawurlencode((string)$ct['slug']) . '/') : '';
    $postItems .= '<article class="post-item">'
        . '<div class="post-top">'
        . '<span class="no">' . str_pad((string)$i0, 2, '0', STR_PAD_LEFT) . '</span>'
        . ($catUrl !== '' ? '<a class="chip" href="' . e($catUrl) . '">' . e($catName) . '</a>' : '')
        . '</div>'
        . '<h2><a href="' . e($purl) . '">' . e($ttl) . '</a></h2>'
        . ($ex !== '' ? '<p class="post-excerpt">' . e($ex) . '</p>' : '')
        . '<div class="post-meta">'
        . '<span class="avatar">' . e($blogAv) . '</span>'
        . '<span>' . e($blogNick) . '</span><span class="dot">·</span>'
        . '<span>' . e(lang_date(strtotime((string)$p['dt']))) . '</span>'
        . '<a class="read-more" href="' . e($purl) . '">' . e($L ? 'Read' : __('list.read')) . '</a>'
        . '</div></article>';
}
if ($postItems === '') { $postItems = '<p class="muted">' . e($L ? 'Fresh articles are on the way — explore the columns first.' : '新文章正在路上——先逛逛栏目吧。') . '</p>'; }
/* 栏目编号 */
$noMap = [];
$i = 1;
foreach ($secCats as $c) { $noMap[(string)$c['slug']] = str_pad((string)$i++, 2, '0', STR_PAD_LEFT); }
$hintMap = [
    'laosen'    => '真假、产区、工艺、冲泡，老森一篇篇讲透。',
    'baike'     => '森林茶是什么、怎么鉴别，查起来最快。',
    'letters'   => '跑山的天气、茶农的一句话、林子里的事。',
    'senlincha' => '从定义到避坑的第一手入门资料。',
    'yiwucha'   => '从产地、工艺到年份陈化的专题。',
];
$hintMapEn = [
    'laosen'    => 'Fraud checks, regions, craft, brewing — explained by Lao Sen one article at a time.',
    'baike'     => 'What forest tea is and how to judge it — quick answers.',
    'letters'   => 'Weather on the hills, a tea farmer\'s words, things in the forest.',
    'senlincha' => 'First-hand basics from definitions to buying pitfalls.',
    'yiwucha'   => 'From Yiwu origins and craft to aged character.',
];
$goSee = $L ? 'Explore' : '去看看';
$unitPosts = $L ? ' posts' : ' 篇';
$icMap = [
    'laosen'    => '<svg viewBox="0 0 24 24" fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"></path><path d="M12 15c-4-1.6-7-4.6-8-8 3.6 1 6.6 3.4 8 7z"></path><path d="M12 15c4-1.6 7-4.6 8-8-3.6 1-6.6 3.4-8 7z"></path><path d="M5 20h14"></path></svg>',
    'letters'   => '<svg viewBox="0 0 24 24" fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m3 7 9 6 9-6"></path></svg>',
    'baike'     => '<svg viewBox="0 0 24 24" fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>',
    'senlincha' => '<svg viewBox="0 0 24 24" fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M7 20h10"></path><path d="M8 20c0-8 8-8 8-16-5 1-8 5-8 8"></path><path d="M16 20c0-6-5-7-5-12 3 1 5 4 5 7"></path></svg>',
    'yiwucha'   => '<svg viewBox="0 0 24 24" fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3v4l6 7v7h2v-7l6-7V3"></path><path d="M5 3h14"></path><path d="M9 9h6"></path></svg>',
];
$catCards = '';
foreach ($secCats as $c):
    $slug = (string)$c['slug'];
    $cName = $L && trim((string)($c['name_en'] ?? '')) !== '' ? (string)$c['name_en'] : (string)($c['name'] ?? '');
    $catCards .= '<a class="fc" href="' . e($U . '/category/' . $slug . '/') . '">'
        . '<span class="fc-no">' . e($noMap[$slug] ?? '··') . '</span>'
        . '<span class="fc-ic">' . ($icMap[$slug] ?? '') . '</span>'
        . '<b>' . e($cName) . '</b>'
        . '<p>' . e(Str::excerpt($L ? ($hintMapEn[$slug] ?? '') : ($hintMap[$slug] ?? ''), 60)) . '</p>'
        . '<span class="fc-go">' . e($goSee) . '<span>' . (int)$c['count'] . $unitPosts . '</span></span></a>';
endforeach;
if ($flavors) {
    $fcTitle = $flavors['title'] ?? '森林的味道';
    $catCards .= '<a class="fc" href="' . e($U . '/' . rawurlencode((string)$flavors['slug']) . '.html') . '">'
        . '<span class="fc-no">' . e(str_pad((string)(count($secCats) + 1), 2, '0', STR_PAD_LEFT)) . '</span>'
        . '<span class="fc-ic"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14h16"></path><path d="M5 14a7 7 0 0 0 14 0"></path><path d="M9 6c0 1 0 1.5-1 1.5S7 7 7 6"></path><path d="M12 5c0 1 0 1.5-1 1.5s-1-.5-1-1.5"></path><path d="M15 6c0 1 0 1.5-1 1.5s-1-.5-1-1.5"></path></svg></span>'
        . '<b>' . e($fcTitle) . '</b>'
        . '<p>' . e($L ? 'Five directions of forest tea — firm, supple, distant, deep, warm. Introductions only, not for sale yet.' : '刚、柔、远、厚、暖——五款森林茶的方向。') . '</p>'
        . '<span class="fc-go">' . e($goSee) . '</span></a>';
}
?>
<style>
/* ============ 首页 V6：白底浅绿框架 ============ */
.lnd-home{display:flex;flex-direction:column;gap:clamp(26px,3.4vw,44px)}
/* --- Hero --- */
.fhero{position:relative;overflow:hidden;border-radius:clamp(14px,1.8vw,22px);
  background:linear-gradient(150deg,#17352A 0%,#1E4636 45%,#2A5A45 100%);
  color:#F4F1E3;box-shadow:0 18px 48px rgba(20,52,40,.18)}
.fhero::before{content:"";position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(560px 300px at 88% -12%,rgba(226,240,214,.14),transparent 58%),
  radial-gradient(420px 260px at 4% 120%,rgba(226,240,214,.08),transparent 60%)}
.fhero-watermark{position:absolute;right:clamp(-46px,-2vw,6px);bottom:-54px;width:clamp(200px,24vw,320px);
  opacity:.08;pointer-events:none}
.fh-in{position:relative;display:grid;grid-template-columns:1.25fr .85fr;gap:clamp(20px,3vw,44px);
  align-items:center;padding:clamp(30px,4.6vw,58px) clamp(22px,4vw,52px)}
.fh-main{min-width:0}
.fh-eyebrow{display:inline-block;font-size:12px;letter-spacing:.38em;color:#B9CDA6;text-transform:uppercase;
  font-weight:600;margin-bottom:clamp(12px,1.8vw,20px)}
.fh-title{font-family:var(--font-serif,"Noto Serif SC","Source Han Serif SC",serif);
  font-size:clamp(28px,3.9vw,46px);line-height:1.3;margin:0 0 clamp(12px,1.8vw,18px);color:#FFFDF4;
  font-weight:700;letter-spacing:.01em;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}
.fh-lead{color:#DCE6D5;font-size:clamp(13.5px,1.3vw,15px);line-height:2;max-width:560px;margin:0 0 clamp(16px,2.2vw,24px);
  -webkit-font-smoothing:antialiased}
.fh-cta{display:flex;flex-wrap:wrap;gap:10px}
.fh-cta .btn{background:#FFFDF4;color:#1E4636;border:1px solid #FFFDF4;border-radius:999px;padding:10px 22px;
  font-size:14px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:7px;
  box-shadow:0 8px 20px rgba(8,30,20,.22);transition:all .18s}
.fh-cta .btn:hover{background:#E6EFD8;transform:translateY(-1px)}
.fh-cta .btn.ghost{background:rgba(255,255,255,.05);color:#E6EFD8;border-color:rgba(226,240,214,.42);box-shadow:none}
.fh-cta .btn.ghost:hover{background:rgba(226,240,214,.14);border-color:#B9CDA6}
/* 右侧老森速览卡（让 hero 左右平衡） */
.fh-side{background:rgba(255,255,255,.07);border:1px solid rgba(226,240,214,.22);
  border-radius:18px;padding:clamp(16px,2vw,22px);backdrop-filter:blur(3px);min-width:0}
.fh-side-top{display:flex;align-items:center;gap:12px;margin-bottom:10px}
.fh-side .av{width:46px;height:46px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(140deg,#EAF0E0,#C9D8A4);color:#1E4636;font-family:var(--font-serif,serif);
  font-size:21px;font-weight:800}
.fh-side h3{margin:0;font-family:var(--font-serif,"Noto Serif SC",serif);font-size:17px;color:#FFFDF4;font-weight:700}
.fh-side .role{font-size:11.5px;color:#AFC6B4;margin-top:1px;letter-spacing:.05em}
.fh-side .bio{color:#DCE6D5;font-size:12.8px;line-height:1.85;margin:0 0 12px}
.fh-side .fl{display:inline-flex;align-items:center;gap:6px;color:#E6EFD8;font-size:12.5px;font-weight:600;
  text-decoration:none}
.fh-side .fl:hover{color:#fff}
/* 数据 2x2 */
.fh-stats{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;margin-top:14px;
  padding-top:13px;border-top:1px solid rgba(226,240,214,.18)}
.fstat b{display:block;font-family:var(--font-serif,serif);font-size:clamp(20px,2vw,26px);line-height:1;
  color:#FFFDF4;font-weight:700}
.fstat b small{font-size:.52em;color:#C9D8A4;margin-left:3px;font-weight:600}
.fstat span{display:block;margin-top:5px;font-size:11.5px;color:#AFC6B4;letter-spacing:.06em}
@media(max-width:900px){
  .fh-in{grid-template-columns:1fr}
  .fh-side{order:2}
}
/* 节标题 */
.fsec{display:flex;align-items:baseline;gap:16px;margin:2px 0 16px}
.fsec h2{font-family:var(--font-serif,"Noto Serif SC",serif);font-size:clamp(19px,2.3vw,25px);
  font-weight:700;color:var(--text,#23322B);margin:0;letter-spacing:.01em}
.fsec .en{display:block;font-size:10.5px;color:var(--accent,#2E5C43);letter-spacing:.3em;text-transform:uppercase;
  font-weight:700;margin-bottom:4px;font-family:system-ui}
.fsec::after{content:"";flex:1;height:1px;background:linear-gradient(90deg,var(--hairline,#EAECE2),transparent)}
.fsec .more{font-size:12.5px;color:var(--text-soft,#57665C);text-decoration:none;white-space:nowrap}
.fsec .more:hover{color:var(--accent-deep,#234735)}
/* 栏目卡：占满整行（auto-fit） */
.fgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
.fc{position:relative;display:flex;flex-direction:column;gap:3px;text-decoration:none;
  background:#F7FAF0;border:1px solid var(--hairline,#DCE5D2);border-radius:14px;padding:16px 16px 13px;
  transition:all .2s;box-shadow:var(--shadow-sm);overflow:hidden}
.fc::before{content:"";position:absolute;left:0;right:0;bottom:0;height:3px;width:0;
  background:linear-gradient(90deg,var(--accent,#2E5C43),#7FA889);transition:width .28s}
.fc:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);border-color:#C9D8C0}
.fc:hover::before{width:100%}
.fc-no{font-family:var(--font-serif,serif);font-style:italic;color:#6F9479;font-size:12px;letter-spacing:.06em}
.fc-ic svg{width:24px;height:24px;stroke:var(--accent,#2E5C43);margin:3px 0}
.fc b{font-family:var(--font-serif,serif);font-size:16.5px;color:#22332B;font-weight:700}
.fc p{margin:0;color:#66756B;font-size:12.8px;line-height:1.8}
.fc-go{margin-top:8px;font-size:12.5px;font-weight:700;color:var(--accent,#2E5C43);display:flex;align-items:baseline;gap:7px}
.fc-go span{font-family:var(--font-serif,serif);font-style:italic;font-weight:400;color:#93A49A}
/* 引语 */
.fquote{position:relative;border-radius:16px;padding:clamp(20px,2.6vw,32px) clamp(20px,3.2vw,42px);
  background:linear-gradient(135deg,#EEF4EA,#E3EDDF);border:1px solid #D8E6D2;color:#294833;overflow:hidden}
.fquote::before{content:"“";position:absolute;top:-18px;left:10px;font-family:var(--font-serif,serif);
  font-size:110px;line-height:1;color:#7FA889;opacity:.14}
.fquote p{margin:0;font-family:var(--font-serif,"Noto Serif SC",serif);font-size:clamp(15px,1.8vw,19px);
  line-height:1.9;font-weight:600}
.fquote .who{margin-top:8px;font-size:12px;color:#5E7A66;letter-spacing:.12em;font-weight:600}
html.dark .fquote{background:linear-gradient(135deg,#1C2A21,#20332a);border-color:#2C4436;color:#DCE6DF}
html.dark .fquote .who{color:#93A89C}
/* 最新文章：两列文章卡网格（复用 .post-item 观感，桌面两列、手机单列） */
.latest-list{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;align-items:start}
.latest-list .muted{grid-column:1/-1;color:var(--text-faint,#7A8F83);font-size:13px;padding:20px 4px}
.latest-list .post-item{margin:0}
@media(max-width:860px){.latest-list{grid-template-columns:1fr}}
/* CTA */
.fcta-band{position:relative;overflow:hidden;border-radius:18px;text-align:center;
  background:linear-gradient(150deg,#153126,#224838 60%,#2A5743);color:#F4F1E3;
  padding:clamp(24px,3.4vw,44px) clamp(20px,3.4vw,44px);box-shadow:0 16px 40px rgba(20,52,40,.16)}
.fcta-band::before{content:"";position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(520px 260px at 12% -6%,rgba(226,240,214,.12),transparent 60%)}
.fcta-band b{display:block;font-family:var(--font-serif,serif);font-size:clamp(18px,2.4vw,24px);color:#FFFDF4;
  font-weight:700;line-height:1.6}
.fcta-band p{color:#DCE6D5;font-size:13.5px;margin:8px auto 18px;max-width:540px;line-height:1.9}
.fcta-band .fcta{display:flex;flex-wrap:wrap;gap:10px;justify-content:center}
.fcta-band .btn{background:#FFFDF4;color:#1E4636;border-radius:999px;padding:10px 26px;font-weight:700;
  text-decoration:none;font-size:14px;display:inline-flex;gap:7px;align-items:center}
.fcta-band .btn:hover{background:#E6EFD8;transform:translateY(-1px)}
.fcta-band .btn.ghost{background:transparent;color:#E6EFD8;border:1px solid rgba(226,240,214,.45)}
.fcta-band .btn.ghost:hover{border-color:#B9CDA6;background:rgba(226,240,214,.12)}
@media(max-width:620px){.fh-title{font-size:28px}}
</style>

<div class="lnd-home">

  <!-- ===== Hero ===== -->
  <section class="fhero">
    <img class="fhero-watermark" src="/themes/forest/logo.svg" alt="" aria-hidden="true">
    <div class="fh-in">
      <div class="fh-main">
        <span class="fh-eyebrow"><?= e($L ? 'FOREST TEA · YUNNAN · A FOREST IN YOUR CUP' : '森林茶 · 云南 · 杯中森林') ?></span>
        <h1 class="fh-title"><?= e($L ? 'A Forest in Every Cup' : '一杯茶里，藏着一片森林') ?></h1>
        <p class="fh-lead"><?php if ($L): ?>Hi, I'm Lao Sen. For over twenty years I've walked Yunnan's tea mountains — writing about authenticity, regions, craft, storage and price in words anyone can actually use.<?php else: ?><?= e($sub) ?>。我是老森，在云南茶山走了二十年——真假鉴别、产区工艺、存放价格，把森林茶讲成你能听懂、用得上的话。<?php endif; ?></p>
        <div class="fh-cta">
          <?php if ($laosenPg): ?>
          <a class="btn" href="<?= e($U . '/' . rawurlencode((string)$laosenPg['slug']) . '.html') ?>"><?= e($L ? 'About Lao Sen' : '了解老森') ?></a>
          <?php endif; ?>
          <a class="btn ghost" href="<?= e($U . '/category/laosen/') ?>"><?= e($L ? "Read Lao Sen's Column →" : '读老森专栏 →') ?></a>
          <a class="btn ghost" href="<?= e($U . '/category/baike/') ?>"><?= e($L ? 'Forest Tea Encyclopedia →' : '森林茶百科 →') ?></a>
        </div>
      </div>
      <aside class="fh-side">
        <div class="fh-side-top">
          <span class="av"><?= e($blogAv) ?></span>
          <div><h3><?= e($L ? 'Lao Sen' : '老森') ?></h3><div class="role"><?= e($L ? 'Yunnan · 20 years on the tea hills' : '云南 · 跑了二十年茶山') ?></div></div>
        </div>
        <p class="bio"><?= e(Str::excerpt($L ? 'Twenty years walking Yunnan\'s tea mountains, telling it straight about forest tea.' : ($bio !== '' ? $bio : '跑了二十年云南茶山的老森，把森林茶的事讲明白。'), 72)) ?></p>
        <?php if ($laosenPg): ?><a class="fl" href="<?= e($U . '/' . rawurlencode((string)$laosenPg['slug']) . '.html') ?>"><?= e($L ? 'About Lao Sen →' : '关于老森 →') ?></a><?php endif; ?>
        <div class="fh-stats">
          <div class="fstat"><b><?= (int)$totPost ?><?php if (!$L): ?><small>篇</small><?php endif; ?></b><span><?= e($L ? 'Articles' : '干货内容') ?></span></div>
          <div class="fstat"><b>20<?php if (!$L): ?><small>年</small><?php endif; ?></b><span><?= e($L ? 'Years in the hills' : '跑山经验') ?></span></div>
          <div class="fstat"><b><?= (int)$totCat ?><?php if (!$L): ?><small>个</small><?php endif; ?></b><span><?= e($L ? 'Columns' : '核心栏目') ?></span></div>
          <div class="fstat"><b><?= (int)$totTag ?><small>+</small></b><span><?= e($L ? 'Terms & tags' : '词条标签') ?></span></div>
        </div>
      </aside>
    </div>
  </section>

  <!-- ===== 栏目 ===== -->
  <section>
    <div class="fsec"><span class="en">Explore</span><h2><?= e($L ? 'Where to Start' : '从哪儿逛起') ?></h2><a class="more" href="<?= e($U . '/category/') ?>"><?= e($L ? 'All Columns →' : '全部栏目 →') ?></a></div>
    <div class="fgrid"><?= $catCards ?></div>
  </section>

  <!-- ===== 引语 ===== -->
  <section class="fquote">
    <p><?= e($L ? 'Forest tea is not mysterious. It is about which forest a tea tree grows in, who its neighbours are, and how it lives year after year — all things you can walk into and see, then sit down and taste.' : '森林茶不玄。它讲的是那棵茶树长在哪片林子里、和谁做邻居、年复一年怎么活——这些，都是能走进去看、坐下来喝明白的事。') ?></p>
    <div class="who"><?= e($L ? '— Lao Sen · Notes from the Mountain Trail' : '—— 老森 · 跑山人笔记') ?></div>
  </section>

  <!-- ===== 最新文章 ===== -->
  <section>
    <div class="fsec"><span class="en">Latest</span><h2><?= e($L ? 'Latest Articles' : '最新文章') ?></h2><a class="more" href="<?= e($U . '/category/') ?>"><?= e($L ? 'Browse all →' : '浏览全部 →') ?></a></div>
    <div class="latest-list"><?= $postItems ?></div>
  </section>

  <!-- ===== CTA ===== -->
  <section class="fcta-band">
    <b><?= e($L ? 'Thinking of buying tea? Slow down — read this corner first.' : '想买茶？别急着掏钱——先把这里读懂。') ?></b>
    <p><?= e($L ? 'Authenticity, craft, storage and price: the plain words Lao Sen has gathered in twenty years on the mountains, one article at a time.' : '真假、工艺、存放、价格，老森二十年跑山攒下的实在话，一篇篇说给你听。') ?></p>
    <div class="fcta">
      <a class="btn" href="<?= e($U . '/category/laosen/') ?>"><?= e($L ? "Start Reading Lao Sen's Column" : '开始读老森专栏') ?></a>
      <a class="btn ghost" href="<?= e($U . '/flavors.html') ?>"><?= e($L ? 'Explore Flavors of the Forest' : '看看森林的味道') ?></a>
      <a class="btn ghost" href="<?= e($U . '/feed') ?>"><?= e($L ? 'Subscribe to RSS' : '订阅 RSS') ?></a>
    </div>
  </section>

</div>
