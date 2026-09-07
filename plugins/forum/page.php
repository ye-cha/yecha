<?php
declare(strict_types=1);
// 论坛插件 · 前台（/forum）v3：左右结构（正文 + 右侧栏）；内容页作者信息置顶
require_once __DIR__ . '/lib.php';

function yc_forum_page(string $slug): array
{
    $GLOBALS['YC_NO_SIDE'] = true;
    $GLOBALS['YC_SB_TYPE'] = 'page';
    $err = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $err = yf_handle_post();
    }
    $curB = '';
    $mode = (string)($_GET['mode'] ?? '');
    if ($mode === 'new') {
        $curB = (string)($_GET['b'] ?? '');
        return ['html' => yf_frame(yf_css() . yf_new_form($err), $curB), 'title' => (yf_en() ? 'Start a New Topic' : '发起新帖') . ' · ' . site_label()];
    }
    $t = (int)($_GET['t'] ?? 0);
    if ($t > 0) {
        $th = Db::row('SELECT board_id FROM ' . Db::t('forum_threads') . ' WHERE id=? AND status=\'normal\' LIMIT 1', [$t]);
        $curB = $th ? (string)$th['board_id'] : '';
        return ['html' => yf_frame(yf_css() . yf_thread_view($t, $err), $curB), 'title' => yf_thread_title($t)];
    }
    $b = (string)($_GET['b'] ?? '');
    if ($b !== '') {
        return ['html' => yf_frame(yf_css() . yf_board_view($b, $err), $b), 'title' => yf_board_title($b)];
    }
    return ['html' => yf_frame(yf_css() . yf_index_view($err), ''), 'title' => (yf_en() ? 'Forum' : '论坛') . ' · ' . site_label()];
}

/* ---------- 左右结构外壳 ---------- */
function yf_frame(string $inner, string $curB = ''): string
{
    return '<div class="yf-layout"><div class="yf-main">' . $inner . '</div>' . yf_side($curB) . '</div>';
}

function yf_side(string $curB = ''): string
{
    $T = Db::t('forum_threads');
    $R = Db::t('forum_replies');
    $boards = yf_boards_all();
    $li = '';
    foreach ($boards as $b) {
        $n = (int)Db::one('SELECT COUNT(*) FROM ' . $T . " WHERE board_id=? AND status='normal'", [(int)$b['id']]);
        $li .= '<li><a' . ($curB !== '' && (string)$b['id'] === $curB ? ' class="on"' : '') . ' href="' . e(yf_url(['b' => (int)$b['id']])) . '">'
            . '<span>' . e(yf_bname($b)) . '</span><span class="n">' . $n . '</span></a></li>';
    }
    $tt = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE status='normal'");
    $rt = (int)Db::one("SELECT COUNT(*) FROM {$R} WHERE status='normal'");
    $nt = count($boards);
    $stats = '<div class="yf-stats"><span><b>' . $tt . '</b>' . e(yf_t('主题', 'topics')) . '</span>'
        . '<span><b>' . $rt . '</b>' . e(yf_t('回复', 'replies')) . '</span>'
        . '<span><b>' . $nt . '</b>' . e(yf_t('版块', 'boards')) . '</span></div>';
    $auth = '';
    if (\Auth::check()) {
        $u = \Auth::user();
        $auth = '<div class="yf-sidebox"><p>' . e(yf_t('已登录：', 'Signed in as: ')) . ' <b>' . e((string)($u['display_name'] ?? $u['username'] ?? '')) . '</b></p>'
            . '<a class="yf-btn" style="width:100%;justify-content:center" href="' . e(yf_url(['mode' => 'new'])) . '">+ ' . e(yf_t('发新帖', 'New Topic')) . '</a></div>';
    } else {
        $auth = '<div class="yf-sidebox"><p>' . e(yf_t('登录后即可发帖与回帖。', 'Log in to post and reply.')) . '</p>'
            . '<a class="yf-btn2" style="width:100%;justify-content:center;box-sizing:border-box" href="' . e(\ui_login(yf_url())) . '">' . e(yf_t('去登录 →', 'Log in →')) . '</a></div>';
    }
    return '<aside class="yf-side">'
        . '<div class="yf-w"><h4>' . e(yf_t('版块', 'Boards')) . '</h4><ul>' . $li . '</ul></div>'
        . '<div class="yf-w"><h4>' . e(yf_t('统计', 'Stats')) . '</h4>' . $stats . '</div>'
        . $auth
        . '<div class="yf-w"><h4>' . e(yf_t('贴士', 'Tips')) . '</h4><div class="yf-sidebox"><p>' . e(yf_t('发言前先搜官方帮助帖；提问把场景写清楚，回复会更快。', 'Search the official help posts first; describe your scenario clearly for faster answers.')) . '</p></div></div>'
        . '</aside>';
}

