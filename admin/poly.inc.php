<?php
declare(strict_types=1);
/**
 * 后台「多语」模块：翻译状态总览（可切换语言）/ 逐语言工作台（左源右译）/ AI 生成草稿 / 批量翻译 / 语言与模式管理 / AI 设置
 * 由 admin/index.php 在 section=poly 下调用（POST 的 CSRF 已在外层校验）。
 * 基准语言（默认中文）以外的任意启用语言均可翻译，数据表 ye_poly_contents 按 lang 分行。
 */

function poly_do(string $do): never
{
    if ($do === 'poly-config') {
        $base = trim((string)($_POST['ai_base'] ?? ''));
        $key = trim((string)($_POST['ai_key'] ?? ''));
        $model = trim((string)($_POST['ai_model'] ?? ''));
        $protect = trim((string)($_POST['ai_protect'] ?? ''));
        set_option('ai.base', $base === '' ? 'https://api.deepseek.com/chat/completions' : $base);
        if ($key !== '' && !str_starts_with($key, 'enc:')) { yc_opt_set('ai.key', $key); } // 空=保留；仅存密文
        set_option('ai.model', $model === '' ? 'deepseek-chat' : $model);
        set_option('ai.protect', $protect);
        // 注意：此处不再改动 site.languages —— 语言启用在「语言管理」卡片单独保存
        $_SESSION['flash'] = 'AI 设置已保存（语言启用以「语言管理」卡片为准）';
        header('Location: /admin/?s=poly');
        exit;
    }
    if ($do === 'poly-batch') {
        $code = trim((string)($_POST['lang'] ?? ''));
        if (!in_array($code, Poly::enabled(), true) || $code === Poly::defaultLang()) { $code = 'en'; }
        $label = Poly::label($code);
        $mode = (($_POST['mode'] ?? '') === 'stale') ? 'stale' : 'missing';
        if (!yc_opt_has('ai.key')) {
            $_SESSION['flash'] = '批量翻译需要 AI Key：请先在「AI 设置」卡片填写 API Key';
            header('Location: /admin/?s=poly');
            exit;
        }
        $T = Db::t('contents');
        $P = Db::t('poly_contents');
        $types = "c.type IN ('post','page')";
        if ($mode === 'stale') {
            $ss0 = Poly::staleStats($code);
            $actionable = $ss0['stale'] - $ss0['locked'];
            if ($actionable <= 0) {
                $_SESSION['flash'] = "{$label}：没有可自动重译的过期译文" . ($ss0['locked'] > 0 ? "（{$ss0['locked']} 篇为人工锁定，请手动更新）" : '') . ' ✔';
                header('Location: /admin/?s=poly');
                exit;
            }
            $deadline = microtime(true) + 26;
            $ok = 0; $fail = 0; $consec = 0; $lastErr = '';
            while (microtime(true) < $deadline) {
                $next = Db::row(
                    "SELECT c.id, c.title FROM {$T} c JOIN {$P} p ON p.group_id=c.id "
                    . "WHERE {$types} AND c.status='publish' AND c.deleted_at IS NULL AND p.lang=? AND p.status='publish' AND p.stale=1 AND p.manual_override=0 "
                    . "ORDER BY c.id ASC LIMIT 1",
                    [$code]
                );
                if ($next === null) { break; }
                $res = Poly::aiTranslate((int)$next['id'], $code, '', '', '', true);
                if ($res['ok'] ?? false) { $ok++; $consec = 0; } else { $fail++; $consec++; $lastErr = (string)($res['error'] ?? ''); }
                if ($consec >= 2) { break; }
            }
            $ss2 = Poly::staleStats($code);
            $msg = "{$label}过期重译：本次成功 {$ok} 篇" . ($fail ? "，失败 {$fail} 篇" . ($lastErr !== '' ? "（末次：{$lastErr}）" : '') : '')
                . "。剩余过期 {$ss2['stale']} 篇（锁 {$ss2['locked']}）。";
            if ($ss2['stale'] - $ss2['locked'] === 0) { $msg .= ' 全部已更新 ✔'; } else { $msg .= ' 再点一次继续推进。'; }
            $_SESSION['flash'] = $msg;
            header('Location: /admin/?s=poly');
            exit;
        }
        $st = Poly::stats($code);
        if ($st['missing'] === 0) {
            $_SESSION['flash'] = "{$label}：全部 {$st['total']} 篇内容（文章+独立页）都已有已发布译文，无需翻译 ✔";
            header('Location: /admin/?s=poly');
            exit;
        }
        $T = Db::t('contents');
        $P = Db::t('poly_contents');
        $deadline = microtime(true) + 26; // 每次请求最多跑 ~26 秒，避免超时
        $ok = 0; $fail = 0; $consec = 0; $lastErr = '';
        while (microtime(true) < $deadline) {
            $next = Db::row(
                "SELECT c.id, c.title FROM {$T} c WHERE {$types} AND c.status='publish' AND c.deleted_at IS NULL "
                . "AND NOT EXISTS (SELECT 1 FROM {$P} p WHERE p.group_id=c.id AND p.lang=? AND p.status='publish') ORDER BY c.id ASC LIMIT 1",
                [$code]
            );
            if ($next === null) { break; }
            $res = Poly::aiTranslate((int)$next['id'], $code, '', '', '', true);
            if ($res['ok'] ?? false) { $ok++; $consec = 0; } else { $fail++; $consec++; $lastErr = (string)($res['error'] ?? ''); }
            if ($consec >= 2) { break; } // 连续失败就停下，避免空转
        }
        $st2 = Poly::stats($code);
        $msg = "{$label}批量翻译：本次成功 {$ok} 篇" . ($fail ? "，失败 {$fail} 篇" . ($lastErr !== '' ? "（末次：{$lastErr}）" : '') : '')
            . "。已发布 {$st2['published']}/{$st2['total']}，剩余 {$st2['missing']}。";
        if ($st2['missing'] === 0) { $msg .= ' 全部完成 ✔'; } else { $msg .= ' 再点一次「继续翻译」即可推进（也可用服务器命令一次跑完）。'; }
        $_SESSION['flash'] = $msg;
        header('Location: /admin/?s=poly');
        exit;
    }
    if ($do === 'poly-langs') {
        $cats = Poly::catalog();
        $def = Poly::defaultLang();
        $clean = [];
        foreach ((array)($_POST['langs'] ?? []) as $c) {
            $c = preg_replace('/[^a-z]/', '', strtolower((string)$c)) ?? '';
            if ($c !== '' && isset($cats[$c]) && !in_array($c, $clean, true)) { $clean[] = $c; }
        }
        if (!in_array($def, $clean, true)) { array_unshift($clean, $def); }
        set_option('site.languages', json_encode($clean, JSON_UNESCAPED_UNICODE));
        Cache::flush('pages');
        Cache::flush('blocks');
        $_SESSION['flash'] = '已启用语言：' . implode(' / ', array_map(fn($c) => Poly::label($c), $clean));
        header('Location: /admin/?s=poly');
        exit;
    }
    if ($do === 'poly-modes') {
        set_option('poly.mode_default', (($_POST['mode_default'] ?? '') === 'ai') ? 'ai' : 'manual');
        foreach ((array)($_POST['mode'] ?? []) as $code => $m) {
            $code = preg_replace('/[^a-z]/', '', strtolower((string)$code)) ?? '';
            if ($code === '') { continue; }
            $m = (string)$m;
            set_option('poly.mode.' . $code, ($m === 'ai' || $m === 'manual') ? $m : '');
        }
        Cache::flush('pages');
        Cache::flush('blocks');
        $_SESSION['flash'] = '翻译模式已保存（AI 全自动语言：译文内容由 AI 维护，可逐篇手动修正锁定；人工双语语言：日常双语工作台编辑）';
        header('Location: /admin/?s=poly');
        exit;
    }
    $baseId = (int)($_POST['base_id'] ?? 0);
    $lang = trim((string)($_POST['lang'] ?? 'en'));
    if ($baseId <= 0 || !in_array($lang, Poly::enabled(), true) || $lang === Poly::defaultLang()) {
        $_SESSION['flash'] = '参数错误';
        header('Location: /admin/?s=poly');
        exit;
    }
    $label = Poly::label($lang);
    $back = '/admin/?s=poly&action=edit&id=' . $baseId . '&lang=' . rawurlencode($lang);
    if ($do === 'ai-generate') {
        $res = Poly::aiTranslate($baseId, $lang, '', '', '', false, true); // 显式单篇 AI：允许重译并清除 manual_override
        $_SESSION['flash'] = $res['ok']
            ? 'AI 已生成' . $label . '草稿（ai_generated=1），请审校后保存发布'
            : ('AI 失败：' . ($res['error'] ?? ''));
        header('Location: ' . $back);
        exit;
    }
    if ($do === 'poly-save') {
        $res = Poly::save($baseId, $lang, [
            'title' => (string)($_POST['title'] ?? ''),
            'slug' => (string)($_POST['slug'] ?? ''),
            'excerpt' => (string)($_POST['excerpt'] ?? ''),
            'keywords' => (string)($_POST['keywords'] ?? ''),
            'description' => (string)($_POST['description'] ?? ''),
            'content' => (string)($_POST['content'] ?? ''),
            'status' => (string)($_POST['status'] ?? 'draft'),
        ]);
        $_SESSION['flash'] = $res['ok'] ? $label . '版已保存' . (((string)($_POST['status'] ?? 'draft') === 'publish') ? '并发布' : '（草稿）') . '（写事件已清缓存）' : ('保存失败：' . ($res['error'] ?? ''));
        header('Location: ' . $back);
        exit;
    }
    header('Location: /admin/?s=poly');
    exit;
}

