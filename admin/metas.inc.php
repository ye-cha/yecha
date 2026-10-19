<?php
declare(strict_types=1);
/**
 * 后台分类标签管理：新增 / 编辑改名 / 删除（维护 relationships 与计数，slug 冲突加后缀）
 * POST do=meta-save | meta-delete（CSRF 由 admin 外层校验）
 */
function metas_do(string $do): never
{
    if ($do === 'meta-delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            Db::deleteBy('relationships', 'meta_id=?', [$id]);
            Db::deleteBy('metas', 'id=?', [$id]);
            Cache::flush('pages');
            Cache::flush('blocks');
            $_SESSION['flash'] = '已删除该分类/标签及其关联';
        }
        header('Location: /admin/?s=metas');
        exit;
    }
    // meta-save（新增或编辑）
    $id = (int)($_POST['id'] ?? 0);
    $type = ($_POST['type'] ?? '') === 'category' ? 'category' : 'tag';
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') { $_SESSION['flash'] = '名称不能为空'; header('Location: /admin/?s=metas'); exit; }
    $rawSlug = trim((string)($_POST['slug'] ?? ''));
    $slug = $type === 'tag' ? Str::tagSlug($name) : ($rawSlug !== '' ? substr(strtolower(preg_replace('/[^a-z0-9-]/', '-', $rawSlug) ?? ''), 0, 190) : Str::slug($name));
    if ($type === 'tag' && $rawSlug !== '' && !preg_match('/[\x{4e00}-\x{9fa5}]/u', $name)) { $slug = Str::slug($rawSlug); }
    // slug 唯一（与同类型其它术语的 slug 列及 slug_i18n 任一语言值都不冲突，避免前台语言解析串位）
    $candidate = $slug;
    for ($i = 2; $i < 60; $i++) {
        if (!Poly::baseSlugReserved($type, $candidate, $id)) { break; }
        $candidate = $slug . '-' . $i;
    }
    // names_i18n：合并各启用语言名称（保留未提交的其它语言键）
    $i18n = [];
    if ($id > 0) {
        $oldI18n = Db::row('SELECT names_i18n FROM ' . Db::t('metas') . ' WHERE id=?', [$id]);
        $dec = json_decode((string)($oldI18n['names_i18n'] ?? ''), true);
        $i18n = is_array($dec) ? $dec : [];
    }
    $default = Poly::defaultLang();
    foreach (Poly::enabled() as $code) {
        if ($code === $default) { continue; }
        $val = trim((string)($_POST['name_i18n_' . $code] ?? ''));
        if ($val !== '') { $i18n[$code] = $val; }
    }
    $i18nJson = json_encode($i18n, JSON_UNESCAPED_UNICODE);
    $nameEn = (string)($i18n['en'] ?? '');
    $slugI18n = json_encode(Poly::termSlugI18nFor($i18n, $type, $id), JSON_UNESCAPED_UNICODE);
    if ($id > 0) {
        Db::update('metas', ['name' => $name, 'name_en' => $nameEn, 'names_i18n' => $i18nJson, 'slug_i18n' => $slugI18n, 'slug' => $candidate], 'id=?', [$id]);
    } else {
        Db::insert('metas', ['type' => $type, 'name' => $name, 'name_en' => $nameEn, 'names_i18n' => $i18nJson, 'slug_i18n' => $slugI18n, 'slug' => $candidate]);
    }
    Cache::flush('pages');
    Cache::flush('blocks');
    $_SESSION['flash'] = '已保存（' . ($type === 'category' ? '分类' : '标签') . '：' . $name . '）';
    header('Location: /admin/?s=metas');
    exit;
}

function metas_editor_html(string $type, int $id = 0): string
{
    $d = ['name' => '', 'name_en' => '', 'slug' => '', 'names_i18n' => ''];
    if ($id > 0) {
        $r = Db::row('SELECT * FROM ' . Db::t('metas') . ' WHERE id=?', [$id]);
        if ($r !== null) { $d = ['name' => (string)$r['name'], 'name_en' => (string)($r['name_en'] ?? ''), 'slug' => (string)$r['slug'], 'type' => (string)$r['type'], 'names_i18n' => (string)($r['names_i18n'] ?? '')]; }
        $type = $d['type'];
    }
    $i18nDec = json_decode($d['names_i18n'], true);
    $i18nVal = is_array($i18nDec) ? $i18nDec : [];
    $typeName = $type === 'category' ? '分类' : '标签';
    $listUrl = '/admin/?s=metas';
    $defLang = Poly::defaultLang();
    // 各启用语言名称输入格
    $cells = '<div><label class="pf">名称（' . Poly::label($defLang) . ' / 默认） *</label><input name="name" value="' . e($d['name']) . '" required></div>';
    foreach (Poly::enabled() as $code) {
        if ($code === $defLang) { continue; }
        $v = $i18nVal[$code] ?? (($code === 'en') ? $d['name_en'] : '');
        $cells .= '<div><label class="pf">名称（' . e(Poly::label($code)) . '）</label><input name="name_i18n_' . $code . '" value="' . e((string)$v) . '" placeholder="留空：前台自动回落中文名"></div>';
    }
    return '<div class="card"><h3>' . ($id > 0 ? '编辑' : '新增') . $typeName . '</h3>'
        . '<form method="post" action="/admin/?s=metas">' . Csrf::field()
        . '<input type="hidden" name="do" value="meta-save"><input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="type" value="' . $type . '">'
        . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:640px;">' . $cells
        . '<div><label class="pf">slug（留空自动；标签自动用中文名）</label><input name="slug" value="' . e($d['slug']) . '"></div></div>'
        . '<div style="margin-top:12px;"><button class="btn" type="submit">保存</button> <a class="btn ghost" href="' . $listUrl . '">返回列表</a></div></form>'
        . ($id > 0
            ? '<form method="post" action="/admin/?s=metas" style="margin-top:10px;display:inline;" onsubmit="return confirm(\'删除该' . $typeName . '？将同时移除关联。\')">' . Csrf::field()
                . '<input type="hidden" name="do" value="meta-delete"><input type="hidden" name="id" value="' . $id . '">'
                . '<button class="btn ghost danger" type="submit">删除</button></form>'
            : '')
        . '<style>.pf{display:block;font-size:12.5px;color:#6f767e;margin:6px 0 2px}.pf+input{width:100%;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font:inherit}</style>';
}

