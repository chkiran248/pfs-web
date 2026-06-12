<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/subscription.php';
require_login();
require_role('client');

$db      = get_db();
$uid     = get_user_id();
$plan    = get_user_plan($uid);
$pc      = get_plan_config($plan);

// ── User + profile ────────────────────────────────────────
$stmt = $db->prepare("SELECT full_name, last_login FROM users WHERE id = :uid");
$stmt->execute([':uid' => $uid]);
$user_data = $stmt->fetch() ?: [];

$stmt = $db->prepare("SELECT risk_profile FROM user_profiles WHERE user_id = :uid ORDER BY id DESC LIMIT 1");
$stmt->execute([':uid' => $uid]);
$user_data['risk_profile'] = $stmt->fetchColumn() ?: null;

// ── Portfolio totals ──────────────────────────────────────
$stmt = $db->prepare("SELECT fund_type, SUM(invested_amount) as invested, SUM(current_value) as current FROM portfolio_entries WHERE user_id = :uid GROUP BY fund_type");
$stmt->execute([':uid' => $uid]);
$allocation_rows = $stmt->fetchAll();

$total_invested = 0; $total_current = 0;
$allocation = [];
foreach ($allocation_rows as $row) {
    $total_invested += (float)$row['invested'];
    $total_current  += (float)$row['current'];
    $allocation[ucfirst($row['fund_type'])] = (float)$row['current'];
}
$gain       = $total_current - $total_invested;
$gain_pct   = $total_invested > 0 ? ($gain / $total_invested) * 100 : 0;

// Simple CAGR approximation for XIRR (avg purchase date → today)
$stmt = $db->prepare("SELECT AVG(DATEDIFF(NOW(), purchase_date)) as avg_days FROM portfolio_entries WHERE user_id = :uid AND purchase_date IS NOT NULL AND invested_amount > 0");
$stmt->execute([':uid' => $uid]);
$avg_days = (float)($stmt->fetchColumn() ?: 0);
$xirr = 0;
if ($avg_days > 0 && $total_invested > 0 && $total_current > 0) {
    $years = $avg_days / 365;
    $xirr  = (pow($total_current / $total_invested, 1 / $years) - 1) * 100;
}

// ── Retirement Income (6% SWR on investable corpus) ──────
$protection_only_types = ['Term Insurance','Health Insurance','Critical Illness','Personal Accident'];

$stmt_ret = $db->prepare("SELECT fund_type, fund_name, units_held, current_value, folio_number FROM portfolio_entries WHERE user_id = :uid");
$stmt_ret->execute([':uid' => $uid]);
$ret_holdings = $stmt_ret->fetchAll();

$investable_corpus = 0.0;
$crypto_raw = []; // [index => ['name','units','stored_value']]

foreach ($ret_holdings as $h) {
    $ftype = $h['fund_type'] ?? '';
    $cval  = (float)($h['current_value'] ?? 0);

    if ($ftype === 'insurance') {
        $policy_type = trim($h['folio_number'] ?? '');
        if (!in_array($policy_type, $protection_only_types)) {
            $investable_corpus += $cval; // Endowment/ULIP/Whole Life/Money Back — has maturity value
        }
    } elseif ($ftype === 'crypto') {
        $crypto_raw[] = ['name' => trim($h['fund_name'] ?? ''), 'units' => (float)($h['units_held'] ?? 0), 'stored' => $cval];
        $investable_corpus += $cval; // add stored first; may be replaced by live price below
    } else {
        $investable_corpus += $cval;
    }
}

