<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$db = get_db();

// Historical Nifty 50 trend for chart — mfapi NAV proxy; normalized to base=100 in JS
$nifty_history = [];
try {
    $hist_stmt = $db->prepare(
        "SELECT nav_date, nav_value FROM benchmark_nav
         WHERE benchmark = 'nifty50'
           AND nav_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
         ORDER BY nav_date ASC"
    );
    $hist_stmt->execute();
    $nifty_history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('market-indices DB error: ' . $e->getMessage());
}

$chart_dates = array_column($nifty_history, 'nav_date');
$chart_vals  = array_map('floatval', array_column($nifty_history, 'nav_value'));

$page_title = 'Market Indices — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Advisory</p>
<h1 class="page-title">Market Indices</h1>
<p class="page-subtitle">Live Indian equity benchmarks</p>

<!-- ── INDEX CARDS — loaded client-side from Yahoo Finance ──────────────── -->
<div id="indices-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:1rem;margin-bottom:2rem">
  <?php for ($i = 0; $i < 8; $i++): ?>
  <div class="portal-card idx-sk" style="text-align:center;padding:1.5rem 1rem;min-height:110px">
    <div style="height:0.55rem;background:var(--border);border-radius:4px;width:65%;margin:0 auto 0.85rem;animation:sk-pulse 1.4s ease-in-out infinite alternate"></div>
    <div style="height:1.5rem;background:var(--border);border-radius:4px;width:50%;margin:0 auto 0.55rem;animation:sk-pulse 1.4s ease-in-out 0.2s infinite alternate"></div>
    <div style="height:0.7rem;background:var(--border);border-radius:4px;width:42%;margin:0 auto;animation:sk-pulse 1.4s ease-in-out 0.4s infinite alternate"></div>
  </div>
  <?php endfor; ?>
</div>
<div id="indices-error" style="display:none;padding:1.25rem;background:var(--surface-1);border:1px solid var(--border);border-radius:10px;color:var(--text-secondary);font-size:0.875rem;margin-bottom:2rem">
  Live market data unavailable. Markets may be closed or data provider temporarily down.
</div>
<style>
@keyframes sk-pulse { 0%{opacity:1} 100%{opacity:0.35} }
</style>

<!-- ── NIFTY 50 TREND CHART ──────────────────────────────────────────────── -->
<div class="portal-card" style="margin-bottom:2rem">
  <div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;margin-bottom:1rem">
    <div class="card-title" style="margin:0">NIFTY 50 — 1 Year Trend</div>
    <?php if (!empty($nifty_history)): ?>
    <span style="font-family:'DM Mono',monospace;font-size:0.6rem;color:var(--text-muted);letter-spacing:0.08em">Indexed to 100</span>
    <?php endif; ?>
  </div>
  <?php if (empty($nifty_history)): ?>
    <div style="padding:2.5rem;text-align:center;color:var(--text-secondary);font-size:0.875rem">
      Chart data will appear after the daily cron has run for a few days.
    </div>
  <?php else: ?>
    <div style="position:relative;height:300px">
      <canvas id="niftyChart"></canvas>
    </div>
  <?php endif; ?>
</div>

<!-- ── FII / DII ACTIVITY ────────────────────────────────────────────────── -->
<div class="portal-card" style="margin-bottom:2rem">
  <div style="display:flex;align-items:baseline;gap:0.75rem;margin-bottom:1.25rem;flex-wrap:wrap">
    <span style="font-family:'DM Mono',monospace;font-size:0.58rem;letter-spacing:0.2em;text-transform:uppercase;color:var(--lime)">Market Flows</span>
    <div class="card-title" style="margin:0">FII / DII Activity</div>
  </div>
  <div id="fii-dii-loading" style="color:var(--text-secondary);font-size:0.875rem;padding:0.5rem 0">Loading FII/DII data…</div>
  <div id="fii-dii-table" style="display:none;overflow-x:auto"></div>
  <div id="fii-dii-error"  style="display:none;color:var(--text-secondary);font-size:0.875rem;padding:0.5rem 0">FII/DII data temporarily unavailable.</div>
  <div style="margin-top:0.75rem;font-size:0.72rem;color:var(--text-muted)">Source: mfapis.club — updated daily</div>
