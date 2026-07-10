<?php
declare(strict_types=1);

/**
 * Personalised fund recommendation engine.
 * Scores each admin-curated fund against the logged-in client's risk profile,
 * goals, and investment horizon. Returns funds sorted best-match first.
 */

function get_personalized_funds(PDO $db, int $uid): array
{
    // Load client profile
    $pstmt = $db->prepare("SELECT risk_profile, risk_score, risk_assessed_at, dob, annual_income FROM user_profiles WHERE user_id=:uid ORDER BY id DESC LIMIT 1");
    $pstmt->execute([':uid' => $uid]);
    $profile = $pstmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Load client's active goals
    $gstmt = $db->prepare("SELECT goal_type, target_year FROM goals WHERE user_id=:uid AND status='active' ORDER BY target_year ASC");
    $gstmt->execute([':uid' => $uid]);
    $goals = $gstmt->fetchAll(PDO::FETCH_ASSOC);

    // Load all active funds
    $funds = $db->query(
        "SELECT * FROM fund_recommendations WHERE is_active=1 ORDER BY is_featured DESC, fund_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $scored = [];
    foreach ($funds as $fund) {
        ['score' => $score, 'reasons' => $reasons, 'label' => $label] =
            score_fund_for_client($fund, $profile, $goals);

        $fund['match_score']   = $score;
        $fund['match_reasons'] = $reasons;
        $fund['match_label']   = $label;
        $scored[] = $fund;
    }

    usort($scored, fn($a, $b) => $b['match_score'] <=> $a['match_score']);
    return $scored;
}

function score_fund_for_client(array $fund, array $profile, array $goals): array
{
    $score   = 0;
    $reasons = [];
    $rp      = $profile['risk_profile'] ?? null;

    // ── 1. Risk alignment (40 pts) ────────────────────────────────────────────
    $risk_pts = compute_risk_pts($rp, $fund['risk_level'] ?? 'moderate');
    $score   += $risk_pts;
    if ($risk_pts >= 35) $reasons[] = '✓ Risk Match';
    elseif ($risk_pts >= 20) $reasons[] = '~ Risk Adjacent';

    // ── 2. Goal match (25 pts) ────────────────────────────────────────────────
    $goal_pts = 0;
    $matched_goal = null;
    if (empty($goals)) {
        $goal_pts = 12; // neutral — no goals on file
    } else {
        $fund_goals = explode(',', $fund['goal_types'] ?? '');
        foreach ($goals as $g) {
            if (in_array($g['goal_type'], $fund_goals, true)) {
                $goal_pts    = 25;
                $matched_goal = $g['goal_type'];
                break;
            }
        }
    }
    $score += $goal_pts;
    if ($matched_goal) {
        $goal_labels = [
            'retirement' => 'Retirement', 'education' => 'Education',
            'home' => 'Home', 'marriage' => 'Marriage',
            'vehicle' => 'Vehicle', 'emergency' => 'Emergency', 'custom' => 'Goal',
        ];
        $reasons[] = '✓ Goal: ' . ($goal_labels[$matched_goal] ?? ucfirst($matched_goal));
    }

    // ── 3. Horizon match (20 pts) ─────────────────────────────────────────────
    $horizon_pts = compute_horizon_pts($profile, $goals, (int)($fund['min_horizon_yrs'] ?? 1));
    $score      += $horizon_pts;
    if ($horizon_pts >= 18) {
        $reasons[] = '✓ Fits your horizon';
    } elseif ($horizon_pts >= 10) {
        $reasons[] = '~ Slightly long horizon';
    }

    // ── 4. Performance — 3yr CAGR (10 pts) ────────────────────────────────────
    $r3       = (float)($fund['return_3yr'] ?? 0);
    $perf_pts = match(true) {
        $fund['return_3yr'] === null => 2,
        $r3 >= 15  => 10, $r3 >= 12 => 8, $r3 >= 8 => 5, default => 2
    };
    $score   += $perf_pts;
    if ($fund['return_3yr'] !== null && $r3 >= 12) {
        $reasons[] = '✓ ' . round($r3, 1) . '% 3yr CAGR';
    }

    // ── 5. Tech score bonus (5 pts) ───────────────────────────────────────────
    if (!empty($fund['tech_score']) && (int)$fund['tech_score'] >= 70) {
        $score    += 5;
        $reasons[] = '★ Advisor Featured';
    }

    // ── Label ─────────────────────────────────────────────────────────────────
    $label = match(true) {
        $score >= 75 => 'Strong Match',
        $score >= 50 => 'Good Fit',
        $score >= 25 => 'Consider',
        default      => '',
    };

    return ['score' => $score, 'reasons' => $reasons, 'label' => $label];
}

function compute_risk_pts(?string $client_risk, string $fund_risk): int
{
    // Map fund risk_level to ordinal
    $ord = ['low' => 0, 'moderate' => 1, 'high' => 2, 'very_high' => 3];
    $f   = $ord[$fund_risk] ?? 1;

    return match($client_risk) {
        'conservative' => match($f) { 0 => 40, 1 => 20, 2 => 5,  3 => 0,  default => 20 },
        'moderate'     => match($f) { 0 => 25, 1 => 40, 2 => 20, 3 => 5,  default => 25 },
        'aggressive'   => match($f) { 0 => 5,  1 => 15, 2 => 35, 3 => 40, default => 20 },
        default        => 20, // no profile — neutral
    };
}

function compute_horizon_pts(array $profile, array $goals, int $fund_min_yrs): int
{
    $current_year = (int)date('Y');

    if (!empty($goals)) {
        // Use the nearest active goal as the client's horizon
        $horizons = array_map(fn($g) => (int)$g['target_year'] - $current_year, $goals);
        $horizons = array_filter($horizons, fn($h) => $h > 0);
        $client_horizon = !empty($horizons) ? min($horizons) : 5;
    } else {
        // Infer from risk profile when no goals set
        $client_horizon = match($profile['risk_profile'] ?? null) {
            'conservative' => 3, 'moderate' => 5, 'aggressive' => 7, default => 5
        };
    }

    $gap = $fund_min_yrs - $client_horizon;
    return match(true) {
        $gap <= 0 => 20,  // fund horizon fits within client's timeline
        $gap <= 1 => 12,  // fund needs 1 more year than client has
        $gap <= 2 => 6,   // 2 years over
        default   => 0,
    };
}
