<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }

set_time_limit(0);           // CLI scripts need unlimited time for MFAPI fetches
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mf-api.php';

$db = get_db();
echo '[' . date('H:i:s') . "] Fund scoring starting\n";

const RISK_FREE_ANNUAL = 6.5; // RBI repo rate proxy (%)
const MIN_DAYS_STD     = 60;  // minimum trading days for std dev / sharpe
const MIN_DAYS_BETA    = 120; // minimum aligned days for beta / alpha
const MIN_DAYS_ROLLING = 365; // minimum days to compute rolling win rate

// Benchmark MFAPI proxy scheme codes (fallback when benchmark_nav is sparse)
const BENCH_PROXY = [
    'nifty50'           => '120716',
    'nifty100'          => '147666',
    'nifty_midcap150'   => '148726',
    'nifty_smallcap250' => '148519',
    'nifty500'          => '152908',
    'crisil_short_dur'  => '118796',
    'crisil_gilt'       => '119707',
];

$funds = $db->query(
    "SELECT id, fund_name, scheme_code, benchmark, return_1yr, return_3yr, return_5yr, expense_ratio, aum_cr
     FROM fund_recommendations WHERE is_active=1 AND scheme_code IS NOT NULL"
)->fetchAll(PDO::FETCH_ASSOC);

$update = $db->prepare(
    "UPDATE fund_recommendations SET
       std_dev_1yr=:sd, max_drawdown=:md, sharpe_ratio=:sh, sortino_ratio=:so,
       alpha=:al, beta=:be, r_squared=:rs, tracking_error=:te, info_ratio=:ir,
       excess_return_3yr=:ex, rolling_win_rate=:wr,
       tech_score=:ts, is_featured=:ft, tech_scored_at=NOW()
     WHERE id=:id"
);

$all_results = [];

foreach ($funds as $fund) {
    echo "\nScoring: {$fund['fund_name']}\n";

    // ── Fetch fund NAV history from MFAPI ────────────────────────────────────
    $fund_data = mf_api_fetch($fund['scheme_code']);
    if (!$fund_data || empty($fund_data['data'])) {
        echo "  SKIP — MFAPI unavailable for scheme {$fund['scheme_code']}\n";
        continue;
    }
    $fund_navs = parse_nav_series($fund_data['data']); // [date => nav], sorted oldest-first

    // ── Fetch benchmark NAV history ───────────────────────────────────────────
    $bench_navs = [];
    if (!empty($fund['benchmark']) && isset(BENCH_PROXY[$fund['benchmark']])) {
        $bench_data = get_benchmark_navs($db, $fund['benchmark'], BENCH_PROXY[$fund['benchmark']]);
        $bench_navs = parse_nav_series($bench_data);
    }

    // ── Compute metrics ───────────────────────────────────────────────────────
    $metrics = compute_metrics($fund_navs, $bench_navs, $fund);

    // ── Tech score ────────────────────────────────────────────────────────────
    $tech_score = compute_tech_score($metrics, $fund);
    $is_featured = $tech_score >= 70 ? 1 : 0;

    printf(
        "  Score:%d  Sharpe:%.2f  MaxDD:%.1f%%  Alpha:%.2f  Excess3yr:%.2f%%  Featured:%s\n",
        $tech_score,
        $metrics['sharpe_ratio']      ?? 0,
        $metrics['max_drawdown']      ?? 0,
        $metrics['alpha']             ?? 0,
        $metrics['excess_return_3yr'] ?? 0,
        $is_featured ? 'YES' : 'no'
    );

    $all_results[$fund['id']] = array_merge($metrics, ['tech_score' => $tech_score]);

    $update->execute([
        ':sd' => $metrics['std_dev_1yr'],
        ':md' => $metrics['max_drawdown'],
        ':sh' => $metrics['sharpe_ratio'],
        ':so' => $metrics['sortino_ratio'],
        ':al' => $metrics['alpha'],
        ':be' => $metrics['beta'],
        ':rs' => $metrics['r_squared'],
        ':te' => $metrics['tracking_error'],
        ':ir' => $metrics['info_ratio'],
        ':ex' => $metrics['excess_return_3yr'],
        ':wr' => $metrics['rolling_win_rate'],
        ':ts' => $tech_score,
        ':ft' => $is_featured,
        ':id' => $fund['id'],
    ]);

    sleep(1); // respect MFAPI rate limits
}

