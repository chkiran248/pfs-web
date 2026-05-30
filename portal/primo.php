<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();
require_role('client');

$uid         = get_user_id();
$db          = get_db();
$session_key = bin2hex(random_bytes(8));

// Portfolio snapshot for context panel
$snap_stmt = $db->prepare("SELECT COALESCE(SUM(invested_amount),0) as inv, COALESCE(SUM(current_value),0) as cur FROM portfolio_entries WHERE user_id=:uid");
$snap_stmt->execute([':uid' => $uid]);
$snap = $snap_stmt->fetch();
$snap_gain_pct = $snap['inv'] > 0 ? round((($snap['cur'] - $snap['inv']) / $snap['inv']) * 100, 1) : 0;

$goal_count_stmt = $db->prepare("SELECT COUNT(*) FROM goals WHERE user_id=:uid AND status='active'");
$goal_count_stmt->execute([':uid' => $uid]);
$goal_count = (int)$goal_count_stmt->fetchColumn();

$profile_stmt = $db->prepare("SELECT risk_profile FROM user_profiles WHERE user_id=:uid ORDER BY id DESC LIMIT 1");
$profile_stmt->execute([':uid' => $uid]);
$risk_profile = $profile_stmt->fetchColumn() ?: 'Not assessed';

// Recent conversation history for display
$hist_stmt = $db->prepare("SELECT role, message, created_at FROM primo_conversations WHERE user_id=:uid ORDER BY created_at DESC LIMIT 20");
$hist_stmt->execute([':uid' => $uid]);
$history = array_reverse($hist_stmt->fetchAll());

$page_title = 'Primo — AI Financial Assistant';
require_once '../includes/portal-header.php';
?>

