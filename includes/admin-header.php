<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$admin_current = basename($_SERVER['PHP_SELF'], '.php');

$flash = null;
if (!empty($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

function admin_link(string $href, string $icon, string $label, string $current): string {
    $page   = basename($href, '.php');
    $active = ($current === $page) ? ' active' : '';
    $url    = SITE_URL . $href;
    return sprintf('<a href="%s" class="sidebar-link%s"><span class="icon">%s</span>%s</a>',
        htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), $active, $icon,
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title><?= htmlspecialchars($page_title ?? 'Admin — Prime Financials', ENT_QUOTES, 'UTF-8') ?></title>
  <script>(function(){var t=localStorage.getItem('pv-theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})()</script>
  <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/portal.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
</head>
<body>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<div class="portal-wrapper">

  <aside class="portal-sidebar" id="sidebar">
    <div class="sidebar-logo">
      <a href="<?= SITE_URL ?>/admin/dashboard.php">
        <img src="<?= SITE_URL ?>/logo.png" alt="Prime Financials"
             width="32" height="32"
             style="width:32px;height:32px;object-fit:contain;border-radius:6px;flex-shrink:0;mix-blend-mode:lighten;display:block" />
        <div>
          <span class="logo-text">Prime Admin</span>
          <span class="logo-tagline">Control Centre</span>
        </div>
      </a>
    </div>
    <nav class="sidebar-nav">
      <div class="sidebar-group">
        <span class="sidebar-group-label">Overview</span>
        <?= admin_link('/admin/dashboard.php',           '⬡', 'Dashboard',          $admin_current) ?>
      </div>
      <div class="sidebar-group">
        <span class="sidebar-group-label">Clients</span>
        <?= admin_link('/admin/clients.php',             '◎', 'All Clients',         $admin_current) ?>
        <?= admin_link('/admin/leads.php',               '◉', 'Leads',               $admin_current) ?>
      </div>
      <div class="sidebar-group">
        <span class="sidebar-group-label">Advisory Content</span>
        <?= admin_link('/admin/fund-recommendations.php','◆', 'Fund Recommendations', $admin_current) ?>
        <?= admin_link('/admin/stock-research.php',      '◃', 'Stock Research',       $admin_current) ?>
        <?= admin_link('/admin/model-portfolios.php',    '▤', 'Model Portfolios',     $admin_current) ?>
        <?= admin_link('/admin/insights.php',            '▦', 'Market Insights',      $admin_current) ?>
      </div>
      <div class="sidebar-group">
        <span class="sidebar-group-label">Documents</span>
        <?= admin_link('/admin/documents.php',   '▣', 'Send Documents', $admin_current) ?>
      </div>
      <div class="sidebar-group">
        <span class="sidebar-group-label">System</span>
        <?= admin_link('/admin/coupons.php',     '🎟', 'Coupon Codes',  $admin_current) ?>
        <?= admin_link('/admin/data-status.php', '◈', 'Data Pipeline',  $admin_current) ?>
      </div>
      <div class="sidebar-group" style="margin-top:auto;padding-top:1rem;border-top:1px solid var(--border-light)">
        <span class="sidebar-group-label">Client Portal</span>
        <a href="<?= SITE_URL ?>/portal/dashboard.php" class="sidebar-link" style="font-size:0.78rem"><span class="icon">↗</span>View as Client</a>
      </div>
    </nav>
  </aside>

  <div class="portal-main">
    <header class="portal-header" id="portal-header">
      <div class="header-left">
        <button class="hamburger" id="hamburger">☰</button>
        <span style="font-family:'DM Mono',monospace;font-size:0.62rem;color:var(--gold);letter-spacing:0.2em;padding:0.2rem 0.6rem;border:1px solid rgba(201,168,76,0.3);border-radius:4px">ADMIN</span>
      </div>
      <div class="header-right">
        <button class="theme-toggle" id="theme-toggle" title="Toggle theme">☀️</button>
        <div class="header-user">
          <div class="user-avatar" style="background:var(--gold);color:#1a1a00;font-weight:700">A</div>
          <span class="user-name"><?= htmlspecialchars(get_user_name(), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <form method="POST" action="<?= SITE_URL ?>/auth/logout.php" style="display:inline;margin:0">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
          <button type="submit" class="logout-link" style="background:none;border:none;cursor:pointer;font-family:inherit">Logout</button>
        </form>
      </div>
    </header>

    <main class="portal-content">
      <?php if ($flash): ?>
        <div class="flash-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>
