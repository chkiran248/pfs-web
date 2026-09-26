<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

set_time_limit(120);
header('Content-Type: application/json');

// ── Method guard ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Auth guard ───────────────────────────────────────────────────────────────
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── CSRF guard (header-based for AJAX/fetch) ─────────────────────────────────
$csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verify_csrf($csrf_header)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

// ── Parse JSON body ──────────────────────────────────────────────────────────
$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body) || !isset($body['funds']) || !is_array($body['funds'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body. Expected {"funds": [...]}']);
    exit;
}

$funds = $body['funds'];

// ── Max 25 funds per request ─────────────────────────────────────────────────
if (count($funds) > 25) {
    http_response_code(400);
    echo json_encode(['error' => 'Too many funds. Maximum 25 per request.']);
    exit;
}

// ── VRO scraping functions ───────────────────────────────────────────────────

/**
 * Parse a star rating (1–5) from a VRO HTML page.
 * Returns null if no rating is found.
 */
function vro_parse_stars(string $html): ?int
{
    $patterns = [
        '/["\s]rating["\s]*[:=]\s*["\']?(\d)["\']?/i',        // JSON-like: "rating": 4
        '/data-rating=["\'](\d)["\']/',                          // data attr
        '/(?:star|rating)-(\d)(?:["\s]|$)/i',                   // CSS class star-4
        '/title=["\'](\d)\s*(?:star|Star)/i',                   // title attr
        '/aria-label=["\'](\d)\s*(?:star|Star)/i',              // aria-label
        '/(\d)\s*stars?\s*(?:out of|\/)\s*5/i',                 // "4 stars out of 5"
        '/class=["\'][^"\']*(?:vr-star|star-rating)[^"\']*["\'][^>]*>.*?(\d)/is', // star container
    ];

    foreach ($patterns as $p) {
        if (preg_match($p, $html, $m)) {
            $n = (int) $m[1];
            if ($n >= 1 && $n <= 5) {
                return $n;
            }
        }
    }

    return null;
}

/**
 * Fetch the VRO star rating for a fund by name.
 * Tries two VRO search endpoints, follows fund detail links, and falls back
 * to parsing the search results page directly.
 */
function vro_fetch_rating(string $fund_name): ?int
{
    $ctx = stream_context_create(['http' => [
        'timeout'       => 12,
        'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'ignore_errors' => true,
        'header'        =>
            "Accept: text/html,application/xhtml+xml\r\n" .
            "Accept-Language: en-IN,en;q=0.9\r\n" .
            "Referer: https://www.valueresearchonline.com/\r\n",
    ]]);

    $q = urlencode($fund_name);

    $search_urls = [
        "https://www.valueresearchonline.com/funds/quicksearch/?q={$q}",
        "https://www.valueresearchonline.com/funds/findafund.asp?keyword={$q}",
    ];

    foreach ($search_urls as $url) {
        $html = @file_get_contents($url, false, $ctx);
        if (!$html || strlen($html) < 200) {
            continue;
        }

        // Try to find a VRO fund detail URL in the search results
        if (preg_match(
            '/(?:href|url)=["\']([^"\']*(?:newsnapshot|fund-detail)[^"\']*schemecode[=\/](\d+)[^"\']*)["\']/',
            $html,
            $m
        )) {
            $detail_path = $m[1];
            $detail_url  = str_starts_with($detail_path, 'http')
                ? $detail_path
                : 'https://www.valueresearchonline.com' . $detail_path;

            $fhtml = @file_get_contents($detail_url, false, $ctx);
            if ($fhtml) {
                $stars = vro_parse_stars($fhtml);
                if ($stars !== null) {
                    return $stars;
                }
            }
        }

        // Try to parse stars directly from the search results page
        $stars = vro_parse_stars($html);
        if ($stars !== null) {
            return $stars;
        }
    }

    return null;
}

// ── Main caching + fetch loop ────────────────────────────────────────────────
$db      = get_db();
$ratings = [];

foreach ($funds as $fund) {
    if (!is_array($fund)) {
        continue;
    }

    $name = trim((string) ($fund['name'] ?? ''));
    $sc   = trim((string) ($fund['scheme_code'] ?? ''));

    if ($name === '') {
        continue;
    }

    // Check 7-day cache by fund_name
    $cache_stmt = $db->prepare(
        "SELECT vro_stars
         FROM fund_ratings
         WHERE fund_name = ?
           AND fetched_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $cache_stmt->execute([$name]);
    $cached = $cache_stmt->fetchColumn();

    if ($cached !== false) {
        // Cache hit — $cached may be null (no rating found) or a numeric string
        $ratings[$name] = ($cached !== null) ? (int) $cached : null;
        continue;
    }

    // Cache miss — fetch from VRO
    $stars = vro_fetch_rating($name);

    // Upsert into fund_ratings
    try {
        $db->prepare(
            "INSERT INTO fund_ratings (fund_name, scheme_code, vro_stars, fetched_at)
             VALUES (:fn, :sc, :stars, NOW())
             ON DUPLICATE KEY UPDATE
                 scheme_code = VALUES(scheme_code),
                 vro_stars   = VALUES(vro_stars),
                 fetched_at  = NOW()"
        )->execute([
            ':fn'    => $name,
            ':sc'    => ($sc !== '') ? $sc : null,
            ':stars' => $stars,
        ]);
    } catch (PDOException $e) {
        error_log('fetch-fund-ratings DB error: ' . $e->getMessage());
        // Non-fatal — still return the scraped value
    }

    $ratings[$name] = $stars;

    // Be polite to VRO between requests
    sleep(1);
}

echo json_encode(['ratings' => $ratings]);
