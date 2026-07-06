<?php
// =============================================================
// public/complaints.php — Directory-wide complaints hub
// URL: /complaints
// =============================================================
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';

$pdo = db();

// ── Filters ──────────────────────────────────────────────────
$params = [];
$q       = trim($_GET['q']       ?? '');
$council = trim($_GET['council'] ?? '');
$type    = trim($_GET['type']    ?? '');
$sort    = trim($_GET['sort']    ?? 'most');
$status  = trim($_GET['status']  ?? 'active');
$sp      = trim($_GET['sp']      ?? '');
$page    = max(1, (int) ($_GET['page'] ?? 1));

if ($q)       $params['q']       = $q;
if ($council) $params['council'] = $council;
if ($type)    $params['type']    = $type;
if ($sort && $sort !== 'most') $params['sort'] = $sort;
if ($status)  $params['status']  = $status;
if ($sp)      $params['sp']      = $sp;

$validSorts = ['most', 'upheld', 'name_az', 'council'];
if (!in_array($sort, $validSorts, true)) $sort = 'most';
$params['sort']   = $sort;
$params['status'] = $status;

// ── Filter dropdown options ────────────────────────────────
$councilOptions = $pdo->query(
    "SELECT DISTINCT council_area FROM services
     WHERE council_area IS NOT NULL AND council_area != ''
       AND (complaints_upheld > 0 OR complaints_not_upheld > 0)
     ORDER BY council_area"
)->fetchAll(PDO::FETCH_COLUMN);

$typeOptions = $pdo->query(
    "SELECT DISTINCT care_service FROM services
     WHERE care_service IS NOT NULL AND care_service != ''
       AND (complaints_upheld > 0 OR complaints_not_upheld > 0)
     ORDER BY care_service"
)->fetchAll(PDO::FETCH_COLUMN);

// If arriving from a provider page (?sp=…), resolve the name for display
$spName = '';
if ($sp !== '') {
    $stmt = $pdo->prepare("SELECT MAX(provider_name) FROM services WHERE sp_number = ?");
    $stmt->execute([$sp]);
    $spName = (string) ($stmt->fetchColumn() ?: $sp);
}

// ── Paginated results ─────────────────────────────────────
$result = search_complaints($params, $page, 50);
$rows   = $result['rows'];
$total  = $result['total'];
$pages  = $result['pages'];
$page   = $result['page'];

// ── Stats — mirror the same filters as the table ──────────
$statsWhere  = ["s.complaints_upheld > 0", "s.public_list = 1"];
$statsBinds  = [];
if ($council) { $statsWhere[] = 's.council_area = :council'; $statsBinds[':council'] = $council; }
if ($type)    { $statsWhere[] = 's.care_service = :type';    $statsBinds[':type']    = $type;    }
if ($q)       { $statsWhere[] = '(s.service_name LIKE :q OR s.provider_name LIKE :q2)';
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $statsBinds[':q'] = $like; $statsBinds[':q2'] = $like; }
if ($sp)      { $statsWhere[] = 's.sp_number = :sp';         $statsBinds[':sp']      = $sp;      }
if ($status === 'active') { $statsWhere[] = "s.service_status = 'Active'"; }

