<?php
require_once __DIR__.'/bootstrap.php';

/**
 * Raised by the service order pipeline with an HTTP status the API can return.
 */
class TdcOrderError extends RuntimeException
{
    public int $status;
    public function __construct(string $message, int $status = 422)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function tdc_service(string $slug): array
{
    $q = db()->prepare('SELECT id,slug,name,price,pricing_mode,active FROM services WHERE slug=?');
    $q->execute([$slug]);
    $service = $q->fetch();
    if (!$service || !(int)$service['active']) throw new TdcOrderError('This service is currently unavailable.', 404);
    return $service;
}

function tdc_service_for_page(string $slug, string $page): array
{
    $service = tdc_service($slug);
    $q = db()->prepare('SELECT 1 FROM service_page_services WHERE page_slug=? AND service_id=?');
    $q->execute([$page, $service['id']]);
    if (!$q->fetchColumn()) throw new TdcOrderError('This service is not part of this page.', 404);
    return $service;
}

function tdc_user_balance(int $userId): float
{
    $q = db()->prepare("SELECT COALESCE(SUM(CASE WHEN status='posted' THEN amount ELSE 0 END),0) FROM wallet_ledger WHERE user_id=?");
    $q->execute([$userId]);
    return (float)$q->fetchColumn();
}

function tdc_text_field(array $data, string $key, int $max = 500, bool $required = false): string
{
    $value = trim((string)($data[$key] ?? ''));
    if ($required && $value === '') throw new TdcOrderError('Please complete every required field.');
    if (text_len($value) > $max) throw new TdcOrderError(ucfirst(str_replace('_', ' ', $key)).' is too long.');
    return $value;
}

function tdc_url_field(array $data, string $key, bool $required = false): string
{
    $value = tdc_text_field($data, $key, 500, $required);
    if ($value !== '' && !preg_match('#^https?://[^\s]+$#i', $value)) throw new TdcOrderError(ucfirst(str_replace('_', ' ', $key)).' must be a full http(s) URL.');
    return $value;
}

function tdc_url_list(string $value, int $min = 0, int $max = 20): array
{
    $parts = preg_split('/[\r\n,]+/', $value) ?: [];
    $urls = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (!preg_match('#^https?://[^\s]+$#i', $part)) throw new TdcOrderError('Every link must start with http:// or https://');
        $urls[] = $part;
    }
    $urls = array_values(array_unique($urls));
    if (count($urls) < $min) throw new TdcOrderError('Add at least '.$min.' valid links.');
    if (count($urls) > $max) throw new TdcOrderError('Add no more than '.$max.' links.');
    return $urls;
}

