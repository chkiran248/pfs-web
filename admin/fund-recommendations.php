<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('admin');

$db = get_db();
$error = '';

// Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save_fund') {
    if (!verify_csrf($_POST['csrf_token']??'')) { $error = 'Invalid request.'; }
    else {
        $fid = (int)($_POST['fund_id']??0);
        $goal_types = implode(',', array_filter($_POST['goal_types']??[]));
        $d = [
            ':fn'=>trim($_POST['fund_name']??''), ':fh'=>trim($_POST['fund_house']??''),
            ':cat'=>trim($_POST['category']??''), ':sub'=>trim($_POST['sub_category']??'')?:null,
            ':risk'=>$_POST['risk_level']??'moderate', ':hor'=>(int)($_POST['min_horizon_yrs']??1),
            ':goals'=>$goal_types, ':why'=>trim($_POST['why_recommended']??'')?:null,
            ':feat'=>trim($_POST['key_features']??'')?:null,
            ':exp'=>(float)($_POST['expense_ratio']??0)?:null, ':aum'=>(float)($_POST['aum_cr']??0)?:null,
            ':r1'=>(float)($_POST['return_1yr']??0)?:null, ':r3'=>(float)($_POST['return_3yr']??0)?:null, ':r5'=>(float)($_POST['return_5yr']??0)?:null,
            ':featured'=>isset($_POST['is_featured'])?1:0, ':active'=>isset($_POST['is_active'])?1:0,
        ];
        try {
            if ($fid) {
                $d[':id']=$fid;
                $db->prepare("UPDATE fund_recommendations SET fund_name=:fn,fund_house=:fh,category=:cat,sub_category=:sub,risk_level=:risk,min_horizon_yrs=:hor,goal_types=:goals,why_recommended=:why,key_features=:feat,expense_ratio=:exp,aum_cr=:aum,return_1yr=:r1,return_3yr=:r3,return_5yr=:r5,is_featured=:featured,is_active=:active,updated_at=NOW() WHERE id=:id")->execute($d);
            } else {
                $db->prepare("INSERT INTO fund_recommendations (fund_name,fund_house,category,sub_category,risk_level,min_horizon_yrs,goal_types,why_recommended,key_features,expense_ratio,aum_cr,return_1yr,return_3yr,return_5yr,is_featured,is_active) VALUES (:fn,:fh,:cat,:sub,:risk,:hor,:goals,:why,:feat,:exp,:aum,:r1,:r3,:r5,:featured,:active)")->execute($d);
            }
            $_SESSION['flash'] = ['type'=>'success','message'=>'Fund recommendation saved.']; header('Location: '.SITE_URL.'/admin/fund-recommendations.php'); exit;
        } catch(PDOException $e){ error_log($e->getMessage()); $error='Could not save.'; }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'toggle_active') {
    if (verify_csrf($_POST['csrf_token']??'')) { $db->prepare("UPDATE fund_recommendations SET is_active=1-is_active WHERE id=:id")->execute([':id'=>(int)($_POST['fund_id']??0)]); header('Location: '.SITE_URL.'/admin/fund-recommendations.php'); exit; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete_fund') {
    if (verify_csrf($_POST['csrf_token']??'')) { $db->prepare("DELETE FROM fund_recommendations WHERE id=:id")->execute([':id'=>(int)($_POST['fund_id']??0)]); $_SESSION['flash']=['type'=>'success','message'=>'Deleted.']; header('Location: '.SITE_URL.'/admin/fund-recommendations.php'); exit; }
}

$edit = null;
if (isset($_GET['edit'])) { $stmt=$db->prepare("SELECT * FROM fund_recommendations WHERE id=:id"); $stmt->execute([':id'=>(int)$_GET['edit']]); $edit=$stmt->fetch(); }
$is_new = isset($_GET['new']) || isset($_GET['edit']);

$funds = $db->query("SELECT * FROM fund_recommendations ORDER BY is_featured DESC, fund_name")->fetchAll();
$goal_opts = ['retirement','education','wealth','tax_saving','emergency','custom'];
$risk_opts  = ['low','moderate','high','very_high'];
$risk_badge = ['low'=>'badge-green','moderate'=>'badge-gold','high'=>'badge-gold','very_high'=>'badge-muted'];

$page_title = 'Fund Recommendations — Prime Financials Admin';
require_once '../includes/admin-header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
  <div><p class="page-eyebrow">Advisory Content</p><h1 class="page-title" style="margin-bottom:0">Fund Recommendations</h1></div>
  <a href="?new=1" class="btn-primary btn-sm">+ Add Fund</a>
</div>

<?php if ($error): ?><div class="flash-error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

<!-- Add/Edit form -->
<?php if ($is_new): ?>
<div class="portal-card" style="margin-bottom:1.5rem">
  <div class="card-title"><?= $edit ? 'Edit Fund' : 'Add New Fund Recommendation' ?></div>
  <form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
    <input type="hidden" name="action" value="save_fund">
    <?php if ($edit): ?><input type="hidden" name="fund_id" value="<?= $edit['id'] ?>"><?php endif; ?>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Fund Name *</label><input class="form-input" type="text" name="fund_name" value="<?= htmlspecialchars($edit['fund_name']??'',ENT_QUOTES,'UTF-8') ?>" required></div>
      <div class="form-group"><label class="form-label">Fund House *</label><input class="form-input" type="text" name="fund_house" value="<?= htmlspecialchars($edit['fund_house']??'',ENT_QUOTES,'UTF-8') ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Category *</label><input class="form-input" type="text" name="category" value="<?= htmlspecialchars($edit['category']??'',ENT_QUOTES,'UTF-8') ?>" placeholder="e.g. Large Cap Equity"></div>
      <div class="form-group"><label class="form-label">Sub Category</label><input class="form-input" type="text" name="sub_category" value="<?= htmlspecialchars($edit['sub_category']??'',ENT_QUOTES,'UTF-8') ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Risk Level</label><select class="form-select" name="risk_level"><?php foreach ($risk_opts as $r): ?><option value="<?= $r ?>" <?= ($edit['risk_level']??'')===$r?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$r)) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label">Min Horizon (years)</label><input class="form-input" type="number" name="min_horizon_yrs" value="<?= $edit['min_horizon_yrs']??1 ?>" min="1"></div>
    </div>
    <div class="form-group"><label class="form-label">Goal Types (select all that apply)</label><div style="display:flex;flex-wrap:wrap;gap:0.75rem;margin-top:0.4rem"><?php foreach ($goal_opts as $g): $checked=($edit&&str_contains($edit['goal_types']??'',$g))||(!$edit); ?><label class="check-row"><input type="checkbox" name="goal_types[]" value="<?= $g ?>" <?= $checked?'checked':'' ?>><?= ucfirst(str_replace('_',' ',$g)) ?></label><?php endforeach; ?></div></div>
    <div class="form-group"><label class="form-label">Why Recommended</label><textarea class="form-textarea" name="why_recommended" rows="3"><?= htmlspecialchars($edit['why_recommended']??'',ENT_QUOTES,'UTF-8') ?></textarea></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">1yr Return (%)</label><input class="form-input" type="number" name="return_1yr" step="0.01" value="<?= $edit['return_1yr']??'' ?>"></div>
      <div class="form-group"><label class="form-label">3yr Return (%)</label><input class="form-input" type="number" name="return_3yr" step="0.01" value="<?= $edit['return_3yr']??'' ?>"></div>
      <div class="form-group"><label class="form-label">5yr Return (%)</label><input class="form-input" type="number" name="return_5yr" step="0.01" value="<?= $edit['return_5yr']??'' ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Expense Ratio (%)</label><input class="form-input" type="number" name="expense_ratio" step="0.001" value="<?= $edit['expense_ratio']??'' ?>"></div>
      <div class="form-group"><label class="form-label">AUM (₹ Cr)</label><input class="form-input" type="number" name="aum_cr" value="<?= $edit['aum_cr']??'' ?>"></div>
    </div>
    <div style="display:flex;gap:1.5rem;margin-bottom:1rem">
      <label class="check-row"><input type="checkbox" name="is_featured" <?= ($edit['is_featured']??0)?'checked':'' ?>> ★ Featured</label>
      <label class="check-row"><input type="checkbox" name="is_active" <?= ($edit?($edit['is_active']??1):1)?'checked':'' ?>> Active</label>
    </div>
    <div style="display:flex;gap:0.75rem">
      <button type="submit" class="btn-primary">Save Fund</button>
      <a href="<?= SITE_URL ?>/admin/fund-recommendations.php" class="btn-ghost">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- List -->
<div class="portal-card" style="padding:0">
  <div class="table-wrapper" style="border:none;border-radius:12px">
    <table class="portal-table">
      <thead><tr><th>Fund</th><th>Category</th><th>Risk</th><th>1yr</th><th>3yr</th><th>5yr</th><th>Featured</th><th>Active</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($funds)): ?><tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--text-secondary)">No fund recommendations yet. Add one above.</td></tr>
        <?php else: ?>
        <?php foreach ($funds as $f): ?>
        <tr>
          <td><div style="font-weight:500;color:var(--cream)"><?= htmlspecialchars($f['fund_name'],ENT_QUOTES,'UTF-8') ?></div><div style="font-size:0.75rem;color:var(--text-secondary)"><?= htmlspecialchars($f['fund_house'],ENT_QUOTES,'UTF-8') ?></div></td>
          <td style="font-size:0.82rem"><?= htmlspecialchars($f['category'],ENT_QUOTES,'UTF-8') ?></td>
          <td><span class="badge <?= $risk_badge[$f['risk_level']]??'badge-muted' ?>"><?= ucfirst(str_replace('_',' ',$f['risk_level'])) ?></span></td>
          <td style="font-family:'DM Mono',monospace;font-size:0.82rem"><?= $f['return_1yr']?$f['return_1yr'].'%':'—' ?></td>
          <td style="font-family:'DM Mono',monospace;font-size:0.82rem"><?= $f['return_3yr']?$f['return_3yr'].'%':'—' ?></td>
          <td style="font-family:'DM Mono',monospace;font-size:0.82rem"><?= $f['return_5yr']?$f['return_5yr'].'%':'—' ?></td>
          <td><?= $f['is_featured']?'★':'' ?></td>
          <td><span class="badge <?= $f['is_active']?'badge-green':'badge-muted' ?>"><?= $f['is_active']?'Active':'Inactive' ?></span></td>
          <td>
            <div style="display:flex;gap:0.4rem">
              <a href="?edit=<?= $f['id'] ?>" class="btn-ghost btn-sm">Edit</a>
              <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="fund_id" value="<?= $f['id'] ?>"><button type="submit" class="btn-outline btn-sm"><?= $f['is_active']?'Deactivate':'Activate' ?></button></form>
              <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="delete_fund"><input type="hidden" name="fund_id" value="<?= $f['id'] ?>"><button type="submit" class="btn-danger btn-sm" onclick="return confirm('Delete?')">Del</button></form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once '../includes/admin-footer.php'; ?>
