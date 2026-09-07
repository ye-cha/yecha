<?php
declare(strict_types=1);
/**
 * 后台文章/页面 模块：列表 / 编辑表单 / 保存 / 回收站删除 / 图片上传
 * 由 admin/index.php 在对应 section/action 下调用；依赖全局已加载 Db/Cache/Csrf/Auth/Str/e()。
 */

/** 回收站外所有 post/page 行（供列表）；$st='trash' 时列回收站 */
function pc_list_rows(string $type, string $q = '', string $st = '', int $page = 1, int $per = 20): array
{
    $where = "type=?";
    $p = [$type];
    if ($st === 'trash') {
        $where .= ' AND deleted_at IS NOT NULL';
    } else {
        $where .= ' AND deleted_at IS NULL';
        if ($q !== '') { $where .= ' AND title LIKE ?'; $p[] = '%' . $q . '%'; }
        if ($st !== '') { $where .= ' AND status=?'; $p[] = $st; }
    }
    $total = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . " WHERE $where", $p);
    $offset = ($page - 1) * $per;
    $rows = Db::rows('SELECT * FROM ' . Db::t('contents') . " WHERE $where ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT $per OFFSET $offset", $p);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per))];
}

/** 回收站动作：restore 恢复（保留原状态字段外，置回草稿或原状态）；purge 彻底删除 */
function pc_trash_action(string $type, string $act, int $cid): void
{
    if ($cid <= 0) { return; }
    $row = Db::row('SELECT * FROM ' . Db::t('contents') . ' WHERE id=? AND type=? AND deleted_at IS NOT NULL', [$cid, $type]);
    if ($row === null) { $_SESSION['flash'] = '内容不在回收站中'; return; }
    if ($act === 'restore') {
        Db::update('contents', ['deleted_at' => null, 'status' => 'draft', 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$cid]);
        $_SESSION['flash'] = '已从回收站恢复为草稿：' . (string)$row['title'];
    } elseif ($act === 'purge') {
        Db::deleteBy('relationships', 'content_id=?', [$cid]);
        Db::deleteBy('poly_contents', 'group_id=?', [$cid]);
        Db::deleteBy('comments', 'content_id=?', [$cid]);
        Db::deleteBy('contents', 'id=?', [$cid]);
        $_SESSION['flash'] = '已彻底删除：' . (string)$row['title'];
    }
    Cache::flush('pages');
    Cache::flush('blocks');
}

/** 汇总单个 meta 的文章数并回写 */
function pc_recount(array $metaIds): void
{
    foreach (array_unique(array_filter(array_map('intval', $metaIds))) as $id) {
        $n = (int)Db::one(
            'SELECT COUNT(*) FROM ' . Db::t('relationships') . ' r JOIN ' . Db::t('contents')
            . " c ON c.id=r.content_id WHERE r.meta_id=? AND c.type='post' AND c.deleted_at IS NULL",
            [$id]
        );
        Db::update('metas', ['count' => $n], 'id=?', [$id]);
    }
}

