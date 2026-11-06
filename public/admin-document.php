<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/admin-bootstrap.php';
require_once __DIR__ . '/../app/driver-documents.php';
$admin = require_admin();

$documentId = validated_positive_id($_GET['id'] ?? null);
if ($documentId === null) {
    http_response_code(404);
    exit('Document not found.');
}
$query = db()->prepare(
    'SELECT storage_key, file_contents, mime_type, original_name
     FROM driver_documents WHERE id = :id LIMIT 1'
);
$query->execute(['id' => $documentId]);
$document = $query->fetch();
if (
    $document === false ||
    !in_array($document['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true)
) {
    http_response_code(404);
    exit('Document not found.');
}
$preview = ($_GET['preview'] ?? '') === '1';
record_admin_activity(db(), $admin, $preview ? 'document_previewed' : 'document_downloaded', 'driver_document', $documentId);

send_driver_document($document, $preview);
