<?php
// =============================================================
// admin/imports.php — Care Inspectorate CSV import manager
// =============================================================
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';

$pdo         = db();
$pending_dir = ROOT . '/storage/imports/pending';
if (!is_dir($pending_dir)) mkdir($pending_dir, 0755, true);

$error   = null;
$success = null;

// ── Handle: download CSV from URL ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download') {
    $url = trim($_POST['url'] ?? '');
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        $error = 'Please enter a valid URL.';
    } elseif (!str_starts_with($url, 'https://')) {
        $error = 'Only HTTPS URLs are accepted.';
    } else {
        $filename = basename(parse_url($url, PHP_URL_PATH));
        if (!preg_match('/\.csv$/i', $filename)) {
            $filename = 'download_' . date('Y-m-d') . '.csv';
        }
        $dest = $pending_dir . '/' . $filename;
        if (is_file($dest)) {
            $error = "A file named <strong>" . h($filename) . "</strong> already exists in the pending folder.";
        } else {
            $ua  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
            $ctx = stream_context_create(['http' => [
                'timeout'         => 120,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'header'          => implode("\r\n", [
                    'User-Agent: ' . $ua,
                    'Accept: text/csv,text/plain,*/*;q=0.8',
                    'Accept-Language: en-GB,en;q=0.9',
                    'Referer: https://www.careinspectorate.com/',
                ]),
            ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

            $data = @file_get_contents($url, false, $ctx);

            if ($data === false || strlen($data) < 500) {
                // Try curl
                if (extension_loaded('curl')) {
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_TIMEOUT        => 120,
                        CURLOPT_USERAGENT      => $ua,
                        CURLOPT_HTTPHEADER     => ['Accept: text/csv,text/plain,*/*', 'Referer: https://www.careinspectorate.com/'],
                    ]);
                    $data = curl_exec($ch);
                    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($data === false || $code < 200 || $code >= 300 || strlen($data) < 500) {
                        $data = false;
                    }
                } else {
                    $data = false;
                }
            }

            if ($data === false) {
                $error = 'Download failed. The Care Inspectorate may be blocking automated requests — try downloading the file manually in your browser and placing it in <code>storage/imports/pending/</code>.';
            } else {
                file_put_contents($dest, $data);
                $success = 'Downloaded <strong>' . h($filename) . '</strong> (' . number_format(strlen($data)) . ' bytes). Ready to import below.';
            }
        }
    }
}

// ── Build pending file list with import status ────────────────
$pending_files = glob($pending_dir . '/*.csv') ?: [];
usort($pending_files, fn($a, $b) => filemtime($b) - filemtime($a)); // newest first

