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

// Save plan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_plan') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    else {
        try {
            $db->prepare("INSERT INTO sip_calculations (user_id, goal_name, monthly_sip, target_amount, duration_years, expected_return, calculated_corpus, scenario) VALUES (:uid,:gname,:sip,:target,:yrs,:ret,:corpus,:scenario)")
               ->execute([
                   ':uid'     => $uid,
                   ':gname'   => trim($_POST['goal_name'] ?? 'SIP Plan'),
                   ':sip'     => (float)($_POST['monthly_sip'] ?? 0),
                   ':target'  => (float)($_POST['target_amount'] ?? 0),
                   ':yrs'     => (int)($_POST['duration_years'] ?? 0),
                   ':ret'     => (float)($_POST['expected_return'] ?? 0),
                   ':corpus'  => (float)($_POST['calculated_corpus'] ?? 0),
                   ':scenario'=> $_POST['scenario'] ?? 'moderate',
               ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Plan saved successfully.'];
            header('Location: ' . SITE_URL . '/portal/sip-calculator.php'); exit;
        } catch (PDOException $e) { error_log($e->getMessage()); $error = 'Could not save plan.'; }
    }
}

// Fetch saved plans
$stmt = $db->prepare("SELECT * FROM sip_calculations WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5");
$stmt->execute([':uid' => $uid]);
$saved_plans = $stmt->fetchAll();

$page_title = 'SIP Calculator — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Tools</p>
<h1 class="page-title">SIP Calculator</h1>
<p class="page-subtitle">Plan your investments with India's most comprehensive SIP calculator</p>

<?php if ($error): ?>
  <div class="flash-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- Tab nav -->
<div style="display:flex;gap:0;margin-bottom:1.5rem;border-bottom:1px solid var(--border)">
  <?php foreach (['sip'=>'SIP to Corpus','goal'=>'Goal to SIP','lumpsum'=>'Lumpsum'] as $t=>$l): ?>
  <button onclick="switchTab('<?= $t ?>')" id="tab-<?= $t ?>"
    style="padding:0.65rem 1.25rem;border:none;background:none;color:var(--text-secondary);font-family:'DM Sans',sans-serif;font-size:0.875rem;font-weight:500;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:all 0.15s">
    <?= $l ?>
  </button>
  <?php endforeach; ?>
</div>

<div class="grid-2" style="align-items:start">

<!-- ── TAB: SIP to Corpus ── -->
<div id="panel-sip" class="portal-card">
  <div class="card-title">Monthly SIP → Final Corpus</div>
  <div class="form-group">
    <label class="form-label">Monthly SIP Amount (₹)</label>
    <input class="form-input" type="number" id="s_sip" value="5000" min="500" oninput="calcSip()">
  </div>
  <div class="form-group">
    <label class="form-label">Expected Return (% p.a.)</label>
    <input class="form-input" type="number" id="s_rate" value="12" step="0.5" min="1" max="30" oninput="calcSip()">
  </div>
  <div class="form-group">
    <label class="form-label">Duration (Years)</label>
    <input class="form-input" type="number" id="s_years" value="15" min="1" max="40" oninput="calcSip()">
  </div>
  <div style="background:var(--surface-2);border-radius:10px;padding:1.25rem;margin-top:0.5rem">
    <div style="display:flex;justify-content:space-between;margin-bottom:0.5rem">
      <span style="color:var(--text-secondary);font-size:0.85rem">Total Invested</span>
      <span style="font-family:'DM Mono',monospace;color:var(--cream)" id="s_invested">₹9,00,000</span>
    </div>
    <div style="display:flex;justify-content:space-between;margin-bottom:0.5rem">
      <span style="color:var(--text-secondary);font-size:0.85rem">Est. Returns</span>
      <span style="font-family:'DM Mono',monospace;color:var(--bright)" id="s_returns">₹16,22,880</span>
    </div>
    <div style="display:flex;justify-content:space-between;border-top:1px solid var(--border);padding-top:0.5rem;margin-top:0.5rem">
      <span style="color:var(--cream);font-weight:600">Final Corpus</span>
      <span style="font-family:'Cormorant Garamond',serif;font-size:1.5rem;color:var(--lime);font-weight:700" id="s_corpus">₹25,22,880</span>
    </div>
  </div>
  <canvas id="sipChart" height="180" style="margin-top:1rem"></canvas>
  <form method="POST" style="margin-top:1rem">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="save_plan">
    <input type="hidden" name="scenario" value="moderate">
    <input type="hidden" name="goal_name" value="">
    <div class="form-group">
      <label class="form-label">Plan Name (optional)</label>
      <input class="form-input" type="text" name="goal_name" placeholder="e.g. Retirement Fund">
    </div>
    <input type="hidden" name="monthly_sip" id="save_sip" value="">
    <input type="hidden" name="target_amount" value="0">
    <input type="hidden" name="duration_years" id="save_years" value="">
    <input type="hidden" name="expected_return" id="save_rate" value="">
    <input type="hidden" name="calculated_corpus" id="save_corpus" value="">
    <button type="submit" class="btn-outline btn-sm" onclick="fillSaveForm()">💾 Save This Plan</button>
  </form>