/** 总览中当前查看的语言（首个非默认启用语言，缺省 en） */
function poly_cur_lang(): string
{
    $langs = array_values(array_filter(Poly::enabled(), fn($c) => $c !== Poly::defaultLang()));
    return $langs[0] ?? 'en';
}

function poly_overview_html(string $cur): string
{
    $T = Db::t('');
    $def = Poly::defaultLang();
    // 语言切换芯片
    $chips = [];
    foreach (Poly::enabled() as $code) {
        if ($code === $def) { continue; }
        $st = Poly::stats($code);
        $ss = Poly::staleStats($code);
        $on = $code === $cur;
        $badge = $ss['stale'] > 0
            ? '<span style="display:block;font-size:10px;color:#b3541e;margin-top:2px;">' . $ss['stale'] . ' 篇过期</span>'
            : '<span style="display:block;font-size:10.5px;opacity:.85;margin-top:2px;">' . $st['published'] . '/' . $st['total'] . '</span>';
        $chips[] = '<a href="/admin/?s=poly&lang=' . rawurlencode($code) . '" style="display:inline-block;min-width:86px;text-align:center;padding:6px 12px;border-radius:8px;text-decoration:none;font-size:13.5px;border:1px solid #e6e6e2;'
            . ($on ? 'background:#b3541e;color:#fff;border-color:#b3541e;' : 'background:#fafaf7;color:#333;') . '">' . e(Poly::label($code)) . $badge . '</a>';
    }
    $chipRow = '<div class="card" style="padding:10px 16px;margin-bottom:14px;"><span style="color:#6f767e;font-size:12.5px;margin-right:8px;">查看语言：</span>' . implode(' ', $chips)
        . '<span style="margin-left:10px;color:#9a9a90;font-size:12px;">基准语言（' . e(Poly::label($def)) . '）为原文；数字=已发布/全部；「N 篇过期」=中文原文修改后待重译（基准保存时自动标记）。</span></div>';

    $rows = Db::rows("SELECT * FROM {$T}contents WHERE type IN ('post','page') AND deleted_at IS NULL ORDER BY type ASC, id DESC LIMIT 400");
    $map = [];
    foreach (Db::rows('SELECT group_id, title, slug, status, ai_generated, manual_override, stale, updated_at FROM ' . Poly::table() . ' WHERE lang=?', [$cur]) as $e) {
        $map[(int)$e['group_id']] = $e;
    }
    $label = Poly::label($cur);
    $list = '';
    foreach ($rows as $r) {
        $row = $map[(int)$r['id']] ?? null;
        $typeTag = $r['type'] === 'page'
            ? '<span style="display:inline-block;font-size:10.5px;border:1px solid #d8cfc0;border-radius:4px;padding:0 5px;margin-right:6px;color:#8a7355;vertical-align:1px;">独立页</span>'
            : '';
        $mo = $row && (int)($row['manual_override'] ?? 0) === 1 ? ' · 人工锁定' : '';
        $staleTag = $row && (int)($row['stale'] ?? 0) === 1 && $row['status'] === 'publish'
            ? ' · <span style="color:#b3541e;">原文已改/过期</span>' : '';
        $state = $row === null
            ? '<span style="color:#9a9a90;">未翻译</span>'
            : ($row['status'] === 'publish'
                ? '<span style="color:#2e6b34;">已发布' . ($row['ai_generated'] ? '（AI）' : '') . $mo . '</span>' . $staleTag
                : '<span style="color:#9a6b3f;">草稿' . ($row['ai_generated'] ? '（AI 待审校）' : '') . $mo . '</span>');
        $list .= '<tr><td>' . $typeTag . e($r['title']) . '</td><td>' . e((string)$r['slug']) . '</td><td>' . $state
            . '</td><td>' . ($row ? e(substr((string)$row['updated_at'], 0, 10)) : '—')
            . '</td><td><a class="btn ghost mini" href="/admin/?s=poly&action=edit&id=' . (int)$r['id'] . '&lang=' . rawurlencode($cur) . '">' . ($row ? '审校 / 编辑' : '翻译为 ' . e($label)) . '</a>'
            . ' <a class="btn ghost mini" target="_blank" href="/admin/?s=' . $r['type'] . 's&action=edit&id=' . (int)$r['id'] . '&lang=' . rawurlencode($cur) . '">' . ($r['type'] === 'page' ? '页面编辑器' : '文章编辑器') . ' ↗</a></td></tr>';
    }
    return $chipRow
        . poly_langs_card()
        . poly_modes_card()
        . '<div class="card"><h3>多语 · 翻译组总览 <small>' . e(Poly::label($def)) . '(默认) ↔ ' . e($label) . '（' . e($cur) . '）· ye_poly_contents 按语言分行；覆盖全部已发布文章与独立页；逐语言工作台与编辑器均可编辑</small></h3>'
        . '<table><tr><th>' . e(Poly::label($def)) . '内容</th><th>slug</th><th>' . e($label) . '状态</th><th>更新时间</th><th>操作</th></tr>' . $list . '</table></div>'
        . poly_batch_card()
        . poly_settings_card();
}

