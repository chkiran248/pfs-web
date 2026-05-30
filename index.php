<?php
$page_title = "Prime Financials | Data is Our Power";
$meta_description = "Prime Financials delivers trusted financial intelligence across Mutual Funds, NPS, Insurance, and Fixed Deposits — built for every Indian investor's journey.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $page_title; ?></title>
  <meta name="description" content="<?php echo $meta_description; ?>" />
  <meta property="og:title" content="Prime Financials | Data is Our Power" />
  <meta property="og:description" content="Trusted financial intelligence for every Indian investor." />
  <meta property="og:url" content="https://primefin.in" />

  <!-- Anti-flash: runs before CSS loads, prevents white flash on dark default -->
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
  <style>
    /* Mobile login link — hidden on desktop, shown in hamburger menu */
    .nav__mobile-login { display: none; }
    @media (max-width: 768px) {
      .nav__mobile-login { display: list-item; }
      .nav__mobile-login a { color: #8DC63F !important; font-weight: 500; }
    }
  </style>
</head>
<body>

<!-- NAV -->
<nav class="nav" id="nav">
  <div class="container nav__inner">
    <a href="/" class="nav__logo">
      <img src="logo.png" alt="Prime Financials logo" class="logo-img" />
      <span class="logo-text">Prime Financials</span>
    </a>
    <button class="nav__hamburger" id="hamburger" aria-label="Open menu" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>
    <ul class="nav__links" id="navLinks">
      <li><a href="#products">Products</a></li>
      <li><a href="#intelligence">Intelligence</a></li>
      <li><a href="#approach">Our Approach</a></li>
      <li><a href="#about">About</a></li>
      <li class="nav__mobile-login"><a href="auth/login.php">Client Login →</a></li>
    </ul>
    <div class="nav__actions">
      <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark/light mode">
        <span class="icon-moon">🌙</span>
        <span class="icon-sun">☀️</span>
      </button>
      <a href="auth/login.php" class="btn btn--ghost" style="padding:0.56rem 1.2rem;font-size:0.875rem">Client Login</a>
      <a href="#contact" class="btn btn--nav">Get Started</a>
    </div>
  </div>
</nav>

<!-- HERO -->
<section class="hero" id="home">
  <div class="hero__bg-grid"></div>
  <div class="glow-orb glow-orb--forest"></div>
  <div class="glow-orb glow-orb--lime"></div>
  <div class="container hero__inner">
    <div class="hero__eyebrow">
      <span class="dot"></span>
      SEBI · AMFI REGISTERED &nbsp;|&nbsp; EST. 2016
    </div>
    <h1 class="hero__headline">
      Wealth Intelligence.<br />
      <em>Verified. Trusted.</em>
    </h1>
    <p class="hero__sub">
      In a market saturated with noise, Prime Financials delivers clarity. We provide independent, research-backed financial advisory across Mutual Funds, NPS, Insurance, and Fixed Deposits — built for the discerning Indian investor.
    </p>
    <div class="hero__cta-group">
      <a href="#contact" class="btn btn--primary">Start Your Journey →</a>
      <a href="#products" class="btn btn--ghost">Explore Products</a>
    </div>
    <div class="hero__trust-strip">
      <div class="trust-item">
        <span class="trust-num">₹1000 Lakhs+</span>
        <span class="trust-label">Assets Advised</span>
      </div>
      <div class="trust-divider"></div>
      <div class="trust-item">
        <span class="trust-num">200+</span>
        <span class="trust-label">Active Clients</span>
      </div>
      <div class="trust-divider"></div>
      <div class="trust-item">
        <span class="trust-num">10+</span>
        <span class="trust-label">Years of Trust</span>
      </div>
      <div class="trust-divider"></div>
      <div class="trust-item">
        <span class="trust-num">40+</span>
        <span class="trust-label">AMC Partnerships</span>
      </div>
    </div>
  </div>
</section>

<!-- MARQUEE -->
<div class="marquee-section">
  <div class="marquee-track">
    <span>Mutual Funds</span><span class="sep">◆</span>
    <span>SIP Planning</span><span class="sep">◆</span>
    <span>NPS Advisory</span><span class="sep">◆</span>
    <span>Term Insurance</span><span class="sep">◆</span>
    <span>Health Cover</span><span class="sep">◆</span>
    <span>Fixed Deposits</span><span class="sep">◆</span>
    <span>Portfolio Rebalancing</span><span class="sep">◆</span>
    <span>Tax Planning</span><span class="sep">◆</span>
    <span>Goal-Based Investing</span><span class="sep">◆</span>
    <span>Wealth Creation</span><span class="sep">◆</span>
    <span>Mutual Funds</span><span class="sep">◆</span>
    <span>SIP Planning</span><span class="sep">◆</span>
    <span>NPS Advisory</span><span class="sep">◆</span>
    <span>Term Insurance</span><span class="sep">◆</span>
    <span>Health Cover</span><span class="sep">◆</span>
    <span>Fixed Deposits</span><span class="sep">◆</span>
    <span>Portfolio Rebalancing</span><span class="sep">◆</span>
    <span>Tax Planning</span><span class="sep">◆</span>
    <span>Goal-Based Investing</span><span class="sep">◆</span>
    <span>Wealth Creation</span><span class="sep">◆</span>
  </div>
</div>

<!-- INTELLIGENCE -->
<section class="intelligence" id="intelligence">
  <div class="container">
    <div class="section-header">
      <p class="section-eyebrow">WHAT WE DELIVER</p>
      <h2 class="section-title">Financial Intelligence<br /><em>at its Sharpest</em></h2>
      <p class="section-sub">We don't distribute products. We architect financial certainty — combining deep research, regulatory compliance, and human advisory to move investors from uncertainty to confidence.</p>
    </div>
    <div class="intel-grid">
      <div class="intel-card intel-card--large">
        <div class="intel-card__icon">◈</div>
        <h3>Research-Backed Intelligence</h3>
        <p>Every recommendation is grounded in quantitative fund analysis, risk-adjusted return modelling, and macro-economic context — not commissions or distributor incentives.</p>
        <div class="intel-card__tag">INDEPENDENT ADVISORY</div>
      </div>
      <div class="intel-card">
        <div class="intel-card__icon">⬡</div>
        <h3>Real-Time Portfolio Monitoring</h3>
        <p>Your portfolio is tracked continuously against benchmark drift, life-stage goals, and market events. We act before you need to ask.</p>
        <div class="intel-card__tag">PROACTIVE REVIEW</div>
      </div>
      <div class="intel-card">
        <div class="intel-card__icon">◎</div>
        <h3>Compliance-First Advisory</h3>
        <p>Every interaction is conducted within SEBI and AMFI regulatory frameworks. Your interests, protected by law and by principle.</p>
        <div class="intel-card__tag">SEBI COMPLIANT</div>
      </div>
      <div class="intel-card">
        <div class="intel-card__icon">⬢</div>
        <h3>Tax-Optimised Strategies</h3>
        <p>LTCG, STCG, 80C, 80D — we architect your financial decisions around India's tax code to maximise in-hand returns across instruments.</p>
        <div class="intel-card__tag">TAX INTELLIGENCE</div>
      </div>
    </div>
  </div>
</section>

<!-- PRODUCTS -->
<section class="products" id="products">
  <div class="container">
    <div class="section-header">
      <p class="section-eyebrow">OUR PRODUCTS</p>
      <h2 class="section-title">Every Instrument.<br /><em>One Trusted Vault.</em></h2>
      <p class="section-sub">From first SIP to retirement corpus, we manage every dimension of your financial life with precision and purpose.</p>
    </div>
    <div class="products-list">

      <div class="product-row">
        <div class="product-row__content">
          <p class="product-eyebrow">01 — MUTUAL FUNDS</p>
          <h3 class="product-title">India's Most Powerful<br />Wealth-Building Engine</h3>
          <p class="product-desc">We map your risk profile, time horizon, and life goals to a curated portfolio across Equity, Debt, Hybrid, and International funds from 40+ AMCs. Our fund selection process eliminates noise — you get only what belongs in your vault.</p>
          <ul class="product-features">
            <li>✦ SIP, Lumpsum &amp; STP Strategies</li>
            <li>✦ ELSS for Section 80C Tax Savings</li>
            <li>✦ Flexi-Cap, Mid-Cap &amp; Thematic Funds</li>
            <li>✦ Direct Fund access via BSE StarMF &amp; MFCentral</li>
          </ul>
          <a href="#contact" class="btn btn--outline">Explore Mutual Funds →</a>
        </div>
        <div class="product-row__visual">
          <div class="visual-card">
            <div class="visual-card__label">PORTFOLIO SNAPSHOT</div>
            <div class="bar-chart">
              <div class="bar-item"><span class="bar-name">Equity Funds</span><div class="bar-track"><div class="bar-fill bar-fill--1" style="width:65%">65%</div></div></div>
              <div class="bar-item"><span class="bar-name">Debt Funds</span><div class="bar-track"><div class="bar-fill bar-fill--2" style="width:20%">20%</div></div></div>
              <div class="bar-item"><span class="bar-name">Hybrid Funds</span><div class="bar-track"><div class="bar-fill bar-fill--3" style="width:10%">10%</div></div></div>
              <div class="bar-item"><span class="bar-name">International</span><div class="bar-track"><div class="bar-fill bar-fill--4" style="width:5%">5%</div></div></div>
            </div>
            <div class="visual-card__footer">Illustrative allocation · Risk-adjusted</div>
          </div>
        </div>
      </div>

      <div class="product-row product-row--reverse">
        <div class="product-row__content">
          <p class="product-eyebrow">02 — NPS</p>
          <h3 class="product-title">Retirement Clarity.<br />Decades in Advance.</h3>
          <p class="product-desc">The National Pension System is India's most tax-efficient retirement vehicle — yet most investors underutilise it. We architect your NPS allocation, Tier I/II strategy, and annual top-ups to maximise the ₹2 lakh annual tax deduction.</p>
          <ul class="product-features">
            <li>✦ Section 80CCD(1B) — Additional ₹50,000 Deduction</li>
            <li>✦ Active vs Auto Asset Allocation Guidance</li>
            <li>✦ Pension Fund Manager Selection</li>
            <li>✦ Annuity Planning at Maturity</li>
          </ul>
          <a href="#contact" class="btn btn--outline">Explore NPS →</a>
        </div>
        <div class="product-row__visual">
          <div class="visual-card">
            <div class="visual-card__label">NPS CORPUS PROJECTION</div>
            <div class="projection-stat">
              <span class="proj-age">Age 30 → 60</span>
              <span class="proj-amount">₹1.2 Cr+</span>
              <span class="proj-note">At ₹5,000/month · 10% CAGR assumed</span>
            </div>
            <div class="nps-breakdown">
              <div class="nps-item"><span>Equity (E)</span><span class="nps-pct">75%</span></div>
              <div class="nps-item"><span>Government Bonds (G)</span><span class="nps-pct">15%</span></div>
              <div class="nps-item"><span>Corporate Bonds (C)</span><span class="nps-pct">10%</span></div>
            </div>
            <div class="visual-card__footer">Illustrative · Actual returns may vary</div>
          </div>
        </div>
      </div>

      <div class="product-row">
        <div class="product-row__content">
          <p class="product-eyebrow">03 — LIFE &amp; HEALTH INSURANCE</p>
          <h3 class="product-title">Protection That Doesn't<br />Compromise Your Wealth.</h3>
          <p class="product-desc">Insurance is the foundation of every sound financial plan. We assess your protection gap, recommend pure term cover calibrated to your income and liabilities, and select health policies that keep medical inflation from eroding your wealth.</p>
          <ul class="product-features">
            <li>✦ Term Insurance — Pure Risk Cover</li>
            <li>✦ Critical Illness &amp; Disability Riders</li>
            <li>✦ Family Floater &amp; Individual Health Plans</li>
            <li>✦ Super Top-Up for Enhanced Coverage</li>
          </ul>
          <a href="#contact" class="btn btn--outline">Explore Insurance →</a>
        </div>
        <div class="product-row__visual">
          <div class="visual-card">
            <div class="visual-card__label">PROTECTION AUDIT</div>
            <div class="protection-grid">
              <div class="protection-item protection-item--ok"><span class="p-icon">✓</span><span>Term Cover</span><span class="p-status">₹1 Cr</span></div>
              <div class="protection-item protection-item--warn"><span class="p-icon">⚠</span><span>Health Cover</span><span class="p-status">Upgrade</span></div>
              <div class="protection-item protection-item--missing"><span class="p-icon">✕</span><span>Critical Illness</span><span class="p-status">Missing</span></div>
              <div class="protection-item protection-item--ok"><span class="p-icon">✓</span><span>Accidental Cover</span><span class="p-status">Active</span></div>
            </div>
            <div class="visual-card__footer">Sample audit · Free for all clients</div>
          </div>
        </div>
      </div>

      <div class="product-row product-row--reverse">
        <div class="product-row__content">
          <p class="product-eyebrow">04 — FIXED DEPOSITS &amp; DEBT</p>
          <h3 class="product-title">Capital Preservation.<br />Intelligently Structured.</h3>
          <p class="product-desc">Not every rupee belongs in equity. We design FD laddering strategies, identify high-yield AAA-rated corporate deposits, and integrate debt instruments into your portfolio to ensure liquidity, capital protection, and predictable returns.</p>
          <ul class="product-features">
            <li>✦ Bank &amp; Corporate FD Comparison</li>
            <li>✦ Ladder Strategy for Liquidity Planning</li>
            <li>✦ Senior Citizen Schemes (SCSS, PMVVY)</li>
            <li>✦ Short-Duration &amp; Liquid Mutual Funds as Alternatives</li>
          </ul>
          <a href="#contact" class="btn btn--outline">Explore Fixed Deposits →</a>
        </div>
        <div class="product-row__visual">
          <div class="visual-card">
            <div class="visual-card__label">FD LADDER STRATEGY</div>
            <div class="ladder">
              <div class="ladder-rung"><span class="l-term">6 Months</span><span class="l-rate">7.00% p.a.</span></div>
              <div class="ladder-rung"><span class="l-term">1 Year</span><span class="l-rate">7.50% p.a.</span></div>
              <div class="ladder-rung"><span class="l-term">2 Years</span><span class="l-rate">7.75% p.a.</span></div>
              <div class="ladder-rung ladder-rung--top"><span class="l-term">3 Years</span><span class="l-rate">8.05% p.a.</span></div>
            </div>
            <div class="visual-card__footer">Illustrative · Rates vary by institution</div>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- APPROACH -->
<section class="approach" id="approach">
  <div class="container">
    <div class="section-header">
      <p class="section-eyebrow">HOW WE WORK</p>
      <h2 class="section-title">The Prime Financials<br /><em>Method</em></h2>
      <p class="section-sub">A structured, four-stage process that transforms financial complexity into a clear, personalised wealth roadmap.</p>
    </div>
    <div class="approach-steps">
      <div class="step-card">
        <div class="step-num">01</div>
        <h3>Discovery &amp; Risk Profiling</h3>
        <p>We begin with a comprehensive financial discovery — income, liabilities, goals, timelines, and risk appetite. No generic forms. A real conversation.</p>
      </div>
      <div class="step-connector">→</div>
      <div class="step-card">
        <div class="step-num">02</div>
        <h3>Vault Architecture</h3>
        <p>We design your personalised financial vault — an optimal allocation across Mutual Funds, NPS, Insurance, and FDs, calibrated to your specific life stage.</p>
      </div>
      <div class="step-connector">→</div>
      <div class="step-card">
        <div class="step-num">03</div>
        <h3>Execution &amp; Onboarding</h3>
        <p>Seamless KYC, fund onboarding via BSE StarMF or MFCentral, insurance placement, and NPS activation — all managed end-to-end by our team.</p>
      </div>
      <div class="step-connector">→</div>
      <div class="step-card">
        <div class="step-num">04</div>
        <h3>Continuous Intelligence</h3>
        <p>Quarterly portfolio reviews, rebalancing alerts, market event communication, and goal tracking — delivered with the rigour of institutional advisory.</p>
      </div>
    </div>
  </div>
</section>

<!-- TESTIMONIALS -->
<section class="testimonials">
  <div class="container">
    <div class="section-header">
      <p class="section-eyebrow">CLIENT VOICES</p>
      <h2 class="section-title">Trusted by Investors<br /><em>Across India</em></h2>
    </div>
    <div class="testimonials-grid">
      <div class="testi-card">
        <div class="testi-quote">"</div>
        <p class="testi-text">Prime Financials didn't just manage my SIPs — they showed me exactly why each fund was chosen. That transparency changed how I think about investing.</p>
        <div class="testi-author">
          <div class="testi-avatar">RK</div>
          <div><div class="testi-name">Ramesh K.</div><div class="testi-role">IT Professional, Bengaluru</div></div>
        </div>
      </div>
      <div class="testi-card testi-card--featured">
        <div class="testi-quote">"</div>
        <p class="testi-text">After years of investing randomly, Prime Financials built a proper financial plan — covering my retirement via NPS, my family's health cover, and my wealth via mutual funds. One call changed everything.</p>
        <div class="testi-author">
          <div class="testi-avatar">PS</div>
          <div><div class="testi-name">Priya S.</div><div class="testi-role">Business Owner, Mumbai</div></div>
        </div>
      </div>
      <div class="testi-card">
        <div class="testi-quote">"</div>
        <p class="testi-text">My FD portfolio was idle for years. Prime Financials restructured it with a ladder strategy and moved some into liquid funds. My returns improved without increasing risk.</p>
        <div class="testi-author">
          <div class="testi-avatar">AV</div>
          <div><div class="testi-name">Ashok V.</div><div class="testi-role">Retired Professional, Chennai</div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ABOUT -->
<section class="about" id="about">
  <div class="container about__inner">
    <div class="about__content">
      <p class="section-eyebrow">WHO WE ARE</p>
      <h2 class="section-title"><br /><em>Prime Financial Services</em></h2>
      <p class="about__text">Founded on the principle that every Indian investor deserves institutional-quality advice, Prime Financials is the wealth intelligence arm of Prime Financial Services — a SEBI and AMFI registered advisory practice serving clients across India.</p>
      <p class="about__text">We are not a platform. We are not an algorithm. We are advisors — with skin in the game, bound by fiduciary duty, and accountable to every client relationship we build.</p>
      <div class="about__certifications">
        <div class="cert-badge">AMFI Registered<br />Mutual Fund Distributor</div>
        <div class="cert-badge">IRDAI Licensed<br />Insurance Advisor</div>
        <div class="cert-badge">NPS<br />Point of Presence</div>
      </div>
    </div>
    <div class="about__manifesto">
      <blockquote class="manifesto-quote">
        "In an age of infinite financial products and infinite noise, the rarest commodity is a trusted advisor who tells you what you need — not what's most profitable to sell."
      </blockquote>
      <cite>— Prime Financials Advisory Principle</cite>
    </div>
  </div>
</section>

<!-- CONTACT -->
<section class="contact" id="contact">
  <div class="container contact__inner">
    <div class="contact__content">
      <p class="section-eyebrow">GET STARTED</p>
      <h2 class="section-title">Open Your<br /><em>Prime Financials</em></h2>
      <p class="contact__sub">A 30-minute discovery call is all it takes to begin. No obligations, no product push — just clarity on where your wealth stands and where it should go.</p>
      <div class="contact__info">
        <a href="https://primefin.in" class="contact-link">🌐 primefin.in</a>
        <a href="tel:+919980001338" class="contact-link">📞 +91 9980001338</a>
        <a href="mailto:support@primefin.in" class="contact-link">✉ support@primefin.in</a>
      </div>
    </div>
    <form class="contact-form" method="POST" action="contact.php">
      <div class="form-group">
        <label for="name">Full Name</label>
        <input type="text" id="name" name="name" placeholder="Your name" required />
      </div>
      <div class="form-group">
        <label for="phone">Mobile Number</label>
        <input type="tel" id="phone" name="phone" placeholder="+91 98765 43210" required />
      </div>
      <div class="form-group">
        <label for="interest">Primary Interest</label>
        <select id="interest" name="interest">
          <option value="">Select a product</option>
          <option>Mutual Funds &amp; SIP</option>
          <option>NPS / Retirement Planning</option>
          <option>Life &amp; Health Insurance</option>
          <option>Fixed Deposits</option>
          <option>Complete Wealth Planning</option>
        </select>
      </div>
      <div class="form-group">
        <label for="message">Tell us about your goals (optional)</label>
        <textarea id="message" name="message" rows="3" placeholder="e.g. I want to start a ₹10,000/month SIP for my child's education..."></textarea>
      </div>
      <button type="submit" class="btn btn--primary btn--full">Request a Discovery Call →</button>
      <p class="form-note">Your data is confidential and never shared. By submitting, you consent to being contacted by Prime Financials advisors.</p>
    </form>
  </div>
</section>

<!-- FOOTER -->
<footer class="footer">
  <div class="container footer__inner">
    <div class="footer__brand">
      <a href="/" class="nav__logo">
        <img src="logo.png" alt="Prime Financials" class="logo-img logo-img--footer" />
        <span class="logo-text">Prime Financials</span>
      </a>
      <p class="footer__tagline">Data is Our Power</p>
      <p class="footer__legal-short">A brand of Prime Financial Services · AMFI Registered · primefin.in</p>
    </div>
    <div class="footer__links">
      <div class="footer__col">
        <h4>Products</h4>
        <ul>
          <li><a href="#products">Mutual Funds</a></li>
          <li><a href="#products">NPS</a></li>
          <li><a href="#products">Insurance</a></li>
          <li><a href="#products">Fixed Deposits</a></li>
        </ul>
      </div>
      <div class="footer__col">
        <h4>Company</h4>
        <ul>
          <li><a href="#about">About Us</a></li>
          <li><a href="#approach">Our Approach</a></li>
          <li><a href="#contact">Contact</a></li>
          <li><a href="https://primefin.in">primefin.in</a></li>
        </ul>
      </div>
      <div class="footer__col">
        <h4>Compliance</h4>
        <ul>
          <li><a href="#">AMFI Registration</a></li>
          <li><a href="#">Privacy Policy</a></li>
          <li><a href="#">Disclaimer</a></li>
          <li><a href="#">Grievance Redressal</a></li>
        </ul>
      </div>
    </div>
  </div>
  <div class="footer__disclaimer">
    <div class="container">
      <p>Mutual Fund investments are subject to market risks. Please read all scheme-related documents carefully before investing. Past performance is not indicative of future returns. Insurance is subject to the terms and conditions of the respective policies. Prime Financials / Prime Financial Services is registered with AMFI (ARN-XXXXXX). This website does not constitute investment advice or solicitation. Please consult a qualified financial advisor before making investment decisions.</p>
    </div>
  </div>
  <div class="footer__bottom">
    <div class="container">
      <p>© <?php echo date('Y'); ?> Prime Financial Services. All rights reserved. · <a href="https://primefin.in">primefin.in</a></p>
    </div>
  </div>
</footer>

<script src="main.js"></script>
</body>
</html>
