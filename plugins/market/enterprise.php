<?php
declare(strict_types=1);
/**
 * 企业主题栏目（/enterprise，与 /theme 并行）
 *
 * 数据来源（本机两套演示站，均已核对落盘位置）：
 *  1) 企业官网风格包 12 款 —— 本机 8090 企业官网演示站 `/site/themes` 展示厅；
 *     实现为 plugins/site 的「一个 _base.css 骨架 + 12 份令牌/profile」，不是 12 个独立主题。
 *     缩略图取线上既有 plugins/site/assets/themes/<id>.jpg（官网已随 site 插件部署）。
 *  2) 主题广场演示站 30 套 —— 本机 yecha-astro「主题广场」演示站 app/themes/<id>；
 *     这 30 套已合入官网主题目录，卡片直接复用 /theme/<id> 的详情与缩略图，不重复造数据。
 *
 * 铁律：主体内容全部由 PHP 直出（无 JS 渲染）；JS 仅用于交互增强，本页不使用。
 * 双语：沿用 showcase.php 的 yc_sc_en() / yc_sc_t()，英文站链接带 /en 前缀。
 */

/** 12 款企业官网行业风格包：id => [中文名, 英文名, 中文行业, 英文行业, 中文气质, 英文气质, 视觉标签, 主色, 强调色] */
function yc_ent_styles(): array
{
    return [
        'business'  => ['商务稳重', 'Corporate Classic', '机械制造 · 工业设备 · 通用 B2B · 外贸出口', 'Machinery · industrial equipment · B2B · export', '偏稳重保守，突出资质、产能与交付能力，适合以「信任」为第一诉求的客户。', 'Conservative and grounded: credentials, capacity and delivery first, for trust-driven clients.', '深藏青 · 暖赭橙点缀 · 小圆角 · 细线无阴影', '#1e3a5f', '#b26a1b'],
        'guofeng'   => ['国风自然', 'Natural Heritage', '茶叶 · 文创礼品 · 地理标志农产品 · 文旅', 'Tea · cultural gifts · regional produce · tourism', '留白与韵律并重，适合强调产地、工艺与人文故事的品牌叙事。', 'Generous whitespace and rhythm for origin, craft and human stories.', '暖米底 · 赭石墨字 · 留白疏朗 · 宋楷标题', '#3e5c47', '#6b5d3f'],
        'tech'      => ['科技极简', 'Tech Minimal', 'SaaS · 软件开发 · 互联网平台 · AI 服务', 'SaaS · software · platforms · AI services', '信息密度低、对比强，适合需要快速讲清产品价值的技术型公司。', 'Low density, high contrast; explains product value fast.', '靛蓝主色 · 渐变光晕 · 大圆角 · 轻投影卡片', '#2563eb', '#06b6d4'],
        'law'       => ['律政庄重', 'Legal Gravitas', '律师事务所 · 审计税务 · 管理咨询 · 知识产权', 'Law firms · audit · consulting · IP', '字重与间距更克制，强调专业、严谨与保密感，适合高决策成本的服务。', 'Restrained type and spacing: professional, rigorous, discreet.', '深灰蓝 · 衬线标题 · 宽行距 · 金色细线', '#7b2d33', '#a8834a'],
        'medical'   => ['医疗洁净', 'Medical Clean', '医院科室 · 口腔诊所 · 生物医药 · 健康管理', 'Hospitals · dental · biopharma · health management', '低饱和蓝绿配大面积留白，传达洁净、安全与专业照护。', 'Muted blue-green with wide whitespace: clean, safe, professional care.', '纯白底 · 湖蓝绿 · 柔和圆角 · 大片留白', '#0e7490', '#0ea5a4'],
        'education' => ['教育活力', 'Education Energy', '培训机构 · 学校 · 知识付费 · 留学服务', 'Training · schools · online courses · study abroad', '高对比暖色配醒目行动按钮，适合转化导向的课程与招生场景。', 'High-contrast warm colors and strong calls to action for enrollment.', '明快橙黄 · 大圆角 · 强 CTA · 插画位多', '#2f6df6', '#ff9f1c'],
        'food'      => ['餐饮暖香', 'Food Warmth', '餐厅 · 烘焙 · 食品工厂 · 食品供应链', 'Restaurants · bakeries · food plants · supply chain', '产品图前置、食欲色系，适合以图片驱动决策的餐饮与食品。', 'Image-first, appetite-driven palette for food businesses.', '焦糖棕 · 暖橙 · 大图优先 · 圆润字形', '#c2410c', '#d99a2b'],
        'estate'    => ['建筑地产', 'Architecture & Realty', '建筑工程 · 房地产开发 · 空间设计 · 装修装饰', 'Construction · real estate · spatial design · interiors', '直线条与大尺度图片语言，强调空间、体量感与项目履历。', 'Straight lines and large imagery: space, scale and project record.', '石灰岩色 · 横向长条 · 分栏式图片 · 硬朗直线', '#2f3437', '#b08d4f'],
        'finance'   => ['金融信赖', 'Finance Trust', '银行 · 证券 · 保险 · 资产管理', 'Banking · securities · insurance · asset management', '数字与表格位权重更高，强调稳健、合规与数据披露。', 'Numbers and tables lead: stability, compliance, disclosure.', '深海军蓝 · 金色强调 · 数字突出 · 表格友好', '#103a6b', '#c8a04a'],
        'energy'    => ['能源环保', 'Energy & Green', '光伏储能 · 环保工程 · 新能源汽车 · 双碳服务', 'Solar & storage · environmental engineering · EV · carbon', '青绿与渐变的清洁意象，适合强调减碳、效率与技术参数。', 'Clean green gradients for carbon, efficiency and technical specs.', '青绿渐变 · 科技蓝 · 数据条 · 浅色底', '#16784f', '#2a9dd6'],
        'logistics' => ['物流动感', 'Logistics Motion', '物流运输 · 仓储配送 · 货运代理 · 供应链', 'Freight · warehousing · forwarding · supply chain', '斜线与速度感的版式语言，强调时效、网络覆盖与运力。', 'Diagonals and motion: transit time, coverage and capacity.', '橙色主色 · 斜切版式 · 速度线 · 强对比', '#1257a6', '#f07a1a'],
        'creative'  => ['创意前卫', 'Creative Edge', '品牌设计 · 广告公司 · 文创 · 影视制作', 'Branding · agencies · cultural creative · film', '深色底配荧光撞色，作品集与工作室调性强烈，适合强调审美的创意团队。', 'Dark base with neon accents, portfolio-first for design studios.', '近黑底 · 荧光黄绿 · 极小圆角 · 大字排版', '#d8ff3e', '#8b7bff'],
    ];
}

