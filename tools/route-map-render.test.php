<?php
declare(strict_types=1);

// Read-only integration test against the configured local database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('session.save_path', sys_get_temp_dir());
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/seats.php';
require __DIR__ . '/../app/pickup-areas.php';
require __DIR__ . '/../app/driver-routes.php';
restore_exception_handler();

// Exercise the eligible driver branch without signing in or saving any records.
$mapDriver = db()->query("SELECT d.id FROM drivers d
    JOIN jeepney_profiles j ON j.driver_id = d.id
    WHERE d.verification_status = 'verified' AND j.plate_number IS NOT NULL
    AND j.capacity > 0 LIMIT 1")->fetch();
if (!$mapDriver) {
    throw new RuntimeException('This integration test requires an existing eligible driver.');
}
$mapPickupAreas = pickup_areas();
foreach (pickup_area_keys() as $locationKey) {
    $expectedRoute = str_starts_with($locationKey, 'mabini-') ? '12000001' : '12000002';
    if (pickup_area_route_key($locationKey) !== $expectedRoute) {
        throw new RuntimeException('Approved area has the wrong departure municipality: ' . $locationKey);
    }
}
$revision = (string) (driver_route_assignment((int) $mapDriver['id'])['route_revision'] ?? 0);
[$invalidStatus] = save_driver_route_and_pickup(
    (int) $mapDriver['id'], '12000001', 'grand-terminal', 'available', $revision
);
if ($invalidStatus !== 422) {
    throw new RuntimeException('A Mabini route accepted a Batangas City starting area.');
}
[$invalidStatus] = save_driver_route_and_pickup(
    (int) $mapDriver['id'], '12000002', 'mabini-tourism-triangle', 'available', $revision
);
if ($invalidStatus !== 422) {
    throw new RuntimeException('A Batangas City route accepted a Mabini starting area.');
}
$mapPickupAvailabilityOptions = pickup_availability_options();
$mapPickupLocationKey = '';
$mapPickupAvailability = 'off_duty';
$mapPickupLocationLabel = 'Not assigned';
$mapDriverSetup = null;
$_SERVER['SCRIPT_NAME'] = '/public/map.php';

foreach (['', '12000001', '12000002'] as $mapDriverRouteKey) {
    ob_start();
    try {
        require __DIR__ . '/../app/route-map.php';
        $html = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom);
    foreach (['driver-route-map', 'map-pickup-area', 'map-driver-route',
        'map-pickup-location', 'map-pickup-availability', 'map-location-data'] as $id) {
        if ($xpath->query('//*[@id="' . $id . '"]')->length !== 1) {
            throw new RuntimeException('Missing or duplicate rendered control: ' . $id);
        }
    }
    if ($xpath->query('//select[@id="map-pickup-location"]//option[@value!=""]')->length !== 6
        || $xpath->query('//select[@id="map-pickup-availability"]/option[@value!=""]')->length !== 4) {
        throw new RuntimeException('Expected six approved areas and four statuses.');
    }
    if (!str_contains($html, '>Save area</button>')
        || $xpath->query('//*[@id="map-pickup-clear" and @type="button"]')->length !== 1
        || !str_contains($html, 'Current assigned area:')) {
        throw new RuntimeException('Missing save button or current assigned area.');
    }
    if ($mapDriverRouteKey !== '' && $xpath->query(
        '//select[@id="map-driver-route"]/option[@selected and @value="' . $mapDriverRouteKey . '"]'
    )->length !== 1) {
        throw new RuntimeException('Saved route selection was not restored.');
    }
}
echo "PASS: driver map controls, both route selections, and rejection of cross-municipality starting areas.\n";
