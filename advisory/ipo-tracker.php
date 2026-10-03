<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$page_title = 'IPO Tracker — Prime Financials';
require_once '../includes/portal-header.php';
?>

<style>
/* ── IPO Tracker local styles ─────────────────────────────────────────── */
.ipo-tabs {
    display: flex;
    gap: 0;
    border-bottom: 1px solid var(--border);
    margin-bottom: 1.75rem;
}
.ipo-tab {
    padding: 0.65rem 1.4rem;
    font-family: 'DM Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--text-secondary);
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    transition: color 0.2s, border-color 0.2s;
}
.ipo-tab:hover { color: var(--cream); }
.ipo-tab.active {
    color: var(--bright);
    border-bottom-color: var(--bright);
}
.ipo-panel { display: none; }
.ipo-panel.active { display: block; }

/* Status badges */
.status-pill {
    display: inline-block;
    font-family: 'DM Mono', monospace;
    font-size: 0.62rem;
    letter-spacing: 0.13em;
    text-transform: uppercase;
    padding: 0.2rem 0.55rem;
    border-radius: 4px;
    font-weight: 500;
}
.status-upcoming  { background: rgba(201,168,76,0.12);  color: var(--gold);   border: 1px solid rgba(201,168,76,0.3);  }
.status-open      { background: rgba(76,175,80,0.13);   color: var(--bright); border: 1px solid rgba(76,175,80,0.3);   }
.status-allotment { background: rgba(141,198,63,0.10);  color: var(--lime);   border: 1px solid rgba(141,198,63,0.25); }
.status-listed    { background: rgba(46,133,64,0.12);   color: var(--mid);    border: 1px solid rgba(46,133,64,0.25);  }

/* GMP pill */
.gmp-pill {
    display: inline-block;
    font-family: 'DM Mono', monospace;
    font-size: 0.62rem;
    letter-spacing: 0.1em;
    padding: 0.18rem 0.5rem;
    border-radius: 4px;
    font-weight: 500;
}
.gmp-pos { background: rgba(76,175,80,0.13); color: var(--bright); border: 1px solid rgba(76,175,80,0.25); }
.gmp-nil { background: rgba(46,133,64,0.06); color: var(--text-muted); border: 1px solid rgba(46,133,64,0.12); }

/* Listing gain colour */
.gain-pos { color: var(--bright); font-weight: 600; }
.gain-neg { color: #e57373;        font-weight: 600; }
.gain-nil { color: var(--text-secondary); }

/* Responsive table wrapper */
.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.ipo-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.84rem;
}
.ipo-table th {
    background: var(--forest);
    color: #fff;
    font-family: 'DM Mono', monospace;
    font-size: 0.65rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    padding: 0.65rem 0.85rem;
    text-align: left;
    white-space: nowrap;
}
.ipo-table td {
    padding: 0.7rem 0.85rem;
    border-bottom: 1px solid var(--border-light);
    color: var(--text-primary);
    vertical-align: middle;
}
.ipo-table tbody tr:nth-child(even) td { background: var(--surface-2); }
.ipo-table tbody tr:hover td { background: rgba(46,133,64,0.07); }
.ipo-table .company-name {
    font-family: 'Cormorant Garamond', Georgia, serif;
    font-size: 0.97rem;
    font-weight: 600;
    color: var(--cream);
}
.ipo-table .company-sym {
    font-family: 'DM Mono', monospace;
    font-size: 0.62rem;
    color: var(--text-muted);
    margin-top: 0.15rem;
}

/* WhatsApp action button */
.btn-wa {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.3rem 0.65rem;
    border-radius: 6px;
    background: rgba(37,211,102,0.08);
    border: 1px solid rgba(37,211,102,0.25);
    color: #25d366;
    font-size: 0.72rem;
    font-family: 'DM Mono', monospace;
    text-decoration: none;
    white-space: nowrap;
    transition: background 0.2s;
}
.btn-wa:hover { background: rgba(37,211,102,0.16); }

