<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$page_title = 'Stock Compare — Prime Financials';

// Pre-populate tickers from URL param
$url_tickers_raw = trim(filter_input(INPUT_GET, 'tickers', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$url_tickers = [];
if ($url_tickers_raw) {
    $url_tickers = array_values(array_slice(
        array_filter(array_unique(array_map('strtoupper', array_map('trim', explode(',', $url_tickers_raw))))),
        0, 4
    ));
}

require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">ADVISORY · STOCK COMPARE</p>
<h1 class="page-title">Stock Compare</h1>
<p class="page-subtitle">Compare up to 4 stocks side by side using live fundamentals</p>

<!-- ── Stock Disclaimer ──────────────────────────────────────────────────── -->
<div class="disclaimer disclaimer--stock">
  <strong>⚠ Research Note — Not Investment Advice</strong>
  The research notes published here are for educational and informational
  purposes only. Prime Financials is an AMFI Registered Mutual Fund
  Distributor and is NOT a SEBI Registered Investment Advisor (RIA).
  This does not constitute investment advice or a recommendation to buy
  or sell any security. Please consult a SEBI RIA before investing.
  Investments in securities are subject to market risks.
</div>

<!-- ── Search / Add Bar ──────────────────────────────────────────────────── -->
<div class="portal-card" style="margin-top:1.5rem">
  <div style="font-family:'DM Mono',monospace;font-size:0.6rem;color:var(--lime);letter-spacing:0.2em;text-transform:uppercase;margin-bottom:0.75rem">Search Stocks</div>

  <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end">
    <div style="flex:1;min-width:220px;position:relative">
      <div style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </div>
      <input
        type="text"
        id="ticker-input"
        class="form-input"
        placeholder="Search ticker symbol (e.g. RELIANCE)"
        style="padding-left:2.25rem;text-transform:uppercase;font-family:'DM Mono',monospace;letter-spacing:0.05em"
        maxlength="20"
        autocomplete="off"
      />
    </div>
    <button class="btn-outline" onclick="addStockFromInput()" style="white-space:nowrap">+ Add Stock</button>
    <button class="btn-primary" id="compare-btn" onclick="compareStocks()" disabled style="white-space:nowrap">Compare</button>
  </div>

  <!-- Ticker chips -->
  <div id="ticker-chips" style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-top:1rem;min-height:2rem"></div>

  <!-- Hint text -->
  <p id="chip-hint" style="font-size:0.75rem;color:var(--text-muted);margin-top:0.5rem">Add 2–4 stocks to compare. Press Enter or click Add Stock.</p>
</div>

<!-- ── Compare Table (hidden until loaded) ──────────────────────────────── -->
<div id="compare-section" style="display:none;margin-top:2rem">

  <!-- Loading state -->
  <div id="compare-loading" style="display:none;text-align:center;padding:3rem;color:var(--text-muted)">
    <div style="font-family:'DM Mono',monospace;font-size:0.72rem;letter-spacing:0.15em">FETCHING LIVE DATA…</div>
    <div id="load-progress" style="font-size:0.65rem;color:var(--text-muted);margin-top:0.5rem"></div>
  </div>

  <!-- Error state -->
  <div id="compare-error" style="display:none" class="portal-card">
    <div style="color:#ef5350;font-size:0.875rem" id="error-message">Unable to load comparison data.</div>
  </div>

  <!-- Results table -->
  <div id="compare-table-wrap" style="display:none;overflow-x:auto;-webkit-overflow-scrolling:touch">
    <table id="compare-table" style="width:100%;border-collapse:collapse;min-width:560px">
      <thead>
        <tr id="stock-header-row">
          <!-- Stock header cells injected by JS -->
        </tr>
      </thead>
      <tbody id="compare-tbody">
        <!-- Metric rows injected by JS -->
      </tbody>
    </table>
  </div>

</div>

<!-- ── WhatsApp float ────────────────────────────────────────────────────── -->
<a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=Hi%2C+I+need+help+with+my+stock+comparison+on+primefin.in"
   class="whatsapp-float" target="_blank" rel="noopener">
  <span>💬</span> Chat with Advisor
</a>

<!-- ── Inline styles ─────────────────────────────────────────────────────── -->
<style>
/* Chip */
.stock-chip {
  display:inline-flex;align-items:center;gap:0.4rem;
  background:var(--surface-2);border:1px solid var(--border);
  border-radius:2rem;padding:0.3rem 0.7rem;
  font-family:'DM Mono',monospace;font-size:0.72rem;letter-spacing:0.1em;
  color:var(--cream);
}
.stock-chip button {
  background:none;border:none;cursor:pointer;color:var(--text-muted);
  font-size:0.9rem;line-height:1;padding:0;display:flex;align-items:center;
}
.stock-chip button:hover { color:#ef5350; }

/* Table base */
#compare-table th,
#compare-table td {
  padding:0.65rem 1rem;
  text-align:left;
  vertical-align:top;
  border-bottom:1px solid var(--border-light);
  font-size:0.82rem;
}

/* Label column */
.cmp-label {
  color:var(--text-secondary);
  white-space:nowrap;
  min-width:140px;
  font-size:0.79rem;
}

/* Section header row */
.cmp-section-row td {
  background:var(--forest) !important;
  color:var(--cream);
  font-family:'DM Mono',monospace;
  font-size:0.62rem;
  letter-spacing:0.18em;
  text-transform:uppercase;
  padding:0.45rem 1rem;
  border-bottom:none;
}

/* Stock header */
.cmp-stock-header {
  background:var(--surface-1);
  padding:1rem !important;
  border-bottom:2px solid var(--mid) !important;
  min-width:160px;
}
.cmp-stock-name {
  font-family:'Cormorant Garamond',serif;
  font-size:1.05rem;font-weight:600;
  color:var(--cream);
  line-height:1.3;
  margin-bottom:0.25rem;
}
.cmp-ticker-badge {
  display:inline-block;
  font-family:'DM Mono',monospace;font-size:0.6rem;letter-spacing:0.15em;
  text-transform:uppercase;
  background:var(--forest);color:var(--lime);
  border-radius:3px;padding:0.15rem 0.45rem;
  margin-bottom:0.25rem;
}
.cmp-sector {
  font-size:0.7rem;color:var(--text-muted);
  font-family:'DM Mono',monospace;
  margin-bottom:0.5rem;
}
.cmp-price {
  font-family:'DM Mono',monospace;font-size:0.95rem;font-weight:600;color:var(--cream);
}
.cmp-change { font-size:0.75rem;font-family:'DM Mono',monospace; }
.cmp-change.pos { color:var(--bright); }
.cmp-change.neg { color:#ef5350; }

/* Best value highlight */
.best-val {
  background:rgba(76,175,80,0.10) !important;
  color:var(--bright) !important;
  font-weight:600;
  border-radius:4px;
}

/* Alternating rows */
#compare-tbody tr:nth-child(even):not(.cmp-section-row) td {
  background:var(--surface-2);
}
#compare-tbody tr:nth-child(odd):not(.cmp-section-row) td {
  background:var(--surface-1);
}

/* WhatsApp column footer */
.cmp-wa-cell {
  padding:0.75rem 1rem !important;
  background:var(--surface-1) !important;
  border-top:1px solid var(--border) !important;
}
</style>

<!-- ── JavaScript ────────────────────────────────────────────────────────── -->
<script>
(function () {
  'use strict';

  // ── State ────────────────────────────────────────────────────────────────
  var _tickers = <?= json_encode($url_tickers) ?>;  // pre-populated from URL
  var _data    = [];

  // ── DOM refs (resolved after DOMContentLoaded) ───────────────────────────
  var $input, $chips, $compareBtn, $hint;

  // ── Init ─────────────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    $input      = document.getElementById('ticker-input');
    $chips      = document.getElementById('ticker-chips');
    $compareBtn = document.getElementById('compare-btn');
    $hint       = document.getElementById('chip-hint');

    // Keyboard shortcut
    $input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); addStockFromInput(); }
    });

    // Render pre-populated tickers
    _tickers.forEach(function (t) { renderChip(t); });
    syncCompareBtn();

    // Auto-compare if pre-populated with ≥2 tickers
    if (_tickers.length >= 2) { compareStocks(); }
  });

  // ── Add stock ────────────────────────────────────────────────────────────
  window.addStockFromInput = function () {
    var val = ($input.value || '').trim().toUpperCase().replace(/[^A-Z0-9&-]/g, '');
    if (!val) return;
    addStock(val);
    $input.value = '';
    $input.focus();
  };

  window.addStock = function (ticker) {
    ticker = ticker.toUpperCase();
    if (_tickers.indexOf(ticker) !== -1) { shake($input); return; }
    if (_tickers.length >= 4) { $hint.textContent = 'Maximum 4 stocks allowed. Remove one to add another.'; $hint.style.color = '#ef5350'; return; }
    _tickers.push(ticker);
    renderChip(ticker);
    syncCompareBtn();
    updateURL();
  };

  // ── Remove stock ─────────────────────────────────────────────────────────
  window.removeStock = function (ticker) {
    var idx = _tickers.indexOf(ticker);
    if (idx === -1) return;
    _tickers.splice(idx, 1);
    var chip = document.getElementById('chip-' + ticker);
    if (chip) chip.remove();
    syncCompareBtn();
    updateURL();
    // Hide table if fewer than 2
    if (_tickers.length < 2) {
      document.getElementById('compare-section').style.display = 'none';
      _data = [];
    }
  };

  // ── Render chip ──────────────────────────────────────────────────────────
  function renderChip(ticker) {
    var div = document.createElement('div');
    div.className = 'stock-chip';
    div.id        = 'chip-' + ticker;
    div.innerHTML =
      '<span>' + esc(ticker) + '</span>' +
      '<button onclick="removeStock(\'' + esc(ticker) + '\')" title="Remove">×</button>';
    $chips.appendChild(div);
  }

  // ── Sync compare button ──────────────────────────────────────────────────
  function syncCompareBtn() {
    var ok = _tickers.length >= 2;
    $compareBtn.disabled = !ok;
    $hint.style.color = 'var(--text-muted)';
    if (_tickers.length === 0) {
      $hint.textContent = 'Add 2–4 stocks to compare. Press Enter or click Add Stock.';
    } else if (_tickers.length === 1) {
      $hint.textContent = 'Add at least one more stock to compare.';
    } else {
      $hint.textContent = _tickers.length + ' stocks selected. Click Compare to load data.';
    }
  }

  // ── Update URL ───────────────────────────────────────────────────────────
  function updateURL() {
    var url = new URL(window.location.href);
    if (_tickers.length > 0) {
      url.searchParams.set('tickers', _tickers.join(','));
    } else {
      url.searchParams.delete('tickers');
    }
    window.history.replaceState({}, '', url.toString());
  }

  // ── Compare ──────────────────────────────────────────────────────────────
  window.compareStocks = function () {
    if (_tickers.length < 2) return;

    var sec    = document.getElementById('compare-section');
    var loader = document.getElementById('compare-loading');
    var errDiv = document.getElementById('compare-error');
    var wrap   = document.getElementById('compare-table-wrap');

    sec.style.display    = 'block';
    loader.style.display = 'block';
    errDiv.style.display = 'none';
    wrap.style.display   = 'none';

    document.getElementById('load-progress').textContent =
      'Fetching fundamentals for ' + _tickers.join(', ') + '…';

    fetch('<?= SITE_URL ?>/api/stock-compare-data.php?tickers=' + encodeURIComponent(_tickers.join(',')))
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        _data = data;
        loader.style.display = 'none';
        if (!data || !data.length) {
          showError('No data returned. Check ticker symbols and try again.');
          return;
        }
        renderTable(data);
        wrap.style.display = 'block';
        highlightBest();
      })
      .catch(function (e) {
        loader.style.display = 'none';
        showError('Unable to load comparison data. Please try again. (' + e.message + ')');
      });
  };

  function showError(msg) {
    var errDiv = document.getElementById('compare-error');
    document.getElementById('error-message').textContent = msg;
    errDiv.style.display = 'block';
  }

  // ── Render Table ─────────────────────────────────────────────────────────
  function renderTable(data) {
    var headerRow = document.getElementById('stock-header-row');
    var tbody     = document.getElementById('compare-tbody');

    // Clear
    headerRow.innerHTML = '';
    tbody.innerHTML     = '';

    // First header cell: label column
    var th0 = document.createElement('th');
    th0.style.cssText = 'background:var(--surface-1);border-bottom:2px solid var(--mid);padding:1rem;position:sticky;left:0;z-index:2;min-width:140px';
    th0.innerHTML = '<span style="font-family:\'DM Mono\',monospace;font-size:0.6rem;color:var(--lime);letter-spacing:0.2em;text-transform:uppercase">Metric</span>';
    headerRow.appendChild(th0);

    // Stock header cells
    data.forEach(function (s) {
      var th = document.createElement('th');
      th.className = 'cmp-stock-header';
      th.dataset.ticker = s.ticker;

      var chgStr = s.change_pct != null
        ? ((s.change_pct >= 0 ? '+' : '') + parseFloat(s.change_pct).toFixed(2) + '%')
        : '—';
      var chgClass = s.change_pct != null ? (s.change_pct >= 0 ? 'pos' : 'neg') : '';

      th.innerHTML =
        '<div class="cmp-ticker-badge">' + esc(s.ticker) + '</div>' +
        '<div class="cmp-stock-name">' + esc(s.name || s.ticker) + '</div>' +
        '<div class="cmp-sector">' + esc(s.sector || 'N/A') + '</div>' +
        '<div class="cmp-price">' + (s.price != null ? '₹' + fmtNum(s.price, 2) : '—') + '</div>' +
        '<div class="cmp-change ' + chgClass + '">' + chgStr + '</div>';
      headerRow.appendChild(th);
    });

    // Metric groups
    var groups = [
      {
        label: 'VALUATION',
        rows: [
          { label: 'Market Cap',  key: 'market_cap_cr', fmt: function(v){ return v != null ? '₹' + fmtNumCr(v) + ' Cr' : '—'; }, best: null },
          { label: 'P/E Ratio',   key: 'pe',            fmt: fmtDecimal, best: 'low'  },
          { label: 'P/B Ratio',   key: 'pb',            fmt: fmtDecimal, best: 'low'  },
          { label: 'EPS (₹)',     key: 'eps',           fmt: function(v){ return v != null ? '₹' + fmtDecimal(v) : '—'; }, best: 'high' },
          { label: 'Div Yield',   key: 'div_yield',     fmt: function(v){ return v != null ? fmtDecimal(v) + '%' : '—'; }, best: 'high' },
        ]
      },
      {
        label: 'PROFITABILITY',
        rows: [
          { label: 'ROE %',  key: 'roe',  fmt: function(v){ return v != null ? fmtDecimal(v) + '%' : '—'; }, best: 'high' },
          { label: 'ROCE %', key: 'roce', fmt: function(v){ return v != null ? fmtDecimal(v) + '%' : '—'; }, best: 'high' },
        ]
      },
      {
        label: 'FINANCIAL HEALTH',
        rows: [
          { label: 'Debt / Equity',  key: 'debt_equity',   fmt: fmtDecimal, best: 'low' },
          { label: 'Current Ratio',  key: 'current_ratio', fmt: fmtDecimal, best: 'high' },
        ]
      },
      {
        label: 'OWNERSHIP',
        rows: [
          { label: 'Promoter %', key: 'promoter_pct', fmt: function(v){ return v != null ? fmtDecimal(v) + '%' : '—'; }, best: 'high' },
          { label: 'Pledge %',   key: 'pledge_pct',   fmt: function(v){ return v != null ? fmtDecimal(v) + '%' : '—'; }, best: 'low'  },
        ]
      },
      {
        label: '52-WEEK RANGE',
        rows: [
          { label: '52W High', key: '52w_high', fmt: function(v){ return v != null ? '₹' + fmtNum(v, 2) : '—'; }, best: null },
          { label: '52W Low',  key: '52w_low',  fmt: function(v){ return v != null ? '₹' + fmtNum(v, 2) : '—'; }, best: null },
        ]
      },
    ];

    groups.forEach(function (group) {
      // Section header row
      var secRow = document.createElement('tr');
      secRow.className = 'cmp-section-row';
      var secTd = document.createElement('td');
      secTd.colSpan = 1 + data.length;
      secTd.textContent = group.label;
      secRow.appendChild(secTd);
      tbody.appendChild(secRow);

      // Metric rows
      group.rows.forEach(function (metric) {
        var tr = document.createElement('tr');
        tr.dataset.key  = metric.key;
        tr.dataset.best = metric.best || '';

        // Label cell
        var labelTd = document.createElement('td');
        labelTd.className = 'cmp-label';
        labelTd.style.cssText = 'position:sticky;left:0;background:inherit;z-index:1';
        labelTd.textContent = metric.label;
        tr.appendChild(labelTd);

        // Value cells
        data.forEach(function (s) {
          var td = document.createElement('td');
          td.dataset.ticker = s.ticker;
          td.dataset.rawval = s[metric.key] != null ? s[metric.key] : '';
          td.textContent = metric.fmt(s[metric.key]);
          tr.appendChild(td);
        });

        tbody.appendChild(tr);
      });
    });

    // WhatsApp row per stock
    var waRow = document.createElement('tr');
    var waLabelTd = document.createElement('td');
    waLabelTd.className = 'cmp-label cmp-wa-cell';
    waLabelTd.style.cssText = 'position:sticky;left:0;z-index:1';
    waRow.appendChild(waLabelTd);

    data.forEach(function (s) {
      var waTd = document.createElement('td');
      waTd.className = 'cmp-wa-cell';
      var msg = encodeURIComponent('Hi, I need help with ' + s.ticker + ' stock analysis on primefin.in');
      waTd.innerHTML =
        '<a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=' + msg + '" ' +
        'target="_blank" rel="noopener" ' +
        'style="display:inline-flex;align-items:center;gap:0.35rem;font-size:0.72rem;color:var(--bright);text-decoration:none;border:1px solid var(--border);border-radius:6px;padding:0.35rem 0.65rem;white-space:nowrap">' +
        '💬 Ask Advisor about ' + esc(s.ticker) +
        '</a>';
      waRow.appendChild(waTd);
    });
    tbody.appendChild(waRow);
  }

  // ── Highlight best ────────────────────────────────────────────────────────
  window.highlightBest = function () {
    var rows = document.querySelectorAll('#compare-tbody tr[data-key]');
    rows.forEach(function (row) {
      var bestDir = row.dataset.best;
      if (!bestDir) return;

      var cells = row.querySelectorAll('td[data-rawval]');
      var vals  = [];
      cells.forEach(function (td) {
        var v = td.dataset.rawval;
        vals.push(v !== '' && !isNaN(Number(v)) ? Number(v) : null);
      });

      // Find best value index
      var validVals = vals.filter(function (v) { return v !== null; });
      if (validVals.length < 2) return; // nothing to compare

      var bestVal = bestDir === 'low'
        ? Math.min.apply(null, validVals)
        : Math.max.apply(null, validVals);

      cells.forEach(function (td, i) {
        td.classList.remove('best-val');
        if (vals[i] !== null && vals[i] === bestVal) {
          td.classList.add('best-val');
        }
      });
    });
  };

  // ── Utility ───────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function fmtNum(v, dec) {
    if (v == null) return '—';
    return parseFloat(v).toLocaleString('en-IN', { minimumFractionDigits: dec, maximumFractionDigits: dec });
  }

  function fmtDecimal(v) {
    if (v == null) return '—';
    return parseFloat(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function fmtNumCr(v) {
    if (v == null) return '—';
    var n = parseFloat(v);
    if (n >= 100000) return (n / 100000).toFixed(2) + ' L'; // Lakh Cr
    return n.toLocaleString('en-IN', { maximumFractionDigits: 0 });
  }

  function shake(el) {
    el.style.animation = 'none';
    el.offsetHeight; // reflow
    el.style.animation = 'shake 0.3s ease';
    setTimeout(function () { el.style.animation = ''; }, 300);
  }
})();
</script>

<style>
@keyframes shake {
  0%, 100% { transform: translateX(0); }
  25%       { transform: translateX(-6px); }
  75%       { transform: translateX(6px); }
}
</style>

<?php require_once '../includes/portal-footer.php'; ?>
