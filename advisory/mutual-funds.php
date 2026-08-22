<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mf-api.php';
require_once '../includes/recommendation-engine.php';
require_login();

$db  = get_db();
$uid = get_user_id();

// Handle watchlist add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_watchlist') {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        try {
            $db->prepare("INSERT INTO fund_watchlist (user_id, fund_name, fund_house) VALUES (:uid,:fn,:fh)")
               ->execute([':uid'=>$uid, ':fn'=>trim($_POST['fund_name']??''), ':fh'=>trim($_POST['fund_house']??'')]);
            $_SESSION['flash'] = ['type'=>'success','message'=>'Added to watchlist.'];
        } catch (PDOException $e) { error_log($e->getMessage()); }
        header('Location: ' . SITE_URL . '/advisory/mutual-funds.php'); exit;
    }
}

// Auto-refresh stale NAVs (up to 5 funds, once per 24h)
mf_maybe_refresh($db);

// Load client risk profile
$prof_stmt = $db->prepare("SELECT risk_profile, risk_score, risk_assessed_at FROM user_profiles WHERE user_id=:uid ORDER BY id DESC LIMIT 1");
$prof_stmt->execute([':uid' => $uid]);
$client_profile = $prof_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$risk_profile    = $client_profile['risk_profile'] ?? null;
$risk_score      = $client_profile['risk_score']   ?? null;
$assessed_at     = $client_profile['risk_assessed_at'] ?? null;
$needs_retake    = $assessed_at && strtotime($assessed_at) < strtotime('-1 year');
$assessment_url  = SITE_URL . '/portal/risk-assessment.php?redirect=mutual-funds';

$page_title = 'Mutual Funds — Prime Financials';
require_once '../includes/portal-header.php';

$risk_badge_cfg = [
    'conservative' => ['label' => 'Conservative', 'color' => 'var(--bright)',  'bg' => 'rgba(76,175,80,0.12)'],
    'moderate'     => ['label' => 'Moderate',      'color' => 'var(--gold)',    'bg' => 'rgba(201,168,76,0.12)'],
    'aggressive'   => ['label' => 'Aggressive',    'color' => '#ff6b35',        'bg' => 'rgba(255,107,53,0.12)'],
];
$rbc = $risk_badge_cfg[$risk_profile] ?? null;
?>

<p class="page-eyebrow">Advisory</p>
<h1 class="page-title">Mutual Fund Recommendations</h1>

<div class="disclaimer disclaimer--mf" style="margin-bottom:1.25rem">
  Mutual Fund investments are subject to market risks. Please read all scheme-related documents carefully before investing. Past performance is not indicative of future results. Prime Financials — AMFI Registered MF Distributor (<?= AMFI_ARN ?>).
</div>

<?php if (!$risk_profile): ?>
<!-- ── No profile gate ────────────────────────────────────────────────────── -->
<div class="portal-card" style="text-align:center;padding:3rem 2rem;max-width:560px;margin:2rem auto">
  <div style="font-size:2.5rem;margin-bottom:1rem">📊</div>
  <h2 style="font-family:'Cormorant Garamond',serif;font-size:1.6rem;color:var(--cream);margin-bottom:0.75rem">
    Let's find funds that suit <em>you</em>
  </h2>
  <p style="color:var(--text-secondary);font-size:0.9rem;line-height:1.75;margin-bottom:1.75rem">
    Our recommendation engine matches funds to your risk tolerance, goals, and investment horizon.
    It takes <strong style="color:var(--cream)">2 minutes</strong> to complete.
  </p>
  <a href="<?= $assessment_url ?>" class="btn-primary" style="display:inline-block;padding:0.8rem 2rem;font-size:1rem">
    Start Risk Assessment →
  </a>
  <div style="margin-top:1.25rem;font-size:0.78rem;color:var(--text-muted)">
    5 questions · No personal data shared · You can retake anytime
  </div>
</div>

<?php else: ?>
<!-- ── Profile chip ───────────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem">
  <div style="display:flex;align-items:center;gap:0.6rem;background:<?= $rbc['bg'] ?>;border:1px solid <?= $rbc['color'] ?>;border-radius:24px;padding:0.4rem 1rem">
    <span style="font-size:0.65rem;font-family:'DM Mono',monospace;color:var(--text-secondary);letter-spacing:0.1em">RISK PROFILE</span>
    <span style="font-weight:600;color:<?= $rbc['color'] ?>;font-size:0.9rem"><?= $rbc['label'] ?></span>
    <?php if ($risk_score !== null): ?>
    <span style="font-family:'DM Mono',monospace;font-size:0.7rem;color:var(--text-muted)"><?= $risk_score ?>/20</span>
    <?php endif; ?>
  </div>
  <?php if ($assessed_at): ?>
  <span style="font-size:0.78rem;color:var(--text-secondary)">
    Assessed <?= date('d M Y', strtotime($assessed_at)) ?>
  </span>
  <?php endif; ?>
  <a href="<?= $assessment_url ?>" style="font-size:0.78rem;color:var(--lime);text-decoration:none">
    <?= $needs_retake ? '⚠ Retake recommended (over 1 year old)' : 'Retake assessment →' ?>
  </a>
