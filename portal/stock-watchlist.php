<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/subscription.php';
require_login();
require_role('client');
require_premium('stock_watchlist');

$db  = get_db();
$uid = get_user_id();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_stock') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $co = trim($_POST['company_name'] ?? '');
        $tk = strtoupper(trim($_POST['ticker_symbol'] ?? ''));
        if (!$co || !$tk) { $error = 'Company name and ticker are required.'; }
        else {
            try {
                $db->prepare("INSERT INTO stock_watchlist (user_id, company_name, ticker_symbol, exchange, added_price, target_price, stop_loss, client_notes) VALUES (:uid,:co,:tk,:ex,:price,:target,:stop,:notes)")
                   ->execute([':uid'=>$uid,':co'=>$co,':tk'=>$tk,':ex'=>$_POST['exchange']??'NSE',':price'=>(float)($_POST['added_price']??0)?:null,':target'=>(float)($_POST['target_price']??0)?:null,':stop'=>(float)($_POST['stop_loss']??0)?:null,':notes'=>trim($_POST['client_notes']??'')?:null]);
                $_SESSION['flash'] = ['type'=>'success','message'=>'Stock added to watchlist.'];
                header('Location: ' . SITE_URL . '/portal/stock-watchlist.php'); exit;
            } catch (PDOException $e) { error_log($e->getMessage()); $error = 'Could not add stock.'; }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_stock') {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $db->prepare("DELETE FROM stock_watchlist WHERE id = :id AND user_id = :uid")->execute([':id'=>(int)($_POST['wid']??0),':uid'=>$uid]);
        $_SESSION['flash'] = ['type'=>'success','message'=>'Removed from watchlist.'];
        header('Location: ' . SITE_URL . '/portal/stock-watchlist.php'); exit;
    }
}

$stmt = $db->prepare("SELECT sw.*, sr.report_title FROM stock_watchlist sw LEFT JOIN stock_research sr ON sr.id = sw.research_id WHERE sw.user_id = :uid ORDER BY sw.added_at DESC");
$stmt->execute([':uid' => $uid]);
$watchlist = $stmt->fetchAll();

$page_title = 'Stock Watchlist — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Watchlists</p>
<h1 class="page-title">Stock Watchlist</h1>

<div class="disclaimer disclaimer--stock" style="margin-bottom:1.25rem">
  <strong>⚠ Educational Watchlist</strong> — For tracking purposes only. Prices are manually entered and may not reflect real-time market data. Prime Financials is NOT a SEBI Registered Investment Advisor. This is not investment advice.
</div>

<?php if ($error): ?><div class="flash-error"><?= htmlspecialchars($error, ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

<!-- Add form -->
<div class="portal-card" style="margin-bottom:1.5rem">
  <div style="display:flex;justify-content:space-between;align-items:center;cursor:pointer" onclick="toggleForm('sform','sicon')">
    <div class="card-title" style="margin-bottom:0">+ Add Stock to Watchlist</div>
    <span id="sicon" style="color:var(--lime);font-size:1.25rem">+</span>
  </div>
  <div id="sform" style="display:none;margin-top:1.25rem">
    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES,'UTF-8') ?>">
      <input type="hidden" name="action" value="add_stock">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Company Name *</label><input class="form-input" type="text" name="company_name" required placeholder="e.g. Reliance Industries"></div>
        <div class="form-group"><label class="form-label">Ticker Symbol *</label><input class="form-input" type="text" name="ticker_symbol" required placeholder="e.g. RELIANCE" style="text-transform:uppercase"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Exchange</label><select class="form-select" name="exchange"><option value="NSE">NSE</option><option value="BSE">BSE</option></select></div>
        <div class="form-group"><label class="form-label">Price When Added (₹)</label><input class="form-input" type="number" name="added_price" step="0.01" placeholder="2450.00"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Target Price (₹)</label><input class="form-input" type="number" name="target_price" step="0.01" placeholder="Optional"></div>
        <div class="form-group"><label class="form-label">Stop Loss (₹)</label><input class="form-input" type="number" name="stop_loss" step="0.01" placeholder="Optional"></div>
      </div>
      <div class="form-group"><label class="form-label">Notes</label><input class="form-input" type="text" name="client_notes" placeholder="Your reason for tracking"></div>
      <button type="submit" class="btn-primary btn-sm">Add to Watchlist</button>
    </form>
  </div>
</div>

<?php if (empty($watchlist)): ?>
<div class="portal-card" style="text-align:center;padding:3rem;color:var(--text-secondary)">
  <div style="font-size:2rem;margin-bottom:1rem">☆</div>
  Your stock watchlist is empty. Add stocks you want to track.
</div>
<?php else: ?>
<div class="portal-card" style="padding:0">
  <div class="table-wrapper" style="border:none;border-radius:12px">
    <table class="portal-table">
      <thead><tr><th>Company</th><th>Exchange</th><th>Added @</th><th>Target</th><th>Stop Loss</th><th>Research</th><th>Notes</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($watchlist as $w): ?>
        <tr>
          <td>
            <div style="font-weight:500;color:var(--cream)"><?= htmlspecialchars($w['company_name'], ENT_QUOTES,'UTF-8') ?></div>
            <div><span class="badge badge-muted"><?= htmlspecialchars($w['ticker_symbol'], ENT_QUOTES,'UTF-8') ?></span></div>
          </td>
          <td><span class="badge badge-muted"><?= $w['exchange'] ?></span></td>
          <td style="font-family:'IBM Plex Mono',monospace"><?= $w['added_price']?'₹'.number_format((float)$w['added_price'],2):'—' ?></td>
          <td style="color:var(--bright);font-family:'IBM Plex Mono',monospace"><?= $w['target_price']?'₹'.number_format((float)$w['target_price'],2):'—' ?></td>
          <td style="color:var(--danger);font-family:'IBM Plex Mono',monospace"><?= $w['stop_loss']?'₹'.number_format((float)$w['stop_loss'],2):'—' ?></td>
          <td><?= $w['report_title']?'<a href="'.SITE_URL.'/advisory/stocks-detail.php?id='.htmlspecialchars($w['research_id'],ENT_QUOTES,'UTF-8').'" class="auth-link" style="font-size:0.78rem">View →</a>':'—' ?></td>
          <td style="font-size:0.8rem;color:var(--text-secondary)"><?= $w['client_notes']?htmlspecialchars($w['client_notes'], ENT_QUOTES,'UTF-8'):'—' ?></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES,'UTF-8') ?>">
              <input type="hidden" name="action" value="remove_stock">
              <input type="hidden" name="wid" value="<?= $w['id'] ?>">
              <button type="submit" class="btn-danger btn-sm" onclick="return confirm('Remove?')">Remove</button>
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
