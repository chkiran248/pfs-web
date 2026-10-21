<?php
declare(strict_types=1);
// Buffer all output so no stray PHP notices/errors contaminate the JSON response
ob_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Discard anything the includes may have output, then set our content type
ob_end_clean();
header('Content-Type: application/json');

// This endpoint ONLY queues a job and returns immediately -- it makes no AI
// call itself, so there is no risk of hitting the hosting's ~60s FPM
// execution ceiling (the actual analysis runs via the CLI-invoked
// data-fetcher/process-rebalancer-queue.php, which has no such ceiling).
// Frontend polls ai/rebalance-mf-status.php for the result.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false,'error'=>'Method not allowed'])); }
if (!is_logged_in()) { http_response_code(401); exit(json_encode(['success'=>false,'error'=>'Unauthorised'])); }
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { http_response_code(403); exit(json_encode(['success'=>false,'error'=>'Invalid CSRF token'])); }

$user_id = get_user_id();
$db      = get_db();

try {
    // Already have an active job? Resume polling on that one rather than
    // queueing a duplicate (e.g. user refreshed the page mid-run).
    $active_stmt = $db->prepare("SELECT id, status FROM rebalancer_jobs WHERE user_id=:uid AND rebalance_type='mutual_fund' AND status IN ('queued','processing') ORDER BY created_at DESC LIMIT 1");
    $active_stmt->execute([':uid' => $user_id]);
    $existing = $active_stmt->fetch();
    if ($existing) {
        echo json_encode(['success' => true, 'job_id' => (int)$existing['id'], 'status' => $existing['status']]);
        exit;
    }

    // Rate limit: 3 runs per user per calendar month. A queued attempt counts
    // whether or not it ultimately succeeds -- consistent with how a real
    // advisor's time would be consumed by an attempt either way.
    $month_runs = (int)$db->query("SELECT COUNT(*) FROM rebalancer_jobs WHERE user_id={$user_id} AND rebalance_type='mutual_fund' AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetchColumn();
    if ($month_runs >= 3) {
        exit(json_encode(['success' => false, 'error' => 'Monthly limit reached (3 runs/month). Try again next month.']));
    }

    $cnt_stmt = $db->prepare("SELECT COUNT(*) FROM portfolio_entries WHERE user_id=:uid AND fund_type IN ('equity','debt','hybrid','elss','index','international','liquid','gold') AND invested_amount > 0");
    $cnt_stmt->execute([':uid' => $user_id]);
    if ((int)$cnt_stmt->fetchColumn() === 0) {
        exit(json_encode(['success' => false, 'error' => 'No mutual fund holdings with an active investment found. Add holdings via Portfolio page or upload a CAS statement via PrimoAI.']));
    }

    $ins = $db->prepare("INSERT INTO rebalancer_jobs (user_id, rebalance_type, status) VALUES (:uid, 'mutual_fund', 'queued')");
    $ins->execute([':uid' => $user_id]);
    $job_id = (int)$db->lastInsertId();

    echo json_encode(['success' => true, 'job_id' => $job_id, 'status' => 'queued']);

} catch (Throwable $e) {
    error_log('MF Rebalancer queue error user_id=' . $user_id . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not queue analysis. Please try again.']);
}
