<?php
declare(strict_types=1);

/**
 * Front：前台页面渲染器（E0：首页列表、文章页、meta 列表、归档、搜索、404）
 * 数据加载 + 模板渲染；页面缓存由 index.php 处理。
 */
final class Front
{
    public const PER_PAGE = 10;

    /** 每页条数：主题设置可覆盖 */
    private static function perPage(): int
    {
        $n = (int)theme_opt('per_page', self::PER_PAGE);
        return max(5, min(50, $n));
    }


    /** 已发布文章基础查询段 */
    private static function postWhere(): string
    {
        return "type='post' AND status='publish' AND deleted_at IS NULL";
    }

    private static function publishAt(): string
    {
        // 前台所有用法均限定 status='publish'（published_at 恒非空）：直用列名以走 idx_contents_pub 索引，
        // 避免 COALESCE 表达式 filesort。草稿/定时等含 NULL 的查询不走本函数（后台列表自带 COALESCE）。
        return 'published_at';
    }

    /** 请求内 memo：type → 窄列术语列表（id,type,slug,slug_i18n），供语言别名解析复用 */
    private static array $metaBySlugIdx = [];

    /** 按语言解析分类/标签：先精确 slug；非默认语再匹配 slug_i18n[lang]（窄列索引 + 命中后单行回表） */
    private static function metaBySlug(string $type, string $slug, ?string $lang = null): ?array
    {
        $lang = $lang ?? Lang::short();
        $row = Db::row('SELECT * FROM ' . Db::t('metas') . ' WHERE type=? AND slug=? LIMIT 1', [$type, $slug]);
        if ($row !== null || $lang === Poly::defaultLang()) { return $row; }
        if (!isset(self::$metaBySlugIdx[$type])) {
            // D9：只取解析所需窄列（不再每请求全量 SELECT * 逐行带大字段），整请求复用一次
            self::$metaBySlugIdx[$type] = Db::rows(
                'SELECT id, type, slug, slug_i18n FROM ' . Db::t('metas') . ' WHERE type=?',
                [$type]
            );
        }
        foreach (self::$metaBySlugIdx[$type] as $m) {
            if (Poly::termSlug($m, $lang) === $slug) {
                return Db::row('SELECT * FROM ' . Db::t('metas') . ' WHERE id=? LIMIT 1', [(int)$m['id']]);
            }
        }
        return null;
    }

    /** 文章行 + 分类/标签 + 评论数（评论数后续改为冗余列） */
    private static function attach(array $rows): array
    {
        if (!$rows) { return $rows; }
        $ids = array_column($rows, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rels = Db::rows(
            'SELECT r.content_id, m.type, m.name, m.name_en, m.names_i18n, m.slug_i18n, m.slug FROM ' . Db::t('relationships') . ' r '
            . 'JOIN ' . Db::t('metas') . " m ON m.id=r.meta_id WHERE r.content_id IN ($in) AND m.type IN ('category','tag')",
            $ids
        );
        $byContent = [];
        foreach ($rels as $r) { $byContent[$r['content_id']][] = $r; }
        foreach ($rows as &$row) {
            $row['cats'] = array_values(array_filter($byContent[$row['id']] ?? [], fn($m) => $m['type'] === 'category'));
            $row['tags'] = array_values(array_filter($byContent[$row['id']] ?? [], fn($m) => $m['type'] === 'tag'));
            // 摘要/meta 只保留纯文本（防止 Markdown 语法漏到前台）
            if (isset($row['excerpt']) && $row['excerpt'] !== '') { $row['excerpt'] = Str::cleanMd((string)$row['excerpt']); }
            if (isset($row['description']) && $row['description'] !== '') { $row['description'] = Str::cleanMd((string)$row['description']); }
        }
        return $rows;
    }

    public static function home(int $page): array
    {
        $limit = self::perPage();
        $offset = ($page - 1) * $limit;
        $rows = Db::rows(
            'SELECT * FROM ' . Db::t('contents') . ' WHERE ' . self::postWhere()
            . ' ORDER BY ' . self::publishAt() . ' DESC, id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset
        );
        $total = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . ' WHERE ' . self::postWhere());
        return [
            'title' => site_label() . ' · ' . site_tagline(),
            'page_title' => __('tt.latest'),
            'posts' => self::attach($rows),
            'page' => $page,
            'total_pages' => max(1, (int)ceil($total / $limit)),
            'list_title' => site_tagline(),
        ];
    }

