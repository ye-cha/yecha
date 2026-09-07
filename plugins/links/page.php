<?php
declare(strict_types=1);
// YeCha 博客收录圈（portal）：统一门户壳 + 广场/文章流/月度精选/申请/回执/徽标/采集说明
// 路由：/links /circle /selected /submit /go /random /stats /certificate /collection
require_once __DIR__ . '/data.php';

/* ============ 门户样式与外壳 ============ */

function yc_portal_css(): string
{
    return '<style>'
        // 门户外壳
        . '.yc-p{font-family:inherit}'
        . '.yc-mast{border:1px solid var(--line,#e6e6e2);border-radius:16px;padding:18px 20px 0;background:linear-gradient(180deg,#fff 0%,#fbf9f5 100%);margin-bottom:18px}'
        . '.yc-mast-top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}'
        . '.yc-brand{font-size:19px;font-weight:800;letter-spacing:.5px}'
        . '.yc-brand small{display:block;font-weight:400;font-size:12.5px;color:#6f767e;margin-top:2px;letter-spacing:0}'
        . '.yc-mast-actions{display:flex;gap:8px}'
        . '.yc-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:14px 0 0;border-top:1px solid #eeece6;padding-top:12px}'
        . '.yc-tab{padding:7px 16px;border-radius:999px;color:#4a5560;text-decoration:none;font-size:14px;border:1px solid transparent}'
        . '.yc-tab:hover{color:#b3541e;background:#fdf3ea}'
        . '.yc-tab.on{background:#b3541e;color:#fff;font-weight:600}'
        . '.yc-hero{max-width:none}'
        . '.yc-hero h1{font-size:30px;line-height:1.35;margin:18px 0 8px;letter-spacing:.5px}'
        . '.yc-hero .sub{color:#6f767e;font-size:15px;line-height:1.9;margin:0}'
        . '.yc-hero .cta{margin:14px 0 2px;display:flex;flex-wrap:wrap;gap:10px;align-items:center}'
        . '.yc-foot{margin-top:26px;border-top:1px solid #eeece6;padding-top:14px;color:#9a9a90;font-size:12.5px;line-height:2}'
        . '.yc-foot a{color:#6f767e}'
        // 统计与区块
        . '.yc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:4px 0 16px}'
        . '.yc-stat{background:#fff;border:1px solid var(--line,#e6e6e2);border-radius:12px;padding:10px 14px}'
        . '.yc-stat b{font-size:21px;display:block}'
        . '.yc-stat span{color:#6f767e;font-size:12px}'
        . '.yc-sec{margin:22px 0 6px}'
        . '.yc-h2{font-size:17px;font-weight:700;padding-left:10px;border-left:3px solid #b3541e;display:flex;align-items:baseline;gap:10px}'
        . '.yc-h2 small{font-weight:400;color:#9a9a90;font-size:12.5px}'
        . '.yc-toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:12px;border:1px solid #eeece6;border-radius:12px;background:#fff;margin:4px 0 14px}'
        . '.yc-toolbar input[type=text]{border:1px solid #d9dee3;border-radius:999px;padding:7px 14px;font:inherit;flex:1;min-width:180px}'
        . '.yc-toolbar select{border:1px solid #d9dee3;border-radius:999px;padding:7px 10px;font:inherit;background:#fff}'
        . '.yc-catnav{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 14px;align-items:center}'
        . '.yc-navcap{color:#9a9a90;font-size:12.5px;letter-spacing:1px}'
        . '.yc-pill{border:1px solid #d9dee3;color:#1f2328;border-radius:999px;padding:4px 14px;font-size:13px;text-decoration:none;background:#fff}'
        . '.yc-pill:hover{border-color:#b3541e;color:#b3541e}'
        . '.yc-pill.on{background:#b3541e;border-color:#b3541e;color:#fff;font-weight:600}'
        // 列表/卡片
        . '.yc-list{display:flex;flex-direction:column;gap:10px}'
        . '.yc-row{display:flex;gap:14px;align-items:center;border:1px solid var(--line,#e6e6e2);border-radius:14px;padding:12px 14px;background:#fff;transition:box-shadow .12s,transform .12s}'
        . '.yc-row:hover{box-shadow:0 6px 18px rgba(0,0,0,.06);transform:translateY(-1px)}'
        . '.yc-ava{width:46px;height:46px;border-radius:10px;flex:none;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:20px;background:#fdf3ea;color:#b3541e;object-fit:contain}'
        . '.yc-main{flex:1;min-width:0}'
        . '.yc-name{font-size:15.5px;font-weight:700}'
        . '.yc-name a{text-decoration:none}.yc-name a:hover{color:#b3541e}'
        . '.yc-desc{color:#6f767e;font-size:12.5px;margin-top:2px}'
        . '.yc-meta{color:#6f767e;font-size:12.5px;line-height:1.9}'
        . '.yc-chips{display:flex;flex-wrap:wrap;gap:4px;margin:3px 0}'
        . '.yc-eng{border:1px solid #e0c9b6;background:#fdf6ef;color:#8a5a2b;border-radius:999px;padding:0 8px;font-size:11px;line-height:1.7}'
        . '.yc-grp{border:1px solid #d9dee3;color:#4a5560;border-radius:999px;padding:0 8px;font-size:11px;line-height:1.7}'
        . '.yc-rss{border:1px solid #b8d4c0;background:#f2f8f2;color:#2e6b34;border-radius:999px;padding:0 8px;font-size:11px;line-height:1.7}'
        . '.yc-rss a{text-decoration:none}'
        . '.yc-stdot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:4px;vertical-align:1px}'
        . '.yc-acts{flex:none;display:flex;flex-direction:column;gap:6px;align-items:flex-end}'
        // 文章流/月度
        . '.yc-feed li{padding:13px 2px;border-bottom:1px dashed #eeece6;list-style:none}'
        . '.yc-feed{list-style:none;margin:0;padding:0}'
        . '.yc-feed a.t{font-size:16px;font-weight:600;text-decoration:none;line-height:1.5}'
        . '.yc-feed a.t:hover{color:#b3541e}'
        . '.yc-feed .s{color:#6f767e;font-size:13px;margin-top:2px}'
        . '.yc-feed .m{color:#9a9a90;font-size:12px;margin-top:4px}'
        . '.yc-star{color:#b3541e}'
        . '.yc-sub{margin-top:20px}'
        . '.yc-grid2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:22px}'
        // 优点展示
        . '.yc-whyh{font-size:14px;letter-spacing:2px;color:#b3541e;font-weight:800;margin:20px 0 2px}'
        . '.yc-why-title{font-size:22px;font-weight:800;margin:2px 0 12px}'
        . '.yc-feats{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin:6px 0 10px}'
        . '.yc-feat{border:1px solid var(--line,#e6e6e2);border-radius:16px;padding:18px;background:#fff}'
        . '.yc-feat:hover{box-shadow:0 8px 22px rgba(0,0,0,.06)}'
        . '.yc-feat .ic{font-size:24px;line-height:1}'
        . '.yc-feat b{display:block;margin:10px 0 4px;font-size:15.5px}'
        . '.yc-feat p{margin:0;color:#6f767e;font-size:13px;line-height:1.85}'
        . '.yc-ctab{margin:22px 0 6px;text-align:center;padding:20px;background:linear-gradient(180deg,#fdf6ef,#fbf9f5);border:1px solid #eadfce;border-radius:18px}'
        . '.yc-ctab b{font-size:17px;display:block;margin-bottom:10px}'
        . '.yc-ctab .btn{text-decoration:none}'
        . '@media(max-width:640px){.yc-hero h1{font-size:24px}}'
        . '.yc-card{border:1px solid var(--line,#e6e6e2);border-radius:14px;padding:16px 18px;background:#fff}'
        . '@media(max-width:820px){.yc-grid2{grid-template-columns:1fr}.yc-acts{flex-direction:row;width:100%;justify-content:flex-end}.yc-row{flex-wrap:wrap}}'
        . '</style>';
}

