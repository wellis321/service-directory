<?php
// =============================================================
// includes/functions.php — shared helpers
// =============================================================

if (!function_exists('load_app_config')) {
    if (!defined('ROOT')) {
        define('ROOT', dirname(__DIR__));
    }
    require_once __DIR__ . '/env.php';
}

// Sanitise output for HTML contexts (DB fields are often null)
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// Grade helpers
function grade_label(int $g): string {
    return match($g) {
        6 => 'Excellent',
        5 => 'Very good',
        4 => 'Good',
        3 => 'Adequate',
        2 => 'Weak',
        1 => 'Unsatisfactory',
        default => 'Not graded',
    };
}
function grade_class(int $g): string {
    return 'grade-g' . max(1, min(6, $g));
}

// Return average of all non-null key question grades for a service row
function avg_grade(array $row): ?float {
    $keys = ['grade_wellbeing','grade_planning','grade_setting','grade_staff','grade_leadership','grade_cpl'];
    $vals = array_filter(array_map(fn($k) => $row[$k] ?? null, $keys), fn($v) => $v !== null);
    return $vals ? round(array_sum($vals) / count($vals), 1) : null;
}

/**
 * SQL expression (alias s) matching avg_grade(): mean of non-null key-question grades, else NULL.
 */
function sql_avg_key_question_score(): string
{
    $cols = ['grade_wellbeing', 'grade_planning', 'grade_setting', 'grade_staff', 'grade_leadership', 'grade_cpl'];
    $sum = implode('+', array_map(static fn (string $c): string => "IFNULL(s.$c,0)", $cols));
    $cnt = implode('+', array_map(static fn (string $c): string => "(s.$c IS NOT NULL)", $cols));

    return "(($sum) / NULLIF($cnt, 0))";
}

