<?php
declare(strict_types=1);

function seat_status(int $occupied, int $capacity): array
{
    $available = $capacity - $occupied;
    $percentage = $occupied / $capacity * 100;
    return [
        'available' => $available,
        'percentAvailable' => (int) round($available / $capacity * 100),
        'percentOccupied' => (int) round($percentage),
        'color' => $percentage >= 90 ? 'red' : ($percentage >= 70 ? 'orange' : ($percentage >= 50 ? 'yellow' : 'green')),
        'label' => $available === 0 ? 'Full' : ($percentage >= 90 ? 'Almost full' : ($percentage >= 70 ? 'Filling up' : ($percentage >= 50 ? 'Half full' : 'Seats available'))),
    ];
}

function driver_seats(int $driverId, bool $lock = false): ?array
{
    $query = db()->prepare('SELECT d.verification_status, j.capacity, j.plate_number, s.occupied,
        s.capacity AS reported_capacity, s.revision, s.updated_at,
        COALESCE(da.account_status, \'active\') AS account_status,
        COALESCE(ja.operational_status, \'active\') AS operational_status
        FROM drivers d LEFT JOIN jeepney_profiles j ON j.driver_id = d.id
        LEFT JOIN driver_seat_availability s ON s.driver_id = d.id
        LEFT JOIN driver_admin_status da ON da.driver_id = d.id
        LEFT JOIN jeepney_admin_status ja ON ja.jeepney_profile_id = j.id
        WHERE d.id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $query->execute([$driverId]);
    return $query->fetch() ?: null;
}

function can_update_seats(?array $seats): bool
{
    // Pending vehicle edits do not replace the previously approved capacity.
    return $seats !== null && $seats['verification_status'] === 'verified'
        && ($seats['account_status'] ?? 'active') === 'active'
        && ($seats['operational_status'] ?? 'active') === 'active'
        && $seats['plate_number'] !== null && (int) $seats['capacity'] > 0;
}
