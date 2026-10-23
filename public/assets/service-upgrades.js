'use strict';
(function () {
  const PAGE = document.body.dataset.service;
  const PAGES = ['social-ads', 'verification', 'account-mgmt', 'press', 'bots'];
  if (!PAGES.includes(PAGE)) return;

  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (v) => '€' + Number(v || 0).toFixed(2);
  const num = (v) => Number(v || 0).toLocaleString('en-GB');
  const toast = (msg, type) => { if (typeof window.showToast === 'function') window.showToast(msg, type || 'success'); };
  const panel = () => document.getElementById('page-' + PAGE);
  const fmtDate = (v) => {
    if (!v) return '—';
    const d = new Date(String(v).replace(' ', 'T') + 'Z');
    return isNaN(d.getTime()) ? String(v).slice(0, 10) : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
  };
  const titleize = (v) => String(v || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

  let csrf = '';
  async function apiGet(url) {
    const r = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
    const d = await r.json().catch(() => ({}));
    if (r.status === 401) { toast('Session expired. Sign in again.', 'error'); throw new Error(d.error || 'Sign in required.'); }
    if (!r.ok) throw new Error(d.error || 'Request failed.');
    if (d.csrf) csrf = d.csrf;
    return d;
  }
  async function ensureCsrf() {
    if (csrf) return csrf;
    const me = await apiGet('/api/me.php');
    csrf = me.csrf || '';
    return csrf;
  }
  async function apiPost(url, payload) {
    await ensureCsrf();
    const r = await fetch(url, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(payload || {}),
    });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) {
      const e = new Error(d.error || 'Request failed.');
      e.status = r.status;
      throw e;
    }
    if (d.csrf) csrf = d.csrf;
    return d;
  }

  const STATUS = {
    'social-ads': { pending: ['Pending Review', 'pending'], processing: ['Running', 'processing'], completed: ['Completed', 'approved'], rejected: ['Rejected', 'rejected'], cancelled: ['Cancelled', 'rejected'] },
    verification: { pending: ['Pending', 'pending'], processing: ['Processing', 'processing'], completed: ['Approved', 'approved'], rejected: ['Rejected', 'rejected'], cancelled: ['Rejected', 'rejected'] },
    'account-mgmt': { pending: ['Pending', 'pending'], processing: ['In Progress', 'processing'], completed: ['Completed', 'approved'], rejected: ['Rejected / Refunded', 'rejected'], cancelled: ['Rejected / Refunded', 'rejected'] },
    press: { pending: ['Pending Review', 'pending'], processing: ['In Distribution', 'processing'], completed: ['Published', 'published'], rejected: ['Rejected', 'rejected'], cancelled: ['Rejected', 'rejected'] },
    bots: { pending: ['Deploying / Pending', 'deploying'], processing: ['Deploying / Pending', 'deploying'], completed: ['Active / Running', 'active'], rejected: ['Failed / Cancelled', 'failed'], cancelled: ['Failed / Cancelled', 'failed'] },
  };
  function badgeFor(page, status, refunded) {
    if (page === 'account-mgmt' && Number(refunded) > 0) return '<span class="up-badge up-badge-rejected">Rejected / Refunded</span>';
    const m = (STATUS[page] || {})[status] || [status || '—', 'neutral'];
    return `<span class="up-badge up-badge-${m[1]}">${esc(m[0])}</span>`;
  }
  function setMetrics(p, items) {
    const row = p && p.querySelector('.metric-row');
    if (!row) return;
    row.innerHTML = items.map((m) => `<div class="metric-card${m.hot ? ' highlight' : ''}"><p class="metric-lbl">${esc(m.label)}</p><p class="metric-val${m.grad ? ' grad' : ''}">${esc(m.value)}</p></div>`).join('');
  }
  function renderTabs(p, labels) {
    const tabs = p.querySelector('.svc-tabs');
    if (!tabs) return;
    tabs.innerHTML = labels.map((l, i) => `<button class="svc-tab${i === 0 ? ' active' : ''}" onclick="switchTab('${PAGE}',${i},this)">${esc(l)}</button>`).join('');
  }
  function renderContent(p, parts) {
    const scroll = p.querySelector('.svc-content-scroll');
    if (!scroll) return;
    scroll.innerHTML = parts.map((html, i) => `<div id="${PAGE}-tab-${i}" style="display:${i === 0 ? 'block' : 'none'}">${html}</div>`).join('');
  }
  function goTab(i) {
    const p = panel();
    const btn = p && p.querySelectorAll('.svc-tab')[i];
    if (btn && typeof window.switchTab === 'function') window.switchTab(PAGE, i, btn);
  }
  const emptyRow = (cols, text) => `<tr><td colspan="${cols}"><div class="up-empty">${esc(text)}</div></td></tr>`;
  function uploaderHint(svc) {
    return svc === 'account-dmca' ? 'PDF, JPG, PNG or MP4 evidence · up to 200 MB each' : 'JPG, PNG, WebP, MP4, WebM or PDF · up to 200 MB each';
  }

  function createUploader(host, hint) {
    const state = { files: [], busy: false };
    host.innerHTML = `<div class="up-upload" tabindex="0" role="button" aria-label="Upload attachments">
      <input type="file" multiple accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.mov,.pdf">
      <b>Drop files here or click to upload</b>
      <span>${esc(hint || 'JPG, PNG, MP4, PDF · up to 200 MB each')}</span>
      <em class="up-status" aria-live="polite"></em>
    </div><div class="up-files"></div>`;
    const zone = host.querySelector('.up-upload');
    const input = host.querySelector('input[type=file]');
    const status = host.querySelector('.up-status');
    const list = host.querySelector('.up-files');
    const draw = () => {
      list.innerHTML = state.files.map((f, i) => `<div class="up-file"><span>${esc(f.name)} · ${(f.bytes / 1048576).toFixed(1)} MB</span><button type="button" data-rm="${i}" aria-label="Remove attachment">×</button></div>`).join('');
      $$('button[data-rm]', list).forEach((b) => {
        b.onclick = () => { state.files.splice(Number(b.dataset.rm), 1); draw(); };
      });
    };
    const upload = async (files) => {
      if (state.busy) return;
      state.busy = true;
      zone.classList.add('busy');
      try {
        const arr = Array.from(files);
        for (let i = 0; i < arr.length; i++) {
          const file = arr[i];
          if (file.size > 200 * 1024 * 1024) throw new Error(file.name + ' is larger than 200 MB.');
          status.textContent = 'Uploading ' + (i + 1) + ' of ' + arr.length + '…';
          await ensureCsrf();
          const body = new FormData();
          body.append('file', file);
          const r = await fetch('/api/service-upload.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf }, body });
          const d = await r.json().catch(() => ({}));
          if (!r.ok) throw new Error(d.error || 'Upload failed.');
          state.files.push({ id: d.id, name: d.name || file.name, bytes: d.bytes || file.size });
        }
        status.textContent = 'Uploaded ' + state.files.length + ' file' + (state.files.length === 1 ? '' : 's') + '.';
        draw();
      } catch (e) {
        status.textContent = e.message;
        toast(e.message, 'error');
      } finally {
        state.busy = false;
        zone.classList.remove('busy');
        input.value = '';
      }
    };
    zone.onclick = () => { if (!state.busy) input.click(); };
    zone.onkeydown = (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (!state.busy) input.click(); } };
    input.onchange = () => { if (input.files && input.files.length) upload(input.files); };
    ['dragover', 'dragenter'].forEach((ev) => zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach((ev) => zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.remove('drag'); }));
    zone.addEventListener('drop', (e) => { if (e.dataTransfer && e.dataTransfer.files.length) upload(e.dataTransfer.files); });
    return {
      ids: () => state.files.map((f) => f.id),
      reset: () => { state.files = []; status.textContent = ''; draw(); },
      busy: () => state.busy,
    };
  }

  function modal(id, html) {
    let m = document.getElementById(id);
    if (!m) {
      m = document.createElement('div');
      m.id = id;
      m.className = 'up-modal';
      m.addEventListener('click', (e) => { if (e.target === m) m.classList.remove('open'); });
      document.body.appendChild(m);
    }
    m.innerHTML = html;
    m.classList.add('open');
    m.scrollTop = 0;
    return m;
  }
  window.closeUpModal = (id) => { const m = document.getElementById(id); if (m) m.classList.remove('open'); };

  /* ───────────────────────── Social Media Ads ───────────────────────── */
  const SA = { d: null, category: '', placements: new Set(), mode: 'duration', quote: null, uploader: null, timer: null, reqId: 0 };
  const SA_CATS = [
    ['music', '🎵', 'Music', 'Artist & release promotion'],
    ['website', '🌐', 'Website', 'Traffic and conversions'],
    ['mobile_app', '📱', 'Mobile App', 'Installs and retention'],
    ['business', '💼', 'Business', 'Local and service leads'],
    ['social_media', '💬', 'Social Media', 'Handles and content growth'],
    ['custom', '✨', 'Custom', 'Anything else'],
  ];
  function saCatFields(cat) {
    const f = (label, key, type, req, ph) => `<div class="up-field"><label>${label}${req ? ' *' : ''}</label><${type === 'textarea' ? 'textarea' : 'input'} data-sa-field="${key}" ${req ? 'data-req="1"' : ''} ${type === 'textarea' ? '' : `type="${type}"`} placeholder="${esc(ph || '')}"></${type === 'textarea' ? 'textarea' : 'input'}></div>`;
    switch (cat) {
      case 'music':
        return `<div class="up-row">${f('Artist name', 'artist_name', 'text', true, 'e.g. Nova Hayes')}</div>
          ${f('Spotify link', 'spotify_url', 'url', false, 'https://open.spotify.com/…')}
          ${f('Apple Music link', 'apple_music_url', 'url', false, 'https://music.apple.com/…')}
          ${f('YouTube link', 'youtube_url', 'url', false, 'https://youtube.com/…')}
          <p class="up-hint">Add at least one streaming or video link.</p>`;
      case 'website':
        return `${f('Site URL', 'site_url', 'url', true, 'https://yourdomain.com')}
          ${f('Page or product to promote', 'promote_page', 'text', true, 'e.g. /pricing or Spring collection')}`;
      case 'mobile_app':
        return `${f('App name', 'app_name', 'text', true, 'e.g. Pulse Fitness')}
          ${f('App Store link', 'app_store_url', 'url', false, 'https://apps.apple.com/…')}
          ${f('Google Play link', 'play_store_url', 'url', false, 'https://play.google.com/…')}
          <p class="up-hint">Add at least one store link.</p>`;
      case 'business':
        return `${f('Business name', 'business_name', 'text', true, 'e.g. Aurora Dental')}
          ${f('Location', 'location', 'text', true, 'City, country or service radius')}
          ${f('Service focus', 'service_focus', 'textarea', true, 'What should this campaign sell?')}`;
      case 'social_media':
        return `${f('Platform handles / links', 'handles', 'textarea', true, '@brand on Instagram, @brand on TikTok…')}
          ${f('Profile links (optional)', 'profile_links', 'textarea', false, 'One URL per line')}`;
      case 'custom':
        return f('Campaign brief', 'brief', 'textarea', true, 'Describe the product, audience and outcome you want.');
      default:
        return '<p class="up-hint">Select a business type to see its campaign fields.</p>';
    }
  }
  function saLocalQuote() {
    if (!SA.d || !SA.category || !SA.placements.size) return null;
    const rates = SA.d.rates.filter((r) => r.category === SA.category && SA.placements.has(r.placement));
    if (!rates.length) return null;
    const daily = rates.reduce((n, r) => n + Number(r.price_per_day), 0);
    const reachPerEur = rates.reduce((n, r) => n + Number(r.reach_per_eur), 0) / rates.length;
    let days = 0, total = 0;
    if (SA.mode === 'budget') {
      const budget = Number(($('#sa-budget') || {}).value || 0);
      if (budget < daily) return { daily, days: 0, total: 0, reach: 0, invalid: true };
      days = Math.floor(budget / daily);
      total = budget;
    } else {
      days = Number(($('#sa-duration') || {}).value || 0);
      if (days < 1) return { daily, days: 0, total: 0, reach: 0, invalid: true };
      total = daily * days;
    }
    return { daily, days, total, reach: Math.round(total * reachPerEur), invalid: false };
  }
  function saPaintSummary(q) {
    const totalEl = $('#sa-total'), daysEl = $('#sa-days'), reachEl = $('#sa-reach'), noteEl = $('#sa-note');
    const btn = $('#sa-launch');
    if (!totalEl) return;
    if (!q) {
      totalEl.textContent = '€0.00';
      daysEl.textContent = 'Select a business type and at least one platform.';
      reachEl.textContent = '';
      noteEl.textContent = 'Rates are set by the TDC team and confirmed on the server.';
      if (btn) { btn.textContent = 'Launch campaign'; btn.disabled = true; }
      return;
    }
    const total = Number(q.total != null ? q.total : q.amount);
    const days = Number(q.days != null ? q.days : q.duration_days);
    const daily = Number(q.daily != null ? q.daily : q.daily_rate);
    const reach = Number(q.reach != null ? q.reach : q.estimated_reach);
    totalEl.textContent = money(total);
    if (SA.mode === 'budget') {
      daysEl.textContent = days ? `${days} full day${days === 1 ? '' : 's'} at ${money(daily)}/day · budget ${money(total)}` : 'Budget is below one day at this rate.';
    } else {
      daysEl.textContent = days ? `${days} day${days === 1 ? '' : 's'} × ${money(daily)}/day` : 'Budget is below one day at this rate.';
    }
    reachEl.textContent = reach ? 'Estimated reach ' + num(reach) + ' impressions' : '';
    noteEl.textContent = SA.mode === 'budget' ? 'Budget mode · duration is derived from the daily rate.' : 'Duration mode · total is derived from the daily rate.';
    if (btn) {
      const ok = !q.invalid && total > 0;
      btn.textContent = ok ? 'Launch campaign — ' + money(total) : 'Launch campaign';
      btn.disabled = !ok;
    }
  }
  function saScheduleQuote() {
    const local = saLocalQuote();
    saPaintSummary(local);
    clearTimeout(SA.timer);
    const req = ++SA.reqId;
    if (!local || local.invalid) { SA.quote = null; return; }
    SA.timer = setTimeout(async () => {
      try {
        const d = await apiPost('/api/social-ads.php', {
          action: 'quote', category: SA.category, platforms: Array.from(SA.placements), mode: SA.mode,
          duration_days: Number(($('#sa-duration') || {}).value || 0), budget: Number(($('#sa-budget') || {}).value || 0),
        });
        if (req !== SA.reqId) return;
        SA.quote = d.quote;
        saPaintSummary(d.quote);
        const err = $('#sa-err'); if (err) err.textContent = '';
      } catch (e) {
        if (req !== SA.reqId) return;
        SA.quote = null;
        const err = $('#sa-err'); if (err) err.textContent = e.message;
      }
    }, 350);
  }
  function saRenderCats() {
    const box = $('#sa-cats');
    if (!box) return;
    box.innerHTML = SA_CATS.map(([id, ic, label, sub]) => `<button type="button" class="up-cat${SA.category === id ? ' sel' : ''}" data-cat="${id}"><span class="ic">${ic}</span><b>${label}</b><small>${sub}</small></button>`).join('');
    $$('[data-cat]', box).forEach((b) => { b.onclick = () => saPickCategory(b.dataset.cat); });
  }
  function saPickCategory(cat) {
    SA.category = cat;
    SA.placements.clear();
    saRenderCats();
    const card = $('#sa-cat-fields-card');
    const host = $('#sa-cat-fields');
    if (card) card.hidden = false;
    if (host) {
      host.innerHTML = saCatFields(cat);
      $$('[data-sa-field]', host).forEach((el) => el.addEventListener('input', saScheduleQuote));
    }
    saRenderPlatforms();
    saScheduleQuote();
  }
  function saRenderPlatforms() {
    const box = $('#sa-platforms');
    if (!box || !SA.d) return;
    if (!SA.category) { box.innerHTML = '<p class="up-hint">Select a business type above to see daily rates.</p>'; return; }
    const rates = {};
    SA.d.rates.forEach((r) => { if (r.category === SA.category) rates[r.placement] = r; });
    const entries = Object.entries(SA.d.placements).filter(([id]) => rates[id]);
    if (!entries.length) { box.innerHTML = '<p class="up-hint">No ad rates configured for this category yet.</p>'; return; }
    box.innerHTML = `<div class="up-checks" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr))">` + entries.map(([id, label]) => {
      const r = rates[id];
      const on = SA.placements.has(id);
      return `<label class="up-check${on ? ' sel' : ''}"><input type="checkbox" value="${id}" ${on ? 'checked' : ''}><span>${esc(label)}<small>${money(r.price_per_day)}/day</small></span></label>`;
    }).join('') + '</div>';
    $$('input[type=checkbox]', box).forEach((input) => {
      input.onchange = () => {
        if (input.checked) SA.placements.add(input.value); else SA.placements.delete(input.value);
        input.closest('.up-check').classList.toggle('sel', input.checked);
        saRenderModeFields();
        saScheduleQuote();
      };
    });
  }
  function saRenderModeFields() {
    const box = $('#sa-mode-fields');
    if (!box) return;
    if (SA.mode === 'budget') {
      box.innerHTML = `<div class="up-field"><label>Total budget (€)</label><input id="sa-budget" type="number" min="1" max="100000" step="1" placeholder="e.g. 300"></div><p class="up-hint">We calculate how many full days your budget buys at the selected daily rate.</p>`;
      const b = $('#sa-budget'); if (b) b.oninput = saScheduleQuote;
    } else {
      box.innerHTML = `<div class="up-field"><label>Campaign duration (days)</label><input id="sa-duration" type="number" min="1" max="90" step="1" value="14"></div><p class="up-hint">1–90 days. The total is the daily rate multiplied by the duration.</p>`;
      const d = $('#sa-duration'); if (d) d.oninput = saScheduleQuote;
    }
  }
  function saSetMode(mode) {
    SA.mode = mode;
    $$('.up-mode button').forEach((b) => b.classList.toggle('on', b.dataset.mode === mode));
    saRenderModeFields();
    saScheduleQuote();
  }
  async function saLaunch() {
    const btn = $('#sa-launch'), err = $('#sa-err');
    if (err) err.textContent = '';
    if (!SA.category) { toast('Choose a business type first.', 'error'); return; }
    if (!SA.placements.size) { toast('Select at least one platform.', 'error'); return; }
    const miss = $$('#sa-cat-fields [data-req]').find((el) => !String(el.value || '').trim());
    if (miss) { toast('Complete the highlighted category fields.', 'error'); miss.focus(); return; }
    if (SA.uploader && SA.uploader.busy()) { toast('Wait for attachments to finish uploading.', 'info'); return; }
    const category_fields = {};
    $$('#sa-cat-fields [data-sa-field]').forEach((el) => { category_fields[el.dataset.saField] = el.value.trim(); });
    const badUrl = $$('#sa-cat-fields input[type=url]').find((el) => el.value.trim() && !/^https?:\/\/\S+$/i.test(el.value.trim()));
    if (badUrl) { toast('Links must be full http(s) URLs.', 'error'); badUrl.focus(); return; }
    const campaign = {
      name: ($('#sa-campaign-name') || {}).value || '',
      objective: ($('#sa-objective') || {}).value || '',
      audience: ($('#sa-audience') || {}).value || '',
      creative_url: ($('#sa-creative-url') || {}).value || '',
    };
    if (!campaign.name.trim()) { toast('Enter a campaign name.', 'error'); return; }
    if (campaign.creative_url.trim() && !/^https?:\/\/\S+$/i.test(campaign.creative_url.trim())) { toast('Creative URL must be a full http(s) URL.', 'error'); return; }
    const payload = {
      category: SA.category, category_fields, campaign,
      platforms: Array.from(SA.placements), mode: SA.mode,
      duration_days: Number(($('#sa-duration') || {}).value || 0),
      budget: Number(($('#sa-budget') || {}).value || 0),
      media_ids: SA.uploader ? SA.uploader.ids() : [],
    };
    btn.disabled = true;
    try {
      const quote = await apiPost('/api/social-ads.php', { ...payload, action: 'quote' });
      const q = quote.quote;
      if (!confirm(`Launch "${campaign.name}" for ${money(q.amount)}?\n\n${q.duration_days} day(s) · ${num(q.estimated_reach)} estimated impressions.`)) return;
      const res = await apiPost('/api/social-ads.php', { ...payload, action: 'create' });
      toast('Campaign #' + res.order_id + ' launched. ' + money(res.amount) + ' debited from your wallet.', 'success');
      saReset();
      await saLoad();
      goTab(1);
    } catch (e) {
      if (err) err.textContent = e.message;
      toast(e.message, 'error');
      if (String(e.message).includes('Insufficient') && typeof window.openTopup === 'function') window.openTopup();
    } finally {
      btn.disabled = false;
    }
  }
  function saReset() {
    SA.category = ''; SA.placements.clear(); SA.mode = 'duration'; SA.quote = null;
    saRenderCats();
    const card = $('#sa-cat-fields-card'); if (card) card.hidden = true;
    const host = $('#sa-cat-fields'); if (host) host.innerHTML = '';
    const c = $('#sa-campaign-name'); if (c) c.value = '';
    const o = $('#sa-objective'); if (o) o.value = '';
    const a = $('#sa-audience'); if (a) a.value = '';
    const u = $('#sa-creative-url'); if (u) u.value = '';
    if (SA.uploader) SA.uploader.reset();
    saRenderPlatforms(); saSetMode('duration'); saPaintSummary(null);
  }
  function saOrderTitle(o) {
    const d = o.details || {};
    return (d.campaign && d.campaign.name) || o.description || ('Campaign #' + o.id);
  }
  function saOrderMeta(o) {
    const d = o.details || {};
    const platforms = (d.platforms || []).map((p) => (SA.d && SA.d.placements[p]) || titleize(p)).join(', ');
    return `${esc(d.category_label || titleize(d.category))} · ${esc(platforms || '—')} · ${d.duration_days || 0} days`;
  }
  function saRenderOrders() {
    const orders = (SA.d && SA.d.orders) || [];
    const active = orders.filter((o) => ['pending', 'processing'].includes(o.status));
    const activeBox = $('#sa-active');
    if (activeBox) {
      activeBox.innerHTML = active.length ? `<div class="up-list">` + active.map((o) => `<div class="up-list-item"><div class="top"><b>${esc(saOrderTitle(o))}</b>${badgeFor('social-ads', o.status)}</div><p>${saOrderMeta(o)}</p><p style="margin-top:6px">Order #${o.id} · ${fmtDate(o.created_at)} · ${money(o.amount)}</p></div>`).join('') + '</div>' : '<div class="up-empty">No active campaigns yet. Launch one from the New Campaign tab.</div>';
    }
    const tbody = $('#sa-history');
    if (tbody) {
      tbody.innerHTML = orders.length ? orders.map((o) => `<tr onclick="upSaBrief(${o.id})"><td class="up-nowrap"><strong>#${o.id}</strong></td><td><strong>${esc(saOrderTitle(o))}</strong><div style="font-size:.68rem;color:var(--muted)">${saOrderMeta(o)}</div></td><td class="up-nowrap">${fmtDate(o.created_at)}</td><td class="up-nowrap"><strong>${money(o.amount)}</strong></td><td>${badgeFor('social-ads', o.status, o.refunded)}</td><td><button type="button" class="up-link">View brief</button></td></tr>`).join('') : emptyRow(6, 'No campaigns yet.');
    }
  }
  window.upSaBrief = (id) => {
    const o = ((SA.d && SA.d.orders) || []).find((x) => Number(x.id) === Number(id));
    if (!o) return;
    const d = o.details || {};
    const cf = d.category_fields || {};
    const rows = Object.entries(cf).map(([k, v]) => `<dt>${esc(titleize(k))}</dt><dd>${Array.isArray(v) ? esc(v.join(', ')) : esc(v)}</dd>`).join('');
    const campaignRows = Object.entries(d.campaign || {}).map(([k, v]) => v ? `<dt>${esc(titleize(k))}</dt><dd>${esc(v)}</dd>` : '').join('');
    modal('up-sa-brief', `<div class="up-modal-card"><div class="up-modal-hd"><div><h3>Campaign #${o.id}</h3><p>${esc(o.description)}</p></div><button class="up-x" type="button" onclick="closeUpModal('up-sa-brief')">✕</button></div>
      <div style="margin-bottom:12px">${badgeFor('social-ads', o.status, o.refunded)} <span class="up-badge up-badge-neutral">${money(o.amount)}</span></div>
      <dl class="up-detail">${campaignRows}${rows}<dt>Platforms</dt><dd>${esc((d.platforms || []).map((p) => (SA.d && SA.d.placements[p]) || p).join(', '))}</dd><dt>Daily rate</dt><dd>${money(d.daily_rate)}</dd><dt>Duration</dt><dd>${esc(d.duration_days)} days</dd><dt>Estimated reach</dt><dd>${num(d.estimated_reach)}</dd></dl>
      ${o.files && o.files.length ? `<p class="up-hint" style="margin-top:14px">Attachments</p><div class="up-files">${o.files.map((f) => `<a class="up-file" href="/api/music-media.php?id=${encodeURIComponent(f.id)}" target="_blank" rel="noopener"><span>${esc(f.original_name)}</span></a>`).join('')}</div>` : ''}
      </div>`);
  };
  async function saLoad() {
    const d = await apiGet('/api/social-ads.php');
    SA.d = d;
    setMetrics(panel(), [
      { label: 'Total spend', value: money(d.stats.spend), hot: true, grad: true },
      { label: 'Campaigns', value: String(d.stats.count) },
      { label: 'Active', value: String(d.stats.active) },
    ]);
    saRenderCats();
    saRenderPlatforms();
    saRenderOrders();
    const bal = $('#sa-balance'); if (bal) bal.textContent = money(d.balance);
    return d;
  }
  async function initSocialAds(p) {
    renderTabs(p, ['New Campaign', 'Active Campaigns', 'Order History']);
    renderContent(p, [
      `<div class="up-grid"><div class="up-stack">
        <div class="up-card"><h3>1. Business type / category</h3><p class="up-sub">The category decides which brief fields and rate card apply.</p><div class="up-cats" id="sa-cats"></div></div>
        <div class="up-card" id="sa-cat-fields-card" hidden><h3>2. Category details</h3><p class="up-sub">Tell us what the campaign is promoting.</p><div id="sa-cat-fields"></div></div>
        <div class="up-card"><h3>3. Campaign brief</h3><div class="up-row"><div class="up-field"><label>Campaign name *</label><input id="sa-campaign-name" type="text" maxlength="160" placeholder="e.g. Summer Promo 2026"></div><div class="up-field"><label>Objective</label><select id="sa-objective"><option>Brand Awareness</option><option>Traffic</option><option>Conversions</option><option>Music Streams</option><option>App Installs</option><option>Lead Generation</option></select></div></div>
          <div class="up-field"><label>Target audience</label><textarea id="sa-audience" placeholder="Age range, interests, location…"></textarea></div>
          <div class="up-field"><label>Ad creative URL (optional)</label><input id="sa-creative-url" type="url" placeholder="Canva, Drive or asset link"></div></div>
        <div class="up-card"><h3>4. Platforms &amp; budget</h3><p class="up-sub">Rates are per day and set by the TDC team.</p><div id="sa-platforms"></div>
          <div class="up-mode"><button type="button" data-mode="duration" class="on">I know my duration</button><button type="button" data-mode="budget">I know my budget</button></div>
          <div id="sa-mode-fields"></div></div>
        <div class="up-card"><h3>5. Ad assets</h3><p class="up-sub">Images, video and PDF up to 200 MB each. Validation runs on the server too.</p><div id="sa-upload"></div></div>
      </div><aside class="up-stack">
        <div class="up-summary"><p class="lbl">ESTIMATED SPEND</p><p class="amount" id="sa-total">€0.00</p><p id="sa-days">Select a business type and at least one platform.</p><p id="sa-reach"></p><p id="sa-note">Rates are set by the TDC team and confirmed on the server.</p><p class="err" id="sa-err"></p></div>
        <div class="up-card"><div class="up-wallet"><span>Wallet balance</span><b id="sa-balance">€0.00</b></div><button type="button" class="up-btn up-btn-primary" id="sa-launch" style="width:100%" disabled>Launch campaign</button><p class="up-hint">Launching validates the brief, re-quotes on the server, debits your wallet and creates the order in one transaction.</p></div>
      </aside></div>`,
      `<div class="up-card"><div class="up-card-hd"><h3>Active campaigns</h3><span class="up-badge up-badge-processing" id="sa-active-count">0</span></div><div id="sa-active"></div></div>`,
      `<div class="up-card"><div class="up-card-hd"><h3>Order history</h3><span class="up-hint" style="margin:0">Click a row to view the brief</span></div><div class="up-table-wrap"><table class="up-table"><thead><tr><th>Order</th><th>Campaign</th><th>Date</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody id="sa-history"></tbody></table></div></div>`,
    ]);
    SA.uploader = createUploader($('#sa-upload'));
    $$('.up-mode button').forEach((b) => { b.onclick = () => saSetMode(b.dataset.mode); });
    $('#sa-launch').onclick = saLaunch;
    saPaintSummary(null);
    await saLoad();
    const ac = $('#sa-active-count'); if (ac) ac.textContent = String(((SA.d && SA.d.orders) || []).filter((o) => ['pending', 'processing'].includes(o.status)).length);
  }

  /* ───────────────────────── Verification ───────────────────────── */
  const VF = { d: null, platform: '', uploader: null };
  const VF_PLATFORMS = ['Meta Verified', 'TikTok', 'Twitter / X', 'Spotify OAC'];
  const VF_CATS = { musician: 'Musician / Artist', business: 'Business', public_figure: 'Public Figure', creator: 'Content Creator', other: 'Other' };
  async function vfLoad() {
    const d = await apiGet('/api/verification.php');
    VF.d = d;
    setMetrics(panel(), [
      { label: 'Total spend', value: money(d.stats.spend), hot: true, grad: true },
      { label: 'Verifications submitted', value: String(d.stats.count) },
      { label: 'In progress', value: String(d.stats.active) },
    ]);
    const fee = $('#vf-fee'); if (fee) fee.textContent = money(d.price);
    const w = $('#vf-wallet'); if (w) w.textContent = money(d.balance);
    vfRenderTracker();
    return d;
  }
  function vfCountLinks() {
    const t = ($('#vf-links') || {}).value || '';
    const urls = t.split(/[\r\n,]+/).map((s) => s.trim()).filter(Boolean);
    const counter = $('#vf-link-count');
    if (counter) counter.textContent = `${urls.length} URL${urls.length === 1 ? '' : 's'} · minimum 3`;
    if (counter) counter.style.color = urls.length >= 3 ? '#4ade80' : 'var(--muted)';
  }
  function vfRenderTracker() {
    const o = (VF.d && VF.d.orders) || [];
    const box = $('#vf-tracker');
    if (!box) return;
    const sum = `<div class="up-metrics"><div class="up-metric hot"><p class="lbl">Total verifications submitted</p><p class="val">${o.length}</p></div><div class="up-metric"><p class="lbl">Total spend on verifications</p><p class="val">${money((VF.d && VF.d.stats.spend) || 0)}</p></div></div>`;
    const table = o.length ? `<div class="up-table-wrap"><table class="up-table"><thead><tr><th>Order</th><th>Profile</th><th>Category</th><th>Date</th><th>Amount</th><th>Status</th></tr></thead><tbody>${o.map((x) => {
      const d = x.details || {};
      return `<tr onclick="upVfDetail(${x.id})"><td class="up-nowrap"><strong>#${x.id}</strong></td><td><strong>${esc(d.handle || x.description)}</strong><div style="font-size:.68rem;color:var(--muted)">${esc(d.platform || '')}</div></td><td>${esc(d.category_label || '—')}</td><td class="up-nowrap">${fmtDate(x.created_at)}</td><td class="up-nowrap"><strong>${money(x.amount)}</strong></td><td>${badgeFor('verification', x.status, x.refunded)}</td></tr>`;
    }).join('')}</tbody></table></div>` : '<div class="up-empty">No verification requests yet. Submit one from the Apply tab.</div>';
    box.innerHTML = sum + `<div class="up-card"><div class="up-card-hd"><h3>Status tracker</h3><span class="up-hint" style="margin:0">Live status from your orders</span></div>${table}</div>`;
  }
  window.upVfDetail = (id) => {
    const o = ((VF.d && VF.d.orders) || []).find((x) => Number(x.id) === Number(id));
    if (!o) return;
    const d = o.details || {};
    modal('up-vf-detail', `<div class="up-modal-card"><div class="up-modal-hd"><div><h3>Verification #${o.id}</h3><p>${esc(d.handle || '')}</p></div><button class="up-x" type="button" onclick="closeUpModal('up-vf-detail')">✕</button></div>
      <div style="margin-bottom:12px">${badgeFor('verification', o.status, o.refunded)} <span class="up-badge up-badge-neutral">${money(o.amount)}</span></div>
      <dl class="up-detail"><dt>Platform</dt><dd>${esc(d.platform)}</dd><dt>Profile URL</dt><dd><a href="${esc(d.profile_url)}" target="_blank" rel="noopener">${esc(d.profile_url)}</a></dd><dt>Category</dt><dd>${esc(d.category_label)}</dd><dt>Identity / background</dt><dd>${esc(d.description)}</dd><dt>Press links</dt><dd>${(d.press_links || []).map((u) => `<a href="${esc(u)}" target="_blank" rel="noopener">${esc(u)}</a>`).join('<br>')}</dd></dl>
      ${o.files && o.files.length ? `<p class="up-hint">Documents</p><div class="up-files">${o.files.map((f) => `<a class="up-file" href="/api/music-media.php?id=${encodeURIComponent(f.id)}" target="_blank" rel="noopener"><span>${esc(f.original_name)}</span></a>`).join('')}</div>` : ''}
      </div>`);
  };
  async function vfSubmit() {
    const err = $('#vf-err'); if (err) err.textContent = '';
    const platform = (VF.platform || '');
    const handle = (($('#vf-handle') || {}).value || '').trim();
    const url = (($('#vf-url') || {}).value || '').trim();
    const category = ($('#vf-category') || {}).value || '';
    const description = (($('#vf-desc') || {}).value || '').trim();
    const links = (($('#vf-links') || {}).value || '').trim();
    const count = links.split(/[\r\n,]+/).map((s) => s.trim()).filter(Boolean).length;
    if (!platform) return toast('Choose a verification platform.', 'error');
    if (!handle) return toast('Enter the profile handle or name.', 'error');
    if (!/^https?:\/\//i.test(url)) return toast('Enter the direct profile URL (http/https).', 'error');
    if (!category) return toast('Choose the account category.', 'error');
    if (description.length < 50) return toast('Describe the account identity and background in at least 50 characters.', 'error');
    if (count < 3) return toast('Add at least 3 press or news article URLs.', 'error');
    if (VF.uploader && VF.uploader.busy()) return toast('Wait for uploads to finish.', 'info');
    const btn = $('#vf-submit'); btn.disabled = true;
    try {
      const res = await apiPost('/api/verification.php', { platform, handle, profile_url: url, category, description, press_links: links, media_ids: VF.uploader ? VF.uploader.ids() : [] });
      toast('Verification #' + res.order_id + ' submitted. ' + money(res.amount) + ' debited.', 'success');
      $('#vf-handle').value = ''; $('#vf-url').value = ''; $('#vf-links').value = ''; $('#vf-desc').value = '';
      $('#vf-category').value = 'musician';
      VF.platform = ''; $$('.up-chip', $('#vf-platforms')).forEach((c) => c.classList.remove('sel'));
      VF.uploader.reset(); vfCountLinks();
      await vfLoad();
      goTab(1);
    } catch (e) {
      if (err) err.textContent = e.message;
      toast(e.message, 'error');
      if (String(e.message).includes('Insufficient') && typeof window.openTopup === 'function') window.openTopup();
    } finally { btn.disabled = false; }
  }
  async function initVerification(p) {
    renderTabs(p, ['Apply', 'Status Tracker']);
    renderContent(p, [
      `<div class="up-grid"><div class="up-stack">
        <div class="up-card"><h3>Verification platform</h3><p class="up-sub">Choose the platform where the account should be verified.</p><div class="up-chips" id="vf-platforms"></div></div>
        <div class="up-card"><h3>Profile details</h3>
          <div class="up-row"><div class="up-field"><label>Profile handle / name *</label><input id="vf-handle" type="text" maxlength="120" placeholder="@yourhandle"></div><div class="up-field"><label>Direct profile URL *</label><input id="vf-url" type="url" placeholder="https://…"></div></div>
          <div class="up-field"><label>Category *</label><select id="vf-category"></select></div>
          <div class="up-field"><label>Identity / background description *</label><textarea id="vf-desc" placeholder="Who is the account, what is their public profile, notable work, and why should it be verified? (50+ characters)"></textarea><span class="up-hint">Mandatory. This is used for the platform submission and press packaging.</span></div>
          <div class="up-field"><label>Press / news article links *</label><textarea id="vf-links" placeholder="One URL per line — recommend at least 3 articles"></textarea><span class="up-hint" id="vf-link-count">0 URLs · minimum 3</span></div>
          <div class="up-field"><label>Supporting documents</label><div id="vf-upload"></div></div>
        </div>
      </div><aside class="up-stack"><div class="up-summary"><p class="lbl">SERVICE FEE</p><p class="amount" id="vf-fee">€0.00</p><p>Verification review, identity packaging and platform submission.</p><p class="err" id="vf-err"></p></div>
        <div class="up-card"><div class="up-wallet"><span>Wallet balance</span><b id="vf-wallet">€0.00</b></div><button type="button" class="up-btn up-btn-primary" id="vf-submit" style="width:100%">Submit application</button></div>
      </aside></div>`,
      `<div id="vf-tracker"></div>`,
    ]);
    const pf = $('#vf-platforms');
    pf.innerHTML = (VF.d ? VF.d.platforms : VF_PLATFORMS).map((x) => `<button type="button" class="up-chip" data-pf="${esc(x)}">${esc(x)}</button>`).join('');
    $$('[data-pf]', pf).forEach((b) => { b.onclick = () => { VF.platform = b.dataset.pf; $$('.up-chip', pf).forEach((x) => x.classList.toggle('sel', x === b)); }; });
    const cat = $('#vf-category');
    const cats = (VF.d && VF.d.categories) || VF_CATS;
    cat.innerHTML = Object.entries(cats).map(([k, v]) => `<option value="${esc(k)}">${esc(v)}</option>`).join('');
    $('#vf-links').addEventListener('input', vfCountLinks);
    VF.uploader = createUploader($('#vf-upload'), 'Passport, PR deck or official documents · up to 200 MB each');
    $('#vf-submit').onclick = vfSubmit;
    await vfLoad();
    vfCountLinks();
  }

  /* ───────────────────────── Account Management ───────────────────────── */
  const ACC = { d: null, modalService: null, step: 1, uploader: null, orders: [] };
  const ACC_META = {
    'account-social': ['📱', 'Full Social Management', 'End-to-end content, publishing and community management.'],
    'account-publicist': ['⭐', 'VIP Publicist', 'PR strategy, media outreach and launch support.'],
    'account-youtube': ['▶️', 'YouTube Monetisation', 'Monetise an existing channel or order a new one.'],
    'account-meta': ['💼', 'Meta BM Setup', 'Business Manager registration and admin transfer.'],
    'account-dmca': ['🚫', 'DMCA Takedown', 'Copyright takedowns across platforms.'],
  };
  function accFields(slug) {
    const input = (label, key, type, req, ph, opts) => {
      if (type === 'select') return `<div class="up-field"><label>${label}${req ? ' *' : ''}</label><select data-acc="${key}" ${req ? 'data-req="1"' : ''}>${opts}</select></div>`;
      if (type === 'textarea') return `<div class="up-field"><label>${label}${req ? ' *' : ''}</label><textarea data-acc="${key}" ${req ? 'data-req="1"' : ''} placeholder="${esc(ph || '')}"></textarea></div>`;
      return `<div class="up-field"><label>${label}${req ? ' *' : ''}</label><input type="${type}" data-acc="${key}" ${req ? 'data-req="1"' : ''} placeholder="${esc(ph || '')}"></div>`;
    };
    const checks = (label, key, items) => `<div class="up-field"><label>${label} *</label><div class="up-checks" data-acc-group="${key}">${items.map((x) => `<label class="up-check"><input type="checkbox" value="${esc(x)}"><span>${esc(x)}</span></label>`).join('')}</div></div>`;
    switch (slug) {
      case 'account-social':
        return `<div class="up-row">${input('Company / brand', 'company_brand', 'text', true, 'Brand or company name')}${input('Contact email', 'contact_email', 'email', true, 'you@company.com')}</div>
          ${input('Business description', 'business_description', 'textarea', true, 'What the business does and who it serves')}
          <div class="up-row">${input('Contact phone', 'contact_phone', 'tel', true, '+44…')}${input('Hours per week', 'hours', 'select', true, '', '<option value="10">10 hours</option><option value="20" selected>20 hours</option><option value="40">40 hours</option>')}</div>
          ${checks('Platforms to manage', 'platforms', ['Instagram', 'Facebook', 'X / Twitter', 'TikTok', 'LinkedIn', 'YouTube'])}
          ${input('Handles / URLs', 'handles', 'textarea', true, 'One per line, e.g. @brand on Instagram')}
          <div class="up-row">${input('Retainer duration', 'duration', 'select', true, '', '<option value="1">1 month</option><option value="3">3 months</option><option value="6">6 months</option>')}</div>
          ${input('Client notes', 'client_notes', 'textarea', false, 'Tone of voice, posting preferences, approvals…')}`;
      case 'account-dmca':
        return `${input('Infringing URLs', 'infringing_urls', 'textarea', true, 'One URL per line')}
          ${input('Copyright / ownership proof', 'ownership_proof', 'textarea', true, 'Registration numbers, original upload dates, contracts…')}
          ${input('Target platforms', 'target_platforms', 'text', true, 'e.g. Instagram, TikTok, Google')}
          <div class="up-row">${input('Contact name', 'contact_name', 'text', true, 'Full legal name')}${input('Contact email', 'contact_email', 'email', true, 'you@company.com')}</div>
          ${input('Violation details', 'violation_details', 'textarea', true, 'How the content infringes and where it appears')}
          ${input('Authorization reference (optional)', 'authorization_document', 'text', false, 'Rights-holder authorisation reference')}
          <div class="up-field"><label>Authorization document upload</label><div id="acc-upload"></div></div>`;
      case 'account-youtube':
        return `<div class="up-field"><label>Monetisation route *</label><div class="up-chips" id="acc-yt-mode"><button type="button" class="up-chip sel" data-yt="existing">Monetise existing channel</button><button type="button" class="up-chip" data-yt="new">Order a new channel</button></div></div>
          ${input('Channel URL', 'channel_url', 'url', true, 'https://youtube.com/@channel')}
          ${input('Channel niche', 'niche', 'text', true, 'e.g. music, fitness, finance')}
          ${input('Target metrics', 'target_metrics', 'textarea', true, 'Subscribers, watch hours, upload cadence…')}
          ${input('Contact email', 'contact_email', 'email', true, 'you@company.com')}
          ${input('Dashboard screenshots', 'screenshots_note', 'textarea', false, 'Describe what is in the attached screenshots (optional)')}
          <div class="up-field"><label>Dashboard screenshots / assets</label><div id="acc-upload"></div></div>
          ${input('Notes', 'notes', 'textarea', false, 'Anything else we should know')}`;
      case 'account-publicist':
        return `<div class="up-row">${input('Brand / artist', 'brand_artist', 'text', true, 'Name')}${input('Launch schedule', 'launch_schedule', 'date', true, '')}</div>
          ${input('Media goals', 'media_goals', 'textarea', true, 'What coverage do you want and why now?')}
          ${input('Preferred PR outlets', 'preferred_outlets', 'textarea', true, 'One outlet per line')}
          ${input('Press kit link', 'press_kit_url', 'url', false, 'https://…')}
          ${input('Notes', 'notes', 'textarea', false, 'Additional context')}
          <div class="up-field"><label>Assets upload</label><div id="acc-upload"></div></div>`;
      case 'account-meta':
        return `${input('Facebook page link', 'page_link', 'url', true, 'https://facebook.com/…')}
          ${input('Business Manager name', 'bm_name', 'text', true, 'Legal BM name')}
          ${input('Registration / tax ID', 'registration_id', 'text', true, 'Company registration or VAT/tax ID')}
          <div class="up-row">${input('Admin email for transfer', 'admin_email', 'email', true, 'admin@company.com')}${input('Contact phone', 'contact_phone', 'tel', true, '+44…')}</div>
          ${input('Special instructions', 'instructions', 'textarea', false, 'Pixels, ad accounts, domains…')}`;
      default:
        return '';
    }
  }
  function accTarget(o) {
    const f = (o.details && o.details.fields) || {};
    return f.company_brand || f.channel_url || f.brand_artist || f.bm_name || (Array.isArray(f.infringing_urls) ? f.infringing_urls[0] : '') || o.description || '—';
  }
  function accRenderHistory() {
    const orders = ACC.orders || [];
    const tbody = $('#acc-history');
    if (!tbody) return;
    tbody.innerHTML = orders.length ? orders.map((o) => `<tr onclick="upAccDetail(${o.id})"><td class="up-nowrap"><strong>#${o.id}</strong></td><td>${esc(o.service_name)}</td><td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(accTarget(o))}</td><td class="up-nowrap">${fmtDate(o.created_at)}</td><td class="up-nowrap"><strong>${money(o.amount)}</strong></td><td>${badgeFor('account-mgmt', o.status, o.refunded)}</td></tr>`).join('') : emptyRow(6, 'No management orders yet.');
  }
  function accRenderModal() {
    const svc = ACC.modalService;
    if (!svc) return;
    const meta = ACC_META[svc.slug] || ['💼', svc.name, ''];
    if (ACC.step === 1) {
      modal('up-acc-modal', `<div class="up-modal-card"><div class="up-modal-hd"><div><h3>${meta[0]} ${esc(meta[1])}</h3><p>${esc(meta[2])}</p></div><button class="up-x" type="button" onclick="closeUpModal('up-acc-modal')">✕</button></div>
        <div class="up-steps"><span class="up-step on"></span><span class="up-step"></span></div>
        <div class="up-summary"><p class="lbl">SERVICE FEE</p><p class="amount">${money(svc.price)}</p><p>Charged immediately from your wallet when you submit the intake.</p></div>
        <div class="up-actions"><button type="button" class="up-btn" onclick="closeUpModal('up-acc-modal')">Cancel</button><button type="button" class="up-btn up-btn-primary" id="acc-continue">Continue to details</button></div></div>`);
      $('#acc-continue').onclick = () => { ACC.step = 2; accRenderModal(); };
      return;
    }
    modal('up-acc-modal', `<div class="up-modal-card up-wide"><div class="up-modal-hd"><div><h3>${meta[0]} ${esc(meta[1])}</h3><p>Step 2 of 2 · ${money(svc.price)} will be debited from your wallet.</p></div><button class="up-x" type="button" onclick="closeUpModal('up-acc-modal')">✕</button></div>
      <div class="up-steps"><span class="up-step on"></span><span class="up-step on"></span></div>
      <div id="acc-fields">${accFields(svc.slug)}</div>
      <div class="up-wallet" style="margin-top:14px"><span>Wallet balance</span><b id="acc-balance">${money((ACC.d && ACC.d.balance) || 0)}</b></div>
      <p class="up-err" id="acc-err"></p>
      <div class="up-actions"><button type="button" class="up-btn" id="acc-back">Back</button><button type="button" class="up-btn up-btn-primary" id="acc-submit">Submit management order · ${money(svc.price)}</button></div></div>`);
    ACC.uploader = null;
    const up = $('#acc-upload');
    if (up) ACC.uploader = createUploader(up, uploaderHint(svc.slug));
    const modes = $$('#acc-yt-mode [data-yt]');
    modes.forEach((b) => { b.onclick = () => { ACC.ytMode = b.dataset.yt; modes.forEach((x) => x.classList.toggle('sel', x === b)); }; });
    $('#acc-back').onclick = () => { ACC.step = 1; accRenderModal(); };
    $('#acc-submit').onclick = accSubmit;
  }
  function accOpen(slug) {
    const svc = ((ACC.d && ACC.d.services) || []).find((s) => s.slug === slug);
    if (!svc) return toast('Service unavailable. Refresh the page.', 'error');
    ACC.modalService = svc; ACC.step = 1; ACC.ytMode = 'existing';
    accRenderModal();
  }
  window.upAccOpen = accOpen;
  async function accSubmit() {
    const svc = ACC.modalService;
    if (!svc) return;
    const err = $('#acc-err'); if (err) err.textContent = '';
    const missing = $$('#acc-fields [data-req]').find((el) => !String(el.value || '').trim());
    if (missing) { toast('Complete every required field.', 'error'); missing.focus(); return; }
    const fields = {};
    $$('#acc-fields [data-acc]').forEach((el) => { fields[el.dataset.acc] = el.value.trim(); });
    $$('#acc-fields [data-acc-group]').forEach((group) => {
      fields[group.dataset.accGroup] = $$('input[type=checkbox]:checked', group).map((c) => c.value);
    });
    if (svc.slug === 'account-youtube') fields.mode = ACC.ytMode || 'existing';
    if (ACC.uploader && ACC.uploader.busy()) return toast('Wait for uploads to finish.', 'info');
    const btn = $('#acc-submit'); btn.disabled = true;
    try {
      const res = await apiPost('/api/account-mgmt.php', { service_slug: svc.slug, fields, media_ids: ACC.uploader ? ACC.uploader.ids() : [] });
      toast('Order #' + res.order_id + ' submitted. ' + money(res.amount) + ' debited.', 'success');
      ACC.step = 1;
      modal('up-acc-modal', `<div class="up-modal-card"><div class="up-modal-hd"><div><h3>Order confirmed</h3><p>${esc(svc.name)}</p></div><button class="up-x" type="button" onclick="closeUpModal('up-acc-modal')">✕</button></div>
        <div class="up-summary"><p class="lbl">ORDER #${res.order_id}</p><p class="amount">${money(res.amount)}</p><p>New wallet balance: ${money(res.balance_after)}</p><p>Your order is now pending and visible in Order History.</p></div>
        <div class="up-actions"><button type="button" class="up-btn up-btn-primary" onclick="closeUpModal('up-acc-modal');upAccDone()">View order history</button></div></div>`);
      ACC.orders = [];
      await accLoad();
    } catch (e) {
      if (err) err.textContent = e.message;
      toast(e.message, 'error');
      if (e.status === 402 && typeof window.openTopup === 'function') window.openTopup();
    } finally { btn.disabled = false; }
  }
  window.upAccDone = () => goTab(1);
  window.upAccDetail = (id) => {
    const o = (ACC.orders || []).find((x) => Number(x.id) === Number(id));
    if (!o) return;
    const d = o.details || {};
    const f = d.fields || {};
    const rows = Object.entries(f).map(([k, v]) => {
      if (k === 'api_token') return '';
      const val = Array.isArray(v) ? v.join(', ') : v;
      const isUrl = /^https?:\/\//.test(String(val));
      return `<dt>${esc(titleize(k))}</dt><dd>${isUrl ? `<a href="${esc(val)}" target="_blank" rel="noopener">${esc(val)}</a>` : esc(val)}</dd>`;
    }).join('');
    modal('up-acc-detail', `<div class="up-modal-card up-wide"><div class="up-modal-hd"><div><h3>Order #${o.id} · ${esc(o.service_name)}</h3><p>${fmtDate(o.created_at)} · ${money(o.amount)}</p></div><button class="up-x" type="button" onclick="closeUpModal('up-acc-detail')">✕</button></div>
      <div style="margin-bottom:12px">${badgeFor('account-mgmt', o.status, o.refunded)}</div>
      <dl class="up-detail">${rows}</dl>
      ${o.files && o.files.length ? `<p class="up-hint">Uploaded files</p><div class="up-files">${o.files.map((x) => `<a class="up-file" href="/api/music-media.php?id=${encodeURIComponent(x.id)}" target="_blank" rel="noopener"><span>${esc(x.original_name)}</span></a>`).join('')}</div>` : ''}
      <div class="up-actions"><a class="up-btn" href="/api/document.php?order=${o.id}">Download invoice</a></div></div>`);
  };
  async function accLoad() {
    const d = await apiGet('/api/account-mgmt.php');
    ACC.d = d; ACC.orders = d.orders || [];
    setMetrics(panel(), [
      { label: 'Total spend', value: money(d.stats.spend), hot: true, grad: true },
      { label: 'Management orders', value: String(d.stats.count) },
      { label: 'In progress', value: String(d.stats.active) },
    ]);
    const grid = $('#acc-grid');
    if (grid) {
      grid.innerHTML = d.services.map((s) => {
        const meta = ACC_META[s.slug] || ['💼', s.name, ''];
        return `<button type="button" class="up-cat" data-acc-svc="${esc(s.slug)}"><span class="ic">${meta[0]}</span><b>${esc(meta[1])}</b><small>${esc(meta[2])}</small><span style="display:block;margin-top:8px;color:#c0f06d;font-weight:800;font-size:.78rem">${money(s.price)}</span></button>`;
      }).join('');
      $$('[data-acc-svc]', grid).forEach((b) => { b.onclick = () => accOpen(b.dataset.accSvc); });
    }
    const bal = $('#acc-wallet'); if (bal) bal.textContent = money(d.balance);
    accRenderHistory();
    return d;
  }
  async function initAccount(p) {
    renderTabs(p, ['Apply', 'Order History']);
    renderContent(p, [
      `<div class="up-card"><div class="up-card-hd"><h3>Select a management service</h3><div class="up-wallet" style="margin:0"><span>Wallet</span><b id="acc-wallet">€0.00</b></div></div><p class="up-sub">Each service opens a two-step intake. The fee is deducted instantly when you submit.</p><div class="up-cats" id="acc-grid"></div></div>`,
      `<div class="up-card"><h3>Order history</h3><p class="up-sub">Click any row for the full intake and uploaded files.</p><div class="up-table-wrap"><table class="up-table"><thead><tr><th>Order ID</th><th>Service type</th><th>Target account / link</th><th>Date</th><th>Amount deducted</th><th>Status</th></tr></thead><tbody id="acc-history"></tbody></table></div></div>`,
    ]);
    await accLoad();
  }

  /* ───────────────────────── Press Releases ───────────────────────── */
  const PR = { d: null, tier: 'press-tier1', wikiTier: 'wiki-tier1', uploader: null, wikiUploader: null };
  function prServices(prefix) { return ((PR.d && PR.d.services) || []).filter((s) => s.slug.startsWith(prefix)); }
  function prTierCards(prefix, selected, handler) {
    return `<div class="up-tiers">` + prServices(prefix).map((s) => {
      const tier = s.slug.endsWith('tier2') ? 'TIER 2' : 'TIER 1';
      const desc = prefix === 'press-'
        ? (tier === 'TIER 1' ? 'Regional outlets, music blogs and Google News indexing.' : 'Yahoo Finance, Bloomberg, Benzinga, AP-syndicated + 200 outlets.')
        : (tier === 'TIER 1' ? 'Notability assessment, source audit and sandbox draft.' : 'Full non-promotional draft, citations and live publishing support.');
      return `<button type="button" class="up-tier${selected === s.slug ? ' sel' : ''}" data-tier="${esc(s.slug)}" onclick="${handler}('${esc(s.slug)}',this)"><div class="tag">${tier}</div><h4>${esc(s.name.replace(/^.*·\s*/, ''))}</h4><p>${desc}</p><b>${money(s.price)}</b></button>`;
    }).join('') + '</div>';
  }
  window.upPrPick = (slug, el) => {
    PR.tier = slug;
    $$('[data-tier]', $('#pr-tier-box')).forEach((b) => b.classList.toggle('sel', b === el));
    const svc = prServices('press-').find((s) => s.slug === slug);
    const fee = $('#pr-fee'); if (svc && fee) fee.textContent = money(svc.price);
  };
  window.upWikiPick = (slug, el) => {
    PR.wikiTier = slug;
    $$('[data-tier]', $('#wiki-tier-box')).forEach((b) => b.classList.toggle('sel', b === el));
    const svc = prServices('wiki-').find((s) => s.slug === slug);
    const fee = $('#wiki-fee'); if (svc && fee) fee.textContent = money(svc.price);
  };
  function prRenderHistory() {
    const orders = (PR.d && PR.d.orders) || [];
    const tbody = $('#pr-history');
    if (!tbody) return;
    const sum = `<div class="up-metrics"><div class="up-metric"><p class="lbl">Total orders</p><p class="val">${orders.length}</p></div><div class="up-metric hot"><p class="lbl">Total money spent</p><p class="val">${money((PR.d && PR.d.stats.spend) || 0)}</p></div></div>`;
    const table = orders.length ? `<div class="up-table-wrap"><table class="up-table"><thead><tr><th>Order ID</th><th>Tier</th><th>Headline</th><th>Date</th><th>Amount paid</th><th>Status</th><th>Actions</th></tr></thead><tbody>${orders.map((o) => {
      const d = o.details || {};
      return `<tr onclick="upPrBrief(${o.id})"><td class="up-nowrap"><strong>#${o.id}</strong></td><td class="up-nowrap">${esc(d.wiki ? 'Wikipedia · Tier ' + (d.tier || '1') : 'Tier ' + (d.tier || '1'))}</td><td><strong>${esc((d.fields && (d.fields.headline || d.fields.subject)) || o.description)}</strong></td><td class="up-nowrap">${fmtDate(o.created_at)}</td><td class="up-nowrap"><strong>${money(o.amount)}</strong></td><td>${badgeFor('press', o.status, o.refunded)}</td><td class="up-nowrap"><a class="up-link" href="/api/document.php?order=${o.id}" onclick="event.stopPropagation()">Invoice PDF</a><button type="button" class="up-link" onclick="event.stopPropagation();upPrBrief(${o.id})">View brief</button></td></tr>`;
    }).join('')}</tbody></table></div>` : '<div class="up-empty">No press releases yet.</div>';
    tbody.innerHTML = sum + table;
  }
  window.upPrBrief = (id) => {
    const o = ((PR.d && PR.d.orders) || []).find((x) => Number(x.id) === Number(id));
    if (!o) return;
    const d = o.details || {}, f = d.fields || {};
    const body = f.body || f.brief || '';
    modal('up-pr-brief', `<div class="up-modal-card up-wide"><div class="up-modal-hd"><div><h3>${esc(f.headline || f.subject || 'Press brief')}</h3><p>Order #${o.id} · ${fmtDate(o.created_at)}</p></div><button class="up-x" type="button" onclick="closeUpModal('up-pr-brief')">✕</button></div>
      <div style="margin-bottom:12px">${badgeFor('press', o.status, o.refunded)} <span class="up-badge up-badge-neutral">${money(o.amount)}</span></div>
      <dl class="up-detail"><dt>Tier</dt><dd>${esc(d.wiki ? 'Wikipedia · Tier ' + (d.tier || '1') : 'Tier ' + (d.tier || '1'))}</dd>
      <dt>Headline / subject</dt><dd>${esc(f.headline || f.subject || '—')}</dd>
      ${f.contact_email ? `<dt>Contact</dt><dd>${esc(f.contact_email)}</dd>` : ''}
      ${f.target_outlets ? `<dt>Target outlets</dt><dd>${esc(f.target_outlets)}</dd>` : ''}
      ${f.press_links && f.press_links.length ? `<dt>Links</dt><dd>${f.press_links.map((u) => `<a href="${esc(u)}" target="_blank" rel="noopener">${esc(u)}</a>`).join('<br>')}</dd>` : ''}
      <dt>Brief</dt><dd style="white-space:pre-wrap">${esc(body)}</dd></dl>
      ${o.files && o.files.length ? `<p class="up-hint">Attachments</p><div class="up-files">${o.files.map((x) => `<a class="up-file" href="/api/music-media.php?id=${encodeURIComponent(x.id)}" target="_blank" rel="noopener"><span>${esc(x.original_name)}</span></a>`).join('')}</div>` : ''}
      <div class="up-actions"><a class="up-btn" href="/api/document.php?order=${o.id}">Download invoice PDF</a></div></div>`);
  };
  async function prSubmit(kind) {
    const isWiki = kind === 'wiki';
    const slug = isWiki ? PR.wikiTier : PR.tier;
    const err = $(isWiki ? '#wiki-err' : '#pr-err'); if (err) err.textContent = '';
    if (PR.uploader && PR.uploader.busy()) return toast('Wait for uploads to finish.', 'info');
    if (PR.wikiUploader && PR.wikiUploader.busy()) return toast('Wait for uploads to finish.', 'info');
    const fields = isWiki
      ? { subject: (($('#wiki-subject') || {}).value || '').trim(), language: ($('#wiki-language') || {}).value || 'English (en)', press_links: (($('#wiki-links') || {}).value || '').trim(), brief: (($('#wiki-brief') || {}).value || '').trim() }
      : { headline: (($('#pr-headline') || {}).value || '').trim(), body: (($('#pr-body') || {}).value || '').trim(), target_outlets: (($('#pr-outlets') || {}).value || '').trim(), contact_email: (($('#pr-email') || {}).value || '').trim() };
    if (isWiki && !fields.subject) return toast('Enter the Wikipedia subject name.', 'error');
    if (!isWiki && !fields.headline) return toast('Enter the press release headline.', 'error');
    if (!isWiki && fields.body.length < 40) return toast('Add the article draft or a brief of at least 40 characters.', 'error');
    const btn = $(isWiki ? '#wiki-submit' : '#pr-submit'); btn.disabled = true;
    try {
      const res = await apiPost('/api/press.php', { service_slug: slug, fields, media_ids: isWiki ? (PR.wikiUploader ? PR.wikiUploader.ids() : []) : (PR.uploader ? PR.uploader.ids() : []) });
      toast('Press release #' + res.order_id + ' submitted. ' + money(res.amount) + ' debited.', 'success');
      if (isWiki) {
        $('#wiki-subject').value = ''; $('#wiki-links').value = ''; $('#wiki-brief').value = '';
        PR.wikiUploader.reset();
      } else {
        $('#pr-headline').value = ''; $('#pr-body').value = ''; $('#pr-outlets').value = ''; $('#pr-email').value = '';
        PR.uploader.reset();
      }
      await prLoad();
      goTab(2);
    } catch (e) {
      if (err) err.textContent = e.message;
      toast(e.message, 'error');
      if (String(e.message).includes('Insufficient') && typeof window.openTopup === 'function') window.openTopup();
    } finally { btn.disabled = false; }
  }
  async function prLoad() {
    const d = await apiGet('/api/press.php');
    PR.d = d;
    setMetrics(panel(), [
      { label: 'Total spend', value: money(d.stats.spend), hot: true, grad: true },
      { label: 'Press orders', value: String(d.stats.count) },
      { label: 'In progress', value: String(d.stats.active) },
    ]);
    const tb = $('#pr-tier-box');
    if (tb) tb.innerHTML = prTierCards('press-', PR.tier, 'upPrPick');
    const wb = $('#wiki-tier-box');
    if (wb) wb.innerHTML = prTierCards('wiki-', PR.wikiTier, 'upWikiPick');
    const p1 = prServices('press-tier1')[0], p2 = prServices('press-tier2')[0];
    if (p1) PR.tier = 'press-tier1';
    if (p2) PR.tier = PR.tier || 'press-tier1';
    const fee = $('#pr-fee'); if (fee && p1) fee.textContent = money(p1.price);
    const wfee = $('#wiki-fee'); if (wfee && prServices('wiki-tier1')[0]) wfee.textContent = money(prServices('wiki-tier1')[0].price);
    const bal = $('#pr-wallet'); if (bal) bal.textContent = money(d.balance);
    prRenderHistory();
    return d;
  }
  async function initPress(p) {
    renderTabs(p, ['Press Release', 'Wikipedia Service', 'Order History']);
    renderContent(p, [
      `<div class="up-grid"><div class="up-stack">
        <div class="up-card"><h3>Select tier</h3><p class="up-sub">Tier 1 is €85.00 · Tier 2 is €250.00. The server re-checks the tier price.</p><div id="pr-tier-box"></div></div>
        <div class="up-card"><h3>Article details</h3>
          <div class="up-field"><label>Article title *</label><input id="pr-headline" type="text" maxlength="200" placeholder="Headline for your press release"></div>
          <div class="up-field"><label>Article draft or brief *</label><textarea id="pr-body" style="min-height:130px" placeholder="Paste your draft or brief our copywriters…"></textarea></div>
          <div class="up-field"><label>Target outlets (optional)</label><input id="pr-outlets" type="text" placeholder="e.g. Yahoo Finance, Benzinga"></div>
          <div class="up-field"><label>Contact email (optional)</label><input id="pr-email" type="email" placeholder="press@company.com"></div>
          <div class="up-field"><label>Existing draft (optional)</label><div id="pr-upload"></div></div>
        </div>
      </div><aside class="up-stack"><div class="up-summary"><p class="lbl">SELECTED TIER</p><p class="amount" id="pr-fee">€85.00</p><p>Distribution, Google News indexing and order tracking.</p><p class="err" id="pr-err"></p></div>
        <div class="up-card"><div class="up-wallet"><span>Wallet balance</span><b id="pr-wallet">€0.00</b></div><button type="button" class="up-btn up-btn-primary" id="pr-submit" style="width:100%">Submit press release</button></div></aside></div>`,
      `<div class="up-grid"><div class="up-stack">
        <div class="up-card"><h3>Wikipedia service tier</h3><p class="up-sub">Factual, non-promotional drafting only. Publication depends on Wikipedia notability.</p><div id="wiki-tier-box"></div></div>
        <div class="up-card"><h3>Subject details</h3>
          <div class="up-field"><label>Subject / entity name *</label><input id="wiki-subject" type="text" placeholder="Person, band, company or brand"></div>
          <div class="up-field"><label>Wikipedia language</label><select id="wiki-language"><option>English (en)</option><option>French (fr)</option><option>Spanish (es)</option><option>German (de)</option></select></div>
          <div class="up-field"><label>Existing press links</label><textarea id="wiki-links" placeholder="One URL per line"></textarea></div>
          <div class="up-field"><label>Brief</label><textarea id="wiki-brief" placeholder="What should the article cover?"></textarea></div>
          <div class="up-field"><label>Supporting document</label><div id="wiki-upload"></div></div>
        </div>
      </div><aside class="up-stack"><div class="up-summary"><p class="lbl">SELECTED TIER</p><p class="amount" id="wiki-fee">€150.00</p><p>Assessment, sourcing and publishing support.</p><p class="err" id="wiki-err"></p></div>
        <div class="up-card"><button type="button" class="up-btn up-btn-primary" id="wiki-submit" style="width:100%">Submit Wikipedia request</button></div></aside></div>`,
      `<div class="up-card"><h3>Order history</h3><p class="up-sub">Invoices are generated server-side as real PDFs.</p><div id="pr-history"></div></div>`,
    ]);
    PR.uploader = createUploader($('#pr-upload'), 'DOCX, PDF or supporting files · up to 200 MB each');
    PR.wikiUploader = createUploader($('#wiki-upload'), 'PDF, DOCX or TXT · up to 200 MB each');
    $('#pr-submit').onclick = () => prSubmit('press');
    $('#wiki-submit').onclick = () => prSubmit('wiki');
    await prLoad();
  }

  /* ───────────────────────── Custom Bots ───────────────────────── */
  const BT = { d: null, type: 'telegram', uploader: null };
  const BT_META = {
    telegram: ['📨', 'Telegram Bot', 'Mass messaging, group management and command auto-replies.'],
    scraper: ['🕷️', 'Web Scraper', 'Extract data from sites, social profiles and directories.'],
    dm: ['💬', 'DM Auto-responder', 'Instagram and TikTok DM sequences with lead capture.'],
  };
  function btRenderHistory() {
    const orders = (BT.d && BT.d.orders) || [];
    const box = $('#bt-history');
    if (!box) return;
    const sum = `<div class="up-metrics"><div class="up-metric"><p class="lbl">Total custom bots deployed</p><p class="val">${orders.length}</p></div><div class="up-metric hot"><p class="lbl">Total spend on custom bots</p><p class="val">${money((BT.d && BT.d.stats.spend) || 0)}</p></div></div>`;
    const table = orders.length ? `<div class="up-table-wrap"><table class="up-table"><thead><tr><th>Order / bot ID</th><th>Bot type &amp; name</th><th>Deployment date</th><th>Plan fee</th><th>Status</th><th>Actions</th></tr></thead><tbody>${orders.map((o) => {
      const d = o.details || {}, f = d.fields || {};
      return `<tr onclick="upBtConfig(${o.id})"><td class="up-nowrap"><strong>#${o.id}</strong><div style="font-size:.66rem;color:var(--muted)">BOT-${String(o.id).padStart(5, '0')}</div></td><td><strong>${esc(f.bot_name || o.description)}</strong><div style="font-size:.68rem;color:var(--muted)">${esc(d.bot_type_label || titleize(d.bot_type))}</div></td><td class="up-nowrap">${fmtDate(o.created_at)}</td><td class="up-nowrap"><strong>${money(o.amount)}</strong></td><td>${badgeFor('bots', o.status, o.refunded)}</td><td class="up-nowrap"><a class="up-link" href="/api/document.php?order=${o.id}" onclick="event.stopPropagation()">Invoice PDF</a><button type="button" class="up-link" onclick="event.stopPropagation();upBtConfig(${o.id})">View config</button></td></tr>`;
    }).join('')}</tbody></table></div>` : '<div class="up-empty">No custom bots deployed yet.</div>';
    box.innerHTML = sum + `<div class="up-card"><h3 style="margin-bottom:12px">Bot deployments</h3>${table}</div>`;
  }
  window.upBtConfig = (id) => {
    const o = ((BT.d && BT.d.orders) || []).find((x) => Number(x.id) === Number(id));
    if (!o) return;
    const d = o.details || {}, f = d.fields || {};
    modal('up-bt-config', `<div class="up-modal-card up-wide"><div class="up-modal-hd"><div><h3>${esc(f.bot_name || 'Bot')} · #${o.id}</h3><p>${esc(d.bot_type_label || titleize(d.bot_type))} · ${fmtDate(o.created_at)}</p></div><button class="up-x" type="button" onclick="closeUpModal('up-bt-config')">✕</button></div>
      <div style="margin-bottom:12px">${badgeFor('bots', o.status, o.refunded)} <span class="up-badge up-badge-neutral">${money(o.amount)}</span></div>
      <dl class="up-detail"><dt>Bot type</dt><dd>${esc(d.bot_type_label || titleize(d.bot_type))}</dd><dt>Bot name</dt><dd>${esc(f.bot_name || '—')}</dd><dt>Target</dt><dd>${esc(f.target || '—')}</dd><dt>Schedule</dt><dd>${esc(f.schedule || '—')}</dd><dt>API token</dt><dd>${f.api_token_set ? 'Provided (stored securely)' : 'Not provided'}</dd><dt>Configuration</dt><dd style="white-space:pre-wrap">${esc(f.configuration || '—')}</dd></dl>
      ${o.files && o.files.length ? `<p class="up-hint">Attachments</p><div class="up-files">${o.files.map((x) => `<a class="up-file" href="/api/music-media.php?id=${encodeURIComponent(x.id)}" target="_blank" rel="noopener"><span>${esc(x.original_name)}</span></a>`).join('')}</div>` : ''}
      <div class="up-actions"><a class="up-btn" href="/api/document.php?order=${o.id}">Download invoice PDF</a></div></div>`);
  };
  async function btSubmit() {
    const err = $('#bt-err'); if (err) err.textContent = '';
    if (!BT.type) return toast('Choose a bot type.', 'error');
    const name = (($('#bt-name') || {}).value || '').trim();
    const config = (($('#bt-config') || {}).value || '').trim();
    if (!name) return toast('Enter a bot name.', 'error');
    if (config.length < 20) return toast('Describe what the bot should do in at least 20 characters.', 'error');
    if (BT.uploader && BT.uploader.busy()) return toast('Wait for uploads to finish.', 'info');
    const fields = {
      bot_name: name, api_token: (($('#bt-token') || {}).value || '').trim(),
      target: (($('#bt-target') || {}).value || '').trim(), schedule: (($('#bt-schedule') || {}).value || '').trim(),
      configuration: config,
    };
    const btn = $('#bt-submit'); btn.disabled = true;
    try {
      const res = await apiPost('/api/bots.php', { bot_type: BT.type, fields, media_ids: BT.uploader ? BT.uploader.ids() : [] });
      toast('Bot #' + res.order_id + ' queued. ' + money(res.amount) + ' debited.', 'success');
      $('#bt-name').value = ''; $('#bt-token').value = ''; $('#bt-target').value = ''; $('#bt-schedule').value = ''; $('#bt-config').value = '';
      BT.uploader.reset();
      await btLoad();
      goTab(1);
    } catch (e) {
      if (err) err.textContent = e.message;
      toast(e.message, 'error');
      if (String(e.message).includes('Insufficient') && typeof window.openTopup === 'function') window.openTopup();
    } finally { btn.disabled = false; }
  }
  async function btLoad() {
    const d = await apiGet('/api/bots.php');
    BT.d = d;
    setMetrics(panel(), [
      { label: 'Total spend', value: money(d.stats.spend), hot: true, grad: true },
      { label: 'Bots deployed', value: String(d.stats.count) },
      { label: 'Active / deploying', value: String(d.stats.active) },
    ]);
    const price = $('#bt-price'); if (price) price.textContent = money(d.price);
    const bal = $('#bt-wallet'); if (bal) bal.textContent = money(d.balance);
    btRenderHistory();
    return d;
  }
  async function initBots(p) {
    renderTabs(p, ['Deploy Bot', 'Order History']);
    renderContent(p, [
      `<div class="up-grid"><div class="up-stack">
        <div class="up-card"><h3>Select bot type</h3><div class="up-cats" id="bt-types"></div></div>
        <div class="up-card"><h3>Configuration</h3>
          <div class="up-row"><div class="up-field"><label>Bot name / label *</label><input id="bt-name" type="text" maxlength="120" placeholder="e.g. Lead Gen Bot v1"></div><div class="up-field"><label>Target (optional)</label><input id="bt-target" type="text" placeholder="@channel, website or handle"></div></div>
          <div class="up-field"><label>Telegram Bot API token (if applicable)</label><input id="bt-token" type="text" autocomplete="off" placeholder="Paste your BotFather token"></div>
          <div class="up-field"><label>Schedule (optional)</label><input id="bt-schedule" type="text" placeholder="e.g. Every day at 09:00 UTC"></div>
          <div class="up-field"><label>Deployment notes *</label><textarea id="bt-config" placeholder="Describe triggers, responses and behaviour…"></textarea></div>
          <div class="up-field"><label>Supporting files (optional)</label><div id="bt-upload"></div></div>
        </div>
      </div><aside class="up-stack"><div class="up-summary"><p class="lbl">MONTHLY PLAN</p><p class="amount" id="bt-price">€30.00</p><p>Managed cloud instance, deployment and monitoring.</p><p class="err" id="bt-err"></p></div>
        <div class="up-card"><div class="up-wallet"><span>Wallet balance</span><b id="bt-wallet">€0.00</b></div><button type="button" class="up-btn up-btn-primary" id="bt-submit" style="width:100%">Deploy bot</button></div></aside></div>`,
      `<div id="bt-history"></div>`,
    ]);
    const types = $('#bt-types');
    types.innerHTML = Object.entries(BT_META).map(([id, m]) => `<button type="button" class="up-cat${id === BT.type ? ' sel' : ''}" data-bt="${id}"><span class="ic">${m[0]}</span><b>${esc(m[1])}</b><small>${esc(m[2])}</small></button>`).join('');
    $$('[data-bt]', types).forEach((b) => { b.onclick = () => { BT.type = b.dataset.bt; $$('[data-bt]', types).forEach((x) => x.classList.toggle('sel', x === b)); }; });
    BT.uploader = createUploader($('#bt-upload'));
    $('#bt-submit').onclick = btSubmit;
    await btLoad();
  }

  /* ───────────────────────── Bootstrap ───────────────────────── */
  document.addEventListener('DOMContentLoaded', async () => {
    const p = panel();
    if (!p) return;
    try {
      if (PAGE === 'social-ads') await initSocialAds(p);
      else if (PAGE === 'verification') await initVerification(p);
      else if (PAGE === 'account-mgmt') await initAccount(p);
      else if (PAGE === 'press') await initPress(p);
      else if (PAGE === 'bots') await initBots(p);
    } catch (e) {
      toast(e.message || 'Unable to load this service page.', 'error');
    }
  });
})();
