<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = get_db();
echo '[' . date('H:i:s') . "] Insight drafting starting\n";

// Check we haven't already made 2 drafts today
$today_count = (int)$db->query("SELECT COUNT(*) FROM market_insights WHERE DATE(created_at)=CURDATE() AND is_published=0 AND title LIKE '[AI Draft]%'")->fetchColumn();
if ($today_count >= 2) { echo "Already have $today_count AI drafts today — skipping\n"; exit(0); }

// Get unused news from last 24h
$stmt = $db->prepare("SELECT title, summary, source, category FROM news_items WHERE used_for_insight=0 AND published_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY published_at DESC LIMIT 10");
$stmt->execute();
$news = $stmt->fetchAll();

if (empty($news)) { echo "No new news to process\n"; exit(0); }

$news_text = '';
foreach ($news as $n) {
    $news_text .= "SOURCE: {$n['source']}\nHEADLINE: {$n['title']}\n";
    if ($n['summary']) $news_text .= "SUMMARY: " . mb_substr($n['summary'], 0, 200) . "\n";
    $news_text .= "\n";
}

$system = "You are a financial content writer for Prime Financials, an AMFI registered mutual fund distributor in India (EST. 2016). Write concise, educational market insights (200-250 words) for retail SIP investors. Tone: warm, clear, advisor-like. Rules: no specific stock buy/sell calls, no specific fund scheme picks (categories are fine), include one actionable takeaway. End every insight with: 'Mutual Fund investments are subject to market risks. Please read all scheme-related documents carefully. — Prime Financials'. Return only the insight body text, no title, no JSON.";

$payload = [
    'model'      => PRIMO_MODEL,
    'max_tokens' => 512,
    'system'     => $system,
    'messages'   => [['role' => 'user', 'content' => "Write a market insight for retail MF investors based on these Indian finance headlines:\n\n{$news_text}"]],
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-key: ' . CLAUDE_API_KEY, 'anthropic-version: 2023-06-01'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
]);
$response = curl_exec($ch);
curl_close($ch);

$data    = json_decode((string)$response, true);
$content = trim($data['content'][0]['text'] ?? '');

if (!$content) { echo "Empty Claude response\n"; exit(1); }

$admin_id = (int)($db->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn() ?: 1);
$title    = '[AI Draft] Market Update — ' . date('d M Y');
$slug     = 'ai-draft-' . date('Y-m-d-His');
$excerpt  = mb_substr(strip_tags($content), 0, 150) . '...';

$db->prepare("INSERT INTO market_insights (title,slug,content,excerpt,category,author_id,is_published) VALUES (:t,:s,:c,:e,'market_update',:a,0)")
   ->execute([':t' => $title, ':s' => $slug, ':c' => $content, ':e' => $excerpt, ':a' => $admin_id]);

$db->exec("UPDATE news_items SET used_for_insight=1 WHERE used_for_insight=0 AND published_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");

echo "Draft created: $title\n";
echo "Review and publish at: /admin/insights.php\n";
echo '[' . date('H:i:s') . "] Insight drafting complete\n";
