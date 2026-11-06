<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function jeepney_response(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    jeepney_response(405, ['error' => 'Only GET requests are supported.']);
}
$id = null;
if (array_key_exists('id', $_GET)) {
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) jeepney_response(400, ['error' => 'Invalid route ID.']);
}
try {
    require_once dirname(__DIR__, 2) . '/app/jeepney_routes_repository.php';
    $routes = jeepney_route_records(terminals_database(), $id);
    if ($id !== null) {
        if (!$routes) jeepney_response(404, ['error' => 'Jeepney route not found.']);
        jeepney_response(200, ['route' => $routes[0]]);
    }
    jeepney_response(200, ['routes' => $routes]);
} catch (Throwable $error) {
    error_log('Jeepney route paths: ' . $error->getMessage());
    jeepney_response(503, ['error' => 'Saved jeepney routes are temporarily unavailable. Please try again.']);
}
