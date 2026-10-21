<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

/**
 * Main entry point — detects document type and extracts holdings via Claude API.
 */
function parse_financial_document(
    string $file_path,
    string $file_name,
    int    $user_id,
    int    $document_id
): array {
    $db = get_db();

    $db->prepare("UPDATE document_queue SET status='processing' WHERE document_id=:did AND user_id=:uid")
       ->execute([':did' => $document_id, ':uid' => $user_id]);

    try {
        $raw_text = extract_pdf_text_smart($file_path);
        $doc_type = detect_document_type($raw_text, $file_name);
        $holdings = extract_holdings_batched($raw_text, $doc_type, $file_path);

        $db->prepare("UPDATE document_queue SET extracted_data=:data, status='pending_confirm' WHERE document_id=:did")
           ->execute([':data' => json_encode($holdings), ':did' => $document_id]);

        return [
            'success'     => true,
            'holdings'    => $holdings,
            'doc_type'    => $doc_type,
            'count'       => count($holdings),
            'document_id' => $document_id,
            'message'     => 'Found ' . count($holdings) . ' holding(s) in ' . format_doc_type($doc_type) . '. Review before adding.',
        ];
    } catch (Exception $e) {
        error_log("Document parser error user_id={$user_id}: " . $e->getMessage());
        $db->prepare("UPDATE document_queue SET status='failed', error_message=:err WHERE document_id=:did")
           ->execute([':err' => $e->getMessage(), ':did' => $document_id]);
        return ['success' => false, 'message' => 'Could not extract holdings. ' . $e->getMessage()];
    }
}

function format_doc_type(string $t): string {
    return match($t) {
        'CAS_MUTUAL_FUND'      => 'CAS Statement',
        'PORTFOLIO_VALUATION'  => 'Portfolio Valuation Report',
        'BROKER_STATEMENT'     => 'Broker Statement',
        'DEMAT_STATEMENT'      => 'Demat Statement',
        'FD_CERTIFICATE'       => 'FD Certificate',
        'INSURANCE_POLICY'     => 'Insurance Policy',
        default                => 'Financial Document',
    };
}

/**
 * Try Ghostscript's text device (pdftotext is not installed on this host),
 * fall back to empty string so Claude reads the PDF directly via base64.
 * Text extraction is far faster than vision processing — using it whenever
 * possible avoids the slow vision path timing out on longer documents.
 * Limits to first 10 pages to keep payload manageable.
 */
function extract_pdf_text_smart(string $file_path): string {
    if (!function_exists('shell_exec') || !function_exists('exec') || str_contains(PHP_OS, 'WIN')) {
        return '';
    }

    $gs = trim((string)(shell_exec('which gs 2>/dev/null') ?? ''));
    if (!$gs) return '';

    $tmp = sys_get_temp_dir() . '/' . bin2hex(random_bytes(8)) . '_pdftext.txt';
    $cmd = escapeshellarg($gs) . ' -dBATCH -dNOPAUSE -q -dFirstPage=1 -dLastPage=10'
         . ' -sDEVICE=txtwrite -sOutputFile=' . escapeshellarg($tmp)
         . ' ' . escapeshellarg($file_path) . ' 2>/dev/null';
    exec($cmd);

    $text = file_exists($tmp) ? (string)file_get_contents($tmp) : '';
    @unlink($tmp);

    return strlen(trim($text)) > 50 ? $text : ''; // empty triggers Claude vision fallback
}

