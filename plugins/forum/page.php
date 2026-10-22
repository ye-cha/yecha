<?php
declare(strict_types=1);
// 论坛插件 · 前台（/forum）v4（t39）：linux.sb 式两栏 —— 版块列表 + 主题列表 + 统计侧栏；
// 版块 / 主题 / 帖子 / 发帖 / 回复 / 引用 / 编辑 / 软删 / 搜索 / 分页 / 个人资料 / 治理（置顶·精华·锁定）。
require_once __DIR__ . '/lib.php';

/**
 * ★ yf-seo-20261020：旧 query 形态 301 到伪静态，避免同一内容两个 URL。
 *   /forum?b=14      → /forum/b14
 *   /forum?t=10&p=2  → /forum/t10?p=2
 *   /forum?mode=new  → /forum/new
 * 只在 GET、且参数全是已知参数时跳（带 utm_* 之类外部分析参数就不动，免得丢数据）。
 */
function yf_canonical_redirect(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { return; }
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($uri === '') { return; }

    /* 只处理社区路径，别的页一概不碰 */
    $pathOnly = (string)parse_url($uri, PHP_URL_PATH);
    if (preg_match('#^/(?:[a-z]{2}(?:-[a-z]{2})?/)?forum(?:\.html|/|-|$)#', $pathOnly) !== 1) { return; }

    /* 带未知参数（utm_* / fbclid 之类）就不跳，免得把外部分析参数 301 掉 */
    $known = ['b', 't', 'u', 'mode', 'view', 'unread', 'q', 'p', 'quote', 'rid', 'sort', 'hot', 'rp', 'f', 'restore', 'post'];
    foreach (array_keys($_GET) as $k) {
        if (!in_array((string)$k, $known, true)) { return; }
    }

    /* 整体比对（路径 + query）：一致就不动，不一致 301 到规范形态。
       ★ yf-html-20261020：改成整体比对后，/forum、/forum/b14、/forum?t=10
         三种旧形态都会被收敛到 /forum-*.html，同一内容不再有两个 URL。 */
    $canon = yf_url($_GET);
    if ($canon === $uri) { return; }

    header('Location: ' . $canon, true, 301);
    exit;
}

function yc_forum_page(string $slug): array
{
    yf_canonical_redirect();   /* yf-seo-20261020：旧 ?b=/?t= 形态 301 到伪静态 */
    /* ★ yf-upload-20261020：上传端点。放在 301 之后、POST 分发之前 ——
       它要输出 JSON 并 exit，不能让后面任何渲染/跳转参与。 */
    if ((string)($_GET['mode'] ?? '') === 'attach') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Method Not Allowed');
        }
        yf_attach_upload();
    }
    /* ★ yf-ui-20261020：关掉 layout 层的面包屑「首页 › 社区」。
       版块/返回都靠左栏与帖子标题，一条「首页 › 社区」在社区里没有导航价值，
       还会把三栏挤下去 30px。用内核现成的 YC_LQ_BARE 让位开关，不改 layout。 */
    $GLOBALS['YC_LQ_BARE'] = 1;
    $GLOBALS['YC_NO_SIDE'] = true;
    $GLOBALS['YC_SB_TYPE'] = 'page';
    $err = '';
    $preview = '';
    $old = [];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'preview') {
            $old = [
                'title' => trim((string)($_POST['title'] ?? '')),
                'content' => trim((string)($_POST['content'] ?? '')),
                'board_id' => (int)($_POST['board_id'] ?? 0),
            ];
            $preview = yf_md($old['content']);
        } elseif ($act === 'react' || $act === 'draft_save') {
            yf_handle_ajax();
        } else {
            $err = yf_handle_post();
        }
    }
    yf_touch_presence();

    // 永久短链：/forum/f/<主题>[/<楼层回帖 id>]（分享按钮复制的那种）；/forum/drafts 别名
    $path = array_values((array)($GLOBALS['YC_PLUGIN_PATH'] ?? []));
    if (($path[0] ?? '') === 'drafts') {
        return ['html' => yf_frame(yf_css() . yf_drafts_view()), 'title' => yf_t('我的草稿', 'My Drafts') . ' · ' . site_label()];
    }
    if (($path[0] ?? '') === 'f' && isset($path[1])) {
        $tid = (int)$path[1];
        $focus = (int)($path[2] ?? 0);
        $html = yf_thread_view($tid, '', $focus);
        if ($html === null) {
            return ['html' => yf_frame(yf_css() . yf_notfound_html(), ''), 'title' => '404 · ' . site_label(), 'status' => 404];
        }
        return ['html' => yf_frame(yf_css() . $html, (string)($GLOBALS['YF_TH_BOARD'][$tid] ?? '')), 'title' => yf_thread_title($tid)];
    }

    $mode = (string)($_GET['mode'] ?? '');
    if ($mode === 'new') {
        $curB = (string)($_GET['b'] ?? '');
        $old += ['board_id' => (int)($_GET['b'] ?? 0)];
        /* ★ yf-prefill-20261020 · 跨站预填：liulanqi 云笔记的「一键发布到论坛」带 pt/pc 过来。
           只作表单初值，最终仍走 POST + Csrf + 登录校验；长度硬截断，不信任外部输入。
           pt 限 200（与 input maxlength 一致）；pc 限 3000（URL 长度所限，发起方负责截断并提示）。 */
        if (trim((string)($old['title'] ?? '')) === '' && isset($_GET['pt'])) {
            $old['title'] = mb_substr(trim((string)$_GET['pt']), 0, 200);
        }
        if (trim((string)($old['content'] ?? '')) === '' && isset($_GET['pc'])) {
            $old['content'] = mb_substr((string)$_GET['pc'], 0, 3000);
        }
        return ['html' => yf_frame(yf_css() . yf_new_form($err, $old, $preview), $curB), 'title' => yf_t('发起新帖', 'Start a New Topic') . ' · ' . site_label()];
    }
    if ($mode === 'drafts') {
        return ['html' => yf_frame(yf_css() . yf_drafts_view()), 'title' => yf_t('我的草稿', 'My Drafts') . ' · ' . site_label()];
    }
    if ($mode === 'edit') {
        $tid = (int)($_GET['t'] ?? 0);
        if ($tid > 0) {
            $th = Db::row('SELECT board_id FROM ' . Db::t('forum_threads') . " WHERE id=? AND status='normal' LIMIT 1", [$tid]);
            $curB = $th ? (string)$th['board_id'] : '';
            return ['html' => yf_frame(yf_css() . yf_edit_thread_form($tid, $err, $old, $preview), $curB), 'title' => yf_t('编辑帖子', 'Edit Topic') . ' · ' . site_label()];
        }
        $rid = (int)($_GET['rid'] ?? 0);
        $th = $rid > 0 ? Db::row('SELECT t.board_id FROM ' . Db::t('forum_replies') . ' r JOIN ' . Db::t('forum_threads') . ' t ON t.id=r.thread_id WHERE r.id=? AND r.status=\'normal\' LIMIT 1', [$rid]) : null;
        $curB = $th ? (string)$th['board_id'] : '';
        return ['html' => yf_frame(yf_css() . yf_edit_reply_form($rid, $err, $old), $curB), 'title' => yf_t('编辑回帖', 'Edit Reply') . ' · ' . site_label()];
    }
    $u = (int)($_GET['u'] ?? 0);
    if ($u > 0) {
        return ['html' => yf_frame(yf_css() . yf_profile_view($u)), 'title' => yf_t('用户资料', 'Profile') . ' · ' . site_label()];
    }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        return ['html' => yf_frame(yf_css() . yf_search_view($q)), 'title' => yf_t('搜索', 'Search') . ' · ' . site_label()];
    }
    $t = (int)($_GET['t'] ?? 0);
    if ($t > 0) {
        $html = yf_thread_view($t, $err);
        if ($html === null) {
            return ['html' => yf_frame(yf_css() . yf_notfound_html(), ''), 'title' => '404 · ' . site_label(), 'status' => 404];
        }
        return ['html' => yf_frame(yf_css() . $html, (string)($GLOBALS['YF_TH_BOARD'][$t] ?? '')), 'title' => yf_thread_title($t)];
    }
    $b = (string)($_GET['b'] ?? '');
    if ($b !== '') {
        return ['html' => yf_frame(yf_css() . yf_board_view($b, $err), $b), 'title' => yf_board_title($b)];
    }
    if ((string)($_GET['view'] ?? '') === 'all') {
        return ['html' => yf_frame(yf_css() . yf_list_view($err), ''), 'title' => yf_t('全部主题', 'All Topics') . ' · ' . site_label()];
    }
    return ['html' => yf_frame(yf_css() . yf_index_view($err), ''), 'title' => yf_t('社区', 'Community') . ' · ' . site_label()];
}

/* ---------- 结构外壳 ---------- */
/** ★ yf-ico-svg-20261020：板块图标（按 slug 映射；未收录的用通用对话图标）
 *  统一 24×24、stroke=currentColor，颜色由 .yf-ico 的 color 决定（= --primary） */
function yf_board_icon(string $slug): string
{
    $P = [
        'browser-help'    => '<path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
        'browser-talk'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'browser-compare' => '<path d="M12 3v18"/><path d="M5 8h14"/><path d="M5 8 2 15h6Z"/><path d="M19 8l-3 7h6Z"/>',
        'browser-sync'    => '<path d="M21 12a9 9 0 0 1-9 9 9 9 0 0 1-6.4-2.6"/><path d="M3 12a9 9 0 0 1 9-9 9 9 0 0 1 6.4 2.6"/><path d="M21 3v6h-6"/><path d="M3 21v-6h6"/>',
        'browser-privacy' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'browser-ext'     => '<path d="M20.5 7H19V4a1 1 0 0 0-1-1h-3a1 1 0 0 0-1 1v3h-2V4a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v3H5.5a2.5 2.5 0 0 0 0 5H7v3a1 1 0 0 0 1 1h3v-2.5a2.5 2.5 0 0 1 5 0V19h3a1 1 0 0 0 1-1v-3h1.5a2.5 2.5 0 0 0 0-5Z"/>',
        'browser-version' => '<path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',
        'browser-tools'   => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'tools'           => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'site-feedback'   => '<path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>',
        'general'         => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"/>',
    ];
    $inner = $P[$slug] ?? '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
        . $inner . '</svg>';
}

/* ══════════════════════════════════════════════════════════════════
 * ★ yf-3col-20261020：三栏改造（左＝板块导航 / 中＝帖子 / 右＝每日热榜）
 *   一一对应 linux.sb（实测其 plugins.css）：
 *     左导航 width:144px 卡片化，每行 = 彩色圆点 + 名称 + 右侧计数
 *     右栏「每日热榜」= 标题 + 三 tab（24小时/36小时/一周）+ 「近 N 小时 M 回复」
 *   ★ 不做 linux.sb 左栏下方那块公告卡 —— 那是推广位。
 *   ★ 不用 fixed 浮动（宽屏会压住内容），改真三栏 grid，全收在 1280px 容器内。
 * ══════════════════════════════════════════════════════════════════ */

/** ★ yf-community-20261020：发帖之星 —— 按发帖数排 Top N（左栏最下面） */
function yf_star_users(int $n = 5): array
{
    return yf_side_cache('star_' . $n, static function () use ($n): array {
        $T = Db::t('forum_threads');
        $U = Db::t('users');
        try {
            $rows = Db::rows("SELECT u.id, u.username, u.display_name, COUNT(t.id) AS c"
                . " FROM {$T} t JOIN {$U} u ON u.id=t.user_id"
                . " WHERE t.status='normal' GROUP BY u.id, u.username, u.display_name"
                . " ORDER BY c DESC, u.id ASC LIMIT " . (int)$n);
            if ($rows) { return $rows; }
        } catch (\Throwable $e) { /* display_name 可能不存在，走下面的兜底 */ }
        try {
            return Db::rows("SELECT u.id, u.username, '' AS display_name, COUNT(t.id) AS c"
                . " FROM {$T} t JOIN {$U} u ON u.id=t.user_id"
                . " WHERE t.status='normal' GROUP BY u.id, u.username"
                . " ORDER BY c DESC, u.id ASC LIMIT " . (int)$n) ?: [];
        } catch (\Throwable $e2) { return []; }
    }, 120);
}

function yf_board_color(string $slug): string
{
    $M = [
        'gust'         => '#3b82f6',
        'yecha'        => '#8b5cf6',
        'kingfisher'   => '#f59e0b',
        'browser-help' => '#ef4444',
        'browser-talk' => '#06b6d4',
        'tools'        => '#10b981',
        'general'      => '#6b7280',
        /* yecha.org 板块配色（20261023 社区三栏同步） */
        'browser-feedback'  => '#ec4899',
        'browser-versions'  => '#0ea5e9',
        'browser-migration' => '#14b8a6',
        'browser-privacy'   => '#a855f7',
        'browser-dev'       => '#6366f1',
        'help'              => '#f97316',
        'feedback'          => '#eab308',
        'showcase'          => '#84cc16',
    ];
    return $M[$slug] ?? 'hsl(var(--primary))';
}

function yf_leftnav(string $curB = ''): string
{
    /* ★ yf-community-20261020
       · 「自有浏览器」用**色块分组**把它和通用板块分开 —— 自家产品优先
       · 最下面加「发帖之星」（linux.sb 那个位置是站务公告，老吕要的是发帖排行） */
    $boards = yf_boards_stats();
    $unread = yf_unread();
    $all = ($curB === '' || $curB === '0');

    $OWN = ['gust', 'yecha', 'kingfisher'];
    $ownLi = '';
    $comLi = '';
    foreach ($boards as $b) {
        $bid = (int)$b['id'];
        $slug = (string)($b['slug'] ?? '');
        $ud = (int)($unread['boards'][$bid] ?? 0);
        $row = '<li><a class="' . (!$all && $curB === (string)$bid ? 'on' : '') . '" href="' . e(yf_url(['b' => $bid])) . '">'
            . '<span class="yf-navdot" style="--c:' . e(yf_board_color($slug)) . '"></span>'
            . '<span class="yf-navname">' . e(yf_bname($b)) . '</span>'
            . ($ud > 0 ? '<span class="yf-dotr">' . $ud . '</span>' : '')
            . '<span class="yf-navcnt">' . (int)$b['tc'] . '</span></a></li>';
        if (in_array($slug, $OWN, true)) { $ownLi .= $row; } else { $comLi .= $row; }
    }

    $html = '<aside class="yf-left">'
        . '<nav class="yf-lnav" aria-label="' . e(yf_t('社区板块', 'Boards')) . '">'
        . '<ul><li><a class="' . ($all ? 'on' : '') . '" href="' . e(yf_url()) . '">'
        . '<span class="yf-navdot" style="--c:hsl(var(--primary))"></span>'
        . '<span class="yf-navname">' . e(yf_t('全部主题', 'All topics')) . '</span></a></li></ul>';

    if ($ownLi !== '') {
        $html .= '<div class="yf-lgroup"><span class="yf-lgroup-t">' . e(yf_t('自有浏览器', 'Our browsers')) . '</span></div>'
            . '<ul>' . $ownLi . '</ul>';
    }
    if ($comLi !== '') {
        $html .= '<div class="yf-lgroup"><span class="yf-lgroup-t">' . e(yf_t('讨论与反馈', 'Discussion')) . '</span></div>'
            . '<ul>' . $comLi . '</ul>';
    }
    $html .= '<a class="yf-lnav-new" href="' . e(yf_url(['mode' => 'new'])) . '">+ ' . e(yf_t('发新帖', 'New topic')) . '</a>'
        . '</nav>';

    /* ── 发帖之星 ── */
    $stars = yf_star_users(5);
    $oli = '';
    $rank = 0;
    foreach ($stars as $u) {
        $rank++;
        $nm = trim((string)($u['display_name'] ?? '')) !== '' ? (string)$u['display_name'] : (string)$u['username'];
        $oli .= '<li><span class="yf-star-i' . ($rank <= 3 ? ' top' : '') . '">' . $rank . '</span>'
            . '<a href="' . e(yf_url(['u' => (int)$u['id']])) . '">' . e($nm) . '</a>'
            . '<b>' . (int)$u['c'] . '</b></li>';
    }
    if ($oli === '') {
        $oli = '<li class="yf-star-empty">' . e(yf_t('还没有人发帖', 'Nobody has posted yet')) . '</li>';
    }
    $html .= '<div class="yf-star"><h4>' . e(yf_t('发帖之星', 'Top posters')) . '</h4>'
        . '<ol>' . $oli . '</ol></div>';

    return $html . '</aside>';
}

