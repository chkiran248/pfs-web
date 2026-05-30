/* Primevault — main.js (Final) */

/* ── ANTI-FLASH + THEME TOGGLE ─────────────────────────── */
const html        = document.documentElement;
const themeToggle = document.getElementById('themeToggle');

// Read saved theme (default: dark — no attribute needed)
const saved = localStorage.getItem('pv-theme') || 'dark';
if (saved === 'light') {
  html.setAttribute('data-theme', 'light');
}

themeToggle.addEventListener('click', () => {
  const isLight = html.getAttribute('data-theme') === 'light';
  if (isLight) {
    html.removeAttribute('data-theme');
    localStorage.setItem('pv-theme', 'dark');
  } else {
    html.setAttribute('data-theme', 'light');
    localStorage.setItem('pv-theme', 'light');
  }
});

/* ── NAV SCROLL ────────────────────────────────────────── */
const nav = document.getElementById('nav');
window.addEventListener('scroll', () => {
  nav.classList.toggle('nav--scrolled', window.scrollY > 60);
}, { passive: true });

/* ── HAMBURGER MENU ────────────────────────────────────── */
const hamburger = document.getElementById('hamburger');
const navLinks  = document.getElementById('navLinks');

hamburger.addEventListener('click', () => {
  const open = navLinks.classList.toggle('open');
  hamburger.setAttribute('aria-expanded', String(open));
});

// Close on any nav link click
navLinks.querySelectorAll('a').forEach(link => {
  link.addEventListener('click', () => {
    navLinks.classList.remove('open');
    hamburger.setAttribute('aria-expanded', 'false');
  });
});

// Close on outside click
document.addEventListener('click', (e) => {
  if (!nav.contains(e.target)) {
    navLinks.classList.remove('open');
    hamburger.setAttribute('aria-expanded', 'false');
  }
});

/* ── SCROLL REVEAL ─────────────────────────────────────── */
const revealSelectors = [
  '.intel-card', '.product-row', '.step-card',
  '.testi-card', '.visual-card',
  '.about__content', '.about__manifesto',
  '.contact__content', '.contact-form'
].join(', ');

const revealEls = document.querySelectorAll(revealSelectors);
revealEls.forEach(el => el.classList.add('reveal'));

const revealObserver = new IntersectionObserver((entries) => {
  entries.forEach((entry, i) => {
    if (entry.isIntersecting) {
      setTimeout(() => entry.target.classList.add('visible'), i * 75);
      revealObserver.unobserve(entry.target);
    }
  });
}, { threshold: 0.1, rootMargin: '0px 0px -48px 0px' });

revealEls.forEach(el => revealObserver.observe(el));

/* ── SMOOTH ANCHOR SCROLL ──────────────────────────────── */
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
  anchor.addEventListener('click', function (e) {
    const target = document.querySelector(this.getAttribute('href'));
    if (target) {
      e.preventDefault();
      const top = target.getBoundingClientRect().top + window.scrollY - 80;
      window.scrollTo({ top, behavior: 'smooth' });
    }
  });
});

/* ── ACTIVE NAV HIGHLIGHT ──────────────────────────────── */
const sections       = document.querySelectorAll('section[id]');
const navAnchorLinks = document.querySelectorAll('.nav__links a[href^="#"]');

const sectionObserver = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      const id = entry.target.getAttribute('id');
      navAnchorLinks.forEach(link => {
        const active = link.getAttribute('href') === `#${id}`;
        link.style.color = active ? 'var(--cream)' : '';
        link.style.fontWeight = active ? '500' : '';
      });
    }
  });
}, { threshold: 0.45 });

sections.forEach(s => sectionObserver.observe(s));

/* ── CONTACT FORM VALIDATION ───────────────────────────── */
const contactForm = document.querySelector('.contact-form');
if (contactForm) {
  contactForm.addEventListener('submit', function (e) {
    const name  = this.querySelector('#name').value.trim();
    const phone = this.querySelector('#phone').value.trim();
    if (!name || !phone) {
      e.preventDefault();
      alert('Please enter your name and mobile number to continue.');
    }
  });
}
