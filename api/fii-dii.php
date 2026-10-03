<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}

$url = MFAPIS_BASE_URL . '/market/fii_dii/latest';

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
    echo json_encode(['error' => 'unavailable', 'rows' => []]);
    exit;
}

$data = json_decode((string) $resp, true);
if (!is_array($data)) {
    echo json_encode(['error' => 'parse_error', 'rows' => []]);
    exit;
}

// Normalise envelope — API may return data[] or rows[] or a plain array
$rows = $data['data'] ?? $data['rows'] ?? $data['results'] ?? (isset($data[0]) ? $data : []);

echo json_encode(['rows' => array_values((array) $rows)]);
