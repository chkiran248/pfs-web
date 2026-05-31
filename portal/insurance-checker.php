<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$page_title = 'Insurance Checker — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Tools</p>
<h1 class="page-title">Insurance Gap Checker</h1>
<p class="page-subtitle">Find out if your life and health cover is adequate for your situation</p>

<div class="grid-2" style="align-items:start">

<!-- Term Insurance -->
<div class="portal-card">
  <h2 class="section-header" style="font-size:1.15rem">🛡 Term Insurance Analysis</h2>
  <div class="form-group">
    <label class="form-label">Annual Income (₹)</label>
    <input class="form-input" type="number" id="t_income" value="1200000" step="50000" oninput="calcInsurance()">
  </div>
  <div class="form-group">
    <label class="form-label">Outstanding Loans (₹) <span style="color:var(--text-muted);font-size:0.78rem">home + car + personal</span></label>
    <input class="form-input" type="number" id="t_loans" value="0" step="100000" oninput="calcInsurance()">
  </div>
  <div class="form-group">
    <label class="form-label">Number of Dependents</label>
    <input class="form-input" type="number" id="t_dep" value="2" min="0" max="10" oninput="calcInsurance()">
  </div>
  <div class="form-group">
    <label class="form-label">Existing Term Cover (₹)</label>
    <input class="form-input" type="number" id="t_existing" value="0" step="500000" oninput="calcInsurance()">
  </div>

  <div style="background:var(--surface-2);border-radius:10px;padding:1.25rem;margin-top:0.75rem">
    <div style="display:flex;justify-content:space-between;margin-bottom:0.6rem">
      <span style="color:var(--text-secondary);font-size:0.875rem">Recommended Cover</span>
      <span style="font-family:'DM Mono',monospace;color:var(--cream)" id="t_recommended">—</span>
    </div>
    <div style="display:flex;justify-content:space-between;margin-bottom:0.6rem">
      <span style="color:var(--text-secondary);font-size:0.875rem">Existing Cover</span>
      <span style="font-family:'DM Mono',monospace;color:var(--cream)" id="t_existing_show">₹0</span>
    </div>
    <div style="display:flex;justify-content:space-between;border-top:1px solid var(--border);padding-top:0.6rem;margin-top:0.6rem">
      <span style="font-weight:600;color:var(--cream)">Coverage Gap</span>
      <span style="font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:700" id="t_gap">—</span>
    </div>
  </div>

  <!-- Protection score -->
  <div style="margin-top:1rem">
    <div style="display:flex;justify-content:space-between;margin-bottom:0.4rem">
      <span style="font-size:0.8rem;color:var(--text-secondary)">Protection Score</span>
      <span style="font-family:'DM Mono',monospace;font-size:0.85rem" id="t_score_label">0%</span>
    </div>
    <div style="background:var(--surface-2);border-radius:6px;height:10px;overflow:hidden">
      <div id="t_score_bar" style="height:100%;width:0%;border-radius:6px;transition:width 0.5s,background 0.5s"></div>
    </div>
    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.3rem" id="t_verdict">—</div>
  </div>
</div>

<!-- Health Insurance -->
<div class="portal-card">
  <h2 class="section-header" style="font-size:1.15rem">🏥 Health Insurance Analysis</h2>
  <div class="form-group">
    <label class="form-label">Family Coverage</label>
    <select class="form-select" id="h_family" onchange="calcInsurance()">
      <option value="self">Self only</option>
      <option value="couple" selected>Self + Spouse</option>
      <option value="family">Family Floater (with kids)</option>
    </select>
  </div>
  <div class="form-group">
    <label class="form-label">City Tier</label>
    <select class="form-select" id="h_city" onchange="calcInsurance()">
      <option value="metro">Metro (Mumbai, Delhi, Bengaluru etc.)</option>
      <option value="tier2" selected>Tier 2 City</option>
      <option value="tier3">Tier 3 / Small Town</option>
    </select>
  </div>
  <div class="form-group">
    <label class="form-label">Existing Health Cover (₹) <span style="color:var(--text-muted);font-size:0.78rem">incl. employer cover</span></label>
    <input class="form-input" type="number" id="h_existing" value="300000" step="100000" oninput="calcInsurance()">
  </div>

  <div style="background:var(--surface-2);border-radius:10px;padding:1.25rem;margin-top:0.75rem">
    <div style="display:flex;justify-content:space-between;margin-bottom:0.6rem">
      <span style="color:var(--text-secondary);font-size:0.875rem">Recommended Cover</span>
      <span style="font-family:'DM Mono',monospace;color:var(--cream)" id="h_recommended">—</span>
    </div>
    <div style="display:flex;justify-content:space-between;margin-bottom:0.6rem">
      <span style="color:var(--text-secondary);font-size:0.875rem">Existing Cover</span>
      <span style="font-family:'DM Mono',monospace;color:var(--cream)" id="h_existing_show">—</span>
    </div>
    <div style="display:flex;justify-content:space-between;border-top:1px solid var(--border);padding-top:0.6rem;margin-top:0.6rem">
      <span style="font-weight:600;color:var(--cream)">Upgrade Needed</span>
      <span style="font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:700" id="h_gap">—</span>
    </div>
  </div>

  <!-- Score -->
  <div style="margin-top:1rem">
    <div style="display:flex;justify-content:space-between;margin-bottom:0.4rem">
      <span style="font-size:0.8rem;color:var(--text-secondary)">Health Cover Score</span>
      <span style="font-family:'DM Mono',monospace;font-size:0.85rem" id="h_score_label">0%</span>
    </div>
    <div style="background:var(--surface-2);border-radius:6px;height:10px;overflow:hidden">
      <div id="h_score_bar" style="height:100%;width:0%;border-radius:6px;transition:width 0.5s,background 0.5s"></div>
    </div>
    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.3rem" id="h_verdict">—</div>
  </div>
