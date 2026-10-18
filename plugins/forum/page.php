<?php
declare(strict_types=1);
// 论坛插件 · 前台（/forum）v4（t39）：linux.sb 式两栏 —— 版块列表 + 主题列表 + 统计侧栏；
// 版块 / 主题 / 帖子 / 发帖 / 回复 / 引用 / 编辑 / 软删 / 搜索 / 分页 / 个人资料 / 治理（置顶·精华·锁定）。
require_once __DIR__ . '/lib.php';

function yc_forum_page(string $slug): array
{
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
        } else {
            $err = yf_handle_post();
        }
    }
    yf_touch_presence();

    $mode = (string)($_GET['mode'] ?? '');
    if ($mode === 'new') {
        $curB = (string)($_GET['b'] ?? '');
        $old += ['board_id' => (int)($_GET['b'] ?? 0)];
        return ['html' => yf_frame(yf_css() . yf_new_form($err, $old, $preview), $curB), 'title' => yf_t('发起新帖', 'Start a New Topic') . ' · ' . site_label()];
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
        $th = Db::row('SELECT board_id FROM ' . Db::t('forum_threads') . " WHERE id=? AND status='normal' LIMIT 1", [$t]);
        $curB = $th ? (string)$th['board_id'] : '';
        return ['html' => yf_frame(yf_css() . yf_thread_view($t, $err), $curB), 'title' => yf_thread_title($t)];
    }
    $b = (string)($_GET['b'] ?? '');
    if ($b !== '') {
        return ['html' => yf_frame(yf_css() . yf_board_view($b, $err), $b), 'title' => yf_board_title($b)];
    }
    if ((string)($_GET['view'] ?? '') === 'all') {
        return ['html' => yf_frame(yf_css() . yf_list_view($err), ''), 'title' => yf_t('全部主题', 'All Topics') . ' · ' . site_label()];
    }
    return ['html' => yf_frame(yf_css() . yf_index_view($err), ''), 'title' => yf_t('论坛', 'Forum') . ' · ' . site_label()];
}

/* ---------- 结构外壳 ---------- */
function yf_frame(string $inner, string $curB = ''): string
{
    return '<div class="yf-forum">' . yf_topbar() . '<div class="yf-layout"><div class="yf-main">' . $inner . '</div>' . yf_side($curB) . '</div></div>';
}

