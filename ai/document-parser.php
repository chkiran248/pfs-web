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
        $prompt   = build_extraction_prompt($raw_text, $doc_type, $file_path);
        $raw      = call_claude_extraction($prompt);
        $holdings = validate_extracted_holdings($raw, $doc_type);

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
        'CAS_MUTUAL_FUND'  => 'CAS Statement',
        'BROKER_STATEMENT' => 'Broker Statement',
        'DEMAT_STATEMENT'  => 'Demat Statement',
        'FD_CERTIFICATE'   => 'FD Certificate',
        'INSURANCE_POLICY' => 'Insurance Policy',
        default            => 'Financial Document',
    };
}

/**
 * Try pdftotext first (Linux/Hostinger), fall back to empty string
 * so Claude reads the PDF directly via base64.
 */
function extract_pdf_text_smart(string $file_path): string {
    if (function_exists('shell_exec') && !str_contains(PHP_OS, 'WIN')) {
        $which = trim((string)(shell_exec('which pdftotext 2>/dev/null') ?? ''));
        if ($which) {
            $text = (string)(shell_exec('pdftotext ' . escapeshellarg($file_path) . ' - 2>/dev/null') ?? '');
            if (strlen(trim($text)) > 100) return $text;
        }
    }
    return ''; // triggers Claude vision fallback
}

function detect_document_type(string $text, string $filename): string {
    $lower = strtolower($text . ' ' . $filename);
    if (str_contains($lower, 'consolidated account statement') || str_contains($lower, 'cams') || str_contains($lower, 'kfintech') || str_contains($lower, 'karvy')) return 'CAS_MUTUAL_FUND';
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
For each holding return: fund_name, fund_house, fund_type (equity/debt/hybrid/elss/index/international/liquid/gold), folio_number, units_held (decimal), avg_nav, current_nav, invested_amount, current_value, purchase_date (YYYY-MM-DD), sip_active (true/false), sip_amount (monthly).",

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

    $user_content = [];
    if ($has_text) {
        $user_content[] = ['type' => 'text', 'text' => $instructions . "\n\nDocument text:\n" . mb_substr($text, 0, 12000)];
    } else {
        // Send PDF as base64 for Claude vision
        $pdf_b64 = base64_encode(file_get_contents($file_path));
        $user_content[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $pdf_b64]];
        $user_content[] = ['type' => 'text', 'text' => $instructions];
    }

    return ['system' => $system, 'user_content' => $user_content];
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

function call_claude_extraction(array $prompt): array {
    $payload = [
        'model'      => PRIMO_MODEL,
        'max_tokens' => 8000,           // increased for large statements
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
        CURLOPT_TIMEOUT        => 120,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) throw new Exception("Connection error: $err");

    $data = json_decode($response, true);
    if (isset($data['error'])) {
        $msg = $data['error']['message'] ?? 'Unknown Claude error';
        if (stripos($msg, 'password') !== false) throw new Exception("PDF is password protected. " . $msg);
        throw new Exception("AI error: " . $msg);
    }

    $raw = trim($data['content'][0]['text'] ?? '');
    error_log("Claude extraction response (first 300 chars): " . substr($raw, 0, 300));

    if (stripos($raw, 'password protected') !== false || stripos($raw, 'password-protected') !== false) {
        throw new Exception("PDF is password protected. Please enter the correct password.");
    }

    $parsed = extract_json_array($raw);

    if ($parsed === null) {
        error_log("Claude extraction failed. Full response: " . substr($raw, 0, 1000));
        throw new Exception("Could not extract holdings from this document. The statement format may not be supported, or the PDF may be image-based (scanned). Try a text-based PDF.");
    }

    return $parsed;
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

        // Auto-fill missing values
        if ($h['invested_amount'] === 0.0 && $h['units_held'] > 0 && $h['avg_nav'] > 0)
            $h['invested_amount'] = round($h['units_held'] * $h['avg_nav'], 2);
        if ($h['current_value'] === 0.0 && $h['units_held'] > 0 && $h['current_nav'] > 0)
            $h['current_value'] = round($h['units_held'] * $h['current_nav'], 2);
        if ($h['current_value'] === 0.0)
            $h['current_value'] = $h['invested_amount'];

        $valid[] = $h;
    }
    return $valid;
}

function validate_iso_date(string $d): ?string {
    if (!$d) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}
