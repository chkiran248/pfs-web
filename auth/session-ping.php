<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'session_expired']);
    exit;
}

$_SESSION['last_activity'] = time();
echo json_encode(['ok' => true]);
