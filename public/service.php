<?php
// =============================================================
// public/service.php — Individual service profile
// URL pattern: /service/{cs_number}/{slug}
//   → Route via .htaccess or handle cs_number from $_GET['cs']
// =============================================================
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';

$cs_number = trim($_GET['cs'] ?? '');
if (!preg_match('/^CS\d+$/i', $cs_number)) {
    http_response_code(404);
    exit('Service not found.');
}

$service = get_service(strtoupper($cs_number));
if (!$service) {
    http_response_code(404);
    exit('Service not found.');
}

$avg                = avg_grade($service);
$tier               = $service['tier'] ?? 'free';
$title              = h($service['service_name']) . ' — CareScotland';

// Work out how stale the inspection data is
$grade_age_years = null;
$stale_level     = null; // null | 'warn' | 'old' | 'very_old'
if (!empty($service['grade_published'])) {
    $grade_age_years = (int) floor(
        (time() - strtotime($service['grade_published'])) / (365.25 * 86400)
    );
    if ($grade_age_years >= 5)      $stale_level = 'very_old';
    elseif ($grade_age_years >= 3)  $stale_level = 'old';
    elseif ($grade_age_years >= 2)  $stale_level = 'warn';
} elseif ($avg === null) {
    $stale_level = 'ungraded';
}
$inspection_reports = get_ci_inspection_reports($service['cs_number']);
$grade_history      = get_ci_quality_grades($service['cs_number']);
$complaints         = get_complaints_from_db($service['cs_number']);
$comparisons        = get_service_comparisons($service, db());
$sibling_registrations = get_sibling_registrations($service, db());

// Build chart data — combine old+new frameworks into a single timeline, oldest first
$chart_points = [];
foreach (array_reverse($grade_history['old'] ?? []) as $r) {
    if ($r['avg'] !== null) $chart_points[] = ['date' => $r['date'], 'avg' => $r['avg'], 'fw' => 'old'];
}
foreach (array_reverse($grade_history['new'] ?? []) as $r) {
    if ($r['avg'] !== null) $chart_points[] = ['date' => $r['date'], 'avg' => $r['avg'], 'fw' => 'new'];
}
// Grade question labels for comparison table
$grade_questions = [
    'wellbeing'  => 'Support & wellbeing',
    'leadership' => 'Leadership',
    'staff'      => 'Staff team',
    'setting'    => 'Setting',
    'planning'   => 'Care & support planning',
    'cpl'        => 'Care, play & learning',
];