/* Loading state */
.ipo-loading {
    text-align: center;
    padding: 3rem;
    color: var(--text-secondary);
    font-size: 0.875rem;
}
.ipo-loading .spinner {
    width: 28px; height: 28px;
    border: 2px solid var(--border);
    border-top-color: var(--bright);
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
    margin: 0 auto 1rem;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Empty / error state */
.ipo-empty {
    text-align: center;
    padding: 3rem;
    color: var(--text-secondary);
    font-size: 0.875rem;
    line-height: 1.7;
}

/* Subscription-required card */
.sub-required {
    text-align: center;
    padding: 3rem 2rem;
    border-radius: 12px;
    background: var(--surface-1);
    border: 1px solid var(--border);
}
.sub-required .sub-icon {
    font-size: 2.2rem;
    margin-bottom: 0.75rem;
    line-height: 1;
}
.sub-required h3 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.45rem;
    color: var(--cream);
    margin: 0 0 0.5rem;
}
.sub-required p {
    font-size: 0.84rem;
    color: var(--text-secondary);
    max-width: 380px;
    margin: 0 auto 1.25rem;
    line-height: 1.65;
}
.sub-required a.btn-contact {
    display: inline-block;
    padding: 0.6rem 1.5rem;
    background: var(--mid);
    color: #fff;
    border-radius: 8px;
    font-size: 0.85rem;
    text-decoration: none;
    transition: background 0.2s;
}
.sub-required a.btn-contact:hover { background: var(--bright); }

@media (max-width: 640px) {
    .ipo-tab { padding: 0.55rem 0.9rem; font-size: 0.65rem; }
}
</style>

<!-- Page header -->
<p class="page-eyebrow">Advisory</p>
<h1 class="page-title">IPO Tracker</h1>
<p class="page-desc">Live IPO calendar — upcoming issues, open subscriptions, and listed performance data.</p>

<!-- Disclaimer (mandatory — IPOs are securities) -->
<div class="disclaimer disclaimer--stock">
  <strong>⚠ Research Note — Not Investment Advice</strong>
  IPO information is for educational purposes only. Prime Financials is NOT a SEBI Registered Investment Advisor.
  GMP (Grey Market Premium) is unofficial, unregulated, and indicative only.
  Allotment is subject to SEBI rules. Do not make investment decisions based solely on this data.
  Please consult a SEBI Registered Investment Advisor before investing.
</div>

<!-- Tabs -->
<div class="ipo-tabs" role="tablist" aria-label="IPO categories">
    <button class="ipo-tab active" role="tab" aria-selected="true"  data-tab="upcoming">Upcoming</button>
    <button class="ipo-tab"        role="tab" aria-selected="false" data-tab="recent">Open / Recent</button>
    <button class="ipo-tab"        role="tab" aria-selected="false" data-tab="listed">Listed Performance</button>
</div>

<!-- Panel: Upcoming -->
<div class="ipo-panel active" id="panel-upcoming" role="tabpanel">
    <div class="ipo-loading" id="loading-upcoming">
        <div class="spinner"></div>
        Loading upcoming IPOs…
    </div>
    <div id="content-upcoming" style="display:none"></div>
</div>

<!-- Panel: Recent / Open -->
<div class="ipo-panel" id="panel-recent" role="tabpanel">
    <div class="ipo-loading" id="loading-recent" style="display:none">
        <div class="spinner"></div>
        Loading open &amp; recent IPOs…
    </div>
    <div id="content-recent" style="display:none"></div>
</div>

<!-- Panel: Listed -->
<div class="ipo-panel" id="panel-listed" role="tabpanel">
    <div class="ipo-loading" id="loading-listed" style="display:none">
        <div class="spinner"></div>
        Loading listed performance…
    </div>
    <div id="content-listed" style="display:none"></div>
</div>

<!-- Floating WhatsApp CTA -->
<a href="https://wa.me/919980001338?text=Hi%2C+I+need+help+with+my+finances+on+primefin.in"
   class="whatsapp-float" target="_blank" rel="noopener">
  <span>💬</span> Chat with Advisor
</a>

