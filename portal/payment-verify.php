<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/cashfree.php';
require_once '../includes/subscription.php';
require_once '../includes/mailer.php';
require_login();
require_role('client');

$uid      = get_user_id();
$order_id = trim($_GET['order_id'] ?? '');

$result  = 'error';
$message = 'Something went wrong. Please contact support.';

if (!$order_id) {
    $result  = 'error';
    $message = 'Invalid payment return. No order ID found.';
} else {
    try {
        // Verify the order belongs to this user
        $db   = get_db();
        $stmt = $db->prepare("SELECT id, billing_cycle, status FROM payments WHERE cashfree_order_id = :oid AND user_id = :uid LIMIT 1");
        $stmt->execute([':oid' => $order_id, ':uid' => $uid]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            $result  = 'error';
            $message = 'Payment record not found for your account.';
        } elseif ($payment['status'] === 'paid') {
            // Already processed (e.g. webhook beat us here)
            $result  = 'success';
            $message = 'Your Prime Member subscription is active!';
        } else {
            // Fetch live order status from Cashfree
            $cf_order = Cashfree::getOrder($order_id);
            $cf_status = $cf_order['order_status'] ?? 'UNKNOWN';

            if ($cf_status === 'PAID') {
                // Fetch payment method from payments endpoint
                $payments  = Cashfree::getOrderPayments($order_id);
                $pay_method = '';
                $pay_id     = '';
                if (!empty($payments) && is_array($payments)) {
                    $first       = $payments[0];
                    $pay_id      = $first['cf_payment_id'] ?? '';
                    $pay_method  = $first['payment_group'] ?? '';
                }

                // Update payments table with CF payment details
                $db->prepare("UPDATE payments SET cashfree_payment_id = :pid, payment_method = :pm WHERE cashfree_order_id = :oid")
                   ->execute([':pid' => $pay_id, ':pm' => $pay_method, ':oid' => $order_id]);

                // Activate subscription (also marks payment as paid)
                $billing_cycle = $payment['billing_cycle'];
                $activated     = activate_paid_subscription($uid, $order_id, $billing_cycle);

                if ($activated) {
                    $result  = 'success';
                    $message = 'Your Prime Member subscription is now active!';

                    // Send confirmation email
                    $user_stmt = $db->prepare("SELECT full_name, email FROM users WHERE id = :uid LIMIT 1");
                    $user_stmt->execute([':uid' => $uid]);
                    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($user) {
                        $amount_fmt = $billing_cycle === 'annual' ? '₹4,999/year' : '₹499/month';
                        $expires    = $billing_cycle === 'annual' ? '1 year' : '30 days';
                        $email_body = "
                        <h2 style='color:#1B5E2A'>Welcome to Prime Membership!</h2>
                        <p>Hi {$user['full_name']},</p>
                        <p>Your <strong>Prime Financials Prime Member</strong> subscription is now active.</p>
                        <table style='border-collapse:collapse;margin:1rem 0'>
                            <tr><td style='padding:0.4rem 1rem 0.4rem 0;color:#666'>Plan</td><td><strong>Prime Member</strong></td></tr>
                            <tr><td style='padding:0.4rem 1rem 0.4rem 0;color:#666'>Amount Paid</td><td><strong>{$amount_fmt}</strong></td></tr>
                            <tr><td style='padding:0.4rem 1rem 0.4rem 0;color:#666'>Valid For</td><td><strong>{$expires}</strong></td></tr>
                            <tr><td style='padding:0.4rem 1rem 0.4rem 0;color:#666'>Order ID</td><td><code>{$order_id}</code></td></tr>
                        </table>
                        <p>You now have full access to PrimoAI, Portfolio Rebalancer, Watchlists, Document Vault, and all premium tools.</p>
                        <p><a href='" . SITE_URL . "/portal/dashboard.php' style='background:#2E8540;color:#fff;padding:0.6rem 1.25rem;border-radius:6px;text-decoration:none;display:inline-block'>Go to Portal →</a></p>
                        <p style='font-size:0.8rem;color:#999'>Questions? WhatsApp us at +91 99800 01338 or reply to this email.</p>";
                        send_email($user['email'], '[Prime Financials] Prime Member Subscription Activated', $email_body, "Your Prime Member subscription is active. Order ID: {$order_id}");
                    }
                } else {
                    $result  = 'error';
                    $message = 'Payment received but subscription activation failed. Please contact support with Order ID: ' . htmlspecialchars($order_id, ENT_QUOTES, 'UTF-8');
                }

            } elseif (in_array($cf_status, ['EXPIRED', 'CANCELLED'])) {
                $db->prepare("UPDATE payments SET status = 'expired' WHERE cashfree_order_id = :oid")
                   ->execute([':oid' => $order_id]);
                $result  = 'failed';
                $message = 'Payment expired or was cancelled. You have not been charged. Please try again.';
            } else {
                // ACTIVE or unknown — payment not completed
                $result  = 'pending';
                $message = 'Payment is still processing (Status: ' . htmlspecialchars($cf_status, ENT_QUOTES, 'UTF-8') . '). If your money was deducted, please contact support with Order ID: ' . htmlspecialchars($order_id, ENT_QUOTES, 'UTF-8');
            }
        }
    } catch (RuntimeException $e) {
        error_log('payment-verify error: ' . $e->getMessage());
        $result  = 'error';
        $message = 'Could not verify payment status. Please contact support with Order ID: ' . htmlspecialchars($order_id, ENT_QUOTES, 'UTF-8');
    }
}

