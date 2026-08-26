<?php
declare(strict_types=1);
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/subscription.php';
require_login();
require_role('client');
require_premium('primo_ai');

$_primo_plan       = get_user_plan(get_user_id());
$_primo_is_premium = in_array($_primo_plan, ['active_investor','premium']);

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

$page_title = 'PrimoAI — AI Financial Assistant';
require_once '../includes/portal-header.php';
?>

<style>
.primo-wrap { display:flex; flex-direction:column; height:calc(100vh - var(--header-height) - 4rem); min-height:500px; }
.primo-header { display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem; background:var(--surface-1); border:1px solid var(--border); border-radius:12px 12px 0 0; flex-shrink:0; }
.primo-logo { display:flex; align-items:center; gap:0.6rem; }
.primo-logo-icon { font-size:1.25rem; color:var(--lime); }
.primo-logo-name { font-family:'Cormorant Garamond',serif; font-size:1.25rem; font-weight:600; color:var(--cream); }
.primo-logo-sub { font-family:'IBM Plex Mono',monospace; font-size:0.55rem; color:var(--lime); text-transform:uppercase; letter-spacing:0.15em; display:block; }
.primo-actions { display:flex; gap:0.5rem; align-items:center; }
.context-panel { position:relative; }
.context-toggle { font-size:0.78rem; font-family:'IBM Plex Mono',monospace; color:var(--text-secondary); background:var(--surface-2); border:1px solid var(--border); padding:0.3rem 0.7rem; border-radius:5px; cursor:pointer; }
.context-dropdown { display:none; position:absolute; right:0; top:calc(100% + 0.4rem); background:var(--surface-1); border:1px solid var(--border); border-radius:10px; padding:1rem; min-width:260px; z-index:50; box-shadow:0 8px 24px rgba(0,0,0,0.3); }
.context-dropdown.open { display:block; }
.context-row { display:flex; justify-content:space-between; padding:0.35rem 0; border-bottom:1px solid var(--border-light); font-size:0.82rem; }
.context-row:last-child { border-bottom:none; }
.context-label { color:var(--text-secondary); }
.context-value { color:var(--cream); font-family:'IBM Plex Mono',monospace; }
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
.msg-time { font-family:'IBM Plex Mono',monospace; font-size:0.6rem; color:var(--text-muted); margin-top:0.25rem; text-align:right; }
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
.primo-textarea { flex:1; background:var(--surface-2); border:1px solid var(--border); border-radius:8px; padding:0.6rem 0.875rem; color:var(--text-primary); font-family:'Inter',sans-serif; font-size:0.875rem; resize:none; line-height:1.5; max-height:120px; overflow-y:auto; }
.primo-textarea:focus { outline:none; border-color:var(--bright); }
.primo-textarea::placeholder { color:var(--text-muted); }
.primo-send { background:var(--mid); color:#fff; border:none; border-radius:8px; padding:0.6rem 1rem; cursor:pointer; font-size:0.875rem; transition:background 0.15s; flex-shrink:0; }
.primo-send:hover { background:var(--bright); }
.primo-send:disabled { opacity:0.5; cursor:not-allowed; }
.primo-msg-content strong { color:var(--bright); }
.msg.user .primo-msg-content strong { color:#fff; font-weight:600; }
.primo-msg-content code { background:rgba(255,255,255,0.1); padding:0.1em 0.35em; border-radius:3px; font-family:'IBM Plex Mono',monospace; font-size:0.82em; }
.primo-msg-content ul { padding-left:1.25rem; margin:0.2rem 0; }
.primo-msg-content ol { padding-left:1.25rem; margin:0.2rem 0; }
.primo-msg-content li { margin-bottom:0.15rem; }
.primo-msg-content br + br { display:none; }  /* prevent double <br> gaps */
@media(max-width:768px){
  .primo-wrap{
    height:calc(100dvh - var(--header-height) - 72px - env(safe-area-inset-bottom,0px));
    min-height:400px;
  }
  .msg{max-width:95%;}
  .primo-suggestions{display:none;}
  .primo-footer-strip{display:none;}
  .primo-textarea{font-size:1rem;}
  .context-dropdown{min-width:220px;}
  .primo-header{padding:0.75rem 1rem;}
  .primo-messages{padding:0.875rem;}
}
</style>

<div class="primo-wrap">

  <!-- Header -->
  <div class="primo-header">
    <div class="primo-logo">
      <span class="primo-logo-icon">✦</span>
      <div>
        <span class="primo-logo-name">PrimoAI</span>
        <span class="primo-logo-sub">AI Financial Assistant</span>
      </div>
    </div>
    <div class="primo-actions">
      <!-- Portfolio context panel -->
      <div class="context-panel">
        <button class="context-toggle" onclick="toggleContext()">Portfolio Context ▾</button>
        <div class="context-dropdown" id="contextDropdown">
          <div style="font-family:'IBM Plex Mono',monospace;font-size:0.6rem;color:var(--lime);letter-spacing:0.15em;margin-bottom:0.6rem">LIVE DATA — PRIMO KNOWS THIS</div>
          <div class="context-row"><span class="context-label">Invested</span><span class="context-value"><?= format_inr((float)$snap['inv']) ?></span></div>
          <div class="context-row"><span class="context-label">Current Value</span><span class="context-value"><?= format_inr((float)$snap['cur']) ?></span></div>
          <div class="context-row"><span class="context-label">Overall Return</span><span class="context-value" style="color:<?= $snap_gain_pct>=0?'var(--bright)':'var(--danger)' ?>"><?= $snap_gain_pct>=0?'+':'' ?><?= $snap_gain_pct ?>%</span></div>
          <div class="context-row"><span class="context-label">Active Goals</span><span class="context-value"><?= $goal_count ?></span></div>
          <div class="context-row"><span class="context-label">Risk Profile</span><span class="context-value"><?= htmlspecialchars(ucfirst($risk_profile),ENT_QUOTES,'UTF-8') ?></span></div>
        </div>
      </div>
      <button class="btn-ghost btn-sm" onclick="showClearModal()">Clear Chat</button>
    </div>
  </div>

  <!-- Messages -->
  <div class="primo-messages" id="primoMessages">
    <!-- Welcome message -->
    <div class="msg assistant" id="welcomeMsg">
      <div class="msg-avatar">✦</div>
      <div>
        <div class="msg-bubble primo-msg-content" id="welcomeBubble"
             data-md="Hi **<?= htmlspecialchars(get_user_name(),ENT_QUOTES,'UTF-8') ?>**! I'm **PrimoAI**, your AI financial assistant. I have live access to your portfolio, goals, and watchlists.

Ask me anything about your investments, goals, tax planning, or general Indian finance questions. What's on your mind?"></div>
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
  <?php if (!$_primo_is_premium): ?>
  <div style="padding:0.6rem 1.25rem;background:rgba(239,83,80,0.07);border-top:1px solid rgba(239,83,80,0.2);text-align:center;font-size:0.78rem;color:var(--text-secondary)">
    🔒 PrimoAI requires an <strong style="color:var(--lime)">Active Investor</strong> or <strong style="color:var(--gold)">Prime Member</strong> plan.
    <a href="<?= ONBOARDING_URL ?>?utm_source=primo_gate&utm_medium=portal" target="_blank" rel="noopener" style="color:var(--lime);margin:0 0.4rem">🚀 Invest — Get Free Access</a> ·
    <a href="<?= SITE_URL ?>/portal/pricing.php" style="color:var(--lime)">View Plans →</a>
  </div>
  <?php endif; ?>
  <div class="primo-input-area">
    <button class="btn-ghost btn-sm" onclick="openScanModal()" title="Scan a financial document" style="flex-shrink:0;padding:0.55rem 0.75rem">📎</button>
    <textarea class="primo-textarea" id="primoInput"
              placeholder="Ask PrimoAI anything about your portfolio, goals, or finances…"
              rows="1"></textarea>
    <button class="primo-send" id="primoSend" onclick="sendMessage()" <?= !$_primo_is_premium ? 'disabled' : '' ?>>Send ↑</button>
  </div>

  <!-- Footer strip -->
  <div class="primo-footer-strip">
    <a href="<?= ONBOARDING_URL ?>?utm_source=primo_footer&utm_medium=portal" target="_blank" rel="noopener">🚀 Start Investing</a>
    <span class="sep">·</span>
    <a href="<?= INSURANCE_URL ?>?utm_source=primo_footer&utm_medium=portal" target="_blank" rel="noopener">🛡 Get Insurance</a>
    <span class="sep">·</span>
    <a href="<?= CALENDLY_URL ?>" target="_blank" rel="noopener">📅 Book a Session</a>
    <span class="sep">·</span>
    <a href="https://wa.me/<?= WHATSAPP_NUM ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
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

function renderMarkdown(raw) {
  // Pre-process: strip Claude's excessive blank lines before anything else
  let t = raw.trim()
    .replace(/\r\n/g, '\n')             // normalise line endings
    .replace(/[ \t]+\n/g, '\n')         // strip trailing spaces on lines
    .replace(/\n{3,}/g, '\n\n');        // collapse 3+ blank lines → 1

  // 1. Code blocks — preserve before other replacements
  const codeBlocks = [];
  t = t.replace(/```[\w]*\n?([\s\S]*?)```/g, (_, code) => {
    codeBlocks.push(code.trim());
    return '\x00CODE' + (codeBlocks.length - 1) + '\x00';
  });

  // 2. Markdown TABLES — must happen before line-by-line transforms
  // Matches: header row | separator row | data rows
  t = t.replace(/^(\|.+\|)\n\|[-| :]+\|\n((?:\|.+\|\n?)*)/gm, (match, header, body) => {
    const th = header.split('|').filter(c => c.trim()).map(c =>
      `<th style="padding:0.5rem 0.75rem;text-align:left;font-family:'IBM Plex Mono',monospace;font-size:0.65rem;letter-spacing:0.08em;font-weight:500;white-space:nowrap">${c.trim()}</th>`
    ).join('');
    const rows = body.trim().split('\n').map(row => {
      const tds = row.split('|').filter(c => c !== '' && c !== undefined)
        .map(c => `<td style="padding:0.45rem 0.75rem;border-bottom:1px solid var(--border-light);font-size:0.82rem">${c.trim()}</td>`).join('');
      return `<tr style="transition:background 0.1s" onmouseover="this.style.background='var(--mid-pale)'" onmouseout="this.style.background=''">${tds}</tr>`;
    }).join('');
    return `<div style="overflow-x:auto;margin:0.6rem 0;border-radius:8px;border:1px solid var(--border)"><table style="width:100%;border-collapse:collapse"><thead><tr style="background:var(--forest)">${th}</tr></thead><tbody>${rows}</tbody></table></div>`;
  });

  // 3. Inline code
  t = t.replace(/`([^`]+)`/g, '<code style="background:rgba(255,255,255,0.09);padding:0.1em 0.45em;border-radius:3px;font-family:\'IBM Plex Mono\',monospace;font-size:0.84em">$1</code>');

  // 4. Bold + italic
  t = t.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
  t = t.replace(/\*\*(.+?)\*\*/g, '<strong style="color:var(--cream)">$1</strong>');
  t = t.replace(/\*([^*\n]+?)\*/g, '<em>$1</em>');

  // 5. Headers
  t = t.replace(/^### (.+)$/gm, '<div style="font-family:\'Cormorant Garamond\',serif;font-size:1rem;font-weight:600;color:var(--cream);margin:0.65rem 0 0.2rem">$1</div>');
  t = t.replace(/^## (.+)$/gm,  '<div style="font-family:\'Cormorant Garamond\',serif;font-size:1.15rem;font-weight:600;color:var(--cream);margin:0.75rem 0 0.25rem;padding-bottom:0.25rem;border-bottom:1px solid var(--border-light)">$1</div>');
  t = t.replace(/^# (.+)$/gm,   '<div style="font-family:\'Cormorant Garamond\',serif;font-size:1.25rem;font-weight:700;color:var(--cream);margin:0.8rem 0 0.3rem">$1</div>');

  // 6. Horizontal rule
  t = t.replace(/^---+$/gm, '<hr style="border:none;border-top:1px solid var(--border);margin:0.75rem 0">');

  // 7. Blockquotes
  t = t.replace(/^> (.+)$/gm, '<div style="border-left:3px solid var(--mid);padding-left:0.75rem;color:var(--text-secondary);font-style:italic;margin:0.3rem 0">$1</div>');

  // 8. Unordered lists
  t = t.replace(/((?:^[-•*✓✗] .+\n?)+)/gm, block => {
    const items = block.trim().split('\n')
      .map(l => '<li style="margin-bottom:0.25rem">' + l.replace(/^[-•*✓✗]\s+/, '') + '</li>').join('');
    return '<ul style="padding-left:1.25rem;margin:0.2rem 0;display:flex;flex-direction:column;gap:0">' + items + '</ul>';
  });

  // 9. Ordered lists
  t = t.replace(/((?:^\d+\.\s+.+\n?)+)/gm, block => {
    const items = block.trim().split('\n')
      .map(l => '<li style="margin-bottom:0.25rem">' + l.replace(/^\d+\.\s+/, '') + '</li>').join('');
    return '<ol style="padding-left:1.25rem;margin:0.2rem 0">' + items + '</ol>';
  });

  // 10. Collapse excessive blank lines, then convert to single <br>
  t = t.replace(/\n{3,}/g, '\n\n');      // 3+ blank lines → 1 blank line
  t = t.replace(/\n\n/g, '<br>');        // paragraph break = 1 br (not 2)
  t = t.replace(/\n/g, ' ');             // soft newline = space

  // 11. Restore code blocks
  t = t.replace(/\x00CODE(\d+)\x00/g, (_, i) =>
    '<pre style="background:var(--surface-2);border:1px solid var(--border);padding:0.75rem 1rem;border-radius:7px;overflow-x:auto;margin:0.5rem 0;font-family:\'IBM Plex Mono\',monospace;font-size:0.82em;line-height:1.5;white-space:pre">' +
    codeBlocks[+i].replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</pre>'
  );

  // 12. Remove <br> immediately before/after block elements (avoids double spacing)
  t = t.replace(/(<br>)+(<\/?(div|ul|ol|pre|hr|table)[^>]*>)/g, '$2');
  t = t.replace(/(<\/?(div|ul|ol|pre|hr|table)[^>]*>)(<br>)+/g, '$1');

  return t;
}

// ── Follow-up questions extractor ────────────────────────
function extractFollowUps(text) {
  // Patterns Claude uses for follow-up sections
  const patterns = [
    // "💡 **You might also ask:**\n- Q?\n- Q?"
    /(💡\s*\*{0,2}You might also ask[:\*]*\*{0,2}\s*\n)((?:[-•*]\s*.+\?.*\n?)+)/i,
    // "**Follow-up questions:**\n- Q?"
    /(\*{0,2}Follow.?up questions?[:\*]*\*{0,2}\s*\n)((?:[-•*]\s*.+\?.*\n?)+)/i,
    // "You might also want to ask:\n- Q?"
    /(You might (?:also )?(?:want to )?ask[:\s]+)((?:[-•*]\s*.+\?.*\n?)+)/i,
    // "Related questions:\n- Q?"
    /(Related questions?[:\s]+)((?:[-•*]\s*.+\?.*\n?)+)/i,
  ];

  for (const re of patterns) {
    const m = text.match(re);
    if (m) {
      const qs = m[2].trim().split('\n')
        .map(l => l.replace(/^[-•*\d.]\s*/, '').trim())
        .filter(l => l.length > 8);
      if (qs.length >= 1) {
        const cutIdx = text.indexOf(m[0]);
        const cleaned = text.slice(0, cutIdx).trim();
        return { questions: qs.slice(0, 3), cleaned };
      }
    }
  }

  // No follow-up section found — generate generic ones based on content keywords
  const fallbacks = [];
  const low = text.toLowerCase();
  if (low.includes('portfolio') || low.includes('holding'))
    fallbacks.push('Which of my funds has the best returns?');
  if (low.includes('sip') || low.includes('invest'))
    fallbacks.push('How much SIP do I need to reach ₹1 Crore in 10 years?');
  if (low.includes('tax') || low.includes('ltcg') || low.includes('elss'))
    fallbacks.push('How can I reduce my tax liability this year?');
  if (low.includes('goal') || low.includes('retire'))
    fallbacks.push('Am I on track to meet my financial goals?');
  if (low.includes('fund') || low.includes('equity') || low.includes('debt'))
    fallbacks.push('Should I switch any of my underperforming funds?');

  // Always show at least 2 follow-ups if response is long enough
  const wordCount = text.split(/\s+/).length;
  const showFallback = wordCount > 80 && fallbacks.length >= 2;

  return {
    questions: showFallback ? fallbacks.slice(0, 3) : [],
    cleaned: text
  };
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
  const primoBubble = addBubble('assistant', '', true); // shows typing dots

  try {
    const resp = await fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
      body: JSON.stringify({ message: text, session_key: SESSION_KEY })
    });

    const data = await resp.json();

    if (data.error) {
      primoBubble.innerHTML = '⚠ ' + data.error;
      return;
    }

    // Extract follow-up questions before rendering
    const { questions, cleaned } = extractFollowUps(data.response || '');

    // Typewriter animation
    await typewriter(primoBubble, cleaned);

    // Show follow-up question chips after response
    if (questions.length) {
      const followEl = document.createElement('div');
      followEl.style.cssText = 'margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid rgba(46,133,64,0.15)';
      followEl.innerHTML = '<div style="font-family:\'IBM Plex Mono\',monospace;font-size:0.6rem;color:var(--lime);letter-spacing:0.15em;text-transform:uppercase;margin-bottom:0.4rem">Continue exploring</div>' +
        questions.map(q => `<button onclick="sendSuggestion(this)" style="display:inline-block;margin:0.2rem 0.2rem 0 0;font-size:0.78rem;padding:0.3rem 0.7rem;border:1px solid var(--border);border-radius:14px;background:var(--surface-2);color:var(--text-secondary);cursor:pointer;transition:all 0.15s;font-family:\'Inter\',sans-serif" onmouseover="this.style.borderColor=\'var(--mid)\';this.style.color=\'var(--cream)\'" onmouseout="this.style.borderColor=\'\';this.style.color=\'\'">${q}</button>`).join('');
      primoBubble.parentNode.appendChild(followEl);
      scrollBottom();
    }

  } catch (e) {
    primoBubble.innerHTML = `
      <div style="display:flex;flex-direction:column;gap:0.6rem">
        <div style="display:flex;align-items:center;gap:0.5rem;color:var(--gold);font-weight:600;font-size:0.88rem">
          <i class="bi bi-wifi-off"></i> Couldn't reach PrimoAI
        </div>
        <div style="font-size:0.82rem;color:var(--text-secondary);line-height:1.5">
          This usually happens when the AI is taking longer than expected to respond.
          Your question wasn't lost — please try sending it again.
        </div>
        <div style="display:flex;gap:0.6rem;flex-wrap:wrap;margin-top:0.2rem">
          <button onclick="document.getElementById('primoInput').focus()" style="font-size:0.75rem;font-family:'IBM Plex Mono',monospace;padding:0.3rem 0.75rem;border:1px solid var(--border);border-radius:4px;background:var(--surface-2);color:var(--lime);cursor:pointer">
            Try again
          </button>
          <a href="https://wa.me/919980001338" target="_blank" rel="noopener" style="font-size:0.75rem;font-family:'IBM Plex Mono',monospace;padding:0.3rem 0.75rem;border:1px solid rgba(141,198,63,0.2);border-radius:4px;background:transparent;color:var(--text-secondary);text-decoration:none">
            <i class="bi bi-whatsapp"></i> Chat with Kiran instead
          </a>
        </div>
      </div>`;
  } finally {
    isSending = false;
    document.getElementById('primoSend').disabled = false;
  }
}

// Typewriter: renders markdown progressively word by word
async function typewriter(el, fullText) {
  const words = fullText.split(' ');
  let built   = '';
  el.innerHTML = '';
  for (let i = 0; i < words.length; i++) {
    built += (i === 0 ? '' : ' ') + words[i];
    el.innerHTML = renderMarkdown(built);
    scrollBottom();
    const delay = i % 25 === 24 ? 25 : 15;
    await new Promise(r => setTimeout(r, delay));
  }
  el.innerHTML = renderMarkdown(fullText);
  scrollBottom();
}

function sendSuggestion(btn) {
  const text = btn.textContent.trim();
  // Strip leading emoji + space only if the button starts with an emoji
  const isEmoji = text.codePointAt(0) > 0xFF;
  document.getElementById('primoInput').value = isEmoji ? text.replace(/^[^\s]+\s/, '') : text;
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

// ── Custom in-app clear modal ─────────────────────────────
function showClearModal() {
  document.getElementById('clearModal').style.display = 'flex';
}
function hideClearModal() {
  document.getElementById('clearModal').style.display = 'none';
}

async function clearChat() {
  hideClearModal();
  const btn = document.querySelector('[onclick="showClearModal()"]');
  if (btn) { btn.disabled = true; btn.textContent = 'Clearing…'; }

  try {
    const res = await fetch(CLEAR_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
      body: JSON.stringify({ session_key: SESSION_KEY })
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok && data.success !== false) {
      // Remove all messages except the welcome message
      const wrap = document.getElementById('primoMessages');
      const kids = Array.from(wrap.children);
      kids.forEach((el, i) => { if (i > 0) el.remove(); });
      // Show suggestions strip again
      const sugg = document.getElementById('primoSuggestions');
      if (sugg) sugg.style.display = 'flex';
    }
  } catch(e) {
    console.error('Clear chat error:', e);
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Clear Chat'; }
  }
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

// Render welcome message through markdown pipeline
const wb = document.getElementById('welcomeBubble');
if (wb) wb.innerHTML = renderMarkdown(wb.dataset.md);

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
    <p style="color:var(--text-secondary);font-size:0.875rem;margin-bottom:1.25rem">Upload your CAS statement, broker PDF, or FD certificate. PrimoAI will automatically extract and add your holdings to your portfolio.</p>

    <!-- Step 1: Dropzone -->
    <div id="scanDropzone" style="border:2px dashed var(--border);border-radius:10px;padding:2rem;text-align:center;cursor:pointer;transition:border-color 0.2s"
         onclick="document.getElementById('scanFile').click()"
         ondragover="event.preventDefault();this.style.borderColor='var(--mid)'"
         ondragleave="this.style.borderColor=''"
         ondrop="handleDrop(event)">
      <div style="font-size:2rem;margin-bottom:0.5rem">📁</div>
      <p style="color:var(--text-secondary);font-size:0.875rem">Drag & drop your PDF here or <span style="color:var(--lime)">browse</span></p>
      <p style="font-size:0.75rem;color:var(--text-muted);margin-top:0.4rem">CAS (CAMS/KFintech), Zerodha, Groww, NSDL, CDSL, FD Certificate · PDF only</p>
      <input type="file" id="scanFile" accept=".pdf,.csv" style="display:none" onchange="fileSelected(this.files[0])">
    </div>

    <!-- Step 2: File selected — show password field before uploading -->
    <div id="scanFileReady" style="display:none">
      <div style="display:flex;align-items:center;gap:0.75rem;padding:0.875rem;background:var(--surface-2);border-radius:8px;border:1px solid var(--border);margin-bottom:1rem">
        <span style="font-size:1.25rem">📄</span>
        <div style="flex:1;overflow:hidden">
          <div id="selectedFileName" style="font-size:0.875rem;color:var(--cream);font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></div>
          <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.1rem">Ready to scan</div>
        </div>
        <button onclick="resetScanModal()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1rem;flex-shrink:0">✕</button>
      </div>

      <!-- Password field -->
      <div style="margin-bottom:1rem">
        <label style="display:block;font-size:0.82rem;color:var(--text-secondary);margin-bottom:0.4rem;font-weight:500">
          PDF Password <span style="color:var(--text-muted);font-weight:400">(if password-protected)</span>
        </label>
        <input type="password" id="pdfPassword" class="form-input"
               placeholder="Leave blank if no password"
               style="font-family:'IBM Plex Mono',monospace;letter-spacing:0.1em">
        <div style="margin-top:0.5rem;background:var(--surface-2);border-radius:6px;padding:0.6rem 0.75rem;font-size:0.75rem;color:var(--text-secondary);line-height:1.6">
          🔐 <strong style="color:var(--cream)">Common statement passwords:</strong><br>
          • NSDL / CDSL demat statement → <span style="font-family:'IBM Plex Mono',monospace;color:var(--lime)">PAN in CAPITALS</span> (e.g. ABCDE1234F)<br>
          • CAMS / KFintech CAS → <span style="font-family:'IBM Plex Mono',monospace;color:var(--lime)">PAN in CAPITALS</span><br>
          • Broker statements (Zerodha/Groww) → usually not password protected<br>
          Don't have your statement yet? <a href="<?= SITE_URL ?>/documentation.php?page=cas-nsdl-statement" style="color:var(--lime)">See how to get one →</a>
        </div>
      </div>

      <button onclick="uploadForScan()" class="btn-primary" style="width:100%;justify-content:center">
        Scan Document →
      </button>
    </div>

    <!-- Progress — step by step -->
    <div id="scanProgress" style="display:none;padding:0.5rem 0">
      <div id="scanSteps">
        <?php foreach ([
          ['id'=>'sp1','label'=>'Uploading document'],
          ['id'=>'sp2','label'=>'Detecting document type'],
          ['id'=>'sp3','label'=>'Extracting holdings with AI (15–30 sec)'],
          ['id'=>'sp4','label'=>'Validating extracted data'],
          ['id'=>'sp5','label'=>'Ready to confirm'],
        ] as $step): ?>
        <div id="<?= $step['id'] ?>" style="display:flex;align-items:center;gap:0.75rem;padding:0.55rem 0;border-bottom:1px solid var(--border-light)">
          <div class="sp-icon" style="width:22px;height:22px;border-radius:50%;border:2px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:0.7rem;color:var(--text-muted)">◌</div>
          <span class="sp-label" style="font-size:0.875rem;color:var(--text-muted)"><?= $step['label'] ?></span>
        </div>
        <?php endforeach; ?>
      </div>
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

let pendingFile = null;

function openScanModal()  { document.getElementById('scanModal').style.display='flex'; }
function closeScanModal() { document.getElementById('scanModal').style.display='none'; resetScanModal(); }
function resetScanModal() {
  pendingFile = null;
  clearTimeout(stepTimer);
  document.getElementById('scanDropzone').style.display='block';
  document.getElementById('scanFileReady').style.display='none';
  document.getElementById('scanProgress').style.display='none';
  document.getElementById('scanResults').style.display='none';
  document.getElementById('confirmBtn').style.display='none';
  document.getElementById('pdfPassword').value='';
  STEPS.forEach(s => setStep(s,'pending'));
  scannedDocId=null; scannedData=[];
}
function handleDrop(e) { e.preventDefault(); const f=e.dataTransfer.files[0]; if(f) fileSelected(f); }

// Step 1 — file chosen: show password field
function fileSelected(file) {
  if (!file) return;
  pendingFile = file;
  document.getElementById('scanDropzone').style.display='none';
  document.getElementById('selectedFileName').textContent=file.name;
  document.getElementById('scanFileReady').style.display='block';
  document.getElementById('scanResults').style.display='none';
}

// ── Step progress helpers ─────────────────────────────────
let stepTimer = null;
const STEPS = ['sp1','sp2','sp3','sp4','sp5'];

function setStep(id, state) {
  const el = document.getElementById(id);
  if (!el) return;
  const icon  = el.querySelector('.sp-icon');
  const label = el.querySelector('.sp-label');
  if (state === 'done') {
    icon.style.cssText  = 'width:22px;height:22px;border-radius:50%;background:var(--bright);border:2px solid var(--bright);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:0.75rem;color:#fff';
    icon.textContent    = '✓';
    label.style.color   = 'var(--cream)';
  } else if (state === 'active') {
    icon.innerHTML = '<div style="width:12px;height:12px;border:2px solid var(--border);border-top-color:var(--mid);border-radius:50%;animation:rb-spin 0.7s linear infinite"></div>';
    icon.style.cssText  = 'width:22px;height:22px;border-radius:50%;border:none;display:flex;align-items:center;justify-content:center;flex-shrink:0;';
    label.style.color   = 'var(--cream)';
    label.style.fontWeight = '500';
  } else {
    icon.textContent    = '◌';
    icon.style.cssText  = 'width:22px;height:22px;border-radius:50%;border:2px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:0.7rem;color:var(--text-muted)';
    label.style.color   = 'var(--text-muted)';
    label.style.fontWeight = 'normal';
  }
}

function startProgressSteps() {
  STEPS.forEach(s => setStep(s, 'pending'));
  setStep('sp1','active');
  clearTimeout(stepTimer);
  const timing = [800, 2500, 4000, null]; // sp1→sp2→sp3(long)→sp4 on response
  let current = 0;
  function advance() {
    setStep(STEPS[current],'done');
    current++;
    if (current < timing.length && timing[current-1] !== null) {
      setStep(STEPS[current],'active');
      if (timing[current] !== null) stepTimer = setTimeout(advance, timing[current]);
    }
  }
  stepTimer = setTimeout(advance, timing[0]);
}

function finishProgressSteps() {
  clearTimeout(stepTimer);
  STEPS.slice(0,4).forEach(s => setStep(s,'done'));
  setStep('sp5','done');
}

function failProgressSteps() {
  clearTimeout(stepTimer);
  // Mark the active step as failed (leave others as-is)
  STEPS.forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    if (el.querySelector('.sp-icon')?.innerHTML.includes('rb-spin')) {
      el.querySelector('.sp-icon').textContent = '✗';
      el.querySelector('.sp-icon').style.color = '#ef5350';
      el.querySelector('.sp-label').style.color = '#ef5350';
    }
  });
}

// Step 2 — actually upload with password
async function uploadForScan() {
  const file = pendingFile;
  if (!file) return;
  const password = document.getElementById('pdfPassword').value.trim();

  document.getElementById('scanFileReady').style.display='none';
  document.getElementById('scanProgress').style.display='block';
  startProgressSteps();

  const fd=new FormData();
  fd.append('document', file);
  fd.append('csrf_token', getCsrf());
  if (password) fd.append('pdf_password', password);

  try {
    const res=await fetch(SCAN_URL,{method:'POST',body:fd});
    const r=await res.json();

    if (!r.success) {
      failProgressSteps();
      document.getElementById('scanProgress').style.display='none';
      if (r.password_required) {
        document.getElementById('scanFileReady').style.display='block';
        document.getElementById('scanResults').innerHTML=`
          <div class="flash-error" style="margin-bottom:0.75rem">
            🔒 This PDF is password-protected.<br>
            <small style="color:var(--text-muted)">Tip: NSDL/CDSL password = PAN in CAPITALS (e.g. ABCDE1234F) · CAMS CAS = PAN in CAPITALS</small>
          </div>`;
        document.getElementById('scanResults').style.display='block';
      } else {
        document.getElementById('scanDropzone').style.display='block';
        document.getElementById('scanResults').innerHTML=`<div class="flash-error" style="margin-top:1rem">⚠ ${r.message}</div>`;
        document.getElementById('scanResults').style.display='block';
      }
      return;
    }
    finishProgressSteps();
    setTimeout(() => {
      document.getElementById('scanProgress').style.display='none';
      scannedDocId=r.document_id;
      scannedData=r.holdings;
      showHoldingsPreview(r.holdings, r.message);
    }, 600);
  } catch(e) {
    failProgressSteps();
    document.getElementById('scanProgress').style.display='none';
    document.getElementById('scanDropzone').style.display='block';
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
      <td style="font-family:'IBM Plex Mono',monospace;font-size:0.82rem">${inv}</td>
      <td style="font-family:'IBM Plex Mono',monospace;font-size:0.82rem">${val}</td></tr>`;
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

<!-- ── Clear Chat Confirmation Modal ── -->
<div id="clearModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:2000;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)hideClearModal()">
  <div style="background:var(--surface-1);border:1px solid var(--border);border-radius:14px;padding:2rem;max-width:380px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.5)">
    <div style="font-size:2rem;text-align:center;margin-bottom:0.75rem">🗑️</div>
    <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.35rem;color:var(--cream);text-align:center;margin-bottom:0.5rem">Clear Conversation?</h3>
    <p style="color:var(--text-secondary);font-size:0.875rem;text-align:center;line-height:1.6;margin-bottom:1.5rem">
      This will delete your entire PrimoAI conversation history. Your portfolio data is not affected.
    </p>
    <div style="display:flex;gap:0.75rem;justify-content:center">
      <button onclick="hideClearModal()"
        style="flex:1;padding:0.6rem 1.25rem;border-radius:8px;border:1px solid var(--border);background:var(--surface-2);color:var(--text-secondary);cursor:pointer;font-family:'Inter',sans-serif;font-size:0.875rem;transition:all 0.15s"
        onmouseover="this.style.borderColor='var(--mid)';this.style.color='var(--cream)'"
        onmouseout="this.style.borderColor='';this.style.color=''">
        Cancel
      </button>
      <button onclick="clearChat()"
        style="flex:1;padding:0.6rem 1.25rem;border-radius:8px;border:none;background:#ef5350;color:#fff;cursor:pointer;font-family:'Inter',sans-serif;font-size:0.875rem;font-weight:500;transition:opacity 0.15s"
        onmouseover="this.style.opacity='0.85'"
        onmouseout="this.style.opacity='1'">
        Yes, Clear Chat
      </button>
    </div>
  </div>
</div>

<?php require_once '../includes/portal-footer.php'; ?>
