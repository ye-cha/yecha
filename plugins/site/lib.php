<?php
declare(strict_types=1);
/**
 * Site：企业官网插件核心（M1 骨架 + M2 前台 SSR）
 * - 路由：内核 case 'site' 分发多级路径 /site[...]；/site 单段经 plugin_page 兜底同入口。
 * - SSR 铁律：页面主体 PHP 直出，HTML 源码可读全内容；JS 仅客服浮窗交互 / 表单提交。
 * - 主题引擎：themes/<id>/style.css 设计令牌内联注入（<style data-site>），后台一键切换。
 * - AI：客服浮窗 / 语义搜索经 Ai 插件（独立能力层）。
 */
final class Site
{
    public const OPT_PREFIX = 'site.';

    /**
     * 行业风格包（= 主题）。每套 = themes/<id>/style.css（令牌覆写 + 品牌特征）
     * + themes/<id>/profile.php（行业词表 + 首页区块顺序），公共骨架统一走 themes/_base.css。
     * 覆盖主流企业类型，后台一键切换；?theme=<id> 可无副作用预览。
     */
    public const THEMES = [
        'business'  => '商务稳重（制造 / 通用 B2B）',
        'guofeng'   => '国风自然（茶 / 文化 / 农产品）',
        'tech'      => '科技极简（软件 / SaaS / 互联网）',
        'law'       => '律政庄重（律所 / 法律 / 咨询）',
        'medical'   => '医疗洁净（医院 / 健康 / 生物）',
        'education' => '教育活力（培训 / 学校 / 知识服务）',
        'food'      => '餐饮暖香（餐厅 / 食品 / 烘焙）',
        'estate'    => '建筑地产（工程 / 地产 / 空间设计）',
        'finance'   => '金融信赖（银行 / 投资 / 保险）',
        'energy'    => '能源环保（新能源 / 环保 / 双碳）',
        'logistics' => '物流动感（物流 / 运输 / 供应链）',
        'creative'  => '创意前卫（设计 / 广告 / 文创）',
    ];

    /**
     * 行业风格包的展示元信息（/site/themes 主题展示厅用）
     * - use：适用行业一句话（给选型用）；tags：视觉特征标签；desc：气质说明。
     * - 新增主题时同步补一条，展示厅会自动收录；缺项时按 id 兜底，不影响渲染。
     */
    public const THEME_META = [
        'business' => [
            'use'  => '机械制造 · 工业设备 · 通用 B2B · 外贸出口',
            'tags' => ['深藏青', '暖赭橙点缀', '小圆角', '细线无阴影'],
            'desc' => '偏稳重保守，突出资质、产能与交付能力，适合以「信任」为第一诉求的客户。',
        ],
        'guofeng' => [
            'use'  => '茶叶 · 文创礼品 · 地理标志农产品 · 文旅',
            'tags' => ['暖米底', '赭石墨字', '留白疏朗', '宋楷标题'],
            'desc' => '留白与韵律并重，适合强调产地、工艺与人文故事的品牌叙事。',
        ],
        'tech' => [
            'use'  => 'SaaS · 软件开发 · 互联网平台 · AI 服务',
            'tags' => ['靛蓝主色', '渐变光晕', '大圆角', '轻投影卡片'],
            'desc' => '信息密度低、对比强，适合需要快速讲清产品价值的技术型公司。',
        ],
        'law' => [
            'use'  => '律师事务所 · 审计税务 · 管理咨询 · 知识产权',
            'tags' => ['深灰蓝', '衬线标题', '宽行距', '金色细线'],
            'desc' => '字重与间距更克制，强调专业、严谨与保密感，适合高决策成本的服务。',
        ],
        'medical' => [
            'use'  => '医院科室 · 口腔诊所 · 生物医药 · 健康管理',
            'tags' => ['纯白底', '湖蓝绿', '柔和圆角', '大片留白'],
            'desc' => '低饱和蓝绿配大面积留白，传达洁净、安全与专业照护。',
        ],
        'education' => [
            'use'  => '培训机构 · 学校 · 知识付费 · 留学服务',
            'tags' => ['明快橙黄', '大圆角', '强 CTA', '插画位多'],
            'desc' => '高对比暖色配醒目行动按钮，适合转化导向的课程与招生场景。',
        ],
        'food' => [
            'use'  => '餐厅 · 烘焙 · 食品工厂 · 食品供应链',
            'tags' => ['焦糖棕', '暖橙', '大图优先', '圆润字形'],
            'desc' => '产品图前置、食欲色系，适合以图片驱动决策的餐饮与食品。',
        ],
        'estate' => [
            'use'  => '建筑工程 · 房地产开发 · 空间设计 · 装修装饰',
            'tags' => ['石灰岩色', '横向长条', '分栏式图片', '硬朗直线'],
            'desc' => '直线条与大尺度图片语言，强调空间、体量感与项目履历。',
        ],
        'finance' => [
            'use'  => '银行 · 证券 · 保险 · 资产管理',
            'tags' => ['深海军蓝', '金色强调', '数字突出', '表格友好'],
            'desc' => '数字与表格位权重更高，强调稳健、合规与数据披露。',
        ],
        'energy' => [
            'use'  => '光伏储能 · 环保工程 · 新能源汽车 · 双碳服务',
            'tags' => ['青绿渐变', '科技蓝', '数据条', '浅色底'],
            'desc' => '青绿与渐变的清洁意象，适合强调减碳、效率与技术参数。',
        ],
        'logistics' => [
            'use'  => '物流运输 · 仓储配送 · 货运代理 · 供应链',
            'tags' => ['橙色主色', '斜切版式', '速度线', '强对比'],
            'desc' => '斜线与速度感的版式语言，强调时效、网络覆盖与运力。',
        ],
        'creative' => [
            'use'  => '品牌设计 · 广告公司 · 文创 · 影视制作',
            'tags' => ['近黑底', '荧光黄绿', '极小圆角', '大字排版'],
            'desc' => '深色底配荧光撞色，作品集与工作室调性强烈，适合强调审美的创意团队。',
        ],
    ];


    /** 表名：site_product → ye_site_product（跟随宿主前缀机制） */
    public static function t(string $name): string
    {
        return Db::t('site_' . $name);
    }

    public static function opt(string $key, string $default = ''): string
    {
        return (string)get_option(self::OPT_PREFIX . $key, $default);
    }

    public static function setOpt(string $key, string $value): void
    {
        set_option(self::OPT_PREFIX . $key, $value);
    }

    /** ?theme=<id> 预览标记（只影响本次请求，不写库，供演示/客户对比皮肤） */
    private static bool $preview = false;

    /**
     * 当前风格包。优先级：URL ?theme=<id> > 会话记忆 > 后台设置。
     * 会话记忆：预览过某套风格后，在同一次浏览里点进产品详情 / 用户中心等内页仍保持该预览，
     *          否则每个内链都得拼 ?theme=，分享出去的链接还会串味。?theme=reset 可随时退出预览。
     */
    public static function theme(): string
    {
        $pv = strtolower(trim((string)($_GET['theme'] ?? '')));
        if ($pv === 'reset' || $pv === 'off' || $pv === 'exit') {
            unset($_SESSION['site_theme_pv']);
        } elseif ($pv !== '') {
            if (isset(self::THEMES[$pv])) { $_SESSION['site_theme_pv'] = $pv; self::$preview = true; return $pv; }
        }
        $sp = strtolower((string)($_SESSION['site_theme_pv'] ?? ''));
        if ($sp !== '' && isset(self::THEMES[$sp])) { self::$preview = true; return $sp; }
        $t = self::opt('theme', 'business');
        return isset(self::THEMES[$t]) ? $t : 'business';
    }

    public static function isThemePreview(): bool { return self::$preview; }

    /* ================= 行业风格包：词表 + 首页区块（profile.php） ================= */

    /** 行业词表默认值（无 profile.php 或未覆写时使用；保证任何主题都能正常渲染） */
    private const LEX = [
        'nav_home'   => '首页',
        'product'    => '产品中心',
        'product_one'=> '产品',
        'case'       => '客户案例',
        'case_one'   => '案例',
        'news'       => '新闻动态',
        'about'      => '关于我们',
        'jobs'       => '加入我们',
        'contact'    => '联系我们',
        'cta1'       => '获取报价',
        'cta2'       => '浏览产品',
        'search_ph'  => '搜索产品 / 案例 / 新闻',
        'feat_title' => '服务优势',
        'cta_title'  => '需要进一步了解？',
        'cta_desc'   => '留下您的需求，我们会在一个工作日内回复。',
            'more_product' => '查看全部产品 →',
            'more_case'  => '更多案例 →',
            'more_news'  => '更多动态 →',
            // 行业专属区块的小标题（各主题在 profile 里按行业口径覆写）
            'stats_title' => '实力与资质',
            'process_title' => '服务流程',
            'team_title' => '专业团队',
            'menu_title' => '招牌推荐',
            'quote_title' => '客户评价',
            'timeline_title' => '发展历程',
            'gallery_title' => '精选作品',
            'list_title' => '业务领域',
            'table_title' => '产品与费率一览',
            'cta_link'  => '',
        ];

    private static array $profileCache = [];

    /** 当前风格包的 profile（词表 + 首页区块顺序 + 导航裁剪），结果按主题缓存 */
    public static function profile(): array
    {
        $id = self::theme();
        if (isset(self::$profileCache[$id])) { return self::$profileCache[$id]; }
        $f = __DIR__ . '/themes/' . $id . '/profile.php';
        $p = is_file($f) ? (array)(include $f) : [];
        $out = [
            'label'    => (string)($p['label'] ?? (self::THEMES[$id] ?? $id)),
            'lex'      => array_merge(self::LEX, (array)($p['lex'] ?? [])),
            'home'     => array_values((array)($p['home'] ?? ['hero', 'strip', 'features', 'products', 'cases', 'news', 'cta'])),
            'nav_hide' => array_values((array)($p['nav_hide'] ?? [])),
        ];
        return self::$profileCache[$id] = $out;
    }

    /** 取行业词表条目（按当前语言自动翻译；中文站或查不到译文时回落原文） */
    public static function lex(string $key, string $default = ''): string
    {
        $l = self::profile()['lex'];
        return self::trText((string)($l[$key] ?? $default));
    }

    /* ================= 界面词表多语言（i18n-lex.php 词典） ================= */

    private static ?array $dict = null;
    private static ?array $dictMap = null;

    /** 词典原文（语言无关，同请求内 memo；未装词典文件返回空数组） */
    public static function dict(): array
    {
        if (self::$dict === null) {
            $f = __DIR__ . '/i18n-lex.php';
            self::$dict = is_file($f) ? (array)(include $f) : [];
        }
        return self::$dict;
    }

    /**
     * 翻译一条界面文案（导航 / 标题 / 按钮 / 占位符 / 整句提示）。
     * 中文站（lang 为空）直接返回原文，零开销；词典未收录同样回落原文。
     */
    public static function trText(string $zh): string
    {
        $l = self::lang();
        if ($l === '' || $zh === '') { return $zh; }
        $v = (self::dict()[$zh][$l] ?? '');
        return is_string($v) && $v !== '' ? $v : $zh;
    }

    /**
     * 全文兜底替换表：词典全量（中文原文 => 当前语言译文）。
     * 只用于「HTML 文本节点 + 文案属性」的替换，不碰标签名与代码，
     * 因此短词（首页 / 产品 / 归档…，嵌在面包屑、标题、按钮里）也能安全命中。
     */
    private static function dictMap(): array
    {
        if (self::$dictMap !== null) { return self::$dictMap; }
        $l = self::lang();
        $out = [];
        if ($l !== '') {
            foreach (self::dict() as $zh => $t) {
                $v = (string)(is_array($t) ? ($t[$l] ?? '') : '');
                if ($v === '' || $v === $zh || strpos((string)$zh, '<') !== false) { continue; }
                $out[(string)$zh] = $v;
            }
        }
        return self::$dictMap = $out;
    }

    /**
     * 对一段已渲染 HTML 做兜底翻译（幂等）。
     * 只替换「标签之间的文本节点」与常见文案属性（title/alt/placeholder/content/aria-label）：
     * 界面词（导航 / 面包屑 / 标题 / 按钮 / 页脚 / 浮窗）无论长短都能命中，
     * 而标签名、类名、URL、脚本逻辑一律不受影响。
     */
    public static function translateHtml(string $html): string
    {
        $m = self::dictMap();
        // 预览演示数据时保持原文：样例文案只有中文，词典替换只会得到半中半洋的怪译
        if (!$m || self::demoOn()) { return $html; }
        // 1) 文本节点
        $html = preg_replace_callback('/>([^<>]+)</u', function ($x) use ($m) {
            return '>' . strtr($x[1], $m) . '<';
        }, $html);
        // 2) 承载文案的属性值
        $html = preg_replace_callback(
            '/\b(title|alt|placeholder|content|aria-label)="([^"]*)"/u',
            function ($x) use ($m) {
                return $x[1] . '="' . strtr($x[2], $m) . '"';
            },
            $html
        );
        return $html;
    }

