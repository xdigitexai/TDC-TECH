<?php
/* TDC Tech admin suite API — Phase 5, 6, 7
 * Phase 5: dynamic service builder (create service + custom form fields)
 * Phase 6: SMTP broadcast (test + batch, queued through notification_outbox)
 * Phase 7: bulk client actions, impersonation ("enter public account"), media delete
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
$d = json_body();
$act = (string) ($d['action'] ?? '');
$pdo = db();

/* Leaving an impersonation session is allowed without an admin session. */
if ($act === 'impersonate_stop') {
    $back = (int) ($_SESSION['impersonator_uid'] ?? 0);
    if (!$back) json_out(['error' => 'No impersonation session is active.'], 409);
    $_SESSION['uid'] = $back;
    unset($_SESSION['impersonator_uid']);
    audit('user.impersonation_stopped', $back, []);
    json_out(['ok' => true, 'redirect' => '/admin/']);
}

require_method('POST');
require_csrf();
$admin = require_admin();

/* ─────────────── Phase 5: create a service ─────────────── */
if ($act === 'service_create') {
    $name = trim((string) ($d['name'] ?? ''));
    $price = round((float) ($d['price'] ?? -1), 2);
    $pageSlug = (string) ($d['page_slug'] ?? '');
    $mode = (string) ($d['pricing_mode'] ?? 'fixed');
    $icon = trim((string) ($d['dashboard_icon'] ?? 'star'));
    $fields = $d['fields'] ?? [];

    if (text_len($name) < 2 || text_len($name) > 160) json_out(['error' => 'Service name must be 2–160 characters.'], 422);
    if ($price < 0 || $price > 100000) json_out(['error' => 'Price must be from 0 to 100,000.'], 422);
    if (!in_array($mode, ['fixed', 'per_unit', 'quote'], true)) json_out(['error' => 'Invalid pricing mode.'], 422);
    if (!preg_match('/^[a-z0-9-]{2,80}$/', $pageSlug)) json_out(['error' => 'Choose a service page.'], 422);

    $q = $pdo->prepare('SELECT slug FROM service_pages WHERE slug=?');
    $q->execute([$pageSlug]);
    if (!$q->fetch()) json_out(['error' => 'That service page does not exist.'], 422);

    if (!is_array($fields)) $fields = [];
    if (count($fields) > 20) json_out(['error' => 'At most 20 custom fields.'], 422);
    $clean = [];
    foreach ($fields as $f) {
        if (!is_array($f)) continue;
        $label = trim((string) ($f['label'] ?? ''));
        if ($label === '') continue;
        $key = strtolower(preg_replace('/[^a-z0-9_]+/', '_', (string) ($f['key'] ?? $label)));
        $key = trim($key, '_');
        if ($key === '') $key = 'field_' . count($clean);
        $type = (string) ($f['type'] ?? 'text');
        if (!in_array($type, ['text', 'url', 'number', 'textarea', 'file'], true)) $type = 'text';
        $clean[] = ['key' => substr($key, 0, 40), 'label' => text_cut($label, 0, 80), 'type' => $type, 'required' => !empty($f['required'])];
    }

    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $slug = trim(substr($slug, 0, 70), '-');
    if ($slug === '') json_out(['error' => 'Could not build a slug from that name.'], 422);
    $q = $pdo->prepare('SELECT id FROM services WHERE slug=?');
    $q->execute([$slug]);
    if ($q->fetch()) $slug .= '-' . bin2hex(random_bytes(2));

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO services(slug,name,price,pricing_mode,active,dashboard_icon,form_fields,created_at,updated_at) VALUES(?,?,?,?,0,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
            ->execute([$slug, text_cut($name, 0, 160), $price, $mode, text_cut($icon, 0, 40), json_encode($clean)]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO service_page_services(page_slug,service_id) VALUES(?,?)')->execute([$pageSlug, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('service_create failed: ' . $e->getMessage());
        json_out(['error' => 'The service could not be created.'], 500);
    }

    audit('service.created', (int) $admin['id'], ['service_id' => $id, 'slug' => $slug, 'page' => $pageSlug, 'fields' => count($clean)]);
    json_out(['ok' => true, 'id' => $id, 'slug' => $slug, 'fields' => count($clean), 'published' => false]);
}

/* ─────────────── Phase 6: broadcast ─────────────── */
function broadcast_recipients(array $d, PDO $pdo): array {
    if (!empty($d['all'])) {
        $rows = $pdo->query("SELECT id,name,email FROM users WHERE role='customer' AND status='active' AND email<>''")->fetchAll();
        return $rows;
    }
    $ids = array_values(array_unique(array_map('intval', (array) ($d['ids'] ?? []))));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT id,name,email FROM users WHERE id IN ($in) AND role='customer'");
    $q->execute($ids);
    return $q->fetchAll();
}

if ($act === 'broadcast_test' || $act === 'broadcast_send') {
    require_once dirname(__DIR__, 2) . '/app/notifications.php';
    $subject = trim((string) ($d['subject'] ?? ''));
    $html = (string) ($d['html'] ?? '');
    if (text_len($subject) < 3 || text_len($subject) > 200) json_out(['error' => 'Subject must be 3–200 characters.'], 422);
    if (trim($html) === '') json_out(['error' => 'Add the email HTML or text.'], 422);
    if (strlen($html) > 60000) json_out(['error' => 'Email body is too large (60 KB max).'], 422);

    $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?: '');
    $body = $html;

    if ($act === 'broadcast_test') {
        $to = (string) ($admin['email'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'The admin account has no valid email.'], 422);
        queue_email($to, '[TEST] ' . $subject, $body, (int) $admin['id']);
        audit('broadcast.test_queued', (int) $admin['id'], ['to' => $to, 'subject' => $subject]);
        json_out(['ok' => true, 'queued' => 1, 'to' => $to]);
    }

    $people = broadcast_recipients($d, $pdo);
    if (!$people) json_out(['error' => 'No recipients matched.'], 422);
    if (count($people) > 500) json_out(['error' => 'Batch limited to 500 recipients per send.'], 422);

    $queued = 0;
    foreach ($people as $p) {
        if (!filter_var((string) $p['email'], FILTER_VALIDATE_EMAIL)) continue;
        queue_email((string) $p['email'], $subject, $body, (int) $p['id']);
        $queued++;
    }
    audit('broadcast.queued', (int) $admin['id'], ['recipients' => $queued, 'subject' => $subject]);
    json_out(['ok' => true, 'queued' => $queued, 'subject' => $subject]);
}