function detect_document_type(string $text, string $filename): string {
    $lower = strtolower($text . ' ' . $filename);
    if (str_contains($lower, 'consolidated account statement') || str_contains($lower, 'cams') || str_contains($lower, 'kfintech') || str_contains($lower, 'karvy')) return 'CAS_MUTUAL_FUND';
    if (str_contains($lower, 'portfolio valuation') || str_contains($lower, 'valuation report') || str_contains($lower, 'valueplus') || str_contains($lower, 'assetplus') || str_contains($lower, 'portfolio statement')) return 'PORTFOLIO_VALUATION';
    if (str_contains($lower, 'zerodha') || str_contains($lower, 'groww') || str_contains($lower, 'upstox') || str_contains($lower, 'angel')) return 'BROKER_STATEMENT';
    if (str_contains($lower, 'nsdl') || str_contains($lower, 'cdsl') || str_contains($lower, 'demat')) return 'DEMAT_STATEMENT';
    if (str_contains($lower, 'fixed deposit') || str_contains($lower, 'fd certificate') || str_contains($lower, 'term deposit')) return 'FD_CERTIFICATE';
    if (str_contains($lower, 'insurance') || str_contains($lower, 'policy') || str_contains($lower, 'premium')) return 'INSURANCE_POLICY';
    return 'GENERAL_FINANCIAL';
}

function build_extraction_prompt(string $text, string $doc_type, string $file_path): array {
    $has_text = strlen(trim($text)) > 100;

    $instructions = match($doc_type) {
        'CAS_MUTUAL_FUND' => "Extract ALL mutual fund holdings from this CAMS/KFintech Consolidated Account Statement.
For each holding return: fund_name, fund_house, fund_type (equity/debt/hybrid/elss/index/international/liquid/gold), folio_number, units_held (decimal), avg_nav, current_nav, invested_amount, current_value, purchase_date (YYYY-MM-DD), sip_active (true/false), sip_amount (monthly).
For purchase_date: many schemes show multiple SIP/lumpsum transaction dates rather than one purchase date — if so, use the EARLIEST transaction date for that folio+scheme. If no transaction date is visible at all, return null. NEVER use today's date, the statement generation date, or the statement period end date as a substitute — an incorrect recent date is worse than no date.",

        'PORTFOLIO_VALUATION' => "Extract ALL mutual fund holdings from this Portfolio Valuation Report (Valueplus/AssetPlus or similar).
Each fund row typically shows: Scheme Name, Scheme Type/Category, Market Value (current value), Investment Value or Cost Value (amount invested), Units, NAV or Current NAV, and sometimes XIRR/Returns.
For each fund return: fund_name (full scheme name), fund_house (AMC name — extract from scheme name e.g. 'Mirae Asset' from 'Mirae Asset Large Cap Fund'), fund_type (equity/debt/hybrid/elss/index/international/liquid/gold — infer from scheme name or category column), units_held (units, decimal), current_nav (NAV value), invested_amount (Investment Value / Cost Value), current_value (Market Value), purchase_date (null — not typically shown in valuation reports), folio_number (if visible).
IMPORTANT: Extract EVERY single fund row. Reports can have 30-50+ funds. Do not stop early.",

        'DEMAT_STATEMENT' => "Extract ALL holdings from this NSDL/CDSL Demat Account Statement. NSDL statements show: ISIN, Company/Issuer Name, Market Type, Quantity, Current Market Value. For each holding return: fund_name (full company name), fund_house ('NSE' or 'BSE' or 'NSDL'), fund_type ('equity' for shares, 'debt' for bonds/debentures), units_held (quantity as number), avg_nav (0 if not shown), current_nav (0 if not shown), invested_amount (0 if not shown), current_value (current market value — extract this), purchase_date (null if not shown), ticker_symbol (NSE/BSE symbol if shown). Extract EVERY row even if some fields are missing.",

        'BROKER_STATEMENT' => "Extract ALL stock holdings. For each: fund_name (company name), fund_house (exchange NSE/BSE), fund_type='equity', units_held (shares), avg_nav (avg buy price), current_nav (current price if shown), invested_amount, current_value, purchase_date (YYYY-MM-DD), ticker_symbol.",

        'FD_CERTIFICATE' => "Extract Fixed Deposit details. For each: fund_name (bank + 'FD'), fund_house (bank name), fund_type='fd', invested_amount (principal), interest_rate (number, e.g. 7.5), purchase_date (YYYY-MM-DD), maturity_date (YYYY-MM-DD), current_value (maturity amount if shown).",

        'INSURANCE_POLICY' => "Extract insurance policy. For each: fund_name (policy type), fund_house (insurer), fund_type='other', invested_amount (annual premium), current_value (sum assured), purchase_date (YYYY-MM-DD), maturity_date (expiry YYYY-MM-DD).",

        default => "Extract any financial holdings or investments. Include: fund_name, fund_type, invested_amount, current_value, purchase_date.",
    };

    $system = "You are a financial data extraction specialist for Indian financial documents.
Your response MUST start with [ and end with ] — a valid JSON array of holding objects.
No explanation before or after. No markdown fences. No preamble.
If nothing found, return exactly: []
All monetary values: plain numbers without ₹ or commas (e.g. 150000 not 1,50,000).
All dates: YYYY-MM-DD format. Percentages: plain numbers (7.5 not 7.5%).";

    // Larger limit for multi-holding reports to capture all funds
    $text_limit = in_array($doc_type, ['PORTFOLIO_VALUATION', 'DEMAT_STATEMENT', 'CAS_MUTUAL_FUND']) ? 25000 : 12000;

    $user_content = [];
    if ($has_text) {
        $user_content[] = ['type' => 'text', 'text' => $instructions . "\n\nDocument text:\n" . mb_substr($text, 0, $text_limit)];
    } else {
        // Guard: refuse to base64-encode files > 3 MB — they will time out the API call
        $file_size = filesize($file_path);
        if ($file_size > 3 * 1024 * 1024) {
            throw new Exception("This PDF is too large to process automatically ({$file_size} bytes). Please upload a text-based PDF (not a scanned image), or contact support for help importing your holdings.");
        }
        // Send PDF as base64 for Claude vision
        $pdf_b64 = base64_encode((string)file_get_contents($file_path));
        $user_content[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $pdf_b64]];
        $user_content[] = ['type' => 'text', 'text' => $instructions];
    }

    $complex_types = ['CAS_MUTUAL_FUND', 'PORTFOLIO_VALUATION', 'DEMAT_STATEMENT'];
    return [
        'system'        => $system,
        'user_content'  => $user_content,
        'doc_type_hint' => in_array($doc_type, $complex_types),
    ];
}

