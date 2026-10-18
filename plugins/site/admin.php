<?php
declare(strict_types=1);
// 企业官网插件 · 后台管理：总览 / 企业信息与主题 / 横幅 / 产品分类 / 产品 / 案例 / 招聘 / 留言
// POST 自处理（宿主 admin POST 段已统一 Csrf::check()）；上传复用宿主 do=upload 通道
require_once __DIR__ . '/lib.php';

function yc_site_admin(): string
{
    if (!\Auth::isAdmin()) { return '<div class="card"><h3>企业官网</h3><div class="stub">需要管理员权限。</div></div>'; }
    $msg = st_admin_handle();
    $tab = (string)($_GET['tab'] ?? 'home');
    $base = '/admin/?s=plug_site';

    $tabs = ['home' => '总览', 'skin' => '行业风格', 'set' => '企业信息', 'lang' => '多语言', 'banner' => '横幅', 'cate' => '产品分类', 'product' => '产品管理', 'case' => '客户案例', 'job' => '招聘管理', 'message' => '留言'];
    $tabBar = '<div class="mla-tabs">';
    foreach ($tabs as $k => $label) {
        $tabBar .= '<a href="' . $base . '&amp;tab=' . $k . '"' . ($tab === $k ? ' class="on"' : '') . '>' . $label . '</a>';
    }
    $tabBar .= '</div>';

    switch ($tab) {
        case 'skin': $out = st_skin_page($base, $msg); break;
        case 'set': $out = st_set_page($base, $msg); break;
        case 'lang': $out = st_lang_page($base, $msg); break;
        case 'banner': $out = st_banner_page($base, $msg); break;
        case 'cate': $out = st_cate_page($base, $msg); break;
        case 'product': $out = st_product_page($base, $msg); break;
        case 'case': $out = st_case_page($base, $msg); break;
        case 'job': $out = st_job_page($base, $msg); break;
        case 'message': $out = st_message_page($base); break;
        default: $out = st_home_page($base); break;
    }
    return '<div class="card"><h3>企业官网</h3>' . $tabBar . $out . '</div>';
}

