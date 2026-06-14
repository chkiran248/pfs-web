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

// ── Handle: Risk quiz ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_risk') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $score = 0;
        for ($i = 1; $i <= 11; $i++) {
            $score += (int)($_POST["q$i"] ?? 1);
        }
        // Max score = 44 (11 questions × 4). Thresholds scaled from 10-question version.
        $risk = $score <= 22 ? 'conservative' : ($score <= 33 ? 'moderate' : 'aggressive');
        $life = $score <= 22 ? 'preservation' : ($score <= 33 ? 'growth' : 'accumulation');
        try {
            $stmt2 = $db->prepare("UPDATE user_profiles SET risk_profile=:risk, life_stage=:life WHERE user_id=:uid ORDER BY id DESC LIMIT 1");
            $stmt2->execute([':uid'=>$uid,':risk'=>$risk,':life'=>$life]);
            if ($stmt2->rowCount() === 0) {
                $db->prepare("INSERT INTO user_profiles (user_id, risk_profile, life_stage) VALUES (:uid, :risk, :life)")
                   ->execute([':uid'=>$uid,':risk'=>$risk,':life'=>$life]);
            }
            $_SESSION['flash'] = ['type'=>'success','message'=>'Risk profile saved: ' . ucfirst($risk) . '.'];
            header('Location: ' . SITE_URL . '/portal/profile.php?tab=risk');
            exit;
        } catch (PDOException $e) { error_log($e->getMessage()); $error = 'Could not save risk profile.'; }
    }
}

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
    'conservative' => 'You prefer capital protection over high returns. Recommended: Debt funds, FDs, Liquid funds.',
    'moderate'     => 'You balance growth with safety. Recommended: Balanced/Hybrid funds, large-cap equity, NPS.',
    'aggressive'   => 'You seek maximum long-term growth. Recommended: Mid/small cap, ELSS, sectoral funds.',
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
<?php if ($profile['risk_profile']): ?>
<div class="portal-card" style="margin-bottom:1.25rem">
  <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem">
    <div class="stat-value positive" style="font-size:1.5rem"><?= ucfirst($profile['risk_profile']) ?></div>
    <span class="badge badge-green" style="font-size:0.7rem"><?= ucfirst($profile['life_stage']??'') ?></span>
  </div>
  <p style="color:var(--text-secondary);font-size:0.9rem;line-height:1.7"><?= $risk_descriptions[$profile['risk_profile']] ?? '' ?></p>
  <div style="margin-top:1rem">
    <a href="?tab=risk&retake=1" class="btn-outline btn-sm">Retake Quiz</a>
  </div>
</div>
<?php endif; ?>

<?php if (!$profile['risk_profile'] || isset($_GET['retake'])): ?>
<div class="portal-card">
  <div class="card-title">Risk Profile Assessment</div>
  <p style="color:var(--text-secondary);font-size:0.875rem;margin-bottom:1.5rem">Answer 10 quick questions to find your investor profile.</p>
  <form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES,'UTF-8') ?>">
    <input type="hidden" name="action" value="save_risk">

    <?php
    $questions = [
      1 => ['q'=>'What is your current age?', 'opts'=>['Above 50','36–50 years','25–35 years','Under 25 years']],
      2 => ['q'=>'What is your primary investment goal?', 'opts'=>['Capital Preservation','Regular Income','Balanced Growth','Maximum Growth']],
      3 => ['q'=>'What is your investment time horizon?', 'opts'=>['Less than 1 year','1–3 years','3–7 years','More than 7 years']],
      4 => ['q'=>'If your portfolio drops 20%, you would…', 'opts'=>['Sell everything','Sell some holdings','Hold and wait','Buy more']],
      5 => ['q'=>'How stable is your income?', 'opts'=>['Unpredictable','Variable','Stable','Very stable']],
      6 => ['q'=>'How many months of expenses do you have as emergency fund?', 'opts'=>['Less than 3 months','3–6 months','6–12 months','More than 12 months']],
      7 => ['q'=>'What is your investment experience?', 'opts'=>['Beginner — new to investing','Some — invested in FDs/MFs','Experienced — track markets actively','Expert — manage own portfolio']],
      8 => ['q'=>'What are your current financial liabilities (loans etc.)?', 'opts'=>['Very high','Moderate','Low','None']],
      9 => ['q'=>'How many financial dependents do you have?', 'opts'=>['3 or more','2','1','None']],
      10=> ['q'=>'When might you need this invested money?', 'opts'=>['Within 2 years','2–5 years','5–10 years','More than 10 years']],
      11=> ['q'=>'Are you comfortable with short-term losses for long-term gains?', 'opts'=>['No, I need capital safety','Somewhat, minor dips are okay','Yes, I can handle volatility','Definitely — I focus on long-term']],
    ];
    foreach ($questions as $n => $q): ?>
    <div class="form-group" style="margin-bottom:1.5rem">
      <label class="form-label" style="font-size:0.875rem;color:var(--cream)">
        <?= $n ?>. <?= htmlspecialchars($q['q'], ENT_QUOTES,'UTF-8') ?>
      </label>
      <div style="display:flex;flex-direction:column;gap:0.4rem;margin-top:0.4rem">
        <?php foreach ($q['opts'] as $i => $opt): ?>
        <label style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;font-size:0.875rem;color:var(--text-secondary);padding:0.4rem 0">
          <input type="radio" name="q<?= $n ?>" value="<?= $i+1 ?>" required style="accent-color:var(--mid)">
          <?= htmlspecialchars($opt, ENT_QUOTES,'UTF-8') ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <button type="submit" class="btn-primary">Get My Risk Profile →</button>
  </form>
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
