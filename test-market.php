<?php
declare(strict_types=1);
require_once 'includes/config.php';

$key = $_GET['key'] ?? '';
if (!defined('CRON_SECRET') || $key !== CRON_SECRET || CRON_SECRET === '') {
    http_response_code(403); exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);

function try_url(string $label, string $url, array $extra_headers = []): void {
    $headers = array_merge([
        'user_agent'    => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
        'timeout'       => 10,
        'ignore_errors' => true,
    ], $extra_headers);
    $ctx = stream_context_create(['http' => $headers]);
    $t0  = microtime(true);
    $raw = @file_get_contents($url, false, $ctx);
    $ms  = round((microtime(true) - $t0) * 1000);
    $http_vars = $http_response_header ?? [];
    $status = '';
    foreach ($http_vars as $h) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) { $status = $m[1]; break; }
    }
    if ($raw && strlen($raw) > 10) {
        echo "[$label] OK ({$ms}ms, HTTP {$status}) — " . substr(strip_tags($raw), 0, 120) . "\n";
    } else {
        echo "[$label] FAIL ({$ms}ms, HTTP {$status}) — no usable response\n";
    }
}

echo "=== Market Data Source Diagnostic ===\n";
echo "Server time: " . date('Y-m-d H:i:s T') . "\n\n";

// 1. mfapi.in (control — confirmed works)
try_url('mfapi.in (control)', 'https://api.mfapi.in/mf/120716');

echo "\n";

// 2. Stooq.com — free index data, actual Nifty levels
try_url('stooq Nifty50', 'https://stooq.com/q/l/?s=^nsei&f=sd2c&h&e=csv');
try_url('stooq Sensex',  'https://stooq.com/q/l/?s=^bsesn&f=sd2c&h&e=csv');
try_url('stooq BankNifty','https://stooq.com/q/l/?s=^nsebank&f=sd2c&h&e=csv');

echo "\n";

// 3. Yahoo Finance (known blocked on server; confirming)
try_url('Yahoo Finance', 'https://query1.finance.yahoo.com/v8/finance/chart/%5ENSEI?interval=1d&range=2d');

echo "\n";

// 4. Nifty ETF NAV (1 unit ≈ 1/100 of Nifty 50 — guaranteed via mfapi.in)
// Nippon India ETF Nifty BeES — if NAV ~245, Nifty50 = NAV × 100 = 24,500
try_url('Nifty BeES ETF (mfapi)', 'https://api.mfapi.in/mf/search?q=nifty+bees');

echo "\n";

// 5. BSE/NSE direct
try_url('NSE allIndices', 'https://www.nseindia.com/api/allIndices', [
    'header' => "Referer: https://www.nseindia.com/\r\nAccept: application/json\r\n",
]);

echo "\n=== Done ===\n";
