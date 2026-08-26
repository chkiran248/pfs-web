<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('admin');

$db = get_db();

// Data freshness queries
$nav_last        = $db->query("SELECT MAX(created_at) FROM nav_history")->fetchColumn();
$news_last       = $db->query("SELECT MAX(created_at) FROM news_items")->fetchColumn();
$fund_last       = $db->query("SELECT MAX(last_data_refresh) FROM fund_recommendations")->fetchColumn();
$stock_last      = $db->query("SELECT MAX(created_at) FROM stock_prices")->fetchColumn();
$ai_drafts       = (int)$db->query("SELECT COUNT(*) FROM market_insights WHERE is_published=0 AND title LIKE '[AI Draft]%'")->fetchColumn();
$nav_count       = (int)$db->query("SELECT COUNT(*) FROM nav_history WHERE nav_date=CURDATE()")->fetchColumn();
$news_count      = (int)$db->query("SELECT COUNT(*) FROM news_items WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$primo_msgs      = (int)$db->query("SELECT COUNT(*) FROM primo_conversations WHERE DATE(created_at)=CURDATE()")->fetchColumn();
// New: benchmark + scoring feeds
$bench_last      = false; try { $bench_last = $db->query("SELECT MAX(nav_date) FROM benchmark_nav")->fetchColumn(); } catch (PDOException $e) {}
$scored_count    = 0; $bench_count = 0;
try {
    $scored_count = (int)$db->query("SELECT COUNT(*) FROM fund_recommendations WHERE tech_score IS NOT NULL")->fetchColumn();
    $bench_count  = (int)$db->query("SELECT COUNT(DISTINCT benchmark) FROM benchmark_nav")->fetchColumn();
} catch (PDOException $e) {}
// Convert bench_last date string to timestamp-like for freshness()
$bench_last_ts = $bench_last ? $bench_last . ' 06:30:00' : false;

function freshness(string|false|null $ts): array {
    if (!$ts) return ['label' => 'Never', 'color' => 'var(--danger)', 'ok' => false];
    $hrs = (time() - strtotime($ts)) / 3600;
    if ($hrs < 1)  return ['label' => 'Just now',          'color' => 'var(--bright)', 'ok' => true];
    if ($hrs < 25) return ['label' => date('d M, g:i a', strtotime($ts)), 'color' => 'var(--bright)', 'ok' => true];
    if ($hrs < 48) return ['label' => 'Yesterday',         'color' => 'var(--gold)', 'ok' => true];
    return ['label' => date('d M Y', strtotime($ts)), 'color' => 'var(--danger)', 'ok' => false];
}

$page_title = 'Data Status — Prime Financials Admin';
require_once '../includes/admin-header.php';
?>

<p class="page-eyebrow">Admin</p>
<h1 class="page-title">Data Pipeline Status</h1>
<p class="page-subtitle">Live view of all automated data feeds — <?= date('d M Y, g:i a') ?></p>

<div class="stats-grid" style="margin-bottom:1.5rem">
  <div class="stat-box"><div class="stat-label">NAV Updates Today</div><div class="stat-value neutral"><?= $nav_count ?></div></div>
  <div class="stat-box"><div class="stat-label">News Items Today</div><div class="stat-value neutral"><?= $news_count ?></div></div>
  <div class="stat-box"><div class="stat-label">AI Drafts Pending</div><div class="stat-value <?= $ai_drafts>0?'gold':'neutral' ?>"><?= $ai_drafts ?></div></div>
  <div class="stat-box"><div class="stat-label">Benchmarks Tracked</div><div class="stat-value <?= $bench_count >= 7 ? 'positive' : ($bench_count > 0 ? 'gold' : 'neutral') ?>"><?= $bench_count ?>/7</div></div>
  <div class="stat-box"><div class="stat-label">Funds Scored</div><div class="stat-value <?= $scored_count > 0 ? 'positive' : 'neutral' ?>"><?= $scored_count ?></div></div>
  <div class="stat-box"><div class="stat-label">Primo Messages Today</div><div class="stat-value neutral"><?= $primo_msgs ?></div></div>
</div>

<div class="portal-card" style="padding:0;margin-bottom:1.5rem">
  <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--border)">
    <div class="card-title" style="margin-bottom:0">Feed Status</div>
  </div>
  <table class="portal-table">
    <thead><tr><th>Feed</th><th>Script</th><th>Schedule</th><th>Last Run</th><th>Status</th></tr></thead>
    <tbody>
      <?php
      $feeds = [
        ['NAV Data (AMFI)',         'fetch-nav.php',          'Daily 6:00am',   freshness($nav_last)],
        ['Benchmark NAV (NSE/MFAPI)','fetch-benchmarks.php',  'Daily 6:30am',   freshness($bench_last_ts)],
        ['Stock Prices (YF)',        'fetch-stock-prices.php', 'Daily 7:00am',   freshness($stock_last)],
        ['News RSS',                 'fetch-news.php',          'Daily 8:00am',   freshness($news_last)],
        ['AI Insight Drafts',        'draft-insights.php',      'Daily 9:00am',   freshness($nav_last)],
        ['Fund Returns',             'fetch-fund-data.php',     'Daily 6:00am',   freshness($fund_last)],
        ['Fund Scoring (Tech Score)','score-funds.php',         'Weekly Sun 3am', freshness($fund_last)],
      ];
      foreach ($feeds as [$name, $script, $schedule, $status]): ?>
      <tr>
        <td style="font-weight:500;color:var(--cream)"><?= $name ?></td>
        <td style="font-family:'IBM Plex Mono',monospace;font-size:0.78rem;color:var(--text-secondary)"><?= $script ?></td>
        <td style="font-size:0.82rem;color:var(--text-secondary)"><?= $schedule ?></td>
        <td style="font-family:'IBM Plex Mono',monospace;font-size:0.82rem;color:<?= $status['color'] ?>"><?= $status['label'] ?></td>
        <td><span class="badge <?= $status['ok']?'badge-green':'badge-muted' ?>"><?= $status['ok']?'OK':'Stale' ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- AI Draft Review -->
