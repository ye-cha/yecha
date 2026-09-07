/* YeCha 后台 双模式编辑器：Markdown ↔ 富文本（所见即所得）、分屏预览、工具栏、上传 */
(function () {
  'use strict';
  const CSRF = (window.__YC || {}).csrf || '';
  let mode = 'md';

  const mdIn = document.getElementById('mdIn');
  const richIn = document.getElementById('richIn');
  const prev = document.getElementById('edPrev');
  const area = document.getElementById('edArea');
  const contentValue = document.getElementById('contentValue');
  if (!mdIn) return;

  const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  /* ---------- Markdown 渲染（客户端预览，与服务器侧一致的最小子集） ---------- */
  function md2html(src) {
    let out = '', lines = String(src).split('\n'), inCode = false, buf = [];
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
      if ((m = l.match(/^(#{1,4})\s+(.*)$/))) { out += '<h' + (m[1].length + 1) + '>' + inline(m[2]) + '</h' + (m[1].length + 1) + '>'; }
      else if (l.indexOf('&gt; ') === 0) { out += '<blockquote>' + inline(l.slice(5)) + '</blockquote>'; }
      else if (l === '---' || l === '***') { out += '<hr>'; }
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

  /* ---------- HTML → Markdown（富文本切回时，block 级最小子集） ---------- */
  function node2md(el) {
    let out = '';
    el.childNodes.forEach(n => {
      if (n.nodeType === 3) { out += n.textContent; return; }
      if (n.nodeType !== 1) return;
      const tag = n.tagName.toLowerCase();
      const inner = () => Array.from(n.childNodes).map(c => node2md(c)).join('');
      if (tag === 'p') out += inner() + '\n\n';
      else if (tag === 'h1' || tag === 'h2' || tag === 'h3' || tag === 'h4') {
        out += '#'.repeat(+tag[1]) + ' ' + inner() + '\n\n';
      } else if (tag === 'blockquote') out += '> ' + inner().replace(/\n+/g, '\n> ') + '\n\n';
      else if (tag === 'pre') { out += '```\n' + n.textContent + '\n```\n\n'; }
      else if (tag === 'code') out += '`' + n.textContent + '`';
      else if (tag === 'ul') { n.querySelectorAll && n.querySelectorAll(':scope > li').forEach(li => { out += '- ' + li.textContent + '\n'; }); out += '\n'; }
      else if (tag === 'ol') { n.querySelectorAll && n.querySelectorAll(':scope > li').forEach(li => { out += '1. ' + li.textContent + '\n'; }); out += '\n'; }
      else if (tag === 'li') out += inner();
      else if (tag === 'strong' || tag === 'b') out += '**' + inner() + '**';
      else if (tag === 'em' || tag === 'i') out += '*' + inner() + '*';
      else if (tag === 'del' || tag === 's') out += '~~' + inner() + '~~';
      else if (tag === 'a') out += '[' + inner() + '](' + (n.getAttribute('href') || '') + ')';
      else if (tag === 'img') out += '![' + (n.getAttribute('alt') || '') + '](' + (n.getAttribute('src') || '') + ')';
      else if (tag === 'br') out += '  \n';
      else if (tag === 'hr') out += '---\n\n';
      else out += inner();
    });
    return out;
  }

  function render() {
    prev.innerHTML = md2html(mdIn.value);
    const len = mdIn.value.length;
    const cc = document.getElementById('charCount');
    if (cc) cc.textContent = '字符：' + len + '（存 Markdown；富文本所见即所得编辑后转存 Markdown）';
  }
  mdIn.addEventListener('input', render);

  /* ---------- 提交：content 同步为 Markdown（须最先绑定：页面无标签框等元素时，后续初始化报错也不影响正文保存） ---------- */
  const postForm = document.getElementById('postForm');
  if (contentValue && postForm) {
    postForm.addEventListener('submit', () => {
      if (mode === 'rich') mdIn.value = node2md(richIn);
      contentValue.value = mdIn.value;
    });
  }

  /* ---------- 模式切换 ---------- */
  function setMode(m) {
    mode = m;
    const btns = document.querySelectorAll('#edMode button');
    btns[0].classList.toggle('active', m === 'md');
    btns[1].classList.toggle('active', m === 'rich');
    if (m === 'rich') {
      if (!richIn.innerHTML) richIn.innerHTML = md2html(mdIn.value);
      mdIn.style.display = 'none'; richIn.style.display = 'block';
    } else {
      mdIn.value = node2md(richIn);
      mdIn.style.display = 'block'; richIn.style.display = 'none';
      render();
    }
  }
  document.querySelectorAll('#edMode button').forEach(b => b.addEventListener('click', () => setMode(b.dataset.mode)));

  document.getElementById('btnSplit').addEventListener('click', function () {
    area.classList.toggle('split');
    this.classList.toggle('btn-primary');
    render();
  });

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

  /* ---------- 标签 chips（仅文章有；页面无 tagInput/tagChips/tagsHidden，需判空） ---------- */
  const tagNames = (window.__YC && window.__YC.tags) || [];
  const tagChips = document.getElementById('tagChips');
  const tagHidden = document.getElementById('tagsHidden');
  const tagInput = document.getElementById('tagInput');
  function paintTags() {
    if (!tagChips) return;
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
    if (tagHidden) tagHidden.value = tagNames.join(',');
  }
  if (tagInput) {
    tagInput.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        const v = tagInput.value.trim();
        if (v && tagNames.indexOf(v) < 0) tagNames.push(v);
        tagInput.value = ''; paintTags();
      }
    });
    paintTags();
  }

  /* ---------- 状态联动定时时间 ---------- */
  const statusSel = document.getElementById('statusSel');
  const schedRow = document.getElementById('schedRow');
  function syncStatus() {
    schedRow.style.display = statusSel.value === 'scheduled' ? 'block' : 'none';
  }
  statusSel.addEventListener('change', syncStatus); syncStatus();

  /* ---------- slug 实时预览 ---------- */
  const slugIn = document.getElementById('edSlug');
  const slugLive = document.getElementById('slugLive');
  slugIn.addEventListener('input', () => {
    const s = slugIn.value.trim();
    slugLive.textContent = s ? '/zh/' + s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '.html' : '';
  });

  render();
})();