/** 保存文章/页面（POST 入口，CSRF 已由外层校验） */
function pc_save(): void
{
    $type = ($_POST['type'] ?? 'post') === 'page' ? 'page' : 'post';
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') {
        $_SESSION['flash'] = '标题不能为空';
        header('Location: /admin/?s=' . $type . 's&action=new');
        exit;
    }
    // slug：空则按标题生成；否则清洗为 [a-z0-9-]
    $rawSlug = trim((string)($_POST['slug'] ?? ''));
    $slug = $rawSlug === ''
        ? Str::slug($title)
        : substr(strtolower(preg_replace('/[^a-z0-9-]/', '-', $rawSlug) ?? ''), 0, 190);
    $slug = trim($slug, '-');
    if ($slug === '' || strlen($slug) < 2) { $slug = Str::slug($title); }
    // 保留字与冲突：追加 -2/-3…（回收站中的旧 slug 也视为占用，避免唯一索引冲突）
    $base = $slug;
    for ($i = 2; $i < 50; $i++) {
        $exists = Db::one('SELECT id FROM ' . Db::t('contents') . ' WHERE slug=? AND id<>? LIMIT 1', [$slug, $id]);
        if ($exists === null && !in_array($slug, Router::RESERVED, true)) { break; }
        $slug = $base . '-' . $i;
    }

    $status = (string)($_POST['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'publish', 'scheduled', 'hidden'], true)) { $status = 'draft'; }
    $now = date('Y-m-d H:i:s');
    $scheduledAt = trim((string)($_POST['scheduled_at'] ?? ''));
    $publishedAt = null;
    if ($status === 'publish') {
        // 保留已有发布时间
        if ($id > 0) {
            $old = Db::row('SELECT published_at FROM ' . Db::t('contents') . ' WHERE id=?', [$id]);
            $publishedAt = $old && $old['published_at'] ? $old['published_at'] : $now;
        } else {
            $publishedAt = $now;
        }
    } elseif ($status === 'scheduled' && $scheduledAt !== '') {
        $publishedAt = date('Y-m-d H:i:s', strtotime($scheduledAt));
        if ($publishedAt === false) { $publishedAt = null; }
    }

    $data = [
        'type' => $type, 'title' => $title, 'slug' => $slug,
        'excerpt' => trim((string)($_POST['excerpt'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'keywords' => trim((string)($_POST['keywords'] ?? '')),
        'content' => (string)($_POST['content'] ?? ''),
        'content_format' => 'markdown',
        'status' => $status,
        'allow_comment' => isset($_POST['allow_comment']) ? 1 : 0,
        'featured' => isset($_POST['featured']) ? 1 : 0,
        'order' => isset($_POST['sticky']) ? 1 : 0,
    ];
    if ($publishedAt !== null) { $data['published_at'] = $publishedAt; }

    $T = Db::t('');
    // 旧 meta（用于计数回收）
    $oldMetaIds = [];
    $emptyContentKeep = false; // 防呆：正文为空且原正文非空 → 保留原正文，避免旧版编辑器空提交把内容清空
    if ($id > 0) {
        $oldMetaRows = Db::rows('SELECT meta_id FROM ' . Db::t('relationships') . ' WHERE content_id=?', [$id]);
        $oldMetaIds = array_column($oldMetaRows, 'meta_id');
        if ((string)$data['content'] === '') {
            $oldBody = Db::one('SELECT content FROM ' . Db::t('contents') . ' WHERE id=?', [$id]);
            if ($oldBody !== null && $oldBody !== '') { $data['content'] = (string)$oldBody; $emptyContentKeep = true; }
        }
        Db::update('contents', $data, 'id=?', [$id]);
    } else {
        $data['author_id'] = Auth::id();
        $data['views'] = 0;
        $id = Db::insert('contents', $data);
    }

    // 分类（单）+ 标签（多）关系重建
    Db::deleteBy('relationships', 'content_id=?', [$id]);
    $newMetaIds = [];
    $catId = (int)($_POST['category'] ?? 0);
    if ($catId > 0) {
        Db::insert('relationships', ['content_id' => $id, 'meta_id' => $catId]);
        $newMetaIds[] = $catId;
    }
    $tags = trim((string)($_POST['tags'] ?? ''));
    if ($tags !== '') {
        foreach (array_unique(array_filter(array_map('trim', explode(',', $tags)))) as $tName) {
            $row = Db::row('SELECT id FROM ' . Db::t('metas') . " WHERE type='tag' AND name=? LIMIT 1", [$tName]);
            $tid = $row ? (int)$row['id'] : Db::insert('metas', ['type' => 'tag', 'name' => $tName, 'slug' => Str::tagSlug($tName)]);
            Db::insert('relationships', ['content_id' => $id, 'meta_id' => $tid]);
            $newMetaIds[] = $tid;
        }
    }
    // 若 type=page 不需要分类计数，但无妨
    pc_recount(array_merge($oldMetaIds, $newMetaIds));

    // 多语：基准原文被修改 → 译文标记“过期”；AI 全自动模式语言在本请求内自动重译（人工锁定篇跳过）；文章与独立页均适用
    $autoNote = '';
    if (in_array($type, ['post', 'page'], true) && $id > 0 && Poly::on()) {
        Poly::markStale($id);
        $autoDone = [];
        foreach (Poly::enabled() as $lc) {
            if ($lc === Poly::defaultLang() || Poly::mode($lc) !== 'ai') { continue; }
            if (!yc_opt_has('ai.key')) { continue; }
            try {
                $r = Poly::aiTranslate($id, $lc, '', '', '', true);
                if (($r['ok'] ?? false) && empty($r['skipped_override'])) { $autoDone[] = Poly::label($lc); }
            } catch (Throwable $e) { /* 单语言失败不阻断保存 */ }
        }
        if ($autoDone) { $autoNote = ' · 已自动重译：' . implode('/', $autoDone); }
    }

    Cache::flush('pages');
    Cache::flush('blocks');
    $statusText = ['draft' => '草稿', 'publish' => '已发布', 'scheduled' => '定时', 'hidden' => '已下线'][$status] ?? $status;
    $_SESSION['flash'] = ($id > 0 ? '更新成功' : '已保存') . '（' . $statusText . '）· slug：' . $slug . $autoNote
        . ($emptyContentKeep ? ' · ⚠ 正文为空，已保留原正文（请用新编辑器后刷新再试）' : '');
    header('Location: /admin/?s=' . $type . 's');
    exit;
}

/** 回收站（软删除） */
function pc_trash(): void
{
    $id = (int)($_POST['cid'] ?? 0);
    if ($id > 0) {
        $row = Db::row('SELECT * FROM ' . Db::t('contents') . ' WHERE id=?', [$id]);
        Db::update('contents', ['status' => 'trash', 'deleted_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
        if ($row) {
            $old = Db::rows('SELECT meta_id FROM ' . Db::t('relationships') . ' WHERE content_id=?', [$id]);
            pc_recount(array_column($old, 'meta_id'));
        }
        Cache::flush('pages');
        Cache::flush('blocks');
        $_SESSION['flash'] = '已移入回收站';
    }
    header('Location: /admin/?s=' . ((($_POST['type'] ?? '') === 'page') ? 'pages' : 'posts'));
    exit;
}

/** 图片/附件上传（POST multipart，返回 JSON） */
function pc_upload(): void
{
    header('Content-Type: application/json; charset=utf-8');
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => '上传失败（error=' . ($f['error'] ?? 'none') . '）'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allow = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'md', 'txt', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
    if (!in_array($ext, $allow, true)) {
        echo json_encode(['ok' => false, 'error' => '不允许的文件类型：.' . $ext], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($f['size'] > 10 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => '文件超过 10MB'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 真实类型校验：光栅图必须能解析（防止伪装脚本），SVG 保留但仅限 img 语境引用
    $raster = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (in_array($ext, $raster, true) && @getimagesize($f['tmp_name']) === false) {
        echo json_encode(['ok' => false, 'error' => '图片文件无效（类型校验失败）'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rel = date('Y/m');
    $dir = dirname(__DIR__) . '/uploads/' . $rel;
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $name = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        echo json_encode(['ok' => false, 'error' => '无法写入 uploads（权限？）'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $url = '/uploads/' . $rel . '/' . $name;
    Db::insert('attachments', [
        'user_id' => Auth::id(), 'file_path' => $rel . '/' . $name,
        'file_name' => $f['name'], 'file_type' => (string)($f['type'] ?? ''), 'file_ext' => $ext,
        'size' => (int)$f['size'], 'source' => 'local',
    ]);
    $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    echo json_encode(['ok' => true, 'url' => $url, 'is_img' => $isImg, 'name' => $f['name']], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ================= 列表视图 ================= */
function pc_render_list(string $type, string $q, string $st, int $page): string
{
    $d = pc_list_rows($type, $q, $st, $page);
    $typeName = $type === 'page' ? '页面' : '文章';
    $list = '';
    $statusMap = ['publish' => '已发布', 'draft' => '草稿', 'scheduled' => '定时', 'hidden' => '已下线', 'trash' => '回收站'];
    $isTrash = $st === 'trash';
    foreach ($d['rows'] as $r) {
        $stTxt = $statusMap[$r['status']] ?? $r['status'];
        $styl = $r['status'] === 'draft' ? ' style="color:#9a6b3f;"' : ($r['status'] === 'scheduled' ? ' style="color:#2e6b34;"' : '');
        if ($isTrash) {
            // 回收站行：恢复 / 彻底删除
            $list .= '<tr><td>' . e($r['title']) . '<br><span style="color:#c0c0b8;font-size:11.5px;">删除于 ' . e(substr((string)$r['deleted_at'], 0, 16)) . ' · slug ' . e((string)$r['slug']) . '</span></td>'
                . '<td><code style="font-size:12px;color:#6f767e;">' . e((string)$r['slug']) . '</code></td>'
                . '<td style="color:#9c3f1b;">回收站</td>'
                . '<td>' . number_format((int)$r['views']) . '</td>'
                . '<td>' . e(substr((string)($r['published_at'] ?? $r['created_at']), 0, 10)) . '</td>'
                . '<td><form class="inline" method="post" action="/admin/?s=' . $type . 's"><input type="hidden" name="do" value="trash-restore"><input type="hidden" name="type" value="' . $type . '">' . Csrf::field()
                . '<input type="hidden" name="cid" value="' . (int)$r['id'] . '"><button class="btn ghost mini" type="submit">恢复</button></form> '
                . '<form class="inline" method="post" action="/admin/?s=' . $type . 's" onsubmit="return confirm(\'彻底删除（含其评论/译文/关系，不可恢复）？\')"><input type="hidden" name="do" value="trash-purge"><input type="hidden" name="type" value="' . $type . '">' . Csrf::field()
                . '<input type="hidden" name="cid" value="' . (int)$r['id'] . '"><button class="btn ghost mini" style="border-color:#e3c5b6;color:#9c3f1b;" type="submit">彻底删除</button></form></td></tr>';
            continue;
        }
        $list .= '<tr><td>' . e($r['title']) . ($r['status'] === 'scheduled' ? '<br><span style="color:#9a6b3f;font-size:11.5px;">将于 ' . e(substr((string)$r['published_at'], 0, 16)) . ' 发布</span>' : '')
            . '</td><td><code style="font-size:12px;color:#6f767e;">' . e((string)$r['slug']) . '</code></td>'
            . '<td' . $styl . '>' . $stTxt . '</td>'
            . '<td>' . number_format((int)$r['views']) . '</td>'
            . '<td>' . e(substr((string)($r['published_at'] ?? $r['created_at']), 0, 10)) . '</td>'
            . '<td><a class="btn ghost mini" href="/admin/?s=' . $type . 's&action=edit&id=' . (int)$r['id'] . '">编辑</a> '
            . ($r['status'] === 'publish'
                ? '<a class="btn ghost mini" href="/' . e((string)$r['slug']) . '.html">预览</a> '
                : '<span style="color:#c0c0b8;font-size:12px;">未发布</span> ')
            . '<form class="inline" method="post" action="/admin/?s=' . $type . 's" onsubmit="return confirm(\'移入回收站？\')">' . Csrf::field()
            . '<input type="hidden" name="do" value="post-trash"><input type="hidden" name="type" value="' . $type . '">'
            . '<input type="hidden" name="cid" value="' . (int)$r['id'] . '"><button class="btn ghost mini" style="border-color:#e3c5b6;color:#9c3f1b;" type="submit">回收站</button></form></td></tr>';
    }
    $pager = '';
    if ($d['pages'] > 1) {
        $qprm = (($q !== '') ? '&q=' . rawurlencode($q) : '') . (($st !== '') ? '&st=' . $st : '');
        $lnk = function (int $i) use ($type, $qprm): string {
            return '/admin/?s=' . $type . 's&page=' . $i . $qprm;
        };
        $cur = fn(int $i) => $i === $d['page'] ? ' style="color:#b3541e;font-weight:700;"' : '';
        $pager = '<div style="padding:12px 0 4px;">';
        if ($d['page'] > 1) { $pager .= '<a href="' . $lnk($d['page'] - 1) . '">← 上一页</a> '; }
        $window = 5;
        $start = max(1, min($d['page'] - 2, $d['pages'] - $window + 1));
        $end = min($d['pages'], $start + $window - 1);
        if ($start > 1) { $pager .= '<a' . $cur(1) . ' href="' . $lnk(1) . '">1</a> … '; }
        for ($i = $start; $i <= $end; $i++) {
            $pager .= '<a' . $cur($i) . ' href="' . $lnk($i) . '">' . $i . '</a> ';
        }
        if ($end < $d['pages']) { $pager .= '… <a' . $cur($d['pages']) . ' href="' . $lnk($d['pages']) . '">' . $d['pages'] . '</a> '; }
        if ($d['page'] < $d['pages']) { $pager .= '<a href="' . $lnk($d['page'] + 1) . '">下一页 →</a> '; }
        $pager .= '（共 ' . $d['total'] . ' 条）</div>';
    }
    $sel = function (string $v) use ($st): string { return $v === $st ? ' selected' : ''; };
    return '<div class="card"><h3>' . $typeName . '列表 <small>共 ' . $d['total'] . ' 条</small></h3>'
        . '<form method="get" action="/admin/" style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap;">'
        . '<input type="hidden" name="s" value="' . $type . 's"><input name="q" value="' . e($q) . '" placeholder="搜索标题…" style="border:1px solid #e6e6e2;border-radius:6px;padding:6px 10px;min-width:200px;">'
        . '<select name="st" style="border:1px solid #e6e6e2;border-radius:6px;padding:6px 8px;"><option value="">全部状态</option>'
        . '<option value="publish"' . $sel('publish') . '>已发布</option><option value="draft"' . $sel('draft') . '>草稿</option>'
        . '<option value="scheduled"' . $sel('scheduled') . '>定时</option><option value="hidden"' . $sel('hidden') . '>已下线</option>'
        . '<option value="trash"' . $sel('trash') . '>回收站</option></select>'
        . '<button class="btn ghost" type="submit">筛选</button></form>'
        . '<a class="btn" href="/admin/?s=' . $type . 's&action=new">＋ 新建' . $typeName . '</a></div>'
        . '<div class="card"><table><tr><th>标题</th><th>slug</th><th>状态</th><th>阅读</th><th>日期</th><th>操作</th></tr>' . $list . '</table>' . $pager . '</div>';
}

/* ================= 多语（Drupal 式节点翻译） ================= */

/** 某语言版在中文编辑页的保存入口：POST do=poly-en-save（id=中文内容 id，lang=启用语言短码；post 与独立页 page 通用） */
function pc_poly_save(): void
{
    $baseId = (int)($_POST['id'] ?? 0);
    $code = trim((string)($_POST['lang'] ?? 'en'));
    $base = Db::row('SELECT * FROM ' . Db::t('contents') . ' WHERE id=?', [$baseId]);
    if ($base === null || !in_array(($base['type'] ?? ''), ['post', 'page'], true) || !in_array($code, Poly::enabled(), true) || $code === Poly::defaultLang()) {
        $_SESSION['flash'] = '参数错误';
        header('Location: /admin/?s=posts');
        exit;
    }
    $sec = ($base['type'] ?? 'post') === 'page' ? 'pages' : 'posts';
    $label = Poly::label($code);
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') {
        $_SESSION['flash'] = $label . '标题不能为空';
        header('Location: /admin/?s=' . $sec . '&action=edit&id=' . $baseId . '&lang=' . rawurlencode($code));
        exit;
    }
    // slug：同 Poly::save 规则（该语言下唯一）
    $raw = trim((string)($_POST['slug'] ?? ''));
    $slug = $raw === '' ? Str::slug($title) : trim(substr(strtolower(preg_replace('/[^a-z0-9-]/', '-', $raw) ?? ''), 0, 190), '-');
    $baseSlug = $slug;
    for ($i = 2; $i < 50; $i++) {
        $hit = Db::one("SELECT id FROM " . Db::t('poly_contents') . " WHERE lang=? AND slug=? AND group_id<>? LIMIT 1", [$code, $slug, $baseId]);
        if ($hit === null) { break; }
        $slug = $baseSlug . '-' . $i;
    }
    $status = (($_POST['status'] ?? '') === 'publish') ? 'publish' : 'draft';

    // 该语言版独立分类/标签：cat 单选 + tag 名称列表（已有术语复用，新名称则创建 tag 术语）
    $catId = (int)($_POST['poly_category'] ?? 0);
    $catRow = $catId > 0 ? Db::row('SELECT id FROM ' . Db::t('metas') . " WHERE id=? AND type='category'", [$catId]) : null;
    $cats = $catRow ? [(int)$catId] : [];
    $tagIds = [];
    $tagsRaw = trim((string)($_POST['poly_tags'] ?? ''));
    if ($tagsRaw !== '') {
        foreach (array_unique(array_filter(array_map('trim', explode(',', $tagsRaw)))) as $tName) {
            $row = Db::row('SELECT id FROM ' . Db::t('metas') . " WHERE type='tag' AND name=? LIMIT 1", [$tName]);
            $tid = $row ? (int)$row['id'] : Db::insert('metas', ['type' => 'tag', 'name' => $tName, 'slug' => Str::tagSlug($tName)]);
            $tagIds[] = (int)$tid;
        }
    }

    $d = [
        'group_id' => $baseId, 'lang' => $code,
        'title' => $title, 'slug' => $slug,
        'excerpt' => trim((string)($_POST['excerpt'] ?? '')),
        'keywords' => trim((string)($_POST['keywords'] ?? '')),
        'description' => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 300),
        'content' => (string)($_POST['content'] ?? ''),
        'status' => $status,
        'cat_ids' => json_encode($cats, JSON_UNESCAPED_UNICODE),
        'tag_ids' => json_encode($tagIds, JSON_UNESCAPED_UNICODE),
    ];
    $old = Poly::row($baseId, $code);
    if ($old !== null) {
        $d['manual_override'] = 1; // 编辑器保存视为人工修正
        $d['ai_generated'] = 0;
        unset($d['group_id'], $d['lang']);
        Db::update('poly_contents', $d, 'id=?', [(int)$old['id']]);
    } else {
        $d['ai_generated'] = 0;
        $d['manual_override'] = 1;
        $d['source_lang'] = Poly::defaultLang();
        Db::insert('poly_contents', $d);
    }
    Poly::clearStale($baseId, $code); // 人工保存=最新版，清除“过期”
    Cache::flush('pages');
    Cache::flush('blocks');
    $_SESSION['flash'] = $label . '版已保存' . ($status === 'publish' ? '并发布' : '（草稿）');
    header('Location: /admin/?s=' . $sec . '&action=edit&id=' . $baseId . '&lang=' . rawurlencode($code));
    exit;
}

/** AI 生成某语言草稿入口：POST do=poly-ai-en（id=中文内容 id，lang=语言短码；post 与独立页 page 通用） */
function pc_poly_ai(): void
{
    $baseId = (int)($_POST['id'] ?? 0);
    $code = trim((string)($_POST['lang'] ?? 'en'));
    $base = Db::row('SELECT * FROM ' . Db::t('contents') . ' WHERE id=?', [$baseId]);
    if ($base === null || !in_array(($base['type'] ?? ''), ['post', 'page'], true) || !in_array($code, Poly::enabled(), true) || $code === Poly::defaultLang()) {
        $_SESSION['flash'] = '参数错误';
        header('Location: /admin/?s=posts');
        exit;
    }
    $sec = ($base['type'] ?? 'post') === 'page' ? 'pages' : 'posts';
    $res = Poly::aiTranslate($baseId, $code, '', '', '', false, true); // 显式单篇 AI：允许重译并清除 manual_override
    if (!($res['ok'] ?? false)) {
        $_SESSION['flash'] = 'AI 翻译失败：' . ($res['error'] ?? '');
        header('Location: /admin/?s=' . $sec . '&action=edit&id=' . $baseId . '&lang=' . rawurlencode($code));
        exit;
    }
    // 默认带入中文原文的分类/标签（该语言版可再独立调整；page 通常无分类标签）
    $rel = Db::rows(
        "SELECT r.meta_id, m.type FROM " . Db::t('relationships') . " r JOIN " . Db::t('metas') . " m ON m.id=r.meta_id WHERE r.content_id=? AND m.type IN ('category','tag')",
        [$baseId]
    );
    Db::update('poly_contents', [
        'cat_ids' => json_encode(array_values(array_map('intval', array_column(array_filter($rel, fn($x) => $x['type'] === 'category'), 'meta_id'))), JSON_UNESCAPED_UNICODE),
        'tag_ids' => json_encode(array_values(array_map('intval', array_column(array_filter($rel, fn($x) => $x['type'] === 'tag'), 'meta_id'))), JSON_UNESCAPED_UNICODE),
    ], "group_id=? AND lang=?", [$baseId, $code]);
    Cache::flush('pages');
    Cache::flush('blocks');
    $_SESSION['flash'] = 'AI 已生成' . Poly::label($code) . '草稿（ai_generated=1），请审校后保存发布';
    header('Location: /admin/?s=' . $sec . '&action=edit&id=' . $baseId . '&lang=' . rawurlencode($code));
    exit;
}

/** 中文编辑页顶部的语言标签（Drupal 节点式入口；cur=当前编辑语言短码，默认语言为原文；post 与独立页 page 通用） */
function pc_lang_tabs(int $id, string $type, string $cur): string
{
    if (!in_array($type, ['post', 'page'], true) || $id <= 0 || !Poly::on()) { return ''; }
    $sec = $type === 'page' ? 'pages' : 'posts';
    $typeName = $type === 'page' ? '页面' : '文章';
    $def = Poly::defaultLang();
    $langs = array_values(array_unique(array_merge([$def], Poly::enabled())));
    $chips = [];
    foreach ($langs as $code) {
        $isDef = $code === $def;
        $href = '/admin/?s=' . $sec . '&action=edit&id=' . $id . ($isDef ? '' : '&lang=' . rawurlencode($code));
        $on = $cur === $code;
        $label = Poly::label($code) . ($isDef ? '（原文）' : '');
        if (!$isDef) {
            $row = Poly::row($id, $code);
            $staleDot = $row && !empty($row['stale']) ? ' · <span style="color:#f5d29a;">过期</span>' : '';
            $badge = $row === null
                ? '<span style="display:block;font-size:10.5px;opacity:.8;margin-top:2px;">未翻译</span>'
                : ($row['status'] === 'publish'
                    ? '<span style="display:block;font-size:10.5px;color:#cfe8d3;margin-top:2px;">✓ 已发布' . $staleDot . '</span>'
                    : '<span style="display:block;font-size:10.5px;color:#f0d9b8;margin-top:2px;">草稿 / 待发布</span>');
        } else {
            $badge = '';
        }
        $chips[] = '<a href="' . $href . '" style="display:inline-block;min-width:74px;text-align:center;padding:6px 12px;border-radius:8px;text-decoration:none;font-size:13.5px;border:1px solid #e6e6e2;'
            . ($on ? 'background:#b3541e;color:#fff;border-color:#b3541e;' : 'background:#fafaf7;color:#333;') . '">' . $label . $badge . '</a>';
    }
    return '<div class="card" style="padding:10px 16px;margin-bottom:14px;"><span style="color:#6f767e;font-size:12.5px;margin-right:8px;">翻译组（每语言独立版本）：</span>'
        . implode(' ', $chips)
        . '<div style="margin-top:8px;color:#6f767e;font-size:12px;">中文为基准原文；各语言版独立保存标题 / slug / 摘要 / 描述 / 关键词 / 正文（' . $typeName . '适用）。独立页（page）默认无分类标签；AI 全自动模式下本页仅用于手动修正。</div></div>';
}

/** 某语言版编辑器（在中文文章/页面编辑页内，?lang=<code>；post 与独立页 page 通用） */
function pc_render_editor_lang(int $id, string $code): string
{
    $base = Db::row('SELECT * FROM ' . Db::t('contents') . ' WHERE id=?', [$id]);
    if ($base === null || !in_array(($base['type'] ?? ''), ['post', 'page'], true) || !in_array($code, Poly::enabled(), true) || $code === Poly::defaultLang()) {
        $_SESSION['flash'] = '未找到该内容';
        header('Location: /admin/?s=posts');
        exit;
    }
    $isPage = ($base['type'] ?? '') === 'page';
    $sec = $isPage ? 'pages' : 'posts';
    $typeName = $isPage ? '页面' : '文章';
    $label = Poly::label($code);
    $row = Poly::row($id, $code);
    $f = [
        'title' => $row['title'] ?? '', 'slug' => $row['slug'] ?? (string)$base['slug'],
        'excerpt' => (string)($row['excerpt'] ?? ''), 'description' => (string)($row['description'] ?? ''),
        'keywords' => (string)($row['keywords'] ?? ''), 'content' => (string)($row['content'] ?? ''),
        'status' => $row['status'] ?? 'draft',
    ];
    $catIds = $row && ($row['cat_ids'] ?? '') !== '' ? array_values(array_filter(array_map('intval', (array)json_decode((string)$row['cat_ids'], true)))) : [];
    $tagIds = $row && ($row['tag_ids'] ?? '') !== '' ? array_values(array_filter(array_map('intval', (array)json_decode((string)$row['tag_ids'], true)))) : [];
    // 尚未建立该语言版分类/标签时，默认带入中文原文选择
    if (($row === null) || (!$catIds && !$tagIds)) {
        $rel = Db::rows(
            "SELECT r.meta_id, m.type FROM " . Db::t('relationships') . " r JOIN " . Db::t('metas') . " m ON m.id=r.meta_id WHERE r.content_id=? AND m.type IN ('category','tag')",
            [$id]
        );
        if (!$catIds) { $catIds = array_values(array_map('intval', array_column(array_filter($rel, fn($x) => $x['type'] === 'category'), 'meta_id'))); }
        if (!$tagIds) { $tagIds = array_values(array_map('intval', array_column(array_filter($rel, fn($x) => $x['type'] === 'tag'), 'meta_id'))); }
    }
    $selCat = $catIds[0] ?? 0;
    $cats = Db::rows('SELECT * FROM ' . Db::t('metas') . " WHERE type='category' ORDER BY `order` ASC, id ASC");
    $catOpts = '<option value="0">未分类</option>';
    foreach ($cats as $c) {
        $alt = Poly::termLang($c, $code);
        $nm = (string)$c['name'];
        $catOpts .= '<option value="' . (int)$c['id'] . '"' . ($c['id'] == $selCat ? ' selected' : '') . '>' . e($nm . ($alt !== '' && $alt !== $nm ? ' / ' . $alt : '')) . '</option>';
    }
    // 标签显示当前语言 id 集合对应名称（该语言名称优先）
    $tagNames = [];
    if ($tagIds) {
        $in = implode(',', array_fill(0, count($tagIds), '?'));
        foreach (Db::rows('SELECT * FROM ' . Db::t('metas') . " WHERE id IN ($in)", $tagIds) as $tg) {
            $n = Poly::termName($tg, $code);
            $tagNames[] = $n !== '' ? $n : (string)$tg['name'];
        }
    }
    $rowNote = $row ? '（' . ($row['ai_generated'] ? 'AI 生成' : '人工') . ($row['manual_override'] ? ' · 已人工修正' : '') . '）' : '';
    $modeNote = Poly::mode($code) === 'ai'
        ? '<div style="margin-top:8px;padding:8px 12px;border-radius:8px;background:#fbf3e9;border:1px solid #ecd9c2;color:#7a5b34;font-size:12.5px;">' . e($label) . '当前为 <b>AI 全自动</b>：本页仅用于<b>手动修正</b>。保存后将标记 manual_override，之后“AI 全量重译”不会覆盖本篇。</div>'
        : '';
    $staleWarn = ($row !== null && !empty($row['stale']))
        ? '<div style="margin-top:8px;padding:8px 12px;border-radius:8px;background:#fdf3e7;border:1px solid #e8cfa8;color:#8a5a20;font-size:12.5px;">⚠ 中文原文在本文档生成后又修改过，此译文<b>已过期</b>。可点下方「🤖 AI 生成/重译」整篇重译（人工锁定篇不会自动覆盖），或人工校对后保存（保存即标记为最新）。</div>'
        : '';
    $csrf = Csrf::field();
    $zh = $row === null ? '' : (string)$base['content'];
    $slugPath = '/' . $code . '/' . e($f['slug']) . '.html';
    return pc_lang_tabs($id, $isPage ? 'page' : 'post', $code)
        . $modeNote
        . $staleWarn
        . '<div class="card"><h3>' . e($label) . ' 翻译编辑 <small>独立字段，保存不覆盖中文原文' . ($rowNote !== '' ? ' ' . $rowNote : '') . '</small></h3>'
        . '<form method="post" action="/admin/?s=' . $sec . '">' . $csrf
        . '<input type="hidden" name="do" value="poly-en-save"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="lang" value="' . e($code) . '">'
        . '<input name="title" value="' . e($f['title']) . '" placeholder="' . ($code === 'en' ? 'English title' : e($label) . ' 标题') . '" required style="width:100%;box-sizing:border-box;font-size:15px;font-weight:700;padding:8px 10px;border:1px solid #e6e6e2;border-radius:6px;">'
        . '<div class="slug-row" style="margin:8px 0;color:#6f767e;">固定链接：/' . e($code) . '/<input name="slug" value="' . e($f['slug']) . '" style="width:200px;padding:4px 8px;border:1px solid #e6e6e2;border-radius:6px;">.html（留空自动）</div>'
        . '<div class="ed-grid"><div class="ed-left">'
        . '<label class="pf">正文（Markdown）</label><textarea name="content" rows="16" style="width:100%;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:8px 10px;font-family:Consolas,monospace;font-size:13px;">' . e($f['content']) . '</textarea>'
        . '<div class="hint">中文原文预览（只读参考）：</div><div class="ed-preview-box" style="max-height:320px;overflow:auto;background:#fafaf7;border:1px solid #e6e6e2;border-radius:6px;padding:10px;font-size:12.5px;color:#565b61;white-space:pre-wrap;">' . e($zh) . '</div>'
        . '</div><div class="ed-right">'
        . '<div class="side-field"><label>状态</label><select name="status"><option value="draft"' . ($f['status'] === 'draft' ? ' selected' : '') . '>草稿（/ ' . e($code) . ' 回落中文）</option><option value="publish"' . ($f['status'] === 'publish' ? ' selected' : '') . '>发布（/ ' . e($code) . ' 显示该语言版）</option></select></div>'
        . ($isPage ? ''
            : '<div class="side-field"><label>分类（该语言版独立）</label><select name="poly_category">' . $catOpts . '</select></div>'
            . '<div class="side-field"><label>标签（该语言版独立，逗号分隔；已有术语复用，新名称自动创建）</label><textarea name="poly_tags" rows="3" style="width:100%;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font:inherit;">' . e(implode(', ', $tagNames)) . '</textarea></div>')
        . '<div class="side-field"><label>简介 excerpt</label><textarea name="excerpt" rows="3" style="width:100%;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font:inherit;">' . e($f['excerpt']) . '</textarea></div>'
        . '<div class="side-field"><label>描述 description（meta）</label><input name="description" value="' . e($f['description']) . '" style="width:100%;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font:inherit;"></div>'
        . '<div class="side-field"><label>关键词 keywords（meta）</label><input name="keywords" value="' . e($f['keywords']) . '" style="width:100%;box-sizing:border-box;border:1px solid #e6e6e2;border-radius:6px;padding:6px 9px;font:inherit;"></div>'
        . '</div></div>'
        . '<div class="ed-actions" style="margin-top:12px;display:flex;gap:8px;align-items:center;">'
        . '<button class="btn" type="submit">保存' . e($label) . '版</button>'
        . '</form>'
        . '<form method="post" action="/admin/?s=' . $sec . '" style="display:inline;">' . $csrf
        . '<input type="hidden" name="do" value="poly-ai-en"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="lang" value="' . e($code) . '">'
        . '<button class="btn ghost" type="submit">🤖 AI 生成/重译（草稿，可多次）</button></form>'
        . '<a class="btn ghost" href="/admin/?s=' . $sec . '&action=edit&id=' . $id . '">返回中文编辑</a>'
        . '<a class="btn ghost" target="_blank" href="' . $slugPath . '">预览该语言前台 ↗</a>'
        . '</div>'
        . '<style>.pf{display:block;font-size:12.5px;color:#6f767e;margin:10px 0 2px}.pf+textarea,.pf+select,.side-field input{width:100%;box-sizing:border-box}.side-field{margin-bottom:10px}</style></div>';
}

/* ================= 编辑器视图 ================= */
function pc_render_editor(string $type, int $id = 0, string $lang = ''): string
{
    $typeName = $type === 'page' ? '页面' : '文章';
    $d = [
        'title' => '', 'slug' => '', 'excerpt' => '', 'description' => '', 'keywords' => '',
        'content' => '', 'status' => 'draft', 'published_at' => null,
        'allow_comment' => 1, 'featured' => 0, 'sticky' => 0, 'catId' => 0, 'tagNames' => [],
    ];
    if ($id > 0) {
        $r = Db::row('SELECT * FROM ' . Db::t('contents') . ' WHERE id=?', [$id]);
        if ($r === null || $r['type'] !== $type) {
            $_SESSION['flash'] = '未找到该内容';
            header('Location: /admin/?s=' . $type . 's');
            exit;
        }
        $d = [
            'title' => $r['title'], 'slug' => (string)$r['slug'], 'excerpt' => (string)$r['excerpt'],
            'description' => (string)$r['description'], 'keywords' => (string)$r['keywords'],
            'content' => (string)$r['content'], 'status' => $r['status'],
            'published_at' => $r['published_at'], 'allow_comment' => (int)$r['allow_comment'],
            'featured' => (int)$r['featured'], 'sticky' => (int)$r['order'],
            'catId' => 0, 'tagNames' => [],
        ];
        $rels = Db::rows(
            'SELECT m.id, m.type, m.name FROM ' . Db::t('relationships') . ' r JOIN ' . Db::t('metas')
            . ' m ON m.id=r.meta_id WHERE r.content_id=? AND m.type IN (\'category\',\'tag\')',
            [$id]
        );
        foreach ($rels as $rel) {
            if ($rel['type'] === 'category') { $d['catId'] = (int)$rel['id']; }
            else { $d['tagNames'][] = $rel['name']; }
        }
    }
    $cats = Db::rows('SELECT * FROM ' . Db::t('metas') . " WHERE type='category' ORDER BY `order` ASC, id ASC");
    $catOpts = '';
    foreach ($cats as $c) {
        $selC = $c['id'] == $d['catId'] ? ' selected' : '';
        $catOpts .= '<option value="' . (int)$c['id'] . '"' . $selC . '>' . e($c['name']) . '</option>';
    }
    $checked = fn(bool $v) => $v ? ' checked' : '';
    $isPage = $type === 'page';
    $slugVal = e($d['slug']);
    $excerpt = e($d['excerpt']);
    $desc = e($d['description']);
    $kw = e($d['keywords']);
    $tagsJson = json_encode(array_values($d['tagNames']), JSON_UNESCAPED_UNICODE);
    $csrf = Csrf::token();
    $titleVal = e($d['title']);
    $listUrl = '/admin/?s=' . $type . 's';
    $formAction = '/admin/?s=' . $type . 's';
    $csrfField = Csrf::field();
    $mdBody = e($d['content']);
    $optDraft = $d['status'] === 'draft' ? ' selected' : '';
    $optPub = $d['status'] === 'publish' ? ' selected' : '';
    $optSch = $d['status'] === 'scheduled' ? ' selected' : '';
    $optHide = $d['status'] === 'hidden' ? ' selected' : '';
    $ckSticky = $d['sticky'] ? ' checked' : '';
    $ckFeat = $d['featured'] ? ' checked' : '';
    $ckComm = $d['allow_comment'] ? ' checked' : '';
    $schedVal = $d['published_at'] ? date('Y-m-d\TH:i', strtotime((string)$d['published_at'])) : '';
    $langTabsHtml = $id > 0 ? pc_lang_tabs($id, $type, Poly::defaultLang()) : '';

    $__html = <<<HTML
$langTabsHtml
<form id="postForm" method="post" action="{$formAction}">
<input type="hidden" name="do" value="post-save">
<input type="hidden" name="type" value="{$type}">
<input type="hidden" name="id" value="{$id}">
<input type="hidden" name="content" id="contentValue">
$csrfField
<div class="ed-grid">
  <div class="ed-left">
    <input name="title" id="edTitle" value="{$titleVal}" placeholder="标题" class="ed-title" required>
    <div class="slug-row"><span>固定链接：/</span><input name="slug" id="edSlug" value="{$slugVal}" placeholder="自动生成"><span>.html</span>
      <span class="hint" id="slugLive"></span></div>

    <div class="ed-toolbar" id="toolbar">
      <button type="button" data-cmd="h2">H2</button><button type="button" data-cmd="h3">H3</button><span class="sep"></span>
      <button type="button" data-cmd="bold"><b>B</b></button><button type="button" data-cmd="italic"><i>I</i></button>
      <button type="button" data-cmd="del"><s>S</s></button><button type="button" data-cmd="code">`code`</button>
      <button type="button" data-cmd="quote">❝引用</button><button type="button" data-cmd="ul">•列表</button>
      <button type="button" data-cmd="ol">1.列表</button><button type="button" data-cmd="link">🔗链接</button>
      <button type="button" data-cmd="img">🖼图片</button><button type="button" data-cmd="table">▦表格</button>
      <button type="button" data-cmd="hr">—分割线</button>
      <span class="sep"></span>
      <span class="ed-mode" id="edMode"><button type="button" class="active" data-mode="md">Markdown</button><button type="button" data-mode="rich">富文本</button></span>
      <button type="button" id="btnSplit" class="split">分屏预览</button>
    </div>
    <div class="ed-area split" id="edArea">
      <textarea id="mdIn" class="ed-textarea" spellcheck="false" placeholder="用 Markdown 写作，或切到富文本模式所见即所得…">__MD_BODY__</textarea>
      <div id="richIn" class="ed-rich" contenteditable="true" style="display:none;"></div>
      <div id="edPrev" class="ed-preview"></div>
    </div>
    <div class="hint" id="charCount"></div>
  </div>

  <div class="ed-right">
    <div class="side-field"><label>状态</label>
      <select name="status" id="statusSel">
        <option value="draft"$optDraft>草稿</option>
        <option value="publish"$optPub>立即发布</option>
        <option value="scheduled"$optSch>定时发布</option>
        <option value="hidden"$optHide>下线（前台隐藏）</option>
      </select></div>
    <div class="side-field" id="schedRow" style="display:none;"><label>定时时间</label>
      <input type="datetime-local" name="scheduled_at" id="schedAt" value="$schedVal"></div>
    <div class="side-field"><label>选项</label>
      <label class="chk"><input type="checkbox" name="sticky"$ckSticky> 置顶</label>
      <label class="chk"><input type="checkbox" name="featured"$ckFeat> 精选</label>
      <label class="chk"><input type="checkbox" name="allow_comment"$ckComm> 允许评论</label></div>
HTML
    . ($isPage ? '' : '<div class="side-field"><label>分类</label><select name="category"><option value="0">未分类</option>' . $catOpts . '</select></div>')
    . ($isPage ? '' : '<div class="side-field"><label>标签（回车或逗号分隔，可新建）</label><input id="tagInput" placeholder="输入后回车">
        <div class="chips" id="tagChips"></div><input type="hidden" name="tags" id="tagsHidden"></div>')
    . '<div class="side-field"><label>简介 excerpt</label><textarea name="excerpt" rows="3">' . $excerpt . '</textarea></div>'
    . '<div class="side-field"><label>描述 description（meta）</label><input name="description" value="' . $desc . '"></div>'
    . '<div class="side-field"><label>关键词 keywords（meta）</label><input name="keywords" value="' . $kw . '"></div>'
    . '<div class="side-field"><label>上传图片 / 附件</label><button type="button" class="btn ghost" id="btnUpload">选择文件上传</button>
        <input type="file" id="upFile" style="display:none;"><div class="hint" id="upMsg"></div></div>'
    . '</div></div>'
    . '<div class="ed-actions"><button type="submit" class="btn">保存</button> <a class="btn ghost" href="' . $listUrl . '">返回列表</a>'
    . ($id > 0 && $d['status'] === 'publish' ? ' <a class="btn ghost" target="_blank" href="/' . e($d['slug']) . '.html">预览前台 ↗</a>' : '')
    . '</div></form>'
    . '<script>window.__YC = {csrf:' . json_encode($csrf) . ', tags:' . $tagsJson . ', page:' . ($isPage ? 'true' : 'false') . '};</script>'
    . '<link rel="stylesheet" href="/admin/assets/editor.css?v=' . @filemtime(__DIR__ . '/assets/editor.css') . '"><script src="/admin/assets/editor.js?v=' . @filemtime(__DIR__ . '/assets/editor.js') . '"></script>';
    return str_replace('__MD_BODY__', $mdBody, $__html);
}

/** HTML 转义复用的 select selected helper */
if (!function_exists('adm_sel')) {
    function adm_sel(string $needle, string $value): string
    {
        return $needle === $value ? ' selected' : '';
    }
}
