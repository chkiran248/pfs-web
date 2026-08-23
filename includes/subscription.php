<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * Returns the highest active plan for a user.
 * 'free' | 'active_investor' | 'premium'
 */
function get_user_plan(int $user_id): string {
    $db   = get_db();
    $stmt = $db->prepare("
        SELECT plan_code FROM user_subscriptions
        WHERE user_id = :uid AND status = 'active'
          AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY FIELD(plan_code,'premium','active_investor','free') ASC
        LIMIT 1
    ");
    $stmt->execute([':uid' => $user_id]);
    return $stmt->fetchColumn() ?: 'free';
}

/** Check if user's plan allows a specific feature. */
function can_access(int $user_id, string $feature): bool {
    $free_features = [
        'sip_calculator','goal_planner','tax_calculator','nps_projector',
        'insurance_checker','risk_quiz','stock_research','sector_tracker',
        'mf_advisory_view','portfolio_basic',
    ];
    if (in_array($feature, $free_features)) return true;

    $plan = get_user_plan($user_id);
    return in_array($plan, ['active_investor','premium']);
}

/** Plan config for badges. */
function get_plan_config(string $plan): array {
    return match($plan) {
        'active_investor' => ['label'=>'Prime',    'icon'=>'✦', 'colour'=>'var(--lime)', 'bg'=>'rgba(141,198,63,0.10)', 'border'=>'rgba(141,198,63,0.25)'],
        'premium'         => ['label'=>'Member',   'icon'=>'★', 'colour'=>'var(--gold)', 'bg'=>'rgba(201,168,76,0.10)', 'border'=>'rgba(201,168,76,0.25)'],
        default           => ['label'=>'Explorer', 'icon'=>'◎', 'colour'=>'var(--text-muted)', 'bg'=>'rgba(255,255,255,0.05)', 'border'=>'rgba(255,255,255,0.10)'],
    };
}

/** Redirect to pricing if feature is locked. */
function require_premium(string $feature, string $redirect_back = ''): void {
    if (!is_logged_in()) {
        $back = urlencode($redirect_back ?: $_SERVER['REQUEST_URI']);
        header('Location: ' . SITE_URL . '/auth/login.php?redirect=' . $back);
        exit;
    }
    if (!can_access(get_user_id(), $feature)) {
        $_SESSION['premium_gate_feature']  = $feature;
        $_SESSION['premium_gate_redirect'] = $redirect_back ?: $_SERVER['REQUEST_URI'];
        header('Location: ' . SITE_URL . '/portal/pricing.php?feature=' . urlencode($feature));
        exit;
    }
}

/**
 * Activate a paid Cashfree subscription for a user.
 * Called by payment-verify.php and the webhook after confirming payment.
 */
function activate_paid_subscription(int $user_id, string $cf_order_id, string $billing_cycle): bool {
    $db          = get_db();
    $plan_code   = 'premium';
    $days        = $billing_cycle === 'annual' ? 365 : 30;
    $expires_sql = "DATE_ADD(NOW(), INTERVAL {$days} DAY)";

    $db->beginTransaction();
    try {
        // Cancel any existing active subscriptions
        $db->prepare("UPDATE user_subscriptions SET status = 'cancelled' WHERE user_id = :uid AND status = 'active'")
           ->execute([':uid' => $user_id]);

        // Insert new subscription
        $db->prepare("
            INSERT INTO user_subscriptions
                (user_id, plan_code, status, billing_cycle, cashfree_order_id, started_at, expires_at)
            VALUES
                (:uid, :plan, 'active', :cycle, :oid, NOW(), {$expires_sql})
        ")->execute([
            ':uid'   => $user_id,
            ':plan'  => $plan_code,
            ':cycle' => $billing_cycle,
            ':oid'   => $cf_order_id,
        ]);

        // Mark payment as paid
        $db->prepare("UPDATE payments SET status = 'paid', paid_at = NOW() WHERE cashfree_order_id = :oid")
           ->execute([':oid' => $cf_order_id]);

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log('activate_paid_subscription error: ' . $e->getMessage());
        return false;
    }
}

/** Redeem a coupon code for the current user. */
function redeem_coupon(int $user_id, string $code): array {
    $db   = get_db();
    $code = strtoupper(trim($code));

    $stmt = $db->prepare("
        SELECT * FROM coupon_codes
        WHERE code = :code AND is_active = 1
          AND (valid_until IS NULL OR valid_until > NOW())
          AND (max_uses = 0 OR used_count < max_uses)
    ");
    $stmt->execute([':code' => $code]);
    $coupon = $stmt->fetch();

    if (!$coupon) {
        return ['success' => false, 'message' => 'Invalid or expired coupon code. Please check and try again.'];
    }

    // Already used?
    $check = $db->prepare("SELECT id FROM coupon_usage WHERE coupon_id=:cid AND user_id=:uid");
    $check->execute([':cid' => $coupon['id'], ':uid' => $user_id]);
    if ($check->fetch()) {
        return ['success' => false, 'message' => 'You have already used this coupon code.'];
    }

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE user_subscriptions SET status='cancelled' WHERE user_id=:uid AND status='active'")
           ->execute([':uid' => $user_id]);

        $db->prepare("INSERT INTO user_subscriptions (user_id,plan_code,status,billing_cycle,coupon_code,started_at,expires_at) VALUES (:uid,:plan,'active','coupon',:code,NOW(),DATE_ADD(NOW(),INTERVAL 1 YEAR))")
           ->execute([':uid' => $user_id, ':plan' => $coupon['plan_code'], ':code' => $code]);

        $db->prepare("INSERT INTO coupon_usage (coupon_id,user_id) VALUES (:cid,:uid)")
           ->execute([':cid' => $coupon['id'], ':uid' => $user_id]);

        $db->prepare("UPDATE coupon_codes SET used_count=used_count+1 WHERE id=:id")
           ->execute([':id' => $coupon['id']]);

        $db->commit();
        return ['success' => true, 'message' => '🎉 Coupon applied! You now have full access to all premium features for 1 year.', 'plan' => $coupon['plan_code']];
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Coupon redeem error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Something went wrong. Please try again.'];
    }
}
