<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$db = get_db();

// Latest benchmark values — DB only, no blocking API
$bm = [];
try {
    $bm_stmt = $db->query(
        "SELECT benchmark, nav_value, nav_date
         FROM benchmark_nav
         WHERE benchmark IN ('nifty50','nifty500')
           AND nav_date = (SELECT MAX(nav_date) FROM benchmark_nav)"
    );
    if ($bm_stmt) {
        foreach ($bm_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bm[$r['benchmark']] = $r;
        }
    }
} catch (\PDOException $e) {
    error_log('market-overview DB error: ' . $e->getMessage());
}

// Market status — NSE open Mon–Fri 09:15–15:30 IST
date_default_timezone_set('Asia/Kolkata');
$h   = (int) date('H');
$m   = (int) date('i');
$dow = (int) date('N'); // 1=Mon … 7=Sun
$timeMinutes   = $h * 60 + $m;
$marketOpen    = $dow <= 5 && $timeMinutes >= 555 && $timeMinutes < 930; // 09:15=555, 15:30=930

$page_title = 'Market Overview — Prime Financials';
require_once '../includes/portal-header.php';
?>

<!-- ── PAGE HEADER ─────────────────────────────────────────────── -->
<p class="page-eyebrow">Advisory</p>
<h1 class="page-title">Market Overview</h1>
<p class="page-subtitle">Macro market pulse, FII/DII flows, top movers &amp; news — refreshed on load.</p>

<!-- ══════════════════════════════════════════════════════════════
     SECTION 1 — MARKET PULSE STRIP
══════════════════════════════════════════════════════════════════ -->
<div class="mo-pulse-strip" id="pulse-strip">

  <!-- Nifty 50 -->
  <div class="mo-pulse-item">
    <span class="mo-pulse-label">NIFTY 50</span>
    <?php if (!empty($bm['nifty50'])): ?>
      <span class="mo-pulse-value"><?= number_format((float) $bm['nifty50']['nav_value'], 2) ?></span>
      <span class="mo-pulse-date"><?= htmlspecialchars(date('d M Y', strtotime($bm['nifty50']['nav_date'])), ENT_QUOTES, 'UTF-8') ?></span>
    <?php else: ?>
      <span class="mo-pulse-value mo-dim">—</span>
      <span class="mo-pulse-date">No data</span>
    <?php endif; ?>
  </div>

  <div class="mo-pulse-divider"></div>

  <!-- Nifty 500 -->
  <div class="mo-pulse-item">
    <span class="mo-pulse-label">NIFTY 500</span>
    <?php if (!empty($bm['nifty500'])): ?>
      <span class="mo-pulse-value"><?= number_format((float) $bm['nifty500']['nav_value'], 2) ?></span>
      <span class="mo-pulse-date"><?= htmlspecialchars(date('d M Y', strtotime($bm['nifty500']['nav_date'])), ENT_QUOTES, 'UTF-8') ?></span>
    <?php else: ?>
      <span class="mo-pulse-value mo-dim">—</span>
      <span class="mo-pulse-date">No data</span>
    <?php endif; ?>
  </div>

  <div class="mo-pulse-divider"></div>

  <!-- Market status -->
  <div class="mo-pulse-item">
    <span class="mo-pulse-label">NSE STATUS</span>
    <span class="mo-pulse-value mo-status <?= $marketOpen ? 'mo-status-open' : 'mo-status-closed' ?>">
      <?= $marketOpen ? '● Open' : '● Closed' ?>
    </span>
    <span class="mo-pulse-date"><?= date('h:i A') ?> IST</span>
  </div>

</div>

<!-- ══════════════════════════════════════════════════════════════
     SECTION 2 — FII/DII + TOP MOVERS
