<?php
declare(strict_types=1);

/**
 * Changelog：/changelog 内容页专用「版本时间线」结构化渲染（SSR 直出，零 JS）
 *
 * 对齐的展示惯例（2026-11-07 整理）：
 *   · Keep a Changelog —— 每版独立入口 / 同类改动分组 / 版本与章节可链接 /
 *                         新版本在前 / 每版标注 ISO 发布日期 / 语义化版本
 *   · GitHub Releases、WordPress 生态 —— 版本号等宽徽章 + 日期 <time> +
 *                         分类彩色标签 + Release/Tag 链接 + 最新版 Latest 标记
 *   · shadcn / Bruno 文档站 —— 时间线竖线与圆点、侧栏/顶部版本索引、移动端单列
 *   · 本站 /docs —— 开篇导语 + 「本页导航」锚点目录 + 表格 + 带语言标记的围栏代码块
 *
 * 源 Markdown 格式保持不变（首页 landing-cms.php 依 `## YYYY-MM-DD · ...` 解析）：
 *   ## 2026-11-06 · YeCha 1.2.4 · 标题
 *   > 一句话摘要
 *   ### 修复
 *   - 要点
 *   ```bash
 *   命令
 *   ```
 *
 * 样式由主题 themes/yecha/style.css 的 .cl-* 规则承担（含 html.dark / 移动端）。
 * 即使未加载样式，输出仍是语义化 HTML，内容完整可读。
 */
final class Changelog
{
    private const GH_REPO = 'https://github.com/ye-cha/yecha';
    /** 确实存在 tag / release 的版本，避免生成死链 */
    private const GH_TAGS = ['1.2.4', '1.2.3', '1.2.2', '1.2.1', '1.2.0', '1.1.0'];
    private const GH_RELEASES = ['1.2.4', '1.2.3'];

    /** 分类：slug => [符号, 中文, 英文] —— 符号 + 文字双通道，不靠颜色单独表意 */
    private const GROUPS = [
        'add'      => ['+', '新增', 'Added'],
        'change'   => ['↑', '改进', 'Changed'],
        'fix'      => ['✓', '修复', 'Fixed'],
        'security' => ['!', '安全', 'Security'],
        'verify'   => ['✓', '验收', 'Verified'],
        'cause'    => ['◐', '根因', 'Root cause'],
        'docs'     => ['§', '文档', 'Docs'],
        'remove'   => ['−', '移除', 'Removed'],
        'note'     => ['·', '说明', 'Notes'],
    ];

    public static function render(string $src, string $lang = 'zh'): string
    {
        $en = preg_match('/^en\b/i', $lang) === 1;
        $rels = self::parse($src);
        if ($rels === []) { return Markdown::render($src); } // 格式不符时安全回退通用渲染

        $out = '<div class="cl-wrap">';

        // 开篇导语：与 /docs 同一口径
        $out .= '<div class="cl-intro"><p>'
            . ($en
                ? 'All notable changes to YeCha are documented here, <strong>newest first</strong>. Every release carries a version badge, an ISO release date and a shareable anchor; changes are grouped by type. Format follows <a href="https://keepachangelog.com/en/1.1.0/" rel="noopener" target="_blank">Keep a Changelog</a> and <a href="https://semver.org/" rel="noopener" target="_blank">Semantic Versioning</a>.'
                : '本页记录 YeCha 各版本的重要改动，<strong>新版本在前</strong>。每个版本有独立版本号、ISO 发布日期与可分享的锚点链接，同类改动分组展示；格式遵循 <a href="https://keepachangelog.com/zh-CN/1.1.0/" rel="noopener" target="_blank">Keep a Changelog</a> 与<a href="https://semver.org/lang/zh-CN/" rel="noopener" target="_blank">语义化版本</a>规范。')
            . '</p><p class="cl-intro-meta">'
            . ($en ? 'Current release' : '当前版本') . ' <b>v'
            . e(defined('YC_VERSION') ? YC_VERSION : '') . '</b>'
            . ' · ' . ($en ? 'Releases' : '已收录版本') . ' <b>' . count($rels) . '</b>'
            . ' · ' . ($en ? 'Latest' : '最近发布') . ' <b>' . e($rels[0]['date']) . '</b>'
            . '</p></div>';

        // 版本导航：对齐 /docs 的「本页导航 {#toc}」
        $out .= '<nav class="cl-nav" aria-label="' . ($en ? 'Release index' : '版本导航') . '">'
            . '<span class="cl-nav-cap">' . ($en ? 'Versions' : '版本导航') . '</span>';
        foreach ($rels as $r) {
            $out .= '<a href="#' . e($r['id']) . '">'
                . '<b>' . e($r['ver'] !== '' ? 'v' . ltrim($r['ver'], 'v') : $r['date']) . '</b>'
                . '<time datetime="' . e($r['date']) . '">' . e($r['date']) . '</time>'
                . '</a>';
        }
        $out .= '</nav>';

        $out .= '<div class="cl-timeline">';
        foreach ($rels as $i => $r) {
            $out .= self::renderRelease($r, $i === 0, $en);
        }
        $out .= '</div></div>';
        return $out;
    }

