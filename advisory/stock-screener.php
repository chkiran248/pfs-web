<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$page_title = 'Stock Screener — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Advisory · Stock Screener</p>
<h1 class="page-title">Stock Screener</h1>

<!-- ── Mandatory stock disclaimer ──────────────────────────────────────── -->
<div class="disclaimer disclaimer--stock">
  <strong>⚠ Research Note — Not Investment Advice</strong>
  The research notes published here are for educational and informational
  purposes only. Prime Financials is an AMFI Registered Mutual Fund
  Distributor and is NOT a SEBI Registered Investment Advisor (RIA).
  This does not constitute investment advice or a recommendation to buy
  or sell any security. Please consult a SEBI RIA before investing.
  Investments in securities are subject to market risks.
</div>

<!-- ── Filter panel ─────────────────────────────────────────────────────── -->
<div class="portal-card" style="margin-bottom:1.5rem">
  <div style="font-family:'DM Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.18em;text-transform:uppercase;margin-bottom:1rem">
    Screen Filters
  </div>

  <div style="display:flex;flex-wrap:wrap;gap:1rem 1.25rem;align-items:flex-end">

    <!-- Sector -->
    <div style="flex:1 1 160px;min-width:140px">
      <label class="form-label" for="f-sector">Sector</label>
      <select id="f-sector" class="form-select">
        <option value="">All Sectors</option>
        <option value="Banking">Banking</option>
        <option value="IT">IT</option>
        <option value="Pharma">Pharma</option>
        <option value="Auto">Auto</option>
        <option value="FMCG">FMCG</option>
        <option value="Energy">Energy</option>
        <option value="Metals">Metals</option>
        <option value="Realty">Realty</option>
        <option value="Infra">Infra</option>
        <option value="Chemicals">Chemicals</option>
      </select>
    </div>

    <!-- Max P/E -->
    <div style="flex:1 1 120px;min-width:110px">
      <label class="form-label" for="f-pe">Max P/E</label>
      <input id="f-pe" type="number" min="0" step="0.5" placeholder="e.g. 25" class="form-input" />
    </div>

    <!-- Max P/B -->
    <div style="flex:1 1 120px;min-width:110px">
      <label class="form-label" for="f-pb">Max P/B</label>
      <input id="f-pb" type="number" min="0" step="0.1" placeholder="e.g. 3" class="form-input" />
    </div>

    <!-- Min ROE % -->
    <div style="flex:1 1 130px;min-width:110px">
      <label class="form-label" for="f-roe">Min ROE %</label>
      <input id="f-roe" type="number" min="0" step="0.5" placeholder="e.g. 15" class="form-input" />
    </div>

    <!-- Min Promoter % -->
    <div style="flex:1 1 150px;min-width:130px">
      <label class="form-label" for="f-promoter">Min Promoter %</label>
      <input id="f-promoter" type="number" min="0" max="100" step="0.5" placeholder="e.g. 50" class="form-input" />
    </div>

    <!-- Max Pledge % -->
    <div style="flex:1 1 140px;min-width:120px">
      <label class="form-label" for="f-pledge">Max Pledge %</label>
      <input id="f-pledge" type="number" min="0" max="100" step="0.5" placeholder="e.g. 10" class="form-input" />
    </div>

    <!-- Min Market Cap -->
    <div style="flex:1 1 160px;min-width:140px">
      <label class="form-label" for="f-mktcap-min">Min Mkt Cap (₹ Cr)</label>
      <input id="f-mktcap-min" type="number" min="0" step="100" placeholder="e.g. 5000" class="form-input" />
    </div>

    <!-- Sort By -->
    <div style="flex:1 1 150px;min-width:130px">
      <label class="form-label" for="f-sort">Sort By</label>
      <select id="f-sort" class="form-select">
        <option value="market_cap">Market Cap</option>
        <option value="pe">P/E</option>
        <option value="pb">P/B</option>
        <option value="roe">ROE</option>
        <option value="promoter_pct">Promoter %</option>
      </select>
    </div>

    <!-- Buttons -->
    <div style="display:flex;gap:0.6rem;align-items:flex-end;flex:0 0 auto;margin-top:0.25rem">
      <button class="btn-primary" onclick="screenStocks()">
        <i class="bi bi-funnel-fill" style="margin-right:0.35rem"></i>Screen Stocks
      </button>
      <button class="btn-ghost" onclick="resetFilters()">Reset</button>
    </div>

  </div>
</div>

<!-- ── Results area ─────────────────────────────────────────────────────── -->
<div id="screener-results">
  <div id="screener-loading" style="text-align:center;padding:3rem;color:var(--text-secondary)">
    <div class="spinner" style="margin:0 auto 1rem"></div>
    <div style="font-family:'DM Mono',monospace;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Loading stocks…</div>
  </div>
</div>

<style>
/* ── Spinner ──────────────────────────────────────────────────────────── */
.spinner {
  width: 36px; height: 36px;
  border: 3px solid var(--border);
  border-top-color: var(--bright);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Screener table ───────────────────────────────────────────────────── */
.screener-table-wrap {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  border-radius: 10px;
  border: 1px solid var(--border);
}

.screener-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
  min-width: 860px;
}

