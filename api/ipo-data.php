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

$type = trim($_GET['type'] ?? 'upcoming');
$allowed_types = ['upcoming', 'recent', 'listed'];
if (!in_array($type, $allowed_types, true)) {
    $type = 'upcoming';
}

// Map tab type to endpoint path
$endpoint_map = [
    'upcoming' => '/ipo/upcoming',
    'recent'   => '/ipo/recent',
    'listed'   => '/ipo/listed',
];
$endpoint = $endpoint_map[$type];

$url = MFAPIS_BASE_URL . $endpoint;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . MFAPIS_API_KEY,
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 12,
]);
$resp     = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// Paid-tier gate
if ($httpCode === 402 || $httpCode === 403) {
    echo json_encode(['error' => 'subscription_required', 'ipos' => []]);
    exit;
}

if ($resp === false || $curlErr !== '' || $httpCode < 200 || $httpCode >= 300) {
    error_log('IPO API error: HTTP ' . $httpCode . ' ' . $curlErr);
    http_response_code(502);
    echo json_encode(['error' => 'unavailable', 'ipos' => []]);
    exit;
}

$data = json_decode((string) $resp, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['error' => 'unavailable', 'ipos' => []]);
    exit;
}

// Unwrap common API envelope shapes
$raw = $data['data']    ??
       $data['ipos']    ??
       $data['results'] ??
       $data['items']   ??
       (isset($data[0]) ? $data : []);

if (!is_array($raw)) {
    $raw = [];
}

// ── Normalise each IPO record ─────────────────────────────────────────────
function pick(array $row, array $keys, mixed $default = null): mixed
{
    foreach ($keys as $k) {
        if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) {
            return $row[$k];
        }
    }
    return $default;
}

function normalise_date(mixed $val): ?string
{
    if (!$val) return null;
    $ts = is_numeric($val) ? (int) $val : strtotime((string) $val);
    return $ts ? date('Y-m-d', $ts) : null;
}

function normalise_float(mixed $val): ?float
{
    if ($val === null || $val === '') return null;
    $cleaned = preg_replace('/[^\d.\-]/', '', (string) $val);
    return is_numeric($cleaned) ? (float) $cleaned : null;
}

