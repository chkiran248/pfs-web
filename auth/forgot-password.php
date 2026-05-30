<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mailer.php';

if (is_logged_in()) {
    header('Location: ' . SITE_URL . '/portal/dashboard.php');
    exit;
}

// Step: 'email' → 'reset' (OTP + new password on same page after email submitted)
$step  = $_SESSION['reset_step'] ?? 'email';
$error = '';

// ── STEP 1: Email submission ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_otp') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');

        // Always show same message regardless of whether email exists
        $always_msg = 'If this email is registered, a reset OTP has been sent to it.';

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $db   = get_db();
                $stmt = $db->prepare("SELECT * FROM users WHERE email = :email AND is_active = 1");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch();

                if ($user) {
                    $otp      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $otp_hash = password_hash($otp, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
                    $expires  = date('Y-m-d H:i:s', time() + OTP_EXPIRY_MINUTES * 60);

                    // Invalidate old reset OTPs
                    $db->prepare("UPDATE otp_tokens SET used = 1 WHERE user_id = :uid AND type = 'password_reset' AND used = 0")
                       ->execute([':uid' => $user['id']]);

                    $db->prepare(
                        "INSERT INTO otp_tokens (user_id, token_hash, type, expires_at)
                         VALUES (:uid, :hash, 'password_reset', :exp)"
                    )->execute([':uid' => $user['id'], ':hash' => $otp_hash, ':exp' => $expires]);

                    $html_body = '
                    <div style="font-family:sans-serif;max-width:500px">
                      <h2 style="color:#2E8540">Reset Your Password</h2>
                      <p>Hi ' . htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') . ',</p>
                      <p>Your password reset OTP is:</p>
                      <div style="background:#f0f6f0;border:1px solid #c8e6c9;border-radius:8px;padding:1.5rem;text-align:center;margin:1rem 0">
                        <p style="margin:0;font-family:monospace;font-size:2.5rem;font-weight:bold;color:#1B5E2A;letter-spacing:0.5rem">' . $otp . '</p>
                        <p style="margin:0.5rem 0 0;font-size:0.8rem;color:#666">Expires in ' . OTP_EXPIRY_MINUTES . ' minutes</p>
                      </div>
                      <p style="font-size:0.85rem;color:#666">If you did not request this, please ignore this email or contact us immediately.</p>
                      <p style="font-size:0.8rem;color:#999">Prime Financials · support@primefin.in · +91 9980001338</p>
                    </div>';

                    send_email($email, 'Reset Your Password', $html_body);

                    $_SESSION['reset_email'] = $email;
                    $_SESSION['reset_step']  = 'reset';
                }
            } catch (PDOException $e) {
                error_log('Forgot password error: ' . $e->getMessage());
            }
        }

        // Always redirect to step 2 to not reveal if email exists
        $_SESSION['forgot_msg'] = $always_msg;
        $_SESSION['reset_step'] = 'reset';
        if (!isset($_SESSION['reset_email'])) {
            $_SESSION['reset_email'] = $email; // store anyway; OTP verify will fail safely
        }
        header('Location: ' . SITE_URL . '/auth/forgot-password.php');
        exit;
    }
}

// ── STEP 2: OTP + new password ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $otp_input = trim($_POST['otp'] ?? '');
        $new_pass  = $_POST['new_password'] ?? '';
        $confirm   = $_POST['confirm_password'] ?? '';
        $r_email   = $_SESSION['reset_email'] ?? '';

        // Validate password strength
        $pw_errors = [];
        if (strlen($new_pass) < 8)                     $pw_errors[] = 'At least 8 characters.';
        if (!preg_match('/[A-Z]/', $new_pass))          $pw_errors[] = 'One uppercase letter required.';
        if (!preg_match('/[0-9]/', $new_pass))          $pw_errors[] = 'One number required.';
        if (!preg_match('/[^a-zA-Z0-9]/', $new_pass))  $pw_errors[] = 'One special character required.';
        if ($new_pass !== $confirm)                     $pw_errors[] = 'Passwords do not match.';

        if ($pw_errors) {
            $error = implode('<br>', $pw_errors);
            $step  = 'reset';
        } else {
            try {
                $db   = get_db();
                $stmt = $db->prepare("SELECT * FROM users WHERE email = :email AND is_active = 1");
                $stmt->execute([':email' => $r_email]);
                $user = $stmt->fetch();

                if (!$user) {
                    $error = 'Invalid reset request. Please start over.';
                    $step  = 'reset';
                } else {
                    $stmt = $db->prepare(
                        "SELECT * FROM otp_tokens
                         WHERE user_id = :uid AND type = 'password_reset' AND used = 0 AND expires_at > NOW()
                         ORDER BY created_at DESC LIMIT 1"
                    );
                    $stmt->execute([':uid' => $user['id']]);
                    $otp_row = $stmt->fetch();

                    if (!$otp_row || !password_verify($otp_input, $otp_row['token_hash'])) {
                        $error = 'Invalid or expired OTP. Please try again or request a new one.';
                        $step  = 'reset';
                    } else {
                        // Mark OTP used
                        $db->prepare("UPDATE otp_tokens SET used = 1 WHERE id = :id")
                           ->execute([':id' => $otp_row['id']]);

                        // Update password
                        $new_hash = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
                        $db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id")
                           ->execute([':hash' => $new_hash, ':id' => $user['id']]);

                        // Invalidate all sessions for this user
                        $db->prepare("DELETE FROM sessions WHERE user_id = :uid")
                           ->execute([':uid' => $user['id']]);

                        unset($_SESSION['reset_email'], $_SESSION['reset_step'], $_SESSION['forgot_msg']);

                        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Password reset successfully. Please login with your new password.'];
                        header('Location: ' . SITE_URL . '/auth/login.php');
                        exit;
                    }
                }
            } catch (PDOException $e) {
                error_log('Reset password error: ' . $e->getMessage());
                $error = 'Something went wrong. Please try again.';
                $step  = 'reset';
            }
        }
    }
}