echo "\n[" . date('H:i:s') . "] Scoring complete — {$update->rowCount()} funds processed\n";

// ── Pure math helpers ─────────────────────────────────────────────────────────

function parse_nav_series(array $raw): array
{
    $out = [];
    foreach ($raw as $entry) {
        $date = isset($entry['date'])
            ? date('Y-m-d', strtotime(str_replace('-', '/', preg_replace('/(\d{2})-(\d{2})-(\d{4})/', '$3-$2-$1', $entry['date']))))
            : ($entry['nav_date'] ?? null);
        $nav  = (float) ($entry['nav'] ?? $entry['nav_value'] ?? 0);
        if ($date && $nav > 0) $out[$date] = $nav;
    }
    ksort($out); // oldest first
    return $out;
}

function get_benchmark_navs(PDO $db, string $benchmark, string $mfapi_code): array
{
    // Try benchmark_nav table first (populated by fetch-benchmarks.php)
    $stmt = $db->prepare("SELECT nav_date AS date, nav_value AS nav FROM benchmark_nav WHERE benchmark=:bm ORDER BY nav_date ASC");
    $stmt->execute([':bm' => $benchmark]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) >= MIN_DAYS_BETA) return $rows;

    // Not enough — fetch full history from MFAPI proxy
    $data = mf_api_fetch($mfapi_code);
    if ($data && !empty($data['data'])) {
        // Backfill benchmark_nav table for future use
        $ins = $db->prepare(
            "INSERT INTO benchmark_nav (benchmark, nav_date, nav_value, source)
             VALUES (:bm, :dt, :val, 'mfapi') ON DUPLICATE KEY UPDATE nav_value=VALUES(nav_value)"
        );
        foreach ($data['data'] as $entry) {
            $ymd = date('Y-m-d', strtotime(str_replace('-', '/', preg_replace('/(\d{2})-(\d{2})-(\d{4})/', '$3-$2-$1', $entry['date']))));
            try { $ins->execute([':bm' => $benchmark, ':dt' => $ymd, ':val' => (float)$entry['nav']]); } catch (PDOException $e) {}
        }
        return $data['data'];
    }
    return $rows; // return whatever we have
}

function daily_log_returns(array $navs): array
{
    $dates = array_keys($navs);
    $vals  = array_values($navs);
    $rets  = [];
    for ($i = 1; $i < count($vals); $i++) {
        if ($vals[$i - 1] <= 0) continue;
        $rets[$dates[$i]] = log($vals[$i] / $vals[$i - 1]);
    }
    return $rets;
}

function std_dev(array $values): float
{
    $n = count($values);
    if ($n < 2) return 0.0;
    $mean = array_sum($values) / $n;
    $var  = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / ($n - 1);
    return sqrt($var);
}

function cagr_from_navs(array $navs, int $years): ?float
{
    if (count($navs) < 2) return null;
    $dates  = array_keys($navs);
    $latest = end($navs);
    $target = strtotime("-{$years} years", strtotime(end($dates)));
    foreach (array_reverse($dates, true) as $d => $nav) {
        if (strtotime($d) <= $target) {
            if ($nav <= 0) return null;
            $actual_yrs = (strtotime(end($dates)) - strtotime($d)) / (365.25 * 86400);
            if ($actual_yrs <= 0) return null;
            return round((pow($latest / $nav, 1.0 / $actual_yrs) - 1.0) * 100, 2);
        }
    }
    return null;
}