    public static function post(string $slug, string $lang = 'zh', int $cpage = 1): ?array
    {
        $default = Poly::defaultLang();
        $lang = $lang !== '' ? $lang : $default;
        // 其它语言：先找已发布译文；找不到回落默认语言原稿
        $translated = false;
        $fallback = false;
        if ($lang !== $default) {
            $poly = Poly::findPublished($slug, $lang);
            if ($poly !== null) {
                $row = Db::row('SELECT * FROM ' . Db::t('contents') . ' WHERE id=?', [(int)$poly['group_id']]);
                if ($row !== null) {
                    $row['title'] = $poly['title'];
                    $row['content'] = $poly['content'];
                    $row['excerpt'] = $poly['excerpt'];
                    $row['keywords'] = $poly['keywords'];
                    $row['description'] = (string)($poly['description'] ?? '');
                    $row['slug'] = $poly['slug'];
                    $row['stale'] = (int)($poly['stale'] ?? 0);
                    $translated = true;
                }
            } else {
                $fallback = true;
            }
        }
        if (!isset($row) || $row === null) {
            $row = Db::row(
                'SELECT * FROM ' . Db::t('contents') . " WHERE slug=? AND status='publish' AND deleted_at IS NULL LIMIT 1",
                [$slug]
            );
        }
        if ($row === null) { return null; }
        $data = self::attach([$row])[0];
        $cid = (int)$row['id'];
        // 译文版独立分类/标签：使用 poly 行 cat_ids/tag_ids（术语取当前语言 names_i18n；无该语言名则不显示）
        $curLang = Lang::short();
        if (Poly::on() && $curLang !== Poly::defaultLang() && !$fallback) {
            $polyRow = Poly::row($cid, $curLang);
            $rawCat = $polyRow['cat_ids'] ?? null;
            $rawTag = $polyRow['tag_ids'] ?? null;
            if ($rawCat !== null || $rawTag !== null) {
                $ids = fn($raw) => array_values(array_filter(array_map('intval', $raw !== null && $raw !== '' ? (array)json_decode((string)$raw, true) : [])));
                $take = fn(string $type, array $idList) => $idList ? Db::rows(
                    'SELECT id, type, name, name_en, names_i18n, slug_i18n, slug FROM ' . Db::t('metas') . " WHERE type=? AND id IN (" . implode(',', array_fill(0, count($idList), '?')) . ')',
                    array_merge([$type], $idList)
                ) : [];
                $mk = function (array $mm) use ($curLang): ?array {
                    $n = Poly::termLang($mm, $curLang);
                    if ($n === '') { return null; } // 术语未提供当前语言名时不显示（避免混入其它语言）
                    return ['id' => (int)$mm['id'], 'type' => $mm['type'], 'name' => $n, 'slug' => (string)$mm['slug'], 'slug_i18n' => (string)($mm['slug_i18n'] ?? ''), 'count' => 0];
                };
                $fil = fn(array $rows): array => array_values(array_filter(array_map($mk, $rows), fn($v) => $v !== null));
                $data['cats'] = $fil($take('category', $ids($rawCat)));
                $data['tags'] = $fil($take('tag', $ids($rawTag)));
            }
        }
        $html = Markdown::render((string)$row['content']);
        // 服务端目录：给 h2~h5 加锚点 id 并收集目录项（右栏顶部展示，无需 JS）
        $tocItems = [];
        $html = preg_replace_callback('/<h([2345])([^>]*)>(.*?)<\/h\1>/is', function ($m) use (&$tocItems): string {
            $lvl = (int)$m[1]; $attrs = (string)$m[2]; $inner = (string)$m[3];
            $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text = trim((string)preg_replace('/\s+/u', ' ', $text));
            if ($text === '') { return $m[0]; }
            if (preg_match('/\bid=["\']([^"\']+)["\']/', $attrs, $mm)) {
                $id = $mm[1];
            } else {
                $id = 'sec-' . count($tocItems);
                $attrs .= ' id="' . $id . '"';
            }
            $tocItems[] = ['l' => $lvl, 'id' => $id, 't' => $text];
            return '<h' . $lvl . $attrs . '>' . $inner . '</h' . $lvl . '>';
        }, $html);
        // 评论：顶层分页（?cpage=），每页 20；只取当前页根评论及其后代（D4：不再每篇拉 2000 全量）
        $segments = Db::rows(
            "SELECT * FROM " . Db::t('comments') . " WHERE content_id=? AND status='approved' AND kind='segment' ORDER BY id ASC LIMIT 500",
            [$cid]
        );
        $normalTotal = (int)Db::one(
            "SELECT COUNT(*) FROM " . Db::t('comments') . " WHERE content_id=? AND status='approved' AND kind='normal'",
            [$cid]
        );
        $per = 20;
        $pages = max(1, (int)ceil($normalTotal / $per));
        $cpage = max(1, min($pages, $cpage));
        // 根评论分页：先按根计数/取当前页根，再迭代收后代
        $rootTotal = (int)Db::one(
            "SELECT COUNT(*) FROM " . Db::t('comments') . " WHERE content_id=? AND status='approved' AND kind='normal' AND parent_id IS NULL",
            [$cid]
        );
        $pages = max(1, (int)ceil($rootTotal / $per));
        $cpage = max(1, min($pages, $cpage));
        $rootIds = array_map('intval', array_column(Db::rows(
            "SELECT id FROM " . Db::t('comments') . " WHERE content_id=? AND status='approved' AND kind='normal' AND parent_id IS NULL "
            . 'ORDER BY id ASC LIMIT ' . (int)$per . ' OFFSET ' . (int)(($cpage - 1) * $per),
            [$cid]
        ), 'id'));
        $allowed = $rootIds;
        // 迭代收当前页根的后代（多级，最多 500 个/防极端树）
        for ($guard = 0; $guard < 20 && $allowed; $guard++) {
            $in = implode(',', array_fill(0, count($allowed), '?'));
            $children = array_map('intval', array_column(Db::rows(
                "SELECT id FROM " . Db::t('comments') . " WHERE content_id=? AND status='approved' AND kind='normal' AND parent_id IN ($in) "
                . 'AND id NOT IN (' . $in . ') ORDER BY id ASC LIMIT 500',
                array_merge([$cid], $allowed, $allowed)
            ), 'id'));
            $new = array_values(array_diff($children, $allowed));
            if (!$new) { break; }
            $allowed = array_merge($allowed, $new);
            $allowed = array_values(array_unique($allowed));
        }
        $comments = [];
        if ($allowed) {
            $in = implode(',', array_fill(0, count($allowed), '?'));
            $comments = Db::rows(
                'SELECT * FROM ' . Db::t('comments') . " WHERE id IN ($in) ORDER BY id ASC",
                $allowed
            );
        }
        $out = [
            'post'  => $data,
            'html'  => $html,
            'title' => $row['title'] . ' · ' . site_label(),
            'comment_total' => $normalTotal + count($segments),
            'comments' => $comments,
            'segments' => $segments,
            'para_n' => substr_count($html, '<p>') + substr_count($html, '<ol>') + substr_count($html, '<ul>'),
            'fav' => Auth::check()
                ? (Db::one('SELECT id FROM ' . Db::t('favorites') . ' WHERE user_id=? AND content_id=? LIMIT 1', [Auth::id(), $cid]) !== null)
                : false,
            'my_marks' => Auth::check()
                ? (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('marks') . ' WHERE user_id=? AND content_id=?', [Auth::id(), $cid])
                : 0,
            'lang' => $lang,
            'translated' => $translated,
            'fallback' => $fallback,
            'toc' => $tocItems,
            'comment_page' => $cpage,
            'comment_pages' => $pages,
            'prev' => null,
            'next' => null,
        ];
        if (($row['type'] ?? 'post') === 'post') {
            // 上一篇/下一篇按发布时间（含同分秒时以 id 决序）相邻，与首页/列表顺序一致：
            // 上一篇 = 时间更新（列表上方），下一篇 = 时间更旧（列表下方）。
            $pub = (string)($row['published_at'] ?? $row['created_at']);
            $T = Db::t('contents');
            $prevRow = Db::row(
                "SELECT id, title, slug FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL "
                . 'AND (published_at > ? OR (published_at = ? AND id > ?)) ORDER BY published_at ASC, id ASC LIMIT 1',
                [$pub, $pub, $cid]
            );
            $nextRow = Db::row(
                "SELECT id, title, slug FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL "
                . 'AND (published_at < ? OR (published_at = ? AND id < ?)) ORDER BY published_at DESC, id DESC LIMIT 1',
                [$pub, $pub, $cid]
            );
            // 译文界面：相邻文章指向该语言译文（标题/链接均为当前语言，避免混入其它语言）
            $curLang = Lang::short();
            $neighbor = function (?array $r) use ($curLang): ?array {
                if ($r === null || !(Poly::on() && $curLang !== Poly::defaultLang())) { return $r; }
                $en = Poly::row((int)$r['id'], $curLang);
                if ($en !== null && ($en['status'] ?? '') === 'publish') {
                    return ['id' => (int)$r['id'], 'title' => $en['title'], 'slug' => $en['slug']];
                }
                return $r;
            };
            $out['prev'] = $neighbor($prevRow);
            $out['next'] = $neighbor($nextRow);
        }
        return $out;
    }