/* ================= POST ================= */
function yf_handle_post(): string
{
    $action = (string)($_POST['action'] ?? '');
    try { \Csrf::check(); } catch (Throwable) { return yf_t('校验失败，请刷新页面后重试', 'Verification failed — please refresh and retry.'); }
    if (trim((string)($_POST['website2'] ?? '')) !== '') { return yf_t('操作过于频繁，请稍后再试', 'Too fast — slow down a little.'); }

    if ($action === 'new') {
        if (!\Auth::check()) { \Uc::needLogin(); }
        $boardId = (int)($_POST['board_id'] ?? 0);
        $board = $boardId > 0 ? yf_board($boardId) : null;
        if ($board === null) { return yf_t('请选择版块', 'Please choose a board.'); }
        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) { return yf_t('标题不能为空且不超过 200 字', 'Title is required and must be under 200 characters.'); }
        if (mb_strlen($content) < 2 || mb_strlen($content) > 60000) { return yf_t('内容至少 2 字，最多 60000 字', 'Content must be 2–60000 characters.'); }
        if (($r = yf_rate_check()) !== '') { return $r; }
        $uid = (int)\Auth::id();
        $now = date('Y-m-d H:i:s');
        $tid = Db::insert('forum_threads', [
            'board_id' => (int)$board['id'], 'user_id' => $uid, 'title' => $title, 'content' => $content,
            'views' => 0, 'replies' => 0, 'status' => 'normal', 'created_at' => $now, 'updated_at' => $now, 'last_at' => $now, 'last_user_id' => $uid,
        ]);
        header('Location: ' . yf_url(['t' => $tid])); exit;
    }

    if ($action === 'reply') {
        if (!\Auth::check()) { \Uc::needLogin(); }
        $tid = (int)($_POST['thread_id'] ?? 0);
        $content = trim((string)($_POST['content'] ?? ''));
        $T = Db::t('forum_threads');
        $thread = $tid > 0 ? Db::row("SELECT id FROM {$T} WHERE id=? AND status='normal' LIMIT 1", [$tid]) : null;
        if ($thread === null) { return yf_t('帖子不存在或已删除', 'Topic not found or removed.'); }
        if (mb_strlen($content) < 2 || mb_strlen($content) > 60000) { return yf_t('回帖至少 2 字，最多 60000 字', 'Reply must be 2–60000 characters.'); }
        if (($r = yf_rate_check()) !== '') { return $r; }
        $uid = (int)\Auth::id();
        Db::insert('forum_replies', ['thread_id' => $tid, 'user_id' => $uid, 'content' => $content, 'status' => 'normal']);
        Db::run("UPDATE {$T} SET replies=replies+1, last_at=NOW(), last_user_id=?, updated_at=NOW() WHERE id=?", [$uid, $tid]);
        header('Location: ' . yf_url(['t' => $tid]) . '#yf-replies'); exit;
    }
    return '';
}

function yf_rate_check(): string
{
    if (!\Auth::check()) { return yf_t('请先登录', 'Please log in first.'); }
    $n = (int)\Cache::incr('forum_rate', 'u' . (int)\Auth::id(), 300);
    if ($n > 4) { return yf_t('操作太快了，休息几分钟再继续', 'Posting too fast — take a short break.'); }
    return '';
}

/* ================= 小工具 ================= */
function yf_filter(): string
{
    $f = (string)($_GET['f'] ?? '');
    return in_array($f, ['hot', 'good'], true) ? $f : '';
}

function yf_boards_all(): array
{
    return Db::rows('SELECT * FROM ' . Db::t('forum_boards') . " WHERE status='show' ORDER BY sort ASC, id ASC");
}

