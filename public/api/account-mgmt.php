<?php
require_once dirname(__DIR__,2).'/app/service_orders.php';
$u = require_user();

$allowed = ['account-social', 'account-publicist', 'account-youtube', 'account-meta', 'account-dmca'];

function tdc_account_services(array $allowed): array
{
    $marks = implode(',', array_fill(0, count($allowed), '?'));
    $q = db()->prepare("SELECT s.slug,s.name,s.price FROM services s JOIN service_page_services ps ON ps.service_id=s.id AND ps.page_slug='account-mgmt' WHERE s.slug IN ($marks) AND s.active=1 ORDER BY FIELD(s.slug,'account-social','account-publicist','account-youtube','account-meta','account-dmca')");
    $q->execute($allowed);
    $rows = $q->fetchAll();
    foreach ($rows as &$row) $row['price'] = (float)$row['price'];
    unset($row);
    return $rows;
}

function tdc_email_field(string $value, string $label): string
{
    $value = trim($value);
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) throw new TdcOrderError('Enter a valid '.$label.'.');
    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $orders = tdc_orders_for_page((int)$u['id'], 'account-mgmt');
    json_out([
        'ok' => true,
        'services' => tdc_account_services($allowed),
        'orders' => $orders,
        'stats' => tdc_order_totals($orders),
        'balance' => tdc_user_balance((int)$u['id']),
        'csrf' => csrf_token(),
    ]);
}

require_method('POST');
require_csrf();
$d = json_body();

try {
    $slug = (string)($d['service_slug'] ?? '');
    if (!in_array($slug, $allowed, true)) throw new TdcOrderError('Choose a management service.');
    $service = tdc_service_for_page($slug, 'account-mgmt');
    $f = $d['fields'] ?? [];
    if (!is_array($f)) $f = [];
    $clean = [];
    $target = $service['name'];

    switch ($slug) {
        case 'account-social':
            $clean['company_brand'] = tdc_text_field($f, 'company_brand', 160, true);
            $clean['business_description'] = tdc_text_field($f, 'business_description', 2000, true);
            $clean['contact_email'] = tdc_email_field((string)($f['contact_email'] ?? ''), 'contact email');
            $clean['contact_phone'] = tdc_text_field($f, 'contact_phone', 60, true);
            $platforms = $f['platforms'] ?? [];
            $valid = ['Instagram', 'Facebook', 'X / Twitter', 'TikTok', 'LinkedIn', 'YouTube'];
            $platforms = is_array($platforms) ? array_values(array_intersect($valid, array_map('strval', $platforms))) : [];
            if (!$platforms) throw new TdcOrderError('Select at least one platform to manage.');
            $clean['platforms'] = $platforms;
            $clean['handles'] = tdc_text_field($f, 'handles', 1000, true);
            $hours = (string)($f['hours'] ?? '');
            if (!in_array($hours, ['10', '20', '40'], true)) throw new TdcOrderError('Choose the weekly management hours.');
            $clean['hours_per_week'] = (int)$hours;
            $duration = (string)($f['duration'] ?? '');
            if (!in_array($duration, ['1', '3', '6'], true)) throw new TdcOrderError('Choose a retainer duration.');
            $clean['duration_months'] = (int)$duration;
            $clean['client_notes'] = tdc_text_field($f, 'client_notes', 2000);
            $target = $clean['company_brand'];
            break;

        case 'account-dmca':
            $clean['infringing_urls'] = tdc_url_list((string)($f['infringing_urls'] ?? ''), 1, 20);
            $clean['ownership_proof'] = tdc_text_field($f, 'ownership_proof', 2000, true);
            $clean['target_platforms'] = tdc_text_field($f, 'target_platforms', 300, true);
            $clean['contact_name'] = tdc_text_field($f, 'contact_name', 120, true);
            $clean['contact_email'] = tdc_email_field((string)($f['contact_email'] ?? ''), 'contact email');
            $clean['violation_details'] = tdc_text_field($f, 'violation_details', 4000, true);
            $clean['authorization_document'] = tdc_text_field($f, 'authorization_document', 300);
            $target = $clean['infringing_urls'][0];
            break;

        case 'account-youtube':
            $mode = (string)($f['mode'] ?? '');
            if (!in_array($mode, ['existing', 'new'], true)) throw new TdcOrderError('Choose whether to monetise an existing channel or order a new one.');
            $clean['mode'] = $mode;
            $clean['channel_url'] = tdc_url_field($f, 'channel_url', true);
            $clean['niche'] = tdc_text_field($f, 'niche', 200, true);
            $clean['target_metrics'] = tdc_text_field($f, 'target_metrics', 500, true);
            $clean['contact_email'] = tdc_email_field((string)($f['contact_email'] ?? ''), 'contact email');
            $clean['notes'] = tdc_text_field($f, 'notes', 2000);
            $target = $clean['channel_url'];
            break;

        case 'account-publicist':
            $clean['brand_artist'] = tdc_text_field($f, 'brand_artist', 160, true);
            $clean['media_goals'] = tdc_text_field($f, 'media_goals', 2000, true);
            $clean['preferred_outlets'] = tdc_text_field($f, 'preferred_outlets', 1000, true);
            $clean['press_kit_url'] = tdc_url_field($f, 'press_kit_url');
            $clean['launch_schedule'] = tdc_text_field($f, 'launch_schedule', 120, true);
            $clean['notes'] = tdc_text_field($f, 'notes', 2000);
            $target = $clean['brand_artist'];
            break;

        case 'account-meta':
            $clean['page_link'] = tdc_url_field($f, 'page_link', true);
            $clean['bm_name'] = tdc_text_field($f, 'bm_name', 160, true);
            $clean['registration_id'] = tdc_text_field($f, 'registration_id', 120, true);
            $clean['admin_email'] = tdc_email_field((string)($f['admin_email'] ?? ''), 'admin email');
            $clean['contact_phone'] = tdc_text_field($f, 'contact_phone', 60, true);
            $clean['instructions'] = tdc_text_field($f, 'instructions', 3000);
            $target = $clean['bm_name'];
            break;
    }

    $mediaIds = tdc_media_ids($d['media_ids'] ?? []);
    $details = [
        'service_slug' => $slug,
        'service_label' => $service['name'],
        'fields' => $clean,
        'media_ids' => $mediaIds,
    ];
    $result = tdc_place_order((int)$u['id'], $slug, $service['name'].' — '.text_cut($target, 0, 180), $details, (float)$service['price'], $mediaIds);
    json_out(['ok' => true, 'message' => 'Management order submitted.'] + $result);
} catch (TdcOrderError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
}
