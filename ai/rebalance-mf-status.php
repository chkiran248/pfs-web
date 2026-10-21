<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

ob_end_clean();
header('Content-Type: application/json');

if (!is_logged_in()) { http_response_code(401); exit(json_encode(['success'=>false,'error'=>'Unauthorised'])); }

$user_id = get_user_id();
$db      = get_db();
$job_id  = (int)($_GET['job_id'] ?? 0);

try {
    if ($job_id > 0) {
        $stmt = $db->prepare("SELECT id, status, result, error_message FROM rebalancer_jobs WHERE id=:id AND user_id=:uid AND rebalance_type='mutual_fund'");
        $stmt->execute([':id' => $job_id, ':uid' => $user_id]);
    } else {
        // No job_id given -- used on page load to check for an active job to resume polling.
        $stmt = $db->prepare("SELECT id, status, result, error_message FROM rebalancer_jobs WHERE user_id=:uid AND rebalance_type='mutual_fund' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([':uid' => $user_id]);
    }
    $job = $stmt->fetch();

    if (!$job) {
        echo json_encode(['success' => true, 'status' => 'none']);
        exit;
    }

    if ($job['status'] === 'completed') {
        $payload = json_decode((string)$job['result'], true) ?: [];
        echo json_encode([
            'success'     => true,
            'job_id'      => (int)$job['id'],
            'status'      => 'completed',
            'data'        => $payload['result'] ?? null,
            'total_funds' => $payload['total_funds'] ?? null,
        ]);
    } elseif ($job['status'] === 'failed') {
        echo json_encode([
            'success' => true,
            'job_id'  => (int)$job['id'],
            'status'  => 'failed',
            'error'   => $job['error_message'] ?: 'Analysis failed. Please try again.',
        ]);
    } else {
        echo json_encode(['success' => true, 'job_id' => (int)$job['id'], 'status' => $job['status']]);
    }

} catch (Throwable $e) {
    error_log('MF Rebalancer status error user_id=' . $user_id . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not check analysis status.']);
}
