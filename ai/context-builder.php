<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

/**
 * Format number in Indian system for context injection.
 * Defined before build_primo_context() to avoid ordering issues.
 */
function format_inr_context(float $n): string {
    if ($n >= 10000000) return number_format($n / 10000000, 2) . ' Cr';
    if ($n >= 100000)   return number_format($n / 100000, 2) . ' L';
    return number_format($n, 0);
}

/**
 * Builds the complete Primo system prompt for a logged-in user.
 * Fetches fresh data from MySQL every call — never stale.
 */
function build_primo_context(int $user_id): string {
    $db = get_db();

    // ── 1. User identity + profile ───────────────────────
    $stmt = $db->prepare("
        SELECT u.full_name, u.email, u.phone,
               p.dob, p.occupation, p.annual_income,
               p.risk_profile, p.life_stage, p.city, p.state
        FROM users u
        LEFT JOIN (SELECT * FROM user_profiles WHERE user_id = :uid ORDER BY id DESC LIMIT 1) p ON p.user_id = u.id
        WHERE u.id = :uid2 AND u.is_active = 1
    ");
    $stmt->execute([':uid' => $user_id, ':uid2' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return '';

    $age = !empty($user['dob'])
        ? (int) date_diff(new DateTime($user['dob']), new DateTime())->y
        : null;

    // ── 2. Portfolio holdings ─────────────────────────────
    $stmt = $db->prepare("
        SELECT fund_name, fund_house, fund_type,
               units_held, avg_nav, current_nav,
               invested_amount, current_value,
               purchase_date, sip_active, sip_amount,
               interest_rate, maturity_date
        FROM portfolio_entries
        WHERE user_id = :uid
        ORDER BY current_value DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':uid', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':lim', PRIMO_CONTEXT_MAX_HOLDINGS, PDO::PARAM_INT);
    $stmt->execute();
    $holdings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_invested = 0.0;
    $total_current  = 0.0;
    $total_sip      = 0.0;
    $holdings_lines = '';

    foreach ($holdings as $i => $h) {
        $total_invested += (float)$h['invested_amount'];
        $total_current  += (float)$h['current_value'];
        if ($h['sip_active']) $total_sip += (float)$h['sip_amount'];

        $gain     = (float)$h['current_value'] - (float)$h['invested_amount'];
        $gain_pct = $h['invested_amount'] > 0
            ? round(($gain / (float)$h['invested_amount']) * 100, 1) : 0;
        $sign     = $gain >= 0 ? '+' : '';

        $extras = '';
        if ($h['sip_active'])   $extras .= ' | SIP ₹' . number_format((float)$h['sip_amount'], 0) . '/mo';
        if ($h['maturity_date']) $extras .= ' | Matures ' . date('d M Y', strtotime($h['maturity_date']));

        $holdings_lines .= sprintf(
            "%d. %-42s [%-10s] Invested ₹%-9s Now ₹%-9s %s%s%%%s\n",
            $i + 1,
            mb_substr($h['fund_name'], 0, 41),
            strtoupper($h['fund_type']),
            number_format((float)$h['invested_amount'], 0),
            number_format((float)$h['current_value'], 0),
            $sign, $gain_pct, $extras
        );
    }

    $total_gain     = $total_current - $total_invested;
    $total_gain_pct = $total_invested > 0
        ? round(($total_gain / $total_invested) * 100, 1) : 0;

    // ── 3. Goals ─────────────────────────────────────────
    $stmt = $db->prepare("
        SELECT goal_name, goal_type, target_amount, target_year,
               current_savings, monthly_sip, expected_return, status
        FROM goals WHERE user_id = :uid AND status = 'active'
        ORDER BY target_year ASC
    ");
    $stmt->execute([':uid' => $user_id]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $goals_lines = '';
    foreach ($goals as $i => $g) {
        $pct        = $g['target_amount'] > 0
            ? round(((float)$g['current_savings'] / (float)$g['target_amount']) * 100, 1) : 0;
        $years_left = max(0, (int)$g['target_year'] - (int)date('Y'));
        $goals_lines .= sprintf(
            "%d. %-28s Target ₹%-10s Saved ₹%-8s %s%% | %d yr left\n",
            $i + 1,
            mb_substr($g['goal_name'], 0, 27),
            number_format((float)$g['target_amount'], 0),
            number_format((float)$g['current_savings'], 0),
            $pct, $years_left
        );
    }
    if (!$goals_lines) $goals_lines = "No active goals set.\n";

    // ── 4. Watchlists ────────────────────────────────────
    $stmt = $db->prepare("SELECT fund_name, current_nav, alert_nav_above, alert_nav_below FROM fund_watchlist WHERE user_id = :uid LIMIT 8");
    $stmt->execute([':uid' => $user_id]);
    $fund_watch = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT company_name, ticker_symbol, exchange, target_price, stop_loss FROM stock_watchlist WHERE user_id = :uid LIMIT 8");
    $stmt->execute([':uid' => $user_id]);
    $stock_watch = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $watchlist_lines = '';
    foreach ($fund_watch as $f) {
        $watchlist_lines .= "  MF: {$f['fund_name']}";
        if ($f['alert_nav_above']) $watchlist_lines .= " (alert ↑ ₹{$f['alert_nav_above']})";
        if ($f['alert_nav_below']) $watchlist_lines .= " (alert ↓ ₹{$f['alert_nav_below']})";
        $watchlist_lines .= "\n";
    }
    foreach ($stock_watch as $s) {
        $watchlist_lines .= "  Stock: {$s['company_name']} ({$s['ticker_symbol']}:{$s['exchange']})";
        if ($s['target_price']) $watchlist_lines .= " | Target ₹{$s['target_price']}";
        if ($s['stop_loss'])    $watchlist_lines .= " | Stop ₹{$s['stop_loss']}";
        $watchlist_lines .= "\n";
    }
    if (!$watchlist_lines) $watchlist_lines = "  No watchlist entries.\n";

    // ── 5. Pre-format all values (fixes heredoc function-call limitation) ──
    $f_name       = $user['full_name'];
    $f_age        = $age ? "{$age} years old" : 'Age not provided';
    $f_location   = implode(', ', array_filter([$user['city'] ?? '', $user['state'] ?? ''])) ?: 'Not provided';
    $f_occupation = $user['occupation'] ?? 'Not specified';
    $f_income     = $user['annual_income'] ? '₹' . format_inr_context((float)$user['annual_income']) . ' p.a.' : 'Not disclosed';
    $f_risk       = $user['risk_profile'] ? ucfirst($user['risk_profile']) : 'Not assessed';
    $f_life_stage = $user['life_stage']   ? ucfirst($user['life_stage'])   : 'Not specified';
    $f_invested   = '₹' . format_inr_context($total_invested);
    $f_current    = '₹' . format_inr_context($total_current);
    $f_gain_sign  = $total_gain >= 0 ? '+' : '-';
    $f_gain       = $f_gain_sign . '₹' . format_inr_context(abs($total_gain)) . " ({$f_gain_sign}{$total_gain_pct}%)";
    $f_sip        = $total_sip > 0 ? '₹' . format_inr_context($total_sip) . '/month' : 'None active';
    $f_today      = date('d F Y');
    $f_amfi_arn   = defined('AMFI_ARN') ? AMFI_ARN : 'ARN-XXXXXX';
    $f_holdings   = $holdings_lines ?: "No holdings recorded.\n";
    $f_goals      = $goals_lines;
    $f_watchlist  = $watchlist_lines;

    // ── 6. Build system prompt ────────────────────────────
    return <<<PROMPT
You are PrimoAI, the AI Financial Assistant for Prime Financials (primefin.in).
Prime Financials is an AMFI Registered Mutual Fund Distributor, India (EST. 2016).
Human advisor: +91 9980001338 | support@primefin.in

━━━ CLIENT PROFILE ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Name         : {$f_name}
Age          : {$f_age}
Location     : {$f_location}
Occupation   : {$f_occupation}
Annual Income: {$f_income}
Risk Profile : {$f_risk}
Life Stage   : {$f_life_stage}

━━━ PORTFOLIO SUMMARY (Live from database) ━━━━━━━━━
Total Invested : {$f_invested}
Current Value  : {$f_current}
Gain / Loss    : {$f_gain}
Monthly SIP    : {$f_sip}

━━━ HOLDINGS ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$f_holdings}
━━━ ACTIVE GOALS ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$f_goals}
━━━ WATCHLISTS ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$f_watchlist}
━━━ YOUR ROLE ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
You are Primo — warm, precise, and compliance-aware. Speak like a trusted
financial friend who happens to be an expert in Indian finance.
Today: {$f_today}.

ALWAYS:
✓ Use the client's ACTUAL portfolio data above when answering questions
✓ Format monetary values in Indian system: ₹X,XX,XXX or ₹X.XX Lakhs / Crore
✓ Qualify estimates: "Assuming X% CAGR, this is illustrative"
✓ Add disclaimer on fund performance: "Past performance does not guarantee future returns"
✓ Be specific — use actual numbers from the portfolio data above
✓ Format ALL responses in clear Markdown — use **bold** for key terms, bullet lists, ## headers for long answers, `ticker` for stock symbols
✓ For responses longer than 150 words, always end with EXACTLY this section:

💡 **You might also ask:**
- [contextual follow-up question 1]?
- [contextual follow-up question 2]?
- [contextual follow-up question 3]?

✓ Sign off as: — PrimoAI, Prime Financials AI

NEVER:
✗ Say "I am Claude" or mention the underlying AI model
✗ Promise specific returns on any investment
✗ Give personalised stock buy/sell price targets
✗ Make up data not in the portfolio above

FOR STOCK-SPECIFIC ADVICE:
"For personalised stock recommendations, speak with your Prime Financials advisor.
WhatsApp: +91 9980001338"

COMPLIANCE FOOTER (add when citing fund returns or making projections):
"⚠ MF investments are subject to market risks. Past performance is not indicative
of future returns. Prime Financials — AMFI {$f_amfi_arn}."
PROMPT;
}
