# public/ — User-Facing Pages

## Purpose

Owns every PHP page that Apache routes to via `.htaccess` rewrites. All pages are request-scoped (no sessions, no shared state between requests). This directory does **not** own business logic — that lives in `../includes/functions.php`.

## Entry Points

| File | Route | Key `$_GET` param |
|------|-------|-------------------|
| `index.php` | `/` | `q`, `type`, `council`, `sp`, `sort`, `min_grade`, `min_avg`, `graded_within` |
| `service.php` | `/service/{cs_number}/{slug}` | `cs` |
| `provider.php` | `/provider/{sp_number}/{slug}` | `sp`, `sort`, `status`, `council`, `type`, `q` |
| `councils-map.php` | `/councils` | directory filters (preserved across map ↔ list) |
| `insights.php` | `/insights` | `scope`, `council`, `type`, `provider` |
| `news.php` | `/news` | — |
| `complaints.php` | `/complaints` | — |
| `complaints-metrics.php` | `/complaints/metrics` | — |

## Bootstrap Contract

Every file in this directory **must** open with:

```php
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';
```

`db.php` bootstraps the PDO singleton and loads env config. Never skip this or reorder it.

## Contracts & Invariants

- **Input validation before use**: all `$_GET` values must be validated against a whitelist or regex before being passed to queries. Use PDO parameterised statements — no string interpolation into SQL.
- **Output escaping**: always call `h()` before echoing any value from the DB or user input.
- **No raw DB queries for search**: use `search_services()` from `functions.php` — it handles `Active`/`public_list` filtering, tier sort, and pagination in one place.
- **Grade display**: use `grade_label(int $g)` and `grade_class(int $g)` from `functions.php`; never hard-code grade strings.
- **Average grades**: use `avg_grade(array $row)` (PHP) or `sql_avg_key_question_score()` (SQL alias `s`) — never recompute the 6-column average inline.
- **Tier sort**: `pro=0 → premium=1 → free/null=2` in every query that lists services.

## Patterns

**Adding a new public page:**
1. Create the PHP file here; open with the bootstrap contract above.
2. Validate all `$_GET` inputs against explicit whitelists.
3. Use helpers from `../includes/functions.php`; add new shared logic there (not in the page file).
4. Add the Apache rewrite rule in `../.htaccess`.
5. Update the routing table in `../CLAUDE.md` and this file.

**Pagination:** call `paginate($total, $page, $pages, $params)` from `functions.php` — it renders the HTML pagination block and preserves all active filters in links.

**Inspection grade staleness** (`service.php`): grades are flagged as `warn` (≥2y), `old` (≥3y), or `very_old` (≥5y) based on `grade_published`. Match this logic if building similar staleness indicators elsewhere.

## Anti-Patterns

- Don't write `db()` calls directly in page files for standard service lookups — use `search_services()` or `get_service()`.
- Don't open a second PDO connection; `db()` is a singleton.
- Don't echo `$_GET` values without `h()` — XSS risk.
- Don't add business logic (grade computation, search filtering) to page files; it belongs in `../includes/functions.php`.

## Related Context

- Shared helpers: `../includes/functions.php`
- DB + config bootstrap: `../includes/db.php`, `../includes/env.php`
- Map data helpers: `../includes/council_map.php`, `../includes/council_centroids.php`
- Insights data: `../includes/insights_data.php`
- Global invariants: `../CLAUDE.md` → Intent Layer