// Slug a service name for SEO-friendly URLs
function slug(?string $s): string {
    $s = strtolower(trim((string) ($s ?? '')));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

// Search the services table with filters
function search_services(array $params = [], int $page = 1, int $per_page = 24): array {
    $pdo = db();
    $where = ["s.service_status = 'Active'", "s.public_list = 1"];
    $binds = [];
    $avgSql = sql_avg_key_question_score();

    if (!empty($params['q'])) {
        $where[] = 'MATCH(s.service_name, s.town, s.provider_name, s.client_group) AGAINST(:q IN BOOLEAN MODE)';
        $binds[':q'] = $params['q'] . '*';
    }
    if (!empty($params['type'])) {
        $where[] = 's.care_service = :type';
        $binds[':type'] = $params['type'];
    }
    if (!empty($params['council'])) {
        $where[] = 's.council_area = :council';
        $binds[':council'] = $params['council'];
    }
    if (!empty($params['health_board'])) {
        $where[] = 's.health_board = :health_board';
        $binds[':health_board'] = $params['health_board'];
    }
    if (!empty($params['min_grade'])) {
        $where[] = 's.grade_min >= :min_grade';
        $binds[':min_grade'] = (int) $params['min_grade'];
    }
    if (!empty($params['sp'])) {
        $where[] = 's.sp_number = :sp';
        $binds[':sp'] = $params['sp'];
    }
    $gradedWithin = (int) ($params['graded_within'] ?? 0);
    if (in_array($gradedWithin, [90, 180, 365], true)) {
        $where[] = 's.grade_published IS NOT NULL AND s.grade_published >= DATE_SUB(CURDATE(), INTERVAL :gw DAY)';
        $binds[':gw'] = $gradedWithin;
    }

    $minAvgMark = trim((string) ($params['min_avg'] ?? ''));
    if (in_array($minAvgMark, ['3.5', '4', '4.5', '5'], true)) {
        $where[] = "($avgSql) IS NOT NULL AND ($avgSql) >= :min_avg";
        $binds[':min_avg'] = $minAvgMark;
    }

    $sql_where = implode(' AND ', $where);

    $count_sql = "SELECT COUNT(*) FROM services s WHERE $sql_where";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($binds);
    $total = (int) $stmt->fetchColumn();

    $pages = $total > 0 ? (int) ceil($total / $per_page) : 1;
    $page  = min(max(1, $page), $pages);
    $offset = ($page - 1) * $per_page;

    $tierOrder = "CASE WHEN lt.tier = 'pro'     THEN 0
                 WHEN lt.tier = 'premium' THEN 1
                 ELSE 2 END";
    $sort = $params['sort'] ?? 'default';

    // Explicit column sorts bypass tier ordering so the chosen column takes full effect
    if ($sort === 'name_az') {
        $orderSql = "s.service_name ASC";
    } elseif ($sort === 'name_za') {
        $orderSql = "s.service_name DESC";
    } elseif ($sort === 'council_az') {
        $orderSql = "s.council_area ASC, s.service_name ASC";
    } elseif ($sort === 'type_az') {
        $orderSql = "s.care_service ASC, s.service_name ASC";
    } elseif ($sort === 'beds_desc') {
        $orderSql = "(s.total_beds IS NULL AND s.registered_places IS NULL) ASC,
            COALESCE(s.total_beds, s.registered_places) DESC,
            s.service_name ASC";
    } elseif ($sort === 'avg_high') {
        $orderSql = "($avgSql IS NULL) ASC, $avgSql DESC, s.service_name ASC";
    } elseif ($sort === 'avg_low') {
        $orderSql = "($avgSql IS NULL) ASC, $avgSql ASC, s.service_name ASC";
    } elseif ($sort === 'grades_recent') {
        $orderSql = "(s.grade_published IS NULL) ASC, s.grade_published DESC, s.service_name ASC";
    } elseif ($sort === 'inspected_asc') {
        $orderSql = "(s.grade_published IS NULL) ASC, s.grade_published ASC, s.service_name ASC";
    } else {
        // Default: paid tiers first, then highest grade
        $orderSql = "$tierOrder, s.grade_min DESC, s.service_name ASC";
    }

    $data_sql = "
        SELECT s.*,
               lt.tier, lt.tagline, lt.vacancy_count, lt.weekly_fee_from, lt.weekly_fee_to,
               lt.approved AS listing_approved
        FROM   services s
        LEFT JOIN listing_tiers lt ON lt.service_id = s.id
        WHERE  $sql_where
        ORDER BY $orderSql
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($data_sql);
    foreach ($binds as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    return [
        'rows'     => $rows,
        'total'    => $total,
        'pages'    => $pages,
        'page'     => $page,
        'per_page' => $per_page,
    ];
}

/** Normalise directory GET params for pagination / links (drops empty defaults). */
function directory_params_for_url(array $p): array
{
    $out = [];
    foreach (['q', 'type', 'council', 'health_board', 'sp'] as $k) {
        $v = isset($p[$k]) ? trim((string) $p[$k]) : '';
        if ($v !== '') {
            $out[$k] = $v;
        }
    }
    if (!empty($p['min_grade'])) {
        $out['min_grade'] = (int) $p['min_grade'];
    }
    if (!empty($p['sort']) && (string) $p['sort'] !== 'default') {
        $out['sort'] = (string) $p['sort'];
    }
    $gw = (int) ($p['graded_within'] ?? 0);
    if ($gw > 0) {
        $out['graded_within'] = $gw;
    }
    $minAvg = trim((string) ($p['min_avg'] ?? ''));
    if (in_array($minAvg, ['3.5', '4', '4.5', '5'], true)) {
        $out['min_avg'] = $minAvg;
    }
    if (isset($p['page']) && (int) $p['page'] > 1) {
        $out['page'] = (int) $p['page'];
    }

    return $out;
}

// Fetch a single service by cs_number
function get_service(string $cs_number): ?array {
    $stmt = db()->prepare("
        SELECT s.*, lt.tier, lt.tagline, lt.description, lt.photo_urls,
               lt.vacancy_count, lt.weekly_fee_from, lt.weekly_fee_to,
               lt.website_url, lt.enquiry_email, lt.approved AS listing_approved
        FROM   services s
        LEFT JOIN listing_tiers lt ON lt.service_id = s.id
        WHERE  s.cs_number = ?
    ");
    $stmt->execute([$cs_number]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Other active, public services from the same provider at the same address —
// the Care Inspectorate issues a separate CS number per regulated service
// type, so one team/building can genuinely hold several registrations
// (e.g. a "Housing Support Service" and a "Support Service" run from the
// same address). Surfaced on the service page so this reads as normal
// rather than looking like a duplicate/bug. Requires a non-empty postcode
// so services with redacted addresses (e.g. childminders) never match.
function get_sibling_registrations(array $service, PDO $pdo): array {
    if (empty($service['sp_number']) || empty($service['postcode'])) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT cs_number, service_name, care_service, subtype
        FROM services
        WHERE sp_number = ?
          AND postcode = ?
          AND cs_number != ?
          AND service_status = 'Active'
          AND public_list = 1
        ORDER BY service_name
    ");
    $stmt->execute([$service['sp_number'], $service['postcode'], $service['cs_number']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Send a simple email (PHPMailer recommended for production — this uses mail() for simplicity)
function send_email(string $to, string $subject, string $body): bool {
    $cfg = load_app_config();
    $headers = "From: {$cfg['from_name']} <{$cfg['from_email']}>\r\nContent-Type: text/html; charset=UTF-8";
    return mail($to, $subject, $body, $headers);
}

// Pagination HTML (compact: prev/next + window around current, not 1..N links)
function paginate(int $total, int $page, int $pages, array $params): string
{
    if ($pages <= 1) {
        return '';
    }

    $hrefFor = function (int $p) use ($params): string {
        $q = array_merge($params, ['page' => $p]);

        return '?' . http_build_query(directory_params_for_url($q));
    };

    $nums = [1, $pages];
    $win = 2;
    $start = max(2, $page - $win);
    $end = min($pages - 1, $page + $win);
    for ($i = $start; $i <= $end; $i++) {
        $nums[] = $i;
    }
    $nums = array_values(array_unique($nums));
    sort($nums, SORT_NUMERIC);

    $html = '<nav class="pagination" aria-label="Pages">';
    $html .= '<span class="pagination__meta">Page ' . $page . ' of ' . $pages . '</span>';
    if ($page > 1) {
        $html .= '<a class="pagination__step" rel="prev" href="' . h($hrefFor($page - 1)) . '">←</a>';
    }

    $prev = 0;
    foreach ($nums as $p) {
        if ($prev > 0 && $p > $prev + 1) {
            $html .= '<span class="pagination__ellipsis" aria-hidden="true">…</span>';
        }
        $href = h($hrefFor($p));
        if ($p === $page) {
            $html .= '<span class="active" aria-current="page">' . $p . '</span>';
        } else {
            $html .= '<a href="' . $href . '">' . $p . '</a>';
        }
        $prev = $p;
    }

    if ($page < $pages) {
        $html .= '<a class="pagination__step" rel="next" href="' . h($hrefFor($page + 1)) . '">→</a>';
    }

    return $html . '</nav>';
}

// Validate & sanitise a postcode search term
function clean_postcode(string $s): string {
    return strtoupper(preg_replace('/[^A-Z0-9 ]/i', '', $s));
}

// ── CI API shared POST helper ─────────────────────────────────
function ci_post(string $endpoint, string $cs_number): string|false {
    $url  = 'https://www.careinspectorate.com/berengCareservices/html/' . $endpoint . '.html.php';
    $body = "service_number='" . $cs_number . "'";
    $ua   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
    $hdrs = [
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: ' . $ua,
        'Referer: https://www.careinspectorate.com/index.php/care-services',
    ];

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => implode("\r\n", $hdrs),
        'content' => $body,
        'timeout' => 8,
    ]]);
    $html = @file_get_contents($url, false, $ctx);
    if ($html !== false && strlen($html) > 5) return $html;

    if (extension_loaded('curl')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => $hdrs,
        ]);
        $html = curl_exec($ch);
        if ($html && strlen($html) > 5) return $html;
    }
    return false;
}

// ── CI cache helper ───────────────────────────────────────────
function ci_cache_get(string $cs_number, string $key): mixed {
    $file = (defined('ROOT') ? ROOT : __DIR__ . '/..') . '/storage/ci_reports/'
          . strtoupper($cs_number) . '_' . $key . '.json';
    if (is_file($file) && (time() - filemtime($file)) < 86400) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data)) return $data;
    }
    return null;
}

