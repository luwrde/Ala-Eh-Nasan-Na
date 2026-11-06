<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function vehicle_api_response(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$driver = current_driver();
if ($driver === null) {
    vehicle_api_response(401, ['error' => 'Sign in as a driver to access your vehicle profile.']);
}
$driverId = (int) $driver['id'];
$access = db()->prepare("SELECT COALESCE(s.account_status, 'active') FROM drivers d LEFT JOIN driver_admin_status s ON s.driver_id = d.id WHERE d.id = ?");
$access->execute([$driverId]);
if ($access->fetchColumn() !== 'active') {
    unset($_SESSION['driver']);
    vehicle_api_response(403, ['error' => 'This driver account is suspended or deactivated.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $query = db()->prepare(
            'SELECT id, plate_number, capacity, route_assignment, verification_status,
                    pending_plate_number, pending_capacity, pending_route_assignment, updated_at
             FROM jeepney_profiles WHERE driver_id = :driver_id LIMIT 1'
        );
        $query->execute(['driver_id' => $driverId]);
        $vehicle = $query->fetch();
        vehicle_api_response(200, ['vehicle' => $vehicle === false ? null : $vehicle]);
    } catch (Throwable $exception) {
        error_log((string) $exception);
        vehicle_api_response(500, ['error' => 'Unable to load your vehicle profile. Please try again.']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    header('Allow: GET, PUT');
    vehicle_api_response(405, ['error' => 'Method not allowed. Use GET or PUT.']);
}

if (!csrf_value_is_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    vehicle_api_response(403, ['error' => 'Invalid request token. Reload the page and try again.']);
}
$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    vehicle_api_response(415, ['error' => 'Send vehicle details as application/json.']);
}

$requestBody = file_get_contents('php://input');
if ($requestBody === false || strlen($requestBody) > 8192) {
    vehicle_api_response(413, ['error' => 'The vehicle update is too large.']);
}

try {
    $input = json_decode($requestBody, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    vehicle_api_response(400, ['error' => 'The request body must contain valid JSON.']);
}
if (!is_array($input)) {
    vehicle_api_response(400, ['error' => 'The request body must be a JSON object.']);
}

$validation = normalize_vehicle_input($input);
if ($validation['errors'] !== []) {
    vehicle_api_response(422, [
        'error' => 'Check the highlighted vehicle details.',
        'fields' => $validation['errors'],
    ]);
}

try {
    $vehicle = $validation['vehicle'];
    $save = db()->prepare(
        "INSERT INTO jeepney_profiles (
            driver_id, pending_plate_number, pending_capacity,
            pending_route_assignment, verification_status
         ) VALUES (
            :driver_id, :plate_number, :capacity, :route_assignment, 'pending_verification'
         )
         ON DUPLICATE KEY UPDATE
            pending_plate_number = VALUES(pending_plate_number),
            pending_capacity = VALUES(pending_capacity),
            pending_route_assignment = VALUES(pending_route_assignment),
            verification_status = 'pending_verification'"
    );
    $save->execute([
        'driver_id' => $driverId,
        'plate_number' => $vehicle['plate_number'],
        'capacity' => $vehicle['capacity'],
        'route_assignment' => $vehicle['route_assignment'],
    ]);

    $query = db()->prepare(
        'SELECT id, plate_number, capacity, route_assignment, verification_status,
                pending_plate_number, pending_capacity, pending_route_assignment, updated_at
         FROM jeepney_profiles WHERE driver_id = :driver_id LIMIT 1'
    );
    $query->execute(['driver_id' => $driverId]);
    vehicle_api_response(200, [
        'message' => 'Vehicle changes were submitted and are pending verification.',
        'vehicle' => $query->fetch(),
    ]);
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        vehicle_api_response(409, ['error' => 'That plate number is already assigned to another verified vehicle.']);
    }
    error_log((string) $exception);
    vehicle_api_response(500, ['error' => 'Unable to save your vehicle changes. Please try again.']);
} catch (Throwable $exception) {
    error_log((string) $exception);
    vehicle_api_response(500, ['error' => 'Unable to save your vehicle changes. Please try again.']);
}
