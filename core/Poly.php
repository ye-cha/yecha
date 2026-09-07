<?php
declare(strict_types=1);
/**
 * Poly：多语言（翻译组模型）
 * - 默认语言内容在 ye_contents；其它语言真实版本在 ye_poly_contents（group_id → contents.id）
 * - 每语言 title/slug/正文独立；slug 在 (lang, slug) 内唯一
 * - AI 翻译：OpenAI 兼容接口（默认 DeepSeek），生成写为 draft（ai_generated=1），人工审校后发布
 */
final class Poly
{
    public static function enabled(): array
    {
        $raw = get_option('site.languages', null);
        $list = $raw ? (json_decode((string)$raw, true) ?: []) : ['zh'];
        return array_values(array_filter((array)$list));
    }

    /** 内置语言目录（后台语言管理勾选用；pack=界面语言包文件基名） */
    public static function catalog(): array
    {
        return [
            'zh' => ['name' => '中文（简）', 'pack' => 'zh_CN', 'dir' => 'ltr'],
            'tw' => ['name' => '中文（繁）', 'pack' => 'zh_TW', 'dir' => 'ltr'],
            'en' => ['name' => 'English', 'pack' => 'en_US', 'dir' => 'ltr'],
            'ja' => ['name' => '日本語', 'pack' => 'ja_JP', 'dir' => 'ltr'],
            'ko' => ['name' => '한국어', 'pack' => 'ko_KR', 'dir' => 'ltr'],
            'fr' => ['name' => 'Français', 'pack' => 'fr_FR', 'dir' => 'ltr'],
            'de' => ['name' => 'Deutsch', 'pack' => 'de_DE', 'dir' => 'ltr'],
            'es' => ['name' => 'Español', 'pack' => 'es_ES', 'dir' => 'ltr'],
            'pt' => ['name' => 'Português', 'pack' => 'pt_BR', 'dir' => 'ltr'],
            'it' => ['name' => 'Italiano', 'pack' => 'it_IT', 'dir' => 'ltr'],
            'ru' => ['name' => 'Русский', 'pack' => 'ru_RU', 'dir' => 'ltr'],
            'ar' => ['name' => 'العربية', 'pack' => 'ar_SA', 'dir' => 'rtl'],
            'hi' => ['name' => 'हिन्दी', 'pack' => 'hi_IN', 'dir' => 'ltr'],
            'th' => ['name' => 'ไทย', 'pack' => 'th_TH', 'dir' => 'ltr'],
            'vi' => ['name' => 'Tiếng Việt', 'pack' => 'vi_VN', 'dir' => 'ltr'],
            'id' => ['name' => 'Bahasa Indonesia', 'pack' => 'id_ID', 'dir' => 'ltr'],
            'tr' => ['name' => 'Türkçe', 'pack' => 'tr_TR', 'dir' => 'ltr'],
            'pl' => ['name' => 'Polski', 'pack' => 'pl_PL', 'dir' => 'ltr'],
            'nl' => ['name' => 'Nederlands', 'pack' => 'nl_NL', 'dir' => 'ltr'],
            'sv' => ['name' => 'Svenska', 'pack' => 'sv_SE', 'dir' => 'ltr'],
        ];
    }

    public static function label(string $code): string
    {
        return self::catalog()[$code]['name'] ?? $code;
    }

    /** 界面语言包文件名（目录不存在的语言回落默认语言包，前台界面暂以基准语言显示） */
    public static function pack(string $code): string
    {
        $p = self::catalog()[$code]['pack'] ?? '';
        $f = dirname(__DIR__) . '/lang/' . $p . '.php';
        return is_file($f) ? $p : 'zh_CN';
    }

    /** 术语（分类/标签）指定语言的名称；该语言无名称时返回空串 */
    public static function termLang(array $row, string $lang): string
    {
        $i18n = (string)($row['names_i18n'] ?? '');
        if ($i18n !== '') {
            $j = json_decode($i18n, true);
            if (is_array($j) && isset($j[$lang]) && $j[$lang] !== '') { return (string)$j[$lang]; }
        }
        if ($lang === 'en' && (string)($row['name_en'] ?? '') !== '') { return (string)$row['name_en']; }
        return '';
    }

    /** 术语（分类/标签）在指定语言的名称：names_i18n → name_en → name */
    public static function termName(array $row, ?string $lang = null): string
    {
        $lang = $lang ?? Lang::short();
        $t = self::termLang($row, $lang);
        return $t !== '' ? $t : (string)($row['name'] ?? '');
    }