══════════════════════════════════════════════════════════════════ -->
<div class="mo-grid-2" style="margin-top:1.5rem">

  <!-- ── FII / DII FLOWS ──────────────────────────────────── -->
  <div class="mo-card" id="fii-card">
    <div class="mo-card-header">
      <span class="mo-eyebrow">FII &amp; DII Activity</span>
      <h2 class="mo-card-title">Institutional Flows</h2>
    </div>

    <div id="fii-loading" class="mo-loading">
      <span class="mo-spinner"></span> Loading flows…
    </div>
    <div id="fii-error" class="mo-error" style="display:none">
      Unable to load flow data. Please try again later.
    </div>
    <div id="fii-content" style="display:none">
      <!-- Summary row injected by JS -->
      <div id="fii-summary" class="mo-flow-summary"></div>
      <!-- Bar chart injected by JS -->
      <div id="fii-bars" class="mo-flow-bars"></div>
      <!-- Table injected by JS -->
      <div class="mo-table-wrap" style="margin-top:1rem">
        <table class="mo-table" id="fii-table">
          <thead>
            <tr>
              <th>Date</th>
              <th class="ta-r">FII Net (Cr)</th>
              <th class="ta-r">DII Net (Cr)</th>
              <th class="ta-r">Net Total (Cr)</th>
            </tr>
          </thead>
          <tbody id="fii-tbody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── TOP MOVERS ──────────────────────────────────────── -->
  <div class="mo-card" id="movers-card">
    <div class="mo-card-header">
      <span class="mo-eyebrow">NSE Equities</span>
      <h2 class="mo-card-title">Top Movers</h2>
    </div>

    <!-- Sub-tabs -->
    <div class="mo-tabs" role="tablist">
      <button class="mo-tab active" data-mover-tab="gainers" role="tab" aria-selected="true">
        <i class="bi bi-arrow-up-circle"></i> Gainers
      </button>
      <button class="mo-tab" data-mover-tab="losers" role="tab" aria-selected="false">
        <i class="bi bi-arrow-down-circle"></i> Losers
      </button>
    </div>

    <div id="movers-loading" class="mo-loading">
      <span class="mo-spinner"></span> Loading movers…
    </div>
    <div id="movers-error" class="mo-error" style="display:none">
      Unable to load movers data. Please try again later.
    </div>
    <div id="movers-content" style="display:none">
      <ul class="mo-mover-list" id="movers-list"></ul>
    </div>
  </div>

</div>

<!-- ══════════════════════════════════════════════════════════════
     SECTION 3 — MARKET NEWS
══════════════════════════════════════════════════════════════════ -->
<div class="mo-card" style="margin-top:1.5rem" id="news-card">
  <div class="mo-card-header">
    <span class="mo-eyebrow">Live Feed</span>
    <h2 class="mo-card-title">Market News</h2>
  </div>

  <div id="news-loading" class="mo-loading">
    <span class="mo-spinner"></span> Fetching latest news…
  </div>
  <div id="news-error" class="mo-error" style="display:none">
    Unable to load news. Please try again later.
  </div>
  <div id="news-content" style="display:none">
    <div class="mo-news-grid" id="news-grid"></div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     SECTION 4 — MACRO INDICATORS