// Load all completed import runs to check status
$done_imports = $pdo->query("
    SELECT source_url, file_hash_md5, finished_at, status, rows_parsed, rows_inserted, rows_updated, id
    FROM import_log
    WHERE status IN ('complete','complete_with_warnings')
    ORDER BY finished_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Index by filename and MD5 so we can look up status quickly
$done_by_name = [];
$done_by_hash = [];
foreach ($done_imports as $imp) {
    if (str_starts_with((string)($imp['source_url'] ?? ''), 'local:')) {
        $name = substr($imp['source_url'], 6);
        $done_by_name[$name] ??= $imp; // keep first (most recent)
    }
    if ($imp['file_hash_md5']) {
        $done_by_hash[$imp['file_hash_md5']] ??= $imp;
    }
}

// Enrich each file with size, md5, and import status
$files = [];
foreach ($pending_files as $path) {
    $name = basename($path);
    $size = filesize($path);
    $md5  = md5_file($path);
    $mtime = filemtime($path);

    $done = $done_by_hash[$md5] ?? $done_by_name[$name] ?? null;

    $files[] = [
        'path'   => $path,
        'name'   => $name,
        'size'   => $size,
        'mtime'  => $mtime,
        'md5'    => $md5,
        'done'   => $done,
    ];
}

function fmt_bytes(int $b): string {
    if ($b >= 1048576) return number_format($b / 1048576, 2) . ' MB';
    if ($b >= 1024)    return number_format($b / 1024, 1) . ' KB';
    return $b . ' B';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Import Manager · CareScotland Admin</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; font-size: 14px; background: #f0f2f5; color: #1a1a1a; }
  .page { max-width: 960px; margin: 0 auto; padding: 28px 16px 60px; }
  .back { display: inline-flex; align-items: center; gap: 4px; color: #1565c0; text-decoration: none; font-size: 0.85em; margin-bottom: 16px; }
  .back:hover { text-decoration: underline; }
  .page-header { margin-bottom: 28px; }
  .page-header h1 { font-size: 1.5rem; font-weight: 700; }
  .page-header p  { color: #666; margin-top: 4px; font-size: 0.9em; }
  .section { margin-top: 32px; }
  .section__title { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #888; margin-bottom: 12px; }
  .card { background: #fff; border: 1px solid #e4e4e4; border-radius: 10px; padding: 20px; }
  .alert { padding: 12px 16px; margin-bottom: 20px; font-size: 0.9em; border-left: 4px solid; }
  .alert--ok   { background: #f1f8e9; border-color: #7cb342; }
  .alert--warn { background: #fff8e1; border-color: #f9a825; }
  .alert--bad  { background: #fce4ec; border-color: #c62828; }
  .form-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .form-row input[type=url] {
    flex: 1; min-width: 260px; padding: 9px 12px; border: 1px solid #ccc;
    border-radius: 6px; font-size: 0.9em; font-family: monospace;
  }
  .btn { display: inline-block; padding: 9px 18px; border-radius: 6px; font-size: 0.88em; font-weight: 600; border: none; cursor: pointer; }
  .btn-primary { background: #1565c0; color: #fff; }
  .btn-primary:hover { background: #0d47a1; }
  .btn-run { background: #2e7d32; color: #fff; font-size: 0.82em; padding: 6px 14px; }
  .btn-run:hover { background: #1b5e20; }
  .btn-run:disabled { background: #aaa; cursor: not-allowed; }
  .table-wrap { background: #fff; border: 1px solid #e4e4e4; border-radius: 10px; overflow: hidden; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #f7f7f7; text-align: left; padding: 9px 14px; font-weight: 600; font-size: 0.8em; color: #555; border-bottom: 1px solid #e4e4e4; }
  td { padding: 10px 14px; border-top: 1px solid #f0f0f0; vertical-align: middle; }
  tr:hover td { background: #fafafa; }
  .badge { display: inline-block; color: #fff; padding: 2px 9px; border-radius: 4px; font-size: 0.78em; font-weight: 600; }
  .badge--done    { background: #2e7d32; }
  .badge--warn    { background: #f57c00; }
  .badge--pending { background: #888; }
  .mono { font-family: monospace; font-size: 0.82em; }
  .muted { color: #aaa; }
  .help-text { color: #888; font-size: 0.82em; margin-top: 10px; line-height: 1.5; }
  .empty-state { text-align: center; padding: 40px 20px; color: #aaa; }
  .empty-state p { margin-top: 8px; font-size: 0.88em; }
</style>
</head>
<body>
<div class="page">

  <a href="/admin/data-status.php" class="back">← Data metrics</a>

  <div class="page-header">
    <h1>Import Manager</h1>
    <p>Download Care Inspectorate CSV files and import them into the database.</p>
  </div>

  <?php if ($error): ?>
    <div class="alert alert--bad"><?= $error ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert--ok"><?= $success ?></div>
  <?php endif; ?>

  <!-- ── Datastore coverage (cron/check_datastore_coverage.php) ─ -->
  <?php
    $coverage_file = ROOT . '/storage/imports/coverage_check.json';
    $coverage      = is_file($coverage_file) ? json_decode(file_get_contents($coverage_file), true) : null;
  ?>
  <div class="section">
    <div class="section__title">Datastore coverage (last year)</div>
    <div class="card">
      <?php if (!$coverage): ?>
        <p class="help-text">
          The automated coverage check hasn't run yet. It checks the
          <a href="https://www.careinspectorate.scot/resources-data/data-and-statistics/datastore" target="_blank" rel="noopener">Care Inspectorate Datastore</a>
          page against what's already imported and downloads anything missing from the last ~13 months.
          Run it manually with <code>php cron/check_datastore_coverage.php</code>, or schedule it weekly:<br>
          <code>0 7 * * 1 php /full/path/to/cron/check_datastore_coverage.php &gt;&gt; storage/logs/datastore_check.log 2&gt;&1</code>
        </p>
      <?php else: ?>
        <?php
          $downloaded = $coverage['downloaded'] ?? [];
          $failed     = $coverage['failed'] ?? [];
          $awaiting   = $coverage['awaiting_import'] ?? [];
          $siteMonths = $coverage['site_months'] ?? [];
        ?>
        <p style="margin:0 0 0.75rem;color:#666;font-size:0.88em;">
          Last checked <?= h((string)($coverage['checked_at'] ?? '—')) ?> ·
          <?= count($siteMonths) ?> month(s) found on the CI site
        </p>
        <?php if (!$downloaded && !$failed && !$awaiting): ?>
          <div class="alert alert--ok" style="margin:0;">✓ Nothing missing — every month in the last year is accounted for.</div>
        <?php else: ?>
          <?php if ($downloaded): ?>
            <div class="alert alert--ok" style="margin:0 0 0.5rem;">
              Downloaded <?= count($downloaded) ?> missing file(s): <?= h(implode(', ', $downloaded)) ?> — ready to import below.
            </div>
          <?php endif; ?>
          <?php if ($awaiting): ?>
            <div class="alert" style="margin:0 0 0.5rem;background:#fff8e1;color:#5d4000;">
              <?= count($awaiting) ?> month(s) already downloaded but not yet imported: <?= h(implode(', ', $awaiting)) ?>
            </div>
          <?php endif; ?>
          <?php if ($failed): ?>
            <div class="alert alert--bad" style="margin:0;">
              Could not fetch <?= count($failed) ?> file(s):
              <?php foreach ($failed as $f): ?>
                <br>&middot; <?= h($f['date'] ?? '?') ?> — <?= h($f['error'] ?? 'unknown error') ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Download a new file ─────────────────────────────────── -->
  <div class="section">
    <div class="section__title">Download a new file from the Care Inspectorate</div>
    <div class="card">
      <form method="post" action="">
        <input type="hidden" name="action" value="download">
        <div class="form-row">
          <input type="url" name="url" required
                 placeholder="https://www.careinspectorate.com/images/Datastore/YYMMDD_DatastoreExternal.csv"
                 value="<?= h((string)($_POST['url'] ?? '')) ?>">
          <button type="submit" class="btn btn-primary">Download file</button>
        </div>
      </form>
      <p class="help-text">
        Find the current URL at <strong>careinspectorate.com → Publications & statistics → Datastore</strong>.
        The filename changes each month (e.g. <code>260331_DatastoreExternal.csv</code> for March 2026).<br>
        If the download is blocked, save the file manually in your browser and place it in
        <code>storage/imports/pending/</code> via SFTP.
      </p>
    </div>
  </div>


  <!-- ── Pending files ──────────────────────────────────────── -->
  <div class="section">
    <div class="section__title">
      Files in pending folder
      <span style="font-weight:400;font-size:0.9em;color:#aaa;">(storage/imports/pending/)</span>
    </div>

    <?php if (empty($files)): ?>
      <div class="table-wrap">
        <div class="empty-state">
          <strong>No CSV files found</strong>
          <p>Download a file above, or place a <code>.csv</code> file in <code>storage/imports/pending/</code> via SFTP.</p>
        </div>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <tr>
          <th>File</th>
          <th>Added</th>
          <th>Size</th>
          <th>Status</th>
          <th>Last imported</th>
          <th>Rows parsed</th>
          <th></th>
        </tr>
        <?php foreach ($files as $f): ?>
        <?php
          $done  = $f['done'];
          $imported_at = $done ? date('j M Y H:i', strtotime((string)$done['finished_at'])) : null;
          $badge = $done
            ? ($done['status'] === 'complete_with_warnings'
                ? '<span class="badge badge--warn">imported (warnings)</span>'
                : '<span class="badge badge--done">imported</span>')
            : '<span class="badge badge--pending">not imported</span>';
        ?>
        <tr>
          <td class="mono"><?= h($f['name']) ?></td>
          <td style="color:#666;font-size:0.85em;white-space:nowrap;"><?= date('j M Y', (int)$f['mtime']) ?></td>
          <td style="color:#666;font-size:0.85em;"><?= fmt_bytes((int)$f['size']) ?></td>
          <td><?= $badge ?></td>
          <td style="color:#666;font-size:0.85em;white-space:nowrap;"><?= $imported_at ?? '<span class="muted">—</span>' ?></td>
          <td style="color:#666;font-size:0.85em;"><?= $done ? number_format((int)$done['rows_parsed']) : '<span class="muted">—</span>' ?></td>
          <td>
            <form method="post" action="run-import.php"
                  onsubmit="return confirm('Import <?= h($f['name']) ?>? This may take a minute or two.')">
              <input type="hidden" name="file" value="<?= h($f['name']) ?>">
              <button type="submit" class="btn btn-run"
                      <?= $done ? '' : '' ?>>
                <?= $done ? 'Re-import' : 'Import →' ?>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <p class="help-text">
      MD5 is used to detect whether a file has already been imported — re-importing the same file is safe
      (it will update existing records but not create duplicates).
    </p>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
