<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('admin');

$db = get_db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add_lead') {
            $db->prepare("INSERT INTO leads (full_name,email,phone,source_tool,interest,notes) VALUES (:n,:e,:p,:s,:i,:notes)")
               ->execute([':n'=>trim($_POST['full_name']??''),':e'=>trim($_POST['email']??''),':p'=>trim($_POST['phone']??''),':s'=>trim($_POST['source_tool']??'Manual'),':i'=>trim($_POST['interest']??''),':notes'=>trim($_POST['notes']??'')]);
            $_SESSION['flash'] = ['type'=>'success','message'=>'Lead added.'];
        } elseif ($action === 'update_lead') {
            $lid = (int)($_POST['lead_id']??0);
            $db->prepare("UPDATE leads SET status=:s,notes=:n,updated_at=NOW() WHERE id=:id")
               ->execute([':s'=>$_POST['status']??'new',':n'=>trim($_POST['notes']??''),':id'=>$lid]);
            $_SESSION['flash'] = ['type'=>'success','message'=>'Lead updated.'];
        } elseif ($action === 'delete_lead') {
            $db->prepare("DELETE FROM leads WHERE id=:id")->execute([':id'=>(int)($_POST['lead_id']??0)]);
            $_SESSION['flash'] = ['type'=>'success','message'=>'Lead deleted.'];
        }
        header('Location: ' . SITE_URL . '/admin/leads.php'); exit;
    }
}

$leads = $db->query("SELECT * FROM leads ORDER BY created_at DESC")->fetchAll();

$counts = ['new'=>0,'contacted'=>0,'converted'=>0,'lost'=>0];
foreach ($leads as $l) { if (isset($counts[$l['status']])) $counts[$l['status']]++; }

$sbadge = ['new'=>'badge-green','contacted'=>'badge-gold','converted'=>'badge-green','lost'=>'badge-muted'];

$page_title = 'Leads — Prime Financials Admin';
require_once '../includes/admin-header.php';
?>

<p class="page-eyebrow">Admin</p>
<h1 class="page-title">Leads Management</h1>

<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.5rem">
  <?php foreach ($counts as $status=>$count): ?>
  <div class="stat-box"><div class="stat-label"><?= ucfirst($status) ?></div><div class="stat-value neutral"><?= $count ?></div></div>
  <?php endforeach; ?>
</div>

<!-- Add lead -->
<div class="portal-card" style="margin-bottom:1.5rem">
  <div style="display:flex;justify-content:space-between;align-items:center;cursor:pointer" onclick="toggleForm('lform','licon')">
    <div class="card-title" style="margin-bottom:0">+ Add Lead Manually</div>
    <span id="licon" style="color:var(--lime);font-size:1.25rem">+</span>
  </div>
  <div id="lform" style="display:none;margin-top:1.25rem">
    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
      <input type="hidden" name="action" value="add_lead">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Full Name *</label><input class="form-input" type="text" name="full_name" required></div>
        <div class="form-group"><label class="form-label">Email</label><input class="form-input" type="email" name="email"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Phone</label><input class="form-input" type="tel" name="phone"></div>
        <div class="form-group"><label class="form-label">Interest</label><input class="form-input" type="text" name="interest" placeholder="e.g. Mutual Funds SIP"></div>
      </div>
      <div class="form-group"><label class="form-label">Notes</label><textarea class="form-textarea" name="notes" rows="2"></textarea></div>
      <input type="hidden" name="source_tool" value="Manual — Admin">
      <button type="submit" class="btn-primary btn-sm">Add Lead</button>
    </form>
  </div>
</div>

<?php if ($error): ?><div class="flash-error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

<div class="portal-card" style="padding:0">
  <div class="table-wrapper" style="border:none;border-radius:12px">
    <table class="portal-table">
      <thead><tr><th>Lead</th><th>Phone</th><th>Interest</th><th>Source</th><th>Status</th><th>Date</th><th>Update</th></tr></thead>
      <tbody>
        <?php if (empty($leads)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-secondary);padding:2rem">No leads yet.</td></tr>
        <?php else: ?>
        <?php foreach ($leads as $l): ?>
        <tr>
          <td>
            <div style="font-weight:500;color:var(--cream)"><?= htmlspecialchars($l['full_name']??'',ENT_QUOTES,'UTF-8') ?></div>
            <div style="font-size:0.75rem;color:var(--text-secondary)"><?= htmlspecialchars($l['email']??'',ENT_QUOTES,'UTF-8') ?></div>
          </td>
          <td style="font-size:0.82rem"><?= htmlspecialchars($l['phone']??'',ENT_QUOTES,'UTF-8') ?></td>
          <td style="font-size:0.82rem;color:var(--text-secondary)"><?= htmlspecialchars($l['interest']??'',ENT_QUOTES,'UTF-8') ?></td>
          <td style="font-size:0.75rem;color:var(--text-muted)"><?= htmlspecialchars($l['source_tool']??'',ENT_QUOTES,'UTF-8') ?></td>
          <td><span class="badge <?= $sbadge[$l['status']]??'badge-muted' ?>"><?= ucfirst($l['status']) ?></span></td>
          <td style="font-size:0.75rem;color:var(--text-muted)"><?= date('d M Y',strtotime($l['created_at'])) ?></td>
          <td>
            <form method="POST" style="display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
              <input type="hidden" name="action" value="update_lead">
              <input type="hidden" name="lead_id" value="<?= $l['id'] ?>">
              <select class="form-select" name="status" style="font-size:0.78rem;padding:0.3rem 0.5rem;width:110px">
                <?php foreach (['new','contacted','converted','lost'] as $s): ?><option value="<?= $s ?>" <?= $l['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
              </select>
              <button type="submit" class="btn-ghost btn-sm">Save</button>
              <button type="submit" form="" onclick="this.form.querySelector('[name=action]').value='delete_lead';return confirm('Delete this lead?')" class="btn-danger btn-sm">Del</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>function toggleForm(id,icon){var f=document.getElementById(id),i=document.getElementById(icon),o=f.style.display!=='none';f.style.display=o?'none':'block';i.textContent=o?'+':'−';}</script>

<?php require_once '../includes/admin-footer.php'; ?>
