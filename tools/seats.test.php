<?php
declare(strict_types=1);
require __DIR__ . '/../app/seats.php';
foreach ([0 => 'green', 9 => 'green', 10 => 'yellow', 12 => 'yellow', 13 => 'yellow', 14 => 'orange', 16 => 'orange', 17 => 'orange', 18 => 'red', 19 => 'red', 20 => 'red'] as $occupied => $color) {
    $result = seat_status($occupied, 20);
    if ($result['color'] !== $color || $result['available'] !== 20 - $occupied) throw new RuntimeException('Incorrect seat state at ' . $occupied);
}
if (seat_status(0, 20)['percentAvailable'] !== 100 || seat_status(20, 20)['percentAvailable'] !== 0 || seat_status(20, 20)['label'] !== 'Full') throw new RuntimeException('Incorrect empty/full state');
if (seat_status(1, 3)['color'] !== 'green' || seat_status(2, 3)['color'] !== 'yellow') throw new RuntimeException('Incorrect non-20-seat capacity');
if (can_update_seats(null) || can_update_seats(['verification_status' => 'pending_verification', 'plate_number' => 'ABC 1234', 'capacity' => 20])) throw new RuntimeException('Unverified access');
echo "Seat thresholds, capacity calculations, and eligibility checks passed.\n";
