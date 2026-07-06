<?php
declare(strict_types=1);
/**
 * Benchmark charts: national, council, service type, provider (Care Inspectorate open data aggregates).
 */
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';
require_once ROOT . '/includes/insights_data.php';

function insights_truncate(string $s, int $max = 40): string
{
    $s = trim($s);
    if (mb_strlen($s) <= $max) {
        return $s;
    }

    return mb_substr($s, 0, $max - 1) . '…';
}

$pdo = db();
$councils = $pdo->query(
    "SELECT DISTINCT council_area FROM services WHERE council_area IS NOT NULL AND TRIM(council_area) != '' ORDER BY council_area"
)->fetchAll(PDO::FETCH_COLUMN);
$types = $pdo->query(
    "SELECT DISTINCT care_service FROM services WHERE care_service IS NOT NULL AND TRIM(care_service) != '' ORDER BY care_service"
)->fetchAll(PDO::FETCH_COLUMN);

$scope = trim((string) ($_GET['scope'] ?? 'national'));
if (!in_array($scope, ['national', 'council', 'type', 'provider'], true)) {
    $scope = 'national';
}

$council = trim((string) ($_GET['council'] ?? ''));
if ($council !== '' && !in_array($council, $councils, true)) {
    $council = '';
}

$type = trim((string) ($_GET['type'] ?? ''));
if ($type !== '' && !in_array($type, $types, true)) {
    $type = '';
}

$sp = trim((string) ($_GET['sp'] ?? ''));
if ($sp !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $sp)) {
    $sp = '';
}

$providerPick = insights_provider_picklist($pdo, 120);
$validSp = [];
foreach ($providerPick as $p) {
    $validSp[(string) ($p['sp'] ?? '')] = true;
}
if ($sp !== '' && !isset($validSp[$sp])) {
    $chk = $pdo->prepare(
        "SELECT COUNT(*) FROM services WHERE service_status = 'Active' AND public_list = 1 AND sp_number = ?"
    );
    $chk->execute([$sp]);
    if ((int) $chk->fetchColumn() < 1) {
        $sp = '';
    }
}

$chartList = [];
$tables = [];
$providerBundle = null;
$headline = 'Scotland overview';
$lead = 'Counts and grade patterns from active, public-listed services in this directory. Use the scopes below for like-for-like context.';

$green = 'rgba(15, 110, 86, 0.75)';
$greenLight = 'rgba(15, 110, 86, 0.35)';
$gradeColors = ['#8b1538', '#b83232', '#c97a2d', '#c9a227', '#6a9c3e', '#0f6e56'];