/** 热榜：统计**窗口内新增的回复数**（不是累计 replies，也不是浏览量） */
function yf_hot_rows(string $win = '24h', int $n = 10): array
{
    $H = ['24h' => 24, '36h' => 36, 'week' => 168];
    $h = $H[$win] ?? 24;
    return yf_side_cache('hot_' . $win . '_' . $n, static function () use ($h, $n, $win): array {
        $T = Db::t('forum_threads');
        $R = Db::t('forum_replies');
        $rows = [];
        try {
            $rows = Db::rows("SELECT t.id, t.title, COUNT(r.id) AS c FROM {$T} t"
                . " JOIN {$R} r ON r.thread_id=t.id AND r.status='normal'"
                . " WHERE t.status='normal' AND r.created_at >= DATE_SUB(NOW(), INTERVAL {$h} HOUR)"
                . " GROUP BY t.id, t.title ORDER BY c DESC, t.id DESC LIMIT " . (int)$n);
        } catch (\Throwable $e) { $rows = []; }
        if ($rows) { return ['rows' => $rows, 'fb' => false, 'win' => $win]; }
        /* 兜底：窗口内没回复就给最新帖，并让前端标明（不假装是热榜） */
        $rows = Db::rows("SELECT id, title, replies AS c FROM {$T} WHERE status='normal'"
            . " ORDER BY COALESCE(last_at, created_at) DESC, id DESC LIMIT " . (int)$n);
        return ['rows' => $rows ?: [], 'fb' => true, 'win' => $win];
    }, 120);
}

function yf_hot_block(): string
{
    $cur = (string)($_GET['hot'] ?? '24h');
    if (!in_array($cur, ['24h', '36h', 'week'], true)) { $cur = '24h'; }
    $d = yf_hot_rows($cur, 10);
    $rows = (array)($d['rows'] ?? []);
    $fb = (bool)($d['fb'] ?? false);
    $CN = ['24h' => '24 小时', '36h' => '36 小时', 'week' => '一周'];

    $tabs = '';
    foreach (['24h' => '24小时', '36h' => '36小时', 'week' => '一周'] as $k => $label) {
        $tabs .= '<a class="' . ($cur === $k ? 'on' : '') . '" href="' . e(yf_url(['hot' => $k])) . '">' . e($label) . '</a>';
    }

    $li = '';
    foreach ($rows as $r) {
        $cnt = (int)($r['c'] ?? 0);
        /* ★ 兜底时不在每行重复「暂无回复」，只在标题行统一说明一次 */
        $sub = $fb ? '' : yf_t('近 ' . $CN[$cur] . ' ' . $cnt . ' 回复', 'last ' . $cnt . ' replies');
        $li .= '<li><a href="' . e(yf_url(['t' => (int)$r['id']])) . '">'
            . '<span class="yf-hot-t">' . e((string)$r['title']) . '</span>'
            . ($sub !== '' ? '<span class="yf-hot-c">' . e($sub) . '</span>' : '')
            . '</a></li>';
    }
    if ($li === '') {
        $li = '<li class="yf-hot-empty">' . e(yf_t('还没有讨论。发第一帖？', 'Nothing yet. Start the first topic?')) . '</li>';
    }
    $note = $fb ? '<p class="yf-hot-note">' . e(yf_t('这段时间还没有回复，下面是最近发布的主题。',
                                                    'No replies in this window — showing newest topics.')) . '</p>' : '';
    return '<div class="yf-w yf-hot"><h4>' . e(yf_t('每日热榜', 'Daily hot')) . '</h4>'
        . '<div class="yf-hot-tabs">' . $tabs . '</div>' . $note
        . '<ul class="yf-hot-list">' . $li . '</ul></div>';
}

function yf_frame(string $inner, string $curB = ''): string
{
    /* ★ yf-nobar-20261020：社区页除主站页头外**不再有任何通栏**。
       原来 yf_topbar() 是一条横贯全宽的搜索+登录条，现已拆进右栏：
       搜索置右栏最顶，登录/用户工具条紧随其下。 */
    $side = yf_side($curB);
    $left = yf_leftnav($curB);
    /* 窄屏（≤760）时右栏会掉到内容下方，搜索与用户卡就看不见了 —— 在主区顶部再放一份，
       宽屏用 CSS 隐藏。 */
    $mob = '<div class="yf-mobtop">' . yf_search_box() . yf_usercard() . '</div>';
    return '<div class="yf-forum">'
        . '<div class="yf-layout">' . $left
        . '<div class="yf-main">' . $mob . $inner . '</div>'
        . $side . '</div></div>' . yf_js() . yf_toolbar_js();
}

function yf_search_box(): string
{
    /* ★ yf-nobar-20261020：原通栏顶栏拆解 —— 这里只留「帖子搜索」，作为右栏第一张卡。 */
    $q = trim((string)($_GET['q'] ?? ''));
    return '<div class="yf-w yf-searchbox"><form method="get" action="/forum-search.html" role="search">'
        . '<input type="search" name="q" value="' . e($q) . '" placeholder="' . e(yf_t('搜索帖子', 'Search posts')) . '" aria-label="' . e(yf_t('搜索帖子', 'Search posts')) . '">'
        . '<button class="yf-sbtn" type="submit">' . e(yf_t('搜索', 'Search')) . '</button>'
        . '</form></div>';
}

/** 论坛侧栏全局数据缓存（60s；发帖/回帖/编辑/删除/治理时主动清） */
function yf_side_cache(string $key, callable $fn, int $ttl = 60): array
{
    $raw = \Cache::get('forum_side', $key);
    if (is_string($raw) && $raw !== '') {
        $v = json_decode($raw, true);
        if (is_array($v)) { return $v; }
    }
    $v = $fn();
    \Cache::set('forum_side', $key, json_encode($v, JSON_UNESCAPED_UNICODE), $ttl);
    return $v;
}

function yf_side_flush(): void
{
    \Cache::flush('forum_side');
}

/** 版块 + 主题/回复计数（一条 SQL；t50 起供侧栏与首页共用，避免逐版块 N+1） */
function yf_boards_stats(): array
{
    static $m = null;
    if ($m !== null) { return $m; }
    $m = yf_side_cache('boards', static function (): array {
        $T = Db::t('forum_threads');
        $R = Db::t('forum_replies');
        $rows = Db::rows('SELECT b.*,'
            . " (SELECT COUNT(*) FROM {$T} t WHERE t.board_id=b.id AND t.status='normal') tc,"
            . " (SELECT COUNT(*) FROM {$R} r JOIN {$T} t2 ON t2.id=r.thread_id WHERE t2.board_id=b.id AND t2.status='normal' AND r.status='normal') rc"
            . ' FROM ' . Db::t('forum_boards') . " b WHERE b.status='show' ORDER BY b.sort ASC, b.id ASC");
        $out = [];
        foreach ($rows as $r) { $out[(int)$r['id']] = $r; }
        return $out;
    });
    return $m;
}

/** 每个版块最后一条主题（首页版块行用；一条 SQL，含作者名） */
function yf_board_last_map(): array
{
    static $m = null;
    if ($m !== null) { return $m; }
    $m = yf_side_cache('board_last', static function (): array {
        $T = Db::t('forum_threads');
        $ids = array_keys(yf_boards_stats());
        if ($ids === []) { return []; }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::rows("SELECT t.*, u.username, u.display_name FROM {$T} t"
            . " JOIN (SELECT board_id, MAX(last_at) mlast FROM {$T} WHERE status='normal' AND board_id IN ({$in}) GROUP BY board_id) x"
            . ' ON x.board_id=t.board_id AND x.mlast=t.last_at'
            . ' LEFT JOIN ' . Db::t('users') . ' u ON u.id=COALESCE(t.last_user_id,t.user_id)'
            . " WHERE t.status='normal' ORDER BY t.last_at DESC, t.id DESC", $ids);
        $out = [];
        foreach ($rows as $r) { $out[(int)$r['board_id']] = $r; }
        return $out;
    });
    return $m;
}

/** 侧栏“最新主题” */
function yf_latest_topics(int $n = 5): array
{
    static $m = [];
    if (isset($m[$n])) { return $m[$n]; }
    $m[$n] = yf_side_cache('latest_' . $n, static function () use ($n): array {
        return Db::rows('SELECT id, title, replies FROM ' . Db::t('forum_threads')
            . " WHERE status='normal' ORDER BY pinned DESC, last_at DESC, id DESC LIMIT " . (int)$n);
    });
    return $m[$n];
}

/** 站点统计 + 最新会员 + 我的草稿数（一条 SQL） */
function yf_stats_row(): array
{
    static $s = null;
    if ($s !== null) { return $s; }
    $T = Db::t('forum_threads');
    $R = Db::t('forum_replies');
    $RD = Db::t('forum_reads');
    $uid = \Auth::check() ? (int)\Auth::id() : 0;
    $args = [$uid, $uid];
    $unreadSql = '';
    if (!empty($GLOBALS['YF_ON_THREAD']) && $uid > 0) {
        // 主题页要的未读总数 + 分版块数，并进同一条 stats SQL（省一条往返）
        $unreadSql = ", (SELECT COUNT(*) FROM {$T} t0 LEFT JOIN {$RD} r0 ON r0.thread_id=t0.id AND r0.user_id=?"
            . " WHERE t0.status='normal' AND (r0.user_id IS NULL OR t0.last_at > r0.read_at)) unread_total"
            . ", (SELECT GROUP_CONCAT(CONCAT(z.bid,':',z.c)) FROM (SELECT t1.board_id bid, COUNT(*) c FROM {$T} t1"
            . " LEFT JOIN {$RD} r1 ON r1.thread_id=t1.id AND r1.user_id=?"
            . " WHERE t1.status='normal' AND (r1.user_id IS NULL OR t1.last_at > r1.read_at) GROUP BY t1.board_id) z) unread_boards";
        $args[] = $uid;
        $args[] = $uid;
    }
    $s = Db::row('SELECT'
        . " (SELECT COUNT(*) FROM {$T} WHERE status='normal') tt,"
        . " (SELECT COUNT(*) FROM {$R} WHERE status='normal') rt,"
        . ' (SELECT COUNT(*) FROM ' . Db::t('users') . ') uc,'
        . ' (SELECT COUNT(*) FROM ' . Db::t('users') . " WHERE last_seen_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)) online,"
        . ' (SELECT COUNT(*) FROM ' . Db::t('forum_drafts') . ' WHERE user_id=?) dc,'
        . ' (SELECT COUNT(*) FROM ' . Db::t('notifications') . ' WHERE user_id=? AND is_read=0) nc' . $unreadSql
        . ', u.id lm_id, u.username lm_username, u.display_name lm_display'
        . ' FROM ' . Db::t('users') . ' u ORDER BY u.id DESC LIMIT 1', $args);
    return $s ?: ['tt' => 0, 'rt' => 0, 'uc' => 0, 'online' => 0, 'dc' => 0, 'nc' => 0,
        'unread_total' => 0, 'unread_boards' => '', 'lm_id' => 0, 'lm_username' => '', 'lm_display' => ''];
}

/** ★ yf-nobar-mob-20261020：用户/登录卡抽成独立函数 ——
    桌面渲染在右栏，窄屏（≤760）渲染在主区顶部（右栏那份由 CSS 隐藏）。 */
function yf_usercard(): string
{
    $unread = yf_unread();
    $st = yf_stats_row();
    $auth = '';
    if (\Auth::check()) {
        $u = \Auth::user();
        /* ★ yf-nobar-20261020：原通栏顶栏里的「未读 / 全部已读 / 草稿 / 消息 / 退出」全部收进这张卡。
           颜色改走 CSS 变量（原来硬编码 #B3541E 棕橙，暗色下看不清）。 */
        $auth = '<div class="yf-w yf-sidebox yf-ucard"><div class="yf-uhd">' . yf_ava($u, 34)
            . '<div><a class="yf-uname" href="' . e(yf_url(['u' => (int)\Auth::id()])) . '">' . e(yf_uname($u)) . '</a>'
            . '<div class="yf-usub">' . e(yf_t('已登录', 'Signed in')) . '</div></div></div>'
            . '<div class="yf-ulinks">'
            . '<a href="' . e(yf_url(['view' => 'all', 'unread' => 1])) . '">' . e(yf_t('未读', 'Unread')) . ' <b>' . (int)$unread['count'] . '</b></a>'
            . '<a href="' . e(yf_url(['mode' => 'drafts'])) . '">' . e(yf_t('草稿', 'Drafts')) . ' <b>' . (int)$st['dc'] . '</b></a>'
            . '<a href="/user/notifications">' . e(yf_t('消息', 'Notifications')) . ((int)$st['nc'] > 0 ? ' <b>' . (int)$st['nc'] . '</b>' : '') . '</a>'
            . '<a href="/logout">' . e(yf_t('退出', 'Log out')) . '</a></div>'
            . ($unread['count'] > 0
                ? '<form method="post" action="' . e(yf_url()) . '" class="yf-uform">' . \Csrf::field()
                  . '<input type="hidden" name="action" value="mark_all_read">'
                  . '<button class="yf-btn2 sm" type="submit">' . e(yf_t('全部标为已读', 'Mark all read')) . '</button></form>'
                : '')
            . '<a class="yf-btn yf-ubtn" href="' . e(yf_url(['mode' => 'new'])) . '">+ ' . e(yf_t('发新帖', 'New Topic')) . '</a></div>';
    } else {
        $auth = '<div class="yf-w yf-sidebox"><p>' . e(yf_t('登录后即可发帖与回帖。', 'Log in to post and reply.')) . '</p>'
            . '<a class="yf-btn" href="' . e(\ui_login(yf_url())) . '">' . e(yf_t('去登录 →', 'Log in →')) . '</a>'
            . '<a class="yf-btn2" style="width:100%;justify-content:center;box-sizing:border-box;margin-top:6px" href="' . e(\ui_register()) . '">' . e(yf_t('注册账号', 'Create account')) . '</a></div>';
    }
    return $auth;
}