function yf_topbar(): string
{
    $q = trim((string)($_GET['q'] ?? ''));
    $u = '';
    if (\Auth::check()) {
        $me = \Auth::user();
        $u = '<div class="yf-u"><a class="yf-ava" href="' . e(yf_url(['u' => (int)\Auth::id()])) . '" title="' . e(yf_uname($me)) . '">' . yf_ava($me, 24) . '</a>'
            . '<a href="' . e(yf_url(['u' => (int)\Auth::id()])) . '">' . e(yf_uname($me)) . '</a>'
            . '<a class="yf-btn sm" href="' . e(yf_url(['mode' => 'new'])) . '">+ ' . e(yf_t('发帖', 'New')) . '</a>'
            . '<a href="/logout">' . e(yf_t('退出', 'Log out')) . '</a></div>';
    } else {
        $u = '<div class="yf-u"><a href="' . e(\ui_login(yf_url())) . '">' . e(yf_t('登录', 'Log in')) . '</a>'
            . '<a class="yf-btn sm" href="' . e(\ui_register()) . '">' . e(yf_t('注册', 'Sign up')) . '</a></div>';
    }
    return '<div class="yf-top"><a class="yf-brand" href="/forum">' . e(yf_t('论坛', 'Forum')) . ' <em>·</em> ' . e(site_name()) . '</a>'
        . '<form method="get" action="/forum" role="search"><input type="search" name="q" value="' . e($q) . '" placeholder="' . e(yf_t('搜索标题 / 正文…', 'Search titles and posts…')) . '" aria-label="' . e(yf_t('搜索', 'Search')) . '">'
        . '<button class="yf-sbtn" type="submit">' . e(yf_t('搜索', 'Search')) . '</button></form>' . $u . '</div>';
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
    $uc = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('users'));
    $lm = Db::row('SELECT id, username, display_name FROM ' . Db::t('users') . ' ORDER BY id DESC LIMIT 1');
    $online = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('users') . ' WHERE last_seen_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $stats = '<div class="yf-stats"><span><b>' . $tt . '</b>' . e(yf_t('主题', 'topics')) . '</span>'
        . '<span><b>' . ($tt + $rt) . '</b>' . e(yf_t('帖数', 'posts')) . '</span>'
        . '<span><b>' . $uc . '</b>' . e(yf_t('会员', 'members')) . '</span></div>'
        . '<div class="yf-online"><span class="yf-dot"></span>' . e(yf_t('在线会员', 'Online')) . ' <b>' . $online . '</b></div>';
    $latest = '';
    foreach (Db::rows("SELECT id, title, replies FROM {$T} WHERE status='normal' ORDER BY pinned DESC, last_at DESC, id DESC LIMIT 5") as $th) {
        $latest .= '<li><a href="' . e(yf_url(['t' => (int)$th['id']])) . '"><span>' . e((string)$th['title']) . '</span><span class="n">' . (int)$th['replies'] . '</span></a></li>';
    }
    if ($latest === '') { $latest = '<li><span class="yf-empty" style="padding:10px 4px">' . e(yf_t('暂无主题', 'No topics yet')) . '</span></li>'; }
    $auth = '';
    if (\Auth::check()) {
        $u = \Auth::user();
        $auth = '<div class="yf-w yf-sidebox"><div style="display:flex;gap:9px;align-items:center">' . yf_ava($u, 34)
            . '<div><a href="' . e(yf_url(['u' => (int)\Auth::id()])) . '" style="font-weight:700;color:inherit;text-decoration:none">' . e(yf_uname($u)) . '</a>'
            . '<div style="font-size:11.5px;color:#9a9a90">' . e(yf_t('已登录', 'Signed in')) . '</div></div></div>'
            . '<a class="yf-btn" style="margin-top:9px" href="' . e(yf_url(['mode' => 'new'])) . '">+ ' . e(yf_t('发新帖', 'New Topic')) . '</a></div>';
    } else {
        $auth = '<div class="yf-w yf-sidebox"><p>' . e(yf_t('登录后即可发帖与回帖。', 'Log in to post and reply.')) . '</p>'
            . '<a class="yf-btn" href="' . e(\ui_login(yf_url())) . '">' . e(yf_t('去登录 →', 'Log in →')) . '</a>'
            . '<a class="yf-btn2" style="width:100%;justify-content:center;box-sizing:border-box;margin-top:6px" href="' . e(\ui_register()) . '">' . e(yf_t('注册账号', 'Create account')) . '</a></div>';
    }
    return '<aside class="yf-side">'
        . $auth
        . '<div class="yf-w"><h4>' . e(yf_t('站点统计', 'Site stats')) . '</h4>' . $stats
        . ($lm ? '<p style="margin:8px 0 0;font-size:12px;color:#9a9a90">' . e(yf_t('最新会员', 'Newest member')) . '：<a href="' . e(yf_url(['u' => (int)$lm['id']])) . '" style="color:#B3541E;text-decoration:none">' . e(yf_uname($lm)) . '</a></p>' : '')
        . '</div>'
        . '<div class="yf-w"><h4>' . e(yf_t('最新主题', 'Latest topics')) . '</h4><ul>' . $latest . '</ul></div>'
        . '<div class="yf-w"><h4>' . e(yf_t('版块', 'Boards')) . '</h4><ul>' . $li . '</ul></div>'
        . '<div class="yf-w yf-sidebox"><h4>' . e(yf_t('贴士', 'Tips')) . '</h4><p>' . e(yf_t('提问把场景写清楚，回复会更快；广告与人身攻击会被删除。', 'Describe your setup clearly for faster answers; ads and personal attacks get removed.')) . '</p></div>'
        . '</aside>';
}