    /** 多语插件是否在运行（可关闭；关闭后前台不解析语言前缀、不输出任何多语痕迹）
     *  单请求内 memo：避免每页 6-10 次重复查库（options 每请求已全量 autoload，一致性不受影响） */
    public static function on(): bool
    {
        static $memo = null;
        if ($memo !== null) { return $memo; }
        $v = get_option('plugin.poly.enabled', '1');
        $state = $v === null ? '1' : (string)$v;
        $memo = $state !== '0' && count(self::enabled()) > 1;
        return $memo;
    }

    public static function defaultLang(): string
    {
        $raw = get_option('site.default_lang', 'zh');
        return (string)$raw;
    }

    public static function table(): string
    {
        return Db::t('poly_contents');
    }

    /** 某翻译组在指定语言的版本行（含草稿） */
    public static function row(int $baseId, string $lang): ?array
    {
        return Db::row('SELECT * FROM ' . self::table() . ' WHERE group_id=? AND lang=? LIMIT 1', [$baseId, $lang]);
    }

    /** 已发布译文：按 lang+slug 解析（供前台 /{lang}/{slug}.html） */
    public static function findPublished(string $slug, string $lang): ?array
    {
        return Db::row(
            "SELECT * FROM " . self::table() . " WHERE lang=? AND slug=? AND status='publish' LIMIT 1",
            [$lang, $slug]
        );
    }

    /** 前台列表：某语言已发布译文（按更新时间倒序）；$withContent=true 时同时取正文（供 RSS） */
    public static function publishedList(string $lang, int $limit = 20, bool $withContent = false): array
    {
        $sel = 'p.title, p.slug, p.excerpt, p.updated_at, c.views, c.published_at';
        if ($withContent) { $sel .= ', p.content'; }
        return Db::rows(
            'SELECT ' . $sel
            . ' FROM ' . self::table() . ' p JOIN ' . Db::t('contents') . ' c ON c.id=p.group_id '
            . "WHERE p.lang=? AND p.status='publish' AND c.status='publish' AND c.deleted_at IS NULL "
            . 'ORDER BY c.published_at DESC, p.id DESC LIMIT ' . (int)$limit,
            [$lang]
        );
    }

    /** 站点默认翻译模式：manual（人工/双语）| ai（AI 全自动） */
    public static function modeDefault(): string
    {
        return (string)get_option('poly.mode_default', 'manual') === 'ai' ? 'ai' : 'manual';
    }

    /** 某语言实际模式（覆盖优先，否则跟站点默认）；基准语言恒 manual */
    public static function mode(string $lang): string
    {
        if ($lang === self::defaultLang()) { return 'manual'; }
        $m = (string)get_option('poly.mode.' . $lang, '');
        return ($m === 'ai' || $m === 'manual') ? $m : self::modeDefault();
    }

    /** 基准内容（中文）关联的分类/标签 meta id */
    public static function baseTaxIds(int $baseId): array
    {
        $rel = Db::rows(
            "SELECT r.meta_id, m.type FROM " . Db::t('relationships') . " r JOIN " . Db::t('metas') . " m ON m.id=r.meta_id "
            . "WHERE r.content_id=? AND m.type IN ('category','tag')", [$baseId]
        );
        return [
            'cats' => array_values(array_map('intval', array_column(array_filter($rel, fn($x) => $x['type'] === 'category'), 'meta_id'))),
            'tags' => array_values(array_map('intval', array_column(array_filter($rel, fn($x) => $x['type'] === 'tag'), 'meta_id'))),
        ];
    }

    /* ---------- 术语 slug 语言化（每语言 URL 别名） ---------- */

    /** 从 names_i18n 生成各语言 slug（仅拉丁/纯 ASCII 名称可生成；中文/韩文/假名等回落默认 slug） */
    public static function termSlugsFromI18n(array $i18n): array
    {
        $default = self::defaultLang();
        $out = [];
        foreach ($i18n as $code => $name) {
            if ($code === $default || !is_string($name) || trim($name) === '') { continue; }
            // 纯 ASCII 化 slug（与 Str::slug 一致但不落入 p-hash 回退，确保可复现）
            $s = strtolower(trim($name));
            $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
            $s = trim($s, '-');
            if ($s === '' || strlen($s) < 2) { continue; } // 韩文/假名/西里尔等非拉丁名：留空回落默认 slug
            $out[$code] = substr($s, 0, 190);
        }
        return $out;
    }

