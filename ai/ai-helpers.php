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

/**
 * Single Anthropic API call. Returns null on overload/rate-limit (allows tier escalation).
 * Throws RuntimeException on billing errors or empty responses.
 */
function call_anthropic(string $system, array $messages, int $max_tokens, string $model): ?array {
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
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 120,
    ]);

    $resp     = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

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
