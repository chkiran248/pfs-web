<?php
declare(strict_types=1);
$is_web = php_sapi_name() !== 'cli';
if ($is_web) {
    $token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
    if (!defined('CRON_SECRET') || $token !== CRON_SECRET || CRON_SECRET === '') {
        http_response_code(403); exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
    if (ob_get_level()) ob_end_clean();
    @ini_set('implicit_flush', '1');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mf-api.php';

$db = get_db();
echo '[' . date('H:i:s') . "] Benchmark fetch starting\n";

// ── Benchmark definitions ────────────────────────────────────────────────────
// yahoo  : Yahoo Finance ticker for actual index level (primary for equity)
// mfapi  : mfapi.in scheme code (fallback for equity; primary for debt)
const BENCHMARKS = [
    'nifty50'           => ['yahoo' => '^NSEI',        'mfapi' => '120716'],
    'nifty100'          => ['yahoo' => '^CNX100',      'mfapi' => '147666'],
    'nifty_midcap150'   => ['yahoo' => '^NIFMDCP150',  'mfapi' => '148726'],
    'nifty_smallcap250' => ['yahoo' => '^NIFSC250',    'mfapi' => '148519'],
    'nifty500'          => ['yahoo' => '^CNX500',      'mfapi' => '147666'],
    'crisil_short_dur'  => ['yahoo' => null,           'mfapi' => '118796'],
    'crisil_gilt'       => ['yahoo' => null,           'mfapi' => '119707'],
];

$insert = $db->prepare(
    "INSERT INTO benchmark_nav (benchmark, nav_date, nav_value, source)
     VALUES (:bm, :dt, :val, :src)
     ON DUPLICATE KEY UPDATE nav_value=VALUES(nav_value), source=VALUES(source)"
);

$stats = ['yahoo' => 0, 'mfapi' => 0, 'failed' => 0];

foreach (BENCHMARKS as $key => $cfg) {
    $value  = null;
    $prev   = null;
    $source = null;
    $date   = date('Y-m-d');

    // ── Yahoo Finance (actual index levels for equity benchmarks) ─────────────
    if ($cfg['yahoo'] !== null) {
        try {
            $ydata = fetch_yahoo_index($cfg['yahoo']);
            if ($ydata && $ydata['current'] > 0) {
                $value  = $ydata['current'];
                $prev   = $ydata['prev'];
                $source = 'yahoo';
                $date   = $ydata['date'];
                echo "  [Yahoo] $key = " . number_format($value, 2) . " (date: $date)\n";
            }
        } catch (Throwable $e) {
            error_log("fetch-benchmarks Yahoo error ($key): " . $e->getMessage());
        }
    }

    // ── mfapi.in fallback (debt primary; equity fallback) ────────────────────
    $mfapi_history = null;
    if ($value === null) {
        try {
            $data = mf_api_fetch($cfg['mfapi']);
            if ($data && !empty($data['data'])) {
                $mfapi_history = $data['data'];
                $value  = (float) $data['data'][0]['nav'];
                $source = 'mfapi';
                $date   = mf_date_to_ymd($data['data'][0]['date']);
                echo "  [MFAPI] $key = $value (date: $date)\n";
            }
        } catch (Throwable $e) {
            error_log("fetch-benchmarks MFAPI error ($key): " . $e->getMessage());
        }
    }

    if ($value === null || $source === null) {
        echo "  [FAIL]  $key — all sources unavailable\n";
        error_log("fetch-benchmarks: all sources failed for $key");
        $stats['failed']++;
        continue;
    }

    try {
        $insert->execute([':bm' => $key, ':dt' => $date, ':val' => $value, ':src' => $source]);
        $stats[$source] = ($stats[$source] ?? 0) + 1;
    } catch (PDOException $e) {
        error_log("fetch-benchmarks DB insert error ($key): " . $e->getMessage());
        $stats['failed']++;
        continue;
    }

    // Store previous trading day so dashboard can show 1-day % change from first run
    if ($source === 'yahoo' && $prev !== null && $prev > 0) {
        $prev_date = date('Y-m-d', strtotime('-1 day', strtotime($date)));
        // Walk back to find the last business day
        $wd = (int)date('N', strtotime($prev_date));
        if ($wd === 7) $prev_date = date('Y-m-d', strtotime('-2 days', strtotime($date)));
        if ($wd === 6) $prev_date = date('Y-m-d', strtotime('-1 day', strtotime($date)));
        try {
            $insert->execute([':bm' => $key, ':dt' => $prev_date, ':val' => $prev, ':src' => 'yahoo']);
        } catch (PDOException $e) {
            error_log("fetch-benchmarks Yahoo prev-day insert ($key): " . $e->getMessage());
        }
    } elseif ($mfapi_history !== null && isset($mfapi_history[1])) {
        try {
            $prev_val  = (float) $mfapi_history[1]['nav'];
            $prev_date = mf_date_to_ymd($mfapi_history[1]['date']);
            $insert->execute([':bm' => $key, ':dt' => $prev_date, ':val' => $prev_val, ':src' => 'mfapi']);
        } catch (PDOException $e) {
            error_log("fetch-benchmarks MFAPI prev-day insert ($key): " . $e->getMessage());
        }
    }
}

echo '[' . date('H:i:s') . "] Done — Yahoo:{$stats['yahoo']} MFAPI:{$stats['mfapi']} Failed:{$stats['failed']}\n";

// ── Helpers ──────────────────────────────────────────────────────────────────

function fetch_yahoo_index(string $ticker): ?array
{
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . urlencode($ticker) . '?interval=1d&range=2d';
    $ctx = stream_context_create(['http' => [
        'timeout'       => 10,
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
    if (!$current) return null;
    return [
        'current' => (float) $current,
        'prev'    => $prev !== null ? (float) $prev : null,
        'date'    => date('Y-m-d'),
    ];
}

function mf_date_to_ymd(string $dmy): string
{
    $parts = explode('-', $dmy);
    return count($parts) === 3 ? "{$parts[2]}-{$parts[1]}-{$parts[0]}" : date('Y-m-d');
}
