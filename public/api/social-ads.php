<?php
require_once dirname(__DIR__,2).'/app/ad_rates.php';
$u = require_user();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $orders = tdc_orders_for_page((int)$u['id'], 'social-ads');
    json_out([
        'ok' => true,
        'categories' => tdc_ad_categories(),
        'placements' => tdc_ad_placements(),
        'rates' => tdc_ad_rates(),
        'orders' => $orders,
        'stats' => tdc_order_totals($orders),
        'balance' => tdc_user_balance((int)$u['id']),
        'csrf' => csrf_token(),
    ]);
}

require_method('POST');
require_csrf();
$d = json_body();
$action = (string)($d['action'] ?? 'create');

try {
    $quote = tdc_ad_quote($d);
    if ($action === 'quote') json_out(['ok' => true, 'quote' => $quote]);

    $category = $quote['category'];
    $cat = $d['category_fields'] ?? [];
    if (!is_array($cat)) $cat = [];
    $clean = [];

    switch ($category) {
        case 'music':
            $clean['artist_name'] = tdc_text_field($cat, 'artist_name', 160, true);
            $links = [];
            foreach (['spotify_url', 'apple_music_url', 'youtube_url'] as $key) {
                $url = tdc_url_field($cat, $key);
                if ($url !== '') $links[$key] = $url;
            }
            if (!$links) throw new TdcOrderError('Add at least one Spotify, Apple Music or YouTube link.');
            $clean += $links;
            break;
        case 'website':
            $clean['site_url'] = tdc_url_field($cat, 'site_url', true);
            $clean['promote_page'] = tdc_text_field($cat, 'promote_page', 200, true);
            break;
        case 'mobile_app':
            $clean['app_name'] = tdc_text_field($cat, 'app_name', 160, true);
            $appStore = tdc_url_field($cat, 'app_store_url');
            $playStore = tdc_url_field($cat, 'play_store_url');
            if ($appStore === '' && $playStore === '') throw new TdcOrderError('Add the App Store or Google Play link.');
            $clean['app_store_url'] = $appStore;
            $clean['play_store_url'] = $playStore;
            break;
        case 'business':
            $clean['business_name'] = tdc_text_field($cat, 'business_name', 160, true);
            $clean['location'] = tdc_text_field($cat, 'location', 200, true);
            $clean['service_focus'] = tdc_text_field($cat, 'service_focus', 500, true);
            break;
        case 'social_media':
            $clean['handles'] = tdc_text_field($cat, 'handles', 500, true);
            $clean['profile_links'] = tdc_url_list((string)($cat['profile_links'] ?? ''), 0, 10);
            break;
        case 'custom':
            $clean['brief'] = tdc_text_field($cat, 'brief', 4000, true);
            if (text_len($clean['brief']) < 20) throw new TdcOrderError('Describe your campaign brief in at least 20 characters.');
            break;
    }

    $campaign = [
        'name' => tdc_text_field($d, 'campaign_name', 160, true),
        'objective' => tdc_text_field($d, 'objective', 80),
        'audience' => tdc_text_field($d, 'audience', 500),
        'creative_url' => tdc_url_field($d, 'creative_url'),
    ];

    $mediaIds = tdc_media_ids($d['media_ids'] ?? []);
    $details = [
        'category' => $category,
        'category_label' => tdc_ad_categories()[$category],
        'category_fields' => $clean,
        'campaign' => $campaign,
        'platforms' => $quote['platforms'],
        'mode' => $quote['mode'],
        'daily_rate' => $quote['daily_rate'],
        'duration_days' => $quote['duration_days'],
        'estimated_reach' => $quote['estimated_reach'],
        'media_ids' => $mediaIds,
    ];
    $result = tdc_place_order((int)$u['id'], 'social-ads', 'Social ads — '.$campaign['name'], $details, (float)$quote['amount'], $mediaIds);
    json_out(['ok' => true, 'message' => 'Campaign launched.', 'quote' => $quote] + $result);
} catch (TdcOrderError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
}
