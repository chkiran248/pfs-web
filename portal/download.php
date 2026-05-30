<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();

$id  = (int)($_GET['id'] ?? 0);
$uid = get_user_id();

if (!$id) { header('Location: ' . SITE_URL . '/portal/documents.php'); exit; }

$db   = get_db();
$stmt = $db->prepare("SELECT * FROM documents WHERE id = :id AND user_id = :uid AND shared_with_client = 1");
$stmt->execute([':id' => $id, ':uid' => $uid]);
$doc  = $stmt->fetch();

if (!$doc) {
    header('HTTP/1.0 403 Forbidden');
    die('Access denied.');
}

// basename() prevents path traversal
$file_path = UPLOAD_PATH . basename($doc['file_path']);

if (!file_exists($file_path)) {
    header('HTTP/1.0 404 Not Found');
    die('File not found.');
}

$mime = $doc['file_mime'] ?: 'application/octet-stream';
$safe_name = preg_replace('/[^a-zA-Z0-9._\- ]/', '_', $doc['file_original_name']);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $safe_name . '"');
header('Content-Length: ' . filesize($file_path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache');
readfile($file_path);
exit;