    /** /{lang}/ 首页列表（该语言已发布译文；支持分页；无则空） */
    public static function polyHome(string $lang, int $page = 1): array
    {
        $limit = self::perPage();
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;
        $total = (int)Db::one(
            'SELECT COUNT(*) FROM ' . Poly::table() . " p JOIN " . Db::t('contents') . ' c ON c.id=p.group_id '
            . "WHERE p.lang=? AND p.status='publish' AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL",
            [$lang]
        );
        $rows = Db::rows(
            'SELECT p.title, p.slug, p.excerpt, p.content, p.updated_at, c.views, c.published_at '
            . 'FROM ' . Poly::table() . " p JOIN " . Db::t('contents') . ' c ON c.id=p.group_id '
            . "WHERE p.lang=? AND p.status='publish' AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL "
            . 'ORDER BY c.published_at DESC, p.id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
            [$lang]
        );
        $posts = [];
        foreach ($rows as $r) {
            $posts[] = [
                'id' => 0, 'title' => $r['title'], 'slug' => $r['slug'],
                'excerpt' => $r['excerpt'], 'content' => (string)$r['content'],
                'views' => $r['views'],
                'published_at' => $r['published_at'], 'created_at' => $r['updated_at'],
                'cats' => [], 'tags' => [],
            ];
        }
        return [
            'title' => site_label() . ' · ' . Poly::label($lang),
            'page_title' => __('tt.latest'),
            'sub' => '',
            'posts' => $posts,
            'page' => $page,
            'total_pages' => max(1, (int)ceil($total / $limit)),
            'pager_base' => '/' . $lang . '/page/{n}',
        ];
    }

