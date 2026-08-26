<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('admin');

$db    = get_db();
$error = '';

// Generate coupon
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'generate') {
    if (!verify_csrf($_POST['csrf_token']??'')) { $error='Invalid request.'; }
    else {
        $code        = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_POST['code']??''));
        $description = substr(trim($_POST['description']??''), 0, 200);
        $plan_code   = in_array($_POST['plan_code']??'',['active_investor','premium']) ? $_POST['plan_code'] : 'active_investor';
        $max_uses    = max(0, (int)($_POST['max_uses']??0));
        $valid_until = !empty($_POST['valid_until']) ? date('Y-m-d 23:59:59', strtotime($_POST['valid_until'])) : null;

        if (strlen($code) < 4) { $error = 'Code must be at least 4 characters.'; }
        else {
            try {
                $db->prepare("INSERT INTO coupon_codes (code,description,plan_code,max_uses,valid_until,created_by) VALUES (:c,:d,:p,:m,:v,:by)")
                   ->execute([':c'=>$code,':d'=>$description,':p'=>$plan_code,':m'=>$max_uses,':v'=>$valid_until,':by'=>get_user_id()]);
                $_SESSION['flash'] = ['type'=>'success','message'=>"Coupon {$code} created!"]; header('Location: '.SITE_URL.'/admin/coupons.php'); exit;
            } catch (PDOException $e) {
                $error = str_contains($e->getMessage(),'Duplicate') ? 'Code already exists. Try a different one.' : 'Failed to create coupon.';
            }
        }
    }
}

// Toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'toggle') {
    if (verify_csrf($_POST['csrf_token']??'')) { $db->prepare("UPDATE coupon_codes SET is_active=1-is_active WHERE id=:id")->execute([':id'=>(int)($_POST['cid']??0)]); header('Location: '.SITE_URL.'/admin/coupons.php'); exit; }
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete') {
    if (verify_csrf($_POST['csrf_token']??'')) { $db->prepare("DELETE FROM coupon_codes WHERE id=:id")->execute([':id'=>(int)($_POST['cid']??0)]); $_SESSION['flash']=['type'=>'success','message'=>'Coupon deleted.']; header('Location: '.SITE_URL.'/admin/coupons.php'); exit; }
}

$coupons = $db->query("SELECT c.*, (SELECT COUNT(*) FROM coupon_usage u WHERE u.coupon_id=c.id) as uses FROM coupon_codes c ORDER BY c.created_at DESC")->fetchAll();
$usage_log = $db->query("SELECT cu.used_at, cc.code, u.full_name, u.email FROM coupon_usage cu JOIN coupon_codes cc ON cc.id=cu.coupon_id JOIN users u ON u.id=cu.user_id ORDER BY cu.used_at DESC LIMIT 30")->fetchAll();

$page_title = 'Coupon Codes — Prime Financials Admin';
require_once '../includes/admin-header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
  <div><p class="page-eyebrow">Admin</p><h1 class="page-title" style="margin-bottom:0">Coupon Codes</h1></div>
</div>

<?php if ($error): ?><div class="flash-error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

<!-- Generate form -->
<div class="portal-card" style="margin-bottom:1.5rem">
  <div class="card-title">Generate New Coupon</div>
  <form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
    <input type="hidden" name="action" value="generate">
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Coupon Code *</label>
        <div style="display:flex;gap:0.5rem">
          <input class="form-input" type="text" name="code" id="couponCode" required maxlength="30" placeholder="e.g. DIWALI2026" style="text-transform:uppercase;flex:1">
          <button type="button" onclick="generateCode()" class="btn-ghost btn-sm" style="flex-shrink:0">Auto-Generate</button>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <input class="form-input" type="text" name="description" placeholder="e.g. Diwali promo — active investors">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Plan</label>
        <select class="form-select" name="plan_code">
          <option value="active_investor">Active Investor (all premium, free)</option>
          <option value="premium">Prime Member</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Max Uses <span style="color:var(--text-muted)">(0 = unlimited)</span></label>
        <input class="form-input" type="number" name="max_uses" value="0" min="0">
      </div>
    </div>
    <div class="form-group" style="max-width:220px">
      <label class="form-label">Valid Until <span style="color:var(--text-muted)">(blank = no expiry)</span></label>
      <input class="form-input" type="date" name="valid_until">
    </div>
    <button type="submit" class="btn-primary">🎟 Generate Coupon</button>
  </form>
