<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$db = get_db();

// 1-year Nifty 50 history for chart — from benchmark_nav (Yahoo Finance source)
$nifty_history = [];
try {
    $hist_stmt = $db->prepare(
        "SELECT nav_date, nav_value FROM benchmark_nav
         WHERE benchmark = 'nifty50'
           AND source = 'yahoo'
           AND nav_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
         ORDER BY nav_date ASC"
    );
    $hist_stmt->execute();
    $nifty_history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('market-indices DB error: ' . $e->getMessage());
}

$chart_dates = [];
$chart_vals  = [];
foreach ($nifty_history as $row) {
    $chart_dates[] = $row['nav_date'];
    $chart_vals[]  = (float) $row['nav_value'];
}

$page_title = 'Market Indices — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Advisory</p>
<h1 class="page-title">Market Indices</h1>
<p class="page-subtitle">Live Indian equity benchmark levels</p>

<!-- ── INDEX CARDS (live, loaded via JS) ────────────────────────────────────── -->
<div id="indices-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:1rem;margin-bottom:2rem">
  <!-- Skeletons while loading -->
  <?php for ($i = 0; $i < 8; $i++): ?>
  <div class="portal-card idx-skeleton" style="text-align:center;padding:1.5rem 1rem;min-height:110px;animation:pulse 1.4s ease-in-out infinite alternate">
    <div style="height:0.6rem;background:var(--border);border-radius:4px;width:70%;margin:0 auto 0.75rem"></div>
    <div style="height:1.6rem;background:var(--border);border-radius:4px;width:55%;margin:0 auto 0.5rem"></div>
    <div style="height:0.75rem;background:var(--border);border-radius:4px;width:45%;margin:0 auto"></div>
  </div>
  <?php endfor; ?>
</div>
<div id="indices-error" style="display:none;padding:1.5rem;background:var(--surface-1);border:1px solid var(--border);border-radius:10px;color:var(--text-secondary);font-size:0.875rem;margin-bottom:2rem">
  Live data temporarily unavailable. Please refresh in a moment.
</div>
<style>@keyframes pulse{0%{opacity:1}100%{opacity:0.45}}</style>

<!-- ── NIFTY 50 CHART ─────────────────────────────────────── -->
<div class="portal-card" style="margin-bottom:2rem">
  <div class="card-title">NIFTY 50 — Historical Performance</div>
  <?php if (empty($nifty_history)): ?>
    <div style="padding:2.5rem;text-align:center;color:var(--text-secondary);font-size:0.875rem">
      Chart will appear after the next daily data fetch (01:00 UTC).
    </div>
  <?php else: ?>
    <div style="position:relative;height:320px">
      <canvas id="niftyChart"></canvas>
    </div>
  <?php endif; ?>
</div>

<!-- ── FII / DII ACTIVITY ─────────────────────────────────── -->
<div class="portal-card" style="margin-bottom:2rem">
  <div style="display:flex;align-items:baseline;gap:0.75rem;margin-bottom:1.25rem;flex-wrap:wrap">
    <span style="font-family:'DM Mono',monospace;font-size:0.58rem;letter-spacing:0.2em;text-transform:uppercase;color:var(--lime)">Market Flows</span>
    <div class="card-title" style="margin:0">FII / DII Activity</div>
  </div>
  <div id="fii-dii-loading" style="color:var(--text-secondary);font-size:0.875rem;padding:0.5rem 0">Loading FII/DII data…</div>
  <div id="fii-dii-table" style="display:none;overflow-x:auto"></div>
  <div id="fii-dii-error" style="display:none;color:var(--text-secondary);font-size:0.875rem;padding:0.5rem 0">FII/DII data temporarily unavailable.</div>
  <div style="margin-top:0.75rem;font-size:0.72rem;color:var(--text-muted)">Source: mfapis.club — updated daily</div>
</div>