<style>
.primo-wrap { display:flex; flex-direction:column; height:calc(100vh - var(--header-height) - 4rem); min-height:500px; }
.primo-header { display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem; background:var(--surface-1); border:1px solid var(--border); border-radius:12px 12px 0 0; flex-shrink:0; }
.primo-logo { display:flex; align-items:center; gap:0.6rem; }
.primo-logo-icon { font-size:1.25rem; color:var(--lime); }
.primo-logo-name { font-family:'Cormorant Garamond',serif; font-size:1.25rem; font-weight:600; color:var(--cream); }
.primo-logo-sub { font-family:'DM Mono',monospace; font-size:0.55rem; color:var(--lime); text-transform:uppercase; letter-spacing:0.15em; display:block; }
.primo-actions { display:flex; gap:0.5rem; align-items:center; }
.context-panel { position:relative; }
.context-toggle { font-size:0.78rem; font-family:'DM Mono',monospace; color:var(--text-secondary); background:var(--surface-2); border:1px solid var(--border); padding:0.3rem 0.7rem; border-radius:5px; cursor:pointer; }
.context-dropdown { display:none; position:absolute; right:0; top:calc(100% + 0.4rem); background:var(--surface-1); border:1px solid var(--border); border-radius:10px; padding:1rem; min-width:260px; z-index:50; box-shadow:0 8px 24px rgba(0,0,0,0.3); }
.context-dropdown.open { display:block; }
.context-row { display:flex; justify-content:space-between; padding:0.35rem 0; border-bottom:1px solid var(--border-light); font-size:0.82rem; }
.context-row:last-child { border-bottom:none; }
.context-label { color:var(--text-secondary); }
.context-value { color:var(--cream); font-family:'DM Mono',monospace; }
.primo-messages { flex:1; overflow-y:auto; padding:1.25rem; background:var(--bg); border-left:1px solid var(--border); border-right:1px solid var(--border); display:flex; flex-direction:column; gap:1rem; scroll-behavior:smooth; }
.msg { display:flex; gap:0.6rem; max-width:85%; }
.msg.user { align-self:flex-end; flex-direction:row-reverse; }
.msg.assistant { align-self:flex-start; }
.msg-avatar { width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.8rem; flex-shrink:0; margin-top:2px; }
.msg.assistant .msg-avatar { background:var(--forest); color:var(--lime); }
.msg.user .msg-avatar { background:var(--mid); color:#fff; }
.msg-bubble { padding:0.75rem 1rem; border-radius:16px; font-size:0.875rem; line-height:1.65; }
.msg.assistant .msg-bubble { background:var(--surface-2); color:var(--text-primary); border-radius:4px 16px 16px 16px; }
.msg.user .msg-bubble { background:var(--mid); color:#fff; border-radius:16px 4px 16px 16px; }
.msg-time { font-family:'DM Mono',monospace; font-size:0.6rem; color:var(--text-muted); margin-top:0.25rem; text-align:right; }
.msg.assistant .msg-time { text-align:left; }
.typing-dots { display:flex; gap:4px; padding:0.5rem 0; }
.typing-dot { width:7px; height:7px; border-radius:50%; background:var(--lime); opacity:0.4; animation:dot-pulse 1.4s infinite; }
.typing-dot:nth-child(2) { animation-delay:0.2s; }
.typing-dot:nth-child(3) { animation-delay:0.4s; }
@keyframes dot-pulse { 0%,60%,100%{opacity:0.3;transform:translateY(0)} 30%{opacity:1;transform:translateY(-4px)} }
.primo-suggestions { padding:0.875rem 1.25rem; display:flex; flex-wrap:wrap; gap:0.5rem; background:var(--surface-1); border-left:1px solid var(--border); border-right:1px solid var(--border); }
.sugg-btn { font-size:0.78rem; padding:0.35rem 0.75rem; border:1px solid var(--border); border-radius:20px; background:var(--surface-2); color:var(--text-secondary); cursor:pointer; transition:all 0.15s; white-space:nowrap; }
.sugg-btn:hover { border-color:var(--mid); color:var(--cream); background:var(--mid-pale); }
.primo-input-area { display:flex; gap:0.6rem; padding:0.875rem 1rem; background:var(--surface-1); border:1px solid var(--border); border-radius:0 0 12px 12px; flex-shrink:0; align-items:flex-end; }
.primo-textarea { flex:1; background:var(--surface-2); border:1px solid var(--border); border-radius:8px; padding:0.6rem 0.875rem; color:var(--text-primary); font-family:'DM Sans',sans-serif; font-size:0.875rem; resize:none; line-height:1.5; max-height:120px; overflow-y:auto; }
.primo-textarea:focus { outline:none; border-color:var(--bright); }
.primo-textarea::placeholder { color:var(--text-muted); }
.primo-send { background:var(--mid); color:#fff; border:none; border-radius:8px; padding:0.6rem 1rem; cursor:pointer; font-size:0.875rem; transition:background 0.15s; flex-shrink:0; }
.primo-send:hover { background:var(--bright); }
.primo-send:disabled { opacity:0.5; cursor:not-allowed; }
.primo-msg-content strong { color:var(--bright); }
.msg.user .primo-msg-content strong { color:#fff; font-weight:600; }
.primo-msg-content code { background:rgba(255,255,255,0.1); padding:0.1em 0.35em; border-radius:3px; font-family:'DM Mono',monospace; font-size:0.82em; }
.primo-msg-content ul { padding-left:1.25rem; margin:0.35rem 0; }
.primo-msg-content li { margin-bottom:0.2rem; }
@media(max-width:768px){.primo-wrap{height:calc(100vh - var(--header-height) - 2rem);}.msg{max-width:95%;}.primo-suggestions{display:none;}}
</style>

<div class="primo-wrap">

  <!-- Header -->
  <div class="primo-header">
    <div class="primo-logo">
      <span class="primo-logo-icon">✦</span>
      <div>
        <span class="primo-logo-name">Primo</span>
        <span class="primo-logo-sub">AI Financial Assistant</span>
      </div>
    </div>
    <div class="primo-actions">
      <!-- Portfolio context panel -->
      <div class="context-panel">
        <button class="context-toggle" onclick="toggleContext()">Portfolio Context ▾</button>
        <div class="context-dropdown" id="contextDropdown">
          <div style="font-family:'DM Mono',monospace;font-size:0.6rem;color:var(--lime);letter-spacing:0.15em;margin-bottom:0.6rem">LIVE DATA — PRIMO KNOWS THIS</div>
          <div class="context-row"><span class="context-label">Invested</span><span class="context-value">₹<?= number_format((float)$snap['inv'], 0) ?></span></div>
          <div class="context-row"><span class="context-label">Current Value</span><span class="context-value">₹<?= number_format((float)$snap['cur'], 0) ?></span></div>
          <div class="context-row"><span class="context-label">Overall Return</span><span class="context-value" style="color:<?= $snap_gain_pct>=0?'var(--bright)':'var(--danger)' ?>"><?= $snap_gain_pct>=0?'+':'' ?><?= $snap_gain_pct ?>%</span></div>
          <div class="context-row"><span class="context-label">Active Goals</span><span class="context-value"><?= $goal_count ?></span></div>
          <div class="context-row"><span class="context-label">Risk Profile</span><span class="context-value"><?= htmlspecialchars(ucfirst($risk_profile),ENT_QUOTES,'UTF-8') ?></span></div>
        </div>
      </div>
      <button class="btn-ghost btn-sm" onclick="clearChat()">Clear Chat</button>
    </div>
  </div>

  <!-- Messages -->
  <div class="primo-messages" id="primoMessages">
    <!-- Welcome message -->
    <div class="msg assistant" id="welcomeMsg">
      <div class="msg-avatar">✦</div>
      <div>
        <div class="msg-bubble primo-msg-content">
          Hi <?= htmlspecialchars(get_user_name(),ENT_QUOTES,'UTF-8') ?>! I'm <strong>Primo</strong>, your AI financial assistant. I have live access to your portfolio, goals, and watchlists.<br><br>
          Ask me anything about your investments, goals, tax planning, or general Indian finance questions. What's on your mind?
        </div>
        <div class="msg-time">Just now</div>
      </div>
    </div>

    <?php foreach ($history as $msg): ?>
    <div class="msg <?= $msg['role'] ?>">
      <div class="msg-avatar"><?= $msg['role']==='user'?'👤':'✦' ?></div>
      <div>
        <div class="msg-bubble primo-msg-content"><?= nl2br(htmlspecialchars($msg['message'],ENT_QUOTES,'UTF-8')) ?></div>
        <div class="msg-time"><?= date('d M, g:i a', strtotime($msg['created_at'])) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Suggested questions (hidden after first message) -->
  <div class="primo-suggestions" id="primoSuggestions">
    <?php foreach ([
      '📊 How is my portfolio performing?',
      '🎯 Am I on track for my goals?',
      '💰 What\'s my estimated tax liability?',
      '📈 Which of my funds is performing best?',
      '🏦 When do my FDs mature?',
      '💡 How can I improve my returns?',
    ] as $q): ?>
    <button class="sugg-btn" onclick="sendSuggestion(this)"><?= htmlspecialchars($q,ENT_QUOTES,'UTF-8') ?></button>
    <?php endforeach; ?>
  </div>

  <!-- Input area -->
  <div class="primo-input-area">
    <button class="btn-ghost btn-sm" onclick="openScanModal()" title="Scan a financial document" style="flex-shrink:0;padding:0.55rem 0.75rem">📎</button>
    <textarea class="primo-textarea" id="primoInput"
              placeholder="Ask Primo anything about your portfolio, goals, or finances…"
              rows="1"></textarea>
    <button class="primo-send" id="primoSend" onclick="sendMessage()">Send ↑</button>
  </div>

</div>

<script>
const API_URL    = '<?= SITE_URL ?>/ai/primo-api.php';
const CLEAR_URL  = '<?= SITE_URL ?>/ai/primo-clear.php';
const SESSION_KEY = '<?= $session_key ?>';
let isSending = false;

function getCsrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function scrollBottom() {
  const el = document.getElementById('primoMessages');
  el.scrollTop = el.scrollHeight;
}

function formatTime(d) {
  return d.toLocaleTimeString('en-IN', {hour:'2-digit',minute:'2-digit'});
}

function renderMarkdown(text) {
  return text
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.*?)\*/g, '<em>$1</em>')
    .replace(/`(.*?)`/g, '<code>$1</code>')
    .replace(/^[-•] (.+)$/gm, '<li>$1</li>')
    .replace(/(<li>[\s\S]*?<\/li>)/g, '<ul>$1</ul>')
    .replace(/\n/g, '<br>');
}

function addBubble(role, content, animated) {
  const wrap = document.getElementById('primoMessages');
  const div  = document.createElement('div');
  div.className = 'msg ' + role;
  const avatar = role === 'user' ? '👤' : '✦';
  const timeStr = formatTime(new Date());
  div.innerHTML = `
    <div class="msg-avatar">${avatar}</div>
    <div>
      <div class="msg-bubble primo-msg-content">${animated?'':renderMarkdown(content)}</div>
      <div class="msg-time">${timeStr}</div>
    </div>`;
  if (animated) {
    div.querySelector('.msg-bubble').innerHTML =
      '<div class="typing-dots"><div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div></div>';
  }
  wrap.appendChild(div);
  scrollBottom();
  return div.querySelector('.msg-bubble');
}

async function sendMessage() {
  const input = document.getElementById('primoInput');
  const text  = input.value.trim();
  if (!text || isSending) return;

  isSending = true;
  document.getElementById('primoSend').disabled = true;
  document.getElementById('primoSuggestions').style.display = 'none';
  input.value = '';
  input.style.height = 'auto';

  addBubble('user', text, false);

  const primoBubble = addBubble('assistant', '', true);

  try {
    const resp = await fetch(API_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrf()
      },
      body: JSON.stringify({ message: text, session_key: SESSION_KEY })
    });

    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      primoBubble.textContent = '⚠ ' + (err.error || 'Something went wrong. Please try again.');
      return;
    }

    const reader  = resp.body.getReader();
    const decoder = new TextDecoder();
    let fullText  = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      const lines = decoder.decode(value, { stream: true }).split('\n');
      for (const line of lines) {
        if (!line.startsWith('data: ')) continue;
        const data = JSON.parse(line.slice(6).trim() || '{}');
        if (data.error) { primoBubble.innerHTML = '⚠ ' + data.error; break; }
        if (data.chunk) { fullText += data.chunk; primoBubble.innerHTML = renderMarkdown(fullText); scrollBottom(); }
      }
    }
  } catch (e) {
    primoBubble.textContent = '⚠ Connection error. Check your internet and try again.';
  } finally {
    isSending = false;
    document.getElementById('primoSend').disabled = false;
  }
}

