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
  <div class="form-group"><label class="form-label">Current Corpus / Savings (₹)</label><input class="form-input" type="number" id="c_corpus" value="500000" step="50000" oninput="model()"></div>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Income Growth (% p.a.)</label><input class="form-input" type="number" id="c_igrow" value="8" step="0.5" oninput="model()"></div>
    <div class="form-group"><label class="form-label">Expense Inflation (% p.a.)</label><input class="form-input" type="number" id="c_egrow" value="6" step="0.5" oninput="model()"></div>
  </div>
  <div class="form-group"><label class="form-label">Investment Return (% p.a.)</label><input class="form-input" type="number" id="c_ret_rate" value="10" step="0.5" oninput="model()"></div>

  <div style="margin:1.25rem 0 0.75rem;font-family:'DM Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.18em;text-transform:uppercase">Life Events (optional)</div>
  <div class="check-row" style="margin-bottom:0.6rem"><input type="checkbox" id="e_marriage" onchange="model()"><label for="e_marriage">Marriage — ₹5L one-time expense in year</label><input type="number" id="e_marriage_yr" value="5" min="1" max="40" style="width:60px;margin-left:0.5rem;background:var(--surface-2);border:1px solid var(--border);border-radius:4px;padding:0.25rem 0.4rem;color:var(--cream);font-size:0.82rem" oninput="model()"></div>
  <div class="check-row" style="margin-bottom:0.6rem"><input type="checkbox" id="e_child" onchange="model()"><label for="e_child">Child born in year</label><input type="number" id="e_child_yr" value="7" min="1" max="30" style="width:60px;margin-left:0.5rem;background:var(--surface-2);border:1px solid var(--border);border-radius:4px;padding:0.25rem 0.4rem;color:var(--cream);font-size:0.82rem" oninput="model()"><label style="margin-left:0.3rem;font-size:0.82rem;color:var(--text-secondary)">+₹3K/month for 18yr</label></div>
  <div class="check-row"><input type="checkbox" id="e_edu" onchange="model()"><label for="e_edu">Child education (₹10L lumpsum) in year</label><input type="number" id="e_edu_yr" value="25" min="1" max="40" style="width:60px;margin-left:0.5rem;background:var(--surface-2);border:1px solid var(--border);border-radius:4px;padding:0.25rem 0.4rem;color:var(--cream);font-size:0.82rem" oninput="model()"></div>
</div>

<div>
  <div class="stats-grid" style="grid-template-columns:1fr 1fr;margin-bottom:1rem">
    <div class="stat-box"><div class="stat-label">Corpus at Retirement</div><div class="stat-value positive" id="r_corpus">—</div></div>
    <div class="stat-box"><div class="stat-label">Monthly Income in Retirement</div><div class="stat-value positive" id="r_monthly">—</div></div>
    <div class="stat-box"><div class="stat-label">Corpus Survives Until</div><div class="stat-value neutral" id="r_survives">—</div></div>
    <div class="stat-box"><div class="stat-label">Retirement Duration Funded</div><div class="stat-value neutral" id="r_funded">—</div></div>
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
    <thead><tr><th>Age</th><th>Annual Income</th><th>Annual Expenses</th><th>Annual Surplus</th><th>Corpus</th></tr></thead>
    <tbody id="cf_tbody"></tbody>
  </table></div>
</div>