// Live crypto prices via CoinGecko (cached 30 min in session)
if (!empty($crypto_raw)) {
    $coin_map = [
        'bitcoin'=>'bitcoin','btc'=>'bitcoin',
        'ethereum'=>'ethereum','eth'=>'ethereum',
        'tether'=>'tether','usdt'=>'tether',
        'bnb'=>'binancecoin','binance coin'=>'binancecoin',
        'solana'=>'solana','sol'=>'solana',
        'xrp'=>'ripple','ripple'=>'ripple',
        'cardano'=>'cardano','ada'=>'cardano',
        'polygon'=>'matic-network','matic'=>'matic-network','pol'=>'matic-network',
        'dogecoin'=>'dogecoin','doge'=>'dogecoin',
        'shiba inu'=>'shiba-inu','shib'=>'shiba-inu',
        'polkadot'=>'polkadot','dot'=>'polkadot',
        'litecoin'=>'litecoin','ltc'=>'litecoin',
        'chainlink'=>'chainlink','link'=>'chainlink',
        'avalanche'=>'avalanche-2','avax'=>'avalanche-2',
        'usd coin'=>'usd-coin','usdc'=>'usd-coin',
        'uniswap'=>'uniswap','uni'=>'uniswap',
        'pepe'=>'pepe','wif'=>'dogwifhat',
    ];
    $mapped = [];
    foreach ($crypto_raw as $i => $c) {
        $key = strtolower($c['name']);
        $mapped[$i] = $coin_map[$key] ?? null;
    }
    $ids = array_filter(array_unique(array_values($mapped)));
    if ($ids) {
        $ck = 'cgp_' . md5(implode(',', $ids));
        $live = (isset($_SESSION[$ck], $_SESSION[$ck.'_ts']) && (time() - $_SESSION[$ck.'_ts']) < 1800)
            ? $_SESSION[$ck] : null;
        if (!$live) {
            $url = 'https://api.coingecko.com/api/v3/simple/price?ids=' . implode(',', $ids) . '&vs_currencies=inr';
            $ctx = stream_context_create(['http'=>['timeout'=>3,'header'=>"User-Agent: PrimeFinancials/1.0\r\n"]]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw) { $live = json_decode($raw, true); $_SESSION[$ck] = $live; $_SESSION[$ck.'_ts'] = time(); }
        }
        if ($live) {
            foreach ($crypto_raw as $i => $c) {
                $cg_id = $mapped[$i] ?? null;
                if ($cg_id && isset($live[$cg_id]['inr'])) {
                    $live_value = $c['units'] * $live[$cg_id]['inr'];
                    $investable_corpus -= $c['stored']; // remove stored estimate
                    $investable_corpus += $live_value;  // replace with live value
                }
            }
        }
    }
    unset($crypto_raw, $coin_map, $mapped, $ids);
}

$retire_monthly = $investable_corpus * 0.06 / 12;

// ── Goals ─────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM goals WHERE user_id = :uid AND status = 'active' ORDER BY target_year ASC LIMIT 3");
$stmt->execute([':uid' => $uid]);
$goals = $stmt->fetchAll();

// ── Market insights ───────────────────────────────────────
$stmt = $db->prepare("SELECT title, slug, excerpt, category, published_at FROM market_insights WHERE is_published = 1 ORDER BY published_at DESC LIMIT 3");
$stmt->execute();
$insights = $stmt->fetchAll();

// ── Greeting ──────────────────────────────────────────────
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$goal_icons = ['retirement'=>'🏦','education'=>'🎓','home'=>'🏠','marriage'=>'💍','vehicle'=>'🚗','emergency'=>'🆘','custom'=>'🎯'];
$category_labels = ['market_update'=>'Market Update','tax_tips'=>'Tax Tips','fund_analysis'=>'Fund Analysis','nps'=>'NPS','insurance'=>'Insurance','stocks'=>'Stocks','general'=>'General'];

$page_title = 'Dashboard — Prime Financials';
require_once '../includes/portal-header.php';
?>

<!-- Welcome bar -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.5rem;padding-bottom:1.25rem;border-bottom:1px solid var(--border)">
  <div>
    <p class="page-eyebrow">Overview</p>
    <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap">
      <h1 class="page-title" style="margin-bottom:0"><?= $greeting ?>, <?= htmlspecialchars($user_data['full_name'], ENT_QUOTES, 'UTF-8') ?>!</h1>
      <span style="display:inline-flex;align-items:center;gap:0.3rem;font-family:'DM Mono',monospace;font-size:0.62rem;font-weight:500;letter-spacing:0.12em;text-transform:uppercase;padding:0.28rem 0.65rem;border-radius:20px;border:1px solid <?= $pc['border'] ?>;background:<?= $pc['bg'] ?>;color:<?= $pc['colour'] ?>;vertical-align:middle">
        <?= $pc['icon'] ?> <?= $pc['label'] ?>
      </span>
    </div>
    <div style="font-family:'DM Mono',monospace;font-size:0.65rem;color:var(--text-muted);margin-top:0.3rem;letter-spacing:0.06em">
      <?php if ($user_data['last_login']): ?>Last login: <?= date('d M Y, g:i a', strtotime($user_data['last_login'])) ?> · <?php endif; ?>
      Risk: <?= $user_data['risk_profile'] ? ucfirst($user_data['risk_profile']) : 'Not assessed' ?>
    </div>
  </div>
  <?php if ($plan === 'free'): ?>
  <a href="<?= SITE_URL ?>/portal/pricing.php"
     style="font-family:'DM Mono',monospace;font-size:0.68rem;color:var(--lime);letter-spacing:0.08em;text-decoration:none;border:1px solid rgba(141,198,63,0.25);padding:0.35rem 0.85rem;border-radius:4px;transition:all 0.2s;white-space:nowrap"
     onmouseover="this.style.background='rgba(141,198,63,0.1)'" onmouseout="this.style.background=''">↑ Upgrade plan</a>
  <?php endif; ?>
