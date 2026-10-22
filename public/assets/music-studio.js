'use strict';
/* TDC Music Studio
   - Distribute Release wizard (single / album / video)
   - Artist profile claim accordions
   - Project Tracker release catalog
   All calls go through the existing /api/music-media.php, /api/orders.php,
   /api/support.php, /api/royalties.php endpoints. */
(function(){
  const $=(sel,root)=> (root||document).querySelector(sel);
  const $$=(sel,root)=> Array.prototype.slice.call((root||document).querySelectorAll(sel));
  const esc=function(v){
    return String(v==null?'':v).replace(/[&<>"']/g,function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  };
  function toast(msg,type){ if(typeof window.showToast==='function') window.showToast(msg,type||'success'); }
  function randHex(n){
    const bytes=new Uint8Array(Math.ceil(n/2));
    (window.crypto||window.msCrypto).getRandomValues(bytes);
    let out=''; for(let i=0;i<bytes.length;i++) out+=('0'+bytes[i].toString(16)).slice(-2);
    return out.slice(0,n);
  }
  function money(n){ return '€'+Number(n||0).toFixed(2); }

  /* ── API helpers ─────────────────────────────────────────────── */
  let csrfToken='';
  async function csrf(){
    if(csrfToken) return csrfToken;
    const r=await fetch('/api/me.php',{credentials:'same-origin',cache:'no-store'});
    const d=await r.json().catch(function(){ return {}; });
    csrfToken=d.csrf||'';
    return csrfToken;
  }
  async function postJSON(url,payload){
    const token=await csrf();
    const r=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify(payload)});
    const d=await r.json().catch(function(){ return {}; });
    if(!r.ok) throw new Error(d.error||'Request failed. Please retry.');
    return d;
  }
  async function uploadFile(file,kind){
    const token=await csrf();
    const body=new FormData(); body.append('media',file); body.append('kind',kind);
    const r=await fetch('/api/music-media.php',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':token},body:body});
    const d=await r.json().catch(function(){ return {}; });
    if(!r.ok) throw new Error(d.error||'Upload failed.');
    return {id:d.id,name:d.name||file.name,kind:d.kind||kind,mime:file.type||'',size:file.size||0};
  }
  async function createTicket(subject,body,mediaIds){
    const payload={action:'create',subject:String(subject).slice(0,160),body:String(body).slice(0,3900),request_id:randHex(24)};
    if(mediaIds&&mediaIds.length) payload.media_ids=mediaIds.slice(0,5);
    const d=await postJSON('/api/support.php',payload);
    return d.id;
  }

  /* ══════════════════════════════════════════════════════════════
     1. DISTRIBUTE RELEASE WIZARD
     ══════════════════════════════════════════════════════════════ */
  const GENRES=['Afrobeats','Hip-Hop / Rap','R&B / Soul','Pop','Electronic','Gospel','Drill','Dancehall','Amapiano','Reggae','Rock','Jazz','Classical','Country','Latin','Other'];
  const LANGUAGES=['English','French','Swahili','Yoruba','Spanish','Portuguese','Arabic','Lingala','Zulu','German','Other'];
  const DSP_OUTLETS=['Spotify','Apple Music','YouTube Music','TikTok','Tidal','Amazon Music','Deezer','Pandora','Napster','Shazam','Instagram / Facebook','+ 140 more DSPs'];
  const VIDEO_OUTLETS=['YouTube','VEVO','Apple Music','TikTok','Instagram Reels','Facebook','Vimeo','Dailymotion','X (Twitter)'];
  const STEP_NAMES=['Choose type','Details','Uploads','Platforms','Review'];

  const W={
    kind:'', format:'', step:1, price:15, busy:false,
    single:{title:'',artist:'',featured:'',genre:'Afrobeats',language:'English',date:'',isrc:'',upc:''},
    album:{title:'',artist:'',genres:'',copyright:'',date:'',upc:'',tracks:[{title:'',isrc:''}]},
    video:{title:'',artist:'',desc:'',date:''},
    media:{audio:[],artwork:null,video:null},
    outlets:[]
  };
  let priceLoaded=false;

  function defaultDate(){
    const d=new Date(); d.setDate(d.getDate()+21);
    return d.toISOString().slice(0,10);
  }
  function ean13(){
    let base='20'; for(let i=0;i<10;i++) base+=''+Math.floor(Math.random()*10);
    let sum=0; for(let i=0;i<12;i++) sum+=(+base[i])*(i%2?3:1);
    return base+''+((10-(sum%10))%10);
  }
  function autoIsrc(){
    let n=''; for(let i=0;i<5;i++) n+=''+Math.floor(Math.random()*10);
    return 'TDC'+String(new Date().getFullYear()).slice(2)+n;
  }

  function wizard(){
    if(!$('#music-studio')) return;
    const stepsEl=$('#ms-steps');
    const panelEl=$('#ms-panel');
    const backBtn=$('#ms-back');
    const nextBtn=$('#ms-next');
    if(!stepsEl||!panelEl||!backBtn||!nextBtn) return;

    function renderSteps(){
      stepsEl.innerHTML=STEP_NAMES.map(function(name,i){
        const n=i+1;
        const cls=n===W.step?'is-active':(n<W.step?'is-done':'');
        return '<li class="ms-step '+cls+'"><span class="ms-step-num">'+(n<W.step?'✓':n)+'</span><span class="ms-step-txt">'+
          (n===2&&W.kind==='video'?'Video details':name)+'</span></li>';
      }).join('');
    }
    function renderNav(){
      backBtn.style.visibility=W.step===1?'hidden':'visible';
      nextBtn.disabled=W.busy;
      nextBtn.textContent=W.step===5?('Submit release'):'Continue';
      const hint=$('#ms-hint');
      if(hint) hint.textContent=W.step===5?'Your wallet is debited on submit. Review everything first.':'Step '+W.step+' of 5';
    }

    /* ── step 1: type ── */
    function panelType(){
      return '<div class="ms-card"><div class="ms-card-hd"><h4>What are you distributing?</h4><small>Pick one to continue</small></div>'+
        '<div class="ms-choice-grid">'+
          choice('music','🎵','Distribute Music','Singles and albums delivered to 150+ DSPs with ISRC, UPC and royalty collection.')+
          choice('video','🎬','Distribute Video','Music videos delivered to YouTube, VEVO and video platforms with artwork thumbnails.')+
        '</div></div>';
    }
    function choice(kind,ico,title,desc){
      return '<button type="button" class="ms-choice '+(W.kind===kind?'is-sel':'')+'" data-kind="'+kind+'">'+
        '<span class="ms-choice-ico">'+ico+'</span><span><span class="ms-choice-t">'+title+'</span>'+
        '<span class="ms-choice-d">'+desc+'</span></span></button>';
    }

    /* ── step 2: details ── */
    function panelDetails(){
      if(W.kind==='video') return panelVideoDetails();
      const fmt=W.format||'single';
      const seg='<div class="ms-seg">'+
        '<button type="button" class="'+(fmt==='single'?'is-sel':'')+'" data-format="single">Single</button>'+
        '<button type="button" class="'+(fmt==='album'?'is-sel':'')+'" data-format="album">Album / EP</button></div>';
      return '<div class="ms-card"><div class="ms-card-hd"><h4>Release format</h4>'+seg+'</div>'+
        (fmt==='album'?albumFields():singleFields())+'</div>';
    }
    function genreOptions(selected){
      return GENRES.map(function(g){ return '<option'+(g===selected?' selected':'')+'>'+esc(g)+'</option>'; }).join('');
    }
    function languageOptions(selected){
      return LANGUAGES.map(function(g){ return '<option'+(g===selected?' selected':'')+'>'+esc(g)+'</option>'; }).join('');
    }
    function singleFields(){
      const f=W.single;
      return '<div class="form-grid form-grid-2">'+
        field('ms-title','Song title','e.g. High Experience','text',f.title,true)+
        field('ms-artist','Primary artist','Artist name as on DSPs','text',f.artist,true)+
        field('ms-featured','Featured artists (optional)','ft. Artist Name','text',f.featured)+
        '<div class="field"><label>Genre</label><select id="ms-genre">'+genreOptions(f.genre)+'</select></div>'+
        '<div class="field"><label>Language</label><select id="ms-language">'+languageOptions(f.language)+'</select></div>'+
        field('ms-date','Release date','','date',f.date||defaultDate(),true)+
        field('ms-isrc','ISRC code (optional)','Leave blank to auto-generate','text',f.isrc)+
        field('ms-upc','UPC / EAN (optional)','Leave blank to auto-generate','text',f.upc)+
      '</div>';
    }
    function albumFields(){
      const f=W.album;
      return '<div class="form-grid form-grid-2">'+
        field('ms-album-title','Album / EP title','e.g. High Experience','text',f.title,true)+
        field('ms-album-artist','Primary artist','Album artist as on DSPs','text',f.artist,true)+
        field('ms-album-genres','Genres','Afrobeats, R&B','text',f.genres)+
        field('ms-album-copyright','Copyright','℗ 2026 Your Label','text',f.copyright)+
        field('ms-album-date','Release date','','date',f.date||defaultDate(),true)+
        field('ms-album-upc','UPC / EAN (optional)','Leave blank to auto-generate','text',f.upc)+
      '</div>'+
      '<div class="ms-card-hd" style="margin:18px 0 10px"><h4>Tracks</h4><small>Minimum one track · ISRC auto-generated per track</small></div>'+
      '<div class="ms-tracks" id="ms-tracks">'+f.tracks.map(trackRow).join('')+'</div>'+
      '<button type="button" class="ms-add-track" id="ms-add-track">＋ Add track</button>';
    }
    function trackRow(t,i){
      return '<div class="ms-track" data-track-row>'+
        '<span class="ms-track-no">'+(i+1)+'</span>'+
        '<input class="ms-track-title" type="text" placeholder="Track title" value="'+esc(t.title)+'" aria-label="Track '+(i+1)+' title">'+
        '<input class="ms-track-isrc ms-track-isrc-i" type="text" placeholder="ISRC (optional)" value="'+esc(t.isrc)+'" aria-label="Track '+(i+1)+' ISRC">'+
        '<button type="button" data-remove-track="'+i+'" aria-label="Remove track '+(i+1)+'">×</button>'+
      '</div>';
    }
    function panelVideoDetails(){
      const f=W.video;
      return '<div class="ms-card"><div class="ms-card-hd"><h4>Video details</h4><small>Delivered to video platforms</small></div>'+
        '<div class="form-grid form-grid-2">'+
          field('ms-video-title','Video title','e.g. High Experience (Official Video)','text',f.title,true)+
          field('ms-video-artist','Artist name','Artist name as on platforms','text',f.artist,true)+
          field('ms-video-date','Release date','','date',f.date||defaultDate(),true)+
          '<div class="field"><label>Description / credits</label><textarea id="ms-video-desc" rows="4" placeholder="Director, label, credits, description">'+esc(f.desc)+'</textarea></div>'+
        '</div></div>';
    }
    function field(id,label,ph,type,value,required){
      return '<div class="field"><label for="'+id+'">'+esc(label)+'</label><input id="'+id+'" type="'+(type||'text')+'" placeholder="'+esc(ph||'')+'" value="'+esc(value||'')+'"'+(required?' required':'')+'></div>';
    }

    /* ── step 3: uploads ── */
    function panelUploads(){
      const music=W.kind!=='video';
      let html='<div class="ms-card"><div class="ms-card-hd"><h4>'+(music?'Audio & artwork':'Video & thumbnail')+'</h4>'+
        '<small>'+(music?'Audio plus square artwork are required':'Video plus square thumbnail are required')+'</small></div>';
      if(music){
        html+=uploadSlot('audio','Upload audio track'+(W.media.audio.length>1?'s':''),'WAV, FLAC, MP3, M4A or OGG · up to 100 MB each','.wav,.flac,.mp3,.m4a,.ogg',true);
        html+=uploadSlot('artwork','Upload cover artwork','Any image — we crop and optimise it automatically','.jpg,.jpeg,.png,.webp,.gif,.bmp',false);
      }else{
        html+=uploadSlot('video','Upload music video','MP4, WebM or MOV · up to 100 MB','.mp4,.webm,.mov',false);
        html+=uploadSlot('artwork','Upload video thumbnail','Any image — we crop and optimise it automatically','.jpg,.jpeg,.png,.webp,.gif,.bmp',false);
      }
      html+='<p class="ms-form-note">Files stay private in your account. Each release costs '+money(W.price)+' from your wallet.</p></div>';
      return html;
    }
    function uploadSlot(slot,title,hint,accept,multiple){
      const list=slot==='audio'?W.media.audio:(W.media[slot]?[W.media[slot]]:[]);
      const has=list.length?' has-file':'';
      let files='';
      if(list.length){
        files='<ul class="ms-files">'+list.map(function(f){
          return '<li class="ms-file"><b>'+esc(f.name)+'</b><span>'+esc(f.kind)+'</span>'+
            '<button type="button" data-remove-slot="'+slot+'" data-remove-id="'+esc(f.id)+'" aria-label="Remove '+esc(f.name)+'">×</button></li>';
        }).join('')+'</ul>';
      }
      return '<div class="ms-slot"><div class="upload-zone ms-drop'+has+'" data-slot="'+slot+'" tabindex="0" role="button">'+
        '<svg viewBox="0 0 24 24"><path d="M12 16V3m-5 5 5-5 5 5M4 15v5h16v-5"/></svg>'+
        '<p>'+esc(title)+'</p><span>'+esc(hint)+'</span>'+
        '<input type="file" hidden accept="'+esc(accept)+'"'+(multiple?' multiple':'')+'></div>'+files+'</div>';
    }

    /* ── step 4: platforms ── */
    function panelPlatforms(){
      const list=W.kind==='video'?VIDEO_OUTLETS:DSP_OUTLETS;
      const label=W.kind==='video'?'Platforms':'DSP outlets';
      return '<div class="ms-card"><div class="ms-card-hd"><h4>'+label+'</h4><small>Select at least one</small></div>'+
        '<div class="platform-grid ms-chip-grid">'+list.map(function(name){
          const sel=W.outlets.indexOf(name)!==-1?' sel':'';
          return '<div class="platform-chip'+sel+'" data-outlet="'+esc(name)+'">'+esc(name)+'</div>';
        }).join('')+'</div>'+
        '<p class="ms-form-note">All selected stores receive the release on the same date. Delivery normally takes 24–72 hours after approval.</p></div>';
    }

    /* ── step 5: review ── */
    function panelReview(){
      const rows=[];
      rows.push(['Release type',W.kind==='video'?'Music video':(W.format==='album'?'Album / EP':'Single')]);
      if(W.kind==='video'){
        rows.push(['Video title',W.video.title]);
        rows.push(['Artist',W.video.artist]);
        rows.push(['Release date',W.video.date]);
        rows.push(['Description',W.video.desc||'—']);
        rows.push(['Video file',W.media.video?W.media.video.name:'—']);
      }else if(W.format==='album'){
        rows.push(['Album title',W.album.title]);
        rows.push(['Primary artist',W.album.artist]);
        rows.push(['Genres',W.album.genres||'—']);
        rows.push(['Copyright',W.album.copyright||'Auto-assigned']);
        rows.push(['Release date',W.album.date]);
        rows.push(['Tracks',W.album.tracks.filter(function(t){return t.title;}).map(function(t,i){return (i+1)+'. '+t.title;}).join(' · ')||'—']);
        rows.push(['UPC',W.album.upc||'Auto-generate']);
      }else{
        rows.push(['Song title',W.single.title]);
        rows.push(['Primary artist',W.single.artist]);
        rows.push(['Featured',W.single.featured||'—']);
        rows.push(['Genre / language',W.single.genre+' · '+W.single.language]);
        rows.push(['Release date',W.single.date]);
        rows.push(['ISRC',W.single.isrc||'Auto-generate']);
        rows.push(['UPC',W.single.upc||'Auto-generate']);
      }
      rows.push(['Audio files',String(W.media.audio.length)]);
      rows.push(['Artwork',W.media.artwork?W.media.artwork.name:'—']);
      const outlets=W.outlets.map(function(o){return '<span>'+esc(o)+'</span>';}).join(' ');
      rows.push(['Platforms',outlets||'—']);
      const priceRow='<div class="price-calc" style="margin-top:14px;display:flex;align-items:center;justify-content:space-between;gap:12px">'+
        '<div><p class="price-calc-label">TOTAL DUE FROM WALLET</p><p class="price-calc-note">One-time distribution fee · royalties stay 100% yours</p></div>'+
        '<p class="price-calc-amount" style="margin:0">'+money(W.price)+'</p></div>';
      return '<div class="ms-card"><div class="ms-card-hd"><h4>Review &amp; submit</h4><small>Confirm the details below</small></div>'+
        '<dl class="ms-review">'+rows.map(function(r){return '<dt>'+esc(r[0])+'</dt><dd>'+(r[0]==='Platforms'?r[1]:esc(r[1]))+'</dd>';}).join('')+'</dl>'+
        priceRow+'</div>';
    }

    /* ── sync helpers ── */
    function val(id){
      const e=document.getElementById(id);
      return e?String(e.value==null?'':e.value).trim():'';
    }
    function syncDetails(){
      if(W.kind==='video'){
        W.video.title=val('ms-video-title'); W.video.artist=val('ms-video-artist');
        W.video.date=val('ms-video-date'); W.video.desc=val('ms-video-desc');
        return;
      }
      if(W.format==='album'){
        W.album.title=val('ms-album-title'); W.album.artist=val('ms-album-artist');
        W.album.genres=val('ms-album-genres'); W.album.copyright=val('ms-album-copyright');
        W.album.date=val('ms-album-date'); W.album.upc=val('ms-album-upc');
        const rows=$$('#ms-tracks [data-track-row]');
        if(rows.length){
          W.album.tracks=rows.map(function(row){
            return {title:($('.ms-track-title',row)||{}).value?$('.ms-track-title',row).value.trim():'',isrc:($('.ms-track-isrc',row)||{}).value?$('.ms-track-isrc',row).value.trim():''};
          });
        }
      }else{
        W.single.title=val('ms-title'); W.single.artist=val('ms-artist');
        W.single.featured=val('ms-featured'); W.single.genre=val('ms-genre')||W.single.genre;
        W.single.language=val('ms-language')||W.single.language; W.single.date=val('ms-date');
        W.single.isrc=val('ms-isrc'); W.single.upc=val('ms-upc');
      }
    }

    /* ── validation ── */
    function validate(step){
      if(step===1){
        if(!W.kind){ toast('Choose music or video distribution.','error'); return false; }
      }
      if(step===2){
        syncDetails();
        const title=W.kind==='video'?W.video.title:(W.format==='album'?W.album.title:W.single.title);
        const artist=W.kind==='video'?W.video.artist:(W.format==='album'?W.album.artist:W.single.artist);
        if(!title){ toast('Enter a release title.','error'); return false; }
        if(!artist){ toast('Enter the primary artist name.','error'); return false; }
        if(W.kind==='music'&&W.format==='album'&&!W.album.tracks.some(function(t){return t.title;})){
          toast('Add at least one track with a title.','error'); return false;
        }
      }
      if(step===3){
        if(W.kind==='video'){
          if(!W.media.video){ toast('Upload the music video.','error'); return false; }
          if(!W.media.artwork){ toast('Upload a thumbnail image.','error'); return false; }
        }else{
          if(!W.media.audio.length){ toast('Upload at least one audio file.','error'); return false; }
          if(!W.media.artwork){ toast('Upload a cover image.','error'); return false; }
        }
      }
      if(step===4&&!W.outlets.length){ toast('Select at least one platform.','error'); return false; }
      return true;
    }

    /* ── render ── */
    function render(){
      renderSteps();
      let html='';
      if(W.step===1) html=panelType();
      else if(W.step===2) html=panelDetails();
      else if(W.step===3) html=panelUploads();
      else if(W.step===4) html=panelPlatforms();
      else html=panelReview();
      panelEl.innerHTML=html;
      panelEl.className='ms-panel is-active';
      renderNav();
      bindPanel();
    }
    function bindPanel(){
      $$('#ms-panel [data-kind]').forEach(function(btn){
        btn.addEventListener('click',function(){
          W.kind=btn.dataset.kind;
          if(W.kind==='music'&&!W.format) W.format='single';
          W.outlets=[];
          render();
        });
      });
      $$('#ms-panel [data-format]').forEach(function(btn){
        btn.addEventListener('click',function(){
          syncDetails();
          W.format=btn.dataset.format;
          render();
        });
      });
      const add=$('#ms-add-track');
      if(add) add.addEventListener('click',function(){
        syncDetails();
        W.album.tracks.push({title:'',isrc:''});
        render();
        const rows=$$('#ms-tracks [data-track-row]');
        const last=rows[rows.length-1];
        if(last){ const input=$('.ms-track-title',last); if(input) input.focus(); }
      });
      $$('#ms-panel [data-remove-track]').forEach(function(btn){
        btn.addEventListener('click',function(){
          syncDetails();
          const idx=parseInt(btn.dataset.removeTrack,10);
          if(W.album.tracks.length<=1){ W.album.tracks=[{title:'',isrc:''}]; }
          else W.album.tracks.splice(idx,1);
          render();
        });
      });
      $$('#ms-panel [data-outlet]').forEach(function(chip){
        chip.addEventListener('click',function(){
          const name=chip.dataset.outlet;
          const i=W.outlets.indexOf(name);
          if(i===-1) W.outlets.push(name); else W.outlets.splice(i,1);
          chip.classList.toggle('sel');
        });
      });
      $$('#ms-panel .ms-drop').forEach(function(zone){
        const slot=zone.dataset.slot;
        const input=zone.querySelector('input[type=file]');
        if(!input) return;
        zone.addEventListener('click',function(e){ if(e.target!==input) input.click(); });
        zone.addEventListener('keydown',function(e){ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); input.click(); } });
        zone.addEventListener('dragover',function(e){ e.preventDefault(); zone.classList.add('is-busy'); });
        zone.addEventListener('dragleave',function(){ zone.classList.remove('is-busy'); });
        zone.addEventListener('drop',function(e){
          e.preventDefault(); zone.classList.remove('is-busy');
          if(input.files) handleFiles(slot,Array.prototype.slice.call(e.dataTransfer.files||[]),zone);
        });
        input.addEventListener('change',function(){
          handleFiles(slot,Array.prototype.slice.call(input.files||[]),zone);
        });
      });
      $$('#ms-panel [data-remove-slot]').forEach(function(btn){
        btn.addEventListener('click',function(e){
          e.stopPropagation();
          const slot=btn.dataset.removeSlot, id=btn.dataset.removeId;
          if(slot==='audio') W.media.audio=W.media.audio.filter(function(f){return f.id!==id;});
          else if(W.media[slot]&&W.media[slot].id===id) W.media[slot]=null;
          render();
        });
      });
    }
    async function handleFiles(slot,files,zone){
      if(!files.length) return;
      const input=zone.querySelector('input[type=file]');
      if(input) input.disabled=true;
      zone.classList.add('is-busy');
      try{
        for(const file of files){
          const rec=await uploadFile(file,slot);
          if(slot==='audio') W.media.audio.push(rec); else W.media[slot]=rec;
        }
        render();
        toast(files.length+' file'+(files.length>1?'s':'')+' uploaded.','success');
      }catch(err){
        toast(err.message||'Upload failed.','error');
        zone.classList.remove('is-busy');
        if(input){ input.disabled=false; input.value=''; }
      }
    }

    /* ── navigation ── */
    function go(step){
      W.step=Math.max(1,Math.min(5,step));
      render();
    }
    backBtn.addEventListener('click',function(){
      if(W.step===1) return;
      if(W.step===2) syncDetails();
      go(W.step-1);
    });
    nextBtn.addEventListener('click',function(){
      if(W.busy) return;
      if(W.step===5){ submit(); return; }
      if(!validate(W.step)) return;
      if(W.step===2) syncDetails();
      go(W.step+1);
    });

    /* ── submit ── */
    async function submit(){
      if(W.busy) return;
      W.busy=true; renderNav();
      try{
        const mediaIds=[];
        if(W.kind==='video'){
          if(W.media.video) mediaIds.push(W.media.video.id);
        }else{
          W.media.audio.forEach(function(f){ mediaIds.push(f.id); });
        }
        if(W.media.artwork) mediaIds.push(W.media.artwork.id);

        const details={release_kind:W.kind==='video'?'video':(W.format==='album'?'album':'single'),media_ids:mediaIds,outlets:W.outlets.slice(0,30)};
        let title='',label='';
        if(W.kind==='video'){
          details.release_title=W.video.title;
          details.artist=W.video.artist;
          details.description=W.video.desc;
          details.release_date=W.video.date;
          details.isrc=autoIsrc();
          details.upc=ean13();
          title=W.video.title;
          label='Music video distribution — '+title;
        }else if(W.format==='album'){
          const tracks=W.album.tracks.filter(function(t){return t.title;}).map(function(t){
            return {title:t.title,isrc:t.isrc||autoIsrc()};
          });
          details.release_title=W.album.title;
          details.artist=W.album.artist;
          details.genres=W.album.genres?W.album.genres.split(/[,;]\s*/).filter(Boolean):[];
          details.genre=(details.genres[0]||'');
          details.language='';
          details.release_date=W.album.date;
          details.copyright=W.album.copyright;
          details.tracks=tracks;
          details.isrc=(tracks[0]&&tracks[0].isrc)||autoIsrc();
          details.upc=W.album.upc||ean13();
          title=W.album.title;
          label='Album distribution — '+title;
        }else{
          details.release_title=W.single.title;
          details.artist=W.single.artist;
          details.genre=W.single.genre;
          details.language=W.single.language;
          details.release_date=W.single.date;
          details.featured_artists=W.single.featured;
          details.isrc=W.single.isrc||autoIsrc();
          details.upc=W.single.upc||ean13();
          title=W.single.title;
          label='Music distribution — '+title;
        }
        if(typeof window.placeOrder!=='function') throw new Error('Checkout is unavailable. Reload the page.');
        const result=await window.placeOrder(label,0,details);
        if(!result){ return; }
        resetWizard();
        loadCatalog(true);
        loadOrderHistory(true);
        loadRoyalties(true);
        const tabs=$$('#page-music .svc-tab');
        if(typeof window.switchTab==='function'&&tabs[4]) window.switchTab('music',4,tabs[4]);
      }catch(err){
        toast(err.message||'Could not submit the release.','error');
      }finally{
        W.busy=false;
        if($('#music-studio')) renderNav();
      }
    }
    function resetWizard(){
      W.kind=''; W.format=''; W.step=1; W.outlets=[];
      W.single={title:'',artist:'',featured:'',genre:'Afrobeats',language:'English',date:'',isrc:'',upc:''};
      W.album={title:'',artist:'',genres:'',copyright:'',date:'',upc:'',tracks:[{title:'',isrc:''}]};
      W.video={title:'',artist:'',desc:'',date:''};
      W.media={audio:[],artwork:null,video:null};
      render();
    }

    render();

    /* Real service price (never hardcoded for charging; the server quotes it too). */
    if(!priceLoaded){
      priceLoaded=true;
      fetch('/api/catalog.php',{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){
        const svc=(d.services||[]).find(function(s){return s.slug==='music';});
        if(!svc) return;
        W.price=Number(svc.price)||W.price;
        const el=document.getElementById('ms-price-value');
        if(el) el.textContent=money(W.price);
        const note=document.getElementById('ms-price-note');
        if(note) note.textContent='Per release · 150+ DSPs · ISRC & UPC included';
        if(W.step===5) render();
      }).catch(function(){});
    }
  }

  /* ══════════════════════════════════════════════════════════════
     2. ARTIST PROFILES (accordions + claim tickets)
     ══════════════════════════════════════════════════════════════ */
  const PROFILE_DOCS=new Map();

  function bindDocZone(zone){
    const key=zone.dataset.msDoc;
    if(!key) return;
    const input=zone.querySelector('input[type=file]');
    if(!input) return;
    const list=document.querySelector('[data-ms-files="'+key+'"]');
    zone.addEventListener('click',function(e){ if(e.target!==input) input.click(); });
    zone.addEventListener('keydown',function(e){ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); input.click(); } });
    input.addEventListener('change',async function(){
      const file=(input.files||[])[0];
      if(!file) return;
      zone.classList.add('is-busy');
      try{
        const rec=await uploadFile(file,'document');
        PROFILE_DOCS.set(key,rec);
        zone.classList.add('has-file');
        if(list){
          list.innerHTML='<li class="ms-file"><b>'+esc(rec.name)+'</b><span>'+esc(rec.kind)+'</span>'+
            '<button type="button" data-doc-clear="'+esc(key)+'" aria-label="Remove file">×</button></li>';
          const clear=$('[data-doc-clear="'+key+'"]',list);
          if(clear) clear.addEventListener('click',function(){
            PROFILE_DOCS.delete(key); list.innerHTML=''; zone.classList.remove('has-file');
          });
        }
        toast('File uploaded.','success');
      }catch(err){
        toast(err.message||'Upload failed.','error');
      }finally{
        zone.classList.remove('is-busy');
        input.value='';
      }
    });
  }

  function formBody(form){
    const lines=[];
    $$('[data-label]',form).forEach(function(el){
      const value=String(el.value||'').trim();
      if(value) lines.push(el.dataset.label+': '+value);
    });
    return lines;
  }

  function initProfiles(){
    const root=document.getElementById('music-tab-1');
    if(!root) return;
    $$('.ms-drop',root).forEach(bindDocZone);
    $$('.ms-ticket-form',root).forEach(function(form){
      form.addEventListener('submit',async function(ev){
        ev.preventDefault();
        const btn=form.querySelector('button[type=submit]');
        const subject=form.dataset.subject||'Artist profile claim';
        const lines=formBody(form);
        const key=form.dataset.docKey||'';
        const doc=key?PROFILE_DOCS.get(key):null;
        if(doc) lines.push('Attachment: '+doc.name+' (media '+doc.id+')');
        if(!lines.length){ toast('Fill in at least one field before submitting.','error'); return; }
        const body=subject+'\n\n'+lines.join('\n')+'\n\nSent from the Music & Distribution dashboard.';
        const mediaIds=(doc&&/^image\//.test(doc.mime||''))?[doc.id]:[];
        if(btn){ btn.disabled=true; }
        try{
          const id=await createTicket(subject,body,mediaIds);
          toast('Ticket #'+id+' created. Our team will reply in support chat.','success');
          form.reset();
          if(key){ PROFILE_DOCS.delete(key); const list=document.querySelector('[data-ms-files="'+key+'"]'); if(list) list.innerHTML=''; }
          const details=form.closest('details');
          if(details) details.open=false;
        }catch(err){
          toast(err.message||'Could not create the ticket.','error');
        }finally{
          if(btn) btn.disabled=false;
        }
      });
    });
  }

  /* ══════════════════════════════════════════════════════════════
     3. PROJECT TRACKER — release catalog
     ══════════════════════════════════════════════════════════════ */
  const CAT={list:[],files:{},loaded:false,busy:false};

  function statusClass(status){
    const s=String(status||'').toLowerCase();
    if(s==='completed'||s==='delivered') return 'status-done';
    if(s==='processing'||s==='in_review') return 'status-review';
    return 'status-pending';
  }
  function statusText(status){
    const s=String(status||'pending').toLowerCase();
    if(s==='completed') return 'Completed';
    if(s==='processing') return 'Processing';
    if(s==='pending') return 'Pending';
    if(s==='rejected') return 'Rejected';
    if(s==='cancelled') return 'Cancelled';
    return s.charAt(0).toUpperCase()+s.slice(1);
  }
  function releaseStatusText(status){
    const map={pending:'Pending',distributed:'Distributed',takedown:'Taken Down',deleted:'Deleted'};
    return map[String(status||'pending').toLowerCase()]||String(status||'Pending');
  }
  function releaseStatusClass(status){
    const s=String(status||'pending').toLowerCase();
    if(s==='distributed') return 'status-done';
    if(s==='pending') return 'status-review';
    return 'status-pending';
  }
  function parseDetails(raw){
    if(!raw) return {};
    if(typeof raw==='object') return raw;
    try{ return JSON.parse(raw)||{}; }catch(e){ return {}; }
  }
  function coverFor(rel){
    if(rel.artworkId) return '/api/music-media.php?id='+encodeURIComponent(rel.artworkId)+'&inline';
    const ids=(rel.details&&rel.details.media_ids)||[];
    for(const id of ids){
      const f=CAT.files[id];
      if(f&&f.kind==='artwork') return '/api/music-media.php?id='+encodeURIComponent(id)+'&inline';
    }
    return '';
  }
  function releaseFiles(rel){
    const attached=Object.keys(CAT.files).map(function(key){ return CAT.files[key]; })
      .filter(function(f){ return String(f.order_id)===String(rel.id); });
    if(attached.length) return attached;
    return ((rel.details&&rel.details.media_ids)||[]).map(function(id){
      return CAT.files[id]||{id:id,original_name:'media '+String(id).slice(0,8),kind:'file'};
    });
  }
  function kindLabel(kind){
    const k=String(kind||'single').toLowerCase();
    if(k==='album'||k==='ep') return 'Album / EP';
    if(k==='video') return 'Music video';
    return 'Single';
  }
  function fmtMoney(value){ return '€'+Number(value||0).toFixed(2); }
  function fmtDate(value){
    if(!value) return '—';
    const d=new Date(String(value).replace(' ','T')+'Z');
    if(isNaN(d.getTime())) return String(value).slice(0,10);
    return d.toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'});
  }

  async function loadCatalog(force){
    const box=document.getElementById('ms-catalog');
    if(!box) return;
    if(CAT.busy) return;
    CAT.busy=true;
    if(force||!CAT.loaded) box.innerHTML='<p class="ms-muted">Loading your releases…</p>';
    try{
      const results=await Promise.all([
        fetch('/api/royalties.php',{credentials:'same-origin',cache:'no-store'}),
        fetch('/api/music-media.php',{credentials:'same-origin',cache:'no-store'})
      ]);
      const rel=await results[0].json().catch(function(){ return {}; });
      const med=await results[1].json().catch(function(){ return {}; });
      if(!results[0].ok) throw new Error(rel.error||'Could not load your releases.');
      const files={};
      (med.files||[]).forEach(function(f){ files[f.id]=f; });
      CAT.files=files;
      CAT.list=(rel.releases||[]).map(function(o){
        const d=parseDetails(o.details);
        return {
          id:o.id, raw:o, details:d,
          title:d.release_title||d.title||o.description||'Untitled release',
          artist:d.artist||d.primary_artist||'—',
          date:o.created_at, status:o.status,
          kind:d.release_kind||'single',
          upc:d.upc||'', isrc:d.isrc||'',
          artworkId:o.artwork_id||'',
          streams:Number(o.streams||0), playlists:Number(o.playlists||0),
          revenue:Number(o.revenue||0),
          releaseStatus:String(o.release_status||'pending').toLowerCase(),
          dsp:o.dsp_json||'', timeline:o.timeline||''
        };
      });
      CAT.loaded=true;
      renderCatalog();
    }catch(err){
      box.innerHTML='<div class="ms-empty"><strong>Catalog unavailable</strong><p>'+esc(err.message||'Try reloading in a moment.')+'</p></div>';
    }finally{
      CAT.busy=false;
    }
  }

  function renderCatalog(){
    const box=document.getElementById('ms-catalog');
    if(!box) return;
    if(!CAT.list.length){
      box.innerHTML='<div class="ms-empty"><strong>No releases yet</strong>'+
        '<p>Distribute your first single, album or video and it will appear here with live delivery status, streams and DSP breakdown.</p>'+
        '<button type="button" class="btn-primary" style="margin-top:14px" data-cat-start>Distribute a release</button></div>';
      const start=box.querySelector('[data-cat-start]');
      if(start) start.addEventListener('click',function(){
        const tabs=$$('#page-music .svc-tab');
        if(typeof window.switchTab==='function'&&tabs[0]) window.switchTab('music',0,tabs[0]);
      });
      return;
    }
    let streams=0, revenue=0, singles=0, albums=0, videos=0;
    CAT.list.forEach(function(rel){
      streams+=rel.streams; revenue+=rel.revenue;
      if(rel.kind==='video') videos++;
      else if(rel.kind==='album'||rel.kind==='ep') albums++;
      else singles++;
    });
    const mix=[singles+' single'+(singles===1?'':'s'),albums+' album'+(albums===1?'':'s')];
    if(videos) mix.push(videos+' video'+(videos===1?'':'s'));
    box.innerHTML='<div class="ms-stat-grid">'+
        '<div class="ms-stat"><b>Total streams</b><span>'+streams.toLocaleString()+'</span><small>Across every release</small></div>'+
        '<div class="ms-stat"><b>Tracks distributed</b><span>'+CAT.list.length+'</span><small>'+esc(mix.join(' · '))+'</small></div>'+
        '<div class="ms-stat is-money"><b>Total revenue</b><span>'+fmtMoney(revenue)+'</span><small>Reported by the stores</small></div>'+
      '</div>'+
      '<div class="ms-cat-top"><p class="ms-cat-title">Your catalog <span>'+CAT.list.length+' release'+(CAT.list.length>1?'s':'')+'</span></p>'+
      '<button type="button" class="btn-secondary" data-cat-refresh>Refresh</button></div>'+
      '<div class="ms-cat-grid">'+CAT.list.map(function(rel){
        const cover=coverFor(rel);
        return '<button type="button" class="ms-rel" data-release="'+rel.id+'">'+
          '<span class="ms-cover">'+(cover?'<img src="'+esc(cover)+'" alt="" loading="lazy">':'<span class="ms-cover-ph">♪</span>')+
          '<span class="status-badge '+releaseStatusClass(rel.releaseStatus)+'">'+esc(releaseStatusText(rel.releaseStatus))+'</span></span>'+
          '<span class="ms-rel-body"><span class="ms-rel-title">'+esc(rel.title)+'</span>'+
          '<span class="ms-rel-artist">'+esc(rel.artist)+'</span>'+
          '<span class="ms-rel-date">'+esc(kindLabel(rel.kind))+' · '+esc(fmtDate(rel.date))+'</span></span></button>';
      }).join('')+'</div>';
    const refresh=box.querySelector('[data-cat-refresh]');
    if(refresh) refresh.addEventListener('click',function(){ loadCatalog(true); });
    $$('[data-release]',box).forEach(function(card){
      card.addEventListener('click',function(){ openRelease(card.dataset.release); });
    });
  }

  function dspRows(json){
    let data=json;
    if(typeof data==='string'){
      try{ data=JSON.parse(data); }catch(e){ data={}; }
    }
    if(!data) return [];
    const rows=[];
    if(Array.isArray(data)){
      data.forEach(function(item){
        if(!item||typeof item!=='object') return;
        const name=item.name||item.label||item.dsp||item.platform||'DSP';
        const value=Number(item.value!=null?item.value:(item.streams!=null?item.streams:item.pct));
        if(isFinite(value)&&value>0) rows.push({name:String(name),value:value});
      });
    }else if(typeof data==='object'){
      Object.keys(data).forEach(function(key){
        const v=data[key];
        let value;
        if(v&&typeof v==='object') value=Number(v.streams!=null?v.streams:(v.value!=null?v.value:v.pct));
        else value=Number(v);
        if(isFinite(value)&&value>0) rows.push({name:key,value:value});
      });
    }
    return rows.sort(function(a,b){return b.value-a.value;}).slice(0,8);
  }

  function ensureModal(){
    let modal=document.getElementById('ms-modal');
    if(modal) return modal;
    modal=document.createElement('div');
    modal.className='ms-modal';
    modal.id='ms-modal';
    modal.setAttribute('aria-hidden','true');
    modal.innerHTML='<div class="ms-sheet" role="dialog" aria-modal="true" aria-label="Release details">'+
      '<button type="button" class="ms-close" data-ms-close aria-label="Close">×</button>'+
      '<div id="ms-modal-body"></div></div>';
    document.body.appendChild(modal);
    modal.addEventListener('click',function(e){
      if(e.target===modal||e.target.hasAttribute('data-ms-close')) closeModal();
    });
    document.addEventListener('keydown',function(e){
      if(e.key==='Escape'&&modal.classList.contains('open')) closeModal();
    });
    return modal;
  }
  function closeModal(){
    const modal=document.getElementById('ms-modal');
    if(modal){ modal.classList.remove('open'); modal.setAttribute('aria-hidden','true'); }
  }

  function openRelease(id){
    const rel=CAT.list.find(function(r){return String(r.id)===String(id);});
    if(!rel) return;
    const modal=ensureModal();
    const body=document.getElementById('ms-modal-body');
    const d=rel.details||{};
    const cover=coverFor(rel);
    const rows=dspRows(rel.dsp);
    const total=rows.reduce(function(s,r){return s+r.value;},0)||1;
    const tracks=Array.isArray(d.tracks)?d.tracks.map(function(t){return typeof t==='string'?{title:t}:(t||{});}).filter(function(t){return t.title;}):[];
    const genres=Array.isArray(d.genres)?d.genres.slice():((d.genres||d.genre)?String(d.genres||d.genre).split(/[,;]\s*/).filter(Boolean):[]);
    const outlets=Array.isArray(d.outlets)?d.outlets:[];
    const files=releaseFiles(rel);
    const info=[
      ['Order ID','#'+rel.id],
      ['Type',kindLabel(rel.kind)],
      ['Order status',statusText(rel.status)],
      ['Release date',d.release_date?fmtDate(d.release_date):fmtDate(rel.date)],
      ['ISRC',rel.isrc||'—'],
      ['UPC',rel.upc||'—'],
      ['Genre',genres.length?genres.join(', '):'—'],
      ['Language',d.language||'—'],
      ['Featured artists',d.featured_artists||'—'],
      ['Copyright',d.copyright||'—']
    ];
    body.innerHTML='<div class="ms-rel-hero">'+
        '<div class="ms-cover">'+(cover?'<img src="'+esc(cover)+'" alt="'+esc(rel.title)+' artwork">':'<span class="ms-cover-ph">♪</span>')+'</div>'+
        '<div><h3 class="ms-detail-title">'+esc(rel.title)+'</h3>'+
        '<p class="ms-detail-artist">'+esc(rel.artist)+'</p>'+
        '<p style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">'+
          '<span class="status-badge '+releaseStatusClass(rel.releaseStatus)+'">'+esc(releaseStatusText(rel.releaseStatus))+'</span>'+
          '<span class="status-badge ms-badge-neutral">'+esc(kindLabel(rel.kind))+'</span>'+
          '<span class="ms-rel-date">'+esc(fmtDate(rel.date))+'</span></p>'+
        '<div class="ms-detail-meta">'+
          '<div class="ms-meta"><b>Total streams</b><span>'+rel.streams.toLocaleString()+'</span></div>'+
          '<div class="ms-meta"><b>Total revenue</b><span>'+fmtMoney(rel.revenue)+'</span></div>'+
          '<div class="ms-meta"><b>Playlists</b><span>'+rel.playlists.toLocaleString()+'</span></div>'+
          '<div class="ms-meta"><b>UPC</b><span>'+esc(rel.upc||'—')+'</span></div>'+
        '</div></div></div>'+
      '<div class="ms-detail-grid">'+
        '<div class="ms-panel-box"><h5>Release information</h5><dl class="ms-info">'+
          info.map(function(row){return '<dt>'+esc(row[0])+'</dt><dd>'+esc(row[1])+'</dd>';}).join('')+
        '</dl>'+(tracks.length?'<h5 style="margin-top:14px">Tracks</h5><ol class="ms-track-list">'+
          tracks.map(function(t){return '<li>'+esc(t.title)+(t.isrc?' <small style="color:var(--muted)">'+esc(t.isrc)+'</small>':'')+'</li>';}).join('')+
        '</ol>':'')+'</div>'+
        '<div class="ms-panel-box"><h5>Stores &amp; platforms</h5>'+
          (outlets.length?'<div class="ms-chip-list">'+outlets.map(function(o){return '<span>'+esc(o)+'</span>';}).join('')+'</div>':'<p class="ms-muted">No platform list stored for this release.</p>')+
          '<h5 style="margin-top:14px">Uploaded files</h5>'+
          (files.length?'<ul class="ms-file-list">'+files.map(function(f){
            return '<li><a href="/api/music-media.php?id='+encodeURIComponent(f.id)+'" target="_blank" rel="noopener"><b>'+esc(f.original_name||f.kind)+'</b><span>'+esc(f.kind||'file')+'</span></a></li>';
          }).join('')+'</ul>':'<p class="ms-muted">No files attached to this order yet.</p>')+
        '</div>'+
      '</div>'+
      '<div class="ms-detail-grid">'+
        '<div class="ms-panel-box"><h5>DSP breakdown</h5>'+
          (rows.length?rows.map(function(r){
            const pct=Math.max(2,Math.round(r.value/total*100));
            return '<div class="dsp-row"><span class="dsp-name">'+esc(r.name)+'</span>'+
              '<div class="dsp-bar-wrap"><div class="dsp-bar" style="width:'+pct+'%"></div></div>'+
              '<span class="dsp-pct">'+pct+'%</span></div>';
          }).join(''):'<p class="ms-muted">No DSP report yet. Streaming data appears after the release goes live.</p>')+
        '</div>'+
        '<div class="ms-panel-box"><h5>Delivery timeline</h5>'+
          (rel.timeline?'<div class="ms-timeline">'+esc(rel.timeline)+'</div>':
            '<div class="ms-timeline">'+esc(fmtDate(rel.date))+' — submitted\nCurrent status: '+esc(statusText(rel.status))+'</div>')+
        '</div>'+
      '</div>'+
      '<div class="ms-actions">'+
        '<button type="button" data-act="edit">Edit release</button>'+
        '<button type="button" data-act="link">Smart link</button>'+
        '<button type="button" data-act="pitch">Pitch to playlists</button>'+
        '<button type="button" data-act="archive">Archive</button>'+
        '<button type="button" data-act="codes">Download ISRC/UPC</button>'+
      '</div>';
    body.querySelectorAll('[data-act]').forEach(function(btn){
      btn.addEventListener('click',function(){ action(btn.dataset.act,rel); });
    });
    modal.classList.add('open');
    modal.setAttribute('aria-hidden','false');
  }

  async function action(act,rel){
    if(act==='edit'){
      toast('Contact support to change a live release — we will update the metadata with the stores.','info');
      return;
    }
    if(act==='archive'){
      toast('Archive requested. Support will hide this release from your catalog.','info');
      return;
    }
    if(act==='link'){
      const link=window.location.origin+'/r/'+rel.id;
      try{
        await navigator.clipboard.writeText(link);
        toast('Smart link copied: '+link,'success');
      }catch(e){
        window.prompt('Copy your smart link:',link);
      }
      return;
    }
    if(act==='codes'){
      const text='Release: '+rel.title+'\nArtist: '+rel.artist+'\nUPC: '+(rel.upc||'pending')+'\nISRC: '+(rel.isrc||'pending')+'\nOrder: #'+rel.id+'\n';
      const blob=new Blob([text],{type:'text/plain'});
      const url=URL.createObjectURL(blob);
      const a=document.createElement('a');
      a.href=url; a.download='release-'+rel.id+'-codes.txt';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function(){ URL.revokeObjectURL(url); },2000);
      toast('Codes downloaded.','success');
      return;
    }
    if(act==='pitch'){
      try{
        const body='Playlist pitch request\n\nRelease: '+rel.title+'\nArtist: '+rel.artist+'\nOrder: #'+rel.id+
          '\nUPC: '+(rel.upc||'—')+'\nISRC: '+(rel.isrc||'—')+
          '\n\nPlease pitch this release to editorial playlists. Target DSPs and playlist ideas:\n';
        const id=await createTicket('Playlist pitch — '+rel.title,body);
        toast('Pitch request sent as ticket #'+id+'.','success');
      }catch(err){
        toast(err.message||'Could not send the pitch request.','error');
      }
    }
  }

  /* ══════════════════════════════════════════════════════════════
     4. ROYALTIES — balance, statements, withdrawal request
     ══════════════════════════════════════════════════════════════ */
  const ROV={data:null,loaded:false,busy:false,proof:null,sending:false};
  const WITHDRAW_METHODS=['Local Bank','Mobile Money','Cryptocurrency','Other'];

  function withdrawBadge(status){
    const s=String(status||'pending').toLowerCase();
    if(s==='paid') return ['Paid','status-done'];
    if(s==='denied') return ['Denied','ms-badge-bad'];
    if(s==='accepted') return ['Accepted','status-review'];
    if(s==='processing') return ['Processing','status-review'];
    return ['Pending','status-pending'];
  }

  async function loadRoyalties(force){
    const box=document.getElementById('ms-royalties');
    if(!box) return;
    if(ROV.busy) return;
    ROV.busy=true;
    if(force||!ROV.loaded) box.innerHTML='<p class="ms-muted">Loading your royalties…</p>';
    try{
      const r=await fetch('/api/royalties.php',{credentials:'same-origin',cache:'no-store'});
      const d=await r.json().catch(function(){ return {}; });
      if(!r.ok) throw new Error(d.error||'Could not load your royalties.');
      ROV.data=d;
      ROV.loaded=true;
      renderRoyalties();
    }catch(err){
      box.innerHTML='<div class="ms-empty"><strong>Royalties unavailable</strong><p>'+esc(err.message||'Try again in a moment.')+'</p></div>';
    }finally{
      ROV.busy=false;
    }
  }

  function renderRoyalties(){
    const box=document.getElementById('ms-royalties');
    if(!box) return;
    const d=ROV.data||{};
    const available=Number(d.available||0);
    const earnings=Number((d.report&&d.report.earnings)||0);
    const streams=Number((d.report&&d.report.streams)||0);
    const tracks=Number((d.report&&d.report.tracks)||0);
    const withdrawals=(d.withdrawals||[]).filter(function(w){ return !w.user_id||String(w.user_id)===String(d.user_id||w.user_id); });
    box.innerHTML=
      '<div class="ms-head">'+
        '<div><p class="ms-eyebrow">Royalties</p><h3 class="ms-title">Your earnings &amp; payouts</h3>'+
        '<p class="ms-sub">Reported by the stores, paid to your chosen account. Withdraw whenever you reach €0.01.</p></div>'+
        '<button type="button" class="btn-secondary" data-rov-refresh>Refresh</button>'+
      '</div>'+
      '<div class="ms-stat-grid">'+
        '<div class="ms-stat is-money"><b>Available balance</b><span>'+fmtMoney(available)+'</span><small>Ready to withdraw</small></div>'+
        '<div class="ms-stat"><b>Total reported earnings</b><span>'+fmtMoney(earnings)+'</span><small>'+tracks+' track'+(tracks===1?'':'s')+' · '+streams.toLocaleString()+' streams</small></div>'+
      '</div>'+
      '<div class="ms-card"><div class="ms-card-hd"><h4>Statements</h4><small>Full ledger of your royalty account</small></div>'+
        '<div class="ms-stat-docs">'+
          '<a class="ms-doc-card" style="display:flex;align-items:center;gap:12px;padding:14px 16px;border:1px solid rgba(255,255,255,.12);border-radius:14px;text-decoration:none;color:inherit;background:rgba(255,255,255,.03)" href="/api/document.php" target="_blank" rel="noopener"><span class="ms-doc-ico" style="font-size:22px;line-height:1">📄</span><span class="ms-doc-txt" style="display:flex;flex-direction:column;gap:2px;flex:1;min-width:0"><b style="font-size:.9rem">PDF statement</b><small style="color:#a1a1aa;font-size:.76rem">Full royalty ledger</small></span><span class="ms-doc-cta" style="font-weight:700;font-size:.8rem;color:#c0f06d;border:1px solid rgba(192,240,109,.4);border-radius:8px;padding:6px 10px;white-space:nowrap">Download</span></a>'+
          '<a class="ms-doc-card" style="display:flex;align-items:center;gap:12px;padding:14px 16px;border:1px solid rgba(255,255,255,.12);border-radius:14px;text-decoration:none;color:inherit;background:rgba(255,255,255,.03)" href="/api/document.php?format=csv" target="_blank" rel="noopener"><span class="ms-doc-ico" style="font-size:22px;line-height:1">📊</span><span class="ms-doc-txt" style="display:flex;flex-direction:column;gap:2px;flex:1;min-width:0"><b style="font-size:.9rem">CSV export</b><small style="color:#a1a1aa;font-size:.76rem">Spreadsheet for accounting</small></span><span class="ms-doc-cta" style="font-weight:700;font-size:.8rem;color:#c0f06d;border:1px solid rgba(192,240,109,.4);border-radius:8px;padding:6px 10px;white-space:nowrap">Download</span></a>'+
        '</div>'+
      '</div>'+
      '<div class="ms-card"><div class="ms-card-hd"><h4>Request royalty withdrawal</h4><small>Available: '+fmtMoney(available)+'</small></div>'+
        '<form id="ms-withdraw-form" novalidate>'+
          '<div class="ms-form-grid">'+
            '<label>Amount to withdraw (EUR)<input id="ms-wd-amount" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" required></label>'+
            '<label>Payment method<select id="ms-wd-method">'+WITHDRAW_METHODS.map(function(m){return '<option>'+esc(m)+'</option>';}).join('')+'</select></label>'+
            '<label>Full name<input id="ms-wd-name" type="text" maxlength="180" placeholder="Name on the account" required></label>'+
            '<label>Email<input id="ms-wd-email" type="email" maxlength="190" placeholder="you@email.com" required></label>'+
            '<label class="ms-span-2">Account / phone / wallet details<textarea id="ms-wd-details" maxlength="2000" placeholder="Bank name + account number, mobile money number, USDT wallet address…" required></textarea></label>'+
            '<div class="ms-span-2"><label style="display:block">Proof / screenshot (optional, recommended)</label>'+
              '<div class="upload-zone ms-drop" data-ms-proof="withdraw" tabindex="0" role="button">'+
                '<svg viewBox="0 0 24 24"><path d="M12 16V3m-5 5 5-5 5 5M4 15v5h16v-5"/></svg>'+
                '<p>Upload proof or screenshot</p><span>JPG, PNG or WebP · up to 100 MB</span>'+
                '<input type="file" hidden accept=".jpg,.jpeg,.png,.webp">'+
              '</div>'+
              '<div class="ms-proof-row" id="ms-wd-proof"></div>'+
            '</div>'+
          '</div>'+
          '<p class="ms-form-error" id="ms-wd-error" role="alert"></p>'+
          '<div class="ms-form-actions">'+
            '<button type="submit" class="btn-primary" id="ms-wd-submit">Request withdrawal</button>'+
            '<span class="ms-form-note" style="margin:0">Payouts are reviewed in support chat. Minimum €0.01.</span>'+
          '</div>'+
        '</form>'+
      '</div>'+
      '<div class="ms-card"><div class="ms-card-hd"><h4>Withdrawal history</h4><small>'+withdrawals.length+' request'+(withdrawals.length===1?'':'s')+'</small></div>'+
        (withdrawals.length?'<div class="ms-order-list">'+withdrawals.map(function(w){
          const badge=withdrawBadge(w.status);
          const when=fmtDate(w.created_at);
          return '<div class="ms-order-row">'+
            '<div class="ms-order-ico">€</div>'+
            '<div class="ms-order-main"><div class="ms-order-title">'+fmtMoney(w.amount)+' · '+esc(w.method||'—')+'</div>'+
              '<div class="ms-order-meta"><span class="status-badge '+badge[1]+'">'+esc(badge[0])+'</span>'+
              '<span>'+esc(w.beneficiary||'—')+'</span><span>'+esc(when)+'</span>'+(w.payment_reference?'<span>Ref: '+esc(w.payment_reference)+'</span>':'')+'</div></div>'+
            '<div class="ms-order-right">'+(String(w.status)==='paid'?'<a class="ms-inv-link" href="/api/document.php?withdraw='+encodeURIComponent(w.id)+'" target="_blank" rel="noopener">Receipt</a>':'')+'</div>'+
          '</div>';
        }).join('')+'</div>':'<p class="ms-muted">No withdrawal requests yet.</p>')+
      '</div>';
    const refresh=box.querySelector('[data-rov-refresh]');
    if(refresh) refresh.addEventListener('click',function(){ loadRoyalties(true); });
    const zone=box.querySelector('[data-ms-proof]');
    if(zone) bindProofZone(zone);
    const form=document.getElementById('ms-withdraw-form');
    if(form) form.addEventListener('submit',submitWithdrawal);
  }

  function renderProof(){
    const row=document.getElementById('ms-wd-proof');
    if(!row) return;
    if(!ROV.proof){ row.innerHTML=''; return; }
    const isImage=/^image\//.test(ROV.proof.mime||'');
    row.innerHTML=(isImage?'<img src="/api/music-media.php?id='+encodeURIComponent(ROV.proof.id)+'&inline" alt="Proof preview">':'')+
      '<span class="ms-muted">'+esc(ROV.proof.name)+'</span>'+
      '<button type="button" class="btn-secondary" data-proof-clear style="padding:6px 10px;font-size:.7rem">Remove</button>';
    const clear=row.querySelector('[data-proof-clear]');
    if(clear) clear.addEventListener('click',function(){ ROV.proof=null; renderProof(); });
  }

  function bindProofZone(zone){
    const input=zone.querySelector('input[type=file]');
    if(!input) return;
    zone.addEventListener('click',function(e){ if(e.target!==input) input.click(); });
    zone.addEventListener('keydown',function(e){ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); input.click(); } });
    input.addEventListener('change',async function(){
      const file=(input.files||[])[0];
      if(!file) return;
      zone.classList.add('is-busy');
      try{
        const rec=await uploadFile(file,'document');
        ROV.proof=rec;
        renderProof();
        toast('Proof uploaded.','success');
      }catch(err){
        toast(err.message||'Upload failed.','error');
      }finally{
        zone.classList.remove('is-busy');
        input.value='';
      }
    });
  }

  async function submitWithdrawal(ev){
    ev.preventDefault();
    if(ROV.sending) return;
    const errEl=document.getElementById('ms-wd-error');
    const btn=document.getElementById('ms-wd-submit');
    const amount=Number(document.getElementById('ms-wd-amount').value||0);
    const method=document.getElementById('ms-wd-method').value;
    const name=document.getElementById('ms-wd-name').value.trim();
    const email=document.getElementById('ms-wd-email').value.trim();
    const details=document.getElementById('ms-wd-details').value.trim();
    const fail=function(msg){ if(errEl) errEl.textContent=msg; toast(msg,'error'); };
    if(!(amount>=0.01)) return fail('Enter an amount of at least €0.01.');
    if(!name) return fail('Enter the full name on the account.');
    if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return fail('Enter a valid email address.');
    if(!details) return fail('Enter your account, phone or wallet details.');
    if(!method) return fail('Choose a payment method.');
    if(errEl) errEl.textContent='';
    ROV.sending=true;
    if(btn){ btn.disabled=true; btn.textContent='Submitting…'; }
    try{
      const payload={action:'withdraw',amount:Math.round(amount*100)/100,method:method,beneficiary:name,payout_details:details,email:email,request_id:randHex(24)};
      if(ROV.proof) payload.proof_media_id=ROV.proof.id;
      const d=await postJSON('/api/royalties.php',payload);
      toast('Withdrawal request #'+(d.id||'')+' submitted.','success');
      ROV.proof=null;
      await loadRoyalties(true);
    }catch(err){
      fail(err.message||'Could not submit the withdrawal.');
    }finally{
      ROV.sending=false;
      if(btn){ btn.disabled=false; btn.textContent='Request withdrawal'; }
    }
  }

  /* ══════════════════════════════════════════════════════════════
     5. ORDER HISTORY — real music orders
     ══════════════════════════════════════════════════════════════ */
  const ORD={list:[],loaded:false,busy:false};

  async function loadOrderHistory(force){
    const box=document.getElementById('ms-orders');
    if(!box) return;
    if(ORD.busy) return;
    ORD.busy=true;
    if(force||!ORD.loaded) box.innerHTML='<p class="ms-muted">Loading your orders…</p>';
    try{
      const r=await fetch('/api/orders.php',{credentials:'same-origin',cache:'no-store'});
      const d=await r.json().catch(function(){ return {}; });
      if(!r.ok) throw new Error(d.error||'Could not load your orders.');
      ORD.list=(d.orders||[]).filter(function(o){
        return o.page_slug==='music'||o.service_slug==='music';
      });
      ORD.loaded=true;
      renderOrderHistory();
    }catch(err){
      box.innerHTML='<div class="ms-empty"><strong>Orders unavailable</strong><p>'+esc(err.message||'Try again in a moment.')+'</p></div>';
    }finally{
      ORD.busy=false;
    }
  }

  function renderOrderHistory(){
    const box=document.getElementById('ms-orders');
    if(!box) return;
    if(!ORD.list.length){
      box.innerHTML='<div class="ms-empty"><strong>No music orders yet</strong>'+
        '<p>Orders appear here as soon as you submit a release.</p>'+
        '<button type="button" class="btn-primary" style="margin-top:14px" data-orders-start>Distribute a release</button></div>';
      const start=box.querySelector('[data-orders-start]');
      if(start) start.addEventListener('click',function(){
        const tabs=$$('#page-music .svc-tab');
        if(typeof window.switchTab==='function'&&tabs[0]) window.switchTab('music',0,tabs[0]);
      });
      return;
    }
    let total=0;
    box.innerHTML='<div class="ms-cat-top"><p class="ms-cat-title">Music orders <span>'+ORD.list.length+' order'+(ORD.list.length>1?'s':'')+'</span></p>'+
      '<button type="button" class="btn-secondary" data-orders-refresh>Refresh</button></div>'+
      '<div class="ms-order-list">'+ORD.list.map(function(o){
        const amount=Number(o.amount||0); total+=amount;
        return '<div class="ms-order-row">'+
          '<div class="ms-order-ico">🎵</div>'+
          '<div class="ms-order-main"><div class="ms-order-title">#'+esc(o.id)+' · '+esc(o.description||'Music order')+'</div>'+
            '<div class="ms-order-meta"><span class="status-badge '+statusClass(o.status)+'">'+esc(statusText(o.status))+'</span>'+
            '<span>'+esc(fmtDate(o.created_at))+'</span><span>'+esc(o.service||'Music & Distribution')+'</span></div></div>'+
          '<div class="ms-order-right"><span class="ms-order-amt">€'+amount.toFixed(2)+'</span>'+
            '<a class="ms-inv-link" href="/api/document.php?order='+encodeURIComponent(o.id)+'" target="_blank" rel="noopener">Invoice</a></div>'+
        '</div>';
      }).join('')+'</div>'+
      '<p class="ms-form-note">Total spent on music orders: '+fmtMoney(total)+'</p>';
    const refresh=box.querySelector('[data-orders-refresh]');
    if(refresh) refresh.addEventListener('click',function(){ loadOrderHistory(true); });
  }

  /* ── wire up ─────────────────────────────────────────────────── */
  function boot(){
    if(document.getElementById('music-studio')) wizard();
    if(document.getElementById('music-tab-1')) initProfiles();
    if(document.getElementById('ms-catalog')) loadCatalog(false);
    if(document.getElementById('ms-royalties')) loadRoyalties(false);
    if(document.getElementById('ms-orders')) loadOrderHistory(false);
    /* /services/music boots with data-service="music": make sure the panel is visible
       even if the SPA navigation ran before the DOM was in place. */
    const page=document.body.dataset.service;
    if(page==='music'){
      const panel=document.getElementById('page-music');
      if(panel&&!document.querySelector('.svc-page.active')&&typeof window.navTo==='function'){
        window.navTo('music');
      }
    }
  }
  window.msRefreshMusic=function(){ loadCatalog(true); loadRoyalties(true); loadOrderHistory(true); };
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',boot);
  else boot();
})();