function st_admin_handle(): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { return ''; }
    try { \Csrf::check(); } catch (Throwable $e) { return '<div class="notice error">CSRF 校验失败</div>'; }
    $act = (string)($_POST['act'] ?? '');

    if ($act === 'save_company') {
        foreach (['company.name', 'company.slogan', 'company.intro', 'company.phone', 'company.email', 'company.address', 'company.wechat', 'company.icp', 'seo.title', 'seo.kw', 'seo.desc'] as $k) {
            Site::setOpt($k, trim((string)($_POST[str_replace('.', '_', $k)] ?? '')));
        }
        Site::setOpt('mode', ($_POST['mode_official'] ?? '') === '1' ? 'official' : 'blog');
        Site::setOpt('lang.switch', ($_POST['lang_switch'] ?? '') === '1' ? '1' : '0');
        Site::setOpt('news.cate', (string)(int)($_POST['news_cate'] ?? 0));
        Site::setOpt('news.exclude', trim((string)($_POST['news_exclude'] ?? '')));
        Site::setOpt('hide.kernel_pages', ($_POST['hide_kernel_pages'] ?? '') === '1' ? '1' : '0');
        return '<div class="notice ok">企业信息已保存。</div>';
    }

    // AI 内容助手：为内容项生成指定字段并写回
    if ($act === 'ai_gen') {
        $r = Site::genField((string)($_POST['item'] ?? ''), (int)($_POST['id'] ?? 0), (string)($_POST['field'] ?? ''));
        return '<div class="notice ' . (!empty($r['ok']) ? 'ok' : 'error') . '">' . e((string)$r['msg']) . '</div>';
    }

    // AI 多语言：翻译内容项并落库
    if ($act === 'ai_translate') {
        $r = Site::translateItem((string)($_POST['item'] ?? ''), (int)($_POST['id'] ?? 0), (string)($_POST['lang'] ?? ''));
        return '<div class="notice ' . (!empty($r['ok']) ? 'ok' : 'error') . '">' . e((string)$r['msg']) . '</div>';
    }

    // AI 多语言：一键批量翻译整站（站点文案 + 产品 / 案例 / 职位 / 横幅）
    if ($act === 'ai_translate_all') {
        $r = Site::translateAll((string)($_POST['lang'] ?? ''));
        return '<div class="notice ' . (!empty($r['ok']) ? 'ok' : 'error') . '">' . e((string)$r['msg']) . '</div>';
    }

    // AI 多语言：删除某语言译文
    if ($act === 'tr_del') {
        Db::deleteBy('site_i18n', 'item_type = ? AND item_id = ? AND lang = ?', [(string)($_POST['item'] ?? ''), (int)($_POST['id'] ?? 0), (string)($_POST['lang'] ?? '')]);
        return '<div class="notice ok">译文已删除。</div>';
    }

    if ($act === 'save_theme') {
        $t = (string)($_POST['theme'] ?? 'business');
        if (isset(Site::THEMES[$t])) { Site::setOpt('theme', $t); return '<div class="notice ok">已切换行业风格：' . e(Site::THEMES[$t]) . '</div>'; }
        return '<div class="notice error">未知主题。</div>';
    }

    // 载入某行业的完整演示数据（企业信息 / 优势 / 产品 / 案例 / 招聘 / 新闻 / AI 知识库）
    if ($act === 'seed_demo') {
        $ind = (string)($_POST['industry'] ?? '');
        if (!isset(Site::THEMES[$ind])) { return '<div class="notice error">未知行业：' . e($ind) . '</div>'; }
        require_once __DIR__ . '/seed.php';
        try {
            return '<div class="notice ok">' . SiteSeed::apply($ind) . '</div>';
        } catch (Throwable $e) {
            return '<div class="notice error">载入失败：' . e($e->getMessage()) . '</div>';
        }
    }

    if ($act === 'save_ai') {
        Site::setOpt('ai.chat', ($_POST['ai_chat'] ?? '') === '1' ? '1' : '0');
        return '<div class="notice ok">AI 客服设置已保存。</div>';
    }

    // 通用实体保存/删除（banner / cate / product / case / job）
    $map = [
        'banner' => ['table' => 'banner', 'fields' => ['title', 'sub', 'image', 'link', 'sort', 'is_show']],
        'cate' => ['table' => 'product_category', 'fields' => ['name', 'slug', 'sort', 'is_show']],
        'product' => ['table' => 'product', 'fields' => ['cate_id', 'name', 'summary', 'content', 'image', 'params', 'sort', 'is_show']],
        'case' => ['table' => 'case', 'fields' => ['name', 'industry', 'challenge', 'solution', 'result', 'image', 'sort', 'is_show']],
        'job' => ['table' => 'job', 'fields' => ['title', 'place', 'type', 'salary', 'duty', 'requirement', 'sort', 'is_show']],
    ];
    foreach ($map as $kind => $cfg) {
        if ($act === $kind . '_save') {
            $data = [];
            foreach ($cfg['fields'] as $f) {
                $v = (string)($_POST[$f] ?? '');
                $data[$f] = in_array($f, ['sort', 'is_show', 'cate_id'], true) ? (int)$v : $v;
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                Db::update($cfg['table'], $data, 'id = ?', [$id]);
            } else {
                Db::insert($cfg['table'], $data);
            }
            return '<div class="notice ok">已保存。</div>';
        }
        if ($act === $kind . '_delete') {
            Db::update($cfg['table'], ['is_delete_time' => time()], 'id = ?', [(int)($_POST['id'] ?? 0)]);
            return '<div class="notice ok">已删除。</div>';
        }
    }

    if ($act === 'message_delete') {
        Db::deleteBy('site_message', 'id = ?', [(int)($_POST['id'] ?? 0)]);
        return '<div class="notice ok">留言已删除。</div>';
    }
    if ($act === 'message_read') {
        Db::update('site_message', ['is_read' => 1], 'id = ?', [(int)($_POST['id'] ?? 0)]);
        return '<div class="notice ok">已标记已读。</div>';
    }
    return '';
}

/* ---------- 页面片段 ---------- */

function st_home_page(string $base): string
{
    $n = [
        '产品' => (int)Db::one("SELECT COUNT(*) FROM " . Site::t('product') . " WHERE is_delete_time = 0"),
        '案例' => (int)Db::one("SELECT COUNT(*) FROM " . Site::t('case') . " WHERE is_delete_time = 0"),
        '职位' => (int)Db::one("SELECT COUNT(*) FROM " . Site::t('job') . " WHERE is_delete_time = 0"),
        '留言' => (int)Db::one("SELECT COUNT(*) FROM " . Site::t('message')),
        '未读留言' => (int)Db::one("SELECT COUNT(*) FROM " . Site::t('message') . " WHERE is_read = 0"),
    ];
    $h = '<table class="wp-list-table widefat"><tbody>';
    foreach ($n as $k => $v) { $h .= '<tr><th>' . $k . '</th><td>' . $v . '</td></tr>'; }
    $h .= '</tbody></table>';
    $h .= '<p style="margin-top:12px">前台地址：<code>' . e(Site::opt('company.name', '我的企业')) . '</code> → <a href="/site" target="_blank">/site</a> · 当前主题：<b>' . e(Site::THEMES[Site::theme()]) . '</b></p>';
    $aiOn = class_exists('Ai') && \Plugins::isOn('ai') && Ai::isConfigured();
    $h .= '<p>AI 客服：' . ($aiOn ? '<b style="color:#0f6e56">就绪</b>（ai 插件已配置）' : '<b style="color:#a32d2d">未就绪</b>（请先在「AI 能力」插件配置 API Key）') . '</p>';
    return $h;
}