</div>

<?php if ($plan === 'free'): ?>
<!-- Upgrade nudge for free users -->
<div style="background:linear-gradient(135deg,var(--surface-1),rgba(27,94,42,0.1));border:1px solid rgba(141,198,63,0.2);border-radius:12px;padding:1.1rem 1.25rem;margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem">
  <div>
    <div style="font-weight:600;color:var(--cream);font-size:0.9rem;margin-bottom:0.15rem">Unlock the full Prime Financials platform</div>
    <div style="font-size:0.8rem;color:var(--text-secondary)">Get PrimoAI, Rebalancer, Document Scanner &amp; more — free when you invest with us.</div>
  </div>
  <div style="display:flex;gap:0.6rem;flex-wrap:wrap;flex-shrink:0">
    <a href="<?= ONBOARDING_URL ?>?utm_source=dashboard_nudge&utm_medium=portal" target="_blank" rel="noopener"
       style="background:var(--lime);color:#0c1a0c;padding:0.5rem 1rem;border-radius:6px;font-size:0.82rem;font-weight:700;text-decoration:none;white-space:nowrap">🚀 Invest — Get Premium Free</a>
    <a href="<?= SITE_URL ?>/portal/pricing.php"
       style="background:transparent;border:1px solid var(--border);color:var(--text-secondary);padding:0.5rem 0.875rem;border-radius:6px;font-size:0.82rem;text-decoration:none;white-space:nowrap">View Plans</a>
  </div>
</div>
<?php endif; ?>

<?php
// Insurance nudge if no insurance in portfolio
$ins_check = $db->prepare("SELECT COUNT(*) FROM portfolio_entries WHERE user_id=:uid AND (fund_type='other' OR LOWER(fund_name) LIKE '%insurance%' OR LOWER(fund_name) LIKE '%term%' OR LOWER(fund_name) LIKE '%policy%')");
$ins_check->execute([':uid'=>$uid]);
$has_insurance = (int)$ins_check->fetchColumn() > 0;
if (!$has_insurance):
?><div style="background:linear-gradient(135deg,rgba(201,168,76,0.08),rgba(27,94,42,0.06));border:1px solid rgba(201,168,76,0.2);border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
  <span style="font-size:1.6rem;flex-shrink:0">🛡</span>
  <div style="flex:1;min-width:180px">
    <div style="font-weight:600;color:var(--cream);font-size:0.875rem;margin-bottom:0.1rem">Protect your wealth</div>
    <div style="font-size:0.78rem;color:var(--text-secondary)">No insurance detected in your portfolio. A term cover is essential to protect your family's financial future.</div>
  </div>
  <a href="<?= INSURANCE_URL ?>?utm_source=dashboard_insurance&utm_medium=portal" target="_blank" rel="noopener"
     style="background:var(--gold);color:#0c1a0c;padding:0.5rem 1rem;border-radius:6px;font-size:0.82rem;font-weight:600;text-decoration:none;white-space:nowrap;flex-shrink:0">Get Covered →</a>
</div><?php endif; ?>

