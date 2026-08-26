/* ============================================================
   Prime Financials — Portal JS
   ============================================================ */

// ── Global Indian currency formatter ─────────────────────────
window.formatINR = function(n, decimals) {
  if (n === null || n === undefined || isNaN(+n)) return '—';
  return '₹' + Math.abs(+n).toLocaleString('en-IN', {
    minimumFractionDigits: decimals || 0,
    maximumFractionDigits: decimals || 0
  });
};
window.formatINRShort = function(n) {
  const abs = Math.abs(+n);
  if (abs >= 10000000) return '₹' + (abs / 10000000).toFixed(2) + ' Cr';
  if (abs >= 100000)   return '₹' + (abs / 100000).toFixed(2) + ' L';
  return window.formatINR(n);
};

// ── Amount in words (Indian numbering — lakh/crore) ──────────
(function () {
  const ONES = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten',
                'Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
  const TENS = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];

  function twoDigitWords(n) {
    if (n < 20) return ONES[n];
    const t = Math.floor(n / 10), o = n % 10;
    return TENS[t] + (o ? ' ' + ONES[o] : '');
  }

  function threeDigitWords(n) {
    let out = '';
    if (n >= 100) {
      out += ONES[Math.floor(n / 100)] + ' Hundred';
      n = n % 100;
      if (n) out += ' ';
    }
    if (n > 0) out += twoDigitWords(n);
    return out;
  }

  window.numberToWordsIndian = function (num) {
    num = Math.floor(Math.abs(+num || 0));
    if (num === 0) return 'Zero';
    const crore = Math.floor(num / 10000000); num %= 10000000;
    const lakh  = Math.floor(num / 100000);    num %= 100000;
    const thousand = Math.floor(num / 1000);   num %= 1000;
    const hundred = num;
    const parts = [];
    if (crore) parts.push(threeDigitWords(crore) + ' Crore');
    if (lakh) parts.push(threeDigitWords(lakh) + ' Lakh');
    if (thousand) parts.push(threeDigitWords(thousand) + ' Thousand');
    if (hundred) parts.push(threeDigitWords(hundred));
    return parts.join(' ');
  };

  window.amountToWordsCaption = function (value) {
    const n = Math.round(parseFloat(value));
    if (!value || isNaN(n) || n <= 0) return '';
    return window.numberToWordsIndian(n) + ' Rupees Only';
  };
})();

(function () {
  'use strict';

  // ── Theme Toggle ─────────────────────────────────────────
  const THEME_KEY = 'pv-theme';

  function applyTheme(theme) {
    if (theme === 'light') {
      document.documentElement.setAttribute('data-theme', 'light');
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
  }

  // Apply on load (anti-flash handled by inline script in <head>)
  applyTheme(localStorage.getItem(THEME_KEY) || 'dark');

  document.addEventListener('DOMContentLoaded', function () {

    // ── Theme toggle button ───────────────────────────────
    const themeBtn = document.getElementById('theme-toggle');
    if (themeBtn) {
      themeBtn.addEventListener('click', function () {
        const current = localStorage.getItem(THEME_KEY) || 'dark';
        const next = current === 'dark' ? 'light' : 'dark';
        localStorage.setItem(THEME_KEY, next);
        applyTheme(next);
        themeBtn.textContent = next === 'dark' ? '☀️' : '🌙';
      });

      // Set initial icon
      const t = localStorage.getItem(THEME_KEY) || 'dark';
      themeBtn.textContent = t === 'dark' ? '☀️' : '🌙';
    }

    // ── Sidebar Toggle (mobile) ───────────────────────────
    const hamburger = document.getElementById('hamburger');
    const sidebar   = document.getElementById('sidebar');
    const overlay   = document.getElementById('sidebar-overlay');

    function openSidebar() {
      sidebar && sidebar.classList.add('open');
      overlay && overlay.classList.add('active');
      document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
      sidebar && sidebar.classList.remove('open');
      overlay && overlay.classList.remove('active');
      document.body.style.overflow = '';
    }

    hamburger && hamburger.addEventListener('click', openSidebar);
    overlay   && overlay.addEventListener('click', closeSidebar);

    // ── Flash message auto-dismiss ────────────────────────
    document.querySelectorAll('.flash-success, .flash-error, .flash-info').forEach(function (el) {
      setTimeout(function () {
        el.style.transition = 'opacity 0.4s';
        el.style.opacity = '0';
        setTimeout(function () { el.remove(); }, 400);
      }, 4000);
    });

    // ── Active sidebar link ───────────────────────────────
    const currentPath = window.location.pathname;
    document.querySelectorAll('.sidebar-link').forEach(function (link) {
      const href = link.getAttribute('href') || '';
      // Match on the filename portion
      const linkFile = href.split('/').pop();
      const currentFile = currentPath.split('/').pop();
      if (linkFile && currentFile && linkFile === currentFile) {
        link.classList.add('active');
      }
    });

    // ── Header scroll shadow ──────────────────────────────
    const header = document.getElementById('portal-header');
    if (header) {
      window.addEventListener('scroll', function () {
        header.classList.toggle('scrolled', window.scrollY > 20);
      }, { passive: true });
    }

    // ── Live "amount in words" caption on currency fields ─
    document.querySelectorAll('.amount-input').forEach(function (el) {
      const hint = document.getElementById(el.id + '-words');
      if (!hint) return;
      const update = function () { hint.textContent = window.amountToWordsCaption(el.value); };
      el.addEventListener('input', update);
      update();
    });

  }); // end DOMContentLoaded

  // ── Confirm action helper ─────────────────────────────────
  window.confirmAction = function (message, callback) {
    if (window.confirm(message)) {
      callback();
    }
  };

  // ── CSRF token helper ─────────────────────────────────────
  window.getCsrfToken = function () {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  };

  // ── Fetch POST wrapper ────────────────────────────────────
  window.apiPost = function (url, data) {
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': window.getCsrfToken(),
      },
      body: JSON.stringify(data),
    }).then(function (res) {
      if (!res.ok) throw new Error('Network response was not ok');
      return res.json();
    });
  };

})();
