<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$db      = get_db();
$uid     = get_user_id();

// ── User + profile ────────────────────────────────────────
$stmt = $db->prepare("SELECT full_name, last_login FROM users WHERE id = :uid");
$stmt->execute([':uid' => $uid]);
$user_data = $stmt->fetch() ?: [];

$stmt = $db->prepare("SELECT risk_profile FROM user_profiles WHERE user_id = :uid ORDER BY id DESC LIMIT 1");
$stmt->execute([':uid' => $uid]);
$user_data['risk_profile'] = $stmt->fetchColumn() ?: null;

// ── Portfolio totals ──────────────────────────────────────
$stmt = $db->prepare("SELECT fund_type, SUM(invested_amount) as invested, SUM(current_value) as current FROM portfolio_entries WHERE user_id = :uid GROUP BY fund_type");
$stmt->execute([':uid' => $uid]);
$allocation_rows = $stmt->fetchAll();

$total_invested = 0; $total_current = 0;
$allocation = [];
foreach ($allocation_rows as $row) {
    $total_invested += (float)$row['invested'];
    $total_current  += (float)$row['current'];
    $allocation[ucfirst($row['fund_type'])] = (float)$row['current'];
}
$gain       = $total_current - $total_invested;
$gain_pct   = $total_invested > 0 ? ($gain / $total_invested) * 100 : 0;

// Simple CAGR approximation for XIRR (avg purchase date → today)
$stmt = $db->prepare("SELECT AVG(DATEDIFF(NOW(), purchase_date)) as avg_days FROM portfolio_entries WHERE user_id = :uid AND purchase_date IS NOT NULL AND invested_amount > 0");
$stmt->execute([':uid' => $uid]);
$avg_days = (float)($stmt->fetchColumn() ?: 0);
$xirr = 0;
if ($avg_days > 0 && $total_invested > 0 && $total_current > 0) {
    $years = $avg_days / 365;
    $xirr  = (pow($total_current / $total_invested, 1 / $years) - 1) * 100;
}

// ── Goals ─────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM goals WHERE user_id = :uid AND status = 'active' ORDER BY target_year ASC LIMIT 3");
$stmt->execute([':uid' => $uid]);
$goals = $stmt->fetchAll();

// ── Market insights ───────────────────────────────────────
$stmt = $db->prepare("SELECT title, slug, excerpt, category, published_at FROM market_insights WHERE is_published = 1 ORDER BY published_at DESC LIMIT 3");
$stmt->execute();
$insights = $stmt->fetchAll();

// ── Greeting ──────────────────────────────────────────────
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$goal_icons = ['retirement'=>'🏦','education'=>'🎓','home'=>'🏠','marriage'=>'💍','vehicle'=>'🚗','emergency'=>'🆘','custom'=>'🎯'];
$category_labels = ['market_update'=>'Market Update','tax_tips'=>'Tax Tips','fund_analysis'=>'Fund Analysis','nps'=>'NPS','insurance'=>'Insurance','stocks'=>'Stocks','general'=>'General'];

$page_title = 'Dashboard — Prime Financials';
require_once '../includes/portal-header.php';
?>

<!-- Welcome bar -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:2rem;padding-bottom:1.25rem;border-bottom:1px solid var(--border)">
  <div>
    <p class="page-eyebrow">Overview</p>
    <h1 class="page-title" style="margin-bottom:0"><?= $greeting ?>, <?= htmlspecialchars($user_data['full_name'], ENT_QUOTES, 'UTF-8') ?>!</h1>
  </div>
  <div style="text-align:right;font-size:0.8rem;color:var(--text-secondary)">
    <?php if ($user_data['last_login']): ?>
      Last login: <?= date('d M Y, g:i a', strtotime($user_data['last_login'])) ?><br>
    <?php endif; ?>
    Risk Profile:
    <span class="badge <?= $user_data['risk_profile'] ? 'badge-green' : 'badge-muted' ?>">
      <?= $user_data['risk_profile'] ? ucfirst($user_data['risk_profile']) : 'Not set' ?>
    </span>
  </div>
</div>

