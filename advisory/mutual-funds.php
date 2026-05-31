<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$db  = get_db();
$uid = get_user_id();
$error = '';

// Handle watchlist add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_watchlist') {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        try {
            $db->prepare("INSERT INTO fund_watchlist (user_id, fund_name, fund_house) VALUES (:uid, :fn, :fh)")
               ->execute([':uid'=>$uid, ':fn'=>trim($_POST['fund_name']??''), ':fh'=>trim($_POST['fund_house']??'')]);
            $_SESSION['flash'] = ['type'=>'success','message'=>'Added to watchlist.'];
        } catch (PDOException $e) { error_log($e->getMessage()); }
        header('Location: ' . SITE_URL . '/advisory/mutual-funds.php'); exit;
    }
}

// Filters
$risk  = $_GET['risk'] ?? '';
$goal  = $_GET['goal'] ?? '';
$horizon = (int)($_GET['horizon'] ?? 0);

$where = ['is_active = 1'];
$params = [];
if ($risk)    { $where[] = 'risk_level = :risk';  $params[':risk']    = $risk; }
if ($goal)    { $where[] = 'FIND_IN_SET(:goal, goal_types)'; $params[':goal'] = $goal; }
if ($horizon) { $where[] = 'min_horizon_yrs <= :hor'; $params[':hor'] = $horizon; }

$sql  = "SELECT * FROM fund_recommendations WHERE " . implode(' AND ', $where) . " ORDER BY is_featured DESC, fund_name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$funds = $stmt->fetchAll();

$risk_opts    = ['low'=>'Low','moderate'=>'Moderate','high'=>'High','very_high'=>'Very High'];
$goal_opts    = ['retirement'=>'Retirement','education'=>'Education','wealth'=>'Wealth','tax_saving'=>'Tax Saving','emergency'=>'Emergency'];
$horizon_opts = [1=>'1yr+',3=>'3yr+',5=>'5yr+'];
$risk_badge   = ['low'=>'badge-green','moderate'=>'badge-gold','high'=>'badge-gold','very_high'=>'badge-muted'];

$page_title = 'Mutual Funds — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Advisory</p>
<h1 class="page-title">Mutual Fund Recommendations</h1>

<div class="disclaimer disclaimer--mf">
  Mutual Fund investments are subject to market risks. Please read all scheme-related documents carefully before investing. Past performance is not indicative of future results. Prime Financials — AMFI Registered MF Distributor (<?= AMFI_ARN ?>).
</div>

<!-- Filters -->
<form method="GET" style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end;margin:1.25rem 0">
  <div>
    <label class="form-label">Risk Level</label>
    <select class="form-select" name="risk" style="min-width:140px" onchange="this.form.submit()">
      <option value="">All Risks</option>
      <?php foreach ($risk_opts as $v=>$l): ?><option value="<?= $v ?>" <?= $risk===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label">Goal Type</label>
    <select class="form-select" name="goal" style="min-width:140px" onchange="this.form.submit()">
      <option value="">All Goals</option>
      <?php foreach ($goal_opts as $v=>$l): ?><option value="<?= $v ?>" <?= $goal===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label">Min Horizon</label>
    <select class="form-select" name="horizon" style="min-width:120px" onchange="this.form.submit()">
      <option value="0">Any</option>
      <?php foreach ($horizon_opts as $v=>$l): ?><option value="<?= $v ?>" <?= $horizon===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
  </div>
  <?php if ($risk||$goal||$horizon): ?><a href="<?= SITE_URL ?>/advisory/mutual-funds.php" class="btn-ghost btn-sm" style="align-self:flex-end">Clear Filters</a><?php endif; ?>
</form>

<!-- Fund grid -->
<?php if (empty($funds)): ?>
<div class="portal-card" style="text-align:center;padding:3rem;color:var(--text-secondary)">
  <div style="font-size:2rem;margin-bottom:1rem">◆</div>
  No recommendations match your filters. <a href="<?= SITE_URL ?>/advisory/mutual-funds.php" class="auth-link">Clear filters</a>