if ($scope === 'national') {
    $headline = 'Scotland — all services';
    $byType = insights_services_by_type($pdo, null, 14);
    $grades = insights_grade_min_buckets($pdo, null, null);
    $labels = array_map(static fn (array $r): string => insights_truncate($r['type'], 36), $byType);
    $chartList[] = [
        'title' => 'Largest service types (Scotland)',
        'canvasId' => 'ins-national-types',
        'type' => 'bar',
        'data' => [
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Services',
                'data' => array_column($byType, 'count'),
                'backgroundColor' => $green,
            ]],
        ],
        'options' => [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['beginAtZero' => true]],
        ],
    ];
    $chartList[] = [
        'title' => 'Inspection grade distribution',
        'canvasId' => 'ins-national-grades',
        'type' => 'doughnut',
        'data' => [
            'labels' => array_map(static fn (array $r): string => 'Grade ' . $r['grade'], $grades),
            'datasets' => [[
                'data' => array_column($grades, 'count'),
                'backgroundColor' => $gradeColors,
            ]],
        ],
        'options' => ['plugins' => ['legend' => ['position' => 'right']]],
    ];
} elseif ($scope === 'council' && $council !== '') {
    $headline = 'Council: ' . $council;
    $lead = 'Service type mix and grade distribution in this council, compared to Scotland.';
    $byType = insights_services_by_type($pdo, $council, 14);
    $grades = insights_grade_min_buckets($pdo, $council, null);
    $labels = array_map(static fn (array $r): string => insights_truncate($r['type'], 36), $byType);
    $chartList[] = [
        'title' => 'Largest types in this council',
        'canvasId' => 'ins-council-types',
        'type' => 'bar',
        'data' => [
            'labels' => $labels,
            'datasets' => [[
                'label' => 'In this council',
                'data' => array_column($byType, 'count'),
                'backgroundColor' => $green,
            ]],
        ],
        'options' => [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['beginAtZero' => true]],
        ],
    ];
    $chartList[] = [
        'title' => 'Inspection grade distribution — this council',
        'canvasId' => 'ins-council-grades',
        'type' => 'doughnut',
        'data' => [
            'labels' => array_map(static fn (array $r): string => 'Grade ' . $r['grade'], $grades),
            'datasets' => [[
                'data' => array_column($grades, 'count'),
                'backgroundColor' => $gradeColors,
            ]],
        ],
        'options' => ['plugins' => ['legend' => ['position' => 'right']]],
    ];
    $tables['council_vs_nat'] = insights_council_vs_national_by_type($pdo, $council);
} elseif ($scope === 'type' && $type !== '') {
    $headline = 'Service type: ' . $type;
    $lead = 'Where this type is most common, and how inspection grades are distributed nationally for this type.';
    $grades = insights_grade_min_buckets($pdo, null, $type);
    $topC = insights_type_top_councils($pdo, $type, 16);
    $clabels = array_map(static fn (array $r): string => insights_truncate($r['council'], 28), $topC);
    $chartList[] = [
        'title' => 'Inspection grade distribution — Scotland',
        'canvasId' => 'ins-type-grades',
        'type' => 'doughnut',
        'data' => [
            'labels' => array_map(static fn (array $r): string => 'Grade ' . $r['grade'], $grades),
            'datasets' => [[
                'data' => array_column($grades, 'count'),
                'backgroundColor' => $gradeColors,
            ]],
        ],
        'options' => ['plugins' => ['legend' => ['position' => 'right']]],
    ];
    if ($topC !== []) {
        $chartList[] = [
            'title' => 'Councils with most of this type',
            'canvasId' => 'ins-type-councils',
            'type' => 'bar',
            'data' => [
                'labels' => $clabels,
                'datasets' => [[
                    'label' => 'Services in council',
                    'data' => array_column($topC, 'count'),
                    'backgroundColor' => $greenLight,
                ]],
            ],
            'options' => [
                'indexAxis' => 'y',
                'plugins' => ['legend' => ['display' => false]],
                'scales' => ['x' => ['beginAtZero' => true]],
            ],
        ];
    }
} elseif ($scope === 'provider' && $sp !== '') {
    $bundle = insights_provider_bundle($pdo, $sp);
    if ($bundle) {
        $providerBundle = $bundle;
        $headline = 'Provider: ' . ($bundle['provider_name'] ?: $sp);
        $lead = $bundle['n_services'] . ' active listings across ' . $bundle['n_councils'] . ' council area(s). Bars show volume by type; the table compares average grades to Scotland.';
        $bt = $bundle['by_type'];
        $labels = array_map(static fn (array $r): string => insights_truncate($r['type'], 34), $bt);
        $chartList[] = [
            'title' => 'This provider’s services by type',
            'canvasId' => 'ins-provider-types',
            'type' => 'bar',
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Services',
                    'data' => array_column($bt, 'count'),
                    'backgroundColor' => $green,
                ]],
            ],
            'options' => [
                'indexAxis' => 'y',
                'plugins' => ['legend' => ['display' => false]],
                'scales' => ['x' => ['beginAtZero' => true]],
            ],
        ];
        $tables['provider_vs_nat'] = $bt;
    } else {
        $headline = 'Provider not found';
        $lead = 'No active public listings for that provider reference.';
    }
} else {
    if ($scope === 'council' && $council === '') {
        $headline = 'Choose a council';
        $lead = 'Pick a local authority to see type mix and grade patterns for that area.';
    } elseif ($scope === 'type' && $type === '') {
        $headline = 'Choose a service type';
        $lead = 'Pick a Care Inspectorate service type to see council concentration and grade spread.';
    } elseif ($scope === 'provider' && $sp === '') {
        $headline = 'Choose a provider';
        $lead = 'Pick a provider (by SP reference) to see their portfolio vs national averages by type.';
    }
}