function sendSuggestion(btn) {
  document.getElementById('primoInput').value = btn.textContent.replace(/^[^\s]+\s/, '');
  sendMessage();
}

function toggleContext() {
  document.getElementById('contextDropdown').classList.toggle('open');
}
document.addEventListener('click', e => {
  if (!e.target.closest('.context-panel')) {
    document.getElementById('contextDropdown').classList.remove('open');
  }
});

async function clearChat() {
  if (!confirm('Clear your Primo conversation history?')) return;
  await fetch(CLEAR_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
    body: JSON.stringify({ session_key: SESSION_KEY })
  });
  const wrap = document.getElementById('primoMessages');
  // Keep only welcome message
  while (wrap.children.length > 1) wrap.removeChild(wrap.lastChild);
  document.getElementById('primoSuggestions').style.display = 'flex';
}

// Auto-resize textarea
const ta = document.getElementById('primoInput');
ta.addEventListener('input', () => {
  ta.style.height = 'auto';
  ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
});
ta.addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});

// Pre-scroll if history loaded
scrollBottom();
</script>

<!-- Scanner Modal -->
<div id="scanModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:1000;align-items:center;justify-content:center;padding:1rem">
  <div style="background:var(--surface-1);border:1px solid var(--border);border-radius:16px;padding:2rem;max-width:600px;width:100%;max-height:90vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
      <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.35rem;color:var(--cream)">📄 Scan Your Statement</h3>
      <button onclick="closeScanModal()" style="background:none;border:none;color:var(--text-secondary);font-size:1.5rem;cursor:pointer">×</button>
    </div>
    <p style="color:var(--text-secondary);font-size:0.875rem;margin-bottom:1.25rem">Upload your CAS statement, broker PDF, or FD certificate. Primo will automatically extract and add your holdings to your portfolio.</p>

    <!-- Dropzone -->
    <div id="scanDropzone" style="border:2px dashed var(--border);border-radius:10px;padding:2rem;text-align:center;cursor:pointer;transition:border-color 0.2s"
         onclick="document.getElementById('scanFile').click()"
         ondragover="event.preventDefault();this.style.borderColor='var(--mid)'"
         ondragleave="this.style.borderColor=''"
         ondrop="handleDrop(event)">
      <div style="font-size:2rem;margin-bottom:0.5rem">📁</div>
      <p style="color:var(--text-secondary);font-size:0.875rem">Drag & drop your PDF here or <span style="color:var(--lime)">browse</span></p>
      <p style="font-size:0.75rem;color:var(--text-muted);margin-top:0.4rem">Supported: CAS (CAMS/KFintech), Zerodha, Groww, FD Certificate · PDF only</p>
      <input type="file" id="scanFile" accept=".pdf,.csv" style="display:none" onchange="uploadForScan(this.files[0])">
    </div>

    <!-- Progress -->
    <div id="scanProgress" style="display:none;text-align:center;padding:1.5rem 0">
      <div style="font-size:2rem;margin-bottom:0.75rem">⏳</div>
      <p id="scanStatus" style="color:var(--text-secondary)">Uploading document...</p>
      <p style="font-size:0.75rem;color:var(--text-muted);margin-top:0.4rem">Primo is reading your document. This may take 15–30 seconds.</p>
    </div>

    <!-- Results preview -->
    <div id="scanResults" style="display:none"></div>

    <div style="display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1.25rem">
      <button class="btn-ghost" onclick="closeScanModal()">Cancel</button>
      <button class="btn-primary" id="confirmBtn" style="display:none" onclick="confirmHoldings()">Add Holdings to Portfolio</button>
    </div>
  </div>