</div>

<!-- ── MACRO INDICATORS ──────────────────────────────────────────────────── -->
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
// ── Live index cards — fetched client-side from Yahoo Finance ─────────────
// Yahoo Finance allows CORS from browser; Hostinger server-side is blocked.
(function loadLiveIndices() {
  const INDICES = [
    { ticker: '%5ENSEI',       label: 'NIFTY 50'      },
    { ticker: '%5EBSESN',      label: 'SENSEX'        },
    { ticker: '%5ECNX100',     label: 'NIFTY 100'     },
    { ticker: '%5ECNX500',     label: 'NIFTY 500'     },
    { ticker: '%5ENSEBANK',    label: 'BANK NIFTY'    },
    { ticker: '%5ENIFMDCP150', label: 'MIDCAP 150'    },
    { ticker: '%5ENIFSC250',   label: 'SMALLCAP 250'  },
    { ticker: '%5ECNXIT',      label: 'NIFTY IT'      },
  ];

  const grid  = document.getElementById('indices-grid');
  const errEl = document.getElementById('indices-error');

  function fmtIN(n, dec) {
    return Number(n).toLocaleString('en-IN', {
      minimumFractionDigits: dec, maximumFractionDigits: dec
    });
  }

  function buildCard(label, current, chgPct, change) {
    let valHtml, chgHtml;

    if (current !== null && isFinite(current)) {
      valHtml = '<div style="font-family:\'DM Mono\',monospace;font-size:1.55rem;font-weight:500;color:var(--cream);line-height:1.1;margin-bottom:0.4rem">'
              + fmtIN(current, 2) + '</div>';
    } else {
      valHtml = '<div style="font-family:\'DM Mono\',monospace;font-size:1.55rem;color:var(--text-muted);line-height:1.1;margin-bottom:0.4rem">—</div>';
    }

    if (chgPct !== null && isFinite(chgPct) && change !== null) {
      const up    = chgPct >= 0;
      const col   = up ? 'var(--bright)' : '#ef5350';
      const arrow = up ? '▲' : '▼';
      const sign  = up ? '+' : '';
      chgHtml = '<div style="font-family:\'DM Mono\',monospace;font-size:0.72rem;color:' + col + '">'
              + arrow + ' ' + sign + fmtIN(change, 2)
              + ' <span style="opacity:0.75">(' + sign + Number(chgPct).toFixed(2) + '%)</span></div>';
    } else {
      chgHtml = '<div style="font-size:0.72rem;color:var(--text-muted)">—</div>';
    }

    const div = document.createElement('div');
    div.className = 'portal-card';
    div.style.cssText = 'text-align:center;padding:1.5rem 1rem';
    div.innerHTML =
      '<div style="font-family:\'DM Mono\',monospace;font-size:0.6rem;letter-spacing:0.18em;text-transform:uppercase;color:var(--lime);margin-bottom:0.75rem">'
      + label + '</div>'
      + valHtml + chgHtml;
    return div;
  }

  async function fetchIndex(ticker) {
    const url = 'https://query1.finance.yahoo.com/v8/finance/chart/' + ticker
              + '?interval=1d&range=2d&includePrePost=false';
    const res  = await fetch(url, { headers: { 'Accept': 'application/json' } });
    if (!res.ok) return null;
    const json = await res.json();
    const meta = json?.chart?.result?.[0]?.meta;
    if (!meta) return null;
    const current = meta.regularMarketPrice ?? null;
    const prev    = meta.regularMarketPreviousClose ?? meta.chartPreviousClose ?? null;
    return {
      current,
      prev,
      change:  (current !== null && prev !== null) ? current - prev    : null,
      chgPct:  (current !== null && prev !== null && prev > 0) ? ((current - prev) / prev) * 100 : null,
    };
  }

  // Fetch all indices in parallel, render as each resolves
  grid.innerHTML = '';
  let loaded = 0;
  let anyOk  = false;

  INDICES.forEach(function(sym) {
    // Add card placeholder immediately
    const placeholder = buildCard(sym.label, null, null, null);
    grid.appendChild(placeholder);

    fetchIndex(sym.ticker)
      .then(function(d) {
        if (d) {
          anyOk = true;
          const fresh = buildCard(sym.label, d.current, d.chgPct, d.change);
          grid.replaceChild(fresh, placeholder);
        }
      })
      .catch(function() { /* leave placeholder */ })
      .finally(function() {
        loaded++;
        if (loaded === INDICES.length && !anyOk) {
          grid.style.display = 'none';
          errEl.style.display = 'block';
        }
      });
  });
})();