<?php if ($ai_drafts > 0): ?>
<div class="flash-info" style="margin-bottom:1.25rem">
  ⚠ You have <strong><?= $ai_drafts ?> AI draft insight<?= $ai_drafts!==1?'s':'' ?></strong> waiting for review before publishing.
  <a href="<?= SITE_URL ?>/admin/insights.php" class="auth-link" style="margin-left:0.5rem">Review drafts →</a>
</div>
<?php endif; ?>

<!-- Manual run instructions -->
<div class="portal-card">
  <div class="card-title">Manual Run (Local Dev)</div>
  <p style="color:var(--text-secondary);font-size:0.875rem;margin-bottom:1rem">On XAMPP, run these via terminal in your project root. On Hostinger, these run automatically via cPanel cron.</p>
  <div style="background:var(--surface-2);border-radius:8px;padding:1rem;font-family:'IBM Plex Mono',monospace;font-size:0.78rem;color:var(--cream);line-height:2">
    php data-fetcher/fetch-nav.php<br>
    php data-fetcher/fetch-benchmarks.php<br>
    php data-fetcher/fetch-stock-prices.php<br>
    php data-fetcher/fetch-news.php<br>
    php data-fetcher/draft-insights.php<br>
    php data-fetcher/fetch-fund-data.php<br>
    php data-fetcher/score-funds.php
  </div>
  <div style="margin-top:1rem">
    <div style="font-family:'IBM Plex Mono',monospace;font-size:0.62rem;color:var(--lime);letter-spacing:0.15em;margin-bottom:0.5rem">HOSTINGER CRON SCHEDULE</div>
    <div style="background:var(--surface-2);border-radius:8px;padding:1rem;font-family:'IBM Plex Mono',monospace;font-size:0.72rem;color:var(--text-secondary);line-height:2">
      0 6 * * * /usr/bin/php /home/u834452319/domains/primefin.in/public_html/data-fetcher/fetch-nav.php >> /home/u834452319/domains/primefin.in/logs/cron.log 2>&1<br>
      30 6 * * * /usr/bin/php /home/u834452319/domains/primefin.in/public_html/data-fetcher/fetch-benchmarks.php >> /home/u834452319/domains/primefin.in/logs/cron.log 2>&1<br>
      0 7 * * * /usr/bin/php /home/u834452319/domains/primefin.in/public_html/data-fetcher/fetch-stock-prices.php >> /home/u834452319/domains/primefin.in/logs/cron.log 2>&1<br>
      0 8 * * * /usr/bin/php /home/u834452319/domains/primefin.in/public_html/data-fetcher/fetch-news.php >> /home/u834452319/domains/primefin.in/logs/cron.log 2>&1<br>
      0 9 * * * /usr/bin/php /home/u834452319/domains/primefin.in/public_html/data-fetcher/draft-insights.php >> /home/u834452319/domains/primefin.in/logs/cron.log 2>&1<br>
      0 6 * * * /usr/bin/php /home/u834452319/domains/primefin.in/public_html/data-fetcher/fetch-fund-data.php >> /home/u834452319/domains/primefin.in/logs/cron.log 2>&1<br>
      0 3 * * 0 /usr/bin/php /home/u834452319/domains/primefin.in/public_html/data-fetcher/score-funds.php >> /home/u834452319/domains/primefin.in/logs/cron.log 2>&1
    </div>
  </div>
  <p style="color:var(--text-secondary);font-size:0.8rem;margin-top:0.75rem">
    <strong style="color:var(--lime)">First run:</strong> After deploying, run <code style="font-family:'IBM Plex Mono',monospace;color:var(--cream)">score-funds.php</code> manually once to populate tech scores for all existing funds.
  </p>
</div>

<?php require_once '../includes/admin-footer.php'; ?>
