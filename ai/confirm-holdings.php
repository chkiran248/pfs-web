<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/fund-classifier.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (!is_logged_in()) { http_response_code(401); exit; }
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { http_response_code(403); exit(json_encode(['success'=>false,'message'=>'Invalid CSRF'])); }

$input       = json_decode(file_get_contents('php://input'), true) ?? [];
$document_id = (int)($input['document_id'] ?? 0);
$submitted   = $input['holdings'] ?? [];
$user_id     = get_user_id();
$db          = get_db();

// Verify ownership and state
$stmt = $db->prepare("SELECT id, extracted_data FROM document_queue WHERE document_id=:did AND user_id=:uid AND status='pending_confirm'");
$stmt->execute([':did' => $document_id, ':uid' => $user_id]);
$queue = $stmt->fetch();

if (!$queue) {
    exit(json_encode(['success' => false, 'message' => 'Invalid or expired scan result. Please scan again.']));
}

$added = 0;
$db->beginTransaction();

try {
    $ins = $db->prepare("INSERT INTO portfolio_entries
        (user_id,fund_name,fund_house,fund_type,units_held,avg_nav,current_nav,
         invested_amount,current_value,purchase_date,maturity_date,folio_number,
         sip_active,sip_amount,interest_rate)
        VALUES (:uid,:fn,:fh,:ft,:units,:avg,:cur,:inv,:curv,:pd,:md,:folio,:sip,:sipa,:rate)");

    foreach ($submitted as $h) {
        if (!($h['include'] ?? true) || empty($h['fund_name'])) continue;

        // Classify fund_type properly from fund name
        $classified_type = classify_holding($h['fund_name'] ?? '')['db_type'];

        $ins->execute([
            ':uid'   => $user_id,
            ':fn'    => substr($h['fund_name'], 0, 200),
            ':fh'    => substr($h['fund_house'] ?? '', 0, 100),
            ':ft'    => $classified_type,
            ':units' => (float)($h['units_held'] ?? 0),
            ':avg'   => (float)($h['avg_nav'] ?? 0),
            ':cur'   => (float)($h['current_nav'] ?? 0),
            ':inv'   => (float)($h['invested_amount'] ?? 0),
            ':curv'  => (float)($h['current_value'] ?? 0),
            ':pd'    => $h['purchase_date'] ?: null,
            ':md'    => $h['maturity_date'] ?: null,
            ':folio' => substr($h['folio_number'] ?? '', 0, 50),
            ':sip'   => (int)($h['sip_active'] ?? 0),
            ':sipa'  => (float)($h['sip_amount'] ?? 0),
            ':rate'  => (float)($h['interest_rate'] ?? 0),
        ]);
        $added++;
    }

    $db->prepare("UPDATE document_queue SET status='completed', holdings_added=:added, processed_at=NOW() WHERE document_id=:did")
       ->execute([':added' => $added, ':did' => $document_id]);

    $db->commit();
    echo json_encode(['success' => true, 'added' => $added]);

} catch (Exception $e) {
    $db->rollBack();
    error_log("confirm-holdings error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to add holdings. Please try again.']);
}
