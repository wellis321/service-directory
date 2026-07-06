<?php
// =============================================================
// public/complaints-metrics.php — Provider complaints benchmarking
// URL: /complaints-metrics
// =============================================================
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';

$pdo = db();

// ── Filters ──────────────────────────────────────────────────
$type        = trim($_GET['type']    ?? '');
$min         = max(1, (int) ($_GET['min']  ?? 2));
$sort        = trim($_GET['sort']    ?? 'ratio_desc');
$highlight   = trim($_GET['highlight'] ?? '');   // pinned SP for comparison
$compare_q   = trim($_GET['compare_q'] ?? '');
$page        = max(1, (int) ($_GET['page'] ?? 1));
$per_page    = 50;

$typeOptions = $pdo->query(
    "SELECT DISTINCT care_service FROM services
     WHERE care_service IS NOT NULL AND care_service != '' AND public_list = 1
     ORDER BY care_service"
)->fetchAll(PDO::FETCH_COLUMN);

if ($type !== '' && !in_array($type, $typeOptions, true)) $type = '';

$validSorts = ['ratio_desc','ratio_asc','total_desc','services_desc','name_az'];
if (!in_array($sort, $validSorts, true)) $sort = 'ratio_desc';

$orderSql = match ($sort) {
    'ratio_asc'     => 'ratio ASC, provider_name ASC',
    'total_desc'    => 'total_upheld DESC, provider_name ASC',
    'services_desc' => 'service_count DESC, provider_name ASC',
    'name_az'       => 'provider_name ASC',
    default         => 'ratio DESC, total_upheld DESC, provider_name ASC',
};

$typeWhere = $type !== '' ? "AND s.care_service = :type" : '';
$typeBinds = $type !== '' ? [':type' => $type] : [];