function yf_side(string $curB = ''): string
{
    $T = Db::t('forum_threads');
    $boards = yf_boards_stats();
    $unread = yf_unread();
    $li = '';
    foreach ($boards as $b) {
        $bid = (int)$b['id'];
        $ud = (int)($unread['boards'][$bid] ?? 0);
        $li .= '<li><a' . ($curB !== '' && (string)$b['id'] === $curB ? ' class="on"' : '') . ' href="' . e(yf_url(['b' => $bid])) . '">'
            . '<span>' . e(yf_bname($b)) . '</span>' . ($ud > 0 ? '<span class="yf-dotr">' . $ud . '</span>' : '') . '<span class="n">' . (int)$b['tc'] . '</span></a></li>';
    }
    $st = yf_stats_row();
    $GLOBALS['YC_UNREAD_NOTIF'] = (int)$st['nc'];
    $stats = '<div class="yf-stats"><span><b>' . (int)$st['tt'] . '</b>' . e(yf_t('主题', 'topics')) . '</span>'
        . '<span><b>' . ((int)$st['tt'] + (int)$st['rt']) . '</b>' . e(yf_t('帖数', 'posts')) . '</span>'
        . '<span><b>' . (int)$st['uc'] . '</b>' . e(yf_t('会员', 'members')) . '</span></div>'
        . '<div class="yf-online"><span class="yf-dot"></span>' . e(yf_t('在线会员', 'Online')) . ' <b>' . (int)$st['online'] . '</b></div>';
    $latest = '';
    foreach (yf_latest_topics(5) as $th) {
        $latest .= '<li><a href="' . e(yf_url(['t' => (int)$th['id']])) . '"><span>' . e((string)$th['title']) . '</span><span class="n">' . (int)$th['replies'] . '</span></a></li>';
    }
    if ($latest === '') { $latest = '<li><span class="yf-empty" style="padding:10px 4px">' . e(yf_t('暂无主题', 'No topics yet')) . '</span></li>'; }
    $auth = yf_usercard();
    $lm = (int)$st['lm_id'] > 0 ? ['id' => (int)$st['lm_id'], 'username' => (string)$st['lm_username'], 'display_name' => (string)$st['lm_display']] : null;
    /* ★ yf-nobar-20261020：右栏顺序 = 搜索（最顶） → 登录/用户 → 每日热榜 → 社区统计 */
    return '<aside class="yf-side">'
        . yf_search_box()
        . $auth
        . yf_hot_block()
        . '<div class="yf-w"><h4>' . e(yf_t('社区统计', 'Community stats')) . '</h4>' . $stats
        . ($lm ? '<p style="margin:8px 0 0;font-size:12px;color:hsl(var(--muted-foreground))">' . e(yf_t('最新会员', 'Newest member')) . '：<a href="' . e(yf_url(['u' => $lm['id']])) . '" style="color:hsl(var(--primary));text-decoration:none">' . e(yf_uname($lm)) . '</a></p>' : '')
        . '</div>'
        . '</aside>';
}

/* ================= POST ================= */
/** 点赞/草稿的 AJAX 端点：直接输出 JSON 并结束请求 */
function yf_handle_ajax(): void
{
    header('Content-Type: application/json; charset=utf-8');
    $json = static function (array $d, int $code = 200): never {
        http_response_code($code);
        echo json_encode($d, JSON_UNESCAPED_UNICODE);
        exit;
    };
    try { if (!\Csrf::verify($_POST['_csrf'] ?? null)) { throw new RuntimeException('csrf'); } } catch (Throwable) { $json(['ok' => false, 'error' => yf_t('校验失败，请刷新后重试', 'Verification failed — refresh and retry.')], 403); }

    $act = (string)($_POST['action'] ?? '');
    if ($act === 'react') {
        if (!\Auth::check()) { $json(['ok' => false, 'login' => true, 'url' => ui_login((string)($_SERVER['REQUEST_URI'] ?? '/forum'))]); }
        $r = yf_toggle_reaction((string)($_POST['post_type'] ?? ''), (int)($_POST['post_id'] ?? 0), (int)($_POST['thread_id'] ?? 0), (string)($_POST['kind'] ?? ''));
        $json($r, $r['ok'] ? 200 : 400);
    }
    if ($act === 'draft_save') {
        if (!\Auth::check()) { $json(['ok' => false, 'login' => true, 'url' => ui_login()]); }
        $kind = (string)($_POST['kind'] ?? 'thread') === 'reply' ? 'reply' : 'thread';
        $tid = (int)($_POST['thread_id'] ?? 0);
        $ok = yf_draft_save($kind, $tid, (int)($_POST['board_id'] ?? 0), (string)($_POST['title'] ?? ''), (string)($_POST['content'] ?? ''));
        $json(['ok' => $ok, 'saved_at' => date('H:i')]);
    }
    $json(['ok' => false, 'error' => 'bad action'], 400);
}

/** 点赞开关：同一用户同一层同一种表情只一条（唯一键兜底）；返回 JSON 片段 */
function yf_toggle_reaction(string $type, int $postId, int $tid, string $kind): array
{
    $kinds = yf_react_kinds();
    if (!isset($kinds[$kind]) || $postId <= 0 || $tid <= 0) { return ['ok' => false, 'error' => yf_t('参数无效', 'Invalid request')]; }
    if ((int)\Cache::incr('forum_rate', 'r' . (int)\Auth::id(), 60) > 90) { return ['ok' => false, 'error' => yf_t('操作太频繁，请稍后再试', 'Too fast — slow down.')]; }
    if ($type === 'thread') {
        $ok = Db::one('SELECT id FROM ' . Db::t('forum_threads') . " WHERE id=? AND status='normal' LIMIT 1", [$postId]);
        if ($ok === null || $postId !== $tid) { return ['ok' => false, 'error' => yf_t('内容不存在或已删除', 'Post not found')]; }
    } elseif ($type === 'reply') {
        $ok = Db::one('SELECT id FROM ' . Db::t('forum_replies') . " WHERE id=? AND thread_id=? AND status='normal' LIMIT 1", [$postId, $tid]);
        if ($ok === null) { return ['ok' => false, 'error' => yf_t('回帖不存在或已删除', 'Reply not found')]; }
    } else {
        return ['ok' => false, 'error' => yf_t('参数无效', 'Invalid request')];
    }
    $R = Db::t('forum_reactions');
    $where = 'post_type=? AND post_id=? AND user_id=? AND kind=?';
    $args = [$type, $postId, (int)\Auth::id(), $kind];
    $deleted = Db::run('DELETE FROM ' . $R . ' WHERE ' . $where, $args)->rowCount();
    if ($deleted <= 0) {
        try {
            Db::insert('forum_reactions', ['post_type' => $type, 'post_id' => $postId, 'thread_id' => $tid,
                'user_id' => (int)\Auth::id(), 'kind' => $kind]);
        } catch (Throwable) { /* 唯一键并发重复 → 视作已赞 */ }
    }
    $count = (int)Db::one('SELECT COUNT(*) FROM ' . $R . ' WHERE post_type=? AND post_id=? AND kind=?', [$type, $postId, $kind]);
    $mine = Db::one('SELECT id FROM ' . $R . ' WHERE ' . $where . ' LIMIT 1', $args) !== null;
    return ['ok' => true, 'post_type' => $type, 'post_id' => $postId, 'kind' => $kind, 'count' => $count, 'mine' => $mine];
}

/**
 * 回帖后给主题作者 / 被引用者发站内通知（复用 ye_notifications）。
 * 同一个人只发一条，自己不通知自己。
 */
function yf_notify_reply(array $thread, int $rid, int $uid, int $quoteId): void
{
    $tid = (int)$thread['id'];
    $title = mb_substr((string)$thread['title'], 0, 60);
    $me = \Auth::user();
    $nm = $me ? yf_uname($me) : '?';
    $link = yf_share_path($tid, $rid) . '#p' . $rid;
    $authorId = (int)$thread['user_id'];
    // 收件人：主题作者 → 回复通知；被引用者 → 引用通知；同一人时引用优先，只发一条
    $targets = [];
    if ($authorId !== $uid) { $targets[$authorId] = 'reply'; }
    if ($quoteId > 0) {
        $quid = (int)(Db::one('SELECT user_id FROM ' . Db::t('forum_replies') . ' WHERE id=? LIMIT 1', [$quoteId]) ?? 0);
        if ($quid > 0 && $quid !== $uid) { $targets[$quid] = 'quote'; }
    }
    foreach ($targets as $to => $kind) {
        if ($kind === 'quote') {
            yf_notify($to, 'forum_quote', $nm . ' 引用了你在《' . $title . '》中的回复', $link);
        } else {
            yf_notify($to, 'forum_reply', $nm . ' 回复了你的主题《' . $title . '》', $link);
        }
    }
}

function yf_handle_post(): string
{
    $action = (string)($_POST['action'] ?? '');
    try { \Csrf::check(); } catch (Throwable) { return yf_t('校验失败，请刷新页面后重试', 'Verification failed — please refresh and retry.'); }
    if (trim((string)($_POST['website2'] ?? '')) !== '') { return yf_t('操作过于频繁，请稍后再试', 'Too fast — slow down a little.'); }

    $T = Db::t('forum_threads');
    $R = Db::t('forum_replies');

    if ($action === 'react') {
        if (!\Auth::check()) { \Uc::needLogin(); }
        $r = yf_toggle_reaction((string)($_POST['post_type'] ?? ''), (int)($_POST['post_id'] ?? 0), (int)($_POST['thread_id'] ?? 0), (string)($_POST['kind'] ?? ''));
        if (empty($r['ok'])) { return (string)($r['error'] ?? 'error'); }
        $tid = (int)($_POST['thread_id'] ?? 0);
        $ptype = (string)($_POST['post_type'] ?? 'reply') === 'thread' ? 'thread' : 'reply';
        header('Location: ' . yf_url(['t' => $tid]) . ($ptype === 'thread' ? '#pt' . $tid : '#p' . (int)($_POST['post_id'] ?? 0))); exit;
    }

    if ($action === 'mark_all_read') {
        if (!\Auth::check()) { \Uc::needLogin(); }
        yf_mark_all_read();
        $back = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if (!preg_match('#^https?://[^/]+(/.*)?$#', $back) || !str_contains($back, (string)($_SERVER['HTTP_HOST'] ?? ''))) { $back = yf_url(); }
        header('Location: ' . $back); exit;
    }

    if ($action === 'draft_del') {
        if (!\Auth::check()) { \Uc::needLogin(); }
        $kind = (string)($_POST['kind'] ?? 'thread') === 'reply' ? 'reply' : 'thread';
        $tid = (int)($_POST['thread_id'] ?? 0);
        yf_draft_delete($kind, $tid);
        header('Location: ' . ($kind === 'reply' && $tid > 0 ? yf_url(['t' => $tid]) . '#yf-reply' : yf_url())); exit;
    }

    if ($action === 'draft_save') {
        if (!\Auth::check()) { \Uc::needLogin(); }
        $kind = (string)($_POST['kind'] ?? 'thread') === 'reply' ? 'reply' : 'thread';
        $tid = (int)($_POST['thread_id'] ?? 0);
        yf_draft_save($kind, $tid, (int)($_POST['board_id'] ?? 0), (string)($_POST['title'] ?? ''), (string)($_POST['content'] ?? ''));
        header('Location: ' . ($kind === 'reply' && $tid > 0 ? yf_url(['t' => $tid]) . '#yf-reply' : yf_url(['mode' => 'new', 'restore' => 1]))); exit;
    }

    if ($action === 'new') {
        if (!\Auth::check()) { \Uc::needLogin(); }
        $boardId = (int)($_POST['board_id'] ?? 0);
        $board = $boardId > 0 ? yf_board($boardId) : null;
        if ($board === null) { return yf_t('请选择版块', 'Please choose a board.'); }
        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) { return yf_t('标题不能为空且不超过 200 字', 'Title is required and must be under 200 characters.'); }
        if (mb_strlen($content) < 2 || mb_strlen($content) > 60000) { return yf_t('内容至少 2 字，最多 60000 字', 'Content must be 2–60000 characters.'); }
        if (($r = yf_gate($title, $content)) !== '') { return $r; }
        if (($r = yf_rate_check()) !== '') { return $r; }
        $content = yf_mirror_images($content);   /* ★ 外链图片抓回站内 */
        $uid = (int)\Auth::id();
        $now = date('Y-m-d H:i:s');
        $tid = Db::insert('forum_threads', [
            'board_id' => (int)$board['id'], 'user_id' => $uid, 'title' => $title, 'content' => $content,
            'views' => 0, 'replies' => 0, 'status' => 'normal', 'pinned' => 0, 'featured' => 0, 'locked' => 0,
            'created_at' => $now, 'updated_at' => $now, 'last_at' => $now, 'last_user_id' => $uid,
        ]);
        yf_draft_delete('thread', 0);
        yf_side_flush();
        header('Location: ' . yf_url(['t' => $tid])); exit;
    }

    if ($action === 'reply') {
        if (!\Auth::check()) { \Uc::needLogin(); }
        $tid = (int)($_POST['thread_id'] ?? 0);
        $content = trim((string)($_POST['content'] ?? ''));
        $thread = $tid > 0 ? Db::row("SELECT * FROM {$T} WHERE id=? AND status='normal' LIMIT 1", [$tid]) : null;
        if ($thread === null) { return yf_t('帖子不存在或已删除', 'Topic not found or removed.'); }
        if ((int)$thread['locked'] === 1 && !\Auth::isAdmin()) { return yf_t('该帖已锁定，不能再回复', 'This topic is locked.'); }
        if (mb_strlen($content) < 2 || mb_strlen($content) > 60000) { return yf_t('回帖至少 2 字，最多 60000 字', 'Reply must be 2–60000 characters.'); }
        /* 回帖没有标题：yf_gate 的第一个参数是引用，不能直接传 null 字面量（PHP 会 Fatal） */
        $__noTitle = null;
        if (($r = yf_gate($__noTitle, $content)) !== '') { return $r; }
        if (($r = yf_rate_check()) !== '') { return $r; }
        $content = yf_mirror_images($content);   /* ★ 外链图片抓回站内 */
        $uid = (int)\Auth::id();
        $quote = (int)($_POST['quote_id'] ?? 0);
        if ($quote > 0) {
            $ok = Db::one("SELECT id FROM {$R} WHERE id=? AND thread_id=? AND status='normal' LIMIT 1", [$quote, $tid]);
            if ($ok === null) { $quote = 0; }
        }
        $rid = Db::insert('forum_replies', ['thread_id' => $tid, 'user_id' => $uid, 'content' => $content, 'quote_id' => $quote > 0 ? $quote : null, 'status' => 'normal']);
        Db::run("UPDATE {$T} SET replies=replies+1, last_at=NOW(), last_user_id=?, updated_at=NOW() WHERE id=?", [$uid, $tid]);
        yf_draft_delete('reply', $tid);
        yf_notify_reply($thread, $rid, $uid, $quote);
        yf_side_flush();
        header('Location: ' . yf_url(['t' => $tid]) . '#yf-r' . $rid); exit;
    }

    if ($action === 'edit_thread') {
        if (!\Auth::check()) { \Uc::needLogin(); }
        $id = (int)($_POST['id'] ?? 0);
        $th = Db::row("SELECT * FROM {$T} WHERE id=? AND status='normal' LIMIT 1", [$id]);
        if ($th === null) { return yf_t('帖子不存在或已删除', 'Topic not found.'); }
        if (!yf_can_edit((int)$th['user_id'])) { return yf_t('无权编辑该帖', 'You cannot edit this topic.'); }
        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) { return yf_t('标题不能为空且不超过 200 字', 'Title is required and must be under 200 characters.'); }
        if (mb_strlen($content) < 2 || mb_strlen($content) > 60000) { return yf_t('内容至少 2 字，最多 60000 字', 'Content must be 2–60000 characters.'); }
        if (($r = yf_gate($title, $content)) !== '') { return $r; }
        Db::run("UPDATE {$T} SET title=?, content=?, updated_at=NOW() WHERE id=?", [$title, $content, $id]);
        yf_side_flush();
        header('Location: ' . yf_url(['t' => $id])); exit;
    }

    if ($action === 'edit_reply') {
        if (!\Auth::check()) { \Uc::needLogin(); }
        $id = (int)($_POST['id'] ?? 0);
        $rp = Db::row("SELECT * FROM {$R} WHERE id=? AND status='normal' LIMIT 1", [$id]);
        if ($rp === null) { return yf_t('回帖不存在或已删除', 'Reply not found.'); }
        if (!yf_can_edit((int)$rp['user_id'])) { return yf_t('无权编辑该回帖', 'You cannot edit this reply.'); }
        $content = trim((string)($_POST['content'] ?? ''));
        if (mb_strlen($content) < 2 || mb_strlen($content) > 60000) { return yf_t('内容至少 2 字，最多 60000 字', 'Content must be 2–60000 characters.'); }
        /* 回帖没有标题：yf_gate 的第一个参数是引用，不能直接传 null 字面量（PHP 会 Fatal） */
        $__noTitle = null;
        if (($r = yf_gate($__noTitle, $content)) !== '') { return $r; }
        Db::run("UPDATE {$R} SET content=?, edited_at=NOW() WHERE id=?", [$content, $id]);
        yf_side_flush();
        header('Location: ' . yf_url(['t' => (int)$rp['thread_id']]) . '#yf-r' . $id); exit;
    }

    if ($action === 'del_thread') {
        if (!\Auth::check()) { \Uc::needLogin(); }
        $id = (int)($_POST['id'] ?? 0);
        $th = Db::row("SELECT * FROM {$T} WHERE id=? AND status='normal' LIMIT 1", [$id]);
        if ($th === null) { return yf_t('帖子不存在或已删除', 'Topic not found.'); }
        if (!yf_can_edit((int)$th['user_id'])) { return yf_t('无权删除该帖', 'You cannot delete this topic.'); }
        Db::run("UPDATE {$T} SET status='deleted' WHERE id=?", [$id]);
        Db::run("UPDATE {$R} SET status='deleted' WHERE thread_id=?", [$id]);
        yf_side_flush();
        header('Location: ' . yf_url(['b' => (int)$th['board_id']])); exit;
    }

    if ($action === 'del_reply') {
        if (!\Auth::check()) { \Uc::needLogin(); }
        $id = (int)($_POST['id'] ?? 0);
        $rp = Db::row("SELECT * FROM {$R} WHERE id=? AND status='normal' LIMIT 1", [$id]);
        if ($rp === null) { return yf_t('回帖不存在或已删除', 'Reply not found.'); }
        if (!yf_can_edit((int)$rp['user_id'])) { return yf_t('无权删除该回帖', 'You cannot delete this reply.'); }
        Db::run("UPDATE {$R} SET status='deleted' WHERE id=?", [$id]);
        Db::run("UPDATE {$T} SET replies=GREATEST(replies-1,0), updated_at=NOW() WHERE id=?", [(int)$rp['thread_id']]);
        yf_side_flush();
        header('Location: ' . yf_url(['t' => (int)$rp['thread_id']]) . '#yf-r' . $id); exit;
    }

    if ($action === 'mod_thread') {
        if (!\Auth::isAdmin()) { return yf_t('需要管理员权限', 'Admin only.'); }
        $id = (int)($_POST['id'] ?? 0);
        $what = (string)($_POST['what'] ?? '');
        $th = Db::row("SELECT id, board_id FROM {$T} WHERE id=? LIMIT 1", [$id]);
        if ($th === null) { return yf_t('帖子不存在', 'Topic not found.'); }
        $sql = [
            'pin' => "pinned=1", 'unpin' => "pinned=0",
            'good' => "featured=1", 'ungood' => "featured=0",
            'lock' => "locked=1", 'unlock' => "locked=0",
            'del' => "status='deleted'", 'restore' => "status='normal'",
        ];
        if (!isset($sql[$what])) { return yf_t('参数无效', 'Invalid action.'); }
        Db::run("UPDATE {$T} SET " . $sql[$what] . " WHERE id=?", [$id]);
        if ($what === 'del') { Db::run("UPDATE {$R} SET status='deleted' WHERE thread_id=?", [$id]); }
        yf_side_flush();
        header('Location: ' . yf_url(['t' => $id])); exit;
    }

    return '';
}

