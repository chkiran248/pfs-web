<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$db  = get_db();
$uid = get_user_id();
$error = $success = '';
$active_tab = $_GET['tab'] ?? 'personal';

// ── Fetch current data ────────────────────────────────────
// Fetch user and profile separately to avoid multi-row JOIN issues
// (user_profiles has no UNIQUE on user_id, so JOIN can return multiple rows)
$stmt = $db->prepare("SELECT full_name, email, phone FROM users WHERE id = :uid");
$stmt->execute([':uid' => $uid]);
$profile = $stmt->fetch() ?: [];

$stmt = $db->prepare("SELECT * FROM user_profiles WHERE user_id = :uid ORDER BY id DESC LIMIT 1");
$stmt->execute([':uid' => $uid]);
$profile_row = $stmt->fetch() ?: [];
$profile = array_merge($profile, $profile_row);

// ── Handle: Save personal details ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_personal') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $full_name     = trim($_POST['full_name'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $dob           = trim($_POST['dob'] ?? '');
        $city          = trim($_POST['city'] ?? '');
        $state         = trim($_POST['state'] ?? '');
        $occupation    = trim($_POST['occupation'] ?? '');
        $annual_income = trim($_POST['annual_income'] ?? '');
        $pan           = strtoupper(trim($_POST['pan_number'] ?? ''));

        if (!$full_name || strlen($full_name) < 2) { $error = 'Full name is required.'; }
        elseif ($pan && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) { $error = 'Invalid PAN format (e.g. ABCDE1234F).'; }
        else {
            try {
                $db->prepare("UPDATE users SET full_name = :n, phone = :p WHERE id = :id")
                   ->execute([':n' => $full_name, ':p' => $phone ?: null, ':id' => $uid]);
                $stmt2 = $db->prepare("UPDATE user_profiles SET dob=:dob, city=:city, state=:state, occupation=:occ, annual_income=:inc, pan_number=:pan WHERE user_id=:uid ORDER BY id DESC LIMIT 1");
                $stmt2->execute([':uid'=>$uid,':dob'=>$dob?:null,':city'=>$city?:null,':state'=>$state?:null,':occ'=>$occupation?:null,':inc'=>$annual_income?:null,':pan'=>$pan?:null]);
                if ($stmt2->rowCount() === 0) {
                    $db->prepare("INSERT INTO user_profiles (user_id, dob, city, state, occupation, annual_income, pan_number) VALUES (:uid, :dob, :city, :state, :occ, :inc, :pan)")
                       ->execute([':uid'=>$uid,':dob'=>$dob?:null,':city'=>$city?:null,':state'=>$state?:null,':occ'=>$occupation?:null,':inc'=>$annual_income?:null,':pan'=>$pan?:null]);
                }
                $_SESSION['user_name'] = $full_name;
                $_SESSION['flash'] = ['type'=>'success','message'=>'Profile updated successfully.'];
                header('Location: ' . SITE_URL . '/portal/profile.php?tab=personal');
                exit;
            } catch (PDOException $e) { error_log($e->getMessage()); $error = 'Could not save. Please try again.'; }
        }
    }
}

// Risk tab now handled by portal/risk-assessment.php — no inline POST here

// ── Handle: Change password ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $cur  = $_POST['current_password'] ?? '';
        $new  = $_POST['new_password'] ?? '';
        $conf = $_POST['confirm_password'] ?? '';
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = :id");
        $stmt->execute([':id' => $uid]);
        $hash = $stmt->fetchColumn();
        if (!password_verify($cur, $hash)) { $error = 'Current password is incorrect.'; }
        elseif (strlen($new) < 8 || !preg_match('/[A-Z]/', $new) || !preg_match('/[0-9]/', $new) || !preg_match('/[^a-zA-Z0-9]/', $new)) { $error = 'New password must be 8+ chars with uppercase, number, and special character.'; }
        elseif ($new !== $conf) { $error = 'Passwords do not match.'; }
        else {
            try {
                $db->prepare("UPDATE users SET password_hash = :h WHERE id = :id")
                   ->execute([':h' => password_hash($new, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), ':id' => $uid]);
                $_SESSION['flash'] = ['type'=>'success','message'=>'Password changed successfully.'];
                header('Location: ' . SITE_URL . '/portal/profile.php');
                exit;
            } catch (PDOException $e) { error_log($e->getMessage()); $error = 'Could not update password.'; }
        }
    }
}

