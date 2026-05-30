<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('admin');

$db = get_db();
$error = '';

// Create default portfolios
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'create_defaults') {
    if (verify_csrf($_POST['csrf_token']??'')) {
        $defaults=[['Conservative Vault','conservative','Capital preservation with steady income.',8.0,'quarterly'],['Balanced Growth Portfolio','moderate','Balanced growth with moderate risk.',11.0,'quarterly'],['Aggressive Wealth Builder','aggressive','Maximum long-term wealth creation.',14.0,'yearly']];
        foreach ($defaults as $d) { $db->prepare("INSERT IGNORE INTO model_portfolios (portfolio_name,risk_profile,description,target_return,rebalance_freq,is_active) VALUES (?,?,?,?,?,1)")->execute($d); }
        $_SESSION['flash']=['type'=>'success','message'=>'Default portfolios created.']; header('Location: '.SITE_URL.'/admin/model-portfolios.php'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save_portfolio') {
    if (verify_csrf($_POST['csrf_token']??'')) {
        $pid=(int)($_POST['portfolio_id']??0);
        $db->prepare("UPDATE model_portfolios SET portfolio_name=:n,description=:d,target_return=:t,rebalance_freq=:r,is_active=:a WHERE id=:id")->execute([':n'=>trim($_POST['portfolio_name']??''),':d'=>trim($_POST['description']??''),':t'=>(float)($_POST['target_return']??0),':r'=>$_POST['rebalance_freq']??'quarterly',':a'=>isset($_POST['is_active'])?1:0,':id'=>$pid]);
        $_SESSION['flash']=['type'=>'success','message'=>'Portfolio updated.']; header('Location: '.SITE_URL.'/admin/model-portfolios.php?edit='.$pid); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'add_holding') {
    if (verify_csrf($_POST['csrf_token']??'')) {
        $pid=(int)($_POST['portfolio_id']??0);
        // Check total allocation
        $current_total=$db->prepare("SELECT COALESCE(SUM(allocation_pct),0) FROM model_portfolio_holdings WHERE portfolio_id=:pid"); $current_total->execute([':pid'=>$pid]); $total=(float)$current_total->fetchColumn();
        $new_pct=(float)($_POST['allocation_pct']??0);
        if ($total+$new_pct>100) { $error='Total allocation would exceed 100%. Current: '.round($total,1).'%'; }
        else { $db->prepare("INSERT INTO model_portfolio_holdings (portfolio_id,instrument_type,instrument_name,ticker_symbol,allocation_pct,rationale) VALUES (:pid,:type,:name,:tk,:pct,:rat)")->execute([':pid'=>$pid,':type'=>$_POST['instrument_type']??'mutual_fund',':name'=>trim($_POST['instrument_name']??''),':tk'=>trim($_POST['ticker_symbol']??'')?:null,':pct'=>$new_pct,':rat'=>trim($_POST['rationale']??'')?:null]); $_SESSION['flash']=['type'=>'success','message'=>'Holding added.']; header('Location: '.SITE_URL.'/admin/model-portfolios.php?edit='.$pid); exit; }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete_holding') {
    if (verify_csrf($_POST['csrf_token']??'')) { $db->prepare("DELETE FROM model_portfolio_holdings WHERE id=:id")->execute([':id'=>(int)($_POST['holding_id']??0)]); header('Location: '.SITE_URL.'/admin/model-portfolios.php?edit='.(int)($_POST['portfolio_id']??0)); exit; }
}

$portfolios=$db->query("SELECT * FROM model_portfolios ORDER BY FIELD(risk_profile,'conservative','moderate','aggressive')")->fetchAll();

$edit_id=(int)($_GET['edit']??0);
$edit_portfolio=null; $holdings=[];
if ($edit_id) {
    $s=$db->prepare("SELECT * FROM model_portfolios WHERE id=:id"); $s->execute([':id'=>$edit_id]); $edit_portfolio=$s->fetch();
    $h=$db->prepare("SELECT * FROM model_portfolio_holdings WHERE portfolio_id=:pid ORDER BY allocation_pct DESC"); $h->execute([':pid'=>$edit_id]); $holdings=$h->fetchAll();
}

$total_alloc=array_sum(array_column($holdings,'allocation_pct'));

$page_title='Model Portfolios — Prime Financials Admin';
require_once '../includes/admin-header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
  <div><p class="page-eyebrow">Advisory Content</p><h1 class="page-title" style="margin-bottom:0">Model Portfolios</h1></div>
  <?php if (empty($portfolios)): ?>
  <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="create_defaults"><button type="submit" class="btn-primary btn-sm">Create Default Portfolios</button></form>
  <?php endif; ?>
</div>

<?php if ($error): ?><div class="flash-error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

<?php if ($edit_portfolio): ?>
<div style="margin-bottom:1rem"><a href="<?= SITE_URL ?>/admin/model-portfolios.php" style="color:var(--text-secondary);font-size:0.875rem;text-decoration:none">← All Portfolios</a></div>
<div class="grid-2" style="align-items:start">
  <div class="portal-card">
    <div class="card-title">Edit Portfolio Details</div>
    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
      <input type="hidden" name="action" value="save_portfolio">
      <input type="hidden" name="portfolio_id" value="<?= $edit_portfolio['id'] ?>">
      <div class="form-group"><label class="form-label">Portfolio Name</label><input class="form-input" type="text" name="portfolio_name" value="<?= htmlspecialchars($edit_portfolio['portfolio_name'],ENT_QUOTES,'UTF-8') ?>"></div>
      <div class="form-group"><label class="form-label">Description</label><textarea class="form-textarea" name="description" rows="3"><?= htmlspecialchars($edit_portfolio['description']??'',ENT_QUOTES,'UTF-8') ?></textarea></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Target Return (% p.a.)</label><input class="form-input" type="number" name="target_return" step="0.5" value="<?= $edit_portfolio['target_return'] ?>"></div>
        <div class="form-group"><label class="form-label">Rebalance Frequency</label><select class="form-select" name="rebalance_freq"><?php foreach (['monthly','quarterly','yearly'] as $rf): ?><option value="<?= $rf ?>" <?= $edit_portfolio['rebalance_freq']===$rf?'selected':'' ?>><?= ucfirst($rf) ?></option><?php endforeach; ?></select></div>
      </div>
      <label class="check-row" style="margin-bottom:1rem"><input type="checkbox" name="is_active" <?= $edit_portfolio['is_active']?'checked':'' ?>> Active</label>
      <button type="submit" class="btn-primary">Save Changes</button>
    </form>
  </div>
  <div>
    <div class="portal-card" style="margin-bottom:1rem">
      <div class="card-title">Holdings (Total: <?= round($total_alloc,1) ?>%)</div>
      <?php foreach ($holdings as $h): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:0.4rem 0;border-bottom:1px solid var(--border-light)">
        <div><div style="font-size:0.875rem;color:var(--cream)"><?= htmlspecialchars($h['instrument_name'],ENT_QUOTES,'UTF-8') ?></div><div style="font-size:0.72rem;color:var(--text-muted)"><?= ucfirst(str_replace('_',' ',$h['instrument_type'])) ?></div></div>
        <div style="display:flex;align-items:center;gap:0.75rem">
          <span style="font-family:'DM Mono',monospace;color:var(--lime)"><?= $h['allocation_pct'] ?>%</span>
          <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="delete_holding"><input type="hidden" name="holding_id" value="<?= $h['id'] ?>"><input type="hidden" name="portfolio_id" value="<?= $edit_id ?>"><button type="submit" class="btn-danger btn-sm" onclick="return confirm('Remove?')">×</button></form>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if ($total_alloc<100): ?>
      <div class="card-title" style="margin:1rem 0 0.75rem">Add Holding</div>
      <form method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
        <input type="hidden" name="action" value="add_holding">
        <input type="hidden" name="portfolio_id" value="<?= $edit_id ?>">
        <div class="form-group"><label class="form-label">Type</label><select class="form-select" name="instrument_type"><option value="mutual_fund">Mutual Fund</option><option value="etf">ETF</option><option value="stock">Stock</option><option value="fd">FD</option><option value="gold">Gold</option><option value="nps">NPS</option></select></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Name *</label><input class="form-input" type="text" name="instrument_name" required placeholder="e.g. Mirae Large Cap"></div>
          <div class="form-group"><label class="form-label">Allocation % * <span style="color:var(--lime)">(Remaining: <?= round(100-$total_alloc,1) ?>%)</span></label><input class="form-input" type="number" name="allocation_pct" step="0.5" min="0.5" max="<?= round(100-$total_alloc,1) ?>" required></div>
        </div>
        <div class="form-group"><label class="form-label">Rationale</label><input class="form-input" type="text" name="rationale" placeholder="Why this instrument?"></div>
        <button type="submit" class="btn-primary btn-sm">Add Holding</button>
      </form>
      <?php else: ?><div class="flash-info" style="margin-top:0.75rem">100% allocated. Remove a holding to add more.</div><?php endif; ?>
    </div>
  </div>
</div>
<?php else: ?>
<div class="grid-3">
  <?php foreach ($portfolios as $p): ?>
  <div class="portal-card">
    <span class="badge <?= ['conservative'=>'badge-green','moderate'=>'badge-gold','aggressive'=>'badge-muted'][$p['risk_profile']] ?>"><?= ucfirst($p['risk_profile']) ?></span>
    <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.25rem;color:var(--cream);margin:0.75rem 0 0.4rem"><?= htmlspecialchars($p['portfolio_name'],ENT_QUOTES,'UTF-8') ?></h3>
    <div style="font-family:'DM Mono',monospace;color:var(--lime);font-size:0.85rem;margin-bottom:1rem"><?= $p['target_return'] ?>% target · <?= ucfirst($p['rebalance_freq']) ?></div>
    <a href="?edit=<?= $p['id'] ?>" class="btn-outline btn-sm">Edit Holdings →</a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once '../includes/admin-footer.php'; ?>