function yf_bname(array $b): string
{
    return yf_en() && trim((string)$b['name_en']) !== '' ? (string)$b['name_en'] : (string)$b['name'];
}

function yf_ava(array $u): string
{
    return '<span class="ava">' . e(mb_substr(yf_uname($u), 0, 1)) . '</span>';
}

function yf_row(array $th, bool $withBoard): string
{
    $u = yf_user((int)$th['user_id']);
    $board = null;
    if ($withBoard && !empty($th['board_id'])) {
        $b = yf_board((int)$th['board_id']);
        if ($b) { $board = $b; }
    }
    $pin = (int)($th['pinned'] ?? 0) === 1 ? '<span class="yf-pin">' . e(yf_t('顶', 'TOP')) . '</span> ' : '';
    $meta = '<span>' . e(yf_uname($u)) . '</span>';
    if ($board) { $meta = '<span class="bchip">' . e(yf_bname($board)) . '</span>' . $meta; }
    $meta .= '<span>·</span><time>' . e(yf_dt((string)$th['created_at'])) . '</time>';
    $last = '';
    if ((int)$th['replies'] > 0 && !empty($th['last_user_id']) && (string)$th['last_user_id'] !== (string)$th['user_id']) {
        $lu = yf_user((int)$th['last_user_id']);
        $last = '<span class="yf-last">· ' . e(yf_t('最后回复', 'last')) . ' ' . e(yf_uname($lu)) . ' · <time>' . e(yf_dt((string)$th['last_at'])) . '</time></span>';
    }
    return '<li class="yf-thread">' . yf_ava($u)
        . '<div class="tc"><div class="tt">' . $pin . '<a href="' . e(yf_url(['t' => (int)$th['id']])) . '">' . e((string)$th['title']) . '</a></div>'
        . '<div class="tm">' . $meta . $last . '</div></div>'
        . '<div class="cnt"><span><b>' . (int)$th['replies'] . '</b>' . e(yf_t('回复', 'replies')) . '</span>'
        . '<span><b>' . (int)$th['views'] . '</b>' . e(yf_t('查看', 'views')) . '</span></div></li>';
}

function yf_tab(string $val, string $zh, string $en): string
{
    $f = yf_filter();
    $q = yf_url(array_filter(['f' => $val !== '' ? $val : null, 'b' => $_GET['b'] ?? null], fn($v) => $v !== null && $v !== ''));
    return '<a href="' . e($q) . '"' . ($f === $val ? ' class="on"' : '') . '>' . e(yf_en() ? $en : $zh) . '</a>';
}

function yf_chips(string $curB = ''): string
{
    $h = '<a class="yf-chip' . ($curB === '' ? ' on' : '') . '" href="/forum">' . e(yf_t('全部', 'All')) . '</a>';
    foreach (yf_boards_all() as $b) {
        $h .= '<a class="yf-chip' . ($curB === (string)$b['id'] ? ' on' : '') . '" href="' . e(yf_url(['b' => (int)$b['id']])) . '">' . e(yf_bname($b)) . '</a>';
    }
    return '<div class="yf-filters">' . $h . '</div>';
}

/* ================= 首页（版块主导） ================= */
function yf_board_row(array $b): string
{
    $T = Db::t('forum_threads');
    $bid = (int)$b['id'];
    $tc = (int)Db::one('SELECT COUNT(*) FROM ' . $T . " WHERE board_id=? AND status='normal'", [$bid]);
    $rc = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('forum_replies') . ' r JOIN ' . $T . ' t ON t.id=r.thread_id AND r.status=\'normal\' WHERE t.board_id=? AND t.status=\'normal\'', [$bid]);
    $last = '';
    $lt = Db::row("SELECT * FROM {$T} WHERE board_id=? AND status='normal' ORDER BY last_at DESC, id DESC LIMIT 1", [$bid]);
    if ($lt) {
        $lu = yf_user((int)$lt['user_id']);
        $last = '<div class="bl"><b>' . e(yf_t('最后发表', 'Last post')) . '</b><br>'
            . '<a href="' . e(yf_url(['t' => (int)$lt['id']])) . '" style="color:#6f6a5e">' . e(mb_substr((string)$lt['title'], 0, 22)) . '</a><br>'
            . e(yf_uname($lu)) . ' · ' . e(yf_dt((string)$lt['last_at'])) . '</div>';
    }
    return '<a class="yf-brow" href="' . e(yf_url(['b' => $bid])) . '">'
        . '<div class="bb"><b>' . e(yf_bname($b)) . '</b><p>' . e((string)$b['intro']) . '</p></div>'
        . '<div class="bc"><b>' . $tc . '</b>' . e(yf_t('主题', 'topics')) . '</div>'
        . '<div class="bc"><b>' . $rc . '</b>' . e(yf_t('回复', 'replies')) . '</div>'
        . $last . '</a>';
}

