<?php
declare(strict_types=1);
/**
 * Overview of all services for one Care Inspectorate service provider (SP number).
 * URL: /provider/{sp_number}/{slug}?sort=&status=&council=&type=&q=
 */
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';

$sp = trim((string) ($_GET['sp'] ?? ''));
if ($sp === '' || !preg_match('/^[A-Za-z0-9]+$/', $sp)) {
    http_response_code(404);
    exit('Provider not found.');
}

$pdo = db();

// —— Filter options (only values that exist for this SP) ——
$stmt = $pdo->prepare(
    'SELECT DISTINCT council_area FROM services WHERE sp_number = ? AND council_area IS NOT NULL AND TRIM(council_area) != \'\' ORDER BY council_area'
);
$stmt->execute([$sp]);
$councilOptions = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$stmt = $pdo->prepare(
    'SELECT DISTINCT care_service FROM services WHERE sp_number = ? AND care_service IS NOT NULL AND TRIM(care_service) != \'\' ORDER BY care_service'
);
$stmt->execute([$sp]);
$typeOptions = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

// —— Meta counts (whole provider, ignores filters) ——
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS n_all, SUM(service_status = \'Active\') AS n_active FROM services WHERE sp_number = ?'
);
$stmt->execute([$sp]);
$meta = $stmt->fetch() ?: ['n_all' => 0, 'n_active' => 0];
$nAll = (int) ($meta['n_all'] ?? 0);
$nActiveAll = (int) ($meta['n_active'] ?? 0);
$cancelledAll = max(0, $nAll - $nActiveAll);

$stmt = $pdo->prepare(
    'SELECT provider_name FROM services WHERE sp_number = ? AND service_status = \'Active\' AND provider_name IS NOT NULL AND TRIM(provider_name) != \'\' LIMIT 1'
);
$stmt->execute([$sp]);
$provider_name = (string) ($stmt->fetchColumn() ?: '');
if ($provider_name === '') {
    $stmt = $pdo->prepare('SELECT provider_name FROM services WHERE sp_number = ? LIMIT 1');
    $stmt->execute([$sp]);
    $provider_name = (string) ($stmt->fetchColumn() ?: 'Service provider ' . $sp);
}

// —— GET filters (validated against whitelists / escaped LIKE) ——
$sort = trim((string) ($_GET['sort'] ?? 'availability'));
$status = trim((string) ($_GET['status'] ?? 'active'));
if (!in_array($status, ['active', 'all'], true)) {
    $status = 'active';
}

$council = trim((string) ($_GET['council'] ?? ''));
if ($council !== '' && !in_array($council, $councilOptions, true)) {
    $council = '';
}

$type = trim((string) ($_GET['type'] ?? ''));
if ($type !== '' && !in_array($type, $typeOptions, true)) {
    $type = '';
}

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) > 120) {
    $q = mb_substr($q, 0, 120);
}

$sortKeys = [
    'availability',
    'name_asc',
    'name_desc',
    'council_asc',
    'council_desc',
    'type',
    'type_desc',
    'status_asc',
    'status_desc',
    'grade_high',
    'grade_low',
];
if (!in_array($sort, $sortKeys, true)) {
    $sort = 'availability';
}

$where = ['s.sp_number = ?'];
$params = [$sp];

if ($status === 'active') {
    $where[] = "s.service_status = 'Active'";
}
if ($council !== '') {
    $where[] = 's.council_area = ?';
    $params[] = $council;
}
if ($type !== '') {
    $where[] = 's.care_service = ?';
    $params[] = $type;
}
if ($q !== '') {
    $where[] = 's.service_name LIKE ?';
    $params[] = '%' . addcslashes($q, '%_\\') . '%';
}

$whereSql = implode(' AND ', $where);

$orderSql = match ($sort) {
    'name_asc' => 's.service_name ASC',
    'name_desc' => 's.service_name DESC',
    'council_asc' => '(s.council_area IS NULL) ASC, s.council_area ASC, s.service_name ASC',
    'council_desc' => '(s.council_area IS NULL) ASC, s.council_area DESC, s.service_name ASC',
    'type' => 's.care_service ASC, s.service_name ASC',
    'type_desc' => 's.care_service DESC, s.service_name ASC',
    'status_asc' => 's.service_status ASC, s.service_name ASC',
    'status_desc' => 's.service_status DESC, s.service_name ASC',
    'grade_high' => '(s.grade_min IS NULL) ASC, s.grade_min DESC, s.service_name ASC',
    'grade_low' => '(s.grade_min IS NULL) ASC, s.grade_min ASC, s.service_name ASC',
    default => "(s.service_status = 'Active') DESC,
        (lt.vacancy_count IS NOT NULL AND lt.vacancy_count > 0) DESC,
        (s.phone IS NOT NULL AND TRIM(s.phone) != '') DESC,
        (s.email IS NOT NULL AND TRIM(s.email) != '') DESC,
        (s.council_area IS NULL) ASC,
        s.council_area ASC,
        s.service_name ASC",
};

$sql = "
    SELECT s.*
    FROM services s
    LEFT JOIN listing_tiers lt ON lt.service_id = s.id
    WHERE {$whereSql}
    ORDER BY {$orderSql}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
if ($nAll === 0) {
    http_response_code(404);
    exit('No services found for this provider reference.');
}

$councils = $councilOptions;

$byType = [];
$stmt = $pdo->prepare('SELECT care_service, COUNT(*) AS c FROM services WHERE sp_number = ? GROUP BY care_service ORDER BY c DESC');
$stmt->execute([$sp]);
while ($trow = $stmt->fetch()) {
    $t = trim((string) ($trow['care_service'] ?? '')) ?: 'Other';
    $byType[$t] = (int) $trow['c'];
}

$stmt = $pdo->prepare(
    'SELECT AVG(grade_min) AS a FROM services WHERE sp_number = ? AND service_status = \'Active\' AND grade_min IS NOT NULL'
);
$stmt->execute([$sp]);
$avgAllRaw = $stmt->fetchColumn();
$avgAll = $avgAllRaw !== null && $avgAllRaw !== '' ? round((float) $avgAllRaw, 1) : null;

// ── Analytics queries ─────────────────────────────────────────

