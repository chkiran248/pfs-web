?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/stock-api.php';
require_login();
// Advisory pages accessible to both clients and admins
if (!is_logged_in()) { header('Location: ' . SITE_URL . '/auth/login.php'); exit; }

$db = get_db();
stock_maybe_refresh($db);

$sector = trim($_GET['sector'] ?? '');
$cap    = trim($_GET['cap'] ?? '');

$where = ['is_published = 1'];
$params = [];
if ($sector) { $where[] = 'sector = :sector'; $params[':sector'] = $sector; }
if ($cap)    { $where[] = 'market_cap_type = :cap'; $params[':cap'] = $cap; }

$stmt = $db->prepare("SELECT * FROM stock_research WHERE " . implode(' AND ', $where) . " ORDER BY report_date DESC");
$stmt->execute($params);
$stocks = $stmt->fetchAll();

$sectors_stmt = $db->prepare("SELECT DISTINCT sector FROM stock_research WHERE is_published = 1 AND sector IS NOT NULL ORDER BY sector");
$sectors_stmt->execute();
$sectors = $sectors_stmt->fetchAll(PDO::FETCH_COLUMN);

$cap_labels = ['large_cap'=>'Large Cap','mid_cap'=>'Mid Cap','small_cap'=>'Small Cap','micro_cap'=>'Micro Cap'];
$page_title = 'Stock Research â€” Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Advisory</p>
<h1 class="page-title">Stock Research</h1>

<div class="disclaimer disclaimer--stock">
  <strong>âš  Research Note â€” Not Investment Advice</strong>
  These research notes are for educational and informational purposes only. Prime Financials is an AMFI Registered Mutual Fund Distributor and is NOT a SEBI Registered Investment Advisor (RIA). This does not constitute investment advice or a recommendation to buy or sell any security. Please consult a SEBI RIA before investing. Investments in securities are subject to market risks.
</div>

<!-- Filters -->
<form method="GET" style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end;margin:1.25rem 0">
  <div>
    <label class="form-label">Sector</label>
    <select class="form-select" name="sector" onchange="this.form.submit()">
      <option value="">All Sectors</option>
      <?php foreach ($sectors as $s): ?><option value="<?= htmlspecialchars($s,ENT_QUOTES,'UTF-8') ?>" <?= $sector===$s?'selected':'' ?>><?= htmlspecialchars($s,ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label">Market Cap</label>
    <select class="form-select" name="cap" onchange="this.form.submit()">
      <option value="">All Caps</option>
      <?php foreach ($cap_labels as $v=>$l): ?><option value="<?= $v ?>" <?= $cap===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
  </div>
  <?php if ($sector||$cap): ?><a href="<?= SITE_URL ?>/advisory/stocks.php" class="btn-ghost btn-sm" style="align-self:flex-end">Clear</a><?php endif; ?>
</form>

<?php if (empty($stocks)): ?>
<div class="portal-card" style="text-align:center;padding:3rem;color:var(--text-secondary)">
  <div style="font-size:2rem;margin-bottom:1rem">â—ƒ</div>
  No research published yet.
</div>
<?php else: ?>
<div class="grid-2">
  <?php foreach ($stocks as $s): ?>
  <div class="portal-card">
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.75rem">
      <span class="badge badge-muted"><?= htmlspecialchars($s['ticker_symbol'],ENT_QUOTES,'UTF-8') ?></span>
      <span class="badge badge-muted"><?= $s['exchange'] ?></span>
      <?php if ($s['sector']): ?><span class="badge badge-muted"><?= htmlspecialchars($s['sector'],ENT_QUOTES,'UTF-8') ?></span><?php endif; ?>
      <?php if ($s['market_cap_type']): ?><span class="badge badge-green"><?= $cap_labels[$s['market_cap_type']]??$s['market_cap_type'] ?></span><?php endif; ?>
    </div>
    <div style="font-family:'Cormorant Garamond',serif;font-size:1.15rem;font-weight:600;color:var(--cream);margin-bottom:0.3rem"><?= htmlspecialchars($s['company_name'],ENT_QUOTES,'UTF-8') ?></div>
    <?php if ($s['current_price']): ?>
    <div style="margin-bottom:0.4rem"><?= stock_price_badge((float)$s['current_price'],(float)$s['price_change_pct']) ?></div>
    <?php endif; ?>
    <?php if ($s['report_title']): ?><div style="font-style:italic;color:var(--text-secondary);font-size:0.875rem;margin-bottom:0.5rem"><?= htmlspecialchars($s['report_title'],ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
    <?php if ($s['analyst_view']): ?><div style="font-size:0.82rem;color:var(--text-secondary);line-height:1.6;margin-bottom:0.75rem"><?= htmlspecialchars(mb_substr($s['analyst_view'],0,120),ENT_QUOTES,'UTF-8') ?><?= strlen($s['analyst_view'])>120?'â€¦':'' ?></div><?php endif; ?>
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div style="font-size:0.75rem;color:var(--text-muted);font-family:'DM Mono',monospace">
        <?= $s['report_date']?date('d M Y',strtotime($s['report_date'])):'â€”' ?><?= $s['price_at_report']?' Â· â‚¹'.number_format((float)$s['price_at_report'],2).' at report':'' ?>
      </div>
      <a href="<?= SITE_URL ?>/advisory/stocks-detail.php?id=<?= $s['id'] ?>" class="btn-outline btn-sm">Read Report â†’</a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once '../includes/portal-footer.php'; ?>


