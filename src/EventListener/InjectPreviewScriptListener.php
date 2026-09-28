<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * When the frontend preview iframe loads a page with ?_clp=1, injects a tiny
 * postMessage listener + highlight CSS before </body>. This enables the backend
 * JS to scroll to and briefly outline the currently edited article/element.
 *
 * Only fires for frontend HTML responses — never for backend, JSON, or assets.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -300)]
class InjectPreviewScriptListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        // Backend scope, non-HTML, or no preview marker → skip.
        if ('backend' === $request->attributes->get('_scope')) {
            return;
        }

        if (!$request->query->getBoolean('_clp')) {
            return;
        }

        $response = $event->getResponse();

        if (!str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')) {
            return;
        }

        $content = $response->getContent();

        if (false === $content || !str_contains($content, '</body>')) {
            return;
        }

        $response->setContent(str_replace('</body>', $this->buildInjection() . '</body>', $content));

        // no-cache: browser always revalidates before using a cached response.
        // A 304 Not Modified costs only one RTT (no body) so navigation stays snappy,
        // while stale content after saves or back-navigations within 60 s is impossible.
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->headers->remove('Pragma');
    }

    private function buildInjection(): string
    {
        // Inline to avoid extra HTTP requests. Only present when loaded inside the
        // preview iframe (?_clp=1). Handles two message types from the backend:
        //   clp:highlight — scroll to element, apply persistent blue outline + label badge
        //   clp:refresh   — fetch current page, swap article DOM node, then highlight
        return <<<'HTML'
<style>
/* Styles for selected/hovered elements */
.clp-sel,.clp-sel-secondary,.clp-hover{outline:2px solid #0594ff!important;outline-offset:-2px}
.clp-sel-secondary,.clp-hover{outline-style:dashed!important}
.clp-hover{outline-color:#d946ef!important}
/* Badge action styles */
.clp-badge,.clp-hover-badge{position:absolute;display:flex;align-items:center;gap:5px;color:#fff;font:700 11px/1 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;padding:7px 9px 8px 10px;border-radius:3px;white-space:nowrap;pointer-events:none;background:#0594ff;z-index:2147483647}
.clp-hover-badge{background:#d946ef}
.clp-badge-edit,.clp-badge-action{all:unset;display:flex;align-items:center;cursor:pointer;opacity:.75;transition:opacity .15s;pointer-events:auto;padding:8px;margin:-8px}
.clp-badge-action{justify-content:center;padding:5px;margin:-5px -2px;line-height:1}
.clp-badge-edit:hover,.clp-badge-action:hover{opacity:1}
.clp-badge-sep{display:inline-block;width:1px;height:12px;background:rgba(255,255,255,.3);margin:0 2px;flex-shrink:0;align-self:center}
</style>
<script>(function(){
// _el/_elSecondary  = data elements (carry data-contao-* attrs; used for hover exclusion + DOM swap).
var _el=null,_secondaryEl=null,_badge=null,_secondaryBadge=null,_gen=0;
var _articleId=null,_contentElementId=null;
var _hoverEl=null,_hoverBadge=null;
var _refreshAbort=null;
var _editIcon='<svg style="flex-shrink:0" width="11" height="11" viewBox="0 0 10 10" fill="none"><path d="M7 1.5l1.5 1.5-5.5 5.5H1.5V7L7 1.5z" stroke="#fff" stroke-width="1.2" stroke-linejoin="round"/><line x1="5.8" y1="2.7" x2="7.3" y2="4.2" stroke="#fff" stroke-width="1.2"/></svg>';
var _dupIcon='<svg style="flex-shrink:0" width="11" height="11" viewBox="0 0 11 11" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3.5" y="3.5" width="6.5" height="6.5" rx=".8"/><path d="M1 7.5V1h6.5v2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
var _addIcon='<svg style="flex-shrink:0" width="11" height="11" viewBox="0 0 11 11" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="5.5" y1="1.5" x2="5.5" y2="9.5"/><line x1="1.5" y1="5.5" x2="9.5" y2="5.5"/></svg>';
function findEl(sels){var r=null;for(var i=0;i<sels.length;i++){r=document.querySelector(sels[i]);if(r)break;}return r;}
// Return the child as the visual target when el is a single-child grid column wrapper (col-*).
// Used for attaching outline + badge to the child, the data element (el) is kept for DOM queries.
function clpVisTarget(el){var cc=String(el.className||'').split(/\s+/);for(var i=0;i<cc.length;i++){if(cc[i].indexOf('col-')===0){if(el.children.length===1)return el.children[0];break;}}return el;}
function _clear(refEl,refBadge){if(refEl){clpVisTarget(refEl).classList.remove('clp-sel','clp-sel-secondary','clp-hover');refEl=null;}if(refBadge){refBadge.remove();refBadge=null;}}
function clpClear(){_clear(_el,_badge);_clear(_secondaryEl,_secondaryBadge);}
function clpHoverClear(){_clear(_hoverEl,_hoverBadge);}
function clpIsFixed(el){var n=el;while(n&&n!==document.body){if(getComputedStyle(n).position==='fixed')return true;n=n.parentElement;}return false;}
// Position a badge at the top-left of its target. When the target box is too
// short to contain the badge (empty article / element group with little padding),
// render it just ABOVE the box instead of overflowing it, clamped into view.
function clpBadgePos(b,el){
  var r=el.getBoundingClientRect();
  var bh=b.offsetHeight||24;
  var above=r.height<bh+4;
  var aboveGap=b.classList.contains('clp-hover-badge')?0:2;
  if(clpIsFixed(el)){
    b.style.position='fixed';
    b.style.top=(above?Math.max(aboveGap,r.top-bh-aboveGap):r.top+2)+'px';
    b.style.left=(r.left+2)+'px';
  }else{
    b.style.position='';
    var t=window.scrollY+r.top;
    b.style.top=(above?Math.max(window.scrollY+aboveGap,t-bh-aboveGap):t+2)+'px';
    b.style.left=(window.scrollX+r.left+2)+'px';
  }
}
function _rectsOverlap(a,c){return !(a.right<=c.left||c.right<=a.left||a.bottom<=c.top||c.bottom<=a.top);}
// Dual-highlight mode stacks the content-element badge and the article badge on
// the same top-left corner whenever the article/group has no own padding. Push
// the (secondary) article badge below the CE badge so both stay readable.
function clpDeconflict(){
  if(!_badge||!_secondaryBadge)return;
  if(_rectsOverlap(_badge.getBoundingClientRect(),_secondaryBadge.getBoundingClientRect())){
    _badge.style.top=((parseFloat(_badge.style.top)||0)+_secondaryBadge.offsetHeight+4)+'px';
  }
}
function _mkBadge(cls,lbl,table,editId){var b=document.createElement('div');b.className=cls;var s=document.createElement('span');s.textContent=lbl;b.appendChild(s);var btn=document.createElement('button');btn.type='button';btn.className='clp-badge-edit';btn.innerHTML=_editIcon;if(table&&editId){btn.addEventListener('click',function(ev){ev.stopPropagation();window.parent.postMessage({type:'clp:edit',table:table,id:editId},'*');});}b.appendChild(btn);if(table==='tl_content'&&editId){var sep=document.createElement('span');sep.className='clp-badge-sep';b.appendChild(sep);var db=document.createElement('button');db.type='button';db.className='clp-badge-action';db.title='Element duplizieren';db.innerHTML=_dupIcon;db.addEventListener('click',function(ev){ev.stopPropagation();window.parent.postMessage({type:'clp:duplicate',id:editId},'*');});b.appendChild(db);var nb=document.createElement('button');nb.type='button';nb.className='clp-badge-action';nb.title='Neues Element danach';nb.innerHTML=_addIcon;nb.addEventListener('click',function(ev){ev.stopPropagation();window.parent.postMessage({type:'clp:insert-after',id:editId},'*');});b.appendChild(nb);}document.body.appendChild(b);return b;}
function makeBadge(el,lbl,t,id,type=null){if(type==='hover'){_hoverBadge=_mkBadge('clp-hover-badge',lbl,t,id);clpBadgePos(_hoverBadge,clpVisTarget(el));}else if(type==='secondary'){_secondaryBadge=_mkBadge('clp-badge',lbl,t,id);clpBadgePos(_secondaryBadge,clpVisTarget(el));}else{_badge=_mkBadge('clp-badge',lbl,t,id);clpBadgePos(_badge,clpVisTarget(el));}}
function getCeLabel(el){if(!el)return '';if(el.dataset&&el.dataset.contaoLabel&&el.dataset.contaoLabel!==''){return el.dataset.contaoLabel.toUpperCase();}var cc=String(el.className||'').split(/\s+/);for(var i=0;i<cc.length;i++){if(cc[i].indexOf('ce_')===0){return cc[i].slice(3).replace(/([a-z])([A-Z])/g,'$1 $2').replace(/_/g,' ').toUpperCase();}}return 'INHALTSELEMENT';}
function clpReposAll(){var vis=clpVisTarget(_el);if(_badge&&vis)clpBadgePos(_badge,vis);var secondaryVis=clpVisTarget(_secondaryEl);if(_secondaryBadge&&secondaryVis)clpBadgePos(_secondaryBadge,secondaryVis);var hoverVis=clpVisTarget(_hoverEl);if(_hoverBadge&&hoverVis)clpBadgePos(_hoverBadge,hoverVis);clpDeconflict();}
window.addEventListener('resize',clpReposAll,{passive:true});
function highlight(el,bh,label,table,editId,type=null) {
  if(!type)clpClear();
  var vis=clpVisTarget(el);
  _gen++;var myGen=_gen;
  // strict matching on false (should default to 'smooth' otherwise)
  if (bh!==false){
    var rect=vis.getBoundingClientRect();
    var targetY=window.scrollY+rect.top-(window.innerHeight-rect.height)/2;
    window.scrollTo({top:Math.max(0,targetY),left:0,behavior:bh||'smooth'});
  }
  function apply(){if(_gen!==myGen)return;if(type==='hover'){_hoverEl=el;vis.classList.add('clp-hover')}else if (type==='secondary'){_secondaryEl=el;vis.classList.add('clp-sel-secondary')}else{_el=el;vis.classList.add('clp-sel')}if(label){makeBadge(el,label,table,editId,type);}}
  if(bh===false||(bh||'smooth')==='instant'){apply();}
  else{var t;function hl(){clearTimeout(t);window.removeEventListener('scrollend',hl);apply();}if('onscrollend'in window)window.addEventListener('scrollend',hl,{once:true});t=setTimeout(hl,800);}
}
window.addEventListener('message',function(e){
  if(!e.data||!e.data.type)return;
  if(e.data.type==='clp:highlight'){
    var el=findEl(e.data.selectors||[]);
    var aEl=findEl(e.data.articleSelectors||[]);
    _articleId=aEl?e.data.articleId||null:null;
    _contentElementId=el?e.data.contentElementId||null:null;
    if(!el&&!aEl){clpClear();return;}
    var both=el&&aEl&&el!==aEl;
    // Prefer data-contao-label from the DOM — set by InjectContentElementMarkersListener
    // in fully-bootstrapped frontend context, so language files are always complete.
    var lbl=getCeLabel(el)||e.data.label||'';
    var bh=e.data.scrollBehavior;
    if(el)highlight(el,both?bh||'instant':bh,lbl,el.dataset.contaoTable,_contentElementId);
    if(aEl)highlight(aEl,both?false:bh,e.data.articleLabel||'',aEl.dataset.contaoTable,_articleId,both?'secondary':'');
    if(both)clpDeconflict();
    return;
  }
  if(e.data.type==='clp:refresh'){
    var articleId=e.data.articleId;var selectors=e.data.selectors||[];var label=e.data.label||'';
    if(_refreshAbort){_refreshAbort.abort();}
    _refreshAbort=('AbortController'in window)?new AbortController():null;
    var fetchOpts={credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}};
    if(_refreshAbort){fetchOpts.signal=_refreshAbort.signal;}
    fetch(window.location.href,fetchOpts)
      .then(function(r){return r.text();})
      .then(function(html){
        _refreshAbort=null;
        var doc=new DOMParser().parseFromString(html,'text/html');
        var fresh=null,live=null;
        for(var i=0;i<selectors.length;i++){var f=doc.querySelector(selectors[i]);var l=document.querySelector(selectors[i]);if(f&&l){fresh=f;live=l;break;}}
        if(fresh&&live){for(var ai=0;ai<fresh.attributes.length;ai++){live.setAttribute(fresh.attributes[ai].name,fresh.attributes[ai].value);}live.innerHTML=fresh.innerHTML;var el=findEl(selectors);if(el){highlight(el,false,label,'tl_article',_articleId);}}
        window.parent.postMessage({type:'clp:refreshed',articleId:articleId},'*');
      })
      .catch(function(err){
        if(err&&err.name==='AbortError'){return;}
        _refreshAbort=null;
        window.parent.postMessage({type:'clp:refreshed',articleId:articleId},'*');
      });
    return;
  }
});
// Hover: fuchsia dashed outline + badge for any article/CE on the page.
// _hoverEl = data element (for exclusion check + mouseout boundary).
// _hoverElVis = visual target (receives outline class and badge position).
document.addEventListener('mouseover',function(e){
  if(e.target.closest&&e.target.closest('.clp-badge,.clp-hover-badge'))return;
  var el=e.target.closest?e.target.closest('[data-contao-table]'):null;
  if(!el){clpHoverClear();return;}
  if(el===_hoverEl)return;
  clpHoverClear();
  if(el===_el||el===_elSecondary)return;
  var table=el.dataset.contaoTable;
  var id=parseInt(el.dataset.contaoId,10)||0;
  if(!table||!id)return;
  var lbl=table==='tl_article'?'ARTIKEL':getCeLabel(el);
  highlight(el,false,lbl,table,id,'hover');
});
// mouseout: _hoverEl (the data/container element) defines the boundary.
// Covers both the col-* wrapper and its single child — don't clear until cursor
// truly leaves the container (or moves to the badge for edit-icon click).
document.addEventListener('mouseout',function(e){
  if(!_hoverEl)return;
  var rel=e.relatedTarget;
  if(rel&&(rel===_hoverEl||_hoverEl.contains(rel)))return;
  if(_hoverBadge&&rel&&(rel===_hoverBadge||_hoverBadge.contains(rel)))return;
  clpHoverClear();
});
// Keep ?_clp=1 on same-origin in-frame navigation so the preview script is
// re-injected on every page the editor browses to. Without this, following an
// internal link (or submitting a form) drops the marker/hover/refresh machinery
// until the next backend resolve.
function _clpRewrite(raw){
  try{
    var u=new URL(raw,window.location.href);
    if(u.origin!==window.location.origin)return null;
    if(u.searchParams.get('_clp')!=='1')u.searchParams.set('_clp','1');
    return u;
  }catch(err){return null;}
}
// Bubble phase (false) so JS toggle handlers (e.g. mobile menu) can call
// preventDefault() first — if they did, we skip navigation entirely.
document.addEventListener('click',function(e){
  if(e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
  var a=e.target.closest?e.target.closest('a[href]'):null;
  if(!a||a.hasAttribute('download'))return;
  if(a.target&&a.target!==''&&a.target!=='_self')return; // _blank etc. → real new tab, leave it
  var href=a.getAttribute('href')||'';
  if(!href||href.charAt(0)==='#'||/^(mailto:|tel:|javascript:)/i.test(href))return;
  var u=_clpRewrite(a.href);
  if(!u)return;
  // Pure in-page anchor on the current URL → let the browser scroll.
  if(u.hash&&u.pathname===window.location.pathname&&u.search===window.location.search)return;
  e.preventDefault();
  window.location.assign(u.toString());
},false);
// Forms lose the marker on submit: GET rebuilds the query from fields (so _clp
// must ride as a hidden input), POST keeps it only if it is in the action URL.
document.addEventListener('submit',function(e){
  var f=e.target;
  if(!f||f.tagName!=='FORM')return;
  var u=_clpRewrite(f.getAttribute('action')||window.location.href);
  if(!u)return;
  if((f.method||'get').toLowerCase()==='get'){
    if(!f.querySelector('input[name="_clp"]')){
      var i=document.createElement('input');i.type='hidden';i.name='_clp';i.value='1';f.appendChild(i);
    }
  }else{
    f.setAttribute('action',u.toString());
  }
},true);
})();</script>
HTML;
    }
}
