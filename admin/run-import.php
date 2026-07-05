<?php
// =============================================================
// admin/run-import.php — Executes a pending CSV import
// Streams log output line-by-line to the browser.
// =============================================================
define('ROOT', dirname(__DIR__));

$pending_dir = ROOT . '/storage/imports/pending';
$filename    = basename((string)($_POST['file'] ?? ''));

// Validate: must be a .csv inside the pending folder
if (!$filename || !preg_match('/\.csv$/i', $filename)) {
    http_response_code(400);
    exit('Invalid file.');
}
$filepath = $pending_dir . '/' . $filename;
if (!is_file($filepath)) {
    http_response_code(404);
    exit('File not found in pending folder.');
}

// Tell the import script which file to use
define('CI_IMPORT_FILE', $filepath);

// Allow plenty of time for large imports
set_time_limit(600);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', 'Off');

// Override log_msg to emit styled HTML lines and flush immediately
function log_msg(string $msg): void {
    $ts  = date('[Y-m-d H:i:s]');
    $cls = str_contains($msg, 'ERROR') || str_contains($msg, 'CRITICAL')
        ? 'err'
        : (str_contains($msg, 'WARNING') || str_contains($msg, 'warning') ? 'warn'
        : (str_contains($msg, 'passed') || str_contains($msg, 'Done') || str_contains($msg, 'complete') ? 'ok'
        : 'info'));
    echo '<div class="line ' . $cls . '"><span class="ts">' . $ts . '</span> ' . htmlspecialchars($msg, ENT_QUOTES) . '</div>';
    if (ob_get_level()) ob_flush();
    flush();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Importing <?= htmlspecialchars($filename) ?> · CareScotland Admin</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; font-size: 14px; background: #0f1117; color: #d4d4d4; padding: 28px 20px; }
  h1 { font-size: 1rem; color: #fff; margin-bottom: 4px; }
  .meta { color: #666; font-size: 0.82em; margin-bottom: 20px; }
  .log { font-family: monospace; font-size: 0.85em; line-height: 1.7; }
  .line { padding: 1px 0; }
  .ts   { color: #555; margin-right: 8px; }
  .err  { color: #f28b82; }
  .warn { color: #fdd663; }
  .ok   { color: #81c995; }
  .info { color: #d4d4d4; }
  .done-bar { margin-top: 24px; padding: 14px 16px; border-radius: 8px; font-size: 0.9em; font-weight: 600; }
  .done-bar--ok   { background: #1b5e20; color: #c8e6c9; }
  .done-bar--warn { background: #e65100; color: #ffe0b2; }
  .done-bar--err  { background: #b71c1c; color: #ffcdd2; }
  .back { display: inline-block; margin-top: 16px; color: #82b1ff; text-decoration: none; font-size: 0.85em; }
  .back:hover { text-decoration: underline; }
  .spinner { display: inline-block; width: 10px; height: 10px; border: 2px solid #555; border-top-color: #aaa; border-radius: 50%; animation: spin 0.7s linear infinite; margin-right: 6px; vertical-align: middle; }
  @keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>
<h1>Importing: <?= htmlspecialchars($filename) ?></h1>
<p class="meta">Do not close this tab — the import is running.</p>
<div class="log">
<?php
// Flush the opening HTML immediately so the browser renders it
if (ob_get_level()) ob_flush();
flush();

// Run the import — log_msg() above will stream each line to the browser
try {
    require ROOT . '/cron/import.php';
    $import_status = $status ?? 'complete';
} catch (Throwable $e) {
    $import_status = 'failed';
    log_msg('FATAL: ' . $e->getMessage());
}
?>
</div>
<?php
$bar_class = match(true) {
    str_starts_with($import_status, 'complete') && $import_status !== 'complete_with_warnings' => 'done-bar--ok',
    $import_status === 'complete_with_warnings' => 'done-bar--warn',
    default => 'done-bar--err',
};
$bar_msg = match(true) {
    str_starts_with($import_status, 'complete') && $import_status !== 'complete_with_warnings' => '✓ Import complete',
    $import_status === 'complete_with_warnings' => '⚠ Import complete with warnings — check column changes above',
    default => '✗ Import failed — see errors above',
};
?>
<div class="done-bar <?= $bar_class ?>"><?= $bar_msg ?></div>
<a href="/admin/imports.php" class="back">← Back to Import Manager</a>
<br><a href="/admin/data-status.php" class="back">← Data Metrics</a>
</body>
</html>