    /** 术语在指定语言的 slug（slug_i18n[lang] 缺失回落默认 slug） */
    public static function termSlug(array $row, ?string $lang = null): string
    {
        $lang = $lang ?? Lang::short();
        $default = self::defaultLang();
        $base = (string)($row['slug'] ?? '');
        if ($lang === $default) { return $base; }
        $j = json_decode((string)($row['slug_i18n'] ?? ''), true);
        if (is_array($j) && isset($j[$lang]) && $j[$lang] !== '') { return (string)$j[$lang]; }
        return $base;
    }

    /** 生成某术语 slug_i18n：对 names_i18n 中各语言名做 latin 化，并保证与
     *  同类型其它术语不冲突（其基础 slug 占全语言位；同语言别名去重，冲突追加 -2/-3）。
     *  @param array $names 已解码的 names_i18n；@param string $type category|tag；@param int $selfId 自身 id（新增为 0） */
    public static function termSlugI18nFor(array $names, string $type, int $selfId = 0): array
    {
        $map = self::termSlugsFromI18n($names);
        if (!$map) { return []; }
        $t = Db::t('metas');
        $usedAny = [];    // 其它术语的基础 slug：解析先按 slug 列精确匹配，故占所有语言位
        $usedLang = [];   // lang => slug => true（同语言别名）
        foreach (Db::rows("SELECT id, slug, slug_i18n FROM {$t} WHERE type=? AND id<>?", [$type, $selfId]) as $o) {
            $base = (string)$o['slug'];
            if ($base !== '') { $usedAny[$base] = true; }
            $j = json_decode((string)($o['slug_i18n'] ?? ''), true);
            if (is_array($j)) {
                foreach ($j as $code => $s) {
                    if (is_string($s) && $s !== '') { $usedLang[$code][$s] = true; }
                }
            }
        }
        $out = [];
        foreach ($map as $code => $slug) {
            $baseSlug = $slug;
            $n = 2;
            while (isset($usedAny[$slug]) || isset($usedLang[$code][$slug])) {
                $slug = $baseSlug . '-' . $n++;
            }
            $usedLang[$code][$slug] = true;
            $out[$code] = $slug;
        }
        return $out;
    }

    /** 基础 slug（默认语列）是否已被同类型其它术语占用（其 slug 列或其 slug_i18n 任一语言值） */
    public static function baseSlugReserved(string $type, string $slug, int $selfId = 0): bool
    {
        $t = Db::t('metas');
        foreach (Db::rows("SELECT id, slug, slug_i18n FROM {$t} WHERE type=? AND id<>?", [$type, $selfId]) as $o) {
            if ((string)$o['slug'] === $slug) { return true; }
            $j = json_decode((string)($o['slug_i18n'] ?? ''), true);
            if (is_array($j)) {
                foreach ($j as $code => $s) {
                    if (is_string($s) && $s === $slug) { return true; }
                }
            }
        }
        return false;
    }

    /** 术语页相对路径（默认语 /tag/slug/；其它语言 /{lang}/tag/slugL/） */
    public static function termUrl(string $type, array $row): string
    {
        $lang = Lang::short();
        $slug = self::termSlug($row, $lang);
        if ($lang === self::defaultLang() || !Poly::on()) {
            return '/' . $type . '/' . $slug . '/';
        }
        return '/' . $lang . '/' . $type . '/' . $slug . '/';
    }

    /** 保存/新建译文（人工或 AI 审校后） */
    public static function save(int $baseId, string $lang, array $d): array
    {
        $base = Db::row('SELECT * FROM ' . Db::t('contents') . ' WHERE id=?', [$baseId]);
        if ($base === null) { return ['ok' => false, 'error' => '找不到原文']; }
        $title = trim((string)($d['title'] ?? ''));
        if ($title === '') { return ['ok' => false, 'error' => '译文标题不能为空']; }
        $slug = trim((string)($d['slug'] ?? ''));
        if ($slug === '') {
            $slug = Str::slug($title);
        } else {
            $slug = substr(strtolower(preg_replace('/[^a-z0-9-]/', '-', $slug) ?? ''), 0, 190);
        }
        $slug = trim($slug, '-');
        $baseSlug = $slug;
        for ($i = 2; $i < 50; $i++) {
            $exists = Db::one('SELECT id FROM ' . self::table() . ' WHERE lang=? AND slug=? AND group_id<>? LIMIT 1', [$lang, $slug, $baseId]);
            if ($exists === null) { break; }
            $slug = $baseSlug . '-' . $i;
        }
        $status = ($d['status'] ?? '') === 'publish' ? 'publish' : 'draft';
        $data = [
            'group_id' => $baseId, 'lang' => $lang,
            'title' => $title, 'slug' => $slug,
            'excerpt' => trim((string)($d['excerpt'] ?? '')),
            'keywords' => trim((string)($d['keywords'] ?? '')),
            'description' => trim((string)($d['description'] ?? '')),
            'content' => (string)($d['content'] ?? ''),
            'status' => $status,
        ];
        $old = self::row($baseId, $lang);
        if ($old !== null) {
            Db::update('poly_contents', $data, 'id=?', [(int)$old['id']]);
        } else {
            $data['ai_generated'] = (int)($d['ai_generated'] ?? 0);
            $data['source_lang'] = self::defaultLang();
            Db::insert('poly_contents', $data);
        }
        self::clearStale($baseId, $lang);
        Cache::flush('pages');
        Cache::flush('blocks');
        return ['ok' => true, 'slug' => $slug];
    }

