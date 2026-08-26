<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('admin');

$db = get_db();

// Summary stats
$stats = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) AS total_revenue,
        SUM(CASE WHEN status='paid' AND billing_cycle='monthly' THEN 1 ELSE 0 END) AS monthly_count,
        SUM(CASE WHEN status='paid' AND billing_cycle='annual' THEN 1 ELSE 0 END) AS annual_count
    FROM payments
")->fetch(PDO::FETCH_ASSOC);

// Payment records (paginated — simple, last 100)
$payments = $db->query("
    SELECT p.*, u.full_name, u.email
    FROM payments p
    JOIN users u ON u.id = p.user_id
    ORDER BY p.created_at DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// Active premium subscribers
$active_subs = $db->query("
    SELECT u.full_name, u.email, s.billing_cycle, s.started_at, s.expires_at, s.cashfree_order_id
    FROM user_subscriptions s
    JOIN users u ON u.id = s.user_id
    WHERE s.plan_code = 'premium' AND s.status = 'active' AND (s.expires_at IS NULL OR s.expires_at > NOW())
    ORDER BY s.started_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Payments & Subscriptions — Prime Financials Admin';
require_once '../includes/admin-header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
  <div><p class="page-eyebrow">Admin</p><h1 class="page-title" style="margin-bottom:0">Payments &amp; Subscriptions</h1></div>
</div>

<!-- Summary cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem">
  <div class="portal-card" style="text-align:center">
    <div style="font-family:'IBM Plex Mono',monospace;font-size:0.58rem;color:var(--lime);letter-spacing:0.15em;text-transform:uppercase;margin-bottom:0.5rem">Total Revenue</div>
    <div style="font-size:1.4rem;font-weight:700;color:var(--cream)">₹<?= number_format((float)($stats['total_revenue'] ?? 0)) ?></div>
  </div>
  <div class="portal-card" style="text-align:center">
    <div style="font-family:'IBM Plex Mono',monospace;font-size:0.58rem;color:var(--lime);letter-spacing:0.15em;text-transform:uppercase;margin-bottom:0.5rem">Paid Orders</div>
    <div style="font-size:1.4rem;font-weight:700;color:var(--cream)"><?= (int)($stats['paid_count'] ?? 0) ?></div>
  </div>
  <div class="portal-card" style="text-align:center">
    <div style="font-family:'IBM Plex Mono',monospace;font-size:0.58rem;color:var(--lime);letter-spacing:0.15em;text-transform:uppercase;margin-bottom:0.5rem">Active Subscribers</div>
    <div style="font-size:1.4rem;font-weight:700;color:var(--cream)"><?= count($active_subs) ?></div>
  </div>
  <div class="portal-card" style="text-align:center">
    <div style="font-family:'IBM Plex Mono',monospace;font-size:0.58rem;color:var(--gold);letter-spacing:0.15em;text-transform:uppercase;margin-bottom:0.5rem">Monthly / Annual</div>
    <div style="font-size:1.4rem;font-weight:700;color:var(--cream)"><?= (int)($stats['monthly_count'] ?? 0) ?> / <?= (int)($stats['annual_count'] ?? 0) ?></div>
  </div>
</div>

<!-- Active subscribers -->
<?php if (!empty($active_subs)): ?>
<div class="portal-card" style="padding:0;margin-bottom:1.5rem">
  <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--border)"><div class="card-title" style="margin-bottom:0">Active Prime Subscribers (<?= count($active_subs) ?>)</div></div>
  <div class="table-wrapper" style="border:none;border-radius:0">
    <table class="portal-table">
      <thead><tr><th>Client</th><th>Email</th><th>Cycle</th><th>Started</th><th>Expires</th><th>Order ID</th></tr></thead>
      <tbody>
        <?php foreach ($active_subs as $s):
            $expires_ts  = $s['expires_at'] ? strtotime($s['expires_at']) : null;
            $days_left   = $expires_ts ? (int) ceil(($expires_ts - time()) / 86400) : null;
            $expiry_class = ($days_left !== null && $days_left <= 14) ? 'color:var(--gold)' : 'color:var(--text-muted)';
        ?>
        <tr>
          <td><?= htmlspecialchars($s['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="font-size:0.8rem;color:var(--text-secondary)"><?= htmlspecialchars($s['email'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><span class="badge badge-gold"><?= ucfirst($s['billing_cycle']) ?></span></td>
          <td style="font-size:0.78rem;color:var(--text-muted)"><?= date('d M Y', strtotime($s['started_at'])) ?></td>
          <td style="font-size:0.78rem;<?= $expiry_class ?>">
            <?= $s['expires_at'] ? date('d M Y', strtotime($s['expires_at'])) : '∞' ?>
            <?php if ($days_left !== null && $days_left <= 14): ?> <span style="font-size:0.7rem">(<?= $days_left ?>d left)</span><?php endif; ?>
          </td>
          <td style="font-family:'IBM Plex Mono',monospace;font-size:0.68rem;color:var(--text-muted)"><?= htmlspecialchars($s['cashfree_order_id'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- All payment transactions -->
<div class="portal-card" style="padding:0">
  <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--border)"><div class="card-title" style="margin-bottom:0">All Transactions (last 100)</div></div>
  <div class="table-wrapper" style="border:none;border-radius:0">
    <table class="portal-table">
      <thead><tr><th>Client</th><th>Order ID</th><th>Amount</th><th>Cycle</th><th>Method</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
        <?php if (empty($payments)): ?>
        <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-secondary)">No transactions yet.</td></tr>
        <?php else: foreach ($payments as $p): ?>
        <tr>
          <td>
            <?= htmlspecialchars($p['full_name'], ENT_QUOTES, 'UTF-8') ?>
            <div style="font-size:0.75rem;color:var(--text-muted)"><?= htmlspecialchars($p['email'], ENT_QUOTES, 'UTF-8') ?></div>
          </td>
          <td style="font-family:'IBM Plex Mono',monospace;font-size:0.68rem;color:var(--lime)"><?= htmlspecialchars($p['cashfree_order_id'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="font-weight:600">₹<?= number_format((float)$p['amount']) ?></td>
          <td><span class="badge badge-muted"><?= ucfirst($p['billing_cycle']) ?></span></td>
          <td style="font-size:0.78rem;color:var(--text-secondary)"><?= htmlspecialchars($p['payment_method'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
          <td>
            <?php
              $sc = match($p['status']) {
                'paid'         => 'badge-green',
                'failed'       => 'badge-red',
                'expired'      => 'badge-muted',
                'user_dropped' => 'badge-muted',
                default        => 'badge-muted',
              };
            ?>
            <span class="badge <?= $sc ?>"><?= ucfirst(str_replace('_', ' ', $p['status'])) ?></span>
          </td>
          <td style="font-size:0.75rem;color:var(--text-muted)"><?= date('d M Y, g:i a', strtotime($p['created_at'])) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once '../includes/admin-footer.php'; ?>