function ci_cache_set(string $cs_number, string $key, array $data): void {
    $dir  = (defined('ROOT') ? ROOT : __DIR__ . '/..') . '/storage/ci_reports';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/' . strtoupper($cs_number) . '_' . $key . '.json', json_encode($data));
}

/**
 * Fetch inspection reports for a service from the Care Inspectorate.
 * Results are cached for 24 hours in storage/ci_reports/.
 * Returns array of ['date'=>'', 'title'=>'', 'url'=>''] or [] on failure.
 */
function get_ci_inspection_reports(string $cs_number): array {
    if (!preg_match('/^CS\d+$/i', $cs_number)) return [];
    $cached = ci_cache_get($cs_number, 'reports');
    if ($cached !== null) return $cached;

    $html = ci_post('ReportDataDetails', $cs_number);
    if (!$html) return [];

    preg_match_all(
        '/<tr>\s*<td>(.+?)<\/td>\s*<td>(.+?)<\/td>\s*<td>\s*<a href="([^"]+)"/',
        $html, $matches, PREG_SET_ORDER
    );
    $reports = [];
    foreach ($matches as $m) {
        $reports[] = [
            'date'  => trim(strip_tags($m[1])),
            'title' => trim(strip_tags($m[2])),
            'url'   => $m[3],
        ];
    }
    if (!empty($reports)) ci_cache_set($cs_number, 'reports', $reports);
    return $reports;
}

