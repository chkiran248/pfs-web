<?php
declare(strict_types=1);

// Shared MF rebalancer analysis logic -- used by BOTH the CLI-invoked queue
// processor (data-fetcher/process-rebalancer-queue.php, full portfolio, no
// execution-time ceiling) and the synchronous single-fund web endpoint
// (ai/rebalance-mf-single.php, always small/fast enough to run inline).
// Contains no HTTP-specific code (no headers, no CSRF, no session) so it can
// be called from a plain CLI script with no web request behind it at all.

require_once __DIR__ . '/ai-helpers.php';
require_once __DIR__ . '/fund-classifier.php';
require_once __DIR__ . '/../includes/fund-matcher.php';

const RB_BENCH_MAP = [
    'equity'        => 'nifty500',
    'elss'          => 'nifty500',
    'hybrid'        => 'nifty500',
    'index'         => 'nifty50',
    'international' => 'nifty500',
    'debt'          => 'crisil_short_dur',
    'liquid'        => 'crisil_short_dur',
    'gold'          => 'nifty500',
];

function rb_get_benchmark_cagr(PDO $db, string $benchmark, int $years): ?float {
    $l = $db->prepare("SELECT nav_value, nav_date FROM benchmark_nav WHERE benchmark = :b ORDER BY nav_date DESC LIMIT 1");
    $l->execute([':b' => $benchmark]);
    $latest = $l->fetch();
    if (!$latest || $latest['nav_value'] <= 0) return null;

    $target_date = date('Y-m-d', strtotime($latest['nav_date'] . " -{$years} years"));
    $o = $db->prepare("SELECT nav_value FROM benchmark_nav WHERE benchmark = :b AND nav_date <= :d ORDER BY nav_date DESC LIMIT 1");
    $o->execute([':b' => $benchmark, ':d' => $target_date]);
    $old = $o->fetch();
    if (!$old || $old['nav_value'] <= 0) return null;

    return round((pow((float)$latest['nav_value'] / (float)$old['nav_value'], 1.0 / $years) - 1.0) * 100, 1);
}

function rb_build_holdings_text(array $holdings, float $total_current, array $bench_cagr): string {
    $text = '';
    foreach ($holdings as $i => $h) {
        $gain_pct = $h['invested_amount'] > 0
            ? round((($h['current_value'] - $h['invested_amount']) / $h['invested_amount']) * 100, 1) : 0;
        $weight  = $total_current > 0 ? round(($h['current_value'] / $total_current) * 100, 1) : 0;
        $display = fund_type_display($h['fund_type'], $h['fund_name']);
        $sip     = $h['sip_active'] ? ' SIP₹' . number_format((float)$h['sip_amount'], 0) : '';

        if (isset($h['return_1yr']) && $h['return_1yr'] !== null || isset($h['return_3yr']) && $h['return_3yr'] !== null) {
            $r1  = isset($h['return_1yr']) && $h['return_1yr'] !== null ? round((float)$h['return_1yr'], 1) . '%' : 'N/A';
            $r3  = isset($h['return_3yr']) && $h['return_3yr'] !== null ? round((float)$h['return_3yr'], 1) . '%' : 'N/A';
            $bm  = RB_BENCH_MAP[$h['fund_type']] ?? 'nifty500';
            $b1  = isset($bench_cagr[$bm]) && $bench_cagr[$bm]['1yr'] !== null ? round($bench_cagr[$bm]['1yr'], 1) . '%' : 'N/A';
            $b3  = isset($bench_cagr[$bm]) && $bench_cagr[$bm]['3yr'] !== null ? round($bench_cagr[$bm]['3yr'], 1) . '%' : 'N/A';
            $perf = " | 1yr:{$r1} 3yr:{$r3} [bench({$bm}) 1yr:{$b1} 3yr:{$b3}]";
        } else {
            $perf = ' | returns:N/A(scheme unresolved)';
        }

        $text .= sprintf(
            "%d. %-40s [%s] W:%-5s%% Inv:₹%-8s Val:₹%-8s G:%s%s%%%s%s\n",
            $i + 1,
            mb_substr($h['fund_name'], 0, 39),
            mb_substr($display, 0, 22),
            $weight,
            number_format((float)$h['invested_amount'], 0),
            number_format((float)$h['current_value'], 0),
            $gain_pct >= 0 ? '+' : '', $gain_pct,
            $sip,
            $perf
        );
    }
    return $text;
}