/** 门户外壳：页眉band + 页签 + 主体 + 页脚 */
function yc_portal_shell(string $active, string $inner): string
{
    $tabs = [
        'links' => ['收录广场', '/links'],
        'circle' => ['文章流', '/circle'],
        'selected' => ['月度精选', '/selected'],
        'submit' => ['申请收录', '/submit'],
    ];
    $tabHtml = '';
    foreach ($tabs as $k => $v) {
        $tabHtml .= '<a class="yc-tab' . ($k === $active ? ' on' : '') . '" href="' . $v[1] . '">' . $v[0] . '</a>';
    }
    return yc_portal_css()
        . '<div class="yc-p">'
        . '<div class="yc-mast">'
        . '<div class="yc-mast-top">'
        . '<div class="yc-brand">YeCha 收录圈<small>还在更新的中文独立博客，都值得被看见 —— 不转载、不镜像、不抢流量、不做热榜游戏</small></div>'
        . '<div class="yc-mast-actions"><a class="btn ghost" href="/random">🎲 随机串门</a><a class="btn" href="/submit" style="text-decoration:none;">＋ 申请收录</a></div>'
        . '</div>'
        . '<nav class="yc-tabs">' . $tabHtml . '</nav>'
        . '</div>'
        . $inner
        . '<footer class="yc-foot">圈内导航：<a href="/links">收录广场</a> · <a href="/circle">文章流</a> · <a href="/selected">月度精选</a> · <a href="/random">随机串门</a> · <a href="/submit">申请收录</a> · <a href="/collection">采集与互认</a><br>'
        . '本圈只做一件事：收录「人写的、还在更新的中文独立博客」。阅读与点击都导向你的原站；流量回执按“同一访客自然日计 1 次”诚实统计。站长人工把关，收录不代表对内容立场背书。</footer>'
        . '</div>';
}

/* ============ /links 收录广场 ============ */

/** 优点展示（门户为什么值得逛/值得加入） */
function yc_links_features_html(): string
{
    $f = function (string $ic, string $title, string $desc): string {
        return '<div class="yc-feat"><span class="ic">' . $ic . '</span><b>' . $title . '</b><p>' . $desc . '</p></div>';
    };
    return '<div class="yc-whyh">这圈好在哪</div><div class="yc-why-title">对读者：一个不打扰的发现入口；对博主：看得见回报的收录</div>'
        . '<div class="yc-feats">'
        . $f('📊', '流量回执，一笔笔可查', '每一行都标着近 30 天被带走多少真实访客，口径诚实（同一访客自然日计 1 次），站主可见、可导出 CSV，不做自说自话的访问量。')
        . $f('🌿', '温柔聚合，不镜像全文', '只收标题、摘要与原文链接，每小时自动更新；点开就回你的原站。不缓存正文、不劫流量、不追读者。')
        . $f('🩺', '机器体检，状态透明', '每 6 小时体检一次站点可达与 RSS：用的什么程序、多久没更新、还活着吗——列表上一目了然，异常先标注再处理。')
        . $f('🤝', '自由互认，互相成全', '通过收录即可领取「已收录」徽标（HTML/SVG）；本站所有出站都带 via=yecha.org 来源参数，互认而不互吞。')
        . '</div>';
}

function yc_links_cta_html(): string
{
    return '<div class="yc-ctab"><b>想让自己的博客，被“还在认真更新的人”看见？</b>'
        . '<a class="btn" href="/submit">＋ 申请收录你的博客</a>　'
        . '<a class="btn ghost" href="/random">🎲 先随机串门感受一下</a>'
        . '<p style="color:#8a8a82;font-size:12.5px;margin:10px 0 0;">提交后 48–72 小时人工审核 · 收录规则在申请页写得很清楚 · 退出也随时、且不会悄悄消失</p></div>';
}

