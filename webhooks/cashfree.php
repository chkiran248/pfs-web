<?php
/**
 * Cashfree Webhook Handler
 * URL to register in Cashfree Dashboard → Developers → Webhooks:
 *   https://primefin.in/webhooks/cashfree.php
 *
 * Events to subscribe: PAYMENT_SUCCESS_WEBHOOK, PAYMENT_FAILED_WEBHOOK, USER_DROPPED_WEBHOOK
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/cashfree.php';
require_once __DIR__ . '/../includes/subscription.php';

// No session needed — webhook is server-to-server
header('Content-Type: application/json');

$rawBody   = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

// Verify signature
if (!Cashfree::verifyWebhook($rawBody, $timestamp, $signature)) {
    http_response_code(401);
    error_log('Cashfree webhook: invalid signature');
    echo json_encode(['error' => 'invalid signature']);
    exit;
}

$event = json_decode($rawBody, true);
if (!$event) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid JSON']);
    exit;
}

$type     = $event['type'] ?? '';
// Support both 2023-08-01 (PAYMENT_SUCCESS_WEBHOOK) and 2026-01-01 (payment.success) formats
$order_id   = $event['data']['order']['order_id']         ?? ($event['data']['payment']['order_id'] ?? '');
$cf_pay_id  = $event['data']['payment']['cf_payment_id']  ?? '';
$pay_status = $event['data']['payment']['payment_status'] ?? '';

error_log("Cashfree webhook: type={$type} order_id={$order_id} status={$pay_status}");

if (!$order_id) {
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'reason' => 'no order_id']);
    exit;
}

$db = get_db();

// Look up the payment record
$stmt = $db->prepare("SELECT id, user_id, billing_cycle, status FROM payments WHERE cashfree_order_id = :oid LIMIT 1");
$stmt->execute([':oid' => $order_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    // Unknown order — not from this system
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'reason' => 'order not found']);
    exit;
}

// Idempotency: already processed
if ($payment['status'] === 'paid') {
    http_response_code(200);
    echo json_encode(['status' => 'already_processed']);
    exit;
}

// Match both 2023-08-01 style (PAYMENT_SUCCESS_WEBHOOK) and 2026-01-01 style (payment.success)
$is_success  = in_array($type, ['PAYMENT_SUCCESS_WEBHOOK', 'payment.success'])     || $pay_status === 'SUCCESS';
$is_failed   = in_array($type, ['PAYMENT_FAILED_WEBHOOK',  'payment.failed'])       || $pay_status === 'FAILED';
$is_dropped  = in_array($type, ['USER_DROPPED_WEBHOOK',    'payment.user_dropped']) || $pay_status === 'USER_DROPPED';

if ($is_success) {
    // Update payment record
    $pay_method = $event['data']['payment']['payment_group'] ?? '';
    $db->prepare("UPDATE payments SET cashfree_payment_id = :pid, payment_method = :pm WHERE cashfree_order_id = :oid")
       ->execute([':pid' => $cf_pay_id, ':pm' => $pay_method, ':oid' => $order_id]);

    // Activate subscription
    $activated = activate_paid_subscription((int) $payment['user_id'], $order_id, $payment['billing_cycle']);
    if (!$activated) {
        error_log("Cashfree webhook: activate_paid_subscription failed for order {$order_id}");
        http_response_code(500);
        echo json_encode(['error' => 'subscription activation failed']);
        exit;
    }

    http_response_code(200);
    echo json_encode(['status' => 'activated']);
    exit;
}

if ($is_failed) {
    $db->prepare("UPDATE payments SET status = 'failed' WHERE cashfree_order_id = :oid")
       ->execute([':oid' => $order_id]);
    http_response_code(200);
    echo json_encode(['status' => 'marked_failed']);
    exit;
}

if ($is_dropped) {
    // User closed the payment page or abandoned mid-flow
    $db->prepare("UPDATE payments SET status = 'user_dropped' WHERE cashfree_order_id = :oid")
       ->execute([':oid' => $order_id]);
    error_log("Cashfree webhook: user dropped order {$order_id}");
    http_response_code(200);
    echo json_encode(['status' => 'marked_dropped']);
    exit;
}

// Any other event — acknowledge
http_response_code(200);
echo json_encode(['status' => 'ignored', 'type' => $type]);