function yf_touch_presence(): void
{
    if (!\Auth::check()) { return; }
    // 每会话 2 分钟内只写一次（原来每页都发一条 UPDATE，白白占查询数）
    if (isset($_SESSION['yf_seen']) && time() - (int)$_SESSION['yf_seen'] < 120) { return; }
    Db::run('UPDATE ' . Db::t('users') . ' SET last_seen_at=NOW() WHERE id=? AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE))', [(int)\Auth::id()]);
    $_SESSION['yf_seen'] = time();
}

/* ================= 列表 ================= */
function yf_filter(): string
{
    $f = (string)($_GET['f'] ?? '');
    return in_array($f, ['hot', 'good'], true) ? $f : '';
}

function yf_tab(string $val, string $zh, string $en, array $base = []): string
{
    $f = yf_filter();
    $q = yf_url($base + ['f' => $val !== '' ? $val : null]);
    return '<a href="' . e($q) . '"' . ($f === $val ? ' class="on"' : '') . '>' . e(yf_en() ? $en : $zh) . '</a>';
}

function yf_row(array $th, bool $withBoard): string
{
    $u = yf_user((int)$th['user_id']);
    $board = null;
    if ($withBoard && !empty($th['board_id'])) {
        $b = yf_boards_stats()[(int)$th['board_id']] ?? null;
        if ($b) { $board = $b; }
    }
    $meta = '<span>' . e(yf_uname($u)) . '</span>';
    if ($board) { $meta = '<a class="bchip" href="' . e(yf_url(['b' => (int)$board['id']])) . '">' . e(yf_bname($board)) . '</a>' . $meta; }
    $meta .= '<span>·</span><time>' . e(yf_ago((string)$th['created_at'])) . '</time>';
    $last = '';
    if ((int)$th['replies'] > 0) {
        $lu = yf_user((int)($th['last_user_id'] ?: $th['user_id']));
        $last = '<span>· ' . e(yf_t('最后回复', 'last reply')) . ' ' . e(yf_uname($lu)) . ' ' . e(yf_ago((string)$th['last_at'])) . '</span>';
    }
    $un = isset(yf_unread()['ids'][(int)$th['id']]);
    return '<li class="yf-thread' . ($un ? ' unread' : '') . '"><a class="yf-ava" href="' . e(yf_url(['u' => (int)$th['user_id']])) . '">' . yf_ava($u, 36) . '</a>'
        . '<div class="tc"><div class="tt">' . yf_badges($th) . '<a href="' . e(yf_url(['t' => (int)$th['id']])) . '">' . e((string)$th['title']) . '</a></div>'
        . '<div class="tm">' . $meta . $last . '</div></div>'
        . '<div class="cnt"><span><b>' . (int)$th['replies'] . '</b>' . e(yf_t('回复', 'replies')) . '</span>'
        . '<span><b>' . (int)$th['views'] . '</b>' . e(yf_t('查看', 'views')) . '</span></div></li>';
}

function yf_board_row(array $b): string
{
    $bid = (int)$b['id'];
    $all = yf_boards_stats();
    $bc = $all[$bid] ?? $b;
    $tc = (int)($bc['tc'] ?? 0);
    $rc = (int)($bc['rc'] ?? 0);
    $last = '';
    $lt = yf_board_last_map()[$bid] ?? null;
    if ($lt) {
        $lu = isset($lt['username']) ? ['username' => $lt['username'], 'display_name' => $lt['display_name']] : yf_user((int)($lt['last_user_id'] ?: $lt['user_id']));
        $last = '<div class="bl"><b>' . e(yf_t('最后发表', 'Last post')) . '</b>'
            . '<a href="' . e(yf_url(['t' => (int)$lt['id']])) . '">' . e(mb_substr((string)$lt['title'], 0, 22)) . '</a>'
            . e(yf_uname($lu)) . ' · ' . e(yf_ago((string)$lt['last_at'])) . '</div>';
    }
    $ud = (int)(yf_unread()['boards'][$bid] ?? 0);
    return '<div class="yf-brow">'
        /* ★ yf-ico-svg-20261020：首字 → SVG 线条图标（与主站 lucide 同一套语言） */
        . '<a class="bb" href="' . e(yf_url(['b' => $bid])) . '"><span class="yf-ico">' . yf_board_icon((string)($b['slug'] ?? '')) . '</span>'
        . '<div style="min-width:0"><b>' . e(yf_bname($b)) . ($ud > 0 ? ' <span class="yf-dotr">' . $ud . '</span>' : '') . '</b><p>' . e((string)$b['intro']) . '</p></div></a>'
        . '<div class="bc"><b>' . $tc . '</b>' . e(yf_t('主题', 'topics')) . '</div>'
        . '<div class="bc"><b>' . $rc . '</b>' . e(yf_t('回复', 'replies')) . '</div>'
        . $last . '</div>';
}

/* ================= 首页 ================= */
function yf_index_view(string $err = ''): string
{
    /* ★ yf-community-20261020：中间区**只列帖子**，不放板块卡片（照 linux.sb 的 forum-main）。
       顶部只留一排排序 tab（linux.sb 是 新评论/新帖子/精华/热议/申精/抽奖/发卡/红包，
       我们只做跟站内有关的三个：最新 / 热议 / 精华）。
       板块切换交给左栏，不再进主区。 */
    $T = Db::t('forum_threads');
    $f = (string)($_GET['sort'] ?? 'new');
    if (!in_array($f, ['new', 'hot', 'good'], true)) { $f = 'new'; }

    $where = "status='normal'";
    $order = 'pinned DESC, COALESCE(last_at, created_at) DESC, id DESC';
    if ($f === 'hot') { $order = 'pinned DESC, replies DESC, views DESC, id DESC'; }
    if ($f === 'good') { $where .= ' AND featured=1'; }

    $threads = Db::rows("SELECT * FROM {$T} WHERE {$where} ORDER BY {$order} LIMIT 30");
    $rows = '';
    foreach ($threads as $th) { $rows .= yf_row($th, true); }
    if ($rows === '') {
        $rows = '<div class="yf-empty">' . e(yf_t('还没有帖子，来发第一帖吧。', 'No topics yet — start the first one.')) . '</div>';
    }

    $tabs = '';
    foreach (['new' => ['最新', 'Newest'], 'hot' => ['热议', 'Hot'], 'good' => ['精华', 'Featured']] as $k => $lb) {
        $tabs .= '<a class="' . ($f === $k ? 'on' : '') . '" href="' . e(yf_url(['sort' => $k])) . '">' . e(yf_t($lb[0], $lb[1])) . '</a>';
    }

    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    $head = '<div class="yf-head"><h1>' . e(yf_t('社区', 'Community')) . '</h1>'
        . '<p class="yf-sub">' . e(yf_t('浏览器用得顺手不顺手，来这里问、来聊——干净、轻快、不吵。', 'Ask and talk about browsers — clean, light, no noise.')) . '</p></div>';
    $hint = !\Auth::check()
        ? '<p class="yf-sub" style="color:hsl(var(--muted-foreground));margin-top:-6px">'
          . e(yf_t('游客可浏览；登录后可发帖与回帖。', 'Guests can browse; log in to post and reply.')) . '</p>' : '';
    $total = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE status='normal'");
    $bar = '<div class="yf-toolbar"><div class="yf-tabs">' . $tabs . '</div>'
        . '<span class="yf-toolbar-n">' . $total . ' ' . e(yf_t('个主题', 'topics')) . '</span></div>';

    return '<div class="yf-wrap">' . $head . $errHtml . $hint . $bar
        . '<ul class="yf-tlist">' . $rows . '</ul></div>';
}

