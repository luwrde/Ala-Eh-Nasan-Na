<?php
declare(strict_types=1);

function normalize_vehicle_input(array $input): array
{
    $errors = [];
    $plateInput = $input['plate_number'] ?? '';
    $plate = is_string($plateInput) ? strtoupper(trim($plateInput)) : '';
    $plateCompact = preg_replace('/[ -]/', '', $plate);
    if (
        !is_string($plateCompact) ||
        preg_match('/^(?:[A-Z]{3}\d{3,4}|\d{3}[A-Z]{3})$/', $plateCompact) !== 1
    ) {
        $errors['plate_number'] = 'Enter a valid plate such as ABC 1234.';
    } else {
        $plate = strlen($plateCompact) > 3 && ctype_alpha($plateCompact[0])
            ? substr($plateCompact, 0, 3) . ' ' . substr($plateCompact, 3)
            : (ctype_digit($plateCompact[0])
                ? substr($plateCompact, 0, 3) . ' ' . substr($plateCompact, 3)
                : $plateCompact);
    }

    $capacityInput = $input['capacity'] ?? null;
    if (
        !(is_int($capacityInput) || (is_string($capacityInput) && preg_match('/^\d+$/', $capacityInput) === 1)) ||
        (int) $capacityInput < 1 ||
        (int) $capacityInput > 4294967295
    ) {
        $errors['capacity'] = 'Capacity must be a whole number greater than zero.';
    }

    $routeInput = $input['route_assignment'] ?? '';
    $route = is_string($routeInput) ? trim($routeInput) : '';
    if ($route === '' || mb_strlen($route) > 120) {
        $errors['route_assignment'] = 'Enter a route assignment of up to 120 characters.';
    }

    return [
        'errors' => $errors,
        'vehicle' => $errors === [] ? [
            'plate_number' => $plate,
            'capacity' => (int) $capacityInput,
            'route_assignment' => $route,
        ] : null,
    ];
}
