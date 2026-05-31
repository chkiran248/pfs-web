<?php
/**
 * Premium gate overlay — include on locked pages instead of content.
 * Expects $gate_feature to be set before including.
 */
$gate_feature = $gate_feature ?? ($_SESSION['premium_gate_feature'] ?? 'this feature');
$gate_labels  = [
    'primo_ai'          => ['Primo AI',                  'PrimoAI is available to Active Investors and Prime Members.'],
    'rebalancer'        => ['Portfolio Rebalancer',       'The AI-powered Portfolio Rebalancer is a premium feature.'],
    'document_scanner'  => ['Document Scanner',           'Uploading and scanning statements is a premium feature.'],
    'cashflow_modeler'  => ['Lifetime Cashflow Modeler',  'The Cashflow Modeler is available to premium users.'],
    'overlap_analyzer'  => ['Overlap Analyzer',           'Portfolio overlap analysis is a premium feature.'],
    'tax_modeler'       => ['Tax Switch Modeler',         'The Tax Switch Modeler is a premium feature.'],
    'fd_tracker'        => ['FD Tracker',                 'The FD Tracker is available to premium users.'],
    'fund_watchlist'    => ['Fund Watchlist',             'Fund watchlist with alerts is a premium feature.'],
    'stock_watchlist'   => ['Stock Watchlist',            'Stock watchlist is available to premium users.'],
    'document_vault'    => ['Document Vault',             'Document storage and sharing is a premium feature.'],
    'portfolio_unlimited'=>['Unlimited Portfolio',        'Free plan is limited to 5 holdings. Upgrade to add unlimited.'],
];
[$gate_title, $gate_desc] = $gate_labels[$gate_feature] ?? ['Premium Feature', 'This feature is available on premium plans.'];

$coupon_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['coupon_code'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $coupon_error = 'Invalid request.'; }
    else {
        $result = redeem_coupon(get_user_id(), $_POST['coupon_code'] ?? '');
        if ($result['success']) {
            $_SESSION['flash'] = ['type' => 'success', 'message' => $result['message']];
            $back = $_SESSION['premium_gate_redirect'] ?? (SITE_URL . '/portal/dashboard.php');
            unset($_SESSION['premium_gate_feature'], $_SESSION['premium_gate_redirect']);
            header('Location: ' . $back); exit;
        }
        $coupon_error = $result['message'];
    }
}
?>
<div style="max-width:600px;margin:3rem auto;text-align:center">
  <div class="portal-card" style="padding:2.5rem">
    <div style="font-size:2.5rem;color:var(--lime);margin-bottom:0.75rem">✦</div>
    <h2 style="font-family:'Cormorant Garamond',serif;font-size:1.6rem;color:var(--cream);margin-bottom:0.5rem"><?= htmlspecialchars($gate_title) ?></h2>
    <p style="color:var(--text-secondary);font-size:0.9rem;margin-bottom:2rem"><?= htmlspecialchars($gate_desc) ?></p>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem">
      <!-- Option 1: Coupon -->
      <div style="background:var(--surface-2);border:1px solid rgba(141,198,63,0.25);border-radius:10px;padding:1.25rem;text-align:left">
        <div style="font-family:'DM Mono',monospace;font-size:0.6rem;letter-spacing:0.15em;color:var(--lime);margin-bottom:0.4rem">HAVE A COUPON?</div>
        <p style="font-size:0.82rem;color:var(--text-secondary);margin-bottom:0.75rem">Already investing with us? Enter your code for free premium access.</p>
        <?php if ($coupon_error): ?><div class="flash-error" style="margin-bottom:0.5rem;font-size:0.8rem"><?= htmlspecialchars($coupon_error) ?></div><?php endif; ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES,'UTF-8') ?>">
          <div style="display:flex;gap:0.4rem">
            <input type="text" name="coupon_code" placeholder="e.g. GOPRIME" maxlength="50" required
              style="flex:1;background:var(--surface-1);border:1px solid var(--border);border-radius:6px;padding:0.5rem 0.75rem;color:var(--cream);font-family:'DM Mono',monospace;font-size:0.85rem;text-transform:uppercase">
            <button type="submit" style="background:var(--mid);color:#fff;border:none;border-radius:6px;padding:0.5rem 0.875rem;font-family:'DM Sans',sans-serif;font-size:0.82rem;cursor:pointer;white-space:nowrap">Apply</button>
          </div>
        </form>
        <a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=<?= urlencode('Hi, I would like a coupon code for Prime Financials premium access.') ?>"
           target="_blank" rel="noopener"
           style="display:inline-block;margin-top:0.6rem;font-size:0.75rem;color:var(--lime);text-decoration:none">💬 WhatsApp us for a coupon →</a>
      </div>

      <!-- Option 2: Subscribe -->
      <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:1.25rem;text-align:left">
        <div style="font-family:'DM Mono',monospace;font-size:0.6rem;letter-spacing:0.15em;color:var(--text-muted);margin-bottom:0.4rem">SUBSCRIBE</div>
        <div style="font-family:'Cormorant Garamond',serif;font-size:1.8rem;color:var(--cream);margin-bottom:0.15rem"><?= format_inr(499) ?><span style="font-size:0.9rem;color:var(--text-muted)">/mo</span></div>
        <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0.75rem">or <?= format_inr(4999) ?>/year (save 17%)</p>
        <a href="<?= SITE_URL ?>/portal/pricing.php" style="display:block;text-align:center;background:var(--mid);color:#fff;padding:0.55rem 0.75rem;border-radius:6px;font-size:0.82rem;font-weight:500;text-decoration:none">View Plans →</a>
      </div>
    </div>

    <!-- Start investing CTA -->
    <div style="background:linear-gradient(135deg,var(--forest),var(--mid));border-radius:10px;padding:1.1rem 1.25rem;text-align:left">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem">
        <div>
          <div style="font-size:0.78rem;font-weight:600;color:#fff">💡 Get premium FREE</div>
          <div style="font-size:0.75rem;color:rgba(255,255,255,0.75);margin-top:0.15rem">Start investing with Prime Financials and all premium features are free.</div>
        </div>
        <a href="<?= ONBOARDING_URL ?>?utm_source=premium_gate&utm_medium=portal&utm_content=<?= urlencode($gate_feature) ?>"
           target="_blank" rel="noopener"
           style="background:var(--lime);color:#0c1a0c;padding:0.5rem 1rem;border-radius:6px;font-size:0.82rem;font-weight:600;text-decoration:none;white-space:nowrap;flex-shrink:0">🚀 Start Investing →</a>
      </div>
    </div>
  </div>
</div>
