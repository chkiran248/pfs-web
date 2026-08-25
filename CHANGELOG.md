# Changelog

All notable changes to this project are documented in this file.

Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). This file starts at **v1.0.0**, treating the current state of the codebase as the baseline — everything before this point is summarized under 1.0.0 rather than itemized commit-by-commit (see `git log` for full history). From this point forward, every change gets its own dated entry under `[Unreleased]`, moved into a version heading at release time.

## [Unreleased]

### Added
- Public-facing "What's New" page in the Documentation Center (`documentation.php?page=changelog`), separate from this developer-facing changelog.

## [1.0.0] — 2026-08-24

Baseline release. Summarizes everything shipped up to and including "Add Cloudflare Turnstile to login page."

### Added
- Client portal: portfolio tracking, goals, SIP/tax/NPS/insurance calculators, risk assessment quiz, cashflow modeler, FD tracker, overlap analyzer, fund & stock watchlists, AI-assisted rebalancer.
- Admin backend: client & lead management, document distribution, advisory content management, coupon codes, data pipeline monitoring.
- Advisory research: mutual fund research & comparison, stock research, sector tracker, model portfolios, market insights, backed by live NAV/price data from MFAPI.in and Yahoo Finance.
- PrimoAI: Claude-powered assistant with portfolio/goal context, conversational chat, document scanning & auto-import (CAS, NSDL/CDSL, broker statements), AI-assisted mutual fund and equity rebalancing.
- Authentication: email/password registration with OTP email verification, Google OAuth sign-in/sign-up.
- Subscriptions & payments: Free / Active Investor / Premium plan tiers, coupon redemption, Cashfree payment gateway integration and checkout flow.
- Public Documentation Center (`/documentation.php`): a 30-article self-service guide covering every client-facing feature, including how to obtain and upload a CAS/NSDL statement.
- Comfortable Dark theme (default) with a light mode toggle.

### Security
- Cloudflare Turnstile CAPTCHA on both registration and login.
- Per-IP rate limiting on account registration, separate from existing login-lockout throttling.
- Email normalization (Gmail dot-trick / `+tag` stripping) to close a duplicate-account abuse path used for scripted/bot signups.
- CSRF protection, session security hardening (secure cookies in production, idle timeout), and directory-level access protection for backend-only paths.

### Fixed
- Multiple NAV/data-pipeline accuracy and reliability fixes (scheme-code matching, timeout/crash fixes, processed-count bug).
- Advisory pages made accessible to both clients and admins (fixed a redirect-loop bug for admin users).
- Light-mode contrast, logo visibility, and scrollbar styling fixes.
- Navigation layout bug where an added menu item wrapped to a second line.
- Compliance fix: removed an inaccurate "SEBI registered" claim and corrected the AMFI ARN number.
