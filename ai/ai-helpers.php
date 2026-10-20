<?php
declare(strict_types=1);

/**
 * Model router — Haiku (economy) or Sonnet (premium), both via Anthropic.
 * Client financial data stays on Anthropic servers only.
 *
 * Tiers:
 *   economy  — Haiku directly. For PrimoAI chat, generic queries.
 *   standard — Haiku first; auto-escalates to Sonnet on overload/error.
 *   premium  — Sonnet directly. For rebalancer, document parsing.
 *
 * @param string $system     System prompt
 * @param array  $messages   [['role'=>'user'|'assistant','content'=>'...']]
 * @param int    $max_tokens Max output tokens
 * @param string $tier       'economy' | 'standard' | 'premium'
 * @return array ['text'=>string, 'model'=>string, 'tokens'=>int]
 * @throws RuntimeException when the API call fails
 */
function call_llm(string $system, array $messages, int $max_tokens = 2048, string $tier = 'economy'): array {
    $haiku  = CLAUDE_HAIKU_MODEL;
    $sonnet = CLAUDE_SONNET_MODEL;

    if ($tier === 'premium') {
        return call_anthropic($system, $messages, $max_tokens, $sonnet);
    }

    // economy and standard both start with Haiku
    $result = call_anthropic($system, $messages, $max_tokens, $haiku);

    // standard tier: escalate to Sonnet if Haiku is overloaded or returns empty
    if ($tier === 'standard' && $result === null) {
        error_log('call_llm: Haiku unavailable — escalating to Sonnet (standard tier)');
        return call_anthropic($system, $messages, $max_tokens, $sonnet);
    }

    if ($result === null) {
        throw new RuntimeException('AI service temporarily unavailable. Please try again.');
    }

    return $result;
}