/**
 * Fetch full grade history per inspection from the Care Inspectorate.
 * Returns two arrays: 'new' (2021+ framework) and 'old' (pre-2021 framework).
 * Each entry: ['date'=>'YYYY-MM-DD', 'avg'=>float|null, grades...]
 */
function get_ci_quality_grades(string $cs_number): array {
    if (!preg_match('/^CS\d+$/i', $cs_number)) return [];
    $cached = ci_cache_get($cs_number, 'grades');
    if ($cached !== null) return $cached;

    $html = ci_post('QualityGrades', $cs_number);
    if (!$html) return [];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // Helper: extract numeric grade from a table cell node
    $grade_val = function(DOMNode $td): ?int {
        $raw  = $td->ownerDocument->saveHTML($td);
        // New framework: "5<br>Very Good<br>(I)" — first digit before <br>
        if (preg_match('/>(\d)<br/i', $raw, $m)) return (int)$m[1];
        // Old framework: "5 - Very Good (I)"
        $text = trim(strip_tags($raw));
        if (preg_match('/^(\d+)\s*[-–]/', $text, $m) && (int)$m[1] > 0) return (int)$m[1];
        return null;
    };

    $parse_date = function(string $s): string {
        $dt = DateTime::createFromFormat('d M Y', trim($s))
           ?: DateTime::createFromFormat('j M Y', trim($s));
        return $dt ? $dt->format('Y-m-d') : trim($s);
    };

    $result = ['new' => [], 'old' => []];

    // ── New framework (div#grades_content_fq, second table) ───
    $new_tables = $xpath->query("//div[@id='grades_content_fq']//table");
    if ($new_tables && $new_tables->length >= 2) {
        $tbl = $new_tables->item(1);
        // Map column index → field name from header text
        $col_map = [];
        $headers = $xpath->query('.//th', $tbl);
        $col_keywords = [
            'wellbeing' => 'wellbeing',
            'leadership' => 'leadership',
            'staff team' => 'staff',
            'setting' => 'setting',
            'care and support plan' => 'planning',
            'care, play' => 'cpl',
        ];
        foreach ($headers as $i => $th) {
            $txt = strtolower(trim($th->textContent));
            foreach ($col_keywords as $kw => $field) {
                if (str_contains($txt, $kw)) { $col_map[$i] = $field; break; }
            }
        }

        $rows = $xpath->query('.//tr[not(ancestor::thead)]', $tbl);
        foreach ($rows as $row) {
            $cells = $row->childNodes;
            $tds = [];
            foreach ($cells as $c) { if ($c->nodeName === 'td') $tds[] = $c; }
            if (empty($tds)) continue;
            $date   = $parse_date($tds[0]->textContent);
            $grades = [];
            foreach ($col_map as $idx => $field) {
                if (isset($tds[$idx])) $grades[$field] = $grade_val($tds[$idx]);
            }
            $vals = array_filter($grades, fn($v) => $v !== null);
            $result['new'][] = [
                'date' => $date,
                'avg'  => !empty($vals) ? round(array_sum($vals) / count($vals), 2) : null,
            ] + $grades;
        }
    }

    // ── Old framework (div#grades_content, first table) ───────
    $old_tables = $xpath->query("//div[@id='grades_content']//table");
    if ($old_tables && $old_tables->length > 0) {
        $tbl  = $old_tables->item(0);
        $rows = $xpath->query('.//tr[not(ancestor::thead)]', $tbl);
        foreach ($rows as $row) {
            $tds = [];
            foreach ($row->childNodes as $c) { if ($c->nodeName === 'td') $tds[] = $c; }
            if (count($tds) < 2) continue;
            $date = $parse_date($tds[0]->textContent);
            $g = [
                'care_support' => isset($tds[1]) ? $grade_val($tds[1]) : null,
                'staffing'     => isset($tds[2]) ? $grade_val($tds[2]) : null,
                'management'   => isset($tds[3]) ? $grade_val($tds[3]) : null,
            ];
            $vals = array_filter($g, fn($v) => $v !== null);
            $result['old'][] = [
                'date' => $date,
                'avg'  => !empty($vals) ? round(array_sum($vals) / count($vals), 2) : null,
            ] + $g;
        }
    }

    if (!empty($result['new']) || !empty($result['old'])) {
        ci_cache_set($cs_number, 'grades', $result);
    }
    return $result;
}

