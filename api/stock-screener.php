<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorised']));
}

header('Content-Type: application/json');

// ── Input sanitisation ────────────────────────────────────────────────────────

$allowed_sectors = ['Banking','IT','Pharma','Auto','FMCG','Energy','Metals','Realty','Infra','Chemicals'];
$allowed_sort    = ['market_cap', 'pe', 'pb', 'roe', 'promoter_pct'];

$sector       = trim(filter_input(INPUT_GET, 'sector',       FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$pe_max       = filter_input(INPUT_GET, 'pe_max',       FILTER_VALIDATE_FLOAT);
$pb_max       = filter_input(INPUT_GET, 'pb_max',       FILTER_VALIDATE_FLOAT);
$roe_min      = filter_input(INPUT_GET, 'roe_min',      FILTER_VALIDATE_FLOAT);
$promoter_min = filter_input(INPUT_GET, 'promoter_min', FILTER_VALIDATE_FLOAT);
$pledge_max   = filter_input(INPUT_GET, 'pledge_max',   FILTER_VALIDATE_FLOAT);
$mktcap_min   = filter_input(INPUT_GET, 'mktcap_min',   FILTER_VALIDATE_FLOAT);
$mktcap_max   = filter_input(INPUT_GET, 'mktcap_max',   FILTER_VALIDATE_FLOAT);
$sort_by      = trim(filter_input(INPUT_GET, 'sort_by',   FILTER_SANITIZE_SPECIAL_CHARS) ?? 'market_cap');
$direction    = trim(filter_input(INPUT_GET, 'direction',  FILTER_SANITIZE_SPECIAL_CHARS) ?? 'desc');
$limit        = (int) filter_input(INPUT_GET, 'limit',    FILTER_VALIDATE_INT);

// Whitelist / range guards
if (!in_array($sector,   $allowed_sectors, true)) $sector   = '';
if (!in_array($sort_by,  $allowed_sort,    true)) $sort_by  = 'market_cap';
if ($direction !== 'asc') $direction = 'desc';
if ($limit < 1 || $limit > 100) $limit = 50;

// ── Build mfapis query ────────────────────────────────────────────────────────

$params = [
    'sort_by'   => $sort_by,
    'direction' => $direction,
    'limit'     => $limit,
];

if ($sector       !== '')  $params['sector']       = $sector;
if ($pe_max       !== false && $pe_max       !== null) $params['pe_max']       = $pe_max;
if ($pb_max       !== false && $pb_max       !== null) $params['pb_max']       = $pb_max;
if ($roe_min      !== false && $roe_min      !== null) $params['roe_min']      = $roe_min;
if ($promoter_min !== false && $promoter_min !== null) $params['promoter_min'] = $promoter_min;
if ($pledge_max   !== false && $pledge_max   !== null) $params['pledge_max']   = $pledge_max;
if ($mktcap_min   !== false && $mktcap_min   !== null) $params['market_cap_min'] = $mktcap_min;  // in Cr
if ($mktcap_max   !== false && $mktcap_max   !== null) $params['market_cap_max'] = $mktcap_max;

$url = MFAPIS_BASE_URL . '/stocks/screener/equity?' . http_build_query($params);

// ── cURL call ─────────────────────────────────────────────────────────────────

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ['x-api-key: ' . MFAPIS_API_KEY, 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 12,
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr || !$resp || $httpCode >= 400) {
    error_log('stock-screener API error: HTTP ' . $httpCode . ' ' . $curlErr);
    http_response_code(502);
    echo json_encode(['stocks' => [], 'total' => 0, 'error' => 'data_unavailable']);
    exit;
}

$data = json_decode((string)$resp, true);
if (!is_array($data)) {
    echo json_encode(['stocks' => [], 'total' => 0]);
    exit;
}

// ── Unwrap envelope ───────────────────────────────────────────────────────────

$raw = $data['data'] ?? $data['stocks'] ?? $data['results'] ?? $data['equity'] ?? (isset($data[0]) ? $data : []);
if (!is_array($raw)) $raw = [];

// ── Normalise each record ─────────────────────────────────────────────────────

function nval(array $row, array $keys): mixed {
    foreach ($keys as $k) {
        if (isset($row[$k]) && $row[$k] !== null && $row[$k] !== '') {
            return $row[$k];
        }
    }
    return null;
}

function fval(array $row, array $keys): ?float {
    $v = nval($row, $keys);
    return ($v !== null) ? (float)$v : null;
}

$stocks = [];
foreach ($raw as $item) {
    if (!is_array($item)) continue;

    $symbol = strtoupper(trim((string)(nval($item, ['symbol','ticker','nse_symbol','bse_symbol','scrip_code']) ?? '')));
    $name   = (string)(nval($item, ['name','company_name','companyName','long_name','shortName','scrip_name']) ?? $symbol);
    $sector = (string)(nval($item, ['sector','industry','Sector']) ?? '');

    // Market cap — prefer a _cr field, otherwise convert from actual value
    $mktcap_cr = fval($item, ['market_cap_cr','marketCapCr','mktcap_cr','market_cap_in_cr']);
    if ($mktcap_cr === null) {
        $raw_mc = fval($item, ['market_cap','marketCap','mktCap','market_capitalisation']);
        if ($raw_mc !== null) {
            // If value looks like it's in actual ₹ (> 1e7), convert to Cr
            $mktcap_cr = $raw_mc > 1e7 ? round($raw_mc / 1e7, 2) : $raw_mc;
        }
    }

    $pe           = fval($item, ['pe','pe_ratio','peRatio','P/E','price_to_earnings']);
    $pb           = fval($item, ['pb','pb_ratio','pbRatio','P/B','price_to_book']);
    $roe          = fval($item, ['roe','roe_pct','ROE','return_on_equity']);
    $promoter_pct = fval($item, ['promoter_pct','promoter_holding','promoterHolding','promoter']);
    $pledge_pct   = fval($item, ['pledge_pct','pledged_pct','pledgePercent','pledge','pledged_shares_pct']);
    $close        = fval($item, ['close','ltp','lastPrice','last_price','current_price','price']);
    $change_pct   = fval($item, ['change_pct','changePct','pChange','percent_change','change_percent','day_change_pct']);

    $stocks[] = [
        'symbol'        => $symbol,
        'name'          => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
        'sector'        => htmlspecialchars($sector, ENT_QUOTES, 'UTF-8'),
        'market_cap_cr' => $mktcap_cr,
        'pe'            => $pe,
        'pb'            => $pb,
        'roe'           => $roe,
        'promoter_pct'  => $promoter_pct,
        'pledge_pct'    => $pledge_pct,
        'close'         => $close,
        'change_pct'    => $change_pct,
    ];
}

echo json_encode(['stocks' => $stocks, 'total' => count($stocks)]);