function poly_langs_card(): string
{
    $enabled = Poly::enabled();
    $cats = Poly::catalog();
    $def = Poly::defaultLang();
    $rows = '';
    foreach ($cats as $code => $meta) {
        $on = in_array($code, $enabled, true);
        $isDef = $code === $def;
        $pack = is_file(dirname(__DIR__) . '/lang/' . $meta['pack'] . '.php') ? '<span style="color:#2e6b34;">界面包✓</span>' : '<span style="color:#9a6b3f;">界面包—</span>';
        $st = $isDef ? '<span style="color:#2e6b34;">基准</span>' : (Poly::on() && $on ? Poly::stats($code)['published'] . ' 篇已发布' : '—');
        $rows .= '<label style="display:flex;align-items:center;gap:8px;padding:5px 10px;border:1px solid #e6e6e2;border-radius:8px;font-size:13.5px;'
            . (!$on ? 'opacity:.55;' : '') . '"><input type="checkbox" name="langs[]" value="' . e($code) . '"' . ($on || $isDef ? ' checked' : '') . ($isDef ? ' disabled' : '') . '> '
            . e($meta['name']) . ' <span style="color:#9a9a90;font-size:12px;">' . $code . ($meta['dir'] === 'rtl' ? ' · RTL' : '') . '</span>'
            . '<span style="margin-left:auto;color:#6f767e;font-size:12px;">' . $st . ' · ' . $pack . '</span></label>';
    }
    return '<div class="card"><h3>语言管理 <small>勾选启用；基准语言（' . e(Poly::label($def)) . '）固定；关闭某语言=该语言前台消失，数据保留（重新勾选即恢复）。修改后请清一次缓存。</small></h3>'
        . '<form method="post" action="/admin/?s=poly">' . Csrf::field()
        . '<input type="hidden" name="do" value="poly-langs">'
        . '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:6px;">' . $rows . '</div>'
        . '<button class="btn ghost" style="margin-top:10px;" type="submit">保存语言设置</button></form></div>';
}

