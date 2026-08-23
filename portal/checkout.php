<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/cashfree.php';
require_once '../includes/subscription.php';
require_login();
require_role('client');

$uid   = get_user_id();
$cycle = in_array($_GET['cycle'] ?? '', ['monthly', 'annual']) ? $_GET['cycle'] : 'annual';

// Block if already on premium
$current_plan = get_user_plan($uid);
if ($current_plan === 'premium') {
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'You already have an active Prime Member subscription.'];
    header('Location: ' . SITE_URL . '/portal/pricing.php'); exit;
}

// Fetch user details for Cashfree customer object
$db   = get_db();
$stmt = $db->prepare("SELECT full_name, email, phone FROM users WHERE id = :uid LIMIT 1");
$stmt->execute([':uid' => $uid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header('Location: ' . SITE_URL . '/auth/logout.php'); exit; }

$amount   = $cycle === 'annual' ? CF_PLAN_ANNUAL_AMT : CF_PLAN_MONTHLY_AMT;
$order_id = 'pf_' . $uid . '_' . time() . '_' . bin2hex(random_bytes(3));

$error              = '';
$payment_session_id = '';

try {
    $cf = Cashfree::createOrder(
        orderId:       $order_id,
        amount:        (float) $amount,
        customerName:  $user['full_name'],
        customerEmail: $user['email'],
        customerPhone: $user['phone'] ?? '9999999999',
        returnUrl:     SITE_URL . '/portal/payment-verify.php?order_id={order_id}',
        notifyUrl:     SITE_URL . '/webhooks/cashfree.php'
    );

    $payment_session_id = $cf['payment_session_id'] ?? '';
    $cf_order_id        = $cf['order_id'] ?? $order_id;

    // Record in payments table (status = created)
    $db->prepare("INSERT IGNORE INTO payments (user_id, cashfree_order_id, amount, plan_code, billing_cycle, status) VALUES (:uid, :oid, :amt, 'premium', :cycle, 'created')")
       ->execute([':uid' => $uid, ':oid' => $cf_order_id, ':amt' => $amount, ':cycle' => $cycle]);

} catch (RuntimeException $e) {
    error_log('Checkout createOrder failed uid=' . $uid . ': ' . $e->getMessage());
    $error = 'Could not initialise payment. Please try again or contact support.';
}

$page_title = 'Checkout — Prime Financials';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/portal.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
body { background: var(--bg); color: var(--text-primary); font-family: 'DM Sans', system-ui, sans-serif; margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.checkout-wrap { text-align: center; max-width: 480px; padding: 2rem 1.5rem; }
.logo-link { display: inline-flex; align-items: center; gap: 0.6rem; text-decoration: none; margin-bottom: 2rem; }
.logo-mark { width: 36px; height: 36px; background: var(--mid); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-family: 'DM Mono', monospace; font-size: 0.75rem; font-weight: 600; color: #fff; }
.logo-name { font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; font-weight: 700; color: var(--cream); }
.checkout-card { background: var(--surface-1); border: 1px solid var(--border); border-radius: 14px; padding: 2rem; }
.plan-badge { font-family: 'DM Mono', monospace; font-size: 0.58rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--gold); margin-bottom: 0.5rem; }
.plan-name { font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; font-weight: 700; color: var(--cream); margin-bottom: 0.25rem; }
.plan-price { font-size: 2rem; font-weight: 700; color: var(--cream); }
.plan-price span { font-size: 0.9rem; color: var(--text-secondary); font-weight: 400; }
.plan-cycle { font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 1.5rem; }
.spinner { width: 28px; height: 28px; border: 3px solid var(--border); border-top-color: var(--mid); border-radius: 50%; animation: spin 0.7s linear infinite; margin: 1.5rem auto 0.75rem; }
@keyframes spin { to { transform: rotate(360deg); } }
.status-msg { font-size: 0.875rem; color: var(--text-secondary); }
.error-msg { background: rgba(239,83,80,0.08); border: 1px solid rgba(239,83,80,0.25); border-radius: 8px; padding: 1rem; color: #ef5350; font-size: 0.85rem; margin-top: 1rem; }
.back-link { display: inline-block; margin-top: 1.25rem; font-size: 0.8rem; color: var(--text-muted); text-decoration: none; }
.back-link:hover { color: var(--text-secondary); }
</style>
</head>
<body>
<div class="checkout-wrap">
  <a href="<?= SITE_URL ?>/portal/dashboard.php" class="logo-link">
    <div class="logo-mark">PF</div>
    <span class="logo-name">Prime Financials</span>
  </a>

  <div class="checkout-card">
    <div class="plan-badge">Prime Member</div>
    <div class="plan-name">Completing your subscription</div>
    <div class="plan-price">
      ₹<?= number_format($amount) ?><span>/<?= $cycle === 'annual' ? 'year' : 'month' ?></span>
    </div>
    <div class="plan-cycle"><?= $cycle === 'annual' ? '₹4,999/year — save 17% vs monthly' : '₹499/month, cancel anytime' ?></div>

    <?php if ($error): ?>
      <div class="error-msg"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <a href="<?= SITE_URL ?>/portal/pricing.php" class="back-link">← Back to pricing</a>
    <?php else: ?>
      <div id="spinnerWrap">
        <div class="spinner"></div>
        <div class="status-msg">Redirecting to secure payment…</div>
      </div>
      <div id="payBtn" style="display:none;margin-top:1.25rem">
        <button onclick="initPayment()" style="width:100%;background:var(--gold);color:#0c1a0c;border:none;padding:0.75rem;border-radius:8px;font-size:0.9rem;font-weight:700;cursor:pointer">
          Click here to pay ₹<?= number_format($amount) ?>
        </button>
        <div id="jsError" style="margin-top:0.75rem;font-size:0.75rem;color:#ef5350"></div>
      </div>
      <a href="<?= SITE_URL ?>/portal/pricing.php" class="back-link">Cancel and go back</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$error && $payment_session_id): ?>
<script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
<script>
const CF_SESSION_ID = <?= json_encode($payment_session_id) ?>;
const CF_MODE       = <?= CF_ENV === 'production' ? '"production"' : '"sandbox"' ?>;

function initPayment() {
  document.getElementById('payBtn').style.display = 'none';
  document.getElementById('spinnerWrap').style.display = 'block';
  try {
    const cashfree = Cashfree({ mode: CF_MODE });
    cashfree.checkout({ paymentSessionId: CF_SESSION_ID, redirectTarget: '_self' })
      .catch(function(err) {
        showFallback('SDK error: ' + (err && err.message ? err.message : JSON.stringify(err)));
      });
  } catch(e) {
    showFallback('Could not start payment: ' + e.message);
  }
}

function showFallback(msg) {
  document.getElementById('spinnerWrap').style.display = 'none';
  document.getElementById('payBtn').style.display = 'block';
  document.getElementById('jsError').textContent = msg || '';
}

// Try auto-redirect on load; show button after 4s if not redirected
window.addEventListener('load', function() {
  setTimeout(function() {
    if (document.getElementById('spinnerWrap')) {
      initPayment();
      // If still on page after 6 more seconds, show manual button
      setTimeout(function() {
        if (document.getElementById('spinnerWrap') &&
            document.getElementById('spinnerWrap').style.display !== 'none') {
          showFallback('');
        }
      }, 6000);
    }
  }, 800);
});
</script>
<?php endif; ?>
</body>
</html>