    public static function metaList(string $type, string $slug, int $page): ?array
    {
        if (Poly::on() && Lang::short() !== Poly::defaultLang()) { return self::enMetaList($type, $slug, $page); }
        $meta = self::metaBySlug($type, $slug, Lang::short());
        if ($meta === null) { return null; }
        $limit = self::perPage();
        $offset = ($page - 1) * $limit;
        // 栏目内页顺序：站点可设 xuecha.list_asc=1 → 最老在上（ASC）；默认最新在前（DESC），不设不影响其它站
        $asc = $type === 'category' && (string)get_option('xuecha.list_asc', '0') === '1';
        $dir  = $asc ? 'ASC' : 'DESC';
        $dirId = $asc ? 'ASC' : 'DESC';
        // D7：一次 JOIN 分页查询（去掉“先 LIMIT 1000 取 id”造成的深翻页截断）；
        // STRAIGHT_JOIN 强制从 relationships(meta_id 索引) 驱动，避免优化器在大表下先全扫 contents
        $rows = Db::rows(
            'SELECT c.* FROM ' . Db::t('relationships') . ' r STRAIGHT_JOIN ' . Db::t('contents') . ' c ON c.id=r.content_id '
            . 'WHERE r.meta_id=? AND ' . self::postWhere()
            . ' ORDER BY ' . self::publishAt() . ' ' . $dir . ', c.id ' . $dirId . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
            [$meta['id']]
        );
        $total = (int)Db::one(
            'SELECT COUNT(*) FROM ' . Db::t('relationships') . ' r JOIN ' . Db::t('contents') . " c ON c.id=r.content_id WHERE r.meta_id=? AND " . self::postWhere(),
            [$meta['id']]
        );
        return [
            'title' => $meta['name'] . ' · ' . site_label(),
            'page_title' => sprintf($type === 'category' ? __('tt.category') : __('tt.tag'), $meta['name']),
            'name' => $meta['name'],
            'total_items' => $total,
            'posts' => self::attach($rows),
            'page' => $page,
            'total_pages' => max(1, (int)ceil($total / $limit)),
        ];
    }