function yc_links_plaza_html(): string
{
    $allowedEng = [];
    foreach (yc_links_engines() as $grp) { foreach (array_keys($grp) as $k) { $allowedEng[] = $k; } }
    $eng = trim((string)($_GET['engine'] ?? ''));
    if (!in_array($eng, $allowedEng, true)) { $eng = ''; }
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
    $sort = (string)($_GET['sort'] ?? '');
    if (!in_array($sort, ['updated', 'created', 'clicks', 'name'], true)) { $sort = ''; }
    $flat = ($q !== '' || $sort !== '');

    $rows = Db::rows('SELECT l.*, g.name AS grp FROM ' . Db::t('links') . ' l LEFT JOIN ' . Db::t('link_groups') . ' g ON g.id=l.group_id WHERE l.status=\'show\' ORDER BY l.sort ASC, l.id ASC LIMIT 600');
    $all = [];
    foreach ($rows as $r) {
        if ($eng !== '' && (string)($r['engine'] ?? '') !== $eng) { continue; }
        if ($q !== '' && mb_stripos((string)$r['name'] . ' ' . (string)$r['url'] . ' ' . (string)$r['description'], $q) === false) { continue; }
        $all[] = $r;
    }
    $ids = array_map(fn($r) => (int)$r['id'], $all);
    $counts = $ids ? yc_links_click_map($ids, 30) : [];
    $clickTotal = array_sum($counts);
    $postsMap = [];
    $postsTotal = 0;
    if (Db::rows('SHOW TABLES LIKE "' . Db::t('circle_posts') . '"')) {
        $postsTotal = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('circle_posts'));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::rows('SELECT link_id, COUNT(*) n FROM ' . Db::t('circle_posts') . ' WHERE link_id IN (' . $ph . ') GROUP BY link_id', $ids) as $r) { $postsMap[(int)$r['link_id']] = (int)$r['n']; }
        }
    }
    if ($sort === 'created') { usort($all, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at'])); }
    elseif ($sort === 'clicks') { usort($all, fn($a, $b) => ($counts[(int)$b['id']] ?? 0) <=> ($counts[(int)$a['id']] ?? 0)); }
    elseif ($sort === 'name') { usort($all, fn($a, $b) => strcmp(mb_strtolower((string)$a['name']), mb_strtolower((string)$b['name']))); }
    elseif ($sort === 'updated') { usort($all, fn($a, $b) => [empty($a['last_post_at']) ? 1 : 0, strtotime((string)$a['last_post_at'])] <=> [empty($b['last_post_at']) ? 1 : 0, strtotime((string)$b['last_post_at'])]); $all = array_reverse($all); }

    $row = function (array $l, bool $withGrp) use ($counts, $postsMap): string {
        $name = (string)$l['name'];
        $ava = (string)$l['logo'] !== '' ? '<img class="yc-ava" src="' . e($l['logo']) . '" alt="" loading="lazy">' : '<span class="yc-ava">' . e(mb_substr($name, 0, 1)) . '</span>';
        $chips = '';
        $eng = (string)($l['engine'] ?? '');
        if ($eng !== '') { $chips .= '<span class="yc-eng">' . e(yc_links_engine_label($eng)) . '</span>'; }
        if ($withGrp && (string)($l['grp'] ?? '') !== '') { $chips .= '<span class="yc-grp">' . e($l['grp']) . '</span>'; }
        if (trim((string)($l['rss'] ?? '')) !== '') { $chips .= '<span class="yc-rss"><a href="' . e($l['rss']) . '" target="_blank" rel="noopener">RSS</a></span>'; }
        $stm = ['ok' => ['#2e6b34', '正常'], 'err' => ['#9c6b1b', 'RSS 异常'], 'down' => ['#c0392b', '疑似失联'], 'no_rss' => ['#6f767e', '未接 RSS'], '' => ['#9a9a90', '待体检']][(string)($l['probe_state'] ?? '')] ?? ['#9a9a90', '—'];
        $upd = '—';
        $lp = trim((string)($l['last_post_at'] ?? ''));
        if ($lp !== '') { $d = (int)ceil((time() - strtotime($lp)) / 86400); $upd = $d <= 0 ? '今天' : ($d . ' 天前'); }
        $since = (string)($l['created_at'] ?? '') !== '' ? ' · 收录于 ' . substr((string)$l['created_at'], 0, 7) : '';
        $n30 = $counts[(int)$l['id']] ?? 0;
        $np = $postsMap[(int)$l['id']] ?? 0;
        return '<div class="yc-row">' . $ava
            . '<div class="yc-main"><div class="yc-name"><a href="/stats?id=' . (int)$l['id'] . '" title="查看资料与流量回执">' . e($name) . '</a></div>'
            . ($chips !== '' ? '<div class="yc-chips">' . $chips . '</div>' : '')
            . ((string)$l['description'] !== '' ? '<div class="yc-desc">' . e(mb_substr((string)$l['description'], 0, 130)) . '</div>' : '')
            . '<div class="yc-meta"><span class="yc-stdot" style="background:' . $stm[0] . '"></span>' . $stm[1] . ' · 最近更新 ' . $upd . $since
            . ' · 收录文章 ' . $np . ' 篇 · 近 30 天带客 <b>' . $n30 . '</b> 位</div></div>'
            . '<div class="yc-acts"><a class="btn ghost mini" href="/stats?id=' . (int)$l['id'] . '">详情 / 回执</a><a class="btn ghost mini" href="/go?id=' . (int)$l['id'] . '" target="_blank" rel="noopener nofollow">访问原站 →</a></div></div>';
    };

    $engCounts = [];
    foreach ($rows as $r) { $engCounts[(string)$r['engine']][] = 1; }
    $engCounts = array_map('count', $engCounts);
    $totalAll = count($rows);
    $groups = [];
    foreach (yc_links_enabled_groups('') as $g) { $groups[(int)$g['id']] = $g['name']; }
    $byGroup = [];
    foreach ($all as $r) { $byGroup[(int)$r['group_id']][] = $r; }
    $groupsUsed = array_filter($groups, fn($_, $gid) => isset($byGroup[$gid]), ARRAY_FILTER_USE_BOTH);

    $list = '';
    if ($flat) {
        $list = '<div class="yc-list">' . implode('', array_map(fn($r) => $row($r, true), $all)) . '</div>';
    } else {
        foreach ($groupsUsed as $gid => $gname) {
            $items = array_map(fn($r) => $row($r, false), $byGroup[$gid] ?? []);
            if (!$items) { continue; }
            $list .= '<div class="yc-sec"><h2 class="yc-h2" id="grp-' . (int)$gid . '">' . e((string)$gname) . '<small>' . count($items) . ' 个博客</small></h2></div>'
                . '<div class="yc-list">' . implode('', $items) . '</div>';
        }
    }
    $sel = function (string $n, array $o, string $c) { $s = ''; foreach ($o as $v => $l) { $s .= '<option value="' . e((string)$v) . '"' . ($c === $v ? ' selected' : '') . '>' . e($l) . '</option>'; } return '<select name="' . e($n) . '" onchange="this.form.submit()">' . $s . '</select>'; };
    $engAll = array_reduce(yc_links_engines(), fn($c, $g) => $c + $g, []);
    $toolbar = '<form class="yc-toolbar" method="get" action="/links">'
        . '<input type="text" name="q" value="' . e($q) . '" placeholder="搜索博客名 / 域名 / 简介…">'
        . $sel('sort', ['' => '默认排序', 'updated' => '最近更新', 'created' => '最近收录', 'clicks' => '带客最多', 'name' => '名称'], $sort)
        . $sel('engine', array_merge(['' => '全部博客程序'], $engAll), $eng)
        . '<button class="btn" type="submit">筛选</button>'
        . (($q !== '' || $sort !== '' || $eng !== '') ? '<a class="yc-pill" href="/links">✕ 清除</a>' : '')
        . '</form>';
    $catnav = '<div class="yc-catnav"><span class="yc-navcap">分类</span><a class="yc-pill' . (!$flat ? ' on' : '') . '" href="/links">全部 ' . $totalAll . '</a>';
    foreach ($groupsUsed as $gid => $gname) { $catnav .= '<a class="yc-pill" href="/links#grp-' . (int)$gid . '">' . e((string)$gname) . '</a>'; }
    $catnav .= '</div>';
    $viewNote = '';
    if ($flat) {
        $why = [];
        if ($q !== '') { $why[] = '搜索「' . $q . '」'; }
        if ($eng !== '') { $why[] = '程序「' . yc_links_engine_label($eng) . '」'; }
        if ($sort !== '') { $why[] = '排序：' . ['updated' => '最近更新', 'created' => '最近收录', 'clicks' => '带客最多', 'name' => '名称'][$sort]; }
        $viewNote = '<p class="yc-meta" style="margin:0 0 10px;">当前视图：' . implode(' · ', $why) . '，共 ' . count($all) . ' 个。</p>';
    }
    $hero = '<div class="yc-hero"><h1>还在更新的博客，都值得被看见</h1>'
        . '<p class="sub">这里收录认真写字的中文独立博客。你看到的每一条都标着：用什么程序写的、多久没更新、目前是否活着、我们带去了多少真实访客——信息齐全，去留透明。</p>'
        . '<div class="cta"><a class="btn" href="/submit" style="text-decoration:none;">＋ 申请收录你的博客</a><a class="btn ghost" href="/random">🎲 随机串门去一家</a><a class="btn ghost" href="/circle">☕ 逛最新文章流</a></div></div>';
    $inner = $hero . yc_links_features_html()
        . '<div class="yc-stats"><div class="yc-stat"><b>' . $totalAll . '</b><span>已收录博客</span></div><div class="yc-stat"><b>' . $postsTotal . '</b><span>RSS 收录文章</span></div><div class="yc-stat"><b>' . $clickTotal . '</b><span>近 30 天真实带客</span></div></div>'
        . $toolbar . $catnav . $viewNote
        . (count($all) === 0 ? '<div class="empty">还没有收录任何博客。<br><a href="/submit">成为第一个 →</a>　或 <a href="/links">清除筛选</a></div>' : $list)
        . '<p class="yc-meta">状态点为机器体检结果（站点可达 / RSS 解析 / 最近更新时间），每 6 小时自动复查；计数口径见页脚。</p>'
        . yc_links_cta_html();
    return yc_portal_shell('links', $inner);
}

/* ============ /circle 文章流 ============ */

function yc_links_circle_html(): string
{
    $feat = [];
    $rows = [];
    if (Db::rows('SHOW TABLES LIKE "' . Db::t('circle_posts') . '"')) {
        $feat = Db::rows('SELECT p.title,p.url,p.featured_at,l.name AS blog,l.id AS lid FROM ' . Db::t('circle_posts') . ' p JOIN ' . Db::t('links') . ' l ON l.id=p.link_id WHERE l.status=\'show\' AND p.featured=1 ORDER BY p.featured_at DESC LIMIT 5');
        $rows = Db::rows('SELECT p.title,p.url,p.summary,p.published_at,l.name AS blog,l.id AS lid FROM ' . Db::t('circle_posts') . ' p JOIN ' . Db::t('links') . ' l ON l.id=p.link_id WHERE l.status=\'show\' ORDER BY p.published_at DESC,p.id DESC LIMIT 100');
    }
    $grouped = ['today' => [], 'week' => [], 'earlier' => []];
    foreach ($rows as $r) {
        $ts = strtotime((string)($r['published_at'] ?? ''));
        if (!$ts) { $grouped['earlier'][] = $r; continue; }
        $d = (int)((time() - $ts) / 86400);
        if ($d <= 0) { $grouped['today'][] = $r; } elseif ($d <= 7) { $grouped['week'][] = $r; } else { $grouped['earlier'][] = $r; }
    }
    $li = function (array $r): string {
        $ago = '';
        $ts = strtotime((string)($r['published_at'] ?? ''));
        if ($ts) { $d = (int)ceil((time() - $ts) / 86400); $ago = $d <= 0 ? '今天' : ($d <= 7 ? $d . ' 天前' : substr((string)$r['published_at'], 0, 10)); }
        return '<li><a class="t" href="' . e(yc_links_append_via((string)$r['url'])) . '" target="_blank" rel="noopener nofollow">' . e($r['title']) . '</a>'
            . ((string)$r['summary'] !== '' ? '<div class="s">' . e(mb_substr((string)$r['summary'], 0, 150)) . '</div>' : '')
            . '<div class="m">' . $ago . ' · 来自 <a href="/stats?id=' . (int)$r['lid'] . '">' . e($r['blog']) . '</a> · <a href="' . e(yc_links_append_via((string)$r['url'])) . '" target="_blank" rel="noopener nofollow">在原文阅读 →</a></div></li>';
    };
    $sec = function (string $label, array $items) use ($li): string {
        if (!$items) { return ''; }
        return '<div class="yc-sec"><h2 class="yc-h2">' . $label . '<small>' . count($items) . ' 篇</small></h2></div><ul class="yc-feed">' . implode('', array_map($li, $items)) . '</ul>';
    };
    $featBox = '';
    if ($feat) {
        $f = '';
        foreach ($feat as $r) { $f .= '<li><a class="t" href="' . e(yc_links_append_via((string)$r['url'])) . '" target="_blank" rel="noopener nofollow"><span class="yc-star">★</span> ' . e($r['title']) . '</a><div class="m">站长精选 · ' . e($r['blog']) . ' · <a href="/selected">月度精选 →</a></div></li>'; }
        $featBox = '<div class="yc-card" style="border-color:#e3c5b6;background:#fdf9f2;margin-bottom:8px;"><div class="yc-h2" style="border-left-color:#e3c5b6;">站长精选 ★ <small>本月值得一读</small></div><ul class="yc-feed" style="margin-top:6px;">' . $f . '</ul></div>';
    }
    $hero = '<div class="yc-hero"><h1>文章流 · 温柔聚合</h1>'
        . '<p class="sub">每小时自动抓取圈内博客的新文章，只留标题、摘要与原文链接——点开，就回到作者那里读。我们不存正文、不镜像、不追踪你。</p>'
        . '<div class="cta"><a class="btn ghost" href="/selected">📖 去月度精选回看</a><a class="btn ghost" href="/submit">还没有你的博客？申请收录</a></div></div>';
    $body = $featBox . $sec('今天的新文章', $grouped['today']) . $sec('近 7 天', $grouped['week']) . $sec('更早', $grouped['earlier']);
    if ($body === '') { $body = '<div class="empty">还没有聚合到文章 —— 等第一个接入 RSS 的博客上线，这里就会亮起来。<br><a href="/submit">去申请 →</a></div>'; }
    return yc_portal_shell('circle', $hero . $body);
}

/* ============ /selected 月度精选（照抄 monthly-selected 形态） ============ */

function yc_links_selected_html(): string
{
    // 订阅/退订
    $msg = ''; $err = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        try { \Csrf::check(); } catch (Throwable $e) { $err = '表单校验失败，请刷新后重试'; }
        if ($err === '') { $r = yc_links_sub_add((string)($_POST['email'] ?? '')); $r === '' ? $msg = '已订阅 ✔ 每期精选会寄到你的邮箱。' : $err = $r; }
    } elseif (isset($_GET['unsub'], $_GET['token'])) {
        yc_links_sub_del((string)$_GET['unsub'], (string)$_GET['token']) ? $msg = '已退订，不再接收月度精选。' : $err = '退订链接无效或已退订。';
    }
    $note = $err !== '' ? '<p class="yc-meta" style="color:#c0392b;background:#fdf0ec;border:1px solid #efc8ba;border-radius:8px;padding:8px 12px;">' . e($err) . '</p>'
        : ($msg !== '' ? '<p class="yc-meta" style="color:#2e6b34;background:#eef4ee;border:1px solid #cfe2d0;border-radius:8px;padding:8px 12px;">' . e($msg) . '</p>' : '');

    $months = [];
    if (Db::rows('SHOW TABLES LIKE "' . Db::t('circle_posts') . '"')) {
        $months = Db::rows('SELECT DATE_FORMAT(published_at, \'%Y-%m\') ym, COUNT(*) n FROM ' . Db::t('circle_posts') . ' WHERE published_at IS NOT NULL GROUP BY ym ORDER BY ym DESC LIMIT 24');
    }
    // 年份→月份导航 + 当前查看月
    $cur = preg_match('/^(\d{4})-(\d{2})$/D', (string)($_GET['ym'] ?? ''), $m) ? $m[1] . '-' . $m[2] : ($months[0]['ym'] ?? date('Y-m'));
    $yrs = [];
    foreach ($months as $mo) { $y = substr((string)$mo['ym'], 0, 4); $yrs[$y][] = $mo; }
    $nav = '';
    foreach ($yrs as $y => $ms) {
        $nav .= '<div class="yc-meta" style="margin:8px 0 4px;"><b>' . $y . '</b>　';
        foreach ($ms as $mo) {
            $on = $mo['ym'] === $cur;
            $nav .= '<a class="yc-pill' . ($on ? ' on' : '') . '" href="/selected?ym=' . e($mo['ym']) . '">' . (int)substr((string)$mo['ym'], 5, 2) . ' 月 · ' . (int)$mo['n'] . ' 篇</a> ';
        }
        $nav .= '</div>';
    }
    // 当前月数据
    $total = 0; $rows = []; $feat = [];
    if (Db::rows('SHOW TABLES LIKE "' . Db::t('circle_posts') . '"')) {
        $total = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('circle_posts') . ' WHERE published_at LIKE ?', [$cur . '-%']);
        $rows = Db::rows('SELECT p.title,p.url,p.published_at,p.featured,p.featured_at,l.name AS blog,l.id AS lid FROM ' . Db::t('circle_posts') . ' p JOIN ' . Db::t('links') . ' l ON l.id=p.link_id WHERE l.status=\'show\' AND p.published_at LIKE ? ORDER BY p.featured DESC,p.featured_at DESC,p.published_at DESC LIMIT 500', [$cur . '-%']);
        $feat = array_values(array_filter($rows, fn($r) => (int)$r['featured'] === 1));
    }
    $item = function (array $r, bool $star): string {
        return '<li><a class="t" href="' . e(yc_links_append_via((string)$r['url'])) . '" target="_blank" rel="noopener nofollow">' . ($star ? '<span class="yc-star">★</span> ' : '') . e($r['title']) . '</a>'
            . '<div class="m">' . e(substr((string)$r['published_at'], 0, 10)) . ' · <a href="/stats?id=' . (int)$r['lid'] . '">' . e($r['blog']) . '</a>' . ($star ? ' · <span class="yc-star">站长精选</span>' : '') . '</div></li>';
    };
    $y = (int)substr($cur, 0, 4); $mm = (int)substr($cur, 5, 2);
    $hero = '<div class="yc-hero"><h1>月度精选 · ' . $y . ' 年 ' . $mm . ' 月</h1>'
        . '<p class="sub">像翻旧杂志：这个月圈里写了什么？站长把值得一读的标上 ★，其余是这个月的新文章（' . $total . ' 篇）。只留标题与链接，想读全文请回到原站。</p></div>';
    $featBox = $feat ? '<div class="yc-card" style="border-color:#e3c5b6;background:#fdf9f2;"><h2 class="yc-h2" style="border-left-color:#e3c5b6;">本月精选 ★ <small>站长逐篇置顶</small></h2><ul class="yc-feed" style="margin:6px 0 0;">' . implode('', array_map(fn($r) => $item($r, true), $feat)) . '</ul></div>' : '';
    $listBox = count($rows)
        ? '<div class="yc-card"><h2 class="yc-h2">本月全部新文 <small>' . count($rows) . ' 篇</small></h2><ul class="yc-feed" style="margin:6px 0 0;">' . implode('', array_map(fn($r) => $item($r, (int)$r['featured'] === 1), $rows)) . '</ul></div>'
        : '<div class="empty">这个月还没有聚合到文章。</div>';
    $csrf = \Csrf::field();
    $sub = '<div class="yc-card yc-sub"><h2 class="yc-h2">订阅「月度精选」</h2>'
        . '<p class="yc-meta">每期寄到你的邮箱（站长在后台开启并配置 SMTP 后才会真正发送）。</p>'
        . '<form method="post" action="/selected" style="display:flex;gap:8px;max-width:420px;">' . $csrf
        . '<input type="email" name="email" required placeholder="you@example.com" style="flex:1;border:1px solid #d9dee3;border-radius:999px;padding:8px 14px;font:inherit;">'
        . '<button class="btn" type="submit" style="border:0;border-radius:999px;">订阅</button></form></div>';
    $inner = $hero . $note
        . '<div class="yc-card"><h2 class="yc-h2">回看年月 <small>按月份存档翻看</small></h2>' . $nav . '</div>'
        . $featBox . '<div style="height:12px"></div>' . $listBox . $sub
        . '<p class="yc-meta" style="margin-top:14px;"><a href="/circle">← 文章流</a> · <a href="/links">收录广场</a></p>';
    return yc_portal_shell('selected', $inner);
}