/* ================= 全部主题（列表） ================= */
function yf_list_view(string $err = ''): string
{
    $f = yf_filter();
    $page = max(1, (int)($_GET['p'] ?? 1));
    $per = 20;
    $T = Db::t('forum_threads');
    $where = "status='normal'";
    $order = 'pinned DESC, last_at DESC, id DESC';
    if ($f === 'hot') { $order = 'pinned DESC, replies DESC, views DESC, id DESC'; }
    if ($f === 'good') { $where .= ' AND featured=1'; }
    $unreadOnly = (int)($_GET['unread'] ?? 0) === 1 && \Auth::check();
    if ($unreadOnly) {
        $uids = array_keys(yf_unread()['ids']);
        $where .= $uids === [] ? ' AND 1=0' : ' AND id IN (' . implode(',', array_map('intval', $uids)) . ')';
    }
    $total = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE {$where}");
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    $threads = Db::rows("SELECT * FROM {$T} WHERE {$where} ORDER BY {$order} LIMIT " . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $rows = '';
    foreach ($threads as $th) { $rows .= yf_row($th, true); }
    if ($rows === '') { $rows = '<div class="yf-empty">' . e($unreadOnly ? yf_t('没有未读主题，全部看完了。', 'Nothing unread — all caught up.') : yf_t('还没有帖子，来发第一帖吧。', 'No topics yet — start the first one.')) . '</div>'; }

    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    $head = '<div class="yf-head"><h1>' . e($unreadOnly ? yf_t('未读主题', 'Unread Topics') : yf_t('全部主题', 'All Topics')) . '</h1>'
        . '<p class="yf-sub">' . e($unreadOnly ? yf_t('只看有未读回复的主题；进入主题即标记已读。', 'Only topics with unread replies; opening one marks it read.') : yf_t('按最新回复、热度或精华筛选。', 'Filter by latest reply, activity or featured.')) . '</p></div>';
    $bar = '<div class="yf-bar"><a class="yf-btn2 sm" href="' . e(yf_url()) . '">← ' . e(yf_t('返回社区', 'Back to community')) . '</a><span style="color:#9a9a90;font-size:13px">' . $total . ' ' . e(yf_t('个主题', 'topics')) . '</span>'
        . ($unreadOnly ? '<form method="post" action="' . e(yf_url()) . '" style="display:inline;margin:0">' . \Csrf::field()
            . '<input type="hidden" name="action" value="mark_all_read"><button class="yf-btn2 sm" type="submit">' . e(yf_t('全部标记已读', 'Mark all read')) . '</button></form>' : '')
        . '<a class="yf-btn" style="margin-left:auto" href="' . e(yf_url(['mode' => 'new'])) . '">+ ' . e(yf_t('发新帖', 'New Topic')) . '</a></div>';
    $tabs = '<div class="yf-tabs">' . ($unreadOnly ? '' : yf_tab('', '最新', 'Latest') . yf_tab('hot', '最热', 'Hot') . yf_tab('good', '精华', 'Featured')) . '</div>';
    return '<div class="yf-wrap">' . $head . $errHtml . $bar . $tabs
        . '<ul class="yf-tlist">' . $rows . '</ul>' . yf_pager($page, $pages, $unreadOnly ? ['view' => 'all', 'unread' => 1] : [])
        . '<p style="color:#9a9a90;font-size:12.5px;margin-top:18px;">Powered by YeCha Forum</p></div>';
}

/* ================= 板块页 ================= */
function yf_board_title(string $b): string
{
    $row = yf_board($b);
    $name = $row ? yf_bname($row) : yf_t('版块', 'Board');
    return $name . ' · ' . yf_t('社区', 'Community') . ' · ' . site_label();
}

function yf_board_view(string $b, string $err = ''): string
{
    $board = yf_board($b);
    if ($board === null) { return yf_notfound_html(); }
    $f = yf_filter();
    $page = max(1, (int)($_GET['p'] ?? 1));
    $per = 20;
    $T = Db::t('forum_threads');
    $where = "board_id=? AND status='normal'";
    $args = [(int)$board['id']];
    $order = 'pinned DESC, last_at DESC, id DESC';
    if ($f === 'hot') { $order = 'pinned DESC, replies DESC, views DESC, id DESC'; }
    if ($f === 'good') { $where .= ' AND featured=1'; }
    $total = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE {$where}", $args);
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    $threads = Db::rows("SELECT * FROM {$T} WHERE {$where} ORDER BY {$order} LIMIT " . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per), $args);
    $rows = '';
    foreach ($threads as $th) { $rows .= yf_row($th, false); }
    if ($rows === '') { $rows = '<div class="yf-empty">' . e(yf_t('这里还没有帖子，来发第一帖吧。', 'No topics yet — start the first one.')) . '</div>'; }
    $crumbs = '<div class="yf-crumbs"><a href="' . e(yf_url()) . '">' . e(yf_t('社区', 'Community')) . '</a> › <span>' . e(yf_bname($board)) . '</span></div>';
    $head = '<div class="yf-head"><h1>' . e(yf_bname($board)) . '</h1><p class="yf-sub">' . e((string)$board['intro']) . '</p></div>';
    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    $bar = '<div class="yf-bar"><a class="yf-btn2 sm" href="' . e(yf_url()) . '">← ' . e(yf_t('返回', 'Back')) . '</a>'
        . '<span style="color:#9a9a90;font-size:13px">' . $total . ' ' . e(yf_t('主题', 'topics')) . '</span>'
        . '<a class="yf-btn" style="margin-left:auto" href="' . e(yf_url(['mode' => 'new', 'b' => (int)$board['id']])) . '">+ ' . e(yf_t('发新帖', 'New Topic')) . '</a></div>';
    $tabs = '<div class="yf-tabs">' . yf_tab('', '最新', 'Latest', ['b' => (int)$board['id']]) . yf_tab('hot', '最热', 'Hot', ['b' => (int)$board['id']]) . yf_tab('good', '精华', 'Featured', ['b' => (int)$board['id']]) . '</div>';
    return '<div class="yf-wrap">' . $crumbs . $errHtml . $head . $bar . $tabs
        . '<ul class="yf-tlist">' . $rows . '</ul>' . yf_pager($page, $pages, ['b' => (int)$board['id']]) . '</div>';
}

/* ================= 帖子详情 ================= */
function yf_thread_title(int $t): string
{
    $cached = $GLOBALS['YF_TH_TITLE'][$t] ?? null;
    if ($cached !== null) { return (string)$cached . ' · ' . site_label(); }
    $row = Db::row('SELECT title FROM ' . Db::t('forum_threads') . " WHERE id=? AND status='normal' LIMIT 1", [$t]);
    return ($row ? (string)$row['title'] : yf_t('帖子', 'Topic')) . ' · ' . site_label();
}

/** 楼层作者：优先用 JOIN 出来的 users 字段，避免每层一次查询 */
function yf_urow(array $r): array
{
    if (isset($r['username'])) {
        return ['id' => (int)($r['user_id'] ?? 0), 'username' => (string)$r['username'],
            'display_name' => (string)($r['display_name'] ?? ''), 'avatar' => (string)($r['avatar'] ?? '')];
    }
    return yf_user((int)($r['user_id'] ?? 0));
}

/** @return string|null null = 主题/楼层不存在（调用方给 404） */
function yf_thread_view(int $tid, string $err = '', int $focusPost = 0): ?string
{
    $GLOBALS['YF_ON_THREAD'] = true;
    $T = Db::t('forum_threads');
    $R = Db::t('forum_replies');
    $uid = \Auth::check() ? (int)\Auth::id() : 0;
    $th = Db::row("SELECT t.*, b.name b_name, b.name_en b_name_en, b.slug b_slug,"
        . ' u.username, u.display_name, u.avatar,'
        . ' (SELECT COUNT(*) FROM ' . Db::t('forum_drafts') . " d WHERE d.user_id=? AND d.kind='reply' AND d.thread_id=t.id) draft_n"
        . " FROM {$T} t LEFT JOIN " . Db::t('forum_boards') . ' b ON b.id=t.board_id'
        . ' LEFT JOIN ' . Db::t('users') . ' u ON u.id=t.user_id'
        . " WHERE t.id=? AND t.status='normal' LIMIT 1", [$uid, $tid]);
    if ($th === null) { return null; }
    $GLOBALS['YF_TH_BOARD'][$tid] = (int)$th['board_id'];
    $GLOBALS['YF_TH_TITLE'][$tid] = (string)$th['title'];
    $board = $th['b_name'] !== null ? ['id' => (int)$th['board_id'], 'name' => (string)$th['b_name'],
        'name_en' => (string)($th['b_name_en'] ?? ''), 'slug' => (string)($th['b_slug'] ?? '')] : null;

    $hasSess = session_status() === PHP_SESSION_ACTIVE;
    if (!($hasSess && !empty($_SESSION['yfv'][$tid]))) {
        Db::run("UPDATE {$T} SET views=views+1 WHERE id=?", [$tid]);
        if ($hasSess) { $_SESSION['yfv'][$tid] = 1; }
    }
    yf_mark_read($tid);

    $per = 20;
    $rtotal = (int)Db::one("SELECT COUNT(*) FROM {$R} WHERE thread_id=? AND status='normal'", [$tid]); // yf-20261022: 实时统计，避免计数器漂移导致“显示N条却空白”
    $rpages = max(1, (int)ceil($rtotal / $per));
    $rp = max(1, (int)($_GET['rp'] ?? 1));
    if ($focusPost > 0) {
        $chk = Db::row("SELECT COUNT(*) pos, SUM(id=?) found FROM {$R} WHERE thread_id=? AND status='normal' AND id<=?", [$focusPost, $tid, $focusPost]);
        if ($chk === null || (int)$chk['found'] === 0) { return null; }
        $pos = (int)$chk['pos'];
        if ($pos <= 0) { return null; }
        $rp = (int)ceil($pos / $per);
    }
    $rp = min(max(1, $rp), $rpages);
    $replies = Db::rows("SELECT r.*, u.username, u.display_name, u.avatar FROM {$R} r LEFT JOIN " . Db::t('users')
        . " u ON u.id=r.user_id WHERE r.thread_id=? AND r.status='normal' ORDER BY r.id ASC LIMIT " . (int)$per . ' OFFSET ' . (int)(($rp - 1) * $per), [$tid]);

    // 引用内容：一次取回本页引用的回帖
    $qids = [];
    foreach ($replies as $r) { if ((int)($r['quote_id'] ?? 0) > 0) { $qids[] = (int)$r['quote_id']; } }
    $qmap = [];
    if ($qids !== []) {
        $in = implode(',', array_fill(0, count($qids), '?'));
        foreach (Db::rows("SELECT r.id, r.user_id, r.content, u.username, u.display_name FROM {$R} r LEFT JOIN " . Db::t('users')
            . " u ON u.id=r.user_id WHERE r.id IN ({$in})", $qids) as $qr) { $qmap[(int)$qr['id']] = $qr; }
    }
    // 点赞：整页一次聚合
    $rxs = yf_reactions_multi(['thread' => [$tid], 'reply' => array_map(static fn(array $r): int => (int)$r['id'], $replies)]);
    $tx = $rxs['thread'][$tid] ?? [];

    $au = yf_urow($th);
    $crumbs = '<div class="yf-crumbs"><a href="' . e(yf_url()) . '">' . e(yf_t('社区', 'Community')) . '</a> › '
        . ($board ? '<a href="' . e(yf_url(['b' => (int)$board['id']])) . '">' . e(yf_bname($board)) . '</a> › ' : '')
        . '<span>' . e(mb_substr((string)$th['title'], 0, 30)) . '</span></div>';
    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    $mods = '';
    if (\Auth::isAdmin()) {
        $mks = [
            [(int)$th['pinned'] === 1 ? 'unpin' : 'pin', (int)$th['pinned'] === 1 ? yf_t('取消置顶', 'Unpin') : yf_t('置顶', 'Pin')],
            [(int)$th['featured'] === 1 ? 'ungood' : 'good', (int)$th['featured'] === 1 ? yf_t('取消精华', 'Unfeature') : yf_t('精华', 'Feature')],
            [(int)$th['locked'] === 1 ? 'unlock' : 'lock', (int)$th['locked'] === 1 ? yf_t('解锁', 'Unlock') : yf_t('锁定', 'Lock')],
        ];
        foreach ($mks as $m) {
            $mods .= '<form method="post" action="' . e(yf_url(['t' => $tid])) . '">' . \Csrf::field()
                . '<input type="hidden" name="action" value="mod_thread"><input type="hidden" name="id" value="' . $tid . '">'
                . '<input type="hidden" name="what" value="' . $m[0] . '"><button class="yf-btn2 sm" type="submit">' . e($m[1]) . '</button></form>';
        }
        $mods .= '<form method="post" action="' . e(yf_url(['t' => $tid])) . '" onsubmit="return confirm(\'删除该帖及其回帖？\')">' . \Csrf::field()
            . '<input type="hidden" name="action" value="mod_thread"><input type="hidden" name="id" value="' . $tid . '"><input type="hidden" name="what" value="del">'
            . '<button class="yf-btn2 sm" type="submit" style="color:#b33">' . e(yf_t('删除', 'Delete')) . '</button></form>';
    }
    $own = yf_can_edit((int)$th['user_id']);
    $opActions = '';
    if ($own) {
        $opActions = '<a href="' . e(yf_url(['mode' => 'edit', 't' => $tid])) . '">' . e(yf_t('编辑', 'Edit')) . '</a>';
        $opActions .= '<form method="post" action="' . e(yf_url(['t' => $tid])) . '" onsubmit="return confirm(\'删除该帖？\')" style="display:inline">' . \Csrf::field()
            . '<input type="hidden" name="action" value="del_thread"><input type="hidden" name="id" value="' . $tid . '"><button type="submit">' . e(yf_t('删除', 'Delete')) . '</button></form>';
    }
    $edited = !empty($th['updated_at']) && (string)$th['updated_at'] !== (string)$th['created_at'] ? ' · ' . e(yf_t('已编辑', 'edited')) : '';
    $op = '<h1 class="yf-ttl">' . yf_badges($th) . e((string)$th['title']) . '</h1>'
        . '<div class="yf-thmeta">'
        . ($board ? '<a class="bchip" href="' . e(yf_url(['b' => (int)$board['id']])) . '">' . e(yf_bname($board)) . '</a>' : '')
        . '<span>' . e(yf_t('楼主', 'by')) . ' <a href="' . e(yf_url(['u' => (int)$th['user_id']])) . '" style="color:#B3541E;text-decoration:none">' . e(yf_uname($au)) . '</a></span>'
        . '<time>' . e(yf_dt((string)$th['created_at'])) . $edited . '</time>'
        . '<span>· ' . (int)$th['views'] . ' ' . e(yf_t('查看', 'views')) . '</span>'
        . '<span>· ' . $rtotal . ' ' . e(yf_t('回复', 'replies')) . '</span>'
        . '<span style="margin-left:auto">' . yf_share_btn($tid, 0) . '</span></div>'
        . ($mods !== '' ? '<div class="yf-mods">' . $mods . '</div>' : '')
        . '<div class="yf-floor-card op" id="pt' . $tid . '"><div class="yf-fhead"><span class="fn">#1</span>'
        . '<a class="yf-ava" href="' . e(yf_url(['u' => (int)$th['user_id']])) . '">' . yf_ava($au, 26) . '</a>'
        . '<span class="fname">' . e(yf_uname($au)) . '</span><span>' . e(yf_dt((string)$th['created_at'])) . '</span>'
        . '<span class="fx"><span class="yf-tag pin">' . e(yf_t('楼主', 'OP')) . '</span>' . $opActions . '</span></div>'
        . '<div class="yf-fbody">' . yf_md((string)$th['content']) . '</div>'
        . '<div class="yf-facts" style="margin:0 16px 12px">' . yf_react_form('thread', $tid, $tid, $tx) . '<span style="flex:1"></span>' . yf_share_btn($tid, 0) . '</div></div>';

    $repHtml = '';
    $idx = ($rp - 1) * $per + 2;
    foreach ($replies as $r) {
        $rid = (int)$r['id'];
        $ru = yf_urow($r);
        $quote = '';
        $qid = (int)($r['quote_id'] ?? 0);
        if ($qid > 0 && isset($qmap[$qid])) {
            $qu = yf_urow($qmap[$qid]);
            $quote = '<div class="yf-quote"><b>@' . e(yf_uname($qu)) . '</b>' . e(yf_t(' 的楼层', ' wrote')) . ':'
                . '<span class="qp">' . e(yf_excerpt((string)$qmap[$qid]['content'], 160)) . '</span></div>';
        }
        $acts = '<a href="' . e(yf_url(['t' => $tid, 'quote' => $rid])) . '#yf-reply">' . e(yf_t('引用', 'Quote')) . '</a>';
        if (yf_can_edit((int)$r['user_id'])) {
            $acts .= '<a href="' . e(yf_url(['mode' => 'edit', 'rid' => $rid])) . '">' . e(yf_t('编辑', 'Edit')) . '</a>';
            $acts .= '<form method="post" action="' . e(yf_url(['t' => $tid])) . '" onsubmit="return confirm(\'删除该回帖？\')" style="display:inline">' . \Csrf::field()
                . '<input type="hidden" name="action" value="del_reply"><input type="hidden" name="id" value="' . $rid . '"><button type="submit">' . e(yf_t('删除', 'Delete')) . '</button></form>';
        }
        $redited = !empty($r['edited_at']) ? ' · ' . e(yf_t('已编辑', 'edited')) : '';
        $repHtml .= '<div class="yf-floor-card" id="p' . $rid . '"><a id="yf-r' . $rid . '"></a><div class="yf-fhead"><span class="fn">#' . $idx . '</span>'
            . '<a class="yf-ava" href="' . e(yf_url(['u' => (int)$r['user_id']])) . '">' . yf_ava($ru, 26) . '</a>'
            . '<span class="fname">' . e(yf_uname($ru)) . '</span><span>' . e(yf_dt((string)$r['created_at'])) . $redited . '</span>'
            . '<span class="fx">' . $acts . yf_share_btn($tid, $rid) . '</span></div>'
            . '<div class="yf-fbody">' . $quote . yf_md((string)$r['content']) . '</div>'
            . '<div class="yf-facts" style="margin:0 16px 12px">' . yf_react_form('reply', $rid, $tid, $rxs['reply'][$rid] ?? []) . '</div></div>';
        $idx++;
    }
    if ($repHtml === '') { $repHtml = '<div class="yf-empty">' . e(yf_t('还没有回帖，坐个沙发吧。', 'No replies yet — be the first.')) . '</div>'; }

    $replyBox = '';
    if ((int)$th['locked'] === 1 && !\Auth::isAdmin()) {
        $replyBox = '<div class="yf-form" id="yf-reply"><p style="margin:0;color:#8a8272">' . e(yf_t('该帖已锁定，不再接受新回复。', 'This topic is locked and no longer accepts replies.')) . '</p></div>';
    } elseif (\Auth::check()) {
        $quoteId = (int)($_GET['quote'] ?? 0);
        $quoteText = '';
        if ($quoteId > 0) {
            $q = Db::row("SELECT r.*, u.username, u.display_name FROM {$R} r LEFT JOIN " . Db::t('users') . " u ON u.id=r.user_id WHERE r.id=? AND r.thread_id=? AND r.status='normal' LIMIT 1", [$quoteId, $tid]);
            if ($q) { $quoteText = '> @' . yf_uname(['display_name' => $q['display_name'], 'username' => $q['username']]) . '：' . yf_excerpt((string)$q['content'], 200) . "\n\n"; }
        }
        $repDraft = (\Auth::check() && (int)($th['draft_n'] ?? 0) > 0) ? yf_draft_get('reply', $tid) : null;
        $restore = (int)($_GET['restore'] ?? 0) === 1;
        $draftBar = ($repDraft !== null && !$restore)
            ? yf_draft_bar($repDraft, yf_url(['t' => $tid, 'restore' => 1]) . '#yf-reply', yf_url(['t' => $tid]) . '#yf-reply') : '';
        $prefill = ($restore && $repDraft !== null) ? (string)$repDraft['content'] : $quoteText;
        $replyBox = '<div class="yf-form" id="yf-reply"><h3>' . e(yf_t('写回帖', 'Write a reply')) . '</h3>' . $draftBar
            . '<form method="post" action="' . e(yf_url(['t' => $tid])) . '" data-yf-draft="reply" data-thread="' . $tid . '">' . \Csrf::field()
            . '<input type="hidden" name="website2" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">'
            . '<input type="hidden" name="action" value="reply"><input type="hidden" name="thread_id" value="' . (int)$th['id'] . '">'
            . '<input type="hidden" name="quote_id" value="' . ($quoteId > 0 ? $quoteId : 0) . '">'
            . '<label>' . e(yf_t('内容（支持 Markdown）', 'Content (Markdown supported)')) . '</label>'
            . '<textarea name="content" required minlength="2" maxlength="60000" placeholder="' . e(yf_t('有话直说，说清楚就好。', 'Say it plainly and clearly.')) . '">' . e($prefill) . '</textarea>'
            . '<div class="row"><button class="yf-btn" type="submit">' . e(yf_t('发表回帖', 'Post Reply')) . '</button>'
            . ($quoteId > 0 ? '<a class="yf-btn2 sm" href="' . e(yf_url(['t' => $tid])) . '#yf-reply">' . e(yf_t('取消引用', 'Cancel quote')) . '</a>' : '')
            . '<span class="yf-draftstate" id="yf-draft-state"></span>'
            . '</div><div class="hint">' . e(yf_t('发言请友善；草稿会自动保存。', 'Be kind. Drafts are saved automatically.')) . '</div></form></div>';
    } else {
        $replyBox = '<div class="yf-form" id="yf-reply" style="text-align:center;color:#6f767e;font-size:13.5px">'
            . e(yf_t('登录后即可参与回帖。', 'Log in to join the discussion.')) . ' <a href="' . e(\ui_login(yf_url(['t' => $tid]))) . '" style="color:#B3541E">' . e(yf_t('去登录 →', 'Log in →')) . '</a></div>';
    }
    $back = '<div style="margin:14px 0 0"><a class="yf-btn2 sm" href="' . e(yf_url(['b' => (int)$th['board_id']])) . '">← ' . e(yf_t('返回版块', 'Back to board')) . '</a></div>';
    $focusJs = $focusPost > 0
        ? '<script>try{var el=document.getElementById("p' . $focusPost . '");if(el){el.classList.add("yf-target");el.scrollIntoView({block:"center"});}}catch(e){}</script>'
        : '';
    return '<div class="yf-wrap">' . $crumbs . $errHtml . $op
        . '<div class="yf-sec"><h2>' . $rtotal . ' ' . e(yf_t('条回复', 'replies')) . '</h2></div>'
        . $repHtml . yf_pager($rp, $rpages, ['t' => $tid], 'rp')
        . $replyBox . $back . $focusJs . '</div>';
}