<!-- ── MACRO INDICATORS ───────────────────────────────────── -->
<div class="portal-card" style="margin-bottom:2rem">
  <div style="margin-bottom:1.25rem">
    <span style="font-family:'DM Mono',monospace;font-size:0.58rem;letter-spacing:0.2em;text-transform:uppercase;color:var(--lime);display:block;margin-bottom:0.35rem">Economy</span>
    <div class="card-title" style="margin:0">Macro Indicators</div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem">
    <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:1.25rem">
      <div style="font-family:'DM Mono',monospace;font-size:0.6rem;letter-spacing:0.15em;text-transform:uppercase;color:var(--lime);margin-bottom:0.5rem">RBI Repo Rate</div>
      <div style="font-family:'Cormorant Garamond',serif;font-size:2.4rem;font-weight:600;color:var(--cream);line-height:1">6.50%</div>
      <div style="font-size:0.72rem;color:var(--text-secondary);margin-top:0.4rem">Source: Reserve Bank of India</div>
    </div>
    <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:1.25rem">
      <div style="font-family:'DM Mono',monospace;font-size:0.6rem;letter-spacing:0.15em;text-transform:uppercase;color:var(--lime);margin-bottom:0.5rem">CPI Inflation</div>
      <div style="font-family:'Cormorant Garamond',serif;font-size:2.4rem;font-weight:600;color:var(--text-muted);line-height:1">—</div>
      <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.4rem">Coming soon</div>
    </div>
    <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:1.25rem">
      <div style="font-family:'DM Mono',monospace;font-size:0.6rem;letter-spacing:0.15em;text-transform:uppercase;color:var(--lime);margin-bottom:0.5rem">GDP Growth</div>
      <div style="font-family:'Cormorant Garamond',serif;font-size:2.4rem;font-weight:600;color:var(--text-muted);line-height:1">—</div>
      <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.4rem">Coming soon</div>
    </div>
  </div>
</div>

<script>
// ── Live index cards ────────────────────────────────────────
(function loadIndices() {
  const grid  = document.getElementById('indices-grid');
  const errEl = document.getElementById('indices-error');

  fetch('<?= SITE_URL ?>/api/market-indices.php', { credentials: 'same-origin' })
    .then(function(res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    })
    .then(function(json) {
      const list = json.data;
      if (!Array.isArray(list) || list.length === 0) throw new Error('empty');

      grid.innerHTML = '';
      list.forEach(function(idx) {
        const current = idx.current;
        const chgPct  = idx.chg_pct;
        const change  = idx.change;

        let valHtml, chgHtml;
        if (current !== null) {
          const fmt = Number(current).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          valHtml = '<div style="font-family:\'DM Mono\',monospace;font-size:1.6rem;font-weight:500;color:var(--cream);line-height:1.1;margin-bottom:0.4rem">' + fmt + '</div>';
        } else {
          valHtml = '<div style="font-family:\'DM Mono\',monospace;font-size:1.6rem;color:var(--text-muted);line-height:1.1;margin-bottom:0.4rem">—</div>';
        }

        if (chgPct !== null && change !== null) {
          const up     = chgPct >= 0;
          const col    = up ? 'var(--bright)' : '#ef5350';
          const arrow  = up ? '▲' : '▼';
          const sign   = up ? '+' : '';
          const chgFmt = sign + Number(change).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          const pctFmt = sign + Number(chgPct).toFixed(2) + '%';
          chgHtml = '<div style="font-family:\'DM Mono\',monospace;font-size:0.75rem;color:' + col + '">'
                  + arrow + ' ' + chgFmt + ' <span style="opacity:0.8">(' + pctFmt + ')</span></div>';
        } else {
          chgHtml = '<div style="font-size:0.72rem;color:var(--text-muted)">Fetching…</div>';
        }

        const card = document.createElement('div');
        card.className = 'portal-card';
        card.style.cssText = 'text-align:center;padding:1.5rem 1rem';
        card.innerHTML =
          '<div style="font-family:\'DM Mono\',monospace;font-size:0.6rem;letter-spacing:0.18em;text-transform:uppercase;color:var(--lime);margin-bottom:0.75rem">'
          + idx.label + '</div>'
          + valHtml
          + chgHtml;
        grid.appendChild(card);
      });
    })
    .catch(function() {
      grid.style.display = 'none';
      errEl.style.display = 'block';
    });
})();

// ── Nifty 50 Chart ─────────────────────────────────────────
<?php if (!empty($nifty_history)): ?>
const niftyDates = <?= json_encode($chart_dates, JSON_THROW_ON_ERROR) ?>;
const niftyVals  = <?= json_encode($chart_vals,  JSON_THROW_ON_ERROR) ?>;

