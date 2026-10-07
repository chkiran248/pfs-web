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

// ── NSE index name → our benchmark key mapping ──────────────
// mfapis.club /market/indices/nse/latest returns all NSE indices.
// SENSEX is a BSE index — not in NSE endpoint, fetched separately via mfapi.in.
const NSE_INDEX_MAP = [
    // mfapis.club index name  => our benchmark key
    'NIFTY 50'          => 'nifty50',
    'NIFTY 100'         => 'nifty100',
    'NIFTY BANK'        => 'banknifty',
    'NIFTY MIDCAP 150'  => 'nifty_midcap150',
    'NIFTY 500'         => 'nifty500',
    'NIFTY SMALLCAP 250'=> 'nifty_smallcap250',
];

// ── mfapi.in proxies (% change only — no actual level available from mfapis.club) ──
// SENSEX = BSE index, no NSE endpoint; Nippon BeES ETF gives accurate % change
// Debt indices = not tracked by mfapis.club
const MFAPI_PROXIES = [
    'sensex'           => ['code' => '131331', 'mult' => 100, 'has_level' => false],
    'crisil_short_dur' => ['code' => '118796', 'mult' => 1,   'has_level' => false],
    'crisil_gilt'      => ['code' => '119707', 'mult' => 1,   'has_level' => false],
];

$insert = $db->prepare(
    "INSERT INTO benchmark_nav (benchmark, nav_date, nav_value, source)
     VALUES (:bm, :dt, :val, :src)
     ON DUPLICATE KEY UPDATE nav_value=VALUES(nav_value), source=VALUES(source)"
);

$stats = ['ok' => 0, 'failed' => 0];

// ── PART 1: mfapis.club — actual NSE index levels ────────────
echo "[" . date('H:i:s') . "] Fetching NSE indices from mfapis.club…\n";

if (MFAPIS_API_KEY === '') {
    echo "  [SKIP] MFAPIS_API_KEY not set — skipping mfapis.club fetch\n";
} else {
    $resp = mfapis_index_latest();

    if ($resp === null) {
        echo "  [FAIL] mfapis.club returned null — check API key and endpoint\n";
        error_log('fetch-benchmarks: mfapis_index_latest() returned null');
    } else {
        // Detect data array — mfapis.club returns {"success":true,"data":{"items":[...]}}
        $rows = null;
        if (isset($resp['data']['items']) && is_array($resp['data']['items'])) {
            $rows = $resp['data']['items'];
        } elseif (isset($resp['data']) && is_array($resp['data'])) {
            if (isset($resp['data'][0]) && is_array($resp['data'][0])) {
                $rows = $resp['data'];
            } elseif (isset($resp['data']['data']) && is_array($resp['data']['data'])) {
                $rows = $resp['data']['data'];
            } elseif (isset($resp['data']['indexDetailList'])) {
                $rows = $resp['data']['indexDetailList'];
            }
        } elseif (isset($resp[0]) && is_array($resp[0])) {
            $rows = $resp;
        }

        if ($rows === null) {
            // Log raw response for debugging — first 2000 chars only
            $raw = json_encode($resp);
            echo "  [FAIL] Cannot parse mfapis.club response structure\n";
            echo "  [DEBUG] Raw response (first 2000 chars): " . substr($raw, 0, 2000) . "\n";
            error_log('fetch-benchmarks: unrecognised mfapis response: ' . substr($raw, 0, 500));
        } else {
            foreach ($rows as $row) {
                // Try multiple field name conventions
                $name = $row['indexSymbol'] ?? $row['name'] ?? $row['index_name'] ?? $row['index'] ?? '';
                $name = trim(strtoupper((string) $name));

                if (!isset(NSE_INDEX_MAP[$name])) {
                    continue; // Not one of our tracked indices
                }

                $bm_key = NSE_INDEX_MAP[$name];

                // Current value — mfapis.club uses 'indexValue'; fallbacks for future-proofing
                $current = $row['indexValue'] ?? $row['current'] ?? $row['last'] ?? $row['lastPrice'] ?? $row['close'] ?? $row['value'] ?? null;
                // Previous close — mfapis.club uses 'prevClose'
                $prev_close = $row['prevClose'] ?? $row['previousClose'] ?? $row['prev_close'] ?? $row['previous_close'] ?? null;
                // Date from tickTime ISO string e.g. "2026-10-02T13:22:54.509Z"
                $tick_time = $row['tickTime'] ?? null;
                $today_date = $tick_time ? substr($tick_time, 0, 10) : date('Y-m-d');
                $prev_date  = date('Y-m-d', strtotime($today_date . ' -1 day'));

                if ($current === null) {
                    echo "  [FAIL] $bm_key — cannot find current value in response row\n";
                    $stats['failed']++;
                    continue;
                }

                $today_val = round((float) $current, 2);
                echo "  [OK] $bm_key = " . number_format($today_val, 2) . "\n";

                try {
                    $insert->execute([':bm' => $bm_key, ':dt' => $today_date, ':val' => $today_val, ':src' => 'mfapis']);
                    $stats['ok']++;

                    // Store previous close so % change works from first cron run
                    if ($prev_close !== null) {
                        $prev_val = round((float) $prev_close, 2);
                        $insert->execute([':bm' => $bm_key, ':dt' => $prev_date, ':val' => $prev_val, ':src' => 'mfapis']);
                    }
                } catch (Throwable $e) {
                    echo "  [FAIL] $bm_key DB insert — " . $e->getMessage() . "\n";
                    error_log("fetch-benchmarks DB ($bm_key): " . $e->getMessage());
                    $stats['failed']++;
                }
            }
        }
    }
}

// ── PART 2: mfapi.in — SENSEX (BSE) + debt proxies ──────────
echo "[" . date('H:i:s') . "] Fetching SENSEX + debt proxies from mfapi.in…\n";

foreach (MFAPI_PROXIES as $key => $cfg) {
    try {
        $data = mf_api_fetch($cfg['code']);
        if (!$data || empty($data['data'])) {
            throw new RuntimeException("No data returned");
        }

        $today     = $data['data'][0];
        $today_val = round((float)$today['nav'] * $cfg['mult'], 2);
        $today_dt  = mf_date_to_ymd($today['date']);

        echo "  [OK] $key = " . number_format($today_val, 2) . " (date: $today_dt)\n";
        $insert->execute([':bm' => $key, ':dt' => $today_dt, ':val' => $today_val, ':src' => 'mfapi']);

        // Store previous trading day so % change works from day 1
        if (isset($data['data'][1])) {
            $prev_val = round((float)$data['data'][1]['nav'] * $cfg['mult'], 2);
            $prev_dt  = mf_date_to_ymd($data['data'][1]['date']);
            $insert->execute([':bm' => $key, ':dt' => $prev_dt, ':val' => $prev_val, ':src' => 'mfapi']);
        }

        $stats['ok']++;
    } catch (Throwable $e) {
        echo "  [FAIL] $key — " . $e->getMessage() . "\n";
        error_log("fetch-benchmarks ($key): " . $e->getMessage());
        $stats['failed']++;
    }
}

echo '[' . date('H:i:s') . "] Done — OK:{$stats['ok']} Failed:{$stats['failed']}\n";

function mf_date_to_ymd(string $dmy): string
{
    $parts = explode('-', $dmy);
    return count($parts) === 3 ? "{$parts[2]}-{$parts[1]}-{$parts[0]}" : date('Y-m-d');
}
