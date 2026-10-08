
const publicRateCards=['social-ads','music','smm-followers','numbers','verification','press-tier1'];
const pricingText=value=>'€'+Number(value||0).toFixed(2).replace(/\.00$/,'');
function siteImage(url,alt,cls){const img=document.createElement('img');img.src=url;img.alt=alt;img.className=cls||'';img.loading='lazy';img.referrerPolicy='no-referrer';return img;}
function drawSiteItems(items){
 const teams=document.getElementById('site-team-grid');if(teams&&items.leader?.length){teams.replaceChildren();items.leader.forEach(x=>{const card=document.createElement('article');card.className='about-team-card';const avatar=document.createElement('div');avatar.className='about-team-avatar';if(x.image_url)avatar.append(siteImage(x.image_url,x.title,''));else avatar.textContent=x.icon_text||'TDC';card.append(avatar);const name=document.createElement('p');name.className='about-team-name';name.textContent=x.title;const role=document.createElement('p');role.className='about-team-role';role.textContent=x.subtitle;const bio=document.createElement('p');bio.className='about-team-bio';bio.textContent=x.body||'';card.append(name,role,bio);teams.append(card);});}
 const reviews=document.getElementById('site-reviews-grid');if(reviews&&items.testimonial?.length){reviews.replaceChildren();items.testimonial.forEach(x=>{const card=document.createElement('article');card.className='review-card';const head=document.createElement('div');head.className='review-header';const left=document.createElement('div');left.style.cssText='display:flex;gap:10px;align-items:center';const avatar=document.createElement('div');avatar.className='review-avatar';if(x.image_url)avatar.append(siteImage(x.image_url,x.title,''));else avatar.textContent=x.icon_text||'★';const info=document.createElement('div');const name=document.createElement('p');name.className='review-name';name.textContent=x.title;const subtitle=document.createElement('p');subtitle.className='review-handle';subtitle.textContent=x.subtitle;info.append(name,subtitle);left.append(avatar,info);const stars=document.createElement('div');stars.className='review-stars';stars.textContent='★'.repeat(Math.max(1,Math.min(5,Number(x.rating)||5)));head.append(left,stars);const body=document.createElement('p');body.className='review-body';body.textContent=x.body||'';card.append(head,body);if(x.href){const link=document.createElement('a');link.href=x.href;link.rel='noopener noreferrer';link.textContent='View client story';link.className='review-service-pill';card.append(link);}reviews.append(card);});}
 const partners=document.getElementById('site-partners');if(partners&&(items.partner?.length||partners.dataset.names)){partners.replaceChildren();items.partner?.forEach(x=>{const a=document.createElement('a');a.textContent=x.title;a.href=x.href||'#';if(x.href){a.target='_blank';a.rel='noopener noreferrer';}partners.append(a);});(partners.dataset.names||'').split(/\r?\n/).map(x=>x.trim()).filter(Boolean).forEach(name=>{const span=document.createElement('span');span.textContent=name;partners.append(span);});}
 const track=document.getElementById('site-brands-track');if(track&&items.brand?.length){track.replaceChildren();const add=(x)=>{const wrap=document.createElement(x.href?'a':'div');wrap.className='trust-logo';if(x.href){wrap.href=x.href;wrap.target='_blank';wrap.rel='noopener noreferrer';}if(x.image_url)wrap.append(siteImage(x.image_url,x.title,''));else if(x.icon_text){const icon=document.createElement('span');icon.textContent=x.icon_text;wrap.append(icon);}const label=document.createElement('span');label.className='trust-logo-name';label.textContent=x.title;wrap.append(label);return wrap;};items.brand.forEach(x=>track.append(add(x)));items.brand.forEach(x=>track.append(add(x)));}
}
async function loadPublicSiteContent(){
 try{const response=await fetch('/api/site-content.php',{credentials:'same-origin',cache:'no-store'});if(!response.ok)return;const data=await response.json();const s=data.settings||{};
  if(s.meta_title){document.title=s.meta_title;const t=document.querySelector('title[data-site-key="meta_title"]');if(t)t.textContent=s.meta_title;}
  if(s.meta_description){let m=document.querySelector('meta[name="description"]');if(!m){m=document.createElement('meta');m.name='description';document.head.append(m);}m.content=s.meta_description;}
  document.querySelectorAll('[data-site-key]').forEach(el=>{const k=el.dataset.siteKey;if(s[k])el.textContent=s[k];});
  if(s.brand_name){document.querySelectorAll('.l-nav-logo,.l-foot-brand,.sb-logo').forEach(el=>{const nodes=[...el.childNodes].filter(n=>n.nodeType===Node.TEXT_NODE);if(nodes.length)nodes[nodes.length-1].textContent=' '+s.brand_name;});}
  if(s.favicon_url){let f=document.querySelector('link[rel="icon"]');if(!f){f=document.createElement('link');f.rel='icon';document.head.append(f);}f.href=s.favicon_url;}
  if(s.logo_url){document.querySelectorAll('.l-nav-logo-mark,.l-foot-brand-mark,.sb-logo-mark').forEach(el=>{const img=siteImage(s.logo_url,s.brand_name||'TDC Tech','site-logo-img');img.style.cssText='width:100%;height:100%;object-fit:contain;border-radius:inherit';el.replaceChildren(img);});}
  const hashtags=document.getElementById('ads-hashtags');if(hashtags&&s.ads_hashtags)hashtags.textContent=s.ads_hashtags;
  const footPrice=document.getElementById('site-footer-pricing');if(footPrice&&s.footer_pricing_text)footPrice.textContent=' · '+s.footer_pricing_text;
  const pages=Object.fromEntries((data.service_pages||[]).map(x=>[x.slug,x]));
  document.querySelectorAll('[data-service-page]').forEach(el=>{const p=pages[el.dataset.servicePage];if(!p)return;if(!Number(p.active)){el.hidden=true;return;}const icon=document.createElement('span');icon.textContent=p.icon;icon.setAttribute('aria-hidden','true');if(el.classList.contains('sb-link')){el.replaceChildren(icon,document.createTextNode(' '+p.name));}else if(el.classList.contains('fn-item')){const old=el.querySelector('svg');if(old)old.replaceWith(icon);else el.prepend(icon);}else{const target=el.querySelector('.svc-icon-sm');if(target)target.replaceChildren(icon);const label=el.querySelector('.svc-label');if(label)label.textContent=p.name;}});
  document.querySelectorAll('[data-service-page-panel]').forEach(el=>{const p=pages[el.dataset.servicePagePanel];if(!p)return;if(!Number(p.active)){el.hidden=true;return;}const icon=el.querySelector('.svc-page-emoji');if(icon)icon.textContent=p.icon;const title=el.querySelector('.svc-page-title h2');if(title)title.textContent=p.name;});
  const catalog=Object.fromEntries((data.services||[]).map(x=>[x.slug,x]));publicRateCards.forEach((slug,i)=>{const card=document.querySelectorAll('.rate-card')[i],item=catalog[slug];if(!card||!item)return;const name=card.querySelector('.rate-name'),price=card.querySelector('.rate-price');if(name)name.textContent=item.name;if(price)price.textContent=pricingText(item.price);});
  const pageServices=data.page_services||{};const cards=document.querySelectorAll('.l-svc-card');cards.forEach(card=>{const label=card.querySelector('.l-svc-name')?.textContent.toLowerCase()||'';let ids=[];if(label.includes('social media ads'))ids=['social-ads'];else if(label==='music distribution')ids=['music'];else if(label.includes('verification'))ids=['verification'];else if(label.includes('web & app development'))ids=['web-dev'];else if(label.includes('account management'))ids=['account-social'];else if(label.includes('press'))ids=['press-tier1'];else if(label.includes('likes')||label.includes('followers'))ids=['smm-followers'];else if(label.includes('virtual numbers'))ids=['numbers'];else if(label.includes('custom bots'))ids=['bots'];else if(label.includes('proxies'))ids=['proxies'];else if(label.includes('templates'))ids=['template-nexus','template-storefront','template-vibe'];if(!ids.length)return;const available=Object.values(pageServices).flat().filter(x=>ids.includes(x.slug)&&Number(x.price)>0);if(!available.length)return;const price=ids.length>1?Math.min(...available.map(x=>Number(x.price))):Number(available[0].price);const priceEl=card.querySelector('.l-svc-price');if(!priceEl)return;const span=priceEl.querySelector('span');priceEl.replaceChildren(document.createTextNode('From '+pricingText(price)+' '));if(span)priceEl.append(span);});
  const partners=document.getElementById('site-partners');if(partners)partners.dataset.names=s.footer_partnerships||'';
  drawSiteItems(data.items||{});
 }catch(_e){}
}