<script>
(function () {
    'use strict';

    const BASE_URL  = '<?= rtrim(SITE_URL, '/') ?>/api/ipo-data.php';
    const WA_BASE   = 'https://wa.me/919980001338?text=';
    const fetched   = {};   // cache per type — avoid duplicate requests

    // ── helpers ──────────────────────────────────────────────────────────

    function esc(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function fmtDate(d) {
        if (!d) return '—';
        const [y, m, day] = d.split('-');
        const mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return (mo[parseInt(m, 10) - 1] || m) + ' ' + parseInt(day, 10) + ', ' + y;
    }

    function gmpBadge(gmp) {
        if (gmp == null) return '<span class="gmp-pill gmp-nil">N/A</span>';
        if (parseFloat(gmp) > 0) return '<span class="gmp-pill gmp-pos">₹' + parseFloat(gmp).toLocaleString('en-IN') + '</span>';
        return '<span class="gmp-pill gmp-nil">₹0</span>';
    }

    function statusBadge(status) {
        const cfg = {
            upcoming:  { cls: 'status-upcoming',  label: 'Upcoming'   },
            open:      { cls: 'status-open',       label: 'Open'       },
            allotment: { cls: 'status-allotment',  label: 'Allotment'  },
            listed:    { cls: 'status-listed',     label: 'Listed'     },
        };
        const c = cfg[status] || { cls: 'status-upcoming', label: status };
        return '<span class="status-pill ' + c.cls + '">' + esc(c.label) + '</span>';
    }

    function waBtn(company) {
        const msg = encodeURIComponent('Hi, I want to know more about the ' + company + ' IPO. Please guide me.');
        return '<a href="' + WA_BASE + msg + '" class="btn-wa" target="_blank" rel="noopener" title="Ask advisor">💬 Ask</a>';
    }

    function subRequiredHTML() {
        return `
        <div class="sub-required">
          <div class="sub-icon">🔒</div>
          <h3>IPO Data Unavailable</h3>
          <p>Live IPO data requires a higher mfapis.club plan tier.
             Contact us to enable this feature for your account.</p>
          <a href="mailto:support@primefin.in" class="btn-contact">Contact support@primefin.in</a>
        </div>`;
    }

    function emptyHTML(msg) {
        return `<div class="ipo-empty">${esc(msg) || 'No IPOs found at this time.'}</div>`;
    }

    // ── Table renderers ───────────────────────────────────────────────────

    function renderUpcoming(ipos) {
        if (!ipos.length) return emptyHTML('No upcoming IPOs found.');
        let rows = ipos.map(ipo => `
        <tr>
          <td>
            <div class="company-name">${esc(ipo.company)}</div>
            ${ipo.symbol ? '<div class="company-sym">' + esc(ipo.symbol) + '</div>' : ''}
          </td>
          <td>${fmtDate(ipo.open_date)}</td>
          <td>${fmtDate(ipo.close_date)}</td>
          <td>${esc(ipo.price_band) || '—'}</td>
          <td>${ipo.lot_size ? Number(ipo.lot_size).toLocaleString('en-IN') : '—'}</td>
          <td>${ipo.issue_size_cr ? '₹' + parseFloat(ipo.issue_size_cr).toLocaleString('en-IN', {maximumFractionDigits:0}) + ' Cr' : '—'}</td>
          <td>${gmpBadge(ipo.gmp)}</td>
          <td>${waBtn(ipo.company)}</td>
        </tr>`).join('');
        return `
        <div class="portal-card" style="padding:0">
          <div class="table-wrap">
            <table class="ipo-table">
              <thead>
                <tr>
                  <th>Company</th>
                  <th>Open Date</th>
                  <th>Close Date</th>
                  <th>Price Band</th>
                  <th>Lot Size</th>
                  <th>Issue Size</th>
                  <th>GMP</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        </div>
        <p style="font-size:0.72rem;color:var(--text-muted);margin-top:0.75rem;font-family:'DM Mono',monospace">
          GMP = Grey Market Premium (unofficial, indicative only)
        </p>`;
    }

    function renderRecent(ipos) {
        if (!ipos.length) return emptyHTML('No open or recent IPOs found.');
        let rows = ipos.map(ipo => `
        <tr>
          <td>
            <div class="company-name">${esc(ipo.company)}</div>
            ${ipo.symbol ? '<div class="company-sym">' + esc(ipo.symbol) + '</div>' : ''}
          </td>
          <td>${statusBadge(ipo.status)}</td>
          <td>${fmtDate(ipo.close_date)}</td>
          <td>${ipo.lot_size ? Number(ipo.lot_size).toLocaleString('en-IN') : '—'}</td>
          <td>${ipo.subscription_x != null ? parseFloat(ipo.subscription_x).toFixed(2) + 'x' : '—'}</td>
          <td>${gmpBadge(ipo.gmp)}</td>
          <td>${waBtn(ipo.company)}</td>
        </tr>`).join('');
        return `
        <div class="portal-card" style="padding:0">
          <div class="table-wrap">
            <table class="ipo-table">
              <thead>
                <tr>
                  <th>Company</th>
                  <th>Status</th>
                  <th>Close Date</th>
                  <th>Lot Size</th>
                  <th>Subscription</th>
                  <th>GMP</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        </div>
        <p style="font-size:0.72rem;color:var(--text-muted);margin-top:0.75rem;font-family:'DM Mono',monospace">
          GMP = Grey Market Premium (unofficial, indicative only)
        </p>`;
    }

    function renderListed(ipos) {
        if (!ipos.length) return emptyHTML('No recently listed IPOs found.');
        let rows = ipos.map(ipo => {
            const gain = ipo.listing_gain_pct;
            let gainHtml;
            if (gain == null) {
                gainHtml = '<span class="gain-nil">—</span>';
            } else {
                const f = parseFloat(gain);
                const cls = f > 0 ? 'gain-pos' : f < 0 ? 'gain-neg' : 'gain-nil';
                const sign = f > 0 ? '+' : '';
                gainHtml = '<span class="' + cls + '">' + sign + f.toFixed(2) + '%</span>';
            }
            const priceBand = ipo.price_band || '—';
            const listPrice = ipo.listing_price != null
                ? '₹' + parseFloat(ipo.listing_price).toLocaleString('en-IN', {maximumFractionDigits:2})
                : '—';
            return `
            <tr>
              <td>
                <div class="company-name">${esc(ipo.company)}</div>
                ${ipo.symbol ? '<div class="company-sym">' + esc(ipo.symbol) + '</div>' : ''}
              </td>
              <td>${fmtDate(ipo.listing_date)}</td>
              <td>${esc(priceBand)}</td>
              <td>${listPrice}</td>
              <td>${gainHtml}</td>
              <td>${statusBadge(ipo.status)}</td>
              <td>${waBtn(ipo.company)}</td>
            </tr>`;
        }).join('');
        return `
        <div class="portal-card" style="padding:0">
          <div class="table-wrap">
            <table class="ipo-table">
              <thead>
                <tr>
                  <th>Company</th>
                  <th>Listing Date</th>
                  <th>Issue Price</th>
                  <th>Listing Price</th>
                  <th>Listing Gain</th>
                  <th>Current Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        </div>`;
    }

    // ── Fetch + render ────────────────────────────────────────────────────

    function loadTab(type) {
        if (fetched[type]) return;   // already fetched
        fetched[type] = true;

        const loadingEl = document.getElementById('loading-' + type);
        const contentEl = document.getElementById('content-' + type);

        if (loadingEl) loadingEl.style.display = 'block';
        if (contentEl) contentEl.style.display = 'none';

        fetch(BASE_URL + '?type=' + encodeURIComponent(type), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(json => {
            if (loadingEl) loadingEl.style.display = 'none';
            if (!contentEl) return;
            contentEl.style.display = 'block';

            if (json.error === 'subscription_required') {
                contentEl.innerHTML = subRequiredHTML();
                return;
            }
            if (json.error || !Array.isArray(json.ipos)) {
                contentEl.innerHTML = emptyHTML('IPO data is temporarily unavailable. Please try again later.');
                return;
            }

            if (type === 'upcoming') contentEl.innerHTML = renderUpcoming(json.ipos);
            else if (type === 'recent') contentEl.innerHTML = renderRecent(json.ipos);
            else if (type === 'listed') contentEl.innerHTML = renderListed(json.ipos);
        })
        .catch(err => {
            console.error('IPO fetch error:', err);
            if (loadingEl) loadingEl.style.display = 'none';
            if (contentEl) {
                contentEl.style.display = 'block';
                contentEl.innerHTML = emptyHTML('Could not load IPO data. Please check your connection and try again.');
            }
        });
    }

    // ── Tab switching ─────────────────────────────────────────────────────

    document.querySelectorAll('.ipo-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.tab;

            // Update tab buttons
            document.querySelectorAll('.ipo-tab').forEach(b => {
                b.classList.toggle('active', b === btn);
                b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
            });

            // Update panels
            document.querySelectorAll('.ipo-panel').forEach(p => {
                p.classList.toggle('active', p.id === 'panel-' + target);
            });

            // Lazy-load data on first click
            loadTab(target);
        });
    });

    // Auto-load upcoming on page load
    loadTab('upcoming');

})();
</script>

<?php require_once '../includes/portal-footer.php'; ?>