function compute_metrics(array $fund_navs, array $bench_navs, array $fund): array
{
    $m = array_fill_keys(['std_dev_1yr','max_drawdown','sharpe_ratio','sortino_ratio','alpha','beta','r_squared','tracking_error','info_ratio','excess_return_3yr','rolling_win_rate'], null);

    $fund_rets  = daily_log_returns($fund_navs);
    $bench_rets = daily_log_returns($bench_navs);
    $rf_daily   = RISK_FREE_ANNUAL / 100 / 252;

    // ── 1yr std dev & sharpe ─────────────────────────────────────────────────
    $cutoff_1yr  = date('Y-m-d', strtotime('-1 year'));
    $rets_1yr    = array_filter($fund_rets, fn($d) => $d >= $cutoff_1yr, ARRAY_FILTER_USE_KEY);

    if (count($rets_1yr) >= MIN_DAYS_STD) {
        $sd_daily            = std_dev(array_values($rets_1yr));
        $m['std_dev_1yr']    = round($sd_daily * sqrt(252) * 100, 2);

        $excess_daily        = array_map(fn($r) => $r - $rf_daily, array_values($rets_1yr));
        $mean_excess         = array_sum($excess_daily) / count($excess_daily);
        $sd_excess           = std_dev($excess_daily);
        $m['sharpe_ratio']   = $sd_excess > 0 ? round($mean_excess / $sd_excess * sqrt(252), 3) : null;

        // Sortino — only downside deviation
        $downside = array_filter($excess_daily, fn($r) => $r < 0);
        if (count($downside) >= 5) {
            $ds_dev            = std_dev(array_values($downside));
            $m['sortino_ratio'] = $ds_dev > 0 ? round($mean_excess / $ds_dev * sqrt(252), 3) : null;
        }
    }

    // ── Max drawdown (full history) ──────────────────────────────────────────
    if (count($fund_navs) >= 2) {
        $peak = 0.0; $max_dd = 0.0;
        foreach ($fund_navs as $nav) {
            if ($nav > $peak) $peak = $nav;
            $dd = $peak > 0 ? ($nav - $peak) / $peak * 100 : 0;
            if ($dd < $max_dd) $max_dd = $dd;
        }
        $m['max_drawdown'] = round($max_dd, 2); // negative value
    }

    // ── Rolling 1yr win rate ─────────────────────────────────────────────────
    if (count($fund_navs) >= MIN_DAYS_ROLLING) {
        $dates = array_keys($fund_navs);
        $wins  = 0; $total_windows = 0;
        for ($i = 252; $i < count($dates); $i++) {
            $ret = $fund_navs[$dates[$i]] / $fund_navs[$dates[$i - 252]] - 1;
            $total_windows++;
            if ($ret > 0) $wins++;
        }
        $m['rolling_win_rate'] = $total_windows > 0 ? round($wins / $total_windows * 100, 1) : null;
    }

    // ── Benchmark-dependent metrics ───────────────────────────────────────────
    if (!empty($bench_navs) && !empty($bench_rets)) {
        // Align dates
        $common = array_intersect_key($fund_rets, $bench_rets);
        $f_vals = array_values(array_intersect_key($fund_rets,  $common));
        $b_vals = array_values(array_intersect_key($bench_rets, $common));
        $n      = count($f_vals);

        if ($n >= MIN_DAYS_BETA) {
            // Beta
            $f_mean = array_sum($f_vals) / $n;
            $b_mean = array_sum($b_vals) / $n;
            $cov    = 0.0; $b_var = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $cov   += ($f_vals[$i] - $f_mean) * ($b_vals[$i] - $b_mean);
                $b_var += ($b_vals[$i] - $b_mean) ** 2;
            }
            $m['beta'] = $b_var > 0 ? round($cov / $b_var, 3) : null;

            // R-squared
            $f_sd = std_dev($f_vals); $b_sd = std_dev($b_vals);
            if ($f_sd > 0 && $b_sd > 0) {
                $corr         = $b_var > 0 ? ($cov / ($n - 1)) / ($f_sd * $b_sd) : 0;
                $m['r_squared'] = round($corr ** 2 * 100, 1);
            }

            // Tracking error
            $diff_rets       = array_map(fn($f, $b) => $f - $b, $f_vals, $b_vals);
            $m['tracking_error'] = round(std_dev($diff_rets) * sqrt(252) * 100, 3);
        }

        // Alpha & excess return (use 3yr CAGR if available)
        $fund_3yr  = (float)($fund['return_3yr'] ?? 0) ?: cagr_from_navs($fund_navs, 3);
        $bench_3yr = cagr_from_navs($bench_navs, 3);

        if ($fund_3yr !== null && $bench_3yr !== null) {
            $m['excess_return_3yr'] = round((float)$fund_3yr - $bench_3yr, 2);
            $beta_val = $m['beta'] ?? 1.0;
            $m['alpha'] = round((float)$fund_3yr - (RISK_FREE_ANNUAL + $beta_val * ($bench_3yr - RISK_FREE_ANNUAL)), 3);
        }

        // Information ratio
        if ($m['alpha'] !== null && !empty($m['tracking_error']) && $m['tracking_error'] > 0) {
            $m['info_ratio'] = round($m['alpha'] / $m['tracking_error'], 3);
        }
    }

    return $m;
}