</div>

<!-- ── TAB: Goal to SIP ── -->
<div id="panel-goal" class="portal-card" style="display:none">
  <div class="card-title">Target Corpus → Monthly SIP</div>
  <div class="form-group">
    <label class="form-label">Target Amount (₹)</label>
    <input class="form-input" type="number" id="g_target" value="1000000" oninput="calcGoal()">
  </div>
  <div class="form-group">
    <label class="form-label">Expected Return (% p.a.)</label>
    <input class="form-input" type="number" id="g_rate" value="12" step="0.5" min="1" max="30" oninput="calcGoal()">
  </div>
  <div class="form-group">
    <label class="form-label">Time Period (Years)</label>
    <input class="form-input" type="number" id="g_years" value="10" min="1" max="40" oninput="calcGoal()">
  </div>
  <div style="background:var(--surface-2);border-radius:10px;padding:1.5rem;margin-top:0.5rem;text-align:center">
    <div style="font-size:0.75rem;font-family:'DM Mono',monospace;color:var(--lime);letter-spacing:0.15em;margin-bottom:0.5rem">MONTHLY SIP REQUIRED</div>
    <div style="font-family:'Cormorant Garamond',serif;font-size:2.5rem;font-weight:700;color:var(--lime)" id="g_sip_result">₹4,347</div>
    <div style="font-size:0.78rem;color:var(--text-secondary);margin-top:0.3rem" id="g_invested_note">Total invested: ₹5,21,640</div>
  </div>
</div>

<!-- ── TAB: Lumpsum ── -->
<div id="panel-lumpsum" class="portal-card" style="display:none">
  <div class="card-title">Lumpsum Investment Growth</div>
  <div class="form-group">
    <label class="form-label">Lumpsum Amount (₹)</label>
    <input class="form-input" type="number" id="l_amount" value="100000" oninput="calcLump()">
  </div>
  <div class="form-group">
    <label class="form-label">Expected Return (% p.a.)</label>
    <input class="form-input" type="number" id="l_rate" value="12" step="0.5" min="1" max="30" oninput="calcLump()">
  </div>
  <div class="form-group">
    <label class="form-label">Duration (Years)</label>
    <input class="form-input" type="number" id="l_years" value="10" min="1" max="40" oninput="calcLump()">
  </div>
  <div style="background:var(--surface-2);border-radius:10px;padding:1.25rem;margin-top:0.5rem">
    <div style="display:flex;justify-content:space-between;margin-bottom:0.5rem">
      <span style="color:var(--text-secondary);font-size:0.85rem">Amount Invested</span>
      <span style="font-family:'DM Mono',monospace;color:var(--cream)" id="l_invested">₹1,00,000</span>
    </div>
    <div style="display:flex;justify-content:space-between;margin-bottom:0.5rem">
      <span style="color:var(--text-secondary);font-size:0.85rem">Wealth Gained</span>
      <span style="font-family:'DM Mono',monospace;color:var(--bright)" id="l_gain">₹2,10,585</span>
    </div>
    <div style="display:flex;justify-content:space-between;border-top:1px solid var(--border);padding-top:0.5rem;margin-top:0.5rem">
      <span style="color:var(--cream);font-weight:600">Maturity Value</span>
      <span style="font-family:'Cormorant Garamond',serif;font-size:1.5rem;color:var(--lime);font-weight:700" id="l_maturity">₹3,10,585</span>
    </div>
  </div>
</div>