    public static function archivesOverview(): array
    {
        if (Poly::on() && Lang::short() !== Poly::defaultLang()) { return self::enArchivesOverview(); }
        $rows = Db::rows(
            'SELECT DATE_FORMAT(' . self::publishAt() . ', "%Y") y, DATE_FORMAT(' . self::publishAt() . ', "%m") m, COUNT(*) n '
            . 'FROM ' . Db::t('contents') . ' WHERE ' . self::postWhere() . ' GROUP BY y, m ORDER BY y DESC, m DESC'
        );
        return ['title' => sprintf(__('tt.arch_of'), site_label()), 'page_title' => __('tt.arch'), 'months' => $rows];
    }

    public static function archivesList(int $y, int $m): array
    {
        if (Poly::on() && Lang::short() !== Poly::defaultLang()) { return self::enArchivesList($y, $m); }
        $sql = 'SELECT * FROM ' . Db::t('contents') . ' WHERE ' . self::postWhere()
            . ' AND YEAR(' . self::publishAt() . ')=?';
        $params = [$y];
        if ($m > 0) { $sql .= ' AND MONTH(' . self::publishAt() . ')=?'; $params[] = $m; }
        $rows = Db::rows($sql . ' ORDER BY ' . self::publishAt() . ' DESC, id DESC LIMIT 200', $params);
        $title = $m > 0 ? lang_month($y, $m) : (Lang::isEn() ? (string)$y : (string)$y . '年');
        return ['title' => $title . ' · ' . site_label(), 'page_title' => sprintf(__('tt.archive'), $title), 'posts' => self::attach($rows), 'page' => 1, 'total_pages' => 1];
    }

