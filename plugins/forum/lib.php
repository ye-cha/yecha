<?php
declare(strict_types=1);
/* 论坛插件 · 公共辅助（yf_ 前缀防冲突）· v2（t39 按 linux.sb 布局重做） */

function yf_en(): bool { return Lang::short() === 'en'; }
function yf_t(string $zh, string $en): string { return yf_en() ? $en : $zh; }
function yf_dt(?string $s): string { return $s ? date('Y-m-d H:i', strtotime($s)) : ''; }
function yf_ago(?string $s): string
{
    if (!$s) { return ''; }
    $t = strtotime($s);
    if ($t === false) { return ''; }
    $d = time() - $t;
    if ($d < 60) { return yf_t('刚刚', 'just now'); }
    if ($d < 3600) { $n = (string)max(1, (int)floor($d / 60)); return yf_t($n . ' 分钟前', $n . 'm ago'); }
    if ($d < 86400) { $n = (string)max(1, (int)floor($d / 3600)); return yf_t($n . ' 小时前', $n . 'h ago'); }
    if ($d < 2592000) { $n = (string)max(1, (int)floor($d / 86400)); return yf_t($n . ' 天前', $n . 'd ago'); }
    return yf_dt($s);
}

/**
 * ★ yf-html-20261020：URL 改「平铺 .html」形态 —— 不用二级路径。
 *
 *   /forum.html                  首页（全部主题）
 *   /forum-b14.html              板块
 *   /forum-t10.html              帖子
 *   /forum-u1.html               用户资料
 *   /forum-new.html              发起新帖
 *   /forum-drafts.html           我的草稿
 *   /forum-edit-t10.html         编辑帖子
 *   /forum-edit-r5.html          编辑回帖
 *   /forum-all.html              全部主题
 *   /forum-unread.html           未读
 *   /forum-search.html?q=xx      搜索
 *   /forum-latest.html           排序：最新（popular=热议 / best=精华）
 *   /forum-hot-24h.html          热榜窗口（hot-36h / hot-week）
 *
 * 为什么平铺：站主裁定「伪静态不需要二级」—— `forum-b14.html` 是一个文件名，
 * 不是 `forum/b14` 那种目录层级。nginx 已有 `^/[A-Za-z0-9][A-Za-z0-9._-]*\.html$`
 * 规则把这类请求交给 index.php，由它的归一化段翻译回 $_GET。
 *
 * 其余参数（分页 p / 引用 quote / 排序与热榜组合 / 草稿恢复 restore / 楼层锚点 post）
 * 仍以 query 追加 —— 这些是「同一页面的参数」，独立成文件名没有意义。
 */
function yf_url(array $q = []): string
{
    /* 白名单：顺序即 query 里的输出顺序 */
    $allow = ['mode', 'view', 'f', 'b', 't', 'rp', 'u', 'q', 'quote', 'rid', 'p', 'unread', 'restore', 'post', 'sort', 'hot'];
    $keep = [];
    foreach ($allow as $k) {
        if (isset($q[$k]) && (string)$q[$k] !== '') { $keep[$k] = $q[$k]; }
    }

    $key  = '';                                    /* 文件名里 forum- 后面那段 */
    $mode = (string)($keep['mode'] ?? '');

    if ($mode === 'new') {
        $key = 'new';
        unset($keep['mode']);
    } elseif ($mode === 'drafts') {
        $key = 'drafts';
        unset($keep['mode']);
    } elseif ($mode === 'attach') {
        $key = 'attach';            /* 上传端点（AJAX 用，不在界面上出现） */
        unset($keep['mode']);
    } elseif ($mode === 'edit') {
        unset($keep['mode']);
        if (!empty($keep['t'])) {
            $key = 'edit-t' . (int)$keep['t'];
            unset($keep['t']);
        } elseif (!empty($keep['rid'])) {
            $key = 'edit-r' . (int)$keep['rid'];
            unset($keep['rid']);
        }
    }

    if ($key === '') {
        if (!empty($keep['q'])) {
            $key = 'search';                        /* q 留在 query 里 */
        } elseif (!empty($keep['sort'])) {
            /* 排序视图：单独出现时进文件名；和板块/帖子同时出现时保留 query */
            $map = ['new' => 'latest', 'hot' => 'popular', 'good' => 'best'];
            if (isset($map[(string)$keep['sort']])) { $key = $map[(string)$keep['sort']]; unset($keep['sort']); }
        } elseif (!empty($keep['hot'])) {
            $map = ['24h' => 'hot-24h', '36h' => 'hot-36h', 'week' => 'hot-week'];
            if (isset($map[(string)$keep['hot']]) && count($keep) === 1) { $key = $map[(string)$keep['hot']]; unset($keep['hot']); }
        } elseif (!empty($keep['t'])) {
            $key = 't' . (int)$keep['t'];
            unset($keep['t']);
        } elseif (!empty($keep['b'])) {
            $key = 'b' . (int)$keep['b'];
            unset($keep['b']);
        } elseif (!empty($keep['u'])) {
            $key = 'u' . (int)$keep['u'];
            unset($keep['u']);
        } elseif ((string)($keep['view'] ?? '') === 'all' && !empty($keep['unread'])) {
            $key = 'unread';
            unset($keep['view'], $keep['unread']);
        } elseif ((string)($keep['view'] ?? '') === 'all') {
            $key = 'all';
            unset($keep['view']);
        }
    }

    $path = '/forum' . ($key !== '' ? '-' . $key : '') . '.html';
    return $path . ($keep ? '?' . http_build_query($keep) : '');
}

function yf_board(int|string $b): ?array
{
    static $m = [];
    $k = (string)$b;
    if (array_key_exists($k, $m)) { return $m[$k]; }
    $T = Db::t('forum_boards');
    if (is_numeric($b)) {
        $row = Db::row("SELECT * FROM {$T} WHERE id=? AND status='show' LIMIT 1", [(int)$b]) ?: null;
    } else {
        $row = Db::row("SELECT * FROM {$T} WHERE slug=? AND status='show' LIMIT 1", [(string)$b]) ?: null;
    }
    $m[$k] = $row;
    return $row;
}

function yf_user(int $id): array
{
    static $m = [];
    if (isset($m[$id])) { return $m[$id]; }
    $u = Db::row('SELECT id, username, display_name, role, avatar, created_at, last_seen_at FROM ' . Db::t('users') . ' WHERE id=? LIMIT 1', [$id]);
    $m[$id] = $u ?: ['id' => $id, 'username' => '?', 'display_name' => '', 'role' => '', 'avatar' => '', 'created_at' => null, 'last_seen_at' => null];
    return $m[$id];
}

function yf_uname(array $u): string
{
    return trim((string)($u['display_name'] ?? '')) !== '' ? (string)$u['display_name'] : (string)($u['username'] ?? 'anonymous');
}

function yf_md(string $c): string
{
    return \Markdown::render($c);
}

function yf_pager(int $cur, int $pages, array $base = [], string $key = 'p'): string
{
    if ($pages <= 1) { return ''; }
    $cur = max(1, min($cur, $pages));
    $h = '<nav class="yf-pg" aria-label="pager">';
    if ($cur > 1) { $h .= '<a href="' . e(yf_url($base + [$key => $cur - 1])) . '" rel="prev">‹</a>'; }
    for ($i = 1; $i <= $pages; $i++) {
        if ($pages > 9 && abs($i - $cur) > 2 && $i !== 1 && $i !== $pages) { continue; }
        $h .= '<a class="' . ($i === $cur ? 'on' : '') . '" href="' . e(yf_url($base + [$key => $i])) . '">' . $i . '</a>';
    }
    if ($cur < $pages) { $h .= '<a href="' . e(yf_url($base + [$key => $cur + 1])) . '" rel="next">›</a>'; }
    return $h . '</nav>';
}

