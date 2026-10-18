<?php
declare(strict_types=1);
// AI 能力插件 · 后台管理：总览 / 服务商设置 / 知识库 / 调用日志
// POST 自处理（宿主 admin POST 段已统一 Csrf::check()）
require_once __DIR__ . '/lib.php';

function yc_ai_admin(): string
{
    if (!\Auth::isAdmin()) { return '<div class="card"><h3>AI 能力</h3><div class="stub">需要管理员权限。</div></div>'; }
    $msg = ai_admin_handle();
    $tab = (string)($_GET['tab'] ?? 'home');
    $base = '/admin/?s=plug_ai';

    $tabs = ['home' => '总览', 'set' => '服务商设置', 'kb' => '知识库', 'log' => '调用日志'];
    $tabBar = '<div class="mla-tabs">';
    foreach ($tabs as $k => $label) {
        $tabBar .= '<a href="' . $base . '&amp;tab=' . $k . '"' . ($tab === $k ? ' class="on"' : '') . '>' . $label . '</a>';
    }
    $tabBar .= '</div>';

    switch ($tab) {
        case 'set': $out = ai_set_page($base, $msg); break;
        case 'kb': $out = ai_kb_page($base, $msg); break;
        case 'log': $out = ai_log_page($base); break;
        default: $out = ai_home_page($base); break;
    }
    return '<div class="card"><h3>AI 能力</h3>' . $tabBar . $out . '</div>';
}

function ai_admin_handle(): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { return ''; }
    try { \Csrf::check(); } catch (Throwable $e) { return '<div class="notice error">CSRF 校验失败</div>'; }
    $act = (string)($_POST['act'] ?? '');

    if ($act === 'save_settings') {
        $provider = ($_POST['provider'] ?? '') === 'zhipu' ? 'zhipu' : 'sensenova';
        Ai::setCfg('provider', $provider);
        // Key：留空不覆盖（不回显），显式填 '-' 表示清除
        foreach (['sensenova', 'zhipu'] as $p) {
            $k = trim((string)($_POST[$p . '_key'] ?? ''));
            if ($k === '-') { Ai::setCfg($p . '.key', ''); }
            elseif ($k !== '') { Ai::setCfg($p . '.key', $k); }
            Ai::setCfg($p . '.model', trim((string)($_POST[$p . '_model'] ?? '')));
            Ai::setCfg($p . '.base', trim((string)($_POST[$p . '_base'] ?? '')));
        }
        Ai::setCfg('system.prompt', trim((string)($_POST['system_prompt'] ?? '')));
        Ai::setCfg('rate.hour', (string)max(1, (int)($_POST['rate_hour'] ?? 20)));
        // ★ 成本控制参数（导购场景：语料条数与历史条数是 token 大头）
        Ai::setCfg('rag.limit', (string)max(1, min(6, (int)($_POST['rag_limit'] ?? 3))));
        Ai::setCfg('rag.per', (string)max(200, min(1200, (int)($_POST['rag_per'] ?? 600))));
        Ai::setCfg('history.n', (string)max(0, min(6, (int)($_POST['history_n'] ?? 3))));
        Ai::setCfg('history.len', (string)max(80, min(800, (int)($_POST['history_len'] ?? 300))));
        return '<div class="notice ok">设置已保存。</div>';
    }

    if ($act === 'kb_save') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $tags = trim((string)($_POST['tags'] ?? ''));
        if ($title === '' || $content === '') { return '<div class="notice error">标题与内容必填。</div>'; }
        if ($id > 0) { Ai::kbUpdate($id, $title, $content, $tags); }
        else { Ai::kbAdd($title, $content, $tags); }
        return '<div class="notice ok">知识条目已保存。</div>';
    }

    if ($act === 'kb_delete') {
        Ai::kbDelete((int)($_POST['id'] ?? 0));
        return '<div class="notice ok">已删除。</div>';
    }
    return '';
}