$statsStmt = $pdo->prepare("
    SELECT SUM(s.complaints_upheld)  AS total_upheld,
           COUNT(*)                  AS services_with_complaints
    FROM   services s
    WHERE  " . implode(' AND ', $statsWhere));
$statsStmt->execute($statsBinds);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ── Pagination URL helper ─────────────────────────────────
function complaints_url(array $base, int $p): string {
    $q = array_merge($base, $p > 1 ? ['page' => $p] : []);
    unset($q['page']);
    if ($p > 1) $q['page'] = $p;
    return '/complaints?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== 'most' && $v !== 'active' || in_array($v, ['most','active'], true) && false ?: $v !== ''));
}

$urlBase = array_filter([
    'q'       => $q,
    'council' => $council,
    'type'    => $type,
    'sort'    => $sort !== 'most' ? $sort : '',
    'status'  => $status !== 'active' ? $status : '',
    'sp'      => $sp,
], fn($v) => $v !== '');

$title = 'Complaints — CareScotland';
if ($spName) $title = h($spName) . ' complaints — CareScotland';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?></title>
<meta name="description" content="Browse, search and filter Care Inspectorate complaints across all Scottish care services. See which services have the most upheld complaints.">
<link rel="stylesheet" href="/assets/style.css">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
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
    <a href="/">Home</a> ›
    <?php if ($spName): ?>
      <a href="/complaints">Complaints</a> › <?= h($spName) ?>
    <?php else: ?>
      Complaints
    <?php endif; ?>
  </nav>

  <div class="complaints-hub-header">
    <div>
      <h1>Complaints<?= $spName ? ' — ' . h($spName) : '' ?></h1>
      <p class="complaints-hub-sub">Complaint outcome counts from the Care Inspectorate CSV. For case-by-case detail (dates, categories) view each service profile.
        <?php if (!$spName): ?>&nbsp;·&nbsp;<a href="/complaints-metrics">Provider benchmarking →</a><?php endif; ?>
      </p>
    </div>
  </div>

  <!-- Stats — reflect current filters -->
  <?php $hasFilter = $sp || $council || $type || $q || $status !== 'active'; ?>
  <div class="complaints-stats">
    <div class="complaints-stat">
      <span class="complaints-stat__val"><?= number_format((int)($stats['services_with_complaints'] ?? 0)) ?></span>
      <span class="complaints-stat__label">Services with upheld complaints<?= $hasFilter ? ' (filtered)' : '' ?></span>
    </div>
    <div class="complaints-stat complaints-stat--upheld">
      <span class="complaints-stat__val"><?= number_format((int)($stats['total_upheld'] ?? 0)) ?></span>
      <span class="complaints-stat__label">Total upheld — all years<?= $hasFilter ? ' (filtered)' : '' ?></span>
    </div>
  </div>

  <!-- Filter form -->
  <form class="complaints-filters" method="get" action="/complaints">
    <?php if ($sp): ?><input type="hidden" name="sp" value="<?= h($sp) ?>"><?php endif; ?>
    <div class="complaints-filters__row">

      <label class="complaints-filter">
        <span>Search</span>
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Service or provider name…" maxlength="120">
      </label>

      <label class="complaints-filter">
        <span>Council</span>
        <select name="council">
          <option value="">Any council</option>
          <?php foreach ($councilOptions as $c): ?>
            <option value="<?= h($c) ?>" <?= $council === $c ? 'selected' : '' ?>><?= h($c) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="complaints-filter">
        <span>Service type</span>
        <select name="type">
          <option value="">Any type</option>
          <?php foreach ($typeOptions as $t): ?>
            <option value="<?= h($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="complaints-filter">
        <span>Status</span>
        <select name="status">
          <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active services</option>
          <option value="all"    <?= $status === 'all'    ? 'selected' : '' ?>>All statuses</option>
        </select>
      </label>

      <label class="complaints-filter">
        <span>Sort by</span>
        <select name="sort">
          <option value="most"    <?= $sort === 'most'    ? 'selected' : '' ?>>Most upheld</option>
          <option value="name_az" <?= $sort === 'name_az' ? 'selected' : '' ?>>Service name A–Z</option>
          <option value="council" <?= $sort === 'council' ? 'selected' : '' ?>>Council A–Z</option>
        </select>
      </label>

      <div class="complaints-filter complaints-filter--actions">
        <button type="submit" class="btn-primary">Apply</button>
        <a class="btn-secondary" href="/complaints<?= $sp ? '?sp=' . h(rawurlencode($sp)) : '' ?>">Clear</a>
      </div>

    </div>
  </form>

  <!-- Results meta -->
  <p class="complaints-meta">
    <?php if ($total === 0): ?>
      No services match these filters.
    <?php else: ?>
      <strong><?= number_format($total) ?></strong> service<?= $total !== 1 ? 's' : '' ?> with complaints<?php if ($q || $council || $type): ?> <span class="filter-active">· filters on</span><?php endif; ?>
    <?php endif; ?>
  </p>

  <!-- Results table -->
  <?php if (!empty($rows)): ?>
  <div class="complaints-table-wrap">
    <table class="complaints-table">
      <thead>
        <tr>
          <th>Service</th>
          <th>Provider</th>
          <th>Type</th>
          <th>Council</th>
          <th class="num-col">
            <a href="/complaints?<?= http_build_query(array_merge($urlBase, ['sort' => 'most'])) ?>"
               class="th-sort <?= $sort === 'most' ? 'th-sort--active' : '' ?>">
              <span class="th-sort__label">Upheld</span>
              <span class="th-sort__glyph" aria-hidden="true"><?= $sort === 'most' ? '▼' : '↕' ?></span>
            </a>
          </th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $svcUrl = '/service/' . rawurlencode($r['cs_number']) . '/' . rawurlencode(slug($r['service_name']));
          $provUrl = !empty($r['sp_number'])
            ? '/provider/' . rawurlencode($r['sp_number']) . '/' . rawurlencode(slug($r['provider_name'] ?? ''))
            : '';
        ?>
        <tr>
          <td class="td-service">
            <a href="<?= h($svcUrl . '#complaints') ?>"><?= h($r['service_name']) ?></a>
            <?php if ($r['town']): ?>
              <span class="td-sub"><?= h($r['town']) ?></span>
            <?php endif; ?>
          </td>
          <td class="td-provider">
            <?php if ($provUrl): ?>
              <a href="<?= h($provUrl) ?>"><?= h($r['provider_name'] ?? '') ?></a>
            <?php else: ?>
              <?= h($r['provider_name'] ?? '—') ?>
            <?php endif; ?>
          </td>
          <td class="td-type"><?= h($r['care_service'] ?? '—') ?></td>
          <td class="td-council">
            <?php if ($r['council_area']): ?>
              <a href="/complaints?council=<?= urlencode($r['council_area']) ?><?= $type ? '&type=' . urlencode($type) : '' ?>"><?= h($r['council_area']) ?></a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="num-col complaints-upheld-num"><?= (int)$r['complaints_upheld'] ?></td>
          <td class="td-action">
            <a href="<?= h($svcUrl . '#complaints') ?>" class="btn-view">Details →</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1):
    $paginateBase = $urlBase;

    $hrefFor = function(int $p) use ($paginateBase): string {
        $q = $paginateBase;
        if ($p > 1) $q['page'] = $p;
        return '/complaints?' . http_build_query($q);
    };

    $nums  = [1, $pages];
    $win   = 2;
    $start = max(2, $page - $win);
    $end   = min($pages - 1, $page + $win);
    for ($i = $start; $i <= $end; $i++) $nums[] = $i;
    $nums = array_values(array_unique($nums));
    sort($nums, SORT_NUMERIC);
  ?>
  <nav class="pagination" aria-label="Pages">
    <span class="pagination__meta">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page > 1): ?>
      <a class="pagination__step" rel="prev" href="<?= h($hrefFor($page - 1)) ?>">←</a>
    <?php endif; ?>
    <?php $prev = 0; foreach ($nums as $p): ?>
      <?php if ($prev > 0 && $p > $prev + 1): ?>
        <span class="pagination__ellipsis" aria-hidden="true">…</span>
      <?php endif; ?>
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
    <p class="grade-date-note" style="margin-top:1rem;">
      No complaints found matching these filters. <a href="/complaints">Clear all filters</a>.
    </p>
  <?php endif; ?>

  <p class="complaints-source-note">
    Complaint outcome counts (upheld / not upheld) are sourced from the Care Inspectorate CSV datastore and refreshed monthly.
    Detailed case histories (dates, case numbers, categories) are loaded from the Care Inspectorate website when individual service profiles are viewed.
    <a href="https://www.careinspectorate.com" rel="noopener" target="_blank">Care Inspectorate ↗</a>
  </p>

</div>

<footer class="site-footer">
  <div class="container">
    <p>Data from the <a href="https://www.careinspectorate.com">Care Inspectorate</a> (Open Government Licence).</p>
    <p class="site-footer__legal"><a href="/terms">Terms</a> · <a href="/privacy">Privacy</a></p>
    <p class="site-footer__admin"><a href="/admin/imports.php">Admin</a></p>
  </div>
</footer>

<script src="/assets/js/cookie-banner.js" defer></script>
</body>
</html>
