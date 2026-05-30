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

$fund_types = ['equity','debt','hybrid','elss','index','international','liquid','fd','nps','gold','other'];
$type_colours = ['equity'=>'badge-green','debt'=>'badge-gold','hybrid'=>'badge-green','elss'=>'badge-green','index'=>'badge-muted','international'=>'badge-muted','liquid'=>'badge-muted','fd'=>'badge-gold','nps'=>'badge-gold','gold'=>'badge-gold','other'=>'badge-muted'];

// ── Handle Add / Edit ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action']??'', ['add_holding','edit_holding'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $d = [
            ':uid'       => $uid,
            ':fund_name' => trim($_POST['fund_name'] ?? ''),
            ':fund_house'=> trim($_POST['fund_house'] ?? ''),
            ':fund_type' => in_array($_POST['fund_type']??'',$fund_types) ? $_POST['fund_type'] : 'other',
            ':units'     => (float)($_POST['units_held'] ?? 0),
            ':avg_nav'   => (float)($_POST['avg_nav'] ?? 0),
            ':cur_nav'   => (float)($_POST['current_nav'] ?? 0),
            ':invested'  => (float)($_POST['invested_amount'] ?? 0),
            ':cur_val'   => (float)($_POST['current_nav'] ?? 0) * (float)($_POST['units_held'] ?? 0),
            ':purchase'  => $_POST['purchase_date'] ?: null,
            ':maturity'  => $_POST['maturity_date'] ?: null,
            ':folio'     => trim($_POST['folio_number'] ?? '') ?: null,
            ':sip'       => isset($_POST['sip_active']) ? 1 : 0,
            ':sip_amt'   => (float)($_POST['sip_amount'] ?? 0) ?: null,
            ':sip_date'  => (int)($_POST['sip_date'] ?? 0) ?: null,
            ':rate'      => (float)($_POST['interest_rate'] ?? 0) ?: null,
            ':notes'     => trim($_POST['notes'] ?? '') ?: null,
        ];
        if (!$d[':fund_name']) { $error = 'Fund name is required.'; }
        else {
            try {
                if (($_POST['action']??'') === 'add_holding') {
                    $db->prepare("INSERT INTO portfolio_entries (user_id,fund_name,fund_house,fund_type,units_held,avg_nav,current_nav,invested_amount,current_value,purchase_date,maturity_date,folio_number,sip_active,sip_amount,sip_date,interest_rate,notes) VALUES (:uid,:fund_name,:fund_house,:fund_type,:units,:avg_nav,:cur_nav,:invested,:cur_val,:purchase,:maturity,:folio,:sip,:sip_amt,:sip_date,:rate,:notes)")
                       ->execute($d);
                } else {
                    $hid = (int)($_POST['holding_id'] ?? 0);
                    $d[':id'] = $hid;
                    $db->prepare("UPDATE portfolio_entries SET fund_name=:fund_name,fund_house=:fund_house,fund_type=:fund_type,units_held=:units,avg_nav=:avg_nav,current_nav=:cur_nav,invested_amount=:invested,current_value=:cur_val,purchase_date=:purchase,maturity_date=:maturity,folio_number=:folio,sip_active=:sip,sip_amount=:sip_amt,sip_date=:sip_date,interest_rate=:rate,notes=:notes WHERE id=:id AND user_id=:uid")
                       ->execute($d);
                }
                $_SESSION['flash'] = ['type'=>'success','message'=>'Holding saved successfully.'];
                header('Location: ' . SITE_URL . '/portal/portfolio.php'); exit;
            } catch (PDOException $e) { error_log($e->getMessage()); $error = 'Could not save holding.'; }
        }
    }
}

// ── Handle Delete ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete_holding') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $hid = (int)($_POST['holding_id'] ?? 0);
        try {
            $db->prepare("DELETE FROM portfolio_entries WHERE id = :id AND user_id = :uid")->execute([':id'=>$hid,':uid'=>$uid]);
            $_SESSION['flash'] = ['type'=>'success','message'=>'Holding removed.'];
            header('Location: ' . SITE_URL . '/portal/portfolio.php'); exit;
        } catch (PDOException $e) { error_log($e->getMessage()); $error = 'Could not delete holding.'; }
    }
}

// ── Fetch holdings ────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM portfolio_entries WHERE user_id = :uid ORDER BY fund_type, fund_name");
$stmt->execute([':uid' => $uid]);
$holdings = $stmt->fetchAll();

$total_invested = array_sum(array_column($holdings, 'invested_amount'));
$total_current  = array_sum(array_column($holdings, 'current_value'));
$gain     = $total_current - $total_invested;
$gain_pct = $total_invested > 0 ? ($gain / $total_invested) * 100 : 0;

