<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$db = get_db();

// Latest values for all equity benchmarks
$idx_stmt = $db->query("
    SELECT benchmark, nav_value, nav_date
    FROM benchmark_nav
    WHERE benchmark IN ('nifty50','nifty100','nifty_midcap150','nifty_smallcap250','nifty500')
    AND nav_date = (SELECT MAX(nav_date) FROM benchmark_nav WHERE benchmark IN ('nifty50','nifty100','nifty_midcap150','nifty_smallcap250','nifty500'))
");
$idx_rows = [];
if ($idx_stmt) {
    foreach ($idx_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $idx_rows[$r['benchmark']] = $r;
    }
}

// 1-year history for chart (Nifty 50 only, ~250 trading days)
$hist_stmt = $db->prepare("
    SELECT nav_date, nav_value FROM benchmark_nav
    WHERE benchmark = 'nifty50' AND nav_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
    ORDER BY nav_date ASC
");
$hist_stmt->execute();
$nifty_history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);

$index_labels = [
    'nifty50'           => 'NIFTY 50',
    'nifty100'          => 'NIFTY 100',
    'nifty_midcap150'   => 'NIFTY MIDCAP 150',
    'nifty_smallcap250' => 'NIFTY SMALLCAP 250',
    'nifty500'          => 'NIFTY 500',
];

// Prepare JS data for chart
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
<p class="page-subtitle">Indian equity benchmark levels — sourced from daily NAV data</p>

<!-- ── INDEX CARDS ──────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:2rem">
  <?php foreach ($index_labels as $key => $label):
    $row      = $idx_rows[$key] ?? null;
    $val      = $row ? (float) $row['nav_value'] : null;
    $nav_date = $row ? $row['nav_date'] : null;
    $display  = $val !== null ? number_format($val, 2) : '—';
    $subtext  = $nav_date !== null
        ? 'As of ' . date('d M Y', strtotime($nav_date))
        : 'Data updating…';
  ?>
  <div class="portal-card" style="text-align:center;padding:1.5rem 1rem">
    <div style="font-family:'DM Mono',monospace;font-size:0.6rem;letter-spacing:0.18em;text-transform:uppercase;color:var(--lime);margin-bottom:0.75rem">
      <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div style="font-family:'DM Mono',monospace;font-size:1.65rem;font-weight:500;color:var(--cream);line-height:1.1;margin-bottom:0.5rem">
      <?= htmlspecialchars($display, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div style="font-size:0.72rem;color:var(--text-secondary)">
      <?= htmlspecialchars($subtext, ENT_QUOTES, 'UTF-8') ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── NIFTY 50 CHART ─────────────────────────────────────── -->
<div class="portal-card" style="margin-bottom:2rem">
  <div class="card-title">NIFTY 50 — 1 Year Performance</div>

  <?php if (empty($nifty_history)): ?>
    <div style="padding:2.5rem;text-align:center;color:var(--text-secondary);font-size:0.875rem">
      Chart data will appear after the daily cron runs.
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
  <div id="fii-dii-loading" style="color:var(--text-secondary);font-size:0.875rem;padding:0.5rem 0">
    Loading FII/DII data…
  </div>
  <div id="fii-dii-table" style="display:none;overflow-x:auto"></div>
  <div id="fii-dii-error" style="display:none;color:var(--text-secondary);font-size:0.875rem;padding:0.5rem 0">
    FII/DII data temporarily unavailable.
  </div>
  <div style="margin-top:0.75rem;font-size:0.72rem;color:var(--text-muted)">
    Source: mfapis.club — updated daily
  </div>
</div>

<!-- ── MACRO INDICATORS ───────────────────────────────────── -->
<div class="portal-card" style="margin-bottom:2rem">
  <div style="margin-bottom:1.25rem">
    <span style="font-family:'DM Mono',monospace;font-size:0.58rem;letter-spacing:0.2em;text-transform:uppercase;color:var(--lime);display:block;margin-bottom:0.35rem">Economy</span>
    <div class="card-title" style="margin:0">Macro Indicators</div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem">
    <!-- RBI Repo Rate -->
    <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:1.25rem">
      <div style="font-family:'DM Mono',monospace;font-size:0.6rem;letter-spacing:0.15em;text-transform:uppercase;color:var(--lime);margin-bottom:0.5rem">RBI Repo Rate</div>
      <div style="font-family:'Cormorant Garamond',serif;font-size:2.4rem;font-weight:600;color:var(--cream);line-height:1">6.50%</div>
      <div style="font-size:0.72rem;color:var(--text-secondary);margin-top:0.4rem">Source: Reserve Bank of India</div>
    </div>
    <!-- CPI Placeholder -->
    <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:1.25rem">
      <div style="font-family:'DM Mono',monospace;font-size:0.6rem;letter-spacing:0.15em;text-transform:uppercase;color:var(--lime);margin-bottom:0.5rem">CPI Inflation</div>
      <div style="font-family:'Cormorant Garamond',serif;font-size:2.4rem;font-weight:600;color:var(--text-muted);line-height:1">—</div>
      <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.4rem">Coming soon</div>
    </div>
    <!-- GDP Placeholder -->
    <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:1.25rem">
      <div style="font-family:'DM Mono',monospace;font-size:0.6rem;letter-spacing:0.15em;text-transform:uppercase;color:var(--lime);margin-bottom:0.5rem">GDP Growth</div>
      <div style="font-family:'Cormorant Garamond',serif;font-size:2.4rem;font-weight:600;color:var(--text-muted);line-height:1">—</div>
      <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.4rem">Coming soon</div>
    </div>
  </div>
</div>

<!-- ── SCRIPTS ─────────────────────────────────────────────── -->
<script>
// ── Nifty 50 Chart ─────────────────────────────────────────
<?php if (!empty($nifty_history)): ?>
const niftyDates = <?= json_encode($chart_dates, JSON_THROW_ON_ERROR) ?>;
const niftyVals  = <?= json_encode($chart_vals,  JSON_THROW_ON_ERROR) ?>;

(function initNiftyChart() {
  function draw() {
    const canvas = document.getElementById('niftyChart');
    if (!canvas || typeof Chart === 'undefined') {
      setTimeout(draw, 80);
      return;
    }
    const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
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
            const gradient = ctx.chart.ctx.createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, 'rgba(76,175,80,0.18)');
            gradient.addColorStop(1, 'rgba(76,175,80,0.01)');
            return gradient;
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
              title: function(items) { return items[0]?.label ?? ''; },
              label: function(item) {
                return ' ' + Number(item.raw).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
              },
            },
          },
        },
        scales: {
          x: {
            ticks: {
              color: textCol,
              font: { family: "'DM Mono',monospace", size: 9 },
              maxTicksLimit: 8,
              maxRotation: 0,
            },
            grid: { color: gridCol },
          },
          y: {
            ticks: {
              color: textCol,
              font: { family: "'DM Mono',monospace", size: 9 },
              callback: function(v) { return Number(v).toLocaleString('en-IN'); },
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

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

  fetch('<?= SITE_URL ?>/api/fii-dii.php', {
    method: 'GET',
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': csrfToken },
  })
  .then(function(res) {
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
  })
  .then(function(data) {
    if (data.error || !Array.isArray(data.rows) || data.rows.length === 0) {
      throw new Error('no_data');
    }
    loadingEl.style.display = 'none';

    // Build table
    let html = '<table style="width:100%;border-collapse:collapse;font-size:0.82rem">';
    html += '<thead><tr style="background:var(--forest)">';
    html += '<th style="padding:0.6rem 0.75rem;text-align:left;color:#fff;font-family:\'DM Mono\',monospace;font-size:0.65rem;letter-spacing:0.1em;font-weight:500">DATE</th>';
    html += '<th style="padding:0.6rem 0.75rem;text-align:right;color:#fff;font-family:\'DM Mono\',monospace;font-size:0.65rem;letter-spacing:0.1em;font-weight:500">FII NET (₹ Cr)</th>';
    html += '<th style="padding:0.6rem 0.75rem;text-align:right;color:#fff;font-family:\'DM Mono\',monospace;font-size:0.65rem;letter-spacing:0.1em;font-weight:500">DII NET (₹ Cr)</th>';
    html += '<th style="padding:0.6rem 0.75rem;text-align:right;color:#fff;font-family:\'DM Mono\',monospace;font-size:0.65rem;letter-spacing:0.1em;font-weight:500">TOTAL (₹ Cr)</th>';
    html += '</tr></thead><tbody>';

    data.rows.forEach(function(row, i) {
      const fii   = parseFloat(row.fii_net ?? row.fii ?? 0);
      const dii   = parseFloat(row.dii_net ?? row.dii ?? 0);
      const total = fii + dii;
      const bg    = i % 2 === 0 ? 'var(--surface-1)' : 'var(--surface-2)';

      function colorNum(n) {
        const col = n >= 0 ? 'var(--bright)' : '#ef5350';
        const sign = n >= 0 ? '+' : '';
        return '<span style="color:' + col + ';font-family:\'DM Mono\',monospace">' + sign + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</span>';
      }

      const dateStr = row.date ?? row.trade_date ?? row.nav_date ?? '—';

      html += '<tr style="background:' + bg + '">';
      html += '<td style="padding:0.55rem 0.75rem;color:var(--cream);border-bottom:1px solid var(--border-light)">' + dateStr + '</td>';
      html += '<td style="padding:0.55rem 0.75rem;text-align:right;border-bottom:1px solid var(--border-light)">' + colorNum(fii)   + '</td>';
      html += '<td style="padding:0.55rem 0.75rem;text-align:right;border-bottom:1px solid var(--border-light)">' + colorNum(dii)   + '</td>';
      html += '<td style="padding:0.55rem 0.75rem;text-align:right;border-bottom:1px solid var(--border-light)">' + colorNum(total) + '</td>';
      html += '</tr>';
    });

    html += '</tbody></table>';
    tableEl.innerHTML = html;
    tableEl.style.display = 'block';
  })
  .catch(function() {
    loadingEl.style.display = 'none';
    errorEl.style.display   = 'block';
  });
})();
</script>

<?php require_once '../includes/portal-footer.php'; ?>
