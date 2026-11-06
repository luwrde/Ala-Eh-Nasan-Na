<?php
declare(strict_types=1);

function map_locations(bool $includeInactive = false): array
{
    $rows = db()->query('SELECT * FROM map_locations' . ($includeInactive ? '' : ' WHERE is_active = 1') . ' ORDER BY id')->fetchAll();
    $locations = array_map(static fn (array $row): array => [
        'id' => 'location-' . $row['id'],
        'locationKey' => $row['location_key'],
        'recordId' => (int) $row['id'],
        'name' => $row['location_name'],
        'area' => $row['area_label'],
        'landmark' => $row['landmark'],
        'type' => $row['location_type'],
        'coordinates' => $row['latitude'] === null || $row['longitude'] === null ? null : [(float) $row['longitude'], (float) $row['latitude']],
        'routeIds' => array_map('intval', array_values(array_filter(explode(',', $row['mapped_route_ids'])))),
        'source' => $row['source_url'],
        'active' => (bool) $row['is_active'],
    ], $rows);

    // Saved coordinates are authoritative, including an administrator's removed pin.
    return $locations;
}

function mapped_routes(): array
{
    return json_decode(file_get_contents(__DIR__ . '/../public/assets/jeepney-routes.json'), true, 512, JSON_THROW_ON_ERROR)['routes'];
}
