<?php
/** yecha 主题首页 · 浏览工坊「工具墙」版（方向 1 · 首页 A 版）
 *  站主 2026-09-23 定稿：137 个工具做成一面"百格工具墙"（与 logo B 同源）+ 主搜索框 + 四条真实计数。
 *  纪律：只用站上真实数据（工具逐个来自 DB，四态来自 ye_links 探测结果）；不编功能、不编日期。
 *  回滚 = 把选项 home.style 改回 text，或删本文件。
 *  变量：$posts（首页最新内容，可为空）
 */
$subtitle = trim((string)get_option('site.subtitle', ''));
$nTools   = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . " WHERE status='publish' AND deleted_at IS NULL AND slug LIKE 'tool\\_\\_%'");
$nTips    = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . " WHERE status='publish' AND deleted_at IS NULL AND slug LIKE 'tips\\_\\_%'");
$nLearn   = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . " WHERE status='publish' AND deleted_at IS NULL AND slug LIKE 'learn\\_\\_%'");
$nPages   = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . " WHERE status='publish' AND deleted_at IS NULL");

/* 四态：来自 ye_links 的真实探测结论（navsrc:pg-nav_sites 那批 = 导航 151 条） */
$navMark  = 'navsrc:pg-nav_sites';
$navTotal = 0; $navAlive = 0; $navSlow = 0; $navBlocked = 0; $navDown = 0;
if (Db::rows('SHOW TABLES LIKE "' . Db::t('links') . '"')) {
    $navTotal = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('links') . ' WHERE engine=?', [$navMark]);
    foreach (Db::rows('SELECT probe_state s, COUNT(*) c FROM ' . Db::t('links') . ' WHERE engine=? GROUP BY probe_state', [$navMark]) as $r) {
        $k = (string)$r['s'];
        if ($k === 'alive')        { $navAlive   = (int)$r['c']; }
        elseif ($k === 'slow')     { $navSlow    = (int)$r['c']; }
        elseif ($k === 'blocked')  { $navBlocked = (int)$r['c']; }
        else                       { $navDown   += (int)$r['c']; }
    }
}
$lockCls = $navDown > 0 ? '' : ' alive';   /* 站点整体状态：有 down 就中性，全活就绿 */

/* 工具墙：每个格子 = 一个真实工具（直接来自 DB，不写死清单）
   分类与配色（与样张方向 1 一致，8 色）：文本与编码 / Markdown / 开发者 / 图片 / 音视频 / 生活计算 / 日期时间 / 生成器 */
