<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$db  = get_db();
$uid = get_user_id();
$error = '';

// Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_doc') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    elseif (empty($_FILES['document']['name'])) { $error = 'Please select a file.'; }
    else {
        $file     = $_FILES['document'];
        $orig     = $file['name'];
        $ext      = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $doc_name = trim($_POST['doc_name'] ?? '') ?: pathinfo($orig, PATHINFO_FILENAME);
        $doc_type = in_array($_POST['doc_type']??'',['portfolio_statement','tax_statement','kyc','insurance','nomination','other']) ? $_POST['doc_type'] : 'other';

        if (!in_array($ext, ALLOWED_EXTENSIONS)) { $error = 'Invalid file type. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS); }
        elseif ($file['size'] > MAX_UPLOAD_MB * 1024 * 1024) { $error = 'File too large. Max ' . MAX_UPLOAD_MB . 'MB.'; }
        else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);
            $allowed_mimes = ['application/pdf','image/jpeg','image/png','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
            if (!in_array($mime, $allowed_mimes)) { $error = 'Invalid file content type.'; }
            else {
                if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0755, true);
                $uuid_name = bin2hex(random_bytes(16)) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], UPLOAD_PATH . $uuid_name)) {
                    try {
                        $db->prepare("INSERT INTO documents (user_id, uploaded_by, doc_name, doc_type, file_path, file_original_name, file_size, file_mime, shared_with_client, shared_with_advisor) VALUES (:uid,:by,:name,:type,:path,:orig,:size,:mime,1,1)")
                           ->execute([':uid'=>$uid,':by'=>$uid,':name'=>$doc_name,':type'=>$doc_type,':path'=>$uuid_name,':orig'=>$orig,':size'=>$file['size'],':mime'=>$mime]);
                        $_SESSION['flash'] = ['type'=>'success','message'=>'Document uploaded.'];
                        header('Location: ' . SITE_URL . '/portal/documents.php'); exit;
                    } catch (PDOException $e) { error_log($e->getMessage()); @unlink(UPLOAD_PATH . $uuid_name); $error = 'Could not save document record.'; }
                } else { $error = 'Upload failed. Please try again.'; }
            }
        }
    }
}

// Delete (own uploads only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_doc') {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $did = (int)($_POST['did'] ?? 0);
        $stmt = $db->prepare("SELECT file_path FROM documents WHERE id = :id AND user_id = :uid AND uploaded_by = :uid");
        $stmt->execute([':id'=>$did,':uid'=>$uid]);
        $doc = $stmt->fetch();
        if ($doc) {
            @unlink(UPLOAD_PATH . basename($doc['file_path']));
            $db->prepare("DELETE FROM documents WHERE id = :id")->execute([':id'=>$did]);
        }
        $_SESSION['flash'] = ['type'=>'success','message'=>'Document deleted.'];
        header('Location: ' . SITE_URL . '/portal/documents.php'); exit;
    }
}

$stmt = $db->prepare("SELECT * FROM documents WHERE user_id = :uid AND shared_with_client = 1 ORDER BY created_at DESC");
$stmt->execute([':uid' => $uid]);
$docs = $stmt->fetchAll();

$type_labels = ['portfolio_statement'=>'Portfolio Statement','tax_statement'=>'Tax Document','kyc'=>'KYC','insurance'=>'Insurance','nomination'=>'Nomination','other'=>'Other'];
$type_badge  = ['portfolio_statement'=>'badge-green','tax_statement'=>'badge-gold','kyc'=>'badge-muted','insurance'=>'badge-muted','nomination'=>'badge-muted','other'=>'badge-muted'];

function fmt_bytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

$page_title = 'My Documents — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Documents</p>
<h1 class="page-title">My Documents</h1>

