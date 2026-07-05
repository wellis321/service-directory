<?php
// =============================================================
// admin/data-status.php — Internal data metrics dashboard
// =============================================================
// Protected by HTTP Basic Auth via admin/.htaccess. Update the
// credentials in admin/.htpasswd before deploying to production.
// =============================================================
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';

$pdo = db();

// ── CSV column headers (saved by importer) ────────────────────
$headers_file    = ROOT . '/storage/imports/headers.json';
$csv_headers     = null;
$csv_imported_at = null;
$csv_col_changes = ['missing_mapped' => [], 'added' => [], 'removed' => []];
if (is_file($headers_file)) {
    $hdata = json_decode(file_get_contents($headers_file), true);
    if (is_array($hdata)) {
        $csv_headers     = $hdata['columns'] ?? [];
        $csv_imported_at = $hdata['imported_at'] ?? null;
        $csv_col_changes = $hdata['column_changes'] ?? $csv_col_changes;
    }
}

// ── Saved CSV archives ────────────────────────────────────────
$gz_files = glob(ROOT . '/storage/imports/*.csv.gz') ?: [];
rsort($gz_files); // newest first

// ── Import history ────────────────────────────────────────────
$imports = $pdo->query("
    SELECT * FROM import_log ORDER BY started_at DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

$last_ok = null;
foreach ($imports as $row) {
    if ($row['status'] === 'complete') { $last_ok = $row; break; }
}

// ── Headline counts ───────────────────────────────────────────
$total_rows      = (int) $pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();
$active_rows     = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE service_status='Active'")->fetchColumn();
$active_public   = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE service_status='Active' AND public_list=1")->fetchColumn();
$active_nonpublic= $active_rows - $active_public;

// ── Registration uniqueness ───────────────────────────────────
// Same postcode + address line 1 with more than one active CS number
$shared_address_count = (int) $pdo->query("
    SELECT COUNT(*) FROM (
        SELECT address_1, postcode
        FROM services
        WHERE service_status='Active' AND postcode IS NOT NULL AND postcode != ''
          AND address_1 IS NOT NULL AND address_1 != ''
        GROUP BY address_1, postcode
        HAVING COUNT(*) > 1
    ) t
")->fetchColumn();

$shared_address_services = (int) $pdo->query("
    SELECT COUNT(*) FROM services
    WHERE service_status='Active'
      AND postcode IS NOT NULL AND postcode != ''
      AND address_1 IS NOT NULL AND address_1 != ''
      AND (address_1, postcode) IN (
          SELECT address_1, postcode
          FROM services
          WHERE service_status='Active' AND postcode IS NOT NULL AND postcode != ''
            AND address_1 IS NOT NULL AND address_1 != ''
          GROUP BY address_1, postcode
          HAVING COUNT(*) > 1
      )
")->fetchColumn();

$shared_addresses = $pdo->query("
    SELECT address_1, MAX(town) AS town, postcode, COUNT(*) AS cnt,
           GROUP_CONCAT(care_service ORDER BY care_service SEPARATOR ', ') AS types,
           GROUP_CONCAT(cs_number ORDER BY cs_number SEPARATOR ', ') AS cs_numbers
    FROM services
    WHERE service_status='Active' AND postcode IS NOT NULL AND postcode != ''
      AND address_1 IS NOT NULL AND address_1 != ''
    GROUP BY address_1, postcode
    HAVING COUNT(*) > 1
    ORDER BY cnt DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// ── By status ─────────────────────────────────────────────────
$by_status = $pdo->query("
    SELECT service_status, COUNT(*) AS cnt
    FROM services GROUP BY service_status ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── By type (active only) ─────────────────────────────────────
$by_type = $pdo->query("
    SELECT care_service, COUNT(*) AS cnt
    FROM services WHERE service_status='Active'
    GROUP BY care_service ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── By council (active only) ──────────────────────────────────
$by_council = $pdo->query("
    SELECT council_area, COUNT(*) AS cnt
    FROM services WHERE service_status='Active' AND council_area IS NOT NULL
    GROUP BY council_area ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Top providers by registration count ───────────────────────
$top_providers = $pdo->query("
    SELECT provider_name, sp_number, COUNT(*) AS cnt
    FROM services WHERE service_status='Active'
      AND provider_name IS NOT NULL AND provider_name != ''
    GROUP BY sp_number, provider_name
    ORDER BY cnt DESC
    LIMIT 25
")->fetchAll(PDO::FETCH_ASSOC);

// ── Grading coverage ──────────────────────────────────────────
$graded = (int) $pdo->query("
    SELECT COUNT(*) FROM services WHERE service_status='Active'
      AND (grade_wellbeing IS NOT NULL OR grade_planning IS NOT NULL
        OR grade_setting IS NOT NULL OR grade_staff IS NOT NULL
        OR grade_leadership IS NOT NULL OR grade_cpl IS NOT NULL)
")->fetchColumn();
$grade_pct = $active_rows > 0 ? round(100 * $graded / $active_rows) : 0;

$graded_within_year = (int) $pdo->query("
    SELECT COUNT(*) FROM services WHERE service_status='Active'
      AND grade_published >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
")->fetchColumn();
$graded_within_2yr = (int) $pdo->query("
    SELECT COUNT(*) FROM services WHERE service_status='Active'
      AND grade_published >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
")->fetchColumn();
$never_graded = (int) $pdo->query("
    SELECT COUNT(*) FROM services WHERE service_status='Active'
      AND grade_wellbeing IS NULL AND grade_planning IS NULL
      AND grade_setting IS NULL AND grade_staff IS NULL
      AND grade_leadership IS NULL AND grade_cpl IS NULL
")->fetchColumn();

// ── Data quality gaps ─────────────────────────────────────────
$no_postcode  = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE service_status='Active' AND (postcode IS NULL OR postcode='')")->fetchColumn();
$no_phone     = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE service_status='Active' AND (phone IS NULL OR phone='')")->fetchColumn();
$no_council   = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE service_status='Active' AND (council_area IS NULL OR council_area='')")->fetchColumn();
$no_email     = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE service_status='Active' AND (email IS NULL OR email='')")->fetchColumn();

// ── Gap drilldown ─────────────────────────────────────────────
$gap_definitions = [
    'no_postcode' => [
        'label'   => 'Missing postcode',
        'where'   => "(postcode IS NULL OR postcode = '')",
        'columns' => ['address_1', 'address_2', 'town', 'postcode'],
        'headers' => ['Address 1', 'Address 2', 'Town', 'Postcode (raw)'],
        'focus'   => 'postcode',
    ],
    'no_phone' => [
        'label'   => 'No phone number',
        'where'   => "(phone IS NULL OR phone = '')",
        'columns' => ['town', 'postcode', 'phone'],
        'headers' => ['Town', 'Postcode', 'Phone (raw)'],
        'focus'   => 'phone',
    ],
    'no_email' => [
        'label'   => 'No email address',
        'where'   => "(email IS NULL OR email = '')",
        'columns' => ['town', 'postcode', 'email'],
        'headers' => ['Town', 'Postcode', 'Email (raw)'],
        'focus'   => 'email',
    ],
    'no_council' => [
        'label'   => 'Missing council area',
        'where'   => "(council_area IS NULL OR council_area = '')",
        'columns' => ['town', 'postcode', 'council_area', 'health_board'],
        'headers' => ['Town', 'Postcode', 'Council area (raw)', 'Health board'],
        'focus'   => 'council_area',
    ],
];

$gap_key  = isset($_GET['gap'], $gap_definitions[$_GET['gap']]) ? $_GET['gap'] : null;
$gap_rows = [];
$gap_total = 0;
$gap_page  = max(1, (int)($_GET['gpage'] ?? 1));
$gap_per   = 50;

if ($gap_key !== null) {
    $gap_def    = $gap_definitions[$gap_key];
    $gap_total  = (int) $pdo->query("
        SELECT COUNT(*) FROM services
        WHERE service_status='Active' AND {$gap_def['where']}
    ")->fetchColumn();
    $gap_offset  = ($gap_page - 1) * $gap_per;
    $extra_cols  = implode(', ', $gap_def['columns']);
    $gap_rows = $pdo->query("
        SELECT cs_number, service_name, care_service, provider_name, {$extra_cols}
        FROM services
        WHERE service_status='Active' AND {$gap_def['where']}
        ORDER BY service_name
        LIMIT {$gap_per} OFFSET {$gap_offset}
    ")->fetchAll(PDO::FETCH_ASSOC);
    $gap_pages = (int) ceil($gap_total / $gap_per);
}

// ── Dates ─────────────────────────────────────────────────────
$newest_reg  = $pdo->query("SELECT MAX(date_registered) FROM services WHERE service_status='Active'")->fetchColumn();
$oldest_reg  = $pdo->query("SELECT MIN(date_registered) FROM services WHERE service_status='Active' AND date_registered IS NOT NULL")->fetchColumn();
$ci_snapshot = $pdo->query("SELECT MAX(ci_last_updated) FROM services WHERE ci_last_updated IS NOT NULL")->fetchColumn();

// ── Helpers ───────────────────────────────────────────────────
function fmt_bytes(int $b): string {
    if ($b >= 1048576) return number_format($b / 1048576, 2) . ' MB';
    if ($b >= 1024)    return number_format($b / 1024, 1) . ' KB';
    return $b . ' B';
}

function pct(int $part, int $total): string {
    return $total > 0 ? number_format(100 * $part / $total, 1) . '%' : '—';
}

function bar(int $pct, string $color = '#2e7d32'): string {
    return "<div class=\"bar\"><div class=\"bar__fill\" style=\"width:{$pct}%;background:{$color}\"></div></div>";
}

function status_badge(string $s): string {
    $map = [
        'complete'              => '#2e7d32',
        'complete_with_warnings'=> '#f57c00',
        'failed'                => '#c62828',
        'running'               => '#1565c0',
    ];
    $bg = $map[$s] ?? '#555';
    $label = $s === 'complete_with_warnings' ? 'warnings' : $s;
    return "<span class=\"badge\" style=\"background:{$bg}\">{$label}</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Data Metrics · CareScotland Admin</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; font-size: 14px; background: #f0f2f5; color: #1a1a1a; }

  .page { max-width: 1200px; margin: 0 auto; padding: 28px 16px 60px; }

  .page-header { margin-bottom: 28px; }
  .page-header h1 { font-size: 1.5rem; font-weight: 700; }
  .page-header p  { color: #666; margin-top: 4px; font-size: 0.9em; }

  .back { display: inline-flex; align-items: center; gap: 4px; color: #1565c0; text-decoration: none; font-size: 0.85em; margin-bottom: 16px; }
  .back:hover { text-decoration: underline; }

  /* Sections */
  .section { margin-top: 36px; }
  .section__title { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #888; margin-bottom: 12px; }

  /* Stat tiles */
  .tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; }
  .tile { background: #fff; border: 1px solid #e4e4e4; border-radius: 10px; padding: 18px 16px; }
  .tile__val  { font-size: 2rem; font-weight: 700; line-height: 1; }
  .tile__label{ color: #666; font-size: 0.78em; margin-top: 6px; line-height: 1.3; }
  .tile--ok   .tile__val { color: #1b5e20; }
  .tile--warn .tile__val { color: #b71c1c; }
  .tile--info .tile__val { color: #0d47a1; }
  .tile--active { outline: 2px solid #1565c0; }

  /* Alert banner */
  .alert { padding: 12px 16px; margin-bottom: 20px; font-size: 0.9em; border-left: 4px solid; }
  .alert--ok   { background: #f1f8e9; border-color: #7cb342; }
  .alert--warn { background: #fff8e1; border-color: #f9a825; }
  .alert--bad  { background: #fce4ec; border-color: #c62828; }

  /* Explanation box */
  .explain { background: #fff; border: 1px solid #e4e4e4; border-radius: 10px; padding: 16px; font-size: 0.88em; line-height: 1.6; color: #444; }
  .explain strong { color: #111; }

  /* Tables */
  .table-wrap { background: #fff; border: 1px solid #e4e4e4; border-radius: 10px; overflow: hidden; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #f7f7f7; text-align: left; padding: 9px 14px; font-weight: 600; font-size: 0.8em; color: #555; border-bottom: 1px solid #e4e4e4; }
  td { padding: 8px 14px; border-top: 1px solid #f0f0f0; vertical-align: middle; }
  tr:hover td { background: #fafafa; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .muted { color: #aaa; }
  .mono { font-family: monospace; font-size: 0.82em; }

  /* Progress bar */
  .bar { height: 6px; background: #e8e8e8; border-radius: 3px; margin-top: 8px; }
  .bar__fill { height: 100%; border-radius: 3px; }

  /* Badge */
  .badge { display: inline-block; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 0.78em; font-weight: 600; }

  /* Two / three column grids */
  .two-col   { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
  .three-col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
  @media (max-width: 780px) { .two-col, .three-col { grid-template-columns: 1fr; } }

  /* Footer note */
  .page-footer { margin-top: 48px; color: #bbb; font-size: 0.78em; border-top: 1px solid #e4e4e4; padding-top: 16px; }

  .highlight-row td { background: #fff8e1 !important; }
</style>
</head>
<body>
<div class="page">

  <div style="display:flex;gap:16px;align-items:center;margin-bottom:16px;">
    <a href="/" class="back" style="margin-bottom:0;">← Directory</a>
    <a href="/admin/imports.php" class="back" style="margin-bottom:0;">⬆ Import Manager</a>
  </div>

  <div class="page-header">
    <h1>Data Metrics</h1>
    <p>Internal view of what's in the database and how it was imported. Refreshed on every page load · <?= date('j M Y, H:i') ?></p>
  </div>

  <?php
    if (!$last_ok):
  ?>
    <div class="alert alert--bad">No successful import run on record. Run <code>php cron/import.php</code> to populate the database.</div>
  <?php else:
    $age_days  = (int) floor((time() - strtotime((string)$last_ok['finished_at'])) / 86400);
    $age_class = $age_days > 40 ? 'alert--warn' : 'alert--ok';
  ?>
    <div class="alert <?= $age_class ?>">
      Last successful import: <strong><?= date('j M Y \a\t H:i', strtotime((string)$last_ok['finished_at'])) ?></strong>
      · <?= $age_days ?> day<?= $age_days !== 1 ? 's' : '' ?> ago
      · CI snapshot date in DB: <strong><?= $ci_snapshot ? date('j M Y', strtotime((string)$ci_snapshot)) : 'unknown' ?></strong>
      <?= $age_days > 40 ? ' · <strong>Overdue for monthly refresh</strong>' : '' ?>
    </div>
  <?php endif; ?>

  <?php if ($csv_col_changes['missing_mapped']): ?>
  <div class="alert alert--bad" style="border-left-width:4px;border-left-color:#c62828;">
    <strong>⚠ Column mismatch detected in last import.</strong>
    The following columns were expected but <em>missing</em> from the CI CSV —
    these fields will have been stored as NULL for every row in that import:<br>
    <code style="display:block;margin-top:6px;color:#c62828;"><?= h(implode(', ', $csv_col_changes['missing_mapped'])) ?></code>
    <span style="font-size:0.85em;color:#888;display:block;margin-top:6px;">
      CI may have renamed these columns. Check the "Care Inspectorate CSV" section below and compare with the unmapped list.
    </span>
  </div>
  <?php elseif ($csv_col_changes['added'] || $csv_col_changes['removed']): ?>
  <div class="alert alert--warn">
    <strong>Column changes since last import:</strong>
    <?php if ($csv_col_changes['added']): ?>
      <span style="color:#2e7d32;">+<?= count($csv_col_changes['added']) ?> added</span>
      (<code><?= h(implode(', ', $csv_col_changes['added'])) ?></code>)
    <?php endif; ?>
    <?php if ($csv_col_changes['removed']): ?>
      &nbsp; <span style="color:#c62828;">−<?= count($csv_col_changes['removed']) ?> removed</span>
      (<code><?= h(implode(', ', $csv_col_changes['removed'])) ?></code>)
    <?php endif; ?>
    — all mapped columns are still present so data import was not affected.
  </div>
  <?php elseif ($csv_headers !== null): ?>
  <div class="alert alert--ok">Column check passed — all mapped columns were present in the last import.</div>
  <?php endif; ?>


  <!-- ═══════════════════════════════════════════════════════════
       SECTION 1 — What the homepage number actually means
  ════════════════════════════════════════════════════════════ -->
  <div class="section">
    <div class="section__title">What does the homepage count mean?</div>
    <div class="explain">
      The headline on the homepage shows <strong><?= number_format($active_public) ?> active, publicly-listed registrations</strong>
      (rows where <code>service_status = 'Active'</code> and <code>public_list = 1</code>).
      Each row is a unique <strong>CS number</strong> — the Care Inspectorate's own registration ID — so the same registration
      cannot appear twice. However, one physical address can hold multiple registrations (e.g. a care home registered
      separately as a Care Home Service <em>and</em> a Support Service). See the <em>Multiple registrations per address</em>
      section below for the full picture.
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════
       SECTION 2 — Headline tiles
  ════════════════════════════════════════════════════════════ -->
  <div class="section">
    <div class="section__title">Counts at a glance</div>
    <div class="tiles">
      <div class="tile">
        <div class="tile__val"><?= number_format($total_rows) ?></div>
        <div class="tile__label">Total rows in DB (all statuses)</div>
      </div>
      <div class="tile tile--ok">
        <div class="tile__val"><?= number_format($active_rows) ?></div>
        <div class="tile__label">Active registrations</div>
      </div>
      <div class="tile tile--info">
        <div class="tile__val"><?= number_format($active_public) ?></div>
        <div class="tile__label">Active + publicly listed <small>(homepage figure)</small></div>
      </div>
      <div class="tile">
        <div class="tile__val"><?= number_format($active_nonpublic) ?></div>
        <div class="tile__label">Active but not publicly listed (CI-suppressed)</div>
      </div>
      <div class="tile <?= $shared_address_count > 0 ? 'tile--warn' : 'tile--ok' ?>">
        <div class="tile__val"><?= number_format($shared_address_count) ?></div>
        <div class="tile__label">Addresses with 2+ active registrations</div>
      </div>
      <div class="tile">
        <div class="tile__val"><?= number_format($shared_address_services) ?></div>
        <div class="tile__label">Active registrations at those shared addresses</div>
      </div>
    </div>
  </div>


  <!-- ═══════════════════════════════════════════════════════════
       SECTION 3 — Status breakdown
  ════════════════════════════════════════════════════════════ -->
  <div class="section">
    <div class="section__title">All registrations by status</div>
    <div class="table-wrap">
      <table>
        <tr><th>Status</th><th class="num">Count</th><th class="num">% of total</th><th style="width:200px">Share</th></tr>
        <?php foreach ($by_status as $r): ?>
        <tr>
          <td><?= h((string)($r['service_status'] ?? 'Unknown')) ?></td>
          <td class="num"><?= number_format((int)$r['cnt']) ?></td>
          <td class="num"><?= pct((int)$r['cnt'], $total_rows) ?></td>
          <td><?= bar($total_rows > 0 ? (int)round(100 * $r['cnt'] / $total_rows) : 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>


  <!-- ═══════════════════════════════════════════════════════════
       SECTION 4 — Multiple registrations at same address
  ════════════════════════════════════════════════════════════ -->
  <div class="section">
    <div class="section__title">Multiple registrations per address (active only)</div>
    <?php if (empty($shared_addresses)): ?>
      <div class="alert alert--ok">No active addresses with more than one registration found.</div>
    <?php else: ?>
      <p style="color:#555;font-size:0.85em;margin-bottom:10px;">
        These addresses have more than one active CS number. This is often legitimate (different service types
        at the same premises) but worth reviewing.
      </p>
      <div class="table-wrap">
        <table>
          <tr>
            <th>Address</th>
            <th>Town</th>
            <th>Postcode</th>
            <th class="num">Registrations</th>
            <th>Service types</th>
            <th>CS numbers</th>
          </tr>
          <?php foreach ($shared_addresses as $r): ?>
          <tr <?= (int)$r['cnt'] >= 3 ? 'class="highlight-row"' : '' ?>>
            <td><?= h((string)$r['address_1']) ?></td>
            <td><?= h((string)$r['town']) ?></td>
            <td class="mono"><?= h((string)$r['postcode']) ?></td>
            <td class="num"><strong><?= (int)$r['cnt'] ?></strong></td>
            <td style="font-size:0.82em;color:#555;"><?= h((string)$r['types']) ?></td>
            <td class="mono" style="font-size:0.78em;color:#888;"><?= h((string)$r['cs_numbers']) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endif; ?>
  </div>


  <!-- ═══════════════════════════════════════════════════════════
       SECTION 5 — Type + Council breakdown
  ════════════════════════════════════════════════════════════ -->
  <div class="section two-col">

    <div>
      <div class="section__title">Active registrations by service type</div>
      <div class="table-wrap">
        <table>
          <tr><th>Type</th><th class="num">Count</th><th class="num">%</th></tr>
          <?php foreach ($by_type as $r): ?>
          <tr>
            <td><?= h((string)($r['care_service'] ?? 'Unknown')) ?></td>
            <td class="num"><?= number_format((int)$r['cnt']) ?></td>
            <td class="num"><?= pct((int)$r['cnt'], $active_rows) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

    <div>
      <div class="section__title">Active registrations by council area</div>
      <div class="table-wrap">
        <table>
          <tr><th>Council</th><th class="num">Count</th><th class="num">%</th></tr>
          <?php foreach ($by_council as $r): ?>
          <tr>
            <td><?= h((string)($r['council_area'] ?? 'Unknown')) ?></td>
            <td class="num"><?= number_format((int)$r['cnt']) ?></td>
            <td class="num"><?= pct((int)$r['cnt'], $active_rows) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

  </div>


  <!-- ═══════════════════════════════════════════════════════════
       SECTION 6 — Top providers
  ════════════════════════════════════════════════════════════ -->
  <div class="section">
    <div class="section__title">Top 25 providers by number of active registrations</div>
    <div class="table-wrap">
      <table>
        <tr><th>Provider name</th><th>SP number</th><th class="num">Registrations</th><th style="width:180px">Share of active</th></tr>
        <?php foreach ($top_providers as $r): ?>
        <tr>
          <td><?= h((string)$r['provider_name']) ?></td>
          <td class="mono muted"><?= h((string)$r['sp_number']) ?></td>
          <td class="num"><strong><?= number_format((int)$r['cnt']) ?></strong></td>
          <td><?= bar($active_rows > 0 ? (int)min(100, round(100 * $r['cnt'] / $active_rows)) : 0, '#1565c0') ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>


  <!-- ═══════════════════════════════════════════════════════════
       SECTION 7 — Grading coverage
  ════════════════════════════════════════════════════════════ -->
  <div class="section">
    <div class="section__title">Inspection grade coverage (active registrations)</div>
    <div class="tiles">
      <div class="tile">
        <div class="tile__val"><?= number_format($graded) ?></div>
        <div class="tile__label">Have at least one grade (<?= $grade_pct ?>%)</div>
        <?= bar($grade_pct) ?>
      </div>
      <div class="tile">
        <div class="tile__val"><?= number_format($never_graded) ?></div>
        <div class="tile__label">Never graded</div>
      </div>
      <div class="tile tile--ok">
        <div class="tile__val"><?= number_format($graded_within_year) ?></div>
        <div class="tile__label">Graded within the last 12 months</div>
      </div>
      <div class="tile">
        <div class="tile__val"><?= number_format($graded_within_2yr) ?></div>
        <div class="tile__label">Graded within the last 2 years</div>
      </div>
    </div>
  </div>


  <!-- ═══════════════════════════════════════════════════════════
       SECTION 8 — Data quality gaps
  ════════════════════════════════════════════════════════════ -->
  <div class="section">
    <div class="section__title">Data quality gaps (active registrations) — click a tile to inspect rows</div>
    <div class="tiles">
      <?php
        $gap_tiles = [
          'no_postcode' => [$no_postcode, 'Missing postcode',     $no_postcode > 0 ? 'tile--warn' : 'tile--ok'],
          'no_phone'    => [$no_phone,    'No phone number',      $no_phone > 500  ? 'tile--warn' : ''],
          'no_email'    => [$no_email,    'No email address',     $no_email > 1000 ? 'tile--warn' : ''],
          'no_council'  => [$no_council,  'Missing council area', $no_council > 0  ? 'tile--warn' : 'tile--ok'],
        ];
        foreach ($gap_tiles as $gk => [$gcount, $glabel, $gcls]):
          $active = $gap_key === $gk;
      ?>
      <a href="?gap=<?= $gk ?>#gap-drilldown"
         class="tile <?= $gcls ?> <?= $active ? 'tile--active' : '' ?>"
         style="text-decoration:none;cursor:pointer;display:block;">
        <div class="tile__val"><?= number_format($gcount) ?></div>
        <div class="tile__label"><?= $glabel ?></div>
        <div style="margin-top:6px;font-size:0.75em;color:<?= $active ? '#1565c0' : '#888' ?>">
          <?= $active ? '▲ viewing rows below' : 'Click to inspect →' ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <p style="color:#888;font-size:0.8em;margin-top:10px;">
      Registration date range: <strong><?= $oldest_reg ? date('j M Y', strtotime((string)$oldest_reg)) : '—' ?></strong>
      to <strong><?= $newest_reg ? date('j M Y', strtotime((string)$newest_reg)) : '—' ?></strong>
    </p>

    <!-- Gap drilldown panel -->
    <?php if ($gap_key !== null): ?>
    <div id="gap-drilldown" style="margin-top:24px;">
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:10px;">
        <strong><?= h($gap_definitions[$gap_key]['label']) ?></strong>
        <span style="color:#888;font-size:0.85em;"><?= number_format($gap_total) ?> rows · showing <?= $gap_per ?> per page</span>
        <a href="?" style="font-size:0.82em;color:#888;">✕ Close</a>
      </div>

      <?php if (empty($gap_rows)): ?>
        <p class="muted">No rows found — the data may have been fixed since the counts were calculated.</p>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <tr>
            <th>CS Number</th>
            <th>Service name</th>
            <th>Type</th>
            <th>Provider</th>
            <?php foreach ($gap_def['headers'] as $hdr): ?>
              <th><?= h($hdr) ?></th>
            <?php endforeach; ?>
          </tr>
          <?php foreach ($gap_rows as $r): ?>
          <tr>
            <td class="mono" style="white-space:nowrap;">
              <a href="/service/<?= h($r['cs_number']) ?>/<?= slug($r['service_name']) ?>" target="_blank"
                 style="color:#1565c0;"><?= h($r['cs_number']) ?></a>
            </td>
            <td><?= h($r['service_name']) ?></td>
            <td style="font-size:0.82em;color:#555;"><?= h((string)$r['care_service']) ?></td>
            <td style="font-size:0.82em;"><?= h((string)$r['provider_name']) ?></td>
            <?php foreach ($gap_def['columns'] as $col):
              $val     = (string)($r[$col] ?? '');
              $is_focus   = $col === $gap_def['focus'];
              $is_missing = $val === '';
              $highlight  = $is_focus && $is_missing ? 'background:#fff3cd;' : '';
            ?>
              <td class="mono" style="font-size:0.85em;<?= $highlight ?>">
                <?= $is_missing
                  ? '<span class="muted">' . ($is_focus ? 'empty / NULL' : '—') . '</span>'
                  : h($val) ?>
              </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

      <?php if ($gap_pages > 1): ?>
      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:0.85em;">
        <?php if ($gap_page > 1): ?>
          <a href="?gap=<?= $gap_key ?>&gpage=<?= $gap_page - 1 ?>#gap-drilldown" style="color:#1565c0;">← Previous</a>
        <?php endif; ?>
        <span style="color:#888;">Page <?= $gap_page ?> of <?= $gap_pages ?></span>
        <?php if ($gap_page < $gap_pages): ?>
          <a href="?gap=<?= $gap_key ?>&gpage=<?= $gap_page + 1 ?>#gap-drilldown" style="color:#1565c0;">Next →</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>


  <!-- ═══════════════════════════════════════════════════════════
       SECTION 9 — CSV column headers from Care Inspectorate
  ════════════════════════════════════════════════════════════ -->
  <div class="section">
    <div class="section__title">Care Inspectorate CSV — all available columns</div>
    <?php if ($csv_headers === null): ?>
      <div class="alert alert--warn">
        No headers saved yet. Run <code>php cron/import.php</code> once and they will appear here automatically.
      </div>
    <?php else: ?>
      <p style="color:#555;font-size:0.85em;margin-bottom:12px;">
        From import on <strong><?= h((string)$csv_imported_at) ?></strong> ·
        <?= count($csv_headers) ?> columns in the CI CSV ·
        Use this to check whether a field you expect (e.g. email) is present under a different column name.
      </p>

      <?php
        // Columns our importer actually reads
        $mapped = [
            'CSNumber','CareService','Subtype','AdditionalSubtypes','ServiceType',
            'ServiceName','Address_line_1','Address_line_2','Address_line_3','Address_line_4',
            'Service_town','Service_Postcode','Service_Phone_Number','Eforms_email_address',
            'Manager_Name','SP_number','ServiceProvider','Provided_by_Local_Authority',
            'ServiceStatus','Date_Reg','DateReg','SIMD_rank','SIMD2020_Decile','Datazone',
            'Integration_Authority_Name','Council_Area_Name','Health_Board_Name',
            'TotalBeds','SingleBedrooms','Registered_Places','NumberStaff','Client_group',
            'CareHome_Main_Area_of_Care','Care_Home_All_Areas_of_Provision','PublicList',
            'KQ_Support_Wellbeing','KQ_Care_and_Support_Planning','KQ_Setting',
            'KQ_Staff_Team','KQ_Leadership','KQ_Care_Play_and_Learning',
            'MinGrade','MaxGrade','GradeSpread','Publication_of_Latest_Grading',
            'RAD_SAT_Score','Complaints_Upheld','Comp',
        ];
        $mapped_set = array_flip($mapped);
        $unmapped = array_filter($csv_headers, fn($c) => !isset($mapped_set[$c]));
      ?>

      <div class="two-col">
        <div>
          <div style="font-size:0.78em;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#2e7d32;margin-bottom:6px;">
            Mapped (<?= count($mapped_set) ?> — imported into DB)
          </div>
          <div class="table-wrap">
            <table>
              <tr><th>#</th><th>Column name in CSV</th><th>DB field</th></tr>
              <?php
                $db_map = [
                    'CSNumber' => 'cs_number', 'CareService' => 'care_service',
                    'Subtype' => 'subtype', 'AdditionalSubtypes' => 'additional_subtypes',
                    'ServiceType' => 'service_type', 'ServiceName' => 'service_name',
                    'Address_line_1' => 'address_1', 'Address_line_2' => 'address_2',
                    'Address_line_3' => 'address_3', 'Address_line_4' => 'address_4',
                    'Service_town' => 'town', 'Service_Postcode' => 'postcode',
                    'Service_Phone_Number' => 'phone', 'Eforms_email_address' => 'email',
                    'Manager_Name' => 'manager_name', 'SP_number' => 'sp_number',
                    'ServiceProvider' => 'provider_name',
                    'Provided_by_Local_Authority' => 'provided_by_la',
                    'ServiceStatus' => 'service_status', 'Date_Reg' => 'date_registered',
                    'SIMD_rank' => 'simd_rank', 'SIMD2020_Decile' => 'simd_decile',
                    'Datazone' => 'datazone',
                    'Integration_Authority_Name' => 'integration_auth',
                    'Council_Area_Name' => 'council_area', 'Health_Board_Name' => 'health_board',
                    'TotalBeds' => 'total_beds', 'SingleBedrooms' => 'single_bedrooms',
                    'Registered_Places' => 'registered_places', 'NumberStaff' => 'num_staff',
                    'Client_group' => 'client_group',
                    'CareHome_Main_Area_of_Care' => 'care_home_main_area',
                    'Care_Home_All_Areas_of_Provision' => 'care_home_areas',
                    'PublicList' => 'public_list',
                    'KQ_Support_Wellbeing' => 'grade_wellbeing',
                    'KQ_Care_and_Support_Planning' => 'grade_planning',
                    'KQ_Setting' => 'grade_setting', 'KQ_Staff_Team' => 'grade_staff',
                    'KQ_Leadership' => 'grade_leadership',
                    'KQ_Care_Play_and_Learning' => 'grade_cpl',
                    'MinGrade' => 'grade_min', 'MaxGrade' => 'grade_max',
                    'GradeSpread' => 'grade_spread',
                    'Publication_of_Latest_Grading' => 'grade_published',
                    'RAD_SAT_Score' => 'rad_sat_score',
                    'Complaints_Upheld' => 'complaints_upheld',
                ];
                $i = 0;
                foreach ($csv_headers as $col):
                  if (!isset($mapped_set[$col])) continue;
                  $i++;
              ?>
              <tr>
                <td class="muted" style="font-size:0.8em;"><?= $i ?></td>
                <td class="mono" style="font-size:0.82em;"><?= h($col) ?></td>
                <td class="mono" style="font-size:0.78em;color:#888;"><?= h($db_map[$col] ?? '—') ?></td>
              </tr>
              <?php endforeach; ?>
            </table>
          </div>
        </div>

        <div>
          <div style="font-size:0.78em;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#c62828;margin-bottom:6px;">
            Not imported (<?= count($unmapped) ?> — in CSV but ignored)
          </div>
          <?php if (empty($unmapped)): ?>
            <p class="muted" style="font-size:0.85em;">All CSV columns are mapped.</p>
          <?php else: ?>
          <div class="table-wrap">
            <table>
              <tr><th>#</th><th>Column name in CSV</th><th>Notes</th></tr>
              <?php $i = 0; foreach ($unmapped as $col): $i++; ?>
              <tr>
                <td class="muted" style="font-size:0.8em;"><?= $i ?></td>
                <td class="mono" style="font-size:0.82em;color:#c62828;"><?= h($col) ?></td>
                <td style="font-size:0.78em;color:#aaa;">not imported</td>
              </tr>
              <?php endforeach; ?>
            </table>
          </div>
          <?php endif; ?>

          <?php if (!empty($gz_files)): ?>
          <div style="margin-top:20px;">
            <div style="font-size:0.78em;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#555;margin-bottom:6px;">
              Saved CSV archives
            </div>
            <div class="table-wrap">
              <table>
                <tr><th>File</th><th>Size</th><th>Date</th></tr>
                <?php foreach ($gz_files as $gz): ?>
                <tr>
                  <td class="mono" style="font-size:0.82em;"><?= h(basename($gz)) ?></td>
                  <td style="font-size:0.82em;"><?= fmt_bytes((int)filesize($gz)) ?></td>
                  <td style="font-size:0.82em;color:#888;"><?= date('j M Y', (int)filemtime($gz)) ?></td>
                </tr>
                <?php endforeach; ?>
              </table>
            </div>
            <p style="font-size:0.78em;color:#aaa;margin-top:6px;">
              Files are in <code>storage/imports/</code> on the server. Download via SFTP to inspect a full raw CSV.
            </p>
          </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>


  <!-- ═══════════════════════════════════════════════════════════
       SECTION 10 — Import run history
  ════════════════════════════════════════════════════════════ -->
  <div class="section">
    <div class="section__title">Import run history (last 20)</div>
    <?php if (empty($imports)): ?>
      <p class="muted">No import runs recorded yet.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <tr>
          <th>#</th>
          <th>Started</th>
          <th>Duration</th>
          <th>Status</th>
          <th class="num">Parsed</th>
          <th class="num">Inserted</th>
          <th class="num">Updated</th>
          <th class="num">Skipped</th>
          <th class="num">Active after</th>
          <th>File size</th>
          <th>MD5</th>
          <th>Source</th>
          <th>Column changes</th>
        </tr>
        <?php foreach ($imports as $r): ?>
        <?php
          $dur = '';
          if ($r['finished_at'] && $r['started_at']) {
              $s = strtotime((string)$r['finished_at']) - strtotime((string)$r['started_at']);
              $dur = $s >= 60 ? floor($s/60).'m '.($s%60).'s' : $s.'s';
          }
          $has_meta  = array_key_exists('file_size_bytes', $r);
          $dash      = '<span class="muted">—</span>';
          $row_notes = ($r['notes'] ?? null) ? json_decode($r['notes'], true) : null;
        ?>
        <tr>
          <td class="muted"><?= (int)$r['id'] ?></td>
          <td><?= date('j M Y H:i', strtotime((string)$r['started_at'])) ?></td>
          <td class="muted"><?= h($dur ?: '—') ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="num"><?= number_format((int)$r['rows_parsed']) ?></td>
          <td class="num"><?= number_format((int)$r['rows_inserted']) ?></td>
          <td class="num"><?= number_format((int)$r['rows_updated']) ?></td>
          <td class="num"><?= number_format((int)$r['rows_skipped']) ?></td>
          <td class="num"><?= ($has_meta && $r['services_active'] !== null) ? number_format((int)$r['services_active']) : $dash ?></td>
          <td><?= ($has_meta && $r['file_size_bytes']) ? fmt_bytes((int)$r['file_size_bytes']) : $dash ?></td>
          <td class="mono" style="font-size:0.78em;color:#777;"><?= ($has_meta && $r['file_hash_md5']) ? substr((string)$r['file_hash_md5'], 0, 8).'…' : $dash ?></td>
          <td class="mono muted" style="font-size:0.75em;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
              title="<?= h((string)($r['source_url'] ?? '')) ?>">
            <?= ($has_meta && $r['source_url']) ? h((string)$r['source_url']) : $dash ?>
          </td>
          <td style="font-size:0.78em;min-width:160px;">
            <?php if (!$row_notes): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <?php if ($row_notes['missing_mapped'] ?? []): ?>
                <span style="color:#c62828;">⚠ Missing: <?= h(implode(', ', $row_notes['missing_mapped'])) ?></span><br>
              <?php endif; ?>
              <?php if ($row_notes['added'] ?? []): ?>
                <span style="color:#2e7d32;">+<?= h(implode(', ', $row_notes['added'])) ?></span><br>
              <?php endif; ?>
              <?php if ($row_notes['removed'] ?? []): ?>
                <span style="color:#e65100;">−<?= h(implode(', ', $row_notes['removed'])) ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <p style="margin-top:8px;color:#aaa;font-size:0.78em;">File size, MD5, and Active after columns populate from migration 004 onwards.</p>
    <?php endif; ?>
  </div>

  <div class="page-footer">
    Internal use only. Restrict access with HTTP basic auth or IP rules in .htaccess before deploying to production.
  </div>

</div>
</body>
</html>
