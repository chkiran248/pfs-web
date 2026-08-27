<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/ai-helpers.php';     // call_llm(), extract_json_from_claude()
require_once __DIR__ . '/fund-classifier.php';

header('Content-Type: application/json');
set_time_limit(300); // allow up to 5 min for large portfolios

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false,'error'=>'Method not allowed'])); }
if (!is_logged_in()) { http_response_code(401); exit(json_encode(['success'=>false,'error'=>'Unauthorised'])); }
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { http_response_code(403); exit(json_encode(['success'=>false,'error'=>'Invalid CSRF token'])); }

$user_id = get_user_id();
$db      = get_db();

// Rate limit: 3 runs per user per day (reduced from 5 since batching is heavier)
$today_runs = (int)$db->query("SELECT COUNT(DISTINCT DATE_FORMAT(generated_at,'%Y-%m-%d %H:%i')) FROM rebalancer_results WHERE user_id={$user_id} AND rebalance_type='mutual_fund' AND DATE(generated_at)=CURDATE()")->fetchColumn();
if ($today_runs >= 3) {
    exit(json_encode(['success'=>false,'error'=>'Daily limit reached (3 runs/day). Try again tomorrow.']));
}

try {

// Auto-reclassify holdings using fund name patterns
reclassify_portfolio($user_id, $db);

// 1. User profile
$prof_stmt = $db->prepare("SELECT risk_profile, life_stage, annual_income FROM user_profiles WHERE user_id=:uid ORDER BY id DESC LIMIT 1");
$prof_stmt->execute([':uid'=>$user_id]);
$profile = $prof_stmt->fetch() ?: [];

// 2. ALL MF holdings (no cap — batch processing handles large portfolios)
$hold_stmt = $db->prepare(
    "SELECT fund_name, fund_house, fund_type, invested_amount, current_value,
            units_held, avg_nav, current_nav, purchase_date, sip_active, sip_amount
     FROM portfolio_entries
     WHERE user_id=:uid
     AND fund_type IN ('equity','debt','hybrid','elss','index','international','liquid','gold')
     ORDER BY current_value DESC"
);
$hold_stmt->execute([':uid'=>$user_id]);
$all_holdings = $hold_stmt->fetchAll();

if (empty($all_holdings)) {
    exit(json_encode(['success'=>false,'error'=>'No mutual fund holdings found. Add holdings via Portfolio page or upload a CAS statement via PrimoAI.']));
}

// 3. Advisor recommended funds (for comparison)
$rec_stmt = $db->query("SELECT fund_name, fund_house, category, risk_level, return_1yr, return_3yr, return_5yr, expense_ratio FROM fund_recommendations WHERE is_active=1 ORDER BY is_featured DESC LIMIT 10");
$rec_funds = $rec_stmt->fetchAll();

$rec_text = '';
foreach ($rec_funds as $r) {
    $rec_text .= "  – {$r['fund_name']} ({$r['category']}, {$r['risk_level']}) 1Y:{$r['return_1yr']}% 3Y:{$r['return_3yr']}%\n";
}

// Profile context
$risk_profile = ucfirst($profile['risk_profile'] ?? 'moderate');
$life_stage   = ucfirst($profile['life_stage']   ?? 'growth');
$target_alloc = match(strtolower($profile['risk_profile'] ?? 'moderate')) {
    'conservative' => 'Equity MF 30% / Debt MF 60% / Others 10%',
    'aggressive'   => 'Equity MF 80% / Debt MF 15% / Others 5%',
    default        => 'Equity MF 60% / Debt MF 30% / Others 10%',
};

$total_invested = array_sum(array_column($all_holdings, 'invested_amount'));
$total_current  = array_sum(array_column($all_holdings, 'current_value'));
$total_val_fmt  = '₹' . number_format($total_current, 0);
$total_count    = count($all_holdings);

// ── Helper: build compact holdings text ──────────────────────────────
function build_holdings_text(array $holdings, float $total_current): string {
    $text = '';
    foreach ($holdings as $i => $h) {
        $gain_pct = $h['invested_amount'] > 0
            ? round((($h['current_value'] - $h['invested_amount']) / $h['invested_amount']) * 100, 1) : 0;
        $weight  = $total_current > 0 ? round(($h['current_value'] / $total_current) * 100, 1) : 0;
        $display = fund_type_display($h['fund_type'], $h['fund_name']);
        $sip     = $h['sip_active'] ? ' SIP₹' . number_format((float)$h['sip_amount'], 0) : '';
        $text .= sprintf(
            "%d. %-40s [%s] W:%-5s%% Inv:₹%-8s Val:₹%-8s G:%s%s%%%s\n",
            $i + 1,
            mb_substr($h['fund_name'], 0, 39),
            mb_substr($display, 0, 22),
            $weight,
            number_format((float)$h['invested_amount'], 0),
            number_format((float)$h['current_value'], 0),
            $gain_pct >= 0 ? '+' : '', $gain_pct,
            $sip
        );
    }
    return $text;
}

// ── Helper: one LLM call (Claude → Gemini fallback) ──────────────────
function call_claude(string $system, string $user_msg): mixed {
    $llm = call_llm($system, [['role' => 'user', 'content' => $user_msg]], 6000);
    $raw = $llm['text'];
    error_log("MF rebalancer response via {$llm['model']} (300): " . substr($raw, 0, 300));
    return extract_json_from_claude($raw);
}

// ── SYSTEM PROMPT ─────────────────────────────────────────────────────
$system = "You are a senior mutual fund portfolio analyst for Prime Financials, an AMFI registered MF distributor in India.

Target allocation for risk profile:
Conservative: Equity MF 30% / Debt MF 60% / Others 10%
Moderate:     Equity MF 60% / Debt MF 30% / Others 10%
Aggressive:   Equity MF 80% / Debt MF 15% / Others 5%

For each fund evaluate: performance vs category, overlap, weight concentration (flag >20%), expense ratio, category fit for risk profile, SIP continuation.

Verdict options: hold / buy_more / switch / sell
SIP options: continue / increase / decrease / stop

Your reply MUST start with { and end with }. No markdown fences, no explanation. JSON only.";

// ── PROCESS: single call if ≤20, batched if >20 ───────────────────────
$all_verdicts  = [];
$overall_data  = null;

$batch_size = 20;
$batches    = array_chunk($all_holdings, $batch_size);
$num_batches = count($batches);

if ($num_batches === 1) {
    // Single call — full output
    $holdings_text = build_holdings_text($all_holdings, $total_current);
    $user_msg = "CLIENT: Risk={$risk_profile} | Life Stage={$life_stage} | Total={$total_val_fmt} | {$total_count} funds\nTarget: {$target_alloc}\n\nHOLDINGS:\n{$holdings_text}\nADVISOR RECOMMENDED FUNDS:\n{$rec_text}\nReturn JSON:\n{\"overall_health\":\"good|fair|needs_attention\",\"overall_score\":0-100,\"summary\":\"...\",\"current_allocation\":{\"equity_pct\":0,\"debt_pct\":0,\"others_pct\":0},\"target_allocation\":{\"equity_pct\":0,\"debt_pct\":0,\"others_pct\":0},\"holdings\":[{\"fund_name\":\"\",\"verdict\":\"\",\"verdict_label\":\"\",\"confidence\":\"high|medium|low\",\"priority\":\"urgent|moderate|low\",\"return_assessment\":\"above_average|average|below_average\",\"weight_in_portfolio_pct\":0,\"reason\":\"\",\"action_detail\":\"\",\"sip_recommendation\":\"continue|increase|decrease|stop\"}],\"rebalancing_actions\":[{\"action_type\":\"\",\"from_fund\":\"\",\"to_fund\":\"\",\"reason\":\"\",\"urgency\":\"urgent|moderate|low\"}],\"disclaimer\":\"\"}";

    $result = call_claude($system, $user_msg);
    if (!is_array($result)) {
        exit(json_encode(['success'=>false,'error'=>'AI returned unexpected format. Please try again.']));
    }
    $overall_data = $result;
    $all_verdicts = $result['holdings'] ?? [];

} else {
    // Batched: get verdicts per batch, then synthesise
    $batch_system = "You are a mutual fund portfolio analyst. For each fund in the list, return ONLY a JSON array of verdict objects. No overall summary — just the holdings array. Start with [ end with ].";

    foreach ($batches as $b_idx => $batch) {
        $bt = build_holdings_text($batch, $total_current);
        $b_msg = "CLIENT: Risk={$risk_profile} | Total portfolio={$total_val_fmt}\nTarget allocation: {$target_alloc}\n\nBATCH " . ($b_idx+1) . "/{$num_batches} — analyse these " . count($batch) . " funds:\n{$bt}\nReturn JSON array:\n[{\"fund_name\":\"\",\"verdict\":\"hold|buy_more|switch|sell\",\"verdict_label\":\"\",\"confidence\":\"high|medium|low\",\"priority\":\"urgent|moderate|low\",\"return_assessment\":\"above_average|average|below_average\",\"weight_in_portfolio_pct\":0,\"reason\":\"\",\"action_detail\":\"\",\"sip_recommendation\":\"continue|increase|decrease|stop\"}]";

        $batch_result = call_claude($batch_system, $b_msg);
        if (is_array($batch_result)) {
            // Might be {holdings:[...]} or just [...]
            $verdicts = isset($batch_result[0]) ? $batch_result : ($batch_result['holdings'] ?? []);
            $all_verdicts = array_merge($all_verdicts, $verdicts);
        }
    }

    // Synthesis call: overall assessment from all verdicts
    $verdicts_summary = '';
    foreach ($all_verdicts as $v) {
        $verdicts_summary .= "  {$v['fund_name']}: {$v['verdict_label']} — {$v['reason']}\n";
    }
    $synth_system = "You are a senior mutual fund portfolio analyst. Given individual fund verdicts, provide an overall portfolio assessment. Your reply MUST start with { and end with }. JSON only, no markdown.";
    $synth_msg = "CLIENT: Risk={$risk_profile} | Life Stage={$life_stage} | Total={$total_val_fmt} | {$total_count} funds\nTarget: {$target_alloc}\n\nINDIVIDUAL FUND VERDICTS:\n{$verdicts_summary}\nReturn JSON (no 'holdings' key needed — just overall assessment):\n{\"overall_health\":\"good|fair|needs_attention\",\"overall_score\":0-100,\"summary\":\"3 sentence overall portfolio assessment\",\"current_allocation\":{\"equity_pct\":0,\"debt_pct\":0,\"others_pct\":0},\"target_allocation\":{\"equity_pct\":0,\"debt_pct\":0,\"others_pct\":0},\"rebalancing_actions\":[{\"action_type\":\"\",\"from_fund\":\"\",\"to_fund\":\"\",\"reason\":\"\",\"urgency\":\"urgent|moderate|low\"}],\"disclaimer\":\"Mutual Fund investments are subject to market risks.\"}";

    $synth = call_claude($synth_system, $synth_msg);

    $overall_data = is_array($synth) ? $synth : [
        'overall_health'      => 'fair',
        'overall_score'       => 65,
        'summary'             => 'Portfolio analysis complete. Review individual fund verdicts above.',
        'current_allocation'  => ['equity_pct' => 0, 'debt_pct' => 0, 'others_pct' => 0],
        'target_allocation'   => ['equity_pct' => 60, 'debt_pct' => 30, 'others_pct' => 10],
        'rebalancing_actions' => [],
        'disclaimer'          => 'Mutual Fund investments are subject to market risks. Past performance is not indicative of future returns. Prime Financials — AMFI Registered MF Distributor.',
    ];
    $overall_data['holdings'] = $all_verdicts;
}

$result = $overall_data;

// Save verdicts to DB
try {
    $ins = $db->prepare("INSERT INTO rebalancer_results (user_id,rebalance_type,holding_name,verdict,verdict_label,confidence,reason,action_detail,priority) VALUES (:uid,'mutual_fund',:name,:verdict,:label,:conf,:reason,:action,:pri)");
    foreach ($result['holdings'] ?? [] as $h) {
        $ins->execute([
            ':uid'    => $user_id,
            ':name'   => mb_substr($h['fund_name'] ?? '', 0, 200),
            ':verdict'=> in_array($h['verdict']??'',['hold','buy_more','switch','sell','accumulate','reduce','exit','review']) ? $h['verdict'] : 'hold',
            ':label'  => mb_substr($h['verdict_label'] ?? 'HOLD', 0, 50),
            ':conf'   => in_array($h['confidence']??'',['high','medium','low']) ? $h['confidence'] : 'medium',
            ':reason' => $h['reason'] ?? '',
            ':action' => $h['action_detail'] ?? null,
            ':pri'    => in_array($h['priority']??'',['urgent','moderate','low']) ? $h['priority'] : 'low',
        ]);
    }
} catch (PDOException $e) { error_log('MF Rebalancer save error: ' . $e->getMessage()); }

echo json_encode(['success' => true, 'data' => $result, 'total_funds' => $total_count, 'batches' => $num_batches]);

} catch (Throwable $e) {
    error_log('MF Rebalancer error user_id=' . $user_id . ': ' . $e->getMessage());
    http_response_code(500);
    $user_msg = str_contains($e->getMessage(), 'API connection error') || str_contains($e->getMessage(), 'timed out')
        ? 'AI service temporarily unavailable. Please try again in a minute.'
        : 'Analysis failed. Please try again.';
    echo json_encode(['success' => false, 'error' => $user_msg]);
}
