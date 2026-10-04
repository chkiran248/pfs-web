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

// Nippon India BeES ETFs are structured so 1 unit = 1/100 of the index by design.
// Storing nav × 100 gives actual approximate index level (within 0.5% of NSE value).
// Funds without a BeES ETF use a regular index fund as proxy — accurate for % change only.
const BENCHMARKS = [
    // BeES ETF series — nav × 100 = actual index level
    'nifty50'           => ['code' => '140084', 'mult' => 100, 'has_level' => true],
    'nifty100'          => ['code' => '121146', 'mult' => 100, 'has_level' => true],
    'sensex'            => ['code' => '131331', 'mult' => 100, 'has_level' => true],
    'banknifty'         => ['code' => '140087', 'mult' => 100, 'has_level' => true],
    'nifty_midcap150'   => ['code' => '146271', 'mult' => 100, 'has_level' => true],
    // Regular index fund proxies — nav tracks % change correctly, absolute value is meaningless
    'nifty500'          => ['code' => '147666', 'mult' => 1, 'has_level' => false],
    'nifty_smallcap250' => ['code' => '148519', 'mult' => 1, 'has_level' => false],
    'crisil_short_dur'  => ['code' => '118796', 'mult' => 1, 'has_level' => false],
    'crisil_gilt'       => ['code' => '119707', 'mult' => 1, 'has_level' => false],
];

$insert = $db->prepare(
    "INSERT INTO benchmark_nav (benchmark, nav_date, nav_value, source)
     VALUES (:bm, :dt, :val, :src)
     ON DUPLICATE KEY UPDATE nav_value=VALUES(nav_value), source=VALUES(source)"
);

$stats = ['ok' => 0, 'failed' => 0];

foreach (BENCHMARKS as $key => $cfg) {
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
