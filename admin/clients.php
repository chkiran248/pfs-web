<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('admin');

$db = get_db();

// CSV export
if (isset($_GET['export'])) {
    $stmt = $db->query("SELECT u.full_name, u.email, u.phone, u.created_at, u.last_login, p.risk_profile FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id WHERE u.role='client' ORDER BY u.created_at DESC");
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="clients_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name','Email','Phone','Registered','Last Login','Risk Profile']);
    while ($row = $stmt->fetch()) { fputcsv($out, $row); }
    fclose($out); exit;
}

// View single client
$view_id = (int)($_GET['view'] ?? 0);
$client  = null;
if ($view_id) {
    $stmt = $db->prepare("SELECT u.*, p.dob, p.occupation, p.annual_income, p.risk_profile, p.life_stage, p.pan_number, p.city, p.state FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id WHERE u.id=:id AND u.role='client'");
    $stmt->execute([':id'=>$view_id]);
    $client = $stmt->fetch();
}

// Search + list
$q = trim($_GET['q'] ?? '');
$where = ["u.role='client'"];
$params = [];
if ($q) { $where[] = "(u.full_name LIKE :q OR u.email LIKE :q)"; $params[':q'] = "%$q%"; }
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 20;
$offset = ($page - 1) * $per;
$total_stmt = $db->prepare("SELECT COUNT(*) FROM users u WHERE " . implode(' AND ', $where));
$total_stmt->execute($params);
$total = (int)$total_stmt->fetchColumn();
$pages = (int)ceil($total / $per);

