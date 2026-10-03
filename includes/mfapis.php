<?php
declare(strict_types=1);
// mfapis.club API wrapper — base URL and key from config.php
// Requires: MFAPIS_BASE_URL and MFAPIS_API_KEY constants defined in config.php

// ── CORE HTTP HELPER ────────────────────────────────────────

/**
 * Internal HTTP GET helper used by all public wrapper functions.
 * Builds URL from base + endpoint + query params, adds auth header,
 * returns decoded JSON array or null on any error.
 */
function mfapis_get(string $endpoint, array $params = [], int $timeout = 15): ?array
{
    $url = MFAPIS_BASE_URL . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . MFAPIS_API_KEY,
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        error_log('[mfapis] cURL error for ' . $endpoint . ': ' . $curlErr);
        return null;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('[mfapis] HTTP ' . $httpCode . ' for ' . $endpoint);
        return null;
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        error_log('[mfapis] JSON decode failed for ' . $endpoint . ': ' . json_last_error_msg());
        return null;
    }

    return $decoded;
}

/**
 * Internal HTTP POST helper for endpoints that require a JSON body.
 */
function mfapis_post(string $endpoint, array $body = [], int $timeout = 15): ?array
{
    $url     = MFAPIS_BASE_URL . $endpoint;
    $payload = json_encode($body);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . MFAPIS_API_KEY,
            'Accept: application/json',
            'Content-Type: application/json',
            'Content-Length: ' . strlen((string) $payload),
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        error_log('[mfapis] cURL POST error for ' . $endpoint . ': ' . $curlErr);
        return null;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('[mfapis] HTTP ' . $httpCode . ' POST for ' . $endpoint);
        return null;
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        error_log('[mfapis] JSON decode failed for POST ' . $endpoint . ': ' . json_last_error_msg());
        return null;
    }

    return $decoded;
}

// ── MF ──────────────────────────────────────────────────────

/**
 * Fetch the latest NAV for a scheme by AMFI code.
 * Returns: nav value + date. Not cached — called from cron only.
 *
 * GET /nav/amfi/{amfi_code}
 */
function mfapis_nav(string $amfi_code): ?array
{
    return mfapis_get('/nav/amfi/' . rawurlencode($amfi_code));
}

/**
 * Fetch full scheme details: returns, expense ratio, category, etc.
 *
 * GET /scheme/{id}
 */
function mfapis_scheme(string $scheme_id): ?array
{
    return mfapis_get('/scheme/' . rawurlencode($scheme_id));
}

/**
 * Fetch compact scheme data: returns + expense ratio.
 *
 * GET /scheme/mf_data/{id}
 */
function mfapis_scheme_data(string $scheme_id): ?array
{
    return mfapis_get('/scheme/mf_data/' . rawurlencode($scheme_id));
}

/**
 * Search schemes by name or keyword. Used in fund explorer / search.
 *
 * GET /scheme?search={query}&limit={limit}
 */
function mfapis_schemes_search(string $query, int $limit = 20): ?array
{
    return mfapis_get('/scheme', [
        'name'  => $query,
        'limit' => $limit,
    ]);
}

/**
 * Filter and screen mutual fund schemes.
 * Supported filters: amc, category, sub_category, option (direct/regular),
 * sort_by, direction.
 *
 * POST /mf/query/screen
 */
function mfapis_schemes_filter(array $filters = [], int $page = 1, int $limit = 18): ?array
{
    $body = array_merge($filters, [
        'page'  => $page,
        'limit' => $limit,
    ]);
    return mfapis_post('/mf/query/screen', $body);
}

// ── STOCKS ──────────────────────────────────────────────────

/**
 * Fetch the latest OHLCV data for a stock by ISIN.
 * Returns: open, high, low, close, volume, date.
 *
 * GET /stocks/ohlcv/latest?isin={isin}
 */
function mfapis_stock_latest(string $isin): ?array
{
    return mfapis_get('/stocks/ohlcv/latest', ['isin' => $isin]);
}

/**
 * Fetch latest OHLCV for multiple ISINs in one call.
 * Returns array keyed by ISIN. Maximum 50 ISINs per call.
 *
 * GET /stocks/ohlcv/multi/latest?isins=ISIN1,ISIN2,...
 */
function mfapis_stocks_batch(array $isins): ?array
{
    if (empty($isins)) {
        return null;
    }
    return mfapis_get('/stocks/ohlcv/multi/latest', [
        'isins' => implode(',', $isins),
    ]);
}

/**
 * Fetch historical OHLCV for a stock.
 * Dates format: YYYY-MM-DD.
 *
 * GET /stocks/ohlcv/history?isin={isin}&from={from}&to={to}
 */
function mfapis_stock_history(string $isin, string $from, string $to): ?array
{
    return mfapis_get('/stocks/ohlcv/history', [
        'isin' => $isin,
        'from' => $from,
        'to'   => $to,
    ]);
}

