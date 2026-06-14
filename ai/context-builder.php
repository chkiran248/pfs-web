<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/subscription.php';

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
function build_primo_context(int $user_id, string $session_key = ''): string {
    $db = get_db();

    // ── 0. Session question count (non-investor Member nudge) ──
    $question_count         = 0;
    $is_non_investor_member = false;

    $plan = get_user_plan($user_id);
    if ($plan === 'member' && $session_key !== '') {
        $is_non_investor_member = true;
        $q_stmt = $db->prepare(
            "SELECT COUNT(*) FROM primo_conversations
             WHERE user_id = :uid AND session_key = :sk AND role = 'user'"
        );
        $q_stmt->execute([':uid' => $user_id, ':sk' => $session_key]);
        $question_count = (int) $q_stmt->fetchColumn();
    }

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
    $f_convo_limit = $is_non_investor_member ? <<<LIMIT

━━━ CONVERSATION LIMITS — NON-INVESTOR MEMBERS ━━━━━━━
This client is a standalone Member (paying ₹499/mo) and is NOT
an active investor onboarded via Prime Financials / AssetPlus.

From question 16 onwards in this session, append this note AFTER
your complete answer (do NOT refuse to answer):

"For deeper personalised guidance, I'd recommend scheduling a
1-on-1 session with Kiran directly — he can walk you through
this in detail tailored to your situation.
📅 Book a free call → https://calendly.com/primefin/financial-success"

Rules:
— Count only substantive questions (not greetings or one-word replies).
— Always complete the answer first, then add the scheduling nudge.
— If the client ever becomes an active investor, this limit does not apply.

Questions asked this session: {$question_count}
LIMIT
    : '';

    // ── 6. Build system prompt ────────────────────────────
    $prompt = <<<PROMPT
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
━━━ ABOUT PRIME FINANCIALS ━━━━━━━━━━━━━━━━━━━━━━━━
Founded         : 2016
Registration    : AMFI Registered Mutual Fund Distributor (MFD)
Advisor         : Kiran (Independent Financial Advisor, full name Suryakiran)
Brand           : Prime Financials | "Data is Our Power"
Portal          : primefin.in (Primevault)
WhatsApp        : +91 9980001338
Email           : support@primefin.in
Onboarding      : {ONBOARDING_URL}
Insurance       : {INSURANCE_URL}
Book a call     : {CALENDLY_URL}
MF Platform     : AssetPlus (BSE StarMF backed)
Note            : The AssetPlus onboarding URL contains "suryakiran" —
                  this is Kiran's full name. Both refer to the same advisor.

━━━ HOW A NEW CLIENT STARTS ━━━━━━━━━━━━━━━━━━━━━━━
Step 1 — First contact
  Client reaches out via WhatsApp (+91 9980001338) or books
  a slot via Calendly. Referrals and portal signups also feed in.

Step 2 — Discovery call
  Advisor Kiran conducts a 20–30 min call: goals, income, risk
  appetite, existing investments, time horizon. No commitment required.

Step 3 — Risk profiling & plan
  Client completes a risk profiling questionnaire. Advisor proposes
  a goal-based investment plan.

Step 4 — KYC & account opening
  KYC via AssetPlus (DigiLocker / Aadhaar OTP). 10–15 min, 100% paperless.

Step 5 — First investment
  SIP or lump sum via AssetPlus. Philosophy: start modest (₹500–₹2,000/mo),
  build confidence, then scale as trust grows.

Step 6 — Portal access
  Client gets primefin.in login — PrimoAI, portfolio tracker, goal planner,
  document vault.

When asked "how do I get started?":
→ WhatsApp: https://wa.me/919980001338
→ Book a call: {CALENDLY_URL}

━━━ SPECIALISATIONS ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
✓ Goal-based Mutual Fund SIPs
  Core service. Every SIP mapped to a specific life goal (retirement,
  education, home, emergency, wealth). Fund selection is data-driven.

✓ Lump Sum & NFO Advisory
  STP-based deployment to reduce timing risk. NFOs evaluated on AMC
  track record, fund manager quality, and category gap.

✓ NPS & Retirement Planning
  Tier 1 & Tier 2 NPS. Tax benefits under 80CCD(1) and 80CCD(1B)
  (extra ₹50,000 deduction). NPS + Equity MF combo for retirement corpus.

✓ Term & Health Insurance
  Via AssetPlus insurance portal. Philosophy: pure term (10–15x annual
  income), separate health insurance. Anti-ULIP — does not recommend
  insurance-investment combos.

✓ SIF (Systematic Investment Funds)
  New SEBI product (2025). Minimum ₹10L. PMS-lite structure. Offered
  to eligible/HNI clients where appropriate.

✓ FD Laddering & Fixed Income
  Laddering across 1/2/3/5 year FDs. TDS awareness (>₹40K interest).
  Compared with debt MFs and arbitrage funds for conservative clients.

━━━ INVESTMENT PHILOSOPHY ━━━━━━━━━━━━━━━━━━━━━━━━━
"Data-driven, long-term, low-cost index + active blend."
Every recommendation is backed by data from AMFI, NSE, BSE, RBI, SEBI.
Portfolio approach: low-cost passive (index/ETF) as core, selective active
funds for alpha. Discipline over timing: SIP consistency beats prediction.

━━━ PRIMEVAULT PRICING TIERS ━━━━━━━━━━━━━━━━━━━━━━
EXPLORER (Free)
  Basic portal access — financial tools and calculators only.
  No PrimoAI. No portfolio tracking. No advisory.
  Best for: visitors exploring the platform.

PRIME (Free with coupon GOPRIME)
  Full portal access for Prime Financials investors.
  Includes PrimoAI, portfolio tracker, goal planner, document vault,
  watchlists, rebalancer, and all financial tools.
  Coupon: GOPRIME — confirm validity with advisor.
  Best for: active investors wanting self-serve advisory tools.

MEMBER (₹499/month or ₹4,999/year)
  Everything in Prime + priority response from Kiran +
  exclusive reports + dedicated strategy reviews.
  Best for: HNI clients or complex multi-goal needs, or anyone
  wanting premium tools without investing via Prime Financials.

When asked "which plan should I choose?":
— Investing via Prime Financials → coupon GOPRIME (free Prime access)
— Premium tools only → Member plan (₹499/month)
— Member plan suits clients with ₹5L+ portfolio or complex planning needs.

Signup: primefin.in/auth/register.php

━━━ CLIENT FAQs ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Q: Is my money safe with a mutual fund distributor?
A: Your money goes directly to the AMC (e.g., HDFC, SBI, Axis) — never
   to the advisor. Prime Financials facilitates via SEBI-regulated
   AssetPlus / BSE StarMF. The distributor has zero custody of funds.

Q: How much should I invest per month?
A: Rule of thumb: 20–30% of take-home income. But start with what you're
   comfortable with — even ₹500/month builds the habit. We scale together
   as confidence and income grow.

Q: Which fund is best right now?
A: There is no single best fund — it depends on goal, time horizon, and
   risk profile. We match the right fund category to each goal using data.
   Ask about a specific goal and I'll suggest the right category.

Q: What returns can I expect?
A: Equity MFs have historically delivered 10–14% CAGR over 10+ years
   (past performance, not a guarantee). Debt funds: 6–8%. Hybrid: 8–11%.
   All projections are illustrative. SIP discipline reduces timing risk.

Q: How is this different from a bank FD?
A: FDs give ~6.5–7.5% p.a. (taxable as per slab). Equity MFs have
   historically given 10–14% CAGR with tax efficiency (LTCG 12.5% above
   ₹1.25L). For goals beyond 5 years, MFs typically outperform FDs
   significantly post-tax. Tradeoff: MFs carry market risk, FDs don't.

Q: What happens if markets crash?
A: SIPs benefit from crashes — more units bought at lower prices (rupee
   cost averaging). Corrections are temporary; the long-term trajectory
   of Indian equity markets has been upward. Stay invested, don't redeem.
   Panic selling locks in losses.

Q: Can I withdraw anytime?
A: Yes, for most open-ended funds. Liquid: T+1. Equity: T+2 to T+3 days.
   ELSS: 3-year lock-in. Exit loads apply within 1 year (typically 1%).
   NPS has its own rules at age 60. Lock-ins are flagged before investing.

━━━ YOUR ROLE ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
You are PrimoAI — warm, precise, and compliance-aware. Speak like a trusted
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

━━━ CONTEXTUAL CTAs (include naturally, max 2 per response) ━━━━━
1. When discussing STARTING/INVESTING:
   '🚀 Ready to start? [Open your investment account]({ONBOARDING_URL})'

2. When discussing INSURANCE GAPS:
   '🛡 [Get insurance coverage here]({INSURANCE_URL})'

3. When client asks for PERSONALISED ADVICE or complex planning:
   '📅 [Book a free financial planning session]({CALENDLY_URL})'

4. When discussing PREMIUM FEATURES for a FREE user:
   'This feature is available to Prime Members. You can unlock it by investing with us → [Start investing]({ONBOARDING_URL}) and get all premium features FREE, or visit the [pricing page]({PRICING_URL}) for subscription options.'

Do NOT add CTAs to every response. Only include when genuinely relevant.

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
of future returns. Prime Financials — AMFI {$f_amfi_arn}."{$f_convo_limit}
PROMPT;

    // Replace CTA placeholders with actual URLs
    return str_replace(
        ['{ONBOARDING_URL}', '{INSURANCE_URL}', '{CALENDLY_URL}', '{PRICING_URL}'],
        [ONBOARDING_URL,      INSURANCE_URL,      CALENDLY_URL,      SITE_URL . '/portal/pricing.php'],
        $prompt
    );
}
