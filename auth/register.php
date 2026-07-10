<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mailer.php';

if (is_logged_in()) {
    $dest = ($_SESSION['user_role'] ?? '') === 'admin' ? '/admin/dashboard.php' : '/portal/dashboard.php';
    header('Location: ' . SITE_URL . $dest);
    exit;
}

$error  = '';
$errors = [];
$vals   = ['name' => '', 'email' => '', 'phone' => ''];

function validate_password_strength(string $pw): ?string {
    if (strlen($pw) < 8)                           return 'At least 8 characters required.';
    if (!preg_match('/[A-Z]/', $pw))               return 'Must include at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $pw))               return 'Must include at least one number.';
    if (!preg_match('/[^a-zA-Z0-9]/', $pw))        return 'Must include at least one special character.';
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $name    = trim($_POST['full_name'] ?? '');
        $email   = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $pass    = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $terms   = isset($_POST['terms']);

        $vals = [
            'name'  => htmlspecialchars($name,  ENT_QUOTES, 'UTF-8'),
            'email' => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
            'phone' => htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'),
        ];

        // Validation
        if (!$name || strlen($name) < 2 || strlen($name) > 100) {
            $errors[] = 'Full name must be 2–100 characters.';
        } elseif (!preg_match('/^[a-zA-Z\s]+$/', $name)) {
            $errors[] = 'Full name may only contain letters and spaces.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }

        $pw_error = validate_password_strength($pass);
        if ($pw_error) $errors[] = $pw_error;

        if ($pass !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (!$terms) {
            $errors[] = 'You must accept the terms to create an account.';
        }

        if (empty($errors)) {
            try {
                $db = get_db();

                // Check for existing email
                $stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
                $stmt->execute([':email' => $email]);
                if ($stmt->fetch()) {
                    $errors[] = 'An account with this email already exists.';
                } else {
                    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

                    // Insert user
                    $stmt = $db->prepare(
                        "INSERT INTO users (full_name, email, phone, password_hash, role, email_verified, is_active)
                         VALUES (:name, :email, :phone, :hash, 'client', 0, 1)"
                    );
                    $stmt->execute([
                        ':name'  => $name,
                        ':email' => $email,
                        ':phone' => $phone ?: null,
                        ':hash'  => $hash,
                    ]);
                    $user_id = (int) $db->lastInsertId();

                    // Create empty profile
                    $db->prepare("INSERT INTO user_profiles (user_id) VALUES (:uid)")
                       ->execute([':uid' => $user_id]);

                    // Generate OTP
                    $otp     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $otp_hash = password_hash($otp, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
                    $expires  = date('Y-m-d H:i:s', time() + OTP_EXPIRY_MINUTES * 60);

                    $db->prepare(
                        "INSERT INTO otp_tokens (user_id, token_hash, type, expires_at)
                         VALUES (:uid, :hash, 'email_verify', :exp)"
                    )->execute([':uid' => $user_id, ':hash' => $otp_hash, ':exp' => $expires]);

                    // Send welcome + verify email
                    $html_body = '
                    <div style="font-family:sans-serif;max-width:500px;margin:0 auto">
                      <h2 style="color:#2E8540">Welcome to Prime Financials</h2>
                      <p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>
                      <p>Thank you for registering. Please verify your email using the OTP below:</p>
                      <div style="background:#f0f6f0;border:1px solid #c8e6c9;border-radius:8px;padding:1.5rem;text-align:center;margin:1.5rem 0">
                        <p style="margin:0;font-family:monospace;font-size:2.5rem;font-weight:bold;color:#1B5E2A;letter-spacing:0.5rem">' . $otp . '</p>
                        <p style="margin:0.5rem 0 0;font-size:0.8rem;color:#666">Expires in ' . OTP_EXPIRY_MINUTES . ' minutes</p>
                      </div>
                      <p style="font-size:0.85rem;color:#666">If you did not create this account, please ignore this email.</p>
                      <hr style="border:none;border-top:1px solid #e0e0e0;margin:1.5rem 0"/>
                      <p style="font-size:0.8rem;color:#999">Prime Financials · support@primefin.in · +91 9980001338</p>
                    </div>';

                    send_email(
                        $email,
                        'Verify Your Email',
                        $html_body,
                        "Hi $name,\n\nYour email verification OTP is: $otp\n\nExpires in " . OTP_EXPIRY_MINUTES . " minutes.\n\n— Prime Financials"
                    );

                    $_SESSION['pending_verify_email'] = $email;

                    // In dev mode, store OTP in session so it can be shown on-screen
                    if (APP_ENV === 'development') {
                        $_SESSION['dev_otp'] = $otp;
                    }

                    header('Location: ' . SITE_URL . '/auth/verify-email.php?email=' . urlencode($email));
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Register error: ' . $e->getMessage());
                $errors[] = 'Something went wrong. Please try again.';
            }
        }

        if ($errors) {
            $error = implode('<br>', $errors);
        }
    }
}

$page_title = 'Create Account — Prime Financials';
$auth_wide  = true;
require_once __DIR__ . '/auth-layout.php';
?>

<h1 class="auth-heading">Create Account</h1>
<p class="auth-sub">Join Prime Financials and take control of your wealth</p>

<?php if ($error): ?>
  <div class="flash-error" style="margin-bottom:1.25rem">
    <?= $error ?>
  </div>
<?php endif; ?>

<form method="POST" action="<?= SITE_URL ?>/auth/register.php" novalidate>
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>" />

  <div class="form-group">
    <label class="form-label" for="full_name">Full Name</label>
    <input class="form-input" type="text" id="full_name" name="full_name"
           value="<?= $vals['name'] ?>" placeholder="Your full name" required autocomplete="name" />
  </div>

  <div class="form-group">
    <label class="form-label" for="email">Email Address</label>
    <input class="form-input" type="email" id="email" name="email"
           value="<?= $vals['email'] ?>" placeholder="you@example.com" required autocomplete="email" />
  </div>

  <div class="form-group">
    <label class="form-label" for="phone">Mobile Number <span style="color:var(--text-muted)">(optional)</span></label>
    <input class="form-input" type="tel" id="phone" name="phone"
           value="<?= $vals['phone'] ?>" placeholder="+91 98765 43210" autocomplete="tel" />
  </div>

  <div class="form-group">
    <label class="form-label" for="password">Password</label>
    <div class="password-field">
      <input class="form-input" type="password" id="password" name="password"
             placeholder="Min 8 chars, uppercase, number, special" required autocomplete="new-password"
             oninput="checkStrength(this.value)" />
      <button type="button" class="password-toggle" onclick="togglePassword('password', this)" title="Show/hide">👁</button>
    </div>
    <div class="strength-bar-wrap"><div class="strength-bar" id="strength-bar"></div></div>
    <div class="strength-label" id="strength-label"></div>
  </div>

  <div class="form-group">
    <label class="form-label" for="confirm_password">Confirm Password</label>
    <div class="password-field">
      <input class="form-input" type="password" id="confirm_password" name="confirm_password"
             placeholder="Re-enter your password" required autocomplete="new-password" />
      <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)" title="Show/hide">👁</button>
    </div>
  </div>

  <div class="check-row" style="margin-bottom:1.5rem">
    <input type="checkbox" id="terms" name="terms" required />
    <label for="terms">I agree to the <a href="<?= SITE_URL ?>/#" class="auth-link">Terms of Service</a> and <a href="<?= SITE_URL ?>/#" class="auth-link">Privacy Policy</a></label>
  </div>

  <button type="submit" class="btn-primary" style="width:100%;justify-content:center">
    Create Account →
  </button>
</form>

<div class="auth-footer">
  Already have an account? <a href="<?= SITE_URL ?>/auth/login.php" class="auth-link">Login</a>
</div>

    </div><!-- /.auth-card -->
  </div><!-- /.auth-inner -->
</div><!-- /.auth-wrapper -->

<script src="<?= SITE_URL ?>/assets/js/portal.js"></script>
<script>
function togglePassword(fieldId, btn) {
  var f = document.getElementById(fieldId);
  f.type = f.type === 'password' ? 'text' : 'password';
  btn.textContent = f.type === 'password' ? '👁' : '🙈';
}

function checkStrength(pw) {
  var bar   = document.getElementById('strength-bar');
  var label = document.getElementById('strength-label');
  var score = 0;
  if (pw.length >= 8)                       score++;
  if (/[A-Z]/.test(pw))                     score++;
  if (/[0-9]/.test(pw))                     score++;
  if (/[^a-zA-Z0-9]/.test(pw))             score++;
  var widths = ['0%','25%','50%','75%','100%'];
  var colours = ['','#ef5350','#C9A84C','#8DC63F','#4CAF50'];
  var labels  = ['','Weak','Fair','Good','Strong'];
  bar.style.width      = widths[score];
  bar.style.background = colours[score];
  label.textContent    = labels[score];
  label.style.color    = colours[score];
}
</script>
</body>
</html>
