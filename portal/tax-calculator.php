<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$page_title = 'Tax Calculator — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Tools</p>
<h1 class="page-title">Income Tax Calculator</h1>
<p class="page-subtitle">FY 2024-25 · Compare Old vs New Tax Regime</p>

<div class="grid-2" style="align-items:start">

<!-- Inputs -->
<div class="portal-card">
  <div class="card-title">Income & Deductions</div>

  <div class="form-group">
    <label class="form-label">Gross Annual Income (₹)</label>
    <input class="form-input amount-input" type="number" id="income" value="1200000" step="10000" oninput="calcTax()">
    <div class="form-hint form-hint--words" id="income-words"></div>
  </div>

  <div style="margin:1.25rem 0 0.5rem;font-family:'IBM Plex Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.18em;text-transform:uppercase">Section 80C Deductions (max ₹1,50,000)</div>
  <?php $items80c = ['EPF Contribution'=>'epf','PPF Contribution'=>'ppf','ELSS / Tax Saver MF'=>'elss','LIC Premium'=>'lic','Home Loan Principal'=>'hlp','Tuition Fees'=>'tuition']; ?>
  <?php foreach ($items80c as $label => $id): ?>
  <div style="margin-bottom:0.6rem">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <label style="font-size:0.85rem;color:var(--text-secondary)"><?= $label ?></label>
      <input type="number" id="<?= $id ?>" class="amount-input" value="0" step="1000" oninput="calcTax()"
        style="width:140px;background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:0.4rem 0.6rem;color:var(--text-primary);font-family:'IBM Plex Mono',monospace;font-size:0.85rem;text-align:right">
    </div>
    <div class="form-hint form-hint--words" id="<?= $id ?>-words" style="text-align:right"></div>
  </div>
  <?php endforeach; ?>
  <div style="text-align:right;font-size:0.75rem;color:var(--lime);font-family:'IBM Plex Mono',monospace">Total 80C: <span id="total80c">₹0</span> / ₹1,50,000</div>

  <div style="margin:1.25rem 0 0.5rem;font-family:'IBM Plex Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.18em;text-transform:uppercase">Section 80D — Health Insurance</div>
  <div style="margin-bottom:0.5rem">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <label style="font-size:0.85rem;color:var(--text-secondary)">Self & Family premium</label>
      <input type="number" id="health_self" class="amount-input" value="0" step="1000" oninput="calcTax()" style="width:140px;background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:0.4rem 0.6rem;color:var(--text-primary);font-family:'IBM Plex Mono',monospace;font-size:0.85rem;text-align:right">
    </div>
    <div class="form-hint form-hint--words" id="health_self-words" style="text-align:right"></div>
  </div>
  <div style="margin-bottom:0.5rem">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <label style="font-size:0.85rem;color:var(--text-secondary)">Parents premium <span style="font-size:0.72rem;color:var(--text-muted)">(max ₹50K if senior)</span></label>
      <input type="number" id="health_parents" class="amount-input" value="0" step="1000" oninput="calcTax()" style="width:140px;background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:0.4rem 0.6rem;color:var(--text-primary);font-family:'IBM Plex Mono',monospace;font-size:0.85rem;text-align:right">
    </div>
    <div class="form-hint form-hint--words" id="health_parents-words" style="text-align:right"></div>
  </div>

  <div style="margin:1.25rem 0 0.5rem;font-family:'IBM Plex Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.18em;text-transform:uppercase">80CCD(1B) — NPS (max ₹50,000)</div>
  <div style="margin-bottom:0.5rem">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <label style="font-size:0.85rem;color:var(--text-secondary)">NPS Contribution</label>
      <input type="number" id="nps" class="amount-input" value="0" step="1000" oninput="calcTax()" style="width:140px;background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:0.4rem 0.6rem;color:var(--text-primary);font-family:'IBM Plex Mono',monospace;font-size:0.85rem;text-align:right">
    </div>
    <div class="form-hint form-hint--words" id="nps-words" style="text-align:right"></div>
  </div>

  <div style="margin:1.25rem 0 0.5rem;font-family:'IBM Plex Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.18em;text-transform:uppercase">Other</div>
  <div>
    <div style="display:flex;align-items:center;justify-content:space-between">
      <label style="font-size:0.85rem;color:var(--text-secondary)">HRA Exemption</label>
      <input type="number" id="hra" class="amount-input" value="0" step="10000" oninput="calcTax()" style="width:140px;background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:0.4rem 0.6rem;color:var(--text-primary);font-family:'IBM Plex Mono',monospace;font-size:0.85rem;text-align:right">
    </div>
    <div class="form-hint form-hint--words" id="hra-words" style="text-align:right"></div>
  </div>
