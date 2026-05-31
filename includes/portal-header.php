<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

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

// Helper: sidebar link builder
function nav_link(string $href, string $icon, string $label, string $current): string {
    $page = basename($href, '.php');
    $active = ($current === $page) ? ' active' : '';
    $url = SITE_URL . $href;
    return sprintf(
        '<a href="%s" class="sidebar-link%s"><span class="icon">%s</span>%s</a>',
        htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
        $active,
        $icon,
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
      <a href="<?= SITE_URL ?>/portal/dashboard.php">
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
          <span class="icon">✦</span>PrimoAI
          <span class="sidebar-badge">NEW</span>
        </a>
      </div>

      <!-- OVERVIEW -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Overview</span>
        <?= nav_link('/portal/dashboard.php', '⬡', 'Dashboard', $current_page) ?>
        <?= nav_link('/portal/profile.php',   '◎', 'My Profile', $current_page) ?>
      </div>

      <!-- MY FINANCES -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">My Finances</span>
        <?= nav_link('/portal/portfolio.php',   '◈', 'Portfolio',   $current_page) ?>
        <?= nav_link('/portal/goals.php',       '◉', 'Goals',       $current_page) ?>
        <?= nav_link('/portal/fd-tracker.php',  '◫', 'FD Tracker',  $current_page) ?>
        <?= nav_link('/portal/rebalancer.php',  '⚖', 'Rebalancer',  $current_page) ?>
      </div>

      <!-- TOOLS -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Tools</span>
        <?= nav_link('/portal/calculators.php', '⊕', 'Calculators', $current_page) ?>
      </div>

      <!-- ADVISORY -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Advisory</span>
        <?= nav_link('/advisory/mutual-funds.php',      '◆', 'Mutual Funds',      $current_page) ?>
        <?= nav_link('/advisory/fund-compare.php',      '◇', 'Fund Compare',      $current_page) ?>
        <?= nav_link('/advisory/stocks.php',            '◃', 'Stock Research',    $current_page) ?>
        <?= nav_link('/advisory/sector-tracker.php',    '▹', 'Sector Tracker',    $current_page) ?>
        <?= nav_link('/advisory/model-portfolios.php',  '▤', 'Model Portfolios',  $current_page) ?>
        <?= nav_link('/advisory/insights.php',          '▦', 'Market Insights',   $current_page) ?>
      </div>

      <!-- WATCHLISTS -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Watchlists</span>
        <?= nav_link('/portal/fund-watchlist.php',  '★', 'Fund Watchlist',  $current_page) ?>
        <?= nav_link('/portal/stock-watchlist.php', '☆', 'Stock Watchlist', $current_page) ?>
      </div>

      <!-- DOCUMENTS -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Documents</span>
        <?= nav_link('/portal/documents.php', '▣', 'My Documents', $current_page) ?>
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
