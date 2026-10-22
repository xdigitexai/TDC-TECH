'use strict';
function trackStore(){return []}function marketStore(){return []}
window.renderWebTab=i=>i>=5?'<div class="card"><h3>Database template catalog</h3><a class="btn primary" href="/admin/templates">Manage templates, live demos, prices and protected ZIPs</a></div>':renderOrders(data.orders||[]);
const serviceDraw=window.draw;
window.draw=()=>{
  serviceDraw();
  if(slug!=='press') return;
  const rows=root.querySelectorAll('table[data-orders] tbody tr');
  for(const [i,row] of [...rows].entries()){
    const order=(data.orders||[])[i];
    if(!order) continue;
    const actions=row.lastElementChild;
    for(const [label,status] of [['In Review','pending'],['In Progress','processing'],['Completed','completed'],['Delivered','delivered']]){
      const button=document.createElement('button');
      button.textContent=label;
      button.className='btn small';
      button.onclick=()=>quickStatus(order.id,status);
      actions.append(button);
    }
    const stage=document.createElement('p');
    stage.textContent='Delivery: '+(order.delivery_stage||order.status).replaceAll('_',' ');
    actions.append(stage);
    const cell=row.children[2];
    if(cell){
      for(const file of order.media||[]){
        const a=document.createElement('a');
        a.href='/api/music-media.php?id='+file.id;
        a.textContent=file.kind+': '+file.original_name;
        a.style.display='block';
        cell.append(a);
      }
      const details=document.createElement('details'),summary=document.createElement('summary'),pre=document.createElement('pre');
      summary.textContent='View submitted brief';
      try{ pre.textContent=JSON.stringify(JSON.parse(order.details||'{}'),null,2); }catch{ pre.textContent=order.details; }
      pre.style.cssText='white-space:pre-wrap;overflow-wrap:anywhere';
      details.append(summary,pre);
      cell.append(details);
    }
  }
};
window.makeInvoice=id=>window.open('/api/document.php?order='+encodeURIComponent(id),'_blank','noopener');

if(data)draw();
