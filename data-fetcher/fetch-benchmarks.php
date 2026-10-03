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
require_once __DIR__ . '/../includes/mf-api.php';  // mf_api_fetch() for CRISIL debt fallback

// These are already defined in includes/config.php (loaded above).
// Guard only against environments where config.php is absent.
if (!defined('MFAPIS_BASE_URL')) define('MFAPIS_BASE_URL', 'https://app2.mfapis.club/api/v2');
if (!defined('MFAPIS_API_KEY'))  define('MFAPIS_API_KEY',  '');

$db = get_db();
echo '[' . date('H:i:s') . "] Benchmark NAV fetch starting\n";

// ── Benchmark definitions ────────────────────────────────────────────────────
// mfapis_name  : string(s) to match in the /indices/nse/latest response (case-insensitive)
// nse_col      : fallback column name for legacy NSE CSV (used only if mfapis fails entirely)
// mfapi        : scheme code for mfapi.in (debt indices primary; equity fallback)
// mfapis_only  : true = this index comes from mfapis, false = debt index uses mfapi.in only
const BENCHMARKS = [
    'nifty50'           => [
        'mfapis_name' => ['NIFTY 50'],
        'nse_col'     => 'NIFTY 50 Total Returns Index',
        'mfapi'       => '120716',
        'mfapis_only' => false,
    ],
    'nifty100'          => [
        'mfapis_name' => ['NIFTY 100'],
        'nse_col'     => 'NIFTY 100 Total Returns Index',
        'mfapi'       => '147666',
        'mfapis_only' => false,
    ],
    'nifty_midcap150'   => [
        'mfapis_name' => ['NIFTY MIDCAP 150'],
        'nse_col'     => 'NIFTY MIDCAP 150 Total Returns Index',
        'mfapi'       => '148726',
        'mfapis_only' => false,
    ],
    'nifty_smallcap250' => [
        'mfapis_name' => ['NIFTY SMALLCAP 250'],
        'nse_col'     => 'NIFTY SMALLCAP 250 Total Returns Index',
        'mfapi'       => '148519',
        'mfapis_only' => false,
    ],
    'nifty500'          => [
        'mfapis_name' => ['NIFTY 500'],
        'nse_col'     => 'NIFTY 500 Total Returns Index',
        'mfapi'       => '147666',   // nifty100 proxy (nifty500 funds too new for 3yr history)
        'mfapis_only' => false,
    ],
    'crisil_short_dur'  => [
        'mfapis_name' => [],         // mfapis covers equity only; use mfapi.in
        'nse_col'     => null,
        'mfapi'       => '118796',
        'mfapis_only' => false,
    ],
    'crisil_gilt'       => [
        'mfapis_name' => [],         // mfapis covers equity only; use mfapi.in
        'nse_col'     => null,
        'mfapi'       => '119707',
        'mfapis_only' => false,
    ],
];

$insert = $db->prepare(
    "INSERT INTO benchmark_nav (benchmark, nav_date, nav_value, source)
     VALUES (:bm, :dt, :val, :src)
     ON DUPLICATE KEY UPDATE nav_value=VALUES(nav_value), source=VALUES(source)"
);

$stats = ['mfapis' => 0, 'mfapi' => 0, 'nse' => 0, 'failed' => 0];

// ── Step 1: Fetch all NSE indices from mfapis.club in ONE call ───────────────
$mfapis_index_map = [];   // normalised-name → ['value' => float, 'date' => 'YYYY-MM-DD']
$mfapis_ok        = false;

try {
    $url = MFAPIS_BASE_URL . '/indices/nse/latest';
    $ch  = curl_init($url);
    $headers = ['Accept: application/json'];
    if (MFAPIS_API_KEY !== '') {
        $headers[] = 'x-api-key: ' . MFAPIS_API_KEY;
    }
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        throw new RuntimeException("cURL error: $curl_err");
    }
    if ($http_code < 200 || $http_code >= 300) {
        $body = substr((string)$resp, 0, 400);
        throw new RuntimeException("HTTP $http_code from mfapis — body: $body");
    }
    if (!$resp || strlen($resp) < 10) {
        throw new RuntimeException("Empty response from mfapis");
    }

    $decoded = json_decode($resp, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Non-JSON response from mfapis: " . substr($resp, 0, 200));
    }

    // Log raw structure on first run to aid debugging
    echo "  [mfapis] HTTP $http_code — raw structure: " . describe_structure($decoded) . "\n";

    // Normalise the response into $mfapis_index_map regardless of shape
    $mfapis_index_map = normalise_mfapis_response($decoded);

    if (empty($mfapis_index_map)) {
        throw new RuntimeException("Could not parse any index entries from mfapis response");
    }

    echo "  [mfapis] Loaded " . count($mfapis_index_map) . " indices\n";
    $mfapis_ok = true;

} catch (Throwable $e) {
    echo "  [mfapis] UNAVAILABLE — " . $e->getMessage() . "\n";
    error_log("fetch-benchmarks mfapis error: " . $e->getMessage());
    echo "  [mfapis] Falling back to NSE CSV for equity benchmarks\n";
}