function st_set_page(string $base, string $msg): string
{
    $c = Site::company();
    $f = static fn(string $k) => e(Site::opt($k));
    $h = $msg . '<form method="post">' . \Csrf::field() . '<input type="hidden" name="act" value="save_company">'
        . '<table class="form-table">'
        . '<tr><th>企业名称</th><td><input type="text" name="company_name" value="' . e($c['name']) . '" style="width:320px"></td></tr>'
        . '<tr><th>Slogan</th><td><input type="text" name="company_slogan" value="' . e($c['slogan']) . '" style="width:480px"></td></tr>'
        . '<tr><th>企业简介</th><td><textarea name="company_intro" rows="5" style="width:640px">' . e($c['intro']) . '</textarea></td></tr>'
        . '<tr><th>电话</th><td><input type="text" name="company_phone" value="' . e($c['phone']) . '" style="width:240px"></td></tr>'
        . '<tr><th>邮箱</th><td><input type="text" name="company_email" value="' . e($c['email']) . '" style="width:240px"></td></tr>'
        . '<tr><th>地址</th><td><input type="text" name="company_address" value="' . e($c['address']) . '" style="width:480px"></td></tr>'
        . '<tr><th>微信</th><td><input type="text" name="company_wechat" value="' . e($c['wechat']) . '" style="width:240px"></td></tr>'
        . '<tr><th>ICP 备案号</th><td><input type="text" name="company_icp" value="' . e($c['icp']) . '" style="width:240px"></td></tr>'
        . '<tr><th>SEO 关键词</th><td><input type="text" name="seo_kw" value="' . $f('seo.kw') . '" style="width:480px"></td></tr>'
        . '<tr><th>SEO 描述</th><td><textarea name="seo_desc" rows="2" style="width:640px">' . $f('seo.desc') . '</textarea></td></tr>'
        . '<tr><th>官网模式</th><td><label><input type="checkbox" name="mode_official" value="1"' . (Site::isOfficial() ? ' checked' : '') . '> 站点首页与全部前台页面（含文章/分类/归档/搜索/登录注册/用户中心）统一使用企业官网皮肤；'
        . '<span style="color:#666">关闭后仅 <code>/site</code> 下使用官网皮肤，博客本体恢复原样</span></label></td></tr>'
        . '<tr><th>多语言切换</th><td><label><input type="checkbox" name="lang_switch" value="1"' . (Site::opt('lang.switch', '1') === '1' ? ' checked' : '') . '> 前台页脚显示语言切换（译文由「AI 多语言」生成）</label></td></tr>'
        . '<tr><th>新闻分类</th><td><select name="news_cate"><option value="0">全部文章</option>';
    $curCate = (int)Site::opt('news.cate', '0');
    foreach (Db::rows("SELECT id, name FROM " . Db::t('metas') . " WHERE type = 'category' ORDER BY id ASC") as $ct) {
        $h .= '<option value="' . (int)$ct['id'] . '"' . ($curCate === (int)$ct['id'] ? ' selected' : '') . '>' . e((string)$ct['name']) . '</option>';
    }
    $h .= '</select> <span style="color:#666">新闻动态只取该分类的文章（避免无关内容混入官网新闻）</span></td></tr>'
        . '<tr><th>新闻排除关键词</th><td><input type="text" name="news_exclude" value="' . $f('news.exclude') . '" style="width:480px"> <span style="color:#666">逗号分隔；标题包含这些词的文章不进官网新闻（默认过滤博客系统公告）</span></td></tr>'
        . '<tr><th>博客独立页</th><td><label><input type="checkbox" name="hide_kernel_pages" value="1"' . (Site::opt('hide.kernel_pages', '1') === '1' ? ' checked' : '') . '> 官网模式下隐藏博客独立页（<code>page</code> 类型内容）</label>'
        . '<br><span style="color:#666">官网有自己的页面体系（关于我们 / 联系我们 / 加入我们 …），博客自带的示例页（下载 / 帮助与教程 / 关于 YeCha 等）不应出现在官网前台；'
        . '若确实需要把博客独立页挂在官网上，取消勾选即可放行</span></td></tr>'
        . '</table><p><button class="btn" type="submit">保存企业信息</button></p></form>';

    $h .= '<h3 style="margin-top:20px">行业风格</h3><p>当前风格：<b>' . e(Site::THEMES[Site::theme()] ?? Site::theme())
        . '</b> —— 请到 <a href="/admin/?s=plug_site&amp;tab=skin">「行业风格」</a> 页切换（12 套行业风格包：配色 + 行业术语 + 首页区块结构，可一键载入该行业演示数据）。</p>';

    $aiChat = Site::opt('ai.chat', '1');
    $h .= '<h3 style="margin-top:20px">AI 客服</h3><form method="post">' . \Csrf::field() . '<input type="hidden" name="act" value="save_ai">'
        . '<label><input type="checkbox" name="ai_chat" value="1"' . ($aiChat === '1' ? ' checked' : '') . '> 前台启用 AI 客服浮窗（需 ai 插件已配置 Key）</label> '
        . '<button class="btn" type="submit">保存</button></form>';
    return $h;
}

