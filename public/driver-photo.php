<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$driver = require_driver();
$photo = driver_photo_file((int) $driver['id']);
if ($photo === null) {
    http_response_code(404);
    exit;
}

$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $fileInfo->file($photo);
if (!is_string($mimeType) || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($photo));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
readfile($photo);