    /** 翻译完成度统计（某语言 vs 全部已发布原文：post + page 独立页一并纳入记录） */
    public static function stats(string $lang): array
    {
        $T = Db::t('contents');
        $total = (int)Db::one("SELECT COUNT(*) FROM {$T} WHERE type IN ('post','page') AND status='publish' AND deleted_at IS NULL");
        $published = (int)Db::one(
            'SELECT COUNT(*) FROM ' . self::table() . " p JOIN {$T} c ON c.id=p.group_id "
            . "WHERE p.lang=? AND p.status='publish' AND c.type IN ('post','page') AND c.status='publish' AND c.deleted_at IS NULL",
            [$lang]
        );
        $draft = (int)Db::one(
            'SELECT COUNT(*) FROM ' . self::table() . " p JOIN {$T} c ON c.id=p.group_id "
            . "WHERE p.lang=? AND p.status='draft' AND c.type IN ('post','page') AND c.status='publish' AND c.deleted_at IS NULL",
            [$lang]
        );
        return ['total' => $total, 'published' => $published, 'draft' => $draft, 'missing' => max(0, $total - $published)];
    }

    /** 基准内容更新后调用：该组所有译文标记“过期”（人工锁定篇也标记，供前台/后台提示） */
    public static function markStale(int $baseId): void
    {
        Db::update('poly_contents', ['stale' => 1], 'group_id=?', [$baseId]);
    }

    /** 译文被重译/人工保存后清除“过期”标记 */
    public static function clearStale(int $baseId, string $lang): void
    {
        Db::update('poly_contents', ['stale' => 0], 'group_id=? AND lang=?', [$baseId, $lang]);
    }

    /** 某语言过期统计：已发布译文里 stale=1 总数 / 其中人工锁定数 */
    public static function staleStats(string $lang): array
    {
        $T = Db::t('contents');
        $total = (int)Db::one(
            'SELECT COUNT(*) FROM ' . self::table() . " p JOIN {$T} c ON c.id=p.group_id "
            . "WHERE p.lang=? AND p.status='publish' AND p.stale=1 AND c.status='publish' AND c.deleted_at IS NULL",
            [$lang]
        );
        $locked = (int)Db::one(
            'SELECT COUNT(*) FROM ' . self::table() . " p JOIN {$T} c ON c.id=p.group_id "
            . "WHERE p.lang=? AND p.status='publish' AND p.stale=1 AND p.manual_override=1 AND c.status='publish' AND c.deleted_at IS NULL",
            [$lang]
        );
        return ['stale' => $total, 'locked' => $locked];
    }

