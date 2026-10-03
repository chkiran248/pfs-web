<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/subscription.php';
require_login();
require_role('client');
require_premium('fund_watchlist');

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

<div class="disclaimer disclaimer--mf" style="margin-bottom:1.25rem">MF investments subject to market risks. NAV data is auto-updated daily from AMFI. Alerts are checked each morning.</div>

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
      <thead><tr><th>Fund</th><th>Current NAV</th><th>1Y Return</th><th>3Y Return</th><th>Expense Ratio</th><th>Alert ↑</th><th>Alert ↓</th><th>Advisor Note</th><th>Your Note</th><th>Added</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($watchlist as $w): ?>
        <tr data-wid="<?= $w['id'] ?>" data-fund-name="<?= htmlspecialchars($w['fund_name'], ENT_QUOTES,'UTF-8') ?>" data-fund-house="<?= htmlspecialchars($w['fund_house']??'', ENT_QUOTES,'UTF-8') ?>">
          <td>
            <div style="font-weight:500;color:var(--cream)"><?= htmlspecialchars($w['fund_name'], ENT_QUOTES,'UTF-8') ?></div>
            <?php if ($w['fund_house']): ?><div style="font-size:0.75rem;color:var(--text-secondary)"><?= htmlspecialchars($w['fund_house'], ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
          </td>
          <td style="font-family:'IBM Plex Mono',monospace"><?= $w['current_nav']?'₹'.number_format((float)$w['current_nav'],2):'—' ?></td>
          <td data-fund-row="<?= $w['id'] ?>" style="font-family:'DM Mono',monospace;font-size:0.82rem"><span class="fund-ret-1y-<?= $w['id'] ?>">—</span></td>
          <td data-fund-row="<?= $w['id'] ?>" style="font-family:'DM Mono',monospace;font-size:0.82rem"><span class="fund-ret-3y-<?= $w['id'] ?>">—</span></td>
          <td data-fund-row="<?= $w['id'] ?>" style="font-family:'DM Mono',monospace;font-size:0.82rem"><span class="fund-exp-<?= $w['id'] ?>">—</span></td>
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

document.addEventListener('DOMContentLoaded', function () {
    var apiBase = '<?= rtrim(SITE_URL, '/') ?>/api/fund-returns.php';
    var rows = document.querySelectorAll('tr[data-wid]');

    function fmtReturn(v) {
        if (v === null || v === undefined || v === '') return '—';
        var n = parseFloat(v);
        if (isNaN(n)) return '—';
        var col = n >= 0 ? '#4CAF50' : '#ef5350';
        return '<span style="color:' + col + '">' + (n >= 0 ? '+' : '') + n.toFixed(1) + '%</span>';
    }

    function fmtExp(v) {
        if (v === null || v === undefined || v === '') return '—';
        var n = parseFloat(v);
        if (isNaN(n)) return '—';
        return '<span style="color:var(--text-muted)">' + n.toFixed(2) + '%</span>';
    }

    rows.forEach(function(row, idx) {
        setTimeout(function () {
            var wid   = row.dataset.wid;
            var name  = encodeURIComponent(row.dataset.fundName  || '');
            var house = encodeURIComponent(row.dataset.fundHouse || '');
            fetch(apiBase + '?name=' + name + '&house=' + house)
                .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
                .then(function (d) {
                    if (d.error) return;
                    var s1 = document.querySelector('.fund-ret-1y-' + wid);
                    var s3 = document.querySelector('.fund-ret-3y-' + wid);
                    var se = document.querySelector('.fund-exp-'    + wid);
                    if (s1) s1.innerHTML = fmtReturn(d.return_1yr);
                    if (s3) s3.innerHTML = fmtReturn(d.return_3yr);
                    if (se) se.innerHTML = fmtExp(d.expense_ratio);
                })
                .catch(function () { /* leave — on error */ });
        }, idx * 300);
    });
});
</script>

<?php require_once '../includes/portal-footer.php'; ?>
