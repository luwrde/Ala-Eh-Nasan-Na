<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/terminals_repository.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function constraint_rejects(PDO $db, string $sql): void
{
    try { $db->exec($sql); } catch (PDOException $error) { return; }
    throw new RuntimeException('Invalid data was accepted: ' . $sql);
}

// Synthetic coordinates and route values are used only in this isolated temporary database.
$path = tempnam(sys_get_temp_dir(), 'ala-eh-terminal-test-');
putenv('ALA_EH_TERMINALS_DB=' . $path);
try {
    $db = terminals_database();
    $records = terminal_records($db);
    check(count($records) === 6, 'Expected six project locations');
    check(count(array_unique(array_column($records, 'municipality'))) === 2, 'Expected two municipalities');
    check(count(array_filter($records, fn($r) => $r['municipality'] === 'Batangas City')) === 3, 'Batangas grouping');
    check(count(array_filter($records, fn($r) => $r['municipality'] === 'Mabini')) === 3, 'Mabini grouping');
    $expectedMaps = [
        1 => ['https://maps.app.goo.gl/mi7CqzXjvnwnZBSy8', 13.7560962, 121.0518226],
        2 => ['https://maps.app.goo.gl/Zh9HJ8AVyuARsWjh6', 13.7715645, 121.0499724],
        4 => ['https://maps.app.goo.gl/7YPKhDnvYGaoEx8e9', 13.7525621, 120.9406161],
        5 => ['https://maps.app.goo.gl/L1pNnSffCY6zzKs8A', 13.749087, 120.9404809],
        6 => ['https://maps.app.goo.gl/ujKj51cVLsVsBJrj8', 13.7477068, 120.9406855],
    ];
    foreach ($records as $record) {
        check((bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $record['updated_at']), 'Each location has an update timestamp');
        if ($record['id'] === 3) {
            check($record['coordinates'] === ['latitude' => 13.78986, 'longitude' => 121.06235], 'Grand Terminal uses its mapped reference');
            check(str_contains($record['coordinate_source'], 'W236809581'), 'Mapped pin retains its source');
            check(str_contains($record['coordinate_note'], 'boarding bay'), 'Mapped area must not imply a verified boarding bay');
        } else {
            check($record['coordinates'] === null && !$record['coordinates_verified'], 'Unverified locations remain NULL');
            [$link, $lat, $lng] = $expectedMaps[$record['id']];
            check($record['google_maps_url'] === $link, 'Original Google Maps link is preserved');
            check($record['landmark_coordinates'] === ['latitude' => $lat, 'longitude' => $lng], 'Landmark coordinate is separate from boarding coordinate');
            check($record['landmark_coordinates_verified'] === true && $record['location_verification_status'] === 'landmark_verified', 'Landmark must not be marked as verified boarding point');
        }
        check(!$record['routes'] && $record['classification'] === null && $record['operating_schedule'] === null, 'Examples must not become seeded routes');
        check($record['seat_availability'] === null, 'No live seats source exists');
    }
    check(terminal_records($db, 2)[0]['address'] === "In front of Kwatog's, Barangay Bolbok, Batangas City", 'Exact supplied landmark');
    check(terminal_records($db, 3)[0]['google_maps_url'] === null, 'No Google Maps link was provided for Grand Terminal');
    check(terminal_records(terminals_database(), 3)[0]['routes'] === [], 'Repeated initialization must not recreate example routes');
    check(terminal_records($db, 4)[0]['boarding_direction'] === 'Left side of F. Castillo Boulevard.', 'Triangle boarding instruction');
    check(terminal_records($db, 5)[0]['boarding_direction'] === 'In front of 7-Eleven, left side of F. Castillo Boulevard.', '7-Eleven boarding instruction');
    check(terminal_records($db, 6)[0]['boarding_direction'] === 'In front of the establishment along Mabini Circumferential Road.', 'Iconails boarding instruction');
    check(terminal_records($db, 99999) === [], 'Missing record');
    check(count(terminal_records(terminals_database())) === 6, 'Initialization is idempotent');
    constraint_rejects($db, 'UPDATE terminals SET latitude = 91, longitude = 10 WHERE id = 1');
    constraint_rejects($db, 'UPDATE terminals SET latitude = 10, longitude = NULL WHERE id = 1');
    constraint_rejects($db, 'UPDATE terminals SET coordinates_verified = 1 WHERE id = 1');
    $db->exec('UPDATE terminals SET latitude = 0, longitude = 0 WHERE id = 1');
    check(terminal_records($db, 1)[0]['coordinates'] === null, 'Unverified coordinates cannot escape the API');
    $db->exec("UPDATE terminals SET coordinates_verified = 1, coordinate_source = 'ISOLATED TEST FIXTURE', coordinates_verified_at = '2026-11-01T00:00:00Z' WHERE id = 1");
    check(terminal_records($db, 1)[0]['coordinates'] === ['latitude' => 0.0, 'longitude' => 0.0], 'Valid zero coordinates are supported');
    $db->exec("INSERT INTO terminal_routes (terminal_id, name, destination, fare_information) VALUES (1, 'TEST route', 'TEST destination', 'TEST fare')");
    check(terminal_records($db, 1)[0]['routes'] === [], 'Unverified routes are hidden');
    $db->exec('UPDATE terminal_routes SET verified = 1');
    check(terminal_records($db, 1)[0]['routes'][0]['fare_information'] === 'TEST fare', 'Configured route details are database-backed');
    check(terminal_records(terminals_database(), 1)[0]['coordinates_verified'], 'Startup preserves edits');
    // Simulate an existing version-5 database containing the exact unsolicited seed
    // and an owner record for the same destination with different provenance.
    $example = [3, 'Batangas - Bauan (Grand Terminal)', 'Bauan', 1,
        'https://www.batangascity.gov.ph/web/images/Ordinances2019/ORDINANCENO.23S.2019.pdf#page=41',
        'Batangas City Traffic Ordinance of 2020, Sections 140(e)(2) and 151(d)',
        'Documented terminal route. Confirm the Bauan-bound jeepney and boarding bay with terminal staff. Current departures, fares, and schedules are not yet confirmed.'];
    $insert = $db->prepare('INSERT INTO terminal_routes (terminal_id, name, destination, verified, source_url, source_title, service_note) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute($example);
    $owner = $example; $owner[4] = 'https://owner.example/route'; $owner[5] = 'OWNER RECORD';
    $insert->execute($owner);
    $insert = null;
    $db->exec('PRAGMA user_version = 5');
    $cleaned = terminal_records(terminals_database(), 3)[0]['routes'];
    check(count($cleaned) === 1 && $cleaned[0]['source_title'] === 'OWNER RECORD', 'Cleanup removes only the unsolicited seed and preserves owner-entered services');
    check(count(terminal_records(terminals_database(), 3)[0]['routes']) === 1, 'Cleanup remains idempotent');
    check(count(terminal_records($db)) === 6 && terminal_records($db, 1)[0]['coordinates_verified'], 'Cleanup preserves terminal records and coordinate edits');
    $legacyPath = tempnam(sys_get_temp_dir(), 'ala-eh-terminal-upgrade-');
    $legacy = null;
    $upgraded = null;
    try {
        $legacy = new PDO('sqlite:' . $legacyPath);
        $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $legacy->exec(file_get_contents(dirname(__DIR__) . '/database/terminals-schema.sql'));
        $legacy->exec(file_get_contents(dirname(__DIR__) . '/database/terminals-seed.sql'));
        $legacy->exec('PRAGMA user_version = 1');
        $legacy->exec("INSERT INTO terminal_routes (terminal_id, name, destination, fare_information, verified) VALUES (3, 'OWNER TESTVILLE SERVICE', 'Testville', 'OWNER FARE', 1)");
        $legacy->exec("UPDATE terminals SET boarding_direction = 'OWNER BOARDING INSTRUCTION' WHERE id = 4");
        $legacy->exec("UPDATE terminals SET latitude = 15, longitude = 121, coordinates_verified = 1, coordinate_source = 'OWNER SUPPLIED', coordinates_verified_at = '2026-01-01' WHERE id = 3");
        putenv('ALA_EH_TERMINALS_DB=' . $legacyPath);
        $upgraded = terminals_database();
        $preserved = terminal_records($upgraded, 3)[0];
        check($preserved['coordinates']['latitude'] === 15.0 && $preserved['coordinate_source'] === 'OWNER SUPPLIED', 'Migration must preserve owner-configured coordinates');
        check(count(terminal_records($upgraded)) === 6, 'Upgrade must not duplicate locations');
        $ownerRoutes = terminal_records($upgraded, 3)[0]['routes'];
        check(count($ownerRoutes) === 1 && $ownerRoutes[0]['name'] === 'OWNER TESTVILLE SERVICE' && $ownerRoutes[0]['fare_information'] === 'OWNER FARE', 'Destination migration preserves owner-configured services without duplicates');
        check(terminal_records($upgraded, 4)[0]['boarding_direction'] === 'OWNER BOARDING INSTRUCTION', 'Upgrade must preserve configured boarding instructions');
    } finally {
        $legacy = null;
        $upgraded = null;
        putenv('ALA_EH_TERMINALS_DB=' . $path);
        if (is_file($legacyPath)) unlink($legacyPath);
    }
    echo "Passed: six exact seed locations, grouping, unknown data, repeated initialization, persistence, coordinate constraints, verification, and route records.\n";
} finally {
    $db = null;
    putenv('ALA_EH_TERMINALS_DB');
    if (is_file($path)) unlink($path);
}