function compute_tech_score(array $m, array $fund): int
{
    $score = 0;

    // 3yr CAGR (20 pts)
    $r3 = (float)($fund['return_3yr'] ?? 0);
    $score += match(true) {
        $r3 >= 15 => 20, $r3 >= 12 => 14, $r3 >= 8 => 8, $r3 >= 4 => 4, default => 0
    };

    // 5yr CAGR (10 pts)
    $r5 = (float)($fund['return_5yr'] ?? 0);
    $score += match(true) {
        $r5 >= 15 => 10, $r5 >= 12 => 7, $r5 >= 8 => 4, $r5 > 0 => 2, default => 5 // null=neutral
    };
    if ($fund['return_5yr'] === null) $score += 3; // no data = neutral not penalised

    // Excess return 3yr vs benchmark (15 pts)
    $ex = $m['excess_return_3yr'];
    $score += match(true) {
        $ex === null          => 5,  // neutral
        $ex >= 4              => 15,
        $ex >= 2              => 10,
        $ex >= 0              => 5,
        default               => 0,
    };

    // Alpha (15 pts)
    $al = $m['alpha'];
    $score += match(true) {
        $al === null => 5, $al >= 3 => 15, $al >= 1 => 10, $al >= 0 => 5, default => 0
    };

    // Sharpe ratio (10 pts)
    $sh = $m['sharpe_ratio'];
    $score += match(true) {
        $sh === null => 4, $sh >= 1.5 => 10, $sh >= 1.0 => 7, $sh >= 0.5 => 4, default => 0
    };

    // Max drawdown (10 pts) — value is negative
    $md = $m['max_drawdown'];
    $score += match(true) {
        $md === null          => 4,  // neutral
        $md >= -10            => 10,
        $md >= -20            => 7,
        $md >= -30            => 4,
        default               => 0,
    };

    // Information ratio (10 pts)
    $ir = $m['info_ratio'];
    $score += match(true) {
        $ir === null => 4, $ir >= 1.0 => 10, $ir >= 0.5 => 7, $ir >= 0 => 4, default => 0
    };

    // Expense ratio (5 pts)
    $er = (float)($fund['expense_ratio'] ?? 0);
    $score += match(true) {
        $fund['expense_ratio'] === null => 2,
        $er <= 0.5  => 5, $er <= 1.0 => 3, $er <= 1.5 => 1, default => 0
    };

    // AUM (5 pts)
    $aum = (float)($fund['aum_cr'] ?? 0);
    $score += match(true) {
        $fund['aum_cr'] === null   => 2,
        $aum >= 500 && $aum <= 20000 => 5,
        $aum > 20000               => 4,
        $aum >= 100                => 3,
        default                    => 1,
    };

    return min(100, max(0, $score));
}
