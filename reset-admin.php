<?php
// ONE-TIME ADMIN RESET SCRIPT — DELETE IMMEDIATELY AFTER USE
declare(strict_types=1);

$secret = $_GET['k'] ?? '';
if ($secret !== 'primefin2026reset') {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$action  = $_POST['action'] ?? '';
$message = '';

if ($action === 'reset') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$email || !$password || strlen($password) < 8) {
        $message = '<span style="color:red">Email and password (min 8 chars) required.</span>';
    } else {
        try {
            $db   = get_db();
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            // Check if user exists
            $stmt = $db->prepare('SELECT id, role FROM users WHERE email = :email');
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $db->prepare('UPDATE users SET password_hash = :hash WHERE email = :email')
                   ->execute([':hash' => $hash, ':email' => $email]);
                $message = '<span style="color:green">Password updated for ' . htmlspecialchars($email) . ' (role: ' . $user['role'] . '). DELETE THIS FILE NOW.</span>';
            } else {
                // Create admin user
                $db->prepare("INSERT INTO users (email, password_hash, full_name, role, is_active, created_at) VALUES (:email, :hash, 'Prime Financials Admin', 'admin', 1, NOW())")
                   ->execute([':email' => $email, ':hash' => $hash]);
                $message = '<span style="color:green">Admin user created: ' . htmlspecialchars($email) . '. DELETE THIS FILE NOW.</span>';
            }
        } catch (PDOException $e) {
            $message = '<span style="color:red">DB error: ' . htmlspecialchars($e->getMessage()) . '</span>';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>Admin Reset</title>
<style>body{font-family:sans-serif;max-width:420px;margin:4rem auto;padding:1rem}
input{display:block;width:100%;padding:0.5rem;margin:0.5rem 0 1rem;box-sizing:border-box}
button{padding:0.6rem 1.5rem;background:#2E8540;color:#fff;border:none;cursor:pointer}</style>
</head>
<body>
<h2>Admin Password Reset</h2>
<p style="color:red;font-weight:bold">DELETE THIS FILE IMMEDIATELY AFTER USE</p>
<?php if ($message): ?><p><?= $message ?></p><?php endif; ?>
<form method="POST" action="?k=<?= htmlspecialchars($secret) ?>">
  <input type="hidden" name="action" value="reset">
  <label>Email</label>
  <input type="email" name="email" value="support@primefin.in">
  <label>New Password</label>
  <input type="password" name="password" placeholder="Min 8 chars">
  <button type="submit">Reset Password</button>
</form>
</body>
</html>
