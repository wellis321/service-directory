<?php
declare(strict_types=1);
/**
 * Check the Care Inspectorate Datastore listing page for monthly CSV
 * snapshots we don't have yet, and download any gaps into
 * storage/imports/pending/ for review in the admin Import Manager.
 *
 * This does NOT run the import itself — it only makes sure the file is
 * available locally so nothing from the past year gets silently missed.
 * Files are cumulative (see cron/import.php's per-service dedup), so
 * downloading and importing them in any order is safe.
 *
 * Usage:
 *   php cron/check_datastore_coverage.php
 *
 * Recommended cron (weekly is plenty — CI publishes ~monthly):
 *   0 7 * * 1 php /full/path/to/cron/check_datastore_coverage.php >> storage/logs/datastore_check.log 2>&1
 */
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';

$pdo = db();

if (!function_exists('log_msg')) {
    function log_msg(string $msg): void {
        echo date('[Y-m-d H:i:s]') . ' ' . $msg . PHP_EOL;
    }
}

const DATASTORE_URL   = 'https://www.careinspectorate.scot/resources-data/data-and-statistics/datastore';
const LOOKBACK_DAYS   = 400; // a little over a year, so a slow month never falls through the crack
const MONTH_NAMES     = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];

/** Care Inspectorate returns 403 to bare PHP user-agents; send browser-like headers. */
function ci_http_get(string $url): string {
    $ua  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
    $hdr = [
        'User-Agent: ' . $ua,
        'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
        'Accept-Language: en-GB,en;q=0.9',
    ];
    $ctx = stream_context_create([
        'http' => ['timeout' => 60, 'follow_location' => 1, 'max_redirects' => 5, 'header' => implode("\r\n", $hdr)],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data !== false && strlen($data) > 200) {
        return $data;
    }

    if (extension_loaded('curl')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8', 'Accept-Language: en-GB,en;q=0.9'],
            CURLOPT_USERAGENT      => $ua,
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        if ($data !== false && $code >= 200 && $code < 300 && strlen($data) > 200) {
            return $data;
        }
        throw new RuntimeException("Fetch failed for {$url} (HTTP {$code})" . ($cerr !== '' ? ": {$cerr}" : ''));
    }

    throw new RuntimeException("Fetch failed for {$url} (blocked or empty, and no curl extension available).");
}

/**
 * CI's monthly library page slugs are inconsistently formatted:
 *   datastore-31-03-2025   (DD-MM-YYYY)
 *   datastore-30-april-2026 (DD-Month-YYYY)
 *   datastore-30nov2025    (DDMonYYYY, no separators)
 * Returns 'YYYY-MM-DD' or null if unparseable.
 */
function ci_parse_slug_date(string $slug): ?string {
    if (preg_match('/datastore-(\d{1,2})-(\d{1,2})-(\d{4})$/i', $slug, $m)) {
        [, $d, $mo, $y] = $m;
        return checkdate((int)$mo, (int)$d, (int)$y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
    if (preg_match('/datastore-(\d{1,2})-([a-z]+)-(\d{4})$/i', $slug, $m)) {
        [, $d, $moName, $y] = $m;
        $mo = MONTH_NAMES[strtolower(substr($moName, 0, 3))] ?? null;
        return ($mo && checkdate($mo, (int)$d, (int)$y)) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
    if (preg_match('/datastore-(\d{1,2})([a-z]{3})(\d{4})$/i', $slug, $m)) {
        [, $d, $moName, $y] = $m;
        $mo = MONTH_NAMES[strtolower($moName)] ?? null;
        return ($mo && checkdate($mo, (int)$d, (int)$y)) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
    return null;
}

/** Filename convention already used across this project: YYMMDDdatastoreexternal.csv */
function ci_expected_filename(string $isoDate): string {
    $dt = new DateTime($isoDate);
    return $dt->format('ymd') . 'datastoreexternal.csv';
}

// ── 1. Fetch the datastore listing and find monthly page links ────
log_msg("Checking Datastore coverage: " . DATASTORE_URL);
$html = ci_http_get(DATASTORE_URL);

preg_match_all('#/resources-data/publications-and-statistics/library/(datastore-[a-z0-9\-]+)#i', $html, $matches);
$slugs = array_unique($matches[1]);

$cutoff = date('Y-m-d', time() - LOOKBACK_DAYS * 86400);
$months = []; // iso_date => slug
foreach ($slugs as $slug) {
    $date = ci_parse_slug_date($slug);
    if ($date === null || $date < $cutoff) continue;
    $months[$date] = $slug;
}
ksort($months);
log_msg("Found " . count($months) . " snapshot(s) on the site within the last " . LOOKBACK_DAYS . " days.");

// ── 2. Work out what we already have locally ──────────────────────
$pending_dir = ROOT . '/storage/imports/pending';
if (!is_dir($pending_dir)) mkdir($pending_dir, 0755, true);

$localDates = []; // 'YYYY-MM' => true, from files sitting in pending/
foreach (glob($pending_dir . '/*.csv') ?: [] as $path) {
    $fname = basename($path);
    if (preg_match('/^(\d{2})(\d{2})(\d{2})/', $fname, $m)) {
        [, $yy, $mm] = $m;
        $localDates['20' . $yy . '-' . $mm] = true;
    }
}

$importedDates = []; // 'YYYY-MM' => true, from successful import_log entries
$stmt = $pdo->query("SELECT source_url FROM import_log WHERE status IN ('complete','complete_with_warnings') AND source_url LIKE 'local:%'");
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sourceUrl) {
    $fname = substr((string)$sourceUrl, strlen('local:'));
    if (preg_match('/^(\d{2})(\d{2})(\d{2})/', $fname, $m)) {
        [, $yy, $mm] = $m;
        $importedDates['20' . $yy . '-' . $mm] = true;
    }
}

// ── 3. Diff and download any real gaps ─────────────────────────────
$summary = ['checked_at' => date('Y-m-d H:i:s'), 'site_months' => [], 'downloaded' => [], 'awaiting_import' => [], 'failed' => []];

foreach ($months as $isoDate => $slug) {
    $ym = substr($isoDate, 0, 7);
    $summary['site_months'][] = $isoDate;

    if (isset($importedDates[$ym])) {
        continue; // already imported — fully covered
    }
    if (isset($localDates[$ym])) {
        $summary['awaiting_import'][] = $isoDate;
        log_msg("  {$isoDate}: already downloaded, awaiting import — skipping.");
        continue;
    }

    // Genuine gap: not imported, not even downloaded yet.
    log_msg("MISSING: {$isoDate} (site page: {$slug}) — fetching...");
    try {
        $pageHtml = ci_http_get('https://www.careinspectorate.scot/resources-data/publications-and-statistics/library/' . $slug);
        if (!preg_match('#href="(https://[^"]+\.csv)"#i', $pageHtml, $m)) {
            throw new RuntimeException("No CSV link found on the library page.");
        }
        $csvUrl = html_entity_decode($m[1], ENT_QUOTES);

        $ch = curl_init($csvUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        ]);
        $csvData = curl_exec($ch);
        $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($csvData === false || $code < 200 || $code >= 300 || strlen($csvData) < 1000) {
            throw new RuntimeException("CSV download failed (HTTP {$code}).");
        }

        $destName = ci_expected_filename($isoDate);
        $destPath = $pending_dir . '/' . $destName;
        file_put_contents($destPath, $csvData);
        log_msg("  Downloaded → storage/imports/pending/{$destName} (" . round(strlen($csvData) / 1024) . " KB). Ready to import from the admin panel.");
        $summary['downloaded'][] = $isoDate;
    } catch (Throwable $e) {
        log_msg("  ERROR: " . $e->getMessage());
        $summary['failed'][] = ['date' => $isoDate, 'error' => $e->getMessage()];
    }
}

file_put_contents(ROOT . '/storage/imports/coverage_check.json', json_encode($summary, JSON_PRETTY_PRINT));

$gap_count = count($summary['downloaded']) + count($summary['failed']);
if ($gap_count === 0) {
    log_msg("Coverage check complete — nothing missing.");
} else {
    log_msg("Coverage check complete — " . count($summary['downloaded']) . " file(s) downloaded, "
        . count($summary['failed']) . " failed, " . count($summary['awaiting_import']) . " already pending.");
}

exit(count($summary['failed']) > 0 ? 1 : 0);