$chartJson = json_encode([
    'charts' => array_map(static function (array $c): array {
        return [
            'canvasId' => $c['canvasId'],
            'type' => $c['type'],
            'data' => $c['data'],
            'options' => $c['options'] ?? [],
        ];
    }, $chartList),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
$title = 'Insights & comparisons | CareScotland';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<meta name="description" content="Charts comparing care services in Scotland by council, service type, and provider (Care Inspectorate open data).">
<link rel="stylesheet" href="<?= asset_url('/assets/style.css') ?>">
<link rel="icon" type="image/svg+xml" href="<?= asset_url('/assets/favicon.svg') ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<script src="<?= asset_url('/assets/js/insights-page.js') ?>" defer></script>
</head>
<body class="insights-page">

<header class="site-header">
  <div class="container">
    <a href="/" class="logo"><span class="logo-icon"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 2.5 4.5 5v5.2c0 5.4 3.3 9.9 7.5 11.3 4.2-1.4 7.5-5.9 7.5-11.3V5L12 2.5Z" fill="currentColor"/><path d="M8.3 12.1l2.6 2.6 4.8-5.4" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg></span> CareScotland</a>
    <div class="nav-disclosure">
      <input type="checkbox" id="nav-toggle" class="nav-toggle-input">
      <label for="nav-toggle" class="nav-toggle" aria-label="Menu">☰</label>
      <nav class="site-header__nav">
      <a href="/">Directory</a>
      <a href="/insights" aria-current="page">Insights</a>
      <a href="/councils">Council map</a>
      <a href="/news">News</a>
      <a href="/provider/claim.php">Are you a provider?</a>
    </nav>
    </div>
  </div>
</header>

<main class="container insights-main">
  <nav class="breadcrumb">
    <a href="/">Home</a> › <span>Insights</span>
  </nav>

  <header class="insights-header">
    <h1><?= h($headline) ?></h1>
    <p class="insights-lead"><?= h($lead) ?></p>
  </header>

  <section class="insights-scopes" aria-labelledby="insights-scope-h">
    <h2 id="insights-scope-h" class="insights-subh">Scope</h2>
    <div class="insights-scope-grid">
      <a class="insights-scope-card <?= $scope === 'national' ? 'is-active' : '' ?>" href="/insights?scope=national">Scotland (all)</a>
      <form class="insights-scope-form" method="get" action="/insights">
        <input type="hidden" name="scope" value="council">
        <label>Council area
          <select name="council" onchange="this.form.submit()">
            <option value="">— Select —</option>
            <?php foreach ($councils as $c): ?>
              <option value="<?= h((string) $c) ?>" <?= $council === $c ? 'selected' : '' ?>><?= h((string) $c) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
      <form class="insights-scope-form" method="get" action="/insights">
        <input type="hidden" name="scope" value="type">
        <label>Service type
          <select name="type" onchange="this.form.submit()">
            <option value="">— Select —</option>
            <?php foreach ($types as $t): ?>
              <option value="<?= h((string) $t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= h(insights_truncate((string) $t, 56)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
      <form class="insights-scope-form" method="get" action="/insights">
        <input type="hidden" name="scope" value="provider">
        <label>Provider (SP)
          <select name="sp" onchange="this.form.submit()">
            <option value="">— Select —</option>
            <?php foreach ($providerPick as $p): ?>
              <option value="<?= h((string) $p['sp']) ?>" <?= $sp === (string) $p['sp'] ? 'selected' : '' ?>><?= h(insights_truncate((string) $p['name'], 36)) ?> (<?= (int) $p['count'] ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
    </div>
    <p class="insights-scope-note">Figures cover active, public-listed services. <strong>Lowest grade</strong> is the weakest individual pillar score from the Care Inspectorate (1–6, higher is better). Not financial or occupancy benchmarking.</p>
  </section>

  <?php if ($chartList !== []): ?>
  <div class="insights-chart-grid">
    <?php foreach ($chartList as $ch): ?>
      <figure class="insights-chart-wrap">
        <figcaption class="insights-chart-cap"><?= h((string) ($ch['title'] ?? 'Chart')) ?></figcaption>
        <div class="insights-chart-canvas">
          <canvas id="<?= h((string) $ch['canvasId']) ?>" aria-label="Chart"></canvas>
        </div>
      </figure>
    <?php endforeach; ?>
  </div>
  <script type="application/json" id="insights-chart-payload"><?= $chartJson ?></script>
  <?php endif; ?>

  <?php if (!empty($tables['council_vs_nat'])): ?>
  <section class="insights-table-section" aria-labelledby="insights-t1">
    <h2 id="insights-t1" class="insights-subh">Council vs Scotland — average grade by service type</h2>
    <div class="insights-table-scroll">
      <table class="insights-table">
        <thead>
          <tr>
            <th scope="col">Type</th>
            <th scope="col">In council</th>
            <th scope="col">Council avg</th>
            <th scope="col">Scotland avg</th>
            <th scope="col">Scotland N</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tables['council_vs_nat'] as $row): ?>
            <tr>
              <td><?= h(insights_truncate($row['type'], 48)) ?></td>
              <td><?= (int) $row['council_count'] ?></td>
              <td><?= $row['council_avg'] !== null ? h((string) $row['council_avg']) : '—' ?></td>
              <td><?= $row['national_avg'] !== null ? h((string) $row['national_avg']) : '—' ?></td>
              <td><?= (int) $row['national_count'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php $firstCouncilType = $tables['council_vs_nat'][0]['type'] ?? ''; ?>
    <p class="insights-table-foot">
      <?php if ($firstCouncilType !== ''): ?>
        Deep dive: <a href="/insights?scope=type&amp;type=<?= h(rawurlencode($firstCouncilType)) ?>"><?= h(insights_truncate($firstCouncilType, 44)) ?></a>
      <?php endif; ?>
      <?php if ($council !== ''): ?>
        <?php if ($firstCouncilType !== ''): ?> · <?php endif; ?>
        <a href="/?council=<?= h(rawurlencode($council)) ?>#directory">Open directory in this council</a>
      <?php endif; ?>
    </p>
  </section>
  <?php endif; ?>

  <?php if (!empty($tables['provider_vs_nat'])): ?>
  <section class="insights-table-section" aria-labelledby="insights-t2">
    <h2 id="insights-t2" class="insights-subh">This provider vs Scotland — average grade by type</h2>
    <div class="insights-table-scroll">
      <table class="insights-table">
        <thead>
          <tr>
            <th scope="col">Type</th>
            <th scope="col">Their services</th>
            <th scope="col">Provider avg</th>
            <th scope="col">Scotland avg</th>
            <th scope="col">Scotland N</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tables['provider_vs_nat'] as $row): ?>
            <tr>
              <td><a href="/insights?scope=type&amp;type=<?= h(rawurlencode($row['type'])) ?>"><?= h(insights_truncate($row['type'], 44)) ?></a></td>
              <td><?= (int) $row['count'] ?></td>
              <td><?= $row['provider_avg'] !== null ? h((string) $row['provider_avg']) : '—' ?></td>
              <td><?= $row['national_avg'] !== null ? h((string) $row['national_avg']) : '—' ?></td>
              <td><?= (int) $row['national_count'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="insights-table-foot">
      <?php if ($providerBundle): ?>
        <a href="/provider/<?= h(rawurlencode($sp)) ?>/<?= h(slug((string) ($providerBundle['provider_name'] ?? 'provider'))) ?>">Provider overview</a> ·
      <?php endif; ?>
      <a href="/?sp=<?= h(rawurlencode($sp)) ?>#directory">Same provider in directory</a>
    </p>
  </section>
  <?php endif; ?>

</main>

<footer class="site-footer site-footer--compact">
  <div class="container">
    <p>Care Inspectorate open data (OGL). Charts are indicative; confirm grades on inspection reports.</p>
    <p class="site-footer__legal"><a href="/terms">Terms</a> · <a href="/privacy">Privacy</a></p>
    <p class="site-footer__admin"><a href="/admin/imports.php">Admin</a></p>
  </div>
</footer>

<script src="<?= asset_url('/assets/js/cookie-banner.js') ?>" defer></script>
</body>
</html>
