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
require_once __DIR__ . '/../includes/mfapis.php';

$db = get_db();
echo '[' . date('H:i:s') . "] Benchmark fetch starting\n";

// ── STRATEGY ────────────────────────────────────────────────
//
// mfapis.club: provides ACTUAL NSE index levels (exact values like 22,603.05)
//   but their data freshness is unreliable — can lag 1–5 days.
//   Stored with source='mfapis'. Used for absolute level display.
//
// mfapi.in (BeES ETF NAV): updated daily, gives accurate 1-day % CHANGE
//   (TRI vs Price Return differ in absolute value but move identically day-to-day).
//   Stored with source='mfapi'. Used only for % change calculation.
//
// Two separate rows per benchmark. Display pages query each source independently:
//   - Absolute level: latest source='mfapis' row (may be a few days old, shows date)
//   - % change: latest source='mfapi' rows today vs yesterday (accurate, daily)

// ── NSE index name → our benchmark key ──────────────────────
const NSE_INDEX_MAP = [
    'NIFTY 50'           => 'nifty50',
    'NIFTY 100'          => 'nifty100',
    'NIFTY BANK'         => 'banknifty',
    'NIFTY MIDCAP 150'   => 'nifty_midcap150',
    'NIFTY 500'          => 'nifty500',
    'NIFTY SMALLCAP 250' => 'nifty_smallcap250',
];

// ── mfapi.in funds for daily % change ───────────────────────
// Nippon India BeES ETFs track TRI — wrong absolute level, but
// day-to-day % change matches NSE Price Return index precisely.
const MFAPI_PCT = [
    // NSE indices (BeES ETFs × 100)
    'nifty50'           => ['code' => '140084', 'mult' => 100],
    'nifty100'          => ['code' => '121146', 'mult' => 100],
    'banknifty'         => ['code' => '140087', 'mult' => 100],
    'nifty_midcap150'   => ['code' => '146271', 'mult' => 100],
    'nifty500'          => ['code' => '147666', 'mult' => 1],
    'nifty_smallcap250' => ['code' => '148519', 'mult' => 1],
    // SENSEX (BSE — not in mfapis.club NSE endpoint)
    'sensex'            => ['code' => '131331', 'mult' => 100],
    // Debt proxies
    'crisil_short_dur'  => ['code' => '118796', 'mult' => 1],
    'crisil_gilt'       => ['code' => '119707', 'mult' => 1],
];

$insert = $db->prepare(
    "INSERT INTO benchmark_nav (benchmark, nav_date, nav_value, source)
     VALUES (:bm, :dt, :val, :src)
     ON DUPLICATE KEY UPDATE nav_value=VALUES(nav_value), source=VALUES(source)"
);

$stats = ['ok' => 0, 'failed' => 0];

// ── PART 1: mfapis.club — actual NSE index levels ────────────
// Stored with source='mfapis'. May lag a few days — always shows with date in UI.
echo "[" . date('H:i:s') . "] Fetching NSE index levels from mfapis.club…\n";

if (MFAPIS_API_KEY === '') {
    echo "  [SKIP] MFAPIS_API_KEY not set\n";
} else {
    $resp = mfapis_index_latest();
    $rows = null;
    if (isset($resp['data']['items']) && is_array($resp['data']['items'])) {
        $rows = $resp['data']['items'];
    } elseif (isset($resp['data']) && is_array($resp['data']) && isset($resp['data'][0])) {
        $rows = $resp['data'];
    }

    if ($rows === null) {
        $raw = json_encode($resp ?? []);
        echo "  [FAIL] Cannot parse mfapis.club response\n";
        echo "  [DEBUG] " . substr($raw, 0, 500) . "\n";
        error_log('fetch-benchmarks: unrecognised mfapis response: ' . substr($raw, 0, 500));
    } else {
        foreach ($rows as $row) {
            $name = trim(strtoupper((string)($row['indexSymbol'] ?? $row['name'] ?? '')));
            if (!isset(NSE_INDEX_MAP[$name])) continue;

            $bm_key  = NSE_INDEX_MAP[$name];
            $current = $row['indexValue'] ?? $row['current'] ?? $row['last'] ?? null;
            if ($current === null) continue;

            // Date from tickTime ISO string e.g. "2026-10-02T13:22:54.509Z"
            $tick_time  = $row['tickTime'] ?? null;
            $level_date = $tick_time ? substr($tick_time, 0, 10) : date('Y-m-d');
            $level_val  = round((float) $current, 2);

            echo "  [LEVEL] $bm_key = " . number_format($level_val, 2) . " (as of $level_date)\n";
            try {
                $insert->execute([':bm' => $bm_key, ':dt' => $level_date, ':val' => $level_val, ':src' => 'mfapis']);
                // Also store prevClose from the same API response
                $prev_close = $row['prevClose'] ?? $row['previousClose'] ?? null;
                if ($prev_close !== null) {
                    $prev_date = date('Y-m-d', strtotime($level_date . ' -1 day'));
                    $insert->execute([':bm' => $bm_key, ':dt' => $prev_date, ':val' => round((float)$prev_close, 2), ':src' => 'mfapis']);
                }
                $stats['ok']++;
            } catch (Throwable $e) {
                echo "  [FAIL] $bm_key DB — " . $e->getMessage() . "\n";
                error_log("fetch-benchmarks mfapis ($bm_key): " . $e->getMessage());
                $stats['failed']++;
            }
        }
    }
}

// ── PART 2: mfapi.in — daily % change data ──────────────────
// Stored with source='mfapi'. Always today's date — used for % change display.
// Do NOT use these nav_values as absolute levels (TRI, not Price Return).
echo "[" . date('H:i:s') . "] Fetching daily % change data from mfapi.in…\n";

foreach (MFAPI_PCT as $key => $cfg) {
    try {
        $data = mf_api_fetch($cfg['code']);
        if (!$data || empty($data['data'])) {
            throw new RuntimeException("No data returned");
        }

        $today     = $data['data'][0];
        $today_val = round((float)$today['nav'] * $cfg['mult'], 2);
        $today_dt  = mf_date_to_ymd($today['date']);

        echo "  [PCT] $key — nav_date=$today_dt\n";
        $insert->execute([':bm' => $key, ':dt' => $today_dt, ':val' => $today_val, ':src' => 'mfapi']);

        // Store previous trading day for % change calculation
        if (isset($data['data'][1])) {
            $prev_val = round((float)$data['data'][1]['nav'] * $cfg['mult'], 2);
            $prev_dt  = mf_date_to_ymd($data['data'][1]['date']);
            $insert->execute([':bm' => $key, ':dt' => $prev_dt, ':val' => $prev_val, ':src' => 'mfapi']);
        }

        $stats['ok']++;
    } catch (Throwable $e) {
        echo "  [FAIL] $key — " . $e->getMessage() . "\n";
        error_log("fetch-benchmarks mfapi ($key): " . $e->getMessage());
        $stats['failed']++;
    }
}

echo '[' . date('H:i:s') . "] Done — OK:{$stats['ok']} Failed:{$stats['failed']}\n";

function mf_date_to_ymd(string $dmy): string
{
    $parts = explode('-', $dmy);
    return count($parts) === 3 ? "{$parts[2]}-{$parts[1]}-{$parts[0]}" : date('Y-m-d');
}