/* ================= 搜索 ================= */
function yf_search_view(string $q): string
{
    $q = mb_substr(trim($q), 0, 60);
    $page = max(1, (int)($_GET['p'] ?? 1));
    $per = 20;
    $T = Db::t('forum_threads');
    $like = '%' . $q . '%';
    $total = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE status='normal' AND (title LIKE ? OR content LIKE ?)", [$like, $like]);
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    $rows = '';
    foreach (Db::rows("SELECT * FROM {$T} WHERE status='normal' AND (title LIKE ? OR content LIKE ?) ORDER BY last_at DESC, id DESC LIMIT " . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per), [$like, $like]) as $th) {
        $rows .= yf_row($th, true);
    }
    if ($rows === '') { $rows = '<div class="yf-empty">' . e(yf_t('没有匹配的主题。', 'No matching topics.')) . '</div>'; }
    return '<div class="yf-wrap"><div class="yf-crumbs"><a href="' . e(yf_url()) . '">' . e(yf_t('社区', 'Community')) . '</a> › <span>' . e(yf_t('搜索', 'Search')) . '</span></div>'
        . '<div class="yf-head"><h1>' . e(yf_t('搜索', 'Search')) . '</h1><p class="yf-sub">' . e(yf_t('关键词', 'Keyword')) . ' “' . e($q) . '” · ' . $total . ' ' . e(yf_t('个结果', 'results')) . '</p></div>'
        . '<form method="get" action="/forum-search.html" style="display:flex;gap:6px;margin:0 0 14px"><input type="search" name="q" value="' . e($q) . '" style="flex:1;min-width:0;border:1px solid #e2ddd2;border-radius:999px;padding:8px 14px;font:inherit"><button class="yf-btn" type="submit">' . e(yf_t('搜索', 'Search')) . '</button></form>'
        . '<ul class="yf-tlist">' . $rows . '</ul>' . yf_pager($page, $pages, ['q' => $q]) . '</div>';
}

/* ================= 个人资料 ================= */
function yf_profile_view(int $uid): string
{
    $u = Db::row('SELECT * FROM ' . Db::t('users') . ' WHERE id=? LIMIT 1', [$uid]);
    if ($u === null) { return yf_notfound_html(); }
    $T = Db::t('forum_threads');
    $R = Db::t('forum_replies');
    $tc = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE user_id=? AND status='normal'", [$uid]);
    $rc = (int)Db::one("SELECT COUNT(*) FROM {$R} WHERE user_id=? AND status='normal'", [$uid]);
    $role = (string)($u['role'] ?? '');
    $roleName = $role === 'admin' ? yf_t('管理员', 'Admin') : ($role === 'subscriber' ? yf_t('订阅会员', 'Subscriber') : yf_t('会员', 'Member'));
    $joined = !empty($u['created_at']) ? date('Y-m-d', strtotime((string)$u['created_at'])) : '';
    $bio = trim((string)($u['bio'] ?? ''));
    $th = '';
    foreach (Db::rows("SELECT * FROM {$T} WHERE user_id=? AND status='normal' ORDER BY last_at DESC, id DESC LIMIT 8", [$uid]) as $t) {
        $th .= '<li class="yf-thread"><div class="tc"><div class="tt"><a href="' . e(yf_url(['t' => (int)$t['id']])) . '">' . e((string)$t['title']) . '</a></div>'
            . '<div class="tm"><span>' . e(yf_ago((string)$t['created_at'])) . '</span><span>· ' . (int)$t['replies'] . ' ' . e(yf_t('回复', 'replies')) . '</span></div></div></li>';
    }
    if ($th === '') { $th = '<div class="yf-empty">' . e(yf_t('还没有发过主题。', 'No topics yet.')) . '</div>'; }
    $rp = '';
    foreach (Db::rows("SELECT r.*, t.title ttitle FROM {$R} r JOIN {$T} t ON t.id=r.thread_id WHERE r.user_id=? AND r.status='normal' ORDER BY r.id DESC LIMIT 8", [$uid]) as $r) {
        $rp .= '<li class="yf-thread"><div class="tc"><div class="tt"><a href="' . e(yf_url(['t' => (int)$r['thread_id']])) . '#yf-r' . (int)$r['id'] . '">' . e(mb_substr((string)$r['ttitle'], 0, 40)) . '</a></div>'
            . '<div class="tm"><span>' . e(yf_excerpt((string)$r['content'], 70)) . '</span><span>· ' . e(yf_ago((string)$r['created_at'])) . '</span></div></div></li>';
    }
    if ($rp === '') { $rp = '<div class="yf-empty">' . e(yf_t('还没有回帖。', 'No replies yet.')) . '</div>'; }
    return '<div class="yf-wrap"><div class="yf-crumbs"><a href="' . e(yf_url()) . '">' . e(yf_t('社区', 'Community')) . '</a> › <span>' . e(yf_uname($u)) . '</span></div>'
        . '<div class="yf-prof">' . yf_ava($u, 64) . '<div><div class="pn">' . e(yf_uname($u)) . '</div>'
        . '<div class="pm">' . e($roleName) . ($joined !== '' ? ' · ' . e(yf_t('注册于', 'joined')) . ' ' . e($joined) : '') . '</div>'
        . ($bio !== '' ? '<div class="pm">' . e($bio) . '</div>' : '')
        . '<div class="yf-kv"><span><b>' . $tc . '</b>' . e(yf_t('主题', 'topics')) . '</span><span><b>' . $rc . '</b>' . e(yf_t('回帖', 'replies')) . '</span></div></div></div>'
        . '<div class="yf-sec"><h2>' . e(yf_t('最近主题', 'Recent topics')) . '</h2></div><ul class="yf-tlist">' . $th . '</ul>'
        . '<div class="yf-sec"><h2>' . e(yf_t('最近回帖', 'Recent replies')) . '</h2></div><ul class="yf-tlist">' . $rp . '</ul></div>';
}

/* ================= 发帖 / 编辑 ================= */
/**
 * ★ yf-toolbar-20261020：编辑器格式工具栏
 *   论坛正文是 Markdown，这里只是「往选区插语法」的快捷按钮，不引富文本编辑器（保持轻量）。
 *   支持：加粗 B / 斜体 I / 标题 / 链接 / 图片 / 行内代码 / 引用 / 列表。
 *   data-tb 指定操作，事件委托到 document，多个编辑器（发帖、回帖、编辑）共用一套。
 */
/** ★ yf-tb-js-20261020：工具栏 → 往 Markdown 插语法（纯选区操作，零依赖）
 *  用 nowdoc 存脚本，避免把它塞进 yf_js 的 PHP 字符串里导致转义出错。 */
