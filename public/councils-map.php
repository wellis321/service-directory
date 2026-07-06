<?php
declare(strict_types=1);
/**
 * Full-page Scotland council picker (Leaflet). URL: /councils?… (directory filters preserved)
 */
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';
require_once ROOT . '/includes/council_map.php';

$pdo = db();
$query = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'type' => trim((string) ($_GET['type'] ?? '')),
    'council' => trim((string) ($_GET['council'] ?? '')),
    'min_grade' => (int) ($_GET['min_grade'] ?? 0),
    'min_avg' => trim((string) ($_GET['min_avg'] ?? '')),
    'sp' => trim((string) ($_GET['sp'] ?? '')),
    'sort' => trim((string) ($_GET['sort'] ?? 'default')),
    'graded_within' => (int) ($_GET['graded_within'] ?? 0),
];
$cm = council_map_prepare($pdo, $query);
if (!$cm['has_markers']) {
    http_response_code(404);
    exit('Council map is not available.');
}

$directoryHref = (directory_params_for_url($query) === [] ? '/' : '/?' . http_build_query(directory_params_for_url($query))) . '#directory';

$title = 'Council map — pick an area | CareScotland';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<meta name="description" content="Interactive map of Scottish council areas. Choose an area to open matching care services in the CareScotland directory.">
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.css" crossorigin>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
</head>
<body class="council-map-page">

<header class="site-header">
  <div class="container">
    <a href="/" class="logo">
      <span class="logo-icon"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 2.5 4.5 5v5.2c0 5.4 3.3 9.9 7.5 11.3 4.2-1.4 7.5-5.9 7.5-11.3V5L12 2.5Z" fill="currentColor"/><path d="M8.3 12.1l2.6 2.6 4.8-5.4" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg></span>
      CareScotland
    </a>
    <div class="nav-disclosure">
      <input type="checkbox" id="nav-toggle" class="nav-toggle-input">
      <label for="nav-toggle" class="nav-toggle" aria-label="Menu">☰</label>
      <nav class="site-header__nav">
      <a href="/">Directory</a>
      <a href="/insights">Insights</a>
      <a href="/councils" aria-current="page">Council map</a>
      <a href="/news">News</a>
      <a href="/provider/claim.php">Are you a provider?</a>
    </nav>
    </div>
  </div>
</header>

<main class="council-map-page-main container">
  <nav class="breadcrumb">
    <a href="/">Home</a> › <span>Council map</span>
  </nav>

  <header class="council-map-page-header">
    <h1>Council map</h1>
    <p class="council-map-page-lead">Click a marker to open the directory filtered to that council. The map stays at a fixed view of Scotland so it does not zoom or jump when you move between areas.</p>
    <p class="council-map-page-actions">
      <a class="btn-primary" href="<?= h($directoryHref) ?>">View matching services as a list →</a>
      <a class="btn-secondary" href="/">Return to directory</a>
    </p>
  </header>

  <div id="council-map" class="council-map council-map--page" aria-label="Scotland council areas; click a marker to filter the directory"></div>
  <script type="application/json" id="council-map-config"><?= $cm['json'] ?></script>
</main>

<footer class="site-footer site-footer--compact">
  <div class="container">
    <p>Map © <a href="https://www.openstreetmap.org/copyright" rel="noopener">OpenStreetMap</a>. Service counts from CareScotland listings (active, public).</p>
    <p class="site-footer__legal"><a href="/terms">Terms</a> · <a href="/privacy">Privacy</a></p>
    <p class="site-footer__admin"><a href="/admin/imports.php">Admin</a></p>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.js" crossorigin></script>
<script src="/assets/js/council-map.js" defer></script>
<script src="/assets/js/cookie-banner.js" defer></script>
</body>
</html>
