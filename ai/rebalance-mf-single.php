<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/rebalancer-core.php';

ob_end_clean();
header('Content-Type: application/json');
set_time_limit(60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false,'error'=>'Method not allowed'])); }
if (!is_logged_in()) { http_response_code(401); exit(json_encode(['success'=>false,'error'=>'Unauthorised'])); }
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { http_response_code(403); exit(json_encode(['success'=>false,'error'=>'Invalid CSRF token'])); }

$user_id    = get_user_id();
$db         = get_db();
$input      = json_decode(file_get_contents('php://input'), true) ?? [];
$holding_id = (int)($input['holding_id'] ?? 0);

if ($holding_id <= 0) {
    exit(json_encode(['success' => false, 'error' => 'Invalid request.']));
}

try {
    // Generous daily cap -- a single-fund check is cheap (one small Claude
    // call) but still real AI spend, so it's kept separate from (and more
    // generous than) the 3/month full-portfolio limit.
    $today_runs = (int)$db->query("SELECT COUNT(*) FROM rebalancer_results WHERE user_id={$user_id} AND rebalance_type='mutual_fund_single' AND DATE(generated_at)=CURDATE()")->fetchColumn();
    if ($today_runs >= 15) {
        exit(json_encode(['success' => false, 'error' => 'Daily limit reached for single-fund analysis (15/day). Try again tomorrow.']));
    }

    $data = run_mf_single_fund_analysis($db, $user_id, $holding_id);
    echo json_encode(['success' => true, 'data' => $data]);

} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('MF Single Rebalancer error user_id=' . $user_id . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Analysis failed. Please try again.']);
}
