<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$db = get_db();

// All keys shown on this page
const ALL_KEYS = ['nifty50', 'nifty100', 'sensex', 'banknifty', 'nifty_midcap150', 'nifty500', 'nifty_smallcap250'];

const INDEX_LABELS = [
    'nifty50'           => 'NIFTY 50',
    'nifty100'          => 'NIFTY 100',
    'sensex'            => 'SENSEX (BSE)',
    'banknifty'         => 'BANK NIFTY',
    'nifty_midcap150'   => 'NIFTY MIDCAP 150',
    'nifty500'          => 'NIFTY 500',
    'nifty_smallcap250' => 'NIFTY SMALLCAP 250',
];

$keys_in = implode(',', array_map(fn($k) => "'$k'", ALL_KEYS));

// ── Query A: mfapi.in rows — fallback % change source (daily, always fresh) ──
$pct_data = [];
try {
    $stmt = $db->query(
        "SELECT b1.benchmark,
                b1.nav_value AS today_val,
                b1.nav_date  AS today_date,
                b2.nav_value AS prev_val
         FROM benchmark_nav b1
         LEFT JOIN benchmark_nav b2
           ON b2.benchmark = b1.benchmark
          AND b2.source = 'mfapi'
          AND b2.nav_date = (
              SELECT MAX(nav_date) FROM benchmark_nav
              WHERE benchmark = b1.benchmark AND source = 'mfapi' AND nav_date < b1.nav_date
          )
         WHERE b1.benchmark IN ($keys_in)
           AND b1.source = 'mfapi'
           AND b1.nav_date = (
               SELECT MAX(nav_date) FROM benchmark_nav
               WHERE benchmark = b1.benchmark AND source = 'mfapi'
           )"
    );
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
        $pct_data[$r['benchmark']] = $r;
    }
} catch (Throwable $e) {
    error_log('market-indices pct_data DB error: ' . $e->getMessage());
}