</div>

<?php
// ── Scored fund list ──────────────────────────────────────────────────────────
$all_scored   = get_personalized_funds($db, $uid);
$strong       = array_filter($all_scored, fn($f) => $f['match_score'] >= 75);
$good         = array_filter($all_scored, fn($f) => $f['match_score'] >= 50 && $f['match_score'] < 75);
$consider     = array_filter($all_scored, fn($f) => $f['match_score'] >= 25 && $f['match_score'] < 50);
$other        = array_filter($all_scored, fn($f) => $f['match_score'] < 25);
$recommended  = array_merge(array_values($strong), array_values($good));
$risk_badge   = ['low'=>'badge-green','moderate'=>'badge-gold','high'=>'badge-gold','very_high'=>'badge-muted'];
?>

<?php if (!empty($recommended)): ?>
<!-- ── Recommended section ────────────────────────────────────────────────── -->
<div style="font-family:'DM Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.2em;margin-bottom:0.75rem">
  ★ RECOMMENDED FOR YOU (<?= count($recommended) ?> fund<?= count($recommended)!==1?'s':'' ?>)
</div>
<div class="grid-2" style="margin-bottom:2rem">
  <?php foreach ($recommended as $f): ?>
  <?php render_fund_card($f, $risk_badge, $uid, $db); ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($consider) || !empty($other)): ?>
<!-- ── Other funds toggle ────────────────────────────────────────────────── -->
<button id="toggleOther" onclick="toggleOtherFunds()" style="background:none;border:none;color:var(--lime);font-size:0.82rem;cursor:pointer;padding:0;margin-bottom:1rem;font-family:'DM Mono',monospace;letter-spacing:0.1em">
  ▶ SHOW OTHER FUNDS (<?= count($consider) + count($other) ?>)
</button>
<div id="otherFunds" style="display:none">
  <?php if (!empty($consider)): ?>
  <div style="font-family:'DM Mono',monospace;font-size:0.58rem;color:var(--text-muted);letter-spacing:0.15em;margin-bottom:0.75rem">CONSIDER (lower fit for your profile)</div>
  <div class="grid-2" style="margin-bottom:1.5rem">
    <?php foreach ($consider as $f): ?>
    <?php render_fund_card($f, $risk_badge, $uid, $db, true); ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if (!empty($other)): ?>
  <div style="font-family:'DM Mono',monospace;font-size:0.58rem;color:var(--text-muted);letter-spacing:0.15em;margin-bottom:0.75rem">OTHER FUNDS (different risk profile)</div>
  <div class="grid-2">
    <?php foreach ($other as $f): ?>
    <?php render_fund_card($f, $risk_badge, $uid, $db, true); ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($all_scored)): ?>
<div class="portal-card" style="text-align:center;padding:3rem;color:var(--text-secondary)">
  No active funds available. Check back soon.
</div>
<?php endif; ?>

<?php endif; ?>

<!-- ELSS Planner -->
<div class="portal-card" style="margin-top:2rem">
  <div class="card-title">ELSS Tax Saver Planner</div>
  <div class="grid-2" style="align-items:start">
    <div>
      <p style="color:var(--text-secondary);font-size:0.9rem;line-height:1.7;margin-bottom:0.75rem">ELSS (Equity Linked Savings Scheme) funds offer tax savings under <strong style="color:var(--cream)">Section 80C</strong> up to ₹1.5 Lakhs — with the shortest lock-in of just <strong style="color:var(--lime)">3 years</strong>.</p>
      <div style="display:flex;flex-direction:column;gap:0.5rem">
        <?php foreach ([['📅','Shortest lock-in: 3 years (vs 5yr for PPF/NSC)'],['💰','Tax saving: up to ₹46,800 at 30% bracket'],['📈','Historically 12–15% XIRR over 5+ years'],['🔄','SIP allowed — invest from ₹500/month']] as [$icon,$text]): ?>
        <div style="display:flex;gap:0.75rem;align-items:flex-start;font-size:0.875rem;color:var(--text-secondary)"><span><?= $icon ?></span><span><?= $text ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div style="background:var(--surface-2);border-radius:10px;padding:1.25rem">
      <div style="font-family:'DM Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.15em;margin-bottom:0.75rem">QUICK ELSS CALCULATOR</div>
      <div class="form-group"><label class="form-label">Monthly ELSS SIP (₹)</label><input class="form-input" type="number" id="elss_sip" value="12500" oninput="calcElss()"></div>
      <div style="margin-top:0.75rem;font-size:0.85rem;color:var(--text-secondary)">Annual investment: <span style="color:var(--cream)" id="elss_annual">₹1,50,000</span></div>
      <div style="font-size:0.85rem;color:var(--text-secondary)">Tax saved (30% bracket): <span style="color:var(--lime)" id="elss_saved">₹46,800</span></div>
      <div style="font-size:0.85rem;color:var(--text-secondary)">3-year corpus @13%: <span style="color:var(--bright)" id="elss_corpus">₹5,22,000</span></div>
    </div>
  </div>