    /** 把 Markdown 源按「版本 → 分类组」解析成结构 */
    private static function parse(string $src): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $src) ?: [];
        $rels = [];
        $cur = null;
        $grp = null;
        $buf = [];
        $inFence = false;

        $flush = function () use (&$cur, &$grp, &$buf): void {
            if ($cur === null) { return; }
            if ($grp !== null) {
                $grp['md'] = trim(implode("\n", $buf), "\n");
                $cur['groups'][] = $grp;
            }
            $buf = [];
            $grp = null;
        };

        foreach ($lines as $ln) {
            $t = trim($ln);

            // 围栏代码块：开合行与块内空行都要原样交给 Markdown 渲染器
            if (str_starts_with($t, '```')) {
                $inFence = !$inFence;
                if ($cur !== null) {
                    if ($grp === null) { $grp = ['title' => '', 'md' => '']; }
                    $buf[] = $ln;
                }
                continue;
            }
            if ($t === '' && $inFence) {
                if ($cur !== null) { $buf[] = $ln; }
                continue;
            }
            // 其余空行只是 Markdown 排版分隔：不能让它们提前创建空分组
            if ($t === '') { continue; }

            // 新版本：`## YYYY-MM-DD · YeCha <版本> · <标题>`
            if (preg_match('/^##\s+(\d{4}-\d{2}-\d{2})\s*[·・]\s*(.+)$/u', $t, $m)) {
                if ($cur !== null) { $flush(); $rels[] = $cur; }
                $inFence = false;
                $rest = trim($m[2]);
                $ver = '';
                $title = $rest;
                if (preg_match('/^YeCha\s+(.+?)\s*[·・]\s*(.+)$/u', $rest, $mm)) {
                    $ver = trim($mm[1]);
                    $title = trim($mm[2]);
                }
                $cur = [
                    'date'   => $m[1],
                    'ver'    => $ver,
                    'title'  => $title,
                    'id'     => self::anchor($ver, $m[1]),
                    'sum'    => '',
                    'groups' => [],
                ];
                continue;
            }
            if ($cur === null) { continue; } // 导语段落由渲染器统一生成

            // 分类组
            if (preg_match('/^###\s+(.+)$/u', $t, $m)) {
                $flush();
                $grp = ['title' => trim($m[1]), 'md' => ''];
                continue;
            }
            // 摘要（首个分类组之前）
            if ($grp === null && $cur['sum'] === '' && $t !== '' && $t[0] === '>') {
                $cur['sum'] = trim(substr($t, 1));
                continue;
            }
            // 旧式水平分隔线不再需要：时间线自带分隔
            if ($t === '---' || $t === '***' || $t === '___') { continue; }
            if ($grp === null) { $grp = ['title' => '', 'md' => '']; }
            $buf[] = $ln;
        }
        if ($cur !== null) { $flush(); $rels[] = $cur; }
        return $rels;
    }

    private static function anchor(string $ver, string $date): string
    {
        $v = strtolower(trim($ver));
        $v = trim((string)preg_replace('/[^a-z0-9]+/', '-', $v), '-');
        return $v !== '' ? 'v' . $v : 'rel-' . $date;
    }

    private static function renderRelease(array $r, bool $isLatest, bool $en): string
    {
        $ver = $r['ver'];
        $h = '<section class="cl-rel' . ($isLatest ? ' is-latest' : '') . '">';
        $h .= '<header class="cl-head"><div class="cl-badges">';
        if ($ver !== '') {
            $h .= '<span class="cl-ver">' . e('v' . ltrim($ver, 'v')) . '</span>';
        }
        $h .= '<time class="cl-date" datetime="' . e($r['date']) . '">' . e($r['date']) . '</time>';
        if ($isLatest) {
            $h .= '<span class="cl-latest">' . ($en ? 'Latest' : '最新版') . '</span>';
        }
        if (in_array($ver, self::GH_TAGS, true)) {
            $isRel = in_array($ver, self::GH_RELEASES, true);
            $url = self::GH_REPO . ($isRel ? '/releases/tag/v' : '/tree/v') . rawurlencode($ver);
            $h .= '<a class="cl-gh" href="' . e($url) . '" rel="noopener" target="_blank">'
                . ($isRel ? 'Release' : 'Tag') . ' ↗</a>';
        }
        $h .= '</div><h2 class="cl-title" id="' . e($r['id']) . '">' . e($r['title']) . '</h2></header>';

        if ($r['sum'] !== '') {
            $h .= '<div class="cl-sum">' . self::inlineMd($r['sum']) . '</div>';
        }
        foreach ($r['groups'] as $g) {
            if (trim($g['md']) === '') { continue; }
            $gi = self::groupInfo($g['title'], $en);
            $h .= '<div class="cl-grp cl-grp-' . $gi[0] . '">';
            $h .= '<div class="cl-grp-h"><span class="cl-chip"><i aria-hidden="true">' . e($gi[1]) . '</i>'
                . e($gi[2]) . '</span>';
            if ($gi[3] !== '') {
                $h .= '<span class="cl-grp-extra">' . e($gi[3]) . '</span>';
            }
            $h .= '</div><div class="cl-grp-body">' . Markdown::render($g['md']) . '</div></div>';
        }
        return $h . '</section>';
    }

    /** 返回 [slug, 符号, 标签, 补充说明] */
    private static function groupInfo(string $title, bool $en): array
    {
        $t = trim($title);
        $rules = [
            'security' => ['安全', 'Security', '漏洞', '源码外泄'],
            'fix'      => ['修复', 'Fixed', 'Bug', '纠正'],
            'add'      => ['新增', 'Added', '新功能', 'Feature', '上线', '接入'],
            'change'   => ['改进', '优化', '性能', 'Changed', 'Improved', '清理', '重构'],
            'verify'   => ['验收', '验证', '自检', '冒烟', '实测', '测试', 'Verified', 'Verification', 'Tested'],
            'cause'    => ['根因', '原因', '问题', 'Root cause', 'Problem'],
            'docs'     => ['文档', '站点', '部署', '下载', '生产口径', '内核接线', '同步', '配置', 'Docs', 'Documentation', 'Deployment', 'Downloads'],
            'remove'   => ['移除', 'Removed', 'Deprecated', '弃用'],
        ];
        $slug = 'note';
        foreach ($rules as $k => $words) {
            foreach ($words as $w) {
                if (mb_stripos($t, $w, 0, 'UTF-8') !== false) { $slug = $k; break 2; }
            }
        }
        $g = self::GROUPS[$slug];
        $label = $en ? $g[2] : $g[1];

        // 标题里的括号内容 / 分类词之后的补充说明
        $extra = '';
        if (preg_match('/[（(](.+)[）)]\s*$/u', $t, $mm)) {
            $extra = trim($mm[1]);
        } else {
            $clean = trim((string)preg_replace(
                '/^(新增|改进|修复|安全|验收|根因|文档|移除|说明|Added|Changed|Fixed|Security|Verified|Root cause|Docs|Removed|Notes)\s*[:：·\-]?\s*/u',
                '', $t
            ));
            if ($clean !== '' && $clean !== $t) { $extra = $clean; }
        }
        return [$slug, $g[0], $label, $extra];
    }

    /** 摘要里允许行内 Markdown（反引号/粗体/链接），去掉外层 <p> */
    private static function inlineMd(string $s): string
    {
        $h = trim(Markdown::render($s));
        return (string)(preg_replace('/^<p>(.*)<\/p>$/s', '$1', $h) ?? $h);
    }
}
