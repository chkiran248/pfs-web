<?php
// Shared HTML wrapper for all auth pages.
// Expected variables set by calling page before this include:
//   $page_title (string)
//   $auth_wide  (bool, optional) — use wider card (520px) for register

$auth_wide = $auth_wide ?? false;

// Read and clear flash
$auth_flash = null;
if (!empty($_SESSION['flash'])) {
    $auth_flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($page_title ?? 'Prime Financials', ENT_QUOTES, 'UTF-8') ?></title>
  <script>(function(){var t=localStorage.getItem('pv-theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})()</script>
  <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/portal.css" />
</head>
<body>

<div class="auth-bg">
  <div class="auth-bg-grid"></div>
  <div class="glow-orb glow-orb--forest"></div>
  <div class="glow-orb glow-orb--lime"></div>
</div>

<div class="auth-wrapper">
  <div class="auth-inner<?= $auth_wide ? ' auth-inner--wide' : '' ?>">

    <a href="<?= SITE_URL ?>" class="auth-logo-link">
      <img src="<?= SITE_URL ?>/logo.png" alt="Prime Financials" class="auth-logo-img" />
      <div class="auth-logo-text">
        <span class="auth-logo-name">Prime Financials</span>
        <span class="auth-logo-tagline">Data is Our Power</span>
      </div>
    </a>

    <?php if ($auth_flash): ?>
      <div class="flash-<?= htmlspecialchars($auth_flash['type'], ENT_QUOTES, 'UTF-8') ?>" style="width:100%">
        <?= htmlspecialchars($auth_flash['message'], ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <div class="auth-card">
