<?php
declare(strict_types=1);
/**
 * 行业演示数据包（SiteSeed）
 * —— 一键把官网内容切换成某个行业的完整样例：企业信息 + 优势 + 横幅 + 产品 + 案例 + 招聘 + 新闻，
 *    让 12 套行业风格包都能以「像那个行业的站」的样子呈现（演示 / 上架 / 客户对比用）。
 * —— 数据本身在 seed-data.php；本文件只负责写入与清理。
 *
 * 注意：会**替换**现有官网演示内容（site_product / site_case / site_banner / site_job
 *      以及「公司动态」分类下的文章），后台按钮上已注明，生产站慎用。
 */
final class SiteSeed
{
    public const NEWS_CATE_SLUG = 'company-news';

    /** 可载入的行业包（与 Site::THEMES 一一对应） */
    public static function industries(): array
    {
        return Site::THEMES;
    }

    private static function data(): array
    {
        static $d = null;
        if ($d === null) {
            $f = __DIR__ . '/seed-data.php';
            $d = is_file($f) ? (array)(include $f) : [];
        }
        return $d;
    }

    /** 载入某行业演示数据；返回可读的操作摘要 */
    public static function apply(string $id): string
    {
        $all = self::data();
        if (!isset($all[$id])) {
            return '未找到行业演示数据包：' . e($id);
        }
        $d = $all[$id];
        $log = [];

        /* 1. 企业信息 + SEO + 优势 + 风格包 */
        foreach (['name', 'slogan', 'intro', 'phone', 'email', 'address', 'wechat', 'icp'] as $k) {
            Site::setOpt('company.' . $k, (string)($d['company'][$k] ?? ''));
        }
        foreach (['title', 'desc', 'kw'] as $k) {
            Site::setOpt('seo.' . $k, (string)($d['seo'][$k] ?? ''));
        }
        Site::setOpt('features', implode("\n", (array)($d['features'] ?? [])));
        Site::setOpt('theme', $id);

        /* 2. 清空官网自身的演示表（硬删：这些表只存官网内容，不含用户系统数据） */
        foreach (['product', 'case', 'banner', 'job'] as $tb) {
            Db::run('DELETE FROM ' . Site::t($tb));
        }

        /* 3. 横幅 / 产品 / 案例 / 招聘 */
        $sort = 100;
        foreach ((array)($d['banners'] ?? []) as $b) {
            Db::insert('site_banner', ['title' => (string)$b[0], 'sub' => (string)($b[1] ?? ''),
                'image' => '', 'link' => '', 'sort' => $sort--, 'is_show' => 1]);
        }
        $sort = 100;
        foreach ((array)($d['products'] ?? []) as $p) {
            Db::insert('site_product', [
                'cate_id' => 0, 'name' => (string)$p['name'], 'summary' => (string)($p['summary'] ?? ''),
                'content' => (string)($p['content'] ?? ''), 'image' => (string)($p['img'] ?? ''),
                'params' => implode("\n", (array)($p['params'] ?? [])),
                'sort' => $sort--, 'is_show' => 1,
            ]);
        }
        $sort = 100;
        foreach ((array)($d['cases'] ?? []) as $c) {
            Db::insert('site_case', [
                'name' => (string)$c['name'], 'industry' => (string)($c['industry'] ?? ''),
                'challenge' => (string)($c['challenge'] ?? ''), 'solution' => (string)($c['solution'] ?? ''),
                'result' => (string)($c['result'] ?? ''), 'image' => (string)($c['img'] ?? ''),
                'sort' => $sort--, 'is_show' => 1,
            ]);
        }
        $sort = 100;
        foreach ((array)($d['jobs'] ?? []) as $j) {
            Db::insert('site_job', [
                'title' => (string)$j['title'], 'place' => (string)($j['place'] ?? ''),
                'type' => (string)($j['type'] ?? '全职'), 'salary' => (string)($j['salary'] ?? ''),
                'duty' => implode("\n", (array)($j['duty'] ?? [])),
                'requirement' => implode("\n", (array)($j['requirement'] ?? [])),
                'sort' => $sort--, 'is_show' => 1,
            ]);
        }
        $log[] = '产品 ' . count((array)($d['products'] ?? [])) . ' / 案例 ' . count((array)($d['cases'] ?? []))
               . ' / 横幅 ' . count((array)($d['banners'] ?? [])) . ' / 岗位 ' . count((array)($d['jobs'] ?? []));

        /* 4. 新闻：确保「公司动态」分类存在，清掉旧演示文章后重灌 */
        $cateId = self::ensureNewsCate();
        Site::setOpt('news.cate', (string)$cateId);
        Site::setOpt('news.exclude', 'YeCha,夜叉');

        $old = Db::rows('SELECT content_id FROM ' . Db::t('relationships') . ' WHERE meta_id = ?', [$cateId]);
        $ids = array_map(static fn($r) => (int)$r['content_id'], $old);
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            Db::run('DELETE FROM ' . Db::t('relationships') . " WHERE meta_id = ?", [$cateId]);
            Db::run('DELETE FROM ' . Db::t('contents') . " WHERE id IN ($in)", $ids);
        }
        $n = 0;
        foreach ((array)($d['news'] ?? []) as $i => $a) {
            $slug = $id . '-news-' . ($i + 1);
            $cid = Db::insert('contents', [
                'type' => 'post', 'status' => 'publish', 'title' => (string)$a['title'], 'slug' => $slug,
                'excerpt' => (string)($a['excerpt'] ?? ''), 'description' => (string)($a['excerpt'] ?? ''),
                'keywords' => (string)($d['seo']['kw'] ?? ''), 'content' => (string)($a['body'] ?? ''),
                'content_format' => 'markdown', 'author_id' => 0, 'allow_comment' => 0,
                'published_at' => date('Y-m-d H:i:s', strtotime('-' . (($i + 1) * 9) . ' days')),
            ]);
            Db::insert('relationships', ['content_id' => (int)$cid, 'meta_id' => $cateId]);
            $n++;
        }
        $log[] = '新闻 ' . $n . '（分类 #' . $cateId . '）';