/**
 * Fetch complaint history from the Care Inspectorate, including outcome per case.
 * Results are cached in a file (24 hr) and persisted to the complaints table.
 */
function get_ci_complaints(string $cs_number): array {
    if (!preg_match('/^CS\d+$/i', $cs_number)) return [];
    $cs_number = strtoupper($cs_number);

    $cached = ci_cache_get($cs_number, 'complaints');
    if ($cached !== null) return $cached;

    $html = ci_post('ComplaintsDetails', $cs_number);
    if (!$html) return [];

    preg_match_all(
        '/<tr[^>]*>\s*<td[^>]*>([^<]+)<\/td>\s*<td[^>]*>([^<]+)<\/td>\s*<td[^>]*>([^<]+)<\/td>/i',
        $html, $matches, PREG_SET_ORDER
    );
    $complaints = [];
    foreach ($matches as $m) {
        $date = trim($m[1]);
        if (!$date || str_starts_with(strtolower($date), 'date') || str_starts_with($date, 'If')) continue;
        $case_number = trim(strip_tags($m[2]));
        $complaints[] = [
            'date'        => $date,
            'case_number' => $case_number,
            'category'    => trim(strip_tags($m[3])),
            'outcome'     => _ci_fetch_complaint_outcome($cs_number, $case_number),
        ];
    }
    ci_cache_set($cs_number, 'complaints', $complaints);
    _ci_complaints_persist($cs_number, $complaints);
    return $complaints;
}

/**
 * Fetch the outcome (Upheld / Not Upheld / etc.) for a single complaint case
 * from the CI ComplaintsSubTypes endpoint.
 */
