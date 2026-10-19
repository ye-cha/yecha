<?php
declare(strict_types=1);
/* 论坛插件 · 公共辅助（yf_ 前缀防冲突）· v2（t39 按 linux.sb 布局重做） */

function yf_en(): bool { return Lang::short() === 'en'; }
function yf_t(string $zh, string $en): string { return yf_en() ? $en : $zh; }
function yf_dt(?string $s): string { return $s ? date('Y-m-d H:i', strtotime($s)) : ''; }
function yf_ago(?string $s): string
{
    if (!$s) { return ''; }
    $t = strtotime($s);
    if ($t === false) { return ''; }
    $d = time() - $t;
    if ($d < 60) { return yf_t('刚刚', 'just now'); }
    if ($d < 3600) { $n = (string)max(1, (int)floor($d / 60)); return yf_t($n . ' 分钟前', $n . 'm ago'); }
    if ($d < 86400) { $n = (string)max(1, (int)floor($d / 3600)); return yf_t($n . ' 小时前', $n . 'h ago'); }
    if ($d < 2592000) { $n = (string)max(1, (int)floor($d / 86400)); return yf_t($n . ' 天前', $n . 'd ago'); }
    return yf_dt($s);
}

/** 构造 /forum 链接（参数排序稳定） */
function yf_url(array $q = []): string
{
    $parts = [];
    foreach (['mode', 'view', 'f', 'b', 't', 'rp', 'u', 'q', 'quote', 'rid', 'p'] as $k) {
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
    $u = Db::row('SELECT id, username, display_name, role, avatar, created_at, last_seen_at FROM ' . Db::t('users') . ' WHERE id=? LIMIT 1', [$id]);
    $m[$id] = $u ?: ['id' => $id, 'username' => '?', 'display_name' => '', 'role' => '', 'avatar' => '', 'created_at' => null, 'last_seen_at' => null];
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

/** 字母头像（无上传头像时）：按名字取色 */
function yf_ava(array $u, int $size = 38): string
{
    $name = yf_uname($u);
    $av = trim((string)($u['avatar'] ?? ''));
    if ($av !== '' && preg_match('#^(https?://|/)#', $av)) {
        return '<img class="ava-img" src="' . e($av) . '" alt="' . e($name) . '" width="' . $size . '" height="' . $size . '" loading="lazy">';
    }
    $ch = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '?';
    $hue = (int)(crc32($name) % 360);
    $st = 'background:hsl(' . $hue . ',46%,88%);color:hsl(' . $hue . ',48%,26%)';
    return '<span class="ava" style="' . $st . ';width:' . $size . 'px;height:' . $size . 'px;font-size:' . (int)max(11, $size * 0.42) . 'px">' . e($ch) . '</span>';
}

function yf_bname(array $b): string
{
    return yf_en() && trim((string)$b['name_en']) !== '' ? (string)$b['name_en'] : (string)$b['name'];
}

function yf_boards_all(): array
{
    return Db::rows('SELECT * FROM ' . Db::t('forum_boards') . " WHERE status='show' ORDER BY sort ASC, id ASC");
}

function yf_badges(array $th): string
{
    $h = '';
    if ((int)($th['pinned'] ?? 0) === 1) { $h .= '<span class="yf-tag pin">' . e(yf_t('置顶', 'Pinned')) . '</span>'; }
    if ((int)($th['featured'] ?? 0) === 1) { $h .= '<span class="yf-tag good">' . e(yf_t('精华', 'Featured')) . '</span>'; }
    if ((int)($th['locked'] ?? 0) === 1) { $h .= '<span class="yf-tag lock">' . e(yf_t('已锁', 'Locked')) . '</span>'; }
    return $h;
}

function yf_can_edit(int $ownerId): bool
{
    return \Auth::check() && ((int)\Auth::id() === $ownerId || \Auth::isAdmin());
}

/** 反垃圾：屏蔽词 + 链接数（可在后台 options 里改 forum.words，逗号分隔） */
function yf_blocked(string $text): string
{
    $raw = trim((string)get_option('forum.words', '加微信,代开发票,博彩,菠菜,办证,裸聊,约炮,私彩,洗钱,信用卡套现,刷单'));
    foreach (preg_split('/[,，\s]+/u', $raw) ?: [] as $w) {
        $w = trim($w);
        if ($w !== '' && mb_stripos($text, $w) !== false) {
            return yf_t('内容包含被拦截的词（' . $w . '），请修改后再发', 'Your post contains a blocked word (' . $w . '). Please edit and retry.');
        }
    }
    if (preg_match_all('#https?://#i', $text) > 3) {
        return yf_t('外链太多（最多 3 条），请精简后再发', 'Too many links (max 3). Please trim and retry.');
    }
    return '';
}

function yf_rate_check(): string
{
    if (!\Auth::check()) { return yf_t('请先登录', 'Please log in first.'); }
    $n = (int)\Cache::incr('forum_rate', 'u' . (int)\Auth::id(), 300);
    if ($n > 6) { return yf_t('操作太快了，休息几分钟再继续', 'Posting too fast — take a short break.'); }
    return '';
}

function yf_excerpt(string $s, int $n = 120): string
{
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? '');
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
}

/** 论坛页公用的样式（v2：linux.sb 式两栏 + 统计侧栏） */
function yf_css(): string
{
    return '<style>'
        . '.yf-forum{max-width:1120px;margin:0 auto}'
        . '.yf-top{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:#fff;border:1px solid var(--line,#e8e4da);border-radius:14px;padding:10px 14px;margin:0 0 16px}'
        . '.yf-top .yf-brand{font-family:var(--font-serif,"Noto Serif SC",serif);font-size:19px;font-weight:800;color:#23303a;text-decoration:none;white-space:nowrap}'
        . '.yf-top .yf-brand em{font-style:normal;color:#B3541E}'
        . '.yf-top form{flex:1;min-width:180px;display:flex;gap:6px}'
        . '.yf-top input[type=search]{flex:1;min-width:0;border:1px solid #e2ddd2;border-radius:999px;padding:7px 14px;font:inherit;font-size:13.5px;background:#fbfaf7;color:#23303a}'
        . '.yf-top input[type=search]:focus{outline:0;border-color:#B3541E;background:#fff}'
        . '.yf-top .yf-sbtn{border:0;border-radius:999px;padding:7px 16px;background:#B3541E;color:#fff;font:inherit;font-size:13px;font-weight:600;cursor:pointer}'
        . '.yf-top .yf-u{display:flex;align-items:center;gap:8px;font-size:13px;color:#6f767e}'
        . '.yf-top .yf-u a{color:#5a5348;text-decoration:none}'
        . '.yf-top .yf-u a:hover{color:#B3541E}'
        . '.yf-layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:clamp(14px,2vw,24px);align-items:start}'
        . '.yf-main{min-width:0}'
        . '.yf-side{display:flex;flex-direction:column;gap:14px;position:sticky;top:calc(var(--nav-h,64px) + 14px)}'
        . '.yf-w{border:1px solid var(--line,#e8e4da);background:#fff;border-radius:14px;padding:12px 14px}'
        . '.yf-w h4{margin:0 0 8px;font-size:13px;letter-spacing:.04em;color:#5a5348;border-left:3px solid #B3541E;padding-left:8px}'
        . '.yf-w ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:1px}'
        . '.yf-w li a{display:flex;align-items:baseline;gap:6px;text-decoration:none;color:#4a4a40;font-size:13px;padding:6px;border-radius:8px;min-width:0}'
        . '.yf-w li a:hover{background:#f6f2e9;color:#B3541E}'
        . '.yf-w li a .n{margin-left:auto;color:#b3aa99;font-size:11.5px;flex:none}'
        . '.yf-w li a.on{background:#B3541E;color:#fff;font-weight:600}.yf-w li a.on .n{color:rgba(255,255,255,.78)}'
        . '.yf-w li a span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
        . '.yf-head h1{font-family:var(--font-serif,"Noto Serif SC",serif);font-size:28px;margin:2px 0 6px}'
        . '.yf-sub{color:#6f767e;font-size:13.5px;line-height:1.85;margin:0 0 12px}'
        . '.yf-crumbs{font-size:12.5px;color:#9a9a90;margin:0 0 6px}.yf-crumbs a{color:inherit;text-decoration:none}.yf-crumbs a:hover{color:#B3541E}'
        . '.yf-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:8px 0 12px}'
        . '.yf-tabs{display:flex;gap:2px;border-bottom:1px solid var(--hairline,#eee7db);margin:0 0 4px;flex-wrap:wrap}'
        . '.yf-tabs a{padding:7px 12px;font-size:13.5px;color:#6f767e;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px}'
        . '.yf-tabs a.on{color:#B3541E;border-bottom-color:#B3541E;font-weight:700}.yf-tabs a:hover{color:#B3541E}'
        . '.yf-btn,.yf-btn2{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:8px 18px;font-size:13.5px;text-decoration:none;font-weight:600;cursor:pointer;border:0}'
        . '.yf-btn{background:#B3541E;color:#fff}.yf-btn:hover{background:#9c4517}'
        . '.yf-btn2{border:1px solid #d9d2c6;color:#5a5348;background:#fff}.yf-btn2:hover{border-color:#B3541E;color:#B3541E}'
        . '.yf-btn.sm,.yf-btn2.sm{padding:4px 11px;font-size:12px}'
        . '.yf-blist{display:flex;flex-direction:column;border:1px solid var(--line,#e8e4da);border-radius:14px;overflow:hidden;background:#fff}'
        . '.yf-brow{display:flex;align-items:center;gap:14px;padding:13px 16px;border-bottom:1px solid var(--hairline,#eee7db);text-decoration:none;color:inherit;transition:background .12s}'
        . '.yf-brow:last-child{border-bottom:0}.yf-brow:hover{background:#faf7f0}'
        . '.yf-brow .bb{flex:1 1 auto;min-width:0;display:flex;align-items:center;gap:11px;text-decoration:none;color:inherit}.yf-brow .bb>div{min-width:0}'
        . '.yf-brow .bb b{font-size:15.5px;color:#23303a;display:flex;align-items:center;gap:8px}'
        . '.yf-brow .bb p{margin:4px 0 0;color:#8a8272;font-size:12.5px;line-height:1.65;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
        . '.yf-brow .bc{flex:none;width:60px;text-align:center;font-size:11px;color:#9a9a90}.yf-brow .bc b{display:block;font-size:15px;color:#23303a;line-height:1.25}'
        . '.yf-brow .bl{flex:none;width:180px;text-align:right;font-size:11.5px;color:#9a9a90;line-height:1.6;overflow:hidden}'
        . '.yf-brow .bl b{color:#6f6a5e;font-weight:600}.yf-brow .bl a{color:#6f6a5e;text-decoration:none;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
        . '.yf-brow .bl a:hover{color:#B3541E}'
        . '.yf-ico{flex:none;width:38px;height:38px;border-radius:11px;background:linear-gradient(140deg,#f6e5d4,#e9c9a8);color:#8a3f14;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;font-family:var(--font-serif,serif)}'
        . '.yf-tlist{list-style:none;margin:0;padding:0;border-bottom:1px solid var(--hairline,#eee7db)}'
        . '.yf-thread{display:flex;gap:11px;align-items:flex-start;padding:11px 4px;border-top:1px solid var(--hairline,#eee7db)}'
        . '.yf-thread .tc{flex:1;min-width:0}'
        . '.yf-thread .tt{display:flex;align-items:baseline;gap:7px;flex-wrap:wrap}'
        . '.yf-thread .tt a{color:#23303a;text-decoration:none;font-size:14.5px;line-height:1.55;font-weight:600;overflow-wrap:anywhere}'
        . '.yf-thread .tt a:hover{color:#B3541E}'
        . '.yf-thread .tm{display:flex;align-items:center;gap:6px;color:#9a9a90;font-size:12px;margin-top:3px;flex-wrap:wrap}'
        . '.yf-thread .tm .bchip{background:#f3efe6;color:#7a6a4f;border-radius:999px;padding:0 8px;font-size:11px}'
        . '.yf-thread .cnt{flex:none;display:flex;gap:14px;color:#9a9a90;font-size:11px;text-align:center}'
        . '.yf-thread .cnt b{display:block;font-size:14.5px;color:#23303a;font-variant-numeric:tabular-nums;line-height:1.15}'
        . '.yf-tag{display:inline-block;border-radius:4px;font-size:10.5px;padding:0 5px;line-height:1.6;vertical-align:1px;font-weight:600}'
        . '.yf-tag.pin{background:#B3541E;color:#fff}.yf-tag.good{background:#E8B33C;color:#4a3300}.yf-tag.lock{background:#e9e6de;color:#777}'
        . '.yf-sec{display:flex;align-items:baseline;gap:10px;margin:24px 0 8px}'
        . '.yf-sec h2{font-size:16.5px;margin:0}.yf-sec .en2{font-size:10.5px;letter-spacing:.18em;text-transform:uppercase;color:#B3541E}'
        . '.yf-sec::after{content:"";flex:1;height:1px;background:var(--hairline,#eee7db)}'
        . '.yf-sec a.more{margin-left:auto;font-size:12.5px;color:#B3541E;text-decoration:none}'
        . '.yf-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;text-align:center}'
        . '.yf-stats b{display:block;font-size:17px;color:#23303a;line-height:1.25}.yf-stats span{font-size:11px;color:#9a9a90}'
        . '.yf-sidebox p{margin:5px 0;color:#6f767e;font-size:12.5px;line-height:1.75}'
        . '.yf-sidebox .yf-btn{width:100%;justify-content:center;box-sizing:border-box}'
        . '.yf-online{display:flex;align-items:center;gap:6px;font-size:12.5px;color:#6f767e;margin-top:6px}'
        . '.yf-dot{width:7px;height:7px;border-radius:50%;background:#5aa469;display:inline-block}'
        . '.yf-pg{display:flex;gap:5px;flex-wrap:wrap;margin:16px 0 4px}'
        . '.yf-pg a{border:1px solid #ddd6ca;color:#5a5348;text-decoration:none;border-radius:8px;min-width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;padding:0 4px}'
        . '.yf-pg a.on{background:#B3541E;border-color:#B3541E;color:#fff}.yf-pg a:hover{border-color:#B3541E}'
        . '.yf-empty{color:#9a9a90;padding:28px 4px;text-align:center;font-size:13.5px}'
        . '.yf-ttl{font-size:22px;line-height:1.45;margin:6px 0 8px;font-weight:800;color:#23303a;overflow-wrap:anywhere}'
        . '.yf-thmeta{display:flex;gap:10px;flex-wrap:wrap;color:#9a9a90;font-size:12.5px;margin:0 0 14px;align-items:center}'
        . '.yf-thmeta .bchip{background:#f3efe6;color:#7a6a4f;border-radius:999px;padding:1px 10px;text-decoration:none}'
        . '.yf-floor-card{border:1px solid var(--line,#e8e4da);border-radius:14px;background:#fff;margin:0 0 12px;overflow:hidden}'
        . '.yf-floor-card.op{border-color:#dfcdb4}'
        . '.yf-fhead{display:flex;align-items:center;gap:9px;flex-wrap:wrap;padding:9px 14px;background:#f8f5ee;border-bottom:1px solid var(--hairline,#eee7db);font-size:12.5px;color:#9a9a90}'
        . '.yf-fhead .fn{color:#b3541e;font-weight:800;flex:none}'
        . '.yf-fhead .fname{color:#23303a;font-weight:700;font-size:13px}'
        . '.yf-fhead .fx{margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap}'
        . '.yf-fhead .fx a,.yf-fhead .fx button{border:0;background:none;color:#8a8272;font:inherit;font-size:12px;cursor:pointer;text-decoration:none;padding:0}'
        . '.yf-fhead .fx a:hover,.yf-fhead .fx button:hover{color:#B3541E}'
        . '.yf-fbody{padding:13px 16px 16px;color:#2c3438;font-size:14.5px;line-height:1.95;overflow-wrap:anywhere}'
        . '.yf-fbody img{max-width:100%;height:auto;border-radius:8px;margin:6px 0}'
        . '.yf-fbody pre{background:#f6f3ec;border:1px solid #e9e2d4;border-radius:8px;padding:10px 12px;overflow:auto;font-size:13px}'
        . '.yf-fbody code{background:#f3efe6;border-radius:4px;padding:1px 5px;font-size:13px}.yf-fbody pre code{background:none;padding:0}'
        . '.yf-fbody blockquote{border-left:3px solid #d8c9b2;margin:8px 0;padding:2px 12px;color:#6f767e}'
        . '.yf-quote{border:1px solid #eadfce;background:#faf6ee;border-radius:10px;padding:8px 12px;margin:0 0 10px;font-size:12.5px;color:#7a6a4f;line-height:1.75}'
        . '.yf-quote b{color:#8a4a22}.yf-quote .qp{display:block;color:#8a8272;margin-top:2px}'
        . '.yf-form{border:1px solid var(--line,#e8e4da);background:#fff;border-radius:14px;padding:16px 18px;margin-top:10px}'
        . '.yf-form h2,.yf-form h3{margin:0 0 8px;font-size:17px}'
        . '.yf-form label{display:block;font-size:12.5px;color:#6f767e;margin:10px 0 4px;font-weight:600}'
        . '.yf-form input[type=text],.yf-form textarea,.yf-form select{width:100%;box-sizing:border-box;border:1px solid #e2ddd2;border-radius:9px;padding:9px 12px;font:inherit;background:#fff;color:#23303a}'
        . '.yf-form textarea{min-height:190px;line-height:1.8;resize:vertical}'
        . '.yf-form .hint{color:#9a9a90;font-size:12px;margin-top:6px}'
        . '.yf-form .row{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:center}'
        . '.yf-pre{border:1px dashed #d8c4a8;background:#fdfaf5;border-radius:12px;padding:12px 14px;margin-top:10px;font-size:14px;line-height:1.9;overflow-wrap:anywhere}'
        . '.yf-pre .cap{font-size:11.5px;letter-spacing:.12em;color:#B3541E;text-transform:uppercase;margin-bottom:6px}'
        . '.yf-err{background:#fdf0ec;border:1px solid #efc8ba;color:#c0392b;border-radius:10px;padding:9px 14px;font-size:13.5px;margin:0 0 10px}'
        . '.yf-msg{background:#eef4ee;border:1px solid #cfe2d0;color:#2e6b34;border-radius:10px;padding:9px 14px;font-size:13.5px;margin:0 0 10px}'
        . '.yf-ava{display:inline-flex;flex:none;text-decoration:none}.yf-ava .ava{border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-family:var(--font-serif,serif)}'
        . '.ava-img{border-radius:50%;object-fit:cover;display:inline-block;flex:none;background:#f0ece2}'
        . '.yf-prof{display:flex;gap:16px;align-items:center;border:1px solid var(--line,#e8e4da);background:#fff;border-radius:16px;padding:18px 20px;margin-bottom:16px}'
        . '.yf-prof .pn{font-size:22px;font-weight:800;font-family:var(--font-serif,serif)}'
        . '.yf-prof .pm{color:#9a9a90;font-size:12.5px;margin-top:4px;line-height:1.7}'
        . '.yf-kv{display:flex;gap:18px;margin-top:8px;flex-wrap:wrap;font-size:12.5px;color:#6f767e}'
        . '.yf-kv b{color:#23303a;font-size:15px;margin-right:3px}'
        . '.yf-mods{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}'
        . '.yf-mods form{display:inline}'
        . 'html.dark .yf-top,html.dark .yf-blist,html.dark .yf-w,html.dark .yf-form,html.dark .yf-floor-card,html.dark .yf-prof,html.dark .yf-pre{background:#1c221c;border-color:#2c3a30}'
        . 'html.dark .yf-top .yf-brand,html.dark .yf-brow .bb b,html.dark .yf-brow .bc b,html.dark .yf-thread .tc .tt a,html.dark .yf-thread .cnt b,html.dark .yf-ttl,html.dark .yf-fhead .fname,html.dark .yf-prof .pn,html.dark .yf-stats b{color:#e7ebdd}'
        . 'html.dark .yf-brow:hover{background:#202a21}html.dark .yf-fhead{background:#223024;border-color:#2c3a30}'
        . 'html.dark .yf-fbody{color:#d5ddd2}html.dark .yf-fbody pre,html.dark .yf-fbody code{background:#141a15;border-color:#2c3a30}'
        . 'html.dark .yf-top input[type=search],html.dark .yf-form input[type=text],html.dark .yf-form textarea,html.dark .yf-form select{background:#141a15;border-color:#33422f;color:#e7ebdd}'
        . 'html.dark .yf-btn2{background:transparent;color:#c9d5c9;border-color:#46564a}'
        . 'html.dark .yf-pg a{color:#c9d5c9;border-color:#46564a}'
        . 'html.dark .yf-thread,html.dark .yf-brow,html.dark .yf-tabs{border-color:#24312a}'
        . 'html.dark .yf-quote{background:#241f1a;border-color:#3a2f24;color:#c9b79a}'
        . 'html.dark .yf-w li a{color:#c9d5c9}html.dark .yf-w li a:hover{background:#24312a;color:#e7ebdd}'
        . '@media(max-width:980px){.yf-layout{grid-template-columns:1fr}.yf-side{position:static;order:2}}'
        . '@media(max-width:760px){.yf-brow{gap:9px}.yf-brow .bl{display:none}.yf-brow .bc{width:52px}}'
        . '@media(max-width:560px){.yf-forum{padding:0 2px}.yf-top{gap:8px;padding:9px 10px}.yf-top form{order:3;flex-basis:100%}.yf-brow{gap:7px;padding:11px 12px}.yf-brow .bc{width:44px}.yf-brow .bc:first-of-type{display:none}.yf-thread .cnt{gap:8px}.yf-thread .cnt span:last-child{display:none}.yf-fhead{padding:8px 11px}.yf-fbody{padding:11px 12px 14px}.yf-ttl{font-size:19px}.yf-form{padding:13px 13px}}'
        . '</style>';
}
