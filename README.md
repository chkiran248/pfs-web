# Prime Financials — pfs-web

**"Data is Our Power"**

The web platform for Prime Financials / Prime Financial Services — an AMFI-registered Mutual Fund Distributor (ARN-137538), IRDAI-licensed insurance advisor, and NPS Point of Presence. It combines a public marketing site, a documentation center, a client portal, an admin backend, and an AI investment assistant (PrimoAI).

## Tech stack

- **PHP 8.x**, no framework — one plain PHP file per page, `declare(strict_types=1)` throughout
- **MySQL** via raw PDO — no ORM, no migration tool (schema changes are tracked as standalone `schema-*.sql` files and applied manually)
- **Vanilla HTML/CSS/JS** — no build step, no Node dependency for the frontend
- **Composer**: only [`phpmailer/phpmailer`](https://github.com/PHPMailer/PHPMailer) (SMTP via Hostinger)
- **External integrations**: Anthropic Claude API (PrimoAI), Google OAuth, Cloudflare Turnstile, Cashfree (payments), MFAPI.in + Yahoo Finance + AMFI (market data)
- **Hosting**: Hostinger shared hosting (Apache), deployed via hPanel's Git integration or manual upload

## Project structure

```
/                    Public marketing site (index.php) + Documentation Center (documentation.php)
admin/               Admin backend — clients, leads, documents, advisory content, coupons, data pipeline
advisory/            Mutual fund & stock research, sector tracker, model portfolios, market insights
portal/              Client portal — portfolio, goals, calculators, watchlists, rebalancer, PrimoAI, billing
auth/                Login, registration, Google OAuth, email verification, password reset
ai/                  PrimoAI backend — Claude API calls, document parsing/scanning, rebalance logic
includes/            Shared core — config, db, auth/role helpers, subscriptions, mailer, market data APIs
data-fetcher/        Cron jobs — NAV/stock price/news ingestion, fund scoring
cron/                Scheduled maintenance — session cleanup, watchlist alerts
documentation/       Content partials for the public Documentation Center
assets/              Static CSS/JS/fonts/icons
schema-*.sql         Standalone SQL migrations, applied manually to production
```

## Local setup

1. Clone the repo.
2. Copy `includes/config.example.php` to `includes/config.php` and fill in real values (DB credentials, SMTP, `CLAUDE_API_KEY`, `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY`, etc.). This file is git-ignored — never commit it.
3. Create the MySQL database and apply the base schema plus every `schema-*.sql` file in the repo root, in the order they were added.
4. Serve the app — either point Apache/XAMPP at the folder, or for a quick local check: `php -S localhost:8000`.
5. Visit `http://localhost:8000` (and make sure `SITE_URL` in `config.php` matches whatever host/port you're using).

## Key features

- **Client portal** — portfolio tracking, financial goals, SIP/tax/NPS/insurance calculators, risk assessment, cashflow modeler, FD tracker, overlap analyzer, fund & stock watchlists, AI-assisted rebalancing.
- **PrimoAI** — a Claude-powered assistant that understands a client's actual portfolio/goals, answers questions conversationally, and can scan/import a CAS, NSDL/CDSL, or broker statement into the portfolio automatically.
- **Advisory research** — curated mutual fund & stock research, fund comparison, sector tracking, model portfolios, market insights, backed by live NAV/price data.
- **Admin backend** — client & lead management, document distribution, advisory content authoring, coupon codes, data pipeline monitoring.
- **Subscriptions & payments** — Free / Active Investor / Premium tiers, coupon redemption, Cashfree checkout.
- **Public Documentation Center** (`/documentation.php`) — a self-service guide covering every client-facing feature.
- **Auth** — email/password with OTP email verification, Google OAuth, Cloudflare Turnstile bot protection, per-IP rate limiting on registration.

## Security notes

- `includes/config.php` is git-ignored and must never be committed — it holds live DB, SMTP, Claude, Turnstile, and payment gateway credentials.
- Database schema changes are **not** applied automatically. Any `schema-*.sql` file added to the repo must be run manually against the production database — check this repo's history for files matching that pattern before deploying new code that depends on one.
- See `CHANGELOG.md` for what's shipped and when.

## Deployment

Hosted on Hostinger. Two supported paths:
- **hPanel → Advanced → Git** — connect this repo, point at `master`, and every push auto-deploys (see hPanel docs for setup).
- **Manual** — upload changed files directly (FTP/File Manager).

Either way, after deploying new code: check whether it introduced a new `schema-*.sql` file (run it manually first) or new config constants (add them to the live `includes/config.php`) before considering the deploy complete.

## Ownership

Proprietary software for Prime Financial Services. Not licensed for redistribution.