<!-- ── Retirement Income Card ─────────────────────────────── -->
<div style="margin-bottom:1.5rem;background:linear-gradient(135deg,var(--surface-1),rgba(46,133,64,0.06));border:1px solid rgba(201,168,76,0.25);border-radius:12px;padding:1.5rem 1.75rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1.5rem">
  <div>
    <div style="font-family:'DM Mono',monospace;font-size:0.58rem;color:var(--lime);letter-spacing:0.22em;text-transform:uppercase;margin-bottom:0.6rem;display:flex;align-items:center;gap:0.4rem">
      <i class="bi bi-sunrise"></i> Your Monthly Income
    </div>
    <?php if ($investable_corpus > 0): ?>
      <div style="font-family:'Cormorant Garamond',serif;font-size:3.6rem;font-weight:700;color:var(--gold);line-height:1;margin-bottom:0.45rem">
        <?= format_inr($retire_monthly) ?><span style="font-size:1.3rem;font-weight:400;color:var(--text-secondary);margin-left:0.3rem">/month</span>
      </div>
      <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap">
        <span style="font-size:0.82rem;color:var(--text-secondary)">If You Retire Today &nbsp;·&nbsp; 6% Safe Withdrawal Rate</span>
        <!-- Tooltip -->
        <span style="position:relative;display:inline-flex;align-items:center" class="retire-tip-wrap">
          <span style="display:inline-flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:50%;background:rgba(46,133,64,0.2);border:1px solid rgba(46,133,64,0.35);color:var(--lime);font-size:0.55rem;font-family:'DM Mono',monospace;cursor:default;line-height:1">?</span>
          <span class="retire-tip-box" style="position:absolute;bottom:calc(100% + 10px);left:50%;transform:translateX(-50%);background:var(--surface-2);border:1px solid var(--border);border-radius:8px;padding:0.7rem 0.9rem;font-size:0.72rem;color:var(--text-secondary);line-height:1.6;width:260px;pointer-events:none;z-index:300;font-family:'DM Sans',sans-serif;white-space:normal">
            Calculated at <strong style="color:var(--cream)">6% Safe Withdrawal Rate</strong> on your <strong style="color:var(--cream)"><?= format_inr($investable_corpus) ?></strong> investable portfolio.<br><br>
            <strong style="color:var(--cream)">Excluded:</strong> Term Insurance, Health Insurance, Critical Illness & Personal Accident policies (no maturity value).<br><br>
            Crypto values use live INR prices via CoinGecko (30-min cache).
            <span style="position:absolute;top:100%;left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:var(--border)"></span>
          </span>
        </span>
      </div>
    <?php else: ?>
      <div style="font-family:'Cormorant Garamond',serif;font-size:1.6rem;color:var(--text-muted);margin-bottom:0.3rem">Add holdings to see your retirement income</div>
      <div style="font-size:0.82rem;color:var(--text-secondary)">If You Retire Today &nbsp;·&nbsp; Safe Withdrawal Rate on total investable assets</div>
    <?php endif; ?>
  </div>
  <div style="flex-shrink:0">
    <a href="<?= SITE_URL ?>/portal/cashflow-modeler.php" style="font-family:'DM Mono',monospace;font-size:0.68rem;color:var(--lime);letter-spacing:0.08em;text-decoration:none;border:1px solid rgba(141,198,63,0.25);padding:0.45rem 1rem;border-radius:4px;white-space:nowrap;display:inline-flex;align-items:center;gap:0.4rem;transition:background 0.2s" onmouseover="this.style.background='rgba(141,198,63,0.1)'" onmouseout="this.style.background=''">
      <i class="bi bi-graph-up-arrow"></i> Full Retirement Planner →
    </a>
  </div>
</div>
<style>
.retire-tip-wrap .retire-tip-box{opacity:0;transition:opacity 0.18s,transform 0.18s;transform:translateX(-50%) translateY(4px)}
.retire-tip-wrap:hover .retire-tip-box{opacity:1;transform:translateX(-50%) translateY(0)}
</style>

<!-- Stat boxes -->
<div class="stats-grid">
  <div class="stat-box">
    <div class="stat-label">Total Invested</div>
    <div class="stat-value neutral"><?= format_inr($total_invested) ?></div>
    <div class="stat-sub"><?= count($allocation_rows) ?> fund type<?= count($allocation_rows) !== 1 ? 's' : '' ?></div>
  </div>
  <div class="stat-box">
    <div class="stat-label">Current Value</div>
    <div class="stat-value neutral"><?= format_inr($total_current) ?></div>
    <div class="stat-sub">As of today</div>
  </div>
  <div class="stat-box">
    <div class="stat-label">Total Gain / Loss</div>
    <div class="stat-value <?= $gain >= 0 ? 'positive' : 'negative' ?>">
      <?= $gain >= 0 ? '+' : '' ?><?= format_inr(abs($gain)) ?>
    </div>
    <div class="stat-sub"><?= $gain >= 0 ? '+' : '' ?><?= number_format($gain_pct, 2) ?>%</div>
  </div>
  <div class="stat-box">
    <div class="stat-label">Est. XIRR</div>
    <div class="stat-value <?= $xirr >= 0 ? 'positive' : 'negative' ?>">
      <?= $total_invested > 0 ? number_format($xirr, 2) . '%' : '—' ?>
    </div>
    <div class="stat-sub">Annualised return</div>
  </div>
