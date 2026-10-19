/* HeronTool 工具引擎 —— 全站 1000+ 工具共用，浏览器只下载一次
   由 tools/extract_engine.js 从 views/tool.njk 自动抽取，请勿手改此文件。
   页面通过 window.HT_CTX 注入 detail / tool_ui / slug / lang / ui / logged。 */
(function () {
  var DETAIL = (window.HT_CTX && window.HT_CTX.detail) || {};
  var UI = (window.HT_CTX && window.HT_CTX.tool_ui) || {};
  var SLUG = (window.HT_CTX && window.HT_CTX.slug) || '';
  var W = DETAIL.widget;
  var OP = DETAIL.op || '';
  var LANG = (window.HT_CTX && window.HT_CTX.lang) || 'en';
  var L10N = LANG === 'zh' ? {
    noMatch: '无匹配', validJson: '✓ 有效的 JSON', parseFail: '解析失败：', convertFail: '转换失败：',
    invalidUrl: '无效 URL', unknownMime: '未知 MIME', unknownStatus: '未知状态', needStatus: '请输入 3 位状态码',
    err: '错误：', chars: '字符：', noSpace: '不含空格：', words: '词：', lines: '行：', noHeading: '无标题',
    needUser: '请填写用户名', needCmd: '请输入 docker run 命令', needText: '请输入文本', noImage: '未找到镜像',
    needExpr: '请输入表达式', badExpr: '无法解析的表达式', badMoney: '无效金额', badId: '格式错误（需 18 位）',
    badIdCheck: '校验位错误（身份证无效）', validId: '有效身份证', unknown: '未知', badPerm: '格式错误（如 755）',
    badIp: 'IP 格式错误', badRange: '格式错误（如 192.168.1.1-192.168.1.10）', rangeTooBig: '范围过大（超过 10000 个地址）',
    badIban: '格式错误', validIban: '有效 IBAN', badIbanMod: '无效（MOD-97 不通过）', badCipher: '密文格式错误（应为 iv.cipher）',
    badB32: 'Base32 密钥无效', dimMismatch: '维度不匹配', thin: '偏瘦', normal: '正常', fat: '偏胖', obese: '肥胖',
    isPrime: '是质数', composite: '合数', outOfRange: '超出范围', calcErr: '错误', needOptions: '请先输入选项',
    heads: '正面', tails: '反面', strength: '强度：', veryWeak: '很弱', weak: '弱', mid: '中', strong: '强', veryStrong: '很强',
    arrayRequired: '需为数组', invalidTs: '无效时间戳', cannotParse: '无法解析', bcLib: '条码库未加载', bcFail: '生成失败：', pdfFail: '处理失败'
  } : {
    noMatch: 'No match', validJson: '✓ Valid JSON', parseFail: 'Parse error: ', convertFail: 'Conversion error: ',
    invalidUrl: 'Invalid URL', unknownMime: 'Unknown MIME', unknownStatus: 'Unknown status', needStatus: 'Enter a 3-digit status code',
    err: 'Error: ', chars: 'Characters: ', noSpace: 'No spaces: ', words: 'Words: ', lines: 'Lines: ', noHeading: 'No headings',
    needUser: 'Enter a username', needCmd: 'Enter a docker run command', needText: 'Enter some text', noImage: 'No image found',
    needExpr: 'Enter an expression', badExpr: 'Cannot parse expression', badMoney: 'Invalid amount', badId: 'Invalid format (18 digits required)',
    badIdCheck: 'Checksum error (invalid ID)', validId: 'Valid ID', unknown: 'Unknown', badPerm: 'Invalid format (e.g. 755)',
    badIp: 'Invalid IP format', badRange: 'Invalid range (e.g. 192.168.1.1-192.168.1.10)', rangeTooBig: 'Range too large (over 10000 addresses)',
    badIban: 'Invalid format', validIban: 'Valid IBAN', badIbanMod: 'Invalid (MOD-97 check failed)', badCipher: 'Invalid ciphertext (expect iv.cipher)',
    badB32: 'Invalid Base32 secret', dimMismatch: 'Dimension mismatch', thin: 'Underweight', normal: 'Normal', fat: 'Overweight', obese: 'Obese',
    isPrime: 'Prime', composite: 'Composite', outOfRange: 'Out of range', calcErr: 'Error', needOptions: 'Enter some options first',
    heads: 'Heads', tails: 'Tails', strength: 'Strength: ', veryWeak: 'Very weak', weak: 'Weak', mid: 'Medium', strong: 'Strong', veryStrong: 'Very strong',
    arrayRequired: 'An array is required', invalidTs: 'Invalid timestamp', cannotParse: 'Cannot parse', bcLib: 'Barcode library not loaded', bcFail: 'Generate failed: ', pdfFail: 'Processing failed'
  };
  var UIX = (window.HT_CTX && window.HT_CTX.ui) || {};
  var toast = document.getElementById('toast');

  /* ---------- 登录态就绪后：注入反馈表单 + 拉取我的评论 ----------
     SSR 恒输出"请登录"，表单本体放在 <template> 里（文案仍是 SSR 输出），
     登录后克隆进插槽；评论是私有数据，异步拉取。
     这样工具页 HTML 与登录态完全解耦，可被 CDN 公共缓存。 */
  function onAuthReady(cb) {
    if (window.HT_AUTH_READY) { cb(window.HT_LOGGED === true); return; }
    document.addEventListener('ht:auth', function (e) { cb(!!(e && e.detail && e.detail.logged)); });
  }
  onAuthReady(function (logged) {
    if (!logged) return;
    [['comment', 'tplFbComment'], ['correction', 'tplFbCorrection'], ['annotation', 'tplFbAnnotation']]
      .forEach(function (p) {
        var slot = document.querySelector('[data-fb-slot="' + p[0] + '"]');
        var tpl = document.getElementById(p[1]);
        if (slot && tpl && tpl.content) { slot.innerHTML = ''; slot.appendChild(tpl.content.cloneNode(true)); }
      });
    var cl = document.getElementById('cmtList');
    if (cl && cl.getAttribute('data-tool')) {
      fetch('/api/mycomments?tool=' + encodeURIComponent(cl.getAttribute('data-tool'))
        + '&cat=' + encodeURIComponent(cl.getAttribute('data-cat') || ''), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); }).then(function (j) {
          var items = (j && j.items) || [];
          cl.innerHTML = items.map(function (c) {
            var who = c.at ? new Date(c.at).toLocaleDateString() : '';
            return '<div class="cmt-item"><b>' + esc(who) + '</b> · <span>' + esc(c.text) + '</span></div>';
          }).join('');
        }).catch(function () { /* 拉取失败保持空列表，不影响工具使用 */ });
    }
  });
  function showToast(m) { if (!toast) return; toast.textContent = m; toast.classList.add('show'); setTimeout(function () { toast.classList.remove('show'); }, 2200); }

  /* 收藏按钮 */
  var favBtn = document.getElementById('toolFav');
  if (favBtn) {
    /* 登录态惰性判定：在点击时才读，而不是初始化时固化。
       window.HT_LOGGED 由 layout 的 /api/me 异步写入（HTML 本身不含登录态，
       这样工具页可被 CDN 公共缓存，不会把 A 的登录状态渲染进 B 的页面）。 */
    favBtn.addEventListener('click', function () {
      var isLogged = window.HT_LOGGED === true || document.body.getAttribute('data-logged') === '1';
      if (!isLogged) {
        showToast(UIX.login_required || 'login');
        setTimeout(function () { location.href = '/' + LANG + '/login/?next=' + encodeURIComponent(location.pathname); }, 700);
        return;
      }
      fetch('/' + LANG + '/user/favorites/toggle', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'tool=' + encodeURIComponent(favBtn.dataset.tool) + '&cat=' + encodeURIComponent(favBtn.dataset.cat)
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (j.ok) {
          favBtn.classList.toggle('on', j.fav);
          var svg = favBtn.querySelector('svg');
          if (svg) svg.setAttribute('fill', j.fav ? 'currentColor' : 'none');
          var sp = favBtn.querySelector('span');
          if (sp) sp.textContent = j.fav ? UIX.fav_btn_on : UIX.fav_btn;
        }
      });
    });
  }

  /* 反馈 tab 切换 */
  document.querySelectorAll('.fb-tab').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.fb-tab').forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
      document.querySelectorAll('.fb-panel').forEach(function (p) {
        p.classList.toggle('show', p.getAttribute('data-fb-panel') === b.getAttribute('data-fb'));
      });
    });
  });
  function copyText(t) { if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(function () { showToast(UI.copy_done); }, function () { showToast(UI.copy_fail); }); } else { showToast(UI.copy_fail); } }
  function $(id) { return document.getElementById(id); }
  function val(id) { var e = $(id); return e ? e.value : ''; }
  function toLines(t) { return t === '' ? [] : t.split(/\r\n|\r|\n/); }
  function toTitle(t) { return t.replace(/[A-Za-z]\S*/g, function (w) { return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase(); }); }
  function toSentence(t) { return t.replace(/(^|[.!?]\s+)([a-z])/g, function (m, p1, p2) { return p1 + p2.toUpperCase(); }); }
  function htmlToText(h) { try { var d = new DOMParser().parseFromString(h, 'text/html'); return (d.body.textContent || '').replace(/\n{3,}/g, '\n\n').trim(); } catch (e) { return h.replace(/<[^>]*>/g, ''); } }
  function esc(s) { return String(s).replace(/[&<>]/g, function (c) { return c === '&' ? '&amp;' : c === '<' ? '&lt;' : '&gt;'; }); }
  function gcd(a, b) { a = Math.abs(a); b = Math.abs(b); while (b) { var t = b; b = a % b; a = t; } return a || 1; }
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
  function zp(n) { return (n < 10 ? '0' + n : '' + n); }
  function hexToRgb(h) { h = (h || '').replace('#', ''); if (h.length === 3) h = h.split('').map(function (x) { return x + x; }).join(''); var n = parseInt(h, 16); if (isNaN(n)) return null; return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; }
  function rgbToHex(r, g, b) { return '#' + [r, g, b].map(function (x) { return ('0' + clamp(Math.round(x), 0, 255).toString(16)).slice(-2); }).join('').toLowerCase(); }
  function rgbToHsl(r, g, b) { r /= 255; g /= 255; b /= 255; var mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn, h = 0, s = 0, l = (mx + mn) / 2; if (d) { s = l > .5 ? d / (2 - mx - mn) : d / (mx + mn); if (mx === r) h = (g - b) / d + (g < b ? 6 : 0); else if (mx === g) h = (b - r) / d + 2; else h = (r - g) / d + 4; h *= 60; } return [h, s * 100, l * 100]; }
  function hslToRgb(h, s, l) { h = (h % 360 + 360) % 360 / 360; s /= 100; l /= 100; var m; if (s === 0) { m = Math.round(l * 255); return [m, m, m]; } var q = l < .5 ? l * (1 + s) : l + s - l * s; var p = 2 * l - q; function h2(c) { c = (c + 1) % 1; if (c < 1 / 6) return p + (q - p) * 6 * c; if (c < 1 / 2) return q; if (c < 2 / 3) return p + (q - p) * (2 / 3 - c) * 6; return p; } return [Math.round(h2(h) * 255), Math.round(h2(h + 1 / 3) * 255), Math.round(h2(h + 2 / 3) * 255)]; }
  function shiftHue(hex, deg) { var rgb = hexToRgb(hex); if (!rgb) return hex; var hsl = rgbToHsl(rgb[0], rgb[1], rgb[2]); return rgbToHex.apply(null, hslToRgb(hsl[0] + deg, hsl[1], hsl[2])); }
  function isPrime(n) { if (n < 2) return false; if (n < 4) return true; if (n % 2 === 0) return false; for (var i = 3; i * i <= n; i += 2) if (n % i === 0) return false; return true; }
  function nextPrime(n) { var k = n + 1; while (!isPrime(k)) k++; return k; }
  function prevPrime(n) { var k = n - 1; while (k > 1 && !isPrime(k)) k--; return k < 2 ? null : k; }

  // =================== IO ===================
  var IO_LEGACY = { 'case-converter': 1, 'text-deduplicator': 1, 'text-sorter': 1, 'text-replacer': 1, 'html-to-text': 1, 'json-formatter': 1, 'html-encoder': 1, 'url-encoder-decoder': 1, 'base64-encoder-decoder': 1, 'keyword-density-checker': 1, 'readability-analyzer': 1 };
  var FULL = { '!': '！', '"': '＂', '#': '＃', '$': '＄', '%': '％', '&': '＆', "'": '＇', '(': '（', ')': '）', '*': '＊', '+': '＋', ',': '，', '-': '－', '.': '．', '/': '／', '0': '０', '1': '１', '2': '２', '3': '３', '4': '４', '5': '５', '6': '６', '7': '７', '8': '８', '9': '９', ':': '：', ';': '；', '<': '＜', '=': '＝', '>': '＞', '?': '？', '@': '＠', 'A': 'Ａ', 'B': 'Ｂ', 'C': 'Ｃ', 'D': 'Ｄ', 'E': 'Ｅ', 'F': 'Ｆ', 'G': 'Ｇ', 'H': 'Ｈ', 'I': 'Ｉ', 'J': 'Ｊ', 'K': 'Ｋ', 'L': 'Ｌ', 'M': 'Ｍ', 'N': 'Ｎ', 'O': 'Ｏ', 'P': 'Ｐ', 'Q': 'Ｑ', 'R': 'Ｒ', 'S': 'Ｓ', 'T': 'Ｔ', 'U': 'Ｕ', 'V': 'Ｖ', 'W': 'Ｗ', 'X': 'Ｘ', 'Y': 'Ｙ', 'Z': 'Ｚ', '[': '［', '\\': '＼', ']': '］', '^': '＾', '_': '＿', '`': '｀', 'a': 'ａ', 'b': 'ｂ', 'c': 'ｃ', 'd': 'ｄ', 'e': 'ｅ', 'f': 'ｆ', 'g': 'ｇ', 'h': 'ｈ', 'i': 'ｉ', 'j': 'ｊ', 'k': 'ｋ', 'l': 'ｌ', 'm': 'ｍ', 'n': 'ｎ', 'o': 'ｏ', 'p': 'ｐ', 'q': 'ｑ', 'r': 'ｒ', 's': 'ｓ', 't': 'ｔ', 'u': 'ｕ', 'v': 'ｖ', 'w': 'ｗ', 'x': 'ｘ', 'y': 'ｙ', 'z': 'ｚ', '{': '｛', '|': '｜', '}': '｝', '~': '～' };
  var HALF = {}; Object.keys(FULL).forEach(function (k) { HALF[FULL[k]] = k; });
  function halfToFull(t) { return t.replace(/[!-~]/g, function (c) { return FULL[c] || c; }); }
  function fullToHalf(t) { return t.replace(/[！-～]/g, function (c) { return HALF[c] || c; }); }
  function prettyXml(t) { try { var xml = t.replace(/>\s*</g, '>\n<'); var out = '', depth = 0; xml.split('\n').forEach(function (line) { var lt = (line.match(/</g) || []).length, gt = (line.match(/>/g) || []).length; if (line.match(/^<\/\w/) || (gt > lt && !line.match(/^<\w/))) depth--; out += '  '.repeat(Math.max(0, depth)) + line + '\n'; if (line.match(/<[^/!?].*>/) && !line.match(/.*<\/.*>/) && !line.match(/\/>/)) depth++; }); return out.trim(); } catch (e) { return t; } }
  function naiveIndentHtml(t) { var out = '', d = 0; t.replace(/>\s*</g, '>\n<').split('\n').forEach(function (l) { if (/^<\//.test(l)) d--; out += '  '.repeat(Math.max(0, d)) + l.trim() + '\n'; if (/^<[^/!]/.test(l) && !/<\/$/.test(l) && !/\/>$/.test(l)) d++; }); return out; }
  function minifyCss(t) { return t.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s*([{}:;,])\s*/g, '$1').replace(/;}/g, '}').replace(/\n/g, '').trim(); }
  function minifyHtml(t) { return t.replace(/<!--[\s\S]*?-->/g, '').replace(/>\s+</g, '><').replace(/\s{2,}/g, ' ').trim(); }
  function minifyJs(t) { var out = '', i = 0, inS = 0, ch; while (i < t.length) { ch = t[i]; if (inS) { out += ch; if (ch === '\\') { out += t[i + 1]; i += 2; continue; } if (ch === (inS === 1 ? '"' : inS === 2 ? "'" : '`')) inS = 0; i++; continue; } if (ch === '"' || ch === "'" || ch === '`') { inS = ch === '"' ? 1 : ch === "'" ? 2 : 3; out += ch; i++; continue; } if (ch === '/' && t[i + 1] === '/') { while (i < t.length && t[i] !== '\n') i++; continue; } if (ch === '/' && t[i + 1] === '*') { i += 2; while (i < t.length && !(t[i] === '*' && t[i + 1] === '/')) i++; i += 2; continue; } out += ch; i++; } return out.replace(/\s*([=+\-*/%<>{}:;,()\[\]&&||])\s*/g, '$1').replace(/\n/g, '').trim(); }
  function csvToJson(t) { var lines = toLines(t).filter(function (l) { return l.trim() !== ''; }); if (!lines.length) return '空'; var head = lines[0].split(',').map(function (h) { return h.trim(); }); var arr = lines.slice(1).map(function (l) { var cells = l.split(','); var o = {}; head.forEach(function (h, i) { o[h] = (cells[i] || '').trim(); }); return o; }); return JSON.stringify(arr, null, 2); }
  function jsonToCsv(t) { var o = JSON.parse(t); if (!Array.isArray(o)) throw new Error(L10N.arrayRequired); var head = Object.keys(o[0]); var rows = [head.join(',')]; o.forEach(function (r) { var cells = head.map(function (h) { var v = r[h] == null ? '' : r[h]; var s = JSON.stringify(v); return (s.length >= 2 && s.charAt(0) === '"' && s.charAt(s.length - 1) === '"') ? s.slice(1, -1) : s; }); rows.push(cells.join(',')); }); return rows.join('\n'); }
  function jsonPath(t, path) { var o = JSON.parse(t); var p = path.replace(/^\$/, '').replace(/\[(\d+)\]/g, '.$1'); if (p[0] === '.') p = p.slice(1); var cur = o; p.split('.').forEach(function (k) { if (k === '') return; cur = cur == null ? undefined : cur[k]; }); return typeof cur === 'object' ? JSON.stringify(cur, null, 2) : String(cur); }
  var MIME = { html: 'text/html', htm: 'text/html', css: 'text/css', js: 'application/javascript', json: 'application/json', xml: 'application/xml', txt: 'text/plain', csv: 'text/csv', md: 'text/markdown', pdf: 'application/pdf', png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', svg: 'image/svg+xml', webp: 'image/webp', mp3: 'audio/mpeg', mp4: 'video/mp4', zip: 'application/zip', gz: 'application/gzip', ico: 'image/x-icon', woff: 'font/woff', woff2: 'font/woff2', ttf: 'font/ttf' };
  var HTTP = { 200: 'OK', 201: 'Created', 204: 'No Content', 301: 'Moved Permanently', 302: 'Found', 304: 'Not Modified', 400: 'Bad Request', 401: 'Unauthorized', 403: 'Forbidden', 404: 'Not Found', 405: 'Method Not Allowed', 500: 'Internal Server Error', 502: 'Bad Gateway', 503: 'Service Unavailable' };
  var IO_FUNCS = {
    upper: function (t) { return t.toUpperCase(); }, lower: function (t) { return t.toLowerCase(); }, sentence: function (t) { return toSentence(t); },
    emptylines: function (t) { return toLines(t).filter(function (l) { return l.trim() !== ''; }).join('\n'); },
    numberlines: function (t, C) { var s = parseInt(C.fv('start'), 10) || 1; return toLines(t).map(function (l, i) { return (s + i) + '. ' + l; }).join('\n'); },
    unnumber: function (t) { return toLines(t).map(function (l) { return l.replace(/^\s*\d+[.)]\s+/, ''); }).join('\n'); },
    reverse: function (t) { return toLines(t).reverse().join('\n'); },
    shuffle: function (t) { var a = toLines(t), i, j, x; for (i = a.length - 1; i > 0; i--) { j = Math.floor(Math.random() * (i + 1)); x = a[i]; a[i] = a[j]; a[j] = x; } return a.join('\n'); },
    'extract-email': function (t) { var m = t.match(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g); return m ? m.join('\n') : L10N.noMatch; },
    'extract-url': function (t) { var m = t.match(/https?:\/\/[^\s)]+/g); return m ? m.join('\n') : L10N.noMatch; },
    'extract-link': function (t) { var set = {}, m; (t.match(/https?:\/\/[^\s)]+/g) || []).forEach(function (u) { set[u] = 1; }); var re = /\[[^\]]+\]\((https?:\/\/[^)]+)\)/g; while ((m = re.exec(t))) set[m[1]] = 1; var keys = Object.keys(set); return keys.length ? keys.join('\n') : '无匹配'; },
    'extract-phone': function (t) { var m = t.match(/(?:\+?\d[\d\s().-]{6,}\d)/g) || []; var u = {}; m.forEach(function (x) { u[x.trim()] = 1; }); var k = Object.keys(u); return k.length ? k.join('\n') : '无匹配'; },
    split: function (t, C) { return t.split(C.fv('dlm') || '\n').join('\n'); },
    merge: function (t, C) { return toLines(t).join(C.fv('dlm') || ', '); },
    affix: function (t, C) { var p = C.fv('pre') || '', s = C.fv('suf') || ''; return toLines(t).map(function (l) { return p + l + s; }).join('\n'); },
    quotes: function (t, C) { var a = C.act(); var q = a === 'single' ? ["'", "'"] : a === 'back' ? ['`', '`'] : ['"', '"']; return toLines(t).map(function (l) { return q[0] + l + q[1]; }).join('\n'); },
    halffull: function (t, C) { return (C.act() === 'tohalf' ? fullToHalf(t) : halfToFull(t)); },
    fullhalf: function (t, C) { return (C.act() === 'tohalf' ? fullToHalf(t) : halfToFull(t)); },
    wordfreq: function (t, C) { var n = parseInt(C.fv('topn'), 10) || 10; var cnt = {}; (t.match(/[一-鿿㐀-䶿]+|[A-Za-z0-9]+/g) || []).forEach(function (w) { w = w.toLowerCase(); cnt[w] = (cnt[w] || 0) + 1; }); return Object.keys(cnt).map(function (k) { return [k, cnt[k]]; }).sort(function (a, b) { return b[1] - a[1]; }).slice(0, n).map(function (x) { return x[0] + ': ' + x[1]; }).join('\n') || '空'; },
    repeat: function (t, C) { var n = clamp(parseInt(C.fv('n'), 10) || 1, 1, 1000); var a = []; for (var i = 0; i < n; i++) a.push(t); return a.join('\n'); },
    'json-validate': function (t) { try { JSON.parse(t); return L10N.validJson; } catch (e) { return '✗ ' + e.message; } },
    'json-minify': function (t) { try { return JSON.stringify(JSON.parse(t)); } catch (e) { return L10N.parseFail + e.message; } },
    'json2csv': function (t) { try { return jsonToCsv(t); } catch (e) { return L10N.convertFail + e.message; } },
    'csv2json': function (t) { try { return csvToJson(t); } catch (e) { return L10N.convertFail + e.message; } },
    'xml-format': function (t) { return prettyXml(t); },
    'xml2json': function (t) { try { return XML2JSON(t); } catch (e) { return prettyXml(t); } },
    'html-format': function (t) { return naiveIndentHtml(t); },
    'css-format': function (t) { return minifyCss(t).replace(/}/g, '}\n'); },
    'minify-js': function (t) { return minifyJs(t); },
    'minify-css': function (t) { return minifyCss(t); },
    'minify-html': function (t) { return minifyHtml(t); },
    'sql-format': function (t) { var kw = /\b(select|from|where|and|or|insert|into|values|update|set|delete|create|table|join|left|right|inner|outer|on|group|by|order|limit|as|distinct)\b/gi; return t.replace(kw, function (m) { return '\n' + m.toUpperCase(); }).replace(/\s+/g, ' ').trim(); },
    'url-parse': function (t) { try { var u = new URL(t.trim()); var q = {}; u.searchParams.forEach(function (v, k) { q[k] = v; }); return 'Protocol: ' + u.protocol + '\nHost: ' + u.host + '\nPath: ' + u.pathname + '\nQuery: ' + JSON.stringify(q) + '\nHash: ' + u.hash; } catch (e) { return L10N.invalidUrl; } },
    'ua-parse': function (t) { var ua = t.trim(), b = 'Unknown', o = 'Unknown', d = 'Unknown'; if (/Edg\//.test(ua)) b = 'Edge'; else if (/OPR\/|Opera/.test(ua)) b = 'Opera'; else if (/Chrome\//.test(ua)) b = 'Chrome'; else if (/Firefox\//.test(ua)) b = 'Firefox'; else if (/Safari\//.test(ua)) b = 'Safari'; if (/Windows NT 10/.test(ua)) o = 'Windows 10/11'; else if (/Windows/.test(ua)) o = 'Windows'; else if (/Mac OS X/.test(ua)) o = 'macOS'; else if (/Android/.test(ua)) o = 'Android'; else if (/iPhone|iPad/.test(ua)) o = 'iOS'; else if (/Linux/.test(ua)) o = 'Linux'; if (/Mobile/.test(ua)) d = 'Mobile'; else if (/Tablet/.test(ua)) d = 'Tablet'; else d = 'Desktop'; return 'Browser: ' + b + '\nOS: ' + o + '\nDevice: ' + d; },
    mime: function (t) { var ext = (t.trim().split('.').pop() || '').toLowerCase(); return ext + ' → ' + (MIME[ext] || L10N.unknownMime); },
    slug: function (t) { return t.trim().toLowerCase().replace(/[^a-z0-9一-鿿]+/g, '-').replace(/^-+|-+$/g, ''); },
    httpstatus: function (t) { var c = (t.trim().match(/\d{3}/) || [])[0]; return c ? c + ' ' + (HTTP[c] || L10N.unknownStatus) : L10N.needStatus; },
    jsonpath: function (t, C) { try { return jsonPath(t, C.fv('jp')); } catch (e) { return L10N.err + e.message; } },
    length: function (t) { var lines = toLines(t); var words = (t.match(/[一-鿿㐀-䶿]+|[A-Za-z0-9]+/g) || []).length; return L10N.chars + t.length + '\n' + L10N.noSpace + t.replace(/\s/g, '').length + '\n' + L10N.words + words + '\n' + L10N.lines + (t === '' ? 0 : lines.length); },
    checklist: function (t) { return toLines(t).map(function (l) { return '- [ ] ' + l; }).join('\n'); },
    headings: function (t) { var m = t.match(/^#{1,6}\s+.+$/gm); return m ? m.join('\n') : L10N.noHeading; },
    'json2yaml': function (t) { try { return jsyaml.dump(JSON.parse(t), { lineWidth: 120 }); } catch (e) { return L10N.parseFail + e.message; } },
    'yaml2json': function (t) { try { return JSON.stringify(jsyaml.load(t), null, 2); } catch (e) { return L10N.parseFail + e.message; } },
    'toml2json': function (t) { try { return JSON.stringify(Toml.parse(t), null, 2); } catch (e) { return L10N.parseFail + e.message; } },
    'json2toml': function (t) { try { return jsonToToml(JSON.parse(t)); } catch (e) { return L10N.parseFail + e.message; } },
    'json2xml': function (t) { try { return jsonToXml(JSON.parse(t), 'root'); } catch (e) { return L10N.parseFail + e.message; } },
    'json2ts': function (t) { try { return jsonToTs(JSON.parse(t), 'Root'); } catch (e) { return L10N.parseFail + e.message; } },
    'jsonview': function (t) { try { return jsonViewTree(JSON.parse(t), 0); } catch (e) { return L10N.parseFail + e.message; } },
    'tounicode': function (t) { var out = ''; for (var i = 0; i < t.length; i++) { var c = t.charCodeAt(i); out += c > 126 ? '\\u' + ('0000' + c.toString(16)).slice(-4) : t[i]; } return out; },
    'basicauth': function (t, C) { var u = C.fv('user') || '', p = C.fv('pw') || ''; if (!u) return L10N.needUser; return 'Authorization: Basic ' + btoa(u + ':' + p); },
    'dockercompose': function (t) { if (!t.trim()) return L10N.needCmd; try { return dockerRunToCompose(t); } catch (e) { return L10N.parseFail + e.message; } },
    'emailnorm': function (t) { var seen = {}, out = []; toLines(t).forEach(function (l) { var e = l.trim().toLowerCase().replace(/^mailto:/, ''); if (e && !seen[e]) { seen[e] = 1; out.push(e); } }); return out.join('\n') || '空'; },
    'tobin': function (t, C) { if (C.act() === 'frombinary') { var bytes = t.trim().split(/\s+/).map(function (b) { return parseInt(b, 2); }); var s = ''; bytes.forEach(function (b) { if (!isNaN(b)) s += String.fromCharCode(b); }); return s; } var out = []; for (var i = 0; i < t.length; i++) { out.push(('00000000' + t.charCodeAt(i).toString(2)).slice(-8)); } return out.join(' '); },
    'listconv': function (t, C) { var items = toLines(t).map(function (l) { return l.trim(); }).filter(function (l) { return l !== ''; }); var a = C.act() || 'comma'; if (a === 'comma') return items.join(', '); if (a === 'tab') return items.join('\t'); return JSON.stringify(items); },
    'asciiart': function (t) { if (!t.trim()) return L10N.needText; return asciiArt(t); },
    'html2md': function (t) {
      var s = (t || '').replace(/\r\n/g, '\n').replace(/<(script|style)[^>]*>[\s\S]*?<\/(script|style)>/gi, '');
      var pres = [];
      s = s.replace(/<pre[^>]*>([\s\S]*?)<\/pre>/gi, function (m, c) { pres.push(c.replace(/^\n/, '').replace(/\n\s*$/, '')); return ' PRE' + (pres.length - 1) + ' '; });
      function inline(html) {
        return html
          .replace(/<a[^>]*href="([^"]*)"[^>]*>([\s\S]*?)<\/a>/gi, function (m, href, txt) { return '[' + txt.replace(/<[^>]+>/g, '') + '](' + href + ')'; })
          .replace(/<(strong|b)[^>]*>([\s\S]*?)<\/(strong|b)>/gi, function (m, t1, c) { return '**' + c.replace(/<[^>]+>/g, '') + '**'; })
          .replace(/<(em|i)[^>]*>([\s\S]*?)<\/(em|i)>/gi, function (m, t1, c) { return '*' + c.replace(/<[^>]+>/g, '') + '*'; })
          .replace(/<code[^>]*>([\s\S]*?)<\/code>/gi, function (m, c) { return '`' + c.replace(/<[^>]+>/g, '') + '`'; })
          .replace(/<img[^>]*src="([^"]*)"[^>]*>/gi, function (m, src) { return '![](' + src + ')'; })
          .replace(/<br\s*\/?>/gi, '  \n')
          .replace(/<[^>]+>/g, '');
      }
      s = s.replace(/<h([1-6])[^>]*>([\s\S]*?)<\/h[1-6]>/gi, function (m, lvl, c) { return '\n' + Array(parseInt(lvl, 10) + 1).join('#') + ' ' + inline(c).trim() + '\n'; });
      s = s.replace(/<blockquote[^>]*>([\s\S]*?)<\/blockquote>/gi, function (m, c) { return '\n> ' + inline(c).trim().replace(/\n/g, '\n> ') + '\n'; });
      s = s.replace(/<(ul|ol)[^>]*>([\s\S]*?)<\/(ul|ol)>/gi, function (m, type, body) {
        var items = body.match(/<li[^>]*>([\s\S]*?)<\/li>/gi) || [];
        return '\n' + items.map(function (it, i) { var c = it.replace(/<\/?li[^>]*>/gi, ''); return (type === 'ol' ? (i + 1) + '. ' : '- ') + inline(c).trim(); }).join('\n') + '\n';
      });
      s = s.replace(/<p[^>]*>([\s\S]*?)<\/p>/gi, function (m, c) { return '\n' + inline(c).trim() + '\n'; });
      s = s.replace(/\n{3,}/g, '\n\n');
      s = s.replace(/ PRE(\d+) /g, function (m, i) { return '\n```\n' + pres[parseInt(i, 10)] + '\n```\n'; });
      s = s.replace(/<[^>]+>/g, '');
      s = s.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&nbsp;/g, ' ');
      return s.trim();
    },
    'xmlCompress': function (t) { return t.replace(/>\s+</g, '><').replace(/\s{2,}/g, ' ').trim(); },
    'svg2css': function (t) { var s = t.trim().replace(/\s{2,}/g, ' ').replace(/>\s+</g, '><'); var uri = 'data:image/svg+xml,' + encodeURIComponent(s); return 'background-image: url("' + uri + '");\nbackground-repeat: no-repeat;\nbackground-size: contain;\nbackground-position: center;'; },
    esc: function (t, C) { var a = (C && C.act) ? C.act() : 'escape'; if (a === 'unescape') return t.replace(/\\n/g, '\n').replace(/\\t/g, '\t').replace(/\\r/g, '\r').replace(/\\"/g, '"').replace(/\\'/g, "'").replace(/\\\\/g, '\\'); return t.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/'/g, "\\'").replace(/\n/g, '\\n').replace(/\t/g, '\\t').replace(/\r/g, '\\r'); },
    strcheck: function (t) { var chars = t.length, noSpace = t.replace(/\s/g, '').length, words = (t.match(/[A-Za-z0-9\u4e00-\u9fff]+/g) || []).length, lines = t === '' ? 0 : toLines(t).length; function inv(c){ var o=c.charCodeAt(0); return o===0x00a0||o===0x1680||o===0x180e||(o>=0x2000&&o<=0x200a)||o===0x200b||o===0x200c||o===0x200d||o===0x2028||o===0x2029||o===0x202f||o===0x205f||o===0x2060||o===0x3000||o===0xfeff||o===0x00ad||(o>=0x2066&&o<=0x2069); } var invis=0; for(var k=0;k<t.length;k++){ if(inv(t[k])) invis++; } return '\u5b57\u7b26\u6570(\u542b\u7a7a\u683c): ' + chars + '\n\u5b57\u7b26\u6570(\u4e0d\u542b\u7a7a\u683c): ' + noSpace + '\n\u8bcd\u6570: ' + words + '\n\u884c\u6570: ' + lines + '\n\u4e0d\u53ef\u89c1\u5b57\u7b26: ' + invis; },
    enc: function (t, C) { var from = C.fv('from'), to = C.fv('to'); function toBytes(str, e) { if (e === 'utf8' || e === 'ascii') { var s = unescape(encodeURIComponent(str)); var a = []; for (var i = 0; i < s.length; i++) a.push(s.charCodeAt(i) & 255); return a; } if (e === 'latin1' || e === 'gbk') { var a2 = []; for (var i2 = 0; i2 < str.length; i2++) a2.push(str.charCodeAt(i2) & 255); return a2; } if (e === 'base64') { var sb = atob(str.replace(/\s/g, '')); var a3 = []; for (var i3 = 0; i3 < sb.length; i3++) a3.push(sb.charCodeAt(i3) & 255); return a3; } if (e === 'hex') { var cl = str.replace(/\s/g, '').replace(/0x/g, ''); var a4 = []; for (var i4 = 0; i4 + 1 < cl.length; i4 += 2) a4.push(parseInt(cl.substr(i4, 2), 16)); if (cl.length % 2) a4.push(parseInt(cl[cl.length - 1], 16)); return a4; } if (e === 'url') { var su = decodeURIComponent(str.replace(/\+/g, ' ')); var a5 = []; for (var i5 = 0; i5 < su.length; i5++) a5.push(su.charCodeAt(i5) & 255); return a5; } return []; } function fromBytes(a, e) { if (e === 'utf8' || e === 'ascii') { var s = ''; a.forEach(function (b) { s += String.fromCharCode(b); }); return decodeURIComponent(escape(s)); } if (e === 'latin1' || e === 'gbk') { return a.map(function (b) { return String.fromCharCode(b); }).join(''); } if (e === 'base64') { var s2 = ''; a.forEach(function (b) { s2 += String.fromCharCode(b); }); return btoa(s2); } if (e === 'hex') { return a.map(function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); } if (e === 'url') { var s3 = ''; a.forEach(function (b) { s3 += String.fromCharCode(b); }); return encodeURIComponent(decodeURIComponent(escape(s3))); } return ''; } try { return fromBytes(toBytes(t, from), to); } catch (e) { return '转换失败：' + e.message; } },
    csv: function (t) { try { var rows = toLines(t).map(function (l) { return l.split(','); }); if (!rows.length) return '空'; var w = Math.max.apply(null, rows.map(function (r) { return r.length; })); var out = ''; rows.forEach(function (r) { for (var i = 0; i < w; i++) out += (r[i] || '').padEnd(16).slice(0, 16) + ' '; out += '\n'; }); return out; } catch (e) { return '解析失败：' + e.message; } },
    kw: function (t) { var cnt = {}; (t.toLowerCase().match(/[a-z0-9一-鿿]{2,}/g) || []).forEach(function (w) { cnt[w] = (cnt[w] || 0) + 1; }); var keys = Object.keys(cnt).sort(function (a, b) { return cnt[b] - cnt[a]; }).slice(0, 10); return keys.map(function (k) { return k + ': ' + cnt[k]; }).join('\n') || '空'; },

    // =================== 第三批新增（2026-08-29） ===================
    vigenere: function (t, C) { var key = (C.fv('key') || '').toUpperCase().replace(/[^A-Z]/g, ''); if (!key) return L10N.needKey || 'Need a key'; var mode = C.act() || 'enc'; var out = '', ki = 0; for (var i = 0; i < t.length; i++) { var c = t[i]; if (/[a-zA-Z]/.test(c)) { var base = c <= 'Z' ? 65 : 97; var kc = key.charCodeAt(ki % key.length) - 65; out += String.fromCharCode(base + (mode === 'dec' ? (c.charCodeAt(0) - base - kc + 26) % 26 : (c.charCodeAt(0) - base + kc) % 26)); ki++; } else out += c; } return out; },
    base32: function (t, C) { return C.act() === 'dec' ? T.base32Dec(t) : T.base32Enc(t); },
    railfence: function (t, C) { var rails = clamp(parseInt(C.fv('rails'), 10) || 3, 2, 20); var mode = C.act() || 'enc'; if (mode === 'dec') return railDec(t, rails); return railEnc(t, rails); },
    bigcalc: function (t, C) { var expr = (t || '').trim(); if (!expr) return ''; var m = expr.match(/^(-?\d+)\s*([+\-*/%^])\s*(-?\d+)$/); if (!m) return (LANG === 'zh' ? '格式：a + b（支持 + - * / % ^）' : 'Format: a + b (supports + - * / % ^)'); var a = BigInt(m[1]), b = BigInt(m[3]), r; try { if (m[2] === '+') r = a + b; else if (m[2] === '-') r = a - b; else if (m[2] === '*') r = a * b; else if (m[2] === '/') r = b === 0n ? null : a / b; else if (m[2] === '%') r = b === 0n ? null : a % b; else if (m[2] === '^') { if (b > 1000n || b < 0n) return (LANG === 'zh' ? '指数过大（≤1000）' : 'Exponent too large (≤1000)'); r = a ** b; } if (r === null) return (LANG === 'zh' ? '除数不能为 0' : 'Division by zero'); return String(r); } catch (e) { return (LANG === 'zh' ? '计算失败' : 'Calc error') + ': ' + e.message; } },
    textcompress: function (t, C) { var mode = C.act() || 'deflate'; if (typeof pako === 'undefined') return (LANG === 'zh' ? '压缩库未加载' : 'Compression lib not loaded'); try { if (mode === 'inflate') { var data = base64ToBytes(t.replace(/\s+/g, '')); var raw = pako.inflate(data); var bytes = new Uint8Array(raw); var bin = ''; for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]); try { return decodeURIComponent(escape(bin)); } catch (e) { return bin; } } var deflated = pako.deflate(new TextEncoder().encode(t), { level: 6 }); return bytesToBase64(deflated); } catch (e) { return (LANG === 'zh' ? '处理失败：' : 'Error: ') + e.message; } },

    // =================== 第四批新增（2026-08-29） ===================
    caesar: function (t, C) { var offset = parseInt(C.fv('offset'), 10); if (isNaN(offset)) offset = 3; var mode = C.act() || 'enc'; var dir = mode === 'dec' ? -offset : offset; var out = ''; for (var i = 0; i < t.length; i++) { var c = t[i]; if (/[a-zA-Z]/.test(c)) { var base = c <= 'Z' ? 65 : 97; var n = (c.charCodeAt(0) - base + dir) % 26; if (n < 0) n += 26; out += String.fromCharCode(base + n); } else out += c; } return out; },
    rc4: function (t, C) { var key = C.fv('key') || ''; if (!key) return (LANG === 'zh' ? '请输入密钥' : 'Enter a key'); var K = []; for (var i = 0; i < 256; i++) K[i] = i; var j = 0; for (var i2 = 0; i2 < 256; i2++) { j = (j + K[i2] + key.charCodeAt(i2 % key.length)) % 256; var tmp = K[i2]; K[i2] = K[j]; K[j] = tmp; } var out = '', a = 0, b = 0; for (var k = 0; k < t.length; k++) { a = (a + 1) % 256; b = (b + K[a]) % 256; var t2 = K[a]; K[a] = K[b]; K[b] = t2; out += String.fromCharCode(t.charCodeAt(k) ^ K[(K[a] + K[b]) % 256]); } return out; },
    bacon: function (t, C) { return C.act() === 'dec' ? T.baconDec(t) : T.baconEnc(t); },
    uucode: function (t, C) { return C.act() === 'dec' ? T.uuDec(t) : T.uuEnc(t); },
    supsub: function (t, C) { return T.supSub(t, C); },
    base58: function (t, C) { return C.act() === 'dec' ? T.base58Dec(t) : T.base58Enc(t); },
    base62: function (t, C) { return C.act() === 'dec' ? T.base62Dec(t) : T.base62Enc(t); },

    // =================== 第五批新增（2026-08-29 houyue 对照） ===================
    magnetenc: function (t, C) { return T.magnetEncode(t, C); },
    braille: function (t, C) { return C.act() === 'dec' ? T.brailleDec(t) : T.brailleEnc(t); },
    strftime: function (t, C) { var fmt = (t || '').trim() || (C.fv('fmt') || '%Y-%m-%d %H:%M:%S'); var d = new Date(); var p = function (n) { return String(n).padStart(2, '0'); }; var map = { Y: String(d.getFullYear()), y: String(d.getFullYear()).slice(-2), m: p(d.getMonth() + 1), d: p(d.getDate()), H: p(d.getHours()), M: p(d.getMinutes()), S: p(d.getSeconds()), j: String(Math.floor((d - new Date(d.getFullYear(), 0, 1)) / 86400000) + 1), w: String(d.getDay()), q: String(Math.floor(d.getMonth() / 3) + 1) }; return fmt.replace(/%(.)/g, function (m, c) { return map[c] !== undefined ? map[c] : m; }); },
    period: function (t, C) { var last = (C.fv('last') || '').trim(); var cycle = parseInt(C.fv('cycle'), 10) || 28; if (!/^\d{4}-\d{2}-\d{2}$/.test(last)) return (LANG === 'zh' ? '输入日期格式 YYYY-MM-DD' : 'Date format YYYY-MM-DD'); var d = new Date(last + 'T00:00:00'); if (isNaN(d.getTime())) return (LANG === 'zh' ? '无效日期' : 'Invalid date'); function add(n) { var x = new Date(d); x.setDate(x.getDate() + n); return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0'); } var next = add(cycle), ovu = add(cycle - 14), safe1 = add(cycle - 14 - 5), safe2 = add(cycle - 14 + 4); return (LANG === 'zh' ? '下次月经：' : 'Next period: ') + next + '\n' + (LANG === 'zh' ? '预计排卵日：' : 'Ovulation: ') + ovu + '\n' + (LANG === 'zh' ? '易孕期：' : 'Fertile window: ') + safe1 + ' ~ ' + safe2; },

    // =================== 第六批新增（2026-08-29 liulanqi 对照） ===================
    affine: function (t, C) { return C.act() === 'dec' ? T.affineDec(t, C) : T.affineEnc(t, C); },
    blood: function (t, C) { return T.bloodTypeCalc(t, C); },
    cidr: function (t, C) { return T.cidrCalc(t, C); },

    // =================== 第七批新增（2026-09-03 开源项目对照 CyberChef / DevToys / transform.tools） ===================
    b45: function (t, C) { return b9Base45(t, C.act() === 'dec'); },
    b85: function (t, C) { return b9Base85(t, C.act() === 'dec', C.fv('var') || 'ascii85'); },
    b92: function (t, C) { return b9Base92(t, C.act() === 'dec'); },
    bech32: function (t, C) { return b9Bech32(t, C.act() === 'dec', C.fv('hrp') || 'bc', C.opt('m')); },
    qp: function (t, C) { return b9QP(t, C.act() === 'dec'); },
    rot47: function (t) { return b9Rot47(t); },
    atbash: function (t) { return b9Atbash(t); },
    a1z26: function (t, C) { return b9A1Z26(t, C.act() === 'dec', C.fv('sep') || '-'); },
    cabox: function (t, C) { return b9CaesarBox(t, C.act() === 'dec'); },
    xorcipher: function (t, C) { return b9Xor(t, C.fv('key') || '', C.opt('hex')); },
    endian: function (t, C) { return b9Endian(t, parseInt(C.fv('word'), 10) || 32); },
    ieee754: function (t, C) { return b9Ieee754(t, C.act() === 'dec', C.fv('prec') || 'double'); },
    defang: function (t, C) { return b9Defang(t, C.act() === 'refang'); },
    linebreak: function (t, C) { return b9LineBreak(t, C.fv('to') || 'lf'); },
    uninorm: function (t, C) { try { return t.normalize(C.fv('form') || 'NFC'); } catch (e) { return 'Error: ' + e.message; } },
    levenshtein: function (t, C) { return b9Levenshtein(t, C.fv('b') || ''); },
    domains: function (t) { return b9ExtractDomains(t); },
    macs: function (t) { return b9ExtractMacs(t); },
    listcompare: function (t, C) { return b9ListCompare(t, C.fv('b') || ''); },
    entropy: function (t) { return b9Entropy(t); },
    fletcher: function (t, C) { return b9Fletcher(t, C.fv('bits') || '16'); },
    murmur3: function (t, C) { return b9Murmur3(t, parseInt(C.fv('seed'), 10) || 0); },
    crc32: function (t) { return b9Crc32(t); },
    hashid: function (t) { return b9HashId(t); },
    gzipc: function (t, C) { return C.act() === 'dec' ? b9Pako(t, 'ungzip') : b9Pako(t, 'gzip', parseInt(C.fv('level'), 10) || 6); },
    zlibc: function (t, C) { return C.act() === 'dec' ? b9Pako(t, 'inflate') : b9Pako(t, 'deflate', parseInt(C.fv('level'), 10) || 6); },
    jwtsign: function (t, C) { return b9Jwt(t, C.fv('secret') || '', C.act() === 'verify', C.fv('alg') || 'HS256'); },
    hotp: function (t, C) { return b9Hotp(C.fv('secret') || '', parseInt(C.fv('counter'), 10) || 0, parseInt(C.fv('digits'), 10) || 6); },
    uuidv1: function () { return b9UuidV1(); },
    uuidv6: function () { return b9UuidV6(); },
    sm4: function (t, C) { return b9Sm4(t, C.fv('key') || '', C.act() === 'dec', C.fv('enc') || 'hex'); },
    pipeline: function (t, C) { return b9Pipeline(t, C.fv('steps') || ''); },
    csv2html: function (t, C) { return b9Csv2Html(t, C.opt('head'), C.fv('cls') || ''); },
    html2csv: function (t) { return b9Html2Csv(t); },
    csvtranspose: function (t, C) { return b9CsvTranspose(t, C.fv('dlm') || ','); },
    csv2sql: function (t, C) { return b9Csv2Sql(t, C.fv('table') || 'my_table'); },
    mdtable: function (t, C) { return b9MdTable(t, C.fv('align') || 'left'); },
    svg2uri: function (t, C) { return b9Svg2Uri(t, C.act() === 'b64'); },
    html2jsx: function (t) { return b9Html2Jsx(t); },
    svg2jsx: function (t, C) { return b9Svg2Jsx(t, C.fv('name') || 'MyIcon'); },
    svgsprite: function (t) { return b9SvgSprite(t); },
    json2zod: function (t) { return b9Json2Zod(t); },
    json2schema: function (t) { return b9Json2Schema(t); },
    json2go: function (t, C) { return b9Json2Go(t, C.fv('name') || 'Root'); },
    css2jsobj: function (t, C) { return b9Css2Js(t, C.act() === 'camel'); },
    css2tw: function (t) { return b9Css2Tw(t); },
    tw2css: function (t) { return b9Tw2Css(t); },
    html2pug: function (t) { return b9Html2Pug(t); },
    yamlfmt: function (t, C) { return b9YamlFmt(t, parseInt(C.fv('indent'), 10) || 2); },
    toml2yaml: function (t) { return b9Toml2Yaml(t); },
    xmlvalidate: function (t) { return b9XmlValidate(t); },
    htmlvalidate: function (t) { return b9HtmlValidate(t); },
    cssvalidate: function (t) { return b9CssValidate(t); },

    // ====== 第八批新增（2026-09-03 开源项目对照） ======
    msgpack: function (t, C) { return b9MsgPack(t, C.act() === 'dec'); },
    cbor: function (t, C) { return b9Cbor(t, C.act() === 'dec'); },
    asn1: function (t) { return b9Asn1(t); },
    enigma: function (t, C) { return b9Enigma(t, C.fv('rotors') || 'I,II,III', C.fv('rings') || 'AAA', C.fv('pos') || 'AAA', C.fv('plug') || '', C.fv('refl') || 'B'); },
    des: function (t, C) { return b9Des(t, C.fv('key') || '', C.act() === 'dec', false, C.fv('enc') || 'hex'); },
    tdes: function (t, C) { return b9Des(t, C.fv('key') || '', C.act() === 'dec', true, C.fv('enc') || 'hex'); },
    fernet: function (t, C) { return b9Fernet(t, C.fv('key') || '', C.act() === 'dec'); },
    exthash: function (t) { return b9ExtHash(t); },
    ts2schema: function (t) { return b9Ts2Schema(t); },
    specificity: function (t) { return b9Specificity(t); },
    csvmerge: function (t, C) { return b9CsvMerge(t, C.fv('b') || ''); },
    csvsplit: function (t, C) { return b9CsvSplit(t, C.fv('mode') || 'rows', parseInt(C.fv('n'), 10) || 100); },
    // ====== 第十一批新增（2026-09-04 开源项目对照：密码/编码/哈希） ======
    'playfair-cipher': function (t, C) { return c11Playfair(t, C.fv('key') || '', C.act() === 'dec'); },
    'polybius-square-cipher': function (t, C) { return c11Polybius(t, C.fv('key') || '', C.act() === 'dec'); },
    'bifid-cipher': function (t, C) { return c11Bifid(t, C.fv('key') || '', C.act() === 'dec', { period: parseInt(C.fv('period'), 10) || 5 }); },
    'columnar-transposition-cipher': function (t, C) { return c11Columnar(t, C.fv('key') || '', C.act() === 'dec'); },
    'scytale-cipher': function (t, C) { return c11Scytale(t, C.fv('key') || '', C.act() === 'dec', { rows: parseInt(C.fv('rows'), 10) || 5 }); },
    'hill-cipher': function (t, C) { return c11Hill(t, C.fv('key') || '', C.act() === 'dec'); },
    'autokey-cipher': function (t, C) { return c11Autokey(t, C.fv('key') || '', C.act() === 'dec'); },
    'beaufort-cipher': function (t, C) { return c11Beaufort(t, C.fv('key') || '', C.act() === 'dec'); },
    'variant-beaufort-cipher': function (t, C) { return c11VariantBeaufort(t, C.fv('key') || '', C.act() === 'dec'); },
    'porta-cipher': function (t, C) { return c11Porta(t, C.fv('key') || '', C.act() === 'dec'); },
    'gronsfeld-cipher': function (t, C) { return c11Gronsfeld(t, C.fv('key') || '', C.act() === 'dec'); },
    'four-square-cipher': function (t, C) { return c11FourSquare(t, C.fv('key') || '', C.act() === 'dec', { key2: C.fv('key2') || '' }); },
    'two-square-cipher': function (t, C) { return c11TwoSquare(t, C.fv('key') || '', C.act() === 'dec', { key2: C.fv('key2') || '' }); },
    'adfgvx-cipher': function (t, C) { return c11Adfgvx(t, C.fv('key') || '', C.act() === 'dec', { key2: C.fv('key2') || 'KEY' }); },
    'adfgx-cipher': function (t, C) { return c11Adfgx(t, C.fv('key') || '', C.act() === 'dec', { key2: C.fv('key2') || 'KEY' }); },
    'trifid-cipher': function (t, C) { return c11Trifid(t, C.fv('key') || '', C.act() === 'dec', { period: parseInt(C.fv('period'), 10) || 5 }); },
    'nihilist-cipher': function (t, C) { return c11Nihilist(t, C.fv('key') || '', C.act() === 'dec', { key2: C.fv('key2') || '' }); },
    'amsco-cipher': function (t, C) { return c11Amsco(t, C.fv('key') || '', C.act() === 'dec'); },
    'route-transposition-cipher': function (t, C) { return c11Route(t, C.fv('key') || '', C.act() === 'dec', { cols: parseInt(C.fv('cols'), 10) || 6, route: C.fv('route') || 'spiral' }); },
    'double-transposition-cipher': function (t, C) { return c11DoubleTransposition(t, C.fv('key') || '', C.act() === 'dec', { key2: C.fv('key2') || '' }); },
    'ragbaby-cipher': function (t, C) { return c11Ragbaby(t, C.fv('key') || '', C.act() === 'dec'); },
    'tritheme-cipher': function (t, C) { return c11Tritheme(t, C.fv('key') || '', C.act() === 'dec', { start: C.fv('start') === '' || C.fv('start') === undefined ? 1 : (parseInt(C.fv('start'), 10) || 0) }); },
    'vernam-one-time-pad': function (t, C) { return c11Vernam(t, C.fv('key') || '', C.act() === 'dec', { mode: C.opt('bytemode') ? 'byte' : 'letter' }); },
    'homophonic-substitution-cipher': function (t, C) { return c11Homophonic(t, C.fv('key') || '', C.act() === 'dec', {}); },
    'straddling-checkerboard-cipher': function (t, C) { return c11Straddling(t, C.fv('key') || '', C.act() === 'dec', { p2: parseInt(C.fv('p2'), 10) || 2, p3: parseInt(C.fv('p3'), 10) || 6 }); },
    'morbit-cipher': function (t, C) { return c11Morbit(t, C.fv('key') || '', C.act() === 'dec', {}); },
    'pollux-cipher': function (t, C) { return c11Pollux(t, C.fv('key') || '', C.act() === 'dec', {}); },
    'digrafid-cipher': function (t, C) { return c11Digrafid(t, C.fv('key') || '', C.act() === 'dec', { key2: C.fv('key2') || '', period: parseInt(C.fv('period'), 10) || 3 }); },
    'chaocipher': function (t, C) { return c11Chaocipher(t, C.fv('key') || '', C.act() === 'dec', { key2: C.fv('key2') || '' }); },
    'alberti-cipher': function (t, C) { return c11Alberti(t, C.fv('key') || '', C.act() === 'dec'); },
    'keyed-substitution-cipher': function (t, C) { return c11KeyedSub(t, C.fv('key') || '', C.act() === 'dec'); },
    'tap-code-translator': function (t, C) { return c11TapCode(t, C.fv('key') || '', C.act() === 'dec'); },
    'pigpen-cipher': function (t, C) { return c11Pigpen(t, C.fv('key') || '', C.act() === 'dec', {}); },
    'semaphore-flags-translator': function (t, C) { return c11Semaphore(t, C.fv('key') || '', C.act() === 'dec'); },
    /* ------------------------------------------------------------------
       chunk_cipherB_ops  --  op wiring for chunk_cipherB_fn
       candidates 35..67 of cand_crypto.json
       C.fv('x')  = value of field x        C.act() = 'enc' | 'dec'
       C.opt('x') = checkbox x checked?
       ------------------------------------------------------------------ */
    'dancing-men-cipher': function (t, C) {
      return c11BDancingMen(t, '', C.act() === 'dec', {});
    },
    'leetspeak-converter': function (t, C) {
      return c11BLeet(t, '', C.act() === 'dec', { level: parseInt(C.fv('level'), 10) || 1 });
    },
    'rot5-cipher': function (t, C) { return c11BRot5(t); },
    'rot18-cipher': function (t, C) { return c11BRot18(t); },
    'rot8000-encoder': function (t, C) { return c11BRot8000(t); },
    'multitap-keypad-encoder': function (t, C) {
      return c11BMultitap(t, '', C.act() === 'dec', { sep: C.fv('sep') === undefined || C.fv('sep') === '' ? ' ' : C.fv('sep') });
    },
    'baudot-code-translator': function (t, C) {
      return c11BBaudot(t, '', C.act() === 'dec', { format: C.fv('format') || 'bits' });
    },
    'base91-encoder': function (t, C) {
      return c11BBase91(t, '', C.act() === 'dec', {});
    },
    'base36-encoder': function (t, C) {
      return c11BBase36(t, '', C.act() === 'dec', {});
    },
    'base64url-encoder': function (t, C) {
      return c11BBase64url(t, '', C.act() === 'dec', { pad: C.opt('pad') });
    },
    'crockford-base32-encoder': function (t, C) {
      return c11BCrockford(t, '', C.act() === 'dec', { check: C.opt('check') });
    },
    'z-base-32-encoder': function (t, C) {
      return c11BZBase32(t, '', C.act() === 'dec', {});
    },
    'base100-emoji-encoder': function (t, C) {
      return c11BBase100(t, '', C.act() === 'dec', {});
    },
    'ebcdic-converter': function (t, C) {
      return c11BEbcdic(t, '', C.act() === 'dec', { format: C.fv('format') || 'hex' });
    },
    'bubble-babble-encoder': function (t, C) {
      return c11BBubbleBabble(t, '', C.act() === 'dec', {});
    },
    'gray-code-converter': function (t, C) {
      var out = C.fv('out') || 'bin';
      return c11BGray(t, '', C.act() === 'dec', {
        out: out,
        in: C.fv('in') || (out === 'bin' ? 'bin' : 'dec'),
        width: parseInt(C.fv('width'), 10) || 4,
        mode: C.fv('mode') || 'num'
      });
    },
    'varint-leb128-encoder': function (t, C) {
      return c11BVarint(t, '', C.act() === 'dec', { mode: C.fv('mode') || 'uleb' });
    },
    'hexdump-generator': function (t, C) {
      return c11BHexdump(t, '', C.act() === 'dec', { cols: parseInt(C.fv('cols'), 10) || 16 });
    },
    'bit-rotate-reverser': function (t, C) {
      return c11BBitRot(t, '', C.act() === 'dec', {
        op: C.fv('op') || 'rol',
        n: parseInt(C.fv('n'), 10) || 1,
        width: parseInt(C.fv('width'), 10) || 8,
        mode: C.fv('mode') || 'hex'
      });
    },
    'dna-codon-encoder': function (t, C) {
      return c11BDna(t, '', C.act() === 'dec', { mode: C.fv('mode') || 'aa1' });
    },
    'sha3-hash-generator': function (t, C) {
      var v = C.fv('variant') || 'sha3-256';
      if (v === 'shake128' || v === 'shake256') {
        return c11BShake(t, v === 'shake256' ? 256 : 128, parseInt(C.fv('outbytes'), 10) || 32);
      }
      return c11BSha3(t, parseInt(C.fv('bits'), 10) || 256);
    },
    'blake2-hash-generator': function (t, C) {
      var v = C.fv('variant') || 'b';
      var bits = parseInt(C.fv('bits'), 10) || (v === 's' ? 256 : 512);
      if (v === 's') return c11BBlake2s(t, bits, C.fv('key') || '');
      return c11BBlake2b(t, bits, C.fv('key') || '');
    },
    'ripemd160-hash-generator': function (t, C) { return c11BRipemd160(t); },
    'sm3-hash-generator': function (t, C) { return c11BSm3(t); },
    'whirlpool-hash-generator': function (t, C) { return c11BWhirlpool(t); },
    'xxhash32-generator': function (t, C) {
      return c11BXxhash32(t, parseInt(C.fv('seed'), 10) || 0);
    },
    'crc8-checksum': function (t, C) {
      var p8 = C.fv('preset') || 'smbus';
      var o8 = { preset: p8, poly: C.fv('poly'), init: C.fv('init'), xorout: C.fv('xorout'), fmt: C.fv('fmt') || 'hex' };
      /* 只有自定义模式才用复选框，否则预设自带的 refin/refout 会被 false 覆盖 */
      if (p8 === 'custom') { o8.refin = C.opt('refin'); o8.refout = C.opt('refout'); }
      return c11BCrc8(t, o8);
    },
    'crc16-checksum': function (t, C) {
      var p16 = C.fv('preset') || 'modbus';
      var o16 = { preset: p16, poly: C.fv('poly'), init: C.fv('init'), xorout: C.fv('xorout'), fmt: C.fv('fmt') || 'hex' };
      if (p16 === 'custom') { o16.refin = C.opt('refin'); o16.refout = C.opt('refout'); }
      return c11BCrc16(t, o16);
    },
    'luhn-checksum-validator': function (t, C) {
      return c11BLuhn(t, { mode: C.fv('mode') || 'validate' });
    },
    'hamming-code-calculator': function (t, C) {
      return c11BHamming(t, { mode: C.fv('mode') || 'encode', report: C.opt('report') });
    },
    'ntlm-hash-generator': function (t, C) { return c11BNtlm(t); },
    'soundex-generator': function (t, C) {
      return c11BSoundex(t, { length: parseInt(C.fv('length'), 10) || 4 });
    },
    'index-of-coincidence': function (t, C) {
      return c11BIndexOfCoincidence(t, {
        k: parseInt(C.fv('k'), 10) || 1,
        maxK: parseInt(C.fv('maxk'), 10) || 0
      });
    },
    /* ---- 第十三批：数据互转 / 文本特效 / 校验（2026-09-06 开源项目对照） ---- */
    'tsv-to-csv': function (t, C) { return b13TsvCsv(t, C); },
    'csv-to-markdown-table': function (t, C) { return b13CsvToMd(t, C); },
    'markdown-table-to-csv': function (t) { return b13MdToCsv(t); },
    'csv-to-xml': function (t, C) { return b13CsvToXml(t, C); },
    'csv-to-yaml': function (t) { return b13CsvToYaml(t); },
    'yaml-to-csv': function (t) { return b13YamlToCsv(t); },
    'csv-to-latex': function (t, C) { return b13CsvToLatex(t, C); },
    'csv-to-plain-text': function (t) { return b13CsvToText(t); },
    'json-to-markdown-table': function (t) { return b13JsonToMd(t); },
    'json-to-sql': function (t, C) { return b13JsonToSql(t, C); },
    'csv-query-sql': function (t, C) { return b13CsvSql(t, C); },
    'upside-down-text-generator': function (t) { return b13UpsideDown(t); },
    'mirror-text-generator': function (t) { return b13MirrorText(t); },
    'text-to-hex': function (t, C) { return b13TextToHex(t, C); },
    'base64-to-hex': function (t, C) { return b13B64ToHex(t, C); },
    'secret-detector': function (t) { return b13SecretScan(t); },
    'phone-number-validator': function (t) { return b13PhoneCheck(t); },
    'swift-bic-validator': function (t) { return b13SwiftCheck(t); },
    'json-ld-inspector': function (t) { return b13JsonLd(t); },
    'unicode-codepoint-lookup': function (t) { return b13UnicodeLookup(t); },
    'log-analyzer': function (t, C) { return b13LogAnalyze(t, C); },
    'sql-ddl-to-diagram': function (t) { return b13DdlDiagram(t); },
    'js-beautifier': function (t) { return b13BeautifyJs(t); },
    'php-beautifier': function (t) { return b13BeautifyPhp(t); },
    'css-to-less': function (t) { return b13CssToLess(t); },
    'ascii-box-drawing': function (t, C) { return b13AsciiBox(t, C); },
    'ascii-tree-printer': function (t) { return b13AsciiTree(t); },
    'uri-template-parser': function (t, C) { return b13UriTemplate(t, C); },
    'iogen': function (t) { return runTransform(DETAIL.recipe, t); }
  };
  /* ============ 第七批工具引擎：CyberChef / DevToys 对照（2026-09-03） ============ */
  function b9U8(s) { return new TextEncoder().encode(s); }
  function b9Str(b) { try { return new TextDecoder().decode(b); } catch (e) { var o = ''; for (var i = 0; i < b.length; i++) o += String.fromCharCode(b[i]); return o; } }
  function b9Hex(b) { var a = b instanceof Uint8Array ? b : new Uint8Array(b); var s = ''; for (var i = 0; i < a.length; i++) s += ('0' + a[i].toString(16)).slice(-2); return s; }
  function b9Unhex(h) { h = String(h || '').replace(/[^0-9a-fA-F]/g, ''); if (h.length % 2) h = '0' + h; var a = new Uint8Array(h.length / 2); for (var i = 0; i < a.length; i++) a[i] = parseInt(h.substr(i * 2, 2), 16); return a; }
  function b9B64(b) { return bytesToBase64(b); }
  function b9Unb64(s) { try { return base64ToBytes(String(s).replace(/\s/g, '')); } catch (e) { return null; } }
  function b9U64(n) { return ('0000000000000000' + (n >>> 0).toString(16)).slice(-8); }

  /* ---- Base45 ---- */
  var B45C = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
  function b9Base45(t, dec) {
    try {
      if (dec) {
        var out = [];
        for (var i = 0; i < t.length; i += 3) {
          var chunk = t.substr(i, 3), v = 0;
          for (var j = 0; j < chunk.length; j++) { var d = B45C.indexOf(chunk[j]); if (d < 0) return 'Error: invalid Base45 character "' + chunk[j] + '"'; v += d * Math.pow(45, j); }
          if (chunk.length === 3) { if (v > 0xFFFF) return 'Error: invalid Base45 group'; out.push(v % 256, Math.floor(v / 256)); }
          else if (chunk.length === 2) { if (v > 0xFF) return 'Error: invalid Base45 group'; out.push(v); }
          else return 'Error: invalid Base45 length';
        }
        return b9Str(new Uint8Array(out));
      }
      var b = b9U8(t), s = '';
      for (var k = 0; k < b.length; k += 2) {
        if (k + 1 < b.length) { var v2 = b[k] + b[k + 1] * 256; s += B45C[v2 % 45] + B45C[Math.floor(v2 / 45) % 45] + B45C[Math.floor(v2 / 2025) % 45]; }
        else { var v3 = b[k]; s += B45C[v3 % 45] + B45C[Math.floor(v3 / 45) % 45]; }
      }
      return s;
    } catch (e) { return 'Error: ' + e.message; }
  }

  /* ---- Base85（ascii85 / z85 / rfc1924） ---- */
  var Z85C = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ.-:+=^!/*?&<>()[]{}@%$#';
  var R1924C = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz!#$%&()*+-;<=>?@^_`{|}~';
  var A85C = (function () { var x = ''; for (var w = 33; w <= 117; w++) x += String.fromCharCode(w); return x; })();
  function b85GrpEnc(b, off, cs, zeroShort) {
    var v = ((b[off] << 24) | (b[off + 1] << 16) | (b[off + 2] << 8) | b[off + 3]) >>> 0, s = '';
    for (var k = 0; k < 5; k++) { s = cs[v % 85] + s; v = Math.floor(v / 85); }
    return s;
  }
  function b85GrpDec(s, cs) {
    var v = 0;
    for (var k = 0; k < 5; k++) { var d = cs.indexOf(s[k]); if (d < 0) return -1; v = v * 85 + d; }
    if (v > 0xFFFFFFFF) return -2;
    return [Math.floor(v / 16777216) & 255, Math.floor(v / 65536) & 255, Math.floor(v / 256) & 255, v & 255];
  }
  function b9Base85(t, dec, variant) {
    try {
      if (variant === 'z85' || variant === 'rfc1924') {
        var cs = variant === 'z85' ? Z85C : R1924C;
        if (dec) {
          var sd = String(t).replace(/\s/g, '');
          if (sd.length % 5) return 'Error: length must be a multiple of 5';
          var od = [];
          for (var i = 0; i < sd.length; i += 5) {
            var r = b85GrpDec(sd.substr(i, 5), cs);
            if (r === -1) return 'Error: invalid character'; if (r === -2) return 'Error: group out of range';
            od.push(r[0], r[1], r[2], r[3]);
          }
          return b9Str(new Uint8Array(od));
        }
        var b = b9U8(t);
        if (b.length % 4) return 'Error: input length must be a multiple of 4 bytes';
        var s = '';
        for (var j = 0; j < b.length; j += 4) s += b85GrpEnc(b, j, cs);
        return s;
      }
      if (dec) {
        var sa = String(t).replace(/\s/g, ''), oa = [], i2 = 0;
        while (i2 < sa.length) {
          if (sa[i2] === 'z') { oa.push(0, 0, 0, 0); i2++; continue; }
          var ch = sa.substr(i2, 5); if (ch.length < 2) break;
          var pad = 5 - ch.length, v = 0, k2;
          for (k2 = 0; k2 < 5; k2++) { var c2 = k2 < ch.length ? ch.charCodeAt(k2) - 33 : 84; if (c2 < 0 || c2 > 84) return 'Error: invalid ASCII85 character'; v = v * 85 + c2; }
          if (v > 0xFFFFFFFF) return 'Error: group out of range';
          var by = [Math.floor(v / 16777216) & 255, Math.floor(v / 65536) & 255, Math.floor(v / 256) & 255, v & 255];
          for (k2 = 0; k2 < 4 - pad; k2++) oa.push(by[k2]);
          i2 += 5;
        }
        return b9Str(new Uint8Array(oa));
      }
      var be = b9U8(t), oe = '';
      for (var m = 0; m < be.length; m += 4) {
        var tmp = new Uint8Array(4); for (var q = 0; q < 4; q++) tmp[q] = m + q < be.length ? be[m + q] : 0;
        var n = Math.min(4, be.length - m);
        var val = ((tmp[0] << 24) | (tmp[1] << 16) | (tmp[2] << 8) | tmp[3]) >>> 0;
        if (val === 0 && n === 4) { oe += 'z'; continue; }
        oe += b85GrpEnc(tmp, 0, A85C).substr(0, 5 - (4 - n));
      }
      return oe;
    } catch (e) { return 'Error: ' + e.message; }
  }

  /* ---- Base92 ---- */
  function b92Chr(v) { if (v === 0) return '!'; if (v <= 61) return String.fromCharCode(35 + v - 1); return String.fromCharCode(97 + v - 62); }
  function b92Ord(c) { if (c === '!') return 0; var o = c.charCodeAt(0); if (o >= 35 && o <= 96) return o - 34; if (o >= 97 && o <= 125) return o - 35; return -1; }
  function b9Base92(t, dec) {
    try {
      if (dec) {
        var bitstr = '';
        for (var i = 0; i + 1 < t.length; i += 2) {
          var a = b92Ord(t[i]), b = b92Ord(t[i + 1]);
          if (a < 0 || b < 0) return 'Error: invalid Base92 character';
          bitstr += ('0000000000000' + (a * 91 + b).toString(2)).slice(-13);
        }
        var out = [];
        for (var j = 0; j + 8 <= bitstr.length; j += 8) out.push(parseInt(bitstr.substr(j, 8), 2));
        while (out.length && out[out.length - 1] === 0) out.pop();
        return b9Str(new Uint8Array(out));
      }
      var by = b9U8(t), bs = '';
      for (var k = 0; k < by.length; k++) bs += ('00000000' + by[k].toString(2)).slice(-8);
      while (bs.length % 13) bs += '0';
      var s = '';
      for (var m = 0; m < bs.length; m += 13) { var v = parseInt(bs.substr(m, 13), 2); s += b92Chr(Math.floor(v / 91)) + b92Chr(v % 91); }
      return s;
    } catch (e) { return 'Error: ' + e.message; }
  }

  /* ---- Bech32 / Bech32m ---- */
  var B32C = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
  function b32Polymod(vals) {
    var GEN = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3], chk = 1;
    for (var i = 0; i < vals.length; i++) {
      var top = chk >> 25;
      chk = ((chk & 0x1ffffff) << 5) ^ vals[i];
      for (var j = 0; j < 5; j++) if ((top >> j) & 1) chk ^= GEN[j];
    }
    return chk;
  }
  function b32HrpExpand(hrp) {
    var out = [], i;
    for (i = 0; i < hrp.length; i++) out.push(hrp.charCodeAt(i) >> 5);
    out.push(0);
    for (i = 0; i < hrp.length; i++) out.push(hrp.charCodeAt(i) & 31);
    return out;
  }
  function b32ConvBits(data, from, to, pad) {
    var acc = 0, bits = 0, ret = [], maxv = (1 << to) - 1;
    for (var i = 0; i < data.length; i++) {
      acc = (acc << from) | data[i]; bits += from;
      while (bits >= to) { bits -= to; ret.push((acc >> bits) & maxv); }
    }
    if (pad) { if (bits) ret.push((acc << (to - bits)) & maxv); }
    else if (bits >= from || ((acc << (to - bits)) & maxv)) return null;
    return ret;
  }
  function b9Bech32(t, dec, hrp, useM) {
    try {
      if (dec) {
        var s = String(t).trim();
        if (s.toLowerCase() !== s && s.toUpperCase() !== s) return 'Error: mixed case is not allowed';
        s = s.toLowerCase();
        var pos = s.lastIndexOf('1');
        if (pos < 1 || pos + 7 > s.length || s.length > 90) return 'Error: invalid bech32 string';
        var hr = s.slice(0, pos), data = [], i;
        for (i = pos + 1; i < s.length; i++) { var d = B32C.indexOf(s[i]); if (d < 0) return 'Error: invalid character "' + s[i] + '"'; data.push(d); }
        var pm = b32Polymod(b32HrpExpand(hr).concat(data));
        if (pm !== 1 && pm !== 0x2bc830a3) return 'Error: checksum failed';
        var conv = b32ConvBits(data.slice(6), 5, 8, false);
        if (!conv) return 'Error: invalid data section';
        return b9Str(new Uint8Array(conv));
      }
      hrp = String(hrp || 'bc').toLowerCase();
      var words = b32ConvBits(Array.prototype.slice.call(b9U8(t)), 8, 5, true);
      if (!words) return 'Error: conversion failed';
      var constv = useM ? 0x2bc830a3 : 1;
      var pm2 = b32Polymod(b32HrpExpand(hrp).concat(words).concat([0, 0, 0, 0, 0, 0])) ^ constv;
      var chk = [];
      for (var k = 0; k < 6; k++) chk.push((pm2 >> 5 * (5 - k)) & 31);
      return hrp + '1' + words.concat(chk).map(function (x) { return B32C[x]; }).join('');
    } catch (e) { return 'Error: ' + e.message; }
  }

  /* ---- Quoted-Printable ---- */
  function b9QP(t, dec) {
    try {
      if (dec) return t.replace(/=\r?\n/g, '').replace(/=([0-9A-Fa-f]{2})/g, function (m, h) { return String.fromCharCode(parseInt(h, 16)); });
      var b = b9U8(t), out = '', line = '';
      function push(s) { if (line.length + s.length > 75) { out += line + '=\r\n'; line = ''; } line += s; }
      for (var i = 0; i < b.length; i++) {
        var v = b[i];
        if (v === 10 || v === 13) { out += line + '\r\n'; line = ''; continue; }
        if (v >= 33 && v <= 126 && v !== 61) push(String.fromCharCode(v));
        else push('=' + ('0' + v.toString(16)).toUpperCase().slice(-2));
      }
      return out + line;
    } catch (e) { return 'Error: ' + e.message; }
  }

  /* ---- 经典密码 ---- */
  function b9Rot47(s) { return s.replace(/[\x21-\x7e]/g, function (c) { return String.fromCharCode(33 + (c.charCodeAt(0) - 33 + 47) % 94); }); }
  function b9Atbash(s) { return s.replace(/[a-zA-Z]/g, function (c) { var b = c <= 'Z' ? 65 : 97; return String.fromCharCode(b + (25 - (c.charCodeAt(0) - b))); }); }
  function b9A1Z26(s, dec, sep) {
    if (dec) return s.split(sep).map(function (p) { var v = parseInt(p.trim(), 10); return (v >= 1 && v <= 26) ? String.fromCharCode(96 + v) : p; }).join('');
    var out = '', prev = false;
    for (var i = 0; i < s.length; i++) {
      var c = s[i];
      if (/[a-zA-Z]/.test(c)) { if (prev) out += sep; out += (c.toLowerCase().charCodeAt(0) - 96); prev = true; }
      else { out += c; prev = false; }
    }
    return out;
  }
  function b9CaesarBox(s, dec) {
    var t = String(s).replace(/\s+/g, '');
    if (!t) return '';
    var n = Math.ceil(Math.sqrt(t.length)), g = [], r, c, idx = 0;
    for (r = 0; r < n; r++) { g.push([]); for (c = 0; c < n; c++) g[r][c] = idx < t.length ? t[idx++] : null; }
    if (dec) {
      var src = t, p = 0;
      for (c = 0; c < n; c++) for (r = 0; r < n; r++) if (g[r][c] !== null) g[r][c] = p < src.length ? src[p++] : null;
    }
    var out = '';
    if (dec) { for (r = 0; r < n; r++) for (c = 0; c < n; c++) if (g[r][c] !== null) out += g[r][c]; }
    else { for (c = 0; c < n; c++) for (r = 0; r < n; r++) if (g[r][c] !== null) out += g[r][c]; }
    return out;
  }
  function b9Xor(t, key, hexIn) {
    if (!key) return 'Error: please enter a key';
    var kb = b9U8(key), data = hexIn ? b9Unhex(t) : b9U8(t);
    if (!data.length) return '';
    var out = new Uint8Array(data.length);
    for (var i = 0; i < data.length; i++) out[i] = data[i] ^ kb[i % kb.length];
    return b9Hex(out);
  }
  function b9Endian(t, word) {
    var b = b9Unhex(t), size = word / 8;
    if (![2, 4, 8].some(function (x) { return x === size; })) return 'Error: word size must be 16, 32 or 64';
    var out = new Uint8Array(b.length - b.length % size);
    for (var i = 0; i + size <= b.length; i += size) for (var k = 0; k < size; k++) out[i + k] = b[i + size - 1 - k];
    return b9Hex(out);
  }
  function b9Ieee754(t, dec, prec) {
    var s = String(t).trim();
    if (dec) {
      var b = b9Unhex(s.replace(/[\s:]/g, ''));
      if (prec === 'single' && b.length < 4) return 'Error: need 4 bytes for single precision';
      if (prec !== 'single' && b.length < 8) return 'Error: need 8 bytes for double precision';
      var dv = new DataView(b.buffer, b.byteOffset, b.byteLength);
      return String(prec === 'single' ? dv.getFloat32(0) : dv.getFloat64(0));
    }
    var v = parseFloat(s);
    if (isNaN(v)) return 'Error: enter a decimal number (e.g. 3.14159)';
    var buf = new ArrayBuffer(8), dv2 = new DataView(buf);
    if (prec === 'single') { dv2.setFloat32(0, v); return b9Hex(new Uint8Array(buf, 0, 4)); }
    dv2.setFloat64(0, v); return b9Hex(new Uint8Array(buf, 0, 8));
  }

  /* ---- 文本处理 ---- */
  function b9Defang(t, refang) {
    if (refang) return t.replace(/\[\.\]/g, '.').replace(/\[:\/\/\]/g, '://').replace(/\[@\]/g, '@').replace(/\[:\]/g, ':').replace(/hxxp/gi, 'http');
    return t.replace(/([a-z])tp(s?):\/\//gi, '$1xxp$2://').replace(/:\/\//g, '[://]').replace(/\./g, '[.]').replace(/@/g, '[@]');
  }
  function b9LineBreak(t, to) {
    var s = t.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    if (to === 'crlf') return s.replace(/\n/g, '\r\n');
    if (to === 'cr') return s.replace(/\n/g, '\r');
    return s;
  }
  function b9Levenshtein(a, b) {
    if (a === b) return 'Distance: 0\nSimilarity: 100%';
    if (!a.length || !b.length) { var d0 = Math.max(a.length, b.length); return 'Distance: ' + d0 + '\nSimilarity: 0%'; }
    var m = a.length, n = b.length, prev = [], cur = [], i, j;
    for (j = 0; j <= n; j++) prev[j] = j;
    for (i = 1; i <= m; i++) {
      cur[0] = i;
      for (j = 1; j <= n; j++) cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
      for (j = 0; j <= n; j++) prev[j] = cur[j];
    }
    var d = prev[n], maxL = Math.max(m, n);
    return 'Distance: ' + d + '\nSimilarity: ' + (100 * (1 - d / maxL)).toFixed(1) + '%';
  }
  function b9ExtractDomains(t) {
    var m = t.match(/\b(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,24}\b/g) || [];
    var seen = {}, out = [];
    m.forEach(function (d) { var k = d.toLowerCase(); if (!seen[k]) { seen[k] = 1; out.push(k); } });
    return out.length ? out.join('\n') + '\n\n(' + out.length + ' unique)' : 'No domains found';
  }
  function b9ExtractMacs(t) {
    var m = t.match(/\b(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}\b|\b(?:[0-9A-Fa-f]{4}\.){2}[0-9A-Fa-f]{4}\b/g) || [];
    var seen = {}, out = [];
    m.forEach(function (x) { var k = x.toLowerCase().replace(/-/g, ':'); if (!seen[k]) { seen[k] = 1; out.push(k); } });
    return out.length ? out.join('\n') + '\n\n(' + out.length + ' found)' : 'No MAC addresses found';
  }
  function b9ListCompare(a, b) {
    function toArr(s) { var seen = {}, out = []; toLines(s).forEach(function (l) { var k = l.trim(); if (k && !seen[k]) { seen[k] = 1; out.push(k); } }); return out; }
    var A = toArr(a), B = toArr(b), sA = {}, sB = {}, i;
    for (i = 0; i < A.length; i++) sA[A[i]] = 1;
    for (i = 0; i < B.length; i++) sB[B[i]] = 1;
    var both = A.filter(function (x) { return sB[x]; });
    var onlyA = A.filter(function (x) { return !sB[x]; });
    var onlyB = B.filter(function (x) { return !sA[x]; });
    return '=== Common (' + both.length + ') ===\n' + both.join('\n') + '\n\n=== Only in A (' + onlyA.length + ') ===\n' + onlyA.join('\n') + '\n\n=== Only in B (' + onlyB.length + ') ===\n' + onlyB.join('\n') + '\n\n=== Union (' + A.concat(onlyB).length + ') ===\n' + A.concat(onlyB).join('\n');
  }
  function b9Pipeline(t, steps) {
    var names = steps.split(/[,，\s]+/).filter(Boolean);
    if (!names.length) return 'Error: enter transform names, e.g. upper, trimLines, kebab';
    var cur = t, log = [];
    for (var i = 0; i < names.length; i++) {
      var nm = names[i];
      if (typeof T[nm] !== 'function') return 'Error: unknown transform "' + nm + '"\nAvailable: ' + Object.keys(T).slice(0, 40).join(', ') + ' ...';
      cur = T[nm](cur);
      log.push('[' + (i + 1) + '] ' + nm + ' → ' + cur.slice(0, 200));
    }
    return log.join('\n') + '\n\n=== RESULT ===\n' + cur;
  }

  /* ---- 校验和与哈希分析 ---- */
  function b9Entropy(t) {
    if (!t) return 'Shannon entropy: 0 bits/char';
    var f = {}, i;
    for (i = 0; i < t.length; i++) f[t[i]] = (f[t[i]] || 0) + 1;
    var H = 0, len = t.length;
    Object.keys(f).forEach(function (k) { var p = f[k] / len; H -= p * (Math.log(p) / Math.LN2); });
    var uniq = Object.keys(f).length;
    return 'Shannon entropy: ' + H.toFixed(4) + ' bits/char\nTotal: ' + (H * len).toFixed(2) + ' bits in ' + len + ' chars\nUnique symbols: ' + uniq + '\nMax possible: ' + (Math.log(uniq) / Math.LN2).toFixed(4) + ' bits/char\nEfficiency: ' + (uniq > 1 ? (100 * H / (Math.log(uniq) / Math.LN2)).toFixed(1) : '0') + '%';
  }
  function b9Fletcher(t, bits) {
    var b = b9U8(t), i, s1 = 0, s2 = 0;
    if (bits === '8') { for (i = 0; i < b.length; i++) { s1 = (s1 + b[i]) % 15; s2 = (s2 + s1) % 15; } return 'Fletcher-8: 0x' + ('0' + ((s2 << 4) | s1).toString(16)).slice(-2).toUpperCase(); }
    if (bits === '32') { for (i = 0; i < b.length; i++) { s1 = (s1 + b[i]) % 65535; s2 = (s2 + s1) % 65535; } return 'Fletcher-32: 0x' + b9U64(((s2 << 16) | s1) >>> 0).toUpperCase(); }
    if (bits === '64') { for (i = 0; i < b.length; i++) { s1 = (s1 + b[i]) % 4294967295; s2 = (s2 + s1) % 4294967295; } return 'Fletcher-64: 0x' + (s1 >>> 0).toString(16) + ' ' + (s2 >>> 0).toString(16); }
    for (i = 0; i < b.length; i++) { s1 = (s1 + b[i]) % 255; s2 = (s2 + s1) % 255; }
    return 'Fletcher-16: 0x' + ('000' + (((s2 << 8) | s1)).toString(16)).slice(-4).toUpperCase();
  }
  function b9Murmur3(key, seed) {
    var remainder = key.length & 3, bytes = key.length - remainder, h1 = seed >>> 0;
    var c1 = 0xcc9e2d51, c2 = 0x1b873593, i = 0, k1, h1b;
    while (i < bytes) {
      k1 = (key.charCodeAt(i) & 0xff) | ((key.charCodeAt(i + 1) & 0xff) << 8) | ((key.charCodeAt(i + 2) & 0xff) << 16) | ((key.charCodeAt(i + 3) & 0xff) << 24);
      i += 4;
      k1 = Math.imul(k1, c1) >>> 0; k1 = (k1 << 15) | (k1 >>> 17); k1 = Math.imul(k1, c2) >>> 0;
      h1 ^= k1; h1 = (h1 << 13) | (h1 >>> 19);
      h1b = Math.imul(h1, 5) + 0xe6546b64; h1 = h1b >>> 0;
    }
    k1 = 0;
    switch (remainder) {
      case 3: k1 ^= (key.charCodeAt(i + 2) & 0xff) << 16;
      case 2: k1 ^= (key.charCodeAt(i + 1) & 0xff) << 8;
      case 1: k1 ^= (key.charCodeAt(i) & 0xff);
        k1 = Math.imul(k1, c1) >>> 0; k1 = (k1 << 15) | (k1 >>> 17); k1 = Math.imul(k1, c2) >>> 0; h1 ^= k1;
    }
    h1 ^= key.length;
    h1 ^= h1 >>> 16; h1 = Math.imul(h1, 0x85ebca6b) >>> 0;
    h1 ^= h1 >>> 13; h1 = Math.imul(h1, 0xc2b2ae35) >>> 0;
    h1 ^= h1 >>> 16;
    return 'MurmurHash3-32: ' + ('00000000' + (h1 >>> 0).toString(16)).slice(-8) + '\nDecimal: ' + (h1 >>> 0);
  }
  function b9Crc32(t) {
    if (typeof pako === 'undefined') return 'Error: compression library not loaded';
    var c = pako.crc32(t, 0) >>> 0;
    return 'CRC-32: 0x' + ('00000000' + c.toString(16)).slice(-8).toUpperCase() + '\nDecimal: ' + c;
  }
  function b9HashId(t) {
    var s = String(t).trim(), r = [];
    if (!s) return 'Enter a hash to identify';
    if (/^[a-f0-9]{8}$/i.test(s)) r.push('CRC-32 / Adler-32 / Fletcher-32 (low)');
    if (/^[a-f0-9]{16}$/i.test(s)) r.push('MySQL 3.23 / DES half / VNC');
    if (/^[a-f0-9]{32}$/i.test(s)) r.push('MD5', 'NTLM', 'LM (half)', 'MD4', 'RIPEMD-128', 'Haval-128', 'Snefru-128');
    if (/^[a-f0-9]{40}$/i.test(s)) r.push('SHA-1', 'RIPEMD-160', 'Tiger-160', 'Haval-160', 'MySQL 4.1 (raw hex)');
    if (/^[a-f0-9]{48}$/i.test(s)) r.push('Tiger-192 / Haval-192 / SHA-1 + salt');
    if (/^[a-f0-9]{56}$/i.test(s)) r.push('SHA-224', 'Keccak-224', 'SHA3-224', 'Haval-224');
    if (/^[a-f0-9]{64}$/i.test(s)) r.push('SHA-256', 'SHA3-256', 'Keccak-256', 'BLAKE2s-256', 'SM3 (国密)', 'RIPEMD-256', 'Haval-256');
    if (/^[a-f0-9]{96}$/i.test(s)) r.push('SHA-384', 'SHA3-384', 'Keccak-384');
    if (/^[a-f0-9]{128}$/i.test(s)) r.push('SHA-512', 'SHA3-512', 'Keccak-512', 'BLAKE2b-512', 'Whirlpool', 'Skein-512');
    if (/^\*[a-f0-9]{40}$/i.test(s)) r.push('MySQL 4.1 / MySQL 5+ (*A4B...)');
    if (/^\$1\$/.test(s)) r.push('MD5 crypt (Unix, $1$)');
    if (/^\$2[axyb]?\$/.test(s)) r.push('bcrypt ($2a$ / $2y$ / $2b$)');
    if (/^\$5\$/.test(s)) r.push('SHA-256 crypt ($5$)');
    if (/^\$6\$/.test(s)) r.push('SHA-512 crypt ($6$)');
    if (/^\$argon2(id|i|d)\$/.test(s)) r.push('Argon2');
    if (/^\$scrypt\$/.test(s)) r.push('scrypt');
    if (/^\$pbkdf2(-sha(1|256|512))?\$/i.test(s)) r.push('PBKDF2');
    if (/^sha1\$/i.test(s)) r.push('Django SHA1');
    if (/^pbkdf2_sha256\$/i.test(s)) r.push('Django PBKDF2-SHA256');
    if (/^[A-Za-z0-9+/]{27}=$/.test(s)) r.push('SHA-224 (base64)');
    if (/^[A-Za-z0-9+/]{43}=$/.test(s)) r.push('SHA-256 (base64)');
    if (/^[A-Za-z0-9+/]{86}==$/.test(s)) r.push('SHA-512 (base64)');
    if (/^[A-Za-z0-9+/]{22}(==)?$/.test(s)) r.push('MD5 (base64)');
    if (/^[a-f0-9]{4,}$/i.test(s) && s.length % 2 === 0 && s.length < 8) r.push('CRC-16 / short checksum');
    if (/^[0-9]{10}$/.test(s)) r.push('Unix timestamp (10 digits)');
    if (/^[0-9]{13}$/.test(s)) r.push('Unix timestamp in ms (13 digits)');
    if (!r.length) return 'Could not identify this hash.\nLength: ' + s.length + ' chars\nCharset: ' + (/^[a-f0-9]+$/i.test(s) ? 'hex' : /^[A-Za-z0-9+/=]+$/.test(s) ? 'base64' : 'mixed');
    return 'Possible algorithms:\n- ' + r.join('\n- ') + '\n\nLength: ' + s.length + ' chars';
  }

  /* ---- 压缩（pako） ---- */
  function b9Pako(t, mode, level) {
    if (typeof pako === 'undefined') return 'Error: compression library not loaded';
    try {
      if (mode === 'ungzip') { var raw = b9Unb64(t); if (!raw) return 'Error: invalid base64 input'; return b9Str(pako.ungzip(raw)); }
      if (mode === 'inflate') { var raw2 = b9Unb64(t); if (!raw2) return 'Error: invalid base64 input'; return b9Str(pako.inflate(raw2)); }
      if (!t) return '';
      var src = b9U8(t);
      var out = mode === 'gzip' ? pako.gzip(src, { level: level }) : pako.deflate(src, { level: level });
      return b9B64(out) + '\n\n(' + src.length + ' B → ' + out.length + ' B, ' + (src.length ? (100 - 100 * out.length / src.length).toFixed(1) : '0') + '% saved)';
    } catch (e) { return 'Error: ' + e.message; }
  }

  /* ---- JWT / HOTP（WebCrypto） ---- */
  function b9B64UrlEncode(str) { return btoa(unescape(encodeURIComponent(str))).replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_'); }
  function b9B64UrlDecode(s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '='; return b9Str(base64ToBytes(s)); }
  function b9Jwt(t, secret, verify, alg) {
    if (!secret) return 'Error: please enter a secret';
    var map = { HS256: 'SHA-256', HS384: 'SHA-384', HS512: 'SHA-512' };
    if (!map[alg]) return 'Error: unsupported algorithm';
    function impKey() { return crypto.subtle.importKey('raw', b9U8(secret), { name: 'HMAC', hash: map[alg] }, false, ['sign', 'verify']); }
    if (verify) {
      var parts = String(t).trim().split('.');
      if (parts.length !== 3) return 'Error: a JWT must have 3 parts separated by dots';
      var signingInput = parts[0] + '.' + parts[1];
      return impKey().then(function (key) {
        return crypto.subtle.sign({ name: 'HMAC' }, key, b9U8(signingInput));
      }).then(function (sig) {
        var ok = b9B64UrlEncode(String.fromCharCode.apply(null, new Uint8Array(sig))) === parts[2];
        try { var hdr = JSON.parse(b9B64UrlDecode(parts[0])), pl = JSON.parse(b9B64UrlDecode(parts[1])); var exp = pl.exp ? (pl.exp * 1000 < Date.now() ? 'EXPIRED' : 'valid until ' + new Date(pl.exp * 1000).toLocaleString()) : 'no exp claim'; return (ok ? '✓ Signature VALID' : '✗ Signature INVALID') + '\n\nHeader:\n' + JSON.stringify(hdr, null, 2) + '\n\nPayload:\n' + JSON.stringify(pl, null, 2) + '\n\nExpiry: ' + exp; } catch (e) { return (ok ? '✓ Signature VALID' : '✗ Signature INVALID') + '\n\nPayload (raw): ' + b9B64UrlDecode(parts[1]); }
      }).catch(function (e) { return 'Error: ' + e.message; });
    }
    var header = { alg: alg, typ: 'JWT' }, payload;
    try { payload = JSON.parse(t); } catch (e) { payload = { sub: '1234567890', name: 'HeronTool', iat: Math.floor(Date.now() / 1000) }; }
    var h = b9B64UrlEncode(JSON.stringify(header)), p = b9B64UrlEncode(JSON.stringify(payload));
    return impKey().then(function (key) { return crypto.subtle.sign({ name: 'HMAC' }, key, b9U8(h + '.' + p)); }).then(function (sig) {
      return h + '.' + p + '.' + b9B64UrlEncode(String.fromCharCode.apply(null, new Uint8Array(sig)));
    }).catch(function (e) { return 'Error: ' + e.message; });
  }
  function b9Hotp(secret, counter, digits) {
    if (!secret) return 'Error: please enter a secret';
    try {
      var keyBytes = /^[A-Z2-7]+=*$/i.test(secret.replace(/\s/g, '')) ? base64ToBytes(secret.replace(/\s/g, '')) : b9U8(secret);
      return crypto.subtle.importKey('raw', keyBytes, { name: 'HMAC', hash: 'SHA-1' }, false, ['sign']).then(function (key) {
        var buf = new ArrayBuffer(8), dv = new DataView(buf);
        dv.setUint32(0, Math.floor(counter / 4294967296)); dv.setUint32(4, counter >>> 0);
        return crypto.subtle.sign({ name: 'HMAC' }, key, buf);
      }).then(function (sig) {
        var h = new Uint8Array(sig), off = h[19] & 0x0f;
        var code = ((h[off] & 0x7f) << 24) | (h[off + 1] << 16) | (h[off + 2] << 8) | h[off + 3];
        var otp = code % Math.pow(10, digits);
        return 'HOTP: ' + String(otp).padStart(digits, '0') + '\nCounter: ' + counter + '\nDigits: ' + digits;
      }).catch(function (e) { return 'Error: ' + e.message; });
    } catch (e) { return 'Error: ' + e.message; }
  }

  /* ---- UUID v1 / v6 ---- */
  function b9UuidV1() {
    var b = new Uint8Array(16); crypto.getRandomValues(b);
    var t = Date.now() * 10000 + 0x01b21dd213814000;
    var tl = t % 4294967296, th = Math.floor(t / 4294967296);
    b[0] = (tl >>> 24) & 255; b[1] = (tl >>> 16) & 255; b[2] = (tl >>> 8) & 255; b[3] = tl & 255;
    b[4] = (th >>> 8) & 255; b[5] = th & 255;
    b[6] = ((th >>> 24) & 0x0f) | 0x10; b[7] = (th >>> 16) & 255;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = b9Hex(b);
    return h.substr(0, 8) + '-' + h.substr(8, 4) + '-' + h.substr(12, 4) + '-' + h.substr(16, 4) + '-' + h.substr(20);
  }
  function b9UuidV6() {
    var b = new Uint8Array(16); crypto.getRandomValues(b);
    var t = Date.now() * 10000 + 0x01b21dd213814000;
    var hi = Math.floor(t / 4294967296) & 0x0fffffff, lo = t % 4294967296;
    b[0] = (hi >>> 24) & 255; b[1] = (hi >>> 16) & 255; b[2] = (hi >>> 8) & 255; b[3] = hi & 255;
    b[4] = (lo >>> 24) & 255; b[5] = (lo >>> 16) & 255;
    b[6] = ((lo >>> 8) & 0x0f) | 0x60; b[7] = lo & 255;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = b9Hex(b);
    return h.substr(0, 8) + '-' + h.substr(8, 4) + '-' + h.substr(12, 4) + '-' + h.substr(16, 4) + '-' + h.substr(20);
  }

  /* ---- SM4 国密分组算法（ECB / PKCS#7） ---- */
  var SM4_SBOX = [0xd6,0x90,0xe9,0xfe,0xcc,0xe1,0x3d,0xb7,0x16,0xb6,0x14,0xc2,0x28,0xfb,0x2c,0x05,0x2b,0x67,0x9a,0x76,0x2a,0xbe,0x04,0xc3,0xaa,0x44,0x13,0x26,0x49,0x86,0x06,0x99,0x9c,0x42,0x50,0xf4,0x91,0xef,0x98,0x7a,0x33,0x54,0x0b,0x43,0xed,0xcf,0xac,0x62,0xe4,0xb3,0x1c,0xa9,0xc9,0x08,0xe8,0x95,0x80,0xdf,0x94,0xfa,0x75,0x8f,0x3f,0xa6,0x47,0x07,0xa7,0xfc,0xf3,0x73,0x17,0xba,0x83,0x59,0x3c,0x19,0xe6,0x85,0x4f,0xa8,0x68,0x6b,0x81,0xb2,0x71,0x64,0xda,0x8b,0xf8,0xeb,0x0f,0x4b,0x70,0x56,0x9d,0x35,0x1e,0x24,0x0e,0x5e,0x63,0x58,0xd1,0xa2,0x25,0x22,0x7c,0x3b,0x01,0x21,0x78,0x87,0xd4,0x00,0x46,0x57,0x9f,0xd3,0x27,0x52,0x4c,0x36,0x02,0xe7,0xa0,0xc4,0xc8,0x9e,0xea,0xbf,0x8a,0xd2,0x40,0xc7,0x38,0xb5,0xa3,0xf7,0xf2,0xce,0xf9,0x61,0x15,0xa1,0xe0,0xae,0x5d,0xa4,0x9b,0x34,0x1a,0x55,0xad,0x93,0x32,0x30,0xf5,0x8c,0xb1,0xe3,0x1d,0xf6,0xe2,0x2e,0x82,0x66,0xca,0x60,0xc0,0x29,0x23,0xab,0x0d,0x53,0x4e,0x6f,0xd5,0xdb,0x37,0x45,0xde,0xfd,0x8e,0x2f,0x03,0xff,0x6a,0x72,0x6d,0x6c,0x5b,0x51,0x8d,0x1b,0xaf,0x92,0xbb,0xdd,0xbc,0x7f,0x11,0xd9,0x5c,0x41,0x1f,0x10,0x5a,0xd8,0x0a,0xc1,0x31,0x88,0xa5,0xcd,0x7b,0xbd,0x2d,0x74,0xd0,0x12,0xb8,0xe5,0xb4,0xb0,0x89,0x69,0x97,0x4a,0x0c,0x96,0x77,0x7e,0x65,0xb9,0xf1,0x09,0xc5,0x6e,0xc6,0x84,0x18,0xf0,0x7d,0xec,0x3a,0xdc,0x4d,0x20,0x79,0xee,0x5f,0x3e,0xd7,0xcb,0x39,0x48];
  var SM4_CK = [0x00070e15,0x1c232a31,0x383f464d,0x545b6269,0x70777e85,0x8c939aa1,0xa8afb6bd,0xc4cbd2d9,0xe0e7eef5,0xfc030a11,0x181f262d,0x343b4249,0x50575e65,0x6c737a81,0x888f969d,0xa4abb2b9,0xc0c7ced5,0xdce3eaf1,0xf8ff060d,0x141b2229,0x30373e45,0x4c535a61,0x686f767d,0x848b9299,0xa0a7aeb5,0xbcc3cad1,0xd8dfe6ed,0xf4fb0209,0x10171e25,0x2c333a41,0x484f565d,0x646b7279];
  var SM4_FK = [0xa3b1bac6, 0x56aa3350, 0x677d9197, 0xb27022dc];
  function sm4Rotl(x, n) { return ((x << n) | (x >>> (32 - n))) >>> 0; }
  function sm4Tau(a) { var o = 0; for (var i = 0; i < 4; i++) { var b = (a >>> (24 - 8 * i)) & 255; o = (o << 8) | SM4_SBOX[b]; } return o >>> 0; }
  function sm4L(b) { return (b ^ sm4Rotl(b, 2) ^ sm4Rotl(b, 10) ^ sm4Rotl(b, 18) ^ sm4Rotl(b, 24)) >>> 0; }
  function sm4Lp(b) { return (b ^ sm4Rotl(b, 13) ^ sm4Rotl(b, 23)) >>> 0; }
  function sm4KeyExt(mk) {
    var K = [], rk = [], i;
    for (i = 0; i < 4; i++) K.push((mk[i] ^ SM4_FK[i]) >>> 0);
    for (i = 0; i < 32; i++) {
      var x = (K[i + 1] ^ K[i + 2] ^ K[i + 3] ^ SM4_CK[i]) >>> 0;
      K.push((K[i] ^ sm4Lp(sm4Tau(x))) >>> 0);
      rk.push(K[i + 4]);
    }
    return rk;
  }
  function sm4Block(x, rk) {
    var X = x.slice(), i;
    for (i = 0; i < 32; i++) {
      var t = (X[i + 1] ^ X[i + 2] ^ X[i + 3] ^ rk[i]) >>> 0;
      X.push((X[i] ^ sm4L(sm4Tau(t))) >>> 0);
    }
    return [X[35], X[34], X[33], X[32]];
  }
  function b9Sm4(t, key, dec, encFmt) {
    try {
      var kb = Array.prototype.slice.call(b9U8(key));
      if (kb.length !== 16) return 'Error: SM4 requires a 16-byte (128-bit) key, got ' + kb.length;
      var mk = [];
      for (var q = 0; q < 4; q++) mk.push(((kb[q * 4] << 24) | (kb[q * 4 + 1] << 16) | (kb[q * 4 + 2] << 8) | kb[q * 4 + 3]) >>> 0);
      var rk = sm4KeyExt(mk);
      if (dec) rk = rk.slice().reverse();
      var data;
      if (dec) data = Array.prototype.slice.call(b9Unhex(t));
      else data = Array.prototype.slice.call(b9U8(t));
      if (!data.length) return '';
      if (!dec) { var pad = 16 - (data.length % 16); for (var pz = 0; pz < pad; pz++) data.push(pad); }
      if (data.length % 16) return 'Error: ciphertext length must be a multiple of 16 bytes';
      var out = [];
      for (var i = 0; i < data.length; i += 16) {
        var blk = [];
        for (var q2 = 0; q2 < 4; q2++) blk.push(((data[i + q2 * 4] << 24) | (data[i + q2 * 4 + 1] << 16) | (data[i + q2 * 4 + 2] << 8) | data[i + q2 * 4 + 3]) >>> 0);
        var r = sm4Block(blk, rk);
        for (var q3 = 0; q3 < 4; q3++) { out.push((r[q3] >>> 24) & 255, (r[q3] >>> 16) & 255, (r[q3] >>> 8) & 255, r[q3] & 255); }
      }
      if (dec) {
        var padLen = out[out.length - 1];
        if (padLen >= 1 && padLen <= 16) { var ok = true; for (var z = 0; z < padLen; z++) if (out[out.length - 1 - z] !== padLen) ok = false; if (ok) out = out.slice(0, out.length - padLen); }
        return b9Str(new Uint8Array(out));
      }
      return b9Hex(new Uint8Array(out));
    } catch (e) { return 'Error: ' + e.message; }
  }

  /* ---- CSV / 表格 / SQL / Markdown 转换 ---- */
  function b9ParseCsv(t, dlm) {
    dlm = dlm || ',';
    var rows = [], row = [], cur = '', q = false, i;
    for (i = 0; i < t.length; i++) {
      var c = t[i];
      if (q) { if (c === '"') { if (t[i + 1] === '"') { cur += '"'; i++; } else q = false; } else cur += c; }
      else {
        if (c === '"') q = true;
        else if (c === dlm) { row.push(cur); cur = ''; }
        else if (c === '\n') { row.push(cur); rows.push(row); row = []; cur = ''; }
        else if (c !== '\r') cur += c;
      }
    }
    if (cur !== '' || row.length) { row.push(cur); rows.push(row); }
    return rows;
  }
  function b9CsvCell(s, dlm) {
    s = String(s == null ? '' : s);
    if (s.indexOf('"') >= 0 || s.indexOf(dlm) >= 0 || s.indexOf('\n') >= 0 || s.indexOf('\r') >= 0) return '"' + s.replace(/"/g, '""') + '"';
    return s;
  }
  function b9Esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function b9Csv2Html(t, head, cls) {
    var rows = b9ParseCsv(t);
    if (!rows.length) return '';
    var h = '<table' + (cls ? ' class="' + cls + '"' : '') + '>\n', start = 0;
    if (head) { h += '  <thead>\n    <tr>\n' + rows[0].map(function (c) { return '      <th>' + b9Esc(c) + '</th>'; }).join('\n') + '\n    </tr>\n  </thead>\n'; start = 1; }
    h += '  <tbody>\n' + rows.slice(start).map(function (r) { return '    <tr>\n' + r.map(function (c) { return '      <td>' + b9Esc(c) + '</td>'; }).join('\n') + '\n    </tr>'; }).join('\n') + '\n  </tbody>\n</table>';
    return h;
  }
  function b9Html2Csv(t) {
    var doc = new DOMParser().parseFromString(t, 'text/html'), table = doc.querySelector('table');
    if (!table) return 'Error: no <table> element found';
    var rows = table.querySelectorAll('tr'), out = [];
    for (var i = 0; i < rows.length; i++) {
      var cells = rows[i].querySelectorAll('th,td'), arr = [];
      for (var j = 0; j < cells.length; j++) arr.push(b9CsvCell(cells[j].textContent.trim(), ','));
      out.push(arr.join(','));
    }
    return out.join('\n');
  }
  function b9CsvTranspose(t, dlm) {
    var rows = b9ParseCsv(t, dlm);
    if (!rows.length) return '';
    var cols = Math.max.apply(null, rows.map(function (r) { return r.length; })), out = [];
    for (var c = 0; c < cols; c++) {
      var row = [];
      for (var r = 0; r < rows.length; r++) row.push(b9CsvCell(rows[r][c] == null ? '' : rows[r][c], dlm));
      out.push(row.join(dlm));
    }
    return out.join('\n');
  }
  function b9Csv2Sql(t, table) {
    var rows = b9ParseCsv(t);
    if (!rows.length) return '';
    var head = rows[0], safe = String(table || 'my_table').replace(/[^A-Za-z0-9_]/g, '_');
    function ident(s) { return '`' + String(s == null ? '' : s).replace(/`/g, '``') + '`'; }
    var out = ['CREATE TABLE IF NOT EXISTS ' + ident(safe) + ' (', '  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,'];
    out.push(head.map(function (c, i) { return '  ' + ident(c) + ' TEXT' + (i === head.length - 1 ? '' : ','); }).join('\n'));
    out.push(');', '');
    rows.slice(1).forEach(function (r) {
      var vals = head.map(function (_, i) { return "'" + String(r[i] == null ? '' : r[i]).replace(/'/g, "''") + "'"; }).join(', ');
      out.push('INSERT INTO ' + ident(safe) + ' (' + head.map(ident).join(', ') + ') VALUES (' + vals + ');');
    });
    return out.join('\n');
  }
  function b9MdTable(t, align) {
    var rows = b9ParseCsv(t);
    if (!rows.length) return '';
    var cols = Math.max.apply(null, rows.map(function (r) { return r.length; }));
    function cell(c) { return String(c == null ? '' : c).replace(/\|/g, '\\|').replace(/\s*[\n\r]+\s*/g, ' '); }
    function w(s) { return cell(s).replace(/[^\x00-\xff]/g, 'xx').length; }
    function pad(s, width, a) {
      s = cell(s); var d = width - w(s); if (d < 0) d = 0;
      if (a === 'right') return new Array(d + 1).join(' ') + s;
      if (a === 'center') { var l = Math.floor(d / 2); return new Array(l + 1).join(' ') + s + new Array(d - l + 1).join(' '); }
      return s + new Array(d + 1).join(' ');
    }
    var widths = [];
    for (var c = 0; c < cols; c++) { var mw = 3; for (var r = 0; r < rows.length; r++) { var l = w(rows[r][c]); if (l > mw) mw = l; } widths.push(mw); }
    var lines = ['| ' + rows[0].map(function (x, i) { return pad(x, widths[i], align); }).join(' | ') + ' |'];
    if (align === 'right') lines.push('|' + widths.map(function (x) { return ' ' + new Array(x).join('-') + ':'; }).join('|') + '|');
    else if (align === 'center') lines.push('|' + widths.map(function (x) { return ':' + new Array(x - 1).join('-') + ':'; }).join('|') + '|');
    else lines.push('| ' + widths.map(function (x) { return new Array(x + 1).join('-'); }).join(' | ') + ' |');
    rows.slice(1).forEach(function (r) { lines.push('| ' + widths.map(function (x, i) { return pad(r[i], x, align); }).join(' | ') + ' |'); });
    return lines.join('\n');
  }

  /* ---- SVG Data URI ---- */
  function b9Svg2Uri(t, useB64) {
    var s = String(t).trim();
    if (!s) return '';
    if (useB64) return 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(s)));
    return 'data:image/svg+xml,' + s.replace(/\s*\n\s*/g, '').replace(/%/g, '%25').replace(/#/g, '%23').replace(/"/g, "'").replace(/</g, '%3C').replace(/>/g, '%3E').replace(/\s+/g, ' ');
  }
  function b9SvgSprite(t) {
    var svgs = String(t).match(/<svg[\s\S]*?<\/svg>/gi) || [];
    if (!svgs.length) return 'Error: no <svg> element found. Paste one or more SVG icons.';
    var syms = [], usage = [];
    svgs.forEach(function (svg, i) {
      var vb = (svg.match(/viewBox\s*=\s*["']([^"']+)["']/i) || [])[1] || '0 0 24 24';
      var id = (svg.match(/\sid\s*=\s*["']([^"']+)["']/i) || [])[1] || ('icon-' + (i + 1));
      var inner = svg.replace(/^[\s\S]*?<svg[^>]*>/i, '').replace(/<\/svg>\s*$/i, '');
      inner = inner.replace(/\sid\s*=\s*["'][^"']*["']/gi, '').replace(/\sxmlns(:[\w-]+)?\s*=\s*["'][^"']*["']/gi, '').replace(/\s+/g, ' ').trim();
      syms.push('  <symbol id="' + id + '" viewBox="' + vb + '">' + inner + '</symbol>');
      usage.push('<svg width="24" height="24"><use href="#' + id + '"></use></svg>');
    });
    return '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">\n' + syms.join('\n') + '\n</svg>\n\n<!-- Usage -->\n' + usage.join('\n');
  }

  /* ---- HTML / SVG → JSX ---- */
  function b9Attr2Jsx(name) {
    var map = { 'class': 'className', 'for': 'htmlFor', 'tabindex': 'tabIndex', 'readonly': 'readOnly', 'maxlength': 'maxLength', 'autofocus': 'autoFocus', 'colspan': 'colSpan', 'rowspan': 'rowSpan', 'novalidate': 'noValidate', 'spellcheck': 'spellCheck', 'autocomplete': 'autoComplete', 'enctype': 'encType', 'srcset': 'srcSet', 'contenteditable': 'contentEditable', 'crossorigin': 'crossOrigin', 'datetime': 'dateTime', 'stroke-width': 'strokeWidth', 'stroke-linecap': 'strokeLinecap', 'stroke-linejoin': 'strokeLinejoin', 'fill-rule': 'fillRule', 'clip-rule': 'clipRule', 'stop-color': 'stopColor' };
    if (map[name]) return map[name];
    if (/^on[a-z]+$/.test(name)) return 'on' + name[2].toUpperCase() + name.slice(3);
    if (/^(data|aria)-/.test(name)) return name;
    if (name.indexOf('-') >= 0) return name.replace(/-([a-z])/g, function (m, c) { return c.toUpperCase(); });
    return name;
  }
  function b9Style2Obj(v) {
    var o = {};
    String(v).split(';').forEach(function (d) {
      var i = d.indexOf(':'); if (i < 0) return;
      var k = d.slice(0, i).trim(); if (!k) return;
      o[k.replace(/-([a-z])/g, function (m, c) { return c.toUpperCase(); })] = d.slice(i + 1).trim();
    });
    return o;
  }
  function b9Html2Jsx(t) {
    var s = String(t).trim();
    if (!s) return '';
    var doc = new DOMParser().parseFromString('<div id="__b9root">' + s + '</div>', 'text/html');
    var root = doc.getElementById('__b9root');
    if (!root) return '';
    var lines = [];
    function walk(node, ind) {
      Array.prototype.forEach.call(node.childNodes, function (n) {
        if (n.nodeType === 3) { var tx = n.textContent.replace(/\s+/g, ' ').trim(); if (tx) lines.push(ind + tx); return; }
        if (n.nodeType === 8) { lines.push(ind + '{/*' + n.textContent + '*/}'); return; }
        if (n.nodeType !== 1) return;
        var tag = n.tagName.toLowerCase(), attrs = [];
        for (var i = 0; i < n.attributes.length; i++) {
          var a = n.attributes[i], nm = b9Attr2Jsx(a.name), v = a.value;
          if (nm === 'style') { var so = b9Style2Obj(v), ks = Object.keys(so), LB = '{', RB = '}'; attrs.push(ks.length ? 'style=' + LB + LB + ' ' + ks.map(function (k) { return (/^[A-Za-z_$][\w$]*$/.test(k) ? k : "'" + k + "'") + ": '" + so[k] + "'"; }).join(', ') + ' ' + RB + RB : 'style=' + LB + LB + RB + RB); }
          else if (/^on[A-Z]/.test(nm)) attrs.push(nm + '={() => {}}');
          else if (v === '') attrs.push(nm);
          else attrs.push(nm + '="' + v.replace(/"/g, '&quot;') + '"');
        }
        var elems = 0, texts = [];
        Array.prototype.forEach.call(n.childNodes, function (c) { if (c.nodeType === 1) elems++; else if (c.nodeType === 3 && c.textContent.trim()) texts.push(c.textContent.replace(/\s+/g, ' ').trim()); });
        var attrStr = attrs.length ? ' ' + attrs.join(' ') : '';
        if (!elems && texts.length === 1) { lines.push(ind + '<' + tag + attrStr + '>' + texts[0] + '</' + tag + '>'); return; }
        if (!elems && !texts.length) { lines.push(ind + '<' + tag + attrStr + ' />'); return; }
        lines.push(ind + '<' + tag + attrStr + '>');
        walk(n, ind + '  ');
        lines.push(ind + '</' + tag + '>');
      });
    }
    walk(root, '');
    return lines.join('\n');
  }
  function b9Svg2Jsx(t, name) {
    var body = b9Html2Jsx(t);
    if (!body) return '';
    var comp = String(name || 'MyIcon').replace(/[^A-Za-z0-9_]/g, '') || 'MyIcon';
    comp = comp.charAt(0).toUpperCase() + comp.slice(1);
    var indented = body.split('\n').map(function (l) { return '    ' + l; }).join('\n');
    return 'export default function ' + comp + '(props) {\n  return (\n' + indented + '\n  );\n}';
  }
  function b9Html2Pug(t) {
    var s = String(t).trim();
    if (!s) return '';
    var doc = new DOMParser().parseFromString('<div id="__b9r">' + s + '</div>', 'text/html');
    var root = doc.getElementById('__b9r');
    if (!root) return '';
    var lines = [];
    function walk(n, ind) {
      Array.prototype.forEach.call(n.childNodes, function (c) {
        if (c.nodeType === 3) { var tx = c.textContent.replace(/\s+/g, ' ').trim(); if (tx) lines.push(ind + '| ' + tx); return; }
        if (c.nodeType === 8) { lines.push(ind + '//' + c.textContent); return; }
        if (c.nodeType !== 1) return;
        var tag = c.tagName.toLowerCase(), cls = c.getAttribute('class'), ida = c.getAttribute('id');
        var head = tag + (ida ? '#' + ida : '') + (cls ? '.' + cls.trim().split(/\s+/).join('.') : '');
        var attrs = [];
        for (var i = 0; i < c.attributes.length; i++) { var a = c.attributes[i]; if (a.name === 'class' || a.name === 'id') continue; attrs.push(a.name + '="' + a.value + '"'); }
        if (attrs.length) head += '(' + attrs.join(', ') + ')';
        var txt = '', elems = 0;
        Array.prototype.forEach.call(c.childNodes, function (k) { if (k.nodeType === 3) txt += k.textContent; else if (k.nodeType === 1) elems++; });
        txt = txt.replace(/\s+/g, ' ').trim();
        if (txt && !elems) lines.push(ind + head + ' ' + txt);
        else { lines.push(ind + head); walk(c, ind + '  '); }
      });
    }
    walk(root, '');
    return lines.join('\n');
  }

  /* ---- JSON → Zod / JSON Schema / Go struct ---- */
  function b9JsonParse(t) { try { return JSON.parse(t); } catch (e) { return { __err: e.message }; } }
  function b9TypeOf(v) {
    if (v === null) return 'null';
    if (Array.isArray(v)) return 'array';
    if (typeof v === 'number') return Number.isInteger(v) ? 'integer' : 'number';
    if (typeof v === 'boolean') return 'boolean';
    if (typeof v === 'string') {
      if (/^\d{4}-\d{2}-\d{2}([T ].*)?$/.test(v)) return 'date-time';
      if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'email';
      if (/^https?:\/\//i.test(v)) return 'uri';
      if (/^[0-9a-f]{8}-[0-9a-f]{4}-/i.test(v)) return 'uuid';
      return 'string';
    }
    return 'object';
  }
  function b9Json2Zod(t) {
    var o = b9JsonParse(t); if (o && o.__err) return 'Error: invalid JSON — ' + o.__err;
    function gen(v, ind) {
      ind = ind || '';
      var ty = b9TypeOf(v);
      if (ty === 'object') {
        var keys = Object.keys(v);
        if (!keys.length) return 'z.object({})';
        return 'z.object({\n' + keys.map(function (k) { return ind + '  ' + (/^[A-Za-z_$][\w$]*$/.test(k) ? k : JSON.stringify(k)) + ': ' + gen(v[k], ind + '  '); }).join(',\n') + '\n' + ind + '})';
      }
      if (ty === 'array') return v.length ? 'z.array(' + gen(v[0], ind) + ')' : 'z.array(z.unknown())';
      if (ty === 'integer') return 'z.number().int()';
      if (ty === 'number') return 'z.number()';
      if (ty === 'boolean') return 'z.boolean()';
      if (ty === 'null') return 'z.null()';
      if (ty === 'date-time') return 'z.string().datetime()';
      if (ty === 'email') return 'z.string().email()';
      if (ty === 'uri') return 'z.string().url()';
      if (ty === 'uuid') return 'z.string().uuid()';
      return 'z.string()';
    }
    return 'import { z } from "zod";\n\n' + 'const Schema = ' + gen(o, '') + ';\n\nexport default Schema;';
  }
  function b9Json2Schema(t) {
    var o = b9JsonParse(t); if (o && o.__err) return 'Error: invalid JSON — ' + o.__err;
    function gen(v) {
      var ty = b9TypeOf(v);
      if (ty === 'object') {
        var props = {}, req = [];
        Object.keys(v).forEach(function (k) { props[k] = gen(v[k]); req.push(k); });
        return { type: 'object', properties: props, required: req };
      }
      if (ty === 'array') return { type: 'array', items: v.length ? gen(v[0]) : {} };
      if (ty === 'integer') return { type: 'integer' };
      if (ty === 'number') return { type: 'number' };
      if (ty === 'boolean') return { type: 'boolean' };
      if (ty === 'null') return { type: 'null' };
      if (ty === 'date-time') return { type: 'string', format: 'date-time' };
      if (ty === 'email') return { type: 'string', format: 'email' };
      if (ty === 'uri') return { type: 'string', format: 'uri' };
      if (ty === 'uuid') return { type: 'string', format: 'uuid' };
      return { type: 'string' };
    }
    var s = { $schema: 'http://json-schema.org/draft-07/schema#', title: 'Generated schema' };
    var g = gen(o);
    Object.keys(g).forEach(function (k) { s[k] = g[k]; });
    return JSON.stringify(s, null, 2);
  }
  function b9Json2Go(t, name) {
    var o = b9JsonParse(t); if (o && o.__err) return 'Error: invalid JSON — ' + o.__err;
    var out = [], pending = [], emitted = {};
    function cap(s) { var p = String(s).replace(/[^A-Za-z0-9]+/g, ' ').trim().split(' ').map(function (w) { return w.charAt(0).toUpperCase() + w.slice(1); }).join(''); return /^[0-9]/.test(p) ? 'F' + p : p; }
    function baseType(ty) { return { integer: 'int', number: 'float64', boolean: 'bool', 'null': 'interface{}', 'date-time': 'string', email: 'string', uri: 'string', uuid: 'string', string: 'string' }[ty] || 'string'; }
    function gen(v, nm) {
      if (emitted[nm]) return nm;
      emitted[nm] = 1;
      out.push('type ' + nm + ' struct {');
      Object.keys(v).forEach(function (k) {
        var f = cap(k) || 'Field', val = v[k], ty = b9TypeOf(val), gt;
        if (ty === 'object') { gt = f; pending.push([val, f]); }
        else if (ty === 'array') {
          if (!val.length) gt = '[]interface{}';
          else if (b9TypeOf(val[0]) === 'object') { gt = '[]' + f; pending.push([val[0], f]); }
          else gt = '[]' + baseType(b9TypeOf(val[0]));
        } else gt = baseType(ty);
        out.push('\t' + f + ' ' + gt + ' `json:"' + k + '"`');
      });
      out.push('}');
      out.push('');
      while (pending.length) { var nx = pending.shift(); gen(nx[0], nx[1]); }
    }
    var root = cap(name || 'Root') || 'Root';
    if (Array.isArray(o)) { if (o.length) gen(o[0], root); else out.push('type ' + root + ' []interface{}'); }
    else gen(o, root);
    return 'package main\n\n' + out.join('\n');
  }

  /* ---- CSS ↔ JS 对象 / Tailwind ---- */
  function b9Css2Js(t, camel) {
    var s = String(t).replace(/\/\*[\s\S]*?\*\//g, '');
    var body = s.replace(/^[^{]*\{/, '').replace(/\}\s*$/, '');
    var obj = {};
    body.split(';').map(function (d) { return d.trim(); }).filter(Boolean).forEach(function (d) {
      var i = d.indexOf(':'); if (i < 0) return;
      var k = d.slice(0, i).trim(), v = d.slice(i + 1).trim();
      if (!k || k.indexOf('{') >= 0) return;
      var key = camel ? k.replace(/^--/, '').replace(/-([a-z])/g, function (m, c) { return c.toUpperCase(); }) : k;
      obj[key] = /^-?\d+(\.\d+)?$/.test(v) ? v : "'" + v.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
    });
    var ks = Object.keys(obj);
    if (!ks.length) return '{}';
    return '{\n' + ks.map(function (k) { return '  ' + (/^[A-Za-z_$][\w$]*$/.test(k) ? k : "'" + k + "'") + ': ' + obj[k]; }).join(',\n') + '\n}';
  }
  var TW_MAP = { 'display:flex': 'flex', 'display:inline-flex': 'inline-flex', 'display:block': 'block', 'display:inline-block': 'inline-block', 'display:inline': 'inline', 'display:none': 'hidden', 'display:grid': 'grid', 'display:contents': 'contents', 'position:relative': 'relative', 'position:absolute': 'absolute', 'position:fixed': 'fixed', 'position:sticky': 'sticky', 'position:static': 'static', 'flex-direction:column': 'flex-col', 'flex-direction:row': 'flex-row', 'flex-direction:column-reverse': 'flex-col-reverse', 'flex-wrap:wrap': 'flex-wrap', 'flex-wrap:nowrap': 'flex-nowrap', 'flex:1': 'flex-1', 'flex:1 1 0%': 'flex-1', 'align-items:center': 'items-center', 'align-items:flex-start': 'items-start', 'align-items:flex-end': 'items-end', 'align-items:stretch': 'items-stretch', 'justify-content:center': 'justify-center', 'justify-content:flex-start': 'justify-start', 'justify-content:flex-end': 'justify-end', 'justify-content:space-between': 'justify-between', 'justify-content:space-around': 'justify-around', 'text-align:center': 'text-center', 'text-align:left': 'text-left', 'text-align:right': 'text-right', 'text-align:justify': 'text-justify', 'font-weight:bold': 'font-bold', 'font-weight:700': 'font-bold', 'font-weight:600': 'font-semibold', 'font-weight:500': 'font-medium', 'font-weight:400': 'font-normal', 'font-weight:300': 'font-light', 'font-style:italic': 'italic', 'text-decoration:underline': 'underline', 'text-decoration:line-through': 'line-through', 'text-transform:uppercase': 'uppercase', 'text-transform:lowercase': 'lowercase', 'text-transform:capitalize': 'capitalize', 'overflow:hidden': 'overflow-hidden', 'overflow:auto': 'overflow-auto', 'overflow:scroll': 'overflow-scroll', 'overflow-x:hidden': 'overflow-x-hidden', 'cursor:pointer': 'cursor-pointer', 'cursor:not-allowed': 'cursor-not-allowed', 'white-space:nowrap': 'whitespace-nowrap', 'visibility:hidden': 'invisible', 'list-style:none': 'list-none', 'box-sizing:border-box': 'box-border', 'box-sizing:content-box': 'box-content', 'object-fit:cover': 'object-cover', 'object-fit:contain': 'object-contain', 'border-collapse:collapse': 'border-collapse' };
  function b9Px(n) { return n % 4 === 0 ? String(n / 4) : '[' + n + 'px]'; }
  function b9Css2Tw(t) {
    var s = String(t).replace(/\/\*[\s\S]*?\*\//g, '');
    var body = s.replace(/^[^{]*\{/, '').replace(/\}\s*$/, '');
    var out = [], miss = [];
    body.split(';').map(function (d) { return d.trim(); }).filter(Boolean).forEach(function (d) {
      var i = d.indexOf(':'); if (i < 0) return;
      var k = d.slice(0, i).trim().toLowerCase(), v = d.slice(i + 1).trim().replace(/\s+/g, ' ');
      if (!k) return;
      var cls = null;
      if (TW_MAP[k + ':' + v]) cls = TW_MAP[k + ':' + v];
      else {
        var m = /^(-?\d+(?:\.\d+)?)px$/.exec(v), n = m ? parseFloat(m[1]) : 0;
        if (m) {
          if (k === 'width') cls = 'w' + (v === '100%' ? '' : '-' + b9Px(n));
          else if (k === 'height') cls = 'h-' + b9Px(n);
          else if (k === 'padding') cls = 'p-' + b9Px(n);
          else if (k === 'margin') cls = 'm-' + b9Px(n);
          else if (k === 'margin-top') cls = 'mt-' + b9Px(n);
          else if (k === 'margin-bottom') cls = 'mb-' + b9Px(n);
          else if (k === 'padding-top') cls = 'pt-' + b9Px(n);
          else if (k === 'padding-bottom') cls = 'pb-' + b9Px(n);
          else if (k === 'gap') cls = 'gap-' + (n % 4 === 0 ? String(n / 4) : '[' + n + 'px]');
          else if (k === 'border-radius') cls = 'rounded' + (n >= 9999 ? '-full' : '-' + b9Px(n));
          else if (k === 'top' || k === 'right' || k === 'bottom' || k === 'left') cls = k.charAt(0) + '-' + (n % 4 === 0 ? String(n / 4) : '[' + n + 'px]');
          else if (k === 'font-size') cls = 'text-[' + n + 'px]';
          else if (k === 'line-height') cls = 'leading-[' + n + 'px]';
          else if (k === 'z-index') cls = 'z-[' + String(Math.round(n)) + ']';
          else if (k === 'letter-spacing') cls = 'tracking-[' + n + 'px]';
        } else if (/rem$/.test(v)) {
          var r = parseFloat(v);
          if (k === 'padding') cls = 'p-' + r * 4; else if (k === 'margin') cls = 'm-' + r * 4;
          else if (k === 'font-size') cls = 'text-[' + v + ']';
        } else if (k === 'color') cls = 'text-[' + v + ']';
        else if (k === 'background-color' || k === 'background') cls = 'bg-[' + v + ']';
        else if (k === 'opacity') cls = 'opacity-' + Math.round(parseFloat(v) * 100);
        else if (k === 'width' && v === '100%') cls = 'w-full';
        else if (k === 'height' && v === '100%') cls = 'h-full';
      }
      if (cls) out.push(cls); else miss.push(k + ': ' + v);
    });
    return out.join(' ') + (miss.length ? '\n\n/* Not mapped (Tailwind has no direct utility):\n' + miss.map(function (x) { return '   ' + x + ';'; }).join('\n') + '\n   → use arbitrary values, e.g. [property:value]\n*/' : '');
  }
  function b9Tw2Css(t) {
    var names = String(t).split(/[\s.]+/).filter(Boolean);
    var decls = [], unknown = [], sel = '.' + names.join('.');
    var REV = {};
    Object.keys(TW_MAP).forEach(function (k) { var p = k.split(':'); if (!REV[p[1]]) REV[p[1]] = []; REV[p[1]].push(p[0]); });
    names.forEach(function (c) {
      if (REV[c]) { REV[c].forEach(function (p) { decls.push('  ' + p + ': ' + c + ';'); }); return; }
      var m;
      if ((m = /^([pm])([xytrbl]?)-(\d+)$/.exec(c))) {
        var base = m[1] === 'p' ? 'padding' : 'margin', u = parseFloat(m[3]) * 0.25;
        if (!m[2]) decls.push('  ' + base + ': ' + u + 'rem;');
        else if (m[2] === 'x') { decls.push('  ' + base + '-left: ' + u + 'rem;'); decls.push('  ' + base + '-right: ' + u + 'rem;'); }
        else if (m[2] === 'y') { decls.push('  ' + base + '-top: ' + u + 'rem;'); decls.push('  ' + base + '-bottom: ' + u + 'rem;'); }
        else decls.push('  ' + base + '-' + ({ t: 'top', r: 'right', b: 'bottom', l: 'left' }[m[2]]) + ': ' + u + 'rem;');
        return;
      }
      if ((m = /^([pm])([xytrbl]?)-\[(.+)\]$/.exec(c))) {
        var b2 = m[1] === 'p' ? 'padding' : 'margin', val = m[3];
        if (!m[2]) decls.push('  ' + b2 + ': ' + val + ';');
        else if (m[2] === 'x') { decls.push('  ' + b2 + '-left: ' + val + ';'); decls.push('  ' + b2 + '-right: ' + val + ';'); }
        else if (m[2] === 'y') { decls.push('  ' + b2 + '-top: ' + val + ';'); decls.push('  ' + b2 + '-bottom: ' + val + ';'); }
        else decls.push('  ' + b2 + '-' + ({ t: 'top', r: 'right', b: 'bottom', l: 'left' }[m[2]]) + ': ' + val + ';');
        return;
      }
      if ((m = /^([wh])-(\d+)$/.exec(c))) { decls.push('  ' + (m[1] === 'w' ? 'width' : 'height') + ': ' + parseFloat(m[2]) * 0.25 + 'rem;'); return; }
      if ((m = /^([wh])-\[(.+)\]$/.exec(c))) { decls.push('  ' + (m[1] === 'w' ? 'width' : 'height') + ': ' + m[2] + ';'); return; }
      if ((m = /^([wh])-(full|screen|auto|fit)$/.exec(c))) { var vv = { full: '100%', screen: '100vh', auto: 'auto', fit: 'fit-content' }[m[2]]; decls.push('  ' + (m[1] === 'w' ? 'width' : 'height') + ': ' + (m[1] === 'h' && m[2] === 'screen' ? '100vh' : m[1] === 'w' && m[2] === 'screen' ? '100vw' : vv) + ';'); return; }
      if ((m = /^gap-(\d+)$/.exec(c))) { decls.push('  gap: ' + parseFloat(m[1]) * 0.25 + 'rem;'); return; }
      if ((m = /^gap-\[(.+)\]$/.exec(c))) { decls.push('  gap: ' + m[1] + ';'); return; }
      if ((m = /^rounded(-(none|sm|md|lg|xl|2xl|3xl|full))?$/.exec(c))) { var rv = { 'none': '0', 'sm': '0.125rem', 'md': '0.375rem', 'lg': '0.5rem', 'xl': '0.75rem', '2xl': '1rem', '3xl': '1.5rem', 'full': '9999px' }[m[2] || 'md']; decls.push('  border-radius: ' + rv + ';'); return; }
      if ((m = /^rounded-\[(.+)\]$/.exec(c))) { decls.push('  border-radius: ' + m[1] + ';'); return; }
      if ((m = /^text-\[(.+)\]$/.exec(c))) { decls.push('  ' + (/^(#|rgb|hsl|var|transparent|currentcolor|[a-z]+$)/i.test(m[1]) && !/px|rem|em/.test(m[1]) ? 'color' : 'font-size') + ': ' + m[1] + ';'); return; }
      if ((m = /^bg-\[(.+)\]$/.exec(c))) { decls.push('  background-color: ' + m[1] + ';'); return; }
      if ((m = /^opacity-(\d+)$/.exec(c))) { decls.push('  opacity: ' + (parseFloat(m[1]) / 100) + ';'); return; }
      if ((m = /^z-\[(-?\d+)\]$/.exec(c))) { decls.push('  z-index: ' + m[1] + ';'); return; }
      if ((m = /^z-(\d+)$/.exec(c))) { decls.push('  z-index: ' + m[1] + ';'); return; }
      if ((m = /^font-(thin|extralight|light|normal|medium|semibold|bold|extrabold|black)$/.exec(c))) { var fw = { thin: '100', extralight: '200', light: '300', normal: '400', medium: '500', semibold: '600', bold: '700', extrabold: '800', black: '900' }[m[1]]; decls.push('  font-weight: ' + fw + ';'); return; }
      if ((m = /^(top|right|bottom|left)-(-?\d+)$/.exec(c))) { decls.push('  ' + m[1] + ': ' + parseFloat(m[2]) * 0.25 + 'rem;'); return; }
      if ((m = /^(top|right|bottom|left)-\[(.+)\]$/.exec(c))) { decls.push('  ' + m[1] + ': ' + m[2] + ';'); return; }
      if ((m = /^leading-\[(.+)\]$/.exec(c))) { decls.push('  line-height: ' + m[1] + ';'); return; }
      if ((m = /^tracking-\[(.+)\]$/.exec(c))) { decls.push('  letter-spacing: ' + m[1] + ';'); return; }
      unknown.push(c);
    });
    return sel + ' {\n' + decls.join('\n') + '\n}' + (unknown.length ? '\n\n/* Not recognised: ' + unknown.join(', ') + ' */' : '');
  }

  /* ---- YAML / TOML ---- */
  function b9YamlFmt(t, indent) {
    if (typeof jsyaml === 'undefined') return 'Error: YAML library not loaded';
    try { var o = jsyaml.load(t); return jsyaml.dump(o, { indent: indent, lineWidth: 120, noRefs: true, sortKeys: false }); } catch (e) { return 'Error: ' + e.message; }
  }
  function b9Toml2Yaml(t) {
    if (typeof Toml === 'undefined') return 'Error: TOML library not loaded';
    if (typeof jsyaml === 'undefined') return 'Error: YAML library not loaded';
    try { var o = Toml.parse(t); return jsyaml.dump(o, { indent: 2, lineWidth: 120, noRefs: true, sortKeys: false }); } catch (e) { return 'Error: ' + e.message; }
  }

  /* ---- 校验器 ---- */
  function b9XmlValidate(t) {
    var s = String(t).trim();
    if (!s) return 'Empty input';
    var doc = new DOMParser().parseFromString(s, 'application/xml');
    var err = doc.querySelector('parsererror');
    if (err) return '✗ Invalid XML\n\n' + err.textContent.replace(/\s+/g, ' ').trim();
    var all = doc.getElementsByTagName('*'), attrs = 0, i;
    for (i = 0; i < all.length; i++) attrs += all[i].attributes.length;
    return '✓ Valid XML\n\nRoot element: <' + doc.documentElement.nodeName + '>\nElements: ' + all.length + '\nAttributes: ' + attrs + '\nDeclared XML version: ' + (doc.xmlVersion || '1.0');
  }
  function b9HtmlValidate(t) {
    var s = String(t).trim();
    if (!s) return 'Empty input';
    var doc = new DOMParser().parseFromString(s, 'text/html');
    var issues = [], VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
    var all = doc.body ? doc.body.getElementsByTagName('*') : [];
    var dup = {}, dups = [], i;
    for (i = 0; i < all.length; i++) {
      var e = all[i], tag = e.tagName.toLowerCase();
      if (tag === 'img' && !e.hasAttribute('alt')) issues.push('<img> is missing the alt attribute');
      if (tag === 'a') { var h = e.getAttribute('href'); if (h === '' || h === '#') issues.push('<a> has an empty or "#" href'); }
      if (tag === 'input' && !e.hasAttribute('type')) issues.push('<input> has no type attribute');
      if (VOID.indexOf(tag) >= 0 && e.innerHTML.trim()) issues.push('<' + tag + '> is a void element and must not contain content');
      var idv = e.getAttribute('id');
      if (idv) { if (dup[idv]) dups.push(idv); dup[idv] = 1; }
    }
    dups.forEach(function (x) { issues.push('Duplicate id: "' + x + '"'); });
    if (/<html/i.test(s) && !doc.querySelector('title')) issues.push('Document is missing a <title> element');
    if (/<html/i.test(s) && !/<meta[^>]+charset/i.test(s)) issues.push('Missing <meta charset="utf-8">');
    if (/<html/i.test(s) && !/<meta[^>]+name=["']viewport/i.test(s)) issues.push('Missing viewport meta tag');
    if (/<table/i.test(s) && !/<(th|caption)/i.test(s)) issues.push('<table> has no header cells (<th>) or caption');
    return 'Elements parsed: ' + all.length + '\n' + (issues.length ? '⚠ ' + issues.length + ' issue(s) found:\n- ' + issues.join('\n- ') : '✓ No obvious issues found');
  }
  function b9CssValidate(t) {
    var s = String(t).replace(/\/\*[\s\S]*?\*\//g, '');
    if (!s.trim()) return 'Empty input';
    var issues = [], depth = 0, i;
    for (i = 0; i < s.length; i++) {
      if (s[i] === '{') depth++;
      else if (s[i] === '}') { depth--; if (depth < 0) { issues.push('Unexpected "}" at position ' + i); depth = 0; } }
    }
    if (depth > 0) issues.push(depth + ' unclosed "{" brace(s)');
    var KNOWN = ['align-items', 'align-self', 'animation', 'aspect-ratio', 'backdrop-filter', 'background', 'background-color', 'background-image', 'background-position', 'background-repeat', 'background-size', 'border', 'border-collapse', 'border-color', 'border-radius', 'border-style', 'border-width', 'bottom', 'box-shadow', 'box-sizing', 'clear', 'color', 'column-gap', 'content', 'cursor', 'display', 'filter', 'flex', 'flex-direction', 'flex-wrap', 'float', 'font-family', 'font-size', 'font-style', 'font-weight', 'gap', 'grid-gap', 'grid-template-columns', 'grid-template-rows', 'height', 'inset', 'justify-content', 'justify-items', 'left', 'letter-spacing', 'line-height', 'list-style', 'margin', 'margin-bottom', 'margin-left', 'margin-right', 'margin-top', 'max-height', 'max-width', 'min-height', 'min-width', 'object-fit', 'opacity', 'order', 'outline', 'overflow', 'overflow-x', 'overflow-y', 'padding', 'padding-bottom', 'padding-left', 'padding-right', 'padding-top', 'pointer-events', 'position', 'right', 'row-gap', 'scroll-behavior', 'text-align', 'text-decoration', 'text-overflow', 'text-transform', 'top', 'transform', 'transition', 'user-select', 'vertical-align', 'visibility', 'white-space', 'width', 'word-break', 'z-index'];
    var re = /([a-zA-Z-]+)\s*:\s*([^;{}]*)/g, m, props = 0, seen = {};
    while ((m = re.exec(s))) {
      var p = m[1].trim().toLowerCase();
      if (p.indexOf('{') >= 0) continue;
      props++;
      if (!seen[p]) seen[p] = 0; seen[p]++;
      if (KNOWN.indexOf(p) < 0 && p.indexOf('--') !== 0 && !/^-(moz|webkit|ms|o)-/.test(p)) issues.push('Uncommon or misspelled property: "' + p + '"');
      if (m[2].trim() === '') issues.push('Empty value for "' + p + '"');
      if (/[;{]\s*[a-zA-Z-]+\s*:\s*[^;{}]*:\s*[a-zA-Z-]+\s*:/.test(s)) { /* noop */ }
    }
    Object.keys(seen).forEach(function (k) { if (seen[k] > 3) issues.push('Property "' + k + '" declared ' + seen[k] + ' times (possible duplication)'); });
    return 'Declarations scanned: ' + props + '\n' + (issues.length ? '⚠ ' + issues.length + ' issue(s):\n- ' + issues.join('\n- ') : '✓ No issues found');
  }

  /* ====== 第八批新增：编码/密码/哈希 ====== */
  /* ================================================================
     chunk_crypto_fn  --  b9 crypto / encoding primitives
     pure functions, no DOM access, ES5 style (Promise/async allowed)
     ================================================================ */

  /* ---------------- shared helpers ---------------- */
  function b9CryptoHex(u8) {
    var s = '', i, h = '0123456789abcdef';
    for (i = 0; i < u8.length; i++) {
      s += h.charAt((u8[i] >> 4) & 15) + h.charAt(u8[i] & 15);
    }
    return s;
  }

  function b9CryptoUnhex(hex) {
    var s = String(hex === null || hex === undefined ? '' : hex).replace(/[^0-9a-fA-F]/g, '');
    if (s.length % 2) s = s.slice(0, s.length - 1);
    var u8 = new Uint8Array(s.length / 2), i;
    for (i = 0; i < u8.length; i++) u8[i] = parseInt(s.substr(i * 2, 2), 16);
    return u8;
  }

  function b9KeyBytes(s) { var k = String(s === null || s === undefined ? '' : s).replace(/\s+/g, ''); if (/^[0-9a-fA-F]{16}$/.test(k) || /^[0-9a-fA-F]{32}$/.test(k) || /^[0-9a-fA-F]{48}$/.test(k)) return b9CryptoUnhex(k); return b9CryptoTxt(k); }
  function b9CryptoTxt(s) {
    if (typeof TextEncoder !== 'undefined') return new TextEncoder().encode(String(s === null || s === undefined ? '' : s));
    var str0 = String(s === null || s === undefined ? '' : s), arr = [], i, c;
    for (i = 0; i < str0.length; i++) {
      c = str0.charCodeAt(i);
      if (c < 0x80) arr.push(c);
      else if (c < 0x800) arr.push(0xc0 | (c >> 6), 0x80 | (c & 63));
      else arr.push(0xe0 | (c >> 12), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
    }
    return new Uint8Array(arr);
  }

  function b9CryptoStr(u8) {
    if (typeof TextDecoder !== 'undefined') {
      try { return new TextDecoder().decode(u8); } catch (e) { /* fall through */ }
    }
    var out = '', i = 0, a, b, c;
    while (i < u8.length) {
      a = u8[i++];
      if (a < 0x80) out += String.fromCharCode(a);
      else if (a < 0xe0) { b = u8[i++]; out += String.fromCharCode(((a & 31) << 6) | (b & 63)); }
      else { b = u8[i++]; c = u8[i++]; out += String.fromCharCode(((a & 15) << 12) | ((b & 63) << 6) | (c & 63)); }
    }
    return out;
  }

  /* ================================================================
     1. MessagePack
     ================================================================ */
  function b9MpW16(out, v) { out.push((v >> 8) & 255, v & 255); }
  function b9MpW32(out, v) { out.push((v >>> 24) & 255, (v >>> 16) & 255, (v >>> 8) & 255, v & 255); }
  function b9MpW64(out, v) {
    var hi = Math.floor(v / 4294967296), lo = v - hi * 4294967296;
    b9MpW32(out, hi); b9MpW32(out, lo);
  }
  function b9MpF64(out, v) {
    var dv = new DataView(new ArrayBuffer(8)), i;
    dv.setFloat64(0, v);
    for (i = 0; i < 8; i++) out.push(dv.getUint8(i));
  }

  function b9MpEnc(v, out) {
    var i, n, L, su, keys;
    if (v === null || v === undefined) { out.push(0xc0); return; }
    var ty = typeof v;
    if (ty === 'boolean') { out.push(v ? 0xc3 : 0xc2); return; }
    if (ty === 'number') {
      if (!isFinite(v) || v !== Math.floor(v)) { out.push(0xcb); b9MpF64(out, v); return; }
      if (v >= 0) {
        if (v < 128) out.push(v);
        else if (v < 256) out.push(0xcc, v);
        else if (v < 65536) { out.push(0xcd); b9MpW16(out, v); }
        else if (v < 4294967296) { out.push(0xce); b9MpW32(out, v); }
        else { out.push(0xcf); b9MpW64(out, v); }
      } else {
        if (v >= -32) out.push(0xe0 | (v + 32));
        else if (v >= -128) out.push(0xd0, v & 255);
        else if (v >= -32768) { out.push(0xd1); b9MpW16(out, v); }
        else if (v >= -2147483648) { out.push(0xd2); b9MpW32(out, v); }
        else { out.push(0xd3); b9MpW64(out, v); }
      }
      return;
    }
    if (ty === 'string') {
      su = b9CryptoTxt(v); L = su.length;
      if (L < 32) out.push(0xa0 | L);
      else if (L < 256) out.push(0xd9, L);
      else if (L < 65536) { out.push(0xda); b9MpW16(out, L); }
      else { out.push(0xdb); b9MpW32(out, L); }
      for (i = 0; i < L; i++) out.push(su[i]);
      return;
    }
    if (Object.prototype.toString.call(v) === '[object Array]') {
      n = v.length;
      if (n < 16) out.push(0x90 | n);
      else if (n < 65536) { out.push(0xdc); b9MpW16(out, n); }
      else { out.push(0xdd); b9MpW32(out, n); }
      for (i = 0; i < n; i++) b9MpEnc(v[i], out);
      return;
    }
    if (ty === 'object') {
      keys = [];
      for (var k in v) { if (Object.prototype.hasOwnProperty.call(v, k)) keys.push(k); }
      n = keys.length;
      if (n < 16) out.push(0x80 | n);
      else if (n < 65536) { out.push(0xde); b9MpW16(out, n); }
      else { out.push(0xdf); b9MpW32(out, n); }
      for (i = 0; i < n; i++) { b9MpEnc(keys[i], out); b9MpEnc(v[keys[i]], out); }
    }
  }

  function b9MpR16(u8, p) { var v = (u8[p.i] << 8) | u8[p.i + 1]; p.i += 2; return v; }
  function b9MpR32(u8, p) { var v = 0, i; for (i = 0; i < 4; i++) v = v * 256 + u8[p.i++]; return v; }
  function b9MpR64(u8, p) {
    var hi = 0, lo = 0, i;
    for (i = 0; i < 4; i++) hi = hi * 256 + u8[p.i++];
    for (i = 0; i < 4; i++) lo = lo * 256 + u8[p.i++];
    return hi * 4294967296 + lo;
  }

  function b9MpDec(u8, p) {
    if (p.i >= u8.length) throw new Error('truncated');
    var b = u8[p.i++], i, n, L, res, key, val, out, dv;
    if (b <= 0x7f) return b;
    if (b >= 0xe0) return b - 0x100;
    if (b >= 0xa0 && b <= 0xbf) {
      L = b & 31;
      if (p.i + L > u8.length) throw new Error('truncated str');
      res = b9CryptoStr(u8.subarray(p.i, p.i + L));
      p.i += L;
      return res;
    }
    switch (b) {
      case 0xc0: return null;
      case 0xc2: return false;
      case 0xc3: return true;
      case 0xc4: L = u8[p.i++]; break;
      case 0xc5: L = b9MpR16(u8, p); break;
      case 0xc6: L = b9MpR32(u8, p); break;
      case 0xca: dv = new DataView(new ArrayBuffer(4)); for (i = 0; i < 4; i++) dv.setUint8(i, u8[p.i++]); return dv.getFloat32(0);
      case 0xcb: dv = new DataView(new ArrayBuffer(8)); for (i = 0; i < 8; i++) dv.setUint8(i, u8[p.i++]); return dv.getFloat64(0);
      case 0xcc: return u8[p.i++];
      case 0xcd: return b9MpR16(u8, p);
      case 0xce: return b9MpR32(u8, p);
      case 0xcf: return b9MpR64(u8, p);
      case 0xd0: return (u8[p.i++] << 24) >> 24;
      case 0xd1: return (b9MpR16(u8, p) << 16) >> 16;
      case 0xd2: return b9MpR32(u8, p) | 0;
      case 0xd3: n = b9MpR64(u8, p); return n >= 9223372036854775808 ? n - 18446744073709551616 : n;
      case 0xd9: L = u8[p.i++]; break;
      case 0xda: L = b9MpR16(u8, p); break;
      case 0xdb: L = b9MpR32(u8, p); break;
      case 0xdc: n = b9MpR16(u8, p); break;
      case 0xdd: n = b9MpR32(u8, p); break;
      case 0xde: n = b9MpR16(u8, p); break;
      case 0xdf: n = b9MpR32(u8, p); break;
      default:
        if (b >= 0x90 && b <= 0x9f) { n = b & 15; break; }
        if (b >= 0x80 && b <= 0x8f) { n = b & 15; break; }
        throw new Error('unsupported msgpack byte 0x' + b.toString(16));
    }
    if (b === 0xc4 || b === 0xc5 || b === 0xc6) {
      p.i += L; return '0x' + b9CryptoHex(u8.subarray(p.i - L, p.i));
    }
    if (b === 0xd9 || b === 0xda || b === 0xdb) {
      if (p.i + L > u8.length) throw new Error('truncated str');
      res = b9CryptoStr(u8.subarray(p.i, p.i + L)); p.i += L; return res;
    }
    if (b === 0xdc || b === 0xdd || (b >= 0x90 && b <= 0x9f)) {
      out = [];
      for (i = 0; i < n; i++) out.push(b9MpDec(u8, p));
      return out;
    }
    res = {};
    for (i = 0; i < n; i++) {
      key = b9MpDec(u8, p);
      val = b9MpDec(u8, p);
      res[String(key)] = val;
    }
    return res;
  }

  function b9MsgPack(jsonText, dec) {
    try {
      if (dec) {
        var u8 = b9CryptoUnhex(jsonText);
        if (!u8.length) return '';
        var p = { i: 0 };
        var v = b9MpDec(u8, p);
        return JSON.stringify(v, null, 2);
      }
      var src = String(jsonText === null || jsonText === undefined ? '' : jsonText).replace(/^\uFEFF/, '').trim();
      var obj = JSON.parse(src === '' ? 'null' : src);
      var out = [];
      b9MpEnc(obj, out);
      return b9CryptoHex(new Uint8Array(out));
    } catch (e) {
      return 'Error: ' + (e && e.message ? e.message : String(e));
    }
  }

  /* ================================================================
     2. CBOR (RFC 7049)
     ================================================================ */
  function b9CborHead(mt, v, out) {
    var i, hi, lo;
    if (v < 0) v = 0;
    if (v < 24) { out.push((mt << 5) | v); return; }
    if (v < 256) { out.push((mt << 5) | 24, v); return; }
    if (v < 65536) { out.push((mt << 5) | 25, (v >> 8) & 255, v & 255); return; }
    if (v < 4294967296) {
      out.push((mt << 5) | 26);
      for (i = 3; i >= 0; i--) out.push((v >>> (8 * i)) & 255);
      return;
    }
    hi = Math.floor(v / 4294967296); lo = v - hi * 4294967296;
    out.push((mt << 5) | 27);
    for (i = 3; i >= 0; i--) out.push((hi >>> (8 * i)) & 255);
    for (i = 3; i >= 0; i--) out.push((lo >>> (8 * i)) & 255);
  }

  function b9CborF64(out, v) {
    var dv = new DataView(new ArrayBuffer(8)), i;
    dv.setFloat64(0, v);
    out.push(0xfb);
    for (i = 0; i < 8; i++) out.push(dv.getUint8(i));
  }

  function b9CborEnc(v, out) {
    var i, n, su, keys, k;
    if (v === null || v === undefined) { out.push(0xf6); return; }
    var ty = typeof v;
    if (ty === 'boolean') { out.push(v ? 0xf5 : 0xf4); return; }
    if (ty === 'number') {
      if (!isFinite(v)) { b9CborF64(out, v); return; }
      if (v === Math.floor(v) && Math.abs(v) < 9007199254740992) {
        if (v >= 0) b9CborHead(0, v, out);
        else b9CborHead(1, -1 - v, out);
        return;
      }
      b9CborF64(out, v);
      return;
    }
    if (ty === 'string') {
      su = b9CryptoTxt(v);
      b9CborHead(3, su.length, out);
      for (i = 0; i < su.length; i++) out.push(su[i]);
      return;
    }
    if (Object.prototype.toString.call(v) === '[object Array]') {
      n = v.length;
      b9CborHead(4, n, out);
      for (i = 0; i < n; i++) b9CborEnc(v[i], out);
      return;
    }
    if (ty === 'object') {
      keys = [];
      for (k in v) { if (Object.prototype.hasOwnProperty.call(v, k)) keys.push(k); }
      b9CborHead(5, keys.length, out);
      for (i = 0; i < keys.length; i++) { b9CborEnc(keys[i], out); b9CborEnc(v[keys[i]], out); }
    }
  }

  function b9CborArg(u8, p, ai) {
    var val = 0, i;
    if (ai < 24) return ai;
    if (ai === 24) return u8[p.i++];
    if (ai === 25) { val = (u8[p.i] << 8) | u8[p.i + 1]; p.i += 2; return val; }
    if (ai === 26) { for (i = 0; i < 4; i++) val = val * 256 + u8[p.i++]; return val; }
    if (ai === 27) { for (i = 0; i < 8; i++) val = val * 256 + u8[p.i++]; return val; }
    throw new Error('bad cbor additional info ' + ai);
  }

  function b9CborHead2(u8, p) {
    var ib = u8[p.i++];
    return { mt: ib >> 5, ai: ib & 31 };
  }

  function b9CborF16(u8, p) {
    var hb = (u8[p.i] << 8) | u8[p.i + 1];
    p.i += 2;
    var sg = (hb & 0x8000) ? -1 : 1, ex = (hb >> 10) & 31, mn = hb & 1023;
    if (ex === 0) return sg * mn * 5.960464477539063e-8;
    if (ex === 31) return mn === 0 ? sg * Infinity : NaN;
    return sg * Math.pow(2, ex - 15) * (1 + mn / 1024);
  }

  function b9CborDec(u8, p) {
    var h, i, n, res, key, val, out, dv, chunks, e, indef;
    if (p.i >= u8.length) throw new Error('truncated');
    if (u8[p.i] === 0xff) { p.i++; return null; }
    h = b9CborHead2(u8, p);
    if (h.mt === 0) return b9CborArg(u8, p, h.ai);
    if (h.mt === 1) return -1 - b9CborArg(u8, p, h.ai);
    if (h.mt === 7) {
      if (h.ai === 20) return false;
      if (h.ai === 21) return true;
      if (h.ai === 22) return null;
      if (h.ai === 23) return null;
      if (h.ai === 25) return b9CborF16(u8, p);
      if (h.ai === 26) {
        dv = new DataView(new ArrayBuffer(4));
        for (i = 0; i < 4; i++) dv.setUint8(i, u8[p.i++]);
        return dv.getFloat32(0);
      }
      if (h.ai === 27) {
        dv = new DataView(new ArrayBuffer(8));
        for (i = 0; i < 8; i++) dv.setUint8(i, u8[p.i++]);
        return dv.getFloat64(0);
      }
      if (h.ai === 31) { p.i++; return null; }
      e = b9CborArg(u8, p, h.ai);
      return 'simple(' + e + ')';
    }
    indef = (h.ai === 31);
    n = indef ? -1 : b9CborArg(u8, p, h.ai);
    if (h.mt === 2) {
      if (indef) {
        chunks = [];
        while (p.i < u8.length && u8[p.i] !== 0xff) chunks.push(String(b9CborDec(u8, p)).replace(/^0x/, ''));
        p.i++;
        return '0x' + chunks.join('');
      }
      if (p.i + n > u8.length) throw new Error('truncated bytes');
      res = '0x' + b9CryptoHex(u8.subarray(p.i, p.i + n));
      p.i += n;
      return res;
    }
    if (h.mt === 3) {
      if (indef) {
        out = '';
        while (p.i < u8.length && u8[p.i] !== 0xff) out += String(b9CborDec(u8, p));
        p.i++;
        return out;
      }
      if (p.i + n > u8.length) throw new Error('truncated text');
      res = b9CryptoStr(u8.subarray(p.i, p.i + n));
      p.i += n;
      return res;
    }
    if (h.mt === 4) {
      out = [];
      if (indef) {
        while (p.i < u8.length && u8[p.i] !== 0xff) out.push(b9CborDec(u8, p));
        p.i++;
        return out;
      }
      for (i = 0; i < n; i++) out.push(b9CborDec(u8, p));
      return out;
    }
    if (h.mt === 5) {
      res = {};
      if (indef) {
        while (p.i < u8.length && u8[p.i] !== 0xff) {
          key = b9CborDec(u8, p); val = b9CborDec(u8, p);
          res[String(key)] = val;
        }
        p.i++;
        return res;
      }
      for (i = 0; i < n; i++) {
        key = b9CborDec(u8, p); val = b9CborDec(u8, p);
        res[String(key)] = val;
      }
      return res;
    }
    /* major type 6: tag -> decode the tagged value */
    b9CborArg(u8, p, h.ai);
    return b9CborDec(u8, p);
  }

  function b9Cbor(jsonText, dec) {
    try {
      if (dec) {
        var u8 = b9CryptoUnhex(jsonText);
        if (!u8.length) return '';
        var p = { i: 0 };
        return JSON.stringify(b9CborDec(u8, p), null, 2);
      }
      var src = String(jsonText === null || jsonText === undefined ? '' : jsonText).replace(/^\uFEFF/, '').trim();
      var obj = JSON.parse(src === '' ? 'null' : src);
      var out = [];
      b9CborEnc(obj, out);
      return b9CryptoHex(new Uint8Array(out));
    } catch (e2) {
      return 'Error: ' + (e2 && e2.message ? e2.message : String(e2));
    }
  }

  /* ================================================================
     3. ASN.1 / DER parser
     ================================================================ */
  var B9_ASN1_TAGS = {
    1: 'BOOLEAN', 2: 'INTEGER', 3: 'BIT STRING', 4: 'OCTET STRING', 5: 'NULL',
    6: 'OBJECT IDENTIFIER', 12: 'UTF8String', 16: 'SEQUENCE', 17: 'SET',
    19: 'PrintableString', 22: 'IA5String', 23: 'UTCTime', 24: 'GeneralizedTime'
  };
  var B9_ASN1_CLASSES = ['UNIVERSAL', 'APPLICATION', 'CONTEXT', 'PRIVATE'];

  function b9Asn1Oid(u8) {
    if (!u8.length) return '';
    var subs = [], i, f = u8[0];
    if (f < 40) { subs.push(0, f); }
    else if (f < 80) { subs.push(1, f - 40); }
    else { subs.push(2, f - 80); }
    var val = 0;
    for (i = 1; i < u8.length; i++) {
      var b = u8[i];
      val = val * 128 + (b & 0x7f);
      if (!(b & 0x80)) { subs.push(val); val = 0; }
    }
    return subs.join('.');
  }

  function b9Asn1IntDec(u8) {
    var bytes = [], i;
    for (i = 0; i < u8.length; i++) bytes.push(u8[i]);
    var neg = bytes.length > 0 && (bytes[0] & 0x80) !== 0;
    if (neg) {
      var carry = 1, v;
      for (i = bytes.length - 1; i >= 0; i--) {
        v = (255 - bytes[i]) + carry;
        bytes[i] = v & 255;
        carry = (v >> 8) & 1;
      }
    }
    var digits = [], allz, r, cur;
    for (;;) {
      allz = true; r = 0;
      for (i = 0; i < bytes.length; i++) {
        cur = r * 256 + bytes[i];
        bytes[i] = Math.floor(cur / 10);
        r = cur - bytes[i] * 10;
        if (bytes[i] !== 0) allz = false;
      }
      digits.unshift(r);
      if (allz) break;
    }
    return (neg ? '-' : '') + digits.join('');
  }

  function b9Asn1Pad(n) {
    var s = String(n);
    while (s.length < 4) s = '0' + s;
    return s;
  }

  function b9Asn1TryChildren(u8, start, end) {
    var tmp = [], p = { i: start }, cnt = 0;
    while (p.i < end) {
      b9Asn1Node(u8, p, 0, tmp, end);
      cnt++;
      if (cnt > 512) throw new Error('too deep');
    }
    return cnt;
  }

  function b9Asn1Node(u8, p, depth, lines, end) {
    var start = p.i;
    if (start >= end) throw new Error('offset out of range');
    var tb = u8[p.i++];
    var cls = (tb >> 6) & 3;
    var constructed = (tb & 0x20) !== 0;
    var tagno = tb & 31, sh;
    if (tagno === 31) {
      tagno = 0;
      do { sh = u8[p.i++]; tagno = tagno * 128 + (sh & 0x7f); } while (sh & 0x80);
    }
    if (p.i >= end + 1) throw new Error('truncated tag');
    var lb = u8[p.i++], len = 0, i, nb;
    if (lb & 0x80) {
      nb = lb & 0x7f;
      if (nb === 0) len = -1;
      else {
        if (nb > 4) throw new Error('length too large');
        for (i = 0; i < nb; i++) len = len * 256 + u8[p.i++];
      }
    } else len = lb;
    var hdrEnd = p.i;
    if (len < 0 || hdrEnd + len > end) len = end - hdrEnd;
    if (len < 0) len = 0;
    var val = u8.subarray(hdrEnd, hdrEnd + len);
    var name = cls === 0 ? (B9_ASN1_TAGS[tagno] || 'UNKNOWN(' + tagno + ')') : '[' + tagno + ']';
    var head = b9Asn1Pad(start) + '  ';
    var ind = '';
    for (i = 0; i < depth; i++) ind += '  ';
    var meta = '[' + B9_ASN1_CLASSES[cls] + '.' + (constructed ? 'CONSTRUCTED' : 'PRIMITIVE') + ']';
    var summary = '';
    p.i = hdrEnd + len;

    var recurse = false;
    if (cls === 0 && (tagno === 16 || tagno === 17)) recurse = true;
    if (cls !== 0 && constructed) recurse = true;
    if (cls === 0 && tagno === 4) {
      try {
        if (len > 0 && b9Asn1TryChildren(u8, hdrEnd, hdrEnd + len) > 0) recurse = true;
      } catch (e) { recurse = false; }
    }

    if (recurse && len > 0) {
      lines.push(ind + head + meta + '  ' + name + '  len=' + len);
      var cp = { i: hdrEnd }, guard = 0;
      while (cp.i < hdrEnd + len && guard < 512) {
        b9Asn1Node(u8, cp, depth + 1, lines, hdrEnd + len);
        guard++;
      }
      return;
    }

    if (cls === 0) {
      if (tagno === 1) summary = len > 0 && val[0] !== 0 ? 'TRUE' : 'FALSE';
      else if (tagno === 2) summary = b9Asn1IntDec(val) + ' (0x' + b9CryptoHex(val) + ')';
      else if (tagno === 3) summary = 'unused=' + (len > 0 ? val[0] : 0) + ' 0x' + b9CryptoHex(val.subarray(1)) + ' (' + len + ' bytes)';
      else if (tagno === 4) summary = '0x' + b9CryptoHex(val) + ' (' + len + ' bytes)';
      else if (tagno === 5) summary = 'NULL';
      else if (tagno === 6) summary = b9Asn1Oid(val);
      else if (tagno === 12 || tagno === 19 || tagno === 22 || tagno === 23 || tagno === 24) {
        summary = b9CryptoStr(val);
        if (summary.length > 60) summary = summary.slice(0, 60) + '...';
      } else {
        summary = '0x' + (b9CryptoHex(val).length > 64 ? b9CryptoHex(val).slice(0, 64) + '...' : b9CryptoHex(val)) + ' (' + len + ' bytes)';
      }
    } else {
      summary = '0x' + (b9CryptoHex(val).length > 64 ? b9CryptoHex(val).slice(0, 64) + '...' : b9CryptoHex(val)) + ' (' + len + ' bytes)';
    }
    lines.push(ind + head + meta + '  ' + name + '  len=' + len + '  : ' + summary);
  }

  function b9Asn1(hexText) {
    var hex = String(hexText === null || hexText === undefined ? '' : hexText).replace(/[^0-9a-fA-F]/g, '');
    if (hex.length % 2) hex = hex.slice(0, hex.length - 1);
    if (!hex.length) return 'Error: empty or invalid hex input';
    var u8 = new Uint8Array(hex.length / 2), i;
    for (i = 0; i < u8.length; i++) u8[i] = parseInt(hex.substr(i * 2, 2), 16);
    var lines = [];
    try {
      var p = { i: 0 };
      var guard = 0;
      while (p.i < u8.length && guard < 4096) {
        b9Asn1Node(u8, p, 0, lines, u8.length);
        guard++;
      }
    } catch (e) {
      lines.push('... parse stopped: ' + (e && e.message ? e.message : String(e)));
    }
    if (!lines.length) lines.push('(no ASN.1 structure found)');
    return lines.join('\n');
  }

  /* ================================================================
     4. Enigma (Enigma I)
     ================================================================ */
  var B9_ENIGMA_ROTORS = {
    'I': { w: 'EKMFLGDQVZNTOWYHXUSPAIBRCJ', n: 'Q' },
    'II': { w: 'AJDKSIRUXBLHWTMCQGZNPYFVOE', n: 'E' },
    'III': { w: 'BDFHJLCPRTXVZNYEIWGAKMUSQO', n: 'V' },
    'IV': { w: 'ESOVPZJAYQUIRHXLNFTGKDCMWB', n: 'J' },
    'V': { w: 'VZBRGITYUPSDNHLXAWMJQOFECK', n: 'Z' }
  };
  var B9_ENIGMA_REFL = {
    'B': 'YRUHQSLDPXNGOKMIEBFZCWVJAT',
    'C': 'FVPJIAOYEDRZXWGCTKUQSBNMHL'
  };

  function b9EnigmaCodes(wiring) {
    var f = new Array(26), r = new Array(26), i;
    for (i = 0; i < 26; i++) { f[i] = wiring.charCodeAt(i) - 65; r[f[i]] = i; }
    return { f: f, r: r };
  }

  function b9EnigmaMod(n) { return ((n % 26) + 26) % 26; }

  function b9Enigma(text, rotorNames, ringStr, posStr, plugStr, reflectorName) {
    try {
      var names = String(rotorNames || '').toUpperCase().replace(/[^A-Z0-9,;\s|]/g, '').split(/[,;\s|]+/);
      var clean = [], i;
      for (i = 0; i < names.length; i++) { if (names[i]) clean.push(names[i]); }
      while (clean.length < 3) clean.push(clean.length === 0 ? 'I' : (clean.length === 1 ? 'II' : 'III'));
      var rings = String(ringStr || '').toUpperCase().replace(/[^A-Z]/g, '');
      var poss = String(posStr || '').toUpperCase().replace(/[^A-Z]/g, '');
      while (rings.length < 3) rings += 'A';
      while (poss.length < 3) poss += 'A';
      var reflName = String(reflectorName || 'B').toUpperCase().replace(/[^BC]/g, '');
      if (!reflName) reflName = 'B';
      reflName = reflName.charAt(0);

      /* index 0 = leftmost (slowest), index 2 = rightmost (fastest) */
      var rotors = [], notch = [], pos = [], ring = [], c;
      for (i = 0; i < 3; i++) {
        var nm = clean[i];
        var def = B9_ENIGMA_ROTORS[nm];
        if (!def) return 'Error: unknown rotor "' + nm + '" (use I, II, III, IV, V)';
        rotors.push(b9EnigmaCodes(def.w));
        notch.push(def.n.charCodeAt(0) - 65);
        pos.push(b9EnigmaMod(poss.charCodeAt(i) - 65));
        ring.push(b9EnigmaMod(rings.charCodeAt(i) - 65));
      }
      var refl = b9EnigmaCodes(B9_ENIGMA_REFL[reflName]).f;

      /* plugboard */
      var plug = new Array(26);
      for (i = 0; i < 26; i++) plug[i] = i;
      var pairs = String(plugStr || '').toUpperCase().split(/[^A-Z]+/), s;
      for (i = 0; i < pairs.length; i++) {
        s = pairs[i];
        if (s.length === 2 && s.charAt(0) !== s.charAt(1)) {
          var a = s.charCodeAt(0) - 65, b = s.charCodeAt(1) - 65;
          plug[a] = b; plug[b] = a;
        }
      }

      var out = '', src = String(text === null || text === undefined ? '' : text);
      for (i = 0; i < src.length; i++) {
        c = src.charAt(i);
        if (c >= 'a' && c <= 'z') {
          out += b9EnigmaStep(String.fromCharCode(c.charCodeAt(0) - 32), rotors, notch, pos, ring, refl, plug).toLowerCase();
        } else if (c >= 'A' && c <= 'Z') {
          out += b9EnigmaStep(c, rotors, notch, pos, ring, refl, plug);
        } else {
          out += c;
        }
      }
      return out;
    } catch (e) {
      return 'Error: ' + (e && e.message ? e.message : String(e));
    }
  }

  function b9EnigmaStep(ch, rotors, notch, pos, ring, refl, plug) {
    /* stepping with double-step */
    if (pos[1] === notch[1]) {
      pos[1] = b9EnigmaMod(pos[1] + 1);
      pos[0] = b9EnigmaMod(pos[0] + 1);
    } else if (pos[2] === notch[2]) {
      pos[1] = b9EnigmaMod(pos[1] + 1);
    }
    pos[2] = b9EnigmaMod(pos[2] + 1);

    var c = plug[ch.charCodeAt(0) - 65], i, off;
    /* right -> left */
    for (i = 2; i >= 0; i--) {
      off = b9EnigmaMod(pos[i] - ring[i]);
      c = b9EnigmaMod(rotors[i].f[b9EnigmaMod(c + off)] - off);
    }
    c = refl[c];
    /* left -> right */
    for (i = 0; i <= 2; i++) {
      off = b9EnigmaMod(pos[i] - ring[i]);
      c = b9EnigmaMod(rotors[i].r[b9EnigmaMod(c + off)] - off);
    }
    c = plug[c];
    return String.fromCharCode(65 + c);
  }

  /* ================================================================
     5. DES / 3DES  (ECB + PKCS#7)
     ================================================================ */
  var B9_DES_IP = [
    58, 50, 42, 34, 26, 18, 10, 2, 60, 52, 44, 36, 28, 20, 12, 4,
    62, 54, 46, 38, 30, 22, 14, 6, 64, 56, 48, 40, 32, 24, 16, 8,
    57, 49, 41, 33, 25, 17, 9, 1, 59, 51, 43, 35, 27, 19, 11, 3,
    61, 53, 45, 37, 29, 21, 13, 5, 63, 55, 47, 39, 31, 23, 15, 7
  ];
  var B9_DES_FP = [
    40, 8, 48, 16, 56, 24, 64, 32, 39, 7, 47, 15, 55, 23, 63, 31,
    38, 6, 46, 14, 54, 22, 62, 30, 37, 5, 45, 13, 53, 21, 61, 29,
    36, 4, 44, 12, 52, 20, 60, 28, 35, 3, 43, 11, 51, 19, 59, 27,
    34, 2, 42, 10, 50, 18, 58, 26, 33, 1, 41, 9, 49, 17, 57, 25
  ];
  var B9_DES_E = [
    32, 1, 2, 3, 4, 5, 4, 5, 6, 7, 8, 9, 8, 9, 10, 11,
    12, 13, 12, 13, 14, 15, 16, 17, 16, 17, 18, 19, 20, 21, 20, 21,
    22, 23, 24, 25, 24, 25, 26, 27, 28, 29, 28, 29, 30, 31, 32, 1
  ];
  var B9_DES_P = [
    16, 7, 20, 21, 29, 12, 28, 17, 1, 15, 23, 26, 5, 18, 31, 10,
    2, 8, 24, 14, 32, 27, 3, 9, 19, 13, 30, 6, 22, 11, 4, 25
  ];
  var B9_DES_PC1 = [
    57, 49, 41, 33, 25, 17, 9, 1, 58, 50, 42, 34, 26, 18,
    10, 2, 59, 51, 43, 35, 27, 19, 11, 3, 60, 52, 44, 36,
    63, 55, 47, 39, 31, 23, 15, 7, 62, 54, 46, 38, 30, 22,
    14, 6, 61, 53, 45, 37, 29, 21, 13, 5, 28, 20, 12, 4
  ];
  var B9_DES_PC2 = [
    14, 17, 11, 24, 1, 5, 3, 28, 15, 6, 21, 10,
    23, 19, 12, 4, 26, 8, 16, 7, 27, 20, 13, 2,
    41, 52, 31, 37, 47, 55, 30, 40, 51, 45, 33, 48,
    44, 49, 39, 56, 34, 53, 46, 42, 50, 36, 29, 32
  ];
  var B9_DES_SHIFTS = [1, 1, 2, 2, 2, 2, 2, 2, 1, 2, 2, 2, 2, 2, 2, 1];
  var B9_DES_S = [
    [14, 4, 13, 1, 2, 15, 11, 8, 3, 10, 6, 12, 5, 9, 0, 7,
     0, 15, 7, 4, 14, 2, 13, 1, 10, 6, 12, 11, 9, 5, 3, 8,
     4, 1, 14, 8, 13, 6, 2, 11, 15, 12, 9, 7, 3, 10, 5, 0,
     15, 12, 8, 2, 4, 9, 1, 7, 5, 11, 3, 14, 10, 0, 6, 13],
    [15, 1, 8, 14, 6, 11, 3, 4, 9, 7, 2, 13, 12, 0, 5, 10,
     3, 13, 4, 7, 15, 2, 8, 14, 12, 0, 1, 10, 6, 9, 11, 5,
     0, 14, 7, 11, 10, 4, 13, 1, 5, 8, 12, 6, 9, 3, 2, 15,
     13, 8, 10, 1, 3, 15, 4, 2, 11, 6, 7, 12, 0, 5, 14, 9],
    [10, 0, 9, 14, 6, 3, 15, 5, 1, 13, 12, 7, 11, 4, 2, 8,
     13, 7, 0, 9, 3, 4, 6, 10, 2, 8, 5, 14, 12, 11, 15, 1,
     13, 6, 4, 9, 8, 15, 3, 0, 11, 1, 2, 12, 5, 10, 14, 7,
     1, 10, 13, 0, 6, 9, 8, 7, 4, 15, 14, 3, 11, 5, 2, 12],
    [7, 13, 14, 3, 0, 6, 9, 10, 1, 2, 8, 5, 11, 12, 4, 15,
     13, 8, 11, 5, 6, 15, 0, 3, 4, 7, 2, 12, 1, 10, 14, 9,
     10, 6, 9, 0, 12, 11, 7, 13, 15, 1, 3, 14, 5, 2, 8, 4,
     3, 15, 0, 6, 10, 1, 13, 8, 9, 4, 5, 11, 12, 7, 2, 14],
    [2, 12, 4, 1, 7, 10, 11, 6, 8, 5, 3, 15, 13, 0, 14, 9,
     14, 11, 2, 12, 4, 7, 13, 1, 5, 0, 15, 10, 3, 9, 8, 6,
     4, 2, 1, 11, 10, 13, 7, 8, 15, 9, 12, 5, 6, 3, 0, 14,
     11, 8, 12, 7, 1, 14, 2, 13, 6, 15, 0, 9, 10, 4, 5, 3],
    [12, 1, 10, 15, 9, 2, 6, 8, 0, 13, 3, 4, 14, 7, 5, 11,
     10, 15, 4, 2, 7, 12, 9, 5, 6, 1, 13, 14, 0, 11, 3, 8,
     9, 14, 15, 5, 2, 8, 12, 3, 7, 0, 4, 10, 1, 13, 11, 6,
     4, 3, 2, 12, 9, 5, 15, 10, 11, 14, 1, 7, 6, 0, 8, 13],
    [4, 11, 2, 14, 15, 0, 8, 13, 3, 12, 9, 7, 5, 10, 6, 1,
     13, 0, 11, 7, 4, 9, 1, 10, 14, 3, 5, 12, 2, 15, 8, 6,
     1, 4, 11, 13, 12, 3, 7, 14, 10, 15, 6, 8, 0, 5, 9, 2,
     6, 11, 13, 8, 1, 4, 10, 7, 9, 5, 0, 15, 14, 2, 3, 12],
    [13, 2, 8, 4, 6, 15, 11, 1, 10, 9, 3, 14, 5, 0, 12, 7,
     1, 15, 13, 8, 10, 3, 7, 4, 12, 5, 6, 11, 0, 14, 9, 2,
     7, 11, 4, 1, 9, 12, 14, 2, 0, 6, 10, 13, 15, 3, 5, 8,
     2, 1, 14, 7, 4, 10, 8, 13, 15, 12, 9, 0, 3, 5, 6, 11]
  ];

  function b9DesToBits(b) {
    var r = new Array(b.length * 8), i, j;
    for (i = 0; i < b.length; i++) for (j = 0; j < 8; j++) r[i * 8 + j] = (b[i] >> (7 - j)) & 1;
    return r;
  }
  function b9DesToBytes(bits) {
    var n = bits.length / 8, out = new Uint8Array(n), i, j, v;
    for (i = 0; i < n; i++) { v = 0; for (j = 0; j < 8; j++) v = (v << 1) | bits[i * 8 + j]; out[i] = v & 255; }
    return out;
  }
  function b9DesPerm(bits, tab) {
    var out = new Array(tab.length), i;
    for (i = 0; i < tab.length; i++) out[i] = bits[tab[i] - 1];
    return out;
  }
  function b9DesXor(a, b) {
    var r = new Array(a.length), i;
    for (i = 0; i < a.length; i++) r[i] = a[i] ^ b[i];
    return r;
  }
  function b9DesKeys(key8) {
    var k = b9DesPerm(b9DesToBits(key8), B9_DES_PC1), i, s;
    var c = k.slice(0, 28), d = k.slice(28), keys = [];
    for (i = 0; i < 16; i++) {
      s = B9_DES_SHIFTS[i];
      c = c.slice(s).concat(c.slice(0, s));
      d = d.slice(s).concat(d.slice(0, s));
      keys.push(b9DesPerm(c.concat(d), B9_DES_PC2));
    }
    return keys;
  }
  function b9DesF(r, k) {
    var e = b9DesPerm(r, B9_DES_E), x = b9DesXor(e, k), out = [], i, j, six, row, col, v;
    for (i = 0; i < 8; i++) {
      six = x.slice(i * 6, i * 6 + 6);
      row = (six[0] << 1) | six[5];
      col = (six[1] << 3) | (six[2] << 2) | (six[3] << 1) | six[4];
      v = B9_DES_S[i][row * 16 + col];
      for (j = 3; j >= 0; j--) out.push((v >> j) & 1);
    }
    return b9DesPerm(out, B9_DES_P);
  }
  function b9DesBlock(blk8, keys) {
    var b = b9DesPerm(b9DesToBits(blk8), B9_DES_IP), i, l = b.slice(0, 32), r = b.slice(32), nl, nr;
    for (i = 0; i < 16; i++) {
      nl = r;
      nr = b9DesXor(l, b9DesF(r, keys[i]));
      l = nl; r = nr;
    }
    return b9DesToBytes(b9DesPerm(r.concat(l), B9_DES_FP));
  }
  function b9DesKey24(kb) {
    var k = new Uint8Array(24), i, n = kb.length;
    for (i = 0; i < 24 && i < n; i++) k[i] = kb[i];
    if (n <= 8) { for (i = 0; i < 8; i++) { k[8 + i] = k[i]; k[16 + i] = k[i]; } }
    else if (n <= 16) { for (i = 0; i < 8; i++) k[16 + i] = k[i]; }
    return k;
  }
  /* raw byte API: data/key are Uint8Array */
  function b9DesRaw(data, key, dec, triple) {
    var i, k1, k2, k3, kb8, out, tmp, blk, kk;
    if (triple) {
      var k24 = b9DesKey24(key);
      k1 = b9DesKeys(k24.subarray(0, 8));
      k2 = b9DesKeys(k24.subarray(8, 16));
      k3 = b9DesKeys(k24.subarray(16, 24));
    } else {
      kb8 = new Uint8Array(8);
      for (i = 0; i < 8 && i < key.length; i++) kb8[i] = key[i];
      k1 = b9DesKeys(kb8);
    }
    if (data.length % 8 !== 0) throw new Error('data length must be a multiple of 8');
    out = new Uint8Array(data.length);
    for (i = 0; i < data.length; i += 8) {
      blk = data.subarray(i, i + 8);
      if (triple) {
        if (dec) {
          tmp = b9DesBlock(blk, k3.slice().reverse());
          tmp = b9DesBlock(tmp, k2);
          tmp = b9DesBlock(tmp, k1.slice().reverse());
        } else {
          tmp = b9DesBlock(blk, k1);
          tmp = b9DesBlock(tmp, k2.slice().reverse());
          tmp = b9DesBlock(tmp, k3);
        }
      } else {
        tmp = b9DesBlock(blk, dec ? k1.slice().reverse() : k1);
      }
      out.set(tmp, i);
    }
    return out;
  }
  function b9DesPkcs7Pad(u8) {
    var pad = 8 - (u8.length % 8), out = new Uint8Array(u8.length + pad), i;
    out.set(u8);
    for (i = u8.length; i < out.length; i++) out[i] = pad;
    return out;
  }
  function b9DesPkcs7Unpad(u8) {
    if (!u8.length || u8.length % 8 !== 0) throw new Error('invalid ciphertext length');
    var pad = u8[u8.length - 1], i;
    if (pad < 1 || pad > 8) throw new Error('bad PKCS#7 padding');
    for (i = u8.length - pad; i < u8.length; i++) { if (u8[i] !== pad) throw new Error('bad PKCS#7 padding'); }
    return u8.subarray(0, u8.length - pad);
  }
  function b9DesB64(u8) {
    var s = '', i;
    for (i = 0; i < u8.length; i++) s += String.fromCharCode(u8[i]);
    return btoa(s);
  }
  function b9DesUnb64(s) {
    s = String(s || '').replace(/[\s\r\n]/g, '');
    while (s.length % 4) s += '=';
    var bin = atob(s), u8 = new Uint8Array(bin.length), i;
    for (i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i) & 255;
    return u8;
  }
  function b9Des(text, key, dec, triple, encFmt) {
    try {
      var kb = b9KeyBytes(key);
      var fmt = String(encFmt || 'hex').toLowerCase();
      var data, res, i;
      if (dec) {
        data = fmt === 'base64' ? b9DesUnb64(text) : b9CryptoUnhex(text);
        if (!data.length) return '';
        res = b9DesRaw(data, kb, true, !!triple);
        res = b9DesPkcs7Unpad(res);
        return b9CryptoStr(res);
      }
      data = b9CryptoTxt(text);
      data = b9DesPkcs7Pad(data);
      res = b9DesRaw(data, kb, false, !!triple);
      return fmt === 'base64' ? b9DesB64(res) : b9CryptoHex(res);
    } catch (e) {
      return 'Error: ' + (e && e.message ? e.message : String(e));
    }
  }

  /* ================================================================
     6. Fernet
     ================================================================ */
  function b9FernetB64e(u8) {
    var s = '', i;
    for (i = 0; i < u8.length; i++) s += String.fromCharCode(u8[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function b9FernetB64d(str) {
    var s = String(str === null || str === undefined ? '' : str).replace(/[\s\r\n]/g, '').replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s), u8 = new Uint8Array(bin.length), i;
    for (i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i) & 255;
    return u8;
  }
  function b9FernetSubtle() {
    if (typeof crypto !== 'undefined' && crypto && crypto.subtle) return crypto.subtle;
    if (typeof self !== 'undefined' && self.crypto && self.crypto.subtle) return self.crypto.subtle;
    return null;
  }
  function b9FernetRnd(n) {
    var a = new Uint8Array(n);
    if (typeof crypto !== 'undefined' && crypto && crypto.getRandomValues) crypto.getRandomValues(a);
    return a;
  }

  async function b9Fernet(text, keyB64, dec) {
    var sb = b9FernetSubtle();
    if (!sb) return 'Error: WebCrypto (crypto.subtle) is not available';
    try {
      var key = b9FernetB64d(String(keyB64 || '').trim());
      if (!key || key.length !== 32) return 'Error: key must be 32 bytes (base64url)';
      var signKey = key.subarray(0, 16), encKey = key.subarray(16, 32);
      var hk = await sb.importKey('raw', signKey, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign', 'verify']);
      var ak = await sb.importKey('raw', encKey, { name: 'AES-CBC' }, false, ['encrypt', 'decrypt']);
      var i, raw, body, sig, ok, ts, iv, ct, pt, pad, diff;

      if (!dec) {
        iv = b9FernetRnd(16);
        ts = Math.floor(Date.now() / 1000);
        var head = new Uint8Array(1 + 8 + 16);
        head[0] = 0x80;
        var v = ts;
        for (i = 8; i >= 1; i--) { head[i] = v % 256; v = Math.floor(v / 256); }
        for (i = 0; i < 16; i++) head[9 + i] = iv[i];
        var ctBuf = await sb.encrypt({ name: 'AES-CBC', iv: iv }, ak, b9CryptoTxt(text));
        ct = new Uint8Array(ctBuf);
        body = new Uint8Array(head.length + ct.length);
        body.set(head); body.set(ct, head.length);
        var sg = await sb.sign('HMAC', hk, body);
        sig = new Uint8Array(sg);
        raw = new Uint8Array(body.length + sig.length);
        raw.set(body); raw.set(sig, body.length);
        return b9FernetB64e(raw);
      }

      raw = b9FernetB64d(String(text || '').trim());
      if (raw.length < 1 + 8 + 16 + 32) return 'Error: invalid token';
      if (raw[0] !== 0x80) return 'Error: invalid token';
      body = raw.subarray(0, raw.length - 32);
      sig = raw.subarray(raw.length - 32);
      ok = await sb.verify('HMAC', hk, sig, body);
      if (!ok) return 'Error: invalid token';
      ts = 0;
      for (i = 1; i <= 8; i++) ts = ts * 256 + raw[i];
      iv = raw.subarray(9, 25);
      ct = raw.subarray(25, raw.length - 32);
      if (ct.length === 0 || ct.length % 16 !== 0) return 'Error: invalid token';
      var ptBuf = await sb.decrypt({ name: 'AES-CBC', iv: iv }, ak, ct);
      pt = new Uint8Array(ptBuf);
      if (!pt.length) return 'Error: invalid token';
      /* WebCrypto strips PKCS#7 itself when it produces fewer bytes than
         the ciphertext; some engines do not, so unpad only when needed. */
      if (pt.length === ct.length) {
        pad = pt[pt.length - 1];
        if (pad < 1 || pad > 16 || pad > pt.length) return 'Error: invalid token';
        diff = 0;
        for (i = pt.length - pad; i < pt.length; i++) diff |= (pt[i] ^ pad);
        if (diff !== 0) return 'Error: invalid token';
        pt = pt.subarray(0, pt.length - pad);
      }
      return b9CryptoStr(pt);
    } catch (e) {
      return 'Error: invalid token';
    }
  }

  /* ================================================================
     7. Extended hash (MD5 pure JS + WebCrypto SHA family)
     ================================================================ */
  var B9_MD5_S = [
    7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22,
    5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20,
    4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23,
    6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21
  ];
  var B9_MD5_K = [
    0xd76aa478, 0xe8c7b756, 0x242070db, 0xc1bdceee, 0xf57c0faf, 0x4787c62a, 0xa8304613, 0xfd469501,
    0x698098d8, 0x8b44f7af, 0xffff5bb1, 0x895cd7be, 0x6b901122, 0xfd987193, 0xa679438e, 0x49b40821,
    0xf61e2562, 0xc040b340, 0x265e5a51, 0xe9b6c7aa, 0xd62f105d, 0x02441453, 0xd8a1e681, 0xe7d3fbc8,
    0x21e1cde6, 0xc33707d6, 0xf4d50d87, 0x455a14ed, 0xa9e3e905, 0xfcefa3f8, 0x676f02d9, 0x8d2a4c8a,
    0xfffa3942, 0x8771f681, 0x6d9d6122, 0xfde5380c, 0xa4beea44, 0x4bdecfa9, 0xf6bb4b60, 0xbebfbc70,
    0x289b7ec6, 0xeaa127fa, 0xd4ef3085, 0x04881d05, 0xd9d4d039, 0xe6db99e5, 0x1fa27cf8, 0xc4ac5665,
    0xf4292244, 0x432aff97, 0xab9423a7, 0xfc93a039, 0x655b59c3, 0x8f0ccc92, 0xffeff47d, 0x85845dd1,
    0x6fa87e4f, 0xfe2ce6e0, 0xa3014314, 0x4e0811a1, 0xf7537e82, 0xbd3af235, 0x2ad7d2bb, 0xeb86d391
  ];

  function b9Md5Hex(u8) {
    var len = u8.length, bitLen = len * 8, i;
    var total = (((len + 8) >> 6) + 1) << 6;
    var buf = new Uint8Array(total);
    buf.set(u8);
    buf[len] = 0x80;
    var lo = bitLen % 4294967296, hi = Math.floor(bitLen / 4294967296);
    for (i = 0; i < 4; i++) {
      buf[total - 8 + i] = (lo >>> (8 * i)) & 255;
      buf[total - 4 + i] = (hi >>> (8 * i)) & 255;
    }
    var a0 = 0x67452301, b0 = 0xefcdab89, c0 = 0x98badcfe, d0 = 0x10325476;
    var M = new Array(16), j, A, B, C, D, F, g, tmp;
    for (j = 0; j < total; j += 64) {
      for (i = 0; i < 16; i++) {
        M[i] = (buf[j + i * 4]) | (buf[j + i * 4 + 1] << 8) | (buf[j + i * 4 + 2] << 16) | (buf[j + i * 4 + 3] << 24);
      }
      A = a0; B = b0; C = c0; D = d0;
      for (i = 0; i < 64; i++) {
        if (i < 16) { F = (B & C) | ((~B) & D); g = i; }
        else if (i < 32) { F = (D & B) | ((~D) & C); g = (5 * i + 1) % 16; }
        else if (i < 48) { F = B ^ C ^ D; g = (3 * i + 5) % 16; }
        else { F = C ^ (B | (~D)); g = (7 * i) % 16; }
        F = (F + A + B9_MD5_K[i] + M[g]) >>> 0;
        tmp = D; D = C; C = B;
        B = (B + ((F << B9_MD5_S[i]) | (F >>> (32 - B9_MD5_S[i])))) >>> 0;
        A = tmp;
      }
      a0 = (a0 + A) >>> 0; b0 = (b0 + B) >>> 0; c0 = (c0 + C) >>> 0; d0 = (d0 + D) >>> 0;
    }
    var hs = '0123456789abcdef', out = '', w;
    var words = [a0, b0, c0, d0];
    for (j = 0; j < 4; j++) {
      w = words[j];
      for (i = 0; i < 4; i++) out += hs.charAt((w >>> (8 * i + 4)) & 15) + hs.charAt((w >>> (8 * i)) & 15);
    }
    return out;
  }

  async function b9ExtHash(text) {
    try {
      var u8 = b9CryptoTxt(text);
      var lines = ['MD5: ' + b9Md5Hex(u8)];
      var sb = b9FernetSubtle();
      var names = ['SHA-1', 'SHA-224', 'SHA-256', 'SHA-384', 'SHA-512'];
      var i, buf, dig;
      for (i = 0; i < names.length; i++) {
        if (!sb) { if (names[i] !== 'SHA-224') lines.push(names[i] + ': (WebCrypto unavailable)'); continue; }
        try {
          buf = await sb.digest(names[i], u8);
          dig = b9CryptoHex(new Uint8Array(buf));
          lines.push(names[i] + ': ' + dig);
        } catch (e) {
          if (names[i] !== 'SHA-224') lines.push(names[i] + ': Error: ' + (e && e.message ? e.message : String(e)));
        }
      }
      return lines.join('\n');
    } catch (e2) {
      return 'Error: ' + (e2 && e2.message ? e2.message : String(e2));
    }
  }
  /* ====== 第八批新增：类型生成/CSS 特异性/CSV/BlurHash ====== */
  /* ==========================================================
   * 1. TypeScript -> JSON Schema (draft-07)
   * ========================================================== */
  var B9_TS_DRAFT = 'http://json-schema.org/draft-07/schema#';

  function b9TsPrim(name) {
    if (name === 'string' || name === 'symbol' || name === 'RegExp') return { type: 'string' };
    if (name === 'number' || name === 'bigint' || name === 'float' || name === 'double' || name === 'integer') return { type: 'number' };
    if (name === 'boolean' || name === 'bool') return { type: 'boolean' };
    if (name === 'null') return { type: 'null' };
    if (name === 'object') return { type: 'object' };
    if (name === 'Date') return { type: 'string', format: 'date-time' };
    if (name === 'any' || name === 'unknown' || name === 'undefined' || name === 'void' || name === 'never') return {};
    return null;
  }

  function b9TsTrim(v) {
    return String(v === null || v === undefined ? '' : v).replace(/^\s+|\s+$/g, '');
  }

  function b9TsKeyCount(o) {
    var n = 0, k;
    for (k in o) { if (Object.prototype.hasOwnProperty.call(o, k)) n++; }
    return n;
  }

  function b9TsSkipWs(src, i) {
    while (i < src.length && /\s/.test(src.charAt(i))) i++;
    return i;
  }

  function b9TsSkipStr(src, i) {
    var q = src.charAt(i), n = src.length;
    i++;
    while (i < n) {
      if (src.charAt(i) === '\\') { i += 2; continue; }
      if (src.charAt(i) === q) return i + 1;
      i++;
    }
    return n;
  }

  /* 返回 src 中从 i 处括号内的文本（不含外层括号） */
  function b9TsMatch(src, i, open, close) {
    var d = 0, n = src.length, start = i + 1;
    while (i < n) {
      var ch = src.charAt(i);
      if (ch === '"' || ch === "'" || ch === '`') { i = b9TsSkipStr(src, i); continue; }
      if (ch === open) { d++; if (d === 1) start = i + 1; }
      else if (ch === close) { if (d > 0) d--; if (d === 0) return src.slice(start, i); }
      i++;
    }
    return src.slice(start);
  }

  /* 去掉注释、import / export、declare */
  function b9TsStrip(src) {
    var out = '', i = 0, n = src.length, ch, q;
    while (i < n) {
      ch = src.charAt(i);
      if (ch === '/' && src.charAt(i + 1) === '/') {
        while (i < n && src.charAt(i) !== '\n') i++;
        continue;
      }
      if (ch === '/' && src.charAt(i + 1) === '*') {
        i += 2;
        while (i < n && !(src.charAt(i) === '*' && src.charAt(i + 1) === '/')) i++;
        i += 2;
        continue;
      }
      if (ch === '"' || ch === "'" || ch === '`') {
        q = ch;
        out += ch;
        i++;
        while (i < n) {
          if (src.charAt(i) === '\\') { out += src.substr(i, 2); i += 2; continue; }
          out += src.charAt(i);
          if (src.charAt(i) === q) { i++; break; }
          i++;
        }
        continue;
      }
      out += ch;
      i++;
    }
    out = out.replace(/\bimport\s+[\s\S]*?\bfrom\s*(['"])[^'"]*\1\s*;?/g, '');
    out = out.replace(/\bimport\s+[^;\n]*;?/g, '');
    out = out.replace(/\bexport\s*\{[^}]*\}\s*;?/g, '');
    out = out.replace(/\bexport\s+(?:default\s+)?/g, '');
    out = out.replace(/\bdeclare\s+(?=interface|type|class|const|enum)/g, '');
    return out;
  }

  /* 从 from 开始找 interface 体的 '{'（跳过泛型与字符串） */
  function b9TsFindBrace(src, from) {
    var i = from, n = src.length, d = 0, ch;
    while (i < n) {
      ch = src.charAt(i);
      if (ch === '"' || ch === "'" || ch === '`') { i = b9TsSkipStr(src, i); continue; }
      if (ch === '<') { d++; i++; continue; }
      if (ch === '>') { if (d > 0) d--; i++; continue; }
      if (ch === '{') { if (d === 0) return i; }
      if (ch === ';') { if (d === 0) return -1; }
      i++;
    }
    return -1;
  }

  /* 在 depth 0 处按 seps 中的字符切分 */
  function b9TsSplitTop(text, seps) {
    var out = [], cur = '', d = 0, i = 0, n = text.length, ch;
    while (i < n) {
      ch = text.charAt(i);
      if (ch === '"' || ch === "'" || ch === '`') {
        var s0 = i;
        i = b9TsSkipStr(text, i);
        cur += text.slice(s0, i);
        continue;
      }
      if (ch === '{' || ch === '(' || ch === '[' || ch === '<') d++;
      else if (ch === '}' || ch === ')' || ch === ']' || ch === '>') { if (d > 0) d--; }
      if (d === 0 && seps.indexOf(ch) >= 0) { out.push(cur); cur = ''; i++; continue; }
      cur += ch;
      i++;
    }
    out.push(cur);
    return out;
  }

  /* 找 depth 0 的 ':'（属性分隔） */
  function b9TsFindColon(text) {
    var d = 0, i = 0, n = text.length, ch;
    while (i < n) {
      ch = text.charAt(i);
      if (ch === '"' || ch === "'" || ch === '`') { i = b9TsSkipStr(text, i); continue; }
      if (ch === '{' || ch === '(' || ch === '[' || ch === '<') d++;
      else if (ch === '}' || ch === ')' || ch === ']' || ch === '>') { if (d > 0) d--; }
      else if (ch === ':' && d === 0) {
        if (text.charAt(i + 1) !== ':') return i;
      }
      i++;
    }
    return -1;
  }

  /* type 别名语句的结束位置 */
  function b9TsStmtEnd(src, p) {
    var d = 0, i = p, n = src.length, ch, last = '', content = false;
    while (i < n) {
      ch = src.charAt(i);
      if (ch === '"' || ch === "'" || ch === '`') {
        i = b9TsSkipStr(src, i);
        last = 'x';
        content = true;
        continue;
      }
      if (ch === '(' || ch === '[' || ch === '{' || ch === '<') { d++; last = ch; content = true; i++; continue; }
      if (ch === ')' || ch === ']' || ch === '}' || ch === '>') { if (d > 0) d--; last = ch; content = true; i++; continue; }
      if (d === 0 && ch === ';') return i;
      if (d === 0 && (ch === '\n' || ch === '\r')) {
        if (content) {
          var nx = b9TsSkipWs(src, i);
          var nc = src.charAt(nx);
          if (nc === '|' || nc === '&' || '|&+=?!:,-(.<'.indexOf(last) >= 0) { i++; continue; }
          return i;
        }
        i++;
        continue;
      }
      if (/\s/.test(ch)) { i++; continue; }
      last = ch;
      content = true;
      i++;
    }
    return n;
  }

  function b9TsObject(body, defs) {
    var members = b9TsSplitTop(body, ';,\n');
    var props = {}, required = [], additional = null, i, t, ci, key, opt, ty, cb, rest;
    for (i = 0; i < members.length; i++) {
      t = b9TsTrim(members[i]);
      if (!t) continue;
      if (t.charAt(0) === '[') {
        cb = t.indexOf(']');
        rest = t.slice(cb + 1);
        if (cb > 0 && rest.indexOf(':') >= 0) {
          additional = b9TsType(rest.slice(rest.indexOf(':') + 1), defs);
          continue;
        }
      }
      t = t.replace(/^readonly\s+/, '');
      ci = b9TsFindColon(t);
      if (ci < 0) continue;
      key = b9TsTrim(t.slice(0, ci));
      if (key.indexOf('(') >= 0) continue;
      if (key.charAt(0) === '[') continue;
      opt = false;
      if (key.charAt(key.length - 1) === '?') { opt = true; key = b9TsTrim(key.slice(0, -1)); }
      key = key.replace(/^['"]/, '').replace(/['"]$/, '');
      if (!key) continue;
      ty = b9TsTrim(t.slice(ci + 1));
      props[key] = b9TsType(ty, defs);
      if (!opt) required.push(key);
    }
    var sch = { type: 'object', properties: props };
    if (required.length) sch.required = required;
    if (additional) sch.additionalProperties = additional;
    return sch;
  }

  function b9TsUnion(parts, defs) {
    var enums = [], types = [], complex = [], i, p, s;
    for (i = 0; i < parts.length; i++) {
      p = b9TsTrim(parts[i]);
      if (!p) continue;
      if (p === 'null' || p === 'undefined') continue;
      s = b9TsType(p, defs);
      if (s['const'] !== undefined && b9TsKeyCount(s) === 1) { enums.push(s['const']); continue; }
      if (typeof s.type === 'string' && b9TsKeyCount(s) === 1) {
        if (types.indexOf(s.type) < 0) types.push(s.type);
        continue;
      }
      complex.push(s);
    }
    var out = {};
    if (enums.length) out['enum'] = enums;
    if (types.length) out.type = types.length === 1 ? types[0] : types;
    if (complex.length) {
      if (!enums.length && !types.length) return complex.length === 1 ? complex[0] : { anyOf: complex };
      out.anyOf = complex;
    }
    if (!enums.length && !types.length && !complex.length) out = {};
    return out;
  }

  function b9TsType(raw, defs) {
    var t = b9TsTrim(raw);
    if (!t) return {};
    var parts = b9TsSplitTop(t, '|');
    if (parts.length > 1) return b9TsUnion(parts, defs);
    t = t.replace(/^readonly\s+/, '');
    if (/^'[^']*'$/.test(t)) return { 'const': t.slice(1, -1) };
    if (/^"[^"]*"$/.test(t)) return { 'const': t.slice(1, -1) };
    if (/^-?\d+(\.\d+)?$/.test(t)) return { 'const': parseFloat(t) };
    if (t === 'true') return { 'const': true };
    if (t === 'false') return { 'const': false };
    var prim = b9TsPrim(t);
    if (prim) return prim;
    /* T[] */
    var arrM = /^([\s\S]*)\[\]$/.exec(t);
    if (arrM && b9TsTrim(arrM[1])) return { type: 'array', items: b9TsType(arrM[1], defs) };
    /* 泛型 */
    var gen = /^([A-Za-z_$][\w$.]*)\s*<([\s\S]*)>$/.exec(t);
    if (gen) {
      var g = gen[1], args = b9TsSplitTop(gen[2], ','), a0 = b9TsTrim(args[0] || '');
      if (g === 'Array' || g === 'ReadonlyArray' || g === 'Set' || g === 'ReadonlySet') {
        return { type: 'array', items: b9TsType(a0 || 'any', defs) };
      }
      if (g === 'Record') {
        var rs = { type: 'object' };
        rs.additionalProperties = b9TsType(b9TsTrim(args[1] || 'any'), defs);
        return rs;
      }
      if (g === 'Partial' || g === 'Required' || g === 'Readonly' || g === 'NonNullable' || g === 'Promise') {
        return b9TsType(a0 || 'any', defs);
      }
      if (g === 'Omit' || g === 'Pick') {
        var base = defs[a0];
        if (base) return { $ref: '#/definitions/' + a0 };
        return { type: 'object' };
      }
      if (defs[g]) return { $ref: '#/definitions/' + g };
      if (args.length > 1) return { type: 'object' };
      return b9TsType(a0 || 'any', defs);
    }
    if (t.charAt(0) === '{') return b9TsObject(b9TsMatch(t, 0, '{', '}'), defs);
    if (t.charAt(0) === '(') {
      var inner = b9TsMatch(t, 0, '(', ')');
      var after = b9TsTrim(t.slice(inner.length + 2));
      if (after.indexOf('=>') === 0) return b9TsType(after.slice(2), defs);
      return b9TsType(inner, defs);
    }
    if (defs[t]) return { $ref: '#/definitions/' + t };
    if (t.indexOf('=>') >= 0) return b9TsType(t.slice(t.indexOf('=>') + 2), defs);
    return { type: 'object' };
  }

  function b9Ts2Schema(tsText) {
    try {
      var src = b9TsStrip(String(tsText === null || tsText === undefined ? '' : tsText));
      var order = [], bodies = [], seen = {};
      var re = /\binterface\s+([A-Za-z_$][\w$]*)|\btype\s+([A-Za-z_$][\w$]*)\s*(?=[<=(])/g;
      var m, name, br, blk, p, g, end;
      while ((m = re.exec(src)) !== null) {
        name = m[1] || m[2];
        if (!name || seen[name] !== undefined) continue;
        if (m[1]) {
          br = b9TsFindBrace(src, re.lastIndex);
          if (br < 0) continue;
          blk = b9TsMatch(src, br, '{', '}');
          bodies.push({ name: name, kind: 'object', text: blk });
          re.lastIndex = br + blk.length + 2;
        } else {
          p = b9TsSkipWs(src, re.lastIndex);
          if (src.charAt(p) === '<') {
            g = b9TsMatch(src, p, '<', '>');
            p = b9TsSkipWs(src, p + g.length + 2);
          }
          if (src.charAt(p) !== '=') continue;
          p = b9TsSkipWs(src, p + 1);
          if (src.charAt(p) === '{') {
            blk = b9TsMatch(src, p, '{', '}');
            bodies.push({ name: name, kind: 'object', text: blk });
          } else {
            end = b9TsStmtEnd(src, p);
            bodies.push({ name: name, kind: 'expr', text: src.slice(p, end) });
          }
          re.lastIndex = p;
        }
        seen[name] = 1;
        order.push(name);
      }
      if (!order.length) return 'Error: 未找到 interface 或 type 定义';
      var defs = {}, i;
      for (i = 0; i < order.length; i++) defs[order[i]] = {};
      for (i = 0; i < bodies.length; i++) {
        if (bodies[i].kind === 'object') defs[bodies[i].name] = b9TsObject(bodies[i].text, defs);
        else defs[bodies[i].name] = b9TsType(bodies[i].text, defs);
      }
      var root, k;
      if (order.length === 1) {
        root = { $schema: B9_TS_DRAFT };
        var one = defs[order[0]];
        for (k in one) { if (Object.prototype.hasOwnProperty.call(one, k)) root[k] = one[k]; }
        root.definitions = defs;
      } else {
        root = { $schema: B9_TS_DRAFT, $ref: '#/definitions/' + order[0], definitions: defs };
      }
      return JSON.stringify(root, null, 2);
    } catch (e) {
      return 'Error: ' + (e && e.message ? e.message : String(e));
    }
  }

  /* ==========================================================
   * 2. CSS 特异性计算器
   * ========================================================== */
  var B9_SPEC_PE = {
    'before': 1, 'after': 1, 'first-line': 1, 'first-letter': 1, 'placeholder': 1,
    'selection': 1, 'backdrop': 1, 'marker': 1, 'cue': 1, 'cue-region': 1,
    'file-selector-button': 1, 'grammar-error': 1, 'spelling-error': 1,
    'target-text': 1, 'highlight': 1, 'part': 1, 'slotted': 1,
    'input-placeholder': 1, 'scrollbar': 1, 'details-content': 1
  };
  var B9_SPEC_FN = { 'not': 1, 'is': 1, 'where': 1, 'has': 1, 'matches': 1, '-webkit-any': 1, '-moz-any': 1 };

  function b9SpecSkipIdent(s, i) {
    while (i < s.length) {
      var ch = s.charAt(i);
      if (ch === '\\') { i += 2; continue; }
      if (/[-\w\u0080-\uffff]/.test(ch)) { i++; continue; }
      break;
    }
    return i;
  }

  function b9SpecIdentAt(s, i) {
    var out = '';
    while (i < s.length) {
      var ch = s.charAt(i);
      if (ch === '\\') { i += 2; continue; }
      if (/[-\w\u0080-\uffff]/.test(ch)) { out += ch; i++; continue; }
      break;
    }
    return out;
  }

  function b9SpecSkipStr(s, i) {
    var q = s.charAt(i), n = s.length;
    i++;
    while (i < n) {
      if (s.charAt(i) === '\\') { i += 2; continue; }
      if (s.charAt(i) === q) return i + 1;
      i++;
    }
    return n;
  }

  function b9SpecSkip(s, i, open, close) {
    var d = 0, n = s.length, ch;
    while (i < n) {
      ch = s.charAt(i);
      if (ch === '\\') { i += 2; continue; }
      if (ch === '"' || ch === "'") { i = b9SpecSkipStr(s, i); continue; }
      if (ch === open) d++;
      else if (ch === close) { d--; if (d === 0) return i + 1; }
      i++;
    }
    return n;
  }

  /* 取函数伪类括号内的文本，同时返回结束位置 */
  function b9SpecArgs(s, i) {
    var n = s.length;
    if (s.charAt(i) !== '(') return { text: '', end: i };
    var d = 0, j = i, ch;
    var start = i + 1;
    while (j < n) {
      ch = s.charAt(j);
      if (ch === '\\') { j += 2; continue; }
      if (ch === '"' || ch === "'") { j = b9SpecSkipStr(s, j); continue; }
      if (ch === '(') d++;
      else if (ch === ')') { d--; if (d === 0) return { text: s.slice(start, j), end: j + 1 }; }
      j++;
    }
    return { text: s.slice(start), end: n };
  }

  function b9SpecMerge(x, y) {
    x.a += y.a; x.b += y.b; x.c += y.c;
    return x;
  }

  function b9SpecMax(x, y) {
    var vx = x.a * 10000 + x.b * 100 + x.c, vy = y.a * 10000 + y.b * 100 + y.c;
    return vy > vx ? y : x;
  }

  /* 单个（不含顶层逗号）选择器的计数 */
  function b9SpecCount(sel, notes) {
    var s = String(sel), i = 0, n = s.length, ch, name, lname, isElem, start, after, arg, r;
    var res = { a: 0, b: 0, c: 0 };
    notes = notes || [];
    while (i < n) {
      ch = s.charAt(i);
      if (ch === '\\') { i += 2; continue; }
      if (ch === '"' || ch === "'") { i = b9SpecSkipStr(s, i); continue; }
      if (ch === '[') { res.b++; i = b9SpecSkip(s, i, '[', ']'); continue; }
      if (ch === '(') { i = b9SpecSkip(s, i, '(', ')'); continue; }
      if (ch === '.') {
        if (i + 1 < n && /[0-9]/.test(s.charAt(i + 1))) { i++; continue; }
        if (i + 1 < n && /[-\w\u0080-\uffff]/.test(s.charAt(i + 1))) { res.b++; i = b9SpecSkipIdent(s, i + 1); continue; }
        i++;
        continue;
      }
      if (ch === '#') { res.a++; i = b9SpecSkipIdent(s, i + 1); continue; }
      if (ch === ':') {
        isElem = s.charAt(i + 1) === ':';
        start = isElem ? i + 2 : i + 1;
        name = b9SpecIdentAt(s, start);
        after = start + name.length;
        lname = name.toLowerCase();
        if (isElem || B9_SPEC_PE[lname] || lname.charAt(0) === '-') {
          res.c++;
          arg = b9SpecArgs(s, after);
          i = arg.end;
          continue;
        }
        if (B9_SPEC_FN[lname]) {
          arg = b9SpecArgs(s, after);
          r = b9SpecList(arg.text, notes);
          if (lname === 'where') notes.push(':where() 的权重为 0');
          else b9SpecMerge(res, r);
          i = arg.end;
          continue;
        }
        res.b++;
        arg = b9SpecArgs(s, after);
        i = arg.end;
        continue;
      }
      if (ch === '*' || ch === '+' || ch === '>' || ch === '~' || ch === ',' || ch === ')' || /\s/.test(ch)) { i++; continue; }
      if (/[-\w\u0080-\uffff]/.test(ch)) { res.c++; i = b9SpecSkipIdent(s, i); continue; }
      i++;
    }
    return res;
  }

  /* 逗号分隔的选择器列表，取特异性最高者 */
  function b9SpecList(text, notes) {
    var parts = [], cur = '', i = 0, n = String(text).length, ch, res = { a: 0, b: 0, c: 0 }, first = true;
    var s = String(text);
    while (i < n) {
      ch = s.charAt(i);
      if (ch === '\\') { cur += s.substr(i, 2); i += 2; continue; }
      if (ch === '"' || ch === "'") { var s0 = i; i = b9SpecSkipStr(s, i); cur += s.slice(s0, i); continue; }
      if (ch === '(' || ch === '[') { var p0 = i; i = b9SpecSkip(s, i, ch === '(' ? '(' : '[', ch === '(' ? ')' : ']'); cur += s.slice(p0, i); continue; }
      if (ch === ',') { parts.push(cur); cur = ''; i++; continue; }
      cur += ch;
      i++;
    }
    parts.push(cur);
    for (i = 0; i < parts.length; i++) {
      if (!b9TsTrim(parts[i])) continue;
      var r = b9SpecCount(parts[i], notes);
      res = first ? r : b9SpecMax(res, r);
      first = false;
    }
    return res;
  }

  function b9SpecNote(it) {
    var seg = [];
    if (it.a) seg.push(it.a + ' 个 ID');
    if (it.b) seg.push(it.b + ' 个类/属性/伪类');
    if (it.c) seg.push(it.c + ' 个元素/伪元素');
    if (!seg.length) return '通用选择器，无计分（0 特异性）';
    return seg.join(' + ');
  }

  function b9Specificity(selectorText) {
    var lines = String(selectorText === null || selectorText === undefined ? '' : selectorText).split(/\r?\n/);
    var items = [], out = [], i, line, r;
    for (i = 0; i < lines.length; i++) {
      line = lines[i].replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s+|\s+$/g, '');
      if (!line) continue;
      var notes = [];
      r = b9SpecList(line, notes);
      items.push({ sel: line, a: r.a, b: r.b, c: r.c, notes: notes });
    }
    if (!items.length) return 'Error: 请输入至少一个 CSS 选择器（每行一个）';
    for (i = 0; i < items.length; i++) {
      out.push(items[i].sel + '  \u2192  (' + items[i].a + ', ' + items[i].b + ', ' + items[i].c + ')  [\u5177\u4f53\u5ea6 ' + items[i].a + '-' + items[i].b + '-' + items[i].c + ']  \u2192  ' + b9SpecNote(items[i]));
    }
    if (items.length > 1) {
      var sorted = items.slice().sort(function (x, y) {
        return (y.a * 10000 + y.b * 100 + y.c) - (x.a * 10000 + x.b * 100 + x.c);
      });
      out.push('---');
      out.push('\u7279\u5f02\u6027\u6392\u5e8f\uff08\u4ece\u9ad8\u5230\u4f4e\uff09\uff1a');
      for (i = 0; i < sorted.length; i++) {
        out.push((i + 1) + '. ' + sorted[i].sel + '   (' + sorted[i].a + '-' + sorted[i].b + '-' + sorted[i].c + ')');
      }
    }
    return out.join('\n');
  }

  /* ==========================================================
   * 3. CSV 合并
   * ========================================================== */
  function b9CsvRow(row, dlm) {
    var out = [], i;
    for (i = 0; i < row.length; i++) {
      out.push(b9CsvCell(row[i] === null || row[i] === undefined ? '' : String(row[i]), dlm));
    }
    return out.join(dlm);
  }

  function b9CsvTable(header, rows, dlm) {
    var out = [b9CsvRow(header, dlm)], i;
    for (i = 0; i < rows.length; i++) out.push(b9CsvRow(rows[i], dlm));
    return out.join('\n');
  }

  function b9CsvMerge(textA, textB) {
    try {
      var A = b9ParseCsv(String(textA === null || textA === undefined ? '' : textA));
      var B = b9ParseCsv(String(textB === null || textB === undefined ? '' : textB));
      if (!A.length || !A[0].length) return 'Error: 第一个 CSV 为空';
      var headerA = A[0].slice(), rowsA = A.slice(1);
      var headerB = B.length ? B[0] : [], rowsB = B.length ? B.slice(1) : [];
      var merged = headerA.slice(), mapB = [], i, j, idx, k, lower;
      for (j = 0; j < headerB.length; j++) {
        idx = -1;
        for (k = 0; k < merged.length; k++) { if (merged[k] === headerB[j]) { idx = k; break; } }
        if (idx < 0) {
          lower = String(headerB[j]).toLowerCase();
          for (k = 0; k < merged.length; k++) { if (String(merged[k]).toLowerCase() === lower) { idx = k; break; } }
        }
        if (idx < 0) { merged.push(headerB[j]); idx = merged.length - 1; }
        mapB[j] = idx;
      }
      var out = [], row;
      for (i = 0; i < rowsA.length + rowsB.length; i++) {
        row = [];
        for (k = 0; k < merged.length; k++) row.push('');
        if (i < rowsA.length) {
          for (k = 0; k < headerA.length && k < rowsA[i].length; k++) row[k] = rowsA[i][k];
        } else {
          var br = rowsB[i - rowsA.length];
          for (k = 0; k < mapB.length && k < br.length; k++) row[mapB[k]] = br[k];
        }
        out.push(row);
      }
      var res = b9CsvTable(merged, out, ',');
      res += '\n---\nA \u884c: ' + rowsA.length + ', B \u884c: ' + rowsB.length + ', \u5408\u5e76\u540e: ' + out.length;
      if (merged.length > headerA.length) {
        res += '\n\u65b0\u589e\u5217: ' + merged.slice(headerA.length).join(', ');
      }
      return res;
    } catch (e) {
      return 'Error: ' + (e && e.message ? e.message : String(e));
    }
  }

  /* ==========================================================
   * 4. CSV 拆分
   * ========================================================== */
  function b9CsvSplit(text, mode, n) {
    try {
      var rows = b9ParseCsv(String(text === null || text === undefined ? '' : text));
      if (!rows.length || !rows[0].length) return 'Error: CSV 为空';
      var header = rows[0], data = rows.slice(1), i, out = [], groups = [], names = [];
      if (mode === 'cols') {
        var ci = (typeof n === 'number' && isFinite(n) && n >= 1) ? Math.floor(n) - 1 : 0;
        if (ci >= header.length) ci = 0;
        var map = {};
        for (i = 0; i < data.length; i++) {
          var key = data[i][ci] === null || data[i][ci] === undefined ? '' : String(data[i][ci]);
          if (map[key] === undefined) { map[key] = []; names.push(key); }
          map[key].push(data[i]);
        }
        for (i = 0; i < names.length; i++) groups.push({ name: names[i], rows: map[names[i]] });
      } else {
        var size = (typeof n === 'number' && isFinite(n) && n >= 1) ? Math.floor(n) : 100;
        for (i = 0; i < data.length; i += size) {
          groups.push({ name: 'part-' + (groups.length + 1), rows: data.slice(i, i + size) });
        }
      }
      if (!groups.length) return 'Error: 没有可拆分的数据行';
      for (i = 0; i < groups.length; i++) {
        out.push('--- ' + (groups[i].name === '' ? '(\u7a7a)' : groups[i].name) + ' (' + groups[i].rows.length + ') ---');
        out.push(b9CsvTable(header, groups[i].rows, ','));
      }
      return out.join('\n');
    } catch (e) {
      return 'Error: ' + (e && e.message ? e.message : String(e));
    }
  }

  /* ==========================================================
   * 5. BlurHash 编解码
   * ========================================================== */
  var B9_BH83 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_' + '{|}~';

  function b9Bh83(value, len) {
    var out = '', i, d;
    for (i = len - 1; i >= 0; i--) {
      d = Math.floor(value / Math.pow(83, i)) % 83;
      if (d < 0) d = 0;
      out += B9_BH83.charAt(d);
    }
    return out;
  }

  function b9BhUn83(s) {
    var v = 0, i;
    for (i = 0; i < s.length; i++) {
      var d = B9_BH83.indexOf(s.charAt(i));
      if (d < 0) return 0;
      v = v * 83 + d;
    }
    return v;
  }

  function b9BhSrgbToLinear(v) {
    var x = v / 255;
    if (x <= 0.04045) return x / 12.92;
    return Math.pow((x + 0.055) / 1.055, 2.4);
  }

  function b9BhLinearToSrgb(v) {
    var x = v;
    if (x < 0) x = 0;
    if (x > 1) x = 1;
    if (x <= 0.0031308) return Math.round(x * 12.92 * 255 + 0.5);
    return Math.round((1.055 * Math.pow(x, 1 / 2.4) - 0.055) * 255 + 0.5);
  }

  function b9BhSignPow(v, e) {
    return (v < 0 ? -1 : 1) * Math.pow(Math.abs(v), e);
  }

  function b9BhEncodeDC(r, g, b) {
    return (b9BhLinearToSrgb(r) << 16) + (b9BhLinearToSrgb(g) << 8) + b9BhLinearToSrgb(b);
  }

  function b9BhEncodeAC(r, g, b, maxValue) {
    var qr = clamp(Math.floor(b9BhSignPow(r / maxValue, 0.5) * 9 + 9.5), 0, 18);
    var qg = clamp(Math.floor(b9BhSignPow(g / maxValue, 0.5) * 9 + 9.5), 0, 18);
    var qb = clamp(Math.floor(b9BhSignPow(b / maxValue, 0.5) * 9 + 9.5), 0, 18);
    return qr * 19 * 19 + qg * 19 + qb;
  }

  function b9BlurHashEncode(pixels, w, h, cx, cy) {
    w = w | 0;
    h = h | 0;
    cx = clamp(cx | 0, 1, 9);
    cy = clamp(cy | 0, 1, 9);
    if (!pixels || w <= 0 || h <= 0 || pixels.length < w * h * 4) return '';
    var components = [], i, j, x, y, p, basis, rr, gg, bb, norm;
    for (j = 0; j < cy; j++) {
      for (i = 0; i < cx; i++) {
        norm = (i === 0 && j === 0) ? 1 : 2;
        rr = 0; gg = 0; bb = 0;
        for (y = 0; y < h; y++) {
          for (x = 0; x < w; x++) {
            basis = norm * Math.cos(Math.PI * i * x / w) * Math.cos(Math.PI * j * y / h);
            p = (y * w + x) * 4;
            rr += basis * b9BhSrgbToLinear(pixels[p]);
            gg += basis * b9BhSrgbToLinear(pixels[p + 1]);
            bb += basis * b9BhSrgbToLinear(pixels[p + 2]);
          }
        }
        components.push([rr / (w * h), gg / (w * h), bb / (w * h)]);
      }
    }
    var dc = components[0], k, maxValue = 0;
    for (k = 1; k < components.length; k++) {
      for (j = 0; j < 3; j++) {
        var av = Math.abs(components[k][j]);
        if (av > maxValue) maxValue = av;
      }
    }
    if (maxValue > 1) maxValue = 1;
    if (maxValue < 0) maxValue = 0;
    var hash = '';
    hash += b9Bh83((cx - 1) + (cy - 1) * 9, 1);
    hash += b9Bh83(clamp(Math.floor(maxValue * 166 - 0.5), 0, 82), 1);
    hash += b9Bh83(b9BhEncodeDC(dc[0], dc[1], dc[2]), 4);
    for (k = 1; k < components.length; k++) {
      var c = components[k];
      if (maxValue <= 0) hash += b9Bh83(0, 2);
      else hash += b9Bh83(b9BhEncodeAC(c[0], c[1], c[2], maxValue), 2);
    }
    return hash;
  }

  function b9BlurHashDecode(hash, w, h, punch) {
    hash = String(hash === null || hash === undefined ? '' : hash);
    w = w | 0;
    h = h | 0;
    if (typeof punch !== 'number' || !isFinite(punch) || punch <= 0) punch = 1;
    if (hash.length < 6 || w <= 0 || h <= 0) return null;
    var ci;
    for (ci = 0; ci < hash.length; ci++) { if (B9_BH83.indexOf(hash.charAt(ci)) < 0) return null; }
    var sizeFlag = b9BhUn83(hash.charAt(0));
    var cx = (sizeFlag % 9) + 1, cy = Math.floor(sizeFlag / 9) + 1;
    if (cx * cy * 2 + 4 !== hash.length) return null;
    var maxValue = (b9BhUn83(hash.charAt(1)) + 1) / 166 * punch;
    var dcValue = b9BhUn83(hash.substr(2, 4));
    var colors = [], i, j, k, v, q, quant;
    colors.push([
      b9BhSrgbToLinear((dcValue >> 16) & 255),
      b9BhSrgbToLinear((dcValue >> 8) & 255),
      b9BhSrgbToLinear(dcValue & 255)
    ]);
    for (k = 1; k < cx * cy; k++) {
      v = b9BhUn83(hash.substr(6 + (k - 1) * 2, 2));
      quant = [Math.floor(v / (19 * 19)), Math.floor(v / 19) % 19, v % 19];
      colors.push([
        b9BhSignPow((quant[0] - 9) / 9, 2) * maxValue,
        b9BhSignPow((quant[1] - 9) / 9, 2) * maxValue,
        b9BhSignPow((quant[2] - 9) / 9, 2) * maxValue
      ]);
    }
    var out = new Uint8ClampedArray(w * h * 4), x, y, r, g, b, basis, col, idx;
    for (y = 0; y < h; y++) {
      for (x = 0; x < w; x++) {
        r = 0; g = 0; b = 0;
        for (j = 0; j < cy; j++) {
          for (i = 0; i < cx; i++) {
            basis = Math.cos(Math.PI * x * i / w) * Math.cos(Math.PI * y * j / h);
            col = colors[i + j * cx];
            r += col[0] * basis;
            g += col[1] * basis;
            b += col[2] * basis;
          }
        }
        idx = (y * w + x) * 4;
        out[idx] = b9BhLinearToSrgb(r);
        out[idx + 1] = b9BhLinearToSrgb(g);
        out[idx + 2] = b9BhLinearToSrgb(b);
        out[idx + 3] = 255;
      }
    }
    return out;
  }
  /* ====== 第十一批新增：经典密码 / 编码 / 现代哈希（开源项目对照 2026-09-04） ====== */
  /* ================================================================
     chunk_cipherA_fn  --  c11 classic cipher engines (candidates 1..34)
     ES5 ONLY: var / function, no arrow, no let/const, no template string.
     Every engine: function c11Xxx(t, key, dec, opt)
       t   = input text (string)
       key = primary key (string)
       dec = boolean, true = decrypt
       opt = optional object (period / rows / cols / route / key2 / start ...)
     Non letters are preserved in place for substitution ciphers and
     stripped for fractionation / transposition ciphers.
     ================================================================ */

  /* ---------------- shared helpers ---------------- */
  function c11Mod(n, m) { var r = n % m; return r < 0 ? r + m : r; }
  function c11Str(s) { return s === null || s === undefined ? '' : String(s); }
  function c11Up(s) { return c11Str(s).toUpperCase(); }
  function c11Letters(s) {
    var t = c11Up(s), o = '', i, c;
    for (i = 0; i < t.length; i++) { c = t.charCodeAt(i); if (c >= 65 && c <= 90) o += t.charAt(i); }
    return o;
  }
  function c11Digits(s) {
    var t = c11Str(s), o = '', i, c;
    for (i = 0; i < t.length; i++) { c = t.charCodeAt(i); if (c >= 48 && c <= 57) o += t.charAt(i); }
    return o;
  }
  function c11Dedup(s) {
    var seen = {}, o = '', i, c;
    for (i = 0; i < s.length; i++) { c = s.charAt(i); if (!seen[c]) { seen[c] = 1; o += c; } }
    return o;
  }
  function c11Mix(key, alpha) {
    var a = alpha || 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', k = c11Dedup(c11Str(key)), o = '', i, c;
    for (i = 0; i < k.length; i++) { c = k.charAt(i); if (a.indexOf(c) >= 0 && o.indexOf(c) < 0) o += c; }
    for (i = 0; i < a.length; i++) { c = a.charAt(i); if (o.indexOf(c) < 0) o += c; }
    return o;
  }
  function c11Sq5(key) { return c11Mix(c11Letters(key).replace(/J/g, 'I'), 'ABCDEFGHIKLMNOPQRSTUVWXYZ'); }
  function c11SqStd() { return 'ABCDEFGHIKLMNOPQRSTUVWXYZ'; }
  function c11Sq6(key) { return c11Mix(c11Letters(key), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'); }
  /* column read order: alphabetical by key letter, ties broken left to right */
  function c11KeyOrder(key) {
    var k = c11Letters(key), n = k.length, a = [], i;
    for (i = 0; i < n; i++) a.push(i);
    a.sort(function (x, y) {
      var d = k.charCodeAt(x) - k.charCodeAt(y);
      if (d !== 0) return d;
      return x - y;
    });
    return a;
  }
  /* how many chars each column holds when len chars are written row wise */
  function c11ColLens(len, ncols) {
    var full = Math.floor(len / ncols), rem = len % ncols, a = [], i;
    for (i = 0; i < ncols; i++) a.push(full + (i < rem ? 1 : 0));
    return a;
  }
  /* grid[r][c] -> text, columns visited in key order */
  function c11ReadCols(grid, nrows, order) {
    var out = '', j, r;
    for (j = 0; j < order.length; j++) {
      for (r = 0; r < nrows; r++) out += grid[r][order[j]] || '';
    }
    return out;
  }
  /* text -> grid[r][c], columns filled in key order */
  function c11FillCols(text, nrows, order, lens) {
    var grid = [], r, j, p = 0, take;
    for (r = 0; r < nrows; r++) grid.push([]);
    for (j = 0; j < order.length; j++) {
      take = lens[order[j]];
      for (r = 0; r < take; r++) grid[r][order[j]] = text.charAt(p++);
    }
    return grid;
  }
  function c11Opt(opt) { return opt && typeof opt === 'object' ? opt : {}; }
  function c11Int(v, d) { var n = parseInt(v, 10); return isNaN(n) ? d : n; }
  /* split into digraphs, insert X between doubled letters, pad odd tail */
  function c11Digraphs(s) {
    var pairs = [], k = 0;
    while (k < s.length) {
      if (k + 1 >= s.length || s.charAt(k + 1) === s.charAt(k)) { pairs.push(s.charAt(k) + 'X'); k += 1; }
      else { pairs.push(s.charAt(k) + s.charAt(k + 1)); k += 2; }
    }
    return pairs;
  }
  /* plain consecutive pairs, used when decrypting (no padding logic) */
  function c11Pairs(s) {
    var pairs = [], k;
    for (k = 0; k + 1 < s.length; k += 2) pairs.push(s.substr(k, 2));
    return pairs;
  }

  /* ---------------- 1. playfair ---------------- */
  function c11Playfair(t, key, dec) {
    var sq = c11Sq5(key), s = c11Letters(t).replace(/J/g, 'I'), out = '', i, a, b, ra, ca, rb, cb;
    if (dec) {
      for (i = 0; i + 1 < s.length; i += 2) {
        a = sq.indexOf(s.charAt(i)); b = sq.indexOf(s.charAt(i + 1));
        ra = Math.floor(a / 5); ca = a % 5; rb = Math.floor(b / 5); cb = b % 5;
        if (ra === rb) { out += sq.charAt(ra * 5 + c11Mod(ca - 1, 5)) + sq.charAt(rb * 5 + c11Mod(cb - 1, 5)); }
        else if (ca === cb) { out += sq.charAt(c11Mod(ra - 1, 5) * 5 + ca) + sq.charAt(c11Mod(rb - 1, 5) * 5 + cb); }
        else { out += sq.charAt(ra * 5 + cb) + sq.charAt(rb * 5 + ca); }
      }
      /* drop padding X: between two equal letters, or trailing */
      var res = '', n = out.length, j;
      for (j = 0; j < n; j++) {
        if (out.charAt(j) === 'X') {
          if (j === n - 1) continue;
          if (j > 0 && j + 1 < n && out.charAt(j - 1) === out.charAt(j + 1)) continue;
        }
        res += out.charAt(j);
      }
      return res;
    }
    var pairs = c11Digraphs(s);
    for (i = 0; i < pairs.length; i++) {
      a = sq.indexOf(pairs[i].charAt(0)); b = sq.indexOf(pairs[i].charAt(1));
      ra = Math.floor(a / 5); ca = a % 5; rb = Math.floor(b / 5); cb = b % 5;
      if (ra === rb) { out += sq.charAt(ra * 5 + c11Mod(ca + 1, 5)) + sq.charAt(rb * 5 + c11Mod(cb + 1, 5)); }
      else if (ca === cb) { out += sq.charAt(c11Mod(ra + 1, 5) * 5 + ca) + sq.charAt(c11Mod(rb + 1, 5) * 5 + cb); }
      else { out += sq.charAt(ra * 5 + cb) + sq.charAt(rb * 5 + ca); }
    }
    return out;
  }

  /* ---------------- 2. polybius square ---------------- */
  function c11Polybius(t, key, dec) {
    var sq = c11Sq5(key), i, p, out = '';
    if (dec) {
      var d = c11Digits(t), v;
      for (i = 0; i + 1 < d.length; i += 2) {
        v = (parseInt(d.charAt(i), 10) - 1) * 5 + (parseInt(d.charAt(i + 1), 10) - 1);
        if (v >= 0 && v < 25) out += sq.charAt(v);
      }
      return out;
    }
    var s = c11Letters(t).replace(/J/g, 'I');
    for (i = 0; i < s.length; i++) {
      p = sq.indexOf(s.charAt(i));
      if (p < 0) continue;
      out += (out.length ? ' ' : '') + (Math.floor(p / 5) + 1) + (p % 5 + 1);
    }
    return out;
  }

  /* ---------------- 3. bifid ---------------- */
  function c11Bifid(t, key, dec, opt) {
    var o = c11Opt(opt), period = c11Int(o.period, 5);
    if (period < 1) period = 5;
    var sq = c11Sq5(key), s = c11Letters(t).replace(/J/g, 'I');
    var out = '', b, i, n, rows, cols, seq, p;
    for (b = 0; b < s.length; b += period) {
      n = Math.min(period, s.length - b);
      if (n < 1) continue;
      rows = ''; cols = '';
      for (i = 0; i < n; i++) {
        p = sq.indexOf(s.charAt(b + i));
        rows += String(Math.floor(p / 5));
        cols += String(p % 5);
      }
      if (dec) {
        /* cipher letters give the (row,col) pairs, re interleave to the raw stream */
        seq = '';
        for (i = 0; i < n; i++) seq += rows.charAt(i) + cols.charAt(i);
        for (i = 0; i < n; i++) {
          out += sq.charAt(parseInt(seq.charAt(i), 10) * 5 + parseInt(seq.charAt(n + i), 10));
        }
      } else {
        seq = rows + cols;
        for (i = 0; i + 1 < seq.length; i += 2) {
          out += sq.charAt(parseInt(seq.charAt(i), 10) * 5 + parseInt(seq.charAt(i + 1), 10));
        }
      }
    }
    return out;
  }

  /* ---------------- 4. columnar transposition ---------------- */
  function c11Columnar(t, key, dec) {
    var s = c11Letters(t), order = c11KeyOrder(key), nc = order.length;
    if (!nc) return s;
    var nr = Math.ceil(s.length / nc), lens = c11ColLens(s.length, nc), grid, r, c, p = 0, out = '';
    if (dec) {
      grid = c11FillCols(s, nr, order, lens);
      for (r = 0; r < nr; r++) { for (c = 0; c < nc; c++) out += grid[r][c] || ''; }
      return out;
    }
    grid = [];
    for (r = 0; r < nr; r++) {
      grid.push([]);
      for (c = 0; c < nc; c++) { grid[r][c] = p < s.length ? s.charAt(p++) : ''; }
    }
    return c11ReadCols(grid, nr, order);
  }

  /* ---------------- 5. scytale ---------------- */
  function c11Scytale(t, key, dec, opt) {
    var o = c11Opt(opt), rows = c11Int(o.rows, c11Int(c11Digits(key), 5));
    if (rows < 1) rows = 1;
    var s = c11Letters(t), n = s.length, cols = Math.ceil(n / rows), r, c, idx, out = '', p = 0;
    if (dec) {
      var g = [];
      for (r = 0; r < rows; r++) g.push([]);
      for (c = 0; c < cols; c++) {
        for (r = 0; r < rows; r++) {
          idx = r * cols + c;
          if (idx < n) g[r][c] = s.charAt(p++);
        }
      }
      for (r = 0; r < rows; r++) { for (c = 0; c < cols; c++) if (g[r][c] !== undefined) out += g[r][c]; }
      return out;
    }
    for (c = 0; c < cols; c++) {
      for (r = 0; r < rows; r++) {
        idx = r * cols + c;
        if (idx < n) out += s.charAt(idx);
      }
    }
    return out;
  }

  /* ---------------- 6. hill ---------------- */
  function c11HillDet(m, n) {
    var i, j, k, sub, d = 0;
    if (n === 1) return m[0][0];
    if (n === 2) return m[0][0] * m[1][1] - m[0][1] * m[1][0];
    for (j = 0; j < n; j++) {
      sub = [];
      for (i = 1; i < n; i++) {
        sub.push([]);
        for (k = 0; k < n; k++) { if (k !== j) sub[i - 1].push(m[i][k]); }
      }
      d += (j % 2 === 0 ? 1 : -1) * m[0][j] * c11HillDet(sub, n - 1);
    }
    return d;
  }
  function c11HillCof(m, n, r, c) {
    var sub = [], i, j;
    for (i = 0; i < n; i++) {
      if (i === r) continue;
      sub.push([]);
      for (j = 0; j < n; j++) { if (j !== c) sub[sub.length - 1].push(m[i][j]); }
    }
    return ((r + c) % 2 === 0 ? 1 : -1) * c11HillDet(sub, n - 1);
  }
  function c11Gcd(a, b) { a = Math.abs(a); b = Math.abs(b); while (b) { var t = b; b = a % b; a = t; } return a; }
  function c11Hill(t, key, dec) {
    var k = c11Letters(key), n, i, j, p, q;
    if (k.length >= 9) n = 3; else n = 2;
    while (k.length < n * n) k += 'A';
    k = k.substr(0, n * n);
    var m = [], p2 = 0;
    for (i = 0; i < n; i++) { m.push([]); for (j = 0; j < n; j++) m[i].push(k.charCodeAt(p2++) - 65); }
    var det = c11Mod(c11HillDet(m, n), 26);
    if (c11Gcd(det, 26) !== 1) return '[Hill: matrix not invertible mod 26, det=' + det + ']';
    var w = m;
    if (dec) {
      var invD = 0;
      for (q = 1; q < 26; q++) { if (c11Mod(q * det, 26) === 1) { invD = q; break; } }
      var adj = [];
      for (i = 0; i < n; i++) { adj.push([]); for (j = 0; j < n; j++) adj[i].push(c11Mod(c11HillCof(m, n, j, i) * invD, 26)); }
      w = adj;
    }
    var s = c11Letters(t);
    while (s.length % n) s += 'X';
    var out = '', v;
    for (p = 0; p < s.length; p += n) {
      for (i = 0; i < n; i++) {
        v = 0;
        for (j = 0; j < n; j++) v += w[i][j] * (s.charCodeAt(p + j) - 65);
        out += String.fromCharCode(65 + c11Mod(v, 26));
      }
    }
    return out;
  }

  /* ---------------- 7. autokey ---------------- */
  function c11Autokey(t, key, dec) {
    var k = c11Letters(key), s = c11Up(t), out = '', ki = 0, i, c, p;
    if (!k.length) return s;
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c >= 65 && c <= 90) {
        if (ki < k.length) p = k.charCodeAt(ki) - 65;
        else if (dec) p = out.charCodeAt(out.length - k.length) - 65;
        else p = s.charCodeAt(i - k.length) - 65;
        if (dec) out += String.fromCharCode(65 + c11Mod(c - 65 - p, 26));
        else out += String.fromCharCode(65 + c11Mod(c - 65 + p, 26));
        ki++;
      } else out += s.charAt(i);
    }
    return out;
  }

  /* ---------------- 8. beaufort (reciprocal) ---------------- */
  function c11Beaufort(t, key, dec) {
    var k = c11Letters(key), s = c11Up(t), out = '', ki = 0, i, c;
    if (!k.length) return s;
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c >= 65 && c <= 90) {
        out += String.fromCharCode(65 + c11Mod(k.charCodeAt(ki % k.length) - 65 - (c - 65), 26));
        ki++;
      } else out += s.charAt(i);
    }
    return out;
  }

  /* ---------------- 9. variant beaufort ---------------- */
  function c11VariantBeaufort(t, key, dec) {
    var k = c11Letters(key), s = c11Up(t), out = '', ki = 0, i, c;
    if (!k.length) return s;
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c >= 65 && c <= 90) {
        if (dec) out += String.fromCharCode(65 + c11Mod(c - 65 + (k.charCodeAt(ki % k.length) - 65), 26));
        else out += String.fromCharCode(65 + c11Mod(c - 65 - (k.charCodeAt(ki % k.length) - 65), 26));
        ki++;
      } else out += s.charAt(i);
    }
    return out;
  }

  /* ---------------- 10. porta (reciprocal, 13 rows) ---------------- */
  function c11Porta(t, key, dec) {
    var k = c11Letters(key), s = c11Up(t), out = '', ki = 0, i, c, row, p;
    if (!k.length) return s;
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c >= 65 && c <= 90) {
        row = Math.floor((k.charCodeAt(ki % k.length) - 65) / 2);
        p = c - 65;
        if (p < 13) p = c11Mod(p + row, 13) + 13;
        else p = c11Mod(p - 13 - row, 13);
        out += String.fromCharCode(65 + p);
        ki++;
      } else out += s.charAt(i);
    }
    return out;
  }

  /* ---------------- 11. gronsfeld ---------------- */
  function c11Gronsfeld(t, key, dec) {
    var k = c11Digits(key), s = c11Up(t), out = '', ki = 0, i, c;
    if (!k.length) return s;
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c >= 65 && c <= 90) {
        if (dec) out += String.fromCharCode(65 + c11Mod(c - 65 - parseInt(k.charAt(ki % k.length), 10), 26));
        else out += String.fromCharCode(65 + c11Mod(c - 65 + parseInt(k.charAt(ki % k.length), 10), 26));
        ki++;
      } else out += s.charAt(i);
    }
    return out;
  }

  /* ---------------- 12. four square ---------------- */
  function c11FourSquare(t, key, dec, opt) {
    var o = c11Opt(opt), tr = c11Sq5(key), bl = c11Sq5(c11Str(o.key2) || key), std = c11SqStd();
    var s = c11Letters(t).replace(/J/g, 'I'), out = '', i, a, b, r1, c1, r2, c2;
    var pairs = dec ? c11Pairs(s) : c11Digraphs(s);
    for (i = 0; i < pairs.length; i++) {
      if (dec) {
        a = tr.indexOf(pairs[i].charAt(0)); b = bl.indexOf(pairs[i].charAt(1));
        r1 = Math.floor(a / 5); c1 = a % 5; r2 = Math.floor(b / 5); c2 = b % 5;
        out += std.charAt(r1 * 5 + c2) + std.charAt(r2 * 5 + c1);
      } else {
        a = std.indexOf(pairs[i].charAt(0)); b = std.indexOf(pairs[i].charAt(1));
        r1 = Math.floor(a / 5); c1 = a % 5; r2 = Math.floor(b / 5); c2 = b % 5;
        out += tr.charAt(r1 * 5 + c2) + bl.charAt(r2 * 5 + c1);
      }
    }
    return out;
  }

  /* ---------------- 13. two square (horizontal) ---------------- */
  function c11TwoSquare(t, key, dec, opt) {
    var o = c11Opt(opt), L = c11Sq5(key), R = c11Sq5(c11Str(o.key2) || key);
    var s = c11Letters(t).replace(/J/g, 'I'), out = '', i, a, b, r1, c1, r2, c2;
    var pairs = dec ? c11Pairs(s) : c11Digraphs(s);
    for (i = 0; i < pairs.length; i++) {
      a = L.indexOf(pairs[i].charAt(0)); b = R.indexOf(pairs[i].charAt(1));
      r1 = Math.floor(a / 5); c1 = a % 5; r2 = Math.floor(b / 5); c2 = b % 5;
      if (r1 === r2) out += pairs[i].charAt(0) + pairs[i].charAt(1);
      else out += L.charAt(r1 * 5 + c2) + R.charAt(r2 * 5 + c1);
    }
    return out;
  }

  /* ---------------- 14/15. adfgvx / adfgx ---------------- */
  function c11AdfgxCore(t, key, dec, opt, six) {
    var o = c11Opt(opt), sym = six ? 'ADFGVX' : 'ADFGX', side = six ? 6 : 5;
    var sq = six ? c11Sq6(key) : c11Sq5(key);
    var tkey = c11Str(o.key2) || 'KEY', order = c11KeyOrder(tkey), nc = order.length || 1;
    var i, r, c, p, frac = '', out = '', s;
    if (dec) {
      s = c11Up(t).replace(new RegExp('[^' + sym + ']', 'g'), '');
      var nrD = Math.ceil(s.length / nc), lens = c11ColLens(s.length, nc);
      var g = c11FillCols(s, nrD, order, lens), stream = '';
      for (r = 0; r < nrD; r++) { for (c = 0; c < nc; c++) stream += g[r][c] || ''; }
      for (i = 0; i + 1 < stream.length; i += 2) {
        r = sym.indexOf(stream.charAt(i)); c = sym.indexOf(stream.charAt(i + 1));
        if (r < 0 || c < 0) continue;
        out += sq.charAt(r * side + c);
      }
      return out;
    }
    s = six ? c11Up(t).replace(/[^A-Z0-9]/g, '') : c11Letters(t).replace(/J/g, 'I');
    for (i = 0; i < s.length; i++) {
      p = sq.indexOf(s.charAt(i));
      if (p < 0) continue;
      frac += sym.charAt(Math.floor(p / side)) + sym.charAt(p % side);
    }
    var nr = Math.ceil(frac.length / nc), grid = [];
    p = 0;
    for (r = 0; r < nr; r++) {
      grid.push([]);
      for (c = 0; c < nc; c++) grid[r][c] = p < frac.length ? frac.charAt(p++) : '';
    }
    return c11ReadCols(grid, nr, order);
  }
  function c11Adfgvx(t, key, dec, opt) { return c11AdfgxCore(t, key, dec, opt, true); }
  function c11Adfgx(t, key, dec, opt) { return c11AdfgxCore(t, key, dec, opt, false); }

  /* ---------------- 16. trifid ---------------- */
  function c11TrifidTable(key) {
    var k = c11Dedup(c11Letters(key)), r = '', i, c;
    for (i = 0; i < 26; i++) { c = String.fromCharCode(65 + i); if (k.indexOf(c) < 0) r += c; }
    return k + '#' + r;
  }
  function c11Trifid(t, key, dec, opt) {
    var o = c11Opt(opt), period = c11Int(o.period, 5);
    if (period < 1) period = 5;
    var T = c11TrifidTable(key), pos = {}, i;
    for (i = 0; i < 27; i++) pos[T.charAt(i)] = i;
    var s = dec ? c11Up(t).replace(/[^A-Z#]/g, '') : c11Up(t).replace(/[^A-Z]/g, '');
    var out = '', b, n, seq, v;
    for (b = 0; b < s.length; b += period) {
      n = Math.min(period, s.length - b);
      if (n < 1) continue;
      seq = '';
      for (i = 0; i < n; i++) {
        v = pos[s.charAt(b + i)];
        if (v === undefined) continue;
        seq += String(Math.floor(v / 9)) + String(Math.floor((v % 9) / 3)) + String(v % 3);
      }
      if (dec) {
        /* seq is the raw digit stream: layers then rows then cols */
        for (i = 0; i < n; i++) {
          v = parseInt(seq.charAt(i), 10) * 9 +
            parseInt(seq.charAt(n + i), 10) * 3 +
            parseInt(seq.charAt(2 * n + i), 10);
          out += T.charAt(v);
        }
      } else {
        var lay = '', row = '', col = '';
        for (i = 0; i < n; i++) lay += seq.charAt(i * 3);
        for (i = 0; i < n; i++) row += seq.charAt(i * 3 + 1);
        for (i = 0; i < n; i++) col += seq.charAt(i * 3 + 2);
        seq = lay + row + col;
        for (i = 0; i + 2 < seq.length; i += 3) {
          v = parseInt(seq.charAt(i), 10) * 9 + parseInt(seq.charAt(i + 1), 10) * 3 + parseInt(seq.charAt(i + 2), 10);
          out += T.charAt(v);
        }
      }
    }
    return out;
  }

  /* ---------------- 17. nihilist ---------------- */
  function c11Nihilist(t, key, dec, opt) {
    var o = c11Opt(opt), sq = c11Sq5(key), kk = c11Letters(c11Str(o.key2) || key), i, p, kv = [], out = '';
    for (i = 0; i < kk.length; i++) {
      p = sq.indexOf(kk.charAt(i).replace(/J/g, 'I'));
      kv.push((Math.floor(p / 5) + 1) * 10 + (p % 5 + 1));
    }
    if (!kv.length) kv.push(0);
    if (dec) {
      var arr = c11Str(t).match(/\d+/g) || [];
      for (i = 0; i < arr.length; i++) {
        p = parseInt(arr[i], 10) - kv[i % kv.length] - 11;
        out += sq.charAt(Math.floor(p / 10) * 5 + (p % 10));
      }
      return out;
    }
    var s = c11Letters(t).replace(/J/g, 'I');
    for (i = 0; i < s.length; i++) {
      p = sq.indexOf(s.charAt(i));
      if (p < 0) continue;
      out += (out.length ? ' ' : '') + ((Math.floor(p / 5) + 1) * 10 + (p % 5 + 1) + kv[i % kv.length]);
    }
    return out;
  }

  /* ---------------- 18. amsco ---------------- */
  function c11Amsco(t, key, dec) {
    var order = c11KeyOrder(key), nc = order.length;
    if (!nc) return c11Letters(t);
    var s = c11Letters(t), n = s.length, lens = [], i, j, r, p = 0, take, nr = 0;
    function chunkOf(col) { return (col % 2 === 0) ? 1 : 2; }
    for (i = 0; i < nc; i++) lens.push(0);
    while (p < n) {
      nr++;
      for (j = 0; j < nc && p < n; j++) {
        take = Math.min(chunkOf(j), n - p);
        lens[j] += take; p += take;
      }
    }
    var grid = [], out = '';
    for (r = 0; r < nr; r++) { grid.push([]); for (j = 0; j < nc; j++) grid[r][j] = ''; }
    if (dec) {
      var q = 0, tmp, rr, pos2;
      for (i = 0; i < order.length; i++) {
        j = order[i];
        tmp = s.substr(q, lens[j]); q += lens[j];
        pos2 = 0; rr = 0;
        while (pos2 < tmp.length && rr < nr) {
          take = Math.min(chunkOf(j), tmp.length - pos2);
          grid[rr][j] = tmp.substr(pos2, take); pos2 += take; rr++;
        }
      }
      for (r = 0; r < nr; r++) { for (j = 0; j < nc; j++) out += grid[r][j]; }
      return out;
    }
    p = 0;
    for (r = 0; r < nr; r++) {
      for (j = 0; j < nc; j++) {
        take = Math.min(chunkOf(j), n - p);
        if (take <= 0) continue;
        grid[r][j] = s.substr(p, take); p += take;
      }
    }
    for (i = 0; i < order.length; i++) {
      for (r = 0; r < nr; r++) out += grid[r][order[i]];
    }
    return out;
  }

  /* ---------------- 19. route transposition ---------------- */
  function c11RoutePath(nr, nc, route) {
    var path = [], i, r, c, top = 0, bot = nr - 1, left = 0, right = nc - 1;
    if (route === 'snake') {
      for (r = 0; r < nr; r++) {
        if (r % 2 === 0) { for (c = 0; c < nc; c++) path.push(r * nc + c); }
        else { for (c = nc - 1; c >= 0; c--) path.push(r * nc + c); }
      }
      return path;
    }
    if (route === 'columns') {
      for (c = 0; c < nc; c++) { for (r = 0; r < nr; r++) path.push(r * nc + c); }
      return path;
    }
    var ccw = (route === 'spiral-ccw');
    while (top <= bot && left <= right) {
      if (ccw) {
        for (i = top; i <= bot; i++) path.push(i * nc + left);
        if (top < bot) {
          for (i = left + 1; i <= right; i++) path.push(bot * nc + i);
          if (left < right) {
            for (i = bot - 1; i >= top; i--) path.push(i * nc + right);
            for (i = right - 1; i > left; i--) path.push(top * nc + i);
          }
        } else {
          for (i = left + 1; i <= right; i++) path.push(top * nc + i);
        }
      } else {
        for (i = left; i <= right; i++) path.push(top * nc + i);
        if (top < bot) {
          for (i = top + 1; i <= bot; i++) path.push(i * nc + right);
          if (left < right) {
            for (i = right - 1; i >= left; i--) path.push(bot * nc + i);
            for (i = bot - 1; i > top; i--) path.push(i * nc + left);
          }
        }
      }
      top++; bot--; left++; right--;
    }
    return path;
  }
  function c11Route(t, key, dec, opt) {
    var o = c11Opt(opt), nc = c11Int(o.cols, c11Int(c11Digits(key), 6));
    if (nc < 1) nc = 1;
    var route = c11Str(o.route) || 'spiral';
    var s = c11Letters(t), nr = Math.ceil(s.length / nc), total = nr * nc, i;
    var buf = s;
    while (buf.length < total) buf += 'X';
    var path = c11RoutePath(nr, nc, route), out = '', g = [];
    if (dec) {
      for (i = 0; i < total; i++) g.push('');
      for (i = 0; i < path.length && i < buf.length; i++) g[path[i]] = buf.charAt(i);
      return g.join('');
    }
    for (i = 0; i < path.length; i++) out += buf.charAt(path[i]);
    return out;
  }

  /* ---------------- 20. double transposition ---------------- */
  function c11DoubleTransposition(t, key, dec, opt) {
    var o = c11Opt(opt), k2 = c11Str(o.key2) || key;
    if (dec) return c11Columnar(c11Columnar(t, k2, true), key, true);
    return c11Columnar(c11Columnar(t, key, false), k2, false);
  }

  /* ---------------- 21. ragbaby ---------------- */
  function c11Ragbaby(t, key, dec) {
    var tab = c11Mix(c11Letters(key).replace(/J/g, 'I'), 'ABCDEFGHIKLMNOPQRSTUVWXYZ');
    var s = c11Up(t), out = '', n = 0, i, c, p;
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c >= 65 && c <= 90) {
        p = tab.indexOf(s.charAt(i).replace(/J/g, 'I'));
        if (p < 0) { out += s.charAt(i); continue; }
        n++;
        out += tab.charAt(c11Mod(p + (dec ? -n : n), 25));
      } else {
        out += s.charAt(i);
        if (s.charAt(i) === ' ' || s.charAt(i) === '\t' || s.charAt(i) === '\n') n = 0;
      }
    }
    return out;
  }

  /* ---------------- 22. trithemius / progressive key ---------------- */
  function c11Tritheme(t, key, dec, opt) {
    var o = c11Opt(opt), start = 1;
    if (o.start !== undefined && o.start !== null && o.start !== '') start = c11Int(o.start, 1);
    var s = c11Up(t), out = '', i = 0, j, c;
    for (j = 0; j < s.length; j++) {
      c = s.charCodeAt(j);
      if (c >= 65 && c <= 90) {
        out += String.fromCharCode(65 + c11Mod(c - 65 + (dec ? -(start + i) : (start + i)), 26));
        i++;
      } else out += s.charAt(j);
    }
    return out;
  }

  /* ---------------- 23. vernam one time pad ---------------- */
  function c11Vernam(t, key, dec, opt) {
    var o = c11Opt(opt), i, v;
    if (o.mode === 'byte') {
      var ks = c11Str(key), kb = [];
      for (i = 0; i < ks.length; i++) kb.push(ks.charCodeAt(i) & 255);
      if (!kb.length) kb.push(0);
      if (dec) {
        var hex = c11Str(t).replace(/[^0-9a-fA-F]/g, ''), arr = [], s2 = '';
        if (hex.length % 2) hex = hex.slice(0, hex.length - 1);
        for (i = 0; i < hex.length; i += 2) arr.push(parseInt(hex.substr(i, 2), 16) ^ kb[(i / 2) % kb.length]);
        for (i = 0; i < arr.length; i++) s2 += String.fromCharCode(arr[i]);
        try { return decodeURIComponent(escape(s2)); } catch (e) { return s2; }
      }
      var raw = c11Str(t), esc, o2 = '';
      try { esc = unescape(encodeURIComponent(raw)); } catch (e2) { esc = raw; }
      for (i = 0; i < esc.length; i++) {
        v = (esc.charCodeAt(i) & 255) ^ kb[i % kb.length];
        o2 += (v < 16 ? '0' : '') + v.toString(16);
      }
      return o2.toUpperCase();
    }
    var k = c11Letters(key), s = c11Up(t), out = '', ki = 0, c2, x;
    if (!k.length) return s;
    /* 5 bit XOR: result lives in A..Z plus [ \ ] ^ _ (codes 65..96) */
    for (i = 0; i < s.length; i++) {
      c2 = s.charCodeAt(i);
      if (c2 >= 65 && c2 <= 96) {
        x = (c2 - 65) ^ (k.charCodeAt(ki % k.length) - 65);
        out += String.fromCharCode(65 + (x & 31));
        ki++;
      } else out += s.charAt(i);
    }
    return out;
  }

  /* ---------------- 24. homophonic substitution ---------------- */
  function c11Homophonic(t, key, dec, opt) {
    var freq = 'ETAOINSHRDLCUMWFGYPBVKJXQZ';
    var cnt = [12, 9, 8, 8, 7, 7, 6, 6, 6, 4, 4, 3, 3, 3, 2, 2, 2, 2, 2, 2, 1, 1, 1, 1, 1, 1];
    var slots = [], i, j, left = [], nums = [], seed, st;
    for (i = 0; i < 26; i++) left.push(cnt[i]);
    var more = true;
    while (more) {
      more = false;
      for (i = 0; i < 26; i++) { if (left[i] > 0) { slots.push(freq.charAt(i)); left[i]--; more = true; } }
    }
    for (i = 0; i < 100; i++) nums.push(i);
    seed = c11Str(key);
    st = 12345;
    for (i = 0; i < seed.length; i++) st = (st * 31 + seed.charCodeAt(i)) % 2147483647;
    for (i = 99; i > 0; i--) {
      st = (st * 1103515245 + 12345) % 2147483647;
      j = st % (i + 1);
      var sw = nums[i]; nums[i] = nums[j]; nums[j] = sw;
    }
    var codeOf = {}, letterOf = {}, cc, cs;
    for (i = 0; i < 100; i++) {
      cc = nums[i]; cs = (cc < 10 ? '0' : '') + String(cc);
      letterOf[cs] = slots[i];
      if (!codeOf[slots[i]]) codeOf[slots[i]] = [];
      codeOf[slots[i]].push(cs);
    }
    if (dec) {
      var arr2 = c11Str(t).match(/\d\d/g) || [], outD = '';
      for (i = 0; i < arr2.length; i++) outD += letterOf[arr2[i]] || '';
      return outD;
    }
    var s = c11Letters(t), out2 = '';
    for (i = 0; i < s.length; i++) {
      var set = codeOf[s.charAt(i)];
      if (!set) continue;
      st = (st * 1103515245 + 12345) % 2147483647;
      out2 += (out2.length ? ' ' : '') + set[st % set.length];
    }
    return out2;
  }

  /* ---------------- 25. straddling checkerboard ---------------- */
  function c11Straddling(t, key, dec, opt) {
    var o = c11Opt(opt), i, ch, top, r2, r3, p2, p3;
    if (c11Letters(key).length) {
      var mk = c11Mix(c11Letters(key), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
      top = mk.substr(0, 8);
      r2 = mk.substr(8, 10);
      r3 = mk.substr(18, 8) + '.#';
    } else {
      top = c11Str(o.top) || 'ATONESIR';
      r2 = c11Str(o.r2) || 'BCDFGHJKLM';
      r3 = c11Str(o.r3) || 'PQUVWXYZ.#';
    }
    p2 = c11Int(o.p2, 2); p3 = c11Int(o.p3, 6);
    var topCol = [], topAt = {};
    for (i = 0; i < 10; i++) { if (i !== p2 && i !== p3) topCol.push(i); }
    for (i = 0; i < topCol.length; i++) topAt[top.charAt(i)] = topCol[i];
    if (dec) {
      var s = c11Digits(t), out = '', j;
      i = 0;
      while (i < s.length) {
        j = parseInt(s.charAt(i), 10);
        if (j === p2) { out += r2.charAt(parseInt(s.charAt(i + 1), 10)); i += 2; }
        else if (j === p3) { out += r3.charAt(parseInt(s.charAt(i + 1), 10)); i += 2; }
        else {
          ch = top.charAt(topCol.indexOf(j));
          out += (ch === undefined || ch === '' ? '?' : ch);
          i += 1;
        }
      }
      return out.replace(/\./g, ' ');
    }
    var src = c11Up(t).replace(/[^A-Z\s]/g, '').replace(/\s+/g, '.'), out2 = '';
    for (i = 0; i < src.length; i++) {
      ch = src.charAt(i);
      if (topAt[ch] !== undefined) out2 += String(topAt[ch]);
      else if (r2.indexOf(ch) >= 0) out2 += String(p2) + String(r2.indexOf(ch));
      else if (r3.indexOf(ch) >= 0) out2 += String(p3) + String(r3.indexOf(ch));
    }
    return out2;
  }

  /* ---------------- 26/27. morse helpers, morbit & pollux ---------------- */
  var C11MORSE = {
    A: '.-', B: '-...', C: '-.-.', D: '-..', E: '.', F: '..-.', G: '--.', H: '....',
    I: '..', J: '.---', K: '-.-', L: '.-..', M: '--', N: '-.', O: '---', P: '.--.',
    Q: '--.-', R: '.-.', S: '...', T: '-', U: '..-', V: '...-', W: '.--', X: '-..-',
    Y: '-.--', Z: '--..', '0': '-----', '1': '.----', '2': '..---', '3': '...--',
    '4': '....-', '5': '.....', '6': '-....', '7': '--...', '8': '---..', '9': '----.'
  };
  function c11Unmorse(code) {
    for (var k in C11MORSE) { if (C11MORSE[k] === code) return k; }
    return '';
  }
  /* text -> morse stream: letters joined by x, words by xx */
  function c11MorseStream(s) {
    var words = c11Up(s).replace(/[^A-Z0-9 ]/g, ' ').split(/\s+/), w, i, out = '', first = true;
    for (w = 0; w < words.length; w++) {
      if (!words[w].length) continue;
      if (!first) out += 'xx';
      first = false;
      for (i = 0; i < words[w].length; i++) {
        if (i) out += 'x';
        out += C11MORSE[words[w].charAt(i)] || '';
      }
    }
    return out;
  }
  function c11UnmorseStream(str) {
    var parts = str.split('x'), out = '', i, m, prevEmpty = false, started = false;
    for (i = 0; i < parts.length; i++) {
      if (parts[i] === '') {
        if (started) prevEmpty = true;
        continue;
      }
      m = c11Unmorse(parts[i]);
      if (prevEmpty) out += ' ';
      out += m;
      prevEmpty = false; started = true;
    }
    return out;
  }
  function c11Morbit(t, key, dec, opt) {
    var pairs = ['..', '.-', '.x', '-.', '--', '-x', 'x.', 'x-', 'xx'];
    var k = c11Digits(key), i, j;
    if (k.length < 9) k = (k + '123456789').substr(0, 9);
    k = k.substr(0, 9);
    var out = '', str, idx;
    if (dec) {
      str = '';
      for (i = 0; i < t.length; i++) {
        j = k.indexOf(t.charAt(i));
        if (j >= 0) str += pairs[j];
      }
      return c11UnmorseStream(str);
    }
    str = c11MorseStream(t);
    if (str.length % 2) str += 'x';
    for (i = 0; i + 1 < str.length; i += 2) {
      idx = -1;
      for (j = 0; j < 9; j++) { if (pairs[j] === str.substr(i, 2)) idx = j; }
      out += idx >= 0 ? k.charAt(idx) : '';
    }
    return out;
  }
  /* Pollux 数字→莫尔斯符号映射表：10 位，必含 点/划/分隔符 三种符号，
     由密钥驱动对固定多重集 '....---xxx' 洗牌，保证编解码严格可逆 */
  function c11PolluxMap(key) {
    var arr = ('....---xxx').split(''), k = c11Letters(key) || 'POLLUX', i, j, tmp, st = 7;
    for (i = 0; i < k.length; i++) st = (st * 31 + k.charCodeAt(i)) % 2147483647;
    for (i = arr.length - 1; i > 0; i--) {
      st = (st * 1103515245 + 12345) % 2147483647;
      j = st % (i + 1);
      tmp = arr[i]; arr[i] = arr[j]; arr[j] = tmp;
    }
    return arr.join('');
  }
  function c11Pollux(t, key, dec, opt) {
    var map = c11PolluxMap(key), i, j, st = 987654321;
    if (dec) {
      var str = '', d;
      for (i = 0; i < t.length; i++) {
        d = parseInt(c11Str(t).charAt(i), 10);
        if (isNaN(d)) continue;
        str += map.charAt(d);
      }
      return c11UnmorseStream(str);
    }
    var ms = c11MorseStream(t) + 'x', pool = [], out = '';
    for (i = 0; i < 3; i++) {
      pool.push([]);
      for (j = 0; j < 10; j++) { if (map.charAt(j) === '.-x'.charAt(i)) pool[i].push(j); }
      if (!pool[i].length) pool[i] = [i];
    }
    for (i = 0; i < ms.length; i++) {
      var ci = '.-x'.indexOf(ms.charAt(i));
      st = (st * 1103515245 + 12345) % 2147483647;
      out += String(pool[ci][st % pool[ci].length]);
    }
    return out;
  }

  /* ---------------- 28. digrafid ---------------- */
  function c11Digrafid(t, key, dec, opt) {
    var o = c11Opt(opt), period = c11Int(o.period, 3);
    if (period < 1) period = 3;
    var A1 = c11Mix(c11Letters(key), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ#');
    var A2 = c11Mix(c11Letters(c11Str(o.key2) || key), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ#');
    var i, j, s, blocks = [], out = '';
    if (dec) s = c11Up(t).replace(/[^A-Z#]/g, '');
    else s = c11Up(t).replace(/[^A-Z]/g, '');
    if (s.length % 2) s += '#';
    for (i = 0; i < s.length; i += 2) blocks.push(s.substr(i, 2));
    function numOf(dg) {
      var p1 = A1.indexOf(dg.charAt(0)), p2 = A2.indexOf(dg.charAt(1));
      if (p1 < 0 || p2 < 0) return [1, 1, 1];
      return [(p1 % 9) + 1, Math.floor(p1 / 9) * 3 + (p2 % 3) + 1, Math.floor(p2 / 3) + 1];
    }
    function dgOf(n) {
      var r1 = Math.floor((n[1] - 1) / 3), c2 = (n[1] - 1) % 3;
      return A1.charAt(r1 * 9 + (n[0] - 1)) + A2.charAt((n[2] - 1) * 3 + c2);
    }
    for (i = 0; i < blocks.length; i += period) {
      var grp = blocks.slice(i, i + period), nums = [], ns = [], p;
      for (j = 0; j < grp.length; j++) nums.push(numOf(grp[j]));
      if (dec) {
        /* the digit stream is the digits of the numbers read back to back */
        for (j = 0; j < nums.length; j++) {
          ns.push(nums[j][0]); ns.push(nums[j][1]); ns.push(nums[j][2]);
        }
        for (j = 0; j < grp.length; j++) {
          out += dgOf([ns[j], ns[grp.length + j], ns[2 * grp.length + j]]);
        }
      } else {
        for (j = 0; j < nums.length; j++) ns.push(nums[j][0]);
        for (j = 0; j < nums.length; j++) ns.push(nums[j][1]);
        for (j = 0; j < nums.length; j++) ns.push(nums[j][2]);
        for (j = 0; j + 2 < ns.length; j += 3) out += dgOf([ns[j], ns[j + 1], ns[j + 2]]);
      }
    }
    return out.replace(/#/g, '');
  }

  /* ---------------- 29. chaocipher ---------------- */
  function c11ChaoPermute(arr, idx, right) {
    var t = arr.slice(idx).concat(arr.slice(0, idx)), store, j;
    if (!right) {
      store = t[1];
      for (j = 2; j <= 13; j++) t[j - 1] = t[j];
      t[13] = store;
    } else {
      store = t[0];
      for (j = 1; j <= 25; j++) t[j - 1] = t[j];
      t[25] = store;
      store = t[2];
      for (j = 3; j <= 13; j++) t[j - 1] = t[j];
      t[13] = store;
    }
    return t;
  }
  function c11Chaocipher(t, key, dec, opt) {
    var o = c11Opt(opt), i, ci, idx, out = '', s = c11Up(t);
    var Ls = c11Letters(key), Rs = c11Letters(c11Str(o.key2));
    if (Ls.length < 26) Ls = Ls.length ? c11Mix(Ls, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') : 'HXUCZVAMDSLKPEFJRIGTWOBNYQ';
    if (Rs.length < 26) Rs = Rs.length ? c11Mix(Rs, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') : 'PTLNBQDEOYSFAVZKGJRIHWXUMC';
    var L = Ls.split(''), R = Rs.split('');
    for (i = 0; i < s.length; i++) {
      ci = s.charCodeAt(i);
      if (ci < 65 || ci > 90) continue;
      if (dec) { idx = L.indexOf(s.charAt(i)); out += R[idx]; }
      else { idx = R.indexOf(s.charAt(i)); out += L[idx]; }
      idx = c11Mod(idx, 26);
      L = c11ChaoPermute(L, idx, false);
      R = c11ChaoPermute(R, idx, true);
    }
    return out;
  }

  /* ---------------- 30. alberti disc ---------------- */
  function c11Alberti(t, key, dec) {
    var outer = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    var inner = c11Mix(c11Letters(key), outer);
    var s = c11Up(t), out = '', shift = 0, i, c, p;
    for (i = 0; i < s.length; i++) {
      c = s.charAt(i);
      if (c >= '0' && c <= '9') { shift = parseInt(c, 10); out += c; continue; }
      if (c >= 'A' && c <= 'Z') {
        if (dec) out += outer.charAt(c11Mod(inner.indexOf(c) - shift, 26));
        else out += inner.charAt(c11Mod(outer.indexOf(c) + shift, 26));
        continue;
      }
      out += c;
    }
    return out;
  }

  /* ---------------- 31. keyed substitution ---------------- */
  function c11KeyedSub(t, key, dec) {
    var k = c11Letters(key), tab;
    if (k.length === 26 && c11Dedup(k).length === 26) tab = k;
    else tab = c11Mix(k, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    var s = c11Up(t), out = '', i, c, p;
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c >= 65 && c <= 90) {
        if (dec) { p = tab.indexOf(s.charAt(i)); out += String.fromCharCode(65 + (p < 0 ? 0 : p)); }
        else out += tab.charAt(c - 65);
      } else out += s.charAt(i);
    }
    return out;
  }

  /* ---------------- 32. tap code ---------------- */
  function c11TapCode(t, key, dec) {
    var tab = 'ABCDEFGHIKLMNOPQRSTUVWXYZ', i, j, r, c;
    function dots(n) { var s = ''; for (var q = 0; q < n; q++) s += '.'; return s; }
    if (dec) {
      var ws = c11Str(t).split('/'), ow = [], w2, lts, pr2, o2;
      for (w2 = 0; w2 < ws.length; w2++) {
        lts = ws[w2].replace(/[^.\s]/g, '').split(/\s{2,}/);
        o2 = '';
        for (i = 0; i < lts.length; i++) {
          pr2 = lts[i].replace(/^\s+|\s+$/g, '').split(/\s+/);
          if (pr2.length < 2 || !pr2[0] || !pr2[1]) continue;
          r = pr2[0].length - 1; c = pr2[1].length - 1;
          if (r >= 0 && c >= 0 && r < 5 && c < 5) o2 += tab.charAt(r * 5 + c);
        }
        ow.push(o2);
      }
      return ow.join(' ');
    }
    var words = c11Up(t).replace(/J/g, 'I').split(/[^A-Z]+/), res = [], cur, p;
    for (i = 0; i < words.length; i++) {
      if (!words[i].length) continue;
      cur = [];
      for (j = 0; j < words[i].length; j++) {
        p = tab.indexOf(words[i].charAt(j));
        if (p < 0) continue;
        cur.push(dots(Math.floor(p / 5) + 1) + ' ' + dots(p % 5 + 1));
      }
      res.push(cur.join('  '));
    }
    return res.join(' / ');
  }

  /* ---------------- 33. pigpen ---------------- */
  function c11Pigpen(t, key, dec, opt) {
    var o = c11Opt(opt), plain = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', i, p;
    var sym = c11Str(o.symbols);
    if (sym.length < 26) sym = '┌┬┐├┼┤└┴┘╔╦╗╠╬╣╚╩╝◤◥◣◢◸◹◺◿';
    var tab = sym.split('');
    if (c11Letters(key).length) {
      var mk = c11Mix(c11Letters(key), plain), mapped = [];
      for (i = 0; i < 26; i++) mapped[mk.charCodeAt(i) - 65] = sym.charAt(i);
      tab = mapped;
    }
    var s = c11Up(t), out = '';
    for (i = 0; i < s.length; i++) {
      if (dec) {
        p = tab.indexOf(s.charAt(i));
        out += p >= 0 ? plain.charAt(p) : s.charAt(i);
      } else if (s.charAt(i) >= 'A' && s.charAt(i) <= 'Z') {
        out += tab[s.charCodeAt(i) - 65];
      } else out += s.charAt(i);
    }
    return out;
  }

  /* ---------------- 34. semaphore flags ---------------- */
  function c11Semaphore(t, key, dec) {
    var dirs = ['↓', '↙', '←', '↖', '↑', '↗', '→', '↘'];
    var pairs = [], i, j, k = 0;
    for (i = 0; i < 8 && k < 26; i++) {
      for (j = i + 1; j < 8 && k < 26; j++) { pairs.push([i, j]); k++; }
    }
    var tab = [], letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    for (i = 0; i < 26; i++) tab.push(dirs[pairs[i][0]] + dirs[pairs[i][1]]);
    if (dec) {
      var toks = c11Str(t).split(/[\s\/]+/), out = '', p;
      for (i = 0; i < toks.length; i++) {
        if (!toks[i]) continue;
        p = -1;
        for (j = 0; j < 26; j++) { if (tab[j] === toks[i]) p = j; }
        out += p >= 0 ? letters.charAt(p) : '';
      }
      return out;
    }
    var words = c11Up(t).split(/[^A-Z]+/), res = [], cur;
    for (i = 0; i < words.length; i++) {
      if (!words[i].length) continue;
      cur = [];
      for (j = 0; j < words[i].length; j++) cur.push(tab[words[i].charCodeAt(j) - 65]);
      res.push(cur.join(' '));
    }
    return res.join(' / ');
  }

  /* ================================================================
     chunk_cipherB_fn  --  c11 encoding / modern hash / checksum engines
     (candidates 35..67 of cand_crypto.json)
     ES5 ONLY: var / function, no arrow fn, no let/const, no template
     string, no class.  Never emits nunjucks markers.
     Signatures:
       reversible : engine c11Xxx(t, key, dec, opt)
       one way    : engine c11Xxx(t, opt)   /  c11Xxx(t, key)
     Every helper is prefixed c11B so nothing can collide with the
     helper names already defined in chunk_cipherA_fn.
     ================================================================ */

  /* ---------------- shared helpers ---------------- */
  function c11BStr(s) { return s === null || s === undefined ? '' : String(s); }
  function c11BUp(s) { return c11BStr(s).toUpperCase(); }
  function c11BOpt(o) { return (o && typeof o === 'object') ? o : {}; }
  function c11BInt(v, d) { var n = parseInt(v, 10); return isNaN(n) ? d : n; }

  function c11BLetters(s) {
    var t = c11BUp(s), o = '', i, c;
    for (i = 0; i < t.length; i++) { c = t.charCodeAt(i); if (c >= 65 && c <= 90) o += t.charAt(i); }
    return o;
  }

  /* string -> array of utf8 bytes (handles surrogate pairs) */
  function c11BUtf8(s) {
    var t = c11BStr(s), out = [], i, c, c2;
    for (i = 0; i < t.length; i++) {
      c = t.charCodeAt(i);
      if (c >= 0xD800 && c <= 0xDBFF && i + 1 < t.length) {
        c2 = t.charCodeAt(i + 1);
        if (c2 >= 0xDC00 && c2 <= 0xDFFF) { c = 0x10000 + ((c - 0xD800) << 10) + (c2 - 0xDC00); i++; }
      }
      if (c < 0x80) out.push(c);
      else if (c < 0x800) { out.push(0xC0 | (c >> 6)); out.push(0x80 | (c & 0x3F)); }
      else if (c < 0x10000) { out.push(0xE0 | (c >> 12)); out.push(0x80 | ((c >> 6) & 0x3F)); out.push(0x80 | (c & 0x3F)); }
      else { out.push(0xF0 | (c >> 18)); out.push(0x80 | ((c >> 12) & 0x3F)); out.push(0x80 | ((c >> 6) & 0x3F)); out.push(0x80 | (c & 0x3F)); }
    }
    return out;
  }

  /* array of utf8 bytes -> string */
  function c11BFromUtf8(b) {
    var out = '', i = 0, c, n, cp, k, ok;
    while (i < b.length) {
      c = b[i] & 0xFF;
      if (c < 0x80) { out += String.fromCharCode(c); i += 1; continue; }
      if (c >= 0xC0 && c < 0xE0) { n = 2; cp = c & 0x1F; }
      else if (c >= 0xE0 && c < 0xF0) { n = 3; cp = c & 0x0F; }
      else if (c >= 0xF0 && c < 0xF8) { n = 4; cp = c & 0x07; }
      else { out += String.fromCharCode(c); i += 1; continue; }
      ok = (i + n) <= b.length;
      for (k = 1; k < n; k++) { if (!ok) break; cp = (cp << 6) | (b[i + k] & 0x3F); }
      i += n;
      if (cp > 0xFFFF) {
        cp -= 0x10000;
        out += String.fromCharCode(0xD800 + (cp >> 10)) + String.fromCharCode(0xDC00 + (cp & 0x3FF));
      } else out += String.fromCharCode(cp);
    }
    return out;
  }

  /* string -> utf16le bytes (NTLM) */
  function c11BUtf16le(s) {
    var t = c11BStr(s), out = [], i, c;
    for (i = 0; i < t.length; i++) {
      c = t.charCodeAt(i);
      out.push(c & 0xFF); out.push((c >> 8) & 0xFF);
    }
    return out;
  }

  function c11BHex2(v) { var s = (v & 0xFF).toString(16); return s.length < 2 ? '0' + s : s; }
  function c11BHex(b) { var o = '', i; for (i = 0; i < b.length; i++) o += c11BHex2(b[i]); return o; }
  function c11BHexUpper(b) { return c11BHex(b).toUpperCase(); }

  function c11BUnhex(s) {
    var t = c11BStr(s).replace(/[^0-9a-fA-F]/g, ''), out = [], i;
    if (t.length % 2) t = '0' + t;
    for (i = 0; i < t.length; i += 2) out.push(parseInt(t.substr(i, 2), 16));
    return out;
  }

  function c11BRotl(x, n) { return ((x << n) | (x >>> (32 - n))) >>> 0; }
  function c11BRotr(x, n) { return ((x >>> n) | (x << (32 - n))) >>> 0; }

  function c11BBe32(b, i) { return ((b[i] << 24) | (b[i + 1] << 16) | (b[i + 2] << 8) | b[i + 3]) >>> 0; }
  function c11BLe32(b, i) { return (b[i] | (b[i + 1] << 8) | (b[i + 2] << 16) | (b[i + 3] << 24)) >>> 0; }

  var C11B_B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
  function c11BB64e(b) {
    var out = '', i, n1, n2, n3;
    for (i = 0; i < b.length; i += 3) {
      n1 = b[i] & 0xFF; n2 = (i + 1 < b.length) ? (b[i + 1] & 0xFF) : 0; n3 = (i + 2 < b.length) ? (b[i + 2] & 0xFF) : 0;
      out += C11B_B64.charAt(n1 >> 2);
      out += C11B_B64.charAt(((n1 & 3) << 4) | (n2 >> 4));
      out += (i + 1 < b.length) ? C11B_B64.charAt(((n2 & 15) << 2) | (n3 >> 6)) : '=';
      out += (i + 2 < b.length) ? C11B_B64.charAt(n3 & 63) : '=';
    }
    return out;
  }
  function c11BB64d(s) {
    var t = c11BStr(s).replace(/[^A-Za-z0-9+/=]/g, ''), out = [], i, c, v = 0, vb = 0;
    for (i = 0; i < t.length; i++) {
      c = t.charAt(i);
      if (c === '=') break;
      v = (v << 6) | C11B_B64.indexOf(c);
      vb += 6;
      if (vb >= 8) { vb -= 8; out.push((v >> vb) & 0xFF); }
    }
    return out;
  }

  /* 5 bit grouping helper: bytes -> array of 5 bit values (msb first) */
  function c11BBits5(b) {
    var out = [], acc = 0, nb = 0, i;
    for (i = 0; i < b.length; i++) {
      acc = (acc << 8) | (b[i] & 0xFF); nb += 8;
      while (nb >= 5) { nb -= 5; out.push((acc >> nb) & 31); }
    }
    if (nb > 0) out.push((acc << (5 - nb)) & 31);
    return out;
  }
  /* 5 bit values -> bytes (msb first), trailing bits dropped */
  function c11BUnbits5(v, nbytes) {
    var out = [], acc = 0, nb = 0, i;
    for (i = 0; i < v.length; i++) {
      acc = (acc << 5) | (v[i] & 31); nb += 5;
      if (nb >= 8) { nb -= 8; out.push((acc >> nb) & 0xFF); }
    }
    if (nbytes && out.length > nbytes) out = out.slice(0, nbytes);
    return out;
  }

  /* arbitrary precision base conversion helpers (ES5, no BigInt) --------- */
  /* big endian digit array (base 256) -> {q: quotient, r: remainder} */
  function c11BDivSmall(d, m) {
    var q = [], rem = 0, i, cur;
    for (i = 0; i < d.length; i++) {
      cur = rem * 256 + d[i];
      q.push(Math.floor(cur / m));
      rem = cur % m;
    }
    while (q.length && q[0] === 0) q.shift();
    return { q: q, r: rem };
  }
  function c11BMulAddSmall(d, m, add) {
    var out = d.slice(), i, cur, carry = add;
    for (i = 0; i < out.length; i++) {
      cur = out[i] * m + carry;
      out[i] = cur & 0xFF;
      carry = cur >>> 8;
    }
    while (carry > 0) { out.push(carry & 0xFF); carry = carry >>> 8; }
    return out;
  }

  /* ================================================================
     35. Dancing Men cipher  (rocksalt stick figures, 3 line ascii art)
     ================================================================ */
  var C11B_DM_HEAD = [' o ', '<o ', ' o>', '<o>'];
  var C11B_DM_ARMS = ['\\|/', '/|\\', '-|-', ' | ', '\\| ', ' |/', '/| '];
  var C11B_DM_LEGS = ['/ \\', ' | ', '| \\', '/ |', '/_\\', '\\ /'];

  function c11BDmGlyph(i) {
    var a = i % 4, b = Math.floor(i / 4) % 7;
    return [C11B_DM_HEAD[a], C11B_DM_ARMS[b], C11B_DM_LEGS[(a + b) % 6]];
  }

  function c11BDancingMen(t, key, dec, opt) {
    var s = c11BUp(t), i, c, idx, cells, rows, j, k, map, cell, lines, out, maxw;
    if (dec) {
      lines = c11BStr(t).replace(/\r/g, '').split('\n');
      while (lines.length && lines[lines.length - 1].indexOf('|') < 0 && !/\S/.test(lines[lines.length - 1])) lines.pop();
      if (lines.length < 3) return '';
      map = {};
      for (i = 0; i < 26; i++) {
        c = c11BDmGlyph(i);
        map[c[0] + '\n' + c[1] + '\n' + c[2]] = String.fromCharCode(65 + i);
      }
      maxw = 0;
      for (i = 0; i < lines.length; i++) maxw = Math.max(maxw, lines[i].length);
      out = '';
      for (i = 0; i < maxw; i += 4) {
        cell = '';
        for (j = 0; j < 3; j++) {
          cell += (j ? '\n' : '');
          for (k = lines[j].length; k < i + 3; k++) lines[j] += ' ';
          cell += lines[j].substr(i, 3);
        }
        if (cell === ' | \n | \n | ') out += ' ';
        else if (map[cell]) out += map[cell];
      }
      return out;
    }
    cells = [];
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c === 32) cells.push([' | ', ' | ', ' | ']);
      else if (c >= 65 && c <= 90) cells.push(c11BDmGlyph(c - 65));
    }
    rows = ['', '', ''];
    for (i = 0; i < cells.length; i++) {
      for (j = 0; j < 3; j++) rows[j] += cells[i][j] + ' ';
    }
    for (j = 0; j < 3; j++) rows[j] = rows[j].replace(/\s+$/, '');
    return rows[0] + '\n' + rows[1] + '\n' + rows[2];
  }

  /* ================================================================
     36. Leetspeak converter  (level 1..3, one way + best effort back)
     ================================================================ */
  var C11B_LEET1 = { A: '4', E: '3', I: '1', O: '0' };
  var C11B_LEET2 = { A: '4', E: '3', I: '1', O: '0', S: '5', T: '7', B: '8', G: '6', L: '|', Z: '2' };
  var C11B_LEET_BACK = {
    '4': 'A', '3': 'E', '1': 'I', '0': 'O', '5': 'S', '7': 'T',
    '8': 'B', '6': 'G', '|': 'L', '2': 'Z', '@': 'A', '$': 'S', '!': 'I'
  };

  function c11BLeet(t, key, dec, opt) {
    var o = c11BOpt(opt), lvl = c11BInt(o.level, 1);
    var s = c11BUp(t), out = '', i, ch, m;
    if (dec) {
      for (i = 0; i < s.length; i++) {
        ch = s.charAt(i);
        out += C11B_LEET_BACK[ch] ? C11B_LEET_BACK[ch] : ch;
      }
      return out;
    }
    if (lvl < 1) lvl = 1;
    if (lvl > 3) lvl = 3;
    for (i = 0; i < s.length; i++) {
      ch = s.charAt(i);
      if (lvl === 1) out += C11B_LEET1[ch] ? C11B_LEET1[ch] : ch;
      else out += C11B_LEET2[ch] ? C11B_LEET2[ch] : ch;
    }
    if (lvl >= 3) {
      out = out.replace(/CK/g, 'X').replace(/ER/g, '0R').replace(/ED/g, '3D')
               .replace(/GHT/g, 'G7').replace(/OO/g, '00');
    }
    return out;
  }

  /* ================================================================
     37/38. ROT5, ROT18
     ================================================================ */
  function c11BRot5(t) {
    var s = c11BStr(t), o = '', i, c;
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c >= 48 && c <= 57) o += String.fromCharCode(48 + ((c - 48 + 5) % 10));
      else o += s.charAt(i);
    }
    return o;
  }

  function c11BRot18(t) {
    var s = c11BStr(t), o = '', i, c;
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c >= 65 && c <= 90) o += String.fromCharCode(65 + ((c - 65 + 13) % 26));
      else if (c >= 97 && c <= 122) o += String.fromCharCode(97 + ((c - 97 + 13) % 26));
      else if (c >= 48 && c <= 57) o += String.fromCharCode(48 + ((c - 48 + 5) % 10));
      else o += s.charAt(i);
    }
    return o;
  }

  /* ================================================================
     39. ROT8000 - shift every non ascii code point by 0x8000
     ================================================================ */
  function c11BRot8000(t) {
    var s = c11BStr(t), o = '', i, c, u;
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c >= 0xD800 && c <= 0xDBFF && i + 1 < s.length) {
        o += s.charAt(i) + s.charAt(i + 1); i++; continue;
      }
      if (c < 128) { o += s.charAt(i); continue; }
      u = c + 0x8000;
      if (u > 0xFFFF) u -= 0x10000;
      o += String.fromCharCode(u);
    }
    return o;
  }

  /* ================================================================
     40. Phone keypad multi-tap
     ================================================================ */
  var C11B_TAP = ['2', '22', '222', '3', '33', '333', '4', '44', '444', '5', '55', '555',
    '6', '66', '666', '7', '77', '777', '7777', '8', '88', '888', '9', '99', '999', '9999'];
  var C11B_TAPG = ['ABC', 'DEF', 'GHI', 'JKL', 'MNO', 'PQRS', 'TUV', 'WXYZ'];

  function c11BMultitap(t, key, dec, opt) {
    var o = c11BOpt(opt), sep = o.sep === undefined ? ' ' : c11BStr(o.sep);
    var s = c11BUp(t), out = [], i, c, toks, j, tk, d;
    if (dec) {
      toks = c11BStr(t).split(/[^0-9]+/);
      for (i = 0; i < toks.length; i++) {
        tk = toks[i];
        if (!tk) continue;
        if (tk === '0') { out.push(' '); continue; }
        d = parseInt(tk.charAt(0), 10);
        if (d < 2 || d > 9) continue;
        if (d === 7 || d === 9) { if (tk.length > 4) continue; out.push(C11B_TAPG[d - 2].charAt(tk.length - 1)); }
        else { if (tk.length > 3) continue; out.push(C11B_TAPG[d - 2].charAt(tk.length - 1)); }
      }
      return out.join('');
    }
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c === 32) out.push('0');
      else if (c >= 65 && c <= 90) out.push(C11B_TAP[c - 65]);
    }
    return out.join(sep);
  }

  /* ================================================================
     41. Baudot / ITA2  (official ITA2 table, msb first, 5 bit)
     ================================================================ */
  var C11B_ITA2 = [
    ['\0', '\0'], ['E', '3'], ['\n', '\n'], ['A', '-'], [' ', ' '], ['S', '\''], ['I', '8'], ['U', '7'],
    ['\r', '\r'], ['D', '$'], ['R', '4'], ['J', '\''], ['N', ','], ['F', '!'], ['C', ':'], ['K', '('],
    ['T', '5'], ['Z', '"'], ['L', ')'], ['W', '2'], ['H', '#'], ['Y', '6'], ['P', '0'], ['Q', '1'],
    ['O', '9'], ['B', '?'], ['G', '&'], [null, null], ['M', '.'], ['X', '/'], ['V', ';'], [null, null]
  ];
  var C11B_ITA2_FIGS = 27, C11B_ITA2_LTRS = 31;

  function c11BBaudot(t, key, dec, opt) {
    var o = c11BOpt(opt), fmt = o.format === 'hex' ? 'hex' : 'bits';
    var i, v, out, shift, ch, code, bits, toks, res;
    if (dec) {
      toks = c11BStr(t).trim().split(/[^01]+/);
      if (fmt === 'hex') {
        toks = [];
        var hx = c11BStr(t).trim().split(/[^0-9a-fA-F]+/);
        for (i = 0; i < hx.length; i++) if (hx[i]) toks.push(parseInt(hx[i], 16) & 31);
      } else {
        for (i = 0; i < toks.length; i++) toks[i] = toks[i] ? parseInt(toks[i], 2) & 31 : -1;
      }
      shift = 0; out = '';
      for (i = 0; i < toks.length; i++) {
        v = toks[i];
        if (v < 0) continue;
        if (v === C11B_ITA2_FIGS) { shift = 1; continue; }
        if (v === C11B_ITA2_LTRS) { shift = 0; continue; }
        ch = C11B_ITA2[v][shift];
        if (ch && ch !== '\0') out += ch;
      }
      return out;
    }
    var s = c11BUp(t);
    shift = 0; res = [];
    res.push(C11B_ITA2_LTRS);
    for (i = 0; i < s.length; i++) {
      ch = s.charAt(i);
      if (ch === '\n') { code = 2; }
      else if (ch === '\r') { code = 8; }
      else {
        code = -1;
        for (v = 0; v < 32; v++) {
          if (C11B_ITA2[v][0] === ch) { code = v; break; }
          if (C11B_ITA2[v][1] === ch) { code = v; break; }
        }
        if (code < 0) continue;
      }
      var inFigs = (C11B_ITA2[code][0] !== ch) && (C11B_ITA2[code][1] === ch);
      if (code === 4 || code === 2 || code === 8) inFigs = false;
      if (inFigs && shift === 0) { res.push(C11B_ITA2_FIGS); shift = 1; }
      if (!inFigs && shift === 1) { res.push(C11B_ITA2_LTRS); shift = 0; }
      res.push(code);
    }
    if (fmt === 'hex') {
      out = '';
      for (i = 0; i < res.length; i++) out += (i ? ' ' : '') + c11BHex2(res[i] & 31);
      return out;
    }
    bits = [];
    for (i = 0; i < res.length; i++) {
      v = res[i] & 31; bits.push(String.fromCharCode(48 + ((v >> 4) & 1)) + String.fromCharCode(48 + ((v >> 3) & 1)) +
        String.fromCharCode(48 + ((v >> 2) & 1)) + String.fromCharCode(48 + ((v >> 1) & 1)) + String.fromCharCode(48 + (v & 1)));
    }
    return bits.join(' ');
  }

  /* ================================================================
     42. basE91
     ================================================================ */
  var C11B_B91 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!#$%&()*+,./:;<=>?@[]^_`{|}~"';

  function c11BBase91(t, key, dec, opt) {
    var i, b, n, v, out;
    if (dec) {
      var s = c11BStr(t), d, len = s.length;
      b = 0; n = 0; v = -1; out = [];
      for (i = 0; i < len; i++) {
        d = C11B_B91.indexOf(s.charAt(i));
        if (d < 0) continue;
        if (v < 0) { v = d; continue; }
        v += d * 91;
        b |= v << n;
        n += ((v & 8191) > 88) ? 13 : 14;
        while (n > 7) { out.push(b & 0xFF); b = b >>> 8; n -= 8; }
        v = -1;
      }
      if (v >= 0) out.push((b | (v << n)) & 0xFF);
      return c11BFromUtf8(out);
    }
    var m = c11BUtf8(t);
    b = 0; n = 0; out = '';
    for (i = 0; i < m.length; i++) {
      b |= (m[i] & 0xFF) << n; n += 8;
      if (n > 13) {
        v = b & 8191;
        if (v > 88) { b = b >>> 13; n -= 13; }
        else { v = b & 16383; b = b >>> 14; n -= 14; }
        out += C11B_B91.charAt(v % 91) + C11B_B91.charAt(Math.floor(v / 91));
      }
    }
    if (n) {
      out += C11B_B91.charAt(b % 91);
      if (n > 7 || b > 90) out += C11B_B91.charAt(Math.floor(b / 91));
    }
    return out;
  }

  /* ================================================================
     43. Base36 (whole byte stream as one big integer, no BigInt)
     ================================================================ */
  var C11B_B36 = '0123456789abcdefghijklmnopqrstuvwxyz';

  function c11BBase36(t, key, dec, opt) {
    var i, d, out, rem;
    if (dec) {
      var s = c11BStr(t).toLowerCase().replace(/[^0-9a-z]/g, '');
      if (!s) return '';
      d = [0];
      for (i = 0; i < s.length; i++) d = c11BMulAddSmall(d, 36, C11B_B36.indexOf(s.charAt(i)));
      while (d.length > 1 && d[0] === 0) d.shift();
      /* value zero -> empty byte stream (leading zero bytes are not
         recoverable from a plain big integer base conversion) */
      if (d.length === 1 && d[0] === 0) return '';
      d.reverse();
      return c11BFromUtf8(d);
    }
    var m = c11BUtf8(t);
    while (m.length > 1 && m[0] === 0) m.shift();
    if (!m.length) return '0';
    out = '';
    while (m.length) {
      rem = c11BDivSmall(m, 36);
      out = C11B_B36.charAt(rem.r) + out;
      m = rem.q;
    }
    return out;
  }

  /* ================================================================
     44. Base64URL
     ================================================================ */
  function c11BBase64url(t, key, dec, opt) {
    var o = c11BOpt(opt), pad = !!o.pad, s, i, v, vb, out;
    if (dec) {
      s = c11BStr(t).replace(/[^A-Za-z0-9\-_=]/g, '').replace(/-/g, '+').replace(/_/g, '/');
      return c11BFromUtf8(c11BB64d(s));
    }
    s = c11BB64e(c11BUtf8(t));
    s = s.replace(/\+/g, '-').replace(/\//g, '_');
    if (!pad) s = s.replace(/=+$/, '');
    return s;
  }

  /* ================================================================
     45. Crockford Base32
     ================================================================ */
  var C11B_CROCK = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
  var C11B_CROCK_CHECK = '0123456789ABCDEFGHJKMNPQRSTVWXYZ*~$=U';

  function c11BCrockford(t, key, dec, opt) {
    var o = c11BOpt(opt), m, v, i, out, c, d, chk;
    if (dec) {
      var s = c11BStr(t).toUpperCase().replace(/[^0-9A-Z*~$=U]/g, '');
      if (o.check && s.length) {
        chk = s.charAt(s.length - 1); s = s.substring(0, s.length - 1);
      }
      v = [];
      for (i = 0; i < s.length; i++) {
        c = s.charAt(i);
        if (c === 'I' || c === 'L') c = '1';
        else if (c === 'O') c = '0';
        d = C11B_CROCK.indexOf(c);
        if (d >= 0) v.push(d);
      }
      m = c11BUnbits5(v, 0);
      if (chk) {
        var mod = 0;
        for (i = 0; i < m.length; i++) mod = (mod * 256 + (m[i] & 0xFF)) % 37;
        out = c11BFromUtf8(m);
        return out + '  [check ' + C11B_CROCK_CHECK.charAt(mod) + (C11B_CROCK_CHECK.charAt(mod) === chk ? ' OK' : ' BAD, got ' + chk) + ']';
      }
      return c11BFromUtf8(m);
    }
    m = c11BUtf8(t);
    v = c11BBits5(m);
    out = '';
    for (i = 0; i < v.length; i++) out += C11B_CROCK.charAt(v[i]);
    if (o.check) {
      var md = 0;
      for (i = 0; i < m.length; i++) md = (md * 256 + (m[i] & 0xFF)) % 37;
      out += C11B_CROCK_CHECK.charAt(md);
    }
    return out;
  }

  /* ================================================================
     46. z-base-32
     ================================================================ */
  var C11B_ZB32 = 'ybndrfg8ejkmcpqxot1uwisza345h769';

  function c11BZBase32(t, key, dec, opt) {
    var m, v, i, out, d;
    if (dec) {
      var s = c11BStr(t).toLowerCase().replace(/[^a-z0-9]/g, '');
      v = [];
      for (i = 0; i < s.length; i++) {
        d = C11B_ZB32.indexOf(s.charAt(i));
        if (d >= 0) v.push(d);
      }
      return c11BFromUtf8(c11BUnbits5(v, Math.floor(v.length * 5 / 8)));
    }
    m = c11BUtf8(t);
    v = c11BBits5(m);
    out = '';
    for (i = 0; i < v.length; i++) out += C11B_ZB32.charAt(v[i]);
    return out;
  }

  /* ================================================================
     47. Base100 emoji
     ================================================================ */
  function c11BBase100(t, key, dec, opt) {
    var m, i, out, cp, v, s, k, c, hi, lo;
    if (dec) {
      s = c11BStr(t); out = [];
      for (i = 0; i < s.length; i++) {
        c = s.charCodeAt(i);
        if (c >= 0xD800 && c <= 0xDBFF && i + 1 < s.length) {
          lo = s.charCodeAt(i + 1); i++;
          cp = 0x10000 + ((c - 0xD800) << 10) + (lo - 0xDC00);
        } else cp = c;
        if (cp >= 0x1F300 && cp <= 0x2F2FF) {
          v = cp - 0x1F300;
          out.push((v >> 8) & 0xFF); out.push(v & 0xFF);
        } else if (cp >= 0x100 && cp <= 0x1FF) out.push(cp - 0x100);
      }
      return c11BFromUtf8(out);
    }
    m = c11BUtf8(t); out = '';
    for (i = 0; i < m.length; i += 2) {
      if (i + 1 < m.length) cp = 0x1F300 + ((m[i] << 8) | m[i + 1]);
      else cp = 0x100 + m[i];
      if (cp > 0xFFFF) {
        v = cp - 0x10000; hi = 0xD800 + (v >> 10); lo = 0xDC00 + (v & 0x3FF);
        out += String.fromCharCode(hi) + String.fromCharCode(lo);
      } else out += String.fromCharCode(cp);
    }
    return out;
  }

  /* ================================================================
     48. EBCDIC (IBM CP037)
     ================================================================ */
  var C11B_CP037 = [
    0x0000, 0x0001, 0x0002, 0x0003, 0x009c, 0x0009, 0x0086, 0x007f, 0x0097, 0x008d, 0x008e, 0x000b, 0x000c, 0x000d, 0x000e, 0x000f,
    0x0010, 0x0011, 0x0012, 0x0013, 0x009d, 0x0085, 0x0008, 0x0087, 0x0018, 0x0019, 0x0092, 0x008f, 0x001c, 0x001d, 0x001e, 0x001f,
    0x0080, 0x0081, 0x0082, 0x0083, 0x0084, 0x000a, 0x0017, 0x001b, 0x0088, 0x0089, 0x008a, 0x008b, 0x008c, 0x0005, 0x0006, 0x0007,
    0x0090, 0x0091, 0x0016, 0x0093, 0x0094, 0x0095, 0x0096, 0x0004, 0x0098, 0x0099, 0x009a, 0x009b, 0x0014, 0x0015, 0x009e, 0x001a,
    0x0020, 0x00a0, 0x00e2, 0x00e4, 0x00e0, 0x00e1, 0x00e3, 0x00e5, 0x00e7, 0x00f1, 0x00a2, 0x002e, 0x003c, 0x0028, 0x002b, 0x007c,
    0x0026, 0x00e9, 0x00ea, 0x00eb, 0x00e8, 0x00ed, 0x00ee, 0x00ef, 0x00ec, 0x00df, 0x0021, 0x0024, 0x002a, 0x0029, 0x003b, 0x00ac,
    0x002d, 0x002f, 0x00c2, 0x00c4, 0x00c0, 0x00c1, 0x00c3, 0x00c5, 0x00c7, 0x00d1, 0x00a6, 0x002c, 0x0025, 0x005f, 0x003e, 0x003f,
    0x00f8, 0x00c9, 0x00ca, 0x00cb, 0x00c8, 0x00cd, 0x00ce, 0x00cf, 0x00cc, 0x0060, 0x003a, 0x0023, 0x0040, 0x0027, 0x003d, 0x0022,
    0x00d8, 0x0061, 0x0062, 0x0063, 0x0064, 0x0065, 0x0066, 0x0067, 0x0068, 0x0069, 0x00ab, 0x00bb, 0x00f0, 0x00fd, 0x00fe, 0x00b1,
    0x00b0, 0x006a, 0x006b, 0x006c, 0x006d, 0x006e, 0x006f, 0x0070, 0x0071, 0x0072, 0x00aa, 0x00ba, 0x00e6, 0x00b8, 0x00c6, 0x00a4,
    0x00b5, 0x007e, 0x0073, 0x0074, 0x0075, 0x0076, 0x0077, 0x0078, 0x0079, 0x007a, 0x00a1, 0x00bf, 0x00d0, 0x00dd, 0x00de, 0x00ae,
    0x005e, 0x00a3, 0x00a5, 0x00b7, 0x00a9, 0x00a7, 0x00b6, 0x00bc, 0x00bd, 0x00be, 0x005b, 0x005d, 0x00af, 0x00a8, 0x00b4, 0x00d7,
    0x007b, 0x0041, 0x0042, 0x0043, 0x0044, 0x0045, 0x0046, 0x0047, 0x0048, 0x0049, 0x00ad, 0x00f4, 0x00f6, 0x00f2, 0x00f3, 0x00f5,
    0x007d, 0x004a, 0x004b, 0x004c, 0x004d, 0x004e, 0x004f, 0x0050, 0x0051, 0x0052, 0x00b9, 0x00fb, 0x00fc, 0x00f9, 0x00fa, 0x00ff,
    0x005c, 0x00f7, 0x0053, 0x0054, 0x0055, 0x0056, 0x0057, 0x0058, 0x0059, 0x005a, 0x00b2, 0x00d4, 0x00d6, 0x00d2, 0x00d3, 0x00d5,
    0x0030, 0x0031, 0x0032, 0x0033, 0x0034, 0x0035, 0x0036, 0x0037, 0x0038, 0x0039, 0x00b3, 0x00db, 0x00dc, 0x00d9, 0x00da, 0x009f
  ];

  function c11BEbcdic(t, key, dec, opt) {
    var o = c11BOpt(opt), fmt = o.format === 'raw' ? 'raw' : 'hex';
    var i, out, m, s, rev, b;
    if (dec) {
      s = c11BStr(t);
      m = (/^[0-9a-fA-F\s]+$/.test(s.replace(/[^0-9a-fA-F\s]/g, '')) && /[0-9a-fA-F]/.test(s)) ? c11BUnhex(s) : null;
      if (m === null || !m.length) {
        m = [];
        for (i = 0; i < s.length; i++) m.push(s.charCodeAt(i) & 0xFF);
      }
      out = '';
      for (i = 0; i < m.length; i++) out += String.fromCharCode(C11B_CP037[m[i] & 0xFF]);
      return out;
    }
    s = c11BStr(t);
    rev = {};
    for (i = 0; i < 256; i++) {
      if (C11B_CP037[i] && rev[C11B_CP037[i]] === undefined) rev[C11B_CP037[i]] = i;
    }
    m = [];
    for (i = 0; i < s.length; i++) {
      b = rev[s.charCodeAt(i)];
      m.push(b === undefined ? 0x3F : b);
    }
    if (fmt === 'raw') {
      out = '';
      for (i = 0; i < m.length; i++) out += String.fromCharCode(m[i]);
      return out;
    }
    out = '';
    for (i = 0; i < m.length; i++) out += (i ? ' ' : '') + c11BHex2(m[i]).toUpperCase();
    return out;
  }

  /* ================================================================
     49. Bubble Babble
     ================================================================ */
  var C11B_BB_V = 'aeiouy';
  var C11B_BB_C = 'bcdfghklmnprstvzx';

  function c11BBubbleBabble(t, key, dec, opt) {
    var m, i, out, seed, b1, b2, a, b, c, d, e, s, L, n, pos, ch, val;
    if (dec) {
      s = c11BStr(t).toLowerCase().replace(/[^a-z]/g, '');
      if (s.charAt(0) === 'x') s = s.substring(1);
      if (s.charAt(s.length - 1) === 'x') s = s.substring(0, s.length - 1);
      L = s.length;
      m = []; seed = 1; pos = 0;
      while (L - pos >= 5) {
        a = C11B_BB_V.indexOf(s.charAt(pos));
        b = C11B_BB_C.indexOf(s.charAt(pos + 1));
        c = C11B_BB_V.indexOf(s.charAt(pos + 2));
        d = C11B_BB_C.indexOf(s.charAt(pos + 3));
        e = C11B_BB_C.indexOf(s.charAt(pos + 4));
        if (a < 0 || b < 0 || c < 0 || d < 0 || e < 0) return '';
        b1 = ((((a - seed) % 6) + 6) % 6) * 64 + b * 4 + ((((c - Math.floor(seed / 6)) % 6) + 6) % 6);
        b2 = d * 16 + e;
        m.push(b1 & 0xFF); m.push(b2 & 0xFF);
        seed = (seed * 5 + (b1 & 0xFF) * 7 + (b2 & 0xFF)) % 36;
        pos += 5;
      }
      if (L - pos === 3) {
        a = C11B_BB_V.indexOf(s.charAt(pos));
        b = C11B_BB_C.indexOf(s.charAt(pos + 1));
        c = C11B_BB_V.indexOf(s.charAt(pos + 2));
        if (a < 0 || b < 0 || c < 0) return '';
        /* middle char 'x' means even length checksum tuple, no data byte */
        if (b !== C11B_BB_C.indexOf('x')) {
          b1 = ((((a - seed) % 6) + 6) % 6) * 64 + b * 4 + ((((c - Math.floor(seed / 6)) % 6) + 6) % 6);
          m.push(b1 & 0xFF);
        }
      }
      return c11BFromUtf8(m);
    }
    m = c11BUtf8(t);
    out = 'x'; seed = 1;
    for (i = 0; i + 1 < m.length; i += 2) {
      b1 = m[i] & 0xFF; b2 = m[i + 1] & 0xFF;
      a = (((b1 >> 6) & 3) + seed) % 6;
      b = (b1 >> 2) & 15;
      c = ((b1 & 3) + Math.floor(seed / 6)) % 6;
      d = (b2 >> 4) & 15;
      e = b2 & 15;
      out += C11B_BB_V.charAt(a) + C11B_BB_C.charAt(b) + C11B_BB_V.charAt(c) + C11B_BB_C.charAt(d) + '-' + C11B_BB_C.charAt(e);
      seed = (seed * 5 + b1 * 7 + b2) % 36;
    }
    if (i < m.length) {
      /* odd length: data tuple, middle char is a data consonant */
      b1 = m[i] & 0xFF;
      a = (((b1 >> 6) & 3) + seed) % 6;
      b = (b1 >> 2) & 15;
      c = ((b1 & 3) + Math.floor(seed / 6)) % 6;
      out += C11B_BB_V.charAt(a) + C11B_BB_C.charAt(b) + C11B_BB_V.charAt(c);
    } else {
      /* even length: canonical checksum tuple, middle char is 'x' */
      out += C11B_BB_V.charAt(seed % 6) + 'x' + C11B_BB_V.charAt(Math.floor(seed / 6) % 6);
    }
    return out + 'x';
  }

  /* ================================================================
     50. Gray code
     ================================================================ */
  function c11BGrayNum(n, dec) {
    var g, i, r;
    n = n >>> 0;
    if (!dec) return (n ^ (n >>> 1)) >>> 0;
    r = n; g = n;
    while (g > 0) { g = g >>> 1; r = r ^ g; }
    return r >>> 0;
  }

  function c11BGray(t, key, dec, opt) {
    var o = c11BOpt(opt), out = o.out === 'dec' ? 'dec' : (o.out === 'hex' ? 'hex' : 'bin');
    var width = c11BInt(o.width, 4);
    var mode = o.mode === 'text' ? 'text' : 'num';
    var s = c11BStr(t), toks, res = [], i, n, g, w, pad, base;
    if (mode === 'text') {
      var m, r = [], k, g2, flat;
      if (dec) {
        flat = s.replace(/\s+/g, '');
        m = (/^[0-9a-fA-F]+$/.test(flat) && flat.length % 2 === 0) ? c11BUnhex(flat) : c11BUtf8(t);
        for (k = 0; k < m.length; k++) { n = m[k]; g2 = n; while (n > 0) { n = n >> 1; g2 = g2 ^ n; } r.push(g2 & 0xFF); }
        return c11BFromUtf8(r);
      }
      m = c11BUtf8(t);
      for (k = 0; k < m.length; k++) r.push((m[k] ^ (m[k] >> 1)) & 0xFF);
      return c11BHex(r);
    }
    base = (o.in === 'bin') ? 2 : ((o.in === 'hex') ? 16 : 10);
    toks = s.split(/[^0-9a-fA-F]+/);
    for (i = 0; i < toks.length; i++) {
      if (!toks[i]) continue;
      n = parseInt(toks[i], base);
      if (isNaN(n)) continue;
      g = c11BGrayNum(n, dec);
      if (out === 'bin') {
        w = width;
        pad = g.toString(2);
        while (pad.length < w) pad = '0' + pad;
        res.push(pad);
      } else if (out === 'hex') res.push(g.toString(16));
      else res.push(String(g));
    }
    return res.join(' ');
  }

  /* ================================================================
     51. VarInt / LEB128
     ================================================================ */
  function c11BVarint(t, key, dec, opt) {
    var o = c11BOpt(opt), sleb = o.mode === 'sleb';
    var i, b, n, more, out, lines, v, shift, val;
    if (dec) {
      var m = c11BUnhex(c11BStr(t)), res = [], k;
      i = 0;
      while (i < m.length) {
        val = 0; shift = 0; b = 0;
        do {
          b = m[i] & 0xFF; i++;
          val |= (b & 0x7F) << shift;
          shift += 7;
        } while ((b & 0x80) && i < m.length && shift < 64);
        if (sleb && shift < 64 && (b & 0x40)) val = val - (1 << shift);
        res.push(String(val));
      }
      return res.join(' ');
    }
    lines = c11BStr(t).trim().split(/[^0-9\-]+/);
    out = [];
    for (i = 0; i < lines.length; i++) {
      if (!lines[i]) continue;
      n = parseInt(lines[i], 10);
      if (isNaN(n)) continue;
      b = [];
      if (sleb) {
        more = true;
        while (more) {
          var byte7 = n & 0x7F;
          n = n >> 7;
          if ((n === 0 && !(byte7 & 0x40)) || (n === -1 && (byte7 & 0x40))) more = false;
          else byte7 |= 0x80;
          b.push(byte7 & 0xFF);
        }
      } else {
        v = n >>> 0;
        do {
          var by = v & 0x7F;
          v = Math.floor(v / 128);
          if (v !== 0) by |= 0x80;
          b.push(by);
        } while (v !== 0);
      }
      var hs = '';
      for (var k2 = 0; k2 < b.length; k2++) hs += (k2 ? ' ' : '') + c11BHex2(b[k2] & 0xFF);
      out.push(hs);
    }
    return out.join('\n');
  }

  /* ================================================================
     52. Hex dump
     ================================================================ */
  function c11BHexdump(t, key, dec, opt) {
    var o = c11BOpt(opt), cols = c11BInt(o.cols, 16), i, j, out = '', hex, asc, c, lines, line, m, k, hx;
    if (dec) {
      lines = c11BStr(t).split('\n');
      m = [];
      for (i = 0; i < lines.length; i++) {
        line = lines[i].replace(/\|[^|]*\|\s*$/, '').replace(/^\s*[0-9a-fA-F]+\s+/, '');
        hx = line.replace(/[^0-9a-fA-F]/g, '');
        for (k = 0; k + 1 < hx.length + 1; k += 2) m.push(parseInt(hx.substr(k, 2), 16));
      }
      return c11BFromUtf8(m);
    }
    m = c11BUtf8(t);
    if (cols < 1) cols = 16;
    for (i = 0; i < m.length; i += cols) {
      hex = ''; asc = '';
      for (j = 0; j < cols; j++) {
        if (i + j < m.length) {
          hex += (j ? ' ' : '') + c11BHex2(m[i + j]);
          c = m[i + j];
          asc += (c >= 32 && c < 127) ? String.fromCharCode(c) : '.';
        }
      }
      while (hex.length < cols * 3 - 1) hex += ' ';
      out += (i ? '\n' : '') + c11BHex2((i >> 24) & 0xFF) + c11BHex2((i >> 16) & 0xFF) +
        c11BHex2((i >> 8) & 0xFF) + c11BHex2(i & 0xFF) + '  ' + hex + '  |' + asc + '|';
    }
    return out;
  }

  /* ================================================================
     53. Bit rotate / reverse
     ================================================================ */
  function c11BRevBits8(v) {
    var r = 0, i;
    for (i = 0; i < 8; i++) { r = ((r << 1) | ((v >> i) & 1)) & 0xFF; }
    return r;
  }

  function c11BRol8(v, n) { n = ((n % 8) + 8) % 8; return (((v << n) | (v >> (8 - n))) & 0xFF); }

  function c11BRolW(v, w, n) {
    n = ((n % w) + w) % w;
    if (!n) return v >>> 0;
    return (((v << n) | (v >>> (w - n))) >>> 0) & ((w === 32) ? 0xFFFFFFFF : ((1 << w) - 1));
  }

  function c11BBitRot(t, key, dec, opt) {
    var o = c11BOpt(opt), op = o.op === 'ror' ? 'ror' : (o.op === 'rev' ? 'rev' : 'rol');
    var n = c11BInt(o.n, 1), mode = o.mode === 'text' ? 'text' : 'hex';
    var width = c11BInt(o.width, 8);
    var m, i, out, r, toks, v;
    if (dec) {
      if (op === 'rol') op = 'ror';
      else if (op === 'ror') op = 'rol';
    }
    if (mode === 'text') m = c11BUtf8(t);
    else {
      toks = c11BStr(t).trim().split(/[^0-9a-fA-F]+/);
      m = [];
      for (i = 0; i < toks.length; i++) if (toks[i]) m.push(parseInt(toks[i], 16) & 0xFF);
    }
    out = [];
    for (i = 0; i < m.length; i++) {
      if (width === 8) {
        if (op === 'rev') r = c11BRevBits8(m[i]);
        else if (op === 'ror') r = c11BRol8(m[i], 8 - n);
        else r = c11BRol8(m[i], n);
        out.push(r & 0xFF);
      } else {
        out.push(c11BRolW(m[i], width, (op === 'ror') ? (width - n) : n) & 0xFF);
      }
    }
    if (mode === 'text' && op !== 'rev') return c11BFromUtf8(out);
    r = '';
    for (i = 0; i < out.length; i++) r += (i ? ' ' : '') + c11BHex2(out[i] & 0xFF);
    return r;
  }

  /* ================================================================
     54. DNA codon encoder
     ================================================================ */
  var C11B_AA3 = {
    A: 'ALA', R: 'ARG', N: 'ASN', D: 'ASP', C: 'CYS', Q: 'GLN', E: 'GLU', G: 'GLY',
    H: 'HIS', I: 'ILE', L: 'LEU', K: 'LYS', M: 'MET', F: 'PHE', P: 'PRO', S: 'SER',
    T: 'THR', W: 'TRP', Y: 'TYR', V: 'VAL', B: 'ASX', Z: 'GLX', X: 'UNK', '*': 'STOP'
  };
  var C11B_AA3R = {
    ALA: 'A', ARG: 'R', ASN: 'N', ASP: 'D', CYS: 'C', GLN: 'Q', GLU: 'E', GLY: 'G',
    HIS: 'H', ILE: 'I', LEU: 'L', LYS: 'K', MET: 'M', PHE: 'F', PRO: 'P', SER: 'S',
    THR: 'T', TRP: 'W', TYR: 'Y', VAL: 'V', ASX: 'B', GLX: 'Z', UNK: 'X', STOP: '*'
  };
  var C11B_GENETIC = 'FFLLSSSSYY**CC*WLLLLPPPPHHQQRRRRIIIMTTTTNNKKSSRRVVVVAAAADDEEGGGG';
  var C11B_BASES = 'TCAG';

  function c11BDna(t, key, dec, opt) {
    var o = c11BOpt(opt), mode = o.mode || 'aa1';
    var s, i, out, m, j, k, v, acc, nb, b1, b2, b3, aa;
    if (mode === 'aa1') {
      s = c11BUp(t).replace(/[^A-Z*]/g, '');
      if (dec) {
        var toks = c11BStr(t).toUpperCase().split(/[^A-Z]+/);
        out = [];
        for (i = 0; i < toks.length; i++) if (toks[i] && C11B_AA3R[toks[i]]) out.push(C11B_AA3R[toks[i]]);
        return out.join('');
      }
      out = [];
      for (i = 0; i < s.length; i++) if (C11B_AA3[s.charAt(i)]) out.push(C11B_AA3[s.charAt(i)]);
      return out.join('-');
    }
    if (mode === 'protein') {
      s = c11BUp(t).replace(/[^TCAG]/g, '');
      out = '';
      for (i = 0; i + 2 < s.length + 1; i += 3) {
        b1 = C11B_BASES.indexOf(s.charAt(i));
        b2 = C11B_BASES.indexOf(s.charAt(i + 1));
        b3 = C11B_BASES.indexOf(s.charAt(i + 2));
        if (b1 < 0 || b2 < 0 || b3 < 0) break;
        out += C11B_GENETIC.charAt(b1 * 16 + b2 * 4 + b3);
      }
      return out;
    }
    /* mode 'dna' : 2 bit packing A=00 C=01 G=10 T=11 */
    if (dec) {
      s = c11BUp(t).replace(/[^ACGT]/g, '');
      m = []; acc = 0; nb = 0;
      for (i = 0; i < s.length; i++) {
        v = C11B_BASES.indexOf(s.charAt(i));
        if (v < 0) continue;
        acc = (acc << 2) | v; nb += 2;
        if (nb >= 8) { nb -= 8; m.push((acc >> nb) & 0xFF); }
      }
      return c11BFromUtf8(m);
    }
    m = c11BUtf8(t); out = '';
    for (i = 0; i < m.length; i++) {
      for (j = 6; j >= 0; j -= 2) out += C11B_BASES.charAt(((m[i] >> j) & 3));
    }
    return out;
  }

  /* ================================================================
     55. SHA-3 / SHAKE  --  Keccak-f[1600], 64 bit lanes kept as two
     32 bit halves (hi in AH, lo in AL)
     ================================================================ */
  var C11B_KROT = [0, 1, 62, 28, 27, 36, 44, 6, 55, 20, 3, 10, 43, 25, 39, 41, 45, 15, 21, 8, 18, 2, 61, 56, 14];
  var C11B_KRC_HI = [0, 0, 0x80000000, 0x80000000, 0, 0, 0x80000000, 0x80000000, 0, 0, 0, 0, 0, 0x80000000, 0x80000000, 0x80000000,
    0x80000000, 0x80000000, 0, 0x80000000, 0x80000000, 0x80000000, 0, 0x80000000];
  var C11B_KRC_LO = [0x00000001, 0x00008082, 0x0000808A, 0x80008000, 0x0000808B, 0x80000001, 0x80008081, 0x00008009,
    0x0000008A, 0x00000088, 0x80008009, 0x8000000A, 0x8000808B, 0x0000008B, 0x00008089, 0x00008003,
    0x00008002, 0x00000080, 0x0000800A, 0x8000000A, 0x80008081, 0x00008080, 0x80000001, 0x80008008];

  function c11BKeccakF(ah, al) {
    var CH = [0, 0, 0, 0, 0], CL = [0, 0, 0, 0, 0];
    var BH = new Array(25), BL = new Array(25);
    var r, x, y, i0, i1, i2, n, m, t, hi, lo, dh, dl;
    for (r = 0; r < 24; r++) {
      for (x = 0; x < 5; x++) {
        CH[x] = ah[x] ^ ah[x + 5] ^ ah[x + 10] ^ ah[x + 15] ^ ah[x + 20];
        CL[x] = al[x] ^ al[x + 5] ^ al[x + 10] ^ al[x + 15] ^ al[x + 20];
      }
      for (x = 0; x < 5; x++) {
        i1 = (x + 1) % 5; i2 = (x + 4) % 5;
        dh = ((((CH[i1] << 1) | (CL[i1] >>> 31)) >>> 0) ^ CH[i2]) >>> 0;
        dl = ((((CL[i1] << 1) | (CH[i1] >>> 31)) >>> 0) ^ CL[i2]) >>> 0;
        for (y = 0; y < 5; y++) {
          ah[x + 5 * y] = (ah[x + 5 * y] ^ dh) >>> 0;
          al[x + 5 * y] = (al[x + 5 * y] ^ dl) >>> 0;
        }
      }
      for (x = 0; x < 5; x++) {
        for (y = 0; y < 5; y++) {
          i0 = x + 5 * y;
          n = C11B_KROT[i0];
          hi = ah[i0]; lo = al[i0];
          if (n === 32) { t = hi; hi = lo; lo = t; }
          else if (n > 32) {
            m = n - 32;
            t = ((lo << m) | (hi >>> (32 - m))) >>> 0;
            lo = ((hi << m) | (lo >>> (32 - m))) >>> 0;
            hi = t;
          } else if (n > 0) {
            t = ((hi << n) | (lo >>> (32 - n))) >>> 0;
            lo = ((lo << n) | (hi >>> (32 - n))) >>> 0;
            hi = t;
          }
          i1 = y + 5 * ((2 * x + 3 * y) % 5);
          BH[i1] = hi; BL[i1] = lo;
        }
      }
      for (y = 0; y < 5; y++) {
        for (x = 0; x < 5; x++) {
          i0 = x + 5 * y; i1 = ((x + 1) % 5) + 5 * y; i2 = ((x + 2) % 5) + 5 * y;
          ah[i0] = (BH[i0] ^ ((~BH[i1]) & BH[i2])) >>> 0;
          al[i0] = (BL[i0] ^ ((~BL[i1]) & BL[i2])) >>> 0;
        }
      }
      ah[0] = (ah[0] ^ C11B_KRC_HI[r]) >>> 0;
      al[0] = (al[0] ^ C11B_KRC_LO[r]) >>> 0;
    }
  }

  function c11BKeccak(msg, rate, suffix, outLen) {
    var ah = [], al = [], i, j, lane, b, m, padLen, pos, out = [];
    for (i = 0; i < 25; i++) { ah.push(0); al.push(0); }
    m = msg.slice();
    padLen = rate - (m.length % rate);
    m.push(suffix & 0xFF);
    for (i = 1; i < padLen; i++) m.push(0);
    m[m.length - 1] = m[m.length - 1] | 0x80;
    for (pos = 0; pos < m.length; pos += rate) {
      for (i = 0; i < rate; i += 8) {
        lane = i / 8;
        for (j = 0; j < 8; j++) {
          b = m[pos + i + j] & 0xFF;
          if (j < 4) al[lane] = (al[lane] ^ (b << (8 * j))) >>> 0;
          else ah[lane] = (ah[lane] ^ (b << (8 * (j - 4)))) >>> 0;
        }
      }
      c11BKeccakF(ah, al);
    }
    while (out.length < outLen) {
      for (i = 0; i < rate && out.length < outLen; i++) {
        lane = Math.floor(i / 8); j = i % 8;
        out.push((j < 4 ? (al[lane] >>> (8 * j)) : (ah[lane] >>> (8 * (j - 4)))) & 0xFF);
      }
      if (out.length < outLen) c11BKeccakF(ah, al);
    }
    return out;
  }

  function c11BSha3(t, bits) {
    bits = c11BInt(bits, 256);
    if (bits !== 224 && bits !== 256 && bits !== 384 && bits !== 512) bits = 256;
    var rate = (1600 - 2 * bits) / 8;
    return c11BHex(c11BKeccak(c11BUtf8(t), rate, 0x06, bits / 8));
  }

  function c11BShake(t, sec, outBytes) {
    sec = (c11BInt(sec, 128) === 256) ? 256 : 128;
    outBytes = c11BInt(outBytes, 32);
    if (outBytes < 1) outBytes = 32;
    var rate = (1600 - 2 * sec) / 8;
    return c11BHex(c11BKeccak(c11BUtf8(t), rate, 0x1F, outBytes));
  }

  /* ================================================================
     56. BLAKE2b / BLAKE2s
     ================================================================ */
  var C11B_SIGMA = [
    [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
    [14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3],
    [11, 8, 12, 0, 5, 2, 15, 13, 10, 14, 3, 6, 7, 1, 9, 4],
    [7, 9, 3, 1, 13, 12, 11, 14, 2, 6, 5, 10, 4, 0, 15, 8],
    [9, 0, 5, 7, 2, 4, 10, 15, 14, 1, 11, 12, 6, 8, 3, 13],
    [2, 12, 6, 10, 0, 11, 8, 3, 4, 13, 7, 5, 15, 14, 1, 9],
    [12, 5, 1, 15, 14, 13, 4, 10, 0, 7, 6, 3, 9, 2, 8, 11],
    [13, 11, 7, 14, 12, 1, 3, 9, 5, 0, 15, 4, 8, 6, 2, 10],
    [6, 15, 14, 9, 11, 3, 0, 8, 12, 2, 13, 7, 1, 4, 10, 5],
    [10, 2, 8, 4, 7, 6, 1, 5, 15, 11, 9, 14, 3, 12, 13, 0]
  ];
  var C11B_B2B_IV_HI = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];
  var C11B_B2B_IV_LO = [0xf3bcc908, 0x84caa73b, 0xfe94f82b, 0x5f1d36f1, 0xade682d1, 0x2b3e6c1f, 0xfb41bd6b, 0x137e2179];
  var C11B_B2S_IV = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];

  function c11BRotr64H(lo, hi, n) {
    if (n === 0) return hi >>> 0;
    if (n === 32) return lo >>> 0;
    if (n < 32) return ((hi >>> n) | (lo << (32 - n))) >>> 0;
    return c11BRotr64L(lo, hi, n - 32);
  }
  function c11BRotr64L(lo, hi, n) {
    if (n === 0) return lo >>> 0;
    if (n === 32) return hi >>> 0;
    if (n < 32) return ((lo >>> n) | (hi << (32 - n))) >>> 0;
    return c11BRotr64H(lo, hi, n - 32);
  }

  function c11BB2bG(v, a, b, c, d, xh, xl, yh, yl) {
    var ah = v[2 * a], al = v[2 * a + 1], bh = v[2 * b], bl = v[2 * b + 1];
    var ch = v[2 * c], cl = v[2 * c + 1], dh = v[2 * d], dl = v[2 * d + 1];
    var tlo, thi, nhi, nlo;
    tlo = al + bl; thi = ah + bh + (tlo > 0xFFFFFFFF ? 1 : 0);
    nlo = (tlo >>> 0) + xl; nhi = (thi >>> 0) + xh + (nlo > 0xFFFFFFFF ? 1 : 0);
    ah = nhi >>> 0; al = nlo >>> 0;
    dh = (dh ^ ah) >>> 0; dl = (dl ^ al) >>> 0;
    tlo = dh; dh = dl; dl = tlo;
    tlo = cl + dl; thi = ch + dh + (tlo > 0xFFFFFFFF ? 1 : 0);
    ch = thi >>> 0; cl = tlo >>> 0;
    bh = (bh ^ ch) >>> 0; bl = (bl ^ cl) >>> 0;
    nhi = ((bh >>> 24) | (bl << 8)) >>> 0; nlo = ((bl >>> 24) | (bh << 8)) >>> 0;
    bh = nhi; bl = nlo;
    tlo = al + bl; thi = ah + bh + (tlo > 0xFFFFFFFF ? 1 : 0);
    nlo = (tlo >>> 0) + yl; nhi = (thi >>> 0) + yh + (nlo > 0xFFFFFFFF ? 1 : 0);
    ah = nhi >>> 0; al = nlo >>> 0;
    dh = (dh ^ ah) >>> 0; dl = (dl ^ al) >>> 0;
    nhi = ((dh >>> 16) | (dl << 16)) >>> 0; nlo = ((dl >>> 16) | (dh << 16)) >>> 0;
    dh = nhi; dl = nlo;
    tlo = cl + dl; thi = ch + dh + (tlo > 0xFFFFFFFF ? 1 : 0);
    ch = thi >>> 0; cl = tlo >>> 0;
    bh = (bh ^ ch) >>> 0; bl = (bl ^ cl) >>> 0;
    nhi = ((bh << 1) | (bl >>> 31)) >>> 0; nlo = ((bl << 1) | (bh >>> 31)) >>> 0;
    bh = nhi; bl = nlo;
    v[2 * a] = ah; v[2 * a + 1] = al; v[2 * b] = bh; v[2 * b + 1] = bl;
    v[2 * c] = ch; v[2 * c + 1] = cl; v[2 * d] = dh; v[2 * d + 1] = dl;
  }

  function c11BBlake2b(t, outBits, key) {
    var nn = c11BInt(outBits, 512) / 8;
    if (nn < 1 || nn > 64) nn = 64;
    var kb = key ? c11BUtf8(key) : [];
    if (kb.length > 64) kb = kb.slice(0, 64);
    var msg = c11BUtf8(t), i, j, r, s;
    var h = [], v = [], m = [], block, blocks = [], t0 = 0;
    for (i = 0; i < 8; i++) { h.push(C11B_B2B_IV_HI[i]); h.push(C11B_B2B_IV_LO[i]); }
    h[1] = (h[1] ^ (0x01010000 ^ (kb.length << 8) ^ nn)) >>> 0;
    if (kb.length) {
      block = kb.slice();
      while (block.length < 128) block.push(0);
      blocks.push({ b: block, n: 128 });
    }
    for (i = 0; i < msg.length; i += 128) {
      block = [];
      for (j = 0; j < 128; j++) block.push(j + i < msg.length ? (msg[i + j] & 0xFF) : 0);
      blocks.push({ b: block, n: Math.min(128, msg.length - i) });
    }
    if (!blocks.length) {
      block = [];
      for (j = 0; j < 128; j++) block.push(0);
      blocks.push({ b: block, n: 0 });
    }
    for (i = 0; i < blocks.length; i++) {
      block = blocks[i].b;
      t0 += blocks[i].n;
      for (j = 0; j < 16; j++) {
        m[2 * j] = c11BLe32(block, 8 * j + 4);
        m[2 * j + 1] = c11BLe32(block, 8 * j);
      }
      for (j = 0; j < 8; j++) {
        v[2 * j] = h[2 * j]; v[2 * j + 1] = h[2 * j + 1];
        v[16 + 2 * j] = C11B_B2B_IV_HI[j]; v[16 + 2 * j + 1] = C11B_B2B_IV_LO[j];
      }
      v[25] = (v[25] ^ (t0 % 0x100000000)) >>> 0;
      v[24] = (v[24] ^ Math.floor(t0 / 0x100000000)) >>> 0;
      if (i === blocks.length - 1) { v[28] = (~v[28]) >>> 0; v[29] = (~v[29]) >>> 0; }
      for (r = 0; r < 12; r++) {
        s = C11B_SIGMA[r % 10];
        c11BB2bG(v, 0, 4, 8, 12, m[2 * s[0]], m[2 * s[0] + 1], m[2 * s[1]], m[2 * s[1] + 1]);
        c11BB2bG(v, 1, 5, 9, 13, m[2 * s[2]], m[2 * s[2] + 1], m[2 * s[3]], m[2 * s[3] + 1]);
        c11BB2bG(v, 2, 6, 10, 14, m[2 * s[4]], m[2 * s[4] + 1], m[2 * s[5]], m[2 * s[5] + 1]);
        c11BB2bG(v, 3, 7, 11, 15, m[2 * s[6]], m[2 * s[6] + 1], m[2 * s[7]], m[2 * s[7] + 1]);
        c11BB2bG(v, 0, 5, 10, 15, m[2 * s[8]], m[2 * s[8] + 1], m[2 * s[9]], m[2 * s[9] + 1]);
        c11BB2bG(v, 1, 6, 11, 12, m[2 * s[10]], m[2 * s[10] + 1], m[2 * s[11]], m[2 * s[11] + 1]);
        c11BB2bG(v, 2, 7, 8, 13, m[2 * s[12]], m[2 * s[12] + 1], m[2 * s[13]], m[2 * s[13] + 1]);
        c11BB2bG(v, 3, 4, 9, 14, m[2 * s[14]], m[2 * s[14] + 1], m[2 * s[15]], m[2 * s[15] + 1]);
      }
      for (j = 0; j < 8; j++) {
        h[2 * j] = (h[2 * j] ^ v[2 * j] ^ v[16 + 2 * j]) >>> 0;
        h[2 * j + 1] = (h[2 * j + 1] ^ v[2 * j + 1] ^ v[17 + 2 * j]) >>> 0;
      }
    }
    var out = [];
    for (i = 0; i < 8 && out.length < nn; i++) {
      out.push(h[2 * i + 1] & 0xFF); out.push((h[2 * i + 1] >>> 8) & 0xFF);
      out.push((h[2 * i + 1] >>> 16) & 0xFF); out.push((h[2 * i + 1] >>> 24) & 0xFF);
      out.push(h[2 * i] & 0xFF); out.push((h[2 * i] >>> 8) & 0xFF);
      out.push((h[2 * i] >>> 16) & 0xFF); out.push((h[2 * i] >>> 24) & 0xFF);
    }
    return c11BHex(out.slice(0, nn));
  }

  function c11BB2sG(v, a, b, c, d, x, y) {
    v[a] = (v[a] + v[b] + x) >>> 0;
    v[d] = c11BRotr(v[d] ^ v[a], 16);
    v[c] = (v[c] + v[d]) >>> 0;
    v[b] = c11BRotr(v[b] ^ v[c], 12);
    v[a] = (v[a] + v[b] + y) >>> 0;
    v[d] = c11BRotr(v[d] ^ v[a], 8);
    v[c] = (v[c] + v[d]) >>> 0;
    v[b] = c11BRotr(v[b] ^ v[c], 7);
  }

  function c11BBlake2s(t, outBits, key) {
    var nn = c11BInt(outBits, 256) / 8;
    if (nn < 1 || nn > 32) nn = 32;
    var kb = key ? c11BUtf8(key) : [];
    if (kb.length > 32) kb = kb.slice(0, 32);
    var msg = c11BUtf8(t), i, j, r, s;
    var h = [], v = [], m = [], block, blocks = [], t0 = 0;
    for (i = 0; i < 8; i++) h.push(C11B_B2S_IV[i]);
    h[0] = (h[0] ^ (0x01010000 ^ (kb.length << 8) ^ nn)) >>> 0;
    if (kb.length) {
      block = kb.slice();
      while (block.length < 64) block.push(0);
      blocks.push({ b: block, n: 64 });
    }
    for (i = 0; i < msg.length; i += 64) {
      block = [];
      for (j = 0; j < 64; j++) block.push(j + i < msg.length ? (msg[i + j] & 0xFF) : 0);
      blocks.push({ b: block, n: Math.min(64, msg.length - i) });
    }
    if (!blocks.length) {
      block = [];
      for (j = 0; j < 64; j++) block.push(0);
      blocks.push({ b: block, n: 0 });
    }
    for (i = 0; i < blocks.length; i++) {
      block = blocks[i].b;
      t0 += blocks[i].n;
      for (j = 0; j < 16; j++) m[j] = c11BLe32(block, 4 * j);
      for (j = 0; j < 8; j++) { v[j] = h[j]; v[8 + j] = C11B_B2S_IV[j]; }
      v[12] = (v[12] ^ (t0 % 0x100000000)) >>> 0;
      v[13] = (v[13] ^ Math.floor(t0 / 0x100000000)) >>> 0;
      if (i === blocks.length - 1) v[14] = (~v[14]) >>> 0;
      for (r = 0; r < 10; r++) {
        s = C11B_SIGMA[r % 10];
        c11BB2sG(v, 0, 4, 8, 12, m[s[0]], m[s[1]]);
        c11BB2sG(v, 1, 5, 9, 13, m[s[2]], m[s[3]]);
        c11BB2sG(v, 2, 6, 10, 14, m[s[4]], m[s[5]]);
        c11BB2sG(v, 3, 7, 11, 15, m[s[6]], m[s[7]]);
        c11BB2sG(v, 0, 5, 10, 15, m[s[8]], m[s[9]]);
        c11BB2sG(v, 1, 6, 11, 12, m[s[10]], m[s[11]]);
        c11BB2sG(v, 2, 7, 8, 13, m[s[12]], m[s[13]]);
        c11BB2sG(v, 3, 4, 9, 14, m[s[14]], m[s[15]]);
      }
      for (j = 0; j < 8; j++) h[j] = (h[j] ^ v[j] ^ v[8 + j]) >>> 0;
    }
    var out = [];
    for (i = 0; i < 8 && out.length < nn; i++) {
      out.push(h[i] & 0xFF); out.push((h[i] >>> 8) & 0xFF);
      out.push((h[i] >>> 16) & 0xFF); out.push((h[i] >>> 24) & 0xFF);
    }
    return c11BHex(out.slice(0, nn));
  }

  /* ================================================================
     57. RIPEMD-160
     ================================================================ */
  var C11B_RMD_ZL = [
    0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15,
    7, 4, 13, 1, 10, 6, 15, 3, 12, 0, 9, 5, 2, 14, 11, 8,
    3, 10, 14, 4, 9, 15, 8, 1, 2, 7, 0, 6, 13, 11, 5, 12,
    1, 9, 11, 10, 0, 8, 12, 4, 13, 3, 7, 15, 14, 5, 6, 2,
    4, 0, 5, 9, 7, 12, 2, 10, 14, 1, 3, 8, 11, 6, 15, 13
  ];
  var C11B_RMD_ZR = [
    5, 14, 7, 0, 9, 2, 11, 4, 13, 6, 15, 8, 1, 10, 3, 12,
    6, 11, 3, 7, 0, 13, 5, 10, 14, 15, 8, 12, 4, 9, 1, 2,
    15, 5, 1, 3, 7, 14, 6, 9, 11, 8, 12, 2, 10, 0, 4, 13,
    8, 6, 4, 1, 3, 11, 15, 0, 5, 12, 2, 13, 9, 7, 10, 14,
    12, 15, 10, 4, 1, 5, 8, 7, 6, 2, 13, 14, 0, 3, 9, 11
  ];
  var C11B_RMD_SL = [
    11, 14, 15, 12, 5, 8, 7, 9, 11, 13, 14, 15, 6, 7, 9, 8,
    7, 6, 8, 13, 11, 9, 7, 15, 7, 12, 15, 9, 11, 7, 13, 12,
    11, 13, 6, 7, 14, 9, 13, 15, 14, 8, 13, 6, 5, 12, 7, 5,
    11, 12, 14, 15, 14, 15, 9, 8, 9, 14, 5, 6, 8, 6, 5, 12,
    9, 15, 5, 11, 6, 8, 13, 12, 5, 12, 13, 14, 11, 8, 5, 6
  ];
  var C11B_RMD_SR = [
    8, 9, 9, 11, 13, 15, 15, 5, 7, 7, 8, 11, 14, 14, 12, 6,
    9, 13, 15, 7, 12, 8, 9, 11, 7, 7, 12, 7, 6, 15, 13, 11,
    9, 7, 15, 11, 8, 6, 6, 14, 12, 13, 5, 14, 13, 13, 7, 5,
    15, 5, 8, 11, 14, 14, 6, 14, 6, 9, 12, 9, 12, 5, 15, 8,
    8, 5, 12, 9, 12, 5, 14, 6, 8, 13, 6, 5, 15, 13, 11, 11
  ];

  function c11BRmdF(j, x, y, z) {
    if (j < 16) return (x ^ y ^ z) >>> 0;
    if (j < 32) return ((x & y) | ((~x) & z)) >>> 0;
    if (j < 48) return ((x | (~y)) ^ z) >>> 0;
    if (j < 64) return ((x & z) | (y & (~z))) >>> 0;
    return (x ^ (y | (~z))) >>> 0;
  }

  function c11BRipemd160(t) {
    var m = c11BUtf8(t), n = m.length, i, j, T, X = [];
    var h0 = 0x67452301, h1 = 0xefcdab89, h2 = 0x98badcfe, h3 = 0x10325476, h4 = 0xc3d2e1f0;
    var A, B, C, D, E, A2, B2, C2, D2, E2, KL, KR;
    var msg = m.slice();
    msg.push(0x80);
    while (msg.length % 64 !== 56) msg.push(0);
    var bits = n * 8, hi = Math.floor(bits / 0x100000000), lo = bits % 0x100000000;
    msg.push(lo & 0xFF); msg.push((lo >>> 8) & 0xFF); msg.push((lo >>> 16) & 0xFF); msg.push((lo >>> 24) & 0xFF);
    msg.push(hi & 0xFF); msg.push((hi >>> 8) & 0xFF); msg.push((hi >>> 16) & 0xFF); msg.push((hi >>> 24) & 0xFF);
    for (i = 0; i < msg.length; i += 64) {
      for (j = 0; j < 16; j++) X[j] = c11BLe32(msg, i + 4 * j);
      A = h0; B = h1; C = h2; D = h3; E = h4;
      A2 = h0; B2 = h1; C2 = h2; D2 = h3; E2 = h4;
      for (j = 0; j < 80; j++) {
        KL = j < 16 ? 0x00000000 : (j < 32 ? 0x5A827999 : (j < 48 ? 0x6ED9EBA1 : (j < 64 ? 0x8F1BBCDC : 0xA953FD4E)));
        KR = j < 16 ? 0x50A28BE6 : (j < 32 ? 0x5C4DD124 : (j < 48 ? 0x6D703EF3 : (j < 64 ? 0x7A6D76E9 : 0x00000000)));
        T = (c11BRotl((A + c11BRmdF(j, B, C, D) + X[C11B_RMD_ZL[j]] + KL) >>> 0, C11B_RMD_SL[j]) + E) >>> 0;
        A = E; E = D; D = c11BRotl(C, 10); C = B; B = T;
        T = (c11BRotl((A2 + c11BRmdF(79 - j, B2, C2, D2) + X[C11B_RMD_ZR[j]] + KR) >>> 0, C11B_RMD_SR[j]) + E2) >>> 0;
        A2 = E2; E2 = D2; D2 = c11BRotl(C2, 10); C2 = B2; B2 = T;
      }
      T = (h1 + C + D2) >>> 0;
      h1 = (h2 + D + E2) >>> 0;
      h2 = (h3 + E + A2) >>> 0;
      h3 = (h4 + A + B2) >>> 0;
      h4 = (h0 + B + C2) >>> 0;
      h0 = T;
    }
    var out = [], hv = [h0, h1, h2, h3, h4];
    for (i = 0; i < 5; i++) {
      out.push(hv[i] & 0xFF); out.push((hv[i] >>> 8) & 0xFF);
      out.push((hv[i] >>> 16) & 0xFF); out.push((hv[i] >>> 24) & 0xFF);
    }
    return c11BHex(out);
  }

  /* ================================================================
     58. SM3 (Chinese national hash, 256 bit)
     ================================================================ */
  var C11B_SM3_IV = [0x7380166f, 0x4914b2b9, 0x172442d7, 0xda8a0600, 0xa96f30bc, 0x163138aa, 0xe38dee4d, 0xb0fb0e4e];

  function c11BSm3P0(x) { return ((x) ^ c11BRotl(x, 9) ^ c11BRotl(x, 17)) >>> 0; }
  function c11BSm3P1(x) { return ((x) ^ c11BRotl(x, 15) ^ c11BRotl(x, 23)) >>> 0; }
  function c11BSm3FF(j, x, y, z) { return j < 16 ? ((x ^ y ^ z) >>> 0) : (((x & y) | (x & z) | (y & z)) >>> 0); }
  function c11BSm3GG(j, x, y, z) { return j < 16 ? ((x ^ y ^ z) >>> 0) : (((x & y) | ((~x) & z)) >>> 0); }

  function c11BSm3(t) {
    var m = c11BUtf8(t), n = m.length, i, j, V, W, W1, A, B, C, D, E, F, G, H, ss1, ss2, tt1, tt2, Tj;
    var msg = m.slice();
    msg.push(0x80);
    while (msg.length % 64 !== 56) msg.push(0);
    var bits = n * 8, hi = Math.floor(bits / 0x100000000), lo = bits % 0x100000000;
    msg.push((hi >>> 24) & 0xFF); msg.push((hi >>> 16) & 0xFF); msg.push((hi >>> 8) & 0xFF); msg.push(hi & 0xFF);
    msg.push((lo >>> 24) & 0xFF); msg.push((lo >>> 16) & 0xFF); msg.push((lo >>> 8) & 0xFF); msg.push(lo & 0xFF);
    V = C11B_SM3_IV.slice();
    W = []; W1 = [];
    for (i = 0; i < msg.length; i += 64) {
      for (j = 0; j < 16; j++) W[j] = c11BBe32(msg, i + 4 * j);
      for (j = 16; j < 68; j++) {
        W[j] = (c11BSm3P1((W[j - 16] ^ W[j - 9] ^ c11BRotl(W[j - 3], 15)) >>> 0) ^ c11BRotl(W[j - 13], 7) ^ W[j - 6]) >>> 0;
      }
      for (j = 0; j < 64; j++) W1[j] = (W[j] ^ W[j + 4]) >>> 0;
      A = V[0]; B = V[1]; C = V[2]; D = V[3]; E = V[4]; F = V[5]; G = V[6]; H = V[7];
      for (j = 0; j < 64; j++) {
        Tj = j < 16 ? 0x79cc4519 : 0x7a879d8a;
        ss1 = c11BRotl((c11BRotl(A, 12) + E + c11BRotl(Tj, j % 32)) >>> 0, 7);
        ss2 = (ss1 ^ c11BRotl(A, 12)) >>> 0;
        tt1 = (c11BSm3FF(j, A, B, C) + D + ss2 + W1[j]) >>> 0;
        tt2 = (c11BSm3GG(j, E, F, G) + H + ss1 + W[j]) >>> 0;
        D = C; C = c11BRotl(B, 9); B = A; A = tt1;
        H = G; G = c11BRotl(F, 19); F = E; E = c11BSm3P0(tt2);
      }
      V[0] = (V[0] ^ A) >>> 0; V[1] = (V[1] ^ B) >>> 0; V[2] = (V[2] ^ C) >>> 0; V[3] = (V[3] ^ D) >>> 0;
      V[4] = (V[4] ^ E) >>> 0; V[5] = (V[5] ^ F) >>> 0; V[6] = (V[6] ^ G) >>> 0; V[7] = (V[7] ^ H) >>> 0;
    }
    var out = [];
    for (i = 0; i < 8; i++) {
      out.push((V[i] >>> 24) & 0xFF); out.push((V[i] >>> 16) & 0xFF);
      out.push((V[i] >>> 8) & 0xFF); out.push(V[i] & 0xFF);
    }
    return c11BHex(out);
  }

  /* ================================================================
     59. Whirlpool (ISO/IEC 10118-3)  --  tables generated at first use
     ================================================================ */
  var C11B_WP = null;

  function c11BWhirlpoolInit() {
    if (C11B_WP) return;
    var eBOX = [0x1, 0xb, 0x9, 0xc, 0xd, 0x6, 0xf, 0x3, 0xe, 0x8, 0x7, 0x4, 0xa, 0x2, 0x5, 0x0];
    var rBOX = [0x7, 0xc, 0xb, 0xd, 0xe, 0x4, 0x9, 0xf, 0x6, 0x3, 0x8, 0xa, 0x2, 0x5, 0x1, 0x0];
    var theta = [1, 1, 4, 1, 8, 5, 2, 9];
    var iBOX = [], SBOX = [], C = [], RC = [];
    var i, t, left, right, temp, V;
    for (i = 0; i < 16; i++) iBOX[eBOX[i]] = i;
    for (i = 0; i < 256; i++) {
      left = eBOX[i >> 4]; right = iBOX[i & 15]; temp = rBOX[left ^ right];
      SBOX[i] = (eBOX[left ^ temp] << 4) | iBOX[right ^ temp];
    }
    for (t = 0; t < 8; t++) C[t] = [];
    for (i = 0; i < 256; i++) {
      V = [];
      V[1] = SBOX[i];
      V[2] = V[1] << 1; if (V[2] >= 0x100) V[2] ^= 0x11d;
      V[3] = V[2] ^ V[1];
      V[4] = V[2] << 1; if (V[4] >= 0x100) V[4] ^= 0x11d;
      V[5] = V[4] ^ V[1];
      V[8] = V[4] << 1; if (V[8] >= 0x100) V[8] ^= 0x11d;
      V[9] = V[8] ^ V[1];
      C[0][i * 2] = ((V[theta[0]] << 24) | (V[theta[1]] << 16) | (V[theta[2]] << 8) | V[theta[3]]) >>> 0;
      C[0][i * 2 + 1] = ((V[theta[4]] << 24) | (V[theta[5]] << 16) | (V[theta[6]] << 8) | V[theta[7]]) >>> 0;
      for (t = 1; t < 8; t++) {
        C[t][i * 2] = c11BRotr64L(C[0][i * 2], C[0][i * 2 + 1], t << 3);
        C[t][i * 2 + 1] = c11BRotr64H(C[0][i * 2], C[0][i * 2 + 1], t << 3);
      }
    }
    RC[0] = 0; RC[1] = 0;
    for (i = 1; i <= 10; i++) {
      RC[i * 2] = ((C[0][16 * i - 16] & 0xff000000) ^ (C[1][16 * i - 14] & 0x00ff0000) ^
        (C[2][16 * i - 12] & 0x0000ff00) ^ (C[3][16 * i - 10] & 0x000000ff)) >>> 0;
      RC[i * 2 + 1] = ((C[4][16 * i - 7] & 0xff000000) ^ (C[5][16 * i - 5] & 0x00ff0000) ^
        (C[6][16 * i - 3] & 0x0000ff00) ^ (C[7][16 * i - 1] & 0x000000ff)) >>> 0;
    }
    C11B_WP = { C: C, RC: RC };
  }

  function c11BWhirlpool(t) {
    c11BWhirlpoolInit();
    var C = C11B_WP.C, RC = C11B_WP.RC;
    var m = c11BUtf8(t), n = m.length, i, j, r, s, kk, jj, out;
    var msg = m.slice(), rem = n % 64, padLen = (rem < 56) ? (56 - rem) : (120 - rem);
    msg.push(0x80);
    for (i = 1; i < padLen; i++) msg.push(0);
    var bits = n * 8, hi = Math.floor(bits / 0x100000000), lo = bits % 0x100000000;
    msg.push((hi >>> 24) & 0xFF); msg.push((hi >>> 16) & 0xFF); msg.push((hi >>> 8) & 0xFF); msg.push(hi & 0xFF);
    msg.push((lo >>> 24) & 0xFF); msg.push((lo >>> 16) & 0xFF); msg.push((lo >>> 8) & 0xFF); msg.push(lo & 0xFF);
    var hash = [], state, K, L, block;
    for (i = 0; i < 16; i++) hash.push(0);
    for (i = 0; i < msg.length; i += 64) {
      block = [];
      for (j = 0; j < 16; j++) block.push(c11BBe32(msg, i + 4 * j));
      state = []; K = hash.slice();
      for (j = 0; j < 16; j++) state.push((block[j] ^ K[j]) >>> 0);
      for (r = 1; r <= 10; r++) {
        L = [];
        for (j = 0; j < 8; j++) {
          L[j * 2] = 0; L[j * 2 + 1] = 0;
          for (kk = 0, s = 56, jj = 0; kk < 8; kk++, s -= 8, jj = s < 32 ? 1 : 0) {
            L[j * 2] = (L[j * 2] ^ C[kk][((K[((j - kk) & 7) * 2 + jj] >>> (s % 32)) & 0xff) * 2]) >>> 0;
            L[j * 2 + 1] = (L[j * 2 + 1] ^ C[kk][((K[((j - kk) & 7) * 2 + jj] >>> (s % 32)) & 0xff) * 2 + 1]) >>> 0;
          }
        }
        for (j = 0; j < 16; j++) K[j] = L[j];
        K[0] = (K[0] ^ RC[r * 2]) >>> 0;
        K[1] = (K[1] ^ RC[r * 2 + 1]) >>> 0;
        for (j = 0; j < 8; j++) {
          L[j * 2] = K[j * 2];
          L[j * 2 + 1] = K[j * 2 + 1];
          for (kk = 0, s = 56, jj = 0; kk < 8; kk++, s -= 8, jj = s < 32 ? 1 : 0) {
            L[j * 2] = (L[j * 2] ^ C[kk][((state[((j - kk) & 7) * 2 + jj] >>> (s % 32)) & 0xff) * 2]) >>> 0;
            L[j * 2 + 1] = (L[j * 2 + 1] ^ C[kk][((state[((j - kk) & 7) * 2 + jj] >>> (s % 32)) & 0xff) * 2 + 1]) >>> 0;
          }
        }
        for (j = 0; j < 16; j++) state[j] = L[j];
      }
      for (j = 0; j < 16; j++) hash[j] = (hash[j] ^ state[j] ^ block[j]) >>> 0;
    }
    out = [];
    for (i = 0; i < 16; i++) {
      out.push((hash[i] >>> 24) & 0xFF); out.push((hash[i] >>> 16) & 0xFF);
      out.push((hash[i] >>> 8) & 0xFF); out.push(hash[i] & 0xFF);
    }
    return c11BHex(out);
  }

  /* ================================================================
     60. MD4  +  NTLM (MD4 of UTF-16LE password)
     ================================================================ */
  var C11B_MD4_R2 = [0, 4, 8, 12, 1, 5, 9, 13, 2, 6, 10, 14, 3, 7, 11, 15];
  var C11B_MD4_R3 = [0, 8, 4, 12, 2, 10, 6, 14, 1, 9, 5, 13, 3, 11, 7, 15];

  function c11BMd4(bytes) {
    var n = bytes.length, i, j, X = [], msg = bytes.slice();
    var a0 = 0x67452301, b0 = 0xefcdab89, c0 = 0x98badcfe, d0 = 0x10325476;
    var A, B, C, D, AA, BB, CC, DD;
    msg.push(0x80);
    while (msg.length % 64 !== 56) msg.push(0);
    var bits = n * 8, lo = bits % 0x100000000, hi = Math.floor(bits / 0x100000000);
    msg.push(lo & 0xFF); msg.push((lo >>> 8) & 0xFF); msg.push((lo >>> 16) & 0xFF); msg.push((lo >>> 24) & 0xFF);
    msg.push(hi & 0xFF); msg.push((hi >>> 8) & 0xFF); msg.push((hi >>> 16) & 0xFF); msg.push((hi >>> 24) & 0xFF);
    for (i = 0; i < msg.length; i += 64) {
      for (j = 0; j < 16; j++) X[j] = c11BLe32(msg, i + 4 * j);
      AA = A = a0; BB = B = b0; CC = C = c0; DD = D = d0;
      for (j = 0; j < 16; j += 4) {
        A = c11BRotl((A + ((B & C) | ((~B) & D)) + X[j]) >>> 0, 3);
        D = c11BRotl((D + ((A & B) | ((~A) & C)) + X[j + 1]) >>> 0, 7);
        C = c11BRotl((C + ((D & A) | ((~D) & B)) + X[j + 2]) >>> 0, 11);
        B = c11BRotl((B + ((C & D) | ((~C) & A)) + X[j + 3]) >>> 0, 19);
      }
      for (j = 0; j < 4; j++) {
        A = c11BRotl((A + (((B & C) | (B & D) | (C & D))) + X[C11B_MD4_R2[j * 4]] + 0x5a827999) >>> 0, 3);
        D = c11BRotl((D + (((A & B) | (A & C) | (B & C))) + X[C11B_MD4_R2[j * 4 + 1]] + 0x5a827999) >>> 0, 5);
        C = c11BRotl((C + (((D & A) | (D & B) | (A & B))) + X[C11B_MD4_R2[j * 4 + 2]] + 0x5a827999) >>> 0, 9);
        B = c11BRotl((B + (((C & D) | (C & A) | (D & A))) + X[C11B_MD4_R2[j * 4 + 3]] + 0x5a827999) >>> 0, 13);
      }
      for (j = 0; j < 4; j++) {
        A = c11BRotl((A + ((B ^ C ^ D)) + X[C11B_MD4_R3[j * 4]] + 0x6ed9eba1) >>> 0, 3);
        D = c11BRotl((D + ((A ^ B ^ C)) + X[C11B_MD4_R3[j * 4 + 1]] + 0x6ed9eba1) >>> 0, 9);
        C = c11BRotl((C + ((D ^ A ^ B)) + X[C11B_MD4_R3[j * 4 + 2]] + 0x6ed9eba1) >>> 0, 11);
        B = c11BRotl((B + ((C ^ D ^ A)) + X[C11B_MD4_R3[j * 4 + 3]] + 0x6ed9eba1) >>> 0, 15);
      }
      a0 = (a0 + A) >>> 0; b0 = (b0 + B) >>> 0; c0 = (c0 + C) >>> 0; d0 = (d0 + D) >>> 0;
    }
    var out = [], hv = [a0, b0, c0, d0];
    for (i = 0; i < 4; i++) {
      out.push(hv[i] & 0xFF); out.push((hv[i] >>> 8) & 0xFF);
      out.push((hv[i] >>> 16) & 0xFF); out.push((hv[i] >>> 24) & 0xFF);
    }
    return out;
  }

  function c11BNtlm(t) {
    return c11BHex(c11BMd4(c11BUtf16le(t)));
  }

  /* ================================================================
     61. xxHash32
     ================================================================ */
  function c11BMul32(a, b) {
    var ah = (a >>> 16) & 0xFFFF, al = a & 0xFFFF, bh = (b >>> 16) & 0xFFFF, bl = b & 0xFFFF;
    return (((al * bl) >>> 0) + ((((ah * bl + al * bh) & 0xFFFF) << 16) >>> 0)) >>> 0;
  }

  function c11BXxhRound(acc, input) {
    acc = (acc + c11BMul32(input, 0x85EBCA77)) >>> 0;
    return c11BMul32(c11BRotl(acc, 13), 0x9E3779B1);
  }

  function c11BXxhash32(t, seed) {
    var m = c11BUtf8(t), len = m.length, i = 0, h, v1, v2, v3, v4, limit;
    seed = (seed === undefined || seed === null || isNaN(seed)) ? 0 : (parseInt(seed, 10) >>> 0);
    if (len >= 16) {
      v1 = (seed + 0x9E3779B1 + 0x85EBCA77) >>> 0;
      v2 = (seed + 0x85EBCA77) >>> 0;
      v3 = seed >>> 0;
      v4 = (seed - 0x9E3779B1) >>> 0;
      limit = len - 16;
      while (i <= limit) {
        v1 = c11BXxhRound(v1, c11BLe32(m, i)); i += 4;
        v2 = c11BXxhRound(v2, c11BLe32(m, i)); i += 4;
        v3 = c11BXxhRound(v3, c11BLe32(m, i)); i += 4;
        v4 = c11BXxhRound(v4, c11BLe32(m, i)); i += 4;
      }
      h = (c11BRotl(v1, 1) + c11BRotl(v2, 7) + c11BRotl(v3, 12) + c11BRotl(v4, 18)) >>> 0;
    } else {
      h = (seed + 0x165667B1) >>> 0;
    }
    h = (h + len) >>> 0;
    while (i + 4 <= len) {
      h = (h + c11BMul32(c11BLe32(m, i), 0xC2B2AE3D)) >>> 0;
      h = c11BMul32(c11BRotl(h, 17), 0x27D4EB2F);
      i += 4;
    }
    while (i < len) {
      h = (h + c11BMul32(m[i] & 0xFF, 0x165667B1)) >>> 0;
      h = c11BMul32(c11BRotl(h, 11), 0x9E3779B1);
      i += 1;
    }
    h = (h ^ (h >>> 15)) >>> 0;
    h = c11BMul32(h, 0x85EBCA77);
    h = (h ^ (h >>> 13)) >>> 0;
    h = c11BMul32(h, 0xC2B2AE3D);
    h = (h ^ (h >>> 16)) >>> 0;
    return c11BHex([(h >>> 24) & 0xFF, (h >>> 16) & 0xFF, (h >>> 8) & 0xFF, h & 0xFF]);
  }

  /* ================================================================
     62. CRC-8 / CRC-16  (generic width 8 / 16 bit, reflected or not)
     ================================================================ */
  var C11B_CRC8_P = {
    smbus: { poly: 0x07, init: 0x00, refin: false, refout: false, xorout: 0x00 },
    maxim: { poly: 0x31, init: 0x00, refin: true, refout: true, xorout: 0x00 },
    icode: { poly: 0x1d, init: 0xfd, refin: false, refout: false, xorout: 0x00 },
    cdma2000: { poly: 0x9b, init: 0xff, refin: false, refout: false, xorout: 0x00 },
    darc: { poly: 0x39, init: 0x00, refin: true, refout: true, xorout: 0x00 }
  };
  var C11B_CRC16_P = {
    'ccitt-false': { poly: 0x1021, init: 0xffff, refin: false, refout: false, xorout: 0x0000 },
    arc: { poly: 0x8005, init: 0x0000, refin: true, refout: true, xorout: 0x0000 },
    modbus: { poly: 0x8005, init: 0xffff, refin: true, refout: true, xorout: 0x0000 },
    'x-25': { poly: 0x1021, init: 0xffff, refin: true, refout: true, xorout: 0xffff },
    xmodem: { poly: 0x1021, init: 0x0000, refin: false, refout: false, xorout: 0x0000 },
    kermit: { poly: 0x1021, init: 0x0000, refin: true, refout: true, xorout: 0x0000 },
    'aug-ccitt': { poly: 0x1021, init: 0x1d0f, refin: false, refout: false, xorout: 0x0000 },
    'usb': { poly: 0x8005, init: 0xffff, refin: true, refout: true, xorout: 0xffff }
  };

  function c11BRevBits(v, w) {
    var r = 0, i;
    for (i = 0; i < w; i++) r = ((r << 1) | ((v >>> i) & 1)) >>> 0;
    return r >>> 0;
  }

  function c11BCrc(t, opt) {
    var o = c11BOpt(opt), width = c11BInt(o.width, 16), pre, p;
    pre = (width === 8) ? C11B_CRC8_P[o.preset || 'smbus'] : C11B_CRC16_P[o.preset || 'modbus'];
    if (!pre) pre = (width === 8) ? C11B_CRC8_P.smbus : C11B_CRC16_P.modbus;
    p = (o.poly !== undefined && o.poly !== null && o.poly !== '') ? (parseInt(o.poly, 16) & 0xFFFF) : pre.poly;
    var init = (o.init !== undefined && o.init !== null && o.init !== '') ? (parseInt(o.init, 16) & 0xFFFF) : pre.init;
    var xorout = (o.xorout !== undefined && o.xorout !== null && o.xorout !== '') ? (parseInt(o.xorout, 16) & 0xFFFF) : pre.xorout;
    var refin = (o.refin !== undefined) ? !!o.refin : pre.refin;
    var refout = (o.refout !== undefined) ? !!o.refout : pre.refout;
    var mask = (width === 8) ? 0xFF : 0xFFFF;
    var top = (width === 8) ? 0x80 : 0x8000;
    var crc = init & mask, i, j, b, m = c11BUtf8(t);
    if (refin) {
      /* reflected (right shifting) variant, polynomial used in reversed form */
      p = c11BRevBits(p, width) & mask;
      for (i = 0; i < m.length; i++) {
        crc = (crc ^ (m[i] & 0xFF)) & mask;
        for (j = 0; j < 8; j++) {
          if (crc & 1) crc = ((crc >>> 1) ^ p) & mask;
          else crc = (crc >>> 1) & mask;
        }
      }
      if (!refout) crc = c11BRevBits(crc, width) & mask;
    } else {
      for (i = 0; i < m.length; i++) {
        crc = (crc ^ ((m[i] & 0xFF) << (width - 8))) & mask;
        for (j = 0; j < 8; j++) {
          if (crc & top) crc = ((crc << 1) ^ p) & mask;
          else crc = (crc << 1) & mask;
        }
      }
      if (refout) crc = c11BRevBits(crc, width) & mask;
    }
    crc = (crc ^ xorout) & mask;
    if (o.fmt === 'dec') return String(crc);
    if (o.fmt === 'bin') {
      var s2 = crc.toString(2);
      while (s2.length < width) s2 = '0' + s2;
      return s2;
    }
    var s = crc.toString(16).toUpperCase();
    while (s.length < width / 4) s = '0' + s;
    return s;
  }

  function c11BCrc8(t, opt) {
    var o = c11BOpt(opt); o.width = 8;
    if (!o.preset) o.preset = 'smbus';
    return c11BCrc(t, o);
  }

  function c11BCrc16(t, opt) {
    var o = c11BOpt(opt); o.width = 16;
    if (!o.preset) o.preset = 'modbus';
    return c11BCrc(t, o);
  }

  /* ================================================================
     63. Luhn checksum
     ================================================================ */
  function c11BLuhnSum(d) {
    var sum = 0, alt = false, i, v;
    for (i = d.length - 1; i >= 0; i--) {
      v = parseInt(d.charAt(i), 10);
      if (isNaN(v)) continue;
      if (alt) { v *= 2; if (v > 9) v -= 9; }
      sum += v;
      alt = !alt;
    }
    return sum;
  }

  function c11BLuhn(t, opt) {
    var o = c11BOpt(opt), mode = o.mode || 'validate';
    var s = c11BStr(t), d = s.replace(/[^0-9]/g, ''), cd;
    if (!d.length) return '';
    if (mode === 'generate' || mode === 'checkdigit') {
      cd = (10 - (c11BLuhnSum(d + '0') % 10)) % 10;
      if (mode === 'checkdigit') return String(cd);
      return d + cd;
    }
    if (c11BLuhnSum(d) % 10 === 0) return 'VALID';
    cd = (10 - (c11BLuhnSum(d.substring(0, d.length - 1) + '0') % 10)) % 10;
    return 'INVALID (expected check digit ' + cd + ')';
  }

  /* ================================================================
     64. Hamming (7,4)
     ================================================================ */
  function c11BHamming(t, opt) {
    var o = c11BOpt(opt), mode = o.mode === 'decode' ? 'decode' : 'encode';
    var s = c11BStr(t).replace(/[^01]/g, ''), i, out = [], n, d1, d2, d3, d4, p1, p2, p4, b, s1, s2, s4, pos, fix, notes = [];
    if (mode === 'encode') {
      for (i = 0; i + 4 <= s.length + 1 && i < s.length; i += 4) {
        if (i + 4 > s.length) break;
        n = parseInt(s.substr(i, 4), 2);
        d1 = n & 1; d2 = (n >> 1) & 1; d3 = (n >> 2) & 1; d4 = (n >> 3) & 1;
        p1 = d1 ^ d2 ^ d4; p2 = d1 ^ d3 ^ d4; p4 = d2 ^ d3 ^ d4;
        out.push('' + p1 + p2 + d1 + p4 + d2 + d3 + d4);
      }
      return out.join(' ');
    }
    for (i = 0; i + 7 <= s.length; i += 7) {
      b = s.substr(i, 7);
      s1 = (parseInt(b.charAt(0), 10) ^ parseInt(b.charAt(2), 10) ^ parseInt(b.charAt(4), 10) ^ parseInt(b.charAt(6), 10));
      s2 = (parseInt(b.charAt(1), 10) ^ parseInt(b.charAt(2), 10) ^ parseInt(b.charAt(5), 10) ^ parseInt(b.charAt(6), 10));
      s4 = (parseInt(b.charAt(3), 10) ^ parseInt(b.charAt(4), 10) ^ parseInt(b.charAt(5), 10) ^ parseInt(b.charAt(6), 10));
      pos = s1 + 2 * s2 + 4 * s4;
      fix = b;
      if (pos > 0 && pos <= 7) {
        fix = b.substring(0, pos - 1) + (b.charAt(pos - 1) === '0' ? '1' : '0') + b.substring(pos);
        if (o.report) notes.push('group ' + (i / 7 + 1) + ': bit ' + pos + ' corrected');
      }
      d1 = parseInt(fix.charAt(2), 10); d2 = parseInt(fix.charAt(4), 10);
      d3 = parseInt(fix.charAt(5), 10); d4 = parseInt(fix.charAt(6), 10);
      out.push('' + d4 + d3 + d2 + d1);
    }
    return out.join(' ') + (notes.length ? '   ' + notes.join(', ') : '');
  }

  /* ================================================================
     65. Soundex (NARA variant)
     ================================================================ */
  var C11B_SOUNDEX_MAP = {
    B: '1', F: '1', P: '1', V: '1', C: '2', G: '2', J: '2', K: '2', Q: '2', S: '2', X: '2', Z: '2',
    D: '3', T: '3', L: '4', M: '5', N: '5', R: '6'
  };

  function c11BSoundex(t, opt) {
    var o = c11BOpt(opt), width = c11BInt(o.length, 4);
    var s = c11BUp(t).replace(/[^A-Z]/g, ''), i, ch, code, prev, out;
    if (!s.length) return '';
    out = s.charAt(0);
    prev = C11B_SOUNDEX_MAP[s.charAt(0)] || '';
    for (i = 1; i < s.length && out.length < width; i++) {
      ch = s.charAt(i);
      code = C11B_SOUNDEX_MAP[ch] || '';
      if (!code) {
        if (ch !== 'H' && ch !== 'W') prev = '';
        continue;
      }
      if (code !== prev) { out += code; prev = code; }
    }
    while (out.length < width) out += '0';
    return out.substring(0, width);
  }

  /* ================================================================
     66. Index of coincidence
     ================================================================ */
  function c11BIcOf(s) {
    var c = {}, i, ch, n = 0, sum = 0, k;
    for (i = 0; i < s.length; i++) {
      ch = s.charAt(i);
      if (ch < 'A' || ch > 'Z') continue;
      c[ch] = (c[ch] || 0) + 1; n++;
    }
    if (n < 2) return 0;
    for (k in c) { if (c.hasOwnProperty(k)) sum += c[k] * (c[k] - 1); }
    return sum / (n * (n - 1));
  }

  function c11BIndexOfCoincidence(t, opt) {
    var o = c11BOpt(opt), s = c11BUp(t).replace(/[^A-Z]/g, '');
    var k, i, lines, avg, groups, g;
    if (o.maxK && c11BInt(o.maxK, 0) > 0) {
      lines = [];
      for (k = 1; k <= c11BInt(o.maxK, 0); k++) {
        groups = [];
        for (i = 0; i < k; i++) groups.push('');
        for (i = 0; i < s.length; i++) groups[i % k] += s.charAt(i);
        avg = 0;
        for (g = 0; g < k; g++) avg += c11BIcOf(groups[g]);
        avg = avg / k;
        lines.push('k=' + k + '  IC=' + avg.toFixed(5));
      }
      return lines.join('\n');
    }
    k = c11BInt(o.k, 1);
    if (k > 1) {
      groups = [];
      for (i = 0; i < k; i++) groups.push('');
      for (i = 0; i < s.length; i++) groups[i % k] += s.charAt(i);
      avg = 0;
      for (g = 0; g < k; g++) avg += c11BIcOf(groups[g]);
      return (avg / k).toFixed(5);
    }
    return c11BIcOf(s).toFixed(5);
  }

function railEnc(s, rails) { var rows = []; for (var i = 0; i < rails; i++) rows.push(''); var r = 0, down = true; for (var j = 0; j < s.length; j++) { rows[r] += s[j]; if (rails === 1) continue; if (down) { if (r === rails - 1) { down = false; r--; } else r++; } else { if (r === 0) { down = true; r++; } else r--; } } return rows.join(''); }
  function railDec(s, rails) { if (rails <= 1) return s; var len = s.length; var pattern = []; for (var i = 0; i < rails; i++) pattern.push(0); var r = 0, down = true; for (var j = 0; j < len; j++) { pattern[r]++; if (down) { if (r === rails - 1) { down = false; r--; } else r++; } else { if (r === 0) { down = true; r++; } else r--; } } var rows = [], pos = 0; for (var k = 0; k < rails; k++) { rows.push(s.substr(pos, pattern[k])); pos += pattern[k]; } var out = '', idx = []; for (var m = 0; m < rails; m++) idx.push(0); r = 0; down = true; for (var n = 0; n < len; n++) { out += rows[r][idx[r]++]; if (down) { if (r === rails - 1) { down = false; r--; } else r++; } else { if (r === 0) { down = true; r++; } else r--; } } return out; }
  function bytesToBase64(bytes) { var bin = ''; for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]); return btoa(bin); }
  function base64ToBytes(b64) { var bin = atob(b64), bytes = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i); return bytes; }
  function XML2JSON(xml) { var p = new DOMParser(); var doc = p.parseFromString(xml, 'application/xml'); function nodeTo(n) { var obj = {}; if (n.attributes && n.attributes.length) { obj['@attr'] = {}; for (var i = 0; i < n.attributes.length; i++) obj['@attr'][n.attributes[i].name] = n.attributes[i].value; } var txt = ''; for (var i = 0; i < n.childNodes.length; i++) { var c = n.childNodes[i]; if (c.nodeType === 3) txt += c.textContent; else if (c.nodeType === 1) { obj[c.nodeName] = nodeTo(c); } } if (txt.trim() && Object.keys(obj).length <= 1) return txt.trim(); return obj; } var r = {}; r[doc.documentElement.nodeName] = nodeTo(doc.documentElement); return JSON.stringify(r, null, 2); }

  // =================== 新增工具 helper（GitHub 开源工具 2026-08-28） ===================

// ---------- JSON/TOML/XML/TS 转换 ----------
function jsonToToml(o) {
  var lines = [];
  function scalar(v) { if (typeof v === 'string') return JSON.stringify(v); if (typeof v === 'boolean') return String(v); if (v === null) return '""'; return String(v); }
  function arrScalar(a) { return '[' + a.map(function (x) { return x === null ? '""' : (typeof x === 'string' ? JSON.stringify(x) : String(x)); }).join(', ') + ']'; }
  function walk(obj, prefix) {
    var keys = Object.keys(obj);
    keys.forEach(function (k) {
      var v = obj[k], key = prefix ? prefix + '.' + k : k;
      if (v === null || typeof v !== 'object') lines.push(key + ' = ' + scalar(v));
      else if (Array.isArray(v) && !v.some(function (x) { return x && typeof x === 'object'; })) lines.push(key + ' = ' + arrScalar(v));
    });
    keys.forEach(function (k) {
      var v = obj[k];
      if (v && typeof v === 'object' && !Array.isArray(v)) { lines.push('[' + (prefix ? prefix + '.' + k : k) + ']'); walk(v, prefix ? prefix + '.' + k : k); }
      else if (Array.isArray(v) && v.some(function (x) { return x && typeof x === 'object'; })) {
        v.forEach(function (item) { lines.push('[[' + (prefix ? prefix + '.' + k : k) + ']]'); walk(item, prefix ? prefix + '.' + k : k); });
      }
    });
  }
  walk(o, '');
  return lines.join('\n');
}

function jsonToXml(o, name) {
  function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function node(key, v, d) {
    var ind = new Array(d + 1).join('  ');
    if (v === null || typeof v !== 'object') return ind + '<' + key + '>' + esc(v) + '</' + key + '>';
    if (Array.isArray(v)) return v.map(function (x) { return node(key, x, d); }).join('\n');
    var attrs = '', kids = [];
    if (v['@attr'] && typeof v['@attr'] === 'object') Object.keys(v['@attr']).forEach(function (a) { attrs += ' ' + a + '="' + esc(v['@attr'][a]) + '"'; });
    Object.keys(v).forEach(function (k) { if (k !== '@attr') kids.push(node(k, v[k], d + 1)); });
    if (!kids.length) return ind + '<' + key + attrs + ' />';
    return ind + '<' + key + attrs + '>\n' + kids.join('\n') + '\n' + ind + '</' + key + '>';
  }
  return '<?xml version="1.0" encoding="UTF-8"?>\n' + node(name, o, 0);
}

function jsonToTs(o, name) {
  var out = [];
  function tsName(s) { return s.replace(/[^A-Za-z0-9_$]/g, '_').replace(/^(\d)/, '_$1'); }
  function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
  function typeOf(v) {
    if (v === null) return 'any';
    if (Array.isArray(v)) { if (!v.length) return 'any[]'; var t = typeOf(v[0]); return /\[\]$/.test(t) ? t : t + '[]'; }
    if (typeof v === 'string') return 'string';
    if (typeof v === 'number') return 'number';
    if (typeof v === 'boolean') return 'boolean';
    return 'any';
  }
  function emit(obj, nm) {
    out.push('export interface ' + nm + ' {');
    Object.keys(obj).forEach(function (k) {
      var v = obj[k];
      if (v && typeof v === 'object' && !Array.isArray(v)) { var sub = nm + cap(tsName(k)); out.push('  ' + k + ': ' + sub + ';'); emit(v, sub); }
      else if (Array.isArray(v) && v.length && v[0] && typeof v[0] === 'object' && !Array.isArray(v[0])) { var sub2 = nm + cap(tsName(k)); out.push('  ' + k + ': ' + sub2 + '[];'); emit(v[0], sub2); }
      else out.push('  ' + k + ': ' + typeOf(v) + ';');
    });
    out.push('}');
  }
  emit(o, tsName(name));
  return out.join('\n');
}

function jsonViewTree(o, d) {
  var out = [];
  function pad(n) { return new Array(n + 1).join('  '); }
  function walk(v, d) {
    if (v === null) { out.push(pad(d) + 'null'); return; }
    if (Array.isArray(v)) {
      if (!v.length) { out.push(pad(d) + '[] (array)'); return; }
      out.push(pad(d) + '[' + v.length + '] (array)');
      v.forEach(function (x, i) { out.push(pad(d + 1) + '[' + i + ']:'); walk(x, d + 2); });
      return;
    }
    if (typeof v === 'object') {
      var ks = Object.keys(v);
      if (!ks.length) { out.push(pad(d) + '{} (object)'); return; }
      out.push(pad(d) + '{' + ks.length + '} (object)');
      ks.forEach(function (k) {
        var t = typeof v[k];
        out.push(pad(d + 1) + k + ': ' + (v[k] !== null && t === 'object' ? (Array.isArray(v[k]) ? '(array)' : '(object)') : '(' + t + ')'));
        if (v[k] !== null && t === 'object') walk(v[k], d + 2);
      });
      return;
    }
    out.push(pad(d) + String(v));
  }
  walk(o, d || 0);
  return out.join('\n');
}

// ---------- Docker run → Compose ----------
function dockerRunToCompose(cmd) {
  var parts = [];
  cmd.trim().split(/\s+/).forEach(function (x) {
    if (x.indexOf('--') === 0 && x.indexOf('=') > 0) { var kv = x.split('='); parts.push(kv[0]); parts.push(kv.slice(1).join('=')); }
    else parts.push(x);
  });
  if (parts[0] === 'docker' && parts[1] === 'run') parts = parts.slice(2);
  var svc = { image: null, container_name: null, ports: [], environment: [], volumes: [], restart: null, command: [] };
  var HAS_VAL = { '-p': 1, '--publish': 1, '-e': 1, '--env': 1, '-v': 1, '--volume': 1, '--name': 1, '--restart': 1, '--network': 1, '--hostname': 1, '-h': 1, '-w': 1, '--workdir': 1, '-u': 1, '--user': 1, '-m': 1, '--memory': 1, '--cpus': 1, '--env-file': 1, '--add-host': 1, '--cap-add': 1, '--cap-drop': 1, '--device': 1, '--dns': 1, '--label': 1, '-l': 1, '--platform': 1, '--entrypoint': 1, '--ip': 1, '--expose': 1, '--log-driver': 1, '--log-opt': 1, '--cgroup-parent': 1, '--shm-size': 1, '--tmpfs': 1, '--ulimit': 1 };
  var i = 0;
  while (i < parts.length) {
    var p = parts[i];
    if (p === '-d' || p === '--detach' || p === '--rm' || p === '-it' || p === '-i' || p === '-t' || p === '--tty' || p === '--interactive') { i++; }
    else if (HAS_VAL[p]) {
      if (p === '-p' || p === '--publish') svc.ports.push(parts[i + 1]);
      else if (p === '-e' || p === '--env') svc.environment.push(parts[i + 1]);
      else if (p === '-v' || p === '--volume') svc.volumes.push(parts[i + 1]);
      else if (p === '--name') svc.container_name = parts[i + 1];
      else if (p === '--restart') svc.restart = parts[i + 1];
      i += 2;
    }
    else if (p.indexOf('--') === 0) { i += 2; }
    else if (p.indexOf('-') === 0) { i += 2; }
    else { if (!svc.image) svc.image = p; else svc.command.push(p); i++; }
  }
  if (!svc.image) throw new Error(L10N.noImage);
  var s = { image: svc.image };
  if (svc.container_name) s.container_name = svc.container_name;
  if (svc.ports.length) s.ports = svc.ports.map(function (x) { return x.replace(/\/tcp$/, ''); });
  if (svc.environment.length) s.environment = svc.environment;
  if (svc.volumes.length) s.volumes = svc.volumes;
  if (svc.restart) s.restart = svc.restart;
  if (svc.command.length) s.command = svc.command;
  var compose = { services: {} };
  compose.services.app = s;
  return 'version: "3"\n' + jsyaml.dump(compose, { lineWidth: 200 });
}

// ---------- 数学表达式求值 ----------
function evalExpr(src) {
  var i = 0;
  var s = String(src).toLowerCase().replace(/\s+/g, '');
  if (!s) return L10N.needExpr;
  function num() {
    var t = '';
    while (i < s.length && /[0-9.]/.test(s[i])) t += s[i++];
    if (i < s.length && s[i] === 'e' && /[0-9]/.test(s[i + 1] || '')) { t += 'e'; i++; if (s[i] === '+' || s[i] === '-') t += s[i++]; while (i < s.length && /[0-9.]/.test(s[i])) t += s[i++]; }
    return parseFloat(t);
  }
  function ident() { var t = ''; while (i < s.length && /[a-z]/.test(s[i])) t += s[i++]; return t; }
  function atom() {
    var c = s[i];
    if (c === '(') { i++; var v = expr(); if (s[i] === ')') i++; return v; }
    if (c === '-') { i++; return -atom(); }
    if (c === '+') { i++; return atom(); }
    if (/[0-9.]/.test(c || '')) return num();
    var fn = ident();
    if (fn === 'pi') return Math.PI;
    if (fn === 'e') return Math.E;
    if (s[i] === '(') {
      i++; var a = expr(); if (s[i] === ')') i++;
      if (fn === 'sqrt') return Math.sqrt(Math.max(0, a));
      if (fn === 'sin') return Math.sin(a); if (fn === 'cos') return Math.cos(a); if (fn === 'tan') return Math.tan(a);
      if (fn === 'log') return Math.log10(Math.max(1e-300, a)); if (fn === 'ln') return Math.log(Math.max(1e-300, a));
      if (fn === 'abs') return Math.abs(a); if (fn === 'round') return Math.round(a); if (fn === 'floor') return Math.floor(a); if (fn === 'ceil') return Math.ceil(a);
      if (fn === 'min') { return NaN; }
      return NaN;
    }
    return NaN;
  }
  function power() { var v = atom(); while (s[i] === '^') { i++; v = Math.pow(v, atom()); } return v; }
  function mul() { var v = power(); while (s[i] === '*' || s[i] === '/' || s[i] === '%') { var op = s[i++], b = power(); v = op === '*' ? v * b : op === '/' ? v / b : v % b; } return v; }
  function expr() { var v = mul(); while (s[i] === '+' || s[i] === '-') { var op = s[i++], b = mul(); v = op === '+' ? v + b : v - b; } return v; }
  var r = expr();
  if (isNaN(r) || r === undefined) return L10N.badExpr;
  return Math.round(r * 1e10) / 1e10;
}

// ---------- 中文大写金额 ----------
function moneyUpper(n) {
  var D = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
  var U = ['', '拾', '佰', '仟'];
  var G = ['', '万', '亿', '兆'];
  var v = Math.round(parseFloat(n) * 100) / 100;
  if (isNaN(v)) return L10N.badMoney;
  var neg = v < 0; v = Math.abs(v);
  var intP = Math.floor(v), dec = Math.round((v - intP) * 100);
  var s = String(intP), intStr = '';
  if (intP === 0) intStr = '零';
  else {
    var len = s.length, gCount = Math.ceil(len / 4);
    var parts = [], segEndZero = [];
    for (var gi = 0; gi < gCount; gi++) {
      var seg = s.slice(Math.max(0, len - (gi + 1) * 4), len - gi * 4);
      var so = '', prevZero = true;
      for (var j = 0; j < seg.length; j++) {
        var d = +seg[j];
        if (d === 0) prevZero = true;
        else {
          if (so !== '' && prevZero) so += '零';
          else if (so === '' && prevZero && gi < gCount - 1 && j > 0) so += '零';
          so += D[d] + U[seg.length - 1 - j];
          prevZero = false;
        }
      }
      parts.push(so + G[gi]);
      segEndZero.push(seg[seg.length - 1] === '0');
    }
    for (var pi = gCount - 1; pi >= 0; pi--) {
      if (!parts[pi]) continue;
      if (intStr && segEndZero[pi + 1] && parts[pi].indexOf('零') !== 0) intStr += '零';
      intStr += parts[pi];
    }
  }
  var decStr = '';
  var jiao = Math.floor(dec / 10), fen = dec % 10;
  if (jiao === 0 && fen === 0) decStr = '整';
  else { if (jiao > 0) decStr += D[jiao] + '角'; else if (intP > 0) decStr += '零'; if (fen > 0) decStr += D[fen] + '分'; }
  return (neg ? '负' : '') + intStr + '元' + decStr;
}

// ---------- 身份证 ----------
var IDC_REGIONS = { '11': '北京', '12': '天津', '13': '河北', '14': '山西', '15': '内蒙古', '21': '辽宁', '22': '吉林', '23': '黑龙江', '31': '上海', '32': '江苏', '33': '浙江', '34': '安徽', '35': '福建', '36': '江西', '37': '山东', '41': '河南', '42': '湖北', '43': '湖南', '44': '广东', '45': '广西', '46': '海南', '50': '重庆', '51': '四川', '52': '贵州', '53': '云南', '54': '西藏', '61': '陕西', '62': '甘肃', '63': '青海', '64': '宁夏', '65': '新疆', '71': '台湾', '81': '香港', '82': '澳门' };
var IDC_REGIONS_EN = { '11': 'Beijing', '12': 'Tianjin', '13': 'Hebei', '14': 'Shanxi', '15': 'Inner Mongolia', '21': 'Liaoning', '22': 'Jilin', '23': 'Heilongjiang', '31': 'Shanghai', '32': 'Jiangsu', '33': 'Zhejiang', '34': 'Anhui', '35': 'Fujian', '36': 'Jiangxi', '37': 'Shandong', '41': 'Henan', '42': 'Hubei', '43': 'Hunan', '44': 'Guangdong', '45': 'Guangxi', '46': 'Hainan', '50': 'Chongqing', '51': 'Sichuan', '52': 'Guizhou', '53': 'Yunnan', '54': 'Tibet', '61': 'Shaanxi', '62': 'Gansu', '63': 'Qinghai', '64': 'Ningxia', '65': 'Xinjiang', '71': 'Taiwan', '81': 'Hong Kong', '82': 'Macao' };
function idCardParse(id) {
  var s = String(id).trim().toUpperCase();
  var R = { valid: '—', region: '—', birth: '—', gender: '—', age: '—' };
  if (!/^\d{17}[\dX]$/.test(s)) { R.valid = L10N.badId; return R; }
  var W = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
  var C = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
  var sum = 0;
  for (var i = 0; i < 17; i++) sum += +s[i] * W[i];
  if (C[sum % 11] !== s[17]) { R.valid = L10N.badIdCheck; return R; }
  R.valid = L10N.validId;
  var prov = (LANG === 'zh' ? IDC_REGIONS : IDC_REGIONS_EN)[s.slice(0, 2)];
  R.region = prov ? prov + (LANG === 'zh' ? '省/直辖市' : ' province/municipality') : L10N.unknown;
  var y = +s.slice(6, 10), m = +s.slice(10, 12), d = +s.slice(12, 14);
  R.birth = y + '-' + (m < 10 ? '0' + m : m) + '-' + (d < 10 ? '0' + d : d);
  R.gender = (+s[16] % 2 === 1) ? (LANG === 'zh' ? '男' : 'Male') : (LANG === 'zh' ? '女' : 'Female');
  var now = new Date();
  var age = now.getFullYear() - y - ((now.getMonth() + 1) * 100 + now.getDate() < m * 100 + d ? 1 : 0);
  R.age = String(Math.max(0, age));
  return R;
}

// ---------- chmod ----------
function chmodCalc(perm) {
  var s = String(perm).trim();
  if (!/^\d{3,4}$/.test(s)) return { symbolic: '—', bits: '—', info: L10N.badPerm };
  var digits = s.length === 4 ? s.slice(1) : s;
  var map = ['---', '--x', '-w-', '-wx', 'r--', 'r-x', 'rw-', 'rwx'];
  var sym = digits.split('').map(function (d) { return map[+d] || '---'; }).join(' ');
  var who = ['u', 'g', 'o'];
  var info = digits.split('').map(function (d, i) { return who[i] + '=' + d; }).join('  ');
  var bits = sym.replace(/r/g, '1').replace(/w/g, '1').replace(/x/g, '1').replace(/-/g, '0').replace(/ /g, ' ');
  return { symbolic: sym, bits: bits, info: info };
}

// ---------- IPv4 ----------
function ipToInt(ip) { var p = String(ip).split('.'); return (((+p[0] || 0) * 16777216 + (+p[1] || 0) * 65536 + (+p[2] || 0) * 256 + (+p[3] || 0)) >>> 0); }
function intToIp(n) { n = n >>> 0; return [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join('.'); }
function subnetCalc(ip, prefix) {
  var R = { network: '—', mask: '—', broadcast: '—', usable: '—', range: '—' };
  if (!/^\d{1,3}(\.\d{1,3}){3}$/.test(String(ip))) { R.network = L10N.badIp; return R; }
  var pre = Math.min(Math.max(parseInt(prefix, 10) || 24, 0), 32);
  var mask = pre === 0 ? 0 : ((~0 << (32 - pre)) >>> 0);
  var ipn = ipToInt(ip);
  var net = ipn & mask;
  var bcast = pre === 32 ? net : ((net | (~mask >>> 0)) >>> 0);
  var usable = pre >= 31 ? 0 : Math.pow(2, 32 - pre) - 2;
  var f1 = pre >= 31 ? net : net + 1, l1 = pre >= 31 ? bcast : bcast - 1;
  R.network = intToIp(net); R.mask = intToIp(mask); R.broadcast = intToIp(bcast);
  R.usable = String(usable); R.range = intToIp(f1) + ' – ' + intToIp(l1);
  return R;
}
function iprangeCalc(range) {
  var m = String(range).trim().match(/^(\d{1,3}(?:\.\d{1,3}){3})\s*[-–]\s*(\d{1,3}(?:\.\d{1,3}){3})$/);
  if (!m) return { list: L10N.badRange, count: '0' };
  var a = ipToInt(m[1]), b = ipToInt(m[2]);
  if (b < a) { var t = a; a = b; b = t; }
  var diff = b - a;
  if (diff > 10000) return { list: L10N.rangeTooBig, count: String(diff + 1) };
  var out = [];
  for (var i = a; i <= b; i++) out.push(intToIp(i));
  return { list: out.join('\n'), count: String(out.length) };
}
function ipv4convCalc(ip) {
  var R = { int: '—', hex: '—', bin: '—' };
  if (!/^\d{1,3}(\.\d{1,3}){3}$/.test(String(ip))) { R.int = L10N.badIp; return R; }
  var n = ipToInt(ip);
  R.int = String(n >>> 0);
  R.hex = '0x' + ('00000000' + (n >>> 0).toString(16)).slice(-8).toUpperCase();
  R.bin = ('00000000000000000000000000000000' + (n >>> 0).toString(2)).slice(-32);
  return R;
}

// ---------- IBAN ----------
function ibanCalc(iban) {
  var s = String(iban).replace(/\s+/g, '').toUpperCase();
  var R = { valid: '—', country: '—', bban: '—', check: '—' };
  if (!/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/.test(s)) { R.valid = L10N.badIban; return R; }
  R.country = s.slice(0, 2); R.check = s.slice(2, 4); R.bban = s.slice(4);
  var re = (s.slice(4) + s.slice(0, 4)).replace(/[A-Z]/g, function (c) { return String(c.charCodeAt(0) - 55); });
  var rem = 0;
  for (var i = 0; i < re.length; i++) rem = (rem * 10 + +re[i]) % 97;
  R.valid = rem === 1 ? L10N.validIban : L10N.badIbanMod;
  return R;
}

// ---------- 温度 ----------
function tempCalc(v, from, to) {
  var c, f, k;
  if (from === 'C') { c = v; f = v * 9 / 5 + 32; k = v + 273.15; }
  else if (from === 'F') { c = (v - 32) * 5 / 9; f = v; k = c + 273.15; }
  else { k = v; c = k - 273.15; f = c * 9 / 5 + 32; }
  var res = to === 'C' ? c : to === 'F' ? f : k;
  var u = to === 'C' ? '°C' : to === 'F' ? '°F' : 'K';
  return { result: res.toFixed(2) + ' ' + u, c: c.toFixed(2) + ' °C', f: f.toFixed(2) + ' °F', k: k.toFixed(2) + ' K' };
}

// ---------- Cron 描述 ----------
function cronDesc(min, hour, dom, mon, dow) {
  var zh = LANG === 'zh';
  function piece(v, unitZh, unitEn) {
    if (v === '*' || v === '*/1') return zh ? '每' + unitZh : 'every ' + unitEn;
    if (v.indexOf('*/') === 0) return zh ? '每' + v.slice(2) + ' ' + unitZh : 'every ' + v.slice(2) + ' ' + unitEn + (v.slice(2) === '1' ? '' : 's');
    if (v.indexOf('/') > 0) { var p = v.split('/'); return (zh ? '从' : 'starting at ') + p[0] + (zh ? '起每' + p[1] + ' ' + unitZh : ', every ' + p[1] + ' ' + unitEn + 's'); }
    if (v.indexOf(',') > 0) return (zh ? unitZh + ' ' : unitEn + 's ') + v;
    return v + (zh ? ' ' + unitZh : ' ' + unitEn + (v === '1' ? '' : 's'));
  }
  var parts = [];
  parts.push(piece(min, '分钟', 'minute'));
  parts.push(piece(hour, '小时', 'hour'));
  if (dom !== '*') parts.push(piece(dom, '日', 'day of month'));
  if (mon !== '*') parts.push(piece(mon, '月', 'month'));
  if (dow !== '*') parts.push(piece(dow, '周', 'day of week'));
  return (zh ? '运行时间：' : 'Runs: ') + parts.join(zh ? '、' : ', ');
}

// ---------- ASCII 艺术字 ----------
var ASC_FONT = {
  A: [' ### ', '#   #', '#####', '#   #', '#   #'], B: ['#### ', '#   #', '#### ', '#   #', '#### '],
  C: [' ####', '#    ', '#    ', '#    ', ' ####'], D: ['#### ', '#   #', '#   #', '#   #', '#### '],
  E: ['#####', '#    ', '#### ', '#    ', '#####'], F: ['#####', '#    ', '#### ', '#    ', '#    '],
  G: [' ####', '#    ', '#  ##', '#   #', ' ####'], H: ['#   #', '#   #', '#####', '#   #', '#   #'],
  I: ['#####', '  #  ', '  #  ', '  #  ', '#####'], J: ['  ###', '   # ', '   # ', '#  # ', ' ##  '],
  K: ['#   #', '#  # ', '###  ', '#  # ', '#   #'], L: ['#    ', '#    ', '#    ', '#    ', '#####'],
  M: ['#   #', '## ##', '# # #', '#   #', '#   #'], N: ['#   #', '##  #', '# # #', '#  ##', '#   #'],
  O: [' ### ', '#   #', '#   #', '#   #', ' ### '], P: ['#### ', '#   #', '#### ', '#    ', '#    '],
  Q: [' ### ', '#   #', '# # #', '#  # ', ' ## #'], R: ['#### ', '#   #', '#### ', '#  # ', '#   #'],
  S: [' ####', '#    ', ' ### ', '    #', '#### '], T: ['#####', '  #  ', '  #  ', '  #  ', '  #  '],
  U: ['#   #', '#   #', '#   #', '#   #', ' ### '], V: ['#   #', '#   #', '#   #', ' # # ', '  #  '],
  W: ['#   #', '#   #', '# # #', '## ##', '#   #'], X: ['#   #', ' # # ', '  #  ', ' # # ', '#   #'],
  Y: ['#   #', ' # # ', '  #  ', '  #  ', '  #  '], Z: ['#####', '   # ', '  #  ', ' #   ', '#####'],
  '0': [' ### ', '#   #', '#   #', '#   #', ' ### '], '1': ['  #  ', ' ##  ', '  #  ', '  #  ', '#####'],
  '2': [' ### ', '#   #', '   # ', '  #  ', '#####'], '3': ['#####', '   # ', ' ### ', '   # ', '#####'],
  '4': ['   # ', '  ## ', ' # # ', '#####', '   # '], '5': ['#####', '#    ', '#### ', '    #', '#### '],
  '6': [' ####', '#    ', '#### ', '#   #', ' ####'], '7': ['#####', '    #', '   # ', '  #  ', '  #  '],
  '8': [' ### ', '#   #', ' ### ', '#   #', ' ### '], '9': [' ### ', '#   #', ' ####', '    #', ' ####'],
  ' ': ['     ', '     ', '     ', '     ', '     '], '!': ['  #  ', '  #  ', '  #  ', '     ', '  #  '],
  '?': [' ### ', '#   #', '   # ', '  #  ', '     '], '.': ['     ', '     ', '     ', '     ', '  #  '],
  '-': ['     ', '     ', '#####', '     ', '     '], '+': ['     ', '  #  ', '#####', '  #  ', '     '],
  '=': ['     ', '#####', '     ', '#####', '     '], '/': ['    #', '   # ', '  #  ', ' #   ', '#    '],
  ':': ['     ', '  #  ', '     ', '  #  ', '     '], '@': [' ### ', '#   #', '# # #', '#    ', ' ### '],
  '#': [' # # ', '#####', ' # # ', '#####', ' # # '], '&': [' ##  ', '#  # ', ' ##  ', '#  # ', ' ## #'],
  '*': ['     ', '# # #', ' ### ', '# # #', '     '], "'": ['  #  ', '  #  ', '     ', '     ', '     '],
  '"': [' # # ', ' # # ', '     ', '     ', '     '], '(': ['   # ', '  #  ', '  #  ', '  #  ', '   # '],
  ')': [' #   ', '  #  ', '  #  ', '  #  ', ' #   '], ',': ['     ', '     ', '     ', '  #  ', ' #   '],
  '>': ['   # ', '  #  ', ' #   ', '  #  ', '   # '], '<': [' #   ', '  #  ', '   # ', '  #  ', ' #   ']
};
function asciiArt(text) {
  var t = String(text).toUpperCase();
  var lines = ['', '', '', '', ''];
  for (var i = 0; i < t.length; i++) {
    var g = ASC_FONT[t[i]] || ASC_FONT[' '];
    for (var r = 0; r < 5; r++) lines[r] += (lines[r] ? ' ' : '') + g[r];
  }
  return lines.join('\n');
}

// ---------- WebCrypto 辅助 ----------
function bufToB64(buf) { var b = new Uint8Array(buf), s = ''; for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]); return btoa(s); }
function b64ToBuf(b64) { var s = atob(b64), b = new Uint8Array(s.length); for (var i = 0; i < s.length; i++) b[i] = s.charCodeAt(i); return b; }
function encTxt(s) { return new TextEncoder().encode(s); }
function decTxt(b) { return new TextDecoder().decode(b); }
function hexStr(buf) { var b = new Uint8Array(buf), s = ''; for (var i = 0; i < b.length; i++) s += ('0' + b[i].toString(16)).slice(-2); return s; }

async function aesRun(mode, data, keyStr) {
  var keyBuf = await crypto.subtle.digest('SHA-256', encTxt(keyStr));
  var key = await crypto.subtle.importKey('raw', keyBuf, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
  if (mode === 'enc') {
    var iv = crypto.getRandomValues(new Uint8Array(12));
    var ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv }, key, encTxt(data));
    return bufToB64(iv) + '.' + bufToB64(ct);
  }
  var parts = String(data).split('.');
  if (parts.length !== 2) throw new Error(L10N.badCipher);
  var pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: b64ToBuf(parts[0]) }, key, b64ToBuf(parts[1]));
  return decTxt(pt);
}

async function rsaRun(bits) {
  var kp = await crypto.subtle.generateKey({ name: 'RSA-PSS', modulusLength: parseInt(bits, 10), publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' }, true, ['sign', 'verify']);
  var pub = await crypto.subtle.exportKey('spki', kp.publicKey);
  var prv = await crypto.subtle.exportKey('pkcs8', kp.privateKey);
  function toPem(buf, label) { var b64 = bufToB64(buf).match(/.{1,64}/g).join('\n'); return '-----BEGIN ' + label + '-----\n' + b64 + '\n-----END ' + label + '-----'; }
  return toPem(pub, 'PUBLIC KEY') + '\n\n' + toPem(prv, 'PRIVATE KEY');
}

async function hmacRun(data, keyStr, algo) {
  var HASHMAP = { 'SHA1': 'SHA-1', 'SHA256': 'SHA-256', 'SHA512': 'SHA-512', 'SHA-1': 'SHA-1', 'SHA-256': 'SHA-256', 'SHA-512': 'SHA-512' }; var k = await crypto.subtle.importKey('raw', encTxt(keyStr), { name: 'HMAC', hash: HASHMAP[algo] || 'SHA-256' }, false, ['sign']);
  var sig = await crypto.subtle.sign('HMAC', k, encTxt(data));
  return hexStr(sig);
}

async function totpRun(secret, digits, step) {
  var base32 = String(secret).replace(/\s+/g, '').toUpperCase().replace(/=+$/, '');
  var ALPH = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  var bits = '';
  for (var i = 0; i < base32.length; i++) { var idx = ALPH.indexOf(base32[i]); if (idx < 0) throw new Error(L10N.badB32); bits += ('00000' + idx.toString(2)).slice(-5); }
  var bytes = [];
  for (var j = 0; j + 8 <= bits.length; j += 8) bytes.push(parseInt(bits.slice(j, j + 8), 2));
  if (!bytes.length) throw new Error(L10N.badB32);
  var counter = Math.floor(Date.now() / 1000 / step);
  var buf = new ArrayBuffer(8), v = new DataView(buf);
  v.setUint32(4, counter, false);
  var key = await crypto.subtle.importKey('raw', new Uint8Array(bytes), { name: 'HMAC', hash: 'SHA-1' }, false, ['sign']);
  var mac = new Uint8Array(await crypto.subtle.sign('HMAC', key, new Uint8Array(buf)));
  var off = mac[mac.length - 1] & 15;
  var code = ((mac[off] & 127) << 24 | mac[off + 1] << 16 | mac[off + 2] << 8 | mac[off + 3]) % Math.pow(10, digits);
  var pad = String(code); while (pad.length < digits) pad = '0' + pad;
  var remain = step - Math.floor(Date.now() / 1000 % step);
  return pad + '\n' + (LANG === 'zh' ? '剩余 ' + remain + ' 秒刷新' : 'refreshes in ' + remain + 's');
}

async function bip39Run(words) {
  var ENT = { 12: 128, 15: 160, 18: 192, 21: 224, 24: 256 }[words] || 128;
  var bytes = new Uint8Array(ENT / 8);
  crypto.getRandomValues(bytes);
  var bits = '';
  for (var i = 0; i < bytes.length; i++) bits += ('00000000' + bytes[i].toString(2)).slice(-8);
  var hashBuf = await crypto.subtle.digest('SHA-256', bytes);
  var hb = new Uint8Array(hashBuf);
  var cs = ENT / 32;
  for (var j = 0; j < cs; j++) bits += (hb[Math.floor(j / 8)] >> (7 - j % 8)) & 1;
  var idx = [];
  for (var k = 0; k < bits.length; k += 11) idx.push(parseInt(bits.slice(k, k + 11), 2));
  return idx.map(function (x) { return BIP39_WORDS[x]; }).join(' ');
}

  // =================== CALC ===================
  var CALC_LEGACY = { 'unit-converter': 1, 'gradient-generator': 1, 'date-calculator': 1, 'age-calculator': 1, 'time-zone-converter': 1, 'percentage-calculator': 1, 'fraction-calculator': 1, 'base-converter': 1, 'meta-tag-generator': 1 };
  function num(id) { var v = parseFloat(val(id)); return isNaN(v) ? 0 : v; }
  function parseMatrix(t) { return toLines(t).filter(function (l) { return l.trim() !== ''; }).map(function (l) { return l.trim().split(/\s+/).map(Number); }); }
  function matOp(a, b, op) { if (a[0].length !== b.length) return L10N.dimMismatch; var r = a.map(function (row) { return b[0].map(function (_, j) { var s = 0; for (var k = 0; k < b.length; k++) s += row[k] * b[k][j]; return s; }); }); if (op === 'add' || op === 'sub') { return a.map(function (row, i) { return row.map(function (v, j) { return v + (op === 'add' ? b[i][j] : -b[i][j]); }); }); } return r; }
  function median(arr) { var a = arr.slice().sort(function (x, y) { return x - y; }); var m = Math.floor(a.length / 2); return a.length % 2 ? a[m] : (a[m - 1] + a[m]) / 2; }
/* ====== 第十二批新增：CSS 生成器引擎（开源项目对照 2026-09-05） ====== */
/* ===== 第十二批 design：CSS 生成器引擎函数（d11*） ===== */
/* 约定：每个函数无参数，内部用 val()/num() 读字段；返回 { code: 字符串, outs: { preview: HTML, ... } } */
/* 预览 HTML 自带 <style>，类名统一 ht- 前缀 */

/* ---------- 公共小工具 ---------- */
function d11Color(id, def) {
  var v = (val(id) || '').trim();
  if (!v) v = def || '#3e7c46';
  if (v.charAt(0) !== '#') v = '#' + v.replace(/[^0-9a-fA-F]/g, '').slice(0, 6);
  if (v === '#') v = def || '#3e7c46';
  return v;
}
function d11Rgba(hex, a) {
  var c = hexToRgb(hex) || [62, 124, 70];
  return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')';
}
function d11Rep(s, n) { var o = '', i = 0; for (; i < n; i++) o += s; return o; }
function d11Int(id, def) { var v = parseInt(val(id), 10); return isNaN(v) ? (def === undefined ? 0 : def) : v; }
function d11Num(id, def) { var v = parseFloat(val(id)); return isNaN(v) ? (def === undefined ? 0 : def) : v; }
function d11Pos(id, def, list) { var v = (val(id) || '').trim(); return list.indexOf(v) >= 0 ? v : def; }
function d11Attr(s) {
  return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
    return c === '&' ? '&amp;' : c === '<' ? '&lt;' : c === '>' ? '&gt;' : c === '"' ? '&quot;' : '&#39;';
  });
}
function d11Prev(css, html) { return '<style>' + css + '</style>' + html; }
function d11Font(size, weight) {
  return 'font:' + (weight || 400) + ' ' + (size || 14) + 'px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif';
}

/* ---------- 01 css-radio-button-generator ---------- */
/* 字段：size(20) color(#3e7c46) border(#c3cdbf) ring(2) dur(0.2) */
function d11Radio() {
  var size = clamp(d11Int('size', 20), 8, 200), color = d11Color('color', '#3e7c46'),
    bd = d11Color('border', '#c3cdbf'), ring = clamp(d11Int('ring', 2), 1, 20),
    dur = clamp(d11Num('dur', 0.2), 0, 5);
  var dot = Math.max(4, Math.round(size * 0.5));
  var css = '.ht-radio {\n' +
    '  -webkit-appearance: none;\n' +
    '  appearance: none;\n' +
    '  width: ' + size + 'px;\n' +
    '  height: ' + size + 'px;\n' +
    '  margin: 0;\n' +
    '  border: ' + ring + 'px solid ' + bd + ';\n' +
    '  border-radius: 50%;\n' +
    '  background: #fff;\n' +
    '  cursor: pointer;\n' +
    '  position: relative;\n' +
    '  transition: border-color ' + dur + 's ease, background ' + dur + 's ease;\n' +
    '}\n' +
    '.ht-radio:checked {\n' +
    '  border-color: ' + color + ';\n' +
    '}\n' +
    '.ht-radio::after {\n' +
    '  content: \'\';\n' +
    '  position: absolute;\n' +
    '  top: 50%;\n' +
    '  left: 50%;\n' +
    '  width: ' + dot + 'px;\n' +
    '  height: ' + dot + 'px;\n' +
    '  border-radius: 50%;\n' +
    '  background: ' + color + ';\n' +
    '  transform: translate(-50%, -50%) scale(0);\n' +
    '  transition: transform ' + dur + 's cubic-bezier(.34, 1.56, .64, 1);\n' +
    '}\n' +
    '.ht-radio:checked::after {\n' +
    '  transform: translate(-50%, -50%) scale(1);\n' +
    '}\n' +
    '.ht-radio:focus-visible {\n' +
    '  outline: none;\n' +
    '  box-shadow: 0 0 0 3px ' + d11Rgba(color, 0.25) + ';\n' +
    '}';
  var html = '<div style="display:flex;align-items:center;gap:14px;padding:14px;background:#fff;border-radius:10px">' +
    '<input type="radio" class="ht-radio" name="ht-radio-demo" checked>' +
    '<input type="radio" class="ht-radio" name="ht-radio-demo">' +
    '<span style="' + d11Font(14) + ';color:#4a5a48">自定义单选按钮</span>' +
    '</div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: size + 'px · ' + color } };
}

/* ---------- 02 css-switch-generator ---------- */
/* 字段：w(56) h(30) color(#3e7c46) off(#ccd6c8) dur(0.25) */
function d11Switch() {
  var w = clamp(d11Int('w', 56), 24, 400), h = clamp(d11Int('h', 30), 14, 200),
    color = d11Color('color', '#3e7c46'), off = d11Color('off', '#ccd6c8'),
    dur = clamp(d11Num('dur', 0.25), 0, 5);
  var knob = Math.max(6, h - 6), travel = Math.max(2, w - knob - 6);
  var css = '.ht-switch {\n' +
    '  -webkit-appearance: none;\n' +
    '  appearance: none;\n' +
    '  position: relative;\n' +
    '  width: ' + w + 'px;\n' +
    '  height: ' + h + 'px;\n' +
    '  margin: 0;\n' +
    '  border-radius: ' + Math.round(h / 2) + 'px;\n' +
    '  background: ' + off + ';\n' +
    '  cursor: pointer;\n' +
    '  transition: background ' + dur + 's ease;\n' +
    '}\n' +
    '.ht-switch::after {\n' +
    '  content: \'\';\n' +
    '  position: absolute;\n' +
    '  top: 3px;\n' +
    '  left: 3px;\n' +
    '  width: ' + knob + 'px;\n' +
    '  height: ' + knob + 'px;\n' +
    '  border-radius: 50%;\n' +
    '  background: #fff;\n' +
    '  box-shadow: 0 1px 3px rgba(0, 0, 0, .25);\n' +
    '  transition: transform ' + dur + 's cubic-bezier(.4, 0, .2, 1);\n' +
    '}\n' +
    '.ht-switch:checked {\n' +
    '  background: ' + color + ';\n' +
    '}\n' +
    '.ht-switch:checked::after {\n' +
    '  transform: translateX(' + travel + 'px);\n' +
    '}\n' +
    '.ht-switch:focus-visible {\n' +
    '  outline: none;\n' +
    '  box-shadow: 0 0 0 3px ' + d11Rgba(color, 0.25) + ';\n' +
    '}';
  var html = '<div style="display:flex;align-items:center;gap:14px;padding:14px;background:#fff;border-radius:10px">' +
    '<input type="checkbox" class="ht-switch" checked>' +
    '<input type="checkbox" class="ht-switch">' +
    '</div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: w + '×' + h + 'px · ' + color } };
}

/* ---------- 03 css-checkbox-generator ---------- */
/* 字段：size(22) color(#3e7c46) radius(6) dur(0.2) */
function d11Checkbox() {
  var size = clamp(d11Int('size', 22), 10, 200), color = d11Color('color', '#3e7c46'),
    radius = clamp(d11Int('radius', 6), 0, 100), dur = clamp(d11Num('dur', 0.2), 0, 5);
  var bw = Math.max(2, Math.round(size * 0.12));
  var css = '.ht-check {\n' +
    '  -webkit-appearance: none;\n' +
    '  appearance: none;\n' +
    '  width: ' + size + 'px;\n' +
    '  height: ' + size + 'px;\n' +
    '  margin: 0;\n' +
    '  border: 2px solid #c3cdbf;\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  background: #fff;\n' +
    '  cursor: pointer;\n' +
    '  position: relative;\n' +
    '  transition: background ' + dur + 's ease, border-color ' + dur + 's ease;\n' +
    '}\n' +
    '.ht-check::after {\n' +
    '  content: \'\';\n' +
    '  position: absolute;\n' +
    '  left: ' + Math.round(size * 0.3) + 'px;\n' +
    '  top: ' + Math.round(size * 0.16) + 'px;\n' +
    '  width: ' + Math.round(size * 0.24) + 'px;\n' +
    '  height: ' + Math.round(size * 0.46) + 'px;\n' +
    '  border: solid #fff;\n' +
    '  border-width: 0 ' + bw + 'px ' + bw + 'px 0;\n' +
    '  transform: rotate(45deg) scale(0);\n' +
    '  transform-origin: center;\n' +
    '  transition: transform ' + dur + 's cubic-bezier(.34, 1.56, .64, 1);\n' +
    '}\n' +
    '.ht-check:checked {\n' +
    '  background: ' + color + ';\n' +
    '  border-color: ' + color + ';\n' +
    '}\n' +
    '.ht-check:checked::after {\n' +
    '  transform: rotate(45deg) scale(1);\n' +
    '}\n' +
    '.ht-check:focus-visible {\n' +
    '  outline: none;\n' +
    '  box-shadow: 0 0 0 3px ' + d11Rgba(color, 0.25) + ';\n' +
    '}';
  var html = '<div style="display:flex;align-items:center;gap:14px;padding:14px;background:#fff;border-radius:10px">' +
    '<input type="checkbox" class="ht-check" checked>' +
    '<input type="checkbox" class="ht-check">' +
    '<span style="' + d11Font(14) + ';color:#4a5a48">自定义复选框</span>' +
    '</div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: size + 'px · r' + radius } };
}

/* ---------- 04 css-input-field-generator ---------- */
/* 字段：w(260) pad(10) radius(8) border(#c9d3c5) focus(#3e7c46) bg(#ffffff) */
function d11Input() {
  var w = clamp(d11Int('w', 260), 60, 1200), pad = clamp(d11Int('pad', 10), 0, 60),
    radius = clamp(d11Int('radius', 8), 0, 60), bd = d11Color('border', '#c9d3c5'),
    focus = d11Color('focus', '#3e7c46'), bg = d11Color('bg', '#ffffff');
  var css = '.ht-input {\n' +
    '  width: ' + w + 'px;\n' +
    '  max-width: 100%;\n' +
    '  padding: ' + pad + 'px ' + (pad + 4) + 'px;\n' +
    '  border: 1px solid ' + bd + ';\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  background: ' + bg + ';\n' +
    '  color: #2f3a2f;\n' +
    '  font: 400 14px/1.4 system-ui, sans-serif;\n' +
    '  outline: none;\n' +
    '  transition: border-color .18s ease, box-shadow .18s ease;\n' +
    '}\n' +
    '.ht-input::placeholder {\n' +
    '  color: #9aa39a;\n' +
    '}\n' +
    '.ht-input:hover {\n' +
    '  border-color: ' + d11Rgba(focus, 0.6) + ';\n' +
    '}\n' +
    '.ht-input:focus {\n' +
    '  border-color: ' + focus + ';\n' +
    '  box-shadow: 0 0 0 3px ' + d11Rgba(focus, 0.18) + ';\n' +
    '}\n' +
    '.ht-input:disabled {\n' +
    '  background: #f2f5f1;\n' +
    '  color: #a5afa4;\n' +
    '  cursor: not-allowed;\n' +
    '}';
  var html = '<div style="display:flex;flex-direction:column;gap:10px;padding:14px;background:#fff;border-radius:10px">' +
    '<input class="ht-input" placeholder="请输入内容…">' +
    '<input class="ht-input" value="已聚焦样式示例" style="border-color:' + focus + ';box-shadow:0 0 0 3px ' + d11Rgba(focus, 0.18) + '">' +
    '</div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: w + 'px · r' + radius } };
}

/* ---------- 05 css-select-dropdown-styler ---------- */
/* 字段：w(220) h(42) radius(8) border(#c9d3c5) bg(#ffffff) arrow(#3e7c46) */
function d11Select() {
  var w = clamp(d11Int('w', 220), 60, 1200), h = clamp(d11Int('h', 42), 20, 200),
    radius = clamp(d11Int('radius', 8), 0, 60), bd = d11Color('border', '#c9d3c5'),
    bg = d11Color('bg', '#ffffff'), arrow = d11Color('arrow', '#3e7c46');
  var ac = arrow.replace('#', '%23');
  var uri = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1.6 L7 7.4 L13 1.6' fill='none' stroke='" + ac + "' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E";
  var css = '.ht-select {\n' +
    '  -webkit-appearance: none;\n' +
    '  -moz-appearance: none;\n' +
    '  appearance: none;\n' +
    '  width: ' + w + 'px;\n' +
    '  max-width: 100%;\n' +
    '  height: ' + h + 'px;\n' +
    '  padding: 0 ' + (h + 4) + 'px 0 12px;\n' +
    '  border: 1px solid ' + bd + ';\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  background-color: ' + bg + ';\n' +
    '  background-image: url("' + uri + '");\n' +
    '  background-repeat: no-repeat;\n' +
    '  background-position: right ' + Math.round(h / 2.6) + 'px center;\n' +
    '  background-size: 14px 9px;\n' +
    '  color: #2f3a2f;\n' +
    '  font: 400 14px/1 system-ui, sans-serif;\n' +
    '  cursor: pointer;\n' +
    '  outline: none;\n' +
    '  transition: border-color .18s ease, box-shadow .18s ease;\n' +
    '}\n' +
    '.ht-select:hover {\n' +
    '  border-color: ' + arrow + ';\n' +
    '}\n' +
    '.ht-select:focus {\n' +
    '  border-color: ' + arrow + ';\n' +
    '  box-shadow: 0 0 0 3px ' + d11Rgba(arrow, 0.18) + ';\n' +
    '}\n' +
    '.ht-select::-ms-expand {\n' +
    '  display: none;\n' +
    '}';
  var html = '<div style="padding:16px;background:#fff;border-radius:10px">' +
    '<select class="ht-select">' +
    '<option>请选择一项</option>' +
    '<option>苍鹭工具 HeronTool</option>' +
    '<option>在线 CSS 生成器</option>' +
    '</select>' +
    '</div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: w + '×' + h + 'px' } };
}

/* ---------- 06 css-tab-generator ---------- */
/* 字段：count(3) color(#3e7c46) bg(#f2f6f0) radius(8) */
function d11Tabs() {
  var n = clamp(d11Int('count', 3), 2, 8), color = d11Color('color', '#3e7c46'),
    bg = d11Color('bg', '#f2f6f0'), radius = clamp(d11Int('radius', 8), 0, 40);
  var names = ['选项卡', '标签', '面板', '更多', '设置', '关于', '帮助', '其他'];
  var rules = '', inputs = '', labels = '', panels = '';
  for (var i = 0; i < n; i++) {
    rules += '.ht-tabs #ht-tab' + i + ':checked ~ .ht-tablabels label:nth-child(' + (i + 1) + ') {\n' +
      '  background: ' + color + ';\n' +
      '  color: #fff;\n' +
      '}\n' +
      '.ht-tabs #ht-tab' + i + ':checked ~ .ht-tabpanels > .ht-panel:nth-child(' + (i + 1) + ') {\n' +
      '  display: block;\n' +
      '}\n';
    inputs += '<input type="radio" name="ht-tabs" id="ht-tab' + i + '"' + (i === 0 ? ' checked' : '') + '>';
    labels += '<label for="ht-tab' + i + '">' + names[i % names.length] + ' ' + (i + 1) + '</label>';
    panels += '<div class="ht-panel"><strong>' + names[i % names.length] + ' ' + (i + 1) + '</strong><br>这是第 ' + (i + 1) + ' 个面板的内容，纯 CSS 实现，无需 JavaScript。</div>';
  }
  var css = '.ht-tabs {\n' +
    '  width: 100%;\n' +
    '  max-width: 520px;\n' +
    '  background: #fff;\n' +
    '  border: 1px solid #e2e9df;\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  overflow: hidden;\n' +
    '}\n' +
    '.ht-tabs > input[type=radio] {\n' +
    '  position: absolute;\n' +
    '  opacity: 0;\n' +
    '  pointer-events: none;\n' +
    '}\n' +
    '.ht-tablabels {\n' +
    '  display: flex;\n' +
    '  background: ' + bg + ';\n' +
    '  border-bottom: 1px solid #e2e9df;\n' +
    '}\n' +
    '.ht-tablabels label {\n' +
    '  flex: 1;\n' +
    '  padding: 10px 8px;\n' +
    '  text-align: center;\n' +
    '  cursor: pointer;\n' +
    '  ' + d11Font(14) + ';\n' +
    '  color: #4a5a48;\n' +
    '  transition: background .2s ease, color .2s ease;\n' +
    '}\n' +
    '.ht-tablabels label:hover {\n' +
    '  background: ' + d11Rgba(color, 0.12) + ';\n' +
    '}\n' +
    '.ht-tabpanels {\n' +
    '  padding: 16px;\n' +
    '}\n' +
    '.ht-panel {\n' +
    '  display: none;\n' +
    '  ' + d11Font(14) + ';\n' +
    '  color: #3f4a3d;\n' +
    '}\n' + rules;
  var html = '<div class="ht-tabs">' + inputs +
    '<div class="ht-tablabels">' + labels + '</div>' +
    '<div class="ht-tabpanels">' + panels + '</div>' +
    '</div>';
  return { code: css, outs: { preview: d11Prev(css, html), count: String(n) } };
}

/* ---------- 07 css-accordion-generator ---------- */
/* 字段：color(#3e7c46) bg(#ffffff) radius(8) dur(0.3) */
function d11Accordion() {
  var color = d11Color('color', '#3e7c46'), bg = d11Color('bg', '#ffffff'),
    radius = clamp(d11Int('radius', 8), 0, 40), dur = clamp(d11Num('dur', 0.3), 0, 5);
  var css = '.ht-acc {\n' +
    '  width: 100%;\n' +
    '  max-width: 520px;\n' +
    '  background: ' + bg + ';\n' +
    '  border: 1px solid #e2e9df;\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  overflow: hidden;\n' +
    '}\n' +
    '.ht-acc + .ht-acc {\n' +
    '  margin-top: 8px;\n' +
    '}\n' +
    '.ht-acc > summary {\n' +
    '  list-style: none;\n' +
    '  cursor: pointer;\n' +
    '  padding: 12px 16px;\n' +
    '  ' + d11Font(14, 600) + ';\n' +
    '  color: ' + color + ';\n' +
    '  background: ' + d11Rgba(color, 0.06) + ';\n' +
    '  display: flex;\n' +
    '  align-items: center;\n' +
    '  justify-content: space-between;\n' +
    '  transition: background ' + dur + 's ease;\n' +
    '}\n' +
    '.ht-acc > summary::-webkit-details-marker {\n' +
    '  display: none;\n' +
    '}\n' +
    '.ht-acc > summary::after {\n' +
    '  content: \'+\';\n' +
    '  font-size: 18px;\n' +
    '  line-height: 1;\n' +
    '  transition: transform ' + dur + 's ease;\n' +
    '}\n' +
    '.ht-acc[open] > summary::after {\n' +
    '  transform: rotate(45deg);\n' +
    '}\n' +
    '.ht-acc > summary:hover {\n' +
    '  background: ' + d11Rgba(color, 0.14) + ';\n' +
    '}\n' +
    '.ht-acc-body {\n' +
    '  padding: 0 16px;\n' +
    '  ' + d11Font(14) + ';\n' +
    '  color: #3f4a3d;\n' +
    '}\n' +
    '.ht-acc[open] .ht-acc-body {\n' +
    '  padding: 12px 16px;\n' +
    '  animation: ht-acc-in ' + dur + 's ease;\n' +
    '}\n' +
    '@keyframes ht-acc-in {\n' +
    '  from { opacity: 0; transform: translateY(-6px); }\n' +
    '  to { opacity: 1; transform: none; }\n' +
    '}';
  var items = [['什么是苍鹭工具？', 'HeronTool 是一个提供 967 个在线工具的站点，全部纯前端计算。'],
  ['生成的 CSS 怎么用？', '复制左侧代码，粘贴到你的样式表，并给元素加上对应类名即可。'],
  ['需要引入外部库吗？', '不需要，所有效果均为原生 CSS 实现。']];
  var html = '';
  for (var i = 0; i < items.length; i++) {
    html += '<details class="ht-acc"' + (i === 0 ? ' open' : '') + '><summary>' + items[i][0] + '</summary>' +
      '<div class="ht-acc-body">' + items[i][1] + '</div></details>';
  }
  return { code: css, outs: { preview: d11Prev(css, html), dur: dur + 's' } };
}

/* ---------- 08 css-modal-generator ---------- */
/* 字段：w(420) radius(12) overlay(0.5) dur(0.3) */
function d11Modal() {
  var w = clamp(d11Int('w', 420), 160, 1400), radius = clamp(d11Int('radius', 12), 0, 60),
    ov = clamp(d11Num('overlay', 0.5), 0, 1), dur = clamp(d11Num('dur', 0.3), 0, 5);
  var css = '.ht-modal {\n' +
    '  position: fixed;\n' +
    '  inset: 0;\n' +
    '  display: flex;\n' +
    '  align-items: center;\n' +
    '  justify-content: center;\n' +
    '  background: rgba(0, 0, 0, ' + ov + ');\n' +
    '  opacity: 0;\n' +
    '  pointer-events: none;\n' +
    '  transition: opacity ' + dur + 's ease;\n' +
    '  z-index: 999;\n' +
    '}\n' +
    '.ht-modal-card {\n' +
    '  width: ' + w + 'px;\n' +
    '  max-width: calc(100% - 32px);\n' +
    '  background: #fff;\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  padding: 24px;\n' +
    '  box-shadow: 0 24px 60px rgba(0, 0, 0, .28);\n' +
    '  transform: translateY(16px) scale(.96);\n' +
    '  transition: transform ' + dur + 's cubic-bezier(.2, .8, .3, 1);\n' +
    '}\n' +
    '.ht-modal-toggle {\n' +
    '  position: absolute;\n' +
    '  opacity: 0;\n' +
    '  pointer-events: none;\n' +
    '}\n' +
    '.ht-modal-toggle:checked ~ .ht-modal {\n' +
    '  opacity: 1;\n' +
    '  pointer-events: auto;\n' +
    '}\n' +
    '.ht-modal-toggle:checked ~ .ht-modal .ht-modal-card {\n' +
    '  transform: none;\n' +
    '}\n' +
    '.ht-modal-close {\n' +
    '  display: block;\n' +
    '  margin-top: 18px;\n' +
    '  text-align: center;\n' +
    '  cursor: pointer;\n' +
    '  color: #4a5a48;\n' +
    '  ' + d11Font(14) + ';\n' +
    '}';
  var html = '<div class="ht-modal-demo" style="position:relative;height:200px;border-radius:10px;overflow:hidden;background:repeating-linear-gradient(45deg,#f2f6f0 0 10px,#e9f0e7 10px 20px)">' +
    '<input type="checkbox" id="ht-modal-tg" class="ht-modal-toggle" checked>' +
    '<label for="ht-modal-tg" style="position:absolute;left:14px;top:14px;padding:8px 14px;border-radius:8px;background:#3e7c46;color:#fff;cursor:pointer;' + d11Font(13) + '">打开 / 关闭</label>' +
    '<div class="ht-modal" style="position:absolute">' +
    '<div class="ht-modal-card">' +
    '<h4 style="margin:0 0 8px;font-size:17px;color:#2f3a2f">模态框标题</h4>' +
    '<p style="margin:0;' + d11Font(14) + ';color:#5b6a58">这是一个纯 CSS 模态框，使用 checkbox hack 控制显示隐藏。</p>' +
    '<label for="ht-modal-tg" class="ht-modal-close">关闭</label>' +
    '</div></div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: w + 'px · 遮罩 ' + ov } };
}

/* ---------- 09 css-toast-notification-generator ---------- */
/* 字段：pos(bottom-right) bg(#2f5a35) fg(#ffffff) radius(8) dur(0.4) */
function d11Toast() {
  var pos = d11Pos('pos', 'bottom-right', ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'top-center', 'bottom-center']);
  var bg = d11Color('bg', '#2f5a35'), fg = d11Color('fg', '#ffffff'),
    radius = clamp(d11Int('radius', 8), 0, 40), dur = clamp(d11Num('dur', 0.4), 0, 5);
  var ver = pos.indexOf('top') === 0 ? 'top' : 'bottom';
  var hor = pos.indexOf('left') >= 0 ? 'left' : pos.indexOf('right') >= 0 ? 'right' : 'center';
  var place = hor === 'center'
    ? '  ' + ver + ': 24px;\n  left: 50%;\n  transform: translateX(-50%);\n'
    : '  ' + ver + ': 24px;\n  ' + hor + ': 24px;\n';
  var from = hor === 'center' ? 'translate(-50%, ' + (ver === 'top' ? '-140%' : '140%') + ')'
    : (hor === 'left' ? 'translateX(-140%)' : 'translateX(140%)');
  var to = hor === 'center' ? 'translate(-50%, 0)' : 'translateX(0)';
  var css = '.ht-toast {\n' +
    '  position: fixed;\n' + place +
    '  min-width: 200px;\n' +
    '  max-width: 360px;\n' +
    '  padding: 12px 16px;\n' +
    '  background: ' + bg + ';\n' +
    '  color: ' + fg + ';\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  box-shadow: 0 10px 30px rgba(0, 0, 0, .22);\n' +
    '  ' + d11Font(14) + ';\n' +
    '  z-index: 1000;\n' +
    '  animation: ht-toast-in ' + dur + 's cubic-bezier(.2, .9, .3, 1) both;\n' +
    '}\n' +
    '@keyframes ht-toast-in {\n' +
    '  from { opacity: 0; transform: ' + from + '; }\n' +
    '  to { opacity: 1; transform: ' + to + '; }\n' +
    '}\n' +
    '@keyframes ht-toast-out {\n' +
    '  to { opacity: 0; transform: ' + from + '; }\n' +
    '}';
  var html = '<div style="position:relative;height:150px;border-radius:10px;background:repeating-linear-gradient(45deg,#f2f6f0 0 10px,#e9f0e7 10px 20px);overflow:hidden">' +
    '<div class="ht-toast" style="position:absolute">操作成功，已保存到本地</div>' +
    '</div>';
  return { code: css, outs: { preview: d11Prev(css, html), pos: pos } };
}

/* ---------- 10 css-pagination-generator ---------- */
/* 字段：count(5) size(36) color(#3e7c46) radius(6) gap(6) */
function d11Pagination() {
  var n = clamp(d11Int('count', 5), 1, 12), size = clamp(d11Int('size', 36), 16, 120),
    color = d11Color('color', '#3e7c46'), radius = clamp(d11Int('radius', 6), 0, 60),
    gap = clamp(d11Int('gap', 6), 0, 40);
  var css = '.ht-page {\n' +
    '  display: flex;\n' +
    '  align-items: center;\n' +
    '  gap: ' + gap + 'px;\n' +
    '  list-style: none;\n' +
    '  margin: 0;\n' +
    '  padding: 0;\n' +
    '}\n' +
    '.ht-page a,\n' +
    '.ht-page span {\n' +
    '  display: inline-flex;\n' +
    '  align-items: center;\n' +
    '  justify-content: center;\n' +
    '  min-width: ' + size + 'px;\n' +
    '  height: ' + size + 'px;\n' +
    '  padding: 0 8px;\n' +
    '  border: 1px solid #dde5d9;\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  background: #fff;\n' +
    '  color: #4a5a48;\n' +
    '  text-decoration: none;\n' +
    '  ' + d11Font(14) + ';\n' +
    '  transition: background .18s ease, color .18s ease, border-color .18s ease;\n' +
    '}\n' +
    '.ht-page a:hover {\n' +
    '  border-color: ' + color + ';\n' +
    '  color: ' + color + ';\n' +
    '  background: ' + d11Rgba(color, 0.08) + ';\n' +
    '}\n' +
    '.ht-page .is-active {\n' +
    '  background: ' + color + ';\n' +
    '  border-color: ' + color + ';\n' +
    '  color: #fff;\n' +
    '}\n' +
    '.ht-page .is-disabled {\n' +
    '  color: #b6c0b4;\n' +
    '  background: #f6f9f4;\n' +
    '  cursor: not-allowed;\n' +
    '}';
  var items = '<a href="javascript:void(0)" class="is-disabled">‹</a>';
  for (var i = 1; i <= n; i++) {
    items += '<a href="javascript:void(0)"' + (i === 1 ? ' class="is-active"' : '') + '>' + i + '</a>';
  }
  items += '<a href="javascript:void(0)">›</a>';
  var html = '<div style="padding:16px;background:#fff;border-radius:10px"><nav class="ht-page">' + items + '</nav></div>';
  return { code: css, outs: { preview: d11Prev(css, html), count: String(n) } };
}

/* ---------- 11 css-breadcrumb-generator ---------- */
/* 字段：items(首页 / 产品 / 详情) sep(/) color(#3e7c46) */
function d11Breadcrumb() {
  var raw = val('items') || '首页 / 产品 / 详情';
  var parts = raw.split(/[/|>]|<li>/).map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
  if (!parts.length) parts = ['首页', '产品', '详情'];
  var sep = val('sep') || '/', color = d11Color('color', '#3e7c46');
  var css = '.ht-crumb {\n' +
    '  display: flex;\n' +
    '  flex-wrap: wrap;\n' +
    '  align-items: center;\n' +
    '  gap: 8px;\n' +
    '  list-style: none;\n' +
    '  margin: 0;\n' +
    '  padding: 0;\n' +
    '  ' + d11Font(14) + ';\n' +
    '}\n' +
    '.ht-crumb li {\n' +
    '  display: inline-flex;\n' +
    '  align-items: center;\n' +
    '  gap: 8px;\n' +
    '}\n' +
    '.ht-crumb li::after {\n' +
    '  content: \'' + sep.replace(/'/g, '') + '\';\n' +
    '  color: #a9b3a7;\n' +
    '}\n' +
    '.ht-crumb li:last-child::after {\n' +
    '  content: none;\n' +
    '}\n' +
    '.ht-crumb a {\n' +
    '  color: ' + color + ';\n' +
    '  text-decoration: none;\n' +
    '  transition: color .18s ease;\n' +
    '}\n' +
    '.ht-crumb a:hover {\n' +
    '  color: #2f5a35;\n' +
    '  text-decoration: underline;\n' +
    '}\n' +
    '.ht-crumb .is-current {\n' +
    '  color: #7c8779;\n' +
    '}';
  var lis = '';
  for (var i = 0; i < parts.length; i++) {
    var last = i === parts.length - 1;
    lis += '<li>' + (last ? '<span class="is-current">' + esc(parts[i]) + '</span>' : '<a href="javascript:void(0)">' + esc(parts[i]) + '</a>') + '</li>';
  }
  var html = '<div style="padding:16px;background:#fff;border-radius:10px"><nav class="ht-crumb">' + lis + '</nav></div>';
  return { code: css, outs: { preview: d11Prev(css, html), levels: String(parts.length) } };
}

/* ---------- 12 css-table-styler ---------- */
/* 字段：stripe(#f6f9f4) head(#2f5a35) border(#dde5d9) radius(8) hover(#eef4ec) */
function d11Table() {
  var stripe = d11Color('stripe', '#f6f9f4'), head = d11Color('head', '#2f5a35'),
    bd = d11Color('border', '#dde5d9'), radius = clamp(d11Int('radius', 8), 0, 40),
    hover = d11Color('hover', '#eef4ec');
  var css = '.ht-table {\n' +
    '  width: 100%;\n' +
    '  border-collapse: separate;\n' +
    '  border-spacing: 0;\n' +
    '  border: 1px solid ' + bd + ';\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  overflow: hidden;\n' +
    '  ' + d11Font(14) + ';\n' +
    '  color: #3f4a3d;\n' +
    '}\n' +
    '.ht-table th,\n' +
    '.ht-table td {\n' +
    '  padding: 10px 14px;\n' +
    '  text-align: left;\n' +
    '  border-bottom: 1px solid ' + bd + ';\n' +
    '}\n' +
    '.ht-table thead th {\n' +
    '  background: ' + head + ';\n' +
    '  color: #fff;\n' +
    '  font-weight: 600;\n' +
    '}\n' +
    '.ht-table tbody tr:nth-child(even) {\n' +
    '  background: ' + stripe + ';\n' +
    '}\n' +
    '.ht-table tbody tr:hover {\n' +
    '  background: ' + hover + ';\n' +
    '}\n' +
    '.ht-table tbody tr:last-child td {\n' +
    '  border-bottom: none;\n' +
    '}';
  var rows = [['苍鹭工具', '967', '设计'], ['JSON 格式化', '1.2k', '开发'], ['图片压缩', '860', '图像']];
  var body = '';
  for (var i = 0; i < rows.length; i++) {
    body += '<tr><td>' + rows[i][0] + '</td><td>' + rows[i][1] + '</td><td>' + rows[i][2] + '</td></tr>';
  }
  var html = '<div style="padding:14px;background:#fff;border-radius:10px">' +
    '<table class="ht-table"><thead><tr><th>工具</th><th>用量</th><th>分类</th></tr></thead><tbody>' + body + '</tbody></table>' +
    '</div>';
  return { code: css, outs: { preview: d11Prev(css, html), theme: head + ' / ' + stripe } };
}

/* ---------- 13 css-timeline-generator ---------- */
/* 字段：count(4) color(#3e7c46) dot(12) line(2) */
function d11Timeline() {
  var n = clamp(d11Int('count', 4), 1, 10), color = d11Color('color', '#3e7c46'),
    dot = clamp(d11Int('dot', 12), 4, 60), line = clamp(d11Int('line', 2), 1, 20);
  var off = Math.round(dot / 2) - Math.round(line / 2) + 12;
  var css = '.ht-timeline {\n' +
    '  list-style: none;\n' +
    '  margin: 0;\n' +
    '  padding: 4px 0 4px ' + (dot + 20) + 'px;\n' +
    '  position: relative;\n' +
    '}\n' +
    '.ht-timeline::before {\n' +
    '  content: \'\';\n' +
    '  position: absolute;\n' +
    '  left: ' + off + 'px;\n' +
    '  top: 8px;\n' +
    '  bottom: 8px;\n' +
    '  width: ' + line + 'px;\n' +
    '  background: ' + d11Rgba(color, 0.35) + ';\n' +
    '  border-radius: ' + line + 'px;\n' +
    '}\n' +
    '.ht-timeline li {\n' +
    '  position: relative;\n' +
    '  padding: 0 0 20px 0;\n' +
    '  ' + d11Font(14) + ';\n' +
    '  color: #3f4a3d;\n' +
    '}\n' +
    '.ht-timeline li:last-child {\n' +
    '  padding-bottom: 0;\n' +
    '}\n' +
    '.ht-timeline li::before {\n' +
    '  content: \'\';\n' +
    '  position: absolute;\n' +
    '  left: -' + (dot + 20 - off + Math.round(dot / 2) - line) + 'px;\n' +
    '  top: 4px;\n' +
    '  width: ' + dot + 'px;\n' +
    '  height: ' + dot + 'px;\n' +
    '  border-radius: 50%;\n' +
    '  background: ' + color + ';\n' +
    '  box-shadow: 0 0 0 3px ' + d11Rgba(color, 0.18) + ';\n' +
    '}\n' +
    '.ht-timeline li b {\n' +
    '  display: block;\n' +
    '  color: ' + color + ';\n' +
    '  ' + d11Font(13, 600) + ';\n' +
    '}';
  var lis = '';
  for (var i = 0; i < n; i++) {
    lis += '<li><b>2026 年 ' + (i + 1) + ' 月</b>第 ' + (i + 1) + ' 个节点：完成第 ' + (i + 1) + ' 阶段的目标。</li>';
  }
  var html = '<div style="padding:16px;background:#fff;border-radius:10px"><ul class="ht-timeline">' + lis + '</ul></div>';
  return { code: css, outs: { preview: d11Prev(css, html), count: String(n) } };
}

/* ---------- 14 css-pricing-table-generator ---------- */
/* 字段：tiers(3) color(#3e7c46) accent(#e7efe1) radius(12) */
function d11Pricing() {
  var n = clamp(d11Int('tiers', 3), 1, 5), color = d11Color('color', '#3e7c46'),
    accent = d11Color('accent', '#e7efe1'), radius = clamp(d11Int('radius', 12), 0, 60);
  var names = ['基础版', '专业版', '企业版', '旗舰版', '定制版'];
  var prices = ['¥0', '¥39', '¥99', '¥199', '询价'];
  var feats = ['3 个项目', '无限项目', '团队协作', '优先支持', '私有部署'];
  var mid = Math.floor(n / 2);
  var css = '.ht-price {\n' +
    '  display: flex;\n' +
    '  flex-wrap: wrap;\n' +
    '  gap: 14px;\n' +
    '  align-items: stretch;\n' +
    '}\n' +
    '.ht-price-card {\n' +
    '  flex: 1 1 160px;\n' +
    '  background: #fff;\n' +
    '  border: 1px solid #e2e9df;\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  padding: 20px 16px;\n' +
    '  text-align: center;\n' +
    '  transition: transform .2s ease, box-shadow .2s ease;\n' +
    '}\n' +
    '.ht-price-card:hover {\n' +
    '  transform: translateY(-4px);\n' +
    '  box-shadow: 0 14px 32px rgba(0, 0, 0, .1);\n' +
    '}\n' +
    '.ht-price-card.is-featured {\n' +
    '  background: ' + accent + ';\n' +
    '  border: 2px solid ' + color + ';\n' +
    '  box-shadow: 0 14px 30px ' + d11Rgba(color, 0.18) + ';\n' +
    '}\n' +
    '.ht-price-name {\n' +
    '  ' + d11Font(14, 600) + ';\n' +
    '  color: #4a5a48;\n' +
    '}\n' +
    '.ht-price-amount {\n' +
    '  margin: 8px 0;\n' +
    '  ' + d11Font(30, 700) + ';\n' +
    '  color: ' + color + ';\n' +
    '}\n' +
    '.ht-price-feat {\n' +
    '  list-style: none;\n' +
    '  margin: 10px 0 16px;\n' +
    '  padding: 0;\n' +
    '  ' + d11Font(13) + ';\n' +
    '  color: #5b6a58;\n' +
    '}\n' +
    '.ht-price-btn {\n' +
    '  display: inline-block;\n' +
    '  padding: 9px 18px;\n' +
    '  border-radius: ' + Math.max(4, Math.round(radius * 0.6)) + 'px;\n' +
    '  background: ' + color + ';\n' +
    '  color: #fff;\n' +
    '  text-decoration: none;\n' +
    '  ' + d11Font(14, 600) + ';\n' +
    '}\n' +
    '.ht-price-card:not(.is-featured) .ht-price-btn {\n' +
    '  background: transparent;\n' +
    '  color: ' + color + ';\n' +
    '  border: 1px solid ' + color + ';\n' +
    '}';
  var cards = '';
  for (var i = 0; i < n; i++) {
    cards += '<div class="ht-price-card' + (i === mid ? ' is-featured' : '') + '">' +
      '<div class="ht-price-name">' + names[i % names.length] + '</div>' +
      '<div class="ht-price-amount">' + prices[i % prices.length] + '</div>' +
      '<ul class="ht-price-feat"><li>' + feats[i % feats.length] + '</li><li>基础功能</li></ul>' +
      '<a class="ht-price-btn" href="javascript:void(0)">选择</a>' +
      '</div>';
  }
  var html = '<div style="padding:14px;background:#fff;border-radius:10px"><div class="ht-price">' + cards + '</div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), tiers: String(n) } };
}


/* ---------- 15 css-card-flip-generator ---------- */
/* 字段：w(240) h(160) dur(0.6) radius(12) */
function d11CardFlip() {
  var w = clamp(d11Int('w', 240), 80, 1200), h = clamp(d11Int('h', 160), 60, 1200),
    dur = clamp(d11Num('dur', 0.6), 0, 10), radius = clamp(d11Int('radius', 12), 0, 80);
  var css = '.ht-flip {\n' +
    '  width: ' + w + 'px;\n' +
    '  height: ' + h + 'px;\n' +
    '  perspective: 1000px;\n' +
    '}\n' +
    '.ht-flip-inner {\n' +
    '  position: relative;\n' +
    '  width: 100%;\n' +
    '  height: 100%;\n' +
    '  transform-style: preserve-3d;\n' +
    '  transition: transform ' + dur + 's cubic-bezier(.4, .2, .2, 1);\n' +
    '}\n' +
    '.ht-flip:hover .ht-flip-inner {\n' +
    '  transform: rotateY(180deg);\n' +
    '}\n' +
    '.ht-flip-face {\n' +
    '  position: absolute;\n' +
    '  inset: 0;\n' +
    '  display: flex;\n' +
    '  align-items: center;\n' +
    '  justify-content: center;\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  backface-visibility: hidden;\n' +
    '  -webkit-backface-visibility: hidden;\n' +
    '  box-shadow: 0 10px 28px rgba(0, 0, 0, .14);\n' +
    '  ' + d11Font(16, 600) + ';\n' +
    '}\n' +
    '.ht-flip-front {\n' +
    '  background: linear-gradient(135deg, #2f5a35, #3e7c46);\n' +
    '  color: #fff;\n' +
    '}\n' +
    '.ht-flip-back {\n' +
    '  background: #f2f6f0;\n' +
    '  color: #2f5a35;\n' +
    '  transform: rotateY(180deg);\n' +
    '}';
  var html = '<div style="padding:18px;display:flex;justify-content:center;background:#fff;border-radius:10px">' +
    '<div class="ht-flip"><div class="ht-flip-inner">' +
    '<div class="ht-flip-face ht-flip-front">正面 · 悬停翻转</div>' +
    '<div class="ht-flip-face ht-flip-back">背面内容</div>' +
    '</div></div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: w + '×' + h + 'px · ' + dur + 's' } };
}

/* ---------- 16 css-menu-generator ---------- */
/* 字段：w(180) bg(#ffffff) fg(#333333) hover(#eef4ec) radius(8) */
function d11Menu() {
  var w = clamp(d11Int('w', 180), 80, 800), bg = d11Color('bg', '#ffffff'),
    fg = d11Color('fg', '#333333'), hover = d11Color('hover', '#eef4ec'),
    radius = clamp(d11Int('radius', 8), 0, 40);
  var css = '.ht-menu {\n' +
    '  position: relative;\n' +
    '  display: inline-block;\n' +
    '  font-family: system-ui, sans-serif;\n' +
    '}\n' +
    '.ht-menu-btn {\n' +
    '  padding: 9px 16px;\n' +
    '  border: 1px solid rgba(0, 0, 0, .1);\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  background: ' + bg + ';\n' +
    '  color: ' + fg + ';\n' +
    '  cursor: pointer;\n' +
    '  ' + d11Font(14) + ';\n' +
    '}\n' +
    '.ht-menu-list {\n' +
    '  position: absolute;\n' +
    '  top: calc(100% + 6px);\n' +
    '  left: 0;\n' +
    '  width: ' + w + 'px;\n' +
    '  margin: 0;\n' +
    '  padding: 6px;\n' +
    '  list-style: none;\n' +
    '  background: ' + bg + ';\n' +
    '  border: 1px solid rgba(0, 0, 0, .08);\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  box-shadow: 0 12px 28px rgba(0, 0, 0, .12);\n' +
    '  opacity: 0;\n' +
    '  visibility: hidden;\n' +
    '  transform: translateY(-6px);\n' +
    '  transition: opacity .18s ease, transform .18s ease, visibility .18s;\n' +
    '  z-index: 20;\n' +
    '}\n' +
    '.ht-menu:hover .ht-menu-list,\n' +
    '.ht-menu:focus-within .ht-menu-list {\n' +
    '  opacity: 1;\n' +
    '  visibility: visible;\n' +
    '  transform: none;\n' +
    '}\n' +
    '.ht-menu-list a {\n' +
    '  display: block;\n' +
    '  padding: 8px 12px;\n' +
    '  border-radius: ' + Math.max(2, Math.round(radius * 0.6)) + 'px;\n' +
    '  color: ' + fg + ';\n' +
    '  text-decoration: none;\n' +
    '  ' + d11Font(14) + ';\n' +
    '  transition: background .15s ease, color .15s ease;\n' +
    '}\n' +
    '.ht-menu-list a:hover {\n' +
    '  background: ' + hover + ';\n' +
    '}\n' +
    '.ht-menu-list hr {\n' +
    '  border: none;\n' +
    '  border-top: 1px solid rgba(0, 0, 0, .08);\n' +
    '  margin: 6px 4px;\n' +
    '}';
  var html = '<div style="padding:26px 16px 90px;background:#fff;border-radius:10px">' +
    '<div class="ht-menu"><button class="ht-menu-btn">菜单 ▾</button>' +
    '<ul class="ht-menu-list">' +
    '<li><a href="javascript:void(0)">新建文件</a></li>' +
    '<li><a href="javascript:void(0)">打开…</a></li>' +
    '<li><hr></li>' +
    '<li><a href="javascript:void(0)">导出为 PNG</a></li>' +
    '<li><a href="javascript:void(0)">设置</a></li>' +
    '</ul></div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: w + 'px' } };
}

/* ---------- 17 responsive-breakpoints-generator ---------- */
/* 字段：xs(480) sm(768) md(1024) lg(1280) xl(1440) unit(px) */
function d11Breakpoints() {
  var unit = (val('unit') || 'px').trim();
  if (['px', 'em', 'rem'].indexOf(unit) < 0) unit = 'px';
  var keys = ['xs', 'sm', 'md', 'lg', 'xl'];
  var defs = { xs: 480, sm: 768, md: 1024, lg: 1280, xl: 1440 };
  var vals = {}, lines = [], vars = [];
  for (var i = 0; i < keys.length; i++) {
    var k = keys[i];
    vals[k] = clamp(d11Int(k, defs[k]), 1, 7680);
    vars.push('  --bp-' + k + ': ' + vals[k] + unit + ';');
  }
  for (var j = 0; j < keys.length; j++) {
    var kk = keys[j];
    lines.push('/* ' + kk.toUpperCase() + ' ≥ ' + vals[kk] + unit + ' */\n' +
      '@media (min-width: ' + vals[kk] + unit + ') {\n' +
      '  .ht-col { width: 100%; }\n' +
      '}');
  }
  lines.push('/* 仅在小屏生效 */\n@media (max-width: ' + (vals.xs - 1) + unit + ') {\n  .ht-only-mobile { display: block; }\n}');
  var css = ':root {\n' + vars.join('\n') + '\n}\n\n' + lines.join('\n\n');
  var html = '<div style="padding:14px;background:#fff;border-radius:10px">' +
    '<div style="display:flex;flex-wrap:wrap;gap:8px">' +
    keys.map(function (k) {
      return '<span style="padding:6px 10px;border-radius:6px;background:#eef4ec;color:#2f5a35;' + d11Font(12, 600) + '">' + k + ' ' + vals[k] + unit + '</span>';
    }).join('') +
    '</div><p style="margin:10px 0 0;' + d11Font(13) + ';color:#5b6a58">已生成 ' + (keys.length + 1) + ' 条媒体查询，缩放窗口即可验证断点。</p></div>';
  return { code: css, outs: { preview: d11Prev(css, html), count: String(keys.length + 1) } };
}

/* ---------- 18 container-query-generator ---------- */
/* 字段：min(400) max(0) name(card) property(flex-direction:row) */
function d11ContainerQuery() {
  var min = clamp(d11Int('min', 400), 0, 4000), max = clamp(d11Int('max', 0), 0, 4000);
  var name = (val('name') || 'card').trim().replace(/[^A-Za-z0-9_-]/g, '') || 'card';
  var prop = (val('property') || 'flex-direction:row').trim();
  if (prop.indexOf(':') < 0) prop = prop + ': block';
  var pi = prop.indexOf(':');
  var pName = prop.slice(0, pi).trim() || 'display';
  var pVal = prop.slice(pi + 1).trim() || 'block';
  var cond = [];
  if (min > 0) cond.push('min-width: ' + min + 'px');
  if (max > 0) cond.push('max-width: ' + max + 'px');
  if (!cond.length) cond.push('min-width: 400px');
  var css = '.ht-cq-wrap {\n' +
    '  container-type: inline-size;\n' +
    '  container-name: ' + name + ';\n' +
    '  width: 100%;\n' +
    '}\n' +
    '.ht-cq-item {\n' +
    '  display: flex;\n' +
    '  flex-direction: column;\n' +
    '  gap: 10px;\n' +
    '}\n' +
    '@container ' + name + ' (' + cond.join(') and (') + ') {\n' +
    '  .ht-cq-item {\n' +
    '    ' + pName + ': ' + pVal + ';\n' +
    '  }\n' +
    '}\n' +
    '/* 写法二：匿名容器（就近匹配最近的容器祖先） */\n' +
    '@container (' + cond.join(') and (') + ') {\n' +
    '  .ht-cq-item {\n' +
    '    ' + pName + ': ' + pVal + ';\n' +
    '  }\n' +
    '}';
  var html = '<div style="padding:14px;background:#fff;border-radius:10px">' +
    '<div class="ht-cq-wrap" style="resize:horizontal;overflow:auto;border:1px dashed #c3cdbf;padding:10px;min-width:180px;max-width:100%">' +
    '<div class="ht-cq-item">' +
    '<div style="flex:1;padding:10px;border-radius:8px;background:#eef4ec;' + d11Font(13) + ';color:#2f5a35">A</div>' +
    '<div style="flex:1;padding:10px;border-radius:8px;background:#e7efe1;' + d11Font(13) + ';color:#2f5a35">B</div>' +
    '</div></div>' +
    '<p style="margin:8px 0 0;' + d11Font(12) + ';color:#7c8779">拖动右下角改变容器宽度（≥ ' + (min || 400) + 'px 时切换为 ' + pVal + '）</p></div>';
  return { code: css, outs: { preview: d11Prev(css, html), cond: cond.join(' 且 ') } };
}

/* ---------- 19 css-masonry-layout-generator ---------- */
/* 字段：cols(3) gap(16) radius(10) */
function d11Masonry() {
  var cols = clamp(d11Int('cols', 3), 1, 12), gap = clamp(d11Int('gap', 16), 0, 80),
    radius = clamp(d11Int('radius', 10), 0, 60);
  var css = '/* 方案 A：CSS 多列（最简单，内容按列流式排布） */\n' +
    '.ht-masonry-cols {\n' +
    '  columns: ' + cols + ';\n' +
    '  column-gap: ' + gap + 'px;\n' +
    '}\n' +
    '.ht-masonry-cols > * {\n' +
    '  break-inside: avoid;\n' +
    '  margin-bottom: ' + gap + 'px;\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  overflow: hidden;\n' +
    '}\n\n' +
    '/* 方案 B：Grid + dense（顺序更可控） */\n' +
    '.ht-masonry-grid {\n' +
    '  display: grid;\n' +
    '  grid-template-columns: repeat(' + cols + ', 1fr);\n' +
    '  grid-auto-rows: ' + Math.max(4, Math.round(gap / 2)) + 'px;\n' +
    '  grid-auto-flow: row dense;\n' +
    '  gap: ' + gap + 'px;\n' +
    '}\n' +
    '.ht-masonry-grid > * {\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  overflow: hidden;\n' +
    '}';
  var hs = [90, 140, 70, 110, 160, 80, 120, 100, 130];
  var items = '';
  for (var i = 0; i < 9; i++) {
    items += '<div style="height:' + hs[i] + 'px;background:linear-gradient(135deg,' +
      (i % 2 ? '#3e7c46' : '#2f5a35') + ',#7ab8a0);display:flex;align-items:center;justify-content:center;color:#fff;' + d11Font(13, 600) + '">' + (i + 1) + '</div>';
  }
  var html = '<div style="padding:14px;background:#fff;border-radius:10px">' +
    '<div class="ht-masonry-cols">' + items + '</div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: cols + ' 列 · ' + gap + 'px' } };
}

/* ---------- 20 layout-generator ---------- */
/* 字段：display(flex) dir(row) justify(center) align(center) gap(16) wrap(nowrap) */
function d11Layout() {
  var disp = d11Pos('display', 'flex', ['flex', 'grid']);
  var dir = d11Pos('dir', 'row', ['row', 'row-reverse', 'column', 'column-reverse']);
  var jc = (val('justify') || 'center').trim() || 'center';
  var ai = (val('align') || 'center').trim() || 'center';
  var gap = clamp(d11Int('gap', 16), 0, 200);
  var wrap = (val('wrap') || 'nowrap').trim() || 'nowrap';
  var ok = /^[a-zA-Z-]+$/.test(jc) && /^[a-zA-Z-]+$/.test(ai) && /^[a-zA-Z-]+$/.test(wrap);
  if (!ok) { jc = 'center'; ai = 'center'; wrap = 'nowrap'; }
  var inner = disp === 'grid'
    ? '  display: grid;\n  grid-template-columns: repeat(3, 1fr);\n  gap: ' + gap + 'px;\n  justify-items: ' + jc + ';\n  align-items: ' + ai + ';'
    : '  display: flex;\n  flex-direction: ' + dir + ';\n  justify-content: ' + jc + ';\n  align-items: ' + ai + ';\n  gap: ' + gap + 'px;\n  flex-wrap: ' + wrap + ';';
  var css = '.ht-layout {\n' + inner + '\n}';
  var boxes = '';
  for (var i = 1; i <= 3; i++) {
    boxes += '<div style="min-width:48px;min-height:48px;padding:10px 14px;border-radius:8px;background:' +
      (i === 2 ? '#3e7c46' : '#e7efe1') + ';color:' + (i === 2 ? '#fff' : '#2f5a35') + ';' + d11Font(13, 600) + '">' + i + '</div>';
  }
  var html = '<div style="padding:14px;background:#fff;border-radius:10px">' +
    '<div class="ht-layout" style="min-height:110px;background:#f6f9f4;border:1px dashed #c3cdbf;border-radius:8px">' + boxes + '</div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), mode: disp + ' / ' + dir } };
}

/* ---------- 21 skeleton-screen-generator ---------- */
/* 字段：rows(3) w(320) h(16) radius(6) color(#e3e9df) speed(1.4) */
function d11Skeleton() {
  var rows = clamp(d11Int('rows', 3), 1, 12), w = clamp(d11Int('w', 320), 40, 1600),
    h = clamp(d11Int('h', 16), 4, 200), radius = clamp(d11Int('radius', 6), 0, 60),
    color = d11Color('color', '#e3e9df'), speed = clamp(d11Num('speed', 1.4), 0.1, 20);
  var css = '.ht-skeleton {\n' +
    '  width: ' + w + 'px;\n' +
    '  max-width: 100%;\n' +
    '  display: flex;\n' +
    '  flex-direction: column;\n' +
    '  gap: ' + Math.max(4, Math.round(h * 0.6)) + 'px;\n' +
    '}\n' +
    '.ht-skeleton i {\n' +
    '  display: block;\n' +
    '  height: ' + h + 'px;\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  background: linear-gradient(90deg, ' + color + ' 25%, #f4f7f2 37%, ' + color + ' 63%);\n' +
    '  background-size: 400% 100%;\n' +
    '  animation: ht-shimmer ' + speed + 's ease-in-out infinite;\n' +
    '}\n' +
    '.ht-skeleton i:last-child {\n' +
    '  width: ' + Math.round(w * 0.6) + 'px;\n' +
    '}\n' +
    '@keyframes ht-shimmer {\n' +
    '  0% { background-position: 100% 50%; }\n' +
    '  100% { background-position: 0 50%; }\n' +
    '}';
  var items = '';
  for (var i = 0; i < rows; i++) items += '<i></i>';
  var html = '<div style="padding:16px;background:#fff;border-radius:10px"><div class="ht-skeleton">' + items + '</div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), rows: String(rows) } };
}

/* ---------- 22 animated-background-generator ---------- */
/* 字段：c1(#3e7c46) c2(#7ab8a0) angle(120) dur(12) */
function d11AnimBg() {
  var c1 = d11Color('c1', '#3e7c46'), c2 = d11Color('c2', '#7ab8a0'),
    angle = clamp(d11Int('angle', 120), 0, 360), dur = clamp(d11Num('dur', 12), 0.5, 120);
  var css = '.ht-animbg {\n' +
    '  width: 100%;\n' +
    '  min-height: 160px;\n' +
    '  background: linear-gradient(' + angle + 'deg, ' + c1 + ', ' + c2 + ', ' + c1 + ');\n' +
    '  background-size: 300% 300%;\n' +
    '  animation: ht-bgmove ' + dur + 's ease infinite;\n' +
    '}\n' +
    '@keyframes ht-bgmove {\n' +
    '  0% { background-position: 0% 50%; }\n' +
    '  50% { background-position: 100% 50%; }\n' +
    '  100% { background-position: 0% 50%; }\n' +
    '}';
  var html = '<div class="ht-animbg" style="border-radius:10px;display:flex;align-items:center;justify-content:center">' +
    '<span style="color:#fff;text-shadow:0 1px 6px rgba(0,0,0,.25);' + d11Font(16, 700) + '">动态渐变背景 ' + dur + 's</span></div>';
  return { code: css, outs: { preview: d11Prev(css, html), angle: angle + '° · ' + dur + 's' } };
}

/* ---------- 23 neon-text-generator ---------- */
/* 字段：color(#f2fff7) glow(#3e7c46) intensity(4) size(48) */
function d11Neon() {
  var color = d11Color('color', '#f2fff7'), glow = d11Color('glow', '#3e7c46'),
    n = clamp(d11Int('intensity', 4), 1, 10), size = clamp(d11Int('size', 48), 8, 300);
  var layers = [];
  for (var i = 1; i <= n; i++) {
    layers.push('0 0 ' + (i * 4) + 'px ' + d11Rgba(glow, Math.max(0.15, 0.9 - i * 0.12)));
  }
  layers.push('0 0 ' + Math.round(size * 0.18) + 'px ' + d11Rgba(glow, 0.35));
  var css = '.ht-neon {\n' +
    '  color: ' + color + ';\n' +
    '  font: 700 ' + size + 'px/1.2 "Segoe UI", system-ui, sans-serif;\n' +
    '  text-align: center;\n' +
    '  text-shadow:\n    ' + layers.join(',\n    ') + ';\n' +
    '  animation: ht-neon-flicker 3s infinite alternate;\n' +
    '}\n' +
    '@keyframes ht-neon-flicker {\n' +
    '  0%, 19%, 21%, 23%, 25%, 54%, 56%, 100% { opacity: 1; }\n' +
    '  20%, 24%, 55% { opacity: .78; }\n' +
    '}';
  var html = '<div style="padding:26px 16px;background:#10140f;border-radius:10px">' +
    '<div class="ht-neon">NEON</div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), layers: String(layers.length) } };
}

/* ---------- 24 text-glitch-effect-generator ---------- */
/* 字段：text(GLITCH) c1(#ff2e63) c2(#00e5ff) dur(0.8) */
function d11Glitch() {
  var text = val('text') || 'GLITCH', c1 = d11Color('c1', '#ff2e63'), c2 = d11Color('c2', '#00e5ff'),
    dur = clamp(d11Num('dur', 0.8), 0.05, 20);
  var css = '.ht-glitch {\n' +
    '  position: relative;\n' +
    '  display: inline-block;\n' +
    '  font: 700 46px/1.2 "Segoe UI", system-ui, sans-serif;\n' +
    '  color: #fff;\n' +
    '  letter-spacing: 2px;\n' +
    '}\n' +
    '.ht-glitch::before,\n' +
    '.ht-glitch::after {\n' +
    '  content: attr(data-text);\n' +
    '  position: absolute;\n' +
    '  top: 0;\n' +
    '  left: 0;\n' +
    '  width: 100%;\n' +
    '  background: #10140f;\n' +
    '  overflow: hidden;\n' +
    '}\n' +
    '.ht-glitch::before {\n' +
    '  color: ' + c1 + ';\n' +
    '  animation: ht-glitch-a ' + dur + 's infinite linear alternate-reverse;\n' +
    '}\n' +
    '.ht-glitch::after {\n' +
    '  color: ' + c2 + ';\n' +
    '  animation: ht-glitch-b ' + (dur * 1.3).toFixed(2) + 's infinite linear alternate-reverse;\n' +
    '}\n' +
    '@keyframes ht-glitch-a {\n' +
    '  0% { clip-path: inset(0 0 82% 0); transform: translate(-2px, 1px); }\n' +
    '  20% { clip-path: inset(28% 0 46% 0); transform: translate(2px, -1px); }\n' +
    '  40% { clip-path: inset(62% 0 12% 0); transform: translate(-2px, 0); }\n' +
    '  60% { clip-path: inset(14% 0 66% 0); transform: translate(2px, 1px); }\n' +
    '  80% { clip-path: inset(48% 0 30% 0); transform: translate(-1px, -1px); }\n' +
    '  100% { clip-path: inset(8% 0 78% 0); transform: translate(1px, 0); }\n' +
    '}\n' +
    '@keyframes ht-glitch-b {\n' +
    '  0% { clip-path: inset(74% 0 6% 0); transform: translate(2px, -1px); }\n' +
    '  25% { clip-path: inset(20% 0 58% 0); transform: translate(-2px, 1px); }\n' +
    '  50% { clip-path: inset(40% 0 38% 0); transform: translate(1px, 1px); }\n' +
    '  75% { clip-path: inset(66% 0 16% 0); transform: translate(-1px, -2px); }\n' +
    '  100% { clip-path: inset(4% 0 84% 0); transform: translate(2px, 0); }\n' +
    '}';
  var html = '<div style="padding:26px 16px;background:#10140f;border-radius:10px;text-align:center">' +
    '<span class="ht-glitch" data-text="' + d11Attr(text) + '">' + esc(text) + '</span></div>';
  return { code: css, outs: { preview: d11Prev(css, html), dur: dur + 's' } };
}

/* ---------- 25 css-glow-generator ---------- */
/* 字段：color(#3e7c46) size(20) spread(0) intensity(3) */
function d11Glow() {
  var color = d11Color('color', '#3e7c46'), size = clamp(d11Int('size', 20), 0, 300),
    spread = clamp(d11Int('spread', 0), -50, 200), n = clamp(d11Int('intensity', 3), 1, 8);
  var layers = [];
  for (var i = 1; i <= n; i++) {
    layers.push('0 0 ' + (size * i) + 'px ' + spread + 'px ' + d11Rgba(color, Math.max(0.12, 0.75 - i * 0.14)));
  }
  var css = '.ht-glow {\n' +
    '  width: 120px;\n' +
    '  height: 120px;\n' +
    '  border-radius: 16px;\n' +
    '  background: ' + color + ';\n' +
    '  box-shadow:\n    ' + layers.join(',\n    ') + ';\n' +
    '}';
  var inline = 'box-shadow:' + layers.join(',') + ';';
  var html = '<div style="padding:40px;background:#f6f9f4;border-radius:10px;display:flex;justify-content:center">' +
    '<div style="width:120px;height:120px;border-radius:16px;background:' + color + ';' + inline + '"></div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), layers: String(n) } };
}

/* ---------- 26 text-stroke-generator ---------- */
/* 字段：text(HERON) w(2) stroke(#3e7c46) fill(#ffffff) size(56) */
function d11TextStroke() {
  var text = val('text') || 'HERON', bw = clamp(d11Num('w', 2), 0, 20),
    stroke = d11Color('stroke', '#3e7c46'), fill = d11Color('fill', '#ffffff'),
    size = clamp(d11Int('size', 56), 8, 300);
  var css = '.ht-stroke {\n' +
    '  font: 700 ' + size + 'px/1.2 "Segoe UI", system-ui, sans-serif;\n' +
    '  color: ' + fill + ';\n' +
    '  -webkit-text-stroke: ' + bw + 'px ' + stroke + ';\n' +
    '  paint-order: stroke fill;\n' +
    '  letter-spacing: 2px;\n' +
    '}\n' +
    '/* 兼容：无 -webkit-text-stroke 时退化为 text-shadow 描边 */\n' +
    '@supports not (-webkit-text-stroke: 1px red) {\n' +
    '  .ht-stroke {\n' +
    '    text-shadow:\n' +
    '      -' + bw + 'px 0 ' + stroke + ', ' + bw + 'px 0 ' + stroke + ',\n' +
    '      0 -' + bw + 'px ' + stroke + ', 0 ' + bw + 'px ' + stroke + ';\n' +
    '  }\n' +
    '}';
  var html = '<div style="padding:24px 16px;background:#f6f9f4;border-radius:10px;text-align:center">' +
    '<span class="ht-stroke">' + esc(text) + '</span></div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: bw + 'px ' + stroke } };
}

/* ---------- 27 squiggly-line-generator ---------- */
/* 字段：color(#3e7c46) thickness(3) wavelength(8) */
function d11Squiggly() {
  var color = d11Color('color', '#3e7c46'), th = clamp(d11Num('thickness', 3), 0.5, 30),
    wl = clamp(d11Num('wavelength', 8), 2, 200);
  var w2 = Math.round(wl * 2), h2 = Math.max(2, Math.round(th * 2));
  var ec = color.replace('#', '%23');
  var uri = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='" + w2 + "' height='" + h2 +
    "' viewBox='0 0 " + w2 + " " + h2 + "'%3E%3Cpath d='M0 " + (h2 - th / 2).toFixed(1) +
    " Q " + (w2 * 0.25).toFixed(1) + " 0 " + (w2 * 0.5).toFixed(1) + " " + (h2 / 2).toFixed(1) +
    " T " + w2 + " " + (h2 / 2).toFixed(1) + "' fill='none' stroke='" + ec + "' stroke-width='" + th + "' stroke-linecap='round'/%3E%3C/svg%3E";
  var css = '/* 方案 A：原生 wavy 下划线（最简单） */\n' +
    '.ht-squiggly {\n' +
    '  text-decoration: underline;\n' +
    '  text-decoration-style: wavy;\n' +
    '  text-decoration-color: ' + color + ';\n' +
    '  text-decoration-thickness: ' + th + 'px;\n' +
    '  text-underline-offset: ' + Math.max(2, Math.round(th * 1.6)) + 'px;\n' +
    '}\n\n' +
    '/* 方案 B：SVG 波浪背景（可自定义波长，兼容性更好） */\n' +
    '.ht-squiggly-svg {\n' +
    '  display: inline;\n' +
    '  padding-bottom: ' + h2 + 'px;\n' +
    '  background-image: url("' + uri + '");\n' +
    '  background-repeat: repeat-x;\n' +
    '  background-position: left bottom;\n' +
    '  background-size: ' + w2 + 'px ' + h2 + 'px;\n' +
    '}';
  var html = '<div style="padding:22px 16px;background:#fff;border-radius:10px;' + d11Font(20) + ';color:#2f3a2f">' +
    '原生波浪线：<span class="ht-squiggly">HeronTool 苍鹭工具</span><br><br>' +
    'SVG 波浪线：<span class="ht-squiggly-svg">HeronTool 苍鹭工具</span></div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: th + 'px / ' + wl + 'px' } };
}

/* ---------- 28 css-highlight-marker-generator ---------- */
/* 字段：color(#ffe08a) height(60) opacity(100) */
function d11Highlight() {
  var color = d11Color('color', '#ffe08a'), hh = clamp(d11Int('height', 60), 5, 100),
    op = clamp(d11Num('opacity', 100), 0, 100) / 100;
  var c = d11Rgba(color, op), cSolid = d11Rgba(color, Math.min(1, op + 0.2));
  var css = '.ht-marker {\n' +
    '  background-image: linear-gradient(to top, ' + c + ' 0, ' + c + ' ' + hh + '%, transparent ' + hh + '%);\n' +
    '  background-repeat: no-repeat;\n' +
    '  background-size: 100% 100%;\n' +
    '  padding: 0 2px;\n' +
    '  border-radius: 2px;\n' +
    '}\n' +
    '/* 进阶：带笔触边缘的马克笔效果 */\n' +
    '.ht-marker-brush {\n' +
    '  background-image: linear-gradient(to top, ' + cSolid + ' 0, ' + c + ' ' + hh + '%, transparent ' + hh + '%);\n' +
    '  background-repeat: no-repeat;\n' +
    '  padding: 0 3px;\n' +
    '  box-decoration-break: clone;\n' +
    '  -webkit-box-decoration-break: clone;\n' +
    '}';
  var html = '<div style="padding:22px 16px;background:#fff;border-radius:10px;' + d11Font(18) + ';color:#2f3a2f;line-height:2">' +
    '这是一段文字，其中 <span class="ht-marker">这一句被荧光笔高亮</span> 了；' +
    '另一种 <span class="ht-marker-brush">笔触式高亮</span> 效果。</div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: hh + '% · ' + Math.round(op * 100) + '%' } };
}


/* ---------- 29 line-height-calculator ---------- */
/* 字段：size(16) base(16) lh(1.6) unit(x|px) */
function d11LineHeight() {
  var size = clamp(d11Num('size', 16), 1, 400), base = clamp(d11Num('base', 16), 1, 400);
  var unit = (val('unit') || 'x').trim();
  if (unit !== 'px' && unit !== 'x') unit = 'x';
  var lh = d11Num('lh', 1.6);
  if (lh <= 0) lh = unit === 'px' ? Math.round(size * 1.6) : 1.6;
  var px = unit === 'px' ? lh : lh * size;
  var unitless = px / size;
  var rem = px / base;
  var pct = unitless * 100;
  var r2 = function (v) { return (Math.round(v * 1000) / 1000).toString(); };
  var css = '.ht-lh-demo {\n' +
    '  font-size: ' + size + 'px;\n' +
    '  line-height: ' + r2(unitless) + ';              /* 无单位（推荐） */\n' +
    '  line-height: ' + r2(px) + 'px;            /* 像素 */\n' +
    '  line-height: ' + r2(rem) + 'rem;           /* rem（root=' + base + 'px） */\n' +
    '  line-height: ' + r2(pct) + '%;             /* 百分比 */\n' +
    '}';
  var html = '<div style="padding:16px;background:#fff;border-radius:10px">' +
    '<div style="' + d11Font(size) + ';line-height:' + r2(unitless) + ';color:#2f3a2f;max-width:420px">' +
    '苍鹭工具 HeronTool 提供 967 个在线工具。行高 ' + r2(unitless) + ' 表示每行占据字号高度的 ' + r2(pct) + '%，' +
    '换算成像素约 ' + r2(px) + 'px。</div></div>';
  return {
    code: css,
    outs: {
      preview: d11Prev(css, html),
      px: r2(px) + 'px',
      rem: r2(rem) + 'rem',
      percent: r2(pct) + '%',
      unitless: r2(unitless)
    }
  };
}

/* ---------- 30 letter-spacing-generator ---------- */
/* 字段：size(16) tracking(2) unit(em|px|rem|%) base(16) */
function d11LetterSpacing() {
  var size = clamp(d11Num('size', 16), 1, 400), base = clamp(d11Num('base', 16), 1, 400);
  var unit = (val('unit') || 'em').trim();
  if (['px', 'em', 'rem', '%'].indexOf(unit) < 0) unit = 'em';
  var t = d11Num('tracking', 2);
  var px, em, rem, pct;
  if (unit === 'px') { px = t; em = t / size; rem = t / base; pct = t / size * 100; }
  else if (unit === 'rem') { px = t * base; em = t * base / size; rem = t; pct = t * base / size * 100; }
  else if (unit === '%') { px = t / 100 * size; em = t / 100; rem = t / 100 * size / base; pct = t; }
  else { em = t; px = t * size; rem = t * size / base; pct = t * 100; }
  var r3 = function (v) { return (Math.round(v * 1000) / 1000).toString(); };
  var css = '.ht-tracking {\n' +
    '  font-size: ' + size + 'px;\n' +
    '  letter-spacing: ' + r3(px) + 'px;   /* 像素 */\n' +
    '  letter-spacing: ' + r3(em) + 'em;   /* em（随字号缩放，推荐） */\n' +
    '  letter-spacing: ' + r3(rem) + 'rem;  /* rem */\n' +
    '  letter-spacing: ' + r3(pct) + '%;   /* 百分比 */\n' +
    '}';
  var html = '<div style="padding:16px;background:#fff;border-radius:10px">' +
    '<div style="' + d11Font(size) + ';letter-spacing:' + r3(em) + 'em;color:#2f3a2f">Letter Spacing 字间距示例</div>' +
    '<div style="margin-top:10px;' + d11Font(size) + ';letter-spacing:0;color:#7c8779">Letter Spacing 字间距示例（对照）</div></div>';
  return {
    code: css,
    outs: {
      preview: d11Prev(css, html),
      px: r3(px) + 'px',
      em: r3(em) + 'em',
      rem: r3(rem) + 'rem',
      percent: r3(pct) + '%'
    }
  };
}

/* ---------- 31 css-shapes-generator ---------- */
/* 字段：shape(circle) w(160) h(160) color(#3e7c46) */
function d11Shapes() {
  var shape = d11Pos('shape', 'circle', ['circle', 'triangle', 'diamond', 'hexagon', 'star', 'bubble', 'parallelogram', 'trapezoid', 'ellipse']);
  var w = clamp(d11Int('w', 160), 20, 1000), h = clamp(d11Int('h', 160), 20, 1000),
    color = d11Color('color', '#3e7c46');
  var map = {
    circle: 'circle(50% at 50% 50%)',
    ellipse: 'ellipse(50% 50% at 50% 50%)',
    triangle: 'polygon(50% 0%, 0% 100%, 100% 100%)',
    diamond: 'polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%)',
    hexagon: 'polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%)',
    star: 'polygon(50% 0%, 61% 35%, 98% 35%, 68% 57%, 79% 91%, 50% 70%, 21% 91%, 32% 57%, 2% 35%, 39% 35%)',
    bubble: 'polygon(0% 0%, 100% 0%, 100% 72%, 62% 72%, 48% 100%, 34% 72%, 0% 72%)',
    parallelogram: 'polygon(25% 0%, 100% 0%, 75% 100%, 0% 100%)',
    trapezoid: 'polygon(20% 0%, 80% 0%, 100% 100%, 0% 100%)'
  };
  var cp = map[shape] || map.circle;
  var fill = 'linear-gradient(135deg, ' + color + ', ' + d11Rgba(color, 0.62) + ')';
  var css = '.ht-shape {\n' +
    '  width: ' + w + 'px;\n' +
    '  height: ' + h + 'px;\n' +
    '  clip-path: ' + cp + ';\n' +
    '  background: ' + fill + ';\n' +
    '}';
  var html = '<div style="padding:20px;background:#f6f9f4;border-radius:10px;display:flex;justify-content:center">' +
    '<div class="ht-shape"></div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), shape: shape } };
}

/* ---------- 32 css-mask-generator ---------- */
/* 字段：type(linear) angle(90) stops(3) size(100) */
function d11Mask() {
  var type = d11Pos('type', 'linear', ['linear', 'radial', 'conic']);
  var angle = clamp(d11Int('angle', 90), 0, 360),
    stops = clamp(d11Int('stops', 3), 2, 6), size = clamp(d11Int('size', 100), 5, 100);
  var list = [], i;
  for (i = 0; i < stops; i++) {
    var p = Math.round(i * 100 / (stops - 1));
    var a = i >= Math.ceil(stops / 2) ? 0 : 1;
    list.push('#000 ' + p + '%');
    if (i < stops - 1) {
      var p2 = Math.round((i + 0.5) * 100 / (stops - 1));
      list.push('rgba(0, 0, 0, ' + a + ') ' + p2 + '%');
    }
  }
  var grad;
  if (type === 'radial') grad = 'radial-gradient(circle, ' + list.join(', ') + ')';
  else if (type === 'conic') grad = 'conic-gradient(from ' + angle + 'deg, ' + list.join(', ') + ')';
  else grad = 'linear-gradient(' + angle + 'deg, ' + list.join(', ') + ')';
  var css = '.ht-mask {\n' +
    '  width: 240px;\n' +
    '  height: 160px;\n' +
    '  background: linear-gradient(135deg, #2f5a35, #7ab8a0);\n' +
    '  -webkit-mask-image: ' + grad + ';\n' +
    '  mask-image: ' + grad + ';\n' +
    '  -webkit-mask-size: ' + size + '% ' + size + '%;\n' +
    '  mask-size: ' + size + '% ' + size + '%;\n' +
    '  -webkit-mask-repeat: no-repeat;\n' +
    '  mask-repeat: no-repeat;\n' +
    '  -webkit-mask-position: center;\n' +
    '  mask-position: center;\n' +
    '}';
  var html = '<div style="padding:20px;background:repeating-linear-gradient(45deg,#eef2ec 0 8px,#e3eae1 8px 16px);border-radius:10px;display:flex;justify-content:center">' +
    '<div class="ht-mask"></div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), type: type } };
}

/* ---------- 33 text-mask-generator ---------- */
/* 字段：text(HERON) c1(#3e7c46) c2(#7ab8a0) angle(90) size(56) */
function d11TextMask() {
  var text = val('text') || 'HERON', c1 = d11Color('c1', '#3e7c46'), c2 = d11Color('c2', '#7ab8a0'),
    angle = clamp(d11Int('angle', 90), 0, 360), size = clamp(d11Int('size', 56), 8, 300);
  var css = '.ht-textmask {\n' +
    '  font: 700 ' + size + 'px/1.2 "Segoe UI", system-ui, sans-serif;\n' +
    '  background: linear-gradient(' + angle + 'deg, ' + c1 + ', ' + c2 + ');\n' +
    '  -webkit-background-clip: text;\n' +
    '  background-clip: text;\n' +
    '  -webkit-text-fill-color: transparent;\n' +
    '  color: transparent;\n' +
    '}';
  var html = '<div style="padding:24px 16px;background:#fff;border-radius:10px;text-align:center">' +
    '<span class="ht-textmask">' + esc(text) + '</span></div>';
  return { code: css, outs: { preview: d11Prev(css, html), gradient: c1 + ' → ' + c2 } };
}

/* ---------- 34 border-image-generator ---------- */
/* 字段：url(空=内置示例图) slice(30) width(10) outset(0) repeat(stretch|repeat|round|space) */
function d11BorderImage() {
  var url = (val('url') || '').trim();
  var slice = clamp(d11Int('slice', 30), 0, 200), bw = clamp(d11Int('width', 10), 0, 100),
    outset = clamp(d11Int('outset', 0), 0, 100);
  var repeat = d11Pos('repeat', 'stretch', ['stretch', 'repeat', 'round', 'space']);
  var def = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='90' height='90'%3E%3Crect x='4' y='4' width='82' height='82' fill='none' stroke='%233e7c46' stroke-width='8' stroke-dasharray='16 9'/%3E%3Ccircle cx='45' cy='45' r='9' fill='%237ab8a0'/%3E%3C/svg%3E";
  var src = url ? 'url("' + url.replace(/"/g, '') + '")' : 'url("' + def + '")';
  var css = '.ht-bimg {\n' +
    '  width: 220px;\n' +
    '  min-height: 110px;\n' +
    '  padding: 14px;\n' +
    '  border-style: solid;\n' +
    '  border-width: ' + bw + 'px;\n' +
    '  border-image-source: ' + src + ';\n' +
    '  border-image-slice: ' + slice + ' fill;\n' +
    '  border-image-width: ' + bw + 'px;\n' +
    '  border-image-outset: ' + outset + 'px;\n' +
    '  border-image-repeat: ' + repeat + ';\n' +
    '}';
  var html = '<div style="padding:' + (bw + 12) + 'px;background:#f6f9f4;border-radius:10px;display:flex;justify-content:center">' +
    '<div class="ht-bimg" style="display:flex;align-items:center;justify-content:center;' + d11Font(13) + ';color:#2f5a35">border-image 示例</div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), repeat: repeat + ' / slice ' + slice } };
}

/* ---------- 35 css-corner-fold-generator ---------- */
/* 字段：size(40) color(#d9e2d5) bg(#ffffff) position(br|tr|bl|tl) */
function d11CornerFold() {
  var s = clamp(d11Int('size', 40), 6, 400), color = d11Color('color', '#d9e2d5'),
    bg = d11Color('bg', '#ffffff');
  var pos = d11Pos('position', 'br', ['tl', 'tr', 'bl', 'br']);
  var corner = { tl: 'top: 0; left: 0;', tr: 'top: 0; right: 0;', bl: 'bottom: 0; left: 0;', br: 'bottom: 0; right: 0;' }[pos];
  var gradDir = { tl: '225deg', tr: '135deg', bl: '315deg', br: '45deg' }[pos];
  var foldDir = { tl: '135deg', tr: '225deg', bl: '45deg', br: '315deg' }[pos];
  var css = '.ht-fold {\n' +
    '  position: relative;\n' +
    '  width: 240px;\n' +
    '  min-height: 120px;\n' +
    '  padding: 18px;\n' +
    '  background: ' + bg + ';\n' +
    '  border: 1px solid rgba(0, 0, 0, .07);\n' +
    '  border-radius: 6px;\n' +
    '  overflow: hidden;\n' +
    '}\n' +
    '.ht-fold::before {\n' +
    '  content: \'\';\n' +
    '  position: absolute;\n' +
    '  ' + corner + '\n' +
    '  width: 0;\n' +
    '  height: 0;\n' +
    '  border-style: solid;\n' +
    '  border-width: ' + s + 'px;\n' +
    '  border-color: transparent;\n' +
    '}\n' +
    '.ht-fold::after {\n' +
    '  content: \'\';\n' +
    '  position: absolute;\n' +
    '  ' + corner + '\n' +
    '  width: ' + Math.round(s * 1.42) + 'px;\n' +
    '  height: ' + Math.round(s * 1.42) + 'px;\n' +
    '  background: linear-gradient(' + gradDir + ', ' + color + ' 0%, ' + color + ' 50%, transparent 50%);\n' +
    '  box-shadow: 0 0 ' + Math.round(s / 4) + 'px rgba(0, 0, 0, .12);\n' +
    '  transform-origin: 0 0;\n' +
    '}\n' +
    '/* 折角对应的三角缺口（用 ::before 的 border 三角色实现） */\n' +
    '.ht-fold {\n' +
    '  background: ' + bg + ';\n' +
    '}\n' +
    '.ht-fold-corner {\n' +
    '  position: absolute;\n' +
    '  ' + corner + '\n' +
    '  width: ' + s + 'px;\n' +
    '  height: ' + s + 'px;\n' +
    '  background: linear-gradient(' + foldDir + ', #ffffff 50%, ' + color + ' 50%);\n' +
    '}';
  var html = '<div style="padding:22px;background:#f2f6f0;border-radius:10px;display:flex;justify-content:center">' +
    '<div class="ht-fold"><span class="ht-fold-corner"></span>' +
    '<div style="' + d11Font(13) + ';color:#5b6a58">右下角折角效果，纯 CSS 实现，无需图片。</div></div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), pos: pos + ' · ' + s + 'px' } };
}

/* ---------- 36 css-zigzag-border-generator ---------- */
/* 字段：size(20) color(#3e7c46) side(bottom|top|left|right) type(zigzag|scallop) */
function d11Zigzag() {
  var s = clamp(d11Int('size', 20), 4, 200), color = d11Color('color', '#3e7c46');
  var side = d11Pos('side', 'bottom', ['top', 'bottom', 'left', 'right']);
  var type = d11Pos('type', 'zigzag', ['zigzag', 'scallop']);
  var d = Math.round(s * 2);
  var stripCss, geo;
  if (side === 'bottom') geo = 'left: 0; right: 0; bottom: -' + s + 'px; height: ' + s + 'px;';
  else if (side === 'top') geo = 'left: 0; right: 0; top: -' + s + 'px; height: ' + s + 'px;';
  else if (side === 'left') geo = 'top: 0; bottom: 0; left: -' + s + 'px; width: ' + s + 'px;';
  else geo = 'top: 0; bottom: 0; right: -' + s + 'px; width: ' + s + 'px;';
  if (type === 'scallop') {
    stripCss = '  ' + geo + '\n' +
      '  background-image: radial-gradient(circle at 50% 50%, ' + color + ' 48%, transparent 50%);\n' +
      '  background-size: ' + s + 'px ' + s + 'px;\n' +
      '  background-repeat: ' + (side === 'left' || side === 'right' ? 'repeat-y' : 'repeat-x') + ';\n';
  } else {
    stripCss = '  ' + geo + '\n' +
      '  background-image:\n' +
      '    linear-gradient(135deg, ' + color + ' 25%, transparent 25%),\n' +
      '    linear-gradient(225deg, ' + color + ' 25%, transparent 25%),\n' +
      '    linear-gradient(315deg, ' + color + ' 25%, transparent 25%),\n' +
      '    linear-gradient(45deg, ' + color + ' 25%, transparent 25%);\n' +
      '  background-size: ' + d + 'px ' + d + 'px;\n' +
      '  background-position: -' + s + 'px 0, -' + s + 'px 0, 0 0, 0 0;\n';
  }
  var css = '.ht-zigzag {\n' +
    '  position: relative;\n' +
    '  width: 260px;\n' +
    '  min-height: 110px;\n' +
    '  padding: 20px;\n' +
    '  background: ' + color + ';\n' +
    '  color: #fff;\n' +
    '  ' + d11Font(14) + ';\n' +
    '}\n' +
    '.ht-zigzag::after {\n' +
    '  content: \'\';\n' +
    '  position: absolute;\n' + stripCss +
    '}';
  var html = '<div style="padding:' + (s + 22) + 'px;background:#f6f9f4;border-radius:10px;display:flex;justify-content:center">' +
    '<div class="ht-zigzag">' + (type === 'zigzag' ? '锯齿边 Zigzag' : '扇贝边 Scallop') + ' · ' + side + '</div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: type + ' / ' + side + ' / ' + s + 'px' } };
}

/* ---------- 37 css-multiple-columns-generator ---------- */
/* 字段：count(3) gap(24) rule(1px solid #dde5d9) width(0) */
function d11Columns() {
  var count = clamp(d11Int('count', 3), 1, 12), gap = clamp(d11Int('gap', 24), 0, 200);
  var rule = (val('rule') || '1px solid #dde5d9').trim();
  if (!/^[0-9a-zA-Z#().,%\s-]+$/.test(rule)) rule = '1px solid #dde5d9';
  var width = clamp(d11Int('width', 0), 0, 2000);
  var css = '.ht-cols {\n' +
    (width > 0 ? '  column-width: ' + width + 'px;\n' : '  column-count: ' + count + ';\n') +
    '  column-gap: ' + gap + 'px;\n' +
    '  column-rule: ' + rule + ';\n' +
    '  text-align: justify;\n' +
    '  ' + d11Font(14) + ';\n' +
    '  color: #3f4a3d;\n' +
    '}\n' +
    '.ht-cols > * {\n' +
    '  break-inside: avoid;\n' +
    '}\n' +
    '.ht-cols h4 {\n' +
    '  column-span: all;\n' +
    '  margin: 0 0 10px;\n' +
    '}';
  var p = '苍鹭工具（HeronTool）是一个纯前端的在线工具集合，所有计算都在浏览器本地完成，不上传任何数据。';
  var html = '<div style="padding:16px;background:#fff;border-radius:10px">' +
    '<div class="ht-cols"><h4 style="font-size:15px;color:#2f5a35">多列排版</h4><p>' + d11Rep(p, 6) + '</p></div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: (width > 0 ? width + 'px 列宽' : count + ' 列') } };
}

/* ---------- 38 box-sizing-generator ---------- */
/* 字段：w(200) h(100) pad(20) border(8) mode(compare|content-box|border-box) */
function d11BoxSizing() {
  var w = clamp(d11Int('w', 200), 20, 1200), h = clamp(d11Int('h', 100), 20, 1200),
    pad = clamp(d11Int('pad', 20), 0, 300), bd = clamp(d11Int('border', 8), 0, 100);
  var mode = d11Pos('mode', 'compare', ['compare', 'content-box', 'border-box']);
  var outerCb = w + 2 * pad + 2 * bd, outerBb = w;
  var innerCb = w, innerBb = Math.max(0, w - 2 * pad - 2 * bd);
  var lines = [];
  if (mode === 'border-box' || mode === 'compare') {
    lines.push('.ht-box-border {\n' +
      '  box-sizing: border-box;\n' +
      '  width: ' + w + 'px;\n' +
      '  height: ' + h + 'px;\n' +
      '  padding: ' + pad + 'px;\n' +
      '  border: ' + bd + 'px solid ' + '#3e7c46' + ';\n' +
      '  /* 实际占位：' + w + ' × ' + h + 'px，内容区 ' + innerBb + 'px */\n' +
      '}');
  }
  if (mode === 'content-box' || mode === 'compare') {
    lines.push('.ht-box-content {\n' +
      '  box-sizing: content-box;\n' +
      '  width: ' + w + 'px;\n' +
      '  height: ' + h + 'px;\n' +
      '  padding: ' + pad + 'px;\n' +
      '  border: ' + bd + 'px solid #7ab8a0;\n' +
      '  /* 实际占位：' + outerCb + ' × ' + (h + 2 * pad + 2 * bd) + 'px，内容区 ' + innerCb + 'px */\n' +
      '}');
  }
  lines.push('/* 全局推荐写法 */\n*, *::before, *::after { box-sizing: border-box; }');
  var css = lines.join('\n\n');
  function card(cls, bdc, note, total) {
    return '<div style="margin:8px"><div class="' + cls + '" style="box-sizing:' + cls.replace('ht-box-', '') + ';width:' + w +
      'px;height:' + h + 'px;padding:' + pad + 'px;border:' + bd + 'px solid ' + bdc + ';background:#e7efe1;display:flex;align-items:center;justify-content:center;text-align:center">' +
      '<span style="' + d11Font(12) + ';color:#2f5a35">' + note + '<br>占位 ' + total + 'px</span></div></div>';
  }
  var html = '<div style="padding:16px;background:#fff;border-radius:10px">' +
    '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start">' +
    (mode === 'content-box' ? '' : card('ht-box-border', '#3e7c46', 'border-box', outerBb)) +
    (mode === 'border-box' ? '' : card('ht-box-content', '#7ab8a0', 'content-box', outerCb)) +
    '</div>' +
    '<p style="margin:10px 0 0;' + d11Font(12) + ';color:#5b6a58">内容 w=' + w + ' · padding=' + pad + ' · border=' + bd +
    '：border-box 内容区 ' + innerBb + 'px，content-box 内容区 ' + innerCb + 'px</p></div>';
  return { code: css, outs: { preview: d11Prev(css, html), contentBox: outerCb + 'px', borderBox: outerBb + 'px' } };
}

/* ---------- 39 css-tooltip-generator ---------- */
/* 字段：pos(top) bg(#2f5a35) fg(#ffffff) radius(6) text(提示文字) */
function d11Tooltip() {
  var pos = d11Pos('pos', 'top', ['top', 'bottom', 'left', 'right']);
  var bg = d11Color('bg', '#2f5a35'), fg = d11Color('fg', '#ffffff'),
    radius = clamp(d11Int('radius', 6), 0, 40), text = val('text') || '提示文字';
  var off = '12px';
  var base = '.ht-tip {\n' +
    '  position: relative;\n' +
    '  display: inline-block;\n' +
    '  cursor: help;\n' +
    '}\n' +
    '.ht-tip::after {\n' +
    '  content: attr(data-tip);\n' +
    '  position: absolute;\n' +
    '  padding: 6px 10px;\n' +
    '  background: ' + bg + ';\n' +
    '  color: ' + fg + ';\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  white-space: nowrap;\n' +
    '  ' + d11Font(12) + ';\n' +
    '  opacity: 0;\n' +
    '  pointer-events: none;\n' +
    '  transition: opacity .18s ease, transform .18s ease;\n' +
    '  z-index: 30;\n' +
    '}\n' +
    '.ht-tip:hover::after,\n' +
    '.ht-tip:focus-visible::after {\n' +
    '  opacity: 1;\n' +
    '  transform: none;\n' +
    '}';
  var posCss = pos === 'top'
    ? { place: 'bottom: calc(100% + ' + off + '); left: 50%;', from: 'translate(-50%, 6px)', to: 'translate(-50%, 0)' }
    : pos === 'bottom'
      ? { place: 'top: calc(100% + ' + off + '); left: 50%;', from: 'translate(-50%, -6px)', to: 'translate(-50%, 0)' }
      : pos === 'left'
        ? { place: 'right: calc(100% + ' + off + '); top: 50%;', from: 'translate(6px, -50%)', to: 'translate(0, -50%)' }
        : { place: 'left: calc(100% + ' + off + '); top: 50%;', from: 'translate(-6px, -50%)', to: 'translate(0, -50%)' };
  var css = base + '\n.ht-tip::after {\n  ' + posCss.place + '\n  transform: ' + posCss.from + ';\n}\n' +
    '.ht-tip:hover::after {\n  transform: ' + posCss.to + ';\n}';
  var html = '<div style="padding:56px 20px;background:#fff;border-radius:10px;display:flex;justify-content:center">' +
    '<span class="ht-tip" data-tip="' + d11Attr(text) + '" tabindex="0" style="' + d11Font(14) + ';color:#2f5a35;border-bottom:1px dashed #7ab8a0">悬停查看提示</span></div>';
  return { code: css, outs: { preview: d11Prev(css, html), pos: pos } };
}

/* ---------- 40 css-ribbon-generator ---------- */
/* 字段：text(NEW) color(#ffffff) bg(#3e7c46) position(tr|tl|br|bl) size(34) */
function d11Ribbon() {
  var text = val('text') || 'NEW', color = d11Color('color', '#ffffff'), bg = d11Color('bg', '#3e7c46');
  var pos = d11Pos('position', 'tr', ['tl', 'tr', 'bl', 'br']);
  var s = clamp(d11Int('size', 34), 10, 200);
  var isTop = pos.charAt(0) === 't', isRight = pos.charAt(1) === 'r';
  var anchor = (isTop ? 'top' : 'bottom') + ': 0; ' + (isRight ? 'right' : 'left') + ': 0;';
  var pad = 'translate(' + (isRight ? '' : '-') + Math.round(s * 0.42) + 'px, ' + (isTop ? '' : '-') + Math.round(s * 0.42) + 'px) rotate(' + (isTop ? 45 : -45) + 'deg)';
  var css = '.ht-ribbon {\n' +
    '  position: relative;\n' +
    '  width: 240px;\n' +
    '  min-height: 120px;\n' +
    '  padding: 20px;\n' +
    '  background: #fff;\n' +
    '  border: 1px solid #e2e9df;\n' +
    '  border-radius: 10px;\n' +
    '  overflow: hidden;\n' +
    '}\n' +
    '.ht-ribbon-band {\n' +
    '  position: absolute;\n' +
    '  ' + anchor + '\n' +
    '  width: ' + Math.round(s * 3.2) + 'px;\n' +
    '  padding: ' + Math.max(2, Math.round(s * 0.12)) + 'px 0;\n' +
    '  background: ' + bg + ';\n' +
    '  color: ' + color + ';\n' +
    '  text-align: center;\n' +
    '  ' + d11Font(Math.max(10, Math.round(s * 0.34)), 700) + ';\n' +
    '  letter-spacing: 1px;\n' +
    '  transform: ' + pad + ';\n' +
    '  transform-origin: center;\n' +
    '  box-shadow: 0 4px 12px rgba(0, 0, 0, .18);\n' +
    '}';
  var html = '<div style="padding:24px;background:#f6f9f4;border-radius:10px;display:flex;justify-content:center">' +
    '<div class="ht-ribbon"><span class="ht-ribbon-band">' + esc(text) + '</span>' +
    '<div style="' + d11Font(13) + ';color:#5b6a58">角落丝带角标，纯 CSS 实现。</div></div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), pos: pos } };
}

/* ---------- 41 notification-badge-generator ---------- */
/* 字段：count(5) color(#e04b3a) size(20) pulse(1) */
function d11Badge() {
  var count = clamp(d11Int('count', 5), 0, 9999), color = d11Color('color', '#e04b3a'),
    s = clamp(d11Int('size', 20), 8, 120), pulse = val('pulse') === '0' ? 0 : 1;
  var label = count > 99 ? '99+' : String(count);
  var css = '.ht-badge-wrap {\n' +
    '  position: relative;\n' +
    '  display: inline-block;\n' +
    '}\n' +
    '.ht-badge {\n' +
    '  position: absolute;\n' +
    '  top: -' + Math.round(s * 0.35) + 'px;\n' +
    '  right: -' + Math.round(s * 0.35) + 'px;\n' +
    '  min-width: ' + s + 'px;\n' +
    '  height: ' + s + 'px;\n' +
    '  padding: 0 ' + Math.max(3, Math.round(s * 0.2)) + 'px;\n' +
    '  border-radius: ' + Math.round(s / 2) + 'px;\n' +
    '  background: ' + color + ';\n' +
    '  color: #fff;\n' +
    '  display: inline-flex;\n' +
    '  align-items: center;\n' +
    '  justify-content: center;\n' +
    '  ' + d11Font(Math.max(9, Math.round(s * 0.55)), 700) + ';\n' +
    '  border: 2px solid #fff;\n' +
    '  box-sizing: border-box;\n' +
    '}\n' +
    (pulse ? '.ht-badge::after {\n' +
      '  content: \'\';\n' +
      '  position: absolute;\n' +
      '  inset: -2px;\n' +
      '  border-radius: inherit;\n' +
      '  border: 2px solid ' + color + ';\n' +
      '  animation: ht-badge-pulse 1.6s ease-out infinite;\n' +
      '}\n' +
      '@keyframes ht-badge-pulse {\n' +
      '  0% { transform: scale(1); opacity: .8; }\n' +
      '  100% { transform: scale(2); opacity: 0; }\n' +
      '}\n' : '') +
    '.ht-badge.is-dot {\n' +
    '  min-width: ' + Math.max(6, Math.round(s * 0.5)) + 'px;\n' +
    '  height: ' + Math.max(6, Math.round(s * 0.5)) + 'px;\n' +
    '  padding: 0;\n' +
    '  font-size: 0;\n' +
    '}';
  var html = '<div style="padding:34px 24px;background:#fff;border-radius:10px;display:flex;gap:34px;justify-content:center">' +
    '<span class="ht-badge-wrap" style="' + d11Font(26) + '">🔔<span class="ht-badge">' + label + '</span></span>' +
    '<span class="ht-badge-wrap" style="' + d11Font(26) + '">✉<span class="ht-badge is-dot"></span></span>' +
    '</div>';
  return { code: css, outs: { preview: d11Prev(css, html), count: label } };
}

/* ---------- 42 input-range-styler ---------- */
/* 字段：w(240) h(6) track(#dde5d9) thumb(#3e7c46) color(#3e7c46) */
function d11Range() {
  var w = clamp(d11Int('w', 240), 40, 1600), h = clamp(d11Int('h', 6), 1, 60),
    track = d11Color('track', '#dde5d9'), thumb = d11Color('thumb', '#3e7c46'),
    accent = d11Color('color', '#3e7c46');
  var ts = Math.max(8, h * 2 + 6);
  var css = '.ht-range {\n' +
    '  -webkit-appearance: none;\n' +
    '  appearance: none;\n' +
    '  width: ' + w + 'px;\n' +
    '  max-width: 100%;\n' +
    '  height: ' + ts + 'px;\n' +
    '  background: transparent;\n' +
    '  cursor: pointer;\n' +
    '}\n' +
    '/* WebKit / Blink 轨道 */\n' +
    '.ht-range::-webkit-slider-runnable-track {\n' +
    '  height: ' + h + 'px;\n' +
    '  border-radius: ' + Math.round(h / 2) + 'px;\n' +
    '  background: ' + track + ';\n' +
    '}\n' +
    '.ht-range::-webkit-slider-thumb {\n' +
    '  -webkit-appearance: none;\n' +
    '  appearance: none;\n' +
    '  width: ' + ts + 'px;\n' +
    '  height: ' + ts + 'px;\n' +
    '  margin-top: -' + Math.round((ts - h) / 2) + 'px;\n' +
    '  border-radius: 50%;\n' +
    '  background: ' + thumb + ';\n' +
    '  border: 2px solid #fff;\n' +
    '  box-shadow: 0 2px 6px rgba(0, 0, 0, .2);\n' +
    '  transition: box-shadow .15s ease;\n' +
    '}\n' +
    '.ht-range:hover::-webkit-slider-thumb,\n' +
    '.ht-range:focus::-webkit-slider-thumb {\n' +
    '  box-shadow: 0 0 0 6px ' + d11Rgba(accent, 0.2) + ';\n' +
    '}\n' +
    '/* Firefox */\n' +
    '.ht-range::-moz-range-track {\n' +
    '  height: ' + h + 'px;\n' +
    '  border-radius: ' + Math.round(h / 2) + 'px;\n' +
    '  background: ' + track + ';\n' +
    '}\n' +
    '.ht-range::-moz-range-progress {\n' +
    '  height: ' + h + 'px;\n' +
    '  border-radius: ' + Math.round(h / 2) + 'px;\n' +
    '  background: ' + accent + ';\n' +
    '}\n' +
    '.ht-range::-moz-range-thumb {\n' +
    '  width: ' + ts + 'px;\n' +
    '  height: ' + ts + 'px;\n' +
    '  border-radius: 50%;\n' +
    '  background: ' + thumb + ';\n' +
    '  border: 2px solid #fff;\n' +
    '  box-shadow: 0 2px 6px rgba(0, 0, 0, .2);\n' +
    '}\n' +
    '.ht-range:focus {\n' +
    '  outline: none;\n' +
    '}';
  var html = '<div style="padding:20px;background:#fff;border-radius:10px">' +
    '<input type="range" class="ht-range" min="0" max="100" value="42">' +
    '</div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: w + 'px · 轨道 ' + h + 'px' } };
}


/* ---------- 43 form-style-generator ---------- */
/* 字段：radius(8) color(#3e7c46) bg(#ffffff) border(#c9d3c5) size(14) */
function d11Form() {
  var radius = clamp(d11Int('radius', 8), 0, 40), color = d11Color('color', '#3e7c46'),
    bgc = d11Color('bg', '#ffffff'), bd = d11Color('border', '#c9d3c5'),
    size = clamp(d11Int('size', 14), 8, 40);
  var pad = Math.round(size * 0.72);
  var css = '.ht-form {\n' +
    '  width: 320px;\n' +
    '  max-width: 100%;\n' +
    '  padding: ' + (pad * 2) + 'px;\n' +
    '  background: ' + bgc + ';\n' +
    '  border: 1px solid ' + bd + ';\n' +
    '  border-radius: ' + radius + 'px;\n' +
    '  font-family: system-ui, sans-serif;\n' +
    '}\n' +
    '.ht-form label {\n' +
    '  display: block;\n' +
    '  margin-bottom: 6px;\n' +
    '  font-size: ' + size + 'px;\n' +
    '  font-weight: 600;\n' +
    '  color: #3f4a3d;\n' +
    '}\n' +
    '.ht-form input,\n' +
    '.ht-form textarea,\n' +
    '.ht-form select {\n' +
    '  width: 100%;\n' +
    '  box-sizing: border-box;\n' +
    '  padding: ' + pad + 'px ' + (pad + 2) + 'px;\n' +
    '  margin-bottom: 14px;\n' +
    '  border: 1px solid ' + bd + ';\n' +
    '  border-radius: ' + Math.max(2, Math.round(radius * 0.7)) + 'px;\n' +
    '  background: #fff;\n' +
    '  font-size: ' + size + 'px;\n' +
    '  color: #2f3a2f;\n' +
    '  outline: none;\n' +
    '  transition: border-color .18s ease, box-shadow .18s ease;\n' +
    '}\n' +
    '.ht-form input:focus,\n' +
    '.ht-form textarea:focus,\n' +
    '.ht-form select:focus {\n' +
    '  border-color: ' + color + ';\n' +
    '  box-shadow: 0 0 0 3px ' + d11Rgba(color, 0.18) + ';\n' +
    '}\n' +
    '.ht-form input:valid:not(:placeholder-shown) {\n' +
    '  border-color: #4caf7d;\n' +
    '}\n' +
    '.ht-form input:invalid:not(:placeholder-shown) {\n' +
    '  border-color: #e06c5a;\n' +
    '  box-shadow: 0 0 0 3px rgba(224, 108, 90, .15);\n' +
    '}\n' +
    '.ht-form .ht-hint {\n' +
    '  display: block;\n' +
    '  margin: -10px 0 12px;\n' +
    '  font-size: ' + Math.max(10, size - 2) + 'px;\n' +
    '  color: #8b958a;\n' +
    '}\n' +
    '.ht-form button {\n' +
    '  width: 100%;\n' +
    '  padding: ' + pad + 'px;\n' +
    '  border: none;\n' +
    '  border-radius: ' + Math.max(2, Math.round(radius * 0.7)) + 'px;\n' +
    '  background: ' + color + ';\n' +
    '  color: #fff;\n' +
    '  font-size: ' + size + 'px;\n' +
    '  font-weight: 600;\n' +
    '  cursor: pointer;\n' +
    '  transition: filter .18s ease, transform .12s ease;\n' +
    '}\n' +
    '.ht-form button:hover {\n' +
    '  filter: brightness(1.08);\n' +
    '}\n' +
    '.ht-form button:active {\n' +
    '  transform: translateY(1px);\n' +
    '}';
  var html = '<div style="padding:16px;background:#f6f9f4;border-radius:10px;display:flex;justify-content:center">' +
    '<form class="ht-form" onsubmit="return false;">' +
    '<label for="ht-f-mail">邮箱</label>' +
    '<input id="ht-f-mail" type="email" placeholder="you@example.com" required>' +
    '<span class="ht-hint">我们不会公开你的邮箱</span>' +
    '<label for="ht-f-pwd">密码</label>' +
    '<input id="ht-f-pwd" type="password" placeholder="至少 8 位" required>' +
    '<button type="submit">创建账号</button>' +
    '</form></div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: size + 'px / r' + radius } };
}

/* ---------- 44 css-3d-transform-generator ---------- */
/* 字段：rx(20) ry(30) rz(0) tz(0) perspective(600) */
function d11Transform3D() {
  var rx = clamp(d11Num('rx', 20), -360, 360), ry = clamp(d11Num('ry', 30), -360, 360),
    rz = clamp(d11Num('rz', 0), -360, 360), tz = clamp(d11Num('tz', 0), -2000, 2000),
    persp = clamp(d11Int('perspective', 600), 0, 5000);
  var css = '.ht-3d-scene {\n' +
    '  perspective: ' + persp + 'px;\n' +
    '  perspective-origin: 50% 50%;\n' +
    '}\n' +
    '.ht-3d-box {\n' +
    '  width: 120px;\n' +
    '  height: 120px;\n' +
    '  margin: 0 auto;\n' +
    '  background: linear-gradient(135deg, #2f5a35, #7ab8a0);\n' +
    '  border-radius: 12px;\n' +
    '  transform-style: preserve-3d;\n' +
    '  transform: perspective(' + persp + 'px) rotateX(' + rx + 'deg) rotateY(' + ry + 'deg) rotateZ(' + rz + 'deg) translateZ(' + tz + 'px);\n' +
    '  transition: transform .3s ease;\n' +
    '}\n' +
    '/* 纯 rotate3d 写法（等价） */\n' +
    '.ht-3d-alt {\n' +
    '  transform: rotate3d(' + (rx / 90).toFixed(3) + ', ' + (ry / 90).toFixed(3) + ', ' + (rz / 90).toFixed(3) + ', 90deg) translateZ(' + tz + 'px);\n' +
    '}';
  var html = '<div class="ht-3d-scene" style="padding:36px 20px;background:#f6f9f4;border-radius:10px">' +
    '<div class="ht-3d-box"></div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), matrix: 'rotateX ' + rx + '° / rotateY ' + ry + '°' } };
}

/* ---------- 45 perspective-generator ---------- */
/* 字段：persp(600) sx(10) sy(0) origin(center|left top|...) */
function d11Perspective() {
  var persp = clamp(d11Int('persp', 600), 0, 5000),
    sx = clamp(d11Num('sx', 10), -85, 85), sy = clamp(d11Num('sy', 0), -85, 85);
  var origin = (val('origin') || 'center').trim();
  if (!/^[a-zA-Z0-9%\s.-]+$/.test(origin)) origin = 'center';
  var css = '.ht-persp-scene {\n' +
    '  perspective: ' + persp + 'px;\n' +
    '  perspective-origin: ' + origin + ';\n' +
    '}\n' +
    '.ht-persp-box {\n' +
    '  width: 160px;\n' +
    '  height: 90px;\n' +
    '  margin: 0 auto;\n' +
    '  background: linear-gradient(135deg, #3e7c46, #a8d5bd);\n' +
    '  border-radius: 8px;\n' +
    '  transform: skew(' + sx + 'deg, ' + sy + 'deg);\n' +
    '  transform-origin: ' + origin + ';\n' +
    '  transition: transform .25s ease;\n' +
    '}\n' +
    '.ht-persp-box:hover {\n' +
    '  transform: skew(' + sx + 'deg, ' + sy + 'deg) rotateX(12deg);\n' +
    '}';
  var html = '<div class="ht-persp-scene" style="padding:32px 20px;background:#f6f9f4;border-radius:10px">' +
    '<div class="ht-persp-box"></div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), spec: persp + 'px · skew(' + sx + ',' + sy + ')' } };
}

/* ---------- 46 particle-background-generator ---------- */
/* 字段：count(20) color(#ffffff) size(4) dur(20) —— 纯 CSS，无需 canvas */
function d11Particles() {
  var n = clamp(d11Int('count', 20), 1, 120), color = d11Color('color', '#ffffff'),
    size = clamp(d11Int('size', 4), 1, 60), dur = clamp(d11Num('dur', 20), 1, 300);
  function h(i, s) { var x = Math.sin(i * (s || 127.1) + 3.7) * 43758.5453; return x - Math.floor(x); }
  var layers = [], sizes = [], i;
  for (i = 0; i < n; i++) {
    var sz = Math.max(1, Math.round(size * (0.45 + h(i, 311.7) * 1.1)));
    var px = Math.round(h(i, 127.1) * 100), py = Math.round(h(i, 269.5) * 100);
    layers.push('radial-gradient(circle at ' + px + '% ' + py + '%, ' + color + ' 0, ' + color + ' 40%, transparent 42%)');
    sizes.push(sz + 'px ' + sz + 'px');
  }
  var css = '.ht-particles {\n' +
    '  position: relative;\n' +
    '  width: 100%;\n' +
    '  min-height: 220px;\n' +
    '  background: #101810;\n' +
    '  overflow: hidden;\n' +
    '  border-radius: 10px;\n' +
    '}\n' +
    '.ht-particles::before {\n' +
    '  content: \'\';\n' +
    '  position: absolute;\n' +
    '  left: 0;\n' +
    '  top: 0;\n' +
    '  width: 100%;\n' +
    '  height: 200%;\n' +
    '  background-image:\n    ' + layers.join(',\n    ') + ';\n' +
    '  background-size: ' + sizes.join(', ') + ';\n' +
    '  background-repeat: repeat;\n' +
    '  animation: ht-p-drift ' + dur + 's linear infinite;\n' +
    '}\n' +
    '@keyframes ht-p-drift {\n' +
    '  from { transform: translateY(0); }\n' +
    '  to { transform: translateY(-50%); }\n' +
    '}';
  var html = '<div class="ht-particles"><div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#e7efe1;' + d11Font(14, 600) + '">' + n + ' 个粒子 · ' + dur + 's</div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), count: String(n) } };
}

/* ---------- 47 duotone-generator ---------- */
/* 字段：c1(#3e7c46) c2(#7ab8a0) blend(multiply) opacity(60) */
function d11Duotone() {
  var c1 = d11Color('c1', '#3e7c46'), c2 = d11Color('c2', '#7ab8a0');
  var blend = d11Pos('blend', 'multiply', ['multiply', 'screen', 'overlay', 'color', 'hue', 'luminosity', 'darken', 'lighten']);
  var op = clamp(d11Num('opacity', 60), 0, 100) / 100;
  var css = '.ht-duotone {\n' +
    '  position: relative;\n' +
    '  width: 280px;\n' +
    '  height: 180px;\n' +
    '  overflow: hidden;\n' +
    '  border-radius: 10px;\n' +
    '  background-image: linear-gradient(120deg, #cfd8cc, #7f8f7c 45%, #2f3a2f);\n' +
    '  background-size: cover;\n' +
    '  background-position: center;\n' +
    '  isolation: isolate;\n' +
    '}\n' +
    '.ht-duotone::before,\n' +
    '.ht-duotone::after {\n' +
    '  content: \'\';\n' +
    '  position: absolute;\n' +
    '  inset: 0;\n' +
    '}\n' +
    '.ht-duotone::before {\n' +
    '  background: ' + c1 + ';\n' +
    '  mix-blend-mode: ' + blend + ';\n' +
    '  opacity: ' + op.toFixed(2) + ';\n' +
    '}\n' +
    '.ht-duotone::after {\n' +
    '  background: ' + c2 + ';\n' +
    '  mix-blend-mode: screen;\n' +
    '  opacity: ' + (op * 0.7).toFixed(2) + ';\n' +
    '}\n' +
    '/* 提示：把 .ht-duotone 的 background-image 换成你的图片即可 */';
  var html = '<div style="padding:18px;background:#f6f9f4;border-radius:10px;display:flex;justify-content:center">' +
    '<div class="ht-duotone"></div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), blend: blend + ' ' + Math.round(op * 100) + '%' } };
}

/* ---------- 48 halftone-dither-generator ---------- */
/* 字段：dot(6) gap(3) color(#3e7c46) bg(#f5f8f2) */
function d11Halftone() {
  var dot = clamp(d11Num('dot', 6), 1, 100), gap = clamp(d11Num('gap', 3), 0, 100),
    color = d11Color('color', '#3e7c46'), bgc = d11Color('bg', '#f5f8f2');
  var cell = (dot + gap * 2).toFixed(2);
  var css = '.ht-halftone {\n' +
    '  width: 100%;\n' +
    '  min-height: 180px;\n' +
    '  background-color: ' + bgc + ';\n' +
    '  background-image: radial-gradient(circle at center, ' + color + ' 0, ' + color + ' ' + (dot / 2).toFixed(2) + 'px, transparent ' + (dot / 2 + 0.4).toFixed(2) + 'px);\n' +
    '  background-size: ' + cell + 'px ' + cell + 'px;\n' +
    '}\n' +
    '/* 进阶：加一层渐变遮罩，做出由密到疏的半调过渡 */\n' +
    '.ht-halftone-fade {\n' +
    '  background-color: ' + bgc + ';\n' +
    '  background-image: radial-gradient(circle at center, ' + color + ' 0, ' + color + ' ' + (dot / 2).toFixed(2) + 'px, transparent ' + (dot / 2 + 0.4).toFixed(2) + 'px);\n' +
    '  background-size: ' + cell + 'px ' + cell + 'px;\n' +
    '  -webkit-mask-image: linear-gradient(90deg, #000 10%, rgba(0, 0, 0, .15) 90%);\n' +
    '  mask-image: linear-gradient(90deg, #000 10%, rgba(0, 0, 0, .15) 90%);\n' +
    '}';
  var html = '<div style="padding:14px;background:#fff;border-radius:10px">' +
    '<div class="ht-halftone" style="border-radius:8px"></div>' +
    '<div class="ht-halftone-fade" style="border-radius:8px;min-height:70px;margin-top:10px"></div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), cell: cell + 'px' } };
}

/* ---------- 49 svg-text-path-generator ---------- */
/* 字段：text(HeronTool 苍鹭工具) path(空=内置贝塞尔) size(28) color(#3e7c46) */
function d11SvgTextPath() {
  var text = val('text') || 'HeronTool 苍鹭工具 · 967 个在线工具';
  var path = (val('path') || '').trim();
  if (!path) path = 'M20,110 C120,10 220,190 340,100 C440,30 540,150 640,80';
  if (!/^[MmLlCcSsQqTtAaHhVvZz0-9.,\s+-]+$/.test(path)) path = 'M20,110 C120,10 220,190 340,100 C440,30 540,150 640,80';
  var size = clamp(d11Int('size', 28), 6, 200), color = d11Color('color', '#3e7c46');
  var svg = '<svg viewBox="0 0 660 180" width="100%" height="180" xmlns="http://www.w3.org/2000/svg">\n' +
    '  <path id="ht-text-path" d="' + d11Attr(path) + '" fill="none" stroke="' + d11Rgba(color, 0.35) + '" stroke-width="1" stroke-dasharray="4 4"></path>\n' +
    '  <text font-family="system-ui, sans-serif" font-size="' + size + '" font-weight="700" fill="' + color + '">\n' +
    '    <textPath href="#ht-text-path" startOffset="50%" text-anchor="middle">' + esc(text) + '</textPath>\n' +
    '  </text>\n' +
    '</svg>';
  var note = '<!-- 用法：把上面的 <svg> 直接放进 HTML；修改 <path> 的 d 属性即可改变文字走向 -->\n';
  var html = '<div style="padding:14px;background:#fff;border-radius:10px">' + svg + '</div>';
  return { code: note + svg, outs: { preview: html, size: size + 'px' } };
}

/* ---------- 50 gridlover ---------- */
/* 字段：fs(16) scale(1.5) lh(1.5) base(8) */
function d11Gridlover() {
  var fs = clamp(d11Num('fs', 16), 6, 200), scale = clamp(d11Num('scale', 1.5), 1, 4),
    lhm = clamp(d11Num('lh', 1.5), 0.6, 4), base = clamp(d11Num('base', 8), 1, 100);
  function snap(v) { return Math.max(base, Math.round(v / base) * base); }
  var bodyLh = snap(fs * lhm);
  var out = [], i;
  var tags = ['h6', 'h5', 'h4', 'h3', 'h2', 'h1'];
  for (i = 0; i < tags.length; i++) {
    var sz = fs * Math.pow(scale, i);
    var lh = snap(sz * lhm);
    out.push(tags[i] + ' {\n' +
      '  font-size: ' + (Math.round(sz * 100) / 100) + 'px;\n' +
      '  line-height: ' + lh + 'px;\n' +
      '  margin-top: ' + lh + 'px;\n' +
      '  margin-bottom: ' + lh + 'px;\n' +
      '}');
  }
  out.push('p {\n' +
    '  font-size: ' + fs + 'px;\n' +
    '  line-height: ' + bodyLh + 'px;\n' +
    '  margin-bottom: ' + bodyLh + 'px;\n' +
    '}');
  var css = '/* 基线网格：字号 ' + fs + 'px · 比例 ' + scale + ' · 行高 ' + lhm + ' · 基线单位 ' + base + 'px */\n' +
    ':root {\n' +
    '  --ht-base: ' + base + 'px;\n' +
    '  --ht-fs: ' + fs + 'px;\n' +
    '  --ht-lh: ' + bodyLh + 'px;\n' +
    '}\n\n' + out.join('\n\n');
  var prev = '';
  for (i = tags.length - 1; i >= 0; i--) {
    var s2 = fs * Math.pow(scale, i), l2 = snap(s2 * lhm);
    prev += '<' + tags[i] + ' style="margin:0 0 ' + l2 + 'px;font-size:' + (Math.round(s2 * 100) / 100) + 'px;line-height:' + l2 + 'px;color:#2f3a2f;font-family:system-ui">标题 ' + tags[i].toUpperCase() + '</' + tags[i] + '>';
  }
  prev += '<p style="margin:0;font-size:' + fs + 'px;line-height:' + bodyLh + 'px;color:#3f4a3d;font-family:system-ui">正文段落示例：苍鹭工具 HeronTool 提供 967 个在线工具，全部在浏览器本地计算完成。</p>';
  var html = '<div style="padding:16px;background:#fff;border-radius:10px">' + prev + '</div>';
  return {
    code: css,
    outs: {
      preview: html,
      body: fs + 'px / ' + bodyLh + 'px',
      h1: (Math.round(fs * Math.pow(scale, 5) * 100) / 100) + 'px'
    }
  };
}

/* ---------- 51 quantity-query-generator ---------- */
/* 字段：min(3) max(0) selector(.item) property(flex-basis:33%) */
function d11QuantityQuery() {
  var min = clamp(d11Int('min', 3), 1, 100), max = clamp(d11Int('max', 0), 0, 100);
  var sel = (val('selector') || '.item').trim() || '.item';
  if (!/^[.#:a-zA-Z0-9_\-\[\]="' ]+$/.test(sel)) sel = '.item';
  var prop = (val('property') || 'flex-basis:33%').trim();
  if (prop.indexOf(':') < 0) prop = prop + ': block';
  var pi = prop.indexOf(':');
  var pName = prop.slice(0, pi).trim() || 'display';
  var pVal = prop.slice(pi + 1).trim() || 'block';
  var wrap = '.ht-list';
  var decl = '  ' + pName + ': ' + pVal + ';';
  var blocks = [];
  blocks.push('/* 至少 ' + min + ' 个子项时生效 */\n' +
    wrap + ':has(> ' + sel + ':nth-last-child(n+' + min + ')) > ' + sel + ' {\n' + decl + '\n}');
  if (max > 0) {
    blocks.push('/* 至多 ' + max + ' 个子项时生效 */\n' +
      wrap + ':has(> ' + sel + ':nth-last-child(-n+' + max + '):first-child) > ' + sel + ' {\n' + decl + '\n}');
    if (max >= min) {
      blocks.push('/* 子项数量在 ' + min + ' ~ ' + max + ' 之间时生效 */\n' +
        wrap + ':has(> ' + sel + ':nth-last-child(n+' + min + '):nth-last-child(-n+' + max + '):first-child) > ' + sel + ' {\n' + decl + '\n}');
    }
  }
  blocks.push('/* 兼容无 :has() 的浏览器：用 :only-child / :nth-child 粗略匹配 */\n' +
    wrap + ' > ' + sel + ':nth-child(n+' + min + ') ~ ' + sel + ' {\n' + decl + '\n}');
  var css = blocks.join('\n\n');
  var n = Math.max(min, Math.min(max || min + 1, 6));
  var items = '';
  for (var i = 0; i < n; i++) items += '<div class="ht-qq-item">' + (i + 1) + '</div>';
  var html = '<div style="padding:14px;background:#fff;border-radius:10px">' +
    '<div class="ht-list" style="display:flex;flex-wrap:wrap;gap:8px">' + items + '</div>' +
    '<p style="margin:10px 0 0;' + d11Font(12) + ';color:#7c8779">共 ' + n + ' 个子项（≥ ' + min + (max > 0 ? '，≤ ' + max : '') + '）→ ' + pName + ': ' + pVal + '</p></div>';
  return { code: css, outs: { preview: d11Prev('.ht-list{display:flex;flex-wrap:wrap;gap:8px}\n.ht-qq-item{width:52px;height:40px;border-radius:8px;background:#e7efe1;color:#2f5a35;display:flex;align-items:center;justify-content:center;font:600 13px system-ui}', html), range: min + ' ~ ' + (max || '∞') } };
}

/* ---------- 52 wait-animate ---------- */
/* 字段：name(spin) dur(1) timing(linear) iteration(infinite) direction(normal) */
function d11WaitAnimate() {
  var name = d11Pos('name', 'spin', ['spin', 'pulse', 'bounce', 'shake', 'fade', 'wave']);
  var dur = clamp(d11Num('dur', 1), 0.05, 60);
  var timing = d11Pos('timing', 'linear', ['linear', 'ease', 'ease-in', 'ease-out', 'ease-in-out']);
  var iter = (val('iteration') || 'infinite').trim();
  if (!/^(infinite|[0-9]+(\.[0-9]+)?)$/.test(iter)) iter = 'infinite';
  var dir = d11Pos('direction', 'normal', ['normal', 'reverse', 'alternate', 'alternate-reverse']);
  var frames = {
    spin: 'from { transform: rotate(0deg); }\n  to { transform: rotate(360deg); }',
    pulse: '0%, 100% { transform: scale(1); opacity: 1; }\n  50% { transform: scale(1.12); opacity: .55; }',
    bounce: '0%, 100% { transform: translateY(0); }\n  50% { transform: translateY(-18px); }',
    shake: '0%, 100% { transform: translateX(0); }\n  20% { transform: translateX(-8px); }\n  40% { transform: translateX(8px); }\n  60% { transform: translateX(-4px); }\n  80% { transform: translateX(4px); }',
    fade: '0%, 100% { opacity: 1; }\n  50% { opacity: .25; }',
    wave: '0%, 100% { transform: rotate(-14deg); }\n  50% { transform: rotate(14deg); }'
  };
  var body = frames[name] || frames.spin;
  var css = '@keyframes ht-' + name + ' {\n  ' + body + '\n}\n\n' +
    '.ht-wait {\n' +
    '  width: 48px;\n' +
    '  height: 48px;\n' +
    '  border-radius: ' + (name === 'spin' ? '50%' : '10px') + ';\n' +
    '  background: ' + (name === 'spin' ? 'conic-gradient(#3e7c46, #7ab8a0, #3e7c46)' : '#3e7c46') + ';\n' +
    '  animation: ht-' + name + ' ' + dur + 's ' + timing + ' ' + iter + ' ' + dir + ';\n' +
    '}';
  var html = '<div style="padding:34px 20px;background:#f6f9f4;border-radius:10px;display:flex;justify-content:center">' +
    '<div class="ht-wait"></div></div>';
  return { code: css, outs: { preview: d11Prev(css, html), anim: name + ' ' + dur + 's ' + timing } };
}

/* ---------- 53 code-to-image-converter ---------- */
/* 字段：code(示例源码) theme(dark|light|forest|sunset) fontSize(14) padding(24) bg(空=跟随主题) */
function d11CodeToImage() {
  var src = val('code');
  if (!src) src = 'function greet(name) {\n  return "Hello, " + name + "!";\n}\n\nconsole.log(greet("HeronTool"));';
  var theme = d11Pos('theme', 'dark', ['dark', 'light', 'forest', 'sunset']);
  var fs = clamp(d11Int('fontSize', 14), 8, 72), pad = clamp(d11Int('padding', 24), 0, 200);
  var themes = {
    dark: { bg: '#1e2227', fg: '#e6e6e6', bar: '#2b3036' },
    light: { bg: '#ffffff', fg: '#24292e', bar: '#f1f3f5' },
    forest: { bg: '#12261a', fg: '#d9f0e0', bar: '#1b3826' },
    sunset: { bg: '#2b1b2e', fg: '#f7e6ef', bar: '#3d2742' }
  };
  var t = themes[theme] || themes.dark;
  var bgIn = (val('bg') || '').trim();
  var bg = bgIn ? d11Color('bg', t.bg) : t.bg;
  var lines = String(src).split(/\r\n|\r|\n/);
  var lh = Math.round(fs * 1.55);
  var maxLen = 1, i;
  for (i = 0; i < lines.length; i++) if (lines[i].length > maxLen) maxLen = lines[i].length;
  var charW = fs * 0.6;
  var W = Math.ceil(Math.max(240, maxLen * charW + pad * 2));
  var H = Math.ceil(lines.length * lh + pad * 2);
  var url = '';
  var canDraw = (typeof document !== 'undefined' && document && typeof document.createElement === 'function');
  if (canDraw) {
    try {
      var cv = document.createElement('canvas');
      if (cv && cv.getContext) {
        var ctx = cv.getContext('2d');
        if (ctx) {
          cv.width = W; cv.height = H;
          ctx.fillStyle = bg;
          ctx.fillRect(0, 0, W, H);
          ctx.fillStyle = t.bar;
          ctx.fillRect(0, 0, W, Math.max(14, Math.round(pad * 0.9)));
          ctx.font = fs + 'px "Consolas", "SFMono-Regular", Menlo, monospace';
          ctx.textBaseline = 'top';
          ctx.fillStyle = t.fg;
          for (i = 0; i < lines.length; i++) ctx.fillText(lines[i], pad, pad + i * lh);
          url = cv.toDataURL('image/png');
        }
      }
    } catch (e) { url = ''; }
  }
  if (!url) {
    /* 无 canvas（或渲染失败）时退化为 SVG data-uri，纯字符串生成，结果同样可直接下载 */
    function xm(s) {
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    var tspans = '';
    for (i = 0; i < lines.length; i++) {
      tspans += '<text x="' + pad + '" y="' + (pad + i * lh + fs) + '">' + xm(lines[i]) + '</text>';
    }
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '">' +
      '<rect width="' + W + '" height="' + H + '" fill="' + bg + '"/>' +
      '<rect width="' + W + '" height="' + Math.max(14, Math.round(pad * 0.9)) + '" fill="' + t.bar + '"/>' +
      '<g font-family="Consolas, SFMono-Regular, Menlo, monospace" font-size="' + fs + '" fill="' + t.fg + '">' + tspans + '</g>' +
      '</svg>';
    url = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  }
  var code = '<!-- 代码图片（data URI，可直接用于 <img src> 或下载） -->\n' + url;
  var html = '<div style="padding:14px;background:#f6f9f4;border-radius:10px">' +
    '<img src="' + url + '" alt="code" style="max-width:100%;height:auto;display:block;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.14)">' +
    '<p style="margin:10px 0 0;' + d11Font(12) + ';color:#5b6a58">' + W + '×' + H + 'px · ' + lines.length + ' 行 · ' + theme + ' 主题</p></div>';
  return { code: code, outs: { preview: html, size: W + '×' + H, lines: String(lines.length) } };
}

/* ---------- 54 react-native-shadow-generator ---------- */
/* 字段：x(0) y(4) blur(8) color(#000000) elevation(4) */
function d11RnShadow() {
  var x = clamp(d11Num('x', 0), -100, 100), y = clamp(d11Num('y', 4), -100, 100),
    blur = clamp(d11Num('blur', 8), 0, 200), elevation = clamp(d11Num('elevation', 4), 0, 24);
  var hex = (val('color') || '').trim() || '#000000';
  if (hex.charAt(0) !== '#') hex = '#' + hex;
  var alpha = 0.3, baseHex = hex;
  if (hex.length === 9) {
    var rgb2 = hexToRgb(hex.slice(0, 7)) || [0, 0, 0];
    alpha = parseInt(hex.slice(7, 9), 16) / 255;
    baseHex = rgbToHex(rgb2[0], rgb2[1], rgb2[2]);
  }
  alpha = Math.round(alpha * 100) / 100;
  var spread = 0;
  var cssBlur = blur;
  var css = '/* iOS（RN shadow* 属性，映射为 CSS 时的等价写法） */\n' +
    '.ht-rn-card {\n' +
    '  box-shadow: ' + x + 'px ' + y + 'px ' + cssBlur + 'px ' + spread + 'px ' + d11Rgba(baseHex, alpha) + ';\n' +
    '}\n\n' +
    '/* Android 等价（只支持 elevation，方向/模糊由系统计算） */\n' +
    '.ht-rn-card-android {\n' +
    '  elevation: ' + elevation + ';   /* RN: elevation: ' + elevation + ' */\n' +
    '}';
  var rn = '// React Native StyleSheet\n' +
    'const styles = StyleSheet.create({\n' +
    '  card: {\n' +
    '    backgroundColor: "#ffffff",\n' +
    '    borderRadius: 12,\n' +
    '    // iOS\n' +
    '    shadowColor: "' + baseHex + '",\n' +
    '    shadowOffset: { width: ' + x + ', height: ' + y + ' },\n' +
    '    shadowOpacity: ' + alpha + ',\n' +
    '    shadowRadius: ' + blur + ',\n' +
    '    // Android\n' +
    '    elevation: ' + elevation + '\n' +
    '  }\n' +
    '});';
  var html = '<div style="padding:34px;background:#f6f9f4;border-radius:10px;display:flex;justify-content:center">' +
    '<div style="width:130px;height:80px;border-radius:12px;background:#fff;box-shadow:' + x + 'px ' + y + 'px ' + cssBlur + 'px ' + spread + 'px ' + d11Rgba(baseHex, alpha) + '"></div></div>';
  return { code: rn + '\n\n' + css, outs: { preview: d11Prev(css, html), ios: x + ',' + y + ' r' + blur, android: 'elevation ' + elevation } };
}

/* ---------- 55 image-triangulator ---------- */
/* 字段：img(图片 URL，留空用内置渐变) detail(6) —— 网格密度 1~24 */
function d11Triangulator() {
  var img = (val('img') || '').trim();
  var detail = clamp(d11Int('detail', 6), 1, 24);
  var W = 360, H = 240;
  var cols = detail;
  var rows = Math.max(1, Math.round(detail * H / W));
  function h(i, s) { var x = Math.sin(i * (s || 127.1) + 1.3) * 43758.5453; return x - Math.floor(x); }
  var safeImg = img.replace(/["'()\\\s]/g, '');
  var bgImage = safeImg ? 'url("' + safeImg + '")' : 'linear-gradient(120deg, #2f5a35, #7ab8a0 45%, #e7efe1)';
  var bgSize = safeImg ? W + 'px ' + H + 'px' : '100% 100%';
  var facets = '', rules = '', idx = 0, r, c;
  for (r = 0; r < rows; r++) {
    for (c = 0; c < cols; c++) {
      var x0 = c * W / cols, x1 = (c + 1) * W / cols;
      var y0 = r * H / rows, y1 = (r + 1) * H / rows;
      var mxs = W / cols * 0.2, mys = H / rows * 0.2;
      var mx = x0 + W / cols / 2 + (h(idx, 91.7) - 0.5) * mxs * 2;
      var my = y0 + H / rows / 2 + (h(idx, 213.3) - 0.5) * mys * 2;
      idx++;
      var tri = [
        [[x0, y0], [x1, y0], [mx, my]],
        [[x1, y0], [x1, y1], [mx, my]],
        [[x1, y1], [x0, y1], [mx, my]],
        [[x0, y1], [x0, y0], [mx, my]]
      ];
      for (var k = 0; k < tri.length; k++) {
        var pts = [];
        for (var p = 0; p < 3; p++) {
          pts.push((tri[k][p][0] / W * 100).toFixed(2) + '% ' + (tri[k][p][1] / H * 100).toFixed(2) + '%');
        }
        var clip = 'polygon(' + pts.join(', ') + ')';
        var bright = (0.88 + h(idx * 7 + k, 317.1) * 0.28).toFixed(2);
        facets += '<i style="clip-path:' + clip + ';-webkit-clip-path:' + clip + ';filter:brightness(' + bright + ')"></i>';
        rules += '.ht-tri i:nth-child(' + (idx * 4 + k - 3) + ') {\n  clip-path: ' + clip + ';\n  filter: brightness(' + bright + ');\n}\n';
      }
    }
  }
  var total = rows * cols * 4;
  var css = '.ht-tri {\n' +
    '  position: relative;\n' +
    '  width: ' + W + 'px;\n' +
    '  height: ' + H + 'px;\n' +
    '  max-width: 100%;\n' +
    '  overflow: hidden;\n' +
    '  border-radius: 10px;\n' +
    '  background: #e7efe1;\n' +
    '}\n' +
    '.ht-tri i {\n' +
    '  position: absolute;\n' +
    '  inset: 0;\n' +
    '  background-image: ' + bgImage + ';\n' +
    '  background-size: ' + bgSize + ';\n' +
    '  background-position: 0 0;\n' +
    '  background-repeat: no-repeat;\n' +
    '  display: block;\n' +
    '}\n' +
    '/* 共 ' + total + ' 个三角面（' + cols + ' 列 × ' + rows + ' 行 × 4） */\n' +
    rules;
  var note = safeImg ? '' : '<p style="margin:10px 0 0;' + d11Font(12) + ';color:#7c8779">未填写图片地址，当前使用内置渐变演示。填入图片 URL 后将对图片本身做三角化。</p>';
  var html = '<div style="padding:14px;background:#f6f9f4;border-radius:10px">' +
    '<div class="ht-tri">' + facets + '</div>' + note + '</div>';
  return { code: css, outs: { preview: d11Prev(css, html), facets: String(total) } };
}

/* ====== 第十三批新增：数据互转 / 文本特效 / 随机数据 / 生成器 / 色彩（2026-09-06 开源项目对照） ====== */
/* ====== 第十三批：数据互转 / 文本特效 / 随机数据 / 校验 / 生成器（开源项目对照 2026-09-06） ======
   纯前端实现，不引入第三方大库。所有函数以 b13 开头避免与既有引擎命名冲突。 */

/* ---------- 通用：CSV / 表格 ---------- */
function b13Delim(name) {
  if (name === 'tab' || name === '\\t') return '\t';
  if (name === 'semi') return ';';
  if (name === 'pipe') return '|';
  return ',';
}
function b13CsvParse(t, d) {
  var rows = [], row = [], cur = '', q = false, s = String(t == null ? '' : t).replace(/\r\n/g, '\n').replace(/\r/g, '\n');
  for (var i = 0; i < s.length; i++) {
    var c = s[i];
    if (q) {
      if (c === '"') { if (s[i + 1] === '"') { cur += '"'; i++; } else q = false; }
      else cur += c;
    } else {
      if (c === '"') q = true;
      else if (c === d) { row.push(cur); cur = ''; }
      else if (c === '\n') { row.push(cur); rows.push(row); row = []; cur = ''; }
      else cur += c;
    }
  }
  if (cur !== '' || row.length) { row.push(cur); rows.push(row); }
  return rows.filter(function (r) { return r.length && !(r.length === 1 && r[0].trim() === ''); });
}
function b13CsvCell(v, d) {
  var s = String(v == null ? '' : v);
  if (s.indexOf(d) >= 0 || s.indexOf('"') >= 0 || s.indexOf('\n') >= 0 || s.indexOf('\t') >= 0) return '"' + s.replace(/"/g, '""') + '"';
  return s;
}
function b13Cols(rows) { var n = 0; rows.forEach(function (r) { if (r.length > n) n = r.length; }); return n; }
function b13Norm(rows) {
  var n = b13Cols(rows);
  return rows.map(function (r) { var o = r.slice(); while (o.length < n) o.push(''); return o; });
}
function b13Widths(rows, head) {
  var n = b13Cols(rows), w = [];
  for (var c = 0; c < n; c++) { w[c] = String(head && head[c] != null ? head[c] : ''); }
  rows.forEach(function (r) { for (var c = 0; c < n; c++) { var v = String(r[c] == null ? '' : r[c]); if (v.length > w[c].length) w[c] = v; } });
  return w.map(function (x) { return x.length; });
}
function b13Pad(v, n, al) {
  var s = String(v == null ? '' : v), sp = n - s.length; if (sp < 0) sp = 0;
  if (al === 'right') return new Array(sp + 1).join(' ') + s;
  if (al === 'center') { var l = Math.floor(sp / 2), r = sp - l; return new Array(l + 1).join(' ') + s + new Array(r + 1).join(' '); }
  return s + new Array(sp + 1).join(' ');
}
function b13Esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function b13YamlScalar(v) {
  var s = String(v == null ? '' : v);
  if (s === '') return '""';
  if (/^[\d.]+$/.test(s) && !/^0\d/.test(s)) return s;
  if (/^(true|false|null|yes|no|on|off|~)$/i.test(s)) return '"' + s + '"';
  if (/^[\s"'\[\]{}#&*!|>%@`]/.test(s) || s.indexOf(': ') >= 0 || s.indexOf(' #') >= 0 || s.indexOf('\n') >= 0) return '"' + s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n') + '"';
  return s;
}
function b13JsonKey(k) { var s = String(k == null ? '' : k).replace(/[^\w一-龥]/g, '_'); if (/^\d/.test(s)) s = '_' + s; return s || 'col'; }
function b13SqlIdent(s) { var v = String(s == null ? '' : s).trim().replace(/[^\w]/g, '_'); return v || 't'; }
function b13SqlLit(v) {
  if (v === '' || v == null) return 'NULL';
  if (/^-?\d+(\.\d+)?$/.test(String(v).trim())) return String(v).trim();
  return "'" + String(v).replace(/'/g, "''") + "'";
}

/* ---------- 1. TSV ↔ CSV ---------- */
function b13TsvCsv(t, C) {
  var to = C.act ? C.act() : '';
  var lines = String(t).replace(/\r\n/g, '\n').split('\n');
  if (to === 'csv2tsv') {
    return b13CsvParse(t, ',').map(function (r) { return r.map(function (c) { return c.replace(/[\t\n]/g, ' '); }).join('\t'); }).join('\n');
  }
  return lines.map(function (l) { return l.split('\t').map(function (c) { return b13CsvCell(c, ','); }).join(','); }).join('\n');
}

/* ---------- 2. CSV → Markdown 表 ---------- */
function b13CsvToMd(t, C) {
  var rows = b13Norm(b13CsvParse(t, ','));
  if (!rows.length) return '';
  var al = (C.fv('align') || 'left');
  var bold = C.opt ? C.opt('bold') : false;
  var head = rows[0].map(function (h) { return bold ? '**' + h + '**' : h; });
  var w = b13Widths(rows.slice(1), head);
  var mk = w.map(function (n) {
    if (al === 'center') return ':' + new Array(Math.max(n - 1, 1) + 1).join('-') + ':';
    if (al === 'right') return new Array(Math.max(n - 1, 1) + 1).join('-') + ':';
    return new Array(Math.max(n, 3) + 1).join('-');
  });
  var out = ['| ' + head.map(function (h, i) { return b13Pad(h, w[i], 'left'); }).join(' | ') + ' |',
    '| ' + mk.map(function (m, i) { return b13Pad(m, w[i], al === 'right' ? 'right' : (al === 'center' ? 'center' : 'left')); }).join(' | ') + ' |'];
  rows.slice(1).forEach(function (r) { out.push('| ' + r.map(function (c, i) { return b13Pad(c, w[i], 'left'); }).join(' | ') + ' |'); });
  return out.join('\n');
}

/* ---------- 3. Markdown 表 → CSV ---------- */
function b13MdToCsv(t) {
  var lines = String(t).split('\n').filter(function (l) { return /^\s*\|/.test(l); });
  var rows = [];
  lines.forEach(function (l) {
    if (/^\s*\|[\s:|-]+\|\s*$/.test(l)) return;
    var s = l.trim().replace(/^\|/, '').replace(/\|\s*$/, '');
    var cells = s.split('|').map(function (c) { return c.trim().replace(/^\*\*|\*\*$/g, ''); });
    rows.push(cells);
  });
  return rows.map(function (r) { return r.map(function (c) { return b13CsvCell(c, ','); }).join(','); }).join('\n');
}

/* ---------- 4. CSV → XML ---------- */
function b13CsvToXml(t, C) {
  var root = b13SqlIdent(C.fv('root') || 'root'), row = b13SqlIdent(C.fv('row') || 'row');
  var rows = b13Norm(b13CsvParse(t, ','));
  if (!rows.length) return '<' + root + '/>';
  var head = rows[0].map(b13JsonKey), seen = {}, keys = head.map(function (k) { if (seen[k]) { seen[k]++; return k + seen[k]; } seen[k] = 1; return k; });
  var out = ['<' + root + '>'];
  rows.slice(1).forEach(function (r) {
    out.push('  <' + row + '>');
    keys.forEach(function (k, i) { out.push('    <' + k + '>' + b13Esc(r[i]) + '</' + k + '>'); });
    out.push('  </' + row + '>');
  });
  out.push('</' + root + '>');
  return out.join('\n');
}

/* ---------- 5. CSV → YAML ---------- */
function b13CsvToYaml(t) {
  var rows = b13Norm(b13CsvParse(t, ','));
  if (!rows.length) return '[]';
  var head = rows[0].map(b13JsonKey), seen = {}, keys = head.map(function (k) { if (seen[k]) { seen[k]++; return k + seen[k]; } seen[k] = 1; return k; });
  var out = [];
  rows.slice(1).forEach(function (r) { out.push('- ' + keys.map(function (k, i) { return k + ': ' + b13YamlScalar(r[i]); }).join('\n  ')); });
  return out.join('\n');
}

/* ---------- 6. YAML → CSV（支持顶层映射 / - 列表 / 列表项映射） ---------- */
function b13YamlToCsv(t) {
  var lines = String(t).replace(/\r\n/g, '\n').split('\n').filter(function (l) { return l.trim() !== '' && l.trim().charAt(0) !== '#'; });
  var rows = [], keys = [], cur = null;
  var strip = function (s) { return s.replace(/^["']|["']$/g, '').replace(/\\n/g, '\n'); };
  lines.forEach(function (raw) {
    var ind = raw.length - raw.replace(/^\s+/, '').length, l = raw.trim();
    var m = l.match(/^-\s*(.*)$/);
    var isItem = !!m;
    var body = isItem ? m[1] : l;
    var kv = body.match(/^([^:]+):\s*(.*)$/);
    if (isItem) {
      if (kv) { cur = {}; rows.push(cur); if (keys.indexOf(kv[1].trim()) < 0) keys.push(kv[1].trim()); cur[kv[1].trim()] = strip(kv[2].trim()); }
      else { cur = { value: strip(body) }; rows.push(cur); if (keys.indexOf('value') < 0) keys.push('value'); }
      return;
    }
    if (kv && ind > 0 && cur) {
      var k = kv[1].trim(); if (keys.indexOf(k) < 0) keys.push(k);
      var v = kv[2].trim();
      if (v === '' ) { cur[k] = {}; return; }
      cur[k] = strip(v);
      return;
    }
    if (kv) {
      if (!cur) { cur = {}; rows.push(cur); }
      var k2 = kv[1].trim(); if (keys.indexOf(k2) < 0) keys.push(k2);
      cur[k2] = strip(kv[2].trim());
    }
  });
  if (!rows.length) return '';
  var out = [keys.map(function (k) { return b13CsvCell(k, ','); }).join(',')];
  rows.forEach(function (r) { out.push(keys.map(function (k) { return b13CsvCell(r[k] == null ? '' : r[k], ','); }).join(',')); });
  return out.join('\n');
}

/* ---------- 7. CSV → LaTeX ---------- */
function b13CsvToLatex(t, C) {
  var rows = b13Norm(b13CsvParse(t, ','));
  if (!rows.length) return '';
  var al = C.fv('align') || 'l';
  var n = b13Cols(rows), colspec = new Array(n + 1).join(al.charAt(0));
  var out = ['\\begin{tabular}{' + colspec + '}', '\\hline'];
  rows.forEach(function (r, i) {
    out.push(r.map(function (c) { return String(c).replace(/([&%$#_{}])/g, '\\$1'); }).join(' & ') + ' \\\\');
    if (i === 0 || i === rows.length - 1) out.push('\\hline');
  });
  out.push('\\end{tabular}');
  return out.join('\n');
}

/* ---------- 8. CSV → 对齐纯文本表 ---------- */
function b13CsvToText(t) {
  var rows = b13Norm(b13CsvParse(t, ','));
  if (!rows.length) return '';
  var w = b13Widths(rows.slice(1), rows[0]);
  var sep = '+' + w.map(function (n) { return new Array(n + 3).join('-'); }).join('+') + '+';
  var out = [sep];
  function line(r) { return '| ' + r.map(function (c, i) { return b13Pad(c, w[i], 'left'); }).join(' | ') + ' |'; }
  rows.forEach(function (r, i) { out.push(line(r)); if (i === 0) out.push(sep); });
  out.push(sep);
  return out.join('\n');
}

/* ---------- 9. JSON → Markdown 表 ---------- */
function b13JsonToMd(t) {
  var o; try { o = JSON.parse(t); } catch (e) { return 'Invalid JSON'; }
  if (!Array.isArray(o)) o = [o];
  if (!o.length) return '';
  var keys = [];
  o.forEach(function (it) { if (it && typeof it === 'object') Object.keys(it).forEach(function (k) { if (keys.indexOf(k) < 0) keys.push(k); }); });
  if (!keys.length) keys = ['value'];
  var body = o.map(function (it) { return keys.map(function (k) { var v = (it && typeof it === 'object') ? it[k] : it; return typeof v === 'object' ? JSON.stringify(v) : String(v == null ? '' : v); }); });
  var w = b13Widths(body, keys);
  var out = ['| ' + keys.map(function (k, i) { return b13Pad(k, w[i], 'left'); }).join(' | ') + ' |',
    '| ' + w.map(function (n) { return new Array(Math.max(n, 3) + 1).join('-'); }).join(' | ') + ' |'];
  body.forEach(function (r) { out.push('| ' + r.map(function (c, i) { return b13Pad(c, w[i], 'left'); }).join(' | ') + ' |'); });
  return out.join('\n');
}

/* ---------- 10. JSON → SQL ---------- */
function b13JsonToSql(t, C) {
  var o; try { o = JSON.parse(t); } catch (e) { return 'Invalid JSON'; }
  if (!Array.isArray(o)) o = [o];
  if (!o.length) return '';
  var table = b13SqlIdent(C.fv('table') || 'my_table');
  var keys = [];
  o.forEach(function (it) { if (it && typeof it === 'object') Object.keys(it).forEach(function (k) { if (keys.indexOf(k) < 0) keys.push(k); }); });
  if (!keys.length) return '';
  var cols = keys.map(function (k) { return '  ' + b13SqlIdent(k) + ' TEXT'; }).join(',\n');
  var out = ['CREATE TABLE ' + table + ' (', cols, ');', ''];
  o.forEach(function (it) {
    var vals = keys.map(function (k) { var v = (it && typeof it === 'object') ? it[k] : undefined; return typeof v === 'object' ? b13SqlLit(JSON.stringify(v)) : b13SqlLit(v == null ? '' : v); });
    out.push('INSERT INTO ' + table + ' (' + keys.map(b13SqlIdent).join(', ') + ') VALUES (' + vals.join(', ') + ');');
  });
  return out.join('\n');
}

/* ---------- 11. CSV SQL 查询 ---------- */
function b13CsvSql(t, C) {
  var sql = String(C.fv('sql') || '').trim();
  if (!sql) sql = 'SELECT *';
  var rows = b13Norm(b13CsvParse(t, ','));
  if (!rows.length) return '';
  var head = rows[0].map(b13JsonKey), seen = {}, cols = head.map(function (k) { if (seen[k]) { seen[k]++; return k + seen[k]; } seen[k] = 1; return k; });
  var data = rows.slice(1).map(function (r) { var o = {}; cols.forEach(function (c, i) { o[c] = r[i]; }); return o; });
  /* 拆分 SELECT 列表 / WHERE / ORDER BY / LIMIT（不依赖 FROM 子句） */
  var rest = sql.replace(/^select\s+/i, '');
  var lm = rest.match(/\s+limit\s+(\d+)\s*$/i), lim = 0;
  if (lm) { lim = parseInt(lm[1], 10); rest = rest.slice(0, lm.index); }
  var om = rest.match(/\s+order\s+by\s+([\w]+)(?:\s+(asc|desc))?\s*$/i), oc = '', desc = false;
  if (om) { oc = om[1]; desc = (om[2] || '').toLowerCase() === 'desc'; rest = rest.slice(0, om.index); }
  var wm = rest.match(/\s+where\s+([\s\S]+)$/i), cond = '';
  if (wm) { cond = wm[1]; rest = rest.slice(0, wm.index); }
  var selPart = rest.trim().replace(/\s+from\s+\w+\s*$/i, '');
  if (!selPart) selPart = '*';
  if (cond) {
    var parts = cond.split(/\s+and\s+/i);
    data = data.filter(function (row) {
      return parts.every(function (p) {
        var mm = p.trim().match(/^([\w]+)\s*(>=|<=|!=|<>|=|>|<|like|contains)\s*(.+)$/i);
        if (!mm) return true;
        var lv = row[mm[1]], op = mm[2].toLowerCase(), rv = mm[3].replace(/^['"]|['"]$/g, '');
        if (op === 'like' || op === 'contains') return String(lv).toLowerCase().indexOf(rv.toLowerCase()) >= 0;
        var ln = parseFloat(lv), rn = parseFloat(rv);
        var numeric = !isNaN(ln) && !isNaN(rn);
        if (op === '=') return numeric ? ln === rn : String(lv) === rv;
        if (op === '!=' || op === '<>') return numeric ? ln !== rn : String(lv) !== rv;
        if (op === '>') return numeric ? ln > rn : String(lv) > rv;
        if (op === '<') return numeric ? ln < rn : String(lv) < rv;
        if (op === '>=') return numeric ? ln >= rn : String(lv) >= rv;
        if (op === '<=') return numeric ? ln <= rn : String(lv) <= rv;
        return true;
      });
    });
  }
  if (oc) {
    data.sort(function (a, b) {
      var x = a[oc], y = b[oc], nx = parseFloat(x), ny = parseFloat(y);
      var r = (!isNaN(nx) && !isNaN(ny)) ? nx - ny : String(x).localeCompare(String(y));
      return desc ? -r : r;
    });
  }
  if (lim) data = data.slice(0, lim);
  var pick = selPart.trim();
  var outCols, outRows;
  if (pick === '*') {
    outCols = cols; outRows = data.map(function (o) { return cols.map(function (c) { return o[c]; }); });
  } else {
    outCols = pick.split(',').map(function (x) { return x.trim(); });
    outRows = data.map(function (o) { return outCols.map(function (c) { return o[c]; }); });
  }
  outRows.unshift(outCols);
  return outRows.map(function (r) { return r.map(function (c) { return b13CsvCell(c == null ? '' : c, ','); }).join(','); }).join('\n');
}

/* ---------- 12/13. 上下颠倒 / 镜像文字 ---------- */
function b13FlipMap() {
  var a = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!?.,\'"()[]{}<>;:_&-+=*/\\|~^`';
  var b = 'ɐqɔpǝɟƃɥıɾʞlɯuodbɹsʇnʌʍxʎz∀qƆpƎℲפHIſʞ˥WNOԀQɹS┴∩ΛMX⅄Z0123456789¡¿˙\'‾)(}{][><؛:‾-∩+*/\\|~‾,';
  var m = {};
  for (var i = 0; i < a.length; i++) m[a[i]] = b[i] || a[i];
  return m;
}
function b13UpsideDown(t) {
  var m = b13FlipMap();
  return String(t).split('').reverse().map(function (c) { return m[c] || c; }).join('');
}
function b13MirrorText(t) {
  var M = { '(': ')', ')': '(', '[': ']', ']': '[', '{': '}', '}': '{', '<': '>', '>': '<', '\\': '/', '/': '\\' };
  var rev = { b: 'd', d: 'b', p: 'q', q: 'p', s: 'z', z: 's', n: 'n', m: 'w', w: 'm' };
  return String(t).split('\n').map(function (line) {
    return line.split('').reverse().map(function (c) {
      if (M[c]) return M[c];
      if (rev[c]) return rev[c];
      return c;
    }).join('');
  }).join('\n');
}

/* ---------- 14. 文本 ↔ HEX ---------- */
function b13TextToHex(t, C) {
  var act = C.act ? C.act() : '';
  if (act === 'dec') {
    var s = String(t).replace(/[^0-9a-fA-F\s]/g, '').trim();
    if (!s) return '';
    var bytes = s.split(/\s+/).map(function (h) { return String.fromCharCode(parseInt(h, 16)); });
    return bytes.join('');
  }
  var out = [];
  for (var i = 0; i < String(t).length; i++) out.push(('0' + String(t).charCodeAt(i).toString(16)).slice(-2));
  return out.join(' ').toUpperCase();
}

/* ---------- 15. Base64 → HEX ---------- */
function b13B64ToHex(t, C) {
  var act = C.act ? C.act() : '';
  if (act === 'dec') {
    var hex = String(t).replace(/[^0-9a-fA-F]/g, '');
    if (hex.length % 2) hex = '0' + hex;
    var bytes = [];
    for (var i = 0; i < hex.length; i += 2) bytes.push(parseInt(hex.substr(i, 2), 16));
    var bin = '';
    bytes.forEach(function (b) { bin += String.fromCharCode(b); });
    try { return btoa(bin); } catch (e) { return 'Convert error'; }
  }
  var clean = String(t).trim().replace(/\s+/g, '');
  var bin2;
  try { bin2 = atob(clean); } catch (e) { return 'Invalid Base64'; }
  var out = [];
  for (var j = 0; j < bin2.length; j++) out.push(('0' + bin2.charCodeAt(j).toString(16)).slice(-2));
  return out.join(' ').toUpperCase();
}

/* ---------- 16. 密钥泄露检测 ---------- */
function b13SecretScan(t) {
  var RULES = [
    ['AWS Access Key ID', /AKIA[0-9A-Z]{16}/g],
    ['AWS Secret Access Key', /aws(.{0,20})?['"][0-9a-zA-Z\/+]{40}['"]/gi],
    ['Google API Key', /AIza[0-9A-Za-z\-_]{35}/g],
    ['Google OAuth', /ya29\.[0-9A-Za-z\-_]+/g],
    ['GitHub Token', /gh[pousr]_[0-9A-Za-z]{36,255}/g],
    ['Slack Token', /xox[baprs]-[0-9A-Za-z-]{10,72}/g],
    ['Slack Webhook', /https:\/\/hooks\.slack\.com\/services\/T[A-Za-z0-9_]+\/B[A-Za-z0-9_]+\/[A-Za-z0-9_]+/g],
    ['Stripe Key', /[sr]k_(live|test)_[0-9a-zA-Z]{24,}/g],
    ['SendGrid Key', /SG\.[0-9A-Za-z\-_]{22}\.[0-9A-Za-z\-_]{43}/g],
    ['Twilio SID', /AC[0-9a-fA-F]{32}/g],
    ['Mailgun Key', /key-[0-9a-zA-Z]{32}/g],
    ['OpenAI Key', /sk-[0-9A-Za-z]{32,}/g],
    ['Private Key Block', /-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----/g],
    ['JWT', /eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/g],
    ['Basic Auth in URL', /https?:\/\/[^\s\/:@]+:[^\s\/@]+@/g],
    ['Password Assignment', /(password|passwd|pwd|secret|token|api[_-]?key)\s*[:=]\s*['"][^'"]{6,}['"]/gi],
    ['DB Connection String', /(mysql|postgres|mongodb(\+srv)?|redis):\/\/[^\s"']+/gi],
    ['IPv4 with Port', /\b(?:\d{1,3}\.){3}\d{1,3}:\d{2,5}\b/g]
  ];
  var lines = String(t).split('\n'), found = [], counts = {};
  lines.forEach(function (line, li) {
    RULES.forEach(function (r) {
      var m, re = new RegExp(r[1].source, r[1].flags);
      while ((m = re.exec(line)) !== null) {
        var val = m[0];
        var masked = val.length > 12 ? val.slice(0, 6) + '…' + val.slice(-4) : val.slice(0, 4) + '…';
        counts[r[0]] = (counts[r[0]] || 0) + 1;
        found.push('L' + (li + 1) + '  [' + r[0] + ']  ' + masked);
        if (m.index === re.lastIndex) re.lastIndex++;
      }
    });
  });
  if (!found.length) return (LANG === 'zh' ? '✓ 未发现明显的密钥 / 凭证泄露' : '✓ No obvious secrets detected');
  var head = (LANG === 'zh' ? '⚠ 发现 ' : '⚠ Found ') + found.length + (LANG === 'zh' ? ' 处可疑内容：' : ' suspicious item(s):');
  var summary = Object.keys(counts).map(function (k) { return '  ' + k + ' × ' + counts[k]; });
  return [head].concat(summary, [''], found).join('\n');
}

/* ---------- 17. 国际电话号码校验 ---------- */
function b13PhoneCheck(t) {
  var CC = [
    ['CN', 'China', '中国', '86', [11], /^1[3-9]\d{9}$/],
    ['US', 'USA', '美国', '1', [10], /^[2-9]\d{9}$/],
    ['HK', 'Hong Kong, China', '中国香港', '852', [8], /^[2-9]\d{7}$/],
    ['MO', 'Macao, China', '中国澳门', '853', [8], /^[26]\d{7}$/],
    ['TW', 'Taiwan, China', '中国台湾', '886', [9], /^9\d{8}$/],
    ['JP', 'Japan', '日本', '81', [10, 11], /^[789]0\d{8,9}$/],
    ['KR', 'Korea', '韩国', '82', [9, 10], /^10\d{7,8}$/],
    ['GB', 'United Kingdom', '英国', '44', [10], /^[1-9]\d{9}$/],
    ['DE', 'Germany', '德国', '49', [10, 11], /^[1-9]\d{9,10}$/],
    ['FR', 'France', '法国', '33', [9], /^[1-9]\d{8}$/],
    ['IN', 'India', '印度', '91', [10], /^[6-9]\d{9}$/],
    ['SG', 'Singapore', '新加坡', '65', [8], /^[3689]\d{7}$/],
    ['MY', 'Malaysia', '马来西亚', '60', [9, 10], /^[1-9]\d{7,9}$/],
    ['TH', 'Thailand', '泰国', '66', [9], /^[689]\d{8}$/],
    ['VN', 'Vietnam', '越南', '84', [9, 10], /^[3-9]\d{8,9}$/],
    ['ID', 'Indonesia', '印尼', '62', [10, 12], /^[1-9]\d{9,11}$/],
    ['AU', 'Australia', '澳大利亚', '61', [9], /^[2-478]\d{8}$/],
    ['CA', 'Canada', '加拿大', '1', [10], /^[2-9]\d{9}$/],
    ['RU', 'Russia', '俄罗斯', '7', [10], /^[3489]\d{9}$/],
    ['BR', 'Brazil', '巴西', '55', [11], /^[1-9]\d{10}$/]
  ];
  var raw = String(t).trim();
  if (!raw) return LANG === 'zh' ? '请输入电话号码' : 'Enter a phone number';
  var num = raw.replace(/[^\d+]/g, '');
  var plus = num.charAt(0) === '+';
  var digits = num.replace(/\+/g, '');
  var cc = '', nat = '', info = null;
  if (plus) {
    for (var i = 0; i < CC.length; i++) {
      var code = CC[i][3];
      if (digits.indexOf(code) === 0) { cc = code; nat = digits.slice(code.length); info = CC[i]; break; }
    }
    if (!info) return (LANG === 'zh' ? '✗ 未知国家代码：+' : '✗ Unknown country code +') + digits.slice(0, 4);
  } else {
    info = CC[0]; cc = info[3]; nat = digits;
  }
  var okLen = info[4].indexOf(nat.length) >= 0;
  var okPat = info[5].test(nat);
  var e164 = '+' + cc + nat;
  var national = '0' + nat;
  var intl = '+' + cc + ' ' + (nat.length > 6 ? nat.slice(0, nat.length - 4) + ' ' + nat.slice(-4) : nat);
  var out = [];
  var cname = LANG === 'zh' ? info[2] : info[1];
  out.push((LANG === 'zh' ? '国家/地区：' : 'Country: ') + cname + ' (+' + cc + ')');
  out.push((LANG === 'zh' ? '国内号码：' : 'National: ') + nat);
  out.push('E.164：' + e164);
  out.push((LANG === 'zh' ? '国际格式：' : 'International: ') + intl);
  out.push((LANG === 'zh' ? '本地拨号：' : 'Local dial: ') + national);
  out.push((LANG === 'zh' ? '长度校验：' : 'Length: ') + (okLen ? '✓ ' + nat.length : '✗ ' + nat.length + (LANG === 'zh' ? '（期望 ' : ' (expected ') + info[4].join('/') + '）'));
  out.push((LANG === 'zh' ? '号段校验：' : 'Pattern: ') + (okPat ? '✓ ' : '✗ ') + info[5]);
  out.push((LANG === 'zh' ? '结论：' : 'Result: ') + (okLen && okPat ? (LANG === 'zh' ? '✔ 号码合法' : '✔ Valid') : (LANG === 'zh' ? '✘ 号码疑似不合法' : '✘ Likely invalid')));
  return out.join('\n');
}

/* ---------- 18. SWIFT/BIC 校验 ---------- */
function b13SwiftCheck(t) {
  var s = String(t).trim().toUpperCase().replace(/\s/g, '');
  if (!s) return LANG === 'zh' ? '请输入 SWIFT/BIC 代码' : 'Enter a SWIFT/BIC code';
  var ok8 = /^[A-Z]{6}[A-Z0-9]{2}$/.test(s), ok11 = /^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/.test(s);
  if (!ok11) {
    return (LANG === 'zh' ? '✘ 格式不正确\nSWIFT/BIC 应为 8 或 11 位：4 位银行代码 + 2 位国家代码 + 2 位地区代码（+ 3 位分行代码）'
      : '✘ Invalid format\nSWIFT/BIC must be 8 or 11 chars: 4 bank + 2 country + 2 location (+ 3 branch)');
  }
  var bank = s.slice(0, 4), country = s.slice(4, 6), loc = s.slice(6, 8), branch = s.slice(8);
  var test = s.slice(4, 6);
  var isTest = test === 'ZZ' || loc.charAt(1) === '0' || loc.charAt(1) === '1' || loc.charAt(1) === '2';
  var passive = loc.charAt(1) === '1';
  var reverse = loc.charAt(1) === '2';
  var out = [];
  out.push((LANG === 'zh' ? '代码：' : 'BIC: ') + s + (LANG === 'zh' ? '（' : ' (') + s.length + (LANG === 'zh' ? ' 位）' : ' chars)'));
  out.push((LANG === 'zh' ? '银行代码：' : 'Bank code: ') + bank);
  out.push((LANG === 'zh' ? '国家代码：' : 'Country: ') + country + (country === 'CN' ? (LANG === 'zh' ? '（中国）' : ' (China)') : ''));
  out.push((LANG === 'zh' ? '地区代码：' : 'Location: ') + loc + (passive ? (LANG === 'zh' ? '（被动参与者）' : ' (passive)') : (reverse ? (LANG === 'zh' ? '（反向计费）' : ' (reverse billing)') : '')));
  out.push((LANG === 'zh' ? '分行代码：' : 'Branch: ') + (branch || (LANG === 'zh' ? '（无，总行 XXX）' : '(head office XXX)')));
  out.push((LANG === 'zh' ? '测试代码：' : 'Test BIC: ') + (isTest ? (LANG === 'zh' ? '是（非生产环境）' : 'Yes (non-production)') : (LANG === 'zh' ? '否' : 'No')));
  out.push((LANG === 'zh' ? '结论：✔ 格式合法' : 'Result: ✔ Format valid'));
  return out.join('\n');
}

/* ---------- 19. JSON-LD 检查 ---------- */
function b13JsonLd(t) {
  var txt = String(t).trim();
  var blocks = [];
  if (txt.indexOf('<script') >= 0) {
    var re = /<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi, m;
    while ((m = re.exec(txt)) !== null) blocks.push(m[1].trim());
  } else blocks.push(txt);
  if (!blocks.length) return LANG === 'zh' ? '未找到 JSON-LD（粘贴页面 HTML 或 <script type="application/ld+json"> 内容）' : 'No JSON-LD found';
  var out = [];
  var TYPES = {
    Article: '文章 · Google 富媒体：文章卡片', NewsArticle: '新闻文章', BlogPosting: '博客文章',
    Product: '产品 · 价格/库存富媒体', Recipe: '食谱 · 评分/烹饪时长富媒体', Event: '活动 · 日期/地点富媒体',
    Organization: '机构 · 知识面板', LocalBusiness: '本地商家 · 地图卡片', Person: '人物 · 知识面板',
    BreadcrumbList: '面包屑 · 搜索结果显示路径', FAQPage: 'FAQ · 常见问题折叠', HowTo: '步骤指南富媒体',
    VideoObject: '视频 · 缩略图富媒体', JobPosting: '招聘 · 职位卡片', Course: '课程富媒体',
    WebSite: '站点 · 站内搜索框', WebPage: '网页', Review: '评价 · 星级富媒体', SoftwareApplication: '应用 · 评分富媒体'
  };
  blocks.forEach(function (b, i) {
    var o;
    try { o = JSON.parse(b); } catch (e) {
      out.push('#' + (i + 1) + ' ✘ ' + (LANG === 'zh' ? 'JSON 语法错误：' : 'JSON syntax error: ') + e.message);
      return;
    }
    var list = Array.isArray(o) ? o : [o];
    if (o && o['@graph']) list = o['@graph'];
    out.push('#' + (i + 1) + ' ✔ ' + (LANG === 'zh' ? '合法 JSON-LD，含 ' : 'Valid JSON-LD, ') + list.length + (LANG === 'zh' ? ' 个节点' : ' node(s)'));
    list.forEach(function (n) {
      var type = n && n['@type'] ? (Array.isArray(n['@type']) ? n['@type'].join('/') : n['@type']) : '(none)';
      var rich = TYPES[String(type).split('/')[0]] || '';
      out.push('   • @type: ' + type + (rich ? '  → ' + rich : ''));
      var warn = [];
      if (!n['@context']) warn.push('@context');
      if (/^(Product|Recipe|Event|JobPosting|Course)$/.test(String(type)) && !n.name) warn.push('name');
      if (type === 'Product' && !n.offers) warn.push('offers');
      if (type === 'Recipe' && !n.recipeIngredient) warn.push('recipeIngredient');
      if (type === 'Event' && !n.startDate) warn.push('startDate');
      if (type === 'FAQPage' && !n.mainEntity) warn.push('mainEntity');
      if (warn.length) out.push('     ⚠ ' + (LANG === 'zh' ? '建议补充字段：' : 'Recommended fields missing: ') + warn.join(', '));
    });
  });
  return out.join('\n');
}

/* ---------- 20. Unicode 码点查询 ---------- */
function b13UnicodeLookup(t) {
  var s = String(t).trim();
  if (!s) return LANG === 'zh' ? '请输入字符或码点（如 A 或 U+00E9）' : 'Enter characters or a codepoint (e.g. U+00E9)';
  var m = s.match(/^(?:U\+|0x)?([0-9a-fA-F]{2,6})$/);
  if (m) { s = String.fromCodePoint(parseInt(m[1], 16)); }
  var out = [];
  var BLOCKS = [[0, 0x7F, 'Basic Latin 基本拉丁'], [0x80, 0xFF, 'Latin-1 Supplement'], [0x100, 0x17F, 'Latin Extended-A'],
    [0x370, 0x3FF, 'Greek and Coptic 希腊文'], [0x400, 0x4FF, 'Cyrillic 西里尔文'], [0x590, 0x5FF, 'Hebrew 希伯来文'],
    [0x600, 0x6FF, 'Arabic 阿拉伯文'], [0x900, 0x97F, 'Devanagari 天城文'], [0xE00, 0xE7F, 'Thai 泰文'],
    [0x1100, 0x11FF, 'Hangul Jamo 韩文'], [0x1E00, 0x1EFF, 'Latin Extended Additional'], [0x2000, 0x206F, 'General Punctuation 通用标点'],
    [0x2070, 0x209F, 'Super/Subscripts'], [0x20A0, 0x20BF, 'Currency Symbols 货币符号'], [0x2190, 0x21FF, 'Arrows 箭头'],
    [0x2200, 0x22FF, 'Mathematical Operators 数学符号'], [0x2500, 0x257F, 'Box Drawing 制表符'], [0x25A0, 0x25FF, 'Geometric Shapes 几何图形'],
    [0x2600, 0x26FF, 'Miscellaneous Symbols'], [0x2700, 0x27BF, 'Dingbats'], [0x3000, 0x303F, 'CJK Symbols and Punctuation 中文标点'],
    [0x3040, 0x309F, 'Hiragana 平假名'], [0x30A0, 0x30FF, 'Katakana 片假名'], [0x4E00, 0x9FFF, 'CJK Unified Ideographs 汉字'],
    [0xAC00, 0xD7AF, 'Hangul Syllables 韩文音节'], [0xF900, 0xFAFF, 'CJK Compatibility Ideographs'], [0xFF00, 0xFFEF, 'Halfwidth/Fullwidth Forms 全半角'],
    [0x1F300, 0x1F5FF, 'Misc Symbols and Pictographs'], [0x1F600, 0x1F64F, 'Emoticons 表情'], [0x1F900, 0x1F9FF, 'Supplemental Symbols']];
  var chars = Array.from(s).slice(0, 60);
  chars.forEach(function (c, i) {
    var cp = c.codePointAt(0);
    var hex = 'U+' + cp.toString(16).toUpperCase().padStart(4, '0');
    var utf8 = unescape(encodeURIComponent(c)).split('').map(function (x) { return ('0' + x.charCodeAt(0).toString(16)).slice(-2).toUpperCase(); }).join(' ');
    var utf16 = c.length > 1 ? ('0x' + c.charCodeAt(0).toString(16).toUpperCase() + ' 0x' + c.charCodeAt(1).toString(16).toUpperCase()) : '0x' + cp.toString(16).toUpperCase();
    var block = 'Unknown';
    for (var b = 0; b < BLOCKS.length; b++) { if (cp >= BLOCKS[b][0] && cp <= BLOCKS[b][1]) { block = BLOCKS[b][2]; break; } }
    var name = /^[A-Za-z]$/.test(c) ? 'LATIN ' + (/[A-Z]/.test(c) ? 'CAPITAL' : 'SMALL') + ' LETTER ' + c.toUpperCase() : (/[\u4e00-\u9fa5]/.test(c) ? 'CJK UNIFIED IDEOGRAPH' : '');
    out.push((i ? '\n' : '') + c + '   ' + hex + '   十进制 ' + cp);
    out.push('   UTF-8:  ' + utf8 + '   (' + utf8.split(' ').length + (LANG === 'zh' ? ' 字节' : ' bytes') + ')');
    out.push('   UTF-16: ' + utf16);
    out.push('   HTML:   &#' + cp + ';    JS: \\u{' + cp.toString(16) + '}');
    out.push('   ' + (LANG === 'zh' ? '区块：' : 'Block: ') + block + (name ? '   ' + name : ''));
  });
  out.push('\n' + (LANG === 'zh' ? '共 ' : 'Total ') + Array.from(s).length + (LANG === 'zh' ? ' 个字符' : ' char(s)'));
  return out.join('\n');
}

/* ---------- 21. 日志分析 ---------- */
function b13LogAnalyze(t, C) {
  var level = C.fv('level') || 'all';
  var lines = String(t).split('\n');
  var LV = ['TRACE', 'DEBUG', 'INFO', 'NOTICE', 'WARN', 'WARNING', 'ERROR', 'ERR', 'FATAL', 'CRIT', 'CRITICAL', 'SEVERE'];
  var counts = {}, total = 0, kept = [], byHour = {}, samples = {};
  var top = [];
  lines.forEach(function (raw) {
    var l = raw.trim();
    if (!l) return;
    var m = l.match(/\b(TRACE|DEBUG|INFO|NOTICE|WARN|WARNING|ERROR|ERR|FATAL|CRIT|CRITICAL|SEVERE)\b/i);
    var lv = m ? m[1].toUpperCase() : 'OTHER';
    if (lv === 'WARNING') lv = 'WARN'; if (lv === 'ERR') lv = 'ERROR'; if (lv === 'CRIT' || lv === 'CRITICAL') lv = 'FATAL';
    counts[lv] = (counts[lv] || 0) + 1;
    total++;
    if (level !== 'all' && lv !== level.toUpperCase()) return;
    kept.push(l);
    var hm = l.match(/(\d{4}-\d{2}-\d{2})[T ](\d{2})/);
    if (hm) byHour[hm[1] + ' ' + hm[2] + ':00'] = (byHour[hm[1] + ' ' + hm[2] + ':00'] || 0) + 1;
    if (lv === 'ERROR' || lv === 'FATAL') {
      var key = l.replace(/\d+/g, '#').slice(0, 110);
      samples[key] = (samples[key] || 0) + 1;
    }
  });
  var order = ['FATAL', 'ERROR', 'WARN', 'INFO', 'DEBUG', 'TRACE', 'OTHER'];
  var out = [];
  out.push((LANG === 'zh' ? '总行数：' : 'Total lines: ') + total);
  out.push((LANG === 'zh' ? '级别分布：' : 'By level:'));
  order.forEach(function (k) { if (counts[k]) out.push('   ' + k + '  ' + counts[k] + '  (' + (counts[k] / total * 100).toFixed(1) + '%)'); });
  var hours = Object.keys(byHour).sort();
  if (hours.length) {
    out.push('');
    out.push(LANG === 'zh' ? '按小时分布：' : 'By hour:');
    hours.slice(0, 24).forEach(function (h) { out.push('   ' + h + '  ' + new Array(Math.min(byHour[h], 60) + 1).join('█') + ' ' + byHour[h]); });
  }
  var errs = Object.keys(samples).sort(function (a, b) { return samples[b] - samples[a]; }).slice(0, 10);
  if (errs.length) {
    out.push('');
    out.push(LANG === 'zh' ? '高频错误 TOP 10（数字已归一）：' : 'Top errors (digits masked):');
    errs.forEach(function (k) { out.push('   ' + samples[k] + '×  ' + k); });
  }
  if (level !== 'all') {
    out.push('');
    out.push((LANG === 'zh' ? '筛选结果（' + level + '）：' : 'Filtered (' + level + '): ') + kept.length + (LANG === 'zh' ? ' 行' : ' lines'));
    out.push(kept.slice(0, 200).join('\n'));
  }
  return out.join('\n');
}

/* ---------- 22. SQL DDL → ER 图 ---------- */
function b13DdlDiagram(t) {
  var sql = String(t).replace(/--[^\n]*/g, '').replace(/\/\*[\s\S]*?\*\//g, '');
  var tables = [];
  var re = /create\s+table\s+(?:if\s+not\s+exists\s+)?[`"\[]?([\w]+)[`"\]]?\s*\(([\s\S]*?)\)\s*;/gi;
  var m;
  while ((m = re.exec(sql)) !== null) {
    var name = m[1], body = m[2];
    var cols = [], fks = [];
    body.split(/,(?![^()]*\))/).forEach(function (part) {
      var p = part.trim();
      if (!p) return;
      if (/^(primary|foreign|unique|key|constraint|index|check)\s/i.test(p)) {
        var fkm = p.match(/foreign\s+key\s*\(\s*[`"\[]?([\w]+)[`"\]]?\s*\)\s*references\s+[`"\[]?([\w]+)[`"\]]?\s*\(\s*[`"\[]?([\w]+)/i);
        if (fkm) fks.push({ col: fkm[1], ref: fkm[2], refcol: fkm[3] });
        return;
      }
      var cm = p.match(/^[`"\[]?([\w]+)[`"\]]?\s+([A-Za-z]+(?:\s*\([^)]*\))?)/);
      if (cm) {
        var flags = [];
        if (/primary\s+key/i.test(p)) flags.push('PK');
        if (/\bnot\s+null\b/i.test(p)) flags.push('NN');
        if (/\bunique\b/i.test(p)) flags.push('UQ');
        if (/auto_increment|autoincrement/i.test(p)) flags.push('AI');
        cols.push({ name: cm[1], type: cm[2].toUpperCase(), flags: flags });
      }
    });
    tables.push({ name: name, cols: cols, fks: fks });
  }
  if (!tables.length) return LANG === 'zh' ? '未解析到 CREATE TABLE 语句' : 'No CREATE TABLE found';
  var out = [];
  out.push('%% Mermaid erDiagram');
  tables.forEach(function (tb) {
    out.push('  ' + tb.name + ' {');
    tb.cols.slice(0, 24).forEach(function (c) { out.push('    ' + c.type.replace(/[()]/g, '_') + ' ' + c.name + (c.flags.length ? ' ' + c.flags.join(' ') : '')); });
    out.push('  }');
  });
  tables.forEach(function (tb) {
    tb.fks.forEach(function (f) { out.push('  ' + tb.name + ' }o--|| ' + f.ref + ' : "' + f.col + '→' + f.refcol + '"'); });
  });
  out.push('');
  out.push('/* ' + (LANG === 'zh' ? '文本 ER 图' : 'Text ER diagram') + ' */');
  var w = 0;
  function colText(c) { return c.name + ' : ' + c.type + (c.flags.length ? ' [' + c.flags.join(',') + ']' : ''); }
  tables.forEach(function (tb) { tb.cols.forEach(function (c) { var n = colText(c).length; if (n > w) w = n; }); if (tb.name.length > w) w = tb.name.length; });
  w = Math.max(w, 8);
  var bar = '+' + new Array(w + 3).join('-') + '+';
  tables.forEach(function (tb) {
    out.push(bar);
    out.push('| ' + b13Pad(tb.name, w, 'center') + ' |');
    out.push(bar);
    tb.cols.forEach(function (c) { out.push('| ' + b13Pad(colText(c), w, 'left') + ' |'); });
    out.push(bar);
  });
  if (tables.some(function (t2) { return t2.fks.length; })) {
    out.push('');
    out.push(LANG === 'zh' ? '关系：' : 'Relations:');
    tables.forEach(function (tb) { tb.fks.forEach(function (f) { out.push('  ' + tb.name + '.' + f.col + '  →  ' + f.ref + '.' + f.refcol); }); });
  }
  return out.join('\n');
}

/* ---------- 23/24. JS / PHP 美化 ---------- */
function b13BeautifyJs(t) {
  var src = String(t).replace(/\r\n/g, '\n');
  var out = '', i = 0, ind = 0, n = src.length;
  var inStr = 0, inLine = false, inBlock = false, inTpl = false;
  var push = function (s) { out += s; };
  var nl = function () { out += '\n'; for (var k = 0; k < ind; k++) out += '  '; };
  while (i < n) {
    var c = src[i], nx = src[i + 1];
    if (inLine) { push(c); if (c === '\n') inLine = false; i++; continue; }
    if (inBlock) { push(c); if (c === '*' && nx === '/') { push('/'); i += 2; inBlock = false; continue; } i++; continue; }
    if (inStr) { push(c); if (c === '\\') { push(nx || ''); i += 2; continue; } if ((c === '"' || c === "'") && inStr === c) inStr = 0; i++; continue; }
    if (inTpl) { push(c); if (c === '`') inTpl = false; i++; continue; }
    if (c === '/' && nx === '/') { inLine = true; push(c); i++; continue; }
    if (c === '/' && nx === '*') { inBlock = true; push('/*'); i += 2; continue; }
    if (c === '"' || c === "'") { inStr = c; push(c); i++; continue; }
    if (c === '`') { inTpl = true; push(c); i++; continue; }
    if (c === '{' || c === '[') { push(c); ind++; nl(); i++; continue; }
    if (c === '}' || c === ']') { ind = Math.max(ind - 1, 0); nl(); push(c); i++; continue; }
    if (c === '(') { push(c); i++; continue; }
    if (c === ')') { push(c); i++; continue; }
    if (c === ';') { push(c); i++; var jj = i; while (jj < n && /[ \t\n]/.test(src[jj])) jj++; if (src[jj] === '}' || src[jj] === ']') { i = jj; continue; } nl(); continue; }
    if (c === ',') { push(c); nl(); i++; continue; }
    if (c === '\n' || c === '\t' || c === ' ') { if (out && out.charAt(out.length - 1) !== ' ' && out.charAt(out.length - 1) !== '\n') push(' '); i++; continue; }
    push(c); i++;
  }
  return out.split('\n').map(function (l) { return l.replace(/\s+$/, ''); }).filter(function (l, idx, arr) { return !(l === '' && arr[idx + 1] === ''); }).join('\n');
}
function b13BeautifyPhp(t) {
  var src = String(t).replace(/\r\n/g, '\n');
  var out = '', i = 0, ind = 0, n = src.length, inPhp = false;
  var nl = function () { out += '\n'; for (var k = 0; k < ind; k++) out += '    '; };
  var trimEnd = function () { out = out.replace(/[ \t]+$/, ''); };
  while (i < n) {
    var c = src[i];
    if (!inPhp) {
      if (src.substr(i, 5) === '<?php') { inPhp = true; out += '<?php'; i += 5; nl(); continue; }
      out += c; i++; continue;
    }
    if (src.substr(i, 2) === '?>') { inPhp = false; trimEnd(); out += '\n?>'; i += 2; continue; }
    if (src.substr(i, 2) === '//') { var e = src.indexOf('\n', i); if (e < 0) e = n; out += src.slice(i, e); i = e; continue; }
    if (src.substr(i, 2) === '/*' || c === '#') {
      var e2 = (c === '#') ? src.indexOf('\n', i) : src.indexOf('*/', i);
      if (e2 < 0) e2 = n; else if (c !== '#') e2 += 2;
      out += src.slice(i, e2); i = e2; continue;
    }
    if (c === '"' || c === "'") {
      var q = c, j = i + 1, seg = q;
      while (j < n) { if (src[j] === '\\') { seg += src[j] + (src[j + 1] || ''); j += 2; continue; } if (src[j] === q) { seg += q; j++; break; } seg += src[j]; j++; }
      out += seg; i = j; continue;
    }
    if (c === '{') { trimEnd(); out += ' {'; ind++; nl(); i++; continue; }
    if (c === '}') { ind = Math.max(ind - 1, 0); trimEnd(); nl(); out += '}'; if (src[i + 1] === '\n') i++; i++; nl(); continue; }
    if (c === ';') { trimEnd(); out += ';'; i++; if (src[i] === '\n') i++; nl(); continue; }
    if (c === '\n' || c === '\t') { i++; continue; }
    if (c === ' ') { if (out && !/[ \n]$/.test(out)) out += ' '; i++; continue; }
    out += c; i++;
  }
  return out.split('\n').map(function (l) { return l.replace(/\s+$/, ''); }).filter(function (l, idx, a) { return !(l === '' && a[idx + 1] === ''); }).join('\n');
}

/* ---------- 25. CSS → LESS ---------- */
function b13CssToLess(t) {
  var css = String(t).replace(/\/\*[\s\S]*?\*\//g, '');
  var RE = /([^{}]+)\{([^{}]*)\}/g, m, blocks = [];
  while ((m = RE.exec(css)) !== null) {
    var sel = m[1].trim().replace(/\s+/g, ' ');
    var body = m[2].split(';').map(function (x) { return x.trim(); }).filter(Boolean);
    if (sel.charAt(0) === '@' || !body.length) continue;
    blocks.push({ sel: sel, body: body });
  }
  if (!blocks.length) return css.trim();
  function indent(n) { return new Array(n + 1).join('  '); }
  /* 按首段选择器归并：同一选择器的自身声明与后代块合并成一个嵌套块 */
  function nest(list, prefix, depth) {
    var out = [], groups = {}, order = [];
    list.forEach(function (b) {
      var sel = b.sel;
      var rel = prefix ? sel.replace(new RegExp('^' + prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s+'), '') : sel;
      if (rel === sel && prefix) { out.push(indent(depth) + sel + ' {'); b.body.forEach(function (d) { out.push(indent(depth + 1) + d + ';'); }); out.push(indent(depth) + '}'); return; }
      var parts = rel.split(/\s+/);
      var head = parts[0];
      var g = groups[head] || (groups[head] = { body: [], kids: [] });
      if (order.indexOf(head) < 0) order.push(head);
      if (parts.length === 1) b.body.forEach(function (d) { g.body.push(d); });
      else g.kids.push({ sel: parts.slice(1).join(' '), body: b.body });
    });
    order.forEach(function (h) {
      var g = groups[h];
      out.push(indent(depth) + h + ' {');
      g.body.forEach(function (d) { out.push(indent(depth + 1) + d + ';'); });
      if (g.kids.length) { out.push(''); nest(g.kids, h, depth + 1).forEach(function (l) { out.push(l); }); }
      out.push(indent(depth) + '}');
    });
    return out;
  }
  return nest(blocks, '', 0).join('\n').replace(/\n{3,}/g, '\n\n');
}

/* ---------- 26. ASCII 边框 ---------- */
function b13AsciiBox(t, C) {
  var style = C.fv('style') || 'single';
  var SETS = {
    single: ['┌', '┐', '└', '┘', '─', '│'],
    double: ['╔', '╗', '╚', '╝', '═', '║'],
    round: ['╭', '╮', '╰', '╯', '─', '│'],
    bold: ['┏', '┓', '┗', '┛', '━', '┃'],
    star: ['*', '*', '*', '*', '*', '*'],
    hash: ['#', '#', '#', '#', '#', '#'],
    ascii: ['+', '+', '+', '+', '-', '|']
  };
  var S = SETS[style] || SETS.single;
  var lines = String(t).replace(/\r\n/g, '\n').split('\n');
  var w = 0;
  lines.forEach(function (l) { if (l.length > w) w = l.length; });
  var bar = new Array(w + 3).join(S[4]);
  var out = [S[0] + bar + S[1]];
  lines.forEach(function (l) { out.push(S[5] + ' ' + b13Pad(l, w, 'left') + ' ' + S[5]); });
  out.push(S[2] + bar + S[3]);
  return out.join('\n');
}

/* ---------- 27. ASCII 目录树 ---------- */
function b13AsciiTree(t) {
  var lines = String(t).replace(/\r\n/g, '\n').split('\n').filter(function (l) { return l.trim() !== ''; });
  if (!lines.length) return '';
  var isIndent = lines.some(function (l) { return /^[\s│├└─|-]*\S/.test(l) && /^\s/.test(l); });
  var pathMode = lines.every(function (l) { return l.indexOf('/') >= 0 || l.indexOf('\\') >= 0; });
  var rows = [];
  if (pathMode) {
    var root = {};
    lines.forEach(function (p) {
      var parts = p.trim().replace(/\\/g, '/').split('/').filter(Boolean);
      var cur = root;
      parts.forEach(function (seg) { cur[seg] = cur[seg] || {}; cur = cur[seg]; });
    });
    var out = [];
    (function walk(node, prefix) {
      var keys = Object.keys(node);
      keys.forEach(function (k, i) {
        var last = i === keys.length - 1;
        out.push(prefix + (last ? '└── ' : '├── ') + k);
        walk(node[k], prefix + (last ? '    ' : '│   '));
      });
    })(root, '');
    return out.join('\n');
  }
  if (isIndent) {
    var stack = [];
    lines.forEach(function (raw) {
      var m = raw.match(/^([\s]*)(.*)$/);
      var indStr = m[1].replace(/\t/g, '    ');
      var depth = Math.floor(indStr.length / 2);
      var name = m[2].replace(/^[│├└─┬\s|-]+/, '').trim();
      if (!name) return;
      stack[depth] = name;
      var prefix = '';
      for (var d = 0; d < depth; d++) prefix += (stack[d + 1] === undefined ? '    ' : '│   ');
      rows.push(prefix + '├── ' + name);
    });
    rows = rows.map(function (r, i) {
      var nextDepth = -1;
      for (var j = i + 1; j < rows.length; j++) { var dd = Math.floor((rows[j].match(/^([\s│├└─|-]*)/)[1].length) / 4); nextDepth = dd; break; }
      return r;
    });
    return rows.join('\n');
  }
  return lines.map(function (l, i) { return (i === lines.length - 1 ? '└── ' : '├── ') + l.trim(); }).join('\n');
}

/* ---------- 28. URI 模板展开（RFC 6570 子集） ---------- */
function b13UriTemplate(t, C) {
  var tpl = String(t).trim();
  if (!tpl) return '';
  var varsRaw = String(C.fv('vars') || '');
  var V = {};
  varsRaw.split(/[,\n]/).forEach(function (pair) {
    var i = pair.indexOf('=');
    if (i < 0) return;
    V[pair.slice(0, i).trim()] = pair.slice(i + 1).trim();
  });
  function enc(s, allow) { return encodeURIComponent(String(s)).replace(/[!'()*]/g, function (c) { return '%' + c.charCodeAt(0).toString(16).toUpperCase(); }); }
  function expand(expr) {
    var op = expr.charAt(0);
    var ops = '+#./;?&';
    var body = (ops.indexOf(op) >= 0) ? expr.slice(1) : expr;
    if (ops.indexOf(op) < 0) op = '';
    var names = body.split(',').map(function (x) { return x.trim(); });
    var defined = names.filter(function (n) { return V[n] !== undefined && V[n] !== ''; });
    if (!defined.length) return '';
    function val(n) { var v = V[n]; return Array.isArray(v) ? v : String(v); }
    if (op === '') return defined.map(function (n) { return enc(val(n)); }).join(',');
    if (op === '+') return defined.map(function (n) { return encodeURI(String(val(n))); }).join(',');
    if (op === '#') return '#' + defined.map(function (n) { return encodeURI(String(val(n))); }).join(',');
    if (op === '.') return '.' + defined.map(function (n) { return enc(val(n)); }).join('.');
    if (op === '/') return '/' + defined.map(function (n) { return enc(val(n)); }).join('/');
    if (op === ';') return ';' + defined.map(function (n) { var v = enc(val(n)); return v === '' ? n : n + '=' + v; }).join(';');
    if (op === '?') return '?' + defined.map(function (n) { return n + '=' + enc(val(n)); }).join('&');
    if (op === '&') return '&' + defined.map(function (n) { return n + '=' + enc(val(n)); }).join('&');
    return '';
  }
  return tpl.replace(/\{([^{}]+)\}/g, function (m, inner) { return expand(inner); });
}

/* ====== 第十三批（续）：生成器 GEN / 计算器 CALC ====== */
function b13Ri(a, b) { return a + Math.floor(Math.random() * (b - a + 1)); }
function b13Pick(a) { return a[Math.floor(Math.random() * a.length)]; }

/* ---------- GEN：随机字母 ---------- */
function b13RandLetter() {
  var n = Math.min(Math.max(parseInt(val('n'), 10) || 10, 1), 2000);
  var mode = val('case') || 'mixed';
  var U = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', L = 'abcdefghijklmnopqrstuvwxyz';
  var pool = mode === 'upper' ? U : (mode === 'lower' ? L : U + L);
  var group = parseInt(val('group'), 10) || 0;
  var sep = val('sep') || '';
  var out = [], i;
  if (group > 0) {
    for (i = 0; i < n; i++) {
      var chunk = [];
      for (var k = 0; k < group; k++) chunk.push(pool.charAt(Math.floor(Math.random() * pool.length)));
      out.push(chunk.join(''));
    }
    return { out: out.join(sep || ' ') };
  }
  for (i = 0; i < n; i++) out.push(pool.charAt(Math.floor(Math.random() * pool.length)));
  return { out: out.join(sep) };
}

/* ---------- GEN：随机日期 ---------- */
function b13RandDate() {
  var n = Math.min(Math.max(parseInt(val('n'), 10) || 10, 1), 1000);
  var from = val('from') || '2000-01-01', to = val('to') || '2025-12-31';
  var t1 = Date.parse(from + 'T00:00:00'), t2 = Date.parse(to + 'T23:59:59');
  if (isNaN(t1) || isNaN(t2)) { t1 = Date.parse('2000-01-01T00:00:00'); t2 = Date.parse('2025-12-31T23:59:59'); }
  if (t2 < t1) { var x = t1; t1 = t2; t2 = x; }
  var withTime = val('time') === '1' || val('time') === 'true';
  var sortOut = val('sort') === '1' || val('sort') === 'true';
  var arr = [];
  for (var i = 0; i < n; i++) {
    var d = new Date(t1 + Math.floor(Math.random() * (t2 - t1 + 1)));
    var p = function (v) { return (v < 10 ? '0' : '') + v; };
    arr.push(d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + (withTime ? ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()) : ''));
  }
  if (sortOut) arr.sort();
  return { out: arr.join('\n') };
}

/* ---------- GEN：随机十六进制 ---------- */
function b13RandHex() {
  var n = Math.min(Math.max(parseInt(val('n'), 10) || 5, 1), 1000);
  var len = Math.min(Math.max(parseInt(val('len'), 10) || 8, 1), 128);
  var up = val('case') !== 'lower';
  var pfx = val('prefix') || '';
  var sep = val('sep') || '';
  var H = '0123456789abcdef';
  var out = [], i;
  for (i = 0; i < n; i++) {
    var s = '';
    for (var k = 0; k < len; k++) s += H.charAt(Math.floor(Math.random() * 16));
    out.push(pfx + (up ? s.toUpperCase() : s));
  }
  return { out: out.join(sep || '\n') };
}

/* ---------- GEN：随机 JSON（按字段定义） ---------- */
function b13RandJson() {
  var n = Math.min(Math.max(parseInt(val('n'), 10) || 3, 1), 500);
  var defs = String(val('schema') || 'name:string, age:int, email:email, active:bool').split(/[\n,]/).map(function (x) { return x.trim(); }).filter(Boolean);
  var FIELDS = defs.map(function (d) {
    var p = d.split(/[:=]/);
    return { k: (p[0] || 'f').trim(), t: (p[1] || 'string').trim().toLowerCase() };
  });
  var FIRST = ['Alice', 'Bob', 'Carol', 'Dave', 'Eve', 'Frank', 'Grace', 'Henry', 'Ivy', 'Jack', 'Kate', 'Leo', 'Mia', 'Noah', 'Olivia', 'Peter'];
  var LAST = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Wilson', 'Anderson', 'Taylor', 'Thomas'];
  var CITY = ['Beijing', 'Shanghai', 'Tokyo', 'Seoul', 'London', 'Paris', 'Berlin', 'New York', 'Sydney', 'Singapore', 'Kunming', 'Mumbai'];
  var WORDS = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel', 'india', 'juliet', 'kilo', 'lima'];
  function gen(t) {
    var hex = function () { return '0123456789abcdef'.charAt(b13Ri(0, 15)); };
    switch (t) {
      case 'int': case 'integer': case 'number': case 'num': return b13Ri(1, 9999);
      case 'float': case 'double': case 'decimal': return Math.round(Math.random() * 10000) / 100;
      case 'bool': case 'boolean': return Math.random() < 0.5;
      case 'email': return (b13Pick(FIRST) + '.' + b13Pick(LAST)).toLowerCase() + b13Ri(1, 999) + '@' + b13Pick(['example.com', 'mail.com', 'test.org', 'demo.net']);
      case 'name': return b13Pick(FIRST) + ' ' + b13Pick(LAST);
      case 'firstname': case 'first': return b13Pick(FIRST);
      case 'lastname': case 'last': return b13Pick(LAST);
      case 'city': return b13Pick(CITY);
      case 'country': return b13Pick(['China', 'USA', 'Japan', 'Germany', 'France', 'India', 'Brazil', 'Australia']);
      case 'url': return 'https://' + b13Pick(['example.com', 'site.org', 'demo.io']) + '/' + b13Pick(WORDS);
      case 'uuid': case 'id': return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = b13Ri(0, 15); return (c === 'x' ? hex() : ((r & 0x3) | 0x8).toString(16)); });
      case 'date': var d = new Date(Date.now() - b13Ri(0, 1000) * 86400000); return d.toISOString().slice(0, 10);
      case 'datetime': case 'timestamp': return new Date(Date.now() - b13Ri(0, 100000) * 60000).toISOString();
      case 'phone': return '1' + b13Ri(200, 989) + b13Ri(1000000, 9999999);
      case 'word': return b13Pick(WORDS);
      case 'text': case 'sentence': return b13Pick(WORDS) + ' ' + b13Pick(WORDS) + ' ' + b13Pick(WORDS);
      case 'ip': return b13Ri(1, 223) + '.' + b13Ri(0, 255) + '.' + b13Ri(0, 255) + '.' + b13Ri(1, 254);
      case 'hex': var s = ''; for (var i = 0; i < 8; i++) s += hex(); return s;
      case 'null': return null;
      default: return b13Pick(WORDS) + '-' + b13Ri(10, 99);
    }
  }
  var arr = [];
  for (var i = 0; i < n; i++) {
    var o = {};
    FIELDS.forEach(function (f) { o[f.k] = gen(f.t); });
    arr.push(o);
  }
  return { out: JSON.stringify(n === 1 ? arr[0] : arr, null, 2) };
}

/* ---------- GEN：随机 CSV ---------- */
function b13RandCsv() {
  var rows = Math.min(Math.max(parseInt(val('rows'), 10) || 10, 1), 2000);
  var cols = Math.min(Math.max(parseInt(val('cols'), 10) || 4, 1), 30);
  var head = val('header') !== '0' && val('header') !== 'false';
  var kind = val('kind') || 'mixed';
  var out = [];
  if (head) {
    var h = [];
    for (var c = 0; c < cols; c++) h.push('col' + (c + 1));
    out.push(h.join(','));
  }
  function cell() {
    if (kind === 'int') return b13Ri(1, 9999);
    if (kind === 'float') return (Math.round(Math.random() * 10000) / 100).toFixed(2);
    if (kind === 'name') return b13Pick(['Alice', 'Bob', 'Carol', 'Dave', 'Eve', 'Frank', 'Grace', 'Henry']);
    if (kind === 'word') return b13Pick(['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel']);
    if (kind === 'bool') return b13Pick(['true', 'false', '0', '1']);
    if (kind === 'date') { var d = new Date(Date.now() - b13Ri(0, 900) * 86400000); return d.toISOString().slice(0, 10); }
    return b13Pick([b13Ri(1, 9999), b13Pick(['alpha', 'bravo', 'charlie', 'delta']), (Math.random() * 100).toFixed(2), b13Pick(['true', 'false'])]);
  }
  for (var r = 0; r < rows; r++) {
    var line = [];
    for (var k = 0; k < cols; k++) line.push(b13CsvCell(cell(), ','));
    out.push(line.join(','));
  }
  return { out: out.join('\n') };
}

/* ---------- GEN：随机 XML ---------- */
function b13RandXml() {
  var n = Math.min(Math.max(parseInt(val('n'), 10) || 5, 1), 500);
  var root = (val('root') || 'items').replace(/[^\w]/g, '');
  var item = (val('item') || 'item').replace(/[^\w]/g, '');
  var fields = String(val('fields') || 'id:int, name:string, price:float').split(/[\n,]/).map(function (x) { return x.trim(); }).filter(Boolean);
  var F = fields.map(function (d) { var p = d.split(/[:=]/); return { k: (p[0] || 'f').replace(/[^\w]/g, ''), t: (p[1] || 'string').trim().toLowerCase() }; });
  var out = ['<?xml version="1.0" encoding="UTF-8"?>', '<' + root + '>'];
  for (var i = 0; i < n; i++) {
    out.push('  <' + item + '>');
    F.forEach(function (f) {
      var v;
      if (f.t === 'int' || f.t === 'number') v = b13Ri(1, 9999);
      else if (f.t === 'float') v = (Math.random() * 1000).toFixed(2);
      else if (f.t === 'bool') v = b13Pick(['true', 'false']);
      else if (f.t === 'date') v = new Date(Date.now() - b13Ri(0, 900) * 86400000).toISOString().slice(0, 10);
      else v = b13Pick(['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot']);
      out.push('    <' + f.k + '>' + b13Esc(v) + '</' + f.k + '>');
    });
    out.push('  </' + item + '>');
  }
  out.push('</' + root + '>');
  return { out: out.join('\n') };
}

/* ---------- GEN：按正则生成随机串 ---------- */
function b13CharPool() { var a = []; for (var x = 32; x < 127; x++) a.push(String.fromCharCode(x)); return a; }
function b13GenFromRe(re) {
  var s = String(re), i = 0, guard = 0;
  function pick(a) { return a[Math.floor(Math.random() * a.length)]; }
  function parseAlt() {
    var opts = [parseSeq()];
    while (s[i] === '|') { i++; opts.push(parseSeq()); }
    return function () { return pick(opts)(); };
  }
  function parseSeq() {
    var items = [];
    while (i < s.length && s[i] !== '|' && s[i] !== ')') {
      if (++guard > 5000) break;
      items.push(parseItem());
    }
    return function () { var o = ''; for (var k = 0; k < items.length; k++) o += items[k](); return o; };
  }
  function rep(f, a, b) { return function () { var n = a + Math.floor(Math.random() * (b - a + 1)), o = ''; for (var k = 0; k < n; k++) o += f(); return o; }; }
  function parseItem() {
    var atom = parseAtom(), q = s[i];
    if (q === '*') { i++; return rep(atom, 0, 4); }
    if (q === '+') { i++; return rep(atom, 1, 4); }
    if (q === '?') { i++; return function () { return Math.random() < 0.5 ? atom() : ''; }; }
    if (q === '{') {
      var m = /^\{(\d+)(,(\d*))?\}/.exec(s.slice(i));
      if (m) { i += m[0].length; var a = parseInt(m[1], 10), b = m[2] ? (m[3] ? parseInt(m[3], 10) : a + 3) : a; if (b > 40) b = 40; return rep(atom, a, b); }
    }
    return atom;
  }
  function lit(c) { return function () { return c; }; }
  function parseAtom() {
    var c = s[i];
    if (c === '(') { i++; if (s.substr(i, 2) === '?:') i += 2; var f = parseAlt(); if (s[i] === ')') i++; return f; }
    if (c === '[') {
      var j = s.indexOf(']', i); if (j < 0) j = s.length;
      var body = s.slice(i + 1, j); i = j + 1;
      var neg = body.charAt(0) === '^'; if (neg) body = body.slice(1);
      var chars = [];
      for (var k = 0; k < body.length; k++) {
        if (body[k] === '\\') { var nx = body[k + 1]; if (nx === 'd') for (var d0 = 48; d0 < 58; d0++) chars.push(String.fromCharCode(d0)); else if (nx === 'w') { 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_'.split('').forEach(function (ch2) { chars.push(ch2); }); } else chars.push(nx); k++; continue; }
        if (body[k + 1] === '-' && body[k + 2] && body[k + 2] !== ']') { var a2 = body.charCodeAt(k), b2 = body.charCodeAt(k + 2); for (var x = a2; x <= b2; x++) chars.push(String.fromCharCode(x)); k += 2; continue; }
        chars.push(body[k]);
      }
      if (neg) { var set = {}; chars.forEach(function (ch3) { set[ch3] = 1; }); chars = b13CharPool().filter(function (ch4) { return !set[ch4]; }); }
      return function () { return pick(chars.length ? chars : ['x']); };
    }
    if (c === '\\') {
      var e = s[i + 1]; i += 2;
      if (e === 'd') return function () { return String(b13Ri(0, 9)); };
      if (e === 'D') return function () { return pick(b13CharPool().filter(function (ch5) { return !/[0-9]/.test(ch5); })); };
      if (e === 'w') return function () { return pick('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_'.split('')); };
      if (e === 'W') return function () { return pick('!@#$%^&*()-_=+'.split('')); };
      if (e === 's') return function () { return ' '; };
      if (e === 'S') return function () { return pick(b13CharPool().filter(function (ch6) { return !/\s/.test(ch6); })); };
      return lit(e);
    }
    if (c === '.') { i++; var pool = b13CharPool(); return function () { return pick(pool); }; }
    if (c === '^' || c === '$') { i++; return function () { return ''; }; }
    i++;
    return lit(c);
  }
  return parseAlt();
}
function b13RandFromRegex() {
  var re = String(val('regex') || '[A-Z]{2}\\d{6}').trim();
  var n = Math.min(Math.max(parseInt(val('n'), 10) || 10, 1), 500);
  var out = [], gen;
  try { gen = b13GenFromRe(re); } catch (e) { return { out: 'Regex error: ' + (e && e.message || e) }; }
  for (var i = 0; i < n; i++) out.push(gen());
  return { out: out.join('\n') };
}

/* ---------- GEN：tar 命令生成 ---------- */
function b13TarCmd() {
  var mode = val('mode') || 'create';
  var file = (val('file') || 'archive.tar.gz').trim();
  var paths = (val('paths') || '.').trim().split(/\s+/).filter(Boolean).join(' ');
  var v = val('verbose') === '1' || val('verbose') === 'true';
  var zip = val('compress') || 'gz';
  var flags = [];
  if (mode === 'create') flags.push('-c');
  else if (mode === 'extract') flags.push('-x');
  else flags.push('-t');
  if (zip === 'gz') flags.push('-z');
  else if (zip === 'bz2') flags.push('-j');
  else if (zip === 'xz') flags.push('-J');
  else if (zip === 'zstd') flags.push('--zstd');
  if (v) flags.push('-v');
  flags.push('-f ' + file);
  var cmd = 'tar ' + flags.join(' ') + (mode === 'create' ? ' ' + paths : '');
  var notes = [];
  if (mode === 'create') notes.push('# ' + (LANG === 'zh' ? '打包 ' : 'Create archive from ') + paths);
  else if (mode === 'extract') notes.push('# ' + (LANG === 'zh' ? '解包到当前目录，加 -C <dir> 指定目录' : 'Extract here; add -C <dir> for a target dir'));
  else notes.push('# ' + (LANG === 'zh' ? '列出归档内容' : 'List archive contents'));
  if (zip === 'none') notes.push('# ' + (LANG === 'zh' ? '未压缩，仅打包' : 'No compression, archive only'));
  return { out: notes.join('\n') + '\n' + cmd };
}

/* ---------- GEN：MySQL 命令生成 ---------- */
function b13MysqlCmd() {
  var op = val('op') || 'createdb';
  var db = b13SqlIdent(val('db') || 'mydb');
  var table = b13SqlIdent(val('table') || 'users');
  var user = (val('user') || 'appuser').replace(/[^\w]/g, '');
  var host = (val('host') || 'localhost').replace(/[^\w.%]/g, '');
  var pass = val('pass') || 'StrongPass123!';
  var charset = val('charset') || 'utf8mb4';
  var out = [];
  if (op === 'createdb') {
    out.push('# ' + (LANG === 'zh' ? '创建数据库' : 'Create database'));
    out.push('CREATE DATABASE IF NOT EXISTS `' + db + '` CHARACTER SET ' + charset + ' COLLATE ' + charset + '_unicode_ci;');
    out.push('USE `' + db + '`;');
  } else if (op === 'createtable') {
    out.push('# ' + (LANG === 'zh' ? '创建表' : 'Create table'));
    out.push('USE `' + db + '`;');
    out.push('CREATE TABLE IF NOT EXISTS `' + table + '` (');
    out.push('  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,');
    out.push('  `name` VARCHAR(100) NOT NULL,');
    out.push('  `email` VARCHAR(190) NOT NULL,');
    out.push('  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,');
    out.push('  PRIMARY KEY (`id`),');
    out.push('  UNIQUE KEY `uk_email` (`email`)');
    out.push(') ENGINE=InnoDB DEFAULT CHARSET=' + charset + ';');
  } else if (op === 'grant') {
    out.push('# ' + (LANG === 'zh' ? '创建用户并授权' : 'Create user and grant'));
    out.push("CREATE USER IF NOT EXISTS '" + user + "'@'" + host + "' IDENTIFIED BY '" + pass + "';");
    out.push("GRANT SELECT, INSERT, UPDATE, DELETE ON `" + db + "`.* TO '" + user + "'@'" + host + "';");
    out.push('FLUSH PRIVILEGES;');
  } else if (op === 'backup') {
    out.push('# ' + (LANG === 'zh' ? '备份数据库' : 'Backup database'));
    out.push('mysqldump -u root -p --single-transaction --routines --triggers ' + db + ' > ' + db + '_$(date +%F).sql');
    out.push('# ' + (LANG === 'zh' ? '恢复' : 'Restore'));
    out.push('mysql -u root -p ' + db + ' < ' + db + '_2026-01-01.sql');
  } else if (op === 'dump-table') {
    out.push('# ' + (LANG === 'zh' ? '只导出一张表' : 'Dump a single table'));
    out.push('mysqldump -u root -p ' + db + ' ' + table + ' > ' + table + '.sql');
  } else if (op === 'import-csv') {
    out.push('# ' + (LANG === 'zh' ? '从 CSV 导入' : 'Import from CSV'));
    out.push("LOAD DATA LOCAL INFILE '/tmp/data.csv'");
    out.push("INTO TABLE `" + table + "`");
    out.push("FIELDS TERMINATED BY ',' ENCLOSED BY '\"' LINES TERMINATED BY '\\n'");
    out.push('IGNORE 1 ROWS;');
  } else if (op === 'index') {
    out.push('# ' + (LANG === 'zh' ? '常用索引维护' : 'Index maintenance'));
    out.push('SHOW INDEX FROM `' + table + '`;');
    out.push('CREATE INDEX idx_' + table + '_created ON `' + table + '` (`created_at`);');
    out.push('ANALYZE TABLE `' + table + '`;');
  }
  return { out: out.join('\n') };
}

/* ---------- GEN：重定向规则生成 ---------- */
function b13Redirect() {
  var kind = val('kind') || 'nginx';
  var from = (val('from') || '/old-page').trim();
  var to = (val('to') || 'https://example.com/new-page').trim();
  var code = val('code') || '301';
  var out = [];
  if (kind === 'nginx') {
    out.push('# ' + (LANG === 'zh' ? '放在 server {} 块内' : 'Inside the server {} block'));
    out.push('location = ' + from + ' {');
    out.push('    return ' + code + ' ' + to + ';');
    out.push('}');
    out.push('');
    out.push('# ' + (LANG === 'zh' ? '整站跳转' : 'Whole-site redirect'));
    out.push('return ' + code + ' ' + to + '$request_uri;');
  } else if (kind === 'apache') {
    out.push('# .htaccess 或 <VirtualHost> 内');
    out.push('Redirect ' + code + ' ' + from + ' ' + to);
    out.push('');
    out.push('# ' + (LANG === 'zh' ? '或用 RewriteRule' : 'Or with RewriteRule'));
    out.push('RewriteEngine On');
    out.push('RewriteRule ^' + from.replace(/^\//, '') + '$ ' + to + ' [R=' + code + ',L]');
  } else if (kind === 'html') {
    out.push('<!-- ' + (LANG === 'zh' ? '放到 <head> 中，0 秒后跳转' : 'Place in <head>, redirects after 0s') + ' -->');
    out.push('<meta http-equiv="refresh" content="0; url=' + to + '">');
  } else if (kind === 'js') {
    out.push('// ' + (LANG === 'zh' ? '浏览器端跳转' : 'Client-side redirect'));
    out.push("window.location.replace('" + to + "');");
  } else if (kind === 'php') {
    out.push('<?php');
    out.push('// ' + (LANG === 'zh' ? '必须在任何输出之前调用' : 'Must run before any output'));
    out.push("header('Location: " + to + "', true, " + code + ');');
    out.push('exit;');
  } else if (kind === 'meta-refresh') {
    out.push('<html><head>');
    out.push('<meta http-equiv="refresh" content="3; url=' + to + '">');
    out.push('</head><body>' + (LANG === 'zh' ? '正在跳转…' : 'Redirecting...') + ' <a href="' + to + '">' + to + '</a></body></html>');
  } else if (kind === 'cloudflare') {
    out.push('# ' + (LANG === 'zh' ? 'Cloudflare Bulk Redirects（CSV）' : 'Cloudflare Bulk Redirects (CSV)'));
    out.push(from + ',' + to + ',' + code);
  }
  return { out: out.join('\n') };
}

/* ---------- CALC：RGB / CMYK / HSV ---------- */
function b13Clamp255(v) { v = parseInt(v, 10); if (isNaN(v)) v = 0; return Math.min(255, Math.max(0, v)); }
function b13HexOf(r, g, b) { return '#' + [r, g, b].map(function (v) { return ('0' + b13Clamp255(v).toString(16)).slice(-2); }).join('').toUpperCase(); }
function b13RgbToCmyk(F) {
  var r = b13Clamp255(F('r')) / 255, g = b13Clamp255(F('g')) / 255, b = b13Clamp255(F('b')) / 255;
  var k = 1 - Math.max(r, g, b);
  var c = k === 1 ? 0 : (1 - r - k) / (1 - k);
  var m = k === 1 ? 0 : (1 - g - k) / (1 - k);
  var y = k === 1 ? 0 : (1 - b - k) / (1 - k);
  return { outs: { hex: b13HexOf(b13Clamp255(F('r')), b13Clamp255(F('g')), b13Clamp255(F('b'))), c: Math.round(c * 100) + '%', m: Math.round(m * 100) + '%', y: Math.round(y * 100) + '%', k: Math.round(k * 100) + '%', css: 'cmyk(' + Math.round(c * 100) + '%, ' + Math.round(m * 100) + '%, ' + Math.round(y * 100) + '%, ' + Math.round(k * 100) + '%)' } };
}
function b13CmykToRgb(F) {
  var c = Math.min(100, Math.max(0, parseInt(F('c'), 10) || 0)) / 100;
  var m = Math.min(100, Math.max(0, parseInt(F('m'), 10) || 0)) / 100;
  var y = Math.min(100, Math.max(0, parseInt(F('y'), 10) || 0)) / 100;
  var k = Math.min(100, Math.max(0, parseInt(F('k'), 10) || 0)) / 100;
  var r = Math.round(255 * (1 - c) * (1 - k)), g = Math.round(255 * (1 - m) * (1 - k)), b = Math.round(255 * (1 - y) * (1 - k));
  return { outs: { hex: b13HexOf(r, g, b), rgb: 'rgb(' + r + ', ' + g + ', ' + b + ')', css: 'rgb(' + r + ', ' + g + ', ' + b + ')', hsl: b13HslStr(r, g, b) } };
}
function b13HslStr(r, g, b) {
  r /= 255; g /= 255; b /= 255;
  var mx = Math.max(r, g, b), mn = Math.min(r, g, b), h = 0, s = 0, l = (mx + mn) / 2, d = mx - mn;
  if (d) { s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn); if (mx === r) h = ((g - b) / d + (g < b ? 6 : 0)); else if (mx === g) h = (b - r) / d + 2; else h = (r - g) / d + 4; h *= 60; }
  return 'hsl(' + Math.round(h) + ', ' + Math.round(s * 100) + '%, ' + Math.round(l * 100) + '%)';
}
function b13RgbToHsv(F) {
  var r = b13Clamp255(F('r')) / 255, g = b13Clamp255(F('g')) / 255, b = b13Clamp255(F('b')) / 255;
  var mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn, h = 0;
  if (d) {
    if (mx === r) h = ((g - b) / d + (g < b ? 6 : 0));
    else if (mx === g) h = (b - r) / d + 2;
    else h = (r - g) / d + 4;
    h *= 60;
  }
  var s = mx === 0 ? 0 : d / mx;
  return { outs: { hex: b13HexOf(b13Clamp255(F('r')), b13Clamp255(F('g')), b13Clamp255(F('b'))), hue: Math.round(h) + '°', sat: Math.round(s * 100) + '%', val: Math.round(mx * 100) + '%', css: 'hsv(' + Math.round(h) + ', ' + Math.round(s * 100) + '%, ' + Math.round(mx * 100) + '%)', hsl: b13HslStr(b13Clamp255(F('r')), b13Clamp255(F('g')), b13Clamp255(F('b'))) } };
}
function b13HsvToRgb(F) {
  var h = ((parseFloat(F('h')) || 0) % 360 + 360) % 360, s = Math.min(100, Math.max(0, parseFloat(F('s')) || 0)) / 100, v = Math.min(100, Math.max(0, parseFloat(F('v')) || 0)) / 100;
  var c = v * s, x = c * (1 - Math.abs(((h / 60) % 2) - 1)), m = v - c, seg = Math.floor(h / 60) % 6;
  var rgb = [[c, x, 0], [x, c, 0], [0, c, x], [0, x, c], [x, 0, c], [c, 0, x]][seg];
  var r = Math.round((rgb[0] + m) * 255), g = Math.round((rgb[1] + m) * 255), b = Math.round((rgb[2] + m) * 255);
  return { outs: { hex: b13HexOf(r, g, b), rgb: 'rgb(' + r + ', ' + g + ', ' + b + ')', css: 'rgb(' + r + ', ' + g + ', ' + b + ')', hsl: b13HslStr(r, g, b) } };
}
/* Pantone 近似对照（取欧氏距离最小者，结果仅供参考） */
var B13_PANTONE = [
  ['Yellow C', 'FEDD00'], ['Yellow 012 C', 'FFD700'], ['101 C', 'F5E9A6'], ['102 C', 'F1E389'], ['7406 C', 'F2C800'],
  ['116 C', 'FFCD00'], ['123 C', 'FFC72C'], ['137 C', 'FFA300'], ['151 C', 'FF8000'], ['165 C', 'FF5A00'],
  ['Orange 021 C', 'FE5000'], ['172 C', 'FA4616'], ['179 C', 'E03C31'], ['185 C', 'E4002B'], ['199 C', 'D50032'],
  ['Red 032 C', 'EF3340'], ['Warm Red C', 'F9423A'], ['Rubine Red C', 'CE0058'], ['Rhodamine Red C', 'E3007F'], ['225 C', 'D6006E'],
  ['238 C', 'B9006B'], ['259 C', '7B2D8B'], ['267 C', '5F2C82'], ['Purple C', 'BB29BB'], ['Violet C', '5F259F'],
  ['2728 C', '4D4DFF'], ['Blue 072 C', '10069F'], ['Reflex Blue C', '001489'], ['2748 C', '00205B'], ['2758 C', '00205B'],
  ['286 C', '0032A0'], ['293 C', '0033A0'], ['300 C', '0067A0'], ['3005 C', '005EB8'], ['307 C', '0072CE'],
  ['Process Blue C', '0085CA'], ['312 C', '00A3AD'], ['326 C', '00B2A9'], ['327 C', '00B2A9'], ['339 C', '00A99D'],
  ['347 C', '009A44'], ['354 C', '00B140'], ['361 C', '4CB648'], ['368 C', '78BE20'], ['375 C', '8DC63F'],
  ['Green C', '00A94F'], ['802 C', '44D62C'], ['382 C', 'BBD530'], ['393 C', 'D9D56F'], ['396 C', 'E8E83E'],
  ['448 C', '4A3C31'], ['4625 C', '5C3A21'], ['469 C', '8A6A4A'], ['Black C', '000000'], ['Black 6 C', '101820'],
  ['Cool Gray 1 C', 'D9D9D6'], ['Cool Gray 5 C', '888B8D'], ['Cool Gray 9 C', '4B4F52'], ['Cool Gray 11 C', '1B1C1D'],
  ['Warm Gray 1 C', 'D8D2C4'], ['Warm Gray 5 C', '8C857B'], ['Warm Gray 9 C', '554D42'], ['Warm Gray 11 C', '2A2622'],
  ['877 C', '8A8D8F'], ['871 C', 'A78B5C'], ['872 C', '84754E'], ['428 C', 'C9C8C3'], ['430 C', 'A9AEB2'],
  ['White C', 'FFFFFF'], ['7541 C', '9EA0A1'], ['Cool Gray 3 C', 'B2B4B6']
];
function b13HexToPantone(F) {
  var hex = String(F('hex') || '#3E7C46').trim().replace(/^#?/, '');
  if (hex.length === 3) hex = hex.split('').map(function (c) { return c + c; }).join('');
  if (!/^[0-9a-fA-F]{6}$/.test(hex)) return { outs: { name: '—', code: '—', delta: '—' } };
  var r = parseInt(hex.substr(0, 2), 16), g = parseInt(hex.substr(2, 2), 16), b = parseInt(hex.substr(4, 2), 16);
  /* redmean 加权距离（比朴素欧氏距离更贴近人眼） */
  function dist(pr, pg, pb) {
    var rm = (r + pr) / 2, dr = r - pr, dg = g - pg, db = b - pb;
    return (2 + rm / 256) * dr * dr + 4 * dg * dg + (2 + (255 - rm) / 256) * db * db;
  }
  function spread(v) { return Math.abs((v >> 16 & 255) - (v >> 8 & 255)) + Math.abs((v >> 8 & 255) - (v & 255)) + Math.abs((v >> 16 & 255) - (v & 255)); }
  var colorful = spread((r << 16) | (g << 8) | b) >= 60;
  var pool = B13_PANTONE.filter(function (p) {
    if (!colorful) return true;
    return spread(parseInt(p[1], 16)) >= 60;
  });
  if (!pool.length) pool = B13_PANTONE;
  var best = null, bd = 1e9;
  pool.forEach(function (p) {
    var pr = parseInt(p[1].substr(0, 2), 16), pg = parseInt(p[1].substr(2, 2), 16), pb = parseInt(p[1].substr(4, 2), 16);
    var d = dist(pr, pg, pb);
    if (d < bd) { bd = d; best = p; }
  });
  var distv = Math.sqrt(bd / 3);
  var quality = distv <= 12 ? (LANG === 'zh' ? '非常接近' : 'very close') : (distv <= 35 ? (LANG === 'zh' ? '较接近' : 'close') : (LANG === 'zh' ? '仅供粗略参考' : 'rough match only'));
  return { outs: { name: 'PANTONE ' + best[0], pcode: '#' + best[1], delta: 'ΔE≈' + Math.round(distv) + ' · ' + quality, sw: '#' + best[1] } };
}

  var CALC_FUNCS = {
    /* ---- 第十二批：CSS 生成器 ---- */
'css-radio-button-generator': function () { return d11Radio(); },
    'css-switch-generator': function () { return d11Switch(); },
    'css-checkbox-generator': function () { return d11Checkbox(); },
    'css-input-field-generator': function () { return d11Input(); },
    'css-select-dropdown-styler': function () { return d11Select(); },
    'css-tab-generator': function () { return d11Tabs(); },
    'css-accordion-generator': function () { return d11Accordion(); },
    'css-modal-generator': function () { return d11Modal(); },
    'css-toast-notification-generator': function () { return d11Toast(); },
    'css-pagination-generator': function () { return d11Pagination(); },
    'css-breadcrumb-generator': function () { return d11Breadcrumb(); },
    'css-table-styler': function () { return d11Table(); },
    'css-timeline-generator': function () { return d11Timeline(); },
    'css-pricing-table-generator': function () { return d11Pricing(); },
    'css-card-flip-generator': function () { return d11CardFlip(); },
    'css-menu-generator': function () { return d11Menu(); },
    'responsive-breakpoints-generator': function () { return d11Breakpoints(); },
    'container-query-generator': function () { return d11ContainerQuery(); },
    'css-masonry-layout-generator': function () { return d11Masonry(); },
    'layout-generator': function () { return d11Layout(); },
    'skeleton-screen-generator': function () { return d11Skeleton(); },
    'animated-background-generator': function () { return d11AnimBg(); },
    'neon-text-generator': function () { return d11Neon(); },
    'text-glitch-effect-generator': function () { return d11Glitch(); },
    'css-glow-generator': function () { return d11Glow(); },
    'text-stroke-generator': function () { return d11TextStroke(); },
    'squiggly-line-generator': function () { return d11Squiggly(); },
    'css-highlight-marker-generator': function () { return d11Highlight(); },
    'line-height-calculator': function () { return d11LineHeight(); },
    'letter-spacing-generator': function () { return d11LetterSpacing(); },
    'css-shapes-generator': function () { return d11Shapes(); },
    'css-mask-generator': function () { return d11Mask(); },
    'text-mask-generator': function () { return d11TextMask(); },
    'border-image-generator': function () { return d11BorderImage(); },
    'css-corner-fold-generator': function () { return d11CornerFold(); },
    'css-zigzag-border-generator': function () { return d11Zigzag(); },
    'css-multiple-columns-generator': function () { return d11Columns(); },
    'box-sizing-generator': function () { return d11BoxSizing(); },
    'css-tooltip-generator': function () { return d11Tooltip(); },
    'css-ribbon-generator': function () { return d11Ribbon(); },
    'notification-badge-generator': function () { return d11Badge(); },
    'input-range-styler': function () { return d11Range(); },
    'form-style-generator': function () { return d11Form(); },
    'css-3d-transform-generator': function () { return d11Transform3D(); },
    'perspective-generator': function () { return d11Perspective(); },
    'particle-background-generator': function () { return d11Particles(); },
    'duotone-generator': function () { return d11Duotone(); },
    'halftone-dither-generator': function () { return d11Halftone(); },
    'svg-text-path-generator': function () { return d11SvgTextPath(); },
    'gridlover': function () { return d11Gridlover(); },
    'quantity-query-generator': function () { return d11QuantityQuery(); },
    'wait-animate': function () { return d11WaitAnimate(); },
    'code-to-image-converter': function () { return d11CodeToImage(); },
    'react-native-shadow-generator': function () { return d11RnShadow(); },
    'image-triangulator': function () { return d11Triangulator(); },
    /* ---- 第十三批：色彩模型转换（2026-09-06 开源项目对照） ---- */
    'rgb-to-cmyk': function (F) { return b13RgbToCmyk(F); },
    'cmyk-to-rgb': function (F) { return b13CmykToRgb(F); },
    'rgb-to-hsv': function (F) { return b13RgbToHsv(F); },
    'hsv-to-rgb': function (F) { return b13HsvToRgb(F); },
    'hex-to-pantone': function (F) { return b13HexToPantone(F); },
    bmi: function () { var h = num('h') / 100, w = num('w'); if (!h) return { outs: { bmi: '—', cat: '—' } }; var b = w / (h * h); var cat = b < 18.5 ? L10N.thin : b < 24 ? L10N.normal : b < 28 ? L10N.fat : L10N.obese; return { outs: { bmi: b.toFixed(1), cat: cat } }; },
    loan: function () { var p = num('p'), r = num('r') / 100 / 12, y = num('y'); if (!p) return { outs: { monthly: '—', total: '—', interest: '—' } }; var n = y * 12; var m = r === 0 ? p / n : p * r / (1 - Math.pow(1 + r, -n)); var tot = m * n; return { outs: { monthly: m.toFixed(2), total: tot.toFixed(2), interest: (tot - p).toFixed(2) } }; },
    tip: function () { var bill = num('bill'), pct = num('pct'), sp = num('split') || 1; var tip = bill * pct / 100, tot = bill + tip; return { outs: { tip: tip.toFixed(2), total: tot.toFixed(2), per: (tot / sp).toFixed(2) } }; },
    interest: function () { var p = num('p'), r = num('r') / 100, t = num('t'), n = parseInt(val('n'), 10) || 1; var a = p * Math.pow(1 + r / n, n * t); return { outs: { amount: a.toFixed(2), interest: (a - p).toFixed(2) } }; },
    mortgage: function () { var price = num('price'), down = num('down'), pr = price - down, r = num('r') / 100 / 12, y = num('y'); var n = y * 12; var m = r === 0 ? pr / n : pr * r / (1 - Math.pow(1 + r, -n)); return { outs: { monthly: m.toFixed(2), total: (m * n).toFixed(2) } }; },
    salary: function () { var amt = num('amount'), per = val('period'); var monthly = per === 'year' ? amt / 12 : amt; var yearly = per === 'year' ? amt : amt * 12; return { outs: { monthly: monthly.toFixed(2), yearly: yearly.toFixed(2), hourly: (yearly / 2080).toFixed(2) } }; },
    countdown: function () { var t = new Date(val('target')); if (isNaN(t)) return { outs: { days: '—', hours: '—', abs: '—' } }; var d = t - new Date(); var days = Math.floor(d / 86400000), hrs = Math.floor(d % 86400000 / 3600000); return { outs: { days: String(days), hours: String(hrs), abs: t.toLocaleString() } }; },
    clock: function () { var tz = val('tz') || 'UTC'; try { var s = new Intl.DateTimeFormat(LANG === 'zh' ? 'zh-CN' : 'en-US', { timeZone: tz, hour12: false, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', weekday: 'short' }).format(new Date()); return { outs: { time: s.slice(-8), date: s.slice(0, -9) } }; } catch (e) { return { outs: { time: '—', date: '—' } }; } },
    weeks: function () { var a = new Date(val('d1')), b = new Date(val('d2')); if (isNaN(a) || isNaN(b)) return { outs: { weeks: '—' } }; return { outs: { weeks: ((b - a) / 604800000).toFixed(1) } }; },
    months: function () { var a = new Date(val('d1')), b = new Date(val('d2')); if (isNaN(a) || isNaN(b)) return { outs: { months: '—' } }; return { outs: { months: ((b.getFullYear() - a.getFullYear()) * 12 + b.getMonth() - a.getMonth()).toString() } }; },
    ageindays: function () { var bd = new Date(val('birth')); if (isNaN(bd)) return { outs: { days: '—' } }; return { outs: { days: Math.floor((new Date() - bd) / 86400000).toString() } }; },
    duration: function () { var a = new Date(val('d1')), b = new Date(val('d2')); if (isNaN(a) || isNaN(b)) return { outs: { dur: '—' } }; var s = Math.abs(b - a) / 1000, dd = Math.floor(s / 86400), hh = Math.floor(s % 86400 / 3600), mm = Math.floor(s % 3600 / 60); return { outs: { dur: dd + 'd ' + hh + 'h ' + mm + 'm' } }; },
    unitprice: function () { var t = num('total'), q = num('qty'); return { outs: { unit: q ? (t / q).toFixed(2) : '—' } }; },
    discount: function () { var p = num('price'), pct = num('pct'); var f = p * (1 - pct / 100); return { outs: { final: f.toFixed(2), saved: (p - f).toFixed(2) } }; },
    split: function () { var t = num('total'), n = num('n'); return { outs: { each: n ? (t / n).toFixed(2) : '—' } }; },
    prime: function () { var n = Math.round(num('n')); return { outs: { isPrime: isPrime(n) ? L10N.isPrime : L10N.composite, next: String(nextPrime(n)), prev: String(prevPrime(n) == null ? '—' : prevPrime(n)) } }; },
    factor: function () { var n = Math.round(Math.abs(num('n'))); if (!n) return { outs: { factors: '—', prime: '—' } }; var fs = [], pf = [], i; for (i = 1; i <= n; i++) if (n % i === 0) fs.push(i); var m = n; for (i = 2; i * i <= m; i++) while (m % i === 0) { pf.push(i); m /= i; } if (m > 1) pf.push(m); return { outs: { factors: fs.join(', '), prime: pf.join(' × ') } }; },
    gcdlcm: function () { var a = Math.round(num('a')), b = Math.round(num('b')); var g = gcd(a, b); return { outs: { gcd: String(g), lcm: String(a * b / g) } }; },
    factorial: function () { var n = Math.round(num('n')); if (n < 0 || n > 170) return { outs: { result: L10N.outOfRange } }; var f = 1; for (var i = 2; i <= n; i++) f *= i; return { outs: { result: String(f) } }; },
    perm: function () { var n = Math.round(num('n')), r = Math.round(num('r')); if (r > n) return { outs: { result: '0' } }; var p = 1; for (var i = n - r + 1; i <= n; i++) p *= i; return { outs: { result: String(p) } }; },
    comb: function () { var n = Math.round(num('n')), r = Math.round(num('r')); if (r > n) return { outs: { result: '0' } }; return { outs: { result: String(Math.round(fact(n) / (fact(r) * fact(n - r)))) } }; },
    matrix: function () { try { var a = parseMatrix(val('a')), b = parseMatrix(val('b')), op = val('op'); var r = matOp(a, b, op); if (typeof r === 'string') return { outs: { result: r } }; return { outs: { result: r.map(function (row) { return row.join('\t'); }).join('\n') || '空' } }; } catch (e) { return { outs: { result: L10N.calcErr } }; } },
    seq: function () { var s = num('start'), d = num('diff'), n = Math.round(num('n')) || 0, a = []; for (var i = 0; i < n; i++) a.push(s + d * i); return { outs: { result: a.join(', ') } }; },
    stats: function () { var arr = toLines(val('data')).map(Number).filter(function (x) { return !isNaN(x); }); if (!arr.length) return { outs: { mean: '—', median: '—', std: '—', min: '—', max: '—', sum: '—' } }; var sum = arr.reduce(function (a, b) { return a + b; }, 0), mean = sum / arr.length; var std = Math.sqrt(arr.reduce(function (a, b) { return a + (b - mean) * (b - mean); }, 0) / arr.length); return { outs: { mean: mean.toFixed(2), median: median(arr).toFixed(2), std: std.toFixed(2), min: String(Math.min.apply(null, arr)), max: String(Math.max.apply(null, arr)), sum: sum.toFixed(2) } }; },
    log: function () { var x = num('x'), base = num('base') || 10; if (x <= 0) return { outs: { result: '—' } }; return { outs: { result: (Math.log(x) / Math.log(base)).toFixed(6) } }; },
    trig: function () { var ang = num('angle'), u = val('unit'), rad = u === 'deg' ? ang * Math.PI / 180 : ang, fn = val('fn'); return { outs: { result: (fn === 'sin' ? Math.sin(rad) : fn === 'cos' ? Math.cos(rad) : Math.tan(rad)).toFixed(6) } }; },
    complex: function () { var a = num('a'), b = num('b'), op = val('op'); var r; if (op === 'abs') r = Math.hypot(a, b).toFixed(4); else if (op === 'conj') r = a + ' - ' + b + 'i'; else r = (Math.atan2(b, a) * 180 / Math.PI).toFixed(2) + '°'; return { outs: { result: r } }; },
    sqrt: function () { var x = num('x'); return { outs: { sqrt: x < 0 ? '—' : Math.sqrt(x).toFixed(4), cbrt: Math.cbrt(x).toFixed(4) } }; },
    power: function () { var base = num('base'), e = num('exp'); return { outs: { result: Math.pow(base, e).toFixed(6) } }; },
    modulo: function () { var a = num('a'), b = num('b'); return { outs: { result: b ? String(a % b) : '—' } }; },
    roman: function () { var M = [[1000, 'M'], [900, 'CM'], [500, 'D'], [400, 'CD'], [100, 'C'], [90, 'XC'], [50, 'L'], [40, 'XL'], [10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I']]; if (val('mode') === 'from') { var s = val('val').toUpperCase(), n = 0, i; for (i = 0; i < M.length; i++) { while (s.indexOf(M[i][1]) === 0) { n += M[i][0]; s = s.slice(M[i][1].length); } } return { outs: { result: String(n) } }; } var numv = Math.round(num('val')); if (numv < 1 || numv > 3999) return { outs: { result: '1-3999' } }; var o = ''; for (var i = 0; i < M.length; i++) { while (numv >= M[i][0]) { o += M[i][1]; numv -= M[i][0]; } } return { outs: { result: o } }; },
    avg: function () { var arr = toLines(val('data')).map(Number).filter(function (x) { return !isNaN(x); }); if (!arr.length) return { outs: { mean: '—', sum: '—', count: '0' } }; var sum = arr.reduce(function (a, b) { return a + b; }, 0); return { outs: { mean: (sum / arr.length).toFixed(2), sum: sum.toFixed(2), count: String(arr.length) } }; },
    prob: function () { var a = clamp(num('a'), 0, 1), b = clamp(num('b'), 0, 1), m = val('mode'); var r = m === 'both' ? a * b : m === 'either' ? a + b - a * b : a; return { outs: { result: (r * 100).toFixed(2) + '%' } }; },
    chmod: function () { var r = chmodCalc(val('perm')); return { outs: { symbolic: r.symbolic, bits: r.bits, info: r.info } }; },
    subnet: function () { var r = subnetCalc(val('ip'), val('prefix')); return { outs: { network: r.network, mask: r.mask, broadcast: r.broadcast, usable: r.usable, range: r.range } }; },
    iprange: function () { var r = iprangeCalc(val('range')); return { outs: { list: r.list, count: r.count } }; },
    ipv4conv: function () { var r = ipv4convCalc(val('ip')); return { outs: { int: r.int, hex: r.hex, bin: r.bin } }; },
    iban: function () { var r = ibanCalc(val('iban')); return { outs: { valid: r.valid, country: r.country, bban: r.bban, check: r.check } }; },
    temp: function () { var v = parseFloat(val('v')); if (isNaN(v)) return { outs: { result: '—', c: '—', f: '—', k: '—' } }; var r = tempCalc(v, val('from'), val('to')); return { outs: { result: r.result, c: r.c, f: r.f, k: r.k } }; },
    moneycn: function () { var v = parseFloat(val('amount')); if (isNaN(v)) return { outs: { upper: '—', simple: '—' } }; var u = moneyUpper(v); return { outs: { upper: u, simple: u.replace(/元整/, '元') } }; },
    idcard: function () { var r = idCardParse(val('id')); return { outs: { valid: r.valid, region: r.region, birth: r.birth, gender: r.gender, age: r.age } }; },
    matheval: function () { return { outs: { result: String(evalExpr(val('expr'))) } }; },
    oggen: function () { var L = []; var t = val('title'), d = val('desc'), u = val('url'), i = val('image'), s = val('site'); if (t) L.push('<meta property="og:title" content="' + esc(t) + '">'); if (d) L.push('<meta property="og:description" content="' + esc(d) + '">'); if (u) L.push('<meta property="og:url" content="' + esc(u) + '">'); if (i) L.push('<meta property="og:image" content="' + esc(i) + '">'); if (s) L.push('<meta property="og:site_name" content="' + esc(s) + '">'); if (t) L.push('<meta name="twitter:card" content="summary_large_image">'); return { code: L.join('\n') || L10N.emptyOut }; },
    twgen: function () { var L = []; var t = val('title'), d = val('desc'), i = val('image'), s = val('site'), ty = val('type') || 'summary_large_image'; if (ty) L.push('<meta name="twitter:card" content="' + esc(ty) + '">'); if (s) L.push('<meta name="twitter:site" content="' + esc(s) + '">'); if (t) L.push('<meta name="twitter:title" content="' + esc(t) + '">'); if (d) L.push('<meta name="twitter:description" content="' + esc(d) + '">'); if (i) L.push('<meta name="twitter:image" content="' + esc(i) + '">'); return { code: L.join('\n') || L10N.emptyOut }; },
    serp: function () { var t = val('title'), d = val('desc'), u = val('url') || 'https://herontool.com/'; var host = u.replace(/^https?:\/\//, '').split(/[\/?#]/)[0] || u; var dots = d.length > 155 ? d.slice(0, 152) + '…' : d; var h = '<div class="serp-item"><div class="serp-fav"><span></span></div><div class="serp-link">' + esc(host) + '</div><div class="serp-title">' + (esc(t) || L10N.emptyOut) + '</div><div class="serp-desc">' + esc(dots) + '</div></div>'; return { outs: { preview: h } }; },
    xpath: function () { var xml = val('xml'), expr = (val('expr') || '').trim(); if (!xml || !expr) return { outs: { res: '—' } }; try { var doc = new DOMParser().parseFromString(xml, 'application/xml'); if (doc.querySelector('parsererror')) return { outs: { res: 'XML 解析错误' } }; var res = doc.evaluate(expr, doc, null, XPathResult.ORDERED_NODE_SNAPSHOT_TYPE, null); var out = []; for (var i = 0; i < res.snapshotLength; i++) { var n = res.snapshotItem(i); out.push(n.nodeType === 2 ? ('@' + n.nodeName + ' = ' + n.nodeValue) : (n.textContent || '').trim()); } return { outs: { res: out.length ? out.join('\n') : '(无匹配)' } }; } catch (e) { return { outs: { res: 'XPath 错误: ' + e.message } }; } },
    cronparse: function () { var e = (val('expr') || '').trim(); if (!e) return { outs: { nextruns: '—' } }; var p = e.split(/\s+/); if (p.length < 5) return { outs: { nextruns: '需要 5 个字段: 分 时 日 月 周' } }; var desc = cronDesc(p[0], p[1], p[2], p[3], p[4]); function inF(f, v, min, max) { if (f === '*') return true; var parts = f.split(','); for (var i = 0; i < parts.length; i++) { var part = parts[i].trim(); if (part.indexOf('/') >= 0) { var sp = part.split('/'); var base = sp[0] === '*' ? min : parseInt(sp[0], 10); var step = parseInt(sp[1], 10); if ((v - base) % step === 0 && v >= base) return true; } else if (part.indexOf('-') >= 0) { var rng = part.split('-'); if (v >= parseInt(rng[0], 10) && v <= parseInt(rng[1], 10)) return true; } else if (parseInt(part, 10) === v) return true; } return false; } function matchDay(domF, dowF, date) { var domOK = inF(domF, date.getDate(), 1, 31); var dowOK = inF(dowF, date.getDay(), 0, 7); if (domF === '*') return dowOK; if (dowF === '*') return domOK; return domOK || dowOK; } function ok(d) { return inF(p[0], d.getMinutes(), 0, 59) && inF(p[1], d.getHours(), 0, 23) && inF(p[3], d.getMonth() + 1, 1, 12) && matchDay(p[2], p[4], d); } var runs = [], d = new Date(Math.ceil(Date.now() / 60000) * 60000); for (var k = 0; k < 60 * 24 * 400 && runs.length < 5; k++) { if (ok(d)) runs.push(d.getFullYear() + '-' + zp(d.getMonth() + 1) + '-' + zp(d.getDate()) + ' ' + zp(d.getHours()) + ':' + zp(d.getMinutes())); d = new Date(d.getTime() + 60000); } return { outs: { nextruns: desc + '\n\n下次执行:\n' + (runs.length ? runs.join('\n') : '（未来 400 天内无匹配）') } }; },
    pct: function () { var o = num('old'), n = num('new'); if (!o && o !== 0) return { outs: { change: '—', abs: '—' } }; if (o === 0) return { outs: { change: (n > 0 ? '+∞' : '0') + '%', abs: String(n - o) } }; var c = (n - o) / o * 100; return { outs: { change: (c >= 0 ? '+' : '') + c.toFixed(2) + '%', abs: (n - o).toFixed(2) } }; },
    sleep: function () { var wt = (val('wake') || '').trim(); var m = wt.match(/^(\d{1,2}):(\d{2})$/); if (!m) return { outs: { result: '请输入 HH:MM（24 小时制）' } }; var base = new Date(); base.setHours(parseInt(m[1], 10), parseInt(m[2], 10), 0, 0); var lines = ['基于 90 分钟睡眠周期，建议入睡时间：', ''], labels = [6, 5, 4, 3, 2]; labels.forEach(function (cyc) { var t = new Date(base.getTime() - cyc * 90 * 60000); lines.push(cyc + ' 周期 · ' + zp(t.getHours()) + ':' + zp(t.getMinutes())); }); return { outs: { result: lines.join('\n') } }; },
    vat: function () { var amt = num('amount'), r = num('rate'), mode = val('mode'); if (!amt && amt !== 0) return { outs: { vat: '—', total: '—' } }; if (mode === 'incl') { var net = amt / (1 + r / 100); return { outs: { vat: (amt - net).toFixed(2), total: amt.toFixed(2) } }; } var vat = amt * r / 100; return { outs: { vat: vat.toFixed(2), total: (amt + vat).toFixed(2) } }; },
    bmr: function () { var h = num('h'), w = num('w'), age = num('age'), g = val('gender'), act = parseFloat(val('activity')) || 1.2; if (!h || !w || !age) return { outs: { bmr: '—', tdee: '—' } }; var b = 10 * w + 6.25 * h - 5 * age + (g === 'm' ? 5 : -161); return { outs: { bmr: b.toFixed(0), tdee: (b * act).toFixed(0) } }; },
    loader: function () { var kind = val('kind') || 'spinner', color = val('color') || '#3e7c46', size = parseInt(val('size'), 10) || 48, bw = Math.max(2, Math.round(size / 8)); var code, el; if (kind === 'dots') { var ds = Math.max(6, Math.round(size / 3)), dy = Math.max(6, Math.round(size / 4)); code = '.ht-loader{display:flex;gap:6px;align-items:center}\n.ht-loader span{width:' + ds + 'px;height:' + ds + 'px;border-radius:50%;background:' + color + ';display:inline-block;animation:ht-bounce .6s alternate infinite}\n.ht-loader span:nth-child(2){animation-delay:.2s}\n.ht-loader span:nth-child(3){animation-delay:.4s}\n@keyframes ht-bounce{to{transform:translateY(-' + dy + 'px)}}\n@keyframes ht-spin{to{transform:rotate(360deg)}}'; el = '<div style="display:flex;gap:6px;align-items:center"><span style="width:' + ds + 'px;height:' + ds + 'px;border-radius:50%;background:' + color + ';display:inline-block;animation:ht-bounce .6s alternate infinite"></span><span style="width:' + ds + 'px;height:' + ds + 'px;border-radius:50%;background:' + color + ';display:inline-block;animation:ht-bounce .6s alternate infinite .2s"></span><span style="width:' + ds + 'px;height:' + ds + 'px;border-radius:50%;background:' + color + ';display:inline-block;animation:ht-bounce .6s alternate infinite .4s"></span></div>'; } else if (kind === 'bars') { var bs = Math.max(4, Math.round(size / 8)); code = '.ht-loader{display:flex;gap:5px;align-items:flex-end;height:' + size + 'px}\n.ht-loader span{width:' + bs + 'px;background:' + color + ';display:inline-block;animation:ht-bars 1s ease-in-out infinite}\n.ht-loader span:nth-child(1){animation-delay:0s}.ht-loader span:nth-child(2){animation-delay:.15s}.ht-loader span:nth-child(3){animation-delay:.3s}.ht-loader span:nth-child(4){animation-delay:.45s}\n@keyframes ht-bars{0%,100%{height:30%}50%{height:100%}}\n@keyframes ht-spin{to{transform:rotate(360deg)}}'; el = '<div style="display:flex;gap:5px;align-items:flex-end;height:' + size + 'px"><span style="width:' + bs + 'px;background:' + color + ';display:inline-block;animation:ht-bars 1s ease-in-out infinite"></span><span style="width:' + bs + 'px;background:' + color + ';display:inline-block;animation:ht-bars 1s ease-in-out infinite .15s"></span><span style="width:' + bs + 'px;background:' + color + ';display:inline-block;animation:ht-bars 1s ease-in-out infinite .3s"></span><span style="width:' + bs + 'px;background:' + color + ';display:inline-block;animation:ht-bars 1s ease-in-out infinite .45s"></span></div>'; } else { code = '.ht-loader{width:' + size + 'px;height:' + size + 'px;border:' + bw + 'px solid ' + color + '33;border-top-color:' + color + ';border-radius:50%;animation:ht-spin 1s linear infinite}\n@keyframes ht-spin{to{transform:rotate(360deg)}}'; el = '<div style="width:' + size + 'px;height:' + size + 'px;border:' + bw + 'px solid ' + color + '33;border-top-color:' + color + ';border-radius:50%;animation:ht-spin 1s linear infinite"></div>'; } return { code: code, outs: { preview: '<style>' + code + '</style>' + el } }; },
    clip: function () { var shape = val('shape') || 'circle', size = clamp(parseInt(val('size'), 10) || 50, 0, 100), path; if (shape === 'circle') path = 'clip-path: circle(' + size + '% at 50% 50%);'; else if (shape === 'ellipse') path = 'clip-path: ellipse(' + size + '% ' + size + '% at 50% 50%);'; else if (shape === 'inset') path = 'clip-path: inset(' + (100 - size) + '% round 10px);'; else path = 'clip-path: polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%);'; var code = '.ht-clipped{\n  ' + path + '\n  width:160px;height:160px;background:linear-gradient(135deg,#2f5a35,#3e7c46);\n}'; return { code: code, outs: { preview: '<div style="' + path + ' width:160px;height:160px;background:linear-gradient(135deg,#2f5a35,#3e7c46)"></div>' } }; },
    neumorph: function () { var size = parseInt(val('size'), 10) || 160, radius = parseInt(val('radius'), 10) || 32, bg = val('bg') || '#e0e5ec', dist = parseInt(val('dist'), 10) || 12; function sh(hex, pct) { var c = hexToRgb(hex) || [224, 229, 236]; return rgbToHex(clamp(c[0] + pct, 0, 255), clamp(c[1] + pct, 0, 255), clamp(c[2] + pct, 0, 255)); } var light = sh(bg, 45), dark = sh(bg, -45); var code = '.ht-neu{\n  width:' + size + 'px;height:' + size + 'px;border-radius:' + radius + 'px;\n  background:' + bg + ';\n  box-shadow: ' + dist + 'px ' + dist + 'px ' + (dist * 2) + 'px ' + dark + ', -' + dist + 'px -' + dist + 'px ' + (dist * 2) + 'px ' + light + ';\n}'; var el = '<div style="width:' + size + 'px;height:' + size + 'px;border-radius:' + radius + 'px;background:' + bg + ';box-shadow:' + dist + 'px ' + dist + 'px ' + (dist * 2) + 'px ' + dark + ', -' + dist + 'px -' + dist + 'px ' + (dist * 2) + 'px ' + light + '"></div>'; return { code: code, outs: { preview: el } }; },

    // =================== 第七批新增：设计生成器（2026-09-03） ===================
    conicgrad: function () {
      var c1 = val('c1') || '#3e7c46', c2 = val('c2') || '#e7efe1', c3 = val('c3') || '', from = parseInt(val('from'), 10) || 0, rep = val('rep') === '1';
      var stops = c3 ? [c1 + ' 0%', c2 + ' 50%', c3 + ' 100%'] : [c1 + ' 0%', c2 + ' 100%'];
      var fn = (rep ? 'repeating-conic-gradient' : 'conic-gradient') + '(from ' + from + 'deg, ' + stops.join(', ') + ')';
      return { outs: { stops: stops.join(' → ') }, code: 'background: ' + fn + ';' };
    },
    meshgrad: function () {
      var c1 = val('c1') || '#3e7c46', c2 = val('c2') || '#7ab8a0', c3 = val('c3') || '#e7efe1', c4 = val('c4') || '#f5f8f2';
      var layers = ['radial-gradient(at 20% 20%, ' + c1 + ' 0px, transparent 55%)', 'radial-gradient(at 80% 20%, ' + c2 + ' 0px, transparent 55%)', 'radial-gradient(at 30% 80%, ' + c3 + ' 0px, transparent 55%)', 'radial-gradient(at 75% 75%, ' + c4 + ' 0px, transparent 55%)'];
      return { outs: { count: String(layers.length) }, code: 'background-color: ' + c4 + ';\nbackground-image:\n  ' + layers.join(',\n  ') + ';\nbackground-attachment: fixed;' };
    },
    gradborder: function () {
      var c1 = val('c1') || '#3e7c46', c2 = val('c2') || '#7ab8a0', w = parseInt(val('w'), 10) || 4, r = parseInt(val('r'), 10) || 12, style = val('style') || 'border-image';
      var code;
      if (style === 'border-image') code = 'border: ' + w + 'px solid transparent;\nborder-radius: ' + r + 'px;\nbackground-image: linear-gradient(#fff, #fff), linear-gradient(90deg, ' + c1 + ', ' + c2 + ');\nbackground-origin: border-box;\nbackground-clip: padding-box, border-box;';
      else code = 'position: relative;\nborder-radius: ' + r + 'px;\nbackground: #fff;\n\n/* ::before — gradient ring */\ncontent: "";\nposition: absolute;\ninset: 0;\nborder-radius: ' + r + 'px;\npadding: ' + w + 'px;\nbackground: linear-gradient(90deg, ' + c1 + ', ' + c2 + ');\n-webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);\n-webkit-mask-composite: xor;\nmask-composite: exclude;';
      return { outs: { width: w + 'px', radius: r + 'px' }, code: code };
    },
    shades: function () {
      var base = val('base') || '#3e7c46';
      var rgb = hexToRgb(base); if (!rgb) return { outs: { hex: '—' }, code: '' };
      function mix(a, b, p) { return Math.round(a + (b - a) * p); }
      var steps = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
      var lines = [], rows = [];
      steps.forEach(function (s, i) {
        var p = i / (steps.length - 1);
        var target = s < 500 ? 255 : (s > 500 ? 0 : null);
        var r, g, b;
        if (s === 500) { r = rgb[0]; g = rgb[1]; b = rgb[2]; }
        else if (s < 500) { var t = (500 - s) / 500; r = mix(rgb[0], 255, t); g = mix(rgb[1], 255, t); b = mix(rgb[2], 255, t); }
        else { var t2 = (s - 500) / 500; r = mix(rgb[0], 0, t2); g = mix(rgb[1], 0, t2); b = mix(rgb[2], 0, t2); }
        var hex = rgbToHex(r, g, b);
        lines.push('  --color-' + s + ': ' + hex + ';');
        rows.push(s + ': ' + hex);
      });
      return { outs: { palette: rows.join('  ') }, code: ':root {\n' + lines.join('\n') + '\n}' };
    },
    oklch: function () {
      var L = parseFloat(val('l')); if (isNaN(L)) L = 0.6;
      var C = parseFloat(val('c')); if (isNaN(C)) C = 0.12;
      var H = parseFloat(val('h')); if (isNaN(H)) H = 150;
      var hex = b9OklchToHex(L, C, H);
      var rgb = hexToRgb(hex) || [0, 0, 0];
      var hsl = b9RgbToHsl(rgb[0], rgb[1], rgb[2]);
      return { outs: { hex: hex.toUpperCase(), rgb: 'rgb(' + rgb.join(', ') + ')', hsl: 'hsl(' + hsl[0] + ', ' + hsl[1] + '%, ' + hsl[2] + '%)', css: 'oklch(' + (L * 100).toFixed(1) + '% ' + C.toFixed(3) + ' ' + H.toFixed(1) + ')' }, code: 'color: oklch(' + (L * 100).toFixed(1) + '% ' + C.toFixed(3) + ' ' + H.toFixed(1) + ');\n/* fallback */\ncolor: ' + hex + ';' };
    },
    dropshadow: function () {
      var x = val('x') || '0', y = val('y') || '4', blur = val('blur') || '12', color = val('color') || 'rgba(0,0,0,0.25)', layers = parseInt(val('layers'), 10) || 1;
      var arr = [];
      for (var i = 0; i < layers; i++) arr.push(x + 'px ' + y + 'px ' + blur + 'px ' + color);
      return { outs: { layers: String(layers) }, code: 'filter: drop-shadow(' + arr.join(') drop-shadow(') + ');' };
    },
    aspectratio: function () {
      var w = parseFloat(val('w')), h = parseFloat(val('h'));
      var kw = parseFloat(val('kw')), kh = parseFloat(val('kh'));
      if (!w || !h) return { outs: { res: '—', ratio: '—' }, code: '' };
      var res = '';
      if (kw && !kh) { kh = kw * h / w; res = kw + ' × ' + Math.round(kh * 100) / 100; }
      else if (kh && !kw) { kw = kh * w / h; res = Math.round(kw * 100) / 100 + ' × ' + kh; }
      else if (kw && kh) { res = 'Given both — check ratio'; }
      var g = b9Gcd(Math.round(w), Math.round(h));
      return { outs: { res: res || '—', ratio: (w / g) + ':' + (h / g), dec: (w / h).toFixed(4) }, code: 'aspect-ratio: ' + w + ' / ' + h + ';' };
    },
    fluidtype: function () {
      var minFs = parseFloat(val('minfs')) || 16, maxFs = parseFloat(val('maxfs')) || 24;
      var minVw = parseFloat(val('minvw')) || 320, maxVw = parseFloat(val('maxvw')) || 1200;
      if (maxVw === minVw) maxVw = minVw + 1;
      var slope = (maxFs - minFs) / (maxVw - minVw);
      var yAxis = -minVw * slope + minFs;
      var vw = (slope * 100).toFixed(4), rem = yAxis.toFixed(4);
      return { outs: { atmin: minFs + 'px', atmax: maxFs + 'px', slope: vw + 'vw' }, code: 'font-size: clamp(' + minFs + 'px, ' + rem + 'rem + ' + vw + 'vw, ' + maxFs + 'px);' };
    },
    goldenratio: function () {
      var v = parseFloat(val('v')) || 100, mode = val('mode') || 'both';
      var PHI = 1.618033988749895;
      var a, b;
      if (mode === 'larger') { a = v; b = v * PHI; }
      else if (mode === 'smaller') { a = v / PHI; b = v; }
      else { a = v / PHI; b = v * PHI; }
      return { outs: { small: a.toFixed(2), large: b.toFixed(2), phi: PHI.toFixed(6), pct: (100 / PHI).toFixed(2) + '% / ' + (100 - 100 / PHI).toFixed(2) + '%' }, code: '/* golden split */\n--part-a: ' + a.toFixed(2) + 'px;\n--part-b: ' + b.toFixed(2) + 'px;' };
    },

    'calcgen': function (v) { var vars = {}; (DETAIL.fields || []).forEach(function (f) { vars[f.id] = v(f.id); }); return CALC_REG.formula(vars, DETAIL); }
  };
  function fact(n) { var f = 1; for (var i = 2; i <= n; i++) f *= i; return f; }

  /* ---- 第七批设计/生成辅助 ---- */
  function b9Gcd(a, b) { a = Math.abs(a); b = Math.abs(b); while (b) { var t = b; b = a % b; a = t; } return a || 1; }
  function b9RgbToHsl(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    var mx = Math.max(r, g, b), mn = Math.min(r, g, b), h = 0, s = 0, l = (mx + mn) / 2, d = mx - mn;
    if (d) {
      s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn);
      if (mx === r) h = ((g - b) / d + (g < b ? 6 : 0));
      else if (mx === g) h = (b - r) / d + 2;
      else h = (r - g) / d + 4;
      h *= 60;
    }
    return [Math.round(h), Math.round(s * 100), Math.round(l * 100)];
  }
  function b9OklchToHex(L, C, hDeg) {
    var h = hDeg * Math.PI / 180;
    var a = C * Math.cos(h), bb = C * Math.sin(h);
    var l_ = L + 0.3963377774 * a + 0.2158037573 * bb;
    var m_ = L - 0.1055613458 * a - 0.0638541728 * bb;
    var s_ = L - 0.0894841775 * a - 1.2914855480 * bb;
    var l3 = l_ * l_ * l_, m3 = m_ * m_ * m_, s3 = s_ * s_ * s_;
    var r = 4.0767416621 * l3 - 3.3077115913 * m3 + 0.2309699292 * s3;
    var g = -1.2684380046 * l3 + 2.6097574011 * m3 - 0.3413193965 * s3;
    var b2 = -0.0041960863 * l3 - 0.7034186147 * m3 + 1.7076147010 * s3;
    function gam(x) { return x <= 0.0031308 ? 12.92 * x : 1.055 * Math.pow(Math.max(x, 0), 1 / 2.4) - 0.055; }
    return rgbToHex(Math.round(clamp(gam(r), 0, 1) * 255), Math.round(clamp(gam(g), 0, 1) * 255), Math.round(clamp(gam(b2), 0, 1) * 255));
  }
  function b9Luhn(num) {
    var sum = 0, alt = false, i;
    for (i = num.length - 1; i >= 0; i--) {
      var d = parseInt(num[i], 10);
      if (alt) { d *= 2; if (d > 9) d -= 9; }
      sum += d; alt = !alt;
    }
    return num + String((10 - sum % 10) % 10);
  }
  function b9IbanCheck(str) {
    var rearranged = '', i;
    for (i = 0; i < str.length; i++) {
      var c = str.charAt(i);
      rearranged += (c >= '0' && c <= '9') ? c : String(str.charCodeAt(i) - 55);
    }
    var rem = 0;
    for (i = 0; i < rearranged.length; i++) rem = (rem * 10 + parseInt(rearranged.charAt(i), 10)) % 97;
    return ('0' + (98 - rem)).slice(-2);
  }

  // =================== GEN ===================
  var GEN_LEGACY = { 'random-number-generator': 1, 'color-palette-generator': 1 };
  var LOREM = 'lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua enim ad minim veniam quis nostrud exercitation ullamco laboris nisi aliquip ex ea commodo'.split(' ');
  var EMOJI = '😀 😎 🚀 🌟 🔥 💡 🍀 🌈 ⚡ 🐝 🌿 💧 🍃 🌸 🎯 💎 🌞 🍵 ☕ 📚 🌍 💪 ✨ 🧠 🛠️ 🍂'.split(' ');
  var ADJ = ['smart', 'fast', 'clean', 'bold', 'calm', 'bright', 'quiet', 'sharp', 'free', 'pro', 'eco', 'neo', 'ultra', 'mini', 'max'];
  var NOUN = ['tool', 'lab', 'hub', 'kit', 'box', 'stack', 'flow', 'wave', 'spark', 'core', 'base', 'zone', 'forge', 'works', 'studio'];
  var CN_ADJ = ['智能', '极速', '清爽', '简约', '优雅', '明亮', '安静', '敏锐', '自由', '专业', '环保', '新锐', '超强', '轻量', '满分'];
  var CN_NOUN = ['工具', '实验室', '中心', '套装', '盒子', '栈', '流', '波', '火花', '核心', '基地', '地带', '工坊', '工厂', '工作室'];
  var THEME = ['spring', 'ocean', 'forest', 'city', 'coffee', 'travel', 'music', 'code', 'love', 'dream'];
  function rnd(a) { return a[Math.floor(Math.random() * a.length)]; }
  var GEN_FUNCS = {
    /* ---- 第十三批：随机数据 / 命令生成器（2026-09-06 开源项目对照） ---- */
    'random-letter-generator': function () { return b13RandLetter(); },
    'random-date-generator': function () { return b13RandDate(); },
    'random-hex-generator': function () { return b13RandHex(); },
    'random-json-generator': function () { return b13RandJson(); },
    'random-csv-generator': function () { return b13RandCsv(); },
    'random-xml-generator': function () { return b13RandXml(); },
    'random-data-from-regex': function () { return b13RandFromRegex(); },
    'tar-command-generator': function () { return b13TarCmd(); },
    'mysql-command-generator': function () { return b13MysqlCmd(); },
    'redirect-generator': function () { return b13Redirect(); },
    lorem: function () { var n = clamp(parseInt(val('n'), 10) || 50, 1, 500), p = clamp(parseInt(val('p'), 10) || 3, 1, 20), out = []; for (var i = 0; i < p; i++) { var line = []; for (var j = 0; j < n; j++) line.push(LOREM[Math.floor(Math.random() * LOREM.length)]); out.push(toTitle(line.join(' ')) + '.'); } return { out: out.join('\n\n') }; },
    uuid: function () { var n = clamp(parseInt(val('n'), 10) || 5, 1, 100), up = val('case') === 'upper', out = []; for (var i = 0; i < n; i++) { var u = (crypto && crypto.randomUUID) ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = Math.random() * 16 | 0; return (c === 'x' ? r : r & 3 | 8).toString(16); }); out.push(up ? u.toUpperCase() : u); } return { out: out.join('\n') }; },
    password: function () { var len = clamp(parseInt(val('len'), 10) || 16, 4, 64); var ch = ''; if (val('upper') === '1') ch += 'ABCDEFGHJKLMNPQRSTUVWXYZ'; if (val('lower') === '1') ch += 'abcdefghijkmnpqrstuvwxyz'; if (val('num') === '1') ch += '23456789'; if (val('sym') === '1') ch += '!@#$%^&*'; if (!ch) ch = 'abcdefghijkmnpqrstuvwxyz'; var o = ''; for (var i = 0; i < len; i++) o += ch[Math.floor(Math.random() * ch.length)]; return { out: o }; },
    random: function () { var mn = parseInt(val('min'), 10) || 0, mx = parseInt(val('max'), 10) || 100, n = clamp(parseInt(val('n'), 10) || 5, 1, 200), a = []; for (var i = 0; i < n; i++) a.push(Math.floor(Math.random() * (mx - mn + 1)) + mn); return { out: a.join('\n') }; },
    emoji: function () { var n = clamp(parseInt(val('n'), 10) || 10, 1, 50), a = []; for (var i = 0; i < n; i++) a.push(rnd(EMOJI)); return { out: a.join(' ') }; },
    title: function () { var t = val('topic') || 'your topic'; return { out: (LANG === 'zh' ? CN_ADJ : ADJ).map(function (a) { return toTitle(a + ' ' + t); }).slice(0, 6).join('\n') }; },
    slogan: function () { var t = val('topic') || 'Brand'; var f = LANG === 'zh' ? [t + '，让一切更简单', '更' + rnd(CN_ADJ) + '的' + t, t + '·' + rnd(CN_NOUN)] : ['Make ' + t + ' simple', 'The ' + rnd(ADJ) + ' ' + t, t + ' — ' + rnd(NOUN) + ' for you']; return { out: f.join('\n') }; },
    name: function () { var g = val('gender'); var first = ['Alex', 'Sam', 'Jordan', 'Robin', 'Taylor', 'Casey', 'Morgan', 'Lee']; var cn = ['小苍', '明', '晓', 'river', '青', '林', '禾', '鹭']; return { out: (LANG === 'zh' ? cn : first).map(function (n) { return n; }).slice(0, 8).join('\n') }; },
    paragraph: function () { var t = val('topic') || 'this', n = clamp(parseInt(val('n'), 10) || 4, 1, 20), a = []; for (var i = 0; i < n; i++) a.push('A ' + rnd(ADJ) + ' ' + t + ' helps you ' + rnd(VERB) + ' with less effort.'); return { out: a.join(' ') }; },
    hashtag: function () { var t = val('topic') || 'topic', n = clamp(parseInt(val('n'), 10) || 8, 1, 30), a = []; for (var i = 0; i < n; i++) a.push('#' + rnd((LANG === 'zh' ? CN_NOUN : NOUN)) + (LANG === 'zh' ? '' : rnd(ADJ))); return { out: a.join(' ') }; },
    keyword: function () { var t = val('topic') || 'tool', n = clamp(parseInt(val('n'), 10) || 10, 1, 40), a = []; for (var i = 0; i < n; i++) a.push(t + ' ' + rnd(LANG === 'zh' ? CN_ADJ : ADJ)); return { out: a.join('\n') }; },
    metadesc: function () { var t = val('topic') || 'our product', n = clamp(parseInt(val('len'), 10) || 155, 50, 300); var s = 'Discover ' + t + ' — a ' + rnd(ADJ) + ' ' + rnd(NOUN) + ' that helps you ' + rnd(VERB) + ' faster and easier.'; return { out: s.slice(0, n) }; },
    cta: function () { var t = val('topic') || 'get started'; return { out: [toTitle(t) + ' Now', 'Try ' + t + ' Free', 'Start ' + t].join('\n') }; },
    tweet: function () { var t = val('topic') || 'something'; return { out: 'Just shipped a ' + rnd(ADJ) + ' ' + t + '! 🚀 ' + rnd(HASHTAG) + ' ' + rnd(HASHTAG) }; },
    caption: function () { var t = val('topic') || 'this moment'; return { out: rnd(CAPTION) + ' ' + t + ' ' + rnd(EMOJI) }; },
    ytdesc: function () { var t = val('topic') || 'this'; return { out: 'In this video we explore ' + t + '. You will learn how to ' + rnd(VERB) + ' step by step. Subscribe for more ' + rnd(ADJ) + ' tips!' }; },
    story: function () { var t = val('topic') || 'a key'; return { out: 'Once upon a time there was ' + t + '. It changed everything, and taught us to ' + rnd(VERB) + ' with courage.' }; },
    poem: function () { var t = val('topic') || 'spring'; return { out: (LANG === 'zh' ? ['《' + t + '》', rnd(CN_ADJ) + '的' + t + '轻轻来，', '一行一行写心怀。', '不问归期何处去，', '只留清影入窗台。'] : ['O ' + t, 'soft and ' + rnd(ADJ), 'you ' + rnd(VERB) + ' slow', 'where the ' + rnd(NOUN) + ' glow']).join('\n') }; },
    robots: function () { var s = val('site') || 'https://example.com'; return { out: 'User-agent: *\nAllow: /\nSitemap: ' + s.replace(/\/$/, '') + '/sitemap.xml' }; },
    sitemap: function () { var s = (val('site') || 'https://example.com').replace(/\/$/, ''); return { out: '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n  <url><loc>' + s + '/</loc><changefreq>daily</changefreq></url>\n</urlset>' }; },
    jsonld: function () { var ty = val('type'), nm = val('site') || 'HeronTool'; var o = { '@context': 'https://schema.org', name: nm }; if (ty === 'org') o['@type'] = 'Organization'; else if (ty === 'article') o['@type'] = 'Article'; else if (ty === 'product') o['@type'] = 'Product'; else if (ty === 'breadcrumb') o['@type'] = 'BreadcrumbList'; else if (ty === 'faq') o['@type'] = 'FAQPage'; else o['@type'] = 'WebSite'; return { out: JSON.stringify(o, null, 2) }; },
    alt: function () { var k = val('keyword') || 'image'; return { out: 'Alt text: ' + toTitle(k) + ' — ' + rnd(ADJ) + ' ' + rnd(NOUN) + ' photo' }; },
    canonical: function () { var u = val('url') || 'https://example.com/page'; return { out: '<link rel="canonical" href="' + esc(u) + '" />' }; },
    hreflang: function () { var u = val('url') || 'https://example.com/', langs = (val('langs') || 'en,zh-CN').split(','); return { out: langs.map(function (l) { return '<link rel="alternate" hreflang="' + l.trim() + '" href="' + esc(u) + '" />'; }).join('\n') }; },
    pick: function () { var items = toLines(val('items')).filter(function (l) { return l.trim() !== ''; }); return { out: items.length ? rnd(items) : L10N.needOptions }; },
    dice: function () { var sides = clamp(parseInt(val('n'), 10) || 6, 2, 100); return { out: '🎲 ' + (Math.floor(Math.random() * sides) + 1) }; },
    coin: function () { var n = clamp(parseInt(val('n'), 10) || 1, 1, 20), a = []; for (var i = 0; i < n; i++) a.push(Math.random() < .5 ? L10N.heads : L10N.tails); return { out: a.join(' ') }; },
    strength: function () { var p = val('pw'); var s = 0; if (p.length >= 8) s++; if (p.length >= 12) s++; if (/[a-z]/.test(p)) s++; if (/[A-Z]/.test(p)) s++; if (/\d/.test(p)) s++; if (/[^A-Za-z0-9]/.test(p)) s++; var lv = [L10N.veryWeak, L10N.weak, L10N.mid, L10N.strong, L10N.veryStrong][Math.min(4, Math.floor(s * 4 / 5))]; return { out: L10N.strength + lv + ' (' + s + '/6)' }; },
    calendar: function () { var y = parseInt(val('y'), 10) || 2026, m = clamp(parseInt(val('m'), 10) || 1, 1, 12); return { out: asciiCalendar(y, m) }; },
    bcrypt: function () { var mode = val('mode'), data = val('data'), hash = val('hash'), rounds = clamp(parseInt(val('rounds'), 10) || 10, 4, 15); if (!data) return { out: LANG === 'zh' ? '请先输入文本' : 'Enter some text first' }; if (mode === 'verify') { if (!hash) return { out: LANG === 'zh' ? '请填写要验证的哈希' : 'Enter the hash to verify against' }; var ok = dcodeIO.bcrypt.compareSync(data, hash); return { out: ok ? (LANG === 'zh' ? '✓ 匹配（密码正确）' : '✓ Match — password is correct') : (LANG === 'zh' ? '✗ 不匹配' : '✗ Does not match') }; } return { out: dcodeIO.bcrypt.hashSync(data, rounds) }; },
    aes: function () { var mode = val('mode'), data = val('data'), key = val('key') || 'my-secret-key-16c'; if (!data) return { out: LANG === 'zh' ? '请输入数据' : 'Enter some data' }; return aesRun(mode, data, key).then(function (s) { return { out: s }; }).catch(function (e) { return { out: (LANG === 'zh' ? '加解密失败：' : 'Error: ') + (e && e.message || e) }; }); },
    rsa: function () { return rsaRun(val('bits')).then(function (s) { return { out: s }; }).catch(function (e) { return { out: (LANG === 'zh' ? '生成失败：' : 'Error: ') + (e && e.message || e) }; }); },
    hmac: function () { var data = val('data'), key = val('key') || 'secret'; if (!data) return { out: LANG === 'zh' ? '请输入消息' : 'Enter a message' }; return hmacRun(data, key, val('algo')).then(function (s) { return { out: s }; }).catch(function (e) { return { out: (LANG === 'zh' ? '计算失败：' : 'Error: ') + (e && e.message || e) }; }); },
    totp: function () { return totpRun(val('secret'), parseInt(val('digits'), 10) || 6, parseInt(val('step'), 10) || 30).then(function (s) { return { out: s }; }).catch(function (e) { return { out: (LANG === 'zh' ? '生成失败：' : 'Error: ') + (e && e.message || e) }; }); },
    bip39: function () { return bip39Run(parseInt(val('words'), 10) || 12).then(function (s) { return { out: s }; }).catch(function (e) { return { out: (LANG === 'zh' ? '生成失败：' : 'Error: ') + (e && e.message || e) }; }); },
    cron: function () { var min = val('min') || '*', hour = val('hour') || '*', dom = val('dom') || '*', mon = val('mon') || '*', dow = val('dow') || '*'; var expr = min + ' ' + hour + ' ' + dom + ' ' + mon + ' ' + dow; return { out: expr + '\n' + cronDesc(min, hour, dom, mon, dow) }; },
    mac: function () { var n = clamp(parseInt(val('n'), 10) || 5, 1, 50), fmt = val('fmt'), cs = val('cs') === 'lower' ? 'abcdef0123456789' : 'ABCDEF0123456789'; var out = []; function hex() { return cs[Math.floor(Math.random() * 16)] + cs[Math.floor(Math.random() * 16)]; } for (var i = 0; i < n; i++) { var p = []; for (var j = 0; j < 6; j++) p.push(hex()); out.push(fmt === 'plain' ? p.join('') : p.join(fmt === 'dash' ? '-' : ':')); } return { out: out.join('\n') }; },
    ulid: function () { var n = clamp(parseInt(val('n'), 10) || 5, 1, 50), out = []; function ulid() { var A = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; function enc(num, len) { var s = ''; for (var i = 0; i < len; i++) { s = A[num & 31] + s; num = Math.floor(num / 32); } return s; } var ts = Date.now(); var hi = Math.floor(ts / 4294967296), lo = ts % 4294967296; var timePart = enc(hi, 2) + enc(lo, 8); var rand = ''; for (var i = 0; i < 16; i++) rand += A[Math.floor(Math.random() * 32)]; return timePart + rand; } for (var i = 0; i < n; i++) out.push(ulid()); return { out: out.join('\n') }; },
    port: function () { var mn = parseInt(val('min'), 10) || 1024, mx = parseInt(val('max'), 10) || 65535; if (mx < mn) { var t = mn; mn = mx; mx = t; } var p = Math.floor(Math.random() * (mx - mn + 1)) + mn; return { out: String(p) }; },
    svgph: function () { var w = parseInt(val('w'), 10) || 800, h = parseInt(val('h'), 10) || 400, txt = val('txt') || (w + ' × ' + h), bg = val('bg') || '#3e7c46', fg = val('fg') || '#ffffff'; var fs = Math.max(12, Math.floor(Math.min(w, h) / 10)); var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '">\n  <rect width="100%" height="100%" fill="' + bg + '"/>\n  <text x="50%" y="50%" fill="' + fg + '" font-size="' + fs + '" font-family="sans-serif" text-anchor="middle" dominant-baseline="middle">' + esc(txt) + '</text>\n</svg>'; return { out: svg }; },
    uuidv7: function () { var n = clamp(parseInt(val('count'), 10) || 1, 1, 100), out = []; function u7() { var t = Date.now().toString(16); while (t.length < 12) t = '0' + t; var r = ''; for (var i = 0; i < 16; i++) r += (Math.floor(Math.random() * 16)).toString(16); var v = (Math.floor(Math.random() * 4) + 8).toString(16); return t.slice(0, 8) + '-' + t.slice(8, 12) + '-7' + r.slice(0, 3) + '-' + v + r.slice(3, 7) + '-' + r.slice(7, 19); } for (var i = 0; i < n; i++) out.push(u7()); return { out: out.join('\n') }; },
    ua: function () { var browser = val('browser') || 'chrome', os = val('os') || 'win', n = clamp(parseInt(val('count'), 10) || 1, 1, 100), out = []; var cv = 120 + Math.floor(Math.random() * 30), fv = 110 + Math.floor(Math.random() * 20); function ua() { var plat = { win: 'Windows NT 10.0; Win64; x64', mac: 'Macintosh; Intel Mac OS X 10_15_7', linux: 'X11; Linux x86_64', android: 'Linux; Android 13; SM-G991B', ios: 'iPhone; CPU iPhone OS 16_0 like Mac OS X' }[os] || 'Windows NT 10.0; Win64; x64'; var eng = ' AppleWebKit/537.36 (KHTML, like Gecko) '; var tail = { chrome: 'Chrome/' + cv + '.0.' + (1000 + Math.floor(Math.random() * 9000)) + '.0 Safari/537.36', firefox: 'Gecko/20100101 Firefox/' + fv + '.0', safari: 'Version/16.0 Safari/605.1.15', edge: 'Chrome/' + cv + '.0.' + (1000 + Math.floor(Math.random() * 9000)) + '.0 Safari/537.36 Edg/' + cv + '.0', opera: 'Chrome/' + cv + '.0.' + (1000 + Math.floor(Math.random() * 9000)) + '.0 Safari/537.36 OPR/' + fv + '.0' }[browser] || 'Chrome/' + cv + '.0.0.0 Safari/537.36'; var pre = (os === 'ios') ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)' : (os === 'android') ? 'Mozilla/5.0 (Linux; Android 13; SM-G991B)' : 'Mozilla/5.0 (' + plat + ')'; return pre + eng + tail; } for (var i = 0; i < n; i++) out.push(ua()); return { out: out.join('\n') }; },
    gitignore: function () { var stack = val('stack') || 'node'; var TPL = { node: '# Node\nnode_modules/\ndist/\n.npm\n*.log\n.env\n.DS_Store\ncoverage/\n.next/', python: '# Python\n__pycache__/\n*.py[cod]\n.venv/\nvenv/\n*.egg-info/\n.pytest_cache/\n.env\ndist/\nbuild/', go: '# Go\n/bin/\n*.exe\n*.test\nvendor/\n.env', java: '# Java\n*.class\n*.jar\n/target/\n.idea/\n*.iml\n.gradle/\nbuild/', rust: '# Rust\ntarget/\nCargo.lock\n**/*.rs.bk\n', php: '# PHP\n/vendor/\ncomposer.phar\n.phpunit.result.cache\n.env\n', cpp: '# C++\n*.o\n*.obj\n*.exe\nbuild/\ncmake-build-*/\n', ruby: '# Ruby\n/.bundle/\n/vendor/bundle\n*.gem\n.ruby-version\nlog/\ntmp/', unity: '# Unity\n[Ll]ibrary/\n[Tt]emp/\n[Oo]bj/\n[Bb]uild/\n*.csproj\n*.sln\n.idea/', macos: '# macOS\n.DS_Store\n.AppleDouble\n.AppleUpstream\n.Spotlight-V100\n.fseventsd\n', windows: '# Windows\nThumbs.db\nDesktop.ini\n$RECYCLE.BIN/\n*.lnk\n', visualstudio: '# Visual Studio\n.vs/\n*.user\n*.suo\nbin/\nobj/\n*.aps\n*.opensdf\n' }; return { out: '# .gitignore (' + stack + ')\n' + (TPL[stack] || TPL.node) }; },
    ipinfo: function () { var ip = (val('ip') || '').trim(); if (!ip) return { out: LANG === 'zh' ? '请输入 IP 地址' : 'Enter an IP address' }; return fetch('https://ip-api.com/json/' + encodeURIComponent(ip) + '?fields=status,message,query,country,regionName,city,isp,org,lat,lon,timezone').then(function (r) { return r.json(); }).then(function (j) { if (j.status === 'fail') return { out: (LANG === 'zh' ? '查询失败：' : 'Lookup failed: ') + (j.message || 'unknown') }; return { out: ['IP: ' + j.query, 'Country: ' + (j.country || ''), 'Region: ' + (j.regionName || ''), 'City: ' + (j.city || ''), 'ISP: ' + (j.isp || ''), 'Org: ' + (j.org || ''), 'Coords: ' + (j.lat != null ? j.lat : '') + ', ' + (j.lon != null ? j.lon : ''), 'Timezone: ' + (j.timezone || '')].join('\n') }; }).catch(function (e) { return { out: (LANG === 'zh' ? '请求失败：' : 'Request failed: ') + (e && e.message || e) }; }); },
    dns: function () { var domain = (val('domain') || '').trim(); var type = val('type') || 'A'; if (!domain) return { out: LANG === 'zh' ? '请输入域名' : 'Enter a domain' }; return fetch('https://dns.google/resolve?name=' + encodeURIComponent(domain) + '&type=' + encodeURIComponent(type)).then(function (r) { return r.json(); }).then(function (j) { if (!j.Answer) return { out: (LANG === 'zh' ? '无记录' : 'No records') + (j.Status ? ' (Status ' + j.Status + ')' : '') }; return { out: j.Answer.map(function (a) { return (a.name || '') + '  ' + (a.TTL || '') + '  type:' + (a.type || '') + '  ' + a.data; }).join('\n') }; }).catch(function (e) { return { out: (LANG === 'zh' ? '请求失败：' : 'Request failed: ') + (e && e.message || e) }; }); },

    // =================== 第三批新增（2026-08-29） ===================
    fib: function () { var n = clamp(parseInt(val('n'), 10) || 20, 1, 200); var a = [], x = 0, y = 1; for (var i = 0; i < n; i++) { a.push(x); var t = x + y; x = y; y = t; } return { out: a.join(', ') }; },
    randomIp: function () { var v = val('ver') || '4', n = clamp(parseInt(val('count'), 10) || 5, 1, 50), out = []; for (var i = 0; i < n; i++) { if (v === '6') { var g = []; for (var j = 0; j < 8; j++) g.push((Math.floor(Math.random() * 0x10000)).toString(16)); out.push(g.join(':')); } else { out.push([rndNum(1, 223), rndNum(0, 255), rndNum(0, 255), rndNum(1, 254)].join('.')); } } return { out: out.join('\n') }; function rndNum(a, b) { return Math.floor(Math.random() * (b - a + 1)) + a; } },
    maze: function () { var rows = clamp(parseInt(val('rows'), 10) || 15, 5, 60), cols = clamp(parseInt(val('cols'), 10) || 25, 5, 80); var grid = []; for (var i = 0; i < rows; i++) { grid[i] = []; for (var j = 0; j < cols; j++) grid[i][j] = 1; } function carve(r, c) { grid[r][c] = 0; var dirs = [[0, 2], [0, -2], [2, 0], [-2, 0]].sort(function () { return Math.random() - .5; }); for (var d = 0; d < dirs.length; d++) { var nr = r + dirs[d][0], nc = c + dirs[d][1]; if (nr >= 0 && nr < rows && nc >= 0 && nc < cols && grid[nr][nc] === 1) { grid[r + dirs[d][0] / 2][c + dirs[d][1] / 2] = 0; carve(nr, nc); } } } carve(0, 0); grid[rows - 1][cols - 1] = 0; return { out: grid.map(function (row) { return row.map(function (c) { return c ? '██' : '  '; }).join(''); }).join('\n') }; },
    sudoku: function () { var base = [1, 2, 3, 4, 5, 6, 7, 8, 9]; function shuffle(a) { for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = a[i]; a[i] = a[j]; a[j] = t; } return a; } var rows = []; for (var b = 0; b < 9; b++) { var line = shuffle(base.slice()); var r = []; for (var i = 0; i < 9; i++) { var num = line[((b * 3 + Math.floor(b / 3) + i) % 9)]; r.push(num); } rows.push(r); } var difficulty = val('level') || 'easy'; var blanks = difficulty === 'hard' ? 55 : difficulty === 'medium' ? 42 : 30; var filled = 0; while (filled < 81 - blanks) { var rr = Math.floor(Math.random() * 9), cc = Math.floor(Math.random() * 9); if (rows[rr][cc] !== 0) { rows[rr][cc] = 0; filled++; } } for (var rr2 = 0; rr2 < 9; rr2++) { for (var cc2 = 0; cc2 < 9; cc2++) { if (rows[rr2][cc2] === 0) rows[rr2][cc2] = '.'; } } return { out: rows.map(function (row) { return row.join(' '); }).join('\n') }; },
    shield: function () { var label = val('label') || 'build'; var value = val('value') || 'passing'; var color = (val('color') || 'brightgreen').toLowerCase(); var COLORS = { brightgreen: '#4c1', green: '#97CA00', yellowgreen: '#a4a61d', yellow: '#dfb317', orange: '#fe7d37', red: '#e05d44', blue: '#007ec6', lightgrey: '#9f9f9f', blueviolet: '#8f4fbb', critical: '#e05d44', success: '#4c1', important: '#fe7d37' }; var c = COLORS[color] || '#4c1'; function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); } var lw = 8 + label.length * 7, vw = 10 + value.length * 7; var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + (lw + vw) + '" height="20">\n  <linearGradient id="s" x2="0" y2="100%">\n    <stop offset="0" stop-color="#bbb" stop-opacity=".1"/>\n    <stop offset="1" stop-opacity=".1"/>\n  </linearGradient>\n  <rect width="' + lw + '" height="20" fill="#555"/>\n  <rect x="' + lw + '" width="' + vw + '" height="20" fill="' + c + '"/>\n  <rect width="' + (lw + vw) + '" height="20" fill="url(#s)"/>\n  <g fill="#fff" font-family="DejaVu Sans,Verdana,Geneva,sans-serif" font-size="11">\n    <text x="' + (lw / 2) + '" y="14" text-anchor="middle">' + esc(label) + '</text>\n    <text x="' + (lw + vw / 2) + '" y="14" text-anchor="middle">' + esc(value) + '</text>\n  </g>\n</svg>'; return { out: svg }; },
    cssBubble: function () { var bg = val('bg') || '#3e7c46', fg = val('fg') || '#ffffff', br = clamp(parseInt(val('radius'), 10) || 12, 0, 40), pos = val('arrow') || 'bottom'; var side = pos === 'left' ? 'right' : pos === 'right' ? 'left' : pos === 'top' ? 'bottom' : 'top'; var css = '.speech-bubble {\n  position: relative;\n  background: ' + bg + ';\n  color: ' + fg + ';\n  border-radius: ' + br + 'px;\n  padding: 16px 20px;\n  display: inline-block;\n}\n\n.speech-bubble::after {\n  content: "";\n  position: absolute;\n  ' + side + ': -12px;\n  left: 24px;\n  border-width: 12px;\n  border-style: solid;\n  border-color: transparent;\n  border-' + side + '-color: ' + bg + ';\n}'; return { out: css }; },
    cssTriangle: function () { var dir = val('dir') || 'down', size = clamp(parseInt(val('size'), 10) || 24, 4, 200), color = val('color') || '#3e7c46'; var w = size + 'px', h = size + 'px'; var css = '.triangle {\n  width: 0;\n  height: 0;\n'; if (dir === 'up') css += '  border-left: ' + (size / 2) + 'px solid transparent;\n  border-right: ' + (size / 2) + 'px solid transparent;\n  border-bottom: ' + h + ' solid ' + color + ';\n'; else if (dir === 'down') css += '  border-left: ' + (size / 2) + 'px solid transparent;\n  border-right: ' + (size / 2) + 'px solid transparent;\n  border-top: ' + h + ' solid ' + color + ';\n'; else if (dir === 'left') css += '  border-top: ' + (size / 2) + 'px solid transparent;\n  border-bottom: ' + (size / 2) + 'px solid transparent;\n  border-right: ' + w + ' solid ' + color + ';\n'; else css += '  border-top: ' + (size / 2) + 'px solid transparent;\n  border-bottom: ' + (size / 2) + 'px solid transparent;\n  border-left: ' + w + ' solid ' + color + ';\n'; return { out: css + '}' }; },
    pbkdf2: function () { var pw = val('pw') || '', salt = val('salt') || 'salt', iter = clamp(parseInt(val('iter'), 10) || 10000, 1000, 1000000), len = clamp(parseInt(val('len'), 10) || 32, 16, 64); if (!pw) return { out: LANG === 'zh' ? '请输入密码' : 'Enter a password' }; return crypto.subtle.importKey('raw', new TextEncoder().encode(pw), 'PBKDF2', false, ['deriveBits']).then(function (k) { return crypto.subtle.deriveBits({ name: 'PBKDF2', salt: new TextEncoder().encode(salt), iterations: iter, hash: 'SHA-256' }, k, len * 8); }).then(function (buf) { var a = new Uint8Array(buf), hex = ''; for (var i = 0; i < a.length; i++) hex += ('0' + a[i].toString(16)).slice(-2); return { out: hex.toUpperCase() }; }).catch(function (e) { return { out: (LANG === 'zh' ? '派生失败：' : 'Derive error: ') + (e && e.message || e) }; }); },
    solve24: function () { var n1 = val('n1'), n2 = val('n2'), n3 = val('n3'), n4 = val('n4'); var nums = [parseFloat(n1), parseFloat(n2), parseFloat(n3), parseFloat(n4)]; if (nums.some(function (n) { return isNaN(n); })) return { out: LANG === 'zh' ? '请输入 4 个数字' : 'Enter 4 numbers' }; var ops = ['+', '-', '*', '/']; function calc(a, b, op) { if (op === '+') return a + b; if (op === '-') return a - b; if (op === '*') return a * b; if (op === '/') return b === 0 ? null : a / b; return null; } function perm(a) { if (a.length <= 1) return [a]; var out = []; for (var i = 0; i < a.length; i++) { var rest = a.slice(0, i).concat(a.slice(i + 1)); perm(rest).forEach(function (p) { out.push([a[i]].concat(p)); }); } return out; } var found = null; outer: for (var p = 0; p < perm(nums).length && !found; p++) { var arr = perm(nums)[p]; for (var o1 = 0; o1 < 4; o1++) { for (var o2 = 0; o2 < 4; o2++) { for (var o3 = 0; o3 < 4; o3++) { var r1 = calc(arr[0], arr[1], ops[o1]); if (r1 === null) continue; var r2 = calc(r1, arr[2], ops[o2]); if (r2 === null) continue; var r3 = calc(r2, arr[3], ops[o3]); if (r3 !== null && Math.abs(r3 - 24) < 1e-6) { found = '(((' + arr[0] + ' ' + ops[o1] + ' ' + arr[1] + ') ' + ops[o2] + ' ' + arr[2] + ') ' + ops[o3] + ' ' + arr[3] + ') = 24'; break outer; } var r4 = calc(arr[0], arr[1], ops[o1]); if (r4 === null) continue; var r5 = calc(arr[2], arr[3], ops[o2]); if (r5 === null) continue; var r6 = calc(r4, r5, ops[o3]); if (r6 !== null && Math.abs(r6 - 24) < 1e-6) { found = '(' + arr[0] + ' ' + ops[o1] + ' ' + arr[1] + ') ' + ops[o3] + ' (' + arr[2] + ' ' + ops[o2] + ' ' + arr[3] + ') = 24'; break outer; } } } } } return { out: found || (LANG === 'zh' ? '无解' : 'No solution found') }; },

    // =================== 第四批新增（2026-08-29） ===================
    randStr: function () { var len = clamp(parseInt(val('len'), 10) || 12, 1, 200), n = clamp(parseInt(val('count'), 10) || 5, 1, 50), mode = val('mode') || 'mix'; var pools = { num: '0123456789', lower: 'abcdefghijklmnopqrstuvwxyz', upper: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', alpha: 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', hex: '0123456789abcdef', mix: '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ' }; var pool = pools[mode] || pools.mix; var out = []; for (var i = 0; i < n; i++) { var s = ''; for (var j = 0; j < len; j++) s += pool[Math.floor(Math.random() * pool.length)]; out.push(s); } return { out: out.join('\n') }; },
    schulte: function () { var size = clamp(parseInt(val('size'), 10) || 5, 3, 9); var total = size * size; var nums = []; for (var i = 1; i <= total; i++) nums.push(i); for (var j = nums.length - 1; j > 0; j--) { var k = Math.floor(Math.random() * (j + 1)); var t = nums[j]; nums[j] = nums[k]; nums[k] = t; } var grid = []; for (var r = 0; r < size; r++) grid.push(nums.slice(r * size, r * size + size).map(function (x) { return String(x).padStart(2, ' '); }).join(' ')); return { out: grid.join('\n') }; },
    htpasswd: function () { var user = val('user') || 'admin', pw = val('pw') || ''; if (!pw) return { out: LANG === 'zh' ? '请输入密码' : 'Enter a password' }; if (typeof dcodeIO === 'undefined' || !dcodeIO.bcrypt) return { out: (LANG === 'zh' ? 'bcrypt 库未加载' : 'bcrypt lib not loaded') }; var hash = dcodeIO.bcrypt.hashSync(pw, 10); return { out: user + ':' + hash }; },

    // =================== 第五批新增（2026-08-29 houyue 对照） ===================
    sshfp: function () { var key = (val('key') || '').trim(); if (!key) return { out: LANG === 'zh' ? '粘贴 SSH 公钥' : 'Paste an SSH public key' }; var b64 = (key.match(/^\S+\s+([A-Za-z0-9+/=]+)/) || [])[1]; if (!b64) return { out: (LANG === 'zh' ? '无法解析公钥' : 'Cannot parse key') }; var bin = atob(b64); var bytes = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i); return crypto.subtle.digest('SHA-256', bytes).then(function (buf) { var a = new Uint8Array(buf), hex = [], hx = []; for (var j = 0; j < a.length; j++) { var b = ('0' + a[j].toString(16)).slice(-2); hex.push(b); hx.push(b); } var fp = ''; for (var k = 0; k < hx.length; k++) fp += (k % 2 === 0 ? '' : '') + hx[k]; return { out: (LANG === 'zh' ? 'SHA256 指纹：' : 'SHA256 fingerprint: ') + hex.join(':') }; }).catch(function (e) { return { out: (LANG === 'zh' ? '计算失败：' : 'Error: ') + (e && e.message || e) }; }); },
    passphrase: function () { var n = clamp(parseInt(val('n'), 10) || 4, 3, 12), sep = val('sep') || '-'; var W = ['correct', 'horse', 'battery', 'staple', 'jasmine', 'walnut', 'ember', 'quartz', 'saffron', 'pixel', 'octave', 'willow', 'cobalt', 'dagger', 'lunar', 'mosaic', 'nebula', 'orbit', 'prism', 'ripple', 'sphinx', 'tundra', 'vortex', 'wombat', 'zenith', 'amber', 'birch', 'cedar', 'dune', 'echo', 'falcon', 'glacier', 'harbor', 'iris', 'jaguar', 'kettle', 'linden', 'mango', 'nectar', 'onyx', 'pearl', 'quiver', 'raven', 'sierra', 'temple', 'umbra', 'velvet', 'wander', 'xenon', 'yonder', 'zephyr']; var out = []; for (var i = 0; i < n; i++) out.push(W[Math.floor(Math.random() * W.length)]); return { out: out.join(sep) + (val('num') === '1' ? (sep + Math.floor(Math.random() * 100)) : '') }; },
    persona: function () { var first = ['Alex', 'Sam', 'Jordan', 'Riley', 'Casey', 'Morgan', 'Taylor', 'Robin', 'Avery', 'Quinn', 'Blake', 'Parker'], last = ['Chen', 'Smith', 'Wang', 'Kim', 'Garcia', 'Lee', 'Muller', 'Ivanov', 'Silva', 'Kumar', 'Novak', 'Petrov']; var domains = ['mail.com', 'example.net', 'test.org', 'inbox.com']; function rnd(a) { return a[Math.floor(Math.random() * a.length)]; } var name = rnd(first) + ' ' + rnd(last); var birth = (1970 + Math.floor(Math.random() * 40)) + '-' + String(1 + Math.floor(Math.random() * 12)).padStart(2, '0') + '-' + String(1 + Math.floor(Math.random() * 28)).padStart(2, '0'); return { out: (LANG === 'zh' ? '姓名：' : 'Name: ') + name + '\n' + (LANG === 'zh' ? '邮箱：' : 'Email: ') + name.toLowerCase().replace(/ /g, '.') + Math.floor(Math.random() * 99) + '@' + rnd(domains) + '\n' + (LANG === 'zh' ? '生日：' : 'Birth: ') + birth + '\n' + (LANG === 'zh' ? '电话：' : 'Phone: ') + '+1-' + (100 + Math.floor(Math.random() * 900)) + '-' + (100 + Math.floor(Math.random() * 900)) + '-' + (1000 + Math.floor(Math.random() * 9000)) }; },
    solidColor: function () { var w = clamp(parseInt(val('w'), 10) || 800, 16, 2000), h = clamp(parseInt(val('h'), 10) || 600, 16, 2000), color = (val('color') || '#3e7c46').trim(); var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '"><rect width="100%" height="100%" fill="' + color + '"/></svg>'; return { out: 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svg))) }; },

    // =================== 第六批新增（2026-08-29 liulanqi 对照） ===================
    imgInfo: function () { var f = document.getElementById('file').files[0]; if (!f) return { out: (LANG === 'zh' ? '请选择图片' : 'Choose an image') }; var kb = (f.size / 1024).toFixed(1); var mime = f.type || 'unknown'; return new Promise(function (resolve) { var fr = new FileReader(); fr.onload = function () { var im = new Image(); im.onload = function () { resolve({ out: (LANG === 'zh' ? '文件：' : 'File: ') + f.name + '\n' + (LANG === 'zh' ? '类型：' : 'Type: ') + mime + '\n' + (LANG === 'zh' ? '大小：' : 'Size: ') + kb + ' KB\n' + (LANG === 'zh' ? '尺寸：' : 'Dimensions: ') + im.naturalWidth + ' × ' + im.naturalHeight + ' px' }); }; im.src = fr.result; }; fr.readAsDataURL(f); }); },
    wheelSpin: function () { var opts = (val('opts') || '').split(/[,\n，]/).map(function (x) { return x.trim(); }).filter(Boolean); if (!opts.length) return { out: (LANG === 'zh' ? '请输入选项（逗号分隔）' : 'Enter options (comma separated)') }; var n = parseInt(val('n'), 10) || 1; var picks = []; var pool = opts.slice(); for (var i = 0; i < Math.min(n, pool.length); i++) { var idx = Math.floor(Math.random() * pool.length); picks.push(pool.splice(idx, 1)[0]); } return { out: picks.join('\n') }; },

    // =================== 第七批新增：生成器（2026-09-03） ===================
    creditcard: function () {
      var brand = val('brand') || 'visa', n = clamp(parseInt(val('n'), 10) || 5, 1, 50);
      var PRE = { visa: ['4'], mastercard: ['51', '52', '53', '54', '55'], amex: ['34', '37'], discover: ['6011', '65'], jcb: ['3528', '3538'], unionpay: ['62'] };
      var pres = PRE[brand] || ['4'], len = brand === 'amex' ? 15 : 16, out = [];
      function digits(k) { var s = ''; for (var i = 0; i < k; i++) s += Math.floor(Math.random() * 10); return s; }
      for (var i = 0; i < n; i++) {
        var p = rnd(pres), body = p + digits(len - p.length - 1);
        var card = b9Luhn(body);
        out.push(card + '   (' + brand.toUpperCase() + ', ' + len + ' digits)');
      }
      return { out: out.join('\n') + '\n\n⚠ Test data only — these are not real accounts.' };
    },
    iban: function () {
      var country = String(val('country') || 'DE').toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2) || 'DE';
      var bank = String(val('bank') || '10000000').replace(/\D/g, ''), acct = String(val('account') || '0001234567').replace(/\D/g, '');
      var n = clamp(parseInt(val('n'), 10) || 3, 1, 20), out = [];
      for (var i = 0; i < n; i++) {
        var bban = bank + String(Math.floor(Math.random() * Math.pow(10, acct.length))).padStart(acct.length, '0');
        out.push(country + b9IbanCheck(country + '00' + bban) + bban);
      }
      return { out: out.join('\n') + '\n\n(IBAN check digits computed with ISO 13616 mod-97-10)' };
    },
    commitmsg: function () {
      var type = val('type') || 'feat', scope = String(val('scope') || '').trim(), subject = String(val('subject') || 'add a new feature').trim();
      var body = String(val('body') || '').trim(), breaking = val('breaking') === '1', useEmoji = val('emoji') !== '0', issue = String(val('issue') || '').trim();
      var MAP = { feat: '✨', fix: '🐛', docs: '📝', style: '💄', refactor: '♻️', perf: '⚡️', test: '✅', build: '📦', ci: '👷', chore: '🔧', revert: '⏪' };
      var head = type + (scope ? '(' + scope + ')' : '') + (breaking ? '!' : '') + ': ' + (useEmoji && MAP[type] ? MAP[type] + ' ' : '') + subject;
      var out = head;
      if (body) out += '\n\n' + body;
      if (breaking) out += '\n\nBREAKING CHANGE: ' + (String(val('breakingtext') || 'describe the breaking change').trim());
      if (issue) out += '\n\nCloses #' + issue.replace(/^#/, '');
      return { out: out };
    },
    mockdata: function () {
      var n = clamp(parseInt(val('n'), 10) || 10, 1, 200), fmt = val('fmt') || 'json', cn = val('locale') === 'zh';
      var FIRST = cn ? ['张', '王', '李', '赵', '陈', '刘', '杨', '黄', '周', '吴'] : ['James', 'Mary', 'Robert', 'Patricia', 'John', 'Jennifer', 'Michael', 'Linda', 'David', 'Elizabeth'];
      var LAST = cn ? ['伟', '芳', '娜', '敏', '静', '丽', '强', '磊', '洋', '勇'] : ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez'];
      var CITY = cn ? ['北京', '上海', '广州', '深圳', '成都', '杭州', '西安', '武汉', '昆明', '南京'] : ['New York', 'London', 'Tokyo', 'Paris', 'Berlin', 'Sydney', 'Toronto', 'Singapore', 'Dublin', 'Austin'];
      var CO = cn ? ['苍鹭科技', '青云网络', '明远信息', '禾风数据', '溪谷软件'] : ['Acme Corp', 'Globex', 'Initech', 'Umbrella', 'Soylent', 'Hooli', 'Stark Industries'];
      var rows = [], i;
      for (i = 0; i < n; i++) {
        var f = rnd(FIRST), l = rnd(LAST);
        rows.push({
          id: i + 1,
          name: cn ? f + l : f + ' ' + l,
          email: (cn ? 'user' + (i + 1) : (f + '.' + l).toLowerCase()) + '@' + (cn ? 'example.cn' : 'example.com'),
          phone: cn ? '1' + rnd(['3', '5', '7', '8', '9']) + String(Math.floor(Math.random() * 1e9)).padStart(9, '0') : '+1-' + (100 + Math.floor(Math.random() * 900)) + '-' + (100 + Math.floor(Math.random() * 900)) + '-' + (1000 + Math.floor(Math.random() * 9000)),
          city: rnd(CITY),
          company: rnd(CO),
          age: 18 + Math.floor(Math.random() * 50),
          joined: new Date(Date.now() - Math.floor(Math.random() * 1500) * 86400000).toISOString().slice(0, 10)
        });
      }
      if (fmt === 'csv') {
        var keys = Object.keys(rows[0]), lines = [keys.join(',')];
        rows.forEach(function (r) { lines.push(keys.map(function (k) { return b9CsvCell(r[k], ','); }).join(',')); });
        return { out: lines.join('\n') };
      }
      if (fmt === 'sql') {
        var ks = Object.keys(rows[0]);
        var sql = ['CREATE TABLE mock_data (', ks.map(function (k, j) { return '  ' + k + ' TEXT' + (j === ks.length - 1 ? '' : ','); }).join('\n'), ');', ''];
        rows.forEach(function (r) { sql.push('INSERT INTO mock_data (' + ks.join(', ') + ') VALUES (' + ks.map(function (k) { return "'" + String(r[k]).replace(/'/g, "''") + "'"; }).join(', ') + ');'); });
        return { out: sql.join('\n') };
      }
      return { out: JSON.stringify(rows, null, 2) };
    },
    readme: function () {
      var name = val('name') || 'my-project', desc = val('desc') || 'A short description of the project.';
      var install = val('install') || 'npm install', usage = val('usage') || 'npm start', lic = val('license') || 'MIT', test = val('test') || 'npm test';
      return { out: '# ' + name + '\n\n' + desc + '\n\n## ✨ Features\n\n- Fast and lightweight\n- Zero configuration\n- Well documented\n\n## 📦 Installation\n\n```bash\n' + install + '\n```\n\n## 🚀 Usage\n\n```bash\n' + usage + '\n```\n\n## 🧪 Tests\n\n```bash\n' + test + '\n```\n\n## 🤝 Contributing\n\nPull requests are welcome. For major changes, open an issue first.\n\n## 📄 License\n\n' + lic };
    },
    ghrreadme: function () {
      var user = val('user') || 'your-name', bio = val('bio') || 'Hi there, I am a developer who loves building things.';
      var skills = String(val('skills') || 'JavaScript, TypeScript, Node.js').split(/[,\n]/).map(function (s) { return s.trim(); }).filter(Boolean);
      var theme = val('theme') || 'radical';
      return { out: '<div align="center">\n\n# 👋 Hi, I am @' + user + '\n\n<p>' + bio + '</p>\n\n</div>\n\n## 🛠 Tech stack\n\n' + skills.map(function (s) { return '- **' + s + '**'; }).join('\n') + '\n\n## 📊 GitHub stats\n\n<img height="160" src="https://github-readme-stats.vercel.app/api?username=' + encodeURIComponent(user) + '&show_icons=true&theme=' + theme + '" alt="stats" />\n<img height="160" src="https://github-readme-stats.vercel.app/api/top-langs?username=' + encodeURIComponent(user) + '&layout=compact&theme=' + theme + '" alt="top langs" />\n\n## 📫 How to reach me\n\n- GitHub: [@' + user + '](https://github.com/' + user + ')\n- Email: your-email@example.com\n\n---\n\n<sub>Built with ❤️ and lots of ☕</sub>' };
    },

    uuidv1: function () { return b9UuidV1(); },
    uuidv6: function () { return b9UuidV6(); },

    'gengen': function () { if (DETAIL.genType && GEN_REG[DETAIL.genType]) return GEN_REG[DETAIL.genType](DETAIL); return GEN_REG.template(DETAIL); }
  };
  var VERB = ['build', 'ship', 'learn', 'grow', 'design', 'automate', 'focus', 'create', 'organize', 'optimize'];
  var HASHTAG = ['#buildinpublic', '#devtools', '#productivity', '#a11y', '#opensource'];
  var CAPTION = ['Golden hour vibes ✨', 'Small things, big joy.', 'Today’s little win.', 'Moments worth keeping.'];
  function asciiCalendar(y, m) { var d = new Date(y, m - 1, 1), days = new Date(y, m, 0).getDate(), start = d.getDay(); var head = (LANG === 'zh' ? '日 一 二 三 四 五 六' : 'Su Mo Tu We Th Fr Sa'); var lines = [y + '-' + (m < 10 ? '0' + m : m), head, '']; var row = ''; for (var i = 0; i < start; i++) row += '   '; for (var day = 1; day <= days; day++) { row += (day < 10 ? ' ' + day : day) + ' '; if ((start + day) % 7 === 0) { lines.push(row.trim()); row = ''; } } if (row.trim()) lines.push(row.trim()); return lines.join('\n'); }

  // =================== COLOR ===================
  var COLOR_LEGACY = {};
  function blend(c1, c2, t) { var a = hexToRgb(c1), b = hexToRgb(c2); if (!a || !b) return '#000000'; return rgbToHex(a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t); }
  function monoScale(hex) { var rgb = hexToRgb(hex); if (!rgb) return ['#000', '#fff']; var hsl = rgbToHsl(rgb[0], rgb[1], rgb[2]); var out = []; [0.85, 0.7, 0.55, 0.4, 0.25, 0.12].forEach(function (l) { out.push(rgbToHex.apply(null, hslToRgb(hsl[0], hsl[1], l))); }); return out; }
  function materialScale(hex) { var rgb = hexToRgb(hex); if (!rgb) return ['#000']; var hsl = rgbToHsl(rgb[0], rgb[1], rgb[2]); var names = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900']; var out = []; [0.95, 0.85, 0.72, 0.58, 0.45, 0.32, 0.22, 0.14, 0.08, 0.04].forEach(function (l, i) { out.push(names[i] + ': ' + rgbToHex.apply(null, hslToRgb(hsl[0], hsl[1], l))); }); return out; }
  var COLOR_FUNCS = {
    mix: function () { var r = clamp(parseFloat(val('ratio')) || 50, 0, 100) / 100; var m = blend(val('c1'), val('c2'), r); return { outs: { hex: m, rgb: 'rgb(' + hexToRgb(m).join(', ') + ')' } }; },
    complementary: function () { var c = val('c1'), comp = shiftHue(c, 180); return { outs: { comp: comp, hex: comp } }; },
    analogous: function () { var c = val('c1'); return { outs: { left: shiftHue(c, -30), right: shiftHue(c, 30), hex: c } }; },
    triadic: function () { var c = val('c1'); return { outs: { a: shiftHue(c, 120), b: shiftHue(c, 240), hex: c } }; },
    mono: function () { return { outs: { scale: monoScale(val('c1')).join('\n') } }; },
    material: function () { return { outs: { scale: materialScale(val('c1')).join('\n') } }; },
    blind: function () { var c = val('c1'); return { outs: { scale: [c, shiftHue(c, 60), shiftHue(c, -60), shiftHue(c, 180)].join('\n') } }; }
  };
  // =================== GENERIC RECIPE REGISTRY (WebUtils 批量工具) ===================
  // 安全的算术求值器：仅允许 + - * / % ^、括号、变量、Math 函数。变量来自字段数值（非表达式），表达式来自服务端数据（可信）。
  function safeEval(expr, vars) {
    if (typeof expr !== 'string' || !expr.trim()) return '';
    var src = expr.replace(/[a-zA-Z_]\w*/g, function (m) {
      if (Object.prototype.hasOwnProperty.call(vars, m)) {
        var v = vars[m];
        if (typeof v === 'string' && isNaN(Number(v))) return JSON.stringify(v);
        return '(' + (isNaN(Number(v)) ? 0 : Number(v)) + ')';
      }
      if (m === 'PI') return '(Math.PI)';
      if (m === 'E') return '(Math.E)';
      if (/^(sin|cos|tan|sqrt|pow|abs|min|max|log|log10|round|floor|ceil|exp|atan|asin|acos|tanh|cosh|sinh)$/.test(m)) return 'Math.' + m;
      return m;
    });
    src = src.replace(/Math\.Math\./g, 'Math.');
    if (/[;{}`]/.test(src)) throw new Error('unsafe expr');
    try { return new Function('Math', 'return (' + src + ');')(Math); } catch (e) { return 'ERR'; }
  }
  function fNum(x, d) { var n = parseFloat(x); if (isNaN(n)) return '—'; return d != null ? n.toFixed(d) : String(Math.round(n * 1e6) / 1e6); }
  var T = {
    charCount: function (s) { return String(s.length); },
    charCountNoSpace: function (s) { return String(s.replace(/\s/g, '').length); },
    wordCount: function (s) { return String((s.trim().match(/[A-Za-z0-9\u4e00-\u9fff]+/g) || []).length); },
    lineCount: function (s) { return s === '' ? '0' : String(toLines(s).length); },
    byteSize: function (s) { return String(Buffer && Buffer.byteLength ? Buffer.byteLength(s, 'utf8') : unescape(encodeURIComponent(s)).length); },
    sentenceCount: function (s) { return String((s.match(/[.!?。！？]+/g) || []).length); },
    upper: function (s) { return s.toUpperCase(); },
    lower: function (s) { return s.toLowerCase(); },
    title: function (s) { return s.replace(/\w\S*/g, function (w) { return w.charAt(0).toUpperCase() + w.substr(1).toLowerCase(); }); },
    sentence: function (s) { return s.replace(/(^\s*|[.!?。！？]+\s+)([a-z])/g, function (m, p, c) { return p + c.toUpperCase(); }); },
    camel: function (s) { return s.replace(/[-_\s]+(.)?/g, function (_, c) { return c ? c.toUpperCase() : ''; }).replace(/^(.)/, function (m) { return m.toLowerCase(); }); },
    pascal: function (s) { return s.replace(/(^|[-_\s]+)(.)/g, function (_, __, c) { return c.toUpperCase(); }); },
    snake: function (s) { return s.trim().toLowerCase().replace(/[\s]+/g, '_').replace(/[^a-z0-9_]+/g, ''); },
    kebab: function (s) { return s.trim().toLowerCase().replace(/[\s]+/g, '-').replace(/[^a-z0-9-]+/g, ''); },
    constant: function (s) { return s.trim().toUpperCase().replace(/[\s]+/g, '_').replace(/[^A-Z0-9_]+/g, ''); },
    invert: function (s) { return s.replace(/\w/g, function (c) { return c === c.toUpperCase() ? c.toLowerCase() : c.toUpperCase(); }); },
    reverse: function (s) { return toLines(s).map(function (l) { return l.split('').reverse().join(''); }).join('\n'); },
    reverseLines: function (s) { return toLines(s).reverse().join('\n'); },
    shuffleLines: function (s) { var a = toLines(s); for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = a[i]; a[i] = a[j]; a[j] = t; } return a.join('\n'); },
    trimSpace: function (s) { return s.replace(/[ \t]+/g, ' '); },
    trimLines: function (s) { return toLines(s).map(function (l) { return l.trim(); }).join('\n'); },
    removeEmptyLines: function (s) { return toLines(s).filter(function (l) { return l.trim() !== ''; }).join('\n'); },
    removeDuplicates: function (s) { var seen = {}, out = []; toLines(s).forEach(function (l) { var k = l.trim(); if (!seen[k]) { seen[k] = 1; out.push(l); } }); return out.join('\n'); },
    sortAsc: function (s) { return toLines(s).sort(function (a, b) { return a.localeCompare(b, 'zh'); }).join('\n'); },
    sortDesc: function (s) { return toLines(s).sort(function (a, b) { return b.localeCompare(a, 'zh'); }).join('\n'); },
    sortRandom: function (s) { return T.shuffleLines(s); },
    prefixLines: function (s) { var p = (typeof val === 'function' ? val('pre') : '') || ''; return toLines(s).map(function (l, i) { return p + l; }).join('\n'); },
    suffixLines: function (s) { var p = (typeof val === 'function' ? val('suf') : '') || ''; return toLines(s).map(function (l) { return l + p; }).join('\n'); },
    numberLines: function (s) { var start = parseInt(typeof val === 'function' ? val('start') : '', 10) || 1; return toLines(s).map(function (l, i) { return (start + i) + '. ' + l; }).join('\n'); },
    wrap: function (s) { var w = parseInt(typeof val === 'function' ? val('dlm') : '', 10) || 80; var out = []; toLines(s).forEach(function (l) { for (var i = 0; i < l.length; i += w) out.push(l.substr(i, w)); }); return out.join('\n'); },
    singleSpace: function (s) { return s.replace(/\s+/g, ' ').trim(); },
    compact: function (s) { return toLines(s).map(function (l) { return l.trim(); }).filter(function (l) { return l; }).join(' '); },
    joinLines: function (s) { return toLines(s).join((typeof val === 'function' ? val('dlm') : '') || ' '); },
    findReplace: function (s) { var f = typeof val === 'function' ? val('find') : '', r = typeof val === 'function' ? val('repl') : ''; try { return s.split(f).join(r); } catch (e) { return s; } },
    removeNumbers: function (s) { return s.replace(/[0-9]/g, ''); },
    keepNumbers: function (s) { return (s.match(/[0-9]+/g) || []).join(' '); },
    extractEmails: function (s) { return (s.match(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g) || []).join('\n'); },
    extractUrls: function (s) { return (s.match(/https?:\/\/[^\s"'<>]+/g) || []).join('\n'); },
    stripTags: function (s) { return s.replace(/<[^>]+>/g, ''); },
    htmlToText: function (s) { return s.replace(/<br\s*\/?>/gi, '\n').replace(/<\/(p|div|h[1-6]|li|tr)>/gi, '\n').replace(/<[^>]+>/g, '').replace(/&[a-z]+;/gi, ' ').replace(/\n{2,}/g, '\n').trim(); },
    fullWidth: function (s) { return s.replace(/[!-~]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) + 0xFEE0); }); },
    halfWidth: function (s) { return s.replace(/[！-～]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0xFEE0); }); },
    slugify: function (s) { return s.toString().toLowerCase().trim().replace(/[\s_]+/g, '-').replace(/[^\w\u4e00-\u9fff-]/g, '').replace(/-+/g, '-').replace(/^-|-$/g, ''); },
    b64enc: function (s) { try { return btoa(unescape(encodeURIComponent(s))); } catch (e) { return s; } },
    b64dec: function (s) { try { return decodeURIComponent(escape(atob(s.replace(/\s/g, '')))); } catch (e) { return LANG === 'zh' ? '解码失败（非合法 Base64）' : 'Decode failed'; } },
    urlEnc: function (s) { try { return encodeURIComponent(s); } catch (e) { return s; } },
    urlDec: function (s) { try { return decodeURIComponent(s); } catch (e) { return s; } },
    htmlEnc: function (s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); },
    htmlDec: function (s) { return s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&'); },
    hexEnc: function (s) { var o = ''; for (var i = 0; i < s.length; i++) o += s.charCodeAt(i).toString(16).padStart(2, '0') + ' '; return o.trim(); },
    hexDec: function (s) { try { return s.trim().split(/\s+/).map(function (h) { return String.fromCharCode(parseInt(h, 16)); }).join(''); } catch (e) { return s; } },
    binEnc: function (s) { var o = ''; for (var i = 0; i < s.length; i++) o += s.charCodeAt(i).toString(2).padStart(8, '0') + ' '; return o.trim(); },
    binDec: function (s) { try { return s.trim().split(/\s+/).map(function (b) { return String.fromCharCode(parseInt(b, 2)); }).join(''); } catch (e) { return s; } },
    rot13: function (s) { return s.replace(/[a-zA-Z]/g, function (c) { var b = c <= 'Z' ? 65 : 97; return String.fromCharCode((c.charCodeAt(0) - b + 13) % 26 + b); }); },
    unicodeEsc: function (s) { var o = ''; for (var i = 0; i < s.length; i++) { var cp = s.codePointAt(i); o += '\\u' + ('0000' + cp.toString(16)).slice(-4); if (cp > 0xffff) i++; } return o; },
    unicodeUnesc: function (s) { return s.replace(/\\u([0-9a-fA-F]{4})/g, function (m, h) { return String.fromCharCode(parseInt(h, 16)); }); },
    escapeJS: function (s) { return s.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\t/g, '\\t'); },
    escapeJson: function (s) { return s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\t/g, '\\t').replace(/\r/g, '\\r'); },
    jsonPretty: function (s) { try { return JSON.stringify(JSON.parse(s), null, 2); } catch (e) { return LANG === 'zh' ? 'JSON 解析失败' : 'Invalid JSON'; } },
    jsonMinify: function (s) { try { return JSON.stringify(JSON.parse(s)); } catch (e) { return LANG === 'zh' ? 'JSON 解析失败' : 'Invalid JSON'; } },
    jsonValidate: function (s) { try { JSON.parse(s); return LANG === 'zh' ? '✓ 合法 JSON' : '✓ Valid JSON'; } catch (e) { return (LANG === 'zh' ? '✗ ' : '✗ ') + e.message; } },
    csvToJson: function (s) { try { var L = toLines(s).filter(function (x) { return x.trim(); }); if (!L.length) return '[]'; var h = L[0].split(','); var out = L.slice(1).map(function (r) { var c = r.split(','); var o = {}; h.forEach(function (k, i) { o[k.trim()] = (c[i] || '').trim(); }); return o; }); return JSON.stringify(out, null, 2); } catch (e) { return s; } },
    jsonToCsv: function (s) { try { var a = JSON.parse(s); if (!Array.isArray(a) || !a.length) return ''; var h = Object.keys(a[0]); var rows = [h.join(',')]; a.forEach(function (o) { rows.push(h.map(function (k) { return '"' + String(o[k] == null ? '' : o[k]).replace(/"/g, '""') + '"'; }).join(',')); }); return rows.join('\n'); } catch (e) { return s; } },
    yamlToJson: function (s) { return s; },
    jsonToYaml: function (s) { return s; },
    xmlToJson: function (s) { return s.replace(/</g, '&lt;'); },
    mdToHtml: function (s) { return T.htmlEnc(s); },
    jwtDecode: function (s) { try { var p = s.split('.')[1]; p = p.replace(/-/g, '+').replace(/_/g, '/'); while (p.length % 4) p += '='; return JSON.stringify(JSON.parse(decodeURIComponent(escape(atob(p)))), null, 2); } catch (e) { return s; } },
    timestampToDate: function (s) { var n = parseInt(s.trim(), 10); if (isNaN(n)) return s; if (s.trim().length > 11) n = Math.floor(n / 1000); var d = new Date(n * 1000); return d.toISOString().replace('T', ' ').replace('Z', '') + ' (UTC)'; },
    dateToTimestamp: function (s) { var t = Date.parse(s.trim()); return isNaN(t) ? s : String(Math.floor(t / 1000)); },
    diff: function (s) { var b = typeof val === 'function' ? val('b') : ''; var a = toLines(s), c = toLines(b), out = []; var n = Math.max(a.length, c.length); for (var i = 0; i < n; i++) { var x = a[i] || '', y = c[i] || ''; if (x === y) out.push('  ' + x); else { if (x) out.push('- ' + x); if (y) out.push('+ ' + y); } } return out.join('\n'); },

    // =================== 第三批新增（2026-08-29） ===================
    base32Enc: function (s) { var A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; var b = []; for (var i = 0; i < s.length; i++) b.push(s.charCodeAt(i)); var out = '', bits = 0, val = 0; for (var j = 0; j < b.length; j++) { val = (val << 8) | b[j]; bits += 8; while (bits >= 5) { out += A[(val >>> (bits - 5)) & 31]; bits -= 5; } } if (bits > 0) out += A[(val << (5 - bits)) & 31]; while (out.length % 8) out += '='; return out; },
    base32Dec: function (s) { var A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; var clean = s.toUpperCase().replace(/=+$/, ''); var bits = 0, val = 0, out = []; for (var i = 0; i < clean.length; i++) { var idx = A.indexOf(clean[i]); if (idx < 0) continue; val = (val << 5) | idx; bits += 5; if (bits >= 8) { out.push((val >>> (bits - 8)) & 255); bits -= 8; } } return out.map(function (b) { return String.fromCharCode(b); }).join(''); },
    crc32: function (s) { var table = []; for (var n = 0; n < 256; n++) { var c = n; for (var k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; table[n] = c >>> 0; } var crc = 0xffffffff; for (var i = 0; i < s.length; i++) crc = (crc >>> 8) ^ table[(crc ^ s.charCodeAt(i)) & 0xff]; return ('00000000' + ((crc ^ 0xffffffff) >>> 0).toString(16)).slice(-8).toUpperCase(); },
    cssToSass: function (s) { var out = s.replace(/\{/g, ' {\n  ').replace(/;/g, ';\n  ').replace(/\}/g, '\n}\n'); return out.split('\n').filter(function (l) { return l.trim(); }).join('\n').replace(/\s{2,}/g, ' '); },
    ipv6Expand: function (s) { var t = s.trim(); if (!t || t.indexOf(':') < 0) return t; if (t.indexOf('::') >= 0) { var p = t.split('::'); var l = p[0] ? p[0].split(':') : [], r = p[1] ? p[1].split(':') : []; var miss = 8 - l.length - r.length; for (var i = 0; i < miss; i++) l.push('0'); t = l.concat(r).join(':'); } var g = t.split(':'); for (var j = 0; j < g.length; j++) { if (g[j] === '') g[j] = '0'; g[j] = ('0000' + g[j]).slice(-4); } return g.join(':'); },
    numeronym: function (s) { return s.replace(/[a-zA-Z]{4,}/g, function (w) { return w[0] + (w.length - 2) + w[w.length - 1]; }); },
    safelinkDec: function (s) { var m = s.match(/[?&](?:url|u|q|redirect|destination|target|next)=([^&#]+)/i); if (!m) return s.trim(); try { var v = decodeURIComponent(m[1]); if (/^https?:\/\//i.test(v)) return v; return v; } catch (e) { return s.trim(); } },

    // =================== 第四批新增（2026-08-29 工具站调研） ===================
    adler32: function (s) { var a = 1, b = 0, MOD = 65521; for (var i = 0; i < s.length; i++) { a = (a + s.charCodeAt(i)) % MOD; b = (b + a) % MOD; } return ('00000000' + ((b << 16) | a).toString(16)).slice(-8).toUpperCase(); },
    base58Enc: function (s) { var A = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz'; var bytes = []; for (var i = 0; i < s.length; i++) bytes.push(s.charCodeAt(i)); var zero = 0; while (zero < bytes.length && bytes[zero] === 0) zero++; var digits = [0]; bytes.forEach(function (b) { var carry = b; for (var j = 0; j < digits.length; j++) { carry += digits[j] << 8; digits[j] = carry % 58; carry = (carry / 58) | 0; } while (carry > 0) { digits.push(carry % 58); carry = (carry / 58) | 0; } }); var out = ''; for (var k = 0; k < zero; k++) out += '1'; for (var n = digits.length - 1; n >= 0; n--) out += A[digits[n]]; return out; },
    base58Dec: function (s) { var A = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz'; var clean = s.replace(/\s+/g, ''); var zero = 0; while (zero < clean.length && clean[zero] === '1') zero++; var bytes = [0]; for (var i = 0; i < clean.length; i++) { var idx = A.indexOf(clean[i]); if (idx < 0) return (LANG === 'zh' ? '非法 Base58 字符：' : 'Invalid Base58 char: ') + clean[i]; var carry = idx; for (var j = 0; j < bytes.length; j++) { carry += bytes[j] * 58; bytes[j] = carry & 255; carry = (carry / 256) | 0; } while (carry > 0) { bytes.push(carry & 255); carry = (carry / 256) | 0; } } var out = ''; for (var k = 0; k < zero; k++) out += '\x00'; for (var n = bytes.length - 1; n >= 0; n--) out += String.fromCharCode(bytes[n]); return out; },
    base62Enc: function (s) { var A = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'; var bytes = []; for (var i = 0; i < s.length; i++) bytes.push(s.charCodeAt(i)); var out = ''; var num = 0; bytes.forEach(function (b) { num = num * 256 + b; if (num > 1e15) { out = out + base62Big(num); num = 0; } }); if (num) out = out + base62Big(num); return out; function base62Big(n) { var r = ''; do { r = A[n % 62] + r; n = Math.floor(n / 62); } while (n > 0); return r; } },
    base62Dec: function (s) { var A = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'; var out = '', num = 0; for (var i = 0; i < s.length; i++) { var idx = A.indexOf(s[i]); if (idx < 0) return (LANG === 'zh' ? '非法 Base62 字符：' : 'Invalid Base62 char: ') + s[i]; num = num * 62 + idx; if (num > 1e15) { out += String.fromCharCode(Math.floor(num / 256)) + String.fromCharCode(num % 256); num = 0; } } if (num) out += String.fromCharCode(Math.floor(num / 256)) + String.fromCharCode(num % 256); return out; },
    baconEnc: function (s) { var MAP = { a: 'aaaaa', b: 'aaaab', c: 'aaaba', d: 'aaabb', e: 'aabaa', f: 'aabab', g: 'aabba', h: 'aabbb', i: 'abaaa', j: 'abaab', k: 'ababa', l: 'ababb', m: 'abbaa', n: 'abbab', o: 'abbba', p: 'abbbb', q: 'baaaa', r: 'baaab', s: 'baaba', t: 'baabb', u: 'babaa', v: 'babab', w: 'babba', x: 'babbb', y: 'bbaaa', z: 'bbaab' }; return s.toLowerCase().replace(/[a-z]/g, function (c) { return MAP[c] || c; }); },
    baconDec: function (s) { var MAP = {}; var A = 'abcdefghijklmnopqrstuvwxyz'; for (var i = 0; i < 26; i++) { var v = i.toString(2); while (v.length < 5) v = '0' + v; v = v.replace(/0/g, 'a').replace(/1/g, 'b'); MAP[v] = A[i]; } return s.toLowerCase().replace(/[ab]{5}/g, function (m) { return MAP[m] || m; }); },
    uuEnc: function (s) { var bytes = []; for (var i = 0; i < s.length; i++) bytes.push(s.charCodeAt(i)); var out = []; for (var n = 0; n < bytes.length; n += 45) { var chunk = bytes.slice(n, n + 45); out.push(String.fromCharCode(32 + chunk.length)); for (var j = 0; j < chunk.length; j += 3) { var b1 = chunk[j], b2 = chunk[j + 1] || 0, b3 = chunk[j + 2] || 0; out.push(String.fromCharCode(32 + ((b1 >> 2) & 63)), String.fromCharCode(32 + (((b1 << 4) | (b2 >> 4)) & 63)), String.fromCharCode(32 + (((b2 << 2) | (b3 >> 6)) & 63)), String.fromCharCode(32 + (b3 & 63))); } } out.push('`'); return out.join(''); },
    uuDec: function (s) { var lines = s.split('\n').map(function (l) { return l.trim(); }).filter(function (l) { return l && l !== '`' && l !== 'end'; }); var out = []; lines.forEach(function (line) { var len = (line.charCodeAt(0) - 32) & 63; var body = line.slice(1); var bytes = []; for (var i = 0; i < body.length; i += 4) { var c1 = (body.charCodeAt(i) - 32) & 63, c2 = (body.charCodeAt(i + 1) - 32) & 63, c3 = (body.charCodeAt(i + 2) - 32) & 63, c4 = (body.charCodeAt(i + 3) - 32) & 63; bytes.push((c1 << 2) | (c2 >> 4), ((c2 & 15) << 4) | (c3 >> 2), ((c3 & 3) << 6) | c4); } bytes = bytes.slice(0, len); bytes.forEach(function (b) { out.push(String.fromCharCode(b)); }); }); return out.join(''); },
    fancyChars: function (s) { var CIRC = 'ⓐⓑⓒⓓⓔⓕⓖⓗⓘⓙⓚⓛⓜⓝⓞⓟⓠⓡⓢⓣⓤⓥⓦⓧⓨⓩ'; var SQ = '🄰🄱🄲🄳🄴🄵🄶🄷🄸🄹🄺🄻🄼🄽🄾🄿🅀🅁🅂🅃🅄🅅🅆🅇🅈🅉'; return s.split('').map(function (c) { if (/[a-z]/.test(c)) return CIRC[c.charCodeAt(0) - 97]; if (/[A-Z]/.test(c)) return SQ[c.charCodeAt(0) - 65]; return c; }).join(''); },
    supSub: function (s, C) { var SUP = { '0': '⁰', '1': '¹', '2': '²', '3': '³', '4': '⁴', '5': '⁵', '6': '⁶', '7': '⁷', '8': '⁸', '9': '⁹', '+': '⁺', '-': '⁻', '(': '⁽', ')': '⁾', '=': '⁼', 'n': 'ⁿ' }; var SUB = { '0': '₀', '1': '₁', '2': '₂', '3': '₃', '4': '₄', '5': '₅', '6': '₆', '7': '₇', '8': '₈', '9': '₉', '+': '₊', '-': '₋', '(': '₍', ')': '₎', '=': '₌' }; var mode = (C && C.act) ? C.act() : 'sup'; var M = mode === 'sub' ? SUB : SUP; return s.split('').map(function (c) { return M[c] || c; }).join(''); },
    typesetText: function (s) { return s.replace(/[ \t]+/g, ' ').replace(/\s*\n\s*/g, '\n').replace(/\n{3,}/g, '\n\n').replace(/\u3000+/g, '').trim(); },
    moneyToEnglish: function (s) { var n = parseFloat(s.replace(/[,$\s]/g, '')); if (isNaN(n)) return s; var ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen']; var TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety']; function under100(x) { if (x < 20) return ONES[x]; return TENS[Math.floor(x / 10)] + (x % 10 ? ' ' + ONES[x % 10] : ''); } function under1000(x) { var h = Math.floor(x / 100), r = x % 100; return (h ? ONES[h] + ' Hundred' + (r ? ' ' : '') : '') + (r ? under100(r) : ''); } var whole = Math.floor(n), cents = Math.round((n - whole) * 100); var parts = []; if (whole >= 1e12) { parts.push(under1000(Math.floor(whole / 1e12)) + ' Trillion'); whole %= 1e12; } if (whole >= 1e9) { parts.push(under1000(Math.floor(whole / 1e9)) + ' Billion'); whole %= 1e9; } if (whole >= 1e6) { parts.push(under1000(Math.floor(whole / 1e6)) + ' Million'); whole %= 1e6; } if (whole >= 1000) { parts.push(under1000(Math.floor(whole / 1000)) + ' Thousand'); whole %= 1000; } if (whole > 0 || !parts.length) parts.push(under1000(whole)); var out = parts.join(' '); if (out) out += (cents > 0 ? ' Dollars and ' + under100(cents) + ' Cents' : ' Dollars'); else out = 'Zero Dollars'; return out; },
    weekNumber: function (s) { var d = s && s.trim() ? new Date(s) : new Date(); if (isNaN(d.getTime())) return (LANG === 'zh' ? '无效日期（试试 YYYY-MM-DD）' : 'Invalid date (try YYYY-MM-DD)'); var start = new Date(d.getFullYear(), 0, 1); var dayOfYear = Math.floor((d - start) / 86400000); var week = Math.ceil((dayOfYear + start.getDay() + 1) / 7); var yearDays = (new Date(d.getFullYear(), 11, 31) - new Date(d.getFullYear(), 0, 1)) / 86400000 + 1; var weeksTotal = Math.ceil((yearDays + new Date(d.getFullYear(), 0, 1).getDay() + 1) / 7); return (LANG === 'zh' ? '第 ' : 'Week ') + week + (LANG === 'zh' ? ' 周（全年共 ' : ' of ' + d.getFullYear() + ' (total ') + weeksTotal + (LANG === 'zh' ? ' 周）' : ' weeks)'); },
    idCardInfo: function (s) { var id = (s || '').trim().toUpperCase(); if (!/^\d{17}[\dX]$/.test(id)) return (LANG === 'zh' ? '请输入 18 位身份证号' : 'Enter an 18-digit ID number'); var W = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2]; var C = '10X98765432'; var sum = 0; for (var i = 0; i < 17; i++) sum += parseInt(id[i], 10) * W[i]; if (C[sum % 11] !== id[17]) return (LANG === 'zh' ? '校验位不正确' : 'Invalid check digit'); var birth = id.substr(6, 8); var gender = parseInt(id[16], 10) % 2 ? (LANG === 'zh' ? '男' : 'Male') : (LANG === 'zh' ? '女' : 'Female'); return (LANG === 'zh' ? '出生日期：' : 'Birth: ') + birth.substr(0, 4) + '-' + birth.substr(4, 2) + '-' + birth.substr(6, 2) + '\n' + (LANG === 'zh' ? '性别：' : 'Gender: ') + gender; },
    magicBytesHex: function (s) { var hex = s.replace(/[\s:]/g, '').toLowerCase(); var SIGS = [['ffd8ff', 'JPEG 图片'], ['89504e470d0a1a0a', 'PNG 图片'], ['474946383761', 'GIF87 图片'], ['474946383961', 'GIF89 图片'], ['49492a00', 'TIFF (LE)'], ['4d4d002a', 'TIFF (BE)'], ['424d', 'BMP 图片'], ['25504446', 'PDF 文档'], ['504b0304', 'ZIP / DOCX / XLSX'], ['504b0506', 'ZIP (empty)'], ['52617221', 'RAR 压缩包'], ['377abcaf271c', '7z 压缩包'], ['1f8b', 'GZIP 压缩包'], ['425a68', 'BZIP2 压缩包'], ['fd377a585a00', 'XZ 压缩包'], ['2321', 'Shebang 脚本'], ['7f454c46', 'ELF 可执行'], ['4d5a', 'PE 可执行 (EXE/DLL)'], ['cafebabe', 'Java Class'], ['3c21444f4354595045', 'HTML 文档'], ['3c68746d6c', 'HTML 文档'], ['7b', 'JSON 文本'], ['5b', 'JSON 数组'], ['efbbbf', 'UTF-8 BOM 文本'], ['0000001866747970', 'MP4 视频'], ['1a45dfa3', 'Matroska/WebM 视频'], ['494433', 'MP3 音频'], ['fffefb', 'MP3 音频'], ['4f676753', 'OGG 音频'], ['52494646', 'RIFF/AVI/WAV'], ['000001ba', 'MPEG 视频']]; for (var i = 0; i < SIGS.length; i++) { if (hex.indexOf(SIGS[i][0]) === 0) return SIGS[i][1]; } return (LANG === 'zh' ? '未能识别（试试粘贴更多字节）' : 'Unknown (paste more bytes)'); },

    // =================== 第五批新增（2026-08-29 houyue 对照） ===================
    snowflakeDec: function (s) { var id = s.trim(); if (!/^\d+$/.test(id)) return (LANG === 'zh' ? '请输入数字 ID' : 'Enter a numeric ID'); var n = BigInt(id); var EPOCH = 1288834974657n; var ts = Number((n >> 22n) + EPOCH); var worker = Number((n >> 12n) & 1023n); var seq = Number(n & 4095n); var d = new Date(ts); return (LANG === 'zh' ? '时间戳：' : 'Timestamp: ') + d.toISOString().replace('T', ' ').replace('Z', '') + ' UTC\n' + (LANG === 'zh' ? '机器 ID：' : 'Worker ID: ') + worker + '\n' + (LANG === 'zh' ? '序列号：' : 'Sequence: ') + seq; },
    brailleEnc: function (s) { var BASE = 0x2800; return s.split('').map(function (c) { return String.fromCodePoint(BASE + c.charCodeAt(0)); }).join(''); },
    brailleDec: function (s) { var out = ''; for (var i = 0; i < s.length; i++) { var cp = s.codePointAt(i); if (cp >= 0x2800 && cp < 0x2840) out += String.fromCharCode(cp - 0x2800); else out += s[i]; } return out; },
    ganzhi: function (s) { var y = parseInt(s.trim(), 10); if (isNaN(y) || y < 1) return (LANG === 'zh' ? '请输入年份' : 'Enter a year'); var G = ['甲', '乙', '丙', '丁', '戊', '己', '庚', '辛', '壬', '癸']; var Z = ['子', '丑', '寅', '卯', '辰', '巳', '午', '未', '申', '酉', '戌', '亥']; var A = ['鼠', '牛', '虎', '兔', '龙', '蛇', '马', '羊', '猴', '鸡', '狗', '猪']; var gan = G[(y - 4) % 10], zhi = Z[(y - 4) % 12], animal = A[(y - 4) % 12]; return (LANG === 'zh' ? '天干地支：' : 'Ganzhi: ') + gan + zhi + '\n' + (LANG === 'zh' ? '生肖：' : 'Zodiac: ') + animal + '\n' + (LANG === 'zh' ? '五行：' : 'Element: ') + wuxingOf(gan); function wuxingOf(g) { var W = { '甲': (LANG === 'zh' ? '木' : 'Wood'), '乙': (LANG === 'zh' ? '木' : 'Wood'), '丙': (LANG === 'zh' ? '火' : 'Fire'), '丁': (LANG === 'zh' ? '火' : 'Fire'), '戊': (LANG === 'zh' ? '土' : 'Earth'), '己': (LANG === 'zh' ? '土' : 'Earth'), '庚': (LANG === 'zh' ? '金' : 'Metal'), '辛': (LANG === 'zh' ? '金' : 'Metal'), '壬': (LANG === 'zh' ? '水' : 'Water'), '癸': (LANG === 'zh' ? '水' : 'Water') }; return W[g] || ''; } },
    magnetDecode: function (s) { var m = s.match(/magnet:\?xt=urn:btih:([a-fA-F0-9]{32,40})/); if (!m) return (LANG === 'zh' ? '未找到有效磁力链接（btih）' : 'No valid magnet btih found'); var hash = m[1].toUpperCase(); var dn = (s.match(/&dn=([^&]+)/) || [])[1] || ''; var tr = (s.match(/&tr=([^&]+)/g) || []).map(function (x) { return decodeURIComponent(x.slice(4)); }); var out = (LANG === 'zh' ? 'Info Hash：' : 'Info Hash: ') + hash + '\n' + (LANG === 'zh' ? '名称：' : 'Name: ') + decodeURIComponent(dn) + '\n' + (LANG === 'zh' ? 'Tracker 数：' : 'Trackers: ') + tr.length; return out; },
    magnetEncode: function (s, C) { var hash = (C.fv('hash') || '').trim().toUpperCase(); if (!/^[0-9A-F]{40}$/.test(hash)) return (LANG === 'zh' ? '请输入 40 位 btih 哈希' : 'Enter a 40-char btih hash'); var name = (C.fv('dn') || '').trim(); var tr = (C.fv('tr') || '').trim(); var out = 'magnet:?xt=urn:btih:' + hash; if (name) out += '&dn=' + encodeURIComponent(name); if (tr) out += '&tr=' + encodeURIComponent(tr); return out; },
    receivedParse: function (s) { var lines = s.split('\n').filter(function (l) { return /^Received:/.test(l.trim()); }); if (!lines.length) return (LANG === 'zh' ? '未找到 Received 字段' : 'No Received headers found'); return lines.map(function (l) { var m = l.match(/from\s+(\S+)\s+\(([^)]*)\)\s+by\s+(\S+)\s+with\s+(\S+)\s+id\s+(\S+)\s+for\s+<([^>]+)>/); if (m) return 'via ' + m[3] + ' (' + m[1] + ' ' + m[2].slice(0, 40) + ')'; var m2 = l.match(/from\s+(\S+)\s+by\s+(\S+)\s+with\s+(\S+)/); if (m2) return 'via ' + m2[2] + ' (' + m2[1] + ')'; return l.trim().slice(0, 90); }).join('\n'); },

    // =================== 第六批新增（2026-08-29 liulanqi 对照） ===================
    affineEnc: function (s, C) { var a = parseInt(C.fv('a'), 10), b = parseInt(C.fv('b'), 10); if (isNaN(a) || isNaN(b)) { a = 5; b = 8; } if (a % 2 === 0 || a % 13 === 0) return (LANG === 'zh' ? '乘数 a 必须与 26 互质（奇数且非 13 的倍数）' : 'a must be coprime with 26'); var out = ''; for (var i = 0; i < s.length; i++) { var c = s[i]; if (/[a-zA-Z]/.test(c)) { var base = c <= 'Z' ? 65 : 97; out += String.fromCharCode(base + (((c.charCodeAt(0) - base) * a + b) % 26)); } else out += c; } return out; },
    affineDec: function (s, C) { var a = parseInt(C.fv('a'), 10), b = parseInt(C.fv('b'), 10); if (isNaN(a) || isNaN(b)) { a = 5; b = 8; } var inv = 0; for (var i = 0; i < 26; i++) { if ((a * i) % 26 === 1) { inv = i; break; } } if (!inv) return (LANG === 'zh' ? '乘数 a 无模逆（需与 26 互质）' : 'a has no modular inverse'); var out = ''; for (var j = 0; j < s.length; j++) { var c = s[j]; if (/[a-zA-Z]/.test(c)) { var base = c <= 'Z' ? 65 : 97; var x = (c.charCodeAt(0) - base - b + 26) % 26; out += String.fromCharCode(base + ((inv * x) % 26)); } else out += c; } return out; },
    punycodeEnc: function (s) { var out = ''; for (var i = 0; i < s.length; i++) { var cp = s.codePointAt(i); if (cp < 128) { out += s[i]; } else { out += '&#' + cp + ';'; } } return out; },
    punycodeDec: function (s) { return s.replace(/&#(\d+);/g, function (m, n) { return String.fromCodePoint(parseInt(n, 10)); }); },
    dynastyLookup: function (s) { var y = parseInt(s.trim(), 10); if (isNaN(y) || y < -2000) return (LANG === 'zh' ? '输入公元纪年（如 618）' : 'Enter a year (e.g. 618)'); var D = [['夏', -2070, -1600], ['商', -1600, -1046], ['西周', -1046, -771], ['东周', -770, -256], ['秦', -221, -206], ['西汉', -202, 8], ['新', 9, 25], ['东汉', 25, 220], ['三国', 220, 280], ['西晋', 265, 316], ['东晋', 317, 420], ['南北朝', 420, 589], ['隋', 581, 618], ['唐', 618, 907], ['五代十国', 907, 960], ['北宋', 960, 1127], ['南宋', 1127, 1279], ['元', 1271, 1368], ['明', 1368, 1644], ['清', 1636, 1912]]; var era = y > 0 ? '公元' : '公元前'; for (var i = 0; i < D.length; i++) { if (y >= D[i][1] && y <= D[i][2]) return (LANG === 'zh' ? '朝代：' : 'Dynasty: ') + D[i][0] + '（' + D[i][1] + '~' + D[i][2] + '）'; } if (y >= 1912) return (LANG === 'zh' ? '中华民国/中华人民共和国时期' : 'Modern era (Republic/PRC)'); return (LANG === 'zh' ? '未找到匹配朝代' : 'No dynasty found'); },
    countryCodeLookup: function (s) { var q = (s || '').trim().toLowerCase(); var C = [['中国', 'China', '86', '+86'], ['美国', 'USA', '1', '+1'], ['中国香港', 'HK', '852', '+852'], ['中国澳门', 'Macao', '853', '+853'], ['中国台湾', 'Taiwan', '886', '+886'], ['日本', 'Japan', '81', '+81'], ['韩国', 'Korea', '82', '+82'], ['英国', 'UK', '44', '+44'], ['法国', 'France', '33', '+33'], ['德国', 'Germany', '49', '+49'], ['俄罗斯', 'Russia', '7', '+7'], ['印度', 'India', '91', '+91'], ['新加坡', 'Singapore', '65', '+65'], ['马来西亚', 'Malaysia', '60', '+60'], ['泰国', 'Thailand', '66', '+66'], ['越南', 'Vietnam', '84', '+84'], ['澳大利亚', 'Australia', '61', '+61'], ['加拿大', 'Canada', '1', '+1'], ['意大利', 'Italy', '39', '+39'], ['西班牙', 'Spain', '34', '+34'], ['荷兰', 'Netherlands', '31', '+31'], ['巴西', 'Brazil', '55', '+55'], ['墨西哥', 'Mexico', '52', '+52'], ['印度尼西亚', 'Indonesia', '62', '+62'], ['菲律宾', 'Philippines', '63', '+63'], ['阿联酋', 'UAE', '971', '+971'], ['沙特', 'Saudi', '966', '+966'], ['土耳其', 'Turkey', '90', '+90'], ['南非', 'South Africa', '27', '+27'], ['埃及', 'Egypt', '20', '+20'], ['新西兰', 'NZ', '64', '+64'], ['瑞士', 'Switzerland', '41', '+41'], ['瑞典', 'Sweden', '46', '+46'], ['波兰', 'Poland', '48', '+48'], ['阿根廷', 'Argentina', '54', '+54'], ['乌克兰', 'Ukraine', '380', '+380'], ['以色列', 'Israel', '972', '+972'], ['尼日利亚', 'Nigeria', '234', '+234'], ['巴基斯坦', 'Pakistan', '92', '+92'], ['孟加拉', 'Bangladesh', '880', '+880']]; for (var i = 0; i < C.length; i++) { if (C[i][0].toLowerCase().indexOf(q) !== -1 || C[i][1].toLowerCase().indexOf(q) !== -1 || C[i][2].indexOf(q) !== -1) { return C[i][0] + ' / ' + C[i][1] + ' 区号：' + C[i][3]; } } return (LANG === 'zh' ? '未找到（试试中文名或英文名）' : 'Not found (try CN or EN name)'); },
    bloodTypeCalc: function (s, C) { var fa = C.fv('fa') || 'A', mo = C.fv('mo') || 'B'; var P = { A: ['A', 'O'], B: ['B', 'O'], AB: ['A', 'B'], O: ['O'] }; var f = P[fa] || ['O'], m = P[mo] || ['O']; var poss = {}; f.forEach(function (a) { m.forEach(function (b) { var set = (a + b).split('').sort().join(''); if (set === 'AB' || set === 'BA') poss['AB'] = 1; else poss[set[0]] = 1; }); }); return (LANG === 'zh' ? '父母血型：' : 'Parents: ') + fa + ' × ' + mo + '\n' + (LANG === 'zh' ? '子女可能血型：' : 'Possible children: ') + Object.keys(poss).sort().join(' / '); },
    cidrCalc: function (s, C) { var input = (C.fv('cidr') || s || '').trim(); var m = input.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\/(\d{1,2})$/); if (!m) return (LANG === 'zh' ? '格式：IP/前缀（如 192.168.1.0/24）' : 'Format: IP/prefix (e.g. 192.168.1.0/24)'); var prefix = parseInt(m[5], 10); if (prefix < 0 || prefix > 32) return (LANG === 'zh' ? '前缀需在 0-32' : 'Prefix must be 0-32'); function ipToInt(o) { return ((parseInt(o[1], 10) * 16777216) + (parseInt(o[2], 10) * 65536) + (parseInt(o[3], 10) * 256) + parseInt(o[4], 10)) >>> 0; } function intToIp(n) { return [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join('.'); } var ip = ipToInt(m); var mask = prefix === 0 ? 0 : (0xFFFFFFFF << (32 - prefix)) >>> 0; var net = (ip & mask) >>> 0; var bc = (net | (~mask >>> 0)) >>> 0; var hosts = prefix >= 31 ? (prefix === 32 ? 1 : 2) : Math.pow(2, 32 - prefix) - 2; return (LANG === 'zh' ? '网络地址：' : 'Network: ') + intToIp(net) + '/' + prefix + '\n' + (LANG === 'zh' ? '子网掩码：' : 'Mask: ') + intToIp(mask) + '\n' + (LANG === 'zh' ? '广播地址：' : 'Broadcast: ') + intToIp(bc) + '\n' + (LANG === 'zh' ? '可用主机：' : 'Usable hosts: ') + hosts; }
  };
  var CALC_REG = {
    formula: function (vars, detail) {
      var map = detail.calc || {}; var ids = Object.keys(map); var outs = {};
      var v = {}; for (var k in vars) { if (Object.prototype.hasOwnProperty.call(vars, k)) v[k] = vars[k]; }
      var pass, changed = true;
      for (pass = 0; pass < 5 && changed; pass++) {
        changed = false;
        for (var i = 0; i < ids.length; i++) {
          var id = ids[i]; if (outs[id] !== undefined) continue;
          var r = safeEval(map[id], v);
          if (typeof r === 'number') { outs[id] = fNum(r, detail.calcDp != null ? detail.calcDp : 2); v[id] = r; changed = true; }
          else if (typeof r === 'string' && r !== 'ERR') { outs[id] = r; v[id] = r; changed = true; }
        }
      }
      return { outs: outs };
    }
  };
  var GEN_REG = {
    template: function (detail) {
      var tpl = detail.genTpl || '';
      var out = tpl.replace(/\{\{(\w+)\}\}/g, function (m, k) {
        if (k === 'rand') return Math.random().toString(36).slice(2, 10);
        var v = typeof val === 'function' ? val(k) : '';
        return v || m;
      });
      return { out: out };
    },
    list: function (detail) {
      var items = (detail.genList || []).slice(); var n = clamp(parseInt(typeof val === 'function' ? val('n') : '', 10) || items.length, 1, 500);
      return { out: items.slice(0, n).join('\n') };
    }
  };
  function runTransform(name, txt) { return T[name] ? T[name](txt) : txt; }

  function relLum(hex) { var r = hexToRgb(hex); if (!r) return 0; var a = r.map(function (v) { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }); return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2]; }
  function contrastRatio() { var l1 = relLum(val('c1')), l2 = relLum(val('c2')); var hi = Math.max(l1, l2), lo = Math.min(l1, l2); var ratio = (hi + .05) / (lo + .05); var pass = (ratio >= 4.5 ? 'AA ✓' : ratio >= 3 ? 'AA Large ✓' : '✗ 不达标'); return { outs: { ratio: ratio.toFixed(2) + ':1', pass: pass } }; }

  function setOut(id, v) { var e = $('calc-out-' + id); if (!e) return; if (e.tagName === 'TEXTAREA') e.value = v; else if (e.classList && e.classList.contains('serp-out')) e.innerHTML = v; else e.textContent = v; }
  function bindFields(fn) { (DETAIL.fields || []).forEach(function (f) { var e = $(f.id); if (e) { e.addEventListener('input', fn); e.addEventListener('change', fn); } }); fn(); }

  // =================== DISPATCH ===================
  if (W === 'io') {
    var input = $('ttInput'), output = $('ttOutput');
    if (input && output) {
      function act() { var b = document.querySelector('.text-tool .ctrl-btns button.on'); if (b) return b.dataset.act; return ''; }
      function opt(n) { var el = document.querySelector('[data-opt="' + n + '"]'); return el ? el.checked : false; }
      function fv(id) { return val(id); }
      function run() {
        var txt = input.value, out = txt;
        var r;
        if (IO_LEGACY[SLUG]) r = legacyIO(SLUG, txt, { act: act, opt: opt, fv: fv });
        else if (OP && IO_FUNCS[OP]) r = IO_FUNCS[OP](txt, { act: act, opt: opt, fv: fv });
        else r = txt;
        if (r && typeof r.then === 'function') {
          r.then(function (v) { output.value = v == null ? '' : String(v); })
            .catch(function (e) { output.value = (LANG === 'zh' ? '错误：' : 'Error: ') + ((e && e.message) || e); });
        } else { output.value = r; }
      }
      input.addEventListener('input', run);
      document.querySelectorAll('.text-tool .ctrl-btns button').forEach(function (b) { b.addEventListener('click', function () { document.querySelectorAll('.text-tool .ctrl-btns button').forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); run(); }); });
      document.querySelectorAll('.text-tool .chk input').forEach(function (c) { c.addEventListener('change', run); });
      var ctlIds = ['find', 'repl', 'n', 'pre', 'suf', 'dlm', 'start', 'topn', 'jp'];
      (DETAIL.controls || []).forEach(function (c) { if (c.kind === 'field' && ctlIds.indexOf(c.id) < 0) ctlIds.push(c.id); });
      ctlIds.forEach(function (id) { if ($(id)) $(id).addEventListener('input', run); });
      run();
      var cb = $('ttCopy'), cl = $('ttClear'); if (cb) cb.addEventListener('click', function () { copyText(output.value); }); if (cl) cl.addEventListener('click', function () { input.value = ''; run(); input.focus(); });
    }
  }

  else if (W === 'calc' || W === 'cssgen' || W === 'shadow' || W === 'meta' || W === 'contrast') {
    if (W === 'contrast') {
      bindFields(function () {
        var r = contrastRatio();
        if (r.outs) Object.keys(r.outs).forEach(function (k) { setOut(k, r.outs[k]); });
      });
    } else if (W === 'shadow') {
      bindFields(function () {
        var x = val('x') || '0', y = val('y') || '0', blur = val('blur') || '10', spread = val('spread') || '0', color = val('color') || 'rgba(0,0,0,0.3)', kind = val('kind') || 'outer';
        var ins = kind === 'inner' ? 'inset ' : '';
        var prop = SLUG === 'text-shadow-generator' ? 'text-shadow' : 'box-shadow';
        var code = prop + ': ' + ins + x + 'px ' + y + 'px ' + blur + 'px ' + spread + 'px ' + color + ';';
        var ce = $('calc-out-code'); if (ce) ce.value = code;
      });
    } else if (OP && CALC_FUNCS[OP]) {
      bindFields(function () {
        var r = CALC_FUNCS[OP](val);
        if (r.outs) Object.keys(r.outs).forEach(function (k) { setOut(k, r.outs[k]); });
        if (r.code !== undefined) { var ce = $('calc-out-code'); if (ce) ce.value = r.code; }
      });
    } else if (CALC_LEGACY[SLUG]) {
      legacyCalc();
    }
    var cc = $('calcCopy'); if (cc) cc.addEventListener('click', function () { var ce = $('calc-out-code'); copyText(ce ? ce.value : (DETAIL.out || []).map(function (o) { var e = $('calc-out-' + o.id); return e ? (e.tagName === 'TEXTAREA' ? e.value : e.textContent) : ''; }).join('\n')); });
  }

  else if (W === 'gen') {
    if (OP && GEN_FUNCS[OP]) {
      var gb = $('genBtn'), go = $('genOutput'), gs = $('palSwatches');
      function genRun() { var r = GEN_FUNCS[OP](); if (r && typeof r.then === 'function') { r.then(function (rr) { if (rr && rr.out != null) go.value = rr.out; if (gs && rr && rr.swatches != null) gs.innerHTML = rr.swatches; }).catch(function (e) { go.value = (LANG === 'zh' ? '错误：' : 'Error: ') + (e && e.message || e); }); } else { if (r && r.out != null) go.value = r.out; if (gs && r && r.swatches != null) gs.innerHTML = r.swatches; } }
      bindFields(genRun);
      if (gb) gb.addEventListener('click', genRun);
      var gcl = $('genClear'); if (gcl) gcl.addEventListener('click', function () { go.value = ''; if (gs) gs.innerHTML = ''; });
    } else if (GEN_LEGACY[SLUG]) {
      legacyGen();
    }
  }

  else if (W === 'ai') {
    /* AI 接口工具：登录校验 → 组装 prompt（把花括号包裹的字段 id 占位替换为输入值）→ POST /api/ai → 渲染结果 */
    var ab = $('aiBtn'), ao = $('aiOut'), ast = $('aiStatus'), atip = $('aiLoginTip');
    var aiLogged = document.body.getAttribute('data-logged') === '1';
    var aiLang = document.body.getAttribute('data-lang') || 'zh';
    var AI_MSGS = { zh: { login: '请先登录后使用 AI 工具', empty: '请先填写内容', run: 'AI 生成中…', err: '生成失败：', limit: '本小时 AI 次数已用完，请稍后再试' }, en: { login: 'Please login to use AI tools', empty: 'Please fill in the content first', run: 'AI generating…', err: 'Generation failed: ', limit: 'Hourly AI quota reached, try again later' } };
    var aiTxt = AI_MSGS[aiLang] || AI_MSGS.en;
    function aiBuildPrompt() {
      var tpl = DETAIL.aiPrompt || '';
      var out = tpl.replace(/\{\{(\w+)\}\}/g, function (m, k) {
        var el = $(k); return el ? el.value : '';
      });
      return out;
    }
    function aiRun() {
      if (atip && atip.style.display !== 'none') return;
      var prompt = aiBuildPrompt().trim();
      if (!prompt) { ao.innerHTML = '<p class="ai-msg err">' + aiTxt.empty + '</p>'; return; }
      if (ast) { ast.style.display = 'block'; ast.textContent = aiTxt.run; }
      if (ab) ab.disabled = true;
      fetch('/api/ai', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt: prompt, system: DETAIL.aiSystem || '', max_tokens: DETAIL.maxTokens || 1024 })
      }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); }).then(function (x) {
        if (x.ok && x.j.ok) {
          ao.innerHTML = '<div class="ai-msg ok"><pre>' + esc(x.j.text) + '</pre></div>';
          var c2 = $('aiClear'); if (c2) c2.style.display = '';
        } else if (x.j && x.j.error === 'login') {
          ao.innerHTML = '<p class="ai-msg err"><a href="/' + aiLang + '/login/?next=' + encodeURIComponent(location.pathname) + '">' + aiTxt.login + '</a></p>';
        } else if (x.j && x.j.error === 'limit') {
          ao.innerHTML = '<p class="ai-msg err">' + aiTxt.limit + '</p>';
        } else {
          ao.innerHTML = '<p class="ai-msg err">' + aiTxt.err + ((x.j && x.j.msg) || '') + '</p>';
        }
      }).catch(function (e) { ao.innerHTML = '<p class="ai-msg err">' + aiTxt.err + (e && e.message || e) + '</p>'; })
      .finally(function () { if (ast) ast.style.display = 'none'; if (ab) ab.disabled = false; });
    }
    if (ab) ab.addEventListener('click', aiRun);
    var acl = $('aiClear'); if (acl) acl.addEventListener('click', function () { ao.innerHTML = ''; acl.style.display = 'none'; });
    (DETAIL.fields || []).forEach(function (f) { var el = $(f.id); if (el && f.type !== 'select') { el.addEventListener('keydown', function (e) { if (e.key === 'Enter' && f.type !== 'textarea') aiRun(); }); } });
    if (atip && aiLogged) atip.style.display = 'none';
  }

  else if (W === 'mdimg') {
    /* Markdown → 卡片图片（canvas 绘制，零依赖） */
    var mi = $('mdimgInput'), mb = $('mdimgBtn'), mc = $('mdimgCanvas'), mp = $('mdimgPrev'), ma = $('mdimgA');
    function mdImgRun() {
      var text = (mi.value || '').trim();
      if (!text) { showToast(UI.copy_fail); return; }
      var lines = text.split('\n');
      var W2 = 760, pad = 44, lh = 30, titleH = 0, bodyH = 0;
      var headLines = [], body = [];
      var title = null;
      lines.forEach(function (ln) {
        var t = ln.trim();
        if (t && !title) { title = t.replace(/^#+\s*/, ''); return; }
        body.push(t);
      });
      var font = '"Microsoft YaHei","PingFang SC",sans-serif';
      var ctx = mc.getContext('2d');
      ctx.font = 'bold 26px ' + font;
      var titleLines = title ? wrapText(ctx, title, W2 - pad * 2) : [];
      ctx.font = '15px ' + font;
      var bodyLines = [];
      body.forEach(function (l) { wrapText(ctx, l, W2 - pad * 2).forEach(function (x) { bodyLines.push(x); }); });
      var H = pad * 2 + titleLines.length * 40 + bodyLines.length * lh + 30;
      mc.width = W2; mc.height = H;
      // 背景（茶绿渐变）
      var g = ctx.createLinearGradient(0, 0, 0, H);
      g.addColorStop(0, '#f5f8f2'); g.addColorStop(1, '#eef3e9');
      ctx.fillStyle = g; ctx.fillRect(0, 0, W2, H);
      // 顶部色条
      ctx.fillStyle = '#3e7c46'; ctx.fillRect(0, 0, W2, 8);
      var y = pad;
      ctx.fillStyle = '#1f2e24';
      titleLines.forEach(function (l) { ctx.font = 'bold 24px ' + font; ctx.fillText(l, pad, y); y += 38; });
      y += 6;
      bodyLines.forEach(function (l) {
        ctx.font = '15px ' + font;
        ctx.fillStyle = '#2c3e33';
        if (/^- /.test(l)) { ctx.fillStyle = '#3e7c46'; ctx.fillRect(pad - 14, y - 13, 6, 6); ctx.fillStyle = '#2c3e33'; l = l.replace(/^- /, ''); }
        if (/^>/.test(l)) { ctx.fillStyle = '#6b7f70'; ctx.fillRect(pad - 6, y - 24, 3, lh); }
        ctx.fillText(l, pad, y); y += lh;
      });
      ctx.fillStyle = '#9db8a4'; ctx.font = '12px ' + font;
      ctx.fillText('HeronTool · herontool.com', pad, H - 20);
      // 预览与下载
      mp.innerHTML = '';
      var img = document.createElement('img');
      img.src = mc.toDataURL('image/png');
      img.className = 'img-prev';
      mp.appendChild(img);
      ma.href = mc.toDataURL('image/png');
      var dl = $('mdimgDL'); if (dl) dl.style.display = '';
    }
    function wrapText(ctx, text, maxW) {
      var out = [], cur = '';
      for (var i = 0; i < text.length; i++) {
        cur += text[i];
        if (ctx.measureText(cur).width > maxW) { out.push(cur.slice(0, -1)); cur = text[i]; }
      }
      if (cur) out.push(cur);
      return out;
    }
    if (mb) mb.addEventListener('click', mdImgRun);
    var mic = $('mdimgClear'); if (mic) mic.addEventListener('click', function () { mi.value = ''; mp.innerHTML = ''; var dl = $('mdimgDL'); if (dl) dl.style.display = 'none'; });
  }

  else if (W === 'paint') {
    /* 在线画板 */
    var pv = $('paintCanvas'), pc = $('paintColor'), ps = $('paintSize');
    var pctx = pv ? pv.getContext('2d') : null;
    var drawing = false, erasing = false, lastX = 0, lastY = 0;
    if (pv && pctx) {
      pctx.fillStyle = '#fff'; pctx.fillRect(0, 0, pv.width, pv.height);
      pctx.lineCap = 'round'; pctx.lineJoin = 'round';
      function pos(e) { var r = pv.getBoundingClientRect(); return { x: (e.clientX - r.left) * (pv.width / r.width), y: (e.clientY - r.top) * (pv.height / r.height) }; }
      pv.addEventListener('mousedown', function (e) { drawing = true; var p = pos(e); lastX = p.x; lastY = p.y; });
      window.addEventListener('mousemove', function (e) {
        if (!drawing) return;
        var p = pos(e);
        pctx.strokeStyle = erasing ? '#fff' : pc.value;
        pctx.lineWidth = erasing ? (parseInt(ps.value, 10) * 3) : parseInt(ps.value, 10);
        pctx.beginPath(); pctx.moveTo(lastX, lastY); pctx.lineTo(p.x, p.y); pctx.stroke();
        lastX = p.x; lastY = p.y;
      });
      window.addEventListener('mouseup', function () { drawing = false; });
      pv.addEventListener('touchstart', function (e) { e.preventDefault(); drawing = true; var t = e.touches[0]; var p = pos(t); lastX = p.x; lastY = p.y; }, { passive: false });
      window.addEventListener('touchmove', function (e) { if (!drawing) return; e.preventDefault(); var t = e.touches[0]; var p = pos(t); pctx.strokeStyle = erasing ? '#fff' : pc.value; pctx.lineWidth = erasing ? (parseInt(ps.value, 10) * 3) : parseInt(ps.value, 10); pctx.beginPath(); pctx.moveTo(lastX, lastY); pctx.lineTo(p.x, p.y); pctx.stroke(); lastX = p.x; lastY = p.y; }, { passive: false });
      window.addEventListener('touchend', function () { drawing = false; });
    }
    var pe = $('paintEraser'); if (pe) pe.addEventListener('click', function () { erasing = !erasing; pe.classList.toggle('on', erasing); pe.textContent = erasing ? '✏️ 画笔' : '橡皮'; });
    var pcl = $('paintClear'); if (pcl) pcl.addEventListener('click', function () { pctx.fillStyle = '#fff'; pctx.fillRect(0, 0, pv.width, pv.height); });
    var psv = $('paintSave'); if (psv) psv.addEventListener('click', function () { var a = document.createElement('a'); a.href = pv.toDataURL('image/png'); a.download = 'paint-' + Date.now() + '.png'; a.click(); });
  }

  else if (W === 'notes') {
    /* 在线记事本（localStorage 自动保存） */
    var na = $('notesArea'), ns = $('notesStatus');
    var KEY = 'heron_note_' + LANG;
    if (na) {
      try { na.value = localStorage.getItem(KEY) || ''; } catch (e) {}
      na.addEventListener('input', function () {
        try { localStorage.setItem(KEY, na.value); } catch (e) {}
        if (ns) { ns.textContent = UI.copy_done || '已保存'; setTimeout(function () { ns.textContent = ''; }, 1200); }
      });
      var nsv = $('notesSave'); if (nsv) nsv.addEventListener('click', function () { try { localStorage.setItem(KEY, na.value); } catch (e) {} if (ns) ns.textContent = '✓'; });
      var ncp = $('notesCopy'); if (ncp) ncp.addEventListener('click', function () { copyText(na.value); });
      var ncl = $('notesClear'); if (ncl) ncl.addEventListener('click', function () { if (confirm(LANG === 'zh' ? '确定清空记事本？' : 'Clear notepad?')) { na.value = ''; try { localStorage.removeItem(KEY); } catch (e) {} } });
    }
  }

  else if (W === 'wordcloud') {
    /* 词云生成：每行「词 权重」→ canvas 螺旋布局 */
    var wcCv = $('wcCanvas'), wcStage = $('wcStage');
    function wcRender() {
      var raw = ($('wcInput').value || '').trim();
      if (!raw) return;
      var words = [];
      raw.split('\n').forEach(function (line) {
        var m = line.trim().match(/^(\S+)\s+([\d.]+)$/);
        if (m) words.push({ w: m[1], s: Math.max(8, Math.min(80, parseFloat(m[2]) * 3)) });
      });
      if (!words.length) { wcStage.innerHTML = '<p class="wc-err">' + (LANG === 'zh' ? '格式：每行「词 权重」' : 'Format: word + weight per line') + '</p>'; return; }
      var W = 760, H = 420;
      wcCv.width = W; wcCv.height = H;
      var ctx = wcCv.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
      var PAL = ['#2f5a35', '#3e7c46', '#6a994e', '#a7c957', '#386641', '#5b8e3d', '#8fbc94'];
      var placed = [];
      words.forEach(function (it, i) {
        var size = it.s, x = W / 2, y = H / 2;
        ctx.font = '600 ' + size + 'px "Space Grotesk", "PingFang SC", "Microsoft YaHei", sans-serif';
        var tw = ctx.measureText(it.w).width;
        var fits = false, tries = 0;
        var rad = 4;
        while (!fits && tries < 900) {
          fits = true;
          for (var k = 0; k < placed.length; k++) {
            var p = placed[k];
            if (x < p.x + p.w + 6 && x + tw + 6 > p.x && y < p.y + p.h + 6 && y + size + 6 > p.y) { fits = false; break; }
          }
          if (!fits) {
            rad += 3;
            var a = tries * 0.35;
            x = W / 2 + Math.cos(a) * rad;
            y = H / 2 + Math.sin(a) * rad * 0.7;
            tries++;
          }
        }
        ctx.fillStyle = PAL[i % PAL.length];
        ctx.fillText(it.w, x, y + size * 0.8);
        placed.push({ x: x, y: y, w: tw, h: size });
      });
      $('wcDL').style.display = '';
    }
    var wcB = $('wcBtn'); if (wcB) wcB.addEventListener('click', wcRender);
    var wcC = $('wcClear'); if (wcC) wcC.addEventListener('click', function () { $('wcInput').value = ''; $('wcDL').style.display = 'none'; });
    var wcA = $('wcA'); if (wcA) wcA.addEventListener('click', function () { wcA.href = wcCv.toDataURL('image/png'); });
  }

  else if (W === 'heatmap') {
    /* 矩阵热力图：每行逗号分隔 → 色块 */
    var hmCv = $('hmCanvas'), hmStage = $('hmStage');
    function hmRender() {
      var raw = ($('hmInput').value || '').trim();
      if (!raw) return;
      var rows = [];
      raw.split('\n').forEach(function (line) {
        var nums = line.split(/[,，\s]+/).map(parseFloat).filter(function (n) { return !isNaN(n); });
        if (nums.length) rows.push(nums);
      });
      if (!rows.length) { hmStage.innerHTML = '<p class="wc-err">' + (LANG === 'zh' ? '请输入数字矩阵' : 'Enter a numeric matrix') + '</p>'; return; }
      var cols = Math.max.apply(null, rows.map(function (r) { return r.length; }));
      var min = Math.min.apply(null, rows.reduce(function (a, r) { return a.concat(r); }, []));
      var max = Math.max.apply(null, rows.reduce(function (a, r) { return a.concat(r); }, []));
      var C = 40, R = 30, PAD = 14;
      var W = cols * C + PAD * 2, H = rows.length * R + PAD * 2;
      hmCv.width = W; hmCv.height = H;
      var ctx = hmCv.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
      function heat(v) {
        var t = max === min ? 0.5 : (v - min) / (max - min);
        // 低→高：浅绿→深绿→深青
        var r = Math.round(226 - t * 186), g = Math.round(236 - t * 178), b = Math.round(226 - t * 190);
        return 'rgb(' + r + ',' + g + ',' + b + ')';
      }
      rows.forEach(function (r, i) {
        r.forEach(function (v, j) {
          ctx.fillStyle = heat(v);
          ctx.fillRect(PAD + j * C, PAD + i * R, C - 2, R - 2);
          ctx.fillStyle = '#1f2e24';
          ctx.font = '11px sans-serif';
          ctx.textAlign = 'center';
          ctx.fillText(String(v), PAD + j * C + (C - 2) / 2, PAD + i * R + (R - 2) / 2 + 4);
        });
      });
      $('hmDL').style.display = '';
    }
    var hmB = $('hmBtn'); if (hmB) hmB.addEventListener('click', hmRender);
    var hmC = $('hmClear'); if (hmC) hmC.addEventListener('click', function () { $('hmInput').value = ''; $('hmDL').style.display = 'none'; });
    var hmA = $('hmA'); if (hmA) hmA.addEventListener('click', function () { hmA.href = hmCv.toDataURL('image/png'); });
  }

  else if (W === 'waveform') {
    /* 音频波形：解码 → canvas 采样 */
    var wfCv = $('wfCanvas'), wfStage = $('wfStage'), wfName = $('wfName');
    var wfPick = $('wfPick'), wfFile = $('wfFile');
    if (wfPick) wfPick.addEventListener('click', function () { wfFile.click(); });
    if (wfFile) wfFile.addEventListener('change', function () {
      var f = wfFile.files[0]; if (!f) return;
      wfName.textContent = f.name;
      var fr = new FileReader();
      fr.onload = function () {
        try {
          var actx = new (window.AudioContext || window.webkitAudioContext)();
          actx.decodeAudioData(fr.result, function (buf) {
            var W = 760, H = 220;
            wfCv.width = W; wfCv.height = H;
            var ctx = wfCv.getContext('2d');
            ctx.fillStyle = '#f5f8f2'; ctx.fillRect(0, 0, W, H);
            var ch = buf.getChannelData(0), step = Math.ceil(ch.length / W);
            ctx.fillStyle = '#3e7c46';
            for (var x = 0; x < W; x++) {
              var peak = 0;
              for (var i = x * step; i < (x + 1) * step && i < ch.length; i++) { var a = Math.abs(ch[i]); if (a > peak) peak = a; }
              var h = Math.max(1, peak * H * 0.9);
              ctx.fillRect(x, H / 2 - h / 2, 1, h);
            }
            // 中线
            ctx.strokeStyle = '#c3d4c0'; ctx.beginPath(); ctx.moveTo(0, H / 2); ctx.lineTo(W, H / 2); ctx.stroke();
            $('wfDL').style.display = '';
          }, function () { wfName.textContent = LANG === 'zh' ? '无法解码该音频' : 'Cannot decode audio'; });
        } catch (e) { wfName.textContent = 'Error: ' + e.message; }
      };
      fr.readAsArrayBuffer(f);
    });
    var wfA = $('wfA'); if (wfA) wfA.addEventListener('click', function () { wfA.href = wfCv.toDataURL('image/png'); });
  }

  else if (W === 'gifsplit') {
    /* GIF 抽帧：ImageBitmap 逐帧 → PNG 下载 */
    var gsStage = $('gsStage'), gsName = $('gifName');
    var gifPick = $('gifPick'), gifFile = $('gifFile');
    if (gifPick) gifPick.addEventListener('click', function () { gifFile.click(); });
    if (gifFile) gifFile.addEventListener('change', function () {
      var f = gifFile.files[0]; if (!f) return;
      gsName.textContent = f.name;
      var url = URL.createObjectURL(f);
      var img = new Image();
      img.onload = function () {
        var frames = [];
        var m = f.size; // 粗略：GIF 帧数无法直接取，用 ImageDecoder 检测
        if (window.ImageDecoder && 'ImageDecoder' in window) {
          var dec = new ImageDecoder({ data: f });
          dec.tracks.ready.then(function () {
            return dec.decode({ frameIndex: 0 });
          }).then(function () {
            var count = dec.tracks.selectedTrack.frameCount;
            gsStage.innerHTML = '';
            var done = 0;
            for (var i = 0; i < Math.min(count, 40); i++) {
              (function (idx) {
                dec.decode({ frameIndex: idx }).then(function (res) {
                  var cv = document.createElement('canvas');
                  cv.width = img.naturalWidth; cv.height = img.naturalHeight;
                  cv.getContext('2d').drawImage(res.image, 0, 0);
                  var a = document.createElement('a');
                  a.className = 'gif-frame';
                  a.href = cv.toDataURL('image/png');
                  a.download = 'frame-' + (idx + 1) + '.png';
                  var im = document.createElement('img');
                  im.src = a.href; im.alt = 'frame ' + (idx + 1);
                  a.appendChild(im); gsStage.appendChild(a);
                  done++;
                  if (done >= Math.min(count, 40) && gsName) gsName.textContent = f.name + '（' + done + ' 帧）';
                });
              })(i);
            }
          }).catch(function () { gsFallback(img, url); });
        } else { gsFallback(img, url); }
      };
      img.onerror = function () { gsName.textContent = LANG === 'zh' ? '无法读取该 GIF' : 'Cannot read GIF'; };
      img.src = url;
    });
    function gsFallback(img, url) {
      gsStage.innerHTML = '<p class="wc-err">' + (LANG === 'zh' ? '浏览器不支持抽帧（需 Chrome 94+），仅显示首帧' : 'Frame extraction unsupported (Chrome 94+), showing first frame') + '</p>';
      var cv = document.createElement('canvas');
      cv.width = img.naturalWidth; cv.height = img.naturalHeight;
      cv.getContext('2d').drawImage(img, 0, 0);
      var a = document.createElement('a');
      a.className = 'gif-frame'; a.href = cv.toDataURL('image/png'); a.download = 'frame-1.png';
      a.appendChild(cv); gsStage.appendChild(a);
    }
  }

  else if (W === 'probe') {
    /* 网站检测：POST /api/probe */
    var pbOut = $('probeOut'), pbBtn = $('probeBtn'), pbIn = $('probeInput');
    function pbRun() {
      var val = (pbIn.value || '').trim();
      if (!val) return;
      pbOut.innerHTML = '<div class="ai-msg ok" style="opacity:.7">' + (LANG === 'zh' ? '检测中…' : 'Checking…') + '</div>';
      pbBtn.disabled = true;
      var body = { type: OP, url: val, lang: LANG };
      if (OP === 'bulk') body = { type: 'bulk', urls: val.split(/\n|[,，;；]/).map(function (s) { return s.trim(); }).filter(Boolean), lang: LANG };
      fetch('/api/probe', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (x) {
          if (x.ok && x.j.ok) {
            if (Array.isArray(x.j.result)) {
              var rows = x.j.result.map(function (it) {
                var st = it.status === 200 ? '<span class="pst ok">' + it.status + '</span>' : it.status === 0 ? '<span class="pst bad">✗</span>' : '<span class="pst warn">' + it.status + '</span>';
                return '<div class="pb-row"><span class="pb-host">' + esc(it.host) + '</span>' + st + (it.err ? '<span class="pb-err">' + esc(it.err) + '</span>' : '') + '</div>';
              }).join('');
              pbOut.innerHTML = '<div class="ai-msg ok" style="padding:8px 4px">' + rows + '</div>';
            } else {
              pbOut.innerHTML = '<div class="ai-msg ok"><pre>' + esc(x.j.result) + '</pre></div>';
            }
          } else if (x.j && x.j.error === 'rate') {
            pbOut.innerHTML = '<div class="ai-msg ok">' + (LANG === 'zh' ? '请求过于频繁，请稍后再试' : 'Too many requests, try again later') + '</div>';
          } else {
            pbOut.innerHTML = '<div class="ai-msg ok">' + esc((x.j && x.j.msg) || (LANG === 'zh' ? '检测失败' : 'Check failed')) + '</div>';
          }
        })
        .catch(function (e) { pbOut.innerHTML = '<div class="ai-msg ok">Error: ' + esc(e.message) + '</div>'; })
        .then(function () { pbBtn.disabled = false; });
    }
    if (pbBtn) pbBtn.addEventListener('click', pbRun);
    var pbC = $('probeClear'); if (pbC) pbC.addEventListener('click', function () { pbIn.value = ''; pbOut.innerHTML = ''; });
    if (pbIn) pbIn.addEventListener('keydown', function (e) { if (e.key === 'Enter' && OP !== 'bulk') pbRun(); });
  }

  else if (W === 'hotlist') {
    /* 热榜聚合：tab 切换 + 刷新 */
    var hotTabs = $('hotTabs'), hotPanels = $('hotPanels');
    if (hotTabs && hotPanels) {
      hotTabs.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.hot-tab') : null;
        if (!b) return;
        [].forEach.call(hotTabs.querySelectorAll('.hot-tab'), function (x) { x.classList.remove('on'); });
        b.classList.add('on');
        var idx = b.getAttribute('data-idx');
        [].forEach.call(hotPanels.querySelectorAll('.hot-panel'), function (p) { p.classList.toggle('on', p.getAttribute('data-panel') === idx); });
      });
    }
    var hr = $('hotRefresh');
    if (hr) hr.addEventListener('click', function () {
      hr.disabled = true; hr.textContent = LANG === 'zh' ? '⟳ 刷新中…' : '⟳ Refreshing…';
      fetch('/api/hotlist').then(function (r) { return r.json(); }).then(function (j) {
        if (j.ok && j.boards && j.boards.length) {
          var hh = $('hotUpd'); if (hh) hh.textContent = (UI.updated_at || '更新于') + ' ' + new Date(j.updated).toLocaleTimeString();
          var tabs = '', panels = '';
          j.boards.forEach(function (b, idx) {
            tabs += '<button class="hot-tab' + (idx === 0 ? ' on' : '') + '" data-idx="' + idx + '">' + esc(b.name) + '</button>';
            var its = b.items.map(function (it, i) {
              return '<a class="hot-item" href="' + esc(it.url) + '" target="_blank" rel="nofollow noopener"><span class="hi-rank' + (i < 3 ? ' top' : '') + '">' + esc(it.rank) + '</span><span class="hi-title">' + esc(it.title) + '</span><span class="hi-heat">' + esc(it.heat) + '</span></a>';
            }).join('');
            panels += '<div class="hot-panel' + (idx === 0 ? ' on' : '') + '" data-panel="' + idx + '">' + its + '</div>';
          });
          hotTabs.innerHTML = tabs; hotPanels.innerHTML = panels;
        } else {
          var pe = $('hotPanels'); if (pe) pe.innerHTML = '<p class="hot-err">' + (LANG === 'zh' ? '热榜加载失败' : 'Failed to load') + '</p>';
        }
      }).catch(function () { }).then(function () { hr.disabled = false; hr.textContent = '⟳ ' + (UI.refresh || (LANG === 'zh' ? '刷新' : 'Refresh')); });
    });
  }

  else if (W === 'share') {
    /* 即现分享：大拖拽区 + 选择即上传 + 预览 */
    var shDrop = $('shDrop'), shFile = $('shareFile'), shOut = $('shareOut'), shDropInner = $('shDropInner');
    var ALLOWED = ['html','htm','md','markdown','txt','svg','png','jpg','jpeg','webp','gif'];
    function shHandle(f) {
      if (!f) return;
      var m = f.name.toLowerCase().match(/\.([a-z0-9]+)$/);
      var ext = m ? m[1] : '';
      if (!ALLOWED.includes(ext)) { shDropInner.innerHTML = '<div class="sh-drop-err">' + (LANG === 'zh' ? '不支持的文件类型：' : 'Unsupported type: ') + esc(f.name) + '</div>'; return; }
      if (f.size > 2 * 1024 * 1024) { shDropInner.innerHTML = '<div class="sh-drop-err">' + (LANG === 'zh' ? '文件超过 2MB 限制' : 'File exceeds 2MB') + '</div>'; return; }
      shDropInner.innerHTML = '<div class="sh-drop-loading"><span class="spinner"></span>' + (LANG === 'zh' ? '正在生成链接…' : 'Creating link…') + '</div>';
      shDrop.classList.add('busy');
      var fr = new FileReader();
      fr.onload = function () {
        var b64 = fr.result.split(',')[1] || '';
        fetch('/api/share', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ext: ext === 'htm' ? 'html' : (ext === 'markdown' ? 'md' : ext), data: b64, title: f.name }) })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (x) {
            if (x.ok && x.j.ok) {
              var url = location.origin + x.j.url;
              var prev = '';
              var e2 = ext === 'htm' ? 'html' : (ext === 'markdown' ? 'md' : ext);
              if (['png','jpg','jpeg','webp','gif','svg','ico'].includes(e2)) prev = '<div class="sh-prev"><img src="' + url + 'raw" alt="preview"></div>';
              else if (e2 === 'md' || e2 === 'txt') prev = '<div class="sh-prev sh-prev-txt"><pre>' + esc(f.name) + '</pre></div>';
              shOut.innerHTML = '<div class="sh-done">' +
                '<div class="sh-done-ic">✅</div>' +
                '<div class="sh-done-main">' +
                  '<div class="sh-done-title">' + (LANG === 'zh' ? '分享链接已生成' : 'Share link created') + '</div>' +
                  '<div class="share-link"><a href="' + url + '" target="_blank" rel="noopener">' + url + '</a></div>' +
                  '<div class="sh-done-actions"><button class="btn-copy" id="shareCopy">' + UI.copy + '</button><a class="btn-ghost" href="' + url + '" target="_blank" rel="noopener">' + (LANG === 'zh' ? '打开预览' : 'Open') + '</a><button class="btn-ghost" id="shareAgain">↻ ' + (LANG === 'zh' ? '再传一个' : 'Share another') + '</button></div>' +
                  '<div class="sh-done-meta">' + esc(f.name) + ' · ' + Math.round(f.size / 1024) + ' KB · ' + (UI.share_ttl || '7 天内有效') + '</div>' +
                '</div>' + prev + '</div>';
              var sc = $('shareCopy'); if (sc) sc.addEventListener('click', function () { copyText(url); });
              var sa = $('shareAgain'); if (sa) sa.addEventListener('click', function () { shOut.innerHTML = ''; shDropInner.innerHTML = shDropOrig; shDrop.classList.remove('busy'); });
            } else if (x.j && x.j.error === 'unsafe') { shDropInner.innerHTML = '<div class="sh-drop-err">' + (LANG === 'zh' ? 'HTML/SVG 不允许包含脚本' : 'Inline scripts not allowed') + '</div>'; }
            else if (x.j && x.j.error === 'rate') { shDropInner.innerHTML = '<div class="sh-drop-err">' + (LANG === 'zh' ? '请求过于频繁，请稍后再试' : 'Too many requests') + '</div>'; }
            else { shDropInner.innerHTML = '<div class="sh-drop-err">' + esc((x.j && x.j.msg) || 'Error') + '</div>'; }
            shDrop.classList.remove('busy');
          })
          .catch(function (e) { shDropInner.innerHTML = '<div class="sh-drop-err">Error: ' + esc(e.message) + '</div>'; shDrop.classList.remove('busy'); });
      };
      fr.readAsDataURL(f);
    }
    var shDropOrig = shDropInner ? shDropInner.innerHTML : '';
    if (shDrop) {
      shDrop.addEventListener('click', function (e) { if (e.target.tagName !== 'A') shFile.click(); });
      shDrop.addEventListener('dragover', function (e) { e.preventDefault(); shDrop.classList.add('dragover'); });
      shDrop.addEventListener('dragleave', function () { shDrop.classList.remove('dragover'); });
      shDrop.addEventListener('drop', function (e) {
        e.preventDefault(); shDrop.classList.remove('dragover');
        var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) shHandle(f);
      });
    }
    if (shFile) shFile.addEventListener('change', function () { var f = shFile.files[0]; if (f) shHandle(f); shFile.value = ''; });
  }

  else if (W === 'color') {
    if (OP && COLOR_FUNCS[OP]) {
      bindFields(function () { var r = COLOR_FUNCS[OP](); if (r.outs) Object.keys(r.outs).forEach(function (k) { setOut(k, r.outs[k]); }); });
    } else {
      legacyColor();
    }
  }

  else if (W === 'hash') {
    var hb = $('stCopy');
    function runHash() { var t = val('hashText'), algo = val('algo'); if (!t) { setOut('hashOut', ''); return; } fetch('/api/hash?text=' + encodeURIComponent(t) + '&algo=' + algo).then(function (r) { return r.json(); }).then(function (j) { setOut('hashOut', j.hash || 'error'); }).catch(function () { setOut('hashOut', '请求失败'); }); }
    bindFields(runHash);
    if (hb) hb.addEventListener('click', function () { copyText(val('hashOut')); });
  }

  else if (W === 'timestamp') {
    function runTs() { var v = val('ts').trim(), mode = val('mode'); var out; if (mode === 'from') { var d = new Date(); var n = parseInt(v, 10); if (isNaN(n)) { out = L10N.invalidTs; } else { out = new Date(n * 1000).toLocaleString(); } } else { var m = v.match(/^(\d{4})[-/](\d{2})[-/](\d{2})[ T]?(\d{2})?:?(\d{2})?:?(\d{2})?/); if (m) { out = Math.floor(new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0)).getTime() / 1000); } else { var x = parseInt(v, 10); out = isNaN(x) ? L10N.cannotParse : x; } } setOut('tsResult', String(out)); }
    bindFields(runTs);
    if ($('stCopy')) $('stCopy').addEventListener('click', function () { copyText(val('tsResult')); });
  }

  else if (W === 'jwt') {
    if (OP === 'jwt-encode') {
      function b64urlSign(str) { return btoa(unescape(encodeURIComponent(str))).replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_'); }
      function jwtSignHS256(data, secret) {
        if (!window.crypto || !crypto.subtle) return Promise.reject(new Error(LANG === 'zh' ? '浏览器不支持加密' : 'Crypto unsupported'));
        return crypto.subtle.importKey('raw', new TextEncoder().encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'])
          .then(function (k) { return crypto.subtle.sign('HMAC', k, new TextEncoder().encode(data)); })
          .then(function (buf) { var b = new Uint8Array(buf), s = ''; b.forEach(function (x) { s += String.fromCharCode(x); }); return btoa(s).replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_'); });
      }
      function runJwtEnc() {
        var header = (val('header') || '').trim(), payload = (val('payload') || '').trim(), secret = val('secret') || '';
        if (!header || !payload) { setOut('token', LANG === 'zh' ? '请填写 header 与 payload' : 'Enter header and payload'); return; }
        try { var h = b64urlSign(header), p = b64urlSign(payload); } catch (e) { setOut('token', LANG === 'zh' ? 'JSON 格式错误' : 'Invalid JSON'); return; }
        var data = h + '.' + p;
        jwtSignHS256(data, secret).then(function (sig) { setOut('token', data + '.' + sig); }).catch(function (e) { setOut('token', (LANG === 'zh' ? '签名失败：' : 'Sign failed: ') + e.message); });
      }
      bindFields(runJwtEnc);
      if ($('stCopy')) $('stCopy').addEventListener('click', function () { copyText(val('token')); });
    } else {
      function runJwt() { var tok = val('jwtToken').trim(); var parts = tok.split('.'); if (parts.length < 2) { setOut('jwtOut', LANG === 'zh' ? '无效的 JWT（需要 header.payload.signature）' : 'Invalid JWT'); return; } function dec(s) { try { var b = s.replace(/-/g, '+').replace(/_/g, '/'); while (b.length % 4) b += '='; return JSON.stringify(JSON.parse(decodeURIComponent(escape(atob(b)))), null, 2); } catch (e) { return LANG === 'zh' ? '解码失败' : 'Decode failed'; } } setOut('jwtOut', 'HEADER\n' + dec(parts[0]) + '\n\nPAYLOAD\n' + dec(parts[1])); }
      bindFields(runJwt);
      if ($('stCopy')) $('stCopy').addEventListener('click', function () { copyText(val('jwtOut')); });
    }
  }

  else if (W === 'svg') {
    function runSvg() { var t = val('svgIn'); var o = t.replace(/<!--[\s\S]*?-->/g, '').replace(/>\s+</g, '><').replace(/\s{2,}/g, ' ').trim(); setOut('svgOut', o); }
    bindFields(runSvg);
    if ($('stCopy')) $('stCopy').addEventListener('click', function () { copyText(val('svgOut')); });
  }

  else if (W === 'diff') {
    var dA = $('diffA'), dB = $('diffB'), dOut = $('calc-out-diffOut');
    function runDiff() { if (!dA || !dB || !dOut) return; var a = toLines(dA.value), b = toLines(dB.value); var out = []; var i = 0, j = 0; while (i < a.length && j < b.length) { if (a[i] === b[j]) { out.push('  ' + a[i]); i++; j++; } else if (b.slice(j).indexOf(a[i]) >= 0) { out.push('- ' + b[j]); j++; } else if (a.slice(i).indexOf(b[j]) >= 0) { out.push('+ ' + a[i]); i++; } else { out.push('- ' + b[j]); out.push('+ ' + a[i]); i++; j++; } } while (j < b.length) { out.push('- ' + b[j]); j++; } while (i < a.length) { out.push('+ ' + a[i]); i++; } dOut.value = out.join('\n'); }
    function runJsonDiff() {
      if (!dA || !dB || !dOut) return;
      try {
        var a = JSON.parse(dA.value), b = JSON.parse(dB.value);
        function diffObj(pa, ao, bo, lines) {
          if (ao === null || bo === null || typeof ao !== 'object' || typeof bo !== 'object') {
            if (JSON.stringify(ao) !== JSON.stringify(bo)) { lines.push('- ' + pa + ': ' + JSON.stringify(ao)); lines.push('+ ' + pa + ': ' + JSON.stringify(bo)); }
            return;
          }
          if (Array.isArray(ao) || Array.isArray(bo)) {
            var n = Math.max(ao.length || 0, bo.length || 0);
            for (var i = 0; i < n; i++) { if (i >= (ao.length || 0)) lines.push('+ ' + pa + '[' + i + ']: ' + JSON.stringify(bo[i])); else if (i >= (bo.length || 0)) lines.push('- ' + pa + '[' + i + ']: ' + JSON.stringify(ao[i])); else diffObj(pa + '[' + i + ']', ao[i], bo[i], lines); }
            return;
          }
          var keys = {}; Object.keys(ao).forEach(function (k) { keys[k] = 1; }); Object.keys(bo).forEach(function (k) { keys[k] = 1; });
          Object.keys(keys).forEach(function (k) {
            var np = pa ? pa + '.' + k : k;
            if (!(k in ao)) lines.push('+ ' + np + ': ' + JSON.stringify(bo[k]));
            else if (!(k in bo)) lines.push('- ' + np + ': ' + JSON.stringify(ao[k]));
            else diffObj(np, ao[k], bo[k], lines);
          });
        }
        var lines = [];
        diffObj('', a, b, lines);
        dOut.value = lines.length ? lines.join('\n') : (LANG === 'zh' ? '无差异' : 'No differences');
      } catch (e) { dOut.value = (LANG === 'zh' ? 'JSON 解析失败：' : 'JSON parse error: ') + e.message; }
    }
    if (OP === 'jsondiff') {
      if (dA) dA.addEventListener('input', runJsonDiff); if (dB) dB.addEventListener('input', runJsonDiff); runJsonDiff();
      if ($('diffCopy')) $('diffCopy').addEventListener('click', function () { copyText(dOut.value); });
    } else {
      if (dA) dA.addEventListener('input', runDiff); if (dB) dB.addEventListener('input', runDiff); runDiff();
      if ($('diffCopy')) $('diffCopy').addEventListener('click', function () { copyText(dOut.value); });
    }
  }

  else if (W === 'barcode') {
    var bcBtn = $('bcBtn');
    function runBc() { var txt = val('bcText'), type = val('bcType'), out = $('bcOut'); if (!txt || !window.JsBarcode) { if (out) out.innerHTML = window.JsBarcode ? '' : L10N.bcLib; return; } out.innerHTML = '<svg id="bcSvg"></svg>'; try { JsBarcode('#bcSvg', txt, { format: type, lineColor: '#1f2e24', width: 2, height: 80, displayValue: true }); } catch (e) { out.innerHTML = '<p style="color:#c0392b">' + L10N.bcFail + e.message + '</p>'; } }
    bindFields(runBc);
    if (bcBtn) bcBtn.addEventListener('click', runBc);
  }

  else if (['rotate', 'grayscale', 'watermark', 'border', 'corner', 'overlay', 'collage', 'favicon', 'ascii', 'exif', 'qrread', 'b64file'].indexOf(W) >= 0) {
    var fxFile = $('fxFile'), fxBtn = $('fxBtn'), fxOut = $('fxOut');
    if (fxFile && fxBtn && fxOut) {
      fxBtn.addEventListener('click', function () {
        if (!fxFile.files.length) { showToast(UI.copy_fail); return; }
        var op = (OP === 'flip' && W === 'rotate') ? 'flip' : (OP === 'invert' && W === 'grayscale') ? 'invert' : W;
        if (op === 'b64file') { var frb = new FileReader(); frb.onload = function (e2) { var b64 = e2.target.result; fxOut.innerHTML = '<textarea class="tt-output" readonly style="height:180px">' + esc(b64) + '</textarea><div class="tw-actions"><button class="btn-copy" id="b64cp">' + UI.copy_result + '</button></div>'; var cp = $('b64cp'); if (cp) cp.addEventListener('click', function () { copyText(b64); }); }; frb.readAsDataURL(fxFile.files[0]); return; }
        if (op === 'qrread') { var frq = new FileReader(); frq.onload = function (e3) { var im = new Image(); im.onload = function () { var cv = document.createElement('canvas'); cv.width = im.width; cv.height = im.height; var cx2 = cv.getContext('2d'); cx2.drawImage(im, 0, 0); var id = cx2.getImageData(0, 0, cv.width, cv.height); var code = window.jsQR ? jsQR(id.data, id.width, id.height) : null; fxOut.innerHTML = (code && code.data) ? '<div class="rate-note">✓ ' + esc(code.data) + '</div><div class="tw-actions"><button class="btn-copy" id="qrcp">' + UI.copy_result + '</button></div>' : '<div class="qr-loading">' + (LANG === 'zh' ? '未识别到二维码' : 'No QR code found') + '</div>'; var cp2 = $('qrcp'); if (cp2) cp2.addEventListener('click', function () { copyText(code.data); }); }; im.src = e3.target.result; }; frq.readAsDataURL(fxFile.files[0]); return; }
        if (op === 'collage') { fxCollage(fxFile.files, fxOut); return; }
        var fr = new FileReader(); fr.onload = function () { fxProcess(op, fr.result, fxOut); }; fr.readAsDataURL(fxFile.files[0]);
      });
    }
  }

  else if (W === 'pdfcompress') {
    var pcf = $('pdfcFile'), pcb = $('pdfcBtn'), pco = $('pdfcOut');
    if (pcf && pcb && pco) {
      if (!window.pdfLib) showToast(UI.copy_fail);
      pcb.addEventListener('click', function () {
        if (!pcf.files.length || !window.pdfLib) { showToast(UI.copy_fail); return; }
        pco.innerHTML = '<div class="qr-loading">' + UI.process_hint + '</div>';
        (async function () { try { var q = clamp(parseInt(val('quality'), 10) || 70, 10, 100) / 100; var buf = await pcf.files[0].arrayBuffer(); var doc = await pdfLib.PDFDocument.load(buf); var out = await pdfLib.PDFDocument.create(); var pages = await doc.copyPages(out, doc.getPageIndices()); pages.forEach(function (p) { out.addPage(p); }); var bytes = await out.save({ useObjectStreams: false }); var blob = new Blob([bytes], { type: 'application/pdf' }); var url = URL.createObjectURL(blob); pco.innerHTML = '<div class="rate-note">原始 ' + (pcf.files[0].size / 1024).toFixed(0) + ' KB → ' + (bytes.length / 1024).toFixed(0) + ' KB</div><div class="tw-actions"><a class="btn-copy" href="' + url + '" download="compressed.pdf">' + UI.download + '</a></div>'; } catch (e) { pco.innerHTML = '<div class="qr-loading">' + L10N.pdfFail + '</div>'; } })();
      });
    }
  }

  else if (W === 'filetool') {
    /* 文件批处理：filehash / filemeta / batchrename */
    var ftF = $('ftFiles'), ftG = $('ftGo'), ftO = $('ftOut');
    if (ftF && ftG && ftO) {
      function ftSize(n) { if (n < 1024) return n + ' B'; if (n < 1048576) return (n / 1024).toFixed(1) + ' KB'; if (n < 1073741824) return (n / 1048576).toFixed(2) + ' MB'; return (n / 1073741824).toFixed(2) + ' GB'; }
      function ftTable(head, rows) {
        var h = '<table class="ft-tbl"><thead><tr>' + head.map(function (x) { return '<th>' + esc(x) + '</th>'; }).join('') + '</tr></thead><tbody>';
        rows.forEach(function (r) { h += '<tr>' + r.map(function (c) { return '<td>' + esc(String(c)) + '</td>'; }).join('') + '</tr>'; });
        return h + '</tbody></table>';
      }
      function ftLoading() { ftO.innerHTML = '<div class="qr-loading">' + UI.process_wait + '</div>'; }
      function ftFail(e) { ftO.innerHTML = '<div class="qr-loading">' + L10N.pdfFail + ' ' + String((e && e.message) || e).slice(0, 140) + '</div>'; }
      function ftDl(name, text, mime) {
        var blob = new Blob([text], { type: mime || 'text/plain' });
        return '<a class="btn-ghost" href="' + URL.createObjectURL(blob) + '" download="' + name + '">' + name + '</a> ';
      }
      var FT_OPS = {
        filehash: function (files, f) {
          return (async function () {
            var alg = f('alg') || 'SHA-256', rows = [];
            for (var i = 0; i < files.length; i++) {
              var buf = await files[i].arrayBuffer();
              var dg = await crypto.subtle.digest(alg, buf);
              rows.push([files[i].name, ftSize(files[i].size), b9Hex(new Uint8Array(dg))]);
            }
            return ftTable(['File', 'Size', alg], rows);
          })();
        },
        filemeta: function (files) {
          return (async function () {
            var rows = [], i;
            for (i = 0; i < files.length; i++) {
              var fl = files[i], dim = '';
              if (/^image\//.test(fl.type)) {
                try {
                  dim = await new Promise(function (res) {
                    var fr = new FileReader();
                    fr.onload = function () { var im = new Image(); im.onload = function () { res(im.naturalWidth + ' × ' + im.naturalHeight); }; im.onerror = function () { res(''); }; im.src = fr.result; };
                    fr.onerror = function () { res(''); };
                    fr.readAsDataURL(fl);
                  });
                } catch (e) { dim = ''; }
              }
              var d = new Date(fl.lastModified);
              rows.push([fl.name, fl.type || '-', ftSize(fl.size), dim || '-', d.toISOString().replace('T', ' ').slice(0, 19)]);
            }
            return ftTable(['File', 'MIME', 'Size', 'Dimensions', 'Last modified'], rows);
          })();
        },
        batchrename: function (files, f) {
          var tpl = f('tpl') || '{name}_{n}{ext}', find = f('find') || '', repl = f('repl') || '';
          var kase = f('case') || 'keep', start = parseInt(f('start'), 10) || 1, pad = parseInt(f('pad'), 10) || 0;
          var dstr = new Date().toISOString().slice(0, 10), rows = [], pairs = [];
          files.forEach(function (fl, i) {
            var nm = fl.name, dot = nm.lastIndexOf('.');
            var base = dot > 0 ? nm.slice(0, dot) : nm, ext = dot > 0 ? nm.slice(dot) : '';
            if (find) base = base.split(find).join(repl);
            if (kase === 'lower') base = base.toLowerCase();
            else if (kase === 'upper') base = base.toUpperCase();
            var num = String(start + i); while (num.length < pad) num = '0' + num;
            var out = tpl.replace('{name}', base).replace('{ext}', ext).replace('{n}', num).replace('{i}', String(i + 1)).replace('{date}', dstr);
            rows.push([nm, out]);
            pairs.push([nm, out]);
          });
          var bat = '@echo off\r\nchcp 65001 >nul\r\n' + pairs.map(function (p) { return 'ren "' + p[0] + '" "' + p[1] + '"'; }).join('\r\n') + '\r\n';
          var sh = '#!/bin/sh\n' + pairs.map(function (p) { return 'mv "' + p[0].replace(/"/g, '\\"') + '" "' + p[1].replace(/"/g, '\\"') + '"'; }).join('\n') + '\n';
          return Promise.resolve(ftTable(['Old name', 'New name'], rows) +
            '<div class="rate-note">' + DETAIL.labels.rename_hint + '</div>' +
            '<div class="tw-actions">' + ftDl('rename.bat', bat, 'text/plain') + ftDl('rename.sh', sh, 'text/plain') + '</div>');
        }
      };
      ftG.addEventListener('click', function () {
        var files = Array.prototype.slice.call(ftF.files || []);
        if (!files.length) { showToast(UI.copy_fail); return; }
        ftLoading();
        (async function () {
          try {
            function f(id) { var e = $(id); return e ? e.value : ''; }
            ftO.innerHTML = await FT_OPS[OP](files, f);
          } catch (e) { ftFail(e); }
        })();
      });
    }
  }

  else if (W === 'boximg') {
    /* 图片加框：留白 / 背景色 / 目标比例 */
    var bxF = $('bxFile'), bxG = $('bxGo'), bxO = $('bxOut');
    if (bxF && bxG && bxO) {
      bxG.addEventListener('click', function () {
        if (!bxF.files || !bxF.files[0]) { showToast(UI.copy_fail); return; }
        bxO.innerHTML = '<div class="qr-loading">' + UI.process_wait + '</div>';
        (async function () {
          try {
            function f(id) { var e = $(id); return e ? e.value : ''; }
            var pad = parseInt(f('pad'), 10) || 0, bg = f('bg') || '#ffffff', ratio = f('ratio') || 'keep';
            var fl = bxF.files[0];
            var src = await new Promise(function (res, rej) { var fr = new FileReader(); fr.onload = function () { res(fr.result); }; fr.onerror = rej; fr.readAsDataURL(fl); });
            var img = await new Promise(function (res, rej) { var im = new Image(); im.onload = function () { res(im); }; im.onerror = rej; im.src = src; });
            var iw = img.naturalWidth, ih = img.naturalHeight, cw = iw + pad * 2, ch = ih + pad * 2;
            if (ratio !== 'keep') {
              var p = ratio.split(':'), rw = parseFloat(p[0]), rh = parseFloat(p[1]);
              if (rw > 0 && rh > 0) {
                var target = rw / rh, cur = cw / ch;
                if (cur < target) cw = Math.round(ch * target); else ch = Math.round(cw / target);
              }
            }
            var cv = document.createElement('canvas'); cv.width = cw; cv.height = ch;
            var cx = cv.getContext('2d');
            cx.fillStyle = bg; cx.fillRect(0, 0, cw, ch);
            cx.drawImage(img, Math.round((cw - iw) / 2), Math.round((ch - ih) / 2), iw, ih);
            var url = cv.toDataURL('image/png');
            var name = (fl.name || 'image').replace(/\.[^.]+$/, '') + '-boxed.png';
            bxO.innerHTML = '<img src="' + url + '" class="img-prev" alt=""><div class="rate-note">' + iw + '×' + ih + ' → ' + cw + '×' + ch + '</div><div class="tw-actions"><a class="btn-copy" href="' + url + '" download="' + name + '">' + UI.download + '</a></div>';
          } catch (e) { bxO.innerHTML = '<div class="qr-loading">' + L10N.pdfFail + ' ' + String((e && e.message) || e).slice(0, 140) + '</div>'; }
        })();
      });
    }
  }

  else if (W === 'blurhash') {
    /* BlurHash：读图 → 降采样 → 编码 → 解码预览 */
    var bhF = $('bhFile'), bhG = $('bhGo'), bhO = $('bhOut');
    if (bhF && bhG && bhO) {
      bhG.addEventListener('click', function () {
        if (!bhF.files || !bhF.files[0]) { showToast(UI.copy_fail); return; }
        bhO.innerHTML = '<div class="qr-loading">' + UI.process_wait + '</div>';
        (async function () {
          try {
            function f(id) { var e = $(id); return e ? e.value : ''; }
            var cxn = clamp(parseInt(f('cx'), 10) || 4, 1, 9), cyn = clamp(parseInt(f('cy'), 10) || 3, 1, 9);
            var pw = 32, ph = 32, fl = bhF.files[0];
            var src = await new Promise(function (res, rej) { var fr = new FileReader(); fr.onload = function () { res(fr.result); }; fr.onerror = rej; fr.readAsDataURL(fl); });
            var img = await new Promise(function (res, rej) { var im = new Image(); im.onload = function () { res(im); }; im.onerror = rej; im.src = src; });
            var cv = document.createElement('canvas'); cv.width = pw; cv.height = ph;
            var cc = cv.getContext('2d');
            cc.drawImage(img, 0, 0, pw, ph);
            var px = cc.getImageData(0, 0, pw, ph).data;
            var hash = b9BlurHashEncode(px, pw, ph, cxn, cyn);
            var dec = b9BlurHashDecode(hash, 128, 128, 1);
            var cv2 = document.createElement('canvas'); cv2.width = 128; cv2.height = 128;
            cv2.getContext('2d').putImageData(new ImageData(dec, 128, 128), 0, 0);
            var prev = cv2.toDataURL('image/png');
            bhO.innerHTML = '<img src="' + prev + '" class="img-prev" alt=""><textarea class="tt-output" id="bhHash" readonly style="margin-top:10px">' + esc(hash) + '</textarea>' +
              '<div class="tw-actions"><button class="btn-copy" id="bhCp">' + UI.copy_result + '</button></div>' +
              '<div class="rate-note">' + esc('blurhash: ' + cxn + '×' + cyn + ' components') + '</div>';
            var bcp = $('bhCp'); if (bcp) bcp.addEventListener('click', function () { copyText(hash); });
          } catch (e) { bhO.innerHTML = '<div class="qr-loading">' + L10N.pdfFail + ' ' + String((e && e.message) || e).slice(0, 140) + '</div>'; }
        })();
      });
    }
  }
  else if (W === 'pdftool') {
    /* PDF 通用处理：单入口 + PDF_OPS 分发（pdf-lib） */
    var pOne = $('pdfOne'), pGo = $('pdfGo'), pOut = $('pdfOut');
    if (pOne && pGo && pOut) {
      function p9Range(spec, n) {
        spec = String(spec || '').trim();
        var set = {}, order = [];
        if (!spec) { for (var i0 = 0; i0 < n; i0++) order.push(i0); return order; }
        spec.split(',').forEach(function (part) {
          part = part.trim(); if (!part) return;
          var mm = part.match(/^(\d+)\s*-\s*(\d+)$/);
          if (mm) { var a = parseInt(mm[1], 10), b = parseInt(mm[2], 10); if (a > b) { var t = a; a = b; b = t; } for (var k = a; k <= b; k++) if (k >= 1 && k <= n && !set[k - 1]) { set[k - 1] = 1; order.push(k - 1); } return; }
          var v = parseInt(part, 10); if (v >= 1 && v <= n && !set[v - 1]) { set[v - 1] = 1; order.push(v - 1); }
        });
        return order;
      }
      function p9Link(bytes, name, note) {
        var blob = new Blob([bytes], { type: 'application/pdf' });
        var url = URL.createObjectURL(blob);
        return (note ? '<div class="rate-note">' + note + '</div>' : '') + '<div class="tw-actions"><a class="btn-copy" href="' + url + '" download="' + name + '">' + UI.download + '</a></div>';
      }
      function p9Box(html) { pOut.innerHTML = html; }
      function p9Loading() { pOut.innerHTML = '<div class="qr-loading">' + UI.process_wait + '</div>'; }
      function p9Fail(e) { pOut.innerHTML = '<div class="qr-loading">' + L10N.pdfFail + ' ' + String((e && e.message) || e).slice(0, 140) + '</div>'; }
      var PDF_SIZES = { a4: [595.28, 841.89], letter: [612, 792], a3: [841.89, 1190.55], a5: [419.53, 595.28], legal: [612, 1008] };
      var PDF_OPS = {
        rotate: function (doc, f) {
          var ang = parseInt(f('angle'), 10) || 90, idx = p9Range(f('pages'), doc.getPageCount());
          idx.forEach(function (i) { var p = doc.getPage(i); p.setRotation(pdfLib.degrees(((p.getRotation().angle || 0) + ang) % 360)); });
          return doc.save();
        },
        'delete': function (doc, f) {
          p9Range(f('pages'), doc.getPageCount()).slice().sort(function (a, b) { return b - a; }).forEach(function (i) { doc.removePage(i); });
          return doc.save();
        },
        reorder: function (doc, f) {
          var idx = p9Range(f('pages'), doc.getPageCount());
          return (async function () {
            var out = await pdfLib.PDFDocument.create();
            var pages = await out.copyPages(doc, idx);
            pages.forEach(function (p) { out.addPage(p); });
            return out.save();
          })();
        },
        extract: function (doc, f) {
          var idx = p9Range(f('pages'), doc.getPageCount());
          return (async function () {
            var out = await pdfLib.PDFDocument.create();
            var pages = await out.copyPages(doc, idx);
            pages.forEach(function (p) { out.addPage(p); });
            return out.save();
          })();
        },
        watermark: function (doc, f) {
          return (async function () {
            var text = f('text') || 'CONFIDENTIAL';
            var font = await doc.embedFont(pdfLib.StandardFonts.Helvetica);
            var col = f('color') || '#999999';
            var rgbv = pdfLib.rgb(parseInt(col.slice(1, 3), 16) / 255, parseInt(col.slice(3, 5), 16) / 255, parseInt(col.slice(5, 7), 16) / 255);
            var op = (parseInt(f('opacity'), 10) || 15) / 100;
            var size = parseInt(f('size'), 10) || 48;
            doc.getPages().forEach(function (p) {
              var w = p.getWidth(), h = p.getHeight(), tw = font.widthOfTextAtSize(text, size);
              p.drawText(text, { x: (w - tw) / 2, y: h / 2 - size / 2, size: size, font: font, color: rgbv, opacity: op, rotate: pdfLib.degrees(45) });
            });
            return doc.save();
          })();
        },
        pagenum: function (doc, f) {
          return (async function () {
            var font = await doc.embedFont(pdfLib.StandardFonts.Helvetica);
            var pos = f('pos') || 'bottom-center', size = parseInt(f('size'), 10) || 11, total = doc.getPageCount();
            doc.getPages().forEach(function (p, i) {
              var label = (f('fmt') || '{n} / {t}').replace('{n}', String(i + 1)).replace('{t}', String(total));
              var w = p.getWidth(), h = p.getHeight(), tw = font.widthOfTextAtSize(label, size);
              var x = pos === 'bottom-left' ? 28 : pos === 'bottom-right' ? w - tw - 28 : (w - tw) / 2;
              p.drawText(label, { x: x, y: 24, size: size, font: font, color: pdfLib.rgb(0.35, 0.35, 0.35) });
            });
            return doc.save();
          })();
        },
        meta: function (doc, f) {
          var cur = {
            title: doc.getTitle() || '', author: doc.getAuthor() || '', subject: doc.getSubject() || '',
            keywords: doc.getKeywords() || '', creator: doc.getCreator() || '', producer: doc.getProducer() || '',
            pages: String(doc.getPageCount())
          };
          var nt = f('title'), na = f('author'), ns = f('subject'), nk = f('keywords');
          if (!nt && !na && !ns && !nk) {
            var rows = Object.keys(cur).map(function (k) { return '<div class="calc-out"><span class="lab">' + k + '</span><span class="val">' + esc(String(cur[k])) + '</span></div>'; }).join('');
            return Promise.resolve({ html: '<div class="calc-outs">' + rows + '</div><div class="rate-note">' + DETAIL.labels.fill_to_edit + '</div>' });
          }
          if (nt) doc.setTitle(nt); if (na) doc.setAuthor(na); if (ns) doc.setSubject(ns); if (nk) doc.setKeywords(nk);
          doc.setProducer('HeronTool');
          return doc.save();
        },
        crop: function (doc, f) {
          var m = parseInt(f('margin'), 10) || 0;
          doc.getPages().forEach(function (p) {
            var w = p.getWidth(), h = p.getHeight();
            p.setCropBox(m, m, Math.max(1, w - m * 2), Math.max(1, h - m * 2));
          });
          return doc.save();
        },
        resize: function (doc, f) {
          var sz = PDF_SIZES[f('preset') || 'a4'] || PDF_SIZES.a4, land = f('orient') === 'landscape';
          var tw = land ? sz[1] : sz[0], th = land ? sz[0] : sz[1];
          doc.getPages().forEach(function (p) {
            var w = p.getWidth(), h = p.getHeight(), sc = Math.min(tw / w, th / h);
            p.setSize(tw, th);
            p.scaleContent(sc, sc);
            p.translateContent((tw - w * sc) / 2, (th - h * sc) / 2);
          });
          return doc.save();
        },
        nup: function (doc, f) {
          return (async function () {
            var cols = parseInt(f('cols'), 10) || 2, rows = parseInt(f('rows'), 10) || 2;
            var out = await pdfLib.PDFDocument.create();
            var emb = await out.embedPdf(doc);
            var p0 = doc.getPage(0), pw = p0.getWidth(), ph = p0.getHeight();
            for (var i = 0; i < emb.length; i += cols * rows) {
              var pg = out.addPage([pw * cols, ph * rows]);
              for (var j = 0; j < cols * rows && i + j < emb.length; j++) {
                var c = j % cols, r = Math.floor(j / cols);
                pg.drawPage(emb[i + j], { x: c * pw, y: ph * rows - (r + 1) * ph, width: pw, height: ph });
              }
            }
            return out.save();
          })();
        },
        count: function (doc) {
          var sizes = {};
          doc.getPages().forEach(function (p) { var k = Math.round(p.getWidth()) + ' × ' + Math.round(p.getHeight()); sizes[k] = (sizes[k] || 0) + 1; });
          var list = Object.keys(sizes).map(function (k) { return k + ' pt × ' + sizes[k]; }).join('，');
          return Promise.resolve({ html: '<div class="calc-outs"><div class="calc-out"><span class="lab">' + DETAIL.labels.pages + '</span><span class="val">' + doc.getPageCount() + '</span></div><div class="calc-out"><span class="lab">' + DETAIL.labels.sizes + '</span><span class="val">' + esc(list) + '</span></div></div>' });
        },
        img2pdf: function (doc, f, files) {
          return (async function () {
            var out = await pdfLib.PDFDocument.create();
            var preset = f('preset') || 'fit', margin = parseInt(f('margin'), 10) || 0;
            for (var i = 0; i < files.length; i++) {
              var fl = files[i], buf = await fl.arrayBuffer(), img;
              if (fl.type === 'image/jpeg' || /\.jpe?g$/i.test(fl.name)) img = await out.embedJpg(buf); else img = await out.embedPng(buf);
              var iw = img.width, ih = img.height, pw, ph;
              if (PDF_SIZES[preset]) { pw = PDF_SIZES[preset][0]; ph = PDF_SIZES[preset][1]; } else { pw = iw; ph = ih; }
              var aw = pw - margin * 2, ah = ph - margin * 2, sc = Math.min(aw / iw, ah / ih);
              var pg = out.addPage([pw, ph]);
              pg.drawImage(img, { x: (pw - iw * sc) / 2, y: (ph - ih * sc) / 2, width: iw * sc, height: ih * sc });
            }
            return out.save();
          })();
        },
        txt2pdf: function (doc, f) {
          return (async function () {
            var text = f('text') || '';
            var size = parseInt(f('size'), 10) || 12;
            var sz = PDF_SIZES[f('preset') || 'a4'] || PDF_SIZES.a4;
            var out = await pdfLib.PDFDocument.create();
            var font = await out.embedFont(pdfLib.StandardFonts.Helvetica);
            var pw = sz[0], ph = sz[1], m = 48, lh = size * 1.5, maxW = pw - m * 2;
            var lines = [];
            String(text).split(/\r\n|\r|\n/).forEach(function (para) {
              if (!para.length) { lines.push(''); return; }
              var cur = '';
              for (var i = 0; i < para.length; i++) {
                if (font.widthOfTextAtSize(cur + para[i], size) > maxW) { lines.push(cur); cur = para[i]; }
                else cur += para[i];
              }
              lines.push(cur);
            });
            var perPage = Math.max(1, Math.floor((ph - m * 2) / lh)), idx = 0;
            if (!lines.length) { out.addPage([pw, ph]); }
            while (idx < lines.length) {
              var pg = out.addPage([pw, ph]);
              for (var k = 0; k < perPage && idx < lines.length; k++, idx++) {
                pg.drawText(lines[idx] || ' ', { x: m, y: ph - m - (k + 1) * lh, size: size, font: font, color: pdfLib.rgb(0.1, 0.1, 0.1) });
              }
            }
            return out.save();
          })();
        }
      };
      pGo.addEventListener('click', function () {
        if (!window.pdfLib) { showToast(UI.copy_fail); return; }
        var op = OP, isTxt = (op === 'txt2pdf'), isImg = (op === 'img2pdf');
        var files = Array.prototype.slice.call(pOne.files || []);
        if (!isTxt && !files.length) { showToast(UI.copy_fail); return; }
        p9Loading();
        (async function () {
          try {
            function f(id) { var e = $(id); return e ? e.value : ''; }
            var doc = (isTxt || isImg) ? null : await pdfLib.PDFDocument.load(await files[0].arrayBuffer());
            var res = await PDF_OPS[op](doc, f, files);
            if (res && res.html) { pOut.innerHTML = res.html; return; }
            var name = op === 'extract' ? 'extracted.pdf' : op === 'reorder' ? 'reordered.pdf' : op === 'nup' ? 'nup.pdf' : op === 'img2pdf' ? 'images.pdf' : op === 'txt2pdf' ? 'text.pdf' : 'output.pdf';
            p9Box(p9Link(res, name, (res.length / 1024).toFixed(0) + ' KB'));
          } catch (e) { p9Fail(e); }
        })();
      });
    }
  }
  else if (W === 'keycode') {
    var kcBox = $('kcBox');
    if (kcBox) {
      kcBox.addEventListener('keydown', function (e) {
        e.preventDefault();
        $('kcKey').textContent = e.key === ' ' ? '(space)' : e.key;
        $('kcCode').textContent = e.code;
        $('kcKeyCode').textContent = String(e.keyCode || e.which || 0);
        var hist = $('kcHist');
        var row = document.createElement('div');
        row.className = 'kc-hist-item';
        row.textContent = e.code + '  ·  key: ' + (e.key === ' ' ? '(space)' : e.key) + '  ·  keyCode: ' + (e.keyCode || e.which || 0);
        hist.insertBefore(row, hist.firstChild);
        while (hist.children.length > 12) hist.removeChild(hist.lastChild);
        var hint = kcBox.querySelector('.kb-hint'); if (hint) hint.style.display = 'none';
        var kc = kcBox.querySelector('.kb-code'); if (kc) kc.textContent = e.code;
      });
      kcBox.focus();
    }
  }

  else if (W === 'device') {
    var dg = $('devGrid');
    if (dg) {
      var n = navigator;
      var items = [
        ['User Agent', n.userAgent],
        ['Platform', n.platform || '—'],
        ['Language', n.language || '—'],
        ['Languages', (n.languages || []).join(', ') || '—'],
        ['Online', n.onLine ? '✓ online' : '✗ offline'],
        ['Cores', n.hardwareConcurrency ? n.hardwareConcurrency + ' CPU' : '—'],
        ['Memory', n.deviceMemory ? n.deviceMemory + ' GB' : '—'],
        ['Screen', screen.width + ' × ' + screen.height],
        ['Viewport', window.innerWidth + ' × ' + window.innerHeight],
        ['DPR', String(window.devicePixelRatio || 1)],
        ['Color Depth', screen.colorDepth + ' bit'],
        ['Touch', 'ontouchstart' in window ? 'yes' : 'no']
      ];
      items.forEach(function (it) {
        var d = document.createElement('div'); d.className = 'dev-item';
        d.innerHTML = '<span class="dev-k">' + esc(it[0]) + '</span><span class="dev-v">' + esc(it[1]) + '</span>';
        dg.appendChild(d);
      });
    }
  }

  else if (W === 'camera') {
    var camV = $('camVideo'), camS = $('camStart'), camSt = $('camStop'), camDl = $('camDl');
    if (camV && camS) {
      var stream = null, rec = null, chunks = [];
      camS.addEventListener('click', function () {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { showToast(LANG === 'zh' ? '浏览器不支持摄像头' : 'Camera not supported'); return; }
        navigator.mediaDevices.getUserMedia({ video: true, audio: true }).then(function (s) {
          stream = s; camV.srcObject = s;
          rec = new MediaRecorder(s);
          chunks = [];
          rec.ondataavailable = function (e) { if (e.data.size) chunks.push(e.data); };
          rec.onstop = function () {
            var blob = new Blob(chunks, { type: 'video/webm' });
            var url = URL.createObjectURL(blob);
            camDl.href = url; camDl.hidden = false;
            stream.getTracks().forEach(function (tr) { tr.stop(); });
            camV.srcObject = null;
          };
          rec.start();
          camS.disabled = true; camSt.disabled = false;
        }).catch(function () { showToast(LANG === 'zh' ? '无法访问摄像头' : 'Camera access denied'); });
      });
      camSt.addEventListener('click', function () { if (rec && rec.state !== 'inactive') rec.stop(); camS.disabled = false; camSt.disabled = true; });
    }
  }

  else if (W === 'tts') {
    var ttsT = $('ttsText'), ttsB = $('ttsBtn'), ttsS = $('ttsStop'), ttsN = $('ttsNote');
    if (ttsT && ttsB && 'speechSynthesis' in window) {
      ttsB.addEventListener('click', function () {
        var t = ttsT.value.trim();
        if (!t) { showToast(LANG === 'zh' ? '请输入文本' : 'Enter some text'); return; }
        window.speechSynthesis.cancel();
        var u = new SpeechSynthesisUtterance(t);
        u.rate = parseFloat(val('ttsRate')) || 1;
        u.pitch = parseFloat(val('ttsPitch')) || 1;
        var voices = window.speechSynthesis.getVoices();
        var v = voices.filter(function (x) { return x.lang.indexOf(LANG === 'zh' ? 'zh' : 'en') === 0; })[0] || null;
        if (v) u.voice = v;
        if (ttsN) ttsN.textContent = LANG === 'zh' ? '朗读中…' : 'Speaking…';
        u.onend = function () { if (ttsN) ttsN.textContent = ''; };
        window.speechSynthesis.speak(u);
      });
      ttsS.addEventListener('click', function () { window.speechSynthesis.cancel(); if (ttsN) ttsN.textContent = ''; });
    }
  }

  else if (W === 'typing') {
    var tpText = $('tpText'), tpIn = $('tpInput'), tpBtn = $('tpStart'), tpStats = $('tpStats');
    if (tpText && tpIn && tpBtn) {
      var TEXTS = ['The quick brown fox jumps over the lazy dog while the sun sets behind the mountains and the river flows gently through the quiet valley.', 'Technology should serve people, not the other way around. Every tool we build is a chance to make someone\'s day a little easier.', 'Practice makes perfect. Typing fast is a skill, and like any skill it improves with patient daily practice and steady focus.'];
      var cur = '', startAt = 0;
      function newTest() {
        cur = TEXTS[Math.floor(Math.random() * TEXTS.length)];
        tpText.innerHTML = cur.split('').map(function (c) { return '<span>' + esc(c) + '</span>'; }).join('');
        tpIn.value = ''; tpIn.disabled = false; tpIn.focus();
        startAt = 0; tpStats.innerHTML = '';
        tpBtn.textContent = LANG === 'zh' ? '重新开始' : 'Restart';
      }
      tpBtn.addEventListener('click', newTest);
      tpIn.addEventListener('input', function () {
        if (!startAt) startAt = Date.now();
        var v = tpIn.value;
        var spans = tpText.querySelectorAll('span');
        var ok = 0;
        for (var i = 0; i < spans.length; i++) {
          var ch = cur[i];
          var typed = v[i];
          if (typed === undefined) spans[i].className = '';
          else if (typed === ch) { spans[i].className = 'ok'; ok++; }
          else spans[i].className = 'err';
        }
        if (v.length >= cur.length) {
          var sec = (Date.now() - startAt) / 1000;
          var wpm = Math.round((cur.split(' ').length / sec) * 60);
          var acc = Math.round((ok / cur.length) * 100);
          tpStats.innerHTML = (LANG === 'zh' ? '速度 ' : 'Speed ') + wpm + ' WPM · ' + (LANG === 'zh' ? '准确率 ' : 'Accuracy ') + acc + '% · ' + sec.toFixed(1) + 's';
          tpIn.disabled = true;
        }
      });
      newTest();
    }
  }

  else if (W === 'chronometer') {
    var chD = $('chDisp'), chS = $('chStart'), chL = $('chLap'), chR = $('chReset'), chLs = $('chLaps');
    if (chD && chS) {
      var t0 = 0, elapsed = 0, ticking = null;
      function fmt(ms) { var m = Math.floor(ms / 60000), s = Math.floor(ms % 60000 / 1000), cs = Math.floor(ms % 1000 / 10); return (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s) + '.' + (cs < 10 ? '0' + cs : cs); }
      function tick() { elapsed = Date.now() - t0; chD.textContent = fmt(elapsed); }
      chS.addEventListener('click', function () {
        if (ticking) { clearInterval(ticking); ticking = null; chS.textContent = LANG === 'zh' ? '继续' : 'Resume'; }
        else { t0 = Date.now() - elapsed; ticking = setInterval(tick, 30); chS.textContent = LANG === 'zh' ? '暂停' : 'Pause'; }
      });
      if (chL) chL.addEventListener('click', function () {
        if (!ticking) return;
        var li = document.createElement('div'); li.className = 'ch-lap';
        li.textContent = (chLs.children.length + 1) + '. ' + fmt(elapsed);
        chLs.insertBefore(li, chLs.firstChild);
      });
      if (chR) chR.addEventListener('click', function () { clearInterval(ticking); ticking = null; elapsed = 0; chD.textContent = '00:00.00'; chS.textContent = LANG === 'zh' ? '开始' : 'Start'; chLs.innerHTML = ''; });
    }
  }

  // =================== P0/P1 NEW WIDGETS ===================
  else if (W === 'cheat') {
    var ct = $('cheatTable');
    if (ct) {
      var rows = [
        ['^', LANG === 'zh' ? '字符串开头' : 'start of string'],
        ['$', LANG === 'zh' ? '字符串结尾' : 'end of string'],
        ['.', LANG === 'zh' ? '任意字符（除换行）' : 'any char (except newline)'],
        ['\\d', LANG === 'zh' ? '数字 0-9' : 'digit 0-9'],
        ['\\w', LANG === 'zh' ? '单词字符' : 'word char'],
        ['\\s', LANG === 'zh' ? '空白字符' : 'whitespace'],
        ['[abc]', LANG === 'zh' ? 'a、b 或 c' : 'a, b or c'],
        ['[^abc]', LANG === 'zh' ? '非 a、b、c' : 'not a, b, c'],
        ['a*', LANG === 'zh' ? '0 次或多次' : '0 or more'],
        ['a+', LANG === 'zh' ? '1 次或多次' : '1 or more'],
        ['a?', LANG === 'zh' ? '0 或 1 次' : '0 or 1'],
        ['a{3}', LANG === 'zh' ? '恰好 3 次' : 'exactly 3'],
        ['a{2,5}', LANG === 'zh' ? '2 到 5 次' : '2 to 5'],
        ['(ab)', LANG === 'zh' ? '捕获分组' : 'capture group'],
        ['(?:ab)', LANG === 'zh' ? '非捕获分组' : 'non-capturing group'],
        ['(a|b)', LANG === 'zh' ? 'a 或 b' : 'a or b'],
        ['\\b', LANG === 'zh' ? '单词边界' : 'word boundary'],
        ['\\.', LANG === 'zh' ? '转义点号（字面 .）' : 'escape dot (literal .)']
      ];
      var th = LANG === 'zh' ? '符号' : 'Token';
      var mh = LANG === 'zh' ? '含义' : 'Meaning';
      ct.innerHTML = '<table class="cheat-grid"><thead><tr><th>' + th + '</th><th>' + mh + '</th></tr></thead><tbody>' + rows.map(function (r) { return '<tr><td><code>' + esc(r[0]) + '</code></td><td>' + esc(r[1]) + '</td></tr>'; }).join('') + '</tbody></table>';
    }
  }

  else if (W === 'codepng') {
    var cpCode = $('code'), cpTheme = $('theme'), cpRender = $('cpRender'), cpDown = $('cpDown'), cpOut = $('cpOut');
    function renderCp() {
      if (!cpOut || !cpCode) return;
      var theme = cpTheme ? cpTheme.value : 'dark';
      var bg = theme === 'light' ? '#ffffff' : '#1e1e2e';
      var fg = theme === 'light' ? '#1e1e2e' : '#e6e6e6';
      var fs = 18, pad = 28, lh = 28;
      var lines = cpCode.value.split('\n');
      var cv = document.createElement('canvas');
      var ctx = cv.getContext('2d');
      ctx.font = fs + 'px ui-monospace, Menlo, Consolas, monospace';
      var maxw = 40;
      lines.forEach(function (l) { maxw = Math.max(maxw, ctx.measureText(l || ' ').width); });
      cv.width = Math.ceil(maxw + pad * 2);
      cv.height = Math.ceil(lines.length * lh + pad * 2);
      ctx.fillStyle = bg; ctx.fillRect(0, 0, cv.width, cv.height);
      ctx.fillStyle = fg; ctx.font = fs + 'px ui-monospace, Menlo, Consolas, monospace';
      ctx.textBaseline = 'top';
      lines.forEach(function (l, i) { ctx.fillText(l, pad, pad + i * lh); });
      cv.style.maxWidth = '100%';
      cpOut.innerHTML = ''; cpOut.appendChild(cv);
    }
    if (cpRender) cpRender.addEventListener('click', renderCp);
    if (cpDown) cpDown.addEventListener('click', function () { var c = cpOut.querySelector('canvas'); if (c) { var a = document.createElement('a'); a.download = 'code.png'; a.href = c.toDataURL('image/png'); a.click(); } });
    renderCp();
  }

  else if (W === 'videocompress') {
    var vcFile = $('vcFile'), vcBtn = $('vcBtn'), vcQ = $('vcQuality'), vcOut = $('vcOut');
    if (vcFile && vcBtn && vcOut) {
      vcBtn.addEventListener('click', function () {
        if (!vcFile.files.length) { showToast(UI.copy_fail); return; }
        if (!window.MediaRecorder) { vcOut.innerHTML = '<div class="qr-loading">' + (LANG === 'zh' ? '浏览器不支持 MediaRecorder' : 'MediaRecorder unsupported') + '</div>'; return; }
        var url = URL.createObjectURL(vcFile.files[0]);
        var v = document.createElement('video');
        v.src = url; v.muted = true; v.playsInline = true;
        vcOut.innerHTML = '<div class="qr-loading">' + (LANG === 'zh' ? '压缩中…' : 'Compressing…') + '</div>';
        v.onloadedmetadata = function () {
          var cands = ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/mp4'];
          var mime = ''; for (var i = 0; i < cands.length; i++) { if (MediaRecorder.isTypeSupported(cands[i])) { mime = cands[i]; break; } }
          try { v.play(); } catch (e) {}
          var stream; try { stream = v.captureStream(); } catch (e) { vcOut.innerHTML = '<div class="qr-loading">' + (LANG === 'zh' ? '无法捕获视频流' : 'Cannot capture stream') + '</div>'; return; }
          var q = parseFloat(vcQ ? vcQ.value : '0.6') || 0.6;
          var bps = Math.max(120000, Math.round((v.videoWidth || 640) * (v.videoHeight || 480) * 0.12 * q));
          var opts = mime ? { mimeType: mime, videoBitsPerSecond: bps } : { videoBitsPerSecond: bps };
          var rec; try { rec = new MediaRecorder(stream, opts); } catch (e) { vcOut.innerHTML = '<div class="qr-loading">' + (LANG === 'zh' ? '录制失败：' : 'Rec failed: ') + e.message + '</div>'; return; }
          var chunks = [];
          rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
          rec.onstop = function () {
            v.pause();
            var blob = new Blob(chunks, { type: mime || 'video/webm' });
            var out = URL.createObjectURL(blob);
            vcOut.innerHTML = '<div class="rate-note">' + (LANG === 'zh' ? '原始 ' : 'Original ') + (vcFile.files[0].size / 1048576).toFixed(2) + ' MB → ' + (blob.size / 1048576).toFixed(2) + ' MB</div><div class="tw-actions"><a class="btn-copy" href="' + out + '" download="compressed.webm">' + (LANG === 'zh' ? '下载' : 'Download') + '</a></div>';
          };
          rec.start();
          var dur = isFinite(v.duration) && v.duration > 0 ? v.duration * 1000 : 30000;
          setTimeout(function () { if (rec.state !== 'inactive') rec.stop(); }, Math.min(dur + 2000, 600000));
        };
        v.onerror = function () { vcOut.innerHTML = '<div class="qr-loading">' + (LANG === 'zh' ? '视频加载失败' : 'Video load failed') + '</div>'; };
      });
    }
  }

  else if (W === 'easing') {
    var ezSel = $('ezSel'), ezCanvas = $('ezCanvas'), ezCopy = $('ezCopy');
    function bez(p1x, p1y, p2x, p2y) {
      function ax(t, a1, a2) { var c = 3 * a1, b = 3 * (a2 - a1) - c, a = 1 - c - b; return ((a * t + b) * t + c) * t; }
      function bezX(x) { var t = x; for (var i = 0; i < 8; i++) { var xe = ax(t, p1x, p2x) - x; if (Math.abs(xe) < 1e-4) break; var d = (3 * (1 - 3 * p2x + 3 * p1x) * t * t + 2 * (3 * p2x - 6 * p1x) * t + 3 * p1x); if (Math.abs(d) < 1e-6) break; t -= xe / d; } return t; }
      return function (x) { if (x <= 0) return 0; if (x >= 1) return 1; var t = bezX(x); return ax(t, p1y, p2y); };
    }
    function drawEz() {
      if (!ezCanvas || !ezSel) return null;
      var ctx = ezCanvas.getContext('2d'), Wd = ezCanvas.width, Hd = ezCanvas.height, pad = 16;
      ctx.clearRect(0, 0, Wd, Hd);
      ctx.strokeStyle = '#e0e8dc'; ctx.lineWidth = 1;
      ctx.strokeRect(pad, pad, Wd - pad * 2, Hd - pad * 2);
      var val = ezSel.value, fn;
      if (val === 'linear') fn = function (t) { return t; };
      else if (val === 'ease') fn = bez(0.25, 0.1, 0.25, 1);
      else if (val === 'ease-in') fn = bez(0.42, 0, 1, 1);
      else if (val === 'ease-out') fn = bez(0, 0, 0.58, 1);
      else if (val === 'ease-in-out') fn = bez(0.42, 0, 0.58, 1);
      else { var p = val.split(','); fn = bez(parseFloat(p[0]), parseFloat(p[1]), parseFloat(p[2]), parseFloat(p[3])); }
      ctx.strokeStyle = '#3e7c46'; ctx.lineWidth = 3; ctx.beginPath();
      for (var x = 0; x <= 1.0001; x += 0.02) { var px = pad + x * (Wd - pad * 2), py = (Hd - pad) - fn(x) * (Hd - pad * 2); if (x === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py); }
      ctx.stroke();
      return fn;
    }
    if (ezCanvas && ezSel) {
      var anim = drawEz();
      ezSel.addEventListener('change', function () { anim = drawEz(); });
      if (ezCopy) ezCopy.addEventListener('click', function () { copyText('cubic-bezier(' + ezSel.value + ')'); });
      setInterval(function () { if (!anim) return; drawEz(); var dot = (Date.now() / 1500) % 1; var px = 16 + dot * (ezCanvas.width - 32), py = (ezCanvas.height - 16) - anim(dot) * (ezCanvas.height - 32); var c = ezCanvas.getContext('2d'); c.fillStyle = '#e0533a'; c.beginPath(); c.arc(px, py, 5, 0, 7); c.fill(); }, 40);
    }
  }

  else if (W === 'plot') {
    var plF = $('func'), plMin = $('min'), plMax = $('max'), plBtn = $('plBtn'), plCanvas = $('plCanvas');
    function plotEval(expr, x) { var fn = new Function('x', 'with(Math){ return ' + expr.replace(/\^/g, '**') + '; }'); return fn(x); }
    function drawPlot() {
      if (!plCanvas || !plF) return;
      var ctx = plCanvas.getContext('2d'), Wd = plCanvas.width, Hd = plCanvas.height, pad = 30;
      var lo = parseFloat(plMin ? plMin.value : '-5'), hi = parseFloat(plMax ? plMax.value : '5');
      if (isNaN(lo) || isNaN(hi) || lo >= hi) return;
      ctx.clearRect(0, 0, Wd, Hd);
      ctx.strokeStyle = '#e0e8dc'; ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(pad, Hd / 2); ctx.lineTo(Wd - pad, Hd / 2); ctx.moveTo(Wd / 2, pad); ctx.lineTo(Wd / 2, Hd - pad); ctx.stroke();
      var expr = (plF.value || '').trim(); if (!expr) return;
      var ys = [];
      for (var i = 0; i <= 200; i++) { var x = lo + (hi - lo) * i / 200; try { var y = plotEval(expr, x); if (isFinite(y)) ys.push(y); } catch (e) {} }
      var maxAbs = 1; ys.forEach(function (y) { maxAbs = Math.max(maxAbs, Math.abs(y)); });
      var sx = (Wd - pad * 2) / (hi - lo);
      ctx.strokeStyle = '#3e7c46'; ctx.lineWidth = 2; ctx.beginPath();
      var started = false;
      for (var j = 0; j <= 200; j++) {
        var X = lo + (hi - lo) * j / 200; var Y;
        try { Y = plotEval(expr, X); } catch (e) { started = false; continue; }
        if (!isFinite(Y)) { started = false; continue; }
        var px = pad + (X - lo) * sx;
        var py = Hd / 2 - (Y / maxAbs) * (Hd / 2 - pad);
        if (py < pad) py = pad; if (py > Hd - pad) py = Hd - pad;
        if (!started) { ctx.moveTo(px, py); started = true; } else ctx.lineTo(px, py);
      }
      ctx.stroke();
    }
    if (plBtn) plBtn.addEventListener('click', drawPlot);
    if (plMin) plMin.addEventListener('input', drawPlot);
    if (plMax) plMax.addEventListener('input', drawPlot);
    if (plF) plF.addEventListener('input', drawPlot);
    drawPlot();
  }

  else if (W === 'palette') {
    var palFile = $('palFile'), palBtn = $('palBtn'), palOut = $('palOut');
    if (palFile && palBtn && palOut) {
      palBtn.addEventListener('click', function () {
        if (!palFile.files.length) { showToast(UI.copy_fail); return; }
        var fr = new FileReader();
        fr.onload = function () {
          var img = new Image();
          img.onload = function () {
            var cv = document.createElement('canvas'); cv.width = 100; cv.height = 100;
            var cx = cv.getContext('2d'); cx.drawImage(img, 0, 0, 100, 100);
            var data = cx.getImageData(0, 0, 100, 100).data;
            var buckets = {};
            for (var i = 0; i < data.length; i += 4) {
              var r = data[i], g = data[i + 1], b = data[i + 2];
              var key = (r >> 4) + ',' + (g >> 4) + ',' + (b >> 4);
              if (!buckets[key]) buckets[key] = { r: 0, g: 0, b: 0, c: 0 };
              buckets[key].r += r; buckets[key].g += g; buckets[key].b += b; buckets[key].c++;
            }
            var arr = Object.keys(buckets).map(function (k) { var bk = buckets[k]; return { hex: rgbToHex(bk.r / bk.c, bk.g / bk.c, bk.b / bk.c), c: bk.c }; }).sort(function (a, b) { return b.c - a.c; }).slice(0, 8);
            palOut.innerHTML = arr.map(function (a) { return '<div class="sw"><span style="background:' + a.hex + '"></span><code>' + a.hex + '</code></div>'; }).join('');
          };
          img.src = fr.result;
        };
        fr.readAsDataURL(palFile.files[0]);
      });
    }
  }

  else if (W === 'batchimg') {
    var biFile = $('biFile'), biBtn = $('biBtn'), biFmt = $('fmt'), biW = $('biW'), biOut = $('biOut');
    if (biFile && biBtn && biOut) {
      biBtn.addEventListener('click', function () {
        if (!biFile.files.length) { showToast(UI.copy_fail); return; }
        var fmt = (biFmt ? biFmt.value : 'png') || 'png';
        var maxw = parseInt(biW ? biW.value : '0', 10) || 0;
        biOut.innerHTML = '<div class="qr-loading">' + (LANG === 'zh' ? '处理中…' : 'Processing…') + '</div>';
        var done = 0, links = [];
        function proc(f) {
          var fr = new FileReader();
          fr.onload = function () {
            var img = new Image();
            img.onload = function () {
              var cw = img.width, ch = img.height;
              if (maxw && cw > maxw) { ch = Math.round(ch * maxw / cw); cw = maxw; }
              var cv = document.createElement('canvas'); cv.width = cw; cv.height = ch;
              cv.getContext('2d').drawImage(img, 0, 0, cw, ch);
              var mime = fmt === 'jpeg' ? 'image/jpeg' : fmt === 'webp' ? 'image/webp' : 'image/png';
              var url = cv.toDataURL(mime, 0.9);
              var name = (f.name || 'image').replace(/\.[^.]+$/, '') + '.' + fmt;
              links.push('<a class="btn-ghost" href="' + url + '" download="' + name + '">' + name + '</a>');
              done++;
              if (done >= biFile.files.length) biOut.innerHTML = '<div class="tw-actions">' + links.join(' ') + '</div>';
            };
            img.src = fr.result;
          };
          fr.readAsDataURL(f);
        }
        for (var i = 0; i < biFile.files.length; i++) proc(biFile.files[i]);
      });
    }
  }

  else if (W === 'emojifav') {
    var efEmoji = $('emoji'), efBg = $('bg'), efShape = $('shape'), efBtn = $('efBtn'), efDown = $('efDown'), efOut = $('efOut');
    function drawFav() {
      if (!efOut) return;
      var s = 64, cv = document.createElement('canvas'); cv.width = s; cv.height = s;
      var ctx = cv.getContext('2d');
      var bg = efBg ? efBg.value : '#3e7c46';
      var shape = efShape ? efShape.value : 'rounded';
      ctx.fillStyle = bg;
      if (shape === 'square') { ctx.fillRect(0, 0, s, s); }
      else { var r = 14; ctx.beginPath(); ctx.moveTo(r, 0); ctx.arcTo(s, 0, s, s, r); ctx.arcTo(s, s, 0, s, r); ctx.arcTo(0, s, 0, 0, r); ctx.arcTo(0, 0, s, 0, r); ctx.closePath(); ctx.fill(); }
      ctx.fillStyle = '#ffffff'; ctx.font = '44px serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText((efEmoji ? efEmoji.value : '🦢') || '🦢', s / 2, s / 2 + 2);
      cv.style.width = '64px'; cv.style.height = '64px'; cv.style.maxWidth = '64px';
      efOut.innerHTML = ''; efOut.appendChild(cv);
    }
    if (efBtn) efBtn.addEventListener('click', drawFav);
    if (efDown) efDown.addEventListener('click', function () { var c = efOut.querySelector('canvas'); if (c) { var a = document.createElement('a'); a.download = 'favicon.png'; a.href = c.toDataURL('image/png'); a.click(); } });
    drawFav();
  }

  // =================== LEGACY HELPERS ===================
  function legacyIO(slug, txt, C) {
    var out = txt;
    if (slug === 'case-converter') { var m = C.act(); out = m === 'lower' ? txt.toLowerCase() : m === 'title' ? toTitle(txt) : m === 'sentence' ? toSentence(txt) : txt.toUpperCase(); }
    else if (slug === 'text-deduplicator') { var ls = toLines(txt); if (C.opt('dedup')) { var seen = {}; ls = ls.filter(function (l) { var k = l.trim(); if (seen[k]) return false; seen[k] = 1; return true; }); } if (C.opt('blank')) ls = ls.filter(function (l) { return l.trim() !== ''; }); if (C.opt('trim')) ls = ls.map(function (l) { return l.replace(/^\s+|\s+$/g, ''); }); out = ls.join('\n'); }
    else if (slug === 'text-sorter') { var l2 = toLines(txt); var mm = C.act(); if (mm === 'desc') l2 = l2.slice().sort().reverse(); else if (mm === 'len') l2 = l2.slice().sort(function (a, b) { return a.length - b.length; }); else if (mm === 'rev') l2 = l2.slice().reverse(); else l2 = l2.slice().sort(); out = l2.join('\n'); }
    else if (slug === 'text-replacer') { var f = C.fv('find'), r = C.fv('repl'); if (f !== '') { try { if (C.opt('regex')) out = txt.replace(new RegExp(f, C.opt('icase') ? 'gi' : 'g'), r); else { var re = new RegExp(f.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), C.opt('icase') ? 'gi' : 'g'); out = txt.replace(re, r); } } catch (e) { out = txt; } } }
    else if (slug === 'html-to-text') { out = htmlToText(txt); }
    else if (slug === 'json-formatter') { try { var o = JSON.parse(txt); out = C.act() === 'compact' ? JSON.stringify(o) : JSON.stringify(o, null, 2); } catch (e) { out = C.act() === 'validate' ? 'JSON 无效：' + e.message : '解析失败：' + e.message; } }
    else if (slug === 'html-encoder') { out = C.act() === 'decode' ? txt.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&') : txt.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    else if (slug === 'url-encoder-decoder') { try { out = C.act() === 'decode' ? decodeURIComponent(txt) : encodeURIComponent(txt); } catch (e) { out = txt; } }
    else if (slug === 'base64-encoder-decoder') { try { if (C.act() === 'encode') out = btoa(unescape(encodeURIComponent(txt))); else out = decodeURIComponent(escape(atob(txt.trim()))); } catch (e) { out = '解码失败'; } }
    else if (slug === 'keyword-density-checker') { var ws = (txt.match(/[一-鿿㐀-䶿]+|[A-Za-z0-9]+/g) || []); var cnt = {}; ws.forEach(function (w) { w = w.toLowerCase(); cnt[w] = (cnt[w] || 0) + 1; }); var arr = Object.keys(cnt).map(function (k) { return [k, cnt[k]]; }).sort(function (a, b) { return b[1] - a[1]; }).slice(0, 20); var tot = ws.length || 1; out = arr.map(function (x) { return x[0] + '：' + x[1] + ' 次（' + (x[1] / tot * 100).toFixed(1) + '%）'; }).join('\n') || '暂无内容'; }
    else if (slug === 'readability-analyzer') { var ch = txt.replace(/\s/g, '').length, wds = (txt.match(/[一-鿿㐀-䶿]+|[A-Za-z0-9]+/g) || []).length, sens = (txt.match(/[.!?。！？]+/g) || []).length || 1; out = '字符数：' + ch + '\n词数：' + wds + '\n句子数：' + (txt.match(/[.!?。！？]+/g) || []).length + '\n平均句长：' + (wds / sens).toFixed(1) + ' 词'; }
    return out;
  }
  function legacyCalc() {
    var get = function (id) { return val(id); }; function setOut2(id, v) { var e = $('calc-out-' + id); if (e) { if (e.tagName === 'TEXTAREA') e.value = v; else e.textContent = v; } }
    var UNITS = { length: { m: 1, km: 1000, cm: 0.01, mm: 0.001, mi: 1609.344, ft: 0.3048, yd: 0.9144, in: 0.0254 }, weight: { kg: 1, g: 0.001, mg: 0.000001, t: 1000, lb: 0.45359237, oz: 0.028349523125 }, area: { 'm2': 1, 'km2': 1000000, 'cm2': 0.0001, ha: 10000, 'ft2': 0.09290304, ac: 4046.8564224 } };
    function offMs(tz, date) { var dtf = new Intl.DateTimeFormat('en-US', { timeZone: tz, hour12: false, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }); var p = {}; dtf.formatToParts(date).forEach(function (x) { if (x.type !== 'literal') p[x.type] = x.value; }); var hh = p.hour === '24' ? 0 : +p.hour; return Date.UTC(+p.year, +p.month - 1, +p.day, hh, +p.minute, +p.second) - date.getTime(); }
    function conv(wallStr, from, to) { var d = new Date(wallStr); if (isNaN(d)) return null; var epoch = d.getTime() - offMs(from, d); var dtf = new Intl.DateTimeFormat('en-US', { timeZone: to, hour12: false, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', weekday: 'short' }); return dtf.format(new Date(epoch)); }
    if (SLUG === 'unit-converter') { var catSel = $('cat'), fSel = $('from'), tSel = $('to'); function fill(sel, keys) { sel.innerHTML = ''; keys.forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = k; sel.appendChild(o); }); } function refresh() { var c = catSel.value; if (c === 'temp') fill(fSel, ['C', 'F', 'K']), fill(tSel, ['C', 'F', 'K']); else { var ks = Object.keys(UNITS[c]); fill(fSel, ks); fill(tSel, ks); if (tSel.options[1]) tSel.selectedIndex = 1; } run(); } function run() { var c = catSel.value, v = parseFloat(get('value')); if (isNaN(v)) { setOut2('result', '—'); return; } var r; if (c === 'temp') { var cc = get('from') === 'C' ? v : get('from') === 'F' ? (v - 32) * 5 / 9 : v - 273.15; r = get('to') === 'C' ? cc : get('to') === 'F' ? cc * 9 / 5 + 32 : cc + 273.15; } else { r = v * UNITS[c][get('from')] / UNITS[c][get('to')]; } setOut2('result', (Math.round(r * 1e6) / 1e6) + ' ' + get('to')); } catSel.addEventListener('change', refresh); fSel.addEventListener('change', run); tSel.addEventListener('change', run); $('value').addEventListener('input', run); refresh(); }
    else if (SLUG === 'gradient-generator') { var gp = $('gradPreview'); function run() { var c1 = get('c1') || '#3e7c46', c2 = get('c2') || '#2f5a35', a = get('angle') || 90, ty = get('type') || 'linear'; var css = ty === 'radial' ? 'radial-gradient(circle, ' + c1 + ', ' + c2 + ')' : 'linear-gradient(' + a + 'deg, ' + c1 + ', ' + c2 + ')'; if (gp) gp.style.background = css; var ce = $('calc-out-code'); if (ce) ce.value = 'background: ' + css + ';'; } ['c1', 'c2', 'angle', 'type'].forEach(function (id) { $(id).addEventListener('input', run); $(id).addEventListener('change', run); }); run(); }
    else if (SLUG === 'date-calculator') { ['d1', 'd2'].forEach(function (id) { $(id).addEventListener('change', run); }); function run() { var a = new Date(get('d1')), b = new Date(get('d2')); if (isNaN(a) || isNaN(b)) { setOut2('days', '—'); setOut2('weeks', '—'); setOut2('years', '—'); return; } var d = Math.round((b - a) / 86400000); setOut2('days', d.toLocaleString()); setOut2('weeks', (d / 7).toFixed(1)); setOut2('years', (d / 365.25).toFixed(1)); } run(); }
    else if (SLUG === 'age-calculator') { $('birth').addEventListener('change', run); function run() { var bd = new Date(get('birth')); if (isNaN(bd)) { setOut2('age', '—'); setOut2('days', '—'); setOut2('next', '—'); return; } var now = new Date(); var age = now.getFullYear() - bd.getFullYear(); var m = now.getMonth() - bd.getMonth(); if (m < 0 || (m === 0 && now.getDate() < bd.getDate())) age--; var days = Math.round((now - bd) / 86400000); var next = new Date(now.getFullYear(), bd.getMonth(), bd.getDate()); if (next < now) next.setFullYear(now.getFullYear() + 1); var nd = Math.round((next - now) / 86400000); setOut2('age', age + ' 岁'); setOut2('days', days.toLocaleString() + ' 天'); setOut2('next', nd + ' 天后（' + next.toISOString().slice(0, 10) + '）'); } run(); }
    else if (SLUG === 'time-zone-converter') { ['dt', 'from', 'to'].forEach(function (id) { $(id).addEventListener('change', run); }); function run() { var s = get('dt'); if (!s) { setOut2('result', '—'); return; } var r = conv(s, get('from'), get('to')); setOut2('result', r || '无效时间'); } run(); }
    else if (SLUG === 'percentage-calculator') { ['value', 'total'].forEach(function (id) { $(id).addEventListener('input', run); }); function run() { var v = parseFloat(get('value')), t = parseFloat(get('total')); if (isNaN(v) || isNaN(t) || t === 0) { setOut2('percent', '—'); setOut2('reverse', '—'); return; } setOut2('percent', (v / t * 100).toFixed(2) + ' %'); setOut2('reverse', (v / t).toFixed(4)); } run(); }
    else if (SLUG === 'fraction-calculator') { ['a', 'b', 'c', 'd', 'op'].forEach(function (id) { $(id).addEventListener('input', run); $(id).addEventListener('change', run); }); function run() { var a = +get('a'), b = +get('b'), c = +get('c'), d = +get('d'), op = get('op'); if (!b || !d) { setOut2('result', '—'); setOut2('decimal', '—'); return; } var n, dn; if (op === 'sub') { n = a * d - c * b; dn = b * d; } else if (op === 'mul') { n = a * c; dn = b * d; } else { n = a * d + c * b; dn = b * d; } var g = gcd(n, dn); n /= g; dn /= g; setOut2('result', (dn === 1 ? n : n + '/' + dn)); setOut2('decimal', (n / dn).toFixed(4)); } run(); }
    else if (SLUG === 'base-converter') { ['num', 'from', 'to'].forEach(function (id) { $(id).addEventListener('input', run); $(id).addEventListener('change', run); }); function run() { var num = get('num').trim(), f = parseInt(get('from')), t = parseInt(get('to')); if (!num) { setOut2('result', '—'); return; } var dec = parseInt(num, f); if (isNaN(dec)) { setOut2('result', '无效数字'); return; } setOut2('result', dec.toString(t).toUpperCase()); } run(); }
    else if (SLUG === 'meta-tag-generator') { ['title', 'desc', 'keywords', 'ogImage'].forEach(function (id) { $(id).addEventListener('input', run); }); function run() { var t = get('title'), d = get('desc'), k = get('keywords'), og = get('ogImage'); var lines = []; if (t) lines.push('<title>' + esc(t) + '</title>'); if (d) lines.push('<meta name="description" content="' + esc(d) + '">'); if (k) lines.push('<meta name="keywords" content="' + esc(k) + '">'); if (t) lines.push('<meta property="og:title" content="' + esc(t) + '">'); if (og) lines.push('<meta property="og:image" content="' + esc(og) + '">'); if (t) lines.push('<meta name="twitter:card" content="summary_large_image">'); var ce = $('calc-out-code'); if (ce) ce.value = lines.join('\n'); } run(); }
  }
  function legacyGen() {
    var gb = $('genBtn'), go = $('genOutput'), gs = $('palSwatches');
    function gv(id) { return val(id); }
    function run() {
      if (SLUG === 'random-number-generator') { var mn = parseInt(gv('min')) || 0, mx = parseInt(gv('max')) || 100, n = parseInt(gv('count')) || 1; if (n > 200) n = 200; var arr = []; for (var i = 0; i < n; i++) arr.push(Math.floor(Math.random() * (mx - mn + 1)) + mn); go.value = arr.join('\n'); }
      else if (SLUG === 'color-palette-generator') { var hex = gv('base').replace('#', ''); if (!/^([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(hex)) { go.value = '请输入有效 HEX'; if (gs) gs.innerHTML = ''; return; } if (hex.length === 3) hex = hex.split('').map(function (x) { return x + x; }).join(''); var r = parseInt(hex.slice(0, 2), 16), g = parseInt(hex.slice(2, 4), 16), b = parseInt(hex.slice(4, 6), 16); var mx = Math.max(r, g, b), mn = Math.min(r, g, b), dl = (mx + mn) / 2, h, s, l = dl / 255; if (mx === mn) { h = 0; s = 0; } else { var dd = mx - mn; s = l > .5 ? dd / (2 - mx - mn) : dd / (mx + mn); if (mx === r) h = (g - b) / dd + (g < b ? 6 : 0); else if (mx === g) h = (b - r) / dd + 2; else h = (r - g) / dd + 4; h *= 60; } function hsl2hex(hh, ss, ll) { ss /= 100; ll /= 100; var c = (1 - Math.abs(2 * ll - 1)) * ss, x = c * (1 - Math.abs((hh / 60) % 2 - 1)), m = ll - c / 2, rr = 0, gg = 0, bb = 0; if (hh < 60) { rr = c; gg = x; } else if (hh < 120) { rr = x; gg = c; } else if (hh < 180) { gg = c; bb = x; } else if (hh < 240) { gg = x; bb = c; } else if (hh < 300) { rr = x; bb = c; } else { rr = c; bb = x; } return '#' + [rr, gg, bb].map(function (v) { return ('0' + Math.round((v + m) * 255).toString(16)).slice(-2); }).join(''); } var pal = [hsl2hex(h, s * 100, l * 100), hsl2hex((h + 180) % 360, s * 100, l * 100), hsl2hex((h + 30) % 360, s * 100, Math.min(95, l * 100 + 12)), hsl2hex((h - 30 + 360) % 360, s * 100, Math.min(95, l * 100 + 12)), hsl2hex(h, s * 100, Math.max(8, l * 100 - 15)), hsl2hex(h, s * 100, Math.max(8, l * 100 - 30))]; go.value = pal.join('\n'); if (gs) gs.innerHTML = pal.map(function (c) { return '<span class="pal-chip" style="background:' + c + '"><i>' + c + '</i></span>'; }).join(''); }
    }
    if (gb) gb.addEventListener('click', run);
    var gcl = $('genClear'); if (gcl) gcl.addEventListener('click', function () { go.value = ''; if (gs) gs.innerHTML = ''; });
  }
  function legacyColor() {
    var ci = $('colorInput'), sw = $('colorSwatch');
    if (ci) {
      function hexToRgb(h) { h = h.replace('#', ''); if (h.length === 3) h = h.split('').map(function (x) { return x + x; }).join(''); var n = parseInt(h, 16); return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; }
      function rgbToHsl(r, g, b) { r /= 255; g /= 255; b /= 255; var mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn, h = 0, s = 0, l = (mx + mn) / 2; if (d) { s = l > .5 ? d / (2 - mx - mn) : d / (mx + mn); if (mx === r) h = (g - b) / d + (g < b ? 6 : 0); else if (mx === g) h = (b - r) / d + 2; else h = (r - g) / d + 4; h *= 60; } return [Math.round(h), Math.round(s * 100), Math.round(l * 100)]; }
      function run() { var v = ci.value.trim(), rgb = null, hex = ''; var m = v.match(/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/); if (m) { rgb = hexToRgb(m[1]); hex = '#' + (m[1].length === 3 ? m[1].split('').map(function (x) { return x + x; }).join('') : m[1]).toLowerCase(); } else { var rm = v.match(/rgba?\(([^)]+)\)/i); if (rm) { var p = rm[1].split(',').map(function (x) { return parseFloat(x); }); if (p.length >= 3) { rgb = [p[0], p[1], p[2]]; hex = '#' + rgb.map(function (x) { return ('0' + Math.round(x).toString(16)).slice(-2); }).join(''); } } } if (!rgb) { sw.style.background = 'transparent'; ['hex', 'rgb', 'hsl'].forEach(function (id) { var e = $('calc-out-' + id); if (e) e.textContent = '—'; }); return; } var hsl = rgbToHsl(rgb[0], rgb[1], rgb[2]); sw.style.background = hex; var eH = $('calc-out-hex'); if (eH) eH.textContent = hex; var eR = $('calc-out-rgb'); if (eR) eR.textContent = 'rgb(' + rgb[0] + ', ' + rgb[1] + ', ' + rgb[2] + ')'; var eS = $('calc-out-hsl'); if (eS) eS.textContent = 'hsl(' + hsl[0] + ', ' + hsl[1] + '%, ' + hsl[2] + '%)'; }
      ci.addEventListener('input', run); run();
    }
  }

  // =================== LEGACY WIDGET BRANCHES ===================
  if (W === 'counter') { /* unchanged */ var inp = $('twInput'); if (inp) { var elChars = $('stChars'), elCharsNo = $('stCharsNo'), elWords = $('stWords'), elLines = $('stLines'), elParas = $('stParas'), elRead = $('stRead'); function compute() { var txt = inp.value, chars = txt.length, charsNo = txt.replace(/\s/g, '').length; var cjk = (txt.match(/[一-鿿㐀-䶿]/g) || []).length; var trimmed = txt.trim(), latin = []; if (trimmed) latin = trimmed.split(/\s+/).filter(function (w) { return /[A-Za-z0-9]/.test(w); }); var words = cjk + latin.length; var lines = txt === '' ? 0 : txt.split(/\r\n|\r|\n/).length; var paras = trimmed === '' ? 0 : trimmed.split(/\n\s*\n/).filter(function (p) { return p.trim() !== ''; }).length; var readMin = Math.max(0, Math.ceil(cjk / 400 + latin.length / 200)); if (DETAIL.mode === 'chars') { elChars.textContent = chars.toLocaleString(); elCharsNo.textContent = charsNo.toLocaleString(); if (elLines) elLines.textContent = lines.toLocaleString(); if (elParas) elParas.textContent = paras.toLocaleString(); } else { elChars.textContent = chars.toLocaleString(); elCharsNo.textContent = charsNo.toLocaleString(); elWords.textContent = words.toLocaleString(); elLines.textContent = lines.toLocaleString(); elParas.textContent = paras.toLocaleString(); elRead.textContent = readMin.toLocaleString(); } } inp.addEventListener('input', compute); compute(); var twc = $('twCopy'); if (twc) twc.addEventListener('click', function () { var sum = '字符（含空格）：' + elChars.textContent + '\n字符（不含空格）：' + elCharsNo.textContent; if (DETAIL.mode === 'words') sum += '\n单词数：' + elWords.textContent + '\n行数：' + elLines.textContent + '\n段落数：' + elParas.textContent + '\n预计阅读：' + elRead.textContent + ' 分钟'; else sum += '\n行数：' + elLines.textContent + '\n段落数：' + elParas.textContent; copyText(sum); }); var twcl = $('twClear'); if (twcl) twcl.addEventListener('click', function () { inp.value = ''; compute(); inp.focus(); }); } }
  else if (W === 'markdown') { var md = $('mdInput'), pv = $('mdPreview'); if (md && pv) { function render(src) { var h = esc(src); h = h.replace(/^### (.*)$/gm, '<h3>$1</h3>').replace(/^## (.*)$/gm, '<h2>$1</h2>').replace(/^# (.*)$/gm, '<h1>$1</h1>'); h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/\*([^*]+)\*/g, '<em>$1</em>'); h = h.replace(/`([^`]+)`/g, '<code>$1</code>'); h = h.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>'); h = h.replace(/^- (.*)$/gm, '<li>$1</li>'); h = h.replace(/(<li>[\s\S]*?<\/li>)/g, '<ul>$1</ul>'); return h.split(/\n{2,}/).map(function (b) { return /^<(h\d|ul|ol)/.test(b.trim()) ? b : '<p>' + b.replace(/\n/g, '<br>') + '</p>'; }).join(''); } function run() { pv.innerHTML = render(md.value); if (OP === 'tohtml') { var mh = $('mdHtml'); if (mh) mh.value = render(md.value).replace(/<br>/g, '\n'); } } md.addEventListener('input', run); run(); var mdc = $('mdCopy'); if (mdc) mdc.addEventListener('click', function () { copyText(OP === 'tohtml' && $('mdHtml') ? $('mdHtml').value : render(md.value)); }); var mdl = $('mdClear'); if (mdl) mdl.addEventListener('click', function () { md.value = ''; run(); md.focus(); }); } }
  else if (W === 'image') { /* unchanged */ var file = $('imgFile'), btn = $('imgBtn'), out = $('imgOut'); if (file && out) { var MODE = (DETAIL.img && DETAIL.img.mode) || 'base64'; function getFile(cb) { if (!file.files || !file.files[0]) { showToast(UI.copy_fail); return; } var fr = new FileReader(); fr.onload = function () { cb(fr.result); }; fr.readAsDataURL(file.files[0]); } function process() { getFile(function (src) { if (MODE === 'base64') { out.innerHTML = '<textarea class="tt-output" id="imgB64" readonly style="margin-top:10px">' + esc(src) + '</textarea><div class="tw-actions"><button class="btn-copy" id="imgCp">复制</button></div>'; $('imgCp').addEventListener('click', function () { copyText(src); }); return; } var img = new Image(); img.onload = function () { var cv = document.createElement('canvas'), cx = cv.getContext('2d'); var w, h; if (MODE === 'resize') { w = parseInt($('width').value) || img.width; h = parseInt($('height').value) || img.height; } else { w = img.width; h = img.height; } cv.width = w; cv.height = h; cx.drawImage(img, 0, 0, w, h); var mime = MODE === 'convert' ? ($('format').value || 'image/png') : (file.files[0].type || 'image/png'); var q = MODE === 'compress' ? (parseInt($('quality').value) || 80) / 100 : (mime === 'image/png' ? 1 : 0.92); var url = cv.toDataURL(mime, q), ext = mime.split('/')[1] || 'png', name = (file.files[0].name || 'image').replace(/\.[^.]+$/, '') + '.' + ext; out.innerHTML = '<img src="' + url + '" class="img-prev" alt=""><div class="tw-actions"><a class="btn-copy" id="imgDl" href="' + url + '" download="' + name + '">' + UI.download + '</a></div>'; }; img.src = src; }); } btn.addEventListener('click', process); if (MODE === 'base64') file.addEventListener('change', process); } }
  else if (W === 'regex') { var rp = $('rxPattern'), ri = $('rxInput'), ro = $('rxOutput'); if (rp && ri && ro) { function run() { var p = rp.value, t = ri.value; if (!p) { ro.value = ''; return; } var flags = (optChk('g') ? 'g' : '') + (optChk('i') ? 'i' : ''); if (OP === 'extract') { try { var re = new RegExp(p, flags.indexOf('g') < 0 ? flags + 'g' : flags); var mm, lines = []; while ((mm = re.exec(t)) !== null) { if (mm[0] === '') re.lastIndex++; if (lines.length > 500) break; lines.push(mm[0]); } ro.value = lines.length ? lines.join('\n') : '无匹配'; } catch (e) { ro.value = '正则错误：' + e.message; } return; } try { var re2 = new RegExp(p, flags), n = 0, ls = []; var mm2; if (flags.indexOf('g') >= 0) { while ((mm2 = re2.exec(t)) !== null) { if (n++ > 200) break; ls.push('@' + mm2.index + '  ' + mm2[0] + (mm2[1] !== undefined ? '  → ' + mm2[1] : '')); if (mm2.index === re2.lastIndex) re2.lastIndex++; } } else { mm2 = re2.exec(t); if (mm2) ls.push('@' + mm2.index + '  ' + mm2[0]); } ro.value = (n ? '匹配 ' + n + ' 处：\n' : '无匹配\n') + ls.join('\n'); } catch (e) { ro.value = '正则错误：' + e.message; } } function optChk(n) { var el = document.querySelector('[data-opt="' + n + '"]'); return el ? el.checked : false; } rp.addEventListener('input', run); ri.addEventListener('input', run); document.querySelectorAll('.text-tool .chk input').forEach(function (c) { c.addEventListener('change', run); }); run(); } }
  else if (W === 'font') { var ft = $('fontPreview'); if (ft) { var tx = $('text'), fa = $('family'); var map = { sans: 'system-ui,-apple-system,sans-serif', serif: 'Georgia,"Songti SC",serif', mono: 'ui-monospace,Menlo,monospace', brand: "'Space Grotesk',sans-serif" }; if (OP === 'pairing') { ft.innerHTML = '<div style="font-family:serif;font-size:22px">Aa 苍鹭 HeronTool 中文标题</div><div style="font-family:system-ui;font-size:15px;margin-top:8px">Aa The quick brown fox — 正文示例文本</div><div style="font-family:ui-monospace;font-size:13px;margin-top:8px">Aa 0 1 2 3 code snippet</div>'; } else { function run() { ft.textContent = tx.value || UI.preview_label; ft.style.fontFamily = map[fa.value] || map.sans; } if (tx) tx.addEventListener('input', run); if (fa) fa.addEventListener('change', run); run(); } } }
  else if (W === 'crop') { /* unchanged */ var cFile = $('cropFile'), cStage = $('cropStage'), cBtn = $('cropBtn'), cOut = $('cropOut'); if (cFile && cStage) { var cImg = null, sel = null, drag = null; cFile.addEventListener('change', function () { if (!cFile.files[0]) return; var fr = new FileReader(); fr.onload = function () { var im = new Image(); im.onload = function () { var maxW = cStage.clientWidth || 600, scale = Math.min(1, maxW / im.naturalWidth); cStage.innerHTML = ''; cStage.style.position = 'relative'; im.style.width = (im.naturalWidth * scale) + 'px'; im.style.display = 'block'; im.style.maxWidth = '100%'; cStage.appendChild(im); cImg = im; cImg._scale = scale; var box = document.createElement('div'); box.className = 'crop-box'; cStage.appendChild(box); sel = box; cStage.onmousedown = function (e) { var r = cStage.getBoundingClientRect(); drag = { sx: e.clientX - r.left, sy: e.clientY - r.top, mode: 'new' }; }; window.addEventListener('mousemove', cmove); window.addEventListener('mouseup', function () { drag = null; }); }; im.src = fr.result; }; fr.readAsDataURL(cFile.files[0]); }); function cmove(e) { if (!drag || !cImg) return; var r = cStage.getBoundingClientRect(); var x = Math.max(0, Math.min(e.clientX - r.left, r.width)), y = Math.max(0, Math.min(e.clientY - r.top, r.height)); if (drag.mode === 'new') { sel.style.left = Math.min(drag.sx, x) + 'px'; sel.style.top = Math.min(drag.sy, y) + 'px'; sel.style.width = Math.abs(x - drag.sx) + 'px'; sel.style.height = Math.abs(y - drag.sy) + 'px'; } } cBtn.addEventListener('click', function () { if (!cImg || !sel || !sel.style.width) { showToast(UI.copy_fail); return; } var s = cImg._scale, sx = parseFloat(sel.style.left) / s, sy = parseFloat(sel.style.top) / s, sw = parseFloat(sel.style.width) / s, sh = parseFloat(sel.style.height) / s; var cv = document.createElement('canvas'); cv.width = Math.max(1, Math.round(sw)); cv.height = Math.max(1, Math.round(sh)); cv.getContext('2d').drawImage(cImg, sx, sy, sw, sh, 0, 0, cv.width, cv.height); var url = cv.toDataURL('image/png'), name = (cFile.files[0].name || 'crop').replace(/\.[^.]+$/, '') + '-cropped.png'; cOut.innerHTML = '<img src="' + url + '" class="img-prev" alt=""><div class="tw-actions"><a class="btn-copy" href="' + url + '" download="' + name + '">' + UI.download + '</a></div>'; }); var ccl = $('cropClear'); if (ccl) ccl.addEventListener('click', function () { cStage.innerHTML = '<span class="crop-hint">' + DETAIL.labels.tip + '</span>'; cOut.innerHTML = ''; cFile.value = ''; }); } }
  else if (W === 'highlight') { /* unchanged */ var hI = $('hlInput'), hL = $('hlLang'), hO = $('hlOut'); if (hI && hO) { if (window.hljs) { function runHL() { var code = hI.value, lang = hL.value, html; try { html = lang === 'auto' ? hljs.highlightAuto(code).value : hljs.highlight(code, { language: lang }).value; } catch (e) { try { html = hljs.highlightAuto(code).value; } catch (e2) { html = esc(code); } } hO.innerHTML = html; hO.parentNode.classList.add('hl-rendered'); } var hlb = $('hlBtn'); if (hlb) hlb.addEventListener('click', runHL); hI.addEventListener('input', runHL); hL.addEventListener('change', runHL); runHL(); var hlc = $('hlCopy'); if (hlc) hlc.addEventListener('click', function () { copyText(hO.textContent); }); } else { hO.textContent = hI.value; } } }
  else if (W === 'prompt') { /* unchanged */ var pGen = $('pGen'), pRes = $('pResult'); if (pGen && pRes) { function gv(id) { return val(id); } pGen.addEventListener('click', function () { var role = gv('pRole').trim(), task = gv('pTask').trim(), ctx = gv('pCtx').trim(), con = gv('pCon').trim(), out = gv('pOut').trim(); var parts = []; if (role) parts.push('# 角色 (Role)\n' + role); if (task) parts.push('# 任务 (Task)\n' + task); if (ctx) parts.push('# 背景 (Context)\n' + ctx); if (con) parts.push('# 约束 (Constraints)\n' + con); if (out) parts.push('# 输出格式 (Output Format)\n' + out); pRes.value = parts.join('\n\n') || '请填写上方字段'; }); var pcp = $('pCopy'); if (pcp) pcp.addEventListener('click', function () { copyText(pRes.value); }); var pcl = $('pClear'); if (pcl) pcl.addEventListener('click', function () { ['pRole', 'pTask', 'pCtx', 'pCon', 'pOut'].forEach(function (id) { var e = $(id); if (e) e.value = ''; }); pRes.value = ''; }); } }
  else if (W === 'polish') { /* unchanged */ var pI = $('polInput'), pB = $('polBtn'), pO = $('polOut'); if (pI && pO) { function opt(n) { var e = document.querySelector('[data-opt="' + n + '"]'); return e ? e.checked : false; } function runPol() { var t = pI.value; if (opt('space')) t = t.replace(/[ \t]{2,}/g, ' ').replace(/ ?([,.;:!?]) ?/g, '$1 ').replace(/ +/g, ' ').replace(/\n{3,}/g, '\n\n').trim(); if (opt('case')) t = t.replace(/(^|[.!?。！？]\s+)([a-z])/g, function (m, p1, p2) { return p1 + p2.toUpperCase(); }); if (opt('filler')) { ['其实', '基本上', '说实话', '总的来说', '在我看来', '事实上', '可以说', '某种程度上', '个人觉得', '就是', '然后', '嗯', '那个'].forEach(function (w) { t = t.split(w).join(''); }); } if (opt('punct')) t = t.replace(/([，。！？；：])\1+/g, '$1').replace(/,/g, '，').replace(/\.(?=\S)/g, '。'); pO.value = t; } pB.addEventListener('click', runPol); pI.addEventListener('input', runPol); var pcp = $('polCopy'); if (pcp) pcp.addEventListener('click', function () { copyText(pO.value); }); var pcl = $('polClear'); if (pcl) pcl.addEventListener('click', function () { pI.value = ''; pO.value = ''; }); } }
  else if (W === 'icons') { /* unchanged */ var iS = $('iconSearch'), iG = $('iconGallery'), iC = $('iconCount'); if (iG) { fetch('/static/icons-tabler.json').then(function (r) { return r.json(); }).then(function (map) { var names = Object.keys(map); function render(q) { q = (q || '').toLowerCase(); var list = names.filter(function (n) { return !q || n.indexOf(q) >= 0; }); iC.textContent = list.length + ' ' + (DETAIL.labels.count || 'icons'); iG.innerHTML = list.map(function (n) { var svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + map[n] + '</svg>'; return '<button class="icon-card" data-name="' + n + '"><span class="ic">' + svg + '</span><span class="nm">' + n + '</span></button>'; }).join(''); iG.querySelectorAll('.icon-card').forEach(function (b) { b.addEventListener('click', function () { copyText('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + map[b.dataset.name] + '</svg>'); showToast(DETAIL.labels.copy + ' ✓'); }); }); } render(''); iS.addEventListener('input', function () { render(iS.value); }); }).catch(function () { iG.innerHTML = '<p>图标加载失败</p>'; }); } }
  else if (W === 'mockup') { var mU = $('mkUrl'), mF = $('mkFile'), mD = $('mkDev'), mS = $('mockupStage'); if (mS) { if (OP === 'logo') { mU.style.display = 'none'; mS.innerHTML = '<div class="mk-frame mk-logo"><div style="font-family:Space Grotesk;font-weight:700;font-size:34px;color:#2f5a35;display:flex;align-items:center;gap:10px"><svg viewBox="0 0 24 24" width="34" height="34" fill="#3e7c46"><path d="M12 3l-2 9 8-5-8 13 2-9-8 5z"/></svg> Logo</div></div>'; return; } if (OP === 'browser' && mD) mD.value = 'desktop'; if (OP === 'social' && mD) mD.value = 'phone'; function renderMk() { var dev = mD.value, cls = dev === 'phone' ? 'mk-phone' : dev === 'tablet' ? 'mk-tablet' : 'mk-desktop'; if (mF.files && mF.files[0]) { var fr = new FileReader(); fr.onload = function () { mS.innerHTML = '<div class="mk-frame ' + cls + '"><img src="' + fr.result + '"></div>'; }; fr.readAsDataURL(mF.files[0]); return; } var url = mU.value.trim(); if (url) { if (!/^https?:\/\//.test(url)) url = 'https://' + url; mS.innerHTML = '<div class="mk-frame ' + cls + '"><iframe src="' + esc(url) + '" sandbox="allow-scripts allow-same-origin" loading="lazy"></iframe></div>'; } else { mS.innerHTML = '<div class="mk-frame ' + cls + ' mk-empty">输入网址或上传截图</div>'; } } mU.addEventListener('input', renderMk); mD.addEventListener('change', renderMk); mF.addEventListener('change', renderMk); renderMk(); } }
  else if (W === 'currency') { /* unchanged */ var cA = $('curAmt'), cF = $('curFrom'), cT = $('curTo'), cR = $('curResult'); if (cA && cF && cT && DETAIL.currencies) { DETAIL.currencies.forEach(function (code) { var o1 = document.createElement('option'); o1.value = code; o1.textContent = code; cF.appendChild(o1); var o2 = document.createElement('option'); o2.value = code; o2.textContent = code; cT.appendChild(o2); }); cF.value = 'USD'; cT.value = 'CNY'; function runCur() { var amt = parseFloat(cA.value); if (isNaN(amt)) { cR.textContent = '—'; return; } var r = DETAIL.rates; cR.textContent = (amt * r[cT.value] / r[cF.value]).toLocaleString(undefined, { maximumFractionDigits: 4 }) + ' ' + cT.value; } ['input', 'change'].forEach(function (ev) { cA.addEventListener(ev, runCur); cF.addEventListener(ev, runCur); cT.addEventListener(ev, runCur); }); runCur(); } }
  else if (W === 'pdfmerge' || W === 'pdfsplit') { /* unchanged */ if (!window.pdfLib) { showToast(UI.copy_fail); } else if (W === 'pdfmerge') { var pf = $('pdfFiles'), pmb = $('pdfMergeBtn'), pmo = $('pdfMergeOut'); if (pf && pmb) pmb.addEventListener('click', function () { if (!pf.files.length) { showToast(UI.copy_fail); return; } pmo.innerHTML = '<div class="qr-loading">' + UI.process_hint + '</div>'; (async function () { try { var merged = await pdfLib.PDFDocument.create(); for (var fi = 0; fi < pf.files.length; fi++) { var buf = await pf.files[fi].arrayBuffer(); var doc = await pdfLib.PDFDocument.load(buf); var pages = await doc.copyPages(merged, doc.getPageIndices()); pages.forEach(function (p) { merged.addPage(p); }); } var bytes = await merged.save(); var blob = new Blob([bytes], { type: 'application/pdf' }); var url = URL.createObjectURL(blob); pmo.innerHTML = '<div class="tw-actions"><a class="btn-copy" href="' + url + '" download="merged.pdf">' + UI.download + '</a></div>'; } catch (e) { pmo.innerHTML = '<div class="qr-loading">' + L10N.pdfFail + '</div>'; } })(); }); } else if (W === 'pdfsplit') { var sf = $('pdfSplitFile'), ssb = $('pdfSplitBtn'), sso = $('pdfSplitOut'); if (sf && ssb) ssb.addEventListener('click', function () { if (!sf.files.length) { showToast(UI.copy_fail); return; } sso.innerHTML = '<div class="qr-loading">' + UI.process_hint + '</div>'; (async function () { try { var buf = await sf.files[0].arrayBuffer(); var doc = await pdfLib.PDFDocument.load(buf); var n = doc.getPageCount(); var links = ''; for (var i = 0; i < n; i++) { var nd = await pdfLib.PDFDocument.create(); var cp = await nd.copyPages(doc, [i]); nd.addPage(cp[0]); var bytes = await nd.save(); var blob = new Blob([bytes], { type: 'application/pdf' }); var url = URL.createObjectURL(blob); links += '<a class="btn-ghost" href="' + url + '" download="page-' + (i + 1) + '.pdf">第 ' + (i + 1) + ' 页</a> '; } sso.innerHTML = '<div class="rate-note">' + DETAIL.labels.pages + '：</div><div class="tw-actions">' + links + '</div>'; } catch (e) { sso.innerHTML = '<div class="qr-loading">' + L10N.pdfFail + '</div>'; } })(); }); } }
  else if (W === 'equation') { /* unchanged */ var eI = $('eqInput'), eB = $('eqBtn'), eO = $('eqOut'), eS = $('eqSteps'); if (eI && eO) { function evExpr(expr, x) { var s = expr.replace(/\^/g, '**').replace(/x/gi, '(' + x + ')'); s = s.replace(/([0-9)\]])(\s*)\(/g, '$1*$2('); if (/[^0-9+\-*/().\s*]/i.test(s)) throw new Error('非法字符'); return Function('"use strict";return (' + s + ')')(); } function fmtN(n) { var r = Math.round(n * 1e6) / 1e6; return (Math.abs(r - Math.round(r)) < 1e-6) ? String(Math.round(r)) : String(r); } function solveEq() { var eq = eI.value.trim(); if (!eq) { eO.textContent = '—'; eS.value = ''; return; } var parts = eq.split('='); if (parts.length !== 2) { eO.textContent = '请输入含 = 的方程'; eS.value = ''; return; } function f(x) { return evExpr(parts[0], x) - evExpr(parts[1], x); } var C0 = f(0), sumAB = f(1) - f(0), A = (f(2) - 2 * f(1) + f(0)) / 2, B = sumAB - A, sols = []; if (Math.abs(A) < 1e-9) { if (Math.abs(B) < 1e-9) { eO.textContent = Math.abs(C0) < 1e-9 ? '任意解（恒等式）' : '无解'; eS.value = ''; return; } sols.push('x = ' + fmtN(-C0 / B)); } else { var disc = B * B - 4 * A * C0; if (disc < 0) { eO.textContent = '无实数解'; eS.value = '判别式 < 0'; return; } var sq = Math.sqrt(disc); sols.push('x₁ = ' + fmtN((-B + sq) / (2 * A))); sols.push('x₂ = ' + fmtN((-B - sq) / (2 * A))); } eO.textContent = sols.join('，'); eS.value = '化简后：' + fmtN(A) + 'x² + ' + fmtN(B) + 'x + ' + fmtN(C0) + ' = 0\n解：\n' + sols.join('\n'); } eB.addEventListener('click', solveEq); eI.addEventListener('input', solveEq); var ecl = $('eqClear'); if (ecl) ecl.addEventListener('click', function () { eI.value = ''; eO.textContent = '—'; eS.value = ''; }); } }

  // =================== IMAGE FX PIPELINE ===================
  function fxProcess(op, src, out) {
    var img = new Image(); img.onload = function () {
      var w = img.width, h = img.height, cv = document.createElement('canvas'), cx = cv.getContext('2d');
      cv.width = w; cv.height = h;
      if (op === 'rotate') { var ang = (parseInt(val('angle'), 10) || 90) * Math.PI / 180; var c = Math.abs(Math.cos(ang)), s = Math.abs(Math.sin(ang)); cv.width = Math.ceil(w * c + h * s); cv.height = Math.ceil(h * c + w * s); cx.translate(cv.width / 2, cv.height / 2); cx.rotate(ang); cx.drawImage(img, -w / 2, -h / 2); }
      else if (op === 'flip') { if (val('dir') === 'v') { cv.height = h; cx.translate(0, h); cx.scale(1, -1); } else { cv.width = w; cx.translate(w, 0); cx.scale(-1, 1); } cx.drawImage(img, 0, 0); }
      else if (op === 'grayscale' || op === 'invert') { cx.drawImage(img, 0, 0); var id = cx.getImageData(0, 0, w, h), d = id.data; for (var i = 0; i < d.length; i += 4) { if (op === 'invert') { d[i] = 255 - d[i]; d[i + 1] = 255 - d[i + 1]; d[i + 2] = 255 - d[i + 2]; } else { var g = (d[i] * 0.299 + d[i + 1] * 0.587 + d[i + 2] * 0.114); d[i] = d[i + 1] = d[i + 2] = g; } } cx.putImageData(id, 0, 0); }
      else if (op === 'border') { var bw = parseInt(val('bdW'), 10) || 20, bc = val('bdColor') || '#fff'; cx.fillStyle = bc; cx.fillRect(0, 0, w, h); cx.drawImage(img, bw, bw, w - bw * 2, h - bw * 2); }
      else if (op === 'corner') { var r = parseInt(val('cr'), 10) || 30; cx.beginPath(); cx.moveTo(r, 0); cx.arcTo(w, 0, w, h, r); cx.arcTo(w, h, 0, h, r); cx.arcTo(0, h, 0, 0, r); cx.arcTo(0, 0, w, 0, r); cx.closePath(); cx.clip(); cx.drawImage(img, 0, 0); }
      else if (op === 'overlay') { var ov = parseInt(val('ovOpacity'), 10) || 50, oc = val('ovColor') || '#000'; cx.drawImage(img, 0, 0); cx.fillStyle = oc; cx.globalAlpha = ov / 100; cx.fillRect(0, 0, w, h); cx.globalAlpha = 1; }
      else if (op === 'contrast') { var ct = parseInt(val('ct'), 10) || 0, br = parseInt(val('br'), 10) || 0; cx.drawImage(img, 0, 0); var id2 = cx.getImageData(0, 0, w, h), d2 = id2.data, f = (259 * (ct + 255)) / (255 * (259 - ct)); for (var j = 0; j < d2.length; j += 4) { d2[j] = clamp(f * (d2[j] - 128) + 128 + br, 0, 255); d2[j + 1] = clamp(f * (d2[j + 1] - 128) + 128 + br, 0, 255); d2[j + 2] = clamp(f * (d2[j + 2] - 128) + 128 + br, 0, 255); } cx.putImageData(id2, 0, 0); }
      else if (op === 'watermark') { var wt = val('wmText') || 'HeronTool', wsz = parseInt(val('wmSize'), 10) || 28, wo = parseInt(val('wmOpacity'), 10) || 40; cx.drawImage(img, 0, 0); cx.font = wsz + 'px sans-serif'; cx.fillStyle = 'rgba(255,255,255,' + (wo / 100) + ')'; cx.textBaseline = 'bottom'; var tw = cx.measureText(wt).width; var x = w - tw - 16, y = h - 16; if (x < 0) x = 16; cx.fillText(wt, x, y); cx.fillStyle = 'rgba(0,0,0,' + (wo / 100) + ')'; cx.fillText(wt, x + 1, y + 1); }
      else if (op === 'favicon') { var sz = parseInt(val('fcSize'), 10) || 64; cv.width = sz; cv.height = sz; var side = Math.min(w, h); cx.drawImage(img, (w - side) / 2, (h - side) / 2, side, side, 0, 0, sz, sz); }
      else if (op === 'ascii') { var aw = parseInt(val('acw'), 10) || 60; var sc = document.createElement('canvas'); sc.width = aw; sc.height = Math.max(1, Math.round(aw * h / w / 2)); var sx = sc.getContext('2d'); sx.drawImage(img, 0, 0, aw, sc.height); var sd = sx.getImageData(0, 0, aw, sc.height).data; var chars = ' .:-=+*#%@', lines = []; for (var y = 0; y < sc.height; y++) { var line = ''; for (var x = 0; x < aw; x++) { var idx = (y * aw + x) * 4; var lum = (sd[idx] * 0.299 + sd[idx + 1] * 0.587 + sd[idx + 2] * 0.114); line += chars[Math.min(9, Math.floor(lum / 256 * 10))]; } lines.push(line); } out.innerHTML = '<pre style="font-family:monospace;line-height:1;background:#0b2b1d;color:#9fe6a0;padding:12px;border-radius:8px;overflow:auto;white-space:pre">' + esc(lines.join('\n')) + '</pre>'; return; }
      else if (op === 'exif') { out.innerHTML = '<div class="rate-note">浏览器无法直接读取原始 EXIF。图片已加载：' + w + ' × ' + h + ' px</div><img src="' + src + '" class="img-prev" alt="">'; return; }
      else { cx.drawImage(img, 0, 0); }
      var url = cv.toDataURL('image/png'), name = (fxFile.files[0].name || 'image').replace(/\.[^.]+$/, '') + '-' + op + '.png';
      out.innerHTML = '<img src="' + url + '" class="img-prev" alt=""><div class="tw-actions"><a class="btn-copy" href="' + url + '" download="' + name + '">' + UI.download + '</a></div>';
    };
    img.onerror = function () { out.innerHTML = '<p style="color:#c0392b">图片加载失败</p>'; };
    img.src = src;
  }
  function fxCollage(files, out) {
    var n = files.length, cols = clamp(parseInt(val('cols'), 10) || 2, 1, 8), gap = parseInt(val('gap'), 10) || 8;
    var loaded = 0, imgs = [];
    Array.prototype.forEach.call(files, function (f, i) { var im = new Image(); im.onload = function () { imgs[i] = im; if (++loaded === n) draw(); }; im.onerror = function () { imgs[i] = null; if (++loaded === n) draw(); }; im.src = URL.createObjectURL(f); });
    function draw() { var vw = 600, cell = (vw - gap * (cols - 1)) / cols, rows = Math.ceil(n / cols), vh = rows * cell + gap * (rows - 1); var cv = document.createElement('canvas'); cv.width = vw; cv.height = vh; var cx = cv.getContext('2d'); imgs.forEach(function (im, i) { if (!im) return; var x = (i % cols) * (cell + gap), y = Math.floor(i / cols) * (cell + gap); var r = Math.min(im.width, im.height); cx.drawImage(im, (im.width - r) / 2, (im.height - r) / 2, r, r, x, y, cell, cell); }); var url = cv.toDataURL('image/png'); out.innerHTML = '<img src="' + url + '" class="img-prev" alt=""><div class="tw-actions"><a class="btn-copy" href="' + url + '" download="collage.png">' + UI.download + '</a></div>'; }
  }
})();

