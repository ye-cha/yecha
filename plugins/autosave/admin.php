<?php
declare(strict_types=1);
/**
 * 文章自动保存 · 后台管理页(/admin/?s=plug_autosave)
 * - 保留策略设置(间隔 / 每篇版本数 / 保留天数 / 离页与保存前留底开关)
 * - 版本列表:按文章或全部,可预览、一键载回编辑器、单条删除、按篇清空、清空全部
 */
function yc_autosave_admin(): string
{
    $back = '/admin/?s=plug_autosave';
    if (!Plugins::isOn('autosave')) {
        return '<div class="card"><h3>文章自动保存</h3><div class="stub">插件已停用：到「插件」页启用后即可使用（已保存的快照保留在数据表中）。</div></div>';
    }
    require_once __DIR__ . '/inc.php';
    asv_ensure_table();

    $qid = isset($_GET['id']) ? max(0, (int)$_GET['id']) : null;
    $qlang = trim((string)($_GET['lang'] ?? ''));
    $page = max(1, (int)($_GET['p'] ?? 1));
    $per = 50;

    /* ---------- POST ---------- */
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'settings') {
            set_option('autosave.interval', (string)max(10, min(600, (int)($_POST['interval'] ?? 30))));
            set_option('autosave.keep', (string)max(3, min(500, (int)($_POST['keep'] ?? 30))));
            set_option('autosave.days', (string)max(0, min(3650, (int)($_POST['days'] ?? 30))));
            set_option('autosave.on_submit', !empty($_POST['on_submit']) ? '1' : '0');
            set_option('autosave.on_hide', !empty($_POST['on_hide']) ? '1' : '0');
            $_SESSION['flash'] = '保留策略已保存';
            header('Location: ' . $back); exit;
        }
        if ($do === 'del') {
            asv_delete((int)($_POST['sid'] ?? 0));
            $_SESSION['flash'] = '已删除该版本';
            header('Location: ' . $back); exit;
        }
        if ($do === 'del-doc') {
            $n = asv_delete_content((int)($_POST['cid'] ?? 0), (string)($_POST['lang'] ?? ''));
            $_SESSION['flash'] = '已删除该篇的 ' . $n . ' 个版本';
            header('Location: ' . $back); exit;
        }
        if ($do === 'purge-expired') {
            $n = asv_purge_expired();
            $_SESSION['flash'] = '已清理过期版本 ' . $n . ' 个（保留 ' . asv_days() . ' 天）';
            header('Location: ' . $back); exit;
        }
        if ($do === 'clear') {
            if ((string)($_POST['confirm'] ?? '') !== 'DELETE') {
                $_SESSION['flash'] = '清空全部需要输入 DELETE 确认';
            } else {
                $n = asv_clear_all();
                $_SESSION['flash'] = '已清空全部快照（' . $n . ' 个）';
            }
            header('Location: ' . $back); exit;
        }
    }

    $stats = asv_stats();
    $total = asv_total($qid, $qlang);
    $rows = asv_list($qid, $qlang, $per, ($page - 1) * $per);
    $csrf = Csrf::field();

    $h = '<div class="card"><h3>文章自动保存 <small>写作过程自动留底 · 多版本可回滚 · 手工可删</small></h3>'
        . '<p style="color:#6f767e;font-size:13px;margin:0 0 10px;">快照存在数据表 <code>ye_autosaves</code>（不放 <code>var/cache</code>：框架清缓存会把缓存文件一起清掉，快照必须活得比缓存久）。'
        . '编辑器里每 ' . (int)$stats['interval'] . ' 秒、停手 5 秒、切走页面、点「保存」前各留一版；每篇保留最新 '
        . (int)$stats['keep'] . ' 个版本，超过 ' . ((int)$stats['days'] === 0 ? '永久保留' : (int)$stats['days'] . ' 天') . ' 自动清理。</p>'
        . '<table><tr><th>版本总数</th><th>涉及文章</th><th>正文字数合计</th><th>最早</th><th>最近</th><th>清理</th></tr><tr>'
        . '<td><b>' . (int)$stats['n'] . '</b></td><td>' . (int)$stats['docs'] . '</td><td>' . number_format((int)$stats['chars']) . '</td>'
        . '<td>' . ($stats['first_at'] !== '' ? e($stats['first_at']) : '—') . '</td>'
        . '<td>' . ($stats['last_at'] !== '' ? e($stats['last_at']) : '—') . '</td>'
        . '<td><form class="inline" method="post" action="' . $back . '">' . $csrf
        . '<input type="hidden" name="do" value="purge-expired"><button class="btn ghost mini">清理过期</button></form></td>'
        . '</tr></table></div>';

    /* 保留策略 */
    $h .= '<div class="card"><h3>保留策略</h3><form method="post" action="' . $back . '">' . $csrf
        . '<input type="hidden" name="do" value="settings">'
        . '<table><tr><th>自动留底间隔</th><th>每篇保留版本数</th><th>保留天数</th><th>切走页面留底</th><th>点保存前留底</th></tr><tr>'
        . '<td><input type="number" name="interval" min="10" max="600" value="' . (int)asv_interval() . '" style="width:90px"> 秒</td>'
        . '<td><input type="number" name="keep" min="3" max="500" value="' . (int)asv_keep() . '" style="width:90px"> 个</td>'
        . '<td><input type="number" name="days" min="0" max="3650" value="' . (int)asv_days() . '" style="width:90px"> 天 <span style="color:#6f767e">（0=永久）</span></td>'
        . '<td><label class="chk"><input type="checkbox" name="on_hide" value="1"' . (asv_on_hide() ? ' checked' : '') . '> 开</label></td>'
        . '<td><label class="chk"><input type="checkbox" name="on_submit" value="1"' . (asv_on_submit() ? ' checked' : '') . '> 开</label></td>'
        . '</tr></table><div style="margin-top:10px"><button class="btn">保存策略</button></div></form></div>';

    /* 筛选 + 列表 */
    $filter = $qid !== null ? ('文章 #' . $qid . ($qlang !== '' ? '（' . e($qlang) . '）' : '')) : '全部';
    $h .= '<div class="card"><h3>版本列表 <small>' . $filter . ' · 共 ' . $total . ' 个</small></h3>';
    if ($qid !== null) {
        $h .= '<p style="margin:0 0 10px;font-size:13px"><a href="' . $back . '">← 看全部</a>';
        if ($total > 0) {
            $h .= ' <form class="inline" method="post" action="' . $back . '" onsubmit="return confirm(\'删除该篇的全部快照？\')" style="margin-left:10px">' . $csrf
                . '<input type="hidden" name="do" value="del-doc"><input type="hidden" name="cid" value="' . $qid . '">'
                . '<input type="hidden" name="lang" value="' . e($qlang) . '"><button class="btn ghost mini">删除该篇全部版本</button></form>';
        }
        $h .= '</p>';
    }

    if (!$rows) {
        $h .= '<div class="stub">还没有快照。到「文章 → 编辑」里写点东西，编辑器下方会出现「自动保存」状态条。</div>';
    } else {
        $h .= '<table><tr><th>时间</th><th>文章</th><th>语言</th><th>字数</th><th>来源</th><th>操作</th></tr>';
        $reasonZh = ['timer' => '定时', 'hide' => '离页', 'submit' => '保存前', 'manual' => '手工'];
        foreach ($rows as $r) {
            $sid = (int)$r['id'];
            $cid = (int)$r['content_id'];
            $lang = (string)$r['lang'];
            $title = trim((string)$r['title']);
            if ($title === '') { $title = $cid > 0 ? ('#' . $cid) : '（未保存的新稿）'; }
            /* 预览正文：列表查询不带大字段，这里单取一次（Markdown 优先，富文本快照则取 DOM 原文的纯文字） */
            $pv = Db::row('SELECT content, rich_html FROM ' . asv_table() . ' WHERE id=?', [$sid]);
            $preview = trim((string)($pv['content'] ?? '')) !== ''
                ? (string)$pv['content']
                : strip_tags((string)($pv['rich_html'] ?? ''));
            $editUrl = $cid > 0
                ? '/admin/?s=' . (((string)$r['type']) === 'page' ? 'pages' : 'posts') . '&action=edit&id=' . $cid . '&restore=' . $sid
                : '/admin/?s=posts&action=new&restore=' . $sid;
            $h .= '<tr><td>' . e((string)$r['created_at']) . '</td>'
                . '<td>' . ($cid > 0 ? '<a href="/admin/?s=plug_autosave&id=' . $cid . '&lang=' . rawurlencode($lang) . '">' . e($title) . '</a>' : e($title)) . '</td>'
                . '<td>' . e($lang) . '</td>'
                . '<td>' . (int)$r['plain_chars'] . ' <span style="color:#6f767e">/ ' . (int)$r['chars'] . ' 字符</span></td>'
                . '<td>' . e($reasonZh[(string)$r['reason']] ?? (string)$r['reason']) . '</td>'
                . '<td><a class="btn ghost mini" href="' . e($editUrl) . '">载回编辑器</a> '
                . '<details style="display:inline-block"><summary class="btn ghost mini" style="cursor:pointer">预览</summary>'
                . '<div style="margin-top:6px;max-width:760px;max-height:300px;overflow:auto;border:1px solid #d0d7de;border-radius:6px;padding:8px;background:#f6f8fa;white-space:pre-wrap;font-size:12px">'
                . e(mb_substr($preview, 0, 1500))
                . '</div></details> '
                . '<form class="inline" method="post" action="' . $back . '" onsubmit="return confirm(\'删除这个版本？\')">' . $csrf
                . '<input type="hidden" name="do" value="del"><input type="hidden" name="sid" value="' . $sid . '">'
                . '<button class="btn ghost mini">删除</button></form></td></tr>';
        }
        $h .= '</table>';
        if ($total > $per) {
            $pages = (int)ceil($total / $per);
            $h .= '<div class="pager" style="margin-top:10px">';
            for ($i = 1; $i <= $pages && $i <= 40; $i++) {
                $link = $back . '&p=' . $i . ($qid !== null ? '&id=' . $qid . '&lang=' . rawurlencode($qlang) : '');
                $h .= $i === $page ? '<span class="on">' . $i . '</span>' : '<a href="' . e($link) . '">' . $i . '</a>';
            }
            $h .= '</div>';
        }
    }
    $h .= '</div>';

    /* 清空全部 */
    $h .= '<div class="card"><h3>清空全部快照</h3>'
        . '<p style="color:#6f767e;font-size:13px;margin:0 0 8px;">不可恢复。只想清掉旧的可以用上面的「清理过期」，或按篇删除。</p>'
        . '<form method="post" action="' . $back . '" onsubmit="return confirm(\'清空全部快照？此操作不可恢复\')">' . $csrf
        . '<input type="hidden" name="do" value="clear">'
        . '<label class="chk">输入 <code>DELETE</code> 确认：<input type="text" name="confirm" placeholder="DELETE" style="width:120px"></label> '
        . '<button class="btn ghost">清空全部</button></form></div>';

    return $h;
}
