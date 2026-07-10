<?php
declare(strict_types=1);

/* ============================================================
   MFAPI.in Integration — Mutual Fund live NAV + returns
   Fetches from https://api.mfapi.in/mf/{schemeCode}
   Caches in fund_recommendations table (24h TTL)
   ============================================================ */

function mf_api_fetch(string $scheme_code): ?array {
    $url = 'https://api.mfapi.in/mf/' . urlencode($scheme_code);
    $ctx = stream_context_create(['http' => [
        'timeout'       => 12,
        'user_agent'    => 'PrimeFin-Portal/1.0',
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;
    $data = json_decode($resp, true);
    // MFAPI individual-fund responses don't include a status field; only validate data exists
    if (!is_array($data) || empty($data['data']) || !isset($data['data'][0]['nav'])) return null;
    return $data;
}

function mf_parse_date(string $date_str): int {
    // MFAPI returns DD-MM-YYYY
    $parts = explode('-', $date_str);
    if (count($parts) !== 3) return 0;
    return (int)mktime(0, 0, 0, (int)$parts[1], (int)$parts[0], (int)$parts[2]);
}

function mf_cagr(array $nav_data, int $years): ?float {
    if (empty($nav_data)) return null;
    $latest    = (float)$nav_data[0]['nav'];
    $target_ts = strtotime("-{$years} years");
    foreach ($nav_data as $entry) {
        $entry_ts = mf_parse_date($entry['date']);
        if ($entry_ts <= $target_ts) {
            $old_nav = (float)$entry['nav'];
            if ($old_nav <= 0) return null;
            $actual_yrs = (time() - $entry_ts) / (365.25 * 86400);
            if ($actual_yrs <= 0) return null;
            return round((pow($latest / $old_nav, 1.0 / $actual_yrs) - 1.0) * 100, 2);
        }
    }
    return null; // insufficient history
}

function mf_refresh_fund(int $fund_id, string $scheme_code, PDO $db): bool {
    $data = mf_api_fetch($scheme_code);
    if (!$data) return false;

    $nav_data    = $data['data']; // newest first
    $current_nav = round((float)$nav_data[0]['nav'], 4);
    $return_1yr  = mf_cagr($nav_data, 1);
    $return_3yr  = mf_cagr($nav_data, 3);
    $return_5yr  = mf_cagr($nav_data, 5);
    $nav_date    = $nav_data[0]['date'] ?? null; // DD-MM-YYYY

    try {
        $stmt = $db->prepare(
            "UPDATE fund_recommendations
             SET current_nav=:nav, return_1yr=:r1, return_3yr=:r3, return_5yr=:r5,
                 last_data_refresh=NOW()
             WHERE id=:id"
        );
        $stmt->execute([
            ':nav' => $current_nav,
            ':r1'  => $return_1yr,
            ':r3'  => $return_3yr,
            ':r5'  => $return_5yr,
            ':id'  => $fund_id,
        ]);
        return true;
    } catch (PDOException $e) {
        error_log('MF NAV refresh error: ' . $e->getMessage());
        return false;
    }
}

function mf_maybe_refresh(PDO $db, int $limit = 5): void {
    try {
        $stmt = $db->prepare(
            "SELECT id, scheme_code FROM fund_recommendations
             WHERE is_active = 1
               AND scheme_code IS NOT NULL AND scheme_code != ''
               AND (last_data_refresh IS NULL OR last_data_refresh < DATE_SUB(NOW(), INTERVAL 24 HOUR))
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            mf_refresh_fund((int)$f['id'], $f['scheme_code'], $db);
        }
    } catch (PDOException $e) {
        error_log('mf_maybe_refresh error: ' . $e->getMessage());
    }
}

function mf_format_return(?float $val): string {
    if ($val === null) return '—';
    $color = $val >= 0 ? 'var(--bright)' : 'var(--danger)';
    $sign  = $val >= 0 ? '+' : '';
    return "<span style=\"color:{$color};font-family:'DM Mono',monospace\">{$sign}{$val}%</span>";
}
