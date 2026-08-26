<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$db  = get_db();
$uid = get_user_id();
$redirect = filter_input(INPUT_GET, 'redirect', FILTER_SANITIZE_URL) ?: 'mutual-funds';

const QUESTIONS = [
    [
        'q'    => 'What is your age bracket?',
        'hint' => 'Younger investors can typically afford more market volatility.',
        'opts' => ['Under 25' => 4, '25 – 35' => 3, '36 – 45' => 2, '46 – 55' => 1, 'Above 55' => 0],
    ],
    [
        'q'    => 'When do you expect to need this money?',
        'hint' => 'A longer horizon allows more time to recover from market downturns.',
        'opts' => ['More than 10 years' => 4, '7 – 10 years' => 3, '5 – 7 years' => 2, '3 – 5 years' => 1, 'Less than 3 years' => 0],
    ],
    [
        'q'    => 'How stable is your income?',
        'hint' => 'Stable income means you can sustain SIPs without interruption.',
        'opts' => ['Stable salaried (govt / MNC)' => 4, 'Salaried with variable bonus' => 3, 'Self-employed (consistent)' => 2, 'Business income (variable)' => 1, 'Irregular / no fixed income' => 0],
    ],
    [
        'q'    => 'What best describes your investment experience?',
        'hint' => 'Prior experience helps you stay calm during market corrections.',
        'opts' => ['Active equity / stock investor' => 4, 'Mutual fund investor (SIP/lumpsum)' => 3, 'FD / RD / PPF / bonds only' => 2, 'Some small savings, nothing formal' => 1, 'No investments yet' => 0],
    ],
    [
        'q'    => 'If your portfolio dropped 25% in 6 months, you would:',
        'hint' => 'Your emotional response to losses is the true test of risk tolerance.',
        'opts' => ['Invest more — great buying opportunity!' => 4, 'Stay calm and hold' => 3, 'Wait and watch for 3 – 6 months' => 2, 'Get worried, reduce exposure' => 1, 'Sell and move to FD / safety' => 0],
    ],
];

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid request. Please try again.'];
        header('Location: ' . SITE_URL . '/portal/risk-assessment.php?redirect=' . urlencode($redirect));
        exit;
    }

    $score = 0;
    foreach (array_keys(QUESTIONS) as $i) {
        $ans = (int)($_POST["q{$i}"] ?? -1);
        if ($ans < 0 || $ans > 4) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please answer all questions.'];
            header('Location: ' . SITE_URL . '/portal/risk-assessment.php?redirect=' . urlencode($redirect));
            exit;
        }
        $score += $ans;
    }

    $profile = match(true) {
        $score <= 6  => 'conservative',
        $score <= 13 => 'moderate',
        default      => 'aggressive',
    };

    try {
        $exists = $db->prepare("SELECT id FROM user_profiles WHERE user_id=:uid ORDER BY id DESC LIMIT 1");
        $exists->execute([':uid' => $uid]);
        if ($exists->fetch()) {
            $db->prepare("UPDATE user_profiles SET risk_profile=:rp, risk_score=:rs, risk_assessed_at=NOW() WHERE user_id=:uid ORDER BY id DESC LIMIT 1")
               ->execute([':rp' => $profile, ':rs' => $score, ':uid' => $uid]);
        } else {
            $db->prepare("INSERT INTO user_profiles (user_id, risk_profile, risk_score, risk_assessed_at) VALUES (:uid,:rp,:rs,NOW())")
               ->execute([':uid' => $uid, ':rp' => $profile, ':rs' => $score]);
        }
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Risk profile saved. Here are your personalised recommendations.'];
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Could not save. Please try again.'];
    }

    $dest = match($redirect) {
        'mutual-funds' => SITE_URL . '/advisory/mutual-funds.php',
        'profile'      => SITE_URL . '/portal/profile.php?tab=risk',
        default        => SITE_URL . '/advisory/mutual-funds.php',
    };
    header('Location: ' . $dest); exit;
}

$page_title = 'Risk Assessment — Prime Financials';
require_once '../includes/portal-header.php';
?>

