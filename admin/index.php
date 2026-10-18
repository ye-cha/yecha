<?php
declare(strict_types=1);
/** YeCha 后台：登录（CSRF+防爆破+回跳）→ 左侧导航框架 + 各模块 */

$ROOT = dirname(__DIR__);
if (!is_file($ROOT . '/config.inc.php')) {
    header('Location: /install/');
    exit;
}
require $ROOT . '/config.inc.php';
require $ROOT . '/core/bootstrap.php';
require __DIR__ . '/posts.inc.php';
// 多语言已为云市场插件：poly.inc 随插件存在（兼容：若在则加载）
if (is_file(__DIR__ . '/poly.inc.php')) { require __DIR__ . '/poly.inc.php'; }
require __DIR__ . '/metas.inc.php';
require __DIR__ . '/notes.inc.php';
Plugins::boot(); // 加载第三方插件（Hooks 注册等）

$action = $_GET['action'] ?? '';
$sec = preg_replace('/[^a-z_]/', '', (string)($_GET['s'] ?? 'dashboard'));
$msg = $_SESSION['flash'] ?? '';
$msgErr = false;
if (is_array($msg)) { $msgErr = !empty($msg['e']); $msg = (string)($msg['m'] ?? ''); }
elseif ($msg !== '' && preg_match('/(失败|不正确|至少|不一致|缺失|请勾选|不存在|不可|无法|未选择|不能为空|错误)/u', (string)$msg)) { $msgErr = true; }
unset($_SESSION['flash']);

if ($action === 'logout') {
    Auth::logout();
    header('Location: /admin/');
    exit;
}

$authed = Auth::check();
$isAdmin = $authed && Auth::isAdmin();

if (!$authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Csrf::check();
    $res = Auth::attempt((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
    if ($res['ok']) {
        $_SESSION['flash'] = '欢迎回来，' . ($res['user']['display_name'] ?: $res['user']['username']);
        header('Location: /admin/');
        exit;
    }
    $msg = $res['error'];
}

if ($authed && !$isAdmin) {
    http_response_code(403);
    exit('仅管理员可访问后台');
}

/* ---------- 模块定义 ---------- */

/** 生成全库 SQL 备份（当前前缀全部表：结构 + 数据）并返回文件路径 */
function tools_backup(): string
{
    $prefix = cfg('DB_PREFIX', 'ye_');
    $dir = dirname(__DIR__) . '/var/backup';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $file = $dir . '/yecha-' . date('Ymd-His') . '.sql';
    $out = "-- YeCha 数据库备份  " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\n\n";
    $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix) . '%';
    $tables = Db::rows("SHOW TABLES LIKE '" . $pattern . "'");
    foreach ($tables as $t) {
        $tbl = (string)reset($t);
        if ($tbl === '') { continue; }
        $st = Db::run('SHOW CREATE TABLE `' . $tbl . '`')->fetch(PDO::FETCH_NUM);
        $out .= "\n-- Table: $tbl\n" . ($st[1] ?? '') . ";\n";
        $rows = Db::rows('SELECT * FROM `' . $tbl . '`');
        foreach ($rows as $row) {
            $cols = array_keys($row);
            $vals = [];
            foreach (array_values($row) as $v) {
                $vals[] = $v === null ? 'NULL' : Db::pdo()->quote((string)$v);
            }
            $out .= "INSERT INTO `$tbl` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ");\n";
        }
    }
    file_put_contents($file, $out);
    return $file;
}

/** 引号感知的 SQL 语句切分（备份还原用） */
function yc_sql_split(string $sql): array
{
    $out = [];
    $len = strlen($sql);
    $cur = '';
    $q = null; // null | '\'' | '"' | '`'
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($q !== null) {
            $cur .= $ch;
            if ($ch === '\\') { if ($i + 1 < $len) { $cur .= $sql[++$i]; } continue; }
            if ($ch === $q) { $q = null; }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $q = $ch; $cur .= $ch; continue; }
        if ($ch === ';') {
            $t = trim($cur);
            if ($t !== '') { $out[] = $t; }
            $cur = '';
            continue;
        }
        if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {
            while ($i < $len && $sql[$i] !== "\n") { $i++; }
            continue;
        }
        $cur .= $ch;
    }
    $t = trim($cur);
    if ($t !== '') { $out[] = $t; }
    return $out;
}

$MODULES = [
    'dashboard'   => ['仪表盘', '统计与最近动态'],
    'posts'       => ['文章', '文章列表 / 编辑'],
    'pages'       => ['页面', '页面管理'],
    'metas'       => ['分类标签', '分类 / 标签管理'],
    'poly'        => ['多语', '英文翻译组 + AI 翻译'],
    'notes'       => ['记事本', '私人快速笔记（P-3）'],
    'comments'    => ['评论', '评论与段评审核'],
    'users'       => ['用户', '注册用户管理'],
    'attachments' => ['附件', '附件与图片'],
    'themes'      => ['主题', '主题 / 布局'],
    'layouts'     => ['页面布局', '首页/文章/搜索等右栏与功能块控制'],
    'upgrade'     => ['系统升级', '版本 / 一键升级（自动备份）'],
    'plugins'     => ['插件', '插件管理（P-1~P-7）'],
    'market'      => ['云市场', '插件 / 主题在线安装'],
    'settings'    => ['设置', '站点 / SEO / 统计 / 多语'],
    'tools'       => ['工具', '修复 / 备份 / 缓存'],
];
/* 第三方插件（已启用且带后台菜单）追加到导航 */
foreach (Plugins::activeMenus() as $_pid => $_pm) {
    $MODULES['plug_' . $_pid] = [$_pm['label'], $_pm['desc']];
}

/* 停用的内置插件：从后台导航与功能入口中隐藏（数据不动，可随时启用） */
foreach (['notes', 'poly'] as $_builtin) {
    if (!Plugins::isOn($_builtin)) { unset($MODULES[$_builtin]); }
}

