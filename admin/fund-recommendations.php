<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mf-api.php';
require_login();
require_role('admin');

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = ['type'=>'error','message'=>'Invalid request.'];
        header('Location: ' . SITE_URL . '/admin/fund-recommendations.php'); exit;
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'add' || $action === 'edit') {
        $id         = (int)($_POST['id'] ?? 0);
        $fund_name  = trim($_POST['fund_name'] ?? '');
        $scheme_code= trim($_POST['scheme_code'] ?? '') ?: null;
        $fund_house = trim($_POST['fund_house'] ?? '');
        $category   = trim($_POST['category'] ?? '');
        $sub_cat    = trim($_POST['sub_category'] ?? '') ?: null;
        $risk       = $_POST['risk_level'] ?? 'moderate';
        $benchmark  = trim($_POST['benchmark'] ?? '') ?: null;
        $horizon    = max(1,(int)($_POST['min_horizon_yrs'] ?? 3));
        $goals      = implode(',', array_filter($_POST['goal_types'] ?? []));
        $why        = trim($_POST['why_recommended'] ?? '') ?: null;
        $features   = trim($_POST['key_features'] ?? '') ?: null;
        $exp_r      = ($_POST['expense_ratio'] ?? '') !== '' ? (float)$_POST['expense_ratio'] : null;
        $aum        = ($_POST['aum_cr'] ?? '') !== '' ? (float)$_POST['aum_cr'] : null;
        $featured   = isset($_POST['is_featured']) ? 1 : 0;
        $active     = isset($_POST['is_active'])   ? 1 : 0;
        if (!$fund_name || !$fund_house || !$category) {
            $_SESSION['flash'] = ['type'=>'error','message'=>'Fund name, house, and category are required.'];
            header('Location: ' . SITE_URL . '/admin/fund-recommendations.php'); exit;
        }
        try {
            if ($action === 'add') {
                $stmt = $db->prepare("INSERT INTO fund_recommendations (fund_name,scheme_code,fund_house,category,sub_category,risk_level,benchmark,min_horizon_yrs,goal_types,why_recommended,key_features,expense_ratio,aum_cr,is_featured,is_active) VALUES (:fn,:sc,:fh,:cat,:sub,:risk,:bm,:hor,:goals,:why,:feat,:exp,:aum,:isFeat,:isAct)");
                $stmt->execute([':fn'=>$fund_name,':sc'=>$scheme_code,':fh'=>$fund_house,':cat'=>$category,':sub'=>$sub_cat,':risk'=>$risk,':bm'=>$benchmark,':hor'=>$horizon,':goals'=>$goals,':why'=>$why,':feat'=>$features,':exp'=>$exp_r,':aum'=>$aum,':isFeat'=>$featured,':isAct'=>$active]);
                $new_id = (int)$db->lastInsertId();
                if ($scheme_code) mf_refresh_fund($new_id, $scheme_code, $db);
                $_SESSION['flash'] = ['type'=>'success','message'=>'Fund added' . ($scheme_code ? ' — NAV fetched from MFAPI.in.' : '.')];
            } else {
                $stmt = $db->prepare("UPDATE fund_recommendations SET fund_name=:fn,scheme_code=:sc,fund_house=:fh,category=:cat,sub_category=:sub,risk_level=:risk,benchmark=:bm,min_horizon_yrs=:hor,goal_types=:goals,why_recommended=:why,key_features=:feat,expense_ratio=:exp,aum_cr=:aum,is_featured=:isFeat,is_active=:isAct WHERE id=:id");
                $stmt->execute([':fn'=>$fund_name,':sc'=>$scheme_code,':fh'=>$fund_house,':cat'=>$category,':sub'=>$sub_cat,':risk'=>$risk,':bm'=>$benchmark,':hor'=>$horizon,':goals'=>$goals,':why'=>$why,':feat'=>$features,':exp'=>$exp_r,':aum'=>$aum,':isFeat'=>$featured,':isAct'=>$active,':id'=>$id]);
                if ($scheme_code) mf_refresh_fund($id, $scheme_code, $db);
                $_SESSION['flash'] = ['type'=>'success','message'=>'Fund updated' . ($scheme_code ? ' — NAV refreshed.' : '.')];
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $_SESSION['flash'] = ['type'=>'error','message'=>'Database error. Please try again.'];
        }
        header('Location: ' . SITE_URL . '/admin/fund-recommendations.php'); exit;
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare("DELETE FROM fund_recommendations WHERE id=:id")->execute([':id'=>$id]);
            $_SESSION['flash'] = ['type'=>'success','message'=>'Fund removed.'];
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $_SESSION['flash'] = ['type'=>'error','message'=>'Could not delete.'];
        }
        header('Location: ' . SITE_URL . '/admin/fund-recommendations.php'); exit;
    } elseif ($action === 'refresh_nav') {
        $id = (int)($_POST['id'] ?? 0);
        $s2 = $db->prepare("SELECT scheme_code FROM fund_recommendations WHERE id=:id");
        $s2->execute([':id'=>$id]);
        $sc = $s2->fetchColumn();
        if ($sc && mf_refresh_fund($id, (string)$sc, $db)) {
            $_SESSION['flash'] = ['type'=>'success','message'=>'NAV refreshed from MFAPI.in.'];
        } else {
            $_SESSION['flash'] = ['type'=>'error','message'=>'NAV refresh failed — check the scheme code.'];
        }
        header('Location: ' . SITE_URL . '/admin/fund-recommendations.php'); exit;
    } elseif ($action === 'refresh_all') {
        $s3 = $db->prepare("SELECT id, scheme_code FROM fund_recommendations WHERE is_active=1 AND scheme_code IS NOT NULL AND scheme_code != ''");
        $s3->execute();
        $count = 0;
        foreach ($s3->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if (mf_refresh_fund((int)$f['id'], $f['scheme_code'], $db)) $count++;
        }
        $_SESSION['flash'] = ['type'=>'success','message'=>"Refreshed NAV for {$count} fund(s)."];
        header('Location: ' . SITE_URL . '/admin/fund-recommendations.php'); exit;
    }
}

