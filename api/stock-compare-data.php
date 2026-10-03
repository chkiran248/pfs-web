<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
if (!is_logged_in()) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorised']));
}
header('Content-Type: application/json');

// ── Validate input ─────────────────────────────────────────────────────────
$raw     = trim(filter_input(INPUT_GET, 'tickers', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$tickers = array_filter(array_unique(array_map('strtoupper', array_map('trim', explode(',', $raw)))));
$tickers = array_values(array_slice($tickers, 0, 4)); // max 4

if (empty($tickers)) {
    echo json_encode([]);
    exit;
}

// ── HTTP helper ────────────────────────────────────────────────────────────
function mfapis_get(string $endpoint): ?array
{
    $url = MFAPIS_BASE_URL . $endpoint;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['x-api-key: ' . MFAPIS_API_KEY, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return null;
    $d = json_decode((string)$resp, true);
    return is_array($d) ? $d : null;
}

// Unwrap common API envelopes
function unwrap(?array $d): ?array
{
    if ($d === null) return null;
    return $d['data'] ?? $d['result'] ?? (isset($d[0]) ? $d : $d);
}

function fval(mixed $src, string ...$keys): ?float
{
    if (!is_array($src)) return null;
    foreach ($keys as $k) {
        if (isset($src[$k]) && is_numeric($src[$k])) {
            return (float)$src[$k];
        }
    }
    return null;
}

function sval(mixed $src, string ...$keys): ?string
{
    if (!is_array($src)) return null;
    foreach ($keys as $k) {
        if (!empty($src[$k]) && is_string($src[$k])) {
            return trim($src[$k]);
        }
    }
    return null;
}

// ── Process each ticker ────────────────────────────────────────────────────
$result = [];

foreach ($tickers as $ticker) {

    $entry = [
        'ticker'        => $ticker,
        'name'          => null,
        'sector'        => null,
        'isin'          => null,
        'price'         => null,
        'change_pct'    => null,
        'market_cap_cr' => null,
        'pe'            => null,
        'pb'            => null,
        'roe'           => null,
        'roce'          => null,
        'eps'           => null,
        'div_yield'     => null,
        'debt_equity'   => null,
        'current_ratio' => null,
        '52w_high'      => null,
        '52w_low'       => null,
        'promoter_pct'  => null,
        'pledge_pct'    => null,
    ];

    // Step 1: Resolve ISIN via symbol master search
    $search = mfapis_get('/stocks/symbol_master/search?' . http_build_query(['q' => $ticker]));

    $isin      = null;
    $firstName = null;
    $firstSec  = null;

    if ($search) {
        $items = $search['data'] ?? $search['results'] ?? (isset($search[0]) ? $search : []);
        if (!is_array($items)) $items = [];

        // Exact symbol match first
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $sym = strtoupper(trim($item['symbol'] ?? $item['ticker'] ?? $item['nse_symbol'] ?? ''));
            if ($sym === $ticker) {
                $isin      = $item['isin'] ?? $item['ISIN'] ?? null;
                $firstName = sval($item, 'company_name', 'name', 'short_name');
                $firstSec  = sval($item, 'sector', 'industry', 'sector_name');
                break;
            }
        }
        // Fallback to first result
        if (!$isin && isset($items[0]) && is_array($items[0])) {
            $isin      = $items[0]['isin'] ?? $items[0]['ISIN'] ?? null;
            $firstName = sval($items[0], 'company_name', 'name', 'short_name');
            $firstSec  = sval($items[0], 'sector', 'industry', 'sector_name');
        }
    }

    if (!$isin) {
        $result[] = $entry;
        continue;
    }

    $entry['isin'] = $isin;
    if ($firstName) $entry['name']   = $firstName;
    if ($firstSec)  $entry['sector'] = $firstSec;

    // Step 2a: OHLCV / price data
    $ohlcv = unwrap(mfapis_get('/stocks/ohlcv/latest?' . http_build_query(['isin' => $isin])));
    if ($ohlcv) {
        $entry['price']      = fval($ohlcv, 'close', 'ltp', 'price', 'last_price');
        $entry['change_pct'] = fval($ohlcv, 'change_pct', 'pct_change', 'change_percent', 'chg_pct');
        if (!$entry['name'])   $entry['name']   = sval($ohlcv, 'company_name', 'name', 'short_name');
        if (!$entry['sector']) $entry['sector']  = sval($ohlcv, 'sector', 'industry');
    }

    usleep(150000);

    // Step 2b: Fundamentals
    $fund = unwrap(mfapis_get('/stocks/fundamentals/latest?' . http_build_query(['isin' => $isin])));
    if ($fund) {
        $entry['market_cap_cr'] = fval($fund, 'market_cap_cr', 'market_cap', 'mcap_cr', 'mcap');
        // Convert to Cr if value appears to be in absolute Rs
        if ($entry['market_cap_cr'] !== null && $entry['market_cap_cr'] > 1e10) {
            $entry['market_cap_cr'] = round($entry['market_cap_cr'] / 1e7, 2);
        }
        $entry['pe']        = fval($fund, 'pe', 'pe_ratio', 'p_e');
        $entry['pb']        = fval($fund, 'pb', 'pb_ratio', 'p_b');
        $entry['roe']       = fval($fund, 'roe', 'return_on_equity');
        $entry['roce']      = fval($fund, 'roce', 'return_on_capital_employed');
        $entry['eps']       = fval($fund, 'eps', 'earnings_per_share');
        $entry['div_yield'] = fval($fund, 'div_yield', 'dividend_yield', 'yield');
        $entry['52w_high']  = fval($fund, '52w_high', 'week_52_high', 'year_high', 'high_52');
        $entry['52w_low']   = fval($fund, '52w_low',  'week_52_low',  'year_low',  'low_52');
        if (!$entry['name'])   $entry['name']   = sval($fund, 'company_name', 'name');
        if (!$entry['sector']) $entry['sector']  = sval($fund, 'sector', 'industry');
    }

    usleep(150000);

    // Step 2c: Promoter holding
    $prom = unwrap(mfapis_get('/stocks/promoter/latest?' . http_build_query(['isin' => $isin])));
    if ($prom) {
        $entry['promoter_pct'] = fval($prom, 'promoter_pct', 'promoter', 'promoter_holding', 'promoter_holding_pct');
        $entry['pledge_pct']   = fval($prom, 'pledge_pct', 'pledge', 'pledged_pct', 'pledged_percent');
    }

    usleep(150000);

    // Step 2d: Financial ratios
    $ratios = unwrap(mfapis_get('/financials/' . urlencode($isin) . '/ratios'));
    if ($ratios) {
        $entry['debt_equity']   = fval($ratios, 'debt_equity', 'debt_to_equity', 'de_ratio');
        $entry['current_ratio'] = fval($ratios, 'current_ratio', 'cr');
        if ($entry['roe']  === null) $entry['roe']  = fval($ratios, 'roe', 'return_on_equity');
        if ($entry['roce'] === null) $entry['roce'] = fval($ratios, 'roce', 'return_on_capital_employed');
    }

    $result[] = $entry;

    usleep(150000);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