</div>

<script>
const SCAN_URL    = '<?= SITE_URL ?>/ai/scan-document.php';
const CONFIRM_URL = '<?= SITE_URL ?>/ai/confirm-holdings.php';
let scannedDocId  = null;
let scannedData   = [];

function openScanModal()  { document.getElementById('scanModal').style.display='flex'; }
function closeScanModal() { document.getElementById('scanModal').style.display='none'; resetScanModal(); }
function resetScanModal() {
  document.getElementById('scanDropzone').style.display='block';
  document.getElementById('scanProgress').style.display='none';
  document.getElementById('scanResults').style.display='none';
  document.getElementById('confirmBtn').style.display='none';
  scannedDocId=null; scannedData=[];
}
function handleDrop(e) { e.preventDefault(); const f=e.dataTransfer.files[0]; if(f) uploadForScan(f); }

async function uploadForScan(file) {
  if (!file) return;
  document.getElementById('scanDropzone').style.display='none';
  document.getElementById('scanProgress').style.display='block';
  document.getElementById('scanStatus').textContent='Uploading and analysing...';

  const fd=new FormData();
  fd.append('document', file);
  fd.append('csrf_token', getCsrf());

  try {
    const res=await fetch(SCAN_URL,{method:'POST',body:fd});
    const r=await res.json();
    document.getElementById('scanProgress').style.display='none';
    if (!r.success) {
      document.getElementById('scanResults').innerHTML=`<div class="flash-error" style="margin-top:1rem">⚠ ${r.message}</div>`;
      document.getElementById('scanResults').style.display='block';
      return;
    }
    scannedDocId=r.document_id;
    scannedData=r.holdings;
    showHoldingsPreview(r.holdings, r.message);
  } catch(e) {
    document.getElementById('scanProgress').style.display='none';
    document.getElementById('scanResults').innerHTML='<div class="flash-error" style="margin-top:1rem">⚠ Connection error. Please try again.</div>';
    document.getElementById('scanResults').style.display='block';
  }
}