══════════════════════════════════════════════════════════════════ -->
<div class="mo-grid-2" style="margin-top:1.5rem;margin-bottom:2rem">

  <!-- ── RBI & RATES ──────────────────────────────────────── -->
  <div class="mo-card">
    <div class="mo-card-header">
      <span class="mo-eyebrow">RBI Monetary Policy</span>
      <h2 class="mo-card-title">Key Rates</h2>
    </div>

    <div class="mo-rates-grid">
      <div class="mo-rate-item">
        <span class="mo-rate-label">Repo Rate</span>
        <span class="mo-rate-value">6.50%</span>
      </div>
      <div class="mo-rate-item">
        <span class="mo-rate-label">Reverse Repo</span>
        <span class="mo-rate-value">3.35%</span>
      </div>
      <div class="mo-rate-item">
        <span class="mo-rate-label">CRR</span>
        <span class="mo-rate-value">4.00%</span>
      </div>
      <div class="mo-rate-item">
        <span class="mo-rate-label">SLR</span>
        <span class="mo-rate-value">18.00%</span>
      </div>
    </div>

    <p class="mo-data-note">Source: RBI, as of Oct 2024 &nbsp;·&nbsp; Live rates via mfapis coming soon</p>
  </div>

  <!-- ── MARKET BREADTH ───────────────────────────────────── -->
  <div class="mo-card" id="breadth-card">
    <div class="mo-card-header">
      <span class="mo-eyebrow">NSE Breadth</span>
      <h2 class="mo-card-title">Market Breadth</h2>
    </div>

    <div id="breadth-loading" class="mo-loading">
      <span class="mo-spinner"></span> Loading breadth…
    </div>
    <div id="breadth-error" class="mo-error" style="display:none">
      Unable to load breadth data. Please try again later.
    </div>
    <div id="breadth-content" style="display:none">
      <div class="mo-breadth-stats" id="breadth-stats"></div>
      <div class="mo-breadth-bar-wrap">
        <div class="mo-breadth-bar" id="breadth-bar">
          <div class="mo-breadth-seg mo-seg-adv" id="seg-adv"></div>
          <div class="mo-breadth-seg mo-seg-dec" id="seg-dec"></div>
          <div class="mo-breadth-seg mo-seg-unch" id="seg-unch"></div>
        </div>
        <div class="mo-breadth-bar-labels">
          <span style="color:var(--bright)">Advances</span>
          <span style="color:var(--text-muted)">Unchanged</span>
          <span style="color:var(--danger)">Declines</span>
        </div>
      </div>
      <div class="mo-adr" id="breadth-adr"></div>
    </div>
  </div>

</div>

<!-- ══════════════════════════════════════════════════════════════
     PAGE-SCOPED STYLES
══════════════════════════════════════════════════════════════════ -->
<style>
/* ── Pulse Strip ────────────────────────────────────── */
.mo-pulse-strip {
  display: flex;
  align-items: center;
  gap: 0;
  background: var(--surface-1);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 1.25rem 1.5rem;
  flex-wrap: wrap;
  gap: 1.25rem;
}
.mo-pulse-item {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  min-width: 140px;
}
.mo-pulse-label {
  font-family: 'DM Mono', monospace;
  font-size: 0.62rem;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: var(--lime);
}
.mo-pulse-value {
  font-family: 'Cormorant Garamond', Georgia, serif;
  font-size: 1.75rem;
  font-weight: 600;
  color: var(--cream);
  line-height: 1.1;
}
.mo-pulse-date {
  font-size: 0.72rem;
  color: var(--text-secondary);
}
.mo-dim { color: var(--text-muted); }
.mo-pulse-divider {
  width: 1px;
  height: 48px;
  background: var(--border);
  flex-shrink: 0;
}
.mo-status { font-size: 1rem !important; font-family: 'DM Sans', sans-serif !important; }
.mo-status-open   { color: var(--bright); }
.mo-status-closed { color: var(--text-secondary); }

/* ── Cards ──────────────────────────────────────────── */
.mo-card {
  background: var(--surface-1);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 1.5rem;
}
.mo-card-header { margin-bottom: 1.25rem; }
.mo-eyebrow {
  display: block;
  font-family: 'DM Mono', monospace;
  font-size: 0.62rem;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: var(--lime);
  margin-bottom: 0.25rem;
}
.mo-card-title {
  font-family: 'Cormorant Garamond', Georgia, serif;
  font-size: 1.35rem;
  font-weight: 600;
  color: var(--cream);
  margin: 0;
  padding: 0;
  border: none;
}

/* ── 2-col grid ─────────────────────────────────────── */
.mo-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.25rem;
}
@media (max-width: 768px) {
  .mo-grid-2 { grid-template-columns: 1fr; }
  .mo-pulse-divider { display: none; }
}

