<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/subscription.php';
require_login();
require_role('client');
require_premium('tax_modeler');

$page_title = 'Tax Switch Modeler — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Advanced Tools</p>
<h1 class="page-title">Tax-Aware Switch Modeler</h1>
<p class="page-subtitle">Calculate LTCG/STCG tax before redeeming or switching funds — post July 2024 budget rates</p>

<div class="disclaimer disclaimer--mf">
  <strong>Post July 2024 Budget Tax Rates</strong> — Equity LTCG (&gt;1 year): 12.5% (₹1.25L exemption/year) · Equity STCG (&lt;1 year): 20% · Debt LTCG (&gt;3 years): 20% · Debt STCG: As per slab. This tool provides indicative calculations. Consult a CA for precise tax advice.
</div>

<!-- Add fund rows -->
<div id="funds-container">
  <div class="fund-row portal-card" id="row-0" style="margin-top:1.25rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
      <div class="card-title" style="margin-bottom:0">Fund 1</div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Fund Name</label><input class="form-input" type="text" name="fund_name[]" placeholder="e.g. Mirae Asset Large Cap Fund"></div>
      <div class="form-group"><label class="form-label">Fund Type</label>
        <select class="form-select" name="fund_type[]" onchange="calcRow(this)">
          <option value="equity">Equity / ELSS / Index</option>
          <option value="debt">Debt</option>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Purchase Date</label><input class="form-input" type="date" name="purchase_date[]" oninput="calcRow(this)"></div>
      <div class="form-group"><label class="form-label">Units to Redeem</label><input class="form-input" type="number" name="units[]" step="0.001" placeholder="100.000" oninput="calcRow(this)"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Purchase NAV (₹)</label><input class="form-input" type="number" name="buy_nav[]" step="0.01" placeholder="45.00" oninput="calcRow(this)"></div>
      <div class="form-group"><label class="form-label">Current NAV (₹)</label><input class="form-input" type="number" name="sell_nav[]" step="0.01" placeholder="78.50" oninput="calcRow(this)"></div>
    </div>
    <!-- Result -->
    <div class="row-result" style="background:var(--surface-2);border-radius:10px;padding:1.25rem;margin-top:0.75rem;display:none">
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:0.75rem">
        <div><div style="font-size:0.72rem;color:var(--text-muted);font-family:'IBM Plex Mono',monospace">GAIN/LOSS</div><div style="font-size:1.1rem;font-weight:600" class="r-gain">—</div></div>
        <div><div style="font-size:0.72rem;color:var(--text-muted);font-family:'IBM Plex Mono',monospace">TAX TYPE</div><div style="font-size:1.1rem;font-weight:600" class="r-type">—</div></div>
        <div><div style="font-size:0.72rem;color:var(--text-muted);font-family:'IBM Plex Mono',monospace">TAX DUE</div><div style="font-size:1.1rem;font-weight:600;color:var(--danger)" class="r-tax">—</div></div>
      </div>
      <div style="display:flex;justify-content:space-between;border-top:1px solid var(--border);padding-top:0.75rem">
        <span style="color:var(--text-secondary)">Post-tax Proceeds</span>
        <span style="font-family:'Cormorant Garamond',serif;font-size:1.35rem;color:var(--lime)" class="r-proceeds">—</span>
      </div>
      <div class="r-tip" style="margin-top:0.6rem;font-size:0.8rem;color:var(--gold)"></div>
    </div>
  </div>
</div>

<button type="button" class="btn-outline btn-sm" style="margin-top:1rem" onclick="addRow()">+ Add Another Fund</button>

<!-- Summary -->
<div class="portal-card" style="margin-top:1.5rem" id="summary" style="display:none">
  <div class="card-title">Redemption Summary</div>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem">
    <div class="stat-box"><div class="stat-label">Total Redemption Value</div><div class="stat-value neutral" id="s_total">—</div></div>
    <div class="stat-box"><div class="stat-label">Total Tax Liability</div><div class="stat-value negative" id="s_tax">—</div></div>
    <div class="stat-box"><div class="stat-label">Post-tax Proceeds</div><div class="stat-value positive" id="s_proceeds">—</div></div>
    <div class="stat-box"><div class="stat-label">Effective Tax Rate</div><div class="stat-value gold" id="s_rate">—</div></div>
  </div>
</div>