/** AI 内容助手按钮（独立小表单：act=ai_gen，不依赖 JS） */
function st_ai_btn(string $type, int $id, string $field, string $label): string
{
    return '<form method="post" style="display:inline-block;margin:0 6px 0 0">' . \Csrf::field()
        . '<input type="hidden" name="act" value="ai_gen"><input type="hidden" name="item" value="' . e($type) . '">'
        . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="field" value="' . e($field) . '">'
        . '<button class="btn ghost mini" type="submit">' . e($label) . '</button></form>';
}

/** AI 生成 + 多语言面板（仅编辑已保存的记录时显示；AI 未配置时只显示提示） */
function st_ai_panel(string $type, ?array $row): string
{
    if ($row === null) { return '<p style="color:#666;font-size:13px">AI 内容助手与多语言：请先保存记录，再回来生成。</p>'; }
    $id = (int)$row['id'];
    $aiOn = class_exists('Ai') && \Plugins::isOn('ai') && Ai::isConfigured();
    if (!$aiOn) {
        return '<div class="notice error">AI 未配置：请先到「AI 能力」插件填写服务商 Key，然后回到这里使用内容助手与多语言。</div>';
    }
    $btns = match ($type) {
        'product' => st_ai_btn($type, $id, 'summary', 'AI 写简介') . st_ai_btn($type, $id, 'content', 'AI 写详情'),
        'case'    => st_ai_btn($type, $id, 'challenge', 'AI 写挑战') . st_ai_btn($type, $id, 'solution', 'AI 写方案') . st_ai_btn($type, $id, 'result', 'AI 写成果'),
        'job'     => st_ai_btn($type, $id, 'duty', 'AI 写职责') . st_ai_btn($type, $id, 'requirement', 'AI 写要求'),
        default   => '',
    };
    $h = '<div class="notice" style="background:#f6f7f9;border-color:#e3e7ec"><b>AI 内容助手</b>（生成结果直接写入对应字段，保存前可继续编辑）：<div style="margin-top:8px">' . $btns . '</div>'
        . '<div style="margin-top:12px"><b>AI 多语言</b>（译文落库，前台 ?lang=xx 直接 SSR 输出）：'
        . '<form method="post" style="display:inline-block;margin-left:8px">' . \Csrf::field()
        . '<input type="hidden" name="act" value="ai_translate"><input type="hidden" name="item" value="' . e($type) . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<select name="lang">';
    foreach (Site::LANGS as $code => $name) { $h .= '<option value="' . $code . '">' . e($name) . '</option>'; }
    $h .= '</select> <button class="btn mini" type="submit">翻译并保存</button></form>';
    $done = Site::translatedLangs($type, $id);
    if ($done) {
        $h .= '<div style="margin-top:8px;font-size:12px">已有译文：';
        foreach ($done as $lc) {
            $h .= '<span style="margin-right:10px">' . e($lc)
                . ' <form method="post" style="display:inline" onsubmit="return confirm(\'删除该语言译文？\')">' . \Csrf::field()
                . '<input type="hidden" name="act" value="tr_del"><input type="hidden" name="item" value="' . e($type) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="lang" value="' . e($lc) . '">'
                . '<button class="btn ghost mini" type="submit">删除</button></form></span>';
        }
        $h .= '</div>';
    }
    return $h . '</div></div>';
}

