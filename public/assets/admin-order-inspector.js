/* TDC Tech — Universal Order Inspector (Phase 1)
 * Builds its own modal so no markup changes are needed. Uses globals exposed by
 * /admin/index.php: adminData, run(), makeInvoice(), toast(), money(), status().
 */
(function () {
  'use strict';

  var LABELS = {
    link: 'Target link', ad_link: 'Target ad link', url: 'URL', urls: 'URLs',
    quantity: 'Quantity', media_ids: 'Uploaded files', ad_name: 'Ad name',
    impressions: 'Total impressions', views: 'Total views',
    handles: 'Account handles', business: 'Business description',
    press_links: 'Press and news links', notes: 'Client notes', note: 'Client note',
    outlets: 'Target outlets', references: 'Reference links',
    tier: 'Tier', plan: 'Plan', deadline: 'Deadline', screenshot: 'Dashboard screenshot',
    creative_url: 'Ad creative (image link)', brief: 'Campaign brief', budget: 'Budget',
    audience: 'Target audience', duration_days: 'Duration (days)', days: 'Days',
    credentials: 'Account credentials', contact_email: 'Contact email',
    bot_name: 'Bot name', bot_type: 'Bot type', category: 'Category',
    release_kind: 'Release type', release_title: 'Release title', artist: 'Artist',
    genres: 'Genres', tracks: 'Tracks', headline: 'Headline', body: 'Draft copy',
    configuration: 'Configuration', api_token: 'API token (masked)', dm: 'Contact DM'
  };
  var IMG = /\.(png|jpe?g|gif|webp|avif|bmp|svg)(\?|#|$)/i;
  var URL_RE = /^https?:\/\//i;

  function e(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = String(text);
    return n;
  }

  function ensureModal() {
    var m = document.getElementById('order-inspector-modal');
    if (m) return m;
    m = e('div', 'modal');
    m.id = 'order-inspector-modal';
    m.setAttribute('aria-hidden', 'true');
    var card = e('div', 'modal-card');
    card.id = 'order-inspector-card';
    card.style.width = 'min(760px,100%)';
    m.appendChild(card);
    m.addEventListener('click', function (ev) { if (ev.target === m) window.closeOrderInspector(); });
    document.body.appendChild(m);
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') window.closeOrderInspector();
    });
    return m;
  }

  function linkNode(href) {
    var a = e('a', null, href);
    a.href = href;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    a.style.wordBreak = 'break-all';
    return a;
  }

  function valueNode(val) {
    var box = document.createDocumentFragment();
    if (Array.isArray(val)) {
      if (!val.length) { box.appendChild(e('span', 'muted', 'none')); return box; }
      var list = e('div');
      val.forEach(function (item) {
        var row = e('div');
        row.style.padding = '3px 0';
        if (typeof item === 'string' && URL_RE.test(item)) {
          row.appendChild(linkNode(item));
          if (IMG.test(item)) {
            var im = e('img');
            im.src = item;
            im.loading = 'lazy';
            im.alt = '';
            im.style.cssText = 'display:block;margin-top:6px;max-width:220px;max-height:150px;border-radius:8px;border:1px solid var(--border)';
            row.appendChild(im);
          }
        } else if (item && typeof item === 'object') {
          row.appendChild(e('code', 'muted', JSON.stringify(item)));
        } else {
          row.textContent = String(item);
        }
        list.appendChild(row);
      });
      box.appendChild(list);
      return box;
    }
    if (typeof val === 'string' && URL_RE.test(val)) {
      box.appendChild(linkNode(val));
      if (IMG.test(val)) {
        var img = e('img');
        img.src = val;
        img.loading = 'lazy';
        img.alt = '';
        img.style.cssText = 'display:block;margin-top:6px;max-width:260px;max-height:180px;border-radius:8px;border:1px solid var(--border)';
        box.appendChild(img);
      }
      return box;
    }
    if (val && typeof val === 'object') {
      box.appendChild(e('code', 'muted', JSON.stringify(val)));
      return box;
    }
    box.appendChild(e('span', null, (val === null || val === '') ? 'none' : String(val)));
    return box;
  }

  function parseDetails(raw) {
    if (!raw) return null;
    if (typeof raw === 'object') return raw;
    try {
      var d = JSON.parse(raw);
      if (Array.isArray(d) && !d.length) return null;
      return (d && typeof d === 'object') ? d : null;
    } catch (err) {
      return null;
    }
  }

  window.openOrderInspector = function (id) {
    var orders = ((typeof adminData !== 'undefined' && adminData.orders)) || [];
    var o = orders.filter(function (x) { return String(x.id) === String(id); })[0];
    var modal = ensureModal();
    var card = document.getElementById('order-inspector-card');
    card.replaceChildren();

    var top = e('div', 'modal-top');
    var head = e('div');
    head.appendChild(e('h3', null, o ? ('Order #' + o.id + ' — ' + (o.service || 'Service')) : ('Order #' + id)));
    head.appendChild(e('div', 'muted', o ? ((o.customer || 'Client') + ' · ' + (o.email || '')) : 'Order data unavailable'));
    top.appendChild(head);
    var closeBtn = e('button', 'btn small', 'Close');
    closeBtn.type = 'button';
    closeBtn.onclick = window.closeOrderInspector;
    top.appendChild(closeBtn);
    card.appendChild(top);

    if (!o) {
      card.appendChild(e('p', 'empty', 'This order is not in the current admin dataset. Reload the page and try again.'));
      modal.classList.add('open');
      modal.setAttribute('aria-hidden', 'false');
      return;
    }

    var sum = e('div');
    sum.style.cssText = 'display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:14px 0';
    var cells = [['Amount', money(o.amount)], ['Status', null], ['Submitted', String(o.created_at || 'n/a')]];
    cells.forEach(function (p, i) {
      var cell = e('div');
      cell.style.cssText = 'padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:rgba(255,255,255,.02)';
      cell.appendChild(e('div', 'kpi-lbl', p[0]));
      if (i === 1) {
        var cls = { processing: 'review', completed: 'done', pending: 'pending', rejected: 'rejected', cancelled: 'cancelled' }[o.status] || '';
        cell.appendChild(e('span', 'status' + (cls ? ' ' + cls : ''), o.status));
      } else {
        cell.appendChild(e('strong', null, p[1]));
      }
      sum.appendChild(cell);
    });
    card.appendChild(sum);

    if (o.description) {
      card.appendChild(e('div', 'section-label', 'Requested service'));
      var desc = e('div');
      desc.style.cssText = 'font-size:.74rem;line-height:1.6;color:var(--text)';
      desc.textContent = o.description;
      card.appendChild(desc);
    }

    card.appendChild(e('div', 'section-label', 'Client-submitted details'));
    var details = parseDetails(o.details);
    var wrap = e('div');
    wrap.style.cssText = 'border:1px solid var(--border);border-radius:10px;overflow:hidden';
    if (!details) {
      var none = e('p', 'muted');
      none.style.padding = '12px';
      none.textContent = 'No structured details were submitted with this order.';
      wrap.appendChild(none);
    } else {
      Object.keys(details).forEach(function (k, idx) {
        if (k === 'impressions' || k === 'views') return;
        var row = e('div');
        row.style.cssText = 'display:grid;grid-template-columns:180px 1fr;gap:10px;padding:9px 12px;font-size:.72rem;' +
          (idx ? 'border-top:1px solid rgba(255,255,255,.05)' : '');
        row.appendChild(e('span', 'muted', LABELS[k] || k.replace(/_/g, ' ')));
        row.appendChild(valueNode(details[k]));
        wrap.appendChild(row);
      });
    }
    card.appendChild(wrap);

    var media = o.media || [];
    card.appendChild(e('div', 'section-label', 'Attached files (' + media.length + ')'));
    var mbox = e('div');
    mbox.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px';
    if (!media.length) {
      mbox.appendChild(e('p', 'muted', 'No files were uploaded with this order.'));
    } else {
      media.forEach(function (m) {
        var box = e('div');
        box.style.cssText = 'border:1px solid var(--border);border-radius:10px;padding:10px;background:rgba(255,255,255,.02)';
        var url = '/api/music-media.php?id=' + encodeURIComponent(m.id);
        if (m.mime && m.mime.indexOf('image/') === 0) {
          var img = e('img');
          img.src = url + '&inline=1';
          img.loading = 'lazy';
          img.alt = m.original_name || 'attachment';
          img.style.cssText = 'width:100%;max-height:150px;object-fit:cover;border-radius:8px;border:1px solid var(--border);margin-bottom:8px';
          box.appendChild(img);
        }
        box.appendChild(e('div', null, m.original_name || ('file ' + m.id)));
        box.appendChild(e('div', 'muted', (m.kind || 'file') + (m.bytes ? ' · ' + (Number(m.bytes) / 1048576).toFixed(2) + ' MB' : '')));
        var dl = e('a', 'btn small', 'Download / preview');
        dl.href = url;
        dl.target = '_blank';
        dl.rel = 'noopener noreferrer';
        dl.style.marginTop = '7px';
        box.appendChild(dl);
        mbox.appendChild(box);
      });
    }
    card.appendChild(mbox);

    var isAds = (o.service_slug === 'social-ads') || (o.admin_page_slug === 'social-ads');
    if (isAds) {
      card.appendChild(e('div', 'section-label', 'Campaign performance (live stats)'));
      var grid = e('div', 'form-grid');
      [['impressions', 'Total impressions', details && details.impressions], ['views', 'Total views', details && details.views]]
        .forEach(function (f) {
          var fld = e('div', 'field');
          fld.appendChild(e('label', null, f[1]));
          var inp = e('input');
          inp.type = 'number';
          inp.min = '0';
          inp.step = '1';
          inp.id = 'insp-' + f[0];
          inp.value = f[2] == null ? '' : f[2];
          fld.appendChild(inp);
          grid.appendChild(fld);
        });
      card.appendChild(grid);
      var saveM = e('button', 'btn small primary', 'Save metrics');
      saveM.type = 'button';
      saveM.onclick = function () {
        run('order_metrics', {
          id: o.id,
          impressions: document.getElementById('insp-impressions').value,
          views: document.getElementById('insp-views').value
        }, 'Campaign metrics updated.');
      };
      var mrow = e('div', 'actions');
      mrow.style.marginTop = '9px';
      mrow.appendChild(saveM);
      card.appendChild(mrow);
    }

    card.appendChild(e('div', 'section-label', 'Admin note (emailed to the client when saved)'));
    var note = e('textarea');
    note.id = 'insp-note';
    note.rows = 3;
    note.maxLength = 4000;
    note.value = o.admin_note || '';
    card.appendChild(note);
    var saveN = e('button', 'btn small', 'Save note');
    saveN.type = 'button';
    saveN.onclick = function () {
      run('order_note', { id: o.id, note: document.getElementById('insp-note').value }, 'Order note updated.');
    };
    var nrow = e('div', 'actions');
    nrow.style.marginTop = '9px';
    nrow.appendChild(saveN);
    card.appendChild(nrow);

    card.appendChild(e('div', 'section-label', 'Actions'));
    var acts = e('div', 'actions');
    function act(label, cls, fn) {
      var b = e('button', 'btn small ' + cls, label);
      b.type = 'button';
      b.onclick = fn;
      acts.appendChild(b);
    }
    function transition(target, msg) {
      return function () {
        run('order_status', { id: o.id, status: target }, msg);
        window.closeOrderInspector();
      };
    }
    act('Approve · processing', 'primary', transition('processing', 'Order approved for processing.'));
    act('Mark completed', 'green', transition('completed', 'Order marked completed · invoice queued.'));
    act('Reject · refund', 'danger', transition('rejected', 'Order rejected and refunded.'));
    act('Cancel · refund', 'danger', transition('cancelled', 'Order cancelled and refunded.'));
    act('Invoice', '', function () {
      makeInvoice(String(o.id), o.customer || 'Client', o.service || 'Service', Number(o.amount || 0));
    });
    card.appendChild(acts);

    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
  };

  window.closeOrderInspector = function () {
    var m = document.getElementById('order-inspector-modal');
    if (m) {
      m.classList.remove('open');
      m.setAttribute('aria-hidden', 'true');
    }
  };
})();
