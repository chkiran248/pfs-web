<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/subscription.php';
require_login();
require_role('client');
require_premium('cashflow_modeler');

$page_title = 'Cashflow Modeler — Prime Financials';
require_once '../includes/portal-header.php';
?>

<style>
.tip-wrap{position:relative;display:inline-flex;align-items:center;gap:0.35rem}
.tip-icon{display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;background:rgba(46,133,64,0.2);border:1px solid rgba(46,133,64,0.35);color:var(--lime);font-size:0.55rem;font-family:'DM Mono',monospace;cursor:default;flex-shrink:0;line-height:1}
.tip-icon:hover + .tip-box,.tip-wrap:hover .tip-box{opacity:1;pointer-events:auto;transform:translateY(0)}
.tip-box{position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%) translateY(4px);background:var(--surface-2);border:1px solid var(--border);border-radius:8px;padding:0.6rem 0.75rem;font-size:0.72rem;color:var(--text-secondary);line-height:1.5;width:220px;opacity:0;pointer-events:none;transition:opacity 0.18s,transform 0.18s;z-index:200;font-family:'DM Sans',sans-serif;font-weight:400;text-transform:none;letter-spacing:0}
.tip-box::after{content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:var(--border)}
</style>

<p class="page-eyebrow">Advanced Tools</p>
<h1 class="page-title">Lifetime Cashflow Modeler</h1>
<p class="page-subtitle">Project your wealth from today to age 80 — with life events</p>

<div class="grid-2" style="align-items:start">
<div class="portal-card">
  <div class="card-title">Your Financial Profile</div>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Current Age</label><input class="form-input" type="number" id="c_age" value="30" min="20" max="60" oninput="model()"></div>
    <div class="form-group"><label class="form-label">Retirement Age</label><input class="form-input" type="number" id="c_ret" value="60" min="45" max="70" oninput="model()"></div>
  </div>
  <div class="form-group"><label class="form-label">Monthly Income (₹)</label><input class="form-input" type="number" id="c_income" value="100000" step="5000" oninput="model()"></div>
  <div class="form-group"><label class="form-label">Monthly Expenses (₹)</label><input class="form-input" type="number" id="c_expense" value="60000" step="5000" oninput="model()"></div>
  <div class="form-group">
    <label class="form-label">Monthly Savings / Investments (₹)
      <span style="font-family:'DM Mono',monospace;font-size:0.6rem;color:var(--lime);margin-left:0.5rem" id="sav_pct"></span>
    </label>
    <input class="form-input" type="number" id="c_savings" value="30000" step="1000" oninput="model()">
    <div style="font-size:0.75rem;color:var(--text-secondary);margin-top:0.35rem">Amount you actually invest each month (SIPs, PPF, RD, etc.)</div>
  </div>
  <div class="form-group"><label class="form-label">Current Corpus / Savings (₹)</label><input class="form-input" type="number" id="c_corpus" value="500000" step="50000" oninput="model()"></div>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Savings Growth (% p.a.)</label><input class="form-input" type="number" id="c_igrow" value="5" step="0.5" oninput="model()"></div>
    <div class="form-group"><label class="form-label">Expense Inflation (% p.a.)</label><input class="form-input" type="number" id="c_egrow" value="6" step="0.5" oninput="model()"></div>
  </div>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Investment Return (% p.a.)</label><input class="form-input" type="number" id="c_ret_rate" value="10" step="0.5" oninput="model()"></div>
    <div class="form-group"><label class="form-label">Post-Retirement Return (% p.a.)</label><input class="form-input" type="number" id="c_post_rate" value="7" step="0.5" oninput="model()"></div>
  </div>

  <div style="margin:1.25rem 0 0.75rem;font-family:'DM Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.18em;text-transform:uppercase">Life Events (optional)</div>
  <div class="check-row" style="margin-bottom:0.6rem"><input type="checkbox" id="e_marriage" onchange="model()"><label for="e_marriage">Marriage — ₹5L one-time expense in year</label><input type="number" id="e_marriage_yr" value="5" min="1" max="40" style="width:60px;margin-left:0.5rem;background:var(--surface-2);border:1px solid var(--border);border-radius:4px;padding:0.25rem 0.4rem;color:var(--cream);font-size:0.82rem" oninput="model()"></div>
  <div class="check-row" style="margin-bottom:0.6rem"><input type="checkbox" id="e_child" onchange="model()"><label for="e_child">Child born in year</label><input type="number" id="e_child_yr" value="7" min="1" max="30" style="width:60px;margin-left:0.5rem;background:var(--surface-2);border:1px solid var(--border);border-radius:4px;padding:0.25rem 0.4rem;color:var(--cream);font-size:0.82rem" oninput="model()"><label style="margin-left:0.3rem;font-size:0.82rem;color:var(--text-secondary)">+₹3K/month for 18yr</label></div>
  <div class="check-row"><input type="checkbox" id="e_edu" onchange="model()"><label for="e_edu">Child education (₹10L lumpsum) in year</label><input type="number" id="e_edu_yr" value="25" min="1" max="40" style="width:60px;margin-left:0.5rem;background:var(--surface-2);border:1px solid var(--border);border-radius:4px;padding:0.25rem 0.4rem;color:var(--cream);font-size:0.82rem" oninput="model()"></div>