/** 通用列表+表单渲染器：banner / cate / product / case / job */
function st_entity_page(string $base, string $msg, string $kind, array $fields, array $labels, array $types, string $title): string
{
    $editId = (int)($_GET['edit'] ?? 0);
    $row = $editId > 0 ? Db::row("SELECT * FROM " . Site::t($kind === 'cate' ? 'product_category' : $kind) . " WHERE id = :id", [':id' => $editId]) : null;
    $h = $msg . '<h4 style="margin:10px 0">' . ($row ? '编辑 #' . (int)$row['id'] : '新增') . '</h4><form method="post" style="margin-bottom:20px">' . \Csrf::field()
        . '<input type="hidden" name="act" value="' . $kind . '_save"><input type="hidden" name="id" value="' . (int)($row['id'] ?? 0) . '"><table class="form-table">';
    foreach ($fields as $i => $f) {
        $v = (string)($row[$f] ?? '');
        $input = match ($types[$i]) {
            'text' => '<input type="text" name="' . $f . '" value="' . e($v) . '" style="width:360px">',
            'int' => '<input type="number" name="' . $f . '" value="' . (int)$v . '" style="width:90px">',
            'bool' => '<input type="checkbox" name="' . $f . '" value="1"' . ((int)$v === 1 ? ' checked' : '') . '>',
            'area' => '<textarea name="' . $f . '" rows="4" style="width:640px">' . e($v) . '</textarea>',
            default => '<input type="text" name="' . $f . '" value="' . e($v) . '" style="width:360px">',
        };
        $h .= '<tr><th>' . e($labels[$i]) . '</th><td>' . $input . '</td></tr>';
    }
    $h .= '</table><p><button class="btn" type="submit">' . ($row ? '保存修改' : '新增') . '</button></p></form>';

    // AI 内容助手 + 多语言（仅对可翻译内容项：案例 / 招聘）
    if (in_array($kind, ['case', 'job'], true)) { $h .= st_ai_panel($kind, $row); }

    $rows = Db::rows("SELECT * FROM " . Site::t($kind === 'cate' ? 'product_category' : $kind) . " WHERE is_delete_time = 0 ORDER BY sort DESC, id DESC LIMIT 200");
    $h .= '<table class="wp-list-table widefat"><thead><tr>';
    foreach ($labels as $lb) { $h .= '<th>' . e($lb) . '</th>'; }
    $h .= '<th>操作</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr>';
        foreach ($fields as $i => $f) {
            $v = (string)$r[$f];
            if ($types[$i] === 'bool') { $v = (int)$v === 1 ? '显示' : '隐藏'; }
            $h .= '<td>' . e(mb_substr($v, 0, 40)) . '</td>';
        }
        $h .= '<td><a href="' . $base . '&amp;tab=' . $kind . '&amp;edit=' . (int)$r['id'] . '">编辑</a> '
            . '<form method="post" style="display:inline" onsubmit="return confirm(\'确认删除？\')">' . \Csrf::field() . '<input type="hidden" name="act" value="' . $kind . '_delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn ghost mini" type="submit">删除</button></form></td></tr>';
    }
    return $h . '</tbody></table>';
}

function st_banner_page(string $base, string $msg): string
{
    return st_entity_page($base, $msg, 'banner', ['title', 'sub', 'image', 'link', 'sort', 'is_show'], ['标题', '副标题', '图片URL', '链接', '排序', '显示'], ['text', 'text', 'text', 'text', 'int', 'bool'], '横幅');
}

function st_cate_page(string $base, string $msg): string
{
    return st_entity_page($base, $msg, 'cate', ['name', 'slug', 'sort', 'is_show'], ['分类名', 'Slug', '排序', '显示'], ['text', 'text', 'int', 'bool'], '产品分类');
}

