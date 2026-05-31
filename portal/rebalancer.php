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
.rb-card-sub { font-family:'DM Mono',monospace; font-size:0.58rem; color:var(--lime); text-transform:uppercase; letter-spacing:0.15em; }
.rb-card-meta { display:flex; flex-direction:column; align-items:flex-end; gap:0.35rem; flex-shrink:0; }
.rb-last-run { font-family:'DM Mono',monospace; font-size:0.65rem; color:var(--text-muted); }

.rb-run-btn { background:var(--mid); color:#fff; border:none; border-radius:7px; padding:0.5rem 1.1rem; font-size:0.82rem; font-family:'DM Sans',sans-serif; font-weight:500; cursor:pointer; transition:background 0.15s; white-space:nowrap; }
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
.rb-health { font-family:'DM Mono',monospace; font-size:0.62rem; letter-spacing:0.1em; color:var(--text-muted); text-transform:uppercase; }
.rb-summary { font-size:0.875rem; color:var(--text-secondary); line-height:1.65; margin-bottom:1.25rem; }

.rb-alloc { display:flex; flex-direction:column; gap:0.5rem; margin-bottom:1.25rem; padding:1rem; background:var(--surface-2); border-radius:8px; border:1px solid var(--border-light); }
.rb-alloc-title { font-family:'DM Mono',monospace; font-size:0.6rem; color:var(--lime); letter-spacing:0.15em; text-transform:uppercase; margin-bottom:0.25rem; }
.rb-alloc-row { display:flex; align-items:center; gap:0.6rem; font-size:0.78rem; }
.rb-alloc-name { width:50px; color:var(--text-secondary); flex-shrink:0; }
.rb-alloc-track { flex:1; height:6px; background:var(--surface-1); border-radius:3px; overflow:hidden; }
.rb-alloc-fill { height:100%; background:var(--mid); border-radius:3px; transition:width 0.6s; }
.rb-alloc-pct { width:36px; text-align:right; font-family:'DM Mono',monospace; color:var(--cream); }
.rb-alloc-flag { font-size:0.68rem; white-space:nowrap; }

.rb-holdings { display:flex; flex-direction:column; gap:0.6rem; margin-bottom:1rem; }
.rb-holding { background:var(--surface-2); border:1px solid var(--border-light); border-radius:8px; padding:0.875rem; }
.rb-holding.pri-urgent   { border-left:3px solid #ef5350; }
.rb-holding.pri-moderate { border-left:3px solid var(--gold); }
.rb-holding-top { display:flex; justify-content:space-between; align-items:flex-start; gap:0.5rem; margin-bottom:0.4rem; }
.rb-fund-name { font-size:0.875rem; font-weight:500; color:var(--cream); line-height:1.3; }
.rb-verdict { font-family:'DM Mono',monospace; font-size:0.6rem; letter-spacing:0.06em; padding:0.22rem 0.55rem; border-radius:4px; font-weight:600; flex-shrink:0; white-space:nowrap; }
.rb-meta-row { display:flex; flex-wrap:wrap; gap:0.5rem; font-family:'DM Mono',monospace; font-size:0.65rem; color:var(--text-muted); margin-bottom:0.4rem; }
.rb-sip-pill { border:1px solid currentColor; border-radius:10px; padding:0.08rem 0.45rem; }
.rb-reason { font-size:0.8rem; color:var(--text-secondary); line-height:1.55; }
.rb-action { font-size:0.78rem; color:var(--lime); margin-top:0.3rem; }
.rb-tax-note { font-size:0.75rem; color:var(--gold); margin-bottom:0.3rem; }

.rb-section { background:var(--surface-2); border:1px solid var(--border-light); border-radius:8px; padding:0.875rem; margin-bottom:0.75rem; }
.rb-section-title { font-family:'DM Mono',monospace; font-size:0.6rem; letter-spacing:0.15em; color:var(--lime); text-transform:uppercase; margin-bottom:0.6rem; }
.rb-section ul, .rb-section ol { font-size:0.8rem; color:var(--text-secondary); padding-left:1.1rem; display:flex; flex-direction:column; gap:0.4rem; line-height:1.55; }

.rb-sector-row { display:flex; align-items:center; gap:0.5rem; font-size:0.78rem; padding:0.25rem 0; flex-wrap:wrap; }
.rb-sector-name { width:80px; flex-shrink:0; }
.rb-sector-track { flex:1; height:7px; background:var(--surface-1); border-radius:3px; overflow:hidden; min-width:50px; }
.rb-sector-fill { height:100%; background:var(--mid); border-radius:3px; }
.rb-sector-row.flagged .rb-sector-fill { background:var(--gold); }
.rb-sector-pct { width:32px; text-align:right; font-family:'DM Mono',monospace; flex-shrink:0; }
.rb-flag { font-size:0.68rem; color:var(--gold); }
.rb-ok { color:var(--bright); }

.rb-disclaimer { font-size:0.72rem; color:var(--text-muted); line-height:1.6; margin:0.75rem 0; }
.rb-wa-btn { display:block; text-align:center; background:#25d366; color:#fff; padding:0.7rem; border-radius:8px; font-size:0.875rem; font-weight:500; margin-top:0.75rem; text-decoration:none; transition:opacity 0.2s; }
.rb-wa-btn:hover { opacity:0.9; color:#fff; }
.rb-error { color:#ef5350; font-size:0.875rem; padding:1.5rem; text-align:center; background:rgba(239,83,80,0.07); border-radius:8px; }
.gain-pos { color:var(--bright); }
.gain-neg { color:#ef5350; }
.research-badge { color:var(--lime); }
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
          <div class="rb-card-icon">🔄</div>
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
          <div style="font-size:2rem;margin-bottom:0.5rem">📭</div>
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
          <div class="rb-card-icon">📊</div>
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
          <div style="font-size:2rem;margin-bottom:0.5rem">📭</div>
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
const MF_URL = '<?= SITE_URL ?>/ai/rebalance-mf.php';
const ONBOARDING_URL = '<?= ONBOARDING_URL ?>';
const CALENDLY_URL   = '<?= CALENDLY_URL ?>';
const EQ_URL = '<?= SITE_URL ?>/ai/rebalance-equity.php';
const WA_NUM = '<?= WHATSAPP_NUM ?>';
const CSRF   = document.querySelector('meta[name="csrf-token"]').content;

function e(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

async function runRebalancer(type) {
  const isMF = type === 'mutual_fund';
  const btn  = document.getElementById(isMF ? 'runMF' : 'runEQ');
  const body = document.getElementById(isMF ? 'mfBody' : 'eqBody');
  const lbl  = isMF ? 'mutual fund' : 'equity';

  btn.disabled = true;
  btn.textContent = 'Analysing…';
  body.innerHTML = `<div class="rb-loading"><div class="rb-spinner"></div>
    <p style="color:var(--text-secondary);font-size:0.875rem">PrimoAI is analysing your ${lbl} portfolio…</p>
    <p class="rb-note">This takes 15–30 seconds</p></div>`;

  try {
    const resp = await fetch(isMF ? MF_URL : EQ_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ type })
    });
    const r = await resp.json();
    if (!r.success) throw new Error(r.error || 'Analysis failed');
    isMF ? renderMF(r.data, body) : renderEQ(r.data, body);
  } catch(err) {
    body.innerHTML = `<div class="rb-error">⚠ ${e(err.message)}<br><small style="color:var(--text-muted)">Please try again in a moment.</small></div>`;
  } finally {
    btn.disabled = false;
    btn.textContent = 'Re-run →';
  }
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
    h += `<div style="font-size:0.68rem;color:var(--text-muted);margin-top:0.25rem;font-family:'DM Mono',monospace">Target based on your risk profile</div></div>`;
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
        <span class="rb-fund-name">${e(f.stock_name||'')}${f.ticker?` <small style="color:var(--text-muted);font-family:'DM Mono',monospace;font-size:0.7em">${e(f.ticker)}</small>`:''}
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
  el.innerHTML = h;
}
</script>

<?php require_once '../includes/portal-footer.php'; ?>
