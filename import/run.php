<?php
/**
 * Legacy importer — targets root schema.sql (different columns than sql/schema.sql).
 * For the current app (public/, listing_tiers), use: php cron/import.php
 */
// import/run.php — Care Inspectorate CSV importer
// Run via browser (admin only) or set up as a monthly cron job:
//   0 6 1 * * /usr/bin/php /path/to/your/site/import/run.php

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
$cfg = load_app_config();

// Increase limits for large CSV
set_time_limit(300);
ini_set('memory_limit', '256M');

// CLI or web?
$is_cli = (PHP_SAPI === 'cli');

function log_msg(string $msg): void {
    global $is_cli;
    $line = '[' . date('H:i:s') . '] ' . $msg;
    if ($is_cli) {
        echo $line . PHP_EOL;
    } else {
        echo '<p>' . htmlspecialchars($line) . '</p>';
        if (ob_get_level()) ob_flush();
        flush();
    }
}

// ---------------------------------------------------------
// Column mapping: CSV header → our DB column
// Based on actual Care Inspectorate Datastore CSV structure
// ---------------------------------------------------------
const COL_MAP = [
    'CSNumber'              => 'cs_number',
    'SP_number'             => 'sp_number',
    'ServiceName'           => 'service_name',
    'CareService'           => 'care_service',
    'Subtype'               => 'subtype',
    'ServiceType'           => 'service_type',
    'ServiceStatus'         => 'service_status',
    'Address_line_1'        => 'address_1',
    'Address_line_2'        => 'address_2',
    'Address_line_3'        => 'address_3',
    'Address_line_4'        => 'address_4',
    'Service_town'          => 'town',
    'Service_Postcode'      => 'postcode',
    'ChopUPRN'              => 'uprn',
    'Manager_Name'          => 'manager_name',
    'Service_Phone_Number'  => 'phone',
    'Eforms_email_address'  => 'email',
    'ServiceProvider'       => 'provider_name',
    'Provided_by_Local_Authority' => 'local_authority_run',
    'Council_Area_Name'     => 'council_area',
    'Health_Board_Name'     => 'health_board',
    'Integration_Authority_Name' => 'integration_authority',
    'SIMD_rank'             => 'simd_rank',
    'SIMD2020_Decile'       => 'simd_decile',
    'Datazone'              => 'datazone',
    'TotalBeds'             => 'total_beds',
    'SingleBedrooms'        => 'single_bedrooms',
    'BedsInDoubleRooms'     => 'beds_double',
    'BedsInBedroomsFor3OrMore' => 'beds_shared',
    'Registered_Places'     => 'registered_places',
    'NumberStaff'           => 'num_staff',
    'Client_group'          => 'client_group',
    'KQ_Support_Wellbeing'  => 'grade_wellbeing',
    'KQ_Care_and_Support_Planning' => 'grade_planning',
    'KQ_Setting'            => 'grade_setting',
    'KQ_Staff_Team'         => 'grade_staff',
    'KQ_Leadership'         => 'grade_leadership',
    'KQ_Care_Play_and_Learning' => 'grade_care_play',
    'MinGrade'              => 'grade_min',
    'MaxGrade'              => 'grade_max',
    'GradeSpread'           => 'grade_spread',
    'Publication_of_Latest_Grading' => 'latest_grade_date',
    'Date_Reg'              => 'date_registered',
];

