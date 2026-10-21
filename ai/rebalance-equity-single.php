<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/ai-helpers.php';
ob_end_clean();
header('Content-Type: application/json');
set_time_limit(60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false,'error'=>'Method not allowed'])); }
if (!is_logged_in()) { http_response_code(401); exit(json_encode(['success'=>false,'error'=>'Unauthorised'])); }
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { http_response_code(403); exit(json_encode(['success'=>false,'error'=>'Invalid CSRF token'])); }

$user_id    = get_user_id();
$db         = get_db();
$input      = json_decode(file_get_contents('php://input'), true) ?? [];
$holding_id = (int)($input['holding_id'] ?? 0);

if ($holding_id <= 0) {
    exit(json_encode(['success' => false, 'error' => 'Invalid request.']));
}

try {
    // Generous daily cap -- a single-stock check is cheap (one small Claude
    // call) but still real AI spend, kept separate from the 5/day full-run limit.
    $today_runs = (int)$db->query("SELECT COUNT(*) FROM rebalancer_results WHERE user_id={$user_id} AND rebalance_type='equity_single' AND DATE(generated_at)=CURDATE()")->fetchColumn();
    if ($today_runs >= 15) {
        exit(json_encode(['success' => false, 'error' => 'Daily limit reached for single-stock analysis (15/day). Try again tomorrow.']));
    }

    $stmt = $db->prepare(
        "SELECT id, fund_name, fund_house, invested_amount, current_value, units_held, avg_nav, current_nav, purchase_date
         FROM portfolio_entries
         WHERE id=:id AND user_id=:uid AND asset_type='stock' AND invested_amount > 0"
    );
    $stmt->execute([':id' => $holding_id, ':uid' => $user_id]);
    $h = $stmt->fetch();
    if (!$h) {
        exit(json_encode(['success' => false, 'error' => 'Holding not found or has no active investment.']));
    }

    $tot_stmt = $db->prepare("SELECT COALESCE(SUM(current_value),0) FROM portfolio_entries WHERE user_id=:uid AND asset_type='stock' AND invested_amount > 0");
    $tot_stmt->execute([':uid' => $user_id]);
    $total_equity = (float)$tot_stmt->fetchColumn();

    $gain_pct  = $h['invested_amount'] > 0 ? round((($h['current_value'] - $h['invested_amount']) / $h['invested_amount']) * 100, 1) : 0;
    $weight    = $total_equity > 0 ? round(($h['current_value'] / $total_equity) * 100, 1) : 0;
    $days_held = $h['purchase_date'] ? (int)((time() - strtotime($h['purchase_date'])) / 86400) : 0;
    $tax_type  = $days_held > 365 ? 'LTCG' : 'STCG';
    $ticker    = strtoupper(preg_replace('/[^A-Z0-9&]/i', '', explode(' ', $h['fund_name'])[0]));

    // Resolve ISIN + fundamentals for just this one ticker (no session cache --
    // a single ad-hoc lookup is cheap enough to always fetch fresh).
    $fund_str = '';
    if (strlen($ticker) >= 2) {
        $isin = null;
        $ch = curl_init(MFAPIS_BASE_URL . '/stocks/symbol_master/search?' . http_build_query(['q' => $ticker]));
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER=>['x-api-key: '.MFAPIS_API_KEY,'Accept: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_TIMEOUT=>6]);
        $sr = json_decode((string)curl_exec($ch), true); curl_close($ch);
        if (is_array($sr)) {
            $items = $sr['data'] ?? $sr['results'] ?? (isset($sr[0]) ? $sr : []);
            foreach ((array)$items as $item) {
                if (!is_array($item)) continue;
                if (strtoupper($item['symbol'] ?? $item['ticker'] ?? '') === $ticker) {
                    $isin = $item['isin'] ?? $item['ISIN'] ?? null; break;
                }
            }
            if (!$isin && isset($items[0])) $isin = $items[0]['isin'] ?? $items[0]['ISIN'] ?? null;
        }

        if ($isin) {
            $ch2 = curl_init(MFAPIS_BASE_URL . '/stocks/fundamentals/latest?' . http_build_query(['isin' => $isin]));
            curl_setopt_array($ch2, [CURLOPT_HTTPHEADER=>['x-api-key: '.MFAPIS_API_KEY,'Accept: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_TIMEOUT=>6]);
            $fd = json_decode((string)curl_exec($ch2), true) ?? []; curl_close($ch2);
            if (isset($fd['data'])) $fd = $fd['data'];

            $ch3 = curl_init(MFAPIS_BASE_URL . '/financials/' . rawurlencode($isin) . '/ratios');
            curl_setopt_array($ch3, [CURLOPT_HTTPHEADER=>['x-api-key: '.MFAPIS_API_KEY,'Accept: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_TIMEOUT=>6]);
            $rd = json_decode((string)curl_exec($ch3), true) ?? []; curl_close($ch3);
            if (isset($rd['data'])) $rd = $rd['data'];

            $parts = [];
            $pe = $fd['pe'] ?? $fd['pe_ratio'] ?? null;
            $pb = $fd['pb'] ?? $fd['pb_ratio'] ?? null;
            $roe = $fd['roe'] ?? null;
            $de = $rd['debt_equity'] ?? $rd['debt_to_equity'] ?? null;
            if ($pe !== null)  $parts[] = 'PE:' . round((float)$pe, 1);
            if ($pb !== null)  $parts[] = 'PB:' . round((float)$pb, 2);
            if ($roe !== null) $parts[] = 'ROE:' . round((float)$roe, 1) . '%';
            if ($de !== null)  $parts[] = 'D/E:' . round((float)$de, 2);
            if ($parts) $fund_str = ' [' . implode(' ', $parts) . ']';
        }
    }

    $holdings_text = sprintf(
        "%-30s Weight:%s%% Invested:₹%s Value:₹%s Gain:%s%s%% Held:%dd [%s]%s\n",
        mb_substr($h['fund_name'], 0, 29),
        $weight,
        number_format((float)$h['invested_amount'], 0),
        number_format((float)$h['current_value'], 0),
        $gain_pct >= 0 ? '+' : '', $gain_pct,
        $days_held, $tax_type,
        $fund_str
    );

    $system = "You are a research analyst providing EDUCATIONAL analysis of ONE stock position for a Prime Financials client. This is NOT investment advice. Prime Financials is NOT a SEBI RIA.

Holdings include live fundamental data where available: PE, PB, ROE, D/E. Use these to enrich your analysis. Evaluate position sizing (flag if >10% of equity portfolio), gain/loss and LTCG/STCG tax implications, valuation vs typical sector ranges, financial health (D/E>1.5 elevated, ROE<10% weak), and holding period context. Use educational, research language -- never say 'I recommend you sell X', say 'This position may warrant review given...'.

Your reply MUST start with { and end with }. No markdown fences, no explanation. JSON only.";

    $user_msg = "STOCK POSITION (this stock is {$weight}% of total equity portfolio):\n{$holdings_text}\nReturn JSON:\n{\"stock_name\":\"\",\"ticker\":\"{$ticker}\",\"verdict\":\"hold|accumulate|reduce|exit|review\",\"verdict_label\":\"\",\"confidence\":\"high|medium|low\",\"priority\":\"urgent|moderate|low\",\"weight_in_equity_pct\":{$weight},\"unrealised_gain_pct\":{$gain_pct},\"holding_period_days\":{$days_held},\"tax_note\":\"\",\"reason\":\"\",\"action_detail\":\"\",\"has_research_note\":false}";

    $llm = call_llm($system, [['role' => 'user', 'content' => $user_msg]], 600, 'premium');
    $raw = $llm['text'];
    error_log("Equity single-stock response via {$llm['model']}: " . substr($raw, 0, 300));
    $verdict = extract_json_from_claude($raw);

    if (!is_array($verdict)) {
        exit(json_encode(['success' => false, 'error' => 'AI returned unexpected format. Please try again.']));
    }

    try {
        $ins = $db->prepare("INSERT INTO rebalancer_results (user_id,rebalance_type,holding_name,verdict,verdict_label,confidence,reason,action_detail,priority) VALUES (:uid,'equity_single',:name,:verdict,:label,:conf,:reason,:action,:pri)");
        $ins->execute([
            ':uid'    => $user_id,
            ':name'   => mb_substr($verdict['stock_name'] ?? $h['fund_name'], 0, 200),
            ':verdict'=> in_array($verdict['verdict'] ?? '', ['hold','accumulate','reduce','exit','review']) ? $verdict['verdict'] : 'hold',
            ':label'  => mb_substr($verdict['verdict_label'] ?? 'HOLD', 0, 50),
            ':conf'   => in_array($verdict['confidence'] ?? '', ['high','medium','low']) ? $verdict['confidence'] : 'medium',
            ':reason' => $verdict['reason'] ?? '',
            ':action' => $verdict['action_detail'] ?? null,
            ':pri'    => in_array($verdict['priority'] ?? '', ['urgent','moderate','low']) ? $verdict['priority'] : 'low',
        ]);
    } catch (PDOException $e) {
        error_log('Equity single-stock save error: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'data' => $verdict]);

} catch (Throwable $e) {
    error_log('Equity single-stock error user_id=' . $user_id . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Analysis failed. Please try again.']);
}