/* ---------- 写操作：评论审核 / 内容保存删除 / 上传 / 缓存清理 ---------- */
if ($authed && $isAdmin && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Csrf::check();
    $do = $_POST['do'] ?? '';
    if ($do === 'plugin-toggle') {
        $id = (string)($_POST['id'] ?? '');
        $d = Plugins::def($id);
        $enable = (($_POST['enabled'] ?? '') === '1');
        if ($enable && Plugins::removed($id)) {
            $_SESSION['flash'] = '该插件已卸载：请到「云市场」重装，或先用备份恢复数据表/选项';
            header('Location: /admin/?s=plugins');
            exit;
        }
        if ($enable && Plugins::isExternal($id)) {
            // 第三方：先应用插件自带迁移（plugins/<id>/migrations），再置启用
            set_option('plugin.' . $id . '.removed', '0');
            $r = Upgrade::apply($id, fn(string $s) => Upgrade::log('  ' . $s));
            if ($r['error']) {
                $_SESSION['flash'] = '插件迁移失败：' . $r['error'];
                header('Location: /admin/?s=plugins');
                exit;
            }
        } elseif ($enable && $d !== null && !empty($d['toggle'])) {
            $info = Plugins::uninstallInfo($id);
            foreach ($info['tables'] ?? [] as $tb) {
                if (!Plugins::tableExists($tb)) {
                    $_SESSION['flash'] = '插件数据表 ' . $tb . ' 缺失：请恢复备份或重装后启用';
                    header('Location: /admin/?s=plugins');
                    exit;
                }
            }
        }
        if ($d !== null && !empty($d['toggle'])) {
            set_option('plugin.' . $id . '.enabled', $enable ? '1' : '0');
        }
        Cache::flush('pages');
        Cache::flush('blocks');
        $_SESSION['flash'] = '插件状态已更新';
        header('Location: /admin/?s=plugins');
        exit;
    }
    if ($do === 'admin-password') {
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (!Auth::verifyPassword(Auth::id(), $old)) {
            $_SESSION['flash'] = '旧密码不正确';
        } elseif (mb_strlen($new) < 6) {
            $_SESSION['flash'] = '新密码至少 6 位';
        } elseif ($new !== $confirm) {
            $_SESSION['flash'] = '两次输入的新密码不一致';
        } else {
            Auth::setPassword(Auth::id(), $new);
            Cache::flush('pages');
            Cache::flush('blocks');
            $_SESSION['flash'] = '密码已更新，下次登录请使用新密码';
        }
        header('Location: /admin/?s=settings');
        exit;
    }
    if ($do === 'attach-del') {
        $rel = trim((string)($_POST['path'] ?? ''));
        $rootUp = dirname(__DIR__) . '/uploads/';
        if ($rel !== '' && strpos($rel, '..') === false) {
            $real = realpath($rootUp . $rel);
            if ($real !== false && str_starts_with($real, realpath($rootUp) ?: $rootUp) && is_file($real)) {
                @unlink($real);
                Db::deleteBy('attachments', 'file_path=?', [$rel]);
                Cache::flush('pages');
                Cache::flush('blocks');
                $_SESSION['flash'] = '已删除附件：' . $rel;
            }
        }
        header('Location: /admin/?s=attachments');
        exit;
    }
    if ($do === 'backup-del') {
        $name = basename((string)($_POST['file'] ?? ''));
        if (preg_match('/^yecha-\d{8}-\d{6}\.sql$/', $name)) {
            $f = dirname(__DIR__) . '/var/backup/' . $name;
            if (is_file($f)) { @unlink($f); $_SESSION['flash'] = '已删除备份：' . $name; }
        }
        header('Location: /admin/?s=tools');
        exit;
    }
    if ($do === 'market-source') {
        set_option('market.source', trim((string)($_POST['source'] ?? '')));
        Cache::flush('pages');
        Cache::flush('blocks');
        $_SESSION['flash'] = '市场源已保存（留空=内置本地源）';
        header('Location: /admin/?s=market');
        exit;
    }
    if ($do === 'market-install') {
        $id = (string)($_POST['id'] ?? '');
        $type = ($_POST['type'] ?? '') === 'theme' ? 'theme' : 'plugin';
        try {
            $items = Market::manifest();
            if (!isset($items[$id]) || $items[$id]['type'] !== $type) {
                throw new RuntimeException('清单中未找到 ' . $id);
            }
            $res = Market::install($items[$id]);
            $name = $items[$id]['name'] ?? $id;
            $_SESSION['flash'] = '已从市场安装' . ($type === 'theme' ? '主题' : '插件') . '：' . $name . '（v' . $items[$id]['ver'] . '）' . ($type === 'plugin' ? '，已自动启用' : '');
            if ($type === 'theme') { set_option('theme.default', $res['id']); Cache::flush('pages'); Cache::flush('blocks'); }
        } catch (Throwable $e) {
            $_SESSION['flash'] = '安装失败：' . $e->getMessage();
        }
        header('Location: /admin/?s=market');
        exit;
    }
    if ($do === 'market-theme-set') {
        $id = (string)($_POST['id'] ?? '');
        $themes = Market::themes();
        if ($id !== '' && isset($themes[$id])) {
            set_option('theme.default', $id);
            Cache::flush('pages');
            Cache::flush('blocks');
            $_SESSION['flash'] = '已切换主题：' . $themes[$id];
        }
        header('Location: /admin/?s=market');
        exit;
    }
    if ($do === 'upgrade-run') {
        $comp = (string)($_POST['component'] ?? '');
        if (!isset(Upgrade::COMPONENTS[$comp]) || ($_POST['confirm'] ?? '') !== '1') {
            $_SESSION['flash'] = '请勾选备份确认并选择有效组件';
            header('Location: /admin/?s=upgrade');
            exit;
        }
        // 安全：先自动全库备份
        $bk = tools_backup();
        Upgrade::log('=== upgrade ' . $comp . ' backup=' . basename($bk) . ' by ' . (Auth::user()['username'] ?? '?') . ' ===');
        $res = Upgrade::apply($comp, fn(string $s) => Upgrade::log('  ' . $s));
        $msg = '备份：' . basename($bk) . '；已应用迁移 ' . count($res['applied']) . ' 条';
        if ($res['applied']) { $msg .= '（' . implode(', ', array_map(fn($n) => basename($n), $res['applied'])) . '）'; }
        if ($res['error']) { $msg .= '；失败：' . $res['error']; }
        Cache::flush('pages');
        Cache::flush('blocks');
        $_SESSION['flash'] = $msg;
        header('Location: /admin/?s=upgrade');
        exit;
    }
    if ($do === 'upgrade-remote') {
        $rm = Upgrade::remoteStatus();
        if (!$rm || !Upgrade::isUpdateAvailable($rm)) {
            $_SESSION['flash'] = '当前已是最新或未配置更新源';
            header('Location: /admin/?s=upgrade');
            exit;
        }
        $bk = tools_backup();
        Upgrade::log('=== remote upgrade to ' . (string)$rm['version'] . ' backup=' . basename($bk) . ' by ' . (Auth::user()['username'] ?? '?') . ' ===');
        $res = Upgrade::applyRemote($rm);
        $_SESSION['flash'] = $res['ok']
            ? ('升级成功：' . $res['msg'] . ' 备份：' . basename($bk))
            : ('升级失败：' . ($res['msg'] ?? '未知错误') . '（备份：' . basename($bk) . '，可回滚）');
        header('Location: /admin/?s=upgrade');
        exit;
    }
    if ($do === 'upgrade-config') {
        set_option('upgrade.manifest_url', trim((string)($_POST['manifest_url'] ?? '')));
        $_SESSION['flash'] = '更新源设置已保存（上线后填写 update/manifest.json 或 GitHub raw 地址）';
        header('Location: /admin/?s=upgrade');
        exit;
    }
    if ($do === 'plugin-uninstall') {
        $id = (string)($_POST['id'] ?? '');
        /* 卸载语义（内核合流 2026-09-26，来源：yecha-dev admin/index.php）：
           purge=1 → 卸载并删除插件自有数据表与配置；purge=0 → 仅卸载（停用并移出列表，数据与配置保留，
           可随时重装继续用）。改前**任何卸载都 DROP 表**，是破坏性操作。 */
        $purge = (($_POST['purge'] ?? '') === '1');
        $info = Plugins::uninstallInfo($id);
        if (!$info) {
            $_SESSION['flash'] = '该插件为常驻能力或不存在，不可卸载';
            header('Location: /admin/?s=plugins');
            exit;
        }
        // 1) 自动全库备份（任何卸载都先备份）
        $bk = tools_backup();
        if ($purge) {
            // 2) 卸载并删除配置：清理插件自有数据表
            foreach (($info['tables'] ?? []) as $tb) {
                $t = Db::t($tb);
                Db::run("DROP TABLE IF EXISTS {$t}");
            }
            // 3) 清理插件自有选项（精确 + LIKE 前缀）
            foreach (($info['options'] ?? []) as $name) {
                Db::deleteBy('options', 'name=?', [$name]);
            }
            foreach (($info['like'] ?? []) as $pat) {
                Db::run("DELETE FROM " . Db::t('options') . " WHERE name LIKE ?", [$pat]);
            }
        }
        // 4) 标记已卸载（恒停用，数据不再被引用；仅卸载时表与选项保留）
        set_option('plugin.' . $id . '.removed', '1');
        Cache::flush('pages');
        Cache::flush('blocks');
        $_SESSION['flash'] = $purge
            ? ('插件 ' . $id . ' 已卸载并删除配置（备份：' . basename($bk) . '；如需恢复请还原该备份）')
            : ('插件 ' . $id . ' 已卸载，数据与配置已保留（备份：' . basename($bk) . '；重装后可继续使用）');
        header('Location: /admin/?s=plugins');
        exit;
    }
    if ($do === 'comment-status' && isset($_POST['cid'], $_POST['st'])) {
        Db::update('comments', ['status' => (string)$_POST['st']], 'id=?', [(int)$_POST['cid']]);
        Cache::flush('pages');
        if ($_POST['st'] === 'approved') { Uc::notifyMentions((int)$_POST['cid']); }
        $_SESSION['flash'] = '评论状态已更新';
        header('Location: /admin/?s=comments');
        exit;
    }
    if ($do === 'post-save') {
        pc_save();
    }
    if ($do === 'post-trash') {
        pc_trash();
    }
    if ($do === 'trash-restore' || $do === 'trash-purge') {
        pc_trash_action((($_POST['type'] ?? '') === 'page') ? 'page' : 'post', $do === 'trash-restore' ? 'restore' : 'purge', (int)($_POST['cid'] ?? 0));
        header('Location: /admin/?s=' . ((($_POST['type'] ?? '') === 'page') ? 'pages' : 'posts') . '&st=trash');
        exit;
    }
    if ($do === 'poly-en-save') {
        pc_poly_save();
    }
    if ($do === 'poly-ai-en') {
        pc_poly_ai();
    }
    if ($do === 'poly-ai-row') {
        pc_ai_row();   // 文章/页面列表里的「AI 翻译」按钮
    }
    if ($do === 'upload') {
        pc_upload();
    }
    if (in_array($do, ['poly-config', 'poly-save', 'ai-generate', 'poly-batch', 'poly-langs', 'poly-modes'], true)) {
        poly_do($do);
    }
    if (in_array($do, ['meta-save', 'meta-delete'], true)) {
        metas_do($do);
    }
    if ($do === 'user-status' || $do === 'user-role') {
        $uid = (int)($_POST['uid'] ?? 0);
        $self = $uid === Auth::id();
        if ($uid <= 0 || $self) {
            $_SESSION['flash'] = '不可操作当前账号';
        } elseif ($do === 'user-status') {
            $st = (($_POST['status'] ?? '') === 'disabled') ? 'disabled' : 'normal';
            Db::update('users', ['status' => $st], 'id=?', [$uid]);
            Cache::flush('pages');
            Cache::flush('blocks');
            $_SESSION['flash'] = ($st === 'normal' ? '已恢复' : '已禁用') . '该账号';
        } else {
            $role = (($_POST['role'] ?? '') === 'admin') ? 'admin' : 'subscriber';
            Db::update('users', ['role' => $role], 'id=?', [$uid]);
            $_SESSION['flash'] = '角色已设为' . ($role === 'admin' ? '管理员' : '注册用户');
        }
        header('Location: /admin/?s=users');
        exit;
    }
    if (in_array($do, ['note-save', 'note-delete'], true)) {
        if (!Plugins::isOn('notes')) {
            $_SESSION['flash'] = '记事本插件已停用，无法保存笔记';
            header('Location: /admin/?s=plugins');
            exit;
        }
        notes_do($do);
    }
    if ($do === 'settings-save') {
        $style = ($_POST['home_style'] ?? '') === 'img' ? 'img' : 'text';
        set_option('home.style', $style);
        set_option('site.subtitle', trim((string)($_POST['site_subtitle'] ?? '')));
        set_option('site.description', trim((string)($_POST['site_description'] ?? '')));
        Cache::flush('pages');
        Cache::flush('blocks');
        $_SESSION['flash'] = '设置已保存（首页版式：' . ($style === 'img' ? '图文列表' : '文字列表') . '）';
        header('Location: /admin/?s=settings');
        exit;
    }
    if ($do === 'logo-upload') {
        $err = 0;
        $f = $_FILES['logo_file'] ?? null;
        if (!$f || (int)($f['error'] ?? 1) !== UPLOAD_ERR_OK) { $err = (int)($f['error'] ?? 1); }
        if ($err === 0) {
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) { $err = -1; }
        }
        if ($err === 0) {
            $rel = 'uploads/' . date('Y/m');
            $dir = dirname(__DIR__) . '/' . $rel;
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
            $dest = $dir . '/logo-upload.' . $ext;
            if (!@move_uploaded_file((string)$f['tmp_name'], $dest)) { $err = -2; }
            else {
                // 自动优化：PNG 等比缩到 ≤100px（显示 46px，足够清晰且体积最小）并重压缩（保留透明通道）
                if ($ext === 'png' && function_exists('imagecreatefrompng')) {
                    $im = @imagecreatefrompng($dest);
                    if ($im) {
                        $sw = imagesx($im); $sh = imagesy($im);
                        if ($sw > 100 || $sh > 100) {
                            $scale = min(1, 100 / max($sw, $sh));
                            $nw = max(1, (int)($sw * $scale)); $nh = max(1, (int)($sh * $scale));
                            $res = imagecreatetruecolor($nw, $nh);
                            imagealphablending($res, false); imagesavealpha($res, true);
                            imagecopyresampled($res, $im, 0, 0, 0, 0, $nw, $nh, $sw, $sh);
                            imagedestroy($im);
                            imagepng($res, $dest, 9);
                            imagedestroy($res);
                        } else {
                            imagepng($im, $dest, 9);
                            imagedestroy($im);
                        }
                    }
                }
                set_option('site.logo', '/' . $rel . '/logo-upload.' . $ext);
                Cache::flush('pages');
                Cache::flush('blocks');
                $_SESSION['flash'] = '站点 Logo 已上传并启用（大图自动缩至 ≤100px、PNG 重压缩，透明保留）：/' . $rel . '/logo-upload.' . $ext;
                header('Location: /admin/?s=settings');
                exit;
            }
        }
        $msg = ['UPLOAD_ERR_NO_FILE', 'size超限', 'partial', 'no_file_selected', 'no_tmp', 'write_fail', 'ext_denied'][$err] ?? ('code ' . $err);
        $_SESSION['flash'] = 'Logo 上传失败：' . $msg . '（请换 PNG/JPG，控制文件大小）';
        header('Location: /admin/?s=settings');
        exit;
    }
    if ($do === 'misc-save') {
        $f = fn(string $k) => trim((string)($_POST[$k] ?? ''));
        set_option('blogger.nick', $f('blogger_nick'));
        set_option('blogger.bio', $f('blogger_bio'));
        $defLang = Poly::defaultLang();
        foreach (Poly::enabled() as $lc) {
            if ($lc === $defLang) { continue; }
            set_option('blogger.nick_' . $lc, $f('blogger_nick_' . $lc));
            set_option('blogger.bio_' . $lc, $f('blogger_bio_' . $lc));
        }
        set_option('site.icp', $f('site_icp'));
        set_option('site.icp_link', $f('site_icp_link') !== '' ? $f('site_icp_link') : 'https://beian.miit.gov.cn/');
        set_option('site.logo', $f('site_logo'));
        set_option('stats.head', (string)($_POST['stats_head'] ?? ''));
        set_option('stats.foot', (string)($_POST['stats_foot'] ?? ''));
        Cache::flush('pages');
        Cache::flush('blocks');
        $_SESSION['flash'] = '博主信息 / 备案 / 统计代码已保存';
        header('Location: /admin/?s=settings');
        exit;
    }
    if ($do === 'layouts-save') {
        $types = ['home', 'post', 'page', 'tag', 'category', 'archives', 'search'];
        $allBlocks = array_merge(['search', 'blogger', 'cats', 'latest', 'tags', 'archives'], array_keys(Plugins::sideBlocks()));
        $out = [];
        foreach ($types as $tp) {
            $show = ($_POST["sb_show_$tp"] ?? '') === '1' ? '1' : '0';
            // 优先读用户排好序的顺序字段（逗号分隔；空串=用户主动清空该页全部块）
            $blocks = [];
            $hasOrder = array_key_exists("sb_order_$tp", $_POST);
            $orderRaw = trim((string)($_POST["sb_order_$tp"] ?? ''));
            if ($orderRaw !== '') {
                foreach (explode(',', $orderRaw) as $bRaw) {
                    $b = trim((string)$bRaw);
                    if ($b !== '' && in_array($b, $allBlocks, true) && !in_array($b, $blocks, true)) { $blocks[] = $b; }
                }
            }
            if (!$hasOrder) {
                // 旧版表单（纯勾选、无排序字段）：按固定顺序收集勾选项
                foreach ($allBlocks as $b) {
                    if (isset($_POST["sb_blk_{$tp}_{$b}"])) { $blocks[] = $b; }
                }
            }
            if ($show === '1' && !$hasOrder && !$blocks) { $blocks = ['search']; }
            $out[$tp] = ['show' => $show, 'blocks' => $blocks];
        }
        set_option('theme.sidebar', json_encode($out, JSON_UNESCAPED_UNICODE));
        Cache::flush('pages');
        Cache::flush('blocks');
        $_SESSION['flash'] = '页面布局 / 右栏功能块已保存：顺序、启停即时生效（默认语言页面生效；多语言界面保持轻量侧栏）';
        header('Location: /admin/?s=layouts');
        exit;
    }
    if ($do === 'layouts-mode') {
        $mode = (($_POST['sidebar_mode'] ?? '') === 'sticky') ? 'sticky' : 'flow';
        set_option('theme.sidebar_mode', $mode);
        Cache::flush('pages');
        Cache::flush('blocks');
        $_SESSION['flash'] = $mode === 'sticky' ? '右侧栏已设为「固定+栏内滚动」' : '右侧栏已设为「普通（随页面滚动、无内滚动条）」';
        header('Location: /admin/?s=layouts');
        exit;
    }
    if ($do === 'comment-guard-save') {
        set_option('comment.blacklist', trim((string)($_POST['blacklist'] ?? '')));
        set_option('comment.max_links', max(0, (int)($_POST['max_links'] ?? 3)));
        set_option('comment.banned', trim((string)($_POST['banned'] ?? '')));
        $_SESSION['flash'] = '评论防骚扰（P-4）设置已保存';
        header('Location: /admin/?s=settings');
        exit;
    }
    if ($do === 'backup-create') {
        $f = tools_backup();
        $_SESSION['flash'] = '备份已生成：' . basename($f);
        header('Location: /admin/?s=tools');
        exit;
    }
    if ($do === 'mail-save') {
        $passIn = trim((string)($_POST['pass'] ?? ''));
        if ($passIn !== '' && !str_starts_with($passIn, 'enc:')) { yc_opt_set('mail.pass', $passIn); } // 留空=保留
        foreach (['enabled', 'host', 'port', 'ssl', 'user', 'from', 'notify_to'] as $k) {
            set_option('mail.' . $k, $k === 'enabled' ? ((($_POST['enabled'] ?? '') === '1') ? '1' : '0') : trim((string)($_POST[$k] ?? '')));
        }
        $_SESSION['flash'] = '邮件通知设置已保存（SMTP 密码不回显；留空表示不修改）';
        header('Location: /admin/?s=settings');
        exit;
    }
    if ($do === 'theme-save') {
        // 声明式保存：仅收集当前主题 theme.json settings 声明的字段
        $cur = (string)get_option('theme.default', 'yecha');
        $meta = theme_meta($cur);
        $kv = [];
        foreach ($meta['settings'] as $s) {
            $k = (string)($s['key'] ?? '');
            if ($k === '') { continue; }
            $t = (string)($s['type'] ?? 'text');
            if ($t === 'checkbox') { $kv[$k] = isset($_POST[$k]) && (string)($_POST[$k] ?? '') === '1' ? '1' : '0'; continue; }
            $val = (string)($_POST[$k] ?? '');
            if ($t === 'number') {
                $kv[$k] = (string)max((int)($s['min'] ?? 0), min((int)($s['max'] ?? 999999), (int)$val));
                continue;
            }
            if ($t === 'select') {
                $allowed = array_map('strval', array_keys((array)($s['opts'] ?? [])));
                $kv[$k] = in_array($val, $allowed, true) ? $val : ((string)($s['def'] ?? ''));
                continue;
            }
            if ($t === 'color' || $k === 'accent') { $kv[$k] = preg_match('/^#[0-9a-fA-F]{3,8}$/', $val) ? $val : ''; continue; }
            $kv[$k] = trim($val);
        }
        theme_opt_set($kv);
        Cache::flush('pages');
        Cache::flush('blocks');
        $_SESSION['flash'] = '主题设置已保存并生效';
        header('Location: /admin/?s=themes');
        exit;
    }
    if ($do === 'backup-restore') {
        // 优先上传文件，否则选择已有备份
        $file = '';
        if (!empty($_FILES['restore_file']) && ($_FILES['restore_file']['error'] ?? 1) === UPLOAD_ERR_OK && str_ends_with(strtolower((string)$_FILES['restore_file']['name']), '.sql')) {
            $dir = dirname(__DIR__) . '/var/backup';
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
            $file = $dir . '/restore-' . date('Ymd-His') . '.sql';
            move_uploaded_file($_FILES['restore_file']['tmp_name'], $file);
        } else {
            $sel = (string)($_POST['file'] ?? '');
            if (preg_match('/^(?:yecha|restore)-\d{8}-\d{6}\.sql$/', $sel)) {
                $f2 = dirname(__DIR__) . '/var/backup/' . $sel;
                if (is_file($f2)) { $file = $f2; }
            }
        }
        if ($file === '' || !is_file($file)) {
            $_SESSION['flash'] = '未选择有效备份文件';
            header('Location: /admin/?s=tools');
            exit;
        }
        $okN = 0; $errN = 0; $errMsg = [];
        foreach (yc_sql_split((string)file_get_contents($file)) as $st) {
            try { Db::run($st); $okN++; }
            catch (Throwable $ex) { $errN++; if (count($errMsg) < 3) { $errMsg[] = mb_substr($ex->getMessage(), 0, 120); } }
        }
        Cache::flush('pages');
        Cache::flush('blocks');
        $_SESSION['flash'] = "还原完成：成功 {$okN} 条，失败 {$errN} 条" . ($errMsg ? '（' . implode(' | ', $errMsg) . '）' : '');
        header('Location: /admin/?s=tools');
        exit;
    }
    if ($do === 'flush-cache') {
        Cache::flush('pages');
        Cache::flush('blocks');
        $_SESSION['flash'] = '页面缓存与区块缓存已清空';
        header('Location: /admin/?s=' . $sec);
        exit;
    }
}

