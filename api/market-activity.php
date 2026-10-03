<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorised']));
}

header('Content-Type: application/json');

$url = MFAPIS_BASE_URL . '/market/activity/latest';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . MFAPIS_API_KEY,
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 10,
]);
$resp     = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false || $httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['error' => 'unavailable', 'activity' => null]);
    exit;
}

$data = json_decode((string) $resp, true);
if (!is_array($data)) {
    echo json_encode(['error' => 'parse_error', 'activity' => null]);
    exit;
}

// Normalise envelope
$raw = $data['data'] ?? $data['activity'] ?? $data['breadth'] ?? $data;

if (!is_array($raw)) {
    echo json_encode(['error' => 'unknown_format', 'activity' => null]);
    exit;
}

$advances  = (int) ($raw['advances']  ?? $raw['advance']  ?? $raw['advancing']  ?? 0);
$declines  = (int) ($raw['declines']  ?? $raw['decline']  ?? $raw['declining']  ?? 0);
$unchanged = (int) ($raw['unchanged'] ?? $raw['flat']     ?? $raw['neutral']    ?? 0);
$total     = $advances + $declines + $unchanged;
if ($total === 0) $total = 1; // avoid division by zero

$ratio = $declines > 0
    ? round($advances / $declines, 2)
    : ($advances > 0 ? 99.99 : 0.0);

echo json_encode([
    'activity' => [
        'advances'             => $advances,
        'declines'             => $declines,
        'unchanged'            => $unchanged,
        'total'                => $total,
        'advance_decline_ratio' => $ratio,
    ],
]);
