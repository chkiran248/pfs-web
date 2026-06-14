<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/context-builder.php';

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

// Auth check
if (!is_logged_in()) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorised']));
}

// Premium check — PrimoAI requires Active Investor or Prime plan
if (!can_access(get_user_id(), 'primo_ai')) {
    http_response_code(403);
    exit(json_encode([
        'error'           => '🔒 PrimoAI is a premium feature. Invest with Prime Financials to get free access, or enter a coupon code on the pricing page.',
        'upgrade_url'     => SITE_URL . '/portal/pricing.php',
        'onboarding_url'  => ONBOARDING_URL,
    ]));
}

// CSRF check
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verify_csrf($csrf)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Invalid CSRF token']));
}

// Parse JSON body
$input       = json_decode(file_get_contents('php://input'), true) ?? [];
$user_msg    = trim($input['message'] ?? '');
$session_key = preg_replace('/[^a-zA-Z0-9]/', '', $input['session_key'] ?? '');

if ($user_msg === '' || mb_strlen($user_msg) > 2000) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid message length']));
}

$user_id = get_user_id();
$db      = get_db();

// Rate limit: 20 user messages per hour
$rate_stmt = $db->prepare("
    SELECT COUNT(*) FROM primo_conversations
    WHERE user_id = :uid AND role = 'user'
    AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
");
$rate_stmt->execute([':uid' => $user_id]);
if ((int)$rate_stmt->fetchColumn() >= 20) {
    http_response_code(429);
    exit(json_encode(['error' => 'Rate limit reached. Please wait a few minutes.']));
}

// Build system prompt from live DB data (pass session_key for question count)
$system_prompt = build_primo_context($user_id, $session_key);

// Load conversation history
$hist_stmt = $db->prepare("
    SELECT role, message FROM primo_conversations
    WHERE user_id = :uid
    ORDER BY created_at DESC
    LIMIT :lim
");
$hist_stmt->bindValue(':uid', $user_id, PDO::PARAM_INT);
$hist_stmt->bindValue(':lim', PRIMO_HISTORY_LIMIT * 2, PDO::PARAM_INT);
$hist_stmt->execute();
$history = array_reverse($hist_stmt->fetchAll(PDO::FETCH_ASSOC));

// Build messages array
$messages = [];
foreach ($history as $row) {
    $messages[] = ['role' => $row['role'], 'content' => $row['message']];
}
$messages[] = ['role' => 'user', 'content' => $user_msg];

// Save user message immediately
$db->prepare("INSERT INTO primo_conversations (user_id, role, message, session_key) VALUES (:uid,'user',:msg,:sk)")
   ->execute([':uid' => $user_id, ':msg' => $user_msg, ':sk' => $session_key]);

// Standard JSON response (no SSE streaming — works reliably on XAMPP/Windows/shared hosting)
header('Content-Type: application/json');

// Call Claude API (non-streaming — get full response at once)
$api_payload = [
    'model'      => PRIMO_MODEL,
    'max_tokens' => PRIMO_MAX_TOKENS,
    'system'     => $system_prompt,
    'messages'   => $messages,
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($api_payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 60,
]);

$response = curl_exec($ch);
$curl_err  = curl_error($ch);
$full_response = '';
$tokens_used   = 0;
curl_close($ch);

if ($curl_err) {
    exit(json_encode(['error' => 'AI service unavailable. Please try again.']));
}

$data = json_decode($response, true);
if (isset($data['error'])) {
    exit(json_encode(['error' => $data['error']['message'] ?? 'Claude API error.']));
}

$full_response = trim($data['content'][0]['text'] ?? '');
$tokens_used   = $data['usage']['output_tokens'] ?? 0;

if (!$full_response) {
    exit(json_encode(['error' => 'Empty response from AI. Please try again.']));
}

// Re-ping DB after long API call — MySQL may have timed out during Claude response
$db = db_ensure_alive();

// Save assistant response
$db->prepare("INSERT INTO primo_conversations (user_id, role, message, tokens_used, session_key) VALUES (:uid,'assistant',:msg,:tok,:sk)")
   ->execute([':uid' => $user_id, ':msg' => $full_response, ':tok' => $tokens_used, ':sk' => $session_key]);

echo json_encode(['response' => $full_response, 'tokens' => $tokens_used]);