// XIRR approximation
$stmt = $db->prepare("SELECT AVG(DATEDIFF(NOW(), purchase_date)) as avg_days FROM portfolio_entries WHERE user_id = :uid AND purchase_date IS NOT NULL AND invested_amount > 0");
$stmt->execute([':uid' => $uid]);
$avg_days = (float)($stmt->fetchColumn() ?: 0);
$xirr = 0;
if ($avg_days > 0 && $total_invested > 0 && $total_current > 0) {
    $years = $avg_days / 365;
    $xirr  = (pow($total_current / $total_invested, 1 / $years) - 1) * 100;
}

// Allocation by type
$allocation = [];
foreach ($holdings as $h) {
    $t = ucfirst($h['fund_type']);
    $allocation[$t] = ($allocation[$t] ?? 0) + (float)$h['current_value'];
}

// Edit prefill
$edit_holding = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM portfolio_entries WHERE id = :id AND user_id = :uid");
    $stmt->execute([':id'=>(int)$_GET['edit'],':uid'=>$uid]);
    $edit_holding = $stmt->fetch();
}

$page_title = 'My Portfolio — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">My Finances</p>
<h1 class="page-title">Portfolio</h1>

<?php if ($error): ?>
  <div class="flash-error"><?= htmlspecialchars($error, ENT_QUOTES,'UTF-8') ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-box"><div class="stat-label">Total Invested</div><div class="stat-value neutral">₹<?= number_format($total_invested,0) ?></div></div>
  <div class="stat-box"><div class="stat-label">Current Value</div><div class="stat-value neutral">₹<?= number_format($total_current,0) ?></div></div>
  <div class="stat-box">
    <div class="stat-label">Gain / Loss</div>
    <div class="stat-value <?= $gain>=0?'positive':'negative' ?>"><?= $gain>=0?'+':'' ?>₹<?= number_format(abs($gain),0) ?></div>
    <div class="stat-sub"><?= $gain>=0?'+':'' ?><?= number_format($gain_pct,2) ?>%</div>
  </div>
  <div class="stat-box">
    <div class="stat-label">Est. XIRR</div>
    <div class="stat-value <?= $xirr>=0?'positive':'negative' ?>"><?= $total_invested>0?number_format($xirr,2).'%':'—' ?></div>
  </div>
</div>