// ---------------------------------------------------------
// Main import
// ---------------------------------------------------------
function run_import(string $csv_path_or_url): array {
    $pdo = db();

    // Log to import_log table
    $stmt = $pdo->prepare("INSERT INTO import_log (status, source_url) VALUES ('running', ?)");
    $stmt->execute([$csv_path_or_url]);
    $log_id = $pdo->lastInsertId();

    $stats = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'cancelled' => 0];

    try {
        log_msg("Opening CSV: $csv_path_or_url");

        // Open stream (handles both local file and URL)
        $stream = @fopen($csv_path_or_url, 'r');
        if (!$stream) throw new Exception("Cannot open CSV source: $csv_path_or_url");

        // Read header row
        $headers = fgetcsv($stream);
        if (!$headers) throw new Exception("CSV appears empty");

        // Trim BOM and whitespace from headers
        $headers = array_map(fn($h) => trim($h, "\xEF\xBB\xBF \t"), $headers);
        log_msg("CSV headers found: " . count($headers) . " columns");

        // Track which cs_numbers are in this import
        $seen_cs = [];

        // Prepare upsert statement
        $upsert = $pdo->prepare("
            INSERT INTO services
                (cs_number, sp_number, service_name, care_service, subtype, service_type,
                 service_status, address_1, address_2, address_3, address_4, town, postcode, uprn,
                 manager_name, phone, email, provider_name, local_authority_run,
                 council_area, health_board, integration_authority, simd_rank, simd_decile, datazone,
                 total_beds, single_bedrooms, beds_double, beds_shared, registered_places, num_staff, client_group,
                 grade_wellbeing, grade_planning, grade_setting, grade_staff, grade_leadership, grade_care_play,
                 grade_min, grade_max, grade_spread, latest_grade_date, date_registered, last_import_at)
            VALUES
                (:cs_number, :sp_number, :service_name, :care_service, :subtype, :service_type,
                 :service_status, :address_1, :address_2, :address_3, :address_4, :town, :postcode, :uprn,
                 :manager_name, :phone, :email, :provider_name, :local_authority_run,
                 :council_area, :health_board, :integration_authority, :simd_rank, :simd_decile, :datazone,
                 :total_beds, :single_bedrooms, :beds_double, :beds_shared, :registered_places, :num_staff, :client_group,
                 :grade_wellbeing, :grade_planning, :grade_setting, :grade_staff, :grade_leadership, :grade_care_play,
                 :grade_min, :grade_max, :grade_spread, :latest_grade_date, :date_registered, NOW())
            ON DUPLICATE KEY UPDATE
                service_name = VALUES(service_name),
                care_service = VALUES(care_service),
                subtype = VALUES(subtype),
                service_status = VALUES(service_status),
                address_1 = VALUES(address_1), address_2 = VALUES(address_2),
                town = VALUES(town), postcode = VALUES(postcode),
                manager_name = VALUES(manager_name), phone = VALUES(phone), email = VALUES(email),
                provider_name = VALUES(provider_name),
                council_area = VALUES(council_area), health_board = VALUES(health_board),
                total_beds = VALUES(total_beds), registered_places = VALUES(registered_places),
                num_staff = VALUES(num_staff), client_group = VALUES(client_group),
                grade_wellbeing = VALUES(grade_wellbeing), grade_planning = VALUES(grade_planning),
                grade_setting = VALUES(grade_setting), grade_staff = VALUES(grade_staff),
                grade_leadership = VALUES(grade_leadership), grade_care_play = VALUES(grade_care_play),
                grade_min = VALUES(grade_min), grade_max = VALUES(grade_max),
                latest_grade_date = VALUES(latest_grade_date),
                last_import_at = NOW()
        ");

        $pdo->beginTransaction();

        while (($row = fgetcsv($stream)) !== false) {
            if (count($row) < 3) continue; // skip blank lines

            // Map CSV columns to named keys
            $data = [];
            foreach ($headers as $i => $header) {
                $col = COL_MAP[$header] ?? null;
                if ($col) $data[$col] = trim($row[$i] ?? '');
            }

            if (empty($data['cs_number'])) continue;

            // Sanitise / cast
            $data['local_authority_run'] = (strtolower($data['local_authority_run'] ?? '') === 'yes') ? 1 : 0;

            // Nullable integers
            foreach (['simd_rank','simd_decile','total_beds','single_bedrooms','beds_double','beds_shared',
                      'registered_places','num_staff',
                      'grade_wellbeing','grade_planning','grade_setting','grade_staff',
                      'grade_leadership','grade_care_play','grade_min','grade_max'] as $col) {
                $data[$col] = is_numeric($data[$col] ?? '') ? (int)$data[$col] : null;
            }

            // Nullable dates
            foreach (['latest_grade_date','date_registered'] as $col) {
                $d = $data[$col] ?? '';
                if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $d)) {
                    [$day,$mon,$yr] = explode('/', $d);
                    $data[$col] = "$yr-$mon-$day";
                } elseif (preg_match('/^\d{4}_\d{1,2}$/', $d)) {
                    [$yr,$mon] = explode('_', $d);
                    $data[$col] = sprintf('%04d-%02d-01', $yr, $mon);
                } else {
                    $data[$col] = null;
                }
            }

            $upsert->execute($data);
            $affected = $upsert->rowCount();
            if ($affected === 1) $stats['inserted']++;
            elseif ($affected === 2) $stats['updated']++;

            $seen_cs[] = $data['cs_number'];
            $stats['processed']++;

            // Commit in batches of 500 for performance
            if ($stats['processed'] % 500 === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
                log_msg("  Processed: {$stats['processed']} rows...");
            }
        }

        $pdo->commit();
        fclose($stream);

        // Mark services not in this import as cancelled
        if (!empty($seen_cs)) {
            $placeholders = implode(',', array_fill(0, count($seen_cs), '?'));
            $cancel = $pdo->prepare("
                UPDATE services SET service_status = 'Cancelled'
                WHERE service_status = 'Active'
                AND cs_number NOT IN ($placeholders)
            ");
            $cancel->execute($seen_cs);
            $stats['cancelled'] = $cancel->rowCount();
            if ($stats['cancelled']) log_msg("Marked {$stats['cancelled']} services as cancelled");
        }

        // Update log
        $pdo->prepare("
            UPDATE import_log SET status='success', finished_at=NOW(),
            rows_processed=?, rows_inserted=?, rows_updated=?, rows_cancelled=?
            WHERE id=?
        ")->execute([$stats['processed'], $stats['inserted'], $stats['updated'], $stats['cancelled'], $log_id]);

        log_msg("✓ Import complete. Processed: {$stats['processed']}, New: {$stats['inserted']}, Updated: {$stats['updated']}, Cancelled: {$stats['cancelled']}");

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare("UPDATE import_log SET status='failed', finished_at=NOW(), error_message=? WHERE id=?")
            ->execute([$e->getMessage(), $log_id]);
        log_msg("ERROR: " . $e->getMessage());
        throw $e;
    }

    return $stats;
}

// ---------------------------------------------------------
// Entry point
// ---------------------------------------------------------
if (!$is_cli) {
    // Web: require admin session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['admin_id'])) {
        header('Location: ../admin/login.php');
        exit;
    }
    echo '<html><body style="font-family:monospace;padding:1rem">';
    echo '<h2>Running import...</h2>';
}

// Allow overriding CSV URL via CLI argument: php run.php /path/to/local.csv
$source = $argv[1] ?? ($cfg['ci_csv_url'] ?? '');

try {
    run_import($source);
} catch (Throwable $e) {
    exit(1);
}

if (!$is_cli) {
    echo '<p><a href="../admin/">← Back to admin</a></p></body></html>';
}
