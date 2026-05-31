<?php
declare(strict_types=1);

/**
 * Classifies a holding by name into a proper fund_type and display label.
 * Used by scanner confirmation AND rebalancer display.
 */
function classify_holding(string $name): array {
    $lower = strtolower(trim($name));

    // ── Individual stocks: no fund/scheme/etf/plan keywords ─────
    $mf_indicators = [
        'fund','scheme','etf','plan','nifty','sensex','bse ','nse ',
        'index fund','fof','liquid','overnight','gilt','bond fund',
    ];
    $looks_like_mf = false;
    foreach ($mf_indicators as $kw) {
        if (str_contains($lower, $kw)) { $looks_like_mf = true; break; }
    }
    // Also check: if name ends in "Limited" / "Ltd" / "Corp" with no fund words → stock
    $stock_suffixes = ['limited','ltd','corporation','corp','industries','telecom','bank','technologies'];
    $looks_like_stock = false;
    if (!$looks_like_mf) {
        foreach ($stock_suffixes as $s) {
            if (str_contains($lower, $s)) { $looks_like_stock = true; break; }
        }
    }
    if ($looks_like_stock && !$looks_like_mf) {
        return ['db_type' => 'equity', 'display' => 'Stock'];
    }

    // ── ELSS / Tax Saver ─────────────────────────────────────────
    if (str_contains($lower, 'elss') || str_contains($lower, 'tax saver') || str_contains($lower, 'tax saving') || str_contains($lower, 'taxsaver')) {
        return ['db_type' => 'elss', 'display' => 'Mutual Fund – ELSS'];
    }

    // ── Index / ETF / FOF ────────────────────────────────────────
    if (str_contains($lower, ' etf') || str_contains($lower, 'etf ') || str_contains($lower, 'nifty') || str_contains($lower, 'sensex') ||
        str_contains($lower, 'index fund') || str_contains($lower, 'bse 500') || str_contains($lower, 'nasdaq') ||
        str_contains($lower, 'fof') || str_contains($lower, 'fund of fund') || str_contains($lower, 'index ')) {
        return ['db_type' => 'index', 'display' => 'Mutual Fund – Index/ETF'];
    }

    // ── Hybrid / Balanced ────────────────────────────────────────
    if (str_contains($lower, 'balanced') || str_contains($lower, 'hybrid') ||
        str_contains($lower, 'dynamic asset') || str_contains($lower, 'equity & debt') ||
        str_contains($lower, 'equity and debt') || str_contains($lower, 'asset alloc') ||
        str_contains($lower, 'multi asset') || str_contains($lower, 'conservative hybrid') ||
        str_contains($lower, 'aggressive hybrid') || str_contains($lower, 'arbitrage')) {
        return ['db_type' => 'hybrid', 'display' => 'Mutual Fund – Hybrid'];
    }

    // ── Debt ─────────────────────────────────────────────────────
    if (str_contains($lower, 'bond') || str_contains($lower, 'gilt') ||
        str_contains($lower, 'short term') || str_contains($lower, 'short duration') ||
        str_contains($lower, 'long duration') || str_contains($lower, 'medium duration') ||
        str_contains($lower, 'credit risk') || str_contains($lower, 'overnight') ||
        str_contains($lower, 'liquid fund') || str_contains($lower, 'money market') ||
        str_contains($lower, 'ultra short') || str_contains($lower, 'corporate bond') ||
        str_contains($lower, 'banking & psu') || str_contains($lower, 'banking and psu') ||
        str_contains($lower, 'floater') || str_contains($lower, 'dynamic bond')) {
        return ['db_type' => 'debt', 'display' => 'Mutual Fund – Debt'];
    }

    // ── Gold / Silver ────────────────────────────────────────────
    if (str_contains($lower, 'gold') || str_contains($lower, 'silver')) {
        return ['db_type' => 'gold', 'display' => 'Mutual Fund – Gold'];
    }

    // ── International ────────────────────────────────────────────
    if (str_contains($lower, 'international') || str_contains($lower, 'global') ||
        str_contains($lower, 'overseas') || str_contains($lower, 'us equity') ||
        str_contains($lower, 'world fund') || str_contains($lower, 'emerging market')) {
        return ['db_type' => 'international', 'display' => 'Mutual Fund – International'];
    }

    // ── Equity sub-categories ────────────────────────────────────
    if (str_contains($lower, 'small cap') || str_contains($lower, 'smallcap')) {
        return ['db_type' => 'equity', 'display' => 'Mutual Fund – Small Cap'];
    }
    if (str_contains($lower, 'mid cap') || str_contains($lower, 'midcap')) {
        return ['db_type' => 'equity', 'display' => 'Mutual Fund – Mid Cap'];
    }
    if (str_contains($lower, 'large cap') || str_contains($lower, 'largecap') || str_contains($lower, 'bluechip') || str_contains($lower, 'blue chip')) {
        return ['db_type' => 'equity', 'display' => 'Mutual Fund – Large Cap'];
    }
    if (str_contains($lower, 'large & mid') || str_contains($lower, 'large and mid') || str_contains($lower, 'large midcap')) {
        return ['db_type' => 'equity', 'display' => 'Mutual Fund – Large & Mid Cap'];
    }
    if (str_contains($lower, 'flexi cap') || str_contains($lower, 'flexicap') || str_contains($lower, 'multi cap') || str_contains($lower, 'multicap')) {
        return ['db_type' => 'equity', 'display' => 'Mutual Fund – Flexi/Multi Cap'];
    }
    if (str_contains($lower, 'focused')) {
        return ['db_type' => 'equity', 'display' => 'Mutual Fund – Focused'];
    }
    if (str_contains($lower, 'sectoral') || str_contains($lower, 'thematic') || str_contains($lower, 'infrastructure') || str_contains($lower, 'banking') || str_contains($lower, 'healthcare') || str_contains($lower, 'pharma') || str_contains($lower, 'technology') || str_contains($lower, 'consumption')) {
        return ['db_type' => 'equity', 'display' => 'Mutual Fund – Sectoral/Thematic'];
    }
    if (str_contains($lower, 'contra') || str_contains($lower, 'value fund') || str_contains($lower, 'quant')) {
        return ['db_type' => 'equity', 'display' => 'Mutual Fund – Value/Contra'];
    }

    // ── Default: MF Equity ───────────────────────────────────────
    if ($looks_like_mf) {
        return ['db_type' => 'equity', 'display' => 'Mutual Fund – Equity'];
    }

    return ['db_type' => 'equity', 'display' => 'Equity'];
}

