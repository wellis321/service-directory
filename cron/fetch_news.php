<?php
declare(strict_types=1);
/**
 * Fetch Google News RSS articles for each care service provider.
 *
 * Runs daily (recommended: 07:30, after the monthly import at 06:00).
 * Each run processes up to BATCH_SIZE providers, prioritising those never
 * fetched or fetched longest ago. With 2,700+ providers and a daily batch
 * of 150, the full list cycles every ~18 days — fine for news currency.
 *
 * Usage:
 *   php cron/fetch_news.php              # daily batch (up to 150 providers)
 *   php cron/fetch_news.php SP2004006282 # one provider (testing)
 *
 * Hostinger cron:
 *   30 7 * * * php /full/path/to/cron/fetch_news.php
 */
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';

const BATCH_SIZE   = 150;  // providers per daily run
const SLEEP_SECS   = 4;    // gap between Google News requests

$pdo = db();

// Ensure table exists before running
try {
    $pdo->query('SELECT 1 FROM provider_news LIMIT 1');
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: provider_news table missing. Run sql/migrations/006_provider_news.sql first.\n");
    exit(1);
}

// Single-provider test mode
$filterSp = isset($argv[1]) && preg_match('/^[A-Za-z0-9]+$/', $argv[1]) ? $argv[1] : null;

if ($filterSp !== null) {
    // Test mode — fetch exactly this one provider
    $sql = "
        SELECT s.sp_number, MAX(s.provider_name) AS provider_name
        FROM services s
        WHERE s.service_status = 'Active'
          AND s.provider_name IS NOT NULL AND TRIM(s.provider_name) != ''
          AND s.sp_number = " . $pdo->quote($filterSp) . "
        GROUP BY s.sp_number
    ";
} else {
    // Daily batch: providers ordered by least recently fetched (NULLs first)
    $sql = "
        SELECT s.sp_number, MAX(s.provider_name) AS provider_name,
               MAX(pn.fetched_at) AS last_fetched
        FROM services s
        LEFT JOIN provider_news pn ON pn.sp_number = s.sp_number
        WHERE s.service_status = 'Active'
          AND s.provider_name IS NOT NULL AND TRIM(s.provider_name) != ''
        GROUP BY s.sp_number
        ORDER BY last_fetched ASC
        LIMIT " . BATCH_SIZE . "
    ";
}

$providers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$total = count($providers);
if ($total === 0) {
    echo "No providers found.\n";
    exit(0);
}

$mode = $filterSp ? 'single-provider test' : "batch of {$total} (up to " . BATCH_SIZE . " per run)";
echo "Fetching news — {$mode}...\n";

$insertStmt = $pdo->prepare("
    INSERT IGNORE INTO provider_news
        (sp_number, title, url, url_hash, source_name, snippet, published_at, fetched_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
");

$ctx = stream_context_create([
    'http' => [
        'timeout'         => 15,
        'follow_location' => 1,
        'max_redirects'   => 5,
        'header'          => [
            'Accept: application/rss+xml, application/xml, text/xml, */*',
            'Accept-Language: en-GB,en;q=0.9',
        ],
        'user_agent' => 'Mozilla/5.0 (compatible; CareScotlandBot/1.0)',
    ],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
]);

$totalInserted = 0;
$totalSkipped  = 0;
$totalErrors   = 0;

foreach ($providers as $i => $prov) {
    $sp   = (string) $prov['sp_number'];
    $name = (string) $prov['provider_name'];

    // Quoted-phrase search; append Scotland if not already in the name
    $searchName = str_replace('"', '', $name);
    $query      = '"' . $searchName . '"';
    if (stripos($name, 'scotland') === false) {
        $query .= ' Scotland';
    }

    $rssUrl = 'https://news.google.com/rss/search?q=' . rawurlencode($query)
            . '&hl=en-GB&gl=GB&ceid=GB:en';

    echo sprintf('  [%d/%d] %s... ', $i + 1, $total, $name);

    $xml = @file_get_contents($rssUrl, false, $ctx);
    if ($xml === false || $xml === '') {
        echo "FAILED (fetch error)\n";
        $totalErrors++;
        sleep(SLEEP_SECS + 2);  // back off a little longer on errors
        continue;
    }

    libxml_use_internal_errors(true);
    $feed = @simplexml_load_string($xml);
    if ($feed === false) {
        echo "FAILED (XML parse error)\n";
        $totalErrors++;
        sleep(SLEEP_SECS + 2);
        continue;
    }

    $items   = $feed->channel->item ?? [];
    $newHere = 0;

    foreach ($items as $item) {
        $title   = trim((string) ($item->title   ?? ''));
        $link    = trim((string) ($item->link    ?? ''));
        $pubDate = trim((string) ($item->pubDate ?? ''));
        $desc    = trim(strip_tags((string) ($item->description ?? '')));

        // Google News titles/descriptions sometimes contain literal HTML
        // entities (e.g. "&nbsp;") as actual text rather than real whitespace.
        // Decode them now so h() doesn't re-escape the "&" later and display
        // raw "&nbsp;" on the page, then collapse the resulting whitespace.
        $title = trim(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        $desc  = trim(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));

        $sourceName = '';
        if (isset($item->source)) {
            $sourceName = trim((string) $item->source);
        }

        // Google News appends " - Source" to every title — strip it
        if ($sourceName !== '' && str_ends_with($title, ' - ' . $sourceName)) {
            $title = substr($title, 0, -strlen(' - ' . $sourceName));
        }

        if ($title === '' || $link === '') {
            continue;
        }

        $urlHash = md5($link);
        $pubDt   = ($pubDate !== '') ? date('Y-m-d H:i:s', (int) strtotime($pubDate)) : null;
        $snippet = $desc !== '' ? mb_substr($desc, 0, 500) : null;

        try {
            $insertStmt->execute([$sp, $title, $link, $urlHash, $sourceName ?: null, $snippet, $pubDt]);
            if ($insertStmt->rowCount() > 0) {
                $newHere++;
                $totalInserted++;
            } else {
                $totalSkipped++;
            }
        } catch (PDOException) {
            $totalSkipped++;
        }
    }

    echo "OK ({$newHere} new)\n";

    if ($i < $total - 1) {
        sleep(SLEEP_SECS);
    }
}

echo "\nDone. Inserted: {$totalInserted}, Already stored: {$totalSkipped}, Errors: {$totalErrors}\n";
