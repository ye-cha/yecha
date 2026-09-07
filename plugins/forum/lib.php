<?php
declare(strict_types=1);
/* 论坛插件 · 公共辅助（yf_ 前缀防冲突） */

function yf_en(): bool { return Lang::short() === 'en'; }
function yf_t(string $zh, string $en): string { return yf_en() ? $en : $zh; }
function yf_dt(?string $s): string { return $s ? date('Y-m-d H:i', strtotime($s)) : ''; }

/** 构造 /forum 链接（参数排序稳定） */
function yf_url(array $q = []): string
{
    $parts = [];
    foreach (['mode', 'view', 'b', 't', 'p', 'q'] as $k) {
        if (isset($q[$k]) && (string)$q[$k] !== '') { $parts[$k] = $q[$k]; }
    }
    return '/forum' . ($parts ? '?' . http_build_query($parts) : '');
}

function yf_board(int|string $b): ?array
{
    $T = Db::t('forum_boards');
    if (is_numeric($b)) {
        return Db::row("SELECT * FROM {$T} WHERE id=? AND status='show' LIMIT 1", [(int)$b]) ?: null;
    }
    return Db::row("SELECT * FROM {$T} WHERE slug=? AND status='show' LIMIT 1", [(string)$b]) ?: null;
}

function yf_user(int $id): array
{
    static $m = [];
    if (isset($m[$id])) { return $m[$id]; }
    $u = Db::row('SELECT id, username, display_name, role FROM ' . Db::t('users') . ' WHERE id=? LIMIT 1', [$id]);
    $m[$id] = $u ?: ['id' => $id, 'username' => '?', 'display_name' => '', 'role' => ''];
    return $m[$id];
}

function yf_uname(array $u): string
{
    return trim((string)($u['display_name'] ?? '')) !== '' ? (string)$u['display_name'] : (string)($u['username'] ?? 'anonymous');
}

function yf_md(string $c): string
{
    return \Markdown::render($c);
}

function yf_pager(int $cur, int $pages, array $base = [], string $key = 'p'): string
{
    if ($pages <= 1) { return ''; }
    $cur = max(1, min($cur, $pages));
    $h = '<nav class="yf-pg" aria-label="pager">';
    if ($cur > 1) { $h .= '<a href="' . e(yf_url($base + [$key => $cur - 1])) . '" rel="prev">‹</a>'; }
    for ($i = 1; $i <= $pages; $i++) {
        if ($pages > 9 && abs($i - $cur) > 2 && $i !== 1 && $i !== $pages) { continue; }
        $h .= '<a class="' . ($i === $cur ? 'on' : '') . '" href="' . e(yf_url($base + [$key => $i])) . '">' . $i . '</a>';
    }
    if ($cur < $pages) { $h .= '<a href="' . e(yf_url($base + [$key => $cur + 1])) . '" rel="next">›</a>'; }
    return $h . '</nav>';
}

