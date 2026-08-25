<?php
// documentation.php — Prime Financials Documentation Center (public, no login required)

$site_url     = 'http://localhost/primefin_website';
$whatsapp_num = '919980001338';
if (file_exists(__DIR__ . '/includes/config.php')) {
    require_once __DIR__ . '/includes/config.php';
    $site_url     = SITE_URL;
    $whatsapp_num = defined('WHATSAPP_NUM') ? WHATSAPP_NUM : $whatsapp_num;
}

// ── Sidebar structure: category => { label, icon, pages: { slug => title } } ──
$DOC_NAV = [
    'getting-started' => [
        'label' => 'Getting Started',
        'icon'  => 'rocket-takeoff',
        'pages' => [
            'introduction'     => 'Introduction',
            'creating-account' => 'Creating Your Account',
            'quick-start'      => 'Quick Start',
        ],
    ],
    'whats-new' => [
        'label' => "What's New",
        'icon'  => 'megaphone',
        'pages' => [
            'changelog' => "What's New",
        ],
    ],
    'documents-statements' => [
        'label' => 'Documents & Statements',
        'icon'  => 'file-earmark-text',
        'pages' => [
            'cas-nsdl-statement'   => 'Get Your CAS / NSDL Statement',
            'uploading-documents'  => 'Uploading Documents with PrimoAI',
            'file-types-passwords' => 'Supported Files & Passwords',
        ],
    ],
    'portfolio-planning' => [
        'label' => 'Portfolio & Planning',
        'icon'  => 'graph-up-arrow',
        'pages' => [
            'portfolio-tracking' => 'Tracking Your Portfolio',
            'goals'              => 'Setting Financial Goals',
            'rebalancing'        => 'Rebalancing Your Portfolio',
            'watchlists'         => 'Fund & Stock Watchlists',
            'fd-tracker'         => 'FD Tracker',
            'overlap-analyzer'   => 'Overlap Analyzer',
        ],
    ],
    'calculators-tools' => [
        'label' => 'Calculators & Tools',
        'icon'  => 'calculator',
        'pages' => [
            'sip-calculator'    => 'SIP Calculator',
            'tax-tools'         => 'Tax Calculator & Tax Modeler',
            'nps-projector'     => 'NPS Projector',
            'insurance-checker' => 'Insurance Checker',
            'risk-assessment'   => 'Risk Assessment Quiz',
            'cashflow-modeler'  => 'Cashflow Modeler',
        ],
    ],
    'advisory-research' => [
        'label' => 'Advisory & Research',
        'icon'  => 'search',
        'pages' => [
            'mutual-fund-research'      => 'Mutual Fund Research & Comparison',
            'stock-research'            => 'Stock Research & Sector Tracker',
            'model-portfolios-insights' => 'Model Portfolios & Market Insights',
        ],
    ],
    'primoai' => [
        'label' => 'PrimoAI Assistant',
        'icon'  => 'robot',
        'pages' => [
            'primoai-overview' => 'What is PrimoAI',
            'primoai-chat'     => 'Chatting & Getting Recommendations',
        ],
    ],
    'account-billing' => [
        'label' => 'Account & Billing',
        'icon'  => 'credit-card',
        'pages' => [
            'plans-pricing'    => 'Plans & Pricing',
            'coupons'          => 'Redeeming a Coupon',
            'profile-security' => 'Profile & Security Settings',
        ],
    ],
    'legal-compliance' => [
        'label' => 'Legal & Compliance',
        'icon'  => 'shield-check',
        'pages' => [
            'amfi-compliance'     => 'AMFI Registration & ARN',
            'privacy-policy'      => 'Privacy Policy',
            'disclaimer'          => 'Disclaimer',
            'grievance-redressal' => 'Grievance Redressal',
        ],
    ],
];

// ── Flatten to slug => [category, title] and resolve the active page ──
$slug_map = [];
foreach ($DOC_NAV as $cat_key => $cat) {
    foreach ($cat['pages'] as $slug => $title) {
        $slug_map[$slug] = ['category' => $cat_key, 'title' => $title];
    }
}

$requested   = isset($_GET['page']) ? (string) $_GET['page'] : 'introduction';
$active_slug = array_key_exists($requested, $slug_map) ? $requested : 'introduction';
$active      = $slug_map[$active_slug];
$active_cat  = $DOC_NAV[$active['category']];

