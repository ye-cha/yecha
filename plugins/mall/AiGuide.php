<?php
declare(strict_types=1);
/**
 * AI 导购浮窗挂件（mall 插件）
 * - 复用 ai 插件的前台接口 POST /ai/api/chat
 * - 回复里的 ||goods:ID|| 锚点 → 请求 /mall/api/cards → 渲染真实商品卡
 *   （★ AI 不生成商品信息，标题/价格/图片全部来自数据库，杜绝编造商品与错价）
 * - 模型平均响应 5~20 秒（推理模型），故必须有等待提示
 * - 未配置 AI / 插件关闭 → 不渲染任何东西（零成本）
 */
final class MallAiGuide
{
    /** 是否可用：ai 插件启用 + 已配置 Key */
    public static function ready(): bool
    {
        try {
            return class_exists('Ai') && \Plugins::isOn('ai') && Ai::isConfigured();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param string $sceneHint 场景附加提示（如"用户正在看【古树茶入门】专题"）
     * @param string $greeting  开场白
     * @param array  $quick     快捷问题
     * @param array  $ctx       上下文（topic_id / goods_id），随请求带上
     */
    public static function render(
        string $sceneHint = '',
        string $greeting = '',
        array $quick = [],
        array $ctx = []
    ): string {
        if (!self::ready()) { return ''; }
        if ((string)get_option('mall.ai_guide', '1') !== '1') { return ''; }

        if ($greeting === '') {
            $greeting = '想找什么茶？可以说预算、口味，或者送人还是自己喝。';
        }
        if ($quick === []) {
            $quick = ['300 元以内的口粮茶', '送长辈送什么茶', '新手第一饼怎么选'];
        }

        $cfg = json_encode([
            'hint'  => $sceneHint,
            'quick' => array_values($quick),
            'ctx'   => $ctx,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $qbtn = '';
        foreach ($quick as $qk) {
            $qbtn .= '<button type="button" class="mg-quick" data-q="' . e((string)$qk) . '">' . e((string)$qk) . '</button>';
        }

        $js = <<<'JS'
(function(){
  var root=document.getElementById('mg-root');
  if(!root) return;
  var CFG={};
  try{ CFG=JSON.parse(root.getAttribute('data-cfg')||'{}'); }catch(e){ CFG={}; }
  var btn=document.getElementById('mg-btn');
  var panel=document.getElementById('mg-panel');
  var close=document.getElementById('mg-close');
  var form=document.getElementById('mg-form');
  var input=document.getElementById('mg-input');
  var list=document.getElementById('mg-list');
  var sid='';
  var busy=false;

  function esc(t){return String(t).replace(/[&<>"]/g,function(c){
    return c==='&'?'&amp;':c==='<'?'&lt;':c==='>'?'&gt;':'&quot;';});}

  // 模型偶尔输出 Markdown：转义后仅保留 **加粗**（换行由 CSS pre-wrap 保留）
  function md(t){
    return esc(t).replace(/\*\*([^*]+)\*\*/g,'<b>$1</b>');
  }

  function near(el){ if(el&&el.scrollIntoView){ el.scrollIntoView({block:'nearest'}); } }

  function addUser(t){
    var d=document.createElement('div');
    d.className='mg-msg mg-user'; d.textContent=t;
    list.appendChild(d); near(d);
  }
  function addAi(html){
    var d=document.createElement('div');
    d.className='mg-msg mg-ai'; d.innerHTML=html;
    list.appendChild(d); near(d); return d;
  }
  function addWait(){
    var d=document.createElement('div');
    d.className='mg-msg mg-ai mg-wait';
    d.innerHTML='<span class="mg-dots"><i></i><i></i><i></i></span> 正在看商品资料…';
    list.appendChild(d); near(d); return d;
  }

  // 商品卡：数据全部来自 /mall/api/cards（AI 只给 id）
  function renderCards(ids, box){
    if(!ids||!ids.length) return;
    fetch('/mall/api/cards?ids='+ids.join(','),{headers:{'Accept':'application/json'}})
      .then(function(r){return r.json();})
      .then(function(d){
        var arr=(d&&d.data&&d.data.list)||[];
        if(!arr.length) return;
        var wrap=document.createElement('div');
        wrap.className='mg-cards';
        arr.forEach(function(g){
          var a=document.createElement('a');
          a.className='mg-card'; a.href=g.url+'?from=ai'; a.target='_blank'; a.rel='noopener';
          var img=document.createElement('img');
          img.src=g.cover; img.alt=g.title; img.loading='lazy';
          var tx=document.createElement('div'); tx.className='mg-card-tx';
          var b=document.createElement('b'); b.textContent=g.title;
          var p=document.createElement('span'); p.className='mg-card-price';
          p.textContent='¥'+g.price;
          if(g.market_price){ var m=document.createElement('em'); m.textContent='¥'+g.market_price; p.appendChild(m); }
          if(g.stock_state==='low'){ var s=document.createElement('i'); s.className='mg-low'; s.textContent='库存紧张'; p.appendChild(s); }
          tx.appendChild(b); tx.appendChild(p);
          a.appendChild(img); a.appendChild(tx);
          wrap.appendChild(a);
        });
        box.appendChild(wrap); near(wrap);
      })
      .catch(function(){});
  }

  function send(msg){
    if(busy||!msg) return;
    busy=true;
    addUser(msg);
    var wait=addWait();
    var payload={message:msg};
    if(sid) payload.sid=sid;
    if(CFG.hint) payload.scene_hint=CFG.hint;

    fetch('/ai/api/chat',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify(payload)})
      .then(function(r){return r.json();})
      .then(function(d){
        wait.remove();
        if(d.sid) sid=d.sid;
        if(!d.ok){
          addAi(esc(d.err||'服务暂时不可用，请稍后再试或留言。'));
          return;
        }
        // 展示文本里去掉锚点标记（锚点用于取商品卡）
        var text=String(d.reply||'')
          .replace(/\|\|\s*goods\s*[:：]\s*\d{1,9}\s*\|\|/g,'')
          .replace(/\[\s*goods\s*[:：]\s*\d{1,9}\s*\]/g,'')
          .replace(/[ \t]{2,}/g,' ').trim();
        var box=addAi(md(text));
        renderCards(d.anchors||[], box);
      })
      .catch(function(){
        wait.remove();
        addAi('网络异常，请稍后再试。');
      })
      .then(function(){ busy=false; });
  }

  if(btn) btn.onclick=function(){
    var open=(panel.style.display==='none');
    panel.style.display=open?'flex':'none';
    if(open&&input) input.focus();
  };
  if(close) close.onclick=function(){ panel.style.display='none'; };
  if(form) form.onsubmit=function(ev){
    ev.preventDefault();
    var m=(input.value||'').trim();
    if(!m) return;
    input.value='';
    send(m);
  };
  var qs=root.querySelectorAll('.mg-quick');
  for(var i=0;i<qs.length;i++){
    qs[i].onclick=function(){ send(this.getAttribute('data-q')||''); };
  }
  // ★ 「这款适合我吗」按钮：打开浮窗并把 CFG.hint 作为场景上下文发送
  document.addEventListener('click', function(e){
    var b = e.target && e.target.closest ? e.target.closest('.mg-ask-this') : null;
    if (!b) return;
    e.preventDefault();
    if (panel) { panel.classList.add('on'); }
    if (root) { root.classList.add('open'); }
    send('这款适合我吗？');
  });
  // ★ 搜索无结果兜底按钮：把用户搜的词直接交给 AI
  document.addEventListener('click', function(e){
    var b = e.target && e.target.closest ? e.target.closest('.mg-ask-search') : null;
    if (!b) return;
    e.preventDefault();
    var q = b.getAttribute('data-q') || '';
    if (panel) { panel.classList.add('on'); }
    if (root) { root.classList.add('open'); }
    send(q ? ('我想找：' + q) : '帮我推荐几款茶');
  });
})();
JS;

        return '<div id="mg-root" class="mg-root" data-cfg="' . e((string)$cfg) . '">'
            . '<button type="button" id="mg-btn" class="mg-btn" aria-label="AI 帮你选茶">帮你选茶</button>'
            . '<div id="mg-panel" class="mg-panel" style="display:none">'
            . '<div class="mg-head"><b>AI 选茶助手</b><button type="button" id="mg-close" class="mg-close" aria-label="关闭">×</button></div>'
            . '<div id="mg-list" class="mg-list">'
            . '<div class="mg-msg mg-ai">' . e($greeting) . '</div>'
            . ($qbtn !== '' ? '<div class="mg-quicks">' . $qbtn . '</div>' : '')
            . '</div>'
            . '<form id="mg-form" class="mg-form">'
            . '<input id="mg-input" class="mg-input" type="text" placeholder="说说你想找什么茶…" autocomplete="off" maxlength="200">'
            . '<button type="submit" class="mg-send">发送</button>'
            . '</form>'
            . '<div class="mg-foot">AI 推荐基于站内商品资料，价格以商品页为准</div>'
            . '</div>'
            . '<script>' . $js . '</script>'
            . '</div>';
    }
}
