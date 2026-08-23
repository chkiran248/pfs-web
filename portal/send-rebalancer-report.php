<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mailer.php';
require_login();

header('Content-Type: application/json');

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verify_csrf($csrf)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
$type = $body['type'] ?? '';
$data = $body['data'] ?? null;

if (!in_array($type, ['mutual_fund', 'equity'], true) || !is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Invalid data.']);
    exit;
}

$user_email = $_SESSION['user_email'] ?? '';
$user_name  = $_SESSION['user_name']  ?? 'Valued Client';

if (!$user_email || !filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'No valid email address on file.']);
    exit;
}

$is_mf = $type === 'mutual_fund';
$title = $is_mf ? 'Mutual Fund Rebalancer Report' : 'Equity Portfolio Analyser Report';
$date  = date('d M Y');

$disclaimer = $is_mf
    ? 'Mutual Fund investments are subject to market risks. Please read all scheme-related documents carefully before investing. Past performance is not indicative of future results. Prime Financials — AMFI Registered MF Distributor (ARN-137538).'
    : '⚠ Research Note — Not Investment Advice. This analysis is for educational and informational purposes only. Prime Financials is an AMFI Registered Mutual Fund Distributor and is NOT a SEBI Registered Investment Advisor (RIA). This does not constitute investment advice or a recommendation to buy or sell any security. Please consult a SEBI RIA before investing. Investments in securities are subject to market risks.';

// ── Generate report HTML from structured data ────────────────
$G = '#2E8540'; $R = '#c62828'; $Y = '#a07d2a'; $B = '#1565c0'; $DIM = '#5a7a5a'; $BDR = '#c8e6c9';
$MFV = ['hold'=>['l'=>'HOLD','c'=>$G],'buy_more'=>['l'=>'BUY MORE','c'=>$B],'switch'=>['l'=>'SWITCH','c'=>$Y],'sell'=>['l'=>'SELL','c'=>$R]];
$EQV = ['hold'=>['l'=>'HOLD','c'=>$G],'accumulate'=>['l'=>'ACCUMULATE','c'=>$B],'reduce'=>['l'=>'CONSIDER REDUCING','c'=>$Y],'exit'=>['l'=>'REVIEW POSITION','c'=>$R],'review'=>['l'=>'MONITOR CLOSELY','c'=>'#f57f17']];
$SIPL = ['continue'=>'Continue SIP','increase'=>'Increase SIP','decrease'=>'Reduce SIP','stop'=>'Stop SIP'];

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$hcol = $is_mf
    ? (['good'=>$G,'fair'=>$Y,'needs_attention'=>$R][$data['overall_health']??''] ?? $G)
    : $G;

ob_start();
?>
<div style="display:flex;align-items:baseline;gap:0.75rem;margin-bottom:0.4rem">
  <span style="font-size:2rem;font-weight:700;color:<?= $hcol ?>;line-height:1"><?= (int)($data['overall_score']??0) ?>/100</span>
  <span style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.1em;color:<?= $DIM ?>;font-family:monospace">
    <?= h(strtoupper(str_replace('_',' ',$data[$is_mf?'overall_health':'overall_assessment']??''))) ?>
  </span>
</div>
<p style="font-size:0.85rem;color:#0d1f0d;line-height:1.65;margin-bottom:1.25rem"><?= h($data['summary']??'') ?></p>

<?php if ($is_mf): ?>

