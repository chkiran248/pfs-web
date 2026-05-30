<?php
/**
 * Cron: check-watchlist-alerts.php
 * Schedule: 0 9 * * * (daily at 9am)
 * Checks fund_watchlist for NAV alerts and logs them.
 * NOTE: Actual NAV data must be updated via an external API feed.
 *       This script checks stored current_nav against alert thresholds.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';

$db = get_db();

// Fetch all watchlist items with alerts set
$stmt = $db->prepare("
    SELECT fw.*, u.email, u.full_name
    FROM fund_watchlist fw
    JOIN users u ON u.id = fw.user_id
    WHERE (fw.alert_nav_above IS NOT NULL OR fw.alert_nav_below IS NOT NULL)
      AND fw.current_nav IS NOT NULL
");
$stmt->execute();
$items = $stmt->fetchAll();

$alerts_sent = 0;
foreach ($items as $item) {
    $nav   = (float)$item['current_nav'];
    $above = $item['alert_nav_above'] ? (float)$item['alert_nav_above'] : null;
    $below = $item['alert_nav_below'] ? (float)$item['alert_nav_below'] : null;

    $triggered = false;
    $msg       = '';

    if ($above && $nav >= $above) {
        $triggered = true;
        $msg = "NAV ₹{$nav} has crossed your alert threshold of ₹{$above} (above).";
    } elseif ($below && $nav <= $below) {
        $triggered = true;
        $msg = "NAV ₹{$nav} has fallen below your alert threshold of ₹{$below}.";
    }

    if ($triggered) {
        $html = "<p>Hi {$item['full_name']},</p>
                 <p>Your watchlist alert for <strong>{$item['fund_name']}</strong> has triggered.</p>
                 <p>{$msg}</p>
                 <p>Login to your portal to review: <a href='" . SITE_URL . "/portal/fund-watchlist.php'>View Watchlist</a></p>
                 <p style='font-size:0.8rem;color:#999'>Prime Financials · support@primefin.in</p>";

        if (send_email($item['email'], "Watchlist Alert: {$item['fund_name']}", $html)) {
            $alerts_sent++;
            echo date('Y-m-d H:i:s') . " | Alert sent to {$item['email']} for {$item['fund_name']}\n";
        }
    }
}

echo date('Y-m-d H:i:s') . " | Watchlist check complete. {$alerts_sent} alerts sent.\n";
