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

function make_slug(string $title): string {
    return preg_replace('/-+/','-',preg_replace('/[^a-z0-9]+/','-',strtolower(trim($title))));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save_insight') {
    if (!verify_csrf($_POST['csrf_token']??'')) { $error='Invalid request.'; }
    else {
        $iid=(int)($_POST['insight_id']??0);
        $title=trim($_POST['title']??'');
        $slug=trim($_POST['slug']??'')?:make_slug($title);
        $is_pub=isset($_POST['is_published'])?1:0;
        // Check slug uniqueness
        $sq=$db->prepare("SELECT id FROM market_insights WHERE slug=:slug".($iid?' AND id != :id':''));
        $sp=[':slug'=>$slug]; if($iid) $sp[':id']=$iid;
        $sq->execute($sp);
        if ($sq->fetch()) { $error='Slug already exists. Choose a different one.'; }
        else {
            $d=[':title'=>$title,':slug'=>$slug,':excerpt'=>trim($_POST['excerpt']??'')?:null,':content'=>trim($_POST['content']??''),':cat'=>$_POST['category']??'general',':author'=>$uid,':pub'=>$is_pub];
            try {
                if ($iid) {
                    $d[':id']=$iid;
                    $prev=$db->prepare("SELECT is_published FROM market_insights WHERE id=:id"); $prev->execute([':id'=>$iid]); $was=(int)$prev->fetchColumn();
                    $pub_sql=($is_pub&&!$was)?',published_at=NOW()':'';
                    $db->prepare("UPDATE market_insights SET title=:title,slug=:slug,excerpt=:excerpt,content=:content,category=:cat,is_published=:pub,updated_at=NOW()$pub_sql WHERE id=:id")->execute($d);
                } else {
                    $pub_sql=$is_pub?',published_at' : '';
                    $db->prepare("INSERT INTO market_insights (title,slug,excerpt,content,category,author_id,is_published".($is_pub?',published_at':'').") VALUES (:title,:slug,:excerpt,:content,:cat,:author,:pub".($is_pub?',NOW()':'').")")->execute($d);
                }
                $_SESSION['flash']=['type'=>'success','message'=>'Insight saved.']; header('Location: '.SITE_URL.'/admin/insights.php'); exit;
            } catch(PDOException $e){ error_log($e->getMessage()); $error='Could not save.'; }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'toggle_publish') {
    if (verify_csrf($_POST['csrf_token']??'')) {
        $iid=(int)($_POST['insight_id']??0);
        $s=$db->prepare("SELECT is_published FROM market_insights WHERE id=:id"); $s->execute([':id'=>$iid]); $cur=(int)$s->fetchColumn();
        $new=1-$cur;
        $db->prepare("UPDATE market_insights SET is_published=:p".($new?',published_at=NOW()':'')." WHERE id=:id")->execute([':p'=>$new,':id'=>$iid]);
        header('Location: '.SITE_URL.'/admin/insights.php'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete_insight') {
    if (verify_csrf($_POST['csrf_token']??'')) { $db->prepare("DELETE FROM market_insights WHERE id=:id")->execute([':id'=>(int)($_POST['insight_id']??0)]); $_SESSION['flash']=['type'=>'success','message'=>'Deleted.']; header('Location: '.SITE_URL.'/admin/insights.php'); exit; }
}

$edit=null;
if (isset($_GET['edit'])) { $s=$db->prepare("SELECT * FROM market_insights WHERE id=:id"); $s->execute([':id'=>(int)$_GET['edit']]); $edit=$s->fetch(); }
$is_new=isset($_GET['new'])||isset($_GET['edit']);
$list=$db->query("SELECT id,title,category,is_published,views,published_at,created_at FROM market_insights ORDER BY created_at DESC")->fetchAll();
$cats=['market_update'=>'Market Update','tax_tips'=>'Tax Tips','fund_analysis'=>'Fund Analysis','nps'=>'NPS','insurance'=>'Insurance','stocks'=>'Stocks','general'=>'General'];

$page_title='Market Insights — Prime Financials Admin';
require_once '../includes/admin-header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
  <div><p class="page-eyebrow">Advisory Content</p><h1 class="page-title" style="margin-bottom:0">Market Insights</h1></div>
  <a href="?new=1" class="btn-primary btn-sm">+ New Insight</a>
</div>

<?php if ($error): ?><div class="flash-error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

<?php if ($is_new): ?>
<div class="portal-card" style="margin-bottom:1.5rem">
  <div class="card-title"><?= $edit?'Edit Insight':'New Market Insight' ?></div>
  <form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
    <input type="hidden" name="action" value="save_insight">
    <?php if ($edit): ?><input type="hidden" name="insight_id" value="<?= $edit['id'] ?>"><?php endif; ?>
    <div class="form-group"><label class="form-label">Title *</label><input class="form-input" type="text" name="title" id="ins_title" value="<?= htmlspecialchars($edit['title']??'',ENT_QUOTES,'UTF-8') ?>" required oninput="autoSlug()"></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Slug (URL)</label><input class="form-input" type="text" name="slug" id="ins_slug" value="<?= htmlspecialchars($edit['slug']??'',ENT_QUOTES,'UTF-8') ?>" placeholder="auto-generated-from-title"></div>
      <div class="form-group"><label class="form-label">Category</label><select class="form-select" name="category"><?php foreach ($cats as $v=>$l): ?><option value="<?= $v ?>" <?= ($edit['category']??'')===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="form-group"><label class="form-label">Excerpt <span style="color:var(--text-muted)">(shown in card grid)</span></label><textarea class="form-textarea" name="excerpt" rows="2"><?= htmlspecialchars($edit['excerpt']??'',ENT_QUOTES,'UTF-8') ?></textarea></div>
    <div class="form-group"><label class="form-label">Full Content *</label><textarea class="form-textarea" name="content" rows="16" required><?= htmlspecialchars($edit['content']??'',ENT_QUOTES,'UTF-8') ?></textarea></div>
    <label class="check-row" style="margin-bottom:1rem"><input type="checkbox" name="is_published" <?= ($edit['is_published']??0)?'checked':'' ?>> Publish immediately</label>
    <div style="display:flex;gap:0.75rem"><button type="submit" class="btn-primary">Save Insight</button><a href="<?= SITE_URL ?>/admin/insights.php" class="btn-ghost">Cancel</a></div>
  </form>
</div>
<script>
function autoSlug(){
  var t=document.getElementById('ins_title').value;
  document.getElementById('ins_slug').value=t.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
}
</script>
<?php endif; ?>

<div class="portal-card" style="padding:0">
  <div class="table-wrapper" style="border:none;border-radius:12px">
    <table class="portal-table">
      <thead><tr><th>Title</th><th>Category</th><th>Views</th><th>Published</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($list)): ?><tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-secondary)">No insights yet.</td></tr>
        <?php else: foreach ($list as $ins): ?>
        <tr>
          <td style="max-width:300px"><div style="font-weight:500;color:var(--cream);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($ins['title'],ENT_QUOTES,'UTF-8') ?></div></td>
          <td><span class="badge badge-muted"><?= $cats[$ins['category']]??$ins['category'] ?></span></td>
          <td style="font-family:'DM Mono',monospace;font-size:0.82rem"><?= $ins['views'] ?></td>
          <td style="font-size:0.75rem;color:var(--text-muted)"><?= $ins['published_at']?date('d M Y',strtotime($ins['published_at'])):'—' ?></td>
          <td><span class="badge <?= $ins['is_published']?'badge-green':'badge-muted' ?>"><?= $ins['is_published']?'Published':'Draft' ?></span></td>
          <td><div style="display:flex;gap:0.4rem">
            <a href="?edit=<?= $ins['id'] ?>" class="btn-ghost btn-sm">Edit</a>
            <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="toggle_publish"><input type="hidden" name="insight_id" value="<?= $ins['id'] ?>"><button type="submit" class="btn-outline btn-sm"><?= $ins['is_published']?'Unpublish':'Publish' ?></button></form>
            <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="delete_insight"><input type="hidden" name="insight_id" value="<?= $ins['id'] ?>"><button type="submit" class="btn-danger btn-sm" onclick="return confirm('Delete?')">Del</button></form>
          </div></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once '../includes/admin-footer.php'; ?>