/* ============ /submit 申请收录 ============ */

function yc_links_submit_page(): string
{
    $ok = ''; $err = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $err = yc_links_submit_handle();
        $ok = $err === '' ? 'ok' : '';
    }
    return yc_links_submit_html($err !== '' ? $err : $ok);
}

function yc_links_submit_html(string $state): string
{
    $csrf = \Csrf::field();
    $note = '';
    if ($state !== '' && $state !== 'ok') {
        $note = '<p style="color:#c0392b;background:#fdf0ec;border:1px solid #efc8ba;border-radius:8px;padding:9px 12px;">' . e($state) . '</p>';
    } elseif ($state === 'ok') {
        $note = '<p style="color:#2e6b34;background:#eef4ee;border:1px solid #cfe2d0;border-radius:8px;padding:9px 12px;">已收到申请 ✔ 我们会在 48–72 小时内人工审核（节假日顺延）。通过后，你的博客会出现在收录广场，新文章进文章流，并能在“流量回执”里看到我们每天带去的真实访客。</p>';
    }
    $left = '<div class="yc-card"><h2 class="yc-h2">把博客交给我们的方式很简单，条件也写得很清楚</h2>'
        . '<p class="yc-meta" style="font-size:14px;line-height:2;">我们只做一件事：收录「人写的、还在更新的中文独立博客」。这里没有转载，没有全文镜像，也没有按点击排行的游戏——你的每一位访客，都来自真正愿意读的人。</p>'
        . '<h3 class="yc-meta" style="font-weight:700;margin:10px 0 4px;">收录标准 · 一条不少，也一条不多</h3>'
        . '<ul class="yc-meta" style="padding-left:20px;line-height:2.1;">'
        . '<li><b>类型</b>：个人独立博客，拥有独立域名；内容以原创为主，有自己的观察与想法。</li>'
        . '<li><b>回链</b>：自愿互认。建议在你的友链页放回本站链接或「已收录」徽标，互相尊重是圈子的规矩。</li>'
        . '<li><b>审核</b>：提交后 48–72 小时内人工审核（节假日顺延）。通过后即出现在收录广场；未通过则不展示，可再次提交或来信询问。</li>'
        . '<li><b>更新</b>：收录后我们每 6 小时体检、每小时聚合你的 RSS（只读标题与摘要），建议保持数月内有新内容。</li>'
        . '<li><b>移除</b>：长时间停更、域名失效或备案异常、内容转向违规、或你主动申请退出——会先标注再移除，不会悄悄消失。</li>'
        . '<li><b>不收</b>：免费子域名、境内访问过慢、空站或内容未成型、疑似 AI 批量生成、发布未授权资源。</li></ul>'
        . '<p class="yc-meta">想先看看圈里有什么？<a href="/links">收录广场</a> · <a href="/selected">月度精选</a> · <a href="/collection">采集与互认说明</a></p></div>';
    $right = '<div class="yc-card"><h2 class="yc-h2">填写申请</h2>' . $note
        . '<form method="post" action="/submit">' . $csrf
        . '<input type="text" name="homepage" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">'
        . '<div style="display:grid;gap:12px;">'
        . '<div><label class="pf">博客名称 *</label><input name="name" required maxlength="120" placeholder="例如：老吕的茶话"></div>'
        . '<div><label class="pf">博客地址 *</label><input name="url" required maxlength="500" placeholder="https://example.com"></div>'
        . '<div><label class="pf">RSS / Feed 地址（强烈建议）</label><input name="rss" maxlength="500" placeholder="https://example.com/feed"></div>'
        . '<div><label class="pf">使用的博客程序 *</label><select name="engine" required>' . yc_links_engine_options() . '</select></div>'
        . '<div><label class="pf">一句话介绍</label><input name="description" maxlength="200" placeholder="这个博客写些什么？"></div>'
        . '<div><label class="pf">常用邮箱（仅审核沟通，不公开）</label><input name="email" type="email" maxlength="190" required placeholder="you@example.com"></div>'
        . '</div>'
        . '<button class="btn" type="submit" style="margin-top:14px;border:0;width:100%;">提交申请</button></form></div>';
    $hero = '<div class="yc-hero"><h1>申请收录你的博客</h1><p class="sub">认真写字、还在更新的中文独立博客，欢迎入圈。</p></div>';
    $inner = $hero . '<div class="yc-grid2">' . $left . $right . '</div>';
    return yc_portal_shell('submit', $inner);
}

