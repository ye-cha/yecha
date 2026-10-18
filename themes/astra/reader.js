/* YeCha 读者端：私有划线高亮 + 选中划线（仅登录用户） */
(function () {
  'use strict';
  var cfg = window.__READER || {};
  if (!cfg.cid) return;
  var T = cfg.t || {};

  var body = document.getElementById('articleBody');
  if (!body) return;

  var COLS = { y: '#ffe08a', g: '#cdeccb', b: '#cfe2ff' };

  function paraIndex(node) {
    var p = node && node.nodeType === 1 ? node : (node ? node.parentElement : null);
    while (p && p !== body && p.tagName !== 'P') p = p.parentElement;
    if (!p || p === body) return 0;
    var list = Array.prototype.slice.call(body.children).filter(function (el) { return el.tagName === 'P'; });
    return list.indexOf(p) + 1;
  }

  function highlight(marks) {
    if (!marks || !marks.length) return;
    var ps = Array.prototype.slice.call(body.querySelectorAll('p'));
    marks.forEach(function (m) {
      if (!m.quote) return;
      for (var i = 0; i < ps.length; i++) {
        var p = ps[i];
        if (p.dataset.hl) continue;
        var idx = p.textContent.indexOf(m.quote);
        if (idx < 0) continue;
        var r = document.createRange();
        r.setStart(p.firstChild, 0);
        // 文本节点遍历定位（简化：仅在单文本子节点段落内替换）
        var walker = document.createTreeWalker(p, NodeFilter.SHOW_TEXT);
        var node, offset = 0, target = null;
        while ((node = walker.nextNode())) {
          var len = node.length;
          if (offset + len >= idx + m.quote.length) {
            var inner = idx - offset;
            var range = document.createRange();
            range.setStart(node, inner);
            range.setEnd(node, inner + m.quote.length);
            var mark = document.createElement('mark');
            mark.style.background = m.color || COLS.y;
            mark.title = T.mt || '';
            try { range.surroundContents(mark); } catch (err) {}
            p.dataset.hl = '1';
            return;
          }
          offset += len;
        }
      }
    });
  }

  function loadMarks() {
    if (!cfg.logged) return;
    fetch('/user/api/marks?content_id=' + cfg.cid)
      .then(function (r) { return r.json(); })
      .then(function (d) { highlight(d.marks); })
      .catch(function () {});
  }

  /* 选中文字 → 划线菜单 */
  var menu = null;
  function ensureMenu() {
    if (menu) return menu;
    menu = document.createElement('div');
    menu.style.cssText = 'position:absolute;z-index:99;background:#fff;border:1px solid #e0e0da;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.12);padding:6px;display:flex;gap:6px;';
    Object.keys(COLS).forEach(function (k) {
      var b = document.createElement('button');
      b.type = 'button';
      b.style.cssText = 'width:20px;height:20px;border-radius:50%;border:1px solid rgba(0,0,0,.15);cursor:pointer;background:' + COLS[k] + ';';
      b.title = T.tip || '';
      b.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
      b.addEventListener('click', function () { doMark(COLS[k]); });
      menu.appendChild(b);
    });
    var note = document.createElement('span');
    note.textContent = T.add || '+注';
    note.style.cssText = 'font-size:12px;color:#b3541e;cursor:pointer;align-self:center;padding:0 4px;';
    note.title = T.noteTip || '';
    note.addEventListener('click', function () { doMark(COLS.y, true); });
    menu.appendChild(note);
    document.body.appendChild(menu);
    return menu;
  }

  function doMark(color, withNote) {
    var sel = window.getSelection();
    if (!sel || sel.isCollapsed || !sel.rangeCount) return;
    var txt = sel.toString().trim();
    if (txt.length < 1 || txt.length > 200) return;
    var pIdx = paraIndex(sel.anchorNode);
    var anchor = String(pIdx);
    var note = '';
    if (withNote) { note = window.prompt(T.prompt || 'Write a note (private):', '') || ''; }
    var fd = new FormData();
    fd.append('content_id', cfg.cid);
    fd.append('anchor_ref', anchor);
    fd.append('quote', txt);
    fd.append('color', color);
    fd.append('note', note);
    fd.append('_csrf', cfg.csrf);
    fd.append('redirect', window.location.pathname);
    fetch('/user/mark', { method: 'POST', body: fd })
      .then(function () {
        sel.removeAllRanges();
        if (menu) { menu.remove(); menu = null; }
        loadMarks();
        if (note) { alert(T.saved || 'Saved highlight + note.'); }
      })
      .catch(function () {});
  }

  body.addEventListener('mouseup', function (e) {
    if (!cfg.logged) return;
    var sel = window.getSelection();
    if (sel && !sel.isCollapsed && sel.toString().trim().length >= 2) {
      var m = ensureMenu();
      m.style.left = (e.clientX + 10) + 'px';
      m.style.top = (e.clientY + 10) + 'px';
      m.style.display = 'flex';
    } else if (menu) {
      menu.style.display = 'none';
    }
  });

  document.addEventListener('scroll', function () { if (menu) menu.style.display = 'none'; });
  loadMarks();
})();
