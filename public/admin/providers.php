<?php
require dirname(__DIR__,2).'/app/admin_view.php';admin_header('smm','SMM providers');
?>
<div class="card"><div class="card-hd"><h3>Provider connection & pricing</h3><a class="btn" href="/admin/service?slug=smm">Orders and catalog</a></div>
<p class="muted">Connect a provider, choose your profit, then sync its services. Wallet prices are in EUR. Enter how many provider-currency units equal €1. Sync applies that conversion and profit to the latest rates.</p>
<form id="provider-form"><input type="hidden" id="provider-id"><div class="form-grid">
<div class="field"><label for="provider-name">Provider name</label><input id="provider-name" required maxlength="120"></div>
<div class="field"><label for="provider-url">HTTPS API endpoint</label><input id="provider-url" required type="url" placeholder="https://your-provider.example/api/v2"></div>
<div class="field"><label for="provider-key">API key (leave blank to keep saved key)</label><input id="provider-key" type="password" autocomplete="new-password"></div>
<div class="field"><label for="provider-currency">Provider currency</label><input id="provider-currency" required maxlength="3" value="USD"></div>
<div class="field"><label for="provider-fx">Provider units per €1</label><input id="provider-fx" type="number" required min="0.000001" step="0.000001"></div>
<div class="field"><label for="provider-profit">Profit markup %</label><input id="provider-profit" required type="number" min="0" max="10000" step="0.01" value="25"></div></div>
<p><label><input type="checkbox" id="provider-active" checked style="width:auto"> Enable this provider</label></p>
<div class="actions"><button class="btn primary" type="submit">Save & validate key</button><button class="btn" type="button" onclick="newProvider()">New provider</button></div></form></div>
<div class="card"><h3>Connections</h3><div class="table-wrap" id="providers"></div></div>
<div class="card"><h3>Submissions needing review</h3><p class="muted">An interrupted request may have reached the provider. Confirm its order ID before reconnecting it here; it will not be sent a second time.</p><div id="review"></div></div>
<script>
let state;const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const el=id=>document.getElementById('provider-'+id);
async function send(action,values={}){const r=await fetch('/api/smm-admin.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrf},body:JSON.stringify({action,...values})});const d=await r.json();if(!r.ok)throw Error(d.error||'Request failed');return d;}
async function load(){const r=await fetch('/api/smm-admin.php',{cache:'no-store'});state=await r.json();if(!r.ok)throw Error(state.error);document.getElementById('providers').innerHTML='<table><thead><tr><th>Name</th><th>Currency / conversion</th><th>Profit</th><th>Last sync</th><th>Actions</th></tr></thead><tbody>'+state.providers.map(p=>`<tr><td>${esc(p.name)} · ${p.active?'Enabled':'Disabled'}<br>${esc(p.api_url)}</td><td>${esc(p.currency)} · ${Number(p.units_per_eur)}</td><td>${Number(p.profit_percent)}%</td><td>${esc(p.last_synced_at||'Never')}</td><td><div class="actions"><button class="btn" onclick="edit(${Number(p.id)})">Edit</button><button class="btn primary" onclick="sync(${Number(p.id)},this)">Sync services</button><button class="btn" onclick="balance(${Number(p.id)})">Balance</button></div></td></tr>`).join('')+'</tbody></table>';document.getElementById('review').innerHTML=state.review.length?state.review.map(j=>`<p>#${Number(j.order_id)} · ${esc(j.description)} · ${esc(j.provider_status)} ${j.state==='review'?`<button class="btn" onclick="reconcile(${Number(j.order_id)})">Link confirmed order</button>`:''}</p>`).join(''):'<p class="empty">No submissions awaiting review.</p>';}
function edit(id){const p=state.providers.find(x=>Number(x.id)===id);el('id').value=p.id;el('name').value=p.name;el('url').value=p.api_url;el('key').value='';el('currency').value=p.currency;el('fx').value=p.units_per_eur;el('profit').value=p.profit_percent;el('active').checked=!!Number(p.active);window.scrollTo({top:0,behavior:'smooth'});}
function newProvider(){document.getElementById('provider-form').reset();el('id').value='';}
async function sync(id,b){b.disabled=true;try{const r=await send('sync',{id});toast(`${r.imported} services synced. ${r.unsupported} unsupported and ${r.invalid} invalid services skipped.`);await load();}catch(e){toast(e.message,'error');b.disabled=false;}}
async function balance(id){try{const r=await send('balance',{id});toast(`${r.currency} ${r.balance}`);}catch(e){toast(e.message,'error');}}
async function reconcile(order_id){const remote_order=prompt('Provider order ID confirmed for this customer order:');if(!remote_order)return;try{await send('reconcile',{order_id,remote_order});await load();toast('Order linked for status tracking');}catch(e){toast(e.message,'error');}}
document.getElementById('provider-form').onsubmit=async e=>{e.preventDefault();const b=e.submitter;b.disabled=true;try{const r=await send('save',{id:Number(el('id').value),name:el('name').value,api_url:el('url').value,api_key:el('key').value,currency:el('currency').value,units_per_eur:el('fx').value,profit_percent:el('profit').value,active:el('active').checked});el('key').value='';el('id').value=r.id;toast(r.message);await load();}catch(e){toast(e.message,'error');}finally{b.disabled=false;}};load().catch(e=>toast(e.message,'error'));
</script>
<?php admin_footer(); ?>
