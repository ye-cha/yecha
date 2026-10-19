/*! StatX v2 · YeCha plugins/statx · 无Cookie/不存IP/仅localStorage随机ID · MIT */
(function(){try{
var d=document,w=window,l=location,a=d.currentScript||d.querySelector('script[data-site]');
if(!a){return}var s=a.getAttribute('data-site');
if(!s||d.visibilityState=='prerender'||navigator.webdriver){return}
var o='';try{o=a.src?new URL(a.src,l.href).origin:''}catch(y){}
var ep=a.getAttribute('data-ep')||(o?o+'/st':'/st'),q=ep.indexOf('?')<0?'?':'&';
var e=encodeURIComponent,R=function(){var i,x='',c=[];try{c=crypto.getRandomValues(new Uint8Array(8))}catch(y){}for(i=0;i<8;i++){x+=(c[i]||Math.random()*256|0).toString(16).slice(-2)}return x};
var L=null;try{L=w.localStorage}catch(y){}
var m=Date.now(),uid='',sid='';
try{uid=(L&&L.getItem('_sxu'))||'';if(!uid){uid=R();L&&L.setItem('_sxu',uid)}
var t=(L&&+L.getItem('_sxt'))||0;sid=(L&&L.getItem('_sxs'))||'';
if(!sid||m-t>18e5){sid=R();L&&L.setItem('_sxs',sid)}L&&L.setItem('_sxt',m)}catch(y){uid=uid||R();sid=sid||R()}
var b=ep+q+'s='+e(s)+'&si='+sid+'&ui='+uid+'&h='+e(l.host)+'&p='+e(l.pathname)+'&r='+e(d.referrer||'')+'&sw='+(screen.width|0)+'&sh='+(screen.height|0)+'&vw='+(innerWidth|0)+'&vh='+(innerHeight|0)+'&lg='+e(navigator.language||'');
try{var u=['source','medium','campaign','term','content'],i,v,p=new URLSearchParams(l.search);
for(i=0;i<5;i++){v=p.get('utm_'+u[i]);if(v){b+='&utm_'+u[i]+'='+e(v.slice(0,100))}}}catch(y){}
var g=function(x){if(navigator.sendBeacon){navigator.sendBeacon(x)}else{var n=new Image();n.src=x}};
g(b+'&k=pv');
d.addEventListener('click',function(v){var n=Date.now();if(n-(+g.t||0)<2e3){return}g.t=n;
var x=v.target,en='';while(x&&x.getAttribute){en=x.getAttribute('data-event')||'';if(en){break}x=x.parentNode}
if(!en){en=v.target&&v.target.tagName?v.target.tagName.toLowerCase():''}
g(b+'&k=cl&x='+Math.round(v.clientX)+'&y='+Math.round(v.clientY)+'&en='+e(en.slice(0,48)))},true);
}catch(x){}})();
