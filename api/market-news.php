<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorised']));
}

header('Content-Type: application/json');

$limit = min(20, max(5, (int) ($_GET['limit'] ?? 10)));

$url = MFAPIS_BASE_URL . '/market/news/latest?' . http_build_query(['limit' => $limit]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . MFAPIS_API_KEY,
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 12,
]);
$resp     = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false || $httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['error' => 'unavailable', 'news' => []]);
    exit;
}

$data = json_decode((string) $resp, true);
if (!is_array($data)) {
    echo json_encode(['error' => 'parse_error', 'news' => []]);
    exit;
}

// Normalise envelope — API may use data[], news[], results[], or a plain array
$raw = $data['data'] ?? $data['news'] ?? $data['results'] ?? (isset($data[0]) ? $data : []);

// Normalise each item to a consistent shape
$news = [];
foreach ((array) $raw as $item) {
    if (!is_array($item)) continue;
    $news[] = [
        'title'        => (string) ($item['title']        ?? $item['headline']    ?? ''),
        'url'          => (string) ($item['url']          ?? $item['link']        ?? '#'),
        'source'       => (string) ($item['source']       ?? $item['publisher']   ?? ''),
        'published_at' => (string) ($item['published_at'] ?? $item['date']        ?? $item['publishedAt'] ?? ''),
        'summary'      => (string) ($item['summary']      ?? $item['description'] ?? $item['snippet']    ?? ''),
    ];
}

echo json_encode(['news' => array_values($news)]);
