<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$cache_key = 'mkt_indices_v2';
$cache_ttl = 900; // 15 minutes

if (
    isset($_SESSION[$cache_key], $_SESSION[$cache_key . '_ts'])
    && (time() - $_SESSION[$cache_key . '_ts']) < $cache_ttl
) {
    echo json_encode(['data' => $_SESSION[$cache_key], 'cached' => true]);
    exit;
}

$symbols = [
    ['key' => 'nifty50',     'ticker' => '^NSEI',       'label' => 'NIFTY 50'],
    ['key' => 'sensex',      'ticker' => '^BSESN',      'label' => 'SENSEX'],
    ['key' => 'nifty100',    'ticker' => '^CNX100',     'label' => 'NIFTY 100'],
    ['key' => 'nifty500',    'ticker' => '^CNX500',     'label' => 'NIFTY 500'],
    ['key' => 'banknifty',   'ticker' => '^NSEBANK',    'label' => 'BANK NIFTY'],
    ['key' => 'midcap150',   'ticker' => '^NIFMDCP150', 'label' => 'MIDCAP 150'],
    ['key' => 'smallcap250', 'ticker' => '^NIFSC250',   'label' => 'SMALLCAP 250'],
    ['key' => 'niftyit',     'ticker' => '^CNXIT',      'label' => 'NIFTY IT'],
];

$results = [];
foreach ($symbols as $sym) {
    try {
        $ydata = fetch_yahoo_quote($sym['ticker']);
    } catch (Throwable $e) {
        error_log("market-indices Yahoo error ({$sym['ticker']}): " . $e->getMessage());
        $ydata = null;
    }

    $current = $ydata['current'] ?? null;
    $prev    = $ydata['prev']    ?? null;
    $change  = ($current !== null && $prev !== null) ? $current - $prev : null;
    $chg_pct = ($change !== null && $prev > 0) ? ($change / $prev) * 100 : null;

    $results[] = [
        'key'     => $sym['key'],
        'label'   => $sym['label'],
        'current' => $current,
        'prev'    => $prev,
        'change'  => $change,
        'chg_pct' => $chg_pct,
    ];
}

$_SESSION[$cache_key]       = $results;
$_SESSION[$cache_key . '_ts'] = time();

echo json_encode(['data' => $results, 'cached' => false]);

function fetch_yahoo_quote(string $ticker): ?array
{
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/'
         . urlencode($ticker)
         . '?interval=1d&range=2d';

    $ctx = stream_context_create(['http' => [
        'timeout'       => 8,
        'user_agent'    => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'header'        => "Accept: application/json, text/plain, */*\r\nAccept-Language: en-US,en;q=0.9\r\n",
        'ignore_errors' => true,
    ]]);

    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp || strlen($resp) < 50) return null;

    $data = json_decode($resp, true);
    if (!isset($data['chart']['result'][0]['meta'])) return null;

    $meta    = $data['chart']['result'][0]['meta'];
    $current = $meta['regularMarketPrice'] ?? null;
    $prev    = $meta['regularMarketPreviousClose'] ?? $meta['chartPreviousClose'] ?? null;

    if ($current === null) return null;

    return [
        'current' => round((float)$current, 2),
        'prev'    => $prev !== null ? round((float)$prev, 2) : null,
    ];
}