$edit_fund = null;
if (isset($_GET['edit'])) {
    $es = $db->prepare("SELECT * FROM fund_recommendations WHERE id=:id");
    $es->execute([':id'=>(int)$_GET['edit']]);
    $edit_fund = $es->fetch(PDO::FETCH_ASSOC) ?: null;
}
$fs = $db->query("SELECT * FROM fund_recommendations ORDER BY is_featured DESC, is_active DESC, fund_name ASC");
$all_funds = $fs->fetchAll(PDO::FETCH_ASSOC);

$risk_badge  = ['low'=>'badge-green','moderate'=>'badge-gold','high'=>'badge-gold','very_high'=>'badge-muted'];
$risk_opts   = ['low'=>'Low','moderate'=>'Moderate','high'=>'High','very_high'=>'Very High'];
$goal_opts   = ['retirement'=>'Retirement','education'=>'Education','wealth'=>'Wealth Creation','tax_saving'=>'Tax Saving (ELSS)','emergency'=>'Emergency','custom'=>'Custom'];
$cat_opts    = ['Large Cap','Mid Cap','Small Cap','Flexi Cap','ELSS','Debt','Hybrid','Index','Sectoral / Thematic','Liquid / Overnight'];
$bench_opts  = [
    ''                   => '— Select Benchmark —',
    'nifty50'            => 'Nifty 50 TRI',
    'nifty100'           => 'Nifty 100 TRI',
    'nifty_midcap150'    => 'Nifty Midcap 150 TRI',
    'nifty_smallcap250'  => 'Nifty Smallcap 250 TRI',
    'nifty500'           => 'Nifty 500 TRI',
    'crisil_short_dur'   => 'CRISIL Short Duration (Debt)',
    'crisil_gilt'        => 'CRISIL Gilt (Debt)',
];

$page_title = 'Fund Recommendations — Admin';
$ef         = $edit_fund;
$is_edit    = $ef !== null;
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Admin · Advisory</p>
<h1 class="page-title">Fund Recommendations</h1>
<p class="page-subtitle">Curate the mutual funds shown to clients. Scheme codes pull live NAV + CAGR returns from MFAPI.in daily.</p>

<div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;margin-bottom:1.5rem">
  <button class="btn-primary" onclick="openModal()"><i class="bi bi-plus-lg"></i> Add Fund</button>
  <form method="POST" style="display:inline">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="refresh_all">
    <button type="submit" class="btn-outline"><i class="bi bi-arrow-clockwise"></i> Refresh All NAV</button>
  </form>
  <span style="font-size:0.8rem;color:var(--text-secondary);margin-left:auto">
    <?= count($all_funds) ?> funds · Data via <a href="https://mfapi.in" target="_blank" rel="noopener" style="color:var(--lime)">MFAPI.in</a>
  </span>
