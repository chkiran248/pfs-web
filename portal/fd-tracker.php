<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$db  = get_db();
$uid = get_user_id();

// Fetch FDs from portfolio_entries
$stmt = $db->prepare("SELECT * FROM portfolio_entries WHERE user_id = :uid AND fund_type = 'fd' ORDER BY maturity_date ASC");
$stmt->execute([':uid' => $uid]);
$fds = $stmt->fetchAll();

$total_invested = 0; $total_maturity = 0; $due_soon = 0; $due_soon_amt = 0;
$chart_data = [];

foreach ($fds as $fd) {
    $invested = (float)$fd['invested_amount'];
    $rate     = (float)($fd['interest_rate'] ?: 7);
    $purchase = $fd['purchase_date'] ?? date('Y-m-d');
    $maturity = $fd['maturity_date'] ?? null;
    $years    = $maturity ? max(0.08, (strtotime($maturity) - strtotime($purchase)) / (365 * 24 * 3600)) : 1;
    // Quarterly compounding
    $mat_val  = $invested * pow(1 + $rate / (100 * 4), 4 * $years);
    $total_invested += $invested;
    $total_maturity += $mat_val;
    $days_left = $maturity ? (int)((strtotime($maturity) - time()) / 86400) : 9999;
    if ($days_left <= 90 && $days_left >= 0) { $due_soon++; $due_soon_amt += $mat_val; }
    // Chart grouping by maturity month
    if ($maturity) {
        $key = date('M Y', strtotime($maturity));
        $chart_data[$key] = ($chart_data[$key] ?? 0) + $mat_val;
    }
}

$page_title = 'FD Tracker — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">My Finances</p>
<h1 class="page-title">FD Tracker</h1>
<p class="page-subtitle">Track all your Fixed Deposits and get maturity alerts</p>

<div class="stats-grid">
  <div class="stat-box"><div class="stat-label">Total FD Invested</div><div class="stat-value neutral"><?= format_inr($total_invested) ?></div></div>
  <div class="stat-box"><div class="stat-label">Total Maturity Value</div><div class="stat-value positive"><?= format_inr($total_maturity) ?></div></div>
  <div class="stat-box"><div class="stat-label">Total Interest Earned</div><div class="stat-value positive"><?= format_inr($total_maturity - $total_invested) ?></div></div>
  <div class="stat-box">
    <div class="stat-label">Due Within 90 Days</div>
    <div class="stat-value <?= $due_soon > 0 ? 'gold' : 'neutral' ?>"><?= $due_soon ?> FD<?= $due_soon !== 1 ? 's' : '' ?></div>
    <?php if ($due_soon > 0): ?><div class="stat-sub"><?= format_inr($due_soon_amt) ?> maturing</div><?php endif; ?>
  </div>
</div>

<?php if (empty($fds)): ?>
<div class="portal-card" style="text-align:center;padding:3rem;margin-top:1.5rem;color:var(--text-secondary)">
  <div style="font-size:2.5rem;margin-bottom:1rem">📋</div>
  <p>No FDs tracked yet.</p>
  <a href="<?= SITE_URL ?>/portal/portfolio.php" class="btn-primary btn-sm" style="margin-top:1rem">Add FD via Portfolio →</a>
</div>
<?php else: ?>

<!-- FD Table -->
<div class="portal-card" style="margin-top:1.5rem;padding:0">
  <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--border)"><div class="card-title" style="margin-bottom:0">All Fixed Deposits</div></div>
  <div class="table-wrapper" style="border:none;border-radius:0">
    <table class="portal-table">
      <thead><tr><th>Bank / Institution</th><th>Invested</th><th>Rate</th><th>Start Date</th><th>Maturity Date</th><th>Maturity Value</th><th>Days Left</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($fds as $fd):
          $invested = (float)$fd['invested_amount'];
          $rate     = (float)($fd['interest_rate'] ?: 7);
          $purchase = $fd['purchase_date'] ?? date('Y-m-d');
          $maturity = $fd['maturity_date'];
          $years    = $maturity ? max(0.08, (strtotime($maturity) - strtotime($purchase)) / (365 * 24 * 3600)) : 1;
          $mat_val  = $invested * pow(1 + $rate / 400, 4 * $years);
          $days_left = $maturity ? (int)((strtotime($maturity) - time()) / 86400) : null;
          $status = is_null($days_left) ? ['label'=>'Unknown','class'=>'badge-muted'] :
                    ($days_left < 0 ? ['label'=>'Matured','class'=>'badge-muted'] :
                    ($days_left <= 90 ? ['label'=>'Due Soon','class'=>'badge-gold'] : ['label'=>'Active','class'=>'badge-green']));
        ?>
        <tr>
          <td style="font-weight:500;color:var(--cream)"><?= htmlspecialchars($fd['fund_name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= format_inr($invested) ?></td>
          <td style="font-family:'DM Mono',monospace"><?= $rate ?>%</td>
          <td style="font-size:0.82rem"><?= $fd['purchase_date'] ? date('d M Y', strtotime($fd['purchase_date'])) : '—' ?></td>
          <td style="font-size:0.82rem"><?= $maturity ? date('d M Y', strtotime($maturity)) : '—' ?></td>
          <td style="color:var(--lime);font-family:'DM Mono',monospace"><?= format_inr($mat_val) ?></td>
          <td style="font-family:'DM Mono',monospace;font-size:0.82rem"><?= is_null($days_left) ? '—' : ($days_left < 0 ? 'Matured' : $days_left.' days') ?></td>
          <td><span class="badge <?= $status['class'] ?>"><?= $status['label'] ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Maturity chart -->
<?php if (!empty($chart_data)): ?>
<div class="portal-card" style="margin-top:1.5rem">
  <div class="card-title">Maturity Timeline</div>
  <canvas id="fdChart" height="200"></canvas>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
  new Chart(document.getElementById('fdChart'),{
    type:'bar',
    data:{
      labels:<?= json_encode(array_keys($chart_data)) ?>,
      datasets:[{data:<?= json_encode(array_values($chart_data)) ?>,backgroundColor:'#C9A84C',borderRadius:6,label:'Maturity Value'}]
    },
    options:{plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>'₹'+ctx.raw.toLocaleString('en-IN')}}},scales:{y:{ticks:{callback:v=>'₹'+v.toLocaleString('en-IN'),color:'#85a885'},grid:{color:'rgba(46,133,64,0.1)'}},x:{ticks:{color:'#85a885'},grid:{display:false}}}}
  });
});
</script>
<?php endif; ?>

<div class="portal-card" style="margin-top:1rem;text-align:center">
  <p style="color:var(--text-secondary);font-size:0.875rem;margin-bottom:1rem">Want better FD rates or FD ladder strategy advice?</p>
  <a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=I+tracked+my+FDs+on+primefin.in+and+want+advice+on+better+rates+and+reinvestment." class="btn-primary btn-sm" target="_blank" rel="noopener">💬 Get FD Advice</a>
</div>

<?php endif; ?>

<?php require_once '../includes/portal-footer.php'; ?>