/** 处理 POST 申请 */
function yc_links_submit_handle(): string
{
    try { \Csrf::check(); } catch (Throwable $e) { return '表单校验失败，请刷新后重试'; }
    if (trim((string)($_POST['homepage'] ?? '')) !== '') { return '提交过于频繁，请稍后再试'; }
    $ipKey = 'link_submit:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if ((int)\Cache::get('throttle', $ipKey) >= 5) { return '提交过于频繁，请 10 分钟后再试'; }
    $name = trim((string)($_POST['name'] ?? ''));
    $url = trim((string)($_POST['url'] ?? ''));
    $rss = trim((string)($_POST['rss'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $engine = trim((string)($_POST['engine'] ?? ''));
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 200);
    if ($name === '' || $url === '' || $email === '') { return '请填写博客名称、地址和邮箱'; }
    if (!preg_match('#^https?://#i', $url)) { return '博客地址请以 http(s):// 开头'; }
    if ($rss !== '' && !preg_match('#^https?://#i', $rss)) { return 'RSS 地址请以 http(s):// 开头，或留空'; }
    if (strlen($rss) > 500) { return 'RSS 地址过长'; }
    $engines = [];
    foreach (yc_links_engines() as $grp) { foreach (array_keys($grp) as $k) { $engines[] = $k; } }
    if ($engine === '' || !in_array($engine, $engines, true)) { return '请选择使用的博客程序'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return '邮箱格式不正确'; }
    if (strlen($name) > 120 || strlen($url) > 500) { return '内容过长'; }
    $dup = \Db::one('SELECT id FROM ' . \Db::t('links') . ' WHERE url=? AND status<>? LIMIT 1', [$url, 'trash']);
    if ($dup !== null) { return '该博客已在收录列表或待审中'; }
    \Db::insert('links', ['group_id' => 0, 'name' => $name, 'url' => $url, 'rss' => $rss, 'engine' => $engine, 'email' => $email, 'description' => $desc, 'sort' => 0, 'status' => 'pending']);
    \Cache::incr('throttle', $ipKey, 600);
    \Cache::flush('blocks');
    return '';
}

/* ============ /go /random /stats /certificate /collection ============ */

function yc_links_go_redirect(): never
{
    $id = (int)($_GET['id'] ?? 0);
    $l = $id > 0 ? \Db::row('SELECT * FROM ' . \Db::t('links') . " WHERE id=? AND status='show' LIMIT 1", [$id]) : null;
    if ($l === null) { header('Location: /links'); exit; }
    yc_links_click_track((int)$l['id']);
    header('Location: ' . yc_links_append_via((string)$l['url']));
    exit;
}

function yc_links_random_redirect(): never
{
    $l = yc_links_random_link();
    if ($l === null) { header('Location: /links'); exit; }
    yc_links_click_track((int)$l['id']);
    header('Location: ' . yc_links_append_via((string)$l['url']));
    exit;
}

function yc_links_stats_html(): string
{
    $id = (int)($_GET['id'] ?? 0);
    $l = $id > 0 ? \Db::row('SELECT * FROM ' . \Db::t('links') . " WHERE id=? AND status='show' LIMIT 1", [$id]) : null;
    if ($l === null) { header('Location: /links'); exit; }
    if (($_GET['export'] ?? '') === '1') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="yc-stats-' . $id . '.csv"');
        echo "date,visitors\n";
        foreach (yc_links_click_days($id, 30) as $d => $n) { echo $d . ',' . $n . "\n"; }
        exit;
    }
    $days = yc_links_click_days($id, 30);
    $total30 = array_sum($days);
    $today = $days[date('Y-m-d')] ?? 0;
    $max = max(1, ...(array_values($days) ?: [0]));
    $bars = '';
    foreach ($days as $d => $n) {
        $w = max(1, (int)round((int)$n / $max * 100));
        $bars .= '<div style="display:flex;align-items:center;gap:6px;margin:2px 0;font-size:12px;"><span style="width:82px;flex:none;color:#9a9a90;">' . e($d) . '</span><span style="height:13px;width:' . $w . '%;min-width:2px;background:#b3541e;border-radius:3px;"></span><span style="width:22px;color:#6f767e;">' . (int)$n . '</span></div>';
    }
    $stm = ['ok' => ['#2e6b34', '正常'], 'err' => ['#9c6b1b', 'RSS 异常'], 'down' => ['#c0392b', '疑似失联'], 'no_rss' => ['#6f767e', '未接 RSS'], '' => ['#9a9a90', '待体检']][(string)($l['probe_state'] ?? '')] ?? ['#9a9a90', '—'];
    $posts = [];
    if (Db::rows('SHOW TABLES LIKE "' . Db::t('circle_posts') . '"')) {
        $posts = Db::rows('SELECT title,url,published_at FROM ' . Db::t('circle_posts') . ' WHERE link_id=? ORDER BY published_at DESC,id DESC LIMIT 10', [$id]);
    }
    $postLi = '';
    foreach ($posts as $pt) { $postLi .= '<li><a class="t" style="font-size:14px;" href="' . e(yc_links_append_via((string)$pt['url'])) . '" target="_blank" rel="noopener nofollow">' . e($pt['title']) . '</a><div class="m">' . e(substr((string)$pt['published_at'], 0, 10)) . '</div></li>'; }
    $grpName = $l['group_id'] > 0 ? (string)(Db::one('SELECT name FROM ' . Db::t('link_groups') . ' WHERE id=?', [(int)$l['group_id']]) ?? '') : '';
    $chips = '<span class="yc-eng">' . e(yc_links_engine_label((string)($l['engine'] ?? ''))) . '</span>' . ($grpName !== '' ? '<span class="yc-grp">' . e($grpName) . '</span>' : '') . ((string)$l['rss'] !== '' ? '<span class="yc-rss"><a href="' . e($l['rss']) . '" target="_blank" rel="noopener">RSS</a></span>' : '');
    $hero = '<div class="yc-hero"><h1>流量回执 · ' . e($l['name']) . '</h1>'
        . '<p class="sub">我们从本站带给你多少真实访客，一笔一笔算给你看——口径诚实：同一访客自然日计 1 次，只统计收录广场与随机串门出发的点击。</p></div>';
    $inner = $hero
        . '<div class="yc-card"><h2 class="yc-h2">基本信息</h2><table style="margin-top:8px;font-size:14px;">'
        . '<tr><th style="width:110px;color:#9a9a90;font-weight:400;">博客</th><td>' . e($l['name']) . '</td></tr>'
        . '<tr><th style="color:#9a9a90;font-weight:400;">程序 / 分组</th><td><div class="yc-chips">' . $chips . '</div></td></tr>'
        . '<tr><th style="color:#9a9a90;font-weight:400;">网站</th><td><a href="/go?id=' . (int)$l['id'] . '" rel="noopener nofollow">' . e($l['url']) . '</a></td></tr>'
        . '<tr><th style="color:#9a9a90;font-weight:400;">状态</th><td><span class="yc-stdot" style="background:' . $stm[0] . '"></span>' . $stm[1]
        . ((string)($l['probe_note'] ?? '') !== '' ? ' <span style="color:#9a9a90;">（' . e((string)$l['probe_note']) . '）</span>' : '')
        . ((string)($l['last_post_at'] ?? '') !== '' ? ' <span style="color:#9a9a90;">· 最近更新 ' . e((string)$l['last_post_at']) . '</span>' : '') . '</td></tr>'
        . '</table></div>'
        . '<div class="yc-stats"><div class="yc-stat"><b>' . $total30 . '</b><span>近 30 天被带走的真实访客</span></div><div class="yc-stat"><b>' . $today . '</b><span>今天</span></div><div class="yc-stat"><b>' . count($posts) . '</b><span>本站收录文章</span></div></div>'
        . '<div class="yc-card"><h2 class="yc-h2">近 30 天每日来访</h2><div style="margin-top:8px;">' . $bars . '</div></div>'
        . '<div class="yc-card" style="margin-top:14px;"><h2 class="yc-h2">本站收录的文章 <small>最近 ' . count($posts) . ' 篇</small></h2>'
        . ($posts ? '<ul class="yc-feed" style="margin:6px 0 0;">' . $postLi . '</ul>'
            : ((string)$l['rss'] !== '' ? '<div class="empty" style="padding:16px 0;">还没有抓取到文章 —— 等每小时聚合跑过，或站长发布新文后自动出现。</div>' : '<div class="empty" style="padding:16px 0;">未接入 RSS，无法聚合文章（可在后台补填后点体检）。</div>'))
        . '</div>'
        . '<div class="yc-acts" style="flex-direction:row;align-items:center;margin-top:14px;"><a class="btn" href="/certificate?id=' . (int)$l['id'] . '" style="text-decoration:none;">领取「已收录」徽标</a><a class="btn ghost" href="/stats?id=' . (int)$l['id'] . '&amp;export=1">导出近 30 天 CSV</a><a class="btn ghost" href="/links">← 收录广场</a></div>'
        . '<p class="yc-meta" style="margin-top:10px;">出站统一携带 <code>via=yecha.org</code> 来源参数（<a href="/collection">互认说明</a>）。</p>';
    return yc_portal_shell('links', $inner);
}

function yc_links_certificate_html(): string
{
    $id = (int)($_GET['id'] ?? 0);
    $l = $id > 0 ? \Db::row('SELECT * FROM ' . \Db::t('links') . " WHERE id=? AND status='show' LIMIT 1", [$id]) : null;
    if ($l === null) { header('Location: /links'); exit; }
    if (($_GET['svg'] ?? '') === '1') {
        header('Content-Type: image/svg+xml; charset=utf-8');
        $xml = fn(string $s): string => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="290" height="56"><rect x="1" y="1" width="288" height="54" rx="27" fill="#f7faf7" stroke="#cfe2d0" stroke-width="2"/><circle cx="30" cy="28" r="9" fill="#2e6b34"/><path d="M25 28l4 4 8-9" stroke="#fff" stroke-width="2.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/><text x="52" y="25" font-family="PingFang SC,sans-serif" font-size="17" font-weight="700">' . $xml('已收录 · YeCha 博客收录圈') . '</text><text x="52" y="43" font-family="monospace" font-size="11" fill="#6f767e">yecha.org</text></svg>';
        exit;
    }
    $code = '<a href="https://yecha.org/links" target="_blank" rel="noopener" style="display:inline-block;border:1px solid #cfe2d0;border-radius:999px;padding:6px 14px;font-size:13px;color:#2e6b34;text-decoration:none;background:#f7faf7;">✓ 已收录 · YeCha 博客收录圈</a>';
    $hero = '<div class="yc-hero"><h1>已收录徽标 · ' . e($l['name']) . '</h1><p class="sub">互认的“会员证”：放进你的友链页或页脚，读者就能沿它走进整个圈。</p></div>';
    $inner = $hero
        . '<div class="yc-card" style="max-width:680px;"><h2 class="yc-h2">预览</h2><p style="margin:10px 0;">' . $code . '</p><p><img src="/certificate?id=' . (int)$l['id'] . '&amp;svg=1" width="290" height="56" alt="徽标" style="border:1px solid #eeece6;border-radius:10px;"></p></div>'
        . '<div class="yc-card" style="max-width:680px;margin-top:12px;"><h2 class="yc-h2">复制 HTML</h2><textarea readonly rows="4" style="width:100%;margin-top:8px;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:8px;padding:8px 10px;font:12.5px/1.6 monospace;">' . e($code) . '</textarea>'
        . '<p class="yc-meta">或直接引用 SVG：<code>/certificate?id=' . (int)$l['id'] . '&amp;svg=1</code>。互认是双向的：我们从本站出站都携带 <code>via=yecha.org</code>（<a href="/collection">说明</a>）。</p></div>'
        . '<p><a href="/links">← 收录广场</a></p>';
    return yc_portal_shell('links', $inner);
}

function yc_links_collection_html(): string
{
    $inner = '<div class="yc-hero"><h1>采集与互认说明</h1><p class="sub">我们对你站点做什么、怎么做、如何喊停，写在这里，一件都不藏着。</p></div>'
        . '<div class="yc-card" style="max-width:860px;line-height:2;color:#333;">'
        . '<h2 class="yc-h2">一、我们收录什么</h2><p>只收录三类信息：<b>博客</b>（名称/网址/程序/一句话简介）、<b>RSS 元数据</b>（标题、摘要、原文链接、发布时间）、<b>来自本站的点击</b>（用于流量回执）。<b>不抓正文、不镜像全文、不缓存图片、不采集评论、不跟踪读者。</b></p>'
        . '<h2 class="yc-h2">二、抓取身份与频率</h2><ul style="padding-left:20px;"><li>探测 UA：<code>YeCha-LinksProbe/1.5 (+https://yecha.org)</code></li><li>体检约每 6 小时 1 次；RSS 聚合约每 1 小时 1 次；单次 ≤10 秒、≤512KB，尊重 robots.txt。</li></ul>'
        . '<h2 class="yc-h2">三、如何停止抓取 / 移除</h2><p>对我们返回 <code>403 Forbidden</code>（或屏蔽上述 UA）持续 3–7 天，我们会移除；也可直接来信申请退出——<b>会先标注、不会悄悄消失</b>。</p>'
        . '<h2 class="yc-h2">四、互认（徽标与来源）</h2><p>已收录站点在各自“流量回执”页领取徽标。我们所有出站链接统一携带 <code>via=yecha.org</code>，任何收到流量的站点都能识别“谁把我带了过去” —— 开放、无中心依赖，欢迎其它收录圈采用（参数规范随插件文档发布）。</p>'
        . '<h2 class="yc-h2">五、AI 时代的姿态</h2><p>这里只收录“人在写”的内容。若更新开始明显由机器批量生成，我们会先沟通再决定是否移除。</p></div>'
        . '<p><a href="/links">← 收录广场</a></p>';
    return yc_portal_shell('links', $inner);
}

/** 前台入口（收录门户统一声明：无右栏、通栏内容） */
function yc_links_page(string $slug): array
{
    $GLOBALS['YC_NO_SIDE'] = true;
    $GLOBALS['YC_SB_TYPE'] = 'page';
    if ($slug === 'submit') { return ['html' => yc_links_submit_page(), 'title' => '申请收录 · ' . site_label()]; }
    if ($slug === 'go') { yc_links_go_redirect(); }
    if ($slug === 'random') { yc_links_random_redirect(); }
    if ($slug === 'stats') { return ['html' => yc_links_stats_html(), 'title' => '流量回执 · ' . site_label()]; }
    if ($slug === 'circle') { return ['html' => yc_links_circle_html(), 'title' => '文章流 · ' . site_label()]; }
    if ($slug === 'selected') { return ['html' => yc_links_selected_html(), 'title' => '月度精选 · ' . site_label()]; }
    if ($slug === 'certificate') { return ['html' => yc_links_certificate_html(), 'title' => '已收录徽标 · ' . site_label()]; }
    if ($slug === 'collection') { return ['html' => yc_links_collection_html(), 'title' => '采集与互认说明 · ' . site_label()]; }
    return ['html' => yc_links_plaza_html(), 'title' => '博客收录 · ' . site_label()];
}
