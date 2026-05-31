<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../ai/fund-classifier.php';
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
  <div class="stat-box"><div class="stat-label">Total Invested</div><div class="stat-value neutral"><?= format_inr($total_invested) ?></div></div>
  <div class="stat-box"><div class="stat-label">Current Value</div><div class="stat-value neutral"><?= format_inr($total_current) ?></div></div>
  <div class="stat-box">
    <div class="stat-label">Gain / Loss</div>
    <div class="stat-value <?= $gain>=0?'positive':'negative' ?>"><?= $gain>=0?'+':'' ?><?= format_inr(abs($gain)) ?></div>
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
    <form method="POST" novalidate id="add-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
      <input type="hidden" name="action" value="<?= $edit_holding ? 'edit_holding' : 'add_holding' ?>">
      <input type="hidden" name="fund_type" id="hidden_fund_type" value="<?= htmlspecialchars($edit_holding['fund_type']??'equity',ENT_QUOTES,'UTF-8') ?>">
      <?php if ($edit_holding): ?><input type="hidden" name="holding_id" value="<?= $edit_holding['id'] ?>"><?php endif; ?>

      <?php
        // Determine initial asset_type for edit mode
        $init_ft = $edit_holding['fund_type'] ?? '';
        $init_asset = match($init_ft) {
          'fd'                         => 'fd',
          'nps'                        => 'nps',
          'gold'                       => 'gold',
          'other'                      => 'other',
          'equity' => str_contains(strtolower($edit_holding['fund_name']??''), 'fund') ? 'mutual_fund' : 'stock',
          default => 'mutual_fund',
        };
        if ($edit_holding && !str_contains(strtolower($edit_holding['fund_name']??''), 'fund') &&
            in_array($edit_holding['fund_type']??'', ['equity'])) {
            $init_asset = 'stock';
        }
      ?>

      <!-- Bulk import tip -->
      <div style="display:flex;align-items:flex-start;gap:0.75rem;background:rgba(141,198,63,0.07);border:1px solid rgba(141,198,63,0.2);border-radius:10px;padding:0.875rem 1rem;margin-bottom:1.25rem">
        <span style="font-size:1.25rem;flex-shrink:0;margin-top:0.05rem">✦</span>
        <div style="font-size:0.85rem;color:var(--text-secondary);line-height:1.6">
          <strong style="color:var(--lime)">Adding multiple holdings?</strong>
          Upload your <strong style="color:var(--cream)">CAS statement, NSDL demat statement, or broker PDF</strong> to
          <a href="<?= SITE_URL ?>/portal/primo.php" style="color:var(--lime);font-weight:500;text-decoration:none">PrimoAI →</a>
          and it will automatically extract and add all your holdings at once — no manual entry needed.
        </div>
      </div>

      <!-- ── STEP 1: Asset Type ─────────────────────────────── -->
      <div class="form-group">
        <label class="form-label" style="font-size:0.9rem;color:var(--cream);font-weight:600">Step 1 — Select Asset Type</label>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.6rem;margin-top:0.5rem;max-width:640px" id="asset-type-grid">
          <?php
          $asset_types = [
            'mutual_fund' => ['🏦','Mutual Fund','SIP, ELSS, Debt, Index'],
            'stock'       => ['📈','Stock / Share','NSE / BSE equities'],
            'fd'          => ['🏛','Fixed Deposit','Bank & corp FDs'],
            'nps'         => ['🎯','NPS','National Pension System'],
            'gold'        => ['🥇','Gold / SGB','Physical, SGB, ETF'],
            'other'       => ['📋','Other','PPF, EPFO, ULIP...'],
          ];
          foreach ($asset_types as $at_key => [$icon, $label, $hint]):
            $active = ($init_asset === $at_key && $edit_holding) ? true : false;
          ?>
          <button type="button" class="asset-type-btn<?= $active?' active':'' ?>" data-type="<?= $at_key ?>"
            onclick="selectAssetType('<?= $at_key ?>')"
            style="border:1px solid var(--border);border-radius:10px;padding:0.875rem 0.5rem;background:var(--surface-2);cursor:pointer;text-align:center;transition:all 0.15s;display:flex;flex-direction:column;align-items:center;gap:0.25rem<?= $active?';border-color:var(--bright);background:rgba(76,175,80,0.1)':'' ?>">
            <span style="font-size:1.5rem;line-height:1"><?= $icon ?></span>
            <span style="font-size:0.82rem;font-weight:600;color:var(--cream);margin-top:0.2rem"><?= $label ?></span>
            <span style="font-size:0.68rem;color:var(--text-muted);line-height:1.3;word-break:break-word"><?= $hint ?></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ── STEP 2: Sub-type + fields (shown after asset type selected) ── -->
      <div id="step2-fields" style="display:<?= $edit_holding?'block':'none' ?>">
        <div style="height:1px;background:var(--border);margin:1.25rem 0"></div>
        <p class="page-eyebrow" style="margin-bottom:1rem">Step 2 — Enter Details</p>

        <!-- ── Mutual Fund fields ── -->
        <div class="asset-fields" id="fields-mutual_fund" style="display:none">
          <div class="form-group">
            <label class="form-label">Fund Category</label>
            <select class="form-select" id="mf_subtype" onchange="setMFType(this.value)">
              <option value="equity"        <?= ($init_ft==='equity'&&$init_asset==='mutual_fund')?'selected':'' ?>>Equity Fund (Large/Mid/Small/Flexi Cap)</option>
              <option value="elss"          <?= $init_ft==='elss'?'selected':'' ?>>ELSS / Tax Saver Fund (80C)</option>
              <option value="hybrid"        <?= $init_ft==='hybrid'?'selected':'' ?>>Hybrid / Balanced Advantage Fund</option>
              <option value="debt"          <?= $init_ft==='debt'?'selected':'' ?>>Debt Fund (Bond/Gilt/Short Duration)</option>
              <option value="index"         <?= $init_ft==='index'?'selected':'' ?>>Index Fund / ETF</option>
              <option value="gold"          <?= ($init_ft==='gold'&&$init_asset==='mutual_fund')?'selected':'' ?>>Gold Fund</option>
              <option value="international" <?= $init_ft==='international'?'selected':'' ?>>International / Global Fund</option>
              <option value="liquid"        <?= $init_ft==='liquid'?'selected':'' ?>>Liquid / Overnight / Money Market</option>
            </select>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Fund Name *</label>
              <input class="form-input" type="text" name="fund_name" id="fn_mf" value="<?= ($init_asset==='mutual_fund'&&$edit_holding)?htmlspecialchars($edit_holding['fund_name']??'',ENT_QUOTES,'UTF-8'):'' ?>" placeholder="e.g. Mirae Asset Large Cap Fund – Growth" required>
            </div>
            <div class="form-group">
              <label class="form-label">Fund House (AMC)</label>
              <input class="form-input" type="text" name="fund_house" id="fh_mf" value="<?= ($init_asset==='mutual_fund'&&$edit_holding)?htmlspecialchars($edit_holding['fund_house']??'',ENT_QUOTES,'UTF-8'):'' ?>" placeholder="e.g. Mirae Asset">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Units Held</label>
              <input class="form-input" type="number" name="units_held" id="u_mf" step="0.0001" value="<?= ($init_asset==='mutual_fund'&&$edit_holding)?($edit_holding['units_held']??''):'' ?>" placeholder="0.0000" oninput="calcMFValue()">
            </div>
            <div class="form-group">
              <label class="form-label">Average NAV (₹)</label>
              <input class="form-input" type="number" name="avg_nav" id="an_mf" step="0.0001" value="<?= ($init_asset==='mutual_fund'&&$edit_holding)?($edit_holding['avg_nav']??''):'' ?>" placeholder="0.0000" oninput="calcMFValue()">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Current NAV (₹)</label>
              <input class="form-input" type="number" name="current_nav" id="cn_mf" step="0.0001" value="<?= ($init_asset==='mutual_fund'&&$edit_holding)?($edit_holding['current_nav']??''):'' ?>" placeholder="0.0000" oninput="calcMFValue()">
            </div>
            <div class="form-group">
              <label class="form-label">Invested Amount (₹)</label>
              <input class="form-input" type="number" name="invested_amount" id="ia_mf" step="0.01" value="<?= ($init_asset==='mutual_fund'&&$edit_holding)?($edit_holding['invested_amount']??''):'' ?>" placeholder="Auto-calculated">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">First Purchase Date</label>
              <input class="form-input" type="date" name="purchase_date" id="pd_mf" value="<?= ($init_asset==='mutual_fund'&&$edit_holding)?htmlspecialchars($edit_holding['purchase_date']??'',ENT_QUOTES,'UTF-8'):'' ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Folio Number (optional)</label>
              <input class="form-input" type="text" name="folio_number" value="<?= ($init_asset==='mutual_fund'&&$edit_holding)?htmlspecialchars($edit_holding['folio_number']??'',ENT_QUOTES,'UTF-8'):'' ?>" placeholder="e.g. 1234567890">
            </div>
          </div>
          <div class="check-row" style="margin-bottom:0.75rem">
            <input type="checkbox" id="sip_active" name="sip_active" <?= ($edit_holding&&$edit_holding['sip_active']??0)?'checked':'' ?> onchange="toggleSip()">
            <label for="sip_active" style="font-size:0.875rem">Active SIP running for this fund</label>
          </div>
          <div id="sip-fields" style="display:<?= ($edit_holding&&($edit_holding['sip_active']??0))?'flex':'none' ?>;gap:1rem;flex-wrap:wrap">
            <div class="form-group" style="flex:1;min-width:160px">
              <label class="form-label">Monthly SIP Amount (₹)</label>
              <input class="form-input" type="number" name="sip_amount" value="<?= $edit_holding['sip_amount']??'' ?>" placeholder="5000">
            </div>
            <div class="form-group" style="flex:1;min-width:120px">
              <label class="form-label">SIP Date (day 1–28)</label>
              <input class="form-input" type="number" name="sip_date" min="1" max="28" value="<?= $edit_holding['sip_date']??'' ?>" placeholder="5">
            </div>
          </div>
        </div>

        <!-- ── Stock fields ── -->
        <div class="asset-fields" id="fields-stock" style="display:none">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Company Name *</label>
              <input class="form-input" type="text" name="fund_name" id="fn_stock" value="<?= ($init_asset==='stock'&&$edit_holding)?htmlspecialchars($edit_holding['fund_name']??'',ENT_QUOTES,'UTF-8'):'' ?>" placeholder="e.g. Reliance Industries Limited">
            </div>
            <div class="form-group">
              <label class="form-label">Ticker Symbol</label>
              <input class="form-input" type="text" name="fund_house" id="fh_stock" value="<?= ($init_asset==='stock'&&$edit_holding)?htmlspecialchars($edit_holding['fund_house']??'',ENT_QUOTES,'UTF-8'):'' ?>" placeholder="e.g. RELIANCE" style="text-transform:uppercase">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Number of Shares *</label>
              <input class="form-input" type="number" name="units_held" id="u_stock" step="1" value="<?= ($init_asset==='stock'&&$edit_holding)?($edit_holding['units_held']??''):'' ?>" placeholder="100" oninput="calcStockValue()">
            </div>
            <div class="form-group">
              <label class="form-label">Avg Buy Price per Share (₹) *</label>
              <input class="form-input" type="number" name="avg_nav" id="an_stock" step="0.01" value="<?= ($init_asset==='stock'&&$edit_holding)?($edit_holding['avg_nav']??''):'' ?>" placeholder="2450.00" oninput="calcStockValue()">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Current Market Price (₹)</label>
              <input class="form-input" type="number" name="current_nav" id="cn_stock" step="0.01" value="<?= ($init_asset==='stock'&&$edit_holding)?($edit_holding['current_nav']??''):'' ?>" placeholder="2820.00" oninput="calcStockValue()">
            </div>
            <div class="form-group">
              <label class="form-label">Total Invested (₹)</label>
              <input class="form-input" type="number" name="invested_amount" id="ia_stock" step="0.01" value="<?= ($init_asset==='stock'&&$edit_holding)?($edit_holding['invested_amount']??''):'' ?>" placeholder="Auto-calculated">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">First Purchase Date</label>
            <input class="form-input" type="date" name="purchase_date" id="pd_stock" value="<?= ($init_asset==='stock'&&$edit_holding)?htmlspecialchars($edit_holding['purchase_date']??'',ENT_QUOTES,'UTF-8'):'' ?>" style="max-width:220px">
          </div>
          <input type="hidden" name="folio_number" value="">
          <input type="hidden" name="units_held" value="" id="u_stock_hidden">
        </div>

        <!-- ── FD fields ── -->
        <div class="asset-fields" id="fields-fd" style="display:none">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Bank / Institution *</label>
              <input class="form-input" type="text" name="fund_name" id="fn_fd" value="<?= ($init_ft==='fd'&&$edit_holding)?htmlspecialchars($edit_holding['fund_name']??'',ENT_QUOTES,'UTF-8'):'' ?>" placeholder="e.g. SBI Fixed Deposit">
            </div>
            <div class="form-group">
              <label class="form-label">Bank Name</label>
              <input class="form-input" type="text" name="fund_house" id="fh_fd" value="<?= ($init_ft==='fd'&&$edit_holding)?htmlspecialchars($edit_holding['fund_house']??'',ENT_QUOTES,'UTF-8'):'' ?>" placeholder="State Bank of India">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Principal Amount (₹) *</label>
              <input class="form-input" type="number" name="invested_amount" id="ia_fd" step="0.01" value="<?= ($init_ft==='fd'&&$edit_holding)?($edit_holding['invested_amount']??''):'' ?>" placeholder="1,00,000">
            </div>
            <div class="form-group">
              <label class="form-label">Interest Rate (% p.a.) *</label>
              <input class="form-input" type="number" name="interest_rate" step="0.01" value="<?= ($init_ft==='fd'&&$edit_holding)?($edit_holding['interest_rate']??''):'' ?>" placeholder="7.50">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">FD Start Date *</label>
              <input class="form-input" type="date" name="purchase_date" id="pd_fd" value="<?= ($init_ft==='fd'&&$edit_holding)?htmlspecialchars($edit_holding['purchase_date']??'',ENT_QUOTES,'UTF-8'):'' ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Maturity Date *</label>
              <input class="form-input" type="date" name="maturity_date" value="<?= ($init_ft==='fd'&&$edit_holding)?htmlspecialchars($edit_holding['maturity_date']??'',ENT_QUOTES,'UTF-8'):'' ?>">
            </div>
          </div>
          <div class="form-hint" style="font-size:0.8rem;color:var(--text-secondary);padding:0.6rem 0.875rem;background:var(--surface-2);border-radius:7px">
            💡 Maturity value is auto-calculated using quarterly compounding. You can also enter it manually in <strong style="color:var(--cream)">Current Value</strong> if known.
          </div>
          <div class="form-group" style="margin-top:0.75rem">
            <label class="form-label">Maturity Value (₹) <span style="color:var(--text-muted)">(optional — leave blank to auto-calculate)</span></label>
            <input class="form-input" type="number" name="current_nav" step="0.01" value="<?= ($init_ft==='fd'&&$edit_holding)?($edit_holding['current_nav']??''):'' ?>" placeholder="Auto-calculated" style="max-width:260px">
          </div>
          <input type="hidden" name="units_held" value="1">
          <input type="hidden" name="avg_nav" value="0">
          <input type="hidden" name="folio_number" value="">
        </div>

        <!-- ── NPS fields ── -->
        <div class="asset-fields" id="fields-nps" style="display:none">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Pension Fund Manager *</label>
              <select class="form-select" name="fund_name" id="fn_nps">
                <?php
                $pfms = ['SBI Pension Funds','HDFC Pension Fund','ICICI Prudential Pension','Kotak Pension Fund','Aditya Birla Sun Life Pension','LIC Pension Fund','UTI Retirement Solutions','Tata Pension Fund'];
                $cur_nps = ($init_ft==='nps'&&$edit_holding)?($edit_holding['fund_name']??''):'';
                foreach ($pfms as $pfm): ?>
                <option value="<?= htmlspecialchars($pfm,ENT_QUOTES,'UTF-8') ?>" <?= $cur_nps===$pfm?'selected':'' ?>><?= htmlspecialchars($pfm,ENT_QUOTES,'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Tier</label>
              <select class="form-select" name="fund_house">
                <option value="Tier I" <?= ($edit_holding&&str_contains($edit_holding['fund_house']??'','I'))?'selected':'' ?>>Tier I (Retirement – Tax benefits)</option>
                <option value="Tier II" <?= ($edit_holding&&str_contains($edit_holding['fund_house']??'','II'))?'selected':'' ?>>Tier II (Voluntary savings)</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Current Corpus (₹) *</label>
              <input class="form-input" type="number" name="current_nav" step="0.01" value="<?= ($init_ft==='nps'&&$edit_holding)?($edit_holding['current_nav']??''):'' ?>" placeholder="2,50,000">
            </div>
            <div class="form-group">
              <label class="form-label">Total Contributed (₹)</label>
              <input class="form-input" type="number" name="invested_amount" step="0.01" value="<?= ($init_ft==='nps'&&$edit_holding)?($edit_holding['invested_amount']??''):'' ?>" placeholder="2,00,000">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Contribution Start Date</label>
            <input class="form-input" type="date" name="purchase_date" value="<?= ($init_ft==='nps'&&$edit_holding)?htmlspecialchars($edit_holding['purchase_date']??'',ENT_QUOTES,'UTF-8'):'' ?>" style="max-width:220px">
          </div>
          <input type="hidden" name="units_held" value="1">
          <input type="hidden" name="avg_nav" value="0">
          <input type="hidden" name="folio_number" value="">
          <input type="hidden" name="maturity_date" value="">
        </div>

        <!-- ── Gold fields ── -->
        <div class="asset-fields" id="fields-gold" style="display:none">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Gold Type</label>
              <select class="form-select" name="fund_name" id="fn_gold">
                <?php
                $gold_types = ['Sovereign Gold Bond (SGB)','Physical Gold','Gold ETF','Gold Mutual Fund','Silver ETF','Digital Gold'];
                $cur_gold = ($init_ft==='gold'&&$edit_holding)?($edit_holding['fund_name']??''):'';
                foreach ($gold_types as $gt): ?>
                <option value="<?= htmlspecialchars($gt,ENT_QUOTES,'UTF-8') ?>" <?= $cur_gold===$gt?'selected':'' ?>><?= $gt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Issuer / Custodian</label>
              <input class="form-input" type="text" name="fund_house" value="<?= ($init_ft==='gold'&&$edit_holding)?htmlspecialchars($edit_holding['fund_house']??'',ENT_QUOTES,'UTF-8'):'' ?>" placeholder="RBI / Zerodha / Groww">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Quantity (grams or units)</label>
              <input class="form-input" type="number" name="units_held" step="0.001" value="<?= ($init_ft==='gold'&&$edit_holding)?($edit_holding['units_held']??''):'' ?>" placeholder="50" oninput="calcGoldValue()">
            </div>
            <div class="form-group">
              <label class="form-label">Purchase Price per gram/unit (₹)</label>
              <input class="form-input" type="number" name="avg_nav" step="0.01" value="<?= ($init_ft==='gold'&&$edit_holding)?($edit_holding['avg_nav']??''):'' ?>" placeholder="6200" oninput="calcGoldValue()">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Current Price per gram/unit (₹)</label>
              <input class="form-input" type="number" name="current_nav" step="0.01" value="<?= ($init_ft==='gold'&&$edit_holding)?($edit_holding['current_nav']??''):'' ?>" placeholder="7400" oninput="calcGoldValue()">
            </div>
            <div class="form-group">
              <label class="form-label">Total Invested (₹)</label>
              <input class="form-input" type="number" name="invested_amount" id="ia_gold" step="0.01" value="<?= ($init_ft==='gold'&&$edit_holding)?($edit_holding['invested_amount']??''):'' ?>" placeholder="Auto-calculated">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Purchase Date</label>
              <input class="form-input" type="date" name="purchase_date" value="<?= ($init_ft==='gold'&&$edit_holding)?htmlspecialchars($edit_holding['purchase_date']??'',ENT_QUOTES,'UTF-8'):'' ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Maturity Date <span style="color:var(--text-muted)">(SGB only)</span></label>
              <input class="form-input" type="date" name="maturity_date" value="<?= ($init_ft==='gold'&&$edit_holding)?htmlspecialchars($edit_holding['maturity_date']??'',ENT_QUOTES,'UTF-8'):'' ?>">
            </div>
          </div>
          <input type="hidden" name="folio_number" value="">
        </div>

        <!-- ── Other fields ── -->
        <div class="asset-fields" id="fields-other" style="display:none">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Investment Name *</label>
              <input class="form-input" type="text" name="fund_name" id="fn_other" value="<?= ($init_ft==='other'&&$edit_holding)?htmlspecialchars($edit_holding['fund_name']??'',ENT_QUOTES,'UTF-8'):'' ?>" placeholder="e.g. PPF, EPFO, ULIP, Real Estate">
            </div>
            <div class="form-group">
              <label class="form-label">Institution / Details</label>
              <input class="form-input" type="text" name="fund_house" value="<?= ($init_ft==='other'&&$edit_holding)?htmlspecialchars($edit_holding['fund_house']??'',ENT_QUOTES,'UTF-8'):'' ?>" placeholder="e.g. SBI, LIC">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Amount Invested (₹) *</label>
              <input class="form-input" type="number" name="invested_amount" step="0.01" value="<?= ($init_ft==='other'&&$edit_holding)?($edit_holding['invested_amount']??''):'' ?>" placeholder="5,00,000">
            </div>
            <div class="form-group">
              <label class="form-label">Current Value (₹)</label>
              <input class="form-input" type="number" name="current_nav" step="0.01" value="<?= ($init_ft==='other'&&$edit_holding)?($edit_holding['current_nav']??''):'' ?>" placeholder="6,20,000">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Start Date</label>
            <input class="form-input" type="date" name="purchase_date" value="<?= ($init_ft==='other'&&$edit_holding)?htmlspecialchars($edit_holding['purchase_date']??'',ENT_QUOTES,'UTF-8'):'' ?>" style="max-width:220px">
          </div>
          <input type="hidden" name="units_held" value="1">
          <input type="hidden" name="avg_nav" value="0">
          <input type="hidden" name="folio_number" value="">
          <input type="hidden" name="maturity_date" value="">
        </div>

        <!-- ── Common: Notes + Submit ── -->
        <div id="common-submit" style="margin-top:1.25rem">
          <div class="form-group">
            <label class="form-label">Notes <span style="color:var(--text-muted)">(optional)</span></label>
            <textarea class="form-textarea" name="notes" rows="2" placeholder="Any additional notes..."><?= htmlspecialchars($edit_holding['notes']??'',ENT_QUOTES,'UTF-8') ?></textarea>
          </div>
          <div style="display:flex;gap:0.75rem;flex-wrap:wrap;margin-top:0.75rem">
            <button type="submit" class="btn-primary"><?= $edit_holding ? 'Update Holding' : 'Add to Portfolio →' ?></button>
            <?php if ($edit_holding): ?>
              <a href="<?= SITE_URL ?>/portal/portfolio.php" class="btn-ghost">Cancel</a>
            <?php endif; ?>
          </div>
        </div>

      </div><!-- /#step2-fields -->
    </form>
  </div>
</div>

<style>
.asset-type-btn:hover { border-color:var(--mid)!important; background:var(--mid-pale)!important; }
.asset-type-btn.active { border-color:var(--bright)!important; background:rgba(76,175,80,0.12)!important; }
@media(max-width:600px){ #asset-type-grid{ grid-template-columns:repeat(2,1fr)!important; } }
</style>

<script>
var currentAssetType = '<?= $init_asset ?>';

function selectAssetType(type) {
  currentAssetType = type;
  // Update button styles
  document.querySelectorAll('.asset-type-btn').forEach(b => {
    var active = b.dataset.type === type;
    b.style.borderColor = active ? 'var(--bright)' : '';
    b.style.background  = active ? 'rgba(76,175,80,0.12)' : '';
    b.classList.toggle('active', active);
  });
  // Show step 2
  document.getElementById('step2-fields').style.display = 'block';
  // Hide all field groups
  document.querySelectorAll('.asset-fields').forEach(f => f.style.display = 'none');
  // Show relevant group
  var el = document.getElementById('fields-' + type);
  if (el) el.style.display = 'block';
  // Set hidden fund_type
  var typeMap = {
    mutual_fund: document.getElementById('mf_subtype')?.value || 'equity',
    stock: 'equity', fd: 'fd', nps: 'nps', gold: 'gold', other: 'other'
  };
  document.getElementById('hidden_fund_type').value = typeMap[type] || 'equity';
  // Scroll to step 2
  setTimeout(() => document.getElementById('step2-fields').scrollIntoView({behavior:'smooth',block:'nearest'}), 100);
}

function setMFType(val) {
  if (currentAssetType === 'mutual_fund') {
    document.getElementById('hidden_fund_type').value = val;
  }
}

function toggleSip() {
  var sf = document.getElementById('sip-fields');
  if (sf) sf.style.display = document.getElementById('sip_active').checked ? 'flex' : 'none';
}

function calcMFValue() {
  var u = parseFloat(document.getElementById('u_mf')?.value)||0;
  var a = parseFloat(document.getElementById('an_mf')?.value)||0;
  var ia = document.getElementById('ia_mf');
  if (u && a && ia) ia.value = (u * a).toFixed(2);
}

function calcStockValue() {
  var u = parseFloat(document.getElementById('u_stock')?.value)||0;
  var a = parseFloat(document.getElementById('an_stock')?.value)||0;
  var ia = document.getElementById('ia_stock');
  if (u && a && ia) ia.value = (u * a).toFixed(2);
}

function calcGoldValue() {
  var fields = document.querySelectorAll('#fields-gold [name="units_held"]')[0];
  var avgN   = document.querySelectorAll('#fields-gold [name="avg_nav"]')[0];
  var ia     = document.getElementById('ia_gold');
  if (fields && avgN && ia) {
    var u = parseFloat(fields.value)||0, a = parseFloat(avgN.value)||0;
    if (u && a) ia.value = (u * a).toFixed(2);
  }
}

// On form submit, consolidate duplicate name fields
document.getElementById('add-form').addEventListener('submit', function(e) {
  // Disable all fund_name inputs except the active one
  document.querySelectorAll('.asset-fields').forEach(function(sec) {
    if (sec.style.display === 'none') {
      sec.querySelectorAll('input,select,textarea').forEach(function(inp) {
        inp.disabled = true;
      });
    }
  });
});

<?php if ($edit_holding): ?>
// Init edit mode
selectAssetType('<?= $init_asset ?>');
<?php if ($init_asset === 'mutual_fund'): ?>
document.getElementById('hidden_fund_type').value = '<?= htmlspecialchars($edit_holding['fund_type']??'equity',ENT_QUOTES,'UTF-8') ?>';
document.getElementById('mf_subtype').value = '<?= htmlspecialchars($edit_holding['fund_type']??'equity',ENT_QUOTES,'UTF-8') ?>';
<?php endif; ?>
if (<?= ($edit_holding['sip_active']??0) ? 'true' : 'false' ?>) toggleSip();
<?php endif; ?>
</script>

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
          <td><span class="badge <?= $type_colours[$h['fund_type']]??'badge-muted' ?>" style="white-space:nowrap"><?= htmlspecialchars(fund_type_display($h['fund_type'], $h['fund_name']), ENT_QUOTES, 'UTF-8') ?></span></td>
          <td style="font-family:'DM Mono',monospace;font-size:0.82rem"><?= number_format((float)$h['units_held'],4) ?></td>
          <td style="font-family:'DM Mono',monospace;font-size:0.82rem">₹<?= number_format((float)$h['avg_nav'],2) ?></td>
          <td style="font-family:'DM Mono',monospace;font-size:0.82rem">₹<?= number_format((float)$h['current_nav'],2) ?></td>
          <td><?= format_inr((float)$h["invested_amount"]) ?></td>
          <td><?= format_inr((float)$h["current_value"]) ?></td>
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
// Old calc functions removed — handled by new asset-type-aware form above

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
