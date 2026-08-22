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

// ── Validate state (CSRF protection) ────────────────────────────────────────
$state         = $_GET['state'] ?? '';
$stored_state  = $_SESSION['oauth_state'] ?? '';
unset($_SESSION['oauth_state']);

if (!$state || !hash_equals($stored_state, $state)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid OAuth state. Please try again.'];
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit;
}

// ── Handle Google error response ─────────────────────────────────────────────
if (isset($_GET['error'])) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Google login was cancelled.'];
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit;
}

$code = $_GET['code'] ?? '';
if (!$code) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'No authorisation code received from Google.'];
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit;
}

// ── Exchange code for access token ───────────────────────────────────────────
$token_response = google_post('https://oauth2.googleapis.com/token', [
    'code'          => $code,
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
]);

if (!$token_response || empty($token_response['access_token'])) {
    error_log('Google OAuth token exchange failed: ' . json_encode($token_response));
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Google login failed. Please try again.'];
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit;
}

// ── Fetch Google user info ───────────────────────────────────────────────────
$user_info = google_get(
    'https://www.googleapis.com/oauth2/v3/userinfo',
    $token_response['access_token']
);

if (!$user_info || empty($user_info['email'])) {
    error_log('Google OAuth userinfo failed: ' . json_encode($user_info));
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Could not retrieve your Google account details.'];
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit;
}

if (empty($user_info['email_verified'])) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Your Google account email is not verified.'];
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit;
}

$google_id    = $user_info['sub'];
$google_email = strtolower(trim($user_info['email']));
$google_name  = trim($user_info['name'] ?? $user_info['given_name'] ?? $google_email);
$avatar_url   = $user_info['picture'] ?? null;

// ── DB: find or create account ───────────────────────────────────────────────
try {
    $db = get_db();

    // 1. Existing account linked via google_id
    $stmt = $db->prepare("SELECT * FROM users WHERE google_id = :gid AND is_active = 1");
    $stmt->execute([':gid' => $google_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // 2. Existing account with matching email — link it
        $stmt = $db->prepare("SELECT * FROM users WHERE email = :email AND is_active = 1");
        $stmt->execute([':email' => $google_email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Link Google ID to existing account
            $db->prepare("UPDATE users SET google_id = :gid, email_verified = 1, updated_at = NOW() WHERE id = :id")
               ->execute([':gid' => $google_id, ':id' => $user['id']]);
        } else {
            // 3. New user — create account (no password required)
            $db->prepare(
                "INSERT INTO users (full_name, email, password_hash, google_id, role, email_verified, is_active, created_at)
                 VALUES (:name, :email, '', :gid, 'client', 1, 1, NOW())"
            )->execute([
                ':name'  => $google_name,
                ':email' => $google_email,
                ':gid'   => $google_id,
            ]);
            $new_id = (int) $db->lastInsertId();

            // Re-fetch the new user row
            $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
            $stmt->execute([':id' => $new_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$user) {
        throw new \RuntimeException('User record could not be created or retrieved.');
    }

    // ── Set session ──────────────────────────────────────────────────────────
    session_regenerate_id(true);
    $_SESSION['user_id']    = (int) $user['id'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_name']  = $user['full_name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['login_time'] = time();

    // Record session in DB
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $db->prepare(
        "INSERT INTO sessions (user_id, session_token, ip_address, user_agent, is_valid, expires_at)
         VALUES (:uid, :token, :ip, :ua, 1, :exp)"
    )->execute([
        ':uid'   => $user['id'],
        ':token' => $token,
        ':ip'    => $ip,
        ':ua'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ':exp'   => $expires,
    ]);
    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = :id")->execute([':id' => $user['id']]);

    // ── Redirect ─────────────────────────────────────────────────────────────
    $redirect = $_SESSION['oauth_redirect'] ?? '';
    unset($_SESSION['oauth_redirect']);

    if ($redirect && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
        header('Location: ' . SITE_URL . $redirect);
    } elseif ($user['role'] === 'admin') {
        header('Location: ' . SITE_URL . '/admin/dashboard.php');
    } else {
        header('Location: ' . SITE_URL . '/portal/dashboard.php');
    }
    exit;

} catch (\Throwable $e) {
    error_log('Google OAuth DB error: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Something went wrong. Please try again.'];
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function google_post(string $url, array $data): ?array
{
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content' => http_build_query($data),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? json_decode($raw, true) : null;
}

function google_get(string $url, string $access_token): ?array
{
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "Authorization: Bearer $access_token\r\nAccept: application/json\r\n",
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? json_decode($raw, true) : null;
}