<?php if (!empty($data['current_allocation']) && !empty($data['target_allocation'])): ?>
<h3 style="color:<?= $G ?>;font-size:0.65rem;text-transform:uppercase;letter-spacing:0.15em;font-family:monospace;margin:1.25rem 0 0.6rem">Allocation Drift</h3>
<table style="width:100%;border-collapse:collapse;margin-bottom:1.25rem">
  <thead><tr style="background:<?= $G ?>;color:#fff">
    <th style="text-align:left;padding:0.45rem 0.7rem;font-size:0.78rem">Category</th>
    <th style="text-align:right;padding:0.45rem 0.7rem;font-size:0.78rem">Current</th>
    <th style="text-align:right;padding:0.45rem 0.7rem;font-size:0.78rem">Target</th>
    <th style="text-align:right;padding:0.45rem 0.7rem;font-size:0.78rem">Drift</th>
  </tr></thead>
  <tbody>
  <?php foreach ([['Equity','equity'],['Debt','debt'],['Others','others']] as [$label,$key]): ?>
    <?php
      $cur  = (float)($data['current_allocation'][$key.'_pct'] ?? 0);
      $tgt  = (float)($data['target_allocation'][$key.'_pct']  ?? 0);
      $diff = round($cur - $tgt, 1);
      $ok   = abs($diff) <= 5;
    ?>
    <tr style="border-bottom:1px solid <?= $BDR ?>">
      <td style="padding:0.45rem 0.7rem"><?= $label ?></td>
      <td style="text-align:right;padding:0.45rem 0.7rem"><?= $cur ?>%</td>
      <td style="text-align:right;padding:0.45rem 0.7rem"><?= $tgt ?>%</td>
      <td style="text-align:right;padding:0.45rem 0.7rem;color:<?= $ok ? $G : $Y ?>"><?= ($diff>0?'+':'') ?><?= $diff ?>% <?= $ok ? '✓' : '⚠' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if (!empty($data['holdings'])): ?>
<h3 style="color:<?= $G ?>;font-size:0.65rem;text-transform:uppercase;letter-spacing:0.15em;font-family:monospace;margin:1.25rem 0 0.6rem">Holdings Analysis</h3>
<?php foreach ($data['holdings'] as $f):
  $v  = $MFV[$f['verdict'] ?? 'hold'] ?? $MFV['hold'];
  $sl = $SIPL[$f['sip_recommendation'] ?? 'continue'] ?? 'Continue SIP';
  $bc = ($f['priority']??'')==='urgent' ? "border-left:3px solid {$R}" : (($f['priority']??'')==='moderate' ? "border-left:3px solid {$Y}" : '');
?>
<div style="border:1px solid <?= $BDR ?>;border-radius:6px;padding:0.8rem;margin-bottom:0.65rem;<?= $bc ?>">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.35rem">
    <strong style="font-size:0.875rem;color:#0d1f0d"><?= h($f['fund_name']??'') ?></strong>
    <span style="font-family:monospace;font-size:0.62rem;padding:0.18rem 0.5rem;border-radius:4px;background:<?= $v['c'] ?>20;color:<?= $v['c'] ?>;border:1px solid <?= $v['c'] ?>;white-space:nowrap"><?= $v['l'] ?></span>
  </div>
  <div style="font-family:monospace;font-size:0.65rem;color:<?= $DIM ?>;margin-bottom:0.35rem">
    Weight: <?= (float)($f['weight_in_portfolio_pct']??0) ?>% · <?= h($sl) ?>
    <?= !empty($f['return_assessment']) ? ' · '.h(str_replace('_',' ',$f['return_assessment'])) : '' ?>
  </div>
  <p style="font-size:0.8rem;color:#0d1f0d;line-height:1.55;margin:0"><?= h($f['reason']??'') ?></p>
  <?php if (!empty($f['action_detail'])): ?>
  <p style="font-size:0.78rem;color:<?= $G ?>;margin:0.3rem 0 0">→ <?= h($f['action_detail']) ?></p>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($data['rebalancing_actions'])): ?>
<h3 style="color:<?= $G ?>;font-size:0.65rem;text-transform:uppercase;letter-spacing:0.15em;font-family:monospace;margin:1.25rem 0 0.6rem">Recommended Actions</h3>
<ol style="padding-left:1.1rem;line-height:1.65;font-size:0.82rem">
<?php foreach ($data['rebalancing_actions'] as $a): ?>
  <li style="margin-bottom:0.5rem">
    <strong><?= h(strtoupper(str_replace('_',' ',$a['action_type']??''))) ?></strong>
    <?php if (!empty($a['from_fund'])): ?> — <?= h($a['from_fund']) ?><?= !empty($a['to_fund']) ? ' → '.h($a['to_fund']) : '' ?><?php endif; ?>
    <br><span style="color:<?= $DIM ?>;font-size:0.78rem"><?= h($a['reason']??'') ?></span>
  </li>