// Redirect on success, render UI on failure/pending
if ($result === 'success') {
    $_SESSION['flash'] = ['type' => 'success', 'message' => '🎉 ' . $message . ' Welcome to Prime Membership!'];
    header('Location: ' . SITE_URL . '/portal/dashboard.php'); exit;
}

$page_title = 'Payment Status — Prime Financials';
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
.status-wrap { text-align: center; max-width: 460px; padding: 2rem 1.5rem; }
.logo-link { display: inline-flex; align-items: center; gap: 0.6rem; text-decoration: none; margin-bottom: 2rem; }
.logo-mark { width: 36px; height: 36px; background: var(--mid); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-family: 'DM Mono', monospace; font-size: 0.75rem; font-weight: 600; color: #fff; }
.logo-name { font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; font-weight: 700; color: var(--cream); }
.status-card { background: var(--surface-1); border: 1px solid var(--border); border-radius: 14px; padding: 2rem; }
.status-icon { font-size: 2.5rem; margin-bottom: 1rem; }
.status-title { font-family: 'Cormorant Garamond', serif; font-size: 1.4rem; font-weight: 700; color: var(--cream); margin-bottom: 0.75rem; }
.status-msg { font-size: 0.85rem; color: var(--text-secondary); line-height: 1.7; margin-bottom: 1.5rem; }
.order-mono { font-family: 'DM Mono', monospace; font-size: 0.72rem; color: var(--text-muted); margin-top: 1rem; }
.btn-row { display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; }
</style>
</head>
<body>
<div class="status-wrap">
  <a href="<?= SITE_URL ?>/portal/dashboard.php" class="logo-link">
    <div class="logo-mark">PF</div>
    <span class="logo-name">Prime Financials</span>
  </a>

  <div class="status-card">
    <?php if ($result === 'failed'): ?>
      <div class="status-icon">❌</div>
      <div class="status-title">Payment Not Completed</div>
    <?php elseif ($result === 'pending'): ?>
      <div class="status-icon">⏳</div>
      <div class="status-title">Payment Processing</div>
    <?php else: ?>
      <div class="status-icon">⚠️</div>
      <div class="status-title">Something Went Wrong</div>
    <?php endif; ?>

    <div class="status-msg"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>

    <?php if ($order_id): ?>
      <div class="order-mono">Order ID: <?= htmlspecialchars($order_id, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="btn-row" style="margin-top:1.5rem">
      <a href="<?= SITE_URL ?>/portal/pricing.php" class="btn-primary">Try Again</a>
      <a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=<?= urlencode('Hi, I have a payment issue. Order ID: ' . $order_id) ?>" target="_blank" rel="noopener" class="btn-ghost">💬 WhatsApp Support</a>
    </div>
  </div>
</div>
</body>
</html>
