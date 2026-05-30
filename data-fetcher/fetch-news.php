<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = get_db();
echo '[' . date('H:i:s') . "] News fetch starting\n";

$feeds = [
    ['url' => 'https://economictimes.indiatimes.com/markets/rssfeeds/1977021501.cms',   'source' => 'ET Markets',   'category' => 'market_update'],
    ['url' => 'https://www.moneycontrol.com/rss/MCtopnews.xml',                          'source' => 'Moneycontrol', 'category' => 'market_update'],
    ['url' => 'https://www.livemint.com/rss/markets',                                    'source' => 'Mint',         'category' => 'market_update'],
    ['url' => 'https://www.rbi.org.in/scripts/RSS.aspx',                                 'source' => 'RBI',          'category' => 'general'],
];

$keywords = ['mutual fund','sip','market','nifty','sensex','rbi','sebi','inflation','gdp',
             'interest rate','equity','debt','emi','tax','budget','mf','portfolio','invest'];

$stmt  = $db->prepare("INSERT IGNORE INTO news_items (source,title,url,summary,category,published_at) VALUES (:src,:title,:url,:summary,:cat,:pub)");
$total = 0;

$context = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'PrimeFinancials NewsBot/1.0']]);

foreach ($feeds as $feed) {
    $xml_raw = @file_get_contents($feed['url'], false, $context);
    if (!$xml_raw) { echo "Skipped: {$feed['source']}\n"; continue; }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_raw);
    if (!$xml) { echo "Invalid XML: {$feed['source']}\n"; continue; }

    $items = $xml->channel->item ?? $xml->entry ?? [];
    foreach ($items as $item) {
        $title   = trim(strip_tags((string)($item->title ?? '')));
        $url     = trim((string)($item->link ?? $item->id ?? ''));
        $summary = trim(strip_tags((string)($item->description ?? $item->summary ?? '')));
        $pub_raw = trim((string)($item->pubDate ?? $item->published ?? ''));
        if (!$title) continue;

        $pub_date = null;
        if ($pub_raw) { $ts = @strtotime($pub_raw); if ($ts) $pub_date = date('Y-m-d H:i:s', $ts); }
        if ($pub_date && strtotime($pub_date) < strtotime('-7 days')) continue;

        $combined = strtolower($title . ' ' . $summary);
        $relevant = false;
        foreach ($keywords as $kw) { if (str_contains($combined, $kw)) { $relevant = true; break; } }
        if (!$relevant) continue;

        $stmt->execute([':src' => $feed['source'], ':title' => mb_substr($title, 0, 300), ':url' => mb_substr($url, 0, 500), ':summary' => mb_substr($summary, 0, 1000), ':cat' => $feed['category'], ':pub' => $pub_date]);
        $total++;
    }
    echo "Fetched from {$feed['source']}\n";
    sleep(2);
}

echo "Stored $total news items\n";
echo '[' . date('H:i:s') . "] News fetch complete\n";