<script>
var rowCount=1;
function inr(n){return '₹'+Math.round(n).toLocaleString('en-IN');}

function calcRow(el){
  var row=el.closest('.fund-row');
  var type=row.querySelector('[name="fund_type[]"]').value;
  var purchase=row.querySelector('[name="purchase_date[]"]').value;
  var units=parseFloat(row.querySelector('[name="units[]"]').value)||0;
  var buyNav=parseFloat(row.querySelector('[name="buy_nav[]"]').value)||0;
  var sellNav=parseFloat(row.querySelector('[name="sell_nav[]"]').value)||0;
  if(!purchase||!units||!buyNav||!sellNav) return;

  var invested=units*buyNav, value=units*sellNav, gain=value-invested;
  var days=Math.floor((new Date()-new Date(purchase))/86400000);
  var months=Math.floor(days/30);

  var taxType,tax=0,tip='';
  if(type==='equity'){
    if(days>365){
      taxType='LTCG (>1yr) @12.5%';
      tax=Math.max(0,gain-125000)*0.125;
      tip=gain>125000?'₹1.25L LTCG exemption applied.':'✓ Gain is within ₹1.25L LTCG exemption — no tax!';
    } else {
      taxType='STCG (<1yr) @20%';
      tax=Math.max(0,gain)*0.20;
      var daysLeft=366-days;
      tip=gain>0?'Hold '+daysLeft+' more day(s) to qualify for LTCG rate.':'';
    }
  } else {
    if(days>1095){
      taxType='Debt LTCG (>3yr) @20%';
      tax=Math.max(0,gain)*0.20;
    } else {
      taxType='Debt STCG — as per slab';
      tax=0; tip='Debt STCG taxed per your income slab. Estimated here at 30%: '+inr(gain*0.30);
    }
  }

  var result=row.querySelector('.row-result');
  result.style.display='block';
  result.querySelector('.r-gain').textContent=inr(gain);
  result.querySelector('.r-gain').style.color=gain>=0?'var(--bright)':'var(--danger)';
  result.querySelector('.r-type').textContent=taxType;
  result.querySelector('.r-tax').textContent=inr(tax);
  result.querySelector('.r-proceeds').textContent=inr(value-tax);
  result.querySelector('.r-tip').textContent=tip;
  updateSummary();
}

function updateSummary(){
  var rows=document.querySelectorAll('.fund-row');
  var totalVal=0,totalTax=0;
  rows.forEach(function(r){
    var units=parseFloat(r.querySelector('[name="units[]"]').value)||0;
    var sellNav=parseFloat(r.querySelector('[name="sell_nav[]"]').value)||0;
    var taxEl=r.querySelector('.r-tax');
    if(taxEl&&taxEl.textContent!=='—'){
      totalVal+=units*sellNav;
      totalTax+=parseFloat(taxEl.textContent.replace(/[₹,]/g,''))||0;
    }
  });
  if(totalVal>0){
    document.getElementById('summary').style.display='block';
    document.getElementById('s_total').textContent=inr(totalVal);
    document.getElementById('s_tax').textContent=inr(totalTax);
    document.getElementById('s_proceeds').textContent=inr(totalVal-totalTax);
    document.getElementById('s_rate').textContent=(totalVal>0?(totalTax/totalVal*100).toFixed(1):'0')+'%';
  }
}

function addRow(){
  rowCount++;
  var tmpl=document.getElementById('row-0').cloneNode(true);
  tmpl.id='row-'+rowCount;
  tmpl.querySelector('.card-title').textContent='Fund '+rowCount;
  tmpl.querySelectorAll('input').forEach(function(i){i.value='';});
  tmpl.querySelector('.row-result').style.display='none';
  var delBtn=document.createElement('button');
  delBtn.type='button'; delBtn.className='btn-danger btn-sm'; delBtn.textContent='Remove';
  delBtn.style.float='right';
  delBtn.onclick=function(){tmpl.remove();updateSummary();};
  tmpl.querySelector('.card-title').parentNode.appendChild(delBtn);
  document.getElementById('funds-container').appendChild(tmpl);
  tmpl.querySelectorAll('input,select').forEach(function(el){el.addEventListener('input',function(){calcRow(el);});el.addEventListener('change',function(){calcRow(el);});});
}
</script>

<?php require_once '../includes/portal-footer.php'; ?>