// ── Sector / type benchmark ───────────────────────────────
$secStmt = $pdo->prepare("
    SELECT
        ROUND(SUM(complaints_upheld) / NULLIF(COUNT(*), 0), 4) AS sector_ratio,
        SUM(complaints_upheld)                                  AS sector_total,
        COUNT(*)                                                AS sector_services,
        COUNT(DISTINCT sp_number)                               AS sector_providers
    FROM services s
    WHERE s.service_status = 'Active' AND s.public_list = 1 $typeWhere
");
$secStmt->execute($typeBinds);
$sector      = $secStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$sectorRatio = (float) ($sector['sector_ratio'] ?? 0);

// ── Type breakdown (for no-type view) ────────────────────
$typeStats = [];
if ($type === '') {
    $stmt = $pdo->query("
        SELECT
            care_service                                                  AS type,
            COUNT(DISTINCT sp_number)                                     AS providers,
            COUNT(*)                                                      AS services,
            SUM(complaints_upheld)                                        AS total_upheld,
            ROUND(SUM(complaints_upheld) / NULLIF(COUNT(*), 0), 3)       AS ratio
        FROM services
        WHERE service_status = 'Active' AND public_list = 1
          AND care_service IS NOT NULL AND care_service != ''
        GROUP BY care_service
        ORDER BY ratio DESC
    ");
    $typeStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Pinned provider comparison ────────────────────────────
$pinnedRow = null;
if ($highlight !== '') {
    $stmt = $pdo->prepare("
        SELECT sp_number,
               MAX(provider_name)                                             AS provider_name,
               COUNT(*)                                                       AS service_count,
               SUM(complaints_upheld)                                         AS total_upheld,
               ROUND(SUM(complaints_upheld) / COUNT(*), 4)                   AS ratio,
               GROUP_CONCAT(DISTINCT care_service ORDER BY care_service SEPARATOR ', ') AS service_types
        FROM services s
        WHERE s.service_status = 'Active' AND s.public_list = 1
          AND s.sp_number = :sp $typeWhere
        GROUP BY sp_number
    ");
    $stmt->execute(array_merge($typeBinds, [':sp' => $highlight]));
    $pinnedRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Provider search for comparison pin
$compareResults = [];
if ($compare_q !== '' && $highlight === '') {
    $stmt = $pdo->prepare("
        SELECT sp_number, MAX(provider_name) AS pname, COUNT(*) AS cnt
        FROM services s
        WHERE (s.provider_name LIKE :q OR s.sp_number LIKE :q2)
          AND s.service_status = 'Active' AND s.public_list = 1
          $typeWhere
        GROUP BY sp_number
        ORDER BY pname
        LIMIT 10
    ");
    $like = '%' . addcslashes($compare_q, '%_\\') . '%';
    $stmt->execute(array_merge($typeBinds, [':q' => $like, ':q2' => $like]));
    $compareResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Paginated provider table ──────────────────────────────
$cntStmt = $pdo->prepare("
    SELECT COUNT(*) FROM (
        SELECT sp_number FROM services s
        WHERE s.service_status = 'Active' AND s.public_list = 1 $typeWhere
        GROUP BY sp_number
        HAVING COUNT(*) >= :min
    ) t
");
$cntStmt->execute(array_merge($typeBinds, [':min' => $min]));
$total  = (int) $cntStmt->fetchColumn();
$pages  = $total > 0 ? (int) ceil($total / $per_page) : 1;
$page   = min(max(1, $page), $pages);
$offset = ($page - 1) * $per_page;

$dataStmt = $pdo->prepare("
    SELECT sp_number,
           MAX(provider_name)                                             AS provider_name,
           COUNT(*)                                                       AS service_count,
           SUM(complaints_upheld)                                         AS total_upheld,
           ROUND(SUM(complaints_upheld) / COUNT(*), 4)                   AS ratio,
           GROUP_CONCAT(DISTINCT care_service ORDER BY care_service SEPARATOR ', ') AS service_types
    FROM services s
    WHERE s.service_status = 'Active' AND s.public_list = 1 $typeWhere
    GROUP BY sp_number
    HAVING service_count >= :min
    ORDER BY $orderSql
    LIMIT :limit OFFSET :offset
");
foreach ($typeBinds as $k => $v) $dataStmt->bindValue($k, $v);
$dataStmt->bindValue(':min',    $min,      PDO::PARAM_INT);
$dataStmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
$dataStmt->execute();
$rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

// Scale for ratio bars: use 95th-percentile ratio to avoid one outlier
// crushing every other bar
$allRatios = array_column($rows, 'ratio');
if ($pinnedRow) $allRatios[] = $pinnedRow['ratio'];
rsort($allRatios);
$p95idx    = max(0, (int) floor(count($allRatios) * 0.05));
$barScale  = (float) ($allRatios[$p95idx] ?? 1) ?: 1;
$barScale  = max($barScale, $sectorRatio * 1.5, 0.01);

// ── URL helpers ───────────────────────────────────────────
$urlBase = array_filter([
    'type'      => $type,
    'min'       => $min !== 2  ? $min  : '',
    'sort'      => $sort !== 'ratio_desc' ? $sort : '',
    'highlight' => $highlight,
], fn($v) => $v !== '');

$selfUrl = '/complaints-metrics';
$sortUrl = fn(string $s) => $selfUrl . '?' . http_build_query(array_filter(array_merge($urlBase, ['sort' => $s !== 'ratio_desc' ? $s : ''])));

$title = 'Complaints metrics — CareScotland';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?></title>
<meta name="description" content="Complaints per service benchmarking across Scottish care providers. Compare upheld complaint rates like-for-like by service type.">
<link rel="stylesheet" href="<?= asset_url('/assets/style.css') ?>">
<link rel="icon" type="image/svg+xml" href="<?= asset_url('/assets/favicon.svg') ?>">
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
      <a href="/insights">Insights</a>
      <a href="/councils">Council map</a>
      <a href="/news">News</a>
      <a href="/provider/claim.php">Are you a provider?</a>
    </nav>
    </div>
  </div>
</header>

<div class="container">

  <nav class="breadcrumb">
    <a href="/">Home</a> › <a href="/complaints">Complaints</a> › Metrics
  </nav>

  <div class="cm-header">
    <div>
      <h1>Complaints benchmarking</h1>
      <p class="cm-sub">Upheld complaints per active service — ranked by provider. Filter by service type for like-for-like comparisons.</p>
    </div>
  </div>

  <!-- Sector average strip -->
  <div class="complaints-stats" style="margin-bottom:1rem;">
    <div class="complaints-stat">
      <span class="complaints-stat__val"><?= number_format((int)($sector['sector_providers'] ?? 0)) ?></span>
      <span class="complaints-stat__label">Providers<?= $type ? ' — ' . h($type) : ' (all types)' ?></span>
    </div>
    <div class="complaints-stat">
      <span class="complaints-stat__val"><?= number_format((int)($sector['sector_services'] ?? 0)) ?></span>
      <span class="complaints-stat__label">Active services</span>
    </div>
    <div class="complaints-stat complaints-stat--upheld">
      <span class="complaints-stat__val"><?= number_format((int)($sector['sector_total'] ?? 0)) ?></span>
      <span class="complaints-stat__label">Total upheld</span>
    </div>
    <div class="complaints-stat">
      <span class="complaints-stat__val" style="color:#0f6e56;"><?= number_format($sectorRatio, 3) ?></span>
      <span class="complaints-stat__label">Sector avg per service<?= $type ? '' : ' (all types)' ?></span>
    </div>
  </div>

  <!-- Type breakdown (when no type filter) -->
  <?php if ($type === '' && !empty($typeStats)): ?>
  <section class="profile-section" style="margin-bottom:1.5rem;">
    <h2 style="font-size:1rem;margin-bottom:0.6rem;">By service type <span style="font-weight:400;color:#888;font-size:0.82rem;">— click a type to filter like-for-like</span></h2>
    <div class="cm-type-grid">
      <?php foreach ($typeStats as $ts):
        $ts_ratio = (float)$ts['ratio'];
        $ts_bar   = $ts_ratio > 0 ? min(100, round(($ts_ratio / max($sectorRatio * 2, 0.001)) * 100)) : 0;
      ?>
      <a class="cm-type-card" href="<?= h($selfUrl . '?type=' . urlencode($ts['type'])) ?>">
        <div class="cm-type-card__name"><?= h($ts['type']) ?></div>
        <div class="cm-type-card__stats">
          <span><?= number_format((int)$ts['providers']) ?> providers</span>
          <span><?= number_format((int)$ts['services']) ?> services</span>
          <span class="cm-type-card__ratio <?= $ts_ratio > $sectorRatio ? 'ratio--above' : 'ratio--below' ?>">
            <?= number_format($ts_ratio, 3) ?> per service
          </span>
        </div>
        <?php if ($ts_ratio > 0): ?>
        <div class="cm-mini-bar">
          <div class="cm-mini-bar__fill <?= $ts_ratio > $sectorRatio ? 'fill--above' : 'fill--below' ?>" style="width:<?= $ts_bar ?>%"></div>
        </div>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Comparison pin -->
  <div class="pa-compare" style="margin-bottom:1rem;">
    <?php if ($pinnedRow): ?>
      <div class="pa-compare__active">
        <span class="pa-compare__dot pa-compare__dot--b"></span>
        Comparing: <strong><?= h($pinnedRow['provider_name']) ?></strong>
        &nbsp;·&nbsp; <?= number_format((int)$pinnedRow['service_count']) ?> services
        &nbsp;·&nbsp; <?= (int)$pinnedRow['total_upheld'] ?> upheld
        &nbsp;·&nbsp; ratio: <strong><?= number_format((float)$pinnedRow['ratio'], 3) ?></strong>
        <?php
          $diff = (float)$pinnedRow['ratio'] - $sectorRatio;
          $diffClass = $diff > 0 ? 'diff--below' : ($diff < 0 ? 'diff--above' : 'diff--same');
          $diffLabel = $diff > 0 ? '+' . number_format($diff, 3) . ' above avg' : (abs($diff) < 0.0005 ? 'at avg' : number_format(abs($diff), 3) . ' below avg');
        ?>
        <span class="pa-q-diff <?= $diffClass ?>" style="margin-left:4px;"><?= h($diffLabel) ?></span>
        <a href="<?= h($selfUrl . '?' . http_build_query(array_filter(array_merge($urlBase, ['highlight' => ''])))) ?>"
           class="pa-compare__remove">✕ Clear</a>
      </div>
    <?php else: ?>
      <form class="pa-compare__form" method="get" action="<?= h($selfUrl) ?>">
        <?php if ($type):    ?><input type="hidden" name="type" value="<?= h($type) ?>"><?php endif; ?>
        <?php if ($min > 1): ?><input type="hidden" name="min"  value="<?= $min ?>"><?php endif; ?>
        <?php if ($sort !== 'ratio_desc'): ?><input type="hidden" name="sort" value="<?= h($sort) ?>"><?php endif; ?>
        <input type="search" name="compare_q" value="<?= h($compare_q) ?>"
               placeholder="Pin a provider for comparison — type their name…"
               class="pa-compare__input" autocomplete="off">
        <button type="submit" class="pa-compare__btn">Search</button>
      </form>
      <?php if ($compare_q !== '' && empty($compareResults)): ?>
        <p class="pa-compare__none">No providers found for "<?= h($compare_q) ?>".</p>
      <?php endif; ?>
      <?php if (!empty($compareResults)): ?>
        <div class="pa-compare__results">
          <?php foreach ($compareResults as $cr): ?>
            <a class="pa-compare__result"
               href="<?= h($selfUrl . '?' . http_build_query(array_filter(array_merge($urlBase, ['highlight' => $cr['sp_number']])))) ?>">
              <?= h($cr['pname']) ?>
              <span class="pa-compare__cnt"><?= (int)$cr['cnt'] ?> services</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Filter + sort form -->
  <form class="complaints-filters" method="get" action="<?= h($selfUrl) ?>">
    <?php if ($highlight): ?><input type="hidden" name="highlight" value="<?= h($highlight) ?>"><?php endif; ?>
    <div class="complaints-filters__row">

      <label class="complaints-filter">
        <span>Service type</span>
        <select name="type" onchange="this.form.submit()">
          <option value="">All types</option>
          <?php foreach ($typeOptions as $t): ?>
            <option value="<?= h($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="complaints-filter">
        <span>Min services</span>
        <select name="min" onchange="this.form.submit()">
          <?php foreach ([1, 2, 5, 10, 20] as $opt): ?>
            <option value="<?= $opt ?>" <?= $min === $opt ? 'selected' : '' ?>><?= $opt ?>+</option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="complaints-filter">
        <span>Sort by</span>
        <select name="sort" onchange="this.form.submit()">
          <option value="ratio_desc"    <?= $sort === 'ratio_desc'    ? 'selected' : '' ?>>Highest ratio first</option>
          <option value="ratio_asc"     <?= $sort === 'ratio_asc'     ? 'selected' : '' ?>>Lowest ratio first</option>
          <option value="total_desc"    <?= $sort === 'total_desc'    ? 'selected' : '' ?>>Most upheld complaints</option>
          <option value="services_desc" <?= $sort === 'services_desc' ? 'selected' : '' ?>>Most services</option>
          <option value="name_az"       <?= $sort === 'name_az'       ? 'selected' : '' ?>>Provider name A–Z</option>
        </select>
      </label>

      <div class="complaints-filter complaints-filter--actions">
        <a class="btn-secondary" href="<?= h($selfUrl) ?>">Reset</a>
      </div>

    </div>
  </form>

  <p class="complaints-meta">
    <strong><?= number_format($total) ?></strong> provider<?= $total !== 1 ? 's' : '' ?>
    with <?= $min ?>+ active services<?= $type ? ' · ' . h($type) : '' ?>
    · sector average: <strong><?= number_format($sectorRatio, 3) ?></strong> upheld per service
    <?php if ($type): ?> · <a href="<?= h($selfUrl) ?>">Show all types</a><?php endif; ?>
  </p>

  <!-- Provider table -->
  <?php if (!empty($rows)): ?>
  <div class="cm-table-wrap">
    <table class="cm-table">
      <thead>
        <tr>
          <th>
            <a href="<?= h($sortUrl('name_az')) ?>"
               class="th-sort <?= $sort === 'name_az' ? 'th-sort--active' : '' ?>">
              <span class="th-sort__label">Provider</span>
              <span class="th-sort__glyph" aria-hidden="true"><?= $sort === 'name_az' ? '▲' : '↕' ?></span>
            </a>
          </th>
          <?php if ($type === ''): ?><th>Types</th><?php endif; ?>
          <th class="num-col">
            <a href="<?= h($sortUrl('services_desc')) ?>"
               class="th-sort <?= $sort === 'services_desc' ? 'th-sort--active' : '' ?>">
              <span class="th-sort__label">Services</span>
              <span class="th-sort__glyph" aria-hidden="true"><?= $sort === 'services_desc' ? '▼' : '↕' ?></span>
            </a>
          </th>
          <th class="num-col">
            <a href="<?= h($sortUrl('total_desc')) ?>"
               class="th-sort <?= $sort === 'total_desc' ? 'th-sort--active' : '' ?>">
              <span class="th-sort__label">Upheld</span>
              <span class="th-sort__glyph" aria-hidden="true"><?= $sort === 'total_desc' ? '▼' : '↕' ?></span>
            </a>
          </th>
          <th>
            <a href="<?= h($sortUrl($sort === 'ratio_desc' ? 'ratio_asc' : 'ratio_desc')) ?>"
               class="th-sort <?= in_array($sort, ['ratio_desc','ratio_asc'], true) ? 'th-sort--active' : '' ?>">
              <span class="th-sort__label">Per service</span>
              <span class="th-sort__glyph" aria-hidden="true"><?= $sort === 'ratio_asc' ? '▲' : '▼' ?></span>
            </a>
          </th>
          <th>vs avg <span class="cm-avg-marker-label"><?= number_format($sectorRatio, 3) ?></span></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php
          $rank = $offset + 1;
          foreach ($rows as $r):
            $ratio    = (float) $r['ratio'];
            $isPinned = $highlight !== '' && $r['sp_number'] === $highlight;
            $diff     = $ratio - $sectorRatio;

            // Bar widths as % of scale
            $ratioBar  = $barScale > 0 ? min(100, round(($ratio / $barScale) * 100)) : 0;
            $avgBar    = $barScale > 0 ? min(100, round(($sectorRatio / $barScale) * 100)) : 0;

            $diffClass = abs($diff) < 0.0005 ? 'diff--same'
                       : ($diff > 0 ? 'diff--below' : 'diff--above'); // below = more complaints = bad = red

            $provUrl = '/provider/' . rawurlencode($r['sp_number']) . '/' . rawurlencode(slug($r['provider_name']));
        ?>
        <tr class="<?= $isPinned ? 'cm-row--pinned' : '' ?> <?= $ratio > $sectorRatio ? 'cm-row--above' : ($ratio == 0 ? 'cm-row--zero' : '') ?>">
          <td class="td-service">
            <span class="cm-rank"><?= $rank ?></span>
            <a href="<?= h($provUrl) ?>"><?= h($r['provider_name']) ?></a>
            <?php if ($isPinned): ?>
              <span class="cm-pinned-badge">pinned</span>
            <?php endif; ?>
          </td>
          <?php if ($type === ''): ?>
          <td class="cm-types"><?= h($r['service_types']) ?></td>
          <?php endif; ?>
          <td class="num-col"><?= number_format((int)$r['service_count']) ?></td>
          <td class="num-col <?= (int)$r['total_upheld'] > 0 ? 'complaints-upheld-num' : '' ?>"><?= (int)$r['total_upheld'] ?></td>
          <td class="num-col" style="font-weight:600;"><?= number_format($ratio, 3) ?></td>
          <td class="cm-bar-cell">
            <div class="cm-bar" title="<?= h($r['provider_name']) ?>: <?= number_format($ratio, 3) ?> · Avg: <?= number_format($sectorRatio, 3) ?>">
              <div class="cm-bar__fill <?= $ratio > $sectorRatio ? 'cm-bar__fill--above' : 'cm-bar__fill--below' ?>" style="width:<?= $ratioBar ?>%"></div>
              <div class="cm-bar__avg" style="left:<?= $avgBar ?>%" title="Sector avg: <?= number_format($sectorRatio, 3) ?>"></div>
            </div>
            <?php if (abs($diff) >= 0.0005): ?>
              <span class="cm-diff <?= $diffClass ?>">
                <?= $diff > 0 ? '+' : '' ?><?= number_format($diff, 3) ?>
              </span>
            <?php else: ?>
              <span class="cm-diff diff--same">avg</span>
            <?php endif; ?>
          </td>
          <td class="td-action">
            <a href="<?= h('/complaints?sp=' . rawurlencode($r['sp_number'])) ?>" class="btn-view">Complaints →</a>
          </td>
        </tr>
        <?php $rank++; endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1):
    $hrefFor = function(int $p) use ($urlBase, $selfUrl): string {
        $q = $urlBase;
        if ($p > 1) $q['page'] = $p;
        return $selfUrl . '?' . http_build_query($q);
    };
    $nums  = [1, $pages];
    $win   = 2; $start = max(2, $page - $win); $end = min($pages - 1, $page + $win);
    for ($i = $start; $i <= $end; $i++) $nums[] = $i;
    $nums = array_values(array_unique($nums)); sort($nums, SORT_NUMERIC);
  ?>
  <nav class="pagination" aria-label="Pages">
    <span class="pagination__meta">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page > 1): ?>
      <a class="pagination__step" rel="prev" href="<?= h($hrefFor($page - 1)) ?>">←</a>
    <?php endif; ?>
    <?php $prev = 0; foreach ($nums as $p): ?>
      <?php if ($prev > 0 && $p > $prev + 1): ?><span class="pagination__ellipsis" aria-hidden="true">…</span><?php endif; ?>
      <?php if ($p === $page): ?>
        <span class="active" aria-current="page"><?= $p ?></span>
      <?php else: ?>
        <a href="<?= h($hrefFor($p)) ?>"><?= $p ?></a>
      <?php endif; ?>
      <?php $prev = $p; endforeach; ?>
    <?php if ($page < $pages): ?>
      <a class="pagination__step" rel="next" href="<?= h($hrefFor($page + 1)) ?>">→</a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>

  <?php else: ?>
  <p class="grade-date-note" style="margin-top:1rem;">No providers match these filters.</p>
  <?php endif; ?>

  <p class="complaints-source-note">
    Upheld complaint counts from the Care Inspectorate CSV (years 2023–24, 2024–25, 2025–26 combined).
    Ratio = upheld complaints ÷ active services. Only providers with <?= $min ?>+ active services shown.
  </p>

</div>

<footer class="site-footer">
  <div class="container">
    <p>Data from the <a href="https://www.careinspectorate.scot">Care Inspectorate</a> (Open Government Licence).</p>
    <p class="site-footer__legal"><a href="/terms">Terms</a> · <a href="/privacy">Privacy</a></p>
    <p class="site-footer__admin"><a href="/admin/imports.php">Admin</a></p>
  </div>
</footer>

<script src="<?= asset_url('/assets/js/cookie-banner.js') ?>" defer></script>
</body>
</html>
