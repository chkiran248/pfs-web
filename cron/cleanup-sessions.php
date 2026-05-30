<?php
declare(strict_types=1);
/**
 * Cron: cleanup-sessions.php
 * Schedule: 0 3 * * * (daily at 3am)
 * Run: php /path/to/primefin_website/cron/cleanup-sessions.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = get_db();

// Delete expired sessions
$stmt = $db->prepare("DELETE FROM sessions WHERE expires_at < NOW()");
$stmt->execute();
$deleted_sessions = $stmt->rowCount();

// Delete used/expired OTP tokens older than 24 hours
$stmt2 = $db->prepare("DELETE FROM otp_tokens WHERE (used = 1 OR expires_at < NOW()) AND created_at < NOW() - INTERVAL 24 HOUR");
$stmt2->execute();
$deleted_otps = $stmt2->rowCount();

// Delete old Primo conversations (beyond PRIMO_HISTORY_DAYS)
$stmt3 = $db->prepare("DELETE FROM primo_conversations WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)");
$stmt3->execute([':days' => PRIMO_HISTORY_DAYS]);
$deleted_primo = $stmt3->rowCount();

// Clean up old news items (older than 30 days)
$db->exec("DELETE FROM news_items WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");

// Clean up old document queue entries (failed/completed older than 7 days)
$db->exec("DELETE FROM document_queue WHERE status IN ('failed','completed') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");

echo date('Y-m-d H:i:s') . " | Cleanup complete. Sessions: $deleted_sessions deleted. OTPs: $deleted_otps deleted. Primo msgs: $deleted_primo deleted.\n";