// Grade distribution: count of services per grade_min value (1-6)
$gradeDist = array_fill(1, 6, 0);
$stmt = $pdo->prepare("
    SELECT grade_min, COUNT(*) AS cnt
    FROM services
    WHERE sp_number = ? AND service_status = 'Active' AND grade_min IS NOT NULL
    GROUP BY grade_min ORDER BY grade_min
");
$stmt->execute([$sp]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $gradeDist[(int)$r['grade_min']] = (int)$r['cnt'];
}

// Grading recency
$stmt = $pdo->prepare("
    SELECT
        SUM(grade_published >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR))  AS within_1yr,
        SUM(grade_published >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR))  AS within_2yr,
        SUM(grade_min IS NULL)                                         AS ungraded,
        COUNT(*)                                                        AS total
    FROM services WHERE sp_number = ? AND service_status = 'Active'
");
$stmt->execute([$sp]);
$recency = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalActive = (int)($recency['total'] ?? 0);
$ungraded    = (int)($recency['ungraded'] ?? 0);
$graded      = $totalActive - $ungraded;
$within1yr   = (int)($recency['within_1yr'] ?? 0);
$pctGood     = $graded > 0 ? round(100 * array_sum(array_slice($gradeDist, 3)) / $graded) : null;
$pctVeryGood = $graded > 0 ? round(100 * (($gradeDist[5] + $gradeDist[6]) / $graded))     : null;

