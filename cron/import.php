<?php
// =============================================================
// cron/import.php — Monthly Care Inspectorate CSV importer
// =============================================================
// Run via cron on your hosting control panel, e.g.:
//   0 6 1 * * php /path/to/carescotland/cron/import.php
// Or trigger manually from admin/import.php
// =============================================================

defined('ROOT') || define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';

$cfg = load_app_config();

$log_id  = null;
$pdo     = db();

if (!function_exists('log_msg')) {
    function log_msg(string $msg): void {
        echo date('[Y-m-d H:i:s]') . ' ' . $msg . PHP_EOL;
    }
}

/** Strip / replace bytes MySQL utf8mb4 rejects (CSV sometimes has mojibake or lone continuation bytes). */
function ci_csv_utf8(string $s): string
{
    $s = str_replace("\0", '', $s);
    if ($s === '') {
        return '';
    }
    if (mb_check_encoding($s, 'UTF-8')) {
        $out = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        return $out !== false ? $out : '';
    }
    $fromCp = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
    if ($fromCp !== false && $fromCp !== '') {
        return $fromCp;
    }
    $fromLat = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $s);
    if ($fromLat !== false) {
        return $fromLat;
    }
    $out = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    return $out !== false ? $out : '';
}

/**
 * Care Inspectorate often returns 403 to bare PHP user-agents; send browser-like headers.
 * Optional: php cron/import.php /path/to/DatastoreExternal.csv
 */
