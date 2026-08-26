<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('admin');

$db  = get_db();
$uid = get_user_id();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save_research') {
    if (!verify_csrf($_POST['csrf_token']??'')) { $error='Invalid request.'; }
    else {
        $rid = (int)($_POST['research_id']??0);
        $is_pub = isset($_POST['is_published'])?1:0;
        $d = [':co'=>trim($_POST['company_name']??''),':tk'=>strtoupper(trim($_POST['ticker_symbol']??'')),':ex'=>$_POST['exchange']??'NSE',':sec'=>trim($_POST['sector']??'')?:null,':cap'=>$_POST['market_cap_type']??null,':rt'=>trim($_POST['report_title']??''),':rc'=>trim($_POST['report_content']??''),':av'=>trim($_POST['analyst_view']??'')?:null,':km'=>trim($_POST['key_metrics']??'')?:null,':price'=>(float)($_POST['price_at_report']??0)?:null,':date'=>$_POST['report_date']??null,':author'=>$uid,':access'=>$_POST['access_level']??'all_users',':pub'=>$is_pub];
        try {
            if ($rid) {
                $d[':id']=$rid;
                // Set published_at if publishing now
                $prev=$db->prepare("SELECT is_published FROM stock_research WHERE id=:id"); $prev->execute([':id'=>$rid]); $was=($prev->fetchColumn()?:0);
                $pub_sql = ($is_pub && !$was) ? ',published_at=NOW()' : '';
                $db->prepare("UPDATE stock_research SET company_name=:co,ticker_symbol=:tk,exchange=:ex,sector=:sec,market_cap_type=:cap,report_title=:rt,report_content=:rc,analyst_view=:av,key_metrics=:km,price_at_report=:price,report_date=:date,access_level=:access,is_published=:pub,updated_at=NOW()$pub_sql WHERE id=:id")->execute($d);
            } else {
                $pub_sql = $is_pub ? ',published_at=NOW()' : '';
                $db->prepare("INSERT INTO stock_research (company_name,ticker_symbol,exchange,sector,market_cap_type,report_title,report_content,analyst_view,key_metrics,price_at_report,report_date,author_id,access_level,is_published$($is_pub?',published_at':'')) VALUES (:co,:tk,:ex,:sec,:cap,:rt,:rc,:av,:km,:price,:date,:author,:access,:pub$($is_pub?',NOW()':''))")->execute($d);
            }
            $_SESSION['flash']=['type'=>'success','message'=>'Research saved.']; header('Location: '.SITE_URL.'/admin/stock-research.php'); exit;
        } catch(PDOException $e){ error_log($e->getMessage()); $error='Could not save: '.$e->getMessage(); }
    }
}

