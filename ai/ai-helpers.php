<?php
declare(strict_types=1);

/**
 * Unified LLM caller — tries Claude first, falls back to Gemini on billing/overload errors.
 *
 * @param string $system      System prompt
 * @param array  $messages    Array of ['role'=>'user'|'assistant', 'content'=>'...']
 * @param int    $max_tokens  Max output tokens
 * @return array ['text'=>string, 'model'=>'claude'|'gemini', 'tokens'=>int]
 * @throws RuntimeException on total failure (both models failed)
 */
function call_llm(string $system, array $messages, int $max_tokens = 2048): array {
    // ── Try Claude ───────────────────────────────────────────────────────
    $payload = [
        'model'      => PRIMO_MODEL,
        'max_tokens' => $max_tokens,
        'system'     => $system,
        'messages'   => $messages,
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-key: ' . CLAUDE_API_KEY, 'anthropic-version: 2023-06-01'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 90,
    ]);
    $resp     = curl_exec($ch);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if (!$curl_err && $resp) {
        $data      = json_decode($resp, true);
        $err_type  = $data['error']['type'] ?? '';
        $err_msg   = $data['error']['message'] ?? '';
        $is_billing = str_contains($err_type, 'insufficient') || str_contains($err_msg, 'credit') || str_contains($err_msg, 'billing');
        $is_overload = $err_type === 'overloaded_error' || str_contains($err_msg, 'overloaded');

        if (!isset($data['error'])) {
            // Claude succeeded
            $text = trim($data['content'][0]['text'] ?? '');
            if ($text !== '') {
                return ['text' => $text, 'model' => 'claude', 'tokens' => $data['usage']['output_tokens'] ?? 0];
            }
        }

        if (!$is_billing && !$is_overload) {
            // Real Claude error (not billing/overload) — don't expose raw message
            $safe = $is_billing ? 'AI service billing issue.' : ($err_msg ?: 'AI returned an unexpected error.');
            throw new RuntimeException('Claude error: ' . $safe);
        }

        error_log('call_llm: Claude unavailable (' . $err_type . '), falling back to Gemini');
    } else {
        error_log('call_llm: Claude cURL error (' . $curl_err . '), falling back to Gemini');
    }

    // ── Fall back to Gemini ──────────────────────────────────────────────
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
        throw new RuntimeException('AI service temporarily unavailable. Please try again later.');
    }

    // Convert message roles: 'assistant' → 'model' for Gemini
    $gemini_contents = [];
    foreach ($messages as $m) {
        $gemini_contents[] = [
            'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ];
    }

    $gemini_payload = [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents'           => $gemini_contents,
        'generationConfig'   => ['maxOutputTokens' => $max_tokens, 'temperature' => 0.7],
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;
    $ch2 = curl_init($url);
    curl_setopt_array($ch2, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($gemini_payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
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

    $data2 = json_decode($resp2, true);
    if (isset($data2['error'])) {
        error_log('call_llm: Gemini error: ' . ($data2['error']['message'] ?? 'unknown'));
        throw new RuntimeException('AI service temporarily unavailable. Please try again later.');
    }

    $text2 = trim($data2['candidates'][0]['content']['parts'][0]['text'] ?? '');
    if ($text2 === '') {
        throw new RuntimeException('AI returned an empty response. Please try again.');
    }

    error_log('call_llm: Gemini fallback succeeded');
    return ['text' => $text2, 'model' => 'gemini', 'tokens' => 0];
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
