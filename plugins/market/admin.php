<?php
declare(strict_types=1);
// 市场收录 · 后台：验证 / 发布 / 驳回
require_once __DIR__ . '/market_lib.php';

function yc_market_admin(): string
{
    $back = '/admin/?s=plug_market';
    $do = (string)($_POST['do'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $do !== '') {
        $id = (int)($_POST['id'] ?? 0);
        if ($do === 'mkt-verify' && $id > 0) {
            $req = Db::row('SELECT * FROM ' . mkt_table() . ' WHERE id=?', [$id]);
            if ($req) {
                $file = mkt_pending_dir() . '/' . strtolower(preg_replace('/[^a-z0-9_]/', '', (string)$req['app_id'])) . '-' . time() . '.zip';
                $dl = mkt_download((string)$req['url'], $file);
                if (!$dl['ok']) { @unlink($file); $_SESSION['flash'] = ['m' => '验证失败：' . $dl['err'], 'e' => true]; }
                else {
                    $v = mkt_validate_zip($file, (string)$req['type']);
                    $claim = trim(strtolower((string)$req['sha_claim']));
                    $shaOk = $claim === '' || $claim === $v['sha'];
                    $note = 'SHA=' . mb_substr((string)$v['sha'], 0, 16) . '… · ' . $v['files'] . ' 文件 · ' . number_format((int)$v['size']) . ' B';
                    if (!$shaOk) { $note .= ' · 声明的 SHA 不匹配'; }
                    if ($v['ok'] && $shaOk) {
                        Db::update('market_reqs', ['status' => 'verified', 'checked_note' => $note, 'checked_at' => date('Y-m-d H:i:s'), 'note' => '包文件: ' . basename($file), 'file_path' => $file], 'id=?', [$id]);
                        $_SESSION['flash'] = ['m' => '验证通过 ✔ ' . $note, 'e' => false];
                    } else {
                        @unlink($file);
                        Db::update('market_reqs', ['checked_note' => '验证未通过：' . ($v['err'] ? implode('；', array_slice($v['err'], 0, 3)) : ($shaOk ? '' : 'SHA 不一致')) . ($v['ok'] && !$shaOk ? '' : ''), 'checked_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
                        $_SESSION['flash'] = ['m' => '验证未通过，原因已记录', 'e' => true];
                    }
                }
            }
            header('Location: ' . $back); exit;
        }
        if ($do === 'mkt-publish' && $id > 0) {
            $req = Db::row('SELECT * FROM ' . mkt_table() . ' WHERE id=?', [$id]);
            if ($req && (string)$req['status'] === 'verified' && is_file((string)$req['file_path'])) {
                $pub = mkt_publish($req, (string)$req['file_path']);
                if ($pub['ok']) {
                    Db::update('market_reqs', ['status' => 'published', 'note' => '已发布 ' . $pub['package'], 'checked_note' => 'SHA=' . mb_substr((string)$pub['sha'], 0, 16) . '…'], 'id=?', [$id]);
                    \Cache::flush('pages');
                    $_SESSION['flash'] = ['m' => '已发布到市场 ✔ ' . $pub['package'], 'e' => false];
                } else {
                    $_SESSION['flash'] = ['m' => '发布失败：' . $pub['err'], 'e' => true];
                }
            }
            header('Location: ' . $back); exit;
        }
        if ($do === 'mkt-reject' && $id > 0) {
            Db::update('market_reqs', ['status' => 'rejected', 'checked_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
            header('Location: ' . $back); exit;
        }
    }

    $csrf = Csrf::field();
    $rows = Db::rows('SELECT * FROM ' . mkt_table() . ' ORDER BY id DESC LIMIT 300');
    $stName = ['pending' => '待验证', 'verified' => '已通过（待发布）', 'published' => '已发布', 'rejected' => '已驳回'];
    $tr = '';
    foreach ($rows as $r) {
        $st = (string)($r['status'] ?? 'pending');
        $color = ['pending' => '#9c6b1b', 'verified' => '#2e6b34', 'published' => '#2e6b34', 'rejected' => '#9a9a90'][$st] ?? '#9a9a90';
        $acts = '';
        if ($st === 'pending') {
            $acts .= '<form method="post" style="display:inline;">' . $csrf . '<input type="hidden" name="do" value="mkt-verify"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn ghost mini" style="color:#1f2328;border-color:#8a94a0;">验证</button></form> ';
        } elseif ($st === 'verified') {
            $acts .= '<form method="post" style="display:inline;">' . $csrf . '<input type="hidden" name="do" value="mkt-publish"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn ghost mini" style="background:#2e6b34;color:#fff;border-color:#2e6b34;">发布到市场</button></form> ';
        }
        if ($st !== 'rejected' && $st !== 'published') {
            $acts .= '<form method="post" style="display:inline;" onsubmit="return confirm(\'驳回该申请？\')">' . $csrf . '<input type="hidden" name="do" value="mkt-reject"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn ghost mini" style="color:#b3261e;border-color:#d9a49e;">驳回</button></form>';
        }
        $note = trim((string)$r['checked_note']);
        $tr .= '<tr><td><b>' . e($r['name']) . '</b><div style="color:#6f767e;font-size:12px;">' . e($r['type'] === 'theme' ? '主题' : '插件') . ' · ' . e($r['app_id']) . ' v' . e($r['ver']) . '</div></td>'
            . '<td>' . e($r['author']) . '<div style="color:#9a9a90;font-size:11px;">' . e(mb_substr((string)$r['url'], 0, 44)) . '</div></td>'
            . '<td style="max-width:220px;">' . e(mb_substr((string)$r['description'], 0, 70)) . '<div style="color:#9a9a90;font-size:11px;">' . e($r['email']) . ' · ' . e(substr((string)$r['created_at'], 0, 10)) . '</div></td>'
            . '<td><span style="color:' . $color . ';font-weight:600;">' . $stName[$st] . '</span>' . ($note !== '' ? '<div style="color:#9a9a90;font-size:11px;">' . e(mb_substr($note, 0, 90)) . '</div>' : '') . '</td>'
            . '<td style="white-space:nowrap;">' . $acts . '</td></tr>';
    }
    $manifest = '';
    $mf = mkt_manifest_path();
    if (is_file($mf)) {
        $items = json_decode((string)file_get_contents($mf), true)['items'] ?? [];
        $manifest = '当前市场 manifest：' . count($items) . ' 个条目 · 路径 <code>' . e(str_replace(mkt_root(), '', $mf)) . '</code> · 已发布会在云市场与前台出现。';
    }
    return '<div class="card"><h3>应用市场收录 <small>公开 /market-app 提交 → 验证 → 发布进 var/market/manifest.json（云市场自动读取）</small></h3>'
        . '<p style="color:#565b61;font-size:13px;">' . $manifest . '</p>'
        . '<p style="color:#9a9a90;font-size:12.5px;">验证内容：ZIP 结构（防路径穿越）→ 插件需根 plugin.php / 主题需含 CSS → 包内全部 .php 跑 php -l → 计算 SHA-256 并与申请声明比对。验证不执行包内代码，发布后上线行为仍需留意。前台入口：<a target="_blank" href="/market-app">/market-app</a>（上架规范见该页与<a target="_blank" href="/market.html">应用市场</a>）。</p></div>'
        . '<div class="card"><h3>上架申请（' . count($rows) . '）</h3>'
        . ($rows
            ? '<table><tr><th>应用</th><th>作者 / 下载</th><th>说明 / 联系</th><th>状态</th><th>操作</th></tr>' . $tr . '</table>'
            : '<div class="stub">暂无申请。去 <a href="/market-app">/market-app</a> 看前台入口。</div>')
        . '</div>'
        . '<style>.pf{display:block;font-size:12.5px;color:#6f767e;margin:4px 0 2px}</style>';
}
