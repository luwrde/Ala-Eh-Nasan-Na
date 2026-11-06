<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/driver-documents.php';
$driver = require_driver();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$query = db()->prepare('SELECT storage_key, file_contents, mime_type, original_name FROM driver_documents WHERE id = ? AND driver_id = ?');
$query->execute([$id ?: 0, (int) $driver['id']]);
$document = $query->fetch();
if (!$document) { http_response_code(404); exit('Document not found.'); }
send_driver_document($document);