<?php if ($error): ?><div class="flash-error"><?= htmlspecialchars($error, ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

<!-- AI Scan Import card -->
<div class="portal-card" style="margin-bottom:1.5rem;background:linear-gradient(135deg,var(--surface-1),rgba(27,94,42,0.12));border-color:rgba(141,198,63,0.25)">
  <div style="display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap">
    <div style="font-size:2.5rem;flex-shrink:0">🤖</div>
    <div style="flex:1;min-width:200px">
      <div style="font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:600;color:var(--cream);margin-bottom:0.25rem">Auto-Import with Primo AI</div>
      <p style="color:var(--text-secondary);font-size:0.875rem;margin-bottom:0.6rem">Upload your CAS statement or broker PDF — Primo extracts and adds all holdings automatically.</p>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;font-size:0.75rem;color:var(--text-muted)">
        <span>① Upload PDF</span><span style="color:var(--border)">→</span>
        <span>② Primo reads it</span><span style="color:var(--border)">→</span>
        <span>③ Review holdings</span><span style="color:var(--border)">→</span>
        <span>④ Add to portfolio</span>
      </div>
    </div>
    <a href="<?= SITE_URL ?>/portal/primo.php" class="btn-primary btn-sm" style="flex-shrink:0">Open Primo Scanner →</a>
  </div>
</div>

<!-- Upload form -->
<div class="portal-card" style="margin-bottom:1.5rem">
  <div style="display:flex;justify-content:space-between;align-items:center;cursor:pointer" onclick="toggleForm('uform','uicon')">
    <div class="card-title" style="margin-bottom:0">↑ Upload Document</div>
    <span id="uicon" style="color:var(--lime);font-size:1.25rem">+</span>
  </div>
  <div id="uform" style="display:<?= $error?'block':'none' ?>;margin-top:1.25rem">
    <form method="POST" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES,'UTF-8') ?>">
      <input type="hidden" name="action" value="upload_doc">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Document Name</label><input class="form-input" type="text" name="doc_name" placeholder="e.g. Consolidated Account Statement Mar 2025"></div>
        <div class="form-group"><label class="form-label">Document Type</label>
          <select class="form-select" name="doc_type">
            <?php foreach ($type_labels as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">File <span style="color:var(--text-muted)">(PDF, JPG, PNG, XLSX — max <?= MAX_UPLOAD_MB ?>MB)</span></label>
        <input class="form-input" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.xlsx" required>
      </div>
      <button type="submit" class="btn-primary btn-sm">Upload Document</button>
    </form>
  </div>
</div>

<!-- Category tabs -->
<?php
$active_cat = $_GET['cat'] ?? 'all';
$grouped = [];
foreach ($docs as $d) { $grouped[$d['doc_type']][] = $d; }
$filtered = $active_cat === 'all' ? $docs : ($grouped[$active_cat] ?? []);
?>
<div style="display:flex;flex-wrap:wrap;gap:0.4rem;margin-bottom:1.25rem">
  <a href="?cat=all" class="<?= $active_cat==='all'?'btn-primary':'btn-ghost' ?> btn-sm">All (<?= count($docs) ?>)</a>
  <?php foreach ($grouped as $cat=>$items): ?>
  <a href="?cat=<?= $cat ?>" class="<?= $active_cat===$cat?'btn-primary':'btn-ghost' ?> btn-sm"><?= $type_labels[$cat]??ucfirst($cat) ?> (<?= count($items) ?>)</a>
  <?php endforeach; ?>
</div>

<?php if (empty($docs)): ?>
<div class="portal-card" style="text-align:center;padding:3rem;color:var(--text-secondary)">
  <div style="font-size:2rem;margin-bottom:1rem">▣</div>
  No documents yet. Your advisor will share portfolio statements, tax reports, and other documents here. You can also upload your own documents above.
</div>
<?php elseif (empty($filtered)): ?>
<div class="portal-card" style="text-align:center;padding:2rem;color:var(--text-secondary)">No documents in this category.</div>
<?php else: ?>
<div class="portal-card" style="padding:0">
  <div class="table-wrapper" style="border:none;border-radius:12px">
    <table class="portal-table">
      <thead><tr><th>Document</th><th>Type</th><th>Size</th><th>Uploaded</th><th>Download</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($filtered as $d): ?>
        <tr>
          <td style="font-weight:500;color:var(--cream)"><?= htmlspecialchars($d['doc_name'], ENT_QUOTES,'UTF-8') ?></td>
          <td><span class="badge <?= $type_badge[$d['doc_type']]??'badge-muted' ?>"><?= $type_labels[$d['doc_type']]??$d['doc_type'] ?></span></td>
          <td style="font-size:0.8rem;color:var(--text-secondary)"><?= fmt_bytes((int)$d['file_size']) ?></td>
          <td style="font-size:0.75rem;color:var(--text-muted)"><?= date('d M Y', strtotime($d['created_at'])) ?></td>
          <td><a href="<?= SITE_URL ?>/portal/download.php?id=<?= $d['id'] ?>" class="btn-outline btn-sm">↓ Download</a></td>
          <td>
            <?php if ($d['uploaded_by'] == $uid): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES,'UTF-8') ?>">
              <input type="hidden" name="action" value="delete_doc">
              <input type="hidden" name="did" value="<?= $d['id'] ?>">
              <button type="submit" class="btn-danger btn-sm" onclick="return confirm('Delete this document?')">Delete</button>
            </form>
            <?php else: ?>
            <span style="font-size:0.75rem;color:var(--text-muted)">Shared by advisor</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>function toggleForm(id,icon){var f=document.getElementById(id),i=document.getElementById(icon),o=f.style.display!=='none';f.style.display=o?'none':'block';i.textContent=o?'+':'−';}</script>

<?php require_once '../includes/portal-footer.php'; ?>