function yf_home_view(string $err = ''): string
{
    $boards = yf_boards_all();
    $bh = '';
    foreach ($boards as $b) { $bh .= yf_board_row($b); }
    if ($bh === '') { $bh = '<div class="yf-empty">' . e(yf_t('还没有版块。', 'No boards yet.')) . '</div>'; }
    // 最新主题（跨版块，最近 12 条）
    $T = Db::t('forum_threads');
    $latest = Db::rows("SELECT * FROM {$T} WHERE status='normal' ORDER BY pinned DESC, last_at DESC, id DESC LIMIT 12");
    $rows = '';
    foreach ($latest as $th) { $rows .= yf_row($th, true); }
    if ($rows === '') { $rows = '<div class="yf-empty">' . e(yf_t('还没有帖子，来发第一帖吧。', 'No topics yet — start the first one.')) . '</div>'; }

    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    $head = '<div class="yf-head"><h1>' . e(yf_t('论坛', 'Forum')) . '</h1>'
        . '<p class="yf-sub">' . e(yf_t('问问题、聊使用、晒作品——干净又轻快的讨论区。', 'Ask, discuss, show off your builds — a clean, light community.')) . '</p></div>';
    $hint = !\Auth::check() ? '<p class="yf-sub" style="color:#9a9a90">' . e(yf_t('游客可浏览；登录后可发帖与回帖。', 'Guests can browse; log in to post and reply.')) . '</p>' : '';
    $bar = '<div class="yf-bar"><span style="color:#9a9a90;font-size:13px">' . count($boards) . ' ' . e(yf_t('个版块', 'boards')) . '</span>'
        . '<a class="yf-btn" style="margin-left:auto" href="' . e(yf_url(['mode' => 'new'])) . '">+ ' . e(yf_t('发新帖', 'New Topic')) . '</a></div>';
    $secBoard = '<div class="yf-sec"><span class="en2">Boards</span><h2>' . e(yf_t('论坛版块', 'Boards')) . '</h2></div>';
    $secLatest = '<div class="yf-sec"><span class="en2">Latest</span><h2>' . e(yf_t('最新主题', 'Latest Topics')) . '</h2>'
        . '<a class="more" style="font-size:12.5px;color:var(--accent,#B3541E);text-decoration:none;margin-left:auto" href="' . e(yf_url(['view' => 'all'])) . '">' . e(yf_t('全部主题 →', 'All topics →')) . '</a></div>';
    return '<div class="yf-wrap">' . $head . $errHtml . $hint . $bar
        . $secBoard . '<div class="yf-blist">' . $bh . '</div>'
        . $secLatest . '<ul class="yf-tlist">' . $rows . '</ul>'
        . '<p style="color:#9a9a90;font-size:12.5px;margin-top:18px;">Powered by YeCha Forum</p></div>';
}

