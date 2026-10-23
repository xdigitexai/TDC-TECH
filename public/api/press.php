<?php
require_once dirname(__DIR__,2).'/app/service_orders.php';
$u = require_user();

$allowed = ['press-tier1', 'press-tier2', 'wiki-tier1', 'wiki-tier2'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $marks = implode(',', array_fill(0, count($allowed), '?'));
    $q = db()->prepare("SELECT s.slug,s.name,s.price FROM services s JOIN service_page_services ps ON ps.service_id=s.id AND ps.page_slug='press' WHERE s.slug IN ($marks) AND s.active=1 ORDER BY s.id");
    $q->execute($allowed);
    $services = $q->fetchAll();
    foreach ($services as &$s) $s['price'] = (float)$s['price'];
    unset($s);
    $orders = tdc_orders_for_page((int)$u['id'], 'press');
    json_out([
        'ok' => true,
        'services' => $services,
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
    if (!in_array($slug, $allowed, true)) throw new TdcOrderError('Choose a press release tier.');
    $service = tdc_service_for_page($slug, 'press');
    $isWiki = str_starts_with($slug, 'wiki-');
    $f = $d['fields'] ?? [];
    if (!is_array($f)) $f = [];

    if ($isWiki) {
        $clean = [
            'subject' => tdc_text_field($f, 'subject', 200, true),
            'language' => tdc_text_field($f, 'language', 40, true),
            'press_links' => tdc_url_list((string)($f['press_links'] ?? ''), 0, 20),
            'brief' => tdc_text_field($f, 'brief', 4000),
        ];
        $title = $clean['subject'];
        $description = 'Wikipedia — '.$clean['subject'];
    } else {
        $headline = tdc_text_field($f, 'headline', 200, true);
        if (text_len($headline) < 8) throw new TdcOrderError('Enter a full press release headline.');
        $body = tdc_text_field($f, 'body', 8000, true);
        if (text_len($body) < 40) throw new TdcOrderError('Add the article draft or a brief of at least 40 characters.');
        $clean = [
            'headline' => $headline,
            'body' => $body,
            'target_outlets' => tdc_text_field($f, 'target_outlets', 1000),
            'contact_email' => tdc_text_field($f, 'contact_email', 254),
        ];
        if ($clean['contact_email'] !== '' && !filter_var($clean['contact_email'], FILTER_VALIDATE_EMAIL)) throw new TdcOrderError('Enter a valid contact email.');
        $title = $headline;
        $description = 'Press release — '.$headline;
    }

    $mediaIds = tdc_media_ids($d['media_ids'] ?? []);
    $details = [
        'service_slug' => $slug,
        'tier' => str_ends_with($slug, 'tier2') ? '2' : '1',
        'wiki' => $isWiki,
        'fields' => $clean,
        'media_ids' => $mediaIds,
    ];
    $result = tdc_place_order((int)$u['id'], $slug, text_cut($description, 0, 250), $details, (float)$service['price'], $mediaIds);
    json_out(['ok' => true, 'message' => 'Press release submitted.', 'title' => $title] + $result);
} catch (TdcOrderError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
}