function yf_toolbar_js(): string
{
    static $emit = false;
    if ($emit) { return ''; }
    $emit = true;
    return <<<'YFTBJS'
<script>
(function(){
  if (window.__yfTb) { return; } window.__yfTb = 1;
  function fire(ta){ ta.dispatchEvent(new Event('input', { bubbles: true })); }
  function wrap(ta, before, after, ph){
    var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    var sel = v.slice(s, e) || ph || '';
    var head = v.slice(0, s), tail = v.slice(e);
    var A = (after === null) ? before : after;
    if (A === before && head.slice(-before.length) === before && tail.slice(0, before.length) === before) {
      ta.value = head.slice(0, -before.length) + sel + tail.slice(before.length);
      ta.selectionStart = s - before.length; ta.selectionEnd = e - before.length;
    } else {
      ta.value = head + before + sel + A + tail;
      ta.selectionStart = s + before.length;
      ta.selectionEnd = s + before.length + sel.length;
    }
    ta.focus(); fire(ta);
  }
  function prefixLines(ta, pfx){
    var v = ta.value, s = ta.selectionStart, e = ta.selectionEnd;
    var ls = v.lastIndexOf('\n', s - 1) + 1;
    var nl = v.indexOf('\n', e), le = (nl === -1 ? v.length : nl);
    var block = v.slice(ls, le), lines = block.split('\n');
    var all = lines.every(function(l){ return l.indexOf(pfx) === 0; });
    lines = lines.map(function(l){ return all ? l.slice(pfx.length) : pfx + l; });
    var nb = lines.join('\n');
    ta.value = v.slice(0, ls) + nb + v.slice(le);
    ta.selectionStart = ls; ta.selectionEnd = ls + nb.length;
    ta.focus(); fire(ta);
  }
  /* ── yf-upload-20261020：本地上传 ────────────────────────────────
     点 🖼 选文件 / 直接粘贴截图 / 把图片拖进输入框，都会：
       1. 先插一行「![上传中 xxx]()」占位
       2. POST /forum-attach.html（服务端统一转 WebP、去重、落 uploads/forum/）
       3. 把占位替换成真正的 markdown
     失败不阻断：占位变成「![上传失败：原因]()」，用户能看见也删得掉。 */
  var MAXB = 5 * 1024 * 1024;
  function csrfVal(){
    var el = document.querySelector('input[name="_csrf"]');
    return el ? el.value : '';
  }
  function insertAt(ta, text){
    var s = ta.selectionStart, v = ta.value;
    ta.value = v.slice(0, s) + text + v.slice(s);
    ta.selectionStart = ta.selectionEnd = s + text.length;
    ta.focus(); fire(ta);
    return s;
  }
  function uploadOne(ta, file, ph, done){
    var fd = new FormData();
    fd.append('file', file);
    fd.append('_csrf', csrfVal());
    fetch('/forum-attach.html', {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrfVal() }
    }).then(function (r) {
      return r.json().catch(function () { return { ok: 0, msg: '服务器返回异常（' + r.status + '）' }; });
    }).then(function (j) {
      var md = (j && j.ok)
        ? '![' + String(file.name || '图片').replace(/[\[\]\(\)]/g, '') + '](' + j.url + ')'
        : '![上传失败：' + ((j && j.msg) || '未知错误') + ']()';
      ta.value = ta.value.replace(ph, md);
      fire(ta); done();
    }).catch(function () {
      ta.value = ta.value.replace(ph, '![上传失败：网络错误]()');
      fire(ta); done();
    });
  }
  function uploadList(ta, files){
    var arr = [].slice.call(files || []);
    if (!arr.length) { return; }
    var i = 0;
    (function next(){
      if (i >= arr.length) { return; }
      var f = arr[i++];
      if (f.size > MAXB) {
        insertAt(ta, '\n![已跳过：' + f.name + ' 超过 5MB]()\n');
        return next();
      }
      var ph = '![上传中 ' + (f.name || '') + ']()';
      insertAt(ta, ph);
      uploadOne(ta, f, ph, next);
    })();
  }
  function pick(ta){
    var inp = document.createElement('input');
    inp.type = 'file'; inp.accept = 'image/*'; inp.multiple = true;
    inp.style.display = 'none';
    document.body.appendChild(inp);
    inp.addEventListener('change', function(){
      uploadList(ta, inp.files);
      document.body.removeChild(inp);
    });
    inp.click();
  }
  /* 粘贴截图 / 拖拽图片 —— 绑到所有正文输入框 */
  function bindAttach(){
    [].forEach.call(document.querySelectorAll('textarea[name="content"]'), function (ta) {
      if (ta.__yfAtt) { return; }
      ta.__yfAtt = 1;
      ta.addEventListener('paste', function (ev) {
        var items = (ev.clipboardData || {}).items || [];
        var files = [];
        for (var i = 0; i < items.length; i++) {
          if (items[i].type && items[i].type.indexOf('image/') === 0) {
            var f = items[i].getAsFile();
            if (f) { files.push(f); }
          }
        }
        if (files.length) { ev.preventDefault(); uploadList(ta, files); }
      });
      ta.addEventListener('dragover', function (ev) { ev.preventDefault(); });
      ta.addEventListener('drop', function (ev) {
        if (!ev.dataTransfer || !ev.dataTransfer.files || !ev.dataTransfer.files.length) { return; }
        ev.preventDefault();
        uploadList(ta, ev.dataTransfer.files);
      });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindAttach);
  } else { bindAttach(); }

  document.addEventListener('click', function(ev){
    var b = ev.target.closest ? ev.target.closest('.yf-tb-b') : null;
    if (!b) { return; }
    ev.preventDefault();
    var scope = b.closest('.yf-form') || document;
    var ta = scope.querySelector('textarea[name="content"]');
    if (!ta) { return; }
    var op = b.getAttribute('data-tb');
    if (op === 'bold') { wrap(ta, '**', '**', '加粗文字'); }
    else if (op === 'italic') { wrap(ta, '*', '*', '斜体文字'); }
    else if (op === 'code') { wrap(ta, '`', '`', 'code'); }
    else if (op === 'h2') { prefixLines(ta, '## '); }
    else if (op === 'quote') { prefixLines(ta, '> '); }
    else if (op === 'ul') { prefixLines(ta, '- '); }
    else if (op === 'link') { wrap(ta, '[', '](https://example.com)', '链接文字'); }
    else if (op === 'img') { pick(ta); }
  }, false);
})();
</script>
YFTBJS;
}

/**
 * ★ yf-mirror-img-20261020：把正文里的外链图片抓回站内
 *   存 themes/bl/forum-img/<YYYYMM>/<hash>.<ext>（实测只有 /themes/ 下按扩展名放行，
 *   /uploads /assets /files /static 都被内核路由成 410）
 *   失败一律保留原 URL —— 不能因为对方站挂了就让人发不了帖。
 */
/* ══════════════════════════════════════════════════════════════════════════════
 * ★ yf-attach-20261020：社区附件存储规范
 *
 * 【目录规范】<站点根>/uploads/forum/
 *                 img/YYYY/MM/<sha1前16>.webp     图片（统一 WebP）
 *                 file/YYYY/MM/<sha1前16>.<ext>   其它附件（预留）
 *   · 按年月分层 —— 单目录文件数不会无限膨胀，日后清理/迁移按目录走
 *   · 不放 themes/ —— 换主题或覆盖 themes 目录会把图一起带走（旧实现的硬伤）
 *   · URL = /uploads/forum/img/2026/10/abc123.webp，nginx 静态直出、禁执行、长缓存
 *
 * 【格式规范】一律 WebP
 *   · jpeg / png / webp / 单帧 gif → 转 WebP（quality 82，最大边 1600，等比缩）
 *   · gif（含动图）→ 转 WebP：统一格式优先；动图会变成首帧静图（论坛场景动图极少）
 *   · avif → 保留 avif：本机 GD 未编 AVIF 解码
 *   · svg → 一律拒收（能带脚本）
 *
 * 【去重】文件名 = 内容 SHA1 前 16 位 ⇒ 同一张图重复引用只落一份盘
 * ══════════════════════════════════════════════════════════════════════════════ */

/** 附件根目录（绝对路径） */
function yf_attach_root(): string
{
    $rel = trim((string)get_option('forum.attach_dir', 'uploads/forum'), '/');
    if ($rel === '') { $rel = 'uploads/forum'; }
    return dirname(__DIR__, 2) . '/' . $rel;
}

/** 附件 URL 前缀（如 /uploads/forum） */
function yf_attach_prefix(): string
{
    $rel = trim((string)get_option('forum.attach_dir', 'uploads/forum'), '/');
    if ($rel === '') { $rel = 'uploads/forum'; }
    return '/' . $rel;
}

/** GIF 是否为多帧动图（图形控制扩展块出现 >1 次） */

/**
 * 图片字节 → WebP 字节。
 * @return array{0:string,1:string} [字节, 扩展名]；失败 ['','']
 */
function yf_img_to_webp(string $bytes, int $maxEdge = 1600, int $quality = 82): array
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) { return ['', '']; }

    $im = @imagecreatefromstring($bytes);
    if ($im === false) { return ['', '']; }

    /* ★ 调色板图（GIF / 部分 PNG）必须先转真彩色，否则 imagewebp() 直接返回 false
       —— 实测 GIF 全部走兜底、转不成 WebP 就是这个原因。 */
    if (function_exists('imagepalettetotruecolor') && !imageistruecolor($im)) {
        @imagepalettetotruecolor($im);
    }

    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 1 || $h < 1 || ($w * $h) > 50000000) {          /* >5000 万像素：防内存爆 */
        imagedestroy($im);
        return ['', ''];
    }

    if (max($w, $h) > $maxEdge) {                            /* 等比缩到最大边以内 */
        $ratio = $maxEdge / max($w, $h);
        $nw = max(1, (int)round($w * $ratio));
        $nh = max(1, (int)round($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);                     /* 保留 PNG/WebP 透明 */
        imagesavealpha($dst, true);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $im = $dst;
    }

    ob_start();
    $ok = @imagewebp($im, null, max(1, min(100, $quality)));
    $out = (string)ob_get_clean();
    imagedestroy($im);

    if (!$ok || $out === '') { return ['', '']; }
    return [$out, 'webp'];
}

/**
 * 把临时文件落盘成规范附件。
 * @return string 公开 URL；失败返回 ''
 */
function yf_attach_save(string $tmpFile, string $mime = ''): string
{
    if (!is_file($tmpFile)) { return ''; }
    $size = (int)@filesize($tmpFile);
    if ($size < 64 || $size > 5 * 1024 * 1024) { return ''; }   /* 5MB 上限 */

    $bytes = (string)@file_get_contents($tmpFile);
    if ($bytes === '') { return ''; }

    /* 以真实内容为准嗅探 MIME（不信扩展名，也不完全信响应头） */
    $info = @getimagesize($tmpFile);
    $real = is_array($info) ? strtolower((string)($info['mime'] ?? '')) : '';
    $mime = $real !== '' ? $real : strtolower(trim(explode(';', $mime)[0]));

    $allow = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
    if (!in_array($mime, $allow, true)) { return ''; }          /* SVG 等一律拒收 */

    $ext  = '';
    $data = '';

    if ($mime === 'image/avif') {
        $ext  = 'avif';                                         /* GD 无 AVIF 解码 */
        $data = $bytes;
    } else {
        $maxEdge = (int)get_option('forum.img_max_edge', 1600);
        $quality = (int)get_option('forum.img_quality', 82);
        [$data, $ext] = yf_img_to_webp($bytes, $maxEdge > 0 ? $maxEdge : 1600, $quality > 0 ? $quality : 82);
        if ($data === '' || $ext === '') {
            /* 转 WebP 失败（少见编码）→ 退回原格式，别让用户贴不上图 */
            $fb  = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $ext = $fb[$mime] ?? '';
            $data = $bytes;
            if ($ext === '') { return ''; }
        }
    }

    $sub = 'img/' . date('Y/m');
    $dir = yf_attach_root() . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { return ''; }

    $name = substr(sha1($data), 0, 16) . '.' . $ext;
    $dest = $dir . '/' . $name;

    if (!is_file($dest)) {
        if (@file_put_contents($dest, $data) === false) { return ''; }
        @chmod($dest, 0644);
        /* ★ 不要再 chown/chgrp：宝塔默认把 chown 写进 disable_functions，
           调用「未定义函数」是 Error（@ 抑制不住）⇒ 整个发帖 500。
           文件本来就是 www 进程创建的，属主天然是 www，这两行纯多余。 */
    }

    return yf_attach_prefix() . '/' . $sub . '/' . $name;
}

function yf_fetch_image(string $url): string
{
    $p = parse_url($url);
    if (!$p || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true)) { return ''; }
    $host = (string)($p['host'] ?? '');
    if ($host === '') { return ''; }

    /* SSRF 防护：域名→IP，内网/保留段直接跳过 */
    $ip = gethostbyname($host);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return '';
    }

    $tmp = @tempnam(sys_get_temp_dir(), 'yfimg');
    if ($tmp === false) { return ''; }

    $fp = @fopen($tmp, 'wb');
    if ($fp === false) { @unlink($tmp); return ''; }
    $got = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; yecha-forum/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => static function ($c, $dt, $d, $ut, $u) use (&$got) {
            $got = (int)$d;
            return ($d > 5 * 1024 * 1024) ? 1 : 0;   /* 超过 5MB 中断 */
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    fclose($fp);

    if ($ok === false || $code !== 200 || !is_file($tmp) || filesize($tmp) < 128) {
        @unlink($tmp); return '';
    }
    if (filesize($tmp) > 5 * 1024 * 1024) { @unlink($tmp); return ''; }

    /* ★ yf-attach-20261020：落盘交给 yf_attach_save() —— 统一目录 + 统一 WebP。 */
    $stored = yf_attach_save($tmp, $ctype);
    @unlink($tmp);
    return $stored;
}

/** 正文里的外链图片 → 站内；返回替换后的正文 */
/* ══════════════════════════════════════════════════════════════════════════════
 * ★ yf-upload-20261020：本地图片上传（AJAX，返回 JSON）
 *   POST /forum-attach.html   multipart: file=<二进制>  +  _csrf（或 X-CSRF-Token 头）
 *   成功 {ok:1,url,w,h,size} · 失败 {ok:0,msg}（HTTP 4xx）
 *   复用 yf_attach_save() ⇒ 目录、WebP、去重、大小限制全部一致。
 * ══════════════════════════════════════════════════════════════════════════════ */
