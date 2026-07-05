# CareScotland Directory

PHP + MySQL care service directory built on [Care Inspectorate Datastore](https://www.careinspectorate.com/index.php/publications-statistics/44-public/93-datastore) open data (Open Government Licence).

## Configuration (.env)

Secrets and environment-specific values live in **`.env`** at the project root (next to `includes/`). This file is **git-ignored** — never commit it.

1. Copy **`.env.example`** → **`.env`**
2. Fill in `DB_*`, `SITE_URL`, and anything else you use (SMTP, Stripe, `CI_CSV_URL`).
3. **Local:** `APP_ENV=local` and `APP_DEBUG=true` are fine.
4. **Production (Hostinger):** set `APP_ENV=production`, `APP_DEBUG=false`, and production `SITE_URL` / database credentials. Upload `.env` via File Manager or SFTP to the same folder as `includes/`, or define the same variable names in the host’s environment if your plan supports it (non-empty server env vars override `.env` file values for those keys).

Variable names are listed in **`.env.example`**. PHP reads them via `includes/env.php` (`load_app_config()`); no Composer package is required.

## Which files to use

Use **one** stack to avoid schema clashes:

| Path | Role |
|------|------|
| `sql/schema.sql` | Canonical MySQL schema (`services`, `listing_tiers`, `enquiries`, …) |
| `.env` / `.env.example` | Runtime config (DB, URLs, Stripe, CSV URL) |
| `includes/env.php`, `includes/db.php`, `includes/functions.php` | Env parsing + PDO + helpers |
| `public/index.php`, `public/service.php`, `public/provider.php` | Directory UI; provider overview at `/provider/{sp_number}/{slug}` |
| `provider/claim.php` | Claim flow starter |
| `cron/import.php` | Monthly CSV import (matches `sql/schema.sql`) |

Legacy / alternate layout (root `schema.sql`, `import/run.php`) targets an older table layout. Prefer the table above unless you intentionally migrate.

## Run locally

**Prerequisites:** MySQL/MariaDB running, database created and `sql/schema.sql` imported, `.env` filled in (especially `DB_*` and `SITE_URL`).

### Option A — MAMP / Apache (matches production `.htaccess`)

Point the site’s document root at **this project folder** (the one that contains `.htaccess`). Ensure `mod_rewrite` is enabled. Open your vhost URL (e.g. `http://localhost:8888/`). Set `SITE_URL` in `.env` to that same base URL.

### Option B — PHP built-in server (quick, no Apache)

From the project root (same pattern many PHP apps use: `-t` = document root, last arg = router script):

```bash
php -S localhost:8080 -t . index.php
```

Open **http://localhost:8080/** and set **`SITE_URL=http://localhost:8080`** in `.env`.

**Port:** MAMP’s MySQL often uses **8889**, so avoid using **8889** for this HTTP server or you will get a bind error. Use **8080**, **8888**, or another free port. If you use `8889` for PHP, change MAMP MySQL to another port or stop MySQL while testing (not recommended).

## Setup (local MAMP / phpMyAdmin)

1. Copy `.env.example` to `.env` and set database credentials and `SITE_URL`.
2. In phpMyAdmin, create a database (e.g. `service-directory`). If the name contains a hyphen, use backticks in SQL: `` CREATE DATABASE `service-directory` … ``.
3. Import **`sql/schema.sql`** into that database.
4. Point Apache’s document root at this project folder. Ensure `mod_rewrite` is on. If the site lives in a subfolder (e.g. `http://localhost:8888/service-directory/`), set `RewriteBase /service-directory/` in `.htaccess`.
5. First import: from the project root, run `php cron/import.php` (CLI PHP must use the same MySQL as phpMyAdmin). On **MAMP**, add **`DB_PORT=8889`** (or **`DB_SOCKET`** to your MAMP `mysql.sock` path) in `.env` — the CLI often hits “connection refused” on `127.0.0.1:3306` while phpMyAdmin still works because MAMP’s MySQL uses another port or socket.
6. Production: schedule `0 6 1 * * php /full/path/to/cron/import.php` monthly.
7. Optional: schedule `0 7 * * 1 php /full/path/to/cron/check_datastore_coverage.php` weekly — checks the CI Datastore listing for any of the last ~13 months' files you don't have yet and downloads them into `storage/imports/pending/` for review (see `admin/imports.php`).

If the remote CSV returns **403** on your host, download the file in a browser from the [Datastore](https://www.careinspectorate.com/index.php/publications-statistics/44-public/93-datastore) and run `php cron/import.php /path/to/DatastoreExternal.csv`.

If import fails with **SQLSTATE 1366 Incorrect string value**, the DB or connection may not be utf8mb4: run **`sql/ensure_utf8mb4.sql`** in phpMyAdmin (edit the database name in the first line if yours is not `` `service-directory` ``), then run the import again. The app also sets `SET NAMES utf8mb4` on each PDO connection and normalises CSV text during import.

## GitHub & Hostinger

- Remote example: [github.com/wellis321/service-directory](https://github.com/wellis321/service-directory.git) — push **without** `.env`; commit **`.env.example`** only.
- After clone or pull on the server, create **`.env`** again from `.env.example` and fill production values (or rely on host-set environment variables for the same keys).

## Next files to build

- provider/dashboard.php
- provider/pricing.php (Stripe Checkout)
- provider/stripe_webhook.php
- admin/import.php (optional UI around `cron/import.php`)

`assets/style.css` is a starter stylesheet linked from `public/` pages; extend or replace as you like.

## Cursor / Claude context prompt

Paste this at the start of each Cursor session:

> PHP + MySQL care directory. Config from `.env` via `load_app_config()` in `includes/env.php`. PDO via `db()` in `includes/db.php`. Output via `h()`. Services in `services` keyed by `cs_number`. Provider orgs grouped by Care Inspectorate `sp_number` — overview at `/provider/{sp_number}/{slug}`. Providers claim via `listing_tiers` (free/premium/pro). URL pattern: `/service/{cs_number}/{slug}`
