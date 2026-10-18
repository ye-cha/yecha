<?php
/* lqht-ext.php — 第22棒（ta22）扩展 widget 渲染器：probe（服务端探测 · 4 款）· share（即现分享 · 1 款）
 * =============================================================================
 * 为什么单独一个文件：lqht.php 是**承重共享件**（1266 行产物都由它渲染）。本棒只往它的
 *   `lqht_supported()` 白名单加两个名字、在 `lqht_body()` 的分支链尾加**一个 elseif**，
 *   真正的渲染代码放这里 ⇒ 对既有 widget 的产物**逐字节零影响**（可用 regen dry-run 断言）。
 *
 * ★★ 访客侧零外发（本文件的设计前提）：
 *   两个 widget 的页面**只请求本站**（`/api/probe` · `/api/share`）；所有对第三方的访问
 *   （DNS / TCP / TLS / HTTP / 框架指纹 / 短链读取）都在**服务端**完成。
 * ========================================================================== */

if (!function_exists('lqht_ext_body')) {

    /** 入口：由 lqht_body() 在 $w ∈ {probe,share} 时调用 */
    function lqht_ext_body(string $w, string $slug, array $d, array $ui): string
    {
        if ($w === 'probe') { return lqht_ext_probe($slug, $d, $ui); }
        return lqht_ext_share($slug, $d, $ui);
    }

    /* ------------------------------------------------------------------ probe */
    function lqht_ext_probe(string $slug, array $d, array $ui): string
    {
        $op    = (string)($d['op'] ?? '');
        $label = '网站地址';
        $ph    = "https://example.com";
        foreach ((array)($d['fields'] ?? []) as $f) {
            $f = (array)$f;
            if ((string)($f['id'] ?? '') === 'url') {
                $label = (string)($f['label'] ?? $label);
                if ((string)($f['placeholder'] ?? '') !== '') { $ph = (string)$f['placeholder']; }
            }
        }
        if ($op === 'bulk') { $ph = "https://example.com\nhttps://example.org"; }
        $btn = (string)($ui['generate'] ?? '');
        if ($btn === '') { $btn = '开始检测'; }

        $js = <<<'JS'
(function () {
  var root = document.querySelector('[data-lq-tool="__SLUG__"]');
  if (!root) { return; }
  var ta  = root.querySelector('[data-lq-in="url"]');
  var btn = root.querySelector('[data-lq-act="run"]');
  var out = root.querySelector('[data-lq-out="out"]');
  var st  = root.querySelector('[data-lq-out="status"]');
  if (!ta || !btn || !out) { return; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
  function say(t, cls) { if (st) { st.textContent = t; st.className = 'probe-status ' + (cls || ''); } }
  function kv(k, v) { return '<div class="lq-stat"><span class="lq-k">' + esc(k) + '</span><span class="lq-v">' + esc(v) + '</span></div>'; }
  function render(j) {
    if (!j || j.error) { out.innerHTML = '<div class="lq-meta lq-warn">' + esc((j && (j.msg || j.error)) || 'no_result') + '</div>'; return; }
    var h = '';
    if (j.rows && j.rows.length) { h += '<div class="lq-grid">' + j.rows.map(function (r) { return kv(r[0], r[1]); }).join('') + '</div>'; }
    if (j.list && j.list.length) {
      h += '<table class="probe-tbl"><thead><tr><th>地址 / 域名</th><th>状态</th><th>说明</th></tr></thead><tbody>'
        + j.list.map(function (r) { return '<tr><td class="u">' + esc(r.url) + '</td><td>' + esc(r.status) + '</td><td>' + esc(r.note || '') + '</td></tr>'; }).join('')
        + '</tbody></table>';
    }
    if (j.note) { h += '<div class="lq-meta">' + esc(j.note) + '</div>'; }
    out.innerHTML = h || '<div class="lq-meta">无结果</div>';
  }
  function run() {
    var v = String(ta.value || '').trim();
    if (!v) { say('请先填网址', 'lq-warn'); return; }
    btn.disabled = true; say('服务端检测中…');
    fetch('/api/probe', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ op: '__OP__', url: v }) })
      .then(function (r) { return r.json().catch(function () { return { error: 'bad_json' }; }); })
      .then(function (j) { render(j); say(j && j.error ? ('失败：' + (j.msg || j.error)) : '完成', j && j.error ? 'lq-warn' : 'lq-good'); btn.disabled = false; })
      .catch(function () { say('网络错误', 'lq-bad'); btn.disabled = false; });
  }
  btn.addEventListener('click', function (e) { e.preventDefault(); run(); });
})();
JS;
        $js = str_replace(['__SLUG__', '__OP__'], [str_replace("'", "", $slug), $op], $js);

        return '<div class="lqht-widget probe-tool lqht-probe" data-tool="' . lqht_e($slug) . '">'
            . '<div class="calc-fields"><div class="calc-field-wrap">'
            . '<label for="url">' . lqht_e($label) . '</label>'
            . '<textarea class="calc-field" id="url" data-lq-in="url" rows="4" spellcheck="false" placeholder="' . lqht_e($ph) . '"></textarea>'
            . '</div></div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" data-lq-act="run">' . lqht_e($btn) . '</button></div>'
            . '<div class="probe-status" data-lq-out="status"></div>'
            . '<div class="probe-out" data-lq-out="out"></div>'
            . '<div class="lq-meta">★ 本工具由**本站服务器**代你访问目标站点（DNS / TCP / TLS / HTTP / 框架指纹全部在服务端完成）；'
            . '你的浏览器只请求本站 <code>/api/probe</code>，**不会向任何第三方发起连接**。'
            . '内网 / 回环 / 保留地址一律拒绝（SSRF 守卫）。</div>'
            . '<script>' . $js . '</script>'
            . '</div>';
    }

    /* ------------------------------------------------------------------ share */
    function lqht_ext_share(string $slug, array $d, array $ui): string
    {
        $btn = (string)($ui['generate'] ?? '');
        if ($btn === '') { $btn = '生成分享链接'; }
        $js = <<<'JS'
(function () {
  var root = document.querySelector('[data-lq-tool="__SLUG__"]');
  if (!root) { return; }
  var title = root.querySelector('[data-lq-in="title"]');
  var file  = root.querySelector('[data-lq-in="file"]');
  var text  = root.querySelector('[data-lq-in="text"]');
  var btn   = root.querySelector('[data-lq-act="run"]');
  var out   = root.querySelector('[data-lq-out="out"]');
  var st    = root.querySelector('[data-lq-out="status"]');
  if (!btn || !out) { return; }
  function say(t, cls) { if (st) { st.textContent = t; st.className = 'probe-status ' + (cls || ''); } }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
  btn.addEventListener('click', function (e) {
    e.preventDefault();
    var f = file && file.files && file.files[0];
    var t = text ? String(text.value || '') : '';
    if (!f && !t.trim()) { say('请选一个文件，或直接粘贴文本', 'lq-warn'); return; }
    btn.disabled = true; say('上传中…');
    function done(j) {
      btn.disabled = false;
      if (!j || j.error) { say('失败：' + ((j && (j.msg || j.error)) || 'unknown'), 'lq-bad'); out.innerHTML = ''; return; }
      say('已生成（' + j.expires_h + ' 小时后过期，' + esc(j.size_h) + '）', 'lq-good');
      var abs = location.origin + j.url;
      out.innerHTML = '<div class="lq-stat"><span class="lq-k">分享链接</span>'
        + '<span class="lq-v"><a href="' + esc(j.url) + '" target="_blank" rel="noopener noreferrer">' + esc(abs) + '</a></span></div>'
        + '<div class="lq-meta">把上面的地址发给别人即可打开；链接 ' + esc(j.expires_h) + ' 小时后自动失效。</div>';
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'btn-ghost'; b.textContent = '复制链接';
      b.addEventListener('click', function () { try { navigator.clipboard.writeText(abs); b.textContent = '已复制'; setTimeout(function () { b.textContent = '复制链接'; }, 1200); } catch (er) { } });
      out.appendChild(b);
    }
    if (f) {
      var fd = new FormData();
      fd.append('f', f, f.name || 'file');
      fd.append('title', title ? String(title.value || '') : '');
      fetch('/api/share', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) { return r.json().catch(function () { return { error: 'bad_json' }; }); })
        .then(done).catch(function () { say('网络错误', 'lq-bad'); btn.disabled = false; });
    } else {
      fetch('/api/share', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ text: t, title: title ? String(title.value || '') : '' }) })
        .then(function (r) { return r.json().catch(function () { return { error: 'bad_json' }; }); })
        .then(done).catch(function () { say('网络错误', 'lq-bad'); btn.disabled = false; });
    }
  });
})();
JS;
        $js = str_replace('__SLUG__', str_replace("'", '', $slug), $js);

        return '<div class="lqht-widget probe-tool lqht-share" data-tool="' . lqht_e($slug) . '">'
            . '<div class="calc-fields">'
            . '<div class="calc-field-wrap"><label for="title">标题（可选）</label>'
            . '<input class="calc-field" id="title" data-lq-in="title" type="text" placeholder="给这份内容起个名字"></div>'
            . '<div class="calc-field-wrap" style="grid-column:1/-1"><label for="file">选择文件（HTML / Markdown / SVG / 图片 / 文本，≤ 256 KB）</label>'
            . '<input class="calc-field" id="file" data-lq-in="file" type="file"></div>'
            . '<div class="calc-field-wrap" style="grid-column:1/-1"><label for="text">或直接粘贴文本</label>'
            . '<textarea class="calc-field" id="text" data-lq-in="text" rows="6" spellcheck="false" placeholder="也可以把内容直接贴在这里"></textarea></div>'
            . '</div>'
            . '<div class="tw-actions"><button type="button" class="btn-copy" data-lq-act="run">' . lqht_e($btn) . '</button></div>'
            . '<div class="probe-status" data-lq-out="status"></div>'
            . '<div class="probe-out" data-lq-out="out"></div>'
            . '<div class="lq-meta">★ 内容存在本站数据库里，生成 <code>/p/&lt;短码&gt;</code> 短链；'
            . '默认 7 天自动过期。分享页以 <code>Content-Security-Policy: sandbox</code> 隔离发送，脚本一律不执行、不被搜索引擎收录。'
            . '请勿上传敏感信息。</div>'
            . '<script>' . $js . '</script>'
            . '</div>';
    }
}