function poly_modes_card(): string
{
    $def = Poly::modeDefault();
    $opts = function (string $cur): string {
        $sel = fn(string $v) => $v === $cur ? ' selected' : '';
        return '<select name="mode_default"><option value="manual"' . $sel('manual') . '>人工 / 双语（保留字段编辑）</option>'
            . '<option value="ai"' . $sel('ai') . '>AI 全自动（内容只读，可逐篇手动修正）</option></select>';
    };
    $rows = '';
    $enabled = Poly::enabled();
    $defLang = Poly::defaultLang();
    foreach ($enabled as $lc) {
        if ($lc === $defLang) { continue; }
        $ov = (string)get_option('poly.mode.' . $lc, '');
        $eff = Poly::mode($lc);
        $sel = fn(string $v) => $v === $ov ? ' selected' : '';
        $rows .= '<div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px dashed #ecebe4;">'
            . '<span style="width:130px;">' . e(Poly::label($lc)) . '</span>'
            . '<span style="color:#6f767e;font-size:12.5px;">当前：' . ($eff === 'ai' ? 'AI 全自动' : '人工/双语') . '</span>'
            . '<select name="mode[' . e($lc) . ']" style="margin-left:auto;">'
            . '<option value=""' . $sel('') . '>跟站点默认</option>'
            . '<option value="manual"' . $sel('manual') . '>人工 / 双语</option>'
            . '<option value="ai"' . $sel('ai') . '>AI 全自动</option></select></div>';
    }
    return '<div class="card"><h3>翻译模式 <small>站点默认 + 每语言覆盖。AI 全自动：日常由 AI 批量维护，双语工作台仍可「手动修正」（自动打 manual_override，之后全量重译不再覆盖）；人工/双语：日常双语工作台编辑为主。</small></h3>'
        . '<form method="post" action="/admin/?s=poly">' . Csrf::field()
        . '<input type="hidden" name="do" value="poly-modes">'
        . '<div style="display:flex;align-items:center;gap:10px;padding-bottom:8px;"><span style="width:130px;">站点默认</span>' . $opts($def) . '</div>'
        . $rows
        . '<button class="btn ghost" style="margin-top:10px;" type="submit">保存翻译模式</button></form></div>';
}