    public static function search(string $q): array
    {
        if (Poly::on() && Lang::short() !== Poly::defaultLang()) { return self::enSearch($q); }
        $rows = [];
        if ($q !== '') {
            $terms = Str::terms($q);
            if ($terms) {
                // D8：优先 ngram FULLTEXT（boolean 模式，逐词 + 必现）；命中不足/单字异常时回退 LIKE。
                $ids = self::ftIds($terms);
                if (!$ids) { $ids = self::likeIds($terms); }
                if ($ids) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $rows = Db::rows(
                        'SELECT * FROM ' . Db::t('contents') . ' WHERE id IN (' . $in . ') AND ' . self::postWhere(),
                        $ids
                    );
                    // 相关度排序（标题命中优先）
                    usort($rows, function (array $a, array $b) use ($q): int {
                        return self::rank($b, $q) <=> self::rank($a, $q);
                    });
                    $rows = array_slice($rows, 0, 40);
                }
            }
        }
        return [
            'title' => sprintf(__('tt.search_title'), $q, site_label()),
            'page_title' => sprintf(__('tt.search_pre'), $q),
            'q' => $q,
            'posts' => self::attach($rows),
            'page' => 1, 'total_pages' => 1,
        ];
    }

    /** FULLTEXT id 集：ngram boolean（词间默认 OR，与原 LIKE 任一命中一致）；异常/空返回 []（调用方走 LIKE 兜底） */
    private static function ftIds(array $terms): array
    {
        if (!$terms) { return []; }
        $bool = implode(' ', $terms);
        try {
            return array_map('intval', array_column(Db::rows(
                'SELECT id FROM ' . Db::t('contents') . ' WHERE ' . self::postWhere()
                . ' AND MATCH(title, content) AGAINST (? IN BOOLEAN MODE) LIMIT 300',
                [$bool]
            ), 'id'));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** LIKE 兜底 id 集（只取主键/标题做预筛，正文按 top40 回表，避免先拉 300 行 LONGTEXT） */
    private static function likeIds(array $terms): array
    {
        $where = [];
        $params = [];
        foreach ($terms as $t) {
            $where[] = '(title LIKE ? OR content LIKE ?)';
            $like = '%' . $t . '%';
            $params[] = $like; $params[] = $like;
        }
        return array_map('intval', array_column(Db::rows(
            'SELECT id FROM ' . Db::t('contents') . ' WHERE ' . self::postWhere()
            . ' AND (' . implode(' OR ', $where) . ') ORDER BY ' . self::publishAt() . ' DESC, id DESC LIMIT 300',
            $params
        ), 'id'));
    }

    /** 简单相关度分：标题完全/前缀/包含 / 正文命中靠后 */
    private static function rank(array $r, string $q): int
    {
        $title = (string)$r['title'];
        $content = (string)$r['content'];
        $score = 0;
        foreach (Str::terms($q) as $t) {
            if (mb_stripos($title, $t) !== false) {
                $score += 40;
                if (mb_stripos($title, $t) === 0) { $score += 30; }
                if (mb_strtolower($title) === mb_strtolower($t)) { $score += 40; }
            }
            if (mb_stripos($content, $t) !== false) { $score += 5; }
        }
        return $score;
    }

    /** 侧栏公共数据（区块缓存：短 TTL） */
    public static function sidebar(): array
    {
        $key = 'sidebar:v1';
        $cached = Cache::get('blocks', $key);
        if ($cached !== false) {
            return json_decode($cached, true);
        }
        $latest = Db::rows('SELECT id, title, slug, ' . self::publishAt() . ' AS dt FROM ' . Db::t('contents') . ' WHERE ' . self::postWhere() . ' ORDER BY ' . self::publishAt() . ' DESC LIMIT ' . (int)(int)theme_opt('latest_n', 8));
        $cats = Db::rows('SELECT * FROM ' . Db::t('metas') . " WHERE type='category' ORDER BY `order` ASC, id ASC LIMIT 50");
        $tags = Db::rows('SELECT * FROM ' . Db::t('metas') . " WHERE type='tag' AND count>0 ORDER BY count DESC LIMIT 30");
        $months = Db::rows('SELECT DATE_FORMAT(' . self::publishAt() . ', "%Y") y, DATE_FORMAT(' . self::publishAt() . ', "%m") m, COUNT(*) n FROM ' . Db::t('contents') . ' WHERE ' . self::postWhere() . ' GROUP BY y, m ORDER BY y DESC, m DESC LIMIT 12');
        $data = ['latest' => $latest, 'cats' => $cats, 'tags' => $tags, 'months' => $months];
        Cache::set('blocks', $key, json_encode($data, JSON_UNESCAPED_UNICODE), 300);
        return $data;
    }

    /** /tag/ /category/ 索引：全部含计数，便于导航与 SEO */
    public static function metaIndex(string $type): array
    {
        if (Poly::on() && Lang::short() !== Poly::defaultLang()) { return self::enMetaIndex($type); }
        $rows = Db::rows(
            'SELECT * FROM ' . Db::t('metas') . ' WHERE type=? ORDER BY `count` DESC, id ASC LIMIT 500',
            [$type]
        );
        $label = $type === 'category' ? __('tt.mi_category') : __('tt.mi_tag');
        $total = count($rows);
        return [
            'type' => $type,
            'items' => $rows,
            'page_title' => $label,
            'sub' => sprintf($type === 'category' ? __('tt.mi_sub_cat') : __('tt.mi_sub_tag'), $total),
            'hint' => $type === 'category' ? __('tt.mi_hint_category') : __('tt.mi_hint_tag'),
            'title' => $label . ' · ' . site_label(),
        ];
    }

    /** /pages：全部已发布独立页面（导航 & SEO） */
    public static function pagesList(): array
    {
        $rows = Db::rows(
            "SELECT id, title, slug, excerpt, description, updated_at FROM " . Db::t('contents')
            . " WHERE type='page' AND status='publish' AND deleted_at IS NULL ORDER BY `order` ASC, id ASC LIMIT 200"
        );
        return [
            'pages' => $rows,
            'page_title' => __('tt.pages'),
            'sub' => sprintf(__('tt.pages_sub'), count($rows)),
            'title' => __('tt.pages') . ' · ' . site_label(),
        ];
    }

    /* =================================================================
     * 英文内容页域（多语插件启用时）：分类/标签索引与列表、归档、站内搜索
     * 都以「英文译文集合」（ye_poly_contents lang=en & publish）为数据源，
     * 术语显示英文名（name_en，空时回落中文名——建议术语都补英文名）。
     * ================================================================= */
    private static function polyCol(string $type): string
    {
        return $type === 'category' ? 'cat_ids' : 'tag_ids';
    }

    /** 术语 JSON 数组列（cat_ids/tag_ids）是否含某 meta id：JSON_CONTAINS 精确匹配（首/末元素不再失配） */
    private static function polyJsonMatches(string $col, string $metaIdExpr): string
    {
        return "({$col} IS NOT NULL AND {$col}<>'' AND JSON_CONTAINS(IF(JSON_VALID({$col}), {$col}, '[]'), CAST(" . $metaIdExpr . " AS JSON)))";
    }

    public static function enMetaIndex(string $type): array
    {
        $lang = Lang::short();
        $col = self::polyCol($type);
        $T = Db::t('contents');
        $P = Poly::table();
        $rows = Db::rows(
            "SELECT m.*, (SELECT COUNT(*) FROM {$P} p JOIN {$T} c ON c.id=p.group_id "
            . "WHERE p.lang='{$lang}' AND p.status='publish' AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL "
            . 'AND ' . self::polyJsonMatches('p.' . $col, 'm.id') . ') cnt ' . PHP_EOL
            . "FROM " . Db::t('metas') . " m WHERE m.type=? HAVING cnt>0 ORDER BY cnt DESC, m.id ASC LIMIT 300",
            [$type]
        );
        foreach ($rows as &$m) {
            $m['name'] = Poly::termName($m, $lang); // 当前语言名优先，空则回落中文名
        }
        unset($m);
        $label = $type === 'category' ? __('tt.mi_category') : __('tt.mi_tag');
        $total = count($rows);
        return [
            'type' => $type,
            'items' => $rows,
            'page_title' => $label,
            'sub' => sprintf($type === 'category' ? __('tt.mi_sub_cat') : __('tt.mi_sub_tag'), $total),
            'hint' => $type === 'category' ? __('tt.mi_hint_category') : __('tt.mi_hint_tag'),
            'title' => $label . ' · ' . site_label(),
        ];
    }

    public static function enMetaList(string $type, string $slug, int $page): ?array
    {
        $lang = Lang::short();
        $meta = self::metaBySlug($type, $slug, Lang::short());
        if ($meta === null) { return null; }
        $col = self::polyCol($type);
        $T = Db::t('contents');
        $P = Poly::table();
        $per = self::perPage();
        $offset = ($page - 1) * $per;
        $match = self::polyJsonMatches('p.' . $col, (string)(int)$meta['id']);
        $total = (int)Db::one(
            "SELECT COUNT(*) FROM {$P} p JOIN {$T} c ON c.id=p.group_id WHERE p.lang='{$lang}' AND p.status='publish' "
            . "AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL AND {$match}",
        );
        $raw = Db::rows(
            "SELECT p.slug, p.title, p.excerpt, p.content, c.views, c.published_at FROM {$P} p JOIN {$T} c ON c.id=p.group_id "
            . "WHERE p.lang='{$lang}' AND p.status='publish' AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL AND {$match} "
            . 'ORDER BY c.published_at DESC, p.id DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)$offset,
        );
        $posts = [];
        foreach ($raw as $r) {
            $posts[] = [
                'id' => 0, 'title' => $r['title'], 'slug' => $r['slug'],
                'excerpt' => (string)($r['excerpt'] ?? ''), 'content' => (string)($r['content'] ?? ''),
                'views' => $r['views'], 'published_at' => $r['published_at'], 'created_at' => $r['published_at'],
                'cats' => [], 'tags' => [],
            ];
        }
        $name = Poly::termName($meta, $lang);
        return [
            'title' => $name . ' · ' . site_label(),
            'page_title' => sprintf($type === 'category' ? __('tt.category') : __('tt.tag'), $name),
            'name' => $name,
            'total_items' => $total,
            'posts' => $posts,
            'page' => $page,
            'total_pages' => max(1, (int)ceil($total / $per)),
        ];
    }

    public static function enArchivesOverview(): array
    {
        $lang = Lang::short();
        $T = Db::t('contents');
        $P = Poly::table();
        $rows = Db::rows(
            "SELECT DATE_FORMAT(c.published_at, '%Y') y, DATE_FORMAT(c.published_at, '%m') m, COUNT(DISTINCT p.group_id) n "
            . "FROM {$P} p JOIN {$T} c ON c.id=p.group_id "
            . "WHERE p.lang='{$lang}' AND p.status='publish' AND c.status='publish' AND c.deleted_at IS NULL "
            . 'GROUP BY y, m ORDER BY y DESC, m DESC'
        );
        return ['title' => sprintf(__('tt.arch_of'), site_label()), 'page_title' => __('tt.arch'), 'months' => $rows];
    }

    public static function enArchivesList(int $y, int $m): array
    {
        $lang = Lang::short();
        $T = Db::t('contents');
        $P = Poly::table();
        $rows = Db::rows(
            "SELECT p.slug, p.title, p.excerpt, p.content, c.views, c.published_at FROM {$P} p JOIN {$T} c ON c.id=p.group_id "
            . "WHERE p.lang='{$lang}' AND p.status='publish' AND c.status='publish' AND c.deleted_at IS NULL "
            . "AND YEAR(c.published_at)=? AND MONTH(c.published_at)=? ORDER BY c.published_at DESC, p.id DESC LIMIT 200",
            [$y, $m]
        );
        $posts = [];
        foreach ($rows as $r) {
            $posts[] = [
                'id' => 0, 'title' => $r['title'], 'slug' => $r['slug'],
                'excerpt' => (string)($r['excerpt'] ?? ''), 'content' => (string)($r['content'] ?? ''),
                'views' => $r['views'], 'published_at' => $r['published_at'], 'created_at' => $r['published_at'],
                'cats' => [], 'tags' => [],
            ];
        }
        $title = lang_month($y, $m);
        return ['title' => $title . ' · ' . site_label(), 'page_title' => sprintf(__('tt.archive'), $title), 'posts' => $posts, 'page' => 1, 'total_pages' => 1];
    }

    public static function enSearch(string $q): array
    {
        $lang = Lang::short();
        $T = Db::t('contents');
        $P = Poly::table();
        $posts = [];
        if ($q !== '') {
            $terms = Str::terms($q);
            if ($terms) {
                // D8：译文表无 FULLTEXT —— 先只取 id+title 预筛（≤300），排序后对 top40 回表取全文，避免先拉 300 行 LONGTEXT
                $where = [];
                $params = [];
                foreach ($terms as $t) {
                    $where[] = '(p.title LIKE ? OR p.content LIKE ?)';
                    $like = '%' . $t . '%';
                    $params[] = $like; $params[] = $like;
                }
                $pre = Db::rows(
                    "SELECT p.id, p.title FROM {$P} p JOIN {$T} c ON c.id=p.group_id "
                    . "WHERE p.lang='{$lang}' AND p.status='publish' AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL "
                    . 'AND (' . implode(' OR ', $where) . ') ORDER BY c.published_at DESC, p.id DESC LIMIT 300',
                    $params
                );
                usort($pre, function (array $a, array $b) use ($q): int {
                    return self::rankText($b['title'], $q) <=> self::rankText($a['title'], $q);
                });
                $top = array_slice($pre, 0, 40);
                if ($top) {
                    $ids = array_map('intval', array_column($top, 'id'));
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $raw = Db::rows(
                        "SELECT p.slug, p.title, p.excerpt, p.content, c.views, c.published_at FROM {$P} p JOIN {$T} c ON c.id=p.group_id "
                        . "WHERE p.lang='{$lang}' AND p.status='publish' AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL AND p.id IN ($in)",
                        $ids
                    );
                    foreach ($raw as $r) {
                        $posts[] = [
                            'id' => 0, 'title' => $r['title'], 'slug' => $r['slug'],
                            'excerpt' => (string)($r['excerpt'] ?? ''), 'content' => (string)($r['content'] ?? ''),
                            'views' => $r['views'], 'published_at' => $r['published_at'], 'created_at' => $r['published_at'],
                            'cats' => [], 'tags' => [],
                        ];
                    }
                }
            }
        }
        return [
            'title' => sprintf(__('tt.search_title'), $q, site_label()),
            'page_title' => sprintf(__('tt.search_pre'), $q),
            'q' => $q,
            'posts' => $posts,
            'page' => 1, 'total_pages' => 1,
        ];
    }

    private static function rankText(string $title, string $q): int
    {
        $score = 0;
        foreach (Str::terms($q) as $t) {
            if (mb_stripos($title, $t) !== false) {
                $score += 60;
                if (mb_stripos($title, $t) === 0) { $score += 40; }
            }
        }
        return $score;
    }
}