/* ── Loading & Error states ─────────────────────────── */
.mo-loading {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  color: var(--text-secondary);
  font-size: 0.85rem;
  padding: 1.5rem 0;
}
.mo-error {
  color: var(--danger);
  font-size: 0.85rem;
  padding: 1rem 0;
  border-top: 1px solid rgba(239,83,80,0.15);
}
.mo-spinner {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid var(--border);
  border-top-color: var(--mid);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Tables ─────────────────────────────────────────── */
.mo-table-wrap { overflow-x: auto; }
.mo-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}
.mo-table th {
  background: var(--forest);
  color: var(--cream);
  padding: 0.5rem 0.75rem;
  text-align: left;
  font-weight: 600;
  font-size: 0.75rem;
  letter-spacing: 0.04em;
}
.mo-table td {
  padding: 0.5rem 0.75rem;
  border-bottom: 1px solid var(--border-light);
  color: var(--text-primary);
}
.mo-table tr:nth-child(even) td { background: var(--surface-2); }
.ta-r { text-align: right; }

/* ── FII/DII Summary & Bars ─────────────────────────── */
.mo-flow-summary {
  display: flex;
  gap: 1.5rem;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}
.mo-flow-stat {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}
.mo-flow-stat-label {
  font-family: 'DM Mono', monospace;
  font-size: 0.6rem;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--text-secondary);
}
.mo-flow-stat-val {
  font-family: 'Cormorant Garamond', Georgia, serif;
  font-size: 1.35rem;
  font-weight: 600;
  line-height: 1.1;
}
.mo-pos { color: var(--bright); }
.mo-neg { color: var(--danger); }

.mo-flow-bars { display: flex; flex-direction: column; gap: 0.6rem; }
.mo-flow-bar-row {
  display: grid;
  grid-template-columns: 72px 1fr 1fr;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.78rem;
}
.mo-flow-bar-date {
  color: var(--text-secondary);
  font-family: 'DM Mono', monospace;
  font-size: 0.62rem;
}
.mo-bar-track {
  height: 8px;
  border-radius: 4px;
  background: var(--surface-2);
  overflow: hidden;
}
.mo-bar-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 0.6s ease;
  min-width: 2px;
}
.mo-bar-fii { background: var(--bright); }
.mo-bar-dii { background: var(--gold); }

/* ── Movers ─────────────────────────────────────────── */
.mo-tabs {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
  border-bottom: 1px solid var(--border);
  padding-bottom: 0.5rem;
}
.mo-tab {
  background: transparent;
  border: 1px solid var(--border);
  border-radius: 6px;
  color: var(--text-secondary);
  font-family: 'DM Sans', sans-serif;
  font-size: 0.8rem;
  padding: 0.35rem 0.9rem;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  gap: 0.35rem;
}
.mo-tab:hover { border-color: var(--mid); color: var(--cream); }
.mo-tab.active {
  background: var(--mid);
  border-color: var(--mid);
  color: #fff;
}
.mo-mover-list {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.mo-mover-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.55rem 0.75rem;
  border-radius: 8px;
  background: var(--surface-2);
  border: 1px solid var(--border-light);
}
.mo-mover-name {
  font-size: 0.85rem;
  font-weight: 500;
  color: var(--cream);
}
.mo-mover-ticker {
  font-family: 'DM Mono', monospace;
  font-size: 0.65rem;
  color: var(--text-secondary);
  margin-top: 0.1rem;
}
.mo-mover-right { text-align: right; }
.mo-mover-price {
  font-size: 0.85rem;
  color: var(--text-primary);
  font-family: 'DM Mono', monospace;
}
.mo-change-badge {
  font-family: 'DM Mono', monospace;
  font-size: 0.65rem;
  letter-spacing: 0.08em;
  padding: 0.15rem 0.45rem;
  border-radius: 4px;
  font-weight: 500;
}
.mo-badge-pos { background: rgba(76,175,80,0.15); color: var(--bright); }
.mo-badge-neg { background: rgba(239,83,80,0.12); color: var(--danger); }

