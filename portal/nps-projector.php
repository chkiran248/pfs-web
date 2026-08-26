<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$page_title = 'NPS Projector — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Tools</p>
<h1 class="page-title">NPS Projector</h1>
<p class="page-subtitle">Project your retirement corpus and pension from the National Pension System</p>

<div class="grid-2" style="align-items:start">
<div class="portal-card">
  <div class="card-title">Your NPS Details</div>
  <div class="form-row">
    <div class="form-group">
      <label class="form-label">Current Age</label>
      <input class="form-input" type="number" id="cur_age" value="30" min="18" max="59" oninput="calcNps()">
    </div>
    <div class="form-group">
      <label class="form-label">Retirement Age</label>
      <input class="form-input" type="number" id="ret_age" value="60" min="50" max="70" oninput="calcNps()">
    </div>
  </div>
  <div class="form-group">
    <label class="form-label">Monthly NPS Contribution (₹)</label>
    <input class="form-input amount-input" type="number" id="monthly" value="5000" step="500" oninput="calcNps()">
    <div class="form-hint form-hint--words" id="monthly-words"></div>
  </div>
  <div class="form-group">
    <label class="form-label">Expected Annual Return (% p.a.)</label>
    <input class="form-input" type="number" id="ret" value="10" step="0.5" min="6" max="15" oninput="calcNps()">
  </div>

  <div style="margin:1rem 0 0.5rem;font-family:'IBM Plex Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.18em;text-transform:uppercase">Asset Allocation (must total 100%)</div>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.75rem">
    <?php foreach (['eq'=>['Equity (E)','75'],'gb'=>['Govt Bonds (G)','15'],'cb'=>['Corp Bonds (C)','10']] as $id=>[$label,$val]): ?>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label"><?= $label ?> %</label>
      <input class="form-input" type="number" id="<?= $id ?>" value="<?= $val ?>" min="0" max="75" oninput="calcNps()">
    </div>
    <?php endforeach; ?>
  </div>
  <div style="text-align:right;font-size:0.75rem;margin-top:0.4rem;font-family:'IBM Plex Mono',monospace" id="alloc_check" style="color:var(--lime)">Total: 100%</div>
</div>

<div>
  <!-- Key results -->
  <div class="stats-grid" style="grid-template-columns:1fr 1fr;margin-bottom:1rem">
    <div class="stat-box"><div class="stat-label">Corpus at Retirement</div><div class="stat-value positive" id="corpus">₹—</div></div>
    <div class="stat-box"><div class="stat-label">Est. Monthly Pension</div><div class="stat-value positive" id="pension">₹—</div></div>
    <div class="stat-box"><div class="stat-label">Tax-Free Lumpsum (60%)</div><div class="stat-value neutral" id="lumpsum">₹—</div></div>
    <div class="stat-box"><div class="stat-label">Annuity Corpus (40%)</div><div class="stat-value gold" id="annuity">₹—</div></div>
  </div>

  <!-- Tax benefits -->
  <div class="portal-card" style="margin-bottom:1rem">
    <div class="card-title">Annual Tax Benefits</div>
    <div style="display:flex;flex-direction:column;gap:0.6rem">
      <div style="display:flex;justify-content:space-between;padding:0.5rem 0;border-bottom:1px solid var(--border-light)">
        <span style="color:var(--text-secondary);font-size:0.875rem">Under 80CCD(1) in 80C limit</span>
        <span style="font-family:'IBM Plex Mono',monospace;color:var(--cream)" id="tax80c">—</span>
      </div>
      <div style="display:flex;justify-content:space-between;padding:0.5rem 0;border-bottom:1px solid var(--border-light)">
        <span style="color:var(--text-secondary);font-size:0.875rem">Additional 80CCD(1B) — up to ₹50,000</span>
        <span style="font-family:'IBM Plex Mono',monospace;color:var(--cream)" id="tax80ccd">—</span>
      </div>
      <div style="display:flex;justify-content:space-between;padding:0.5rem 0">
        <span style="color:var(--cream);font-weight:500">Est. Annual Tax Saved (30% bracket)</span>
        <span style="font-family:'IBM Plex Mono',monospace;color:var(--lime);font-size:1.1rem" id="taxsaved">—</span>
      </div>
    </div>
  </div>

  <!-- Chart -->
  <div class="portal-card" style="margin-bottom:1rem">
    <div class="card-title">Corpus Growth</div>
    <canvas id="npsChart" height="220"></canvas>
  </div>

  <div class="portal-card" style="text-align:center">
    <p style="color:var(--text-secondary);font-size:0.875rem;margin-bottom:1rem">Want to open NPS or optimise your contributions?</p>
    <a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=I+used+the+NPS+Projector+on+primefin.in+and+want+help+with+NPS." class="btn-primary btn-sm" target="_blank" rel="noopener">💬 Talk to Advisor</a>
  </div>