function st_product_page(string $base, string $msg): string
{
    $cates = Db::rows("SELECT id, name FROM " . Site::t('product_category') . " WHERE is_show = 1 AND is_delete_time = 0 ORDER BY sort DESC, id ASC");
    $opts = '<option value="0">未分类</option>';
    foreach ($cates as $ct) { $opts .= '<option value="' . (int)$ct['id'] . '">' . e((string)$ct['name']) . '</option>'; }
    $editId = (int)($_GET['edit'] ?? 0);
    $row = $editId > 0 ? Db::row("SELECT * FROM " . Site::t('product') . " WHERE id = :id", [':id' => $editId]) : null;
    $h = $msg . '<h4 style="margin:10px 0">' . ($row ? '编辑产品 #' . (int)$row['id'] : '新增产品') . '</h4><form method="post" style="margin-bottom:20px">' . \Csrf::field()
        . '<input type="hidden" name="act" value="product_save"><input type="hidden" name="id" value="' . (int)($row['id'] ?? 0) . '"><table class="form-table">'
        . '<tr><th>所属分类</th><td><select name="cate_id">' . $opts . '</select></td></tr>'
        . '<tr><th>产品名称</th><td><input type="text" name="name" value="' . e((string)($row['name'] ?? '')) . '" style="width:360px"></td></tr>'
        . '<tr><th>简介</th><td><textarea name="summary" rows="2" style="width:640px">' . e((string)($row['summary'] ?? '')) . '</textarea></td></tr>'
        . '<tr><th>详情内容</th><td><textarea name="content" rows="6" style="width:640px">' . e((string)($row['content'] ?? '')) . '</textarea></td></tr>'
        . '<tr><th>图片URL</th><td><input type="text" name="image" value="' . e((string)($row['image'] ?? '')) . '" style="width:480px"></td></tr>'
        . '<tr><th>参数表</th><td><textarea name="params" rows="4" placeholder="每行一条：参数名|参数值" style="width:640px">' . e((string)($row['params'] ?? '')) . '</textarea></td></tr>'
        . '<tr><th>排序 / 显示</th><td><input type="number" name="sort" value="' . (int)($row['sort'] ?? 0) . '" style="width:90px"> <input type="checkbox" name="is_show" value="1"' . ((int)($row['is_show'] ?? 1) === 1 ? ' checked' : '') . '> 显示</td></tr>'
        . '</table><p><button class="btn" type="submit">' . ($row ? '保存修改' : '新增') . '</button></p></form>'
        . st_ai_panel('product', $row);

    $rows = Db::rows("SELECT id, name, cate_id, is_show FROM " . Site::t('product') . " WHERE is_delete_time = 0 ORDER BY sort DESC, id DESC LIMIT 200");
    $h .= '<table class="wp-list-table widefat"><thead><tr><th>ID</th><th>名称</th><th>分类</th><th>显示</th><th>操作</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><td>' . (int)$r['id'] . '</td><td>' . e((string)$r['name']) . '</td><td>' . (int)$r['cate_id'] . '</td><td>' . ((int)$r['is_show'] === 1 ? '显示' : '隐藏') . '</td>'
            . '<td><a href="' . $base . '&amp;tab=product&amp;edit=' . (int)$r['id'] . '">编辑</a> '
            . '<form method="post" style="display:inline" onsubmit="return confirm(\'确认删除？\')">' . \Csrf::field() . '<input type="hidden" name="act" value="product_delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn ghost mini" type="submit">删除</button></form></td></tr>';
    }
    return $h . '</tbody></table>';
}

function st_case_page(string $base, string $msg): string
{
    return st_entity_page($base, $msg, 'case', ['name', 'industry', 'challenge', 'solution', 'result', 'image', 'sort', 'is_show'], ['客户/项目', '行业', '客户挑战', '解决方案', '成果', '图片URL', '排序', '显示'], ['text', 'text', 'area', 'area', 'area', 'text', 'int', 'bool'], '客户案例');
}

function st_job_page(string $base, string $msg): string
{
    return st_entity_page($base, $msg, 'job', ['title', 'place', 'type', 'salary', 'duty', 'requirement', 'sort', 'is_show'], ['职位', '地点', '类型', '薪资', '岗位职责(每行一条)', '任职要求(每行一条)', '排序', '显示'], ['text', 'text', 'text', 'text', 'area', 'area', 'int', 'bool'], '招聘');
}

function st_message_page(string $base): string
{
    $rows = Db::rows("SELECT * FROM " . Site::t('message') . " ORDER BY id DESC LIMIT 200");
    $h = '<table class="wp-list-table widefat"><thead><tr><th>时间</th><th>称呼</th><th>联系方式</th><th>意图</th><th>内容</th><th>状态</th><th>操作</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><td>' . e((string)$r['created_at']) . '</td><td>' . e((string)$r['name']) . '</td><td>' . e((string)$r['contact']) . '</td><td>' . e((string)$r['intent']) . '</td><td>' . e(mb_substr((string)$r['content'], 0, 80)) . '</td>'
            . '<td>' . ((int)$r['is_read'] === 1 ? '已读' : '<b style="color:#a32d2d">未读</b>') . '</td><td>'
            . ((int)$r['is_read'] === 0 ? '<form method="post" style="display:inline">' . \Csrf::field() . '<input type="hidden" name="act" value="message_read"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn ghost mini" type="submit">标已读</button></form> ' : '')
            . '<form method="post" style="display:inline" onsubmit="return confirm(\'确认删除？\')">' . \Csrf::field() . '<input type="hidden" name="act" value="message_delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn ghost mini" type="submit">删除</button></form></td></tr>';
    }
    return $h . '</tbody></table>';
}

