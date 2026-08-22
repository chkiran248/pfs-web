<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();

$db  = get_db();
$cat = trim($_GET['cat'] ?? '');

$where  = ['is_published = 1'];
$params = [];
if ($cat) { $where[] = 'category = :cat'; $params[':cat'] = $cat; }

$stmt = $db->prepare("SELECT * FROM market_insights WHERE " . implode(' AND ', $where) . " ORDER BY published_at DESC");
$stmt->execute($params);
$insights = $stmt->fetchAll();

$cats = ['market_update'=>'Market Update','tax_tips'=>'Tax Tips','fund_analysis'=>'Fund Analysis','nps'=>'NPS','insurance'=>'Insurance','stocks'=>'Stocks','general'=>'General'];
$cat_badge = ['market_update'=>'badge-green','tax_tips'=>'badge-gold','fund_analysis'=>'badge-green','nps'=>'badge-gold','insurance'=>'badge-muted','stocks'=>'badge-muted','general'=>'badge-muted'];

$page_title = 'Market Insights — Prime Financials';
require_once '../includes/portal-header.php';
?>

<p class="page-eyebrow">Advisory</p>
<h1 class="page-title">Market Insights</h1>
<p class="page-subtitle">Research, commentary and analysis from Prime Financials advisors</p>

<!-- Category filters -->
<div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1.5rem">
  <a href="<?= SITE_URL ?>/advisory/insights.php" class="<?= !$cat?'btn-primary':'btn-ghost' ?> btn-sm">All</a>
  <?php foreach ($cats as $v=>$l): ?>
  <a href="?cat=<?= $v ?>" class="<?= $cat===$v?'btn-primary':'btn-ghost' ?> btn-sm"><?= $l ?></a>
  <?php endforeach; ?>
</div>

<?php if (empty($insights)): ?>
<div class="portal-card" style="text-align:center;padding:3rem;color:var(--text-secondary)">
  <div style="font-size:2rem;margin-bottom:1rem">▦</div>
  No insights published yet<?= $cat?' in this category':'' ?>. Check back soon.
</div>
<?php else: ?>
<div class="grid-3">
  <?php foreach ($insights as $ins): ?>
  <div class="portal-card">
    <div style="margin-bottom:0.6rem"><span class="badge <?= $cat_badge[$ins['category']]??'badge-muted' ?>"><?= $cats[$ins['category']]??ucfirst($ins['category']) ?></span></div>
    <div style="font-family:'Cormorant Garamond',serif;font-size:1.15rem;font-weight:600;color:var(--cream);line-height:1.3;margin-bottom:0.5rem"><?= htmlspecialchars($ins['title'],ENT_QUOTES,'UTF-8') ?></div>
    <?php if ($ins['excerpt']): ?><div style="font-size:0.82rem;color:var(--text-secondary);line-height:1.6;margin-bottom:0.75rem"><?= htmlspecialchars(mb_substr($ins['excerpt'],0,150),ENT_QUOTES,'UTF-8') ?><?= strlen($ins['excerpt'])>150?'…':'' ?></div><?php endif; ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:auto">
      <span style="font-size:0.72rem;color:var(--text-muted);font-family:'DM Mono',monospace"><?= $ins['published_at']?date('d M Y',strtotime($ins['published_at'])):'—' ?> · <?= $ins['views'] ?> views</span>
      <button onclick="openInsight(<?= $ins['id'] ?>)" class="btn-ghost btn-sm">Read →</button>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Inline read modal -->
<div id="insight-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:1000;overflow-y:auto;padding:2rem 1rem">
  <div style="max-width:720px;margin:0 auto;background:var(--surface-1);border:1px solid var(--border);border-radius:16px;padding:2rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
      <span class="badge" id="modal-cat"></span>
      <button onclick="closeInsight()" style="background:none;border:none;color:var(--text-secondary);font-size:1.5rem;cursor:pointer">×</button>
    </div>
    <h2 style="font-family:'Cormorant Garamond',serif;font-size:1.8rem;color:var(--cream);margin-bottom:0.5rem" id="modal-title"></h2>
    <div style="font-size:0.75rem;color:var(--text-muted);font-family:'DM Mono',monospace;margin-bottom:1.5rem" id="modal-date"></div>
    <div id="modal-content" style="color:var(--text-secondary);line-height:1.8;font-size:0.9rem"></div>
  </div>
</div>

<?php
// Preload insight data as JSON
$insight_map = [];
foreach ($insights as $ins) {
    $insight_map[$ins['id']] = ['title'=>$ins['title'],'cat'=>$cats[$ins['category']]??$ins['category'],'date'=>$ins['published_at']?date('d M Y',strtotime($ins['published_at'])):'','content'=>$ins['content']];
}
?>
<script>
var insights = <?= json_encode($insight_map) ?>;
function openInsight(id){
  var i=insights[id]; if(!i) return;
  document.getElementById('modal-title').textContent=i.title;
  document.getElementById('modal-cat').textContent=i.cat;
  document.getElementById('modal-date').textContent=i.date;
  document.getElementById('modal-content').innerHTML=i.content.replace(/\n/g,'<br>');
  document.getElementById('insight-modal').style.display='block';
  document.body.style.overflow='hidden';
}
function closeInsight(){document.getElementById('insight-modal').style.display='none';document.body.style.overflow='';}
document.getElementById('insight-modal').addEventListener('click',function(e){if(e.target===this)closeInsight();});
</script>
<?php endif; ?>

<?php require_once '../includes/portal-footer.php'; ?>