// Per-question averages for this provider
$stmt = $pdo->prepare("
    SELECT
        ROUND(AVG(NULLIF(grade_wellbeing,0)),2)  AS wellbeing,
        ROUND(AVG(NULLIF(grade_leadership,0)),2) AS leadership,
        ROUND(AVG(NULLIF(grade_staff,0)),2)      AS staff,
        ROUND(AVG(NULLIF(grade_setting,0)),2)    AS setting,
        ROUND(AVG(NULLIF(grade_planning,0)),2)   AS planning,
        ROUND(AVG(NULLIF(grade_cpl,0)),2)        AS cpl
    FROM services WHERE sp_number = ? AND service_status = 'Active'
");
$stmt->execute([$sp]);
$provQAvg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// National averages for the same service types (excluding this provider)
$typeList = array_keys($byType);
$natQAvg  = [];
if ($typeList) {
    $in = implode(',', array_fill(0, count($typeList), '?'));
    $stmt = $pdo->prepare("
        SELECT
            ROUND(AVG(NULLIF(grade_wellbeing,0)),2)  AS wellbeing,
            ROUND(AVG(NULLIF(grade_leadership,0)),2) AS leadership,
            ROUND(AVG(NULLIF(grade_staff,0)),2)      AS staff,
            ROUND(AVG(NULLIF(grade_setting,0)),2)    AS setting,
            ROUND(AVG(NULLIF(grade_planning,0)),2)   AS planning,
            ROUND(AVG(NULLIF(grade_cpl,0)),2)        AS cpl
        FROM services
        WHERE care_service IN ($in) AND service_status='Active' AND sp_number != ?
    ");
    $stmt->execute([...$typeList, $sp]);
    $natQAvg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Average grade by year of inspection (trend)
$stmt = $pdo->prepare("
    SELECT YEAR(grade_published) AS yr,
           ROUND(AVG(grade_min),2) AS avg_min,
           COUNT(*) AS n
    FROM services
    WHERE sp_number = ? AND service_status = 'Active'
      AND grade_published IS NOT NULL AND grade_min IS NOT NULL
    GROUP BY YEAR(grade_published)
    ORDER BY yr
");
$stmt->execute([$sp]);
$gradeByYear = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Drilldown data for clickable tiles ───────────────────────
$drillCols = "cs_number, service_name, care_service, council_area, town,
              grade_min, grade_max, grade_wellbeing, grade_leadership,
              grade_staff, grade_setting, grade_planning, grade_published";

$stmt = $pdo->prepare("SELECT $drillCols FROM services
    WHERE sp_number=? AND service_status='Active' AND grade_min >= 4
    ORDER BY grade_min DESC, grade_published DESC, service_name");
$stmt->execute([$sp]);
$drillGood = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT $drillCols FROM services
    WHERE sp_number=? AND service_status='Active' AND grade_min >= 5
    ORDER BY grade_min DESC, grade_published DESC, service_name");
$stmt->execute([$sp]);
$drillVGood = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT $drillCols FROM services
    WHERE sp_number=? AND service_status='Active'
      AND grade_published >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
    ORDER BY grade_published DESC");
$stmt->execute([$sp]);
$drillRecent = $stmt->fetchAll(PDO::FETCH_ASSOC);

$mostCommonGrade = array_search(max($gradeDist), $gradeDist);
$stmt = $pdo->prepare("SELECT $drillCols FROM services
    WHERE sp_number=? AND service_status='Active' AND grade_min=?
    ORDER BY service_name");
$stmt->execute([$sp, $mostCommonGrade]);
$drillCommon = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Comparison provider ───────────────────────────────────────
$compareSp = trim($_GET['compare'] ?? '');
if ($compareSp !== '' && (!preg_match('/^[A-Za-z0-9]+$/', $compareSp) || $compareSp === $sp)) {
    $compareSp = '';
}

$cmp     = null;
$cmpName = '';
if ($compareSp !== '') {
    $stmt = $pdo->prepare("SELECT MAX(provider_name) FROM services WHERE sp_number=?");
    $stmt->execute([$compareSp]);
    $cmpName = (string)($stmt->fetchColumn() ?: $compareSp);

    $cmpDist = array_fill(1, 6, 0);
    $stmt = $pdo->prepare("SELECT grade_min, COUNT(*) AS cnt FROM services WHERE sp_number=? AND service_status='Active' AND grade_min IS NOT NULL GROUP BY grade_min");
    $stmt->execute([$compareSp]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $cmpDist[(int)$r['grade_min']] = (int)$r['cnt'];

    $stmt = $pdo->prepare("SELECT YEAR(grade_published) AS yr, ROUND(AVG(grade_min),2) AS avg_min, COUNT(*) AS n FROM services WHERE sp_number=? AND service_status='Active' AND grade_published IS NOT NULL AND grade_min IS NOT NULL GROUP BY YEAR(grade_published) ORDER BY yr");
    $stmt->execute([$compareSp]);
    $cmpByYear = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT ROUND(AVG(NULLIF(grade_wellbeing,0)),2) AS wellbeing, ROUND(AVG(NULLIF(grade_leadership,0)),2) AS leadership, ROUND(AVG(NULLIF(grade_staff,0)),2) AS staff, ROUND(AVG(NULLIF(grade_setting,0)),2) AS setting, ROUND(AVG(NULLIF(grade_planning,0)),2) AS planning, ROUND(AVG(NULLIF(grade_cpl,0)),2) AS cpl FROM services WHERE sp_number=? AND service_status='Active'");
    $stmt->execute([$compareSp]);
    $cmpQAvg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("SELECT SUM(grade_published >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)) AS within_1yr, SUM(grade_min IS NULL) AS ungraded, COUNT(*) AS total FROM services WHERE sp_number=? AND service_status='Active'");
    $stmt->execute([$compareSp]);
    $cmpRec   = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $cmpTotal = (int)($cmpRec['total'] ?? 0);
    $cmpGraded = $cmpTotal - (int)($cmpRec['ungraded'] ?? 0);
    $cmpWithin1yr = (int)($cmpRec['within_1yr'] ?? 0);
    $cmpPctGood  = $cmpGraded > 0 ? round(100 * array_sum(array_slice($cmpDist, 3)) / $cmpGraded) : null;
    $cmpPctVGood = $cmpGraded > 0 ? round(100 * (($cmpDist[5] + $cmpDist[6]) / $cmpGraded)) : null;
    $cmpMostCommon = array_search(max($cmpDist), $cmpDist);

    $cmp = compact('cmpDist','cmpByYear','cmpQAvg','cmpTotal','cmpGraded',
                   'cmpWithin1yr','cmpPctGood','cmpPctVGood','cmpMostCommon');
}

// Search for providers to compare
$compareSearch = trim($_GET['compare_q'] ?? '');
$compareResults = [];
if ($compareSearch !== '' && $compareSp === '') {
    $stmt = $pdo->prepare("
        SELECT sp_number, MAX(provider_name) AS pname, COUNT(*) AS cnt
        FROM services
        WHERE (provider_name LIKE ? OR sp_number LIKE ?)
          AND sp_number != ? AND service_status = 'Active'
          AND provider_name IS NOT NULL AND provider_name != ''
        GROUP BY sp_number
        ORDER BY pname
        LIMIT 12
    ");
    $like = '%' . addcslashes($compareSearch, '%_\\') . '%';
    $stmt->execute([$like, $like, $sp]);
    $compareResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Provider news ───────────────────────────────────────────────────────────
$providerNews = [];
try {
    $newsStmt = $pdo->prepare("
        SELECT title, url, source_name, snippet, published_at
        FROM provider_news
        WHERE sp_number = ? AND status = 'shown'
        ORDER BY published_at DESC
        LIMIT 8
    ");
    $newsStmt->execute([$sp]);
    $providerNews = $newsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {
    // Table not yet created — silently skip
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = preg_replace('/\?.*$/', '', (string) $path);

$sortBase = ['status' => $status, 'council' => $council, 'type' => $type, 'q' => $q];
$sortUrl = static function (string $path, array $base, string $newSort): string {
    // Fragment keeps scroll on the table after full navigation (otherwise browser jumps to top).
    return $path . '?' . http_build_query(array_merge($base, ['sort' => $newSort])) . '#provider-services';
};

$hasFilters = $status !== 'active' || $council !== '' || $type !== '' || $q !== '' || $sort !== 'availability';
$title = $provider_name . ' — All services | CareScotland';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<meta name="description" content="<?= h($provider_name) ?>: <?= $nActiveAll ?> active care services in Scotland (Care Inspectorate data).">
<link rel="stylesheet" href="/assets/style.css">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js" defer></script>
</head>
<body>

<header class="site-header">
  <div class="container">
    <a href="/" class="logo"><span class="logo-icon"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 2.5 4.5 5v5.2c0 5.4 3.3 9.9 7.5 11.3 4.2-1.4 7.5-5.9 7.5-11.3V5L12 2.5Z" fill="currentColor"/><path d="M8.3 12.1l2.6 2.6 4.8-5.4" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg></span> CareScotland</a>
    <div class="nav-disclosure">
      <input type="checkbox" id="nav-toggle" class="nav-toggle-input">
      <label for="nav-toggle" class="nav-toggle" aria-label="Menu">☰</label>
      <nav class="site-header__nav">
      <a href="/">Directory</a>
      <a href="/insights?scope=provider&amp;sp=<?= h(rawurlencode($sp)) ?>">Insights</a>
      <a href="/councils">Council map</a>
      <a href="/news">News</a>
      <a href="/provider/claim.php">Are you a provider?</a>
    </nav>
    </div>
  </div>
</header>

<div class="container provider-overview">
  <nav class="breadcrumb">
    <a href="/">Home</a> › <span><?= h($provider_name) ?></span>
  </nav>

  <header class="provider-overview-header">
    <h1><?= h($provider_name) ?></h1>
    <p class="provider-overview-meta">
      Care Inspectorate service provider reference: <strong><?= h($sp) ?></strong>
      · <?= $nActiveAll ?> active service<?= $nActiveAll === 1 ? '' : 's' ?> in data<?= $cancelledAll > 0 ? ' · ' . $cancelledAll . ' other row(s) (e.g. cancelled)' : '' ?>
    </p>
    <p class="provider-overview-linkrow">
      <a href="/insights?scope=provider&amp;sp=<?= h(rawurlencode($sp)) ?>">Charts &amp; benchmarks (vs Scotland by type) →</a>
      &nbsp;·&nbsp;
      <a href="#complaints">Complaints ↓</a>
    </p>
  </header>

  <section class="profile-section provider-overview-stats">
    <h2>At a glance</h2>
    <div class="stat-grid">
      <div class="stat-card">
        <span class="stat-value"><?= $nActiveAll ?></span>
        <span class="stat-label">Active (all data)</span>
      </div>
      <div class="stat-card">
        <span class="stat-value"><?= count($councils) ?></span>
        <span class="stat-label">Council areas</span>
      </div>
      <?php if ($avgAll !== null): ?>
        <div class="stat-card">
          <span class="stat-value"><?= h((string) $avgAll) ?></span>
          <span class="stat-label">Average lowest inspection grade</span>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($byType): ?>
      <h3 class="provider-subh">Service types</h3>
      <ul class="provider-type-list">
        <?php foreach ($byType as $type => $n): ?>
          <li><span class="type-count"><?= (int) $n ?></span> <?= h($type) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($councils): ?>
      <h3 class="provider-subh">Areas</h3>
      <p class="provider-councils">
        <?php foreach ($councils as $i => $c): ?>
          <?php if ($i > 0): ?>, <?php endif; ?>
          <a href="<?= h($path . '?council=' . urlencode($c) . '#provider-services') ?>"><?= h($c) ?></a>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
  </section>

  <?php if ($providerNews): ?>
  <section class="profile-section provider-news">
    <h2>In the news</h2>
    <p class="provider-news__attr">Articles sourced from Google News matching this provider's name. External links open in a new tab.</p>
    <div class="news-grid">
      <?php foreach ($providerNews as $article):
        $pubTs  = !empty($article['published_at']) ? strtotime($article['published_at']) : null;
        $pubStr = $pubTs ? date('j M Y', $pubTs) : '';
      ?>
      <article class="news-card">
        <a class="news-card__title" href="<?= h($article['url']) ?>" target="_blank" rel="noopener noreferrer">
          <?= h($article['title']) ?>
        </a>
        <div class="news-card__meta">
          <?php if ($article['source_name']): ?>
            <span class="news-card__source"><?= h($article['source_name']) ?></span>
          <?php endif; ?>
          <?php if ($pubStr): ?>
            <?php if ($article['source_name']): ?><span class="news-card__sep">·</span><?php endif; ?>
            <time class="news-card__date" datetime="<?= h($article['published_at'] ?? '') ?>"><?= $pubStr ?></time>
          <?php endif; ?>
        </div>
        <?php if (!empty($article['snippet'])): ?>
          <p class="news-card__snippet"><?= h($article['snippet']) ?></p>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($graded > 0): ?>
  <section class="profile-section provider-analytics">
    <h2>Grade analytics</h2>
    <p class="grade-date-note">Based on the current Care Inspectorate grades for all <?= $totalActive ?> active services. <?= $ungraded ?> service<?= $ungraded !== 1 ? 's have' : ' has' ?> not yet been graded.</p>

    <!-- Compare provider search -->
    <div class="pa-compare">
      <?php if ($cmp): ?>
        <div class="pa-compare__active">
          <span class="pa-compare__dot pa-compare__dot--a"></span> <strong><?= h($provider_name) ?></strong>
          &nbsp;vs&nbsp;
          <span class="pa-compare__dot pa-compare__dot--b"></span> <strong><?= h($cmpName) ?></strong>
          <a href="<?= h($path) ?>" class="pa-compare__remove">✕ Remove comparison</a>
        </div>
      <?php else: ?>
        <form class="pa-compare__form" method="get" action="<?= h($path) ?>">
          <?php foreach (['status'=>$status,'council'=>$council,'q'=>$q,'sort'=>$sort] as $k=>$v): ?>
            <?php if ($v && $v !== 'active' && $v !== 'availability'): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endif; ?>
          <?php endforeach; ?>
          <input type="search" name="compare_q" value="<?= h($compareSearch) ?>"
                 placeholder="Compare with another provider — type their name…"
                 class="pa-compare__input" autocomplete="off">
          <button type="submit" class="pa-compare__btn">Search</button>
        </form>
        <?php if ($compareSearch !== '' && empty($compareResults)): ?>
          <p class="pa-compare__none">No providers found for "<?= h($compareSearch) ?>".</p>
        <?php endif; ?>
        <?php if ($compareResults): ?>
          <div class="pa-compare__results">
            <?php foreach ($compareResults as $r): ?>
              <a class="pa-compare__result"
                 href="<?= h($path . '?compare=' . urlencode($r['sp_number'])) ?>">
                <?= h($r['pname']) ?>
                <span class="pa-compare__cnt"><?= (int)$r['cnt'] ?> services</span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Summary tiles — click to drill down -->
    <?php if ($cmp): ?>
    <!-- Comparison tile grid: side by side rows -->
    <div class="pa-cmp-grid">
      <?php
        $tileRows = [
          ['label' => 'Good or better (4+)',          'a' => $pctGood  !== null ? $pctGood.'%'  : '—', 'b' => $cmp['cmpPctGood']  !== null ? $cmp['cmpPctGood'].'%'  : '—', 'suffix' => ' of graded services'],
          ['label' => 'Very Good or Excellent (5–6)', 'a' => $pctVeryGood !== null ? $pctVeryGood.'%' : '—', 'b' => $cmp['cmpPctVGood'] !== null ? $cmp['cmpPctVGood'].'%' : '—', 'suffix' => ' of graded services'],
          ['label' => 'Inspected in last 12 months',  'a' => $within1yr,  'b' => $cmp['cmpWithin1yr'], 'suffix' => ' services'],
          ['label' => 'Total active services',         'a' => $totalActive, 'b' => $cmp['cmpTotal'],    'suffix' => ' services'],
          ['label' => 'Most common grade',             'a' => grade_label($mostCommonGrade) . ' (' . $gradeDist[$mostCommonGrade] . ')', 'b' => grade_label($cmp['cmpMostCommon']) . ' (' . $cmp['cmpDist'][$cmp['cmpMostCommon']] . ')', 'suffix' => ''],
        ];
      ?>
      <div class="pa-cmp-header">
        <div></div>
        <div class="pa-cmp-col-a"><span class="pa-compare__dot pa-compare__dot--a"></span> <?= h($provider_name) ?></div>
        <div class="pa-cmp-col-b"><span class="pa-compare__dot pa-compare__dot--b"></span> <?= h($cmpName) ?></div>
      </div>
      <?php foreach ($tileRows as $row): ?>
      <div class="pa-cmp-row">
        <div class="pa-cmp-label"><?= h($row['label']) ?></div>
        <div class="pa-cmp-val pa-cmp-val--a"><?= h((string)$row['a']) ?></div>
        <div class="pa-cmp-val pa-cmp-val--b"><?= h((string)$row['b']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="pa-tiles">
      <?php if ($pctGood !== null): ?>
      <button class="pa-tile pa-tile--good pa-tile--btn" data-panel="drill-good" aria-expanded="false">
        <div class="pa-tile__val"><?= $pctGood ?>%</div>
        <div class="pa-tile__label">of graded services rated Good or better (4+)</div>
        <div class="pa-tile__cta">View <?= count($drillGood) ?> services ↓</div>
      </button>
      <?php endif; ?>
      <?php if ($pctVeryGood !== null): ?>
      <button class="pa-tile pa-tile--vgood pa-tile--btn" data-panel="drill-vgood" aria-expanded="false">
        <div class="pa-tile__val"><?= $pctVeryGood ?>%</div>
        <div class="pa-tile__label">rated Very Good or Excellent (5–6)</div>
        <div class="pa-tile__cta">View <?= count($drillVGood) ?> services ↓</div>
      </button>
      <?php endif; ?>
      <button class="pa-tile pa-tile--btn" data-panel="drill-recent" aria-expanded="false">
        <div class="pa-tile__val"><?= $within1yr ?></div>
        <div class="pa-tile__label">services inspected in the last 12 months</div>
        <div class="pa-tile__cta">View <?= count($drillRecent) ?> services ↓</div>
      </button>
      <?php if ($graded > 0): ?>
      <button class="pa-tile pa-tile--btn" data-panel="drill-common" aria-expanded="false">
        <div class="pa-tile__val"><?= $gradeDist[$mostCommonGrade] ?></div>
        <div class="pa-tile__label">services rated <?= grade_label($mostCommonGrade) ?> — most common grade</div>
        <div class="pa-tile__cta">View <?= count($drillCommon) ?> services ↓</div>
      </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Drilldown panels -->
    <?php
    $drillPanels = [
        'drill-good'   => ['title' => 'Services rated Good or better (grade 4+)',           'rows' => $drillGood,   'note' => 'These services have a minimum inspection grade of 4 or above across all key questions.'],
        'drill-vgood'  => ['title' => 'Services rated Very Good or Excellent (grade 5–6)',   'rows' => $drillVGood,  'note' => 'These services have a minimum inspection grade of 5 or above — the top two bands.'],
        'drill-recent' => ['title' => 'Services inspected in the last 12 months',            'rows' => $drillRecent, 'note' => 'Ordered by most recent inspection date first.'],
        'drill-common' => ['title' => 'Services rated ' . grade_label($mostCommonGrade) . ' (grade ' . $mostCommonGrade . ') — most common grade', 'rows' => $drillCommon, 'note' => 'All active services from this provider with a minimum grade of ' . $mostCommonGrade . '.'],
    ];
    foreach ($drillPanels as $panelId => $panel):
    ?>
    <div id="<?= $panelId ?>" class="pa-drill" hidden>
      <div class="pa-drill__header">
        <h3><?= h($panel['title']) ?></h3>
        <button class="pa-drill__close" data-panel="<?= $panelId ?>">✕ Close</button>
      </div>
      <p class="grade-date-note"><?= h($panel['note']) ?></p>
      <?php if (empty($panel['rows'])): ?>
        <p class="pa-drill__empty">No services match this criteria.</p>
      <?php else: ?>
      <div class="pa-drill__table-wrap">
        <table class="pa-drill__table">
          <thead>
            <tr>
              <th>Service</th>
              <th>Type</th>
              <th>Council</th>
              <th>Min</th>
              <th>Wellbeing</th>
              <th>Leadership</th>
              <th>Staff</th>
              <th>Setting</th>
              <th>Planning</th>
              <th>Last inspected</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($panel['rows'] as $r):
            $url = '/service/' . h($r['cs_number']) . '/' . slug($r['service_name']);
            $gpTs = !empty($r['grade_published']) ? strtotime($r['grade_published']) : null;
          ?>
          <tr>
            <td class="dt-name"><a href="<?= $url ?>"><?= h($r['service_name']) ?></a>
              <?php if ($r['town']): ?><span class="dt-town"><?= h($r['town']) ?></span><?php endif; ?></td>
            <td class="dt-type"><?= h($r['care_service'] ?? '—') ?></td>
            <td class="dt-council"><?= h($r['council_area'] ?? '—') ?></td>
            <td class="dt-grade">
              <?php if ($r['grade_min'] !== null): ?>
                <span class="grade-pip <?= grade_class((int)$r['grade_min']) ?>"><?= $r['grade_min'] ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <?php foreach (['grade_wellbeing','grade_leadership','grade_staff','grade_setting','grade_planning'] as $gk): ?>
            <td class="dt-grade">
              <?php $g = $r[$gk] ?? null; ?>
              <?php if ($g): ?><span class="grade-pip <?= grade_class((int)$g) ?>"><?= $g ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <?php endforeach; ?>
            <td class="dt-date"><?= $gpTs ? date('j M Y', $gpTs) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <script>
    (function() {
      var btns   = document.querySelectorAll('.pa-tile--btn');
      var panels = document.querySelectorAll('.pa-drill');

      function closeAll() {
        panels.forEach(function(p) { p.hidden = true; });
        btns.forEach(function(b) { b.setAttribute('aria-expanded','false'); b.classList.remove('pa-tile--active'); });
      }

      btns.forEach(function(btn) {
        btn.addEventListener('click', function() {
          var id    = btn.dataset.panel;
          var panel = document.getElementById(id);
          var open  = btn.getAttribute('aria-expanded') === 'true';
          closeAll();
          if (!open) {
            panel.hidden = false;
            btn.setAttribute('aria-expanded','true');
            btn.classList.add('pa-tile--active');
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }
        });
      });

      document.querySelectorAll('.pa-drill__close').forEach(function(btn) {
        btn.addEventListener('click', closeAll);
      });
    })();
    </script>

    <!-- Charts row -->
    <div class="pa-charts">

      <!-- Grade distribution -->
      <div class="pa-chart-box">
        <h3>Grade distribution <span class="pa-chart-sub">(lowest grade per service)</span></h3>
        <div class="pa-chart-wrap">
          <canvas id="chartDist"></canvas>
        </div>
        <p class="grade-date-note">How many services score each grade on their weakest key question. Higher is better (6 = Excellent).</p>
      </div>

      <?php if (count($gradeByYear) >= 2): ?>
      <!-- Trend by year -->
      <div class="pa-chart-box">
        <h3>Average grade by inspection year</h3>
        <div class="pa-chart-wrap">
          <canvas id="chartTrend"></canvas>
        </div>
        <p class="grade-date-note">Average minimum grade across services whose inspection was published in each year. Shows whether portfolio performance is improving over time.</p>
      </div>
      <?php endif; ?>

    </div>

    <!-- Per-question breakdown -->
    <?php
      $questions = [
        'wellbeing'  => 'Support & wellbeing',
        'leadership' => 'Leadership',
        'staff'      => 'Staff team',
        'setting'    => 'Setting',
        'planning'   => 'Care & support planning',
        'cpl'        => 'Care, play & learning',
      ];
      $hasQData = array_filter($provQAvg, fn($v) => $v !== null && $v > 0);
    ?>
    <?php if ($hasQData): ?>
    <h3 style="margin-top:1.5rem;">Average grade per key question</h3>
    <p class="grade-date-note">
      <?= $cmp ? h($provider_name) . ' vs ' . h($cmpName) : 'This provider vs Scotland-wide average for the same service types.' ?>
    </p>
    <div class="pa-questions">
      <?php foreach ($questions as $field => $label):
        $pVal = isset($provQAvg[$field]) && $provQAvg[$field] > 0 ? (float)$provQAvg[$field] : null;
        $bVal = $cmp
            ? (isset($cmp['cmpQAvg'][$field]) && $cmp['cmpQAvg'][$field] > 0 ? (float)$cmp['cmpQAvg'][$field] : null)
            : (isset($natQAvg[$field]) && $natQAvg[$field] > 0 ? (float)$natQAvg[$field] : null);
        if ($pVal === null) continue;
        $pPct = round(($pVal / 6) * 100);
        $bPct = $bVal ? round(($bVal / 6) * 100) : null;
        $diff = $bVal !== null ? round($pVal - $bVal, 1) : null;
        $bLabel = $cmp ? h($cmpName) : 'Scotland avg';
        $bFill  = $cmp ? 'pa-q-fill--cmp' : 'pa-q-fill--nat';
      ?>
      <div class="pa-q-row">
        <div class="pa-q-label"><?= h($label) ?></div>
        <div class="pa-q-bars">
          <div class="pa-q-bar-wrap" title="<?= $cmp ? h($provider_name) : 'This provider' ?>: <?= number_format($pVal,1) ?>">
            <span class="pa-q-bar-tag"><?= $cmp ? h($provider_name) : 'This provider' ?></span>
            <div class="pa-q-bar">
              <div class="pa-q-fill pa-q-fill--prov" style="width:<?= $pPct ?>%"></div>
            </div>
            <span class="pa-q-val"><?= number_format($pVal, 1) ?></span>
            <?php if ($diff !== null): ?>
              <span class="pa-q-diff <?= $diff > 0 ? 'diff--above' : ($diff < 0 ? 'diff--below' : 'diff--same') ?>">
                <?= $diff > 0 ? '+' : '' ?><?= $diff ?>
              </span>
            <?php endif; ?>
          </div>
          <?php if ($bPct !== null): ?>
          <div class="pa-q-bar-wrap pa-q-nat" title="<?= $bLabel ?>: <?= number_format($bVal,1) ?>">
            <span class="pa-q-bar-tag"><?= $bLabel ?></span>
            <div class="pa-q-bar">
              <div class="pa-q-fill <?= $bFill ?>" style="width:<?= $bPct ?>%"></div>
            </div>
            <span class="pa-q-val"><?= number_format($bVal, 1) ?></span>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Chart JS -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      var gradeColors = ['','#c62828','#e53935','#ef6c00','#7cb342','#43a047','#2e7d32'];
      var gradeLabels = ['','Unsatisfactory','Weak','Adequate','Good','Very Good','Excellent'];
      var distA       = <?= json_encode(array_values($gradeDist)) ?>;
      var nameA       = <?= json_encode($provider_name) ?>;
      var comparing   = <?= $cmp ? 'true' : 'false' ?>;
      <?php if ($cmp): ?>
      var distB = <?= json_encode(array_values($cmp['cmpDist'])) ?>;
      var nameB = <?= json_encode($cmpName) ?>;
      <?php endif; ?>

      // Distribution chart
      var ctxDist = document.getElementById('chartDist');
      if (ctxDist) {
        var distDatasets = comparing ? [
          { label: nameA, data: distA, backgroundColor: 'rgba(21,101,192,0.75)', borderRadius: 4 },
          { label: nameB, data: distB, backgroundColor: 'rgba(230,81,0,0.75)',   borderRadius: 4 },
        ] : [
          { label: 'Services', data: distA, backgroundColor: gradeColors.slice(1), borderRadius: 4 },
        ];
        new Chart(ctxDist, {
          type: 'bar',
          data: { labels: gradeLabels.slice(1), datasets: distDatasets },
          options: {
            responsive: true,
            plugins: {
              legend: { display: comparing },
              tooltip: {
                callbacks: {
                  title: function(items) { return gradeLabels[items[0].dataIndex + 1] + ' (' + (items[0].dataIndex + 1) + ')'; },
                  label: function(item) { return item.dataset.label + ': ' + item.raw + ' service' + (item.raw !== 1 ? 's' : ''); }
                }
              }
            },
            scales: {
              y: { beginAtZero: true, ticks: { stepSize: 1 }, title: { display: true, text: 'Number of services' } },
              x: { grid: { display: false } }
            }
          }
        });
      }

      <?php
        // Merge years from both providers for the trend chart x-axis
        $allYears = array_unique(array_merge(
            array_column($gradeByYear, 'yr'),
            $cmp ? array_column($cmp['cmpByYear'], 'yr') : []
        ));
        sort($allYears);
        $yearAvgA = array_column($gradeByYear, 'avg_min', 'yr');
        $yearAvgB = $cmp ? array_column($cmp['cmpByYear'], 'avg_min', 'yr') : [];
        $yearNA   = array_column($gradeByYear, 'n', 'yr');
        $yearNB   = $cmp ? array_column($cmp['cmpByYear'], 'n', 'yr') : [];
      ?>
      <?php if (count($allYears) >= 2): ?>
      // Trend chart
      var trendYears = <?= json_encode(array_values($allYears)) ?>;
      var trendA     = <?= json_encode(array_map(fn($y) => isset($yearAvgA[$y]) ? (float)$yearAvgA[$y] : null, $allYears)) ?>;
      var trendNA    = <?= json_encode(array_map(fn($y) => $yearNA[$y] ?? 0, $allYears)) ?>;
      <?php if ($cmp): ?>
      var trendB  = <?= json_encode(array_map(fn($y) => isset($yearAvgB[$y]) ? (float)$yearAvgB[$y] : null, $allYears)) ?>;
      var trendNB = <?= json_encode(array_map(fn($y) => $yearNB[$y] ?? 0, $allYears)) ?>;
      <?php endif; ?>
      var ctxTrend = document.getElementById('chartTrend');
      if (ctxTrend) {
        var trendDatasets = [{
          label: nameA,
          data: trendA,
          borderColor: '#1565c0',
          backgroundColor: 'rgba(21,101,192,0.08)',
          pointBackgroundColor: trendA.map(function(v){ return v === null ? 'transparent' : (v >= 4 ? '#2e7d32' : v >= 3 ? '#ef6c00' : '#c62828'); }),
          pointRadius: 6, tension: 0.3, fill: !comparing, spanGaps: true,
        }];
        <?php if ($cmp): ?>
        trendDatasets.push({
          label: nameB,
          data: trendB,
          borderColor: '#e65100',
          backgroundColor: 'rgba(230,81,0,0.08)',
          pointBackgroundColor: trendB.map(function(v){ return v === null ? 'transparent' : (v >= 4 ? '#2e7d32' : v >= 3 ? '#ef6c00' : '#c62828'); }),
          pointRadius: 6, tension: 0.3, fill: false, spanGaps: true, borderDash: [5,3],
        });
        <?php endif; ?>
        new Chart(ctxTrend, {
          type: 'line',
          data: { labels: trendYears, datasets: trendDatasets },
          options: {
            responsive: true,
            plugins: {
              legend: { display: comparing },
              tooltip: {
                callbacks: {
                  label: function(ctx) {
                    if (ctx.parsed.y === null) return null;
                    var nArr = ctx.datasetIndex === 0 ? trendNA : <?= $cmp ? 'trendNB' : 'trendNA' ?>;
                    var n = nArr[ctx.dataIndex];
                    return ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2) + ' (' + n + ' service' + (n !== 1 ? 's' : '') + ')';
                  }
                }
              }
            },
            scales: {
              y: {
                min: 1, max: 6,
                ticks: { callback: function(v) { return gradeLabels[v] ? v + ' – ' + gradeLabels[v] : v; } },
                grid: { color: 'rgba(0,0,0,0.06)' }
              },
              x: { grid: { display: false } }
            }
          }
        });
      }
      <?php endif; ?>
    });
    </script>

  </section>
  <?php endif; ?>

  <?php
  // ── Complaints section ──────────────────────────────────
  $complaintStats = get_provider_complaints_stats($sp);
  if ($complaintStats['services_with_complaints'] > 0):
  ?>
  <section class="profile-section provider-complaints" id="complaints">
    <h2>Complaints</h2>
    <p class="grade-date-note">
      Complaint outcomes recorded by the Care Inspectorate across all active services.
      <a href="/complaints?sp=<?= h(rawurlencode($sp)) ?>">View all complaints for this provider →</a>
    </p>

    <div class="complaints-stats complaints-stats--compact">
      <div class="complaints-stat complaints-stat--upheld">
        <span class="complaints-stat__val"><?= $complaintStats['total_upheld'] ?></span>
        <span class="complaints-stat__label">Total upheld (all years)</span>
      </div>
      <div class="complaints-stat">
        <span class="complaints-stat__val"><?= $complaintStats['services_with_complaints'] ?></span>
        <span class="complaints-stat__label">Service<?= $complaintStats['services_with_complaints'] !== 1 ? 's' : '' ?> with upheld complaints</span>
      </div>
    </div>

    <div class="complaints-table-wrap">
      <table class="complaints-table">
        <thead>
          <tr>
            <th>Service</th>
            <th>Type</th>
            <th>Council</th>
            <th class="num-col">Upheld</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($complaintStats['rows'] as $cr):
            $csUrl = '/service/' . rawurlencode($cr['cs_number']) . '/' . rawurlencode(slug($cr['service_name']));
          ?>
          <tr>
            <td class="td-service">
              <a href="<?= h($csUrl . '#complaints') ?>"><?= h($cr['service_name']) ?></a>
              <?php if ($cr['town']): ?>
                <span class="td-sub"><?= h($cr['town']) ?></span>
              <?php endif; ?>
            </td>
            <td class="td-type"><?= h($cr['care_service'] ?? '—') ?></td>
            <td><?= h($cr['council_area'] ?? '—') ?></td>
            <td class="num-col complaints-upheld-num"><?= (int)$cr['complaints_upheld'] ?></td>
            <td class="td-action">
              <a href="<?= h($csUrl . '#complaints') ?>" class="btn-view">Details →</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="grade-date-note" style="margin-top:0.5rem;">
      Detailed case history (date, case number, category) is shown on each service's profile page.
    </p>
  </section>
  <?php endif; ?>

  <section class="profile-section">
    <h2>All services (this provider)</h2>

    <form class="provider-filters" method="get" action="<?= h($path) ?>">
      <input type="hidden" name="sort" value="<?= h($sort) ?>">
      <?php if ($type !== ''): ?>
        <input type="hidden" name="type" value="<?= h($type) ?>">
      <?php endif; ?>
      <div class="provider-filters-row">
        <label class="provider-filter">
          <span>Status</span>
          <select name="status">
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active only</option>
            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All statuses</option>
          </select>
        </label>
        <label class="provider-filter">
          <span>Council</span>
          <select name="council">
            <option value="">Any council</option>
            <?php foreach ($councilOptions as $c): ?>
              <option value="<?= h($c) ?>" <?= $council === $c ? 'selected' : '' ?>><?= h($c) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="provider-filter provider-filter--grow">
          <span>Search name</span>
          <input type="search" name="q" value="<?= h($q) ?>" placeholder="Part of service name…" maxlength="120">
        </label>
        <div class="provider-filters-actions">
          <button type="submit" class="btn-primary">Apply</button>
          <a class="btn-secondary" href="<?= h($path) ?>">Clear</a>
        </div>
      </div>
    </form>

    <p class="provider-results-meta">
      Showing <strong><?= count($rows) ?></strong> of <strong><?= $nAll ?></strong> row<?= $nAll === 1 ? '' : 's' ?> for this provider
      <?php if ($hasFilters): ?><span class="filter-active">· filters on</span><?php endif; ?>
    </p>

    <p class="grade-note">Use the form above to narrow the list (status, council, search). Data: Care Inspectorate Datastore (OGL).</p>

    <?php if (empty($rows)): ?>
      <p class="no-results">No services match these filters. <a href="<?= h($path) ?>">Clear filters</a>.</p>
    <?php else: ?>
    <?php
      $nextName = in_array($sort, ['name_asc', 'name_desc'], true)
          ? ($sort === 'name_asc' ? 'name_desc' : 'name_asc')
          : 'name_asc';
      $nextType = in_array($sort, ['type', 'type_desc'], true)
          ? ($sort === 'type' ? 'type_desc' : 'type')
          : 'type';
      $nextCouncil = in_array($sort, ['council_asc', 'council_desc'], true)
          ? ($sort === 'council_asc' ? 'council_desc' : 'council_asc')
          : 'council_asc';
      $nextStatus = in_array($sort, ['status_asc', 'status_desc'], true)
          ? ($sort === 'status_asc' ? 'status_desc' : 'status_asc')
          : 'status_asc';
      $nextGrade = in_array($sort, ['grade_high', 'grade_low'], true)
          ? ($sort === 'grade_high' ? 'grade_low' : 'grade_high')
          : 'grade_high';
    ?>
    <div class="provider-service-table-wrap" id="provider-services">
      <table class="provider-service-table">
        <thead>
          <tr>
            <th scope="col"<?= $sort === 'name_asc' ? ' aria-sort="ascending"' : ($sort === 'name_desc' ? ' aria-sort="descending"' : '') ?>>
              <a href="<?= h($sortUrl($path, $sortBase, $nextName)) ?>"
                 class="th-sort<?= in_array($sort, ['name_asc', 'name_desc'], true) ? ' th-sort--active' : '' ?>"
                 title="Sort by service name. Click again to reverse A–Z / Z–A."
                 aria-label="Sort table by service name. Click to change order.">
                <span class="th-sort__label">Service</span>
                <span class="th-sort__glyph" aria-hidden="true"><?= in_array($sort, ['name_asc', 'name_desc'], true) ? ($sort === 'name_asc' ? '▲' : '▼') : '↕' ?></span>
              </a>
            </th>
            <th scope="col"<?= $sort === 'type' ? ' aria-sort="ascending"' : ($sort === 'type_desc' ? ' aria-sort="descending"' : '') ?>>
              <a href="<?= h($sortUrl($path, $sortBase, $nextType)) ?>"
                 class="th-sort<?= in_array($sort, ['type', 'type_desc'], true) ? ' th-sort--active' : '' ?>"
                 title="Sort by service type. Click again to reverse order."
                 aria-label="Sort table by service type.">
                <span class="th-sort__label">Type</span>
                <span class="th-sort__glyph" aria-hidden="true"><?= in_array($sort, ['type', 'type_desc'], true) ? ($sort === 'type' ? '▲' : '▼') : '↕' ?></span>
              </a>
            </th>
            <th scope="col"<?= $sort === 'council_asc' ? ' aria-sort="ascending"' : ($sort === 'council_desc' ? ' aria-sort="descending"' : '') ?>>
              <a href="<?= h($sortUrl($path, $sortBase, $nextCouncil)) ?>"
                 class="th-sort<?= in_array($sort, ['council_asc', 'council_desc'], true) ? ' th-sort--active' : '' ?>"
                 title="Sort by council / area. Click again to reverse order."
                 aria-label="Sort table by council or area.">
                <span class="th-sort__label">Area</span>
                <span class="th-sort__glyph" aria-hidden="true"><?= in_array($sort, ['council_asc', 'council_desc'], true) ? ($sort === 'council_asc' ? '▲' : '▼') : '↕' ?></span>
              </a>
            </th>
            <th scope="col"<?= $sort === 'status_asc' ? ' aria-sort="ascending"' : ($sort === 'status_desc' ? ' aria-sort="descending"' : '') ?>>
              <a href="<?= h($sortUrl($path, $sortBase, $nextStatus)) ?>"
                 class="th-sort<?= in_array($sort, ['status_asc', 'status_desc'], true) ? ' th-sort--active' : '' ?>"
                 title="Sort by status (e.g. Active). Click again to reverse order."
                 aria-label="Sort table by service status.">
                <span class="th-sort__label">Status</span>
                <span class="th-sort__glyph" aria-hidden="true"><?= in_array($sort, ['status_asc', 'status_desc'], true) ? ($sort === 'status_asc' ? '▲' : '▼') : '↕' ?></span>
              </a>
            </th>
            <th scope="col"<?= $sort === 'grade_high' ? ' aria-sort="descending"' : ($sort === 'grade_low' ? ' aria-sort="ascending"' : '') ?>>
              <a href="<?= h($sortUrl($path, $sortBase, $nextGrade)) ?>"
                 class="th-sort<?= in_array($sort, ['grade_high', 'grade_low'], true) ? ' th-sort--active' : '' ?>"
                 title="Sort by lowest inspection grade (higher number is better). Click again to reverse."
                 aria-label="Sort table by lowest inspection grade.">
                <span class="th-sort__label">Lowest grade</span>
                <span class="th-sort__glyph" aria-hidden="true"><?= in_array($sort, ['grade_high', 'grade_low'], true) ? ($sort === 'grade_high' ? '▼' : '▲') : '↕' ?></span>
              </a>
            </th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $slug = slug($r['service_name'] ?? '');
              $url = '/service/' . rawurlencode((string) $r['cs_number']) . '/' . rawurlencode($slug);
            ?>
            <tr class="<?= ($r['service_status'] ?? '') === 'Active' ? '' : 'row-muted' ?>">
              <td><a href="<?= h($url) ?>"><?= h($r['service_name'] ?? '') ?></a></td>
              <td><?= h($r['care_service'] ?? '') ?></td>
              <td>
                <?php if (!empty($r['council_area'])): ?>
                  <a href="<?= h($path . '?council=' . urlencode($r['council_area']) . '#provider-services') ?>"><?= h($r['council_area']) ?></a>
                <?php endif; ?>
                <?= !empty($r['town']) ? '<br><span class="td-sub">' . h($r['town']) . '</span>' : '' ?>
              </td>
              <td><?= h($r['service_status'] ?? '') ?></td>
              <td><?= $r['grade_min'] !== null && $r['grade_min'] !== '' ? h((string) $r['grade_min']) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
</div>

<footer class="site-footer">
  <div class="container">
    <p>Service data from the <a href="https://www.careinspectorate.com" rel="noopener">Care Inspectorate</a> (Open Government Licence).</p>
    <p class="site-footer__admin"><a href="/admin/imports.php">Admin</a></p>
  </div>
</footer>

</body>
</html>