function rb_call_claude(string $system, string $user_msg, int $max_tokens = 6000): mixed {
    $llm = call_llm($system, [['role' => 'user', 'content' => $user_msg]], $max_tokens, 'premium');
    $raw = $llm['text'];
    error_log("MF rebalancer response via {$llm['model']} (300): " . substr($raw, 0, 300));
    return extract_json_from_claude($raw);
}

/** Resolves missing scheme_code for a set of holdings in-place via nav_history fuzzy match. */
function rb_resolve_scheme_codes(PDO $db, int $user_id, array &$holdings): void {
    $needs_sc = array_filter($holdings, fn($h) => empty($h['scheme_code']));
    if (empty($needs_sc)) return;

    $latest_nav_date = $db->query("SELECT MAX(nav_date) FROM nav_history")->fetchColumn();
    if (!$latest_nav_date) return;

    $nh_stmt = $db->prepare("SELECT fund_code, LOWER(fund_name) AS lname FROM nav_history WHERE nav_date = ?");
    $nh_stmt->execute([$latest_nav_date]);
    $amfi_pool = $nh_stmt->fetchAll();

    $upd_sc = $db->prepare("UPDATE portfolio_entries SET scheme_code = ? WHERE user_id = ? AND fund_name = ? AND (scheme_code IS NULL OR scheme_code = '')");
    foreach ($holdings as &$h) {
        if (!empty($h['scheme_code'])) continue;
        $match = fuzzy_match_fund((string)$h['fund_name'], $amfi_pool, 'lname');
        if ($match) {
            $h['scheme_code'] = $match['fund_code'];
            $upd_sc->execute([$match['fund_code'], $user_id, $h['fund_name']]);
        }
    }
    unset($h);
}

const RB_SYSTEM_PROMPT = "You are a senior mutual fund portfolio analyst for Prime Financials, an AMFI registered MF distributor in India.

Target allocation for risk profile:
Conservative: Equity MF 30% / Debt MF 60% / Others 10%
Moderate:     Equity MF 60% / Debt MF 30% / Others 10%
Aggressive:   Equity MF 80% / Debt MF 15% / Others 5%

For each fund evaluate using the ACTUAL return data provided (1yr/3yr CAGR vs benchmark). Do NOT rely on training knowledge for fund performance — use only the numbers given. Compare each fund's 1yr and 3yr CAGR to its benchmark. Flag underperformers where fund CAGR trails benchmark by >2% over 3yr. Also evaluate: overlap (if two funds in same category), weight concentration (flag >20%), expense ratio, category fit for risk profile, SIP continuation. Where return data shows N/A, state clearly that performance could not be verified.

Keep each fund's \"reason\" and \"action_detail\" to ONE short sentence (15 words max) — this report may cover many funds and must stay concise.

Verdict options: hold / buy_more / switch / sell
SIP options: continue / increase / decrease / stop

Your reply MUST start with { and end with }. No markdown fences, no explanation. JSON only.";

/**
 * Full-portfolio MF rebalance analysis. No execution-time budget concerns --
 * intended to be called ONLY from the CLI-invoked queue processor, which has
 * no hosting-imposed timeout (unlike a normal web/FPM request).
 *
 * @return array ['result'=>array,'total_funds'=>int,'batches'=>int]
 * @throws RuntimeException with a user-facing message on failure
 */
