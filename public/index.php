<?php
// =============================================================
// public/index.php — Homepage + directory search
// =============================================================
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';
require ROOT . '/includes/insights_data.php';

$pdo = db();

$service_types = $pdo->query("SELECT DISTINCT care_service FROM services WHERE care_service IS NOT NULL ORDER BY care_service")->fetchAll(PDO::FETCH_COLUMN);
$councils      = $pdo->query("SELECT DISTINCT council_area FROM services WHERE council_area IS NOT NULL ORDER BY council_area")->fetchAll(PDO::FETCH_COLUMN);

$type = trim((string) ($_GET['type'] ?? ''));
if ($type !== '' && !in_array($type, $service_types, true)) $type = '';

$council = trim((string) ($_GET['council'] ?? ''));
if ($council !== '' && !in_array($council, $councils, true)) $council = '';

$providerOptions = $pdo->query(
    "SELECT sp_number AS sp, MAX(provider_name) AS provider_name
     FROM services
     WHERE service_status = 'Active' AND public_list = 1
       AND sp_number IS NOT NULL AND TRIM(sp_number) != ''
       AND provider_name IS NOT NULL AND TRIM(provider_name) != ''
     GROUP BY sp_number ORDER BY provider_name"
)->fetchAll(PDO::FETCH_ASSOC);
$validSps = [];
foreach ($providerOptions as $row) $validSps[(string)($row['sp'] ?? '')] = true;

$sp = trim((string) ($_GET['sp'] ?? ''));
if ($sp === '' || !isset($validSps[$sp])) $sp = '';

$sort = trim((string) ($_GET['sort'] ?? 'default'));
$valid_sorts = ['default','grades_recent','avg_high','avg_low','name_az','name_za','council_az','type_az','beds_desc','inspected_asc'];
if (!in_array($sort, $valid_sorts, true)) $sort = 'default';

$minAvg = trim((string) ($_GET['min_avg'] ?? ''));
if (!in_array($minAvg, ['', '3.5', '4', '4.5', '5'], true)) $minAvg = '';

$gradedWithin = (int) ($_GET['graded_within'] ?? 0);
if (!in_array($gradedWithin, [0, 90, 180, 365], true)) $gradedWithin = 0;

$minGrade = (int) ($_GET['min_grade'] ?? 0);
if (!in_array($minGrade, [0, 3, 4, 5], true)) $minGrade = 0;

$params = [
    'q'             => trim((string) ($_GET['q'] ?? '')),
    'type'          => $type,
    'council'       => $council,
    'min_grade'     => $minGrade,
    'min_avg'       => $minAvg,
    'sp'            => $sp,
    'sort'          => $sort,
    'graded_within' => $gradedWithin,
];
$page   = max(1, (int) ($_GET['page'] ?? 1));
$result = search_services($params, $page);

// ── Homepage metrics ──────────────────────────────────────────
$total_active    = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE service_status='Active'")->fetchColumn();
$total_providers = (int) $pdo->query("SELECT COUNT(DISTINCT sp_number) FROM services WHERE service_status='Active' AND sp_number IS NOT NULL")->fetchColumn();
$total_councils  = (int) $pdo->query("SELECT COUNT(DISTINCT council_area) FROM services WHERE service_status='Active' AND council_area IS NOT NULL")->fetchColumn();
$total_graded    = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE service_status='Active' AND grade_min IS NOT NULL")->fetchColumn();
$count_good      = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE service_status='Active' AND grade_min >= 4")->fetchColumn();
$pct_good        = $total_graded > 0 ? round(100 * $count_good / $total_graded) : null;

$lastUpdated      = $pdo->query("SELECT MAX(ci_last_updated) FROM services WHERE ci_last_updated IS NOT NULL")->fetchColumn();
$lastUpdatedLabel = $lastUpdated ? date('F Y', strtotime((string)$lastUpdated)) : null;

$gradeBuckets = insights_grade_min_buckets($pdo);