/* ---------- 后台布局 ---------- */
function adm_render(string $title, string $section, string $content): void
{
    global $MODULES;
    $T = Db::t('');
    $postN = (int)Db::one("SELECT COUNT(*) FROM {$T}contents WHERE type='post' AND deleted_at IS NULL");
    $pendN = (int)Db::one("SELECT COUNT(*) FROM {$T}comments WHERE status='pending'");
    $userN = (int)Db::one("SELECT COUNT(*) FROM {$T}users WHERE role='subscriber'");
    $me = Auth::user();
    $navHtml = '';
    foreach ($MODULES as $key => $m) {
        $label = $m[0];
        $badge = '';
        if ($key === 'comments' && $pendN > 0) { $badge = '<span class="b">' . $pendN . '</span>'; }
        elseif ($key === 'posts') { $badge = '<span class="b dim">' . $postN . '</span>'; }
        elseif ($key === 'users' && $userN > 0) { $badge = '<span class="b dim">' . $userN . '</span>'; }
        $active = $key === $section ? ' class="active"' : '';
        $navHtml .= '<li><a' . $active . ' href="/admin/?s=' . $key . '">' . e($label) . $badge . '</a></li>';
    }
    $name = e((string)($me['display_name'] ?: $me['username'] ?? ''));
    // 后台 Logo 与前台一致：跟随 site.logo（无则主题默认）
    $admLogo = trim((string)get_option('site.logo', ''));
    if ($admLogo === '' || !is_file(dirname(__DIR__) . $admLogo)) { $admLogo = '/themes/yecha/logo-oni.png'; }
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $name . ' · YeCha 后台</title><style>'
        . 'body{margin:0;font:14px/1.7 -apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f5f5f3;color:#1f2328}'
        . 'a{color:inherit;text-decoration:none}'
        . 'header{background:#fff;border-bottom:1px solid #e6e6e2;display:flex;align-items:center;gap:16px;padding:0 20px;min-height:54px;position:sticky;top:0;z-index:40}'
        . 'header .logo b{font-size:16px} header .spacer{flex:1} header a.c{color:#6f767e;font-size:13px} header a.c:hover{color:#b3541e}'
        . '.wrap{display:grid;grid-template-columns:' . (!empty($GLOBALS['YC_ADMIN_FOCUS']) ? '1fr' : '200px 1fr') . ';gap:20px;max-width:' . (!empty($GLOBALS['YC_ADMIN_FOCUS']) ? '1240px' : '1200px') . ';margin:20px auto;padding:0 18px;align-items:start}'
        . 'aside{background:#fff;border:1px solid #e6e6e2;border-radius:8px;padding:8px}'
        . 'aside ul{list-style:none;margin:0;padding:0}'
        . 'aside li a{display:flex;align-items:center;justify-content:space-between;padding:7px 12px;border-radius:6px;color:#3d4147;font-size:14px}'
        . 'aside li a:hover{background:#f4f4f1} aside li a.active{background:#f6ece4;color:#b3541e;font-weight:600}'
        . '.b{background:#b3541e;color:#fff;border-radius:999px;font-size:11px;padding:0 7px;line-height:18px}'
        . '.b.dim{background:#e5e5e0;color:#6f767e}'
        . 'main{min-width:0} .card{background:#fff;border:1px solid #e6e6e2;border-radius:8px;padding:16px 18px;margin-bottom:16px}'
        . '.card h3{margin:0 0 12px;font-size:15px}.card h3 small{color:#6f767e;font-weight:400;font-size:12px;margin-left:8px}'
        . '.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}'
        . '.stat{background:#fff;border:1px solid #e6e6e2;border-radius:8px;padding:12px 16px}'
        . '.stat b{font-size:24px;display:block}.stat span{color:#6f767e;font-size:12px}.stat i{color:#b3541e;font-size:11.5px;font-style:normal}'
        . 'table{width:100%;border-collapse:collapse;font-size:13.5px}'
        . 'td,th{padding:7px 8px;border-bottom:1px solid #f1f1ee;text-align:left;vertical-align:top}'
        . 'th{color:#6f767e;font-size:12px;font-weight:500}'
        . 'button,.btn{background:#b3541e;color:#fff;border:0;border-radius:6px;padding:6px 14px;font-size:13px;cursor:pointer}'
        . '.btn.ghost{background:#fff;color:#3f4650;border:1px solid #dfe2e7;padding:4px 12px}'
        . '.btn.ghost:hover{background:#f6f7f9;border-color:#c9ced6;color:#20252c}'
        . '.btn.ghost:active{background:#eceff2}'
        . '.btn.mini{padding:2px 10px;font-size:12px} .btn.ghost.mini{background:#fff}'
        . '.btn.danger{color:#b42318!important;border-color:#e9c8c4!important}'
        . '.btn.danger:hover{background:#fdf4f3!important;border-color:#d9a49e!important;color:#8f1c12!important}'
        . '.btn.ai{color:#1a56db!important;border-color:#c7d7f8!important}'
        . '.btn.ai:hover{background:#f3f7ff!important;border-color:#9fbcf2!important;color:#1543ad!important}'
        . ' form.inline{display:inline}'
        . '.err{color:#9c3f1b;background:#fdf0ec;border:1px solid #efc8ba;border-radius:6px;padding:8px 12px;font-size:13px;margin-bottom:14px}'
        . '.ok{color:#2e6b34;background:#eef4ee;border:1px solid #cfe2d0;border-radius:6px;padding:8px 12px;font-size:13px;margin-bottom:14px}'
        . '.stub{color:#6f767e;background:#fbfbf8;border:1px dashed #e0e0d8;border-radius:6px;padding:14px 16px;font-size:13.5px}'
        . '@media(max-width:860px){.wrap{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,1fr)}}'
        . 'header .logo{display:inline-flex;align-items:center;gap:8px;color:inherit;text-decoration:none}'
        . 'header .logo img{height:34px;width:34px;object-fit:contain;border-radius:50%}'
        . 'header .logo .lt{font-size:15px;font-weight:700;display:none}'
        . 'header .logo:hover .lt,header .logo:focus .lt{display:inline}'
        . '</style></head><body><header><a class="logo" href="/admin/" title="YeCha 夜叉 · 后台"><img src="' . e($admLogo) . '" alt=""><span class="lt">YeCha 夜叉 · 后台</span></a>'
        . '<a class="c" href="/">查看站点 ↗</a><span class="spacer"></span>'
        . '<span style="color:#6f767e;font-size:13px;">博主：' . $name . '</span>'
        . '<a class="c" href="/admin/?action=logout">退出</a></header>'
        . '<div class="wrap">' . (!empty($GLOBALS['YC_ADMIN_FOCUS']) ? '' : '<aside><ul>' . $navHtml . '</ul></aside>') . '<main>' . $content . '</main></div>'
        . '</body></html>';
}