function fetch_ci_csv(string $url): string
{
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
    $hdr = [
        'User-Agent: ' . $ua,
        'Accept: text/csv,text/plain,*/*;q=0.8',
        'Accept-Language: en-GB,en;q=0.9',
        'Referer: https://www.careinspectorate.com/',
    ];
    $ctx = stream_context_create([
        'http' => [
            'timeout'         => 120,
            'follow_location' => 1,
            'max_redirects'   => 5,
            'header'          => implode("\r\n", $hdr),
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data !== false && strlen($data) > 500) {
        return $data;
    }

    if (extension_loaded('curl')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/csv,text/plain,*/*',
                'Accept-Language: en-GB,en;q=0.9',
                'Referer: https://www.careinspectorate.com/',
            ],
            CURLOPT_USERAGENT      => $ua,
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($data !== false && $code >= 200 && $code < 300 && strlen($data) > 500) {
            return $data;
        }
        throw new RuntimeException("CSV download failed (HTTP {$code})" . ($cerr !== '' ? ": {$cerr}" : ''));
    }

    throw new RuntimeException(
        'CSV download failed (blocked or empty). Download the CSV in a browser from the Care Inspectorate Datastore, then run: php cron/import.php /full/path/to/DatastoreExternal.csv'
    );
}

// ── 1. Start log record ───────────────────────────────────────
$stmt = $pdo->prepare("INSERT INTO import_log (status) VALUES ('running')");
$stmt->execute();
$log_id = (int) $pdo->lastInsertId();
log_msg("Import started. Log ID: $log_id");

$counts = ['parsed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'skipped_stale' => 0];
$meta   = ['source_url' => null, 'file_size_bytes' => null, 'file_hash_md5' => null];

$unlink_tmp = true;
// Accept file path from CLI argv, or from a constant set by the web runner
if (PHP_SAPI === 'cli' && !empty($_SERVER['argv'][1])) {
    $argv1 = $_SERVER['argv'][1];
} elseif (defined('CI_IMPORT_FILE')) {
    $argv1 = CI_IMPORT_FILE;
} else {
    $argv1 = null;
}

try {
    // ── 2. CSV source: local path (CLI) or HTTP download ──────
    $tmp_file = null;
    if (is_string($argv1) && $argv1 !== '' && is_readable($argv1)) {
        $tmp_file = realpath($argv1) ?: $argv1;
        $unlink_tmp = false;
        $meta['source_url'] = 'local:' . basename($tmp_file);
        log_msg("Using local CSV: {$tmp_file}");
    } else {
        if (is_string($argv1) && $argv1 !== '') {
            log_msg("Warning: file not readable ({$argv1}), trying URL instead.");
        }
        $csv_url = $cfg['ci_csv_url'];
        $meta['source_url'] = $csv_url;
        log_msg("Downloading CSV from Care Inspectorate...");
        $tmp_file = tempnam(sys_get_temp_dir(), 'ci_csv_');
        if ($tmp_file === false) {
            throw new RuntimeException('Could not create temp file.');
        }
        $data = fetch_ci_csv($csv_url);
        file_put_contents($tmp_file, $data);
        $meta['file_size_bytes'] = strlen($data);
        $meta['file_hash_md5']   = md5($data);
        log_msg(sprintf(
            "Downloaded %s bytes → %s (MD5: %s)",
            number_format(strlen($data)), $tmp_file, $meta['file_hash_md5']
        ));
    }

    // Capture size+hash for local files too
    if ($meta['file_size_bytes'] === null && is_string($tmp_file) && is_file($tmp_file)) {
        $raw = file_get_contents($tmp_file);
        if ($raw !== false) {
            $meta['file_size_bytes'] = strlen($raw);
            $meta['file_hash_md5']   = md5($raw);
        }
    }

    // ── 3. Save a copy of the CSV and its headers ─────────────
    $storage_dir = ROOT . '/storage/imports';
    if (!is_dir($storage_dir)) {
        mkdir($storage_dir, 0755, true);
    }

    // Gzip-compress and save the full CSV (keeps last 3, deletes older)
    $gz_path = $storage_dir . '/' . date('Y-m-d') . '.csv.gz';
    $raw_csv = file_get_contents($tmp_file);
    if ($raw_csv !== false) {
        $compressed = gzencode($raw_csv, 6);
        if ($compressed !== false) {
            file_put_contents($gz_path, $compressed);
            $saved_kb = round(strlen($compressed) / 1024);
            log_msg("Saved compressed CSV: {$gz_path} ({$saved_kb} KB)");
        }
        // Keep only the 3 most recent .csv.gz files
        $gz_files = glob($storage_dir . '/*.csv.gz') ?: [];
        if (count($gz_files) > 3) {
            sort($gz_files);
            foreach (array_slice($gz_files, 0, count($gz_files) - 3) as $old) {
                unlink($old);
                log_msg("Removed old archive: " . basename($old));
            }
        }
    }

    // ── 4. Parse & upsert ─────────────────────────────────────
    $fh = fopen($tmp_file, 'r');
    if ($fh === false) throw new RuntimeException("Cannot open temp file.");

    // Read header row and normalise column names
    $header = fgetcsv($fh, null, ',', '"', '\\');
    $header = array_map('trim', $header);
    $col    = array_flip($header);  // column_name => index

    // Columns our upsert actually reads — if any go missing we must know immediately
    $mapped_columns = [
        'CSNumber','CareService','Subtype','AdditionalSubtypes','ServiceType',
        'ServiceName','Address_line_1','Address_line_2','Address_line_3','Address_line_4',
        'Service_town','Service_Postcode','Service_Phone_Number','Eforms_email_address',
        'Manager_Name','SP_number','ServiceProvider','Provided_by_Local_Authority',
        'ServiceStatus','Date_Reg','SIMD_rank','SIMD2020_Decile','Datazone',
        'Integration_Authority_Name','Council_Area_Name','Health_Board_Name',
        'TotalBeds','SingleBedrooms','Registered_Places','NumberStaff','Client_group',
        'CareHome_Main_Area_of_Care','Care_Home_All_Areas_of_Provision','PublicList',
        'KQ_Support_Wellbeing','KQ_Care_and_Support_Planning','KQ_Setting',
        'KQ_Staff_Team','KQ_Leadership','KQ_Care_Play_and_Learning',
        'MinGrade','MaxGrade','GradeSpread','Publication_of_Latest_Grading',
        'RAD_SAT_Score',
    ];

    // CI exports a rolling 3-year window of "Complaints_upheld_YYZZ" columns
    // (e.g. 2425/2526/2627) and shifts it forward every year, dropping the
    // oldest and adding a new one — so we discover these by prefix instead
    // of hardcoding specific year suffixes that would go stale annually.
    $complaints_cols = array_values(array_filter(
        array_keys($col),
        fn($c) => str_starts_with($c, 'Complaints_upheld_')
    ));
    if (!$complaints_cols) {
        log_msg("CRITICAL: no Complaints_upheld_* columns found in CSV — complaints_upheld will be 0 for every row. CI may have renamed this column family.");
    } else {
        log_msg("Complaints columns detected: " . implode(', ', $complaints_cols));
    }

    // Compare against previous headers to detect renames / additions / removals
    $headers_file   = $storage_dir . '/headers.json';
    $prev_columns   = [];
    $column_changes = ['missing_mapped' => [], 'added' => [], 'removed' => []];

    if (is_file($headers_file)) {
        $prev = json_decode(file_get_contents($headers_file), true);
        $prev_columns = $prev['columns'] ?? [];
    }

    if ($prev_columns) {
        $column_changes['added']   = array_values(array_diff($header, $prev_columns));
        $column_changes['removed'] = array_values(array_diff($prev_columns, $header));
        if ($column_changes['added']) {
            log_msg("NEW columns in this CSV (not seen before): " . implode(', ', $column_changes['added']));
        }
        if ($column_changes['removed']) {
            log_msg("WARNING: columns REMOVED since last import: " . implode(', ', $column_changes['removed']));
        }
    }

    // Check which mapped columns are absent from the current CSV
    $column_changes['missing_mapped'] = array_values(array_filter(
        $mapped_columns,
        fn($c) => !isset($col[$c])
    ));
    if ($column_changes['missing_mapped']) {
        log_msg("CRITICAL: mapped columns missing from CSV — data will be NULL for: "
            . implode(', ', $column_changes['missing_mapped']));
    } else {
        log_msg("Column check passed — all " . count($mapped_columns) . " mapped columns present.");
    }

    // Save current headers (done after diff so prev is still the old file during comparison)
    file_put_contents(
        $headers_file,
        json_encode([
            'imported_at'    => date('Y-m-d H:i:s'),
            'columns'        => $header,
            'column_changes' => $column_changes,
        ], JSON_PRETTY_PRINT)
    );

    // Helper: safe column read + UTF-8 safe for MySQL utf8mb4
    $get = function (array $row, string $name, mixed $default = null) use ($col): mixed {
        if (!isset($col[$name], $row[$col[$name]]) || $row[$col[$name]] === '') {
            return $default;
        }
        return ci_csv_utf8(trim((string) $row[$col[$name]]));
    };

    // Prepare upsert statement.
    //
    // Two strategies in the ON DUPLICATE KEY UPDATE clause:
    //
    //   OVERWRITE — CI's authoritative current data always wins.
    //     Service name, status, address, grades, provider, public_list etc.
    //     If CI changes these, we want the new value immediately.
    //
    //   FILL-IN — preserve any value we already have if the new CSV is empty.
    //     COALESCE(NULLIF(VALUES(col),''), col)
    //     = use new value if non-empty, otherwise keep existing.
    //     Applied to: postcode, phone, email, manager_name, date_registered,
    //     and the deprivation/location fields CI sometimes omits.
    //     If CI later supplies a real value it will overwrite the kept one.
    //
    $upsert = $pdo->prepare("
        INSERT INTO services (
            cs_number, care_service, subtype, additional_subtypes, service_type,
            service_name, address_1, address_2, address_3, address_4,
            town, postcode, phone, email, manager_name,
            sp_number, provider_name, provided_by_la, service_status, date_registered,
            simd_rank, simd_decile, datazone,
            integration_auth, council_area, health_board,
            total_beds, single_bedrooms, registered_places, num_staff, client_group,
            care_home_main_area, care_home_areas, public_list,
            grade_wellbeing, grade_planning, grade_setting, grade_staff, grade_leadership, grade_cpl,
            grade_min, grade_max, grade_spread, grade_published,
            rad_sat_score, complaints_upheld, ci_last_updated
        ) VALUES (
            :cs_number, :care_service, :subtype, :additional_subtypes, :service_type,
            :service_name, :address_1, :address_2, :address_3, :address_4,
            :town, :postcode, :phone, :email, :manager_name,
            :sp_number, :provider_name, :provided_by_la, :service_status, :date_registered,
            :simd_rank, :simd_decile, :datazone,
            :integration_auth, :council_area, :health_board,
            :total_beds, :single_bedrooms, :registered_places, :num_staff, :client_group,
            :care_home_main_area, :care_home_areas, :public_list,
            :grade_wellbeing, :grade_planning, :grade_setting, :grade_staff, :grade_leadership, :grade_cpl,
            :grade_min, :grade_max, :grade_spread, :grade_published,
            :rad_sat_score, :complaints_upheld, :ci_last_updated
        )
        ON DUPLICATE KEY UPDATE
            -- OVERWRITE: CI's current data is authoritative
            service_name      = VALUES(service_name),
            care_service      = VALUES(care_service),
            subtype           = VALUES(subtype),
            service_type      = VALUES(service_type),
            address_1         = VALUES(address_1),
            address_2         = VALUES(address_2),
            address_3         = VALUES(address_3),
            address_4         = VALUES(address_4),
            town              = VALUES(town),
            sp_number         = VALUES(sp_number),
            provider_name     = VALUES(provider_name),
            provided_by_la    = VALUES(provided_by_la),
            service_status    = VALUES(service_status),
            council_area      = VALUES(council_area),
            health_board      = VALUES(health_board),
            integration_auth  = VALUES(integration_auth),
            total_beds        = VALUES(total_beds),
            single_bedrooms   = VALUES(single_bedrooms),
            registered_places = VALUES(registered_places),
            num_staff         = VALUES(num_staff),
            client_group      = VALUES(client_group),
            care_home_main_area = VALUES(care_home_main_area),
            care_home_areas   = VALUES(care_home_areas),
            public_list       = VALUES(public_list),
            grade_wellbeing   = VALUES(grade_wellbeing),
            grade_planning    = VALUES(grade_planning),
            grade_setting     = VALUES(grade_setting),
            grade_staff       = VALUES(grade_staff),
            grade_leadership  = VALUES(grade_leadership),
            grade_cpl         = VALUES(grade_cpl),
            grade_min         = VALUES(grade_min),
            grade_max         = VALUES(grade_max),
            grade_spread      = VALUES(grade_spread),
            grade_published   = VALUES(grade_published),
            rad_sat_score     = VALUES(rad_sat_score),
            complaints_upheld = VALUES(complaints_upheld),
            ci_last_updated   = VALUES(ci_last_updated),
            -- FILL-IN: keep existing value if new CSV is empty
            postcode          = COALESCE(NULLIF(VALUES(postcode), ''),    postcode),
            phone             = COALESCE(NULLIF(VALUES(phone), ''),       phone),
            email             = COALESCE(NULLIF(VALUES(email), ''),       email),
            manager_name      = COALESCE(NULLIF(VALUES(manager_name), ''), manager_name),
            date_registered   = COALESCE(VALUES(date_registered),         date_registered),
            simd_rank         = COALESCE(VALUES(simd_rank),               simd_rank),
            simd_decile       = COALESCE(VALUES(simd_decile),             simd_decile),
            datazone          = COALESCE(NULLIF(VALUES(datazone), ''),    datazone),
            updated_at        = CURRENT_TIMESTAMP
    ");

    // CI's CSV exports are cumulative snapshots of every service's CURRENT
    // state, not deltas — so files can safely be imported in any order.
    // A service that hasn't been reinspected between two exports will have
    // identical data in both, so re-applying an older file is a harmless
    // no-op for it. The only thing we must guard against is a row whose
    // grade data is OLDER than an inspection we've already recorded for
    // that service (e.g. CI re-sends a prior inspection, or we import an
    // archived file after a newer one) — that row is a stale duplicate and
    // must not overwrite the newer data we already have.
    $existingGradePublished = [];
    foreach ($pdo->query("SELECT cs_number, grade_published FROM services") as $r) {
        $existingGradePublished[$r['cs_number']] = $r['grade_published'];
    }

    $pdo->beginTransaction();
    $today = date('Y-m-d');

    while (($row = fgetcsv($fh, null, ',', '"', '\\')) !== false) {
        $counts['parsed']++;

        $cs = $get($row, 'CSNumber');
        if (!$cs) { $counts['skipped']++; continue; }

        // Parse date (CSV format varies — handle dd/mm/yyyy and yyyy-mm-dd)
        $raw_date  = $get($row, 'Date_Reg') ?: $get($row, 'DateReg');
        $date_reg  = null;
        if ($raw_date) {
            $dt = DateTime::createFromFormat('d/m/Y', $raw_date)
               ?: DateTime::createFromFormat('Y-m-d', $raw_date);
            $date_reg = $dt ? $dt->format('Y-m-d') : null;
        }

        $grade_pub = $get($row, 'Publication_of_Latest_Grading');
        $gp_date   = null;
        if ($grade_pub) {
            $dt = DateTime::createFromFormat('d/m/Y', $grade_pub)
               ?: DateTime::createFromFormat('Y-m-d', $grade_pub);
            $gp_date = $dt ? $dt->format('Y-m-d') : null;
        }

        // Skip rows whose grade data is older than what we already have for
        // this service — a duplicate/stale inspection record from an
        // out-of-order or re-sent file. A brand new service (not seen
        // before) or one with no existing grade always passes through.
        $existing_gp = $existingGradePublished[$cs] ?? null;
        if ($existing_gp !== null && ($gp_date === null || $gp_date < $existing_gp)) {
            $counts['skipped_stale']++;
            continue;
        }

        $int = fn($v) => ($v !== null && is_numeric($v)) ? (int)$v : null;
        $flt = fn($v) => ($v !== null && is_numeric($v)) ? (float)$v : null;

        $upsert->execute([
            ':cs_number'          => $cs,
            ':care_service'       => $get($row, 'CareService'),
            ':subtype'            => $get($row, 'Subtype'),
            ':additional_subtypes'=> $get($row, 'AdditionalSubtypes'),
            ':service_type'       => $get($row, 'ServiceType'),
            ':service_name'       => $get($row, 'ServiceName', ''),
            ':address_1'          => $get($row, 'Address_line_1'),
            ':address_2'          => $get($row, 'Address_line_2'),
            ':address_3'          => $get($row, 'Address_line_3'),
            ':address_4'          => $get($row, 'Address_line_4'),
            ':town'               => $get($row, 'Service_town'),
            ':postcode'           => $get($row, 'Service_Postcode'),
            ':phone'              => $get($row, 'Service_Phone_Number'),
            ':email'              => $get($row, 'Eforms_email_address'),
            ':manager_name'       => $get($row, 'Manager_Name'),
            ':sp_number'          => $get($row, 'SP_number'),
            ':provider_name'      => $get($row, 'ServiceProvider'),
            ':provided_by_la'     => strtolower((string)$get($row, 'Provided_by_Local_Authority')) === 'yes' ? 1 : 0,
            ':service_status'     => $get($row, 'ServiceStatus'),
            ':date_registered'    => $date_reg,
            ':simd_rank'          => $int($get($row, 'SIMD_rank')),
            ':simd_decile'        => $int($get($row, 'SIMD2020_Decile')),
            ':datazone'           => $get($row, 'Datazone'),
            ':integration_auth'   => $get($row, 'Integration_Authority_Name'),
            ':council_area'       => $get($row, 'Council_Area_Name'),
            ':health_board'       => $get($row, 'Health_Board_Name'),
            ':total_beds'         => $int($get($row, 'TotalBeds')),
            ':single_bedrooms'    => $int($get($row, 'SingleBedrooms')),
            ':registered_places'  => $int($get($row, 'Registered_Places')),
            ':num_staff'          => $int($get($row, 'NumberStaff')),
            ':client_group'       => $get($row, 'Client_group'),
            ':care_home_main_area'=> $get($row, 'CareHome_Main_Area_of_Care'),
            ':care_home_areas'    => $get($row, 'Care_Home_All_Areas_of_Provision'),
            ':public_list'        => strtolower((string)$get($row, 'PublicList')) === 'no' ? 0 : 1,
            ':grade_wellbeing'    => $int($get($row, 'KQ_Support_Wellbeing')),
            ':grade_planning'     => $int($get($row, 'KQ_Care_and_Support_Planning')),
            ':grade_setting'      => $int($get($row, 'KQ_Setting')),
            ':grade_staff'        => $int($get($row, 'KQ_Staff_Team')),
            ':grade_leadership'   => $int($get($row, 'KQ_Leadership')),
            ':grade_cpl'          => $int($get($row, 'KQ_Care_Play_and_Learning')),
            ':grade_min'          => $int($get($row, 'MinGrade')),
            ':grade_max'          => $int($get($row, 'MaxGrade')),
            ':grade_spread'       => $get($row, 'GradeSpread'),
            ':grade_published'    => $gp_date,
            ':rad_sat_score'      => $flt($get($row, 'RAD_SAT_Score')),
            ':complaints_upheld'  => array_sum(array_map(
                                        fn($c) => $int($get($row, $c)) ?? 0,
                                        $complaints_cols
                                    )),
            ':ci_last_updated'    => $today,
        ]);

        $affected = $upsert->rowCount();
        if ($affected === 1) $counts['inserted']++;
        elseif ($affected === 2) $counts['updated']++;
        else $counts['skipped']++;

        if ($counts['parsed'] % 1000 === 0) {
            log_msg("  ... {$counts['parsed']} rows processed");
        }
    }

    $pdo->commit();
    fclose($fh);
    if ($unlink_tmp && is_string($tmp_file) && is_file($tmp_file)) {
        unlink($tmp_file);
    }

    $status = 'complete';
    if ($column_changes['missing_mapped']) {
        $status = 'complete_with_warnings';
    }
    log_msg("Done. Inserted: {$counts['inserted']}, Updated: {$counts['updated']}, Skipped: {$counts['skipped']}, Skipped (stale): {$counts['skipped_stale']}");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = 'failed';
    log_msg("ERROR: " . $e->getMessage());
}

// ── 5. Finalise log record ────────────────────────────────────
if ($log_id) {
    $total_services  = (int) $pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();
    $active_services = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE service_status='Active'")->fetchColumn();

    $notes = null;
    $any_changes = $column_changes['missing_mapped']
                || $column_changes['added']
                || $column_changes['removed']
                || $counts['skipped_stale'] > 0;
    if ($any_changes) {
        $notes = json_encode($column_changes + ['skipped_stale' => $counts['skipped_stale']]);
    }

    $pdo->prepare("
        UPDATE import_log SET
            finished_at      = NOW(),
            csv_date         = CURDATE(),
            rows_parsed      = :parsed,
            rows_inserted    = :inserted,
            rows_updated     = :updated,
            rows_skipped     = :skipped,
            status           = :status,
            source_url       = :source_url,
            file_size_bytes  = :file_size_bytes,
            file_hash_md5    = :file_hash_md5,
            services_total   = :services_total,
            services_active  = :services_active,
            notes            = :notes
        WHERE id = :id
    ")->execute([
        ':parsed'          => $counts['parsed'],
        ':inserted'        => $counts['inserted'],
        ':updated'         => $counts['updated'],
        ':skipped'         => $counts['skipped'],
        ':status'          => $status,
        ':source_url'      => $meta['source_url'],
        ':file_size_bytes' => $meta['file_size_bytes'],
        ':file_hash_md5'   => $meta['file_hash_md5'],
        ':services_total'  => $total_services,
        ':services_active' => $active_services,
        ':notes'           => $notes,
        ':id'              => $log_id,
    ]);
    log_msg("DB snapshot: {$total_services} total services, {$active_services} active.");
}
