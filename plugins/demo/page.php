<?php
declare(strict_types=1);
// /demo 在线体验与监控页
function yc_demo_page(string $slug): array
{
    $msg = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['act'] ?? '') === 'apply')) {
        $name = trim((string)($_POST['name'] ?? ''));
        $url = trim((string)($_POST['url'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if ($name === '' || $url === '') {
            $msg = '<p class="yc-msg err">请至少填写站点名称与 URL。</p>';
        } elseif (!preg_match('#^https?://#i', $url)) {
            $msg = '<p class="yc-msg err">URL 需以 http(s):// 开头。</p>';
        } else {
            Db::insert('demo_sites', [
                'name' => mb_substr($name, 0, 120), 'url' => mb_substr($url, 0, 300),
                'note' => mb_substr($note, 0, 500), 'email' => mb_substr($email, 0, 160),
                'status' => 'pending', 'last_code' => 0, 'last_ms' => 0,
            ]);
            $msg = '<p class="yc-msg ok">申请已提交：审核通过后会接入体验站列表并纳入可达性监控。</p>';
        }
    }

    $rows = Db::rows('SELECT * FROM ' . Db::t('demo_sites') . " WHERE status IN ('ok','down') ORDER BY id ASC");
    $cards = '';
    foreach ($rows as $r) {
        $ok = $r['status'] === 'ok';
        $badge = $ok ? '<span class="dot ok">●</span> 正常' : '<span class="dot bad">●</span> 异常';
        $checked = $r['checked_at'] ? '<small>最近检查 ' . htmlspecialchars((string)$r['checked_at'], ENT_QUOTES) . '</small>' : '<small>待首次检查</small>';
        $host = parse_url((string)$r['url'], PHP_URL_HOST) ?: $r['url'];
        $cards .= '<div class="dm-card"><div class="dm-top"><b>' . htmlspecialchars((string)$r['name'], ENT_QUOTES)
            . '</b>' . $badge . '</div><div class="dm-note">' . htmlspecialchars((string)$r['note'], ENT_QUOTES)
            . '</div><div class="dm-meta"><a href="' . htmlspecialchars((string)$r['url'], ENT_QUOTES) . '" rel="noopener" target="_blank">'
            . htmlspecialchars($host, ENT_QUOTES) . ' ↗</a>' . $checked . '</div></div>';
    }
    if ($cards === '') { $cards = '<p class="muted">体验站接入中，稍后回来看看。</p>'; }

    $html = '<style>
.dm{font-family:inherit}
.dm h1{font-size:26px;margin:0 0 6px}
.dm .sub{color:#6f767e;margin:0 0 18px;line-height:1.9}
.dm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px;margin:6px 0 22px}
.dm-card{border:1px solid #e6e6e2;border-radius:12px;padding:14px 16px;background:#fff}
.dm-top{display:flex;justify-content:space-between;align-items:center;gap:8px}
.dm-top b{font-size:15px}
.dot{font-size:11px}.dot.ok{color:#2e7d32}.dot.bad{color:#c62828}
.dm-note{color:#6f767e;font-size:13px;line-height:1.8;margin:6px 0}
.dm-meta{display:flex;justify-content:space-between;gap:8px;font-size:12px;color:#9a9a90;flex-wrap:wrap}
.dm-meta a{color:#b3541e;font-weight:600}
.yc-msg{padding:9px 14px;border-radius:8px;font-size:13.5px}
.yc-msg.ok{background:#eef6ee;color:#2e6b34}
.yc-msg.err{background:#fdf0ec;color:#9c3f1b}
.dm-form{border:1px solid #e6e6e2;border-radius:12px;padding:16px 18px;background:#fbfaf7;max-width:640px}
.dm-form h2{font-size:16px;margin:0 0 4px}
.dm-form .hint{color:#6f767e;font-size:13px;margin:0 0 12px}
.dm-form label{display:block;font-size:13px;color:#6f767e;margin:10px 0 2px}
.dm-form input,.dm-form textarea{width:100%;box-sizing:border-box;border:1px solid #d9dee3;border-radius:8px;padding:8px 11px;font:inherit}
.dm-form .btn{margin-top:14px}
</style>
<div class="dm">
  <h1>在线体验</h1>
  <p class="sub">这些站点都运行在 YeCha 之上，可作为“活示例”体验前台与主题效果。系统会定时探测它们的可达性；状态与最近检查时间实时展示。你也可以申请把自己的 YeCha 站点加入列表（审核通过后自动纳入监控）。</p>
  ' . $msg . '
  <div class="dm-grid">' . $cards . '</div>
  <div class="dm-form">
    <h2>申请加入体验站</h2>
    <p class="hint">提交后由站长人工审核；通过后公开为“体验站”并纳入每 5 分钟的可达性监控。</p>
    <form method="post" action="/demo">
      <input type="hidden" name="act" value="apply">
      <label>站点名称 *</label><input type="text" name="name" required maxlength="120">
      <label>站点 URL *</label><input type="url" name="url" required maxlength="300" placeholder="https://…">
      <label>一句话介绍</label><input type="text" name="note" maxlength="500" placeholder="例如：我的茶博客，运行 YeCha + TeaDoc 主题">
      <label>邮箱（用于通知审核结果）</label><input type="email" name="email" maxlength="160">
      <button class="btn" type="submit">提交申请</button>
    </form>
  </div>
</div>';
    return ['title' => '在线体验', 'html' => $html];
}
