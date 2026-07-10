<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mf-api.php';

$db = get_db();
echo '[' . date('H:i:s') . "] Benchmark NAV fetch starting\n";

// Benchmark definitions: nse_col = column name to find in NSE CSV (null = MFAPI only)
const BENCHMARKS = [
    'nifty50'          => ['nse_col' => 'NIFTY 50 Total Returns Index',          'mfapi' => '120716'],
    'nifty100'         => ['nse_col' => 'NIFTY 100 Total Returns Index',          'mfapi' => '147666'],
    'nifty_midcap150'  => ['nse_col' => 'NIFTY MIDCAP 150 Total Returns Index',   'mfapi' => '148726'],
    'nifty_smallcap250'=> ['nse_col' => 'NIFTY SMALLCAP 250 Total Returns Index', 'mfapi' => '148519'],
    'nifty500'         => ['nse_col' => 'NIFTY 500 Total Returns Index',           'mfapi' => '147666'], // nifty100 proxy (nifty500 funds too new for 3yr history)
    'crisil_short_dur' => ['nse_col' => null,                                      'mfapi' => '118796'],
    'crisil_gilt'      => ['nse_col' => null,                                      'mfapi' => '119707'],
];

$insert = $db->prepare(
    "INSERT INTO benchmark_nav (benchmark, nav_date, nav_value, source)
     VALUES (:bm, :dt, :val, :src)
     ON DUPLICATE KEY UPDATE nav_value=VALUES(nav_value), source=VALUES(source)"
);

$stats = ['nse' => 0, 'mfapi' => 0, 'failed' => 0];

foreach (BENCHMARKS as $key => $cfg) {
    $value  = null;
    $source = null;
    $date   = date('Y-m-d');

    // ── NSE primary (equity benchmarks only) ────────────────────────────────
    if ($cfg['nse_col'] !== null) {
        try {
            $value = fetch_nse_tri($cfg['nse_col'], $date);
            if ($value !== null) {
                $source = 'nse';
                echo "  [NSE]   $key = $value\n";
            }
        } catch (Throwable $e) {
            error_log("fetch-benchmarks NSE error ($key): " . $e->getMessage());
        }
    }

    // ── MFAPI fallback (or primary for debt) ────────────────────────────────
    if ($value === null) {
        try {
            $data = mf_api_fetch($cfg['mfapi']);
            if ($data && !empty($data['data'])) {
                $value  = (float) $data['data'][0]['nav'];
                $source = 'mfapi';
                // Use the NAV date from MFAPI (may differ from $date on holidays)
                $date   = mf_date_to_ymd($data['data'][0]['date']);
                echo "  [MFAPI] $key = $value (date: $date)\n";
            }
        } catch (Throwable $e) {
            error_log("fetch-benchmarks MFAPI error ($key): " . $e->getMessage());
        }
    }

    if ($value === null || $source === null) {
        echo "  [FAIL]  $key — both NSE and MFAPI unavailable\n";
        error_log("fetch-benchmarks: both sources failed for $key");
        $stats['failed']++;
        continue;
    }

    try {
        $insert->execute([':bm' => $key, ':dt' => $date, ':val' => $value, ':src' => $source]);
        $stats[$source]++;
    } catch (PDOException $e) {
        error_log("fetch-benchmarks DB insert error ($key): " . $e->getMessage());
        $stats['failed']++;
    }
}

echo '[' . date('H:i:s') . "] Done — NSE:{$stats['nse']} MFAPI:{$stats['mfapi']} Failed:{$stats['failed']}\n";

// ── Helpers ─────────────────────────────────────────────────────────────────

function fetch_nse_tri(string $col_name, string $date_ymd): ?float
{
    // Try today, then walk back up to 4 days for weekends/holidays
    for ($offset = 0; $offset <= 4; $offset++) {
        $ts  = strtotime("-{$offset} days", strtotime($date_ymd));
        $dmy = date('d', $ts) . date('m', $ts) . date('Y', $ts); // DDMMYYYY
        $url = "https://nsearchives.nseindia.com/content/indices/ind_close_all_{$dmy}.csv";

        $ctx = stream_context_create(['http' => [
            'timeout'     => 15,
            'user_agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'header'      => "Referer: https://www.nseindia.com/\r\nAccept: text/csv,*/*\r\n",
            'ignore_errors' => true,
        ]]);

        $raw = @file_get_contents($url, false, $ctx);
        if (!$raw || strlen($raw) < 100) continue;

        // Parse CSV — first row is header
        $lines = array_filter(explode("\n", trim($raw)));
        if (count($lines) < 2) continue;

        $headers = str_getcsv(array_shift($lines));
        $headers = array_map('trim', $headers);
        $col_idx = array_search($col_name, $headers, true);
        if ($col_idx === false) {
            // Try case-insensitive partial match
            foreach ($headers as $i => $h) {
                if (stripos($h, 'total return') !== false && stripos($h, explode(' ', $col_name)[1]) !== false) {
                    $col_idx = $i;
                    break;
                }
            }
        }
        if ($col_idx === false) continue; // column not in this CSV

        // Find the data row (CSV may have index name in first column or be wide format)
        foreach ($lines as $line) {
            $row = str_getcsv($line);
            if (!isset($row[$col_idx])) continue;
            $val = (float) str_replace(',', '', trim($row[$col_idx]));
            if ($val > 0) return $val;
        }
    }
    return null;
}

function mf_date_to_ymd(string $dmy): string
{
    // MFAPI returns DD-MM-YYYY
    $parts = explode('-', $dmy);
    return count($parts) === 3 ? "{$parts[2]}-{$parts[1]}-{$parts[0]}" : date('Y-m-d');
}