function poly_batch_card(): string
{
    $keySet = yc_opt_has('ai.key');
    $csrf = Csrf::field();
    $defLang = Poly::defaultLang();
    $langs = [];
    foreach (Poly::enabled() as $code) {
        if ($code === $defLang) { continue; }
        $langs[] = $code;
    }
    $cards = '';
    foreach ($langs as $code) {
        $st = Poly::stats($code);
        $ss = Poly::staleStats($code);
        $label = Poly::label($code);
        $pct = $st['total'] > 0 ? round(100 * $st['published'] / $st['total']) : 100;
        $bar = '<div style="height:6px;background:#f0efe9;border-radius:4px;max-width:300px;overflow:hidden;"><div style="height:100%;width:' . $pct . '%;background:#b3541e;"></div></div>';
        $acts = [];
        if ($st['missing'] > 0 && $keySet) {
            $acts[] = '<form method="post" action="/admin/?s=poly" style="display:inline;">' . $csrf
                . '<input type="hidden" name="do" value="poly-batch"><input type="hidden" name="lang" value="' . e($code) . '">'
                . '<button class="btn" type="submit">🤖 翻译剩余 ' . $st['missing'] . ' 篇</button></form>';
        }
        if ($ss['stale'] - $ss['locked'] > 0 && $keySet) {
            $acts[] = '<form method="post" action="/admin/?s=poly" style="display:inline;">' . $csrf
                . '<input type="hidden" name="do" value="poly-batch"><input type="hidden" name="lang" value="' . e($code) . '">'
                . '<input type="hidden" name="mode" value="stale">'
                . '<button class="btn ghost" type="submit">🔁 重译过期 ' . ($ss['stale'] - $ss['locked']) . ' 篇</button></form>';
        }
        if (!$acts) {
            $acts[] = $ss['stale'] > 0
                ? '<span style="color:#9a6b3f;">' . $ss['stale'] . ' 篇过期（其中 ' . $ss['locked'] . ' 篇人工锁定，需手动更新）</span>'
                : ($st['missing'] === 0 ? '<span style="color:#2e6b34;">✔ 全部最新</span>' : '<span style="color:#9a6b3f;">先在下表填写 API Key</span>');
        }
        $cards .= '<div style="padding:8px 0;border-bottom:1px dashed #ecebe4;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">'
            . '<b style="min-width:70px;">' . e($label) . '</b>'
            . '<span style="font-size:13px;color:#565b61;">已发布 ' . $st['published'] . '/' . $st['total'] . ' · 草稿 ' . $st['draft']
            . ($ss['stale'] > 0 ? ' · <b style="color:#b3541e;">过期 ' . $ss['stale'] . '</b>（锁 ' . $ss['locked'] . '）' : '') . '</span>'
            . $bar . implode(' ', $acts) . '</div>';
    }
    return '<div class="card"><h3>批量翻译 <small>「翻译剩余」= 未翻译；「重译过期」= 中文原文修改后待更新的译文（人工锁定篇不自动覆盖，请手动）。每点一次推进一批</small></h3>'
        . $cards
        . '<div style="margin-top:12px;font-size:12.5px;color:#6f767e;">一次跑完全部启用语言（推荐服务器执行，在 docker/ 目录）：'
        . '<code style="background:#f0efe9;padding:1px 6px;border-radius:4px;">docker compose exec -T php php tools/translate-all.php</code></div></div>';
}

