<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$db  = get_db();
$uid = get_user_id();
$error = '';

$goal_types = ['retirement','education','home','marriage','vehicle','emergency','custom'];
$goal_icons = ['retirement'=>'bi-bank','education'=>'bi-mortarboard','home'=>'bi-house','marriage'=>'bi-heart','vehicle'=>'bi-car-front','emergency'=>'bi-shield-exclamation','custom'=>'bi-star'];

// ── Add / Edit ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action']??'', ['add_goal','edit_goal'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $name     = trim($_POST['goal_name'] ?? '');
        $type     = in_array($_POST['goal_type']??'',$goal_types) ? $_POST['goal_type'] : 'custom';
        $target   = (float)($_POST['target_amount'] ?? 0);
        $year     = (int)($_POST['target_year'] ?? date('Y'));
        $savings  = (float)($_POST['current_savings'] ?? 0);
        $ret      = (float)($_POST['expected_return'] ?? 12);
        $sip      = (float)($_POST['monthly_sip'] ?? 0);
        $notes    = trim($_POST['notes'] ?? '') ?: null;

        if (!$name) { $error = 'Goal name is required.'; }
        elseif ($target <= 0) { $error = 'Target amount must be greater than zero.'; }
        elseif ($year < (int)date('Y')) { $error = 'Target year must be in the future.'; }
        else {
            try {
                $d = [':uid'=>$uid,':name'=>$name,':type'=>$type,':target'=>$target,':year'=>$year,':savings'=>$savings,':sip'=>$sip?:null,':ret'=>$ret?:null,':notes'=>$notes];
                if (($_POST['action']??'') === 'add_goal') {
                    $db->prepare("INSERT INTO goals (user_id,goal_name,goal_type,target_amount,target_year,current_savings,monthly_sip,expected_return,notes) VALUES (:uid,:name,:type,:target,:year,:savings,:sip,:ret,:notes)")->execute($d);
                } else {
                    $gid = (int)($_POST['goal_id'] ?? 0);
                    $d[':id'] = $gid;
                    $db->prepare("UPDATE goals SET goal_name=:name,goal_type=:type,target_amount=:target,target_year=:year,current_savings=:savings,monthly_sip=:sip,expected_return=:ret,notes=:notes WHERE id=:id AND user_id=:uid")->execute($d);
                }
                $_SESSION['flash'] = ['type'=>'success','message'=>'Goal saved.'];
                header('Location: ' . SITE_URL . '/portal/goals.php'); exit;
            } catch (PDOException $e) { error_log($e->getMessage()); $error = 'Could not save goal.'; }
        }
    }
}

// ── Mark achieved ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'achieve_goal') {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $gid = (int)($_POST['goal_id'] ?? 0);
        $db->prepare("UPDATE goals SET status='achieved' WHERE id=:id AND user_id=:uid")->execute([':id'=>$gid,':uid'=>$uid]);
        $_SESSION['flash'] = ['type'=>'success','message'=>'🎉 Congratulations! Goal marked as achieved.'];
        header('Location: ' . SITE_URL . '/portal/goals.php'); exit;
    }
}

// ── Delete ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete_goal') {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $gid = (int)($_POST['goal_id'] ?? 0);
        $db->prepare("DELETE FROM goals WHERE id=:id AND user_id=:uid")->execute([':id'=>$gid,':uid'=>$uid]);
        $_SESSION['flash'] = ['type'=>'success','message'=>'Goal deleted.'];
        header('Location: ' . SITE_URL . '/portal/goals.php'); exit;
    }
}

// ── Fetch goals ───────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM goals WHERE user_id = :uid ORDER BY FIELD(status,'active','paused','achieved'), target_year ASC");
$stmt->execute([':uid' => $uid]);
$goals = $stmt->fetchAll();
$active_goals   = array_filter($goals, fn($g) => $g['status'] === 'active');
$achieved_goals = array_filter($goals, fn($g) => $g['status'] === 'achieved');

// Edit prefill
$edit_goal = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM goals WHERE id = :id AND user_id = :uid");
    $stmt->execute([':id'=>(int)$_GET['edit'],':uid'=>$uid]);
    $edit_goal = $stmt->fetch();
}

// SIP calculator helper
function calc_sip(float $target, float $savings, int $target_year, float $annual_rate): float {
    $months = max(1, ($target_year - (int)date('Y')) * 12);
    $fv     = $target - $savings * pow(1 + $annual_rate / 100, ($target_year - (int)date('Y')));
    if ($fv <= 0) return 0;
    $r = ($annual_rate / 100) / 12;
    if ($r == 0) return $fv / $months;
    return $fv * $r / (pow(1 + $r, $months) - 1);
}

$page_title = 'My Goals — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">My Finances</p>
<h1 class="page-title">Financial Goals</h1>

<?php if ($error): ?>
  <div class="flash-error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div>
