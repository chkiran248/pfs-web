<?php
declare(strict_types=1);

/**
 * Unified LLM caller — tries OpenRouter (free) first, falls back to Claude (paid).
 *
 * Primary  : OpenRouter (google/gemini-2.0-flash-exp:free or OPENROUTER_MODEL)
 * Fallback : Claude (Anthropic) — used when OpenRouter is rate-limited or unavailable
 *
 * @param string $system      System prompt
 * @param array  $messages    Array of ['role'=>'user'|'assistant', 'content'=>'...']
 * @param int    $max_tokens  Max output tokens
 * @return array ['text'=>string, 'model'=>string, 'tokens'=>int]
 * @throws RuntimeException when both providers fail
 */
function call_llm(string $system, array $messages, int $max_tokens = 2048): array {
    // ── 1. Try OpenRouter (free tier) ────────────────────────────────────
    if (defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '') {
        // OpenRouter uses OpenAI-compatible format: system goes as first message
        $or_messages = array_merge(
            [['role' => 'system', 'content' => $system]],
            $messages
        );
        $or_payload = [
            'model'      => defined('OPENROUTER_MODEL') ? OPENROUTER_MODEL : 'google/gemini-2.0-flash-exp:free',
            'messages'   => $or_messages,
            'max_tokens' => $max_tokens,
        ];
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($or_payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . OPENROUTER_API_KEY,
                'HTTP-Referer: https://primefin.in',
                'X-Title: Prime Financials',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 90,
        ]);
        $resp     = curl_exec($ch);
        $curl_err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$curl_err && $resp) {
            $data = json_decode($resp, true);
            $text = trim($data['choices'][0]['message']['content'] ?? '');
            if ($text !== '' && !isset($data['error'])) {
                return [
                    'text'   => $text,
                    'model'  => $or_payload['model'],
                    'tokens' => $data['usage']['completion_tokens'] ?? 0,
                ];
            }
            $or_err = $data['error']['message'] ?? 'unknown';
            error_log("call_llm: OpenRouter failed (HTTP {$http_code}): {$or_err} — falling back to Claude");
        } else {
            error_log("call_llm: OpenRouter cURL error: {$curl_err} — falling back to Claude");
        }
    }

    // ── 2. Fall back to Claude (Anthropic) ───────────────────────────────
    $payload = [
        'model'      => PRIMO_MODEL,
        'max_tokens' => $max_tokens,
        'system'     => $system,
        'messages'   => $messages,
    ];
    $ch2 = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch2, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-key: ' . CLAUDE_API_KEY, 'anthropic-version: 2023-06-01'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 90,
    ]);
    $resp2     = curl_exec($ch2);
    $curl_err2 = curl_error($ch2);
    curl_close($ch2);

    if ($curl_err2) {
        throw new RuntimeException('AI service temporarily unavailable. Please try again later.');
    }

    $data2    = json_decode($resp2, true);
    $err_type = $data2['error']['type'] ?? '';
    $err_msg  = $data2['error']['message'] ?? '';

    if (isset($data2['error'])) {
        $is_billing = str_contains($err_type, 'insufficient') || str_contains($err_msg, 'credit') || str_contains($err_msg, 'billing');
        error_log("call_llm: Claude error ({$err_type}): {$err_msg}");
        if ($is_billing) {
            throw new RuntimeException('AI service is currently unavailable. Please try again later or contact support.');
        }
        throw new RuntimeException('AI service temporarily unavailable. Please try again.');
    }

    $text2 = trim($data2['content'][0]['text'] ?? '');
    if ($text2 === '') {
        throw new RuntimeException('AI returned an empty response. Please try again.');
    }

    error_log('call_llm: Claude fallback succeeded');
    return ['text' => $text2, 'model' => 'claude', 'tokens' => $data2['usage']['output_tokens'] ?? 0];
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