/* ================= POST ================= */
function yf_handle_post(): string
{
    $action = (string)($_POST['action'] ?? '');
    try { \Csrf::check(); } catch (Throwable) { return yf_t('校验失败，请刷新页面后重试', 'Verification failed — please refresh and retry.'); }
    if (trim((string)($_POST['website2'] ?? '')) !== '') { return yf_t('操作过于频繁，请稍后再试', 'Too fast — slow down a little.'); }

    $T = Db::t('forum_threads');
    $R = Db::t('forum_replies');

    if ($action === 'new') {
        if (!\Auth::check()) { \Uc::needLogin(); }
        $boardId = (int)($_POST['board_id'] ?? 0);
        $board = $boardId > 0 ? yf_board($boardId) : null;
        if ($board === null) { return yf_t('请选择版块', 'Please choose a board.'); }
        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) { return yf_t('标题不能为空且不超过 200 字', 'Title is required and must be under 200 characters.'); }
        if (mb_strlen($content) < 2 || mb_strlen($content) > 60000) { return yf_t('内容至少 2 字，最多 60000 字', 'Content must be 2–60000 characters.'); }
        if (($r = yf_blocked($title . "\n" . $content)) !== '') { return $r; }
        if (($r = yf_rate_check()) !== '') { return $r; }
        $uid = (int)\Auth::id();
        $now = date('Y-m-d H:i:s');
        $tid = Db::insert('forum_threads', [
            'board_id' => (int)$board['id'], 'user_id' => $uid, 'title' => $title, 'content' => $content,
            'views' => 0, 'replies' => 0, 'status' => 'normal', 'pinned' => 0, 'featured' => 0, 'locked' => 0,
            'created_at' => $now, 'updated_at' => $now, 'last_at' => $now, 'last_user_id' => $uid,
        ]);
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
        if (($r = yf_blocked($content)) !== '') { return $r; }
        if (($r = yf_rate_check()) !== '') { return $r; }
        $uid = (int)\Auth::id();
        $quote = (int)($_POST['quote_id'] ?? 0);
        if ($quote > 0) {
            $ok = Db::one("SELECT id FROM {$R} WHERE id=? AND thread_id=? AND status='normal' LIMIT 1", [$quote, $tid]);
            if ($ok === null) { $quote = 0; }
        }
        $rid = Db::insert('forum_replies', ['thread_id' => $tid, 'user_id' => $uid, 'content' => $content, 'quote_id' => $quote > 0 ? $quote : null, 'status' => 'normal']);
        Db::run("UPDATE {$T} SET replies=replies+1, last_at=NOW(), last_user_id=?, updated_at=NOW() WHERE id=?", [$uid, $tid]);
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
        if (($r = yf_blocked($title . "\n" . $content)) !== '') { return $r; }
        Db::run("UPDATE {$T} SET title=?, content=?, updated_at=NOW() WHERE id=?", [$title, $content, $id]);
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
        if (($r = yf_blocked($content)) !== '') { return $r; }
        Db::run("UPDATE {$R} SET content=?, edited_at=NOW() WHERE id=?", [$content, $id]);
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
        header('Location: ' . yf_url(['t' => $id])); exit;
    }

    return '';
}

function yf_touch_presence(): void
{
    if (!\Auth::check()) { return; }
    Db::run('UPDATE ' . Db::t('users') . ' SET last_seen_at=NOW() WHERE id=? AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE))', [(int)\Auth::id()]);
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
        $b = yf_board((int)$th['board_id']);
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
    return '<li class="yf-thread"><a class="yf-ava" href="' . e(yf_url(['u' => (int)$th['user_id']])) . '">' . yf_ava($u, 36) . '</a>'
        . '<div class="tc"><div class="tt">' . yf_badges($th) . '<a href="' . e(yf_url(['t' => (int)$th['id']])) . '">' . e((string)$th['title']) . '</a></div>'
        . '<div class="tm">' . $meta . $last . '</div></div>'
        . '<div class="cnt"><span><b>' . (int)$th['replies'] . '</b>' . e(yf_t('回复', 'replies')) . '</span>'
        . '<span><b>' . (int)$th['views'] . '</b>' . e(yf_t('查看', 'views')) . '</span></div></li>';
}

function yf_board_row(array $b): string
{
    $T = Db::t('forum_threads');
    $bid = (int)$b['id'];
    $tc = (int)Db::one('SELECT COUNT(*) FROM ' . $T . " WHERE board_id=? AND status='normal'", [$bid]);
    $rc = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('forum_replies') . ' r JOIN ' . $T . ' t ON t.id=r.thread_id WHERE r.status=\'normal\' AND t.board_id=? AND t.status=\'normal\'', [$bid]);
    $last = '';
    $lt = Db::row("SELECT * FROM {$T} WHERE board_id=? AND status='normal' ORDER BY last_at DESC, id DESC LIMIT 1", [$bid]);
    if ($lt) {
        $lu = yf_user((int)($lt['last_user_id'] ?: $lt['user_id']));
        $last = '<div class="bl"><b>' . e(yf_t('最后发表', 'Last post')) . '</b>'
            . '<a href="' . e(yf_url(['t' => (int)$lt['id']])) . '">' . e(mb_substr((string)$lt['title'], 0, 22)) . '</a>'
            . e(yf_uname($lu)) . ' · ' . e(yf_ago((string)$lt['last_at'])) . '</div>';
    }
    return '<div class="yf-brow">'
        . '<a class="bb" href="' . e(yf_url(['b' => $bid])) . '"><span class="yf-ico">' . e(mb_substr(yf_bname($b), 0, 1)) . '</span>'
        . '<div style="min-width:0"><b>' . e(yf_bname($b)) . '</b><p>' . e((string)$b['intro']) . '</p></div></a>'
        . '<div class="bc"><b>' . $tc . '</b>' . e(yf_t('主题', 'topics')) . '</div>'
        . '<div class="bc"><b>' . $rc . '</b>' . e(yf_t('回复', 'replies')) . '</div>'
        . $last . '</div>';
}

