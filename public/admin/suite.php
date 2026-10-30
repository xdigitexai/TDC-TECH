<?php
require dirname(__DIR__, 2) . '/app/admin_view.php';
admin_header('suite', 'Admin suite');
$csrf = csrf_token();
$pdo = db();
$pages = $pdo->query('SELECT slug,name FROM service_pages WHERE active=1 ORDER BY sort_order, name')->fetchAll();
$clients = $pdo->query("SELECT id,name,email,status,created_at FROM users WHERE role='customer' ORDER BY id DESC LIMIT 300")->fetchAll();
$media = $pdo->query('SELECT id,original_name,kind,order_id,bytes FROM customer_media ORDER BY created_at DESC LIMIT 40')->fetchAll();
?>
<style>
.suite-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.suite-tab{min-height:33px;padding:0 14px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:var(--sub);font-size:.7rem;font-weight:700}
.suite-tab.active{color:#a5b4fc;background:rgba(99,102,241,.14);border-color:rgba(99,102,241,.28)}
.suite-panel{display:none}.suite-panel.active{display:block}
.fields-row{display:grid;grid-template-columns:1fr 1fr 120px 90px 40px;gap:8px;margin-bottom:8px;align-items:center}
.ck{width:15px;height:15px;accent-color:#6366f1}
.mini{font-size:.62rem;color:var(--muted)}
</style>

<div class="suite-tabs">
  <button class="suite-tab active" data-panel="builder">Service builder</button>
  <button class="suite-tab" data-panel="broadcast">Email broadcast</button>
  <button class="suite-tab" data-panel="clients">Bulk clients &amp; login-as</button>
  <button class="suite-tab" data-panel="media">Media library</button>
</div>
<div class="notice" id="suite-notice" role="status"></div>

<!-- ── Phase 5: service builder ── -->
<section class="suite-panel active" id="panel-builder">
  <div class="card">
    <div class="card-hd"><h3>Create a service</h3><span class="muted">Publishes to the customer dashboard when you enable it</span></div>
    <div class="form-grid">
      <div class="field"><label>Service title</label><input id="sv-name" placeholder="PayPal Account Creation"></div>
      <div class="field"><label>Price (€)</label><input id="sv-price" type="number" step="0.01" min="0" value="0"></div>
      <div class="field"><label>Service page</label>
        <select id="sv-page">
          <?php foreach ($pages as $p): ?><option value="<?= htmlspecialchars($p['slug'], ENT_QUOTES) ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Pricing mode</label>
        <select id="sv-mode"><option value="fixed">Fixed price</option><option value="per_unit">Per unit</option><option value="quote">Quote on request</option></select>
      </div>
    </div>
    <div class="section-label">Custom intake fields</div>
    <div id="sv-fields"></div>
    <div class="actions" style="margin-top:8px">
      <button class="btn small" type="button" onclick="addField()">+ Add field</button>
      <button class="btn small primary" type="button" onclick="createService()">Create service</button>
    </div>
    <p class="mini" style="margin-top:10px">New services are created <strong>inactive</strong>. Enable it in the Services tab once you're happy with the price.</p>
  </div>
</section>

<!-- ── Phase 6: broadcast ── -->
<section class="suite-panel" id="panel-broadcast">
  <div class="card">
    <div class="card-hd"><h3>Email broadcast</h3><span class="muted">Queued through the notification outbox (SMTP)</span></div>
    <div class="field"><label>Subject</label><input id="bc-subject" placeholder="Important update from TDC Tech"></div>
    <div class="field"><label>HTML or plain text</label><textarea id="bc-html" rows="10" placeholder="&lt;h1&gt;Hello&lt;/h1&gt;&lt;p&gt;Your update…&lt;/p&gt;"></textarea></div>
    <div class="actions" style="margin-bottom:10px">
      <input type="file" id="bc-file" accept=".html,.htm,.txt" style="max-width:260px">
      <button class="btn small" type="button" onclick="loadTemplate()">Load template file</button>
    </div>
    <div class="field"><label>Recipients</label>
      <label class="mini" style="display:flex;gap:7px;align-items:center;margin-bottom:6px">
        <input type="checkbox" class="ck" id="bc-all" checked onchange="toggleAllRecipients()"> All active customers (<?= count(array_filter($clients, fn($c) => $c['status'] === 'active')) ?>)
      </label>
      <div style="max-height:230px;overflow:auto;border:1px solid var(--border);border-radius:10px;padding:8px">
        <?php foreach ($clients as $c): if ($c['status'] !== 'active') continue; ?>
          <label class="mini" style="display:flex;gap:8px;align-items:center;padding:3px 2px">
            <input type="checkbox" class="ck bc-pick" value="<?= (int) $c['id'] ?>" disabled>
            <span><?= htmlspecialchars($c['name']) ?> · <?= htmlspecialchars($c['email']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="actions">
      <button class="btn small" type="button" onclick="sendBroadcast(true)">Send test to me</button>
      <button class="btn small primary" type="button" onclick="sendBroadcast(false)">Send email broadcast</button>
    </div>
  </div>
</section>

<!-- ── Phase 7: bulk clients ── -->
<section class="suite-panel" id="panel-clients">
  <div class="card">
    <div class="card-hd">
      <h3>Clients</h3>
      <div class="actions">
        <button class="btn small" type="button" onclick="bulk('ban')">Ban selected</button>
        <button class="btn small" type="button" onclick="bulk('unban')">Unban selected</button>
        <button class="btn small danger" type="button" onclick="bulk('delete')">Delete selected</button>
      </div>
    </div>
    <div class="field"><label>Bulk email to selected</label>
      <input id="bul-subject" placeholder="Subject" style="margin-bottom:6px">
      <textarea id="bul-body" rows="3" placeholder="Message…"></textarea>
    </div>
    <div class="actions" style="margin-bottom:10px"><button class="btn small" type="button" onclick="bulk('email')">Queue email</button></div>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th><input type="checkbox" class="ck" id="cl-all" onchange="toggleAllClients()"></th>
          <th>Client</th><th>Email</th><th>Status</th><th>Joined</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($clients as $c): ?>
          <tr>
            <td><input type="checkbox" class="ck cl-pick" value="<?= (int) $c['id'] ?>"></td>
            <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
            <td><?= htmlspecialchars($c['email']) ?></td>
            <td><?= htmlspecialchars($c['status']) ?></td>
            <td class="mini"><?= htmlspecialchars(substr((string) $c['created_at'], 0, 10)) ?></td>
            <td><button class="btn small" type="button" onclick="enterAccount(<?= (int) $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name']), ENT_QUOTES) ?>')">Enter account</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- ── Phase 7: media ── -->
<section class="suite-panel" id="panel-media">
  <div class="card">
    <div class="card-hd"><h3>Media library</h3><span class="muted">Deleting a file is permanent (confirmation required)</span></div>
    <div class="grid">
      <?php foreach ($media as $m): ?>
        <article class="service-card" style="align-items:flex-start">
          <span class="service-icon"><?= $m['kind'] === 'image' ? 'IMG' : strtoupper(substr((string) $m['kind'], 0, 3)) ?></span>
          <span style="min-width:0">
            <strong style="display:block;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($m['original_name']) ?></strong>
            <small class="mini"><?= htmlspecialchars($m['kind']) ?> · <?= number_format(((int) $m['bytes']) / 1048576, 2) ?> MB · order #<?= (int) $m['order_id'] ?></small>
            <span class="actions" style="margin-top:7px">
              <a class="btn small" href="/api/music-media.php?id=<?= urlencode($m['id']) ?>" target="_blank" rel="noopener">Preview</a>
              <button class="btn small danger" type="button" onclick="deleteMedia('<?= htmlspecialchars($m['id'], ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($m['original_name']), ENT_QUOTES) ?>')">Delete</button>
            </span>
          </span>
        </article>
      <?php endforeach; ?>
      <?php if (!$media): ?><p class="empty">No media uploaded yet.</p><?php endif; ?>
    </div>
  </div>
</section>

<script>
const SUITE_CSRF = <?= json_encode($csrf) ?>;
const notice = document.getElementById('suite-notice');
function say(msg, bad) { notice.textContent = msg; notice.className = 'notice' + (bad ? ' error' : ''); if (!bad) window.toast && window.toast(msg); }
async function api(payload) {
  const r = await fetch('/api/admin-suite.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': SUITE_CSRF },
    body: JSON.stringify(payload)
  });
  const d = await r.json().catch(() => ({ error: 'Unexpected response.' }));
  if (!r.ok) throw new Error(d.error || 'Request failed.');
  return d;
}
document.querySelectorAll('.suite-tab').forEach(t => t.onclick = () => {
  document.querySelectorAll('.suite-tab').forEach(x => x.classList.remove('active'));
  document.querySelectorAll('.suite-panel').forEach(x => x.classList.remove('active'));
  t.classList.add('active');
  document.getElementById('panel-' + t.dataset.panel).classList.add('active');
});

/* Phase 5 */
function addField(label, key, type, required) {
  const row = document.createElement('div');
  row.className = 'fields-row';
  row.innerHTML = '<input class="f-label" placeholder="Field label (e.g. PayPal email)" value="' + (label || '') + '">' +
    '<input class="f-key" placeholder="key (optional)" value="' + (key || '') + '">' +
    '<select class="f-type">' +
      ['text','url','number','textarea','file'].map(t => '<option value="' + t + '"' + (t === (type || 'text') ? ' selected' : '') + '>' + t + '</option>').join('') +
    '</select>' +
    '<label class="mini" style="display:flex;gap:5px;align-items:center"><input type="checkbox" class="ck f-req"' + (required ? ' checked' : '') + '> req</label>' +
    '<button class="btn small danger" type="button">×</button>';
  row.querySelector('button').onclick = () => row.remove();
  document.getElementById('sv-fields').appendChild(row);
}
addField();
async function createService() {
  const fields = [...document.querySelectorAll('#sv-fields .fields-row')].map(r => ({
    label: r.querySelector('.f-label').value.trim(),
    key: r.querySelector('.f-key').value.trim(),
    type: r.querySelector('.f-type').value,
    required: r.querySelector('.f-req').checked
  })).filter(f => f.label);
  try {
    const d = await api({
      action: 'service_create',
      name: document.getElementById('sv-name').value.trim(),
      price: document.getElementById('sv-price').value,
      page_slug: document.getElementById('sv-page').value,
      pricing_mode: document.getElementById('sv-mode').value,
      fields
    });
    say('Service #' + d.id + ' created (' + d.slug + ') with ' + d.fields + ' field(s). Enable it in the Services tab.', false);
  } catch (e) { say(e.message, true); }
}

/* Phase 6 */
function toggleAllRecipients() {
  const all = document.getElementById('bc-all').checked;
  document.querySelectorAll('.bc-pick').forEach(c => { c.disabled = all; if (all) c.checked = false; });
}
function loadTemplate() {
  const f = document.getElementById('bc-file').files[0];
  if (!f) return say('Choose an .html or .txt file first.', true);
  const r = new FileReader();
  r.onload = () => { document.getElementById('bc-html').value = r.result; say('Template loaded: ' + f.name, false); };
  r.readAsText(f);
}
async function sendBroadcast(test) {
  const all = document.getElementById('bc-all').checked;
  const ids = [...document.querySelectorAll('.bc-pick:checked')].map(c => Number(c.value));
  if (!all && !ids.length) return say('Select at least one recipient or tick "All active customers".', true);
  const payload = {
    action: test ? 'broadcast_test' : 'broadcast_send',
    subject: document.getElementById('bc-subject').value.trim(),
    html: document.getElementById('bc-html').value
  };
  if (!test) { payload.all = all; payload.ids = ids; }
  try {
    const d = await api(payload);
    say(test ? ('Test queued to ' + d.to) : ('Broadcast queued for ' + d.queued + ' recipient(s).'), false);
  } catch (e) { say(e.message, true); }
}

/* Phase 7 */
function toggleAllClients() {
  const on = document.getElementById('cl-all').checked;
  document.querySelectorAll('.cl-pick').forEach(c => c.checked = on);
}
async function bulk(op) {
  const ids = [...document.querySelectorAll('.cl-pick:checked')].map(c => Number(c.value));
  if (!ids.length) return say('Select at least one client.', true);
  if (op === 'delete' && !window.confirm('Delete ' + ids.length + ' client(s)? Their email addresses are released for re-registration and the accounts stop receiving mail.')) return;
  const payload = { action: 'users_bulk', op, ids };
  if (op === 'email') {
    payload.subject = document.getElementById('bul-subject').value.trim();
    payload.body = document.getElementById('bul-body').value;
  }
  try {
    const d = await api(payload);
    const n = d.affected ?? d.queued ?? 0;
    say(op === 'email' ? ('Queued ' + n + ' email(s).') : (op + ': ' + n + ' account(s) updated.'), false);
    setTimeout(() => location.reload(), 1200);
  } catch (e) { say(e.message, true); }
}
async function enterAccount(id, name) {
  if (!window.confirm('Enter the public account of ' + name + '? You will be signed in as that customer until you return.')) return;
  try {
    const d = await api({ action: 'impersonate', id });
    window.location.href = d.redirect || '/dashboard.php';
  } catch (e) { say(e.message, true); }
}
async function deleteMedia(id, name) {
  if (!window.confirm('Delete “' + name + '” permanently? This cannot be undone.')) return;
  try {
    const d = await api({ action: 'media_delete', id });
    say('Deleted ' + d.deleted, false);
    setTimeout(() => location.reload(), 900);
  } catch (e) { say(e.message, true); }
}
</script>
<?php admin_footer(); ?>
