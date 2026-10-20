<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fund-matcher.php';

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
    $match = fuzzy_match_fund((string)$fund['fund_name'], $nav_map, 'name');
    if ($match) {
        $code = array_search($match, $nav_map, true);
        $stmt_nav->execute([':code' => $code, ':name' => $match['name'], ':nav' => $match['nav'], ':date' => $match['date']]);
        $stmt_upd->execute([':nav' => $match['nav'], ':id' => $fund['id']]);
        $updated++;
    }
}

echo "Updated $updated fund NAVs\n";

// ── Refresh fund_watchlist current_nav using same AMFI data ─────────
$wl_names = $db->query(
    "SELECT DISTINCT fund_name FROM fund_watchlist WHERE fund_name != '' AND fund_name IS NOT NULL"
)->fetchAll(PDO::FETCH_COLUMN);

if (!empty($wl_names)) {
    $upd_wl    = $db->prepare("UPDATE fund_watchlist SET current_nav = :nav WHERE LOWER(fund_name) = LOWER(:fn)");
    $wl_updated = 0;
    foreach ($wl_names as $wl_name) {
        $match = fuzzy_match_fund((string)$wl_name, $nav_map, 'name');
        if ($match) {
            $upd_wl->execute([':nav' => $match['nav'], ':fn' => $wl_name]);
            $wl_updated += $upd_wl->rowCount();
        }
    }
    echo "Updated $wl_updated watchlist NAV(s)\n";
}

echo '[' . date('H:i:s') . "] NAV fetch complete\n";