</div>

<div class="portal-card" style="padding:0;overflow:hidden">
  <div class="table-wrapper" style="border:none">
    <table class="portal-table">
      <thead><tr>
        <th>Fund</th><th>Scheme Code</th><th>Category / Risk</th>
        <th style="text-align:right">Current NAV</th>
        <th style="text-align:right">1yr</th><th style="text-align:right">3yr</th><th style="text-align:right">5yr</th>
        <th style="text-align:right" title="Automated tech score 0–100. is_featured=1 when ≥70">Tech Score</th>
        <th style="text-align:right" title="Risk-adjusted return (Sharpe Ratio)">Sharpe</th>
        <th style="text-align:right" title="Maximum Drawdown">Max DD</th>
        <th style="text-align:right" title="Jensen's Alpha vs benchmark">Alpha</th>
        <th>Refreshed</th><th>Status</th><th>Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($all_funds)): ?>
        <tr><td colspan="10" style="text-align:center;padding:3rem;color:var(--text-secondary)">No funds yet. Click <strong>Add Fund</strong> to get started.</td></tr>
        <?php endif; ?>
        <?php foreach ($all_funds as $f):
          $is_stale = !$f['last_data_refresh'] || strtotime($f['last_data_refresh']) < strtotime('-25 hours');
          $has_code = !empty($f['scheme_code']);
        ?>
        <tr>
          <td>
            <div style="font-weight:500;color:var(--cream)"><?= htmlspecialchars($f['fund_name'], ENT_QUOTES, 'UTF-8') ?><?php if ($f['is_featured']): ?> <span class="badge badge-gold">★</span><?php endif; ?></div>
            <div style="font-size:0.75rem;color:var(--text-secondary)"><?= htmlspecialchars($f['fund_house'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
          </td>
          <td>
            <?php if ($has_code): ?>
              <span style="font-family:'IBM Plex Mono',monospace;font-size:0.8rem;color:var(--lime)"><?= htmlspecialchars($f['scheme_code'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php else: ?>
              <span style="color:var(--danger);font-size:0.75rem"><i class="bi bi-exclamation-triangle"></i> Not set</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge badge-muted"><?= htmlspecialchars($f['category'] ?? '', ENT_QUOTES, 'UTF-8') ?></span><br>
            <span class="badge <?= $risk_badge[$f['risk_level']] ?? 'badge-muted' ?>" style="margin-top:3px"><?= ucfirst(str_replace('_', ' ', $f['risk_level'] ?? '')) ?></span>
          </td>
          <td style="text-align:right;font-family:'IBM Plex Mono',monospace;color:var(--cream)"><?= $f['current_nav'] ? '&#8377;'.number_format((float)$f['current_nav'], 4) : '&mdash;' ?></td>
          <td style="text-align:right;font-family:'IBM Plex Mono',monospace;color:<?= $f['return_1yr'] !== null ? 'var(--bright)' : 'var(--text-muted)' ?>"><?= $f['return_1yr'] !== null ? round((float)$f['return_1yr'],2).'%' : '&mdash;' ?></td>
          <td style="text-align:right;font-family:'IBM Plex Mono',monospace;color:<?= $f['return_3yr'] !== null ? 'var(--bright)' : 'var(--text-muted)' ?>"><?= $f['return_3yr'] !== null ? round((float)$f['return_3yr'],2).'%' : '&mdash;' ?></td>
          <td style="text-align:right;font-family:'IBM Plex Mono',monospace;color:<?= $f['return_5yr'] !== null ? 'var(--bright)' : 'var(--text-muted)' ?>"><?= $f['return_5yr'] !== null ? round((float)$f['return_5yr'],2).'%' : '&mdash;' ?></td>
          <?php /* Tech Score with colour bar */ ?>
          <td style="text-align:right">
            <?php if (isset($f['tech_score']) && $f['tech_score'] !== null): $ts = (int)$f['tech_score']; $tc = $ts >= 70 ? 'var(--bright)' : ($ts >= 50 ? 'var(--gold)' : 'var(--text-muted)'); ?>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:0.82rem;color:<?= $tc ?>;font-weight:600"><?= $ts ?></div>
            <div style="height:3px;width:<?= min(100,$ts) ?>%;background:<?= $tc ?>;border-radius:2px;margin-top:3px;margin-left:auto"></div>
            <?php else: ?><span style="color:var(--text-muted);font-size:0.75rem">—</span><?php endif; ?>
          </td>
          <td style="text-align:right;font-family:'IBM Plex Mono',monospace;font-size:0.8rem;color:<?= isset($f['sharpe_ratio']) && $f['sharpe_ratio'] !== null ? (((float)$f['sharpe_ratio']) >= 1 ? 'var(--bright)' : 'var(--gold)') : 'var(--text-muted)' ?>"><?= isset($f['sharpe_ratio']) && $f['sharpe_ratio'] !== null ? round((float)$f['sharpe_ratio'],2) : '&mdash;' ?></td>
          <td style="text-align:right;font-family:'IBM Plex Mono',monospace;font-size:0.8rem;color:<?= isset($f['max_drawdown']) && $f['max_drawdown'] !== null ? ((float)$f['max_drawdown'] > -20 ? 'var(--bright)' : 'var(--danger)') : 'var(--text-muted)' ?>"><?= isset($f['max_drawdown']) && $f['max_drawdown'] !== null ? round((float)$f['max_drawdown'],1).'%' : '&mdash;' ?></td>
          <td style="text-align:right;font-family:'IBM Plex Mono',monospace;font-size:0.8rem;color:<?= isset($f['alpha']) && $f['alpha'] !== null ? ((float)$f['alpha'] >= 0 ? 'var(--bright)' : 'var(--danger)') : 'var(--text-muted)' ?>"><?= isset($f['alpha']) && $f['alpha'] !== null ? round((float)$f['alpha'],1) : '&mdash;' ?></td>
          <td style="font-size:0.75rem;color:<?= $is_stale ? 'var(--danger)' : 'var(--bright)' ?>;font-family:'IBM Plex Mono',monospace"><?= $f['last_data_refresh'] ? date('d M, H:i', strtotime($f['last_data_refresh'])) : 'Never' ?></td>
          <td><span class="badge <?= $f['is_active'] ? 'badge-green' : 'badge-muted' ?>"><?= $f['is_active'] ? 'Active' : 'Hidden' ?></span></td>
          <td>
            <div style="display:flex;gap:0.4rem">
              <a href="?edit=<?= $f['id'] ?>" class="btn-ghost btn-sm"><i class="bi bi-pencil"></i></a>
              <?php if ($has_code): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="refresh_nav">
                <input type="hidden" name="id" value="<?= $f['id'] ?>">
                <button type="submit" class="btn-ghost btn-sm" title="Refresh NAV"><i class="bi bi-arrow-clockwise"></i></button>
              </form>
              <?php endif; ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('Remove this fund?')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $f['id'] ?>">
                <button type="submit" class="btn-danger btn-sm"><i class="bi bi-trash"></i></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="portal-card" style="margin-top:1.25rem;background:rgba(46,133,64,0.04);border-color:rgba(46,133,64,0.15)">
  <div style="display:flex;gap:0.75rem;align-items:flex-start">
    <i class="bi bi-info-circle" style="color:var(--lime);font-size:1.1rem;flex-shrink:0;margin-top:2px"></i>
    <div style="font-size:0.85rem;color:var(--text-secondary);line-height:1.7">
      <strong style="color:var(--cream)">Finding Scheme Codes:</strong> Use the fund search in the Add Fund form — it searches MFAPI.in live and auto-fills the code.
      Always use the <strong style="color:var(--cream)">Direct Plan – Growth</strong> variant for correct CAGR calculation.
    </div>
  </div>
</div>

<!-- ADD / EDIT MODAL -->
<div id="fundModal" style="display:<?= $is_edit ? 'flex' : 'none' ?>;position:fixed;inset:0;background:rgba(7,14,7,0.88);backdrop-filter:blur(5px);z-index:9000;align-items:flex-start;justify-content:center;padding:1.5rem 1rem;overflow-y:auto">
  <div style="background:var(--surface-1);border:1px solid var(--border);border-radius:16px;width:100%;max-width:660px;padding:2rem;margin:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
      <h2 style="font-family:'Cormorant Garamond',serif;font-size:1.4rem;color:var(--cream)"><?= $is_edit ? 'Edit' : 'Add' ?> Fund Recommendation</h2>
      <button onclick="closeModal()" style="background:none;border:none;color:var(--text-secondary);font-size:1.5rem;cursor:pointer;line-height:1">&times;</button>
    </div>
    <form method="POST" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="<?= $is_edit ? 'edit' : 'add' ?>">
      <?php if ($is_edit): ?><input type="hidden" name="id" value="<?= (int)$ef['id'] ?>"><?php endif; ?>
      <?php if (!$is_edit): ?>
      <div class="form-group" style="position:relative">
        <label class="form-label">Search Fund <span style="color:var(--lime);font-size:0.7rem;font-weight:400">— type 3+ chars to search MFAPI.in live</span></label>
        <input type="text" id="fundSearch" class="form-input" placeholder="e.g. Parag Parikh Flexi Cap Direct Growth" autocomplete="off" spellcheck="false" />
        <div id="fundSuggestions" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 2px);background:var(--surface-1);border:1px solid var(--border);border-radius:8px;max-height:280px;overflow-y:auto;z-index:200;box-shadow:0 8px 28px rgba(0,0,0,0.4)"></div>
      </div>
      <?php endif; ?>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Fund Name *</label>
          <input type="text" name="fund_name" id="inp_fund_name" class="form-input" required value="<?= htmlspecialchars($ef['fund_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" />
        </div>
        <div class="form-group">
          <label class="form-label">AMFI Scheme Code <span style="color:var(--lime);font-size:0.68rem;font-weight:400">(for live NAV)</span></label>
          <input type="text" name="scheme_code" id="inp_scheme_code" class="form-input" placeholder="e.g. 122639" value="<?= htmlspecialchars($ef['scheme_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>" />
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Fund House *</label>
          <input type="text" name="fund_house" id="inp_fund_house" class="form-input" required value="<?= htmlspecialchars($ef['fund_house'] ?? '', ENT_QUOTES, 'UTF-8') ?>" />
        </div>
        <div class="form-group">
          <label class="form-label">Category *</label>
          <input type="text" name="category" class="form-input" list="cat_list" required value="<?= htmlspecialchars($ef['category'] ?? '', ENT_QUOTES, 'UTF-8') ?>" />
          <datalist id="cat_list"><?php foreach ($cat_opts as $c): ?><option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>"><?php endforeach; ?></datalist>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Risk Level</label>
          <select name="risk_level" class="form-select">
            <?php foreach ($risk_opts as $v => $l): ?><option value="<?= $v ?>" <?= ($ef['risk_level'] ?? 'moderate') === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Benchmark <span style="color:var(--lime);font-size:0.68rem;font-weight:400">(for Alpha / Tracking Error)</span></label>
          <select name="benchmark" class="form-select">
            <?php foreach ($bench_opts as $bv => $bl): ?><option value="<?= $bv ?>" <?= ($ef['benchmark'] ?? '') === $bv ? 'selected' : '' ?>><?= htmlspecialchars($bl, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Min Investment Horizon (years)</label>
          <input type="number" name="min_horizon_yrs" class="form-input" min="1" max="30" value="<?= (int)($ef['min_horizon_yrs'] ?? 3) ?>" />
        </div>
        <div class="form-group">
          <label class="form-label">Sub-Category <span style="color:var(--text-muted);font-size:0.68rem;font-weight:400">(optional)</span></label>
          <input type="text" name="sub_category" class="form-input" placeholder="e.g. Large & Mid Cap" value="<?= htmlspecialchars($ef['sub_category'] ?? '', ENT_QUOTES, 'UTF-8') ?>" />
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Suitable Goals</label>
        <div style="display:flex;flex-wrap:wrap;gap:0.6rem;margin-top:0.25rem">
          <?php $sel_goals = explode(',', $ef['goal_types'] ?? ''); ?>
          <?php foreach ($goal_opts as $v => $l): ?>
          <label style="display:flex;align-items:center;gap:0.35rem;font-size:0.85rem;cursor:pointer;color:var(--text-secondary)">
            <input type="checkbox" name="goal_types[]" value="<?= $v ?>" <?= in_array($v, $sel_goals, true) ? 'checked' : '' ?>>
            <?= htmlspecialchars($l, ENT_QUOTES, 'UTF-8') ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Expense Ratio (%)</label>
          <input type="number" name="expense_ratio" step="0.001" class="form-input" placeholder="0.500" value="<?= $ef['expense_ratio'] ?? '' ?>" />
        </div>
        <div class="form-group">
          <label class="form-label">AUM (&#8377; Crore)</label>
          <input type="number" name="aum_cr" step="1" class="form-input" placeholder="5000" value="<?= $ef['aum_cr'] ?? '' ?>" />
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Why We Recommend This Fund</label>
        <textarea name="why_recommended" class="form-textarea" rows="3" placeholder="Explain to clients why this fund suits their goals..."><?= htmlspecialchars($ef['why_recommended'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Key Features / Differentiators</label>
        <textarea name="key_features" class="form-textarea" rows="2" placeholder="e.g. Consistent alpha, low turnover, experienced fund manager..."><?= htmlspecialchars($ef['key_features'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
      <div style="display:flex;gap:2rem;margin-bottom:1.5rem">
        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;font-size:0.875rem;color:var(--text-secondary)">
          <input type="checkbox" name="is_featured" <?= ($ef['is_featured'] ?? 0) ? 'checked' : '' ?>>
          <span><strong style="color:var(--cream)">Featured</strong> — shown at top</span>
        </label>
        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;font-size:0.875rem;color:var(--text-secondary)">
          <input type="checkbox" name="is_active" <?= ($ef['is_active'] ?? 1) ? 'checked' : '' ?>>
          <span><strong style="color:var(--cream)">Active</strong> — visible to clients</span>
        </label>
      </div>
      <div style="display:flex;gap:0.75rem">
        <button type="submit" class="btn-primary"><i class="bi bi-<?= $is_edit ? 'check-lg' : 'plus-lg' ?>"></i> <?= $is_edit ? 'Save Changes' : 'Add Fund' ?></button>
        <?php if ($is_edit): ?><a href="<?= SITE_URL ?>/admin/fund-recommendations.php" class="btn-ghost">Cancel</a>
        <?php else: ?><button type="button" class="btn-ghost" onclick="closeModal()">Cancel</button><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<script>
function openModal()  { document.getElementById('fundModal').style.display = 'flex'; }
function closeModal() { document.getElementById('fundModal').style.display = 'none'; }
document.getElementById('fundModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
(function() {
  var searchEl  = document.getElementById('fundSearch');
  var suggestEl = document.getElementById('fundSuggestions');
  var inpName   = document.getElementById('inp_fund_name');
  var inpCode   = document.getElementById('inp_scheme_code');
  var inpHouse  = document.getElementById('inp_fund_house');
  if (!searchEl) return;
  var timer;
  searchEl.addEventListener('input', function() {
    clearTimeout(timer);
    var q = this.value.trim();
    if (q.length < 3) { suggestEl.style.display = 'none'; return; }
    timer = setTimeout(function() {
      fetch('<?= SITE_URL ?>/ai/mf-search.php?q=' + encodeURIComponent(q))
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (!data.length) { suggestEl.style.display = 'none'; return; }
          suggestEl.innerHTML = data.map(function(d) {
            var ne = d.schemeName.replace(/&/g,'&amp;').replace(/</g,'&lt;');
            var he = d.fundHouse.replace(/&/g,'&amp;').replace(/</g,'&lt;');
            var na = d.schemeName.replace(/"/g,'&quot;');
            var ha = d.fundHouse.replace(/"/g,'&quot;');
            return '<div class="mfs" data-code="'+d.schemeCode+'" data-name="'+na+'" data-house="'+ha+'" style="padding:0.7rem 1rem;cursor:pointer;border-bottom:1px solid var(--border-light)"><div style="color:var(--cream);font-size:0.875rem">'+ne+'</div><div style="font-size:0.7rem;color:var(--text-secondary);margin-top:2px;font-family:\'IBM Plex Mono\',monospace">'+he+' &middot; Code: '+d.schemeCode+'</div></div>';
          }).join('');
          suggestEl.style.display = 'block';
          suggestEl.querySelectorAll('.mfs').forEach(function(el) {
            el.addEventListener('mouseenter', function() { this.style.background='var(--mid-pale)'; });
            el.addEventListener('mouseleave', function() { this.style.background=''; });
            el.addEventListener('click', function() {
              inpName.value=this.dataset.name; inpCode.value=this.dataset.code; inpHouse.value=this.dataset.house;
              searchEl.value=this.dataset.name; suggestEl.style.display='none';
            });
          });
        }).catch(function() { suggestEl.style.display='none'; });
    }, 350);
  });
  document.addEventListener('click', function(e) { if (!searchEl.contains(e.target) && !suggestEl.contains(e.target)) suggestEl.style.display='none'; });
})();
</script>

<?php require_once '../includes/portal-footer.php'; ?>
