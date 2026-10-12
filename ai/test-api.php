<?php
declare(strict_types=1);
require_once '../includes/config.php';

// Gate behind cron token so it's not publicly accessible
$token = $_GET['token'] ?? '';
if (!defined('CRON_SECRET') || $token !== CRON_SECRET || CRON_SECRET === '') {
    http_response_code(403); exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

echo "Model: " . CLAUDE_HAIKU_MODEL . "\n";
echo "Key prefix: " . substr(CLAUDE_API_KEY, 0, 20) . "...\n\n";

$payload = [
    'model'      => CLAUDE_HAIKU_MODEL,
    'max_tokens' => 10,
    'messages'   => [['role' => 'user', 'content' => 'Say OK']],
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 30,
]);

$resp = curl_exec($ch);
$err  = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP status: $http\n";
if ($err) echo "cURL error: $err\n";
echo "Response:\n" . $resp . "\n";
