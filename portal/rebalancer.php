<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/subscription.php';
require_login();
require_role('client');
require_premium('rebalancer');

$user_id = get_user_id();
$db      = get_db();

$mf_count  = (int)$db->query("SELECT COUNT(*) FROM portfolio_entries WHERE user_id={$user_id} AND fund_type IN ('equity','debt','hybrid','elss','index','international','liquid','gold')")->fetchColumn();
$eq_count  = (int)$db->query("SELECT COUNT(*) FROM portfolio_entries WHERE user_id={$user_id} AND fund_type='equity'")->fetchColumn();
$last_mf   = $db->query("SELECT generated_at FROM rebalancer_results WHERE user_id={$user_id} AND rebalance_type='mutual_fund' ORDER BY generated_at DESC LIMIT 1")->fetchColumn();
$last_eq   = $db->query("SELECT generated_at FROM rebalancer_results WHERE user_id={$user_id} AND rebalance_type='equity' ORDER BY generated_at DESC LIMIT 1")->fetchColumn();

$page_title = 'Portfolio Rebalancer — Prime Financials';
require_once '../includes/portal-header.php';
?>

<style>
.rb-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-top:1.5rem; }
@media(max-width:900px){ .rb-grid{ grid-template-columns:1fr; } }

.rb-card { background:var(--surface-1); border:1px solid var(--border); border-radius:14px; overflow:hidden; display:flex; flex-direction:column; }