/* ── News Grid ──────────────────────────────────────── */
.mo-news-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
}
@media (max-width: 900px) { .mo-news-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .mo-news-grid { grid-template-columns: 1fr; } }

.mo-news-card {
  background: var(--surface-2);
  border: 1px solid var(--border-light);
  border-radius: 10px;
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  transition: border-color 0.2s;
}
.mo-news-card:hover { border-color: var(--mid); }
.mo-news-source {
  font-family: 'DM Mono', monospace;
  font-size: 0.6rem;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--lime);
}
.mo-news-headline {
  font-family: 'Cormorant Garamond', Georgia, serif;
  font-size: 1.05rem;
  font-weight: 600;
  color: var(--cream);
  line-height: 1.3;
  text-decoration: none;
}
.mo-news-headline:hover { color: var(--bright); }
.mo-news-date { font-size: 0.7rem; color: var(--text-secondary); }
.mo-news-summary {
  font-size: 0.8rem;
  color: var(--text-secondary);
  line-height: 1.5;
  flex: 1;
}

/* ── RBI Rates ──────────────────────────────────────── */
.mo-rates-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
  margin-bottom: 1rem;
}
.mo-rate-item {
  background: var(--surface-2);
  border: 1px solid var(--border-light);
  border-radius: 8px;
  padding: 0.9rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.mo-rate-label {
  font-family: 'DM Mono', monospace;
  font-size: 0.62rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--text-secondary);
}
.mo-rate-value {
  font-family: 'Cormorant Garamond', Georgia, serif;
  font-size: 1.55rem;
  font-weight: 600;
  color: var(--gold);
  line-height: 1;
}
.mo-data-note {
  font-size: 0.72rem;
  color: var(--text-muted);
  font-style: italic;
}

/* ── Market Breadth ─────────────────────────────────── */
.mo-breadth-stats {
  display: flex;
  gap: 1.25rem;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}
.mo-breadth-stat {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}
.mo-breadth-stat-label {
  font-family: 'DM Mono', monospace;
  font-size: 0.6rem;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--text-secondary);
}
.mo-breadth-stat-val {
  font-family: 'Cormorant Garamond', Georgia, serif;
  font-size: 1.45rem;
  font-weight: 600;
  line-height: 1;
}
.mo-adv-col { color: var(--bright); }
.mo-dec-col { color: var(--danger); }
.mo-unch-col { color: var(--text-secondary); }

.mo-breadth-bar-wrap { margin-bottom: 0.5rem; }
.mo-breadth-bar {
  display: flex;
  height: 12px;
  border-radius: 6px;
  overflow: hidden;
  background: var(--surface-2);
  margin-bottom: 0.35rem;
}
.mo-breadth-seg {
  height: 100%;
  transition: width 0.7s ease;
}
.mo-seg-adv  { background: var(--bright); }
.mo-seg-dec  { background: var(--danger); }
.mo-seg-unch { background: var(--text-muted); }
.mo-breadth-bar-labels {
  display: flex;
  justify-content: space-between;
  font-size: 0.7rem;
}
.mo-adr {
  margin-top: 1rem;
  font-size: 0.82rem;
  color: var(--text-secondary);
  border-top: 1px solid var(--border-light);
  padding-top: 0.75rem;
}
.mo-adr strong { color: var(--cream); }
</style>

<!-- ══════════════════════════════════════════════════════════════
     JAVASCRIPT — ALL ASYNC FETCHES