/** 主题广场演示站（yecha-astro/app/themes）的 30 套主题 id */
function yc_ent_square_ids(): array
{
    return ['antai', 'astra', 'duyi', 'forest', 'hanyan', 'help', 'huangyu', 'kejiyuan', 'kuake', 'liuchuan',
        'lvjian', 'mayu', 'mobai', 'octern', 'paiji', 'pidan', 'pinyun', 'pyq', 'qichu', 'rongguang',
        'teadoc', 'tianqing', 'wenshu', 'xinglan', 'xinlv', 'yecha', 'yuesi', 'yunku', 'zhijian', 'zhiku'];
}

/** /enterprise 页面（由 plugins/market/page.php 分发） */
function yc_enterprise_page(): array
{
    $GLOBALS['YC_NO_SIDE'] = true;
    $GLOBALS['YC_SB_TYPE'] = 'page';
    $lp = yc_sc_en() ? '/en' : '';
    $styles = yc_ent_styles();
    $all = yc_sc_themes();
    $square = [];
    foreach (yc_ent_square_ids() as $id) {
        if (isset($all[$id])) { $square[$id] = $all[$id]; }
    }

    $GLOBALS['YC_META'] = [
        'description' => yc_sc_t(
            'YeCha 企业主题：' . count($styles) . ' 款企业官网行业风格包 + ' . count($square) . ' 套主题广场演示站主题，与常规主题目录并行。',
            'YeCha enterprise themes: ' . count($styles) . ' industry style packs and ' . count($square) . ' theme-square demos, alongside the regular theme directory.'
        ),
        'keywords' => '',
    ];

    $css = '<style>'
        . '.et-wrap{max-width:1180px;margin:0 auto}'
        . '.et-crumb{font-size:13px;color:#8a8a80;margin:4px 0 14px}.et-crumb a{color:inherit}'
        . '.et-head{padding:6px 0 4px}.et-head h1{font-size:32px;margin:0 0 8px;letter-spacing:.5px}'
        . '.et-head .et-lead{font-size:15px;line-height:1.9;color:#5f666b;max-width:860px;margin:0}'
        . '.et-kpi{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0 4px}'
        . '.et-kpi a,.et-kpi span{border:1px solid var(--line,#e6e6e2);background:#fff;border-radius:12px;padding:8px 16px;text-decoration:none;color:inherit;font-size:13.5px;line-height:1.5}'
        . '.et-kpi b{font-size:19px;color:#AC4429;margin-right:6px}'
        . '.et-sec{margin:28px 0 0;border:1px solid var(--line,#e6e6e2);border-radius:18px;background:#fbfaf7;padding:20px 20px 16px}'
        . '.et-sec h2{font-size:21px;margin:0 0 6px}.et-sec h2 em{font-style:normal;color:#AC4429;font-size:14px;margin-left:8px}'
        . '.et-sec .et-desc{font-size:13.5px;color:#6f767e;line-height:1.85;margin:0 0 16px;max-width:900px}'
        . '.et-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}'
        . '.et-card{border:1px solid var(--line,#e6e6e2);background:#fff;border-radius:14px;overflow:hidden;display:flex;flex-direction:column}'
        . '.et-shot{display:block;aspect-ratio:16/9;background:#f2f1ed;overflow:hidden}'
        . '.et-shot img{width:100%;height:100%;object-fit:cover;object-position:top center;display:block}'
        . '.et-body{padding:13px 15px 15px}'
        . '.et-body h3{font-size:17px;margin:0 0 3px}.et-id{font-size:12px;color:#9a9a90;margin-left:6px;font-family:ui-monospace,Consolas,monospace}'
        . '.et-ind{font-size:12.5px;color:#6f767e;line-height:1.7;margin:6px 0}'
        . '.et-mood{font-size:13px;line-height:1.8;color:#3d4348;margin:6px 0 10px}'
        . '.et-tag{font-size:12px;color:#8a7a68;margin:0 0 10px}'
        . '.et-pal{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#8a8a80;font-family:ui-monospace,Consolas,monospace}'
        . '.et-pal i{width:16px;height:16px;border-radius:4px;border:1px solid rgba(0,0,0,.12);display:inline-block}'
        . '.et-pal span{margin-left:4px}'
        . '.et-mini{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}'
        . '.et-mini a{border:1px solid var(--line,#e6e6e2);background:#fff;border-radius:12px;text-decoration:none;color:inherit;overflow:hidden;display:flex;flex-direction:column}'
        . '.et-mini img{width:100%;aspect-ratio:4/3;object-fit:cover;object-position:top center;display:block;background:#f2f1ed}'
        . '.et-mini b{font-size:13.5px;padding:9px 11px 2px;line-height:1.5}'
        . '.et-mini small{font-size:11.5px;color:#9a9a90;padding:0 11px 10px}'
        . '.et-note{margin:22px 0 0;border-left:3px solid #b3541e;padding:2px 0 2px 14px;font-size:13.5px;line-height:2;color:#5f666b}'
        . '.et-note a{color:#AC4429}'
        . '.et-links{display:flex;gap:10px;flex-wrap:wrap;margin:20px 0 6px}'
        . 'html.dark .et-sec{background:#1f2328;border-color:#333}html.dark .et-card,html.dark .et-mini a,html.dark .et-kpi a,html.dark .et-kpi span{background:#1f2328;border-color:#333}'
        . 'html.dark .et-mood{color:#d8d8d2}html.dark .et-shot,html.dark .et-mini img{background:#2a2e33}'
        . '@media(max-width:640px){.et-sec{padding:14px 12px 12px}.et-head h1{font-size:26px}.et-grid{grid-template-columns:1fr}}'
        . '</style>';

    $cards = '';
    foreach ($styles as $id => $s) {
        $img = '/plugins/site/assets/themes/' . $id . '.jpg';
        $name = yc_sc_en() ? $s[1] : $s[0];
        $ind = yc_sc_en() ? $s[3] : $s[2];
        $mood = yc_sc_en() ? $s[5] : $s[4];
        $cards .= '<article class="et-card">'
            . '<a class="et-shot" href="' . e($img) . '" target="_blank" rel="noopener" title="' . e($name . ' · ' . yc_sc_t('查看大图', 'open full screenshot')) . '">'
            . '<img src="' . e($img) . '" alt="' . e($name . ' ' . yc_sc_t('企业官网风格包界面示例', 'enterprise style pack preview')) . '"></a>'
            . '<div class="et-body">'
            . '<h3>' . e($name) . '<span class="et-id">' . e($id) . '</span></h3>'
            . '<div class="et-ind">' . yc_sc_t('适用行业', 'Industries') . '：' . e($ind) . '</div>'
            . '<p class="et-mood">' . e($mood) . '</p>'
            . '<p class="et-tag">' . e($s[6]) . '</p>'
            . '<div class="et-pal"><i style="background:' . e($s[7]) . '"></i><i style="background:' . e($s[8]) . '"></i><span>' . e($s[7] . ' · ' . $s[8]) . '</span></div>'
            . '</div></article>';
    }

    $minis = '';
    foreach ($square as $id => $it) {
        [$nm, $ds] = yc_sc_label($it);
        $thumb = yc_sc_thumb($it);
        $minis .= '<a href="' . e($lp . '/theme/' . $id) . '">'
            . '<img src="' . e($thumb) . '" alt="' . e($nm) . '">'
            . '<b>' . e($nm) . '</b>'
            . '<small>' . e($id) . ($it['ver'] !== '' ? ' · v' . $it['ver'] : '') . ' →</small>'
            . '</a>';
    }

    $html = '<div class="et-wrap">'
        . '<div class="et-crumb"><a href="' . e($lp . '/') . '">' . yc_sc_t('首页', 'Home') . '</a> / '
        . '<a href="' . e($lp . '/market') . '">' . yc_sc_t('应用市场', 'App market') . '</a> / '
        . yc_sc_t('企业主题', 'Enterprise Themes') . '</div>'
        . '<div class="et-head"><h1>' . yc_sc_t('企业主题', 'Enterprise Themes') . '</h1>'
        . '<p class="et-lead">' . yc_sc_t(
            '面向企业官网的两类主题，与站内常规主题目录并行：一类是企业官网插件内置的行业风格包，一类是主题广场演示站合入的整套主题。前者换肤不改结构，后者是完整可安装的独立主题。',
            'Two families of themes for business sites, alongside the regular theme directory: industry style packs built into the enterprise-site plugin, and full standalone themes merged from the theme-square demo site.'
        ) . '</p>'
        . '<div class="et-kpi">'
        . '<span><b>' . count($styles) . '</b>' . yc_sc_t('款企业官网风格包', 'enterprise style packs') . '</span>'
        . '<a href="' . e($lp . '/theme') . '"><b>' . count($all) . '</b>' . yc_sc_t('套常规主题', 'regular themes') . '</a>'
        . '<span><b>' . count($square) . '</b>' . yc_sc_t('套演示站主题', 'theme-square demos') . '</span>'
        . '</div></div>'

        . '<section class="et-sec"><h2>' . yc_sc_t('企业官网 · 行业风格包', 'Enterprise site · industry style packs')
        . '<em>' . count($styles) . ' ' . yc_sc_t('款', 'packs') . '</em></h2>'
        . '<p class="et-desc">' . yc_sc_t(
            '来自本机 8090 企业官网演示站的「主题风格展示厅」。一套公共骨架 + 12 份配色/词表令牌：换肤时板块名称、按钮文案与演示图会随行业同步切换，栏目结构不变。装好企业官网插件后在后台「企业官网 → 风格包」一键保存即生效；演示站另支持在页面地址后追加 ?theme=<id> 无损预览。本栏目只展示界面截图，不提供在线换肤。',
            'From the local enterprise-site demo gallery (port 8090). One shared layout plus 12 token sets: section names, button copy and demo imagery follow the chosen industry. With the enterprise-site plugin installed, pick a pack in the admin panel; the demo site also supports a non-persistent ?theme=<id> preview. This gallery shows screenshots only — no live switching here.'
        ) . '</p>'
        . '<div class="et-grid">' . $cards . '</div></section>'

        . '<section class="et-sec"><h2>' . yc_sc_t('主题广场 · 演示站主题', 'Theme square · demo themes')
        . '<em>' . count($square) . ' ' . yc_sc_t('套', 'themes') . '</em></h2>'
        . '<p class="et-desc">' . yc_sc_t(
            '来自本机 yecha-astro「主题广场」演示站（每套 4 张界面截图）。这 30 套已合入官网主题目录，点卡片进入对应详情：看界面示例、版本、许可与 tar.gz 安装包。它们与上方企业风格包是两套不同机制，互不替代。',
            'From the local yecha-astro theme-square demo site (four screenshots per theme). These 30 themes are already part of the site theme directory; open a card for screenshots, version, license and a tar.gz package. They are a different mechanism from the style packs above.'
        ) . '</p>'
        . '<div class="et-mini">' . $minis . '</div></section>'

        . '<div class="et-note">'
        . yc_sc_t('怎么选', 'How to choose') . '：<br>'
        . '· ' . yc_sc_t('要企业官网（产品 / 案例 / 招聘 / 新闻 / 留言）：装 site 插件，再选一款行业风格包；AI 客服、语义搜索与多语言为可选能力。', 'For a business site (products / cases / jobs / news / contact): install the site plugin and pick an industry style pack; AI chat, semantic search and multilingual are optional.') . '<br>'
        . '· ' . yc_sc_t('要博客、文档或知识库外观：走', 'For a blog, docs or knowledge-base look: browse') . ' <a href="' . e($lp . '/theme') . '">' . yc_sc_t('主题目录', 'the theme directory') . '</a> ' . yc_sc_t('，安装即用。', '— install and go.') . '<br>'
        . '· ' . yc_sc_t('下载完整包与单包：', 'Full and single packages:') . ' <a href="' . e($lp . '/downloads') . '">' . yc_sc_t('下载中心', 'Download center') . '</a>'
        . '</div>'
        . '<div class="et-links">'
        . '<a class="sc-btn ghost" href="' . e($lp . '/theme') . '">' . yc_sc_t('常规主题', 'Regular themes') . ' →</a>'
        . '<a class="sc-btn ghost" href="' . e($lp . '/plugin') . '">' . yc_sc_t('插件目录', 'Plugins') . ' →</a>'
        . '<a class="sc-btn ghost" href="' . e($lp . '/market') . '">' . yc_sc_t('应用市场', 'App market') . ' →</a>'
        . '</div></div>';

    return [
        'html' => yc_sc_css() . $css . $html,
        'title' => yc_sc_t('企业主题', 'Enterprise Themes') . ' · ' . site_label(),
    ];
}
