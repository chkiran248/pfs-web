<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false])); }
if (!is_logged_in()) { http_response_code(401); exit(json_encode(['success'=>false])); }
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { http_response_code(403); exit(json_encode(['success'=>false])); }

$user_id = get_user_id();
$db      = get_db();

try {
    $db->prepare("UPDATE users SET has_seen_tour = 1 WHERE id = :id")->execute([':id' => $user_id]);
    $_SESSION['has_seen_tour'] = true;
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('complete-tour error: ' . $e->getMessage());
    echo json_encode(['success' => false]);
}