function poly_settings_card(): string
{
    $val = fn(string $k, string $d) => e((string)get_option($k, $d));
    $csrf = Csrf::field();
    $enabled = array_values(array_filter(Poly::enabled(), fn($c) => $c !== Poly::defaultLang()));
    $keyPh = yc_opt_has('ai.key') ? '（已保存，留空保持不变）' : '（必填才能启用 AI 翻译）';
    $langLine = '<div style="margin-top:8px;font-size:12.5px;color:#6f767e;">当前启用（除基准）：' . (count($enabled) ? implode(' / ', array_map(fn($c) => e(Poly::label($c)), $enabled)) : '仅基准语言') . ' —— 语言开关在「语言管理」卡片。</div>';
    return '<div class="card"><h3>AI 设置 <small>OpenAI 兼容接口（默认 DeepSeek）；AI 翻译把中文原文译为各目标语言</small></h3>'
        . '<form method="post" action="/admin/?s=poly">' . $csrf
        . '<input type="hidden" name="do" value="poly-config">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div><label style="font-size:12.5px;color:#6f767e;">接口地址 base</label><input name="ai_base" value="' . $val('ai.base', 'https://api.deepseek.com/chat/completions') . '" style="width:100%;box-sizing:border-box;"></div>
        <div><label style="font-size:12.5px;color:#6f767e;">模型</label><input name="ai_model" value="' . $val('ai.model', 'deepseek-chat') . '" style="width:100%;box-sizing:border-box;"></div></div>
        <div style="margin-top:8px;"><label style="font-size:12.5px;color:#6f767e;">API Key（AI 翻译用；已保存的密钥不回显）</label><input name="ai_key" value="" placeholder="' . $keyPh . '" autocomplete="off" style="width:100%;box-sizing:border-box;"></div>
        <div style="margin-top:8px;"><label style="font-size:12.5px;color:#6f767e;">保护词（不翻译，逗号分隔）</label><input name="ai_protect" value="' . $val('ai.protect', 'YeCha,夜叉') . '" style="width:100%;box-sizing:border-box;"></div>'
        . '<button class="btn ghost" style="margin-top:10px;" type="submit">保存设置</button></form>' . $langLine . '</div>';
}