<!-- Results panel (right side) -->
<div>
  <?php if (!empty($saved_plans)): ?>
  <div class="portal-card">
    <div class="card-title">Saved Plans</div>
    <div class="table-wrapper">
      <table class="portal-table">
        <thead><tr><th>Plan</th><th>Monthly SIP</th><th>Years</th><th>Corpus</th></tr></thead>
        <tbody>
          <?php foreach ($saved_plans as $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['goal_name'] ?: 'SIP Plan', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= format_inr((float)$p['monthly_sip']) ?></td>
            <td><?= $p['duration_years'] ?>yr</td>
            <td style="color:var(--lime);font-family:'DM Mono',monospace"><?= format_inr((float)$p['calculated_corpus']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="portal-card" style="margin-top:1rem;text-align:center">
    <div style="font-size:1.5rem;margin-bottom:0.75rem">💬</div>
    <p style="color:var(--text-secondary);font-size:0.875rem;margin-bottom:1rem">Want a personalised SIP plan for your goals?</p>
    <a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=Hi%2C+I+used+the+SIP+Calculator+on+primefin.in+and+want+advice+on+building+the+right+plan."
       class="btn-primary btn-sm" target="_blank" rel="noopener">Chat with Advisor →</a>
  </div>
</div>
</div>

<script>
var sipChart;
function inr(n){ return '₹' + Math.round(n).toLocaleString('en-IN'); }

function calcSip(){
  var sip   = parseFloat(document.getElementById('s_sip').value)||0;
  var rate  = parseFloat(document.getElementById('s_rate').value)||12;
  var years = parseInt(document.getElementById('s_years').value)||15;
  var r = (rate/100)/12, n = years*12;
  var corpus = r > 0 ? sip * ((Math.pow(1+r,n)-1)/r) * (1+r) : sip*n;
  var invested = sip * n;
  var returns  = corpus - invested;
  document.getElementById('s_invested').textContent = inr(invested);
  document.getElementById('s_returns').textContent  = inr(returns);
  document.getElementById('s_corpus').textContent   = inr(corpus);
  updateSipChart(invested, returns);
}

function updateSipChart(inv, ret){
  var ctx = document.getElementById('sipChart').getContext('2d');
  if(sipChart) sipChart.destroy();
  sipChart = new Chart(ctx, {
    type:'doughnut',
    data:{
      labels:['Invested','Returns'],
      datasets:[{data:[inv,ret],backgroundColor:['#1B5E2A','#8DC63F'],borderColor:'#0c140c',borderWidth:3}]
    },
    options:{cutout:'65%',plugins:{legend:{position:'bottom',labels:{color:'#85a885',font:{family:"'DM Mono'"},padding:12,boxWidth:12}}}}
  });
}

function calcGoal(){
  var target = parseFloat(document.getElementById('g_target').value)||0;
  var rate   = parseFloat(document.getElementById('g_rate').value)||12;
  var years  = parseInt(document.getElementById('g_years').value)||10;
  var r = (rate/100)/12, n = years*12;
  var sip = r > 0 ? target * r / ((Math.pow(1+r,n)-1)*(1+r)) : target/n;
  document.getElementById('g_sip_result').textContent = inr(sip);
  document.getElementById('g_invested_note').textContent = 'Total invested: ' + inr(sip*n);
}

function calcLump(){
  var amt   = parseFloat(document.getElementById('l_amount').value)||0;
  var rate  = parseFloat(document.getElementById('l_rate').value)||12;
  var years = parseInt(document.getElementById('l_years').value)||10;
  var mat   = amt * Math.pow(1+rate/100, years);
  document.getElementById('l_invested').textContent = inr(amt);
  document.getElementById('l_gain').textContent     = inr(mat-amt);
  document.getElementById('l_maturity').textContent = inr(mat);
}

function switchTab(t){
  ['sip','goal','lumpsum'].forEach(function(x){
    document.getElementById('panel-'+x).style.display = x===t?'block':'none';
    var btn = document.getElementById('tab-'+x);
    btn.style.borderBottomColor = x===t?'var(--bright)':'transparent';
    btn.style.color = x===t?'var(--cream)':'var(--text-secondary)';
  });
}

function fillSaveForm(){
  document.querySelector('[name="monthly_sip"]').value = document.getElementById('s_sip').value;
  document.querySelector('[name="duration_years"]').value = document.getElementById('s_years').value;
  document.querySelector('[name="expected_return"]').value = document.getElementById('s_rate').value;
  var sip=parseFloat(document.getElementById('s_sip').value)||0,rate=parseFloat(document.getElementById('s_rate').value)||12,years=parseInt(document.getElementById('s_years').value)||15;
  var r=(rate/100)/12,n=years*12;
  var corpus=r>0?sip*((Math.pow(1+r,n)-1)/r)*(1+r):sip*n;
  document.querySelector('[name="calculated_corpus"]').value = Math.round(corpus);
}

document.addEventListener('DOMContentLoaded',function(){
  switchTab('sip'); calcSip(); calcGoal(); calcLump();
});
</script>

<div class="cta-bar"><div class="cta-bar__content"><div class="cta-bar__text"><span class="cta-bar__eyebrow">READY TO START?</span><span class="cta-bar__title">Start this SIP with Prime Financials in minutes</span></div><div class="cta-bar__actions"><a href="<?= ONBOARDING_URL ?>?utm_source=sip_calc&utm_medium=portal" target="_blank" rel="noopener" class="cta-btn cta-btn--primary">🚀 Start This SIP →</a><a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=<?= urlencode('Hi, I calculated a SIP on primefin.in and would like to start.') ?>" target="_blank" rel="noopener" class="cta-btn cta-btn--whatsapp">💬 WhatsApp</a></div></div></div>
<?php require_once '../includes/portal-footer.php'; ?>
