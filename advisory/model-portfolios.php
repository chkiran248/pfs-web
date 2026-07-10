<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
// Advisory pages accessible to both clients and admins
if (!is_logged_in()) { header('Location: ' . SITE_URL . '/auth/login.php'); exit; }

$db = get_db();

// Try to get portfolios from DB
$stmt = $db->prepare("SELECT mp.*, GROUP_CONCAT(mph.instrument_name, '|', mph.allocation_pct, '|', mph.instrument_type, '|', COALESCE(mph.rationale,'') SEPARATOR ';;') as holdings_raw FROM model_portfolios mp LEFT JOIN model_portfolio_holdings mph ON mph.portfolio_id = mp.id WHERE mp.is_active = 1 GROUP BY mp.id ORDER BY FIELD(mp.risk_profile,'conservative','moderate','aggressive')");
$stmt->execute();
$portfolios = $stmt->fetchAll();

// Static fallback
$static = [
    ['portfolio_name'=>'Conservative Vault','risk_profile'=>'conservative','description'=>'Capital preservation with steady income. Ideal for investors within 3 years of a goal or with low risk tolerance.','target_return'=>8.0,'rebalance_freq'=>'quarterly','holdings'=>[['Liquid Fund',20,'mutual_fund','Emergency buffer, high liquidity'],['Short Duration Debt',30,'mutual_fund','Stable returns, low volatility'],['Large Cap Equity',30,'mutual_fund','Quality growth with lower drawdown'],['Gold',10,'gold','Inflation hedge & portfolio anchor'],['FD / Senior Schemes',10,'fd','Capital protection, guaranteed return']]],
    ['portfolio_name'=>'Balanced Growth Portfolio','risk_profile'=>'moderate','description'=>'Balanced growth with moderate risk. Suitable for investors with 5â€“7 year horizon and moderate risk appetite.','target_return'=>11.0,'rebalance_freq'=>'quarterly','holdings'=>[['Large Cap Equity',40,'mutual_fund','Foundation â€” quality blue chip exposure'],['Flexi Cap Fund',20,'mutual_fund','Active management across market caps'],['Hybrid / Balanced Adv.',25,'mutual_fund','Risk modulation with equity + debt'],['Gold',10,'gold','Diversification and inflation protection'],['International / US Equity',5,'etf','Global exposure and currency diversification']]],
    ['portfolio_name'=>'Aggressive Wealth Builder','risk_profile'=>'aggressive','description'=>'Maximum long-term wealth creation. For investors with 7+ year horizon and high risk tolerance.','target_return'=>14.0,'rebalance_freq'=>'yearly','holdings'=>[['Mid & Small Cap',35,'mutual_fund','High growth potential, higher volatility'],['Flexi Cap / Multi Cap',30,'mutual_fund','Flexible allocation across market caps'],['International / US Equity',20,'etf','Global diversification and dollar returns'],['Gold',10,'gold','Portfolio stabiliser'],['Thematic / Sector Funds',5,'mutual_fund','Tactical high-conviction bets']]],
];

$use_static = empty($portfolios);
$risk_badge = ['conservative'=>'badge-green','moderate'=>'badge-gold','aggressive'=>'badge-muted'];
$chart_colors = ['#1B5E2A','#2E8540','#4CAF50','#8DC63F','#C9A84C','#558b2f','#a5d6a7','#66BB6A'];

$page_title = 'Model Portfolios â€” Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Advisory</p>
<h1 class="page-title">Model Portfolios</h1>
<p class="page-subtitle">Illustrative, research-backed portfolio frameworks for different risk profiles</p>

<div class="disclaimer disclaimer--mf">
  These are illustrative model portfolios for educational purposes only. Actual recommended allocation may vary based on your individual risk profile, tax situation, and financial goals. AMFI Registered MF Distributor (ARN-<?= AMFI_ARN ?>). MF investments subject to market risks.
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1.5rem;margin-top:1.5rem">
  <?php
  $display = $use_static ? $static : [];
  if (!$use_static) {
      foreach ($portfolios as $p) {
          $holdings = [];
          if ($p['holdings_raw']) {
              foreach (explode(';;', $p['holdings_raw']) as $h) {
                  $parts = explode('|', $h);
                  if (count($parts) >= 2) $holdings[] = [$parts[0], (float)$parts[1], $parts[2]??'mutual_fund', $parts[3]??''];
              }
          }
          $display[] = array_merge($p, ['holdings'=>$holdings]);
      }
  }
  foreach ($display as $idx => $p):
    $chartId = 'chart_' . $idx;
    $labels  = array_map(fn($h) => $h[0], $p['holdings']);
    $data    = array_map(fn($h) => $h[1], $p['holdings']);
  ?>
  <div class="portal-card">
    <div style="margin-bottom:1rem">
      <span class="badge <?= $risk_badge[$p['risk_profile']] ?>"><?= ucfirst($p['risk_profile']) ?></span>
      <?php if ($p['rebalance_freq']): ?><span class="badge badge-muted" style="margin-left:0.4rem"><?= ucfirst($p['rebalance_freq']) ?> rebalance</span><?php endif; ?>
    </div>
    <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.35rem;font-weight:600;color:var(--cream);margin-bottom:0.4rem"><?= htmlspecialchars($p['portfolio_name'],ENT_QUOTES,'UTF-8') ?></h3>
    <p style="font-size:0.875rem;color:var(--text-secondary);line-height:1.6;margin-bottom:1rem"><?= htmlspecialchars($p['description']??'',ENT_QUOTES,'UTF-8') ?></p>
    <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem">
      <div class="stat-label">Target Return</div>
      <div style="font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:700;color:var(--lime)"><?= number_format((float)$p['target_return'],1) ?>% p.a.</div>
    </div>
    <canvas id="<?= $chartId ?>" height="200" style="margin-bottom:1rem"></canvas>
    <!-- Holdings table -->
    <table style="width:100%;border-collapse:collapse;font-size:0.8rem">
      <?php foreach ($p['holdings'] as $h): ?>
      <tr style="border-bottom:1px solid var(--border-light)">
        <td style="padding:0.4rem 0;color:var(--text-secondary)"><?= htmlspecialchars($h[0],ENT_QUOTES,'UTF-8') ?></td>
        <td style="padding:0.4rem 0;text-align:right;font-family:'DM Mono',monospace;color:var(--lime)"><?= $h[1] ?>%</td>
      </tr>
      <?php endforeach; ?>
    </table>
    <div style="margin-top:1rem">
      <a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=I+want+to+discuss+the+<?= urlencode($p['portfolio_name']) ?>+model+portfolio+on+primefin.in" class="btn-outline btn-sm" target="_blank" rel="noopener">ðŸ’¬ Discuss This Portfolio</a>
    </div>
  </div>
  <script>
  document.addEventListener('DOMContentLoaded',function(){
    new Chart(document.getElementById('<?= $chartId ?>'),{
      type:'doughnut',
      data:{labels:<?= json_encode($labels) ?>,datasets:[{data:<?= json_encode($data) ?>,backgroundColor:<?= json_encode(array_slice($chart_colors, 0, count($labels))) ?>,borderColor:'#0c140c',borderWidth:3,hoverOffset:4}]},
      options:{cutout:'62%',plugins:{legend:{position:'bottom',labels:{color:'#85a885',font:{family:"'DM Mono'"},padding:10,boxWidth:10,font:{size:11}}},tooltip:{callbacks:{label:ctx=>ctx.label+': '+ctx.raw+'%'}}}}
    });
  });
  </script>
  <?php endforeach; ?>
</div>

<?php require_once '../includes/portal-footer.php'; ?>

