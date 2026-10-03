<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mf-api.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorised']));
}

header('Content-Type: application/json');

$name  = trim($_GET['name']  ?? '');
$house = trim($_GET['house'] ?? '');

if (!$name) {
    echo json_encode(['error' => 'name required']);
    exit;
}

$query = $house ? "$name $house" : $name;

// ── Step 1: Try mfapis.club (primary) ────────────────────
$result = null;

if (defined('MFAPIS_API_KEY') && MFAPIS_API_KEY !== '') {
    $url = MFAPIS_BASE_URL . '/scheme?' . http_build_query(['search' => $query, 'limit' => 5]);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['x-api-key: ' . MFAPIS_API_KEY, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp && $httpCode < 400) {
        $data = json_decode((string)$resp, true);
        $scheme = null;
        if (is_array($data[0] ?? null))             $scheme = $data[0];
        elseif (is_array($data['data'][0] ?? null)) $scheme = $data['data'][0];

        if ($scheme) {
            $scheme_id = $scheme['scheme_code'] ?? $scheme['amfi_code'] ?? $scheme['id'] ?? $scheme['schemeCode'] ?? null;
            if ($scheme_id) {
                $url2 = MFAPIS_BASE_URL . '/scheme/mf_data/' . rawurlencode((string)$scheme_id);
                $ch2  = curl_init($url2);
                curl_setopt_array($ch2, [
                    CURLOPT_HTTPHEADER     => ['x-api-key: ' . MFAPIS_API_KEY, 'Accept: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT        => 8,
                ]);
                $resp2 = curl_exec($ch2);
                $code2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);
                $mf = json_decode((string)$resp2, true);
                if (isset($mf['data']) && is_array($mf['data'])) $mf = $mf['data'];
                if (is_array($mf) && $code2 < 400) {
                    $r1 = $mf['return_1yr'] ?? $mf['returns_1yr'] ?? $mf['cagr_1yr'] ?? null;
                    $r3 = $mf['return_3yr'] ?? $mf['returns_3yr'] ?? $mf['cagr_3yr'] ?? null;
                    $er = $mf['expense_ratio'] ?? $mf['expenseRatio'] ?? $mf['ter'] ?? null;
                    if ($r1 !== null || $r3 !== null || $er !== null) {
                        $result = [
                            'return_1yr'    => $r1 !== null ? (float)$r1 : null,
                            'return_3yr'    => $r3 !== null ? (float)$r3 : null,
                            'expense_ratio' => $er !== null ? (float)$er : null,
                            'scheme_id'     => $scheme_id,
                            'source'        => 'mfapis',
                        ];
                    }
                }
            }
        }
    }
}

// ── Step 2: Fall back to mfapi.in (secondary) ────────────
if ($result === null) {
    $matches = mfapi_search($name, 5);
    foreach ($matches as $m) {
        $code = (string)($m['schemeCode'] ?? '');
        if (!$code) continue;
        $scheme_data = mf_api_fetch($code);
        if (!$scheme_data || empty($scheme_data['data'])) continue;
        $nav_data = $scheme_data['data'];
        $r1 = mf_cagr($nav_data, 1);
        $r3 = mf_cagr($nav_data, 3);
        $result = [
            'return_1yr'    => $r1,
            'return_3yr'    => $r3,
            'expense_ratio' => null,
            'scheme_id'     => $code,
            'source'        => 'mfapi',
        ];
        break;
    }
}

if ($result === null) {
    echo json_encode(['error' => 'not_found']);
    exit;
}

echo json_encode($result);
