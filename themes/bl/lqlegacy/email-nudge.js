/* ============================================================================
 * lqlegacy · 首次登录「补真实邮箱」提示（★ 可关闭 · ★ 不阻塞 · ★ 零外发）
 * 触发：/api/auth/me 回 email_synth=true（= 该账号还在用迁移时的合成占位邮箱）
 * 关闭：点「不再提示」⇒ localStorage `llq_email_nudge_v1` = 1（本机记住）
 * 不阻塞：fixed 顶条、无遮罩、无 modal、不改任何表单与登录流程
 * ========================================================================== */
(function () {
  'use strict';
  var K = 'llq_email_nudge_v1';
  try { if (localStorage.getItem(K) === '1') { return; } } catch (e) { }
  function go() {
    fetch('/api/auth/me', { credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (j) {
        if (!j || j.email_synth !== true) { return; }
        var bar = document.createElement('div');
        bar.setAttribute('data-lqnudge', '1');
        bar.innerHTML = '<span>你的账号用的是<b>迁移时的占位邮箱</b>（<code>@import.invalid</code>）。'
          + '建议补一个<b>真实邮箱</b>，方便找回密码与接收通知；也可以继续用<b>用户名或该占位邮箱</b>登录。</span>'
          + '<span class="lqnudge-acts">'
          + '<button type="button" data-lqnudge="ok">知道了</button>'
          + '<button type="button" data-lqnudge="never">不再提示</button>'
          + '</span>';
        var css = document.createElement('style');
        css.textContent = '[data-lqnudge]{position:fixed;left:0;right:0;top:0;z-index:80;display:flex;gap:10px;'
          + 'flex-wrap:wrap;align-items:center;justify-content:center;padding:8px 12px;font-size:13px;'
          + 'background:#fff7ed;color:#7c2d12;border-bottom:1px solid #fed7aa}'
          + '[data-lqnudge] code{background:rgba(0,0,0,.06);padding:0 4px;border-radius:4px}'
          + '[data-lqnudge] .lqnudge-acts{display:flex;gap:6px}'
          + '[data-lqnudge] button{font-size:12px;padding:3px 10px;border:1px solid #fdba74;border-radius:6px;'
          + 'background:transparent;color:inherit;cursor:pointer}';
        document.head.appendChild(css);
        document.body.appendChild(bar);
        bar.addEventListener('click', function (e) {
          var k = e.target && e.target.getAttribute && e.target.getAttribute('data-lqnudge');
          if (!k) { return; }
          if (k === 'never') { try { localStorage.setItem(K, '1'); } catch (er) { } }
          bar.parentNode.removeChild(bar);
        });
      }).catch(function () { });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', go); } else { go(); }
})();
