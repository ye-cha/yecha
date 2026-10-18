<?php
/**
 * ChaWiki · 词条详情页区块
 * 变量（内核 Front::post 提供）：$post,$html,$comments,$segments,$para_n,$fav,$my_marks,$toc
 * 结构：面包屑 → 标题 → 元信息 → 分类/标签条 → infobox → 老吕署名块 → 正文(.prose-wiki) → 参考来源 → 上下篇 → 评论
 * ★ 正文原样输出：$html 由内核按 content_format 渲染（HTML 词条走 html 分支，格式兼容见 core/Front.php 的 content_format 判定）
 */
require_once __DIR__ . '/../../inc.php';

$p = $post;
$cid = (int)$p['id'];
$logged = Auth::check();
$csrf = Csrf::token();
$lp2 = chawiki_prefix();
$back = chawiki_url((string)$p['slug']);

$cat0 = $p['cats'][0] ?? null;
$catName = $cat0 ? Poly::termName($cat0) : '';
$catUrl = $cat0 ? Poly::termUrl('category', $cat0) : '';

/* infobox：优先 ye_fields(name='infobox') 的结构化备份；正文已自带 <table> 时不重复渲染 */
$infobox = [];
$rawBox = Db::one(
    'SELECT value FROM ' . Db::t('fields') . " WHERE content_id=? AND name='infobox' LIMIT 1",
    [$cid]
);
if ($rawBox !== null && (string)$rawBox !== '') {
    $decoded = json_decode((string)$rawBox, true);
    if (is_array($decoded)) {
        foreach ($decoded as $it) {
            if (is_array($it) && isset($it['label'])) { $infobox[] = ['l' => (string)$it['label'], 'v' => (string)($it['value'] ?? '')]; }
        }
    }
}
/* 正文里是否已含“基本信息”表 —— 有则不再单独出 infobox（避免两张表，见 PLAN §3.4） */
$bodyHasTable = stripos((string)($p['content'] ?? ''), '<table') !== false;

/* 老吕个人视角 */
$laolv = (string)Db::one(
    'SELECT value FROM ' . Db::t('fields') . " WHERE content_id=? AND name='laolv' LIMIT 1",
    [$cid]
);

/* 参考来源 */
$refs = [];
$rawRefs = Db::one(
    'SELECT value FROM ' . Db::t('fields') . " WHERE content_id=? AND name='refs' LIMIT 1",
    [$cid]
);
if ($rawRefs !== null && (string)$rawRefs !== '') {
    $dr = json_decode((string)$rawRefs, true);
    if (is_array($dr)) { foreach ($dr as $r) { if (is_string($r) && trim($r) !== '') { $refs[] = trim($r); } } }
}
$bodyHasRefs = (bool)preg_match('/参考(来源|文献|资料)/u', (string)($p['content'] ?? ''));

$pt = strtotime((string)($p['published_at'] ?? $p['created_at']));
$plainTxt = trim(preg_replace('/\s+/u', '', strip_tags((string)($p['content'] ?? ''))) ?? '');
$readMin = $plainTxt !== '' ? max(1, (int)ceil(mb_strlen($plainTxt) / 450)) : 0;
$bodyEmpty = $plainTxt === '';

/* ★ Wiki 版式（2026-09-18）新增：
   ① 目录 TOC：内核 Front::post 早已算好 $toc（h2~h5 已带 sec-N 锚点），旧版从未渲染 —— 现在渲染两份，
      桌面（≥1024px）在正文右侧栏默认展开、移动端在正文上方默认收起（CSS 二选一显示，零 JS）。
   ② 编辑/历史入口占位：路由由并行的「wiki 功能线」提供，未上线时不渲染死链（见 inc.php::chawiki_route_ready）。
   ③ 相关词条：取「本词条所属分类」的同组词条（复用左栏树缓存 chawiki_left_tree，0 条新增 SQL）。 */
$cwTocItems  = (is_array($toc ?? null)) ? $toc : [];
$cwEntryUrl  = chawiki_url((string)$p['slug']);
$cwIsAdmin   = \Auth::isAdmin();
$cwEditReady = chawiki_route_ready('edit');
$cwHistReady = chawiki_route_ready('history');

