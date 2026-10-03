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
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$job = trim($_GET['job'] ?? 'all');
if (!in_array($job, ['benchmarks', 'stocks', 'all'], true)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unknown job: ' . htmlspecialchars($job, ENT_QUOTES, 'UTF-8')]);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
if (ob_get_level()) ob_end_clean();
@ini_set('implicit_flush', '1');
set_time_limit(300);

$root = dirname(__DIR__);

// Direct PHP include — avoids the server-level Deny from all on data-fetcher/.
// $_GET['token'] remains set so the fetcher scripts' own auth checks pass.

if ($job === 'benchmarks' || $job === 'all') {
    echo "=== fetch-benchmarks.php === " . date('H:i:s') . "\n";
    include $root . '/data-fetcher/fetch-benchmarks.php';
    echo "\n";
}

if ($job === 'stocks' || $job === 'all') {
    echo "=== fetch-stock-prices.php === " . date('H:i:s') . "\n";
    include $root . '/data-fetcher/fetch-stock-prices.php';
    echo "\n";
}

echo "[DONE] " . date('Y-m-d H:i:s') . "\n";