$risk_descriptions = [
    'conservative' => 'You prefer capital preservation over high returns. Focus on debt funds, liquid funds, short-duration bonds, and low-volatility hybrid funds.',
    'moderate'     => 'You balance growth with safety. A mix of large-cap equity, balanced/hybrid funds, and some debt instruments suits you best.',
    'aggressive'   => 'You seek maximum long-term wealth creation. Mid-cap, small-cap, flexi-cap, ELSS, and sectoral funds are well-suited for you.',
];
$risk_badge_cfg = [
    'conservative' => ['color' => 'var(--bright)', 'bg' => 'rgba(76,175,80,0.12)'],
    'moderate'     => ['color' => 'var(--gold)',   'bg' => 'rgba(201,168,76,0.12)'],
    'aggressive'   => ['color' => '#ff6b35',        'bg' => 'rgba(255,107,53,0.12)'],
];
$income_ranges = ['<5L' => 'Below ₹5 Lakhs', '5-10L' => '₹5–10 Lakhs', '10-25L' => '₹10–25 Lakhs', '25-50L' => '₹25–50 Lakhs', '50L+' => 'Above ₹50 Lakhs'];

$page_title = 'My Profile — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Account</p>
<h1 class="page-title">My Profile</h1>

<?php if ($error): ?>
  <div class="flash-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- Tab nav -->
<div style="display:flex;gap:0.5rem;margin-bottom:1.75rem;border-bottom:1px solid var(--border);padding-bottom:0">
  <?php foreach (['personal'=>'Personal Details','risk'=>'Risk Profile'] as $tab=>$label): ?>
  <a href="?tab=<?= $tab ?>"
     style="padding:0.6rem 1.25rem;font-size:0.875rem;font-weight:500;border-bottom:2px solid <?= $active_tab===$tab ? 'var(--bright)' : 'transparent' ?>;color:<?= $active_tab===$tab ? 'var(--cream)' : 'var(--text-secondary)' ?>;text-decoration:none;transition:all 0.15s;margin-bottom:-1px">
    <?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($active_tab === 'personal'): ?>
<!-- ── Personal Details Tab ── -->
<div class="portal-card">
  <form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="save_personal">

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Full Name</label>
        <input class="form-input" type="text" name="full_name" value="<?= htmlspecialchars($profile['full_name']??'', ENT_QUOTES,'UTF-8') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Email <span style="color:var(--text-muted)">(cannot change)</span></label>
        <input class="form-input" type="email" value="<?= htmlspecialchars($profile['email']??'', ENT_QUOTES,'UTF-8') ?>" disabled style="opacity:0.6">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Mobile Number</label>
        <input class="form-input" type="tel" name="phone" value="<?= htmlspecialchars($profile['phone']??'', ENT_QUOTES,'UTF-8') ?>" placeholder="+91 98765 43210">
      </div>
      <div class="form-group">
        <label class="form-label">Date of Birth</label>
        <input class="form-input" type="date" name="dob" value="<?= htmlspecialchars($profile['dob']??'', ENT_QUOTES,'UTF-8') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">City</label>
        <input class="form-input" type="text" name="city" value="<?= htmlspecialchars($profile['city']??'', ENT_QUOTES,'UTF-8') ?>" placeholder="Bengaluru">
      </div>
      <div class="form-group">
        <label class="form-label">State</label>
        <input class="form-input" type="text" name="state" value="<?= htmlspecialchars($profile['state']??'', ENT_QUOTES,'UTF-8') ?>" placeholder="Karnataka">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Occupation</label>
        <input class="form-input" type="text" name="occupation" value="<?= htmlspecialchars($profile['occupation']??'', ENT_QUOTES,'UTF-8') ?>" placeholder="Software Engineer">
      </div>
      <div class="form-group">
        <label class="form-label">Annual Income</label>
        <select class="form-select" name="annual_income">
          <option value="">— Select —</option>
          <?php foreach ($income_ranges as $val=>$label): ?>
          <option value="<?= $val ?>" <?= ($profile['annual_income']??'')===$val?'selected':'' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">PAN Number</label>
      <input class="form-input" type="text" name="pan_number" maxlength="10" style="text-transform:uppercase;max-width:220px"
             value="<?= htmlspecialchars($profile['pan_number']??'', ENT_QUOTES,'UTF-8') ?>" placeholder="ABCDE1234F">
      <div class="form-hint">Your PAN is stored securely and never shared.</div>
    </div>
    <button type="submit" class="btn-primary">Save Changes</button>
  </form>
