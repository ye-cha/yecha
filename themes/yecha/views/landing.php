<?php
/** yecha.org 首页 · emlog 式官网布局（landing）
 * hero(产品大图+按钮) → 功能特性 → 安装部署 → 应用生态 → 更新动态 → CTA；
 * 「博客圈」为独立模块，仅 home.show_circle=1 时首页底部有入口卡。 */
$name = e(site_name());
$posts = $posts ?? [];
$circleBlogs = (int)($circle_blogs ?? 0);
$circlePosts = (int)($circle_posts ?? 0);
$showCircle = (string)get_option('home.show_circle', '0') === '1';
$gh = 'https://github.com/ye-cha/yecha';
$shot = '/uploads/2026/09/shots/';
$nav = function (string $label, string $href, string $hint = ''): string {
    return '<a class="yc-card" href="' . e($href) . '"><b>' . e($label) . '</b>' . ($hint !== '' ? '<span>' . e($hint) . '</span>' : '') . '<em>→</em></a>';
};
$postLi = '';
foreach ($posts as $p) {
    $postLi .= '<li><a href="/' . rawurlencode((string)$p['slug']) . '.html">' . e((string)$p['title']) . '</a><span>' . e(substr((string)($p['published_at'] ?? ''), 0, 10)) . '</span></li>';
}
if ($postLi === '') { $postLi = '<li class="muted">发布中的介绍与更新会出现在这里。</li>'; }
?>
<style>
.yc{max-width:1120px;margin:0 auto;padding:0 20px}
.yc-ver{display:inline-flex;gap:8px;align-items:center;font-size:12.5px;color:#6f767e;margin-bottom:10px}
.yc-ver b{background:#b3541e;color:#fff;border-radius:999px;padding:2px 10px;font-size:11.5px;font-weight:700}
.yc-hero{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,0.95fr);gap:34px;align-items:center;padding:34px 0 18px}
.yc-hero h1{font-size:36px;line-height:1.3;margin:0 0 14px;letter-spacing:.5px}
.yc-hero .lead{color:#565b61;font-size:15.5px;line-height:2;margin:0 0 20px}
.yc-cta{display:flex;flex-wrap:wrap;gap:10px}
.btn{display:inline-block;background:#b3541e;color:#fff;border-radius:999px;padding:11px 22px;font-size:14.5px;font-weight:600;text-decoration:none}
.btn.ghost{background:transparent;color:#1f2328;border:1px solid #e0c9b6}
.btn.ghost:hover{background:#fbf3e9}
.yc-hero-img img{width:100%;height:auto;border:1px solid #e9e2d6;border-radius:14px;box-shadow:0 14px 34px rgba(0,0,0,.08)}
.yc-hero-img figcaption{color:#9a9a90;font-size:12px;text-align:center;margin-top:6px}
.yc-badges{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0 0}
.yc-badge{border:1px solid #e6e6e2;background:#fff;border-radius:999px;padding:5px 13px;font-size:12.5px;color:#565b61}
.yc-sec{margin:40px 0 4px}
.yc-h{font-size:13px;letter-spacing:2px;color:#b3541e;font-weight:800;text-transform:uppercase}
.yc-t{font-size:25px;font-weight:800;margin:2px 0 6px}
.yc-s{color:#6f767e;font-size:14px;margin:0 0 16px}
.yc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.yc-item{border:1px solid #e9e2d6;border-radius:14px;padding:16px;background:#fff}
.yc-item .ic{font-size:22px}
.yc-item b{display:block;font-size:15.5px;margin:8px 0 4px}
.yc-item p{margin:0;color:#6f767e;font-size:13px;line-height:1.85}
.yc-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}
.yc-card{border:1px solid #e9e2d6;border-radius:14px;padding:16px;background:#fbfaf7;text-decoration:none;position:relative;display:block}
.yc-card b{font-size:15px}
.yc-card span{color:#6f767e;font-size:12.5px;line-height:1.7;display:block;margin-top:3px}
.yc-card em{position:absolute;right:14px;top:12px;color:#b3541e;font-style:normal}
.yc-card:hover{border-color:#d8b08f}
.yc-ul{list-style:none;margin:0;padding:0;line-height:2}
.yc-ul li{display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed #eeece6;padding:4px 0}
.yc-ul a{color:#1f2328;text-decoration:none;font-weight:600}
.yc-ul a:hover{color:#b3541e}
.yc-ul span{color:#9a9a90;font-size:12px}
.yc-cta2{margin:42px 0 0;text-align:center;padding:24px;background:linear-gradient(180deg,#fdf6ef,#fbf9f5);border:1px solid #eadfce;border-radius:18px}
.yc-cta2 b{font-size:18px;display:block;margin-bottom:14px}
.yc-mod{margin-top:34px;border:1px dashed #d8c4a8;border-radius:16px;padding:18px 20px;background:#fdfaf5}
.yc-mod b{color:#1f2328}
.yc-mod a{color:#b3541e;font-weight:700}
.yc-mod p{color:#565b61;font-size:13.5px;line-height:1.95;margin:8px 0 0}
@media(max-width:900px){.yc-hero{grid-template-columns:1fr;gap:20px;padding-top:22px}.yc-hero h1{font-size:29px}.yc-hero-img{order:2}}
</style>
<div class="yc">
  <div class="yc-hero">
    <div class="yc-hero-txt">
      <span class="yc-ver"><b>YeCha 1.0 Beta</b> · MIT 开源 · 上次更新 2026-09-06</span>
      <h1>轻量开源博客系统</h1>
      <p class="lead">YeCha（夜叉）是一款纯 PHP + MySQL 的开源博客与内容建站系统——没有框架、没有 Node、没有 Redis，一个 PHP 进程就能跑起整站。Markdown 创作、多语言、插件与主题云市场、全页缓存、一键升级，开箱即用；适合个人博客、技术笔记、文档站、小团队官网与轻社区。</p>
      <div class="yc-cta">
        <a class="btn" href="/download.html">下载 YeCha 1.0 Beta</a>
        <a class="btn ghost" href="/docs.html">安装部署文档</a>
        <a class="btn ghost" href="/demo">在线体验</a>
        <a class="btn ghost" href="<?= e($gh) ?>" rel="noopener" target="_blank">GitHub →</a>
      </div>
      <div class="yc-badges">
        <span class="yc-badge">⚡ 全页缓存 · 毫秒级首屏</span>
        <span class="yc-badge">✍️ Markdown 创作</span>
        <span class="yc-badge">🌐 多语言开箱即用</span>
        <span class="yc-badge">🧩 云市场</span>
        <span class="yc-badge">📦 MIT 开源</span>
      </div>
    </div>
    <figure class="yc-hero-img">
      <img src="<?= e($shot . 'teadoc-home.png') ?>" alt="YeCha 站点界面示意">
      <figcaption>运行在 YeCha 之上的 TeaDoc 主题站点（实时截图）</figcaption>
    </figure>
  </div>

  <div class="yc-sec"><div class="yc-h">Features</div><div class="yc-t">功能特性</div><p class="yc-s">少依赖、可读源码，把内容与体验的掌控权还给站长。</p></div>
  <div class="yc-grid">
    <div class="yc-item"><span class="ic">⚡</span><b>一个进程跑全站</b><p>纯 PHP+MySQL，无框架、无 Composer 依赖；全页缓存下公开页毫秒级返回，低配小机也能轻松带动。</p></div>
    <div class="yc-item"><span class="ic">✍️</span><b>Markdown 专注创作</b><p>Markdown ↔ 所见即所得双模式 + 分屏预览；置顶、定时发布、全文搜索；评论先审后发、防骚扰内建。</p></div>
    <div class="yc-item"><span class="ic">🌐</span><b>多语言开箱即用</b><p>内容按语言组成组、/en/ 等前缀切换；术语别名与界面语言包联动，官方预置 9 种语言。</p></div>
    <div class="yc-item"><span class="ic">🧩</span><b>插件与主题云市场</b><p>后台一键安装与更新：SHA-256 校验 → 安全解包 → 启用并自动跑幂等迁移；插件可注册页面/后台/侧栏/钩子。</p></div>
    <div class="yc-item"><span class="ic">🛠️</span><b>数据自主 · 一键升级</b><p>全库备份/还原；升级前自动备份、迁移留痕；阅读量同访客自然日去重、计数可复核。</p></div>
    <div class="yc-item"><span class="ic">🔓</span><b>开源 MIT · 可二开</b><p>代码结构清晰可直接阅读；主题与页面布局后台可视化调整，暗色与移动端完善，随时换风格。</p></div>
  </div>

  <div class="yc-sec"><div class="yc-h">Install</div><div class="yc-t">安装部署</div><p class="yc-s">一次安装向导即可建站，文档覆盖主流服务器环境。</p></div>
  <div class="yc-grid">
    <div class="yc-item"><span class="ic">🧰</span><b>宝塔 / aaPanel</b><p>PHP 8.1+（pdo_mysql/mbstring/simplexml/curl）、MySQL 5.7+，Nginx/Apache 伪静态与安全规则示例见部署清单。<a href="/docs.html">查看教程 →</a></p></div>
    <div class="yc-item"><span class="ic">🐳</span><b>Docker 本地体验</b><p>仓库自带编排：`docker compose up -d` 即起 nginx + php + mysql 演示环境，数分钟上手。<a href="/docs.html">查看教程 →</a></p></div>
    <div class="yc-item"><span class="ic">🚀</span><b>手动部署（三步）</b><p>① 解压到站点目录 ② 改写 config.inc.php 数据库信息 ③ 访问站点根进入一次性安装向导。<a href="/download.html">环境要求 →</a></p></div>
  </div>

  <div class="yc-sec"><div class="yc-h">Ecosystem</div><div class="yc-t">应用生态</div><p class="yc-s">官方主题与可独立安装的模块，均在云市场一键获取。</p></div>
  <div class="yc-cards">
    <a class="yc-card" href="/teadoc.html"><img src="<?= e($shot . 'teadoc-article.png') ?>" alt="TeaDoc" style="width:100%;border-radius:8px;margin-bottom:8px"><b>TeaDoc（主题）</b><span>文档式主题：大 Hero + 学习目录，内容页三栏阅读，双色系 × 明暗。</span><em>→</em></a>
    <a class="yc-card" href="/warmpaper.html"><img src="<?= e($shot . 'warmpaper-home.png') ?>" alt="WarmPaper" style="width:100%;border-radius:8px;margin-bottom:8px"><b>WarmPaper 暖纸（主题）</b><span>暖米纸底 + 赭红强调 + 衬线标题，自带暗色，即装即用。</span><em>→</em></a>
    <a class="yc-card" href="/blog-circle.html"><span style="font-size:34px">🪐</span><b>博客圈（独立模块）</b><span>友链 + 博客收录 + 文章流 + 月度精选 + 流量回执；与 YeCha 本体解耦，按需安装。</span><em>→</em></a>
  </div>
  <div style="margin-top:14px"><?= $nav('探索全部应用', '/store', '主题 / 模块卡片市场') ?></div>

  <div class="yc-sec"><div class="yc-h">News</div><div class="yc-t">更新动态</div><p class="yc-s">真实记录，从 v1.0 Beta 开始按时间追加。</p></div>
  <ul class="yc-ul"><?= $postLi ?></ul>

  <div class="yc-cta2">
    <b>一个 PHP 进程就能跑起来的博客，现在就开始。</b>
    <a class="btn" href="/download.html">下载 YeCha 1.0 Beta</a>
    <a class="btn ghost" href="<?= e($gh) ?>" rel="noopener" target="_blank">在 GitHub 上查看源码</a>
    <?php if ($showCircle): ?><a class="btn ghost" href="/blog-circle.html">去看看博客圈</a><?php endif; ?>
  </div>
</div>