.rb-card-header { padding:1.25rem 1.5rem; border-bottom:1px solid var(--border-light); }
.rb-card-title-row { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; }
.rb-card-title { display:flex; align-items:center; gap:0.75rem; }
.rb-card-icon { width:40px; height:40px; border-radius:10px; background:var(--mid-pale); display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
.rb-card-name { font-family:'Cormorant Garamond',serif; font-size:1.15rem; font-weight:600; color:var(--cream); line-height:1.2; margin-bottom:0.15rem; }
.rb-card-sub { font-family:'IBM Plex Mono',monospace; font-size:0.58rem; color:var(--lime); text-transform:uppercase; letter-spacing:0.15em; }
.rb-card-meta { display:flex; flex-direction:column; align-items:flex-end; gap:0.35rem; flex-shrink:0; }
.rb-last-run { font-family:'IBM Plex Mono',monospace; font-size:0.65rem; color:var(--text-muted); }

.rb-run-btn { background:var(--mid); color:#fff; border:none; border-radius:7px; padding:0.5rem 1.1rem; font-size:0.82rem; font-family:'Inter',sans-serif; font-weight:500; cursor:pointer; transition:background 0.15s; white-space:nowrap; }
.rb-run-btn:hover:not(:disabled){ background:var(--bright); }
.rb-run-btn:disabled { background:var(--surface-2); color:var(--text-muted); cursor:not-allowed; border:1px solid var(--border); }

.rb-body { padding:1.25rem 1.5rem; flex:1; }

.rb-idle { text-align:center; padding:1.5rem 0.5rem; color:var(--text-secondary); font-size:0.875rem; line-height:1.7; }
.rb-idle p { margin-bottom:0.4rem; }
.rb-note { font-size:0.75rem; color:var(--text-muted); }
.rb-empty { text-align:center; padding:1.5rem 0.5rem; color:var(--text-secondary); font-size:0.875rem; }
.rb-empty a { color:var(--lime); display:block; margin-top:0.35rem; }

.rb-loading { text-align:center; padding:2rem; }
.rb-spinner { width:32px; height:32px; border:3px solid var(--border); border-top-color:var(--mid); border-radius:50%; animation:rb-spin 0.8s linear infinite; margin:0 auto 0.75rem; }
@keyframes rb-spin{ to{ transform:rotate(360deg); } }

/* ── Result rendering styles ── */
.rb-score-row { display:flex; align-items:baseline; gap:0.75rem; margin-bottom:0.6rem; }
.rb-score { font-family:'Cormorant Garamond',serif; font-size:2.2rem; font-weight:700; color:var(--lime); line-height:1; }
.rb-health { font-family:'IBM Plex Mono',monospace; font-size:0.62rem; letter-spacing:0.1em; color:var(--text-muted); text-transform:uppercase; }
.rb-summary { font-size:0.875rem; color:var(--text-secondary); line-height:1.65; margin-bottom:1.25rem; }

.rb-alloc { display:flex; flex-direction:column; gap:0.5rem; margin-bottom:1.25rem; padding:1rem; background:var(--surface-2); border-radius:8px; border:1px solid var(--border-light); }
.rb-alloc-title { font-family:'IBM Plex Mono',monospace; font-size:0.6rem; color:var(--lime); letter-spacing:0.15em; text-transform:uppercase; margin-bottom:0.25rem; }
.rb-alloc-row { display:flex; align-items:center; gap:0.6rem; font-size:0.78rem; }
.rb-alloc-name { width:50px; color:var(--text-secondary); flex-shrink:0; }
.rb-alloc-track { flex:1; height:6px; background:var(--surface-1); border-radius:3px; overflow:hidden; }
.rb-alloc-fill { height:100%; background:var(--mid); border-radius:3px; transition:width 0.6s; }
.rb-alloc-pct { width:36px; text-align:right; font-family:'IBM Plex Mono',monospace; color:var(--cream); }
.rb-alloc-flag { font-size:0.68rem; white-space:nowrap; }

.rb-holdings { display:flex; flex-direction:column; gap:0.6rem; margin-bottom:1rem; }
.rb-holding { background:var(--surface-2); border:1px solid var(--border-light); border-radius:8px; padding:0.875rem; }
.rb-holding.pri-urgent   { border-left:3px solid #ef5350; }
.rb-holding.pri-moderate { border-left:3px solid var(--gold); }
.rb-holding-top { display:flex; justify-content:space-between; align-items:flex-start; gap:0.5rem; margin-bottom:0.4rem; }
.rb-fund-name { font-size:0.875rem; font-weight:500; color:var(--cream); line-height:1.3; }
.rb-verdict { font-family:'IBM Plex Mono',monospace; font-size:0.6rem; letter-spacing:0.06em; padding:0.22rem 0.55rem; border-radius:4px; font-weight:600; flex-shrink:0; white-space:nowrap; }
.rb-meta-row { display:flex; flex-wrap:wrap; gap:0.5rem; font-family:'IBM Plex Mono',monospace; font-size:0.65rem; color:var(--text-muted); margin-bottom:0.4rem; }
.rb-sip-pill { border:1px solid currentColor; border-radius:10px; padding:0.08rem 0.45rem; }
.rb-reason { font-size:0.8rem; color:var(--text-secondary); line-height:1.55; }
.rb-action { font-size:0.78rem; color:var(--lime); margin-top:0.3rem; }
.rb-tax-note { font-size:0.75rem; color:var(--gold); margin-bottom:0.3rem; }

.rb-section { background:var(--surface-2); border:1px solid var(--border-light); border-radius:8px; padding:0.875rem; margin-bottom:0.75rem; }
.rb-section-title { font-family:'IBM Plex Mono',monospace; font-size:0.6rem; letter-spacing:0.15em; color:var(--lime); text-transform:uppercase; margin-bottom:0.6rem; }
.rb-section ul, .rb-section ol { font-size:0.8rem; color:var(--text-secondary); padding-left:1.1rem; display:flex; flex-direction:column; gap:0.4rem; line-height:1.55; }

.rb-sector-row { display:flex; align-items:center; gap:0.5rem; font-size:0.78rem; padding:0.25rem 0; flex-wrap:wrap; }
.rb-sector-name { width:80px; flex-shrink:0; }
.rb-sector-track { flex:1; height:7px; background:var(--surface-1); border-radius:3px; overflow:hidden; min-width:50px; }
.rb-sector-fill { height:100%; background:var(--mid); border-radius:3px; }
.rb-sector-row.flagged .rb-sector-fill { background:var(--gold); }
.rb-sector-pct { width:32px; text-align:right; font-family:'IBM Plex Mono',monospace; flex-shrink:0; }
.rb-flag { font-size:0.68rem; color:var(--gold); }
.rb-ok { color:var(--bright); }

.rb-disclaimer { font-size:0.72rem; color:var(--text-muted); line-height:1.6; margin:0.75rem 0; }
.rb-wa-btn { display:block; text-align:center; background:#25d366; color:#fff; padding:0.7rem; border-radius:8px; font-size:0.875rem; font-weight:500; margin-top:0.75rem; text-decoration:none; transition:opacity 0.2s; }
.rb-wa-btn:hover { opacity:0.9; color:#fff; }
.rb-error { color:#ef5350; font-size:0.875rem; padding:1.5rem; text-align:center; background:rgba(239,83,80,0.07); border-radius:8px; }
.gain-pos { color:var(--bright); }
.gain-neg { color:#ef5350; }
.research-badge { color:var(--lime); }
.rb-report-actions { display:flex; gap:0.65rem; margin-top:1rem; flex-wrap:wrap; }
.rb-action-btn { display:flex; align-items:center; gap:0.4rem; padding:0.55rem 1rem; border-radius:7px; font-size:0.82rem; font-family:'Inter',sans-serif; font-weight:500; cursor:pointer; border:none; transition:all 0.18s; }
.rb-action-btn--pdf  { background:var(--mid); color:#fff; }
.rb-action-btn--pdf:hover  { background:var(--bright); }
.rb-action-btn--email { background:transparent; color:var(--lime); border:1px solid var(--lime); }
.rb-action-btn--email:hover { background:rgba(141,198,63,0.1); }
.rb-action-btn:disabled { opacity:0.55; cursor:not-allowed; }
</style>

<p class="page-eyebrow">My Finances</p>
<h1 class="page-title">Portfolio Rebalancer</h1>
<p class="page-subtitle">AI-powered analysis of your holdings — powered by PrimoAI. Limit: 5 runs per card per day.</p>

<div class="rb-grid">

  <!-- ── Card 1: MF Rebalancer ── -->
  <div class="rb-card">
    <div class="rb-card-header">
      <div class="rb-card-title-row">
        <div class="rb-card-title">
          <div class="rb-card-icon"><i class="bi bi-arrow-repeat" style="color:var(--lime)"></i></div>
          <div>
            <div class="rb-card-name">Mutual Fund Rebalancer</div>
            <div class="rb-card-sub">AMFI-compliant advisory</div>
          </div>
        </div>
        <div class="rb-card-meta">
          <?php if ($last_mf): ?><span class="rb-last-run">Last: <?= date('d M Y', strtotime($last_mf)) ?></span><?php endif; ?>
          <button class="rb-run-btn" id="runMF" onclick="runRebalancer('mutual_fund')" <?= !$mf_count ? 'disabled' : '' ?>>
            <?= $mf_count ? 'Run Analysis →' : 'No Holdings' ?>
          </button>
        </div>
      </div>
    </div>
    <div class="rb-body" id="mfBody">
      <?php if (!$mf_count): ?>
        <div class="rb-empty">
          <i class="bi bi-inbox" style="font-size:2rem;color:var(--lime);display:block;margin-bottom:0.5rem"></i>
          <p>No mutual fund holdings found.</p>
          <a href="<?= SITE_URL ?>/portal/portfolio.php">Add holdings manually →</a>
          <a href="<?= SITE_URL ?>/portal/primo.php">Upload CAS statement via PrimoAI →</a>
        </div>
      <?php else: ?>
        <div class="rb-idle">
          <p>Click <strong style="color:var(--cream)">Run Analysis</strong> to get AI-powered rebalancing recommendations for your <strong style="color:var(--cream)"><?= $mf_count ?> MF holding<?= $mf_count!==1?'s':'' ?></strong>.</p>
          <p class="rb-note">Checks allocation drift, underperformers, overlap &amp; SIP strategy. ~15–30 sec.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Card 2: Equity Analyser ── -->
  <div class="rb-card">
    <div class="rb-card-header">
      <div class="rb-card-title-row">
        <div class="rb-card-title">
          <div class="rb-card-icon"><i class="bi bi-graph-up" style="color:var(--lime)"></i></div>
          <div>
            <div class="rb-card-name">Equity Portfolio Analyser</div>
            <div class="rb-card-sub">Research &amp; educational view</div>
          </div>
        </div>
        <div class="rb-card-meta">
          <?php if ($last_eq): ?><span class="rb-last-run">Last: <?= date('d M Y', strtotime($last_eq)) ?></span><?php endif; ?>
          <button class="rb-run-btn" id="runEQ" onclick="runRebalancer('equity')" <?= !$eq_count ? 'disabled' : '' ?>>
            <?= $eq_count ? 'Run Analysis →' : 'No Holdings' ?>
          </button>
        </div>
      </div>
    </div>
    <div class="disclaimer disclaimer--stock" style="margin:0.75rem 1.5rem 0;font-size:0.78rem">
      <strong>⚠ Research Note — Not Investment Advice.</strong>
      Educational analysis only. Prime Financials is NOT a SEBI RIA. Consult a SEBI RIA before acting on any suggestion.
    </div>
    <div class="rb-body" id="eqBody">
      <?php if (!$eq_count): ?>
        <div class="rb-empty">
          <i class="bi bi-inbox" style="font-size:2rem;color:var(--lime);display:block;margin-bottom:0.5rem"></i>
          <p>No equity stock holdings found.</p>
          <a href="<?= SITE_URL ?>/portal/portfolio.php">Add stock holdings →</a>
          <a href="<?= SITE_URL ?>/portal/primo.php">Upload broker statement via PrimoAI →</a>
        </div>
      <?php else: ?>
        <div class="rb-idle">
          <p>Click <strong style="color:var(--cream)">Run Analysis</strong> for a research-based view of your <strong style="color:var(--cream)"><?= $eq_count ?> stock<?= $eq_count!==1?'s':'' ?></strong> — sector concentration, tax opportunities, position sizing.</p>
          <p class="rb-note">~15–30 seconds.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
const SITE_URL_JS = '<?= SITE_URL ?>';
const MF_URL = '<?= SITE_URL ?>/ai/rebalance-mf.php';
const ONBOARDING_URL = '<?= ONBOARDING_URL ?>';
const CALENDLY_URL   = '<?= CALENDLY_URL ?>';
const EQ_URL = '<?= SITE_URL ?>/ai/rebalance-equity.php';
const WA_NUM = '<?= WHATSAPP_NUM ?>';
const CSRF   = document.querySelector('meta[name="csrf-token"]').content;

function e(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

const RB_STEPS = {
  mutual_fund: [
    { t:  0, msg: 'Fetching your mutual fund holdings…' },
    { t:  4, msg: 'Classifying fund types & categories…' },
    { t:  9, msg: 'Calculating portfolio weights & drift…' },
    { t: 14, msg: 'Comparing allocation against your risk profile…' },
    { t: 19, msg: 'Sending portfolio to PrimoAI for analysis…' },
    { t: 27, msg: 'AI is generating rebalancing recommendations…' },
    { t: 38, msg: 'Processing AI output & building your report…' },
    { t: 50, msg: 'Saving results — almost done…' },
    { t: 65, msg: 'Large portfolio — AI is still working, please wait…' },
    { t: 80, msg: 'Still processing — this can take up to 2 minutes…' },
    { t: 100, msg: 'Almost there — finalising your recommendations…' },
    { t: 120, msg: 'Taking a bit longer than usual — still running…' },
  ],
  equity: [
    { t:  0, msg: 'Fetching your equity holdings…' },
    { t:  4, msg: 'Filtering individual stocks…' },
    { t:  8, msg: 'Analysing sector concentration & weights…' },
    { t: 13, msg: 'Checking LTCG / STCG tax positions…' },
    { t: 18, msg: 'Sending holdings to PrimoAI for analysis…' },
    { t: 26, msg: 'AI is reviewing research notes & patterns…' },
    { t: 38, msg: 'Processing recommendations & alerts…' },
    { t: 50, msg: 'Saving results — almost done…' },
    { t: 65, msg: 'Still working — AI is finalising insights…' },
    { t: 80, msg: 'This can take up to 2 minutes for large portfolios…' },
    { t: 100, msg: 'Almost there — wrapping up your report…' },
    { t: 120, msg: 'Taking a bit longer than usual — still running…' },
  ],
};

async function runRebalancer(type) {
  const isMF = type === 'mutual_fund';
  const btn  = document.getElementById(isMF ? 'runMF' : 'runEQ');
  const body = document.getElementById(isMF ? 'mfBody' : 'eqBody');

  btn.disabled = true;
  btn.textContent = 'Analysing…';

  body.innerHTML = `
    <div class="rb-loading">
      <div class="rb-spinner"></div>
      <p class="rb-status-msg" style="color:var(--cream);font-size:0.9rem;font-weight:500;margin-bottom:0.25rem;transition:opacity 0.4s">
        Fetching your ${isMF ? 'mutual fund' : 'equity'} holdings…
      </p>
      <p class="rb-note rb-elapsed" style="font-variant-numeric:tabular-nums">0s elapsed</p>
    </div>`;

  const msgEl     = body.querySelector('.rb-status-msg');
  const elapsedEl = body.querySelector('.rb-elapsed');
  const steps     = RB_STEPS[type];
  const startTime = Date.now();
  let stepIdx     = 0;

  const ticker = setInterval(() => {
    const elapsed = Math.floor((Date.now() - startTime) / 1000);
    elapsedEl.textContent = elapsed + 's elapsed';

    // Advance step if the next step's time threshold is passed
    if (stepIdx + 1 < steps.length && elapsed >= steps[stepIdx + 1].t) {
      stepIdx++;
      msgEl.style.opacity = '0';
      setTimeout(() => {
        if (msgEl.isConnected) {
          msgEl.textContent = steps[stepIdx].msg;
          msgEl.style.opacity = '1';
        }
      }, 200);
    }
    // Beyond all steps — pulse the last message every 20s so it never looks frozen
    const isLast = stepIdx === steps.length - 1;
    if (isLast && elapsed > steps[stepIdx].t && (elapsed - steps[stepIdx].t) % 20 === 0 && elapsed > steps[stepIdx].t) {
      msgEl.style.opacity = '0';
      setTimeout(() => { if (msgEl.isConnected) msgEl.style.opacity = '1'; }, 300);
    }
  }, 500);

  try {
    const resp = await fetch(isMF ? MF_URL : EQ_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ type })
    });
    const r = await resp.json();
    if (!r.success) throw new Error(r.error || 'Analysis failed');
    if (isMF) { window._rbMFData = r.data; renderMF(r.data, body); }
    else       { window._rbEQData = r.data; renderEQ(r.data, body); }
  } catch(err) {
    body.innerHTML = `<div class="rb-error">⚠ ${e(err.message)}<br><small style="color:var(--text-muted)">Please try again in a moment.</small></div>`;
  } finally {
    clearInterval(ticker);
    btn.disabled = false;
    btn.textContent = 'Re-run →';
  }
}

// ── PDF Download ───────────────────────────────────────────
function downloadPDF(type) {
  const data = type === 'mutual_fund' ? window._rbMFData : window._rbEQData;
  if (!data) return;
  const title = type === 'mutual_fund' ? 'Mutual Fund Rebalancer Report' : 'Equity Portfolio Analyser Report';
  const date  = new Date().toLocaleDateString('en-IN', { day:'numeric', month:'long', year:'numeric' });
  const body  = buildReportBody(type, data);
  const disclaimer = type === 'mutual_fund'
    ? 'Mutual Fund investments are subject to market risks. Please read all scheme-related documents carefully before investing. Past performance is not indicative of future results. Prime Financials — AMFI Registered MF Distributor (ARN-137538).'
    : '⚠ Research Note — Not Investment Advice. This analysis is for educational and informational purposes only. Prime Financials is an AMFI Registered MF Distributor and is NOT a SEBI Registered Investment Advisor (RIA). This does not constitute investment advice. Please consult a SEBI RIA before investing. Investments in securities are subject to market risks.';

  const html = `<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <title>${e(title)} — Prime Financials</title>
    <style>
      *{box-sizing:border-box;margin:0;padding:0}
      body{font-family:Arial,Helvetica,sans-serif;color:#0d1f0d;background:#fff;padding:2cm;font-size:13px}
      h3{color:#1B5E2A;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.15em;font-family:monospace;margin:1.25rem 0 0.6rem}
      table{width:100%;border-collapse:collapse;margin-bottom:1.25rem}
      th{background:#1B5E2A;color:#fff;text-align:left;padding:0.45rem 0.7rem;font-size:0.78rem}
      td{padding:0.45rem 0.7rem;border-bottom:1px solid #c8e6c9;font-size:0.8rem}
      .score{font-size:2.2rem;font-weight:700;color:#2E8540;line-height:1}
      .badge{font-family:monospace;font-size:0.62rem;padding:0.2rem 0.5rem;border-radius:4px;white-space:nowrap}
      .card{border:1px solid #c8e6c9;border-radius:6px;padding:0.8rem;margin-bottom:0.65rem}
      .card-urgent{border-left:3px solid #c62828}
      .card-moderate{border-left:3px solid #a07d2a}
      ul,ol{padding-left:1.1rem;line-height:1.65}li{margin-bottom:0.3rem}
      @media print{body{padding:1cm}}
    </style>
  </head><body>
    <div style="display:flex;align-items:center;gap:1rem;border-bottom:2px solid #2E8540;padding-bottom:1rem;margin-bottom:1.5rem">
      <img src="${SITE_URL_JS}/logo.png" alt="Prime Financials" style="height:44px;object-fit:contain">
      <div>
        <div style="font-size:1.2rem;font-weight:700;color:#1B5E2A">Prime Financials</div>
        <div style="font-size:0.72rem;color:#666">AMFI Registered MF Distributor · ARN-137538 · primefin.in</div>
      </div>
    </div>
    <h1 style="font-size:1.3rem;color:#1B5E2A;margin-bottom:0.2rem">${e(title)}</h1>
    <p style="font-size:0.78rem;color:#888;margin-bottom:1.5rem">Generated on ${date} · Prime Financials Client Portal</p>
    ${body}
    <div style="margin-top:2rem;padding:0.875rem 1rem;background:#f5f9f5;border-left:3px solid #2E8540;font-size:0.72rem;color:#555;line-height:1.7">${disclaimer}</div>
    <div style="margin-top:1.25rem;padding-top:0.75rem;border-top:1px solid #ddd;display:flex;justify-content:space-between;font-size:0.68rem;color:#aaa">
      <span>Prime Financials · support@primefin.in · +91 9980001338</span><span>primefin.in</span>
    </div>
  </body></html>`;

  const w = window.open('', '_blank');
  if (!w) { alert('Allow pop-ups for this site to download the PDF.'); return; }
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 600);
}

// ── Email Report ────────────────────────────────────────────
async function emailReport(type, btn) {
  const data = type === 'mutual_fund' ? window._rbMFData : window._rbEQData;
  if (!data) return;
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sending…';
  try {
    const res = await fetch(SITE_URL_JS + '/portal/send-rebalancer-report.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ type, data })
    });
    const r = await res.json();
    if (!r.success) throw new Error(r.error || 'Failed');
    btn.innerHTML = '<i class="bi bi-check-circle"></i> Email Sent!';
    btn.style.cssText += ';background:#2E8540;color:#fff;border-color:#2E8540';
    setTimeout(() => { btn.innerHTML = orig; btn.removeAttribute('style'); btn.disabled = false; }, 3500);
  } catch (err) {
    btn.innerHTML = '<i class="bi bi-x-circle"></i> Failed';
    btn.style.cssText += ';background:#ef5350;color:#fff;border-color:#ef5350';
    setTimeout(() => { btn.innerHTML = orig; btn.removeAttribute('style'); btn.disabled = false; }, 3000);
  }
}

// ── Report body builder (shared by PDF + Email) ─────────────
function buildReportBody(type, d) {
  const isMF = type === 'mutual_fund';
  const G = '#2E8540', R = '#c62828', Y = '#a07d2a', B = '#1565c0', DIM = '#5a7a5a', BDR = '#c8e6c9';
  const MFV = { hold:{l:'HOLD',c:G}, buy_more:{l:'BUY MORE',c:B}, switch:{l:'SWITCH',c:Y}, sell:{l:'SELL',c:R} };
  const EQV = { hold:{l:'HOLD',c:G}, accumulate:{l:'ACCUMULATE',c:B}, reduce:{l:'CONSIDER REDUCING',c:Y}, exit:{l:'REVIEW POSITION',c:R}, review:{l:'MONITOR CLOSELY',c:'#f57f17'} };
  const SIPL = { continue:'Continue SIP', increase:'Increase SIP', decrease:'Reduce SIP', stop:'Stop SIP' };
  const hcol = isMF ? ({good:G,fair:Y,needs_attention:R}[d.overall_health]||G) : G;
  let c = `<div style="display:flex;align-items:baseline;gap:0.75rem;margin-bottom:0.4rem">
    <span style="font-size:2.2rem;font-weight:700;color:${hcol};line-height:1">${(isMF?d.overall_score:d.overall_score)||0}/100</span>
    <span style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.1em;color:${DIM};font-family:monospace">${e((isMF?d.overall_health:d.overall_assessment||'').replace(/_/g,' ').toUpperCase())}</span>
  </div><p style="font-size:0.85rem;color:#0d1f0d;line-height:1.65;margin-bottom:1.25rem">${e(d.summary||'')}</p>`;

  if (isMF) {
    if (d.current_allocation && d.target_allocation) {
      c += `<h3>Allocation Drift</h3><table><thead><tr><th>Category</th><th style="text-align:right">Current</th><th style="text-align:right">Target</th><th style="text-align:right">Drift</th></tr></thead><tbody>`;
      [['Equity','equity'],['Debt','debt'],['Others','others']].forEach(([n,k])=>{
        const cur=d.current_allocation[k+'_pct']||0,tgt=d.target_allocation[k+'_pct']||0,diff=cur-tgt,ok=Math.abs(diff)<=5;
        c+=`<tr><td>${n}</td><td style="text-align:right">${cur}%</td><td style="text-align:right">${tgt}%</td><td style="text-align:right;color:${ok?G:Y}">${diff>0?'+':''}${diff}% ${ok?'✓':'⚠'}</td></tr>`;
      });
      c += `</tbody></table>`;
    }
    c += `<h3>Holdings Analysis</h3>`;
    (d.holdings||[]).forEach(f=>{
      const v=MFV[f.verdict]||MFV.hold, sl=SIPL[f.sip_recommendation]||'Continue SIP';
      c+=`<div class="card ${f.priority==='urgent'?'card-urgent':f.priority==='moderate'?'card-moderate':''}">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.35rem">
          <strong style="font-size:0.875rem">${e(f.fund_name||'')}</strong>
          <span class="badge" style="background:${v.c}20;color:${v.c};border:1px solid ${v.c}">${v.l}</span>
        </div>
        <div style="font-family:monospace;font-size:0.65rem;color:${DIM};margin-bottom:0.35rem">Weight: ${f.weight_in_portfolio_pct||0}% · ${e(sl)}${f.return_assessment?' · '+e(f.return_assessment.replace(/_/g,' ')):''}</div>
        <p style="font-size:0.8rem;color:#0d1f0d;line-height:1.55;margin:0">${e(f.reason||'')}</p>
        ${f.action_detail?`<p style="font-size:0.78rem;color:${G};margin:0.3rem 0 0">→ ${e(f.action_detail)}</p>`:''}
      </div>`;
    });
    if ((d.rebalancing_actions||[]).length) {
      c += `<h3>Recommended Actions</h3><ol>`;
      d.rebalancing_actions.forEach(a=>{
        c+=`<li><strong>${e(a.action_type.replace(/_/g,' ').toUpperCase())}</strong>${a.from_fund?' — '+e(a.from_fund)+(a.to_fund?' → '+e(a.to_fund):''):''}<br><span style="color:${DIM};font-size:0.78rem">${e(a.reason||'')}</span></li>`;
      });
      c += `</ol>`;
    }
  } else {
    if ((d.sector_breakdown||[]).length) {
      c += `<h3>Sector Breakdown</h3><table><thead><tr><th>Sector</th><th style="text-align:right">Allocation</th><th style="text-align:center">Status</th></tr></thead><tbody>`;
      d.sector_breakdown.forEach(s=>{
        c+=`<tr><td>${e(s.sector||'')}</td><td style="text-align:right">${s.allocation_pct||0}%</td><td style="text-align:center;color:${s.flag?Y:G}">${s.flag?'⚠ Concentrated':'✓ OK'}</td></tr>`;
      });
      c += `</tbody></table>`;
    }
    c += `<h3>Holdings Analysis</h3>`;
    (d.holdings||[]).forEach(f=>{
      const v=EQV[f.verdict]||EQV.hold,gp=f.unrealised_gain_pct||0;
      c+=`<div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.35rem">
          <strong style="font-size:0.875rem">${e(f.stock_name||'')}${f.ticker?` <span style="font-family:monospace;font-size:0.7em;color:${DIM}">(${e(f.ticker)})</span>`:''}</strong>
          <span class="badge" style="background:${v.c}20;color:${v.c};border:1px solid ${v.c}">${v.l}</span>
        </div>
        <div style="font-family:monospace;font-size:0.65rem;color:${DIM};margin-bottom:0.35rem">Weight: ${f.weight_in_equity_pct||0}% · <span style="color:${gp>=0?G:R}">${gp>=0?'+':''}${gp}% unrealised</span> · ${f.holding_period_days||0}d held</div>
        ${f.tax_note?`<p style="font-size:0.75rem;color:${Y};margin:0 0 0.3rem">💰 ${e(f.tax_note)}</p>`:''}
        <p style="font-size:0.8rem;color:#0d1f0d;line-height:1.55;margin:0">${e(f.reason||'')}</p>
        ${f.action_detail?`<p style="font-size:0.78rem;color:${G};margin:0.3rem 0 0">→ ${e(f.action_detail)}</p>`:''}
      </div>`;
    });
    if ((d.tax_opportunities||[]).length) {
      c+=`<h3>💡 Tax Opportunities</h3><ul>`;
      d.tax_opportunities.forEach(t=>{c+=`<li>${e(t.description||'')}</li>`;});
      c+=`</ul>`;
    }
    if ((d.concentration_alerts||[]).length) {
      c+=`<h3>⚠ Concentration Alerts</h3><ul>`;
      d.concentration_alerts.forEach(a=>{c+=`<li>${e(a.description||'')}<br><span style="color:${DIM};font-size:0.78rem">${e(a.suggestion||'')}</span></li>`;});
      c+=`</ul>`;
    }
  }
  return c;
}

// ── Verdict config ─────────────────────────────────────────
const MF_VERDICTS = {
  hold:     { l:'HOLD',     bg:'rgba(27,94,42,0.25)',     c:'#4CAF50' },
  buy_more: { l:'BUY MORE', bg:'rgba(66,165,245,0.15)',   c:'#64b5f6' },
  switch:   { l:'SWITCH',   bg:'rgba(201,168,76,0.18)',   c:'#C9A84C' },
  sell:     { l:'SELL',     bg:'rgba(239,83,80,0.15)',     c:'#ef5350' },
};
const EQ_VERDICTS = {
  hold:       { l:'HOLD',               bg:'rgba(27,94,42,0.25)',   c:'#4CAF50' },
  accumulate: { l:'ACCUMULATE',         bg:'rgba(66,165,245,0.15)', c:'#64b5f6' },
  reduce:     { l:'CONSIDER REDUCING',  bg:'rgba(201,168,76,0.18)', c:'#C9A84C' },
  exit:       { l:'REVIEW POSITION',    bg:'rgba(239,83,80,0.15)',  c:'#ef5350' },
  review:     { l:'MONITOR CLOSELY',    bg:'rgba(255,213,79,0.15)', c:'#ffd54f' },
};
const SIP_CFG = {
  continue: { l:'Continue SIP', c:'#4CAF50' },
  increase: { l:'Increase SIP', c:'#64b5f6' },
  decrease: { l:'Reduce SIP',   c:'#C9A84C' },
  stop:     { l:'Stop SIP',     c:'#ef5350' },
};

// ── MF result renderer ──────────────────────────────────────
function renderMF(d, el) {
  const hcol = {good:'var(--bright)',fair:'var(--gold)',needs_attention:'#ef5350'}[d.overall_health] || 'var(--lime)';
  let h = `<div class="rb-score-row">
    <span class="rb-score" style="color:${hcol}">${d.overall_score||0}/100</span>
    <span class="rb-health">${e((d.overall_health||'').replace(/_/g,' ').toUpperCase())}</span>
  </div>
  <p class="rb-summary">${e(d.summary||'')}</p>`;

  // Allocation drift
  if (d.current_allocation && d.target_allocation) {
    h += `<div class="rb-alloc"><div class="rb-alloc-title">Allocation Drift</div>`;
    [['Equity','equity'],['Debt','debt'],['Others','others']].forEach(([n,k]) => {
      const cur=d.current_allocation[k+'_pct']||0, tgt=d.target_allocation[k+'_pct']||0;
      const diff=cur-tgt, ok=Math.abs(diff)<=5;
      const fc=ok?'var(--bright)':'var(--gold)';
      const fs=ok?'✓':diff>0?`+${diff}% ⚠`:`${Math.abs(diff)}% ⚠`;
      h += `<div class="rb-alloc-row">
        <span class="rb-alloc-name">${n}</span>
        <div class="rb-alloc-track"><div class="rb-alloc-fill" style="width:${cur}%"></div></div>
        <span class="rb-alloc-pct">${cur}%</span>
        <span class="rb-alloc-flag" style="color:${fc}">${fs}</span>
      </div>`;
    });
    h += `<div style="font-size:0.68rem;color:var(--text-muted);margin-top:0.25rem;font-family:'IBM Plex Mono',monospace">Target based on your risk profile</div></div>`;
  }

  // Holdings
  h += '<div class="rb-holdings">';
  (d.holdings||[]).forEach(f => {
    const v=MF_VERDICTS[f.verdict]||MF_VERDICTS.hold;
    const s=SIP_CFG[f.sip_recommendation]||SIP_CFG.continue;
    const pc=f.priority==='urgent'?'pri-urgent':f.priority==='moderate'?'pri-moderate':'';
    h += `<div class="rb-holding ${pc}">
      <div class="rb-holding-top">
        <span class="rb-fund-name">${e(f.fund_name)}</span>
        <span class="rb-verdict" style="background:${v.bg};color:${v.c};border:1px solid ${v.c}">${v.l}</span>
      </div>
      <div class="rb-meta-row">
        <span>Weight: ${f.weight_in_portfolio_pct||0}%</span>
        ${f.return_assessment?`<span>${e(f.return_assessment.replace(/_/g,' '))}</span>`:''}
        <span class="rb-sip-pill" style="color:${s.c};border-color:${s.c}">${s.l}</span>
      </div>
      <p class="rb-reason">${e(f.reason||'')}</p>
      ${f.action_detail?`<p class="rb-action">→ ${e(f.action_detail)}</p>`:''}
    </div>`;
  });
  h += '</div>';

  // Actions summary
  if ((d.rebalancing_actions||[]).length) {
    h += `<div class="rb-section"><div class="rb-section-title">Recommended Actions</div><ol>`;
    d.rebalancing_actions.forEach(a => {
      h += `<li><strong>${e(a.action_type.replace(/_/g,' ').toUpperCase())}</strong>${a.from_fund?' — '+e(a.from_fund)+(a.to_fund?' → '+e(a.to_fund):''): ''}<br><span style="color:var(--text-muted)">${e(a.reason||'')}</span></li>`;
    });
    h += '</ol></div>';
  }

  h += `<p class="rb-disclaimer">${e(d.disclaimer||'')}</p>`;
  h += `<a href="https://wa.me/${WA_NUM}?text=${encodeURIComponent('Hi, I ran the Prime Financials MF Rebalancer and want to discuss the recommendations.')}" class="rb-wa-btn" target="_blank" rel="noopener">💬 Discuss with Advisor</a>`;
  h += `<div class="rb-report-actions">
    <button class="rb-action-btn rb-action-btn--pdf" onclick="downloadPDF('mutual_fund')"><i class="bi bi-file-earmark-pdf"></i> Download PDF</button>
    <button class="rb-action-btn rb-action-btn--email" id="emailMF" onclick="emailReport('mutual_fund', this)"><i class="bi bi-envelope"></i> Email Report</button>
  </div>`;
  el.innerHTML = h;
}

// ── Equity result renderer ──────────────────────────────────
function renderEQ(d, el) {
  let h = `<div class="rb-score-row">
    <span class="rb-score">${d.overall_score||0}/100</span>
    <span class="rb-health">${e((d.overall_assessment||'').replace(/_/g,' ').toUpperCase())}</span>
  </div>
  <p class="rb-summary">${e(d.summary||'')}</p>`;

  // Sector breakdown
  if ((d.sector_breakdown||[]).length) {
    h += `<div class="rb-section"><div class="rb-section-title">Sector Breakdown</div>`;
    d.sector_breakdown.forEach(s => {
      h += `<div class="rb-sector-row ${s.flag?'flagged':''}">
        <span class="rb-sector-name">${e(s.sector)}</span>
        <div class="rb-sector-track"><div class="rb-sector-fill" style="width:${Math.min(s.allocation_pct||0,100)}%"></div></div>
        <span class="rb-sector-pct">${s.allocation_pct||0}%</span>
        ${s.flag?`<span class="rb-flag">⚠</span>`:'<span class="rb-ok">✓</span>'}
      </div>`;
    });
    h += '</div>';
  }

  // Holdings
  h += '<div class="rb-holdings">';
  (d.holdings||[]).forEach(f => {
    const v=EQ_VERDICTS[f.verdict]||EQ_VERDICTS.hold;
    const gp=f.unrealised_gain_pct||0;
    h += `<div class="rb-holding">
      <div class="rb-holding-top">
        <span class="rb-fund-name">${e(f.stock_name||'')}${f.ticker?` <small style="color:var(--text-muted);font-family:'IBM Plex Mono',monospace;font-size:0.7em">${e(f.ticker)}</small>`:''}
        </span>
        <span class="rb-verdict" style="background:${v.bg};color:${v.c};border:1px solid ${v.c}">${v.l}</span>
      </div>
      <div class="rb-meta-row">
        <span>Weight: ${f.weight_in_equity_pct||0}%</span>
        <span class="${gp>=0?'gain-pos':'gain-neg'}">${gp>=0?'+':''}${gp}% unrealised</span>
        <span>${f.holding_period_days||0}d held</span>
        ${f.has_research_note?'<span class="research-badge">📄 Research note</span>':''}
      </div>
      ${f.tax_note?`<p class="rb-tax-note">💰 ${e(f.tax_note)}</p>`:''}
      <p class="rb-reason">${e(f.reason||'')}</p>
      ${f.action_detail?`<p class="rb-action">→ ${e(f.action_detail)}</p>`:''}
    </div>`;
  });
  h += '</div>';

  if ((d.tax_opportunities||[]).length) {
    h += `<div class="rb-section"><div class="rb-section-title">💡 Tax Opportunities</div><ul>`;
    d.tax_opportunities.forEach(t => { h += `<li>${e(t.description||'')}</li>`; });
    h += '</ul></div>';
  }
  if ((d.concentration_alerts||[]).length) {
    h += `<div class="rb-section"><div class="rb-section-title">⚠ Concentration Alerts</div><ul>`;
    d.concentration_alerts.forEach(a => { h += `<li>${e(a.description||'')}<br><small style="color:var(--text-muted)">${e(a.suggestion||'')}</small></li>`; });
    h += '</ul></div>';
  }

  h += `<div class="disclaimer disclaimer--stock" style="font-size:0.75rem">${e(d.disclaimer||'')}</div>`;
  h += `<a href="https://wa.me/${WA_NUM}?text=${encodeURIComponent('Hi, I reviewed my equity portfolio on Prime Financials and want to discuss the analysis.')}" class="rb-wa-btn" target="_blank" rel="noopener">💬 Discuss with Advisor</a>`;
  h += `<div class="rb-report-actions">
    <button class="rb-action-btn rb-action-btn--pdf" onclick="downloadPDF('equity')"><i class="bi bi-file-earmark-pdf"></i> Download PDF</button>
    <button class="rb-action-btn rb-action-btn--email" id="emailEQ" onclick="emailReport('equity', this)"><i class="bi bi-envelope"></i> Email Report</button>
  </div>`;
  el.innerHTML = h;
}
</script>

<?php require_once '../includes/portal-footer.php'; ?>