</div>

<?php else: ?>
<!-- ── Risk Profile Tab ── -->
<?php
$rp  = $profile['risk_profile'] ?? null;
$rs  = $profile['risk_score']   ?? null;
$rat = $profile['risk_assessed_at'] ?? null;
$rbc = $risk_badge_cfg[$rp] ?? null;
$assessment_url = SITE_URL . '/portal/risk-assessment.php?redirect=profile';
?>

<?php if ($rp): ?>
<div class="portal-card" style="margin-bottom:1.25rem">
  <!-- Profile header -->
  <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem">
    <div style="display:flex;align-items:center;gap:0.6rem;background:<?= $rbc['bg'] ?>;border:1px solid <?= $rbc['color'] ?>;border-radius:24px;padding:0.4rem 1.1rem">
      <span style="font-weight:600;font-size:1.05rem;color:<?= $rbc['color'] ?>"><?= ucfirst($rp) ?></span>
    </div>
    <?php if ($rs !== null): ?>
    <span style="font-family:'IBM Plex Mono',monospace;font-size:0.78rem;color:var(--text-secondary)">
      Score: <strong style="color:var(--cream)"><?= $rs ?>/20</strong>
    </span>
    <?php endif; ?>
    <?php if ($rat): ?>
    <span style="font-size:0.78rem;color:var(--text-secondary)">
      Assessed: <?= date('d M Y', strtotime($rat)) ?>
    </span>
    <?php endif; ?>
  </div>

  <p style="color:var(--text-secondary);font-size:0.9rem;line-height:1.7;margin-bottom:1rem">
    <?= $risk_descriptions[$rp] ?? '' ?>
  </p>

  <!-- Score breakdown bar -->
  <?php if ($rs !== null): ?>
  <div style="margin-bottom:1rem">
    <div style="font-family:'IBM Plex Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.12em;margin-bottom:0.4rem">RISK SCORE SPECTRUM</div>
    <div style="position:relative;height:8px;background:var(--surface-2);border-radius:4px;overflow:hidden">
      <div style="position:absolute;left:0;top:0;height:100%;width:30%;background:var(--bright);opacity:0.5;border-radius:4px 0 0 4px"></div>
      <div style="position:absolute;left:30%;top:0;height:100%;width:35%;background:var(--gold);opacity:0.5"></div>
      <div style="position:absolute;left:65%;top:0;height:100%;width:35%;background:#ff6b35;opacity:0.5;border-radius:0 4px 4px 0"></div>
      <div style="position:absolute;top:-2px;height:12px;width:12px;border-radius:50%;background:var(--cream);border:2px solid var(--bg);left:calc(<?= round($rs / 20 * 100) ?>% - 6px);transition:left 0.4s"></div>
    </div>
    <div style="display:flex;justify-content:space-between;font-family:'IBM Plex Mono',monospace;font-size:0.6rem;color:var(--text-muted);margin-top:0.35rem">
      <span>Conservative (0–6)</span><span>Moderate (7–13)</span><span>Aggressive (14–20)</span>
    </div>
  </div>
  <?php endif; ?>

  <!-- Recommended fund types -->
  <div style="background:var(--surface-2);border-radius:10px;padding:1rem;margin-bottom:1rem">
    <div style="font-family:'IBM Plex Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.12em;margin-bottom:0.6rem">SUITABLE FUND CATEGORIES</div>
    <?php
    $suggestions = [
        'conservative' => [['Liquid / Overnight', 'Lowest risk — park emergency funds'],['Short Duration Debt', 'Stable income, low volatility'],['Conservative Hybrid', 'Mostly debt with small equity kicker'],['Arbitrage Funds', 'Equity taxation, debt-level risk']],
        'moderate'     => [['Large Cap Equity', 'Blue-chip stability with growth'],['Balanced Advantage', 'Dynamic equity-debt allocation'],['ELSS', 'Tax saving + market-linked growth'],['Flexi Cap', 'Fund manager discretion across caps']],
        'aggressive'   => [['Mid Cap Equity', 'High growth potential, higher volatility'],['Small Cap Equity', 'Maximum long-term compounding'],['Sectoral / Thematic', 'Concentrated bets on sectors'],['International Funds', 'Geographic diversification']],
    ];
    foreach (($suggestions[$rp] ?? []) as [$cat, $desc]):
    ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.35rem 0;border-bottom:1px solid var(--border-light)">
      <span style="font-size:0.85rem;color:var(--cream)"><?= $cat ?></span>
      <span style="font-size:0.78rem;color:var(--text-secondary)"><?= $desc ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="display:flex;gap:0.75rem;flex-wrap:wrap">
    <a href="<?= $assessment_url ?>" class="btn-outline btn-sm">Retake Assessment</a>
    <a href="<?= SITE_URL ?>/advisory/mutual-funds.php" class="btn-primary btn-sm">View My Recommendations →</a>
  </div>
