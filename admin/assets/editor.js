/* YeCha 后台 双模式编辑器：Markdown ↔ 富文本（所见即所得）、分屏预览、工具栏、上传
 * build: 2026-09-10 fix1 —— 修复富文本→Markdown(node2md) 丢失全部正文文字的缺陷：
 *   1) 递归进入子节点时未处理文本节点（Text.childNodes 为空 → 文字被丢光，只剩 ### 空标题）；
 *   2) 标题层级偏移（md2html 误把 ## 渲染成 h3，回写后层级漂移）；
 *   3) Chrome contenteditable 回车生成 <div> 未按块级处理；新增表格双向转换；
 *   4) 保存/切回 Markdown 时增加“文字归零”防呆拦截，避免再次静默清空正文。
 */
(function () {
  'use strict';
  const CSRF = (window.__YC || {}).csrf || '';
  const BUILD = 'fix3-20260910-标签关键词';
  let mode = 'md';

  const mdIn = document.getElementById('mdIn');
  const richIn = document.getElementById('richIn');
  const prev = document.getElementById('edPrev');
  const area = document.getElementById('edArea');
  const contentValue = document.getElementById('contentValue');
  if (!mdIn) return;

  const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  /* ---------- “有没有真实文字”判定（用于防呆拦截） ---------- */
  function visibleLen(s) {
    return String(s == null ? '' : s).replace(/[\s#*>`~_|\[\]()!.\-=+:"'\\/]/g, '').length;
  }

  /* ---------- Markdown 渲染（客户端预览，与服务器侧一致的最小子集） ---------- */
  function md2html(src) {
    let out = '', lines = String(src).split('\n'), inCode = false, buf = [];
    const cells = (line, tag) => {
      let s = line.trim().replace(/^\|/, '').replace(/\|$/, '');
      return s.split('|').map(c => '<' + tag + '>' + inline(c.trim()) + '</' + tag + '>').join('');
    };
    const isSep = line => /^\s*\|?[\s:|-]*-[\s:|-]*\|?\s*$/.test(line) && line.indexOf('-') >= 0;
    for (let i = 0; i < lines.length; i++) {
      const t = lines[i].trim();
      if (t.indexOf('```') === 0) {
        if (inCode) { out += '<pre><code>' + esc(buf.join('\n')) + '</code></pre>'; buf = []; }
        inCode = !inCode; continue;
      }
      if (inCode) { buf.push(lines[i]); continue; }
      if (!t) continue;
      let l = esc(lines[i]);
      let m;
      if ((m = l.match(/^(#{1,6})\s+(.*)$/))) {
        const lv = m[1].length;                       // 层级如实，与服务器侧 Markdown.php 一致
        out += '<h' + lv + '>' + inline(m[2]) + '</h' + lv + '>';
      }
      else if (l.indexOf('&gt; ') === 0) { out += '<blockquote>' + inline(l.slice(5)) + '</blockquote>'; }
      else if (l === '---' || l === '***') { out += '<hr>'; }
      else if (t.indexOf('|') >= 0 && i + 1 < lines.length && isSep(lines[i + 1])) {
        out += '<table><thead><tr>' + cells(lines[i], 'th') + '</tr></thead><tbody>';
        i += 2;
        while (i < lines.length && lines[i].trim().indexOf('|') >= 0) { out += '<tr>' + cells(lines[i], 'td') + '</tr>'; i++; }
        i--;
        out += '</tbody></table>';
      }
      else if ((m = l.match(/^\s*([-*+])\s+(.*)$/))) { out += '<ul><li>' + inline(m[2]) + '</li></ul>'; }
      else if ((m = l.match(/^\s*\d+\.\s+(.*)$/))) { out += '<ol><li>' + inline(m[1]) + '</li></ol>'; }
      else { out += '<p>' + inline(l) + '</p>'; }
    }
    if (inCode) out += '<pre><code>' + esc(buf.join('\n')) + '</code></pre>';
    return out;
  }
  function inline(s) {
    s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
    s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    s = s.replace(/~~(.+?)~~/g, '<del>$1</del>');
    return s;
  }

  /* ---------- HTML → Markdown（“转换我自己”，含 block + 行内） ---------- */
  function node2md(node) {
    if (!node) return '';
    if (node.nodeType === 3) return node.textContent || '';   // 文本节点：直接取文字（旧版在此丢字）
    if (node.nodeType !== 1) return '';
    const tag = (node.tagName || '').toLowerCase();
    const kids = () => Array.from(node.childNodes).map(node2md).join('');
    const txt = () => node.textContent || '';
    const childEls = () => Array.from(node.children || node.childNodes || []);
    if (tag === 'script' || tag === 'style' || tag === 'noscript') return '';
    if (tag === 'p' || tag === 'div' || tag === 'section' || tag === 'article' || tag === 'main'
      || tag === 'header' || tag === 'footer' || tag === 'figure' || tag === 'figcaption') return kids() + '\n\n';
    if (/^h[1-6]$/.test(tag)) return '#'.repeat(+tag[1]) + ' ' + kids() + '\n\n';
    if (tag === 'blockquote') return '> ' + kids().replace(/\n+/g, '\n> ') + '\n\n';
    if (tag === 'pre') return '```\n' + txt().replace(/\n+$/, '') + '\n```\n\n';
    if (tag === 'code') return '`' + txt() + '`';
    if (tag === 'strong' || tag === 'b') return '**' + kids() + '**';
    if (tag === 'em' || tag === 'i') return '*' + kids() + '*';
    if (tag === 'del' || tag === 's' || tag === 'strike') return '~~' + kids() + '~~';
    if (tag === 'a') return '[' + kids() + '](' + (node.getAttribute('href') || '') + ')';
    if (tag === 'img') return '![' + (node.getAttribute('alt') || '') + '](' + (node.getAttribute('src') || '') + ')';
    if (tag === 'br') return '  \n';
    if (tag === 'hr') return '---\n\n';
    if (tag === 'ul' || tag === 'ol') {
      let i = 0, out = '';
      childEls().forEach(li => {
        if ((li.tagName || '').toLowerCase() !== 'li') return;
        i++;
        const one = node2md(li).trim().replace(/\n+/g, ' ');
        if (one) out += (tag === 'ul' ? '- ' : i + '. ') + one + '\n';
      });
      return out ? out + '\n' : '';
    }
    if (tag === 'li') return kids();
    if (tag === 'table') {
      const rows = node.querySelectorAll ? Array.from(node.querySelectorAll('tr')) : [];
      if (!rows.length) return kids() + '\n\n';
      const cellTxt = tr => Array.from(tr.children).map(td => node2md(td).trim().replace(/\n+/g, ' ').replace(/\|/g, '\\|'));
      const first = cellTxt(rows[0]);
      let out = '| ' + first.join(' | ') + ' |\n| ' + first.map(() => '---').join(' | ') + ' |\n';
      for (let i = 1; i < rows.length; i++) { out += '| ' + cellTxt(rows[i]).join(' | ') + ' |\n'; }
      return out + '\n';
    }
    if (tag === 'tr' || tag === 'td' || tag === 'th' || tag === 'tbody' || tag === 'thead') return kids();
    if (tag === 'span' || tag === 'font' || tag === 'u' || tag === 'small' || tag === 'mark'
      || tag === 'sup' || tag === 'sub' || tag === 'label' || tag === 'time') {
      const st = node.style || {};
      let t = kids();
      if (t && /bold|^[6-9]00$/.test(String(st.fontWeight || ''))) t = '**' + t + '**';
      if (t && st.fontStyle === 'italic') t = '*' + t + '*';
      return t;
    }
    return kids();
  }

  /* 富文本 → Markdown，带防呆：DOM 里有文字而转换后一个字不剩时返回 null */
  function richToMd() {
    const md = node2md(richIn);
    const domText = (richIn.innerText != null ? richIn.innerText : richIn.textContent) || '';
    if (visibleLen(domText) > 0 && visibleLen(md) === 0) return null;
    return md;
  }
  const LOSS_MSG = '正文转换异常：富文本里的文字没有转成 Markdown，为避免丢失内容已取消本次操作。\n'
    + '请先用 Ctrl+A / Ctrl+C 把正文复制到记事本或 Word 备份，再切回 Markdown 模式粘贴保存（编辑器版本 ' + BUILD + '）。';

  /* ---------- 提交：content 同步为 Markdown（须最先绑定：页面无标签框等元素时，后续初始化报错也不影响正文保存） ---------- */
  const postForm = document.getElementById('postForm');
  if (contentValue && postForm) {
    postForm.addEventListener('submit', ev => {
      let md = mdIn.value;
      if (mode === 'rich') {
        const conv = richToMd();
        if (conv === null) { ev.preventDefault(); alert(LOSS_MSG); return; }
        md = conv;
        mdIn.value = md;
      }
      contentValue.value = md;
    });
  }

  /* ---------- Ctrl/Cmd + S 保存（写作者习惯） ---------- */
  document.addEventListener('keydown', ev => {
    if ((ev.ctrlKey || ev.metaKey) && (ev.key === 's' || ev.key === 'S')) {
      ev.preventDefault();
      if (postForm) { postForm.requestSubmit ? postForm.requestSubmit() : postForm.submit(); }
    }
  });

  /* 预览默认关闭：关闭时不做渲染（省开销），打开时立刻渲染一次 */
  function prevOn() { return area.classList.contains('split'); }
  function render(force) {
    const cc = document.getElementById('charCount');
    if (cc) {
      const n = mdIn.value.length;
      cc.textContent = n === 0 ? '还没有正文' : ('正文 ' + n.toLocaleString() + ' 字符');
    }
    if (force || prevOn()) { prev.innerHTML = md2html(mdIn.value); }
  }
  mdIn.addEventListener('input', () => render(false));

  /* ---------- 预览开关 ---------- */
  const btnPrev = document.getElementById('btnPrev');
  if (btnPrev) {
    btnPrev.addEventListener('click', () => {
      const on = area.classList.toggle('split');
      btnPrev.classList.toggle('on', on);
      btnPrev.setAttribute('aria-pressed', on ? 'true' : 'false');
      render(on);
    });
  }

  /* ---------- 发布设置抽屉（默认收起；新建文章默认展开） ---------- */
  const edCfg = document.getElementById('edCfg');
  const edGrid = document.getElementById('edGrid');
  const edCfgBtn = document.getElementById('edCfgBtn');
  const edCfgX = document.getElementById('edCfgX');
  if (edCfg && edGrid) {
    const paint = (open) => {
      edCfg.hidden = !open;
      edGrid.classList.toggle('open', open);
      if (edCfgBtn) { edCfgBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    };
    let saved = null;
    try { saved = localStorage.getItem('yc-ed-cfg'); } catch (e) {}
    if (saved === '1' || saved === '0') { paint(saved === '1'); }
    const set = (open) => { paint(open); try { localStorage.setItem('yc-ed-cfg', open ? '1' : '0'); } catch (e) {} };
    if (edCfgBtn) { edCfgBtn.addEventListener('click', () => set(!edGrid.classList.contains('open'))); }
    if (edCfgX) { edCfgX.addEventListener('click', () => set(false)); }
  }

  /* ---------- 模式切换 ---------- */
  function setMode(m) {
    mode = m;
    const btns = document.querySelectorAll('#edMode button');
    if (btns[0]) btns[0].classList.toggle('active', m === 'md');
    if (btns[1]) btns[1].classList.toggle('active', m === 'rich');
    if (m === 'rich') {
      if (!richIn.innerHTML) richIn.innerHTML = md2html(mdIn.value);
      mdIn.style.display = 'none'; richIn.style.display = 'block';
    } else {
      const conv = richToMd();
      if (conv === null) { alert(LOSS_MSG + '\n（本次仍停留在富文本模式）'); mode = 'rich'; if (btns[0]) btns[0].classList.remove('active'); if (btns[1]) btns[1].classList.add('active'); return; }
      mdIn.value = conv;
      mdIn.style.display = 'block'; richIn.style.display = 'none';
      render();
    }
  }
  document.querySelectorAll('#edMode button').forEach(b => b.addEventListener('click', () => setMode(b.dataset.mode)));

  /* ---------- 工具栏（Markdown 语法插入） ---------- */
  function insertAt(prefix, suffix, sample) {
    const start = mdIn.selectionStart, end = mdIn.selectionEnd, sel = mdIn.value.slice(start, end) || sample;
    const rep = prefix + sel + suffix;
    mdIn.setRangeText(rep, start, end, 'end');
    render();
  }
  document.querySelectorAll('#toolbar [data-cmd]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (mode !== 'md') setMode('md');
      const c = btn.dataset.cmd;
      const map = {
        h2: ['## ', '', '二级标题'], h3: ['### ', '', '三级标题'],
        bold: ['**', '**', '加粗文字'], italic: ['*', '*', '斜体文字'], del: ['~~', '~~', '删除文字'],
        code: ['`', '`', 'code'], quote: ['\n> ', '', '引用内容'], ul: ['\n- ', '', '列表项'], ol: ['\n1. ', '', '列表项'],
        hr: ['\n---\n\n', '', ''], link: ['[', '](https://)', '链接文字'],
        img: ['![', '](/uploads/图.png)', '图片说明'], table: ['\n| 列1 | 列2 |\n| --- | --- |\n| a | b |\n', '', '']
      };
      if (map[c]) insertAt(map[c][0], map[c][1], map[c][2]);
    });
  });

  /* ---------- 上传 ---------- */
  const upFile = document.getElementById('upFile');
  const upMsg = document.getElementById('upMsg');
  document.getElementById('btnUpload').addEventListener('click', () => upFile.click());
  upFile.addEventListener('change', () => {
    const f = upFile.files[0];
    if (!f) return;
    const fd = new FormData();
    fd.append('file', f); fd.append('_csrf', CSRF); fd.append('do', 'upload');
    upMsg.textContent = '上传中…';
    fetch('/admin/?s=posts&do=upload', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(j => {
        if (!j.ok) { upMsg.textContent = '失败：' + (j.error || ''); return; }
        upMsg.textContent = '已上传：' + j.name;
        if (j.is_img) {
          insertAt('', '', '');
          const md = mdIn.value;
          const pre = md.slice(0, mdIn.selectionStart), post = md.slice(mdIn.selectionEnd);
          const add = '![图](' + j.url + ')\n\n';
          mdIn.value = pre + add + post;
        } else {
          const md = mdIn.value;
          const pre = md.slice(0, mdIn.selectionStart), post = md.slice(mdIn.selectionEnd);
          mdIn.value = pre + '[' + j.name + '](' + j.url + ')\n\n' + post;
        }
        render();
      })
      .catch(err => { upMsg.textContent = '上传异常：' + err; });
  });

  /* ---------- 标签 / 关键词：粘贴一整串也能自动拆成一个个 ----------
     分隔符：中英文逗号、顿号、分号、竖线、换行、制表符。
     标签：输入或粘贴即拆成 chips（不再要求必须按回车）；提交前把输入框里没确认的文字也一并算上。
     关键词：失焦与提交时统一拆开、去空白、去重（ASCII 不区分大小写），并用逗号 + 空格重排。 */
  const tagNames = (window.__YC && window.__YC.tags) || [];
  const tagChips = document.getElementById('tagChips');
  const tagHidden = document.getElementById('tagsHidden');
  const tagInput = document.getElementById('tagInput');
  const kwInput = document.getElementById('edKeywords');
  const kwCount = document.getElementById('kwCount');
  const SPLIT_RE = /[,，、;；|\r\n\t]+/;

  function splitItems(s) {
    return String(s == null ? '' : s).split(SPLIT_RE)
      .map(x => x.replace(/\s+/g, ' ').trim())
      .filter(x => x !== '');
  }
  function dedupeList(items) {
    const seen = {}, out = [];
    items.forEach(x => { const k = x.toLowerCase(); if (!seen[k]) { seen[k] = 1; out.push(x); } });
    return out;
  }
  function normKw(s) { return dedupeList(splitItems(s)).join(', '); }
  /** 读剪贴板纯文本（兼容两种 clipboardData 形态） */
  function clipText(ev) {
    const cd = ev.clipboardData || (typeof window !== 'undefined' ? window.clipboardData : null);
    if (!cd || typeof cd.getData !== 'function') { return ''; }
    try { return String(cd.getData('text') || ''); } catch (e) { return ''; }
  }

  function paintTags() {
    if (!tagChips) { if (tagHidden) { tagHidden.value = tagNames.join(','); } return; }
    tagChips.innerHTML = '';
    tagNames.forEach(t => {
      const sp = document.createElement('span');
      sp.className = 'chip';
      sp.textContent = t;
      const x = document.createElement('b'); x.textContent = '×';
      x.addEventListener('click', () => { tagNames.splice(tagNames.indexOf(t), 1); paintTags(); });
      sp.appendChild(x);
      tagChips.appendChild(sp);
    });
    if (tagHidden) { tagHidden.value = tagNames.join(','); }
    paintKw();
  }
  /** 把一串（可含多种分隔符）拆开后逐个加入标签，重复的忽略 */
  function addTags(str) {
    const items = splitItems(str);
    if (!items.length) { paintTags(); return; }
    const low = tagNames.map(t => t.toLowerCase());
    items.forEach(t => {
      if (t.length > 40) { t = t.slice(0, 40); }
      const k = t.toLowerCase();
      if (low.indexOf(k) < 0) { tagNames.push(t); low.push(k); }
    });
    paintTags();
  }
  function flushTagInput() {
    if (tagInput && tagInput.value.trim() !== '') { const v = tagInput.value; tagInput.value = ''; addTags(v); }
  }

  function paintKw() {
    if (!kwInput) { return; }
    const n = splitItems(kwInput.value).length;
    if (kwCount) { kwCount.textContent = n ? '· ' + n + ' 个' : '· 空'; }
  }

  if (tagInput) {
    /* input 事件同时覆盖「敲了逗号」和「粘贴单行」两种情况 → 立即拆成 chips */
    tagInput.addEventListener('input', () => {
      if (SPLIT_RE.test(tagInput.value)) { const v = tagInput.value; tagInput.value = ''; addTags(v); }
    });
    tagInput.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); flushTagInput(); }
    });
    tagInput.addEventListener('blur', flushTagInput);
    /* 多行粘贴：<input> 的 value 会被浏览器吃掉换行，必须在 paste 里读剪贴板原文，否则整串会粘成一行 */
    tagInput.addEventListener('paste', ev => {
      const txt = clipText(ev);
      if (!txt) { return; }
      const raw = txt.replace(/\r\n?/g, '\n');
      if (!SPLIT_RE.test(raw)) { return; }        // 没分隔符 → 走默认插入 + input 事件
      ev.preventDefault();
      const head = tagInput.value.trim();
      tagInput.value = '';
      addTags((head !== '' ? head + ',' : '') + raw);
    });
    paintTags();
  }
  if (kwInput) {
    kwInput.addEventListener('blur', () => { kwInput.value = normKw(kwInput.value); paintKw(); });
    kwInput.addEventListener('input', paintKw);
    /* 关键词同理：多行粘贴按原文拆（input 里读不到换行） */
    kwInput.addEventListener('paste', ev => {
      const txt = clipText(ev);
      if (!txt || !/[\r\n]/.test(txt)) { return; }
      ev.preventDefault();
      const head = kwInput.value.trim();
      kwInput.value = normKw((head !== '' ? head + ',' : '') + txt);
      paintKw();
    });
    paintKw();
  }
  const kwFromTags = document.getElementById('kwFromTags');
  if (kwFromTags) {
    kwFromTags.addEventListener('click', () => {
      if (!kwInput) { return; }
      flushTagInput();
      kwInput.value = normKw(tagNames.join(', '));
      paintKw();
    });
  }
  /* 提交前兜底：输入框里还留着的文字也算数（否则这串会被丢掉，正是之前的问题） */
  if (postForm) {
    postForm.addEventListener('submit', () => {
      flushTagInput();
      if (kwInput) { kwInput.value = normKw(kwInput.value); paintKw(); }
    });
  }

  /* ---------- 状态联动定时时间 ---------- */
  const statusSel = document.getElementById('statusSel');
  const schedRow = document.getElementById('schedRow');
  function syncStatus() {
    if (schedRow && statusSel) schedRow.style.display = statusSel.value === 'scheduled' ? 'block' : 'none';
  }
  if (statusSel) { statusSel.addEventListener('change', syncStatus); syncStatus(); }

  /* ---------- slug 实时预览 ---------- */
  const slugIn = document.getElementById('edSlug');
  const slugLive = document.getElementById('slugLive');
  if (slugIn && slugLive) {
    slugIn.addEventListener('input', () => {
      const s = slugIn.value.trim();
      slugLive.textContent = s ? '/zh/' + s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '.html' : '';
    });
  }

  render();
  window.__YC_EDITOR_BUILD = BUILD;
})();