// Simpler save with separate publish_at handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save_research' && $error) {
    // Already handled above
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'toggle_publish') {
    if (verify_csrf($_POST['csrf_token']??'')) {
        $rid=(int)($_POST['research_id']??0);
        $cur=(int)$db->prepare("SELECT is_published FROM stock_research WHERE id=:id")->execute([':id'=>$rid])->fetchColumn();
        // Re-query
        $s=$db->prepare("SELECT is_published FROM stock_research WHERE id=:id"); $s->execute([':id'=>$rid]); $cur=(int)$s->fetchColumn();
        $new=1-$cur;
        $sql="UPDATE stock_research SET is_published=:p".($new?',published_at=NOW()':'')." WHERE id=:id";
        $db->prepare($sql)->execute([':p'=>$new,':id'=>$rid]);
        header('Location: '.SITE_URL.'/admin/stock-research.php'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete_research') {
    if (verify_csrf($_POST['csrf_token']??'')) { $db->prepare("DELETE FROM stock_research WHERE id=:id")->execute([':id'=>(int)($_POST['research_id']??0)]); $_SESSION['flash']=['type'=>'success','message'=>'Deleted.']; header('Location: '.SITE_URL.'/admin/stock-research.php'); exit; }
}

$edit=null;
if (isset($_GET['edit'])) { $s=$db->prepare("SELECT * FROM stock_research WHERE id=:id"); $s->execute([':id'=>(int)$_GET['edit']]); $edit=$s->fetch(); }
$is_new=isset($_GET['new'])||isset($_GET['edit']);
$list=$db->query("SELECT id,company_name,ticker_symbol,sector,market_cap_type,report_date,is_published,views FROM stock_research ORDER BY created_at DESC")->fetchAll();
$cap_labels=['large_cap'=>'Large Cap','mid_cap'=>'Mid Cap','small_cap'=>'Small Cap','micro_cap'=>'Micro Cap'];

$page_title='Stock Research — Prime Financials Admin';
require_once '../includes/admin-header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
  <div><p class="page-eyebrow">Advisory Content</p><h1 class="page-title" style="margin-bottom:0">Stock Research</h1></div>
  <a href="?new=1" class="btn-primary btn-sm">+ New Research</a>
</div>

<?php if ($error): ?><div class="flash-error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

<?php if ($is_new): ?>
<div class="portal-card" style="margin-bottom:1.5rem">
  <div class="card-title"><?= $edit?'Edit Research':'New Stock Research Note' ?></div>
  <form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
    <input type="hidden" name="action" value="save_research">
    <?php if ($edit): ?><input type="hidden" name="research_id" value="<?= $edit['id'] ?>"><?php endif; ?>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Company Name *</label><input class="form-input" type="text" name="company_name" value="<?= htmlspecialchars($edit['company_name']??'',ENT_QUOTES,'UTF-8') ?>" required></div>
      <div class="form-group"><label class="form-label">Ticker *</label><input class="form-input" type="text" name="ticker_symbol" value="<?= htmlspecialchars($edit['ticker_symbol']??'',ENT_QUOTES,'UTF-8') ?>" style="text-transform:uppercase"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Exchange</label><select class="form-select" name="exchange"><option <?= ($edit['exchange']??'')==='NSE'?'selected':'' ?>>NSE</option><option value="BSE" <?= ($edit['exchange']??'')==='BSE'?'selected':'' ?>>BSE</option><option value="NSE_BSE" <?= ($edit['exchange']??'')==='NSE_BSE'?'selected':'' ?>>Both</option></select></div>
      <div class="form-group"><label class="form-label">Sector</label><input class="form-input" type="text" name="sector" value="<?= htmlspecialchars($edit['sector']??'',ENT_QUOTES,'UTF-8') ?>" placeholder="e.g. IT, Banking, Pharma"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Market Cap</label><select class="form-select" name="market_cap_type"><option value="">—</option><?php foreach ($cap_labels as $v=>$l): ?><option value="<?= $v ?>" <?= ($edit['market_cap_type']??'')===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label">Price at Report (₹)</label><input class="form-input" type="number" name="price_at_report" step="0.01" value="<?= $edit['price_at_report']??'' ?>"></div>
    </div>
    <div class="form-group"><label class="form-label">Report Title *</label><input class="form-input" type="text" name="report_title" value="<?= htmlspecialchars($edit['report_title']??'',ENT_QUOTES,'UTF-8') ?>" required></div>
    <div class="form-group"><label class="form-label">Analyst View</label><textarea class="form-textarea" name="analyst_view" rows="4"><?= htmlspecialchars($edit['analyst_view']??'',ENT_QUOTES,'UTF-8') ?></textarea></div>
    <div class="form-group"><label class="form-label">Full Report</label><textarea class="form-textarea" name="report_content" rows="12"><?= htmlspecialchars($edit['report_content']??'',ENT_QUOTES,'UTF-8') ?></textarea></div>
    <div class="form-group"><label class="form-label">Key Metrics (JSON)</label><textarea class="form-textarea" name="key_metrics" rows="3" placeholder='{"PE":"25.4","EPS":"45.2","ROE":"18%","Debt/Equity":"0.2"}'><?= htmlspecialchars($edit['key_metrics']??'',ENT_QUOTES,'UTF-8') ?></textarea></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Report Date</label><input class="form-input" type="date" name="report_date" value="<?= $edit['report_date']??date('Y-m-d') ?>"></div>
      <div class="form-group"><label class="form-label">Access Level</label><select class="form-select" name="access_level"><option value="all_users" <?= ($edit['access_level']??'')==='all_users'?'selected':'' ?>>All Users</option><option value="premium" <?= ($edit['access_level']??'')==='premium'?'selected':'' ?>>Premium</option></select></div>
    </div>
    <label class="check-row" style="margin-bottom:1rem"><input type="checkbox" name="is_published" <?= ($edit['is_published']??0)?'checked':'' ?>> Publish immediately</label>
    <div style="display:flex;gap:0.75rem"><button type="submit" class="btn-primary">Save Research</button><a href="<?= SITE_URL ?>/admin/stock-research.php" class="btn-ghost">Cancel</a></div>
  </form>
</div>
<?php endif; ?>

<div class="portal-card" style="padding:0">
  <div class="table-wrapper" style="border:none;border-radius:12px">
    <table class="portal-table">
      <thead><tr><th>Company</th><th>Sector</th><th>Cap</th><th>Date</th><th>Views</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($list)): ?><tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-secondary)">No research notes yet.</td></tr>
        <?php else: foreach ($list as $r): ?>
        <tr>
          <td><div style="font-weight:500;color:var(--cream)"><?= htmlspecialchars($r['company_name'],ENT_QUOTES,'UTF-8') ?></div><span class="badge badge-muted"><?= htmlspecialchars($r['ticker_symbol'],ENT_QUOTES,'UTF-8') ?></span></td>
          <td style="font-size:0.82rem"><?= htmlspecialchars($r['sector']??'',ENT_QUOTES,'UTF-8') ?></td>
          <td><span class="badge badge-muted"><?= $cap_labels[$r['market_cap_type']]??$r['market_cap_type'] ?></span></td>
          <td style="font-size:0.75rem;color:var(--text-muted)"><?= $r['report_date']?date('d M Y',strtotime($r['report_date'])):'—' ?></td>
          <td style="font-family:'IBM Plex Mono',monospace;font-size:0.82rem"><?= $r['views'] ?></td>
          <td><span class="badge <?= $r['is_published']?'badge-green':'badge-muted' ?>"><?= $r['is_published']?'Published':'Draft' ?></span></td>
          <td><div style="display:flex;gap:0.4rem">
            <a href="?edit=<?= $r['id'] ?>" class="btn-ghost btn-sm">Edit</a>
            <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="toggle_publish"><input type="hidden" name="research_id" value="<?= $r['id'] ?>"><button type="submit" class="btn-outline btn-sm"><?= $r['is_published']?'Unpublish':'Publish' ?></button></form>
            <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="delete_research"><input type="hidden" name="research_id" value="<?= $r['id'] ?>"><button type="submit" class="btn-danger btn-sm" onclick="return confirm('Delete?')">Del</button></form>
          </div></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once '../includes/admin-footer.php'; ?>
