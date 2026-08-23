<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/subscription.php';
require_login();
require_role('client');

$uid          = get_user_id();
$current_plan = get_user_plan($uid);
$pc           = get_plan_config($current_plan);
$sub          = get_user_subscription($uid);
$coupon_error = $coupon_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['coupon_code'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $coupon_error = 'Invalid request.'; }
    else {
        $result = redeem_coupon($uid, $_POST['coupon_code'] ?? '');
        if ($result['success']) {
            $_SESSION['flash'] = ['type' => 'success', 'message' => $result['message']];
            header('Location: ' . SITE_URL . '/portal/dashboard.php'); exit;
        }
        $coupon_error = $result['message'];
    }
}

$page_title = 'Plans & Pricing — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Account</p>
<h1 class="page-title">Plans &amp; Pricing</h1>
<p class="page-subtitle">Start free. Upgrade when you're ready — or invest with us and get everything free.</p>

<?php if ($current_plan !== 'free'): ?>
<div class="flash-success" style="margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem">
  <span>✓ You are on the <strong><?= $pc['label'] ?></strong> plan with full premium access.</span>
  <?php if ($sub && $sub['expires_at']): ?>
    <?php $exp = new DateTime($sub['expires_at']); $days_left = (int)(new DateTime())->diff($exp)->days; ?>
    <span style="font-size:0.8rem;color:var(--text-secondary)">
      <?= $days_left <= 30 ? '<span style="color:var(--gold)">⚠ ' : '' ?>
      Active until <strong><?= $exp->format('d M Y') ?></strong>
      (<?= $days_left ?> day<?= $days_left !== 1 ? 's' : '' ?> left)
      <?= $days_left <= 30 ? '</span>' : '' ?>
    </span>
  <?php elseif ($sub && !$sub['expires_at']): ?>
    <span style="font-size:0.8rem;color:var(--text-secondary)">Lifetime access</span>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Pricing cards -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;margin-bottom:2rem">

  <!-- Explorer (Free) -->
  <?php $active = $current_plan==='free'; ?>
  <div class="portal-card" style="<?= $active?'border-color:var(--border);':'' ?>position:relative">
    <div style="font-family:'DM Mono',monospace;font-size:0.62rem;letter-spacing:0.15em;color:var(--text-muted);margin-bottom:0.5rem">FREE PLAN</div>
    <div style="font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:700;color:var(--cream)">Explorer</div>
    <div style="font-size:2rem;font-weight:700;color:var(--cream);margin:0.5rem 0">₹0<span style="font-size:0.85rem;color:var(--text-muted)">/mo</span></div>
    <?php if ($active): ?><span class="badge badge-muted" style="margin-bottom:0.75rem">Current Plan</span><?php endif; ?>
    <ul style="list-style:none;padding:0;margin:1rem 0;display:flex;flex-direction:column;gap:0.4rem;font-size:0.82rem;color:var(--text-secondary)">
      <li>✅ All calculators (SIP, Tax, NPS, Insurance)</li>
      <li>✅ Risk profile quiz</li>
      <li>✅ Portfolio — up to <strong>5 holdings</strong></li>
      <li>✅ Stock research &amp; sector tracker</li>
      <li>✅ MF advisory (view only)</li>
      <li style="color:var(--text-muted)">❌ PrimoAI</li>
      <li style="color:var(--text-muted)">❌ Portfolio Rebalancer</li>
      <li style="color:var(--text-muted)">❌ Document Scanner</li>
      <li style="color:var(--text-muted)">❌ Watchlists, FD Tracker, Vault</li>
    </ul>
    <?php if (!$active): ?><a href="<?= SITE_URL ?>/portal/dashboard.php" class="btn-ghost" style="width:100%;text-align:center">Get Started Free</a><?php endif; ?>
  </div>

  <!-- Active Investor -->
  <?php $active = $current_plan==='active_investor'; ?>
  <div class="portal-card" style="border-color:rgba(141,198,63,0.35);background:linear-gradient(135deg,var(--surface-1),rgba(27,94,42,0.08));position:relative">
    <div style="position:absolute;top:-1px;left:50%;transform:translateX(-50%);background:var(--lime);color:#0c1a0c;font-family:'DM Mono',monospace;font-size:0.58rem;letter-spacing:0.1em;padding:0.2rem 0.75rem;border-radius:0 0 6px 6px;font-weight:700">MOST POPULAR</div>
    <div style="font-family:'DM Mono',monospace;font-size:0.62rem;letter-spacing:0.15em;color:var(--lime);margin-bottom:0.5rem;margin-top:0.75rem">ACTIVE INVESTOR</div>
    <div style="font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:700;color:var(--cream)">Prime</div>
    <div style="font-size:1.5rem;font-weight:700;color:var(--lime);margin:0.5rem 0">Free <span style="font-size:0.78rem;color:var(--text-muted)">with coupon</span></div>
    <?php if ($active): ?><span class="badge badge-green" style="margin-bottom:0.75rem">✓ Active</span><?php endif; ?>
    <ul style="list-style:none;padding:0;margin:1rem 0;display:flex;flex-direction:column;gap:0.4rem;font-size:0.82rem;color:var(--text-secondary)">
      <li>✅ Everything in Explorer</li>
      <li>✅ Unlimited portfolio holdings</li>
      <li>✅ <strong style="color:var(--lime)">PrimoAI</strong> — unlimited</li>
      <li>✅ Portfolio Rebalancer (MF + Equity)</li>
      <li>✅ AI Document Scanner</li>
      <li>✅ Cashflow Modeler &amp; Overlap Analyzer</li>
      <li>✅ Fund &amp; Stock Watchlists</li>
      <li>✅ Document Vault &amp; FD Tracker</li>
      <li>✅ Tax Switch Modeler</li>
    </ul>
    <?php if (!$active): ?>
    <div style="display:flex;flex-direction:column;gap:0.5rem">
      <a href="<?= ONBOARDING_URL ?>?utm_source=pricing&utm_medium=portal"
         target="_blank" rel="noopener"
         style="display:block;text-align:center;background:var(--lime);color:#0c1a0c;padding:0.6rem;border-radius:7px;font-size:0.82rem;font-weight:700;text-decoration:none">🚀 Invest — Get Prime Free</a>
      <button onclick="document.getElementById('couponSection').scrollIntoView({behavior:'smooth'})"
         style="background:transparent;border:1px solid var(--lime);color:var(--lime);padding:0.5rem;border-radius:7px;font-size:0.82rem;cursor:pointer">I have a coupon code</button>
    </div>
    <?php endif; ?>
  </div>

  <!-- Prime Member -->
  <?php $active = $current_plan==='premium'; ?>
  <div class="portal-card" style="<?= $active?'border-color:var(--gold);':'' ?>position:relative">
    <div style="font-family:'DM Mono',monospace;font-size:0.62rem;letter-spacing:0.15em;color:var(--gold);margin-bottom:0.5rem">PRIME MEMBER</div>
    <div style="font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:700;color:var(--cream)">Member</div>
    <div style="font-size:1.75rem;font-weight:700;color:var(--cream);margin:0.5rem 0"><?= format_inr(499) ?><span style="font-size:0.85rem;color:var(--text-muted)">/mo</span></div>
    <div style="font-size:0.78rem;color:var(--gold);margin-bottom:0.5rem"><?= format_inr(4999) ?>/year — save 17%</div>
    <?php if ($active): ?>
      <span class="badge badge-gold" style="margin-bottom:0.5rem">★ Member</span>
      <?php if ($sub && $sub['expires_at']): ?>
        <?php $exp2 = new DateTime($sub['expires_at']); $days2 = (int)(new DateTime())->diff($exp2)->days; ?>
        <div style="font-size:0.75rem;color:<?= $days2 <= 30 ? 'var(--gold)' : 'var(--text-muted)' ?>;margin-bottom:0.5rem">
          <?= $days2 <= 30 ? '⚠ ' : '' ?>Renews <?= $exp2->format('d M Y') ?> · <?= $days2 ?> days left
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <ul style="list-style:none;padding:0;margin:1rem 0;display:flex;flex-direction:column;gap:0.4rem;font-size:0.82rem;color:var(--text-secondary)">
      <li>✅ Everything in Prime</li>
      <li>✅ Priority advisor support</li>
      <li>✅ Early access to new features</li>
    </ul>
    <?php if (!$active): ?>
    <div style="display:flex;flex-direction:column;gap:0.5rem">
      <a href="<?= SITE_URL ?>/portal/checkout.php?cycle=annual"
         style="display:block;text-align:center;background:var(--gold);color:#0c1a0c;padding:0.6rem;border-radius:7px;font-size:0.82rem;font-weight:700;text-decoration:none">Pay Annual ₹4,999 <span style="font-weight:400;font-size:0.75rem">— save 17%</span></a>
      <a href="<?= SITE_URL ?>/portal/checkout.php?cycle=monthly"
         style="display:block;text-align:center;background:transparent;border:1px solid var(--gold);color:var(--gold);padding:0.55rem;border-radius:7px;font-size:0.82rem;font-weight:600;text-decoration:none">Pay Monthly ₹499</a>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Coupon section -->