function ai_home_page(string $base): string
{
    $kb = (int)Db::one("SELECT COUNT(*) FROM " . Ai::t('knowledge') . " WHERE is_delete_time = 0");
    $today = (int)Db::one("SELECT COUNT(*) FROM " . Ai::t('log') . " WHERE created_at > CURDATE()");
    $err24 = (int)Db::one("SELECT COUNT(*) FROM " . Ai::t('log') . " WHERE ok = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $sess = (int)Db::one("SELECT COUNT(*) FROM " . Ai::t('chat_session'));
    $ok = Ai::isConfigured();
    return '<table class="wp-list-table widefat"><tbody>'
        . '<tr><th>AI 服务</th><td>' . ($ok ? '<b style="color:#0f6e56">已配置</b>（' . e(Ai::provider()) . ' / ' . e(Ai::model()) . '）' : '<b style="color:#a32d2d">未配置 API Key</b> — 请到「服务商设置」填写') . '</td></tr>'
        . '<tr><th>接口地址</th><td><code>' . e(Ai::endpoint()) . '</code></td></tr>'
        . '<tr><th>知识库条目</th><td>' . $kb . ' 条</td></tr>'
        . '<tr><th>客服会话数</th><td>' . $sess . '</td></tr>'
        . '<tr><th>今日调用</th><td>' . $today . ' 次</td></tr>'
        . '<tr><th>24h 失败</th><td>' . $err24 . ' 次</td></tr>'
        . '</tbody></table>'
        . '<p style="margin-top:12px;color:#666">复用方式：其他插件调用 <code>Ai::chat($messages, array("scene" =&gt; "xx"))</code> / <code>Ai::translate($text, "English")</code> / <code>Ai::kbSearch($query)</code>。</p>';
}

function ai_set_page(string $base, string $msg): string
{
    $provider = Ai::provider();
    $sp = Ai::cfg('system.prompt', Ai::defaultPrompt());   // ★ 默认改为导购口吻
    $rate = Ai::cfg('rate.hour', '20');
    $f = static fn(string $p, string $k, string $d) => e(Ai::cfg($p . '.' . $k, $d));
    $h = '<form method="post">' . \Csrf::field() . '<input type="hidden" name="act" value="save_settings">'
        . '<table class="form-table">'
        . '<tr><th>默认服务商</th><td><select name="provider"><option value="sensenova"' . ($provider === 'sensenova' ? ' selected' : '') . '>SenseNova（商汤）</option><option value="zhipu"' . ($provider === 'zhipu' ? ' selected' : '') . '>智谱 GLM</option></select></td></tr>'
        . '<tr><th>SenseNova API Key</th><td><input type="password" name="sensenova_key" style="width:360px" placeholder="' . (Ai::cfg('sensenova.key') !== '' ? '已配置（留空不修改，填 - 清除）' : '未配置') . '"></td></tr>'
        . '<tr><th>SenseNova 模型 / 接口</th><td><input type="text" name="sensenova_model" value="' . $f('sensenova', 'model', Ai::defaultModel('sensenova')) . '" style="width:200px"> <input type="text" name="sensenova_base" value="' . $f('sensenova', 'base', Ai::defaultBase('sensenova')) . '" style="width:340px"></td></tr>'
        . '<tr><th>智谱 API Key</th><td><input type="password" name="zhipu_key" style="width:360px" placeholder="' . (Ai::cfg('zhipu.key') !== '' ? '已配置（留空不修改，填 - 清除）' : '未配置') . '"></td></tr>'
        . '<tr><th>智谱 模型 / 接口</th><td><input type="text" name="zhipu_model" value="' . $f('zhipu', 'model', Ai::defaultModel('zhipu')) . '" style="width:200px"> <input type="text" name="zhipu_base" value="' . $f('zhipu', 'base', Ai::defaultBase('zhipu')) . '" style="width:340px"></td></tr>'
        . '<tr><th>客服人设（system prompt）</th><td><textarea name="system_prompt" rows="4" style="width:640px">' . e($sp) . '</textarea></td></tr>'
        . '<tr><th>频控（会话/小时/IP）</th><td><input type="number" name="rate_hour" value="' . e($rate) . '" min="1" max="1000" style="width:90px">'
        . ' <span class="description">按【去重会话数】计；导购建议 60（原实现按消息数计，用户连问几句就被拦）</span></td></tr>'
        . '<tr><th colspan="2" style="padding-top:18px"><b>成本控制</b>（RAG 语料与历史是 token 大头，按需调小可显著省钱）</th></tr>'
        . '<tr><th>知识库注入条数</th><td><input type="number" name="rag_limit" value="' . e(Ai::cfg('rag.limit', '3')) . '" min="1" max="6" style="width:90px">'
        . ' <span class="description">每次提问带几段知识库语料（默认 3；调大更准但更贵）</span></td></tr>'
        . '<tr><th>单段语料截断字数</th><td><input type="number" name="rag_per" value="' . e(Ai::cfg('rag.per', '600')) . '" min="200" max="1200" style="width:90px">'
        . ' <span class="description">每段取前 N 字（默认 600）</span></td></tr>'
        . '<tr><th>携带历史条数</th><td><input type="number" name="history_n" value="' . e(Ai::cfg('history.n', '3')) . '" min="0" max="6" style="width:90px">'
        . ' <span class="description">带最近几轮对话（默认 3；0 = 不带，最省）</span></td></tr>'
        . '<tr><th>单条历史截断字数</th><td><input type="number" name="history_len" value="' . e(Ai::cfg('history.len', '300')) . '" min="80" max="800" style="width:90px">'
        . ' <span class="description">每条历史取前 N 字（默认 300）</span></td></tr>'
        . '</table><p><button class="btn" type="submit">保存设置</button>'
        . ' <button class="btn" type="button" onclick="document.querySelector(&quot;[name=system_prompt]&quot;).value=' . e(json_encode(Ai::defaultPrompt(), JSON_UNESCAPED_UNICODE)) . ';">恢复导购默认人设</button></p></form>';
    return $msg . $h;
}

function ai_kb_page(string $base, string $msg): string
{
    $editId = (int)($_GET['edit'] ?? 0);
    $row = null;
    if ($editId > 0) {
        $row = Db::row("SELECT * FROM " . Ai::t('knowledge') . " WHERE id = :id AND is_delete_time = 0", [':id' => $editId]);
    }
    $h = $msg . '<form method="post" style="margin-bottom:16px">' . \Csrf::field() . '<input type="hidden" name="act" value="kb_save">'
        . '<input type="hidden" name="id" value="' . (int)($row['id'] ?? 0) . '">'
        . '<p><input type="text" name="title" placeholder="标题（如：产品 X 的参数）" value="' . e((string)($row['title'] ?? '')) . '" style="width:400px"> '
        . '<input type="text" name="tags" placeholder="标签（可选）" value="' . e((string)($row['tags'] ?? '')) . '" style="width:200px"></p>'
        . '<p><textarea name="content" rows="5" placeholder="知识内容（客服 RAG 语料）" style="width:640px">' . e((string)($row['content'] ?? '')) . '</textarea></p>'
        . '<p><button class="btn" type="submit">' . ($row ? '保存修改' : '新增条目') . '</button></p></form>';

    $rows = Db::rows("SELECT id, title, tags, is_show, created_at FROM " . Ai::t('knowledge') . " WHERE is_delete_time = 0 ORDER BY sort DESC, id DESC LIMIT 200");
    $h .= '<table class="wp-list-table widefat"><thead><tr><th>ID</th><th>标题</th><th>标签</th><th>时间</th><th>操作</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><td>' . (int)$r['id'] . '</td><td>' . e((string)$r['title']) . '</td><td>' . e((string)$r['tags']) . '</td><td>' . e((string)$r['created_at']) . '</td>'
            . '<td><a href="' . $base . '&amp;tab=kb&amp;edit=' . (int)$r['id'] . '">编辑</a> '
            . '<form method="post" style="display:inline" onsubmit="return confirm(\'确认删除？\')">' . \Csrf::field() . '<input type="hidden" name="act" value="kb_delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn ghost mini" type="submit">删除</button></form></td></tr>';
    }
    $h .= '</tbody></table>';
    return $h;
}

function ai_log_page(string $base): string
{
    $rows = Db::rows("SELECT * FROM " . Ai::t('log') . " ORDER BY id DESC LIMIT 100");
    $h = '<table class="wp-list-table widefat"><thead><tr><th>时间</th><th>场景</th><th>服务商</th><th>模型</th><th>入/出 tokens</th><th>耗时</th><th>状态</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><td>' . e((string)$r['created_at']) . '</td><td>' . e((string)$r['scene']) . '</td><td>' . e((string)$r['provider']) . '</td><td>' . e((string)$r['model']) . '</td>'
            . '<td>' . (int)$r['in_tokens'] . ' / ' . (int)$r['out_tokens'] . '</td><td>' . (int)$r['cost_ms'] . 'ms</td>'
            . '<td>' . ($r['ok'] ? '<span style="color:#0f6e56">OK</span>' : '<span style="color:#a32d2d" title="' . e((string)$r['err']) . '">失败</span>') . '</td></tr>';
    }
    $h .= '</tbody></table>';
    return $h;
}