/**
 * Robustly extract a JSON array from Claude's response.
 * Handles: pure JSON, markdown-fenced, JSON inside prose, object with 'holdings' key.
 */
function extract_json_array(string $text): ?array {
    if (trim($text) === '') return [];

    // Strip markdown fences
    $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $text = preg_replace('/^```\s*$/m', '', $text);
    $text = trim($text);

    // 1. Direct parse
    $d = json_decode($text, true);
    if (is_array($d)) return $d;

    // 2. Find the outermost JSON array in the text
    $depth = 0; $start = -1; $inStr = false; $esc = false;
    for ($i = 0; $i < strlen($text); $i++) {
        $c = $text[$i];
        if ($esc) { $esc = false; continue; }
        if ($c === '\\' && $inStr) { $esc = true; continue; }
        if ($c === '"') { $inStr = !$inStr; continue; }
        if ($inStr) continue;
        if ($c === '[') { if ($depth === 0) $start = $i; $depth++; }
        elseif ($c === ']') { $depth--; if ($depth === 0 && $start >= 0) {
            $candidate = substr($text, $start, $i - $start + 1);
            $parsed = json_decode($candidate, true);
            if (is_array($parsed)) return $parsed;
            $start = -1;
        }}
    }

    // 3. Object with 'holdings' key
    if (preg_match('/\{[\s\S]+\}/s', $text, $m)) {
        $obj = json_decode($m[0], true);
        if (isset($obj['holdings']) && is_array($obj['holdings'])) return $obj['holdings'];
        if (isset($obj['data'])     && is_array($obj['data']))     return $obj['data'];
    }

    // 4. No holdings in document
    if (preg_match('/no holdings|no data|not found|unable to extract|cannot extract/i', $text)) return [];

    return null; // genuine parse failure
}

