<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$db  = get_db();
$uid = get_user_id();
$error = '';

// Add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_fund') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $fn = trim($_POST['fund_name'] ?? '');
        if (!$fn) { $error = 'Fund name is required.'; }
        else {
            try {
                $db->prepare("INSERT INTO fund_watchlist (user_id, fund_name, fund_house, current_nav, alert_nav_above, alert_nav_below, user_note) VALUES (:uid,:fn,:fh,:nav,:above,:below,:note)")
                   ->execute([':uid'=>$uid,':fn'=>$fn,':fh'=>trim($_POST['fund_house']??'')?:null,':nav'=>(float)($_POST['current_nav']??0)?:null,':above'=>(float)($_POST['alert_above']??0)?:null,':below'=>(float)($_POST['alert_below']??0)?:null,':note'=>trim($_POST['user_note']??'')?:null]);
                $_SESSION['flash'] = ['type'=>'success','message'=>'Fund added to watchlist.'];
                header('Location: ' . SITE_URL . '/portal/fund-watchlist.php'); exit;
            } catch (PDOException $e) { error_log($e->getMessage()); $error = 'Could not add fund.'; }
        }
    }
}

// Remove
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_fund') {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $db->prepare("DELETE FROM fund_watchlist WHERE id = :id AND user_id = :uid")->execute([':id'=>(int)($_POST['wid']??0),':uid'=>$uid]);
        $_SESSION['flash'] = ['type'=>'success','message'=>'Removed from watchlist.'];
        header('Location: ' . SITE_URL . '/portal/fund-watchlist.php'); exit;
    }
}

$stmt = $db->prepare("SELECT * FROM fund_watchlist WHERE user_id = :uid ORDER BY added_at DESC");
$stmt->execute([':uid' => $uid]);
$watchlist = $stmt->fetchAll();

$page_title = 'Fund Watchlist — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Watchlists</p>
<h1 class="page-title">Fund Watchlist</h1>

<div class="disclaimer disclaimer--mf" style="margin-bottom:1.25rem">MF investments subject to market risks. NAV data shown is manually entered and may not reflect live prices.</div>

<?php if ($error): ?><div class="flash-error"><?= htmlspecialchars($error, ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

<!-- Add form -->
<div class="portal-card" style="margin-bottom:1.5rem">
  <div style="display:flex;justify-content:space-between;align-items:center;cursor:pointer" onclick="toggleForm('wform','wicon')">
    <div class="card-title" style="margin-bottom:0">+ Add Fund to Watchlist</div>
    <span id="wicon" style="color:var(--lime);font-size:1.25rem">+</span>
  </div>
  <div id="wform" style="display:none;margin-top:1.25rem">
    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES,'UTF-8') ?>">
      <input type="hidden" name="action" value="add_fund">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Fund Name *</label><input class="form-input" type="text" name="fund_name" required placeholder="e.g. Mirae Asset Large Cap Fund"></div>
        <div class="form-group"><label class="form-label">Fund House</label><input class="form-input" type="text" name="fund_house" placeholder="e.g. Mirae Asset"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Current NAV (₹)</label><input class="form-input" type="number" name="current_nav" step="0.01" placeholder="78.50"></div>
        <div class="form-group"><label class="form-label">Alert when NAV rises above (₹)</label><input class="form-input" type="number" name="alert_above" step="0.01" placeholder="Optional"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Alert when NAV falls below (₹)</label><input class="form-input" type="number" name="alert_below" step="0.01" placeholder="Optional"></div>
        <div class="form-group"><label class="form-label">Your Note</label><input class="form-input" type="text" name="user_note" placeholder="Why you're tracking this fund"></div>
      </div>
      <button type="submit" class="btn-primary btn-sm">Add to Watchlist</button>
    </form>
  </div>
</div>

<!-- Watchlist table -->
<?php if (empty($watchlist)): ?>
<div class="portal-card" style="text-align:center;padding:3rem;color:var(--text-secondary)">
  <div style="font-size:2rem;margin-bottom:1rem">★</div>
  Your fund watchlist is empty. Add funds you want to track above, or from the <a href="<?= SITE_URL ?>/advisory/mutual-funds.php" class="auth-link">Mutual Funds page</a>.
</div>
<?php else: ?>
<div class="portal-card" style="padding:0">
  <div class="table-wrapper" style="border:none;border-radius:12px">
    <table class="portal-table">
      <thead><tr><th>Fund</th><th>Current NAV</th><th>Alert ↑</th><th>Alert ↓</th><th>Advisor Note</th><th>Your Note</th><th>Added</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($watchlist as $w): ?>
        <tr>
          <td>
            <div style="font-weight:500;color:var(--cream)"><?= htmlspecialchars($w['fund_name'], ENT_QUOTES,'UTF-8') ?></div>
            <?php if ($w['fund_house']): ?><div style="font-size:0.75rem;color:var(--text-secondary)"><?= htmlspecialchars($w['fund_house'], ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
          </td>
          <td style="font-family:'DM Mono',monospace"><?= $w['current_nav']?'₹'.number_format((float)$w['current_nav'],2):'—' ?></td>
          <td style="color:var(--bright)"><?= $w['alert_nav_above']?'₹'.number_format((float)$w['alert_nav_above'],2):'—' ?></td>
          <td style="color:var(--danger)"><?= $w['alert_nav_below']?'₹'.number_format((float)$w['alert_nav_below'],2):'—' ?></td>
          <td style="font-size:0.8rem;color:var(--text-secondary);font-style:italic"><?= $w['advisor_note']?htmlspecialchars(mb_substr($w['advisor_note'],0,60), ENT_QUOTES,'UTF-8').'…':'—' ?></td>
          <td style="font-size:0.8rem;color:var(--text-secondary)"><?= $w['user_note']?htmlspecialchars($w['user_note'], ENT_QUOTES,'UTF-8'):'—' ?></td>
          <td style="font-size:0.75rem;color:var(--text-muted)"><?= date('d M Y', strtotime($w['added_at'])) ?></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES,'UTF-8') ?>">
              <input type="hidden" name="action" value="remove_fund">
              <input type="hidden" name="wid" value="<?= $w['id'] ?>">
              <button type="submit" class="btn-danger btn-sm" onclick="return confirm('Remove this fund?')">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
function toggleForm(id,icon){var f=document.getElementById(id),i=document.getElementById(icon),o=f.style.display!=='none';f.style.display=o?'none':'block';i.textContent=o?'+':'−';}
</script>

<?php require_once '../includes/portal-footer.php'; ?>