</div>
<?php else: ?>
<div class="grid-2">
  <?php foreach ($funds as $f): ?>
  <div class="portal-card">
    <?php if ($f['is_featured']): ?><div style="margin-bottom:0.6rem"><span class="badge badge-gold">★ Featured</span></div><?php endif; ?>
    <div style="margin-bottom:0.6rem">
      <div style="font-weight:600;color:var(--cream);font-size:1rem"><?= htmlspecialchars($f['fund_name'], ENT_QUOTES,'UTF-8') ?></div>
      <div style="font-size:0.8rem;color:var(--text-secondary)"><?= htmlspecialchars($f['fund_house']??'', ENT_QUOTES,'UTF-8') ?></div>
    </div>
    <div style="display:flex;gap:0.4rem;flex-wrap:wrap;margin-bottom:0.75rem">
      <span class="badge badge-muted"><?= htmlspecialchars($f['category']??'', ENT_QUOTES,'UTF-8') ?></span>
      <span class="badge <?= $risk_badge[$f['risk_level']]??'badge-muted' ?>"><?= ucfirst(str_replace('_',' ',$f['risk_level']??'')) ?></span>
      <?php if ($f['min_horizon_yrs']): ?><span class="badge badge-muted"><?= $f['min_horizon_yrs'] ?>yr+</span><?php endif; ?>
    </div>
    <!-- Returns -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.5rem;background:var(--surface-2);border-radius:8px;padding:0.75rem;margin-bottom:0.75rem">
      <?php foreach (['return_1yr'=>'1yr','return_3yr'=>'3yr','return_5yr'=>'5yr'] as $col=>$label): ?>
      <div style="text-align:center">
        <div style="font-size:0.62rem;color:var(--text-muted);font-family:'DM Mono',monospace"><?= $label ?></div>
        <div style="font-family:'DM Mono',monospace;color:<?= $f[$col]?'var(--bright)':'var(--text-muted)' ?>;font-size:0.9rem"><?= $f[$col]?$f[$col].'%':'—' ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($f['expense_ratio']): ?><div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:0.4rem">Expense: <?= $f['expense_ratio'] ?>% · AUM: <?= $f['aum_cr']?'₹'.number_format((float)$f['aum_cr'],0).'Cr':'—' ?></div><?php endif; ?>
    <?php if ($f['why_recommended']): ?><div style="font-size:0.82rem;color:var(--text-secondary);margin-bottom:0.75rem;line-height:1.5"><?= htmlspecialchars(mb_substr($f['why_recommended'],0,120), ENT_QUOTES,'UTF-8') ?><?= strlen($f['why_recommended'])>120?'…':'' ?></div><?php endif; ?>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
      <form method="POST" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES,'UTF-8') ?>">
        <input type="hidden" name="action" value="add_watchlist">
        <input type="hidden" name="fund_name" value="<?= htmlspecialchars($f['fund_name'], ENT_QUOTES,'UTF-8') ?>">
        <input type="hidden" name="fund_house" value="<?= htmlspecialchars($f['fund_house']??'', ENT_QUOTES,'UTF-8') ?>">
        <button type="submit" class="btn-outline btn-sm">★ Watchlist</button>
      </form>
      <a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=I+want+to+invest+in+<?= urlencode($f['fund_name']) ?>+recommended+on+primefin.in" class="btn-ghost btn-sm" target="_blank" rel="noopener">💬 Advisor</a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ELSS Planner -->
<div class="portal-card" style="margin-top:1.5rem">
  <div class="card-title">ELSS Tax Saver Planner</div>
  <div class="grid-2" style="align-items:start">
    <div>
      <p style="color:var(--text-secondary);font-size:0.9rem;line-height:1.7;margin-bottom:0.75rem">ELSS (Equity Linked Savings Scheme) funds offer tax savings under <strong style="color:var(--cream)">Section 80C</strong> up to ₹1.5 Lakhs per year — with the shortest lock-in of just <strong style="color:var(--lime)">3 years</strong> among all 80C instruments.</p>
      <div style="display:flex;flex-direction:column;gap:0.5rem">
        <?php foreach ([['📅','Shortest lock-in: 3 years (vs 5yr for PPF, 5yr for NSC)'],['💰','Tax saving: up to ₹46,800 at 30% tax bracket'],['📈','Historically 12–15% XIRR over 5+ years'],['🔄','SIP allowed — invest as low as ₹500/month']] as [$icon,$text]): ?>
        <div style="display:flex;gap:0.75rem;align-items:flex-start;font-size:0.875rem;color:var(--text-secondary)"><span><?= $icon ?></span><span><?= $text ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div style="background:var(--surface-2);border-radius:10px;padding:1.25rem">
      <div style="font-family:'DM Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.15em;margin-bottom:0.75rem">QUICK ELSS CALCULATOR</div>
      <div class="form-group"><label class="form-label">Monthly ELSS SIP (₹)</label><input class="form-input" type="number" id="elss_sip" value="12500" oninput="calcElss()"></div>
      <div style="margin-top:0.75rem;font-size:0.85rem;color:var(--text-secondary)">Annual investment: <span style="color:var(--cream)" id="elss_annual">₹1,50,000</span></div>
      <div style="font-size:0.85rem;color:var(--text-secondary)">Tax saved (30% bracket): <span style="color:var(--lime)" id="elss_saved">₹46,800</span></div>
      <div style="font-size:0.85rem;color:var(--text-secondary)">3-year corpus @13%: <span style="color:var(--bright)" id="elss_corpus">₹5,22,000</span></div>
    </div>
  </div>
</div>

<script>
function calcElss(){
  var sip=parseFloat(document.getElementById('elss_sip').value)||0;
  var annual=sip*12; var saved=Math.min(150000,annual)*0.30*1.04;
  var r=(13/100)/12,n=36,corpus=r>0?sip*((Math.pow(1+r,n)-1)/r)*(1+r):sip*n;
  document.getElementById('elss_annual').textContent='₹'+Math.round(annual).toLocaleString('en-IN');
  document.getElementById('elss_saved').textContent='₹'+Math.round(saved).toLocaleString('en-IN');
  document.getElementById('elss_corpus').textContent='₹'+Math.round(corpus).toLocaleString('en-IN');
}
document.addEventListener('DOMContentLoaded',calcElss);
</script>

<?php require_once '../includes/portal-footer.php'; ?>