<!-- Add/Edit form -->
<div class="portal-card" style="margin-top:1.5rem">
  <div style="display:flex;justify-content:space-between;align-items:center;cursor:pointer" onclick="toggleForm()">
    <div class="card-title" style="margin-bottom:0"><?= $edit_holding ? '✏ Edit Holding' : '+ Add Holding' ?></div>
    <span id="form-toggle-icon" style="color:var(--lime);font-size:1.25rem"><?= ($edit_holding||$error) ? '−' : '+' ?></span>
  </div>
  <div id="holding-form" style="display:<?= ($edit_holding||$error) ? 'block' : 'none' ?>;margin-top:1.25rem">
    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
      <input type="hidden" name="action" value="<?= $edit_holding ? 'edit_holding' : 'add_holding' ?>">
      <?php if ($edit_holding): ?><input type="hidden" name="holding_id" value="<?= $edit_holding['id'] ?>"><?php endif; ?>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Fund / Asset Name *</label>
          <input class="form-input" type="text" name="fund_name" value="<?= htmlspecialchars($edit_holding['fund_name']??'_POST_fund_name'??'',ENT_QUOTES,'UTF-8') ?>" required placeholder="e.g. Mirae Asset Large Cap Fund">
        </div>
        <div class="form-group">
          <label class="form-label">Fund House</label>
          <input class="form-input" type="text" name="fund_house" value="<?= htmlspecialchars($edit_holding['fund_house']??'',ENT_QUOTES,'UTF-8') ?>" placeholder="e.g. Mirae Asset">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Fund Type</label>
          <select class="form-select" name="fund_type" id="fund_type" onchange="toggleFdFields()">
            <?php foreach ($fund_types as $t): ?>
            <option value="<?= $t ?>" <?= ($edit_holding['fund_type']??'')===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Purchase Date</label>
          <input class="form-input" type="date" name="purchase_date" value="<?= htmlspecialchars($edit_holding['purchase_date']??'',ENT_QUOTES,'UTF-8') ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Units Held</label>
          <input class="form-input" type="number" name="units_held" step="0.0001" value="<?= $edit_holding['units_held']??'' ?>" placeholder="0.0000" oninput="calcValue()">
        </div>
        <div class="form-group">
          <label class="form-label">Average NAV / Price (₹)</label>
          <input class="form-input" type="number" name="avg_nav" step="0.0001" value="<?= $edit_holding['avg_nav']??'' ?>" placeholder="0.0000" oninput="calcValue()">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Current NAV / Price (₹)</label>
          <input class="form-input" type="number" name="current_nav" id="cur_nav" step="0.0001" value="<?= $edit_holding['current_nav']??'' ?>" placeholder="0.0000" oninput="calcValue()">
        </div>
        <div class="form-group">
          <label class="form-label">Invested Amount (₹)</label>
          <input class="form-input" type="number" name="invested_amount" id="invested_amount" step="0.01" value="<?= $edit_holding['invested_amount']??'' ?>" placeholder="Auto-calculated">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Folio Number (optional)</label>
        <input class="form-input" type="text" name="folio_number" value="<?= htmlspecialchars($edit_holding['folio_number']??'',ENT_QUOTES,'UTF-8') ?>" placeholder="e.g. 12345678" style="max-width:220px">
      </div>

      <!-- FD/NPS fields -->
      <div id="fd-fields" style="display:none">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Interest Rate (% p.a.)</label>
            <input class="form-input" type="number" name="interest_rate" step="0.01" value="<?= $edit_holding['interest_rate']??'' ?>" placeholder="7.50">
          </div>
          <div class="form-group">
            <label class="form-label">Maturity Date</label>
            <input class="form-input" type="date" name="maturity_date" value="<?= htmlspecialchars($edit_holding['maturity_date']??'',ENT_QUOTES,'UTF-8') ?>">
          </div>
        </div>
      </div>

      <!-- SIP fields -->
      <div class="check-row" style="margin-bottom:0.75rem">
        <input type="checkbox" id="sip_active" name="sip_active" <?= ($edit_holding['sip_active']??0)?'checked':'' ?> onchange="toggleSip()">
        <label for="sip_active">SIP is active for this holding</label>
      </div>
      <div id="sip-fields" style="display:none">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Monthly SIP Amount (₹)</label>
            <input class="form-input" type="number" name="sip_amount" value="<?= $edit_holding['sip_amount']??'' ?>" placeholder="5000">
          </div>
          <div class="form-group">
            <label class="form-label">SIP Date (day of month)</label>
            <input class="form-input" type="number" name="sip_date" min="1" max="28" value="<?= $edit_holding['sip_date']??'' ?>" placeholder="1">
          </div>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Notes</label>
        <textarea class="form-textarea" name="notes" rows="2" placeholder="Any notes about this holding..."><?= htmlspecialchars($edit_holding['notes']??'',ENT_QUOTES,'UTF-8') ?></textarea>
      </div>

      <div style="display:flex;gap:0.75rem;flex-wrap:wrap">
        <button type="submit" class="btn-primary"><?= $edit_holding ? 'Update Holding' : 'Add to Portfolio' ?></button>
        <?php if ($edit_holding): ?>
          <a href="<?= SITE_URL ?>/portal/portfolio.php" class="btn-ghost">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Holdings table -->
<?php if (empty($holdings)): ?>
<div class="portal-card" style="text-align:center;padding:3rem;margin-top:1.5rem;color:var(--text-secondary)">
  <div style="font-size:2.5rem;margin-bottom:1rem">◈</div>
  Your portfolio is empty. Add your first holding above.
</div>
<?php else: ?>
<div class="portal-card" style="margin-top:1.5rem;padding:0">
  <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
    <div class="card-title" style="margin-bottom:0">Holdings (<?= count($holdings) ?>)</div>
  </div>
  <div class="table-wrapper" style="border:none;border-radius:0">
    <table class="portal-table" id="holdings-table">
      <thead><tr>
        <th onclick="sortTable(0)" style="cursor:pointer">Fund ↕</th>
        <th onclick="sortTable(1)" style="cursor:pointer">Type ↕</th>
        <th>Units</th>
        <th>Avg NAV</th>
        <th>Cur NAV</th>
        <th onclick="sortTable(5)" style="cursor:pointer">Invested ↕</th>
        <th onclick="sortTable(6)" style="cursor:pointer">Value ↕</th>
        <th onclick="sortTable(7)" style="cursor:pointer">Return ↕</th>
        <th>Action</th>
      </tr></thead>
      <tbody>
        <?php foreach ($holdings as $h):
          $ret = $h['invested_amount'] > 0 ? (($h['current_value'] - $h['invested_amount']) / $h['invested_amount']) * 100 : 0;
        ?>
        <tr>
          <td>
            <div style="font-weight:500;color:var(--cream)"><?= htmlspecialchars($h['fund_name'],ENT_QUOTES,'UTF-8') ?></div>
            <div style="font-size:0.75rem;color:var(--text-secondary)"><?= htmlspecialchars($h['fund_house']??'',ENT_QUOTES,'UTF-8') ?></div>
          </td>
          <td><span class="badge <?= $type_colours[$h['fund_type']]??'badge-muted' ?>"><?= ucfirst($h['fund_type']) ?></span></td>
          <td style="font-family:'DM Mono',monospace;font-size:0.82rem"><?= number_format((float)$h['units_held'],4) ?></td>
          <td style="font-family:'DM Mono',monospace;font-size:0.82rem">₹<?= number_format((float)$h['avg_nav'],2) ?></td>
          <td style="font-family:'DM Mono',monospace;font-size:0.82rem">₹<?= number_format((float)$h['current_nav'],2) ?></td>
          <td>₹<?= number_format((float)$h['invested_amount'],0) ?></td>
          <td>₹<?= number_format((float)$h['current_value'],0) ?></td>
          <td style="color:<?= $ret>=0?'var(--bright)':'var(--danger)' ?>;font-family:'DM Mono',monospace;font-size:0.82rem">
            <?= $ret>=0?'+':'' ?><?= number_format($ret,2) ?>%
          </td>
          <td>
            <div style="display:flex;gap:0.4rem">
              <a href="?edit=<?= $h['id'] ?>" class="btn-ghost btn-sm">Edit</a>
              <form method="POST" style="display:inline" onsubmit="return confirmAction('Delete this holding?',function(){})">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
                <input type="hidden" name="action" value="delete_holding">
                <input type="hidden" name="holding_id" value="<?= $h['id'] ?>">
                <button type="submit" class="btn-danger btn-sm">Del</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Allocation chart -->