<style>
.q-card { display:none; animation: fadeIn 0.3s ease; }
.q-card.active { display:block; }
@keyframes fadeIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
.opt-btn {
    display:block; width:100%; text-align:left; padding:0.9rem 1.25rem;
    background:var(--surface-2); border:1.5px solid var(--border);
    border-radius:10px; color:var(--text-secondary); cursor:pointer;
    font-size:0.9rem; margin-bottom:0.5rem; transition:all 0.18s;
    font-family:'Inter',system-ui,sans-serif;
}
.opt-btn:hover  { border-color:var(--mid); color:var(--cream); }
.opt-btn.chosen { border-color:var(--bright); background:rgba(76,175,80,0.1); color:var(--cream); }
.progress-bar { height:4px; background:var(--surface-2); border-radius:2px; overflow:hidden; margin-bottom:2rem; }
.progress-fill { height:100%; background:var(--bright); border-radius:2px; transition:width 0.4s ease; }
.profile-badge {
    display:inline-block; padding:0.4rem 1.2rem; border-radius:20px;
    font-family:'IBM Plex Mono',monospace; font-size:0.8rem; letter-spacing:0.1em; text-transform:uppercase;
}
.badge-conservative { background:rgba(76,175,80,0.15); border:1px solid var(--bright); color:var(--bright); }
.badge-moderate     { background:rgba(201,168,76,0.15); border:1px solid var(--gold);   color:var(--gold);   }
.badge-aggressive   { background:rgba(255,107,53,0.15); border:1px solid #ff6b35;       color:#ff6b35;       }
</style>

<p class="page-eyebrow">My Profile</p>
<h1 class="page-title">Risk Assessment</h1>
<p class="page-subtitle" style="max-width:520px">5 quick questions to understand your investment personality. Takes about 2 minutes.</p>

<div style="max-width:620px">
  <div class="progress-bar"><div class="progress-fill" id="progressFill" style="width:0%"></div></div>
  <div id="stepCounter" style="font-family:'IBM Plex Mono',monospace;font-size:0.7rem;color:var(--lime);letter-spacing:0.15em;margin-top:-1.5rem;margin-bottom:1.5rem">QUESTION 1 OF 5</div>

  <form method="POST" id="quizForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">

    <?php foreach (QUESTIONS as $i => $q): ?>
    <div class="q-card portal-card <?= $i === 0 ? 'active' : '' ?>" id="card<?= $i ?>">
      <div style="font-family:'Cormorant Garamond',serif;font-size:1.3rem;color:var(--cream);margin-bottom:0.4rem;font-weight:600">
        <?= htmlspecialchars($q['q'], ENT_QUOTES, 'UTF-8') ?>
      </div>
      <div style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:1.25rem">
        <?= htmlspecialchars($q['hint'], ENT_QUOTES, 'UTF-8') ?>
      </div>
      <?php foreach ($q['opts'] as $label => $val): ?>
      <button type="button" class="opt-btn" data-q="<?= $i ?>" data-val="<?= $val ?>">
        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
      </button>
      <?php endforeach; ?>
      <input type="hidden" name="q<?= $i ?>" id="ans<?= $i ?>" value="">
    </div>
    <?php endforeach; ?>

    <!-- Result screen (shown before final submit) -->
    <div class="q-card portal-card" id="cardResult">
      <div style="text-align:center;padding:1rem 0">
        <div style="font-family:'IBM Plex Mono',monospace;font-size:0.7rem;color:var(--lime);letter-spacing:0.15em;margin-bottom:1rem">YOUR RISK PROFILE</div>
        <div id="profileBadge" class="profile-badge" style="font-size:1.1rem;margin-bottom:1.5rem"></div>
        <div id="profileScore" style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:0.5rem"></div>
        <div id="profileDesc" style="font-size:0.9rem;color:var(--text-secondary);line-height:1.7;max-width:440px;margin:0 auto 2rem"></div>
        <div style="display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap">
          <button type="button" class="btn-ghost" onclick="restartQuiz()">Retake</button>
          <button type="submit" class="btn-primary" style="min-width:180px">Save & See Recommendations →</button>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
(function () {
  var total = <?= count(QUESTIONS) ?>;
  var current = 0;
  var answers = new Array(total).fill(-1);

  var profiles = {
    conservative: {
      label: 'Conservative',
      cls:   'badge-conservative',
      desc:  'You prefer capital preservation over high returns. We\'ll focus on debt funds, short-duration bonds, and low-volatility hybrid funds that protect your wealth while generating steady income.'
    },
    moderate: {
      label: 'Moderate',
      cls:   'badge-moderate',
      desc:  'You\'re comfortable with some market ups and downs in exchange for better long-term growth. A balanced mix of large-cap equity, hybrid funds, and some debt instruments suits you best.'
    },
    aggressive: {
      label: 'Aggressive',
      cls:   'badge-aggressive',
      desc:  'You have a high risk tolerance and seek maximum long-term wealth creation. Mid-cap, small-cap, flexi-cap, and sectoral funds are well-suited to your growth-oriented profile.'
    }
  };

  document.querySelectorAll('.opt-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var q   = parseInt(this.dataset.q);
      var val = parseInt(this.dataset.val);
      answers[q] = val;
      document.getElementById('ans' + q).value = val;

      // highlight chosen
      this.closest('.q-card').querySelectorAll('.opt-btn').forEach(function (b) { b.classList.remove('chosen'); });
      this.classList.add('chosen');

      setTimeout(function () { goNext(); }, 280);
    });
  });

  function goNext() {
    document.getElementById('card' + current).classList.remove('active');
    current++;
    updateProgress();
    if (current < total) {
      document.getElementById('card' + current).classList.add('active');
    } else {
      showResult();
    }
  }

  function updateProgress() {
    var pct = Math.round((current / (total + 1)) * 100);
    document.getElementById('progressFill').style.width = pct + '%';
    if (current < total) {
      document.getElementById('stepCounter').textContent = 'QUESTION ' + (current + 1) + ' OF ' + total;
    } else {
      document.getElementById('stepCounter').textContent = 'YOUR RESULT';
    }
  }

  function showResult() {
    var score = answers.reduce(function (a, b) { return a + b; }, 0);
    var profileKey = score <= 6 ? 'conservative' : (score <= 13 ? 'moderate' : 'aggressive');
    var p = profiles[profileKey];

    var badge = document.getElementById('profileBadge');
    badge.textContent = p.label;
    badge.className   = 'profile-badge ' + p.cls;
    document.getElementById('profileScore').textContent = 'Score: ' + score + ' / 20';
    document.getElementById('profileDesc').textContent  = p.desc;
    document.getElementById('progressFill').style.width = '100%';

    document.getElementById('cardResult').classList.add('active');
  }

  window.restartQuiz = function () {
    answers = new Array(total).fill(-1);
    document.querySelectorAll('.opt-btn').forEach(function (b) { b.classList.remove('chosen'); });
    document.querySelectorAll('input[type="hidden"][id^="ans"]').forEach(function (i) { i.value = ''; });
    document.getElementById('cardResult').classList.remove('active');
    current = 0;
    updateProgress();
    document.getElementById('card0').classList.add('active');
    document.getElementById('progressFill').style.width = '0%';
    document.getElementById('stepCounter').textContent = 'QUESTION 1 OF ' + total;
  };
})();
</script>

<?php require_once '../includes/portal-footer.php'; ?>