function yf_index_view(string $err = ''): string
{
    return yf_list_view($err);
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
    if ($f === 'hot') { $order = 'views DESC, id DESC'; }
    if ($f === 'good') { $where .= " AND pinned=1"; }
    $total = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE {$where}");
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    $threads = Db::rows("SELECT * FROM {$T} WHERE {$where} ORDER BY {$order} LIMIT " . (int)$per . " OFFSET " . (int)(($page - 1) * $per));
    $rows = '';
    foreach ($threads as $th) { $rows .= yf_row($th, true); }
    if ($rows === '') { $rows = '<div class="yf-empty">' . e(yf_t('还没有帖子，来发第一帖吧。', 'No topics yet — start the first one.')) . '</div>'; }

    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    $head = '<div class="yf-head"><h1>' . e(yf_t('论坛', 'Forum')) . '</h1>'
        . '<p class="yf-sub">' . e(yf_t('问问题、聊使用、晒作品——干净又轻快的讨论区。右侧选择版块，或直接逛最新主题。', 'Ask, discuss, show off your builds — a clean, light community. Pick a board on the right, or browse the latest topics.')) . '</p></div>';
    $hint = !\Auth::check() ? '<p class="yf-sub" style="color:#9a9a90">' . e(yf_t('游客可浏览；登录后可发帖与回帖。', 'Guests can browse; log in to post and reply.')) . '</p>' : '';
    $bar = '<div class="yf-bar"><span style="color:#9a9a90;font-size:13px">' . $total . ' ' . e(yf_t('个主题', 'topics')) . '</span>'
        . '<a class="yf-btn" style="margin-left:auto" href="' . e(yf_url(['mode' => 'new'])) . '">+ ' . e(yf_t('发新帖', 'New Topic')) . '</a></div>';
    $tabs = '<div class="yf-tabs">' . yf_tab('', '最新', 'Latest') . yf_tab('hot', '热帖', 'Hot') . yf_tab('good', '精华', 'Pinned') . '</div>';
    return '<div class="yf-wrap">' . $head . $errHtml . $hint . $bar . $tabs
        . '<ul class="yf-tlist">' . $rows . '</ul>' . yf_pager($page, $pages, [])
        . '<p style="color:#9a9a90;font-size:12.5px;margin-top:18px;">Powered by YeCha Forum</p></div>';
}

/* ================= 板块页 ================= */
function yf_board_title(string $b): string
{
    $row = yf_board($b);
    $name = $row ? yf_bname($row) : yf_t('版块', 'Board');
    return $name . ' · ' . (yf_en() ? 'Forum' : '论坛') . ' · ' . site_label();
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
    if ($f === 'hot') { $order = 'views DESC, id DESC'; }
    if ($f === 'good') { $where .= " AND pinned=1"; }
    $total = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE {$where}", $args);
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    $threads = Db::rows("SELECT * FROM {$T} WHERE {$where} ORDER BY {$order} LIMIT " . (int)$per . " OFFSET " . (int)(($page - 1) * $per), $args);
    $rows = '';
    foreach ($threads as $th) { $rows .= yf_row($th, false); }
    if ($rows === '') { $rows = '<div class="yf-empty">' . e(yf_t('这里还没有帖子，来发第一帖吧。', 'No topics yet — start the first one.')) . '</div>'; }
    $crumbs = '<div class="yf-crumbs"><a href="/forum">' . e(yf_t('论坛', 'Forum')) . '</a> › <span>' . e(yf_bname($board)) . '</span></div>';
    $head = '<div class="yf-head"><h1>' . e(yf_bname($board)) . '</h1><p class="yf-sub">' . e((string)$board['intro']) . '</p></div>';
    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    $bar = '<div class="yf-bar"><a class="yf-btn2" href="/forum">← ' . e(yf_t('返回', 'Back')) . '</a>'
        . '<span style="color:#9a9a90;font-size:13px">' . $total . ' ' . e(yf_t('主题', 'topics')) . '</span>'
        . '<a class="yf-btn" style="margin-left:auto" href="' . e(yf_url(['mode' => 'new', 'b' => (int)$board['id']])) . '">+ ' . e(yf_t('发新帖', 'New Topic')) . '</a></div>';
    $tabs = '<div class="yf-tabs">' . yf_tab('', '最新', 'Latest') . yf_tab('hot', '热帖', 'Hot') . yf_tab('good', '精华', 'Pinned') . '</div>';
    return '<div class="yf-wrap">' . $crumbs . $errHtml . $head . $bar . $tabs
        . '<ul class="yf-tlist">' . $rows . '</ul>' . yf_pager($page, $pages, ['b' => (int)$board['id']]) . '</div>';
}

/* ================= 帖子详情 ================= */
function yf_thread_title(int $t): string
{
    $row = Db::row('SELECT title FROM ' . Db::t('forum_threads') . ' WHERE id=? AND status=\'normal\' LIMIT 1', [$t]);
    return ($row ? (string)$row['title'] : yf_t('帖子', 'Topic')) . ' · ' . site_label();
}

