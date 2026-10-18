<?php
declare(strict_types=1);
// 后台：体验站申请审核 + 状态（plugins/demo）
function yc_demo_admin(): void
{
    $act = (string)($_POST['act'] ?? ($_GET['act'] ?? ''));
    $id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
    if ($act !== '' && $id > 0) {
        $map = ['approve' => 'ok', 'suspend' => 'hidden', 'remove' => 'DEL'];
        if (isset($map[$act])) {
            if ($map[$act] === 'DEL') { Db::deleteBy('demo_sites', 'id=?', [$id]); }
            else { Db::update('demo_sites', ['status' => $map[$act]], 'id=?', [$id]); }
        }
        header('Location: /admin/?s=ext-demo');
        exit;
    }
    $rows = Db::rows('SELECT * FROM ' . Db::t('demo_sites') . ' ORDER BY (status=\'pending\') DESC, id DESC');
    echo '<div class="card"><h3>在线体验 · 体验站</h3><table class="tb"><thead><tr><th>ID</th><th>站点</th><th>URL</th><th>一句话</th><th>邮箱</th><th>状态</th><th>最近检查</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $badge = ['pending' => '待审', 'ok' => '正常', 'down' => '异常', 'hidden' => '隐藏'][$r['status']] ?? $r['status'];
        $btn = '';
        if ($r['status'] === 'pending') { $btn = '<button name="act" value="approve" class="btn">通过</button> <button name="act" value="suspend" class="btn ghost">驳回</button>'; }
        elseif ($r['status'] === 'ok' || $r['status'] === 'down') { $btn = '<button name="act" value="suspend" class="btn ghost">下线</button>'; }
        else { $btn = '<button name="act" value="approve" class="btn">恢复</button>'; }
        echo '<tr><td>' . (int)$r['id'] . '</td><td>' . e((string)$r['name']) . '</td>'
            . '<td><a href="' . e((string)$r['url']) . '" target="_blank" rel="noopener">' . e((string)$r['url']) . '</a></td>'
            . '<td>' . e((string)$r['note']) . '</td><td>' . e((string)$r['email']) . '</td>'
            . '<td>' . e($badge) . ($r['last_code'] ? ' (HTTP ' . (int)$r['last_code'] . ')' : '') . '</td>'
            . '<td>' . e((string)$r['checked_at']) . '</td>'
            . '<td><form method="post" style="display:inline"><input type="hidden" name="id" value="' . (int)$r['id'] . '">' . $btn . '</form>'
            . '<form method="post" style="display:inline" onsubmit="return confirm(\'删除？\')"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button name="act" value="remove" class="btn ghost">删除</button></form></td></tr>';
    }
    echo '</tbody></table></div>';
}