        /* 5. 同步 AI 客服知识库（标记 demo-<id>，便于重复载入时替换而非堆积） */
        $kb = 0;
        if (class_exists('Ai') && \Plugins::isOn('ai')) {
            try {
                Db::run('DELETE FROM ' . Ai::t('knowledge') . ' WHERE tags = ?', ['demo-' . $id]);
                foreach ((array)($d['kb'] ?? []) as $k) {
                    Ai::kbAdd((string)$k[0], (string)$k[1], 'demo-' . $id);
                    $kb++;
                }
            } catch (Throwable $e) { /* 知识库不可用不影响官网演示数据载入 */ }
        }
        if ($kb) { $log[] = 'AI 知识库 ' . $kb . ' 条'; }

        /* 6. 清页面缓存，避免旧内容残留 */
        foreach (['pages', 'blocks'] as $dir) {
            $p = dirname(__DIR__, 2) . '/var/cache/' . $dir;
            foreach (glob($p . '/*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
        }
        return '已载入「' . e((string)($d['label'] ?? $id)) . '」演示数据：' . implode('，', $log) . '。';
    }

    /** 确保「公司动态」分类存在，返回 meta id */
    private static function ensureNewsCate(): int
    {
        $id = (int)Db::one('SELECT id FROM ' . Db::t('metas') . ' WHERE type = ? AND slug = ? LIMIT 1',
            ['category', self::NEWS_CATE_SLUG]);
        if ($id > 0) { return $id; }
        return (int)Db::insert('metas', ['type' => 'category', 'name' => '公司动态',
            'slug' => self::NEWS_CATE_SLUG, 'description' => '企业官网新闻动态（自动同步到官网首页）']);
    }
}
