<?php
declare(strict_types=1);
// Test: runs all rebalancer setup without calling Claude (no API cost)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/fund-classifier.php';
require_once __DIR__ . '/../includes/mf-api.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if (!defined('CRON_SECRET') || $token !== CRON_SECRET || CRON_SECRET === '') {
    http_response_code(403); exit(json_encode(['error'=>'Forbidden']));
}

if (!is_logged_in()) {
    exit(json_encode(['error' => 'Not logged in', 'session' => session_id()]));
}

$user_id = get_user_id();
$db = get_db();

$hold_stmt = $db->prepare(
    "SELECT fund_name, fund_type, invested_amount, current_value
     FROM portfolio_entries WHERE user_id=:uid
     AND fund_type IN ('equity','debt','hybrid','elss','index','international','liquid','gold')
     ORDER BY current_value DESC"
);
$hold_stmt->execute([':uid' => $user_id]);
$holdings = $hold_stmt->fetchAll();

$max_exec = ini_get('max_execution_time');

echo json_encode([
    'ok'             => true,
    'user_id'        => $user_id,
    'holding_count'  => count($holdings),
    'holdings_sample'=> array_slice($holdings, 0, 3),
    'max_exec_time'  => $max_exec,
    'php_version'    => PHP_VERSION,
]);
