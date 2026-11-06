<?php
declare(strict_types=1);

const DRIVER_DOCUMENT_TYPES = [
    'driver_license' => 'Driver license',
    'vehicle_registration' => 'Vehicle registration',
    'insurance' => 'Insurance',
    'other' => 'Supporting document',
];

function driver_document_path(string $key): ?string
{
    return preg_match('/\A[a-f0-9]{64}\z/', $key)
        ? dirname(__DIR__) . '/storage/driver-documents/' . $key : null;
}

// The caller validates the uploaded file and MIME type before storing it.
function store_driver_document(PDO $pdo, int $driverId, string $type, string $name, string $mime, string $source): void
{
    $key = bin2hex(random_bytes(32));
    $path = driver_document_path($key);
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Document storage is unavailable.');
    }
    $input = fopen($source, 'rb');
    if ($input === false) throw new RuntimeException('Cannot read uploaded document.');
    $output = null;
    try {
        $output = fopen($path, 'xb');
        if ($output === false) throw new RuntimeException('Cannot save document.');
        $expected = filesize($source);
        $written = stream_copy_to_stream($input, $output);
        if ($written === false || $written !== $expected) throw new RuntimeException('Incomplete document write.');
        fclose($output); $output = null;
        $insert = $pdo->prepare('INSERT INTO driver_documents (driver_id, document_type, original_name, storage_key, mime_type) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([$driverId, $type, $name, $key, $mime]);
    } catch (Throwable $error) {
        if (is_resource($output)) fclose($output);
        if (is_file($path)) unlink($path);
        throw $error;
    } finally {
        fclose($input);
    }
}

function send_driver_document(array $document, bool $inline = false): never
{
    $path = driver_document_path((string) $document['storage_key']);
    $legacy = $document['file_contents'] ?? null;
    if (!in_array($document['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true)
        || (!is_string($legacy) && ($path === null || !is_readable($path)))) {
        http_response_code(404);
        exit('Document not found.');
    }
    $name = (string) $document['original_name'];
    header('Content-Type: ' . $document['mime_type']);
    header("Content-Disposition: " . ($inline ? 'inline' : 'attachment') . "; filename=\"document\"; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . (string) (is_string($legacy) ? strlen($legacy) : filesize($path)));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    if (is_string($legacy)) echo $legacy;
    else readfile($path);
    exit;
}
