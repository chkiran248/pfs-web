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
        '<a href="%s" class="sidebar-link%s"><i class="bi bi-%s sidebar-icon"></i><span>%s</span></a>',
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>" />
  <title><?= htmlspecialchars($page_title ?? 'Prime Financials', ENT_QUOTES, 'UTF-8') ?></title>

  <!-- Anti-flash theme script — must run before CSS -->
  <script>(function(){var t=localStorage.getItem('pv-theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})()</script>

  <!-- Portal CSS -->
  <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/portal.css" />

  <!-- Bootstrap Icons (local) -->
  <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/bootstrap-icons.min.css" />

  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
</head>
<body>

<!-- ── SESSION IDLE TIMEOUT MODAL ──────────────────────────── -->
<div id="idle-overlay" style="display:none;position:fixed;inset:0;background:rgba(7,14,7,0.85);backdrop-filter:blur(6px);z-index:9999;align-items:center;justify-content:center">
  <div style="background:var(--surface-1);border:1px solid rgba(201,168,76,0.3);border-radius:16px;padding:2.5rem 2rem;max-width:360px;width:90%;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,0.5)">
    <div style="width:56px;height:56px;border-radius:50%;background:rgba(201,168,76,0.1);border:1px solid rgba(201,168,76,0.25);display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem">
      <i class="bi bi-shield-lock" style="font-size:1.5rem;color:var(--gold)"></i>
    </div>
    <div style="font-family:'DM Mono',monospace;font-size:0.58rem;color:var(--lime);letter-spacing:0.2em;text-transform:uppercase;margin-bottom:0.5rem">Session Security</div>
    <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.6rem;color:var(--cream);margin:0 0 0.5rem">Still there?</h3>
    <p style="font-size:0.82rem;color:var(--text-secondary);line-height:1.6;margin:0 0 1.25rem">You've been inactive for a while. Your session will expire in</p>
    <div id="idle-countdown" style="font-family:'Cormorant Garamond',serif;font-size:3.5rem;font-weight:700;color:var(--gold);line-height:1;margin-bottom:1.5rem">60</div>
    <div style="display:flex;flex-direction:column;gap:0.6rem">
      <button onclick="idleKeepAlive()" style="width:100%;padding:0.75rem;background:var(--mid);border:none;border-radius:8px;color:#fff;font-family:'DM Sans',sans-serif;font-size:0.9rem;font-weight:600;cursor:pointer;transition:background 0.2s" onmouseover="this.style.background='var(--bright)'" onmouseout="this.style.background='var(--mid)'">
        <i class="bi bi-check-circle" style="margin-right:0.4rem"></i>Yes, keep me logged in
      </button>
      <a href="<?= SITE_URL ?>/auth/logout.php" style="width:100%;padding:0.65rem;background:transparent;border:1px solid var(--border);border-radius:8px;color:var(--text-secondary);font-family:'DM Sans',sans-serif;font-size:0.82rem;text-decoration:none;display:block;transition:all 0.2s" onmouseover="this.style.borderColor='rgba(201,168,76,0.3)';this.style.color='var(--cream)'" onmouseout="this.style.borderColor='';this.style.color=''">
        Log out now
      </a>
    </div>
  </div>
</div>

<script>
(function() {
  const WARN_AFTER   = 4 * 60 * 1000;   // 4 min → show modal
  const LOGOUT_AFTER = 5 * 60 * 1000;   // 5 min → force logout
  const PING_URL     = '<?= SITE_URL ?>/auth/session-ping.php';
  const LOGOUT_URL   = '<?= SITE_URL ?>/auth/logout.php?reason=timeout';

  let idleTimer, countdownTimer, countdownSec;
  const overlay    = document.getElementById('idle-overlay');
  const countdownEl = document.getElementById('idle-countdown');

  function resetIdle() {
    clearTimeout(idleTimer);
    clearInterval(countdownTimer);
    if (overlay) overlay.style.display = 'none';
    idleTimer = setTimeout(showWarning, WARN_AFTER);
  }

  function showWarning() {
    if (!overlay) return;
    countdownSec = Math.round((LOGOUT_AFTER - WARN_AFTER) / 1000);
    countdownEl.textContent = countdownSec;
    overlay.style.display = 'flex';
    countdownTimer = setInterval(function() {
      countdownSec--;
      countdownEl.textContent = countdownSec;
      if (countdownSec <= 10) countdownEl.style.color = '#ef5350';
      if (countdownSec <= 0) {
        clearInterval(countdownTimer);
        window.location.href = LOGOUT_URL;
      }
    }, 1000);
  }

  window.idleKeepAlive = function() {
    fetch(PING_URL, { method: 'POST' })
      .then(r => r.json())
      .then(d => {
        if (d.ok) { resetIdle(); }
        else { window.location.href = LOGOUT_URL; }
      })
      .catch(() => resetIdle());
  };

  // Activity events that reset the idle timer
  ['mousemove','mousedown','keydown','scroll','touchstart','click'].forEach(function(ev) {
    document.addEventListener(ev, resetIdle, { passive: true });
  });

  resetIdle(); // start the timer on page load
})();
</script>

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
          <i class="bi bi-stars sidebar-icon"></i><span>PrimoAI</span>
          <span class="sidebar-badge">NEW</span>
        </a>
      </div>

      <!-- OVERVIEW -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Overview</span>
        <?= nav_link('/portal/dashboard.php', 'house',         'Dashboard',      $current_page) ?>
        <?= nav_link('/portal/profile.php',   'person-circle','My Profile',     $current_page) ?>
        <?= nav_link('/portal/pricing.php',   'tag',          'Plans & Pricing',$current_page) ?>
      </div>

      <!-- MY FINANCES -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">My Finances</span>
        <?= nav_link('/portal/portfolio.php',   'pie-chart',       'Portfolio',  $current_page) ?>
        <?= nav_link('/portal/goals.php',       'bullseye',        'Goals',      $current_page) ?>
        <?= nav_link('/portal/fd-tracker.php',  'bank',            'FD Tracker', $current_page) ?>
        <?= nav_link('/portal/rebalancer.php',  'arrow-left-right','Rebalancer', $current_page) ?>
      </div>

      <!-- TOOLS -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Tools</span>
        <?= nav_link('/portal/calculators.php', 'calculator', 'Calculators', $current_page) ?>
      </div>

      <!-- ADVISORY -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Advisory</span>
        <?= nav_link('/advisory/mutual-funds.php',     'graph-up-arrow',  'Mutual Funds',     $current_page) ?>
        <?= nav_link('/advisory/fund-compare.php',     'bar-chart-steps', 'Fund Compare',     $current_page) ?>
        <?= nav_link('/advisory/stocks.php',           'search',          'Stock Research',   $current_page) ?>
        <?= nav_link('/advisory/sector-tracker.php',   'compass',         'Sector Tracker',   $current_page) ?>
        <?= nav_link('/advisory/model-portfolios.php', 'briefcase',       'Model Portfolios', $current_page) ?>
        <?= nav_link('/advisory/insights.php',         'newspaper',       'Market Insights',  $current_page) ?>
      </div>

      <!-- WATCHLISTS -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Watchlists</span>
        <?= nav_link('/portal/fund-watchlist.php',  'star',     'Fund Watchlist',  $current_page) ?>
        <?= nav_link('/portal/stock-watchlist.php', 'graph-up', 'Stock Watchlist', $current_page) ?>
      </div>

      <!-- DOCUMENTS -->
      <div class="sidebar-group">
        <span class="sidebar-group-label">Documents</span>
        <?= nav_link('/portal/documents.php', 'folder2-open', 'My Documents', $current_page) ?>
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
