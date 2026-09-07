<?php
/** 官网首页·门户落地视图（landing）：仅当 option home.landing=1 时由 index 渲染 */
$name = e(site_name());
$sub = e((string)get_option('site.subtitle', ''));
$posts = $posts ?? [];
$circleBlogs = (int)($circle_blogs ?? 0);
$circlePosts = (int)($circle_posts ?? 0);
$nav = function (string $label, string $href, string $hint = ''): string {
    return '<a class="lnd-card" href="' . e($href) . '"><b>' . e($label) . '</b>' . ($hint !== '' ? '<span>' . e($hint) . '</span>' : '') . '<em>→</em></a>';
};
$postLi = '';
foreach ($posts as $p) {
    $postLi .= '<li><a href="/' . rawurlencode((string)$p['slug']) . '.html">' . e((string)$p['title']) . '</a><span>' . e(substr((string)($p['published_at'] ?? ''), 0, 10)) . '</span></li>';
}
if ($postLi === '') { $postLi = '<li class="muted">发布中的介绍与更新会出现在这里。</li>'; }
?>
<style>
.lnd{max-width:none}
.lnd h1{font-size:34px;line-height:1.3;margin:26px 0 10px;letter-spacing:.5px}
.lnd .lead{color:#565b61;font-size:16px;line-height:2;max-width:820px;margin:0 0 18px}
.lnd .cta{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 8px}
.lnd-hero{padding:14px 0 8px}
.lnd-badges{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 6px}
.lnd-badge{border:1px solid var(--line,#e6e6e2);background:#fff;border-radius:999px;padding:5px 14px;font-size:13px}
.lnd-sec{margin:34px 0 6px}
.lnd-h{font-size:13px;letter-spacing:2px;color:#b3541e;font-weight:800;text-transform:uppercase}
.lnd-t{font-size:24px;font-weight:800;margin:2px 0 14px}
.lnd-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.lnd-item{border:1px solid var(--line,#e6e6e2);border-radius:14px;padding:16px;background:#fff}
.lnd-item:hover{box-shadow:0 8px 22px rgba(0,0,0,.06);transform:translateY(-1px)}
.lnd-item .ic{font-size:22px}
.lnd-item b{display:block;font-size:15.5px;margin:8px 0 4px}
.lnd-item p{margin:0;color:#6f767e;font-size:13px;line-height:1.85}
.lnd-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}
.lnd-card{border:1px solid var(--line,#e6e6e2);border-radius:14px;padding:16px 16px 14px;background:#fbfaf7;text-decoration:none;position:relative}
.lnd-card b{font-size:15px;display:block}
.lnd-card span{color:#6f767e;font-size:12.5px;line-height:1.7;display:block;margin-top:3px}
.lnd-card em{position:absolute;right:14px;top:12px;color:#b3541e;font-style:normal}
.lnd-card:hover{border-color:#e0c9b6}
.lnd-ul{list-style:none;margin:0;padding:0;line-height:2}
.lnd-ul li{display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed #eeece6;padding:4px 0}
.lnd-ul a{color:#1f2328;text-decoration:none;font-weight:600}
.lnd-ul a:hover{color:#b3541e}
.lnd-ul span{color:#9a9a90;font-size:12px}
.lnd-ul .muted{color:#9a9a90;font-weight:400}
.lnd-two{display:grid;grid-template-columns:1fr 1fr;gap:22px}
.lnd-cta{margin:36px 0 4px;text-align:center;padding:22px;background:linear-gradient(180deg,#fdf6ef,#fbf9f5);border:1px solid #eadfce;border-radius:18px}
.lnd-cta b{font-size:17px;display:block;margin-bottom:12px}
@media(max-width:820px){.lnd-two{grid-template-columns:1fr}.lnd h1{font-size:26px}}
</style>
<div class="lnd">
  <div class="lnd-hero">
    <h1><?= $name ?> —— 一个进程跑起来的内容站，<br>也是一个「博客收录圈」门户</h1>
    <p class="lead">纯 PHP + MySQL 的极简博客系统（无框架、无 Node、无 Redis），自带多语言、插件市场与全页缓存；本站就运行在它之上——官网 + <a href="/links">博客收录圈</a> + 开源互认。</p>
    <div class="cta">
      <a class="btn" href="/download.html">下载与安装</a>
      <a class="btn ghost" href="/docs.html">帮助与教程</a>
      <a class="btn ghost" href="/market.html">应用市场</a>
      <a class="btn ghost" href="/links">去收录广场逛逛</a>
    </div>
    <div class="lnd-badges">
      <span class="lnd-badge">⚡ 全页缓存 · 毫秒级首屏</span>
      <span class="lnd-badge">🌐 多语言内容与界面</span>
      <span class="lnd-badge">🧩 插件 / 主题 / 云市场</span>
      <span class="lnd-badge">🔒 评论先审后发 · 防骚扰内建</span>
      <span class="lnd-badge">📊 阅读量访客日去重</span>
      <span class="lnd-badge">📦 MIT 开源</span>
    </div>
  </div>

  <div class="lnd-sec"><div class="lnd-h">Features</div><div class="lnd-t">它好在哪</div></div>
  <div class="lnd-grid">
    <div class="lnd-item"><span class="ic">⚡</span><b>轻快省资源</b><p>纯 PHP+MySQL，无框架依赖；全页缓存下公开页毫秒级返回，一台小机器即可支撑。</p></div>
    <div class="lnd-item"><span class="ic">🌐</span><b>多语言</b><p>内容按语言组成组、前台 /en/ 等前缀切换，术语与界面语言包联动；收录圈的声明也双语可期。</p></div>
    <div class="lnd-item"><span class="ic">🧩</span><b>可扩展</b><p>插件可注册独立页/后台/侧栏与钩子、自带幂等迁移；云市场一键安装并做 SHA-256 校验。</p></div>
    <div class="lnd-item"><span class="ic">🔒</span><b>克制与可信</b><p>评论先审后发、垃圾关键词黑名单；阅读量按“同访客自然日计 1 次”去重，计数可复核。</p></div>
    <div class="lnd-item"><span class="ic">📦</span><b>数据是自己的</b><p>全库备份/还原、一键升级（自动备份+幂等迁移留痕）、CLI 运维工具齐全。</p></div>
    <div class="lnd-item"><span class="ic">🎯</span><b>收录圈门户</b><p>本站把博客做成了“收录+聚合+回执”的门户：公开申请、机器体检、月度精选、流量回执。</p></div>
  </div>

  <div class="lnd-sec"><div class="lnd-h">News</div><div class="lnd-t">介绍与更新</div></div>
  <div class="lnd-two">
    <ul class="lnd-ul"><?= $postLi ?></ul>
    <div>
      <p class="muted" style="color:#6f767e;font-size:13.5px;line-height:2;">想更深入地了解它？读读本站正在运行的三件事：<a href="/about.html">关于 YeCha</a>、<a href="/circle">文章流</a> 与 <a href="/collection">采集与互认</a>。</p>
    </div>
  </div>

  <div class="lnd-sec"><div class="lnd-h">Open Interfaces</div><div class="lnd-t">开放接口与数据出口</div></div>
  <div class="lnd-cards">
    <?= $nav('RSS / Feed', '/feed', '全站文章 XML 输出，任何阅读器/收录圈可订阅') ?>
    <?= $nav('站点地图', '/sitemap.xml', '全部已发布内容的站点地图') ?>
    <?= $nav('采集与互认', '/collection', '收录圈抓取纪律：UA、频率、403=退出、via 来源参数') ?>
    <?= $nav('开发文档', '/developers.html', '核心类、插件与主题约定、迁移机制') ?>
    <?= $nav('应用市场 manifest', '/market.html', '插件/主题包与 manifest.json、SHA-256 源') ?>
    <?= $nav('CLI 工具', '/download.html', 'upgrade / backup / sync-views / mail-test / links-* 运维命令') ?>
  </div>

  <div class="lnd-sec"><div class="lnd-h">Site Map</div><div class="lnd-t">页面结构 · 一屏逛完</div></div>
  <div class="lnd-cards">
    <?= $nav('博客收录圈', '/links', '收录广场：分类/搜索/排序/程序筛选', ) ?>
    <?= $nav('文章流', '/circle', '圈内新文温柔聚合，点击回原站') ?>
    <?= $nav('月度精选', '/selected', '按月回看 + 站长精选 ★ + 订阅') ?>
    <?= $nav('申请收录', '/submit', '公开申请 + 收录规则全文') ?>
    <?= $nav('下载', '/download.html', '环境要求与三步安装') ?>
    <?= $nav('帮助文档', '/docs.html', '日常使用 / 主题 / 多语 / 运维') ?>
    <?= $nav('开发文档', '/developers.html', '给想二开/写插件的人') ?>
    <?= $nav('应用市场', '/market.html', '官方插件与内置能力') ?>
    <?= $nav('关于', '/about.html', '名字、理念与它在跑什么') ?>
    <?= $nav('加入与友链', '/join.html', '收录、徽标与互认') ?>
  </div>

  <div class="lnd-sec"><div class="lnd-h">Backstage</div><div class="lnd-t">后台能做什么</div></div>
  <div class="lnd-grid">
    <div class="lnd-item"><span class="ic">📝</span><b>文章 / 页面 / 多语</b><p>Markdown 写作、置顶与定时发布；多语译文成组管理。</p></div>
    <div class="lnd-item"><span class="ic">💬</span><b>评论与段评审核</b><p>先审后发；关键词/昵称/链接数黑名单防骚扰。</p></div>
    <div class="lnd-item"><span class="ic">🧩</span><b>插件与云市场</b><p>管理/停用插件；云市场一键安装与更新。</p></div>
    <div class="lnd-item"><span class="ic">🎨</span><b>主题与布局</b><p>页面宽度、强调色；各页面类型的右栏与功能块分别控制。</p></div>
    <div class="lnd-item"><span class="ic">📊</span><b>仪表盘与统计</b><p>内容数、评论待审、阅读排行；阅读量日去重口径。</p></div>
    <div class="lnd-item"><span class="ic">🛟</span><b>工具与升级</b><p>缓存清理、全库备份/还原、一键升级（自动备份+迁移留痕）。</p></div>
  </div>

  <div class="lnd-cta">
    <b>现在，它正在跑：已收录 <?= $circleBlogs ?> 个博客，温柔聚合 <?= $circlePosts ?> 篇文章。</b>
    <a class="btn" href="/download.html">下载 YeCha 开始你的站</a>
    <a class="btn ghost" href="/submit">把博客收进圈里</a>
  </div>
</div>