/** 字母头像（无上传头像时）：按名字取色 */
function yf_ava(array $u, int $size = 38): string
{
    $name = yf_uname($u);
    $av = trim((string)($u['avatar'] ?? ''));
    if ($av !== '' && preg_match('#^(https?://|/)#', $av)) {
        return '<img class="ava-img" src="' . e($av) . '" alt="' . e($name) . '" width="' . $size . '" height="' . $size . '" loading="lazy">';
    }
    $ch = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '?';
    $hue = (int)(crc32($name) % 360);
    $st = 'background:hsl(' . $hue . ',46%,88%);color:hsl(' . $hue . ',48%,26%)';
    return '<span class="ava" style="' . $st . ';width:' . $size . 'px;height:' . $size . 'px;font-size:' . (int)max(11, $size * 0.42) . 'px">' . e($ch) . '</span>';
}

function yf_bname(array $b): string
{
    return yf_en() && trim((string)$b['name_en']) !== '' ? (string)$b['name_en'] : (string)$b['name'];
}

function yf_boards_all(): array
{
    return Db::rows('SELECT * FROM ' . Db::t('forum_boards') . " WHERE status='show' ORDER BY sort ASC, id ASC");
}

function yf_badges(array $th): string
{
    $h = '';
    if ((int)($th['pinned'] ?? 0) === 1) { $h .= '<span class="yf-tag pin">' . e(yf_t('置顶', 'Pinned')) . '</span>'; }
    if ((int)($th['featured'] ?? 0) === 1) { $h .= '<span class="yf-tag good">' . e(yf_t('精华', 'Featured')) . '</span>'; }
    if ((int)($th['locked'] ?? 0) === 1) { $h .= '<span class="yf-tag lock">' . e(yf_t('已锁', 'Locked')) . '</span>'; }
    return $h;
}

function yf_can_edit(int $ownerId): bool
{
    return \Auth::check() && ((int)\Auth::id() === $ownerId || \Auth::isAdmin());
}

/** 反垃圾：屏蔽词 + 链接数（可在后台 options 里改 forum.words，逗号分隔） */
/** ★ wf-forum-20261020：一次做完「拒绝 + 打码」。
 *  $title 可为 null（回帖没有标题）。拒绝时返回原因；通过时返回 ''，并就地改写传入的字符串。 */
function yf_gate(?string &$title, string &$content): string
{
    $text = ($title !== null ? $title . "\n" : '') . $content;
    if (($r = yf_blocked($text)) !== '') { return $r; }
    if (function_exists('wf_gate')) {
        if ($title !== null) {
            $gt = wf_gate($title, 'forum');
            if ($gt['action'] === 'block') { return (string)$gt['msg']; }
            $title = (string)$gt['text'];
        }
        $gc = wf_gate($content, 'forum');
        if ($gc['action'] === 'block') { return (string)$gc['msg']; }
        $content = (string)$gc['text'];
    }
    return '';
}

function yf_blocked(string $text): string
{
    $raw = trim((string)get_option('forum.words', '加微信,代开发票,博彩,菠菜,办证,裸聊,约炮,私彩,洗钱,信用卡套现,刷单'));
    foreach (preg_split('/[,，\s]+/u', $raw) ?: [] as $w) {
        $w = trim($w);
        if ($w !== '' && mb_stripos($text, $w) !== false) {
            return yf_t('内容包含被拦截的词（' . $w . '），请修改后再发', 'Your post contains a blocked word (' . $w . '). Please edit and retry.');
        }
    }
    if (preg_match_all('#https?://#i', $text) > 3) {
        return yf_t('外链太多（最多 3 条），请精简后再发', 'Too many links (max 3). Please trim and retry.');
    }
    /* ★ wf-forum-20261020：接入「内容过滤」插件（wordfilter）。
       装了才生效；没装时 function_exists 为 false，行为与原来完全一致。
       这里只处理「整条拒绝」，打码改写走下面新增的 yf_gate()。 */
    if (function_exists('wf_reason')) {
        $wr = wf_reason($text, 'forum');
        if ($wr !== '') { return $wr; }
    }
    return '';
}

function yf_rate_check(): string
{
    if (!\Auth::check()) { return yf_t('请先登录', 'Please log in first.'); }
    $n = (int)\Cache::incr('forum_rate', 'u' . (int)\Auth::id(), 300);
    if ($n > 6) { return yf_t('操作太快了，休息几分钟再继续', 'Posting too fast — take a short break.'); }
    return '';
}

function yf_excerpt(string $s, int $n = 120): string
{
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? '');
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
}

/* ================= t50：分享 / 点赞 / 草稿 / 未读 / 通知 ================= */

/** 站点绝对源（分享链接用；跟随当前 Host 与 scheme） */
function yf_origin(): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'yecha.org'));
    if ($host === '') { $host = 'yecha.org'; }
    $sc = function_exists('yc_theme_scheme') ? yc_theme_scheme() : 'https';
    return ($sc === 'https' ? 'https' : 'http') . '://' . $host;
}

/** 论坛永久短链：/forum/f/<主题>[/<楼层回帖 id>] */
function yf_share_path(int $tid, int $postId = 0): string
{
    return '/forum/f/' . $tid . ($postId > 0 ? '/' . $postId : '');
}

function yf_share_url(int $tid, int $postId = 0): string
{
    return yf_origin() . yf_share_path($tid, $postId);
}

/** 分享按钮：点击复制永久链接（JS 见 yf_js；无 JS 时按钮无副作用） */
function yf_share_btn(int $tid, int $postId = 0): string
{
    $u = yf_share_url($tid, $postId) . ($postId > 0 ? '#p' . $postId : '');
    return '<button type="button" class="yf-share" data-share="' . e($u) . '" title="' . e(yf_t('复制该层永久链接', 'Copy permanent link to this post')) . '">'
        . '🔗 ' . e(yf_t('分享', 'Share')) . '</button>';
}

/** 可扩展的表情集合（kind => emoji） */
function yf_react_kinds(): array
{
    return ['like' => '👍', 'love' => '❤️', 'haha' => '😄'];
}

/**
 * 批量取某页所有楼层/主题的点赞聚合（一次 SQL，避免 N+1）。
 * @param array{thread?:int[],reply?:int[]} $wanted
 * @return array<string,array<int,array<string,array{n:int,mine:bool}>>>
 */
function yf_reactions_multi(array $wanted): array
{
    $out = [];
    $wheres = [];
    $args = [];
    $uid = \Auth::check() ? (int)\Auth::id() : 0;
    foreach (['thread', 'reply'] as $ty) {
        $ids = array_values(array_unique(array_map('intval', (array)($wanted[$ty] ?? []))));
        $ids = array_values(array_filter($ids, static fn(int $i): bool => $i > 0));
        if ($ids === []) { continue; }
        $wheres[] = '(post_type=? AND post_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
        $args[] = $ty;
        foreach ($ids as $i) { $args[] = $i; }
    }
    if ($wheres === []) { return []; }
    $sql = 'SELECT post_type, post_id, kind, COUNT(*) n, SUM(user_id=?) mine FROM ' . Db::t('forum_reactions')
        . ' WHERE ' . implode(' OR ', $wheres) . ' GROUP BY post_type, post_id, kind';
    foreach (Db::rows($sql, array_merge([$uid], $args)) as $r) {
        $out[(string)$r['post_type']][(int)$r['post_id']][(string)$r['kind']] = ['n' => (int)$r['n'], 'mine' => (int)$r['mine'] > 0];
    }
    return $out;
}

