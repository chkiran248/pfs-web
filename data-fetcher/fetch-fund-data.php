<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = get_db();
echo '[' . date('H:i:s') . "] Fund return update starting\n";

$funds   = $db->query("SELECT id, fund_name FROM fund_recommendations WHERE is_active=1")->fetchAll();
$context = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'PrimeFinancials/1.0']]);

// Load scheme list from mfapi.in (free, no key)
$schemes_json = @file_get_contents('https://api.mfapi.in/mf', false, $context);
$all_schemes  = json_decode((string)$schemes_json, true) ?? [];
echo 'Loaded ' . count($all_schemes) . " schemes from mfapi.in\n";

$stmt = $db->prepare("UPDATE fund_recommendations SET return_1yr=:r1, return_3yr=:r3, return_5yr=:r5, last_data_refresh=NOW() WHERE id=:id");

function calc_cagr(array $nav_data, float $current_nav, int $days): ?float {
    $target = new DateTime("-{$days} days");
    foreach ($nav_data as $e) {
        $d = DateTime::createFromFormat('d-m-Y', $e['date']); // mfapi.in uses dd-mm-yyyy
        if (!$d) continue;
        if ($d <= $target) {
            $old = (float)$e['nav'];
            if ($old <= 0) return null;
            return round((pow($current_nav / $old, 365 / $days) - 1) * 100, 2);
        }
    }
    return null;
}

foreach ($funds as $fund) {
    // Fuzzy match by similarity
    $best_code = null; $best_pct = 0;
    $search    = strtolower($fund['fund_name']);
    foreach ($all_schemes as $s) {
        similar_text(strtolower($s['schemeName']), $search, $pct);
        if ($pct > $best_pct) { $best_pct = $pct; $best_code = $s['schemeCode']; }
    }
    if (!$best_code || $best_pct < 65) { echo "No match ({$best_pct}%): {$fund['fund_name']}\n"; continue; }

    $hist = @file_get_contents("https://api.mfapi.in/mf/{$best_code}", false, $context);
    if (!$hist) { sleep(1); continue; }

    $data    = json_decode((string)$hist, true);
    $navdata = $data['data'] ?? [];
    if (empty($navdata)) continue;

    $latest = (float)$navdata[0]['nav'];
    $r1     = calc_cagr($navdata, $latest, 365);
    $r3     = calc_cagr($navdata, $latest, 365 * 3);
    $r5     = calc_cagr($navdata, $latest, 365 * 5);

    $stmt->execute([':r1' => $r1, ':r3' => $r3, ':r5' => $r5, ':id' => $fund['id']]);
    echo "Updated: {$fund['fund_name']} | 1yr={$r1}% 3yr={$r3}% 5yr={$r5}%\n";
    sleep(1); // be respectful of free API
}

echo '[' . date('H:i:s') . "] Fund return update complete\n";
