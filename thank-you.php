<?php $page_title = "Thank You — Prime Financials"; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $page_title; ?></title>
  <script>(function(){var t=localStorage.getItem('pv-theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})()</script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;1,600&family=Inter:wght@400;500&family=IBM+Plex+Mono:wght@400&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="style.css" />
  <style>
    .ty-page{min-height:100vh;display:flex;align-items:center;justify-content:center;text-align:center;padding:2rem;position:relative;}
    .ty-box{max-width:540px;position:relative;z-index:1;}
    .ty-icon{font-size:2.8rem;margin-bottom:1.5rem;display:block;}
    .ty-box h1{font-family:'Cormorant Garamond',serif;font-size:clamp(2rem,4vw,3rem);color:var(--cream);margin-bottom:1rem;line-height:1.2;}
    .ty-box h1 em{color:var(--lime);font-style:italic;}
    .ty-box p{color:var(--text-secondary);line-height:1.8;margin-bottom:1.75rem;font-size:1rem;}
    .ty-note{font-family:'IBM Plex Mono',monospace;font-size:0.7rem;letter-spacing:0.06em;color:var(--text-muted);border:1px solid var(--border);padding:1rem 1.5rem;border-radius:8px;background:var(--surface-1);margin-bottom:2rem;line-height:1.7;}
  </style>
</head>
<body>
  <nav class="nav">
    <div class="container nav__inner">
      <a href="/" class="nav__logo">
        <img src="logo.png" alt="Prime Financials" class="logo-img" />
        <span class="logo-text">Prime Financials</span>
      </a>
    </div>
  </nav>
  <div class="glow-orb glow-orb--forest"></div>
  <div class="glow-orb glow-orb--lime"></div>
  <section class="ty-page">
    <div class="ty-box">
      <span class="ty-icon">✦</span>
      <h1>Your Vault<br /><em>Is Being Opened.</em></h1>
      <p>Thank you for reaching out. One of our advisors will contact you within 24 hours to schedule your complimentary discovery call.</p>
      <div class="ty-note">Your information is confidential and protected. We do not share client data with any third parties.</div>
      <a href="/" class="btn btn--primary">← Return to Prime Financials</a>
    </div>
  </section>
  <script src="main.js"></script>
</body>
</html>
