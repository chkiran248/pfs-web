<?php
// contact.php — Prime Financials Contact Form Handler

session_start();

$to_email   = "support@primefin.in";
$from_name  = "Prime Financials Website";
$from_email = "noreply@primefin.in";
$subject    = "New Discovery Call Request — Prime Financials";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// ── CSRF validation ───────────────────────────────────────
$submitted_token = $_POST['csrf_token'] ?? '';
$session_token   = $_SESSION['contact_csrf'] ?? '';
if (empty($session_token) || !hash_equals($session_token, $submitted_token)) {
    header('Location: index.php?error=csrf');
    exit;
}
unset($_SESSION['contact_csrf']); // single-use token

// ── Rate limiting: max 1 submission per 60 seconds ────────
$now = time();
if (isset($_SESSION['contact_last_submit']) && ($now - $_SESSION['contact_last_submit']) < 60) {
    header('Location: index.php?error=rate');
    exit;
}
$_SESSION['contact_last_submit'] = $now;

// ── Sanitize + strip newlines (prevents header injection) ─
function sanitize(string $val): string {
    $val = trim($val);
    $val = strip_tags($val);
    $val = str_replace(["\r", "\n", "%0a", "%0d", "\r\n"], ' ', $val); // header injection prevention
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

function validate_phone(string $phone): bool {
    return (bool) preg_match('/^[\d\s\+\-\(\)]{7,20}$/', $phone);
}

$name     = sanitize($_POST['name']     ?? '');
$phone    = sanitize($_POST['phone']    ?? '');
$interest = sanitize($_POST['interest'] ?? '');
$message  = sanitize($_POST['message']  ?? '');

// ── Validation ────────────────────────────────────────────
if (empty($name) || strlen($name) < 2) {
    header('Location: index.php?error=1');
    exit;
}

if (empty($phone) || !validate_phone(str_replace(['(',')',' ','-','+'], '', $_POST['phone'] ?? ''))) {
    header('Location: index.php?error=1');
    exit;
}

// ── Build email body ──────────────────────────────────────
$body = "
New Discovery Call Request — Prime Financials
========================================
Name     : {$name}
Phone    : {$phone}
Interest : {$interest}
Message  : {$message}
----------------------------------------
Submitted : " . date('d M Y, h:i A') . "
Source    : Prime Financials / primefin.in
";

$headers  = "From: {$from_name} <{$from_email}>\r\n";
$headers .= "Reply-To: {$from_email}\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

$sent = mail($to_email, $subject, $body, $headers);
header($sent ? 'Location: thank-you.php' : 'Location: index.php?error=mail');
exit;
?>
