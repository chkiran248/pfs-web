<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (is_logged_in()) {
    $dest = ($_SESSION['user_role'] ?? '') === 'admin' ? '/admin/dashboard.php' : '/portal/dashboard.php';
    header('Location: ' . SITE_URL . $dest);
    exit;
}

// Store intended redirect and CSRF state
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state']    = $state;
$_SESSION['oauth_redirect'] = trim($_GET['redirect'] ?? '');

$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'access_type'   => 'online',
    'prompt'        => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