</div>

<!-- Active coupons -->
<div class="portal-card" style="padding:0;margin-bottom:1.5rem">
  <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--border)"><div class="card-title" style="margin-bottom:0">All Coupons (<?= count($coupons) ?>)</div></div>
  <div class="table-wrapper" style="border:none;border-radius:0">
    <table class="portal-table">
      <thead><tr><th>Code</th><th>Description</th><th>Plan</th><th>Uses</th><th>Valid Until</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($coupons)): ?><tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-secondary)">No coupons yet.</td></tr>
        <?php else: foreach ($coupons as $c): ?>
        <tr>
          <td>
            <span style="font-family:'IBM Plex Mono',monospace;font-weight:600;color:var(--lime)"><?= htmlspecialchars($c['code'],ENT_QUOTES,'UTF-8') ?></span>
            <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($c['code'],ENT_QUOTES,'UTF-8') ?>').then(()=>alert('Copied!')).catch(()=>{})"
              style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:0.85rem;margin-left:0.4rem" title="Copy">📋</button>
          </td>
          <td style="font-size:0.82rem;color:var(--text-secondary)"><?= htmlspecialchars($c['description']??'',ENT_QUOTES,'UTF-8') ?></td>
          <td><span class="badge <?= $c['plan_code']==='premium'?'badge-gold':'badge-green' ?>"><?= ucfirst(str_replace('_',' ',$c['plan_code'])) ?></span></td>
          <td style="font-family:'IBM Plex Mono',monospace;font-size:0.82rem"><?= $c['uses'] ?><?= $c['max_uses']>0?' / '.$c['max_uses']:' / ∞' ?></td>
          <td style="font-size:0.78rem;color:var(--text-muted)"><?= $c['valid_until']?date('d M Y',strtotime($c['valid_until'])):'No expiry' ?></td>
          <td><span class="badge <?= $c['is_active']?'badge-green':'badge-muted' ?>"><?= $c['is_active']?'Active':'Inactive' ?></span></td>
          <td>
            <div style="display:flex;gap:0.4rem">
              <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="cid" value="<?= $c['id'] ?>"><button type="submit" class="btn-outline btn-sm"><?= $c['is_active']?'Deactivate':'Activate' ?></button></form>
              <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="cid" value="<?= $c['id'] ?>"><button type="submit" class="btn-danger btn-sm" onclick="return confirm('Delete coupon <?= $c['code'] ?>?')">Del</button></form>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Usage log -->
<?php if (!empty($usage_log)): ?>
<div class="portal-card" style="padding:0">
  <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--border)"><div class="card-title" style="margin-bottom:0">Recent Redemptions</div></div>
  <div class="table-wrapper" style="border:none;border-radius:0">
    <table class="portal-table">
      <thead><tr><th>Coupon</th><th>User</th><th>Email</th><th>Redeemed</th></tr></thead>
      <tbody>
        <?php foreach ($usage_log as $u): ?>
        <tr>
          <td><span style="font-family:'IBM Plex Mono',monospace;color:var(--lime)"><?= htmlspecialchars($u['code'],ENT_QUOTES,'UTF-8') ?></span></td>
          <td><?= htmlspecialchars($u['full_name'],ENT_QUOTES,'UTF-8') ?></td>
          <td style="font-size:0.8rem;color:var(--text-secondary)"><?= htmlspecialchars($u['email'],ENT_QUOTES,'UTF-8') ?></td>
          <td style="font-size:0.78rem;color:var(--text-muted)"><?= date('d M Y, g:i a',strtotime($u['used_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
function generateCode() {
  const words = ['PRIME','INVEST','WEALTH','GROW','SMART','WIN','RISE','GOLD'];
  const nums  = Math.floor(Math.random()*9000+1000);
  const word  = words[Math.floor(Math.random()*words.length)];
  document.getElementById('couponCode').value = word + nums;
}
</script>

<?php require_once '../includes/admin-footer.php'; ?>
