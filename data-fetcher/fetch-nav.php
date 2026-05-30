<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$db    = get_db();
$today = date('Y-m-d');
echo '[' . date('H:i:s') . "] NAV fetch starting for $today\n";

// Fetch all fund NAVs from AMFI (free public API, no key needed)
$context = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'PrimeFinancials/1.0']]);
$raw     = @file_get_contents('https://www.amfiindia.com/spages/NAVAll.txt', false, $context);

if (!$raw) { echo "ERROR: Could not reach AMFI API\n"; exit(1); }

// Parse: Scheme Code;ISIN1;ISIN2;Scheme Name;NAV;Date
$nav_map = [];
foreach (explode("\n", $raw) as $line) {
    $p = explode(';', trim($line));
    if (count($p) < 6) continue;
    $code = trim($p[0]);
    $nav  = (float)trim($p[4]);
    $date = trim($p[5]);
    if (!is_numeric($code) || $nav <= 0) continue;
    $dt = DateTime::createFromFormat('d-M-Y', $date);
    if ($dt) $nav_map[$code] = ['name' => trim($p[3]), 'nav' => $nav, 'date' => $dt->format('Y-m-d')];
}
echo 'Parsed ' . count($nav_map) . " NAVs from AMFI\n";

// Get tracked funds
$funds = $db->query("SELECT id, fund_name FROM fund_recommendations WHERE is_active=1")->fetchAll();

$stmt_nav = $db->prepare("INSERT INTO nav_history (fund_code,fund_name,nav,nav_date) VALUES (:code,:name,:nav,:date) ON DUPLICATE KEY UPDATE nav=VALUES(nav)");
$stmt_upd = $db->prepare("UPDATE fund_recommendations SET current_nav=:nav, last_data_refresh=NOW() WHERE id=:id");

$updated = 0;
foreach ($funds as $fund) {
    $words = array_filter(explode(' ', strtolower($fund['fund_name'])), fn($w) => strlen($w) > 3);
    foreach ($nav_map as $code => $entry) {
        $nav_lower   = strtolower($entry['name']);
        $match_count = count(array_filter($words, fn($w) => str_contains($nav_lower, $w)));
        if ($match_count >= max(2, (int)(count($words) * 0.6))) {
            $stmt_nav->execute([':code' => $code, ':name' => $entry['name'], ':nav' => $entry['nav'], ':date' => $entry['date']]);
            $stmt_upd->execute([':nav' => $entry['nav'], ':id' => $fund['id']]);
            $updated++;
            break;
        }
    }
}

echo "Updated $updated fund NAVs\n";
echo '[' . date('H:i:s') . "] NAV fetch complete\n";