$catOf = [
    // 文本与编码
    'text'=>1,'textdiff'=>1,'wordcount'=>1,'case'=>1,'base64'=>1,'hash'=>1,'hex'=>1,'binary'=>1,
    'baseconv'=>1,'unicode'=>1,'htmlentity'=>1,'morse'=>1,'rot13'=>1,'railfence'=>1,'vigenere'=>1,
    'caesar'=>1,'simptrad'=>1,'numcn'=>1,'numrmb'=>1,'roman'=>1,'numcn'=>1,'regex'=>1,'url'=>1,'punycode'=>1,
    // Markdown
    'markdown'=>2,'md2html'=>2,'md2pdf'=>2,'md2word'=>2,'md-todocx'=>2,'md2image'=>2,'md-convert'=>2,
    'md-format'=>2,'md-lint'=>2,'md-toc'=>2,'md-badge'=>2,'md-diagram'=>2,'md-mindmap'=>2,'md-slide'=>2,
    'md-wechat'=>2,'md-github-cards'=>2,'md-fromoffice'=>2,
    // 开发者
    'json'=>3,'yaml'=>3,'xml'=>3,'csv'=>3,'mock'=>3,'uuid'=>3,'jwt'=>3,'totp'=>3,'snowflake'=>3,
    'cidr'=>3,'ip'=>3,'ua'=>3,'httpcode'=>3,'chmod'=>3,'cron'=>3,'gitignore'=>3,'extractlinks'=>3,
    'luhn'=>3,'screenrec'=>3,'browserinfo'=>3,
    // 图片
    'qrcode'=>4,'barcode'=>4,'favicon'=>4,'faviconx'=>4,'imgcompress'=>4,'imgresize'=>4,'imggray'=>4,
    'imginvert'=>4,'imgformat'=>4,'imgwatermark'=>4,'imgpick'=>4,'imggrid'=>4,'imgmosaic'=>4,'imgbase64'=>4,
    'imgradius'=>4,'imginfo'=>4,'avatar'=>4,'svg2png'=>4,'paint'=>4,'plate'=>4,
    // 音视频
    'recorder'=>4,'stt'=>5,'tts'=>5,'audioconv'=>5,'audiojoin'=>5,'audiospeed'=>5,'audiogain'=>5,
    'audiotrim'=>5,'videotrim'=>5,
    // 生活计算
    'bmi'=>6,'tax'=>6,'mortgage'=>6,'investment'=>6,'installment'=>6,'discount'=>6,'compound'=>6,
    'affine'=>6,'whattoeat'=>6,'pregnancy'=>6,'petage'=>6,'period'=>6,'zodiac'=>6,'constellation'=>6,
    'blood'=>6,'tianti'=>6,'relatives'=>6,'capital'=>6,'countrycode'=>6,'dynasty'=>6,'lottery'=>6,
    'area'=>6,'coin'=>6,'unit'=>6,'size'=>6,'width'=>6,'color'=>6,'palette'=>6,'contrast'=>6,
    'gradient'=>6,'wheel'=>6,'random'=>6,
    // 日期时间
    'timestamp'=>7,'clock'=>7,'worldclock'=>7,'calendar'=>7,'agediff'=>7,'age'=>7,'countdown'=>7,
    'pomodoro'=>7,'teatimer'=>7,'moonphase'=>7,
    // 生成器
    'password'=>8,'persona-generator'=>8,'wheel-spinner'=>8,'image-collage'=>8,'emoji'=>8,'lorem'=>8,
    'idcard'=>8,'id-photo'=>8,'name'=>8,'todo'=>8,'notepad'=>8,'group'=>8,
];
$catName = [1=>'文本与编码',2=>'Markdown',3=>'开发者',4=>'图片',5=>'音视频',6=>'生活计算',7=>'日期时间',8=>'生成器'];

$wallRows = Db::rows('SELECT slug,title FROM ' . Db::t('contents')
    . " WHERE status='publish' AND deleted_at IS NULL AND slug LIKE 'tool\\_\\_%' ORDER BY slug ASC");
$wall = [];
foreach ($wallRows as $w) {
    $s = preg_replace('/^tool__/', '', (string)$w['slug']);
    $cat = $catOf[$s] ?? 1;
    $wall[] = ['slug' => $s, 'title' => (string)$w['title'], 'cat' => $cat];
}
$wallCount = count($wall);
$wallGrid  = 140;                                  /* 与样张一致：140 格（137 已上 + 3 留空） */
$hotIdx    = [3, 38, 74, 110];