/**
 * Splits large text-based statements into chunks so no single Claude call's
 * output takes long enough to risk the hosting's hard ~60s process timeout
 * (enforced by PHP-FPM itself — not overridable via set_time_limit()), then
 * runs every chunk CONCURRENTLY via curl_multi. Running them in parallel
 * matters because even fast individual calls would otherwise add up past
 * the 60s ceiling if run sequentially once there are more than a few chunks.
 * Short documents and the vision (no-text) path are unaffected — they just
 * become a single-item "batch".
 */
function extract_holdings_batched(string $text, string $doc_type, string $file_path): array {
    $complex_types = ['CAS_MUTUAL_FUND', 'PORTFOLIO_VALUATION', 'DEMAT_STATEMENT'];
    $has_text      = strlen(trim($text)) > 100;
    $chunk_size    = 12000; // fewer boundaries = fewer chances to lose a holding at a cut

    $chunks = ($has_text && in_array($doc_type, $complex_types) && strlen($text) > $chunk_size)
        ? chunk_text_on_lines($text, $chunk_size)
        : [$text];

    if (count($chunks) > 1) {
        error_log("extract_holdings_batched: split into " . count($chunks) . " chunk(s) of ~{$chunk_size} chars");
    }

    $prompts = array_map(fn($chunk) => build_extraction_prompt($chunk, $doc_type, $file_path), $chunks);
    $results = call_claude_extraction_parallel($prompts);

    $all_holdings = [];
    $any_succeeded = false;
    foreach ($results as $i => $r) {
        if ($r['error'] !== null) {
            error_log("extract_holdings_batched: chunk " . ($i + 1) . "/" . count($chunks) . " failed — " . $r['error']);
            continue;
        }
        $any_succeeded = true;
        $all_holdings = array_merge($all_holdings, $r['holdings']);
    }

    if (!$any_succeeded) {
        // Surface the specific reason (e.g. password-protected) rather than
        // a generic message, so scan-document.php's password detection and
        // the user-facing error both stay accurate.
        throw new Exception($results[0]['error'] ?? 'Could not extract holdings from this document.');
    }

    return validate_extracted_holdings($all_holdings, $doc_type);
}

/**
 * Split text into ~$max_len chunks. Prefers cutting at a blank line within a
 * bounded lookahead window past the target size, since blank lines typically
 * separate distinct records (one fund's entry from the next) in PDF-extracted
 * text -- cutting there instead of at an arbitrary line reduces the chance of
 * slicing a single multi-line holding's record in half, which was observed
 * to silently drop real holdings from a scan's results (the AI can't
 * recognise a fragment of a record as a complete one, so it just omits it).
 * Falls back to cutting immediately at the line boundary if no blank line
 * appears within the lookahead window, to avoid unbounded chunk growth.
 */
function chunk_text_on_lines(string $text, int $max_len, int $lookahead = 3000): array {
    $lines  = explode("\n", $text);
    $chunks = [];
    $buf    = '';
    $i      = 0;
    $n      = count($lines);

    while ($i < $n) {
        $line = $lines[$i];
        $buf .= ($buf === '' ? '' : "\n") . $line;
        $i++;

        if (strlen($buf) <= $max_len) continue;

        // Over target size -- look ahead for the nearest blank line to cut at.
        // Either way, every line consumed during the lookahead (whether a
        // blank line was found or not) must end up in $extended -- $i has
        // already moved past them, so using the original $buf here would
        // silently drop those lines from every chunk entirely.
        $extended = $buf;
        $lookahead_used = 0;
        while ($i < $n && $lookahead_used < $lookahead) {
            $next = $lines[$i];
            if (trim($next) === '') break; // natural break — stop extending, cut here
            $extended .= "\n" . $next;
            $lookahead_used += strlen($next) + 1;
            $i++;
        }
        $chunks[] = $extended;
        $buf = '';
    }

    if ($buf !== '') $chunks[] = $buf;
    return $chunks;
}