// Handle enquiry form submission
$enquiry_sent  = false;
$enquiry_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_enquiry'])) {
    $name    = trim($_POST['sender_name']  ?? '');
    $email   = trim($_POST['sender_email'] ?? '');
    $message = trim($_POST['message']      ?? '');
    if ($name && filter_var($email, FILTER_VALIDATE_EMAIL) && $message) {
        $pdo = db();
        $pdo->prepare("
            INSERT INTO enquiries (service_id, sender_name, sender_email, sender_phone, message, care_start, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $service['id'], $name, $email,
            trim($_POST['sender_phone'] ?? ''),
            $message,
            trim($_POST['care_start'] ?? '') ?: null,
            $_SERVER['REMOTE_ADDR'],
        ]);
        // Forward to provider if they have an enquiry email
        $to = $service['enquiry_email'] ?: $service['email'];
        if ($to) {
            $body = "<p>New enquiry for <strong>{$service['service_name']}</strong> via CareScotland:</p>
                     <p><strong>From:</strong> $name ($email)<br>
                     <strong>Message:</strong> $message</p>";
            send_email($to, "New enquiry: {$service['service_name']}", $body);
        }
        $enquiry_sent = true;
    } else {
        $enquiry_error = 'Please fill in your name, a valid email and a message.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?></title>
<meta name="description" content="View inspection grades, vacancies and contact details for <?= h($service['service_name']) ?> in <?= h($service['town']) ?>.">
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
      <a href="/insights">Insights</a>
      <a href="/councils">Council map</a>
      <a href="/news">News</a>
      <a href="/provider/claim.php">Are you a provider?</a>
    </nav>
    </div>
  </div>
</header>

<div class="container profile-layout">

  <main class="profile-main">
    <nav class="breadcrumb">
      <a href="/">Home</a> › <a href="/?council=<?= urlencode($service['council_area']) ?>"><?= h($service['council_area']) ?></a> › <?= h($service['service_name']) ?>
    </nav>

    <?php if ($stale_level === 'very_old'): ?>
    <div class="stale-banner stale-banner--very-old">
      <span class="stale-banner__icon">⚠</span>
      <div>
        <strong>Inspection grades are <?= $grade_age_years ?> years old.</strong>
        These grades are from <?= date('F Y', strtotime($service['grade_published'])) ?> and may no longer reflect the current quality of this service.
        The Care Inspectorate registers it as <strong>Active</strong> but has not published new grades since then.
        <a href="https://www.careinspectorate.com/index.php/care-services?detail=<?= h($service['cs_number']) ?>" target="_blank" rel="noopener">Check the Care Inspectorate website</a> before making any decisions.
      </div>
    </div>
    <?php elseif ($stale_level === 'old'): ?>
    <div class="stale-banner stale-banner--old">
      <span class="stale-banner__icon">⚠</span>
      <div>
        <strong>Grades are <?= $grade_age_years ?> years old</strong> (published <?= date('F Y', strtotime($service['grade_published'])) ?>).
        The Care Inspectorate may not have re-inspected this service recently.
        <a href="https://www.careinspectorate.com/index.php/care-services?detail=<?= h($service['cs_number']) ?>" target="_blank" rel="noopener">View the official record</a> to check for any updates.
      </div>
    </div>
    <?php elseif ($stale_level === 'warn'): ?>
    <div class="stale-banner stale-banner--warn">
      <span class="stale-banner__icon">ℹ</span>
      <div>
        <strong>Grades are <?= $grade_age_years ?> years old</strong> (published <?= date('F Y', strtotime($service['grade_published'])) ?>).
        A more recent inspection may have taken place but not yet appear in our data.
        <a href="https://www.careinspectorate.com/index.php/care-services?detail=<?= h($service['cs_number']) ?>" target="_blank" rel="noopener">Check the Care Inspectorate website</a> for the latest grades.
      </div>
    </div>
    <?php elseif ($stale_level === 'ungraded'): ?>
    <div class="stale-banner stale-banner--ungraded">
      <span class="stale-banner__icon">ℹ</span>
      <div>
        <strong>No inspection grades on record.</strong>
        This service is registered as Active but has no published grades yet — it may be recently registered or awaiting its first inspection.
        <a href="https://www.careinspectorate.com/index.php/care-services?detail=<?= h($service['cs_number']) ?>" target="_blank" rel="noopener">Check the Care Inspectorate website</a> for the latest status.
      </div>
    </div>
    <?php endif; ?>

    <div class="profile-header">
      <div>
        <span class="type-badge"><?= h($service['care_service'] ?? '') ?></span>
        <?php if ($service['service_status'] === 'Active'): ?>
          <span class="status-badge status-active">Active</span>
        <?php endif; ?>
        <h1><?= h($service['service_name']) ?></h1>
        <p class="profile-location">
          <?= h(implode(', ', array_filter([$service['address_1'], $service['town'], $service['postcode']]))) ?>
        </p>
      </div>
      <?php if ($avg !== null): ?>
        <div class="profile-grade-summary">
          <div class="avg-grade-circle <?= grade_class((int) round($avg)) ?>">
            <span class="avg-num"><?= number_format($avg, 1) ?></span>
            <span class="avg-sublabel">Average</span>
            <span class="avg-label">Key questions (1–6)</span>
          </div>
          <?php if ($service['grade_min'] !== null && $service['grade_max'] !== null): ?>
            <p class="avg-range-hint">Care Inspectorate grades for this service: <strong><?= (int) $service['grade_min'] ?></strong>–<strong><?= (int) $service['grade_max'] ?></strong> (lowest–highest key question).</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($service['tagline']): ?>
      <p class="profile-tagline"><?= h($service['tagline']) ?></p>
    <?php endif; ?>

    <?php if ($service['description']): ?>
      <div class="profile-description">
        <?= nl2br(h($service['description'])) ?>
      </div>
    <?php endif; ?>

    <!-- Inspection grades -->
    <?php $grade_keys = [
      'grade_wellbeing'  => 'Support & wellbeing',
      'grade_planning'   => 'Care & support planning',
      'grade_setting'    => 'Setting',
      'grade_staff'      => 'Staff team',
      'grade_leadership' => 'Leadership',
      'grade_cpl'        => 'Care, play & learning',
    ]; ?>
    <section class="profile-section">
      <h2>Inspection grades</h2>
      <?php if ($service['grade_published']): ?>
        <p class="grade-date">Grades published: <strong><?= date('F Y', strtotime($service['grade_published'])) ?></strong>
        <?php if ($grade_age_years !== null && $grade_age_years > 0): ?>
          — <?= $grade_age_years ?> year<?= $grade_age_years !== 1 ? 's' : '' ?> ago
        <?php endif; ?>
        </p>
      <?php endif; ?>
      <div class="grade-grid">
        <?php foreach ($grade_keys as $key => $label): ?>
          <?php $g = $service[$key]; if ($g === null) continue; ?>
          <div class="grade-card <?= grade_class($g) ?>">
            <span class="grade-score"><?= $g ?></span>
            <span class="grade-name"><?= $label ?></span>
            <span class="grade-desc"><?= grade_label($g) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="grade-note">Grades from 1 (unsatisfactory) to 6 (excellent). Source: Care Inspectorate.</p>
    </section>

    <!-- Grade history chart -->
    <?php if (count($chart_points) >= 2): ?>
    <section class="profile-section">
      <h2>Grade history</h2>
      <p class="grade-date-note">Average inspection grade across all key questions per visit. The grading framework changed in 2021 — older and newer inspections are shown on the same scale (1–6).</p>
      <div class="chart-wrap">
        <canvas id="gradeChart"></canvas>
      </div>
      <script>
      document.addEventListener('DOMContentLoaded', function() {
        var pts  = <?= json_encode($chart_points) ?>;
        var lbls = pts.map(function(p){ return p.date.substring(0,4); });
        var vals = pts.map(function(p){ return p.avg; });
        var colors = vals.map(function(v){
          return v >= 4 ? '#2e7d32' : v >= 3 ? '#f57c00' : '#c62828';
        });
        new Chart(document.getElementById('gradeChart'), {
          type: 'line',
          data: {
            labels: lbls,
            datasets: [{
              label: 'Average grade',
              data: vals,
              borderColor: '#1565c0',
              backgroundColor: 'rgba(21,101,192,0.08)',
              pointBackgroundColor: colors,
              pointRadius: 6,
              pointHoverRadius: 8,
              tension: 0.3,
              fill: true,
            }]
          },
          options: {
            responsive: true,
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  label: function(ctx) {
                    var labels = ['','Unsatisfactory','Weak','Adequate','Good','Very Good','Excellent'];
                    var v = ctx.parsed.y;
                    return 'Average: ' + v + ' — ' + (labels[Math.round(v)] || '');
                  }
                }
              }
            },
            scales: {
              y: {
                min: 1, max: 6, stepSize: 1,
                ticks: {
                  callback: function(v) {
                    return ['','Unsatisfactory','Weak','Adequate','Good','Very Good','Excellent'][v] || v;
                  }
                },
                grid: { color: 'rgba(0,0,0,0.06)' }
              },
              x: { grid: { display: false } }
            }
          }
        });
      });
      </script>
    </section>
    <?php elseif (count($chart_points) === 1): ?>
    <section class="profile-section">
      <h2>Grade history</h2>
      <p class="grade-date-note">Only one graded inspection on record — a trend chart will appear once there are two or more inspections to compare.</p>
    </section>
    <?php endif; ?>

    <!-- Comparisons -->
    <?php if (!empty($comparisons)):
      // For each comparison group, work out how many questions are above / in line / below
      $cmp_groups = [
        'provider' => [
          'label'   => 'Other services by the same provider',
          'count'   => isset($comparisons['provider']) ? (int)$comparisons['provider']['cnt'] . ' other service' . ((int)$comparisons['provider']['cnt'] !== 1 ? 's' : '') : '',
        ],
        'local' => [
          'label'   => h($service['care_service'] ?? 'Similar services') . ' in ' . h($service['council_area'] ?? 'this council'),
          'count'   => isset($comparisons['local']) ? number_format((int)$comparisons['local']['cnt']) . ' services' : '',
        ],
        'national' => [
          'label'   => 'All ' . h($service['care_service'] ?? 'similar services') . ' in Scotland',
          'count'   => isset($comparisons['national']) ? number_format((int)$comparisons['national']['cnt']) . ' services' : '',
        ],
      ];

      // Classify each question vs each group: 'above' | 'inline' | 'below' | null
      $classify = function(?int $mine, ?float $avg): ?string {
        if ($mine === null || $avg === null) return null;
        $diff = $mine - $avg;
        if ($diff >= 0.5)  return 'above';
        if ($diff <= -0.5) return 'below';
        return 'inline';
      };
    ?>
    <section class="profile-section">
      <h2>How this compares</h2>
      <p class="grade-date-note">Based on current Care Inspectorate grades across all active services in Scotland.</p>
      <div class="cmp-cards">
        <?php foreach ($cmp_groups as $grp => $meta):
          if (!isset($comparisons[$grp])) continue;
          $cmp = $comparisons[$grp];

          $above = $inline = $below = $na = [];
          foreach ($grade_questions as $field => $qlabel) {
            $mine = isset($service['grade_' . $field]) ? (int)$service['grade_' . $field] : null;
            $avg  = isset($cmp[$field]) ? (float)$cmp[$field] : null;
            $verdict = $classify($mine, $avg);
            if ($verdict === 'above')  $above[]  = $qlabel;
            elseif ($verdict === 'below')  $below[]  = $qlabel;
            elseif ($verdict === 'inline') $inline[] = $qlabel;
            else                           $na[]     = $qlabel;
          }

          // Overall headline
          $total_rated = count($above) + count($inline) + count($below);
          if ($total_rated === 0) continue;
          if (count($above) === $total_rated)       $headline = 'Above average on every question';
          elseif (count($below) === $total_rated)   $headline = 'Below average on every question';
          elseif (empty($below))                    $headline = 'Above or in line with average on every question';
          elseif (empty($above))                    $headline = 'At or below average on every question';
          else                                      $headline = 'Mixed — above average on some, below on others';
        ?>
        <div class="cmp-card">
          <div class="cmp-card__meta"><?= $meta['count'] ?></div>
          <div class="cmp-card__group"><?= $meta['label'] ?></div>
          <div class="cmp-card__headline <?= !empty($above) && empty($below) ? 'hl--good' : (!empty($below) && empty($above) ? 'hl--poor' : 'hl--mixed') ?>">
            <?= $headline ?>
          </div>
          <ul class="cmp-card__list">
            <?php foreach ($above as $ql): ?>
              <li class="cmp-item cmp-item--above">↑ Above average — <?= h($ql) ?></li>
            <?php endforeach; ?>
            <?php foreach ($inline as $ql): ?>
              <li class="cmp-item cmp-item--inline">≈ In line — <?= h($ql) ?></li>
            <?php endforeach; ?>
            <?php foreach ($below as $ql): ?>
              <li class="cmp-item cmp-item--below">↓ Below average — <?= h($ql) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="grade-date-note" style="margin-top:0.75rem;">"Above average" means this service's grade is at least half a point higher than the group average; "in line" means within half a point either way.</p>
    </section>
    <?php endif; ?>

    <!-- Complaints history -->
    <section class="profile-section" id="complaints">
      <h2>Complaints history</h2>
      <?php if (!empty($complaints)):
        // Group rows by case number — CI returns one row per sub-category
        $grouped = [];
        foreach ($complaints as $c) {
            $key = $c['case_number'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'date'        => $c['date'],
                    'case_number' => $key,
                    'outcome'     => $c['outcome'] ?? null,
                    'categories'  => [],
                ];
            }
            // Split any semicolon-separated cats and deduplicate within this case
            foreach (array_map('trim', explode(';', $c['category'])) as $cat) {
                if ($cat !== '' && !in_array($cat, $grouped[$key]['categories'], true)) {
                    $grouped[$key]['categories'][] = $cat;
                }
            }
        }
      ?>
      <table class="report-table">
        <thead><tr><th>Date</th><th>Case number</th><th>Outcome</th><th>Categories</th></tr></thead>
        <tbody>
          <?php foreach ($grouped as $c):
            $outcome   = $c['outcome'] ?? null;
            $outLower  = strtolower((string) $outcome);
            $outClass  = str_contains($outLower, 'upheld') && !str_contains($outLower, 'not') && !str_contains($outLower, 'partial')
                ? 'outcome-upheld'
                : (str_contains($outLower, 'not upheld') ? 'outcome-not-upheld'
                : (str_contains($outLower, 'partial')    ? 'outcome-partial'
                : 'outcome-other'));
          ?>
          <tr>
            <td class="report-date" style="white-space:nowrap;"><?= h($c['date']) ?></td>
            <td class="mono" style="font-size:0.85em;white-space:nowrap;"><?= h($c['case_number']) ?></td>
            <td style="white-space:nowrap;">
              <?php if ($outcome): ?>
                <span class="outcome-badge <?= $outClass ?>"><?= h($outcome) ?></span>
              <?php else: ?>
                <span class="outcome-badge outcome-other">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (count($c['categories']) === 1): ?>
                <?= h($c['categories'][0]) ?>
              <?php else: ?>
                <ul class="complaint-cats">
                  <?php foreach ($c['categories'] as $cat): ?>
                    <li><?= h($cat) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php elseif ((int) ($service['complaints_upheld'] ?? 0) > 0): ?>
      <p class="grade-date-note">
        The Care Inspectorate's published data shows <strong><?= (int) $service['complaints_upheld'] ?> upheld complaint<?= (int) $service['complaints_upheld'] === 1 ? '' : 's' ?></strong> for this service, but detailed case records aren't available here right now.
        <a href="https://www.careinspectorate.com/index.php/care-services?detail=<?= h($service['cs_number']) ?>" target="_blank" rel="noopener">Check the Care Inspectorate website</a> for full details.
      </p>
      <?php else: ?>
      <p class="grade-date-note" style="color:#2e7d32;">✓ No complaints found for this service in the Care Inspectorate records.</p>
      <?php endif; ?>
    </section>

    <!-- Inspection report history -->
    <?php if (!empty($inspection_reports)): ?>
    <section class="profile-section">
      <h2>Inspection reports</h2>
      <p class="grade-date-note">Full reports from the Care Inspectorate — including written assessments, recommendations and requirements.</p>
      <table class="report-table">
        <thead>
          <tr><th>Date</th><th>Report</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($inspection_reports as $i => $report): ?>
          <tr <?= $i === 0 ? 'class="report-latest"' : '' ?>>
            <td class="report-date"><?= h($report['date']) ?></td>
            <td class="report-title"><?= h($report['title']) ?><?= $i === 0 ? ' <span class="report-badge">Latest</span>' : '' ?></td>
            <td class="report-dl">
              <a href="<?= h($report['url']) ?>" target="_blank" rel="noopener" class="btn-report-dl">
                Download PDF
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
    <?php else: ?>
    <section class="profile-section">
      <h2>Inspection reports</h2>
      <p class="grade-date-note">No inspection reports are currently available for this service. <a href="https://www.careinspectorate.com/index.php/care-services?detail=<?= h($service['cs_number']) ?>" target="_blank" rel="noopener">Check the Care Inspectorate website</a> for the latest information.</p>
    </section>
    <?php endif; ?>

    <!-- Key facts -->
    <section class="profile-section">
      <h2>Key facts</h2>
      <dl class="fact-list">
        <?php if ($service['provider_name']): ?>
          <dt>Provider</dt>
          <dd>
            <?php if (!empty($service['sp_number'])): ?>
              <a href="/provider/<?= rawurlencode((string) $service['sp_number']) ?>/<?= rawurlencode(slug($service['provider_name'])) ?>">
                <?= h($service['provider_name']) ?>
              </a>
              <span class="fact-hint"> · all their services</span>
            <?php else: ?>
              <?= h($service['provider_name']) ?>
            <?php endif; ?>
          </dd>
        <?php endif; ?>
        <?php if ($service['manager_name']): ?>
          <dt>Manager</dt><dd><?= h($service['manager_name']) ?></dd>
        <?php endif; ?>
        <?php if ($service['total_beds']): ?>
          <dt>Total beds</dt><dd><?= $service['total_beds'] ?></dd>
        <?php endif; ?>
        <?php if ($service['registered_places']): ?>
          <dt>Registered places</dt><dd><?= $service['registered_places'] ?></dd>
        <?php endif; ?>
        <?php if ($service['num_staff']): ?>
          <dt>Staff</dt><dd><?= $service['num_staff'] ?></dd>
        <?php endif; ?>
        <?php if ($service['client_group']): ?>
          <dt>Client group</dt><dd><?= h($service['client_group']) ?></dd>
        <?php endif; ?>
        <?php if ($service['date_registered']): ?>
          <dt>Registered since</dt><dd><?= date('Y', strtotime($service['date_registered'])) ?></dd>
        <?php endif; ?>
        <?php if ($service['council_area']): ?>
          <dt>Local authority</dt>
          <dd><a href="/?council=<?= urlencode($service['council_area']) ?>"><?= h($service['council_area']) ?></a></dd>
        <?php endif; ?>
        <?php if ($service['health_board']): ?>
          <dt>Health board</dt>
          <dd><a href="/?health_board=<?= urlencode($service['health_board']) ?>"><?= h($service['health_board']) ?></a></dd>
        <?php endif; ?>
      </dl>
    </section>

    <!-- Other registrations at this address (same provider, same postcode) -->
    <?php if ($sibling_registrations): ?>
    <section class="profile-section">
      <h2>Other registrations at this address</h2>
      <p class="sibling-note">The Care Inspectorate registers each type of care service separately, even when it's delivered by the same team from the same address. This provider also runs:</p>
      <ul class="sibling-list">
        <?php foreach ($sibling_registrations as $sib): ?>
          <li>
            <a href="/service/<?= h($sib['cs_number']) ?>/<?= slug($sib['service_name']) ?>"><?= h($sib['service_name']) ?></a>
            <span class="fact-hint"> — <?= h($sib['care_service']) ?><?= $sib['subtype'] ? ' (' . h($sib['subtype']) . ')' : '' ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <!-- Vacancies (premium only) -->
    <?php if (in_array($tier, ['premium','pro']) && $service['vacancy_count'] !== null): ?>
      <section class="profile-section">
        <h2>Vacancies</h2>
        <?php if ($service['vacancy_count'] > 0): ?>
          <p class="vacancy has-vacancy"><?= $service['vacancy_count'] ?> <?= $service['vacancy_count'] === 1 ? 'vacancy' : 'vacancies' ?> available</p>
        <?php else: ?>
          <p class="vacancy no-vacancy">No current vacancies</p>
        <?php endif; ?>
        <?php if ($service['weekly_fee_from']): ?>
          <p>Weekly fee: from £<?= number_format($service['weekly_fee_from'], 0) ?>
          <?php if ($service['weekly_fee_to']): ?>
            – £<?= number_format($service['weekly_fee_to'], 0) ?>
          <?php endif; ?></p>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if (!in_array($tier, ['premium','pro'])): ?>
      <div class="claim-nudge">
        <div class="claim-nudge__body">
          <h2 class="claim-nudge__h">Run this service?</h2>
          <p>Claim this free listing to add photos, vacancies and pricing — or upgrade to Premium or Pro to appear at the top of search results and get a direct enquiry form.</p>
        </div>
        <a href="/provider/claim.php?cs=<?= h($cs_number) ?>" class="claim-nudge__btn">Claim your listing →</a>
      </div>
    <?php endif; ?>
  </main>

  <!-- Sidebar -->
  <aside class="profile-sidebar">

    <!-- Contact / enquiry -->
    <div class="sidebar-card">
      <h3>Contact this service</h3>
      <?php if ($service['phone']): ?>
        <p><a href="tel:<?= h($service['phone']) ?>"><?= h($service['phone']) ?></a></p>
      <?php endif; ?>
      <?php if ($service['website_url'] && in_array($tier, ['premium','pro'])): ?>
        <p><a href="<?= h($service['website_url']) ?>" rel="noopener" target="_blank">Visit website →</a></p>
      <?php endif; ?>

      <?php if ($enquiry_sent): ?>
        <p class="enquiry-success">Your enquiry has been sent. The service will be in touch.</p>
      <?php else: ?>
        <form class="enquiry-form" method="post">
          <?php if ($enquiry_error): ?>
            <p class="form-error"><?= h($enquiry_error) ?></p>
          <?php endif; ?>
          <input type="hidden" name="send_enquiry" value="1">
          <label>Your name
            <input type="text" name="sender_name" required>
          </label>
          <label>Your email
            <input type="email" name="sender_email" required>
          </label>
          <label>Phone (optional)
            <input type="tel" name="sender_phone">
          </label>
          <label>Care needed from (optional)
            <input type="date" name="care_start">
          </label>
          <label>Message
            <textarea name="message" rows="4" required placeholder="Tell the service about your needs…"></textarea>
          </label>
          <button type="submit" class="btn-primary" style="width:100%">Send enquiry</button>
          <p class="form-note">Your details are shared only with this service.</p>
        </form>
      <?php endif; ?>
    </div>

    <div class="sidebar-card sidebar-card--ci">
      <h3>Official record</h3>
      <p style="font-size:0.88em;color:#555;margin-bottom:12px;">
        Full inspection reports — including assessments, recommendations and requirements — are published by the Care Inspectorate.
      </p>
      <a href="https://www.careinspectorate.com/index.php/care-services?detail=<?= h($service['cs_number']) ?>"
         target="_blank" rel="noopener" class="btn-ci-report">
        View on Care Inspectorate ↗
      </a>
      <p style="font-size:0.78em;color:#aaa;margin-top:8px;">CS number: <?= h($service['cs_number']) ?></p>
    </div>

    <div class="sidebar-card">
      <h3>Location</h3>
      <p><?php
        $addrLines = array_filter([
          $service['address_1'], $service['address_2'],
          $service['address_3'], $service['address_4'],
          $service['town'], $service['postcode'],
        ]);
        echo implode('<br>', array_map('h', $addrLines));
      ?></p>
    </div>

  </aside>

</div>

<footer class="site-footer">
  <div class="container">
    <p>Data from the <a href="https://www.careinspectorate.com">Care Inspectorate</a> (Open Government Licence). <a href="https://www.careinspectorate.com/index.php/care-services?detail=<?= h($service['cs_number']) ?>" rel="noopener" target="_blank">View official record for <?= h($service['cs_number']) ?></a>.</p>
    <p class="site-footer__legal"><a href="/terms">Terms</a> · <a href="/privacy">Privacy</a></p>
    <p class="site-footer__admin"><a href="/admin/imports.php">Admin</a></p>
  </div>
</footer>

<script src="/assets/js/cookie-banner.js" defer></script>
</body>
</html>
