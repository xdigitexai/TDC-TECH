<?php
require_once dirname(__DIR__,2).'/app/service_orders.php';
$u = require_user();

$categories = [
    'musician' => 'Musician / Artist',
    'business' => 'Business',
    'public_figure' => 'Public Figure',
    'creator' => 'Content Creator',
    'other' => 'Other',
];
$platforms = ['Meta Verified', 'TikTok', 'Twitter / X', 'Spotify OAC'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $orders = tdc_orders_for_page((int)$u['id'], 'verification');
    $service = tdc_service('verification');
    json_out([
        'ok' => true,
        'price' => (float)$service['price'],
        'categories' => $categories,
        'platforms' => $platforms,
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
    $platform = (string)($d['platform'] ?? '');
    if (!in_array($platform, $platforms, true)) throw new TdcOrderError('Choose a verification platform.');
    $category = (string)($d['category'] ?? '');
    if (!isset($categories[$category])) throw new TdcOrderError('Choose the account category.');

    $handle = tdc_text_field($d, 'handle', 120, true);
    if (text_len($handle) < 2) throw new TdcOrderError('Enter the profile handle or name.');
    $profileUrl = tdc_url_field($d, 'profile_url', true);

    $description = tdc_text_field($d, 'description', 4000, true);
    if (text_len($description) < 50) throw new TdcOrderError('Describe the account identity and background in at least 50 characters.');

    $pressLinks = tdc_url_list((string)($d['press_links'] ?? ''), 3, 20);
    $mediaIds = tdc_media_ids($d['media_ids'] ?? []);

    $service = tdc_service_for_page('verification', 'verification');
    $details = [
        'platform' => $platform,
        'handle' => $handle,
        'profile_url' => $profileUrl,
        'category' => $category,
        'category_label' => $categories[$category],
        'description' => $description,
        'press_links' => $pressLinks,
        'media_ids' => $mediaIds,
    ];
    $result = tdc_place_order((int)$u['id'], 'verification', 'Verification — '.$handle, $details, (float)$service['price'], $mediaIds);
    json_out(['ok' => true, 'message' => 'Verification application submitted.'] + $result);
} catch (TdcOrderError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
}
