/* ============================================================================
 * lqbookmarks · 收藏（书签）前端真应用 —— 步2b-1「本地优先内核」
 * 挂载：/bookmark 页行末尾 <script src="/themes/bl/lqbookmarks/bookmarks.js?v=<12hex>" defer></script>
 * 存储：本机 localStorage 键 `llq_bookmarks_v1`，结构【照参照 1:1】
 *       { version:2, groups:[ { key, label, items:[ { id,name,url,desc,tags[],starred,createdAt } ] } ] }
 * id 规则【照参照】：'b_' + String(url).replace(/[^a-z0-9]/g,'')  ⇒ 由 URL 派生、非随机（批8 迁移不用重编号）
 * 上限【照参照】：分组 ≤50、条目 ≤1000，超限文案逐字 `书签超限：分组≤50、条目≤1000`
 * 本步**只做本机**（云同步/导入导出/检查失效/分享 = 步2b-2）；不改任何既有 DOM，只**追加**自己的区块 ⇒ 失败也不伤页面
 * 零外发：本文件不请求任何第三方（不引库、不外链字体/图标）
 * ========================================================================== */
(function () {
  'use strict';
  var K = 'llq_bookmarks_v1';
  var MAX_G = 50, MAX_I = 1000;
  var S = { q: '', onlyStar: false, batch: false, pick: [], form: null, lastOut: '', dead: {} };

  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function idFor(url) { return 'b_' + String(url || '').replace(/[^a-z0-9]/g, ''); }
  function load() {
    var raw = null;
    try { raw = localStorage.getItem(K); } catch (e) { raw = null; }
    if (!raw) { var z = { version: 2, groups: [] }; save2(z); return z; }
    try {
      var j = JSON.parse(raw);
      if (j && j.groups && Object.prototype.toString.call(j.groups) === '[object Array]') { return { version: 2, groups: j.groups }; }
    } catch (e) { }
    return { version: 2, groups: [] };
  }
  function save2(v) {
    try { localStorage.setItem(K, JSON.stringify({ version: 2, groups: v.groups })); } catch (e) { }
    /* ★ 登录后：本机一改就排队上云（S.ready 之前不推，避免首次建键时把空壳推上去）
       ★ S.silent：cloudPull 采用云端数据时置位 ⇒ **不回推**（否则会把「已从云端拉取书签（N 个）」
         这条状态文案覆盖成「书签已上传云端（N 个）」，实测踩过） */
    if (S.ready && !S.silent && typeof schedulePush === 'function') { schedulePush(); }
  }
  function count(groups) {
    var n = 0;
    groups.forEach(function (g) { n += (g.items || []).length; });
    return n;
  }
  function toast(m) {
    var t = $('[data-lqb="toast"]');
    if (!t) { return; }
    t.textContent = m; t.removeAttribute('hidden');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { t.setAttribute('hidden', ''); }, 2600);
  }
  function out(m) { var o = $('[data-lqb="out"]'); if (o) { o.textContent = m; S.lastOut = m; } }
  function findItem(v, id) {
    for (var i = 0; i < v.groups.length; i++) {
      var its = v.groups[i].items || [];
      for (var j = 0; j < its.length; j++) { if (its[j].id === id) { return { g: v.groups[i], it: its[j], gi: i, ii: j }; } }
    }
    return null;
  }
  function guardAdd(v, addItems) {
    if (v.groups.length > MAX_G) { toast('书签超限：分组≤50、条目≤1000'); return false; }
    if (count(v.groups) + (addItems || 0) > MAX_I) { toast('书签超限：分组≤50、条目≤1000'); return false; }
    return true;
  }

  /* ---------------------------------------------------------------- 渲染 */
  function card(g, it) {
    var host = ''; try { host = new URL(it.url).hostname; } catch (e) { host = ''; }
    var starred = it.starred ? ' ★' : '';
    return '<div class="lqb-card" data-lqb="card" data-id="' + esc(it.id) + '">'
      + (S.batch ? '<label class="lqb-ck"><input type="checkbox" data-lqb="pick" data-id="' + esc(it.id) + '"' + (S.pick.indexOf(it.id) >= 0 ? ' checked' : '') + '></label>' : '')
      + '<a class="lqb-a" href="' + esc(it.url) + '" target="_blank" rel="noopener nofollow">'
      + '<span class="lqb-ico" style="background:' + hue(it.url) + '">' + esc(ini(host || it.name)) + '</span>'
      + '<span class="lqb-txt"><b>' + esc(it.name || host) + starred + '</b>'
      + '<i>' + esc(host) + '</i>'
      + (it.desc ? '<em>' + esc(it.desc) + '</em>' : '')
      + '</span></a>'
      + '<span class="lqb-acts">'
      + '<button type="button" data-lqb="share" data-id="' + esc(it.id) + '" title="单条分享">分享</button>'
      + '<button type="button" data-lqb="star" data-id="' + esc(it.id) + '" title="设为星标">' + (it.starred ? '取消星标' : '设为星标') + '</button>'
      + '<button type="button" data-lqb="edit" data-id="' + esc(it.id) + '" title="编辑">编辑</button>'
      + '<button type="button" data-lqb="del" data-id="' + esc(it.id) + '" title="删除">删除</button>'
      + '</span>' + (S.dead[it.id] ? '<span class="lqb-dead" title="上次检查时连不通">失效?</span>' : '') + '</div>';
  }
  function hue(u) {
    var h = 0, s = String(u || '');
    for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) % 360; }
    return 'hsl(' + h + ' 62% 46%)';
  }
  function ini(s) { s = String(s || '?').replace(/^www\./i, ''); return (s.charAt(0) || '?').toUpperCase(); }
  function match(it) {
    if (S.onlyStar && !it.starred) { return false; }
    if (!S.q) { return true; }
    var q = S.q.toLowerCase();
    return (String(it.name || '') + ' ' + String(it.url || '') + ' ' + String(it.desc || '') + ' ' + ((it.tags || []).join(' '))).toLowerCase().indexOf(q) >= 0;
  }
  function render() {
    var v = load();
    var root = $('[data-lqb="mine"]');
    if (!root) { return; }
    var gc = v.groups.length, ic = count(v.groups);
    var head = '<div class="lqb-head"><h2 class="lqb-h2">我的书签</h2>'
      + '<span class="lqb-meta">' + gc + ' 个分组 · ' + ic + ' 个书签</span>'
      + '<button type="button" class="lqb-b primary" data-lqb="add">添加书签</button>'
      + '<input class="lqb-search" data-lqb="q" placeholder="搜索书签名称、网址或标签…" value="' + esc(S.q) + '">'
      + '<button type="button" class="lqb-b' + (S.onlyStar ? ' on' : '') + '" data-lqb="onlystar">星标</button>'
      + '<button type="button" class="lqb-b' + (S.batch ? ' on' : '') + '" data-lqb="batch">批量</button>'
      + (S.batch ? '<button type="button" class="lqb-b" data-lqb="batch-del">删除所选</button>' : '')
      + '<button type="button" class="lqb-b" data-lqb="export">导出</button>'
      + '<button type="button" class="lqb-b" data-lqb="import">导入</button>'
      + '<button type="button" class="lqb-b" data-lqb="navimport">从公共导航导入</button>'
      + '<button type="button" class="lqb-b" data-lqb="checkdead">检查失效</button>'
      + '<button type="button" class="lqb-b" data-lqb="newgroup">新分组</button>'
      + '<input type="file" data-lqb="lqbfile" accept=".html,.htm,.json,text/html,application/json" style="display:none">'
      + '</div>';
    var body = '';
    if (!gc) {
      body = '<p class="lqb-help">书签库为空 —— 点上面「添加书签」开始；公共导航里的站点也能逐条收进来。</p>';
    } else {
      v.groups.forEach(function (g, gi) {
        var its = (g.items || []).filter(match);
        if (S.q || S.onlyStar) { if (!its.length) { return; } }
        body += '<div class="lqb-group"><div class="lqb-ghead"><h3>' + esc(g.label || g.key) + '</h3>'
          + '<span class="lqb-meta">' + ((g.items || []).length) + ' 个</span>'
          + '<button type="button" class="lqb-b sm" data-lqb="add" data-g="' + gi + '">添加书签</button>'
          + '<button type="button" class="lqb-b sm" data-lqb="delgroup" data-g="' + gi + '">删除分组</button>'
          + '</div><div class="lqb-grid">';
        its.forEach(function (it) { body += card(g, it); });
        body += '</div></div>';
      });
    }
    var stat = statusHtml();
    root.innerHTML = head + (S.form ? formHtml() : '') + '<div class="lqb-out" data-lqb="out">' + esc(S.lastOut) + '</div>' + body + stat;
    if (S.form) { var f = $('[data-lqb="f-name"]'); if (f) { try { f.focus(); } catch (e) { } } }
  }
  function formHtml() {
    var f = S.form;
    return '<div class="lqb-form">'
      + '<input data-lqb="f-name" placeholder="名称" value="' + esc(f.name || '') + '">'
      + '<input data-lqb="f-url" placeholder="网址（http/https）" value="' + esc(f.url || '') + '">'
      + '<input data-lqb="f-desc" placeholder="备注（为什么收藏它）" value="' + esc(f.desc || '') + '">'
      + '<input data-lqb="f-tags" placeholder="标签（逗号分隔）" value="' + esc(f.tags || '') + '">'
      + '<button type="button" class="lqb-b primary" data-lqb="save">保存</button>'
      + '<button type="button" class="lqb-b" data-lqb="cancel">取消</button></div>';
  }

  /* ---------------------------------------------------------------- 动作 */
  function doSave() {
    var v = load(), f = S.form; if (!f) { return; }
    var name = ($('[data-lqb="f-name"]') || {}).value || '';
    var url = (($('[data-lqb="f-url"]') || {}).value || '').trim();
    var desc = ($('[data-lqb="f-desc"]') || {}).value || '';
    var tags = (($('[data-lqb="f-tags"]') || {}).value || '');
    if (!url || !/^https?:\/\//i.test(url)) { out('网址要以 http:// 或 https:// 开头'); return; }
    var id = idFor(url);
    var tagsArr = tags.split(/[,，]/).map(function (s) { return s.trim(); }).filter(Boolean);
    if (f.id) {
      var h = findItem(v, f.id);
      if (!h) { out('这条书签已经不在了'); S.form = null; render(); return; }
      h.it.name = name || h.it.name; h.it.url = url; h.it.desc = desc; h.it.tags = tagsArr;
      save2(v); S.form = null; render(); toast('书签已更新');
      return;
    }
    if (findItem(v, id)) { out('这个网址已经在书签里了（同名网址自动跳过）'); return; }
    if (!guardAdd(v, 1)) { return; }
    var gi = (typeof f.g === 'number') ? f.g : -1;
    if (gi < 0 || !v.groups[gi]) {
      // 无分组 ⇒ 自动建一个（第一组）
      v.groups.push({ key: 'g' + Date.now().toString(36), label: '默认', items: [] });
      gi = v.groups.length - 1;
    }
    v.groups[gi].items.push({ id: id, name: name || url.replace(/^https?:\/\//i, '').replace(/\/.*$/, ''), url: url, desc: desc, tags: tagsArr, starred: false, createdAt: Date.now() });
    save2(v); S.form = null; render(); toast('书签已添加');
  }
  function del(id) {
    var v = load(), h = findItem(v, id); if (!h) { return; }
    h.g.items.splice(h.ii, 1);
    save2(v); render(); toast('书签已删除');
  }
  function batchDel() {
    if (!S.pick.length) { out('先勾选要删除的书签'); return; }
    var v = load();
    for (var i = v.groups.length - 1; i >= 0; i--) {
      v.groups[i].items = (v.groups[i].items || []).filter(function (it) { return S.pick.indexOf(it.id) < 0; });
    }
    var n = S.pick.length; S.pick = []; save2(v); render(); toast('已批量删除（' + n + ' 个）');
  }
  function navToMine(btn) {
    // 把公共导航里的一张卡收进「我的书签」（= 逐条「从公共导航导入」）
    var box = btn.closest ? btn.closest('.group, [class*="group"]') : null;
    var a = box ? box.querySelector('a[href^="http"]') : null;
    var href = a ? a.getAttribute('href') : '';
    if (!href) { out('没找到这一组的网址'); return; }
    var nm = a.querySelector('div') ? (a.querySelector('div').textContent || '').trim() : href;
    var v = load(), id = idFor(href);
    if (findItem(v, id)) { out('这个网址已经在书签里了'); return; }
    if (!guardAdd(v, 1)) { return; }
    if (!v.groups.length) { v.groups.push({ key: 'g' + Date.now().toString(36), label: '默认', items: [] }); }
    v.groups[0].items.push({ id: id, name: nm || href, url: href, desc: '', tags: [], starred: false, createdAt: Date.now() });
    save2(v); render(); toast('书签已添加');
  }

  /* ---------------------------------------------------------------- 事件（委托，单点绑定） */
  function onClick(e) {
    var t = e.target;
    if (!t || !t.getAttribute) { return; }
    var k = t.getAttribute('data-lqb');
    if (!k) { return; }
    var id = t.getAttribute('data-id') || '';
    if (k === 'add') { e.preventDefault(); var v = load(); S.form = { g: parseInt(t.getAttribute('data-g'), 10) }; if (v.groups.length > MAX_G) { toast('书签超限：分组≤50、条目≤1000'); } render(); return; }
    if (k === 'newgroup') {
      e.preventDefault();
      var v2 = load();
      if (v2.groups.length + 1 > MAX_G) { toast('书签超限：分组≤50、条目≤1000'); return; }
      var name = window.prompt('新分组名');
      if (name == null) { return; }
      name = String(name).trim(); if (!name) { return; }
      if (v2.groups.some(function (g) { return (g.label || '') === name; })) { toast('分组已存在'); return; }
      v2.groups.push({ key: 'g' + Date.now().toString(36), label: name, items: [] });
      save2(v2); render(); return;
    }
    if (k === 'delgroup') {
      e.preventDefault();
      var v3 = load(), gi = parseInt(t.getAttribute('data-g'), 10);
      if (!v3.groups[gi]) { return; }
      var cnt = (v3.groups[gi].items || []).length;
      if (cnt && !window.confirm('这个分组里还有 ' + cnt + ' 条书签，确定删除分组及其内容吗？')) { return; }
      v3.groups.splice(gi, 1); save2(v3); render(); toast('分组已删除'); return;
    }
    if (k === 'star') {
      e.preventDefault(); var v4 = load(), h = findItem(v4, id); if (!h) { return; }
      h.it.starred = !h.it.starred; save2(v4); render(); return;
    }
    if (k === 'edit') {
      e.preventDefault(); var h2 = findItem(load(), id); if (!h2) { return; }
      S.form = { id: id, name: h2.it.name, url: h2.it.url, desc: h2.it.desc, tags: (h2.it.tags || []).join(',') };
      render(); return;
    }
    if (k === 'del') { e.preventDefault(); del(id); return; }
    if (k === 'save') { e.preventDefault(); doSave(); return; }
    if (k === 'cancel') { e.preventDefault(); S.form = null; render(); return; }
    if (k === 'share') { e.preventDefault(); shareItem(id); return; }
    if (k === 'export') { e.preventDefault(); exportHtml(); return; }
    if (k === 'import') { e.preventDefault(); var fi = $('[data-lqb="lqbfile"]'); if (fi) { fi.click(); } return; }
    if (k === 'navimport') { e.preventDefault(); importNav(); return; }
    if (k === 'checkdead') { e.preventDefault(); checkDead(); return; }
    if (k === 'retry') { e.preventDefault(); cloudPush(); return; }
    if (k === 'onlystar') { e.preventDefault(); S.onlyStar = !S.onlyStar; render(); return; }
    if (k === 'batch') { e.preventDefault(); S.batch = !S.batch; if (!S.batch) { S.pick = []; } render(); return; }
    if (k === 'batch-del') { e.preventDefault(); batchDel(); return; }
    if (k === 'share-add' || k === 'nav-into') { e.preventDefault(); navToMine(t); return; }
  }
  function onInput(e) {
    var t = e.target;
    if (!t || !t.getAttribute) { return; }
    if (t.getAttribute('data-lqb') === 'q') { S.q = t.value || ''; render(); var q = $('[data-lqb="q"]'); if (q) { q.focus(); try { q.setSelectionRange(q.value.length, q.value.length); } catch (er) { } } }
  }
  function onChange(e) {
    var t = e.target;
    if (t && t.getAttribute && t.getAttribute('data-lqb') === 'lqbfile') {
      var f = t.files && t.files[0];
      if (!f) { return; }
      var rd = new FileReader();
      rd.onload = function () { importText(String(rd.result || '')); };
      rd.onerror = function () { out('读文件失败'); };
      rd.readAsText(f, 'utf-8');
      t.value = '';
      return;
    }
    if (t && t.getAttribute && t.getAttribute('data-lqb') === 'pick') {
      var id = t.getAttribute('data-id');
      if (t.checked) { if (S.pick.indexOf(id) < 0) { S.pick.push(id); } } else { S.pick = S.pick.filter(function (x) { return x !== id; }); }
    }
  }

  /* ============================================================================
   * 步2b-2：云同步 / 导入导出 / 检查失效 / 单条分享 / 从公共导航导入（批量）
   * 状态条三句【逐字】：已从云端拉取书签（N 个） · 书签已上传云端（N 个） · 云端同步失败，数据已保存在本地，点击重试
   * ★ 零外发口径（写清时刻）：**页面加载阶段**只请求本站（+ 已知 CF 注入 beacon）；
   *   「检查失效」与「点击书签」本质要联系外站 —— 与参照一致、**由用户主动发起**，不计入页面自身外发。
   * ========================================================================== */
  var CLOUD = { uid: 0, csrf: '', on: false, msg: '', busy: false, timer: null };
  function api(path, opt) {
    opt = opt || {};
    var h = { 'Content-Type': 'application/json' };
    if (CLOUD.csrf) { h['X-CSRF-Token'] = CLOUD.csrf; }
    return fetch(path, {
      method: opt.method || 'GET', credentials: 'same-origin', headers: h,
      body: opt.body ? JSON.stringify(opt.body) : undefined
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'bad_json' }; }).then(function (j) { j._status = r.status; return j; });
    }).catch(function () { return { ok: false, error: 'net' }; });
  }
  function cloudProbe() {
    return api('/api/auth/me').then(function (j) {
      if (j && j.uid) { CLOUD.uid = j.uid; CLOUD.csrf = j.csrf || ''; CLOUD.on = true; }
      return CLOUD.on;
    });
  }
  function cloudPull() {
    if (!CLOUD.on) { return Promise.resolve(false); }
    return api('/api/auth/bookmarks').then(function (j) {
      var v = load();
      if (j && j.ok && j.bookmarks && (j.bookmarks.groups || []).length) {
        v.groups = j.bookmarks.groups;
        S.silent = true; save2(v); S.silent = false;     // ★ 采用云端 ⇒ 静默落盘，不回推
        CLOUD.msg = '已从云端拉取书签（' + count(v.groups) + ' 个）';
        render();
        return true;
      }
      if (v.groups.length) { return cloudPush(); }   // 云端还没有 ⇒ 把本机这份推上去
      CLOUD.msg = ''; render();
      return false;
    });
  }
  function cloudPush() {
    if (!CLOUD.on) { return Promise.resolve(false); }
    var v = load();
    CLOUD.busy = true;
    return api('/api/auth/bookmarks', { method: 'PUT', body: { bookmarks: { version: 2, groups: v.groups } } })
      .then(function (j) {
        CLOUD.busy = false;
        if (j && j.ok) { CLOUD.msg = '书签已上传云端（' + count(v.groups) + ' 个）'; }
        else { CLOUD.msg = '云端同步失败，数据已保存在本地，点击重试'; }
        render();
        return !!(j && j.ok);
      });
  }
  function schedulePush() {
    if (!CLOUD.on) { return; }
    clearTimeout(CLOUD.timer);
    CLOUD.timer = setTimeout(function () { cloudPush(); }, 700);
  }
  function statusHtml() {
    if (!CLOUD.on) {
      return '<div class="lqb-stat"><b>本地模式</b><span>书签当前保存在本机浏览器。登录后自动云端同步，换设备不丢书签。</span></div>';
    }
    var m = CLOUD.msg || '已登录 · 改动会自动上云';
    var retry = (CLOUD.msg === '云端同步失败，数据已保存在本地，点击重试') ? ' <button type="button" class="lqb-b sm" data-lqb="retry">点击重试</button>' : '';
    return '<div class="lqb-stat"><b>云端同步</b><span>' + esc(m) + '</span>' + retry + '</div>';
  }
  function dl(name, text, mime) {
    try {
      var b = new Blob([text], { type: mime || 'text/plain;charset=utf-8' });
      var u = URL.createObjectURL(b), a = document.createElement('a');
      a.href = u; a.download = name; document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(u); if (a.parentNode) { a.parentNode.removeChild(a); } }, 1200);
    } catch (e) { out('导出失败：' + (e && e.message ? e.message : '浏览器不支持')); }
  }
  function exportHtml() {
    var v = load(), L = [];
    L.push('<!DOCTYPE NETSCAPE-Bookmark-file-1>');
    L.push('<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">');
    L.push('<TITLE>Bookmarks</TITLE><H1>Bookmarks</H1><DL><p>');
    v.groups.forEach(function (g) {
      L.push('    <DT><H3>' + esc(g.label || g.key) + '</H3>');
      L.push('    <DL><p>');
      (g.items || []).forEach(function (it) {
        L.push('        <DT><A HREF="' + esc(it.url) + '" ADD_DATE="' + Math.floor((it.createdAt || Date.now()) / 1000) + '">' + esc(it.name || it.url) + '</A>');
        if (it.desc) { L.push('        <DD>' + esc(it.desc)); }
      });
      L.push('    </DL><p>');
    });
    L.push('</DL><p>');
    dl('liulanqi-bookmarks.html', L.join('\n'), 'text/html;charset=utf-8');
    out('已导出 ' + count(v.groups) + ' 条为 Netscape 书签 HTML（在浏览器书签管理器里「导入书签」即可写回）');
  }
  function parseNetscape(t) {
    var groups = [], cur = null;
    var re = /<DT>\s*<H3[^>]*>([\s\S]*?)<\/H3>|<DT>\s*<A\s+[^>]*HREF="([^"]+)"[^>]*>([\s\S]*?)<\/A>/gi, m;
    function strip(s) {
      return String(s || '').replace(/<[^>]*>/g, '').replace(/&amp;/g, '&').replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").trim();
    }
    while ((m = re.exec(t)) !== null) {
      if (m[1] !== undefined) { cur = { key: 'g' + Date.now().toString(36) + groups.length, label: strip(m[1]) || '导入的书签', items: [] }; groups.push(cur); continue; }
      var url = m[2], nm = strip(m[3]) || url;
      if (!/^https?:\/\//i.test(url)) { continue; }
      if (!cur) { cur = { key: 'g' + Date.now().toString(36), label: '导入的书签', items: [] }; groups.push(cur); }
      cur.items.push({ id: idFor(url), name: nm, url: url, desc: '', tags: [], starred: false, createdAt: Date.now() });
    }
    return groups;
  }
  function importText(txt) {
    var v = load(), added = 0, merged = 0, groups = null;
    if (/<DT>|<DL>/i.test(txt)) { groups = parseNetscape(txt); }
    else { try { var j = JSON.parse(txt); groups = (j && j.groups) ? j.groups : null; } catch (e) { groups = null; } }
    if (!groups || !groups.length) { out('没认出这个文件（支持 Netscape 书签 HTML 或 v2 JSON）'); return; }
    groups.forEach(function (g) {
      var label = g.label || g.key || '导入的书签', host = null, i;
      for (i = 0; i < v.groups.length; i++) { if (v.groups[i].label === label) { host = v.groups[i]; break; } }
      if (!host) {
        if (v.groups.length + 1 > MAX_G) { toast('书签超限：分组≤50、条目≤1000'); return; }
        host = { key: g.key || ('g' + Date.now().toString(36)), label: label, items: [] };
        v.groups.push(host);
      }
      (g.items || []).forEach(function (it) {
        if (!it || !it.url) { return; }
        var id = it.id || idFor(it.url);
        if (findItem(v, id)) { merged++; return; }              // 同名网址自动跳过
        if (count(v.groups) + 1 > MAX_I) { return; }
        host.items.push({ id: id, name: it.name || it.url, url: it.url, desc: it.desc || '', tags: it.tags || [], starred: !!it.starred, createdAt: it.createdAt || Date.now() });
        added++;
      });
    });
    save2(v); render(); schedulePush();
    out('导入完成：新增 ' + added + ' 条' + (merged ? '，跳过重复 ' + merged + ' 条' : '') + '（原有书签一条没动）');
  }
  /* 检查失效：★ 用户主动发起 ⇒ 会联系外站（与参照一致）；跨域读不到状态码，只能判「连不通」 */
  function checkDead() {
    var v = load(), all = [];
    v.groups.forEach(function (g) { (g.items || []).forEach(function (it) { all.push(it); }); });
    if (!all.length) { out('书签库为空，没有可检查的链接'); return; }
    out('正在检查 ' + all.length + ' 条…（这一步由你的浏览器去连这些网站，是你主动发起的请求）');
    var bad = 0, done = 0;
    all.forEach(function (it) {
      var ctl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
      var t = setTimeout(function () { if (ctl) { try { ctl.abort(); } catch (e) { } } }, 8000);
      fetch(it.url, { mode: 'no-cors', cache: 'no-store', signal: ctl ? ctl.signal : undefined })
        .catch(function () { bad++; S.dead[it.id] = 1; })
        .then(function () {
          clearTimeout(t); done++;
          if (done === all.length) { render(); out('检查完成：' + bad + ' 条连不通（跨域读不到状态码，只能判「连不通」；已在卡片上标出）'); }
        });
    });
  }
  function shareItem(id) {
    if (!CLOUD.on) { toast('登录后才能分享'); return; }
    api('/api/auth/bookmarks/' + encodeURIComponent(id) + '/share', { method: 'POST', body: { share: true } })
      .then(function (j) {
        if (j && j.ok && j.url) { out('单条分享已开启：' + location.origin + j.url + '（未登录也能打开，页面已标 noindex）'); toast('已开启分享'); }
        else { toast((j && j.msg) ? j.msg : '分享失败，请稍后再试'); }
      });
  }
  /* 从公共导航导入（批量）：把静态壳里公共导航每一组整组收进「我的书签」 */
  function navGroups() {
    var res = [], grids = document.querySelectorAll('div.grid');
    Array.prototype.forEach.call(grids, function (gr) {
      if (gr.closest('[data-lqb="mine"]')) { return; }
      var as = gr.querySelectorAll('a[href^="http"]');
      if (!as.length) { return; }
      var box = gr.parentElement, h2 = box ? box.querySelector('h2') : null, items = [];
      Array.prototype.forEach.call(as, function (a) {
        var nm = a.querySelector('div') ? (a.querySelector('div').textContent || '').trim() : '';
        items.push({ url: a.getAttribute('href'), name: nm });
      });
      res.push({ label: h2 ? (h2.textContent || '').trim() : '公共导航', items: items });
    });
    return res;
  }
  function importNav() {
    var gs = navGroups();
    if (!gs.length) { out('没找到公共导航数据'); return; }
    var v = load(), added = 0, gi = 0;
    gs.forEach(function (g) {
      var host = null, i;
      for (i = 0; i < v.groups.length; i++) { if (v.groups[i].label === g.label) { host = v.groups[i]; break; } }
      if (!host) {
        if (v.groups.length + 1 > MAX_G) { toast('书签超限：分组≤50、条目≤1000'); return; }
        host = { key: 'nav' + (gi++), label: g.label, items: [] };
        v.groups.push(host);
      }
      g.items.forEach(function (it) {
        var id = idFor(it.url);
        if (findItem(v, id)) { return; }
        if (count(v.groups) + 1 > MAX_I) { return; }
        host.items.push({ id: id, name: it.name || it.url, url: it.url, desc: '', tags: [], starred: false, createdAt: Date.now() });
        added++;
      });
    });
    save2(v); render(); schedulePush();
    out('已从公共导航导入 ' + added + ' 条（同名网址自动跳过；原有书签一条没动）');
    toast('已从公共导航导入 ' + added + ' 条');
  }

  /* ---------------------------------------------------------------- 挂载 */
  function mount() {
    if ($('[data-lqb="mine"]')) { return; }
    var v = load();                                  // 首次访问即建立本机键
    var host = document.querySelector('main') || document.querySelector('#content') || document.querySelector('.content') || document.body;
    var sec = document.createElement('section');
    sec.setAttribute('data-lqb', 'mine');
    sec.className = 'lqb-mine';
    var toastEl = document.createElement('div');
    toastEl.setAttribute('data-lqb', 'toast'); toastEl.setAttribute('hidden', ''); toastEl.className = 'lqb-toast';
    host.insertBefore(toastEl, host.firstChild);
    host.insertBefore(sec, host.firstChild);
    var st = document.createElement('style');
    st.textContent = CSS;
    document.head.appendChild(st);
    render();
    document.addEventListener('click', onClick, true);
    document.addEventListener('input', onInput, true);
    document.addEventListener('change', onChange, true);
    window.LQBOOKMARKS = { key: K, cloud: function () { return { uid: CLOUD.uid, on: CLOUD.on, msg: CLOUD.msg, busy: CLOUD.busy }; }, push: function () { return cloudPush(); }, state: function () { return { groups: load().groups.length, items: count(load().groups), q: S.q, onlyStar: S.onlyStar, batch: S.batch, pick: S.pick.length, form: !!S.form, out: S.lastOut, dead: Object.keys(S.dead).length }; } };
    /* ★ 登录态探测 → 拉云（云端有则以云端为准；云端空则把本机这份推上去） */
    cloudProbe().then(function (on) {
      S.ready = true;
      if (on) { return cloudPull(); }
      render();
    });
  }

  var CSS = ''
    + '[data-lqb="mine"]{margin:0 0 28px;font-size:14px;color:inherit}'
    + '.lqb-head{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:10px}'
    + '.lqb-h2{font-size:16px;font-weight:700;margin:0}'
    + '.lqb-meta{font-size:12px;color:#6b7280}'
    + '.lqb-search{flex:1 1 200px;min-width:160px;height:30px;padding:0 10px;border:1px solid #d1d5db;border-radius:8px;background:transparent;color:inherit}'
    + '.lqb-b{height:28px;padding:0 10px;border:1px solid #d1d5db;border-radius:8px;background:transparent;color:inherit;font-size:12px;cursor:pointer}'
    + '.lqb-b.on{border-color:#2563eb;color:#2563eb}.lqb-b.primary{background:#2563eb;border-color:#2563eb;color:#fff}.lqb-b.sm{height:24px;font-size:11.5px}'
    + '.lqb-group{margin:0 0 14px}.lqb-ghead{display:flex;align-items:center;gap:8px;margin-bottom:6px}'
    + '.lqb-ghead h3{font-size:13.5px;font-weight:600;margin:0}'
    + '.lqb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px}'
    + '.lqb-card{position:relative;display:flex;align-items:center;gap:8px;border:1px solid #e5e7eb;border-radius:10px;padding:8px 10px;background:transparent}'
    + '.lqb-a{display:flex;align-items:center;gap:8px;min-width:0;flex:1;text-decoration:none;color:inherit}'
    + '.lqb-ico{width:30px;height:30px;flex:0 0 30px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px}'
    + '.lqb-txt{min-width:0;display:flex;flex-direction:column}.lqb-txt b{font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    + '.lqb-txt i{font-size:11.5px;color:#6b7280;font-style:normal;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    + '.lqb-txt em{font-size:11px;color:#9ca3af;font-style:normal;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    + '.lqb-acts{display:flex;gap:4px;opacity:0;transition:opacity .12s}.lqb-card:hover .lqb-acts{opacity:1}'
    + '.lqb-acts button{font-size:11px;padding:2px 6px;border:1px solid #d1d5db;border-radius:6px;background:transparent;color:inherit;cursor:pointer}'
    + '.lqb-form{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0;padding:8px;border:1px dashed #d1d5db;border-radius:10px}'
    + '.lqb-form input{height:28px;padding:0 8px;border:1px solid #d1d5db;border-radius:8px;background:transparent;color:inherit;font-size:12.5px;flex:1 1 140px}'
    + '.lqb-out{min-height:16px;font-size:12px;color:#2563eb;margin:4px 0}'
    + '.lqb-help{font-size:12.5px;color:#6b7280;margin:6px 0}'
    + '.lqb-stat{display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;padding-top:8px}'
    + '.lqb-stat b{color:#2563eb;font-weight:600}'
    + '.lqb-ck{margin-right:2px}'
    + '.lqb-dead{flex:0 0 auto;font-size:10.5px;color:#b91c1c;border:1px solid #fca5a5;border-radius:6px;padding:1px 5px}'
    + '.lqb-toast{position:fixed;left:50%;bottom:28px;transform:translateX(-50%);background:#111827;color:#fff;font-size:13px;padding:8px 16px;border-radius:8px;z-index:90}'
    + '.dark .lqb-search,.dark .lqb-form input,.dark .lqb-b,.dark .lqb-acts button,.dark .lqb-card,.dark .lqb-stat{border-color:#374151}'
    + '.dark .lqb-search,.dark .lqb-form input{color:#e5e7eb}';

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', mount); } else { mount(); }
})();
