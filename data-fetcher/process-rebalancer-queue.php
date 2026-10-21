<?php
declare(strict_types=1);
// Invoked directly by a "run a command" (not "curl a URL") hPanel cron entry:
//   /opt/alt/php80/usr/bin/php /home/.../public_html/data-fetcher/process-rebalancer-queue.php
// CLI PHP on this host has max_execution_time=0 (unlimited) -- unlike a
// web/FPM request, which is hard-capped at ~60s regardless of script-level
// settings. That ceiling is exactly what repeatedly broke the full-portfolio
// MF rebalancer when it ran synchronously inline with the web request; moving
// the actual analysis here removes the ceiling entirely.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../ai/rebalancer-core.php';

$db = get_db();
echo '[' . date('H:i:s') . "] Rebalancer queue processor starting\n";

$processed = 0;
$max_jobs  = 5; // cap per invocation so a backlog doesn't run forever on one cron tick

while ($processed < $max_jobs) {
    $row = $db->query("SELECT id, user_id FROM rebalancer_jobs WHERE status='queued' ORDER BY created_at ASC LIMIT 1")->fetch();
    if (!$row) {
        echo "No queued jobs.\n";
        break;
    }

    // Atomic claim: only proceed if this process is the one that flips
    // queued -> processing. If rowCount is 0, a concurrent run claimed it
    // first (cron overlap) -- just move on to the next queued row.
    $claim = $db->prepare("UPDATE rebalancer_jobs SET status='processing', started_at=NOW() WHERE id=:id AND status='queued'");
    $claim->execute([':id' => $row['id']]);
    if ($claim->rowCount() === 0) {
        continue;
    }

    $job_id  = (int)$row['id'];
    $user_id = (int)$row['user_id'];
    echo "Processing job #{$job_id} (user {$user_id})...\n";

    try {
        $out = run_mf_rebalance_analysis($db, $user_id);
        $db->prepare("UPDATE rebalancer_jobs SET status='completed', result=:result, completed_at=NOW() WHERE id=:id")
           ->execute([':result' => json_encode($out), ':id' => $job_id]);
        echo "  Job #{$job_id} completed ({$out['total_funds']} funds, {$out['batches']} batch(es)).\n";
    } catch (Throwable $e) {
        error_log("Rebalancer job #{$job_id} failed: " . $e->getMessage());
        $db->prepare("UPDATE rebalancer_jobs SET status='failed', error_message=:err, completed_at=NOW() WHERE id=:id")
           ->execute([':err' => mb_substr($e->getMessage(), 0, 500), ':id' => $job_id]);
        echo "  Job #{$job_id} FAILED: " . $e->getMessage() . "\n";
    }

    $processed++;
}

echo "Done. Processed {$processed} job(s).\n";
