# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

CareScotland Directory — a PHP + MySQL care service directory built on [Care Inspectorate Datastore](https://www.careinspectorate.com/index.php/publications-statistics/44-public/93-datastore) open data (Open Government Licence). No Composer, no framework — plain PHP with PDO.

## Running locally

**Prerequisites:** MySQL/MariaDB running, database created, `sql/schema.sql` imported, `.env` filled in.

```bash
# Copy and fill in DB credentials and SITE_URL
cp .env.example .env

# Import schema (once)
mysql -u youruser -p yourdatabase < sql/schema.sql

# Quick dev server (no Apache needed)
php -S localhost:8080 -t . index.php
# Set SITE_URL=http://localhost:8080 in .env

# First data import (also used for monthly refresh)
php cron/import.php
```

**MAMP note:** CLI PHP often can't reach MAMP's MySQL on 3306. Set `DB_PORT=8889` (or `DB_SOCKET=/Applications/MAMP/tmp/mysql/mysql.sock`) in `.env`.

If the remote CSV returns 403, download it manually and pass the local path: `php cron/import.php /path/to/DatastoreExternal.csv`

**Production cron** (Hostinger): `0 6 1 * * php /full/path/to/cron/import.php`

**Datastore coverage check** — `cron/check_datastore_coverage.php` scrapes the [CI Datastore listing](https://www.careinspectorate.scot/resources-data/data-and-statistics/datastore) and downloads any of the last ~13 months' CSVs we don't already have into `storage/imports/pending/` (does not run the import itself — review and import via `admin/imports.php` as normal). Files are cumulative and import order doesn't matter (see the per-service dedup in `cron/import.php`, keyed on `grade_published`). Recommended weekly cron: `0 7 * * 1 php /full/path/to/cron/check_datastore_coverage.php >> storage/logs/datastore_check.log 2>&1`. Status of the last check is shown at the top of `admin/imports.php` and written to `storage/imports/coverage_check.json`.

## Schema and migrations

- **Canonical schema:** `sql/schema.sql` — always use this one.
- **Legacy:** root `schema.sql` and `import/run.php` target an older table layout; ignore unless intentionally migrating.
- **Migrations:** `sql/migrations/` — numbered SQL files for additive changes.
- **UTF-8 fix:** if import fails with `SQLSTATE 1366 Incorrect string value`, run `sql/ensure_utf8mb4.sql` in phpMyAdmin, then re-import.

## Architecture

### Config and bootstrap

Every page defines `ROOT` then requires `includes/db.php` (which also loads `includes/env.php`).

- `includes/env.php` — `load_app_config()` reads `.env` file then real `getenv()` (server env vars win). Returns a flat `$cfg` array with lowercase keys (`db_host`, `site_url`, etc.).
- `includes/db.php` — `db()` returns a cached PDO singleton; sets `utf8mb4` charset on connect. Compatible with PHP 8.5+ (`Pdo\Mysql` class).
- `includes/functions.php` — all shared helpers (see below).

### URL routing (`.htaccess`)

Apache rewrites route to PHP files in `public/`:

| URL | PHP file | Key param |
|-----|----------|-----------|
| `/` | `public/index.php` | — |
| `/service/{cs_number}/{slug}` | `public/service.php` | `?cs=` |
| `/provider/{sp_number}/{slug}` | `public/provider.php` | `?sp=` |
| `/councils` | `public/councils-map.php` | — |
| `/insights` | `public/insights.php` | — |
| `/provider/claim.php` | `provider/claim.php` | — |

`/includes/`, `/cron/`, `/sql/`, and `.env` are blocked by `.htaccess`.

### Key tables

| Table | Purpose |
|-------|---------|
| `services` | All CI-registered services; keyed by `cs_number` (e.g. `CS2003000001`); providers grouped by `sp_number` |
| `providers` | Organisations that have claimed listings on this directory |
| `listing_tiers` | One row per claimed service; `tier` = `free`/`premium`/`pro`; holds Stripe subscription state and enhanced profile fields |
| `enquiries` | Contact form submissions forwarded to providers |
| `import_log` | Audit trail for monthly CSV imports |

### Core helpers (`includes/functions.php`)

- `h(?string $s)` — always use for HTML output (htmlspecialchars wrapper).
- `slug(?string $s)` — creates SEO-friendly URL segments.
- `db()` — PDO singleton.
- `search_services(array $params, int $page, int $per_page)` — main directory search with filters (`q`, `type`, `council`, `sp`, `min_grade`, `min_avg`, `graded_within`, `sort`). Returns `['rows', 'total', 'pages', 'page', 'per_page']`. Joins `listing_tiers` and sorts pro→premium→free first.
- `get_service(string $cs_number)` — fetch single service row with its listing tier.
- `sql_avg_key_question_score()` — returns a SQL expression (alias `s`) computing the mean of the 6 key-question grades; mirrors `avg_grade()` in PHP.
- `grade_label(int $g)` / `grade_class(int $g)` — human label and CSS class for 1–6 CI grades.
- `paginate(int $total, int $page, int $pages, array $params)` — renders pagination HTML.
- `send_email()` — uses `mail()` (swap for PHPMailer in production).

### Listing tier sort priority

In all directory queries, `pro = 0`, `premium = 1`, `free/null = 2` — paid tiers always appear first within any filter set.

## Planned but not yet built

- `provider/dashboard.php`
- `provider/pricing.php` (Stripe Checkout)
- `provider/stripe_webhook.php`
- `admin/import.php` (UI wrapper around `cron/import.php`)

## Intent Layer

**Before modifying code in a subdirectory, read its AGENTS.md first** to understand local patterns and invariants.

- **Public pages**: [public/AGENTS.md](public/AGENTS.md) — all user-facing PHP pages served by Apache rewrites

### Global Invariants

- Every PHP file must define `ROOT` then `require ROOT . '/includes/db.php'` before doing anything.
- Always escape output with `h()` — never echo raw DB values.
- All DB access goes through the `db()` PDO singleton from `includes/db.php`; never create a second PDO.
- All search queries must include `service_status = 'Active' AND public_list = 1` — never expose cancelled/hidden services.
- Listing tier sort order is always `pro=0 → premium=1 → free/null=2`; paid tiers must appear first in every directory query.
- Input from `$_GET`/`$_POST` must be validated against a whitelist before use in queries; use parameterised PDO statements, never string interpolation.

### Subsystems (no dedicated node — document here)

**`includes/`** — config, DB, shared helpers. `env.php` loads `.env` then server env (server wins). `functions.php` owns `search_services()`, `get_service()`, `avg_grade()`, `sql_avg_key_question_score()`, `paginate()`, and grade helpers. Do not duplicate these in page files.

**`cron/`** — two scripts: `import.php` (monthly CI CSV → DB, also accepts a local file path arg) and `fetch_news.php`. These are CLI-only; never web-reachable (blocked by `.htaccess`).

**`sql/`** — `schema.sql` is canonical. Run numbered files in `migrations/` in order for additive changes. The root `schema.sql` and `import/run.php` target a legacy layout — ignore unless migrating.

**`provider/`** — stub files for the provider claim/dashboard flow (not yet built). `claim.php` is the only live file; `dashboard.php`, `pricing.php`, `stripe_webhook.php` are planned.

## Design Context

### Users
CareScotland Directory serves two overlapping groups: (1) families and individuals searching for care services for themselves or a relative — sometimes urgently, sometimes as considered, long-lead-time research — and (2) care providers who claim and manage their listings (free/premium/pro tiers). The interface must work equally well for a stressed, first-time visitor scanning quickly and a methodical user comparing grades across many services.

### Brand Personality
**Modern, sharp, efficient.** This is a polished, confident product — not a dusty government register and not an overly soft "caring" brochure. It should feel like a well-built modern SaaS/directory product that happens to serve a care-sector audience: clear information hierarchy, fast interactions, credible use of official Care Inspectorate data, and enough commercial polish to support paid provider tiers (premium/pro) without feeling like an ad-driven listings site.

### Aesthetic Direction
- **Theme**: Light mode only, for now.
- **Palette**: Open to moving away from the current green (#0f6e56) / red (#c41e3a) / cream (#f6f5f2) combination if critique warrants — but any replacement must still read as credible and Scotland/care-sector appropriate, not generic tech-startup blue.
- **No named references** — use judgment; avoid generic AI-template patterns (hero metric layout, glassmorphism, gradient text, identical card grids, gray-on-color text) and avoid looking like a cold bureaucratic register. Favor gov.uk-style clarity of information without gov.uk's visual plainness — this should look sharper and more designed.
- **Anti-reference**: generic AI-generated SaaS landing pages; also avoid looking like a purely emotional/soft charity brochure — tone should stay efficient and modern over "warm and caring."

### Design Principles
1. **Serve both urgency and deliberation** — critical actions (find a service, compare grades, contact a provider) must be reachable in seconds, but browsing/comparison views should support unhurried scanning without clutter.
2. **Credibility through clarity, not decoration** — official CI grades, statuses, and data should be the visual focus; avoid ornamental elements that dilute trust in the underlying data.
3. **Commercial polish without commercial pushiness** — premium/pro provider tiers should look like a natural product upgrade, not an ad or a paywall wall.
4. **Sharp, not sterile** — modern and efficient, but with enough character (typography, color, spacing decisions) that it doesn't read as a generic government form or generic AI-generated template.
5. **Accessible by default** — WCAG AA contrast minimum given the audience may include stressed, older, or first-time internet users; respect prefers-reduced-motion; never rely on color alone to convey grade/status meaning.
