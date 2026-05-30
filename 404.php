<?php
http_response_code(404);
$site_url = 'http://localhost/primefin_website'; // updated by config if loaded
if (file_exists(__DIR__ . '/includes/config.php')) {
    require_once __DIR__ . '/includes/config.php';
    $site_url = SITE_URL;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Page Not Found — Prime Financials</title>
  <script>(function(){var t=localStorage.getItem('pv-theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})()</script>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@400;500&family=DM+Mono:wght@400&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= $site_url ?>/assets/css/portal.css"/>
  <style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg)}</style>
</head>
<body>
<div style="text-align:center;max-width:480px;padding:2rem">
  <div style="font-family:'Cormorant Garamond',serif;font-size:6rem;color:var(--forest);line-height:1;margin-bottom:1rem">404</div>
  <h1 style="font-family:'Cormorant Garamond',serif;font-size:2rem;color:var(--cream);margin-bottom:0.75rem">Page Not Found</h1>
  <p style="color:var(--text-secondary);margin-bottom:2rem">The page you're looking for doesn't exist or has been moved.</p>
  <div style="display:flex;gap:0.75rem;justify-content:center">
    <a href="<?= $site_url ?>/" class="btn-primary" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.25rem;background:var(--mid);color:#fff;border-radius:7px;text-decoration:none;font-family:'DM Sans',sans-serif;font-size:0.875rem">← Home</a>
    <a href="<?= $site_url ?>/portal/dashboard.php" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.25rem;border:1px solid var(--lime);color:var(--lime);border-radius:7px;text-decoration:none;font-family:'DM Sans',sans-serif;font-size:0.875rem">Portal →</a>
  </div>
</div>
</body>
</html>
