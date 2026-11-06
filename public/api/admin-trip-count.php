<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/admin-bootstrap.php';
require_once __DIR__ . '/../../app/admin-trip-count.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Only GET requests are supported.']);
    exit;
}
if (current_admin() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in as an administrator.']);
    exit;
}
require_admin();
session_write_close();
try {
    echo json_encode(['ongoing_trips' => admin_ongoing_trip_count(db())], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Admin trip count: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Trip count is temporarily unavailable.']);
}
