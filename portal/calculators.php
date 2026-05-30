<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$page_title = 'Calculators — Prime Financials';
require_once '../includes/portal-header.php';

$groups = [
    [
        'label' => 'Investment Planning',
        'icon'  => '⊕',
        'tools' => [
            ['SIP Calculator',    'sip-calculator.php',    '⊕', 'Plan your monthly SIP or calculate corpus for any goal. Three modes: SIP→Corpus, Goal→SIP, Lumpsum.'],
            ['Goal Planner',      'goals.php',             '◉', 'Set financial goals with target amounts and timelines. Auto-calculates monthly SIP required.'],
            ['NPS Projector',     'nps-projector.php',     '⊘', 'Project your NPS retirement corpus, monthly pension estimate, and tax benefits under 80CCD.'],
            ['Cashflow Modeler',  'cashflow-modeler.php',  '⊛', 'Map your entire financial life from today to age 80 with income growth, expenses, and life events.'],
        ],
    ],
    [
        'label' => 'Tax Tools',
        'icon'  => '⊗',
        'tools' => [
            ['Tax Calculator',    'tax-calculator.php',    '⊗', 'Compare Old vs New tax regime for FY 2024-25. Includes 80C, 80D, 80CCD(1B), HRA deductions.'],
            ['Tax Switch Modeler','tax-modeler.php',       '⊞', 'Calculate LTCG/STCG tax before redeeming or switching funds. Post-July 2024 budget rates.'],
        ],
    ],
    [
        'label' => 'Protection & Analysis',
        'icon'  => '⊝',
        'tools' => [
            ['Insurance Checker', 'insurance-checker.php', '⊝', 'Analyse your term and health insurance gap. Get a personalised protection score.'],
            ['Overlap Analyzer',  'overlap-analyzer.php',  '⊜', 'Detect hidden stock duplication across your mutual funds. Get a diversification score.'],
        ],
    ],
];
?>

<p class="page-eyebrow">Tools</p>
<h1 class="page-title">Calculators</h1>
<p class="page-subtitle">All financial tools in one place — plan, calculate, and optimise your wealth</p>

<?php foreach ($groups as $group): ?>
<div style="margin-bottom:2rem">
  <h2 class="section-header">
    <span style="color:var(--lime);margin-right:0.5rem"><?= $group['icon'] ?></span>
    <?= $group['label'] ?>
  </h2>
  <div class="grid-2">
    <?php foreach ($group['tools'] as [$name, $file, $icon, $desc]): ?>
    <a href="<?= SITE_URL ?>/portal/<?= $file ?>"
       style="text-decoration:none;display:flex;flex-direction:column;background:var(--surface-1);border:1px solid var(--border);border-radius:12px;padding:1.5rem;transition:border-color 0.2s,transform 0.15s,box-shadow 0.2s"
       onmouseover="this.style.borderColor='var(--mid)';this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,0.25)'"
       onmouseout="this.style.borderColor='';this.style.transform='';this.style.boxShadow=''">
      <div style="font-size:1.75rem;margin-bottom:0.75rem;color:var(--lime)"><?= $icon ?></div>
      <div style="font-family:'Cormorant Garamond',serif;font-size:1.15rem;font-weight:600;color:var(--cream);margin-bottom:0.4rem"><?= $name ?></div>
      <div style="font-size:0.845rem;color:var(--text-secondary);line-height:1.6;flex:1"><?= $desc ?></div>
      <div style="margin-top:1rem;font-family:'DM Mono',monospace;font-size:0.65rem;color:var(--lime);letter-spacing:0.15em;text-transform:uppercase">Open →</div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<div class="portal-card" style="text-align:center;margin-top:1rem">
  <p style="color:var(--text-secondary);font-size:0.875rem;margin-bottom:1rem">
    Not sure which tool to use? Our advisor can guide you.
  </p>
  <a href="https://wa.me/<?= WHATSAPP_NUM ?>?text=Hi%2C+I+need+help+choosing+the+right+financial+tool+on+primefin.in"
     class="btn-primary btn-sm" target="_blank" rel="noopener">💬 Ask an Advisor</a>
</div>

<?php require_once '../includes/portal-footer.php'; ?>
