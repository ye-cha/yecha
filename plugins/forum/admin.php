<?php
declare(strict_types=1);
// 论坛插件 · 后台管理：板块增改 / 帖子与回帖隐藏与删除（软删）
require_once __DIR__ . '/lib.php';

function yc_forum_admin(): string
{
    if (!\Auth::isAdmin()) { return '<div class="card"><h3>社区</h3><div class="stub">需要管理员权限。</div></div>'; }
    $msg = yf_admin_handle(); // 处理后返回提示或 ''
    $css = '<style>'
        . '.yfa-card{border:1px solid #e5e0d5;background:#fff;border-radius:12px;padding:14px 16px;margin:10px 0}'
        . '.yfa-card h3{margin:0 0 8px;font-size:15.5px}.yfa table{width:100%;border-collapse:collapse;font-size:13px}'
        . '.yfa th,.yfa td{border-bottom:1px solid #eee7db;padding:7px 8px;text-align:left;vertical-align:top}'
        . '.yfa th{color:#6f767e;font-weight:600;font-size:12px}'
        . '.yfa input[type=text],.yfa textarea,.yfa select{box-sizing:border-box;border:1px solid #ded7ca;border-radius:7px;padding:5px 8px;font:inherit;width:100%}'
        . '.yfa .btn{padding:4px 12px;border-radius:999px;font-size:12.5px;border:1px solid #ded7ca;background:#fff;cursor:pointer;text-decoration:none;color:#333;display:inline-block}'
        . '.yfa .btn.primary{background:#B3541E;border-color:#B3541E;color:#fff}'
        . '.yfa .dim{color:#9a9a90}.yfa .tag{display:inline-block;border-radius:999px;padding:0 8px;font-size:11px;background:#f0ede4;color:#6f767e}'
        . '.yfa .row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}'
        . '.yfa .f{display:flex;flex-direction:column;gap:3px;font-size:12px;color:#6f767e}'
        . '.yfa form.inline{display:inline}'
        . '</style>';

    $msgHtml = $msg !== '' ? '<div style="background:#eef4ee;border:1px solid #cfe2d0;color:#2e6b34;border-radius:8px;padding:8px 12px;margin:8px 0;">' . e($msg) . '</div>' : '';

    $B = Db::t('forum_boards');
    $boards = Db::rows("SELECT * FROM {$B} ORDER BY sort ASC, id ASC");
    $rows = '';
    foreach ($boards as $b) {
        $tc = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('forum_threads') . " WHERE board_id=? AND status='normal'", [(int)$b['id']]);
        $rows .= '<tr><td><b>' . e((string)$b['name']) . '</b>' . ($b['name_en'] !== '' ? '<div class="dim">' . e((string)$b['name_en']) . '</div>' : '')
            . '<div class="dim">/' . e((string)$b['slug']) . ' · ' . $tc . ' 帖</div></td>'
            . '<td>' . e(mb_substr((string)$b['intro'], 0, 40)) . '</td>'
            . '<td>' . (($b['status'] ?? 'show') === 'show' ? '<span class="tag" style="background:#e2efe2;color:#2e6b34">显示</span>' : '<span class="tag">隐藏</span>') . '</td>'
            . '<td>' . (int)$b['sort'] . '</td>'
            . '<td><form class="inline" method="post" action="/admin/forum">' . \Csrf::field()
            . '<input type="hidden" name="act" value="board_save"><input type="hidden" name="id" value="' . (int)$b['id'] . '">'
            . '<input type="text" name="name" value="' . e((string)$b['name']) . '" style="width:120px" title="中文名"> '
            . '<input type="text" name="name_en" value="' . e((string)$b['name_en']) . '" style="width:120px" placeholder="English"> '
            . '<input type="text" name="sort" value="' . (int)$b['sort'] . '" style="width:46px" title="排序"> '
            . '<select name="status"><option value="show"' . (($b['status'] ?? '') === 'show' ? ' selected' : '') . '>显示</option><option value="hide"' . (($b['status'] ?? '') === 'hide' ? ' selected' : '') . '>隐藏</option></select> '
            . '<button class="btn primary">保存</button></form></td></tr>';
    }
    $addForm = '<form method="post" action="/admin/forum" class="yfa-card"><h3>＋ 新增板块</h3>' . \Csrf::field()
        . '<input type="hidden" name="act" value="board_save">'
        . '<div class="row">'
        . '<div class="f"><span>slug（小写英文，如 feedback）</span><input type="text" name="slug" required pattern="[a-z0-9-]{1,80}"></div>'
        . '<div class="f"><span>中文名</span><input type="text" name="name" required></div>'
        . '<div class="f"><span>English name</span><input type="text" name="name_en"></div>'
        . '<div class="f"><span>一句话说明</span><input type="text" name="intro" style="width:260px"></div>'
        . '<div class="f"><span>排序（小在前）</span><input type="text" name="sort" value="99" style="width:50px"></div>'
        . '<div class="f"><span>&nbsp;</span><button class="btn primary">创建</button></div>'
        . '</div></form>';

    /* ★ yf-att-admin-20261020：附件统计 + 清理入口
       附件是「无主文件」——数据库只记 markdown 里的路径，删帖不删图。
       这里给一个手动回收入口：只删「正文里没人引用 + 超过 N 天」的。 */
    $AT = Db::t('forum_att');
    $attN = 0; $attSz = 0; $attRecent = [];
    try {
        $attN  = (int)Db::one("SELECT COUNT(*) FROM `{$AT}`");
        $attSz = (int)Db::one("SELECT COALESCE(SUM(size),0) FROM `{$AT}`");
        $attRecent = Db::rows("SELECT path,size,width,height,created_at FROM `{$AT}` ORDER BY id DESC LIMIT 8");
    } catch (\Throwable $e) { /* 表还没建（首次访问）时静默 */ }

    $attThumb = '';
    foreach ($attRecent as $ar) {
        $attThumb .= '<a href="' . e((string)$ar['path']) . '" target="_blank" style="display:inline-block;margin:0 6px 6px 0">'
            . '<img src="' . e((string)$ar['path']) . '" alt="" style="height:56px;width:auto;border-radius:6px;border:1px solid #e5e0d5;display:block">'
            . '<span style="display:block;font-size:10.5px;color:#9a9a90">' . (int)round(((int)$ar['size']) / 1024) . 'KB</span></a>';
    }
    $attForm = '<form method="post" action="/admin/forum" class="yfa-card" onsubmit="return confirm(\'确认清理？只删正文里没人引用的附件，文件会从磁盘删除。\')">'
        . '<h3>附件</h3>' . \Csrf::field()
        . '<input type="hidden" name="act" value="att_gc">'
        . '<p class="dim" style="margin:0 0 8px">共 <b>' . $attN . '</b> 个 · 占用 <b>'
        . ($attSz >= 1048576 ? round($attSz / 1048576, 1) . ' MB' : round($attSz / 1024) . ' KB') . '</b>'
        . ' · 存放于 <code>/uploads/forum/img/YYYY/MM/</code>（统一 WebP）</p>'
        . ($attThumb !== '' ? '<div style="margin:0 0 10px">' . $attThumb . '</div>' : '')
        . '<div class="row"><div class="f"><span>只清理 N 天前的（0 = 全部）</span>'
        . '<input type="text" name="days" value="7" style="width:60px"></div>'
        . '<div class="f"><span>&nbsp;</span><button class="btn">清理孤儿附件</button></div></div>'
        . '<p class="dim" style="margin:8px 0 0;font-size:12px">只删「帖子/回帖正文里都找不到引用」的；'
        . '软删除的帖子仍算引用（可恢复），不会被误删。</p></form>';

    $addForm = $addForm . $attForm;

    // 最近帖子
    $T = Db::t('forum_threads');
    $threads = Db::rows("SELECT t.*, b.name bname FROM {$T} t LEFT JOIN " . Db::t('forum_boards') . " b ON b.id=t.board_id WHERE t.status<>'deleted' ORDER BY t.id DESC LIMIT 25");
    $tr = '';
    foreach ($threads as $th) {
        $u = yf_user((int)$th['user_id']);
        $st = (string)$th['status'];
        $badge = $st === 'normal' ? '<span class="tag" style="background:#e2efe2;color:#2e6b34">正常</span>' : ($st === 'hidden' ? '<span class="tag" style="background:#f6e7d5;color:#a05a1a">隐藏</span>' : '<span class="tag" style="background:#f6e0dc;color:#b33">已删</span>');
        $act1 = $st === 'normal'
            ? '<form class="inline" method="post" action="/admin/forum">' . \Csrf::field() . '<input type="hidden" name="act" value="thread_mod"><input type="hidden" name="id" value="' . (int)$th['id'] . '"><input type="hidden" name="what" value="hide"><button class="btn">隐藏</button></form>'
            : '<form class="inline" method="post" action="/admin/forum">' . \Csrf::field() . '<input type="hidden" name="act" value="thread_mod"><input type="hidden" name="id" value="' . (int)$th['id'] . '"><input type="hidden" name="what" value="show"><button class="btn">恢复</button></form>';
        $act2 = '<form class="inline" method="post" action="/admin/forum" onsubmit="return confirm(\'删除该帖及其回帖？\')">' . \Csrf::field() . '<input type="hidden" name="act" value="thread_mod"><input type="hidden" name="id" value="' . (int)$th['id'] . '"><input type="hidden" name="what" value="del"><button class="btn" style="color:#b33">删除</button></form>';
        $tr .= '<tr><td>' . e(mb_substr((string)$th['title'], 0, 46)) . '<div class="dim">' . e((string)($th['bname'] ?? '')) . ' · ' . e(yf_uname($u)) . ' · ' . (int)$th['replies'] . ' 回 · ' . e(yf_dt((string)$th['created_at'])) . '</div></td>'
            . '<td>' . $badge . '</td>'
            . '<td><a class="btn" href="' . e(yf_url(['t' => (int)$th['id']])) . '" target="_blank">查看</a> ' . $act1 . ' ' . $act2 . '</td></tr>';
    }
    // 最近回帖
    $R = Db::t('forum_replies');
    $reps = Db::rows("SELECT r.*, t.title ttitle FROM {$R} r JOIN {$T} t ON t.id=r.thread_id WHERE r.status<>'deleted' ORDER BY r.id DESC LIMIT 15");
    $rr = '';
    foreach ($reps as $r) {
        $ru = yf_user((int)$r['user_id']);
        $st = (string)$r['status'];
        $badge = $st === 'normal' ? '<span class="tag" style="background:#e2efe2;color:#2e6b34">正常</span>' : ($st === 'hidden' ? '<span class="tag" style="background:#f6e7d5;color:#a05a1a">隐藏</span>' : '<span class="tag" style="background:#f6e0dc;color:#b33">已删</span>');
        $act = $st === 'normal'
            ? '<form class="inline" method="post" action="/admin/forum">' . \Csrf::field() . '<input type="hidden" name="act" value="reply_mod"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="what" value="hide"><button class="btn">隐藏</button></form>'
            : '<form class="inline" method="post" action="/admin/forum">' . \Csrf::field() . '<input type="hidden" name="act" value="reply_mod"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="what" value="show"><button class="btn">恢复</button></form>';
        $del = '<form class="inline" method="post" action="/admin/forum">' . \Csrf::field() . '<input type="hidden" name="act" value="reply_mod"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="what" value="del"><button class="btn" style="color:#b33">删除</button></form>';
        $rr .= '<tr><td>' . e(mb_substr((string)$r['ttitle'], 0, 30)) . '<div class="dim">' . e(yf_uname($ru)) . ' · ' . e(yf_dt((string)$r['created_at'])) . '</div></td>'
            . '<td>' . e(mb_substr((string)$r['content'], 0, 40)) . '</td>'
            . '<td>' . $badge . '</td><td>' . $act . ' ' . $del . '</td></tr>';
    }

    $boardTbl = '<div class="yfa-card yfa"><h3>板块管理（点行内保存生效）</h3>'
        . '<table><thead><tr><th>板块</th><th>说明</th><th>状态</th><th>排序</th><th style="width:330px">修改</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    $threadTbl = '<div class="yfa-card yfa"><h3>最近帖子（隐藏=前台不可见，删除=软删）</h3>'
        . '<table><thead><tr><th>标题</th><th>状态</th><th>操作</th></tr></thead><tbody>' . ($tr !== '' ? $tr : '<tr><td colspan="3" class="dim">暂无帖子</td></tr>') . '</tbody></table></div>';
    $replyTbl = '<div class="yfa-card yfa"><h3>最近回帖</h3>'
        . '<table><thead><tr><th>所在帖</th><th>内容</th><th>状态</th><th>操作</th></tr></thead><tbody>' . ($rr !== '' ? $rr : '<tr><td colspan="4" class="dim">暂无回帖</td></tr>') . '</tbody></table></div>';

    return '<div class="card"><h3>社区 Community 管理</h3>'
        . '<p class="dim" style="margin:2px 0 0">前台入口：<a href="' . e(yf_url()) . '" target="_blank">/forum</a> · 发帖回帖走前台（会员/游客规则同站内）。</p>'
        . $msgHtml . '</div>' . $css . $addForm . $boardTbl . $threadTbl . $replyTbl;
}

