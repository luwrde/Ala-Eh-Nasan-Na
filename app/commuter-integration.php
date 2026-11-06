<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/map-locations.php';
require_once __DIR__ . '/driver-routes.php';

/**
 * Project-owned commuter read model. The passenger module consumes this shape,
 * while the source remains the jeepney application's MySQL database.
 */
function commuter_terminal_records(?int $id = null): array
{
    $records = map_locations();
    $routeOptions = driver_route_options();
    $driversByLocation = commuter_driver_assignments();
    $records = array_map(static function (array $location) use ($routeOptions, $driversByLocation): array {
        $isCity = str_contains($location['area'], 'Batangas City');
        $routeKey = $isCity ? '12000002' : '12000001';
        $route = $routeOptions[$routeKey];
        $coordinates = $location['coordinates'];
        $verified = is_array($coordinates) && count($coordinates) === 2;
        return [
            'id' => $location['recordId'],
            'slug' => $location['locationKey'],
            'name' => $location['name'],
            'municipality' => $isCity ? 'Batangas City' : 'Mabini',
            'address' => $location['landmark'],
            'boarding_direction' => null,
            'classification' => $location['type'],
            'operating_schedule' => null,
            'coordinates' => $verified ? ['latitude' => $coordinates[1], 'longitude' => $coordinates[0]] : null,
            'coordinates_verified' => $verified,
            'landmark_coordinates' => $verified ? ['latitude' => $coordinates[1], 'longitude' => $coordinates[0]] : null,
            'landmark_coordinates_verified' => $verified,
            'location_verification_status' => $verified ? 'verified_reference' : 'pending',
            'google_maps_url' => $location['source'],
            'data_source' => 'Ala-Eh Nasaan Na? jeepney map locations',
            'routes' => [[
                'id' => (int) $routeKey,
                'name' => $route['name'],
                'destination' => $route['destination'],
                'fare_information' => null,
                'operating_schedule' => null,
                'verified' => true,
                'source_url' => $location['source'],
                'source_title' => 'Jeepney route assignment',
                'service_note' => 'Confirm the destination and current departure with the driver before boarding.',
            ]],
            'drivers' => $driversByLocation[$location['locationKey']] ?? [],
            'seat_availability' => null,
        ];
    }, $records);
    if ($id !== null) return array_values(array_filter($records, static fn (array $record): bool => $record['id'] === $id));
    return $records;
}

function commuter_assigned_route_coordinates(string $locationKey, ?string $routeKey): ?array
{
    if ($routeKey === null) return null;
    $root = __DIR__ . '/../public/assets/';
    if ($locationKey === 'diversion-kwatogs' && $routeKey === '12000002') {
        $data = json_decode((string) @file_get_contents($root . 'diversion-mabini-road.json'), true);
        return is_array($data['coordinates'] ?? null) && count($data['coordinates']) >= 2 ? $data['coordinates'] : null;
    }
    if ($routeKey === '12000001' && in_array($locationKey, ['mabini-tourism-triangle', 'mabini-seven-eleven', 'mabini-iconails'], true)) {
        $data = json_decode((string) @file_get_contents($root . 'mabini-area-roads.json'), true);
        foreach (($data['routes'] ?? []) as $route) {
            if (($route['locationKey'] ?? '') === $locationKey && is_array($route['coordinates'] ?? null) && count($route['coordinates']) >= 2) {
                return $route['coordinates'];
            }
        }
    }
    return null;
}