// ── Query B: mfapis.club rows — primary source (actual levels + prev for % change) ──
$level_data = [];
try {
    $stmt2 = $db->query(
        "SELECT b1.benchmark,
                b1.nav_value AS level_val,
                b1.nav_date  AS level_date,
                b2.nav_value AS level_prev_val
         FROM benchmark_nav b1
         LEFT JOIN benchmark_nav b2
           ON b2.benchmark = b1.benchmark
          AND b2.source = 'mfapis'
          AND b2.nav_date = (
              SELECT MAX(nav_date) FROM benchmark_nav
              WHERE benchmark = b1.benchmark AND source = 'mfapis' AND nav_date < b1.nav_date
          )
         WHERE b1.benchmark IN ($keys_in)
           AND b1.source = 'mfapis'
           AND b1.nav_date = (
               SELECT MAX(nav_date) FROM benchmark_nav
               WHERE benchmark = b1.benchmark AND source = 'mfapis'
           )"
    );
    foreach (($stmt2 ? $stmt2->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
        $level_data[$r['benchmark']] = $r;
    }
} catch (Throwable $e) {
    error_log('market-indices level_data DB error: ' . $e->getMessage());
}

// 1-year Nifty 50 history for chart (actual levels since we switched to BeES ETF)
$chart_dates = [];
$chart_vals  = [];
try {
    $h = $db->prepare(
        "SELECT nav_date, nav_value FROM benchmark_nav
         WHERE benchmark = 'nifty50'
           AND source = 'mfapis'
           AND nav_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
         ORDER BY nav_date ASC"
    );
    $h->execute();
    foreach ($h->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $chart_dates[] = $row['nav_date'];
        $chart_vals[]  = (float) $row['nav_value'];
    }
} catch (Throwable $e) { /* chart is optional */ }

$page_title = 'Market Indices — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Advisory</p>
<h1 class="page-title">Market Indices</h1>
<p class="page-subtitle">Indian equity benchmarks — primary: mfapis.club · fallback: mfapi.in</p>

<!-- ── INDEX CARDS ────────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:1rem;margin-bottom:2rem">
  <?php foreach (ALL_KEYS as $key):
    $label   = INDEX_LABELS[$key];
    $pct_row = $pct_data[$key]   ?? null;
    $lvl_row = $level_data[$key] ?? null;

    // ── Freshness check ──────────────────────────────────────
    // mfapis.club is "fresh" if its date is within 2 calendar days of mfapi.in date.
    // When fresh: use mfapis.club for BOTH level AND % change (Price Return, exact).
    // When stale: use mfapi.in for % change (TRI, accurate day-to-day), mfapis for level with label.
    $level_val      = $lvl_row ? (float)$lvl_row['level_val']      : null;
    $level_prev_val = ($lvl_row && $lvl_row['level_prev_val'] !== null) ? (float)$lvl_row['level_prev_val'] : null;
    $level_date     = $lvl_row['level_date'] ?? null;
    $mfapi_date     = $pct_row['today_date'] ?? null;

    $mfapis_fresh = $level_date && $mfapi_date
        && (strtotime($mfapi_date) - strtotime($level_date)) <= (2 * 86400);

    // ── % change calculation ─────────────────────────────────
    $chg_str = null; $arrow = '—'; $chg_col = 'var(--text-muted)'; $is_stale = false;

    if ($mfapis_fresh && $level_val !== null && $level_prev_val !== null && $level_prev_val > 0) {
        // PRIMARY: mfapis.club — actual Price Return % change
        $chg_raw = (($level_val - $level_prev_val) / $level_prev_val) * 100;
        $up      = $chg_raw >= 0;
        $chg_col = $up ? 'var(--bright)' : '#ef5350';
        $arrow   = $up ? '▲' : '▼';
        $chg_str = ($up ? '+' : '') . number_format($chg_raw, 2) . '%';
        $is_stale = false;
    } elseif ($pct_row && $pct_row['prev_val'] !== null && (float)$pct_row['prev_val'] > 0) {
        // SECONDARY: mfapi.in — TRI-based, accurate day-to-day movement
        $today_v = (float)$pct_row['today_val'];
        $prev_v  = (float)$pct_row['prev_val'];
        $chg_raw = (($today_v - $prev_v) / $prev_v) * 100;
        $up      = $chg_raw >= 0;
        $chg_col = $up ? 'var(--bright)' : '#ef5350';
        $arrow   = $up ? '▲' : '▼';
        $chg_str = ($up ? '+' : '') . number_format($chg_raw, 2) . '%';
        $is_stale = !$mfapis_fresh && $level_date !== null;
    }
  ?>
  <div class="portal-card" style="text-align:center;padding:1.5rem 1rem">
    <div style="font-family:'DM Mono',monospace;font-size:0.6rem;letter-spacing:0.18em;text-transform:uppercase;color:var(--lime);margin-bottom:0.75rem">
      <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    </div>

    <?php if ($level_val !== null): ?>
      <div style="font-family:'DM Mono',monospace;font-size:1.5rem;font-weight:500;color:var(--cream);line-height:1.1;margin-bottom:0.25rem">
        <?= number_format($level_val, 2) ?>
      </div>
      <?php if ($is_stale && $level_date): ?>
      <div style="font-size:0.6rem;color:#ef9a33;margin-bottom:0.3rem">
        as of <?= date('d M', strtotime($level_date)) ?> · stale
      </div>
      <?php endif; ?>
    <?php else: ?>
      <div style="font-family:'DM Mono',monospace;font-size:1.5rem;color:var(--text-muted);margin-bottom:0.5rem">—</div>
    <?php endif; ?>

    <?php if ($chg_str !== null): ?>
    <div style="font-family:'DM Mono',monospace;font-size:0.75rem;font-weight:500;color:<?= $chg_col ?>">
      <?= $arrow ?> <?= htmlspecialchars($chg_str, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div style="font-size:0.6rem;color:var(--text-muted);margin-top:0.2rem">1-day change</div>
    <?php elseif ($level_val !== null || $pct_row): ?>
    <div style="font-size:0.65rem;color:var(--text-muted)">Updating…</div>
    <?php else: ?>
    <div style="font-size:0.65rem;color:var(--text-muted)">Pending first fetch</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── NIFTY 50 CHART ─────────────────────────────────────────────────────── -->
<div class="portal-card" style="margin-bottom:2rem">
  <div class="card-title">NIFTY 50 — 1 Year Performance</div>
  <?php if (empty($chart_vals)): ?>
    <div style="padding:2.5rem;text-align:center;color:var(--text-secondary);font-size:0.875rem">
      Chart will build up over the coming days as cron collects historical data.
    </div>
  <?php else: ?>
    <div style="position:relative;height:300px"><canvas id="niftyChart"></canvas></div>
  <?php endif; ?>
</div>

<!-- ── FII / DII ──────────────────────────────────────────────────────────── -->
<div class="portal-card" style="margin-bottom:2rem">
  <div style="display:flex;align-items:baseline;gap:0.75rem;margin-bottom:1.25rem">
    <span style="font-family:'DM Mono',monospace;font-size:0.58rem;letter-spacing:0.2em;text-transform:uppercase;color:var(--lime)">Market Flows</span>
    <div class="card-title" style="margin:0">FII / DII Activity</div>
  </div>
  <div id="fii-loading" style="color:var(--text-secondary);font-size:0.875rem">Loading…</div>
  <div id="fii-table" style="display:none;overflow-x:auto"></div>
  <div id="fii-error" style="display:none;color:var(--text-secondary);font-size:0.875rem">FII/DII data temporarily unavailable.</div>
</div>

<!-- ── MACRO INDICATORS ───────────────────────────────────────────────────── -->
<div class="portal-card" style="margin-bottom:2rem">
  <div style="margin-bottom:1.25rem">
    <span style="font-family:'DM Mono',monospace;font-size:0.58rem;letter-spacing:0.2em;text-transform:uppercase;color:var(--lime);display:block;margin-bottom:0.35rem">Economy</span>
    <div class="card-title" style="margin:0">Macro Indicators</div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem">
    <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:1.25rem">
      <div style="font-family:'DM Mono',monospace;font-size:0.6rem;letter-spacing:0.15em;text-transform:uppercase;color:var(--lime);margin-bottom:0.5rem">RBI Repo Rate</div>
      <div style="font-family:'Cormorant Garamond',serif;font-size:2.4rem;font-weight:600;color:var(--cream);line-height:1">6.50%</div>
      <div style="font-size:0.72rem;color:var(--text-secondary);margin-top:0.4rem">Reserve Bank of India</div>
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
<?php if (!empty($chart_vals)): ?>
(function() {
  function draw() {
    const canvas = document.getElementById('niftyChart');
    if (!canvas || typeof Chart === 'undefined') { setTimeout(draw, 80); return; }
    const isDark  = document.documentElement.getAttribute('data-theme') !== 'light';
    const textCol = isDark ? '#85a885' : '#2a5a2a';
    const gridCol = 'rgba(46,133,64,0.12)';
    new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        labels: <?= json_encode($chart_dates, JSON_THROW_ON_ERROR) ?>,
        datasets: [{
          data: <?= json_encode($chart_vals, JSON_THROW_ON_ERROR) ?>,
          borderColor: '#4CAF50', borderWidth: 2, pointRadius: 0,
          pointHoverRadius: 4, pointHoverBackgroundColor: '#4CAF50',
          fill: true, tension: 0.3,
          backgroundColor: function(ctx) {
            const g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 280);
            g.addColorStop(0, 'rgba(76,175,80,0.18)');
            g.addColorStop(1, 'rgba(76,175,80,0.01)');
            return g;
          },
        }],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(12,20,12,0.92)', borderColor: 'rgba(46,133,64,0.3)', borderWidth: 1,
            titleColor: '#8DC63F', bodyColor: '#e4f0e4',
            titleFont: { family: "'DM Mono',monospace", size: 10 },
            bodyFont: { family: "'DM Sans',sans-serif", size: 13 },
            callbacks: {
              label: function(item) {
                return ' ' + Number(item.raw).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
              },
            },
          },
        },
        scales: {
          x: { ticks: { color: textCol, font: { family: "'DM Mono',monospace", size: 9 }, maxTicksLimit: 8, maxRotation: 0 }, grid: { color: gridCol } },
          y: { ticks: { color: textCol, font: { family: "'DM Mono',monospace", size: 9 }, callback: function(v) { return Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 }); } }, grid: { color: gridCol } },
        },
      },
    });
  }
  draw();
})();
<?php endif; ?>