// ── Latest sector news ────────────────────────────────────────
$latestNews = [];
try {
    $latestNews = $pdo->query("
        SELECT pn.title, pn.url, pn.source_name, pn.published_at, pn.sp_number,
               MAX(s.provider_name) AS provider_name,
               MAX(s.council_area)  AS council_area
        FROM provider_news pn
        LEFT JOIN services s ON s.sp_number = pn.sp_number
        WHERE pn.status = 'shown' AND pn.published_at IS NOT NULL
        GROUP BY pn.id
        ORDER BY pn.published_at DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

$isSearching = $params['q'] !== '' || $params['type'] !== '' || $params['council'] !== ''
              || $params['min_grade'] > 0 || $params['min_avg'] !== '' || $params['sp'] !== '';

$title = 'Find care services in Scotland | CareScotland';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<meta name="description" content="Search <?= number_format($total_active) ?> registered care services in Scotland. Real inspection grades from the Care Inspectorate — filter by type, location and quality.">
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
      <a href="/" aria-current="page">Directory</a>
      <a href="/insights">Insights</a>
      <a href="/councils">Council map</a>
      <a href="/news">News</a>
      <a href="/provider/claim.php">Are you a provider?</a>
    </nav>
    </div>
  </div>
</header>

<!-- ── Hero ──────────────────────────────────────────────────── -->
<section class="home-hero">
  <div class="container home-hero__inner">
    <h1 class="home-hero__h1">Scotland's care services directory</h1>
    <p class="home-hero__sub">
      <?= number_format($total_active) ?> registered services with real inspection grades from the Care Inspectorate<?= $lastUpdatedLabel ? ' · data updated ' . h($lastUpdatedLabel) : '' ?>
    </p>
    <form class="home-hero__form" method="get" action="/">
      <input type="text" name="q" value="<?= h($params['q']) ?>"
             placeholder="Search by name, town or postcode…"
             class="home-hero__input" autofocus>
      <button type="submit" class="home-hero__btn">Search</button>
      <?php
        $heroExtras = directory_params_for_url($params);
        unset($heroExtras['q']);
      ?>
      <?php foreach ($heroExtras as $hk => $hv): ?>
        <input type="hidden" name="<?= h((string)$hk) ?>" value="<?= h((string)$hv) ?>">
      <?php endforeach; ?>
    </form>
    <p class="home-hero__links">
      <a href="#directory">Browse all services ↓</a>
      <span>·</span>
      <a href="/councils">Council map</a>
      <span>·</span>
      <a href="/insights">Sector insights</a>
    </p>
  </div>
</section>

<?php if (!$isSearching): ?>
<!-- ── Proof: real grade data + trust stats (unfiltered homepage only) ─ -->
<section class="home-proof">
  <div class="container home-proof__grid">
    <div class="home-proof__chart">
      <h2 class="home-proof__h">Every grade comes straight from the Care Inspectorate</h2>
      <p class="home-proof__p"><?= number_format($total_graded) ?> active services graded so far, refreshed monthly — here's how they score on their weakest inspection question.</p>
      <div class="grade-bars">
        <?php foreach ($gradeBuckets as $b):
          $gpct = $total_graded > 0 ? round($b['count'] / $total_graded * 100, 1) : 0;
        ?>
        <div class="grade-bar-row">
          <span class="grade-bar-label"><?= grade_label($b['grade']) ?></span>
          <div class="grade-bar-track">
            <div class="grade-bar-fill grade-bar-fill--g<?= $b['grade'] ?>" style="width: <?= $gpct ?>%"></div>
          </div>
          <span class="grade-bar-pct"><?= number_format($b['count']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <a href="/insights" class="home-proof__chart-link">See the full sector breakdown →</a>
    </div>
    <div class="home-proof__stats">
      <div class="home-proof__lead">
        <span class="home-proof__num"><?= number_format($total_active) ?>+</span>
        <span class="home-proof__num-label">registered services</span>
      </div>
      <ul class="home-proof__list">
        <li><strong><?= number_format($total_providers) ?>+</strong> care providers</li>
        <li><strong><?= $total_councils ?></strong> council areas covered</li>
        <?php if ($pct_good !== null): ?>
          <li><strong><?= $pct_good ?>%</strong> of graded services rated Good or better</li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ── Directory ─────────────────────────────────────────────── -->
<section class="directory" id="directory">
  <div class="container">

    <?php if (!$isSearching): ?>
    <h2 class="home-section-h">Browse all care services</h2>
    <?php endif; ?>

    <form class="filter-bar" method="get" action="/">
      <?php if ($params['q']): ?>
        <input type="hidden" name="q" value="<?= h($params['q']) ?>">
      <?php endif; ?>
      <div class="filter-bar__row">
        <select name="type" onchange="this.form.submit()" aria-label="Service type">
          <option value="">All service types</option>
          <?php foreach ($service_types as $t): ?>
            <option value="<?= h($t) ?>" <?= $params['type'] === $t ? 'selected' : '' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="council" onchange="this.form.submit()" aria-label="Council area">
          <option value="">All council areas</option>
          <?php foreach ($councils as $c): ?>
            <option value="<?= h($c) ?>" <?= $params['council'] === $c ? 'selected' : '' ?>><?= h($c) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="min_grade" onchange="this.form.submit()" aria-label="Minimum grade">
          <option value="0">Any grade</option>
          <option value="5" <?= $params['min_grade'] === 5 ? 'selected' : '' ?>>Lowest pillar: Very good (5+)</option>
          <option value="4" <?= $params['min_grade'] === 4 ? 'selected' : '' ?>>Lowest pillar: Good (4+)</option>
          <option value="3" <?= $params['min_grade'] === 3 ? 'selected' : '' ?>>Lowest pillar: Adequate (3+)</option>
        </select>
        <select name="min_avg" onchange="this.form.submit()" aria-label="Minimum average grade">
          <option value="">Any average grade</option>
          <option value="3.5" <?= $params['min_avg'] === '3.5' ? 'selected' : '' ?>>Average grade 3.5+</option>
          <option value="4"   <?= $params['min_avg'] === '4'   ? 'selected' : '' ?>>Average grade 4+</option>
          <option value="4.5" <?= $params['min_avg'] === '4.5' ? 'selected' : '' ?>>Average grade 4.5+</option>
          <option value="5"   <?= $params['min_avg'] === '5'   ? 'selected' : '' ?>>Average grade 5+</option>
        </select>
        <select name="sp" onchange="this.form.submit()" aria-label="Service provider" class="filter-bar__provider">
          <option value="">All providers</option>
          <?php foreach ($providerOptions as $prow): ?>
            <option value="<?= h((string)$prow['sp']) ?>" <?= $params['sp'] === (string)$prow['sp'] ? 'selected' : '' ?>><?= h((string)$prow['provider_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-bar__row filter-bar__row--secondary">
        <select name="sort" onchange="this.form.submit()" aria-label="Sort order">
          <option value="default"       <?= $params['sort'] === 'default'       ? 'selected' : '' ?>>Default (highest grade first)</option>
          <option value="grades_recent" <?= $params['sort'] === 'grades_recent' ? 'selected' : '' ?>>Most recently inspected</option>
          <option value="avg_high"      <?= $params['sort'] === 'avg_high'      ? 'selected' : '' ?>>Highest average grade</option>
          <option value="avg_low"       <?= $params['sort'] === 'avg_low'       ? 'selected' : '' ?>>Lowest average grade</option>
        </select>
        <select name="graded_within" onchange="this.form.submit()" aria-label="When last inspected">
          <option value="0"   <?= $params['graded_within'] === 0   ? 'selected' : '' ?>>Any inspection date</option>
          <option value="90"  <?= $params['graded_within'] === 90  ? 'selected' : '' ?>>Inspected in last 90 days</option>
          <option value="180" <?= $params['graded_within'] === 180 ? 'selected' : '' ?>>Inspected in last 6 months</option>
          <option value="365" <?= $params['graded_within'] === 365 ? 'selected' : '' ?>>Inspected in last year</option>
        </select>
        <span class="results-count">
          <?php $t = $result['total']; ?>
          <?= number_format($t) ?> service<?= $t === 1 ? '' : 's' ?>
        </span>
        <?php if (directory_params_for_url($params) !== []): ?>
          <a class="filter-bar__reset" href="/">Reset filters</a>
        <?php endif; ?>
      </div>
      <p class="filter-bar__note">Grades from the Care Inspectorate (1 = Unsatisfactory · 6 = Excellent). Average grade is the mean across all inspection questions. Lowest pillar is the weakest individual question.</p>
    </form>

    <?php
      $pp = $result['per_page'];
      $tot = $result['total'];
      $pg = $result['page'];
      $showFrom = $tot === 0 ? 0 : ($pg - 1) * $pp + 1;
      $showTo   = min($tot, $pg * $pp);
    ?>
    <?php if ($tot > 0): ?>
    <div class="results-toolbar">
      <p class="directory-slice-meta" role="status">
        Showing <strong><?= (int)$showFrom ?>–<?= (int)$showTo ?></strong> of <strong><?= number_format($tot) ?></strong>
        · <?= (int)$pp ?> per page<?= $result['pages'] > 1 ? ' — use page links below for the rest.' : '' ?>
      </p>
      <div class="view-toggle" role="group" aria-label="View mode">
        <button class="view-btn" id="btn-cards" aria-pressed="true" title="Card view">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
            <rect x="0" y="0" width="7" height="7" rx="1"/><rect x="9" y="0" width="7" height="7" rx="1"/>
            <rect x="0" y="9" width="7" height="7" rx="1"/><rect x="9" y="9" width="7" height="7" rx="1"/>
          </svg>
          Cards
        </button>
        <button class="view-btn" id="btn-table" aria-pressed="false" title="Table view">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
            <rect x="0" y="0" width="16" height="3" rx="1"/><rect x="0" y="5" width="16" height="3" rx="1"/>
            <rect x="0" y="10" width="16" height="3" rx="1"/>
          </svg>
          Table
        </button>
      </div>
    </div>
    <?php endif; ?>

    <?php if (empty($result['rows'])): ?>
      <div class="no-results"><p>No services match your search. Try broadening your filters.</p></div>
    <?php else: ?>

      <?php
        $col_sorts = [
            'name'    => ['name_az',      'name_za'],
            'type'    => ['type_az',       null],
            'council' => ['council_az',    null],
            'avg'     => ['avg_high',      'avg_low'],
            'date'    => ['grades_recent', 'inspected_asc'],
            'beds'    => ['beds_desc',     null],
        ];
        $sort_url = function(string $col) use ($params, $sort, $col_sorts): string {
            [$asc, $desc] = $col_sorts[$col];
            if      ($sort === $asc  && $desc !== null) $target = $desc;
            elseif  ($sort === $desc)                   $target = $asc;
            else                                        $target = $asc;
            $p = array_merge($params, ['sort' => $target, 'page' => 1]);
            return '/?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== 0));
        };
        $sort_arrow = function(string $col) use ($sort, $col_sorts): string {
            [$asc, $desc] = $col_sorts[$col];
            if ($sort === $asc)                    return ' <span class="sort-active">↑</span>';
            if ($desc !== null && $sort === $desc) return ' <span class="sort-active">↓</span>';
            return ' <span class="sort-hint">↕</span>';
        };
      ?>

      <div id="view-cards" class="cards-grid">
        <?php foreach ($result['rows'] as $s):
          $avg = avg_grade($s);
          $tier = $s['tier'] ?? 'free';
          $is_premium = in_array($tier, ['premium','pro']);
        ?>
          <article class="service-card <?= $is_premium ? 'card--premium' : '' ?>">
            <?php if ($tier === 'pro'): ?><span class="tier-badge tier-pro">Featured</span>
            <?php elseif ($tier === 'premium'): ?><span class="tier-badge tier-premium">Premium</span>
            <?php endif; ?>
            <div class="card-body">
              <div class="card-head">
                <h2 class="card-name">
                  <a href="/service/<?= h($s['cs_number']) ?>/<?= slug($s['service_name']) ?>"><?= h($s['service_name']) ?></a>
                </h2>
                <span class="type-badge"><?= h($s['care_service'] ?? '') ?></span>
              </div>
              <p class="card-location"><?= h($s['town']) ?>, <?= h($s['postcode']) ?><?= $s['council_area'] ? ' · ' . h($s['council_area']) : '' ?></p>
              <?php if ($is_premium && $s['tagline']): ?>
                <p class="card-tagline"><?= h($s['tagline']) ?></p>
              <?php endif; ?>
              <?php if ($avg !== null): ?>
                <div class="grade-row">
                  <span class="grade-avg <?= grade_class((int)round($avg)) ?>"><?= number_format($avg, 1) ?> <span class="grade-scale">— <?= grade_label((int)round($avg)) ?></span></span>
                  <span class="grade-label-text">across key questions</span>
                  <?php if ($s['grade_wellbeing']): ?><span class="grade-pill <?= grade_class($s['grade_wellbeing']) ?>">Wellbeing <?= $s['grade_wellbeing'] ?></span><?php endif; ?>
                  <?php if ($s['grade_staff']): ?><span class="grade-pill <?= grade_class($s['grade_staff']) ?>">Staff <?= $s['grade_staff'] ?></span><?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($s['grade_published'])): ?>
                <?php $gpTs = strtotime((string)$s['grade_published']); ?>
                <p class="card-grade-published">Grades published <?= $gpTs ? date('j M Y', $gpTs) : h((string)$s['grade_published']) ?></p>
              <?php endif; ?>
              <div class="card-meta">
                <?php if ($s['total_beds']): ?><span><?= $s['total_beds'] ?> beds</span>
                <?php elseif ($s['registered_places']): ?><span><?= $s['registered_places'] ?> places</span><?php endif; ?>
                <?php if ($s['vacancy_count'] !== null && $is_premium): ?>
                  <span class="vacancy <?= $s['vacancy_count'] > 0 ? 'has-vacancy' : 'no-vacancy' ?>"><?= $s['vacancy_count'] > 0 ? $s['vacancy_count'] . ' vacancies' : 'No vacancies' ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="card-footer">
              <a href="/service/<?= h($s['cs_number']) ?>/<?= slug($s['service_name']) ?>" class="btn-view">View profile →</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <div id="view-table" class="dir-table-wrap" style="display:none">
        <table class="dir-table">
          <thead>
            <tr>
              <th><a href="<?= $sort_url('name') ?>">Service<?= $sort_arrow('name') ?></a></th>
              <th><a href="<?= $sort_url('type') ?>">Type<?= $sort_arrow('type') ?></a></th>
              <th><a href="<?= $sort_url('council') ?>">Council<?= $sort_arrow('council') ?></a></th>
              <th><a href="<?= $sort_url('avg') ?>">Avg grade<?= $sort_arrow('avg') ?></a></th>
              <th>Wellbeing</th><th>Leadership</th><th>Staff</th><th>Setting</th><th>Planning</th>
              <th><a href="<?= $sort_url('date') ?>">Inspected<?= $sort_arrow('date') ?></a></th>
              <th><a href="<?= $sort_url('beds') ?>">Beds/Places<?= $sort_arrow('beds') ?></a></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($result['rows'] as $s):
            $avg = avg_grade($s);
            $url = '/service/' . h($s['cs_number']) . '/' . slug($s['service_name']);
            $gpTs = !empty($s['grade_published']) ? strtotime((string)$s['grade_published']) : null;
            $capacity = $s['total_beds'] ?: $s['registered_places'];
          ?>
          <tr>
            <td class="dt-name"><a href="<?= $url ?>"><?= h($s['service_name']) ?></a><?= $s['town'] ? '<span class="dt-town">' . h($s['town']) . '</span>' : '' ?></td>
            <td class="dt-type"><?= h($s['care_service'] ?? '—') ?></td>
            <td class="dt-council"><?= h($s['council_area'] ?? '—') ?></td>
            <td class="dt-avg">
              <?php if ($avg !== null): ?><span class="grade-avg <?= grade_class((int)round($avg)) ?>" style="font-size:0.82rem;padding:2px 7px;"><?= number_format($avg, 1) ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <?php foreach (['grade_wellbeing','grade_leadership','grade_staff','grade_setting','grade_planning'] as $gk): ?>
            <td class="dt-grade"><?php $g = $s[$gk] ?? null; ?><?= $g ? '<span class="grade-pip ' . grade_class((int)$g) . '">' . $g . '</span>' : '—' ?></td>
            <?php endforeach; ?>
            <td class="dt-date"><?= $gpTs ? date('M Y', $gpTs) : '—' ?></td>
            <td class="dt-beds"><?= $capacity ?: '—' ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?= paginate($result['total'], $result['page'], $result['pages'], $params) ?>

      <script>
      (function(){
        var cards = document.getElementById('view-cards');
        var table = document.getElementById('view-table');
        var btnC  = document.getElementById('btn-cards');
        var btnT  = document.getElementById('btn-table');
        var stored = localStorage.getItem('dir-view') || 'cards';
        function setView(v) {
          var t = v === 'table';
          cards.style.display = t ? 'none' : '';
          table.style.display = t ? '' : 'none';
          btnC.setAttribute('aria-pressed', String(!t));
          btnT.setAttribute('aria-pressed', String(t));
          localStorage.setItem('dir-view', v);
        }
        setView(stored);
        btnC.addEventListener('click', function(){ setView('cards'); });
        btnT.addEventListener('click', function(){ setView('table'); });
      })();
      </script>

    <?php endif; ?>
  </div>
</section>

<?php if ($latestNews): ?>
<!-- ── Latest sector news ─────────────────────────────────────── -->
<section class="home-news">
  <div class="container">
    <div class="home-news__header">
      <div>
        <h2 class="home-section-h">Latest sector news</h2>
        <p class="home-news__sub">Recent press coverage of care providers across Scotland, sourced daily from Google News.</p>
      </div>
      <a class="home-news__all" href="/news">View all news →</a>
    </div>
    <div class="news-grid news-grid--home">
      <?php foreach ($latestNews as $article):
        $pubTs  = !empty($article['published_at']) ? strtotime($article['published_at']) : null;
        $pubStr = $pubTs ? date('j M Y', $pubTs) : '';
      ?>
      <article class="news-card">
        <?php if ($article['provider_name']): ?>
          <a class="news-card__provider" href="/provider/<?= h($article['sp_number']) ?>/<?= slug($article['provider_name']) ?>">
            <?= h($article['provider_name']) ?>
          </a>
        <?php endif; ?>
        <a class="news-card__title" href="<?= h($article['url']) ?>" target="_blank" rel="noopener noreferrer">
          <?= h($article['title']) ?>
        </a>
        <div class="news-card__meta">
          <?php if ($article['source_name']): ?><span class="news-card__source"><?= h($article['source_name']) ?></span><?php endif; ?>
          <?php if ($pubStr): ?>
            <?php if ($article['source_name']): ?><span class="news-card__sep">·</span><?php endif; ?>
            <time class="news-card__date" datetime="<?= h($article['published_at'] ?? '') ?>"><?= $pubStr ?></time>
          <?php endif; ?>
          <?php if (!empty($article['council_area'])): ?>
            <span class="news-card__sep">·</span>
            <a class="news-card__council" href="/news?council=<?= h(rawurlencode((string) $article['council_area'])) ?>"><?= h((string) $article['council_area']) ?></a>
          <?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ── Provider CTA ───────────────────────────────────────────── -->
<section class="provider-cta">
  <div class="container">
    <h2>Do you run a care service?</h2>
    <p>Claim your free listing and add photos, vacancies, pricing and a direct enquiry form. Upgrade to Premium or Pro to appear at the top of search results.</p>
    <div class="provider-cta-actions">
      <a href="/provider/claim.php" class="btn-primary">Claim your listing →</a>
    </div>
  </div>
</section>

<footer class="site-footer">
  <div class="container">
    <p>Service data from the <a href="https://www.careinspectorate.scot" rel="noopener">Care Inspectorate</a> (Open Government Licence) · Updated monthly</p>
    <p class="site-footer__legal"><a href="/terms">Terms</a> · <a href="/privacy">Privacy</a></p>
    <p class="site-footer__admin"><a href="/admin/imports.php">Admin</a></p>
  </div>
</footer>

<script src="<?= asset_url('/assets/js/cookie-banner.js') ?>" defer></script>
</body>
</html>
