?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
// Advisory pages accessible to both clients and admins
if (!is_logged_in()) { header('Location: ' . SITE_URL . '/auth/login.php'); exit; }

$db  = get_db();
$uid = get_user_id();
$id  = (int)($_GET['id'] ?? 0);

if (!$id) { header('Location: ' . SITE_URL . '/advisory/stocks.php'); exit; }

$stmt = $db->prepare("SELECT * FROM stock_research WHERE id = :id AND is_published = 1");
$stmt->execute([':id' => $id]);
$stock = $stmt->fetch();
if (!$stock) { header('Location: ' . SITE_URL . '/advisory/stocks.php'); exit; }

// Increment views
try { $db->prepare("UPDATE stock_research SET views = views + 1 WHERE id = :id")->execute([':id'=>$id]); } catch(PDOException $e){}

// Watchlist add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_watchlist') {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        try {
            $db->prepare("INSERT INTO stock_watchlist (user_id, company_name, ticker_symbol, exchange, added_price, research_id) VALUES (:uid,:co,:tk,:ex,:price,:rid)")
               ->execute([':uid'=>$uid,':co'=>$stock['company_name'],':tk'=>$stock['ticker_symbol'],':ex'=>$stock['exchange'],':price'=>$stock['price_at_report']??null,':rid'=>$id]);
            $_SESSION['flash'] = ['type'=>'success','message'=>'Added to stock watchlist.'];
        } catch(PDOException $e){ error_log($e->getMessage()); }
        header('Location: ' . SITE_URL . '/advisory/stocks-detail.php?id=' . $id); exit;
    }
}

$key_metrics = [];
if (!empty($stock['key_metrics'])) {
    $decoded = json_decode($stock['key_metrics'], true);
    if (is_array($decoded)) $key_metrics = $decoded;
}

$cap_labels = ['large_cap'=>'Large Cap','mid_cap'=>'Mid Cap','small_cap'=>'Small Cap','micro_cap'=>'Micro Cap'];
$page_title = htmlspecialchars($stock['company_name'], ENT_QUOTES, 'UTF-8') . ' â€” Prime Financials';
require_once '../includes/portal-header.php';
?>

<div style="margin-bottom:1.5rem"><a href="<?= SITE_URL ?>/advisory/stocks.php" style="color:var(--text-secondary);font-size:0.875rem;text-decoration:none">â† Back to Stock Research</a></div>

<div class="disclaimer disclaimer--stock">
  <strong>âš  Research Note â€” Not Investment Advice</strong> These notes are for educational purposes only. Prime Financials is NOT a SEBI RIA. Please consult a SEBI RIA before investing. Investments in securities are subject to market risks.
</div>

<div style="margin:1.5rem 0">
  <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.75rem">
    <span class="badge badge-muted"><?= htmlspecialchars($stock['ticker_symbol'],ENT_QUOTES,'UTF-8') ?></span>
    <span class="badge badge-muted"><?= $stock['exchange'] ?></span>
    <?php if ($stock['sector']): ?><span class="badge badge-muted"><?= htmlspecialchars($stock['sector'],ENT_QUOTES,'UTF-8') ?></span><?php endif; ?>
    <?php if ($stock['market_cap_type']): ?><span class="badge badge-green"><?= $cap_labels[$stock['market_cap_type']]??'' ?></span><?php endif; ?>
  </div>
  <h1 style="font-family:'Cormorant Garamond',serif;font-size:2.25rem;font-weight:700;color:var(--cream);margin-bottom:0.25rem"><?= htmlspecialchars($stock['company_name'],ENT_QUOTES,'UTF-8') ?></h1>
  <?php if ($stock['report_title']): ?><p style="font-style:italic;color:var(--text-secondary);font-size:1rem"><?= htmlspecialchars($stock['report_title'],ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
  <p style="font-size:0.78rem;color:var(--text-muted);font-family:'DM Mono',monospace;margin-top:0.5rem">
    Report Date: <?= $stock['report_date']?date('d M Y',strtotime($stock['report_date'])):'â€”' ?>
    <?= $stock['price_at_report']?' Â· Price at report: â‚¹'.number_format((float)$stock['price_at_report'],2):'' ?>
    Â· <?= $stock['views'] ?> views
  </p>
</div>

<div class="grid-2" style="align-items:start">
<div>
  <?php if (!empty($key_metrics)): ?>
  <div class="portal-card" style="margin-bottom:1.25rem">
    <div class="card-title">Key Metrics</div>
    <table style="width:100%;border-collapse:collapse">
      <?php foreach ($key_metrics as $metric => $value): ?>
      <tr style="border-bottom:1px solid var(--border-light)">
        <td style="padding:0.5rem 0;color:var(--text-secondary);font-size:0.875rem"><?= htmlspecialchars($metric,ENT_QUOTES,'UTF-8') ?></td>
        <td style="padding:0.5rem 0;text-align:right;font-family:'DM Mono',monospace;color:var(--cream);font-size:0.875rem"><?= htmlspecialchars($value,ENT_QUOTES,'UTF-8') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($stock['analyst_view']): ?>
  <div class="portal-card" style="margin-bottom:1.25rem">
    <div class="card-title">Analyst View</div>
    <p style="color:var(--text-secondary);font-size:0.9rem;line-height:1.75"><?= nl2br(htmlspecialchars($stock['analyst_view'],ENT_QUOTES,'UTF-8')) ?></p>
  </div>
  <?php endif; ?>

  <!-- Watchlist add -->
  <div class="portal-card">
    <div class="card-title">Track This Stock</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
      <input type="hidden" name="action" value="add_watchlist">
      <p style="color:var(--text-secondary);font-size:0.875rem;margin-bottom:1rem">Add to your watchlist to track this stock and set price alerts.</p>
      <button type="submit" class="btn-outline btn-sm">â˜… Add to Stock Watchlist</button>
    </form>
  </div>
</div>

<?php if ($stock['report_content']): ?>
<div class="portal-card">
  <div class="card-title">Full Research Note</div>
  <div style="color:var(--text-secondary);font-size:0.9rem;line-height:1.8">
    <?= nl2br(htmlspecialchars($stock['report_content'],ENT_QUOTES,'UTF-8')) ?>
  </div>
</div>
<?php endif; ?>
</div>

<?php require_once '../includes/portal-footer.php'; ?>