<!-- Stat boxes -->
<div class="stats-grid">
  <div class="stat-box">
    <div class="stat-label">Total Invested</div>
    <div class="stat-value neutral">₹<?= number_format($total_invested, 0) ?></div>
    <div class="stat-sub"><?= count($allocation_rows) ?> fund type<?= count($allocation_rows) !== 1 ? 's' : '' ?></div>
  </div>
  <div class="stat-box">
    <div class="stat-label">Current Value</div>
    <div class="stat-value neutral">₹<?= number_format($total_current, 0) ?></div>
    <div class="stat-sub">As of today</div>
  </div>
  <div class="stat-box">
    <div class="stat-label">Total Gain / Loss</div>
    <div class="stat-value <?= $gain >= 0 ? 'positive' : 'negative' ?>">
      <?= $gain >= 0 ? '+' : '' ?>₹<?= number_format(abs($gain), 0) ?>
    </div>
    <div class="stat-sub"><?= $gain >= 0 ? '+' : '' ?><?= number_format($gain_pct, 2) ?>%</div>
  </div>
  <div class="stat-box">
    <div class="stat-label">Est. XIRR</div>
    <div class="stat-value <?= $xirr >= 0 ? 'positive' : 'negative' ?>">
      <?= $total_invested > 0 ? number_format($xirr, 2) . '%' : '—' ?>
    </div>
    <div class="stat-sub">Annualised return</div>
  </div>
</div>

<!-- Chart + Goals row -->
<div class="grid-2" style="margin-top:1.5rem;align-items:start">

  <!-- Asset allocation chart -->
  <div class="portal-card">
    <div class="card-title">Asset Allocation</div>
    <?php if (!empty($allocation)): ?>
      <canvas id="allocationChart" height="220"></canvas>
    <?php else: ?>
      <div style="text-align:center;padding:2rem;color:var(--text-secondary)">
        <div style="font-size:2rem;margin-bottom:0.75rem">◈</div>
        No portfolio data yet.<br>
        <a href="<?= SITE_URL ?>/portal/portfolio.php" class="auth-link" style="font-size:0.875rem">Add your first holding →</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- Goals progress -->
  <div class="portal-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
      <div class="card-title" style="margin-bottom:0">Goals Progress</div>
      <a href="<?= SITE_URL ?>/portal/goals.php" class="auth-link" style="font-size:0.8rem">View all →</a>
    </div>
    <?php if (empty($goals)): ?>
      <div style="text-align:center;padding:1.5rem;color:var(--text-secondary)">
        <div style="font-size:2rem;margin-bottom:0.75rem">◉</div>
        No goals yet.<br>
        <a href="<?= SITE_URL ?>/portal/goals.php" class="auth-link" style="font-size:0.875rem">Set your first goal →</a>
      </div>
    <?php else: ?>
      <?php foreach ($goals as $g):
        $pct = $g['target_amount'] > 0 ? min(100, ($g['current_savings'] / $g['target_amount']) * 100) : 0;
        $icon = $goal_icons[$g['goal_type']] ?? '🎯';
      ?>
      <div style="margin-bottom:1.25rem">
        <div style="display:flex;justify-content:space-between;margin-bottom:0.3rem">
          <span style="font-weight:500;color:var(--cream)"><?= $icon ?> <?= htmlspecialchars($g['goal_name'], ENT_QUOTES, 'UTF-8') ?></span>
          <span style="font-size:0.8rem;color:var(--lime)"><?= number_format($pct, 0) ?>%</span>
        </div>
        <div style="background:var(--surface-2);border-radius:4px;height:6px;overflow:hidden">
          <div style="width:<?= $pct ?>%;height:100%;background:var(--mid);border-radius:4px;transition:width 0.5s"></div>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:0.25rem;font-size:0.75rem;color:var(--text-secondary)">
          <span>₹<?= number_format((float)$g['current_savings'], 0) ?> saved</span>
          <span>₹<?= number_format((float)$g['target_amount'], 0) ?> by <?= $g['target_year'] ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<!-- Primo CTA -->
<div class="portal-card" style="margin-top:1.5rem;background:linear-gradient(135deg,var(--surface-1),rgba(27,94,42,0.15));border-color:rgba(141,198,63,0.25)">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem">
    <div style="display:flex;align-items:center;gap:1rem">
      <div style="font-size:2rem;color:var(--lime)">✦</div>
      <div>
        <div style="font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:600;color:var(--cream);margin-bottom:0.2rem">Ask Primo</div>
        <div style="font-size:0.85rem;color:var(--text-secondary)">Your AI assistant knows your portfolio. Ask anything.</div>
      </div>
    </div>
    <div style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center">
      <button class="sugg-pill" onclick="location.href='<?= SITE_URL ?>/portal/primo.php?q=How+is+my+portfolio'">📊 Portfolio review</button>
      <button class="sugg-pill" onclick="location.href='<?= SITE_URL ?>/portal/primo.php?q=Am+I+on+track+for+goals'">🎯 Goal tracking</button>
      <a href="<?= SITE_URL ?>/portal/primo.php" class="btn-primary btn-sm">Chat with Primo →</a>
    </div>
  </div>