/**
 * Fetch fundamental data for a stock.
 * Returns: pe, pb, roe, roce, market_cap, eps, div_yield, 52w_high, 52w_low.
 *
 * GET /stocks/fundamentals/latest?isin={isin}
 */
function mfapis_stock_fundamentals(string $isin): ?array
{
    return mfapis_get('/stocks/fundamentals/latest', ['isin' => $isin]);
}

/**
 * Fetch financial summary for a stock.
 * Returns: revenue, net_profit, margins.
 *
 * GET /financials/{isin}/summary
 */
function mfapis_stock_financials(string $isin): ?array
{
    return mfapis_get('/financials/' . rawurlencode($isin) . '/summary');
}

/**
 * Fetch financial ratios for a stock.
 * Returns: debt_equity, current_ratio, roe, roce.
 *
 * GET /financials/{isin}/ratios
 */
function mfapis_stock_ratios(string $isin): ?array
{
    return mfapis_get('/financials/' . rawurlencode($isin) . '/ratios');
}

/**
 * Fetch promoter holding and pledge data for a stock.
 * Returns: promoter_pct, pledge_pct.
 *
 * GET /stocks/promoter/latest?isin={isin}
 */
function mfapis_stock_promoter(string $isin): ?array
{
    return mfapis_get('/stocks/promoter/latest', ['isin' => $isin]);
}

/**
 * Screen stocks using fundamental filters.
 * Supported filters: sector, pe_min, pe_max, pb_min, pb_max, roe_min,
 * promoter_min, pledge_max, mktcap_min, mktcap_max, sort_by, direction.
 *
 * GET /stocks/screener/equity
 */
function mfapis_screener(array $filters = [], int $limit = 50): ?array
{
    $params = array_merge($filters, ['limit' => $limit]);
    return mfapis_get('/stocks/screener/equity', $params);
}

/**
 * Fetch market movers (top gainers, losers, or volume leaders).
 *
 * GET /stocks/screener/movers?type={type}
 * type: gainers | losers | volume
 */
function mfapis_movers(string $type = 'gainers'): ?array
{
    return mfapis_get('/stocks/screener/movers', ['type' => $type]);
}

/**
 * Search stocks by name or ticker symbol.
 *
 * GET /stocks/symbol_master/search?q={query}
 */
function mfapis_symbol_search(string $query): ?array
{
    return mfapis_get('/stocks/symbol_master/search', ['q' => $query]);
}

// ── INDICES ─────────────────────────────────────────────────

/**
 * Fetch latest index values.
 * Pass empty string to get all indices (Nifty 50, Nifty 500, Midcap 150,
 * Smallcap 250, etc.). Pass index name to filter to one.
 *
 * GET /indices/nse/latest                  — all indices
 * GET /indices/nse/latest?index={name}     — specific index
 */
function mfapis_index_latest(string $index_name = ''): ?array
{
    $params = [];
    if ($index_name !== '') {
        $params['index'] = $index_name;
    }
    return mfapis_get('/indices/nse/latest', $params);
}

/**
 * Fetch historical data for a named index.
 * Dates format: YYYY-MM-DD.
 *
 * GET /indices/nse/history?index={index_name}&from={from}&to={to}
 */
function mfapis_index_history(string $index_name, string $from, string $to): ?array
{
    return mfapis_get('/indices/nse/history', [
        'index' => $index_name,
        'from'  => $from,
        'to'    => $to,
    ]);
}

// ── MARKET / MACRO ──────────────────────────────────────────

/**
 * Fetch FII/DII activity data.
 * Pass true for historical series, false (default) for latest only.
 *
 * GET /market/fii_dii/latest   — $history = false
 * GET /market/fii_dii/history  — $history = true
 */
function mfapis_fii_dii(bool $history = false): ?array
{
    $path = $history ? '/market/fii_dii/history' : '/market/fii_dii/latest';
    return mfapis_get($path);
}

/**
 * Fetch latest market news headlines.
 *
 * GET /market/news/latest?limit={limit}
 */
function mfapis_news(int $limit = 10): ?array
{
    return mfapis_get('/market/news/latest', ['limit' => $limit]);
}

/**
 * Fetch latest RBI policy rates (repo rate, reverse repo, etc.).
 *
 * GET /macro/rbi_rates/latest
 */
function mfapis_rbi_rate(): ?array
{
    return mfapis_get('/macro/rbi_rates/latest');
}

/**
 * Fetch latest CPI (Consumer Price Index) inflation data.
 *
 * GET /macro/cpi/latest
 */
function mfapis_cpi(): ?array
{
    return mfapis_get('/macro/cpi/latest');
}

/**
 * Fetch latest market activity summary (advances, declines, volumes, etc.).
 *
 * GET /market/activity/latest
 */
function mfapis_market_activity(): ?array
{
    return mfapis_get('/market/activity/latest');
}
