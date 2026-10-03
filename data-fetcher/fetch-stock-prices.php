<?php
declare(strict_types=1);
$is_web = php_sapi_name() !== 'cli';
if ($is_web) {
    $token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
    if (!defined('CRON_SECRET') || $token !== CRON_SECRET || CRON_SECRET === '') {
        http_response_code(403); exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
    // Prevent buffering so output streams to cron logs
    if (ob_get_level()) ob_end_clean();
    @ini_set('implicit_flush', '1');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

/**
 * Perform a GET request to mfapis.club and return the decoded JSON array.
 * Returns null on any cURL or JSON error.
 */
function mfapis_get(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['x-api-key: ' . MFAPIS_API_KEY, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '' || $resp === false || $resp === '') {
        if ($err !== '') {
            echo "mfapis cURL error: {$err}\n";
        }
        return null;
    }
    $data = json_decode((string)$resp, true);
    return is_array($data) ? $data : null;
}

$db = get_db();
echo '[' . date('H:i:s') . "] Stock price fetch starting\n";

// ── DETECT ISIN COLUMNS ───────────────────────────────────────────────────────
// isin columns may not exist yet — check at runtime to avoid query errors
$has_isin_wl = false;
$has_isin_rr = false;
try {
    $has_isin_wl = (bool)$db->query("SHOW COLUMNS FROM stock_watchlist LIKE 'isin'")->fetch();
} catch (\PDOException $e) {
    /* column absent — handled via '' AS isin below */
}
try {
    $has_isin_rr = (bool)$db->query("SHOW COLUMNS FROM stock_research LIKE 'isin'")->fetch();
} catch (\PDOException $e) {
    /* column absent */
}

$sel_isin_wl = $has_isin_wl ? 'isin' : "'' AS isin";
$sel_isin_rr = $has_isin_rr ? 'isin' : "'' AS isin";

// ── FETCH DISTINCT TICKERS ────────────────────────────────────────────────────
$sql = "SELECT DISTINCT ticker_symbol, 'NSE' AS exchange, {$sel_isin_wl}
        FROM stock_watchlist WHERE ticker_symbol != ''
        UNION
        SELECT DISTINCT ticker_symbol, exchange, {$sel_isin_rr}
        FROM stock_research WHERE is_published = 1 AND ticker_symbol != ''";

$tickers = $db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

if (empty($tickers)) {
    echo "No tickers to track\n";
    exit(0);
}

// ── PREPARED STATEMENTS ───────────────────────────────────────────────────────
$today    = date('Y-m-d');
$stmt_ins = $db->prepare(
    "INSERT INTO stock_prices (ticker, exchange, close_price, price_date)
     VALUES (:t, :e, :p, :d)
     ON DUPLICATE KEY UPDATE close_price = VALUES(close_price)"
);
$stmt_upd = $db->prepare(
    "UPDATE stock_watchlist SET added_price = :p
     WHERE ticker_symbol = :t AND (added_price IS NULL OR added_price = 0)"
);

// ── PARTITION BY ISIN AVAILABILITY ───────────────────────────────────────────
/** @var array<string, array{ticker: string, exchange: string}> */
$isin_map = [];   // isin => ['ticker' => ..., 'exchange' => ...]
/** @var list<array{ticker: string, exchange: string}> */
$no_isin  = [];   // stocks with no ISIN — per-symbol fetch

foreach ($tickers as $row) {
    $ticker = strtoupper(trim($row['ticker_symbol']));
    $exch   = strtoupper(trim($row['exchange'])) ?: 'NSE';
    $isin   = trim($row['isin'] ?? '');
    if ($isin !== '') {
        $isin_map[$isin] = ['ticker' => $ticker, 'exchange' => $exch];
    } else {
        $no_isin[] = ['ticker' => $ticker, 'exchange' => $exch];
    }
}

/** @var array<string, array{price: float, exchange: string}> */
$prices = [];   // ticker => {price, exchange}

$skip_mfapis_per_symbol = false;

// ── 1. BATCH ISIN FETCH via mfapis.club ──────────────────────────────────────
// Endpoint: GET /stocks/ohlcv/multi/latest?isins=ISIN1,ISIN2,...  (≤50 per call)
if (!empty($isin_map)) {
    $all_chunks_failed   = true;
    $isin_batch_fallback = [];   // ISINs that mfapis couldn't serve

    foreach (array_chunk(array_keys($isin_map), 50) as $chunk) {
        $url  = MFAPIS_BASE_URL . '/stocks/ohlcv/multi/latest?' . http_build_query(['isins' => implode(',', $chunk)]);
        $data = mfapis_get($url);

        if ($data === null) {
            // Entire chunk failed — collect for fallback
            foreach ($chunk as $isin) {
                if (isset($isin_map[$isin])) {
                    $isin_batch_fallback[] = $isin_map[$isin];
                }
            }
            continue;
        }

        $all_chunks_failed = false;

        // Index response by ISIN for O(1) lookup
        $by_isin = [];
        foreach ($data as $item) {
            $resp_isin = trim($item['isin'] ?? '');
            if ($resp_isin !== '') {
                $by_isin[$resp_isin] = $item;
            }
        }

        foreach ($chunk as $isin) {
            if (!isset($by_isin[$isin])) {
                $isin_batch_fallback[] = $isin_map[$isin];
                continue;
            }
            $item  = $by_isin[$isin];
            $close = (float)($item['close'] ?? $item['ltp'] ?? $item['last_price'] ?? 0);
            if ($close > 0) {
                $meta            = $isin_map[$isin];
                $prices[$meta['ticker']] = ['price' => $close, 'exchange' => $meta['exchange']];
                echo "{$meta['ticker']} (mfapis-isin): ₹{$close}\n";
            } else {
                $isin_batch_fallback[] = $isin_map[$isin];
            }
        }
    }

    if ($all_chunks_failed) {
        // mfapis is unreachable — send every ticker straight to Yahoo Finance
        echo "mfapis batch failed entirely — using Yahoo Finance for all tickers\n";
        $no_isin                = array_merge(array_values($isin_map), $no_isin);
        $skip_mfapis_per_symbol = true;
    } else {
        // Merge per-ISIN misses into no_isin for per-symbol mfapis attempt
        $no_isin = array_merge($isin_batch_fallback, $no_isin);
    }
}

// ── 2. PER-SYMBOL FETCH via mfapis.club ──────────────────────────────────────
// Endpoint: GET /stocks/price_feed/latest?symbol=TICKER&exchange=NSE
/** @var list<array{ticker: string, exchange: string}> */
$yahoo_fallback = [];

if (!$skip_mfapis_per_symbol) {
    foreach ($no_isin as $row) {
        $ticker     = $row['ticker'];
        $exch       = $row['exchange'];
        $exch_param = ($exch === 'BSE') ? 'BSE' : 'NSE';

        $url  = MFAPIS_BASE_URL . '/stocks/price_feed/latest?' . http_build_query(['symbol' => $ticker, 'exchange' => $exch_param]);
        $data = mfapis_get($url);

        if ($data === null) {
            $yahoo_fallback[] = $row;
            continue;
        }

        $close = (float)($data['close'] ?? $data['ltp'] ?? $data['last_price'] ?? $data['price'] ?? 0);
        if ($close > 0) {
            $prices[$ticker] = ['price' => $close, 'exchange' => $exch];
            echo "{$ticker} (mfapis): ₹{$close}\n";
        } else {
            $yahoo_fallback[] = $row;
        }
    }
} else {
    // mfapis batch failed entirely — all tickers go to Yahoo
    $yahoo_fallback = $no_isin;
}

// ── 3. YAHOO FINANCE FALLBACK ─────────────────────────────────────────────────
// Used for: (a) stocks mfapis couldn't serve, (b) full mfapis outage
if (!empty($yahoo_fallback)) {
    echo 'Falling back to Yahoo Finance for ' . count($yahoo_fallback) . " tickers\n";
    $ctx = stream_context_create([
        'http' => [
            'timeout'    => 10,
            'user_agent' => 'Mozilla/5.0 (compatible; PrimeFinancials/1.0)',
        ],
    ]);

    foreach ($yahoo_fallback as $row) {
        $ticker  = $row['ticker'];
        $exch    = $row['exchange'];
        $suffix  = ($exch === 'BSE') ? '.BO' : '.NS';
        $yf_tick = $ticker . $suffix;

        $url  = "https://query1.finance.yahoo.com/v8/finance/chart/{$yf_tick}?interval=1d&range=1d";
        $json = @file_get_contents($url, false, $ctx);

        if (!$json) {
            echo "No data for {$ticker} (Yahoo)\n";
            sleep(1);
            continue;
        }

        $data  = json_decode($json, true);
        $close = (float)($data['chart']['result'][0]['meta']['regularMarketPrice'] ?? 0);

        if ($close > 0) {
            $prices[$ticker] = ['price' => $close, 'exchange' => $exch];
            echo "{$ticker} (Yahoo): ₹{$close}\n";
        }
        sleep(1);
    }
}

// ── 4. PERSIST TO DATABASE ────────────────────────────────────────────────────
$fetched = 0;
foreach ($prices as $ticker => $info) {
    try {
        $stmt_ins->execute([':t' => $ticker, ':e' => $info['exchange'], ':p' => $info['price'], ':d' => $today]);
        $stmt_upd->execute([':p' => $info['price'], ':t' => $ticker]);
        $fetched++;
    } catch (\PDOException $e) {
        error_log("stock_prices DB error for {$ticker}: " . $e->getMessage());
        echo "DB error for {$ticker}\n";
    }
}

echo "Fetched prices for {$fetched} stocks\n";
echo '[' . date('H:i:s') . "] Stock price fetch complete\n";
