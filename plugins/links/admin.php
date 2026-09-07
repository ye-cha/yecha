<?php
declare(strict_types=1);
// 友链插件后台管理页（/admin/?s=plug_links）；POST 已由后台外层统一 CSRF 校验
require_once __DIR__ . '/data.php';

function yc_links_admin(): string
{
    $do = (string)($_POST['do'] ?? '');
    $back = '/admin/?s=plug_links';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $do !== '') {
        if ($do === 'links-group-save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $kind = in_array(($_POST['kind'] ?? ''), ['friend', 'nav', 'mixed'], true) ? (string)$_POST['kind'] : 'friend';
            $description = trim((string)($_POST['description'] ?? ''));
            $sort = max(0, (int)($_POST['sort'] ?? 0));
            $enabled = (($_POST['enabled'] ?? '') === '1') ? 1 : 0;
            if ($name !== '') {
                $raw = trim((string)($_POST['slug'] ?? ''));
                $slug = $raw !== '' ? strtolower(trim((string)preg_replace('/[^a-z0-9-]/', '-', $raw), '-')) : '';
                if ($slug === '' || strlen($slug) < 2) { $slug = 'group-' . substr(md5($name . microtime()), 0, 8); }
                $baseSlug = $slug;
                for ($i = 2; $i < 60; $i++) {
                    $hit = Db::one('SELECT id FROM ' . Db::t('link_groups') . ' WHERE slug=? AND id<>? LIMIT 1', [$slug, $id]);
                    if ($hit === null) { break; }
                    $slug = $baseSlug . '-' . $i;
                }
                $d = ['name' => $name, 'slug' => $slug, 'kind' => $kind, 'description' => $description, 'sort' => $sort, 'enabled' => $enabled];
                if ($id > 0) { Db::update('link_groups', $d, 'id=?', [$id]); }
                else { Db::insert('link_groups', $d); }
            }
            Cache::flush('pages');
            header('Location: ' . $back . '&msg=group');
            exit;
        }
        if ($do === 'links-group-del') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                Db::deleteBy('links', 'group_id=?', [$id]);
                Db::deleteBy('link_groups', 'id=?', [$id]);
            }
            Cache::flush('pages');
            header('Location: ' . $back . '&msg=group');
            exit;
        }
        if ($do === 'links-group-toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $g = Db::row('SELECT enabled FROM ' . Db::t('link_groups') . ' WHERE id=?', [$id]);
                if ($g) { Db::update('link_groups', ['enabled' => (int)$g['enabled'] === 1 ? 0 : 1], 'id=?', [$id]); }
            }
            Cache::flush('pages');
            header('Location: ' . $back);
            exit;
        }
        if ($do === 'links-link-save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $url = trim((string)($_POST['url'] ?? ''));
            $gid = max(0, (int)($_POST['group_id'] ?? 0));
            $rss = trim((string)($_POST['rss'] ?? ''));
            if ($rss !== '' && !preg_match('#^https?://#i', $rss)) { $rss = ''; }
            $engine = trim((string)($_POST['engine'] ?? ''));
            $engines = [];
            foreach (yc_links_engines() as $grp) { foreach (array_keys($grp) as $k) { $engines[] = $k; } }
            if (!in_array($engine, $engines, true)) { $engine = ''; }
            if ($name !== '' && $url !== '' && $gid > 0) {
                $d = [
                    'group_id' => $gid, 'name' => $name, 'url' => $url, 'rss' => mb_substr($rss, 0, 500),
                    'engine' => $engine,
                    'logo' => trim((string)($_POST['logo'] ?? '')),
                    'description' => trim((string)($_POST['description'] ?? '')),
                    'sort' => max(0, (int)($_POST['sort'] ?? 0)),
                    'status' => (($_POST['status'] ?? 'show') === 'show') ? 'show' : 'hide',
                ];
                if ($id > 0) { Db::update('links', $d, 'id=?', [$id]); }
                else { Db::insert('links', $d); }
            }
            Cache::flush('pages');
            header('Location: ' . $back . '&msg=link');
            exit;
        }
        if ($do === 'links-probe') {
            // 人工触发机器体检（站点存活 + RSS 可达/解析/最近更新）
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $l = Db::row('SELECT * FROM ' . Db::t('links') . ' WHERE id=?', [$id]);
                if ($l) {
                    $r = yc_links_probe_link($l);
                    $_SESSION['flash'] = ['m' => '体检完成：状态「' . $r['state'] . '」' . ($r['note'] !== '' ? ' · ' . $r['note'] : ''), 'e' => in_array($r['state'], ['ok', 'no_rss'], true) ? false : true];
                } else {
                    $_SESSION['flash'] = ['m' => '未找到该链接', 'e' => true];
                }
            }
            header('Location: ' . $back);
            exit;
        }
        if ($do === 'links-link-del') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) { Db::deleteBy('links', 'id=?', [$id]); }
            Cache::flush('pages');
            header('Location: ' . $back);
            exit;
        }
        if ($do === 'links-link-toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $l = Db::row('SELECT status FROM ' . Db::t('links') . ' WHERE id=?', [$id]);
                if ($l) { Db::update('links', ['status' => $l['status'] === 'show' ? 'hide' : 'show'], 'id=?', [$id]); }
            }
            Cache::flush('pages');
            header('Location: ' . $back);
            exit;
        }
        if ($do === 'links-approve') {
            $id = (int)($_POST['id'] ?? 0);
            $gid = max(0, (int)($_POST['group_id'] ?? 0));
            if ($id > 0) {
                if ($gid <= 0) {
                    $_SESSION['flash'] = '请先选择要归入的分组';
                } else {
                    $row = Db::row('SELECT * FROM ' . Db::t('links') . ' WHERE id=?', [$id]);
                    Db::update('links', ['status' => 'show', 'group_id' => $gid], 'id=?', [$id]);
                    Cache::flush('pages');
                    if ($row && trim((string)$row['email']) !== '') {
                        $mr = yc_links_send_mail(
                            (string)$row['email'],
                            '【YeCha 收录圈】已收录：' . (string)$row['name'],
                            '你好 ' . (string)$row['name'] . "：\n\n你的博客已通过审核，现在出现在 YeCha 博客收录圈：\n- 收录墙：https://yecha.org/links\n- 你的流量回执页：https://yecha.org/stats?id={$id}\n- 已收录徽标：https://yecha.org/certificate?id={$id}\n\n我们每小时聚合你的 RSS 新文章（仅标题与链接，流量都导向你的原站），并公开统计每天带去的真实访客。\n\n—— YeCha 博客收录圈"
                        );
                        if ($mr['ok']) { $_SESSION['flash'] = ['m' => '已通过收录，并已邮件通知博主', 'e' => false]; }
                    }
                }
            }
            header('Location: ' . $back);
            exit;
        }
        if ($do === 'links-reject') {
            // 驳回并删除待审申请（会先邮件告知申请人）
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $row = Db::row('SELECT * FROM ' . Db::t('links') . ' WHERE id=? AND status=?', [$id, 'pending']);
                if ($row) {
                    Db::deleteBy('links', 'id=?', [$id]);
                    if (trim((string)$row['email']) !== '') {
                        $mr = yc_links_send_mail(
                            (string)$row['email'],
                            '【YeCha 收录圈】暂未收录：' . (string)$row['name'],
                            '你好 ' . (string)$row['name'] . "：\n\n很抱歉，你的博客这次未能通过收录审核。常见原因（见 https://yecha.org/submit）：免费子域名、境内访问过慢、空站或内容未成型、疑似 AI 批量生成、发布未授权资源等。\n\n可以改进后再次提交。\n\n—— YeCha 博客收录圈"
                        );
                        if ($mr['ok']) { $_SESSION['flash'] = ['m' => '已驳回删除，并已邮件告知', 'e' => true]; }
                    }
                }
            }
            header('Location: ' . $back);
            exit;
        }
        if ($do === 'circle-featured') {
            $id = (int)($_POST['id'] ?? 0);
            $on = (($_POST['on'] ?? '') === '1');
            if ($id > 0) { yc_links_featured_toggle($id, $on); }
            $_SESSION['flash'] = ['m' => $on ? '已置顶为精选' : '已取消精选', 'e' => false];
            header('Location: ' . $back);
            exit;
        }
    }

    $csrf = Csrf::field();
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId > 0 ? Db::row('SELECT * FROM ' . Db::t('links') . ' WHERE id=?', [$editId]) : null;
    $groups = Db::rows('SELECT * FROM ' . Db::t('link_groups') . ' ORDER BY sort ASC, id ASC');
    $links = Db::rows('SELECT * FROM ' . Db::t('links') . ' ORDER BY sort ASC, id ASC LIMIT 500');
    $gidOf = [];
    foreach ($groups as $g) { $gidOf[(int)$g['id']] = $g['name']; }
    $kindName = ['friend' => '友链', 'nav' => '导航', 'mixed' => '混合'];

    // 体检状态小徽标
    $probeChip = function (array $l): string {
        $map = ['ok' => ['正常', '#2e6b34'], 'err' => ['RSS 异常', '#9c6b1b'], 'down' => ['疑似不可达', '#9c3f1b'], 'no_rss' => ['未接入 RSS', '#6f767e'], '' => ['待体检', '#9a9a90']];
        [$label, $color] = $map[(string)($l['probe_state'] ?? '')] ?? ['—', '#9a9a90'];
        $note = trim((string)($l['probe_note'] ?? ''));
        $at = (string)($l['probe_at'] ?? '');
        $out = '<span style="color:' . $color . ';font-weight:600;">● ' . $label . '</span>';
        if ($note !== '') { $out .= '<div style="color:#9a9a90;font-size:11px;">' . e(mb_substr($note, 0, 90)) . '</div>'; }
        if ($at !== '') { $out .= '<div style="color:#c9c9c2;font-size:10.5px;">体检于 ' . e(substr($at, 0, 16)) . '</div>'; }
        return $out;
    };
    $clicks = [];
    if ($links && Db::rows('SHOW TABLES LIKE "' . Db::t('link_clicks') . '"')) {
        $clk = Db::rows('SELECT link_id, COUNT(*) n FROM ' . Db::t('link_clicks') . " WHERE day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY link_id");
        foreach ($clk as $c) { $clicks[(int)$c['link_id']] = (int)$c['n']; }
    }
    // 精选管理（最近 60 天文章）
    $featRows = [];
    if (Db::rows('SHOW TABLES LIKE "' . Db::t('circle_posts') . '"')) {
        $featRows = Db::rows(
            'SELECT p.id, p.title, p.url, p.featured, p.published_at, l.name AS blog, l.id AS lid'
            . ' FROM ' . Db::t('circle_posts') . ' p'
            . ' JOIN ' . Db::t('links') . ' l ON l.id = p.link_id'
            . ' WHERE p.published_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)'
            . ' ORDER BY p.featured DESC, p.featured_at DESC, p.published_at DESC LIMIT 100'
        );
    }

    $gRows = '';
    foreach ($groups as $g) {
        $cnt = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('links') . ' WHERE group_id=?', [(int)$g['id']]);
        $gRows .= '<tr><td><b>' . e($g['name']) . '</b><div style="color:#6f767e;font-size:12px;">/' . e($g['slug']) . ' · ' . $kindName[$g['kind']] . '</div></td>'
            . '<td>' . (int)$g['sort'] . '</td><td>' . $cnt . '</td>'
            . '<td>' . ((int)$g['enabled'] === 1 ? '<span style="color:#2e6b34;">✔ 启用</span>' : '<span style="color:#9a9a90;">停用</span>') . '</td>'
            . '<td style="white-space:nowrap;">'
            . '<form method="post" style="display:inline;">' . $csrf . '<input type="hidden" name="do" value="links-group-toggle"><input type="hidden" name="id" value="' . (int)$g['id'] . '"><button class="btn ghost mini pl-info">' . ((int)$g['enabled'] === 1 ? '停用' : '启用') . '</button></form> '
            . '<form method="post" style="display:inline;" onsubmit="return confirm(\'删除分组将同时删除其中链接？\')">' . $csrf . '<input type="hidden" name="do" value="links-group-del"><input type="hidden" name="id" value="' . (int)$g['id'] . '"><button class="btn ghost mini pl-del">删除</button></form></td></tr>';
    }

    $groupOpts = '<option value="0">— 选择分组 —</option>';
    foreach ($groups as $g) {
        $groupOpts .= '<option value="' . (int)$g['id'] . '"' . ($edit && (int)$edit['group_id'] === (int)$g['id'] ? ' selected' : '') . '>' . e($g['name'] . '（' . $kindName[$g['kind']] . '）') . '</option>';
    }
    $lRows = '';
    foreach ($links as $l) {
        $gname = $gidOf[(int)$l['group_id']] ?? '?';
        $n30 = $clicks[(int)$l['id']] ?? 0;
        $rssLine = (string)$l['rss'] !== ''
            ? '<div style="font-size:11px;color:#9a9a90;">RSS: <a href="' . e($l['rss']) . '" target="_blank" rel="noopener">' . e(mb_substr((string)$l['rss'], 0, 34)) . '</a></div>'
            : '';
        if ((string)($l['engine'] ?? '') !== '') {
            $rssLine .= '<div style="font-size:11px;color:#8a5a2b;">程序：' . e(yc_links_engine_label((string)$l['engine'])) . '</div>';
        }
        $lRows .= '<tr><td>' . e($l['name']) . ($l['logo'] !== '' ? '<div style="font-size:11px;color:#9a9a90;">' . e($l['logo']) . '</div>' : '')
            . '</td><td><a href="' . e($l['url']) . '" rel="noopener" target="_blank">' . e(mb_substr($l['url'], 0, 42)) . '</a>' . $rssLine . '</td>'
            . '<td>' . e($gname) . '</td>'
            . '<td>' . ((string)$l['status'] === 'show' ? '<span style="color:#2e6b34;">显示</span>' : '<span style="color:#9a9a90;">隐藏</span>') . '</td>'
            . '<td>' . $probeChip($l) . '<div style="color:#9a9a90;font-size:11px;margin-top:3px;">近30天带客 ' . $n30 . '</div></td>'
            . '<td style="white-space:nowrap;">'
            . '<a class="btn ghost mini pl-info" href="/admin/?s=plug_links&edit=' . (int)$l['id'] . '">编辑</a> '
            . '<form method="post" style="display:inline;">' . $csrf . '<input type="hidden" name="do" value="links-probe"><input type="hidden" name="id" value="' . (int)$l['id'] . '"><button class="btn ghost mini pl-info">体检</button></form> '
            . '<form method="post" style="display:inline;">' . $csrf . '<input type="hidden" name="do" value="links-link-toggle"><input type="hidden" name="id" value="' . (int)$l['id'] . '"><button class="btn ghost mini pl-info">' . ((string)$l['status'] === 'show' ? '隐藏' : '显示') . '</button></form> '
            . '<form method="post" style="display:inline;" onsubmit="return confirm(\'删除该链接？\')">' . $csrf . '<input type="hidden" name="do" value="links-link-del"><input type="hidden" name="id" value="' . (int)$l['id'] . '"><button class="btn ghost mini pl-del">删除</button></form></td></tr>';
    }

    $ef = function (string $k, string $d = '') use ($edit): string {
        $v = $edit !== null ? (string)($edit[$k] ?? $d) : $d;
        return e($v);
    };

    // ---- 待审申请（公开提交 status=pending） ----
    $pendings = Db::rows("SELECT * FROM " . Db::t('links') . " WHERE status='pending' ORDER BY id ASC LIMIT 200");
    $pendingRows = '';
    foreach ($pendings as $p) {
        $sel = '<select name="group_id" style="border:1px solid #e6e6e2;border-radius:6px;padding:4px 6px;font:inherit;">' . $groupOpts . '</select>';
        $rssCell = (string)$p['rss'] !== ''
            ? '<a href="' . e($p['rss']) . '" rel="noopener" target="_blank">' . e(mb_substr((string)$p['rss'], 0, 38)) . '</a>'
            : '<span style="color:#9a9a90;">未填</span>';
        $engLine = ((string)($p['engine'] ?? '') !== '') ? '<div style="color:#8a5a2b;font-size:11.5px;margin-top:2px;">程序：' . e(yc_links_engine_label((string)$p['engine'])) . '</div>' : '';
        $pendingRows .= '<tr><td><b>' . e($p['name']) . '</b><div style="color:#6f767e;font-size:12px;">' . e(mb_substr((string)$p['description'], 0, 80)) . '</div></td>'
            . '<td><a href="' . e($p['url']) . '" rel="noopener" target="_blank">' . e(mb_substr((string)$p['url'], 0, 40)) . '</a></td>'
            . '<td>' . e((string)$p['email']) . '<div style="color:#9a9a90;font-size:11.5px;">提交于 ' . e(substr((string)$p['created_at'], 0, 16)) . '</div></td>'
            . '<td>' . $rssCell . $engLine . '<div style="margin-top:4px;">' . $probeChip($p) . '</div></td>'
            . '<td style="white-space:nowrap;">'
            . '<form method="post" style="display:inline-flex;gap:6px;align-items:center;">' . $csrf . '<input type="hidden" name="do" value="links-approve"><input type="hidden" name="id" value="' . (int)$p['id'] . '">' . $sel
            . '<button class="btn ghost mini pl-ok" type="submit">通过并收录</button></form> '
            . '<form method="post" style="display:inline;">' . $csrf . '<input type="hidden" name="do" value="links-probe"><input type="hidden" name="id" value="' . (int)$p['id'] . '"><button class="btn ghost mini pl-info" type="submit">体检</button></form> '
            . '<form method="post" style="display:inline;" onsubmit="return confirm(\'驳回并删除该申请？（会先邮件告知申请人）\')">' . $csrf . '<input type="hidden" name="do" value="links-reject"><input type="hidden" name="id" value="' . (int)$p['id'] . '"><button class="btn ghost mini pl-del">驳回删除</button></form></td></tr>';
    }

    return '<div class="card"><h3>友链·网址导航 / 博客收录 <small>通用模块：分组 + 链接；右栏块与 /links（收录墙）、/submit（公开申请）自动生效</small></h3>'
        . '<div class="card" style="border-color:#e3c5b6;"><h3>待审申请（' . count($pendings) . '）<small>博主在 /submit 提交，选分组后「通过并收录」</small></h3>'
        . (count($pendings)
            ? '<table><tr><th>博客</th><th>网址</th><th>邮箱 / 时间</th><th>RSS / 体检</th><th>操作</th></tr>' . $pendingRows . '</table>'
            : '<div class="stub">暂无待审申请。前台 /submit 提交后在这里显示；点「体检」可先验证站点与 RSS。</div>')
        . '</div>'
        . '<div class="card" style="margin-top:16px;"><h3>新增分组</h3>'
        . '<form method="post" action="/admin/?s=plug_links" style="display:grid;grid-template-columns:repeat(4,auto);gap:8px;align-items:end;max-width:820px;">' . $csrf
        . '<input type="hidden" name="do" value="links-group-save"><input type="hidden" name="id" value="0">'
        . '<div><label class="pf">分组名 *</label><input name="name" required></div>'
        . '<div><label class="pf">slug（留空自动）</label><input name="slug"></div>'
        . '<div><label class="pf">类型</label><select name="kind"><option value="friend">友链</option><option value="nav">网址导航</option><option value="mixed">混合</option></select></div>'
        . '<div><label class="pf">排序</label><input name="sort" type="number" value="0"></div>'
        . '<div style="grid-column:1/-1;"><button class="btn" type="submit">＋ 新增分组</button></div></form></div>'
        . '<div class="card"><h3>分组</h3><table><tr><th>分组</th><th>排序</th><th>链接</th><th>状态</th><th>操作</th></tr>'
        . ($gRows !== '' ? $gRows : '<tr><td colspan="5" class="hint">还没有分组，先在上方新增（示例：茶友博客(kind=友链)、常用工具(kind=导航)）。</td></tr>')
        . '</table></div>'
        . '<div class="card"><h3>' . ($edit !== null ? '编辑链接' : '新增链接') . ' <small>填入分组/名称/网址，保存后即可在右栏块与 /links 页展示</small></h3>'
        . '<form method="post" action="/admin/?s=plug_links">' . $csrf
        . '<input type="hidden" name="do" value="links-link-save">'
        . '<input type="hidden" name="id" value="' . ($edit !== null ? (int)$edit['id'] : 0) . '">'
        . '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0 16px;max-width:860px;">'
        . '<div><label class="pf">分组 *</label><select name="group_id">' . $groupOpts . '</select></div>'
        . '<div><label class="pf">名称 *</label><input name="name" required value="' . $ef('name') . '"></div>'
        . '<div><label class="pf">网址 * https://</label><input name="url" required value="' . $ef('url') . '"></div>'
        . '<div><label class="pf">RSS / Feed（选填，自动体检）</label><input name="rss" value="' . $ef('rss') . '" placeholder="https://example.com/feed"></div>'
        . '<div><label class="pf">博客程序（选填）</label><select name="engine">' . yc_links_engine_options((string)$ef('engine', '')) . '</select></div>'
        . '<div><label class="pf">Logo（可选，图片URL）</label><input name="logo" value="' . $ef('logo') . '"></div>'
        . '<div><label class="pf">简介</label><input name="description" value="' . $ef('description') . '"></div>'
        . '<div><label class="pf">排序 / 状态</label><span style="display:flex;gap:8px;"><input name="sort" type="number" value="' . $ef('sort', '0') . '" style="width:80px;"> '
        . '<select name="status"><option value="show"' . ($edit === null || $edit['status'] === 'show' ? ' selected' : '') . '>显示</option><option value="hide"' . ($edit !== null && $edit['status'] === 'hide' ? ' selected' : '') . '>隐藏</option></select></span></div>'
        . '</div><div style="margin-top:10px;"><button class="btn" type="submit">保存链接</button>'
        . ($edit !== null ? ' <a class="btn ghost" href="/admin/?s=plug_links">取消编辑</a>' : '')
        . '</div></form></div>'
        . '<div class="card"><h3>全部链接（' . count($links) . '）</h3><table><tr><th>名称</th><th>网址 / RSS</th><th>分组</th><th>状态</th><th>体检 / 近30天带客</th><th>操作</th></tr>'
        . ($lRows !== '' ? $lRows : '<tr><td colspan="6" class="hint">还没有链接。</td></tr>')
        . '</table></div>'
        . '<div class="card"><h3>月度精选管理（' . count($featRows) . ' 篇近60天） <small>置顶喜欢的文章，前台 /circle 与 /selected 顶部展示；发刊：cron/手动跑 <code>php tools/links-monthly.php --ym=YYYY-MM</code></small></h3>'
        . ($featRows
            ? '<table><tr><th>文章</th><th>博客</th><th>时间</th><th>精选</th></tr>'
                . implode('', array_map(function ($r) use ($csrf) {
                    $star = (int)$r['featured'] === 1 ? '★ ' : '';
                    return '<tr><td>' . $star . '<a href="' . e($r['url']) . '" rel="noopener" target="_blank">' . e(mb_substr((string)$r['title'], 0, 60)) . '</a></td>'
                        . '<td><a href="/admin/?s=plug_links&edit=' . (int)$r['lid'] . '">' . e($r['blog']) . '</a></td>'
                        . '<td>' . e(substr((string)$r['published_at'], 0, 10)) . '</td>'
                        . '<td style="white-space:nowrap;">'
                        . ((int)$r['featured'] === 1
                            ? '<form method="post" style="display:inline;">' . $csrf . '<input type="hidden" name="do" value="circle-featured"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="on" value="0"><button class="btn ghost mini">取消精选</button></form>'
                            : '<form method="post" style="display:inline;">' . $csrf . '<input type="hidden" name="do" value="circle-featured"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="on" value="1"><button class="btn ghost mini">置顶精选</button></form>')
                        . '</td></tr>';
                }, $featRows))
            : '<div class="stub">还没有聚合到的文章（先跑 tools/links-fetch.php）。</div>')
        . '</div>'
        . '<div class="card"><h3>前台说明</h3><ul style="margin:0;padding-left:20px;line-height:1.9;color:#565b61;font-size:13.5px;">'
        . '<li>独立页：<a target="_blank" href="/links">/links</a>（收录墙，卡片出站统一走 /go 计数）、<a target="_blank" href="/submit">/submit</a>（公开申请，可填 RSS）、<a target="_blank" href="/random">/random</a>（随机串门）。</li>'
        . '<li>流量回执：每个收录博客的卡片下方小字可进入公开 <b>/stats?id=…</b> 页（近30天被带走的真实访客 + 站点状态/最近更新 + 已收录文章）；页内可领取「已收录」徽标（/certificate?id=…）。</li>'
        . '<li>RSS 温柔聚合：cron 跑 <code>php tools/links-fetch.php</code>（建议每小时）把新文章元数据存入 ye_circle_posts → <a target="_blank" href="/circle">/circle</a> 最新文章流、<a target="_blank" href="/selected">/selected</a> 月度回看、/stats 文章列表自动出现（只存标题/摘要/链接）。</li>'
        . '<li>机器体检：pending 或 show 链接都可点「体检」；也可 cron 跑 <code>php tools/links-probe.php</code>（建议每 6 小时）全量体检；连续不可达会按冷却期邮件提醒站主。</li>'
        . '<li>邮件通知：通过/驳回/月度精选订阅，均走后台「设置→邮件通知」的 SMTP（mail.enabled=1 才真正发送，否则自动跳过不报错）。</li>'
        . '<li>右栏块：到「页面布局」为各页面勾选「友链墙 / 网址导航」即可（按分组类型过滤）。</li></ul></div>'
        . '<style>.pf{display:block;font-size:12.5px;color:#6f767e;margin:4px 0 2px}.pf+input,.pf+select{width:100%;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font:inherit}'
        . '.btn.ghost.mini.pl-ok{background:#2e6b34;color:#fff;border:1px solid #2e6b34;font-weight:600}'
        . '.btn.ghost.mini.pl-info{background:#fff;color:#1f2328;border:1px solid #8a94a0;font-weight:500}'
        . '.btn.ghost.mini.pl-del{background:#fff;color:#b3261e;border:1px solid #d9a49e;font-weight:600}'
        . '.btn.ghost.mini.pl-ok:hover{background:#245b2a}'
        . '.btn.ghost.mini.pl-info:hover{border-color:#4a5560;background:#f4f6f8}'
        . '.btn.ghost.mini.pl-del:hover{background:#fdf0ef;border-color:#b3261e}</style>';
}