</div>

<div>
  <div class="stats-grid" style="grid-template-columns:1fr 1fr;margin-bottom:1rem">

    <div class="stat-box">
      <div class="stat-label">
        <span class="tip-wrap">Corpus at Retirement
          <span class="tip-icon">?</span>
          <span class="tip-box">Total wealth you will have built by your retirement age, based on your current corpus, monthly savings, and investment returns.</span>
        </span>
      </div>
      <div class="stat-value positive" id="r_corpus">—</div>
    </div>

    <div class="stat-box">
      <div class="stat-label">
        <span class="tip-wrap">Corpus Required
          <span class="tip-icon">?</span>
          <span class="tip-box">Estimated corpus needed for a comfortable retirement. Calculated as 25× your projected annual expenses at retirement (accounting for inflation). Shown in red if you fall short.</span>
        </span>
      </div>
      <div class="stat-value" id="r_needed" style="color:var(--gold)">—</div>
    </div>

    <div class="stat-box">
      <div class="stat-label">
        <span class="tip-wrap">Monthly Expenses at Retirement
          <span class="tip-icon">?</span>
          <span class="tip-box">Your current monthly expenses adjusted for inflation over your working years. This is what you'll actually spend per month on the day you retire — often much higher than today's expenses.</span>
        </span>
      </div>
      <div class="stat-value" id="r_ret_exp" style="color:var(--gold)">—</div>
    </div>

    <div class="stat-box">
      <div class="stat-label">
        <span class="tip-wrap">Safe Monthly Withdrawal
          <span class="tip-icon">?</span>
          <span class="tip-box">Based on the 4% Safe Withdrawal Rate (SWR) — a widely used rule of thumb. This is the monthly amount you can draw from your corpus without depleting it over a 25–30 year retirement.</span>
        </span>
      </div>
      <div class="stat-value positive" id="r_monthly">—</div>
    </div>

    <div class="stat-box">
      <div class="stat-label">
        <span class="tip-wrap">Corpus Survives Until
          <span class="tip-icon">?</span>
          <span class="tip-box">The age at which your retirement corpus runs out, accounting for post-retirement investment returns and inflation-adjusted expenses. Aim for Age 80+.</span>
        </span>
      </div>
      <div class="stat-value neutral" id="r_survives">—</div>
    </div>

    <div class="stat-box">
      <div class="stat-label">
        <span class="tip-wrap">Retirement Duration Funded
          <span class="tip-icon">?</span>
          <span class="tip-box">Number of years your corpus can sustain your retirement lifestyle. A healthy plan should fund at least 20–25 years to cover life expectancy beyond age 80.</span>
        </span>
      </div>
      <div class="stat-value neutral" id="r_funded">—</div>
    </div>

  </div>

  <div class="portal-card">
    <div class="card-title">Lifetime Corpus Trajectory</div>
    <canvas id="cfChart" height="280"></canvas>
  </div>
</div>
</div>

<!-- Table -->
<div class="portal-card" style="margin-top:1.5rem">
  <div class="card-title">Key Milestones</div>
  <div class="table-wrapper"><table class="portal-table">
    <thead><tr><th>Age</th><th>Monthly Income</th><th>Monthly Expenses</th><th>Monthly Savings</th><th>Corpus</th></tr></thead>
    <tbody id="cf_tbody"></tbody>
  </table></div>
</div>

