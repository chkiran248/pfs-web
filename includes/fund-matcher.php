<?php
declare(strict_types=1);

/**
 * Finds the best match for a fund name among a pool of candidate names by
 * significant-word overlap. Short names (<=4 significant words) require
 * ALL of them to match; longer names require 85%+ (floor 4). Previously
 * all names used a 60%-with-floor-of-2 rule, which let generic shared
 * words (the AMC name, "Fund") satisfy the threshold on their own for
 * short names -- e.g. "HDFC Small Cap Fund" matching "HDFC Mid Cap Fund"
 * purely on "hdfc"+"fund", without the actually-distinguishing word
 * ("small" vs "mid") ever needing to match.
 *
 * @param string $fund_name The holding's fund name to match
 * @param array  $pool      [['name' => lowercase candidate name, ...], ...]
 * @param string $name_key  Key in each $pool entry holding the lowercase name
 * @return array|null The matched pool entry, or null if no confident match
 */
// AMC-boilerplate words common to nearly every scheme name regardless of its
// actual identity -- a candidate having one of these beyond the target's own
// words doesn't disqualify it (e.g. matching a short "Fund - Growth" name
// against AMFI's longer "Fund - Direct Plan - Growth Option"). Anything else
// extra (e.g. "mid" in "Large AND MID Cap Fund") does disqualify it.
const FUND_MATCH_BOILERPLATE = ['fund','plan','option','direct','regular','growth',
    'dividend','scheme','the','and','payout','reinvestment','idcw','bonus'];

function fuzzy_match_fund(string $fund_name, array $pool, string $name_key = 'name'): ?array {
    // >2 (not >3) so 3-letter category words like "mid"/"cap" aren't discarded --
    // those are often exactly the word that distinguishes otherwise-similar schemes.
    $words = array_filter(explode(' ', strtolower($fund_name)), fn($w) => strlen($w) > 2);
    if (empty($words)) return null;
    $wc = count($words);
    $required = $wc <= 4 ? $wc : max(4, (int)ceil($wc * 0.85));

    foreach ($pool as $entry) {
        $candidate = strtolower((string)$entry[$name_key]);
        $hits = count(array_filter($words, fn($w) => str_contains($candidate, $w)));
        if ($hits < $required) continue;

        // Reject if the candidate has a real extra word the target lacks --
        // e.g. target "Large Cap Fund" must not match "Large AND MID Cap
        // Fund" just because all of the target's (fewer) words also happen
        // to appear in the longer name.
        $cand_words = array_filter(explode(' ', $candidate), fn($w) => strlen($w) > 2);
        $extra = array_filter($cand_words, fn($w) => !in_array($w, $words, true) && !in_array($w, FUND_MATCH_BOILERPLATE, true));
        if (!empty($extra)) continue;

        return $entry;
    }
    return null;
}