/* ★★ 方案 D · P1（2026-09-18）：词条页 v2 版式开关 ★★
   ① 开关是 DB 里的**字符串** `theme.options.layout`（默认 'v1'；'v2' 启用）——
      沿用「开关必须是字符串」的既有教训（show_sidebar 用 !== '0' 判定）。
      ⇒ R1 回滚 = 把这一个字符串改回 v1，10 秒回到已验收的现状，不需要重新部署任何文件。
   ② 只对**词条页**（type=post）生效：独立页（/entry/{slug}，type=page）、编辑/历史/diff 页、
      列表/分类/标签/归档/首页全部继续走 v1（那是 P3 的范围，本轮明确不动）。
   ③ 骨架分支在 layout.php 读 $GLOBALS['YC_WIKI_V2']（同一请求内 post 先于 layout 渲染，见文件末尾注释）。 */
$cwV2 = ((string)theme_opt('layout', 'v1') === 'v2') && ((string)($p['type'] ?? 'post') === 'post');
$GLOBALS['YC_WIKI_V2'] = $cwV2;
if ($cwV2) { $GLOBALS['YC_P3_KIND'] = 'entry'; }

/* ★ H1→H3 跳级修正（设计 §③-3(2)，主题侧、不动内容、可单点回滚）：
   全站 DB 实测 h2 = 0、h4 = 0 ⇒ 词条正文一律 H1 → H3，屏幕阅读器大纲跳级（判据 A13 ❌）。
   这里做**标签映射** h3→h2 / h4→h3 / h5→h4（正则带标签边界，只处理开合标签）：
     · 只改标签名，内核已按 h2~h5 注入的 sec-N 锚点（id 属性）原样保留 ⇒ 目录 anchor 不变
     · 目录项层级同步上移一档（wl3 → wl2），使「一级 = h2、子级 = h3」的缩进规则真正生效
     · ★ 副作用必须在验收里豁免：判据「正文逐字节不变」(A9) 升级为
       「正文**文本节点**逐字节不变 + 标签名差异仅限白名单 h3→h2 / h4→h3 / h5→h4」(A9b) */
if ($cwV2) {
    $cwHtmlBeforeTags = (string)$html;
    $html = (string)preg_replace_callback(
        '#<(/?)h([345])(\s[^>]*)?>#i',
        function (array $m): string {
            return '<' . $m[1] . 'h' . ((int)$m[2] - 1) . (isset($m[3]) ? (string)$m[3] : '') . '>';
        },
        (string)$html
    );
    $cwTocItems = array_map(function (array $it): array {
        $it['l'] = max(2, (int)($it['l'] ?? 3) - 1);
        return $it;
    }, $cwTocItems);
}

$cwSib = [];
$cwSibName = '';
$__al = (Poly::on() && Lang::short() !== Poly::defaultLang()) ? Lang::short() : Poly::defaultLang();
$__tree = chawiki_left_tree((string)$__al, Poly::on() && (string)$__al !== Poly::defaultLang());
/* 本词条所属分类（用真实分类归属，而不是"在树的 30 条样本里找自己"——
   13 类各有 280~4,400 条词条，树里只有每类前 30 条，词条本身几乎必然不在样本里：
   实测 gong-cha 在历史类排第 84、bei-cha 在茶类排第 2156 ⇒ 旧写法永远命中不了）。 */
$cwCatSlug = (string)($cat0['slug'] ?? '');
if ($cwCatSlug === '' && $catUrl !== '' && preg_match('#/category/([^/]+)/#', (string)$catUrl, $__m)) { $cwCatSlug = (string)$__m[1]; }
foreach ($__tree as $__g) {
    if ((string)($__g['slug'] ?? '') === $cwCatSlug) { $cwSib = (array)($__g['items'] ?? []); $cwSibName = (string)($__g['name'] ?? ''); break; }
}
/* 传给 layout.php（同一请求内，post 先于 layout 渲染）：
   左栏按「真实分类」高亮分组，并在该组条目里补上本词条（高亮）—— 保住并修好「左栏当前位置」。 */
