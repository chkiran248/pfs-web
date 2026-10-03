<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false])); }
if (!is_logged_in()) { http_response_code(401); exit(json_encode(['success'=>false])); }

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verify_csrf($csrf)) { http_response_code(403); exit(json_encode(['success'=>false])); }

try {
    $db = get_db();
    $db->prepare("DELETE FROM primo_conversations WHERE user_id = :uid")
       ->execute([':uid' => get_user_id()]);
    echo json_encode(['success' => true]);
} catch (\PDOException $e) {
    error_log('primo-clear DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to clear conversation.']);
}