function tdc_media_ids(array $raw): array
{
    if (!is_array($raw)) return [];
    $ids = [];
    foreach (array_slice($raw, 0, 20) as $id) {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) throw new TdcOrderError('Invalid attachment selection.');
        $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

/**
 * Create a paid order: lock the customer row, re-check the ledger, insert the
 * order, attach owned media, then post a single debit entry. Never allows a
 * negative balance. Returns the new order id and the post-debit balance.
 */
function tdc_place_order(int $userId, string $slug, string $description, array $details, float $amount, array $mediaIds = []): array
{
    $service = tdc_service($slug);
    $amount = round($amount, 2);
    if ($amount < 0 || $amount > 100000) throw new TdcOrderError('Invalid order amount.');

    $description = text_cut(trim($description), 0, 255);
    if ($description === '') $description = $service['name'];
    $encoded = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false || strlen($encoded) > 20000) throw new TdcOrderError('Order details are too long.');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
        $q->execute([$userId]);

        $files = [];
        if ($mediaIds) {
            $marks = implode(',', array_fill(0, count($mediaIds), '?'));
            $q = $pdo->prepare("SELECT id FROM customer_media WHERE id IN ($marks) AND user_id=? AND order_id IS NULL FOR UPDATE");
            $q->execute([...$mediaIds, $userId]);
            $files = $q->fetchAll(PDO::FETCH_COLUMN);
            if (count($files) !== count($mediaIds)) throw new TdcOrderError('One or more attachments are unavailable. Upload them again.');
        }

        $q = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status='posted' THEN amount ELSE 0 END),0) FROM wallet_ledger WHERE user_id=?");
        $q->execute([$userId]);
        $balance = (float)$q->fetchColumn();
        if ($balance < $amount) throw new TdcOrderError('Insufficient wallet balance. Your balance is €'.number_format($balance, 2).'. Top up and try again.', 402);

        $q = $pdo->prepare("INSERT INTO orders(user_id,service_id,description,details,amount,status,delivery_stage,created_at) VALUES(?,?,?,?,?,'pending','in_review',UTC_TIMESTAMP())");
        $q->execute([$userId, $service['id'], $description, $encoded, $amount]);
        $orderId = (int)$pdo->lastInsertId();

        if ($mediaIds) {
            $q = $pdo->prepare('UPDATE customer_media SET order_id=? WHERE id=? AND user_id=? AND order_id IS NULL');
            foreach ($mediaIds as $id) $q->execute([$orderId, $id, $userId]);
        }

        $balanceAfter = $balance - $amount;
        if ($amount > 0) {
            $q = $pdo->prepare("INSERT INTO wallet_ledger(user_id,amount,kind,status,reference,note,created_at) VALUES(?,?,'debit','posted',?,?,UTC_TIMESTAMP())");
            $q->execute([$userId, -$amount, 'order:'.$orderId, $description]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    audit('order.created', $userId, ['order_id' => $orderId, 'service' => $slug, 'amount' => $amount]);

    try {
        require_once __DIR__.'/documents.php';
        [$invoiceOrder, $invoiceLines] = tdc_invoice($orderId);
        queue_document_email($userId, 'Invoice INV-'.$orderId, $invoiceLines, 'INV-'.$orderId.'.pdf');
    } catch (Throwable $e) {
        error_log('Invoice queue failed for order #'.$orderId.': '.$e->getMessage());
    }

    return ['order_id' => $orderId, 'amount' => $amount, 'balance_after' => round($balanceAfter, 2)];
}

function tdc_order_refunded(int $orderId): float
{
    $q = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_ledger WHERE kind='refund' AND status='posted' AND reference=?");
    $q->execute(['order:'.$orderId]);
    return (float)$q->fetchColumn();
}

function tdc_orders_for_page(int $userId, string $page): array
{
    $q = db()->prepare('SELECT o.id,o.service_id,s.slug service_slug,s.name service_name,o.description,o.details,o.amount,o.status,o.delivery_stage,o.created_at,o.updated_at
        FROM orders o
        JOIN services s ON s.id=o.service_id
        JOIN service_page_services ps ON ps.service_id=s.id AND ps.page_slug=?
        WHERE o.user_id=? ORDER BY o.id DESC LIMIT 200');
    $q->execute([$page, $userId]);
    $orders = $q->fetchAll();
    if (!$orders) return [];
    $media = db()->prepare('SELECT id,original_name,mime,bytes,kind FROM customer_media WHERE order_id=?');
    foreach ($orders as &$order) {
        $decoded = json_decode((string)$order['details'], true);
        $order['details'] = is_array($decoded) ? $decoded : [];
        $order['amount'] = (float)$order['amount'];
        $order['refunded'] = tdc_order_refunded((int)$order['id']);
        $media->execute([$order['id']]);
        $order['files'] = $media->fetchAll();
    }
    unset($order);
    return $orders;
}

function tdc_order_totals(array $orders): array
{
    $count = count($orders);
    $spend = 0.0;
    $active = 0;
    foreach ($orders as $order) {
        $spend += max(0, (float)$order['amount'] - (float)($order['refunded'] ?? 0));
        if (in_array($order['status'], ['pending', 'processing'], true)) $active++;
    }
    return ['count' => $count, 'spend' => round($spend, 2), 'active' => $active];
}
