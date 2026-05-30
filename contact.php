<?php
// contact.php — Prime Financials Contact Form Handler

$to_email   = "support@primefin.in";
$from_name  = "Prime Financials Website";
$from_email = "noreply@primefin.in";
$subject    = "New Discovery Call Request — Prime Financials";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

function sanitize($val) {
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}

$name     = sanitize($_POST['name']     ?? '');
$phone    = sanitize($_POST['phone']    ?? '');
$interest = sanitize($_POST['interest'] ?? '');
$message  = sanitize($_POST['message']  ?? '');

if (empty($name) || empty($phone)) {
    header('Location: index.php?error=1');
    exit;
}

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