$params[':limit']  = $per;
$params[':offset'] = $offset;
$stmt = $db->prepare("SELECT u.full_name, u.email, u.phone, u.created_at, u.last_login, p.risk_profile, COALESCE(SUM(pe.current_value),0) as portfolio_val, u.id FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id LEFT JOIN portfolio_entries pe ON pe.user_id=u.id WHERE " . implode(' AND ', $where) . " GROUP BY u.id ORDER BY u.created_at DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $per, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
if ($q) $stmt->bindValue(':q', "%$q%");
$stmt->execute();
$clients = $stmt->fetchAll();

$page_title = 'Clients — Prime Financials Admin';
require_once '../includes/admin-header.php';
?>

<p class="page-eyebrow">Admin</p>
<h1 class="page-title">Clients</h1>

<?php if ($client): ?>
<!-- Client detail view -->
<div style="margin-bottom:1rem"><a href="<?= SITE_URL ?>/admin/clients.php" style="color:var(--text-secondary);font-size:0.875rem;text-decoration:none">← Back to Clients</a></div>
<div class="grid-2" style="align-items:start">
  <div class="portal-card">
    <div class="card-title"><?= htmlspecialchars($client['full_name'],ENT_QUOTES,'UTF-8') ?></div>
    <?php foreach (['email'=>'Email','phone'=>'Phone','dob'=>'Date of Birth','city'=>'City','state'=>'State','occupation'=>'Occupation','annual_income'=>'Annual Income','risk_profile'=>'Risk Profile','pan_number'=>'PAN Number','created_at'=>'Registered','last_login'=>'Last Login'] as $col=>$label): ?>
    <div style="display:flex;justify-content:space-between;padding:0.4rem 0;border-bottom:1px solid var(--border-light)">
      <span style="color:var(--text-secondary);font-size:0.875rem"><?= $label ?></span>
      <span style="color:var(--cream);font-size:0.875rem"><?= htmlspecialchars($client[$col]??'—',ENT_QUOTES,'UTF-8') ?></span>
    </div>
    <?php endforeach; ?>
    <div style="margin-top:1rem">
      <a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=Hi+<?= urlencode($client['full_name']) ?>%2C+this+is+Prime+Financials." class="btn-outline btn-sm" target="_blank" rel="noopener">💬 WhatsApp</a>
      <a href="mailto:<?= htmlspecialchars($client['email'],ENT_QUOTES,'UTF-8') ?>" class="btn-ghost btn-sm" style="margin-left:0.5rem">✉ Email</a>
    </div>
  </div>
  <?php
  $pf_stmt = $db->prepare("SELECT * FROM portfolio_entries WHERE user_id=:uid ORDER BY fund_type");
  $pf_stmt->execute([':uid'=>$view_id]);
  $holdings = $pf_stmt->fetchAll();
  $total_inv = array_sum(array_column($holdings,'invested_amount'));
  $total_cur = array_sum(array_column($holdings,'current_value'));
  $gl_stmt = $db->prepare("SELECT * FROM goals WHERE user_id=:uid AND status='active'");
  $gl_stmt->execute([':uid'=>$view_id]);
  $goals = $gl_stmt->fetchAll();
  ?>
  <div>
    <div class="stats-grid" style="grid-template-columns:1fr 1fr;margin-bottom:1rem">
      <div class="stat-box"><div class="stat-label">Portfolio Value</div><div class="stat-value neutral">₹<?= number_format($total_cur,0) ?></div></div>
      <div class="stat-box"><div class="stat-label">Holdings</div><div class="stat-value neutral"><?= count($holdings) ?></div></div>
    </div>
    <?php if (!empty($goals)): ?>
    <div class="portal-card">
      <div class="card-title">Active Goals (<?= count($goals) ?>)</div>
      <?php foreach ($goals as $g): ?><div style="font-size:0.875rem;color:var(--text-secondary);padding:0.3rem 0;border-bottom:1px solid var(--border-light)"><?= htmlspecialchars($g['goal_name'],ENT_QUOTES,'UTF-8') ?> — ₹<?= number_format((float)$g['target_amount'],0) ?> by <?= $g['target_year'] ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<!-- Client list -->
<div style="display:flex;gap:1rem;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:1.25rem">
  <form method="GET" style="display:flex;gap:0.5rem">
    <input class="form-input" type="text" name="q" value="<?= htmlspecialchars($q,ENT_QUOTES,'UTF-8') ?>" placeholder="Search name or email" style="min-width:280px">
    <button type="submit" class="btn-primary btn-sm">Search</button>
    <?php if ($q): ?><a href="<?= SITE_URL ?>/admin/clients.php" class="btn-ghost btn-sm">Clear</a><?php endif; ?>
  </form>
  <a href="?export=1<?= $q?'&q='.urlencode($q):'' ?>" class="btn-outline btn-sm">↓ Export CSV</a>
</div>

<div class="portal-card" style="padding:0">
  <div class="table-wrapper" style="border:none;border-radius:12px">
    <table class="portal-table">
      <thead><tr><th>Client</th><th>Phone</th><th>Registered</th><th>Last Login</th><th>Risk</th><th>Portfolio</th><th>Action</th></tr></thead>
      <tbody>
        <?php if (empty($clients)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-secondary);padding:2rem">No clients found.</td></tr>
        <?php else: ?>
        <?php foreach ($clients as $c): ?>
        <tr>
          <td><div style="font-weight:500;color:var(--cream)"><?= htmlspecialchars($c['full_name'],ENT_QUOTES,'UTF-8') ?></div><div style="font-size:0.75rem;color:var(--text-secondary)"><?= htmlspecialchars($c['email'],ENT_QUOTES,'UTF-8') ?></div></td>
          <td style="font-size:0.82rem"><?= htmlspecialchars($c['phone']??'—',ENT_QUOTES,'UTF-8') ?></td>
          <td style="font-size:0.75rem;color:var(--text-muted)"><?= date('d M Y',strtotime($c['created_at'])) ?></td>
          <td style="font-size:0.75rem;color:var(--text-muted)"><?= $c['last_login']?date('d M Y',strtotime($c['last_login'])):'Never' ?></td>
          <td><span class="badge <?= $c['risk_profile']?'badge-green':'badge-muted' ?>"><?= $c['risk_profile']?ucfirst($c['risk_profile']):'—' ?></span></td>
          <td style="font-family:'DM Mono',monospace;font-size:0.82rem">₹<?= number_format((float)$c['portfolio_val'],0) ?></td>
          <td><a href="?view=<?= $c['id'] ?>" class="btn-ghost btn-sm">View</a></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div style="display:flex;gap:0.4rem;margin-top:1rem;justify-content:center">
  <?php for ($p=1;$p<=$pages;$p++): ?>
  <a href="?page=<?= $p ?><?= $q?'&q='.urlencode($q):'' ?>" class="<?= $p===$page?'btn-primary':'btn-ghost' ?> btn-sm"><?= $p ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once '../includes/admin-footer.php'; ?>
