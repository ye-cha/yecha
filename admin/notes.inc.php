<?php
declare(strict_types=1);
/**
 * 记事本（P-3）：后台私人快速笔记 CRUD（仅博主/admin 使用；表 ye_notes）
 * POST do=note-save | note-delete（CSRF 由外层校验）
 */
function notes_do(string $do): never
{
    if ($do === 'note-delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { Db::deleteBy('notes', 'id=? AND user_id=?', [$id, Auth::id()]); }
        $_SESSION['flash'] = '已删除';
        header('Location: /admin/?s=notes');
        exit;
    }
    // note-save
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '' && $title === '') { $_SESSION['flash'] = '写点什么再保存'; header('Location: /admin/?s=notes&action=new'); exit; }
    $tags = trim((string)($_POST['tags'] ?? ''));
    $data = ['title' => $title, 'body' => $body, 'tags' => $tags];
    if ($id > 0) {
        Db::update('notes', $data, 'id=? AND user_id=?', [$id, Auth::id()]);
    } else {
        $data['user_id'] = Auth::id();
        Db::insert('notes', $data);
    }
    $_SESSION['flash'] = '已保存';
    header('Location: /admin/?s=notes');
    exit;
}

function notes_editor_html(int $id = 0): string
{
    $d = ['title' => '', 'body' => '', 'tags' => ''];
    if ($id > 0) {
        $r = Db::row('SELECT * FROM ' . Db::t('notes') . ' WHERE id=? AND user_id=?', [$id, Auth::id()]);
        if ($r !== null) { $d = ['title' => (string)$r['title'], 'body' => (string)$r['body'], 'tags' => (string)$r['tags']]; }
    }
    return '<div class="card"><h3>' . ($id > 0 ? '编辑笔记' : '随手记一条') . '</h3>'
        . '<form method="post" action="/admin/?s=notes">' . Csrf::field()
        . '<input type="hidden" name="do" value="note-save"><input type="hidden" name="id" value="' . $id . '">'
        . '<label class="pf">标题</label><input name="title" value="' . e($d['title']) . '" placeholder="（可选）">'
        . '<label class="pf">内容 *</label><textarea name="body" rows="12" required style="min-height:220px;">' . e($d['body']) . '</textarea>'
        . '<label class="pf">标签（空格分隔，轻量）</label><input name="tags" value="' . e($d['tags']) . '" placeholder="如：发酵 网站 备忘">'
        . '<div style="margin-top:12px;"><button class="btn" type="submit">保存</button> <a class="btn ghost" href="/admin/?s=notes">返回</a></div></form></div>'
        . '<style>.pf{display:block;font-size:12.5px;color:#6f767e;margin:10px 0 2px}.pf+input,.pf+textarea{width:100%;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font:inherit}</style>';
}

function notes_list_html(): string
{
    $rows = Db::rows('SELECT * FROM ' . Db::t('notes') . ' WHERE user_id=? ORDER BY id DESC LIMIT 200', [Auth::id()]);
    $html = '<div class="card"><h3>记事本 <small>私人快速记录 · P-3（仅博主可见）</small></h3>'
        . '<a class="btn" href="/admin/?s=notes&action=new">＋ 随手记一条</a></div>';
    if (!$rows) {
        $html .= '<div class="card"><div class="stub">还没有笔记。临时想法、清单、待办都可以记在这里。</div></div>';
        return $html;
    }
    foreach ($rows as $r) {
        $tags = trim((string)$r['tags']);
        $tagHtml = '';
        if ($tags !== '') {
            $tagHtml = '<div class="hint" style="margin-top:6px;">';
            foreach (preg_split('/\s+/', $tags) ?: [] as $t) { $tagHtml .= '<span class="tag" style="display:inline-block;margin:0 6px 4px 0;">' . e(trim($t)) . '</span>'; }
            $tagHtml .= '</div>';
        }
        $body = nl2br(e(Str::excerpt((string)$r['body'], 300)));
        $html .= '<div class="card">'
            . '<div style="display:flex;align-items:baseline;gap:10px;"><b style="font-size:15px;">' . ($r['title'] !== '' ? e((string)$r['title']) : '无标题') . '</b>'
            . '<span class="hint">' . e(substr((string)$r['updated_at'], 0, 16)) . '</span>'
            . '<span style="margin-left:auto;"><a class="btn ghost mini" href="/admin/?s=notes&action=edit&id=' . (int)$r['id'] . '">编辑</a> '
            . '<form class="inline" method="post" action="/admin/?s=notes" style="display:inline;" onsubmit="return confirm(\'删除这条笔记？\')">' . Csrf::field()
            . '<input type="hidden" name="do" value="note-delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
            . '<button class="btn ghost mini" style="color:#9c3f1b;border-color:#e3c5b6;" type="submit">删除</button></form></span></div>'
            . '<p style="margin:8px 0 0;white-space:pre-wrap;">' . $body . '</p>' . $tagHtml . '</div>';
    }
    return $html;
}
