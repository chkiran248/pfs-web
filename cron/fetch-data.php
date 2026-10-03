<?php
declare(strict_types=1);
/**
 * Web cron trigger for Hostinger.
 *
 * Hostinger cPanel cron setup (daily at 1:00 UTC = 6:30 AM IST):
 *   curl -s "https://primefin.in/cron/fetch-data.php?token=CHANGE_ME&job=all"
 *
 * CHANGE_ME = value of CRON_SECRET in production includes/config.php
 * NEVER commit config.php with the real secret — it is gitignored.
 *
 * Jobs:
 *   job=benchmarks  — run fetch-benchmarks.php only
 *   job=stocks      — run fetch-stock-prices.php only
 *   job=all         — run both (default)
 */
require_once __DIR__ . '/../includes/config.php';

$token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
if (!defined('CRON_SECRET') || CRON_SECRET === '' || $token !== CRON_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$job  = trim($_GET['job'] ?? 'all');
if (!in_array($job, ['benchmarks', 'stocks', 'all'], true)) {
    http_response_code(400);
    echo 'Unknown job: ' . htmlspecialchars($job, ENT_QUOTES, 'UTF-8');
    exit;
}

$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
      . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

$jobs = match($job) {
    'benchmarks' => ['benchmarks'],
    'stocks'     => ['stocks'],
    default      => ['benchmarks', 'stocks'],
};

foreach ($jobs as $j) {
    $script = $j === 'benchmarks' ? 'fetch-benchmarks.php' : 'fetch-stock-prices.php';
    $url    = $base . '/data-fetcher/' . $script . '?token=' . urlencode($token);
    echo '[' . date('H:i:s') . "] Running $script...\n";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $out  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "HTTP $code\n" . ($out ?: '(no output)') . "\n";
}

echo "\n[DONE] " . date('Y-m-d H:i:s') . "\n";
