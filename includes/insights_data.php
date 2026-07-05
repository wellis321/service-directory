<?php
declare(strict_types=1);

/**
 * Aggregate queries for /insights charts (active, public-listed services only).
 */

function insights_active_where(): string
{
    return "service_status = 'Active' AND public_list = 1";
}

/** @return list<array{type: string, count: int, avg_min: ?float}> */
function insights_services_by_type(PDO $pdo, ?string $council = null, int $limit = 14): array
{
    $w = insights_active_where() . " AND care_service IS NOT NULL AND TRIM(care_service) != ''";
    $bind = [];
    if ($council !== null && $council !== '') {
        $w .= ' AND council_area = :council';
        $bind[':council'] = $council;
    }
    $sql = "SELECT care_service AS type_name, COUNT(*) AS cnt,
            ROUND(AVG(grade_min), 2) AS avg_min
            FROM services WHERE $w
            GROUP BY care_service ORDER BY cnt DESC LIMIT " . (int) $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[] = [
            'type' => (string) ($row['type_name'] ?? ''),
            'count' => (int) ($row['cnt'] ?? 0),
            'avg_min' => $row['avg_min'] !== null && $row['avg_min'] !== '' ? (float) $row['avg_min'] : null,
        ];
    }

    return $out;
}

/** grade_min -> count for histogram / doughnut */
function insights_grade_min_buckets(PDO $pdo, ?string $council = null, ?string $care_service = null): array
{
    $w = insights_active_where() . ' AND grade_min IS NOT NULL';
    $bind = [];
    if ($council !== null && $council !== '') {
        $w .= ' AND council_area = :council';
        $bind[':council'] = $council;
    }
    if ($care_service !== null && $care_service !== '') {
        $w .= ' AND care_service = :cs';
        $bind[':cs'] = $care_service;
    }
    $sql = "SELECT grade_min AS g, COUNT(*) AS c FROM services WHERE $w GROUP BY grade_min ORDER BY grade_min ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(int) $row['g']] = (int) $row['c'];
    }
    $out = [];
    for ($g = 1; $g <= 6; $g++) {
        $out[] = ['grade' => $g, 'count' => $map[$g] ?? 0];
    }

    return $out;
}

/** National avg grade_min by care_service (for comparison tables) */
function insights_national_avg_by_type(PDO $pdo): array
{
    $w = insights_active_where() . " AND care_service IS NOT NULL AND grade_min IS NOT NULL";
    $stmt = $pdo->query(
        "SELECT care_service AS type_name, ROUND(AVG(grade_min), 2) AS avg_min, COUNT(*) AS cnt
         FROM services WHERE $w GROUP BY care_service"
    );
    $by = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $by[(string) $row['type_name']] = [
            'avg_min' => (float) $row['avg_min'],
            'count' => (int) $row['cnt'],
        ];
    }

    return $by;
}

/**
 * In-council stats per type with national benchmark (same type, all Scotland).
 *
 * @return list<array{type: string, council_count: int, council_avg: ?float, national_avg: ?float, national_count: int}>
 */
function insights_council_vs_national_by_type(PDO $pdo, string $council): array
{
    $national = insights_national_avg_by_type($pdo);
    $w = insights_active_where() . ' AND council_area = :council AND care_service IS NOT NULL AND TRIM(care_service) != \'\'';
    $stmt = $pdo->prepare(
        "SELECT care_service AS type_name, COUNT(*) AS cnt,
                ROUND(AVG(grade_min), 2) AS avg_min
         FROM services WHERE $w
         GROUP BY care_service
         HAVING cnt >= 1
         ORDER BY cnt DESC
         LIMIT 24"
    );
    $stmt->execute([':council' => $council]);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $t = (string) ($row['type_name'] ?? '');
        $nat = $national[$t] ?? null;
        $out[] = [
            'type' => $t,
            'council_count' => (int) ($row['cnt'] ?? 0),
            'council_avg' => $row['avg_min'] !== null && $row['avg_min'] !== '' ? (float) $row['avg_min'] : null,
            'national_avg' => $nat['avg_min'] ?? null,
            'national_count' => $nat['count'] ?? 0,
        ];
    }

    return $out;
}

/** Top councils for a given service type */
function insights_type_top_councils(PDO $pdo, string $care_service, int $limit = 16): array
{
    $w = insights_active_where() . ' AND care_service = :cs AND council_area IS NOT NULL AND TRIM(council_area) != \'\'';
    $stmt = $pdo->prepare(
        "SELECT council_area AS council, COUNT(*) AS cnt,
                ROUND(AVG(grade_min), 2) AS avg_min
         FROM services WHERE $w
         GROUP BY council_area
         ORDER BY cnt DESC
         LIMIT " . (int) $limit
    );
    $stmt->execute([':cs' => $care_service]);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[] = [
            'council' => (string) ($row['council'] ?? ''),
            'count' => (int) ($row['cnt'] ?? 0),
            'avg_min' => $row['avg_min'] !== null && $row['avg_min'] !== '' ? (float) $row['avg_min'] : null,
        ];
    }

    return $out;
}

/** @return array<string, mixed>|null */
function insights_provider_bundle(PDO $pdo, string $sp): ?array
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS n, MAX(provider_name) AS provider_name,
                COUNT(DISTINCT council_area) AS n_councils
         FROM services WHERE ' . insights_active_where() . ' AND sp_number = ?'
    );
    $stmt->execute([$sp]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) ($row['n'] ?? 0) < 1) {
        return null;
    }
    $national = insights_national_avg_by_type($pdo);
    $stmt = $pdo->prepare(
        "SELECT care_service AS type_name, COUNT(*) AS cnt,
                ROUND(AVG(grade_min), 2) AS avg_min
         FROM services WHERE " . insights_active_where() . ' AND sp_number = ?
         GROUP BY care_service
         ORDER BY cnt DESC'
    );
    $stmt->execute([$sp]);
    $byType = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $t = (string) ($r['type_name'] ?? '');
        $nat = $national[$t] ?? null;
        $byType[] = [
            'type' => $t,
            'count' => (int) ($r['cnt'] ?? 0),
            'provider_avg' => $r['avg_min'] !== null && $r['avg_min'] !== '' ? (float) $r['avg_min'] : null,
            'national_avg' => $nat['avg_min'] ?? null,
            'national_count' => $nat['count'] ?? 0,
        ];
    }

    return [
        'sp' => $sp,
        'provider_name' => (string) ($row['provider_name'] ?? ''),
        'n_services' => (int) ($row['n'] ?? 0),
        'n_councils' => (int) ($row['n_councils'] ?? 0),
        'by_type' => $byType,
    ];
}

/** SP numbers for hub dropdown (larger providers first) */
function insights_provider_picklist(PDO $pdo, int $limit = 120): array
{
    $stmt = $pdo->query(
        'SELECT sp_number AS sp, MAX(provider_name) AS provider_name, COUNT(*) AS c
         FROM services WHERE ' . insights_active_where() . "
           AND sp_number IS NOT NULL AND TRIM(sp_number) != ''
         GROUP BY sp_number
         ORDER BY c DESC
         LIMIT " . (int) $limit
    );
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[] = [
            'sp' => (string) ($row['sp'] ?? ''),
            'name' => (string) ($row['provider_name'] ?? ''),
            'count' => (int) ($row['c'] ?? 0),
        ];
    }

    return $out;
}