function yf_css(): string
{
    return '<style>'
        . '.yf-wrap{max-width:880px}.yf-head h1{font-family:var(--font-serif,"Noto Serif SC",serif);font-size:26px;margin:4px 0 4px}'
        . '.yf-sub{color:#6f767e;font-size:13.5px;line-height:1.8;margin:0 0 14px}'
        . '.yf-crumbs{font-size:12.5px;color:#9a9a90;margin-bottom:4px}.yf-crumbs a{color:inherit;text-decoration:none}.yf-crumbs a:hover{color:var(--accent,#B3541E)}'
        . '.yf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}'
        . '.yf-card{border:1px solid var(--line,#e8e4da);background:#fff;border-radius:14px;padding:15px 17px;display:block;color:inherit;text-decoration:none;transition:box-shadow .15s,transform .15s;box-sizing:border-box}'
        . '.yf-card:hover{box-shadow:0 8px 22px rgba(0,0,0,.08);transform:translateY(-2px)}'
        . '.yf-card b{display:block;font-size:16px;color:#1f2328;font-family:var(--font-serif,"Noto Serif SC",serif)}'
        . '.yf-card p{margin:6px 0 8px;color:#6f767e;font-size:12.8px;line-height:1.75;min-height:2.6em}'
        . '.yf-card .meta{display:flex;gap:10px;color:#9a9a90;font-size:12px;flex-wrap:wrap}'
        . '.yf-tlist{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:2px}'
        . '.yf-thread{display:flex;gap:14px;align-items:baseline;padding:13px 6px;border-bottom:1px solid var(--hairline,#eee7db)}'
        . '.yf-thread .tt{flex:1;min-width:0}.yf-thread .tt a{color:#23303a;text-decoration:none;font-size:15px;line-height:1.6}.yf-thread .tt a:hover{color:var(--accent,#B3541E)}'
        . '.yf-thread .tt .tm{display:flex;gap:8px;flex-wrap:wrap;color:#9a9a90;font-size:12px;margin-top:3px}'
        . '.yf-thread .cnt{color:#6f767e;font-size:12.5px;white-space:nowrap;text-align:right}'
        . '.yf-btn,.yf-btn2{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:7px 16px;font-size:13.5px;text-decoration:none;font-weight:600}'
        . '.yf-btn{background:#B3541E;color:#fff}.yf-btn:hover{opacity:.92}'
        . '.yf-btn2{border:1px solid #d9d2c6;color:#5a5348;background:#fff}.yf-btn2:hover{border-color:#B3541E;color:#B3541E}'
        . '.yf-row{display:flex;align-items:center;gap:12px;margin:6px 0 16px;flex-wrap:wrap}'
        . '.yf-form{border:1px solid var(--line,#e8e4da);background:#fff;border-radius:14px;padding:16px 18px;margin-top:6px}'
        . '.yf-form label{display:block;font-size:12.5px;color:#6f767e;margin:10px 0 4px}'
        . '.yf-form input[type=text],.yf-form textarea,.yf-form select{width:100%;box-sizing:border-box;border:1px solid #e2ddd2;border-radius:9px;padding:9px 12px;font:inherit;background:#fff;color:#23303a}'
        . '.yf-form textarea{min-height:180px;line-height:1.8}'
        . '.yf-form .btn{margin-top:14px;border:0;cursor:pointer}'
        . '.yf-form .hint{color:#9a9a90;font-size:12px;margin-top:6px}'
        . '.yf-err{background:#fdf0ec;border:1px solid #efc8ba;color:#c0392b;border-radius:10px;padding:9px 14px;font-size:13.5px;margin-bottom:12px}'
        . '.yf-msg{background:#eef4ee;border:1px solid #cfe2d0;color:#2e6b34;border-radius:10px;padding:9px 14px;font-size:13.5px;margin-bottom:12px}'
        . '.yf-pg{display:flex;gap:5px;flex-wrap:wrap;margin:16px 0 4px}.yf-pg a{border:1px solid #ddd6ca;color:#5a5348;text-decoration:none;border-radius:8px;min-width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;padding:0 4px}.yf-pg a.on{background:#B3541E;border-color:#B3541E;color:#fff}.yf-pg a:hover{border-color:#B3541E}'
        . '.yf-empty{color:#9a9a90;padding:26px 4px;text-align:center;font-size:13.5px}'
        . '.yf-post{display:flex;gap:14px;padding:16px 2px;border-bottom:1px solid var(--hairline,#eee7db)}'
        . '.yf-post .who{flex:none;width:120px;font-size:12.5px}.yf-post .who b{display:block;color:#23303a;font-weight:600;margin-bottom:2px}.yf-post .who time{color:#9a9a90}'
        . '.yf-post .body{flex:1;min-width:0;color:#2c3438;font-size:14.5px;line-height:1.95;overflow-wrap:anywhere}'
        . '.yf-post .body img{max-width:100%;height:auto;border-radius:8px;margin:6px 0}'
        . '.yf-post .body pre{background:#f6f3ec;border:1px solid #e9e2d4;border-radius:8px;padding:10px 12px;overflow:auto;font-size:13px}'
        . '.yf-post .body code{background:#f3efe6;border-radius:4px;padding:1px 5px;font-size:13px}.yf-post .body pre code{background:none;padding:0}'
        . '.yf-post .body blockquote{border-left:3px solid #d8c9b2;margin:8px 0;padding:2px 12px;color:#6f767e}'
        . 'html.dark .yf-card,html.dark .yf-form{background:#1c221c;border-color:#2c3a30}'
        . 'html.dark .yf-card b,html.dark .yf-post .who b{color:#e7ebdd}'
        . 'html.dark .yf-thread .tt a{color:#dbe2d4}'
        . 'html.dark .yf-post .body{color:#d5ddd2}'
        . 'html.dark .yf-form input[type=text],html.dark .yf-form textarea,html.dark .yf-form select{background:#141a15;border-color:#33422f;color:#e7ebdd}'
        . 'html.dark .yf-post .body pre,html.dark .yf-post .body code{background:#141a15;border-color:#2c3a30}'
        . 'html.dark .yf-btn2{background:transparent;color:#c9d5c9;border-color:#46564a}'
        . 'html.dark .yf-pg a{color:#c9d5c9;border-color:#46564a}'
        . 'html.dark .yf-thread{border-color:#24312a}html.dark .yf-post{border-color:#24312a}'
        . '@media(max-width:640px){.yf-post{flex-direction:column}.yf-post .who{width:auto}}'
        . '.yf-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:2px 0 12px}'
        . '.yf-filters{display:flex;gap:6px;flex-wrap:wrap}'
        . '.yf-chip{display:inline-flex;align-items:center;border:1px solid var(--line,#e0d9cc);background:#fff;color:#5a5348;border-radius:999px;padding:5px 13px;font-size:13px;text-decoration:none;font-weight:500}'
        . '.yf-chip:hover{border-color:#B3541E;color:#B3541E}'
        . '.yf-chip.on{background:#B3541E;border-color:#B3541E;color:#fff;font-weight:600}'
        . 'html.dark .yf-chip{background:#1c221c;border-color:#46564a;color:#c9d5c9}html.dark .yf-chip.on{background:#B3541E;color:#fff;border-color:#B3541E}'
        . '.yf-tabs{display:flex;gap:2px;margin:2px 0 6px;border-bottom:1px solid var(--hairline,#eee7db)}'
        . '.yf-tabs a{padding:7px 12px;font-size:13.5px;color:#6f767e;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px}'
        . '.yf-tabs a.on{color:#B3541E;border-bottom-color:#B3541E;font-weight:600}'
        . '.yf-tabs a:hover{color:#B3541E}'
        . '.yf-tlist{border-top:1px solid var(--hairline,#eee7db)}'
        . '.yf-thread{display:flex;gap:12px;align-items:center;padding:12px 4px;border-bottom:1px solid var(--hairline,#eee7db)}'
        . '.yf-thread .ava{flex:none;width:38px;height:38px;border-radius:50%;background:linear-gradient(140deg,#EAF0E0,#C9D8A4);color:#1F3F30;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;font-family:var(--font-serif,serif)}'
        . 'html.dark .yf-thread .ava{background:#2c4a3a;color:#e7ebdd}'
        . '.yf-thread .tc{flex:1;min-width:0}'
        . '.yf-thread .tc .tt{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}'
        . '.yf-thread .tc .tt a{color:#23303a;text-decoration:none;font-size:15px;line-height:1.55;font-weight:600}'
        . '.yf-thread .tc .tt a:hover{color:#B3541E}'
        . '.yf-thread .tc .tm{display:flex;align-items:center;gap:8px;color:#9a9a90;font-size:12.5px;margin-top:4px;flex-wrap:wrap}'
        . '.yf-thread .tc .tm .bchip{background:#f3efe6;color:#7a6a4f;border-radius:999px;padding:1px 9px;font-size:11.5px}'
        . 'html.dark .yf-thread .tc .tm .bchip{background:#2a3a2e;color:#c9d5c9}'
        . '.yf-thread .cnt{flex:none;display:flex;gap:16px;color:#9a9a90;font-size:11.5px;text-align:center}'
        . '.yf-thread .cnt b{display:block;font-size:16px;color:#23303a;font-variant-numeric:tabular-nums;line-height:1.1}'
        . 'html.dark .yf-thread .cnt b{color:#e7ebdd}'
        . '.yf-thread .cnt span{min-width:34px}'
        . '.yf-sec{margin:26px 0 10px;display:flex;align-items:baseline;gap:12px}'
        . '.yf-sec h2{font-size:17px;margin:0}'
        . '.yf-sec .en2{font-size:11px;letter-spacing:.22em;text-transform:uppercase;color:#B3541E}'
        . '.yf-sec::after{content:"";flex:1;height:1px;background:var(--hairline,#eee7db)}'
        . '.yf-floor{color:#b3aa99;font-size:11.5px;margin-top:6px}'
        . '.yf-badge{display:inline-block;background:#B3541E;color:#fff;border-radius:999px;padding:0 8px;font-size:11px;margin-left:6px;vertical-align:1px}'
        . '.yf-post .who b{display:flex;align-items:center;gap:4px;flex-wrap:wrap}'
        . '@media(max-width:560px){.yf-thread .cnt{gap:8px}.yf-thread .ava{width:32px;height:32px;font-size:14px}}'
        /* ---- 左右结构 + 作者行置顶（v3） ---- */
        . '.yf-layout{display:grid;grid-template-columns:minmax(0,1fr) 264px;gap:clamp(14px,2vw,26px);align-items:start}'
        . '.yf-main{min-width:0}'
        . '.yf-side{display:flex;flex-direction:column;gap:14px;position:sticky;top:calc(var(--nav-h,64px) + 14px)}'
        . '.yf-w{border:1px solid var(--line,#e8e4da);background:#fff;border-radius:14px;padding:13px 15px}'
        . '.yf-w h4{margin:0 0 8px;font-size:13.5px;color:#5a5348;border-left:3px solid #B3541E;padding-left:8px}'
        . '.yf-w ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:2px}'
        . '.yf-w li a{display:flex;align-items:baseline;gap:6px;text-decoration:none;color:#4a4a40;font-size:13.5px;padding:6px 6px;border-radius:8px}'
        . '.yf-w li a:hover{background:#f6f2e9;color:#B3541E}'
        . '.yf-w li a .n{margin-left:auto;color:#b3aa99;font-size:12px}'
        . '.yf-w li a.on{background:#B3541E;color:#fff;font-weight:600}.yf-w li a.on .n{color:rgba(255,255,255,.75)}'
        . '.yf-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;text-align:center}'
        . '.yf-stats b{display:block;font-size:18px;color:#23303a;line-height:1.2}.yf-stats span{font-size:11.5px;color:#9a9a90}'
        . '.yf-sidebox p{margin:6px 0;color:#6f767e;font-size:12.8px;line-height:1.8}'
        . 'html.dark .yf-w{background:#1c221c;border-color:#2c3a30}'
        . 'html.dark .yf-w h4{color:#c9d5c9}html.dark .yf-w li a{color:#c9d5c9}html.dark .yf-w li a:hover{background:#24312a;color:#e7ebdd}'
        . 'html.dark .yf-stats b{color:#e7ebdd}'
        . '.yf-post{display:block;padding:14px 2px;border-bottom:1px solid var(--hairline,#eee7db)}'
        . '.yf-ptop{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;font-size:13px;color:#9a9a90;margin-bottom:8px}'
        . '.yf-ptop b{color:#23303a;font-weight:700;font-size:13.5px}'
        . '.yf-ptop .yf-badge{margin-left:0}'
        . '.yf-ptop .yf-floor{margin-left:auto}'
        . '.yf-post .body{color:#2c3438;font-size:14.5px;line-height:1.95;overflow-wrap:anywhere}'
        . 'html.dark .yf-ptop b{color:#e7ebdd}html.dark .yf-post .body{color:#d5ddd2}'
        . '@media(max-width:980px){.yf-layout{grid-template-columns:1fr}.yf-side{position:static;order:2}}'
        /* ---- v4 经典论坛范式 ---- */
        . '.yf-blist{display:flex;flex-direction:column;gap:0;border:1px solid var(--line,#e8e4da);border-radius:14px;overflow:hidden;background:#fff}'
        . '.yf-brow{display:grid;grid-template-columns:minmax(0,1fr) 92px 92px minmax(150px,auto);gap:8px 20px;align-items:center;padding:14px 16px;border-bottom:1px solid var(--hairline,#eee7db);text-decoration:none;color:inherit;transition:background .12s}'
        . '.yf-brow:last-child{border-bottom:0}'
        . '.yf-brow:hover{background:#faf7f0}'
        . '.yf-brow .bb b{font-size:16px;color:#23303a;display:flex;align-items:baseline;gap:8px}'
        . '.yf-brow .bb p{margin:5px 0 0;color:#8a8272;font-size:12.5px;line-height:1.7}'
        . '.yf-brow .bc{text-align:center;font-size:11.5px;color:#9a9a90}'
        . '.yf-brow .bc b{display:block;font-size:15px;color:#23303a;line-height:1.2}'
        . '.yf-brow .bl{min-width:150px;text-align:right;font-size:12px;color:#9a9a90;line-height:1.6}'
        . '.yf-brow .bl b{color:#6f6a5e;font-weight:600}'
        . 'html.dark .yf-blist{background:#1c221c;border-color:#2c3a30}'
        . 'html.dark .yf-brow{border-color:#24312a}html.dark .yf-brow:hover{background:#202a21}'
        . 'html.dark .yf-brow .bb b,html.dark .yf-brow .bc b{color:#e7ebdd}'
        . '.yf-pin{display:inline-block;background:#B3541E;color:#fff;border-radius:4px;font-size:11px;padding:0 5px;line-height:1.6;vertical-align:1px}'
        . '.yf-tagm{font-size:12px;color:#6f767e}'
        . '.yf-floor-card{border:1px solid var(--line,#e8e4da);border-radius:14px;background:#fff;margin:0 0 12px;overflow:hidden}'
        . '.yf-floor-card.op{border-color:#d9c9ae}'
        . '.yf-fhead{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:8px 14px;background:#f6f2e9;border-bottom:1px solid var(--hairline,#eee7db);font-size:12.5px;color:#9a9a90}'
        . '.yf-fhead .fn{color:#b3541e;font-weight:700}'
        . '.yf-fhead .fx{margin-left:auto;display:flex;gap:8px;align-items:baseline;flex-wrap:wrap}'
        . '.yf-fhead .fx b{color:#23303a;font-size:13px}'
        . '.yf-floor-card .yf-body{padding:14px 16px 16px;color:#2c3438;font-size:14.5px;line-height:1.95;overflow-wrap:anywhere}'
        . '.yf-floor-card.op .yf-body{border-top:0}'
        . 'html.dark .yf-floor-card{background:#1c221c;border-color:#2c3a30}'
        . 'html.dark .yf-fhead{background:#223024;border-color:#2c3a30;color:#93a89c}'
        . 'html.dark .yf-fhead .fx b{color:#e7ebdd}'
        . 'html.dark .yf-floor-card .yf-body{color:#d5ddd2}'
        . '.yf-ttl{font-size:20px;line-height:1.45;margin:8px 0 12px;font-weight:700;color:#23303a}'
        . 'html.dark .yf-ttl{color:#e7ebdd}'
        . '.yf-last{display:inline-flex;align-items:center;gap:6px}'
        . '@media(max-width:760px){.yf-brow{grid-template-columns:1fr auto;}.yf-brow .bl{display:none}}'
        . '</style>';
}