/* 高频工具（真实存在、且这几天的真实访问里排前列） */
$hot = [
    ['密码生成器', 'password', '生成随机强密码'],
    ['时间戳转换', 'timestamp', '时间戳与日期互转'],
    ['月相查询',   'moonphase', '按日期查月相'],
    ['二维码生成', 'qrcode',    '文本 / 网址转二维码'],
    ['Base64',     'base64',    'Base64 编解码'],
    ['录音',       'recorder',  '浏览器内录音'],
    ['单位换算',   'unit',      '常见单位互换'],
    ['字数统计',   'wordcount', '字数 / 字符 / 行数'],
];
?>
<style>
/* ★ [中性色令牌化批 2026-09-24] 原值：var(--line)×4 var(--line)×2 → var(--line)；var(--fg)×2 var(--fg)×3 → var(--fg)。令牌 = theme 自己的 style.css 已定义的 --line / --fg。回滚 = 换回 wall.php.bak_tok_20260923-1838 */
/* ===== 首页「工具墙」（方向 1）· 全部规则前缀 .wt- ，不影响站内其它页 ===== */
.wt-hero{
  background:linear-gradient(180deg,#ffffff 0%,#f3f6ff 58%,#e8eefc 100%);
  border-bottom:1px solid var(--line);
  padding:30px 0 26px;text-align:center;position:relative;overflow:hidden;
}
.wt-hero::before{
  content:"";position:absolute;inset:0;pointer-events:none;opacity:.5;
  background-image:radial-gradient(circle at 1px 1px,rgba(47,91,215,.14) 1px,transparent 0);
  background-size:22px 22px;
  -webkit-mask-image:linear-gradient(180deg,#000 0%,transparent 72%);
          mask-image:linear-gradient(180deg,#000 0%,transparent 72%);
}
.wt-hero>*{position:relative}
.wt-in{max-width:1120px;margin:0 auto;padding:0 20px}
.wt-h1{font-size:38px;line-height:1.28;margin:0 0 10px;letter-spacing:-1px;font-weight:900;color:var(--fg)}
.wt-h1 em{
  font-style:normal;
  background:linear-gradient(92deg,#2450c7 0%,#5b4fd0 52%,#0f97a8 100%);
  -webkit-background-clip:text;background-clip:text;color:transparent;
}
.wt-sub{margin:0 auto;max-width:660px;font-size:16px;line-height:1.85;color:var(--muted)}
.wt-sub b{color:var(--fg)}
.wt-search{
  display:flex;align-items:center;gap:10px;max-width:700px;margin:20px auto 0;
  background:#fff;border:1.5px solid var(--line);border-radius:12px;padding:14px 16px;
  box-shadow:0 2px 4px rgba(47,91,215,.06),0 14px 32px -14px rgba(47,91,215,.28);
}
.wt-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:inherit;font-size:15px;color:var(--fg)}
.wt-search input::placeholder{color:#9aa3ae}
.wt-search .kbd{font:10.5px/1 ui-monospace,Menlo,Consolas,monospace;color:var(--muted);border:1px solid var(--line);border-radius:4px;padding:4px 6px;background:#f7f8fa}
.wt-quick{display:flex;gap:7px;flex-wrap:wrap;justify-content:center;margin-top:11px;font-size:12px;color:var(--muted);align-items:center}
.wt-quick a{border:1px solid var(--line);background:#fff;border-radius:999px;padding:3px 10px;font-size:12px;color:var(--muted)}
.wt-quick a:hover{border-color:var(--line);color:#2450c7;background:#eef2fe}
.wt-cta{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin:18px 0 0}
.wt-btn{display:inline-flex;align-items:center;gap:8px;border-radius:9px;padding:12px 21px;font-size:15px;font-weight:700;border:1px solid var(--line);background:#fff;color:var(--fg);box-shadow:0 1px 2px rgba(16,20,28,.05)}
.wt-btn:hover{color:var(--fg);border-color:#c9ced6}
.wt-btn.pri{background:#2f5bd7;border-color:#2f5bd7;color:#fff}
.wt-btn.pri:hover{background:#2450c7;color:#fff}
.wt-btn .n{font:700 12.5px/1 ui-monospace,Menlo,Consolas,monospace;background:rgba(255,255,255,.18);border-radius:4px;padding:4px 5px}
.wt-btn:not(.pri) .n{background:#f1f2f5;color:var(--muted)}
.wt-facts{display:flex;gap:18px;flex-wrap:wrap;justify-content:center;margin:16px 0 0;font-size:12.5px;color:var(--muted)}
.wt-facts b{color:var(--fg)}

/* 工具墙 */
.wt-wallbox{max-width:960px;margin:22px auto 0;text-align:left}
.wt-wall{
  display:grid;grid-template-columns:repeat(20,1fr);gap:6px;padding:18px;
  border:1px solid var(--line);border-radius:16px;background:linear-gradient(180deg,#fff,#fbfcff);
  box-shadow:0 2px 4px rgba(16,20,28,.05),0 18px 40px -20px rgba(16,20,28,.28);
}
.wt-wall a{
  aspect-ratio:1;border-radius:5px;display:block;background:#f1f2f5;
  box-shadow:inset 0 -1px 0 rgba(0,0,0,.12);
}
.wt-wall a:hover{outline:2px solid rgba(20,23,26,.6);outline-offset:1.5px;transform:scale(1.06)}
.wt-wall a.c1{background:#2f5bd7} .wt-wall a.c2{background:#7b57d4}
.wt-wall a.c3{background:#0f97a8} .wt-wall a.c4{background:#18894f}
.wt-wall a.c5{background:#639922} .wt-wall a.c6{background:#b57d09}
.wt-wall a.c7{background:#d1622f} .wt-wall a.c8{background:#b3372f}
.wt-wall b.off{aspect-ratio:1;border-radius:5px;display:block;background:#fff;box-shadow:inset 0 0 0 1.5px var(--line)}
.wt-wallcap{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:11px 3px 0;font-size:12.5px;color:var(--muted)}
.wt-wallcap .big{font:900 22px/1 ui-monospace,Menlo,Consolas,monospace;color:var(--fg);letter-spacing:-.5px}
.wt-wallcap .big em{font-style:normal;color:#2f5bd7}
.wt-wallcap .div{width:1px;height:16px;background:var(--line)}
.wt-legend{display:flex;flex-wrap:wrap;gap:7px 12px;margin:10px 3px 0;font-size:11.5px;color:var(--muted)}
.wt-legend span{display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.wt-legend i{width:10px;height:10px;border-radius:3px;display:block;flex:none}

/* 计数条 */
.wt-strip{display:grid;grid-template-columns:repeat(4,1fr);background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;box-shadow:0 1px 2px rgba(16,20,28,.05),0 10px 24px -16px rgba(16,20,28,.16);margin:26px 0 0}
.wt-strip .cell{padding:17px 18px;border-left:1px solid var(--line)}
.wt-strip .cell:first-child{border-left:0}
.wt-strip .n{font:900 30px/1.15 ui-monospace,Menlo,Consolas,monospace;letter-spacing:-1px}
.wt-strip .l{font-size:12.5px;color:var(--muted);margin-top:3px}
.wt-strip .cell.a1 .n{color:#2f5bd7} .wt-strip .cell.a2 .n{color:#7b57d4}
.wt-strip .cell.a3 .n{color:#0f97a8} .wt-strip .cell.a4 .n{color:#18894f}
.wt-strip .cell.a5 .n{color:#b57d09}

/* 高频工具卡 */
.wt-sec{margin:32px 0 0}
.wt-sechead{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
.wt-sechead h2{font-size:19px;margin:0;font-weight:900;letter-spacing:-.3px}
.wt-sechead .cnt{font:600 12px/1 ui-monospace,Menlo,Consolas,monospace;color:var(--muted);background:#fff;border:1px solid var(--line);border-radius:999px;padding:4px 9px}
.wt-sechead .more{margin-left:auto;font-size:13px;font-weight:700;color:#2f5bd7}
.wt-sub2{color:var(--muted);font-size:13px;margin:5px 0 13px}
.wt-tools{display:grid;grid-template-columns:repeat(auto-fill,minmax(214px,1fr));gap:11px}
.wt-tool{position:relative;background:#fff;border:1px solid var(--line);border-radius:11px;padding:12px 13px;box-shadow:0 1px 2px rgba(16,20,28,.05),0 10px 24px -14px rgba(16,20,28,.16);overflow:hidden}
.wt-tool::before{content:"";position:absolute;left:0;right:0;top:0;height:3px;background:var(--acc,#2f5bd7)}
.wt-tool:hover{transform:translateY(-2px);box-shadow:0 2px 4px rgba(16,20,28,.06),0 16px 34px -16px rgba(16,20,28,.24)}
.wt-tool .t{display:flex;align-items:center;gap:8px}
.wt-tool .ico{width:26px;height:26px;border-radius:8px;flex:none;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;background:var(--acc,#2f5bd7);box-shadow:inset 0 -1px 0 rgba(0,0,0,.14)}
.wt-tool .nm{font-size:14.5px;font-weight:800;color:var(--fg);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wt-tool .ds{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.6}
.wt-tool .ft{margin-top:9px}
.wt-local{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:#2f5bd7;background:#eef2fe;border:1px solid var(--line);border-radius:999px;padding:2px 8px}

/* 所有工具（按分类分块） */
.wt-cat{margin:22px 0 0}
.wt-cat h3{font-size:14px;margin:0 0 9px;display:flex;align-items:center;gap:8px;font-weight:800}
.wt-cat h3 i{width:11px;height:11px;border-radius:3px;display:block;flex:none}
.wt-cat h3 .c{font:600 11.5px/1 ui-monospace,Menlo,Consolas,monospace;color:var(--muted);font-weight:600}
.wt-chips{display:flex;flex-wrap:wrap;gap:7px}
.wt-chip{font-size:12.5px;border:1px solid var(--line);background:#fff;border-radius:999px;padding:5px 12px;color:var(--muted);font-weight:600}
.wt-chip:hover{color:#2450c7;background:#eef2fe;border-color:var(--line)}

/* 深色 CTA 区（页脚前的节奏） */
.wt-ctaband{position:relative;overflow:hidden;background:linear-gradient(120deg,#14171a 0%,#1b2330 48%,#213040 100%);color:#fff;border-radius:16px;padding:26px 28px;margin:32px 0 6px;display:grid;grid-template-columns:1fr auto;gap:20px;align-items:center;box-shadow:0 18px 40px -20px rgba(16,20,28,.6)}
.wt-ctaband::before{content:"";position:absolute;inset:0;pointer-events:none;opacity:.5;background-image:linear-gradient(rgba(255,255,255,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.05) 1px,transparent 1px);background-size:28px 28px;-webkit-mask-image:radial-gradient(80% 120% at 100% 0%,#000,transparent 70%);mask-image:radial-gradient(80% 120% at 100% 0%,#000,transparent 70%)}
.wt-ctaband>*{position:relative}
.wt-ctaband h3{margin:0 0 6px;font-size:22px;font-weight:900;letter-spacing:-.5px;color:#fff}
.wt-ctaband p{margin:0;font-size:13px;color:rgba(255,255,255,.72);line-height:1.8}
.wt-ctaband .acts{display:flex;gap:9px;flex-wrap:wrap}
.wt-ctaband .wt-btn{background:rgba(255,255,255,.10);border-color:rgba(255,255,255,.24);color:#fff;box-shadow:none}
.wt-ctaband .wt-btn.pri{background:#fff;border-color:#fff;color:#14171a}

/* 最新内容（沿用站上原有的 post-item 样式，只加标题行） */
.wt-latesthead{display:flex;align-items:baseline;gap:10px;margin:30px 0 0}
.wt-latesthead h2{font-size:19px;margin:0;font-weight:900}

@media(max-width:900px){
  .wt-wall{grid-template-columns:repeat(14,1fr);gap:5px;padding:13px}
  .wt-h1{font-size:29px}
  .wt-strip{grid-template-columns:repeat(2,1fr)}
  .wt-strip .cell:nth-child(3){border-left:0}
  .wt-strip .cell{border-top:1px solid var(--line)}
  .wt-strip .cell:nth-child(-n+2){border-top:0}
  .wt-ctaband{grid-template-columns:1fr;padding:20px}
}
@media(max-width:600px){
  .wt-wall{grid-template-columns:repeat(10,1fr);gap:4px;padding:11px}
  .wt-h1{font-size:25px}
  .wt-search{padding:11px 13px}
  .wt-search .kbd{display:none}
}
html.dark .wt-hero{background:linear-gradient(180deg,#14171b 0%,#151a22 58%,#141a26 100%);border-bottom-color:#2a2f36}
html.dark .wt-search{background:#1f2328;border-color:#2c3a63}
html.dark .wt-search input{color:var(--fg)}
html.dark .wt-wall{background:linear-gradient(180deg,#1b1f24,#181c21);border-color:#2a2f36}
html.dark .wt-wall b.off{background:#14171b;box-shadow:inset 0 0 0 1.5px #2a2f36}
html.dark .wt-strip,html.dark .wt-tool{background:#1b1f24;border-color:#2a2f36}
html.dark .wt-strip .cell{border-left-color:#2a2f36}
html.dark .wt-strip .cell{border-top-color:#2a2f36}
html.dark .wt-chip{background:#1b1f24;border-color:#2a2f36;color:#a8afb8}
html.dark .wt-local{background:#1c2540;border-color:#2c3a63;color:#7d9cf2}
html.dark .wt-btn{background:#1b1f24;border-color:#2a2f36;color:var(--fg)}
html.dark .wt-h1{color:var(--fg)}
html.dark .wt-sub b{color:var(--fg)}
html.dark .wt-wallcap .big{color:var(--fg)}
</style>

<div class="wt-in">
  <!-- ===== 第一屏：品牌 + 一句话 + 主搜索框 + 两个主入口 + 工具墙 ===== -->
  <section class="wt-hero" style="margin:0 -20px;padding-left:20px;padding-right:20px">
    <h1 class="wt-h1">你的浏览器就是<em>工作台</em></h1>
    <p class="wt-sub"><b><?= (int)$nTools ?> 个在线工具，<?= (int)$navTotal ?> 个入口条条实测。</b><br>
      打开就能用，不用注册、不用装东西，用完关掉。<br>
      <span style="font-size:13px;color:var(--muted)">不需要服务器的工具，逐个标上「本机处理 · 不上传」。</span></p>

    <form class="wt-search" method="get" action="/search" role="search">
      <svg width="17" height="17" viewBox="0 0 16 16" fill="none" aria-hidden="true" style="flex:none;color:#9aa3ae"><circle cx="7" cy="7" r="5" stroke="currentColor" stroke-width="1.6"/><path d="M11 11l3.5 3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      <input type="search" name="q" placeholder="搜工具、搜站点、搜技巧 —— 例如：二维码 / 时间戳 / 书签" aria-label="站内搜索">
      <span class="kbd">/</span><span class="kbd">Ctrl K</span>
    </form>
    <div class="wt-quick">
      <span>热门：</span>
      <?php foreach ([['二维码生成','qrcode'],['密码生成器','password'],['时间戳转换','timestamp'],['单位换算','unit'],['字数统计','wordcount'],['Base64','base64']] as $qq): ?>
      <a href="/tool/<?= e($qq[1]) ?>"><?= e($qq[0]) ?></a>
      <?php endforeach; ?>
    </div>

    <div class="wt-cta">
      <a class="wt-btn pri" href="/tool">进入工具区 <span class="n"><?= (int)$nTools ?></span></a>
      <a class="wt-btn" href="/links-health">打开导航 <span class="n"><?= (int)$navTotal ?></span></a>
    </div>

    <div class="wt-facts">
      <span>导航每 <b>6 小时</b>验一轮</span>
      <span>内容 <b><?= (int)$nPages ?></b> 页 · 技巧 <b><?= (int)$nTips ?></b> 篇</span>
      <span>学堂 <b><?= (int)$nLearn ?></b> 篇 · 下载 <b>15</b> 个品牌页</span>
    </div>

    <!-- 工具墙：每一格 = 一个真实工具（点进去就是那个工具页） -->
    <div class="wt-wallbox">
      <div class="wt-wall" role="img" aria-label="<?= (int)$wallCount ?> 个工具的工具墙：每一格代表一个在线工具">
        <?php foreach ($wall as $i => $w): ?>
        <a class="c<?= (int)$w['cat'] ?>" href="/tool/<?= e($w['slug']) ?>" title="<?= e($w['title']) ?>"></a>
        <?php endforeach; ?>
        <?php for ($i = $wallCount; $i < $wallGrid; $i++): ?><b class="off"></b><?php endfor; ?>
      </div>
      <div class="wt-wallcap">
        <span class="big"><em><?= (int)$wallCount ?></em> 格</span>
        <span class="div"></span>
        <span><b>每一格 = 一个在线工具</b>，点进去就是那个工具页</span>
        <span class="div"></span>
        <span style="font-family:ui-monospace,Menlo,Consolas,monospace"><?= (int)$wallGrid ?> 格 = <?= (int)$wallCount ?> 已上 + <?= max(0, (int)$wallGrid - (int)$wallCount) ?> 待上</span>
      </div>
      <div class="wt-legend">
        <?php foreach ($catName as $ci => $cn): ?>
        <span><i class="c<?= (int)$ci ?>" style="background:<?= ['','#2f5bd7','#7b57d4','#0f97a8','#18894f','#639922','#b57d09','#d1622f','#b3372f'][$ci] ?>"></i><?= e($cn) ?></span>
        <?php endforeach; ?>
        <span style="color:var(--muted)">↑ 颜色按分类；每一格都对应站上真实存在的一个工具</span>
      </div>
    </div>
  </section>

  <!-- ===== 数字带 ===== -->
  <div class="wt-strip">
    <div class="cell a1"><div class="n"><?= (int)$nTools ?></div><div class="l">个在线工具</div></div>
    <div class="cell a2"><div class="n"><?= (int)$navTotal ?></div><div class="l">个导航入口 · 逐条实测</div></div>
    <div class="cell a3"><div class="n"><?= (int)$nTips ?></div><div class="l">篇浏览器技巧</div></div>
    <div class="cell a4"><div class="n"><?= (int)$navAlive ?></div><div class="l">条入口活着（本轮实测）</div></div>
  </div>

  <!-- ===== 高频工具 ===== -->
  <section class="wt-sec">
    <div class="wt-sechead"><h2>高频工具</h2><span class="cnt">Top 8</span><a class="more" href="/tool">全部 <?= (int)$nTools ?> 个 →</a></div>
    <p class="wt-sub2">按最近的真实访问排出来的几个，先从这里试手。</p>
    <div class="wt-tools">
      <?php $accs = ['#2f5bd7','#7b57d4','#0f97a8','#18894f','#639922','#b57d09','#d1622f','#b3372f'];
      foreach ($hot as $hi => $h): ?>
      <a class="wt-tool" href="/tool/<?= e($h[1]) ?>" style="--acc:<?= $accs[$hi % 8] ?>">
        <div class="t"><span class="ico"><?= e(mb_substr($h[0], 0, 1)) ?></span><span class="nm"><?= e($h[0]) ?></span></div>
        <div class="ds"><?= e($h[2]) ?></div>
        <div class="ft"><span class="wt-local">本机处理</span></div>
      </a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ===== 全部工具（按分类，真实工具逐个列出） ===== -->
  <section class="wt-sec">
    <div class="wt-sechead"><h2>全部工具</h2><span class="cnt"><?= (int)$nTools ?> 个 · <?= count($catName) ?> 类</span><a class="more" href="/tool">进工具区 →</a></div>
    <p class="wt-sub2">按你现在想干的事找，不按技术分类找。</p>
    <?php
    $byCat = [];
    foreach ($wall as $w) { $byCat[$w['cat']][] = $w; }
    ksort($byCat);
    foreach ($byCat as $ci => $items):
        $cname = $catName[$ci] ?? '其他';
        $ccol  = ['','#2f5bd7','#7b57d4','#0f97a8','#18894f','#639922','#b57d09','#d1622f','#b3372f'][$ci] ?? '#5b6470';
    ?>
    <div class="wt-cat">
      <h3><i style="background:<?= $ccol ?>"></i><?= e($cname) ?> <span class="c"><?= count($items) ?> 个</span></h3>
      <div class="wt-chips">
        <?php foreach ($items as $it): ?>
        <a class="wt-chip" href="/tool/<?= e($it['slug']) ?>"><?= e($it['title']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </section>

  <!-- ===== 深色 CTA 区 ===== -->
  <div class="wt-ctaband">
    <div>
      <h3><?= (int)$navTotal ?> 个入口，条条实测过</h3>
      <p>活的 <b style="color:#fff"><?= (int)$navAlive ?></b> · 慢的 <b style="color:#fff"><?= (int)$navSlow ?></b> · 被反爬 <b style="color:#fff"><?= (int)$navBlocked ?></b> · 已经没了的 <b style="color:#fff"><?= (int)$navDown ?></b> —— 四种状态全部公开，连坏的那几条也照原样留着。<b style="color:#fff">这里不写"我们保证链接有效"，只有每一条的最后验证时间。</b></p>
    </div>
    <div class="acts">
      <a class="wt-btn pri" href="/links-health">看链接体检报告</a>
      <a class="wt-btn" href="/links-health">打开导航 <span class="n"><?= (int)$navTotal ?></span></a>
    </div>
  </div>

  <?php if (!empty($posts)): ?>
  <div class="wt-latesthead"><h2>最新内容</h2><a class="more" href="/tips" style="margin-left:auto;font-size:13px;font-weight:700;color:#2f5bd7">全部 <?= (int)$nTips ?> 篇 →</a></div>
  <?php foreach ($posts as $p): ?>
  <article class="post-item">
    <h2><a href="/<?= e(rawurlencode((string)$p['slug'])) ?>"><?= e((string)$p['title']) ?></a></h2>
    <div class="post-meta"><span><?= e(substr((string)($p['published_at'] ?? ''), 0, 10)) ?></span></div>
  </article>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