══════════════════════════════════════════════════════════════════ -->
<script>
(function () {
  'use strict';

  const BASE = '<?= rtrim(SITE_URL, '/') ?>';

  /* ── Utility: format Indian currency ────────────────── */
  function fmtCr(val) {
    const n = parseFloat(val);
    if (isNaN(n)) return '—';
    const abs = Math.abs(n);
    const sign = n < 0 ? '-' : '+';
    const str = abs >= 10000
      ? (abs / 10000).toFixed(2) + ' K Cr'
      : abs.toFixed(2) + ' Cr';
    return sign + '₹' + str;
  }

  function fmtDate(str) {
    if (!str) return '—';
    const d = new Date(str);
    if (isNaN(d)) return str;
    return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' });
  }

  function truncate(str, len) {
    if (!str) return '';
    return str.length > len ? str.slice(0, len).trimEnd() + '…' : str;
  }

  function show(id)  { const el = document.getElementById(id); if (el) el.style.display = ''; }
  function hide(id)  { const el = document.getElementById(id); if (el) el.style.display = 'none'; }
  function block(id) { const el = document.getElementById(id); if (el) el.style.display = 'block'; }

  /* ─────────────────────────────────────────────────────
     FETCH 1: FII / DII FLOWS
  ───────────────────────────────────────────────────── */
  function loadFiiDii() {
    fetch(BASE + '/api/fii-dii.php')
      .then(r => r.json())
      .then(data => {
        if (data.error && !data.rows?.length) throw new Error(data.error);
        const rows = (data.rows || []).slice(0, 5); // last 5 days
        if (!rows.length) throw new Error('no_data');

        // Compute summary totals
        let fiiTotal = 0, diiTotal = 0;
        rows.forEach(r => {
          fiiTotal += parseFloat(r.fii_net ?? r.fii ?? 0);
          diiTotal += parseFloat(r.dii_net ?? r.dii ?? 0);
        });

        // Summary
        const summaryEl = document.getElementById('fii-summary');
        summaryEl.innerHTML = `
          <div class="mo-flow-stat">
            <span class="mo-flow-stat-label">FII Net (5d)</span>
            <span class="mo-flow-stat-val ${fiiTotal >= 0 ? 'mo-pos' : 'mo-neg'}">${fmtCr(fiiTotal)}</span>
          </div>
          <div class="mo-flow-stat">
            <span class="mo-flow-stat-label">DII Net (5d)</span>
            <span class="mo-flow-stat-val ${diiTotal >= 0 ? 'mo-pos' : 'mo-neg'}">${fmtCr(diiTotal)}</span>
          </div>
          <div class="mo-flow-stat">
            <span class="mo-flow-stat-label">Combined Net</span>
            <span class="mo-flow-stat-val ${(fiiTotal + diiTotal) >= 0 ? 'mo-pos' : 'mo-neg'}">${fmtCr(fiiTotal + diiTotal)}</span>
          </div>`;

        // Bar chart
        const allVals = rows.flatMap(r => [
          Math.abs(parseFloat(r.fii_net ?? r.fii ?? 0)),
          Math.abs(parseFloat(r.dii_net ?? r.dii ?? 0)),
        ]);
        const maxVal = Math.max(...allVals, 1);

        const barsEl = document.getElementById('fii-bars');
        barsEl.innerHTML = rows.map(r => {
          const fiiVal = parseFloat(r.fii_net ?? r.fii ?? 0);
          const diiVal = parseFloat(r.dii_net ?? r.dii ?? 0);
          const fiiPct = Math.min(100, (Math.abs(fiiVal) / maxVal) * 100).toFixed(1);
          const diiPct = Math.min(100, (Math.abs(diiVal) / maxVal) * 100).toFixed(1);
          const dateStr = fmtDate(r.date ?? r.nav_date ?? r.trade_date ?? '');
          return `
            <div class="mo-flow-bar-row" title="FII ${fmtCr(fiiVal)} | DII ${fmtCr(diiVal)}">
              <span class="mo-flow-bar-date">${dateStr}</span>
              <div>
                <div style="font-size:0.65rem;color:var(--lime);margin-bottom:2px;font-family:'DM Mono',monospace">FII ${fmtCr(fiiVal)}</div>
                <div class="mo-bar-track"><div class="mo-bar-fill mo-bar-fii" style="width:${fiiPct}%"></div></div>
              </div>
              <div>
                <div style="font-size:0.65rem;color:var(--gold);margin-bottom:2px;font-family:'DM Mono',monospace">DII ${fmtCr(diiVal)}</div>
                <div class="mo-bar-track"><div class="mo-bar-fill mo-bar-dii" style="width:${diiPct}%"></div></div>
              </div>
            </div>`;
        }).join('');

        // Table
        const tbody = document.getElementById('fii-tbody');
        tbody.innerHTML = rows.map(r => {
          const fiiVal = parseFloat(r.fii_net ?? r.fii ?? 0);
          const diiVal = parseFloat(r.dii_net ?? r.dii ?? 0);
          const net    = fiiVal + diiVal;
          const fmtNum = v => `<span class="${v >= 0 ? 'mo-pos' : 'mo-neg'}">${fmtCr(v)}</span>`;
          return `<tr>
            <td>${fmtDate(r.date ?? r.nav_date ?? r.trade_date ?? '')}</td>
            <td class="ta-r">${fmtNum(fiiVal)}</td>
            <td class="ta-r">${fmtNum(diiVal)}</td>
            <td class="ta-r">${fmtNum(net)}</td>
          </tr>`;
        }).join('');

        hide('fii-loading');
        block('fii-content');
      })
      .catch(() => {
        hide('fii-loading');
        show('fii-error');
      });
  }

  /* ─────────────────────────────────────────────────────
     FETCH 2: TOP MOVERS
  ───────────────────────────────────────────────────── */
  const moversCache = {};
  let activeTab = 'gainers';

  function renderMovers(type, movers) {
    const list = document.getElementById('movers-list');
    if (!movers.length) {
      list.innerHTML = '<li style="color:var(--text-secondary);font-size:0.82rem;padding:0.5rem 0">No data available.</li>';
      return;
    }
    list.innerHTML = movers.slice(0, 8).map(m => {
      const chg  = parseFloat(m.change_pct ?? m.pct_change ?? m.changePercent ?? m.change ?? 0);
      const pos  = chg >= 0;
      const price = m.price ?? m.ltp ?? m.last_price ?? '—';
      const sym  = m.symbol ?? m.ticker ?? m.nse_symbol ?? '';
      const name = m.name ?? m.company_name ?? m.companyName ?? sym;
      return `
        <li class="mo-mover-item">
          <div>
            <div class="mo-mover-name">${escHtml(name)}</div>
            ${sym ? `<div class="mo-mover-ticker">${escHtml(sym)}</div>` : ''}
          </div>
          <div class="mo-mover-right">
            <div class="mo-mover-price">₹${escHtml(String(price))}</div>
            <span class="mo-change-badge ${pos ? 'mo-badge-pos' : 'mo-badge-neg'}">${pos ? '+' : ''}${chg.toFixed(2)}%</span>
          </div>
        </li>`;
    }).join('');
  }

  function loadMovers(type) {
    if (moversCache[type]) {
      renderMovers(type, moversCache[type]);
      hide('movers-loading');
      block('movers-content');
      return;
    }
    hide('movers-content');
    show('movers-loading');
    hide('movers-error');

    fetch(BASE + '/api/market-movers.php?type=' + type)
      .then(r => r.json())
      .then(data => {
        if (data.error && !data.movers?.length) throw new Error(data.error);
        const list = data.movers || [];
        moversCache[type] = list;
        renderMovers(type, list);
        hide('movers-loading');
        block('movers-content');
      })
      .catch(() => {
        hide('movers-loading');
        show('movers-error');
      });
  }

  document.querySelectorAll('[data-mover-tab]').forEach(btn => {
    btn.addEventListener('click', function () {
      document.querySelectorAll('[data-mover-tab]').forEach(b => {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
      });
      this.classList.add('active');
      this.setAttribute('aria-selected', 'true');
      activeTab = this.dataset.moverTab;
      loadMovers(activeTab);
    });
  });

  /* ─────────────────────────────────────────────────────
     FETCH 3: MARKET NEWS
  ───────────────────────────────────────────────────── */
  function loadNews() {
    fetch(BASE + '/api/market-news.php?limit=12')
      .then(r => r.json())
      .then(data => {
        if (data.error && !data.news?.length) throw new Error(data.error);
        const news = data.news || [];
        if (!news.length) throw new Error('no_data');

        const grid = document.getElementById('news-grid');
        grid.innerHTML = news.map(item => {
          const pub = item.published_at
            ? new Date(item.published_at).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
            : '';
          const summary = truncate(item.summary, 100);
          const url = escHtml(item.url || '#');
          return `
            <div class="mo-news-card">
              ${item.source ? `<span class="mo-news-source">${escHtml(item.source)}</span>` : ''}
              <a href="${url}" class="mo-news-headline" target="_blank" rel="noopener noreferrer">
                ${escHtml(item.title || 'Untitled')}
              </a>
              ${pub ? `<span class="mo-news-date">${pub}</span>` : ''}
              ${summary ? `<p class="mo-news-summary">${escHtml(summary)}</p>` : ''}
            </div>`;
        }).join('');

        hide('news-loading');
        block('news-content');
      })
      .catch(() => {
        hide('news-loading');
        show('news-error');
      });
  }

  /* ─────────────────────────────────────────────────────
     FETCH 4: MARKET BREADTH
  ───────────────────────────────────────────────────── */
  function loadBreadth() {
    fetch(BASE + '/api/market-activity.php')
      .then(r => r.json())
      .then(data => {
        if (data.error && !data.activity) throw new Error(data.error);
        const a = data.activity;
        if (!a) throw new Error('no_data');

        const { advances = 0, declines = 0, unchanged = 0, total = 1, advance_decline_ratio = 0 } = a;

        // Stats row
        document.getElementById('breadth-stats').innerHTML = `
          <div class="mo-breadth-stat">
            <span class="mo-breadth-stat-label">Advances</span>
            <span class="mo-breadth-stat-val mo-adv-col">${advances.toLocaleString('en-IN')}</span>
          </div>
          <div class="mo-breadth-stat">
            <span class="mo-breadth-stat-label">Declines</span>
            <span class="mo-breadth-stat-val mo-dec-col">${declines.toLocaleString('en-IN')}</span>
          </div>
          <div class="mo-breadth-stat">
            <span class="mo-breadth-stat-label">Unchanged</span>
            <span class="mo-breadth-stat-val mo-unch-col">${unchanged.toLocaleString('en-IN')}</span>
          </div>`;

        // Ratio bar
        const totSafe = total || 1;
        document.getElementById('seg-adv').style.width  = ((advances  / totSafe) * 100).toFixed(1) + '%';
        document.getElementById('seg-dec').style.width  = ((declines  / totSafe) * 100).toFixed(1) + '%';
        document.getElementById('seg-unch').style.width = ((unchanged / totSafe) * 100).toFixed(1) + '%';

        // ADR text
        const adrColour = advance_decline_ratio >= 1 ? 'var(--bright)' : 'var(--danger)';
        document.getElementById('breadth-adr').innerHTML =
          `Advance / Decline Ratio: <strong style="color:${adrColour}">${advance_decline_ratio.toFixed(2)}</strong>
           &nbsp;·&nbsp; Total stocks tracked: <strong>${totSafe.toLocaleString('en-IN')}</strong>`;

        hide('breadth-loading');
        block('breadth-content');
      })
      .catch(() => {
        hide('breadth-loading');
        show('breadth-error');
      });
  }

  /* ─────────────────────────────────────────────────────
     HTML escape helper
  ───────────────────────────────────────────────────── */
  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /* ─────────────────────────────────────────────────────
     BOOT — fire all 4 fetches on DOMContentLoaded
  ───────────────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', function () {
    loadFiiDii();
    loadMovers('gainers');
    loadNews();
    loadBreadth();
  });

}());
</script>

<?php require_once '../includes/portal-footer.php'; ?>
