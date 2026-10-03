<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorised']));
}

header('Content-Type: application/json');

$name  = trim($_GET['name']  ?? '');
$house = trim($_GET['house'] ?? '');

if (!$name) {
    echo json_encode(['error' => 'name required']);
    exit;
}

// Build search query — append fund house if provided
$query = $house ? "$name $house" : $name;

// ── Step 1: search for scheme by name ────────────────────
$url = MFAPIS_BASE_URL . '/scheme?' . http_build_query(['search' => $query, 'limit' => 5]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ['x-api-key: ' . MFAPIS_API_KEY, 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 10,
]);
$resp = curl_exec($ch);
curl_close($ch);

$data = json_decode((string)$resp, true);

if (!is_array($data) || empty($data)) {
    echo json_encode(['error' => 'not_found']);
    exit;
}

// Pick best match — first result; unwrap common envelope shapes
$scheme = null;
if (is_array($data[0] ?? null)) {
    $scheme = $data[0];
} elseif (is_array($data['data'][0] ?? null)) {
    $scheme = $data['data'][0];
}

if (!$scheme) {
    echo json_encode(['error' => 'not_found']);
    exit;
}

// Extract scheme_id — try common field names across mfapis versions
$scheme_id = $scheme['scheme_code']
    ?? $scheme['amfi_code']
    ?? $scheme['id']
    ?? $scheme['schemeCode']
    ?? null;

if (!$scheme_id) {
    echo json_encode(['error' => 'no_id']);
    exit;
}

// ── Step 2: fetch scheme data (returns + expense ratio) ──
$url2 = MFAPIS_BASE_URL . '/scheme/mf_data/' . rawurlencode((string)$scheme_id);

$ch2 = curl_init($url2);
curl_setopt_array($ch2, [
    CURLOPT_HTTPHEADER     => ['x-api-key: ' . MFAPIS_API_KEY, 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 10,
]);
$resp2 = curl_exec($ch2);
curl_close($ch2);

$mf = json_decode((string)$resp2, true);

// Unwrap envelope if present
if (isset($mf['data']) && is_array($mf['data'])) {
    $mf = $mf['data'];
}

if (!is_array($mf)) {
    echo json_encode(['error' => 'bad_response']);
    exit;
}

// Return normalised fields — try multiple key aliases across API versions
echo json_encode([
    'return_1yr'    => $mf['return_1yr']    ?? $mf['returns_1yr']    ?? $mf['cagr_1yr']    ?? null,
    'return_3yr'    => $mf['return_3yr']    ?? $mf['returns_3yr']    ?? $mf['cagr_3yr']    ?? null,
    'expense_ratio' => $mf['expense_ratio'] ?? $mf['expenseRatio']   ?? $mf['ter']         ?? null,
    'scheme_id'     => $scheme_id,
]);