<div class="portal-card" id="couponSection" style="max-width:560px;margin:0 auto">
  <div style="text-align:center;margin-bottom:1.25rem">
    <div style="font-size:1.5rem;margin-bottom:0.4rem">🎯</div>
    <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.25rem;color:var(--cream);margin-bottom:0.3rem">Already investing with us?</h3>
    <p style="font-size:0.875rem;color:var(--text-secondary)">Enter your coupon code to unlock all premium features free for 1 year.</p>
  </div>
  <?php if ($coupon_error): ?><div class="flash-error" style="margin-bottom:0.75rem"><?= htmlspecialchars($coupon_error) ?></div><?php endif; ?>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
    <div style="display:flex;gap:0.5rem">
      <input type="text" name="coupon_code" placeholder="Enter coupon code (e.g. GOPRIME)" required maxlength="50"
        class="form-input" style="text-transform:uppercase;flex:1">
      <button type="submit" class="btn-primary" style="flex-shrink:0">Apply →</button>
    </div>
  </form>
  <div style="display:flex;gap:0.75rem;justify-content:center;margin-top:1rem;flex-wrap:wrap">
    <a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=<?= urlencode('Hi, I need a coupon code for Prime Financials premium access.') ?>"
       target="_blank" rel="noopener" class="btn-ghost btn-sm">💬 WhatsApp for coupon</a>
    <a href="<?= CALENDLY_URL ?>" target="_blank" rel="noopener" class="btn-ghost btn-sm">📅 Book a session</a>
    <a href="<?= ONBOARDING_URL ?>?utm_source=pricing_bottom&utm_medium=portal"
       target="_blank" rel="noopener" class="btn-outline btn-sm">🚀 Start investing →</a>
  </div>
</div>

<?php require_once '../includes/portal-footer.php'; ?>
