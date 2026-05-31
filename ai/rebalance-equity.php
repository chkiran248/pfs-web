<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/ai-helpers.php';
require_once __DIR__ . '/fund-classifier.php';
set_time_limit(300);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['success'=>false,'error'=>'Method not allowed'])); }
if (!is_logged_in()) { http_response_code(401); exit(json_encode(['success'=>false,'error'=>'Unauthorised'])); }
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { http_response_code(403); exit(json_encode(['success'=>false,'error'=>'Invalid CSRF token'])); }

$user_id = get_user_id();
$db      = get_db();

// Rate limit: 5 equity runs per day
$today_runs = (int)$db->query("SELECT COUNT(*) FROM rebalancer_results WHERE user_id={$user_id} AND rebalance_type='equity' AND DATE(generated_at)=CURDATE()")->fetchColumn();
if ($today_runs >= 5) {
    exit(json_encode(['success'=>false,'error'=>'Daily limit reached (5 runs/day). Try again tomorrow.']));
}

// 1. All 'equity' type holdings
$hold_stmt = $db->prepare("SELECT fund_name, fund_house, fund_type, invested_amount, current_value, units_held, avg_nav, current_nav, purchase_date FROM portfolio_entries WHERE user_id=:uid AND fund_type='equity' ORDER BY current_value DESC LIMIT 50");
$hold_stmt->execute([':uid'=>$user_id]);
$all_equity = $hold_stmt->fetchAll();

if (empty($all_equity)) {
    exit(json_encode(['success'=>false,'error'=>'No equity holdings found. Add stock holdings via Portfolio page.']));
}

// Filter to actual individual stocks (exclude mutual fund names)
$fund_keywords = ['fund','etf','scheme','plan growth','regular growth','direct growth','idcw','fof','index fund','liquid','overnight'];
$holdings = array_filter($all_equity, function($h) use ($fund_keywords) {
    $lower = strtolower($h['fund_name']);
    foreach ($fund_keywords as $kw) {
        if (str_contains($lower, $kw)) return false;
    }
    return true;
});
$holdings = array_values($holdings);

// If no stocks found after filtering (all are MFs), notify the user
if (empty($holdings)) {
    exit(json_encode([
        'success' => false,
        'error'   => 'Your equity holdings appear to be mutual funds (HDFC Flexi Cap, Nippon India, etc.). Please use the Mutual Fund Rebalancer on the left instead. The Equity Analyser is for individual stocks like INFY, RELIANCE, HDFCBANK.'
    ]));
}

// Cap at 25 holdings to keep prompt manageable
$holdings = array_slice($holdings, 0, 25);

// 2. Stock research notes
$res_stmt = $db->query("SELECT company_name, ticker_symbol, sector, market_cap_type, analyst_view, price_at_report, report_date FROM stock_research WHERE is_published=1 ORDER BY report_date DESC LIMIT 20");
$research = $res_stmt->fetchAll();

// 3. Latest stock prices
$price_stmt = $db->query("SELECT ticker, close_price, price_date FROM stock_prices WHERE price_date=(SELECT MAX(price_date) FROM stock_prices)");
$prices_raw = $price_stmt->fetchAll();
$prices = [];
foreach ($prices_raw as $p) { $prices[strtoupper($p['ticker'])] = (float)$p['close_price']; }

// Build holdings text
$total_equity = array_sum(array_column($holdings, 'current_value'));

$holdings_text = '';
foreach ($holdings as $i => $h) {
    $gain_pct    = $h['invested_amount'] > 0 ? round((($h['current_value'] - $h['invested_amount']) / $h['invested_amount']) * 100, 1) : 0;
    $weight      = $total_equity > 0 ? round(($h['current_value'] / $total_equity) * 100, 1) : 0;
    $days_held   = $h['purchase_date'] ? (int)((time() - strtotime($h['purchase_date'])) / 86400) : 0;
    $tax_type    = $days_held > 365 ? 'LTCG' : 'STCG';
    $holdings_text .= sprintf(
        "%d. %-35s Weight:%-5s%% Invested:₹%-7s Value:₹%-7s Gain:%s%s%% Held:%dd [%s]\n",
        $i+1, mb_substr($h['fund_name'],0,34),
        $weight,
        number_format((float)$h['invested_amount'],0),
        number_format((float)$h['current_value'],0),
        $gain_pct>=0?'+':'',$gain_pct,
        $days_held, $tax_type
    );
}

// Research notes
$research_text = '';
foreach ($research as $r) {
    $research_text .= "  - {$r['company_name']} ({$r['ticker_symbol']}) {$r['sector']}: {$r['analyst_view']}\n";
}

$system = "You are a research analyst providing EDUCATIONAL portfolio analysis for Prime Financials clients. This is NOT investment advice. Prime Financials is NOT a SEBI RIA.