function yf_thread_view(int $tid, string $err = ''): string
{
    $T = Db::t('forum_threads');
    $th = Db::row("SELECT * FROM {$T} WHERE id=? AND status='normal' LIMIT 1", [$tid]);
    if ($th === null) { return yf_notfound_html(); }
    $hasSess = session_status() === PHP_SESSION_ACTIVE;
    if (!($hasSess && !empty($_SESSION['yfv'][$tid]))) {
        Db::run("UPDATE {$T} SET views=views+1 WHERE id=?", [$tid]);
        if ($hasSess) { $_SESSION['yfv'][$tid] = 1; }
    }
    $board = yf_board((int)$th['board_id']);
    $au = yf_user((int)$th['user_id']);
    $crumbs = '<div class="yf-crumbs"><a href="/forum">' . e(yf_t('论坛', 'Forum')) . '</a> › '
        . ($board ? '<a href="' . e(yf_url(['b' => (int)$board['id']])) . '">' . e(yf_bname($board)) . '</a> › ' : '')
        . '<span>' . e(mb_substr((string)$th['title'], 0, 30)) . '</span></div>';
    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    // 主题标题 + 楼主楼层卡（作者信息在卡头，正文在下方）
    $op = '<h1 class="yf-ttl">' . ((int)$th['pinned'] === 1 ? '<span class="yf-pin">' . e(yf_t('顶', 'TOP')) . '</span> ' : '') . e((string)$th['title']) . '</h1>'
        . '<div class="yf-floor-card op"><div class="yf-fhead"><span class="fn">#1</span><span class="yf-badge">' . e(yf_t('楼主', 'OP')) . '</span>'
        . '<span class="fx"><b>' . e(yf_uname($au)) . '</b><time>' . e(yf_dt((string)$th['created_at'])) . '</time>'
        . '<span>· ' . (int)$th['views'] . ' ' . e(yf_t('查看', 'views')) . '</span></span></div>'
        . '<div class="yf-body">' . yf_md((string)$th['content']) . '</div></div>';

    $per = 20;
    $R = Db::t('forum_replies');
    $rp = max(1, (int)($_GET['rp'] ?? 1));
    $rtotal = (int)Db::one("SELECT COUNT(*) FROM {$R} WHERE thread_id=? AND status='normal'", [$tid]);
    $rpages = max(1, (int)ceil($rtotal / $per));
    $rp = min($rp, $rpages);
    $replies = Db::rows("SELECT * FROM {$R} WHERE thread_id=? AND status='normal' ORDER BY id ASC LIMIT " . (int)$per . " OFFSET " . (int)(($rp - 1) * $per), [$tid]);
    $repHtml = '';
    $idx = ($rp - 1) * $per + 1;
    foreach ($replies as $r) {
        $ru = yf_user((int)$r['user_id']);
        $repHtml .= '<div class="yf-floor-card" id="yf-r' . (int)$r['id'] . '"><div class="yf-fhead"><span class="fn">#' . $idx . '</span>'
            . '<span class="fx"><b>' . e(yf_uname($ru)) . '</b><time>' . e(yf_dt((string)$r['created_at'])) . '</time></span></div>'
            . '<div class="yf-body">' . yf_md((string)$r['content']) . '</div></div>';
        $idx++;
    }
    if ($repHtml === '') { $repHtml = '<div class="yf-empty">' . e(yf_t('还没有回帖，坐个沙发吧。', 'No replies yet — be the first.')) . '</div>'; }

    $replyBox = '';
    if (\Auth::check()) {
        $replyBox = '<div class="yf-form" id="yf-replies"><h3 style="margin:0 0 4px;font-size:15px">' . e(yf_t('写回帖', 'Write a reply')) . '</h3>'
            . '<form method="post" action="/forum">' . \Csrf::field()
            . '<input type="hidden" name="website2" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">'
            . '<input type="hidden" name="action" value="reply"><input type="hidden" name="thread_id" value="' . (int)$th['id'] . '">'
            . '<label>' . e(yf_t('内容（支持 Markdown）', 'Content (Markdown supported)')) . '</label>'
            . '<textarea name="content" required minlength="2" maxlength="60000" placeholder="' . e(yf_t('有话直说，说清楚就好。', 'Say it plainly and clearly.')) . '"></textarea>'
            . '<button class="btn yf-btn" type="submit">' . e(yf_t('发表回帖', 'Post Reply')) . '</button>'
            . '<div class="hint">' . e(yf_t('发言请友善；管理员会处理恶意与广告内容。', 'Be kind. Moderators handle abuse and spam.')) . '</div></form></div>';
    } else {
        $replyBox = '<div style="background:var(--soft,#f6f3ec);border:1px dashed var(--line,#ddd6ca);border-radius:12px;padding:16px;text-align:center;margin-top:14px;color:#6f767e;font-size:13.5px">'
            . e(yf_t('登录后即可参与回帖。', 'Log in to join the discussion.')) . ' <a href="' . e(\ui_login(yf_url(['t' => $tid]))) . '" style="color:var(--accent,#B3541E)">' . e(yf_t('去登录 →', 'Log in →')) . '</a></div>';
    }
    $back = '<div style="margin:14px 0 0"><a class="yf-btn2" href="' . e(yf_url(['b' => (int)$th['board_id']])) . '">← ' . e(yf_t('返回版块', 'Back to board')) . '</a></div>';
    return '<div class="yf-wrap">' . $crumbs . $errHtml . $op
        . '<div class="yf-sec" style="margin-top:16px"><h2 style="font-size:17px;margin:0">' . (int)$th['replies'] . ' ' . e(yf_t('条回复', 'replies')) . '</h2></div>'
        . $repHtml . yf_pager($rp, $rpages, ['t' => $tid], 'rp')
        . $replyBox . $back . '</div>';
}

