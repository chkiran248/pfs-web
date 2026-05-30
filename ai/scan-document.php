<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/document-parser.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false,'message'=>'Method not allowed'])); }
if (!is_logged_in()) { http_response_code(401); exit(json_encode(['success'=>false,'message'=>'Unauthorised'])); }
if (!verify_csrf($_POST['csrf_token'] ?? '')) { http_response_code(403); exit(json_encode(['success'=>false,'message'=>'Invalid CSRF'])); }

$user_id = get_user_id();
$db      = get_db();

$file = $_FILES['document'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    exit(json_encode(['success' => false, 'message' => 'Upload failed. Error code: ' . ($file['error'] ?? 'none')]));
}

// Size check
if ($file['size'] > MAX_UPLOAD_MB * 1024 * 1024) {
    exit(json_encode(['success' => false, 'message' => 'File too large. Max ' . MAX_UPLOAD_MB . 'MB.']));
}

// Extension — scanner only accepts PDF and CSV
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['pdf', 'csv'])) {
    exit(json_encode(['success' => false, 'message' => 'Only PDF and CSV files are supported for scanning.']));
}

// MIME check
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
if (!in_array($mime, ['application/pdf', 'text/csv', 'text/plain'])) {
    exit(json_encode(['success' => false, 'message' => 'Invalid file content type.']));
}

// Save file with UUID name
if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0755, true);
$uuid_name = bin2hex(random_bytes(16)) . '.' . $ext;
$dest_path = UPLOAD_PATH . $uuid_name;

if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
    exit(json_encode(['success' => false, 'message' => 'Failed to save file. Check server permissions.']));
}

// Set higher execution time for large PDFs
set_time_limit(120);

try {
    $db->prepare("INSERT INTO documents (user_id,uploaded_by,doc_name,doc_type,file_path,file_original_name,file_size,file_mime,shared_with_client,shared_with_advisor) VALUES (:uid,:uid,:name,'other',:path,:orig,:size,:mime,1,1)")
       ->execute([':uid'=>$user_id,':name'=>'AI Scan: '.htmlspecialchars($file['name'],ENT_QUOTES,'UTF-8'),':path'=>$uuid_name,':orig'=>$file['name'],':size'=>$file['size'],':mime'=>$mime]);

    $document_id = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO document_queue (user_id, document_id, status) VALUES (:uid, :did, 'pending')")
       ->execute([':uid' => $user_id, ':did' => $document_id]);

    $result = parse_financial_document($dest_path, $file['name'], $user_id, $document_id);
    echo json_encode($result);

} catch (PDOException $e) {
    error_log("scan-document.php DB error: " . $e->getMessage());
    @unlink($dest_path);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