function metas_list_html(): string
{
    $T = Db::t('');
    $q = trim((string)($_GET['q'] ?? ''));
    $qWhere = $q !== '' ? " AND (name LIKE '%" . addcslashes($q, "%_'") . "%' OR slug LIKE '%" . addcslashes($q, "%_'") . "%')" : '';
    $qTagTotal = $q !== '' ? (int)Db::one("SELECT COUNT(*) FROM {$T}metas WHERE type='tag'{$qWhere}") : 0;
    $cats = Db::rows("SELECT * FROM {$T}metas WHERE type='category' ORDER BY `order` ASC, id ASC");
    $tags = Db::rows("SELECT * FROM {$T}metas WHERE type='tag'{$qWhere} ORDER BY `count` DESC, id ASC LIMIT 300");
    $totalTags = $q !== '' ? $qTagTotal : (int)Db::one("SELECT COUNT(*) FROM {$T}metas WHERE type='tag'");
    $rowsOf = function (array $rows, string $type) {
        $langs = array_values(array_diff(Poly::enabled(), [Poly::defaultLang()]));
        $out = '';
        foreach ($rows as $m) {
            $subs = [];
            foreach ($langs as $code) {
                $ln = Poly::termLang($m, $code);
                if ($ln !== '') { $subs[] = '<span style="margin-right:8px;"><b>' . e(Poly::label($code)) . '</b> · ' . e($ln) . '</span>'; }
            }
            $out .= '<tr><td>' . e($m['name']) . (count($subs) ? '<div style="color:#6f767e;font-size:12px;margin-top:2px;">' . implode('', $subs) . '</div>' : '') . '</td><td><code style="font-size:12px;color:#6f767e;">' . e((string)$m['slug']) . '</code></td>'
                . '<td>' . (int)$m['count'] . '</td>'
                . '<td><a class="btn ghost mini" href="/' . $type . '/' . rawurlencode((string)$m['slug']) . '/">前台</a> '
                . '<a class="btn ghost mini" href="/admin/?s=metas&action=edit&id=' . (int)$m['id'] . '">编辑</a></td></tr>';
        }
        return $out;
    };
    $trimNote = $totalTags > 300 ? '<div class="stub" style="margin:8px 0;">共 ' . $totalTags . ' 个标签：仅显示前 300（按文章数），可用上方搜索精确查找。</div>' : '';
    return '<div class="card"><h3>分类 <small>管理入口：新增 / 改名 / 删除</small></h3>'
        . '<a class="btn" href="/admin/?s=metas&action=new&type=category">＋ 新增分类</a></div>'
        . '<div class="card"><table><tr><th>名称</th><th>slug</th><th>文章数</th><th>操作</th></tr>' . $rowsOf($cats, 'category') . '</table></div>'
        . '<div class="card"><h3>标签 <small>' . $totalTags . ' 个 · 中文名即 slug（可读 URL）</small></h3>'
        . '<form method="get" action="/admin/" style="display:flex;gap:8px;margin-bottom:8px;"><input type="hidden" name="s" value="metas">'
        . '<input name="q" value="' . e($q) . '" placeholder="搜索标签名/slug…" style="border:1px solid #e6e6e2;border-radius:6px;padding:5px 10px;min-width:180px;">'
        . '<button class="btn ghost" type="submit">搜索</button>' . ($q !== '' ? ' <a class="btn ghost" href="/admin/?s=metas">清除</a>' : '') . '</form>' . $trimNote
        . '<a class="btn" href="/admin/?s=metas&action=new&type=tag">＋ 新增标签</a></div>'
        . '<div class="card"><table><tr><th>名称</th><th>slug</th><th>文章数</th><th>操作</th></tr>' . $rowsOf($tags, 'tag') . '</table></div>';
}