/* ================= 行业风格包（12 套行业皮肤） ================= */

/**
 * 行业风格页：每套风格一张卡片（配色板取自该主题 style.css，前台后台永远同源），
 * 支持「使用此风格」「预览」（?theme= 无副作用预览）「载入演示数据」（整套行业内容）。
 */
/** 多语言：译文覆盖统计 + 一键 AI 翻译整站 */
function st_lang_page(string $base, string $msg): string
{
    $h = $msg . '<p style="color:#666;margin:0 0 12px">前台语言切换：<b>'
        . (Site::opt('lang.switch', '1') === '1' ? '已开启' : '已关闭') . '</b>'
        . '（在「企业信息」里可切换）。界面文案（导航 / 按钮 / 区块标题 / 页脚 / 面包屑 / 浮窗）由内置词典直接输出，'
        . '已覆盖 <b>' . count(Site::dict()) . '</b> 条词条，切语言即生效、无需 AI。'
        . '<br>站点文案（企业信息 / 优势条）与内容（产品 / 案例 / 职位 / 横幅）由 AI 翻译并落库；'
        . '「一键 AI 翻译整站」会把当前生效行业的内容一次性翻完。</p>';

    $aiOk = class_exists('Ai') && \Plugins::isOn('ai') && Ai::isConfigured();
    if (!$aiOk) {
        $h .= '<div class="notice error">AI 未配置：请先到「AI 能力」插件填写服务商 Key，「一键翻译」才能使用。</div>';
    }

    $stat = [];
    foreach (Db::rows('SELECT lang, COUNT(*) AS n, MAX(updated_at) AS t FROM ' . Db::t('site_i18n') . ' GROUP BY lang') as $r) {
        $stat[(string)$r['lang']] = $r;
    }

    $h .= '<table class="mla-table" style="width:100%;border-collapse:collapse">'
        . '<thead><tr><th style="text-align:left">语言</th><th style="text-align:left">已翻译记录</th>'
        . '<th style="text-align:left">最近更新</th><th style="text-align:left">站点文案</th><th style="text-align:left">操作</th></tr></thead><tbody>';
    foreach (Site::LANGS as $code => $name) {
        $n = (int)($stat[$code]['n'] ?? 0);
        $t = (int)($stat[$code]['t'] ?? 0);
        $siteTr = Site::trFor('site', 0, $code);
        $h .= '<tr style="border-top:1px solid #eee">'
            . '<td style="padding:7px 0"><b>' . e($name) . '</b> <span style="color:#999">' . e($code) . '</span></td>'
            . '<td>' . ($n > 0 ? '<b>' . $n . '</b>' : '<span style="color:#999">0</span>') . '</td>'
            . '<td>' . ($t > 0 ? e(date('Y-m-d H:i', $t)) : '<span style="color:#999">—</span>') . '</td>'
            . '<td>' . (count($siteTr) > 0 ? '已生成 ' . count($siteTr) . ' 项' : '<span style="color:#999">未生成</span>') . '</td>'
            . '<td><form method="post" style="display:inline-block;margin:0">' . \Csrf::field()
            . '<input type="hidden" name="act" value="ai_translate_all">'
            . '<input type="hidden" name="lang" value="' . e($code) . '">'
            . '<button class="btn mini" type="submit"' . ($aiOk ? ' onclick="return confirm(\'将调用 AI 翻译当前行业的站点文案与全部内容，可能耗时 1~3 分钟，确定继续？\')"' : ' disabled')
            . '>一键 AI 翻译整站</button></form></td></tr>';
    }
    $h .= '</tbody></table>';

    // 站点文案译文对照
    foreach ($stat as $code => $_r) {
        if (!isset(Site::LANGS[$code])) { continue; }
        $tr = Site::trFor('site', 0, $code);
        if (!$tr) { continue; }
        $fs = Site::features(false);
        $h .= '<h4 style="margin:20px 0 6px">站点文案译文 · ' . e(Site::LANGS[$code]) . '</h4>'
            . '<table class="mla-table" style="width:100%;border-collapse:collapse"><thead><tr>'
            . '<th style="text-align:left">项目</th><th style="text-align:left">原文</th><th style="text-align:left">译文</th></tr></thead><tbody>';
        foreach (Site::SITE_TR_KEYS as $k) {
            $src = Site::opt($k, '');
            $dst = (string)($tr[$k] ?? '');
            if ($src === '' && $dst === '') { continue; }
            $h .= '<tr style="border-top:1px solid #eee"><td style="padding:6px 0"><code>' . e($k) . '</code></td>'
                . '<td>' . e(mb_substr($src, 0, 48)) . '</td><td>' . e(mb_substr($dst, 0, 72)) . '</td></tr>';
        }
        foreach ((array)($tr['features'] ?? []) as $i => $f) {
            foreach (['title', 'desc'] as $fld) {
                $dst = (string)($f[$fld] ?? '');
                if ($dst === '') { continue; }
                $h .= '<tr style="border-top:1px solid #eee"><td><code>features.' . (int)$i . '.' . $fld . '</code></td>'
                    . '<td>' . e(mb_substr((string)($fs[$i][$fld] ?? ''), 0, 48)) . '</td><td>' . e(mb_substr($dst, 0, 72)) . '</td></tr>';
            }
        }
        $h .= '</tbody></table>';
    }
    return $h;
}