<script>
var cfChart;
function inr(n){return '₹'+Math.round(n).toLocaleString('en-IN');}
function model(){
  var age=parseInt(document.getElementById('c_age').value)||30;
  var ret=parseInt(document.getElementById('c_ret').value)||60;
  var inc=parseFloat(document.getElementById('c_income').value)||0;
  var exp=parseFloat(document.getElementById('c_expense').value)||0;
  var corpus=parseFloat(document.getElementById('c_corpus').value)||0;
  var ig=parseFloat(document.getElementById('c_igrow').value)||8;
  var eg=parseFloat(document.getElementById('c_egrow').value)||6;
  var rr=parseFloat(document.getElementById('c_ret_rate').value)||10;
  var marriage=document.getElementById('e_marriage').checked?parseInt(document.getElementById('e_marriage_yr').value):99;
  var child=document.getElementById('e_child').checked?parseInt(document.getElementById('e_child_yr').value):99;
  var edu=document.getElementById('e_edu').checked?parseInt(document.getElementById('e_edu_yr').value):99;

  var labels=[],data=[],colors=[],tbody='',retCorpus=0,surviveAge=80;
  var mInc=inc*12,mExp=exp*12;
  for(var y=0;y<=(80-age);y++){
    var curAge=age+y,yr=y+1;
    var events=0;
    if(y===marriage) events-=500000;
    if(y>=child && y<child+18) events-=36000;
    if(y===edu) events-=1000000;
    if(curAge<=ret){
      var surplus=mInc-mExp+events;
      corpus=corpus*(1+rr/100)+surplus;
      mInc*=(1+ig/100);
      mExp*=(1+eg/100);
      if(curAge===ret) retCorpus=corpus;
    } else {
      corpus=corpus*(1+rr/100)-mExp+events;
      if(corpus<0&&surviveAge===80) surviveAge=curAge-1;
    }
    labels.push(''+curAge);
    data.push(Math.max(0,Math.round(corpus)));
    colors.push(corpus>=0?'rgba(76,175,80,0.7)':'rgba(239,83,80,0.7)');
    if(y%5===0||curAge===ret||curAge===80){
      var expY=curAge<=ret?mInc:0;
      tbody+='<tr'+(curAge===ret?' style="background:rgba(76,175,80,0.08)"':'')+'><td>'+(curAge===ret?'<strong>'+curAge+' (Retire)</strong>':curAge)+'</td><td>'+( curAge<=ret?inr(mInc):'—')+'</td><td>'+inr(mExp)+'</td><td style="color:'+(mInc>mExp?'var(--bright)':'var(--danger)')+'">'+( curAge<=ret?inr(mInc-mExp):'−'+inr(mExp))+'</td><td style="color:'+(corpus>=0?'var(--lime)':'var(--danger)')+'">'+inr(Math.max(0,corpus))+'</td></tr>';
    }
  }
  document.getElementById('r_corpus').textContent=inr(retCorpus);
  document.getElementById('r_monthly').textContent=inr(retCorpus*0.06/12);
  document.getElementById('r_survives').textContent='Age '+surviveAge+(surviveAge>=80?' ✓':'');
  document.getElementById('r_funded').textContent=Math.max(0,surviveAge-(ret))+' years';
  document.getElementById('r_survives').style.color=surviveAge>=80?'var(--bright)':'var(--danger)';
  document.getElementById('cf_tbody').innerHTML=tbody;

  var ctx=document.getElementById('cfChart').getContext('2d');
  if(cfChart)cfChart.destroy();
  // Draw retirement line
  var retIdx=ret-age;
  cfChart=new Chart(ctx,{type:'line',data:{labels:labels,datasets:[{data:data,borderColor:'#4CAF50',backgroundColor:'rgba(76,175,80,0.08)',fill:true,tension:0.4,pointRadius:0},{data:labels.map((_,i)=>i===retIdx?Math.max(...data)*1.05:null),type:'bar',backgroundColor:'rgba(201,168,76,0.2)',barThickness:3,label:'Retirement'}]},options:{interaction:{mode:'index',intersect:false},plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>ctx.datasetIndex===0?' Corpus: ₹'+ctx.raw.toLocaleString('en-IN'):null}}},scales:{y:{ticks:{callback:v=>'₹'+v.toLocaleString('en-IN'),color:'#85a885'},grid:{color:'rgba(46,133,64,0.1)'}},x:{ticks:{color:'#85a885',maxTicksLimit:10},grid:{display:false}}}}});
}
document.addEventListener('DOMContentLoaded',model);
</script>

<?php require_once '../includes/portal-footer.php'; ?>
