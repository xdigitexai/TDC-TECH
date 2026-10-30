/* TDC Tech — Phases 3 & 4
 *  Phase 3: Transactions ledger tab (counters, unified table, status override, refund)
 *  Phase 4: Catalog controls — ad rate cards (collapsible) + inline price/active toggles
 * Relies on globals from /admin/index.php: adminData, run(), toast(), esc(), money(), status(), post()
 */
(function () {
  'use strict';

  function e(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = String(text);
    return n;
  }

  function badge(state) {
    var map = { posted: 'done', pending: 'pending', reversed: 'rejected' };
    var cls = map[state] || '';
    return e('span', 'status' + (cls ? ' ' + cls : ''), state);
  }

  /* ─────────────────────────── Phase 3: ledger ─────────────────────────── */
  window.drawLedger = function () {
    var root = document.getElementById('section-root');
    if (!root) return;
    var data = (typeof adminData !== 'undefined' ? adminData : {});
    var rows = data.ledger || [];
    var s = data.stats || {};

    root.replaceChildren();

    var kpis = e('div', 'kpi-row');
    [
      ['TOTAL REVENUE', money(s.revenue || 0), 'Posted debits across the platform'],
      ['PENDING PAYMENTS', String(s.pending_topups || 0), 'Gateway top-ups awaiting settlement'],
      ['COMPLETED TRANSACTIONS', String(s.ledger_posted || 0), 'Posted wallet ledger entries'],
      ['PLATFORM BALANCE', money(s.platform_balance || 0), 'Sum of all customer wallets']
    ].forEach(function (k, i) {
      var card = e('div', 'kpi' + (i === 0 ? ' hot' : ''));
      card.appendChild(e('div', 'kpi-lbl', k[0]));
      var v = e('div', 'kpi-val' + (i === 0 ? ' grad' : ''), k[1]);
      card.appendChild(v);
      card.appendChild(e('div', 'kpi-sub', k[2]));
      kpis.appendChild(card);
    });
    root.appendChild(kpis);

    var card = e('div', 'card');
    var hd = e('div', 'card-hd');
    hd.appendChild(e('h3', null, 'Financial transactions'));
    var tools = e('div', 'actions');
    var refresh = e('button', 'btn small', 'Refresh');
    refresh.type = 'button';
    refresh.onclick = function () { load(); };
    tools.appendChild(refresh);
    hd.appendChild(tools);
    card.appendChild(hd);

    var wrap = e('div', 'table-wrap');
    if (!rows.length) {
      wrap.appendChild(e('p', 'empty', 'No wallet ledger entries yet.'));
    } else {
      var t = e('table');
      var thead = e('thead');
      var hr = e('tr');
      ['ID', 'Client', 'Source', 'Amount', 'Status', 'Reference', 'Date', 'Actions'].forEach(function (h) {
        hr.appendChild(e('th', null, h));
      });
      thead.appendChild(hr);
      t.appendChild(thead);
      var tb = e('tbody');
      rows.forEach(function (r) {
        var tr = e('tr');
        tr.appendChild(e('td', null, '#' + r.id));
        var who = e('td');
        who.appendChild(e('strong', null, r.client || 'Client'));
        who.appendChild(e('br'));
        who.appendChild(e('small', 'muted', r.email || ''));
        tr.appendChild(who);
        tr.appendChild(e('td', null, r.kind || 'ledger'));
        var amt = e('td');
        var neg = Number(r.amount) < 0;
        var span = e('strong', null, (neg ? '' : '+') + money(r.amount));
        span.style.color = neg ? '#fca5a5' : '#86efac';
        amt.appendChild(span);
        tr.appendChild(amt);
        var st = e('td');
        st.appendChild(badge(r.status));
        tr.appendChild(st);
        tr.appendChild(e('td', null, r.reference || '—'));
        tr.appendChild(e('td', null, r.created_at || '—'));

        var act = e('td');
        var box = e('div', 'actions');

        var sel = e('select', 'input');
        sel.style.maxWidth = '120px';
        ['posted', 'pending', 'reversed'].forEach(function (v) {
          var o = e('option', null, v);
          o.value = v;
          if (v === r.status) o.selected = true;
          sel.appendChild(o);
        });
        sel.id = 'lg-' + r.id;
        box.appendChild(sel);

        var save = e('button', 'btn small', 'Set status');
        save.type = 'button';
        save.onclick = function () {
          run('ledger_status', { id: r.id, status: document.getElementById('lg-' + r.id).value }, 'Ledger entry updated.');
        };
        box.appendChild(save);

        var refund = e('button', 'btn small danger', 'Refund');
        refund.type = 'button';
        refund.onclick = function () {
          if (!window.confirm('Reverse ledger entry #' + r.id + ' and credit the client wallet by ' + money(Math.abs(Number(r.amount))) + '?')) return;
          run('ledger_reverse', { id: r.id }, 'Entry reversed and client credited.');
        };
        box.appendChild(refund);

        act.appendChild(box);
        tr.appendChild(act);
        tb.appendChild(tr);
      });
      t.appendChild(tb);
      wrap.appendChild(t);
    }
    card.appendChild(wrap);
    root.appendChild(card);

    var note = e('p', 'muted');
    note.style.marginTop = '10px';
    note.textContent = 'Refunds write a compensating ledger entry, so history is never overwritten.';
    root.appendChild(note);
  };

  /* ─────────────────────── Phase 4: catalog controls ─────────────────────── */
  function enhanceServices() {
    var root = document.getElementById('section-root');
    if (!root) return;
    var tables = root.querySelectorAll('table');
    var svcTable = tables[tables.length - 1];
    var data = (typeof adminData !== 'undefined' && adminData.services) ? adminData.services : [];

    /* rebuild the catalog table with editable price + active toggle */
    if (svcTable && data.length) {
      var tb = svcTable.querySelector('tbody');
      if (tb && !tb.dataset.enhanced) {
        tb.dataset.enhanced = '1';
        tb.replaceChildren();
        data.slice(0, 200).forEach(function (s) {
          var tr = e('tr');
          var name = e('td');
          name.appendChild(e('strong', null, s.name));
          name.appendChild(e('br'));
          name.appendChild(e('small', 'muted', s.slug));
          tr.appendChild(name);

          var price = e('td');
          var inp = e('input', 'input short');
          inp.type = 'number';
          inp.step = '0.01';
          inp.min = '0';
          inp.id = 'svc-price-' + s.id;
          inp.value = Number(s.price).toFixed(2);
          price.appendChild(inp);
          tr.appendChild(price);

          tr.appendChild(e('td', null, s.pricing_mode || 'fixed'));

          var actCell = e('td');
          var chk = e('input');
          chk.type = 'checkbox';
          chk.id = 'svc-active-' + s.id;
          if (Number(s.active)) chk.checked = true;
          actCell.appendChild(chk);
          tr.appendChild(actCell);

          var actions = e('td');
          var btn = e('button', 'btn small primary', 'Save');
          btn.type = 'button';
          btn.onclick = function () {
            run('service_update', {
              id: s.id,
              price: document.getElementById('svc-price-' + s.id).value,
              active: document.getElementById('svc-active-' + s.id).checked
            }, 'Service updated on the public portal.');
          };
          actions.appendChild(btn);
          tr.appendChild(actions);
          tb.appendChild(tr);
        });
      }
    }

    /* ad rate cards */
    var rates = (typeof adminData !== 'undefined' && adminData.rates) ? adminData.rates : [];
    if (!rates.length) return;
    if (document.getElementById('ad-rates-card')) return;

    var card = e('div', 'card');
    card.id = 'ad-rates-card';
    var hd = e('div', 'card-hd');
    hd.appendChild(e('h3', null, 'Social ad rate cards'));
    var toggle = e('button', 'btn small', 'Hide rates');
    toggle.type = 'button';
    var body = e('div');
    toggle.onclick = function () {
      var hidden = body.classList.toggle('hidden');
      toggle.textContent = hidden ? 'Show rates' : 'Hide rates';
    };
    hd.appendChild(toggle);
    card.appendChild(hd);

    var wrap = e('div', 'table-wrap');
    var t = e('table');
    var thead = e('thead');
    var hr = e('tr');
    ['Category', 'Placement', 'Price / day', 'Reach per €1', 'Active', 'Save'].forEach(function (h) {
      hr.appendChild(e('th', null, h));
    });
    thead.appendChild(hr);
    t.appendChild(thead);
    var tb = e('tbody');
    rates.forEach(function (r) {
      var tr = e('tr');
      tr.appendChild(e('td', null, r.category || '—'));
      tr.appendChild(e('td', null, r.placement || '—'));

      var p = e('td');
      var pin = e('input', 'input short');
      pin.type = 'number'; pin.step = '0.01'; pin.min = '0';
      pin.id = 'rate-price-' + r.id;
      pin.value = Number(r.price_per_day || 0).toFixed(2);
      p.appendChild(pin);
      tr.appendChild(p);

      var q = e('td');
      var qin = e('input', 'input short');
      qin.type = 'number'; qin.step = '1'; qin.min = '0';
      qin.id = 'rate-reach-' + r.id;
      qin.value = Number(r.reach_per_eur || 0);
      q.appendChild(qin);
      tr.appendChild(q);

      var a = e('td');
      var ac = e('input');
      ac.type = 'checkbox'; ac.id = 'rate-active-' + r.id;
      if (Number(r.active)) ac.checked = true;
      a.appendChild(ac);
      tr.appendChild(a);

      var s = e('td');
      var b = e('button', 'btn small', 'Save');
      b.type = 'button';
      b.onclick = function () {
        run('rate_update', {
          id: r.id,
          price_per_day: document.getElementById('rate-price-' + r.id).value,
          reach_per_eur: document.getElementById('rate-reach-' + r.id).value,
          active: document.getElementById('rate-active-' + r.id).checked
        }, 'Ad rate updated.');
      };
      s.appendChild(b);
      tr.appendChild(s);
      tb.appendChild(tr);
    });
    t.appendChild(tb);
    wrap.appendChild(t);
    body.appendChild(wrap);
    card.appendChild(body);
    root.appendChild(card);
  }

  /* This file now loads BEFORE the admin bootstrap, so the page's draw*
     functions only exist once that script has run. Wire them afterwards. */
  function whatsNew() {
    var root = document.getElementById('overview-root');
    if (!root || document.getElementById('admin-whats-new')) return;
    var card = document.createElement('div');
    card.className = 'card';
    card.id = 'admin-whats-new';
    card.style.borderColor = 'rgba(99,102,241,.35)';
    card.innerHTML = '<div class="card-hd"><h3>New admin tools</h3><span class="muted">order inspector · ledger · suite</span></div>' +
      '<div class="grid">' +
      '<a class="service-card" href="/admin/?tab=orders"><span class="service-icon">IN</span><span><strong>Order inspector</strong><small>Inspect any order: details, files, metrics, actions</small></span></a>' +
      '<a class="service-card" href="/admin/?tab=ledger"><span class="service-icon">LG</span><span><strong>Transactions ledger</strong><small>Counters, status override, refunds</small></span></a>' +
      '<a class="service-card" href="/admin/suite.php"><span class="service-icon">SU</span><span><strong>Admin suite</strong><small>Service builder, broadcast, bulk clients, login-as</small></span></a>' +
      '</div>';
    root.prepend(card);
  }

  function wire() {
    if (!window.__tdcPhase34Wired) {
      window.__tdcPhase34Wired = true;
      var originalDrawServices = window.drawServices;
      if (typeof originalDrawServices === 'function') {
        window.drawServices = function () {
          originalDrawServices();
          try { enhanceServices(); } catch (err) { }
        };
      }
      var originalDrawOverview = window.drawOverview;
      if (typeof originalDrawOverview === 'function') {
        window.drawOverview = function () {
          originalDrawOverview();
          try { whatsNew(); } catch (err) { }
        };
      }
    }
    /* the page may already have rendered a tab before this file ran */
    try {
      if (typeof current !== 'undefined') {
        if (current === 'services') window.drawServices();
        else if (current === 'ledger') window.drawLedger();
        else if (current === 'overview') window.drawOverview();
      }
    } catch (err) { }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wire);
  else wire();
})();
