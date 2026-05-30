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

// Send document to client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'send_doc') {
    if (!verify_csrf($_POST['csrf_token']??'')) { $error='Invalid request.'; }
    elseif (empty($_FILES['document']['name'])) { $error='Please select a file.'; }
    else {
        $client_uid = (int)($_POST['client_id']??0);
        if (!$client_uid) { $error='Please select a client.'; }
        else {
            $file = $_FILES['document'];
            $orig = $file['name'];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $doc_name = trim($_POST['doc_name']??'')?:pathinfo($orig,PATHINFO_FILENAME);
            $doc_type = in_array($_POST['doc_type']??'',['portfolio_statement','tax_statement','kyc','insurance','nomination','other'])?$_POST['doc_type']:'other';

            if (!in_array($ext, ALLOWED_EXTENSIONS)) { $error='Invalid file type.'; }
            elseif ($file['size'] > MAX_UPLOAD_MB*1024*1024) { $error='File too large.'; }
            else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($file['tmp_name']);
                if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0755, true);
                $uuid_name = bin2hex(random_bytes(16)).'.'.$ext;
                if (move_uploaded_file($file['tmp_name'], UPLOAD_PATH.$uuid_name)) {
                    try {
                        $db->prepare("INSERT INTO documents (user_id,uploaded_by,doc_name,doc_type,file_path,file_original_name,file_size,file_mime,shared_with_client,shared_with_advisor) VALUES (:uid,:by,:name,:type,:path,:orig,:size,:mime,1,1)")
                           ->execute([':uid'=>$client_uid,':by'=>$uid,':name'=>$doc_name,':type'=>$doc_type,':path'=>$uuid_name,':orig'=>$orig,':size'=>$file['size'],':mime'=>$mime]);
                        // Get client name for flash
                        $cn=$db->prepare("SELECT full_name FROM users WHERE id=:id"); $cn->execute([':id'=>$client_uid]); $cname=$cn->fetchColumn();
                        $_SESSION['flash']=['type'=>'success','message'=>'Document sent to '.$cname.'.'];
                        header('Location: '.SITE_URL.'/admin/documents.php'); exit;
                    } catch(PDOException $e){ error_log($e->getMessage()); @unlink(UPLOAD_PATH.$uuid_name); $error='DB error.'; }
                } else { $error='Upload failed.'; }
            }
        }
    }
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete_doc') {
    if (verify_csrf($_POST['csrf_token']??'')) {
        $did=(int)($_POST['did']??0);
        $s=$db->prepare("SELECT file_path FROM documents WHERE id=:id"); $s->execute([':id'=>$did]); $doc=$s->fetch();
        if ($doc) { @unlink(UPLOAD_PATH.basename($doc['file_path'])); $db->prepare("DELETE FROM documents WHERE id=:id")->execute([':id'=>$did]); }
        $_SESSION['flash']=['type'=>'success','message'=>'Document deleted.']; header('Location: '.SITE_URL.'/admin/documents.php'); exit;
    }
}

$clients=$db->query("SELECT id,full_name,email FROM users WHERE role='client' AND is_active=1 ORDER BY full_name")->fetchAll();
$docs=$db->query("SELECT d.*, u.full_name as client_name FROM documents d JOIN users u ON u.id=d.user_id ORDER BY d.created_at DESC LIMIT 30")->fetchAll();
$type_labels=['portfolio_statement'=>'Portfolio Statement','tax_statement'=>'Tax Document','kyc'=>'KYC','insurance'=>'Insurance','nomination'=>'Nomination','other'=>'Other'];

function adm_fmt_bytes(int $b):string{ return $b>=1048576?round($b/1048576,1).' MB':($b>=1024?round($b/1024,1).' KB':$b.' B'); }

$page_title='Send Documents — Prime Financials Admin';
require_once '../includes/admin-header.php';
?>
<p class="page-eyebrow">Documents</p>
<h1 class="page-title">Send Documents to Clients</h1>

<?php if ($error): ?><div class="flash-error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

<div class="grid-2" style="align-items:start">
<div class="portal-card">
  <div class="card-title">Send Document</div>
  <form method="POST" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>">
    <input type="hidden" name="action" value="send_doc">
    <div class="form-group"><label class="form-label">Select Client *</label>
      <select class="form-select" name="client_id" required>
        <option value="">— Choose Client —</option>
        <?php foreach ($clients as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name'],ENT_QUOTES,'UTF-8') ?> (<?= htmlspecialchars($c['email'],ENT_QUOTES,'UTF-8') ?>)</option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Document Name</label><input class="form-input" type="text" name="doc_name" placeholder="e.g. Portfolio Statement Q1 2025"></div>
    <div class="form-group"><label class="form-label">Document Type</label>
      <select class="form-select" name="doc_type">
        <?php foreach ($type_labels as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">File * <span style="color:var(--text-muted)">(PDF, JPG, PNG, XLSX — max <?= MAX_UPLOAD_MB ?>MB)</span></label><input class="form-input" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.xlsx" required></div>
    <button type="submit" class="btn-primary">Send to Client ↑</button>
  </form>
</div>

<!-- Recent documents -->
<div class="portal-card" style="padding:0">
  <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--border)"><div class="card-title" style="margin-bottom:0">Recent Sends</div></div>
  <div class="table-wrapper" style="border:none">
    <table class="portal-table">
      <thead><tr><th>Client</th><th>Document</th><th>Type</th><th>Size</th><th>Sent</th><th>Del</th></tr></thead>
      <tbody>
        <?php if (empty($docs)): ?><tr><td colspan="6" style="text-align:center;padding:1.5rem;color:var(--text-secondary)">No documents sent yet.</td></tr>
        <?php else: foreach ($docs as $d): ?>
        <tr>
          <td style="font-size:0.82rem;font-weight:500;color:var(--cream)"><?= htmlspecialchars($d['client_name'],ENT_QUOTES,'UTF-8') ?></td>
          <td style="font-size:0.8rem"><?= htmlspecialchars($d['doc_name'],ENT_QUOTES,'UTF-8') ?></td>
          <td><span class="badge badge-muted" style="font-size:0.55rem"><?= $type_labels[$d['doc_type']]??$d['doc_type'] ?></span></td>
          <td style="font-size:0.75rem;color:var(--text-muted)"><?= adm_fmt_bytes((int)$d['file_size']) ?></td>
          <td style="font-size:0.72rem;color:var(--text-muted)"><?= date('d M Y',strtotime($d['created_at'])) ?></td>
          <td><form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="delete_doc"><input type="hidden" name="did" value="<?= $d['id'] ?>"><button type="submit" class="btn-danger btn-sm" onclick="return confirm('Delete?')">×</button></form></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<?php require_once '../includes/admin-footer.php'; ?>
