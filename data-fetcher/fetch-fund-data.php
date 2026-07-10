<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

require_once __DIR__ . '/../includes/mf-api.php';

$db = get_db();
echo '[' . date('H:i:s') . "] Fund return update starting\n";

// Use scheme_code from DB when set; fall back to fuzzy-name match only if missing
$funds = $db->query("SELECT id, fund_name, scheme_code FROM fund_recommendations WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC);

$context     = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'PrimeFinancials/1.0']]);
$all_schemes = null; // lazy-load the full list only if fuzzy matching is needed

$stmt = $db->prepare("UPDATE fund_recommendations SET return_1yr=:r1, return_3yr=:r3, return_5yr=:r5, current_nav=:nav, last_data_refresh=NOW() WHERE id=:id");

foreach ($funds as $fund) {
    $scheme_code = $fund['scheme_code'] ?? null;

    if (!$scheme_code) {
        // Lazy-load all schemes for fuzzy matching
        if ($all_schemes === null) {
            $json = @file_get_contents('https://api.mfapi.in/mf', false, $context);
            $all_schemes = json_decode((string)$json, true) ?? [];
            echo 'Loaded ' . count($all_schemes) . " schemes for fuzzy matching\n";
        }
        $best_code = null; $best_pct = 0;
        $search = strtolower($fund['fund_name']);
        foreach ($all_schemes as $s) {
            $sname = strtolower($s['schemeName']);
            if (strpos($sname, 'direct') !== false && strpos($sname, 'growth') !== false) {
                similar_text($search, $sname, $pct);
                if ($pct > $best_pct) { $best_pct = $pct; $best_code = $s['schemeCode']; }
            }
        }
        if (!$best_code || $best_pct < 65) {
            echo "No match ({$best_pct}%): {$fund['fund_name']}\n";
            continue;
        }
        $scheme_code = (string)$best_code;
        echo "Fuzzy matched ({$best_pct}%): {$fund['fund_name']} → {$scheme_code}\n";
    }

    $data    = mf_api_fetch($scheme_code);
    if (!$data) { echo "MFAPI fetch failed: {$fund['fund_name']} [{$scheme_code}]\n"; sleep(1); continue; }

    $navdata = $data['data'];
    $latest  = (float)$navdata[0]['nav'];
    $r1      = mf_cagr($navdata, 1);
    $r3      = mf_cagr($navdata, 3);
    $r5      = mf_cagr($navdata, 5);

    $stmt->execute([':r1' => $r1, ':r3' => $r3, ':r5' => $r5, ':nav' => round($latest, 4), ':id' => $fund['id']]);
    echo "Updated: {$fund['fund_name']} | NAV={$latest} | 1yr={$r1}% 3yr={$r3}% 5yr={$r5}%\n";
    sleep(1);
}

echo '[' . date('H:i:s') . "] Fund return update complete\n";