/** Builds the curl handle for one Anthropic Messages API call (used by both the single and parallel paths). */
function build_anthropic_handle(string $system, array $messages, int $max_tokens, string $model): \CurlHandle {
    $payload = [
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'system'     => $system,
        'messages'   => $messages,
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => array_filter([
            'Content-Type: application/json',
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01',
            defined('ANTHROPIC_WORKSPACE_ID') && ANTHROPIC_WORKSPACE_ID !== ''
                ? 'anthropic-workspace-id: ' . ANTHROPIC_WORKSPACE_ID
                : null,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 120,
    ]);
    return $ch;
}

/**
 * Parses one Anthropic API response. Returns null on overload/rate-limit/empty
 * (allows tier escalation or batch-partial-failure), throws RuntimeException
 * on billing errors, or returns ['text'=>,'model'=>,'tokens'=>] on success.
 */
function parse_anthropic_response(string $resp, string $curl_err, int $http, string $model): ?array {
    if ($curl_err) {
        error_log("call_anthropic [{$model}] cURL error: {$curl_err}");
        return null;
    }

    // Rate-limited or overloaded — signal caller to escalate
    if ($http === 429 || $http === 529) {
        error_log("call_anthropic [{$model}] overloaded (HTTP {$http})");
        return null;
    }

    $data     = json_decode($resp, true);
    $err_type = $data['error']['type']    ?? '';
    $err_msg  = $data['error']['message'] ?? '';

    if (isset($data['error'])) {
        $is_billing = str_contains($err_type, 'insufficient') ||
                      str_contains($err_msg,  'credit')       ||
                      str_contains($err_msg,  'billing');
        error_log("call_anthropic [{$model}] error ({$err_type}): {$err_msg}");
        if ($is_billing) {
            throw new RuntimeException('AI service is currently unavailable (billing). Please contact support.');
        }
        // Treat other API errors as retryable (return null for standard escalation)
        return null;
    }

    $text = trim($data['content'][0]['text'] ?? '');
    if ($text === '') {
        error_log("call_anthropic [{$model}] returned empty text");
        return null;
    }

    $tokens = $data['usage']['output_tokens'] ?? 0;
    error_log("call_llm: {$model} used {$tokens} output tokens");

    return ['text' => $text, 'model' => $model, 'tokens' => $tokens];
}

/** Single Anthropic API call — unchanged behaviour, now built on the shared helpers above. */
function call_anthropic(string $system, array $messages, int $max_tokens, string $model): ?array {
    $ch = build_anthropic_handle($system, $messages, $max_tokens, $model);
    $resp     = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return parse_anthropic_response((string)$resp, $curl_err, $http, $model);
}

/**
 * Runs N independent 'premium' (Sonnet) tier calls CONCURRENTLY via curl_multi.
 * For splitting one large task (e.g. a multi-batch rebalancer run) into pieces
 * that each complete well within the hosting's hard ~60s process timeout —
 * running them sequentially would still sum past that ceiling once there are
 * more than a couple of batches, even though each individual call is fast.
 *
 * @param array $requests [['system'=>string, 'messages'=>array, 'max_tokens'=>int], ...]
 * @return array one ?array result per request, in the same order, each either
 *               ['text'=>,'model'=>,'tokens'=>] on success or null on failure
 *               (never throws for a single batch's failure — only a billing
 *               error on ANY call still throws, since that applies account-wide).
 */
function call_llm_parallel(array $requests): array {
    $mh      = curl_multi_init();
    $handles = [];
    $model   = CLAUDE_SONNET_MODEL;

    foreach ($requests as $i => $req) {
        $ch = build_anthropic_handle($req['system'], $req['messages'], $req['max_tokens'] ?? 4000, $model);
        curl_setopt($ch, CURLOPT_TIMEOUT, 50); // headroom under the ~60s FPM ceiling
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
        $resp     = curl_multi_getcontent($ch);
        $curl_err = curl_error($ch);
        $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        $results[$i] = parse_anthropic_response((string)$resp, $curl_err, $http, $model);
    }
    curl_multi_close($mh);

    ksort($results);
    return $results;
}

/**
 * Robustly extract a JSON object or array from Claude's response.
 * Handles: pure JSON, markdown-fenced, JSON embedded in prose, truncated responses.
 */
function extract_json_from_claude(string $text): mixed {
    if (trim($text) === '') return null;

    // Strip markdown fences
    $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $text = preg_replace('/^```\s*$/m', '', $text);
    $text = trim($text);

    // 1. Direct parse (best case)
    $d = json_decode($text, true);
    if ($d !== null) return $d;

    // 2. Find outermost JSON object { ... }
    $start = strpos($text, '{');
    if ($start !== false) {
        $depth = 0; $inStr = false; $esc = false;
        for ($i = $start; $i < strlen($text); $i++) {
            $c = $text[$i];
            if ($esc)          { $esc = false; continue; }
            if ($c === '\\' && $inStr) { $esc = true; continue; }
            if ($c === '"')    { $inStr = !$inStr; continue; }
            if ($inStr)        continue;
            if ($c === '{')    $depth++;
            elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($text, $start, $i - $start + 1);
                    $parsed = json_decode($candidate, true);
                    if ($parsed !== null) return $parsed;
                    break;
                }
            }
        }
    }

    // 3. Find outermost JSON array [ ... ]
    $start = strpos($text, '[');
    if ($start !== false) {
        $depth = 0; $inStr = false; $esc = false;
        for ($i = $start; $i < strlen($text); $i++) {
            $c = $text[$i];
            if ($esc)          { $esc = false; continue; }
            if ($c === '\\' && $inStr) { $esc = true; continue; }
            if ($c === '"')    { $inStr = !$inStr; continue; }
            if ($inStr)        continue;
            if ($c === '[')    $depth++;
            elseif ($c === ']') {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($text, $start, $i - $start + 1);
                    $parsed = json_decode($candidate, true);
                    if ($parsed !== null) return $parsed;
                    break;
                }
            }
        }
    }

    return null;
}
