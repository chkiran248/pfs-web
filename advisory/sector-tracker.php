<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();

$sectors = [
    ['name'=>'IT / Technology',    'ytd'=>12.4, 'view'=>'Overweight', 'note'=>'Strong earnings momentum. US tech spend recovery driving Indian IT. Preferred: Large-cap IT with global exposure.'],
    ['name'=>'Banking & Finance',  'ytd'=>8.2,  'view'=>'Overweight', 'note'=>'Improving credit quality, stable NIMs. PSU banks re-rating story ongoing. Watch for RBI rate pivot.'],
    ['name'=>'FMCG',               'ytd'=>-2.1, 'view'=>'Neutral',    'note'=>'Rural recovery underway but urban demand subdued. Valuations remain stretched at current levels.'],
    ['name'=>'Pharma & Healthcare','ytd'=>15.6, 'view'=>'Overweight', 'note'=>'USFDA approvals picking up. Domestic formulations growing well. API segment showing recovery.'],
    ['name'=>'Auto & Auto Ancillary','ytd'=>22.3,'view'=>'Overweight', 'note'=>'EV transition playing out. Premium segment demand robust. 2W rural recovery on track.'],
    ['name'=>'Real Estate',        'ytd'=>31.2, 'view'=>'Neutral',    'note'=>'Strong volume growth but valuations now stretched. Selective top-tier developers preferred.'],
    ['name'=>'Metals & Mining',    'ytd'=>-5.8, 'view'=>'Underweight','note'=>'China demand concerns weighing on steel and aluminium. Commodity cycle likely to remain muted.'],
    ['name'=>'Energy & Power',     'ytd'=>6.4,  'view'=>'Neutral',    'note'=>'Renewable energy capex positive long-term. PSU utilities re-rating partially done.'],
    ['name'=>'Infrastructure',     'ytd'=>18.9, 'view'=>'Overweight', 'note'=>'Govt capex sustained at ₹11L Cr. Order books healthy. Roads, railways, water key themes.'],
    ['name'=>'Chemicals',          'ytd'=>-3.2, 'view'=>'Neutral',    'note'=>'China dumping pressure continues. Select specialty chemical companies preferred over bulk.'],
    ['name'=>'Consumption',        'ytd'=>4.8,  'view'=>'Neutral',    'note'=>'Urban spending resilient. Premiumisation continues. Rural recovery expected H2.'],
    ['name'=>'Capital Goods',      'ytd'=>24.1, 'view'=>'Overweight', 'note'=>'Manufacturing capex cycle in full swing. Order inflows robust. Import substitution theme strong.'],
];
$view_style = ['Overweight'=>['badge-green','var(--bright)'],'Neutral'=>['badge-gold','var(--gold)'],'Underweight'=>['badge-muted','var(--danger)']];

$page_title = 'Sector Tracker — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Advisory</p>
<h1 class="page-title">Sector Tracker</h1>
<p class="page-subtitle">NSE sector performance and advisor views — updated periodically</p>

<div class="disclaimer disclaimer--mf" style="margin-bottom:1.5rem">
  Sector performance figures are illustrative. Actual YTD returns vary. This represents advisor views for educational purposes only. Not investment advice.
</div>

<!-- Heatmap grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-bottom:2rem">
  <?php foreach ($sectors as $s):
    $color = $s['ytd'] > 0 ? 'var(--bright)' : 'var(--danger)';
    $intensity = min(1, abs($s['ytd']) / 30);
    $bg = $s['ytd'] > 0 ? "rgba(76,175,80,{$intensity})" : "rgba(239,83,80,{$intensity})";
    [$badge_class, $badge_color] = $view_style[$s['view']];
  ?>
  <div style="background:var(--surface-1);border:1px solid var(--border);border-radius:12px;padding:1.25rem;border-top:3px solid <?= $color ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.6rem">
      <div style="font-weight:600;color:var(--cream);font-size:0.95rem"><?= $s['name'] ?></div>
      <span class="badge <?= $badge_class ?>"><?= $s['view'] ?></span>
    </div>
    <div style="font-family:'Cormorant Garamond',serif;font-size:2rem;font-weight:700;color:<?= $color ?>;margin-bottom:0.4rem">
      <?= $s['ytd'] > 0 ? '+' : '' ?><?= $s['ytd'] ?>%
    </div>
    <div style="font-size:0.72rem;color:var(--text-muted);font-family:'IBM Plex Mono',monospace;margin-bottom:0.6rem">YTD RETURN (ILLUSTRATIVE)</div>
    <div style="background:var(--surface-2);border-radius:6px;height:4px;overflow:hidden;margin-bottom:0.75rem">
      <div style="width:<?= abs($s['ytd'])/40*100 ?>%;height:100%;background:<?= $color ?>;border-radius:4px"></div>
    </div>
    <p style="font-size:0.78rem;color:var(--text-secondary);line-height:1.55"><?= $s['note'] ?></p>
  </div>
  <?php endforeach; ?>
</div>

<!-- Top picks bridge -->
<div class="portal-card">
  <div class="card-title">Overweight Sectors — Relevant Fund Categories</div>
  <div style="display:flex;flex-direction:column;gap:0.75rem">
    <?php foreach ([['IT','Large Cap IT Funds, Index Funds (NIFTY IT)'],['Banking & Finance','Banking & PSU Debt, Nifty Bank ETF, Financial Services Funds'],['Auto','Auto Sector Funds, Flexi Cap with Auto tilt'],['Infrastructure','Infrastructure Funds, PSU Theme Funds, Nifty 500 Index'],['Capital Goods','Manufacturing Funds, Nifty India Manufacturing ETF'],['Pharma','Pharma Sector Funds, Healthcare Thematic Funds']] as [$sec, $funds]): ?>
    <div style="display:flex;align-items:flex-start;gap:1rem;padding:0.75rem 0;border-bottom:1px solid var(--border-light)">
      <span class="badge badge-green" style="flex-shrink:0"><?= $sec ?></span>
      <span style="font-size:0.875rem;color:var(--text-secondary)"><?= $funds ?></span>
      <a href="<?= SITE_URL ?>/advisory/mutual-funds.php" class="btn-ghost btn-sm" style="flex-shrink:0;margin-left:auto">View Funds →</a>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="disclaimer disclaimer--mf" style="margin-top:1.5rem">AMFI Registered MF Distributor (ARN-<?= AMFI_ARN ?>). Mutual Fund investments subject to market risks. Views expressed are for educational purposes only and may change without notice.</div>

<?php require_once '../includes/portal-footer.php'; ?>