function _ci_fetch_complaint_outcome(string $cs_number, string $case_number): ?string {
    $url  = 'https://www.careinspectorate.com/berengCareservices/html/ComplaintsSubTypes.html.php';
    $body = 'compId=' . rawurlencode($cs_number) . '&caseNumber=' . rawurlencode($case_number) . '&subCompNo=0&rowNo=0';
    $ua   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
    $hdrs = [
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: ' . $ua,
        'Referer: https://www.careinspectorate.com/index.php/care-services',
    ];

    $html = false;
    $ctx  = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => implode("\r\n", $hdrs),
        'content' => $body,
        'timeout' => 6,
    ]]);
    $html = @file_get_contents($url, false, $ctx);

    if ((!$html || strlen($html) < 10) && extension_loaded('curl')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => $hdrs,
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
    }

    if (!$html) return null;

    // The sub-complaint detail section always contains:
    // <th ...>Outcome:&nbsp;</th><td>Upheld&nbsp;</td>
    if (preg_match('/<th[^>]*>Outcome:(?:&nbsp;)?\s*<\/th>\s*<td[^>]*>([^<]+)/i', $html, $m)) {
        $outcome = trim(str_replace(['&nbsp;', "\xc2\xa0"], '', $m[1]));
        return $outcome !== '' ? $outcome : null;
    }
    return null;
}

/**
 * Upsert scraped complaint rows into the complaints table.
 * Silently skips if the table does not yet exist.
 */