<?php endforeach; ?>
</ol>
<?php endif; ?>

<?php else: /* equity */ ?>

<?php if (!empty($data['sector_breakdown'])): ?>
<h3 style="color:<?= $G ?>;font-size:0.65rem;text-transform:uppercase;letter-spacing:0.15em;font-family:monospace;margin:1.25rem 0 0.6rem">Sector Breakdown</h3>
<table style="width:100%;border-collapse:collapse;margin-bottom:1.25rem">
  <thead><tr style="background:<?= $G ?>;color:#fff">
    <th style="text-align:left;padding:0.45rem 0.7rem;font-size:0.78rem">Sector</th>
    <th style="text-align:right;padding:0.45rem 0.7rem;font-size:0.78rem">Allocation</th>
    <th style="text-align:center;padding:0.45rem 0.7rem;font-size:0.78rem">Status</th>
  </tr></thead>
  <tbody>
  <?php foreach ($data['sector_breakdown'] as $s): ?>
  <tr style="border-bottom:1px solid <?= $BDR ?>">
    <td style="padding:0.45rem 0.7rem"><?= h($s['sector']??'') ?></td>
    <td style="text-align:right;padding:0.45rem 0.7rem"><?= (float)($s['allocation_pct']??0) ?>%</td>
    <td style="text-align:center;padding:0.45rem 0.7rem;color:<?= !empty($s['flag']) ? $Y : $G ?>">
      <?= !empty($s['flag']) ? '⚠ Concentrated' : '✓ OK' ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if (!empty($data['holdings'])): ?>
<h3 style="color:<?= $G ?>;font-size:0.65rem;text-transform:uppercase;letter-spacing:0.15em;font-family:monospace;margin:1.25rem 0 0.6rem">Holdings Analysis</h3>
<?php foreach ($data['holdings'] as $f):
  $v  = $EQV[$f['verdict'] ?? 'hold'] ?? $EQV['hold'];
  $gp = (float)($f['unrealised_gain_pct'] ?? 0);
  $gc = $gp >= 0 ? $G : $R;
?>
<div style="border:1px solid <?= $BDR ?>;border-radius:6px;padding:0.8rem;margin-bottom:0.65rem">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.35rem">
    <strong style="font-size:0.875rem;color:#0d1f0d">
      <?= h($f['stock_name']??'') ?>
      <?= !empty($f['ticker']) ? '<span style="font-family:monospace;font-size:0.72em;color:'.$DIM.'">('.h($f['ticker']).')</span>' : '' ?>
    </strong>
    <span style="font-family:monospace;font-size:0.62rem;padding:0.18rem 0.5rem;border-radius:4px;background:<?= $v['c'] ?>20;color:<?= $v['c'] ?>;border:1px solid <?= $v['c'] ?>;white-space:nowrap"><?= $v['l'] ?></span>
  </div>
  <div style="font-family:monospace;font-size:0.65rem;color:<?= $DIM ?>;margin-bottom:0.35rem">
    Weight: <?= (float)($f['weight_in_equity_pct']??0) ?>% ·
    <span style="color:<?= $gc ?>"><?= $gp >= 0 ? '+' : '' ?><?= $gp ?>% unrealised</span> ·
    <?= (int)($f['holding_period_days']??0) ?>d held
  </div>
  <?php if (!empty($f['tax_note'])): ?>
  <p style="font-size:0.75rem;color:<?= $Y ?>;margin:0 0 0.3rem">💰 <?= h($f['tax_note']) ?></p>
  <?php endif; ?>
  <p style="font-size:0.8rem;color:#0d1f0d;line-height:1.55;margin:0"><?= h($f['reason']??'') ?></p>
  <?php if (!empty($f['action_detail'])): ?>
  <p style="font-size:0.78rem;color:<?= $G ?>;margin:0.3rem 0 0">→ <?= h($f['action_detail']) ?></p>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($data['tax_opportunities'])): ?>
