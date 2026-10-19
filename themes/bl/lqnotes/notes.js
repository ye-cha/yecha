/* =============================================================================
 * lqnotes · 云笔记前台（零依赖 · 零外发 · 本机为主 · 登录后本站 DB 云同步）
 * -----------------------------------------------------------------------------
 * 存储两段：
 *   ① 本机：localStorage（键名沿用参照站的 llq_md_* 口径，便于将来互换）
 *   ② 云端：本站自己的 /api/notes（session 鉴权 + 站内 Csrf 令牌）——**不接任何第三方**
 * 六项功能：新建/编辑/保存 · 笔记本分类 · 回收站 · 本机自动保存 · 登录云同步 · 记事本数据迁入
 * 分享：POST /api/notes{op:share} ⇒ 生成 /share/{token}（服务端渲染，未登录可读）
 * 全部可测钩子：元素带 data-lqn="…"
 * ========================================================================== */
(function () {
  'use strict';

  var K = {
    notes: 'llq_md_notes_v1',
    books: 'llq_md_notebooks_v1',
    active: 'llq_md_active',
    filter: 'llq_md_filter_v1',
    mode: 'llq_md_mode_v1',
    dark: 'llq_md_dark',            // ★ 与参照同名（参照写 llq_md_dark=1）
    siteDark: 'yecha-dark',         // ★ 站点级深浅色的键（themes/bl/views/layout.php:262）——两个都写，保持同步
    legacy: 'llq_notepad_v1',
    legacyFolders: 'llq_notepad_folders_v1',
    mig: 'llq_md_migrated_notepad_v1',
    migCloud: 'llq_md_migrated_notepad_cloud_v1',
    hist: 'llq_md_history_v1'       // ★ 批4：历史版本（与参照同名同语义）
  };
  var ALL = '__all__', TRASH = '__trash__', MAXBODY = 262144;

  /* ★ 参照 bundle 里的「欢迎使用云笔记」正文（逐字取自 index-BGp4WVFT.js） */
  var WELCOME = {
    title: '欢迎使用云笔记',
    html: '<h1>欢迎使用云笔记</h1><p>这是你的第一篇笔记。</p>'
        + '<p><strong>像 Word 一样打字即可排版</strong>：选中文字加粗、插入表格、勾选任务……</p>'
        + '<p>内容自动保存在本机，登录后自动上云（本地+云端双备份）。</p>',
    book: '默认'
  };
  /* ★ 参照 bundle 里的模板清单（6 条，id/name/html 逐字照抄、顺序照抄） */
  var TEMPLATES = [
    { id: 'blank', name: '空白笔记', html: '' },
    { id: 'diary', name: '📔 日记', html: '<h1>今日记录</h1><p><strong>日期：</strong></p><p><strong>天气：</strong></p><h2>今天做了什么</h2><p></p><h2>开心的事</h2><p></p><h2>明天计划</h2><ul><li></li><li></li></ul>' },
    { id: 'weekly', name: '📊 周报', html: '<h1>周报</h1><h2>本周完成</h2><ul><li></li><li></li></ul><h2>下周计划</h2><ul><li></li><li></li></ul><h2>问题与求助</h2><ul><li></li></ul>' },
    { id: 'meeting', name: '📋 会议纪要', html: '<h1>会议纪要</h1><p><strong>时间：</strong></p><p><strong>参会人：</strong></p><h2>议题</h2><ol><li></li></ol><h2>决议</h2><ul><li></li></ul><h2>待办</h2><ul><li></li></ul>' },
    { id: 'tea', name: '🍵 试茶记录', html: '<h1>今日试茶</h1><p><strong>茶品：</strong></p><p><strong>投茶量：</strong></p><p><strong>水温 / 出汤：</strong></p><h2>香气</h2><p></p><h2>滋味</h2><p></p><h2>回甘</h2><p></p>' },
    { id: 'todo', name: '☑ 待办清单', html: '<h1>待办清单</h1><ul><li>任务一</li><li>任务二</li><li>任务三</li></ul>' }
  ];

  var S = {
    notes: [], books: [], active: '', filter: ALL, mode: 'html', dark: false,
    user: { uid: 0, name: '' }, csrf: '', loaded: false,
    saveTimer: null, netTimer: null, notice: '',
    encLock: false, encOk: {}, encTried: {},
    undo: [], redo: [], lastPush: 0, shTab: 'link', revs: [], revSrv: [],
    /* ★ t30：搜索词 + 保存按钮三态（idle/ready/saving/saved/error） */
    q: '', saveState: 'idle', savedAt: ''
  };

  /* ---------------------------------------------------------------- 工具 */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function ck() { return Date.now().toString(36) + Math.random().toString(36).slice(2, 8); }
  function nowStr() {
    var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }
  function jget(k, d) { try { var v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } }
  function jset(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); return true; } catch (e) { return false; } }
  function trim(s, n) { s = String(s == null ? '' : s); return s.length > n ? s.slice(0, n) : s; }
  function toast(msg, ms) {
    var t = $('[data-lqn="toast"]');
    if (!t) { return; }
    t.textContent = msg;
    t.setAttribute('data-lqn-on', '1');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { t.removeAttribute('data-lqn-on'); }, ms || 2600);
  }

  /* ---------------------------------------------------------------- Markdown（受限子集，和分享页同一套规则） */
  function mdInline(s) {
    s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
    s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/(^|[^*])\*([^*]+)\*(?!\*)/g, '$1<em>$2</em>');
    s = s.replace(/~~([^~]+)~~/g, '<s>$1</s>');
    s = s.replace(/==([^=]+)==/g, '<mark>$1</mark>');
    s = s.replace(/!\[([^\]]{0,120})\]\((https?:\/\/[^\s)]{1,500})\)/g,
      function (m, alt, src) { return '<img src="' + esc(src) + '" alt="' + esc(alt) + '" loading="lazy">'; });
    s = s.replace(/\[([^\]]{1,120})\]\((https?:\/\/[^\s)]{1,500}|mailto:[^\s)]{1,200})\)/g,
      function (m, txt, href) { return '<a href="' + esc(href) + '" rel="noopener nofollow" target="_blank">' + txt + '</a>'; });
    return s;
  }
  function mdToHtml(md) {
    var e = esc(md), lines = e.split(/\r\n|\r|\n/), out = [], ul = false, ol = false, q = false, code = false, buf = [], task = false;
    function cl() {
      if (ul) { out.push('</ul>'); ul = false; }
      if (task) { out.push('</ul>'); task = false; }
      if (ol) { out.push('</ol>'); ol = false; }
    }
    function cq() { if (q) { out.push('</blockquote>'); q = false; } }
    for (var i = 0; i < lines.length; i++) {
      var t = lines[i], m;
      if (/^```/.test(t)) {
        if (code) { out.push('<pre><code>' + buf.join('\n') + '</code></pre>'); buf = []; code = false; }
        else { cl(); cq(); code = true; }
        continue;
      }
      if (code) { buf.push(t); continue; }
      if (!t.trim()) { cl(); cq(); continue; }
      if ((m = /^(#{1,4})\s+(.*)$/.exec(t))) { cl(); cq(); out.push('<h' + m[1].length + '>' + mdInline(m[2]) + '</h' + m[1].length + '>'); continue; }
      // ★ GFM 表格块（| a | b | + | --- | --- |）
      if (/^\s*\|.*\|\s*$/.test(t)) {
        var rows = [], j = i;
        while (j < lines.length && /^\s*\|.*\|\s*$/.test(lines[j])) { rows.push(lines[j]); j++; }
        if (rows.length >= 2 && /^\s*\|[\s:|-]+\|\s*$/.test(rows[1])) {
          cl(); cq();
          var cells = function (row, tag) {
            return row.replace(/^\s*\|/, '').replace(/\|\s*$/, '').split('|')
              .map(function (c) { return '<' + tag + '>' + mdInline(c.trim()) + '</' + tag + '>'; }).join('');
          };
          out.push('<table><thead><tr>' + cells(rows[0], 'th') + '</tr></thead><tbody>');
          for (var k = 2; k < rows.length; k++) { out.push('<tr>' + cells(rows[k], 'td') + '</tr>'); }
          out.push('</tbody></table>');
          i = j - 1; continue;
        }
      }
      if ((m = /^\s*[-*+]\s+(.*)$/.exec(t))) {
        cq();
        var li = m[1], tm = /^\[([ xX])\]\s*(.*)$/.exec(li);
        if (tm) {
          if (!task) { cl(); out.push('<ul class="lqn-task">'); task = true; }
          out.push('<li>' + (tm[1].toLowerCase() === 'x' ? '☑ ' : '☐ ') + mdInline(tm[2]) + '</li>');
          continue;
        }
        if (task) { out.push('</ul>'); task = false; }
        if (!ul) { out.push('<ul>'); ul = true; }
        out.push('<li>' + mdInline(m[1]) + '</li>');
        continue;
      }
      if ((m = /^\s*\d+[.)]\s+(.*)$/.exec(t))) { cq(); if (!ol) { cl(); out.push('<ol>'); ol = true; } out.push('<li>' + mdInline(m[1]) + '</li>'); continue; }
      if ((m = /^&gt;\s?(.*)$/.exec(t))) { cl(); if (!q) { out.push('<blockquote>'); q = true; } out.push('<p>' + mdInline(m[1]) + '</p>'); continue; }
      if (/^(-{3,}|\*{3,})$/.test(t.trim())) { cl(); cq(); out.push('<hr>'); continue; }
      cl(); cq(); out.push('<p>' + mdInline(t) + '</p>');
    }
    if (code && buf.length) { out.push('<pre><code>' + buf.join('\n') + '</code></pre>'); }
    cl(); cq();
    return out.join('\n');
  }
  /* ★ 表格 → GFM（供「查看源码」往返） */
  function tableToMd(tb) {
    var trs = Array.prototype.slice.call(tb.querySelectorAll('tr')), rows = [];
    trs.forEach(function (tr) {
      var cells = Array.prototype.slice.call(tr.children).map(function (td) { return String(td.textContent || '').trim(); });
      if (cells.length) { rows.push('| ' + cells.join(' | ') + ' |'); }
    });
    if (!rows.length) { return ''; }
    var n0 = trs[0].children.length || 1, sep = '| ' + (new Array(n0 + 1)).join('--- | ');
    return [rows[0], sep].concat(rows.slice(1)).join('\n');
  }
  function htmlToMd(html) {
    var d = document.createElement('div');
    d.innerHTML = sanitizeHtml(html);
    function walk(n, depth) {      var out = '';
      for (var i = 0; i < n.childNodes.length; i++) {
        var c = n.childNodes[i];
        if (c.nodeType === 3) { out += c.nodeValue.replace(/\s+/g, ' '); continue; }
        if (c.nodeType !== 1) { continue; }
        var tag = c.tagName.toLowerCase(), inner = walk(c, depth);
        if (/^h[1-6]$/.test(tag)) { out += '\n' + new Array(parseInt(tag[1], 10) + 1).join('#') + ' ' + inner.trim() + '\n'; }
        else if (tag === 'p' || tag === 'div' || tag === 'section') { out += '\n' + inner.trim() + '\n'; }
        else if (tag === 'br') { out += '\n'; }
        else if (tag === 'strong' || tag === 'b') { out += '**' + inner + '**'; }
        else if (tag === 'em' || tag === 'i') { out += '*' + inner + '*'; }
        else if (tag === 's' || tag === 'del' || tag === 'strike') { out += '~~' + inner + '~~'; }
        else if (tag === 'mark') { out += '==' + inner + '=='; }
        else if (tag === 'img') { out += '![' + (c.getAttribute('alt') || '') + '](' + (c.getAttribute('src') || '') + ')'; }
        else if (tag === 'table') { out += '\n' + tableToMd(c) + '\n'; }
        else if (tag === 'code') { out += '`' + inner + '`'; }
        else if (tag === 'pre') { out += '\n```\n' + (c.textContent || '') + '\n```\n'; }
        else if (tag === 'blockquote') { out += '\n> ' + inner.trim().replace(/\n/g, '\n> ') + '\n'; }
        else if (tag === 'ul' || tag === 'ol') { out += '\n' + inner; }
        else if (tag === 'li') {
          var mk = n.tagName.toLowerCase() === 'ol' ? '1. ' : '- ', txt = inner.trim();
          if (/^☐\s*/.test(txt)) { mk = '- [ ] '; txt = txt.replace(/^☐\s*/, ''); }
          else if (/^☑\s*/.test(txt)) { mk = '- [x] '; txt = txt.replace(/^☑\s*/, ''); }
          out += mk + txt + '\n';
        }
        else if (tag === 'a') { var h = c.getAttribute('href') || ''; out += /^(https?:|mailto:)/i.test(h) ? '[' + inner + '](' + h + ')' : inner; }
        else if (tag === 'hr') { out += '\n---\n'; }
        else { out += inner; }
      }
      return out;
    }
    return walk(d, 0).replace(/\n{3,}/g, '\n\n').replace(/[ \t]+\n/g, '\n').trim();
  }
  /* 白名单消毒：把任何存进 DB 的 HTML 在**加载进所见即所得之前**先洗一遍（防自伤 XSS） */
  function sanitizeHtml(html) {
    var OK = { P: 1, DIV: 1, BR: 1, STRONG: 1, B: 1, EM: 1, I: 1, U: 1, S: 1, DEL: 1, STRIKE: 1, MARK: 1,
      CODE: 1, PRE: 1, BLOCKQUOTE: 1,
      UL: 1, OL: 1, LI: 1, H1: 1, H2: 1, H3: 1, H4: 1, H5: 1, H6: 1, A: 1, HR: 1, SPAN: 1, TABLE: 1,
      THEAD: 1, TBODY: 1, TFOOT: 1, TR: 1, TD: 1, TH: 1, IMG: 1 };
    var d = document.createElement('div');
    d.innerHTML = String(html || '');
    (function clean(node) {
      var kids = Array.prototype.slice.call(node.childNodes);
      for (var i = 0; i < kids.length; i++) {
        var c = kids[i];
        if (c.nodeType === 8) { node.removeChild(c); continue; }
        if (c.nodeType !== 1) { continue; }
        var tag = c.tagName.toUpperCase();
        if (!OK[tag]) { // 危险/未知标签：只保留其文本
          var txt = document.createTextNode(c.textContent || '');
          node.replaceChild(txt, c);
          continue;
        }
        var attrs = Array.prototype.slice.call(c.attributes);
        for (var a = 0; a < attrs.length; a++) {
          var nm = attrs[a].name.toLowerCase(), val = String(attrs[a].value || '');
          if (nm === 'href') { if (!/^(https?:|mailto:|\/|#)/i.test(val)) { c.removeAttribute(attrs[a].name); } continue; }
          if (nm === 'src' && tag === 'IMG') { if (!/^(https?:|\/|data:image\/)/i.test(val)) { c.removeAttribute(attrs[a].name); } continue; }
          if (nm === 'alt' && tag === 'IMG') { c.setAttribute('alt', val.slice(0, 200)); continue; }
          // ★ class 放行但限字符集与长度（on* / style / 事件属性一律照旧去掉）
          if (nm === 'class') {
            var cv = val.replace(/[^A-Za-z0-9_\- ]/g, '').slice(0, 80).trim();
            if (cv) { c.setAttribute('class', cv); } else { c.removeAttribute(attrs[a].name); }
            continue;
          }
          c.removeAttribute(attrs[a].name); // 其余属性（含全部 on*）一律去掉
        }
        if (tag === 'A') { c.setAttribute('rel', 'noopener nofollow'); c.setAttribute('target', '_blank'); }
        clean(c);
      }
    })(d);
    return d.innerHTML;
  }
  function plain(s, n) {
    var t = String(s || '')
      .replace(/<[^>]*>/g, ' ')            // 若是 HTML 正文：先去标签
      .replace(/```[\s\S]*?```/g, ' ')
      .replace(/!\[[^\]]*\]\([^)]*\)/g, ' [图片] ')
      .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
      .replace(/[#>*`~|-]/g, ' ')
      .replace(/\s+/g, ' ').trim();
    return t ? t.slice(0, n || 48) : '空白笔记';
  }

  /* ================================================================================
   * 二维码（自研 · 字节模式 · EC 级别 L · 版本 1–10）
   * --------------------------------------------------------------------------------
   * 为什么不引库：站主要求【二维码必须本地生成、不许引第三方】，且本站没有任何二维码库。
   * 只做 URL 需要的字节模式（ASCII/UTF-8）与 EC L；容量：v3=53B / v5=106B / v9=230B。
   * ★ 独立性：验收探针用【结构 + 格式信息 BCH + RS 伴随式全零 + 数据回读】四条独立判据复核，
   *   不是"自己说自己对"。
   * ============================================================================== */
  var QR = (function () {
    var EXP = new Array(512), LOG = new Array(256);
    (function () {
      var x = 1, i;
      for (i = 0; i < 255; i++) { EXP[i] = x; LOG[x] = i; x = (x << 1); if (x & 0x100) { x ^= 0x11D; } }
      for (i = 255; i < 512; i++) { EXP[i] = EXP[i - 255]; }
    })();
    function gmul(a, b) { return (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]]; }
    function rsGen(n) {
      var g = [1], i, j, ng;
      for (i = 0; i < n; i++) {
        ng = [];
        for (j = 0; j < g.length + 1; j++) { ng[j] = 0; }
        for (j = 0; j < g.length; j++) { ng[j] ^= g[j]; ng[j + 1] ^= gmul(g[j], EXP[i]); }
        g = ng;
      }
      return g;
    }
    function rsEncode(data, ecLen) {
      var gen = rsGen(ecLen), res = data.slice(), i, j;
      for (i = 0; i < ecLen; i++) { res.push(0); }
      for (i = 0; i < data.length; i++) {
        var c = res[i];
        if (c !== 0) { for (j = 0; j < gen.length; j++) { res[i + j] ^= gmul(gen[j], c); } }
      }
      return res.slice(data.length);
    }
    /* [totalCw, ecPerBlock, g1Blocks, g1Data, g2Blocks, g2Data, alignCenters]（EC L） */
    var VT = {
      1: [26, 7, 1, 19, 0, 0, []],
      2: [44, 10, 1, 34, 0, 0, [6, 18]],
      3: [70, 15, 1, 55, 0, 0, [6, 22]],
      4: [100, 20, 1, 80, 0, 0, [6, 26]],
      5: [134, 26, 1, 108, 0, 0, [6, 30]],
      6: [172, 18, 2, 68, 0, 0, [6, 34]],
      7: [196, 20, 2, 78, 0, 0, [6, 22, 38]],
      8: [242, 24, 2, 97, 0, 0, [6, 24, 42]],
      9: [292, 30, 2, 116, 0, 0, [6, 26, 46]],
      10: [346, 18, 2, 68, 2, 69, [6, 28, 50]]
    };
    var bestMask = 0;
    function dataCw(v) { var t = VT[v]; return t[2] * t[3] + t[4] * t[5]; }
    function sizeOf(v) { return v * 4 + 17; }
    function utf8Bytes(s) {
      var out = [], i, c;
      for (i = 0; i < s.length; i++) {
        c = s.charCodeAt(i);
        if (c < 0x80) { out.push(c); }
        else if (c < 0x800) { out.push(0xC0 | (c >> 6), 0x80 | (c & 63)); }
        else { out.push(0xE0 | (c >> 12), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63)); }
      }
      return out;
    }
    function pickVersion(bytes) {
      var v;
      for (v = 1; v <= 10; v++) {
        if (dataCw(v) * 8 >= 4 + (v <= 9 ? 8 : 16) + bytes.length * 8) { return v; }
      }
      return 0;
    }
    function makeCodewords(bytes, v) {
      var bits = [], i, j;
      function put(val, n) { for (var k = n - 1; k >= 0; k--) { bits.push((val >> k) & 1); } }
      put(4, 4);                                        // 字节模式
      put(bytes.length, v <= 9 ? 8 : 16);
      for (i = 0; i < bytes.length; i++) { put(bytes[i], 8); }
      var cap = dataCw(v) * 8;
      for (i = 0; i < 4 && bits.length < cap; i++) { bits.push(0); }
      while (bits.length % 8 !== 0) { bits.push(0); }
      var cw = [];
      for (i = 0; i < bits.length; i += 8) {
        var b = 0;
        for (j = 0; j < 8; j++) { b = (b << 1) | bits[i + j]; }
        cw.push(b);
      }
      var pads = [0xEC, 0x11], p = 0;
      while (cw.length < dataCw(v)) { cw.push(pads[p++ % 2]); }
      return cw;
    }
    function interleave(cw, v) {
      var t = VT[v], ecLen = t[1], g1 = t[2], d1 = t[3], g2 = t[4], d2 = t[5];
      var blocks = [], pos = 0, i, j, out = [];
      for (i = 0; i < g1; i++) { blocks.push({ d: cw.slice(pos, pos + d1), e: null }); pos += d1; }
      for (i = 0; i < g2; i++) { blocks.push({ d: cw.slice(pos, pos + d2), e: null }); pos += d2; }
      for (i = 0; i < blocks.length; i++) { blocks[i].e = rsEncode(blocks[i].d, ecLen); }
      var maxD = Math.max(d1, d2);
      for (i = 0; i < maxD; i++) { for (j = 0; j < blocks.length; j++) { if (i < blocks[j].d.length) { out.push(blocks[j].d[i]); } } }
      for (i = 0; i < ecLen; i++) { for (j = 0; j < blocks.length; j++) { out.push(blocks[j].e[i]); } }
      return out;
    }
    function bchFormat(fmt5) {                          // 15 位 = 5 数据 + 10 BCH，再异或 0x5412
      var d = fmt5 << 10, g = 0x537, i;
      for (i = 14; i >= 10; i--) { if ((d >> i) & 1) { d ^= g << (i - 10); } }
      return ((fmt5 << 10) | d) ^ 0x5412;
    }
    function bchVersion(v) {                            // 18 位 = 6 数据 + 12 BCH
      var d = v << 12, g = 0x1F25, i;
      for (i = 17; i >= 12; i--) { if ((d >> i) & 1) { d ^= g << (i - 12); } }
      return (v << 12) | d;
    }
    var MASK = [
      function (r, c) { return (r + c) % 2 === 0; },
      function (r) { return r % 2 === 0; },
      function (r, c) { return c % 3 === 0; },
      function (r, c) { return (r + c) % 3 === 0; },
      /* ★ 掩膜 4 的三个取整方向不对称（col/2 + row/3），写反就是转置 ⇒ 复核器抓过一次 */
      function (r, c) { return (Math.floor(c / 2) + Math.floor(r / 3)) % 2 === 0; },
      function (r, c) { return ((r * c) % 2) + ((r * c) % 3) === 0; },
      function (r, c) { return (((r * c) % 2) + ((r * c) % 3)) % 2 === 0; },
      function (r, c) { return (((r + c) % 2) + ((r * c) % 3)) % 2 === 0; }
    ];
    function build(v) {
      var n = sizeOf(v), m = [], fn = [], r, c, i, j;
      for (r = 0; r < n; r++) { m.push(new Array(n).fill(0)); fn.push(new Array(n).fill(false)); }
      function setF(rr, cc, val) { if (rr >= 0 && cc >= 0 && rr < n && cc < n) { m[rr][cc] = val; fn[rr][cc] = true; } }
      function finder(r0, c0) {
        for (var dr = -1; dr <= 7; dr++) {
          for (var dc = -1; dc <= 7; dc++) {
            var rr = r0 + dr, cc = c0 + dc;
            if (rr < 0 || cc < 0 || rr >= n || cc >= n) { continue; }
            var on = (dr >= 0 && dr <= 6 && (dc === 0 || dc === 6))
              || (dc >= 0 && dc <= 6 && (dr === 0 || dr === 6))
              || (dr >= 2 && dr <= 4 && dc >= 2 && dc <= 4);
            setF(rr, cc, on ? 1 : 0);
          }
        }
      }
      finder(0, 0); finder(0, n - 7); finder(n - 7, 0);
      for (i = 8; i < n - 8; i++) { setF(6, i, i % 2 === 0 ? 1 : 0); setF(i, 6, i % 2 === 0 ? 1 : 0); }
      var ac = VT[v][6];
      for (i = 0; i < ac.length; i++) {
        for (j = 0; j < ac.length; j++) {
          var ar = ac[i], acc = ac[j];
          if (fn[ar][acc]) { continue; }                 // 与定位图形重叠 ⇒ 跳过
          for (r = -2; r <= 2; r++) {
            for (c = -2; c <= 2; c++) {
              setF(ar + r, acc + c, (Math.abs(r) === 2 || Math.abs(c) === 2 || (r === 0 && c === 0)) ? 1 : 0);
            }
          }
        }
      }
      // 格式信息预留（★ 必须跳过第 6 行/列 —— 那是时序图形，不能被清零）
      for (i = 0; i <= 8; i++) { if (i !== 6) { setF(8, i, 0); setF(i, 8, 0); } }
      for (i = 0; i < 8; i++) { setF(8, n - 1 - i, 0); }     // 副本 2 的行 8 段
      for (i = 0; i < 7; i++) { setF(n - 1 - i, 8, 0); }     // 副本 2 的列 8 段
      setF(n - 8, 8, 1);                                     // 固定黑模块
      if (v >= 7) {
        for (i = 0; i < 18; i++) {
          var b = (bchVersion(v) >> i) & 1;
          setF(Math.floor(i / 3), n - 11 + (i % 3), b);
          setF(n - 11 + (i % 3), Math.floor(i / 3), b);
        }
      }
      return { m: m, fn: fn, n: n };
    }
    function placeData(g, cw) {
      var n = g.n, m = g.m, fn = g.fn, bit = 0, total = cw.length * 8, up = true, col, k, s, r, c;
      function bitAt(x) { return (cw[x >> 3] >> (7 - (x & 7))) & 1; }
      for (col = n - 1; col > 0; col -= 2) {
        if (col === 6) { col = 5; }
        for (k = 0; k < n; k++) {
          r = up ? (n - 1 - k) : k;
          for (s = 0; s < 2; s++) {
            c = col - s;
            if (fn[r][c]) { continue; }
            m[r][c] = (bit < total) ? bitAt(bit) : 0;
            bit++;
          }
        }
        up = !up;
      }
    }
    function applyMask(g, mask, fmt5) {
      var n = g.n, r, c, i;
      for (r = 0; r < n; r++) { for (c = 0; c < n; c++) { if (!g.fn[r][c] && MASK[mask](r, c)) { g.m[r][c] ^= 1; } } }
      var f = bchFormat(fmt5);
      /* ★ 副本 1 的走向：bit0..5 沿【列 8 往下】，bit6→(7,8)，bit7→(8,8)，bit8→(8,7)，bit9..14 沿【行 8 往左】
         （写成转置过一次 ⇒ 复核器的 BCH 判据当场判 false） */
      for (i = 0; i <= 5; i++) { g.m[i][8] = (f >> i) & 1; }
      g.m[7][8] = (f >> 6) & 1; g.m[8][8] = (f >> 7) & 1; g.m[8][7] = (f >> 8) & 1;
      for (i = 9; i <= 14; i++) { g.m[8][14 - i] = (f >> i) & 1; }
      /* ★ 副本 2：bit0..7 → 【行 8，列 n-1..n-8】；bit8..14 → 【列 8，行 n-7..n-1】
         （与副本 1 一样写成转置过一次 ⇒ 复核器用"编码器自己的 fn 图"提取时伴随式才归零，暴露了这处） */
      for (i = 0; i <= 7; i++) { g.m[8][n - 1 - i] = (f >> i) & 1; }
      for (i = 8; i <= 14; i++) { g.m[n - 15 + i][8] = (f >> i) & 1; }
      g.m[n - 8][8] = 1;
    }
    function penalty(g) {
      var n = g.n, m = g.m, p = 0, r, c, i, dark = 0;
      function runPenalty(line) {
        var s = 0, cnt = 1, k;
        for (k = 1; k < line.length; k++) {
          if (line[k] === line[k - 1]) { cnt++; } else { if (cnt >= 5) { s += 3 + (cnt - 5); } cnt = 1; }
        }
        if (cnt >= 5) { s += 3 + (cnt - 5); }
        return s;
      }
      var pat = [1, 0, 1, 1, 1, 0, 1];
      function finderLike(line) {
        var s = 0, k, t, before, after;
        for (k = 0; k + 7 <= line.length; k++) {
          var ok = true;
          for (t = 0; t < 7; t++) { if (line[k + t] !== pat[t]) { ok = false; break; } }
          if (!ok) { continue; }
          before = true; after = true;
          for (t = 1; t <= 4; t++) { if (k - t >= 0 && line[k - t] !== 0) { before = false; break; } }
          for (t = 7; t < 11; t++) { if (k + t < line.length && line[k + t] !== 0) { after = false; break; } }
          if (before && after) { s += 40; }
        }
        return s;
      }
      for (r = 0; r < n; r++) { p += runPenalty(m[r]); p += finderLike(m[r]); }
      for (c = 0; c < n; c++) {
        var col = [];
        for (r = 0; r < n; r++) { col.push(m[r][c]); }
        p += runPenalty(col); p += finderLike(col);
      }
      for (r = 0; r < n - 1; r++) {
        for (c = 0; c < n - 1; c++) {
          var v = m[r][c];
          if (v === m[r][c + 1] && v === m[r + 1][c] && v === m[r + 1][c + 1]) { p += 3; }
        }
      }
      for (r = 0; r < n; r++) { for (c = 0; c < n; c++) { if (m[r][c]) { dark++; } } }
      p += Math.floor(Math.abs(dark * 100 / (n * n) - 50) / 5) * 10;
      return p;
    }
    /** 主入口：{ size, modules[r][c], version, mask }；超容量返回 null */
    function matrix(text) {
      var bytes = utf8Bytes(String(text == null ? '' : text));
      var v = pickVersion(bytes);
      if (!v) { return null; }
      var cw = interleave(makeCodewords(bytes, v), v);
      var best = null, bestP = Infinity, mask;
      for (mask = 0; mask < 8; mask++) {
        var g = build(v);
        placeData(g, cw);
        applyMask(g, mask, (1 << 3) | mask);            // EC L = 01
        var p = penalty(g);
        if (p < bestP) { bestP = p; best = g; bestMask = mask; }
      }
      return { size: best.n, modules: best.m, fn: best.fn, version: v, mask: bestMask };
    }
    /** SVG（零外发、可打印、不依赖 canvas） */
    function svg(text) {
      var q = matrix(text);
      if (!q) { return ''; }
      var n = q.size, s = 4, d = [], r, c, run;
      for (r = 0; r < n; r++) {
        for (c = 0; c < n; c++) {
          if (!q.modules[r][c]) { continue; }
          run = 1;
          while (c + run < n && q.modules[r][c + run]) { run++; }
          d.push('M' + (c * s) + ' ' + (r * s) + 'h' + (run * s) + 'v' + s + 'h-' + (run * s) + 'z');
          c += run - 1;
        }
      }
      return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + (n * s) + ' ' + (n * s) + '" '
        + 'width="100%" height="100%" shape-rendering="crispEdges" role="img" aria-label="分享链接二维码">'
        + '<rect width="100%" height="100%" fill="#fff"/><path d="' + d.join('') + '" fill="#000"/></svg>';
    }
    return { matrix: matrix, svg: svg, _VT: VT, _rsGen: rsGen, _rsEncode: rsEncode, _bchFormat: bchFormat, _bytes: utf8Bytes };
  })();

  /* ---------------------------------------------------------------- 状态读写 */
  function persist() {
    if (S.loaded) { S.savedAt = hhmmss(); }   // ★ 参照底部条第二段：已自动保存 HH:MM:SS
    jset(K.notes, S.notes.map(function (n) {
      /* ★ 批9：加密笔记在本机也存【信封串】（而不是明文）——本机持久化不带明文，更贴近"云端只存密文"的口径 */
      var body = (n.enc && n.env) ? n.env : n.body;
      return { ck: n.ck, sid: n.sid || 0, title: n.title, body: body, fmt: n.fmt,
        book: n.book || '', status: n.status || 'normal', demo: n.demo ? 1 : 0,
        dirty: !!n.dirty, su: n.su || '', updated: n.updated || 0 };
    }));
  }
  function persistBooks() {
    jset(K.books, S.books.map(function (b) { return { id: b.id || 0, name: b.name }; }));
  }
  function loadLocal() {
    var ns = jget(K.notes, []), bs = jget(K.books, []);
    S.notes = (Array.isArray(ns) ? ns : []).map(function (n) {
      return { ck: n.ck || ck(), sid: n.sid || 0, title: n.title || '', body: n.body || '',
        fmt: n.fmt === 'html' ? 'html' : 'md', book: n.book || '', status: n.status === 'trash' ? 'trash' : 'normal',
        demo: n.demo ? 1 : 0,
        dirty: !!n.dirty, su: n.su || '', updated: n.updated || Date.now() };
    });
    S.books = (Array.isArray(bs) ? bs : []).map(function (b) {
      if (typeof b === 'string') { return { id: 0, name: b }; }
      return { id: b.id || 0, name: b.name || '' };
    }).filter(function (b) { return b.name; });
    if (!S.books.length) { S.books = [{ id: 0, name: '默认' }]; }
    S.active = localStorage.getItem(K.active) || '';
    S.filter = localStorage.getItem(K.filter) || ALL;
    // ★ 参照的默认视图是所见即所得 ⇒ 没存过时默认 html（与参照一致）
    S.mode = localStorage.getItem(K.mode) === 'md' ? 'md' : 'html';
    // ★ 暗色：llq_md_dark（参照键）优先，其次站点键 yecha-dark，再其次系统偏好
    var dk = localStorage.getItem(K.dark);
    if (dk === null) { dk = localStorage.getItem(K.siteDark); }
    S.dark = (dk === null)
      ? !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
      : (dk === '1' || dk === 'dark' || dk === 'true');
  }
  function noteByCk(c) { for (var i = 0; i < S.notes.length; i++) { if (S.notes[i].ck === c) { return S.notes[i]; } } return null; }
  function active() { return noteByCk(S.active); }
  function touch(n) { n.updated = Date.now(); n.dirty = true; }
  function unsortedFilter() {
    var arr = unsortedFilterRaw();
    var q = String(S.q || '').trim().toLowerCase();
    if (!q) { return arr; }
    return arr.filter(function (n) {
      return (String(n.title || '') + ' ' + String(n.body || '')).toLowerCase().indexOf(q) >= 0;
    });
  }
  function unsortedFilterRaw() {
    return S.filter === TRASH
      ? S.notes.filter(function (n) { return n.status === 'trash'; })
      : (S.filter === ALL
        ? S.notes.filter(function (n) { return n.status !== 'trash'; })
        : S.notes.filter(function (n) { return n.status !== 'trash' && (n.book || '默认') === S.filter; }));
  }
  function listSorted() {
    return unsortedFilter().slice().sort(function (a, b) { return (b.updated || 0) - (a.updated || 0); });
  }

  /* ---------------------------------------------------------------- 渲染 */
  function render() {
    // 侧栏计数
    var live = S.notes.filter(function (n) { return n.status !== 'trash'; });
    var setTxt = function (sel, v) { var el = $(sel); if (el) { el.textContent = String(v); } };
    setTxt('[data-lqn="count-all"]', live.length);
    setTxt('[data-lqn="count-trash"]', S.notes.filter(function (n) { return n.status === 'trash'; }).length);
    $$('[data-lqn="book"]').forEach(function (el) { el.remove(); });
    var nav = $('[data-lqn="books"]');
    if (nav) {
      S.books.forEach(function (b) {
        var cnt = live.filter(function (n) { return (n.book || '默认') === b.name; }).length;
        var row = document.createElement('div');
        row.className = 'flex items-center gap-1';
        row.setAttribute('data-lqn', 'book');
        row.setAttribute('data-book', b.name);
        row.innerHTML =
          '<button type="button" data-lqn="book-pick" class="flex flex-1 items-center justify-between rounded-md px-2 py-1.5 text-[13px] transition-colors ' +
          (S.filter === b.name ? 'bg-primary/10 font-medium text-primary' : 'hover:bg-muted') + '">' +
          '<span class="flex items-center gap-1.5 truncate"><span class="truncate">' + esc(b.name) + '</span></span>' +
          '<span class="text-[11px] text-muted-foreground" data-lqn="book-count">' + cnt + '</span></button>' +
          '<button type="button" data-lqn="book-rename" title="改名" class="rounded-md px-1.5 py-1 text-[11px] text-muted-foreground hover:bg-muted">改</button>' +
          '<button type="button" data-lqn="book-del" title="删除笔记本" class="rounded-md px-1.5 py-1 text-[11px] text-muted-foreground hover:bg-muted">删</button>';
        nav.appendChild(row);
      });
    }
    // 列表
    var list = $('[data-lqn="list"]');
    if (list) {
      list.innerHTML = '';
      var arr = listSorted();
      if (!arr.length) {
        // ★ 文案逐字取自参照 bundle：回收站为空 / 还没有笔记，点「新建笔记」开始
        list.innerHTML = '<p class="text-xs text-muted-foreground">' +
          (S.filter === TRASH ? '回收站为空' : '还没有笔记，点「新建笔记」开始') + '</p>';
      }
      arr.forEach(function (n) {
        var d = document.createElement('div');
        d.setAttribute('data-lqn', 'item');
        d.setAttribute('data-ck', n.ck);
        d.className = 'w-full cursor-pointer rounded-md px-2.5 py-2 text-left transition-colors ' +
          (n.ck === S.active ? 'bg-primary/10' : 'hover:bg-muted');
        var html = '<div class="lqn-item-top"><div class="truncate text-[13px] font-medium">' + esc(n.title || plain(n.body, 24)) + '</div>' +
          (n.status === 'trash' ? '' :
            '<button type="button" data-lqn="del-item" data-ck="' + esc(n.ck) + '" class="lqn-item-del" title="把这篇移入回收站" aria-label="删除这篇笔记">删</button>') +
          '</div>' +
          '<div class="truncate text-[11px] text-muted-foreground">' + esc(plain(n.body, 40)) + '</div>' +
          '<div class="text-[11px] text-muted-foreground">' + (n.status === 'trash' ? '回收站 · ' : '') +
          (n.dirty ? '未同步' : (n.sid ? '云端' : '本机')) + '</div>';
        if (n.status === 'trash') {
          html += '<div class="mt-1 flex items-center gap-1">' +
            '<button type="button" data-lqn="restore" data-ck="' + esc(n.ck) + '" class="rounded-md border px-2 py-0.5 text-[11px] hover:bg-muted">还原</button>' +
            '<button type="button" data-lqn="purge" data-ck="' + esc(n.ck) + '" class="rounded-md border px-2 py-0.5 text-[11px] text-destructive hover:bg-muted">彻底删</button>' +
            '</div>';
        }
        d.innerHTML = html;
        list.appendChild(d);
      });
    }
    // 编辑器
    var n = active();
    var empty = $('[data-lqn="empty"]'), ed = $('[data-lqn="editor"]');
    if (empty && ed) {
      if (!n) { empty.removeAttribute('hidden'); ed.setAttribute('hidden', ''); }
      else { empty.setAttribute('hidden', ''); ed.removeAttribute('hidden'); }
    }
    if (n) {
      var ti = $('[data-lqn="title"]');
      if (ti && document.activeElement !== ti) { ti.value = n.title || ''; }
      var body = $('[data-lqn="body"]');
      if (body) {
        /* ★ 2026-10-17 修复「记事本格式堆在一起」：正文按**笔记自身 fmt**渲染，
           md 笔记必须先 mdToHtml 再放进 contenteditable；直接把 md 原文塞进去，
           换行会被 HTML 折叠 ⇒ 所有段落/标题/清单挤成一坨。 */
        if (S.mode === 'html') {
          var dispH = n.fmt === 'html' ? sanitizeHtml(n.body || '') : mdToHtml(n.body || '');
          if (document.activeElement !== body && body.innerHTML !== dispH) { body.innerHTML = dispH; }
        } else {
          var dispM = n.fmt === 'html' ? htmlToMd(n.body || '') : (n.body || '');
          if (document.activeElement !== body && body.value !== dispM) { body.value = dispM; }
        }
      }
      var sel = $('[data-lqn="book-sel"]');
      if (sel) {
        sel.innerHTML = '<option value="">（未归类）</option>' + S.books.map(function (b) {
          return '<option value="' + esc(b.name) + '"' + ((n.book || '') === b.name ? ' selected' : '') + '>' + esc(b.name) + '</option>';
        }).join('');
      }
      // ★ 对齐参照底部条的三段：`N 字` · `输入自动保存` · `仅本地（登录后自动上云）`
      var wc = wordCount(n);
      var saveSeg = S.savedAt ? ('已自动保存 ' + S.savedAt) : '输入自动保存';
      var whereSeg = S.user.uid ? (n.dirty ? '同步中…' : '已同步到本站 DB') : '仅本地（登录后自动上云）';
      setTxt('[data-lqn="meta"]', wc + ' 字 · ' + saveSeg + ' · ' + whereSeg);
      var sh = $('[data-lqn="share-line"]');
      if (sh) {
        sh.innerHTML = (n.share_url
          ? '分享链接：<a href="' + esc(n.share_url) + '" target="_blank" rel="noopener">' + esc(n.share_url) + '</a>'
          : (S.user.uid ? '还没有分享链接（点上方「分享」生成）' : '未登录：笔记只保存在本机，登录后可云同步与分享'))
          + ' · 更新于 ' + nowStrShort(n.updated);
      }
    }
    // 状态条（★ 对齐参照底部条的「仅本地（登录后自动上云）」措辞）
    var st = $('[data-lqn="status"]');
    if (st) {
      st.textContent = (S.user.uid ? '已登录 ' + S.user.name + ' · 云端同步已开启' : '仅本地（登录后自动上云）')
        + (S.notice ? ' · ' + S.notice : '');
    }
    var mb = $('[data-lqn="mode-md"]');
    if (mb) { mb.className = btnCls(S.mode === 'md'); }
    var tgl = $('[data-lqn="src-toggle"]');
    if (tgl) { tgl.textContent = (S.mode === 'md') ? '返回排版' : '查看源码'; }
    var dkb = $('[data-lqn="dark"]');
    if (dkb) { dkb.className = btnCls(S.dark); }
    var pvb = $('[data-lqn="preview-btn"]'), pvp = $('[data-lqn="preview"]');
    if (pvb) { pvb.className = btnCls(!!(pvp && !pvp.hasAttribute('hidden'))); }
    refreshSaveBtn();
  }
  /* ★ 字数：参照口径 = 去标签、去全部空白后的字符数（欢迎笔记实测 77 字对得上） */
  function wordCount(n) {
    if (!n) { return 0; }
    var t = String(n.body || '');
    if (n.fmt === 'html') { t = t.replace(/<[^>]*>/g, ' '); }
    return t.replace(/\s+/g, '').length;
  }
  function hhmmss() {
    var d = new Date(), p = function (x) { return (x < 10 ? '0' : '') + x; };
    return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }
  function nowStrShort(ms) {
    if (!ms) { return '—'; }
    var d = new Date(ms), p = function (n) { return (n < 10 ? '0' : '') + n; };
    return p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }
  function btnCls(on) {
    return 'rounded-md px-2 py-1 text-[11px] ' + (on ? 'bg-primary/10 text-primary font-medium' : 'text-muted-foreground hover:bg-muted');
  }

  /* ---------------------------------------------------------------- 本地保存（自动） */
  function schedulePersist() {
    clearTimeout(S.saveTimer);
    S.saveTimer = setTimeout(function () { persist(); }, 250);
    persist(); // ★ 立即写一次：满足「边打字边存本地、断网/刷新不丢」
  }

  /* ---------------------------------------------------------------- 云端 API */
  function api(op, data) {
    var body = { op: op };
    for (var k in (data || {})) { body[k] = data[k]; }
    return fetch('/api/notes', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': S.csrf || '' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'bad_json' }; }).then(function (j) { j._status = r.status; return j; }); });
  }
  function serverSave(n, silent) {
    if (!S.user.uid || !n) { return Promise.resolve(false); }
    /* ★ 批9：加密笔记 ⇒ 上传前先封成信封串（★ 明文绝不出本机） */
    if (n.enc && !S.encLock) {
      var plain = String(n.body || '');
      return encSeal(plain).then(function (env) {
        n.env = env;
        return api('save', { ck: n.ck, title: n.title, body: env, fmt: n.fmt, book: n.book || '', status: n.status || 'normal' })
          .then(function (j) {
            if (!j.ok) { setSaveState('error', '保存失败 · 重试'); toast('云端保存失败：' + (j.error || '错误') + '（本机已保留）'); return false; }
            n.dirty = false;
            var srv = (j.notes || []).filter(function (x) { return x.ck === n.ck; })[0];
            if (srv) { n.sid = srv.id; n.su = srv.updated_at; }
            mergeFromServer(j);
            persist();
            S.savedAt = hhmmss();
            if (!silent) { toast('已保存（密文上云）'); }
            render();
            return true;
          });
      }).catch(function () { toast('加密失败 ⇒ 未上传（本机仍保留明文）'); return false; });
    }
    if (String(n.body || '').length > MAXBODY) { toast('这条笔记超过 256 KiB，云端拒绝保存（本机仍保留）'); return Promise.resolve(false); }
    return api('save', { ck: n.ck, title: n.title, body: n.body, fmt: n.fmt, book: n.book || '', status: n.status || 'normal' })
      .then(function (j) {
        if (!j.ok) { setSaveState('error', '保存失败 · 重试'); toast('云端保存失败：' + (j.error || '错误') + '（本机已保留）'); return false; }
        n.dirty = false;
        var srv = (j.notes || []).filter(function (x) { return x.ck === n.ck; })[0];
        if (srv) { n.sid = srv.id; n.su = srv.updated_at; }
        mergeFromServer(j);
        persist();
        S.savedAt = hhmmss();
        if (!silent) { toast('已保存（本机 + 本站数据库）'); }
        render();
        return true;
      });
  }
  function mergeFromServer(j) {
    if (!j || !j.ok) { return; }
    if (Array.isArray(j.books)) {
      var names = j.books.map(function (b) { return b.name; });
      S.books = j.books.map(function (b) { return { id: b.id, name: b.name }; });
      if (!S.books.length) { S.books = [{ id: 0, name: '默认' }]; }
      persistBooks();
    }
    if (Array.isArray(j.notes)) {
      var byCk = {};
      j.notes.forEach(function (sn) { byCk[sn.ck] = sn; });
      var kept = [];
      S.notes.forEach(function (ln) {
        var sn = byCk[ln.ck];
        if (!sn) { kept.push(ln); return; }   // 云端还没有：留在本机（下次继续上传）
        delete byCk[ln.ck];                   // ★ 无论走哪一支都必须销账，否则下面会重复推入
        if (ln.dirty) { kept.push(ln); }
        else {
          ln.sid = sn.id; ln.title = sn.title; ln.fmt = sn.fmt;
          /* ★ 批9：加密笔记 ⇒ 服务端回的是【信封】，绝不能拿它覆盖本机内存里的明文（否则编辑器立刻变密文）
             做法：信封进 ln.env，ln.body 保留本机明文（本机有密钥时） */
          if (encIs(sn.body)) {
            ln.env = sn.body; ln.enc = 1;
            if (!S.encOk[ln.ck]) { ln.body = sn.body; }        // 本机解不开 ⇒ body 就用信封（编辑器显示锁定态）
          } else {
            ln.body = sn.body; ln.enc = 0; ln.env = '';
          }
          ln.book = bookNameOf(sn.book_id, j.books) || ln.book; ln.status = sn.status;
          ln.su = sn.updated_at;
          /* ★ 批3：URL 要【别名优先】——只按 token 拼会把服务端刚给的 /s/<alias> 覆盖回 /share/<token>
             （实测踩过：POST body 与库里都有别名，页面上却显示 token 链接） */
          ln.share_url = sn.share ? (sn.share_alias ? '/s/' + sn.share_alias : '/share/' + sn.share) : ln.share_url;
          ln.share_alias = sn.share_alias || '';
          ln.share_exp = sn.share_exp || '';
          kept.push(ln);
        }
      });
      j.notes.forEach(function (sn) {
        if (!byCk[sn.ck]) { return; }
        byCk[sn.ck] = null;
        kept.push({ ck: sn.ck, sid: sn.id, title: sn.title, body: sn.body, fmt: sn.fmt,
          book: bookNameOf(sn.book_id, j.books) || '', status: sn.status, dirty: false,
          su: sn.updated_at, updated: Date.parse(String(sn.updated_at).replace(' ', 'T')) || Date.now(),
          share_url: sn.share ? (sn.share_alias ? '/s/' + sn.share_alias : '/share/' + sn.share) : '',
          share_alias: sn.share_alias || '', share_exp: sn.share_exp || '' });
      });
      S.notes = kept;
      persist();
    }
  }
  function bookNameOf(id, books) {
    var arr = books || [];
    for (var i = 0; i < arr.length; i++) { if (Number(arr[i].id) === Number(id)) { return arr[i].name; } }
    return '';
  }
  /* 登录后的合并：本机 dirty / 云端没有的 ⇒ 上传；云端有而本机没有的 ⇒ 落到本机 */
  function syncNow(interactive) {
    if (!S.user.uid) { return Promise.resolve(false); }
    return api('list', {}).then(function (j) {
      if (!j.ok) { if (interactive) { toast('云端读取失败：' + (j.error || '')); } return false; }
      var srvCks = {};
      (j.notes || []).forEach(function (sn) { srvCks[sn.ck] = 1; });
      var push = S.notes.filter(function (n) { return !n.demo && (n.dirty || !srvCks[n.ck]); });
      if (!push.length) {
        mergeFromServer(j);
        S.notice = '云端已是最新（本机 ' + S.notes.length + ' 条）';
        render();
        return true;
      }
      var payload = push.map(function (n) {
        return { ck: n.ck, title: n.title, body: n.body, fmt: n.fmt, book: n.book || '', status: n.status || 'normal' };
      });
      var books = S.books.map(function (b) { return b.name; });
      return api('merge', { notes: payload, books: books }).then(function (j2) {
        if (!j2.ok) {
          if (j2.error === 'note_too_large') { toast('有笔记超过 256 KiB，未上传（本机保留）'); }
          else { toast('合并失败：' + (j2.error || '')); }
          return false;
        }
        S.notes.forEach(function (n) { if (n.dirty || !srvCks[n.ck]) { n.dirty = false; } });
        mergeFromServer(j2);
        S.notice = '已合并：本机新增/更新 ' + push.length + ' 条 ⇒ 云端，共 ' + S.notes.length + ' 条';
        try { localStorage.setItem(K.migCloud, '1'); } catch (e) {}
        render();
        if (interactive) { toast('云同步完成：上传 ' + push.length + ' 条'); }
        return true;
      });
    }).catch(function () { if (interactive) { toast('网络不可用（本机数据不受影响）'); } return false; });
  }

  /* ---------------------------------------------------------------- 记事本迁入 */
  /* 旧「记事本」格式：字段名容错（与参照站 Notepad-*.js 的 title|content|folder|tags 口径一致） */
  function pick(o, keys) {
    for (var i = 0; i < keys.length; i++) { var v = o[keys[i]]; if (v != null && String(v).trim() !== '') { return String(v); } }
    return '';
  }
  function normItem(o) {
    if (o == null) { return null; }
    if (typeof o !== 'object') { return { title: '', body: String(o), book: '' }; }
    var title = pick(o, ['title', 'name', 'heading', 'subject', 'note_title', 'topic']);
    var body = pick(o, ['content', 'body', 'text', 'markdown', 'md', 'description', 'desc', 'detail', 'note', 'value']);
    var book = pick(o, ['folder', 'group', 'category', 'notebook', 'parent', 'dir']);
    return (title || body) ? { title: title, body: body, book: book } : null;
  }
  function parseLegacy(raw) {
    var d = raw;
    if (typeof raw === 'string') { try { d = JSON.parse(raw); } catch (e) { return []; } }
    var arr = [];
    if (Array.isArray(d)) { arr = d; }
    else if (d && typeof d === 'object') {
      var keys = ['notes', 'data', 'list', 'items', 'results', 'records', 'noteList', 'note_list'];
      for (var i = 0; i < keys.length; i++) { if (Array.isArray(d[keys[i]])) { arr = d[keys[i]]; break; } }
      if (!arr.length) { arr = [d]; }
    }
    return arr.map(normItem).filter(Boolean);
  }
  /* 旧「记事本」的分组键可能是 ["工作","生活"] 这种纯字符串数组，也可能是 [{name:'工作'}] ⇒ 两种都认 */
  function parseLegacyFolders(raw) {
    var d = raw;
    if (typeof raw === 'string') { try { d = JSON.parse(raw); } catch (e) { return []; } }
    var arr = Array.isArray(d) ? d : (d && typeof d === 'object' ? [d] : []);
    return arr.map(function (x) {
      if (x == null) { return ''; }
      if (typeof x === 'string') { return x.trim(); }
      return pick(x, ['name', 'folder', 'title', 'group', 'notebook', 'category']) || pick(x, ['body', 'content', 'text']);
    }).filter(function (s) { return s; });
  }
  function migrateLegacy() {
    var raw = localStorage.getItem(K.legacy);
    var rawFolders = localStorage.getItem(K.legacyFolders);
    if (!raw) { return 0; }
    if (localStorage.getItem(K.mig) === '1') { return 0; }
    var items = parseLegacy(raw);
    if (!items.length) { try { localStorage.setItem(K.mig, '1'); } catch (e) {} return 0; }
    var books = parseLegacyFolders(rawFolders);
    items.forEach(function (it) { if (it.book) { books.push(it.book); } });
    books.forEach(function (f) { if (!S.books.some(function (b) { return b.name === f; })) { S.books.push({ id: 0, name: f }); } });
    var at = Date.now();
    items.forEach(function (it, i) {
      S.notes.push({ ck: ck(), sid: 0, title: trim(it.title || plain(it.body, 24), 200), body: it.body || '',
        fmt: 'md', book: it.book || '', status: 'normal', dirty: true, su: '', updated: at - i });
    });
    persist(); persistBooks();
    try { localStorage.setItem(K.mig, '1'); } catch (e) {}
    // ★ 原数据（llq_notepad_v1 / llq_notepad_folders_v1）**不删**：只打迁移标记，随时可回退
    S.notice = '已从本机「记事本」迁入 ' + items.length + ' 条（原数据保留未删）';
    return items.length;
  }

  /* ---------------------------------------------------------------- 导入文件 */
  function splitLine(line, sep) {
    var out = [], cur = '', q = false;
    for (var i = 0; i < line.length; i++) {
      var ch = line[i];
      if (q) { if (ch === '"') { if (line[i + 1] === '"') { cur += '"'; i++; } else { q = false; } } else { cur += ch; } }
      else if (ch === '"') { q = true; }
      else if (ch === sep) { out.push(cur); cur = ''; }
      else { cur += ch; }
    }
    out.push(cur);
    return out;
  }
  function parseCsv(text) {
    var lines = text.split(/\r?\n/).filter(function (l) { return l.trim() !== ''; });
    if (!lines.length) { return []; }
    var sep = (lines[0].indexOf('\t') >= 0) ? '\t' : ',';
    var head = splitLine(lines[0], sep).map(function (h) { return h.trim().toLowerCase(); });
    var known = ['title', 'content', 'body', 'text', 'name', 'folder', 'tags', 'group'];
    var hasHead = head.some(function (h) { return known.indexOf(h) >= 0; });
    var body = hasHead ? lines.slice(1) : lines;
    var idx = function (names) { for (var i = 0; i < names.length; i++) { var p = head.indexOf(names[i]); if (p >= 0) { return p; } } return -1; };
    var it = idx(['title', 'name', 'subject', 'heading']), ic = idx(['content', 'body', 'text', 'markdown']), ib = idx(['folder', 'group', 'category', 'notebook']);
    return body.map(function (line) {
      var c = splitLine(line, sep);
      return { title: (it >= 0 ? c[it] : c[0] || '').trim(), body: (ic >= 0 ? c[ic] : c[1] || '').trim(), book: ib >= 0 ? (c[ib] || '').trim() : '' };
    }).filter(function (x) { return x.title || x.body; });
  }
  /* ★ ENEX（Evernote 导出）——纯本地解析，零外发 */
  function parseEnex(text) {
    var items = [];
    if (typeof DOMParser === 'undefined') { return items; }
    var doc = new DOMParser().parseFromString(text, 'text/xml');
    var notes = doc.getElementsByTagName('note');
    for (var i = 0; i < notes.length; i++) {
      var tn = notes[i].getElementsByTagName('title')[0];
      var cn = notes[i].getElementsByTagName('content')[0];
      var nn = notes[i].getElementsByTagName('notebook')[0];
      var title = tn ? String(tn.textContent || '').trim() : '';
      var html = cn ? String(cn.textContent || '') : '';
      if (!title && !html) { continue; }
      items.push({ title: trim(title, 200), body: sanitizeHtml(html), book: nn ? String(nn.textContent || '').trim() : '', fmt: 'html' });
    }
    return items;
  }
  /* ★ HTML 网页/导出文件——纯本地解析 */
  function parseHtmlFile(name, text) {
    if (typeof DOMParser === 'undefined') { return []; }
    var doc = new DOMParser().parseFromString(text, 'text/html');
    var h1 = doc.querySelector ? doc.querySelector('h1') : null;
    var title = String((doc.title || '') || (h1 ? h1.textContent : '') || name.replace(/\.[a-z0-9]+$/i, '')).trim();
    var main = doc.body ? doc.body.innerHTML : String(text || '');
    main = main.replace(/<script[\s\S]*?<\/script>/gi, '').replace(/<style[\s\S]*?<\/style>/gi, '');
    if (!String(main).replace(/<[^>]*>/g, '').trim()) { return []; }
    return [{ title: trim(title, 200), body: sanitizeHtml(main), book: '', fmt: 'html' }];
  }
  function importText(name, text) {
    var items = [];
    if (/\.enex$/i.test(name)) { items = parseEnex(text); }
    else if (/\.(html?|xhtml)$/i.test(name)) { items = parseHtmlFile(name, text); }
    else if (/\.json$/i.test(name)) { items = parseLegacy(text); }
    else if (/\.(csv|tsv)$/i.test(name)) { items = parseCsv(text); }
    else {
      var lines = text.split(/\r?\n/);
      var title = (lines[0] || '').replace(/^#+\s*/, '').trim().slice(0, 200);
      items = [{ title: title, body: lines.slice(1).join('\n').replace(/^\n+/, ''), book: '', fmt: 'md' }];
    }
    if (!items.length) { toast('这个文件里没有读到可迁入的笔记'); return 0; }
    var at = Date.now();
    items.forEach(function (it, i) {
      S.notes.push({ ck: ck(), sid: 0, title: trim(it.title || plain(it.body, 24), 200), body: it.body || '',
        fmt: it.fmt === 'html' ? 'html' : 'md', book: it.book || '', status: 'normal', dirty: true, su: '', updated: at - i });
    });
    persist(); render();
    toast('已导入 ' + items.length + ' 条（本机）' + (S.user.uid ? '，正在上传到云端…' : ''));
    if (S.user.uid) { syncNow(false); }
    return items.length;
  }

  /* ---------------------------------------------------------------- 动作 */
  function newNote(book, tplHtml, tplTitle) {
    var n = { ck: ck(), sid: 0, title: tplTitle || '', body: tplHtml || '', fmt: 'html',
      book: book || (S.filter !== ALL && S.filter !== TRASH ? S.filter : ''), status: 'normal',
      dirty: true, su: '', updated: Date.now() };
    S.notes.unshift(n);
    S.active = n.ck; S.filter = (S.filter === TRASH ? ALL : S.filter);
    persist();
    localStorage.setItem(K.active, S.active);
    // ★ skipPull=true：此刻 active() 已是新笔记，回读 DOM 会把旧编辑器内容写进新笔记
    setMode('html', true);
    render();
    var ti = $('[data-lqn="title"]');
    if (ti) { ti.focus(); }
    return n;
  }
  /* ★ t30：显式「保存」按钮 —— 与自动保存同一接口（POST /api/notes op=save）
     三态：保存中… / 已保存 HH:MM:SS / 保存失败（可重试）；无改动时按钮显示「无改动」并禁用 */
  function saveNow() {
    var n = active();
    if (!n) { toast('先新建或选择一篇笔记'); return; }
    if (n.demo) { n.demo = 0; }         /* ★ t30：显式点保存 ⇒ 这篇算用户自己的，可上云 */
    pullFromDom(n);
    n.dirty = true;
    persist();
    if (!S.user.uid) {
      S.savedAt = hhmmss();
      toast('已保存到本机（未登录，未上传云端）');
      render();
      return;
    }
    setSaveState('saving', '保存中…');
    serverSave(n, false).then(function (ok) {
      S.savedAt = hhmmss();
      if (ok) { setSaveState('saved', '已保存 ' + S.savedAt); }
      else { setSaveState('error', '保存失败 · 重试'); }
      render();
    }).catch(function () { setSaveState('error', '保存失败 · 重试'); });
  }
  function pullFromDom(n) {
    /* ★ 批9：加密笔记且本机没密钥时【锁住】—— 编辑器里是占位提示，绝不能把它当正文回读/上传
       （否则会把信封串覆盖成提示语 = 把用户笔记写坏） */
    if (S.encLock) { return; }
    var ti = $('[data-lqn="title"]'), body = $('[data-lqn="body"]');
    if (ti) { n.title = trim(ti.value, 200); }
    if (body) {
      if (S.mode === 'html') { n.body = sanitizeHtml(body.innerHTML); n.fmt = 'html'; }
      else { n.body = body.value; n.fmt = 'md'; }
    }
    n.updated = Date.now();
  }
  function onInput() {
    var n = active();
    if (!n) { return; }
    pullFromDom(n);
    if (n.demo) { n.demo = 0; }         // ★ t30：用户真改了 ⇒ 这篇就是他自己的了，可以上云
    histPush(n);                       // ★ 批4：自动保存时记录历史版本（照参照口径）
    n.dirty = true;
    schedulePersist();
    clearTimeout(S.netTimer);
    if (S.user.uid) { S.netTimer = setTimeout(function () { serverSave(n, true).catch(function () { setSaveState('error', '保存失败 · 重试'); }); }, 1200); }
    render();
  }
  /* ★ t30 修复「删除无效」：
     ① 不传 ck 时作用在"当前选中"的笔记；没选中 ⇒ 明确提示（原来静默 return = 站主说的"没反应"）
     ② 先取消 1.2s 待发的自动保存，避免它把 status 写回 normal（删了又回来）
     ③ 后端失败 ⇒ 本地状态回滚 + 失败提示（原来照样提示"已移入回收站"，等于骗人）
     ④ 列表项自带「删」，明确删的是哪一篇 */
  function trashNote(ckArg) {
    var n = ckArg ? noteByCk(ckArg) : active();
    if (!n) { toast('先选择一篇笔记，再点「删除」'); return; }
    if (n.status === 'trash') { toast('这篇已经在回收站了'); return; }
    clearTimeout(S.netTimer); S.netTimer = null;
    n.status = 'trash'; n.updated = Date.now(); n.dirty = true;
    persist();
    if (S.active === n.ck) { S.active = ''; localStorage.setItem(K.active, ''); }
    render();
    if (!S.user.uid) { toast('已移入回收站（本机；未登录所以没上云）'); return; }
    if (!n.sid) { toast('已移入回收站（本机待同步）'); return; }
    api('trash', { id: n.sid }).then(function (j) {
      if (j && j.ok) { n.dirty = false; mergeFromServer(j); persist(); render(); toast('已移入回收站（可在回收站还原或彻底删除）'); }
      else { n.status = 'normal'; n.dirty = true; persist(); render(); toast('删除失败：' + ((j && j.error) || '网络错误') + '（笔记已还原）', 3800); }
    }).catch(function () {
      n.status = 'normal'; n.dirty = true; persist(); render();
      toast('删除失败：网络错误（笔记已还原）', 3800);
    });
  }
  function restoreNote(c) {
    var n = noteByCk(c); if (!n) { return; }
    n.status = 'normal'; n.updated = Date.now(); n.dirty = true;
    persist();
    if (S.user.uid && n.sid) { api('restore', { id: n.sid }).then(function (j) { if (j.ok) { n.dirty = false; mergeFromServer(j); persist(); render(); } }); }
    toast('已从回收站还原');
    render();
  }
  function purgeNote(c) {
    var n = noteByCk(c); if (!n) { return; }
    if (S.user.uid && n.sid) {
      api('purge', { id: n.sid }).then(function (j) {
        if (!j || !j.ok) { toast('彻底删除失败：' + ((j && j.error) || '网络错误') + '（刷新后可能仍在回收站）', 4000); }
      }).catch(function () { toast('彻底删除失败：网络错误（刷新后可能仍在回收站）', 4000); });
    }
    S.notes = S.notes.filter(function (x) { return x.ck !== c; });
    if (S.active === c) { S.active = ''; localStorage.setItem(K.active, ''); }
    persist(); toast('已彻底删除（不可恢复）'); render();
  }
  function purgeTrash() {
    var t = S.notes.filter(function (n) { return n.status === 'trash'; });
    if (!t.length) { toast('回收站是空的'); return; }
    var localClear = function () {
      S.notes = S.notes.filter(function (n) { return n.status !== 'trash'; });
      persist(); toast('回收站已清空（' + t.length + ' 条彻底删除）'); render();
    };
    if (!S.user.uid) { localClear(); return; }
    api('purge_trash', {}).then(function (j) {
      if (j && j.ok) { localClear(); }
      else { toast('清空失败：' + ((j && j.error) || '网络错误') + '（回收站未清空）', 3800); }
    }).catch(function () { toast('清空失败：网络错误（回收站未清空）', 3800); });
  }
  /* 笔记本：全部走**页内内联表单**（不用 window.prompt/confirm —— 原生对话框会阻塞自动化验收） */
  function bookForm(mode, name) {
    var box = $('[data-lqn="bookform"]');
    if (!box) { return; }
    box.removeAttribute('hidden');
    box.setAttribute('data-mode', mode);
    box.setAttribute('data-name', name || '');
    var inp = $('[data-lqn="bookform-input"]');
    if (inp) { inp.value = mode === 'rename' ? (name || '') : ''; inp.focus(); }
  }
  function bookFormSubmit() {
    var box = $('[data-lqn="bookform"]'), inp = $('[data-lqn="bookform-input"]');
    if (!box || !inp) { return; }
    var mode = box.getAttribute('data-mode') || 'new';
    var old = box.getAttribute('data-name') || '';
    var v = trim(inp.value, 120);
    box.setAttribute('hidden', '');
    if (!v) { return; }
    if (S.books.some(function (b) { return b.name === v; })) { toast('已有同名笔记本'); return; }
    if (mode === 'rename') {
      var b = S.books.filter(function (x) { return x.name === old; })[0];
      if (!b) { return; }
      b.name = v;
      S.notes.forEach(function (n) { if ((n.book || '') === old) { n.book = v; n.dirty = true; touch(n); } });
      if (S.filter === old) { S.filter = v; }
      persistBooks(); persist();
      if (S.user.uid && b.id) { api('book_save', { id: b.id, name: v }); }
      if (S.user.uid) { S.notes.filter(function (n) { return n.book === v; }).forEach(function (n) { n.dirty = true; serverSave(n, true); }); }
      toast('笔记本已改名：' + old + ' → ' + v);
    } else {
      S.books.push({ id: 0, name: v });
      persistBooks();
      if (S.user.uid) { api('book_save', { name: v }).then(function (j) { if (j.ok) { mergeFromServer(j); persistBooks(); render(); } }); }
      toast('已新建笔记本：' + v);
    }
    render();
  }
  function delBook(name) {
    var b = S.books.filter(function (x) { return x.name === name; })[0];
    if (!b) { return; }
    S.books = S.books.filter(function (x) { return x.name !== name; });
    S.notes.forEach(function (n) { if ((n.book || '') === name) { n.book = ''; n.dirty = true; touch(n); } });
    if (S.filter === name) { S.filter = ALL; }
    persistBooks(); persist();
    if (S.user.uid && b.id) { api('book_delete', { id: b.id }).then(function (j) { if (j.ok) { mergeFromServer(j); persistBooks(); persist(); render(); } }); }
    toast('已删除笔记本「' + name + '」，里面的笔记已移到未归类');
    render();
  }
  function setMode(m, skipPull) {
    var n = active();
    /* ★ 批9：这篇是加密笔记 ⇒ 先在本机解密（有密钥）或锁住（没密钥），再按明文/占位渲染
       ★ S.encTried 保证【只尝试一次】，避免解密失败时 setMode 递归 */
    if (n && (n.enc || encIs(n.body))) {
      n.enc = 1;
      if (!n.env) { n.env = encIs(n.body) ? n.body : ''; }
      if (!S.encOk[n.ck] && !S.encTried[n.ck]) {
        S.encTried[n.ck] = 1; S.encLock = true;
        encOpen(n.env).then(function (plain) {
          var a = active();
          if (!a || a.ck !== n.ck) { return; }
          a.body = plain; S.encOk[a.ck] = 1; S.encLock = false;
          S.notice = ENC_TIP; render(); setMode(S.mode, true); render();
        }).catch(function () {
          var a = active();
          if (a) { a.enc = 1; a.env = a.env || a.body; a.body = ''; }   // ★ 锁住：编辑器留空、信封留在 env，绝不覆盖
          S.encLock = true; S.notice = ENC_NOKEY; render(); setMode(S.mode, true); render();
        });
      }
    }
    // ★ 只在"编辑器元素已存在"且【调用方没要求跳过回读】时才回读 DOM：
    //    · boot 首次调用时 body 还没建，此时回读会把欢迎笔记标题用空 input 覆盖（实测踩过）
    //    · 新建/换模板时 active 已指向新笔记，若还回读就会把【旧编辑器内容】写进新笔记（实测踩过）
    if (!skipPull && n && $('[data-lqn="body"]')) { pullFromDom(n); }
    var next = m === 'md' ? 'md' : 'html';
    if (n && next !== S.mode) {
      // ★ 换视图时把正文在 Markdown ↔ HTML 之间转换（不换内核、不改存储介质）
      if (next === 'html' && n.fmt !== 'html') { n.body = mdToHtml(n.body); n.fmt = 'html'; }
      else if (next === 'md' && n.fmt !== 'md') { n.body = htmlToMd(n.body); n.fmt = 'md'; }
      n.dirty = true;
      persist();
    }
    S.mode = next;
    localStorage.setItem(K.mode, S.mode);
    var wrap = $('[data-lqn="bodywrap"]');
    if (wrap) {
      wrap.innerHTML = '';
      var el = document.createElement(S.mode === 'html' ? 'div' : 'textarea');
      el.setAttribute('data-lqn', 'body');
      if (S.mode === 'html') {
        el.setAttribute('contenteditable', 'true');
        el.className = 'lqn-wys';
        el.setAttribute('data-ph', '开始记录…');
        if (n) { el.innerHTML = n.fmt === 'html' ? sanitizeHtml(n.body || '') : mdToHtml(n.body || ''); }
      } else {
        el.className = 'lqn-ta';
        el.setAttribute('spellcheck', 'false');
        if (n) { el.value = n.fmt === 'html' ? htmlToMd(n.body || '') : (n.body || ''); }
      }
      wrap.appendChild(el);
    }
    /* ★ n.fmt 由「转换块 / pullFromDom」维护；这里不再强改，避免 md 正文被标成 html */
    if (n && n.fmt !== 'html' && n.fmt !== 'md') { n.fmt = 'md'; }
    render();
  }
  function togglePreview() {
    var pv = $('[data-lqn="preview"]');
    if (!pv) { return; }
    if (!pv.hasAttribute('hidden')) { pv.setAttribute('hidden', ''); return; }
    var n = active(); if (!n) { return; }
    pullFromDom(n);
    pv.innerHTML = n.fmt === 'html' ? sanitizeHtml(n.body) : mdToHtml(n.body);
    pv.removeAttribute('hidden');
  }
  function exportMd() {
    var n = active(); if (!n) { return; }
    pullFromDom(n);
    var md = n.fmt === 'html' ? htmlToMd(n.body) : n.body;
    dl((n.title || 'note') + '.md', md, 'text/markdown;charset=utf-8');
  }
  function exportHtml() {
    var n = active(); if (!n) { return; }
    pullFromDom(n);
    var inner = n.fmt === 'html' ? sanitizeHtml(n.body) : mdToHtml(n.body);
    var html = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>' + esc(n.title) +
      '</title></head><body><h1>' + esc(n.title) + '</h1>' + inner + '</body></html>';
    dl((n.title || 'note') + '.html', html, 'text/html;charset=utf-8');
  }
  function dl(name, text, mime) {
    var blob = new Blob([text], { type: mime || 'text/plain;charset=utf-8' });
    var url = URL.createObjectURL(blob), a = document.createElement('a');
    a.href = url; a.download = name; document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 500);
  }
  /* ---------------------------------------------------------------- 分享面板（★ 批3：三个页签 + 短链 + 二维码 + 统计）
   * 项数与文案对齐参照：页签 = 分享链接 / 分享设置 / 访问统计；
   * 设置项 = 有效期（永久/7/30/90 天）· 设置访问密码（留空则保持原密码不变）· 自定义短链；
   * 统计 = 总浏览量 · 独立访客 · 近 14 天趋势 · 最近来源；操作 = 开启分享 / 修改设置 / 取消分享。
   * ★ 二维码本地生成（自研编码器），零外发。
   * ★ 不用 window.confirm/prompt（原生对话框会阻塞自动化验收）⇒ 用页内确认条，文案照抄参照那句。 */
  var SH_TABS = ['link', 'setting', 'stat'];
  function sharePanel() { return $('[data-lqn="share-panel"]'); }
  function shTab(t) {
    S.shTab = SH_TABS.indexOf(t) >= 0 ? t : 'link';
    SH_TABS.forEach(function (k) {
      var pane = $('[data-lqn="sh-pane-' + k + '"]');
      if (pane) { if (k === S.shTab) { pane.removeAttribute('hidden'); } else { pane.setAttribute('hidden', ''); } }
      var btn = $('[data-lqn="sh-tab-' + k + '"]');
      if (btn) { btn.className = 'lqn-sh-tab' + (k === S.shTab ? ' lqn-sh-tab-on' : ''); }
    });
    if (S.shTab === 'stat') { loadStats(); }
  }
  function shareNote() {
    var n = active(); if (!n) { return; }
    if (!S.user.uid) { toast('登录后才能分享'); return; }
    var panel = sharePanel();
    if (panel) { panel.removeAttribute('hidden'); }
    shTab(n.sid ? 'link' : 'setting');
    fillSharePanel();
    if (n.sid) { loadStats(); }
  }
  function fillSharePanel() {
    var n = active(); if (!n) { return; }
    n.share_url = n.share_url || '';
    var out = $('[data-lqn="share-out"]');
    if (out) { if (n.share_url) { out.removeAttribute('hidden'); } else { out.setAttribute('hidden', ''); } }
    var u = $('[data-lqn="share-url"]');
    if (u) { u.value = n.share_url ? (location.origin + n.share_url) : ''; }
    var al = $('[data-lqn="share-alias"]');
    if (al && document.activeElement !== al) { al.value = n.share_alias || ''; }
    var ps = $('[data-lqn="sh-set-msg"]');
    if (ps) {
      ps.textContent = n.share_url
        ? ('已开启分享：' + n.share_url + (n.share_pw ? '（已设访问密码）' : '') + (n.share_exp ? '（有效期至 ' + n.share_exp + '）' : '（永久有效）'))
        : '这篇文章还没有分享';
    }
    var ok = $('[data-lqn="share-do"]');
    if (ok) { ok.textContent = n.share_url ? '修改设置' : '开启分享'; }
    var off = $('[data-lqn="share-off"]');
    if (off) { if (n.share_url) { off.removeAttribute('hidden'); } else { off.setAttribute('hidden', ''); } }
    var cf = $('[data-lqn="share-confirm"]');
    if (cf) { cf.setAttribute('hidden', ''); }
    renderQr(n.share_url);
    var open = $('[data-lqn="share-open"]');
    if (open) { open.setAttribute('href', n.share_url || '#'); }
  }
  function renderQr(url) {
    var box = $('[data-lqn="qr-box"]');
    if (!box) { return; }
    var full = url ? (location.origin + url) : '';
    box.innerHTML = full ? QR.svg(full) : '';
    var wrap = $('[data-lqn="qr-wrap"]');
    if (wrap) { if (full) { wrap.removeAttribute('hidden'); } else { wrap.setAttribute('hidden', ''); } }
  }
  function doShare() {
    var n = active(); if (!n) { return; }
    var days = parseInt((($('[data-lqn="share-days"]') || {}).value || '0'), 10) || 0;
    var pw = ((($('[data-lqn="share-pw"]') || {}).value) || '');
    var alias = ((($('[data-lqn="share-alias"]') || {}).value) || '');
    var wasShared = !!n.share_url;
    pullFromDom(n);
    n.dirty = true;
    serverSave(n, true).then(function () {
      return api('share', { id: n.sid, days: days, password: pw, alias: alias });
    }).then(function (j) {
      if (!j || !j.ok) {
        var m = (j && j.msg) ? j.msg : ((j && j.error === 'alias_taken') ? '别名已被占用，请换一个' : '分享失败，请稍后再试');
        toast(m);
        return;
      }
      n.share_url = j.url; n.share_alias = j.alias || ''; n.share_exp = j.expires_at || '';
      n.share_pw = !!j.has_password;
      // ★ 顺序：先把 URL/别名写进输入框，再 merge（merge 会用服务端字段重建 share_url，现已按别名优先修好）
      var out = $('[data-lqn="share-out"]');
      if (out) { out.removeAttribute('hidden'); }
      var u = $('[data-lqn="share-url"]');
      if (u) { u.value = location.origin + j.url; }
      mergeFromServer(j);
      n = active() || n;                       // ★ merge 会重建 S.notes 里的对象 ⇒ 之后一律用新的 active
      fillSharePanel();
      loadStats();
      toast(wasShared ? '已更新分享设置' : '已开启分享');
      render();
    }).catch(function () { toast('分享失败，请稍后再试'); });
  }
  function toggleOffConfirm() {
    var cf = $('[data-lqn="share-confirm"]');
    if (!cf) { return; }
    if (cf.hasAttribute('hidden')) { cf.removeAttribute('hidden'); } else { cf.setAttribute('hidden', ''); }
  }
  function unshareNote() {
    var n = active(); if (!n) { return; }
    api('unshare', { id: n.sid }).then(function (j) {
      if (!j || !j.ok) { toast('分享失败，请稍后再试'); return; }
      n.share_url = ''; n.share_alias = ''; n.share_exp = ''; n.share_pw = false;
      mergeFromServer(j);
      fillSharePanel();
      shTab('setting');
      toast('已取消分享，链接立即失效');
      render();
    }).catch(function () { toast('分享失败，请稍后再试'); });
  }
  function copyShare() {
    var u = $('[data-lqn="share-url"]');
    if (!u || !u.value) { return; }
    var text = u.value;
    function fallback() { u.select(); try { document.execCommand('copy'); toast('链接已复制'); } catch (e) { toast('复制失败，请手动选择复制'); } }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { toast('链接已复制'); }, fallback);
    } else { fallback(); }
  }
  function loadStats() {
    var n = active(); if (!n || !n.sid || !S.user.uid) { return; }
    api('share_stats', { id: n.sid }).then(function (j) {
      if (!j || !j.ok) { return; }
      var pv = $('[data-lqn="stat-pv"]'), uv = $('[data-lqn="stat-uv"]');
      if (pv) { pv.textContent = String(j.shared ? j.pv : 0); }
      if (uv) { uv.textContent = String(j.shared ? j.uv : 0); }
      var bars = $('[data-lqn="stat-bars"]');
      if (bars) {
        var days = (j.days || []), max = 1, i;
        for (i = 0; i < days.length; i++) { if ((days[i].pv || 0) > max) { max = days[i].pv; } }
        bars.innerHTML = days.map(function (d) {
          var h = Math.round(((d.pv || 0) / max) * 100);
          return '<div class="lqn-bar-col" title="' + esc(d.d) + '：浏览 ' + (d.pv || 0) + ' · 独立访客 ' + (d.uv || 0) + '">'
            + '<i style="height:' + (d.pv ? Math.max(6, h) : 2) + '%"></i><span>' + esc(d.d) + '</span></div>';
        }).join('');
      }
      var refs = $('[data-lqn="stat-refs"]');
      if (refs) {
        if (!j.shared || !(j.refs || []).length) { refs.innerHTML = '<li class="lqn-meta">（还没有访问记录）</li>'; }
        else { refs.innerHTML = j.refs.map(function (r) { return '<li><span>' + esc(r.t) + '</span><b>' + esc(r.referer) + '</b></li>'; }).join(''); }
      }
    }).catch(function () {});
  }

  /* ---------------------------------------------------------------- 编辑器命令（不换内核：html 走 execCommand，md 走语法插入） */
  function bodyEl() { return $('[data-lqn="body"]'); }
  function pushHist() {
    var b = bodyEl(); if (!b) { return; }
    var v = S.mode === 'html' ? b.innerHTML : b.value, t = Date.now();
    if (t - S.lastPush < 250 && S.undo.length) { S.undo[S.undo.length - 1] = v; }
    else { S.undo.push(v); if (S.undo.length > 200) { S.undo.shift(); } }
    S.lastPush = t; S.redo = [];
  }
  function setBodyValue(v) {
    var b = bodyEl(); if (!b) { return; }
    if (S.mode === 'html') { b.innerHTML = v; } else { b.value = v; }
    onInput();
  }
  function undo() {
    var b = bodyEl(); if (!b || !S.undo.length) { return; }
    var cur = S.mode === 'html' ? b.innerHTML : b.value;
    var prev = S.undo.pop();
    if (prev === cur && S.undo.length) { prev = S.undo.pop(); }
    S.redo.push(cur); setBodyValue(prev);
  }
  function redo() {
    var b = bodyEl(); if (!b || !S.redo.length) { return; }
    var cur = S.mode === 'html' ? b.innerHTML : b.value;
    var nxt = S.redo.pop();
    S.undo.push(cur); setBodyValue(nxt);
  }
  /* md 模式：对当前选区做【包裹 / 行前缀 / 插入块】 */
  function mdBlock(mode, arg) {
    var b = bodyEl(); if (!b || S.mode !== 'md') { return false; }
    var v = b.value, s = b.selectionStart || 0, e = b.selectionEnd || 0, sel = v.slice(s, e);
    var ls = v.lastIndexOf('\n', s - 1) + 1;
    var le = v.indexOf('\n', e); if (le < 0) { le = v.length; }
    var block = v.slice(ls, le);
    if (mode === 'wrap') {
      var mk = arg === 'strike' ? ['~~', '~~'] : (arg === 'mark' ? ['==', '=='] : (arg === 'code' ? ['`', '`'] : ['**', '**']));
      var out = mk[0] + (sel || '文字') + mk[1];
      v = v.slice(0, s) + out + v.slice(e);
      b.value = v; b.selectionStart = s + mk[0].length; b.selectionEnd = s + mk[0].length + (sel || '文字').length;
    } else if (mode === 'line') {
      var pre = { h1: '# ', h2: '## ', h3: '### ', ul: '- ', ol: '1. ', task: '- [ ] ', quote: '> ' }[arg] || '';
      var nb = block.split('\n').map(function (l) {
        return pre + l.replace(/^(\s*(?:#{1,6}\s|[-*+]\s|\d+[.)]\s|>\s?|- \[[ x]\]\s))/, '');
      }).join('\n');
      v = v.slice(0, ls) + nb + v.slice(le);
      b.value = v; b.selectionStart = ls; b.selectionEnd = ls + nb.length;
    } else if (mode === 'code') {
      var c = '```\n' + (sel || '') + '\n```';
      v = v.slice(0, s) + c + v.slice(e);
      b.value = v; b.selectionStart = s + 4; b.selectionEnd = s + 4 + (sel || '').length;
    } else if (mode === 'table') {
      var tb = '| 列 A | 列 B |\n| --- | --- |\n| 1 | 2 |';
      v = v.slice(0, s) + tb + v.slice(e);
      b.value = v; b.selectionStart = s; b.selectionEnd = s + tb.length;
    } else if (mode === 'link' || mode === 'image') {
      var pre2 = mode === 'image' ? '![' : '[';
      var txt = mode === 'image' ? (sel || '图片说明') : (sel || '链接文字');
      var ins = pre2 + txt + '](' + (arg || 'https://') + ')';
      v = v.slice(0, s) + ins + v.slice(e);
      b.value = v; b.selectionStart = s + ins.length; b.selectionEnd = s + ins.length;
    }
    b.focus(); onInput();
    return true;
  }
  function applyCmd(cmd, arg) {
    var b = bodyEl(); if (!b) { return; }
    pushHist();
    if (S.mode === 'html') {
      b.focus();
      try {
        if (cmd === 'hilite') {
          var sel = window.getSelection();
          if (sel && sel.rangeCount && !sel.isCollapsed) {
            var r = sel.getRangeAt(0), mk = document.createElement('mark');
            try { r.surroundContents(mk); } catch (e2) { mk.appendChild(r.extractContents()); r.insertNode(mk); }
            sel.removeAllRanges();
          } else { document.execCommand('insertHTML', false, '<mark>高亮</mark>'); }
        } else if (cmd === 'taskList') {
          document.execCommand('insertHTML', false, '<ul class="lqn-task"><li>☐ 任务一</li><li>☐ 任务二</li></ul><p></p>');
        } else if (cmd === 'codeBlock') {
          document.execCommand('insertHTML', false, '<pre><code>代码</code></pre><p></p>');
        } else if (cmd === 'table') {
          document.execCommand('insertHTML', false,
            '<table><thead><tr><th>列 A</th><th>列 B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table><p></p>');
        } else if (cmd === 'link') {
          var s2 = String(window.getSelection ? window.getSelection().toString() : '');
          document.execCommand('insertHTML', false, '<a href="' + esc(arg || 'https://') + '" rel="noopener nofollow" target="_blank">' + esc(s2 || arg || 'https://') + '</a>');
        } else if (cmd === 'image') {
          document.execCommand('insertHTML', false, '<img src="' + esc(arg || 'https://') + '" alt="图片">');
        } else {
          document.execCommand(cmd, false, arg || null);
        }
      } catch (err) { }
      onInput();
      return;
    }
    if (cmd === 'formatBlock') { mdBlock('line', String(arg || 'h2').toLowerCase()); return; }
    var map = { bold: ['wrap', 'bold'], italic: ['wrap', 'italic'], strikeThrough: ['wrap', 'strike'], hilite: ['wrap', 'mark'],
      insertUnorderedList: ['line', 'ul'], insertOrderedList: ['line', 'ol'], taskList: ['line', 'task'],
      codeBlock: ['code'], table: ['table'], link: ['link', arg], image: ['image', arg] };
    if (map[cmd]) { mdBlock(map[cmd][0], map[cmd][1]); }
  }
  /* 链接/图片：页内内联表单（不用 window.prompt —— 原生对话框会阻塞自动化验收） */
  var pendingCmd = null;
  function openUrlForm(cmd) {
    var box = $('[data-lqn="urlform"]'); if (!box) { return; }
    pendingCmd = cmd;
    box.removeAttribute('hidden');
    var lab = $('[data-lqn="urlform-label"]');
    if (lab) { lab.textContent = cmd === 'image' ? '图片外链地址' : '链接地址'; }
    var inp = $('[data-lqn="urlform-input"]');
    if (inp) { inp.value = 'https://'; inp.focus(); inp.select(); }
  }
  function urlFormSubmit() {
    var box = $('[data-lqn="urlform"]'), inp = $('[data-lqn="urlform-input"]');
    if (!box || !inp) { return; }
    var v = trim(inp.value, 500);
    box.setAttribute('hidden', '');
    if (!v) { return; }
    var c = pendingCmd; pendingCmd = null;
    applyCmd(c, v);
  }
  /* 暗色模式：与站点同一套机制（<html>.dark）+ 同名键 llq_md_dark + 站点键 yecha-dark */
  function applyDark() {
    var d = document.documentElement;
    if (d && d.classList) { d.classList.toggle('dark', S.dark); }
  }
  function toggleDark() {
    S.dark = !S.dark;
    applyDark();
    try { localStorage.setItem(K.dark, S.dark ? '1' : '0'); } catch (e) {}
    try { localStorage.setItem(K.siteDark, S.dark ? '1' : '0'); } catch (e) {}
    render();
  }
  function printNote() {
    var n = active();
    if (!n) { toast('先新建或选择一篇笔记'); return; }
    pullFromDom(n);
    var pv = $('[data-lqn="preview"]');
    if (pv) { pv.innerHTML = n.fmt === 'html' ? sanitizeHtml(n.body) : mdToHtml(n.body); pv.removeAttribute('hidden'); }
    document.body.setAttribute('data-lqn-print', '1');
    setTimeout(function () { try { window.print(); } catch (e) {} document.body.removeAttribute('data-lqn-print'); }, 80);
  }
  /* 模板清单（6 条，逐字照抄参照） */
  function renderTemplates() {
    var box = $('[data-lqn="tpls"]');
    if (!box) { return; }
    box.innerHTML = TEMPLATES.map(function (t) {
      return '<button type="button" data-lqn="tpl" data-tpl="' + esc(t.id) + '" class="lqn-tb" title="' + esc(t.name) + '">' + esc(t.name) + '</button>';
    }).join('');
  }
  function useTemplate(id) {
    var t = null;
    for (var i = 0; i < TEMPLATES.length; i++) { if (TEMPLATES[i].id === id) { t = TEMPLATES[i]; } }
    if (!t) { return; }
    newNote('', t.html, '');
    var p = $('[data-lqn="tpl-panel"]'); if (p) { p.setAttribute('hidden', ''); }
    toast('已新建笔记' + (t.html ? '（模板：' + t.name + '）' : ''));
  }

  /* ================================================================================
   * 历史版本（批4）
   * 文案逐字照参照：标题 `历史版本 · {标题}` · 空态 `暂无历史版本（自动保存时记录，最多 20 条）`
   *                · 每行 `恢复此版本` · 底部条按钮 `版本` · 上限 20
   * 存储：本机 `llq_md_history_v1`（与参照同名同语义，自动保存时记录）
   *       + 登录后同时落服务端（换设备也能回溯），面板把两边合并按时间倒排
   * 对比：另加一个「对比」按钮（参照面板只有恢复）——差异呈现照参照「文本对比」工具的话术
   *       `逐行对比两段文本，红色为删除、绿色为新增。`
   * ============================================================================== */
  function histAll() { var a = jget(K.hist, []); return Array.isArray(a) ? a : []; }
  function histOf(n) { return histAll().filter(function (x) { return x && x.ck === n.ck; }); }
  function histPush(n) {
    if (!n || !n.body) { return; }
    var all = histAll(), arr = all.filter(function (x) { return x && x.ck === n.ck; });
    if (arr.length && arr[0].body === n.body) { return; }          // 与最新一条同 ⇒ 不重复记
    all.unshift({ ck: n.ck, ts: Date.now(), title: n.title || '', body: n.body, fmt: n.fmt || 'md' });
    var cnt = {}, out = [];
    for (var i = 0; i < all.length; i++) {
      var c = (all[i] && all[i].ck) || '';
      cnt[c] = (cnt[c] || 0) + 1;
      if (cnt[c] <= 20) { out.push(all[i]); }                       // ★ 最多 20 条（照参照）
    }
    jset(K.hist, out);
  }
  function revModal() { return $('[data-lqn="rev-modal"]'); }
  function openRevs() {
    var n = active(); if (!n) { return; }
    pullFromDom(n);
    var m = revModal(); if (!m) { return; }
    m.removeAttribute('hidden');
    var t = $('[data-lqn="rev-title"]');
    if (t) { t.textContent = '历史版本 · ' + (n.title || '无标题'); }
    S.revs = [];
    revRender();
    if (S.user.uid && n.sid) {
      api('rev_list', { id: n.sid }).then(function (j) {
        if (j && j.ok) { S.revSrv = j.revs || []; revRender(); }
      }).catch(function () {});
    }
  }
  function closeRevs() { var m = revModal(); if (m) { m.setAttribute('hidden', ''); } }
  function revRender() {
    var n = active(); if (!n) { return; }
    var box = $('[data-lqn="rev-list"]'); if (!box) { return; }
    var srv = (S.revSrv || []).map(function (r) {
      return { kind: 'srv', rid: r.rid, at: r.at, ts: Date.parse(String(r.at).replace(' ', 'T')) || 0, words: r.words, title: r.title, fmt: r.fmt };
    });
    var loc = histOf(n).map(function (x) {
      return { kind: 'local', at: new Date(x.ts).toLocaleString('zh-CN'), ts: x.ts,
               words: wordCount({ body: x.body, fmt: x.fmt }), title: x.title, body: x.body, fmt: x.fmt };
    });
    var all = srv.concat(loc).sort(function (a, b) { return (b.ts || 0) - (a.ts || 0); }).slice(0, 20);
    S.revs = all;
    if (!all.length) {
      box.innerHTML = '<p class="lqn-help">暂无历史版本（自动保存时记录，最多 20 条）</p>';
      var pv = $('[data-lqn="rev-cmp-out"]'); if (pv) { pv.innerHTML = ''; }
      return;
    }
    box.innerHTML = all.map(function (r, i) {
      return '<div class="lqn-rev-row">'
        + '<span class="lqn-rev-at">' + esc(r.at) + (r.kind === 'srv' ? ' · 云端' : ' · 本机') + '</span>'
        + '<span class="lqn-rev-w">' + (r.words || 0) + ' 字</span>'
        + '<span class="lqn-rev-acts">'
        + '<button type="button" data-lqn="rev-cmp" data-rev="' + i + '" class="lqn-btn">对比</button>'
        + '<button type="button" data-lqn="rev-restore" data-rev="' + i + '" class="lqn-btn lqn-btn-primary">恢复此版本</button>'
        + '</span></div>';
    }).join('');
  }
  function revFetch(r) {
    if (r.kind === 'local') { return Promise.resolve({ title: r.title, body: r.body, fmt: r.fmt }); }
    return api('rev_get', { rid: r.rid }).then(function (j) { return (j && j.ok && j.rev) ? j.rev : null; });
  }
  /** 行级 LCS 对比（HTML 正文先转 Markdown 再逐行比，避免标签噪声） */
  function lineDiff(a, b) {
    var A = String(a == null ? '' : a).split('\n'), B = String(b == null ? '' : b).split('\n');
    var n = A.length, m = B.length, i, j, dp = [];
    for (i = 0; i <= n; i++) { var row = []; for (j = 0; j <= m; j++) { row.push(0); } dp.push(row); }
    for (i = n - 1; i >= 0; i--) { for (j = m - 1; j >= 0; j--) { dp[i][j] = (A[i] === B[j]) ? (dp[i + 1][j + 1] + 1) : Math.max(dp[i + 1][j], dp[i][j + 1]); } }
    var out = []; i = 0; j = 0;
    while (i < n && j < m) {
      if (A[i] === B[j]) { out.push({ t: 'same', s: A[i] }); i++; j++; }
      else if (dp[i + 1][j] >= dp[i][j + 1]) { out.push({ t: 'del', s: A[i] }); i++; }
      else { out.push({ t: 'add', s: B[j] }); j++; }
    }
    while (i < n) { out.push({ t: 'del', s: A[i++] }); }
    while (j < m) { out.push({ t: 'add', s: B[j++] }); }
    return out;
  }
  function doRevCompare(i) {
    var n = active(); var r = (S.revs || [])[i];
    if (!n || !r) { return; }
    pullFromDom(n);
    revFetch(r).then(function (rev) {
      var out = $('[data-lqn="rev-cmp-out"]'); if (!out) { return; }
      if (!rev) { out.innerHTML = '<p class="lqn-help">读取该版本失败</p>'; return; }
      var cur = n.fmt === 'html' ? htmlToMd(n.body) : n.body;
      var old = String(rev.fmt) === 'html' ? htmlToMd(String(rev.body)) : String(rev.body);
      var d = lineDiff(old, cur), del = 0, add = 0;
      var html = d.map(function (x) {
        if (x.t === 'del') { del++; return '<div class="lqn-df lqn-df-del">- ' + esc(x.s) + '</div>'; }
        if (x.t === 'add') { add++; return '<div class="lqn-df lqn-df-add">+ ' + esc(x.s) + '</div>'; }
        return '<div class="lqn-df">  ' + esc(x.s) + '</div>';
      }).join('');
      out.innerHTML = '<p class="lqn-help">逐行对比两段文本，红色为删除、绿色为新增。（该版本 → 当前：删 ' + del + ' 行 / 增 ' + add + ' 行）</p>'
        + '<div class="lqn-df-box">' + html + '</div>';
    }).catch(function () { });
  }
  function doRevRestore(i) {
    var n = active(); var r = (S.revs || [])[i];
    if (!n || !r) { return; }
    function applied(rev) {
      if (!rev) { toast('读取该版本失败'); return; }
      histPush(n);                                   // ★ 恢复前把"当前"也留在本机历史里 ⇒ 旧版不丢
      n.title = rev.title || n.title;
      n.body = String(rev.body == null ? '' : rev.body);
      n.fmt = String(rev.fmt) === 'html' ? 'html' : 'md';
      n.dirty = true; n.updated = Date.now();
      persist();
      /* ★ skipPull=true：DOM 里还是"恢复前"的正文，回读会把刚恢复的正文覆盖掉（与 newNote 同族，实测踩过） */
      setMode(n.fmt === 'html' ? 'html' : 'md', true);
      render();
      if (S.user.uid && n.sid) { serverSave(n, true); }   // 恢复到云端，多设备一致
      closeRevs(); toast('已恢复此版本');
    }
    if (r.kind !== 'srv') { revFetch(r).then(applied); return; }
    api('rev_restore', { id: n.sid, rid: r.rid }).then(function (j) {
      if (!j || !j.ok) { toast('恢复失败'); return; }
      var before = { title: n.title, body: n.body, fmt: n.fmt };
      histPush(before);
      mergeFromServer(j);
      var a = active();
      if (a) { a.dirty = false; }
      /* ★ skipPull=true：同上——DOM 里还是旧正文，不能回读 */
      setMode(a && a.fmt === 'md' ? 'md' : 'html', true);
      render();
      closeRevs(); toast('已恢复此版本');
    }).catch(function () { toast('恢复失败'); });
  }

  /* ================================================================================
   * 批5b · 本机加密导出 / 导入（方案 ⒞）
   * 硬约束：**零服务端密钥 · 零 core 改动 · 零数据丢失风险**（导出只读、导入只追加）
   * 判据：导出件只能被本机口令解开（错口令必须失败）· 导入后正文逐字节一致 · 零外发 · 不上传任何密钥
   * 算法：PBKDF2-SHA256(口令, 16B salt, 200k) → AES-256-GCM(12B iv)；文件 = 明文头 + base64 密文
   * 说明：口令与密钥**只在本机内存**里用，**没有任何网络请求**；口令/文件丢了都不可恢复（UI 已写明）
   * ============================================================================== */
  function b64enc(buf) { var b = new Uint8Array(buf), s = '', i; for (i = 0; i < b.length; i++) { s += String.fromCharCode(b[i]); } return btoa(s); }
  function b64dec(str) { var bin = atob(String(str)), out = new Uint8Array(bin.length), i; for (i = 0; i < bin.length; i++) { out[i] = bin.charCodeAt(i); } return out; }
  function encSubtle() { return (window.crypto && window.crypto.subtle) ? window.crypto.subtle : null; }
  function encSay(m) { var s = $('[data-lqn="enc-stat"]'); if (s) { s.textContent = m; } }
  function encDerive(pw, salt, iter) {
    var st = encSubtle();
    return st.importKey('raw', new TextEncoder().encode(pw), 'PBKDF2', false, ['deriveKey']).then(function (base) {
      return st.deriveKey({ name: 'PBKDF2', salt: salt, iterations: iter, hash: 'SHA-256' }, base,
        { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
    });
  }
  function encToggle() {
    var p = $('[data-lqn="enc-panel"]'); if (!p) { return; }
    if (p.hasAttribute('hidden')) { p.removeAttribute('hidden'); } else { p.setAttribute('hidden', ''); }
  }
  function encExport() {
    var st = encSubtle();
    if (!st) { encSay('这台浏览器不支持 WebCrypto ⇒ 本机加密导出不可用（未做任何改动）'); return; }
    var pw = (($('[data-lqn="enc-pw"]') || {}).value) || '';
    var pw2 = (($('[data-lqn="enc-pw2"]') || {}).value) || '';
    if (pw.length < 6) { encSay('口令至少 6 位（口令只在本机使用，不会上传、也不会保存）'); return; }
    if (pw !== pw2) { encSay('两次口令不一致'); return; }
    var n = active(); if (n) { pullFromDom(n); }
    var payload = {
      app: 'liulanqi-notes', v: 1, exported_at: new Date().toISOString(), count: S.notes.length,
      notes: S.notes.map(function (x) {
        return { title: x.title || '', body: x.body || '', fmt: x.fmt === 'html' ? 'html' : 'md',
                 book: x.book || '', status: x.status || 'normal' };
      })
    };
    var salt = crypto.getRandomValues(new Uint8Array(16));
    var iv = crypto.getRandomValues(new Uint8Array(12));
    var iter = 200000;
    encSay('正在本机加密…（导出失败不会动你的笔记）');
    encDerive(pw, salt, iter).then(function (key) {
      return st.encrypt({ name: 'AES-GCM', iv: iv }, key, new TextEncoder().encode(JSON.stringify(payload)));
    }).then(function (ct) {
      var out = { fmt: 'lqnenc', v: 1, alg: 'AES-256-GCM',
        kdf: { name: 'PBKDF2-SHA256', iter: iter, salt: b64enc(salt) }, iv: b64enc(iv), ct: b64enc(ct), notes: payload.count };
      dl('liulanqi-notes-' + new Date().toISOString().slice(0, 10) + '.lqnenc',
         JSON.stringify(out), 'application/json;charset=utf-8');
      encSay('已导出 ' + payload.count + ' 篇为加密文件（口令只在本机使用；口令或文件丢了都无法恢复）');
    }).catch(function () { encSay('加密失败，未导出（你的笔记没有改动）'); });
  }
  function encImportText(text) {
    var st = encSubtle();
    if (!st) { encSay('这台浏览器不支持 WebCrypto ⇒ 无法解密导入'); return; }
    var pw = (($('[data-lqn="enc-pw"]') || {}).value) || '';
    if (!pw) { encSay('请先在上面的「口令」里填上导出时用的口令，再选 .lqnenc 文件'); return; }
    var obj = null;
    try { obj = JSON.parse(String(text)); } catch (e) { encSay('这个文件不是 .lqnenc（JSON 解析失败）'); return; }
    if (!obj || obj.fmt !== 'lqnenc' || !obj.ct || !obj.kdf || !obj.iv || !obj.kdf.salt) { encSay('这个文件不是 .lqnenc 格式'); return; }
    var salt = b64dec(obj.kdf.salt), iv = b64dec(obj.iv), ct = b64dec(obj.ct);
    var iter = parseInt(obj.kdf.iter, 10) || 200000;
    encSay('正在本机解密…');
    encDerive(pw, salt, iter).then(function (key) {
      return st.decrypt({ name: 'AES-GCM', iv: iv }, key, ct);
    }).then(function (buf) {
      var payload = JSON.parse(new TextDecoder().decode(buf));
      var items = (payload && payload.notes) || [];
      if (!items.length) { encSay('文件里没有笔记'); return; }
      var at = Date.now(), added = 0;
      items.forEach(function (it, i) {
        if (!it || typeof it.body !== 'string') { return; }
        S.notes.push({ ck: ck(), sid: 0, title: trim(it.title || plain(it.body, 24), 200), body: it.body,
          fmt: it.fmt === 'html' ? 'html' : 'md', book: it.book || '', status: 'normal',
          dirty: true, su: '', updated: at - i });
        added++;
      });
      persist(); render();
      encSay('已从加密文件导入 ' + added + ' 篇（原有笔记一篇没动）');
      toast('已导入 ' + added + ' 篇（本机）');
    }).catch(function () { encSay('解密失败：口令不对，或文件被改过（你的笔记没有改动）'); });
  }

  /* ================================================================================
   * 批9-① · 端到端加密（照参照 ⒜）· 本机密钥 + 云端只存密文
   * 设计（零 schema / 零 core 改动）：密文 = 信封串 `LQNENC1:<b64 iv>:<b64 ct>`，直接存进现有 note.body
   *   ⇒ 服务端/数据库/日志只拿到信封串；历史快照快照的就是入库 body ⇒ 天然也是密文
   *   ⇒ 密钥：localStorage `llq_md_crypto_key`（32 字节 AES-256-GCM）· **永不上传**
   * 参照逐字：按钮 title `加密笔记（云端存密文）` · 文案 `加密已开启：此设备生成密钥，云端仅存密文（换设备需重新开启）`
   * ============================================================================== */
  var ENCP = 'LQNENC1:';
  var ENC_TIP = '加密已开启：此设备生成密钥，云端仅存密文（换设备需重新开启）';
  var ENC_NOKEY = '此笔记已加密；本机没有密钥，换设备需重新开启加密后才能阅读。';   // 自拟
  var ENC_NOSHARE = '加密笔记不支持分享（云端只有密文，分享出去谁也读不了）。';      // 自拟
  function encIs(s) { return typeof s === 'string' && s.indexOf(ENCP) === 0; }
  function encGet() { try { return localStorage.getItem('llq_md_crypto_key') || ''; } catch (e) { return ''; } }
  function encSetRaw(b) { try { localStorage.setItem('llq_md_crypto_key', b); } catch (e) { } }
  function encHas() { return encGet() !== ''; }
  function encGen() { var k = crypto.getRandomValues(new Uint8Array(32)), b = b64enc(k); encSetRaw(b); return b; }
  function encKey() {
    var b = encGet();
    if (!b) { return Promise.reject(new Error('nokey')); }
    var raw; try { raw = b64dec(b); } catch (e) { return Promise.reject(new Error('badkey')); }
    if (!raw || raw.length !== 32) { return Promise.reject(new Error('badkey')); }
    return crypto.subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
  }
  function encSeal(text) {
    return encKey().then(function (k) {
      var iv = crypto.getRandomValues(new Uint8Array(12));
      return crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv }, k, new TextEncoder().encode(String(text)))
        .then(function (ct) { return ENCP + b64enc(iv) + ':' + b64enc(ct); });
    });
  }
  function encOpen(env) {
    if (!encIs(env)) { return Promise.resolve(String(env || '')); }
    var parts = String(env).slice(ENCP.length).split(':');
    if (parts.length !== 2) { return Promise.reject(new Error('badenv')); }
    var iv = b64dec(parts[0]), ct = b64dec(parts[1]);
    return encKey().then(function (k) { return crypto.subtle.decrypt({ name: 'AES-GCM', iv: iv }, k, ct); })
      .then(function (buf) { return new TextDecoder().decode(buf); });
  }
  function curEnc() { var n = active(); return !!(n && (n.enc || encIs(n.body))); }
  /* ---- 批9-②：密钥面板（导出 / 导入）+ 首次开启【一次性确认】 ---- */
  var ENC_CONFIRM = '我已保存密钥、我明白换设备需重新开启';
  function keyPanel(show) {
    var p = $('[data-lqn="key-panel"]');
    if (!p) {
      p = document.createElement('div');
      p.setAttribute('data-lqn', 'key-panel');
      p.className = 'lqn-panel';
      p.innerHTML = '<p class="lqn-key-tip">' + ENC_TIP + '</p>'
        + '<p class="lqn-help">' + ENC_CONFIRM + '</p>'
        + '<p class="lqn-help" data-lqn="key-stat">密钥只在本机；导出件里<b>只有密钥、没有笔记</b>。换设备时把这个文件导入即可阅读已加密的笔记。</p>'
        + '<div class="lqn-keyacts">'
        + '<button type="button" data-lqn="key-start" class="lqn-btn lqn-btn-primary">' + ENC_CONFIRM + '</button>'
        + '<button type="button" data-lqn="key-out" class="lqn-btn">导出密钥文件</button>'
        + '<button type="button" data-lqn="key-in" class="lqn-btn">选择密钥文件导入</button>'
        + '<button type="button" data-lqn="key-close" class="lqn-btn">关闭</button>'
        + '<input type="file" data-lqn="keyfile" accept=".json,application/json" class="hidden">'
        + '</div>';
      var host = $('[data-lqn="bodywrap"]') ? $('[data-lqn="bodywrap"]').parentNode : document.body;
      host.insertBefore(p, host.firstChild);
      var st = document.createElement('style');
      st.textContent = '.lqn-key-tip{font-size:12.5px;line-height:1.8;margin:0 0 4px}'
        + '.lqn-keyacts{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}';
      document.head.appendChild(st);
      p.setAttribute('hidden', '');
    }
    if (show === true) { p.removeAttribute('hidden'); }
    else if (show === false) { p.setAttribute('hidden', ''); }
    else if (p.hasAttribute('hidden')) { p.removeAttribute('hidden'); } else { p.setAttribute('hidden', ''); }
  }
  function keyStat(html) { var s = $('[data-lqn="key-stat"]'); if (s) { s.innerHTML = html; } }
  /** 导出密钥：★ 只含密钥、不含任何笔记内容
   *  ★ 若本机还没有密钥 ⇒ 【先生成再导出】：否则"先导出、再确认"的顺序根本走不通
   *    （实测踩过：面板让用户先导出，但密钥要确认后才生成 ⇒ 导出无文件 ⇒ 跨设备整条链断）
   *    语义澄清：「密钥已存在」≠「已开启加密」——是否加密本篇仍由确认那一步决定。 */
  function keyExport() {
    var k = encGet();
    if (!k) { encGen(); k = encGet(); }
    if (!k) { toast('无法生成密钥（这台浏览器可能不支持 WebCrypto）'); return false; }
    dl('liulanqi-md-key-' + new Date().toISOString().slice(0, 10) + '.json',
      JSON.stringify({ fmt: 'llqkey', v: 1, alg: 'AES-256-GCM', key: k, created_at: new Date().toISOString() }),
      'application/json;charset=utf-8');
    keyStat('密钥已导出（<b>文件里只有密钥，没有笔记</b>）。请存到妥善的地方：丢了就再也解不开加密笔记。');
    toast('密钥已导出');
    return true;
  }
  function keyImportText(txt) {
    var j = null; try { j = JSON.parse(String(txt)); } catch (e) { j = null; }
    var k = (j && j.fmt === 'llqkey' && typeof j.key === 'string') ? j.key : '';
    var raw = null; try { raw = b64dec(k); } catch (e) { raw = null; }
    if (!k || !raw || raw.length !== 32) { keyStat('这个文件不是有效的密钥文件（应为 llqkey 格式）。'); toast('密钥文件无效'); return false; }
    encSetRaw(k);
    keyStat('密钥已导入 ⇒ 本机现在可以阅读已加密的笔记了。');
    toast('密钥已导入');
    S.encTried = {}; S.encOk = {};
    var n = active(); if (n) { n.enc = encIs(n.env || n.body) ? 1 : 0; }
    render(); setMode(S.mode, true); render();
    return true;
  }
  /** 一次性确认后的动作：生成密钥（并顺手给密钥文件）+ 加密当前篇 */
  function encStart() {
    var n = active(); if (!n) { return; }
    if (!encHas()) { encGen(); }
    keyExport();
    pullFromDom(n);
    var plain = String(active().body || '');
    encSeal(plain).then(function (env) {
      var a = active(); a.enc = 1; a.env = env; a.body = plain; a.dirty = true; a.updated = Date.now();
      S.encOk[a.ck] = 1; S.encLock = false;
      persist(); serverSave(a, true);
      S.notice = ENC_TIP; keyPanel(false);
      render(); toast('加密已开启（本篇已加密）');
    }).catch(function () { toast('加密失败'); });
  }
  function encToggleNote() {
    var n = active(); if (!n) { return; }
    if (!curEnc()) {
      if (!encHas()) {                       // ★ 首次开启：先给一次性确认（确认后才开启；不做硬阻塞）
        keyPanel(true);
        keyStat('这台设备还没有密钥。<b>先点「导出密钥文件」把它存好</b>，再点上面那句确认。');
        return;
      }
      pullFromDom(n);
      var plain = String(active().body || '');
      encSeal(plain).then(function (env) {
        var a = active(); a.enc = 1; a.env = env; a.body = plain; a.dirty = true; a.updated = Date.now();
        S.encOk[a.ck] = 1; S.encLock = false;
        persist(); serverSave(a, true);
        S.notice = ENC_TIP;
        render(); toast('本篇已加密');
      }).catch(function () { toast('加密失败（这台浏览器可能不支持 WebCrypto）'); });
      return;
    }
    var env0 = active().env || active().body;
    encOpen(env0).then(function (plain) {
      var a = active(); a.enc = 0; a.env = ''; a.body = plain; a.dirty = true; a.updated = Date.now();
      S.encOk[a.ck] = 1; S.encLock = false;
      persist(); serverSave(a, true);
      S.notice = '本篇已解密（云端将改存明文）';
      render(); toast('已解密本篇');
    }).catch(function () { S.notice = ENC_NOKEY; render(); toast('本机没有密钥 ⇒ 读不了这篇'); });
  }
  function encShareBlocked() { if (!curEnc()) { return false; } toast(ENC_NOSHARE); S.notice = ENC_NOSHARE; render(); return true; }

  /* ---------------------------------------------------------------- 保存按钮三态 / 更多菜单（t30） */
  function setSaveState(st, txt) {
    S.saveState = st;
    var b = $('[data-lqn="save"]');
    if (!b) { return; }
    b.setAttribute('data-state', st);
    b.textContent = txt || '保存';
    if (st === 'saving' || st === 'idle') { b.setAttribute('disabled', ''); } else { b.removeAttribute('disabled'); }
    b.title = st === 'saving' ? '正在保存…'
      : (st === 'error' ? '保存失败，点这里重试（Ctrl+S）'
        : (st === 'idle' ? '没有改动' : '保存（Ctrl+S）'));
  }
  function refreshSaveBtn() {
    var n = active();
    if (!n) { setSaveState('idle', '无改动'); return; }
    if (S.saveState === 'saving') { return; }
    if (S.saveState === 'error') { return; }          /* 失败态留着，等用户点它重试 */
    if (n.dirty) { setSaveState('ready', '保存'); return; }
    if (S.saveState === 'saved') { setSaveState('saved', S.savedAt ? ('已保存 ' + S.savedAt) : '已保存'); return; }
    setSaveState('idle', S.savedAt ? ('已保存 ' + S.savedAt) : '无改动');
  }
  function moreMenu() { return $('[data-lqn="more-menu"]'); }
  function toggleMore(force) {
    var m = moreMenu(); if (!m) { return; }
    var show = (force === true) ? true : (force === false ? false : m.hasAttribute('hidden'));
    if (show) { m.removeAttribute('hidden'); } else { m.setAttribute('hidden', ''); }
    var b = $('[data-lqn="more"]');
    if (b) { b.setAttribute('aria-expanded', show ? 'true' : 'false'); }
  }

  /* ---------------------------------------------------------------- 事件绑定 */
  function bind() {
    document.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-lqn]') : null;
      if (!t) { return; }
      var k = t.getAttribute('data-lqn');
      if (k === 'new-note' || k === 'new-empty') { e.preventDefault(); newNote(); return; }
      if (k === 'save') { e.preventDefault(); saveNow(); return; }
      if (k === 'trash-btn') { e.preventDefault(); trashNote(); return; }
      if (k === 'del-item') { e.preventDefault(); trashNote(t.getAttribute('data-ck')); return; }
      if (k === 'more') { e.preventDefault(); toggleMore(); return; }
      if (k === 'trash') { e.preventDefault(); S.filter = TRASH; localStorage.setItem(K.filter, TRASH); S.active = ''; render(); return; }
      if (k === 'all') { e.preventDefault(); S.filter = ALL; localStorage.setItem(K.filter, ALL); render(); return; }
      if (k === 'newbook') { e.preventDefault(); bookForm('new'); return; }
      if (k === 'bookform-ok') { e.preventDefault(); bookFormSubmit(); return; }
      if (k === 'bookform-cancel') { e.preventDefault(); var bf = $('[data-lqn="bookform"]'); if (bf) { bf.setAttribute('hidden', ''); } return; }
      if (k === 'book-pick') { e.preventDefault(); var bp = t.closest('[data-book]'); S.filter = bp ? bp.getAttribute('data-book') : ALL; localStorage.setItem(K.filter, S.filter); render(); return; }
      if (k === 'book-rename') { e.preventDefault(); var br = t.closest('[data-book]'); bookForm('rename', br ? br.getAttribute('data-book') : ''); return; }
      if (k === 'book-del') { e.preventDefault(); var bd = t.closest('[data-book]'); delBook(bd ? bd.getAttribute('data-book') : ''); return; }
      if (k === 'item') { e.preventDefault(); S.active = t.getAttribute('data-ck'); localStorage.setItem(K.active, S.active); render(); return; }
      if (k === 'restore') { e.preventDefault(); restoreNote(t.getAttribute('data-ck')); return; }
      if (k === 'purge') { e.preventDefault(); purgeNote(t.getAttribute('data-ck')); return; }
      if (k === 'purge-trash') { e.preventDefault(); purgeTrash(); return; }
      if (k === 'mode-md') { e.preventDefault(); setMode('md'); return; }
      if (k === 'mode-html') { e.preventDefault(); setMode('html'); return; }
      if (k === 'src-toggle') { e.preventDefault(); setMode(S.mode === 'md' ? 'html' : 'md'); return; }
      if (k === 'preview-btn') { e.preventDefault(); togglePreview(); return; }
      if (k === 'undo') { e.preventDefault(); undo(); return; }
      if (k === 'redo') { e.preventDefault(); redo(); return; }
      if (k === 'dark') { e.preventDefault(); toggleDark(); return; }
      if (k === 'print') { e.preventDefault(); printNote(); return; }
      if (k === 'tpl') { e.preventDefault(); useTemplate(t.getAttribute('data-tpl')); return; }
      if (k === 'tpl-toggle') {
        e.preventDefault();
        var tp = $('[data-lqn="tpl-panel"]');
        if (tp) { if (tp.hasAttribute('hidden')) { tp.removeAttribute('hidden'); } else { tp.setAttribute('hidden', ''); } }
        return;
      }
      if (k === 'urlform-ok') { e.preventDefault(); urlFormSubmit(); return; }
      if (k === 'urlform-cancel') {
        e.preventDefault(); pendingCmd = null;
        var uf = $('[data-lqn="urlform"]'); if (uf) { uf.setAttribute('hidden', ''); }
        return;
      }
      if (k === 'export-md') { e.preventDefault(); exportMd(); return; }
      if (k === 'export-html') { e.preventDefault(); exportHtml(); return; }
      if (k === 'keypanel') { e.preventDefault(); keyPanel(); return; }
      if (k === 'key-out') { e.preventDefault(); keyExport(); return; }
      if (k === 'key-start') { e.preventDefault(); encStart(); return; }
      if (k === 'key-in') { e.preventDefault(); var kf = $('[data-lqn="keyfile"]'); if (kf) { kf.click(); } return; }
      if (k === 'key-close') { e.preventDefault(); keyPanel(false); return; }
      if (k === 'enc-note') { e.preventDefault(); encToggleNote(); return; }
      if (k === 'share') { if (encShareBlocked()) { return; } shareNote(); return; }
      if (k === 'share-do') { if (encShareBlocked()) { return; } doShare(); return; }
      if (k === 'share-copy') { e.preventDefault(); copyShare(); return; }
      if (k === 'share-off') { e.preventDefault(); toggleOffConfirm(); return; }
      if (k === 'share-confirm-yes') { e.preventDefault(); unshareNote(); return; }
      if (k === 'share-confirm-no') { e.preventDefault(); toggleOffConfirm(); return; }
      if (k === 'sh-tab-link') { e.preventDefault(); shTab('link'); return; }
      if (k === 'sh-tab-setting') { e.preventDefault(); shTab('setting'); return; }
      if (k === 'sh-tab-stat') { e.preventDefault(); shTab('stat'); return; }
      if (k === 'revs') { e.preventDefault(); openRevs(); return; }
      if (k === 'enc') { e.preventDefault(); encToggle(); return; }
      if (k === 'enc-do') { e.preventDefault(); encExport(); return; }
      if (k === 'enc-pick') { e.preventDefault(); var ef = $('[data-lqn="encfile"]'); if (ef) { ef.click(); } return; }
      if (k === 'rev-close') { e.preventDefault(); closeRevs(); return; }
      if (k === 'rev-cmp') { e.preventDefault(); doRevCompare(parseInt(t.getAttribute('data-rev'), 10)); return; }
      if (k === 'rev-restore') { e.preventDefault(); doRevRestore(parseInt(t.getAttribute('data-rev'), 10)); return; }
      if (k === 'import') { e.preventDefault(); var fi = $('[data-lqn="file"]'); if (fi) { fi.click(); } return; }
      if (k === 'sync') { e.preventDefault(); syncNow(true); return; }
      if (k === 'cmd') {
        e.preventDefault();
        var cmd = t.getAttribute('data-cmd'), arg = t.getAttribute('data-arg') || null;
        if (cmd === 'link' || cmd === 'image') { openUrlForm(cmd); return; }
        applyCmd(cmd, arg);
        return;
      }
    });
    document.addEventListener('input', function (e) {
      var t = e.target;
      if (!t || !t.getAttribute) { return; }
      var k = t.getAttribute('data-lqn');
      if (k === 'q') { S.q = String(t.value || ''); render(); return; }
      if (k === 'title' || k === 'body') { onInput(); }
    });
    document.addEventListener('change', function (e) {
      var t = e.target;
      if (t && t.getAttribute && t.getAttribute('data-lqn') === 'keyfile') {
        var kf = t.files && t.files[0];
        if (!kf) { return; }
        var kr = new FileReader();
        kr.onload = function () { keyImportText(String(kr.result || '')); };
        kr.onerror = function () { keyStat('读密钥文件失败'); };
        kr.readAsText(kf, 'utf-8');
        t.value = '';
        return;
      }
      if (t && t.getAttribute && t.getAttribute('data-lqn') === 'encfile') {
        var ef = t.files && t.files[0];
        if (!ef) { return; }
        var re = new FileReader();
        re.onload = function () { encImportText(String(re.result || '')); };
        re.onerror = function () { encSay('读文件失败'); };
        re.readAsText(ef, 'utf-8');
        t.value = '';
      }
      if (t && t.getAttribute && t.getAttribute('data-lqn') === 'file') {
        var f = t.files && t.files[0];
        if (!f) { return; }
        var rd = new FileReader();
        rd.onload = function () { importText(f.name, String(rd.result || '')); };
        rd.readAsText(f, 'utf-8');
        t.value = '';
      }
      if (t && t.getAttribute && t.getAttribute('data-lqn') === 'book-sel') {
        var n = active(); if (!n) { return; }
        n.book = t.value || ''; n.updated = Date.now(); n.dirty = true;
        persist();
        if (S.user.uid) { serverSave(n, true); }
        render();
      }
    });
    document.addEventListener('keydown', function (e) {
      var t = e.target;
      // ★ 参照唯一写明的快捷键：查看源码（Ctrl+/）
      /* ★ t30：Ctrl/Cmd+S = 点「保存」（同一接口），并阻止浏览器"另存为"默认 */
      if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); saveNow(); return; }
      if ((e.ctrlKey || e.metaKey) && (e.key === '/' || e.key === '?')) {
        e.preventDefault(); setMode(S.mode === 'md' ? 'html' : 'md'); return;
      }
      if (!t || !t.getAttribute) { return; }
      var tk = t.getAttribute('data-lqn');
      if (tk === 'bookform-input' && e.key === 'Enter') { e.preventDefault(); bookFormSubmit(); }
      if (tk === 'bookform-input' && e.key === 'Escape') { var bf = $('[data-lqn="bookform"]'); if (bf) { bf.setAttribute('hidden', ''); } }
      if (tk === 'urlform-input' && e.key === 'Enter') { e.preventDefault(); urlFormSubmit(); }
      if (tk === 'urlform-input' && e.key === 'Escape') {
        pendingCmd = null; var uf2 = $('[data-lqn="urlform"]'); if (uf2) { uf2.setAttribute('hidden', ''); }
      }
    });
    /* ★ t30：点「更多」菜单里的项 / 点菜单外 ⇒ 收起（注册在本函数最后 ⇒ 主点击处理先跑） */
    document.addEventListener('click', function (e) {
      var m = moreMenu(); if (!m || m.hasAttribute('hidden')) { return; }
      var w = e.target.closest ? e.target.closest('.lqn-more-wrap') : null;
      var item = e.target.closest ? e.target.closest('[data-lqn]') : null;
      if (!w || (item && item.getAttribute('data-lqn') !== 'more' && m.contains(item))) { m.setAttribute('hidden', ''); }
    });
    window.addEventListener('beforeunload', function () { persist(); });
  }

  /* ---------------------------------------------------------------- 启动 */
  function boot() {
    S.loaded = true;
    loadLocal();
    bind();
    applyDark();
    renderTemplates();
    var migrated = migrateLegacy();
    // ★ 参照行为：本机一条笔记都没有 ⇒ 自动建「欢迎使用云笔记」（标题与正文逐字照抄参照 bundle）
    if (!S.notes.length) {
      /* ★ t30：种下的「欢迎使用云笔记」标记 demo=1 —— 只留在本机，不随登录上传。
         为什么：每个新浏览器 profile 都会种一篇，登录后原实现会把它推上云 ⇒
         站主账号里攒出多篇同名「欢迎使用云笔记」（列表显得又乱又复杂）。 */
      S.notes.push({ ck: ck(), sid: 0, title: WELCOME.title, body: WELCOME.html, fmt: 'html',
        book: WELCOME.book, status: 'normal', demo: 1, dirty: true, su: '', updated: Date.now() });
      if (!S.books.some(function (b) { return b.name === WELCOME.book; })) { S.books.push({ id: 0, name: WELCOME.book }); }
      persist(); persistBooks();
    }
    if (S.active && !noteByCk(S.active)) { S.active = ''; }
    if (!S.active) {
      var first = listSorted()[0];
      if (first) { S.active = first.ck; localStorage.setItem(K.active, S.active); }
    }
    setMode(S.mode);
    render();
    api('me', {}).then(function (j) {
      if (!j || !j.ok) { return; }
      S.user = { uid: j.uid || 0, name: j.name || '' };
      S.csrf = j.csrf || '';
      S.MAXBODY = (j.limits && j.limits.body) || MAXBODY;
      render();
      if (S.user.uid) { syncNow(migrated > 0); }
    }).catch(function () { /* 离线：本机数据照常可用 */ });
    // 暴露一个只读自检句柄（给真浏览器验收用，不改行为）
    window.LQNOTES = {
      state: function () {
        var a = active();
        return { uid: S.user.uid, name: S.user.name, mode: S.mode, filter: S.filter, dark: !!S.dark,
          notes: S.notes.length, live: S.notes.filter(function (n) { return n.status !== 'trash'; }).length,
          trash: S.notes.filter(function (n) { return n.status === 'trash'; }).length,
          books: S.books.map(function (b) { return b.name; }), active: S.active,
          words: a ? wordCount(a) : 0, title: a ? a.title : '', fmt: a ? a.fmt : '',
          /* ★ 自证用：把内存里的笔记数组原样报出来（只读句柄，不改行为） */
          all: S.notes.map(function (x) {
            return x.ck + '|' + String(x.title || '').slice(0, 14) + '|' + String(x.body || '').length + '|' + (x.status || '');
          }),
          booksAll: S.books.map(function (b) { return (b.id || 0) + ':' + b.name; }),
          savedAt: S.savedAt || '',
          templates: TEMPLATES.map(function (t) { return t.name; }),
          notice: S.notice, csrf: S.csrf ? 1 : 0 };
      },
      keys: K
    };
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