<?php endif; ?>

<!-- Active Goals -->
<?php if (empty($active_goals)): ?>
<div class="portal-card" style="text-align:center;padding:3rem;color:var(--text-secondary)">
  <i class="bi bi-bullseye" style="font-size:3rem;color:var(--lime);display:block;margin-bottom:1rem"></i>
  <p style="margin-bottom:1rem">No active goals yet. Set your first financial goal below.</p>
</div>
<?php else: ?>
<div class="grid-2" style="margin-bottom:1.5rem">
  <?php foreach ($active_goals as $g):
    $pct    = $g['target_amount'] > 0 ? min(100, ($g['current_savings'] / $g['target_amount']) * 100) : 0;
    $sip_needed = calc_sip((float)$g['target_amount'], (float)$g['current_savings'], (int)$g['target_year'], (float)($g['expected_return'] ?: 12));
    $icon   = $goal_icons[$g['goal_type']] ?? '🎯';
    $yrs    = max(0, (int)$g['target_year'] - (int)date('Y'));
  ?>
  <div class="portal-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem">
      <div>
        <i class="bi <?= htmlspecialchars($icon,ENT_QUOTES,'UTF-8') ?>" style="font-size:1.5rem;color:var(--lime);display:block;margin-bottom:0.3rem"></i>
        <div style="font-weight:600;color:var(--cream);font-family:'Cormorant Garamond',serif;font-size:1.15rem"><?= htmlspecialchars($g['goal_name'],ENT_QUOTES,'UTF-8') ?></div>
        <div style="font-size:0.75rem;color:var(--text-secondary)"><?= $yrs ?> year<?= $yrs!==1?'s':'' ?> to go · <?= $g['target_year'] ?></div>
      </div>
      <span class="badge badge-green"><?= number_format($pct,0) ?>%</span>
    </div>

    <!-- Progress bar -->
    <div style="margin-bottom:0.4rem">
      <div style="display:flex;justify-content:space-between;font-size:0.78rem;color:var(--text-secondary);margin-bottom:0.3rem">
        <span><?= format_inr((float)$g["current_savings"]) ?> saved</span>
        <span><?= format_inr((float)$g["target_amount"]) ?> target</span>
      </div>
      <div style="background:var(--surface-2);border-radius:6px;height:8px;overflow:hidden">
        <div style="width:<?= $pct ?>%;height:100%;background:linear-gradient(90deg,var(--forest),var(--bright));border-radius:6px;transition:width 0.6s"></div>
      </div>
    </div>

    <div style="font-size:0.82rem;color:var(--text-secondary);margin-top:0.75rem">
      Monthly SIP needed: <strong style="color:var(--lime)"><?= format_inr($sip_needed) ?></strong>
      <?php if ($g['expected_return']): ?> at <?= $g['expected_return'] ?>% p.a.<?php endif; ?>
    </div>

    <?php if ($g['notes']): ?>
    <div style="font-size:0.78rem;color:var(--text-muted);margin-top:0.5rem;font-style:italic"><?= htmlspecialchars($g['notes'],ENT_QUOTES,'UTF-8') ?></div>
    <?php endif; ?>

    <div style="display:flex;gap:0.5rem;margin-top:1rem;flex-wrap:wrap">
      <a href="?edit=<?= $g['id'] ?>" class="btn-ghost btn-sm">Edit</a>
      <form method="POST" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
        <input type="hidden" name="action" value="achieve_goal">
        <input type="hidden" name="goal_id" value="<?= $g['id'] ?>">
        <button type="submit" class="btn-outline btn-sm">✓ Achieved</button>
      </form>
      <form method="POST" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
        <input type="hidden" name="action" value="delete_goal">
        <input type="hidden" name="goal_id" value="<?= $g['id'] ?>">
        <button type="submit" class="btn-danger btn-sm" onclick="return confirm('Delete this goal?')">Delete</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Add / Edit form -->