</div>

<!-- Results -->
<div>
  <div class="portal-card" style="margin-bottom:1rem">
    <div class="card-title">Tax Comparison</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <!-- Old regime -->
      <div style="background:var(--surface-2);border-radius:10px;padding:1.25rem;border:1px solid var(--border)">
        <div style="font-family:'IBM Plex Mono',monospace;font-size:0.62rem;color:var(--text-muted);letter-spacing:0.15em;margin-bottom:0.75rem">OLD REGIME</div>
        <div style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:0.3rem">Taxable Income</div>
        <div style="font-family:'IBM Plex Mono',monospace;color:var(--cream);margin-bottom:0.75rem" id="old_taxable">₹—</div>
        <div style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:0.3rem">Tax + Cess</div>
        <div style="font-family:'Cormorant Garamond',serif;font-size:1.6rem;color:var(--gold)" id="old_tax">₹—</div>
        <div style="font-size:0.75rem;color:var(--text-secondary);margin-top:0.3rem">Monthly: <span id="old_monthly">₹—</span></div>
      </div>
      <!-- New regime -->
      <div style="background:var(--surface-2);border-radius:10px;padding:1.25rem;border:1px solid var(--border)" id="new_card">
        <div style="font-family:'IBM Plex Mono',monospace;font-size:0.62rem;color:var(--text-muted);letter-spacing:0.15em;margin-bottom:0.75rem">NEW REGIME</div>
        <div style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:0.3rem">Taxable Income</div>
        <div style="font-family:'IBM Plex Mono',monospace;color:var(--cream);margin-bottom:0.75rem" id="new_taxable">₹—</div>
        <div style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:0.3rem">Tax + Cess</div>
        <div style="font-family:'Cormorant Garamond',serif;font-size:1.6rem;color:var(--gold)" id="new_tax">₹—</div>
        <div style="font-size:0.75rem;color:var(--text-secondary);margin-top:0.3rem">Monthly: <span id="new_monthly">₹—</span></div>
      </div>
    </div>
    <div style="margin-top:1rem;padding:0.875rem;border-radius:8px;text-align:center" id="recommendation_box">
      <div style="font-size:0.82rem" id="recommendation_text">Enter your income to see recommendation</div>
    </div>
  </div>

  <div class="portal-card" style="margin-bottom:1rem">
    <div class="card-title">Deductions Summary</div>
    <canvas id="taxChart" height="200"></canvas>
  </div>

  <div class="portal-card" style="text-align:center">
    <p style="color:var(--text-secondary);font-size:0.875rem;margin-bottom:1rem">Want to maximise your tax savings?</p>
    <a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=I+used+the+Tax+Calculator+on+primefin.in+and+want+advice+on+tax+saving+investments."
       class="btn-primary btn-sm" target="_blank" rel="noopener">💬 Get Tax Saving Advice</a>
  </div>
</div>
</div>

<script>
var taxChart;
function inr(n){ return '₹'+Math.round(n).toLocaleString('en-IN'); }

function calcTaxOld(income){
  var tax=0;
  if(income<=250000) tax=0;
  else if(income<=500000) tax=(income-250000)*0.05;
  else if(income<=1000000) tax=12500+(income-500000)*0.20;
  else tax=112500+(income-1000000)*0.30;
  // Rebate u/s 87A if taxable ≤ 5L
  if(income<=500000) tax=0;
  return tax * 1.04; // +4% cess
}

function calcTaxNew(income){
  var tax=0;
  if(income<=300000) tax=0;
  else if(income<=600000) tax=(income-300000)*0.05;
  else if(income<=900000) tax=15000+(income-600000)*0.10;
  else if(income<=1200000) tax=45000+(income-900000)*0.15;
  else if(income<=1500000) tax=90000+(income-1200000)*0.20;
  else tax=150000+(income-1500000)*0.30;
  // Rebate u/s 87A if taxable ≤ 7L
  if(income<=700000) tax=0;
  return tax * 1.04;
}