.screener-table thead tr {
  background: var(--forest);
  color: #fff;
}

.screener-table thead th {
  padding: 0.65rem 0.9rem;
  text-align: left;
  font-family: 'DM Mono', monospace;
  font-size: 0.6rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  white-space: nowrap;
  font-weight: 500;
}

.screener-table thead th.num {
  text-align: right;
}

.screener-table tbody tr {
  border-bottom: 1px solid var(--border-light);
  transition: background 0.15s;
}

.screener-table tbody tr:nth-child(even) {
  background: var(--surface-2);
}

.screener-table tbody tr:nth-child(odd) {
  background: var(--surface-1);
}

.screener-table tbody tr:hover {
  background: rgba(46, 133, 64, 0.07);
}

.screener-table tbody td {
  padding: 0.6rem 0.9rem;
  color: var(--text-primary);
  vertical-align: middle;
}

.screener-table tbody td.num {
  text-align: right;
  font-family: 'DM Mono', monospace;
  font-size: 0.78rem;
}

.chg-pos { color: var(--bright); }
.chg-neg { color: #ef5350; }

.pledge-warn  { color: #ef5350; font-weight: 600; }
.promoter-ok  { color: var(--bright); }

.co-name {
  font-family: 'Cormorant Garamond', serif;
  font-size: 1rem;
  font-weight: 600;
  color: var(--cream);
  display: block;
  line-height: 1.2;
}

.co-sym {
  font-family: 'DM Mono', monospace;
  font-size: 0.6rem;
  color: var(--lime);
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.sector-tag {
  display: inline-block;
  font-family: 'DM Mono', monospace;
  font-size: 0.58rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  padding: 0.15rem 0.45rem;
  border-radius: 4px;
  background: rgba(46, 133, 64, 0.12);
  color: var(--lime);
  border: 1px solid rgba(46, 133, 64, 0.2);
}

.wa-link {
  font-family: 'DM Mono', monospace;
  font-size: 0.62rem;
  color: var(--lime);
  text-decoration: none;
  white-space: nowrap;
  opacity: 0.75;
  transition: opacity 0.15s;
}
.wa-link:hover { opacity: 1; }

.screener-count {
  font-family: 'DM Mono', monospace;
  font-size: 0.65rem;
  color: var(--text-secondary);
  letter-spacing: 0.1em;
  text-transform: uppercase;
  margin-bottom: 0.75rem;
}

/* ── Empty / error states ─────────────────────────────────────────────── */
.state-box {
  background: var(--surface-1);
  border: 1px solid var(--border);
  border-radius: 12px;
  text-align: center;
  padding: 3rem 1.5rem;
  color: var(--text-secondary);
}

.state-box .state-icon {
  font-size: 2rem;
  margin-bottom: 0.75rem;
  display: block;
}
</style>

<script>
(function () {
  'use strict';

  const API_URL = '<?= htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8') ?>/api/stock-screener.php';
  const WA_NUM  = '<?= WHATSAPP_NUM ?>';
  const resultsEl = document.getElementById('screener-results');

  /* ── helper: read a numeric input, return null if blank ── */
  function numVal(id) {
    const v = document.getElementById(id).value.trim();
    return v === '' ? null : parseFloat(v);
  }

  /* ── helper: format market cap ── */
  function fmtCap(val) {
    if (val === null || val === undefined) return '—';
    const n = parseFloat(val);
    if (isNaN(n)) return '—';
    if (n >= 100000) return '₹' + (n / 100000).toFixed(2) + ' L Cr';
    if (n >= 1000)   return '₹' + n.toLocaleString('en-IN', {maximumFractionDigits: 0}) + ' Cr';
    return '₹' + n.toFixed(0) + ' Cr';
  }

  /* ── helper: format a float with fallback ── */
  function fmtNum(val, digits, suffix) {
    if (val === null || val === undefined) return '—';
    const n = parseFloat(val);
    if (isNaN(n)) return '—';
    return n.toFixed(digits) + (suffix || '');
  }

  /* ── render table ── */
  function renderTable(stocks) {
    if (!stocks.length) {
      resultsEl.innerHTML = '<div class="state-box"><span class="state-icon">🔍</span>No stocks match your filters.<br><small style="font-size:0.78rem">Try relaxing one or more criteria.</small></div>';
      return;
    }

    const count = stocks.length;
    const rows = stocks.map(s => {
      const chgClass   = (s.change_pct > 0) ? 'chg-pos' : (s.change_pct < 0 ? 'chg-neg' : '');
      const chgSign    = (s.change_pct > 0) ? '+' : '';
      const chgTxt     = (s.change_pct !== null && s.change_pct !== undefined) ? chgSign + parseFloat(s.change_pct).toFixed(2) + '%' : '—';
      const pledgeCls  = (s.pledge_pct !== null && parseFloat(s.pledge_pct) > 20) ? 'pledge-warn' : '';
      const promCls    = (s.promoter_pct !== null && parseFloat(s.promoter_pct) >= 50) ? 'promoter-ok' : '';
      const priceTxt   = (s.close !== null && s.close !== undefined) ? '₹' + parseFloat(s.close).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '—';

      const sym   = s.symbol || '';
      const name  = s.name || sym;
      const sector = s.sector || '';

      const waMsg = encodeURIComponent(
        'Hi, I’m interested in ' + sym + ' (' + name + '). ' +
        'P/E: ' + fmtNum(s.pe, 1) + ', ROE: ' + fmtNum(s.roe, 1) + '%. ' +
        'Please add it to my watchlist via primefin.in'
      );

      return `<tr>
        <td>
          <span class="co-name">${name}</span>
          <span class="co-sym">${sym}</span>
        </td>
        <td>${sector ? '<span class="sector-tag">' + sector + '</span>' : '—'}</td>
        <td class="num" style="font-family:'DM Mono',monospace">${priceTxt}</td>
        <td class="num ${chgClass}">${chgTxt}</td>
        <td class="num">${fmtCap(s.market_cap_cr)}</td>
        <td class="num">${fmtNum(s.pe, 1)}</td>
        <td class="num">${fmtNum(s.pb, 1)}</td>
        <td class="num">${fmtNum(s.roe, 1)}</td>
        <td class="num ${promCls}">${fmtNum(s.promoter_pct, 1, '%')}</td>
        <td class="num ${pledgeCls}">${fmtNum(s.pledge_pct, 1, '%')}</td>
        <td><a class="wa-link" href="https://wa.me/${WA_NUM}?text=${waMsg}" target="_blank" rel="noopener">💬 Watchlist</a></td>
      </tr>`;
    }).join('');

    resultsEl.innerHTML = `
      <div class="screener-count">Showing ${count} stock${count !== 1 ? 's' : ''}</div>
      <div class="screener-table-wrap">
        <table class="screener-table">
          <thead>
            <tr>
              <th>Company</th>
              <th>Sector</th>
              <th class="num">Price</th>
              <th class="num">Chg %</th>
              <th class="num">Mkt Cap</th>
              <th class="num">P/E</th>
              <th class="num">P/B</th>
              <th class="num">ROE %</th>
              <th class="num">Promoter %</th>
              <th class="num">Pledge %</th>
              <th>Watchlist</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>`;
  }

  /* ── main fetch function ── */
  window.screenStocks = function () {
    const params = new URLSearchParams();

    const sector = document.getElementById('f-sector').value;
    const sortBy = document.getElementById('f-sort').value;
    if (sector) params.set('sector', sector);
    params.set('sort_by', sortBy);
    params.set('direction', 'desc');
    params.set('limit', '50');

    const pe  = numVal('f-pe');
    const pb  = numVal('f-pb');
    const roe = numVal('f-roe');
    const pro = numVal('f-promoter');
    const ple = numVal('f-pledge');
    const mcMin = numVal('f-mktcap-min');

    if (pe  !== null) params.set('pe_max',       pe);
    if (pb  !== null) params.set('pb_max',       pb);
    if (roe !== null) params.set('roe_min',      roe);
    if (pro !== null) params.set('promoter_min', pro);
    if (ple !== null) params.set('pledge_max',   ple);
    if (mcMin !== null) params.set('mktcap_min', mcMin);

    resultsEl.innerHTML = `
      <div style="text-align:center;padding:3rem;color:var(--text-secondary)">
        <div class="spinner" style="margin:0 auto 1rem"></div>
        <div style="font-family:'DM Mono',monospace;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase">Screening stocks…</div>
      </div>`;

    fetch(API_URL + '?' + params.toString())
      .then(res => {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(data => {
        if (data.error && data.error === 'data_unavailable') {
          resultsEl.innerHTML = '<div class="state-box"><span class="state-icon">⚠️</span>Market data is temporarily unavailable.<br><small style="font-size:0.78rem">Please try again in a moment.</small></div>';
          return;
        }
        renderTable(data.stocks || []);
      })
      .catch(err => {
        console.error('Screener fetch error:', err);
        resultsEl.innerHTML = '<div class="state-box"><span class="state-icon">⚠️</span>Could not load data. Please check your connection and try again.</div>';
      });
  };

  /* ── reset ── */
  window.resetFilters = function () {
    document.getElementById('f-sector').value   = '';
    document.getElementById('f-pe').value        = '';
    document.getElementById('f-pb').value        = '';
    document.getElementById('f-roe').value       = '';
    document.getElementById('f-promoter').value  = '';
    document.getElementById('f-pledge').value    = '';
    document.getElementById('f-mktcap-min').value = '';
    document.getElementById('f-sort').value      = 'market_cap';
    resultsEl.innerHTML = '';
  };

  /* ── auto-screen on page load ── */
  screenStocks();

})();
</script>

<?php require_once '../includes/portal-footer.php'; ?>
