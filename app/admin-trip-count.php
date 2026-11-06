<?php
declare(strict_types=1);

/** Count each operating jeepney once, across GPS trips and driver status/seat reports. */
function admin_ongoing_trip_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*)
        FROM drivers d
        INNER JOIN jeepney_profiles j ON j.driver_id = d.id
        LEFT JOIN driver_admin_status ds ON ds.driver_id = d.id
        LEFT JOIN jeepney_admin_status js ON js.jeepney_profile_id = j.id
        LEFT JOIN driver_trip_status ts ON ts.driver_id = d.id
        LEFT JOIN driver_seat_availability seats ON seats.driver_id = d.id
        WHERE d.verification_status = 'verified'
          AND j.plate_number IS NOT NULL AND j.capacity > 0
          AND COALESCE(ds.account_status, 'active') = 'active'
          AND COALESCE(js.operational_status, 'active') = 'active'
          AND COALESCE(ts.status, 'available') <> 'out'
          AND (
              ts.status IN ('full', 'in_transit')
              OR (seats.capacity = j.capacity AND seats.occupied >= j.capacity)
              OR EXISTS (SELECT 1 FROM trips t WHERE t.driver_id = d.id
                         AND t.jeepney_profile_id = j.id AND t.trip_status = 'active')
          )")->fetchColumn();
}
