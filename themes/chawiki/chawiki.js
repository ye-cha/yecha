/* ============================================================
   ChaWiki · 词条正文增强（零依赖，原生 JS）
   ① 灯箱：点击 .prose-wiki 内的 portal-img / figure img 放大（.lb-backdrop 抄自旧站）
   ② 图片懒性兜底：加载失败给 .img-miss（layout 已有全局处理，此处补 alt 提示）
   ③ 正文内标题锚点：为 h3 生成 id，便于右侧目录/分享链接
   不做任何内容渲染 —— 正文永远由服务端输出（SEO-CONVENTIONS 第 4 条）
   ============================================================ */
(function () {
  'use strict';

  /* ---------- ① 灯箱 ---------- */
  function buildLightbox() {
    var lb = document.createElement('div');
    lb.className = 'lb-backdrop';
    lb.setAttribute('role', 'dialog');
    lb.setAttribute('aria-modal', 'true');
    lb.setAttribute('aria-label', '图片预览');
    var img = document.createElement('img');
    img.alt = '';
    lb.appendChild(img);
    lb.addEventListener('click', function () { close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
    document.body.appendChild(lb);
    return { el: lb, img: img };
  }

  var LB = null;
  function open(src, alt) {
    if (!LB) { LB = buildLightbox(); }
    LB.img.src = src;
    LB.img.alt = alt || '';
    LB.el.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function close() {
    if (!LB) { return; }
    LB.el.classList.remove('open');
    LB.img.removeAttribute('src');
    document.body.style.overflow = '';
  }

  function initLightbox() {
    var scope = document.getElementById('articleBody') || document.querySelector('.prose-wiki');
    if (!scope) { return; }
    scope.addEventListener('click', function (ev) {
      var t = ev.target;
      if (!t || t.tagName !== 'IMG') { return; }
      if (t.closest('a')) { return; }  // 图片本身是链接（旧站有这种写法）→ 跟随链接
      ev.preventDefault();
      open(t.currentSrc || t.src, t.getAttribute('alt') || '');
    });
    /* 键盘可达：图片包一层 role=button 语义 */
    var imgs = scope.querySelectorAll('img');
    for (var i = 0; i < imgs.length; i++) {
      imgs[i].setAttribute('tabindex', '0');
      imgs[i].setAttribute('role', 'button');
      imgs[i].addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.click(); }
      });
    }
  }

  /* ---------- ③ 正文内标题锚点（h3 无 id 时按序生成） ---------- */
  function initHeadingAnchors() {
    var scope = document.getElementById('articleBody');
    if (!scope) { return; }
    var hs = scope.querySelectorAll('h3:not([id]), h2:not([id])');
    for (var i = 0; i < hs.length; i++) {
      hs[i].id = 'sec-' + i;
      hs[i].style.scrollMarginTop = 'calc(var(--nav-h, 62px) + 14px)';
    }
  }

  function boot() {
    initHeadingAnchors();
    initLightbox();
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
  else { boot(); }
})();