</div>

<?php else: ?>
<!-- No profile yet -->
<div class="portal-card" style="text-align:center;padding:3rem 2rem">
  <div style="font-size:2rem;margin-bottom:0.75rem">📊</div>
  <h3 style="font-family:'Cormorant Garamond',serif;color:var(--cream);font-size:1.4rem;margin-bottom:0.6rem">No risk profile on file</h3>
  <p style="color:var(--text-secondary);font-size:0.9rem;line-height:1.7;max-width:400px;margin:0 auto 1.5rem">
    Complete our 5-question assessment to get personalised fund recommendations matched to your risk tolerance.
  </p>
  <a href="<?= $assessment_url ?>" class="btn-primary">Start Assessment →</a>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Change Password -->
<div class="portal-card" style="margin-top:1.5rem">
  <div class="card-title">Change Password</div>
  <form method="POST" novalidate style="max-width:420px">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES,'UTF-8') ?>">
    <input type="hidden" name="action" value="change_password">
    <div class="form-group">
      <label class="form-label">Current Password</label>
      <div class="password-field">
        <input class="form-input" type="password" name="current_password" required autocomplete="current-password">
        <button type="button" class="password-toggle" onclick="togglePw('cp',this)">👁</button>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">New Password</label>
      <div class="password-field">
        <input class="form-input" type="password" name="new_password" id="cp2" required autocomplete="new-password">
        <button type="button" class="password-toggle" onclick="togglePw('cp2',this)">👁</button>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Confirm New Password</label>
      <div class="password-field">
        <input class="form-input" type="password" name="confirm_password" id="cp3" required autocomplete="new-password">
        <button type="button" class="password-toggle" onclick="togglePw('cp3',this)">👁</button>
      </div>
    </div>
    <button type="submit" class="btn-outline">Update Password</button>
  </form>
</div>

<script>
function togglePw(id, btn) {
  var f = document.getElementById(id) || document.querySelector('[name="' + id + '"]');
  if (!f) return;
  f.type = f.type === 'password' ? 'text' : 'password';
  btn.textContent = f.type === 'password' ? '👁' : '🙈';
}
</script>

<?php require_once '../includes/portal-footer.php'; ?>
