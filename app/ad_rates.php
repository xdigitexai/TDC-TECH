<?php
require_once __DIR__.'/service_orders.php';

function tdc_ad_categories(): array
{
    return [
        'music' => 'Music',
        'website' => 'Website',
        'mobile_app' => 'Mobile App',
        'business' => 'Business',
        'social_media' => 'Social Media',
        'custom' => 'Custom',
    ];
}

function tdc_ad_placements(): array
{
    return [
        'meta' => 'Meta / Facebook',
        'youtube' => 'YouTube',
        'tiktok' => 'TikTok',
        'linkedin' => 'LinkedIn',
        'x' => 'X / Twitter',
        'google' => 'Google Search',
    ];
}

function tdc_ad_rates(bool $activeOnly = true): array
{
    $sql = 'SELECT id,category,placement,price_per_day,reach_per_eur,active FROM ad_rates';
    if ($activeOnly) $sql .= ' WHERE active=1';
    $sql .= ' ORDER BY category,placement';
    $rates = [];
    foreach (db()->query($sql) as $row) {
        $row['price_per_day'] = (float)$row['price_per_day'];
        $row['reach_per_eur'] = (int)$row['reach_per_eur'];
        $rates[] = $row;
    }
    return $rates;
}

/**
 * Server-side ad quote. The client may suggest a duration or a budget, but the
 * rate card and the charged total are always derived here.
 */
function tdc_ad_quote(array $in): array
{
    $categories = tdc_ad_categories();
    $placements = tdc_ad_placements();
    $category = (string)($in['category'] ?? '');
    if (!isset($categories[$category])) throw new TdcOrderError('Choose a business type first.');

    $selected = $in['platforms'] ?? [];
    if (!is_array($selected)) $selected = [];
    $selected = array_values(array_intersect(array_keys($placements), array_map('strval', $selected)));
    if (!$selected) throw new TdcOrderError('Select at least one advertising platform.');

    $rates = [];
    foreach (tdc_ad_rates() as $row) {
        if ($row['category'] === $category) $rates[$row['placement']] = $row;
    }
    if (!$rates) throw new TdcOrderError('This category has no ad rates yet. Contact support.');
    foreach ($selected as $placement) {
        if (!isset($rates[$placement])) throw new TdcOrderError('Selected platform is not available for this category.');
    }

    $daily = 0.0;
    $reachPerEur = 0.0;
    foreach ($selected as $placement) {
        $daily += $rates[$placement]['price_per_day'];
        $reachPerEur += $rates[$placement]['reach_per_eur'];
    }
    $daily = round($daily, 2);
    $reachPerEur = $reachPerEur / max(1, count($selected));
    if ($daily <= 0) throw new TdcOrderError('The ad rate card is not configured. Contact support.');

    $mode = (string)($in['mode'] ?? 'duration');
    if ($mode === 'budget') {
        $budget = round((float)($in['budget'] ?? 0), 2);
        if ($budget < $daily || $budget > 100000) throw new TdcOrderError('Budget must be at least €'.number_format($daily, 2).' (one day) and at most €100,000.');
        $days = (int)floor($budget / $daily);
        $total = $budget;
    } else {
        $days = (int)($in['duration_days'] ?? 0);
        if ($days < 1 || $days > 90) throw new TdcOrderError('Duration must be between 1 and 90 days.');
        $total = round($daily * $days, 2);
    }
    if ($total <= 0 || $total > 100000) throw new TdcOrderError('Invalid campaign amount.');

    return [
        'category' => $category,
        'platforms' => $selected,
        'mode' => $mode === 'budget' ? 'budget' : 'duration',
        'daily_rate' => $daily,
        'duration_days' => $days,
        'amount' => round($total, 2),
        'estimated_reach' => (int)round($total * $reachPerEur),
        'currency' => 'EUR',
    ];
}