function yf_admin_handle(): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return ''; }
    try { \Csrf::check(); } catch (Throwable) { return '校验失败，请刷新重试'; }
    $act = (string)($_POST['act'] ?? '');
    $B = Db::t('forum_boards');
    $T = Db::t('forum_threads');
    $R = Db::t('forum_replies');
    /* ★ yf-att-admin-20261020：清理孤儿附件（正文里已经没人引用的） */
    if ($act === 'att_gc') {
        $r = function_exists('yf_att_gc') ? yf_att_gc((int)($_POST['days'] ?? 7)) : ['deleted' => 0, 'kept' => 0];
        return '清理完成：删除 ' . $r['deleted'] . ' 个孤儿附件，保留 ' . $r['kept'] . ' 个仍被引用的。';
    }

    if ($act === 'board_save') {
        $id = (int)($_POST['id'] ?? 0);
        $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $nameEn = trim((string)($_POST['name_en'] ?? ''));
        $intro = trim((string)($_POST['intro'] ?? ''));
        $sort = max(0, (int)($_POST['sort'] ?? 99));
        $status = ($_POST['status'] ?? 'show') === 'hide' ? 'hide' : 'show';
        if ($name === '') { return '板块中文名不能为空'; }
        if ($id > 0) {
            $cur = Db::row("SELECT slug FROM {$B} WHERE id=?", [$id]);
            if (!$cur) { return '板块不存在'; }
            $slug = (string)$cur['slug'];
            Db::update('forum_boards', ['name' => $name, 'name_en' => $nameEn, 'intro' => $intro, 'sort' => $sort, 'status' => $status], 'id=?', [$id]);
        } else {
            if (!preg_match('/^[a-z0-9-]{1,80}$/D', $slug)) { return 'slug 需为小写英文/数字/连字符'; }
            $dup = Db::one("SELECT id FROM {$B} WHERE slug=? LIMIT 1", [$slug]);
            if ($dup !== null) { return '该 slug 已存在'; }
            Db::insert('forum_boards', ['slug' => $slug, 'name' => $name, 'name_en' => $nameEn, 'intro' => $intro, 'sort' => $sort, 'status' => $status]);
        }
        return '板块已保存';
    }
    if ($act === 'thread_mod') {
        $id = (int)($_POST['id'] ?? 0);
        $what = (string)($_POST['what'] ?? '');
        $map = ['hide' => 'hidden', 'show' => 'normal', 'del' => 'deleted'];
        if (!isset($map[$what]) || $id <= 0) { return '参数无效'; }
        Db::run("UPDATE {$T} SET status=? WHERE id=?", [$map[$what], $id]);
        if ($what === 'del') { Db::run("UPDATE {$R} SET status='deleted' WHERE thread_id=?", [$id]); }
        return '帖子已更新';
    }
    if ($act === 'reply_mod') {
        $id = (int)($_POST['id'] ?? 0);
        $what = (string)($_POST['what'] ?? '');
        $map = ['hide' => 'hidden', 'show' => 'normal', 'del' => 'deleted'];
        if (!isset($map[$what]) || $id <= 0) { return '参数无效'; }
        Db::run("UPDATE {$R} SET status=? WHERE id=?", [$map[$what], $id]);
        return '回帖已更新';
    }
    return '';
}