/** Publish only operationally useful, non-sensitive driver details to commuters. */
function commuter_driver_assignments(): array
{
    $query = db()->query("SELECT a.location_key, a.route_key, d.full_name, a.availability, a.route_name,
        a.route_destination, a.route_ref, a.updated_at, j.plate_number,
        j.capacity AS approved_capacity, s.occupied, s.capacity AS reported_capacity,
        s.updated_at AS seats_updated_at, t.status AS trip_status
        FROM driver_pickup_assignments a
        INNER JOIN drivers d ON d.id = a.driver_id AND d.verification_status = 'verified'
        LEFT JOIN jeepney_profiles j ON j.driver_id = a.driver_id
        LEFT JOIN driver_seat_availability s ON s.driver_id = a.driver_id
        LEFT JOIN driver_trip_status t ON t.driver_id = a.driver_id
        LEFT JOIN driver_admin_status da ON da.driver_id = d.id
        LEFT JOIN jeepney_admin_status ja ON ja.jeepney_profile_id = j.id
        INNER JOIN map_locations l ON l.location_key = a.location_key AND l.is_active = 1
        WHERE a.availability <> 'off_duty' AND a.route_key IS NOT NULL
          AND COALESCE(da.account_status, 'active') = 'active'
          AND COALESCE(ja.operational_status, 'active') = 'active'
        ORDER BY a.location_key, d.full_name");
    $byLocation = [];
    foreach ($query as $row) {
        $capacity = (int) ($row['approved_capacity'] ?? 0);
        $reportedCapacity = (int) ($row['reported_capacity'] ?? 0);
        $hasSeats = $capacity > 0 && $row['plate_number'] !== null
            && $row['seats_updated_at'] !== null && $reportedCapacity === $capacity;
        $occupied = $hasSeats ? min($capacity, max(0, (int) $row['occupied'])) : null;
        if ($hasSeats && $occupied >= $capacity) continue;
        $byLocation[$row['location_key']][] = [
            'name' => $row['full_name'],
            'plate_number' => $row['plate_number'],
            'location_key' => $row['location_key'],
            'route_key' => $row['route_key'],
            'route_id' => $row['route_key'] === '12000001' ? 10755762 : ($row['route_key'] === '12000002' ? 10755763 : null),
            'route_name' => $row['route_name'],
            'route_ref' => $row['route_ref'],
            'destination' => $row['route_destination'],
            'route_coordinates' => commuter_assigned_route_coordinates((string) $row['location_key'], $row['route_key']),
            'availability' => $row['availability'],
            'trip_status' => $row['trip_status'],
            'seats' => $hasSeats ? ['available' => $capacity - $occupied, 'capacity' => $capacity] : null,
            'seat_updated_at' => $hasSeats ? $row['seats_updated_at'] : null,
            'updated_at' => $row['updated_at'],
        ];
    }
    return $byLocation;
}

function commuter_route_records(?int $id = null): array
{
    $source = __DIR__ . '/../public/assets/jeepney-routes.json';
    $payload = json_decode((string) file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
    $result = [];
    foreach (($payload['routes'] ?? []) as $route) {
        if (!is_array($route) || !is_array($route['coordinates'] ?? null) || count($route['coordinates']) < 2) continue;
        $routeId = (int) ($route['id'] ?? 0);
        $isDirectionalRoute = in_array($routeId, [10755762, 10755763], true);
        $startTerminalId = $routeId === 10755762 ? 1 : 3;
        $endTerminalId = $routeId === 10755762 ? 3 : 1;
        $base = [
            'id' => $routeId, 'slug' => 'jeepney-' . $routeId,
            'name' => (string) ($route['name'] ?? 'Jeepney route'), 'start_terminal_id' => 3,
            'end_terminal_id' => 1, 'travel_direction' => 'batangas-to-mabini', 'status' => 'verified',
            'geometry' => ['type' => 'LineString', 'coordinates' => $route['coordinates']],
            'roads' => array_values(array_unique(array_filter($route['roadNames'] ?? []))),
            'stops' => $route['stops'] ?? [], 'verification_source' => $route['source'] ?? $payload['description'] ?? null,
            'verified_by' => 'Ala-Eh Nasaan Na? mapped route data', 'verified_at' => $route['updatedAt'] ?? null,
            'preview' => null, 'updated_at' => $route['updatedAt'] ?? null,
        ];
        if ($isDirectionalRoute) {
            $base['start_terminal_id'] = $startTerminalId;
            $base['end_terminal_id'] = $endTerminalId;
            $base['travel_direction'] = $routeId === 10755762 ? 'mabini-to-batangas' : 'batangas-to-mabini';
        }
        $result[] = $base;
        if ($isDirectionalRoute) continue;
        $reverse = $base;
        $reverse['id'] += 1;
        $reverse['slug'] .= '-reverse';
        $reverse['name'] .= ' (reverse direction)';
        $reverse['start_terminal_id'] = 1; $reverse['end_terminal_id'] = 3;
        $reverse['travel_direction'] = 'mabini-to-batangas';
        $reverse['geometry']['coordinates'] = array_reverse($base['geometry']['coordinates']);
        $reverse['stops'] = array_reverse($base['stops']);
        $result[] = $reverse;
    }
    if ($id !== null) $result = array_values(array_filter($result, static fn (array $route): bool => $route['id'] === $id));
    return $result;
}