/** 点赞条：登录=可点表单（AJAX 渐进增强）；游客=去登录的链接 */
function yf_react_form(string $type, int $id, int $tid, array $counts = []): string
{
    $kinds = yf_react_kinds();
    $rel = $type . ':' . $id;
    if (!\Auth::check()) {
        $login = ui_login((string)($_SERVER['REQUEST_URI'] ?? yf_url(['t' => $tid])));
        $h = '<span class="yf-reacts">';
        foreach ($kinds as $k => $emo) {
            $n = (int)($counts[$k]['n'] ?? 0);
            $h .= '<a class="yf-react" href="' . e($login) . '" data-rel="' . e($rel) . '" data-kind="' . e($k) . '" title="' . e(yf_t('登录后即可点赞', 'Log in to react')) . '">' . $emo . ' <b>' . $n . '</b></a>';
        }
        return $h . '</span>';
    }
    $h = '<form class="yf-reacts" method="post" action="' . e(yf_url(['t' => $tid])) . '" data-react="1">' . \Csrf::field()
        . '<input type="hidden" name="action" value="react"><input type="hidden" name="post_type" value="' . e($type) . '">'
        . '<input type="hidden" name="post_id" value="' . $id . '"><input type="hidden" name="thread_id" value="' . $tid . '"><input type="hidden" name="ajax" value="1">';
    foreach ($kinds as $k => $emo) {
        $n = (int)($counts[$k]['n'] ?? 0);
        $mine = !empty($counts[$k]['mine']);
        $h .= '<button type="submit" class="yf-react' . ($mine ? ' on' : '') . '" data-rel="' . e($rel) . '" data-kind="' . e($k) . '" name="kind" value="' . e($k) . '" aria-pressed="' . ($mine ? 'true' : 'false') . '" title="' . e(yf_t('点赞/取消', 'Like / unlike')) . '">'
            . $emo . ' <b>' . $n . '</b></button>';
    }
    return $h . '</form>';
}

/** 发一条站内通知（复用 ye_notifications；表结构加了 link 列） */
function yf_notify(int $uid, string $type, string $body, string $link): void
{
    if ($uid <= 0) { return; }
    $base = ['user_id' => $uid, 'type' => mb_substr($type, 0, 16), 'comment_id' => null, 'content_id' => 0,
        'body' => mb_substr($body, 0, 500), 'is_read' => 0];
    try {
        Db::insert('notifications', $base + ['link' => mb_substr($link, 0, 255)]);
    } catch (Throwable) {
        Db::insert('notifications', $base);
    }
}

/* ---------- 草稿 ---------- */
function yf_draft_get(string $kind, int $threadId = 0): ?array
{
    if (!\Auth::check()) { return null; }
    return Db::row('SELECT * FROM ' . Db::t('forum_drafts') . ' WHERE user_id=? AND kind=? AND thread_id=? LIMIT 1',
        [(int)\Auth::id(), $kind, $threadId]) ?: null;
}

function yf_draft_delete(string $kind, int $threadId = 0): void
{
    if (!\Auth::check()) { return; }
    Db::run('DELETE FROM ' . Db::t('forum_drafts') . ' WHERE user_id=? AND kind=? AND thread_id=?',
        [(int)\Auth::id(), $kind, $threadId]);
}

function yf_draft_save(string $kind, int $threadId, int $boardId, string $title, string $content): bool
{
    if (!\Auth::check()) { return false; }
    $kind = $kind === 'reply' ? 'reply' : 'thread';
    if ($kind === 'reply' && $threadId <= 0) { return false; }
    $title = mb_substr(trim($title), 0, 200);
    $content = mb_substr((string)$content, 0, 60000);
    if ($title === '' && mb_strlen(trim($content)) < 2) { return false; }
    $T = Db::t('forum_drafts');
    Db::run('INSERT INTO ' . $T . ' (user_id,kind,thread_id,board_id,title,content,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())'
        . ' ON DUPLICATE KEY UPDATE board_id=VALUES(board_id), title=VALUES(title), content=VALUES(content), updated_at=NOW()',
        [(int)\Auth::id(), $kind, $threadId, $boardId, $title, $content]);
    return true;
}

/** 草稿提示条：可恢复 / 丢弃 */
function yf_draft_bar(array $d, string $resumeUrl, string $backUrl): string
{
    return '<div class="yf-draftbar"><span>📝 ' . e(yf_t('发现未发布的草稿', 'Unpublished draft found')) . ' · ' . e(yf_ago((string)$d['updated_at'])) . '</span>'
        . '<a href="' . e($resumeUrl) . '">' . e(yf_t('恢复草稿', 'Restore')) . '</a>'
        . '<form method="post" action="' . e($backUrl) . '" onsubmit="return confirm(\'' . e(yf_t('丢弃这份草稿？', 'Discard this draft?')) . '\')">' . \Csrf::field()
        . '<input type="hidden" name="action" value="draft_del"><input type="hidden" name="kind" value="' . e((string)$d['kind']) . '">'
        . '<input type="hidden" name="thread_id" value="' . (int)$d['thread_id'] . '"><button type="submit">' . e(yf_t('丢弃', 'Discard')) . '</button></form></div>';
}

/* ---------- 未读 / 已读 ---------- */
/** @return array{ids:array<int,int>,count:int,boards:array<int,int>} */
function yf_unread(bool $refresh = false): array
{
    static $c = null;
    if ($c !== null && !$refresh) { return $c; }
    $c = ['ids' => [], 'count' => 0, 'boards' => []];
    if (!\Auth::check()) { return $c; }
    // 主题页：未读总数/分版块数已经在侧栏 stats 那条 SQL 里算好，不再单独发一条
    if (!empty($GLOBALS['YF_ON_THREAD']) && function_exists('yf_stats_row')) {
        $s = yf_stats_row();
        $c['count'] = (int)($s['unread_total'] ?? 0);
        $GLOBALS['YC_UNREAD_NOTIF'] = (int)($s['nc'] ?? 0);
        if (!empty($s['unread_boards'])) {
            foreach (explode(',', (string)$s['unread_boards']) as $pair) {
                $p = explode(':', $pair);
                if (count($p) === 2) { $c['boards'][(int)$p[0]] = (int)$p[1]; }
            }
        }
        return $c;
    }
    $rows = Db::rows('SELECT t.id, t.board_id FROM ' . Db::t('forum_threads') . ' t LEFT JOIN ' . Db::t('forum_reads') . ' r'
        . ' ON r.thread_id=t.id AND r.user_id=? WHERE t.status=\'normal\' AND (r.user_id IS NULL OR t.last_at > r.read_at)', [(int)\Auth::id()]);
    foreach ($rows as $r) {
        $c['ids'][(int)$r['id']] = 1;
        $c['count']++;
        $bid = (int)$r['board_id'];
        $c['boards'][$bid] = ($c['boards'][$bid] ?? 0) + 1;
    }
    return $c;
}

/** 进入主题即标记已读（一条语句：INSERT ... SELECT ... ON DUPLICATE） */
function yf_mark_read(int $tid): void
{
    if (!\Auth::check()) { return; }
    Db::run('INSERT INTO ' . Db::t('forum_reads') . ' (user_id,thread_id,last_read_post_id,read_at)'
        . ' SELECT ?,?,COALESCE(MAX(r.id),0),NOW() FROM ' . Db::t('forum_replies') . ' r WHERE r.thread_id=? AND r.status=\'normal\''
        . ' ON DUPLICATE KEY UPDATE last_read_post_id=VALUES(last_read_post_id), read_at=NOW()',
        [(int)\Auth::id(), $tid, $tid]);
}

