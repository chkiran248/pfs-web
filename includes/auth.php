<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login(): void {
    if (!is_logged_in()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . SITE_URL . '/auth/login.php?redirect=' . $redirect);
        exit;
    }
    // Server-side idle timeout (6 min — client warns at 4, auto-logs at 5)
    $idle_limit = 360;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idle_limit) {
        session_unset();
        session_destroy();
        header('Location: ' . SITE_URL . '/auth/login.php?reason=timeout');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function require_role(string $role): void {
    if (!is_logged_in() || ($_SESSION['user_role'] ?? '') !== $role) {
        header('Location: ' . SITE_URL . '/auth/login.php');
        exit;
    }
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function get_user_id(): int {
    if (empty($_SESSION['user_id'])) {
        throw new RuntimeException('No authenticated user in session.');
    }
    return (int) $_SESSION['user_id'];
}

function get_user_role(): string {
    return (string) ($_SESSION['user_role'] ?? '');
}

function get_user_name(): string {
    return (string) ($_SESSION['user_name'] ?? 'User');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes((int)(CSRF_TOKEN_LENGTH / 2)));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// File-based rate limiting (cross-session, per IP).
// Swap for DB-based implementation before production if needed.
function is_rate_limited(string $ip): bool {
    $file = sys_get_temp_dir() . '/pf_rl_' . md5($ip);
    if (!file_exists($file)) return false;
    $data = @json_decode((string) @file_get_contents($file), true);
    if (!is_array($data)) return false;
    if ((time() - ($data['first'] ?? 0)) > LOCKOUT_MINUTES * 60) {
        @unlink($file);
        return false;
    }
    return ($data['count'] ?? 0) >= MAX_LOGIN_ATTEMPTS;
}

function log_failed_attempt(string $ip): void {
    $file = sys_get_temp_dir() . '/pf_rl_' . md5($ip);
    $data = ['count' => 0, 'first' => time()];
    if (file_exists($file)) {
        $existing = @json_decode((string) @file_get_contents($file), true);
        if (is_array($existing) && (time() - ($existing['first'] ?? 0)) <= LOCKOUT_MINUTES * 60) {
            $data = $existing;
        }
    }
    $data['count']++;
    @file_put_contents($file, json_encode($data), LOCK_EX);
}