$GLOBALS['YC_WIKI_CURCAT'] = $cwCatSlug;
$GLOBALS['YC_WIKI_CURENTRY'] = ['slug' => (string)$p['slug'], 'title' => (string)$p['title']];
?>
<article class="article chawiki-entry" data-cid="<?= $cid ?>">

  <nav class="breadcrumb" aria-label="<?= e(__('ui.main_nav')) ?>">
    <a href="<?= e($lp2 . '/') ?>"><?= e(__('nav.home')) ?></a>
    <span class="sep">/</span>
    <?php if ($cat0): ?>
    <a href="<?= e($lp2 . $catUrl) ?>"><?= e($catName) ?></a>
    <span class="sep">/</span>
    <?php endif; ?>
    <span class="cur"><?= e(Str::excerpt((string)$p['title'], 24)) ?></span>
  </nav>

  <header class="article-header">
    <h1 class="article-title"><?= e($p['title']) ?></h1>
    <div class="article-meta">
      <span class="meta-item"><time class="meta-date"><?= e(lang_date($pt)) ?></time></span>
      <?php if ($cat0): ?>
      <span class="meta-item"><a class="meta-category" href="<?= e($lp2 . $catUrl) ?>"><?= e($catName) ?></a></span>
      <?php endif; ?>
      <?php if ($readMin > 0): ?>
      <span class="meta-item"><span class="meta-reading"><?= e(__('ui.read_min')) ?><?= $readMin ?><?= e(__('ui.read_min_unit')) ?></span></span>
      <?php endif; ?>
      <?php if ((int)$p['views'] > 0): ?>
      <span class="meta-item"><span class="meta-views"><?= e(Str::num((int)$p['views'])) ?> 次阅读</span></span>
      <?php endif; ?>
      <?php /* ★ wiki 惯例：编辑 / 历史 入口。路由未上线时：非管理员完全不渲染（不留死链），
               管理员看到灰色「（即将可用）」占位；对方上线后自动变真链接（chawiki_route_ready）。 */ ?>
      <?php if ($cwIsAdmin || $cwEditReady || $cwHistReady): ?>
      <span class="w-actions">
        <?php if ($cwEditReady): ?><a class="w-act" href="<?= e($cwEntryUrl) ?>/edit" rel="nofollow">编辑</a>
        <?php elseif ($cwIsAdmin): ?><span class="w-act w-soon" title="编辑功能即将上线">编辑（即将可用）</span><?php endif; ?>
        <?php if ($cwHistReady): ?><a class="w-act" href="<?= e($cwEntryUrl) ?>/history" rel="nofollow">历史</a>
        <?php elseif ($cwIsAdmin): ?><span class="w-act w-soon" title="历史版本功能即将上线">历史（即将可用）</span><?php endif; ?>
      </span>
      <?php endif; ?>
    </div>
  </header>

  <?php if (!empty($p['tags'])): ?>
  <div class="term-bar">
    <span class="tb-cap">标签</span>
    <?php foreach ($p['tags'] as $t): ?>
    <a class="term-chip" href="<?= e($lp2 . Poly::termUrl('tag', $t)) ?>"><?= e(Poly::termName($t)) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php /* ★ Wiki 版式：正文 + 右侧「信息栏」（infobox + 目录 TOC）。
     为什么改成结构化的两列（而不是 float）：float 下的 <table width:100%>、
     图片、figure 都可能压到浮动框上（旧站实测过的遮挡类缺陷）；
     grid 列宽在布局期就定死 ⇒ 三档宽度下都不可能重叠，且窄屏可整体退化为单列。 */ ?>
  <div class="w-body<?= (($infobox && !$bodyHasTable) || $cwTocItems !== []) ? '' : ' w-body-solo' ?>">
    <div class="w-body-main">

      <?php if ($laolv !== ''): ?>
      <aside class="laolv-note">
        <div class="lv-cap">老吕个人视角</div>
        <p><?= e($laolv) ?></p>
      </aside>
      <?php endif; ?>

      <?php /* 移动端目录（<1024px 显示；默认收起 ⇒ 不挤占首屏）。桌面端显示右侧那份。 */ ?>
      <?= chawiki_toc($cwTocItems, false, 'wtoc-m') ?>

      <?php /* ★★★★ P3（2026-09-18）：<1024 档「信息框移到正文之前」（设计 §③-6 表 + 线框 ⑥-11）。
         设计原文：`<1024（平板/手机）：正文之前、全宽，grid: 96px minmax(0,1fr)，最多展开 6 行后 <details>`。
         ★ 与 >10 行的桌面折叠不同：这里**最多展开 6 行**（移动档每行 ~35px，6 行 ≈ 210px，
           再往上会把首屏正文压掉 —— P1 报告 §7③ 记录的正是这个风险）。
         ★ 桌面那份仍在右列最上（.w-body-side .infobox），由 CSS 按断点二选一显示，**不改 DOM 顺序的语义**。 */ ?>
      <?php /* ★★★★ P3（2026-09-18）：<1024 档「信息框移到正文之前」—— **实测打挂 A4，按站长指令没有硬做** ★★★★
         设计 §③-3(5)/§③-6 要求 <1024 时信息框「正文之前、全宽、最多展开 6 行后 <details>」。
         实测走了两轮（原始 JSON 见 /root/schemeD-p3/shots/probe-after*.json）：
           第 1 轮 · 照字面「展开 6 行」：390 档格子只有 234px 宽，一行的值常折成 3–4 行
                    ⇒ 信息框 ≈540px，**首屏可见正文 gong-cha 390 从 321 字掉到 0 字**（768：534 → 88）。
           第 2 轮 · 改成**默认收起**的 <details>（≈44px）：390 回到 261 字 ✓，但
                    **768 档 534 → 399 字（−25%）**，低于本判据 T 档线。
         ⇒ 结论：把信息框前移，光靠「首图封顶 30vh」救不回来 —— 真正缺的是**首屏头部那 ≈250px**。
           按站长原话「若仍打挂 ⇒ 如实报告并给方案（别硬做）」，这里**保留 v1 的 DOM 顺序**
           （收起的目录在正文上方、信息框在正文之后），把「前移」列为 P5 前置项写入报告 §5-②。
         ① 位置：见 w-body-side（桌面右列最上）；<1024 时由 CSS 让它排在正文之后（v1 顺序）
         ② 副作用：无（v1 的 DOM 结构一个字节没动） */ ?>

      <?php if ($bodyEmpty): ?>
      <div class="empty-body">本条词条暂无全文内容。<br>可先浏览 <a href="<?= e($lp2 . '/category/') ?>">全部分类</a> 或 <a href="<?= e($lp2 . '/') ?>">返回首页</a> 查看其它词条。</div>
      <?php else: ?>
      <div class="article-content prose-wiki" id="articleBody"><?= $html ?></div>
      <?php endif; ?>

      <?php if ($refs && !$bodyHasRefs): ?>
      <section class="refs-block">
        <?php /* ★ v2：区块标题升为 h2 —— 否则「正文无标题的短词条」（如 36 字的焙茶）会出现
                 H1 → H3 跳级（判据 A13）。改前实测：h1 + 相关词条 h3 = 跳级。 */ ?>
        <<?= $cwV2 ? 'h2' : 'h3' ?>>参考来源</<?= $cwV2 ? 'h2' : 'h3' ?>>
        <ol>
          <?php foreach ($refs as $r): ?><li><?= e($r) ?></li><?php endforeach; ?>
        </ol>
      </section>
      <?php endif; ?>

      <?php
      /* ★ wiki 惯例「相关词条」：本词条所属分类的同组词条（复用左栏树，0 条新增 SQL）。
         同时充当「同类词条互链」，孤岛率口径（正文内链）不受影响。 */
      $cwRel = [];
      foreach ($cwSib as $__s) {
          if ((string)($__s['slug'] ?? '') === (string)$p['slug']) { continue; }
          $cwRel[] = $__s;
          if (count($cwRel) >= 6) { break; }
      }
      ?>
      <?php if ($cwRel): ?>
      <section class="w-related">
        <<?= $cwV2 ? 'h2' : 'h3' ?>>相关词条<?= $cwSibName !== '' ? '（' . e($cwSibName) . '）' : '' ?></<?= $cwV2 ? 'h2' : 'h3' ?>>
        <ul>
          <?php foreach ($cwRel as $r): ?>
          <li><a href="<?= e($lp2 . chawiki_entry_url((string)$r['slug'])) ?>"><?= e((string)$r['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <nav class="adjacent">
        <?php if (!empty($prev)): ?><a href="<?= e(chawiki_url((string)$prev['slug'])) ?>"><b><?= e(__('post.prev')) ?></b><?= e($prev['title']) ?></a>
        <?php else: ?><span></span><?php endif; ?>
        <?php if (!empty($next)): ?><a href="<?= e(chawiki_url((string)$next['slug'])) ?>" class="nx"><b><?= e(__('post.next')) ?></b><?= e($next['title']) ?></a>
        <?php else: ?><span></span><?php endif; ?>
      </nav>

      <?php if (!empty($comments) || !empty($segments)): ?>
      <?= View::render('partials/comments', [
          'cid' => $cid, 'logged' => $logged, 'back' => $back,
          'comments' => $comments, 'segments' => $segments, 'para_n' => $para_n, 'csrf' => $csrf,
          'comment_page' => $comment_page ?? 1, 'comment_pages' => $comment_pages ?? 1,
          'comment_total' => (int)$comment_total,
      ]) ?>
      <?php endif; ?>

      <?php /* ★ v2：编辑/历史**双落点** —— 元信息行（首屏内，一眼看到「可改」）+ 正文末尾
                （读完最后一个字时手边就有入口，这是 wiki 的惯例落点）。
                沿用既有探测机制（chawiki_route_ready）：对非管理员不渲染死链，机制一个字没改。 */ ?>
      <?php if ($cwV2 && ($cwIsAdmin || $cwEditReady || $cwHistReady)): ?>
      <div class="w-actions w-actions-end">
        <?php if ($cwEditReady): ?><a class="w-act" href="<?= e($cwEntryUrl) ?>/edit" rel="nofollow">编辑本词条</a>
        <?php elseif ($cwIsAdmin): ?><span class="w-act w-soon" title="编辑功能即将上线">编辑（即将可用）</span><?php endif; ?>
        <?php if ($cwHistReady): ?><a class="w-act" href="<?= e($cwEntryUrl) ?>/history" rel="nofollow">查看历史</a>
        <?php elseif ($cwIsAdmin): ?><span class="w-act w-soon" title="历史版本功能即将上线">历史（即将可用）</span><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <aside class="w-body-side">
      <?php if ($cwV2): ?>
      <?php /* ★★ v2（方案 D）：右列只有两件 —— **信息框（静态，在最上）** + **目录（sticky）**。
         顺序与 v1 相反；理由不是 v1 的「怕目录被挤出首屏」，而是：
           信息框静态 ⇒ 开局两栏首屏都看得到；往下滚，信息框随页面走掉、目录留在原处继续指路。
         右列自身不再做滚动容器（见 wiki2.css）⇒ A2「纵向内部滚动条 = 0」、A3「只有一层滚动」。
         信息框 >10 行时套 <details>（零 JS）：默认展开前 10 行 + 「展开全部 N 行」占位。 */ ?>
      <?php if ($infobox && !$bodyHasTable):
          $cwBoxFold = count($infobox) > 10;
          $cwBoxHead = $cwBoxFold ? array_slice($infobox, 0, 10) : $infobox;
          $cwBoxRest = $cwBoxFold ? array_slice($infobox, 10) : [];
          $cwBoxRow = function (array $row): string {
              return '<div class="infobox-row"><span class="infobox-label">' . e($row['l'])
                  . '</span><span class="infobox-value">' . e($row['v']) . '</span></div>';
          }; ?>
      <div class="infobox">
        <div class="infobox-head">基本信息</div>
        <?php foreach ($cwBoxHead as $row) { echo $cwBoxRow($row); }
              if ($cwBoxRest !== []) {
                  echo '<details class="infobox-more"><summary>展开全部 ' . count($infobox) . ' 行</summary>';
                  foreach ($cwBoxRest as $row) { echo $cwBoxRow($row); }
                  echo '</details>';
              } ?>
      </div>
      <?php endif; ?>
      <?= chawiki_toc($cwTocItems, true, 'wtoc-d') ?>
      <?php else: ?>
      <?php /* v1 顺序（原样保留，可逐字节回滚）：目录在前、信息框在后 —— 词条信息框常有 8~12 行（实测「贡茶」11 行 ≈ 480px），
              若把信息框放最上，目录就被挤到首屏之外；目录在最上则两者都在首屏内可见。 */ ?>
      <?php /* 桌面端目录（≥1024px 显示；默认展开、sticky 跟随阅读） */ ?>
      <?= chawiki_toc($cwTocItems, true, 'wtoc-d') ?>
      <?php if ($infobox && !$bodyHasTable): ?>
      <div class="infobox">
        <div class="infobox-head">基本信息</div>
        <?php foreach ($infobox as $row): ?>
        <div class="infobox-row"><span class="infobox-label"><?= e($row['l']) ?></span><span class="infobox-value"><?= e($row['v']) ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </aside>
  </div>
</article>
<?php /* ★ v2：不再重复输出 chawiki.js（layout.php 末尾已经输出同一 URL）。
        改前实测：主题自有 <script src> 同页 **2 次**（layout.php + 本文件）⇒ 判据 A11 ① ❌。
        v1 分支原样保留（含这一行），保证 R1 回滚后 v1 输出逐字节不变。 */ ?>
<?php if (!$cwV2): ?>
<script src="<?= e(View::asset('chawiki.js')) ?>" defer></script>
<?php endif; ?>