function showHoldingsPreview(holdings, msg) {
  let html=`<div class="flash-success" style="margin:0.75rem 0">✓ ${msg}</div>`;
  html+=`<p style="font-size:0.82rem;color:var(--text-secondary);margin-bottom:0.75rem">Uncheck any holdings you don't want to add:</p>`;
  html+=`<div style="overflow-x:auto"><table class="portal-table"><thead><tr><th></th><th>Fund / Stock</th><th>Type</th><th>Invested</th><th>Value</th></tr></thead><tbody>`;
  holdings.forEach((h,i)=>{
    const inv=h.invested_amount?'₹'+h.invested_amount.toLocaleString('en-IN'):'—';
    const val=h.current_value?'₹'+h.current_value.toLocaleString('en-IN'):'—';
    html+=`<tr><td><input type="checkbox" checked data-i="${i}" class="hcheck" style="accent-color:var(--mid)"></td>
      <td><strong style="color:var(--cream)">${h.fund_name}</strong>${h.fund_house?'<br><small style="color:var(--text-secondary)">'+h.fund_house+'</small>':''}${h.folio_number?'<br><small style="color:var(--text-muted)">Folio: '+h.folio_number+'</small>':''}</td>
      <td><span class="badge badge-muted">${(h.fund_type||'equity').toUpperCase()}</span></td>
      <td style="font-family:'DM Mono',monospace;font-size:0.82rem">${inv}</td>
      <td style="font-family:'DM Mono',monospace;font-size:0.82rem">${val}</td></tr>`;
  });
  html+=`</tbody></table></div>`;
  document.getElementById('scanResults').innerHTML=html;
  document.getElementById('scanResults').style.display='block';
  const btn=document.getElementById('confirmBtn');
  btn.textContent=`Add ${holdings.length} Holdings to Portfolio`;
  btn.style.display='inline-flex';
  document.querySelectorAll('.hcheck').forEach(cb=>cb.addEventListener('change',()=>{
    const checked=document.querySelectorAll('.hcheck:checked').length;
    document.getElementById('confirmBtn').textContent=`Add ${checked} Holdings to Portfolio`;
  }));
}

async function confirmHoldings() {
  const checked=scannedData.map((h,i)=>({...h,include:document.querySelector(`.hcheck[data-i="${i}"]`)?.checked??true}));
  document.getElementById('confirmBtn').disabled=true;
  document.getElementById('confirmBtn').textContent='Adding...';

  const res=await fetch(CONFIRM_URL,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':getCsrf()},body:JSON.stringify({document_id:scannedDocId,holdings:checked})});
  const r=await res.json();

  closeScanModal();
  if (r.success) {
    addBubble('assistant', `✅ Successfully added **${r.added} holding${r.added!==1?'s':''}** to your portfolio from the uploaded document!\n\nYour portfolio dashboard has been updated. Would you like me to analyse your updated portfolio?`, false);
    scrollBottom();
  } else {
    addBubble('assistant','⚠ ' + (r.message||'Could not add holdings. Please try again.'), false);
  }
}
</script>

<?php require_once '../includes/portal-footer.php'; ?>