</div>

<!-- Chart + Goals row -->
<div class="grid-2" style="margin-top:1.5rem;align-items:start">

  <!-- Asset allocation chart -->
  <div class="portal-card">
    <div class="card-title">Asset Allocation</div>
    <?php if (!empty($allocation)): ?>
      <canvas id="allocationChart" height="220"></canvas>
    <?php else: ?>
      <div style="text-align:center;padding:2rem;color:var(--text-secondary)">
        <div style="font-size:2rem;margin-bottom:0.75rem">◈</div>
        No portfolio data yet.<br>
        <a href="<?= SITE_URL ?>/portal/portfolio.php" class="auth-link" style="font-size:0.875rem">Add your first holding →</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- Goals progress -->
  <div class="portal-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
      <div class="card-title" style="margin-bottom:0">Goals Progress</div>
      <a href="<?= SITE_URL ?>/portal/goals.php" class="auth-link" style="font-size:0.8rem">View all →</a>
    </div>
    <?php if (empty($goals)): ?>
      <div style="text-align:center;padding:1.5rem;color:var(--text-secondary)">
        <div style="font-size:2rem;margin-bottom:0.75rem">◉</div>
        No goals yet.<br>
        <a href="<?= SITE_URL ?>/portal/goals.php" class="auth-link" style="font-size:0.875rem">Set your first goal →</a>
      </div>
    <?php else: ?>
      <?php foreach ($goals as $g):
        $pct = $g['target_amount'] > 0 ? min(100, ($g['current_savings'] / $g['target_amount']) * 100) : 0;
        $icon = $goal_icons[$g['goal_type']] ?? '🎯';
      ?>
      <div style="margin-bottom:1.25rem">
        <div style="display:flex;justify-content:space-between;margin-bottom:0.3rem">
          <span style="font-weight:500;color:var(--cream)"><?= $icon ?> <?= htmlspecialchars($g['goal_name'], ENT_QUOTES, 'UTF-8') ?></span>
          <span style="font-size:0.8rem;color:var(--lime)"><?= number_format($pct, 0) ?>%</span>
        </div>
        <div style="background:var(--surface-2);border-radius:4px;height:6px;overflow:hidden">
          <div style="width:<?= $pct ?>%;height:100%;background:var(--mid);border-radius:4px;transition:width 0.5s"></div>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:0.25rem;font-size:0.75rem;color:var(--text-secondary)">
          <span><?= format_inr((float)$g['current_savings']) ?> saved</span>
          <span><?= format_inr((float)$g['target_amount']) ?> by <?= $g['target_year'] ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<!-- Primo CTA -->
<div class="portal-card" style="margin-top:1.5rem;background:linear-gradient(135deg,var(--surface-1),rgba(27,94,42,0.15));border-color:rgba(141,198,63,0.25)">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem">
    <div style="display:flex;align-items:center;gap:1rem">
      <div style="font-size:2rem;color:var(--lime)">✦</div>
      <div>
        <div style="font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:600;color:var(--cream);margin-bottom:0.2rem">Ask PrimoAI</div>
        <div style="font-size:0.85rem;color:var(--text-secondary)">Your AI assistant knows your portfolio. Ask anything.</div>
      </div>
    </div>
    <div style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center">
      <button class="sugg-pill" onclick="location.href='<?= SITE_URL ?>/portal/primo.php?q=How+is+my+portfolio'">📊 Portfolio review</button>
      <button class="sugg-pill" onclick="location.href='<?= SITE_URL ?>/portal/primo.php?q=Am+I+on+track+for+goals'">🎯 Goal tracking</button>
      <a href="<?= SITE_URL ?>/portal/primo.php" class="btn-primary btn-sm">Chat with Primo →</a>
    </div>
  </div>
