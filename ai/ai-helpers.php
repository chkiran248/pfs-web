<?php
declare(strict_types=1);

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
