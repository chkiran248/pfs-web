<?php
declare(strict_types=1);

/* ============================================================
   Yahoo Finance Integration — Live stock prices for NSE/BSE
   Endpoint: query1.finance.yahoo.com/v8/finance/chart/{symbol}
   Caches in stock_research table (1h TTL)
   ============================================================ */

function stock_api_fetch(string $ticker, string $exchange = 'NSE'): ?array {
    $suffix = ($exchange === 'BSE') ? '.BO' : '.NS';
    $symbol = urlencode(strtoupper($ticker) . $suffix);
    $url    = "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}?interval=1d&range=5d";

    $ctx = stream_context_create(['http' => [
        'timeout'       => 8,
        'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0',
        'header'        => "Accept: application/json\r\nAccept-Language: en-US,en;q=0.9\r\n",
        'ignore_errors' => true,
    ]]);

    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;

    $data   = json_decode($resp, true);
    $result = $data['chart']['result'][0] ?? null;
    if (!$result) return null;

    $meta    = $result['meta'] ?? [];
    $current = (float)($meta['regularMarketPrice'] ?? 0);
    $prev    = (float)($meta['previousClose'] ?? 0);
    if ($current <= 0) return null;

    $change_abs = round($current - $prev, 2);
    $change_pct = $prev > 0 ? round((($current - $prev) / $prev) * 100, 2) : 0.0;

    return [
        'price'      => round($current, 2),
        'change_abs' => $change_abs,
        'change_pct' => $change_pct,
        'prev_close' => round($prev, 2),
        'currency'   => $meta['currency'] ?? 'INR',
    ];
}

function stock_maybe_refresh(PDO $db, int $limit = 15): void {
    try {
        $stmt = $db->prepare(
            "SELECT id, ticker_symbol, exchange FROM stock_research
             WHERE is_published = 1
               AND (last_price_refresh IS NULL OR last_price_refresh < DATE_SUB(NOW(), INTERVAL 1 HOUR))
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $price = stock_api_fetch($s['ticker_symbol'], $s['exchange']);
            if ($price) {
                try {
                    $db->prepare(
                        "UPDATE stock_research SET current_price=:p, price_change_pct=:c, last_price_refresh=NOW() WHERE id=:id"
                    )->execute([':p' => $price['price'], ':c' => $price['change_pct'], ':id' => $s['id']]);
                } catch (PDOException $e) {
                    error_log('Stock price update error: ' . $e->getMessage());
                }
            }
        }
    } catch (PDOException $e) {
        error_log('stock_maybe_refresh error: ' . $e->getMessage());
    }
}

function stock_price_badge(?float $price, ?float $change_pct): string {
    if (!$price) return '';
    $color = ($change_pct ?? 0) >= 0 ? 'var(--bright)' : 'var(--danger)';
    $sign  = ($change_pct ?? 0) >= 0 ? '▲' : '▼';
    $pct   = $change_pct !== null ? abs($change_pct) . '%' : '';
    return "<span style=\"font-family:'DM Mono',monospace;font-size:0.78rem;color:{$color}\">₹" . number_format($price, 2) . " {$sign} {$pct}</span>";
}