/* ─────────────── Phase 7: bulk clients ─────────────── */
if ($act === 'users_bulk') {
    $op = (string) ($d['op'] ?? '');
    $ids = array_values(array_unique(array_map('intval', (array) ($d['ids'] ?? []))));
    $ids = array_values(array_filter($ids, fn($i) => $i > 0 && $i !== (int) $admin['id']));
    if (!$ids) json_out(['error' => 'Select at least one client (you cannot target your own account).'], 422);
    if (count($ids) > 200) json_out(['error' => 'Select at most 200 clients at once.'], 422);
    $in = implode(',', array_fill(0, count($ids), '?'));

    if ($op === 'ban') {
        $q = $pdo->prepare("UPDATE users SET status='banned', updated_at=UTC_TIMESTAMP() WHERE id IN ($in) AND role='customer'");
        $q->execute($ids);
        audit('users.bulk_banned', (int) $admin['id'], ['ids' => $ids, 'count' => $q->rowCount()]);
        json_out(['ok' => true, 'affected' => $q->rowCount()]);
    }
    if ($op === 'unban') {
        $q = $pdo->prepare("UPDATE users SET status='active', updated_at=UTC_TIMESTAMP() WHERE id IN ($in) AND role='customer'");
        $q->execute($ids);
        audit('users.bulk_unbanned', (int) $admin['id'], ['ids' => $ids, 'count' => $q->rowCount()]);
        json_out(['ok' => true, 'affected' => $q->rowCount()]);
    }
    if ($op === 'delete') {
        /* soft delete: mark deleted and release the email address for re-registration */
        $q = $pdo->prepare("SELECT id FROM users WHERE id IN ($in) AND role='customer'");
        $q->execute($ids);
        $targets = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $done = 0;
        foreach ($targets as $id) {
            $pdo->prepare("UPDATE users SET status='deleted', email=CONCAT('deleted+', id, '@deleted.local'), password_hash=?, updated_at=UTC_TIMESTAMP() WHERE id=?")
                ->execute([password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), $id]);
            $done++;
        }
        audit('users.bulk_deleted', (int) $admin['id'], ['ids' => $targets, 'count' => $done]);
        json_out(['ok' => true, 'affected' => $done, 'note' => 'Emails released for re-registration.']);
    }
    if ($op === 'email') {
        require_once dirname(__DIR__, 2) . '/app/notifications.php';
        $subject = trim((string) ($d['subject'] ?? ''));
        $body = (string) ($d['body'] ?? '');
        if (text_len($subject) < 3 || trim($body) === '') json_out(['error' => 'Subject and message are required.'], 422);
        $q = $pdo->prepare("SELECT id,email FROM users WHERE id IN ($in) AND role='customer' AND status='active'");
        $q->execute($ids);
        $n = 0;
        foreach ($q->fetchAll() as $u) {
            if (!filter_var((string) $u['email'], FILTER_VALIDATE_EMAIL)) continue;
            queue_email((string) $u['email'], $subject, $body, (int) $u['id']);
            $n++;
        }
        audit('users.bulk_emailed', (int) $admin['id'], ['count' => $n, 'subject' => $subject]);
        json_out(['ok' => true, 'queued' => $n]);
    }
    json_out(['error' => 'Unknown bulk operation.'], 422);
}

/* ─────────────── Phase 7: impersonation ─────────────── */
if ($act === 'impersonate') {
    $id = (int) ($d['id'] ?? 0);
    $q = $pdo->prepare("SELECT id,name,email,status FROM users WHERE id=? AND role='customer'");
    $q->execute([$id]);
    $u = $q->fetch();
    if (!$u) json_out(['error' => 'Customer not found.'], 404);
    if ($u['status'] !== 'active') json_out(['error' => 'Only active customers can be entered.'], 409);
    $_SESSION['impersonator_uid'] = (int) $admin['id'];
    $_SESSION['uid'] = (int) $u['id'];
    audit('user.impersonation_started', (int) $admin['id'], ['target_id' => (int) $u['id']]);
    json_out(['ok' => true, 'redirect' => '/dashboard.php', 'as' => $u['name']]);
}


/* ─────────────── Phase 7: media delete ─────────────── */
if ($act === 'media_delete') {
    $id = (string) ($d['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) json_out(['error' => 'Invalid media id.'], 422);
    $q = $pdo->prepare('SELECT id,original_name,order_id FROM customer_media WHERE id=?');
    $q->execute([$id]);
    $m = $q->fetch();
    if (!$m) json_out(['error' => 'Media not found.'], 404);
    $pdo->prepare('DELETE FROM customer_media WHERE id=?')->execute([$id]);
    audit('media.deleted', (int) $admin['id'], ['media_id' => $id, 'order_id' => (int) $m['order_id'], 'name' => $m['original_name']]);
    json_out(['ok' => true, 'deleted' => $m['original_name']]);
}

json_out(['error' => 'Unknown action.'], 400);