</div>
<style>.sugg-pill{font-size:0.78rem;padding:0.35rem 0.75rem;border:1px solid var(--border);border-radius:20px;background:var(--surface-2);color:var(--text-secondary);cursor:pointer;transition:all 0.15s;}.sugg-pill:hover{border-color:var(--mid);color:var(--cream);}</style>

<!-- Quick tools strip -->
<div class="portal-card" style="margin-top:1.5rem">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div class="card-title" style="margin-bottom:0">Quick Tools</div>
    <a href="<?= SITE_URL ?>/portal/calculators.php" class="auth-link" style="font-size:0.8rem">View all →</a>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:0.75rem">
    <a href="<?= SITE_URL ?>/portal/sip-calculator.php"   class="btn-outline btn-sm">⊕ SIP</a>
    <a href="<?= SITE_URL ?>/portal/tax-calculator.php"   class="btn-outline btn-sm">⊗ Tax</a>
    <a href="<?= SITE_URL ?>/portal/nps-projector.php"    class="btn-outline btn-sm">⊘ NPS</a>
    <a href="<?= SITE_URL ?>/portal/insurance-checker.php"class="btn-outline btn-sm">⊝ Insurance</a>
    <a href="<?= SITE_URL ?>/portal/cashflow-modeler.php" class="btn-outline btn-sm">⊛ Cashflow</a>
    <a href="<?= SITE_URL ?>/portal/overlap-analyzer.php" class="btn-outline btn-sm">⊜ Overlap</a>
  </div>
</div>

<!-- Market insights -->
<div style="margin-top:1.5rem">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <h2 class="section-header" style="margin-bottom:0;border:none;padding:0">Recent Insights</h2>
    <a href="<?= SITE_URL ?>/advisory/insights.php" class="auth-link" style="font-size:0.8rem">View all →</a>
  </div>
  <?php if (empty($insights)): ?>
    <div class="portal-card" style="text-align:center;color:var(--text-secondary);padding:2rem">
      No market insights published yet.
    </div>
  <?php else: ?>
    <div class="grid-3">
      <?php foreach ($insights as $ins): ?>
      <div class="portal-card">
        <div style="margin-bottom:0.6rem">
          <span class="badge badge-green"><?= htmlspecialchars($category_labels[$ins['category']] ?? $ins['category'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div style="font-weight:500;color:var(--cream);margin-bottom:0.4rem;line-height:1.4">
          <?= htmlspecialchars($ins['title'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div style="font-size:0.82rem;color:var(--text-secondary);line-height:1.6">
          <?= htmlspecialchars(mb_substr($ins['excerpt'] ?? '', 0, 100), ENT_QUOTES, 'UTF-8') ?><?= strlen($ins['excerpt'] ?? '') > 100 ? '…' : '' ?>
        </div>
        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.75rem;font-family:'DM Mono',monospace">
          <?= $ins['published_at'] ? date('d M Y', strtotime($ins['published_at'])) : '' ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if (!empty($allocation)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const isDark = !document.documentElement.hasAttribute('data-theme');
  new Chart(document.getElementById('allocationChart'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode(array_keys($allocation)) ?>,
      datasets: [{
        data: <?= json_encode(array_values($allocation)) ?>,
        backgroundColor: ['#1B5E2A','#2E8540','#4CAF50','#8DC63F','#C9A84C','#558b2f','#a5d6a7','#66BB6A'],
        borderColor: isDark ? '#0c140c' : '#fff',
        borderWidth: 3,
        hoverOffset: 6
      }]
    },
    options: {
      cutout: '68%',
      plugins: {
        legend: {
          position: 'bottom',
          labels: { color: isDark ? '#85a885' : '#2a5a2a', font: { family: "'DM Mono'" }, padding: 14, boxWidth: 12 }
        },
        tooltip: {
          callbacks: {
            label: function(ctx) {
              const val = ctx.raw;
              return ' ₹' + val.toLocaleString('en-IN', {maximumFractionDigits: 0});
            }
          }
        }
      }
    }
  });
});
</script>
<?php endif; ?>

<?php require_once '../includes/portal-footer.php'; ?>
