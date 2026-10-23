<?php
require_once dirname(__DIR__,2).'/app/service_orders.php';
$u = require_user();

$botTypes = [
    'telegram' => 'Telegram Bot',
    'scraper' => 'Web Scraper',
    'dm' => 'DM Auto-responder',
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $orders = tdc_orders_for_page((int)$u['id'], 'bots');
    $service = tdc_service('bots');
    json_out([
        'ok' => true,
        'price' => (float)$service['price'],
        'bot_types' => $botTypes,
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
    $type = (string)($d['bot_type'] ?? '');
    if (!isset($botTypes[$type])) throw new TdcOrderError('Choose a bot type.');
    $f = $d['fields'] ?? [];
    if (!is_array($f)) $f = [];
    $name = tdc_text_field($f, 'bot_name', 120, true);
    if (text_len($name) < 2) throw new TdcOrderError('Enter a bot name.');
    $token = tdc_text_field($f, 'api_token', 300);
    $configuration = tdc_text_field($f, 'configuration', 4000, true);
    if (text_len($configuration) < 20) throw new TdcOrderError('Describe what the bot should do in at least 20 characters.');
    $target = tdc_text_field($f, 'target', 300);
    $schedule = tdc_text_field($f, 'schedule', 120);

    $service = tdc_service_for_page('bots', 'bots');
    $mediaIds = tdc_media_ids($d['media_ids'] ?? []);
    $details = [
        'bot_type' => $type,
        'bot_type_label' => $botTypes[$type],
        'fields' => [
            'bot_name' => $name,
            'api_token_set' => $token !== '',
            'api_token' => $token,
            'configuration' => $configuration,
            'target' => $target,
            'schedule' => $schedule,
        ],
        'media_ids' => $mediaIds,
    ];
    $result = tdc_place_order((int)$u['id'], 'bots', $botTypes[$type].' — '.$name, $details, (float)$service['price'], $mediaIds);
    json_out(['ok' => true, 'message' => 'Bot deployment requested.'] + $result);
} catch (TdcOrderError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
}
