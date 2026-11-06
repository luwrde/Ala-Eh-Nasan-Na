<?php
declare(strict_types=1);

function pickup_area_keys(): array
{
    return [
        'total-djpmm',
        'diversion-kwatogs',
        'grand-terminal',
        'mabini-tourism-triangle',
        'mabini-seven-eleven',
        'mabini-iconails',
    ];
}

function pickup_area_route_key(string $locationKey): ?string
{
    if (in_array($locationKey, ['total-djpmm', 'diversion-kwatogs', 'grand-terminal'], true)) {
        return '12000002'; // Batangas City → Mabini
    }
    if (in_array($locationKey, ['mabini-tourism-triangle', 'mabini-seven-eleven', 'mabini-iconails'], true)) {
        return '12000001'; // Mabini → Batangas City
    }
    return null;
}

function pickup_availability_options(): array
{
    return [
        'available' => ['label' => 'Available', 'description' => 'Ready to pick up passengers.', 'class' => 'available'],
        'waiting' => ['label' => 'Waiting for Passengers', 'description' => 'Waiting at the assigned area.', 'class' => 'waiting'],
        'on_trip' => ['label' => 'On Trip', 'description' => 'Currently traveling along the selected route.', 'class' => 'in-transit'],
        'off_duty' => ['label' => 'Off Duty', 'description' => 'Not currently accepting passengers.', 'class' => 'out'],
    ];
}

function pickup_areas(): array
{
    $keys = pickup_area_keys();
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $query = db()->prepare("SELECT location_key, location_name, area_label, landmark, latitude, longitude
        FROM map_locations WHERE is_active = 1 AND location_key IN ($placeholders)
        ORDER BY FIELD(location_key, " . implode(',', array_fill(0, count($keys), '?')) . ")");
    $query->execute([...$keys, ...$keys]);
    return $query->fetchAll();
}

function driver_pickup_assignment(int $driverId): ?array
{
    $query = db()->prepare('SELECT a.location_key, a.availability, a.revision, a.updated_at,
        l.location_name, l.area_label, l.landmark
        FROM driver_pickup_assignments a INNER JOIN map_locations l ON l.location_key = a.location_key
        WHERE a.driver_id = ? LIMIT 1');
    $query->execute([$driverId]);
    return $query->fetch() ?: null;
}

function save_driver_pickup_assignment(int $driverId, mixed $locationKey, mixed $availability, mixed $revision): array
{
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $driver = driver_seats($driverId, true);
        $current = driver_pickup_assignment($driverId);
        $allowedKeys = pickup_area_keys();
        $options = pickup_availability_options();
        if (!can_update_seats($driver)) {
            $error = [403, 'A verified driver account and approved jeepney are required.'];
        } elseif (!is_string($locationKey) || !in_array($locationKey, $allowedKeys, true)) {
            $error = [422, 'Choose one of the six approved pickup or waiting areas.'];
        } elseif (!is_string($availability) || !array_key_exists($availability, $options)) {
            $error = [422, 'Choose Available, Waiting for Passengers, or Off Duty.'];
        } elseif (!is_string($revision) || !ctype_digit($revision) || strlen($revision) > 10 || (int) $revision !== (int) ($current['revision'] ?? 0)) {
            $error = [409, 'Your assignment changed in another window. Review the latest assignment and save again.'];
        } else {
            $query = $pdo->prepare('INSERT INTO driver_pickup_assignments (driver_id, location_key, availability) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE location_key = VALUES(location_key), availability = VALUES(availability), revision = revision + 1, updated_at = CURRENT_TIMESTAMP');
            $query->execute([$driverId, $locationKey, $availability]);
            $pdo->commit();
            return [200, 'Pickup and waiting area saved.'];
        }
        $pdo->rollBack();
        return $error;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log((string) $exception);
        return [500, 'Pickup and waiting area could not be saved. Please try again.'];
    }
}

function public_pickup_assignments(): array
{
    require_once __DIR__ . '/seats.php';
    $query = db()->query("SELECT a.driver_id, a.location_key, l.location_name, l.area_label, l.landmark,
        d.full_name, a.availability, a.route_key, a.route_name,
        a.route_ref, a.route_start, a.route_destination, a.updated_at,
        j.capacity, j.plate_number, s.occupied, s.capacity AS reported_capacity, s.updated_at AS seats_updated_at
        FROM driver_pickup_assignments a
        INNER JOIN drivers d ON d.id = a.driver_id AND d.verification_status = 'verified'
        INNER JOIN map_locations l ON l.location_key = a.location_key AND l.is_active = 1
        LEFT JOIN jeepney_profiles j ON j.driver_id = a.driver_id
        LEFT JOIN driver_seat_availability s ON s.driver_id = a.driver_id
        LEFT JOIN driver_admin_status da ON da.driver_id = d.id
        LEFT JOIN jeepney_admin_status ja ON ja.jeepney_profile_id = j.id
        WHERE a.route_key IS NOT NULL
          AND COALESCE(da.account_status, 'active') = 'active'
          AND COALESCE(ja.operational_status, 'active') = 'active'
        ORDER BY a.location_key, d.full_name");
    return array_map(static function (array $assignment): array {
        $capacity = (int) ($assignment['capacity'] ?? 0);
        $validSeats = $capacity > 0 && $assignment['plate_number'] !== null && $assignment['seats_updated_at'] !== null
            && (int) $assignment['reported_capacity'] === $capacity;
        $seatState = $validSeats
            ? seat_status(min($capacity, max(0, (int) $assignment['occupied'])), $capacity)
            : null;
        unset($assignment['capacity'], $assignment['plate_number'], $assignment['occupied'], $assignment['reported_capacity'], $assignment['seats_updated_at']);
        $assignment['seat_color'] = $seatState['color'] ?? 'unknown';
        $assignment['percent_occupied'] = $seatState['percentOccupied'] ?? null;
        return $assignment;
    }, $query->fetchAll());
}
