<?php
http_response_code(500);
$site_url = 'http://localhost/primefin_website';
if (file_exists(__DIR__ . '/includes/config.php')) {
    @require_once __DIR__ . '/includes/config.php';
    if (defined('SITE_URL')) $site_url = SITE_URL;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Server Error — Prime Financials</title>
  <script>(function(){var t=localStorage.getItem('pv-theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})()</script>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=Inter:wght@400;500&family=IBM+Plex+Mono:wght@400&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= $site_url ?>/assets/css/portal.css"/>
  <style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg)}</style>
</head>
<body>
<div style="text-align:center;max-width:480px;padding:2rem">
  <div style="font-family:'Cormorant Garamond',serif;font-size:6rem;color:var(--danger);line-height:1;margin-bottom:1rem;opacity:0.5">500</div>
  <h1 style="font-family:'Cormorant Garamond',serif;font-size:2rem;color:var(--cream);margin-bottom:0.75rem">Something Went Wrong</h1>
  <p style="color:var(--text-secondary);margin-bottom:0.5rem">We're experiencing a temporary issue. Our team has been notified.</p>
  <p style="color:var(--text-secondary);margin-bottom:2rem;font-size:0.875rem">If this persists, please contact <a href="mailto:support@primefin.in" style="color:var(--lime)">support@primefin.in</a></p>
  <a href="<?= $site_url ?>/" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.25rem;background:var(--mid);color:#fff;border-radius:7px;text-decoration:none;font-family:'Inter',sans-serif;font-size:0.875rem">← Go Home</a>
</div>
</body>
</html>