$ipos = [];
foreach ($raw as $row) {
    if (!is_array($row)) continue;

    // Company name
    $company = (string) pick($row, ['company', 'company_name', 'name', 'issuerName', 'issuer_name', 'companyName'], '');
    if ($company === '') continue; // skip malformed rows

    // Ticker / symbol
    $symbol = (string) pick($row, ['symbol', 'ticker', 'nse_symbol', 'bse_symbol', 'scrip_code', 'isinCode'], '');

    // Dates
    $open_date    = normalise_date(pick($row, ['open_date', 'openDate', 'open', 'subscription_start', 'startDate', 'issue_open_date', 'bidding_start_date']));
    $close_date   = normalise_date(pick($row, ['close_date', 'closeDate', 'close', 'subscription_end', 'endDate', 'issue_close_date', 'bidding_end_date']));
    $listing_date = normalise_date(pick($row, ['listing_date', 'listingDate', 'listing', 'listed_on', 'listDate']));

    // Price band — accept string as-is or build from min/max
    $pb_raw  = pick($row, ['price_band', 'priceBand', 'price_range', 'priceRange', 'issue_price_range']);
    if ($pb_raw) {
        $price_band = (string) $pb_raw;
        // Ensure ₹ prefix
        if (!str_contains($price_band, '₹') && !str_contains($price_band, 'Rs')) {
            $price_band = '₹' . $price_band;
        }
    } else {
        $min = normalise_float(pick($row, ['price_min', 'minPrice', 'floor_price', 'floorPrice']));
        $max = normalise_float(pick($row, ['price_max', 'maxPrice', 'cap_price', 'capPrice', 'cutoff_price']));
        if ($min && $max) {
            $price_band = '₹' . number_format($min) . '–' . number_format($max);
        } elseif ($max) {
            $price_band = '₹' . number_format($max);
        } else {
            $price_band = null;
        }
    }

    // Lot size
    $lot_size = (int)(pick($row, ['lot_size', 'lotSize', 'min_lot', 'minLot', 'shares_per_lot'], 0));

    // Issue size in crores
    $issue_raw  = normalise_float(pick($row, ['issue_size_cr', 'issueSizeCr', 'issue_size', 'issueSize', 'total_issue_size']));
    $issue_size_cr = $issue_raw;
    // Some APIs give it in absolute rupees — convert if > 10 lakh crore (implausibly big)
    if ($issue_size_cr && $issue_size_cr > 10000000) {
        $issue_size_cr = round($issue_size_cr / 10000000, 2); // paise → crore unlikely, but guard
    }

    // Subscription (times / x)
    $sub_raw = normalise_float(pick($row, ['subscription_x', 'subscriptionX', 'subscription', 'times_subscribed', 'timesSubscribed', 'overall_subscription']));

    // GMP (grey market premium in rupees)
    $gmp_raw = normalise_float(pick($row, ['gmp', 'grey_market_premium', 'greyMarketPremium', 'kostak', 'subject_to_sauda']));

    // Listing price and gain (for listed tab)
    $listing_price     = normalise_float(pick($row, ['listing_price', 'listingPrice', 'listed_price', 'listing_open']));
    $listing_gain_pct  = normalise_float(pick($row, ['listing_gain_pct', 'listingGainPct', 'listing_gain', 'listing_return', 'listingReturn', 'gain_percent', 'gainPercent']));

    // If gain not given but we have issue price + listing price, calculate it
    if ($listing_gain_pct === null && $listing_price) {
        $issue_price = normalise_float(pick($row, ['issue_price', 'issuePrice', 'cutoff', 'cutoff_price', 'upper_band']));
        if ($issue_price && $issue_price > 0) {
            $listing_gain_pct = round((($listing_price - $issue_price) / $issue_price) * 100, 2);
        }
    }

    // Status
    $status_raw = strtolower((string) pick($row, ['status', 'ipo_status', 'ipoStatus', 'state'], ''));
    // Normalise status to our 4 values
    if (str_contains($status_raw, 'upcoming') || str_contains($status_raw, 'forthcoming') || str_contains($status_raw, 'announced')) {
        $status = 'upcoming';
    } elseif (str_contains($status_raw, 'open') || str_contains($status_raw, 'live') || str_contains($status_raw, 'ongoing')) {
        $status = 'open';
    } elseif (str_contains($status_raw, 'allot') || str_contains($status_raw, 'subscri') || str_contains($status_raw, 'closed')) {
        $status = 'allotment';
    } elseif (str_contains($status_raw, 'list') || str_contains($status_raw, 'traded')) {
        $status = 'listed';
    } else {
        // Derive from dates if status field missing
        $now = date('Y-m-d');
        if ($listing_date && $listing_date <= $now) {
            $status = 'listed';
        } elseif ($close_date && $close_date < $now) {
            $status = 'allotment';
        } elseif ($open_date && $open_date <= $now) {
            $status = 'open';
        } else {
            $status = 'upcoming';
        }
    }

    $ipos[] = [
        'company'          => $company,
        'symbol'           => $symbol,
        'open_date'        => $open_date,
        'close_date'       => $close_date,
        'listing_date'     => $listing_date,
        'price_band'       => $price_band,
        'lot_size'         => $lot_size ?: null,
        'issue_size_cr'    => $issue_size_cr,
        'subscription_x'   => $sub_raw,
        'gmp'              => $gmp_raw,
        'listing_price'    => $listing_price,
        'listing_gain_pct' => $listing_gain_pct,
        'status'           => $status,
    ];
}

echo json_encode(['ipos' => $ipos, 'count' => count($ipos), 'type' => $type]);