<div class="portal-card">
  <div style="display:flex;justify-content:space-between;align-items:center;cursor:pointer" onclick="toggleGoalForm()">
    <div class="card-title" style="margin-bottom:0"><?= $edit_goal ? '✏ Edit Goal' : '+ Add New Goal' ?></div>
    <span id="gform-icon" style="color:var(--lime);font-size:1.25rem"><?= ($edit_goal||$error)?'−':'+' ?></span>
  </div>
  <div id="goal-form" style="display:<?= ($edit_goal||$error)?'block':'none' ?>;margin-top:1.25rem">
    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
      <input type="hidden" name="action" value="<?= $edit_goal ? 'edit_goal' : 'add_goal' ?>">
      <?php if ($edit_goal): ?><input type="hidden" name="goal_id" value="<?= $edit_goal['id'] ?>"><?php endif; ?>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Goal Name *</label>
          <input class="form-input" type="text" name="goal_name" value="<?= htmlspecialchars($edit_goal['goal_name']??'',ENT_QUOTES,'UTF-8') ?>" required placeholder="e.g. Buy a House">
        </div>
        <div class="form-group">
          <label class="form-label">Goal Type</label>
          <select class="form-select" name="goal_type">
            <?php foreach ($goal_types as $t): ?>
            <option value="<?= $t ?>" <?= ($edit_goal['goal_type']??'')===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Target Amount (₹) *</label>
          <input class="form-input" type="number" name="target_amount" id="g_target" value="<?= $edit_goal['target_amount']??'' ?>" required placeholder="5000000" oninput="recalcSip()">
        </div>
        <div class="form-group">
          <label class="form-label">Target Year *</label>
          <input class="form-input" type="number" name="target_year" id="g_year" value="<?= $edit_goal['target_year']??'' ?>" required placeholder="<?= date('Y')+5 ?>" min="<?= date('Y')+1 ?>" oninput="recalcSip()">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Current Savings (₹)</label>
          <input class="form-input" type="number" name="current_savings" id="g_savings" value="<?= $edit_goal['current_savings']??0 ?>" placeholder="0" oninput="recalcSip()">
        </div>
        <div class="form-group">
          <label class="form-label">Expected Annual Return (%)</label>
          <input class="form-input" type="number" name="expected_return" id="g_return" step="0.1" value="<?= $edit_goal['expected_return']??12 ?>" placeholder="12" oninput="recalcSip()">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Monthly SIP Required</label>
        <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:7px;padding:0.6rem 0.875rem;font-family:'DM Mono',monospace;color:var(--lime);font-size:1rem" id="sip_display">₹ —</div>
        <div class="form-hint">Auto-calculated · you can also enter manually:</div>
        <input class="form-input" type="number" name="monthly_sip" id="g_sip" value="<?= $edit_goal['monthly_sip']??'' ?>" placeholder="Auto-calculated" style="margin-top:0.4rem">
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <textarea class="form-textarea" name="notes" rows="2" placeholder="Optional notes about this goal..."><?= htmlspecialchars($edit_goal['notes']??'',ENT_QUOTES,'UTF-8') ?></textarea>
      </div>
      <div style="display:flex;gap:0.75rem;flex-wrap:wrap">
        <button type="submit" class="btn-primary"><?= $edit_goal ? 'Update Goal' : 'Save Goal' ?></button>
        <?php if ($edit_goal): ?><a href="<?= SITE_URL ?>/portal/goals.php" class="btn-ghost">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Achieved goals -->
<?php if (!empty($achieved_goals)): ?>
<div style="margin-top:2rem">
  <h2 class="section-header">Achieved Goals</h2>
  <div class="grid-2">
    <?php foreach ($achieved_goals as $g): ?>
    <div class="portal-card" style="opacity:0.7;border-color:var(--gold)">
      <div style="display:flex;gap:0.75rem;align-items:center">
        <i class="bi <?= htmlspecialchars($goal_icons[$g['goal_type']]??'bi-star',ENT_QUOTES,'UTF-8') ?>" style="font-size:1.5rem;color:var(--gold)"></i>
        <div>
          <div style="color:var(--cream);font-weight:500"><?= htmlspecialchars($g['goal_name'],ENT_QUOTES,'UTF-8') ?></div>
          <div style="font-size:0.78rem;color:var(--gold)"><?= format_inr((float)$g["target_amount"]) ?> · Achieved ✓</div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<script>
function toggleGoalForm() {
  var f = document.getElementById('goal-form');
  var i = document.getElementById('gform-icon');
  var open = f.style.display !== 'none';
  f.style.display = open ? 'none' : 'block';
  i.textContent = open ? '+' : '−';
}

function recalcSip() {
  var target  = parseFloat(document.getElementById('g_target').value) || 0;
  var year    = parseInt(document.getElementById('g_year').value) || 0;
  var savings = parseFloat(document.getElementById('g_savings').value) || 0;
  var ret     = parseFloat(document.getElementById('g_return').value) || 12;
  var now     = new Date().getFullYear();
  var months  = Math.max(1, (year - now) * 12);
  var yrs     = Math.max(0.1, year - now);
  var fv      = target - savings * Math.pow(1 + ret / 100, yrs);
  if (fv <= 0) { document.getElementById('sip_display').textContent = '₹ 0 (already saved enough!)'; return; }
  var r = (ret / 100) / 12;
  var sip = r === 0 ? fv / months : fv * r / (Math.pow(1 + r, months) - 1);
  document.getElementById('sip_display').textContent = '₹ ' + Math.ceil(sip).toLocaleString('en-IN');
  document.getElementById('g_sip').placeholder = Math.ceil(sip);
}
// Init on load
recalcSip();
</script>

<?php require_once '../includes/portal-footer.php'; ?>