<h3 style="color:<?= $G ?>;font-size:0.65rem;text-transform:uppercase;letter-spacing:0.15em;font-family:monospace;margin:1.25rem 0 0.6rem">💡 Tax Opportunities</h3>
<ul style="padding-left:1.1rem;line-height:1.65;font-size:0.82rem">
<?php foreach ($data['tax_opportunities'] as $t): ?>
  <li style="margin-bottom:0.35rem"><?= h($t['description']??'') ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if (!empty($data['concentration_alerts'])): ?>
<h3 style="color:<?= $Y ?>;font-size:0.65rem;text-transform:uppercase;letter-spacing:0.15em;font-family:monospace;margin:1.25rem 0 0.6rem">⚠ Concentration Alerts</h3>
<ul style="padding-left:1.1rem;line-height:1.65;font-size:0.82rem">
<?php foreach ($data['concentration_alerts'] as $a): ?>
  <li style="margin-bottom:0.35rem"><?= h($a['description']??'') ?><br>
    <span style="color:<?= $DIM ?>;font-size:0.78rem"><?= h($a['suggestion']??'') ?></span></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php endif; /* end if isMF */ ?>
<?php
$report_content = ob_get_clean();

// ── Build full email HTML ────────────────────────────────────
$email_html = '<!DOCTYPE html><html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . h($title) . ' — Prime Financials</title></head>
<body style="margin:0;padding:0;background:#f0f0f0;font-family:Arial,Helvetica,sans-serif">
<div style="max-width:680px;margin:2rem auto">

  <!-- Header -->
  <div style="background:#0c1a0c;padding:1.25rem 1.5rem;border-radius:8px 8px 0 0;display:flex;align-items:center;gap:1rem">
    <img src="' . SITE_URL . '/logo.png" alt="Prime Financials" style="height:42px;object-fit:contain">
    <div>
      <div style="color:#e4f0e4;font-size:1.05rem;font-weight:700">Prime Financials</div>
      <div style="color:#8DC63F;font-size:0.68rem;letter-spacing:0.15em;text-transform:uppercase">AMFI Registered MF Distributor · ARN-137538</div>
    </div>
  </div>

  <!-- Title bar -->
  <div style="background:#1B5E2A;padding:1rem 1.5rem">
    <div style="color:#fff;font-size:1.05rem;font-weight:600">' . h($title) . '</div>
    <div style="color:#a5d6a7;font-size:0.78rem;margin-top:0.2rem">Hi ' . h($user_name) . ' &nbsp;·&nbsp; Generated on ' . $date . '</div>
  </div>

  <!-- Report content -->
  <div style="background:#fff;padding:1.5rem 1.5rem 0.5rem">
    ' . $report_content . '
  </div>

  <!-- Disclaimer -->
  <div style="background:#fff;padding:0 1.5rem 1.5rem">
    <div style="padding:0.875rem 1rem;background:#f5f9f5;border-left:3px solid #2E8540;font-size:0.72rem;color:#555;line-height:1.7">
      ' . h($disclaimer) . '
    </div>
  </div>

  <!-- Footer -->
  <div style="background:#0c1a0c;padding:1rem 1.5rem;border-radius:0 0 8px 8px;text-align:center">
    <div style="color:#8DC63F;font-size:0.78rem">Prime Financials &nbsp;·&nbsp; support@primefin.in &nbsp;·&nbsp; +91 9980001338</div>
    <div style="color:#4e7a4e;font-size:0.68rem;margin-top:0.3rem">This report was generated from your Prime Financials Client Portal &nbsp;·&nbsp; primefin.in</div>
  </div>

</div>
</body></html>';

$plain = "Prime Financials — {$title}\nGenerated: {$date}\n\nHi {$user_name},\n\nYour portfolio analysis report is attached. Please view this email in HTML format for the best experience.\n\n{$disclaimer}\n\nPrime Financials · support@primefin.in · +91 9980001338 · primefin.in";

try {
    send_email($user_email, "[Prime Financials] {$title} — {$date}", $email_html, $plain);
    echo json_encode(['success' => true]);
} catch (\Exception $e) {
    error_log('Rebalancer email error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to send email. Please try again.']);
}
