<?php
declare(strict_types=1);
// [2026-09-27 官网恢复] 本文件内指向「应用市场说明页」的地址已由 /market.html 改为 /market-guide.html。
// 原因：market 是 core/Router.php 的 RESERVED 保留字，/{slug}.html 对保留字恒 404。
// 注意：升级/覆盖本插件会静默丢回旧地址（旧地址会 404）。
// 市场收录 · 前台申请页（/market-app）
require_once __DIR__ . '/market_lib.php';
require_once __DIR__ . '/showcase.php';

function yc_market_page(string $slug): array
{
    // t38：主题页 / 插件页（列表 + 详情，见 showcase.php）
    if ($slug === 'theme' || $slug === 'plugin') { return yc_sc_page($slug); }
    // t39：应用市场汇总页（/market：主题 / 插件两块 + 计数）
    if ($slug === 'market') {
        $GLOBALS['YC_NO_SIDE'] = true;
        $GLOBALS['YC_SB_TYPE'] = 'page';
        return ['html' => yc_sc_market(), 'title' => (yc_sc_en() ? 'App market' : '应用市场') . ' · ' . site_label()];
    }
    // t38：下载中心（完整包 + 主题包 + 插件包 + 文档入口）
    if ($slug === 'downloads') {
        $GLOBALS['YC_NO_SIDE'] = true;
        $GLOBALS['YC_SB_TYPE'] = 'page';
        return ['html' => yc_sc_downloads(), 'title' => (Poly::on() && Lang::short() !== Poly::defaultLang() ? 'Download center' : '下载中心') . ' · ' . site_label()];
    }
    if ($slug === 'store') {
        $GLOBALS['YC_NO_SIDE'] = true;
        $GLOBALS['YC_SB_TYPE'] = 'page';
        // t38：英文版应用市场走同一份本地目录数据（界面全英文）
        if (Poly::on() && Lang::short() !== Poly::defaultLang()) {
            return ['html' => yc_sc_store_en(), 'title' => 'App market · ' . site_label()];
        }
        return ['html' => yc_market_store_html(), 'title' => '应用商店 · ' . site_label()];
    }
    $ok = '';
    $err = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $err = yc_market_apply_handle();
        $ok = $err === '' ? 'ok' : '';
    }
    $state = $err !== '' ? $err : $ok;
    $note = '';
    if ($state === 'ok') {
        $note = '<p style="color:#2e6b34;background:#eef4ee;border:1px solid #cfe2d0;border-radius:8px;padding:10px 14px;">已收到上架申请 ✔ 我们会在 3–7 天内验证（下载→结构/清单/PHP 语法/SHA 检查），通过后发布到应用市场；不通过会说明原因，可改进后重新提交。</p>';
    } elseif ($state !== '') {
        $note = '<p style="color:#c0392b;background:#fdf0ec;border:1px solid #efc8ba;border-radius:8px;padding:10px 14px;">' . e($state) . '</p>';
    }
    $csrf = \Csrf::field();
    $rules = '<div class="yc-card"><h2 class="yc-h2">上架规范 · 让市场干净可用</h2>'
        . '<ul style="padding-left:20px;line-height:2.05;color:#333;">'
        . '<li><b>类型</b>：插件（含 plugin.php 清单与幂等迁移）或主题（含 CSS 与模板）。</li>'
        . '<li><b>命名</b>：id 用小写字母/数字/下划线（2–40 位）；版本号建议 主.次.修订。</li>'
        . '<li><b>打包</b>：zip 根目录即包内容；不得含绝对路径/穿越路径；≤30MB。</li>'
        . '<li><b>质量</b>：包内 PHP 必须全部通过 <code>php -l</code>；不含明显后门/外联窃密代码（人工+工具复核）。</li>'
        . '<li><b>填写</b>：提供可下载 zip 的 http(s) 地址；SHA-256 选填但强烈建议。</li>'
        . '<li><b>时效</b>：提交后 3–7 天人工验证；不通过会写原因；发布后如需下架可联系。</li>'
        . '<li>发布即代表你同意：以 MIT/兼容协议分发、清单真实、出现问题可被下架。</li>'
        . '</ul><p style="color:#9a9a90;font-size:12.5px;">想先看官方示例？<a href="/market-guide.html">应用市场 →</a></p></div>';
    $form = '<div class="yc-card"><h2 class="yc-h2">提交上架申请</h2>' . $note
        . '<form method="post" action="/market-app">' . $csrf
        . '<input type="text" name="website2" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">'
        . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 16px;">'
        . '<div style="grid-column:1/-1;"><label class="pf">应用类型 *</label><select name="type" required style="width:100%;"><option value="plugin">插件</option><option value="theme">主题</option></select></div>'
        . '<div><label class="pf">ID（小写英文，唯一标识）*</label><input name="app_id" required maxlength="40" pattern="[a-z0-9_]+" placeholder="如 my-plugin"></div>'
        . '<div><label class="pf">名称 *</label><input name="name" required maxlength="60"></div>'
        . '<div><label class="pf">版本 *</label><input name="ver" required maxlength="24" placeholder="1.0.0"></div>'
        . '<div><label class="pf">作者 / 署名 *</label><input name="author" required maxlength="60"></div>'
        . '<div style="grid-column:1/-1;"><label class="pf">一句话说明 *</label><input name="description" required maxlength="200"></div>'
        . '<div style="grid-column:1/-1;"><label class="pf">可下载的 zip 地址（http/https）*</label><input name="url" required type="url" maxlength="600" placeholder="https://example.com/my-plugin.zip"></div>'
        . '<div><label class="pf">SHA-256（选填）</label><input name="sha_claim" maxlength="80" placeholder="64 位十六进制"></div>'
        . '<div><label class="pf">常用邮箱（验证与上架结果通知；不公开）</label><input name="email" type="email" required maxlength="190"></div>'
        . '</div>'
        . '<button class="btn" type="submit" style="margin-top:14px;width:100%;border:0;">提交申请</button></form></div>';
    $inner = '<div class="page-head" style="max-width:none;"><h1 style="font-size:28px;">申请上架到应用市场</h1>'
        . '<span class="sub" style="display:block;color:#6f767e;max-width:820px;line-height:1.9;">YeCha 应用市场是官方审核的插件/主题目录：先规范、后验证、再发布。我们保证包里没有明显危险、语法可跑、清单有效；但上线后的行为仍需你持续维护。</span></div>'
        . '<div class="mkt-two">' . $rules . $form . '</div>';
    $GLOBALS['YC_NO_SIDE'] = true;
    $GLOBALS['YC_SB_TYPE'] = 'page';
    return ['html' => yc_market_page_css() . $inner, 'title' => '申请上架应用 · ' . site_label()];
}

