<?php
declare(strict_types=1);
require_once '../includes/config.php';
$token = $_GET['token'] ?? '';
if (!defined('CRON_SECRET') || $token !== CRON_SECRET || CRON_SECRET === '') {
    http_response_code(403); exit('Forbidden');
}
header('Content-Type: text/plain; charset=utf-8');

echo "Key prefix: " . substr(CLAUDE_API_KEY, 0, 24) . "...\n\n";

$models = [
    'claude-haiku-4-5-20251001',
    'claude-3-5-haiku-20241022',
    'claude-3-haiku-20240307',
];

foreach ($models as $model) {
    echo "Testing: $model\n";
    $payload = ['model' => $model, 'max_tokens' => 10, 'messages' => [['role' => 'user', 'content' => 'Say OK']]];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-api-key: '.CLAUDE_API_KEY,'anthropic-version: 2023-06-01'], CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_CONNECTTIMEOUT=>15, CURLOPT_TIMEOUT=>30]);
    $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode($resp, true);
    $status = isset($data['content'][0]['text'])
        ? 'OK — "' . $data['content'][0]['text'] . '"'
        : 'FAIL — HTTP '.$http.' — '.($data['error']['message'] ?? $resp);
    echo "  $status\n\n";
}
