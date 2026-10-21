<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/fund-classifier.php';
require_once __DIR__ . '/../includes/fund-matcher.php';

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

$added   = 0;
$updated = 0;
$db->beginTransaction();

try {
    // Existing holdings for this user -- used to merge rather than duplicate.
    // A re-upload of the same or an updated CAS should update a fund already
    // on file (new units/NAV/value from the fresher statement) and only
    // insert genuinely new positions, not add a second copy of every fund
    // that was already there.
    $existing_stmt = $db->prepare("SELECT id, fund_name, folio_number FROM portfolio_entries WHERE user_id = :uid");
    $existing_stmt->execute([':uid' => $user_id]);
    $existing_pool = [];
    foreach ($existing_stmt->fetchAll() as $row) {
        $existing_pool[] = ['id' => $row['id'], 'name' => strtolower($row['fund_name']), 'folio' => trim((string)$row['folio_number'])];
    }

    // asset_type is always 'mutual_fund' here -- CAS/NSDL statements processed
    // by this flow are strictly mutual fund holdings, never individual stocks.
    $ins = $db->prepare("INSERT INTO portfolio_entries
        (user_id,fund_name,fund_house,fund_type,asset_type,units_held,avg_nav,current_nav,
         invested_amount,current_value,purchase_date,maturity_date,folio_number,
         sip_active,sip_amount,interest_rate)
        VALUES (:uid,:fn,:fh,:ft,'mutual_fund',:units,:avg,:cur,:inv,:curv,:pd,:md,:folio,:sip,:sipa,:rate)");

    $upd = $db->prepare("UPDATE portfolio_entries SET
        fund_house=:fh, fund_type=:ft, asset_type='mutual_fund', units_held=:units, avg_nav=:avg, current_nav=:cur,
        invested_amount=:inv, current_value=:curv, purchase_date=:pd, maturity_date=:md,
        folio_number=:folio, sip_active=:sip, sip_amount=:sipa, interest_rate=:rate
        WHERE id=:id AND user_id=:uid");

    foreach ($submitted as $h) {
        if (!($h['include'] ?? true) || empty($h['fund_name'])) continue;

        $classified_type = classify_holding($h['fund_name'] ?? '')['db_type'];
        $folio = trim(substr($h['folio_number'] ?? '', 0, 50));

        // Prefer a match within the same folio number when both sides have
        // one (most reliable signal); fall back to fund name alone otherwise.
        $match = null;
        if ($folio !== '') {
            $same_folio_pool = array_values(array_filter($existing_pool, fn($e) => $e['folio'] === $folio));
            if (!empty($same_folio_pool)) {
                $match = fuzzy_match_fund($h['fund_name'], $same_folio_pool, 'name');
            }
        }
        if (!$match) {
            $match = fuzzy_match_fund($h['fund_name'], $existing_pool, 'name');
        }

        $params = [
            ':fh'    => substr($h['fund_house'] ?? '', 0, 100),
            ':ft'    => $classified_type,
            ':units' => (float)($h['units_held'] ?? 0),
            ':avg'   => (float)($h['avg_nav'] ?? 0),
            ':cur'   => (float)($h['current_nav'] ?? 0),
            ':inv'   => (float)($h['invested_amount'] ?? 0),
            ':curv'  => (float)($h['current_value'] ?? 0),
            ':pd'    => $h['purchase_date'] ?: null,
            ':md'    => $h['maturity_date'] ?: null,
            ':folio' => $folio,
            ':sip'   => (int)($h['sip_active'] ?? 0),
            ':sipa'  => (float)($h['sip_amount'] ?? 0),
            ':rate'  => (float)($h['interest_rate'] ?? 0),
        ];

        if ($match) {
            $upd->execute($params + [':id' => $match['id'], ':uid' => $user_id]);
            $updated++;
        } else {
            $ins->execute($params + [':uid' => $user_id, ':fn' => substr($h['fund_name'], 0, 200)]);
            $new_id = (int)$db->lastInsertId();
            $existing_pool[] = ['id' => $new_id, 'name' => strtolower($h['fund_name']), 'folio' => $folio];
            $added++;
        }
    }

    $db->prepare("UPDATE document_queue SET status='completed', holdings_added=:added, processed_at=NOW() WHERE document_id=:did")
       ->execute([':added' => $added, ':did' => $document_id]);

    $db->commit();
    echo json_encode(['success' => true, 'added' => $added, 'updated' => $updated]);

} catch (Exception $e) {
    $db->rollBack();
    error_log("confirm-holdings error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to add holdings. Please try again.']);
}