function yf_mark_all_read(): void
{
    if (!\Auth::check()) { return; }
    $T = Db::t('forum_threads');
    $R = Db::t('forum_replies');
    Db::run('INSERT INTO ' . Db::t('forum_reads') . ' (user_id,thread_id,last_read_post_id,read_at)'
        . ' SELECT ?, t.id, COALESCE((SELECT MAX(r.id) FROM ' . $R . ' r WHERE r.thread_id=t.id AND r.status=\'normal\'),0), NOW()'
        . ' FROM ' . $T . ' t WHERE t.status=\'normal\''
        . ' ON DUPLICATE KEY UPDATE last_read_post_id=VALUES(last_read_post_id), read_at=NOW()',
        [(int)\Auth::id()]);
    yf_unread(true);
}

/* ---------- 前端脚本（点赞 AJAX / 分享复制 / 草稿自动保存） ---------- */
function yf_js(): string
{
    return <<<'HTML'
<script>(function(){
var toast=function(m){var t=document.getElementById('yf-toast');if(!t){t=document.createElement('div');t.id='yf-toast';t.className='yf-toast';document.body.appendChild(t);}t.textContent=m;t.className='yf-toast on';clearTimeout(t._h);t._h=setTimeout(function(){t.className='yf-toast';},1900);};
document.addEventListener('click',function(ev){
  var b=ev.target.closest('[data-share]');if(!b)return;ev.preventDefault();
  var u=b.getAttribute('data-share'),ok=function(){toast('链接已复制：'+u);};
  if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(u).then(ok,function(){toast('请手动复制链接');window.prompt('复制链接',u);});}
  else{var i=document.createElement('textarea');i.value=u;i.style.position='fixed';i.style.left='-9999px';document.body.appendChild(i);i.select();try{document.execCommand('copy');ok();}catch(e){toast('请手动复制链接');window.prompt('复制链接',u);}i.remove();}
});
document.addEventListener('submit',function(ev){
  var f=ev.target;if(!f||!f.hasAttribute('data-react'))return;ev.preventDefault();
  var bt=ev.submitter,fd=new FormData(f);if(bt&&bt.name){fd.set(bt.name,bt.value);}
  fetch(f.getAttribute('action'),{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'fetch'}})
   .then(function(r){return r.json();}).then(function(d){
     if(!d){return;}if(!d.ok){if(d.login&&d.url){location.href=d.url;}else{toast(d.error||'操作失败');}return;}
     var rel=d.post_type+':'+d.post_id;
     document.querySelectorAll('[data-rel="'+rel+'"][data-kind="'+d.kind+'"]').forEach(function(el){
       el.classList.toggle('on',!!d.mine);el.setAttribute('aria-pressed',d.mine?'true':'false');
       var n=el.querySelector('b');if(n){n.textContent=d.count;}
     });
   }).catch(function(){toast('网络错误，请重试');});
});
(function(){
  var f=document.querySelector('form[data-yf-draft]');if(!f)return;
  var kind=f.getAttribute('data-yf-draft'),tid=f.getAttribute('data-thread')||'0';
  var ta=f.querySelector('textarea[name=content]'),ti=f.querySelector('input[name=title]');
  var key='yf-draft:'+kind+':'+tid,last='',timer=null,saving=false,submitted=false;
  function snap(){return JSON.stringify([ti?ti.value:'',ta?ta.value:'']);}
  function local(on){try{if(on){localStorage.setItem(key,snap());}else{localStorage.removeItem(key);}}catch(e){}}
  function state(s){var el=document.getElementById('yf-draft-state');if(el){el.textContent=s;}}
  function save(){
    if(saving||submitted)return;var v=snap();if(v===last)return;
    var fd=new FormData(f);fd.set('action','draft_save');fd.set('ajax','1');fd.set('kind',kind);fd.set('thread_id',tid);
    saving=true;
    fetch(f.getAttribute('action'),{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'fetch'}})
      .then(function(r){return r.json();}).then(function(d){if(d&&d.ok){last=v;state('草稿已保存 '+d.saved_at);}})
      .catch(function(){}).then(function(){saving=false;});
  }
  function changed(){local(true);clearTimeout(timer);timer=setTimeout(save,2500);}
  if(ta){ta.addEventListener('input',changed);}
  if(ti){ti.addEventListener('input',changed);}
  f.addEventListener('submit',function(){submitted=true;clearTimeout(timer);last=snap();local(false);});
  window.addEventListener('pagehide',function(){if(submitted)return;if(snap()!==last){local(true);try{var fd=new FormData(f);fd.set('action','draft_save');fd.set('ajax','1');fd.set('kind',kind);fd.set('thread_id',tid);fetch(f.getAttribute('action'),{method:'POST',body:fd,credentials:'same-origin',keepalive:true,headers:{'X-Requested-With':'fetch'}});}catch(e){}}});
})();
})();</script>
HTML;
}

