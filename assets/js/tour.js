// Onboarding walkthrough for new portal users. Auto-starts once (controlled
// by body[data-start-tour], set server-side from users.has_seen_tour) and can
// be replayed anytime via the compass button in the header.
(function () {
  const TOUR_STEPS = [
    {
      target: null,
      title: 'Welcome to Prime Financials',
      body: "Let's take a quick look at where everything lives — this takes less than a minute.",
    },
    {
      target: '#tour-stats',
      title: 'Your financial snapshot',
      body: 'Total invested, current value, gains, and annualised returns — updated every time you log in.',
    },
    {
      target: '#sidebar',
      title: 'Everything lives in the sidebar',
      body: 'Your portfolio, goals, calculators, advisory research, watchlists and documents are grouped here. Scroll down to see it all.',
      mobileOpenSidebar: true,
    },
    {
      target: '#tour-quick-tools',
      title: 'AI-powered tools, one click away',
      body: 'Run SIP and tax calculators, or try the AI Portfolio Rebalancer. Need a quick answer instead? Chat with PrimoAI, or tap the WhatsApp button to reach your advisor directly.',
    },
  ];

  let currentStep = 0;
  let overlayEl = null;
  let sidebarOpenedByTour = false;

  function buildOverlay() {
    overlayEl = document.createElement('div');
    overlayEl.className = 'tour-overlay';
    overlayEl.innerHTML =
      '<div class="tour-clickblock" id="tourClickblock"></div>' +
      '<div class="tour-spotlight" id="tourSpotlight"></div>' +
      '<div class="tour-tooltip" id="tourTooltip">' +
      '  <div class="tour-progress" id="tourProgress"></div>' +
      '  <h3 class="tour-title" id="tourTitle"></h3>' +
      '  <p class="tour-body" id="tourBody"></p>' +
      '  <div class="tour-actions">' +
      '    <button class="tour-btn tour-btn--skip" id="tourSkip">Skip</button>' +
      '    <div class="tour-actions-right">' +
      '      <button class="tour-btn tour-btn--back" id="tourBack">Back</button>' +
      '      <button class="tour-btn tour-btn--next" id="tourNext">Next</button>' +
      '    </div>' +
      '  </div>' +
      '</div>';
    document.body.appendChild(overlayEl);

    document.getElementById('tourSkip').onclick = endTour;
    document.getElementById('tourBack').onclick = function () { goToStep(currentStep - 1); };
    document.getElementById('tourNext').onclick = function () {
      if (currentStep >= TOUR_STEPS.length - 1) { endTour(); }
      else { goToStep(currentStep + 1); }
    };
  }

  function positionTooltipNear(targetEl) {
    const tooltip = document.getElementById('tourTooltip');
    const spotlight = document.getElementById('tourSpotlight');
    if (!tooltip || !spotlight) return;

    if (!targetEl) {
      spotlight.style.top = '50%';
      spotlight.style.left = '50%';
      spotlight.style.width = '0px';
      spotlight.style.height = '0px';
      tooltip.style.transform = 'translate(-50%, -50%)';
      tooltip.style.top = '50%';
      tooltip.style.left = '50%';
      return;
    }

    const rect = targetEl.getBoundingClientRect();
    spotlight.style.top = (rect.top - 8) + 'px';
    spotlight.style.left = (rect.left - 8) + 'px';
    spotlight.style.width = (rect.width + 16) + 'px';
    spotlight.style.height = (rect.height + 16) + 'px';

    tooltip.style.transform = 'none';
    const spaceBelow = window.innerHeight - rect.bottom;
    if (spaceBelow > 240) {
      tooltip.style.top = (rect.bottom + 16) + 'px';
    } else {
      tooltip.style.top = Math.max(16, rect.top - 16) + 'px';
      tooltip.style.transform = 'translateY(-100%)';
    }
    const maxLeft = Math.max(16, window.innerWidth - 336);
    tooltip.style.left = Math.min(Math.max(16, rect.left), maxLeft) + 'px';
  }

  function openMobileSidebarForTour() {
    const sidebar = document.getElementById('sidebar');
    const scrim = document.getElementById('sidebar-overlay');
    if (sidebar && !sidebar.classList.contains('open')) {
      sidebar.classList.add('open');
      if (scrim) scrim.classList.add('active');
      sidebarOpenedByTour = true;
    }
  }

  function closeMobileSidebarIfOurs() {
    if (!sidebarOpenedByTour) return;
    const sidebar = document.getElementById('sidebar');
    const scrim = document.getElementById('sidebar-overlay');
    if (sidebar) sidebar.classList.remove('open');
    if (scrim) scrim.classList.remove('active');
    sidebarOpenedByTour = false;
  }

  function goToStep(idx) {
    if (idx < 0 || idx >= TOUR_STEPS.length) return;
    const step = TOUR_STEPS[idx];

    if (!step.mobileOpenSidebar) closeMobileSidebarIfOurs();
    if (step.mobileOpenSidebar && window.innerWidth <= 900) openMobileSidebarForTour();

    currentStep = idx;
    document.getElementById('tourProgress').textContent = 'Step ' + (idx + 1) + ' of ' + TOUR_STEPS.length;
    document.getElementById('tourTitle').textContent = step.title;
    document.getElementById('tourBody').textContent = step.body;
    document.getElementById('tourBack').style.visibility = idx === 0 ? 'hidden' : 'visible';
    document.getElementById('tourNext').textContent = idx === TOUR_STEPS.length - 1 ? 'Finish' : 'Next';

    const targetEl = step.target ? document.querySelector(step.target) : null;
    if (targetEl) targetEl.scrollIntoView({ block: 'center', behavior: 'smooth' });
    // Give scrollIntoView a moment to settle before measuring position.
    setTimeout(function () { positionTooltipNear(targetEl); }, targetEl ? 180 : 0);
  }

  function endTour() {
    closeMobileSidebarIfOurs();
    if (overlayEl) { overlayEl.remove(); overlayEl = null; }
    document.removeEventListener('keydown', onKeydown);
    window.removeEventListener('resize', onResize);
    markTourSeen();
  }

  function onKeydown(e) { if (e.key === 'Escape') endTour(); }
  function onResize() {
    const step = TOUR_STEPS[currentStep];
    positionTooltipNear(step.target ? document.querySelector(step.target) : null);
  }

  async function markTourSeen() {
    try {
      const csrfMeta = document.querySelector('meta[name="csrf-token"]');
      const base = window.PF_SITE_URL || '';
      if (!csrfMeta || !base) return;
      await fetch(base + '/portal/complete-tour.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfMeta.content },
      });
    } catch (err) { /* non-critical */ }
  }

  function startTour() {
    currentStep = 0;
    buildOverlay();
    document.addEventListener('keydown', onKeydown);
    window.addEventListener('resize', onResize);
    goToStep(0);
  }

  window.startOnboardingTour = startTour;

  window.relaunchTour = function () {
    if (document.getElementById('tour-stats')) {
      startTour();
    } else {
      const base = window.PF_SITE_URL || '';
      window.location.href = base + '/portal/dashboard.php?start_tour=1';
    }
  };

  document.addEventListener('DOMContentLoaded', function () {
    if (document.body.dataset.startTour === '1') {
      setTimeout(startTour, 600);
    }
  });
})();