For each stock evaluate:
1. Position sizing — flag if > 10% of equity portfolio (single stock concentration)
2. Sector concentration — flag if sector > 30% of portfolio
3. Gain/loss status — LTCG/STCG implications, tax harvesting opportunities
4. Holding period context
5. Cross-reference with any available advisor research notes
6. Portfolio diversification (flag if < 5 stocks or > 25 stocks)

Use educational, research language. Never say 'I recommend you sell X'. Say 'This position may warrant review given...'

RESPONSE: Your reply MUST start with { and end with }. No markdown fences, no explanation before or after. Exact structure:
{
  \"overall_assessment\": \"well_diversified|moderately_concentrated|concentrated\",
  \"overall_score\": 68,
  \"summary\": \"2-3 sentence educational summary\",
  \"sector_breakdown\": [
    {\"sector\": \"Banking\", \"allocation_pct\": 32, \"flag\": true, \"flag_reason\": \"Sector concentration above 30% guideline\"}
  ],
  \"holdings\": [
    {
      \"stock_name\": \"Infosys Ltd\",
      \"ticker\": \"INFY\",
      \"verdict\": \"hold\",
      \"verdict_label\": \"HOLD\",
      \"confidence\": \"medium\",
      \"priority\": \"low\",
      \"weight_in_equity_pct\": 12.5,
      \"unrealised_gain_pct\": 45.2,
      \"holding_period_days\": 420,
      \"tax_note\": \"LTCG applicable — gains above ₹1.25L taxed at 12.5%\",
      \"reason\": \"Educational observation about this holding.\",
      \"action_detail\": \"No action suggested at this time.\",
      \"has_research_note\": false
    }
  ],
  \"concentration_alerts\": [
    {\"alert_type\": \"sector_overweight\", \"description\": \"Banking at 32% above 30% guideline\", \"suggestion\": \"Consider diversifying on new investments\"}
  ],
  \"tax_opportunities\": [
    {\"type\": \"ltcg_harvesting\", \"description\": \"Consider booking ₹1.25L gains tax-free before March 31.\", \"stocks_involved\": [\"INFY\"]}
  ],
  \"disclaimer\": \"This analysis is for educational purposes only. Prime Financials is NOT a SEBI Registered Investment Advisor. Not personalised investment advice. Consult a SEBI RIA before any decisions.\"
}";

$user_prompt = "EQUITY PORTFOLIO ANALYSIS REQUEST\n\nHOLDINGS ({$total_equity} total value):\n{$holdings_text}\n" .
    ($research_text ? "\nADVISOR RESEARCH NOTES AVAILABLE:\n{$research_text}\n" : '') .
    "\nProvide educational portfolio analysis. Identify concentration risks, tax opportunities, and sectors. Use soft language appropriate for a research note, not personalised advice.";

$payload = [
    'model'      => PRIMO_MODEL,
    'max_tokens' => 6000,
    'system'     => $system,
    'messages'   => [['role'=>'user','content'=>$user_prompt]],
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json','x-api-key: '.CLAUDE_API_KEY,'anthropic-version: 2023-06-01'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 60,
]);
$response = curl_exec($ch);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err) exit(json_encode(['success'=>false,'error'=>'AI service unavailable. Please try again.']));

$api_data = json_decode($response, true);
if (isset($api_data['error'])) exit(json_encode(['success'=>false,'error'=>$api_data['error']['message']??'Claude API error']));

$raw    = trim($api_data['content'][0]['text'] ?? '');
$result = extract_json_from_claude($raw);

if (!is_array($result)) {
    error_log('Equity Rebalancer parse fail. Raw: ' . substr($raw, 0, 500));
    exit(json_encode(['success'=>false,'error'=>'AI returned an unexpected format. Please try again in a moment.']));
}

// Save to DB
try {
    $ins = $db->prepare("INSERT INTO rebalancer_results (user_id,rebalance_type,holding_name,verdict,verdict_label,confidence,reason,action_detail,priority) VALUES (:uid,'equity',:name,:verdict,:label,:conf,:reason,:action,:pri)");
    foreach ($result['holdings'] ?? [] as $h) {
        $ins->execute([
            ':uid'    => $user_id,
            ':name'   => mb_substr($h['stock_name']??'', 0, 200),
            ':verdict'=> in_array($h['verdict']??'',['hold','buy_more','switch','sell','accumulate','reduce','exit','review'])?$h['verdict']:'hold',
            ':label'  => mb_substr($h['verdict_label']??'HOLD', 0, 50),
            ':conf'   => in_array($h['confidence']??'',['high','medium','low'])?$h['confidence']:'medium',
            ':reason' => $h['reason'] ?? '',
            ':action' => $h['action_detail'] ?? null,
            ':pri'    => in_array($h['priority']??'',['urgent','moderate','low'])?$h['priority']:'low',
        ]);
    }
} catch (PDOException $e) { error_log('Equity rebalancer save error: '.$e->getMessage()); }

echo json_encode(['success'=>true,'data'=>$result]);
