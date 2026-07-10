<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

if (is_logged_in()) {
    $dest = ($_SESSION['user_role'] ?? '') === 'admin' ? '/admin/dashboard.php' : '/portal/dashboard.php';
    header('Location: ' . SITE_URL . $dest);
    exit;
}

$error    = '';
$email_val = '';

// Session timeout redirect message
$info = '';
if (($_GET['reason'] ?? '') === 'timeout') {
    $info = 'Your session expired after 5 minutes of inactivity. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (is_rate_limited($ip)) {
            $error = 'Too many failed attempts. Please wait ' . LOCKOUT_MINUTES . ' minutes.';
        } else {
            $email    = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
            $password = $_POST['password'] ?? '';
            $email_val = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email or password.';
                log_failed_attempt($ip);
            } else {
                try {
                    $db   = get_db();
                    $stmt = $db->prepare("SELECT * FROM users WHERE email = :email AND is_active = 1");
                    $stmt->execute([':email' => $email]);
                    $user = $stmt->fetch();

                    if (!$user) {
                        log_failed_attempt($ip);
                        $error = 'Invalid email or password.';
                    } elseif (!$user['email_verified']) {
                        $error = 'Please verify your email before logging in. <a href="' . SITE_URL . '/auth/verify-email.php?email=' . urlencode($email) . '" class="auth-link">Resend OTP →</a>';
                    } elseif (!password_verify($password, $user['password_hash'])) {
                        log_failed_attempt($ip);
                        $error = 'Invalid email or password.';
                    } else {
                        // ── Successful login ──
                        session_regenerate_id(true);

                        $_SESSION['user_id']    = (int) $user['id'];
                        $_SESSION['user_role']  = $user['role'];
                        $_SESSION['user_name']  = $user['full_name'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['login_time'] = time();

                        // Record session in DB
                        $token   = bin2hex(random_bytes(32));
                        $expires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
                        $stmt = $db->prepare(
                            "INSERT INTO sessions (user_id, session_token, ip_address, user_agent, is_valid, expires_at)
                             VALUES (:uid, :token, :ip, :ua, 1, :exp)"
                        );
                        $stmt->execute([
                            ':uid'   => $user['id'],
                            ':token' => $token,
                            ':ip'    => $ip,
                            ':ua'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                            ':exp'   => $expires,
                        ]);

                        // Update last login timestamp
                        $db->prepare("UPDATE users SET last_login = NOW() WHERE id = :id")
                           ->execute([':id' => $user['id']]);

                        // Redirect
                        $redirect = trim($_POST['redirect'] ?? $_GET['redirect'] ?? '');
                        if ($redirect && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
                            header('Location: ' . SITE_URL . $redirect);
                        } elseif ($user['role'] === 'admin') {
                            header('Location: ' . SITE_URL . '/admin/dashboard.php');
                        } else {
                            header('Location: ' . SITE_URL . '/portal/dashboard.php');
                        }
                        exit;
                    }
                } catch (PDOException $e) {
                    error_log('Login error: ' . $e->getMessage());
                    $error = 'Something went wrong. Please try again.';
                }
            }
        }
    }
}

$page_title = 'Login — Prime Financials';
$redirect   = htmlspecialchars($_GET['redirect'] ?? '', ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/auth-layout.php';
?>

<h1 class="auth-heading">Welcome Back</h1>
<p class="auth-sub">Login to your Prime Financials portal</p>

<?php if ($info): ?>
  <div style="margin-bottom:1.25rem;background:rgba(201,168,76,0.08);border:1px solid rgba(201,168,76,0.25);border-radius:8px;padding:0.75rem 1rem;font-size:0.82rem;color:var(--gold);display:flex;align-items:center;gap:0.5rem">
    <i class="bi bi-shield-lock"></i> <?= htmlspecialchars($info, ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="flash-error" style="margin-bottom:1.25rem">
    <?= $error ?>
  </div>
<?php endif; ?>

<form method="POST" action="<?= SITE_URL ?>/auth/login.php" novalidate>
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>" />
  <input type="hidden" name="redirect"   value="<?= $redirect ?>" />

  <div class="form-group">
    <label class="form-label" for="email">Email Address</label>
    <input class="form-input" type="email" id="email" name="email"
           value="<?= $email_val ?>" placeholder="you@example.com"
           required autocomplete="email" />
  </div>

  <div class="form-group">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.4rem">
      <label class="form-label" for="password" style="margin-bottom:0">Password</label>
      <a href="<?= SITE_URL ?>/auth/forgot-password.php" class="auth-link" style="font-size:0.8rem">Forgot password?</a>
    </div>
    <div class="password-field">
      <input class="form-input" type="password" id="password" name="password"
             placeholder="••••••••" required autocomplete="current-password" />
      <button type="button" class="password-toggle" onclick="togglePassword('password', this)" title="Show/hide password">👁</button>
    </div>
  </div>

  <div class="check-row" style="margin-bottom:1.5rem">
    <input type="checkbox" id="remember" name="remember" />
    <label for="remember">Remember me on this device</label>
  </div>

  <button type="submit" class="btn-primary" style="width:100%;justify-content:center">
    Login to Portal →
  </button>
</form>

<div class="auth-footer">
  Don't have an account? <a href="<?= SITE_URL ?>/auth/register.php" class="auth-link">Create one</a>
</div>

    </div><!-- /.auth-card -->
  </div><!-- /.auth-inner -->
</div><!-- /.auth-wrapper -->

<script src="<?= SITE_URL ?>/assets/js/portal.js"></script>
<script>
function togglePassword(fieldId, btn) {
  var field = document.getElementById(fieldId);
  if (field.type === 'password') {
    field.type = 'text';
    btn.textContent = '🙈';
  } else {
    field.type = 'password';
    btn.textContent = '👁';
  }
}
</script>
</body>
</html>