(function initNiftyChart() {
  function draw() {
    const canvas = document.getElementById('niftyChart');
    if (!canvas || typeof Chart === 'undefined') { setTimeout(draw, 80); return; }
    const isDark  = document.documentElement.getAttribute('data-theme') !== 'light';
    const gridCol = 'rgba(46,133,64,0.12)';
    const textCol = isDark ? '#85a885' : '#2a5a2a';

    new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        labels: niftyDates,
        datasets: [{
          label: 'NIFTY 50',
          data: niftyVals,
          borderColor: '#4CAF50',
          borderWidth: 2,
          pointRadius: 0,
          pointHoverRadius: 4,
          pointHoverBackgroundColor: '#4CAF50',
          fill: true,
          backgroundColor: function(ctx) {
            const g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 300);
            g.addColorStop(0, 'rgba(76,175,80,0.18)');
            g.addColorStop(1, 'rgba(76,175,80,0.01)');
            return g;
          },
          tension: 0.3,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(12,20,12,0.92)',
            borderColor: 'rgba(46,133,64,0.3)',
            borderWidth: 1,
            titleColor: '#8DC63F',
            bodyColor: '#e4f0e4',
            titleFont: { family: "'DM Mono',monospace", size: 10 },
            bodyFont:  { family: "'DM Sans',sans-serif", size: 13 },
            callbacks: {
              label: function(item) {
                return ' ' + Number(item.raw).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
              },
            },
          },
        },
        scales: {
          x: {
            ticks: { color: textCol, font: { family: "'DM Mono',monospace", size: 9 }, maxTicksLimit: 8, maxRotation: 0 },
            grid: { color: gridCol },
          },
          y: {
            ticks: {
              color: textCol,
              font: { family: "'DM Mono',monospace", size: 9 },
              callback: function(v) { return Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 }); },
            },
            grid: { color: gridCol },
          },
        },
      },
    });
  }
  draw();
})();
<?php endif; ?>

// ── FII / DII Fetch ────────────────────────────────────────
(function loadFiiDii() {
  const loadingEl = document.getElementById('fii-dii-loading');
  const tableEl   = document.getElementById('fii-dii-table');
  const errorEl   = document.getElementById('fii-dii-error');

  fetch('<?= SITE_URL ?>/api/fii-dii.php', { method: 'GET', credentials: 'same-origin' })
    .then(function(res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
    .then(function(data) {
      if (data.error || !Array.isArray(data.rows) || data.rows.length === 0) throw new Error('no_data');
      loadingEl.style.display = 'none';
      let html = '<table style="width:100%;border-collapse:collapse;font-size:0.82rem">';
      html += '<thead><tr style="background:var(--forest)">';
      ['DATE','FII NET (₹ Cr)','DII NET (₹ Cr)','TOTAL (₹ Cr)'].forEach(function(h, i) {
        html += '<th style="padding:0.6rem 0.75rem;text-align:' + (i===0?'left':'right') + ';color:#fff;font-family:\'DM Mono\',monospace;font-size:0.65rem;letter-spacing:0.1em;font-weight:500">' + h + '</th>';
      });
      html += '</tr></thead><tbody>';
      data.rows.forEach(function(row, i) {
        const fii = parseFloat(row.fii_net ?? row.fii ?? 0);
        const dii = parseFloat(row.dii_net ?? row.dii ?? 0);
        const tot = fii + dii;
        const bg  = i % 2 === 0 ? 'var(--surface-1)' : 'var(--surface-2)';
        function cn(n) {
          return '<span style="color:' + (n>=0?'var(--bright)':'#ef5350') + ';font-family:\'DM Mono\',monospace">'
               + (n>=0?'+':'') + n.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}) + '</span>';
        }
        html += '<tr style="background:' + bg + '">';
        html += '<td style="padding:0.55rem 0.75rem;color:var(--cream);border-bottom:1px solid var(--border-light)">' + (row.date??row.trade_date??'—') + '</td>';
        html += '<td style="padding:0.55rem 0.75rem;text-align:right;border-bottom:1px solid var(--border-light)">' + cn(fii) + '</td>';
        html += '<td style="padding:0.55rem 0.75rem;text-align:right;border-bottom:1px solid var(--border-light)">' + cn(dii) + '</td>';
        html += '<td style="padding:0.55rem 0.75rem;text-align:right;border-bottom:1px solid var(--border-light)">' + cn(tot) + '</td>';
        html += '</tr>';
      });
      html += '</tbody></table>';
      tableEl.innerHTML = html;
      tableEl.style.display = 'block';
    })
    .catch(function() { loadingEl.style.display = 'none'; errorEl.style.display = 'block'; });
})();
</script>

<?php require_once '../includes/portal-footer.php'; ?>