</div>
</div>

<!-- CTA result -->
<div class="portal-card" style="margin-top:1.5rem;text-align:center">
  <div style="font-size:1.5rem;margin-bottom:0.5rem">📋</div>
  <h2 class="section-header" style="border:none;padding:0;font-size:1.25rem;margin-bottom:0.5rem">Your Protection Summary</h2>
  <p style="color:var(--text-secondary);margin-bottom:1.25rem" id="summary_text">Enter your details above to see your personalised insurance recommendation.</p>
  <a href="#" id="wa_link" class="btn-primary" target="_blank" rel="noopener">💬 Get Insurance Advice on WhatsApp</a>
</div>

<script>
function inr(n){ return '₹'+Math.round(n).toLocaleString('en-IN'); }
function crore(n){ return n>=10000000?'₹'+(n/10000000).toFixed(1)+' Cr':(n>=100000?'₹'+(n/100000).toFixed(1)+' L':inr(n)); }

function setScore(barId, labelId, verdictId, score){
  var pct=Math.min(100,Math.max(0,score));
  var col=pct>=80?'var(--bright)':pct>=50?'var(--gold)':'var(--danger)';
  document.getElementById(barId).style.width=pct+'%';
  document.getElementById(barId).style.background=col;
  document.getElementById(labelId).textContent=Math.round(pct)+'%';
  document.getElementById(labelId).style.color=col;
  document.getElementById(verdictId).textContent=pct>=80?'✓ Well covered':pct>=50?'⚠ Partially covered':'✗ Significantly under-insured';
}

function calcInsurance(){
  var income   = parseFloat(document.getElementById('t_income').value)||0;
  var loans    = parseFloat(document.getElementById('t_loans').value)||0;
  var dep      = parseInt(document.getElementById('t_dep').value)||0;
  var tExist   = parseFloat(document.getElementById('t_existing').value)||0;

  var termRec  = income*15 + loans;
  var termGap  = Math.max(0, termRec - tExist);
  var tScore   = termRec>0?Math.min(100,(tExist/termRec)*100):100;

  document.getElementById('t_recommended').textContent = crore(termRec);
  document.getElementById('t_existing_show').textContent = crore(tExist);
  document.getElementById('t_gap').textContent = termGap>0?crore(termGap):'✓ Fully covered';
  document.getElementById('t_gap').style.color = termGap>0?'var(--danger)':'var(--bright)';
  setScore('t_score_bar','t_score_label','t_verdict',tScore);

  var family = document.getElementById('h_family').value;
  var city   = document.getElementById('h_city').value;
  var hExist = parseFloat(document.getElementById('h_existing').value)||0;
  var hRec   = {self:{metro:1500000,tier2:1000000,tier3:700000},couple:{metro:2500000,tier2:2000000,tier3:1500000},family:{metro:3000000,tier2:2500000,tier3:2000000}};
  var recommended = hRec[family][city];
  var hGap = Math.max(0, recommended - hExist);
  var hScore = Math.min(100,(hExist/recommended)*100);

  document.getElementById('h_recommended').textContent = crore(recommended);
  document.getElementById('h_existing_show').textContent = crore(hExist);
  document.getElementById('h_gap').textContent = hGap>0?crore(hGap):'✓ Adequate cover';
  document.getElementById('h_gap').style.color = hGap>0?'var(--gold)':'var(--bright)';
  setScore('h_score_bar','h_score_label','h_verdict',hScore);

  var msg = 'Hi%2C+I+checked+my+insurance+gap+on+primefin.in.+I+need+'+encodeURIComponent(crore(termGap))+'+more+term+cover+and+'+encodeURIComponent(crore(hGap))+'+more+health+cover.+Can+you+help%3F';
  document.getElementById('wa_link').href = 'https://wa.me/<?= WHATSAPP_NUM ?>?text='+msg;

  var summary = '';
  if(termGap>0 && hGap>0) summary = 'You need an additional ' + crore(termGap) + ' term cover and ' + crore(hGap) + ' health cover upgrade. Talk to our advisor for the best plans.';
  else if(termGap>0) summary = 'Your health cover looks adequate, but you need an additional ' + crore(termGap) + ' term life cover.';
  else if(hGap>0) summary = 'Your term cover looks good, but your health insurance needs an upgrade of ' + crore(hGap) + '.';
  else summary = '✓ Great! Your insurance coverage looks adequate. Our advisor can do a detailed review.';
  document.getElementById('summary_text').textContent = summary;
}

document.addEventListener('DOMContentLoaded', calcInsurance);
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
