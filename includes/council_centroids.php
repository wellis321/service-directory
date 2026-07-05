<?php
declare(strict_types=1);

/**
 * Approximate administrative centroids for Scottish council areas (lat, lng).
 * Coordinates are indicative for map display only (OGL-style factual listing).
 * Care Inspectorate CSV names are matched exactly where possible; aliases cover common variants.
 *
 * @return array<string, array{0: float, 1: float}> keyed by canonical name (lat, lng)
 */
function council_centroids_canonical(): array
{
    return [
        'Aberdeen City' => [57.1497, -2.0943],
        'Aberdeenshire' => [57.1611, -2.7794],
        'Angus' => [56.6484, -2.8890],
        'Argyll and Bute' => [56.4094, -5.4703],
        'Clackmannanshire' => [56.1150, -3.7542],
        'Dumfries and Galloway' => [55.0702, -3.6053],
        'Dundee City' => [56.4620, -2.9707],
        'East Ayrshire' => [55.4472, -4.2634],
        'East Dunbartonshire' => [55.9234, -4.2025],
        'East Lothian' => [55.9493, -2.7734],
        'East Renfrewshire' => [55.7850, -4.3491],
        'City of Edinburgh' => [55.9533, -3.1883],
        'Falkirk' => [55.9992, -3.7837],
        'Fife' => [56.2082, -3.1495],
        'Glasgow City' => [55.8642, -4.2518],
        'Highland' => [57.4778, -4.2247],
        'Inverclyde' => [55.8998, -4.7505],
        'Midlothian' => [55.8289, -3.1204],
        'Moray' => [57.6494, -3.3184],
        'North Ayrshire' => [55.7590, -4.6873],
        'North Lanarkshire' => [55.8663, -3.9623],
        'Orkney Islands' => [58.9806, -2.9609],
        'Perth and Kinross' => [56.3950, -3.4308],
        'Renfrewshire' => [55.8467, -4.5339],
        'Scottish Borders' => [55.5482, -2.8401],
        'Shetland Islands' => [60.1520, -1.1490],
        'South Ayrshire' => [55.4582, -4.6290],
        'South Lanarkshire' => [55.6736, -3.7819],
        'Stirling' => [56.1165, -3.9369],
        'West Dunbartonshire' => [55.9419, -4.5384],
        'West Lothian' => [55.9070, -3.5226],
        'Na h-Eileanan Siar' => [57.7599, -7.0190],
    ];
}

/** Map alternate labels (as in source data) to canonical centroid keys. */
function council_centroid_aliases(): array
{
    return [
        'Argyll & Bute' => 'Argyll and Bute',
        'Dumfries & Galloway' => 'Dumfries and Galloway',
        'Edinburgh, City of' => 'City of Edinburgh',
        'Edinburgh City' => 'City of Edinburgh',
        'Western Isles' => 'Na h-Eileanan Siar',
        'Eilean Siar' => 'Na h-Eileanan Siar',
        'Na h-Eileanan an Iar' => 'Na h-Eileanan Siar',
    ];
}

/**
 * Resolve a council_area string from the database to [lat, lng] or null.
 *
 * @return array{0: float, 1: float}|null
 */
function council_centroid_for_db_name(string $dbName): ?array
{
    $dbName = trim($dbName);
    if ($dbName === '') {
        return null;
    }
    $aliases = council_centroid_aliases();
    $canonicalKeys = council_centroids_canonical();
    if (isset($canonicalKeys[$dbName])) {
        return $canonicalKeys[$dbName];
    }
    if (isset($aliases[$dbName]) && isset($canonicalKeys[$aliases[$dbName]])) {
        return $canonicalKeys[$aliases[$dbName]];
    }
    return null;
}
