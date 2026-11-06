<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/commuter-integration.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); http_response_code(405); echo json_encode(['error' => 'Only GET requests are supported.']); exit; }
$id = array_key_exists('id', $_GET) ? filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
if ($id === false) { http_response_code(400); echo json_encode(['error' => 'Invalid terminal ID.']); exit; }
try {
    $records = commuter_terminal_records($id === null ? null : (int) $id);
    if ($id !== null && !$records) { http_response_code(404); echo json_encode(['error' => 'Terminal not found.']); exit; }
    echo json_encode($id !== null ? ['terminal' => $records[0]] : ['terminals' => $records, 'totals' => ['locations' => count($records), 'municipalities' => count(array_unique(array_column($records, 'municipality')))]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) { error_log('Commuter terminals: ' . $error->getMessage()); http_response_code(503); echo json_encode(['error' => 'Terminal information is temporarily unavailable. Please try again.']); }