/* ================= 发帖表单 ================= */
function yf_new_form(string $err = ''): string
{
    if (!\Auth::check()) { \Uc::needLogin(yf_url(['mode' => 'new'])); }
    $boards = yf_boards_all();
    $sel = (int)($_GET['b'] ?? 0);
    $opts = '';
    foreach ($boards as $b) {
        $opts .= '<option value="' . (int)$b['id'] . '"' . ($sel === (int)$b['id'] ? ' selected' : '') . '>' . e(yf_bname($b)) . '</option>';
    }
    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    return '<div class="yf-wrap">'
        . '<div class="yf-crumbs"><a href="/forum">' . e(yf_t('论坛', 'Forum')) . '</a> › <span>' . e(yf_t('发新帖', 'New Topic')) . '</span></div>'
        . $errHtml
        . '<div class="yf-form"><h1 style="font-size:22px;margin:0 0 2px;font-family:var(--font-serif,\'Noto Serif SC\',serif)">' . e(yf_t('发起新帖', 'Start a New Topic')) . '</h1>'
        . '<form method="post" action="/forum">' . \Csrf::field()
        . '<input type="hidden" name="website2" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">'
        . '<input type="hidden" name="action" value="new">'
        . '<label>' . e(yf_t('选择版块 *', 'Board *')) . '</label><select name="board_id" required>' . $opts . '</select>'
        . '<label>' . e(yf_t('标题 *', 'Title *')) . '</label><input type="text" name="title" required maxlength="200" placeholder="' . e(yf_t('一句话说清你的问题或话题', 'One line that states your question or topic')) . '">'
        . '<label>' . e(yf_t('内容 *（支持 Markdown）', 'Content * (Markdown supported)')) . '</label>'
        . '<textarea name="content" required minlength="2" maxlength="60000" placeholder="' . e(yf_t('把背景、想讨论的点写清楚。', 'Give enough context so others can help.')) . '"></textarea>'
        . '<button class="btn yf-btn" type="submit">' . e(yf_t('发布帖子', 'Post Topic')) . '</button>'
        . '<div class="hint">' . e(yf_t('请友善发言；灌水/广告会被处理。', 'Be kind; spam gets moderated.')) . '</div>'
        . '</form></div></div>';
}

function yf_notfound_html(): string
{
    return '<div class="yf-wrap"><div class="yf-empty">' . e(yf_t('内容不存在或已被删除。', 'Not found or removed.')) . '<br><br><a class="yf-btn2" href="/forum">← ' . e(yf_t('返回论坛', 'Back to Forum')) . '</a></div></div>';
}