/* ================= 首页 ================= */
function yf_index_view(string $err = ''): string
{
    $boards = yf_boards_all();
    $bh = '';
    foreach ($boards as $b) { $bh .= yf_board_row($b); }
    if ($bh === '') { $bh = '<div class="yf-empty">' . e(yf_t('还没有版块。', 'No boards yet.')) . '</div>'; }
    $T = Db::t('forum_threads');
    $latest = Db::rows("SELECT * FROM {$T} WHERE status='normal' ORDER BY pinned DESC, last_at DESC, id DESC LIMIT 12");
    $rows = '';
    foreach ($latest as $th) { $rows .= yf_row($th, true); }
    if ($rows === '') { $rows = '<div class="yf-empty">' . e(yf_t('还没有帖子，来发第一帖吧。', 'No topics yet — start the first one.')) . '</div>'; }

    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    $head = '<div class="yf-head"><h1>' . e(yf_t('论坛', 'Forum')) . '</h1>'
        . '<p class="yf-sub">' . e(yf_t('问问题、聊使用、晒作品——干净又轻快的讨论区。', 'Ask, discuss, show off your builds — a clean, light community.')) . '</p></div>';
    $hint = !\Auth::check() ? '<p class="yf-sub" style="color:#9a9a90;margin-top:-6px">' . e(yf_t('游客可浏览；登录后可发帖与回帖。', 'Guests can browse; log in to post and reply.')) . '</p>' : '';
    $bar = '<div class="yf-bar"><span style="color:#9a9a90;font-size:13px">' . count($boards) . ' ' . e(yf_t('个版块', 'boards')) . ' · ' . (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE status='normal'") . ' ' . e(yf_t('个主题', 'topics')) . '</span>'
        . '<a class="yf-btn" style="margin-left:auto" href="' . e(yf_url(['mode' => 'new'])) . '">+ ' . e(yf_t('发新帖', 'New Topic')) . '</a></div>';
    $secBoard = '<div class="yf-sec"><span class="en2">Boards</span><h2>' . e(yf_t('论坛版块', 'Boards')) . '</h2></div>';
    $secLatest = '<div class="yf-sec"><span class="en2">Latest</span><h2>' . e(yf_t('最新主题', 'Latest Topics')) . '</h2>'
        . '<a class="more" href="' . e(yf_url(['view' => 'all'])) . '">' . e(yf_t('全部主题 →', 'All topics →')) . '</a></div>';
    return '<div class="yf-wrap">' . $head . $errHtml . $hint . $bar
        . $secBoard . '<div class="yf-blist">' . $bh . '</div>'
        . $secLatest . '<ul class="yf-tlist">' . $rows . '</ul>'
        . '<p style="color:#9a9a90;font-size:12.5px;margin-top:18px;">Powered by YeCha Forum</p></div>';
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
    $total = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE {$where}");
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    $threads = Db::rows("SELECT * FROM {$T} WHERE {$where} ORDER BY {$order} LIMIT " . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $rows = '';
    foreach ($threads as $th) { $rows .= yf_row($th, true); }
    if ($rows === '') { $rows = '<div class="yf-empty">' . e(yf_t('还没有帖子，来发第一帖吧。', 'No topics yet — start the first one.')) . '</div>'; }

    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    $head = '<div class="yf-head"><h1>' . e(yf_t('全部主题', 'All Topics')) . '</h1>'
        . '<p class="yf-sub">' . e(yf_t('按最新回复、热度或精华筛选。', 'Filter by latest reply, activity or featured.')) . '</p></div>';
    $bar = '<div class="yf-bar"><a class="yf-btn2 sm" href="/forum">← ' . e(yf_t('返回论坛', 'Back to forum')) . '</a><span style="color:#9a9a90;font-size:13px">' . $total . ' ' . e(yf_t('个主题', 'topics')) . '</span>'
        . '<a class="yf-btn" style="margin-left:auto" href="' . e(yf_url(['mode' => 'new'])) . '">+ ' . e(yf_t('发新帖', 'New Topic')) . '</a></div>';
    $tabs = '<div class="yf-tabs">' . yf_tab('', '最新', 'Latest') . yf_tab('hot', '最热', 'Hot') . yf_tab('good', '精华', 'Featured') . '</div>';
    return '<div class="yf-wrap">' . $head . $errHtml . $bar . $tabs
        . '<ul class="yf-tlist">' . $rows . '</ul>' . yf_pager($page, $pages, [])
        . '<p style="color:#9a9a90;font-size:12.5px;margin-top:18px;">Powered by YeCha Forum</p></div>';
}

/* ================= 板块页 ================= */
function yf_board_title(string $b): string
{
    $row = yf_board($b);
    $name = $row ? yf_bname($row) : yf_t('版块', 'Board');
    return $name . ' · ' . yf_t('论坛', 'Forum') . ' · ' . site_label();
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
    $crumbs = '<div class="yf-crumbs"><a href="/forum">' . e(yf_t('论坛', 'Forum')) . '</a> › <span>' . e(yf_bname($board)) . '</span></div>';
    $head = '<div class="yf-head"><h1>' . e(yf_bname($board)) . '</h1><p class="yf-sub">' . e((string)$board['intro']) . '</p></div>';
    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    $bar = '<div class="yf-bar"><a class="yf-btn2 sm" href="/forum">← ' . e(yf_t('返回', 'Back')) . '</a>'
        . '<span style="color:#9a9a90;font-size:13px">' . $total . ' ' . e(yf_t('主题', 'topics')) . '</span>'
        . '<a class="yf-btn" style="margin-left:auto" href="' . e(yf_url(['mode' => 'new', 'b' => (int)$board['id']])) . '">+ ' . e(yf_t('发新帖', 'New Topic')) . '</a></div>';
    $tabs = '<div class="yf-tabs">' . yf_tab('', '最新', 'Latest', ['b' => (int)$board['id']]) . yf_tab('hot', '最热', 'Hot', ['b' => (int)$board['id']]) . yf_tab('good', '精华', 'Featured', ['b' => (int)$board['id']]) . '</div>';
    return '<div class="yf-wrap">' . $crumbs . $errHtml . $head . $bar . $tabs
        . '<ul class="yf-tlist">' . $rows . '</ul>' . yf_pager($page, $pages, ['b' => (int)$board['id']]) . '</div>';
}

/* ================= 帖子详情 ================= */
function yf_thread_title(int $t): string
{
    $row = Db::row('SELECT title FROM ' . Db::t('forum_threads') . " WHERE id=? AND status='normal' LIMIT 1", [$t]);
    return ($row ? (string)$row['title'] : yf_t('帖子', 'Topic')) . ' · ' . site_label();
}

function yf_thread_view(int $tid, string $err = ''): string
{
    $T = Db::t('forum_threads');
    $R = Db::t('forum_replies');
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
        . '<span>· ' . (int)$th['replies'] . ' ' . e(yf_t('回复', 'replies')) . '</span></div>'
        . ($mods !== '' ? '<div class="yf-mods">' . $mods . '</div>' : '')
        . '<div class="yf-floor-card op"><div class="yf-fhead"><span class="fn">#1</span>'
        . '<a class="yf-ava" href="' . e(yf_url(['u' => (int)$th['user_id']])) . '">' . yf_ava($au, 26) . '</a>'
        . '<span class="fname">' . e(yf_uname($au)) . '</span><span>' . e(yf_dt((string)$th['created_at'])) . '</span>'
        . '<span class="fx"><span class="yf-tag pin">' . e(yf_t('楼主', 'OP')) . '</span>' . $opActions . '</span></div>'
        . '<div class="yf-fbody">' . yf_md((string)$th['content']) . '</div></div>';

    $per = 20;
    $rp = max(1, (int)($_GET['rp'] ?? 1));
    $rtotal = (int)Db::one("SELECT COUNT(*) FROM {$R} WHERE thread_id=? AND status='normal'", [$tid]);
    $rpages = max(1, (int)ceil($rtotal / $per));
    $rp = min($rp, $rpages);
    $replies = Db::rows("SELECT * FROM {$R} WHERE thread_id=? AND status='normal' ORDER BY id ASC LIMIT " . (int)$per . ' OFFSET ' . (int)(($rp - 1) * $per), [$tid]);
    // 引用内容：一次取回本页引用的回帖
    $qids = [];
    foreach ($replies as $r) { if ((int)($r['quote_id'] ?? 0) > 0) { $qids[] = (int)$r['quote_id']; } }
    $qmap = [];
    if ($qids !== []) {
        $in = implode(',', array_fill(0, count($qids), '?'));
        foreach (Db::rows("SELECT id, user_id, content FROM {$R} WHERE id IN ({$in})", $qids) as $qr) { $qmap[(int)$qr['id']] = $qr; }
    }
    $repHtml = '';
    $idx = ($rp - 1) * $per + 1;
    foreach ($replies as $r) {
        $ru = yf_user((int)$r['user_id']);
        $quote = '';
        $qid = (int)($r['quote_id'] ?? 0);
        if ($qid > 0 && isset($qmap[$qid])) {
            $qu = yf_user((int)$qmap[$qid]['user_id']);
            $quote = '<div class="yf-quote"><b>@' . e(yf_uname($qu)) . '</b>' . e(yf_t(' 的楼层', ' wrote')) . ':'
                . '<span class="qp">' . e(yf_excerpt((string)$qmap[$qid]['content'], 160)) . '</span></div>';
        }
        $acts = '<a href="' . e(yf_url(['t' => $tid, 'quote' => (int)$r['id']])) . '#yf-reply">' . e(yf_t('引用', 'Quote')) . '</a>';
        if (yf_can_edit((int)$r['user_id'])) {
            $acts .= '<a href="' . e(yf_url(['mode' => 'edit', 'rid' => (int)$r['id']])) . '">' . e(yf_t('编辑', 'Edit')) . '</a>';
            $acts .= '<form method="post" action="' . e(yf_url(['t' => $tid])) . '" onsubmit="return confirm(\'删除该回帖？\')" style="display:inline">' . \Csrf::field()
                . '<input type="hidden" name="action" value="del_reply"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button type="submit">' . e(yf_t('删除', 'Delete')) . '</button></form>';
        }
        $redited = !empty($r['edited_at']) ? ' · ' . e(yf_t('已编辑', 'edited')) : '';
        $repHtml .= '<div class="yf-floor-card" id="yf-r' . (int)$r['id'] . '"><div class="yf-fhead"><span class="fn">#' . $idx . '</span>'
            . '<a class="yf-ava" href="' . e(yf_url(['u' => (int)$r['user_id']])) . '">' . yf_ava($ru, 26) . '</a>'
            . '<span class="fname">' . e(yf_uname($ru)) . '</span><span>' . e(yf_dt((string)$r['created_at'])) . $redited . '</span>'
            . '<span class="fx">' . $acts . '</span></div>'
            . '<div class="yf-fbody">' . $quote . yf_md((string)$r['content']) . '</div></div>';
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
        $replyBox = '<div class="yf-form" id="yf-reply"><h3>' . e(yf_t('写回帖', 'Write a reply')) . '</h3>'
            . '<form method="post" action="' . e(yf_url(['t' => $tid])) . '">' . \Csrf::field()
            . '<input type="hidden" name="website2" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">'
            . '<input type="hidden" name="action" value="reply"><input type="hidden" name="thread_id" value="' . (int)$th['id'] . '">'
            . '<input type="hidden" name="quote_id" value="' . ($quoteId > 0 ? $quoteId : 0) . '">'
            . '<label>' . e(yf_t('内容（支持 Markdown）', 'Content (Markdown supported)')) . '</label>'
            . '<textarea name="content" required minlength="2" maxlength="60000" placeholder="' . e(yf_t('有话直说，说清楚就好。', 'Say it plainly and clearly.')) . '">' . e($quoteText) . '</textarea>'
            . '<div class="row"><button class="yf-btn" type="submit">' . e(yf_t('发表回帖', 'Post Reply')) . '</button>'
            . ($quoteId > 0 ? '<a class="yf-btn2 sm" href="' . e(yf_url(['t' => $tid])) . '#yf-reply">' . e(yf_t('取消引用', 'Cancel quote')) . '</a>' : '')
            . '</div><div class="hint">' . e(yf_t('发言请友善；管理员会处理恶意与广告内容。', 'Be kind. Moderators handle abuse and spam.')) . '</div></form></div>';
    } else {
        $replyBox = '<div class="yf-form" id="yf-reply" style="text-align:center;color:#6f767e;font-size:13.5px">'
            . e(yf_t('登录后即可参与回帖。', 'Log in to join the discussion.')) . ' <a href="' . e(\ui_login(yf_url(['t' => $tid]))) . '" style="color:#B3541E">' . e(yf_t('去登录 →', 'Log in →')) . '</a></div>';
    }
    $back = '<div style="margin:14px 0 0"><a class="yf-btn2 sm" href="' . e(yf_url(['b' => (int)$th['board_id']])) . '">← ' . e(yf_t('返回版块', 'Back to board')) . '</a></div>';
    return '<div class="yf-wrap">' . $crumbs . $errHtml . $op
        . '<div class="yf-sec"><h2>' . (int)$th['replies'] . ' ' . e(yf_t('条回复', 'replies')) . '</h2></div>'
        . $repHtml . yf_pager($rp, $rpages, ['t' => $tid], 'rp')
        . $replyBox . $back . '</div>';
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
    return '<div class="yf-wrap"><div class="yf-crumbs"><a href="/forum">' . e(yf_t('论坛', 'Forum')) . '</a> › <span>' . e(yf_t('搜索', 'Search')) . '</span></div>'
        . '<div class="yf-head"><h1>' . e(yf_t('搜索', 'Search')) . '</h1><p class="yf-sub">' . e(yf_t('关键词', 'Keyword')) . ' “' . e($q) . '” · ' . $total . ' ' . e(yf_t('个结果', 'results')) . '</p></div>'
        . '<form method="get" action="/forum" style="display:flex;gap:6px;margin:0 0 14px"><input type="search" name="q" value="' . e($q) . '" style="flex:1;min-width:0;border:1px solid #e2ddd2;border-radius:999px;padding:8px 14px;font:inherit"><button class="yf-btn" type="submit">' . e(yf_t('搜索', 'Search')) . '</button></form>'
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
    return '<div class="yf-wrap"><div class="yf-crumbs"><a href="/forum">' . e(yf_t('论坛', 'Forum')) . '</a> › <span>' . e(yf_uname($u)) . '</span></div>'
        . '<div class="yf-prof">' . yf_ava($u, 64) . '<div><div class="pn">' . e(yf_uname($u)) . '</div>'
        . '<div class="pm">' . e($roleName) . ($joined !== '' ? ' · ' . e(yf_t('注册于', 'joined')) . ' ' . e($joined) : '') . '</div>'
        . ($bio !== '' ? '<div class="pm">' . e($bio) . '</div>' : '')
        . '<div class="yf-kv"><span><b>' . $tc . '</b>' . e(yf_t('主题', 'topics')) . '</span><span><b>' . $rc . '</b>' . e(yf_t('回帖', 'replies')) . '</span></div></div></div>'
        . '<div class="yf-sec"><h2>' . e(yf_t('最近主题', 'Recent topics')) . '</h2></div><ul class="yf-tlist">' . $th . '</ul>'
        . '<div class="yf-sec"><h2>' . e(yf_t('最近回帖', 'Recent replies')) . '</h2></div><ul class="yf-tlist">' . $rp . '</ul></div>';
}

/* ================= 发帖 / 编辑 ================= */
function yf_new_form(string $err = '', array $old = [], string $preview = ''): string
{
    if (!\Auth::check()) { \Uc::needLogin(yf_url(['mode' => 'new'])); }
    $boards = yf_boards_all();
    $sel = (int)($old['board_id'] ?? 0);
    if ($sel === 0) { $sel = (int)($_GET['b'] ?? 0); }
    $opts = '';
    foreach ($boards as $b) {
        $opts .= '<option value="' . (int)$b['id'] . '"' . ($sel === (int)$b['id'] ? ' selected' : '') . '>' . e(yf_bname($b)) . '</option>';
    }
    $errHtml = $err !== '' ? '<div class="yf-err">' . e($err) . '</div>' : '';
    $preHtml = $preview !== '' ? '<div class="yf-pre"><div class="cap">' . e(yf_t('预览', 'Preview')) . '</div>' . $preview . '</div>' : '';
    return '<div class="yf-wrap">'
        . '<div class="yf-crumbs"><a href="/forum">' . e(yf_t('论坛', 'Forum')) . '</a> › <span>' . e(yf_t('发新帖', 'New Topic')) . '</span></div>'
        . $errHtml
        . '<div class="yf-form"><h2>' . e(yf_t('发起新帖', 'Start a New Topic')) . '</h2>'
        . '<form method="post" action="' . e(yf_url(['mode' => 'new'])) . '">' . \Csrf::field()
        . '<input type="hidden" name="website2" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">'
        . '<label>' . e(yf_t('选择版块 *', 'Board *')) . '</label><select name="board_id" required>' . $opts . '</select>'
        . '<label>' . e(yf_t('标题 *', 'Title *')) . '</label><input type="text" name="title" required maxlength="200" value="' . e((string)($old['title'] ?? '')) . '" placeholder="' . e(yf_t('一句话说清你的问题或话题', 'One line that states your question or topic')) . '">'
        . '<label>' . e(yf_t('内容 *（支持 Markdown）', 'Content * (Markdown supported)')) . '</label>'
        . '<textarea name="content" required minlength="2" maxlength="60000" placeholder="' . e(yf_t('把背景、想讨论的点写清楚。', 'Give enough context so others can help.')) . '">' . e((string)($old['content'] ?? '')) . '</textarea>'
        . $preHtml
        . '<div class="row"><button class="yf-btn" type="submit" name="action" value="new">' . e(yf_t('发布帖子', 'Post Topic')) . '</button>'
        . '<button class="yf-btn2" type="submit" name="action" value="preview">' . e(yf_t('预览', 'Preview')) . '</button>'
        . '<a class="yf-btn2 sm" href="/forum">' . e(yf_t('取消', 'Cancel')) . '</a></div>'
        . '<div class="hint">' . e(yf_t('请友善发言；灌水/广告会被处理。', 'Be kind; spam gets moderated.')) . '</div>'
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
    return '<div class="yf-wrap"><div class="yf-crumbs"><a href="/forum">' . e(yf_t('论坛', 'Forum')) . '</a> › <a href="' . e(yf_url(['t' => $id])) . '">' . e(mb_substr((string)$th['title'], 0, 24)) . '</a> › <span>' . e(yf_t('编辑', 'Edit')) . '</span></div>'
        . $errHtml . '<div class="yf-form"><h2>' . e(yf_t('编辑帖子', 'Edit Topic')) . '</h2>'
        . '<form method="post" action="' . e(yf_url(['mode' => 'edit', 't' => $id])) . '">' . \Csrf::field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<label>' . e(yf_t('标题 *', 'Title *')) . '</label><input type="text" name="title" required maxlength="200" value="' . e((string)$title) . '">'
        . '<label>' . e(yf_t('内容 *（支持 Markdown）', 'Content * (Markdown supported)')) . '</label>'
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
    return '<div class="yf-wrap"><div class="yf-crumbs"><a href="/forum">' . e(yf_t('论坛', 'Forum')) . '</a> › <a href="' . e(yf_url(['t' => (int)$r['thread_id']])) . '">#' . (int)$r['id'] . '</a> › <span>' . e(yf_t('编辑回帖', 'Edit Reply')) . '</span></div>'
        . $errHtml . '<div class="yf-form"><h2>' . e(yf_t('编辑回帖', 'Edit Reply')) . '</h2>'
        . '<form method="post" action="' . e(yf_url(['mode' => 'edit', 'rid' => $id])) . '">' . \Csrf::field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<label>' . e(yf_t('内容 *（支持 Markdown）', 'Content * (Markdown supported)')) . '</label>'
        . '<textarea name="content" required minlength="2" maxlength="60000">' . e((string)$content) . '</textarea>'
        . '<div class="row"><button class="yf-btn" type="submit" name="action" value="edit_reply">' . e(yf_t('保存修改', 'Save')) . '</button>'
        . '<a class="yf-btn2 sm" href="' . e(yf_url(['t' => (int)$r['thread_id']])) . '">' . e(yf_t('取消', 'Cancel')) . '</a></div></form></div></div>';
}

function yf_notfound_html(): string
{
    return '<div class="yf-wrap"><div class="yf-empty">' . e(yf_t('内容不存在或已被删除。', 'Not found or removed.')) . '<br><br><a class="yf-btn2" href="/forum">← ' . e(yf_t('返回论坛', 'Back to Forum')) . '</a></div></div>';
}