/**
 * Display label for a given db_type (without needing the fund name).
 */
function fund_type_display(string $db_type, string $fund_name = ''): string {
    if ($fund_name) {
        $classified = classify_holding($fund_name);
        return $classified['display'];
    }
    return match($db_type) {
        'elss'          => 'Mutual Fund – ELSS',
        'index'         => 'Mutual Fund – Index/ETF',
        'hybrid'        => 'Mutual Fund – Hybrid',
        'debt'          => 'Mutual Fund – Debt',
        'gold'          => 'Mutual Fund – Gold',
        'international' => 'Mutual Fund – International',
        'liquid'        => 'Mutual Fund – Liquid/Debt',
        'nps'           => 'NPS',
        'fd'            => 'Fixed Deposit',
        'other'         => 'Other',
        default         => 'Equity',
    };
}

/**
 * Retroactively classify and update all holdings for a user.
 * Safe to run multiple times (idempotent).
 */
function reclassify_portfolio(int $user_id, PDO $db): int {
    $stmt = $db->prepare("SELECT id, fund_name, fund_type FROM portfolio_entries WHERE user_id = :uid");
    $stmt->execute([':uid' => $user_id]);
    $holdings = $stmt->fetchAll();

    $upd = $db->prepare("UPDATE portfolio_entries SET fund_type = :type WHERE id = :id");
    $changed = 0;
    foreach ($holdings as $h) {
        $classified = classify_holding($h['fund_name']);
        if ($classified['db_type'] !== $h['fund_type']) {
            $upd->execute([':type' => $classified['db_type'], ':id' => $h['id']]);
            $changed++;
        }
    }
    return $changed;
}