// ── Nifty 50 Trend Chart — normalized to base 100 ────────────────────────
<?php if (!empty($nifty_history)): ?>
(function initNiftyChart() {
  const rawDates = <?= json_encode($chart_dates, JSON_THROW_ON_ERROR) ?>;
  const rawVals  = <?= json_encode($chart_vals,  JSON_THROW_ON_ERROR) ?>;

  // Normalize: first value = 100
  const base = rawVals[0] || 1;
  const normVals = rawVals.map(function(v) { return +((v / base) * 100).toFixed(2); });

  function draw() {
    const canvas = document.getElementById('niftyChart');
    if (!canvas || typeof Chart === 'undefined') { setTimeout(draw, 80); return; }
    const isDark  = document.documentElement.getAttribute('data-theme') !== 'light';
    const textCol = isDark ? '#85a885' : '#2a5a2a';
    const gridCol = 'rgba(46,133,64,0.12)';

    new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        labels: rawDates,
        datasets: [{
          label: 'NIFTY 50 (indexed)',
          data: normVals,
          borderColor: '#4CAF50',
          borderWidth: 2,
          pointRadius: 0,
          pointHoverRadius: 4,
          pointHoverBackgroundColor: '#4CAF50',
          fill: true,
          backgroundColor: function(ctx) {
            const g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 280);
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
                const v = item.raw;
                const sign = v >= 100 ? '+' : '';
                return ' ' + Number(v).toFixed(2) + ' (' + sign + (v - 100).toFixed(2) + '%)';
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
              callback: function(v) { return v.toFixed(0); },
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

// ── FII / DII ─────────────────────────────────────────────────────────────
(function loadFiiDii() {
  const loadEl  = document.getElementById('fii-dii-loading');
  const tableEl = document.getElementById('fii-dii-table');
  const errEl   = document.getElementById('fii-dii-error');

  fetch('<?= SITE_URL ?>/api/fii-dii.php', { credentials: 'same-origin' })
    .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function(data) {
      if (data.error || !Array.isArray(data.rows) || !data.rows.length) throw new Error('empty');
      loadEl.style.display = 'none';
      let html = '<table style="width:100%;border-collapse:collapse;font-size:0.82rem"><thead><tr style="background:var(--forest)">';
      ['DATE','FII NET (₹ Cr)','DII NET (₹ Cr)','TOTAL (₹ Cr)'].forEach(function(h, i) {
        html += '<th style="padding:0.6rem 0.75rem;text-align:' + (i===0?'left':'right')
              + ';color:#fff;font-family:\'DM Mono\',monospace;font-size:0.65rem;letter-spacing:0.1em;font-weight:500">' + h + '</th>';
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
        html += '<td style="padding:0.55rem 0.75rem;color:var(--cream);border-bottom:1px solid var(--border-light)">' + (row.date??'—') + '</td>';
        html += '<td style="padding:0.55rem 0.75rem;text-align:right;border-bottom:1px solid var(--border-light)">' + cn(fii) + '</td>';
        html += '<td style="padding:0.55rem 0.75rem;text-align:right;border-bottom:1px solid var(--border-light)">' + cn(dii) + '</td>';
        html += '<td style="padding:0.55rem 0.75rem;text-align:right;border-bottom:1px solid var(--border-light)">' + cn(tot) + '</td>';
        html += '</tr>';
      });
      html += '</tbody></table>';
      tableEl.innerHTML = html;
      tableEl.style.display = 'block';
    })
    .catch(function() { loadEl.style.display = 'none'; errEl.style.display = 'block'; });
})();
</script>

<?php require_once '../includes/portal-footer.php'; ?>
