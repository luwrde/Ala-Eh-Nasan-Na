<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/jeepney_routes_repository.php';
function route_check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects_route(callable $action): void {
    try { $action(); } catch (InvalidArgumentException $error) { return; }
    throw new RuntimeException('Invalid route input was accepted.');
}
$path = tempnam(sys_get_temp_dir(), 'ala-eh-jeepney-test-');
$file = tempnam(sys_get_temp_dir(), 'ala-eh-jeepney-import-');
putenv('ALA_EH_TERMINALS_DB=' . $path);
try {
    $db = terminals_database();
    route_check((int) $db->query('PRAGMA user_version')->fetchColumn() === 7, 'Schema version 7');
    $seeded = jeepney_route_records($db);
    route_check(count($seeded) === 1 && $seeded[0]['slug'] === 'grand-terminal-to-mabini', 'Only the one requested route is seeded');
    route_check($seeded[0]['status'] === 'pending' && $seeded[0]['geometry'] === null && $seeded[0]['preview']['route_verified'] === false, 'Generated driving geometry must never become a verified jeepney trace');
    route_check(count($seeded[0]['preview']['points']) === 7 && count($seeded[0]['preview']['geometry']['coordinates']) > 100, 'Sourced references and full road geometry are stored');
    route_check($seeded[0]['roads'] === [] && $seeded[0]['stops'] === [], 'Preliminary waypoints are not verified jeepney stops');
    route_check($seeded[0]['preview']['distance_meters'] > 18000 && $seeded[0]['preview']['distance_meters'] < 22000, 'Mapped preview length is derived from the stored road trace');
    route_check(count(jeepney_route_records(terminals_database())) === 1, 'Repeated startup does not duplicate the test route');
    $seedPayload = json_decode(file_get_contents(dirname(__DIR__) . '/database/grand-terminal-mabini-preview.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ([['status' => 'verified', 'geometry' => $seedPayload['preview']['geometry'], 'verification_source' => 'TEST', 'verified_by' => 'TEST', 'verified_at' => '2026-11-01T00:00:00Z'],
        ['preview' => array_replace($seedPayload['preview'], ['geometry' => ['type' => 'LineString', 'coordinates' => [[121,13],[121.001,13],[121.002,13]]]])],
        ['preview' => array_replace($seedPayload['preview'], ['provider_url' => 'javascript:alert(1)'])],
        ['preview' => array_replace($seedPayload['preview'], ['generated_at' => '2026-02-31T00:00:00Z'])],
        ['preview' => array_replace($seedPayload['preview'], ['points' => array_reverse($seedPayload['preview']['points'])])]
    ] as $badPreview) rejects_route(fn() => jeepney_route_input($db, array_replace($seedPayload, $badPreview)));
    $storedId = $seeded[0]['id'];
    $beforeTerminals = terminal_records($db);
    import_jeepney_route($db, $seedPayload, true);
    route_check(jeepney_route_records($db, $storedId)[0]['preview']['geometry'] == $seedPayload['preview']['geometry'], 'Pending previews can be edited through the existing CLI import structure');
    route_check(terminal_records($db) === $beforeTerminals, 'Preview imports preserve terminal data');
    $db->exec('DELETE FROM jeepney_route_paths'); // Only this isolated temporary test database.
    // Synthetic geometry belongs only to this temporary database, never to the project data.
    $db->exec("UPDATE terminals SET latitude = 0, longitude = CASE id WHEN 1 THEN 0 ELSE 0.02 END,
        coordinates_verified = 1, coordinate_source = 'ISOLATED TEST ONLY', coordinates_verified_at = '2026-11-01T00:00:00Z' WHERE id IN (1,4)");
    $baseline = terminal_records($db);
    $points = [[0,0],[.004,.001],[.008,.001],[.012,0],[.016,.001],[.02,0]];
    $payload = ['slug' => 'isolated-outbound', 'name' => 'SYNTHETIC TEST ROUTE', 'start_terminal_id' => 1, 'end_terminal_id' => 4,
        'travel_direction' => 'batangas-to-mabini', 'geometry' => ['type' => 'LineString', 'coordinates' => $points],
        'status' => 'verified', 'verification_source' => 'ISOLATED TEST FIXTURE, NOT A REAL JEEPNEY ROUTE',
        'verified_by' => 'TEST REVIEWER', 'verified_at' => '2026-11-01T00:00:00Z', 'roads' => ['TEST ROAD A', 'TEST ROAD B'],
        'stops' => [['name' => 'SYNTHETIC STOP', 'coordinates' => $points[1], 'verified' => true, 'verification_source' => 'TEST ONLY']]];
    foreach ([['geometry' => ['type' => 'LineString', 'coordinates' => [[0,0],[.02,0]]]],
        ['geometry' => ['type' => 'LineString', 'coordinates' => [[0,0],[2,0],[.02,0]]]],
        ['geometry' => ['type' => 'LineString', 'coordinates' => [[0,0],[181,0],[.02,0]]]],
        ['geometry' => ['type' => 'LineString', 'coordinates' => [[0,0],['.004',0],[.02,0]]]],
        ['geometry' => ['type' => 'FeatureCollection', 'features' => 'invalid']],
        ['travel_direction' => []], ['start_terminal_id' => 99999], ['end_terminal_id' => 1], ['travel_direction' => 'mabini-to-batangas'],
        ['verification_source' => null], ['verified_by' => ''], ['verified_at' => '2026-02-31T00:00:00Z'],
        ['stops' => [['name' => 'Unverified', 'coordinates' => $points[1], 'verified' => false]]],
        ['stops' => [['name' => 'Wrong place', 'coordinates' => [10,10], 'verified' => true, 'verification_source' => 'TEST']]],
        ['geometry' => ['type' => 'LineString', 'coordinates' => [[1,1],[1.001,1],[1.002,1]]]]
    ] as $invalid) rejects_route(fn() => import_jeepney_route($db, array_replace($payload, $invalid)));
    $id = import_jeepney_route($db, $payload);
    $route = jeepney_route_records($db, $id)[0];
    route_check($route['geometry']['coordinates'] === $points && $route['status'] === 'verified', 'Stored geometry is returned unchanged');
    route_check($route['distance_meters'] > 2200 && $route['distance_meters'] < 3000, 'Distance is derived from the full trace');
    route_check($route['roads'] === $payload['roads'] && count($route['stops']) === 1, 'Verified roads and stops are available');
    route_check(!empty($route['updated_at']), 'Update timestamp saved');
    rejects_route(fn() => import_jeepney_route($db, $payload));
    route_check(count(jeepney_route_records($db)) === 1, 'Duplicate slug cannot silently overwrite a route');
    $reverse = $payload;
    $reverse['slug'] = 'isolated-return';
    $reverse['start_terminal_id'] = 4; $reverse['end_terminal_id'] = 1; $reverse['travel_direction'] = 'mabini-to-batangas';
    $reverse['geometry']['coordinates'] = [[.02,0],[.016,-.001],[.012,-.001],[.008,-.002],[.004,-.001],[0,0]];
    $reverse['stops'] = [];
    $reverseId = import_jeepney_route($db, $reverse);
    route_check(jeepney_route_records($db, $reverseId)[0]['geometry'] !== ['type' => 'LineString', 'coordinates' => array_reverse($points)], 'Reverse direction has independently stored geometry');
    $draft = $payload; $draft['status'] = 'draft';
    $draft['geometry'] = ['type' => 'Feature', 'properties' => [], 'geometry' => $payload['geometry']];
    route_check(import_jeepney_route($db, $draft, true) === $id, 'Explicit replacement preserves ID');
    $draftRecord = jeepney_route_records($db, $id)[0];
    route_check($draftRecord['status'] === 'draft' && $draftRecord['stops'] === [] && $draftRecord['roads'] === [], 'Draft path cannot publish roads or stops as verified');
    $pending = array_replace($payload, ['slug' => 'isolated-pending', 'status' => 'pending', 'geometry' => null, 'stops' => [], 'roads' => []]);
    import_jeepney_route($db, $pending);
    $cli = array_replace($payload, ['slug' => 'isolated-cli', 'status' => 'draft', 'geometry' => null, 'coordinates' => $points]);
    file_put_contents($file, json_encode($cli, JSON_THROW_ON_ERROR));
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/tools/import_jeepney_route.php', '--file=' . $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    route_check(proc_close($process) === 0, 'CLI accepts ordered coordinates: ' . $stderr);
    route_check(str_contains($stdout, 'Saved directional route ID'), 'CLI reports saved record');
    route_check(count(jeepney_route_records(terminals_database())) === 4, 'Reload preserves all directional records');
    route_check(terminal_records($db) === $baseline, 'Import and migration do not modify terminal or service data');
    $db->exec("UPDATE jeepney_route_paths SET geometry_geojson = '{\"type\":\"LineString\",\"coordinates\":[]}' WHERE id = $reverseId");
    $broken = jeepney_route_records($db, $reverseId)[0];
    route_check($broken['status'] === 'pending' && $broken['geometry'] === null, 'Malformed stored geometry is never published as verified');
    route_check(jeepney_route_records($db, 999999) === [], 'Unknown ID');
    echo "Passed: route migration, geometry/evidence/direction validation, independent return traces, stop verification, distance, persistence, CLI import, and unchanged terminals.\n";
} finally {
    $db = null; putenv('ALA_EH_TERMINALS_DB');
    if (is_file($path)) unlink($path);
    if (is_file($file)) unlink($file);
}