async function loadAccountState(){
  const r=await fetch('/api/me.php',{credentials:'same-origin',cache:'no-store'});const data=await r.json();
  if(!data.authenticated){location.href='/login';return null;}
  currentUser=data.user.name;walletBalance=Number(data.balance||0);updateWalletDisplay();
  if(data.user.role==='admin'){location.href='/admin/';return null;}
  await loadAccountActivity();return data;
}
function safeNode(tag,cls,text){const n=document.createElement(tag);if(cls)n.className=cls;n.textContent=text;return n;}
async function loadAccountActivity(){
  try{
    const res=await fetch('/api/orders.php',{credentials:'same-origin',cache:'no-store'});if(!res.ok)return;const data=await res.json();
    document.querySelectorAll('#view-dashboard .txn-row').forEach(n=>n.remove());
    document.querySelectorAll('#view-dashboard .dsp-row').forEach(n=>n.remove());
    const lists=document.querySelectorAll('#view-dashboard .txn-list');
    lists.forEach(n=>{if(!n.children.length)n.append(safeNode('p','empty-state','No transactions yet.'));});
    if(lists[0]&&data.transactions?.length){lists[0].replaceChildren();data.transactions.slice(0,6).forEach(t=>{const row=safeNode('div','txn-row','');const left=safeNode('div','txn-left','');left.append(safeNode('div','txn-ic',t.kind==='credit'?'€':'•'));const info=safeNode('div','','');info.append(safeNode('p','txn-name',t.note||t.kind));info.append(safeNode('p','txn-meta',new Date(t.created_at+'Z').toLocaleString()));left.append(info);const amt=Number(t.amount);row.append(left,safeNode('span','txn-amt '+(amt>=0?'inc':'out'),(amt>=0?'+':'')+'€'+Math.abs(amt).toFixed(2)+' · '+t.status));lists[0].append(row);});}
    const box=document.getElementById('account-orders');if(box){box.replaceChildren();if(!data.orders?.length)box.append(safeNode('p','empty-state','No orders yet.'));else data.orders.forEach(o=>{const card=safeNode('article','order-card','');card.append(safeNode('strong','',`#${o.id} · ${o.service} · €${Number(o.amount).toFixed(2)}`));card.append(safeNode('p','',`${o.description} · ${o.status} · ${new Date(o.created_at+'Z').toLocaleString()}`));if(o.admin_note)card.append(safeNode('p','order-note',o.admin_note));box.append(card);});}
    const proxy=[...(data.orders||[])].find(o=>String(o.service).toLowerCase().includes('proxy')&&o.admin_note);if(proxy){const out=document.getElementById('proxy-cred-output');if(out)out.textContent=proxy.admin_note;}
    document.querySelectorAll('#view-dashboard .stat-card-val').forEach(n=>{if(n.id!=='home-wallet')n.textContent='0';});
    document.querySelectorAll('#view-dashboard .metric-val').forEach(n=>n.textContent=n.textContent.includes('€')?'€0.00':'0');
    document.querySelectorAll('#view-dashboard .metric-sub').forEach(n=>n.textContent='No account activity yet');
  }catch(_e){}
}
const routes={'home':'/dashboard','social-ads':'/services/social-ads','music':'/services/music','verification':'/services/verification','web-dev':'/services/web-dev','account-mgmt':'/services/account-mgmt','press':'/services/press','smm':'/services/smm','numbers':'/services/numbers','bots':'/services/bots','proxies':'/services/proxies','cart':'/cart','chat':'/support','profile':'/profile'};
if(typeof window.navTo==='function'){
  const originalNavTo=window.navTo;
  window.navTo=function(page){originalNavTo(page);const route=routes[page];if(route&&location.pathname!==route)history.pushState({page},'',route);};
}
window.addEventListener('popstate',()=>{const page=Object.keys(routes).find(k=>routes[k]===location.pathname)||document.body.dataset.service||'home';if(typeof window.navTo==='function')window.navTo(page);});
window.addEventListener('DOMContentLoaded',async()=>{
  loadPublicSiteContent();
  const path=location.pathname;
  if(document.body.dataset.page==='dashboard'||document.body.dataset.page==='service'||path==='/dashboard'||path.startsWith('/services/')){
    const data=await loadAccountState();if(!data)return;
    const dash=document.getElementById('view-dashboard');if(dash){document.getElementById('view-landing')?.remove();dash.style.display='flex';dash.classList.add('active');document.getElementById('chat-fab').style.display='flex';const fn=document.getElementById('float-nav');if(fn)fn.style.display='flex';}
    const target=Object.keys(routes).find(k=>routes[k]===location.pathname)||document.body.dataset.service||'home';if(typeof navTo==='function')navTo(target);
  }
});