// FII / DII
(function() {
  const loadEl = document.getElementById('fii-loading');
  const tabEl  = document.getElementById('fii-table');
  const errEl  = document.getElementById('fii-error');
  fetch('<?= SITE_URL ?>/api/fii-dii.php', { credentials: 'same-origin' })
    .then(function(r) { if (!r.ok) throw 0; return r.json(); })
    .then(function(d) {
      if (!Array.isArray(d.rows) || !d.rows.length) throw 0;
      loadEl.style.display = 'none';
      let h = '<table style="width:100%;border-collapse:collapse;font-size:0.82rem"><thead><tr style="background:var(--forest)">';
      ['DATE','FII NET (₹ Cr)','DII NET (₹ Cr)','TOTAL (₹ Cr)'].forEach(function(th, i) {
        h += '<th style="padding:0.6rem 0.75rem;text-align:' + (i?'right':'left') + ';color:#fff;font-family:\'DM Mono\',monospace;font-size:0.65rem;letter-spacing:0.1em;font-weight:500">' + th + '</th>';
      });
      h += '</tr></thead><tbody>';
      d.rows.forEach(function(row, i) {
        const fii = parseFloat(row.fii_net ?? 0), dii = parseFloat(row.dii_net ?? 0), tot = fii + dii;
        const bg = i % 2 ? 'var(--surface-2)' : 'var(--surface-1)';
        function cn(n) { return '<span style="color:' + (n>=0?'var(--bright)':'#ef5350') + ';font-family:\'DM Mono\',monospace">' + (n>=0?'+':'') + n.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}) + '</span>'; }
        h += '<tr style="background:' + bg + '"><td style="padding:0.55rem 0.75rem;color:var(--cream);border-bottom:1px solid var(--border-light)">' + (row.date??'—') + '</td>';
        h += '<td style="padding:0.55rem 0.75rem;text-align:right;border-bottom:1px solid var(--border-light)">' + cn(fii) + '</td>';
        h += '<td style="padding:0.55rem 0.75rem;text-align:right;border-bottom:1px solid var(--border-light)">' + cn(dii) + '</td>';
        h += '<td style="padding:0.55rem 0.75rem;text-align:right;border-bottom:1px solid var(--border-light)">' + cn(tot) + '</td></tr>';
      });
      h += '</tbody></table>';
      tabEl.innerHTML = h; tabEl.style.display = 'block';
    })
    .catch(function() { loadEl.style.display = 'none'; errEl.style.display = 'block'; });
})();
</script>

<?php require_once '../includes/portal-footer.php'; ?>