// ── Step 2: Upsert each benchmark ────────────────────────────────────────────
foreach (BENCHMARKS as $key => $cfg) {
    $value  = null;
    $source = null;
    $date   = date('Y-m-d');

    $has_mfapis_target = !empty($cfg['mfapis_name']);

    // ── 2a. mfapis.club (equity benchmarks) ────────────────────────────────
    if ($has_mfapis_target && $mfapis_ok) {
        foreach ($cfg['mfapis_name'] as $target_name) {
            $hit = find_index_in_map($mfapis_index_map, $target_name);
            if ($hit !== null) {
                $value  = $hit['value'];
                $source = 'mfapis';
                $date   = $hit['date'] ?? date('Y-m-d');
                echo "  [mfapis] $key = $value (date: $date)\n";
                break;
            }
        }
        if ($value === null) {
            echo "  [mfapis] $key — name not found in response; trying fallbacks\n";
        }
    }

    // ── 2b. NSE CSV (equity fallback when mfapis is down) ──────────────────
    if ($value === null && $cfg['nse_col'] !== null) {
        try {
            $nse_val = fetch_nse_tri($cfg['nse_col'], date('Y-m-d'));
            if ($nse_val !== null) {
                $value  = $nse_val;
                $source = 'nse';
                $date   = date('Y-m-d');
                echo "  [NSE]   $key = $value (CSV fallback)\n";
            }
        } catch (Throwable $e) {
            error_log("fetch-benchmarks NSE fallback error ($key): " . $e->getMessage());
        }
    }

    // ── 2c. mfapi.in (debt indices primary; equity last-resort) ────────────
    if ($value === null) {
        try {
            $data = mf_api_fetch($cfg['mfapi']);
            if ($data && !empty($data['data'])) {
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
    }
}

echo '[' . date('H:i:s') . "] Done — mfapis:{$stats['mfapis']} MFAPI:{$stats['mfapi']} NSE:{$stats['nse']} Failed:{$stats['failed']}\n";

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Normalise the mfapis /indices/nse/latest response into a flat map:
 *   [ 'nifty 50' => ['value' => 12345.67, 'date' => '2025-10-03'], ... ]
 *
 * Handles multiple possible shapes the API might return:
 *   - Top-level array of index objects
 *   - Object with 'data'/'indices'/'results' key containing array
 *   - Object with 'index'/'name' key wrapping a single record
 */
function normalise_mfapis_response(array $decoded): array
{
    $map = [];

    // Unwrap common envelope keys
    $candidates = [];
    foreach (['data', 'indices', 'results', 'index', 'records'] as $env_key) {
        if (isset($decoded[$env_key]) && is_array($decoded[$env_key])) {
            $candidates = $decoded[$env_key];
            break;
        }
    }
    // If no envelope found treat the decoded array itself as the list
    if (empty($candidates)) {
        // Could be a flat associative array (name → value) or a list
        if (array_keys($decoded) !== range(0, count($decoded) - 1)) {
            // Associative — maybe {'NIFTY 50': 12345.67, ...}
            foreach ($decoded as $k => $v) {
                if (is_numeric($v)) {
                    $map[strtolower(trim((string)$k))] = ['value' => (float)$v, 'date' => date('Y-m-d')];
                }
            }
            return $map;
        }
        $candidates = $decoded;
    }

    foreach ($candidates as $item) {
        if (!is_array($item)) {
            // Possibly a scalar list — skip
            continue;
        }

        // Extract index name — try common field names
        $name = null;
        foreach (['indexName', 'index_name', 'name', 'Index', 'index', 'symbol', 'label'] as $nk) {
            if (isset($item[$nk]) && is_string($item[$nk])) {
                $name = $item[$nk];
                break;
            }
        }

        // Extract value — try common field names
        $value = null;
        foreach (['last', 'current', 'value', 'close', 'Close', 'currentValue', 'ltp', 'price', 'nav'] as $vk) {
            if (isset($item[$vk]) && is_numeric($item[$vk])) {
                $value = (float)$item[$vk];
                break;
            }
        }

        // Extract date — try common field names
        $date = date('Y-m-d');
        foreach (['date', 'timestamp', 'Date', 'nav_date', 'as_on', 'lastUpdated', 'updateTime'] as $dk) {
            if (isset($item[$dk]) && is_string($item[$dk])) {
                $parsed = parse_mfapis_date($item[$dk]);
                if ($parsed) { $date = $parsed; break; }
            }
        }

        if ($name !== null && $value !== null && $value > 0) {
            $map[strtolower(trim($name))] = ['value' => $value, 'date' => $date];
        }
    }

    return $map;
}

/**
 * Find an index value in the normalised map using case-insensitive partial matching.
 * $target_name is the exact display name e.g. "NIFTY 50".
 */
function find_index_in_map(array $map, string $target_name): ?array
{
    $target_lower = strtolower(trim($target_name));

    // 1. Exact match
    if (isset($map[$target_lower])) {
        return $map[$target_lower];
    }

    // 2. Partial / contains match
    foreach ($map as $key => $hit) {
        if (strpos($key, $target_lower) !== false || strpos($target_lower, $key) !== false) {
            return $hit;
        }
    }

    return null;
}

/**
 * Parse a date string from mfapis into YYYY-MM-DD.
 * Handles ISO 8601, DD-MM-YYYY, DD/MM/YYYY, YYYY-MM-DD.
 */
function parse_mfapis_date(string $raw): ?string
{
    $raw = trim($raw);

    // Already YYYY-MM-DD (possibly with time)
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    // DD-MM-YYYY or DD/MM/YYYY
    if (preg_match('/^(\d{2})[-\/](\d{2})[-\/](\d{4})$/', $raw, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }

    // Timestamp (unix)
    if (ctype_digit($raw)) {
        return date('Y-m-d', (int)$raw);
    }

    // Attempt strtotime as last resort
    $ts = strtotime($raw);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }

    return null;
}

/**
 * Return a brief human-readable description of an array's structure for debug logging.
 */
function describe_structure(array $data): string
{
    $is_list = isset($data[0]);
    if ($is_list) {
        $first = $data[0] ?? null;
        $first_keys = is_array($first) ? implode(', ', array_keys($first)) : gettype($first);
        return sprintf('list[%d] first-keys=[%s]', count($data), $first_keys);
    }
    return 'object keys=[' . implode(', ', array_keys($data)) . ']';
}

/**
 * Legacy NSE CSV fetch — used only as last-resort fallback when mfapis is down.
 * Tries today then walks back up to 4 days for weekends / holidays.
 */
function fetch_nse_tri(string $col_name, string $date_ymd): ?float
{
    for ($offset = 0; $offset <= 4; $offset++) {
        $ts  = strtotime("-{$offset} days", strtotime($date_ymd));
        $dmy = date('d', $ts) . date('m', $ts) . date('Y', $ts); // DDMMYYYY
        $url = "https://nsearchives.nseindia.com/content/indices/ind_close_all_{$dmy}.csv";

        $ctx = stream_context_create(['http' => [
            'timeout'       => 15,
            'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'header'        => "Referer: https://www.nseindia.com/\r\nAccept: text/csv,*/*\r\n",
            'ignore_errors' => true,
        ]]);

        $raw = @file_get_contents($url, false, $ctx);
        if (!$raw || strlen($raw) < 100) continue;

        $lines = array_filter(explode("\n", trim($raw)));
        if (count($lines) < 2) continue;

        $headers = str_getcsv(array_shift($lines));
        $headers = array_map('trim', $headers);
        $col_idx = array_search($col_name, $headers, true);
        if ($col_idx === false) {
            // Case-insensitive partial match
            foreach ($headers as $i => $h) {
                if (stripos($h, 'total return') !== false && stripos($h, explode(' ', $col_name)[1]) !== false) {
                    $col_idx = $i;
                    break;
                }
            }
        }
        if ($col_idx === false) continue;

        foreach ($lines as $line) {
            $row = str_getcsv($line);
            if (!isset($row[$col_idx])) continue;
            $val = (float) str_replace(',', '', trim($row[$col_idx]));
            if ($val > 0) return $val;
        }
    }
    return null;
}

/**
 * Convert MFAPI date format DD-MM-YYYY → YYYY-MM-DD.
 */
function mf_date_to_ymd(string $dmy): string
{
    $parts = explode('-', $dmy);
    return count($parts) === 3 ? "{$parts[2]}-{$parts[1]}-{$parts[0]}" : date('Y-m-d');
}