$content_file = __DIR__ . '/documentation/content/' . $active_slug . '.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($active['title']) ?> — Documentation — Prime Financials</title>
  <meta name="description" content="Prime Financials documentation — guides for portfolio tracking, PrimoAI, calculators, advisory research, and your account." />

  <script>
    (function() {
      var t = localStorage.getItem('pv-theme');
      if (t === 'light') document.documentElement.setAttribute('data-theme', 'light');
    })();
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,600&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css" />
  <link rel="stylesheet" href="assets/css/documentation.css" />
</head>
<body>

<!-- NAV -->
<nav class="nav" id="nav">
  <div class="container nav__inner">
    <a href="<?= htmlspecialchars($site_url) ?>/" class="nav__logo">
      <img src="logo.png" alt="Prime Financials logo" class="logo-img" />
      <span class="logo-text">Prime Financials</span>
    </a>
    <ul class="nav__links" style="margin-left:2rem">
      <li><a href="<?= htmlspecialchars($site_url) ?>/">← Home</a></li>
      <li><a href="documentation.php" style="color:var(--lime)">Documentation</a></li>
    </ul>
    <div class="nav__actions">
      <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark/light mode">
        <span class="icon-moon">🌙</span>
        <span class="icon-sun">☀️</span>
      </button>
      <a href="<?= htmlspecialchars($site_url) ?>/auth/login.php" class="btn btn--ghost" style="padding:0.56rem 1.2rem;font-size:0.875rem">Client Login</a>
    </div>
  </div>
</nav>

<div class="docs-body">
  <div class="docs-layout container">

    <aside class="docs-sidebar">
      <?php foreach ($DOC_NAV as $cat_key => $cat): ?>
        <details class="docs-group"<?= $cat_key === $active['category'] ? ' open' : '' ?>>
          <summary>
            <i class="bi bi-<?= htmlspecialchars($cat['icon']) ?> docs-group-icon"></i>
            <span><?= htmlspecialchars(strtoupper($cat['label'])) ?></span>
            <i class="bi bi-chevron-right docs-chevron"></i>
          </summary>
          <div class="docs-links">
            <?php foreach ($cat['pages'] as $slug => $title): ?>
              <a href="documentation.php?page=<?= urlencode($slug) ?>"<?= $slug === $active_slug ? ' class="active"' : '' ?>><?= htmlspecialchars($title) ?></a>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>

      <div class="docs-help-card">
        <h5><i class="bi bi-headset"></i> Need Help?</h5>
        <p>Our team is ready to help you succeed in every part of your financial journey.</p>
        <a href="https://wa.me/<?= htmlspecialchars($whatsapp_num) ?>?text=Hi%2C+I+have+a+question+about+the+documentation"
           class="btn btn--outline btn--full" target="_blank" rel="noopener noreferrer">
          <i class="bi bi-whatsapp"></i> Chat with Advisor
        </a>
      </div>
    </aside>

    <main class="docs-content">
      <article class="docs-article">
        <div class="hero__eyebrow docs-eyebrow">
          <i class="bi bi-<?= htmlspecialchars($active_cat['icon']) ?>"></i>
          <?= htmlspecialchars(strtoupper($active_cat['label'])) ?>
        </div>
        <h1><?= htmlspecialchars($active['title']) ?></h1>
        <?php if (is_file($content_file)) { include $content_file; } else { ?>
          <p>This guide is coming soon.</p>
        <?php } ?>
      </article>
    </main>

  </div>
</div>

<script>
  (function() {
    var html = document.documentElement;
    var themeToggle = document.getElementById('themeToggle');
    themeToggle.addEventListener('click', function() {
      var isLight = html.getAttribute('data-theme') === 'light';
      if (isLight) {
        html.removeAttribute('data-theme');
        localStorage.setItem('pv-theme', 'dark');
      } else {
        html.setAttribute('data-theme', 'light');
        localStorage.setItem('pv-theme', 'light');
      }
    });

    document.querySelectorAll('.docs-group').forEach(function(group) {
      group.addEventListener('toggle', function() {
        if (group.open) {
          document.querySelectorAll('.docs-group').forEach(function(other) {
            if (other !== group) other.open = false;
          });
        }
      });
    });
  })();
</script>
</body>
</html>
