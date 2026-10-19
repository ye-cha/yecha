<?php
/* lqht-topics.php — 第24棒（ta24）：`hotlist`（全网热榜聚合）widget 渲染器
 * =============================================================================
 * 由 themes/bl/parts/lqht.php 在 $w === 'hotlist' 时 require 并调用 lqht_topics_body()。
 * ★★ 访客侧零外发：本 widget 的页面**只请求本站** `/api/topics`（实现在 plugins/lqtopics/inc.php）；
 *    对上游（微博/知乎/百度/B站/头条/掘金/V2EX/IT之家）的访问全部在服务端完成。
 * ★★ 出处与合规：页面写明参考项目（imsyy/DailyHotApi · MIT）与"数据来自第三方公开聚合、仅供浏览"。
 * ★★ 为什么不改 lqht.css：它是共享资产、`?v=` 与 mtime 绑定（本线栽过两次）⇒ 样式用**页内 <style>**
 *    且类名一律 `lqht-topics` 前缀，不污染任何既有类。
 * ========================================================================== */

if (!function_exists('lqht_topics_body')) {

    function lqht_topics_body(string $slug, array $d, array $ui): string
    {
        /* 榜单元信息（与 plugins/lqtopics/inc.php 的 lqtopics_boards() 同源同序；此处只用于**标签**） */
        $BOARDS = [
            'all'      => ['全部榜单', '缓存快照'],
            'baidu'    => ['百度热搜', '热搜榜'],
            'weibo'    => ['微博热搜', '热搜榜'],
            'zhihu'    => ['知乎热榜', '热榜'],
            'bilibili' => ['哔哩哔哩', '排行榜 · 全站'],
            'toutiao'  => ['今日头条', '热榜'],
            'juejin'   => ['稀土掘金', '热榜'],
            'v2ex'     => ['V2EX', '热议主题'],
            'ithome'   => ['IT之家', '热榜'],
        ];
        $refresh = trim((string)($ui['refresh'] ?? ''));
        if ($refresh === '') { $refresh = '刷新'; }
        $hotErr = trim((string)($ui['hot_error'] ?? ''));
        if ($hotErr === '') { $hotErr = '热榜加载失败，请点击刷新重试'; }
        $updated = trim((string)($ui['updated_at'] ?? ''));
        if ($updated === '') { $updated = '更新于'; }

        $tabs = '';
        foreach ($BOARDS as $k => [$t, $ty]) {
            $tabs .= '<button type="button" class="lqht-topics-tab' . ($k === 'baidu' ? ' is-on' : '') . '"'
                . ' role="tab" aria-selected="' . ($k === 'baidu' ? 'true' : 'false') . '"'
                . ' data-topic="' . lqht_e($k) . '" title="' . lqht_e($ty) . '">' . lqht_e($t) . '</button>';
        }

        $js = <<<'JS'
(function () {
  var root = document.querySelector('[data-lq-tool="hot-topics"]');
  if (!root) { return; }
  var box   = root.querySelector('[data-lq-out="out"]');
  var st    = root.querySelector('[data-lq-out="status"]');
  var btn   = root.querySelector('[data-lq-act="refresh"]');
  var tabs  = root.querySelectorAll('.lqht-topics-tab');
  if (!box || !st) { return; }
  var API = '/api/topics', cur = 'baidu', busy = false;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
  function say(t, cls) { st.textContent = t; st.className = 'lqht-topics-status ' + (cls || ''); }
  function hot(n, txt) {
    if (txt) { return esc(txt); }
    n = Number(n || 0);
    if (!n) { return ''; }
    if (n >= 100000000) { return (n / 100000000).toFixed(1) + ' 亿'; }
    if (n >= 10000) { return (n / 10000).toFixed(1) + ' 万'; }
    return String(n);
  }
  function when(s) { if (!s) { return ''; } var d = new Date(s); if (isNaN(d.getTime())) { return esc(s); }
    function p(x) { return (x < 10 ? '0' : '') + x; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()); }
  function item(it, i) {
    var h = '<li class="lqht-topics-item">'
      + '<span class="lqht-topics-rank' + (i < 3 ? ' is-top' : '') + '">' + (i + 1) + '</span>'
      + '<div class="lqht-topics-body">'
      + '<a class="lqht-topics-title" href="' + esc(it.url || '#') + '" target="_blank" rel="noopener noreferrer nofollow">' + esc(it.title) + '</a>';
    var meta = [];
    if (it.author) { meta.push(esc(it.author)); }
    if (it.hot || it.hotText) { meta.push('热度 ' + hot(it.hot, it.hotText)); }
    if (it.timestamp) { meta.push(when(it.timestamp)); }
    if (meta.length) { h += '<div class="lqht-topics-meta">' + meta.join(' · ') + '</div>'; }
    if (it.desc) { h += '<p class="lqht-topics-desc">' + esc(String(it.desc).slice(0, 110)) + '</p>'; }
    return h + '</div></li>';
  }
  function listOf(j) {
    var d = (j && j.data) || [];
    if (!d.length) { return '<li class="lqht-topics-empty">该榜暂无条目</li>'; }
    return d.map(item).join('');
  }
  function renderAll(j) {
    var bs = (j && j.boards) || {}, h = '', n = 0;
    Object.keys(bs).forEach(function (k) {
      var r = bs[k] || {}, d = (r.data || []).slice(0, 8);
      n += d.length;
      h += '<li class="lqht-topics-sec"><div class="lqht-topics-sechead">' + esc(r.title || k)
        + ' <span>' + (r.total || 0) + ' 条 · ' + (r.error ? '暂无缓存' : (r.from || 'cache')) + '</span></div><ol class="lqht-topics-sublist">'
        + (d.length ? d.map(item).join('') : '<li class="lqht-topics-empty">暂无缓存（点该榜单独加载一次即可）</li>') + '</ol></li>';
    });
    box.innerHTML = h || '<li class="lqht-topics-empty">暂无缓存</li>';
    say('全部榜单（缓存快照）：' + n + ' 条', '');
  }
  function renderOne(j) {
    box.innerHTML = listOf(j);
    if (j.error) { say(ERR + '：' + (j.msg || j.error), 'is-bad'); return; }
    var src = j.from === 'upstream' ? '服务端取数' : (j.from === 'cache' ? '缓存命中' : (j.from === 'stale' ? '过期缓存兜底' : j.from));
    say((j.title || '') + ' · 共 ' + (j.total || 0) + ' 条 · ' + src + ' · ' + UPD + ' ' + when(j.updateTime)
      + (j.stale ? '（上游不可用，正在用缓存）' : ''), j.stale ? 'is-warn' : '');
    if (j.note) { say(st.textContent + ' — ' + j.note, j.stale ? 'is-warn' : ''); }
  }
  function load(b, force) {
    if (busy) { return; }
    busy = true; cur = b;
    say('加载中…', 'is-wait');
    fetch(API + '?board=' + encodeURIComponent(b) + (force ? '&_=' + Date.now() : ''), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { error: 'bad_json', msg: '返回不是合法 JSON（HTTP ' + r.status + '）' }; }); })
      .then(function (j) { if (b === 'all') { renderAll(j); } else { renderOne(j); } })
      .catch(function (e) { say(ERR + '：' + (e && e.message ? e.message : 'network'), 'is-bad'); box.innerHTML = ''; })
      .then(function () { busy = false; try { root.setAttribute('data-lq-mounted', '1'); } catch (e) {} });
  }
  var ERR = root.getAttribute('data-hot-error') || '热榜加载失败，请点击刷新重试';
  var UPD = root.getAttribute('data-updated-at') || '更新于';
  Array.prototype.forEach.call(tabs, function (t) {
    t.addEventListener('click', function (e) {
      e.preventDefault();
      Array.prototype.forEach.call(tabs, function (x) { x.classList.remove('is-on'); x.setAttribute('aria-selected', 'false'); });
      t.classList.add('is-on'); t.setAttribute('aria-selected', 'true');
      load(t.getAttribute('data-topic'), false);
    });
  });
  if (btn) { btn.addEventListener('click', function (e) { e.preventDefault(); load(cur, true); }); }
  try { if (window.LQ && typeof window.LQ.reg === 'function') { window.LQ.reg('hot-topics'); } } catch (e) {}
  if (window.__LQ_TOPICS_NOAUTO__ !== 1) { load('baidu', false); }
})();
JS;
        $js = str_replace("\n", "\n", $js);   /* 占位符已移除：错误文案 / 更新时间从 data-* 读（同一份 ui 常量） */

        $css = '.lqht-topics{font-size:14px}'
            . '.lqht-topics-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 10px}'
            . '.lqht-topics-tab{border:1px solid var(--line,#e5e7eb);background:transparent;color:inherit;border-radius:999px;padding:4px 12px;font-size:13px;cursor:pointer;line-height:1.6}'
            . '.lqht-topics-tab:hover{border-color:var(--lq-accent,#2f5bd7);color:var(--lq-accent,#2f5bd7)}'
            . '.lqht-topics-tab.is-on{background:var(--lq-accent,#2f5bd7);border-color:var(--lq-accent,#2f5bd7);color:#fff}'
            . '.lqht-topics-bar{display:flex;align-items:center;gap:10px;margin:0 0 8px}'
            . '.lqht-topics-status{font-size:12.5px;color:var(--lq-muted,#6b7280);flex:1;min-width:0}'
            . '.lqht-topics-status.is-bad{color:#b91c1c}.lqht-topics-status.is-warn{color:#b45309}'
            . '.lqht-topics-refresh{border:1px solid var(--line,#e5e7eb);background:transparent;color:inherit;border-radius:999px;padding:3px 12px;font-size:13px;cursor:pointer}'
            . '.lqht-topics-list,.lqht-topics-sublist{list-style:none;margin:0;padding:0}'
            . '.lqht-topics-item{display:flex;gap:10px;padding:8px 0;border-top:1px solid var(--line,#f1f3f5)}'
            . '.lqht-topics-item:first-child{border-top:0}'
            . '.lqht-topics-rank{flex:none;width:22px;text-align:center;font-weight:600;color:var(--lq-muted,#9ca3af);font-size:13px}'
            . '.lqht-topics-rank.is-top{color:var(--lq-accent,#2f5bd7)}'
            . '.lqht-topics-body{min-width:0;flex:1}'
            . '.lqht-topics-title{font-weight:500;text-decoration:none}'
            . '.lqht-topics-title:hover{text-decoration:underline}'
            . '.lqht-topics-meta{font-size:12px;color:var(--lq-muted,#9ca3af);margin-top:2px}'
            . '.lqht-topics-desc{margin:2px 0 0;font-size:12.5px;color:var(--lq-muted,#6b7280);overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}'
            . '.lqht-topics-empty{color:var(--lq-muted,#9ca3af);font-size:13px;padding:8px 0;list-style:none}'
            . '.lqht-topics-sec{border-top:1px solid var(--line,#f1f3f5);padding-top:8px;margin-top:8px}'
            . '.lqht-topics-sechead{font-weight:600;font-size:13.5px;margin:2px 0 4px}'
            . '.lqht-topics-sechead span{font-weight:400;color:var(--lq-muted,#9ca3af);font-size:12px}'
            . 'html.dark .lqht-topics-tab,html.dark .lqht-topics-refresh{border-color:#2a2f36}';

        return '<div class="lqht-widget lqht-topics" data-tool="' . lqht_e($slug) . '"'
            . ' data-hot-error="' . lqht_e($hotErr) . '" data-updated-at="' . lqht_e($updated) . '">'
            . '<style>' . $css . '</style>'
            . '<div class="lqht-topics-tabs" role="tablist" aria-label="榜单切换">' . $tabs . '</div>'
            . '<div class="lqht-topics-bar"><span class="lqht-topics-status" data-lq-out="status" role="status" aria-live="polite">加载中…</span>'
            . '<button type="button" class="lqht-topics-refresh" data-lq-act="refresh">' . lqht_e($refresh) . '</button></div>'
            . '<ol class="lqht-topics-list" data-lq-out="out"></ol>'
            . '<div class="lq-meta">★ 榜单数据来自<strong>第三方公开渠道/公开接口的聚合</strong>，仅供参考与浏览；'
            . '条目版权归原平台与原作者所有，请以各平台页面为准。本站服务器代为取数（你的浏览器只请求本站 '
            . '<code>/api/topics</code>，不会向任何第三方发起连接），每榜缓存 20 分钟。'
            . '接口字段结构参考开源项目 <a href="https://github.com/imsyy/DailyHotApi" target="_blank" rel="noopener noreferrer">imsyy/DailyHotApi</a>（MIT 许可）。</div>'
            . '<script>' . $js . '</script>'
            . '</div>';
    }
}
