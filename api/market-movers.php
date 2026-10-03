<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
if (!is_logged_in()) { http_response_code(401); exit(json_encode(['error' => 'Unauthorised'])); }
header('Content-Type: application/json');

$type = trim($_GET['type'] ?? 'gainers');
if (!in_array($type, ['gainers', 'losers', 'volume'], true)) {
    $type = 'gainers';
}

$url = MFAPIS_BASE_URL . '/stocks/screener/movers?' . http_build_query(['type' => $type]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ['x-api-key: ' . MFAPIS_API_KEY, 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 10,
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$resp || $httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['movers' => [], 'error' => 'data_unavailable']);
    exit;
}

$data = json_decode((string)$resp, true);
if (!is_array($data)) {
    echo json_encode(['movers' => []]);
    exit;
}

// Normalise response envelope
$movers = $data['data'] ?? $data['movers'] ?? $data['results'] ?? (isset($data[0]) ? $data : []);

echo json_encode(['movers' => array_values((array)$movers)]);
