'use strict';
// The selected private media IDs travel with the order, never with a public URL.
const tdcUploads=new Map();let tdcUploadBusy=0;
document.addEventListener('DOMContentLoaded',()=>{
 for(const zone of document.querySelectorAll('.upload-zone')){
  if(zone.closest('#page-music'))continue;
  const page=zone.closest('[id^="page-"]');if(!page)continue;
  const input=document.createElement('input');input.type='file';input.multiple=true;input.hidden=true;input.accept='.jpg,.jpeg,.png,.webp,.mp4,.pdf,.docx';
  const status=document.createElement('p');status.setAttribute('role','status');zone.append(input,status);zone.tabIndex=0;zone.setAttribute('role','button');zone.setAttribute('aria-label','Choose or drop service attachments');
  zone.onclick=e=>{if(e.target!==input)input.click()};zone.onkeydown=e=>{if(['Enter',' '].includes(e.key)){e.preventDefault();input.click()}};
  const upload=async files=>{tdcUploadBusy++;input.disabled=true;try{const me=await fetch('/api/me.php',{cache:'no-store'}).then(r=>r.json());for(const file of files){status.textContent='Uploading '+file.name+'…';const body=new FormData();body.append('media',file);body.append('kind','document');const r=await fetch('/api/music-media.php',{method:'POST',headers:{'X-CSRF-Token':me.csrf},body}),d=await r.json();if(!r.ok)throw Error(d.error||'Upload failed');const ids=tdcUploads.get(page.id)||[];ids.push(d.id);tdcUploads.set(page.id,ids);const link=document.createElement('a');link.textContent=d.name;link.href='/api/music-media.php?id='+d.id;link.onclick=e=>e.stopPropagation();zone.after(link);status.textContent='Uploaded '+d.name;}}catch(e){status.textContent=e.message;showToast(e.message,'error')}finally{tdcUploadBusy--;input.disabled=false;input.value=''}};
  input.onchange=()=>upload([...input.files]);for(const event of ['dragover','dragenter'])zone.addEventListener(event,e=>{e.preventDefault();zone.classList.add('drag-active')});zone.addEventListener('dragleave',()=>zone.classList.remove('drag-active'));zone.addEventListener('drop',e=>{e.preventDefault();zone.classList.remove('drag-active');if(!input.disabled)upload([...e.dataTransfer.files])});
 }
 const order=window.placeOrder;
 window.placeOrder=async(label,price,details={})=>{if(tdcUploadBusy){showToast('Wait for attachments to finish uploading.','info');return}const page=[...document.querySelectorAll('[id^="page-"]')].find(p=>p.classList.contains('active')&&tdcUploads.has(p.id));if(page)details={...details,media_ids:[...new Set([...(details.media_ids||[]),...tdcUploads.get(page.id)])]};const result=await order(label,price,details);if(result&&page)tdcUploads.delete(page.id);return result};
});
