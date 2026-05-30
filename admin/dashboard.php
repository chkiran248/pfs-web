<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('admin');

$db = get_db();

// KPIs
$kpis = [];
$kpis['total_clients']  = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='client' AND is_active=1")->fetchColumn();
$kpis['new_this_month'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='client' AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")->fetchColumn();
$kpis['total_leads']    = (int)$db->query("SELECT COUNT(*) FROM leads")->fetchColumn();
$kpis['new_leads_week'] = (int)$db->query("SELECT COUNT(*) FROM leads WHERE created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
$kpis['pub_insights']   = (int)$db->query("SELECT COUNT(*) FROM market_insights WHERE is_published=1")->fetchColumn();
$kpis['pub_funds']      = (int)$db->query("SELECT COUNT(*) FROM fund_recommendations WHERE is_active=1")->fetchColumn();
$kpis['pub_research']   = (int)$db->query("SELECT COUNT(*) FROM stock_research WHERE is_published=1")->fetchColumn();
$kpis['no_portfolio']   = (int)$db->query("SELECT COUNT(DISTINCT u.id) FROM users u LEFT JOIN portfolio_entries pe ON pe.user_id=u.id WHERE u.role='client' AND u.is_active=1 AND pe.id IS NULL")->fetchColumn();

// Recent clients
$clients = $db->query("SELECT u.full_name, u.email, u.created_at, p.risk_profile, COALESCE(SUM(pe.current_value),0) as portfolio_val FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id LEFT JOIN portfolio_entries pe ON pe.user_id=u.id WHERE u.role='client' GROUP BY u.id ORDER BY u.created_at DESC LIMIT 5")->fetchAll();

// Recent leads
$leads = $db->query("SELECT * FROM leads ORDER BY created_at DESC LIMIT 5")->fetchAll();

$page_title = 'Admin Dashboard — Prime Financials';
require_once '../includes/admin-header.php';
?>

<p class="page-eyebrow">Admin</p>
<h1 class="page-title">Dashboard</h1>
<p class="page-subtitle">Platform overview — <?= date('d M Y') ?></p>

<!-- KPI grid -->
<div class="stats-grid" style="margin-bottom:1.5rem">
  <div class="stat-box"><div class="stat-label">Total Clients</div><div class="stat-value neutral"><?= $kpis['total_clients'] ?></div><div class="stat-sub"><?= $kpis['new_this_month'] ?> new this month</div></div>
  <div class="stat-box"><div class="stat-label">Total Leads</div><div class="stat-value neutral"><?= $kpis['total_leads'] ?></div><div class="stat-sub"><?= $kpis['new_leads_week'] ?> this week</div></div>
  <div class="stat-box"><div class="stat-label">Published Insights</div><div class="stat-value positive"><?= $kpis['pub_insights'] ?></div><div class="stat-sub"><?= $kpis['pub_funds'] ?> fund recs active</div></div>
  <div class="stat-box"><div class="stat-label">No Portfolio Yet</div><div class="stat-value <?= $kpis['no_portfolio']>0?'negative':'positive' ?>"><?= $kpis['no_portfolio'] ?></div><div class="stat-sub">clients without holdings</div></div>
</div>

<div class="grid-2" style="align-items:start">
<!-- Recent clients -->
<div class="portal-card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div class="card-title" style="margin-bottom:0">Recent Clients</div>
    <a href="<?= SITE_URL ?>/admin/clients.php" class="auth-link" style="font-size:0.8rem">View all →</a>
  </div>
  <?php if (empty($clients)): ?>
  <p style="color:var(--text-secondary);font-size:0.875rem">No clients yet.</p>
  <?php else: ?>
  <div class="table-wrapper" style="border:none"><table class="portal-table">
    <thead><tr><th>Client</th><th>Risk</th><th>Portfolio</th><th>Joined</th></tr></thead>
    <tbody>
      <?php foreach ($clients as $c): ?>
      <tr>
        <td><div style="font-weight:500;color:var(--cream)"><?= htmlspecialchars($c['full_name'],ENT_QUOTES,'UTF-8') ?></div><div style="font-size:0.75rem;color:var(--text-secondary)"><?= htmlspecialchars($c['email'],ENT_QUOTES,'UTF-8') ?></div></td>
        <td><span class="badge <?= $c['risk_profile']?'badge-green':'badge-muted' ?>"><?= $c['risk_profile']?ucfirst($c['risk_profile']):'—' ?></span></td>
        <td style="font-family:'DM Mono',monospace;font-size:0.82rem">₹<?= number_format((float)$c['portfolio_val'],0) ?></td>
        <td style="font-size:0.75rem;color:var(--text-muted)"><?= date('d M Y',strtotime($c['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<!-- Recent leads -->
<div class="portal-card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div class="card-title" style="margin-bottom:0">Recent Leads</div>
    <a href="<?= SITE_URL ?>/admin/leads.php" class="auth-link" style="font-size:0.8rem">View all →</a>
  </div>
  <?php if (empty($leads)): ?>
  <p style="color:var(--text-secondary);font-size:0.875rem">No leads yet.</p>
  <?php else: ?>
  <div class="table-wrapper" style="border:none"><table class="portal-table">
    <thead><tr><th>Lead</th><th>Interest</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
      <?php $sbadge=['new'=>'badge-green','contacted'=>'badge-gold','converted'=>'badge-green','lost'=>'badge-muted']; ?>
      <?php foreach ($leads as $l): ?>
      <tr>
        <td><div style="font-weight:500;color:var(--cream)"><?= htmlspecialchars($l['full_name']??'',ENT_QUOTES,'UTF-8') ?></div><div style="font-size:0.75rem;color:var(--text-secondary)"><?= htmlspecialchars($l['email']??'',ENT_QUOTES,'UTF-8') ?></div></td>
        <td style="font-size:0.8rem"><?= htmlspecialchars($l['interest']??'',ENT_QUOTES,'UTF-8') ?></td>
        <td><span class="badge <?= $sbadge[$l['status']]??'badge-muted' ?>"><?= ucfirst($l['status']) ?></span></td>
        <td style="font-size:0.75rem;color:var(--text-muted)"><?= date('d M Y',strtotime($l['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
</div>

<!-- Quick actions -->
<div class="portal-card" style="margin-top:1.5rem">
  <div class="card-title">Quick Actions</div>
  <div style="display:flex;flex-wrap:wrap;gap:0.75rem">
    <a href="<?= SITE_URL ?>/admin/insights.php?new=1"           class="btn-outline btn-sm">+ New Insight</a>
    <a href="<?= SITE_URL ?>/admin/fund-recommendations.php?new=1" class="btn-outline btn-sm">+ Fund Recommendation</a>
    <a href="<?= SITE_URL ?>/admin/stock-research.php?new=1"     class="btn-outline btn-sm">+ Stock Research</a>
    <a href="<?= SITE_URL ?>/admin/documents.php"                 class="btn-outline btn-sm">↑ Send Document</a>
    <a href="<?= SITE_URL ?>/admin/leads.php"                     class="btn-outline btn-sm">+ Add Lead</a>
  </div>
</div>

<?php require_once '../includes/admin-footer.php'; ?>