// Set current step from session if not overridden by POST
if (empty($step) || $step === 'email') {
    $step = $_SESSION['reset_step'] ?? 'email';
}

$forgot_msg = $_SESSION['forgot_msg'] ?? '';
if ($forgot_msg) unset($_SESSION['forgot_msg']);

$reset_email = $_SESSION['reset_email'] ?? '';

function mask_email_fp(string $email): string {
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) return $email;
    $local = $parts[0];
    return substr($local, 0, 1) . str_repeat('*', max(strlen($local) - 1, 2)) . '@' . $parts[1];
}

$page_title = 'Reset Password — Prime Financials';
require_once __DIR__ . '/auth-layout.php';
?>

<?php if ($step === 'email'): ?>

  <h1 class="auth-heading">Forgot Password</h1>
  <p class="auth-sub">Enter your registered email and we'll send you a reset OTP</p>

  <?php if ($error): ?>
    <div class="flash-error" style="margin-bottom:1.25rem"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>" />
    <input type="hidden" name="action"     value="send_otp" />

    <div class="form-group">
      <label class="form-label" for="email">Email Address</label>
      <input class="form-input" type="email" id="email" name="email"
             placeholder="you@example.com" required autocomplete="email" />
    </div>

    <button type="submit" class="btn-primary" style="width:100%;justify-content:center">
      Send Reset OTP →
    </button>
  </form>

  <div class="auth-footer">
    <a href="<?= SITE_URL ?>/auth/login.php" class="auth-link">← Back to Login</a>
  </div>

<?php else: ?>

  <h1 class="auth-heading">Reset Password</h1>
  <p class="auth-sub">
    Enter the OTP sent to<br>
    <span class="masked-email"><?= htmlspecialchars(mask_email_fp($reset_email), ENT_QUOTES, 'UTF-8') ?></span>
  </p>

  <?php if ($forgot_msg): ?>
    <div class="flash-info" style="margin-bottom:1.25rem"><?= htmlspecialchars($forgot_msg, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="flash-error" style="margin-bottom:1.25rem"><?= $error ?></div>
  <?php endif; ?>

  <form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>" />
    <input type="hidden" name="action"     value="reset_password" />

    <div class="form-group">
      <label class="form-label" for="otp">OTP Code</label>
      <input class="form-input otp-input" type="text" id="otp" name="otp"
             inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
             placeholder="000000" required autocomplete="one-time-code" />
    </div>

    <div class="form-group">
      <label class="form-label" for="new_password">New Password</label>
      <div class="password-field">
        <input class="form-input" type="password" id="new_password" name="new_password"
               placeholder="Min 8 chars, uppercase, number, special" required />
        <button type="button" class="password-toggle" onclick="togglePassword('new_password', this)">👁</button>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label" for="confirm_password">Confirm New Password</label>
      <div class="password-field">
        <input class="form-input" type="password" id="confirm_password" name="confirm_password"
               placeholder="Re-enter new password" required />
        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)">👁</button>
      </div>
    </div>

    <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:0.5rem">
      Reset Password →
    </button>
  </form>

  <div class="resend-row">
    Wrong email?
    <a href="<?= SITE_URL ?>/auth/forgot-password.php?restart=1" class="auth-link"
       onclick="fetch(''); <?php
         // Restart: clear reset session vars via a GET link helper
       ?>">Start over</a>
  </div>

  <div class="auth-footer">
    <a href="<?= SITE_URL ?>/auth/login.php" class="auth-link">← Back to Login</a>
  </div>

<?php endif; ?>

    </div><!-- /.auth-card -->
  </div><!-- /.auth-inner -->
</div><!-- /.auth-wrapper -->

<?php
// Handle "restart" GET param to clear session state
if (isset($_GET['restart'])) {
    unset($_SESSION['reset_email'], $_SESSION['reset_step'], $_SESSION['forgot_msg']);
    header('Location: ' . SITE_URL . '/auth/forgot-password.php');
    exit;
}
?>

<script src="<?= SITE_URL ?>/assets/js/portal.js"></script>
<script>
function togglePassword(fieldId, btn) {
  var f = document.getElementById(fieldId);
  f.type = f.type === 'password' ? 'text' : 'password';
  btn.textContent = f.type === 'password' ? '👁' : '🙈';
}
</script>
</body>
</html>
