<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mf-api.php';
if (!is_logged_in()) { http_response_code(401); exit(json_encode(['error' => 'Unauthorised'])); }
header('Content-Type: application/json');

$search   = trim($_GET['q']        ?? '');
$category = trim($_GET['category'] ?? '');
$amc      = trim($_GET['amc']      ?? '');
$option   = trim($_GET['option']   ?? '');
$sort_by  = trim($_GET['sort']     ?? 'return_1yr');
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = 18;

// ── Primary: mfapis.club ─────────────────────────────────
$out   = [];
$total = 0;
$source = 'mfapis';
$primary_ok = false;

if (defined('MFAPIS_API_KEY') && MFAPIS_API_KEY !== '') {
    $params = ['limit' => $limit, 'page' => $page];
    if ($search)   $params['search']   = $search;
    if ($category) $params['category'] = $category;
    if ($amc)      $params['amc']      = $amc;
    if ($option)   $params['option']   = $option;
    if ($sort_by)  $params['sort_by']  = $sort_by;
    $params['direction'] = 'desc';

    $url = MFAPIS_BASE_URL . '/scheme?' . http_build_query($params);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['x-api-key: ' . MFAPIS_API_KEY, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 12,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp && $httpCode < 400) {
        $data  = json_decode((string)$resp, true);
        $funds = [];
        if (isset($data['data']) && is_array($data['data']))       { $funds = $data['data']; $total = (int)($data['total'] ?? $data['count'] ?? count($funds)); }
        elseif (isset($data[0]))                                    { $funds = $data;          $total = count($funds); }
        elseif (isset($data['funds']) && is_array($data['funds'])) { $funds = $data['funds']; $total = (int)($data['total'] ?? count($funds)); }

        foreach ($funds as $f) {
            if (!is_array($f)) continue;
            $out[] = [
                'id'            => $f['scheme_code'] ?? $f['amfi_code'] ?? $f['id'] ?? $f['schemeCode'] ?? null,
                'name'          => $f['scheme_name'] ?? $f['fund_name'] ?? $f['name'] ?? '',
                'amc'           => $f['amc'] ?? $f['fund_house'] ?? $f['AMCName'] ?? '',
                'category'      => $f['category'] ?? $f['scheme_category'] ?? '',
                'sub_category'  => $f['sub_category'] ?? $f['schemeSubCategory'] ?? '',
                'option'        => $f['option'] ?? $f['plan'] ?? '',
                'nav'           => isset($f['nav'])           ? (float)$f['nav']           : null,
                'return_1yr'    => isset($f['return_1yr'])    ? (float)$f['return_1yr']    : (isset($f['returns_1yr'])   ? (float)$f['returns_1yr']   : null),
                'return_3yr'    => isset($f['return_3yr'])    ? (float)$f['return_3yr']    : (isset($f['returns_3yr'])   ? (float)$f['returns_3yr']   : null),
                'return_5yr'    => isset($f['return_5yr'])    ? (float)$f['return_5yr']    : (isset($f['returns_5yr'])   ? (float)$f['returns_5yr']   : null),
                'expense_ratio' => isset($f['expense_ratio']) ? (float)$f['expense_ratio'] : (isset($f['expenseRatio']) ? (float)$f['expenseRatio']  : null),
                'aum_cr'        => isset($f['aum'])           ? (float)$f['aum']           : (isset($f['aum_cr'])       ? (float)$f['aum_cr']        : null),
                'risk'          => $f['risk_level'] ?? $f['riskometer'] ?? $f['risk'] ?? '',
                'source'        => 'mfapis',
            ];
        }
        if (!empty($out)) $primary_ok = true;
    }
}

// ── Secondary: mfapi.in fallback ─────────────────────────
if (!$primary_ok) {
    $query_str = trim("$search $category $amc");
    if (!$query_str) $query_str = 'equity'; // sensible default
    $matches = mfapi_search($query_str, $limit);
    foreach ($matches as $m) {
        $code = (string)($m['schemeCode'] ?? '');
        $name = (string)($m['schemeName'] ?? '');
        if (!$code || !$name) continue;

        // Fetch latest NAV + meta for this scheme
        $sd = mf_api_fetch($code);
        $nav = null;
        $fund_house = '';
        $cat = '';
        if ($sd) {
            $nav        = isset($sd['data'][0]['nav']) ? (float)$sd['data'][0]['nav'] : null;
            $fund_house = $sd['meta']['fund_house'] ?? '';
            $cat        = $sd['meta']['scheme_category'] ?? '';
        }

        // Apply filters client-side
        if ($amc      && stripos($fund_house, $amc)      === false) continue;
        if ($category && stripos($cat,        $category) === false) continue;

        $out[] = [
            'id'            => $code,
            'name'          => $name,
            'amc'           => $fund_house,
            'category'      => $cat,
            'sub_category'  => '',
            'option'        => stripos($name, 'direct') !== false ? 'direct' : (stripos($name, 'regular') !== false ? 'regular' : ''),
            'nav'           => $nav,
            'return_1yr'    => null,
            'return_3yr'    => null,
            'return_5yr'    => null,
            'expense_ratio' => null,
            'aum_cr'        => null,
            'risk'          => '',
            'source'        => 'mfapi',
        ];

        // 100 ms delay between individual mfapi.in NAV fetches to avoid hammering
        usleep(100000);
    }
    $total  = count($out);
    $source = 'mfapi';

    // Pagination
    $offset = ($page - 1) * $limit;
    $out    = array_slice($out, $offset, $limit);
}

echo json_encode(['funds' => $out, 'total' => $total, 'page' => $page, 'limit' => $limit, 'source' => $source]);