/* ---------- 未登录：登录页 ---------- */
if (!$authed) {
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>登录 · YeCha 后台</title><style>'
        . 'body{background:#f5f5f3;font:14px/1.7 -apple-system,"PingFang SC","Microsoft YaHei",sans-serif;margin:0}'
        . '.c{max-width:360px;margin:10vh auto;background:#fff;border:1px solid #e6e6e2;border-radius:10px;padding:28px 32px}'
        . 'h2{margin:0 0 4px;font-size:20px}.sub{color:#6f767e;font-size:12.5px;margin-bottom:8px}'
        . 'input{width:100%;border:1px solid #e6e6e2;border-radius:6px;padding:9px 10px;font-size:14px;box-sizing:border-box}'
        . 'label{font-size:13px;color:#6f767e;display:block;margin:12px 0 4px}'
        . 'button{width:100%;background:#b3541e;color:#fff;border:0;border-radius:6px;padding:10px;font-size:15px;margin-top:16px;cursor:pointer}'
        . 'a{color:#6f767e;font-size:12px}'
        . '</style></head><body><div class="c"><h2>YeCha 后台</h2>'
        . '<div class="sub">登录后进入后台；在哪个页面登录，成功后就回到那个页面（回跳演示）</div>';
    if ($msg !== '') { echo '<div class="err">' . htmlspecialchars($msg) . '</div>'; }
    echo '<form method="post">' . Csrf::field()
        . '<label>账号 / 邮箱</label><input name="username" required autofocus>'
        . '<label>密码</label><input type="password" name="password" required>'
        . '<button type="submit">登录</button></form>'
        . '<div style="margin-top:14px;"><a href="/">← 返回前台</a></div></div></body></html>';
    exit;
}

/* ================= 已登录模块区 ================= */
$T = Db::t('');
$out = '';
$stub = function (string $feature) use ($MODULES, $sec): string {
    $label = $MODULES[$sec][0] ?? '未知模块';
    $desc = $MODULES[$sec][1] ?? '';
    $avail = array_map(fn($m) => $m[0], array_slice($MODULES, 0, 8, true));
    return '<div class="card"><h3>' . e($label) . ($desc !== '' ? '<small>' . e($desc) . '</small>' : '') . '</h3>'
        . '<div class="stub">' . e($feature) . '：该地址无对应页面（可能模块已停用或地址有误）。当前可用：' . e(implode(' / ', $avail)) . '。</div></div>';
};

