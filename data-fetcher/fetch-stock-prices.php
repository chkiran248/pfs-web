<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = get_db();
echo '[' . date('H:i:s') . "] Stock price fetch starting\n";

// Get unique tickers from watchlist + research
$tickers = $db->query("SELECT DISTINCT ticker_symbol, 'NSE' as exchange FROM stock_watchlist WHERE ticker_symbol != '' UNION SELECT DISTINCT ticker_symbol, exchange FROM stock_research WHERE is_published=1 AND ticker_symbol != ''")->fetchAll();

if (empty($tickers)) { echo "No tickers to track\n"; exit(0); }

$today    = date('Y-m-d');
$stmt_ins = $db->prepare("INSERT INTO stock_prices (ticker,exchange,close_price,price_date) VALUES (:t,:e,:p,:d) ON DUPLICATE KEY UPDATE close_price=VALUES(close_price)");
$stmt_upd = $db->prepare("UPDATE stock_watchlist SET added_price=:p WHERE ticker_symbol=:t AND (added_price IS NULL OR added_price=0)");

// Use Yahoo Finance API (reliable, free, no cookies needed)
// NSE/BSE stocks: add .NS (NSE) or .BO (BSE) suffix
$context = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0 (compatible; PrimeFinancials/1.0)']]);

$fetched = 0;
foreach ($tickers as $t) {
    $ticker  = strtoupper(trim($t['ticker_symbol']));
    $suffix  = ($t['exchange'] === 'BSE') ? '.BO' : '.NS';
    $yf_tick = $ticker . $suffix;

    $url  = "https://query1.finance.yahoo.com/v8/finance/chart/{$yf_tick}?interval=1d&range=1d";
    $json = @file_get_contents($url, false, $context);
    if (!$json) { echo "No data for $ticker\n"; sleep(1); continue; }

    $data  = json_decode($json, true);
    $close = $data['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;

    if ($close && $close > 0) {
        $stmt_ins->execute([':t' => $ticker, ':e' => $t['exchange'], ':p' => (float)$close, ':d' => $today]);
        $stmt_upd->execute([':p' => (float)$close, ':t' => $ticker]);
        echo "$ticker: ₹$close\n";
        $fetched++;
    }
    sleep(1);
}

echo "Fetched prices for $fetched stocks\n";
echo '[' . date('H:i:s') . "] Stock price fetch complete\n";
