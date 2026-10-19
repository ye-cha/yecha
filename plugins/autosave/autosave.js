/* 文章自动保存 (Autosave) · 前端
 * 职责:定时留底 / 停止输入留底 / 切走页面留底 / 点「保存」前留底 / 一键载入历史版本
 * 设计要点:
 *  - 富文本模式下同步记录 rich_html(DOM 原文) —— 上次丢正文正是「富文本 → Markdown」转换环节,
 *    只存转换结果是救不回来的,原始 DOM 才救得回来。
 *  - 点「保存」时额外记录 contentValue 的值(= 本次真正会提交给服务端的内容),便于事后对照。
 *  - 载入快照只填编辑器,不直接写库;核对后由用户自己点保存。
 */
(function () {
  'use strict';
  var cfgEl = document.getElementById('asvCfg');
  if (!cfgEl) { return; }
  var CFG = {};
  try { CFG = JSON.parse(cfgEl.textContent || '{}'); } catch (e) { return; }
  if (!CFG || !CFG.save) { return; }

  var mdIn = document.getElementById('mdIn');
  var richIn = document.getElementById('richIn');
  var form = document.getElementById('postForm');
  var cv = document.getElementById('contentValue');
  var txt = document.getElementById('asvText');
  if (!mdIn || !form) { return; }

  var lastSig = null;
  var sending = false;
  var idleTimer = null;

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function nowStr() { var d = new Date(); return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()); }
  function setText(s) { if (txt) { txt.textContent = s; } }
  function val(id) { var n = document.getElementById(id); return n ? n.value : ''; }
  function richMode() { return !!(richIn && richIn.style.display !== 'none'); }

  function payload(reason, isSubmit) {
    var rich = richMode();
    var p = {
      cid: CFG.cid || 0,
      type: CFG.type || 'post',
      lang: CFG.lang || 'zh',
      title: val('edTitle'),
      slug: val('edSlug'),
      mode: rich ? 'rich' : 'md',
      reason: reason,
      content: '',
      rich_html: '',
      _csrf: CFG.csrf || ''
    };
    if (rich) {
      p.rich_html = richIn.innerHTML || '';
      /* 富文本模式:只有"点保存"这一刻才有权威的 Markdown(editor.js 此时刚同步过隐藏域) */
      if (isSubmit && cv && cv.value) { p.content = cv.value; }
    } else {
      p.content = mdIn.value || '';
    }
    return p;
  }
  function sigOf(p) {
    var s = (p.content || '') + '\u0000' + (p.rich_html || '');
    var h = 0;
    for (var i = 0; i < s.length; i++) { h = ((h << 5) - h + s.charCodeAt(i)) | 0; }
    return s.length + ':' + h;
  }
  function send(p, beacon, quiet) {
    var fd = new FormData();
    for (var k in p) { if (Object.prototype.hasOwnProperty.call(p, k)) { fd.append(k, p[k]); } }
    if (beacon && navigator.sendBeacon) {
      try { navigator.sendBeacon(CFG.save, fd); } catch (e) {}
      return;
    }
    if (sending) { return; }
    sending = true;
    if (!quiet) { setText('自动保存中…'); }
    fetch(CFG.save, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        sending = false;
        if (!j || !j.ok) { setText('自动保存失败' + (j && j.error ? '：' + j.error : '')); return; }
        if (typeof j.versions === 'number') { paintCount(j.versions); }
        var when = (j.created_at || '').slice(11, 19) || nowStr();
        if (j.skipped === 'dup') { setText('内容未变 · 已有 ' + (j.versions || 0) + ' 个版本'); }
        else if (j.skipped === 'empty') { setText('正文为空,未留底'); }
        else { setText('已自动保存 ' + when + ' · 共 ' + (j.versions || 0) + ' 个版本'); }
      })
      .catch(function () { sending = false; setText('自动保存失败（网络异常）'); });
  }
  function paintCount(n) {
    var b = document.getElementById('asvHistN');
    if (b) { b.textContent = String(n); }
    CFG.versions = n;
  }
  function maybe(reason, force) {
    var p = payload(reason, false);
    if (!p.content && !p.rich_html) { return; }
    var s = sigOf(p);
    if (!force && s === lastSig) { return; }
    lastSig = s;
    send(p, false, false);
  }
  function armIdle() {
    if (idleTimer) { clearTimeout(idleTimer); }
    idleTimer = setTimeout(function () { maybe('timer', false); }, 5000);
  }

  /* 初始基线:避免刚打开页面就为"没改过"的内容留一版 */
  lastSig = sigOf(payload('timer', false));

  /* 1) 定时留底 */
  setInterval(function () { maybe('timer', false); }, Math.max(10, CFG.interval || 30) * 1000);

  /* 2) 输入后停手 5 秒留底 */
  ['input', 'change', 'keyup'].forEach(function (ev) { mdIn.addEventListener(ev, armIdle); });
  if (richIn) { ['input', 'keyup'].forEach(function (ev) { richIn.addEventListener(ev, armIdle); }); }
  ['edTitle', 'edSlug'].forEach(function (id) {
    var n = document.getElementById(id);
    if (n) { n.addEventListener('input', armIdle); }
  });

  /* 3) 切走页面/关标签时留底(sendBeacon,页面卸载也能发出去) */
  function flushBeacon(reason) {
    var p = payload(reason, false);
    var s = sigOf(p);
    if (s === lastSig || (!p.content && !p.rich_html)) { return; }
    lastSig = s;
    send(p, true, true);
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden' && CFG.onHide) { flushBeacon('hide'); }
  });
  window.addEventListener('pagehide', function () { if (CFG.onHide) { flushBeacon('hide'); } });

  /* 4) 点「保存」前留底:这一版记录的是"按下去那一刻"的正文与富文本原文 */
  form.addEventListener('submit', function () {
    if (!CFG.onSubmit) { return; }
    var p = payload('submit', true);
    lastSig = sigOf(p);
    send(p, true, true);
  });

  /* 5) 「立即保存一版」 */
  var btn = document.getElementById('asvNow');
  if (btn) {
    btn.addEventListener('click', function () {
      var p = payload('manual', false);
      if (!p.content && !p.rich_html) { setText('正文为空,未留底'); return; }
      lastSig = sigOf(p);
      send(p, false, false);
    });
  }

  /* 6) 载入历史版本(?restore=<id>) */
  function toMd() { var b = document.querySelector('#edMode button[data-mode="md"]'); if (b) { b.click(); } }
  function toRich() { var b = document.querySelector('#edMode button[data-mode="rich"]'); if (b) { b.click(); } }
  function banner(html) {
    var n = document.getElementById('asvRestore');
    if (!n) {
      n = document.createElement('div');
      n.className = 'asv-ok';
      n.id = 'asvRestore';
      var bar = document.getElementById('asvBar');
      if (bar && bar.parentNode) { bar.parentNode.insertBefore(n, bar.nextSibling); } else { form.appendChild(n); }
    }
    n.innerHTML = html;
  }
  var reasonZh = { timer: '定时', hide: '离页', submit: '保存前', manual: '手工' };
  if (parseInt(CFG.restore, 10) > 0) {
    setText('正在载入快照…');
    fetch(CFG.get + '?id=' + encodeURIComponent(CFG.restore), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) { banner('快照载入失败：' + ((j && j.error) || '未知')); return; }
        var when = j.created_at || '';
        var why = reasonZh[j.reason] || j.reason || '';
        var hasMd = (j.content || '').replace(/\s+/g, '') !== '';
        var hasRich = (j.rich_html || '') !== '';
        if (hasMd) {
          if (richMode()) { toMd(); }
          mdIn.value = j.content;
          try { mdIn.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
          banner('已载入快照（' + when + ' · ' + why + ' · ' + j.plain_chars + ' 字）到 Markdown 编辑器。<b>请核对内容后点「保存」</b>——未保存前库里的正文不变。');
        } else if (hasRich) {
          richIn.innerHTML = j.rich_html;
          if (!richMode()) { toRich(); }
          banner('已载入快照（' + when + ' · ' + why + ' · 富文本原文 · ' + j.plain_chars + ' 字）。<b>请核对内容后点「保存」</b>。');
        } else {
          banner('这条快照没有正文内容（可能只存了标题）。');
        }
        lastSig = sigOf(payload('timer', false));
      })
      .catch(function () { banner('快照载入失败（网络异常）。'); });
  }
})();