function yf_attach_upload(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $fail = static function (string $msg, int $code = 400): void {
        http_response_code($code);
        echo json_encode(['ok' => 0, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (!\Auth::check()) { $fail('请先登录再上传', 401); }

    /* CSRF：优先请求头（AJAX 好带），退回表单字段 */
    $tok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? ''));
    if (!\Csrf::verify($tok)) { $fail('页面已过期，请刷新后重试', 403); }

    /* 限流：每人 10 分钟最多 30 张（Cache 计数，过期自动清） */
    $uid = (int)\Auth::id();
    $ck  = 'att_' . $uid;
    $n   = (int)(\Cache::get('forum', $ck) ?: 0);
    if ($n >= 30) { $fail('上传太频繁，请过一会儿再试', 429); }
    \Cache::set('forum', $ck, (string)($n + 1), 600);

    if (empty($_FILES['file']) || !is_array($_FILES['file'])) { $fail('没有收到文件'); }
    $f = $_FILES['file'];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { $fail('文件太大（上限 5MB）'); }
    if ($err !== UPLOAD_ERR_OK) { $fail('上传失败（错误码 ' . $err . '）'); }
    if ((int)$f['size'] > 5 * 1024 * 1024) { $fail('文件太大（上限 5MB）'); }
    if (!is_uploaded_file((string)$f['tmp_name'])) { $fail('非法上传'); }

    $url = yf_attach_save((string)$f['tmp_name'], (string)($f['type'] ?? ''));
    if ($url === '') { $fail('不支持的图片格式（支持 jpg / png / gif / webp）'); }

    /* 登记索引（去重键冲突时忽略，说明同一张图已登记过） */
    $abs  = dirname(__DIR__, 2) . $url;
    $base = basename($url);
    $hash = (string)substr($base, 0, (int)strpos($base, '.'));
    $wh   = @getimagesize($abs);
    try {
        Db::insert('forum_att', [
            'hash' => $hash, 'path' => $url,
            'mime' => is_array($wh) ? (string)($wh['mime'] ?? '') : '',
            'size' => (int)@filesize($abs),
            'width' => is_array($wh) ? (int)$wh[0] : 0,
            'height' => is_array($wh) ? (int)$wh[1] : 0,
            'kind' => 'img', 'user_id' => $uid,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) { /* 唯一键冲突：已存在，正常 */ }

    echo json_encode([
        'ok' => 1, 'url' => $url,
        'w' => is_array($wh) ? (int)$wh[0] : 0,
        'h' => is_array($wh) ? (int)$wh[1] : 0,
        'size' => (int)@filesize($abs),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * ★ yf-upload-20261020：回收孤儿附件（正文里已经没人引用的）
 *   只统计 status='normal' 的帖子/回帖；软删的内容仍算「有引用」（可恢复）。
 *   @return array{deleted:int,kept:int}
 */
function yf_att_gc(int $olderThanDays = 7, int $limit = 300): array
{
    $A = Db::t('forum_att');
    $T = Db::t('forum_threads');
    $R = Db::t('forum_replies');
    $rows = Db::rows(
        "SELECT id,path FROM `{$A}` WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY id ASC LIMIT " . (int)$limit,
        [max(0, $olderThanDays)]
    );
    $del = 0; $kept = 0;
    foreach ($rows as $r) {
        $p = (string)$r['path'];
        if ($p === '') { continue; }
        $n1 = (int)Db::one("SELECT COUNT(*) FROM `{$T}` WHERE content LIKE ? AND status='normal'", ['%' . $p . '%']);
        $n2 = (int)Db::one("SELECT COUNT(*) FROM `{$R}` WHERE content LIKE ? AND status='normal'", ['%' . $p . '%']);
        if ($n1 + $n2 > 0) { $kept++; continue; }
        $abs = dirname(__DIR__, 2) . $p;
        if (is_file($abs)) { @unlink($abs); }
        Db::run("DELETE FROM `{$A}` WHERE id=?", [(int)$r['id']]);
        $del++;
    }
    return ['deleted' => $del, 'kept' => $kept];
}

function yf_mirror_images(string $content, int $max = 10): string
{
    if ($content === '') { return $content; }
    $urls = [];
    /* Markdown 图片 */
    if (preg_match_all('/!\[[^\]]*\]\(\s*(https?:\/\/[^\s)<>"]+)\s*\)/i', $content, $m)) {
        foreach ($m[1] as $u) { $urls[$u] = 1; }
    }
    /* HTML img */
    if (preg_match_all('/<img[^>]+src=["\'](https?:\/\/[^"\'>\s]+)["\']/i', $content, $m2)) {
        foreach ($m2[1] as $u) { $urls[$u] = 1; }
    }
    if (!$urls) { return $content; }

    $n = 0;
    foreach (array_keys($urls) as $u) {
        if ($n >= $max) { break; }
        /* 已经是本站的就跳过 */
        if (preg_match('#^https?://(www\.)?yecha\.org/#i', $u)) { continue; }
        $local = yf_fetch_image($u);
        if ($local === '') { continue; }
        $content = str_replace($u, $local, $content);
        $n++;
    }
    if ($n > 0) { yf_side_flush(); }
    return $content;
}

function yf_toolbar(): string
{
    $b = [
        ['bold',   'B',  '加粗',       'font-weight:700'],
        ['italic', 'I',  '斜体',       'font-style:italic'],
        ['h2',     'H',  '小标题',     ''],
        ['link',   '🔗', '插入链接',   ''],
        ['img',    '🖼', '插入图片',   ''],
        ['code',   '</>', '行内代码',  'font-family:ui-monospace,monospace;font-size:11px'],
        ['quote',  '❝',  '引用',       ''],
        ['ul',     '≡',  '列表',       ''],
    ];
    $h = '<div class="yf-tb">';
    foreach ($b as $x) {
        $h .= '<button type="button" class="yf-tb-b" data-tb="' . $x[0] . '" title="' . e($x[2]) . '"'
            . ($x[3] !== '' ? ' style="' . $x[3] . '"' : '') . '>' . e($x[1]) . '</button>';
    }
    $h .= '<span class="yf-tb-tip">' . e(yf_t('支持 Markdown · 图片可点上传 / 粘贴 / 拖入，统一存为 WebP', 'Markdown · upload, paste or drop images (saved as WebP)')) . '</span></div>';
    return $h;
}

function yf_new_form(string $err = '', array $old = [], string $preview = ''): string
{
    if (!\Auth::check()) { \Uc::needLogin(yf_url(['mode' => 'new'])); }
    $draft = yf_draft_get('thread', 0);
    $restore = (int)($_GET['restore'] ?? 0) === 1;
    if ($draft !== null && $restore) {
        if (trim((string)($old['title'] ?? '')) === '') { $old['title'] = (string)$draft['title']; }
        if (trim((string)($old['content'] ?? '')) === '') { $old['content'] = (string)$draft['content']; }
        if ((int)($old['board_id'] ?? 0) === 0) { $old['board_id'] = (int)$draft['board_id']; }
    }
    $boards = yf_boards_all();
    $sel = (int)($old['board_id'] ?? 0);
    if ($sel === 0) { $sel = (int)($_GET['b'] ?? 0); }
    $opts = '';
    foreach ($boards as $b) {
        $opts .= '<option value="' . (int)$b['id'] . '"' . ($sel === (int)$b['id'] ? ' selected' : '') . '>' . e(yf_bname($b)) . '</option>';
    }
    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    $draftBar = ($draft !== null && !$restore) ? yf_draft_bar($draft, yf_url(['mode' => 'new', 'restore' => 1]), yf_url(['mode' => 'new'])) : '';
    $preHtml = $preview !== '' ? '<div class="yf-pre"><div class="cap">' . e(yf_t('预览', 'Preview')) . '</div>' . $preview . '</div>' : '';
    return '<div class="yf-wrap">'
        . '<div class="yf-crumbs"><a href="' . e(yf_url()) . '">' . e(yf_t('社区', 'Community')) . '</a> › <span>' . e(yf_t('发新帖', 'New Topic')) . '</span></div>'
        . $errHtml
        . '<div class="yf-form"><h2>' . e(yf_t('发起新帖', 'Start a New Topic')) . '</h2>' . $draftBar
        . '<form method="post" action="' . e(yf_url(['mode' => 'new'])) . '" data-yf-draft="thread" data-thread="0">' . \Csrf::field()
        . '<input type="hidden" name="website2" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">'
        . '<label>' . e(yf_t('选择版块 *', 'Board *')) . '</label><select name="board_id" required>' . $opts . '</select>'
        . '<label>' . e(yf_t('标题 *', 'Title *')) . '</label><input type="text" name="title" required maxlength="200" value="' . e((string)($old['title'] ?? '')) . '" placeholder="' . e(yf_t('一句话说清你的问题或话题', 'One line that states your question or topic')) . '">'
        . '<label>' . e(yf_t('内容 *（支持 Markdown）', 'Content * (Markdown supported)')) . '</label>' . yf_toolbar()
        . '<textarea name="content" required minlength="2" maxlength="60000" placeholder="' . e(yf_t('把背景、想讨论的点写清楚。', 'Give enough context so others can help.')) . '">' . e((string)($old['content'] ?? '')) . '</textarea>'
        . $preHtml
        . '<div class="row"><button class="yf-btn" type="submit" name="action" value="new">' . e(yf_t('发布帖子', 'Post Topic')) . '</button>'
        . '<button class="yf-btn2" type="submit" name="action" value="preview">' . e(yf_t('预览', 'Preview')) . '</button>'
        . '<a class="yf-btn2 sm" href="' . e(yf_url()) . '">' . e(yf_t('取消', 'Cancel')) . '</a>'
        . '<span class="yf-draftstate" id="yf-draft-state"></span></div>'
        . '<div class="hint">' . e(yf_t('请友善发言；草稿会自动保存（本地 + 服务端）。', 'Be kind; drafts are auto-saved (local + server).')) . '</div>'
        . '</form></div></div>';
}

function yf_edit_thread_form(int $id, string $err = '', array $old = [], string $preview = ''): string
{
    if (!\Auth::check()) { \Uc::needLogin(); }
    $th = Db::row('SELECT * FROM ' . Db::t('forum_threads') . " WHERE id=? AND status='normal' LIMIT 1", [$id]);
    if ($th === null) { return yf_notfound_html(); }
    if (!yf_can_edit((int)$th['user_id'])) { return '<div class="yf-wrap"><div class="yf-empty">' . e(yf_t('无权编辑该帖。', 'You cannot edit this topic.')) . '</div></div>'; }
    $title = $old['title'] ?? (string)$th['title'];
    $content = $old['content'] ?? (string)$th['content'];
    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    $preHtml = $preview !== '' ? '<div class="yf-pre"><div class="cap">' . e(yf_t('预览', 'Preview')) . '</div>' . $preview . '</div>' : '';
    return '<div class="yf-wrap"><div class="yf-crumbs"><a href="' . e(yf_url()) . '">' . e(yf_t('社区', 'Community')) . '</a> › <a href="' . e(yf_url(['t' => $id])) . '">' . e(mb_substr((string)$th['title'], 0, 24)) . '</a> › <span>' . e(yf_t('编辑', 'Edit')) . '</span></div>'
        . $errHtml . '<div class="yf-form"><h2>' . e(yf_t('编辑帖子', 'Edit Topic')) . '</h2>'
        . '<form method="post" action="' . e(yf_url(['mode' => 'edit', 't' => $id])) . '">' . \Csrf::field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<label>' . e(yf_t('标题 *', 'Title *')) . '</label><input type="text" name="title" required maxlength="200" value="' . e((string)$title) . '">'
        . '<label>' . e(yf_t('内容 *（支持 Markdown）', 'Content * (Markdown supported)')) . '</label>' . yf_toolbar()
        . '<textarea name="content" required minlength="2" maxlength="60000">' . e((string)$content) . '</textarea>'
        . $preHtml
        . '<div class="row"><button class="yf-btn" type="submit" name="action" value="edit_thread">' . e(yf_t('保存修改', 'Save')) . '</button>'
        . '<button class="yf-btn2" type="submit" name="action" value="preview">' . e(yf_t('预览', 'Preview')) . '</button>'
        . '<a class="yf-btn2 sm" href="' . e(yf_url(['t' => $id])) . '">' . e(yf_t('取消', 'Cancel')) . '</a></div></form></div></div>';
}

function yf_edit_reply_form(int $id, string $err = '', array $old = []): string
{
    if (!\Auth::check()) { \Uc::needLogin(); }
    $r = Db::row('SELECT * FROM ' . Db::t('forum_replies') . " WHERE id=? AND status='normal' LIMIT 1", [$id]);
    if ($r === null) { return yf_notfound_html(); }
    if (!yf_can_edit((int)$r['user_id'])) { return '<div class="yf-wrap"><div class="yf-empty">' . e(yf_t('无权编辑该回帖。', 'You cannot edit this reply.')) . '</div></div>'; }
    $content = $old['content'] ?? (string)$r['content'];
    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    return '<div class="yf-wrap"><div class="yf-crumbs"><a href="' . e(yf_url()) . '">' . e(yf_t('社区', 'Community')) . '</a> › <a href="' . e(yf_url(['t' => (int)$r['thread_id']])) . '">#' . (int)$r['id'] . '</a> › <span>' . e(yf_t('编辑回帖', 'Edit Reply')) . '</span></div>'
        . $errHtml . '<div class="yf-form"><h2>' . e(yf_t('编辑回帖', 'Edit Reply')) . '</h2>'
        . '<form method="post" action="' . e(yf_url(['mode' => 'edit', 'rid' => $id])) . '">' . \Csrf::field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<label>' . e(yf_t('内容 *（支持 Markdown）', 'Content * (Markdown supported)')) . '</label>' . yf_toolbar()
        . '<textarea name="content" required minlength="2" maxlength="60000">' . e((string)$content) . '</textarea>'
        . '<div class="row"><button class="yf-btn" type="submit" name="action" value="edit_reply">' . e(yf_t('保存修改', 'Save')) . '</button>'
        . '<a class="yf-btn2 sm" href="' . e(yf_url(['t' => (int)$r['thread_id']])) . '">' . e(yf_t('取消', 'Cancel')) . '</a></div></form></div></div>';
}

/* ================= 我的草稿 ================= */
function yf_drafts_view(): string
{
    if (!\Auth::check()) { \Uc::needLogin(yf_url(['mode' => 'drafts'])); }
    $rows = Db::rows('SELECT d.*, t.title ttitle FROM ' . Db::t('forum_drafts') . ' d LEFT JOIN ' . Db::t('forum_threads')
        . ' t ON t.id=d.thread_id WHERE d.user_id=? ORDER BY d.updated_at DESC LIMIT 100', [(int)\Auth::id()]);
    $li = '';
    foreach ($rows as $d) {
        $kind = (string)$d['kind'] === 'reply' ? 'reply' : 'thread';
        $resume = $kind === 'reply'
            ? yf_url(['t' => (int)$d['thread_id'], 'restore' => 1]) . '#yf-reply'
            : yf_url(['mode' => 'new', 'restore' => 1]);
        $title = $kind === 'reply'
            ? yf_t('回复：', 'Reply: ') . (string)($d['ttitle'] ?? ('#' . (int)$d['thread_id']))
            : ((string)$d['title'] !== '' ? (string)$d['title'] : yf_t('（无标题）', '(untitled)'));
        $del = '<form method="post" action="' . e(yf_url(['mode' => 'drafts'])) . '" style="display:inline;margin:0" onsubmit="return confirm(\'丢弃这份草稿？\')">' . \Csrf::field()
            . '<input type="hidden" name="action" value="draft_del"><input type="hidden" name="kind" value="' . e($kind) . '">'
            . '<input type="hidden" name="thread_id" value="' . (int)$d['thread_id'] . '"><button class="yf-btn2 sm" type="submit">' . e(yf_t('丢弃', 'Discard')) . '</button></form>';
        $li .= '<li><div class="dt"><b>' . e($title) . '</b><span class="yf-tag">' . e($kind === 'reply' ? yf_t('回帖', 'reply') : yf_t('主题', 'topic')) . '</span></div>'
            . '<div class="dm">' . e(yf_excerpt((string)$d['content'], 140)) . '</div>'
            . '<div class="dm">' . e(yf_t('保存于', 'saved')) . ' ' . e(yf_dt((string)$d['updated_at'])) . '</div>'
            . '<div class="dx"><a class="yf-btn sm" href="' . e($resume) . '">' . e(yf_t('继续编辑', 'Continue')) . '</a>' . $del . '</div></li>';
    }
    if ($li === '') { $li = '<div class="yf-empty">' . e(yf_t('还没有草稿。发帖/回帖时打一半离开，会自动存到这里。', 'No drafts yet. Drafts are saved automatically while you type.')) . '</div>'; }
    return '<div class="yf-wrap"><div class="yf-crumbs"><a href="' . e(yf_url()) . '">' . e(yf_t('社区', 'Community')) . '</a> › <span>' . e(yf_t('我的草稿', 'My Drafts')) . '</span></div>'
        . '<div class="yf-head"><h1>' . e(yf_t('我的草稿', 'My Drafts')) . '</h1><p class="yf-sub">' . e(yf_t('草稿只有你自己可见，不会出现在公开列表。发表后自动删除。', 'Drafts are private and never show in public lists. They disappear after posting.')) . '</p></div>'
        . '<ul class="yf-dlist">' . $li . '</ul>'
        . '<div class="yf-bar"><a class="yf-btn2 sm" href="' . e(yf_url()) . '">← ' . e(yf_t('返回社区', 'Back to community')) . '</a>'
        . '<a class="yf-btn" style="margin-left:auto" href="' . e(yf_url(['mode' => 'new'])) . '">+ ' . e(yf_t('发新帖', 'New Topic')) . '</a></div></div>';
}

function yf_notfound_html(): string
{
    return '<div class="yf-wrap"><div class="yf-empty">' . e(yf_t('内容不存在或已被删除。', 'Not found or removed.')) . '<br><br><a class="yf-btn2" href="' . e(yf_url()) . '">← ' . e(yf_t('返回社区', 'Back to Forum')) . '</a></div></div>';
}
