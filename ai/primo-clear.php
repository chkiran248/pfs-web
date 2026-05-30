<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (!is_logged_in()) { http_response_code(401); exit; }

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verify_csrf($csrf)) { http_response_code(403); exit; }

$db = get_db();
$db->prepare("DELETE FROM primo_conversations WHERE user_id = :uid")
   ->execute([':uid' => get_user_id()]);

echo json_encode(['success' => true]);