/**
 * Runs N extraction prompts concurrently against the Anthropic API via
 * curl_multi. Returns one result per prompt, each either
 * ['holdings' => array, 'error' => null] or ['holdings' => [], 'error' => string] —
 * a failed chunk never aborts the others.
 */
function call_claude_extraction_parallel(array $prompts): array {
    $results = run_claude_extraction_batch($prompts);

    // Retry any chunk whose result carried an error once, sequentially --
    // concurrent large POST bodies to the same host have been observed to
    // occasionally return empty/garbled responses (a known class of
    // curl_multi + keep-alive connection reuse issue), while the identical
    // request succeeds reliably on its own.
    $failed = array_keys(array_filter($results, fn($r) => $r['error'] !== null));
    if (!empty($failed)) {
        error_log('call_claude_extraction_parallel: retrying ' . count($failed) . ' failed chunk(s) sequentially: ' . implode(',', $failed));
        foreach ($failed as $i) {
            $retry = run_claude_extraction_batch([$prompts[$i]]);
            $results[$i] = $retry[0];
        }
    }

    ksort($results);
    return $results;
}

/** Runs one round of concurrent extraction calls via curl_multi. Each handle gets its own fresh connection (not reused/shared) to avoid cross-request interference under concurrent large POST bodies. */
function run_claude_extraction_batch(array $prompts): array {
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($prompts as $i => $prompt) {
        $is_vision  = isset($prompt['user_content'][0]['source']);
        $is_complex = $prompt['doc_type_hint'] ?? false;
        $model = ($is_vision || $is_complex) ? CLAUDE_SONNET_MODEL : CLAUDE_HAIKU_MODEL;
        error_log("call_claude_extraction_parallel: chunk " . ($i + 1) . "/" . count($prompts) . " using {$model} (vision=" . ($is_vision ? 'y' : 'n') . " complex=" . ($is_complex ? 'y' : 'n') . ")");

        $payload = [
            'model'      => $model,
            'max_tokens' => 8000,
            'system'     => $prompt['system'],
            'messages'   => [['role' => 'user', 'content' => $prompt['user_content']]],
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-key: ' . CLAUDE_API_KEY, 'anthropic-version: 2023-06-01'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 50, // must leave headroom under the ~60s FPM ceiling
            CURLOPT_FORBID_REUSE   => true,
            CURLOPT_FRESH_CONNECT  => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) curl_multi_select($mh, 1.0);
    } while ($running > 0 && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $i => $ch) {
        $response = curl_multi_getcontent($ch);
        $err      = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        $results[$i] = extract_one_claude_result($response, $err);
    }
    curl_multi_close($mh);

    return $results;
}

/** Parses a single Claude API response (from either the single or parallel path) into holdings or an error. */
function extract_one_claude_result(?string $response, string $curl_err): array {
    if ($curl_err) {
        return ['holdings' => [], 'error' => "Connection error: {$curl_err}"];
    }

    $data = json_decode((string)$response, true);
    if (isset($data['error'])) {
        $msg = $data['error']['message'] ?? 'Unknown Claude error';
        return ['holdings' => [], 'error' => (stripos($msg, 'password') !== false ? 'PDF is password protected. ' : 'AI error: ') . $msg];
    }

    $raw = trim($data['content'][0]['text'] ?? '');
    error_log("Claude extraction response (first 300 chars): " . substr($raw, 0, 300));

    if (stripos($raw, 'password protected') !== false || stripos($raw, 'password-protected') !== false) {
        return ['holdings' => [], 'error' => 'PDF is password protected. Please enter the correct password.'];
    }

    $parsed = extract_json_array($raw);
    if ($parsed === null) {
        error_log("Claude extraction failed. Full response: " . substr($raw, 0, 1000));
        return ['holdings' => [], 'error' => 'Could not parse a valid holdings list from the AI response.'];
    }

    return ['holdings' => $parsed, 'error' => null];
}

