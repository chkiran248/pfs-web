<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
if (!is_logged_in()) { http_response_code(401); exit(json_encode(['error' => 'Unauthorised'])); }
header('Content-Type: application/json');

$search   = trim($_GET['q']        ?? '');
$category = trim($_GET['category'] ?? '');
$amc      = trim($_GET['amc']      ?? '');
$option   = trim($_GET['option']   ?? '');  // direct | regular
$sort_by  = trim($_GET['sort']     ?? 'return_1yr');
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = 18;

// Build mfapis /scheme query params
$params = ['limit' => $limit, 'page' => $page];
if ($search)   $params['search']       = $search;
if ($category) $params['category']     = $category;
if ($amc)      $params['amc']          = $amc;
if ($option)   $params['option']       = $option;
if ($sort_by)  $params['sort_by']      = $sort_by;
$params['direction'] = 'desc';

$url = MFAPIS_BASE_URL . '/scheme?' . http_build_query($params);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ['x-api-key: ' . MFAPIS_API_KEY, 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 12,
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$resp || $httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['error' => 'data_unavailable', 'funds' => [], 'total' => 0]);
    exit;
}

$data = json_decode((string)$resp, true);
if (!is_array($data)) {
    echo json_encode(['error' => 'parse_error', 'funds' => [], 'total' => 0]);
    exit;
}

// Normalise response — mfapis may return list or envelope
$funds = [];
$total = 0;
if (isset($data['data']) && is_array($data['data'])) {
    $funds = $data['data'];
    $total = (int)($data['total'] ?? $data['count'] ?? count($funds));
} elseif (isset($data[0])) {
    $funds = $data;
    $total = count($funds);
} elseif (isset($data['funds']) && is_array($data['funds'])) {
    $funds = $data['funds'];
    $total = (int)($data['total'] ?? count($funds));
}

// Normalise each fund record to a consistent shape for the front-end
$out = [];
foreach ($funds as $f) {
    if (!is_array($f)) continue;
    $out[] = [
        'id'            => $f['scheme_code'] ?? $f['amfi_code'] ?? $f['id'] ?? $f['schemeCode'] ?? null,
        'name'          => $f['scheme_name'] ?? $f['fund_name'] ?? $f['name'] ?? '',
        'amc'           => $f['amc'] ?? $f['fund_house'] ?? $f['AMCName'] ?? '',
        'category'      => $f['category'] ?? $f['scheme_category'] ?? '',
        'sub_category'  => $f['sub_category'] ?? $f['schemeSubCategory'] ?? '',
        'option'        => $f['option'] ?? $f['plan'] ?? '',
        'nav'           => isset($f['nav'])           ? (float)$f['nav']           : null,
        'return_1yr'    => isset($f['return_1yr'])    ? (float)$f['return_1yr']    : (isset($f['returns_1yr'])    ? (float)$f['returns_1yr']    : null),
        'return_3yr'    => isset($f['return_3yr'])    ? (float)$f['return_3yr']    : (isset($f['returns_3yr'])    ? (float)$f['returns_3yr']    : null),
        'return_5yr'    => isset($f['return_5yr'])    ? (float)$f['return_5yr']    : (isset($f['returns_5yr'])    ? (float)$f['returns_5yr']    : null),
        'expense_ratio' => isset($f['expense_ratio']) ? (float)$f['expense_ratio'] : (isset($f['expenseRatio'])  ? (float)$f['expenseRatio']   : null),
        'aum_cr'        => isset($f['aum'])           ? (float)$f['aum']           : (isset($f['aum_cr'])        ? (float)$f['aum_cr']         : null),
        'risk'          => $f['risk_level'] ?? $f['riskometer'] ?? $f['risk'] ?? '',
    ];
}

echo json_encode(['funds' => $out, 'total' => $total, 'page' => $page, 'limit' => $limit]);