    /**
     * 首页「服务优势」条目：选项 site.features，每行「标题|说明」；最多 8 条。
     * $tr=true 时按当前语言套用译文（site_i18n payload['features']，逐条对齐）。
     */
    public static function features(bool $tr = true): array
    {
        $out = [];
        if (self::demoOn()) {
            // 演示数据：每行「标题|说明」，按行业写（如律所的「48 名执业律师|9 名合伙人…」）
            $lines = (array)(self::demoPack()['features'] ?? []);
        } else {
            $lines = preg_split('/\r\n|\r|\n/', self::opt('features', '')) ?: [];
        }
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') { continue; }
            $a = explode('|', $line, 2);
            $out[] = ['title' => trim($a[0]), 'desc' => trim($a[1] ?? '')];
            if (count($out) >= 8) { break; }
        }
        if (self::demoOn()) { return $out; }   // 演示样例只有中文，不套译文
        if (!$tr || !$out || self::lang() === '') { return $out; }
        $tf = self::trFor('site', 0, self::lang())['features'] ?? null;
        if (!is_array($tf)) { return $out; }
        foreach ($out as $i => $f) {
            if (!empty($tf[$i]['title'])) { $out[$i]['title'] = (string)$tf[$i]['title']; }
            if (!empty($tf[$i]['desc'])) { $out[$i]['desc'] = (string)$tf[$i]['desc']; }
        }
        return $out;
    }


    /**
     * 主题变体图：同名素材存在 `<name>-<theme>.<ext>` 时按当前主题替换。
     * 用于图像需要跟随主题色板（如商务深底 / 国风米底）的场景，未提供变体时原图直出。
     */
    public static function themeImg(string $img): string
    {
        if ($img === '') { return ''; }
        if (!preg_match('#^(.*/)?([^/]+)\.(svg|png|jpe?g|webp)$#i', $img, $m)) { return $img; }
        $alt = ($m[1] ?? '') . $m[2] . '-' . self::theme() . '.' . strtolower($m[3]);
        return is_file(dirname(__DIR__, 2) . $alt) ? $alt : $img;
    }

    /* ================= 行业演示数据层（预览某套风格包 = 加载该行业全套样例内容） ================= */

    /**
     * 是否走演示数据：只有「?theme=<id> 预览」且该行业在 seed-data.php 里有数据包时生效。
     * 正式站点（不带预览参数）永远读自己的数据库——预览不写库、不污染真实业务数据。
     * 后台可用 site.demo.auto=0 关闭（例如客户已录入自有数据、只需要对比皮肤外观时）。
     */
    public static function demoOn(): bool
    {
        if (self::opt('demo.auto', '1') !== '1') { return false; }
        self::theme();                       // 先让 ?theme= 的预览标记落地
        if (!self::isThemePreview()) { return false; }
        return self::demoPack() !== [];
    }

    private static array $demoMemo = [];

    /** 当前预览行业的演示数据包（12 个行业，随插件分发）；缺失/损坏返回空数组 */
    public static function demoPack(): array
    {
        $id = self::theme();
        if (array_key_exists($id, self::$demoMemo)) { return self::$demoMemo[$id]; }
        $f = __DIR__ . '/seed-data.php';
        $all = is_file($f) ? (array)(include $f) : [];
        return self::$demoMemo[$id] = (isset($all[$id]) && is_array($all[$id])) ? $all[$id] : [];
    }

    /** 演示数据 → 统一行结构（id 用序号 +1，保证详情页链接稳定） */
    private static function demoRows(string $key): array
    {
        static $cache = [];
        if (isset($cache[$key])) { return $cache[$key]; }
        $out = [];
        $i = 0;
        foreach ((array)(self::demoPack()[$key] ?? []) as $r) {
            $i++;
            $r = (array)$r;
            switch ($key) {
                case 'products':
                    $out[] = ['id' => $i, 'name' => (string)($r['name'] ?? ''), 'summary' => (string)($r['summary'] ?? ''),
                        'image' => (string)($r['img'] ?? ''), 'content' => (string)($r['content'] ?? ''),
                        'params' => is_array($r['params'] ?? null) ? implode("\n", array_map('strval', $r['params'])) : ''];
                    break;
                case 'cases':
                    $out[] = ['id' => $i, 'name' => (string)($r['name'] ?? ''), 'industry' => (string)($r['industry'] ?? ''),
                        'challenge' => (string)($r['challenge'] ?? ''), 'solution' => (string)($r['solution'] ?? ''),
                        'result' => (string)($r['result'] ?? ''), 'image' => (string)($r['img'] ?? '')];
                    break;
                case 'jobs':
                    $out[] = ['id' => $i, 'title' => (string)($r['title'] ?? ''), 'place' => (string)($r['place'] ?? ''),
                        'type' => (string)($r['type'] ?? ''), 'salary' => (string)($r['salary'] ?? ''),
                        'duty' => is_array($r['duty'] ?? null) ? implode("\n", array_map('strval', $r['duty'])) : '',
                        'requirement' => is_array($r['requirement'] ?? null) ? implode("\n", array_map('strval', $r['requirement'])) : ''];
                    break;
                case 'banners':
                    $out[] = ['id' => $i, 'title' => (string)($r[0] ?? $r['title'] ?? ''), 'sub' => (string)($r[1] ?? $r['sub'] ?? '')];
                    break;
                case 'news':
                    $out[] = ['id' => $i, 'title' => (string)($r['title'] ?? ''), 'excerpt' => (string)($r['excerpt'] ?? ''),
                        'body' => (string)($r['body'] ?? ''), 'slug' => 'demo-n' . $i, 'date' => date('Y-m-d', strtotime('-' . ($i * 9) . ' days'))];
                    break;
            }
        }
        return $cache[$key] = $out;
    }

    /** 横幅 / 数据条（预览走演示包，否则读库） */
    public static function dataBanners(int $limit = 5): array
    {
        if (self::demoOn()) { return array_slice(self::demoRows('banners'), 0, max(1, $limit), true); }
        return self::trAll('banner', Db::rows("SELECT id, title, sub, image, link FROM " . self::t('banner')
            . " WHERE is_show = 1 AND is_delete_time = 0 ORDER BY sort DESC, id DESC LIMIT " . max(1, $limit)));
    }

    /** 产品 / 服务列表 */
    public static function dataProducts(int $limit = 6, int $cateId = 0): array
    {
        if (self::demoOn()) { return array_slice(self::demoRows('products'), 0, $limit > 0 ? $limit : null, true); }
        $w = $cateId > 0 ? ' AND cate_id = ' . (int)$cateId : '';
        $lim = $limit > 0 ? ' LIMIT ' . $limit : '';
        return self::trAll('product', Db::rows("SELECT id, name, summary, image, params FROM " . self::t('product')
            . " WHERE is_show = 1 AND is_delete_time = 0" . $w . " ORDER BY sort DESC, id DESC" . $lim));
    }

    /** 单个产品（含正文与参数） */
    public static function dataProduct(int $id): array
    {
        if (self::demoOn()) {
            foreach (self::demoRows('products') as $p) { if ((int)$p['id'] === $id) { return $p; } }
            return [];
        }
        $p = Db::row("SELECT * FROM " . self::t('product') . " WHERE id = :id AND is_show = 1 AND is_delete_time = 0", [':id' => $id]);
        if ($p === null) { return []; }
        return self::trRow('product', $p);
    }

    /** 案例 / 业绩列表 */
    public static function dataCases(int $limit = 3): array
    {
        if (self::demoOn()) { return array_slice(self::demoRows('cases'), 0, $limit > 0 ? $limit : null, true); }
        $lim = $limit > 0 ? ' LIMIT ' . $limit : '';
        return self::trAll('case', Db::rows("SELECT id, name, industry, challenge, solution, result, image FROM "
            . self::t('case') . " WHERE is_show = 1 AND is_delete_time = 0 ORDER BY sort DESC, id DESC" . $lim));
    }

    public static function dataCase(int $id): array
    {
        if (self::demoOn()) {
            foreach (self::demoRows('cases') as $c) { if ((int)$c['id'] === $id) { return $c; } }
            return [];
        }
        $r = Db::row("SELECT * FROM " . self::t('case') . " WHERE id = :id AND is_show = 1 AND is_delete_time = 0", [':id' => $id]);
        if ($r === null) { return []; }
        return self::trRow('case', $r);
    }

    /** 招聘职位 */
    public static function dataJobs(): array
    {
        if (self::demoOn()) { return array_values(self::demoRows('jobs')); }
        return self::trAll('job', Db::rows("SELECT * FROM " . self::t('job') . " WHERE is_show = 1 AND is_delete_time = 0 ORDER BY sort DESC, id DESC LIMIT 50"));
    }

    /** 新闻列表（每条含 title / slug / url / date） */
    public static function dataNews(int $limit = 5): array
    {
        if (self::demoOn()) {
            $out = [];
            foreach (array_slice(self::demoRows('news'), 0, $limit > 0 ? $limit : null, true) as $n) {
                $out[] = ['title' => (string)$n['title'], 'slug' => (string)$n['slug'], 'url' => '/site/news/' . $n['slug'] . '.html', 'date' => (string)$n['date']];
            }
            return $out;
        }
        return self::newsList($limit);
    }

    /** 新闻正文（预览模式按 demo-n<i> 序号取，否则走博客文章引擎） */
    public static function dataNewsItem(string $slug): array
    {
        if (self::demoOn() && preg_match('/^demo-n(\d+)$/', $slug, $m)) {
            foreach (self::demoRows('news') as $n) {
                if ((string)$n['slug'] === $slug) {
                    return ['id' => (int)$n['id'], 'title' => (string)$n['title'], 'date' => (string)$n['date'],
                        'html' => self::richText((string)$n['body'])];
                }
            }
            return [];
        }
        return [];
    }

    public static function company(): array
    {
        if (self::demoOn()) {
            $d = self::demoPack();
            $c = (array)($d['company'] ?? []);
            $seo = (array)($d['seo'] ?? []);
            return [
                'name' => (string)($c['name'] ?? '我的企业'), 'slogan' => (string)($c['slogan'] ?? ''),
                'intro' => (string)($c['intro'] ?? ''), 'phone' => (string)($c['phone'] ?? ''),
                'email' => (string)($c['email'] ?? ''), 'address' => (string)($c['address'] ?? ''),
                'wechat' => (string)($c['wechat'] ?? ''), 'icp' => (string)($c['icp'] ?? ''),
                'seo_desc' => (string)($seo['desc'] ?? ''), 'seo_kw' => (string)($seo['kw'] ?? ''),
            ];
        }
        return [
            'name' => self::optTr('company.name', '我的企业'),
            'slogan' => self::optTr('company.slogan', ''),
            'intro' => self::optTr('company.intro', ''),
            'phone' => self::opt('company.phone', ''),
            'email' => self::opt('company.email', ''),
            'address' => self::optTr('company.address', ''),
            'wechat' => self::opt('company.wechat', ''),
            'icp' => self::opt('company.icp', ''),
            'seo_desc' => self::optTr('seo.desc', ''),
            'seo_kw' => self::optTr('seo.kw', ''),
        ];
    }

    /* ================= 站点级文案多语言（首屏 slogan / 简介 / 优势条 / 横幅） ================= */

    /**
     * 站点级文案译文取自 site_i18n（item_type='site', item_id=0, payload = {"company.slogan": "…"}）。
     * 注意用 trFor() 而非 tr()：后者有 `$id <= 0` 守卫，会把 item_id=0 直接挡掉。
     * 电话 / 邮箱 / 微信号这类不译的白名单键仍走 opt()。
     */
    public static function optTr(string $key, string $default = ''): string
    {
        $raw = self::opt($key, $default);
        $l = self::lang();
        if ($l === '' || $raw === '') { return $raw; }
        $v = (string)(self::trFor('site', 0, $l)[$key] ?? '');
        return $v !== '' ? $v : $raw;
    }

    /** 站点级文案的可翻译键（后台「一键 AI 翻译」按这个清单走） */
    public const SITE_TR_KEYS = [
        'company.name', 'company.slogan', 'company.intro', 'company.address',
        'seo.desc', 'seo.kw',
    ];

    /** 栏目导航（标签走行业词表；profile.nav_hide 可按行业裁剪栏目，新闻复用博客文章引擎） */
    public static function nav(): array
    {
        $all = [
            'home'     => ['/site', self::lex('nav_home', '首页')],
            'products' => ['/site/products', self::lex('product', '产品中心')],
            'cases'    => ['/site/cases', self::lex('case', '客户案例')],
            'news'     => ['/site/news', self::lex('news', '新闻动态')],
            'about'    => ['/site/about', self::lex('about', '关于我们')],
            'jobs'     => ['/site/jobs', self::lex('jobs', '加入我们')],
            'contact'  => ['/site/contact', self::lex('contact', '联系我们')],
        ];
        $hide = self::profile()['nav_hide'];
        if ($hide) { $all = array_diff_key($all, array_flip($hide)); }
        return $all;
    }

    /* ================= 路由分发 ================= */

    public static function dispatch(array $path, array $query): array
    {
        // 官网页由本插件供内容、内核自包含分支包 <html>，语言得提前交给内核
        self::applyDocLang();
        // POST：留言 / 简历投递
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::handlePost();
        }
        $seg = $path[0] ?? '';
        switch ($seg) {
            case '':
            case 'home':
                return self::layout('首页', 'home', self::pageHome());
            case 'products':
            case 'product': // 详情 URL 为 /site/product/{id}.html（单数）
                return self::layout(self::lex('product'), 'products', ($path[1] ?? '') !== '' ? self::pageProductDetail((int)$path[1]) : self::pageProducts((int)($query['cate'] ?? 0)));
            case 'cases':
            case 'case': // 详情 URL 为 /site/case/{id}.html（单数）
                return self::layout(self::lex('case'), 'cases', ($path[1] ?? '') !== '' ? self::pageCaseDetail((int)$path[1]) : self::pageCases());
            case 'news':
                $sub = (string)($path[1] ?? '');
                if ($sub !== '') {
                    $a = self::articleBody((string)preg_replace('/\.html$/', '', $sub));
                    return self::layout($a['title'] !== '' ? $a['title'] : self::lex('news'), 'news', '<article class="sc-detail sc-article">' . $a['html'] . '</article>');
                }
                return self::layout(self::lex('news'), 'news', self::pageNews());
            case 'search':
                return self::layout('站内搜索', '', self::searchPage((string)($query['q'] ?? '')));
            case 'about':
                return self::layout(self::lex('about'), 'about', self::pageAbout());
            case 'jobs':
                return self::layout(self::lex('jobs'), 'jobs', self::pageJobs());
            case 'contact':
                return self::layout(self::lex('contact'), 'contact', self::pageContact());
            case 'themes':
                // 主题展示厅：独立中性外壳（无企业头尾），卡片直达各风格演示
                return ['html' => self::galleryShell(self::pageThemes()), 'title' => self::trText('主题展示厅')];
            default:
                // 官网内未知路径：同样穿官网皮肤（带完整头尾），并返回 404 而非 200
                return ['html' => self::shellHtml('', '<div class="sc-detail sc-inner"><div class="page-head">'
                        . '<h1>页面不存在</h1><p class="hint">您访问的页面已下线或被移动。</p></div>'
                        . '<ul class="sc-news sc-news-lg"><li><a href="/site">返回官网首页</a></li>'
                        . '<li><a href="/site/products">' . e(self::lex('product')) . '</a></li><li><a href="/site/contact">' . e(self::lex('contact')) . '</a></li></ul></div>'),
                    'title' => self::trText('页面不存在') . ' · ' . self::opt('company.name', '我的企业'), 'status' => 404];
        }
    }

    private static function handlePost(): void
    {
        $act = (string)($_POST['act'] ?? '');
        if ($act !== 'message' && $act !== 'apply') { return; }
        try { \Csrf::check(); } catch (Throwable $e) { return; }
        $ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 64);
        $contact = mb_substr(trim((string)($_POST['contact'] ?? '')), 0, 128);
        $content = mb_substr(trim((string)($_POST['content'] ?? '')), 0, 2000);
        if ($name === '' || $content === '') { return; }
        // 演示预览模式不落库：避免样例页面里的表单把演示数据写进客户的真实业务表
        if (self::demoOn()) {
            $_SESSION['site_flash'] = '演示预览模式：表单提交未写入数据库（正式站点不受影响）。';
            header('Location: ' . ($act === 'apply' ? '/site/jobs' : '/site/contact'));
            exit;
        }

        if ($act === 'message') {
            // 意图自动分类（关键词规则；AI 摘要在 M5 增强）
            $intent = '其他';
            foreach ([['询价|报价|价格|多少钱', '询价'], ['售后|维修|故障|投诉', '售后'], ['合作|代理|加盟|经销', '合作']] as [$re, $tag]) {
                if (preg_match('/' . $re . '/u', $content)) { $intent = $tag; break; }
            }
            try {
                Db::insert('site_message', ['name' => $name, 'contact' => $contact, 'content' => $content, 'intent' => $intent, 'ip' => $ip]);
            } catch (Throwable $e) {
                return;
            }
            $_SESSION['site_flash'] = '留言已提交，我们会尽快与您联系。';
            header('Location: /site/contact'); exit;
        }

        // 简历投递
        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) { return; }
        Db::insert('site_job_apply', ['job_id' => $jobId, 'name' => $name, 'contact' => $contact, 'intro' => $content, 'ip' => $ip]);
        $_SESSION['site_flash'] = '简历已收到，感谢您的关注。';
        header('Location: /site/jobs'); exit;
    }

    private static function flash(): string
    {
        if (empty($_SESSION['site_flash'])) { return ''; }
        $f = '<div class="sc-flash">' . e((string)$_SESSION['site_flash']) . '</div>';
        unset($_SESSION['site_flash']);
        return $f;
    }

    /* ================= 前台页面（SSR） ================= */

    /**
     * 首页：区块顺序由当前行业风格包的 profile.home 决定（hero/strip/features/products/cases/news/cta），
     * 区块内部文案走行业词表 —— 换行业风格包即换「首页结构与术语」，而不只是换配色。
     * 全部 PHP 直出（SSR），HTML 源码可读全部内容。
     */
    private static function pageHome(): string
    {
        $banners  = self::dataBanners(5);
        $products = self::dataProducts(6);
        $cases    = self::dataCases(3);
        $ctx = ['banners' => $banners, 'products' => $products, 'cases' => $cases, 'news' => self::dataNews(5)];
        $h = '';
        foreach (self::profile()['home'] as $blk) {
            switch ($blk) {
                case 'hero':     $h .= self::homeHero($ctx); break;
                case 'strip':    $h .= self::homeStrip($ctx); break;
                case 'stats':    $h .= self::blkStats(); break;
                case 'features': $h .= self::homeFeatures(); break;
                case 'process':  $h .= self::blkProcess(); break;
                case 'products': $h .= self::homeProducts($ctx); break;
                case 'list':     $h .= self::blkList(); break;
                case 'table':    $h .= self::blkTable(); break;
                case 'menu':     $h .= self::blkMenu(); break;
                case 'experts':  $h .= self::blkExperts(); break;
                case 'gallery':  $h .= self::blkGallery(); break;
                case 'cases':    $h .= self::homeCases($ctx); break;
                case 'quote':    $h .= self::blkQuote(); break;
                case 'timeline': $h .= self::blkTimeline(); break;
                case 'news':     $h .= self::homeNews($ctx); break;
                case 'cta':      $h .= self::homeCta(); break;
            }
        }
        return $h;
    }

    /** 首页 · Hero（文案取企业信息，装饰由主题 CSS 决定） */
    private static function homeHero(array $x): string
    {
        $c = self::company();
        $h = '<section class="sc-hero"><div class="sc-hero-text">';
        $h .= !empty($x['banners']) ? '<p class="sc-hero-eyebrow">' . e((string)$x['banners'][0]['title']) . '</p>' : '';
        $h .= '<h1>' . e($c['slogan'] !== '' ? $c['slogan'] : $c['name']) . '</h1>';
        if ($c['intro'] !== '') {
            $intro = trim(preg_split('/\r\n|\r|\n/', $c['intro'])[0] ?? '');
            $h .= '<p class="sc-hero-intro">' . e(mb_substr($intro, 0, 120)) . '</p>';
        }
        $h .= '<div class="sc-hero-cta"><a class="sc-btn sc-btn-primary" href="/site/contact">' . e(self::lex('cta1')) . '</a>'
            . '<a class="sc-btn sc-btn-ghost" href="/site/products">' . e(self::lex('cta2')) . '</a></div>';
        $art = self::heroImg();
        return $h . '</div><div class="sc-hero-art"'
            . ($art !== '' ? ' data-img="1" style="background-image:url(\'' . e($art) . '\')"' : '')
            . '></div></section>';
    }

    /**
     * 首屏配图：真实站点读后台配置 site.hero_img，演示行业包按当前风格取该行业的实拍图。
     * 没有配图时退回各主题自带的几何装饰（--sc-hero-art / ::before），不会留空洞。
     */
    private static function heroImg(): string
    {
        $cfg = trim((string)self::opt('hero_img', ''));
        if ($cfg !== '' && !self::demoOn()) { return $cfg; }
        if (self::demoOn()) {
            $f = '/plugins/site/assets/demo/' . self::theme() . '/hero.jpg';
            $p = dirname(__DIR__, 2) . '/plugins/site/assets/demo/' . self::theme() . '/hero.jpg';
            return is_file($p) ? $f : '';
        }
        return $cfg;
    }

    /** 首页 · 横幅条（多条 banner 时作为数据条） */
    private static function homeStrip(array $x): string
    {
        $b = $x['banners'];
        if (count($b) < 2) { return ''; }
        $h = '<section class="sc-strip"><ul class="sc-banner-list">';
        foreach ($b as $it) {
            $h .= '<li><b>' . e((string)$it['title']) . '</b>' . ($it['sub'] !== '' ? '<span>' . e((string)$it['sub']) . '</span>' : '') . '</li>';
        }
        return $h . '</ul></section>';
    }

    /** 首页 · 服务优势/能力（选项 site.features，每行「标题|说明」） */
    private static function homeFeatures(): string
    {
        $f = self::features();
        if (!$f) { return ''; }
        $h = '<section class="sc-sec"><h2>' . e(self::lex('feat_title')) . '</h2><div class="sc-feats">';
        foreach ($f as $it) {
            $h .= '<div class="sc-feat"><b>' . e($it['title']) . '</b>' . ($it['desc'] !== '' ? '<span>' . e($it['desc']) . '</span>' : '') . '</div>';
        }
        return $h . '</div></section>';
    }

    /** 首页 · 产品/服务（术语按行业） */
    private static function homeProducts(array $x): string
    {
        $rows = $x['products'];
        $h = '<section class="sc-sec"><h2>' . e(self::lex('product')) . '</h2><div class="sc-cards">';
        if (!$rows) { $h .= '<p class="sc-empty">暂无' . e(self::lex('product_one')) . '，请在后台「企业官网」对应板块添加。</p>'; }
        foreach ($rows as $p) {
            $h .= '<a class="sc-card" href="/site/product/' . (int)$p['id'] . '.html"><div class="sc-card-img"'
                . ($p['image'] !== '' ? ' style="background-image:url(' . e(self::themeImg((string)$p['image'])) . ')"' : '') . '></div>'
                . '<b>' . e((string)$p['name']) . '</b>'
                . ($p['summary'] !== '' ? '<span>' . e(mb_substr((string)$p['summary'], 0, 60)) . '</span>' : '') . '</a>';
        }
        return $h . '</div><p class="sc-more"><a href="/site/products">' . e(self::lex('more_product')) . '</a></p></section>';
    }

    /** 首页 · 案例/成果（术语按行业） */
    private static function homeCases(array $x): string
    {
        $rows = $x['cases'];
        $h = '<section class="sc-sec sc-alt"><h2>' . e(self::lex('case')) . '</h2><div class="sc-cards sc-cards-3">';
        if (!$rows) { $h .= '<p class="sc-empty">暂无' . e(self::lex('case_one')) . '。</p>'; }
        foreach ($rows as $cs) {
            $h .= '<a class="sc-card" href="/site/case/' . (int)$cs['id'] . '.html"><b>' . e((string)$cs['name']) . '</b>'
                . '<span>' . e((string)$cs['industry']) . '</span></a>';
        }
        return $h . '</div><p class="sc-more"><a href="/site/cases">' . e(self::lex('more_case')) . '</a></p></section>';
    }

    /** 首页 · 新闻动态（复用博客文章引擎，限定官网新闻口径） */
    private static function homeNews(array $x): string
    {
        $rows = $x['news'];
        $h = '<section class="sc-sec"><h2>' . e(self::lex('news')) . '</h2><ul class="sc-news">';
        if (!$rows) { $h .= '<li class="sc-empty">暂无内容，可在后台发布后自动同步至此。</li>'; }
        foreach ($rows as $n) {
            $h .= '<li><a href="' . e((string)$n['url']) . '">' . e((string)$n['title']) . '</a><time>' . e((string)$n['date']) . '</time></li>';
        }
        return $h . '</ul><p class="sc-more"><a href="/site/news">' . e(self::lex('more_news')) . '</a></p></section>';
    }

    /** 首页 · 转化区（咨询电话/邮箱，SSR 直出） */
    private static function homeCta(): string
    {
        $c = self::company();
        $h = '<section class="sc-sec"><div class="sc-cta-box"><div><b>' . e(self::lex('cta_title')) . '</b>'
            . '<p class="sc-lead">' . e(self::lex('cta_desc')) . '</p></div><div>';
        if ($c['phone'] !== '') { $h .= '<span class="sc-top-phone">' . e($c['phone']) . '</span> '; }
        return $h . '<a class="sc-btn sc-btn-primary" href="/site/contact">' . e(self::lex('cta1')) . '</a></div></div></section>';
    }

    /* ---------- 行业专属区块：同一份数据在不同行业里是不同形态（这才是「布局差异」） ---------- */

    /** 数据 / 资质条（来源：横幅；制造=zertifikat 资质、教育=学员数、金融=规模、地产=开发量） */
    private static function blkStats(): string
    {
        $rows = self::dataBanners(4);
        if (!$rows) { return ''; }
        $h = '<section class="sc-sec sc-stats"><h2>' . e(self::lex('stats_title')) . '</h2><ul class="sc-stat-bar">';
        foreach ($rows as $r) {
            $h .= '<li><b>' . e((string)$r['title']) . '</b>' . ($r['sub'] !== '' ? '<span>' . e((string)$r['sub']) . '</span>' : '') . '</li>';
        }
        return $h . '</ul></section>';
    }

    /** 流程 / 工序步骤（来源：优势条；能源=交付流程、餐饮=出品流程、物流=时效流程、国风=制茶工序） */
    private static function blkProcess(): string
    {
        $f = self::features();
        if (!$f) { return ''; }
        $h = '<section class="sc-sec sc-process"><h2>' . e(self::lex('process_title')) . '</h2><ol class="sc-steps">';
        $i = 0;
        foreach ($f as $it) {
            $i++;
            $h .= '<li><i>' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '</i><b>' . e($it['title']) . '</b>'
                . ($it['desc'] !== '' ? '<span>' . e($it['desc']) . '</span>' : '') . '</li>';
        }
        return $h . '</ol></section>';
    }

    /** 团队 / 专家卡（来源：产品；律所=律师、医疗=医生、教育=师资，圆形首字头像 + 头衔） */
    private static function blkExperts(): string
    {
        $rows = self::dataProducts(8);
        if (!$rows) { return ''; }
        $h = '<section class="sc-sec sc-people-sec"><h2>' . e(self::lex('team_title')) . '</h2><div class="sc-people">';
        foreach ($rows as $p) {
            $h .= '<a class="sc-person" href="/site/product/' . (int)$p['id'] . '.html">'
                . '<span class="sc-avatar">' . e(mb_substr((string)$p['name'], 0, 1)) . '</span>'
                . '<b>' . e((string)$p['name']) . '</b><em>' . e(mb_substr((string)$p['summary'], 0, 40)) . '</em></a>';
        }
        return $h . '</div></section>';
    }

    /** 价目 / 菜单列表（来源：产品；餐饮招牌菜、律师费目、服务价目：名称 + 点线 + 说明） */
    private static function blkMenu(): string
    {
        $rows = self::dataProducts(8);
        if (!$rows) { return ''; }
        $h = '<section class="sc-sec"><h2>' . e(self::lex('menu_title')) . '</h2><ul class="sc-menu">';
        foreach ($rows as $p) {
            $h .= '<li><a href="/site/product/' . (int)$p['id'] . '.html"><b>' . e((string)$p['name']) . '</b>'
                . '<i class="sc-leader"></i><span>' . e(mb_substr((string)$p['summary'], 0, 40)) . '</span></a></li>';
        }
        return $h . '</ul></section>';
    }

    /** 客户评价 / 证言（来源：案例；教育=学员评价、SaaS=ROI、创意 agency=客户金句） */
    private static function blkQuote(): string
    {
        $rows = self::dataCases(3);
        if (!$rows) { return ''; }
        $h = '<section class="sc-sec sc-alt sc-quote-sec"><h2>' . e(self::lex('quote_title')) . '</h2><div class="sc-quotes">';
        foreach ($rows as $c) {
            $body = (string)($c['result'] ?? '') !== '' ? (string)$c['result'] : (string)($c['challenge'] ?? '');
            $h .= '<blockquote class="sc-quote"><p>' . e($body) . '</p><footer><b>' . e((string)$c['name']) . '</b>'
                . ($c['industry'] !== '' ? '<span>' . e((string)$c['industry']) . '</span>' : '') . '</footer></blockquote>';
        }
        return $h . '</div></section>';
    }

    /** 历程 / 门店 / 项目时间轴（来源：案例；老字号、国力茶庄、地产交付节点） */
    private static function blkTimeline(): string
    {
        $rows = self::dataCases(6);
        if (!$rows) { return ''; }
        $h = '<section class="sc-sec"><h2>' . e(self::lex('timeline_title')) . '</h2><ol class="sc-timeline">';
        foreach ($rows as $c) {
            $h .= '<li><i></i><b>' . e((string)$c['name']) . '</b>'
                . ($c['industry'] !== '' ? '<em>' . e((string)$c['industry']) . '</em>' : '')
                . '<span>' . e(mb_substr((string)($c['challenge'] ?? ''), 0, 50)) . '</span></li>';
        }
        return $h . '</ol></section>';
    }

    /** 作品瀑布（来源：产品；创意 agency 的不等高 masonry，配 hover 抬升） */
    private static function blkGallery(): string
    {
        $rows = self::dataProducts(8);
        if (!$rows) { return ''; }
        $h = '<section class="sc-sec"><h2>' . e(self::lex('gallery_title')) . '</h2><div class="sc-gallery">';
        foreach ($rows as $p) {
            $h .= '<a class="sc-shot" href="/site/product/' . (int)$p['id'] . '.html"><div class="sc-card-img"'
                . ($p['image'] !== '' ? ' style="background-image:url(' . e(self::themeImg((string)$p['image'])) . ')"' : '') . '></div>'
                . '<b>' . e((string)$p['name']) . '</b>' . ($p['summary'] !== '' ? '<span>' . e(mb_substr((string)$p['summary'], 0, 40)) . '</span>' : '') . '</a>';
        }
        return $h . '</div></section>';
    }

    /** 纯文字列表式（来源：产品；律所业务领域、制造产品线——不做图卡，重视 Discovery 与密度） */
    private static function blkList(): string
    {
        $rows = self::dataProducts(8);
        if (!$rows) { return ''; }
        $h = '<section class="sc-sec"><h2>' . e(self::lex('list_title')) . '</h2><ul class="sc-plist">';
        foreach ($rows as $p) {
            $h .= '<li><a href="/site/product/' . (int)$p['id'] . '.html"><b>' . e((string)$p['name']) . '</b>'
                . ($p['summary'] !== '' ? '<span>' . e(mb_substr((string)$p['summary'], 0, 50)) . '</span>' : '') . '</a></li>';
        }
        return $h . '</ul><p class="sc-more"><a href="/site/products">' . e(self::lex('more_product')) . '</a></p></section>';
    }

    /** 参数 / 费率表（来源：产品 + 其 params；金融=费率表、制造=参数表、地产=户型表） */
    private static function blkTable(): string
    {
        $rows = self::dataProducts(8);
        if (!$rows) { return ''; }
        $max = 0;
        foreach ($rows as $p) {
            $n = count(self::paramsArr((string)($p['params'] ?? '')));
            $max = $max > $n ? $max : $n;
        }
        if ($max === 0) { return self::homeProducts(['products' => $rows]); }   // 没有参数数据退化成普通卡片
        $head = [];
        foreach ($rows as $p) {
            $head = self::paramsArr((string)($p['params'] ?? ''));
            if ($head) { break; }
        }
        $h = '<section class="sc-sec"><h2>' . e(self::lex('table_title')) . '</h2><div class="sc-dtable-wrap"><table class="sc-dtable"><thead><tr><th>' . e(self::lex('product_one')) . '</th>';
        foreach ($head as $kv) { $h .= '<th>' . e($kv[0]) . '</th>'; }
        $h .= '</tr></thead><tbody>';
        foreach ($rows as $p) {
            $h .= '<tr><th><a href="/site/product/' . (int)$p['id'] . '.html">' . e((string)$p['name']) . '</a></th>';
            foreach (self::paramsArr((string)($p['params'] ?? '')) as $kv) { $h .= '<td>' . e($kv[1]) . '</td>'; }
            $h .= '</tr>';
        }
        return $h . '</tbody></table></div><p class="sc-more"><a href="/site/products">' . e(self::lex('more_product')) . '</a></p></section>';
    }

    /** 解析「名称|值」参数串（每行一条） */
    private static function paramsArr(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $ln) {
            $ln = trim($ln);
            if ($ln === '' || !str_contains($ln, '|')) { continue; }
            [$k, $v] = array_pad(explode('|', $ln, 2), 2, '');
            $out[] = [trim($k), trim($v)];
        }
        return $out;
    }

    private static function newsList(int $limit): array
    {
        [$where, $params, $join] = self::newsWhere();
        $rows = Db::rows(
            "SELECT DISTINCT c.id, c.slug, c.title, c.published_at FROM " . Db::t('contents') . ' c' . $join
            . ' WHERE ' . $where . ' ORDER BY c.published_at DESC, c.id DESC LIMIT ' . max(1, min(60, $limit)),
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $slug = (string)($r['slug'] ?? '');
            $out[] = [
                'title' => self::trNewsTitle((int)$r['id'], (string)$r['title']),
                'slug' => $slug,
                'url' => $slug !== '' ? '/site/news/' . rawurlencode($slug) . '.html' : '/?p=' . (int)$r['id'],
                'date' => mb_substr((string)($r['published_at'] ?? $r['created_at'] ?? ''), 0, 10),
            ];
        }
        return $out;
    }

    /** 新闻源条件：可选限定博客分类 + 排除关键词（默认过滤博客系统公告，官网新闻不出现非本站内容） */
    private static function newsWhere(): array
    {
        $s = self::newsScope();
        return [$s['where'], array_merge($s['jp'], $s['wp']), $s['join']];
    }

    /**
     * 新闻源条件（拆分版）：JOIN 片段与其绑定参数、WHERE 片段与其绑定参数分列为四段。
     * newsWhere() 的合并版只能用于「JOIN 在前、WHERE 在后」的简单查询；一旦还要再叠加别的 JOIN
     * 条件（分类/标签/归档索引），参数顺序会错位，必须用这个拆分版按 SQL 里 ? 的实际出现顺序拼接。
     */
    private static function newsScope(): array
    {
        $join = ''; $jp = [];
        $cate = (int)self::opt('news.cate', '0');
        if ($cate > 0) {
            $join = ' JOIN ' . Db::t('relationships') . ' r ON r.content_id = c.id AND r.meta_id = ?';
            $jp[] = $cate;
        }
        $where = "c.type = 'post' AND c.status = 'publish' AND c.deleted_at IS NULL"; $wp = [];
        foreach (self::newsExclude() as $kw) {
            $where .= ' AND c.title NOT LIKE ?';
            $wp[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $kw) . '%';
        }
        return ['where' => $where, 'wp' => $wp, 'join' => $join, 'jp' => $jp];
    }

    /** 标题是否命中「排除关键词」：官网模式下不呈现非本站/博客系统默认内容 */
    private static function isExcluded(string $title): bool
    {
        foreach (self::newsExclude() as $kw) {
            if ($kw !== '' && mb_stripos($title, $kw) !== false) { return true; }
        }
        return false;
    }

    /**
     * 官网可见范围判定（官网模式下前台只服务官网内容，直链同样适用）：
     * - 命中「排除关键词」→ 不可见（挡住博客系统默认示例内容）；
     * - type=page（博客独立页）→ 默认不可见（官网有自己的页面体系：关于/联系/招聘等），
     *   需要额外页面时把 site.hide.kernel_pages 设为 0 即可放行；
     * - type=post → 配了「新闻分类」时须属于该分类，否则只受排除关键词约束。
     * 这样"什么算官网内容"由后台配置决定，而不是靠猜标题。
     */
    private static function visibleInOfficial(int $id, string $type, string $title): bool
    {
        if ($id <= 0) { return false; }
        if (self::isExcluded($title)) { return false; }
        if ($type === 'page') { return self::opt('hide.kernel_pages', '1') !== '1'; }
        $cate = (int)self::opt('news.cate', '0');
        if ($cate <= 0) { return true; }
        return (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('relationships') . ' WHERE content_id = ? AND meta_id = ?',
            [$id, $cate]) > 0;
    }

    private static function newsExclude(): array
    {
        $out = [];
        foreach (explode(',', self::opt('news.exclude', 'YeCha,夜叉')) as $k) {
            $k = trim($k);
            if ($k !== '') { $out[] = $k; }
        }
        return $out;
    }

    /* ---------- 官网口径的内核列表页（分类 / 标签 / 归档）：只呈现官网新闻范围内的内容 ---------- */

    /** 官网新闻列表的公共渲染（列表 + 条数） */
    private static function newsListHtml(array $rows, string $empty = '暂无内容。'): string
    {
        $h = '<ul class="sc-news sc-news-lg">';
        if (!$rows) { $h .= '<li class="sc-empty">' . e(self::trText($empty)) . '</li>'; }
        foreach ($rows as $r) {
            $slug = (string)($r['slug'] ?? '');
            $url = $slug !== '' ? '/site/news/' . rawurlencode($slug) . '.html' : '/?p=' . (int)$r['id'];
            $h .= '<li><a href="' . e($url) . '">' . e(self::trNewsTitle((int)($r['id'] ?? 0), (string)$r['title'])) . '</a><time>'
                . e(mb_substr((string)($r['published_at'] ?? $r['dt'] ?? ''), 0, 10)) . '</time></li>';
        }
        return $h . '</ul>';
    }

    /**
     * 官网新闻标题译文：新闻是内核 ye_contents 内容（不是官网自己的表），译文落在
     * site_i18n 的 item_type='news' / item_id=<content_id> / payload {title, content}。
     * 未翻译或中文站 → 原样返回。
     */
    private static function trNewsTitle(int $cid, string $title): string
    {
        $l = self::lang();
        if ($l === '' || $cid <= 0 || $title === '') { return $title; }
        $v = (string)(self::trFor('news', $cid, $l)['title'] ?? '');
        return $v !== '' ? $v : $title;
    }

    /** 分类 / 标签页：官网口径下只列官网新闻范围内、且属于该分类/标签的文章 */
    private static function newsByMeta(string $type, string $slug): array
    {
        $meta = Db::row('SELECT id, name FROM ' . Db::t('metas') . ' WHERE type = ? AND slug = ? LIMIT 1', [$type, $slug]);
        if ($meta === null) { return ['html' => '<p class="sc-empty">栏目不存在或已下线。</p>', 'name' => '', 'n' => 0]; }
        $s = self::newsScope();
        $rows = Db::rows(
            'SELECT DISTINCT c.id, c.slug, c.title, c.published_at FROM ' . Db::t('contents') . ' c' . $s['join']
            . ' JOIN ' . Db::t('relationships') . ' r2 ON r2.content_id = c.id AND r2.meta_id = ?'
            . ' WHERE ' . $s['where'] . ' ORDER BY c.published_at DESC, c.id DESC LIMIT 60',
            array_merge($s['jp'], [(int)$meta['id']], $s['wp'])
        );
        $name = (string)$meta['name'];
        $h = '<div class="page-head"><h1>' . e(self::trText($type === 'tag' ? '标签：' : '分类：') . $name) . '</h1>'
            . '<p class="hint">' . e(sprintf(self::trText('共 %d 条官网新闻'), count($rows))) . '</p></div>';
        return ['html' => $h . self::newsListHtml($rows, '该栏目下暂无官网新闻。'), 'name' => $name, 'n' => count($rows)];
    }

    /** 分类 / 标签索引：计数只统计官网新闻范围内的文章，避免把博客全站分类混进官网 */
    private static function newsMetaIndex(string $type): string
    {
        $s = self::newsScope();
        $rows = Db::rows(
            'SELECT m.id, m.name, m.slug, COUNT(DISTINCT c.id) AS n FROM ' . Db::t('metas') . ' m'
            . ' JOIN ' . Db::t('relationships') . ' r2 ON r2.meta_id = m.id'
            . ' JOIN ' . Db::t('contents') . ' c ON c.id = r2.content_id' . $s['join']
            . ' WHERE m.type = ? AND ' . $s['where']
            . ' GROUP BY m.id, m.name, m.slug HAVING n > 0 ORDER BY n DESC, m.id ASC LIMIT 200',
            array_merge($s['jp'], [$type], $s['wp'])
        );
        $base = $type === 'tag' ? '/tag/' : '/category/';
        $h = '<div class="page-head"><h1>' . ($type === 'tag' ? '新闻标签' : '新闻分类') . '</h1></div><div class="meta-index">';
        if (!$rows) { $h .= '<p class="sc-empty">暂无内容。</p>'; }
        foreach ($rows as $r) {
            $h .= '<a class="meta-chip" href="' . $base . e((string)$r['slug']) . '/">' . e((string)$r['name'])
                . '<i class="mi-count">' . (int)$r['n'] . '</i></a>';
        }
        return $h . '</div>';
    }

    /** 归档：官网口径下只列官网新闻范围内的文章，按月分组 */
    private static function newsArchives(int $year = 0, int $month = 0): string
    {
        $s = self::newsScope();
        $where = $s['where'];
        $wp = $s['wp'];
        if ($year > 0) {
            $where .= ' AND YEAR(c.published_at) = ?';
            $wp[] = $year;
            if ($month > 0) { $where .= ' AND MONTH(c.published_at) = ?'; $wp[] = $month; }
        }
        $rows = Db::rows(
            'SELECT DISTINCT c.id, c.slug, c.title, c.published_at FROM ' . Db::t('contents') . ' c' . $s['join']
            . ' WHERE ' . $where . ' ORDER BY c.published_at DESC, c.id DESC LIMIT 200',
            array_merge($s['jp'], $wp)
        );
        $head = $year > 0
            ? '<div class="page-head"><h1>' . e($month > 0
                    ? sprintf(self::trText('归档：%d 年 %d 月'), $year, $month)
                    : sprintf(self::trText('归档：%d 年'), $year)) . '</h1>'
              . '<p class="hint"><a href="/archives">' . e(self::trText('← 全部归档')) . '</a></p></div>'
            : '<div class="page-head"><h1>' . e(self::trText('新闻归档')) . '</h1><p class="hint">' . e(self::trText('按发布时间浏览官网新闻')) . '</p></div>';
        if (!$rows) { return $head . '<p class="sc-empty">该时间段暂无官网新闻。</p>'; }

        // 按月分组（列表已按时间倒序，顺序遍历即可）
        $groups = [];
        foreach ($rows as $r) {
            $ym = mb_substr((string)($r['published_at'] ?? ''), 0, 7);
            $groups[$ym][] = $r;
        }
        $h = $head;
        foreach ($groups as $ym => $items) {
            [$y, $m] = array_pad(explode('-', (string)$ym), 2, '0');
            $h .= '<section class="sc-sec"><h2><a href="/archives/' . (int)$y . '/' . (int)$m . '/">'
                . e((string)$ym) . '（' . count($items) . '）</a></h2>' . self::newsListHtml($items) . '</section>';
        }
        return $h;
    }

    /** 官网模式下的页面总览：默认列出官网自身的栏目（博客独立页已由「官网可见范围」挡在官网之外） */
    private static function pagesListPage(): string
    {
        if (self::opt('hide.kernel_pages', '1') === '1') {
            $sections = [
                ['/site', self::lex('nav_home', '首页')], ['/site/products', self::lex('product', '产品中心')], ['/site/cases', self::lex('case', '客户案例')],
                ['/site/news', self::lex('news', '新闻动态')], ['/site/about', self::lex('about', '关于我们')], ['/site/jobs', self::lex('jobs', '加入我们')],
                ['/site/contact', self::lex('contact', '联系我们')],
            ];
            $h = '<div class="page-head"><h1>网站页面</h1><p class="hint">官网栏目一览</p></div><ul class="sc-news sc-news-lg">';
            foreach ($sections as [$url, $label]) {
                $h .= '<li><a href="' . $url . '">' . e($label) . '</a></li>';
            }
            return $h . '</ul>';
        }
        $where = "type = 'page' AND status = 'publish' AND deleted_at IS NULL";
        $params = [];
        foreach (self::newsExclude() as $kw) {
            $where .= ' AND title NOT LIKE ?';
            $params[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $kw) . '%';
        }
        $rows = Db::rows('SELECT id, slug, title, published_at FROM ' . Db::t('contents') . " WHERE $where ORDER BY published_at DESC, id DESC LIMIT 100", $params);
        $h = '<div class="page-head"><h1>网站页面</h1></div><ul class="sc-news sc-news-lg">';
        if (!$rows) { $h .= '<li class="sc-empty">暂无页面。</li>'; }
        foreach ($rows as $r) {
            $slug = (string)($r['slug'] ?? '');
            $h .= '<li><a href="/' . e($slug) . '.html">' . e((string)$r['title']) . '</a><time>'
                . e(mb_substr((string)($r['published_at'] ?? ''), 0, 10)) . '</time></li>';
        }
        return $h . '</ul>';
    }

    /** 新闻详情正文（复用博客文章引擎的正文渲染，但不带博客划线/收藏等专属元素） */
    private static function articleBody(string $slug): array
    {
        // 演示预览：/site/news/demo-n<i>.html 直接取该行业样例新闻，不碰博客文章表
        if (self::demoOn()) {
            $n = self::dataNewsItem($slug);
            if (!$n) { return ['html' => '<p class="sc-empty">内容不存在或已下线。</p>', 'title' => '', 'missing' => true]; }
            $h = '<header class="sc-art-head"><h1>' . e((string)$n['title']) . '</h1><div class="sc-art-meta"><time>'
                . e((string)$n['date']) . '</time></div></header><div class="sc-content">' . (string)$n['html'] . '</div>'
                . '<p class="sc-art-back"><a class="sc-btn sc-btn-ghost" href="/site/news">← 返回' . e(self::lex('news')) . '</a></p>';
            return ['html' => $h, 'title' => (string)$n['title']];
        }
        if ($slug === '' || !class_exists('Front')) { return ['html' => '<p class="sc-empty">内容不存在。</p>', 'title' => '']; }
        $d = null;
        try { $d = \Front::post($slug); } catch (Throwable $e) { $d = null; }
        if (!is_array($d) || empty($d['post'])) { return ['html' => '<p class="sc-empty">内容不存在或已下线。</p>', 'title' => '', 'missing' => true]; }
        $r = (array)$d['post'];
        $title = (string)($r['title'] ?? '');
        // 官网模式：直链内容也要过「官网可见范围」判定，否则博客默认内容与其他业务线的文章
        // 仍能通过 /xxx.html 直达并出现在官网页面里。
        if (!self::visibleInOfficial((int)($r['id'] ?? 0), (string)($r['type'] ?? 'post'), $title)) {
            return ['html' => '<p class="sc-empty">内容不存在或已下线。</p>', 'title' => '', 'missing' => true];
        }
        $title = self::trNewsTitle((int)($r['id'] ?? 0), $title);
        $h = '<header class="sc-art-head">';
        $cats = (array)($d['cats'] ?? []);
        if ($cats) {
            $cs = [];
            foreach ($cats as $c) { $cs[] = e((string)($c['name'] ?? '')); }
            $h .= '<div class="sc-art-cat">' . implode(' · ', $cs) . '</div>';
        }
        $h .= '<h1>' . e($title) . '</h1><div class="sc-art-meta"><time>'
            . e(mb_substr((string)($r['published_at'] ?? $r['created_at'] ?? ''), 0, 10)) . '</time></div></header>';
        $h .= '<div class="sc-content">' . (string)($d['html'] ?? '') . '</div>';
        $tags = (array)($d['tags'] ?? []);
        if ($tags) {
            $h .= '<div class="sc-art-tags">';
            foreach ($tags as $t) { $h .= '<a href="/tag/' . e((string)($t['slug'] ?? '')) . '/">' . e((string)($t['name'] ?? '')) . '</a>'; }
            $h .= '</div>';
        }
        $h .= '<p class="sc-art-back"><a class="sc-btn sc-btn-ghost" href="/site/news">← 返回' . e(self::lex('news')) . '</a></p>';
        return ['html' => $h, 'title' => $title];
    }

    /* ================= 站内搜索（结构化检索 + AI 语义兜底） ================= */

    private static function searchPage(string $q): string
    {
        $q = trim($q);
        $h = '<form class="sc-search-page" method="get" action="/site/search" role="search">'
            . '<input type="search" name="q" value="' . e($q) . '" placeholder="搜索产品 / 案例 / 新闻，例如：耐高温密封件" autofocus>'
            . '<button type="submit">' . self::trText('搜索') . '</button></form>';
        if ($q === '') {
            return $h . '<p class="sc-empty">输入关键词即可搜索产品、案例与新闻；AI 已配置时还会给出智能回答。</p>';
        }
        // 1) 结构化命中（产品 / 案例 / 新闻）
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        if (self::demoOn()) {
            // 演示预览：在样例数组里做字面匹配，不查真实业务表
            $mq = mb_strtolower($q);
            $hitAll = static function (array $row) use ($mq): bool {
                foreach (['name', 'summary', 'content', 'params', 'industry', 'challenge', 'solution', 'result', 'title', 'excerpt'] as $k) {
                    if (isset($row[$k]) && mb_stripos((string)$row[$k], $mq) !== false) { return true; }
                }
                return false;
            };
            $prods = array_slice(array_filter(self::demoRows('products'), $hitAll), 0, 12);
            $cases = array_slice(array_filter(self::demoRows('cases'), $hitAll), 0, 6);
            $news  = [];
            foreach (array_filter(self::demoRows('news'), $hitAll) as $n) {
                $news[] = ['title' => (string)$n['title'], 'slug' => (string)$n['slug'], 'date' => (string)$n['date'], 'url' => '/site/news/' . $n['slug'] . '.html'];
            }
            $news = array_slice($news, 0, 8);
        } else {
            $prods = Db::rows("SELECT id, name, summary, image FROM " . self::t('product') . " WHERE is_show = 1 AND is_delete_time = 0 AND (name LIKE ? OR summary LIKE ? OR content LIKE ? OR params LIKE ?) ORDER BY sort DESC, id DESC LIMIT 12", [$like, $like, $like, $like]);
            $cases = Db::rows("SELECT id, name, industry, challenge, solution, result FROM " . self::t('case') . " WHERE is_show = 1 AND is_delete_time = 0 AND (name LIKE ? OR challenge LIKE ? OR solution LIKE ? OR result LIKE ?) ORDER BY sort DESC, id DESC LIMIT 6", [$like, $like, $like, $like]);
            $news = Db::rows("SELECT id, slug, title, published_at FROM " . Db::t('contents') . " WHERE type = 'post' AND status = 'publish' AND deleted_at IS NULL AND title LIKE ? ORDER BY published_at DESC LIMIT 8", [$like]);
            // 官网口径：上面这条只按标题模糊匹配，会把博客系统默认内容与其他业务线文章搜出来；
            // 这里用官网新闻范围再过滤一次（分类限定 + 排除关键词），保证搜索结果全部属于官网内容。
            $news = array_values(array_filter($news, static function ($n): bool {
                if (self::isExcluded((string)($n['title'] ?? ''))) { return false; }
                $cate = (int)self::opt('news.cate', '0');
                if ($cate <= 0) { return true; }
                return (int)Db::one('SELECT COUNT(*) FROM ' . Db::t('relationships') . ' WHERE content_id = ? AND meta_id = ?',
                    [(int)$n['id'], $cate]) > 0;
            }));
        }

        // 语义补充：字面 LIKE 没命中时，用知识库语义命中（如「耐多大压力」→「液压密封系统」）反查产品/案例，
        // 让自然语言提问也能出结构化结果（AI 未配置时静默跳过，退化为普通检索）。
        if (!self::demoOn() && (!$prods || !$cases) && class_exists('Ai') && \Plugins::isOn('ai') && Ai::isConfigured()) {
            $pNames = $cNames = [];
            try {
                foreach ((array)Ai::kbSearch($q, 6) as $hit) {
                    $t = trim((string)($hit['title'] ?? ''));
                    if ($t === '') { continue; }
                    if (preg_match('/^案例[:：]\s*(.+?)(?:[（(].*)?$/u', $t, $mm)) { $cNames[] = trim($mm[1]); }
                    else { $pNames[] = $t; }
                }
            } catch (Throwable $e) { /* 语义补充失败不影响主检索 */ }
            if (!$prods && $pNames) {
                $in = implode(',', array_fill(0, count($pNames), '?'));
                $prods = Db::rows("SELECT id, name, summary, image FROM " . self::t('product') . " WHERE is_show = 1 AND is_delete_time = 0 AND name IN ($in) ORDER BY sort DESC, id DESC LIMIT 8", $pNames);
            }
            if (!$cases && $cNames) {
                $in = array_fill(0, count($cNames), '?');
                $cases = Db::rows("SELECT id, name, industry, challenge, solution, result FROM " . self::t('case') . " WHERE is_show = 1 AND is_delete_time = 0 AND name IN (" . implode(',', $in) . ") ORDER BY sort DESC, id DESC LIMIT 6", $cNames);
            }
        }

        // 2) AI 语义检索（向量式关键词扩展 + 知识库全文），未配置 AI 时静默跳过
        $ai = self::aiAnswer($q);

        $total = count($prods) + count($cases) + count($news);
        $h .= '<p class="sc-search-stat">' . e(sprintf(self::trText('关键词「%s」命中 %d 条站内内容'), (string)$q, (int)$total))
            . ($ai !== '' ? e(self::trText('，并生成了 AI 回答')) : '') . '。</p>';
        if ($ai !== '') { $h .= '<section class="sc-ai-answer"><h3>AI 智能回答</h3><div class="sc-ai-body">' . $ai . '</div></section>'; }
        if ($total === 0) { return $h . '<p class="sc-empty">没有找到匹配内容，建议换用更宽泛的词，或直接留言咨询。</p>'; }

        if ($prods) {
            $h .= '<section class="sc-sec"><h2>相关产品（' . count($prods) . '）</h2><div class="sc-cards">';
            foreach ($prods as $p) {
                $img = self::themeImg((string)($p['image'] ?? ''));
                $h .= '<a class="sc-card" href="/site/product/' . (int)$p['id'] . '.html">';
                if ($img !== '') { $h .= '<div class="sc-card-img" style="background-image:url(' . e($img) . ')"></div>'; }
                $h .= '<div class="sc-card-body"><b>' . e((string)$p['name']) . '</b><span>' . e((string)($p['summary'] ?? '')) . '</span></div></a>';
            }
            $h .= '</div></section>';
        }
        if ($cases) {
            $h .= '<section class="sc-sec"><h2>相关案例（' . count($cases) . '）</h2><div class="sc-cards sc-cards-3">';
            foreach ($cases as $c) {
                $h .= '<a class="sc-card" href="/site/case/' . (int)$c['id'] . '.html"><div class="sc-card-body"><b>' . e((string)$c['name']) . '</b>'
                    . '<span>' . e((string)($c['industry'] ?? '')) . ' · ' . e(Str::excerpt((string)($c['result'] ?? $c['solution'] ?? ''), 60)) . '</span></div></a>';
            }
            $h .= '</div></section>';
        }
        if ($news) {
            $h .= '<section class="sc-sec"><h2>相关新闻（' . count($news) . '）</h2><ul class="sc-news sc-news-lg">';
            foreach ($news as $n) {
                $h .= '<li><a href="/site/news/' . e((string)$n['slug']) . '.html">' . e((string)$n['title']) . '</a><time>'
                    . e(mb_substr((string)($n['published_at'] ?? ''), 0, 10)) . '</time></li>';
            }
            $h .= '</ul></section>';
        }
        return $h;
    }

    /** AI 智能回答（知识库 RAG；未配置 AI / 无命中则返回空串，页面照常出结构化结果） */
    private static function aiAnswer(string $q): string
    {
        if (!class_exists('Ai') || !\Plugins::isOn('ai') || !Ai::isConfigured()) { return ''; }
        try {
            $ctx = Ai::kbContext($q);
            $sys = '你是' . self::company()['name'] . '官网的智能助手。请依据下面的企业知识库资料，用简洁中文回答访客的搜索问题；'
                . '资料没有涉及的就说"官网暂无相关资料，建议留言咨询"，不要编造。输出纯文本，不要使用 markdown 标记。';
            $ask = $ctx !== '' ? ("知识库资料：\n" . $ctx . "\n\n访客问题：" . $q) : $q;
            $r = Ai::chat([['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $ask]], ['scene' => 'search']);
            if (!empty($r['ok']) && trim((string)$r['text']) !== '') {
                return '<p>' . e(trim((string)$r['text'])) . '</p>';
            }
        } catch (Throwable $e) { /* AI 不可用不影响搜索 */ }
        return '';
    }

    private static function pageProducts(int $cateId): string
    {
        $cates = self::demoOn() ? [] : self::trAll('cate', Db::rows("SELECT id, name FROM " . self::t('product_category') . " WHERE is_show = 1 AND is_delete_time = 0 ORDER BY sort DESC, id ASC"));
        $products = self::dataProducts(60, $cateId);
        $h = self::flash() . '<div class="sc-with-side"><aside class="sc-side"><b>产品分类</b><ul>';
        $h .= '<li><a href="/site/products"' . ($cateId === 0 ? ' class="on"' : '') . '>全部</a></li>';
        foreach ($cates as $ct) {
            $h .= '<li><a href="/site/products?cate=' . (int)$ct['id'] . '"' . ($cateId === (int)$ct['id'] ? ' class="on"' : '') . '>' . e((string)$ct['name']) . '</a></li>';
        }
        $h .= '</ul></aside><div class="sc-cards">';
        if (!$products) { $h .= '<p class="sc-empty">暂无产品。</p>'; }
        foreach ($products as $p) {
            $h .= '<a class="sc-card" href="/site/product/' . (int)$p['id'] . '.html"><div class="sc-card-img"' . ($p['image'] !== '' ? ' style="background-image:url(' . e(self::themeImg((string)$p['image'])) . ')"' : '') . '></div><b>' . e((string)$p['name']) . '</b>' . ($p['summary'] !== '' ? '<span>' . e(mb_substr((string)$p['summary'], 0, 60)) . '</span>' : '') . '</a>';
        }
        $h .= '</div></div>';
        return $h;
    }

    private static function pageProductDetail(int $id): string
    {
        $p = self::dataProduct($id);
        if (!$p) { return '<p class="sc-empty">产品不存在或已下架。</p>'; }
        $h = self::flash() . '<article class="sc-detail"><h1>' . e((string)$p['name']) . '</h1>';
        if ($p['image'] !== '') { $h .= '<div class="sc-detail-img" style="background-image:url(' . e(self::themeImg((string)$p['image'])) . ')"></div>'; }
        if ($p['summary'] !== '') { $h .= '<p class="sc-lead">' . e((string)$p['summary']) . '</p>'; }
        // 参数表（每行「名称|值」）
        $lines = preg_split('/\r\n|\r|\n/', (string)($p['params'] ?? '')) ?: [];
        $params = [];
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '' || !str_contains($ln, '|')) { continue; }
            [$k, $v] = array_pad(explode('|', $ln, 2), 2, '');
            $params[] = [trim($k), trim($v)];
        }
        if ($params) {
            $h .= '<h3>技术参数</h3><table class="sc-table"><tbody>';
            foreach ($params as [$k, $v]) { $h .= '<tr><th>' . e($k) . '</th><td>' . e($v) . '</td></tr>'; }
            $h .= '</tbody></table>';
        }
        if ((string)($p['content'] ?? '') !== '') { $h .= '<div class="sc-content">' . self::richText((string)$p['content']) . '</div>'; }
        $h .= '<div class="sc-cta-box"><span>对该产品感兴趣？</span><a class="sc-btn sc-btn-primary" href="/site/contact">立即询盘</a></div></article>';
        return $h;
    }

    private static function pageCases(): string
    {
        $rows = self::dataCases(60);
        $h = self::flash() . '<div class="sc-cards sc-cards-3">';
        if (!$rows) { $h .= '<p class="sc-empty">暂无案例。</p>'; }
        foreach ($rows as $r) {
            $h .= '<a class="sc-card" href="/site/case/' . (int)$r['id'] . '.html"><b>' . e((string)$r['name']) . '</b><span>' . e((string)$r['industry']) . '</span><p>' . e(mb_substr((string)($r['challenge'] ?? ''), 0, 60)) . '</p></a>';
        }
        return $h . '</div>';
    }

    private static function pageCaseDetail(int $id): string
    {
        $r = self::dataCase($id);
        if (!$r) { return '<p class="sc-empty">案例不存在。</p>'; }
        $h = '<article class="sc-detail"><p class="sc-hero-eyebrow">' . e((string)$r['industry']) . '</p><h1>' . e((string)$r['name']) . '</h1>';
        foreach ([['客户挑战', 'challenge'], ['解决方案', 'solution'], ['成果', 'result']] as [$label, $key]) {
            if ((string)($r[$key] ?? '') !== '') { $h .= '<h3>' . $label . '</h3><div class="sc-content">' . self::richText((string)$r[$key]) . '</div>'; }
        }
        return $h . '</article>';
    }

    private static function pageNews(): string
    {
        $news = self::dataNews(20);
        $h = self::flash() . '<ul class="sc-news sc-news-lg">';
        if (!$news) { $h .= '<li class="sc-empty">暂无新闻。</li>'; }
        foreach ($news as $n) {
            $h .= '<li><a href="' . e((string)$n['url']) . '">' . e((string)$n['title']) . '</a><time>' . e((string)$n['date']) . '</time></li>';
        }
        return $h . '</ul>';
    }

    private static function pageAbout(): string
    {
        $c = self::company();
        $h = self::flash() . '<article class="sc-detail"><h1>关于 ' . e($c['name']) . '</h1>';
        if ($c['slogan'] !== '') { $h .= '<p class="sc-lead">' . e($c['slogan']) . '</p>'; }
        $h .= '<div class="sc-content">' . ($c['intro'] !== '' ? self::richText($c['intro']) : '<p class="sc-empty">企业简介待完善，请在后台填写。</p>') . '</div>';
        $h .= '<h3>联系方式</h3><table class="sc-table"><tbody>';
        foreach ([['电话', $c['phone']], ['邮箱', $c['email']], ['地址', $c['address']], ['微信', $c['wechat']]] as [$k, $v]) {
            if ($v !== '') { $h .= '<tr><th>' . $k . '</th><td>' . e($v) . '</td></tr>'; }
        }
        return $h . '</tbody></table></article>';
    }

    private static function pageJobs(): string
    {
        $jobs = self::dataJobs();
        $h = self::flash() . '<h1>' . e(self::lex('jobs')) . '</h1>';
        if (!$jobs) { return $h . '<p class="sc-empty">暂无招聘职位。</p>'; }
        foreach ($jobs as $j) {
            $h .= '<article class="sc-job"><header><b>' . e((string)$j['title']) . '</b><span>' . e((string)$j['place']) . ' · ' . e((string)$j['type']) . ($j['salary'] !== '' ? ' · ' . e((string)$j['salary']) : '') . '</span></header>';
            foreach ([['岗位职责', 'duty'], ['任职要求', 'requirement']] as [$label, $key]) {
                $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($j[$key] ?? '')) ?: [])));
                if ($lines) {
                    $h .= '<h4>' . $label . '</h4><ul>';
                    foreach ($lines as $ln) { $h .= '<li>' . e($ln) . '</li>'; }
                    $h .= '</ul>';
                }
            }
            $h .= '<details class="sc-apply"><summary>投递简历</summary><form method="post">' . \Csrf::field() . '<input type="hidden" name="act" value="apply"><input type="hidden" name="job_id" value="' . (int)$j['id'] . '">'
                . '<p><input type="text" name="name" placeholder="姓名" required> <input type="text" name="contact" placeholder="电话 / 邮箱" required></p>'
                . '<p><textarea name="content" rows="3" placeholder="自我介绍 / 简历要点" required></textarea></p>'
                . '<p><button class="sc-btn sc-btn-primary" type="submit">提交</button></p></form></details></article>';
        }
        return $h;
    }

    private static function pageContact(): string
    {
        $c = self::company();
        $h = self::flash() . '<div class="sc-with-side"><aside class="sc-side"><b>联系方式</b><ul class="sc-contact-list">';
        foreach ([['电话', $c['phone']], ['邮箱', $c['email']], ['地址', $c['address']], ['微信', $c['wechat']]] as [$k, $v]) {
            if ($v !== '') { $h .= '<li><i>' . $k . '</i>' . e($v) . '</li>'; }
        }
        $h .= '</ul></aside><div><h1>在线留言</h1>'
            . '<form method="post" class="sc-form">' . \Csrf::field() . '<input type="hidden" name="act" value="message">'
            . '<p><input type="text" name="name" placeholder="您的称呼 *" required> <input type="text" name="contact" placeholder="电话 / 邮箱 / 微信"></p>'
            . '<p><textarea name="content" rows="5" placeholder="请描述您的需求（产品、数量、应用场景等）*" required></textarea></p>'
            . '<p><button class="sc-btn sc-btn-primary" type="submit">提交留言</button></p></form></div></div>';
        return $h;
    }

    /* ================= 主题展示厅 /site/themes ================= */

    /**
     * 读取风格包的设计令牌（用于展示厅色板）。
     * 只看 themes/<id>/style.css 里 .sc-root 的 --sc-* 声明，缺项由调用方兜底。
     */
    private static function themeTokens(string $id): array
    {
        static $cache = [];
        if (isset($cache[$id])) { return $cache[$id]; }
        $out = [];
        $f = __DIR__ . '/themes/' . $id . '/style.css';
        if (is_file($f) && preg_match('/\.sc-root\s*\{([^}]*)\}/s', (string)file_get_contents($f), $m)) {
            foreach (['primary', 'accent', 'deep', 'bg', 'surface'] as $k) {
                if (preg_match('/--sc-' . $k . '\s*:\s*(#[0-9a-fA-F]{3,8})/', (string)$m[1], $x)) {
                    $out[$k] = strtolower($x[1]);
                }
            }
        }
        return $cache[$id] = $out;
    }

    /** 风格包预览图绝对路径（存在才在前台输出 <img>，缺失时退化为色板占位） */
    private static function themeShot(string $id): string
    {
        $rel = '/plugins/site/assets/themes/' . $id . '.jpg';
        return is_file(dirname(__DIR__, 2) . $rel) ? $rel : '';
    }

    /**
     * 主题展示厅：全部行业风格包一览，每张卡片可直达该风格的演示站点。
     * SSR：标题 / 说明 / 标签 / 色板 / 链接全部服务端直出，禁用 JS 也能看全；
     *      演示链接一律 target="_blank" + ?theme=<id>（只读预览，不写库、不改动站点当前风格）。
     * 外壳：不带企业官网的 header/导航/搜索/用户入口/页脚/AI 浮窗，只用中性的展示厅版式（tg-*）
     *      —— 这里的作用是「挑风格」，不该被某套企业皮肤的业务信息干扰。
     */
    private static function pageThemes(): string
    {
        $l  = self::lang();
        $lg = $l !== '' ? '&lang=' . rawurlencode($l) : '';
        $cur = self::theme();
        $n   = count(self::THEMES);

        $h = '<header class="tg-head">'
           . '<p class="tg-kicker">' . e(self::trText('企业官网 · 风格包')) . '</p>'
           . '<h1>' . e(self::trText('主题展示厅')) . '</h1>'
           . '<p class="tg-count">' . e(self::trText('共')) . '<b>' . $n . '</b>' . e(self::trText('款行业风格包')) . '</p>'
           . '<p class="tg-note">' . e(self::trText('点开任意一款即可在新窗口查看真实演示；每套都有各自的版式、配色与行业用词。')) . '</p>'
           . '</header><main class="tg-grid">';

        foreach (self::THEMES as $id => $label) {
            $m    = self::THEME_META[$id] ?? [];
            $tk   = self::themeTokens($id);
            $demo = '/site?theme=' . rawurlencode((string)$id) . $lg;
            $shot = self::themeShot((string)$id);
            $c1   = (string)($tk['primary'] ?? '#1e3a5f');
            $c2   = (string)($tk['accent']  ?? '#b26a1b');
            $c3   = (string)($tk['bg']      ?? '#ffffff');

            $h .= '<article class="tg-card">'
                . '<a class="tg-shot" href="' . e($demo) . '" target="_blank" rel="noopener"'
                . ' title="' . e(self::trText('在新窗口打开演示')) . '">';
            if ($shot !== '') {
                $h .= '<img src="' . e($shot) . '" alt="' . e((string)$label . ' · ' . self::trText('主题预览')) . '" width="640" height="400">';
            } else {
                $h .= '<span class="tg-ph" style="background:linear-gradient(135deg,' . e($c1) . ' 0%,' . e($c1) . ' 55%,' . e($c2) . ' 100%)"></span>';
            }
            $h .= '<span class="tg-hover">' . e(self::trText('打开演示 ↗')) . '</span></a>';

            $h .= '<div class="tg-body"><h2>' . e((string)$label)
                . ($id === $cur && !self::isThemePreview() ? '<i class="tg-badge">' . e(self::trText('当前启用')) . '</i>' : '') . '</h2>';

            if (!empty($m['use'])) {
                $h .= '<p class="tg-use"><i>' . e(self::trText('适用行业')) . '</i>' . e(self::trText((string)$m['use'])) . '</p>';
            }
            if (!empty($m['desc'])) {
                $h .= '<p class="tg-desc">' . e(self::trText((string)$m['desc'])) . '</p>';
            }
            if (!empty($m['tags'])) {
                $h .= '<ul class="tg-tags">';
                foreach ((array)$m['tags'] as $tg) { $h .= '<li>' . e(self::trText((string)$tg)) . '</li>'; }
                $h .= '</ul>';
            }
            $h .= '<p class="tg-pal"><i style="background:' . e($c1) . '"></i><i style="background:' . e($c2) . '"></i>'
                . '<i style="background:' . e($c3) . '"></i><em>' . e($c1) . ' · ' . e($c2) . '</em></p>';

            $h .= '<a class="tg-open" href="' . e($demo) . '" target="_blank" rel="noopener">'
                . e(self::trText('在新窗口打开演示')) . '</a><p class="tg-links">';
            foreach ([
                'products' => self::lex('product'),
                'cases'    => self::lex('case'),
                'news'     => self::lex('news'),
                'about'    => self::lex('about'),
                'contact'  => self::lex('contact'),
                'user'     => self::trText('用户中心'),
            ] as $seg => $txt) {
                $href = $seg === 'user' ? ('/user?theme=' . rawurlencode((string)$id) . $lg)
                                        : ('/site/' . $seg . '?theme=' . rawurlencode((string)$id) . $lg);
                $h .= '<a href="' . e($href) . '" target="_blank" rel="noopener">' . e($txt) . '</a>';
            }
            $h .= '</p></div></article>';
        }
        return $h . '</main>';
    }

    /** 展示厅外壳：只用 gallery.css（中性版式），不套企业头尾、不渲染 AI 浮窗 */
    private static function galleryShell(string $body): string
    {
        $css = (string)(is_file(__DIR__ . '/themes/gallery.css') ? file_get_contents(__DIR__ . '/themes/gallery.css') : '');
        $l = self::lang();
        $dir = ($l === 'ar' || $l === 'fa' || $l === 'he') ? 'rtl' : 'ltr';
        $h = '<style data-gallery>' . $css . '</style>'
           . '<div class="tg"' . ($l !== '' ? ' lang="' . e($l) . '"' : '') . ' dir="' . $dir . '">' . $body;
        // 底部：一行说明（换肤方式）+ 语言切换小链
        $h .= '<footer class="tg-foot"><div class="tg-foot-in"><span>'
            . e(self::trText('切换方式：后台「企业官网 → 风格包」一键保存，或在地址后追加')) . ' <code>?theme=business</code> '
            . e(self::trText('预览（不写库、不影响线上）。')) . '</span>' . self::galleryLangs() . '</div></footer></div>';
        return self::translateHtml($h);
    }

    /** 展示厅的语言切换（SSR 链接，独立于企业外壳的 langBar） */
    private static function galleryLangs(): string
    {
        if (self::opt('lang.switch', '1') !== '1') { return ''; }
        $cur = self::lang();
        $h = '<div class="tg-langs"><a href="/site/themes?lang=zh"' . ($cur === '' ? ' class="on"' : '') . '>中文</a>';
        foreach (self::LANGS as $code => $name) {
            $h .= '<a href="/site/themes?lang=' . $code . '"' . ($cur === $code ? ' class="on"' : '') . '>' . e($name) . '</a>';
        }
        return $h . '</div>';
    }

    /** 简单富文本：换行成段，转义后输出（M1 不引 markdown 引擎） */
    private static function richText(string $s): string
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $s) ?: [])));
        if (!$parts) { return ''; }
        $h = '';
        foreach ($parts as $p) { $h .= '<p>' . e($p) . '</p>'; }
        return $h;
    }

    /* ================= 布局（自包含 SSR 头尾 + 主题令牌内联） ================= */

    private static function layout(string $title, string $active, string $content): array
    {
        return ['html' => self::shellHtml($active, $content), 'title' => self::trText($title) . ' · ' . self::company()['name']];
    }

    /** 官网外壳 HTML（自包含 SSR 头尾 + 主题令牌内联）；/site/* 与「官网模式」接管的内核页面共用 */
    public static function shellHtml(string $active, string $content, string $extraHead = ''): string
    {
        $c = self::company();
        // 样式：公共骨架 _base.css + 行业风格包 themes/<id>/style.css（后者覆写令牌与品牌特征）
        $baseFile  = __DIR__ . '/themes/_base.css';
        $themeFile = __DIR__ . '/themes/' . self::theme() . '/style.css';
        $css = (is_file($baseFile) ? (string)file_get_contents($baseFile) : '')
             . (is_file($themeFile) ? (string)file_get_contents($themeFile) : '');
        $nav = '';
        foreach (self::nav() as $key => [$url, $label]) {
            $nav .= '<a href="' . $url . '"' . ($key === $active ? ' class="on"' : '') . '>' . $label . '</a>';
        }
        $contactBar = $c['phone'] !== '' ? '<span class="sc-top-phone">' . self::trText('咨询热线：') . e($c['phone']) . '</span>' : '';

        // 账户入口（登录态由内核 Auth 判定；异常时退回登录链接，不影响渲染）
        $logged = false; $uname = '';
        try {
            if (class_exists('Auth') && \Auth::check()) {
                $logged = true;
                $u = \Auth::user();
                $uname = (string)(($u['display_name'] ?? '') !== '' ? $u['display_name'] : ($u['username'] ?? ''));
            }
        } catch (Throwable $e) { $logged = false; }
        $back = (string)($_SERVER['REQUEST_URI'] ?? '/site');
        $userHref = $logged ? '/user' : '/login?redirect=' . rawurlencode($back);
        $userTxt = $logged ? ($uname !== '' ? $uname : self::trText('我的账户')) : self::trText('登录 / 注册');

        $h = '<style data-site>' . $css . '</style>';
        // 主题预览（?theme=）不写库也不该被收录，避免同页多版本进索引
        if (self::isThemePreview()) { $h .= '<meta name="robots" content="noindex,follow">'; }
        if ($extraHead !== '') { $h .= $extraHead; }
        // 外壳根元素带 lang / dir：内核的 <html lang> 由路由语言决定、插件改不动，
        // 但把 lang + dir 落在外壳根上即可让浏览器对该子树应用正确的语言与 RTL 排版。
        $l = self::lang();
        $dir = ($l === 'ar' || $l === 'fa' || $l === 'he') ? 'rtl' : 'ltr';
        $h .= '<div class="sc-root"' . ($l !== '' ? ' lang="' . e($l) . '"' : '') . ' dir="' . $dir . '">'
            . '<header class="sc-header"><div class="sc-header-in">'
            . '<a class="sc-logo" href="/site">' . e($c['name']) . '</a>'
            . '<nav class="sc-nav">' . $nav . '</nav>'
            . '<form class="sc-search" method="get" action="/site/search" role="search">'
            . '<input type="search" name="q" value="' . e((string)($_GET['q'] ?? '')) . '" placeholder="' . e(self::lex('search_ph')) . '" aria-label="站内搜索">'
            . '<button type="submit" aria-label="' . e(self::trText('搜索')) . '">' . self::trText('搜索') . '</button></form>'
            . '<a class="sc-user" href="' . e($userHref) . '">' . e($userTxt) . '</a>'
            . $contactBar
            . '</div></header><main class="sc-main">' . $content . '</main>';
        $h .= '<footer class="sc-footer"><div class="sc-footer-in">';
        $h .= '<div class="sc-footer-col"><b>' . e($c['name']) . '</b>' . ($c['slogan'] !== '' ? '<span>' . e($c['slogan']) . '</span>' : '') . '</div>';
        $h .= '<div class="sc-footer-col"><b>' . self::trText('快捷导航') . '</b>' . preg_replace('/class="on"/', '', $nav) . '</div>';
        $h .= '<div class="sc-footer-col"><b>' . e(self::lex('contact')) . '</b>' . ($c['phone'] !== '' ? '<span>' . self::trText('电话：') . e($c['phone']) . '</span>' : '') . ($c['email'] !== '' ? '<span>' . self::trText('邮箱：') . e($c['email']) . '</span>' : '') . ($c['address'] !== '' ? '<span>' . self::trText('地址：') . e($c['address']) . '</span>' : '') . '</div>';
        $h .= '</div><div class="sc-footer-base"><span>© ' . date('Y') . ' ' . e($c['name']) . ($c['icp'] !== '' ? ' · ' . e($c['icp']) : '')
            . ' · <a href="/site/themes">' . e(self::trText('主题风格展示厅')) . '</a></span>';
        // 多语言切换（SSR 链接；译文由后台 AI 翻译生成，切语言后服务端直接输出对应语言内容）
        if (self::opt('lang.switch', '1') === '1') { $h .= self::langBar(); }
        $h .= '</div></footer>';

        // AI 客服浮窗（交互增强；AI 插件开启且已配置才渲染）
        if (self::opt('ai.chat', '1') === '1' && class_exists('Ai') && \Plugins::isOn('ai') && Ai::isConfigured()) {
            $h .= self::chatWidget();
        }
        $h .= '</div>';
        // 多语言兜底：散落在页面文案里的界面长句按词典替换为当前语言（中文站零开销、幂等）
        return self::translateHtml($h);
    }

    /* ================= 官网模式：接管内核页面（主题覆盖全部前台页面） ================= */

    /** 官网模式接管的页面类型（内核 route kind） */
    public const COVER = [
        'home', 'post', 'meta_list', 'tags_index', 'cats_index', 'archives_overview', 'archives_list', 'pages_list',
        'search', 'login', 'register', 'user_profile', 'user_favorites', 'user_notes', 'user_notifications',
        '404',
    ];

    /** 官网模式开启（默认开启）：整站前台穿官网皮肤；关闭后仅 /site/* 适用，博客本体不受影响 */
    public static function isOfficial(): bool
    {
        return self::opt('mode', 'official') !== 'blog';
    }

    /** front.shell 钩子回调：内核页面 → 官网外壳（返回空数组表示不改动） */
    public static function shellFor(array $p): array
    {
        $kind = (string)($p['kind'] ?? '');
        if (!self::isOfficial() || !in_array($kind, self::COVER, true)) { return []; }
        $title = self::officialText(self::stripSiteLabel((string)($p['title'] ?? '')));
        $inner = (string)($p['main'] ?? '');
        $route = (array)($p['route'] ?? []);
        $c = self::company();

        if ($kind === 'home') {
            $body = self::pageHome();
            $full = $c['name'] . ($c['slogan'] !== '' ? ' · ' . $c['slogan'] : '');
            return ['shell' => self::shellHtml('home', $body), 'title' => $full, 'brand' => $c['name'],
                    'bg' => self::theme() === 'guofeng' ? '#f8f4ea' : '#ffffff',
                    'canonical' => '/site', 'meta' => ['description' => $c['seo_desc'], 'keywords' => $c['seo_kw']]]
                    + self::docLang();
        }

        $seg = self::crumb($kind, $route);           // [['首页','/site'], ['新闻动态','/site/news'], ['标题','']]
        $active = self::activeFor($kind);
        $head = self::crumbHtml($seg);
        $statusOut = 0;   // >0 时由内核设为该 HTTP 状态（如排除内容按 404 处理）

        switch ($kind) {
            case 'pages_list':
                $body = self::pagesListPage();
                break;
            case 'search':
                $body = self::searchPage((string)($p['query']['q'] ?? ''));
                $title = '站内搜索';
                break;
            case 'login':
            case 'register':
                $body = '<div class="sc-auth">' . $inner . '</div>';
                break;
            case 'user_profile':
            case 'user_favorites':
            case 'user_notes':
            case 'user_notifications':
                // 官网语境文案（内核用户中心沿用语是博客语义，这里做等价替换，不改内核语言包）
                $inner = self::officialText(strtr($inner, ['>读者<' => '>会员<']));
                $body = self::accountNav($kind, $inner);
                $title = $title !== '' ? $title : '我的账户';
                break;
            case 'post':
                $a = self::articleBody((string)($route['slug'] ?? ''));
                $body = '<article class="sc-detail sc-article">' . $head . $a['html'] . '</article>';
                if (!empty($a['missing'])) { $title = '内容不存在'; $statusOut = 404; }
                elseif ($a['title'] !== '') { $title = $a['title']; }
                break;
            // 内核的内容列表页在官网模式下不再直接复用博客列表（那会把博客系统默认内容与其他
            // 业务线的文章混进官网），改为按「官网新闻范围」重新查询渲染。
            case 'meta_list':
                $m = self::newsByMeta((string)($route['type'] ?? 'category'), (string)($route['slug'] ?? ''));
                $body = '<div class="sc-detail sc-inner">' . self::crumbHtml(self::crumbOfMeta($kind, $route, $m['name'])) . $m['html'] . '</div>';
                $title = ($m['name'] !== '' ? (($route['type'] === 'tag' ? '标签：' : '分类：') . $m['name']) : '栏目不存在');
                break;
            case 'cats_index':
            case 'tags_index':
                $body = '<div class="sc-detail sc-inner">' . $head . self::newsMetaIndex($kind === 'tags_index' ? 'tag' : 'category') . '</div>';
                $title = $kind === 'tags_index' ? '新闻标签' : '新闻分类';
                break;
            case 'archives_overview':
            case 'archives_list':
                $body = '<div class="sc-detail sc-inner">' . $head
                    . self::newsArchives((int)($route['year'] ?? 0), (int)($route['month'] ?? 0)) . '</div>';
                $title = '新闻归档';
                break;
            case '404':
                // 内核 404 页默认走博客主题（会露出博客品牌与侧栏），官网模式下统一下沉到官网 404
                $body = '<div class="sc-detail sc-inner"><div class="page-head"><h1>页面不存在</h1>'
                    . '<p class="hint">您访问的页面已下线或被移动，可从下方入口继续浏览。</p></div>'
                    . '<ul class="sc-news sc-news-lg">'
                    . '<li><a href="/site">返回官网首页</a></li>'
                    . '<li><a href="/site/products">产品中心</a></li>'
                    . '<li><a href="/site/news">新闻动态</a></li>'
                    . '<li><a href="/site/contact">联系我们</a></li>'
                    . '</ul></div>';
                $title = '页面不存在';
                $statusOut = 404;
                break;
            default:
                $body = '<div class="sc-detail sc-inner inner-' . preg_replace('/[^a-z_]/', '', $kind) . '">' . $head . $inner . '</div>';
        }
        return ['shell' => self::shellHtml($active, $body), 'title' => self::trText($title) . ' · ' . $c['name'],
                'brand' => $c['name'], 'bg' => self::theme() === 'guofeng' ? '#f8f4ea' : '#ffffff',
                'status' => $statusOut,
                'meta' => ['description' => $c['seo_desc'], 'keywords' => $c['seo_kw']]]
                + self::docLang();
    }

    /**
     * 文档级语言：把当前官网语言交给内核写进 <html lang> / dir。
     * 未启用多语言（中文）时返回空数组 —— 内核完全沿用原有取值，行为不变。
     */
    private static function docLang(): array
    {
        $l = self::lang();
        if ($l === '') { return []; }
        return [
            'htmlLang' => $l,
            'htmlDir' => ($l === 'ar' || $l === 'fa' || $l === 'he') ? 'rtl' : 'ltr',
        ];
    }

    /** 把当前官网语言写进内核全局（YC_HTML_LANG / YC_HTML_DIR），供 render_page 的 <html lang|dir> 使用 */
    private static function applyDocLang(): void
    {
        foreach (self::docLang() as $k => $v) {
            $GLOBALS['YC_' . strtoupper(preg_replace('/([A-Z])/', '_$1', (string)$k))] = $v;
        }
    }

    /**
     * 官网语境文案替换：内核用户中心沿用的博客语义在官网下替换为等价企业/会员语义。
     * 只做精确整串替换（不做模糊词替换，避免污染正文）。
     */
    private static function officialText(string $s): string
    {
        return self::trText(strtr($s, ['我的主页' => '我的账户', 'uc.role_reader' => '会员']));
    }

    /** 去掉内核标题里拼接的站点名（默认 YeCha），官网语境不出现非本站品牌字样 */
    private static function stripSiteLabel(string $title): string
    {
        foreach ([site_label(), site_name()] as $lbl) {
            $lbl = trim((string)$lbl);
            if ($lbl === '') { continue; }
            foreach ([' · ', ' - ', ' | '] as $sep) {
                if (str_ends_with($title, $sep . $lbl)) { $title = substr($title, 0, -strlen($sep . $lbl)); }
            }
        }
        return trim($title);
    }

    /** 面包屑 HTML（分段：[名称, 链接]，链接为空表示当前页） */
    private static function crumbHtml(array $seg): string
    {
        $h = '<nav class="sc-crumb">';
        foreach ($seg as $i => $it) {
            $h .= $i > 0 ? '<span>/</span>' : '';
            $h .= ($it[1] ?? '') !== '' ? '<a href="' . e($it[1]) . '">' . e($it[0]) . '</a>' : '<span>' . e($it[0]) . '</span>';
        }
        return $h . '</nav>';
    }

    /** 分类 / 标签页面包屑（用真实栏目名，不用 slug） */
    private static function crumbOfMeta(string $kind, array $route, string $name): array
    {
        $isTag = ($route['type'] ?? '') === 'tag';
        $label = $name !== '' ? $name : (string)($route['slug'] ?? '');
        return [['首页', '/site'], ['新闻动态', '/site/news'], [($isTag ? '标签：' : '分类：') . $label, '']];
    }

    /** 面包屑（官网语义：内核的文章/分类/标签/归档一律归入「新闻动态」） */
    private static function crumb(string $kind, array $route): array
    {        $home = ['首页', '/site'];
        return match ($kind) {
            'post'       => [$home, ['新闻动态', '/site/news']],
            'meta_list'  => [$home, ['新闻动态', '/site/news'], [(($route['type'] ?? '') === 'tag' ? '标签' : '分类') . '：' . (string)($route['slug'] ?? ''), '']],
            'tags_index' => [$home, ['新闻标签', '/site/news']],
            'cats_index' => [$home, ['新闻分类', '/site/news']],
            'archives_overview', 'archives_list' => [$home, ['新闻归档', '/site/news']],
            'pages_list' => [$home, ['页面', '/site/news']],
            default      => [$home],
        };
    }

    private static function activeFor(string $kind): string
    {
        return match ($kind) {
            'post', 'meta_list', 'tags_index', 'cats_index', 'archives_overview', 'archives_list' => 'news',
            'pages_list' => 'about',
            default => '',
        };
    }

    /** 账户边栏 + 内容容器（官网风格；功能链接复用内核 /user/*，表单与 CSRF 完全走内核） */
    private static function accountNav(string $kind, string $inner): string
    {
        $items = [
            'user_profile' => ['/user', '我的账户'],
            'user_favorites' => ['/user/favorites', '我的收藏'],
            'user_notifications' => ['/user/notifications', '我的消息'],
            'user_notes' => ['/user/notes', '我的笔记'],
        ];
        $h = '<div class="sc-account"><aside class="sc-account-side"><b>会员中心</b><ul>';
        foreach ($items as $k => [$url, $label]) {
            $h .= '<li><a href="' . $url . '"' . ($k === $kind ? ' class="on"' : '') . '>' . $label . '</a></li>';
        }
        $h .= '<li><a href="/site/jobs">' . self::trText('投递记录') . '</a></li><li><a class="sc-quit" href="/logout">' . self::trText('退出登录') . '</a></li></ul></aside>';
        return $h . '<div class="sc-account-main"><div class="sc-uc">' . $inner . '</div></div></div>';
    }

    /* ================= 官网模式：RSS / sitemap 只输出官网内容 ================= */

    /** 官网 XML（feed / sitemap）；非官网模式返回空串，交由内核 Feed 原样输出 */
    public static function xmlFor(string $kind): string
    {
        if (!self::isOfficial()) { return ''; }
        return $kind === 'feed' ? self::feedXml() : self::sitemapXml();
    }

    private static function baseUrl(): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $scheme = function_exists('yc_theme_scheme') ? (string)yc_theme_scheme() : 'http';
        return $scheme . '://' . $host;
    }

    private static function xesc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** 官网 sitemap：只含官网栏目 + 产品 / 案例 / 官网新闻范围内的文章 */
    private static function sitemapXml(): string
    {
        $base = self::baseUrl();
        $urls = ['/site', '/site/themes', '/site/products', '/site/cases', '/site/news', '/site/about', '/site/jobs', '/site/contact'];
        foreach (Db::rows('SELECT id FROM ' . self::t('product') . ' WHERE is_show = 1 AND is_delete_time = 0 ORDER BY id ASC') as $r) {
            $urls[] = '/site/product/' . (int)$r['id'] . '.html';
        }
        foreach (Db::rows('SELECT id FROM ' . self::t('case') . ' WHERE is_show = 1 AND is_delete_time = 0 ORDER BY id ASC') as $r) {
            $urls[] = '/site/case/' . (int)$r['id'] . '.html';
        }
        $s = self::newsScope();
        // 注意：MySQL 8 (ONLY_FULL_GROUP_BY) 下 DISTINCT 的 ORDER BY 列必须在 SELECT 列表里，
        // 所以这里必须把 published_at 一起选出来，否则整条语句报 3065。
        foreach (Db::rows('SELECT DISTINCT c.slug, c.published_at FROM ' . Db::t('contents') . ' c' . $s['join']
            . ' WHERE ' . $s['where'] . ' ORDER BY c.published_at DESC LIMIT 500', array_merge($s['jp'], $s['wp'])) as $r) {
            $slug = (string)($r['slug'] ?? '');
            if ($slug !== '') { $urls[] = '/site/news/' . rawurlencode($slug) . '.html'; }
        }
        $h = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $i => $u) {
            $h .= '<url><loc>' . self::xesc($base . $u) . '</loc><priority>' . ($i === 0 ? '1.0' : '0.8') . '</priority></url>' . "\n";
        }
        return $h . '</urlset>';
    }

    /** 官网 RSS：站点标题/描述用企业信息，条目只含官网新闻 */
    private static function feedXml(): string
    {
        $base = self::baseUrl();
        $c = self::company();
        $s = self::newsScope();
        $rows = Db::rows('SELECT DISTINCT c.id, c.slug, c.title, c.excerpt, c.published_at FROM ' . Db::t('contents') . ' c' . $s['join']
            . ' WHERE ' . $s['where'] . ' ORDER BY c.published_at DESC, c.id DESC LIMIT 20', array_merge($s['jp'], $s['wp']));
        $h = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0"><channel>'
            . '<title>' . self::xesc($c['name']) . '</title>'
            . '<link>' . self::xesc($base . '/site') . '</link>'
            . '<description>' . self::xesc($c['slogan'] !== '' ? $c['slogan'] : $c['name']) . '</description>'
            . '<language>zh-CN</language>'
            . '<lastBuildDate>' . gmdate('D, d M Y H:i:s') . ' +0000</lastBuildDate>';
        foreach ($rows as $r) {
            $url = $base . '/site/news/' . rawurlencode((string)($r['slug'] ?? '')) . '.html';
            $ts = strtotime((string)($r['published_at'] ?? '')) ?: time();
            $h .= '<item><title>' . self::xesc((string)$r['title']) . '</title>'
                . '<link>' . self::xesc($url) . '</link>'
                . '<guid isPermaLink="true">' . self::xesc($url) . '</guid>'
                . '<pubDate>' . gmdate('D, d M Y H:i:s', $ts) . ' +0000</pubDate>'
                . '<description>' . self::xesc(mb_substr((string)($r['excerpt'] ?? ''), 0, 200)) . '</description></item>';
        }
        return $h . '</channel></rss>';
    }

    /* ================= AI 内容助手（后台生成） + AI 多语言（译文落库 + 前台切换） ================= */

    /** 可翻译语言（代码 => 提示 AI 用的目标语言名） */
    public const LANGS = [
        'en' => 'English', 'ja' => '日本語', 'ko' => '한국어', 'es' => 'Español',
        'ru' => 'Русский', 'de' => 'Deutsch', 'fr' => 'Français', 'ar' => 'العربية',
    ];

    /** 可翻译内容项：表 + 可译字段 */
    public const TR_MAP = [
        'product' => ['table' => 'site_product', 'label' => '产品', 'fields' => ['name', 'summary', 'content', 'params']],
        'case'    => ['table' => 'site_case', 'label' => '案例', 'fields' => ['name', 'industry', 'challenge', 'solution', 'result']],
        'job'     => ['table' => 'site_job', 'label' => '职位', 'fields' => ['title', 'place', 'type', 'salary', 'duty', 'requirement']],
        'banner'  => ['table' => 'site_banner', 'label' => '横幅', 'fields' => ['title', 'sub']],
        'cate'    => ['table' => 'site_product_category', 'label' => '产品分类', 'fields' => ['name']],
    ];

    /** 当前请求语言：?lang=xx 命中白名单即生效并记忆；?lang=zh 清除（回到原文） */
    public static function lang(): string
    {
        // 演示预览（?theme=）固定中文：样例内容未提供译文，半翻译比不翻译更糟
        if (self::demoOn()) { return ''; }
        $l = strtolower(trim((string)($_GET['lang'] ?? '')));
        if ($l !== '') {
            if (isset(self::LANGS[$l])) { $_SESSION['site_lang'] = $l; return $l; }
            if ($l === 'zh' || $l === 'cn' || $l === 'default') { unset($_SESSION['site_lang']); return ''; }
        }
        $s = (string)($_SESSION['site_lang'] ?? '');
        return isset(self::LANGS[$s]) ? $s : '';
    }

    /** 语言切换链接（SSR：普通链接 + 服务端渲染，无 JS） */
    private static function langBar(): string
    {
        $cur = self::lang();
        $h = '<div class="sc-langs"><a href="?lang=zh"' . ($cur === '' ? ' class="on"' : '') . '>中文</a>';
        foreach (self::LANGS as $code => $name) {
            $h .= '<a href="?lang=' . $code . '"' . ($cur === $code ? ' class="on"' : '') . '>' . e($name) . '</a>';
        }
        return $h . '</div>';
    }

    /** 取译文（无译文返回空数组）；同请求内 memo */
    public static function tr(string $type, int $id): array
    {
        $l = self::lang();
        if ($l === '' || $id <= 0) { return []; }
        static $cache = [];
        $k = $type . ':' . $id . ':' . $l;
        if (!array_key_exists($k, $cache)) {
            $json = (string)Db::one('SELECT payload FROM ' . self::t('i18n') . ' WHERE item_type = ? AND item_id = ? AND lang = ? LIMIT 1', [$type, $id, $l]);
            $d = $json !== '' ? json_decode($json, true) : null;
            $cache[$k] = is_array($d) ? $d : [];
        }
        return $cache[$k];
    }

    /** 行数据套用译文（当前语言有译文时覆盖对应字段） */
    public static function trRow(string $type, array $row): array
    {
        foreach (self::tr($type, (int)($row['id'] ?? 0)) as $k => $v) {
            if (is_string($v) && $v !== '') { $row[$k] = $v; }
        }
        return $row;
    }

    /** 批量套用译文（列表页用；未切语言或未翻译时原样返回，零额外查询） */
    public static function trAll(string $type, array $rows): array
    {
        if (self::lang() === '' || !$rows) { return $rows; }
        foreach ($rows as $i => $r) { $rows[$i] = self::trRow($type, (array)$r); }
        return $rows;
    }

    /** 已翻译语言列表 */
    public static function translatedLangs(string $type, int $id): array
    {
        return array_map('strval', array_column(Db::rows('SELECT lang FROM ' . self::t('i18n') . ' WHERE item_type = ? AND item_id = ? ORDER BY lang', [$type, $id]), 'lang'));
    }

    /** AI 翻译一个内容项并落库（按字段逐个翻译，失败字段保留原文不写入） */
    public static function translateItem(string $type, int $id, string $lang): array
    {
        if (!isset(self::TR_MAP[$type]) || !isset(self::LANGS[$lang]) || $id <= 0) { return ['ok' => false, 'msg' => '参数错误']; }
        if (!self::aiReady()) { return ['ok' => false, 'msg' => 'AI 未配置：请先到「AI 能力」插件填写服务商 Key']; }
        $cfg = self::TR_MAP[$type];
        $row = Db::row('SELECT * FROM ' . Db::t($cfg['table']) . ' WHERE id = ? LIMIT 1', [$id]);
        if ($row === null) { return ['ok' => false, 'msg' => '记录不存在']; }
        $out = []; $fail = 0;
        foreach ($cfg['fields'] as $f) {
            $v = trim((string)($row[$f] ?? ''));
            if ($v === '') { continue; }
            $r = Ai::translate($v, self::LANGS[$lang], 'site.translate');
            if (!empty($r['ok']) && trim((string)$r['text']) !== '') { $out[$f] = trim((string)$r['text']); }
            else { $fail++; }
        }
        if (!$out) { return ['ok' => false, 'msg' => '翻译失败：AI 未返回内容']; }
        // 幂等 upsert（唯一键 item_type+item_id+lang）：并发调用也不会撞唯一键报错
        Db::run(
            'INSERT INTO ' . Db::t('site_i18n') . ' (item_type, item_id, lang, payload, engine, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE payload = VALUES(payload), engine = VALUES(engine), updated_at = VALUES(updated_at)',
            [$type, $id, $lang, json_encode($out, JSON_UNESCAPED_UNICODE), Ai::provider(), time()]
        );
        return ['ok' => true, 'msg' => $cfg['label'] . '已生成 ' . self::LANGS[$lang] . ' 译文（' . count($out) . ' 个字段' . ($fail > 0 ? '，' . $fail . ' 个失败' : '') . '）'];
    }

    /** 按指定语言取译文（不受当前请求语言影响，供后台/批量脚本用） */
    public static function trFor(string $type, int $id, string $lang): array
    {
        $json = (string)Db::one(
            'SELECT payload FROM ' . self::t('i18n') . ' WHERE item_type = ? AND item_id = ? AND lang = ? LIMIT 1',
            [$type, $id, $lang]
        );
        $d = $json !== '' ? json_decode($json, true) : null;
        return is_array($d) ? $d : [];
    }

    /** 写译文（幂等 upsert，并发安全） */
    public static function saveTr(string $type, int $id, string $lang, array $payload): void
    {
        Db::run(
            'INSERT INTO ' . self::t('i18n') . ' (item_type, item_id, lang, payload, engine, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE payload = VALUES(payload), engine = VALUES(engine), updated_at = VALUES(updated_at)',
            [$type, $id, $lang, json_encode($payload, JSON_UNESCAPED_UNICODE), Ai::provider(), time()]
        );
    }

    /**
     * 一键批量 AI 翻译：站点文案（企业名/口号/简介/地址/SEO/优势条）+ 全部产品/案例/职位/横幅。
     * 把多条打包成「编号 => 原文」的 JSON 一次请求，整站（含 12 套行业演示数据）约 40 次调用即可翻完。
     * 站点文案落在 item_type='site'/item_id=0（前台 optTr() 与 features() 自动套用），其余按记录写入。
     */
    /**
     * 已有译文索引：把 site_i18n 里某语言的 payload 摊平成 "type:id:field" => 1，
     * 供 translateAll 的「只补缺失」模式使用。
     */
    private static function trIndex(string $lang): array
    {
        static $cache = [];
        if (isset($cache[$lang])) { return $cache[$lang]; }
        $out = [];
        try {
            foreach (Db::rows('SELECT item_type, item_id, payload FROM ' . Db::t('site_i18n') . ' WHERE lang = ?', [$lang]) as $r) {
                $base = (string)$r['item_type'] . ':' . (int)$r['item_id'] . ':';
                $p = json_decode((string)($r['payload'] ?? ''), true);
                if (!is_array($p)) { continue; }
                foreach ($p as $k => $v) {
                    if (is_array($v)) {           // features：[{title,desc}, …]
                        foreach ($v as $i => $pair) {
                            foreach ((array)$pair as $f => $fv) {
                                if (trim((string)$fv) !== '') { $out[$base . $k . '.' . $i . '.' . $f] = 1; }
                            }
                        }
                    } elseif (trim((string)$v) !== '') {
                        $out[$base . $k] = 1;
                    }
                }
            }
        } catch (Throwable $e) { /* 表不存在等 → 视为无译文，走全量 */ }
        return $cache[$lang] = $out;
    }

    public static function translateAll(string $lang, int $batch = 15, bool $onlyMissing = true): array
    {
        if (!isset(self::LANGS[$lang])) { return ['ok' => false, 'msg' => '语言不支持']; }
        if (!self::aiReady()) { return ['ok' => false, 'msg' => 'AI 未配置：请先到「AI 能力」插件填写服务商 Key']; }

        // 1) 收集待译字段（$onlyMissing=true 时跳过已有译文的字段 —— 重跑即补缺，不重复烧 API）
        $have = self::trIndex($lang);
        $add = static function (array &$jobs, string $k, string $v) use ($have, $onlyMissing): void {
            if ($v === '') { return; }
            if ($onlyMissing && isset($have[$k])) { return; }
            $jobs[] = ['k' => $k, 't' => $v];
        };
        $jobs = [];
        foreach (self::SITE_TR_KEYS as $k) {
            $add($jobs, 'site:0:' . $k, trim(self::opt($k, '')));
        }
        foreach (self::features(false) as $i => $f) {
            foreach (['title', 'desc'] as $fld) {
                $add($jobs, 'site:0:features.' . $i . '.' . $fld, trim((string)($f[$fld] ?? '')));
            }
        }
        foreach (self::TR_MAP as $type => $cfg) {
            $rows = Db::rows('SELECT * FROM ' . Db::t($cfg['table']) . ' WHERE is_show = 1 AND is_delete_time = 0 ORDER BY id');
            foreach ($rows as $r) {
                foreach ($cfg['fields'] as $f) {
                    $add($jobs, $type . ':' . (int)$r['id'] . ':' . $f, trim((string)($r[$f] ?? '')));
                }
            }
        }
        // 官网新闻标题（内核 ye_contents；正文是渲染后的 HTML，翻译会破坏标签结构，故只翻标题）
        // 注意：DISTINCT 查询必须把 ORDER BY 用到的列一并 SELECT，否则 MySQL 8 报 3065
        [$nw, $nwp, $nwj] = self::newsWhere();
        foreach (Db::rows(
            'SELECT DISTINCT c.id, c.title, c.published_at FROM ' . Db::t('contents') . ' c' . $nwj
            . ' WHERE ' . $nw . ' ORDER BY c.published_at DESC LIMIT 60',
            $nwp
        ) as $r) {
            $add($jobs, 'news:' . (int)$r['id'] . ':title', trim((string)($r['title'] ?? '')));
        }
        if (!$jobs) {
            return ['ok' => true, 'msg' => self::LANGS[$lang] . ' 已全部翻译完成（' . count($have) . ' 个字段均有译文）'];
        }

        // 2) 分批翻译（编号映射，避免顺序错位）
        $target = self::LANGS[$lang];
        $sys = '你是企业官网本地化译者。用户给出一个 JSON 对象（编号 => 中文文本），'
            . '请把每个值翻译成' . $target . '，返回 JSON 对象，键与输入编号完全一致，值为译文。'
            . '只输出 JSON，不要 markdown 代码块，不要解释。'
            . '企业名用音译；保留数字、单位、认证代号（如 ISO 9001）与专有名词；译文简洁、符合官网语气。';
        $okCount = 0; $failCount = 0; $byItem = [];
        foreach (array_chunk($jobs, $batch) as $chunk) {
            $in = [];
            foreach ($chunk as $i => $j) { $in[(string)$i] = $j['t']; }
            $r = Ai::chat([
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user', 'content' => json_encode($in, JSON_UNESCAPED_UNICODE)],
            ], ['scene' => 'site.translate.batch', 'temperature' => 0.2, 'max_tokens' => 8192]);
            $got = [];
            if (!empty($r['ok'])) {
                $txt = (string)$r['text'];
                $txt = preg_replace('/^```(?:json)?|```$/m', '', $txt);
                if (preg_match('/\{.*\}/s', $txt, $m)) { $txt = $m[0]; }
                $d = json_decode($txt, true);
                if (is_array($d)) { $got = $d; }
            }
            foreach ($chunk as $i => $j) {
                $v = trim((string)($got[(string)$i] ?? ''));
                if ($v === '') { $failCount++; continue; }
                [$type, $id, $field] = explode(':', $j['k'], 3);
                $byItem[$type . ':' . $id][$field] = $v;
                $okCount++;
            }
        }

        // 3) 归并写库（site 的优势条要还原成嵌套数组）
        $written = 0;
        foreach ($byItem as $key => $fields) {
            [$type, $id] = explode(':', $key, 2);
            $id = (int)$id;
            $payload = self::trFor($type, $id, $lang);
            foreach ($fields as $f => $v) {
                if (preg_match('/^features\.(\d+)\.(title|desc)$/', $f, $m)) {
                    $payload['features'][(int)$m[1]][$m[2]] = $v;
                } else {
                    $payload[$f] = $v;
                }
            }
            if (isset($payload['features']) && is_array($payload['features'])) { ksort($payload['features']); }
            self::saveTr($type, $id, $lang, $payload);
            $written++;
        }
        return ['ok' => true, 'msg' => '已生成 ' . $target . ' 译文：' . $okCount . ' 个字段 / ' . $written . ' 条记录'
            . ($failCount > 0 ? '，' . $failCount . ' 个失败' : '')];
    }

    /** AI 内容助手：为内容项生成指定字段并写回 */
    public static function genField(string $type, int $id, string $field): array
    {
        if (!isset(self::TR_MAP[$type]) || $id <= 0) { return ['ok' => false, 'msg' => '参数错误']; }
        if (!self::aiReady()) { return ['ok' => false, 'msg' => 'AI 未配置：请先到「AI 能力」插件填写服务商 Key']; }
        $table = Db::t(self::TR_MAP[$type]['table']);
        $row = Db::row('SELECT * FROM ' . $table . ' WHERE id = ? LIMIT 1', [$id]);
        if ($row === null) { return ['ok' => false, 'msg' => '记录不存在']; }
        $c = self::company();
        $brand = $c['name'] . ($c['intro'] !== '' ? '（' . Str::excerpt($c['intro'], 80) . '）' : '');
        $sys = '你是资深企业官网内容编辑。为「' . $brand . '」撰写中文官网文案：只输出正文，不要标题前缀、不要 markdown 标记、不要解释，用书面简体中文。';
        $patch = []; $label = ''; $ask = '';

        if ($type === 'product') {
            $name = (string)$row['name'];
            $extra = trim((string)$row['params']);
            if ($field === 'summary') {
                $ask = "产品名称：{$name}\n" . ($extra !== '' ? "技术参数：{$extra}\n" : '') . '请写一句 40-60 字的产品简介，突出用途、性能与适用场景，不要口号式空话。';
                $patch = ['summary' => '']; $label = '产品简介';
            } elseif ($field === 'content') {
                $ask = "产品名称：{$name}\n" . ($extra !== '' ? "技术参数：{$extra}\n" : '') . '请写产品详情介绍，依次写「一、产品概述」「二、结构与材质」「三、适用场景」「四、质量保障」四个小节，每节 2-3 句，全文 300-450 字。';
                $patch = ['content' => '']; $label = '产品详情';
            }
        } elseif ($type === 'case') {
            $name = (string)$row['name'];
            $ind = (string)$row['industry'];
            if ($field === 'challenge') {
                $ask = "客户：{$name}（{$ind}行业）\n请写 60-90 字的客户痛点/挑战，具体、可量化（可合理举例交期、成本、故障率等）。";
                $patch = ['challenge' => '']; $label = '案例·挑战';
            } elseif ($field === 'solution') {
                $ask = "客户：{$name}（{$ind}行业），痛点：" . Str::excerpt((string)$row['challenge'], 120) . "\n请写 70-110 字的我们的解决方案，说明具体做法与工艺/服务动作。";
                $patch = ['solution' => '']; $label = '案例·方案';
            } elseif ($field === 'result') {
                $ask = "客户：{$name}（{$ind}行业），方案：" . Str::excerpt((string)$row['solution'], 120) . "\n请写 50-80 字的成果，用可量化指标表述（交期缩短、成本下降、故障率降低等）。";
                $patch = ['result' => '']; $label = '案例·成果';
            }
        } elseif ($type === 'job') {
            $name = (string)$row['title'];
            if ($field === 'duty') {
                $ask = "职位：{$name}\n请写 4 条岗位职责，每条一行、以「- 」开头，每条 12-25 字。";
                $patch = ['duty' => '']; $label = '岗位职责';
            } elseif ($field === 'requirement') {
                $ask = "职位：{$name}\n请写 4 条任职要求，每条一行、以「- 」开头，每条 12-25 字。";
                $patch = ['requirement' => '']; $label = '任职要求';
            }
        }
        if (!$patch || $ask === '') { return ['ok' => false, 'msg' => '该字段暂不支持 AI 生成']; }

        $r = Ai::chat([['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $ask]], ['scene' => 'site.write', 'max_tokens' => 4000, 'temperature' => 0.7]);
        if (empty($r['ok']) || trim((string)$r['text']) === '') { return ['ok' => false, 'msg' => '生成失败：' . (string)($r['err'] ?? 'AI 无返回')]; }
        $text = trim((string)$r['text']);
        $text = trim((string)preg_replace('/^\s*(#{1,6}\s*|【[^】]*】\s*)/mu', '', $text));
        $k = array_key_first($patch);
        Db::update(self::TR_MAP[$type]['table'], [$k => $text], 'id = ?', [$id]);
        return ['ok' => true, 'msg' => $label . '已生成并写入（' . mb_strlen($text) . ' 字），可在下方表单继续编辑后保存'];
    }

    private static function aiReady(): bool
    {
        return class_exists('Ai') && \Plugins::isOn('ai') && Ai::isConfigured();
    }

    /** AI 客服浮窗：主体文案 SSR；JS 只负责开合与请求 */
    private static function chatWidget(): string
    {
        $js = <<<'JS'
(function(){
  var b=document.getElementById('sc-chat-btn'),p=document.getElementById('sc-chat-panel'),x=document.getElementById('sc-chat-close'),f=document.getElementById('sc-chat-form'),i=document.getElementById('sc-chat-input'),l=document.getElementById('sc-chat-list'),sid='';
  function open(){p.style.display=p.style.display==='flex'?'none':'flex';if(p.style.display==='flex')i.focus();}
  // 模型偶发输出 Markdown 加粗 → 先转义再渲染，避免星号原样露出（换行由 CSS pre-wrap 保留）
  function md(t){return String(t).replace(/[&<>"]/g,function(c){return c==='&'?'&amp;':c==='<'?'&lt;':c==='>'?'&gt;':'&quot;';}).replace(/\*\*([^*]+)\*\*/g,'<b>$1</b>');}
  if(b)b.onclick=open; if(x)x.onclick=function(){p.style.display='none';};
  if(f)f.onsubmit=function(ev){ev.preventDefault();var m=i.value.trim();if(!m)return;i.value='';
    var u=document.createElement('div');u.className='sc-msg sc-msg-user';u.textContent=m;l.appendChild(u);l.scrollTop=l.scrollHeight;
    fetch('/ai/api/chat',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({sid:sid,message:m})})
      .then(function(r){return r.json();}).then(function(d){
        if(d.sid)sid=d.sid;
        var a=document.createElement('div');a.className='sc-msg sc-msg-ai';a.innerHTML=md(d.ok?d.reply:(d.err||'服务暂时不可用'));l.appendChild(a);l.scrollTop=l.scrollHeight;
      }).catch(function(){var a=document.createElement('div');a.className='sc-msg sc-msg-ai';a.textContent='网络异常，请稍后再试';l.appendChild(a);});
  };
})();
JS;
        return '<button id="sc-chat-btn" type="button" aria-label="' . e(self::trText('在线咨询')) . '">' . self::trText('在线咨询') . '</button>'
            . '<div id="sc-chat-panel" style="display:none"><div class="sc-chat-head"><b>' . self::trText('在线咨询') . '</b><button id="sc-chat-close" type="button" aria-label="关闭">×</button></div>'
            . '<div id="sc-chat-list"><div class="sc-msg sc-msg-ai">您好，我是 AI 客服，请描述您的问题。</div></div>'
            . '<form id="sc-chat-form"><input id="sc-chat-input" type="text" placeholder="输入您的问题…" autocomplete="off"><button type="submit">发送</button></form></div>'
            . '<script>' . $js . '</script>';
    }
}

/* 启用即加载（plugin.php register_file = lib.php）：注册内核 front.shell 钩子 ——
   官网模式下把官网主题外壳套到任意内核前台页面（首页/文章/分类/归档/独立页/搜索/登录注册/用户中心），
   使「主题覆盖全部页面」且页面不出现宿主博客默认品牌字样。关闭官网模式（site.mode=blog）则完全不介入。 */
if (class_exists('Hooks')) {
    \Hooks::on('front.shell', static function (array $p): array {
        try {
            if (!class_exists('Site') || !\Plugins::isOn('site')) { return $p; }
            $r = Site::shellFor($p);
            if ($r !== [] && !empty($r['shell'])) {
                $p['shell'] = (string)$r['shell'];
                $p['title'] = (string)($r['title'] ?? '');
                if (!empty($r['canonical'])) { $p['canonical'] = (string)$r['canonical']; }
                if (!empty($r['brand'])) { $p['brand'] = (string)$r['brand']; }
                if (!empty($r['bg'])) { $p['bg'] = (string)$r['bg']; }
                if (!empty($r['status'])) { $p['status'] = (int)$r['status']; }
                if (!empty($r['meta'])) { $p['meta'] = (array)$r['meta']; }
                foreach (['htmlLang', 'htmlDir'] as $dk) {
                    if (!empty($r[$dk])) { $p[$dk] = (string)$r[$dk]; }
                }
            }
        } catch (Throwable $e) { /* 接管失败回落博客原样渲染，绝不让官网插件弄挂站点 */ }
        return $p;
    }, 10);

    // 机器端点接管（RSS / sitemap）：官网模式下只输出官网内容
    \Hooks::on('front.xml', static function (array $p): array {
        try {
            if (!class_exists('Site') || !\Plugins::isOn('site')) { return $p; }
            $kind = (string)($p['kind'] ?? '');
            if ($kind !== 'feed' && $kind !== 'sitemap') { return $p; }
            $xml = Site::xmlFor($kind);
            if ($xml !== '') { $p['xml'] = $xml; }
        } catch (Throwable $e) { /* 接管失败回落内核 Feed */ }
        return $p;
    }, 10);
}