function validate_extracted_holdings(array $raw, string $doc_type): array {
    $allowed_types = ['equity','debt','hybrid','elss','index','international','liquid','fd','nps','gold','other'];
    $valid = [];
    foreach ($raw as $h) {
        if (empty($h['fund_name'])) continue;
        $h['fund_type']       = in_array($h['fund_type'] ?? '', $allowed_types) ? $h['fund_type'] : 'equity';
        $h['units_held']      = max(0, round((float)($h['units_held'] ?? 0), 4));
        $h['avg_nav']         = max(0, round((float)($h['avg_nav'] ?? 0), 4));
        $h['current_nav']     = max(0, round((float)($h['current_nav'] ?? 0), 4));
        $h['invested_amount'] = max(0, round((float)($h['invested_amount'] ?? 0), 2));
        $h['current_value']   = max(0, round((float)($h['current_value'] ?? 0), 2));
        $h['purchase_date']   = validate_iso_date($h['purchase_date'] ?? '');
        $h['maturity_date']   = validate_iso_date($h['maturity_date'] ?? '');
        $h['sip_active']      = (bool)($h['sip_active'] ?? false);
        $h['sip_amount']      = max(0, round((float)($h['sip_amount'] ?? 0), 2));
        $h['interest_rate']   = max(0, round((float)($h['interest_rate'] ?? 0), 2));
        $h['folio_number']    = substr((string)($h['folio_number'] ?? ''), 0, 50);
        $h['fund_name']       = substr(htmlspecialchars((string)$h['fund_name'], ENT_QUOTES, 'UTF-8'), 0, 200);
        $h['fund_house']      = substr(htmlspecialchars((string)($h['fund_house'] ?? ''), ENT_QUOTES, 'UTF-8'), 0, 100);

        // Column-confusion guard: if units_held exactly equals invested_amount,
        // the AI has almost certainly mis-read an "Amount" column into the
        // "Units" field -- a genuine unit count coinciding exactly with a
        // rupee amount is implausible. Treat units_held as unreliable rather
        // than let it multiply into a wildly wrong current_value below.
        if ($h['units_held'] > 0 && $h['units_held'] == $h['invested_amount']) {
            $h['units_held'] = 0.0;
        }

        // Auto-fill missing values
        if ($h['invested_amount'] === 0.0 && $h['units_held'] > 0 && $h['avg_nav'] > 0)
            $h['invested_amount'] = round($h['units_held'] * $h['avg_nav'], 2);
        if ($h['current_value'] === 0.0 && $h['units_held'] > 0 && $h['current_nav'] > 0)
            $h['current_value'] = round($h['units_held'] * $h['current_nav'], 2);
        if ($h['current_value'] === 0.0)
            $h['current_value'] = $h['invested_amount'];

        // Sanity cap: a units x NAV-derived current_value more than 20x the
        // invested amount is far beyond any plausible real mutual fund return
        // and signals bad source data rather than a genuine gain. Fall back
        // to invested_amount (no fabricated gain/loss) rather than import it.
        if ($h['invested_amount'] > 0 && $h['current_value'] > $h['invested_amount'] * 20) {
            error_log("validate_extracted_holdings: implausible current_value ({$h['current_value']} vs invested {$h['invested_amount']}) for '{$h['fund_name']}' -- resetting to invested_amount");
            $h['current_value'] = $h['invested_amount'];
        }

        $valid[] = $h;
    }
    return $valid;
}

function validate_iso_date(string $d): ?string {
    if (!$d) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}
