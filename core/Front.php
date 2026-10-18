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

    /**
     * 按给定 id 顺序取回内容行。
     * 列表页一律「先取 id 再取行」：ids 由窄列查询（只带 id/published_at）排序得出，
     * 避免把数十 MB 的 LONGTEXT 正文拖进 filesort——正文以 3GB 计时这一步就是十几秒。
     *
     * $withBody=false 时用 information_schema 拼出「除 content 外的全部列」：
     * 前台列表只需 字数（见 ye_contents.words）与摘要，把正文整列排除后，
     * 20 行的回表从 906ms 降到 1ms（实测，「子部」一页正文合计 35.6MB）。
     */
    private static function rowsByIds(array $ids, bool $withBody = false): array
    {
        if (!$ids) { return []; }
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($withBody) {
            $rows = Db::rows('SELECT * FROM ' . Db::t('contents') . " WHERE id IN ($in)", $ids);
        } else {
            static $listCols = null;
            if ($listCols === null) {
                $cols = array_column(Db::rows(
                    'SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name<>?',
                    [Db::t('contents'), 'content']
                ), 'column_name');
                if (!$cols) { $cols = ['*']; }
                $listCols = count($cols) === 1 && $cols[0] === '*' ? '*' : '`' . implode('`,`', $cols) . '`';
            }
            $rows = Db::rows('SELECT ' . $listCols . ' FROM ' . Db::t('contents') . " WHERE id IN ($in)", $ids);
        }
        $by = [];
        foreach ($rows as $r) { $by[(int)$r['id']] = $r; }
        $out = [];
        foreach ($ids as $id) { if (isset($by[$id])) { $out[] = $by[$id]; } }
        return $out;
    }

    private static function publishAt(): string
    {
        // 前台所有用法均限定 status='publish'（published_at 恒非空）：直用列名以走 idx_contents_pub 索引，
        // 避免 COALESCE 表达式 filesort。草稿/定时等含 NULL 的查询不走本函数（后台列表自带 COALESCE）。
        return 'published_at';
    }

    /** 请求内 memo：type → 窄列术语列表（id,type,slug,slug_i18n），供语言别名解析复用 */
    private static array $metaBySlugIdx = [];

    /* =================================================================
     * 译文（非默认语言）列表：性能关键路径
     * -----------------------------------------------------------------
     * 全量古籍入库后 ye_poly_contents 139,319 行、ye_contents 175,540 行。
     * 原实现一律写成
     *   FROM poly p JOIN contents c ON c.id=p.group_id
     *   WHERE p.lang=? AND p.status='publish' AND c.type='post' ... AND <JSON_CONTAINS(cat_ids)>
     *   ORDER BY c.published_at DESC, p.id DESC LIMIT n
     * 排序键跨两张表 → MySQL 无法用索引排序 → 139k 行 join + filesort，且 select 列表含
     * p.content（LONGTEXT，全站 3.2GB），filesort 阶段还要物化正文。实测后果：
     *   · /tw/<slug>.html     单条 SQL 1689ms（页面 1.71s）
     *   · /tw/category/<部>/  COUNT 6831ms + SELECT 被 max_execution_time=8000 杀掉 → HTTP 500
     *   · /tw/archives/       GROUP BY 被 8s 上限杀掉 → HTTP 500
     *   · /tw/page/2/         同样 500
     * 统一改两步：① 只取窄列（id / published_at）走索引拿到本页所需的 id 集合；
     *             ② 只按这批 id 回表取本页要展示的列，在小结果集内按原语义排序。
     * 分页 OFFSET 语义不变（先按 id 过滤再 OFFSET），仅候选窗口不足时自动放大重试。
     * ================================================================= */

    /** postWhere() 的带别名限定版本：join 了 ye_poly_contents 时 `status` 会歧义，必须限定 */
    private static function postWhereC(string $a = 'c'): string
    {
        return "{$a}.type='post' AND {$a}.status='publish' AND {$a}.deleted_at IS NULL";
    }

    /**
     * poly 侧覆盖索引提示：idx_poly_group_lang_st (group_id,lang,status)。
     * 有它时 (group_id,lang,status) 探测完全走索引，不必回表读 ye_poly_contents 的行
     * （该表 3.5GB，正文在行外，回表即随机 I/O）；实测 /tw/archives/ 冷查询由 8s（被杀）降到亚秒级。
     * 索引不存在时自动退回无提示写法 —— 避免 USE INDEX 引用不存在的索引直接报错。
     */
    private static function polyStHint(): string
    {
        static $hint = null;
        if ($hint === null) {
            $n = (int)Db::one(
                'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
                [Db::t('poly_contents'), 'idx_poly_group_lang_st']
            );
            $hint = $n > 0 ? ' USE INDEX (idx_poly_group_lang_st)' : '';
        }
        return $hint;
    }

    /**
     * contents 侧「有序覆盖索引」提示：idx_c_ts_del_pub (type,status,deleted_at,published_at,id)
     *
     * 为什么必须有这个提示（实测，MySQL 5.7.44）：
     *   本表已有 idx_c_arch_cover_ts(type,status,deleted_at,pub_y,pub_m,published_at)，它虽是覆盖索引，
     *   但 pub_y / pub_m 夹在 deleted_at 与 published_at 之间 → **无法满足** `ORDER BY published_at DESC, id DESC`。
     *   没有任何提示时优化器选它并做 filesort：EXPLAIN 估 9,949 行，实际把该 (type,status,deleted_at)
     *   索引区间**整段扫完**再排序取前 N（Handler_read_next 实测 **247,595**，即 post+publish 全量）——
     *   `LIMIT 160` 实测 281~773ms（暖/冷差异大）。
     *   走本索引则按序取到 N 条即停（read_next ≈ N）→ 实测 **0.69ms**，且返回的 id 序列与不带提示**逐行相同**。
     *   注意：**只加索引不够** —— 加索引前后 EXPLAIN 完全相同（5.7 低估 filesort 代价），必须显式 FORCE INDEX；
     *   这与主题里 idx_c_ts_del_views / idx_c_ts_del_words 的既有经验一致（见 themes/gushu/README.md）。
     * 索引不存在时自动退回无提示写法 —— 避免 FORCE INDEX 引用不存在的索引直接报错。
     */
    private static function pubOrderHint(): string
    {
        static $hint = null;
        if ($hint === null) {
            $n = (int)Db::one(
                'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
                [Db::t('contents'), 'idx_c_ts_del_pub']
            );
            $hint = $n > 0 ? ' FORCE INDEX (idx_c_ts_del_pub)' : '';
        }
        return $hint;
    }

    /**
     * 站内 post+publish 总数（缓存 3600s）。
     * 本类用于「候选窗口能否承载本页」的密度估算（补丁 7-B）；**同时公开供主题等调用方复用**（补丁 9）：
     * 主题 `gsh_home_stats()` 里等价的 `COUNT(*) FROM contents WHERE 同条件` 实测 **519.8 ms**
     * （走 `idx_contents_arch` 覆盖索引全扫 1,323,182 行）；改调本方法即 ~0ms。
     * TTL 由 600s 提到 3600s：该值只在内容导入后变化，1h 陈旧对密度估算无影响。
     */
    public static function postsTotal(): int
    {
        $ck  = 'posts_total_v1';
        $hit = Cache::get('misc', $ck);
        if ($hit !== false && $hit !== '') { return (int)$hit; }
        $n = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . ' WHERE ' . self::postWhere());
        Cache::set('misc', $ck, (string)$n, 3600);
        return $n;
    }

    /**
     * 深翻页提前退出：判断「候选窗口」这条路**还有没有可能**承载本页（补丁 7-B）
     *
     *   · 窗口 = 「最新 $win 条 post」，$win 上限 8192（循环里 min(8192, …)）；
     *   · 本页需要窗口里至少有 `limit + offset` 条命中；
     *   · 术语命中密度 ≈ total / 站内总数 → 所需窗口 ≈ need / 密度；
     *   · 所需窗口若超过 4096（= 循环**首轮**窗口的硬上限），就意味着至少要重试一次更大的窗口，
     *     而重试那一步的 `IN (…最多 8192 个 id…) + ORDER BY + OFFSET` 实测要 0.4~2s
     *     （宋 @2000 实测 1895ms，而 relationships 只要 245ms）→ 不如直接走 relationships。
     *
     * 安全性：本判断只决定「要不要去试窗口」，不改变任何返回数据 ——
     *   · 两条路径返回的 id 序列已实测逐行相同（CORE-PATCH 补丁 6）；
     *   · 拿不到总数（$posts<=0）或参数异常时**一律不拦**，保持原行为。
     */
    private static function windowCanCarry(int $need, int $total): bool
    {
        if ($need <= 0) { return true; }
        $posts = self::postsTotal();
        if ($posts <= 0 || $total <= 0) { return true; }
        // need / (total/posts) <= 4096   ⇔   need * posts <= 4096 * total（整数比较，避免浮点）
        return $need * $posts <= 4096 * $total;
    }
    /** 语言维度译文总数（短时缓存 300s；译文/内容写事件会 flush('blocks') 提前失效） */
    private static function polyCount(string $lang): int
    {
        $ck  = 'polycnt:' . $lang;
        $hit = Cache::get('blocks', $ck);
        if ($hit !== false && $hit !== '') { return (int)$hit; }
        // STRAIGHT_JOIN 从 contents 走覆盖索引（不回表），再用覆盖索引探测 poly —— 两侧都不碰聚集索引。
        $n = (int)Db::one(
            'SELECT COUNT(*) FROM ' . Db::t('contents') . ' c STRAIGHT_JOIN ' . Poly::table() . ' p' . self::polyStHint()
            . " ON p.group_id=c.id AND p.lang=? AND p.status='publish'"
            . ' WHERE ' . self::postWhereC(),
            [$lang]
        );
        Cache::set('blocks', $ck, (string)$n, 300);
        return $n;
    }

    /** 候选 id：contents 侧按 (published_at DESC, id DESC) 走索引倒序扫描，只取 id 窄列 */
    private static function candidateIds(int $win, string $andWhere = '', array $params = []): array
    {
        return array_map('intval', array_column(Db::rows(
            'SELECT id FROM ' . Db::t('contents') . self::pubOrderHint() . ' WHERE ' . self::postWhere()
            . ($andWhere !== '' ? ' AND ' . $andWhere : '')
            . ' ORDER BY ' . self::publishAt() . ' DESC, id DESC LIMIT ' . (int)$win,
            $params
        ), 'id'));
    }

    /* =================================================================
     * 补丁 11「术语键序预计算表」：根治大术语**深偏移**的慢路径
     * -----------------------------------------------------------------
     * 症状（改前实测，SQL 级）：窗口化快路径在深偏移被 windowCanCarry() 主动跳过，
     *   于是落到下面的 relationships 驱动写法 —— 它必须把本术语**全部命中行**跨表 filesort
     *   一遍，再 OFFSET：EXPLAIN `type=ref key=idx_relationships_meta rows=306162
     *   Extra=Using index; Using temporary; Using filesort`，
     *   Handler 计数 Created_tmp_files=2 / Sort_rows=145934 / Handler_read_next=145934
     *   ⇒ 代价是 **O(本术语条目数)**，与偏移无关地固定付出：
     *     · /tag/dyn-1/page/1000|5000|14594/（唐 145,934 条）单条 SQL 385~542ms，页级冷 1.0~1.4s
     *     · /tag/dyn-2/…（宋 321,516 条）1,303ms
     *     · /category/诗/page/5000/（诗 887,517 条）**3,561ms**，页级冷 **8.0s**
     *
     * 改法：为「条目数多的术语」预计算它的**键序**（seq → (published_at, content_id)），
     *   存进本任务自己的辅助表 ye_meta_page_bounds（无任何 ye_contents DDL）。
     *   取本页时不再 OFFSET 全序，而是：
     *     ① 取 seq ∈ [offset, offset+limit) 这 limit 行的**精确键**（主键范围扫描，≤limit 行）；
     *     ② 按 `c.id IN (…)` 主键回表 + STRAIGHT_JOIN relationships 复核归属，按原语义排序。
     *   实测唐 page/14594 的 4 项：EXPLAIN `type=range key=PRIMARY rows=4`，
     *   Handler_read_key=8、**0.7~1.4ms**（旧路径同页 395ms / 诗同类页 3,684ms）✓
     *   —— 与偏移无关、也不受"索引空档"影响。
     *
     * ⚠️ 为什么不用「双边窗口 keyset seek」做主力（实测否掉，务必保留结论）：
     *   seek 形态（`键<=边界 AND 键>下一边界` + ORDER BY + LIMIT/OFFSET）在**同一时间戳内**很快
     *   （实测 0.03s），但有两种结构性退化，且都发生在末页/跨空档的页：
     *   (a) 末页没有"下一档边界"，下界只能退成本术语最老一项 → 窗口按**索引宽度**而不是按条数计费；
     *   (b) 更根本的是：全序里**相邻两项在索引上可能隔着几十万行**。唐 page/14594 的 4 项里，
     *       id 762651（08-13）与 id 312201（08-12）相邻，二者在 idx_c_ts_del_pub 上隔着约
     *       **58.5 万行**（08-13 有 953,358 行，id 762651 以下全在里面）→ 任何「连续索引区间」
     *       都必须跨过去：单边 seek 3,772ms、双边 4,208ms、把 LIMIT 夹到窗口真实条数后仍 4,208ms
     *       （OFFSET 0 只要 0.03s，病根是 OFFSET/跨档而非上界）。
     *   ⇒ 故改为**逐项键序**（step=1）：拿键而不是"找键"，彻底绕开"跨空档"。
     *     稀疏粒度（step>1）仍保留 seek 兜底路径 metaPageIdsSeek()，供省空间的粗粒度建表使用。
     *
     * 等价性（硬要求）：表中 seq 就是该项在全序 L=(published_at DESC, id DESC) 中的**精确 0 基下标**，
     *   故 seq∈[offset, offset+limit) 取出的 id 序列与旧路径逐行相同（并已实测逐行 diff 为 0）。
     *   边界/键序表由 tools/gushu-meta-page-bounds.php 建并填充。
     *
     * 失效即降级（永不返回错数据的设计）：
     *   ① 表不存在 / 无本术语 state / 参数异常        → null（退回旧路径，行为同补丁 10 之前）
     *   ② 规模守卫：实时 $total 与建表时的 meta_count 和 item_count 都不符 → null
     *      （用「快照比对」而不是「期望等于真实」：ye_metas.count 对个别术语本就有 1~2 行偏差，
     *       如「宋」count=321516 而真实 321514，若拿真实值当期望会把这条路永久判死）
     *   ③ 水位守卫：MAX(ye_contents.id) 变了（任何新增内容） → null
     *   ④ 结果守卫：本页应有 expect 条却拿不满（含键序行缺失、内容被删/改属） → null
     *   任何一条不过就退回**旧的 relationships 驱动**（慢但保证逐行等价），绝不端出可疑数据。
     *
     * 关于「本术语最新一项没变」这条更强的守卫：**故意不放在热路径**（实测代价）
     *   —— 它要从全站最新倒序逐行探测归属直到命中本术语：唐/诗/陆游这类「最新项就在全站头部」
     *   的术语只要 0.0006ms，但「康熙字典」（最新项停在 2026-08-13 00:00:00）要 **3.7s**、
     *   「王世贞」要 **0.45s** —— 放进热路径会把本次优化直接吃回去。
     *   改由 CLI 承担：state 里存了 newest_at/newest_id/oldest_at/oldest_id，
     *   `tools/gushu-meta-page-bounds.php --check`（轻）/`--deep-check`（含端点探测）判定并重建。
     * 残留风险（如实记录）：**只改 published_at 而不新增内容**的批量回填不会改变
     *   ye_metas.count / MAX(id) ⇒ 守卫察觉不到，需在回填后重跑一次构建（见该脚本头部注释），
     *   与既有 ye_archive_counts 的运维口径一致。
     */
    private static function metaPageBoundsReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (int)Db::one(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
                [Db::t('meta_page_bounds')]
            ) > 0;
        }
        return $ok;
    }

    /** 全站内容插入水位（MAX(PK) 是一次索引定位，O(1)），请求内 memo */
    private static function contentsMaxId(): int
    {
        static $m = null;
        if ($m === null) { $m = (int)Db::one('SELECT COALESCE(MAX(id),0) FROM ' . Db::t('contents')); }
        return $m;
    }

    /**
     * 键序取本页 id（补丁 11 主力路径）。成功返回 id 数组；任何不确定返回 null 让调用方走旧路径。
     * 只支持 desc 序（本站在跑的序；ASC 需另存一份升序键序，未做 → asc 直接返回 null）。
     * 分层：① 逐项键序（step=1）精确取键 → ② 稀疏键序退化为双边窗口 seek → ③ null（旧路径）。
     */
    private static function metaPageIdsByBounds(int $metaId, int $total, int $limit, int $offset): ?array
    {
        if ($offset <= 0 || $limit <= 0 || $total <= 0 || !self::metaPageBoundsReady()) { return null; }
        $st = Db::row(
            'SELECT step, meta_count, item_count, oldest_at, oldest_id, content_max_id FROM ' . Db::t('meta_page_state') . ' WHERE meta_id=? LIMIT 1',
            [$metaId]
        );
        if ($st === null) { return null; }
        $step      = (int)$st['step'];
        $itemCount = (int)$st['item_count'];
        if ($step < 1 || $itemCount < 1) { return null; }
        // ② 规模守卫（快照比对，非「期望=真实」）
        if ($total !== (int)$st['meta_count'] && $total !== $itemCount) { return null; }
        // ③ 插入水位守卫
        if (self::contentsMaxId() !== (int)$st['content_max_id']) { return null; }
        // 越过末尾 → 空页（与旧路径一致：OFFSET 超过命中行数返回空）
        if ($offset >= $itemCount) { return []; }
        $expect = min($limit, $itemCount - $offset);   // 本页在真实全序里应有的条数

        // ① 逐项键序：直接取本页每一项的键（不"找"键，故不受索引空档影响）
        if ($step === 1) {
            $keys = Db::rows(
                'SELECT published_at, content_id FROM ' . Db::t('meta_page_bounds')
                . ' WHERE meta_id=? AND seq>=? AND seq<? ORDER BY seq LIMIT ' . (int)$limit,
                [$metaId, $offset, $offset + $limit]
            );
            if (count($keys) === $expect) {
                $ids = [];
                foreach ($keys as $k) { $ids[] = (int)$k['content_id']; }
                // 回表 + 复核归属/状态（内容被删或改属 → 行数不足 → 交给 ④ 降级）
                $in = implode(',', array_fill(0, count($ids), '?'));
                $got = array_map('intval', array_column(Db::rows(
                    'SELECT c.id FROM ' . Db::t('contents') . ' c STRAIGHT_JOIN ' . Db::t('relationships') . ' r ON r.content_id=c.id'
                    . ' WHERE c.id IN (' . $in . ') AND r.meta_id=? AND ' . self::postWhereC()
                    . ' ORDER BY c.' . self::publishAt() . ' DESC, c.id DESC LIMIT ' . (int)$limit,
                    array_merge($ids, [$metaId])
                ), 'id'));
                // ④ 结果守卫
                if (count($got) === $expect) { return $got; }
                return null;
            }
            return null;
        }

        // ② 稀疏键序（step>1）：双边窗口 seek 兜底
        return self::metaPageIdsSeek($metaId, $st, $limit, $offset, $itemCount);
    }

    /**
     * 稀疏粒度下的双边窗口 keyset seek（补丁 11 兜底路径；step=1 时不会被走到）。
     * 已实测其在「页内相邻两项跨大索引空档」时会退化（见上方注释）——这正是主力路径改为逐项键序的原因。
     */
    private static function metaPageIdsSeek(int $metaId, array $st, int $limit, int $offset, int $itemCount): ?array
    {
        $step = (int)$st['step'];
        if ($step < 1) { return null; }
        if ($offset >= $itemCount) { return []; }
        $seq0 = intdiv($offset, $step) * $step;
        if ($seq0 >= $itemCount) { return null; }
        $bd = Db::row(
            'SELECT published_at, content_id FROM ' . Db::t('meta_page_bounds') . ' WHERE meta_id=? AND seq=? LIMIT 1',
            [$metaId, $seq0]
        );
        if ($bd === null) { return null; }
        // 下界（窗口右端）：本页最后一个所需项的下一档边界；末页用本术语最老一项（含）
        $seq1  = (int)(ceil(($offset + $limit) / $step) * $step);
        $loIn  = false;
        if ($seq1 < $itemCount) {
            $bd1 = Db::row(
                'SELECT published_at, content_id FROM ' . Db::t('meta_page_bounds') . ' WHERE meta_id=? AND seq=? LIMIT 1',
                [$metaId, $seq1]
            );
            if ($bd1 === null) { return null; }
            $loAt = (string)$bd1['published_at'];
            $loId = (int)$bd1['content_id'];
        } else {
            $loAt = (string)$st['oldest_at'];
            $loId = (int)$st['oldest_id'];
            $loIn = true;
        }
        $skip = $offset - $seq0;
        // 把请求量夹到「窗口内真实存在的条数」——**这一步是快的必要条件**（实测教训）：
        //    窗口里只有 avail 条，若还请求 LIMIT n OFFSET skip 且 skip+n > avail，
        //    MySQL 凑不满就不会停，会一路扫到索引范围尽头：
        //    实测 /tag/dyn-1/page/14594/（窗口 184 条、skip 180、limit 10）→ 从 0.03s 爆到 2.4s
        //    （Handler 计数证明：OFFSET 0 时只读 10 行、OFFSET 180 时读完整个 range）。
        $avail = ($seq1 < $itemCount ? $seq1 : $itemCount) - $seq0;
        if ($avail <= $skip) { return null; }
        $effLimit = min($limit, $avail - $skip);
        $expect   = min($limit, $itemCount - $offset);   // 本页在真实全序里应有的条数
        $P = 'c.' . self::publishAt();
        $ids = array_map('intval', array_column(Db::rows(
            'SELECT c.id FROM ' . Db::t('contents') . ' c' . self::pubOrderHint()
            . ' STRAIGHT_JOIN ' . Db::t('relationships') . ' r ON r.content_id=c.id'
            . ' WHERE ' . self::postWhereC() . ' AND r.meta_id=?'
            . ' AND (' . $P . ' < ? OR (' . $P . ' = ? AND c.id <= ?))'
            . ' AND (' . $P . ' > ? OR (' . $P . ' = ? AND c.id ' . ($loIn ? '>=' : '>') . ' ?))'
            . ' ORDER BY ' . $P . ' DESC, c.id DESC'
            . ' LIMIT ' . (int)$effLimit . ' OFFSET ' . (int)$skip,
            [$metaId,
             (string)$bd['published_at'], (string)$bd['published_at'], (int)$bd['content_id'],
             $loAt, $loAt, $loId]
        ), 'id'));
        // ④ 结果守卫：本该有 expect 条却拿不满 → 边界已失真 → 退回旧路径
        if (count($ids) < $expect) { return null; }
        return $ids;
    }

    /**
     * 术语列表的本页 id：按术语规模三选一（三条路径结果与排序语义一致）。
     *  · 小术语（count ≤ 15000）：relationships(meta_id 索引) 驱动 + 跨表排序。命中行少，filesort 很便宜。
     *  · 大术语（如「唐」145,934、「诗」887,517）且**浅偏移**：从 contents 取候选窗口再按归属过滤：
     *    命中占比高（≥11%）时窗口很快凑够本页条数，且窗口按 c.id DESC 截断与 p.id DESC 同序（见译文侧注释）。
     *    窗口不足本页所需时按 8 倍放大重试，上限 8192。
     *  · 大术语且**深偏移**（窗口注定装不下）：补丁 11 的键序预计算表（O(limit)、与偏移无关）；
     *    表不可用/过期时返回 null → 落回 relationships 驱动（旧行为，逐行等价）。
     */
    private static function metaPageIds(string $type, int $metaId, int $total, int $limit, int $offset, bool $asc): array
    {
        $dir   = $asc ? 'ASC' : 'DESC';
        $dirId = $asc ? 'ASC' : 'DESC';
        $relSql = 'SELECT r.content_id AS id FROM ' . Db::t('relationships') . ' r STRAIGHT_JOIN ' . Db::t('contents') . ' c ON c.id=r.content_id '
            . 'WHERE r.meta_id=? AND ' . self::postWhere()
            . ' ORDER BY ' . self::publishAt() . ' ' . $dir . ', c.id ' . $dirId . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        // 补丁 7-B：窗口注定承载不了本页时不再做注定短页的重试，直接落到下面的 relationships 驱动
        if ($total > 15000 && self::windowCanCarry($limit + $offset, $total)) {
            $win = max(64, min(4096, ($limit + $offset) * 8));
            for ($i = 0; $i < 3; $i++) {
                $cand = array_map('intval', array_column(Db::rows(
                    'SELECT id FROM ' . Db::t('contents') . self::pubOrderHint() . ' WHERE ' . self::postWhere()
                    . ' ORDER BY ' . self::publishAt() . ' ' . $dir . ', id ' . $dirId . ' LIMIT ' . $win
                ), 'id'));
                if (!$cand) { break; }
                $ids = array_map('intval', array_column(Db::rows(
                    'SELECT c.id FROM ' . Db::t('contents') . ' c STRAIGHT_JOIN ' . Db::t('relationships') . ' r ON r.content_id=c.id'
                    . ' WHERE r.meta_id=? AND c.id IN (' . implode(',', array_fill(0, count($cand), '?')) . ')'
                    . ' ORDER BY c.' . self::publishAt() . ' ' . $dir . ', c.id ' . $dirId
                    . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
                    array_merge([$metaId], $cand)
                ), 'id'));
                // 窗口是全局序的前缀，故返回的行一定正确；只有当窗口内命中数不足「本页 + 偏移」时
                // 才会短页 —— 此时退回 relationships 驱动，保证深翻页与旧行为逐行一致。
                if (count($ids) >= $limit) { return $ids; }
                if (count($cand) < $win || $win >= 8192) { break; }
                $win = min(8192, $win * 8);
            }
        }
        // 补丁 11：窗口撑不住本页（深偏移）时，改走「键序预计算表」——
        // 逐项键序（step=1）直接取本页每一项的精确键，实测 0.7~4ms、EXPLAIN type=range key=PRIMARY rows=N。
        // asc 序、表不可用/过期、结果可疑 → 全部返回 null 走下面原路径。
        if (!$asc) {
            $byBounds = self::metaPageIdsByBounds($metaId, (int)$total, (int)$limit, (int)$offset);
            if ($byBounds !== null) { return $byBounds; }
        }
        return array_map('intval', array_column(Db::rows($relSql, [$metaId]), 'id'));
    }

    /** 按候选 id 回表取译文行；小结果集内按原语义 (published_at DESC, p.id DESC) 排序。
     *  刻意**不取 p.content**：列表页只需摘要 + 字数，而主题 gsh_words() 在行里没有 words 列时会
     *  回退去正文里数字数（正则跑 16MB 正文 = 秒级）——这正是 /tw/archives/2026/08/ 3.4s 的真凶。
     *  带上 c.words（入库持久化列，与 zh 列表路径 rowsByIds() 同一数据契约）后 3.4s → 0.2s。 */
    private static function polyRowsByIds(string $lang, array $ids, int $limit, int $offset, string $polyExtra = ''): array
    {
        if (!$ids) { return []; }
        return Db::rows(
            'SELECT p.title, p.slug, p.excerpt, p.updated_at, c.views, c.published_at, c.words'
            . ' FROM ' . Poly::table() . ' p JOIN ' . Db::t('contents') . ' c ON c.id=p.group_id'
            . " WHERE p.lang=? AND p.status='publish' AND c.type='post' AND c.status='publish' AND c.deleted_at IS NULL"
            . ' AND c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            . ($polyExtra !== '' ? ' AND ' . $polyExtra : '')
            . ' ORDER BY c.published_at DESC, p.id DESC'
            . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
            array_merge([$lang], $ids)
        );
    }

    /** 两步取数主入口：候选窗口按 8 倍递增，最多 3 轮（译文稀疏/大量候选被 JSON 过滤时） */
    private static function polyPageTwoStep(string $lang, int $limit, int $offset, string $polyExtra = ''): array
    {
        $win  = max(64, min(4096, ($limit + $offset) * 8));
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $ids = self::candidateIds($win);
            if (!$ids) { return []; }
            $rows = self::polyRowsByIds($lang, $ids, $limit, $offset, $polyExtra);
            if (count($rows) >= $limit || count($ids) < $win || $win >= 8192) { break; }
            $win = min(8192, $win * 8);
        }
        return $rows;
    }

    /**
     * 术语维度（分类/标签）译文列表：候选窗口两步取数。
     * 两个都不能用的老写法：
     *   · `FROM poly p JOIN contents c ... AND JSON_CONTAINS(p.cat_ids/tag_ids, mid) ORDER BY c.published_at DESC`
     *     —— 139k 行 JSON 解析 + 跨表排序，单条 COUNT 实测 6.8s、SELECT 被 max_execution_time 杀掉
     *        （/tw/category/、/tw/tag/<slug>/ HTTP 500）。
     *   · `FROM relationships r STRAIGHT_JOIN contents c STRAIGHT_JOIN poly p ... ORDER BY c.published_at DESC`
     *     —— 「唐」有 95,003 条关联，95k 行跨表 filesort 实测 1.97s。
     * 现改法：① 从 ye_contents 按 (published_at DESC, id DESC) 走索引取候选 id（窄列、不拖正文、索引有序）；
     *         ② 回表 join 译文 + 该术语的 relationships 归属，只在小结果集内按原语义排序；
     *         ③ 命中不足本页所需时按 8 倍放大候选窗口重试（小类目/稀疏术语）。
     * 归属判定用 ye_relationships（基准分类）：实测与译文侧 cat_ids/tag_ids JSON 判定集合完全一致
     * （meta 59 双向 diff 均为 0），且这是唯一能覆盖「只挂在基准内容上的标签」（如新增的 存目）的口径。
     * 排序键一致性依据：ye_poly_contents.id 与 group_id 同序（top 3000 互查错位 0 条），
     * 故候选窗口按 c.id DESC 截断 == 原 ORDER BY p.id DESC 截断。
     */
    private static function polyMetaPage(string $lang, int $metaId, int $limit, int $offset): array
    {
        $want = $limit + $offset;
        $win  = max(64, min(4096, $want * 8));
        for ($i = 0; $i < 3; $i++) {
            $ids = self::candidateIds($win);
            if (!$ids) { break; }
            $rows = Db::rows(
                'SELECT p.title, p.slug, p.excerpt, p.updated_at, c.views, c.published_at, c.words'
                . ' FROM ' . Db::t('contents') . ' c'
                . ' STRAIGHT_JOIN ' . Poly::table() . ' p ON p.group_id=c.id'
                . ' STRAIGHT_JOIN ' . Db::t('relationships') . ' r ON r.content_id=c.id'
                . " WHERE p.lang=? AND p.status='publish' AND r.meta_id=? AND " . self::postWhereC()
                . ' AND c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
                . ' ORDER BY c.published_at DESC, p.id DESC'
                . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
                array_merge([$lang, $metaId], $ids)
            );
            if (count($rows) >= $limit) { return $rows; }
            // 窗口是全局序的前缀，返回的行一定正确；只有窗口内命中数不足「本页 + 偏移」时才短页。
            // 例如「存目」5,584 部的 published_at 集中在 2026-08-13，而最新候选窗口里几乎没有它们。
            if (count($ids) < $win || $win >= 8192 || !$rows) { break; }
            $win = min(8192, $win * 8);
        }
        // 退回 relationships 驱动（旧行为）：命中稀疏/时间久远的术语靠这条保证与原来逐行一致。
        return Db::rows(
            'SELECT p.title, p.slug, p.excerpt, p.updated_at, c.views, c.published_at, c.words'
            . ' FROM ' . Db::t('relationships') . ' r'
            . ' STRAIGHT_JOIN ' . Db::t('contents') . ' c ON c.id=r.content_id'
            . ' STRAIGHT_JOIN ' . Poly::table() . ' p ON p.group_id=c.id'
            . " WHERE r.meta_id=? AND p.lang=? AND p.status='publish' AND " . self::postWhereC()
            . ' ORDER BY c.' . self::publishAt() . ' DESC, p.id DESC'
            . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
            [$metaId, $lang]
        );
    }

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
        // D10：列表不带正文（字数走 ye_contents.words）
        $ids = array_map('intval', array_column(Db::rows(
            'SELECT id FROM ' . Db::t('contents') . ' WHERE ' . self::postWhere()
            . ' ORDER BY ' . self::publishAt() . ' DESC, id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset
        ), 'id'));
        $rows = self::rowsByIds($ids);
        $total = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('contents') . ' WHERE ' . self::postWhere());
        $ctKey = 'home_total_v1';
        $ctC = Cache::get('misc', $ctKey);
        if ($ctC !== false) { $total = (int)$ctC; } else { Cache::set('misc', $ctKey, (string)$total, 600); }
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
            // ⚠️ 性能（补丁 10）：原写法把「同戳用 id 决序」写成
            //   `WHERE (published_at < ? OR (published_at = ? AND id < ?)) ORDER BY published_at DESC, id DESC LIMIT 1`
            // → OR 让索引无法定位起点，MySQL 只能 ref 扫完 (type,status,deleted_at) 整段：
            //   EXPLAIN rows=1,035,145、单发实测 12,754ms（爬虫风暴 13 并发时把 mysqld 打到 157% CPU）。
            //   ⚠️ 只加「冗余上界 published_at <= ?」**实测无效**（仍是 ref/rows=1,035,145，甚至慢到 14,085ms）。
            // 改为拆成两条**都能走索引定位**的查询，按「同戳更近者优先」合并 —— 与 OR 写法**语义完全等价**：
            //   ① published_at = X AND id < N → 等值+id 范围，直接定位（实测 6.24ms）
            //   ② published_at < X            → 纯范围+索引有序，反向扫第一条即停（实测 1.09ms）
            // DESC 序里「同戳且 id 更小」一定排在「更早时间戳」之前 ⇒ ①非空就必须取①。
            $nextRow = Db::row(
                "SELECT id, title, slug FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL "
                . 'AND published_at = ? AND id < ? ORDER BY id DESC LIMIT 1',
                [$pub, $cid]
            );
            if ($nextRow === null) {
                $nextRow = Db::row(
                    "SELECT id, title, slug FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL "
                    . 'AND published_at < ? ORDER BY published_at DESC, id DESC LIMIT 1',
                    [$pub]
                );
            }
            // 上一篇对称：同戳内 id 更大者优先，没有才退到「更晚时间戳」里的最早一条（正向扫第一条即停）
            $prevRow = Db::row(
                "SELECT id, title, slug FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL "
                . 'AND published_at = ? AND id > ? ORDER BY id ASC LIMIT 1',
                [$pub, $cid]
            );
            if ($prevRow === null) {
                $prevRow = Db::row(
                    "SELECT id, title, slug FROM {$T} WHERE type='post' AND status='publish' AND deleted_at IS NULL "
                    . 'AND published_at > ? ORDER BY published_at ASC, id ASC LIMIT 1',
                    [$pub]
                );
            }
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
        $total = self::polyCount($lang);
        $rows = self::polyPageTwoStep($lang, $limit, $offset);
        $posts = [];
        foreach ($rows as $r) {
            $posts[] = [
                'id' => 0, 'title' => $r['title'], 'slug' => $r['slug'],
                'excerpt' => $r['excerpt'], 'content' => '',
                'words' => (int)($r['words'] ?? 0),
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
        $withBody = false; // 列表不带正文：字数走 ye_contents.words 持久化列
        $limit = self::perPage();
        $offset = ($page - 1) * $limit;
        // 栏目内页顺序：站点可设 xuecha.list_asc=1 → 最老在上（ASC）；默认最新在前（DESC），不设不影响其它站
        $asc = $type === 'category' && (string)get_option('xuecha.list_asc', '0') === '1';
        $dir  = $asc ? 'ASC' : 'DESC';
        $dirId = $asc ? 'ASC' : 'DESC';
        // D7：一次 JOIN 分页查询（去掉“先 LIMIT 1000 取 id”造成的深翻页截断）；
        // STRAIGHT_JOIN 强制从 relationships(meta_id 索引) 驱动，避免优化器在大表下先全扫 contents
        //
        // D10（全量古籍入库后追加）：原写法 `SELECT c.* … ORDER BY published_at DESC LIMIT 20`
        // 必须把该栏全部命中行**连同 LONGTEXT 正文**物化后再排序取前 20。
        // 「子部」有 4,006 部书、正文合计 3.5GB，实测这一条 SQL 要 11 秒。
        // 改为两步：先用只含窄列的查询拿本页 20 个 id（filesort 不再搬正文），再按 id 取行。
        // 排序语义与分页偏移完全不变，能正常深翻页。
        // ⚠️ 顺序敏感：$total 必须在本段（计数块）里算好，下面 metaPageIds() 立刻要用它 ——
        // 它决定大术语(>15000，如 诗 105,378 / 唐 95,003)走「窗口化」快路径还是 relationships 驱动慢路径。
        // 本段曾被挪到 metaPageIds() 之后 → $total 未定义((int)null=0) → 快路径永久失效（/tag/dyn-1/ 冷 3.4s）。
        // 见 themes/gushu/CORE-PATCH.md「补丁 5」。
        // 计数（补丁 9）：优先读 ye_metas.count —— 主键单行、O(1)，该列由导入线复算维护。
        // 实测三种取法（术语「诗」三法都得 843,621；「宋」该列仅比真值多 2 行 / 0.0006%）：
        //   · 优化器自选：从 contents 侧驱动 idx_contents_arch 覆盖索引 1,323,182 行 → **3,722.5 ms**
        //   · STRAIGHT_JOIN 从 relationships 驱动 → **3,134.7 ms**（不再从 contents 全扫，但仍旧慢）
        //   · 读 ye_metas.count → **0.2 ms** ✓
        // 故 >0 时直接用该列；为 0 时才回退到关系表 COUNT，且**必须 STRAIGHT_JOIN**
        // （回退路径对「确实为空」的术语是瞬时的：0 条关系 → 空索引探测）。
        $cntMeta = (int)($meta['count'] ?? 0);
        if ($cntMeta > 0) {
            $total = $cntMeta;
        } else {
            $ctKey = 'mlcount:' . $type . ':' . (int)$meta['id'];
            $ctC = Cache::get('misc', $ctKey);
            if ($ctC !== false) {
                $total = (int)$ctC;
            } else {
                $total = (int)Db::one(
                    'SELECT COUNT(*) FROM ' . Db::t('relationships') . ' r STRAIGHT_JOIN ' . Db::t('contents') . ' c ON c.id=r.content_id WHERE r.meta_id=? AND ' . self::postWhereC(),
                    [$meta['id']]
                );
                Cache::set('misc', $ctKey, (string)$total, 600);
            }
        }
        $ids = self::metaPageIds($type, (int)$meta['id'], (int)$total, $limit, $offset, $asc);
        $rows = self::rowsByIds($ids, $withBody);
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

    /* -----------------------------------------------------------------
     * 归档年月计数：预计算表 ye_archive_counts + 优雅降级
     * -----------------------------------------------------------------
     * 现算那条 GROUP BY 要扫两遍 139k 索引项（contents 侧 + poly 侧探测）。计划本身已是最优的
     * 纯覆盖索引扫描，但 buffer pool 只有 2GB、库 7.56GB —— 一旦缓存被内容导入清掉，这些索引页
     * 就得从磁盘重读，/tw/archives/ 冷首击实测 1.6~4.8s。
     * 预计算把这个聚合固化成 2~20 行，读一次是毫秒级：
     *   · 表不存在 / 无该语言行 / 超过 TTL 未刷新 → **返回 null，调用方退回现算**（优雅降级）；
     *   · 现算完顺手回写（惰性刷新）→ 不需要内容写入钩子，也永远不会把过期数据端给用户
     *     （过期的那一次请求自己现算并返回新值，只是顺便把表刷新了）；
     *   · 也可由 tools/gushu-archive-counts.php / cron 主动刷新（见该脚本注释）。
     */
    private static function archiveTable(): string { return Db::t('archive_counts'); }

    /** 预计算表是否存在（一次请求只探测一次；表被 DROP 过也不会报错） */
    private static function archiveTableReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = (int)Db::one(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
                    [self::archiveTable()]
                ) > 0;
            } catch (Throwable $e) { $ok = false; }
        }
        return $ok;
    }

    /** 读预计算表（原始 int 的 y/m/n）；不可用或已过期返回 null */
    private static function archiveCountsPre(string $lang, int $ttl = 600): ?array
    {
        if (!self::archiveTableReady()) { return null; }
        try {
            $fresh = (int)Db::one(
                'SELECT COUNT(*) FROM ' . self::archiveTable() . ' WHERE lang=? AND refreshed_at >= (NOW() - INTERVAL ? SECOND)',
                [$lang, max(0, $ttl)]
            );
            if ($fresh <= 0) { return null; }
            return Db::rows(
                'SELECT y, m, n FROM ' . self::archiveTable() . ' WHERE lang=? ORDER BY y DESC, m DESC',
                [$lang]
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    /** 某年月的归档条数（$m=0 表示整年，对预计算表求和）；表不可用/已过期 → null（调用方退回原行为） */
    private static function archiveCount(string $lang, int $y, int $m): ?int
    {
        $rows = self::archiveCountsPre($lang, 600);
        if ($rows === null) { return null; }
        $n = 0;
        foreach ($rows as $r) {
            if ((int)$r['y'] !== $y) { continue; }
            if ($m > 0 && (int)$r['m'] !== $m) { continue; }
            $n += (int)$r['n'];
        }
        return $n;
    }
    /** 现算某语言的年月计数（不写库）：默认语言走 contents 覆盖索引，其它语言覆盖探测 poly */
    private static function archiveCountsCompute(string $lang): array
    {
        if ($lang === Poly::defaultLang()) {
            return Db::rows(
                'SELECT pub_y y, pub_m m, COUNT(*) n FROM ' . Db::t('contents')
                . ' WHERE ' . self::postWhere() . ' AND pub_y IS NOT NULL'
                . ' GROUP BY pub_y, pub_m ORDER BY pub_y DESC, pub_m DESC'
            );
        }
        return Db::rows(
            'SELECT c.pub_y y, c.pub_m m, COUNT(*) n FROM ' . Db::t('contents') . ' c STRAIGHT_JOIN ' . Poly::table() . ' p' . self::polyStHint()
            . " ON p.group_id=c.id AND p.lang=? AND p.status='publish'"
            . ' WHERE ' . self::postWhereC() . ' AND c.pub_y IS NOT NULL'
            . ' GROUP BY c.pub_y, c.pub_m ORDER BY c.pub_y DESC, c.pub_m DESC',
            [$lang]
        );
    }

    /** 把一组 (y,m,n) 整表替换写入预计算表（月份可能消失，所以先删该语言全部行）；失败静默返回 0 */
    public static function archiveCountsStore(string $lang, array $rows): int
    {
        if (!self::archiveTableReady()) { return 0; }
        try {
            $pdo = Db::pdo();
            $pdo->beginTransaction();
            Db::run('DELETE FROM ' . self::archiveTable() . ' WHERE lang=?', [$lang]);
            $st = $pdo->prepare('INSERT INTO ' . self::archiveTable() . ' (lang, y, m, n) VALUES (?,?,?,?)');
            foreach ($rows as $r) {
                $st->execute([$lang, (int)$r['y'], (int)$r['m'], (int)$r['n']]);
            }
            $pdo->commit();
            return count($rows);
        } catch (Throwable $e) {
            try { if (Db::pdo()->inTransaction()) { Db::pdo()->rollBack(); } } catch (Throwable $e2) {}
            return 0;
        }
    }

    /** 现算 + 回写（tools/gushu-archive-counts.php 与 cron 用） */
    public static function archiveCountsRebuild(string $lang): int
    {
        return self::archiveCountsStore($lang, self::archiveCountsCompute($lang));
    }

    /** 归档年月计数（供两个 archivesOverview 共用）：先预计算表，降级现算并回写 */
    private static function archiveMonths(string $lang): array
    {
        $rows = self::archiveCountsPre($lang);
        if ($rows === null) {
            $rows = self::archiveCountsCompute($lang);
            self::archiveCountsStore($lang, $rows); // 惰性刷新；失败不影响出参
        }
        return $rows;
    }

    public static function archivesOverview(): array
    {
        if (Poly::on() && Lang::short() !== Poly::defaultLang()) { return self::enArchivesOverview(); }
        $rows = self::archiveMonths(Poly::defaultLang());
        return ['title' => sprintf(__('tt.arch_of'), site_label()), 'page_title' => __('tt.arch'), 'months' => $rows];
    }

    /* -----------------------------------------------------------------
     * 归档分页（CORE-PATCH 补丁 11，2026-09-13）
     * -----------------------------------------------------------------
     * 改前：`LIMIT 200` 写死、`page` 参数根本不存在（Router 不解析 page 段）→
     *   · `/archives/2026/08/` 页面上没有任何分页控件，1,099,439 条里只有前 200 条可点；
     *   · `/archives/2026/08/page/2/` 虽然 200 OK，返回的却是**第 1 页的同一批 200 条**
     *     （重复内容，且主题自算的 canonical 还自指该 URL）。
     * 改法（**最小**）：查询形状一字未动（含补丁 7-A 的「按年月密度二选一 hint」判断），
     *   只把写死的 LIMIT 200 换成 `LIMIT 200 OFFSET (page-1)*200`，并把真实总数交给视图。
     *   page=1 时 SQL 与改前**逐字节相同**。
     * 总数来源（按优先级）：
     *   ① `ye_archive_counts`（预计算表，见本文件上方说明）——毫秒级，与总览页同源；
     *   ② 表不可用/已过期 → 覆盖索引区间 COUNT（`published_at` 半开区间，
     *      实测与 `YEAR()/MONTH()` 逐值相同：全表 1,099,608 行 pub_y/pub_m 与
     *      YEAR/MONTH(published_at) **100% 一致**，published_at 无 NULL），结果缓存 600s。
     *   ⚠️ **绝不**把本页条数当总数（这正是 P0-2 的成因）。
     */
    public static function archivesList(int $y, int $m, int $page = 1): array
    {
        if (Poly::on() && Lang::short() !== Poly::defaultLang()) { return self::enArchivesList($y, $m, $page); }
        // D10：与 metaList 同理——YEAR()/MONTH() 用不上索引，若直接 SELECT * 会把全表
        // 数十 MB 的正文一起拖进 filesort。先取 id 再回表。
        // 计划选择（补丁 7-A，实测依据见 themes/gushu/CORE-PATCH.md）：
        //   idx_c_ts_del_pub 的有序扫描是「按 published_at 倒序扫、再按年月过滤」，只有**该年月很密**时才便宜；
        //   年月为空/很小时它反而要把整段索引扫完（/archives/2025/ 实测 2.12ms → 463.20ms ✗），
        //   而既有 idx_c_arch_cover_ts 自带 (pub_y,pub_m) 选择性过滤，正是为空/小年月准备的。
        //   故按预计算表（ye_archive_counts，毫秒级、带优雅降级）的年月条数二选一：
        //   >= 8192 条才加提示；否则不加（拿不到计数时也不加 = 与补丁 7 之前完全一致）。
        $per    = self::archivePerPage();
        $page   = max(1, (int)$page);
        $offset = ($page - 1) * $per;
        $archN  = self::archiveCount(Lang::short(), $y, $m);
        $archHint = ($archN !== null && $archN >= 8192) ? self::pubOrderHint() : '';
        $sql = 'SELECT id FROM ' . Db::t('contents') . $archHint . ' WHERE ' . self::postWhere()
            . ' AND YEAR(' . self::publishAt() . ')=?';
        $params = [$y];
        if ($m > 0) { $sql .= ' AND MONTH(' . self::publishAt() . ')=?'; $params[] = $m; }
        $ids = array_map('intval', array_column(Db::rows($sql . ' ORDER BY ' . self::publishAt() . ' DESC, id DESC LIMIT ' . $per . ' OFFSET ' . $offset, $params), 'id'));
        $rows = self::rowsByIds($ids);
        $total = self::archiveTotal(Lang::short(), $y, $m, $archN);
        $title = $m > 0 ? lang_month($y, $m) : (Lang::isEn() ? (string)$y : (string)$y . '年');
        return [
            'title' => $title . ' · ' . site_label(),
            'page_title' => sprintf(__('tt.archive'), $title),
            'posts' => self::attach($rows),
            'page' => $page,
            'total_pages' => max(1, (int)ceil($total / $per)),
            'total_items' => $total,
        ];
    }

    /** 归档每页条数（保持补丁 11 之前的 200，不跟栏目的 per_page 走） */
    private static function archivePerPage(): int
    {
        return 200;
    }

    /**
     * 归档真实总数：预计算表 → 区间 COUNT（缓存 600s）。
     * `$y` 年 `$m` 月（`$m=0` 表示整年）；拿不到时返回 0（视图侧随之不显示分页器，而不是显示本页条数）。
     */
    private static function archiveTotal(string $lang, int $y, int $m, ?int $pre): int
    {
        if ($pre !== null) { return $pre; }
        $ck = 'arcnt:' . $lang . ':' . $y . ':' . $m;
        $c  = Cache::get('misc', $ck);
        if ($c !== false && $c !== '') { return (int)$c; }
        /* 区间口径与 YEAR()/MONTH() 逐值相同（全表 pub_y/pub_m 与 YEAR/MONTH(published_at) 100% 一致），
           但 published_at 的区间能走 idx_c_ts_del_pub 的覆盖区间计数，不必全表扫。 */
        $n = (int)Db::one(
            'SELECT COUNT(*) FROM ' . Db::t('contents') . self::pubOrderHint() . ' WHERE ' . self::postWhere()
            . ' AND ' . self::publishAt() . ' >= ? AND ' . self::publishAt() . ' < ?',
            [self::archiveFrom($y, $m), self::archiveTo($y, $m)]
        );
        Cache::set('misc', $ck, (string)$n, 600);
        return $n;
    }

    /** 归档区间的起点（$m=0 → 该年 1 月 1 日） */
    private static function archiveFrom(int $y, int $m): string
    {
        return $m > 0 ? sprintf('%04d-%02d-01 00:00:00', $y, $m) : sprintf('%04d-01-01 00:00:00', $y);
    }

    /** 归档区间的终点（半开，$m=0 → 次年 1 月 1 日） */
    private static function archiveTo(int $y, int $m): string
    {
        $from = self::archiveFrom($y, $m);
        return date('Y-m-d H:i:s', (int)strtotime($from . ' +1 month'));
    }


    /**
     * 检索。
     *
     * 全量古籍入库后正文 3.2GB，`LIKE '%词%'` 找冷门词时要把聚集索引扫很深，
     * 实测 `/search?q=山海经` 首命中 18 秒，用户会以为卡死。故加两道保护：
     *   ① `SET SESSION max_execution_time`（毫秒）——超时由 MySQL 中断，捕获后降级为「只查标题」；
     *   ② 候选上限 300、展示前 40 条（沿用原行为，但把数字显式告诉用户）。
     * 同时用 Handler_read_* 差值统计**真实扫描行数**，连同耗时一起交给视图展示。
     */
    public static function search(string $q): array
    {
        if (Poly::on() && Lang::short() !== Poly::defaultLang()) { return self::enSearch($q); }
        $rows = [];
        $t0 = microtime(true);
        $h0 = self::handlerReads();
        $timedOut = false;
        $fellBack = false;
        $mode = 'none';
        $candidates = 0;
        if ($q !== '') {
            $terms = Str::terms($q);
            if ($terms) {
                /* 超时上限只箍住检索这两条语句：它写在会话上，
                   若不复位会把同一请求里后续的（侧栏等）查询一起打断 —— 实测踩过，
                   表现为检索页 500 而 Front::search() 本身返回正常。 */
                self::searchTimeout(8000);
                try {
                    // 原顺序：先 LIKE（含正文），无命中再 FULLTEXT
                    $ids = self::likeIds($terms);
                    $mode = 'like';
                    if (!$ids) { $ids = self::ftIds($terms); $mode = 'fulltext'; }
                } catch (Throwable $e) {
                    // 3024 = 超过 max_execution_time
                    $timedOut = true;
                    $ids = [];
                } finally {
                    self::searchTimeout(0);
                }
                if ($timedOut || !$ids) {
                    // 降级：只比标题（不碰 LONGTEXT 正文列），5 秒上限
                    self::searchTimeout(5000);
                    try {
                        $t = self::titleIds($terms);
                        if ($t) {
                            $ids = $t;
                            $fellBack = true;
                            $mode = $timedOut ? 'title_after_timeout' : 'title_no_content_hit';
                            $timedOut = false;
                        }
                    } catch (Throwable $e) {
                        $timedOut = true;
                        $ids = [];
                    } finally {
                        self::searchTimeout(0);
                    }
                }
                $candidates = count($ids);
                if ($ids) {
                    $rows = self::rowsByIds($ids); // 不带正文，防 300 行 LONGTEXT 打爆内存
                    // 相关度排序（标题命中优先）
                    usort($rows, function (array $a, array $b) use ($q): int {
                        return self::rank($b, $q) <=> self::rank($a, $q);
                    });
                    $rows = array_slice($rows, 0, 40);
                }
            }
        }
        $scanned = max(0, self::handlerReads() - $h0);
        return [
            'title' => sprintf(__('tt.search_title'), $q, site_label()),
            'page_title' => sprintf(__('tt.search_pre'), $q),
            'q' => $q,
            'posts' => self::attach($rows),
            'page' => 1, 'total_pages' => 1,
            'stats' => [
                'elapsed' => round(microtime(true) - $t0, 2),
                'scanned' => $scanned,
                'candidates' => $candidates,
                'shown' => count($rows),
                'cap' => 300,
                'mode' => $mode,
                'fell_back' => $fellBack,
                'timed_out' => $timedOut,
            ],
        ];
    }

    /** 会话级语句超时（毫秒，0=不限）；MySQL 5.7.8+ 支持，失败不影响检索 */
    private static function searchTimeout(int $ms): void
    {
        try { Db::run('SET SESSION max_execution_time = ' . max(0, $ms)); } catch (Throwable $e) { /* 忽略 */ }
    }

    /** 已扫描行数：Handler_read_* 计数之和（会话级累计值） */
    private static function handlerReads(): int
    {
        try {
            $rows = Db::rows("SHOW SESSION STATUS WHERE Variable_name IN "
                . "('Handler_read_first','Handler_read_key','Handler_read_next','Handler_read_prev','Handler_read_rnd','Handler_read_rnd_next')");
            $n = 0;
            foreach ($rows as $r) { $n += (int)($r['Value'] ?? 0); }
            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** 只查标题（不触 LONGTEXT），最坏情况也远快于带正文的 LIKE */
    private static function titleIds(array $terms): array
    {
        $where = [];
        $params = [];
        foreach ($terms as $t) {
            $where[] = 'title LIKE ?';
            $params[] = '%' . $t . '%';
        }
        return array_map('intval', array_column(Db::rows(
            'SELECT id FROM ' . Db::t('contents') . ' WHERE ' . self::postWhere()
            . ' AND (' . implode(' OR ', $where) . ') ORDER BY ' . self::publishAt() . ' DESC, id DESC LIMIT 300',
            $params
        ), 'id'));
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
        /* 检索结果行不再带正文（LONGTEXT 单篇可达数十 MB，300 行会把内存吃爆——
           实测 /search?q=山海经 直接 500）。命中判定已由 likeIds/ftIds 完成，
           这里用摘要加权即可，正文命中改由 words 列表征「确为长文」。 */
        $content = (string)($r['content'] ?? $r['excerpt'] ?? '');
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
        $months = Db::rows('SELECT pub_y y, pub_m m, COUNT(*) n FROM ' . Db::t('contents') . ' WHERE ' . self::postWhere() . ' GROUP BY pub_y, pub_m ORDER BY y DESC, m DESC LIMIT 12');
        $data = ['latest' => $latest, 'cats' => $cats, 'tags' => $tags, 'months' => $months];
        Cache::set('blocks', $key, json_encode($data, JSON_UNESCAPED_UNICODE), 300);
        return $data;
    }

    /**
     * /tag/ /category/ 索引：全部含计数，便于导航与 SEO
     *
     * 补丁 12（2026-09-13，体检 P1-5）：**加真实分页**。
     * 改前写死 `LIMIT 500`，而 `ye_metas` 里 `type='tag'` 实测 **39,447** 行（`count>0` 39,215），
     * 标签索引只装得下 500 个 → 其余 38,947 个标签页**没有任何站内入口**
     * （sitemap 却列了 22,430 个 tag URL，站内可达性与提交面严重不匹配）。
     * 分类只有 72 行，仍一页出完（`total_pages=1`，行为与改前一致）。
     * 页码走查询串 `?p=N`（与 `/pages` 既有做法一致），**不新增路由**。
     */
    public static function metaIndex(string $type, int $page = 1): array
    {
        if (Poly::on() && Lang::short() !== Poly::defaultLang()) { return self::enMetaIndex($type, $page); }
        $per   = $type === 'tag' ? 300 : 500;
        $page  = max(1, (int)$page);
        $total = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('metas') . ' WHERE type=?', [$type]);
        $rows = Db::rows(
            'SELECT * FROM ' . Db::t('metas') . ' WHERE type=? ORDER BY `count` DESC, id ASC LIMIT ' . $per
            . ' OFFSET ' . (($page - 1) * $per),
            [$type]
        );
        $label = $type === 'category' ? __('tt.mi_category') : __('tt.mi_tag');
        return [
            'type' => $type,
            'items' => $rows,
            'page' => $page,
            'total_pages' => max(1, (int)ceil($total / $per)),
            'total_items' => $total,
            'per_page' => $per,
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

    public static function enMetaIndex(string $type, int $page = 1): array
    {
        $lang = Lang::short();
        // 原实现给**每个术语**挂一条相关子查询（JSON_CONTAINS 全表扫 ye_poly_contents 139k 行 + join contents）：
        // 单个术语实测就要 1.07s（如 tag 唐 = 95,003 条），13,000+ 个术语必然超过 max_execution_time 被中断
        // → /tw/category/ 与 /tw/tag/ HTTP 500（SQLSTATE 3024）。
        // 改为走 ye_metas 自带的 idx_metas_type_count (type,count)：count 即该术语的关联条数。
        // 译文侧与基准侧计数一致性已实测：诗/词/曲/子部 = 105378/11547/10243/4026、
        // 唐/宋/元 = 95003/22395/10997，与 JSON_CONTAINS 精确计数逐条相同
        // （因 139,321 篇 post 中 139,319 篇都有 tw 译文）。视图取的就是 $it['count']，无需额外列。
        // 补丁 12：与 zh 侧同样加分页（`?p=N`，每页 300，与原来写死的 LIMIT 300 每页条数一致）。
        $per = 300;
        $page = max(1, (int)$page);
        $total = (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('metas') . ' WHERE type=? AND count>0', [$type]);
        $rows = Db::rows(
            'SELECT * FROM ' . Db::t('metas') . ' WHERE type=? AND count>0 ORDER BY count DESC, id ASC LIMIT ' . $per
            . ' OFFSET ' . (($page - 1) * $per),
            [$type]
        );
        foreach ($rows as &$m) {
            $m['cnt'] = (int)$m['count'];            // 兼容旧视图字段名
            $m['name'] = Poly::termName($m, $lang);  // 当前语言名优先，空则回落中文名
        }
        unset($m);
        $label = $type === 'category' ? __('tt.mi_category') : __('tt.mi_tag');
        return [
            'type' => $type,
            'items' => $rows,
            'page' => $page,
            'total_pages' => max(1, (int)ceil($total / $per)),
            'total_items' => $total,
            'per_page' => $per,
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
        $per = self::perPage();
        $page = max(1, (int)$page);
        $offset = ($page - 1) * $per;
        // 总数直接用 ye_metas.count（术语关联条数，走 idx_metas_type_count）：
        // 与译文侧精确计数一致（诗/词/曲/子部 105378/11547/10243/4026、唐/宋/元 95003/22395/10997 逐条相同），
        // 而原来的 COUNT(*) 要 6.8s（JSON_CONTAINS 全表）或 1s+（95k 关联回表）。
        $total = (int)$meta['count'];
        $raw = self::polyMetaPage($lang, (int)$meta['id'], $per, $offset);
        $posts = [];
        foreach ($raw as $r) {
            $posts[] = [
                'id' => 0, 'title' => $r['title'], 'slug' => $r['slug'],
                'excerpt' => (string)($r['excerpt'] ?? ''), 'content' => '',
                'words' => (int)($r['words'] ?? 0),
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
        // 原写法从 poly 侧驱动（139k 行）再 PK 回表 contents 取 pub_y/pub_m —— 139k 次随机聚集索引读，
        // 实测被 max_execution_time=8000 杀掉（/tw/archives/ HTTP 500）。
        // 改为从 contents 走 idx_contents_arch（覆盖索引）驱动，用 idx_poly_group_lang_st 覆盖探测；
        // 年月计数短时缓存 300s。COUNT(*) 安全：ye_poly_contents 的 (group_id,lang) 实测无重复。
        $ck = 'polyarch:' . $lang;
        $hit = Cache::get('blocks', $ck);
        if ($hit !== false) {
            $rows = json_decode($hit, true);
            if (is_array($rows)) { return ['title' => sprintf(__('tt.arch_of'), site_label()), 'page_title' => __('tt.arch'), 'months' => $rows]; }
        }
        // 预计算表优先（毫秒级）；表不可用/过期则现算并回写。
        // 输出形状与旧实现完全一致：y 字符型、m 补零两位（视图侧对 y/m 都做 (int) 强转）。
        $raw = self::archiveMonths($lang);
        $rows = [];
        foreach ($raw as $r) {
            $rows[] = [
                'y' => (string)$r['y'],
                'm' => str_pad((string)(int)$r['m'], 2, '0', STR_PAD_LEFT),
                'n' => (int)$r['n'],
            ];
        }
        Cache::set('blocks', $ck, json_encode($rows, JSON_UNESCAPED_UNICODE), 300);
        return ['title' => sprintf(__('tt.arch_of'), site_label()), 'page_title' => __('tt.arch'), 'months' => $rows];
    }

    public static function enArchivesList(int $y, int $m, int $page = 1): array
    {
        $lang = Lang::short();
        // 两步：先取窄列 id（不 SELECT 正文），再回表取译文行。
        // 原写法 `AND YEAR(c.published_at)=? AND MONTH(c.published_at)=?` 是函数表达式，用不上索引，
        // 要在 13.9 万行上 filesort（/tw/archives/2026/08/ 实测 3.50s，其中取 id 一步 ~2.7s）。
        // 改为 published_at 半开区间 + 走 idx_contents_status(status,published_at) 的有序扫描，命中即停。
        //
        // 补丁 11：加真实分页（原 LIMIT 200 写死、page 被忽略 → /tw/archives/YYYY/MM/page/N/ 是重复页）。
        // 原实现是「先取该月前 200 条 **zh** id，再筛出其中有 tw 译文的」——译文覆盖率约 83%，
        // 于是每页实际只出 ~166 条，且 OFFSET 无法正确推进。现改为**在 id 查询里就做 poly 过滤**
        // （STRAIGHT_JOIN + 有序区间扫描，与 archiveCountsCompute 的 tw 分支同一写法），
        // 每页稳定 200 条。
        $m  = max(1, min(12, $m));
        $per = self::archivePerPage();
        $page = max(1, (int)$page);
        $offset = ($page - 1) * $per;
        $from = self::archiveFrom($y, $m);
        $to   = self::archiveTo($y, $m);
        $join = ' FROM ' . Db::t('contents') . ' c STRAIGHT_JOIN ' . Poly::table() . ' p' . self::polyStHint()
            . " ON p.group_id=c.id AND p.lang=? AND p.status='publish'"
            . ' WHERE ' . self::postWhereC() . ' AND c.published_at >= ? AND c.published_at < ?';
        $total = self::archiveCount($lang, $y, $m);
        if ($total === null) {
            $ck = 'arcnt:' . $lang . ':' . $y . ':' . $m;
            $c  = Cache::get('misc', $ck);
            if ($c !== false && $c !== '') { $total = (int)$c; }
            else {
                $total = (int)Db::one('SELECT COUNT(*)' . $join, [$lang, $from, $to]);
                Cache::set('misc', $ck, (string)$total, 600);
            }
        }
        $ids = array_map('intval', array_column(Db::rows(
            'SELECT c.id' . $join . ' ORDER BY c.published_at DESC, c.id DESC LIMIT ' . $per . ' OFFSET ' . $offset,
            [$lang, $from, $to]
        ), 'id'));
        $rows = self::polyRowsByIds($lang, $ids, $per, 0);
        $posts = [];
        foreach ($rows as $r) {
            $posts[] = [
                'id' => 0, 'title' => $r['title'], 'slug' => $r['slug'],
                'excerpt' => (string)($r['excerpt'] ?? ''), 'content' => '',
                'words' => (int)($r['words'] ?? 0),
                'views' => $r['views'], 'published_at' => $r['published_at'], 'created_at' => $r['published_at'],
                'cats' => [], 'tags' => [],
            ];
        }
        $title = lang_month($y, $m);
        return [
            'title' => $title . ' · ' . site_label(),
            'page_title' => sprintf(__('tt.archive'), $title),
            'posts' => $posts,
            'page' => $page,
            'total_pages' => max(1, (int)ceil($total / $per)),
            'total_items' => $total,
        ];
    }

    public static function enSearch(string $q): array
    {
        $lang = Lang::short();
        $posts = [];
        $terms = $q !== '' ? Str::terms($q) : [];
        $hitRows = null;
        $ck = 'polysearch:' . $lang . ':' . md5($q);
        if ($terms) {
            $cached = Cache::get('blocks', $ck);
            if ($cached !== false) {
                $dec = json_decode($cached, true);
                if (is_array($dec)) { $hitRows = $dec; }
            }
        }
        if ($terms && $hitRows === null) {
            $hitRows = [];
            /*
             * 原写法 `(p.title LIKE ? OR p.content LIKE ?)` + `ORDER BY c.published_at DESC`：
             *   · p.content 是 LONGTEXT（全表 3.5GB），LIKE '%词%' 必须把 139k 行的正文整列读出来；
             *   · 排序键跨 poly/contents 两表，优化器只能 139k 行 join + filesort。
             * 两条叠加实测 8s 被 max_execution_time 中断（/tw/search HTTP 500，SQLSTATE 3024）。
             * 现改为「标题索引扫描 + 两步取数」，全程不碰正文：
             *   ① 只取 p.id，走新增覆盖索引 idx_poly_lang_status_title(lang,status,title)，
             *      LIKE 在索引内完成，不回表、不读 LONGTEXT（实测 56~115ms）；
             *   ② 命中 id（≤300，与展示上限一致）回表 join contents 拿发布时间排序，窄列；
             *   ③ 按标题相关度排序取前 40，最后才为这 40 条回表取正文（沿用原 D8 的省 IO 思路）。
             * 语义变化：译文检索只匹配**标题**。正文级 LIKE 在本库量级下不可能达标；
             * 这与 zh 侧 search() 的「正文 LIKE 超时→降级只查标题」兜底后的行为一致。
             * 另加会话级 5s 上限 + 异常兜底：即使以后数据再涨，检索页也不会 500。
             */
            self::searchTimeout(5000);
            try {
                $where = [];
                $params = [$lang];
                foreach ($terms as $t) {
                    $where[] = 'p.title LIKE ?';
                    $params[] = '%' . $t . '%';
                }
                $matchSql = '(' . implode(' OR ', $where) . ')';
                $ids = array_map('intval', array_column(Db::rows(
                    'SELECT p.id FROM ' . Poly::table() . ' p'
                    . " WHERE p.lang=? AND p.status='publish' AND " . $matchSql . ' LIMIT 300',
                    $params
                ), 'id'));
                if ($ids) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $hitRows = Db::rows(
                        'SELECT p.id, p.slug, p.title, p.excerpt, p.updated_at, c.views, c.published_at, c.words'
                        . ' FROM ' . Poly::table() . ' p'
                        . ' STRAIGHT_JOIN ' . Db::t('contents') . ' c ON c.id=p.group_id'
                        . " WHERE p.id IN ($in) AND p.status='publish' AND " . self::postWhereC()
                        . ' ORDER BY c.published_at DESC, p.id DESC LIMIT 300',
                        $ids
                    );
                }
            } catch (Throwable $e) {
                // 3024 = 超过 max_execution_time：降级为空结果，不让检索页 500
                $hitRows = [];
            } finally {
                self::searchTimeout(0);
            }
            Cache::set('blocks', $ck, json_encode($hitRows, JSON_UNESCAPED_UNICODE), 300);
        }
        if ($hitRows) {
            usort($hitRows, function (array $a, array $b) use ($q): int {
                return self::rankText($b['title'], $q) <=> self::rankText($a['title'], $q);
            });
            $top = array_slice($hitRows, 0, 40);
            // 只为最终展示的 40 条取正文（避免把 300 行 LONGTEXT 一起拉出来）
            $bodyById = [];
            if ($top) {
                $tids = array_map('intval', array_column($top, 'id'));
                foreach (Db::rows(
                    'SELECT id, content FROM ' . Poly::table() . ' WHERE id IN (' . implode(',', array_fill(0, count($tids), '?')) . ')',
                    $tids
                ) as $b) {
                    $bodyById[(int)$b['id']] = (string)$b['content'];
                }
            }
            foreach ($top as $r) {
                $posts[] = [
                    'id' => 0, 'title' => $r['title'], 'slug' => $r['slug'],
                    'excerpt' => (string)($r['excerpt'] ?? ''), 'content' => $bodyById[(int)$r['id']] ?? '',
                    'words' => (int)($r['words'] ?? 0),
                    'views' => $r['views'], 'published_at' => $r['published_at'], 'created_at' => $r['published_at'],
                    'cats' => [], 'tags' => [],
                ];
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