    /** AI 翻译 → 生成/覆盖译文；$publish=true 时直接发布（CLI 批量用） */
    public static function aiTranslate(int $baseId, string $lang, string $model = '', string $base = '', string $key = '', bool $publish = false, bool $ignoreOverride = false): array
    {
        $base = $base !== '' ? $base : (string)get_option('ai.base', 'https://api.deepseek.com/chat/completions');
        $key = $key !== '' ? $key : yc_opt('ai.key', ''); // 支持密文存储
        $model = $model !== '' ? $model : (string)get_option('ai.model', 'deepseek-chat');
        if ($key === '') {
            return ['ok' => false, 'error' => '未配置 AI Key：请到 后台 → 多语 → AI 设置 填写（OpenAI 兼容，默认 DeepSeek）'];
        }
        $baseRow = Db::row('SELECT * FROM ' . Db::t('contents') . ' WHERE id=?', [$baseId]);
        if ($baseRow === null) { return ['ok' => false, 'error' => '原文不存在']; }
        $oldCheck = self::row($baseId, $lang);
        if ($oldCheck !== null && (int)($oldCheck['manual_override'] ?? 0) === 1 && !$ignoreOverride) {
            // AI 全量重译不得覆盖人工修正
            return ['ok' => true, 'skipped_override' => true];
        }
        $protect = (string)get_option('ai.protect', 'YeCha,夜叉');
        $srcName = self::label(self::defaultLang());
        $tgtName = self::label($lang);
        $sys = 'You are a professional translation engine. Translate the following blog post from ' . $srcName . ' (' . self::defaultLang() . ') to ' . $tgtName . ' (' . $lang . '). '
            . 'Keep Markdown syntax, code blocks and links unchanged. Return ONLY JSON: {"title":"...","excerpt":"...","keywords":"...","content":"..."}. '
            . 'Do not translate these protected terms: ' . $protect . '.';
        $user = 'Title: ' . $baseRow['title'] . "\n\nExcerpt:\n" . $baseRow['excerpt'] . "\n\nContent:\n" . $baseRow['content'];
        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => 0.3,
            'max_tokens' => 8192,
            'response_format' => ['type' => 'json_object'],
        ], JSON_UNESCAPED_UNICODE);

        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $key . "\r\n",
            'content' => $payload,
            'timeout' => 60,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($base, false, $ctx);
        if ($resp === false) {
            return ['ok' => false, 'error' => 'AI 接口请求失败（网络/地址），请检查 ai.base 设置'];
        }
        $j = json_decode($resp, true);
        $text = $j['choices'][0]['message']['content'] ?? '';
        if ($text === '') {
            $err = $j['error']['message'] ?? '未知错误';
            return ['ok' => false, 'error' => 'AI 返回错误：' . $err];
        }
        // 剥离可能的 ```json 围栏
        $text = trim(preg_replace('/^```(?:json)?\s*/', '', $text));
        $text = trim(preg_replace('/```\s*$/', '', $text));
        $parsed = json_decode($text, true);
        if (!is_array($parsed) || ($parsed['content'] ?? '') === '') {
            return ['ok' => false, 'error' => 'AI 返回格式异常，请重试或手动填写'];
        }
        $d = [
            'title' => (string)($parsed['title'] ?? $baseRow['title']),
            'slug' => (string)$baseRow['slug'],
            'excerpt' => Str::cleanMd(trim((string)($parsed['excerpt'] ?? ''))),
            'keywords' => (string)($parsed['keywords'] ?? ''),
            'description' => mb_substr(Str::cleanMd(trim((string)($parsed['description'] ?? ''))), 0, 300),
            'content' => (string)$parsed['content'],
            'status' => $publish ? 'publish' : 'draft',
            'ai_generated' => 1,
        ];
        $old = $oldCheck;
        if ($old !== null) {
            // 缺分类/标签（早期译文）时自动补齐基准分类/标签
            $upd = [
                'title' => $d['title'], 'slug' => $d['slug'], 'excerpt' => $d['excerpt'],
                'keywords' => $d['keywords'], 'description' => $d['description'], 'content' => $d['content'],
                'status' => $d['status'], 'ai_generated' => 1,
                'manual_override' => $ignoreOverride ? 0 : (int)($old['manual_override'] ?? 0),
                'ai_revision' => (int)($old['ai_revision'] ?? 0) + 1,
                'last_ai_at' => date('Y-m-d H:i:s'),
            ];
            if ((($old['cat_ids'] ?? '') === null || ($old['cat_ids'] ?? '') === '')) {
                $tax = self::baseTaxIds($baseId);
                $upd['cat_ids'] = json_encode($tax['cats'], JSON_UNESCAPED_UNICODE);
            }
            if ((($old['tag_ids'] ?? '') === null || ($old['tag_ids'] ?? '') === '')) {
                $tax = self::baseTaxIds($baseId);
                $upd['tag_ids'] = json_encode($tax['tags'], JSON_UNESCAPED_UNICODE);
            }
            Db::update('poly_contents', $upd, 'id=?', [(int)$old['id']]);
        } else {
            $tax = self::baseTaxIds($baseId);
            $d['group_id'] = $baseId;
            $d['lang'] = $lang;
            $d['source_lang'] = self::defaultLang();
            $d['cat_ids'] = json_encode($tax['cats'], JSON_UNESCAPED_UNICODE);
            $d['tag_ids'] = json_encode($tax['tags'], JSON_UNESCAPED_UNICODE);
            $d['manual_override'] = 0;
            $d['ai_revision'] = 0;
            $d['last_ai_at'] = date('Y-m-d H:i:s');
            Db::insert('poly_contents', $d);
        }
        // 重译成功：清除“过期”标记（人工锁定篇在此路径不会到达：已在入口 early-return）
        self::clearStale($baseId, $lang);
        Cache::flush('pages');
        Cache::flush('blocks');
        return ['ok' => true, 'text' => $text];
    }
}