function _ci_complaints_persist(string $cs_number, array $complaints): void {
    if (empty($complaints)) return;
    try {
        $pdo  = db();
        $stmt = $pdo->prepare('SELECT sp_number FROM services WHERE cs_number = ? LIMIT 1');
        $stmt->execute([$cs_number]);
        $sp = (string) ($stmt->fetchColumn() ?: '');

        $ins = $pdo->prepare("
            INSERT INTO complaints (cs_number, sp_number, case_number, complaint_date, category, outcome)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE category = VALUES(category), outcome = VALUES(outcome), fetched_at = NOW()
        ");
        foreach ($complaints as $c) {
            $dt = DateTime::createFromFormat('d M Y', $c['date'])
               ?: DateTime::createFromFormat('j M Y', $c['date'])
               ?: DateTime::createFromFormat('Y-m-d', $c['date']);
            $ins->execute([
                $cs_number,
                $sp ?: null,
                $c['case_number'],
                $dt ? $dt->format('Y-m-d') : null,
                $c['category'],
                $c['outcome'] ?? null,
            ]);
        }
    } catch (PDOException) {
        // complaints table may not exist yet — silently skip
    }
}

/**
 * Detailed per-case complaint rows for one service, read from the local
 * `complaints` table rather than live-scraping the Care Inspectorate site.
 * (The old scrape target, careinspectorate.com's legacy ComplaintsDetails
 * endpoint, now redirects to careinspectorate.scot and returns HTTP 419 —
 * it requires a browser session/CSRF token a server-side POST can't
 * supply, so it always failed silently. Any rows here were captured
 * before that endpoint broke, or via a future replacement scraper.)
 */
function get_complaints_from_db(string $cs_number): array {
    $stmt = db()->prepare("
        SELECT case_number, complaint_date AS date, category, outcome
        FROM complaints
        WHERE cs_number = ?
        ORDER BY complaint_date DESC
    ");
    $stmt->execute([$cs_number]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Aggregate complaints stats for one provider, sourced from the services table
 * (always available from CSV import). Returns total upheld, not_upheld, and
 * which services have complaints, ordered by most complaints first.
 */
function get_provider_complaints_stats(string $sp): array {
    $pdo  = db();
    $stmt = $pdo->prepare("
        SELECT
            SUM(complaints_upheld)                               AS total_upheld,
            COUNT(CASE WHEN complaints_upheld > 0 THEN 1 END)   AS services_with_complaints
        FROM services
        WHERE sp_number = ? AND service_status = 'Active'
    ");
    $stmt->execute([$sp]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("
        SELECT cs_number, service_name, care_service, council_area, town,
               complaints_upheld
        FROM services
        WHERE sp_number = ? AND complaints_upheld > 0
        ORDER BY complaints_upheld DESC, service_name ASC
    ");
    $stmt->execute([$sp]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'total_upheld'             => (int) ($totals['total_upheld']            ?? 0),
        'services_with_complaints' => (int) ($totals['services_with_complaints'] ?? 0),
        'rows'                     => $rows,
    ];
}

/**
 * Search services with complaints for the hub page.
 * Uses aggregate counts from services (always available) — not the detail table.
 */
function search_complaints(array $params = [], int $page = 1, int $per_page = 50): array {
    $pdo   = db();
    $where = ["s.complaints_upheld > 0", "s.public_list = 1"];
    $binds = [];

    if (!empty($params['council'])) {
        $where[] = 's.council_area = :council';
        $binds[':council'] = $params['council'];
    }
    if (!empty($params['type'])) {
        $where[] = 's.care_service = :type';
        $binds[':type'] = $params['type'];
    }
    if (!empty($params['q'])) {
        $where[] = '(s.service_name LIKE :q OR s.provider_name LIKE :q2)';
        $like = '%' . addcslashes($params['q'], '%_\\') . '%';
        $binds[':q']  = $like;
        $binds[':q2'] = $like;
    }
    if (!empty($params['sp'])) {
        $where[] = 's.sp_number = :sp';
        $binds[':sp'] = $params['sp'];
    }
    if (!empty($params['status']) && $params['status'] === 'active') {
        $where[] = "s.service_status = 'Active'";
    }

    $whereSql = implode(' AND ', $where);

    $sort     = $params['sort'] ?? 'most';
    $orderSql = match ($sort) {
        'name_az' => 's.service_name ASC',
        'council' => 's.council_area ASC, s.service_name ASC',
        default   => 's.complaints_upheld DESC, s.service_name ASC',
    };

    $countSql = "SELECT COUNT(*) FROM services s WHERE $whereSql";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($binds);
    $total = (int) $stmt->fetchColumn();

    $pages  = $total > 0 ? (int) ceil($total / $per_page) : 1;
    $page   = min(max(1, $page), $pages);
    $offset = ($page - 1) * $per_page;

    $dataSql = "
        SELECT s.cs_number, s.service_name, s.care_service, s.council_area, s.town,
               s.sp_number, s.provider_name, s.service_status,
               s.complaints_upheld
        FROM   services s
        WHERE  $whereSql
        ORDER BY $orderSql
        LIMIT  :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($dataSql);
    foreach ($binds as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'rows'     => $rows,
        'total'    => $total,
        'pages'    => $pages,
        'page'     => $page,
        'per_page' => $per_page,
    ];
}

/**
 * Compute grade comparisons for a service against its provider,
 * local peers (same type + council), and national peers (same type).
 */
function get_service_comparisons(array $service, PDO $pdo): array {
    $cs   = $service['cs_number'];
    $sp   = $service['sp_number'] ?? null;
    $type = $service['care_service'] ?? null;
    $area = $service['council_area'] ?? null;

    $cols = "ROUND(AVG(NULLIF(grade_wellbeing,0)),2) as wellbeing,
             ROUND(AVG(NULLIF(grade_leadership,0)),2) as leadership,
             ROUND(AVG(NULLIF(grade_staff,0)),2)     as staff,
             ROUND(AVG(NULLIF(grade_setting,0)),2)   as setting,
             ROUND(AVG(NULLIF(grade_planning,0)),2)  as planning,
             ROUND(AVG(NULLIF(grade_cpl,0)),2)       as cpl,
             COUNT(*)                                 as cnt";

    $out = [];

    if ($sp) {
        $stmt = $pdo->prepare("SELECT $cols FROM services WHERE sp_number=? AND service_status='Active' AND cs_number!=?");
        $stmt->execute([$sp, $cs]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['cnt'] > 0) $out['provider'] = $row;
    }

    if ($type && $area) {
        $stmt = $pdo->prepare("SELECT $cols FROM services WHERE care_service=? AND council_area=? AND service_status='Active' AND cs_number!=?");
        $stmt->execute([$type, $area, $cs]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['cnt'] >= 2) $out['local'] = $row;
    }

    if ($type) {
        $stmt = $pdo->prepare("SELECT $cols FROM services WHERE care_service=? AND service_status='Active' AND cs_number!=?");
        $stmt->execute([$type, $cs]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['cnt'] >= 5) $out['national'] = $row;
    }

    return $out;
}
