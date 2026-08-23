<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

class Cashfree {

    private static function headers(): array {
        return [
            'Content-Type: application/json',
            'x-api-version: ' . CF_VERSION,
            'x-client-id: ' . CF_APP_ID,
            'x-client-secret: ' . CF_SECRET_KEY,
        ];
    }

    private static function request(string $method, string $path, array $data = []): array {
        $ch = curl_init(CF_BASE_URL . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => self::headers(),
            CURLOPT_SSL_VERIFYPEER => (CF_ENV === 'production'),
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        curl_setopt_array($ch, $opts);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Cashfree cURL error: ' . $err);
        }
        return ['http_code' => $httpCode, 'body' => json_decode($body, true) ?? []];
    }

    /**
     * Create a payment order.
     * Returns full Cashfree response array (includes payment_session_id, order_id, order_status).
     */
    public static function createOrder(
        string $orderId,
        float  $amount,
        string $customerName,
        string $customerEmail,
        string $customerPhone,
        string $returnUrl,
        string $notifyUrl = ''
    ): array {
        $payload = [
            'order_id'         => $orderId,
            'order_amount'     => round($amount, 2),
            'order_currency'   => 'INR',
            'customer_details' => [
                'customer_id'    => 'pf_' . md5($customerEmail),
                'customer_name'  => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => preg_replace('/\D/', '', $customerPhone) ?: '9999999999',
            ],
            'order_meta' => ['return_url' => $returnUrl],
        ];
        if ($notifyUrl) {
            $payload['order_meta']['notify_url'] = $notifyUrl;
        }

        $res = self::request('POST', '/orders', $payload);
        if ($res['http_code'] !== 200) {
            $msg = $res['body']['message'] ?? ('HTTP ' . $res['http_code']);
            error_log('Cashfree createOrder error: ' . json_encode($res['body']));
            throw new RuntimeException($msg);
        }
        return $res['body'];
    }

    /** Fetch order details (includes order_status: ACTIVE | PAID | EXPIRED). */
    public static function getOrder(string $orderId): array {
        $res = self::request('GET', '/orders/' . $orderId);
        if ($res['http_code'] !== 200) {
            throw new RuntimeException('Could not fetch order: HTTP ' . $res['http_code']);
        }
        return $res['body'];
    }

    /** Fetch payment records for an order. */
    public static function getOrderPayments(string $orderId): array {
        $res = self::request('GET', '/orders/' . $orderId . '/payments');
        return is_array($res['body']) ? $res['body'] : [];
    }

    /**
     * Verify Cashfree webhook signature.
     * Cashfree signs: base64(HMAC-SHA256(timestamp + rawBody, secretKey)) — no separator.
     * Tries both with and without "." separator for cross-version compatibility.
     */
    public static function verifyWebhook(string $rawBody, string $timestamp, string $signature): bool {
        $sig_no_sep  = base64_encode(hash_hmac('sha256', $timestamp . $rawBody,       CF_SECRET_KEY, true));
        $sig_dot_sep = base64_encode(hash_hmac('sha256', $timestamp . '.' . $rawBody, CF_SECRET_KEY, true));
        return hash_equals($sig_no_sep, $signature) || hash_equals($sig_dot_sep, $signature);
    }
}