function poly_edit_html(int $baseId, string $code): string
{
    $base = Db::row('SELECT * FROM ' . Db::t('contents') . ' WHERE id=?', [$baseId]);
    if ($base === null || !in_array($code, Poly::enabled(), true) || $code === Poly::defaultLang()) {
        $_SESSION['flash'] = '未找到原文';
        header('Location: /admin/?s=poly');
        exit;
    }
    $label = Poly::label($code);
    $row = Poly::row($baseId, $code);
    $fTitle = $row['title'] ?? '';
    $fSlug = $row['slug'] ?? $base['slug'];
    $fExcerpt = $row['excerpt'] ?? '';
    $fKw = $row['keywords'] ?? '';
    $fDesc = (string)($row['description'] ?? '');
    $fContent = $row['content'] ?? '';
    $fStatus = $row['status'] ?? 'draft';
    $aiBadge = $row && $row['ai_generated'] ? ' · <span class="tag" style="color:#2e6b34;">AI 生成草稿待审校</span>' : '';
    $lockBadge = $row && (int)($row['manual_override'] ?? 0) === 1 ? ' · <span class="tag" style="color:#9a6b3f;">已人工修正（AI 不覆盖）</span>' : '';
    $sel = fn(string $v) => $fStatus === $v ? ' selected' : '';
    $csrf = Csrf::field();
    $listUrl = '/admin/?s=poly&lang=' . rawurlencode($code);
    $modeNote = Poly::mode($code) === 'ai'
        ? '<div style="margin-top:8px;padding:8px 12px;border-radius:8px;background:#fbf3e9;border:1px solid #ecd9c2;color:#7a5b34;font-size:12.5px;">' . e($label) . ' 当前为 <b>AI 全自动</b>：本页保存即视为<b>手动修正</b>（manual_override），之后「AI 全量重译」不会覆盖本篇。</div>'
        : '';
    return '<div class="card"><h3>逐语言工作台 · 翻译为 ' . e($label) . ' ' . $aiBadge . $lockBadge . ' <small>左：' . e(Poly::label(Poly::defaultLang())) . '原文（只读）→ 右：' . e($label) . '译文（编辑/发布）</small></h3>' . $modeNote
        . '<div style="padding:8px 0 2px;font-size:12.5px;color:#6f767e;">译文 URL：/ ' . e($code) . ' / … .html；标题 / slug / 正文 / 摘要 / 关键词 / 描述均独立保存。'
        . ' 分类与标签可在「文章编辑器」该语言页调整。</div>'
        . '<div class="poly-grid"><div><b>' . e(Poly::label(Poly::defaultLang())) . '原文</b><pre>' . e((string)$base['title']) . "\n\n" . e((string)$base['excerpt']) . "\n\n" . e((string)$base['content']) . '</pre></div>'
        . '<div><form method="post" action="/admin/?s=poly">' . $csrf
        . '<input type="hidden" name="do" value="poly-save"><input type="hidden" name="base_id" value="' . $baseId . '"><input type="hidden" name="lang" value="' . e($code) . '">'
        . '<label class="pf">标题 *</label><input name="title" value="' . e($fTitle) . '" required>'
        . '<label class="pf">slug（' . e($code) . ' 前缀下唯一）</label><input name="slug" value="' . e($fSlug) . '">'
        . '<label class="pf">简介 excerpt</label><textarea name="excerpt" rows="2">' . e($fExcerpt) . '</textarea>'
        . '<label class="pf">关键词 keywords</label><input name="keywords" value="' . e($fKw) . '">'
        . '<label class="pf">描述 description（meta，该语言独立）</label><input name="description" value="' . e($fDesc) . '">'
        . '<label class="pf">正文（Markdown）</label><textarea name="content" rows="14" required style="font-family:Consolas,monospace;">' . e($fContent) . '</textarea>'
        . '<label class="pf">状态</label><select name="status"><option value="draft"' . $sel('draft') . '>草稿（/ ' . e($code) . ' 回落' . e(Poly::label(Poly::defaultLang())) . '）</option><option value="publish"' . $sel('publish') . '>发布（/ ' . e($code) . ' 可见）</option></select>'
        . '<div style="margin-top:10px;display:flex;gap:8px;"><button class="btn" type="submit">保存 / 发布</button>'
        . '</form>'
        . '<form method="post" action="/admin/?s=poly" style="display:inline;">' . $csrf
        . '<input type="hidden" name="do" value="ai-generate"><input type="hidden" name="base_id" value="' . $baseId . '"><input type="hidden" name="lang" value="' . e($code) . '">'
        . '<button class="btn ghost" type="submit">🤖 AI 翻译为草稿</button></form>'
        . '<a class="btn ghost" href="/admin/?s=posts&action=edit&id=' . $baseId . '&lang=' . rawurlencode($code) . '">文章编辑器 ↗</a>'
        . '<a class="btn ghost" href="' . $listUrl . '">返回总览</a></div></form></div></div>'
        . '<style>.pf{display:block;font-size:12.5px;color:#6f767e;margin:8px 0 2px}.pf+input,.pf+textarea,.pf+select{width:100%;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font:inherit;font-size:13.5px}</style>';
}