</div>
<style>.sugg-pill{font-size:0.78rem;padding:0.35rem 0.75rem;border:1px solid var(--border);border-radius:20px;background:var(--surface-2);color:var(--text-secondary);cursor:pointer;transition:all 0.15s;}.sugg-pill:hover{border-color:var(--mid);color:var(--cream);}</style>

<!-- Quick tools strip -->
<div class="portal-card" style="margin-top:1.5rem">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div class="card-title" style="margin-bottom:0">Quick Tools</div>
    <a href="<?= SITE_URL ?>/portal/calculators.php" class="auth-link" style="font-size:0.8rem">View all →</a>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:0.75rem">
    <a href="<?= SITE_URL ?>/portal/sip-calculator.php"   class="btn-outline btn-sm">⊕ SIP</a>
    <a href="<?= SITE_URL ?>/portal/tax-calculator.php"   class="btn-outline btn-sm">⊗ Tax</a>
    <a href="<?= SITE_URL ?>/portal/nps-projector.php"    class="btn-outline btn-sm">⊘ NPS</a>
    <a href="<?= SITE_URL ?>/portal/insurance-checker.php"class="btn-outline btn-sm">⊝ Insurance</a>
    <a href="<?= SITE_URL ?>/portal/cashflow-modeler.php" class="btn-outline btn-sm">⊛ Cashflow</a>
    <a href="<?= SITE_URL ?>/portal/overlap-analyzer.php" class="btn-outline btn-sm">⊜ Overlap</a>
    <a href="<?= SITE_URL ?>/portal/rebalancer.php"      class="btn-outline btn-sm">⚖ Rebalancer</a>
  </div>
</div>

<!-- Market insights -->
<div style="margin-top:1.5rem">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <h2 class="section-header" style="margin-bottom:0;border:none;padding:0">Recent Insights</h2>
    <a href="<?= SITE_URL ?>/advisory/insights.php" class="auth-link" style="font-size:0.8rem">View all →</a>
  </div>
  <?php if (empty($insights)): ?>
    <div class="portal-card" style="text-align:center;color:var(--text-secondary);padding:2rem">
      No market insights published yet.
    </div>
  <?php else: ?>
    <div class="grid-3">
      <?php foreach ($insights as $ins): ?>
      <div class="portal-card">
        <div style="margin-bottom:0.6rem">
          <span class="badge badge-green"><?= htmlspecialchars($category_labels[$ins['category']] ?? $ins['category'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div style="font-weight:500;color:var(--cream);margin-bottom:0.4rem;line-height:1.4">
          <?= htmlspecialchars($ins['title'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div style="font-size:0.82rem;color:var(--text-secondary);line-height:1.6">
          <?= htmlspecialchars(mb_substr($ins['excerpt'] ?? '', 0, 100), ENT_QUOTES, 'UTF-8') ?><?= strlen($ins['excerpt'] ?? '') > 100 ? '…' : '' ?>
        </div>
        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.75rem;font-family:'DM Mono',monospace">
          <?= $ins['published_at'] ? date('d M Y', strtotime($ins['published_at'])) : '' ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if (!empty($allocation)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const isDark = !document.documentElement.hasAttribute('data-theme');
  new Chart(document.getElementById('allocationChart'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode(array_keys($allocation)) ?>,
      datasets: [{
        data: <?= json_encode(array_values($allocation)) ?>,
        backgroundColor: ['#1B5E2A','#2E8540','#4CAF50','#8DC63F','#C9A84C','#558b2f','#a5d6a7','#66BB6A'],
        borderColor: isDark ? '#0c140c' : '#fff',
        borderWidth: 3,
        hoverOffset: 6
      }]
    },
    options: {
      cutout: '68%',
      plugins: {
        legend: {
          position: 'bottom',
          labels: { color: isDark ? '#85a885' : '#2a5a2a', font: { family: "'DM Mono'" }, padding: 14, boxWidth: 12 }
        },
        tooltip: {
          callbacks: {
            label: function(ctx) {
              const val = ctx.raw;
              return ' ₹' + val.toLocaleString('en-IN', {maximumFractionDigits: 0});
            }
          }
        }
      }
    }
  });
});
</script>
<?php endif; ?>

<?php require_once '../includes/portal-footer.php'; ?>
