<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function terminal_response(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    terminal_response(405, ['error' => 'Only GET requests are supported.']);
}
$id = null;
if (array_key_exists('id', $_GET)) {
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) terminal_response(400, ['error' => 'Invalid terminal ID.']);
}
try {
    require_once dirname(__DIR__, 2) . '/app/terminals_repository.php';
    $records = terminal_records(terminals_database(), $id);
    if ($id !== null) {
        if (!$records) terminal_response(404, ['error' => 'Terminal not found.']);
        terminal_response(200, ['terminal' => $records[0]]);
    }
    terminal_response(200, [
        'terminals' => $records,
        'totals' => [
            'locations' => count($records),
            'municipalities' => count(array_unique(array_column($records, 'municipality'))),
        ],
    ]);
} catch (Throwable $error) {
    error_log('Terminal directory: ' . $error->getMessage());
    terminal_response(503, ['error' => 'Terminal information is temporarily unavailable. Please try again.']);
}
