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

// mfapi.in scheme codes — used for % change tracking on dashboard strip.
// Absolute NAV values (~157 for nifty50) are fund proxies, not actual index levels.
// Actual index levels are fetched client-side on the Market Indices page.
const BENCHMARKS = [
    'nifty50'           => '120716',
    'nifty100'          => '147666',
    'nifty_midcap150'   => '148726',
    'nifty_smallcap250' => '148519',
    'nifty500'          => '147666',
    'crisil_short_dur'  => '118796',
    'crisil_gilt'       => '119707',
];

$insert = $db->prepare(
    "INSERT INTO benchmark_nav (benchmark, nav_date, nav_value, source)
     VALUES (:bm, :dt, :val, :src)
     ON DUPLICATE KEY UPDATE nav_value=VALUES(nav_value), source=VALUES(source)"
);

$stats = ['ok' => 0, 'failed' => 0];

foreach (BENCHMARKS as $key => $scheme_code) {
    try {
        $data = mf_api_fetch($scheme_code);
        if (!$data || empty($data['data'])) {
            throw new RuntimeException("No data returned");
        }

        $today = $data['data'][0];
        $value = (float) $today['nav'];
        $date  = mf_date_to_ymd($today['date']);
        echo "  [OK] $key = $value (date: $date)\n";

        $insert->execute([':bm' => $key, ':dt' => $date, ':val' => $value, ':src' => 'mfapi']);

        // Also store previous trading day so dashboard can show 1-day % change immediately
        if (isset($data['data'][1])) {
            $prev_val  = (float) $data['data'][1]['nav'];
            $prev_date = mf_date_to_ymd($data['data'][1]['date']);
            $insert->execute([':bm' => $key, ':dt' => $prev_date, ':val' => $prev_val, ':src' => 'mfapi']);
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