</div>
</div>

<!-- Year-by-year table -->
<div class="portal-card" style="margin-top:1.5rem">
  <div class="card-title">Year-by-Year Projection</div>
  <div class="table-wrapper"><table class="portal-table" id="nps_table">
    <thead><tr><th>Age</th><th>Year</th><th>Annual Contribution</th><th>Corpus</th></tr></thead>
    <tbody id="nps_tbody"></tbody>
  </table></div>
</div>

<script>
var npsChart;
function inr(n){ return '₹'+Math.round(n).toLocaleString('en-IN'); }

function calcNps(){
  var curAge = parseInt(document.getElementById('cur_age').value)||30;
  var retAge = parseInt(document.getElementById('ret_age').value)||60;
  var monthly= parseFloat(document.getElementById('monthly').value)||5000;
  var rate   = parseFloat(document.getElementById('ret').value)||10;
  var eq=parseInt(document.getElementById('eq').value)||0, gb=parseInt(document.getElementById('gb').value)||0, cb=parseInt(document.getElementById('cb').value)||0;
  document.getElementById('alloc_check').textContent='Total: '+(eq+gb+cb)+'%';
  document.getElementById('alloc_check').style.color=(eq+gb+cb===100)?'var(--lime)':'var(--danger)';

  var years=retAge-curAge; if(years<1) return;
  var r=(rate/100)/12, n=years*12;
  var corpus = r>0 ? monthly*((Math.pow(1+r,n)-1)/r)*(1+r) : monthly*n;

  var lumpsum60=corpus*0.60, annuity40=corpus*0.40;
  var pension = annuity40*0.06/12;

  document.getElementById('corpus').textContent  = inr(corpus);
  document.getElementById('pension').textContent = inr(pension);
  document.getElementById('lumpsum').textContent = inr(lumpsum60);
  document.getElementById('annuity').textContent = inr(annuity40);

  var annual = monthly*12;
  var in80c  = Math.min(150000, annual);
  var in80ccd= Math.min(50000, Math.max(0,annual-in80c));
  var saved  = (in80c+in80ccd)*0.30*1.04;
  document.getElementById('tax80c').textContent   = inr(in80c);
  document.getElementById('tax80ccd').textContent = inr(in80ccd);
  document.getElementById('taxsaved').textContent = inr(saved);

  // Build year table
  var labels=[], data=[], tbody='', c=0;
  for(var y=1;y<=years;y++){
    var months=y*12, corp=r>0?monthly*((Math.pow(1+r,months)-1)/r)*(1+r):monthly*months;
    var age=curAge+y;
    if(y<=5 || y>years-5 || y===years){
      tbody+='<tr><td>'+age+'</td><td>'+(new Date().getFullYear()+y)+'</td><td>₹'+Math.round(annual).toLocaleString('en-IN')+'</td><td style="color:var(--lime);font-family:\'IBM Plex Mono\',monospace">₹'+Math.round(corp).toLocaleString('en-IN')+'</td></tr>';
    }
    if(y%3===0||y===years){ labels.push('Age '+(curAge+y)); data.push(Math.round(corp)); }
  }
  document.getElementById('nps_tbody').innerHTML = tbody;

  var ctx=document.getElementById('npsChart').getContext('2d');
  if(npsChart) npsChart.destroy();
  npsChart=new Chart(ctx,{type:'line',data:{labels:labels,datasets:[{data:data,borderColor:'#4CAF50',backgroundColor:'rgba(76,175,80,0.1)',fill:true,tension:0.4,pointRadius:3,pointBackgroundColor:'#4CAF50'}]},options:{plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>'₹'+v.toLocaleString('en-IN'),color:'#85a885'},grid:{color:'rgba(46,133,64,0.1)'}},x:{ticks:{color:'#85a885'},grid:{display:false}}}}});
}

document.addEventListener('DOMContentLoaded', calcNps);
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