function run_mf_rebalance_analysis(PDO $db, int $user_id): array {
    reclassify_portfolio($user_id, $db);

    $prof_stmt = $db->prepare("SELECT risk_profile, life_stage, annual_income FROM user_profiles WHERE user_id=:uid ORDER BY id DESC LIMIT 1");
    $prof_stmt->execute([':uid' => $user_id]);
    $profile = $prof_stmt->fetch() ?: [];

    // Zero-invested_amount rows excluded -- see note in ai/rebalance-mf-single.php
    // history: leftover CAS-extraction artifacts with nothing to rebalance.
    $hold_stmt = $db->prepare(
        "SELECT fund_name, fund_house, fund_type, invested_amount, current_value,
                units_held, avg_nav, current_nav, purchase_date, sip_active, sip_amount,
                scheme_code, return_1yr, return_3yr, perf_refreshed_at
         FROM portfolio_entries
         WHERE user_id=:uid
         AND asset_type='mutual_fund'
         AND invested_amount > 0
         ORDER BY current_value DESC"
    );
    $hold_stmt->execute([':uid' => $user_id]);
    $all_holdings = $hold_stmt->fetchAll();

    if (empty($all_holdings)) {
        throw new RuntimeException('No mutual fund holdings with an active investment found. Add holdings via Portfolio page or upload a CAS statement via PrimoAI.');
    }

    rb_resolve_scheme_codes($db, $user_id, $all_holdings);

    $bench_cagr = [];
    foreach (array_unique(array_column($all_holdings, 'fund_type')) as $ft) {
        $bm = RB_BENCH_MAP[$ft] ?? 'nifty500';
        if (!isset($bench_cagr[$bm])) {
            $bench_cagr[$bm] = [
                '1yr' => rb_get_benchmark_cagr($db, $bm, 1),
                '3yr' => rb_get_benchmark_cagr($db, $bm, 3),
            ];
        }
    }

    $rec_stmt = $db->query("SELECT fund_name, fund_house, category, risk_level, return_1yr, return_3yr, return_5yr, expense_ratio FROM fund_recommendations WHERE is_active=1 ORDER BY is_featured DESC LIMIT 10");
    $rec_funds = $rec_stmt->fetchAll();
    $rec_text = '';
    foreach ($rec_funds as $r) {
        $rec_text .= "  – {$r['fund_name']} ({$r['category']}, {$r['risk_level']}) 1Y:{$r['return_1yr']}% 3Y:{$r['return_3yr']}%\n";
    }

    $risk_profile = ucfirst($profile['risk_profile'] ?? 'moderate');
    $life_stage   = ucfirst($profile['life_stage']   ?? 'growth');
    $target_alloc = match (strtolower($profile['risk_profile'] ?? 'moderate')) {
        'conservative' => 'Equity MF 30% / Debt MF 60% / Others 10%',
        'aggressive'   => 'Equity MF 80% / Debt MF 15% / Others 5%',
        default        => 'Equity MF 60% / Debt MF 30% / Others 10%',
    };

    $total_invested = array_sum(array_column($all_holdings, 'invested_amount'));
    $total_current  = array_sum(array_column($all_holdings, 'current_value'));
    $total_val_fmt  = '₹' . number_format($total_current, 0);
    $total_count    = count($all_holdings);

    $all_verdicts = [];
    $overall_data = null;

    // Single call if <=15 holdings, batched (sequential) beyond that. A
    // single call covering the whole portfolio -- summary, allocation
    // breakdown, AND every fund's verdict -- needs roughly 150-300 output
    // tokens per fund on top of that overhead; past ~15-20 funds it reliably
    // burns through the 6000 max_tokens budget before finishing, producing
    // truncated/unparseable JSON (confirmed: a 40-fund run used all 6000
    // tokens and got cut off mid-summary, before the holdings array even
    // started). The batched path's lighter per-batch schema (just a verdict
    // array, no portfolio-level overhead) comfortably fits many more funds
    // per call. Batching was previously avoided here because this endpoint
    // ran synchronously inline with a web request capped at ~60s total --
    // several sequential batch calls could exceed that. Now that the full
    // analysis runs via the CLI queue processor (no execution-time ceiling),
    // that constraint is gone and batching is the correct default again.
    $batch_size  = 15;
    $batches     = array_chunk($all_holdings, $batch_size);
    $num_batches = count($batches);

    if ($num_batches === 1) {
        $holdings_text = rb_build_holdings_text($all_holdings, $total_current, $bench_cagr);
        $user_msg = "CLIENT: Risk={$risk_profile} | Life Stage={$life_stage} | Total={$total_val_fmt} | {$total_count} funds\nTarget: {$target_alloc}\n\nHOLDINGS:\n{$holdings_text}\nADVISOR RECOMMENDED FUNDS:\n{$rec_text}\nReturn JSON:\n{\"overall_health\":\"good|fair|needs_attention\",\"overall_score\":0-100,\"summary\":\"...\",\"current_allocation\":{\"equity_pct\":0,\"debt_pct\":0,\"others_pct\":0},\"target_allocation\":{\"equity_pct\":0,\"debt_pct\":0,\"others_pct\":0},\"holdings\":[{\"fund_name\":\"\",\"verdict\":\"\",\"verdict_label\":\"\",\"confidence\":\"high|medium|low\",\"priority\":\"urgent|moderate|low\",\"return_assessment\":\"above_average|average|below_average\",\"weight_in_portfolio_pct\":0,\"reason\":\"\",\"action_detail\":\"\",\"sip_recommendation\":\"continue|increase|decrease|stop\"}],\"rebalancing_actions\":[{\"action_type\":\"\",\"from_fund\":\"\",\"to_fund\":\"\",\"reason\":\"\",\"urgency\":\"urgent|moderate|low\"}],\"disclaimer\":\"\"}";

        $result = rb_call_claude(RB_SYSTEM_PROMPT, $user_msg);
        if (!is_array($result)) {
            throw new RuntimeException('AI returned unexpected format. Please try again.');
        }
        $overall_data = $result;
        $all_verdicts = $result['holdings'] ?? [];

    } else {
        $batch_system = "You are a mutual fund portfolio analyst. For each fund in the list, return ONLY a JSON array of verdict objects. No overall summary — just the holdings array. Keep \"reason\" and \"action_detail\" to ONE short sentence (15 words max). Start with [ end with ].";

        foreach ($batches as $b_idx => $batch) {
            $bt = rb_build_holdings_text($batch, $total_current, $bench_cagr);
            $b_msg = "CLIENT: Risk={$risk_profile} | Total portfolio={$total_val_fmt}\nTarget allocation: {$target_alloc}\n\nBATCH " . ($b_idx + 1) . "/{$num_batches} — analyse these " . count($batch) . " funds:\n{$bt}\nReturn JSON array:\n[{\"fund_name\":\"\",\"verdict\":\"hold|buy_more|switch|sell\",\"verdict_label\":\"\",\"confidence\":\"high|medium|low\",\"priority\":\"urgent|moderate|low\",\"return_assessment\":\"above_average|average|below_average\",\"weight_in_portfolio_pct\":0,\"reason\":\"\",\"action_detail\":\"\",\"sip_recommendation\":\"continue|increase|decrease|stop\"}]";

            $batch_result = rb_call_claude($batch_system, $b_msg);
            if (is_array($batch_result)) {
                $verdicts = isset($batch_result[0]) ? $batch_result : ($batch_result['holdings'] ?? []);
                $all_verdicts = array_merge($all_verdicts, $verdicts);
            }
        }

        if (empty($all_verdicts)) {
            throw new RuntimeException('AI analysis failed for all batches. Please try again.');
        }

        $verdicts_summary = '';
        foreach ($all_verdicts as $v) {
            $verdicts_summary .= "  {$v['fund_name']}: {$v['verdict_label']} — {$v['reason']}\n";
        }
        $synth_system = "You are a senior mutual fund portfolio analyst. Given individual fund verdicts, provide an overall portfolio assessment. Your reply MUST start with { and end with }. JSON only, no markdown.";
        $synth_msg = "CLIENT: Risk={$risk_profile} | Life Stage={$life_stage} | Total={$total_val_fmt} | {$total_count} funds\nTarget: {$target_alloc}\n\nINDIVIDUAL FUND VERDICTS:\n{$verdicts_summary}\nReturn JSON (no 'holdings' key needed — just overall assessment):\n{\"overall_health\":\"good|fair|needs_attention\",\"overall_score\":0-100,\"summary\":\"3 sentence overall portfolio assessment\",\"current_allocation\":{\"equity_pct\":0,\"debt_pct\":0,\"others_pct\":0},\"target_allocation\":{\"equity_pct\":0,\"debt_pct\":0,\"others_pct\":0},\"rebalancing_actions\":[{\"action_type\":\"\",\"from_fund\":\"\",\"to_fund\":\"\",\"reason\":\"\",\"urgency\":\"urgent|moderate|low\"}],\"disclaimer\":\"Mutual Fund investments are subject to market risks.\"}";

        $synth = rb_call_claude($synth_system, $synth_msg);

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

    try {
        $ins = $db->prepare("INSERT INTO rebalancer_results (user_id,rebalance_type,holding_name,verdict,verdict_label,confidence,reason,action_detail,priority) VALUES (:uid,'mutual_fund',:name,:verdict,:label,:conf,:reason,:action,:pri)");
        foreach ($result['holdings'] ?? [] as $h) {
            $ins->execute([
                ':uid'    => $user_id,
                ':name'   => mb_substr($h['fund_name'] ?? '', 0, 200),
                ':verdict'=> in_array($h['verdict'] ?? '', ['hold','buy_more','switch','sell','accumulate','reduce','exit','review']) ? $h['verdict'] : 'hold',
                ':label'  => mb_substr($h['verdict_label'] ?? 'HOLD', 0, 50),
                ':conf'   => in_array($h['confidence'] ?? '', ['high','medium','low']) ? $h['confidence'] : 'medium',
                ':reason' => $h['reason'] ?? '',
                ':action' => $h['action_detail'] ?? null,
                ':pri'    => in_array($h['priority'] ?? '', ['urgent','moderate','low']) ? $h['priority'] : 'low',
            ]);
        }
    } catch (PDOException $e) {
        error_log('MF Rebalancer save error: ' . $e->getMessage());
    }

    return ['result' => $result, 'total_funds' => $total_count, 'batches' => $num_batches];
}

/**
 * Single-fund MF analysis -- always one Claude call, small max_tokens, meant
 * to run synchronously inline within a normal web request (never queued).
 *
 * @return array verdict object: fund_name, verdict, verdict_label, confidence,
 *               priority, return_assessment, weight_in_portfolio_pct, reason,
 *               action_detail, sip_recommendation
 * @throws RuntimeException with a user-facing message on failure
 */
function run_mf_single_fund_analysis(PDO $db, int $user_id, int $holding_id): array {
    $stmt = $db->prepare(
        "SELECT id, fund_name, fund_house, fund_type, invested_amount, current_value,
                units_held, avg_nav, current_nav, purchase_date, sip_active, sip_amount,
                scheme_code, return_1yr, return_3yr
         FROM portfolio_entries
         WHERE id=:id AND user_id=:uid
         AND asset_type='mutual_fund'
         AND invested_amount > 0"
    );
    $stmt->execute([':id' => $holding_id, ':uid' => $user_id]);
    $h = $stmt->fetch();
    if (!$h) {
        throw new RuntimeException('Holding not found or has no active investment.');
    }

    $holdings = [$h];
    rb_resolve_scheme_codes($db, $user_id, $holdings);
    $h = $holdings[0];

    $tot_stmt = $db->prepare(
        "SELECT COALESCE(SUM(current_value),0) FROM portfolio_entries
         WHERE user_id=:uid AND asset_type='mutual_fund'
         AND invested_amount > 0"
    );
    $tot_stmt->execute([':uid' => $user_id]);
    $total_current = (float)$tot_stmt->fetchColumn();

    $bm = RB_BENCH_MAP[$h['fund_type']] ?? 'nifty500';
    $bench_cagr = [$bm => [
        '1yr' => rb_get_benchmark_cagr($db, $bm, 1),
        '3yr' => rb_get_benchmark_cagr($db, $bm, 3),
    ]];

    $prof_stmt = $db->prepare("SELECT risk_profile FROM user_profiles WHERE user_id=:uid ORDER BY id DESC LIMIT 1");
    $prof_stmt->execute([':uid' => $user_id]);
    $risk_profile = ucfirst((string)($prof_stmt->fetchColumn() ?: 'moderate'));

    $holdings_text = rb_build_holdings_text([$h], $total_current, $bench_cagr);

    $system = "You are a senior mutual fund analyst for Prime Financials, an AMFI registered MF distributor in India. Evaluate ONE fund using the ACTUAL return data provided (1yr/3yr CAGR vs benchmark) — do not rely on training knowledge for fund performance. Flag if it trails its benchmark by >2% over 3yr, assess category fit for the client's risk profile, and give a clear SIP recommendation. Where return data shows N/A, state clearly that performance could not be verified. Keep \"reason\" and \"action_detail\" to ONE short sentence (15 words max) each. Verdict options: hold / buy_more / switch / sell. SIP options: continue / increase / decrease / stop. Your reply MUST start with { and end with }. No markdown fences, no explanation. JSON only.";

    $user_msg = "CLIENT RISK PROFILE: {$risk_profile}\n\nFUND:\n{$holdings_text}\nReturn JSON:\n{\"fund_name\":\"\",\"verdict\":\"hold|buy_more|switch|sell\",\"verdict_label\":\"\",\"confidence\":\"high|medium|low\",\"priority\":\"urgent|moderate|low\",\"return_assessment\":\"above_average|average|below_average\",\"weight_in_portfolio_pct\":0,\"reason\":\"\",\"action_detail\":\"\",\"sip_recommendation\":\"continue|increase|decrease|stop\"}";

    $verdict = rb_call_claude($system, $user_msg, 600);
    if (!is_array($verdict)) {
        throw new RuntimeException('AI returned unexpected format. Please try again.');
    }

    try {
        $ins = $db->prepare("INSERT INTO rebalancer_results (user_id,rebalance_type,holding_name,verdict,verdict_label,confidence,reason,action_detail,priority) VALUES (:uid,'mutual_fund_single',:name,:verdict,:label,:conf,:reason,:action,:pri)");
        $ins->execute([
            ':uid'    => $user_id,
            ':name'   => mb_substr($verdict['fund_name'] ?? $h['fund_name'], 0, 200),
            ':verdict'=> in_array($verdict['verdict'] ?? '', ['hold','buy_more','switch','sell']) ? $verdict['verdict'] : 'hold',
            ':label'  => mb_substr($verdict['verdict_label'] ?? 'HOLD', 0, 50),
            ':conf'   => in_array($verdict['confidence'] ?? '', ['high','medium','low']) ? $verdict['confidence'] : 'medium',
            ':reason' => $verdict['reason'] ?? '',
            ':action' => $verdict['action_detail'] ?? null,
            ':pri'    => in_array($verdict['priority'] ?? '', ['urgent','moderate','low']) ? $verdict['priority'] : 'low',
        ]);
    } catch (PDOException $e) {
        error_log('MF Single Rebalancer save error: ' . $e->getMessage());
    }

    return $verdict;
}
