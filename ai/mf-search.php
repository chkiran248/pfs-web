<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo '[]';
    exit;
}

$q = trim(filter_input(INPUT_GET, 'q', FILTER_DEFAULT) ?? '');
if (mb_strlen($q) < 3) {
    echo '[]';
    exit;
}

$url = 'https://api.mfapi.in/mf/search?q=' . urlencode($q);
$ctx = stream_context_create(['http' => [
    'timeout'       => 6,
    'user_agent'    => 'PrimeFin-Portal/1.0',
    'ignore_errors' => true,
]]);

$resp = @file_get_contents($url, false, $ctx);
if (!$resp) {
    echo '[]';
    exit;
}

$data = json_decode($resp, true);
if (!is_array($data)) {
    echo '[]';
    exit;
}

$results = array_slice(array_map(fn($item) => [
    'schemeCode' => (string)($item['schemeCode'] ?? ''),
    'schemeName' => (string)($item['schemeName'] ?? ''),
    'fundHouse'  => (string)($item['fundHouse']  ?? ''),
], $data), 0, 12);

echo json_encode($results, JSON_UNESCAPED_UNICODE);