switch ($sec) {
    case 'dashboard':
        $publish = (int)Db::one("SELECT COUNT(*) FROM {$T}contents WHERE type='post' AND status='publish' AND deleted_at IS NULL");
        $drafts = (int)Db::one("SELECT COUNT(*) FROM {$T}contents WHERE type='post' AND status='draft'");
        $pages = (int)Db::one("SELECT COUNT(*) FROM {$T}contents WHERE type='page' AND status='publish'");
        $comments = (int)Db::one("SELECT COUNT(*) FROM {$T}comments WHERE status='approved'");
        $pending = (int)Db::one("SELECT COUNT(*) FROM {$T}comments WHERE status='pending'");
        $views = (int)Db::one("SELECT COALESCE(SUM(views),0) FROM {$T}contents");
        $top = Db::rows("SELECT title, slug, views FROM {$T}contents WHERE type='post' AND status='publish' ORDER BY views DESC LIMIT 5");
        $recent = Db::rows("SELECT c.title, co.author_name, co.body, co.status, co.kind, co.created_at FROM {$T}comments co LEFT JOIN {$T}contents c ON c.id=co.content_id ORDER BY co.id DESC LIMIT 6");
        $smap = ['approved' => '已通过', 'pending' => '待审', 'spam' => '垃圾', 'trash' => '已删'];
        $rows = '';
        foreach ($top as $r) {
            $rows .= '<tr><td><a style="color:#b3541e" href="/' . e((string)$r['slug']) . '.html">' . e($r['title']) . '</a></td><td>' . number_format((int)$r['views']) . '</td></tr>';
        }
        $recentHtml = '';
        foreach ($recent as $r) {
            $recentHtml .= '<tr><td>' . e(mb_substr((string)$r['body'], 0, 44))
                . ($r['kind'] === 'segment' ? ' <span style="color:#b3541e;font-size:11.5px;">段评</span>' : '')
                . '</td><td>' . e((string)$r['author_name']) . '</td><td>' . e($smap[$r['status']] ?? $r['status']) . '</td><td>' . e(substr((string)$r['created_at'], 0, 16)) . '</td></tr>';
        }
        // 在线升级提示：仪表盘醒目提示（同类后台的通用做法）；remoteStatus 已短缓存，不影响仪表盘速度
        $upgB = '';
        if (class_exists('Upgrade')) {
            $rmU = Upgrade::remoteStatus();
            if ($rmU && Upgrade::isUpdateAvailable($rmU)) {
                $csrfU = Csrf::field();
                $upgB = '<div class="card" style="border-left:4px solid #2F7D46;background:#f0f7f1;">'
                    . '<h3 style="margin:0 0 6px;color:#2F7D46;">发现新版本 v' . e((string)$rmU['version']) . '</h3>'
                    . '<p style="margin:0 0 12px;color:#565b61;">检测到线上可用新版本，建议一键在线升级（执行前自动生成全库 SQL 备份）。</p>'
                    . '<form method="post" action="/admin/?s=upgrade" style="display:inline">' . $csrfU
                    . '<input type="hidden" name="do" value="upgrade-remote">'
                    . '<button class="btn" type="submit">立即在线升级</button></form> '
                    . '<a class="btn ghost" href="/admin/?s=upgrade">进入系统升级</a></div>';
            }
        }
        $out = '<div class="stats">'
            . '<div class="stat"><b>' . $publish . '</b><span>文章（草稿 ' . $drafts . '）</span></div>'
            . '<div class="stat"><b>' . $pages . '</b><span>页面</span></div>'
            . '<div class="stat"><b>' . $comments . '</b><span>评论（待审 ' . $pending . '）</span></div>'
            . '<div class="stat"><b>' . number_format($views) . '</b><span>总阅读（日去重口径）</span></div></div>'
            . '<div class="card"><h3>阅读排行 <small>同一访客自然日去重计 1 次 · 多语共用</small></h3><table><tr><th>文章</th><th>阅读</th></tr>' . $rows . '</table></div>'
            . '<div class="card"><h3>最近评论 / 段评</h3><table><tr><th>内容</th><th>作者</th><th>状态</th><th>时间</th></tr>' . $recentHtml . '</table></div>'
            . '<div class="card"><h3>系统状态与维护</h3><p style="margin:0 0 10px;color:#565b61;">PHP ' . PHP_VERSION . ' · 缓存：文件驱动（var/cache）· 统计代码位 / 多语 / AI 配置见「设置」</p>'
            . '<form class="inline" method="post" action="/admin/?s=dashboard"><input type="hidden" name="do" value="flush-cache">' . Csrf::field()
            . '<button class="btn ghost" type="submit">清空页面缓存</button></form></div>';
        break;

    case 'posts':
    case 'pages': {
        $type = $sec === 'posts' ? 'post' : 'page';
        $gAction = (string)($_GET['action'] ?? '');
        // 语言版编辑：lang 必须为“已启用且非默认”的语言短码（多语插件下）
        $gLangReq = trim((string)($_GET['lang'] ?? ''));
        $gLang = (Poly::on() && $gLangReq !== '' && in_array($gLangReq, Poly::enabled(), true) && $gLangReq !== Poly::defaultLang()) ? $gLangReq : '';
        if ($gAction === 'new') {
            $out = pc_render_editor($type, 0);
        } elseif ($gAction === 'edit' && isset($_GET['id'])) {
            $out = $gLang !== '' ? pc_render_editor_lang((int)$_GET['id'], $gLang) : pc_render_editor($type, (int)$_GET['id']);
        } else {
            $out = pc_render_list($type, (string)($_GET['q'] ?? ''), (string)($_GET['st'] ?? ''), max(1, (int)($_GET['page'] ?? 1)));
        }
        break;
    }

    case 'comments': {
        $rows = Db::rows("SELECT co.*, c.title FROM {$T}comments co LEFT JOIN {$T}contents c ON c.id=co.content_id ORDER BY co.id DESC LIMIT 100");
        $smap = ['approved' => '已通过', 'pending' => '待审', 'spam' => '垃圾', 'trash' => '已删'];
        $list = '';
        foreach ($rows as $r) {
            $acts = '';
            foreach ([['approved', '通过'], ['spam', '垃圾'], ['trash', '删除']] as $a) {
                $acts .= '<form class="inline" method="post" action="/admin/?s=comments">' . Csrf::field()
                    . '<input type="hidden" name="do" value="comment-status"><input type="hidden" name="cid" value="' . (int)$r['id'] . '">'
                    . '<input type="hidden" name="st" value="' . $a[0] . '"><button class="btn ghost mini" type="submit">' . $a[1] . '</button></form>';
            }
            $list .= '<tr><td>' . e(mb_substr((string)$r['body'], 0, 60))
                . ($r['kind'] === 'segment' ? '<br><span style="color:#b3541e;font-size:11.5px;">段评 · 锚点 ' . e((string)$r['anchor_ref']) . '</span>' : '')
                . '</td><td>' . e((string)$r['author_name']) . '</td><td>' . e((string)($r['title'] ?? '')) . '</td>'
                . '<td>' . e($smap[$r['status']] ?? $r['status']) . '</td><td>' . $acts . '</td></tr>';
        }
        $out = '<div class="card"><h3>评论与段评 <small>先审后发 · 段评并入审核</small></h3>'
            . '<table><tr><th>内容</th><th>作者</th><th>文章</th><th>状态</th><th>操作</th></tr>' . $list . '</table></div>';
        break;
    }

    case 'metas': {
        $a = (string)($_GET['action'] ?? '');
        if ($a === 'edit' && isset($_GET['id'])) {
            $out = metas_editor_html('', (int)$_GET['id']);
        } elseif ($a === 'new') {
            $out = metas_editor_html((($_GET['type'] ?? '') === 'category') ? 'category' : 'tag');
        } else {
            $out = metas_list_html();
        }
        break;
    }

    case 'users': {
        $me = Auth::id();
        $rows = Db::rows("SELECT * FROM {$T}users ORDER BY id ASC LIMIT 200");
        $ul = '';
        foreach ($rows as $u) {
            $uid = (int)$u['id'];
            $self = $uid === $me;
            $statusTxt = ($u['status'] ?? 'normal') === 'normal' ? '正常' : '已禁用';
            $stCol = ($u['status'] ?? 'normal') === 'normal' ? '#2e6b34' : '#9c3f1b';
            $acts = '';
            if (!$self) {
                $disable = (($u['status'] ?? 'normal') === 'normal');
                $acts .= '<form class="inline" method="post" action="/admin/?s=users">' . Csrf::field()
                    . '<input type="hidden" name="do" value="user-status"><input type="hidden" name="uid" value="' . $uid . '">'
                    . '<input type="hidden" name="status" value="' . ($disable ? 'disabled' : 'normal') . '">'
                    . '<button class="btn ghost mini" type="submit">' . ($disable ? '禁用' : '恢复') . '</button></form> '
                    . '<form class="inline" method="post" action="/admin/?s=users">' . Csrf::field()
                    . '<input type="hidden" name="do" value="user-role"><input type="hidden" name="uid" value="' . $uid . '">'
                    . '<input type="hidden" name="role" value="' . ($u['role'] === 'admin' ? 'subscriber' : 'admin') . '">'
                    . '<button class="btn ghost mini" type="submit">' . ($u['role'] === 'admin' ? '降为注册' : '设为管理员') . '</button></form>';
            } else {
                $acts = '<span style="color:#c0c0b8;font-size:12px;">（当前账号）</span>';
            }
            $ul .= '<tr><td>' . e($u['username']) . '</td><td>' . e((string)$u['display_name']) . '</td><td>' . e((string)$u['email'])
                . '</td><td>' . ($u['role'] === 'admin' ? '管理员' : '注册用户') . '</td>'
                . '<td style="color:' . $stCol . ';">' . $statusTxt . '</td>'
                . '<td>' . e(substr((string)$u['created_at'], 0, 10)) . '</td><td>' . $acts . '</td></tr>';
        }
        $out = '<div class="card"><h3>用户 <small>禁用/恢复与角色调整即时生效（不可操作当前账号自身）</small></h3>'
            . '<table><tr><th>账号</th><th>昵称</th><th>邮箱</th><th>角色</th><th>状态</th><th>注册时间</th><th>操作</th></tr>' . $ul . '</table></div>';
        break;
    }

    case 'poly': {
        if (!Plugins::isOn('poly')) {
            $out = '<div class="card"><h3>多语</h3><div class="stub">多语插件已停用：到「插件」页重新启用（译文数据 ye_poly_contents 保留不删）。</div></div>';
            break;
        }
        $a = (string)($_GET['action'] ?? '');
        $pLang = poly_cur_lang();
        $req = trim((string)($_GET['lang'] ?? ''));
        if ($req !== '' && in_array($req, Poly::enabled(), true) && $req !== Poly::defaultLang()) { $pLang = $req; }
        if ($a === 'edit' && isset($_GET['id'])) {
            $out = poly_edit_html((int)$_GET['id'], $pLang);
        } else {
            $out = poly_overview_html($pLang);
        }
        break;
    }

    case 'notes': {
        if (!Plugins::isOn('notes')) {
            $out = '<div class="card"><h3>记事本</h3><div class="stub">该插件已停用：到「插件」页重新启用（数据 ye_notes 保留不删）。</div></div>';
            break;
        }
        $a = (string)($_GET['action'] ?? '');
        if ($a === 'edit' && isset($_GET['id'])) {
            $out = notes_editor_html((int)$_GET['id']);
        } elseif ($a === 'new') {
            $out = notes_editor_html();
        } else {
            $out = notes_list_html();
        }
        break;
    }

    case 'layouts': {
        $raw = json_decode((string)get_option('theme.sidebar', ''), true);
        $cfg = is_array($raw) ? $raw : [];
        $labels = [
            'home' => '首页', 'post' => '文章页（内容）', 'page' => '独立页面',
            'tag' => '标签页', 'category' => '分类页', 'archives' => '归档页', 'search' => '搜索页',
        ];
        $desc = [
            'home' => '首页右栏', 'post' => '文章正文页右栏（目录自动置顶）', 'page' => '关于/隐私等独立页右栏',
            'tag' => '/tag/… 列表右栏', 'category' => '/category/… 列表右栏',
            'archives' => '归档总览/按月归档右栏', 'search' => '站内搜索页右栏',
        ];
        $blkLabel = ['search' => '搜索框', 'blogger' => '博主信息', 'cats' => '分类', 'latest' => '最新文章', 'tags' => '标签云', 'archives' => '归档'];
        $blkLabel += Plugins::sideBlocks(); // 第三方插件块（如 友链墙）
        $defBlocks = ['search', 'blogger', 'cats', 'latest', 'tags', 'archives'];
        $cards = '';
        $csrf = Csrf::field();
        foreach ($labels as $tp => $name) {
            $cur = $cfg[$tp] ?? ['show' => '1', 'blocks' => $defBlocks];
            $show = ($cur['show'] ?? '1') === '1';
            $blocks = is_array($cur['blocks'] ?? null) ? array_values($cur['blocks']) : $defBlocks;
            $rows = '';
            $pool = '';
            // 已启用：严格按保存顺序输出（拖拽/▲▼ 的顺序即保存顺序，回显必须一致）
            foreach ($blocks as $b) {
                if (!isset($blkLabel[$b])) { continue; }
                $rows .= '<li class="sb-li" draggable="true" data-b="' . e($b) . '">'
                    . '<span class="sb-grip" title="拖拽排序">⠿</span>'
                    . '<span class="sb-name">' . e($blkLabel[$b]) . '</span>'
                    . '<span class="sb-ops"><button type="button" class="sb-up" title="上移">▲</button>'
                    . '<button type="button" class="sb-down" title="下移">▼</button>'
                    . '<button type="button" class="sb-del" title="从此页右栏移除">✕</button></span></li>';
            }
            // 未启用：进入下方按钮池（顺序无所谓，可加回列表末尾）
            foreach ($blkLabel as $b => $bl) {
                if (!in_array($b, $blocks, true)) {
                    $pool .= '<button type="button" class="sb-pool-add" data-b="' . e($b) . '">+ ' . e($bl) . '</button>';
                }
            }
            $cards .= '<div class="card"><h3>' . $name . ' <small>' . $desc[$tp] . '</small></h3>'
                . '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13.5px;"><input type="checkbox" name="sb_show_' . $tp . '" value="1"' . ($show ? ' checked' : '') . '> 显示右侧栏</label>'
                . '<div style="margin-top:6px;color:#6f767e;font-size:12.5px;">右栏功能块：拖拽整行或点 ▲▼ 调整顺序；✕ 移除；被移除的块回到下方「+」按钮池（点击加回，排到末尾）。取消“显示右侧栏”则该页面整页单栏。</div>'
                . '<input type="hidden" name="sb_order_' . $tp . '" value="' . e(implode(',', $blocks)) . '">'
                . '<ol class="sb-list" data-tp="' . $tp . '">' . $rows . '</ol>'
                . '<div class="sb-pool">可用块（点击加入本页右栏）：<br>'
                . ($pool !== '' ? $pool : '<span class="sb-none">（已全部启用；✕ 移除后该项会回到这里）</span>')
                . '</div>'
                . '</div>';
        }
        $modeCur = ((string)get_option('theme.sidebar_mode', 'flow') === 'sticky') ? 'sticky' : 'flow';
        $selM = fn(string $v, string $c) => $v === $c ? ' selected' : '';
        $modeCard = '<div class="card"><h3>文章页右栏模式 <small>目录/功能块整体在带目录的文章页的滚动方式</small></h3>'
            . '<form method="post" action="/admin/?s=layouts">' . $csrf
            . '<input type="hidden" name="do" value="layouts-mode">'
            . '<select name="sidebar_mode" style="padding:6px 10px;border:1px solid #e6e6e2;border-radius:6px;">'
            . '<option value="flow"' . $selM('flow', $modeCur) . '>普通：随页面滚动，无内滚动条（推荐）</option>'
            . '<option value="sticky"' . $selM('sticky', $modeCur) . '>固定：吸附视口顶部，超高时栏内滚动</option></select> '
            . '<button class="btn" type="submit">保存右栏模式</button></form></div>';
        $out = $modeCard
            . '<div class="card"><h3>页面布局控制 <small>分别控制各页面类型的右侧栏与功能块顺序/启停（默认语言页面生效；译文界面使用轻量侧栏；保存即清缓存）</small></h3>'
            . '<form method="post" action="/admin/?s=layouts" id="layoutsForm">' . $csrf
            . '<input type="hidden" name="do" value="layouts-save">' . $cards
            . '<div style="margin-top:12px;"><button class="btn" type="submit">保存页面布局</button></div></form></div>'
            . '<style>.sb-list{list-style:none;margin:10px 0 4px;padding:0;max-width:520px;display:flex;flex-direction:column;gap:6px}'
            . '.sb-li{display:flex;align-items:center;gap:8px;border:1px solid #e6e6e2;border-radius:8px;background:#fbfbf8;padding:7px 10px;font-size:13.5px}'
            . '.sb-li.dragging{opacity:.5;border-color:#b3541e}'
            . '.sb-grip{color:#9a9a90;cursor:grab;font-size:14px;user-select:none}'
            . '.sb-name{flex:1}.sb-ops{display:inline-flex;gap:2px}'
            . '.sb-ops button{background:#fff;color:#6f767e;border:1px solid #e0e0d8;border-radius:5px;padding:1px 7px;font-size:11px;line-height:1.6;cursor:pointer}'
            . '.sb-ops button:hover{border-color:#b3541e;color:#b3541e}'
            . '.sb-del{color:#b42318!important;border-color:#e9c8c4!important}'
            . '.sb-del:hover{background:#fdf4f3!important}'
            . '.sb-pool{margin-top:8px;color:#6f767e;font-size:12.5px;line-height:2.1}'
            . '.sb-pool-add{background:#fff;color:#3f4650;border:1px dashed #d5d9df;border-radius:999px;padding:1px 11px;font-size:12.5px;cursor:pointer;margin-right:6px}'
            . '.sb-pool-add:hover{background:#f0f2f5;border-style:solid}'
            . '</style>'
            . '<script>(function(){'
            . 'function poolEmptyHint(pool){if(pool&&pool.children.length===0){var s=document.createElement("span");s.className="sb-none";s.textContent="（已全部启用；✕ 移除后该项会回到这里）";pool.appendChild(s);}}'
            . 'function sync(tp){var ul=document.querySelector("ol.sb-list[data-tp=\'"+tp+"\']");if(!ul)return;var ids=[];'
            . 'for(var i=0;i<ul.children.length;i++){ids.push(ul.children[i].getAttribute("data-b"));}'
            . 'document.querySelector("input[name=\'sb_order_"+tp+"\']").value=ids.join(",");}'
            . 'function moveLi(li,dir){var ul=li.parentNode;if(dir<0&&li.previousElementSibling){ul.insertBefore(li,li.previousElementSibling);}'
            . 'else if(dir>0&&li.nextElementSibling){ul.insertBefore(li.nextElementSibling,li);}else{return;}sync(ul.getAttribute("data-tp"));}'
            . 'function addPoolBtn(tp,b,name){var ul=document.querySelector("ol.sb-list[data-tp=\'"+tp+"\']");if(!ul)return;'
            . 'var card=ul.closest(".card"),pool=card.querySelector(".sb-pool");'
            . 'if(pool){var none=pool.querySelector(".sb-none");if(none){none.remove();}'
            . 'if(!pool.querySelector(".sb-pool-add[data-b=\'"+b+"\']")){'
            . 'var btn=document.createElement("button");btn.type="button";btn.className="sb-pool-add";btn.setAttribute("data-b",b);btn.textContent="+ "+name;'
            . 'btn.addEventListener("click",poolAdd);pool.appendChild(btn);}}'
            . 'sync(tp);}'
            . 'function removeLi(li){var tp=li.parentNode.getAttribute("data-tp"),b=li.getAttribute("data-b");'
            . 'var name=li.querySelector(".sb-name").textContent;addPoolBtn(tp,b,name);li.remove();sync(tp);}'
            . 'function poolAdd(){var btn=this;var card=btn.closest(".card");var ul=card.querySelector(".sb-list");'
            . 'var b=btn.getAttribute("data-b");var name=btn.textContent.replace(/^\\s*\\+\\s*/,"");'
            . 'if(ul.querySelector(".sb-li[data-b=\'"+b+"\']")){return;}'
            . 'var li=document.createElement("li");li.className="sb-li";li.draggable=true;li.setAttribute("data-b",b);'
            . 'li.innerHTML="<span class=\'sb-grip\' title=\'拖拽排序\'>⠿</span><span class=\'sb-name\'>"+name+"</span>'
            . '<span class=\'sb-ops\'><button type=\'button\' class=\'sb-up\' title=\'上移\'>▲</button>'
            . '<button type=\'button\' class=\'sb-down\' title=\'下移\'>▼</button>'
            . '<button type=\'button\' class=\'sb-del\' title=\'从此页右栏移除\'>✕</button></span>";'
            . 'ul.appendChild(li);btn.remove();poolEmptyHint(card.querySelector(".sb-pool"));sync(ul.getAttribute("data-tp"));}'
            . 'document.querySelectorAll(".sb-pool-add").forEach(function(btn){btn.addEventListener("click",poolAdd);});'
            . 'document.addEventListener("click",function(e){var btn=e.target.closest("button");if(!btn)return;'
            . 'var li=btn.closest(".sb-li");if(!li)return;'
            . 'if(btn.classList.contains("sb-up")){moveLi(li,-1);}else if(btn.classList.contains("sb-down")){moveLi(li,1);}'
            . 'else if(btn.classList.contains("sb-del")){removeLi(li);}e.preventDefault();});'
            . 'var dragSrc=null;'
            . 'document.querySelectorAll("ol.sb-list").forEach(function(ul){'
            . 'ul.addEventListener("dragstart",function(e){var li=e.target.closest(".sb-li");if(!li)return;dragSrc=li;li.classList.add("dragging");'
            . 'e.dataTransfer.effectAllowed="move";try{e.dataTransfer.setData("text/plain","sb");}catch(_){}});'
            . 'ul.addEventListener("dragover",function(e){if(!dragSrc||dragSrc.parentNode!==ul)return;e.preventDefault();e.dataTransfer.dropEffect="move";});'
            . 'ul.addEventListener("dragenter",function(e){if(!dragSrc||dragSrc.parentNode!==ul)return;e.preventDefault();});'
            . 'ul.addEventListener("drop",function(e){if(!dragSrc||dragSrc.parentNode!==ul)return;e.preventDefault();'
            . 'var after=null;for(var i=0;i<ul.children.length;i++){if(ul.children[i]===dragSrc)continue;'
            . 'var r=ul.children[i].getBoundingClientRect();if(e.clientY<r.top+r.height/2){after=ul.children[i];break;}}'
            . 'if(after){ul.insertBefore(dragSrc,after);}else{ul.appendChild(dragSrc);}'
            . 'sync(ul.getAttribute("data-tp"));});'
            . 'ul.addEventListener("dragend",function(e){if(dragSrc){dragSrc.classList.remove("dragging");}dragSrc=null;});'
            . '});'
            . '})();</script>'
            . '<div class="card"><h3>说明与预留</h3><ul style="margin:0;padding-left:20px;line-height:1.9;color:#565b61;font-size:13.5px;">'
            . '<li>文章页右栏顶部为自动「目录」（正文 ≥3 个 h2–h5 标题时生成），不受上述功能块开关影响；关闭右侧栏则不显示。</li>'
            . '<li>分类 / 标签 / 归档块在译文语言界面暂不展示（轻量侧栏为搜索+博主+最新译文）。</li>'
            . '<li><b>插件设置入口（预留）</b>：友情链接、WebP、远程图片等插件启用后，其设置项将出现在「插件」页并可在未来注册到这里作为功能块（如 友链墙）。当前先建好页面布局框架。</li></ul></div>';
        break;
    }
    case 'settings': {
        $style = (string)get_option('home.style', 'text');
        $sel = fn(string $v) => $style === $v ? ' selected' : '';
        $sub = e((string)get_option('site.subtitle', ''));
        $desc = e((string)get_option('site.description', ''));
        $pwCard = '<div class="card"><h3>修改密码 <small>当前登录账号：' . e((string)(Auth::user()['username'] ?? 'admin')) . ' · 修改后下次登录请使用新密码</small></h3>'
            . '<form method="post" action="/admin/?s=settings">' . Csrf::field()
            . '<input type="hidden" name="do" value="admin-password">'
            . '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0 16px;max-width:760px;">'
            . '<div><label class="pf">旧密码</label><input type="password" name="old_password" required autocomplete="current-password"></div>'
            . '<div><label class="pf">新密码（≥6 位）</label><input type="password" name="new_password" required minlength="6" autocomplete="new-password"></div>'
            . '<div><label class="pf">确认新密码</label><input type="password" name="confirm_password" required minlength="6"></div></div>'
            . '<div style="margin-top:12px;"><button class="btn" type="submit">更新密码</button></div></form></div>';
        // 各语言博主简介输入（多语插件启用时侧栏按语言展示；留空回落默认语言）
        $defLang = Poly::defaultLang();
        $bioRows = '';
        foreach (Poly::enabled() as $lc) {
            if ($lc === $defLang) { continue; }
            $bioRows .= '<div><label class="pf">昵称 · ' . e(Poly::label($lc)) . '</label>'
                . '<input name="blogger_nick_' . $lc . '" value="' . e((string)get_option('blogger.nick_' . $lc, '')) . '" placeholder="留空=默认语言昵称"></div>'
                . '<div><label class="pf">简介 · ' . e(Poly::label($lc)) . '</label>'
                . '<input name="blogger_bio_' . $lc . '" value="' . e((string)get_option('blogger.bio_' . $lc, '')) . '" placeholder="留空=默认语言简介"></div>';
        }
        $out = $pwCard . '<div class="card"><h3>站点设置 <small>基础信息 / 首页版式开关</small></h3>'
            . '<form method="post" action="/admin/?s=settings">' . Csrf::field()
            . '<input type="hidden" name="do" value="settings-save">'
            . '<label class="pf">站点副标题</label><input name="site_subtitle" value="' . $sub . '">'
            . '<label class="pf">站点描述（默认 meta description）</label><textarea name="site_description" rows="2">' . $desc . '</textarea>'
            . '<label class="pf">首页版式（图文列表 = 左图右文，参考 2.jpg）</label>'
            . '<select name="home_style"><option value="text"' . $sel('text') . '>纯文字列表</option>'
            . '<option value="img"' . $sel('img') . '>图文列表（缩略图 + 标题/阅读/评论）</option></select>'
            . '<div style="margin-top:12px;"><button class="btn" type="submit">保存</button></div></form></div>'
            . '<div class="card"><h3>评论防骚扰 <small>P-4 · 命中即标记垃圾，不进前台</small></h3>'
            . '<form method="post" action="/admin/?s=settings">' . Csrf::field()
            . '<input type="hidden" name="do" value="comment-guard-save">'
            . '<label class="pf">关键词黑名单（每行一个，评论正文或昵称包含即拦截）</label>'
            . '<textarea name="blacklist" rows="5" style="width:100%;max-width:640px;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font:inherit;">' . e((string)get_option('comment.blacklist', '')) . '</textarea>'
            . '<label class="pf">单条评论链接数上限（0=不限制）</label>'
            . '<input name="max_links" type="number" value="' . e((string)get_option('comment.max_links', '3')) . '" style="width:120px;">'
            . '<label class="pf">昵称 / 邮箱黑名单（每行一个，包含即拦截）</label>'
            . '<textarea name="banned" rows="3" style="width:100%;max-width:640px;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font:inherit;">' . e((string)get_option('comment.banned', '')) . '</textarea>'
            . '<div style="margin-top:12px;"><button class="btn" type="submit">保存防骚扰设置</button></div></form></div>'
            . '<div class="card"><h3>邮件通知 <small>评论通过时可选发送（SMTP；失败仅记日志不阻断）</small></h3>'
            . '<form method="post" action="/admin/?s=settings">' . Csrf::field()
            . '<input type="hidden" name="do" value="mail-save">'
            . '<label class="pf"><input type="checkbox" name="enabled" value="1"' . (get_option('mail.enabled', '0') === '1' ? ' checked' : '') . '> 启用邮件通知</label>'
            . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;max-width:760px;">'
            . '<div><label class="pf">SMTP 主机</label><input name="host" value="' . e((string)get_option('mail.host', '')) . '"></div>'
            . '<div><label class="pf">端口（465=SSL / 587=STARTTLS/非加密）</label><input name="port" value="' . e((string)get_option('mail.port', '465')) . '"></div>'
            . '<div><label class="pf">使用 SSL</label><select name="ssl"><option value="1"' . (get_option('mail.ssl', '1') === '1' ? ' selected' : '') . '>是</option><option value="0"' . (get_option('mail.ssl', '1') !== '1' ? ' selected' : '') . '>否</option></select></div>'
            . '<div><label class="pf">用户名</label><input name="user" value="' . e((string)get_option('mail.user', '')) . '"></div></div>'
            . '<label class="pf">密码（已保存则不回显，留空保持不变）</label><input name="pass" type="password" value="" placeholder="' . (yc_opt_has('mail.pass') ? '••••••（已保存）' : '') . '" autocomplete="off">'
            . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;max-width:760px;">'
            . '<div><label class="pf">发件人 From</label><input name="from" value="' . e((string)get_option('mail.from', '')) . '"></div>'
            . '<div><label class="pf">收件人 notify_to（默认博主）</label><input name="notify_to" value="' . e((string)get_option('mail.notify_to', '')) . '"></div></div>'
            . '<div style="margin-top:12px;"><button class="btn" type="submit">保存邮件设置</button></div></form></div>'
            . '<div class="card"><h3>站点 Logo <small>原文件上传 · PNG/JPG；大 PNG 自动缩至 ≤100px（页头仅 46px，足够清晰）并重压缩保留透明，上传后自动启用</small></h3>'
            . '<form method="post" action="/admin/?s=settings" enctype="multipart/form-data">' . Csrf::field()
            . '<input type="hidden" name="do" value="logo-upload">'
            . '<input type="file" name="logo_file" accept=".png,.svg,.jpg,.jpeg"> '
            . '<button class="btn" type="submit">上传并设为站点 Logo</button>'
            . '<div class="hint" style="margin-top:6px;">当前：' . e((string)get_option('site.logo', '')) . '</div></form></div>'
            . '<div class="card"><h3>博主信息 / 备案 / 统计代码 <small>注入前台页头页脚，保存即清缓存生效</small></h3>'
            . '<form method="post" action="/admin/?s=settings">' . Csrf::field()
            . '<input type="hidden" name="do" value="misc-save">'
            . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;max-width:760px;">'
            . '<div><label class="pf">博主昵称（侧栏署名）</label><input name="blogger_nick" value="' . e((string)get_option('blogger.nick', '')) . '" placeholder="如：夜叉"></div>'
            . '<div><label class="pf">博主简介（侧栏展示）</label><input name="blogger_bio" value="' . e((string)get_option('blogger.bio', '安静写字，认真喝茶。')) . '"></div></div>'
            . (($bioRows !== '' && Poly::on())
                ? '<label class="pf">各语言博主昵称 / 简介（启用多语时侧栏按语言展示；留空回落默认语言）</label>'
                    . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;max-width:760px;">' . $bioRows . '</div>'
                : '')
            . '<label class="pf">备案号（留空则不显示；国内主机建议填写）</label>'
            . '<div style="display:grid;grid-template-columns:2fr 1fr;gap:0 16px;max-width:760px;">'
            . '<div><input name="site_icp" value="' . e((string)get_option('site.icp', '')) . '" placeholder="京ICP备xxxxxxxx号"></div>'
            . '<div><input name="site_icp_link" value="' . e((string)get_option('site.icp_link', 'https://beian.miit.gov.cn/')) . '" placeholder="https://beian.miit.gov.cn/"></div></div>'
            . '<label class="pf">站点 Logo（图片 URL，如 /uploads/2026/09/logo.png；留空=文字 Logo）</label>'
            . '<input name="site_logo" value="' . e((string)get_option('site.logo', '')) . '" placeholder="/uploads/…/logo.png" style="width:100%;max-width:760px;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font:inherit;">'
            . '<label class="pf">统计代码 · 页头（&lt;head&gt; 注入：统计 / 站点验证均可）</label>'
            . '<textarea name="stats_head" rows="3" style="width:100%;max-width:760px;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font-family:Consolas,monospace;font-size:12.5px;">' . e((string)get_option('stats.head', '')) . '</textarea>'
            . '<label class="pf">统计代码 · 页脚（&lt;/body&gt; 前注入：百度 / 51la / 自建统计）</label>'
            . '<textarea name="stats_foot" rows="3" style="width:100%;max-width:760px;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font-family:Consolas,monospace;font-size:12.5px;">' . e((string)get_option('stats.foot', '')) . '</textarea>'
            . '<div style="margin-top:12px;"><button class="btn" type="submit">保存</button></div></form></div>'
            . '<style>.pf{display:block;font-size:12.5px;color:#6f767e;margin:10px 0 2px}.pf+input,.pf+textarea,.pf+select{width:100%;max-width:640px;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font:inherit}</style>';
        break;
    }
    case 'attachments':
        $upRoot = dirname(__DIR__) . '/uploads';
        $rowsDb = [];
        foreach (Db::rows('SELECT * FROM ' . Db::t('attachments') . ' ORDER BY id DESC LIMIT 2000') as $a) {
            $rowsDb[(string)$a['file_path']] = $a;
        }
        $files = [];
        $totalBytes = 0;
        if (is_dir($upRoot)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($upRoot, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $fi) {
                if (!$fi->isFile()) { continue; }
                $rel = ltrim(str_replace('\\', '/', substr($fi->getPathname(), strlen($upRoot))), '/');
                $files[$rel] = ['size' => $fi->getSize(), 'mtime' => $fi->getMTime()];
                $totalBytes += $fi->getSize();
            }
        }
        $all = $files;
        foreach ($rowsDb as $rel => $a) {
            $all[$rel] = $all[$rel] ?? ['size' => (int)($a['size'] ?? 0), 'mtime' => strtotime((string)$a['created_at']) ?: time()];
        }
        uksort($all, fn($a2, $b2) => ($all[$b2]['mtime'] ?? 0) <=> ($all[$a2]['mtime'] ?? 0));
        $csrfA = Csrf::field();
        $count = count($all);
        $rows = '';
        $shown = 0;
        foreach ($all as $rel => $info) {
            if (++$shown > 600) { continue; }
            $db = $rowsDb[$rel] ?? null;
            $isImg = preg_match('/\.(jpe?g|png|gif|webp|svg)$/i', $rel);
            $thumb = $isImg ? '<img src="/uploads/' . e($rel) . '" alt="" style="width:52px;height:38px;object-fit:cover;border-radius:6px;background:#f0efe9;">' : '<span style="color:#9a9a90;">📄</span>';
            $rows .= '<tr><td style="display:flex;align-items:center;gap:10px;">' . $thumb . '<span><a href="/uploads/' . e($rel) . '" target="_blank" rel="noopener">' . e(basename($rel)) . '</a>'
                . '<div style="color:#9a9a90;font-size:11.5px;">/' . e($rel) . '</div></span></td>'
                . '<td>' . number_format((int)$info['size']) . ' B</td>'
                . '<td>' . e(date('Y-m-d', (int)($info['mtime'] ?? time()))) . '</td>'
                . '<td>' . ($db !== null ? '<span style="color:#2e6b34;">已登记</span>' : '<span style="color:#9a9a90;">扫描</span>') . '</td>'
                . '<td><form method="post" action="/admin/?s=attachments" onsubmit="return confirm(\'删除该附件文件？文中引用将失效。\')" style="display:inline;">' . $csrfA
                . '<input type="hidden" name="do" value="attach-del"><input type="hidden" name="path" value="' . e($rel) . '">'
                . '<button class="btn ghost mini danger">删除</button></form></td></tr>';
        }
        $out = '<div class="card"><h3>附件管理 <small>统一上传记录 + uploads 目录扫描（' . $count . ' 个文件 / ' . number_format($totalBytes) . ' B）· 正文引用的图片删文件后前台会断图，请谨慎</small></h3>'
            . '<p style="color:#6f767e;font-size:13px;margin:0 0 6px;">仅列出前 600 个（按修改时间倒序）。「已登记」= 在 ye_attachments 有记录；「扫描」= uploads 里存在但未登记（如导入图）。</p>'
            . '<table><tr><th>文件</th><th>大小</th><th>日期</th><th>来源</th><th>操作</th></tr>'
            . ($rows !== '' ? $rows : '<tr><td colspan="5" class="hint">uploads 为空。</td></tr>')
            . '</table></div>';
        break;
    case 'themes': {
        $cur = (string)get_option('theme.default', 'yecha');
        $meta = theme_meta($cur);
        $sel = fn(string $v, string $cur) => $v === $cur ? ' selected' : '';
        $mkField = function (array $s, string $k, string $val) use ($sel): string {
            $t = (string)($s['type'] ?? 'text');
            $lab = '<label class="pf">' . e((string)($s['label'] ?? $k));
            if (trim((string)($s['help'] ?? '')) !== '') { $lab .= '<span style="color:#9a9a90;font-weight:400;"> · ' . e((string)$s['help']) . '</span>'; }
            $lab .= '</label>';
            $v = e($val);
            if ($t === 'checkbox') {
                $chk = ($val === '1') ? ' checked' : '';
                return $lab . '<span><label style="display:inline-flex;gap:6px;align-items:center;"><input type="checkbox" name="' . e($k) . '" value="1"' . $chk . '> 开启</label></span>';
            }
            if ($t === 'select') {
                $h = $lab . '<select name="' . e($k) . '"><option value="">默认</option>';
                foreach ((array)($s['opts'] ?? []) as $ov => $ol) {
                    $h .= '<option value="' . e((string)$ov) . '"' . $sel((string)$ov, $val) . '>' . e((string)$ol) . '</option>';
                }
                return $h . '</select>';
            }
            if ($t === 'textarea') {
                return $lab . '<textarea name="' . e($k) . '" rows="3">' . $v . '</textarea>';
            }
            $extra = '';
            if ($t === 'number') {
                $extra = ' type="number"';
                if (isset($s['min'])) { $extra .= ' min="' . (int)$s['min'] . '"'; }
                if (isset($s['max'])) { $extra .= ' max="' . (int)$s['max'] . '"'; }
            } elseif ($t === 'color') {
                $extra = ' placeholder="#b3541e"';
            } else {
                $extra = ' type="text"';
            }
            return $lab . '<input name="' . e($k) . '"' . $extra . ' value="' . $v . '">';
        };
        $info = '<div class="card"><h3>' . e($meta['name']) . ' <small>当前主题 · v' . e($meta['version'])
            . ($meta['author'] !== '' ? ' · ' . e($meta['author']) : '') . '（' . e($meta['id']) . '）</small></h3>'
            . ($meta['description'] !== '' ? '<p style="color:#565b61;line-height:1.8;margin:6px 0 0;font-size:13.5px;">' . e($meta['description']) . '</p>' : '')
            . '<p style="color:#9a9a90;font-size:12.5px;margin:8px 0 0;">配置项来自该主题 theme.json 的 settings 声明；通用博客项（宽度/强调色/分页/侧栏）已并入各主题声明。</p></div>';
        $fields = '';
        if ($meta['settings']) {
            foreach ($meta['settings'] as $s) {
                $k = (string)$s['key'];
                $fields .= $mkField($s, $k, (string)theme_opt($k, (string)($s['def'] ?? '')));
            }
        } else {
            $fields = '<p style="color:#9a9a90;">该主题尚未在 theme.json 声明 settings，暂无主题专属设置。</p>';
        }
        $out = $info
            . '<div class="card"><h3>主题设置 <small>保存即时生效</small></h3>'
            . '<form method="post" action="/admin/?s=themes">' . Csrf::field()
            . '<input type="hidden" name="do" value="theme-save">'
            . $fields
            . '<div style="margin-top:12px;"><button class="btn" type="submit">保存主题设置</button></div></form></div>'
            . '<style>.pf{display:block;font-size:12.5px;color:#6f767e;margin:10px 0 2px}.pf+input,.pf+select,.pf+textarea{max-width:560px;width:100%;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font:inherit}</style>';
        break;
    }
    case 'market': {
        $csrfM = Csrf::field();
        $items = [];
        $mErr = '';
        try { $items = Market::manifest(); } catch (Throwable $e) { $mErr = $e->getMessage(); }
        $extInstalled = Plugins::extList();
        $themeList = Market::themes();
        $curTheme = (string)get_option('theme.default', 'yecha');
        $rows = '';
        foreach ($items as $id => $it) {
            $type = $it['type'];
            $ver = (string)($it['ver'] ?? '');
            $instVer = Market::installedVer($type, $id);
            $isInst = $instVer !== null;
            $cur = $type === 'theme' && $id === $curTheme;
            $installedTxt = !$isInst ? '<span style="color:#9a9a90;">未安装</span>'
                : ($type === 'theme'
                    ? '<span style="color:#2e6b34;">已安装 v' . $ver . ($cur ? ' · 使用中' : '') . '</span>'
                    : '<span style="color:#2e6b34;">已安装 v' . $instVer . '</span>');
            $act = '';
            if ($type === 'plugin') {
                if ($isInst) {
                    if ($instVer !== $ver) {
                        $act .= '<form method="post" style="display:inline;">' . $csrfM . '<input type="hidden" name="do" value="market-install"><input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="type" value="plugin"><button class="btn ghost mini">更新到 v' . e($ver) . '</button></form> ';
                    }
                    $act .= '<a class="btn ghost mini" href="/admin/?s=plugins">管理/卸载</a>';
                } else {
                    $act .= '<form method="post" style="display:inline;">' . $csrfM . '<input type="hidden" name="do" value="market-install"><input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="type" value="plugin"><button class="btn">安装 v' . e($ver) . '</button></form>';
                }
            } else {
                $act = '<form method="post" style="display:inline;">' . $csrfM . '<input type="hidden" name="do" value="market-install"><input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="type" value="theme"><button class="btn">' . ($isInst ? '更新' : '安装') . '并启用</button></form> '
                    . ($isInst && !$cur ? '<form method="post" style="display:inline;">' . $csrfM . '<input type="hidden" name="do" value="market-theme-set"><input type="hidden" name="id" value="' . e($id) . '"><button class="btn ghost mini">启用</button></form>' : '');
            }
            $rows .= '<tr><td><b>' . e($it['name'] ?? $id) . '</b><div style="color:#6f767e;font-size:12.5px;">' . e($it['desc'] ?? '') . '</div>'
                . '<div style="color:#9a9a90;font-size:11.5px;">' . e($type === 'plugin' ? '插件' : '主题') . ' · ' . e($id) . ' · ' . e($it['author'] ?? 'YeCha') . '</div></td>'
                . '<td style="white-space:nowrap;">v' . e($ver) . '</td><td style="white-space:nowrap;">' . $installedTxt . '</td>'
                . '<td style="white-space:nowrap;">' . $act . '</td></tr>';
        }
        $src = Market::source();
        $out = '<div class="card"><h3>云市场 <small>插件 / 主题：清单→下载→SHA-256 校验→安全解包→启用（插件自动跑自身迁移）</small></h3>'
            . '<form method="post" action="/admin/?s=market">' . $csrfM
            . '<input type="hidden" name="do" value="market-source">'
            . '<label class="pf" style="display:block;">市场源（留空=内置本地源 var/market/manifest.json；也可填 https://…/manifest.json）</label>'
            . '<input name="source" value="' . e($src) . '" placeholder="https://example.com/yecha/market/manifest.json" style="width:70%;max-width:560px;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;"> '
            . '<button class="btn ghost" type="submit">保存并刷新</button></form></div>'
            . ($mErr !== '' ? '<div class="card"><h3>市场源</h3><p style="color:#9c3f1b;">' . e($mErr) . '</p></div>' : '')
            . '<div class="card"><h3>可安装条目' . (count($items) ? '（' . count($items) . '）' : '') . '</h3>'
            . (count($items)
                ? '<table><tr><th>条目</th><th>版本</th><th>状态</th><th>操作</th></tr>' . $rows . '</table>'
                : '<div class="stub">市场为空或源未配置。上传包到 var/market/ 并写 manifest.json 后可本地安装测试。</div>')
            . '<p style="color:#9c3f1b;font-size:12.5px;margin-top:10px;">⚠️ 安装即执行服务器端代码：仅从可信来源（官方 https 市场源）安装；SHA-256 只防传输损坏，不提供发布者身份签名。</p>'
            . '</div>'
            . '<div class="card"><h3>本机主题</h3><form method="post" action="/admin/?s=market" style="display:flex;gap:8px;align-items:center;max-width:520px;">' . $csrfM
            . '<input type="hidden" name="do" value="market-theme-set">'
            . '<select name="id" style="padding:6px 10px;border:1px solid #e6e6e2;border-radius:6px;">';
        foreach ($themeList as $tid => $tname) {
            $out .= '<option value="' . e($tid) . '"' . ($tid === $curTheme ? ' selected' : '') . '>' . e($tname) . '（' . e($tid) . '）</option>';
        }
        $out .= '</select> <button class="btn ghost" type="submit">启用</button></form></div>'
            . '<div class="card"><h3>说明</h3><ul style="margin:0;padding-left:20px;line-height:1.9;color:#565b61;font-size:13.5px;">'
            . '<li>安装包为 zip；仅接受清单中的 id/type，含 <code>../</code> 或绝对路径的条目会被拒绝。</li>'
            . '<li>更新/重装自动备份旧目录到 <code>var/backup/market/</code>；插件卸载走「插件」页（删数据表+选项，目录保留可随时从市场重装）。</li></ul></div>';
        break;
    }
    case 'plugins': {
        $csrfP = Csrf::field();
        $rows = '';
        foreach (Plugins::all() as $pid => $pd) {
            $isExt = !empty($pd['external']);
            $removed = Plugins::removed($pid);
            $on = Plugins::isOn($pid);
            $extNote = $isExt ? ' <span style="color:#6f767e;font-size:11.5px;">第三方 · plugins/' . e((string)($pd['dir'] ?? $pid)) . '</span>' : '';
            if ($removed) {
                $state = '<span style="color:#9a9a90;">已卸载</span>';
                $act = $isExt
                    ? '<span style="color:#9a9a90;font-size:12px;">可到「云市场」重装</span>'
                    : '<span style="color:#9a9a90;font-size:12px;">恢复需还原卸载前备份</span>';
                $rows .= '<tr style="opacity:.6;"><td><b>' . e($pd['name']) . '</b>' . $extNote . '<div style="color:#6f767e;font-size:12.5px;">' . e($pd['desc']) . '</div></td>'
                    . '<td>v' . e($pd['ver']) . '</td><td>' . $state . '</td><td>' . $act . '</td></tr>';
                continue;
            }
            $state = $on
                ? '<span style="color:#2e6b34;">✔ 启用中</span>'
                : '<span style="color:#9a6b3f;">已停用</span>';
            $act = !empty($pd['toggle'])
                ? '<form method="post" action="/admin/?s=plugins" style="display:inline;">' . $csrfP
                . '<input type="hidden" name="do" value="plugin-toggle"><input type="hidden" name="id" value="' . e($pid) . '">'
                . '<input type="hidden" name="enabled" value="' . ($on ? '0' : '1') . '">'
                . '<button class="btn ghost mini" type="submit">' . ($on ? '停用' : '启用') . '</button></form>'
                : '<span style="color:#9a9a90;font-size:12.5px;">常驻（随核心）</span>';
            $uinfo = Plugins::uninstallInfo($pid);
            if ($uinfo) {
                $act .= '<form method="post" action="/admin/?s=plugins" style="display:inline;" onsubmit="return confirm(\'仅卸载：插件停用并从列表移除，数据表与配置保留（可随时到云市场重装继续用）。确认？\')">' . $csrfP
                    . '<input type="hidden" name="do" value="plugin-uninstall"><input type="hidden" name="id" value="' . e($pid) . '"><input type="hidden" name="purge" value="0">'
                    . '<button class="btn ghost mini" style="border-color:#d9c9b5;color:#8a6d3b;" type="submit">卸载</button></form> '
                    . '<form method="post" action="/admin/?s=plugins" style="display:inline;" onsubmit="return confirm(\'卸载并删除配置：将同时删除插件自有数据表与配置选项（不可恢复，卸载前会自动全库备份）。确认？\')">' . $csrfP
                    . '<input type="hidden" name="do" value="plugin-uninstall"><input type="hidden" name="id" value="' . e($pid) . '"><input type="hidden" name="purge" value="1">'
                    . '<button class="btn ghost mini" style="border-color:#e3c5b6;color:#9c3f1b;" type="submit">卸载并删除配置</button></form>';
            }
            $adminSec = $isExt ? 'plug_' . $pid : (string)($pd['admin'] ?? '');
            $enter = ($on && $adminSec !== '' && $adminSec !== '0') ? ' <a class="btn ghost mini" href="/admin/?s=' . rawurlencode($adminSec) . '">进入 →</a>' : '';
            $rows .= '<tr><td><b>' . e($pd['name']) . '</b>' . $extNote . '<div style="color:#6f767e;font-size:12.5px;">' . e($pd['desc']) . '</div></td>'
                . '<td style="white-space:nowrap;">v' . e($pd['ver']) . '</td>'
                . '<td style="white-space:nowrap;">' . $state . '</td>'
                . '<td style="white-space:nowrap;">' . $act . $enter . '</td></tr>';
        }
        $out = '<div class="card"><h3>插件 <small>内置能力包注册表 · 停用只隐藏功能入口，数据保留</small></h3>'
            . '<p style="color:#6f767e;font-size:13px;margin:0 0 8px;">记事本、评论防骚扰、统计代码位等都以「能力包」登记在 <code>core/Plugins.php</code> 并在此统一管理；多语言、备份还原同理。第三方目录插件与钩子体系见《插件与扩展-架构说明.md》。</p>'
            . '<table><tr><th>插件</th><th>版本</th><th>状态</th><th>操作</th></tr>' . $rows . '</table></div>';
        break;
    }
    case 'upgrade': {
        $csrfU = Csrf::field();
        $rm = Upgrade::remoteStatus();
        $banner = '';
        if ($rm && Upgrade::isUpdateAvailable($rm)) {
            $banner = '<div style="background:#eef6ef;border:1px solid #bcd6c2;border-radius:10px;padding:12px 16px;margin:0 0 12px;">'
                . '<b>发现新版本 v' . e((string)$rm['version']) . '</b> 可在线升级'
                . (!empty($rm['notes']) ? ' — ' . e(mb_substr((string)$rm['notes'], 0, 220)) : '')
                . '<div style="margin-top:8px"><form method="post" action="/admin/?s=upgrade" style="display:inline">' . $csrfU
                . '<input type="hidden" name="do" value="upgrade-remote">'
                . '<button class="btn" type="submit" onclick="return confirm(\'将自动全库备份并在线升级到 v' . e((string)$rm['version']) . '，确认？\')">立即在线升级</button>'
                . '</form></div></div>';
        }
        $st = Upgrade::status();
        $rows = '';
        foreach ($st as $comp => $s) {
            $pend = $s['pending'];
            $pendTxt = $pend
                ? implode('<br>', array_map(fn($f) => e(basename($f)), $pend))
                : '<span style="color:#2e6b34;">无待升级（已是最新）</span>';
            $rows .= '<tr><td><b>' . e($s['name']) . '</b><div style="color:#6f767e;font-size:12px;">v' . e($s['version'])
                . ' · 已应用迁移 ' . $s['applied'] . '</div></td><td>' . $pendTxt . '</td>'
                . '<td><form method="post" action="/admin/?s=upgrade" onsubmit="return confirm(\'执行前将自动生成全库 SQL 备份；建议在“工具→备份”另存一份。确认升级？\')">' . $csrfU
                . '<input type="hidden" name="do" value="upgrade-run"><input type="hidden" name="component" value="' . e($comp) . '">'
                . '<input type="hidden" name="confirm" value="1">'
                . '<button class="btn' . ($pend ? '' : ' ghost') . '" type="submit"' . ($pend ? '' : ' disabled') . '>一键升级</button></form></td></tr>';
        }
        $bk = glob(dirname(__DIR__) . '/var/backup/yecha-*.sql') ?: [];
        rsort($bk);
        $lastBk = $bk ? e(basename($bk[0])) . '（' . date('Y-m-d H:i', filemtime($bk[0])) . '）' : '暂无';
        $log = @file_get_contents(dirname(__DIR__) . '/var/log/upgrade.log');
        $tail = $log ? e(mb_substr($log, -800)) : '（暂无日志）';
        $out = $banner . '<div class="card"><h3>系统升级 <small>一键升级：执行前自动全库备份，迁移逐条记录（ye_migrations）</small></h3>'
            . '<p style="color:#9c3f1b;background:#fdf0ec;border:1px solid #efc8ba;border-radius:8px;padding:8px 12px;font-size:13px;margin:0 0 10px;">'
            . '⚠ 安全提醒：升级会修改数据库结构。每次升级前系统会<b>自动生成全库备份</b>（var/backup）；'
            . '建议先到「工具 → 数据备份」再手动另存一份；生产环境请先在测试站验证。'
            . '迁移文件须幂等（先查后加），失败即停止并记录，可在修复后重试。</p>'
            . '<table><tr><th>组件</th><th>待执行迁移</th><th>操作</th></tr>' . $rows . '</table>'
            . '<div style="margin-top:10px;color:#6f767e;font-size:12.5px;">最近备份：' . $lastBk . '</div></div>'
            . '<div class="card"><h3>更新源 <small>远程更新（GitHub raw / 镜像 manifest.json）——上线发布后配置</small></h3>'
            . '<form method="post" action="/admin/?s=upgrade">' . $csrfU
            . '<input type="hidden" name="do" value="upgrade-config">'
            . '<input name="manifest_url" value="' . e((string)get_option('upgrade.manifest_url', '')) . '" placeholder="https://example.com/yecha/update/manifest.json" style="width:70%;max-width:520px;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;"> '
            . '<button class="btn ghost" type="submit">保存</button>'
            . '<div class="hint">未配置时系统仅支持本地版本与迁移的一键升级；远程校验（SHA-256）与回滚随 GitHub 发布形态接入。</div></form></div>'
            . '<div class="card"><h3>升级日志</h3><pre style="background:var(--soft,#f7f6f2);border:1px solid var(--line,#e6e6e2);border-radius:8px;padding:10px;font-size:12px;overflow:auto;white-space:pre-wrap;margin:0;">' . $tail . '</pre></div>';
        break;
    }
    case 'tools': {
        // 下载备份
        if (($_GET['action'] ?? '') === 'download' && isset($_GET['file'])) {
            $name = (string)$_GET['file'];
            if (preg_match('/^yecha-\d{8}-\d{6}\.sql$/', $name)) {
                $dir = dirname(__DIR__) . '/var/backup';
                $f = $dir . '/' . $name;
                if (is_file($f)) {
                    header('Content-Type: application/sql; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $name . '"');
                    header('Content-Length: ' . filesize($f));
                    readfile($f);
                    exit;
                }
            }
            header('Location: /admin/?s=tools');
            exit;
        }
        $backups = glob(dirname(__DIR__) . '/var/backup/yecha-*.sql') ?: [];
        rsort($backups);
        $list = '';
        foreach (array_slice($backups, 0, 10) as $b) {
            $n = basename($b);
            $list .= '<tr><td><code style="font-size:12px;">' . e($n) . '</code></td><td>' . number_format(filesize($b)) . ' B</td>'
                . '<td>' . e(date('Y-m-d H:i', filemtime($b))) . '</td>'
                . '<td><a class="btn ghost mini" href="/admin/?s=tools&action=download&file=' . rawurlencode($n) . '">下载</a> '
                . '<form method="post" action="/admin/?s=tools" style="display:inline;" onsubmit="return confirm(\'删除该备份文件？此操作不可恢复。\')">' . Csrf::field()
                . '<input type="hidden" name="do" value="backup-del"><input type="hidden" name="file" value="' . e($n) . '">'
                . '<button class="btn ghost mini danger">删除</button></form></td></tr>';
        }
        $out = '<div class="card"><h3>缓存</h3><p style="color:#565b61;margin:0 0 10px;">全页缓存（未登录访客）与侧栏区块缓存均为文件驱动；写事件自动失效，这里提供兜底清理。</p>'
            . '<form method="post" action="/admin/?s=tools"><input type="hidden" name="do" value="flush-cache">' . Csrf::field()
            . '<button class="btn ghost" type="submit">清空全部缓存</button></form></div>'
            . '<div class="card"><h3>数据备份（导出）</h3><p style="color:#565b61;margin:0 0 10px;">生成全库 SQL（全部 ' . e(cfg('DB_PREFIX', 'ye_')) . ' 前缀表：结构 + 数据），保存到 var/backup。还原方式：<code>mysql -uyecha -p yecha &lt; 备份文件.sql</code>（在 docker mysql 容器内执行）。</p>'
            . '<form method="post" action="/admin/?s=tools">' . Csrf::field()
            . '<input type="hidden" name="do" value="backup-create"><button class="btn" type="submit">立即备份</button></form></div>'
            . '<div class="card"><h3>最近备份</h3><table><tr><th>文件</th><th>大小</th><th>时间</th><th>操作</th></tr>' . ($list !== '' ? $list : '<tr><td colspan="4" class="hint">还没有备份</td></tr>') . '</table></div>'
            . '<div class="card"><h3>还原（危险操作，请先备份）</h3>'
            . '<p style="color:#9c3f1b;margin:0 0 10px;font-size:13px;">将执行所选备份文件中的全部语句（可覆盖当前数据）。确认前请确保已生成最新备份。</p>'
            . '<form method="post" action="/admin/?s=tools" enctype="multipart/form-data" onsubmit="return confirm(\'确认用该备份还原数据库？此操作不可撤销。\')">' . Csrf::field()
            . '<input type="hidden" name="do" value="backup-restore">'
            . ($backups
                ? '<div style="margin-bottom:10px;">' . implode('', array_map(function (string $b) {
                    return '<label style="display:block;font-weight:400;font-size:13px;"><input type="radio" name="file" value="' . e(basename($b)) . '"> ' . e(basename($b)) . '</label>';
                }, array_slice($backups, 0, 8))) . '</div>'
                : '<p class="hint">暂无备份文件可选，请先「立即备份」或上传。</p>')
            . '<label class="pf">或上传 .sql：</label><input type="file" name="restore_file" accept=".sql">'
            . '<div style="margin-top:10px;"><button class="btn" style="background:#9c3f1b;" type="submit">开始还原</button></div></form></div>'
            . '<div class="card"><h3>系统修复</h3><div class="stub">表缺失 / 计数偏差 / 权限诊断与修复将在此提供（修复前建议先生成备份）。</div></div>';
        break;
    }

    default:
        $out = $stub('模块不存在或已停用');
}

/* 第三方插件后台页：/admin/?s=plug_<id>（其 admin 函数自行处理 POST 与渲染） */
if (str_starts_with($sec, 'plug_')) {
    $plugId = substr($sec, 5);
    $pm = Plugins::ext($plugId);
    if ($pm !== null && Plugins::isOn($plugId) && !empty($pm['admin'])) {
        $out = Plugins::adminPage($plugId);
    }
}

if ($msg !== '') {
    $out = '<div class="' . ($msgErr ? 'err' : 'ok') . '">' . htmlspecialchars($msg) . '</div>' . $out;
}
adm_render($MODULES[$sec][0] ?? '记事本（已停用）', $sec, $out);
