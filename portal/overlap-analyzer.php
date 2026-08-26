<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/subscription.php';
require_login();
require_role('client');
require_premium('overlap_analyzer');

$db  = get_db();
$uid = get_user_id();

// Fetch equity holdings
$stmt = $db->prepare("SELECT fund_name, fund_house, fund_type FROM portfolio_entries WHERE user_id = :uid AND fund_type IN ('equity','elss','index','hybrid') ORDER BY fund_name");
$stmt->execute([':uid' => $uid]);
$holdings = $stmt->fetchAll();

// Sample holdings by category
$sample = [
    'equity'  => ['HDFC Bank','Reliance Ind.','Infosys','ICICI Bank','TCS','L&T','Axis Bank','SBI','HCL Technologies','Bajaj Finance'],
    'elss'    => ['HDFC Bank','Reliance Ind.','Infosys','ICICI Bank','Maruti Suzuki','TCS','Bajaj Finance','L&T','SBI','HUL'],
    'index'   => ['Reliance Ind.','TCS','HDFC Bank','Infosys','ICICI Bank','L&T','Bajaj Finance','HUL','ITC','Kotak Mahindra Bank'],
    'hybrid'  => ['HDFC Bank','Reliance Ind.','Infosys','ICICI Bank','TCS','Axis Bank','SBI','L&T','Sun Pharma','Maruti Suzuki'],
];

$page_title = 'Overlap Analyzer — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Advanced Tools</p>
<h1 class="page-title">Portfolio Overlap Analyzer</h1>
<p class="page-subtitle">Detect hidden stock duplication across your mutual funds</p>

<div class="disclaimer disclaimer--mf" style="margin-bottom:1.5rem">
  <strong>Educational Tool</strong> — This analysis uses illustrative fund composition data for demonstration. Actual fund holdings change monthly. Visit MFCentral or respective AMC websites for real-time data.
</div>

<?php if (empty($holdings)): ?>
<div class="portal-card" style="text-align:center;padding:3rem;color:var(--text-secondary)">
  <div style="font-size:2.5rem;margin-bottom:1rem">◈</div>
  <p>No equity or hybrid fund holdings found in your portfolio.</p>
  <a href="<?= SITE_URL ?>/portal/portfolio.php" class="btn-primary btn-sm" style="margin-top:1rem">Add Holdings →</a>
</div>
<?php else: ?>

<!-- Fund list -->
<div class="portal-card" style="margin-bottom:1.5rem">
  <div class="card-title">Your Equity Holdings (<?= count($holdings) ?> funds)</div>
  <div style="display:flex;flex-wrap:wrap;gap:0.75rem">
    <?php foreach ($holdings as $h): ?>
    <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:8px;padding:0.6rem 1rem">
      <div style="font-weight:500;color:var(--cream);font-size:0.875rem"><?= htmlspecialchars($h['fund_name'], ENT_QUOTES, 'UTF-8') ?></div>
      <span class="badge badge-green"><?= ucfirst($h['fund_type']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Top holdings per fund -->
<div class="grid-2" style="margin-bottom:1.5rem">
  <?php foreach ($holdings as $h):
    $stocks = $sample[$h['fund_type']] ?? $sample['equity'];
  ?>
  <div class="portal-card">
    <div style="font-weight:600;color:var(--cream);margin-bottom:0.75rem;font-size:0.9rem"><?= htmlspecialchars($h['fund_name'], ENT_QUOTES, 'UTF-8') ?></div>
    <div style="font-family:'IBM Plex Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.15em;margin-bottom:0.6rem">ILLUSTRATIVE TOP 10 HOLDINGS</div>
    <?php foreach ($stocks as $i => $s): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:0.25rem 0;border-bottom:1px solid var(--border-light);font-size:0.8rem">
      <span style="color:var(--text-secondary)"><?= $i+1 ?>. <?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?></span>
      <span style="font-family:'IBM Plex Mono',monospace;color:var(--cream)"><?= number_format(rand(4,14) + 0.1*rand(0,9), 1) ?>%</span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- Overlap matrix -->
<?php if (count($holdings) >= 2):
  // Calculate pairwise overlap
  $overlap_data = [];
  foreach ($holdings as $i => $fi) {
      $si = $sample[$fi['fund_type']] ?? $sample['equity'];
      foreach ($holdings as $j => $fj) {
          if ($i >= $j) continue;
          $sj = $sample[$fj['fund_type']] ?? $sample['equity'];
          $common = count(array_intersect($si, $sj));
          $total  = count(array_unique(array_merge($si, $sj)));
          $pct    = round($common / $total * 100);
          $overlap_data[] = ['f1' => $fi['fund_name'], 'f2' => $fj['fund_name'], 'common' => $common, 'pct' => $pct];
      }
  }
  $avg_overlap = count($overlap_data) > 0 ? array_sum(array_column($overlap_data, 'pct')) / count($overlap_data) : 0;
  $div_score = round(100 - $avg_overlap);
?>
<div class="portal-card" style="margin-bottom:1.5rem">
  <div class="card-title">Overlap Matrix</div>
  <div class="table-wrapper">
    <table class="portal-table">
      <thead><tr><th>Fund Pair</th><th>Common Stocks</th><th>Overlap %</th><th>Assessment</th></tr></thead>
      <tbody>
        <?php foreach ($overlap_data as $o):
          $col = $o['pct'] >= 40 ? 'var(--danger)' : ($o['pct'] >= 20 ? 'var(--gold)' : 'var(--bright)');
          $label = $o['pct'] >= 40 ? 'High Overlap' : ($o['pct'] >= 20 ? 'Moderate' : 'Low Overlap');
        ?>
        <tr>
          <td style="font-size:0.82rem"><?= htmlspecialchars(substr($o['f1'],0,25), ENT_QUOTES,'UTF-8') ?> vs <?= htmlspecialchars(substr($o['f2'],0,25), ENT_QUOTES,'UTF-8') ?></td>
          <td style="text-align:center"><?= $o['common'] ?>/10</td>
          <td style="font-family:'IBM Plex Mono',monospace;color:<?= $col ?>"><?= $o['pct'] ?>%</td>
          <td><span class="badge" style="background:<?= $col ?>22;color:<?= $col ?>"><?= $label ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="portal-card">
  <div class="card-title">Diversification Score</div>
  <div style="display:flex;align-items:center;gap:2rem;flex-wrap:wrap">
    <div>
      <div style="font-family:'Cormorant Garamond',serif;font-size:3rem;font-weight:700;color:<?= $div_score>=80?'var(--bright)':($div_score>=60?'var(--gold)':'var(--danger)') ?>"><?= $div_score ?>/100</div>
      <div style="font-size:0.875rem;color:var(--text-secondary)"><?= $div_score>=80?'Well diversified':($div_score>=60?'Moderately diversified':'Concentrated portfolio') ?></div>
    </div>
    <div style="flex:1;min-width:200px">
      <div style="background:var(--surface-2);border-radius:8px;height:16px;overflow:hidden">
        <div style="width:<?= $div_score ?>%;height:100%;background:<?= $div_score>=80?'var(--bright)':($div_score>=60?'var(--gold)':'var(--danger)') ?>;border-radius:8px"></div>
      </div>
      <?php if ($avg_overlap > 30): ?>
      <div class="flash-info" style="margin-top:1rem;font-size:0.82rem">💡 High overlap detected. Consider replacing one fund with a Mid-Cap or International fund for better diversification.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once '../includes/portal-footer.php'; ?>