function st_skin_page(string $base, string $msg): string
{
    $cur = Site::theme();
    $h = $msg . '<p style="color:#666;margin:0 0 14px">共 <b>' . count(Site::THEMES) . '</b> 套行业风格包：'
        . '每套 = 配色令牌（<code>themes/&lt;id&gt;/style.css</code>）+ 行业术语词表与首页区块结构（<code>profile.php</code>），公共骨架统一走 <code>themes/_base.css</code>。<br>'
        . '「使用此风格」只换皮肤与文案，不动数据；「载入演示数据」会写入该行业的整套演示内容（企业信息 / 优势 / 产品 / 案例 / 招聘 / 新闻 / AI 知识库），用于演示与客户对比。</p>';
    $h .= '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(272px,1fr));gap:14px">';
    foreach (Site::THEMES as $id => $label) {
        $t = st_skin_tokens($id);
        $pf = __DIR__ . '/themes/' . $id . '/profile.php';
        $blocks = is_file($pf) ? (array)(((array)(include $pf))['home'] ?? []) : [];
        $on = ($cur === $id);
        $h .= '<div style="border:1px solid ' . ($on ? '#2271b1' : '#dcdcde') . ';border-radius:6px;padding:12px;background:' . ($on ? '#f0f6fc' : '#fff') . '">';
        $h .= '<div style="display:flex;height:36px;border:1px solid #e5e5e5;border-radius:4px;overflow:hidden">';
        foreach (['primary', 'deep', 'accent', 'bg', 'surface'] as $k) {
            $h .= '<span style="flex:1;background:' . e((string)($t[$k] ?? '#eeeeee')) . '"></span>';
        }
        $h .= '</div>';
        $h .= '<p style="margin:9px 0 3px"><b>' . e($label) . '</b>' . ($on ? ' <span style="color:#2271b1;font-size:12px">· 使用中</span>' : '') . '</p>';
        $h .= '<p style="margin:0 0 4px;font-size:12px;color:#666">风格 ID：<code>' . e($id) . '</code></p>';
        $h .= '<p style="margin:0 0 9px;font-size:12px;color:#888">首页区块：' . e(implode(' → ', $blocks)) . '</p>';
        $h .= '<form method="post" style="display:inline-block;margin:0 4px 4px 0">' . \Csrf::field()
            . '<input type="hidden" name="act" value="save_theme"><input type="hidden" name="theme" value="' . e($id) . '">'
            . '<button class="btn mini" type="submit">' . ($on ? '重新应用' : '使用此风格') . '</button></form>';
        $h .= '<a class="btn ghost mini" style="margin:0 4px 4px 0" href="/site?theme=' . e($id) . '" target="_blank" rel="noopener">预览</a>';
        $h .= '<form method="post" style="display:inline-block;margin:0 0 4px 0" onsubmit="return confirm(\'载入「' . e($label) . '」演示数据会替换现有官网产品 / 案例 / 招聘 / 横幅与「公司动态」新闻，确定继续？\')">' . \Csrf::field()
            . '<input type="hidden" name="act" value="seed_demo"><input type="hidden" name="industry" value="' . e($id) . '">'
            . '<button class="btn ghost mini" type="submit">载入演示数据</button></form>';
        $h .= '</div>';
    }
    return $h . '</div>';
}

/** 从主题 style.css 读令牌色（后台色板与前台实际配色同源，不写第二份） */
function st_skin_tokens(string $id): array
{
    $f = __DIR__ . '/themes/' . $id . '/style.css';
    if (!is_file($f)) { return []; }
    $css = (string)file_get_contents($f);
    $out = [];
    foreach (['primary', 'deep', 'accent', 'bg', 'surface'] as $k) {
        if (preg_match('/--sc-' . $k . '\s*:\s*([^;}]+)/', $css, $m)) { $out[$k] = trim($m[1]); }
    }
    return $out;
}
