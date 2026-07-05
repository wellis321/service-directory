<?php
declare(strict_types=1);

require_once __DIR__ . '/council_centroids.php';

/**
 * Build council marker data and JSON config for the Leaflet map.
 *
 * @param array{q?: string, type?: string, min_grade?: int, min_avg?: string, council?: string, sp?: string, sort?: string, graded_within?: int} $query Passed through to directory URLs when a marker is clicked.
 * @return array{markers: list<array<string, mixed>>, json: string, has_markers: bool}
 */
function council_map_prepare(PDO $pdo, array $query): array
{
    $sortQ = trim((string) ($query['sort'] ?? 'default'));
    if (!in_array($sortQ, ['default', 'grades_recent', 'avg_high', 'avg_low'], true)) {
        $sortQ = 'default';
    }
    $minAvgQ = trim((string) ($query['min_avg'] ?? ''));
    if (!in_array($minAvgQ, ['', '3.5', '4', '4.5', '5'], true)) {
        $minAvgQ = '';
    }
    $gwQ = (int) ($query['graded_within'] ?? 0);
    if (!in_array($gwQ, [0, 90, 180, 365], true)) {
        $gwQ = 0;
    }
    $spQ = trim((string) ($query['sp'] ?? ''));
    if ($spQ !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $spQ)) {
        $spQ = '';
    }

    $councils = $pdo->query(
        "SELECT DISTINCT council_area FROM services WHERE council_area IS NOT NULL ORDER BY council_area"
    )->fetchAll(PDO::FETCH_COLUMN);

    $councilCountsStmt = $pdo->query(
        "SELECT council_area, COUNT(*) AS n FROM services WHERE service_status = 'Active' AND public_list = 1 AND council_area IS NOT NULL AND TRIM(council_area) != '' GROUP BY council_area"
    );
    $councilCounts = [];
    while ($row = $councilCountsStmt->fetch(PDO::FETCH_ASSOC)) {
        $councilCounts[(string) ($row['council_area'] ?? '')] = (int) ($row['n'] ?? 0);
    }

    $selected = trim((string) ($query['council'] ?? ''));
    $markers = [];
    foreach ($councils as $c) {
        if (!is_string($c) || $c === '') {
            continue;
        }
        $cen = council_centroid_for_db_name($c);
        if ($cen === null) {
            continue;
        }
        $markers[] = [
            'name' => $c,
            'lat' => $cen[0],
            'lng' => $cen[1],
            'count' => $councilCounts[$c] ?? 0,
            'selected' => $selected !== '' && $selected === $c,
        ];
    }

    $payload = [
        'markers' => $markers,
        'query' => [
            'q' => (string) ($query['q'] ?? ''),
            'type' => (string) ($query['type'] ?? ''),
            'min_grade' => (int) ($query['min_grade'] ?? 0),
            'min_avg' => $minAvgQ,
            'sp' => $spQ,
            'sort' => $sortQ,
            'graded_within' => $gwQ,
        ],
        'view' => [
            'mode' => 'fixed',
            'lat' => 56.55,
            'lng' => -4.15,
            'zoom' => 6.5,
            'minZoom' => 5,
            'maxZoom' => 11,
        ],
    ];

    return [
        'markers' => $markers,
        'json' => json_encode($payload, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        'has_markers' => $markers !== [],
    ];
}

/** Link to the council map page, preserving optional directory filters. */
function council_map_page_href(array $query): string
{
    $mq = directory_params_for_url($query);
    if ($mq === []) {
        return '/councils';
    }

    return '/councils?' . http_build_query($mq);
}