function calcTax(){
  var income = parseFloat(document.getElementById('income').value)||0;
  var e80c   = Math.min(150000, ['epf','ppf','elss','lic','hlp','tuition'].reduce((s,id)=>s+(parseFloat(document.getElementById(id).value)||0),0));
  var e80d   = Math.min(75000, (parseFloat(document.getElementById('health_self').value)||0) + (parseFloat(document.getElementById('health_parents').value)||0));
  var e80ccd = Math.min(50000, parseFloat(document.getElementById('nps').value)||0);
  var hra    = parseFloat(document.getElementById('hra').value)||0;
  document.getElementById('total80c').textContent = inr(Math.min(150000,['epf','ppf','elss','lic','hlp','tuition'].reduce((s,id)=>s+(parseFloat(document.getElementById(id).value)||0),0)));

  var stdOld = 50000, stdNew = 75000;
  var oldDed  = stdOld + e80c + e80d + e80ccd + hra;
  var oldTaxable = Math.max(0, income - oldDed);
  var oldTax = calcTaxOld(oldTaxable);

  var newTaxable = Math.max(0, income - stdNew);
  var newTax = calcTaxNew(newTaxable);

  document.getElementById('old_taxable').textContent = inr(oldTaxable);
  document.getElementById('old_tax').textContent     = inr(oldTax);
  document.getElementById('old_monthly').textContent = inr(oldTax/12);
  document.getElementById('new_taxable').textContent = inr(newTaxable);
  document.getElementById('new_tax').textContent     = inr(newTax);
  document.getElementById('new_monthly').textContent = inr(newTax/12);

  var savings = Math.abs(oldTax - newTax);
  var better  = oldTax <= newTax ? 'Old' : 'New';
  var box = document.getElementById('recommendation_box');
  var txt = document.getElementById('recommendation_text');
  if(savings > 0){
    box.style.background = 'rgba(76,175,80,0.1)';
    box.style.border = '1px solid rgba(76,175,80,0.3)';
    txt.innerHTML = '<strong style="color:var(--bright)">' + better + ' Regime is better</strong> — saves you ' + inr(savings) + '/year';
  }

  updateChart(income, oldDed, oldTaxable);
}

function updateChart(income, ded, taxable){
  var ctx = document.getElementById('taxChart').getContext('2d');
  if(taxChart) taxChart.destroy();
  taxChart = new Chart(ctx, {
    type:'bar',
    data:{
      labels:['Gross Income','Total Deductions','Taxable Income'],
      datasets:[{data:[income,ded,taxable],backgroundColor:['#2E8540','#8DC63F','#C9A84C'],borderRadius:6}]
    },
    options:{plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>'₹'+v.toLocaleString('en-IN'),color:'#85a885'},grid:{color:'rgba(46,133,64,0.1)'}},x:{ticks:{color:'#85a885'},grid:{display:false}}}}
  });
}

document.addEventListener('DOMContentLoaded', calcTax);
</script>

<?php require_once '../includes/portal-footer.php'; ?>

<?php
// CTA bar injected by Phase 13
$cta_sources = ['tax-calculator'=>'tax_calc','nps-projector'=>'nps_projector','insurance-checker'=>'insurance_checker'];
$cta_titles  = ['tax-calculator'=>'Invest in ELSS and claim your 80C benefit this year','nps-projector'=>'Open your NPS account and start building your pension corpus','insurance-checker'=>'Get the right term and health cover — instantly online'];
$cta_links   = ['tax-calculator'=>ONBOARDING_URL,'nps-projector'=>ONBOARDING_URL,'insurance-checker'=>INSURANCE_URL];
$pg = basename($_SERVER['PHP_SELF'],'.php');
if (isset($cta_sources[$pg])): ?>
<div class="cta-bar" style="margin-top:1.5rem">
  <div class="cta-bar__content">
    <div class="cta-bar__text">
      <span class="cta-bar__eyebrow">ACT NOW</span>
      <span class="cta-bar__title"><?= htmlspecialchars($cta_titles[$pg]??'') ?></span>
    </div>
    <div class="cta-bar__actions">
      <a href="<?= ($cta_links[$pg]??ONBOARDING_URL) ?>?utm_source=<?= $cta_sources[$pg] ?>&utm_medium=portal" target="_blank" rel="noopener" class="cta-btn cta-btn--primary">🚀 Get Started →</a>
      <a href="https://wa.me/<?= WHATSAPP_NUM ?>" target="_blank" rel="noopener" class="cta-btn cta-btn--whatsapp">💬 WhatsApp</a>
    </div>
  </div>
</div>
<?php endif; ?>
