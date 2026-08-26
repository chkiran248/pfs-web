<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mf-api.php';
require_login();

$db = get_db();
mf_maybe_refresh($db);
$ids = array_filter([
    (int)($_GET['f1'] ?? 0),
    (int)($_GET['f2'] ?? 0),
    (int)($_GET['f3'] ?? 0),
]);

$all_funds_stmt = $db->prepare("SELECT id, fund_name, fund_house, category FROM fund_recommendations WHERE is_active = 1 ORDER BY fund_name");
$all_funds_stmt->execute();
$all_funds = $all_funds_stmt->fetchAll();

$selected = [];
if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT * FROM fund_recommendations WHERE id IN ($placeholders) AND is_active = 1");
    $stmt->execute(array_values($ids));
    $selected = $stmt->fetchAll();
}

$risk_badge = ['low'=>'badge-green','moderate'=>'badge-gold','high'=>'badge-gold','very_high'=>'badge-muted'];
$page_title = 'Fund Compare — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Advisory</p>
<h1 class="page-title">Fund Compare</h1>
<p class="page-subtitle">Compare up to 3 mutual funds side by side</p>

<div class="disclaimer disclaimer--mf">MF investments subject to market risks. Returns shown are indicative. Prime Financials — AMFI Registered MF Distributor (ARN-<?= AMFI_ARN ?>).</div>

<!-- Fund selectors -->
<form method="GET" style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;margin:1.25rem 0">
  <?php for ($i = 1; $i <= 3; $i++): ?>
  <div style="flex:1;min-width:200px">
    <label class="form-label">Fund <?= $i ?></label>
    <select class="form-select" name="f<?= $i ?>" onchange="this.form.submit()">
      <option value="">— Select Fund —</option>
      <?php foreach ($all_funds as $f): ?>
      <option value="<?= $f['id'] ?>" <?= (isset($_GET["f$i"]) && (int)$_GET["f$i"] === (int)$f['id'])?'selected':'' ?>>
        <?= htmlspecialchars($f['fund_name'], ENT_QUOTES,'UTF-8') ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endfor; ?>
  <?php if (!empty($selected)): ?><a href="<?= SITE_URL ?>/advisory/fund-compare.php" class="btn-ghost btn-sm" style="align-self:flex-end">Clear</a><?php endif; ?>
</form>

<?php if (empty($selected)): ?>
<div class="portal-card" style="text-align:center;padding:3rem;color:var(--text-secondary)">
  <div style="font-size:2rem;margin-bottom:1rem">◇</div>
  Select up to 3 funds above to compare them side by side.
</div>
<?php else: ?>

<!-- Comparison table -->
<div class="portal-card" style="padding:0;overflow:hidden">
  <div class="table-wrapper" style="border:none">
    <table class="portal-table">
      <thead><tr><th style="min-width:160px">Attribute</th><?php foreach ($selected as $f): ?><th><?= htmlspecialchars($f['fund_name'], ENT_QUOTES,'UTF-8') ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php
        $rows = [
            'Current NAV'    => ['col'=>'current_nav','fmt'=>'nav'],
            'Fund House'     => ['col'=>'fund_house','fmt'=>'text'],
            'Category'       => ['col'=>'category','fmt'=>'text'],
            'Risk Level'     => ['col'=>'risk_level','fmt'=>'badge'],
            'Min Horizon'    => ['col'=>'min_horizon_yrs','fmt'=>'yr'],
            '1yr Return'     => ['col'=>'return_1yr','fmt'=>'pct','best'=>'max'],
            '3yr Return'     => ['col'=>'return_3yr','fmt'=>'pct','best'=>'max'],
            '5yr Return'     => ['col'=>'return_5yr','fmt'=>'pct','best'=>'max'],
            'Expense Ratio'  => ['col'=>'expense_ratio','fmt'=>'pct_low','best'=>'min'],
            'AUM (Cr)'       => ['col'=>'aum_cr','fmt'=>'cr'],
        ];
        foreach ($rows as $label => $cfg):
            $values = array_map(fn($f) => $f[$cfg['col']], $selected);
            $best   = null;
            if (isset($cfg['best']) && !in_array(null, $values)) {
                $numeric = array_filter($values, fn($v) => is_numeric($v));
                if (!empty($numeric)) $best = $cfg['best']==='max' ? max($numeric) : min($numeric);
            }
        ?>
        <tr>
          <td style="font-weight:500;color:var(--cream)"><?= $label ?></td>
          <?php foreach ($selected as $fi => $f):
            $val = $f[$cfg['col']];
            $isBest = $best !== null && is_numeric($val) && (float)$val === (float)$best;
            $style = $isBest ? 'color:var(--bright);font-weight:600' : '';
          ?>
          <td style="<?= $style ?>">
            <?php if ($cfg['fmt']==='badge' && $val): ?>
              <span class="badge <?= $risk_badge[$val]??'badge-muted' ?>"><?= ucfirst(str_replace('_',' ',$val)) ?></span>
            <?php elseif ($cfg['fmt']==='pct' && $val): ?>
              <?= $val ?>%<?= $isBest?' ↑':'' ?>
            <?php elseif ($cfg['fmt']==='pct_low' && $val): ?>
              <?= $val ?>%<?= $isBest?' ↓ (lowest)':'' ?>
            <?php elseif ($cfg['fmt']==='yr' && $val): ?>
              <?= $val ?>yr+
            <?php elseif ($cfg['fmt']==='cr' && $val): ?>
              <?= format_inr((float)$val) ?>Cr
            <?php else: ?>
              <?= $val ? htmlspecialchars((string)$val, ENT_QUOTES,'UTF-8') : '—' ?>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        <tr>
          <td style="font-weight:500;color:var(--cream)">Why Recommended</td>
          <?php foreach ($selected as $f): ?>
          <td style="font-size:0.8rem;color:var(--text-secondary)"><?= $f['why_recommended']?htmlspecialchars(mb_substr($f['why_recommended'],0,120), ENT_QUOTES,'UTF-8').'…':'—' ?></td>
          <?php endforeach; ?>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Returns chart -->
<div class="portal-card" style="margin-top:1.5rem">
  <div class="card-title">Returns Comparison</div>
  <canvas id="compareChart" height="200"></canvas>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var funds = <?= json_encode(array_map(fn($f) => $f['fund_name'], $selected)) ?>;
  var r1    = <?= json_encode(array_map(fn($f) => (float)($f['return_1yr']??0), $selected)) ?>;
  var r3    = <?= json_encode(array_map(fn($f) => (float)($f['return_3yr']??0), $selected)) ?>;
  var r5    = <?= json_encode(array_map(fn($f) => (float)($f['return_5yr']??0), $selected)) ?>;
  var cols  = ['#1B5E2A','#4CAF50','#8DC63F'];
  new Chart(document.getElementById('compareChart'),{
    type:'bar',
    data:{
      labels:['1 Year Return','3 Year Return','5 Year Return'],
      datasets: funds.map(function(name,i){
        return {label:name,data:[r1[i],r3[i],r5[i]],backgroundColor:cols[i],borderRadius:4};
      })
    },
    options:{plugins:{legend:{position:'bottom',labels:{color:'#85a885',font:{family:"'IBM Plex Mono'"},padding:12}}},scales:{y:{ticks:{callback:v=>v+'%',color:'#85a885'},grid:{color:'rgba(46,133,64,0.1)'}},x:{ticks:{color:'#85a885'},grid:{display:false}}}}
  });
});
</script>
<?php endif; ?>

<?php require_once '../includes/portal-footer.php'; ?>