<script>
var cfChart;
function inr(n){return '₹'+Math.round(n).toLocaleString('en-IN');}
function model(){
  var age    = parseInt(document.getElementById('c_age').value)||30;
  var ret    = parseInt(document.getElementById('c_ret').value)||60;
  var inc    = parseFloat(document.getElementById('c_income').value)||0;
  var exp    = parseFloat(document.getElementById('c_expense').value)||0;
  var sav    = parseFloat(document.getElementById('c_savings').value)||0;
  var corpus = parseFloat(document.getElementById('c_corpus').value)||0;
  var sg     = parseFloat(document.getElementById('c_igrow').value)||5;   // savings growth
  var eg     = parseFloat(document.getElementById('c_egrow').value)||6;   // expense inflation
  var rr     = parseFloat(document.getElementById('c_ret_rate').value)||10;
  var pr     = parseFloat(document.getElementById('c_post_rate').value)||7; // post-retirement return
  var marriage = document.getElementById('e_marriage').checked ? parseInt(document.getElementById('e_marriage_yr').value) : 99;
  var child    = document.getElementById('e_child').checked    ? parseInt(document.getElementById('e_child_yr').value)    : 99;
  var edu      = document.getElementById('e_edu').checked      ? parseInt(document.getElementById('e_edu_yr').value)      : 99;

  // Show savings as % of income
  var savPct = inc > 0 ? Math.round(sav/inc*100) : 0;
  document.getElementById('sav_pct').textContent = savPct + '% of income';

  var labels=[],data=[],tbody='',retCorpus=0,retExp=0,surviveAge=80;
  var mSav=sav, mInc=inc, mExp=exp;

  for(var y=0;y<=(80-age);y++){
    var curAge=age+y;
    var events=0;
    if(y===marriage) events-=500000;
    if(y>=child && y<child+18) events-=36000;
    if(y===edu) events-=1000000;

    if(curAge<=ret){
      // Corpus grows at investment return + annual savings + life events
      corpus = corpus*(1+rr/100) + mSav*12 + events;
      mSav  *= (1+sg/100);
      mInc  *= (1+sg/100);
      mExp  *= (1+eg/100);
      if(curAge===ret){ retCorpus=corpus; retExp=mExp; }
    } else {
      // Post-retirement: lower return, expenses grow with inflation
      mExp  *= (1+eg/100);
      corpus = corpus*(1+pr/100) - mExp*12 + events;
      if(corpus<0 && surviveAge===80) surviveAge=curAge-1;
    }

    labels.push(''+curAge);
    data.push(Math.max(0,Math.round(corpus)));

    if(y%5===0 || curAge===ret || curAge===80){
      var isRet=(curAge===ret);
      tbody+='<tr'+(isRet?' style="background:rgba(76,175,80,0.08)"':'')+'>'
        +'<td>'+(isRet?'<strong>'+curAge+' (Retire)</strong>':curAge)+'</td>'
        +'<td>'+inr(mInc)+'</td>'
        +'<td>'+inr(mExp)+'</td>'
        +'<td style="color:var(--bright)">'+(curAge<=ret?inr(mSav):'<span style="color:var(--text-secondary)">Withdrawing</span>')+'</td>'
        +'<td style="color:'+(corpus>=0?'var(--lime)':'var(--danger)')+'">'+inr(Math.max(0,corpus))+'</td>'
        +'</tr>';
    }
  }

  var corpusNeeded = retExp*12*25; // 25× annual retirement expenses
  var shortfall    = corpusNeeded - retCorpus;
  document.getElementById('r_corpus').textContent    = inr(retCorpus);
  document.getElementById('r_needed').textContent    = inr(corpusNeeded);
  document.getElementById('r_needed').style.color    = retCorpus>=corpusNeeded ? 'var(--bright)' : 'var(--danger)';
  document.getElementById('r_ret_exp').textContent   = inr(retExp)+'/mo';
  document.getElementById('r_monthly').textContent   = inr(retCorpus*0.04/12);
  document.getElementById('r_survives').textContent  = 'Age '+surviveAge+(surviveAge>=80?' ✓':'');
  document.getElementById('r_funded').textContent    = Math.max(0,surviveAge-ret)+' years';
  document.getElementById('r_survives').style.color  = surviveAge>=80?'var(--bright)':'var(--danger)';
  document.getElementById('r_funded').style.color    = surviveAge>=80?'var(--bright)':(surviveAge-ret>15?'var(--gold)':'var(--danger)');
  document.getElementById('cf_tbody').innerHTML      = tbody;

  var ctx=document.getElementById('cfChart').getContext('2d');
  if(cfChart) cfChart.destroy();
  var retIdx=ret-age;
  cfChart=new Chart(ctx,{
    type:'line',
    data:{labels:labels,datasets:[
      {data:data,borderColor:'#4CAF50',backgroundColor:'rgba(76,175,80,0.08)',fill:true,tension:0.4,pointRadius:0,label:'Corpus'},
      {data:labels.map((_,i)=>i===retIdx?Math.max(...data)*1.05:null),type:'bar',backgroundColor:'rgba(201,168,76,0.25)',barThickness:3,label:'Retirement'}
    ]},
    options:{
      interaction:{mode:'index',intersect:false},
      plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return c.datasetIndex===0?' Corpus: ₹'+c.raw.toLocaleString('en-IN'):null;}}}},
      scales:{
        y:{ticks:{callback:function(v){return '₹'+v.toLocaleString('en-IN');},color:'#85a885'},grid:{color:'rgba(46,133,64,0.1)'}},
        x:{ticks:{color:'#85a885',maxTicksLimit:10},grid:{display:false}}
      }
    }
  });
}
document.addEventListener('DOMContentLoaded',model);
</script>

<?php require_once '../includes/portal-footer.php'; ?>