<div class="portal-card" style="margin-top:1.5rem;max-width:480px">
  <div class="card-title">Asset Allocation</div>
  <canvas id="allocChart" height="260"></canvas>
</div>
<?php endif; ?>

<script>
function toggleForm() {
  var f = document.getElementById('holding-form');
  var i = document.getElementById('form-toggle-icon');
  var open = f.style.display !== 'none';
  f.style.display = open ? 'none' : 'block';
  i.textContent = open ? '+' : '−';
}
function calcValue() {
  var units = parseFloat(document.querySelector('[name="units_held"]').value) || 0;
  var avg   = parseFloat(document.querySelector('[name="avg_nav"]').value) || 0;
  var inv   = document.getElementById('invested_amount');
  if (units && avg) inv.value = (units * avg).toFixed(2);
}
function toggleFdFields() {
  var t = document.getElementById('fund_type').value;
  document.getElementById('fd-fields').style.display = (t === 'fd' || t === 'nps') ? 'block' : 'none';
}
function toggleSip() {
  document.getElementById('sip-fields').style.display = document.getElementById('sip_active').checked ? 'block' : 'none';
}
// Init
toggleFdFields();
if (document.getElementById('sip_active').checked) toggleSip();

function sortTable(col) {
  var table = document.getElementById('holdings-table');
  var rows = Array.from(table.tBodies[0].rows);
  var asc = table.dataset.sortCol == col && table.dataset.sortDir === 'asc';
  rows.sort(function(a, b) {
    var av = a.cells[col].innerText.replace(/[₹,+%]/g,'').trim();
    var bv = b.cells[col].innerText.replace(/[₹,+%]/g,'').trim();
    var an = parseFloat(av), bn = parseFloat(bv);
    if (!isNaN(an) && !isNaN(bn)) return asc ? bn - an : an - bn;
    return asc ? bv.localeCompare(av) : av.localeCompare(bv);
  });
  rows.forEach(function(r){ table.tBodies[0].appendChild(r); });
  table.dataset.sortCol = col;
  table.dataset.sortDir = asc ? 'desc' : 'asc';
}

<?php if (!empty($allocation)): ?>
document.addEventListener('DOMContentLoaded', function(){
  const isDark = !document.documentElement.hasAttribute('data-theme');
  new Chart(document.getElementById('allocChart'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode(array_keys($allocation)) ?>,
      datasets: [{
        data: <?= json_encode(array_values($allocation)) ?>,
        backgroundColor:['#1B5E2A','#2E8540','#4CAF50','#8DC63F','#C9A84C','#558b2f','#a5d6a7','#66BB6A'],
        borderColor: isDark ? '#0c140c' : '#fff',
        borderWidth: 3, hoverOffset: 6
      }]
    },
    options: {
      cutout: '65%',
      plugins: {
        legend: { position:'bottom', labels:{ color: isDark?'#85a885':'#2a5a2a', font:{family:"'DM Mono'"}, padding:12, boxWidth:12 }},
        tooltip: { callbacks: { label: ctx => ' ₹' + ctx.raw.toLocaleString('en-IN',{maximumFractionDigits:0}) }}
      }
    }
  });
});
<?php endif; ?>
</script>

<?php require_once '../includes/portal-footer.php'; ?>