/** 论坛页公用的样式（v2：linux.sb 式两栏 + 统计侧栏） */
function yf_css(): string
{
    /* ★ yf-restyle-20261020：整表重写，与主站同宽同色系（布局参考 linux.sb）。
       · 所有颜色走 CSS 变量 ⇒ 主站的深色开关一开，论坛自动跟着变（原来手写了 15 行 html.dark）
       · 容器 1280px = 主站 .container；圆角 12px = rounded-xl
       · 字号 12.5~15px（linux.sb 的紧凑密度）
       · .yf-top 改成「一行板块导航」，不再是一张带品牌名的卡片（主站页头已有 logo） */
    $p    = 'hsl(var(--primary))';                 // 靛蓝 #4f46e5
    $pfg  = 'hsl(var(--primary-foreground,0 0% 100%))';
    $fg   = 'hsl(var(--foreground))';
    $mut  = 'hsl(var(--muted-foreground))';
    $bd   = 'hsl(var(--border))';
    $card = 'hsl(var(--card))';
    $mu   = 'hsl(var(--muted))';

    return '<style>'
        /* ── 骨架 ── */
        . '.yf-forum{max-width:1280px;margin:0 auto;font-family:inherit;color:' . $fg . '}'
        /* ★ yf-3col-css-20261020：三栏（左导航 | 帖子 | 热榜），全在 1280px 内 */
        . '.yf-layout{display:grid;grid-template-columns:176px minmax(0,1fr) 288px;gap:20px;align-items:start}'
        . '.yf-left{min-width:0;position:sticky;top:calc(var(--nav-h,64px) + 14px)}'
        . '.yf-lnav{border:1px solid ' . $bd . ';border-radius:12px;background:' . $card . ';overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,.04)}'
        . '.yf-lnav-hd{padding:9px 12px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:' . $mut . ';border-bottom:1px solid ' . $bd . '}'
        . '.yf-lnav ul{list-style:none;margin:0;padding:5px}'
        . '.yf-lnav li a{display:flex;align-items:center;gap:7px;padding:7px 8px;border-radius:7px;font-size:13px;color:' . $fg . ';text-decoration:none;line-height:1.3}'
        . '.yf-lnav li a:hover{background:' . $mu . '}'
        . '.yf-lnav li a.on{background:' . $p . ';color:' . $pfg . ';font-weight:600}'
        . '.yf-navdot{flex:none;width:6px;height:6px;border-radius:50%;background:var(--c,hsl(var(--primary)))}'
        . '.yf-lnav li a.on .yf-navdot{background:rgba(255,255,255,.9)}'
        . '.yf-navname{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
        . '.yf-navcnt{flex:none;font-size:11px;color:' . $mut . ';font-variant-numeric:tabular-nums}'
        . '.yf-lnav li a.on .yf-navcnt{color:rgba(255,255,255,.78)}'
        . '.yf-lnav li a.on .yf-dotr{background:rgba(255,255,255,.92);color:' . $p . '}'
        /* ★ yf-community-css-20261020：左栏分组色块 + 发帖之星 */
        . '.yf-lgroup{margin:8px 5px 3px;padding:4px 8px;border-radius:6px;background:' . $mu . '}'
        . '.yf-lgroup-t{font-size:11px;font-weight:600;letter-spacing:.04em;color:' . $mut . '}'
        . '.yf-star{border:1px solid ' . $bd . ';border-radius:12px;background:' . $card . ';padding:11px 12px;margin-top:12px;box-shadow:0 1px 2px rgba(15,23,42,.04)}'
        . '.yf-star h4{margin:0 0 8px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:' . $mut . ';border-left:3px solid #f59e0b;padding-left:8px;font-weight:600}'
        . '.yf-star ol{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:1px}'
        . '.yf-star li{display:flex;align-items:center;gap:6px;font-size:12.5px;padding:3px 0;min-width:0}'
        . '.yf-star-i{flex:none;width:16px;height:16px;line-height:16px;text-align:center;border-radius:4px;background:' . $mu . ';color:' . $mut . ';font-size:10.5px;font-weight:700}'
        . '.yf-star-i.top{background:#fef3c7;color:#b45309}'
        . '.yf-star li a{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:' . $fg . ';text-decoration:none}'
        . '.yf-star li a:hover{color:' . $p . '}'
        . '.yf-star li b{flex:none;font-size:11px;color:' . $mut . ';font-variant-numeric:tabular-nums;font-weight:600}'
        . '.yf-star-empty{font-size:12px;color:' . $mut . ';display:block;text-align:center;padding:8px 0}'
        . '.yf-lnav-new{display:block;margin:8px 5px 6px;padding:7px;text-align:center;font-size:12.5px;font-weight:600;border-radius:7px;background:' . $mu . ';color:' . $p . ';text-decoration:none}'
        . '.yf-lnav-new:hover{background:' . $p . ';color:' . $pfg . '}'
        . '.yf-main{min-width:0}'
        . '.yf-side{display:flex;flex-direction:column;gap:14px;position:sticky;top:calc(var(--nav-h,64px) + 14px)}'

        /* ── 顶部：一行板块导航（不是卡片） ── */
        /* ★ 板块已挪到左栏，顶栏只剩搜索+登录 ⇒ 右对齐，像页面右上角工具条 */
        . '.yf-top{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap;padding:0;margin:-34px 0 14px}'
        . '.yf-top .yf-brand{display:none}'
        . '.yf-top .yf-navrow{display:flex;align-items:center;gap:2px;flex-wrap:wrap;flex:1;min-width:0}'
        . '.yf-top .yf-navrow a{padding:6px 11px;border-radius:8px;font-size:13px;color:' . $mut . ';text-decoration:none;white-space:nowrap}'
        . '.yf-top .yf-navrow a:hover{background:' . $mu . ';color:' . $fg . '}'
        . '.yf-top .yf-navrow a.on{background:' . $p . ';color:' . $pfg . ';font-weight:600}'
        /* ★ yf-nobar-20261020：通栏顶栏拆进右栏 —— 搜索卡 + 用户卡样式 */
        . '.yf-searchbox{padding:10px}'
        . '.yf-searchbox form{display:flex;gap:6px;margin:0}'
        . '.yf-searchbox input[type=search]{flex:1;min-width:0;width:100%;border:1px solid ' . $bd . ';border-radius:8px;padding:7px 10px;font:inherit;font-size:13px;background:' . $card . ';color:' . $fg . ';box-sizing:border-box}'
        . '.yf-searchbox input[type=search]:focus{outline:0;border-color:' . $p . '}'
        . '.yf-searchbox .yf-sbtn{padding:7px 10px;font-size:13px;flex:none}'
        . '.yf-ucard .yf-uhd{display:flex;gap:9px;align-items:center}'
        . '.yf-ucard .yf-uname{font-weight:700;color:inherit;text-decoration:none}'
        . '.yf-ucard .yf-uname:hover{color:' . $p . '}'
        . '.yf-ucard .yf-usub{font-size:11.5px;color:' . $mut . '}'
        . '.yf-ucard .yf-ulinks{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;font-size:12.5px}'
        . '.yf-ucard .yf-ulinks a{color:' . $p . ';text-decoration:none}'
        . '.yf-ucard .yf-ulinks a:hover{text-decoration:underline}'
        . '.yf-ucard .yf-uform{margin:8px 0 0}'
        . '.yf-ucard .yf-ubtn{margin-top:9px}'
        . '.yf-side>.yf-w:first-child{margin-top:0}'
        . '.yf-mobtop{display:none}'
        . '@media(max-width:760px){.yf-mobtop{display:block;margin:0 0 12px}.yf-side .yf-searchbox,.yf-side .yf-ucard{display:none}}'
        . '.yf-top form{flex:none;display:flex;gap:6px}'
        . '.yf-top input[type=search]{width:200px;border:1px solid ' . $bd . ';border-radius:8px;padding:6px 11px;font:inherit;font-size:13px;background:' . $card . ';color:' . $fg . '}'
        . '.yf-top input[type=search]:focus{outline:0;border-color:' . $p . '}'
        . '.yf-top .yf-sbtn{display:none}'
        . '.yf-top .yf-u{display:flex;align-items:center;gap:8px;font-size:13px;color:' . $mut . '}'
        . '.yf-top .yf-u a{color:' . $mut . ';text-decoration:none}'
        /* ★ 修：`.yf-top .yf-u a`（0,2,1）盖掉了 `.yf-btn`（0,1,0）的文字色 ⇒
           顶部「+ 发帖」变成灰蓝字压在靛蓝底上，对比度不够、看不清。
           这里用同等特异性的类链把按钮文字拉回主色前景。 */
        . '.yf-top .yf-u a.yf-btn,.yf-top .yf-u a.yf-btn:hover,.yf-top .yf-u .yf-btn,.yf-top .yf-u .yf-btn.sm{color:' . $pfg . '}'
        . '.yf-top .yf-u a:hover{color:' . $p . '}'

        /* ── 侧栏块 ── */
        . '.yf-w{border:1px solid ' . $bd . ';background:' . $card . ';border-radius:12px;padding:12px 14px}'
        . '.yf-w h4{margin:0 0 8px;font-size:12.5px;letter-spacing:.02em;color:' . $mut . ';border-left:3px solid ' . $p . ';padding-left:8px;font-weight:600}'
        . '.yf-w ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:1px}'
        . '.yf-w li a{display:flex;align-items:baseline;gap:6px;text-decoration:none;color:' . $fg . ';font-size:13px;padding:6px;border-radius:8px;min-width:0}'
        . '.yf-w li a:hover{background:' . $mu . '}'
        . '.yf-w li a .n{margin-left:auto;color:' . $mut . ';font-size:11.5px;flex:none;font-variant-numeric:tabular-nums}'
        . '.yf-w li a.on{background:' . $p . ';color:' . $pfg . ';font-weight:600}'
        . '.yf-w li a.on .n{color:rgba(255,255,255,.78)}'
        . '.yf-w li a span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'

        /* ── 页头区 ── */
        . '.yf-head h1{font-size:24px;margin:2px 0 6px;font-weight:700;letter-spacing:-.01em}'
        . '.yf-sub{color:' . $mut . ';font-size:13px;line-height:1.75;margin:0 0 12px}'
        . '.yf-crumbs{font-size:12.5px;color:' . $mut . ';margin:0 0 6px}'
        . '.yf-crumbs a{color:inherit;text-decoration:none}.yf-crumbs a:hover{color:' . $p . '}'
        . '.yf-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:8px 0 12px}'
        /* 中间区排序工具栏（照 linux.sb 的 topic-toolbar） */
        . '.yf-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:4px 0 8px;padding-bottom:8px;border-bottom:1px solid ' . $bd . '}'
        . '.yf-toolbar .yf-tabs{border-bottom:0;margin:0;flex:1;min-width:0}'
        . '.yf-toolbar .yf-tabs a{padding:5px 11px;border-radius:7px;border-bottom:0;margin:0}'
        . '.yf-toolbar .yf-tabs a.on{background:' . $mu . ';color:' . $p . ';border-bottom:0}'
        . '.yf-toolbar-n{flex:none;font-size:12px;color:' . $mut . '}'

        /* ── tabs / 按钮 ── */
        . '.yf-tabs{display:flex;gap:2px;border-bottom:1px solid ' . $bd . ';margin:0 0 4px;flex-wrap:wrap}'
        . '.yf-tabs a{padding:7px 12px;font-size:13px;color:' . $mut . ';text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px}'
        . '.yf-tabs a.on{color:' . $p . ';border-bottom-color:' . $p . ';font-weight:600}'
        . '.yf-tabs a:hover{color:' . $p . '}'
        . '.yf-btn,.yf-btn2{display:inline-flex;align-items:center;gap:6px;border-radius:8px;padding:7px 15px;font-size:13px;text-decoration:none;font-weight:500;cursor:pointer;transition:background .15s,border-color .15s}'
        . '.yf-btn{background:' . $p . ';color:' . $pfg . ';border:1px solid ' . $p . '}'
        . '.yf-btn:hover{filter:brightness(.94)}'
        . '.yf-btn2{border:1px solid ' . $bd . ';color:' . $fg . ';background:' . $card . '}'
        . '.yf-btn2:hover{border-color:' . $p . ';color:' . $p . '}'
        . '.yf-btn.sm,.yf-btn2.sm{padding:4px 11px;font-size:12px}'

        /* ── 板块列表（行式，参考 linux.sb） ── */
        . '.yf-blist{display:flex;flex-direction:column;border:1px solid ' . $bd . ';border-radius:12px;overflow:hidden;background:' . $card . '}'
        . '.yf-brow{display:flex;align-items:center;gap:14px;padding:13px 16px;border-bottom:1px solid ' . $bd . ';text-decoration:none;color:inherit}'
        . '.yf-brow:last-child{border-bottom:0}'
        . '.yf-brow:hover{background:' . $mu . '}'
        . '.yf-brow .bb{flex:1 1 auto;min-width:0;display:flex;align-items:center;gap:11px;text-decoration:none;color:inherit}'
        . '.yf-brow .bb>div{min-width:0}'
        . '.yf-brow .bb b{font-size:14.5px;color:' . $fg . ';display:flex;align-items:center;gap:8px;font-weight:600}'
        . '.yf-brow .bb p{margin:3px 0 0;color:' . $mut . ';font-size:12.5px;line-height:1.6;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
        . '.yf-brow .bc{flex:none;width:58px;text-align:center;font-size:11px;color:' . $mut . '}'
        . '.yf-brow .bc b{display:block;font-size:14.5px;color:' . $fg . ';font-variant-numeric:tabular-nums;line-height:1.2;font-weight:600}'
        . '.yf-brow .bl{flex:none;width:172px;text-align:right;font-size:11.5px;color:' . $mut . ';line-height:1.55;overflow:hidden}'
        . '.yf-brow .bl b{color:' . $fg . ';font-weight:500}'
        . '.yf-brow .bl a{color:' . $fg . ';text-decoration:none;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px}'
        . '.yf-brow .bl a:hover{color:' . $p . '}'
        . '.yf-ico{flex:none;width:34px;height:34px;border-radius:9px;background:' . $mu . ';color:' . $p . ';display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px}'

        /* ── 帖子列表 ── */
        . '.yf-tlist{list-style:none;margin:0;padding:0}'
        . '.yf-thread{display:flex;gap:11px;align-items:flex-start;padding:11px 4px;border-top:1px solid ' . $bd . '}'
        . '.yf-thread:first-child{border-top:0}'
        . '.yf-thread .tc{flex:1;min-width:0}'
        . '.yf-thread .tt{display:flex;align-items:baseline;gap:7px;flex-wrap:wrap}'
        . '.yf-thread .tt a{color:' . $fg . ';text-decoration:none;font-size:14.5px;line-height:1.5;font-weight:600;overflow-wrap:anywhere}'
        . '.yf-thread .tt a:hover{color:' . $p . '}'
        . '.yf-thread .tm{display:flex;align-items:center;gap:6px;color:' . $mut . ';font-size:12px;margin-top:3px;flex-wrap:wrap}'
        . '.yf-thread .tm .bchip{background:' . $mu . ';color:' . $mut . ';border-radius:999px;padding:0 8px;font-size:11px}'
        . '.yf-thread .cnt{flex:none;display:flex;gap:14px;color:' . $mut . ';font-size:11px;text-align:center}'
        . '.yf-thread .cnt b{display:block;font-size:14px;color:' . $fg . ';font-variant-numeric:tabular-nums;line-height:1.15;font-weight:600}'

        /* ── 标签 / 分区标题 ── */
        . '.yf-tag{display:inline-block;border-radius:4px;font-size:10.5px;padding:0 5px;line-height:1.6;vertical-align:1px;font-weight:600}'
        . '.yf-tag.pin{background:' . $p . ';color:' . $pfg . '}'
        . '.yf-tag.good{background:#f59e0b;color:#fff}'
        . '.yf-tag.lock{background:' . $mu . ';color:' . $mut . '}'
        . '.yf-sec{display:flex;align-items:baseline;gap:10px;margin:22px 0 8px}'
        . '.yf-sec h2{font-size:15.5px;margin:0;font-weight:600}'
        . '.yf-sec .en2{font-size:10.5px;letter-spacing:.16em;text-transform:uppercase;color:' . $mut . '}'
        . '.yf-sec::after{content:"";flex:1;height:1px;background:' . $bd . '}'
        . '.yf-sec a.more{margin-left:auto;font-size:12.5px;color:' . $p . ';text-decoration:none}'

        /* ── 侧栏统计 ── */
        . '.yf-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;text-align:center}'
        . '.yf-stats b{display:block;font-size:16px;color:' . $fg . ';line-height:1.25;font-weight:600;font-variant-numeric:tabular-nums}'
        . '.yf-stats span{font-size:11px;color:' . $mut . '}'
        . '.yf-sidebox p{margin:5px 0;color:' . $mut . ';font-size:12.5px;line-height:1.7}'
        . '.yf-sidebox .yf-btn{width:100%;justify-content:center;box-sizing:border-box}'
        . '.yf-online{display:flex;align-items:center;gap:6px;font-size:12.5px;color:' . $mut . ';margin-top:6px}'
        . '.yf-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;display:inline-block}'

        /* ── 每日热榜（照 linux.sb：三 tab 等宽、标题 2 行截断、小字「近 N 小时 M 回复」）── */
        . '.yf-hot{display:flex;flex-direction:column;gap:8px}'
        . '.yf-hot h4{margin:0}'
        . '.yf-hot-tabs{display:flex;gap:2px;border:1px solid ' . $bd . ';border-radius:8px;padding:2px;background:' . $mu . '}'
        . '.yf-hot-tabs a{flex:1 1 0;min-width:0;text-align:center;padding:4px 0;font-size:12px;color:' . $mut . ';text-decoration:none;border-radius:6px}'
        . '.yf-hot-tabs a.on{background:' . $card . ';color:' . $fg . ';font-weight:600;box-shadow:0 1px 2px rgba(15,23,42,.08)}'
        . '.yf-hot-note{margin:6px 0 2px;font-size:11px;line-height:1.5;color:' . $mut . '}'
        . '.yf-hot-list{list-style:none;margin:0;padding:0}'
        . '.yf-hot-list li{border-top:1px solid ' . $bd . '}'
        . '.yf-hot-list li:first-child{border-top:0}'
        . '.yf-hot-list li a{display:block;padding:7px 0;text-decoration:none}'
        . '.yf-hot-t{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:12.5px;line-height:1.45;color:' . $fg . '}'
        . '.yf-hot-list li a:hover .yf-hot-t{color:' . $p . '}'
        . '.yf-hot-c{display:block;margin-top:2px;font-size:10.5px;color:' . $mut . ';white-space:nowrap}'
        . '.yf-hot-empty{padding:14px 0;text-align:center;font-size:12px;color:' . $mut . ';border-top:0}'

        /* ── 分页 ── */
        . '.yf-pg{display:flex;gap:5px;flex-wrap:wrap;margin:16px 0 4px}'
        . '.yf-pg a{border:1px solid ' . $bd . ';color:' . $fg . ';text-decoration:none;border-radius:8px;min-width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;font-size:12.5px;padding:0 7px}'
        . '.yf-pg a.on{background:' . $p . ';border-color:' . $p . ';color:' . $pfg . '}'
        . '.yf-pg a:hover{border-color:' . $p . '}'
        . '.yf-empty{color:' . $mut . ';padding:28px 4px;text-align:center;font-size:13px}'

        /* ── 帖子详情 ── */
        . '.yf-ttl{font-size:21px;line-height:1.4;margin:6px 0 8px;font-weight:700;color:' . $fg . ';overflow-wrap:anywhere}'
        . '.yf-thmeta{display:flex;gap:10px;flex-wrap:wrap;color:' . $mut . ';font-size:12.5px;margin:0 0 14px;align-items:center}'
        . '.yf-thmeta .bchip{background:' . $mu . ';color:' . $mut . ';border-radius:999px;padding:1px 10px;text-decoration:none}'
        . '.yf-floor-card{border:1px solid ' . $bd . ';border-radius:12px;background:' . $card . ';margin:0 0 12px;overflow:hidden}'
        . '.yf-fhead{display:flex;align-items:center;gap:9px;flex-wrap:wrap;padding:9px 14px;background:' . $mu . ';border-bottom:1px solid ' . $bd . '}'
        . '.yf-fhead .fn{color:' . $mut . ';font-weight:700;flex:none;font-size:12px}'
        . '.yf-fhead .fname{color:' . $fg . ';font-weight:600;font-size:13px}'
        . '.yf-fhead .fx{margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap}'
        . '.yf-fhead .fx a,.yf-fhead .fx button{border:0;background:none;color:' . $mut . ';font:inherit;font-size:12px;cursor:pointer;padding:0}'
        . '.yf-fhead .fx a:hover,.yf-fhead .fx button:hover{color:' . $p . '}'
        . '.yf-fbody{padding:13px 16px 16px;color:' . $fg . ';font-size:14.5px;line-height:1.9;overflow-wrap:anywhere}'
        . '.yf-fbody img{max-width:100%;height:auto;border-radius:8px;margin:6px 0}'
        . '.yf-fbody pre{background:' . $mu . ';border:1px solid ' . $bd . ';border-radius:8px;padding:10px 12px;overflow:auto;font-size:13px}'
        . '.yf-fbody code{background:' . $mu . ';border-radius:4px;padding:1px 5px;font-size:13px}'
        . '.yf-fbody pre code{background:none;padding:0}'
        . '.yf-fbody blockquote{border-left:3px solid ' . $bd . ';margin:8px 0;padding:2px 12px;color:' . $mut . '}'
        . '.yf-fbody h1,.yf-fbody h2,.yf-fbody h3{font-weight:600;line-height:1.45;margin:16px 0 8px}'
        . '.yf-fbody h2{font-size:17px}.yf-fbody h3{font-size:15px}'
        . '.yf-fbody ul,.yf-fbody ol{padding-left:22px;margin:8px 0}'
        . '.yf-fbody li{margin:3px 0}'
        . '.yf-fbody a{color:' . $p . '}'
        . '.yf-quote{border:1px solid ' . $bd . ';background:' . $mu . ';border-radius:10px;padding:8px 12px;margin:0 0 10px;font-size:12.5px}'
        . '.yf-quote b{color:' . $p . '}.yf-quote .qp{display:block;color:' . $mut . ';margin-top:2px}'

        /* ── 表单 ── */
        . '.yf-form{border:1px solid ' . $bd . ';background:' . $card . ';border-radius:12px;padding:16px 18px;margin-top:10px}'
        . '.yf-form h2,.yf-form h3{margin:0 0 8px;font-size:16px;font-weight:600}'
        . '.yf-form label{display:block;font-size:12.5px;color:' . $mut . ';margin:10px 0 4px;font-weight:500}'
        . '.yf-form input[type=text],.yf-form textarea,.yf-form select{width:100%;box-sizing:border-box;border:1px solid ' . $bd . ';border-radius:8px;padding:9px 11px;font:inherit;font-size:13.5px;background:' . $card . ';color:' . $fg . '}'
        . '.yf-form input:focus,.yf-form textarea:focus,.yf-form select:focus{outline:0;border-color:' . $p . '}'
        . '.yf-form textarea{min-height:190px;line-height:1.8;resize:vertical}'
        /* ★ yf-tb-css-20261020：编辑器格式工具栏 */
        . '.yf-tb{display:flex;align-items:center;gap:3px;flex-wrap:wrap;margin:6px 0 0;padding:5px 6px;border:1px solid ' . $bd . ';border-bottom:0;border-radius:8px 8px 0 0;background:' . $mu . '}'
        . '.yf-tb + textarea{border-radius:0 0 8px 8px !important;margin-top:0 !important}'
        . '.yf-tb-b{min-width:28px;height:26px;padding:0 7px;border:1px solid transparent;border-radius:6px;background:transparent;color:' . $fg . ';font-size:13px;line-height:1;cursor:pointer;transition:background .12s,border-color .12s}'
        . '.yf-tb-b:hover{background:' . $card . ';border-color:' . $bd . '}'
        . '.yf-tb-b:active{background:' . $p . ';color:' . $pfg . ';border-color:' . $p . '}'
        . '.yf-tb-tip{margin-left:auto;font-size:11px;color:' . $mut . ';white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
        . '@media(max-width:560px){.yf-tb-tip{display:none}}'
        . '.yf-form .hint{color:' . $mut . ';font-size:12px;margin-top:6px}'
        . '.yf-form .row{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:center}'
        . '.yf-pre{border:1px dashed ' . $bd . ';background:' . $mu . ';border-radius:10px;padding:12px 14px;margin-top:10px;font-size:14px}'
        . '.yf-pre .cap{font-size:11.5px;letter-spacing:.1em;color:' . $p . ';text-transform:uppercase;margin-bottom:6px}'
        . '.yf-err{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:8px;padding:9px 14px;font-size:13px;margin:0 0 10px}'
        . '.yf-msg{background:#f0fdf4;border:1px solid #bbf7d0;color:#16a34a;border-radius:8px;padding:9px 14px;font-size:13px;margin:0 0 10px}'

        /* ── 头像 / 资料 ── */
        . '.yf-ava{display:inline-flex;flex:none;text-decoration:none}'
        . '.yf-ava .ava{border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:' . $mu . ';color:' . $p . ';font-weight:700}'
        . '.ava-img{border-radius:50%;object-fit:cover;display:inline-block;flex:none;background:' . $mu . '}'
        . '.yf-prof{display:flex;gap:16px;align-items:center;border:1px solid ' . $bd . ';background:' . $card . ';border-radius:12px;padding:16px}'
        . '.yf-prof .pn{font-size:20px;font-weight:700}'
        . '.yf-prof .pm{color:' . $mut . ';font-size:12.5px;margin-top:4px;line-height:1.7}'
        . '.yf-kv{display:flex;gap:18px;margin-top:8px;flex-wrap:wrap;font-size:12.5px;color:' . $mut . '}'
        . '.yf-kv b{color:' . $fg . ';font-size:15px;margin-right:3px;font-weight:600}'
        . '.yf-mods{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}'
        . '.yf-mods form{display:inline}'

        /* ── 互动（点赞/分享/草稿/未读） ── */
        . '.yf-facts{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:9px 0 0;padding-top:9px;border-top:1px dashed ' . $bd . '}'
        . '.yf-reacts{display:inline-flex;gap:4px;align-items:center;flex-wrap:wrap;margin:0}'
        . '.yf-react{border:1px solid ' . $bd . ';background:' . $card . ';color:' . $mut . ';border-radius:999px;padding:2px 9px;font:inherit;font-size:12px;cursor:pointer}'
        . '.yf-react b{font-weight:600;font-variant-numeric:tabular-nums;font-size:11.5px;color:' . $mut . '}'
        . '.yf-react:hover{border-color:' . $p . ';color:' . $p . '}'
        . '.yf-react.on{background:' . $mu . ';border-color:' . $p . ';color:' . $p . '}'
        . '.yf-react.on b{color:' . $p . '}'
        . '.yf-share{border:0;background:none;color:' . $mut . ';font:inherit;font-size:12px;cursor:pointer;padding:0}'
        . '.yf-share:hover{color:' . $p . '}'
        . '.yf-toast{position:fixed;left:50%;bottom:34px;transform:translateX(-50%) translateY(12px);background:' . $fg . ';color:' . $card . ';padding:9px 18px;border-radius:8px;font-size:13px;opacity:0;pointer-events:none;transition:opacity .2s,transform .2s;z-index:99}'
        . '.yf-toast.on{opacity:.96;transform:translateX(-50%) translateY(0)}'
        . '.yf-floor-card:target,.yf-floor-card.yf-target{border-color:' . $p . ';box-shadow:0 0 0 3px hsl(var(--primary) / .12)}'
        . '.yf-thread.unread .tt a{font-weight:700}'
        . '.yf-thread.unread .tc .tt a::before{content:"";display:inline-block;width:6px;height:6px;border-radius:50%;background:' . $p . ';margin-right:6px;vertical-align:2px}'
        . '.yf-dotr{display:inline-block;min-width:16px;height:16px;line-height:16px;padding:0 4px;border-radius:999px;background:#ef4444;color:#fff;font-size:10.5px;text-align:center}'
        . '.yf-dotr.zero{display:none}'
        . '.yf-unreadbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12.5px;color:' . $mut . ';margin:0 0 10px}'
        . '.yf-unreadbar .yf-btn2{padding:3px 10px;font-size:12px}'
        . '.yf-draftbar{border:1px solid #fde68a;background:#fffbeb;color:#92400e;border-radius:8px;padding:8px 12px;margin:0 0 10px;font-size:12.5px}'
        . '.yf-draftbar a{color:' . $p . ';font-weight:600;text-decoration:none}'
        . '.yf-draftbar form{display:inline;margin:0}'
        . '.yf-draftbar button{border:0;background:none;color:' . $mut . ';font:inherit;font-size:12px;cursor:pointer}'
        . '.yf-draftstate{color:' . $mut . ';font-size:11.5px;margin-left:2px}'
        . '.yf-dlist{list-style:none;margin:0;padding:0}'
        . '.yf-dlist li{border:1px solid ' . $bd . ';background:' . $card . ';border-radius:10px;padding:12px 14px;margin:0 0 10px}'
        . '.yf-dlist .dt{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}'
        . '.yf-dlist .dt b{font-size:14px;color:' . $fg . '}'
        . '.yf-dlist .dm{color:' . $mut . ';font-size:12px;margin-top:3px}'
        . '.yf-dlist .dx{margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}'

        /* ── 响应式 ── */
        . '@media(max-width:1180px){.yf-layout{grid-template-columns:150px minmax(0,1fr) 260px;gap:16px}}'
        /* ★ yf-3col-20261020b：原来 1024 直接 `.yf-left{display:none}`、760 再降成单栏
           ⇒ 平板/窄窗口下「三栏」被砍成两栏、一栏。站主要求**三栏做全**：
             · ≥1180  标准三栏
             · 1024~1179 紧凑三栏（左栏保留，只是变窄）
             · ≤1023  更紧凑三栏
             · ≤760   左栏变成顶部横滑条（内容一个不删，只换形态）+ 主区 + 右栏 */
        . '@media(max-width:1180px){.yf-layout{grid-template-columns:150px minmax(0,1fr) 250px;gap:16px}'
        .   '.yf-lnav li a{font-size:12.5px;gap:6px;padding:6px 7px}}'
        . '@media(max-width:1024px){.yf-layout{grid-template-columns:132px minmax(0,1fr) 228px;gap:12px}'
        .   '.yf-lnav li a{font-size:12px;gap:5px;padding:6px 6px}'
        .   '.yf-lnav .yf-navcnt{font-size:10.5px}}'
        . '@media(max-width:900px){.yf-layout{grid-template-columns:124px minmax(0,1fr) 208px;gap:10px}'
        /* 124px 放不下「中文名 + 英文名 + 计数」⇒ 先收计数，再收英文名（见 yf-navname 的处理） */
        .   '.yf-lnav .yf-navcnt{display:none}'
        .   '.yf-lnav li a{font-size:12px;gap:6px;padding:6px 7px}'
        .   '.yf-lnav .yf-navname{overflow:hidden;text-overflow:ellipsis}}'
        . '@media(max-width:760px){'
        .   '.yf-layout{grid-template-columns:minmax(0,1fr)}'
        .   '.yf-left{order:-1;position:static;margin:0 0 10px}'
        .   '.yf-side{position:static;order:2;min-width:0}'
        /* 左栏横滑：分组标题 / 发新帖 / 发帖之星 收起，只留板块胶囊 */
        .   '.yf-lgroup-t,.yf-lnav-new,.yf-star{display:none}'
        .   '.yf-lnav{border:0;background:transparent}'
        /* 左栏是「多个分组 ul」的结构 ⇒ 只让每个 ul 横滑会变成好几行。
           用 display:contents 把 ul 拆掉，li 直接排进 .yf-lnav 这一个横滑容器 ⇒ 真正一行。 */
        .   '.yf-lnav{display:flex;flex-wrap:nowrap;gap:6px;overflow-x:auto;padding:0 0 6px;'
        .     '-webkit-overflow-scrolling:touch;scrollbar-width:thin}'
        .   '.yf-lnav ul{display:contents}'
        .   '.yf-lnav li{flex:0 0 auto}'
        /* 主区底部留白：浮动「发新帖」不能压住最后一行内容 */
        .   '.yf-main{padding-bottom:64px}'
        .   '.yf-lnav li a{display:inline-flex;white-space:nowrap;border:1px solid ' . $bd . ';'
        .     'border-radius:999px;padding:5px 11px;background:' . $card . '}'
        . '}'
        . '@media(max-width:760px){.yf-brow{gap:9px}.yf-brow .bl{display:none}.yf-brow .bc{width:50px}.yf-top input[type=search]{width:140px}}'
        . '@media(max-width:560px){.yf-forum{padding:0 2px}.yf-top{gap:8px}.yf-top form{width:100%}.yf-top input[type=search]{width:100%}}'
        . '</style>';
}
