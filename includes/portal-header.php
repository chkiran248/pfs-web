<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/subscription.php';

// Determine current page for sidebar active state
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Treat any calculator page as "calculators" for sidebar highlighting
$calculator_pages = ['sip-calculator','tax-calculator','nps-projector','cashflow-modeler','overlap-analyzer','insurance-checker','tax-modeler'];
if (in_array($current_page, $calculator_pages)) {
    $current_page = 'calculators';
}

// Read and clear flash message
$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// User initials for avatar
$name_parts = explode(' ', get_user_name());
$initials = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : ''));

// Plan badge for header
$_hdr_plan = get_user_plan(get_user_id());
$_hdr_pc   = get_plan_config($_hdr_plan);

// Helper: sidebar link builder — $icon is a Phosphor icon name e.g. 'house'
function nav_link(string $href, string $icon, string $label, string $current): string {
    $page   = basename($href, '.php');
    $active = ($current === $page) ? ' active' : '';
    $url    = SITE_URL . $href;
    return sprintf(
        '<a href="%s" class="sidebar-link%s"><i class="ph ph-%s sidebar-icon"></i><span>%s</span></a>',
        htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
        $active,
        htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>" />
  <title><?= htmlspecialchars($page_title ?? 'Prime Financials', ENT_QUOTES, 'UTF-8') ?></title>

  <!-- Anti-flash theme script — must run before CSS -->
  <script>(function(){var t=localStorage.getItem('pv-theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})()</script>

  <!-- Portal CSS -->
  <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/portal.css" />

  <!-- Phosphor Icons -->
  <link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css" />

  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
</head>
<body>

<!-- Sidebar overlay (mobile) -->
<div class="sidebar-overlay" id="sidebar-overlay"></div>

<div class="portal-wrapper">

  <!-- ── SIDEBAR ─────────────────────────────────────────── -->
  <aside class="portal-sidebar" id="sidebar">

    <div class="sidebar-logo">
      <a href="<?= SITE_URL ?>">
        <img src="<?= SITE_URL ?>/logo.png" alt="Prime Financials"
             width="32" height="32"
             style="width:32px;height:32px;object-fit:contain;border-radius:6px;flex-shrink:0;mix-blend-mode:lighten;display:block" />
        <div>
          <span class="logo-text">Prime Financials</span>
          <span class="logo-tagline">Data is Our Power</span>
        </div>
      </a>
    </div>

    <nav class="sidebar-nav">

      <!-- AI ASSISTANT -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">AI Assistant</span>
        <a href="<?= SITE_URL ?>/portal/primo.php"
           class="sidebar-link<?= $current_page==='primo'?' active':'' ?>">
          <i class="ph ph-sparkle sidebar-icon"></i><span>PrimoAI</span>
          <span class="sidebar-badge">NEW</span>
        </a>
      </div>

      <!-- OVERVIEW -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Overview</span>
        <?= nav_link('/portal/dashboard.php', 'house',      'Dashboard',      $current_page) ?>
        <?= nav_link('/portal/profile.php',   'user-circle','My Profile',      $current_page) ?>
        <?= nav_link('/portal/pricing.php',   'tag',        'Plans & Pricing', $current_page) ?>
      </div>

      <!-- MY FINANCES -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">My Finances</span>
        <?= nav_link('/portal/portfolio.php',   'chart-pie',  'Portfolio',  $current_page) ?>
        <?= nav_link('/portal/goals.php',       'target',     'Goals',      $current_page) ?>
        <?= nav_link('/portal/fd-tracker.php',  'bank',       'FD Tracker', $current_page) ?>
        <?= nav_link('/portal/rebalancer.php',  'scales',     'Rebalancer', $current_page) ?>
      </div>

      <!-- TOOLS -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Tools</span>
        <?= nav_link('/portal/calculators.php', 'calculator', 'Calculators', $current_page) ?>
      </div>

      <!-- ADVISORY -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Advisory</span>
        <?= nav_link('/advisory/mutual-funds.php',     'trend-up',         'Mutual Funds',     $current_page) ?>
        <?= nav_link('/advisory/fund-compare.php',     'arrows-left-right','Fund Compare',     $current_page) ?>
        <?= nav_link('/advisory/stocks.php',           'magnifying-glass', 'Stock Research',   $current_page) ?>
        <?= nav_link('/advisory/sector-tracker.php',   'compass',          'Sector Tracker',   $current_page) ?>
        <?= nav_link('/advisory/model-portfolios.php', 'briefcase',        'Model Portfolios', $current_page) ?>
        <?= nav_link('/advisory/insights.php',         'newspaper',        'Market Insights',  $current_page) ?>
      </div>

      <!-- WATCHLISTS -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Watchlists</span>
        <?= nav_link('/portal/fund-watchlist.php',  'star',           'Fund Watchlist',  $current_page) ?>
        <?= nav_link('/portal/stock-watchlist.php', 'chart-line-up',  'Stock Watchlist', $current_page) ?>
      </div>

      <!-- DOCUMENTS -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Documents</span>
        <?= nav_link('/portal/documents.php', 'folder-open', 'My Documents', $current_page) ?>
      </div>

    </nav>
  </aside>

  <!-- ── MAIN AREA ──────────────────────────────────────── -->
  <div class="portal-main">

    <!-- Portal Header Bar -->
    <header class="portal-header" id="portal-header">
      <div class="header-left">
        <button class="hamburger" id="hamburger" aria-label="Toggle menu">☰</button>
      </div>

      <div class="header-right">
        <button class="theme-toggle" id="theme-toggle" title="Toggle theme">☀️</button>

        <div class="header-user">
          <div class="user-avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
          <span class="user-name"><?= htmlspecialchars(get_user_name(), ENT_QUOTES, 'UTF-8') ?></span>
          <span style="font-family:'DM Mono',monospace;font-size:0.58rem;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;padding:0.18rem 0.5rem;border-radius:12px;border:1px solid <?= $_hdr_pc['border'] ?>;background:<?= $_hdr_pc['bg'] ?>;color:<?= $_hdr_pc['colour'] ?>"><?= $_hdr_pc['icon'] ?> <?= $_hdr_pc['label'] ?></span>
        </div>

        <form method="POST" action="<?= SITE_URL ?>/auth/logout.php" style="display:inline;margin:0">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>" />
          <button type="submit" class="logout-link" style="background:none;border:none;cursor:pointer;font-family:inherit">Logout</button>
        </form>
      </div>
    </header>

    <!-- Main Content -->
    <main class="portal-content">

      <!-- Flash messages -->
      <?php if ($flash): ?>
        <div class="flash-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>
