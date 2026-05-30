<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Only allow POST — GET logout is a CSRF vulnerability
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit;
}

if (is_logged_in()) {
    try {
        $uid = get_user_id();
        get_db()->prepare("DELETE FROM sessions WHERE user_id = :uid")
                ->execute([':uid' => $uid]);
    } catch (Throwable $e) {
        error_log('Logout DB error: ' . $e->getMessage());
    }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();

session_start();
$_SESSION['flash'] = ['type' => 'success', 'message' => 'You have been logged out successfully.'];

header('Location: ' . SITE_URL . '/auth/login.php');
exit;
