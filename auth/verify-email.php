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

$error   = '';
$success = '';
$email   = $_SESSION['pending_verify_email']
        ?? trim(filter_input(INPUT_GET, 'email', FILTER_SANITIZE_EMAIL) ?? '');

function mask_email(string $email): string {
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) return $email;
    $local = $parts[0];
    return substr($local, 0, 1) . str_repeat('*', max(strlen($local) - 1, 2)) . '@' . $parts[1];
}

function get_user_by_email(string $email): ?array {
    $stmt = get_db()->prepare("SELECT * FROM users WHERE email = :email AND is_active = 1");
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ── Handle OTP verify (POST) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $submitted_otp = trim($_POST['otp'] ?? '');
        $user = $email ? get_user_by_email($email) : null;

        if (!$user) {
            $error = 'Email address not found.';
        } elseif ($user['email_verified']) {
            $_SESSION['flash'] = ['type' => 'info', 'message' => 'Your email is already verified. Please login.'];
            header('Location: ' . SITE_URL . '/auth/login.php');
            exit;
        } else {
            try {
                $db   = get_db();
                $stmt = $db->prepare(
                    "SELECT * FROM otp_tokens
                     WHERE user_id = :uid AND type = 'email_verify' AND used = 0 AND expires_at > NOW()
                     ORDER BY created_at DESC LIMIT 1"
                );
                $stmt->execute([':uid' => $user['id']]);
                $otp_row = $stmt->fetch();

                // Dev mode: diagnose exactly what is failing
                if (APP_ENV === 'development') {
                    // Check without the expires filter to see if the row exists at all
                    $diag = $db->prepare("SELECT id, used, expires_at, (expires_at > NOW()) as not_expired FROM otp_tokens WHERE user_id = :uid AND type = 'email_verify' ORDER BY created_at DESC LIMIT 1");
                    $diag->execute([':uid' => $user['id']]);
                    $diag_row = $diag->fetch();
                    if (!$diag_row) {
                        $error = '[Dev] No OTP row found in DB for this user at all.';
                    } elseif ($diag_row['used']) {
                        $error = '[Dev] OTP row exists but is already marked used=1.';
                    } elseif (!$diag_row['not_expired']) {
                        $error = '[Dev] OTP row exists but expires_at check failed. expires_at=' . $diag_row['expires_at'];
                    } elseif (!$otp_row) {
                        $error = '[Dev] OTP row filtered out by full query (unexpectedly).';
                    } elseif (!password_verify($submitted_otp, $otp_row['token_hash'])) {
                        $session_otp = $_SESSION['dev_otp'] ?? '(not in session)';
                        $error = '[Dev] password_verify failed. You entered: "' . htmlspecialchars($submitted_otp) . '" | Session OTP: "' . $session_otp . '"';
                    }
                }

                $dev_otp_match = APP_ENV === 'development'
                    && isset($_SESSION['dev_otp'])
                    && hash_equals($_SESSION['dev_otp'], $submitted_otp);

                if (!$dev_otp_match && (!$otp_row || !password_verify($submitted_otp, $otp_row['token_hash']))) {
                    if (empty($error)) {
                        $error = 'Invalid or expired OTP. Please request a new one.';
                    }
                } else {
                    // Mark OTP used + user verified
                    if ($otp_row) {
                        $db->prepare("UPDATE otp_tokens SET used = 1 WHERE id = :id")
                           ->execute([':id' => $otp_row['id']]);
                    }
                    $db->prepare("UPDATE users SET email_verified = 1 WHERE id = :id")
                       ->execute([':id' => $user['id']]);

                    // Auto-login
                    session_regenerate_id(true);
                    $_SESSION['user_id']    = (int) $user['id'];
                    $_SESSION['user_role']  = $user['role'];
                    $_SESSION['user_name']  = $user['full_name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['login_time'] = time();

                    // Record session
                    $token   = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
                    $db->prepare(
                        "INSERT INTO sessions (user_id, session_token, ip_address, user_agent, is_valid, expires_at)
                         VALUES (:uid, :token, :ip, :ua, 1, :exp)"
                    )->execute([
                        ':uid'   => $user['id'],
                        ':token' => $token,
                        ':ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
                        ':ua'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                        ':exp'   => $expires,
                    ]);

                    unset($_SESSION['pending_verify_email'], $_SESSION['dev_otp']);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Email verified! Welcome to Prime Financials.'];
                    header('Location: ' . SITE_URL . '/portal/dashboard.php'); // new users are always clients
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Verify email error: ' . $e->getMessage());
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

// ── Handle Resend OTP (POST) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $user = $email ? get_user_by_email($email) : null;

        // Always show same message — don't reveal if email exists
        $success = 'If your email is registered, a new OTP has been sent.';

        if ($user && !$user['email_verified']) {
            try {
                $db = get_db();

                // Rate limit: max 3 OTPs per hour
                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM otp_tokens
                     WHERE user_id = :uid AND type = 'email_verify'
                     AND created_at > NOW() - INTERVAL 1 HOUR"
                );
                $stmt->execute([':uid' => $user['id']]);
                $hourly = (int) $stmt->fetchColumn();

                // 2-minute cooldown
                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM otp_tokens
                     WHERE user_id = :uid AND type = 'email_verify'
                     AND created_at > NOW() - INTERVAL 2 MINUTE"
                );
                $stmt->execute([':uid' => $user['id']]);
                $recent = (int) $stmt->fetchColumn();

                if ($hourly < 3 && $recent === 0) {
                    // Invalidate old OTPs
                    $db->prepare("UPDATE otp_tokens SET used = 1 WHERE user_id = :uid AND type = 'email_verify' AND used = 0")
                       ->execute([':uid' => $user['id']]);

                    $otp      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $otp_hash = password_hash($otp, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
                    $expires  = date('Y-m-d H:i:s', time() + OTP_EXPIRY_MINUTES * 60);

                    $db->prepare(
                        "INSERT INTO otp_tokens (user_id, token_hash, type, expires_at)
                         VALUES (:uid, :hash, 'email_verify', :exp)"
                    )->execute([':uid' => $user['id'], ':hash' => $otp_hash, ':exp' => $expires]);

                    $html_body = '
                    <div style="font-family:sans-serif;max-width:500px">
                      <h2 style="color:#2E8540">Verify Your Email</h2>
                      <p>Hi ' . htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') . ',</p>
                      <p>Your new verification OTP is:</p>
                      <div style="background:#f0f6f0;border:1px solid #c8e6c9;border-radius:8px;padding:1.5rem;text-align:center;margin:1rem 0">
                        <p style="margin:0;font-family:monospace;font-size:2.5rem;font-weight:bold;color:#1B5E2A;letter-spacing:0.5rem">' . $otp . '</p>
                        <p style="margin:0.5rem 0 0;font-size:0.8rem;color:#666">Expires in ' . OTP_EXPIRY_MINUTES . ' minutes</p>
                      </div>
                      <p style="font-size:0.8rem;color:#999">Prime Financials · support@primefin.in</p>
                    </div>';

                    send_email($user['email'], 'Verify Your Email', $html_body);
                }
            } catch (PDOException $e) {
                error_log('Resend OTP error: ' . $e->getMessage());
            }
        }
    }
}

$page_title  = 'Verify Email — Prime Financials';
$masked      = $email ? mask_email($email) : '...';
require_once __DIR__ . '/auth-layout.php';
?>

<h1 class="auth-heading">Verify Your Email</h1>
<p class="auth-sub">
  We sent a 6-digit OTP to<br>
  <span class="masked-email"><?= htmlspecialchars($masked, ENT_QUOTES, 'UTF-8') ?></span>
</p>

<?php if (!empty($_SESSION['dev_otp']) && APP_ENV === 'development'): ?>
  <div class="flash-info" style="margin-bottom:1.25rem;text-align:center">
    <strong>🛠 Dev mode — your OTP is:</strong><br>
    <span style="font-family:'DM Mono',monospace;font-size:1.75rem;letter-spacing:0.4rem;color:var(--lime)"><?= htmlspecialchars($_SESSION['dev_otp'], ENT_QUOTES, 'UTF-8') ?></span><br>
    <small style="color:var(--text-secondary)">This box only appears in development mode</small>
  </div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="flash-error" style="margin-bottom:1.25rem"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($success): ?>
  <div class="flash-success" style="margin-bottom:1.25rem"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="POST" action="">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>" />
  <input type="hidden" name="action"     value="verify" />
  <input type="hidden" name="email"      value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" />

  <div class="form-group">
    <label class="form-label" for="otp">Enter OTP</label>
    <input class="form-input otp-input" type="text" id="otp" name="otp"
           inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
           placeholder="000000" required autocomplete="one-time-code" />
    <div class="form-hint">OTP expires in <?= OTP_EXPIRY_MINUTES ?> minutes</div>
  </div>

  <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:0.5rem">
    Verify Email →
  </button>
</form>

<div class="resend-row">
  Didn't receive it?
  <form method="POST" action="" style="display:inline">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>" />
    <input type="hidden" name="action"     value="resend" />
    <input type="hidden" name="email"      value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" />
    <button type="submit" class="auth-link" style="background:none;border:none;cursor:pointer;font-size:0.85rem;padding:0">Resend OTP</button>
  </form>
</div>

<div class="auth-footer">
  <a href="<?= SITE_URL ?>/auth/login.php" class="auth-link">← Back to Login</a>
</div>

    </div><!-- /.auth-card -->
  </div><!-- /.auth-inner -->
</div><!-- /.auth-wrapper -->
<script src="<?= SITE_URL ?>/assets/js/portal.js"></script>
</body>
</html>