function yc_market_page_css(): string
{
    return '<style>.pf{display:block;font-size:12.5px;color:#6f767e;margin:6px 0 3px}.pf+input,.pf+select{width:100%;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:8px;padding:8px 11px;font:inherit}.mkt-two{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:22px}.yc-card{border:1px solid var(--line,#e6e6e2);border-radius:14px;padding:16px 18px;background:#fff}.yc-h2{font-size:17px;font-weight:700;margin:0 0 8px;padding-left:10px;border-left:3px solid #b3541e}@media(max-width:820px){.mkt-two{grid-template-columns:1fr}}</style>';
}

/** 应用商店（卡片式，读市场 manifest 动态渲染） */
function yc_market_store_html(): string
{
    $mf = mkt_manifest_path();
    $items = is_file($mf) ? ((json_decode((string)file_get_contents($mf), true)['items'] ?? []) ?: []) : [];
    $items = array_values(array_filter($items, fn($x) => is_array($x)));
    $type = (string)($_GET['type'] ?? '');
    if (!in_array($type, ['plugin', 'theme'], true)) { $type = ''; }
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 40);
    $list = array_filter($items, function ($i) use ($type, $q) {
        if ($type !== '' && ($i['type'] ?? '') !== $type) { return false; }
        if ($q !== '' && mb_stripos((string)($i['name'] ?? '') . ' ' . (string)($i['desc'] ?? '') . ' ' . (string)($i['author'] ?? '') . ' ' . (string)($i['id'] ?? ''), $q) === false) { return false; }
        return true;
    });
    $ic = function (array $i): string {
        $map = ['links' => '🧩', 'octern' => '🎨'];
        if (isset($i['icon']) && (string)$i['icon'] !== '') { return (string)$i['icon']; }
        return $map[$i['id'] ?? ''] ?? (($i['type'] ?? '') === 'theme' ? '🎨' : '🧩');
    };
    $detail = function (array $i): string {
        $url = (string)($i['url'] ?? '');
        if ($url === '') { $url = ($i['id'] ?? '') === 'octern' ? '/warmpaper.html' : (($i['id'] ?? '') === 'links' ? '/links' : '/market-guide.html'); }
        return $url;
    };
    $cards = '';
    foreach ($list as $i) {
        $typeZh = ($i['type'] ?? '') === 'theme' ? '主题' : '插件';
        $cards .= '<a class="st-card" href="' . e($detail($i)) . '">'
            . '<div class="st-ic">' . $ic($i) . '</div>'
            . '<div class="st-body"><div class="st-top"><span class="st-chip st-' . (($i['type'] ?? '') === 'theme' ? 't' : 'p') . '">' . $typeZh . '</span>'
            . '<span class="st-ver">v' . e((string)($i['ver'] ?? '')) . '</span></div>'
            . '<b class="st-name">' . e((string)($i['name'] ?? $i['id'])) . '</b>'
            . '<div class="st-desc">' . e(mb_substr((string)($i['desc'] ?? ''), 0, 120)) . '</div>'
            . '<div class="st-meta">' . e((string)($i['author'] ?? '')) . ' · <span class="st-go">详情 →</span></div>'
            . '</div></a>';
    }
    if ($cards === '') { $cards = '<div class="empty">没有匹配的应用。换个筛选词，或 <a href="/market-app">申请上架你的作品</a>。</div>'; }
    $tabs = '';
    foreach ([['', '全部'], ['plugin', '插件'], ['theme', '主题']] as $t) {
        $tabs .= '<a class="st-tab' . ($type === $t[0] ? ' on' : '') . '" href="/store' . ($t[0] !== '' ? '?type=' . $t[0] : '') . '">' . $t[1] . '</a>';
    }
    return '<style>'
        . '.st-hero h1{font-size:28px;margin:20px 0 6px}.st-hero p{color:#6f767e;max-width:780px;line-height:1.9;margin:0 0 4px}'
        . '.st-tabs{display:flex;gap:6px;margin:16px 0 6px}.st-tab{padding:6px 16px;border-radius:999px;border:1px solid #d9dee3;color:#333;text-decoration:none;font-size:14px;background:#fff}.st-tab.on{background:#AC4429;border-color:#AC4429;color:#fff;font-weight:600}.st-tab:hover{border-color:#AC4429;color:#AC4429}'
        . '.st-search{margin:10px 0 4px;display:flex;gap:8px;max-width:420px}.st-search input{flex:1;border:1px solid #d9dee3;border-radius:999px;padding:7px 14px;font:inherit}.st-search .btn{border-radius:999px}'
        . '.st-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;margin-top:16px}'
        . '.st-card{display:flex;gap:12px;border:1px solid var(--line,#e6e6e2);background:#fff;border-radius:16px;padding:16px;text-decoration:none;color:inherit;transition:box-shadow .15s,transform .15s}.st-card:hover{box-shadow:0 10px 26px rgba(0,0,0,.08);transform:translateY(-2px)}'
        . '.st-ic{width:52px;height:52px;border-radius:13px;flex:none;display:flex;align-items:center;justify-content:center;font-size:26px;background:#F7EDE0}'
        . '.st-body{min-width:0}.st-top{display:flex;align-items:center;gap:6px}.st-chip{font-size:11px;border-radius:999px;padding:1px 9px}.st-chip.p{background:#E7EFEA;color:#2e6b34}.st-chip.t{background:#F3E2D2;color:#8F3520}.st-ver{margin-left:auto;color:#9a9a90;font-size:11px}'
        . '.st-name{display:block;font-size:15.5px;margin:6px 0 4px;font-family:var(--font-serif,"Noto Serif SC",serif);color:#1f2328}.st-desc{color:#6f767e;font-size:12.5px;line-height:1.7;min-height:2.4em}.st-meta{color:#9a9a90;font-size:12px;margin-top:6px}.st-go{color:#AC4429}'
        . '@media(max-width:600px){.st-grid{grid-template-columns:1fr}}'
        . '</style>'
        . '<div class="st-hero"><h1>YeCha 应用商店</h1><p>主题与插件，卡片式挑应用；数据来自本站市场清单，站长在后台「云市场」安装启用。想上架？<a href="/market-app">申请上架 →</a></p></div>'
        . '<div class="st-tabs">' . $tabs . '</div>'
        . '<form class="st-search" method="get" action="/store">' . ($type !== '' ? '<input type="hidden" name="type" value="' . e($type) . '">' : '') . '<input name="q" value="' . e($q) . '" placeholder="搜索应用…"><button class="btn" type="submit">搜</button></form>'
        . ($type !== '' || $q !== '' ? '<p style="color:#9a9a90;font-size:13px;">当前：' . ($type === 'plugin' ? '插件' : ($type === 'theme' ? '主题' : '')) . ($q !== '' ? ' · “' . e($q) . '”' : '') . '，共 ' . count($list) . ' 个。</p>' : '')
        . '<div class="st-grid">' . $cards . '</div>'
        . '<p style="color:#9a9a90;font-size:12.5px;margin-top:18px;">安装：登录后台 → 云市场 → 对应应用「安装/更新」（SHA-256 强校验 + 安全解包）。<a href="/market-app">上架规范</a> · <a href="/market-guide.html">市场说明</a></p>';
}