</div>

<script>
function calcElss(){
  var sip=parseFloat(document.getElementById('elss_sip').value)||0;
  var annual=sip*12; var saved=Math.min(150000,annual)*0.30*1.04;
  var r=(13/100)/12,n=36,corpus=r>0?sip*((Math.pow(1+r,n)-1)/r)*(1+r):sip*n;
  document.getElementById('elss_annual').textContent='₹'+Math.round(annual).toLocaleString('en-IN');
  document.getElementById('elss_saved').textContent='₹'+Math.round(saved).toLocaleString('en-IN');
  document.getElementById('elss_corpus').textContent='₹'+Math.round(corpus).toLocaleString('en-IN');
}
function toggleOtherFunds(){
  var el=document.getElementById('otherFunds');
  var btn=document.getElementById('toggleOther');
  var shown=el.style.display!=='none';
  el.style.display=shown?'none':'block';
  btn.textContent=(shown?'▶ SHOW':'▼ HIDE')+btn.textContent.slice(btn.textContent.indexOf(' OTHER'));
}
document.addEventListener('DOMContentLoaded',calcElss);
</script>

<?php require_once '../includes/portal-footer.php'; ?>

<?php
function render_fund_card(array $f, array $risk_badge, int $uid, PDO $db, bool $muted = false): void
{
    $opacity = $muted ? 'opacity:0.75' : '';
    $label   = $f['match_label']   ?? '';
    $reasons = $f['match_reasons'] ?? [];
    $score   = $f['match_score']   ?? 0;
    $score_color = match(true) {
        $score >= 75 => 'var(--bright)',
        $score >= 50 => 'var(--gold)',
        default      => 'var(--text-muted)',
    };
    ?>
    <div class="portal-card" style="<?= $opacity ?>">
      <!-- Match badge -->
      <?php if ($label): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.6rem">
        <span style="font-family:'DM Mono',monospace;font-size:0.6rem;color:<?= $score_color ?>;letter-spacing:0.1em">
          <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
        </span>
        <span style="font-family:'DM Mono',monospace;font-size:0.72rem;color:<?= $score_color ?>;font-weight:600">
          <?= $score ?>% match
        </span>
      </div>
      <?php endif; ?>

      <!-- Fund name -->
      <div style="margin-bottom:0.5rem">
        <div style="font-weight:600;color:var(--cream);font-size:1rem">
          <?= htmlspecialchars($f['fund_name'], ENT_QUOTES, 'UTF-8') ?>
          <?php if ($f['is_featured']): ?><span class="badge badge-gold" style="margin-left:4px">★</span><?php endif; ?>
        </div>
        <div style="font-size:0.8rem;color:var(--text-secondary)"><?= htmlspecialchars($f['fund_house']??'', ENT_QUOTES, 'UTF-8') ?></div>
      </div>

      <!-- Category & risk badges -->
      <div style="display:flex;gap:0.4rem;flex-wrap:wrap;margin-bottom:0.6rem">
        <span class="badge badge-muted"><?= htmlspecialchars($f['category']??'', ENT_QUOTES, 'UTF-8') ?></span>
        <span class="badge <?= $risk_badge[$f['risk_level']] ?? 'badge-muted' ?>"><?= ucfirst(str_replace('_',' ',$f['risk_level']??'')) ?></span>
        <?php if ($f['min_horizon_yrs']): ?><span class="badge badge-muted"><?= $f['min_horizon_yrs'] ?>yr+</span><?php endif; ?>
      </div>

      <!-- Match reason tags -->
      <?php if (!empty($reasons)): ?>
      <div style="display:flex;flex-wrap:wrap;gap:0.35rem;margin-bottom:0.75rem">
        <?php foreach ($reasons as $r): ?>
        <span style="font-size:0.68rem;font-family:'DM Mono',monospace;color:var(--lime);background:rgba(76,175,80,0.08);border:1px solid rgba(76,175,80,0.2);padding:0.15rem 0.5rem;border-radius:10px">
          <?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>
        </span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Live NAV -->
      <?php if ($f['current_nav']): ?>
      <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:0.6rem">
        <div>
          <div style="font-family:'DM Mono',monospace;font-size:0.58rem;color:var(--lime);letter-spacing:0.1em;margin-bottom:2px">CURRENT NAV</div>
          <div style="font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:600;color:var(--cream)">&#8377;<?= number_format((float)$f['current_nav'],4) ?></div>
        </div>
        <?php if ($f['last_data_refresh']): ?>
        <span style="font-family:'DM Mono',monospace;font-size:0.58rem;color:var(--bright);background:rgba(76,175,80,0.1);border:1px solid rgba(76,175,80,0.2);padding:0.2rem 0.5rem;border-radius:10px">&#9679; Live</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Returns -->
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.4rem;background:var(--surface-2);border-radius:8px;padding:0.65rem;margin-bottom:0.65rem">
        <?php foreach (['return_1yr'=>'1yr','return_3yr'=>'3yr','return_5yr'=>'5yr'] as $col=>$lbl): ?>
        <div style="text-align:center">
          <div style="font-size:0.58rem;color:var(--text-muted);font-family:'DM Mono',monospace"><?= $lbl ?> CAGR</div>
          <div style="font-family:'DM Mono',monospace;font-size:0.88rem;color:<?= $f[$col]!==null?(((float)$f[$col])>=0?'var(--bright)':'var(--danger)'):'var(--text-muted)' ?>">
            <?= $f[$col] !== null ? round((float)$f[$col],1).'%' : '—' ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Tech metrics row (if scored) -->
      <?php if (!empty($f['sharpe_ratio']) || !empty($f['max_drawdown'])): ?>
      <div style="display:flex;gap:1rem;font-size:0.72rem;color:var(--text-secondary);margin-bottom:0.65rem;font-family:'DM Mono',monospace">
        <?php if (!empty($f['sharpe_ratio'])): ?>
        <span>Sharpe <strong style="color:var(--cream)"><?= round((float)$f['sharpe_ratio'],2) ?></strong></span>
        <?php endif; ?>
        <?php if (!empty($f['max_drawdown'])): ?>
        <span>Max DD <strong style="color:<?= (float)$f['max_drawdown'] > -20 ? 'var(--bright)' : 'var(--danger)' ?>"><?= round((float)$f['max_drawdown'],1) ?>%</strong></span>
        <?php endif; ?>
        <?php if (!empty($f['alpha'])): ?>
        <span>Alpha <strong style="color:<?= (float)$f['alpha'] >= 0 ? 'var(--bright)' : 'var(--danger)' ?>"><?= round((float)$f['alpha'],1) ?></strong></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($f['expense_ratio']): ?>
      <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:0.4rem">Expense: <?= $f['expense_ratio'] ?>%<?= $f['aum_cr'] ? ' · AUM: ₹'.number_format((float)$f['aum_cr'],0).'Cr' : '' ?></div>
      <?php endif; ?>
      <?php if ($f['why_recommended']): ?>
      <div style="font-size:0.82rem;color:var(--text-secondary);margin-bottom:0.75rem;line-height:1.5">
        <?= htmlspecialchars(mb_substr($f['why_recommended'],0,120), ENT_QUOTES,'UTF-8') ?><?= mb_strlen($f['why_recommended'])>120?'…':'' ?>
      </div>
      <?php endif; ?>

      <!-- Actions -->
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES,'UTF-8') ?>">
          <input type="hidden" name="action" value="add_watchlist">
          <input type="hidden" name="fund_name"  value="<?= htmlspecialchars($f['fund_name'], ENT_QUOTES,'UTF-8') ?>">
          <input type="hidden" name="fund_house" value="<?= htmlspecialchars($f['fund_house']??'', ENT_QUOTES,'UTF-8') ?>">
          <button type="submit" class="btn-outline btn-sm">★ Watchlist</button>
        </form>
        <a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=I+want+to+invest+in+<?= urlencode($f['fund_name']) ?>+recommended+on+primefin.in" class="btn-ghost btn-sm" target="_blank" rel="noopener">💬 Advisor</a>
      </div>
    </div>
    <?php
}
