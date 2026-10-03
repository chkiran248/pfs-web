<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
if (!is_logged_in()) { http_response_code(401); exit(json_encode(['error'=>'Unauthorised'])); }
header('Content-Type: application/json');

$raw     = trim($_GET['tickers'] ?? '');
$tickers = array_filter(array_map('strtoupper', array_map('trim', explode(',', $raw))));
$tickers = array_slice(array_unique($tickers), 0, 30); // cap at 30

if (empty($tickers)) { echo json_encode([]); exit; }

function mfapis_call(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['x-api-key: ' . MFAPIS_API_KEY, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    if (!$resp) return null;
    $d = json_decode((string)$resp, true);
    return is_array($d) ? $d : null;
}

$result = [];

foreach ($tickers as $ticker) {
    // Step 1: resolve ISIN via symbol master search
    $search = mfapis_call(MFAPIS_BASE_URL . '/stocks/symbol_master/search?' . http_build_query(['q' => $ticker]));

    $isin = null;
    if ($search) {
        // Try common shapes: flat array, or data envelope
        $items = $search['data'] ?? $search['results'] ?? (isset($search[0]) ? $search : []);
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $sym = strtoupper(trim($item['symbol'] ?? $item['ticker'] ?? $item['nse_symbol'] ?? ''));
            if ($sym === $ticker) {
                $isin = $item['isin'] ?? $item['ISIN'] ?? null;
                break;
            }
        }
        // fallback: just take first result's ISIN
        if (!$isin && isset($items[0]) && is_array($items[0])) {
            $isin = $items[0]['isin'] ?? $items[0]['ISIN'] ?? null;
        }
    }

    if (!$isin) {
        $result[$ticker] = ['pe' => null, 'pb' => null, 'promoter' => null];
        continue;
    }

    // Step 2: fetch fundamentals
    $fund = mfapis_call(MFAPIS_BASE_URL . '/stocks/fundamentals/latest?' . http_build_query(['isin' => $isin]));
    $prom = mfapis_call(MFAPIS_BASE_URL . '/stocks/promoter/latest?' . http_build_query(['isin' => $isin]));

    // Unwrap data envelope
    if (isset($fund['data'])) $fund = $fund['data'];
    if (isset($prom['data'])) $prom = $prom['data'];

    $pe       = isset($fund['pe'])           ? (float)$fund['pe']           : (isset($fund['pe_ratio'])    ? (float)$fund['pe_ratio']    : null);
    $pb       = isset($fund['pb'])           ? (float)$fund['pb']           : (isset($fund['pb_ratio'])    ? (float)$fund['pb_ratio']    : null);
    $promoter = isset($prom['promoter_pct']) ? (float)$prom['promoter_pct'] : (isset($prom['promoter'])    ? (float)$prom['promoter']    : null);

    $result[$ticker] = compact('pe', 'pb', 'promoter');

    usleep(150000); // 150ms between calls to be polite
}

echo json_encode($result);