function yc_market_apply_handle(): string
{
    try { \Csrf::check(); } catch (Throwable $e) { return '表单校验失败，请刷新后重试'; }
    if (trim((string)($_POST['website2'] ?? '')) !== '') { return '提交过于频繁，请稍后再试'; }
    $ipKey = 'market_apply:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if ((int)\Cache::get('throttle', $ipKey) >= 5) { return '提交过于频繁，请 10 分钟后再试'; }
    $type = (($_POST['type'] ?? '') === 'theme') ? 'theme' : 'plugin';
    $id = strtolower(trim((string)($_POST['app_id'] ?? '')));
    $name = trim((string)($_POST['name'] ?? ''));
    $ver = trim((string)($_POST['ver'] ?? ''));
    $author = trim((string)($_POST['author'] ?? ''));
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 200);
    $url = trim((string)($_POST['url'] ?? ''));
    $sha = strtolower(trim((string)($_POST['sha_claim'] ?? '')));
    $email = trim((string)($_POST['email'] ?? ''));
    if ($name === '' || $id === '' || $ver === '' || $author === '' || $desc === '' || $url === '' || $email === '') { return '请完整填写申请'; }
    if (!preg_match('/^[a-z0-9_]{2,40}$/D', $id)) { return 'ID 需为 2–40 位小写字母/数字/下划线'; }
    if (!preg_match('#^https?://#i', $url) || strlen($url) > 600) { return '下载地址需为合法 http(s) URL'; }
    if ($sha !== '' && !preg_match('/^[a-f0-9]{64}$/D', $sha)) { return 'SHA-256 应为 64 位十六进制'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) { return '邮箱格式不正确'; }
    $t = mkt_table();
    $dup = Db::one("SELECT id FROM {$t} WHERE app_id=? AND status NOT IN ('rejected','published') LIMIT 1", [$id]);
    if ($dup !== null) { return '该 ID 已有进行中的申请，请等结果或联系站长'; }
    Db::insert('market_reqs', [
        'type' => $type, 'app_id' => $id, 'name' => $name, 'ver' => $ver, 'author' => $author,
        'description' => $desc, 'url' => $url, 'sha_claim' => $sha, 'email' => $email,
        'status' => 'pending', 'note' => '', 'checked_note' => '',
    ]);
    \Cache::incr('throttle', $ipKey, 600);
    return '';
}
